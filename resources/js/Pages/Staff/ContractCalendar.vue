<template>
  <admin-layout :title="$t('Contracts calendar')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="calendar" :title="$t('Contracts calendar')" :subtitle="$t('Every contract that ends, starts or is renewed, day by day, and what needs attention now.')">
        <Link :href="route('staffs.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('RANS Staffs') }}</Link>
      </page-header>

      <stat-tiles :items="tiles" />

      <div class="cc-layout">
        <section class="lg-card cc-cal">
          <header class="cc-bar">
            <button type="button" class="lg-icon-btn" :aria-label="$t('Previous month')" @click="move(-1)"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" /></button>
            <h2>{{ monthName }}</h2>
            <button type="button" class="lg-icon-btn" :aria-label="$t('Next month')" @click="move(1)"><icons name="chevron-down" class="w-4 h-4 transform -rotate-90" /></button>
            <button type="button" class="lg-btn lg-btn-soft cc-today" @click="goToday">{{ $t('Today') }}</button>
            <span v-if="loading" class="cc-load" aria-hidden="true"></span>
          </header>
          <div class="cc-legend">
            <label class="cc-key k-ends"><input v-model="show.ends" type="checkbox" /><i></i>{{ $t('Contract ends') }}</label>
            <label class="cc-key k-renewal"><input v-model="show.renewal" type="checkbox" /><i></i>{{ $t('Renewal starts') }}</label>
            <label class="cc-key k-starts"><input v-model="show.starts" type="checkbox" /><i></i>{{ $t('New contract starts') }}</label>
          </div>
          <div class="cc-grid" role="grid">
            <div v-for="d in weekdays" :key="d" class="cc-wd" role="columnheader">{{ d }}</div>
            <div v-for="day in days" :key="day.date" class="cc-day" role="gridcell" :class="{ out: !day.inMonth, today: day.date === today, picked: day.date === picked }" @click="picked = day.date">
              <span class="cc-n">{{ day.n }}</span>
              <ul>
                <li v-for="e in day.events.slice(0, 3)" :key="e.id" class="cc-ev" :class="['k-' + e.kind, 's-' + e.state]"><Link :href="e.url" class="cc-ev-link" :title="e.staff.name + ' · ' + e.contract_number + ' · ' + $t('Open staff details')" @click.stop><i></i>{{ e.staff.name }}</Link></li>
              </ul>
              <button v-if="day.events.length > 3" type="button" class="cc-more" :title="$t('Show everyone on this day')" @click.stop="picked = day.date">+{{ day.events.length - 3 }}</button>
            </div>
          </div>
        </section>

        <aside class="cc-side">
          <section v-if="picked" class="lg-card cc-pick">
            <h3>{{ $date(picked) }}</h3>
            <p v-if="!pickedEvents.length" class="cc-empty">{{ $t('Nothing on this day.') }}</p>
            <ul v-else><li v-for="e in pickedEvents" :key="e.id"><Link :href="e.url" class="cc-row" :class="'k-' + e.kind"><span class="cc-dot"></span><div class="min-w-0"><b>{{ e.staff.name }}</b><small>{{ kindText(e) }} · {{ e.contract_number }} · {{ e.type }}</small></div></Link></li></ul>
          </section>

          <section class="lg-card cc-att">
            <h3><icons name="exclamation" class="w-4 h-4" />{{ $t('Needs renewal now') }} <span class="cc-cnt bad">{{ attention.expired.length }}</span></h3>
            <p v-if="!attention.expired.length" class="cc-empty">{{ $t('No contract is overdue.') }}</p>
            <ul v-else><li v-for="e in attention.expired" :key="e.id"><Link :href="e.url" class="cc-row s-expired"><span class="cc-dot"></span><div class="min-w-0"><b>{{ e.staff.name }}</b><small>{{ e.contract_number }} · {{ $t('ended') }} {{ $date(e.date) }} ({{ Math.abs(e.days_left) }} {{ $t('days ago') }})</small></div></Link></li></ul>
          </section>

          <section class="lg-card cc-att">
            <h3><icons name="calendar" class="w-4 h-4" />{{ $t('Ending in the next 90 days') }} <span class="cc-cnt">{{ attention.soon.length }}</span></h3>
            <p v-if="!attention.soon.length" class="cc-empty">{{ $t('No contract ends in the next 90 days.') }}</p>
            <ul v-else><li v-for="e in attention.soon" :key="e.id"><Link :href="e.url" class="cc-row" :class="'s-' + e.state"><span class="cc-dot"></span><div class="min-w-0"><b>{{ e.staff.name }}</b><small>{{ e.contract_number }} · {{ $date(e.date) }} · {{ e.days_left === 0 ? $t('today') : e.days_left + ' ' + $t('days') }}</small></div></Link></li></ul>
          </section>
        </aside>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

const iso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');

export default {
  components: { AdminLayout, PageHeader, StatTiles, Link },
  props: { today: String, attention: Object, totals: Object, canUpdate: Boolean },
  data() {
    const t = new Date(this.today + 'T00:00:00');
    return { cursor: new Date(t.getFullYear(), t.getMonth(), 1), events: [], loading: false, picked: this.today, show: { ends: true, renewal: true, starts: true }, req: 0 };
  },
  computed: {
    weekdays() { return [this.$t('Mon'), this.$t('Tue'), this.$t('Wed'), this.$t('Thu'), this.$t('Fri'), this.$t('Sat'), this.$t('Sun')]; },
    monthName() { return this.cursor.toLocaleDateString(this.$page.props.settings?.default_locale || 'en', { month: 'long', year: 'numeric' }); },
    range() {
      const first = new Date(this.cursor); const lead = (first.getDay() + 6) % 7;
      const start = new Date(first.getFullYear(), first.getMonth(), 1 - lead);
      return { start, end: new Date(start.getFullYear(), start.getMonth(), start.getDate() + 41) };
    },
    days() {
      const out = [];
      for (let i = 0; i < 42; i++) {
        const d = new Date(this.range.start.getFullYear(), this.range.start.getMonth(), this.range.start.getDate() + i);
        const key = iso(d);
        out.push({ date: key, n: d.getDate(), inMonth: d.getMonth() === this.cursor.getMonth(), events: this.events.filter(e => e.date === key && this.show[e.kind]) });
      }
      return out;
    },
    pickedEvents() { return this.events.filter(e => e.date === this.picked && this.show[e.kind]); },
    tiles() {
      const t = this.totals;
      return [
        { icon: 'doc-text', tone: 'emerald', label: this.$t('Active contracts'), value: t.active },
        { icon: 'calendar', tone: 'amber', label: this.$t('Ending in 60 days'), value: t.soon },
        { icon: 'exclamation', tone: 'rose', label: this.$t('Overdue for renewal'), value: t.expired },
        { icon: 'tick', tone: 'indigo', label: this.$t('Permanent contracts'), value: t.permanent },
        { icon: 'refresh', tone: 'sky', label: this.$t('Renewed this year'), value: t.renewed_this_year },
      ];
    },
  },
  watch: { cursor() { this.load(); } },
  mounted() { this.load(); },
  methods: {
    move(n) { this.cursor = new Date(this.cursor.getFullYear(), this.cursor.getMonth() + n, 1); },
    goToday() { const t = new Date(this.today + 'T00:00:00'); this.cursor = new Date(t.getFullYear(), t.getMonth(), 1); this.picked = this.today; },
    async load() {
      const n = ++this.req; this.loading = true;
      try {
        const { data } = await axios.get(this.route('staffs.contracts.calendar.events'), { params: { from: iso(this.range.start), to: iso(this.range.end) } });
        if (n === this.req) this.events = data.events;
      } catch (e) { if (n === this.req) this.events = []; } finally { if (n === this.req) this.loading = false; }
    },
    kindText(e) { return e.kind === 'ends' ? this.$t('Contract ends') : e.kind === 'renewal' ? this.$t('Renewal starts') : this.$t('New contract starts'); },
  },
};
</script>

<style scoped>
.cc-layout { display: grid; gap: 1rem; grid-template-columns: minmax(0, 1fr) 340px; align-items: start; } @media (max-width: 1100px) { .cc-layout { grid-template-columns: 1fr; } }
.cc-bar { display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.7rem; } .cc-bar h2 { margin: 0 0.4rem; min-width: 11rem; font-size: 1.1rem; font-weight: 800; text-transform: capitalize; } .cc-today { margin-left: auto; }
.cc-load { width: 16px; height: 16px; border-radius: 50%; border: 2.5px solid rgba(59, 130, 246, 0.25); border-top-color: #3b82f6; animation: cc-spin 0.7s linear infinite; } @keyframes cc-spin { to { transform: rotate(360deg); } }
.cc-legend { display: flex; flex-wrap: wrap; gap: 0.4rem 1rem; margin-bottom: 0.7rem; font-size: 0.78rem; font-weight: 700; } .cc-key { display: inline-flex; align-items: center; gap: 0.4rem; cursor: pointer; } .cc-key input { accent-color: #1e3a8a; } .cc-key i { width: 10px; height: 10px; border-radius: 50%; }
.k-ends i, .cc-ev.k-ends i { background: #f43f5e; } .k-renewal i, .cc-ev.k-renewal i { background: #10b981; } .k-starts i, .cc-ev.k-starts i { background: #3b82f6; }
.cc-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 4px; }
.cc-wd { padding: 0.3rem 0; text-align: center; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #64748b; }
.cc-day { position: relative; min-height: 94px; padding: 0.3rem 0.35rem; border-radius: 12px; background: var(--lg-soft, #f1f5f9); box-shadow: inset 0 0 0 1px var(--lg-line, #e2e8f0); cursor: pointer; transition: box-shadow 0.15s, transform 0.15s; overflow: hidden; }
.cc-day:hover { box-shadow: inset 0 0 0 2px #93c5fd; } .cc-day.out { opacity: 0.45; } .cc-day.today { background: rgba(59, 130, 246, 0.1); box-shadow: inset 0 0 0 2px #3b82f6; } .cc-day.picked { box-shadow: inset 0 0 0 2px #f59e0b; }
.cc-n { font-size: 0.78rem; font-weight: 800; color: var(--lg-ink, #0f172a); } .cc-day ul { list-style: none; margin: 3px 0 0; padding: 0; display: grid; gap: 2px; }
.cc-ev { display: flex; align-items: center; gap: 4px; padding: 1px 5px; border-radius: 7px; font-size: 0.66rem; font-weight: 700; line-height: 1.5; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; background: #fff; box-shadow: inset 0 0 0 1px rgba(100, 116, 139, 0.2); } .cc-ev i { flex: none; width: 6px; height: 6px; border-radius: 50%; }
.cc-ev.s-expired, .cc-ev.s-urgent { background: #fff1f2; color: #9f1239; } .cc-ev.s-soon { background: #fffbeb; color: #92400e; } .cc-ev.k-renewal { background: #ecfdf5; color: #065f46; } .cc-ev.k-starts { background: #eff6ff; color: #1e3a8a; }
.cc-more { position: absolute; right: 6px; bottom: 3px; font-size: 0.66rem; font-weight: 800; color: #475569; }
.cc-side > .lg-card { margin-bottom: 1rem; } .cc-side h3 { display: flex; align-items: center; gap: 0.4rem; margin: 0 0 0.6rem; font-size: 0.92rem; font-weight: 800; } .cc-cnt { margin-left: auto; padding: 0 8px; border-radius: 99px; font-size: 0.74rem; background: #e2e8f0; } .cc-cnt.bad { background: #fecdd3; color: #9f1239; }
.cc-side ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.35rem; max-height: 320px; overflow: auto; } .cc-empty { margin: 0; font-size: 0.84rem; color: #64748b; }
.cc-row { display: flex; align-items: center; gap: 0.6rem; padding: 0.5rem 0.65rem; border-radius: 12px; background: var(--lg-soft, #f1f5f9); box-shadow: inset 0 0 0 1px var(--lg-line, #e2e8f0); text-decoration: none; color: inherit; } .cc-row:hover { box-shadow: inset 0 0 0 2px #93c5fd; }
.cc-row b { display: block; font-size: 0.86rem; } .cc-row small { display: block; font-size: 0.74rem; color: #64748b; } .cc-dot { flex: none; width: 10px; height: 10px; border-radius: 50%; background: #10b981; } .cc-row.s-soon .cc-dot { background: #f59e0b; } .cc-row.s-urgent .cc-dot, .cc-row.s-expired .cc-dot, .cc-row.k-ends .cc-dot { background: #f43f5e; } .cc-row.k-starts .cc-dot { background: #3b82f6; }
@media (max-width: 640px) { .cc-day { min-height: 60px; } .cc-ev { font-size: 0; padding: 0; background: none; box-shadow: none; } .cc-ev i { width: 8px; height: 8px; } .cc-day ul { display: flex; flex-wrap: wrap; gap: 3px; } .cc-bar h2 { min-width: 0; font-size: 0.98rem; } }
html.dark .cc-ev { background: rgba(255, 255, 255, 0.06); color: #e2e8f0; } html.dark .cc-ev.s-expired, html.dark .cc-ev.s-urgent { background: rgba(244, 63, 94, 0.18); color: #fecdd3; } html.dark .cc-ev.s-soon { background: rgba(245, 158, 11, 0.16); color: #fde68a; } html.dark .cc-ev.k-renewal { background: rgba(16, 185, 129, 0.16); color: #a7f3d0; } html.dark .cc-ev.k-starts { background: rgba(59, 130, 246, 0.18); color: #bfdbfe; } html.dark .cc-cnt { background: rgba(255, 255, 255, 0.12); }
@media (prefers-reduced-motion: reduce) { .cc-load { animation: none; } }
.cc-ev-link { display: flex; align-items: center; gap: 4px; min-width: 0; color: inherit; text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; border-radius: 6px; }
.cc-ev-link:hover { text-decoration: underline; filter: brightness(0.92); }
button.cc-more { border: 0; background: none; padding: 0; cursor: pointer; font: inherit; color: inherit; }

/* polish */
.cc-cal { animation: cc-rise 0.5s cubic-bezier(0.2, 0.9, 0.3, 1) both; } .cc-side > .lg-card { animation: cc-rise 0.5s cubic-bezier(0.2, 0.9, 0.3, 1) both; } .cc-side > .lg-card:nth-child(2) { animation-delay: 0.08s; } .cc-side > .lg-card:nth-child(3) { animation-delay: 0.16s; }
.cc-day { transition: box-shadow 0.18s ease, transform 0.18s ease, background 0.18s ease; animation: cc-pop 0.4s ease both; } .cc-day:hover { transform: translateY(-2px); background: rgba(59, 130, 246, 0.07); }
.cc-grid .cc-day:nth-child(8n) { animation-delay: 0.02s; } .cc-grid .cc-day:nth-child(9n) { animation-delay: 0.05s; } .cc-grid .cc-day:nth-child(10n) { animation-delay: 0.08s; }
.cc-day.today { animation: cc-pop 0.4s ease both, cc-today 2.6s ease-in-out infinite; } .cc-day.today .cc-n { display: inline-grid; place-items: center; min-width: 22px; height: 22px; padding: 0 4px; border-radius: 99px; color: #fff; background: linear-gradient(145deg, #3b82f6, #1d4ed8); }
.cc-ev { transition: transform 0.15s ease, box-shadow 0.15s ease; } .cc-ev:hover { transform: translateX(2px); box-shadow: 0 6px 12px -8px rgba(15, 37, 64, 0.6); }
.cc-ev.s-expired i, .cc-ev.s-urgent i { animation: cc-blink 1.6s ease-in-out infinite; }
.cc-row { position: relative; overflow: hidden; transition: transform 0.18s ease, box-shadow 0.18s ease; } .cc-row::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: #10b981; } .cc-row.s-soon::before { background: #f59e0b; } .cc-row.s-urgent::before, .cc-row.s-expired::before { background: #f43f5e; }
.cc-row:hover { transform: translateX(3px); box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.4), 0 10px 18px -14px rgba(15, 37, 64, 0.5); }
.cc-side li { animation: cc-slide 0.4s ease both; } .cc-side li:nth-child(2) { animation-delay: 0.05s; } .cc-side li:nth-child(3) { animation-delay: 0.1s; } .cc-side li:nth-child(4) { animation-delay: 0.15s; } .cc-side li:nth-child(n+5) { animation-delay: 0.2s; }
@keyframes cc-rise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
@keyframes cc-pop { from { opacity: 0; transform: scale(0.94); } to { opacity: 1; transform: none; } }
@keyframes cc-slide { from { opacity: 0; transform: translateX(14px); } to { opacity: 1; transform: none; } }
@keyframes cc-today { 0%, 100% { box-shadow: inset 0 0 0 2px #3b82f6, 0 0 0 0 rgba(59, 130, 246, 0.35); } 50% { box-shadow: inset 0 0 0 2px #3b82f6, 0 0 0 5px rgba(59, 130, 246, 0); } }
@keyframes cc-blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }
@media (prefers-reduced-motion: reduce) { .cc-cal, .cc-side > .lg-card, .cc-day, .cc-day.today, .cc-side li, .cc-ev i { animation: none !important; } }
</style>

<template>
  <admin-layout :title="$t('WIMSChat Analytics')">
    <div class="lg-page wca px-4 md:px-0">
      <page-header icon="chart-bar" :title="$t('WIMSChat Analytics')" :subtitle="$t('Activity and storage figures. Counts only, message text is never shown here.')">
        <Link :href="route('wimschat.index')" class="lg-btn lg-btn-soft"><icons name="chat" class="w-4 h-4" />{{ $t('Open WIMSChat') }}</Link>
        <a :href="exportUrl('wimschat.analytics.print')" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
        <a :href="exportUrl('wimschat.analytics.pdf')" class="lg-btn lg-btn-soft"><icons name="doc-text" class="w-4 h-4" />{{ $t('PDF') }}</a>
        <a :href="exportUrl('wimschat.analytics.export')" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />{{ $t('Excel') }}</a>
        <Link v-if="can_logs" :href="route('activity', { module: 'wimschat' })" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('System Logs') }}</Link>
      </page-header>

      <section class="lg-card wca-filter">
        <label class="wca-f"><span>{{ $t('From') }}</span><date-picker v-model="from" :max="to" flush /></label>
        <label class="wca-f"><span>{{ $t('To') }}</span><date-picker v-model="to" :min="from" flush /></label>
        <button type="button" class="lg-btn lg-btn-primary" @click="apply"><icons name="refresh" class="w-4 h-4" />{{ $t('Apply') }}</button>
        <button v-for="d in [7, 30, 90]" :key="d" type="button" class="lg-btn lg-btn-soft" @click="preset(d)">{{ $t('Last :n days', { n: d }) }}</button>
      </section>

      <section class="wca-cards">
        <stat-card :index="0" tone="blue" icon="chat" :label="$t('Messages in period')" :value="k.messages_range" :chip="k.messages_today + ' ' + $t('today')" />
        <stat-card :index="1" tone="purple" icon="users" :label="$t('Active people')" :value="k.active_users" :chip="k.online_now + ' ' + $t('online now')" />
        <stat-card :index="2" tone="teal" icon="collection" :label="$t('Conversations')" :value="k.conversations" :chip="k.groups + ' ' + $t('groups')" />
        <stat-card :index="3" tone="orange" icon="clip" :label="$t('Attachments')" :value="k.attachments" :chip="human(k.storage_bytes)" />
        <stat-card :index="4" tone="emerald" icon="smile" :label="$t('Reactions')" :value="k.reactions" :chip="k.nudges + ' ' + $t('nudges')" />
        <stat-card :index="5" tone="rose" icon="exclamation" :label="$t('Failed uploads')" :value="k.failed_uploads" :chip="human(k.upload_bytes_today) + ' ' + $t('uploaded today')" />
      </section>

      <!-- messages per day -->
      <section class="lg-card wca-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="chart-bar" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Messages per day') }}</h2><p class="lg-card-sub">{{ $t('Text messages sent each day in the period.') }}</p></div>
          <div class="wca-seg" role="tablist"><button v-for="m in metrics" :key="m.key" type="button" role="tab" :aria-selected="metric === m.key" :class="{ 'is-on': metric === m.key }" @click="metric = m.key">{{ m.label }}</button></div>
        </header>
        <div class="wca-chart" @mouseleave="hover = null">
          <svg :viewBox="`0 0 ${W} ${H}`" role="img" :aria-label="$t('Messages per day chart')" preserveAspectRatio="none">
            <g class="wca-grid"><line v-for="t in ticks" :key="t.y" :x1="PL" :x2="W - PR" :y1="t.y" :y2="t.y" /></g>
            <g class="wca-axis"><text v-for="t in ticks" :key="'t' + t.y" :x="PL - 6" :y="t.y + 4" text-anchor="end">{{ t.label }}</text></g>
            <path v-if="area" :d="area" class="wca-area" />
            <path v-if="line" :d="line" class="wca-line" />
            <circle v-if="last" :cx="last.x" :cy="last.y" r="4.5" class="wca-end" />
            <g class="wca-axis"><text v-for="(l, i) in xlabels" :key="'x' + i" :x="l.x" :y="H - 8" text-anchor="middle">{{ l.label }}</text></g>
            <rect v-for="(p, i) in pts" :key="'h' + i" :x="p.x - step / 2" :y="PT" :width="Math.max(step, 4)" :height="H - PT - PB" fill="transparent" @mouseenter="hover = i" @touchstart.passive="hover = i" />
            <line v-if="hover !== null && pts[hover]" :x1="pts[hover].x" :x2="pts[hover].x" :y1="PT" :y2="H - PB" class="wca-cross" />
            <circle v-if="hover !== null && pts[hover]" :cx="pts[hover].x" :cy="pts[hover].y" r="5" class="wca-end" />
          </svg>
          <div v-if="hover !== null && pts[hover]" class="wca-tip" :style="{ left: Math.min(Math.max(pts[hover].x / W * 100, 12), 88) + '%' }"><b>{{ pts[hover].date }}</b><span>{{ metricLabel }}: {{ pts[hover].text }}</span></div>
        </div>
      </section>

      <div class="wca-grid2">
        <section class="lg-card wca-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="users" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Most active people') }}</h2></div></header>
          <ul class="wca-bars">
            <li v-for="r in stats.topUsers" :key="r.id"><span class="wca-bn">{{ r.name }}</span><span class="wca-bt"><i :style="{ width: pct(r.messages, maxMsg) + '%' }"></i></span><b>{{ r.messages }}</b><small>{{ r.files }} {{ $t('files') }} · {{ human(r.bytes) }}</small></li>
            <li v-if="!stats.topUsers.length" class="wca-none">{{ $t('No chat activity in this period.') }}</li>
          </ul>
        </section>
        <section class="lg-card wca-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-teal"><icons name="group" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Most active groups') }}</h2></div></header>
          <ul class="wca-bars">
            <li v-for="g in stats.groups" :key="g.id"><span class="wca-bn">{{ g.title }}</span><span class="wca-bt"><i :style="{ width: pct(g.messages, maxGroup) + '%' }"></i></span><b>{{ g.messages }}</b><small>{{ g.people }} {{ $t('people') }}</small></li>
            <li v-if="!stats.groups.length" class="wca-none">{{ $t('No group activity in this period.') }}</li>
          </ul>
        </section>
      </div>

      <!-- storage -->
      <section class="lg-card wca-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="clip" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Storage') }}</h2><p class="lg-card-sub">{{ $t(':n in :f attachments. Forwarded copies share the original file and use no extra space.', { n: human(k.storage_bytes), f: k.attachments }) }}</p></div></header>
        <div class="wca-grid3">
          <div><h3>{{ $t('By role') }}</h3><ul class="wca-bars is-sm"><li v-for="r in stats.byRole" :key="r.name"><span class="wca-bn">{{ r.name }}</span><span class="wca-bt"><i :style="{ width: pct(r.bytes, maxRole) + '%' }"></i></span><b>{{ human(r.bytes) }}</b></li><li v-if="!stats.byRole.length" class="wca-none">{{ $t('Nothing stored yet.') }}</li></ul></div>
          <div><h3>{{ $t('By file type') }}</h3><ul class="wca-bars is-sm"><li v-for="r in stats.byType" :key="r.key"><span class="wca-bn">{{ r.key }}</span><span class="wca-bt"><i :style="{ width: pct(r.bytes, maxType) + '%' }"></i></span><b>{{ human(r.bytes) }}</b></li><li v-if="!stats.byType.length" class="wca-none">{{ $t('Nothing stored yet.') }}</li></ul></div>
          <div><h3>{{ $t('Largest users') }}</h3><ul class="wca-bars is-sm"><li v-for="r in stats.byUser" :key="r.id"><span class="wca-bn">{{ r.name }}</span><span class="wca-bt"><i :style="{ width: pct(r.bytes, maxUser) + '%' }"></i></span><b>{{ human(r.bytes) }}</b></li><li v-if="!stats.byUser.length" class="wca-none">{{ $t('Nothing stored yet.') }}</li></ul></div>
        </div>
      </section>

      <section class="lg-card wca-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-rose"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Daily quota use, today') }}</h2><p class="lg-card-sub">{{ $t('People who uploaded files today, against their role quota.') }}</p></div></header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead><tr><th>{{ $t('Person') }}</th><th>{{ $t('Used') }}</th><th>{{ $t('Quota') }}</th><th>{{ $t('Share') }}</th></tr></thead>
            <tbody>
              <tr v-for="q in stats.quota" :key="q.id">
                <td :data-label="$t('Person')"><span class="lg-main">{{ q.name }}</span></td>
                <td :data-label="$t('Used')">{{ human(q.used) }}</td>
                <td :data-label="$t('Quota')">{{ human(q.limit) }}</td>
                <td :data-label="$t('Share')"><span class="wca-meter" :class="{ 'is-hot': q.pct >= 90 }"><i :style="{ width: q.pct + '%' }"></i></span> {{ q.pct }}%</td>
              </tr>
              <tr v-if="!stats.quota.length"><td colspan="4" class="wca-none">{{ $t('No uploads today.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatCard from '@/Shared/Page/StatCard.vue';
import { human } from '@/Core/wimschat';

export default {
  components: { AdminLayout, PageHeader, StatCard, Link },
  props: { stats: Object, filters: Object, can_logs: Boolean },
  data() {
    return { from: this.filters.from, to: this.filters.to, metric: 'messages', hover: null, W: 900, H: 280, PL: 44, PR: 16, PT: 16, PB: 30 };
  },
  computed: {
    k() {
      return this.stats.kpi;
    },
    metrics() {
      return [{ key: 'messages', label: this.$t('Messages') }, { key: 'files', label: this.$t('Files') }, { key: 'bytes', label: this.$t('Upload size') }];
    },
    metricLabel() {
      return this.metrics.find(m => m.key === this.metric).label;
    },
    values() {
      return this.stats.series.map(r => r[this.metric]);
    },
    maxY() {
      const m = Math.max(1, ...this.values);
      const pow = 10 ** Math.floor(Math.log10(m));
      return Math.ceil(m / pow) * pow;
    },
    step() {
      return (this.W - this.PL - this.PR) / Math.max(1, this.values.length - 1);
    },
    pts() {
      return this.stats.series.map((r, i) => ({
        x: this.PL + i * this.step,
        y: this.PT + (1 - r[this.metric] / this.maxY) * (this.H - this.PT - this.PB),
        date: r.date,
        text: this.metric === 'bytes' ? human(r.bytes) : r[this.metric],
      }));
    },
    line() {
      return this.pts.length ? this.pts.map((p, i) => (i ? 'L' : 'M') + p.x.toFixed(1) + ' ' + p.y.toFixed(1)).join(' ') : '';
    },
    area() {
      if (!this.pts.length) return '';
      const base = this.H - this.PB;
      return this.line + ` L${this.pts[this.pts.length - 1].x.toFixed(1)} ${base} L${this.pts[0].x.toFixed(1)} ${base} Z`;
    },
    last() {
      return this.pts.length ? this.pts[this.pts.length - 1] : null;
    },
    ticks() {
      return [0, 0.25, 0.5, 0.75, 1].map(f => ({ y: this.PT + (1 - f) * (this.H - this.PT - this.PB), label: this.metric === 'bytes' ? human(this.maxY * f) : String(Math.round(this.maxY * f)) }));
    },
    xlabels() {
      const n = this.pts.length;
      const every = Math.max(1, Math.ceil(n / 7));
      return this.pts.filter((_, i) => i % every === 0).map(p => ({ x: p.x, label: p.date.slice(5) }));
    },
    maxMsg() { return Math.max(1, ...this.stats.topUsers.map(r => r.messages)); },
    maxGroup() { return Math.max(1, ...this.stats.groups.map(r => r.messages)); },
    maxRole() { return Math.max(1, ...this.stats.byRole.map(r => r.bytes)); },
    maxType() { return Math.max(1, ...this.stats.byType.map(r => r.bytes)); },
    maxUser() { return Math.max(1, ...this.stats.byUser.map(r => r.bytes)); },
  },
  methods: {
    human,
    pct(v, m) {
      return Math.max(2, Math.round((v / m) * 100));
    },
    exportUrl(name) {
      return this.route(name, { from: this.from, to: this.to });
    },
    apply() {
      this.$inertia.get(this.route('wimschat.analytics'), { from: this.from, to: this.to }, { preserveScroll: true });
    },
    preset(d) {
      const to = new Date();
      const from = new Date(Date.now() - (d - 1) * 86400000);
      this.to = to.toISOString().slice(0, 10);
      this.from = from.toISOString().slice(0, 10);
      this.apply();
    },
  },
};
</script>

<style scoped>
.wca-filter { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px; margin-bottom: 1rem; }
.wca-f { display: flex; flex-direction: column; gap: 4px; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--lg-faint); }
.wca-f input { height: 2.6rem; padding: 0 0.8rem; border: 1px solid var(--lg-line); border-radius: 0.85rem; background: var(--lg-soft); color: var(--lg-ink); font-weight: 600; }
.wca-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(230px, 100%), 1fr)); gap: 14px; margin-bottom: 1rem; }
.wca-card { margin-bottom: 1rem; }
.wca-seg { margin-left: auto; display: inline-flex; padding: 3px; border-radius: 0.8rem; background: var(--lg-soft); }
.wca-seg button { border: 0; background: transparent; padding: 0.35rem 0.8rem; border-radius: 0.6rem; font-size: 0.78rem; font-weight: 700; color: var(--lg-mute); }
.wca-seg button.is-on { background: var(--lg-card); color: var(--lg-ink); box-shadow: 0 1px 3px rgba(15, 23, 42, 0.15); }
.wca-chart { position: relative; width: 100%; }
.wca-chart svg { display: block; width: 100%; height: 280px; }
.wca-grid line { stroke: var(--lg-line); stroke-width: 1; stroke-dasharray: 3 4; }
.wca-axis text { fill: var(--lg-faint); font-size: 11px; font-weight: 600; }
.wca-area { fill: rgba(47, 111, 179, 0.14); }
.wca-line { fill: none; stroke: #2f6fb3; stroke-width: 2.4; stroke-linejoin: round; stroke-linecap: round; vector-effect: non-scaling-stroke; }
.wca-end { fill: #2f6fb3; stroke: var(--lg-card); stroke-width: 2; }
.wca-cross { stroke: var(--lg-faint); stroke-width: 1; }
.wca-tip { position: absolute; top: 4px; transform: translateX(-50%); padding: 6px 10px; border-radius: 0.7rem; background: #0f172a; color: #fff; font-size: 0.78rem; pointer-events: none; display: flex; flex-direction: column; box-shadow: 0 10px 24px -12px rgba(15, 23, 42, 0.6); white-space: nowrap; }
.wca-grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(420px, 100%), 1fr)); gap: 1rem; }
.wca-grid3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(260px, 100%), 1fr)); gap: 1.2rem; }
.wca-grid3 h3 { margin: 0 0 8px; font-size: 0.8rem; font-weight: 800; color: var(--lg-ink); }
.wca-bars { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.wca-bars li { display: grid; grid-template-columns: minmax(70px, 130px) 1fr auto; align-items: center; gap: 4px 10px; font-size: 0.84rem; }
.wca-bars li small { grid-column: 2 / -1; color: var(--lg-mute); font-size: 0.72rem; margin-top: -4px; }
.wca-bn { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 700; color: var(--lg-ink); }
.wca-bt { height: 8px; border-radius: 9999px; background: var(--lg-soft); overflow: hidden; }
.wca-bt i { display: block; height: 100%; border-radius: 9999px; background: linear-gradient(90deg, #2f6fb3, #5b9bde); }
.wca-bars b { font-variant-numeric: tabular-nums; color: var(--lg-ink); }
.wca-none { grid-template-columns: 1fr !important; color: var(--lg-mute); }
.wca-meter { display: inline-block; width: 90px; height: 8px; border-radius: 9999px; background: var(--lg-soft); overflow: hidden; vertical-align: middle; margin-right: 6px; }
.wca-meter i { display: block; height: 100%; background: #059669; }
.wca-meter.is-hot i { background: #be123c; }
@media (max-width: 640px) { .wca-seg { margin-left: 0; width: 100%; } .wca-seg button { flex: 1; } .wca-chart svg { height: 220px; } .wca-bars li { grid-template-columns: minmax(60px, 100px) 1fr auto; } }
</style>

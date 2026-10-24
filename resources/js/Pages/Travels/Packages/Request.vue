<template>
  <admin-layout :title="$t('Travel Request from :n', { n: package.name })">
    <div class="lg-page ac sc-ui tr-ui px-4 md:px-0">
      <page-header icon="send" :title="$t('Travel Request')" :subtitle="$t('Say who travels and when. The request goes to approval, where the amounts can still be changed.')">
        <Link :href="route('travels.packages.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
      </page-header>

      <!-- the package -->
      <section class="lg-card rq-pkg">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="plane" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ package.name }}</h2><p class="lg-card-sub">{{ package.route }} · {{ package.trip_label }}</p></div></header>
        <div class="rq-am">
          <div v-for="a in cols" :key="a.k" class="rq-a" :style="{ '--c': a.c }"><small><icons :name="a.i" class="w-3.5 h-3.5" />{{ a.l }}</small><b>{{ money(package[a.k]) }}</b><em>{{ $t('per person') }}</em></div>
        </div>
      </section>

      <form @submit.prevent="send">
        <!-- 1 people -->
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="users" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Who travels?') }}</h2><p class="lg-card-sub">{{ $t('How many people travel, then choose each one: a staff member, a user, or someone else.') }}</p></div></header>
          <div class="rq-count">
            <span class="rq-count__l">{{ $t('How many people?') }}</span>
            <div class="rq-step">
              <button type="button" class="rq-sb" :disabled="people.length <= 1" :aria-label="$t('Fewer')" @click="setCount(people.length - 1)"><icons name="minus" class="w-4 h-4" /></button>
              <input class="rq-n" :value="people.length" inputmode="numeric" :aria-label="$t('How many people?')" @change="e => setCount(Number(e.target.value))" />
              <button type="button" class="rq-sb" :disabled="people.length >= 30" :aria-label="$t('More')" @click="setCount(people.length + 1)"><icons name="plus" class="w-4 h-4" /></button>
            </div>
          </div>
          <ul class="rq-people">
            <li v-for="(p, i) in people" :key="p.key" class="rq-p">
              <span class="rq-no">{{ i + 1 }}</span>
              <div class="rq-p__body">
                <div class="rq-kind" role="tablist">
                  <button v-for="k in kinds" :key="k.key" type="button" class="rq-k" :class="{ 'is-on': p.traveller_type === k.key }" @click="setKind(p, k.key)"><icons :name="k.i" class="w-3.5 h-3.5" />{{ k.l }}</button>
                </div>
                <div class="ac-f" v-if="p.traveller_type === 'staff'"><acct-select :model-value="p.staff_id" :options="staffFor(p)" :placeholder="$t('Choose the staff member')" clearable @update:modelValue="v => pickStaff(p, v)" /></div>
                <div class="ac-f" v-else-if="p.traveller_type === 'user'"><acct-select :model-value="p.user_id" :options="usersFor(p)" :placeholder="$t('Choose the user')" clearable @update:modelValue="v => pickUser(p, v)" /></div>
                <div class="rq-other" v-else><input v-model="p.name" :placeholder="$t('Name of the traveller')" /><input v-model="p.phone" inputmode="tel" :placeholder="$t('Mobile')" /></div>
                <span class="ac-err">{{ err('people.' + i + '.staff_id') || err('people.' + i + '.user_id') || err('people.' + i + '.name') }}</span>
              </div>
            </li>
          </ul>
          <span class="ac-err">{{ err('people') }}</span>
        </section>

        <!-- 2 when -->
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="calendar" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('When and why?') }}</h2><p class="lg-card-sub">{{ $t('The dates and the reason. A date in the future is normal.') }}</p></div></header>
          <div class="lg-fields">
            <div class="ac-f"><label>{{ $t('Departs') }} *</label><date-picker v-model="form.depart_date" :clearable="false" :min="today" /><span class="ac-err">{{ err('depart_date') }}</span></div>
            <div v-if="package.trip_type === 'return'" class="ac-f"><label>{{ $t('Returns') }} *</label><date-picker v-model="form.return_date" :min="form.depart_date || today" /><span class="ac-err">{{ err('return_date') }}</span></div>
            <div class="ac-f"><label>{{ $t('Kind of travel') }}</label><acct-select v-model="form.travel_type" :options="types.map(x => ({ value: x, label: x }))" :placeholder="$t('Choose...')" clearable /></div>
            <div class="ac-f"><label>{{ $t('Ticket agent') }}</label><acct-select v-model="form.ticket_agent_contact_id" :options="agents" :placeholder="$t('Choose the ticket agent')" clearable /><span class="ac-err">{{ err('ticket_agent_contact_id') }}</span></div>
            <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Purpose') }}</label><textarea v-model="form.purpose" rows="2" :placeholder="$t('Why are they travelling?')" /></div>
            <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Notes') }}</label><textarea v-model="form.notes" rows="2" /></div>
          </div>
        </section>

        <!-- what it comes to -->
        <section class="lg-card rq-total">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-green"><icons name="calculator" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('What it comes to') }}</h2><p class="lg-card-sub">{{ $t('The amounts of the package times the number of people. The approver can still change them.') }}</p></div></header>
          <table class="rq-t">
            <tbody>
              <tr v-for="a in cols" :key="a.k" :style="{ '--c': a.c }"><td><icons :name="a.i" class="w-3.5 h-3.5" />{{ a.l }}</td><td>{{ money(package[a.k]) }} × {{ people.length }}</td><td class="num">{{ money(Number(package[a.k]) * people.length) }}</td></tr>
              <tr class="rq-t__sum"><td colspan="2">{{ $t('Total for :n people', { n: people.length }) }}</td><td class="num">{{ package.currency }} {{ money(grand) }}</td></tr>
              <tr v-if="package.currency !== 'TZS'" class="rq-t__tzs"><td colspan="2">{{ $t('In TZS at :r', { r: rate(package.exchange_rate) }) }}</td><td class="num">TZS {{ money(grand * Number(package.exchange_rate)) }}</td></tr>
            </tbody>
          </table>
          <div class="rq-send">
            <p class="lg-note lg-note-amber"><icons name="clock" class="w-4 h-4" /><span>{{ $t('This is sent as a Travel Request. Nothing is owed to the ticket agent until it is approved.') }}</span></p>
            <button type="submit" class="lg-btn tone tone-solid" style="--tone: #7c3aed" :disabled="form.processing || !ready"><span class="tone-ico"><icons name="send" class="w-4 h-4" /></span>{{ $t('Send Travel Request') }}</button>
          </div>
        </section>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';

let seq = 0;
const blankPerson = () => ({ key: ++seq, traveller_type: 'staff', staff_id: null, user_id: null, name: '', phone: '' });

export default {
  components: { AdminLayout, PageHeader, Link, DatePicker, AcctSelect },
  props: { package: Object, types: Array, agents: Array, staff: Array, users: Array, today: String },
  data() {
    return {
      people: [blankPerson()],
      form: this.$inertia.form({ depart_date: this.today, return_date: null, travel_type: null, purpose: '', notes: '', ticket_agent_contact_id: this.package.ticket_agent_id || null, people: [] }),
    };
  },
  computed: {
    cols() {
      return [
        { k: 'ticket_amount', l: this.$t('Ticket'), i: 'plane', c: '#0284c7' }, { k: 'accommodation_amount', l: this.$t('Accommodation'), i: 'building', c: '#7c3aed' },
        { k: 'per_diem_amount', l: this.$t('Expenses / Per Diem'), i: 'cash', c: '#d97706' }, { k: 'transport_amount', l: this.$t('Inside Transport'), i: 'car', c: '#0d9488' },
      ];
    },
    kinds() { return [{ key: 'staff', l: this.$t('Staff'), i: 'user' }, { key: 'user', l: this.$t('User'), i: 'users' }, { key: 'other', l: this.$t('Other'), i: 'edit' }]; },
    grand() { return Number(this.package.total) * this.people.length; },
    ready() {
      return !!this.form.depart_date && (this.package.trip_type !== 'return' || !!this.form.return_date) && this.people.length > 0
        && this.people.every(p => (p.traveller_type === 'staff' && p.staff_id) || (p.traveller_type === 'user' && p.user_id) || (p.traveller_type === 'other' && (p.name || '').trim() !== ''));
    },
  },
  methods: {
    err(k) { return (this.$page.props.errors || {})[k]; },
    money(v) { return Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    rate(v) { return Number(v).toLocaleString(undefined, { maximumFractionDigits: 6 }); },
    setCount(n) {
      n = Math.max(1, Math.min(30, Math.round(n) || 1));
      while (this.people.length < n) this.people.push(blankPerson());
      if (this.people.length > n) this.people.splice(n);
    },
    setKind(p, k) { p.traveller_type = k; p.staff_id = null; p.user_id = null; if (k !== 'other') { p.name = ''; p.phone = ''; } },
    // a person can only be chosen once: the ones already picked on other rows are left out of the list
    staffFor(p) { const used = new Set(this.people.filter(x => x !== p && x.staff_id).map(x => x.staff_id)); return this.staff.filter(s => !used.has(s.value)); },
    usersFor(p) { const used = new Set(this.people.filter(x => x !== p && x.user_id && x.traveller_type === 'user').map(x => x.user_id)); return this.users.filter(u => !used.has(u.value)); },
    pickStaff(p, id) { p.staff_id = id || null; const s = this.staff.find(x => x.value === id); p.name = s ? s.name : ''; p.phone = s && s.phone ? s.phone : ''; },
    pickUser(p, id) { p.user_id = id || null; const u = this.users.find(x => x.value === id); p.name = u ? u.name : ''; },
    send() {
      this.form.transform(d => ({ ...d, return_date: this.package.trip_type === 'return' ? d.return_date : null, people: this.people.map(p => ({ traveller_type: p.traveller_type, staff_id: p.staff_id, user_id: p.traveller_type === 'user' ? p.user_id : null, name: p.name, phone: p.phone })) }))
        .post(route('travels.packages.request.store', this.package.id), { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.rq-pkg { margin-bottom: 12px; }
.rq-am { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; margin-top: 10px; }
.rq-a { display: grid; gap: 1px; padding: 9px 11px; border-radius: 14px; background: color-mix(in srgb, var(--c) 8%, transparent); box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--c) 26%, transparent); } .rq-a small { display: flex; align-items: center; gap: 5px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--c); } .rq-a small :deep(div) { flex: none; margin: 0; } .rq-a b { font-size: 1.05rem; font-variant-numeric: tabular-nums; color: var(--sc-ink, #0f2540); } .rq-a em { font-style: normal; font-size: 0.68rem; font-weight: 700; color: var(--sc-mute, #64748b); }
.rq-count { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; margin: 6px 0 12px; } .rq-count__l { font-size: 0.82rem; font-weight: 800; color: var(--sc-ink, #0f2540); }
.rq-step { display: inline-flex; align-items: center; gap: 6px; padding: 4px; border-radius: 999px; background: #f1f5f9; box-shadow: inset 0 0 0 1px #e2e8f0; }
.rq-sb { display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box; width: 36px; height: 36px; min-width: 0 !important; min-height: 0 !important; padding: 0 !important; border: 0; border-radius: 50%; cursor: pointer; color: #fff; background: linear-gradient(145deg, #7c3aed, #4c1d95); box-shadow: 0 8px 12px -8px #4c1d95; } .rq-sb:disabled { opacity: 0.4; cursor: not-allowed; } .rq-sb > div { flex: none; margin: 0; }
.rq-n { width: 56px; height: 36px; text-align: center; border: 0 !important; background: transparent !important; box-shadow: none !important; font-size: 1.2rem; font-weight: 800; color: var(--sc-ink, #0f2540); padding: 0 !important; }
.rq-people { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.rq-p { display: flex; gap: 12px; padding: 12px; border-radius: 18px; background: color-mix(in srgb, #7c3aed 4%, transparent); box-shadow: inset 0 0 0 1px color-mix(in srgb, #7c3aed 18%, transparent); }
.rq-no { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 32px; height: 32px; border-radius: 11px; font-weight: 800; color: #fff; background: linear-gradient(145deg, #8b5cf6, #5b21b6); }
.rq-p__body { min-width: 0; flex: 1; display: grid; gap: 8px; }
.rq-kind { display: inline-flex; flex-wrap: wrap; gap: 6px; } .rq-k { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border: 0; border-radius: 999px; cursor: pointer; font-size: 0.76rem; font-weight: 800; color: #5b21b6; background: #ede9fe; } .rq-k.is-on { color: #fff; background: linear-gradient(135deg, #8b5cf6, #5b21b6); } .rq-k :deep(div) { flex: none; margin: 0; }
.rq-other { display: grid; grid-template-columns: 1.4fr 1fr; gap: 8px; }
.rq-t { width: 100%; border-collapse: collapse; margin-top: 8px; } .rq-t td { padding: 9px 6px; border-bottom: 1px solid var(--sc-line, #e3e9f2); font-size: 0.86rem; } .rq-t td:first-child { font-weight: 700; color: var(--c, var(--sc-ink, #0f2540)); } .rq-t td:first-child :deep(div) { display: inline-block; vertical-align: middle; margin: 0 6px 0 0; } .rq-t .num { text-align: right; font-variant-numeric: tabular-nums; font-weight: 800; }
.rq-t__sum td { border: 0; padding-top: 12px; font-size: 1rem; font-weight: 800; color: #5b21b6 !important; } .rq-t__tzs td { border: 0; font-size: 0.78rem; color: var(--sc-mute, #64748b) !important; }
.rq-send { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-top: 12px; } .rq-send .lg-note { flex: 1 1 280px; margin: 0; }
html.dark .rq-step { background: #1b2342; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.12); } html.dark .rq-k { background: #2e1f5c; color: #c4b5fd; } html.dark .rq-t__sum td { color: #c4b5fd !important; }
@media (max-width: 640px) { .rq-other { grid-template-columns: 1fr; } .rq-send .lg-btn { width: 100%; } .rq-p { padding: 10px; gap: 9px; } }
</style>

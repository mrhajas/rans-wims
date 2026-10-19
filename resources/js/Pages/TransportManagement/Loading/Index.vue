<template>
  <admin-layout :title="$t('Loading & Unloading')">
    <div class="lg-page px-4 md:px-0 tl-page">
      <page-header icon="users" :title="$t('Loading & Unloading')" :subtitle="$t('Daily attendance of the loading and unloading workers, what they earned and what is still owed.')">
        <Link v-if="can.report" :href="route('transport-loading.report')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" />{{ $t('Loading & Unloading Report') }}</Link>
        <a :href="addPersonnelUrl" target="_blank" rel="noopener" class="lg-btn lg-btn-soft lg-tip" :data-tip="addPersonnelTip"><icons name="plus" class="w-4 h-4" />{{ $t('Add Personnel') }}</a>
      </page-header>

      <stat-tiles :items="tiles" />

      <div class="tl-tabs" role="tablist">
        <button type="button" role="tab" :aria-selected="tab === 'balances'" :class="{ 'is-on': tab === 'balances' }" @click="go({ tab: 'balances' })"><icons name="cash" class="w-4 h-4" />{{ $t('Balances & payments') }}</button>
        <button type="button" role="tab" :aria-selected="tab === 'attendance'" :class="{ 'is-on': tab === 'attendance' }" @click="go({ tab: 'attendance' })"><icons name="calendar" class="w-4 h-4" />{{ $t('Daily attendance') }}</button>
      </div>

      <!-- ------------------------------------------------------------------------------ attendance -->
      <section v-if="tab === 'attendance'" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="calendar" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Attendance for the day') }}</h2>
            <p class="lg-card-sub">{{ $t('Each worker marked Present earns') }} {{ $money(rate) }} {{ $t('for the day.') }}</p>
          </div>
          <div v-if="can.print || can.export" class="tl-head-actions print:hidden">
            <a v-if="can.print" :href="reportUrl('print')" target="_blank" rel="noopener" class="lg-btn lg-btn-primary"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
            <a v-if="can.print" :href="reportUrl('pdf')" class="lg-btn lg-btn-dark"><icons name="download" class="w-4 h-4" />{{ $t('Download PDF') }}</a>
            <a v-if="can.export" :href="reportUrl('export')" class="lg-btn lg-btn-green"><icons name="table" class="w-4 h-4" />{{ $t('Export Excel') }}</a>
          </div>
        </header>

        <div class="lg-fields tl-day-fields">
          <div><date-picker :label="$t('Date')" v-model="day" :max="today" /></div>
          <div>
            <auto-complete id="tl_project" :label="$t('Project / site (optional)')" v-model="projectId" :suggestions="projectOptions" clearable :placeholder="$t('Pick a project / site…')" />
          </div>
          <div>
            <auto-complete id="tl_place" :label="$t('Or another place (optional)')" v-model="otherPlace" :suggestions="placeOptions" freeform clearable :placeholder="$t('Write a place or pick one…')" />
          </div>
        </div>
        <p class="tl-where-hint"><icons name="info" class="w-4 h-4" />{{ $t('Worked at a project? Pick it. Worked somewhere else? Write the place in the second field or choose one from the list; places you write are saved and offered next time. Use one or the other.') }}</p>

        <div v-if="!workers.length" class="tl-empty">
          <icons name="users" class="w-8 h-8 mx-auto mb-2 opacity-50" />
          <p>{{ $t('No workers yet. Add a contact in the category') }} <b>{{ category }}</b> {{ $t('and they will appear here.') }}</p>
          <a :href="addPersonnelUrl" target="_blank" rel="noopener" class="lg-btn lg-btn-primary mt-2 lg-tip" :data-tip="addPersonnelTip"><icons name="plus" class="w-4 h-4" />{{ $t('Add Personnel') }}</a>
        </div>

        <template v-else>
          <div class="tl-bulk">
            <span class="tl-count"><b>{{ presentCount }}</b> {{ $t('present') }} · <b>{{ absentCount }}</b> {{ $t('absent') }} · <b>{{ unmarkedCount }}</b> {{ $t('not marked') }}</span>
            <span class="tl-bulk-btns">
              <button type="button" class="lg-btn lg-btn-soft" :disabled="!can.create" @click="markAll('Present')"><icons name="tick" class="w-4 h-4" />{{ $t('All present') }}</button>
              <button type="button" class="lg-btn lg-btn-soft" :disabled="!can.create" @click="markAll('Absent')"><icons name="cross" class="w-4 h-4" />{{ $t('All absent') }}</button>
            </span>
          </div>

          <ul class="tl-list">
            <li v-for="w in workers" :key="w.contact_id" class="tl-row" :class="rowClass(w)">
              <div class="tl-who">
                <span class="tl-ico" aria-hidden="true"><icons name="user" class="w-5 h-5" /></span>
                <div class="tl-who-txt">
                  <Link :href="route('transport-loading.show', w.contact_id)" class="tl-name">{{ w.name }}</Link>
                  <span class="tl-sub">{{ w.phone || '' }}</span>
                </div>
              </div>
              <div class="tl-rate lg-tip" :class="{ 'is-custom': isCustom(w) }" :data-tip="rateTip(w)">
                <small><span class="tl-rate-slot" aria-hidden="true"><icons v-if="isCustom(w)" name="exclamation" class="w-3 h-3 tl-rate-flag" /></span>{{ $t('Daily Rate') }}</small>
                <input v-model.number="rates[w.contact_id]" type="number" min="0" step="500" class="tl-rate-input" :disabled="locked(w)" :aria-label="$t('Daily Rate') + ' ' + w.name" @focus="$event.target.select()" />
              </div>
              <div class="tl-seg" role="group" :aria-label="w.name">
                <button type="button" class="tl-present" :class="{ 'is-on': marks[w.contact_id] === 'Present' }" :disabled="locked(w)" :aria-pressed="marks[w.contact_id] === 'Present'" @click="mark(w, 'Present')"><icons name="tick" class="w-4 h-4" />{{ $t('Present') }}</button>
                <button type="button" class="tl-absent" :class="{ 'is-on': marks[w.contact_id] === 'Absent' }" :disabled="locked(w)" :aria-pressed="marks[w.contact_id] === 'Absent'" @click="mark(w, 'Absent')"><icons name="cross" class="w-4 h-4" />{{ $t('Absent') }}</button>
              </div>
              <div class="tl-amt">{{ amountOf(w) ? $money(amountOf(w)) : '' }}</div>
              <button v-if="w.attendance_id && can.delete" type="button" class="tl-del lg-tip" :data-tip="$t('Remove this day')" :aria-label="$t('Remove this day')" @click="removeDay(w)"><icons name="trash" class="w-4 h-4" /></button>
              <span v-else-if="locked(w)" class="tl-lock lg-tip" :data-tip="$t('Already saved. Changing it needs the correct-attendance permission.')"><icons name="lock-closed" class="w-4 h-4" /></span>
              <span v-else class="tl-slot" aria-hidden="true"></span>
            </li>
          </ul>

          <div class="lg-actionbar tl-actionbar">
            <span class="lg-actionbar-note tl-daytotal">{{ $t('Day total') }}: <b>{{ $money(dayTotal) }}</b> ({{ dayBreakdown }})</span>
            <div class="lg-actionbar-btns">
              <button type="button" class="lg-btn lg-btn-green" :disabled="saving || !can.create || !changedEntries.length || hasInvalidRate" @click="save">
                <icons :name="saving ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': saving }" />{{ saving ? $t('Saving…') : $t('Save attendance') }}
              </button>
            </div>
          </div>
        </template>
      </section>

      <!-- ------------------------------------------------------------------------------ balances -->
      <section v-else class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="cash" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Balances & payments') }}</h2>
            <p class="lg-card-sub">{{ $t('Earned = days present × daily rate. Balance = previous balance + everything earned up to the end date - everything paid.') }}</p>
          </div>
          <div v-if="can.print || can.export" class="tl-head-actions print:hidden">
            <a v-if="can.print" :href="reportUrl('print')" target="_blank" rel="noopener" class="lg-btn lg-btn-primary"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
            <a v-if="can.print" :href="reportUrl('pdf')" class="lg-btn lg-btn-dark"><icons name="download" class="w-4 h-4" />{{ $t('Download PDF') }}</a>
            <a v-if="can.export" :href="reportUrl('export')" class="lg-btn lg-btn-green"><icons name="table" class="w-4 h-4" />{{ $t('Export Excel') }}</a>
          </div>
        </header>

        <div class="lg-fields tl-day-fields">
          <div><date-picker :label="$t('From')" v-model="from" :max="to || undefined" /></div>
          <div><date-picker :label="$t('To')" v-model="to" :min="from || undefined" /></div>
        </div>

        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th v-if="can.voucher" class="tl-check-col">
                  <input type="checkbox" class="tl-check" :checked="allSelected" :disabled="!eligible.length" :aria-label="$t('Select everyone who can be paid')" @change="toggleAll" />
                </th>
                <th>{{ $t('No') }}</th>
                <th>{{ $t('Worker') }}</th>
                <th class="num">{{ $t('Previous balance') }}</th>
                <th class="num">{{ $t('Days') }}</th>
                <th class="num">{{ $t('Earned') }}</th>
                <th class="num">{{ $t('Paid') }}</th>
                <th class="num">{{ $t('Balance') }}</th>
                <th class="lg-sticky-end"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(r, i) in rows" :key="r.contact_id" :class="{ 'is-selected': selected.includes(r.contact_id) }">
                <td v-if="can.voucher" class="tl-check-col" data-label="">
                  <input type="checkbox" class="tl-check" :checked="selected.includes(r.contact_id)" :disabled="!(r.available > 0)" :aria-label="$t('Select') + ' ' + r.name" @change="toggle(r.contact_id)" />
                </td>
                <td :data-label="$t('No')" class="tl-no">{{ i + 1 }}</td>
                <td :data-label="$t('Worker')">
                  <div class="tl-who">
                    <span class="tl-ico" aria-hidden="true"><icons name="user" class="w-5 h-5" /></span>
                    <div class="tl-who-txt"><Link :href="route('transport-loading.show', r.contact_id)" class="lg-main tl-link">{{ r.name }}</Link><div class="lg-subtext">{{ r.phone || '' }}</div></div>
                  </div>
                </td>
                <td :data-label="$t('Previous balance')" class="num">
                  <span v-if="r.opening" :class="{ 'tl-neg': r.opening < 0 }">{{ $money(r.opening) }}</span><span v-else class="tl-none">-</span>
                  <div v-if="r.opening_date" class="lg-subtext tm-nowrap">{{ $t('as at') }} {{ r.opening_date }}</div>
                </td>
                <td :data-label="$t('Days')" class="num">{{ r.days }}</td>
                <td :data-label="$t('Earned')" class="num">{{ $money(r.earned) }}</td>
                <td :data-label="$t('Paid')" class="num">{{ $money(r.paid) }}</td>
                <td :data-label="$t('Balance')" class="num"><b :class="{ 'tl-owed': r.balance > 0, 'tl-adv': r.balance < 0 }">{{ $money(r.balance) }}</b><div v-if="r.pending > 0" class="lg-subtext tm-nowrap">{{ $money(r.pending) }} {{ $t('on voucher') }}</div></td>
                <td class="lg-sticky-end" data-label="">
                  <div class="lg-actions">
                    <Link :href="route('transport-loading.show', r.contact_id)" class="lg-icon-btn lg-tip" :data-tip="$t('Ledger')" :aria-label="$t('Ledger')"><icons name="eye-flat" /></Link>
                    <button v-if="can.opening" type="button" class="lg-icon-btn is-amber lg-tip" :data-tip="r.opening ? $t('Change previous balance') : $t('Add previous balance')" :aria-label="$t('Previous balance')" @click="opening = r"><icons name="clock" /></button>
                    <button v-if="can.pay || can.voucher" type="button" class="lg-icon-btn is-green lg-tip" :data-tip="$t('Pay (voucher or direct)')" :aria-label="$t('Pay (voucher or direct)')" @click="openPay(r)"><icons name="cash" /></button>
                  </div>
                </td>
              </tr>
              <tr v-if="!rows.length" class="lg-empty-row"><td class="lg-empty" :colspan="can.voucher ? 9 : 8">{{ $t('There is no data to display.') }}</td></tr>
            </tbody>
            <tfoot v-if="rows.length">
              <tr class="tl-foot">
                <td v-if="can.voucher"></td><td></td><td><b>{{ $t('Total') }}</b></td>
                <td class="num"><b>{{ $money(totals.opening) }}</b></td>
                <td class="num"><b>{{ totals.days }} {{ $t('Day(s)') }}</b></td>
                <td class="num"><b>{{ $money(totals.earned) }}</b></td>
                <td class="num"><b>{{ $money(totals.paid) }}</b></td>
                <td class="num"><b>{{ $money(totals.balance) }}</b></td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>

        <div v-if="can.voucher && selected.length" class="tl-bulkbar">
          <span class="tl-bulkbar-text"><b>{{ selected.length }}</b> {{ $t('selected') }} · {{ $t('available to pay') }} <b>{{ $money(selectedAvailable) }}</b></span>
          <span class="tl-bulkbar-btns">
            <button type="button" class="lg-btn lg-btn-soft" @click="selected = []"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button>
            <button type="button" class="lg-btn lg-btn-green" @click="bulkOpen = true"><icons name="doc-text" class="w-4 h-4" />{{ $t('Generate') }} {{ selected.length }} {{ $t('separate vouchers') }}</button>
          </span>
        </div>
      </section>

      <pay-modal v-if="pay" :worker="pay" :can="can" @close="pay = null" />
      <opening-modal v-if="opening" :worker="opening" @close="opening = null" />
      <bulk-voucher-modal v-if="bulkOpen" :items="selectedRows" @close="bulkOpen = false" @done="bulkOpen = false; selected = []" />
    </div>
  </admin-layout>
</template>

<script>
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import PayModal from './PayModal.vue';
import OpeningModal from './OpeningModal.vue';
import BulkVoucherModal from './BulkVoucherModal.vue';

export default {
  components: { Link, AdminLayout, PageHeader, StatTiles, DatePicker, AutoComplete, PayModal, OpeningModal, BulkVoucherModal },

  props: { tab: String, filters: Object, rate: Number, projects: Array, places: { type: Array, default: () => [] }, dayWhere: { type: Object, default: () => ({}) }, category: String, stats: Object, can: Object, workers: { type: Array, default: () => [] }, rows: { type: Array, default: () => [] }, totals: { type: Object, default: () => ({}) } },

  data() {
    return {
      day: this.filters.date,
      from: this.filters.date_from,
      to: this.filters.date_to,
      projectId: this.filters.warehouse_id || this.dayWhere.warehouse_id || null,
      otherPlace: this.dayWhere.other_place || null,
      today: new Date().toLocaleDateString('en-CA'),
      marks: Object.fromEntries((this.workers || []).map(w => [w.contact_id, w.status || null])),
      // Each worker's rate for THIS day: the saved one, or the standard daily rate. It can be changed before marking.
      rates: Object.fromEntries((this.workers || []).map(w => [w.contact_id, w.daily_rate ?? this.rate])),
      saving: false,
      pay: null,
      opening: null,
      selected: [],
      bulkOpen: false,
    };
  },

  computed: {
    // Print / PDF / Excel of what the current tab shows: the day's attendance sheet, or the balances of the chosen period.
    // They go through the Loading & Unloading report, so every one is written to the Download / Print log.
    reportQuery() {
      return this.tab === 'attendance'
        ? { report_type: 'sheet', date_from: this.day, date_to: this.day }
        : { report_type: 'summary', date_from: this.from, date_to: this.to };
    },
    // People who can be put on a voucher: those with something still available to pay.
    eligible() { return (this.rows || []).filter(r => r.available > 0); },
    allSelected() { return this.eligible.length > 0 && this.eligible.every(r => this.selected.includes(r.contact_id)); },
    selectedRows() { return (this.rows || []).filter(r => this.selected.includes(r.contact_id)); },
    selectedAvailable() { return this.selectedRows.reduce((s, r) => s + Number(r.available), 0); },
    addPersonnelUrl() {
      return this.route('contacts.create', { category: this.category });
    },
    addPersonnelTip() {
      return this.$t('Adds a Loading & UnLoading Personnel: a new contact with the category') + ' "' + this.category + '". ' + this.$t('Opens in a new tab; they appear here once saved.');
    },
    placeOptions() {
      return this.places.map(p => ({ label: p, value: p }));
    },
    projectOptions() {
      return this.projects.map(p => ({ label: p.name, value: p.id }));
    },
    tiles() {
      return [
        { icon: 'users', tone: 'indigo', label: this.$t('Workers'), value: this.stats.workers },
        { icon: 'tick', tone: 'emerald', label: this.$t('Present on the day'), value: this.stats.present },
        { icon: 'cash', tone: 'sky', label: this.$t('Earned on the day'), value: this.$money(this.stats.earned_day) },
        { icon: 'exclamation', tone: 'amber', label: this.$t('Total balance owed'), value: this.$money(this.stats.balance) },
      ];
    },
    presentCount() { return this.workers.filter(w => this.marks[w.contact_id] === 'Present').length; },
    absentCount() { return this.workers.filter(w => this.marks[w.contact_id] === 'Absent').length; },
    unmarkedCount() { return this.workers.length - this.presentCount - this.absentCount; },
    // "2 × TSH 15,000.00" when everyone present has the same rate; otherwise the number present and that rates differ.
    dayBreakdown() {
      const rates = [...new Set(this.workers.filter(w => this.marks[w.contact_id] === 'Present').map(w => Number(this.rates[w.contact_id]) || 0))];
      if (rates.length <= 1) return this.presentCount + ' × ' + this.$money(rates[0] ?? this.rate);
      return this.presentCount + ' ' + this.$t('present, different rates');
    },
    dayTotal() {
      return this.workers.reduce((s, w) => s + this.amountOf(w), 0);
    },
    changedEntries() {
      return this.workers
        .filter(w => this.marks[w.contact_id] && (this.marks[w.contact_id] !== w.status || this.projectChanged(w) || this.rateChanged(w)))
        .map(w => ({ contact_id: w.contact_id, status: this.marks[w.contact_id], daily_rate: Number(this.rates[w.contact_id]) }));
    },
    hasInvalidRate() {
      return this.workers.some(w => this.marks[w.contact_id] && !(this.rates[w.contact_id] !== '' && Number(this.rates[w.contact_id]) >= 0));
    },
  },

  watch: {
    day(v) { if (v && v !== this.filters.date) this.go({ date: v }); },
    // A day is worked at a project OR at another place: picking one clears the other.
    projectId(v) { if (v) this.otherPlace = null; },
    otherPlace(v) { if (v) this.projectId = null; },
    from() { this.reloadBalances(); },
    to() { this.reloadBalances(); },
  },

  methods: {
    reportUrl(kind) {
      return this.route('transport-loading.report.' + kind, this.reportQuery);
    },
    go(extra) {
      const q = { tab: this.tab, date: this.day, date_from: this.from, date_to: this.to, ...extra };
      Object.keys(q).forEach(k => (q[k] === '' || q[k] == null) && delete q[k]);
      router.get(this.route('transport-loading.index'), q, { preserveState: false, preserveScroll: true });
    },
    reloadBalances() {
      if (this.tab === 'balances' && this.from && this.to && (this.from !== this.filters.date_from || this.to !== this.filters.date_to)) this.go({});
    },
    projectChanged(w) {
      return w.status && (Number(this.projectId || 0) !== Number(w.warehouse_id || 0) || String(this.otherPlace || '') !== String(w.other_place || ''));
    },
    locked(w) {
      return !this.can.create || (!!w.status && !this.can.update);
    },
    mark(w, status) {
      if (this.locked(w)) return;
      this.marks[w.contact_id] = this.marks[w.contact_id] === status ? null : status;
    },
    markAll(status) {
      this.workers.forEach(w => { if (!this.locked(w)) this.marks[w.contact_id] = status; });
    },
    amountOf(w) {
      if (this.marks[w.contact_id] !== 'Present') return 0;
      return Number(this.rates[w.contact_id]) || 0;
    },
    standardFor(w) {
      return Number(w.standard_rate ?? this.rate);
    },
    // A rate different from the standard (or from what was saved with the day) is flagged, with a tooltip saying what it means.
    isCustom(w) {
      return Number(this.rates[w.contact_id]) !== this.standardFor(w);
    },
    rateChanged(w) {
      return w.status && Number(this.rates[w.contact_id]) !== Number(w.daily_rate);
    },
    rateTip(w) {
      const now = this.$money(Number(this.rates[w.contact_id]) || 0);
      const std = this.$money(this.standardFor(w));
      return this.isCustom(w)
        ? this.$t('Custom rate for this day: :rate instead of the standard :std. If marked Present, this person earns :rate for the day.', { rate: now, std })
        : this.$t('Standard daily rate (:std). Change it here to pay this person a different rate for this day, before marking Present or Absent.', { std });
    },
    rowClass(w) {
      return { 'is-present': this.marks[w.contact_id] === 'Present', 'is-absent': this.marks[w.contact_id] === 'Absent' };
    },
    save() {
      this.saving = true;
      router.post(this.route('transport-loading.attendance.store'), { date: this.day, warehouse_id: this.projectId, other_place: this.otherPlace || null, entries: this.changedEntries }, {
        preserveScroll: true,
        onFinish: () => (this.saving = false),
      });
    },
    async removeDay(w) {
      if (!(await this.$confirm(this.$t('Remove the attendance saved for') + ' ' + w.name + '?', { variant: 'danger' }))) return;
      router.delete(this.route('transport-loading.attendance.destroy', w.attendance_id), { preserveScroll: true });
    },
    toggle(id) {
      this.selected = this.selected.includes(id) ? this.selected.filter(x => x !== id) : [...this.selected, id];
    },
    toggleAll() {
      this.selected = this.allSelected ? [] : this.eligible.map(r => r.contact_id);
    },
    openPay(r) {
      this.pay = { contact_id: r.contact_id, name: r.name, balance: r.balance, pending: r.pending || 0, available: r.available ?? r.balance };
    },
  },
};
</script>

<style scoped>
.tl-check-col { width: 1%; }
.tl-neg { color: #dc2626; font-weight: 800; }
.tl-none { color: var(--lg-faint, #94a3b8); }
.tl-check { width: 1.1rem; height: 1.1rem; accent-color: #4f46e5; cursor: pointer; }
.tl-check:disabled { cursor: not-allowed; opacity: 0.4; }
html:not(#_):not(#__) input.tl-check { -webkit-appearance: none; appearance: none; flex: none; width: 1.3rem; min-width: 1.3rem; height: 1.3rem; min-height: 1.3rem; padding: 0; margin: 0; border: 2px solid #94a3b8; border-radius: 0.4rem; background: #fff no-repeat center / 78%; box-shadow: 0 3px 8px -2px rgba(15, 23, 42, 0.35), 0 1px 2px rgba(15, 23, 42, 0.15); transition: background-color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease; }
html:not(#_):not(#__) input.tl-check:hover:not(:disabled) { border-color: #4f46e5; box-shadow: 0 4px 10px -2px rgba(79, 70, 229, 0.45), 0 1px 2px rgba(15, 23, 42, 0.15); }
html:not(#_):not(#__) input.tl-check:checked { background-color: #4f46e5; border-color: #4f46e5; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='none' stroke='white' stroke-width='3.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M4 10.5l4 4 8-9'/%3E%3C/svg%3E"); box-shadow: 0 4px 10px -2px rgba(79, 70, 229, 0.55), 0 1px 2px rgba(15, 23, 42, 0.2); }
html:not(#_):not(#__) input.tl-check:disabled { box-shadow: none; }
html:not(#_):not(#__) input.tl-check:focus-visible { outline: 2px solid #4f46e5; outline-offset: 2px; }
html.dark:not(#_):not(#__) input.tl-check:not(:checked) { background-color: transparent; border-color: #64748b; }
tr.is-selected td { background: rgba(79, 70, 229, 0.07); }
.tl-bulkbar { position: sticky; bottom: 12px; z-index: 5; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-top: 16px; padding: 12px 16px; border-radius: 16px; background: var(--lg-card, #fff); border: 1px solid #a5b4fc; box-shadow: 0 18px 30px -16px rgba(67, 56, 202, 0.55); }
.tl-bulkbar-text { font-size: 0.88rem; color: #3730a3; }
.tl-bulkbar-btns { display: inline-flex; gap: 8px; flex-wrap: wrap; }
.tl-head-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-left: auto; }
.tl-tabs { display: inline-flex; gap: 6px; padding: 5px; margin: 4px 0 14px; border-radius: 14px; background: var(--lg-soft, #eef2f8); box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.08); }
.tl-tabs button { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 10px; font-weight: 700; font-size: 0.85rem; color: #475569; background: transparent; }
.tl-tabs button.is-on { color: #fff; background: linear-gradient(135deg, #4f46e5, #7c3aed); box-shadow: 0 8px 16px -8px rgba(79, 70, 229, 0.7); }
.tl-day-fields { grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr)); margin-bottom: 14px; align-items: start; }
.tl-day-fields :deep(input[autocomplete="off"]) { min-height: 43px; }
.tl-list { margin-bottom: 22px; }
.tl-actionbar { margin-top: 22px; }
/* the day total, bold and maroon so it stands out from the rest of the bar */
.tl-daytotal, .tl-daytotal b { color: #800000 !important; font-weight: 800 !important; }
html.dark .tl-daytotal, html.dark .tl-daytotal b { color: #f08a8a !important; }
.tl-where-hint { display: flex; align-items: flex-start; gap: 8px; margin: -4px 0 14px; padding: 8px 12px; border-radius: 12px; font-size: 0.8rem; line-height: 1.4; color: #1e3a8a; background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.25); }
.tl-where-hint svg { flex: none; margin-top: 2px; color: #2563eb; }
.tl-label { display: block; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: var(--lg-mute, #64748b); margin-bottom: 4px; }
.tl-empty { text-align: center; padding: 28px 12px; color: var(--lg-mute, #64748b); }
.tl-bulk { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
.tl-count { font-size: 0.85rem; color: #475569; }
.tl-bulk-btns { display: inline-flex; gap: 8px; flex-wrap: wrap; }
.tl-list { display: grid; gap: 8px; margin: 0; padding: 0; list-style: none; }
.tl-row { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 14px; padding: 10px 14px; border-radius: 14px; background: var(--lg-card, #fff); border: 1px solid var(--lg-line, #e3e8f0); box-shadow: 0 3px 8px -5px rgba(15, 23, 42, 0.3); transition: border-color 0.15s, box-shadow 0.15s; }
.tl-row.is-present { border-color: #6ee7b7; box-shadow: 0 6px 16px -10px rgba(16, 185, 129, 0.7); background: linear-gradient(90deg, rgba(16, 185, 129, 0.08), transparent 55%); }
.tl-row.is-absent { border-color: #fda4af; box-shadow: 0 6px 16px -10px rgba(244, 63, 94, 0.6); background: linear-gradient(90deg, rgba(244, 63, 94, 0.07), transparent 55%); }
.tl-who { display: flex; align-items: center; gap: 10px; min-width: 0; flex: 1 1 200px; }
.tl-who-txt { display: flex; flex-direction: column; min-width: 0; }
.tl-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 38px; height: 38px; border-radius: 12px; color: #fff; background: linear-gradient(135deg, #64748b, #334155); box-shadow: 0 8px 14px -8px rgba(51, 65, 85, 0.8); transition: background 0.2s, box-shadow 0.2s; }
.tl-row.is-present .tl-ico { background: linear-gradient(135deg, #10b981, #047857); box-shadow: 0 8px 14px -8px rgba(5, 150, 105, 0.9); }
.tl-row.is-absent .tl-ico { background: linear-gradient(135deg, #fb7185, #be123c); box-shadow: 0 8px 14px -8px rgba(225, 29, 72, 0.8); }
.tl-name { font-weight: 800; color: var(--lg-ink, #0f172a); text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tl-name:hover { color: #4338ca; }
.tl-sub { font-size: 0.74rem; color: var(--lg-mute, #64748b); }
.tl-rate { display: flex; flex-direction: row; align-items: center; gap: 8px; min-width: 0; flex: 0 0 230px; }
.tl-rate small { display: inline-flex; align-items: center; gap: 4px; flex: none; white-space: nowrap; font-size: 0.66rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--lg-faint, #94a3b8); font-weight: 800; }
.tl-rate-slot { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 14px; width: 14px; }
.tl-rate-flag { color: #d97706; }
.tl-rate-input { flex: 1 1 auto; min-width: 0; width: 100%; height: 38px; padding: 6px 10px; border-radius: 10px; border: 1px solid var(--lg-line, #e3e8f0); background: var(--lg-card, #fff); font-weight: 800; font-variant-numeric: tabular-nums; box-shadow: 0 3px 8px -4px rgba(15, 23, 42, 0.28); }
.tl-rate-input:focus { outline: none; border-color: #818cf8; box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.25); }
.tl-rate-input:disabled { opacity: 0.6; cursor: not-allowed; }
.tl-rate.is-custom .tl-rate-input { border-color: #f59e0b; color: #92400e; background: linear-gradient(135deg, rgba(245, 158, 11, 0.16), rgba(255, 255, 255, 0.4)); box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.22), 0 6px 14px -8px rgba(217, 119, 6, 0.7); }
.tl-bal { display: flex; flex-direction: column; font-variant-numeric: tabular-nums; }
.tl-bal small { font-size: 0.66rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--lg-faint, #94a3b8); font-weight: 800; }
.tl-bal.is-owed b, .tl-owed { color: #b45309; }
.tl-bal.is-adv b, .tl-adv { color: #0369a1; }
.tl-seg { display: inline-flex; border-radius: 12px; overflow: hidden; box-shadow: 0 3px 8px -4px rgba(15, 23, 42, 0.35); border: 1px solid var(--lg-line, #e3e8f0); }
.tl-seg button { display: inline-flex; align-items: center; gap: 5px; padding: 8px 14px; font-size: 0.82rem; font-weight: 800; background: var(--lg-card, #fff); color: #64748b; min-height: 40px; }
.tl-seg button:disabled { opacity: 0.5; cursor: not-allowed; }
.tl-seg .tl-present.is-on { background: #059669; color: #fff; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.25), 0 0 14px rgba(16, 185, 129, 0.55); }
.tl-seg .tl-absent.is-on { background: #e11d48; color: #fff; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.25), 0 0 14px rgba(244, 63, 94, 0.5); }
.tl-amt { flex: 0 0 130px; text-align: right; font-weight: 800; font-variant-numeric: tabular-nums; color: #047857; white-space: nowrap; }
.tl-slot { flex: 0 0 34px; height: 34px; }
.tl-del, .tl-lock { flex: 0 0 34px; display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 10px; color: #94a3b8; }
.tl-del:hover { color: #e11d48; background: rgba(244, 63, 94, 0.1); }
.tl-no { width: 1%; font-weight: 800; color: var(--lg-faint, #94a3b8); }
.tl-link { text-decoration: none; }
.tl-link:hover { color: #4338ca; }
.tl-foot td { border-top: 2px solid #5f1414; background: #7f1d1d; color: #fff; font-weight: 800; }
.tl-foot td b { color: #fff; font-weight: 800; }
html.dark .tl-tabs button { color: #cbd5e1; }
html.dark .tl-row { background: rgba(15, 23, 42, 0.6); }
@media (max-width: 760px) {
  .tl-check-col { width: auto; justify-content: flex-start !important; }
  .tl-check-col::before { display: none !important; }
  .tl-rate { flex: 1 1 100%; }
  .tl-seg { flex: 1 1 100%; }
  .tl-seg button { flex: 1; justify-content: center; }
  .tl-amt { flex: 1 1 auto; }
}
</style>

<template>
  <admin-layout :title="$t('External Stock')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="truck" :title="$t('External Stock')" :subtitle="$t('Stock that belongs to External Personnel but is kept in our warehouses. Every movement needs approval before the balance changes.')">
        <Link v-if="can.create" :href="route('external-stock.create', { kind: 'withdrawal' })" class="lg-btn lg-btn-red"><icons name="out" class="w-4 h-4" /><span>{{ $t('Stock Out') }}</span></Link>
        <Link v-if="can.create" :href="route('external-stock.create', { kind: 'deposit' })" class="lg-btn lg-btn-green"><icons name="in" class="w-4 h-4" /><span>{{ $t('Stock In') }}</span></Link>
        <Link v-if="can.create" :href="route('external-stock.create', { kind: 'opening' })" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" /><span>{{ $t('Opening Stock') }}</span></Link>
        <Link :href="route('external-stock.reports.index')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Balances and Reports') }}</span></Link>
              <ext-export type="register" :params="f" />
      </page-header>

      <sc-tabs :tabs="tabs.map(t => ({ key: t.key, label: t.label, icon: t.icon, tone: t.tone, count: t.count === null ? undefined : t.count }))" :model-value="f.status || ''" @update:model-value="setStatus" />

      <form class="lg-card" @submit.prevent="go">
        <div class="ac-filters ex-filters">
          <div class="ac-f ex-search"><label>{{ $t('Search') }}</label><input v-model="f.search" type="search" :placeholder="$t('Reference, person, collector, mobile, driver or vehicle')" /></div>
          <div class="ac-f"><label>{{ $t('Kind') }}</label><acct-select v-model="f.kind" :options="kinds" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Person') }}</label><acct-select v-model="f.contact_id" :options="people" :placeholder="$t('Anyone')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Project(s) / Warehouse(s)') }}</label><acct-select v-model="f.warehouse_id" :options="warehouses" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div>
          <div class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
          <div class="ac-f ex-actions"><label aria-hidden="true">&nbsp;</label><div><button type="submit" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Filter') }}</button><button type="button" class="lg-btn t-slate" @click="reset"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div></div>
        </div>
      </form>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('Movement') }}</th><th>{{ $t('Person') }}</th><th>{{ $t('Project(s) / Warehouse(s)') }}</th><th>{{ $t('Items') }}</th><th>{{ $t('Collected / brought by') }}</th><th>{{ $t('Status') }}</th><th>{{ $t('Paperwork') }}</th><th></th></tr></thead>
          <tbody>
            <tr v-for="r in rows.data" :key="r.id" class="is-click" @click="$inertia.visit(route('external-stock.show', r.id))">
              <td :data-label="$t('Movement')"><span class="lg-main">{{ r.reference }}</span><div class="lg-subtext"><span class="lg-chip" :class="kindChip(r.kind)" style="text-transform: none">{{ r.kind_label }}</span> {{ $date(r.date) }}</div></td>
              <td :data-label="$t('Person')">{{ r.contact }}<div v-if="r.location" class="lg-subtext">{{ r.location }}</div></td><td :data-label="$t('Project(s) / Warehouse(s)')">{{ r.warehouse }}</td>
              <td :data-label="$t('Items')"><div v-for="l in r.lines.slice(0, 3)" :key="l.id" class="ex-line"><b>{{ qty(l.quantity) }}</b> {{ l.unit }} {{ l.name }}</div><div v-if="r.lines.length > 3" class="lg-subtext">+ {{ r.lines.length - 3 }} {{ $t('more') }}</div></td>
              <td :data-label="$t('Collected / brought by')" class="ex-who"><template v-if="r.collector_name">{{ r.collector_name }}<div class="lg-subtext">{{ r.collector_phone }}</div></template><span v-else>-</span><div v-if="r.driver || r.vehicle" class="lg-subtext">{{ [r.driver, r.vehicle].filter(Boolean).join(' · ') }}</div></td>
              <td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(r.status)">{{ r.status_label }}</span></td>
              <td :data-label="$t('Paperwork')" @click.stop><div class="ex-paper"><delivery-note-action compact :state="r.delivery_note" /><gate-pass-action compact :state="r.gate_pass" /><transport-route-action compact :state="r.route" /></div></td>
              <td class="lg-sticky-end" @click.stop><a v-if="can.print" :href="route('external-stock.print', r.id)" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('Print')" :aria-label="$t('Print')"><icons name="printer" class="w-4 h-4" /></a><a v-if="can.print" :href="route('external-stock.pdf', r.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" class="w-4 h-4" /></a><Link :href="route('external-stock.show', r.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Open')" :aria-label="$t('Open')"><icons name="eye-flat" class="w-4 h-4" /></Link></td>
            </tr>
            <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="8">{{ $t('There is no data to display.') }}</td></tr>
          </tbody>
        </table>
      </div>
      <pager :rows="rows" />
    </div>
  </admin-layout>
</template>

<script>
import ExtExport from './ExtExport.vue';
import ScTabs from '../Subcontracts/ScTabs.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import GatePassAction from '@/Shared/GatePassAction.vue';
import DeliveryNoteAction from '@/Shared/DeliveryNoteAction.vue';
import TransportRouteAction from '@/Shared/TransportRouteAction.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import Pager from '@/Pages/Accounts/Pager.vue';

export default {
  components: { ExtExport, ScTabs, AdminLayout, PageHeader, Link, DatePicker, AcctSelect, Pager, GatePassAction, DeliveryNoteAction, TransportRouteAction },
  props: { rows: Object, filters: Object, kinds: Array, statuses: Array, people: Array, warehouses: Array, stats: Object, can: Object },
  data() { return { f: { ...this.filters } }; },
  computed: {
    tabs() {
      const s = this.stats;
      return [{ key: '', label: this.$t('All'), icon: 'collection', tone: 'navy', count: s.total }, { key: 'Pending Approval', label: this.$t('Waiting'), icon: 'clock', tone: 'amber', count: s.pending }, { key: 'Approved', label: this.$t('Approved'), icon: 'tick', tone: 'emerald', count: s.approved },
        { key: 'Rejected', label: this.$t('Rejected'), icon: 'x-circle', tone: 'rose', count: s.rejected }, { key: 'Cancelled', label: this.$t('Cancelled'), icon: 'x-circle', tone: 'slate', count: s.cancelled }];
    },
  },
  methods: {
    qty(v) { return Number(v).toLocaleString(undefined, { maximumFractionDigits: 4 }); },
    kindChip(k) { return { withdrawal: 'lg-chip-red', deposit: 'lg-chip-green', opening: 'lg-chip-violet' }[k] || 'lg-chip-slate'; },
    statusChip(s) { return { 'Pending Approval': 'lg-chip-amber', Approved: 'lg-chip-green', Rejected: 'lg-chip-red', Cancelled: 'lg-chip-slate' }[s] || 'lg-chip-slate'; },
    go() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); this.$inertia.get(route('external-stock.index'), q, { preserveState: true }); },
    setStatus(s) { this.f.status = s; this.go(); },
    reset() { this.f = {}; this.go(); },
  },
};
</script>

<style scoped>
.ex-filters .ex-actions > div { display: flex; gap: 8px; }
@media (min-width: 1100px) { .ex-filters { grid-template-columns: minmax(0, 1.7fr) repeat(5, minmax(0, 1fr)) auto; } }
@media (max-width: 1099px) { .ex-filters .ex-search { grid-column: 1 / -1; } }
</style>

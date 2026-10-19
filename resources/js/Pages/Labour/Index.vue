<template>
  <admin-layout :title="$t('Labour')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="users" :title="$t('Labour')" :subtitle="$t('Site Engineer → Project Manager → Super Admin labour approval, converting into Payment Vouchers once approved.')">
        <a :href="printUrl" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" /> <span class="hidden sm:inline">{{ $t('Print') }}</span>
        </a>
        <a :href="downloadUrl" class="lg-btn lg-btn-soft">
          <icons name="download" class="w-4 h-4" /> <span class="hidden sm:inline">{{ $t('Download') }}</span>
        </a>
        <Link v-if="canCreate" :href="route('labour.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" /> {{ $t('New Labour List') }}
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" @select="pickTile" />

      <div class="lg-card lg-filters relative z-20 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end" style="padding: 1rem 1.1rem">
        <div><text-input class="w-full" :label="$t('Search')" v-model="form.search" :placeholder="$t('Reference or project…')" /></div>
        <div><auto-complete id="warehouse_id" :label="$t('Project')" v-model="form.warehouse_id" class="w-full" :suggestions="warehouseOptions" clearable /></div>
        <div><auto-complete id="status" :label="$t('Status')" v-model="form.status" class="w-full" :suggestions="statusOptions" clearable /></div>
        <div><auto-complete id="priority" :label="$t('Priority')" v-model="form.priority" class="w-full" :suggestions="priorityOptions" clearable /></div>
        <div><date-picker class="w-full" :label="$t('Period From')" v-model="form.date_from" clearable /></div>
        <div class="flex items-end gap-2">
          <date-picker class="w-full" :label="$t('Period To')" v-model="form.date_to" clearable />
          <button type="button" class="lg-icon-btn lbr-reset shrink-0 lg-tip" :data-tip="$t('Reset')" :aria-label="$t('Reset')" @click="reset">
            <icons name="refresh" class="w-4 h-4" />
          </button>
        </div>
      </div>

      <div class="flex items-center justify-between gap-4 mb-3 flex-wrap">
        <span class="text-xs text-gray-400">{{ labourLists.meta ? labourLists.meta.total : 0 }} {{ $t('result(s)') }}</span>
      </div>

      <div class="lg-card" style="padding: 1rem">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th class="is-sortable" @click="sortBy('reference')">
                  <span class="lg-sort">{{ $t('Reference') }} <sort-icon :active="isSorted('reference')" :direction="form.direction" /></span>
                </th>
                <th>{{ $t('Project') }}</th>
                <th class="is-sortable" @click="sortBy('period_start')">
                  <span class="lg-sort">{{ $t('Period') }} <sort-icon :active="isSorted('period_start')" :direction="form.direction" /></span>
                </th>
                <th class="is-sortable num" @click="sortBy('total_amount')">
                  <span class="lg-sort">{{ $t('Requested Amount') }} <sort-icon :active="isSorted('total_amount')" :direction="form.direction" /></span>
                </th>
                <th class="is-sortable num" @click="sortBy('approved_amount')">
                  <span class="lg-sort">{{ $t('Approved Amount') }} <sort-icon :active="isSorted('approved_amount')" :direction="form.direction" /></span>
                </th>
                <th class="is-sortable" @click="sortBy('priority')">
                  <span class="lg-sort">{{ $t('Priority') }} <sort-icon :active="isSorted('priority')" :direction="form.direction" /></span>
                </th>
                <th class="is-sortable" @click="sortBy('status')">
                  <span class="lg-sort">{{ $t('Status') }} <sort-icon :active="isSorted('status')" :direction="form.direction" /></span>
                </th>
                <th>{{ $t('Payment') }}</th>
                <th>{{ $t('Created By') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="l in labourLists.data" :key="l.id" class="is-click" @click="$inertia.visit(route('labour.show', l.id))">
                <td
                  class="lbr-tooltip-trigger"
                  :data-label="$t('Reference')"
                  @mouseenter="showTooltip($event, referenceTooltip(l))"
                  @mouseleave="hideTooltip"
                ><span class="lg-chip lg-chip-blue lg-mono" style="text-transform: none">{{ l.reference }}</span></td>
                <td :data-label="$t('Project')"><span class="lg-main">{{ l.warehouse_name || '—' }}</span></td>
                <td class="lg-subtext" :data-label="$t('Period')">{{ l.period_start }} – {{ l.period_end }}</td>
                <td class="num lg-main" :data-label="$t('Requested Amount')">{{ money(l.total_amount) }}</td>
                <td class="num lbr-approved" :data-label="$t('Approved Amount')">
                  <span v-if="l.approved_amount !== null">{{ money(l.approved_amount) }}</span>
                  <span v-else class="lg-subtext">—</span>
                </td>
                <td :data-label="$t('Priority')">
                  <span v-if="l.priority" class="lg-chip" :class="priorityChip(l.priority)">{{ l.priority }}</span>
                  <span v-else class="lg-subtext">—</span>
                </td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(l.status)">{{ l.status }}</span></td>
                <td :data-label="$t('Payment')">
                  <span v-if="l.payment_status" class="lg-chip" :class="payChip(l.payment_status)">{{ l.payment_status }}</span>
                  <span v-else class="lg-subtext">—</span>
                </td>
                <td class="lg-subtext" :data-label="$t('Created By')">{{ l.created_by_name || '—' }}</td>
              </tr>
              <tr v-if="!labourLists.data.length" class="lg-empty-row">
                <td class="lg-empty" colspan="9">{{ $t('No labour lists match these filters.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <pagination class="mt-4" :meta="labourLists.meta" :links="labourLists.links" />
    </div>
  </admin-layout>

  <!-- Teleported to <body> so the Reference cell's hover tooltip can never be clipped by the table wrapper's overflow. -->
  <Teleport to="body">
    <div v-if="tooltip.visible" class="lbr-floating-tooltip" :style="{ left: tooltip.x + 'px', top: tooltip.y + 'px' }">{{ tooltip.text }}</div>
  </Teleport>
</template>

<script>
import debounce from 'lodash/debounce';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Shared/Pagination.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import TextInput from '@/Shared/TextInput.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import { h } from 'vue';

// Same render-function sort-arrow used by PaymentVoucher/Index.vue and
// ProjectMaterialLedger/Index.vue — copied rather than shared, matching how
// those two also each keep their own local copy.
const SortIcon = {
  props: { active: Boolean, direction: String },
  render() {
    const isDesc = !this.active || this.direction === 'desc';
    const path = isDesc
      ? 'M10 3a.75.75 0 01.75.75v10.638l3.96-4.158a.75.75 0 111.08 1.04l-5.25 5.5a.75.75 0 01-1.08 0l-5.25-5.5a.75.75 0 111.08-1.04l3.96 4.158V3.75A.75.75 0 0110 3z'
      : 'M10 17a.75.75 0 01-.75-.75V5.612L5.29 9.77a.75.75 0 01-1.08-1.04l5.25-5.5a.75.75 0 011.08 0l5.25 5.5a.75.75 0 01-1.08 1.04l-3.96-4.158V16.25A.75.75 0 0110 17z';
    return h('span', { class: ['lbr-sort-icon', { 'lbr-sort-icon-active': this.active }] }, [
      h('svg', { xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 20 20', fill: 'currentColor', class: 'w-3 h-3' }, [
        h('path', { 'fill-rule': 'evenodd', d: path, 'clip-rule': 'evenodd' }),
      ]),
    ]);
  },
};

export default {
  components: { AdminLayout, Pagination, AutoComplete, TextInput, DatePicker, SortIcon, PageHeader, StatTiles },

  props: {
    labourLists: Object,
    filters: Object,
    statuses: Array,
    priorities: { type: Array, default: () => ['Low', 'Normal', 'High', 'Extreme'] },
    warehouses: Array,
    stats: Object,
    canCreate: Boolean,
  },

  data() {
    return {
      form: {
        search: this.filters?.search || '',
        warehouse_id: this.filters?.warehouse_id || '',
        status: this.filters?.status || '',
        priority: this.filters?.priority || '',
        date_from: this.filters?.date_from || '',
        date_to: this.filters?.date_to || '',
        sort: this.filters?.sort || null,
        direction: this.filters?.direction || 'desc',
      },
      tooltip: { visible: false, text: '', x: 0, y: 0 },
    };
  },

  computed: {
    statTiles() {
      const s = this.stats || {};
      return [
        { icon: 'clock', tone: 'indigo', label: this.$t('Awaiting PM Clearance'), value: s.awaiting_pm, status: 'Submitted', active: this.form.status === 'Submitted' },
        { icon: 'exclamation', tone: 'amber', label: this.$t('Awaiting Approval'), value: s.awaiting_super_admin, status: 'Cleared', active: this.form.status === 'Cleared' },
        { icon: 'tick', tone: 'emerald', label: this.$t('Approved for Payment'), value: s.approved_for_payment, status: 'Approved', active: this.form.status === 'Approved' },
        { icon: 'doc-text', tone: 'slate', label: this.$t('Payment Voucher Created'), value: s.payment_voucher_created, status: 'Payment Voucher Created', active: this.form.status === 'Payment Voucher Created' },
      ];
    },
    warehouseOptions() {
      return this.warehouses.map(w => ({ id: w.id, label: w.name }));
    },
    statusOptions() {
      return this.statuses.map(s => ({ id: s, label: s }));
    },
    priorityOptions() {
      return this.priorities.map(p => ({ id: p, label: this.$t(p) }));
    },
    activeFilters() {
      const out = {};
      Object.entries(this.form).forEach(([k, v]) => { if (v) out[k] = v; });

      return out;
    },
    printUrl() {
      return this.route('labour.print-list', this.activeFilters);
    },
    downloadUrl() {
      return this.route('labour.pdf-list', this.activeFilters);
    },
  },

  watch: {
    'form.search': debounce(function () { this.applyFilters(); }, 400),
    'form.warehouse_id'() { this.applyFilters(); },
    'form.status'() { this.applyFilters(); },
    'form.priority'() { this.applyFilters(); },
    'form.date_from'() { this.applyFilters(); },
    'form.date_to'() { this.applyFilters(); },
  },

  methods: {
    money(v) {
      return 'TZS ' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    statusSlug(s) {
      return (s || '').toLowerCase().replace(/[^a-z0-9]+/g, '_');
    },
    pickTile(item) {
      this.setStatus(item.status);
    },
    statusChip(s) {
      return { draft: 'lg-chip-slate', submitted: 'lg-chip-indigo', returned: 'lg-chip-red', cleared: 'lg-chip-amber', approved: 'lg-chip-blue', payment_voucher_created: 'lg-chip-green' }[this.statusSlug(s)] || 'lg-chip-slate';
    },
    payChip(s) {
      return { unpaid: 'lg-chip-red', processing: 'lg-chip-amber', paid: 'lg-chip-green', issue: 'lbr-chip-dark' }[this.statusSlug(s)] || 'lg-chip-slate';
    },
    priorityChip(s) {
      return { low: 'lg-chip-slate', normal: 'lg-chip-blue', high: 'lg-chip-amber', extreme: 'lg-chip-red' }[this.statusSlug(s)] || 'lg-chip-slate';
    },
    setStatus(status) {
      this.form.status = this.form.status === status ? '' : status;
    },
    reset() {
      // Not calling applyFilters() explicitly here — replacing the whole
      // form object still fires the per-field watchers below for every
      // field that changed (same as before sort/direction existed), and
      // applyFilters() always reads the full current form fresh, so the
      // reset sort/direction values ride along on whichever of those
      // already-firing calls lands.
      this.form = { search: '', warehouse_id: '', status: '', priority: '', date_from: '', date_to: '', sort: null, direction: 'desc' };
    },
    applyFilters() {
      this.$inertia.get(this.route('labour.index'), this.form, { preserveState: true, replace: true });
    },
    // Toggles direction on a second click of the same column, otherwise
    // switches column and starts at 'asc' — same convention as
    // PaymentVoucher/Index.vue's sortBy(). Calls applyFilters() directly
    // instead of going through the per-field watchers below, so a single
    // click (which changes both form.sort AND form.direction together)
    // only ever triggers one navigation, not two.
    sortBy(column) {
      if (this.form.sort === column) {
        this.form.direction = this.form.direction === 'asc' ? 'desc' : 'asc';
      } else {
        this.form.sort = column;
        this.form.direction = 'asc';
      }
      this.applyFilters();
    },
    isSorted(column) {
      return this.form.sort === column;
    },
    // Positions the teleported tooltip (see .lbr-floating-tooltip below)
    // above the hovered cell using its live viewport rect, so it renders on
    // <body> instead of inside .lbr-table-shell's clipping box.
    showTooltip(event, text) {
      const rect = event.currentTarget.getBoundingClientRect();
      this.tooltip = { visible: true, text, x: rect.left + rect.width / 2, y: rect.top };
    },
    hideTooltip() {
      this.tooltip.visible = false;
    },
    // Extra detail shown on hover over the Reference cell — a floating
    // tooltip (see showTooltip()/.lbr-floating-tooltip), not columns already
    // visible in the table. Newlines render via white-space: pre-line.
    referenceTooltip(l) {
      const lines = [
        `${this.$t('Project')}: ${l.warehouse_name || '—'}`,
        `${this.$t('Period')}: ${l.period_start} – ${l.period_end}`,
        `${l.item_count} ${this.$t('labour personnel')}`,
      ];

      const breakdown = l.priority_breakdown && Object.keys(l.priority_breakdown).length
        ? Object.entries(l.priority_breakdown).map(([p, c]) => `${c} ${this.$t(p)}`).join(', ')
        : null;
      if (breakdown) {
        lines.push(`${this.$t('Priority')}: ${breakdown}`);
      }

      if (l.description) {
        lines.push(`${this.$t('Description')}: ${l.description}`);
      }

      lines.push(`${this.$t('Created by')} ${l.created_by_name || '—'} · ${l.created_at || ''}`);

      return lines.join('\n');
    },
  },
};
</script>

<style scoped>
.lbr-reset { width: 42px; height: 42px; border-radius: 9999px; }
.lbr-approved { text-align: right; font-weight: 700; color: #047857; font-variant-numeric: tabular-nums; }
.lbr-chip-dark { background: #450a0a; color: #fecaca; }
.lbr-tooltip-trigger { cursor: help; }
.lbr-sort-icon { display: inline-flex; color: #cbd5e1; transition: color 0.15s ease; }
.lbr-sort-icon-active { color: #1c3f66; }
html.dark .lbr-sort-icon-active { color: #93c5fd; }

/* Teleported to <body> and positioned via showTooltip()'s getBoundingClientRect() math, so overflow containers can never clip it. */
.lbr-floating-tooltip {
  position: fixed; transform: translate(-50%, calc(-100% - 10px));
  width: max-content; max-width: 16rem; background: #0f172a; color: #fff; padding: 0.5rem 0.7rem; border-radius: 10px;
  font-size: 0.72rem; font-weight: 600; line-height: 1.5; white-space: pre-line; text-align: left;
  pointer-events: none; z-index: 9999;
  box-shadow: 0 12px 24px -10px rgba(15, 23, 42, 0.6);
}
@media (max-width: 767.98px) { .lbr-approved { text-align: right; } }
</style>

<template>
  <admin-layout :title="$t('Funds Allocation')">
    <div class="lg-page px-4 md:px-0 fx-page">
      <page-header icon="cash" :title="$t('Funds Allocation')" :subtitle="$t('Dated sheets of what is paid out, by category and payment method.')">
        <Link v-if="can.reports" :href="route('fund-allocations.reports')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" />{{ $t('Reports') }}</Link>
        <Link v-if="can.settings" :href="route('fund-allocations.settings')" class="lg-btn lg-btn-soft"><icons name="cog" class="w-4 h-4" />{{ $t('Settings') }}</Link>
        <Link v-if="can.template" :href="route('fund-allocations.template')" class="lg-btn lg-btn-soft"><icons name="doc-text" class="w-4 h-4" />{{ $t('Template') }}</Link>
        <Link v-if="can.create" :href="route('fund-allocations.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" />{{ $t('New Allocation Sheet') }}</Link>
      </page-header>

      <stat-tiles v-if="stats" class="fx-tiles" :items="statTiles" @select="pickStatus" />

      <section class="lg-card lg-filters">
        <div class="lg-fields fx-filters">
          <text-input v-model="form.search" :label="$t('Search')" :placeholder="$t('Reference or title')" />
          <filter-select v-model="form.status" :label="$t('Status')" :empty-label="$t('All statuses')" :options="statusOptions" />
          <date-picker v-model="form.from" :label="$t('From')" />
          <date-picker v-model="form.to" :label="$t('To')" />
          <filter-select v-model="form.payer" :label="$t('Payer')" :empty-label="$t('Any payer')" :options="payerOptions" />
          <div class="fx-filter-reset">
            <button type="button" class="lg-btn lg-btn-soft" :disabled="!hasFilters" @click="reset"><icons name="refresh" class="w-4 h-4" />{{ $t('Reset') }}</button>
          </div>
        </div>
      </section>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th>{{ $t('Sheet') }}</th>
              <th>{{ $t('Status') }}</th>
              <th class="num">{{ $t('Available') }}</th>
              <th class="num">{{ $t('Allocated') }}</th>
              <th class="num">{{ $t('Balance') }}</th>
              <th>{{ $t('Paid') }}</th>
              <th>{{ $t('People') }}</th>
              <th class="lg-sticky-end"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="a in allocations.data" :key="a.id" class="is-click" @click="open(a)">
              <td :data-label="$t('Sheet')">
                <div class="lg-main">{{ a.reference }}</div>
                <div class="lg-subtext">{{ $date(a.allocation_date) }}<template v-if="a.title"> &middot; {{ a.title }}</template></div>
                <div class="lg-subtext">{{ a.item_count }} {{ $t('items') }}</div>
              </td>
              <td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(a.status)">{{ $t(a.status) }}</span></td>
              <td class="num" :data-label="$t('Available')">{{ a.available === null ? '-' : fmt(a.available) }}<i v-if="a.available !== null" class="fx-cur">TZS</i></td>
              <td class="num" :data-label="$t('Allocated')">
                <span class="fx-strong">{{ fmt(a.allocated) }}<i class="fx-cur">TZS</i></span>
              </td>
              <td class="num" :data-label="$t('Balance')">
                <span v-if="a.balance === null">-</span>
                <span v-else :class="a.overspent ? 'fx-neg' : 'fx-pos'">{{ fmt(a.balance) }}<i class="fx-cur">TZS</i></span>
                <div v-if="a.overspent" class="fx-overspent">{{ $t('Overspent') }}</div>
              </td>
              <td :data-label="$t('Paid')">
                <div class="fx-progress-cell">
                  <div class="fx-bar"><span :style="{ width: Math.min(100, a.progress) + '%' }" :class="{ done: a.progress >= 100 }"></span></div>
                  <span class="fx-pct">{{ Math.round(a.progress) }}%</span>
                </div>
                <div class="lg-subtext">{{ fmt(a.paid) }}<i class="fx-cur">TZS</i></div>
              </td>
              <td :data-label="$t('People')">
                <div class="lg-subtext fx-people"><b>{{ $t('Prepared') }}:</b> {{ a.prepared_by ? a.prepared_by.name : '-' }}</div>
                <div v-if="a.approved_by" class="lg-subtext fx-people"><b>{{ $t('Approved') }}:</b> {{ a.approved_by.name }}</div>
                <div v-if="a.assigned_to" class="lg-subtext fx-people"><b>{{ $t('Payer') }}:</b> {{ a.assigned_to.name }}</div>
                <div v-if="a.cleared_by" class="lg-subtext fx-people fx-cleared"><b>{{ $t('Cleared by') }}:</b> {{ a.cleared_by.name }}</div>
              </td>
              <td class="lg-sticky-end" :data-label="$t('Actions')" @click.stop>
                <div class="lg-actions">
                  <Link :href="route('fund-allocations.show', a.id)" class="lg-icon-btn lg-tip" :data-tip="$t('View')" :aria-label="$t('View')"><icons name="doc" /></Link>
                  <Link
                    v-if="canEdit(a)"
                    :href="route('fund-allocations.edit', a.id)"
                    class="lg-icon-btn lg-tip"
                    :data-tip="$t('Edit')"
                    :aria-label="$t('Edit')"
                  ><icons name="edit" /></Link>
                  <a
                    v-if="can.print"
                    :href="route('fund-allocations.print', a.id)"
                    target="_blank"
                    class="lg-icon-btn lg-tip"
                    :data-tip="$t('Print')"
                    :aria-label="$t('Print')"
                  ><icons name="printer" /></a>
                  <a
                    v-if="can.print"
                    :href="route('fund-allocations.pdf', a.id)"
                    class="lg-icon-btn lg-tip"
                    :data-tip="$t('Download PDF')"
                    :aria-label="$t('Download PDF')"
                  ><icons name="download" /></a>
                  <button
                    v-if="can.create"
                    type="button"
                    class="lg-icon-btn fx-dup-btn lg-tip"
                    :data-tip="$t('Duplicate as draft')"
                    :aria-label="$t('Duplicate as draft')"
                    @click="openDuplicate(a)"
                  ><icons name="duplicate" /></button>
                </div>
              </td>
            </tr>
            <tr v-if="allocations.data.length === 0" class="lg-empty-row">
              <td class="lg-empty" colspan="8">{{ $t('No allocation sheets match these filters.') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <pagination class="mt-4" :meta="allocations.meta" :links="allocations.links" />
    </div>

    <tec-dialog-modal :show="!!dup.sheet" max-width="md" @close="closeDuplicate">
      <template #title><span class="fx-modal-title">{{ $t('Duplicate allocation sheet') }}</span></template>
      <template #content>
        <div class="lg-page fx-dup-modal">
          <p class="fx-dup-help">
            <b v-if="dup.sheet">{{ dup.sheet.reference }}</b><br />
            {{ $t('A new Draft copy will be created with today\'s date. Approval, payer, payments and vouchers are not copied.') }}
          </p>
          <label class="fx-dup-lbl" for="fx-dup-pw"><icons name="lock-closed" class="w-3 h-3" />{{ $t('Super Admin password') }}</label>
          <input
            id="fx-dup-pw"
            ref="dupPassword"
            v-model="dup.form.password"
            type="password"
            autocomplete="current-password"
            class="fx-dup-input"
            :class="{ 'fx-dup-input-bad': dup.form.errors.password }"
            :placeholder="$t('Enter a Super Admin password')"
            @keyup.enter="submitDuplicate"
          />
          <p v-if="dup.form.errors.password" class="fx-dup-error">{{ dup.form.errors.password }}</p>
        </div>
      </template>
      <template #footer>
        <div class="lg-page fx-dup-foot">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeDuplicate">{{ $t('Close') }}</button>
          <button type="button" class="lg-btn fx-dup-confirm" :disabled="dup.form.processing || !dup.form.password" @click="submitDuplicate">
            <icons name="duplicate" class="w-4 h-4" />{{ dup.form.processing ? $t('Duplicating…') : $t('Duplicate') }}
          </button>
        </div>
      </template>
    </tec-dialog-modal>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import Pagination from '@/Shared/Pagination.vue';
import TextInput from '@/Shared/TextInput.vue';
import FilterSelect from './FilterSelect.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import { fmt, statusChip } from './fundFormat';

export default {
  components: { AdminLayout, PageHeader, StatTiles, Pagination, TextInput, FilterSelect, DatePicker, TecDialogModal },

  props: {
    filters: { type: Object, default: () => ({}) },
    allocations: Object,
    statuses: { type: Array, default: () => [] },
    payers: { type: Array, default: () => [] },
    stats: { type: Object, default: null },
    can: { type: Object, default: () => ({}) },
  },

  data() {
    return {
      form: {
        search: this.filters.search || '',
        status: this.filters.status || null,
        from: this.filters.from || null,
        to: this.filters.to || null,
        payer: this.filters.payer ? Number(this.filters.payer) : null,
      },
      ready: false,
      dup: { sheet: null, form: this.$inertia.form({ password: '' }) },
    };
  },

  computed: {
    statusOptions() {
      return this.statuses.map(s => ({ value: s, label: this.$t(s) }));
    },
    payerOptions() {
      return this.payers.map(p => ({ value: p.id, label: p.name }));
    },
    hasFilters() {
      return !!(this.form.search || this.form.status || this.form.from || this.form.to || this.form.payer);
    },
    statTiles() {
      const s = this.stats;
      if (!s) return [];
      const st = this.form.status;
      return [
        { icon: 'collection', tone: 'navy', label: this.$t('All sheets'), value: s.total, status: null, active: !st },
        { icon: 'edit', tone: 'slate', label: this.$t('Draft'), value: s.draft, status: 'Draft', active: st === 'Draft' },
        { icon: 'clock', tone: 'amber', label: this.$t('Pending Approval'), value: s.pending, status: 'Pending Approval', active: st === 'Pending Approval' },
        { icon: 'tick', tone: 'sky', label: this.$t('Approved'), value: s.approved, status: 'Approved', active: st === 'Approved' },
        { icon: 'refresh', tone: 'violet', label: this.$t('In Progress'), value: s.in_progress, status: 'In Progress', active: st === 'In Progress' },
        { icon: 'tick', tone: 'emerald', label: this.$t('Completed'), value: s.completed, status: 'Completed', active: st === 'Completed' },
        { icon: 'cash', tone: 'indigo', label: this.$t('Allocated this month'), value: fmt(s.allocated_total_this_month) + ' TZS' },
      ];
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        if (!this.ready) return;
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('fund-allocations.index', Object.keys(query).length ? query : {}), { preserveState: true, preserveScroll: true, replace: true });
      }, 350),
      deep: true,
    },
  },

  mounted() {
    this.$nextTick(() => (this.ready = true));
  },

  methods: {
    fmt,
    statusChip,
    open(a) {
      this.$inertia.visit(this.route('fund-allocations.show', a.id));
    },
    openDuplicate(a) {
      this.dup.form.reset();
      this.dup.form.clearErrors();
      this.dup.sheet = a;
      this.$nextTick(() => setTimeout(() => this.$refs.dupPassword && this.$refs.dupPassword.focus(), 300));
    },
    closeDuplicate() {
      this.dup.sheet = null;
      this.dup.form.reset();
      this.dup.form.clearErrors();
    },
    submitDuplicate() {
      if (!this.dup.sheet || this.dup.form.processing || !this.dup.form.password) return;
      this.dup.form.post(this.route('fund-allocations.duplicate', this.dup.sheet.id), {
        preserveScroll: true,
        onSuccess: () => this.closeDuplicate(),
        onFinish: () => this.dup.form.reset('password'),
      });
    },
    canEdit(a) {
      return !!a.can_edit;
    },
    pickStatus(item) {
      this.form.status = item.status || null;
    },
    reset() {
      this.form = { search: '', status: null, from: null, to: null, payer: null };
    },
  },
};
</script>

<style scoped>
.fx-dup-btn { color: #7f1d1d; background: rgba(127, 29, 29, 0.1); }
.fx-dup-btn:hover:not(:disabled) { background: rgba(127, 29, 29, 0.2); }
html.dark .fx-dup-btn { color: #fca5a5; background: rgba(252, 165, 165, 0.12); }
html.dark .fx-dup-btn:hover:not(:disabled) { background: rgba(252, 165, 165, 0.22); }
.fx-dup-help { font-size: 0.84rem; color: var(--lg-mute); margin-bottom: 12px; }
.fx-dup-lbl { display: flex; align-items: center; gap: 5px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); margin-bottom: 4px; }
.fx-dup-input { width: 100%; padding: 0.6rem 0.8rem; border: 1px solid var(--lg-line); border-radius: 0.8rem; background: var(--lg-card); color: var(--lg-ink); font-size: 0.9rem; }
.fx-dup-input:focus { outline: none; border-color: #7f1d1d; box-shadow: 0 0 0 3px rgba(127, 29, 29, 0.2); }
.fx-dup-input-bad { border-color: #ef4444; }
.fx-dup-error { margin-top: 6px; font-size: 0.8rem; font-weight: 600; color: #be123c; }
.fx-dup-foot { display: flex; justify-content: flex-end; gap: 8px; }
.fx-dup-confirm { color: #fff; background: linear-gradient(145deg, #9f1239, #7f1d1d); box-shadow: 0 10px 18px -8px rgba(127, 29, 29, 0.7); }
.fx-filters { grid-template-columns: repeat(auto-fit, minmax(min(170px, 100%), 1fr)); align-items: start; }
/* Seven tiles in one row: six compact ones and a wider last one so big amounts never wrap. */
@media (min-width: 1100px) {
  .fx-tiles { grid-template-columns: repeat(6, minmax(0, 1fr)) minmax(210px, 1.9fr); gap: 10px; }
}
.fx-tiles :deep(.lg-stat) { gap: 9px; padding: 10px 12px; }
.fx-tiles :deep(.lg-stat-ico) { width: 36px; height: 36px; border-radius: 12px; }
.fx-tiles :deep(.lg-stat-value) { font-size: 1.2rem; overflow-wrap: normal; white-space: nowrap; }
.fx-tiles :deep(.lg-stat-label) { font-size: 0.6rem; letter-spacing: 0.04em; }
.fx-filter-reset { display: flex; align-items: flex-start; padding-top: 26px; }
.fx-strong { font-weight: 800; }
.fx-neg { color: #be123c; font-weight: 800; }
.fx-pos { color: #047857; font-weight: 700; }
.fx-overspent { font-size: 0.64rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #be123c; }
.fx-people { white-space: normal; }
.fx-people b { font-weight: 700; color: var(--lg-mute); }
.fx-people.fx-cleared, .fx-people.fx-cleared b { color: #7f1d1d; font-weight: 700; }
html.dark .fx-people.fx-cleared, html.dark .fx-people.fx-cleared b { color: #fca5a5; }
.fx-progress-cell { display: flex; align-items: center; gap: 8px; min-width: 120px; }
.fx-bar { flex: 1; height: 7px; border-radius: 9999px; background: var(--lg-soft); border: 1px solid var(--lg-line); overflow: hidden; }
.fx-bar span { display: block; height: 100%; border-radius: 9999px; background: linear-gradient(90deg, #60a5fa, #1d4ed8); transition: width 0.3s ease; }
.fx-bar span.done { background: linear-gradient(90deg, #34d399, #047857); }
.fx-pct { font-size: 0.72rem; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--lg-mute); }
@media (max-width: 767.98px) {
  .fx-filter-reset { padding-top: 0; }
}
.fx-cur { font-style: normal; font-size: 0.72em; font-weight: 700; margin-left: 4px; opacity: 0.8; letter-spacing: 0.03em; }
/* row action tooltips always open above the button, even on the first row */
.fx-page .lg-table tbody tr:first-child .lg-tip::after { top: auto; bottom: calc(100% + 8px); }
</style>

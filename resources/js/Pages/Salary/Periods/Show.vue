<template>
  <admin-layout :title="period.label">
    <salary-page>
      <page-header icon="cash" :title="period.label" :subtitle="period.batch_reference + ' · ' + period.period_start + ' - ' + period.period_end">
        <span class="lg-chip" :class="badgeClass(period.status)">{{ period.status }}</span>
        <Link :href="route('salary.periods.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" /> {{ $t('Salary Periods') }}</Link>
      </page-header>

      <div v-if="period.rejection_reason" class="lg-note lg-note-red sl-banner">
        <icons name="exclamation" class="w-4 h-4" /><span><strong>{{ $t('Rejection Reason') }}:</strong> {{ period.rejection_reason }}</span>
      </div>

      <div class="sl-kpis">
        <salary-kpi-card icon="cash" accent="indigo" prefix="TSH" :value="period.total_payable" :label="$t('Total Payable')" />
        <salary-kpi-card icon="tick" accent="emerald" prefix="TSH" :value="period.total_paid" :label="$t('Total Paid')" />
        <salary-kpi-card icon="exclamation" accent="rose" prefix="TSH" :value="period.total_outstanding" :label="$t('Total Outstanding')" />
        <salary-kpi-card icon="users" accent="slate" :value="records.length" :label="$t('Employees')" />
      </div>

      <div v-if="hasActions" class="lg-card ps-actions">
        <Link v-if="can.prepare && ['Draft','Preparing'].includes(period.status)" :href="route('salary.preparation.individual') + '?salary_period_id=' + period.id" class="lg-btn lg-btn-soft">
          <icons name="plus" class="w-4 h-4" /> {{ $t('Prepare Individual') }}
        </Link>
        <Link v-if="can.prepare && ['Draft','Preparing'].includes(period.status)" :href="route('salary.preparation.bulk') + '?salary_period_id=' + period.id" class="lg-btn lg-btn-soft">
          <icons name="list" class="w-4 h-4" /> {{ $t('Prepare Bulk') }}
        </Link>
        <button v-if="allowedTargetStatuses.includes('Pending Approval')" type="button" class="lg-btn lg-btn-primary" @click="submit">
          <icons name="send" class="w-4 h-4" /> {{ $t('Submit for Approval') }}
        </button>
        <button v-if="allowedTargetStatuses.includes('Approved')" type="button" class="lg-btn lg-btn-green" @click="approve">
          <icons name="tick" class="w-4 h-4" /> {{ $t('Approve') }}
        </button>
        <button v-if="allowedTargetStatuses.includes('Rejected')" type="button" class="lg-btn lg-btn-red" @click="openReasonModal('reject')">
          <icons name="cross" class="w-4 h-4" /> {{ $t('Reject') }}
        </button>
        <button v-if="allowedTargetStatuses.includes('Cancelled')" type="button" class="lg-btn lg-btn-red" @click="openReasonModal('cancel')">
          <icons name="x-circle" class="w-4 h-4" /> {{ $t('Cancel Period') }}
        </button>
        <button v-if="$can('create-salary-vouchers') && records.length" type="button" class="lg-btn lg-btn-dark" @click="generateBulkVoucher">
          <icons name="doc-text" class="w-4 h-4" /> {{ $t('Generate Bulk Voucher') }}
        </button>
        <button v-if="canPayAll" type="button" class="lg-btn lg-btn-green" @click="openBulkPay">
          <icons name="cash" class="w-4 h-4" /> {{ $t('Pay All Outstanding') }}
        </button>
      </div>

      <section class="lg-card sl-flush">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Employee') }}</th>
                <th class="num">{{ $t('Net') }}</th>
                <th class="num">{{ $t('Payable') }}</th>
                <th class="num">{{ $t('Remaining') }}</th>
                <th>{{ $t('Status') }}</th>
                <th class="lg-sticky-end"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in records" :key="r.id" class="is-click" @click="openDrawer(r)">
                <td :data-label="$t('Employee')">
                  <div class="lg-person">
                    <img v-if="r.photo_url" :src="r.photo_url" class="ps-avatar" alt="" />
                    <span v-else class="lg-avatar">{{ initials(r.staff_name) }}</span>
                    <div class="min-w-0">
                      <div class="lg-main">{{ r.staff_name }}</div>
                      <div class="lg-subtext">{{ r.staff_number }} &middot; {{ r.position }}</div>
                    </div>
                  </div>
                </td>
                <td :data-label="$t('Net')" class="num lg-main">{{ $money(r.net_salary) }}</td>
                <td :data-label="$t('Payable')" class="num lg-main is-accent">{{ $money(r.current_payable) }}</td>
                <td :data-label="$t('Remaining')" class="num" :class="r.remaining_balance > 0 ? 'is-bad' : ''">{{ $money(r.remaining_balance) }}</td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="badgeClass(r.payment_status)">{{ r.payment_status }}</span></td>
                <td :data-label="$t('Actions')" class="lg-sticky-end">
                  <div class="lg-actions">
                    <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('View')" :aria-label="$t('View')" @click.stop="openDrawer(r)"><icons name="eye-flat" class="w-4 h-4" /></button>
                  </div>
                </td>
              </tr>
              <tr v-if="records.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="6">{{ $t('No salary records prepared yet for this period.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </salary-page>

    <modal :show="!!reasonMode" @close="reasonMode = null">
      <div class="ps-modal">
        <header class="ps-modal-head">
          <span class="ps-modal-ico lg-ico-rose"><icons name="exclamation" class="w-5 h-5" /></span>
          <h3 class="ps-modal-title">{{ reasonMode === 'cancel' ? $t('Cancel Salary Period') : $t('Reject Salary Period') }}</h3>
        </header>
        <textarea-input :label="reasonMode === 'cancel' ? $t('Cancellation Reason') : $t('Rejection Reason')" v-model="reasonText" required />
        <div class="ps-modal-foot">
          <button type="button" class="lg-btn lg-btn-soft ps-soft" @click="reasonMode = null">{{ $t('Close') }}</button>
          <button type="button" class="lg-btn lg-btn-red" @click="submitReason">{{ reasonMode === 'cancel' ? $t('Cancel Period') : $t('Reject') }}</button>
        </div>
      </div>
    </modal>

    <modal :show="showBulkPay" @close="closeBulkPay">
      <div class="ps-modal">
        <header class="ps-modal-head">
          <span class="ps-modal-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h3 class="ps-modal-title">{{ $t('Pay All Outstanding') }}</h3>
            <p class="ps-modal-sub">{{ $t('Pays every employee in this period their full remaining balance, in one action.') }}</p>
          </div>
        </header>
        <div class="lg-note lg-note-blue ps-note">
          <icons name="info" class="w-4 h-4" />
          <span>{{ $t('This will record {count} payment(s) totalling {total}.', { count: unpaidCount, total: $money(period.total_outstanding) }) }}</span>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <text-input type="date" v-model="bulkPay.payment_date" :label="$t('Payment Date')" required />
          <text-input v-model="bulkPay.payment_method" :label="$t('Payment Method')" placeholder="e.g. Bank Transfer" />
        </div>
        <text-input v-model="bulkPay.payment_reference" :label="$t('Reference')" class="mt-3" placeholder="e.g. Batch TXN ref" />
        <div class="ps-modal-foot">
          <button type="button" class="lg-btn lg-btn-soft ps-soft" :disabled="bulkPaying" @click="closeBulkPay">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-green" :disabled="bulkPaying || !bulkPay.payment_date" @click="submitBulkPay">
            <icons :name="bulkPaying ? 'refresh' : 'cash'" class="w-4 h-4" :class="{ 'animate-spin': bulkPaying }" />
            {{ bulkPaying ? $t('Paying...') : $t('Confirm & Pay All') }}
          </button>
        </div>
      </div>
    </modal>

    <salary-drawer :show="!!activeRecord" :title="activeRecord ? activeRecord.staff_name : ''" :subtitle="activeRecord ? activeRecord.staff_number + ' · ' + activeRecord.position : ''" icon="user" accent="#4f46e5" @close="activeRecord = null">
      <template v-if="activeRecord">
        <div class="grid grid-cols-3 gap-3">
          <div class="sd-stat"><div class="sd-stat-value">{{ $money(activeRecord.net_salary) }}</div><div class="sd-stat-label">{{ $t('Net') }}</div></div>
          <div class="sd-stat"><div class="sd-stat-value">{{ $money(activeRecord.total_paid) }}</div><div class="sd-stat-label">{{ $t('Paid') }}</div></div>
          <div class="sd-stat sd-stat-rose"><div class="sd-stat-value">{{ $money(activeRecord.remaining_balance) }}</div><div class="sd-stat-label">{{ $t('Remaining') }}</div></div>
        </div>

        <div>
          <div class="sd-section-title">{{ $t('Pay Elements') }}</div>
          <div class="sd-items">
            <div v-for="(item, i) in activeRecord.items" :key="i" class="sd-item-row">
              <span class="sd-item-label">
                {{ item.label }}
                <button v-if="item.source === 'manual' && canEditRecord" type="button" class="sd-remove-btn" :aria-label="$t('Remove')" @click="removeItem(item)">&times;</button>
              </span>
              <span class="sd-item-amount" :class="item.type === 'deduction' ? 'sd-item-negative' : (item.type === 'employer_cost' ? 'sd-item-muted' : 'sd-item-positive')">
                {{ item.type === 'deduction' ? '-' : (item.type === 'employer_cost' ? '' : '+') }}{{ $money(item.amount) }}
              </span>
            </div>
          </div>
          <div class="sd-item-row sd-item-row-total">
            <span class="sd-item-label">{{ $t('Opening Outstanding Balance') }}</span>
            <span class="sd-item-amount">{{ $money(activeRecord.opening_outstanding_balance) }}</span>
          </div>

          <form v-if="canEditRecord" @submit.prevent="addItem" class="sd-mini-form">
            <input v-model="newItem.label" type="text" :placeholder="$t('e.g. Overtime, Bonus, LWOP')" class="sd-mini-input flex-1" />
            <input v-model.number="newItem.amount" type="number" step="0.01" :placeholder="$t('Amount')" class="sd-mini-input w-24" />
            <css-select v-model="newItem.type" class="sd-mini-input w-28">
              <option value="earning">{{ $t('Earning') }}</option>
              <option value="deduction">{{ $t('Deduction') }}</option>
            </css-select>
            <button type="submit" class="sd-mini-btn">{{ $t('Add') }}</button>
          </form>
        </div>

        <div v-if="$can('adjust-salary-periods')">
          <div class="sd-section-title">{{ $t('Adjust Outstanding Balance') }}</div>
          <form @submit.prevent="addAdjustment" class="sd-mini-form">
            <css-select v-model="adjustment.type" class="sd-mini-input w-28">
              <option value="waiver">{{ $t('Waiver') }}</option>
              <option value="correction">{{ $t('Correction') }}</option>
              <option value="settlement">{{ $t('Settlement') }}</option>
            </css-select>
            <input v-model.number="adjustment.amount" type="number" step="0.01" :placeholder="$t('Amount')" class="sd-mini-input w-24" />
            <input v-model="adjustment.reason" type="text" :placeholder="$t('Reason')" class="sd-mini-input flex-1" />
            <button type="submit" class="sd-mini-btn">{{ $t('Apply') }}</button>
          </form>
          <p class="sd-hint">{{ $t('Waiver/Settlement reduce what\'s owed (use a positive amount); Correction adds to it.') }}</p>
        </div>

        <div>
          <div class="sd-section-title">{{ $t('Payment History') }}</div>
          <div v-if="activeRecord.payments.length" class="sd-items">
            <div v-for="(p, i) in activeRecord.payments" :key="i" class="sd-item-row">
              <span class="sd-item-label">
                {{ p.date }} <span v-if="p.method" class="ps-method">&middot; {{ p.method }}</span>
                <span v-if="p.is_reversal" class="sd-reversal-tag">{{ $t('Reversal') }}</span>
                <button v-if="!p.is_reversal && !p.is_reversed && can.pay" type="button" class="sd-remove-btn" @click="reversePayment(p)">{{ $t('Reverse') }}</button>
              </span>
              <span class="sd-item-amount" :class="p.amount < 0 ? 'sd-item-negative' : 'sd-item-positive'">{{ $money(p.amount) }}</span>
            </div>
          </div>
          <div v-else class="sd-empty">{{ $t('No payments recorded yet.') }}</div>
        </div>
      </template>

      <template #footer>
        <Link v-if="can.pay && activeRecord && activeRecord.remaining_balance > 0 && ['Approved','Partially Paid'].includes(period.status)" :href="route('salary.payments.pay', activeRecord.id)" class="lg-btn lg-btn-green">
          <icons name="cash" class="w-4 h-4" /> {{ $t('Pay') }}
        </Link>
        <button v-if="$can('create-salary-vouchers') && activeRecord" type="button" class="lg-btn lg-btn-primary" @click="generateIndividualVoucher(activeRecord.id)">
          <icons name="doc-text" class="w-4 h-4" /> {{ $t('Voucher') }}
        </button>
        <Link v-if="$can('view-salary-ledger') && activeRecord" :href="route('salary.reports.ledger', activeRecord.staff_id)" class="lg-btn lg-btn-soft">
          <icons name="chart-bar" class="w-4 h-4" /> {{ $t('Ledger') }}
        </Link>
      </template>
    </salary-drawer>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Modal from '@/Jetstream/Modal.vue';
import TextInput from '@/Shared/TextInput.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import SalaryDrawer from '@/Shared/Salary/SalaryDrawer.vue';
import SalaryKpiCard from '@/Shared/Salary/SalaryKpiCard.vue';
import { Link } from '@inertiajs/vue3';
import SalaryPage from '@/Shared/Salary/SalaryPage.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, Modal, TextInput, TextareaInput, SalaryDrawer, SalaryKpiCard, Link, SalaryPage, PageHeader },
  props: { period: Object, records: Array, can: Object, allowedTargetStatuses: Array },
  data() {
    return {
      reasonMode: null,
      reasonText: '',
      activeRecord: null,
      newItem: { label: '', amount: '', type: 'deduction' },
      adjustment: { type: 'waiver', amount: '', reason: '' },
      showBulkPay: false,
      bulkPaying: false,
      bulkPay: { payment_date: new Date().toISOString().slice(0, 10), payment_method: '', payment_reference: '' },
    };
  },
  computed: {
    canEditRecord() {
      return ['Draft', 'Preparing'].includes(this.period.status);
    },
    canPayAll() {
      return this.can.pay && this.period.total_outstanding > 0 && ['Approved', 'Partially Paid'].includes(this.period.status);
    },
    hasActions() {
      const t = this.allowedTargetStatuses;
      return (this.can.prepare && ['Draft', 'Preparing'].includes(this.period.status))
        || ['Pending Approval', 'Approved', 'Rejected', 'Cancelled'].some(s => t.includes(s))
        || (this.$can('create-salary-vouchers') && this.records.length > 0)
        || this.canPayAll;
    },
    unpaidCount() {
      return this.records.filter(r => r.remaining_balance > 0).length;
    },
  },
  methods: {
    badgeClass(status) {
      const map = {
        Draft: 'lg-chip-slate', Preparing: 'lg-chip-slate', 'Pending Approval': 'lg-chip-amber',
        Approved: 'lg-chip-indigo', 'Partially Paid': 'lg-chip-amber', Paid: 'lg-chip-green',
        Rejected: 'lg-chip-red', Cancelled: 'lg-chip-red', Closed: 'lg-chip-slate',
      };
      return map[status] || 'lg-chip-slate';
    },
    initials(name) {
      return (name || '').split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase();
    },
    openDrawer(record) {
      this.activeRecord = record;
    },
    submit() {
      this.$inertia.patch(route('salary.periods.submit', this.period.id));
    },
    approve() {
      this.$inertia.patch(route('salary.periods.approve', this.period.id));
    },
    openReasonModal(mode) {
      this.reasonMode = mode;
      this.reasonText = '';
    },
    submitReason() {
      if (this.reasonMode === 'cancel') {
        this.$inertia.patch(route('salary.periods.cancel', this.period.id), { cancellation_reason: this.reasonText }, { onSuccess: () => (this.reasonMode = null) });
      } else {
        this.$inertia.patch(route('salary.periods.reject', this.period.id), { rejection_reason: this.reasonText }, { onSuccess: () => (this.reasonMode = null) });
      }
    },
    addItem() {
      if (!this.newItem.label || !this.newItem.amount || !this.activeRecord) return;
      this.$inertia.post(route('salary.records.items.store', this.activeRecord.id), this.newItem, {
        preserveScroll: true,
        onSuccess: () => { this.newItem = { label: '', amount: '', type: 'deduction' }; this.refreshActiveRecord(); },
      });
    },
    removeItem(item) {
      this.$inertia.delete(route('salary.records.items.destroy', [this.activeRecord.id, item.id]), {
        preserveScroll: true,
        onSuccess: () => this.refreshActiveRecord(),
      });
    },
    addAdjustment() {
      if (!this.adjustment.amount || !this.adjustment.reason || !this.activeRecord) return;
      this.$inertia.post(route('salary.records.adjustments.store', this.activeRecord.id), this.adjustment, {
        preserveScroll: true,
        onSuccess: () => { this.adjustment = { type: 'waiver', amount: '', reason: '' }; this.refreshActiveRecord(); },
      });
    },
    reversePayment(payment) {
      const reason = prompt(this.$t('Reason for reversing this payment:'));
      if (!reason) return;
      this.$inertia.patch(route('salary.payments.reverse', payment.id), { reason }, {
        preserveScroll: true,
        onSuccess: () => this.refreshActiveRecord(),
      });
    },
    // Inertia's own back()->with() reload refreshes `records`, but the open
    // drawer's `activeRecord` is a snapshot taken at openDrawer() time — re-
    // point it at the refreshed row so Pay Elements/Payment History reflect
    // the change just made without closing and reopening the drawer.
    refreshActiveRecord() {
      if (!this.activeRecord) return;
      const id = this.activeRecord.id;
      this.$nextTick(() => {
        this.activeRecord = this.records.find(r => r.id === id) || null;
      });
    },
    generateBulkVoucher() {
      this.$inertia.post(route('salary.vouchers.generate.bulk', this.period.id));
    },
    openBulkPay() {
      this.bulkPay = { payment_date: new Date().toISOString().slice(0, 10), payment_method: '', payment_reference: '' };
      this.showBulkPay = true;
    },
    closeBulkPay() {
      if (this.bulkPaying) return;
      this.showBulkPay = false;
    },
    submitBulkPay() {
      if (this.bulkPaying || !this.bulkPay.payment_date) return;
      this.bulkPaying = true;
      this.$inertia.post(route('salary.payments.bulk', this.period.id), this.bulkPay, {
        preserveScroll: true,
        onFinish: () => {
          this.bulkPaying = false;
          this.showBulkPay = false;
        },
      });
    },
    generateIndividualVoucher(recordId) {
      this.$inertia.post(route('salary.vouchers.generate.individual', recordId));
    },
  },
};
</script>

<style scoped>
.ps-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 0.8rem 1rem; }
.ps-avatar { width: 32px; height: 32px; flex: none; border-radius: 9999px; object-fit: cover; box-shadow: 0 0 0 2px var(--lg-card); }
.ps-method { color: var(--lg-faint); }

.ps-modal { padding: 1.4rem; }
.ps-modal-head { display: flex; align-items: center; gap: 12px; margin-bottom: 1rem; }
.ps-modal-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 40px; height: 40px; border-radius: 13px; color: #fff; }
.ps-modal-title { font-size: 1.02rem; font-weight: 800; color: #0f172a; }
.ps-modal-sub { margin-top: 1px; font-size: 0.76rem; color: #64748b; }
html.dark .ps-modal-title { color: #f1f5f9; }
html.dark .ps-modal-sub { color: #94a3b8; }
.ps-note { margin-bottom: 1rem; }
.ps-modal-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 1.2rem; }
.ps-soft { --lg-card: #fff; --lg-navy: #1c3f66; }
html.dark .ps-soft { --lg-card: rgba(255, 255, 255, 0.06); --lg-navy: #cbd5e1; }
</style>

<template>
  <admin-layout :title="paymentVoucher.pv_number">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="bag" :title="paymentVoucher.pv_number" :subtitle="[paymentVoucher.payee_name, paymentVoucher.warehouse_name].filter(Boolean).join(' · ')">
        <Link :href="route('payment-vouchers.index')" class="lg-btn lg-btn-soft">
          <icons name="list" class="w-4 h-4" />{{ $t('Payment Vouchers') }}
        </Link>
        <a v-if="canPrint" :href="route('payment-vouchers.print', paymentVoucher.id)" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </a>
        <a v-if="canPrint" :href="route('payment-vouchers.pdf', paymentVoucher.id)" class="lg-btn lg-btn-dark">
          <icons name="download" class="w-4 h-4" />{{ $t('Download') }}
        </a>
        <Link v-if="canEdit" :href="route('payment-vouchers.edit', paymentVoucher.id)" class="lg-btn lg-btn-primary">
          <icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}
        </Link>
        <button v-if="canDelete" type="button" @click="deleteRecord" class="lg-btn lg-btn-red">
          <icons name="trash" class="w-4 h-4" />{{ $t('Delete') }}
        </button>
      </page-header>

      <stat-tiles :items="statTiles" />

      <!-- Overview -->
      <section class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="flex-1 min-w-0">
            <div class="lg-card-title">{{ $t('Voucher Overview') }}</div>
          </div>
          <span class="pv-status" :class="'pv-st-' + statusKey(paymentVoucher.display_status || paymentVoucher.status)" role="status"><icons :name="statusIcon(paymentVoucher.display_status || paymentVoucher.status)" class="pv-status__ico" />{{ $t(paymentVoucher.display_status || paymentVoucher.status) }}</span>
        </div>
        <dl class="pv-dl">
          <div><dt>{{ $t('PV Number') }}</dt><dd>{{ paymentVoucher.pv_number }}</dd></div>
          <div><dt>{{ $t('Type') }}</dt><dd>{{ paymentVoucher.type }}</dd></div>
          <div>
            <dt>{{ $t('Payee/Staff') }}</dt>
            <dd>
              {{ paymentVoucher.payee_name }}
              <Link v-if="paymentVoucher.staff_id" :href="route('staffs.show', paymentVoucher.staff_id)" class="pv-link text-xs ml-1">
                ({{ $t('View Staff') }})
              </Link>
            </dd>
          </div>
          <div><dt>{{ $t('Payee Type') }}</dt><dd>{{ paymentVoucher.payee_type }}</dd></div>
          <div><dt>{{ $t('Project') }}</dt><dd>{{ paymentVoucher.warehouse_name }}</dd></div>
          <div v-if="paymentVoucher.asset_id">
            <dt>{{ $t('Asset') }}</dt>
            <dd><Link :href="route('assets.show', paymentVoucher.asset_id)" class="pv-link">{{ paymentVoucher.asset_name }}</Link></dd>
          </div>
          <div><dt>{{ $t('Date') }}</dt><dd>{{ $date(paymentVoucher.voucher_date) }}</dd></div>
          <div><dt>{{ $t('Prepared By') }}</dt><dd>{{ paymentVoucher.prepared_by_name || '-' }}</dd></div>
        </dl>
        <dl v-if="paymentVoucher.purchase_order_id || paymentVoucher.supplier_invoice_id || paymentVoucher.payment_request_id || paymentVoucher.labour_list_id || paymentVoucher.subcontract_request || paymentVoucher.subcontract_claim || paymentVoucher.payment_voucher_batch_id" class="pv-dl pv-dl-sep">
          <div v-if="paymentVoucher.subcontract_request">
            <dt>{{ $t('Subcontract Request') }}</dt>
            <dd><a class="pv-link" :href="paymentVoucher.subcontract_request.url">{{ paymentVoucher.subcontract_request.reference }}</a> <small>({{ paymentVoucher.subcontract_request.agreement }})</small></dd>
          </div>
          <div v-if="paymentVoucher.subcontract_claim">
            <dt>{{ $t('Contract Payment Request') }}</dt>
            <dd><a v-if="paymentVoucher.subcontract_claim.url" class="pv-link" :href="paymentVoucher.subcontract_claim.url">{{ paymentVoucher.subcontract_claim.reference }}</a><template v-else>{{ paymentVoucher.subcontract_claim.reference }}</template> <small>({{ paymentVoucher.subcontract_claim.status }})</small></dd>
          </div>
          <div v-if="paymentVoucher.purchase_order_id"><dt>{{ $t('Purchase Order') }}</dt><dd>{{ paymentVoucher.purchase_order_reference }}</dd></div>
          <div v-if="paymentVoucher.supplier_invoice_id"><dt>{{ $t('Supplier Invoice') }}</dt><dd>{{ paymentVoucher.supplier_invoice_reference }}</dd></div>
          <div v-if="paymentVoucher.payment_request_id"><dt>{{ $t('Payment Request') }}</dt><dd>{{ paymentVoucher.payment_request_reference }}</dd></div>
          <div v-if="paymentVoucher.labour_list_id">
            <dt>{{ $t('Labour List') }}</dt>
            <dd><Link class="pv-link" :href="route('labour.show', paymentVoucher.labour_list_id)">{{ paymentVoucher.labour_list_reference }}</Link></dd>
          </div>
          <div v-if="paymentVoucher.payment_voucher_batch_id">
            <dt>{{ $t('Batch') }}</dt>
            <dd><Link class="pv-link" :href="route('payment-vouchers.batches.show', paymentVoucher.payment_voucher_batch_id)">{{ paymentVoucher.batch_number }}</Link></dd>
          </div>
        </dl>
        <div v-if="paymentVoucher.subcontract_claim && paymentVoucher.subcontract_claim.allocations && paymentVoucher.subcontract_claim.allocations.length" class="pv-dl-sep">
          <h3 class="text-sm font-semibold mb-2">{{ $t('What this voucher pays (per contract)') }}</h3>
          <div class="lg-table-wrap">
            <table class="lg-table lg-responsive">
              <thead><tr><th>{{ $t('Contract') }}</th><th>{{ $t('Payment request') }}</th><th v-if="paymentVoucher.subcontract_claim.allocations[0].amount !== null" class="text-right">{{ $t('Amount') }}</th></tr></thead>
              <tbody>
                <tr v-for="a in paymentVoucher.subcontract_claim.allocations" :key="a.payment_id">
                  <td :data-label="$t('Contract')"><a v-if="paymentVoucher.subcontract_claim.url" class="pv-link" :href="route('subcontracts.agreements.show', a.agreement_id)">{{ a.agreement }}</a><template v-else>{{ a.agreement }}</template> <small>{{ a.title }}</small></td>
                  <td :data-label="$t('Payment request')">{{ a.request || '-' }}</td>
                  <td v-if="a.amount !== null" :data-label="$t('Amount')" class="text-right">{{ a.currency }} {{ Number(a.amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <dl v-if="paymentVoucher.paid_at" class="pv-dl pv-dl-sep">
          <div><dt>{{ $t('Paid') }}</dt><dd>{{ new Date(paymentVoucher.paid_at).toLocaleString() }}</dd></div>
          <div v-if="paymentVoucher.payment_reference"><dt>{{ $t('Payment Reference') }}</dt><dd>{{ paymentVoucher.payment_reference }}</dd></div>
          <div v-if="paymentVoucher.payment_method"><dt>{{ $t('Method') }}</dt><dd>{{ paymentVoucher.payment_method }}</dd></div>
          <div v-if="paymentVoucher.paid_by_name"><dt>{{ $t('Recorded By') }}</dt><dd>{{ paymentVoucher.paid_by_name }}</dd></div>
        </dl>
      </section>

      <section v-if="canRecordPayment" class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
          <div class="flex-1 min-w-0">
            <div class="lg-card-title">{{ $t('Record Payment') }}</div>
          </div>
        </div>
        <div class="lg-fields">
          <text-input :label="$t('Payment Reference (optional)')" v-model="paymentForm.payment_reference" />
          <text-input :label="$t('Payment Method (optional)')" v-model="paymentForm.payment_method" />
        </div>
        <div class="flex justify-end mt-4">
          <button type="button" class="lg-btn lg-btn-green" @click="recordPayment">
            <icons name="tick" class="w-4 h-4" />{{ $t('Mark as Paid') }}
          </button>
        </div>
      </section>

      <!-- Particulars -->
      <section class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="list" class="w-5 h-5" /></span>
          <div class="flex-1 min-w-0">
            <div class="lg-card-title">{{ $t('Particulars') }}</div>
          </div>
        </div>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th class="w-12">{{ $t('SN') }}</th>
                <th>{{ $t('Particulars') }}</th>
                <th class="num">{{ $t('Amount') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in paymentVoucher.items" :key="item.id">
                <td :data-label="$t('SN')" class="lg-subtext">{{ item.line_number }}</td>
                <td :data-label="$t('Particulars')" class="pv-wrap">{{ item.particulars }}</td>
                <td :data-label="$t('Amount')" class="num font-semibold">{{ $money(item.amount) }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="pv-total-row">
                <td colspan="2" class="num font-bold">{{ $t('Total') }}</td>
                <td class="num font-bold">{{ $money(paymentVoucher.total_amount) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
        <p class="lg-subtext mt-3">{{ $t('Amount in Words') }}: <span class="font-semibold pv-strong">{{ paymentVoucher.amount_in_words }}</span></p>
        <p v-if="paymentVoucher.remarks" class="lg-subtext mt-2">
          <span class="font-semibold">{{ $t('Remarks / Notes') }} <icons name="printer" class="w-3 h-3 inline-block" :title="$t('Printed on the voucher')" />:</span>
          {{ paymentVoucher.remarks }}
        </p>
        <p v-if="paymentVoucher.internal_notes" class="lg-subtext mt-2">
          <span class="font-semibold">{{ $t('Internal Notes') }} ({{ $t('not printed') }}):</span>
          {{ paymentVoucher.internal_notes }}
        </p>
      </section>

      <!-- Approval Chain -->
      <section class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-teal"><icons name="users" class="w-5 h-5" /></span>
          <div class="flex-1 min-w-0">
            <div class="lg-card-title">{{ $t('Approval Chain') }}</div>
          </div>
        </div>
        <div class="pv-chain">
          <div class="pv-step is-done">
            <div class="pv-step-title">{{ $t('Prepared') }}</div>
            <div class="pv-step-body">{{ paymentVoucher.prepared_by_name || '-' }} on {{ $datetime(paymentVoucher.created_at) }}</div>
          </div>
          <div v-if="paymentVoucher.approved_at" class="pv-step is-done">
            <div class="pv-step-title">{{ $t('Approved') }} ({{ $t('Stage 1') }})</div>
            <div class="pv-step-body">{{ paymentVoucher.approved_by_name || '-' }} on {{ $datetime(paymentVoucher.approved_at) }}</div>
          </div>
          <div v-if="paymentVoucher.authorised_at" class="pv-step is-done">
            <div class="pv-step-title">{{ $t('Authorised') }} ({{ $t('Stage 2') }})</div>
            <div class="pv-step-body">{{ paymentVoucher.authorised_by_name || '-' }} on {{ $datetime(paymentVoucher.authorised_at) }}</div>
          </div>
          <div v-if="paymentVoucher.status === 'Rejected' && paymentVoucher.approval_remarks" class="pv-step is-bad">
            <div class="pv-step-title">{{ $t('Rejected') }}</div>
            <div class="pv-step-body">{{ paymentVoucher.approval_remarks }}</div>
          </div>
        </div>
      </section>

      <!-- Submit -->
      <section v-if="canSubmit" class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="plane" class="w-5 h-5" /></span>
          <div class="flex-1 min-w-0">
            <div class="lg-card-title">{{ $t('Submit for Approval') }}</div>
          </div>
        </div>
        <loading-button type="button" :loading="submitForm.processing" :disabled="submitForm.processing" @click="submit">
          <icons name="plane" class="w-4 h-4 mr-1" /> {{ $t('Submit') }}
        </loading-button>
      </section>

      <!-- Stage 1: Approve -->
      <section v-if="canApprove" class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="tick" class="w-5 h-5" /></span>
          <div class="flex-1 min-w-0">
            <div class="lg-card-title">{{ $t('Stage 1 Review (Approve)') }}</div>
          </div>
        </div>
        <textarea-input v-model="reviewForm.approval_remarks" :label="$t('Remarks (required to reject)')" :error="$page.props.errors.approval_remarks" />
        <div class="flex justify-end gap-3 mt-3 flex-wrap">
          <button type="button" class="lg-btn lg-btn-red" @click="rejectApproval">
            <icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}
          </button>
          <loading-button type="button" :loading="reviewForm.processing" :disabled="reviewForm.processing" @click="approve">
            <icons name="tick" class="w-4 h-4 mr-1" /> {{ $t('Approve') }}
          </loading-button>
        </div>
      </section>

      <!-- Stage 2: Authorise -->
      <section v-if="canAuthorise" class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="lock-closed" class="w-5 h-5" /></span>
          <div class="flex-1 min-w-0">
            <div class="lg-card-title">{{ $t('Stage 2 Review (Authorise)') }}</div>
          </div>
        </div>
        <textarea-input v-model="authForm.approval_remarks" :label="$t('Remarks (required to reject)')" :error="$page.props.errors.approval_remarks" />
        <div class="flex justify-end gap-3 mt-3 flex-wrap">
          <button type="button" class="lg-btn lg-btn-red" @click="rejectAuthorisation">
            <icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}
          </button>
          <loading-button type="button" :loading="authForm.processing" :disabled="authForm.processing" @click="authorise">
            <icons name="tick" class="w-4 h-4 mr-1" /> {{ $t('Authorise') }}
          </loading-button>
        </div>
      </section>

      <!-- Print history -->
      <section class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="printer" class="w-5 h-5" /></span>
          <div class="flex-1 min-w-0">
            <div class="lg-card-title">{{ $t('Print History') }}</div>
          </div>
        </div>
        <p class="text-sm pv-strong">
          <template v-if="paymentVoucher.printed_count > 0">
            {{ $t('Printed') }} {{ paymentVoucher.printed_count }} {{ $t('time(s), first on') }} {{ $datetime(paymentVoucher.first_printed_at) }}.
          </template>
          <template v-else>{{ $t('Not printed yet.') }}</template>
        </p>
      </section>

      <!-- Activity History -->
      <section class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="clock" class="w-5 h-5" /></span>
          <div class="flex-1 min-w-0">
            <div class="lg-card-title">{{ $t('Activity History') }}</div>
          </div>
        </div>
        <div v-if="!activity.length" class="lg-empty">{{ $t('No history recorded yet.') }}</div>
        <div v-else class="pv-activity-list">
          <div v-for="(entry, idx) in activity" :key="idx" class="pv-activity-row">
            <div class="flex items-center justify-between gap-3 flex-wrap">
              <span class="font-semibold text-sm pv-strong">{{ entry.description }}</span>
              <span class="lg-subtext shrink-0">{{ entry.created_at ? $datetime(entry.created_at) : '' }}</span>
            </div>
            <div class="lg-subtext">{{ $t('By') }} {{ entry.causer }}</div>
          </div>
        </div>
      </section>

      <!-- Attachments -->
      <section class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-pink"><icons name="clip" class="w-5 h-5" /></span>
          <div class="flex-1 min-w-0">
            <div class="lg-card-title">{{ $t('Attachments') }}</div>
          </div>
        </div>
        <ul class="pv-files text-sm">
          <li v-for="file in paymentVoucher.attachments" :key="file.id" class="py-2 flex items-center justify-between gap-3">
            <span class="truncate pv-strong">{{ file.name }}</span>
            <a :href="route('payment-vouchers.attachments.download', [paymentVoucher.id, file.id])" class="pv-link shrink-0 inline-flex items-center gap-1">
              <icons name="download" class="w-4 h-4" /> {{ $t('Download') }}
            </a>
          </li>
          <li v-if="!paymentVoucher.attachments || !paymentVoucher.attachments.length" class="py-2 lg-subtext">{{ $t('No attachments.') }}</li>
        </ul>
      </section>

      <!-- Cancel -->
      <section v-if="canCancel" class="lg-card flex items-center justify-between gap-3 flex-wrap">
        <div class="lg-card-title">{{ $t('Cancel Voucher') }}</div>
        <button type="button" class="lg-btn lg-btn-dark" @click="cancel">
          <icons name="cross" class="w-4 h-4" />{{ $t('Cancel Voucher') }}
        </button>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import TextInput from '@/Shared/TextInput.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';

export default {
  components: { AdminLayout, PageHeader, StatTiles, TextareaInput, TextInput, LoadingButton },

  props: {
    paymentVoucher: Object,
    companyName: String,
    activity: { type: Array, default: () => [] },
  },

  data() {
    return {
      submitForm: this.$inertia.form({}),
      reviewForm: this.$inertia.form({ approval_remarks: '' }),
      authForm: this.$inertia.form({ approval_remarks: '' }),
      cancelForm: this.$inertia.form({}),
      paymentForm: this.$inertia.form({ payment_reference: '', payment_method: '' }),
    };
  },

  computed: {
    statTiles() {
      const v = this.paymentVoucher;
      const tiles = [
        { icon: 'cash', tone: 'pink', label: this.$t('Amount'), value: this.$money(v.total_amount) },
        { icon: this.statusIcon(v.display_status || v.status), tone: { Draft: 'slate', Submitted: 'amber', Approved: 'violet', Authorised: 'sky', Paid: 'emerald', Rejected: 'rose', Cancelled: 'slate' }[v.display_status || v.status] || 'navy', label: this.$t('Status'), value: this.$t(v.display_status || v.status) },
        { icon: 'calendar', tone: 'sky', label: this.$t('Date'), value: this.$date(v.voucher_date) },
        { icon: 'list', tone: 'indigo', label: this.$t('Particulars'), value: (v.items || []).length },
      ];
      if (v.paid_at) {
        tiles.push({ icon: 'tick', tone: 'emerald', label: this.$t('Paid'), value: this.$date(v.paid_at) });
      }
      return tiles;
    },
    canPrint() {
      return this.$can('print-payment-vouchers');
    },
    canEdit() {
      return ['Draft', 'Rejected'].includes(this.paymentVoucher.status) && this.$can('update-payment-vouchers');
    },
    canDelete() {
      return this.paymentVoucher.status === 'Draft' && this.$can('delete-payment-vouchers');
    },
    canSubmit() {
      return ['Draft', 'Rejected'].includes(this.paymentVoucher.status) && this.$can('update-payment-vouchers');
    },
    canApprove() {
      return this.paymentVoucher.status === 'Submitted' && this.$can('approve-payment-vouchers');
    },
    canAuthorise() {
      return this.paymentVoucher.status === 'Approved' && this.$can('authorise-payment-vouchers');
    },
    canCancel() {
      return !['Authorised', 'Cancelled', 'Rejected'].includes(this.paymentVoucher.status) && this.$can('approve-payment-vouchers');
    },
    canRecordPayment() {
      return this.paymentVoucher.status === 'Authorised' && !this.paymentVoucher.paid_at && this.$can('record-payment-payment-vouchers');
    },
  },

  methods: {
    submit() {
      this.submitForm.patch(route('payment-vouchers.submit', this.paymentVoucher.id));
    },
    approve() {
      this.reviewForm.patch(route('payment-vouchers.approve', this.paymentVoucher.id));
    },
    async rejectApproval() {
      if (!this.reviewForm.approval_remarks || !this.reviewForm.approval_remarks.trim()) {
        await this.$alert(this.$t('Please enter remarks explaining the reason before rejecting.'));
        return;
      }
      this.reviewForm.patch(route('payment-vouchers.reject-approval', this.paymentVoucher.id));
    },
    authorise() {
      this.authForm.patch(route('payment-vouchers.authorise', this.paymentVoucher.id));
    },
    async rejectAuthorisation() {
      if (!this.authForm.approval_remarks || !this.authForm.approval_remarks.trim()) {
        await this.$alert(this.$t('Please enter remarks explaining the reason before rejecting.'));
        return;
      }
      this.authForm.patch(route('payment-vouchers.reject-authorisation', this.paymentVoucher.id));
    },
    async cancel() {
      if (!await this.$confirm(this.$t('Are you sure you want to cancel this payment voucher?'), { variant: 'danger' })) {
        return;
      }
      this.cancelForm.patch(route('payment-vouchers.cancel', this.paymentVoucher.id));
    },
    async deleteRecord() {
      if (!await this.$confirm(this.$t('Are you sure you want to delete this payment voucher?'), { variant: 'danger' })) {
        return;
      }
      this.$inertia.delete(route('payment-vouchers.destroy', this.paymentVoucher.id));
    },
    recordPayment() {
      this.paymentForm.patch(route('payment-vouchers.record-payment', this.paymentVoucher.id));
    },
    // the big status badge of the voucher: its own colour and icon per status
    statusKey(status) { return String(status || 'Draft').toLowerCase(); },
    statusIcon(status) { return { Draft: 'doc-text', Submitted: 'clock', Approved: 'tick', Authorised: 'lock-closed', Paid: 'cash', Rejected: 'x-circle', Cancelled: 'cross' }[status] || 'doc-text'; },
    statusChip(status) {
      return (
        {
          Draft: 'lg-chip-slate',
          Submitted: 'lg-chip-amber',
          Approved: 'lg-chip-blue',
          Authorised: 'lg-chip-green',
          Paid: 'lg-chip-indigo',
          Rejected: 'lg-chip-red',
          Cancelled: 'lg-chip-red',
        }[status] || 'lg-chip-slate'
      );
    },
    statusBadgeClass(status) {
      const map = {
        Draft: 'pv-badge-slate',
        Submitted: 'pv-badge-amber',
        Approved: 'pv-badge-blue',
        Authorised: 'pv-badge-emerald',
        Paid: 'pv-badge-blue',
        Rejected: 'pv-badge-rose',
        Cancelled: 'pv-badge-rose',
      };
      return map[status] || 'pv-badge-slate';
    },
  },
};
</script>

<style scoped>
/* voucher status: big, with an icon, one colour per status (Paid dark green, Submitted orange, Approved purple, Rejected maroon ...) */
.pv-status { display: inline-flex; align-items: center; gap: 0.55rem; flex: none; padding: 0.5rem 1.1rem 0.5rem 0.8rem; border-radius: 999px; color: #fff; font-size: 1.05rem; font-weight: 800; letter-spacing: 0.01em; line-height: 1.2; background: #64748b; box-shadow: 0 12px 20px -12px var(--pv-glow, rgba(100, 116, 139, 0.7)); }
.pv-status__ico { width: 1.35rem; height: 1.35rem; flex: none; padding: 0.2rem; box-sizing: content-box; border-radius: 50%; background: rgba(255, 255, 255, 0.22); }
.pv-st-draft { background: linear-gradient(135deg, #64748b, #475569); --pv-glow: rgba(71, 85, 105, 0.7); }
.pv-st-submitted { background: linear-gradient(135deg, #f97316, #c2410c); --pv-glow: rgba(234, 88, 12, 0.7); }
.pv-st-approved { background: linear-gradient(135deg, #8b5cf6, #5b21b6); --pv-glow: rgba(109, 40, 217, 0.7); }
.pv-st-authorised { background: linear-gradient(135deg, #0ea5e9, #075985); --pv-glow: rgba(3, 105, 161, 0.7); }
.pv-st-paid { background: linear-gradient(135deg, #15803d, #14532d); --pv-glow: rgba(20, 83, 45, 0.75); }
.pv-st-rejected { background: linear-gradient(135deg, #9f1239, #6b0f1a); --pv-glow: rgba(127, 29, 29, 0.75); }
.pv-st-cancelled { background: linear-gradient(135deg, #78716c, #44403c); --pv-glow: rgba(68, 64, 60, 0.7); }
@media (max-width: 480px) { .pv-status { font-size: 0.95rem; padding: 0.45rem 0.9rem 0.45rem 0.65rem; } }

.pv-dl { display: grid; gap: 14px 24px; grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr)); }
.pv-dl > div { min-width: 0; }
.pv-dl-sep { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--lg-line); }
.pv-dl dt { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.pv-dl dd { margin-top: 2px; font-size: 0.88rem; font-weight: 600; color: var(--lg-ink); overflow-wrap: anywhere; }
.pv-link { color: #4338ca; font-weight: 600; }
.pv-link:hover { text-decoration: underline; }
html.dark .pv-link { color: #a5b4fc; }
.pv-strong { color: var(--lg-ink); }
.pv-wrap { white-space: normal; min-width: 220px; }
.pv-total-row td { background: var(--lg-soft); border-bottom: 0; }
.pv-chain { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr)); }
.pv-step { padding: 0.7rem 0.9rem; border-radius: 14px; border: 1px solid var(--lg-line); background: var(--lg-soft); border-left: 4px solid #94a3b8; }
.pv-step.is-done { border-left-color: #10b981; }
.pv-step.is-bad { border-left-color: #f43f5e; }
.pv-step-title { font-size: 0.7rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.pv-step-body { margin-top: 2px; font-size: 0.84rem; font-weight: 600; color: var(--lg-ink); overflow-wrap: anywhere; }
.pv-files > li + li { border-top: 1px solid var(--lg-line); }
.pv-activity-list { display: flex; flex-direction: column; gap: 0.65rem; max-height: 320px; overflow-y: auto; }
.pv-activity-row { padding: 0.6rem 0.75rem; border: 1px solid var(--lg-line); border-radius: 0.8rem; background: var(--lg-soft); }
@media (max-width: 767.98px) {
  .pv-total-row { display: flex !important; justify-content: space-between; }
  .pv-total-row td { background: transparent; }
}
</style>

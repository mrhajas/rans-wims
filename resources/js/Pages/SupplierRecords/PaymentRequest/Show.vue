<template>
  <admin-layout :title="paymentRequest.reference">
    <div class="lg-page px-4 md:px-0 sd-show">
      <page-header
        icon="key"
        :title="paymentRequest.reference"
        :subtitle="[paymentRequest.supplier_name, paymentRequest.type === 'balance' ? $t('Account Balance Payment') : $t('Invoice Payment')].filter(Boolean).join(' · ')"
      >
        <span class="sd-status" :class="'is-' + statusTone(paymentRequest.status)">
          <span class="sd-dot" :class="{ 'is-live': paymentRequest.status === 'Pending Approval' }"></span>
          {{ paymentRequest.status }}
        </span>
        <Link :href="route('supplier-records.payment-requests.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Payment Requests') }}
        </Link>
        <a v-if="$can('print-payment-requests')" :href="route('supplier-records.payment-requests.print', paymentRequest.id)" target="_blank" rel="noopener" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </a>
        <a v-if="$can('print-payment-requests')" :href="route('supplier-records.payment-requests.pdf', paymentRequest.id)" class="lg-btn lg-btn-dark">
          <icons name="download" class="w-4 h-4" />{{ $t('Download') }}
        </a>
        <Link v-if="paymentRequest.status === 'Pending Approval'" :href="route('supplier-records.payment-requests.edit', paymentRequest.id)" class="lg-btn lg-btn-primary">
          <icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}
        </Link>
        <Link v-if="paymentRequest.status === 'Approved'" :href="route('supplier-records.payments.create', { payment_request_id: paymentRequest.id })" class="lg-btn lg-btn-green">
          <icons name="tick" class="w-4 h-4" />{{ $t('Record Payment') }}
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" />

      <div class="sd-grid" :class="{ 'sd-grid-one': paymentRequest.status !== 'Pending Approval' }">
        <div class="sd-col">
          <!-- Overview -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-pink"><icons name="key" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Overview') }}</h2>
                <p class="lg-card-sub">{{ paymentRequest.reference }}</p>
              </div>
            </header>
            <dl class="sd-kv">
              <div>
                <dt>{{ $t('Reference') }}</dt>
                <dd>{{ paymentRequest.reference }}</dd>
              </div>
              <div>
                <dt>{{ $t('Supplier') }}</dt>
                <dd>
                  <Link class="sd-link" :href="route('supplier-records.suppliers.show', paymentRequest.supplier_id)">{{ paymentRequest.supplier_name }}</Link>
                </dd>
              </div>
              <div>
                <dt>{{ $t('Date Requested') }}</dt>
                <dd>{{ $date(paymentRequest.date_requested) }}</dd>
              </div>
              <div>
                <dt>{{ $t('Warehouse') }}</dt>
                <dd>{{ paymentRequest.warehouse_name || '-' }}</dd>
              </div>
            </dl>
            <div v-if="paymentRequest.approval_remarks" class="sd-remarks">
              <span class="sd-remarks-label">{{ $t('Remarks') }}</span>
              {{ paymentRequest.approval_remarks }}
            </div>
          </section>

          <!-- Linked invoices -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-amber"><icons name="doc-text" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Linked Invoices') }}</h2>
              </div>
            </header>
            <p v-if="paymentRequest.type === 'balance'" class="lg-note lg-note-blue">
              <icons name="info" class="w-4 h-4" />
              <span>{{ $t('Not tied to a specific invoice. Once paid, the amount will be applied to this supplier\'s oldest outstanding invoices first (FIFO).') }}</span>
            </p>
            <template v-else>
              <ul v-if="paymentRequest.invoices && paymentRequest.invoices.length" class="sd-rows">
                <li v-for="inv in paymentRequest.invoices" :key="inv.id">
                  <Link :href="route('supplier-records.supplier-invoices.show', inv.id)" class="sd-row">
                    <span class="sd-row-main">{{ inv.invoice_number }}</span>
                    <span class="sd-row-amt">{{ $money(inv.total) }}</span>
                  </Link>
                </li>
              </ul>
              <p v-else class="sd-none">{{ $t('No linked invoices.') }}</p>
            </template>
          </section>

          <!-- Payments -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1">
                <h2 class="lg-card-title">{{ $t('Payments') }}</h2>
              </div>
              <span class="lg-chip lg-chip-slate">{{ payments.length }}</span>
            </header>
            <div class="lg-table-wrap">
              <table class="lg-table lg-responsive">
                <thead>
                  <tr>
                    <th>{{ $t('Reference') }}</th>
                    <th>{{ $t('Date') }}</th>
                    <th>{{ $t('Method') }}</th>
                    <th class="num">{{ $t('Amount') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="payment in payments" :key="payment.id" class="is-click" @click="gotoPayment(payment.id)">
                    <td :data-label="$t('Reference')"><span class="lg-main">{{ payment.reference }}</span></td>
                    <td :data-label="$t('Date')">{{ $date(payment.paid_on) }}</td>
                    <td :data-label="$t('Method')">{{ payment.method }}</td>
                    <td :data-label="$t('Amount')" class="num font-semibold">{{ $money(payment.amount) }}</td>
                  </tr>
                  <tr v-if="!payments.length" class="lg-empty-row">
                    <td class="lg-empty" colspan="4">{{ $t('No payments recorded yet.') }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>

        <div v-if="paymentRequest.status === 'Pending Approval'" class="sd-col">
          <!-- Approval -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Approval') }}</h2>
              </div>
            </header>
            <textarea-input v-model="approvalForm.approval_remarks" :label="$t('Remarks')" :error="$page.props.errors.approval_remarks" />
            <div class="sd-actions mt-3">
              <button type="button" class="lg-btn lg-btn-red" @click="reject">
                <icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}
              </button>
              <button type="button" class="lg-btn lg-btn-green" :disabled="approvalForm.processing" @click="approve">
                <icons :name="approvalForm.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': approvalForm.processing }" />{{ $t('Approve') }}
              </button>
            </div>
          </section>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, TextareaInput, PageHeader, StatTiles },

  props: {
    paymentRequest: Object,
    payments: Array,
  },

  data() {
    return {
      approvalForm: this.$inertia.form({ approval_remarks: '' }),
    };
  },

  computed: {
    statTiles() {
      const pr = this.paymentRequest;
      return [
        { icon: 'cash', tone: 'emerald', label: this.$t('Amount'), value: this.$money(pr.amount) },
        { icon: 'calendar', tone: 'sky', label: this.$t('Date Requested'), value: this.$date(pr.date_requested) },
        { icon: 'building', tone: 'indigo', label: this.$t('Warehouse'), value: pr.warehouse_name || '-' },
        { icon: 'doc-text', tone: 'violet', label: this.$t('Payments'), value: this.payments.length },
      ];
    },
  },

  methods: {
    gotoPayment(id) {
      this.$inertia.visit(route('supplier-records.payments.show', id));
    },
    approve() {
      this.approvalForm.patch(route('supplier-records.payment-requests.approve', this.paymentRequest.id));
    },
    async reject() {
      if (!this.approvalForm.approval_remarks || !this.approvalForm.approval_remarks.trim()) {
        await this.$alert(this.$t('Please enter remarks explaining the reason before rejecting.'));
        return;
      }
      this.approvalForm.patch(route('supplier-records.payment-requests.reject', this.paymentRequest.id));
    },
    statusTone(status) {
      const map = {
        'Pending Approval': 'amber',
        Approved: 'blue',
        Rejected: 'red',
        Paid: 'green',
      };
      return map[status] || 'slate';
    },
  },
};
</script>

<style scoped>
.sd-status { --sc: #475569; --sb: rgba(100, 116, 139, 0.14); display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px 8px 12px; border-radius: 9999px; font-size: 0.82rem; font-weight: 800; letter-spacing: 0.02em; color: var(--sc); background: var(--sb); border: 1px solid color-mix(in srgb, var(--sc) 30%, transparent); box-shadow: 0 10px 18px -12px var(--sc); }
.sd-status.is-amber { --sc: #b45309; --sb: rgba(245, 158, 11, 0.16); }
.sd-status.is-blue { --sc: #1d4ed8; --sb: rgba(59, 130, 246, 0.14); }
.sd-status.is-green { --sc: #047857; --sb: rgba(16, 185, 129, 0.16); }
.sd-status.is-red { --sc: #be123c; --sb: rgba(244, 63, 94, 0.14); }
html.dark .sd-status { --sc: #cbd5e1; }
html.dark .sd-status.is-amber { --sc: #fcd34d; }
html.dark .sd-status.is-blue { --sc: #93c5fd; }
html.dark .sd-status.is-green { --sc: #6ee7b7; }
html.dark .sd-status.is-red { --sc: #fda4af; }
.sd-dot { position: relative; width: 9px; height: 9px; border-radius: 9999px; background: var(--sc); flex: none; }
.sd-dot.is-live::after { content: ''; position: absolute; inset: -4px; border-radius: 9999px; border: 2px solid var(--sc); opacity: 0; animation: sd-live 1.8s ease-out infinite; }
@keyframes sd-live { 0% { transform: scale(0.6); opacity: 0.8; } 100% { transform: scale(1.6); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .sd-dot.is-live::after { animation: none; } }

.sd-grid { display: grid; gap: 0 1rem; grid-template-columns: minmax(0, 1fr); align-items: start; }
@media (min-width: 1024px) { .sd-grid { grid-template-columns: minmax(0, 1fr) 22rem; } }
.sd-col { min-width: 0; }
@media (min-width: 1024px) { .sd-grid.sd-grid-one { grid-template-columns: minmax(0, 1fr); } }

.sd-kv { display: grid; gap: 14px 22px; grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr)); margin: 0; }
.sd-kv > div { min-width: 0; }
.sd-kv dt { margin-bottom: 3px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.sd-kv dd { font-size: 0.9rem; font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }
.sd-link { font-weight: 800; color: var(--lg-navy); }
.sd-link:hover { text-decoration: underline; }
html.dark .sd-link { color: #93c5fd; }
.sd-remarks { margin-top: 16px; padding: 0.75rem 1rem; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); font-size: 0.85rem; color: var(--lg-ink); white-space: pre-wrap; }
.sd-remarks-label { display: block; margin-bottom: 2px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.sd-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
.sd-none { font-size: 0.84rem; color: var(--lg-faint); }

.sd-rows { list-style: none; margin: 0; padding: 0; border: 1px solid var(--lg-line); border-radius: 14px; overflow: hidden; }
.sd-rows li { border-bottom: 1px solid var(--lg-line); }
.sd-rows li:last-child { border-bottom: 0; }
.sd-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 14px; color: var(--lg-ink); transition: background 0.15s ease; }
.sd-row:hover { background: var(--lg-soft); }
.sd-row-main { font-size: 0.86rem; font-weight: 800; }
.sd-row-amt { font-size: 0.86rem; font-weight: 800; font-variant-numeric: tabular-nums; }
</style>

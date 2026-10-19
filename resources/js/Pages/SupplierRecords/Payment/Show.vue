<template>
  <admin-layout :title="payment.reference">
    <div class="lg-page px-4 md:px-0 sd-show">
      <page-header icon="tick" :title="payment.reference" :subtitle="$t('Payment recorded. This is a permanent record.')">
        <span class="sd-status" :class="isVerified ? 'is-green' : 'is-amber'">
          <span class="sd-dot" :class="{ 'is-live': !isVerified }"></span>
          <icons v-if="isVerified" name="tick" class="w-4 h-4" />
          {{ payment.verification_status }}
        </span>
        <Link :href="route('supplier-records.payments.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Payments') }}
        </Link>
        <a :href="route('supplier-records.payments.voucher', payment.id)" target="_blank" class="lg-btn lg-btn-dark">
          <icons name="doc-text" class="w-4 h-4" />{{ $t('Download Payment Voucher') }}
        </a>
        <button v-if="$super && !isVerified" type="button" class="lg-btn lg-btn-green" :disabled="verifyForm.processing" @click="verify">
          <icons :name="verifyForm.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': verifyForm.processing }" />{{ $t('Mark as Verified') }}
        </button>
      </page-header>

      <stat-tiles :items="statTiles" />

      <p v-if="isVerified" class="sd-good">
        <icons name="tick" class="w-4 h-4" />
        <span>{{ $t('Verified by') }} {{ payment.verified_by_name }} {{ $t('on') }} {{ $datetime(payment.verified_on) }}</span>
      </p>

      <div class="sd-grid">
        <div class="sd-col">
          <!-- Details -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Payment Details') }}</h2>
                <p class="lg-card-sub">{{ payment.reference }}</p>
              </div>
            </header>
            <dl class="sd-kv">
              <div>
                <dt>{{ $t('Supplier') }}</dt>
                <dd>
                  <Link class="sd-link" :href="route('supplier-records.suppliers.show', payment.supplier_id)">{{ payment.supplier_name }}</Link>
                </dd>
              </div>
              <div>
                <dt>{{ $t('Payment Made By') }}</dt>
                <dd>{{ payment.paid_by_name || '-' }}</dd>
              </div>
              <div>
                <dt>{{ $t('Method') }}</dt>
                <dd>{{ payment.method }}</dd>
              </div>
              <div v-if="payment.cheque_number">
                <dt>{{ $t('Cheque Number') }}</dt>
                <dd>{{ payment.cheque_number }}</dd>
              </div>
              <div>
                <dt>{{ $t('Reference Number') }}</dt>
                <dd>{{ payment.reference_number || '-' }}</dd>
              </div>
              <div>
                <dt>{{ $t('Paid On') }}</dt>
                <dd>{{ $date(payment.paid_on) }}</dd>
              </div>
            </dl>
            <div v-if="payment.remarks" class="sd-remarks">
              <span class="sd-remarks-label">{{ $t('Remarks') }}</span>
              {{ payment.remarks }}
            </div>
          </section>

          <!-- Invoices covered -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-amber"><icons name="doc-text" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1">
                <h2 class="lg-card-title">{{ $t('Invoices Covered By This Payment') }}</h2>
              </div>
              <span class="lg-chip lg-chip-slate">{{ payment.applied_invoices.length }}</span>
            </header>
            <ul v-if="payment.applied_invoices.length" class="sd-rows">
              <li v-for="inv in payment.applied_invoices" :key="inv.id">
                <Link class="sd-row" :href="route('supplier-records.supplier-invoices.show', inv.id)">
                  <span class="sd-row-main">{{ inv.reference }}</span>
                  <span class="sd-row-amt">{{ $money(inv.applied_amount, payment.currency) }}</span>
                </Link>
              </li>
            </ul>
            <p v-else class="sd-none">{{ $t('This payment is not linked to a specific invoice.') }}</p>
          </section>
        </div>

        <div class="sd-col">
          <!-- Attachments -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-slate"><icons name="clip" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1">
                <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
              </div>
              <span class="lg-chip lg-chip-slate">{{ attachmentCount }}</span>
            </header>
            <ul v-if="attachmentCount" class="sd-files">
              <li v-for="file in payment.attachments" :key="file.id">
                <span class="sd-file-name">{{ file.name }}</span>
                <a :href="route('supplier-records.payments.attachments.download', [payment.id, file.id])" class="sd-dl">
                  <icons name="download" class="w-4 h-4" />{{ $t('Download') }}
                </a>
              </li>
            </ul>
            <p v-else class="sd-none">{{ $t('No attachments.') }}</p>
          </section>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, PageHeader, StatTiles },

  props: {
    payment: Object,
  },

  data() {
    return {
      verifyForm: this.$inertia.form({}),
    };
  },

  computed: {
    isVerified() {
      return this.payment.verification_status === 'Verified';
    },
    attachmentCount() {
      return (this.payment.attachments || []).length;
    },
    statTiles() {
      const p = this.payment;
      return [
        { icon: 'cash', tone: 'emerald', label: this.$t('Amount Paid'), value: this.$money(p.amount, p.currency), valueClass: 'is-good' },
        { icon: 'key', tone: 'indigo', label: this.$t('Method'), value: p.method || '-' },
        { icon: 'calendar', tone: 'sky', label: this.$t('Paid On'), value: this.$date(p.paid_on) },
        { icon: 'hash', tone: 'slate', label: this.$t('Reference Number'), value: p.reference_number || '-' },
      ];
    },
  },

  methods: {
    async verify() {
      if (!await this.$confirm(this.$t('Confirm this payment is correct and mark it Verified? This should only be done by a director after checking the payment.'))) {
        return;
      }
      this.verifyForm.patch(route('supplier-records.payments.verify', this.payment.id));
    },
  },
};
</script>

<style scoped>
.sd-status { --sc: #475569; --sb: rgba(100, 116, 139, 0.14); display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px 8px 12px; border-radius: 9999px; font-size: 0.82rem; font-weight: 800; letter-spacing: 0.02em; color: var(--sc); background: var(--sb); border: 1px solid color-mix(in srgb, var(--sc) 30%, transparent); box-shadow: 0 10px 18px -12px var(--sc); }
.sd-status.is-amber { --sc: #b45309; --sb: rgba(245, 158, 11, 0.16); }
.sd-status.is-green { --sc: #047857; --sb: rgba(16, 185, 129, 0.16); }
html.dark .sd-status.is-amber { --sc: #fcd34d; }
html.dark .sd-status.is-green { --sc: #6ee7b7; }
.sd-dot { position: relative; width: 9px; height: 9px; border-radius: 9999px; background: var(--sc); flex: none; }
.sd-dot.is-live::after { content: ''; position: absolute; inset: -4px; border-radius: 9999px; border: 2px solid var(--sc); opacity: 0; animation: sd-live 1.8s ease-out infinite; }
@keyframes sd-live { 0% { transform: scale(0.6); opacity: 0.8; } 100% { transform: scale(1.6); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .sd-dot.is-live::after { animation: none; } }

.sd-good { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 1rem; padding: 0.65rem 0.85rem; border-radius: 14px; font-size: 0.82rem; font-weight: 700; color: #065f46; background: rgba(209, 250, 229, 0.7); border: 1px solid rgba(16, 185, 129, 0.35); }
.sd-good svg, .sd-good > div { flex: none; margin-top: 2px; }
html.dark .sd-good { color: #6ee7b7; background: rgba(16, 185, 129, 0.1); }

.sd-grid { display: grid; gap: 0 1rem; grid-template-columns: minmax(0, 1fr); align-items: start; }
@media (min-width: 1024px) { .sd-grid { grid-template-columns: minmax(0, 1fr) 22rem; } }
.sd-col { min-width: 0; }

.sd-kv { display: grid; gap: 14px 22px; grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr)); margin: 0; }
.sd-kv > div { min-width: 0; }
.sd-kv dt { margin-bottom: 3px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.sd-kv dd { font-size: 0.9rem; font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }
.sd-link { font-weight: 800; color: var(--lg-navy); }
.sd-link:hover { text-decoration: underline; }
html.dark .sd-link { color: #93c5fd; }
.sd-remarks { margin-top: 16px; padding: 0.75rem 1rem; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); font-size: 0.85rem; color: var(--lg-ink); white-space: pre-wrap; }
.sd-remarks-label { display: block; margin-bottom: 2px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.sd-none { font-size: 0.84rem; color: var(--lg-faint); }

.sd-rows { list-style: none; margin: 0; padding: 0; border: 1px solid var(--lg-line); border-radius: 14px; overflow: hidden; }
.sd-rows li { border-bottom: 1px solid var(--lg-line); }
.sd-rows li:last-child { border-bottom: 0; }
.sd-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 14px; color: var(--lg-ink); transition: background 0.15s ease; }
.sd-row:hover { background: var(--lg-soft); }
.sd-row-main { font-size: 0.86rem; font-weight: 800; }
.sd-row-amt { font-size: 0.86rem; font-weight: 800; font-variant-numeric: tabular-nums; }

.sd-files { list-style: none; margin: 0; padding: 0; border: 1px solid var(--lg-line); border-radius: 14px; overflow: hidden; }
.sd-files li { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 12px; border-bottom: 1px solid var(--lg-line); font-size: 0.82rem; color: var(--lg-ink); }
.sd-files li:last-child { border-bottom: 0; }
.sd-file-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sd-dl { display: inline-flex; flex: none; align-items: center; gap: 5px; font-weight: 800; color: var(--lg-navy); }
.sd-dl:hover { text-decoration: underline; }
html.dark .sd-dl { color: #93c5fd; }
</style>

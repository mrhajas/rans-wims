<template>
  <admin-layout :title="supplier.name">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="users"
        :title="supplier.name"
        :subtitle="$t('Sourced from Contacts') + ' · ' + (supplier.email || supplier.phone || '-')"
      >
        <Link :href="route('contacts.index', { category: 'Suppliers' })" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Suppliers') }}
        </Link>
        <Link :href="route('supplier-records.suppliers.ledger.show', supplier.id)" class="lg-btn lg-btn-primary">
          <icons name="doc-text" class="w-4 h-4" />{{ $t('Ledger') }}
        </Link>
        <Link :href="route('supplier-records.suppliers.reconciliations.index', supplier.id)" class="lg-btn lg-btn-soft">
          <icons name="list" class="w-4 h-4" />{{ $t('Reconciliations') }}
        </Link>
        <Link :href="route('supplier-records.suppliers.statement', supplier.id)" class="lg-btn lg-btn-soft">
          <icons name="doc-text" class="w-4 h-4" />{{ $t('Statement') }}
        </Link>
        <Link :href="route('supplier-records.suppliers.details.edit', supplier.id)" class="lg-btn lg-btn-dark">
          <icons name="edit" class="w-4 h-4" />{{ $t('Edit Financial Details') }}
        </Link>
        <Link :href="route('contacts.edit', supplier.id)" class="lg-btn lg-btn-dark">
          <icons name="user" class="w-4 h-4" />{{ $t('Edit Contact') }}
        </Link>
      </page-header>

      <section class="lg-stats lg-stats-tight">
        <Link :href="route('supplier-records.quotations.index', { supplier_id: supplier.id })" class="lg-stat is-clickable">
          <span class="lg-stat-ico lg-ico-violet"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value">{{ stats.quotations }}</div><div class="lg-stat-label">{{ $t('Quotations') }}</div></div>
        </Link>
        <Link :href="route('supplier-records.purchase-orders.index', { supplier_id: supplier.id })" class="lg-stat is-clickable">
          <span class="lg-stat-ico lg-ico-navy"><icons name="truck" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value">{{ stats.purchase_orders }}</div><div class="lg-stat-label">{{ $t('Purchase Orders') }}</div></div>
        </Link>
        <div class="lg-stat">
          <span class="lg-stat-ico" :class="stats.open_purchase_orders ? 'lg-ico-amber' : 'lg-ico-slate'"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value">{{ stats.open_purchase_orders }}</div><div class="lg-stat-label">{{ $t('Open POs') }}</div></div>
        </div>
        <Link :href="route('supplier-records.supplier-invoices.index', { supplier_id: supplier.id })" class="lg-stat is-clickable">
          <span class="lg-stat-ico" :class="stats.unpaid_invoices ? 'lg-ico-rose' : 'lg-ico-slate'"><icons name="doc" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value">{{ stats.unpaid_invoices }}</div><div class="lg-stat-label">{{ $t('Unpaid Invoices') }}</div></div>
        </Link>
        <Link :href="route('supplier-records.payments.index', { supplier_id: supplier.id })" class="lg-stat is-clickable">
          <span class="lg-stat-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value">{{ $money(stats.total_paid, supplier.currency) }}</div><div class="lg-stat-label">{{ $t('Total Paid') }}</div></div>
        </Link>
        <Link :href="route('supplier-records.suppliers.reconciliations.index', supplier.id)" class="lg-stat is-clickable">
          <span class="lg-stat-ico" :class="stats.open_reconciliations ? 'lg-ico-amber' : 'lg-ico-slate'"><icons name="list" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value">{{ stats.open_reconciliations }}</div><div class="lg-stat-label">{{ $t('Open Reconciliations') }}</div></div>
        </Link>
      </section>

      <div class="sp-grid">
        <section class="lg-card sp-grid-main">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="building" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Company Details') }}</h2></div>
          </header>
          <dl class="sp-dl">
            <div class="sp-dl-item"><dt>{{ $t('Email') }}</dt><dd>{{ supplier.email || '-' }}</dd></div>
            <div class="sp-dl-item"><dt>{{ $t('Phone') }}</dt><dd>{{ supplier.phone || '-' }}</dd></div>
            <div v-if="supplier.details" class="sp-dl-item sp-dl-wide"><dt>{{ $t('Notes') }}</dt><dd>{{ supplier.details }}</dd></div>
          </dl>

          <div class="sp-divider">
            <div class="sp-sub-head">
              <h3 class="sp-sub-title">{{ $t('Financial Details') }}</h3>
              <Link :href="route('supplier-records.suppliers.details.edit', supplier.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Edit Financial Details')" :aria-label="$t('Edit Financial Details')">
                <icons name="edit" class="w-4 h-4" />
              </Link>
            </div>
            <dl class="sp-dl">
              <div class="sp-dl-item"><dt>{{ $t('Tax ID') }}</dt><dd>{{ supplier.tax_id || '-' }}</dd></div>
              <div class="sp-dl-item"><dt>{{ $t('Payment Terms') }}</dt><dd>{{ supplier.payment_terms_days }} {{ $t('days') }}</dd></div>
              <div class="sp-dl-item"><dt>{{ $t('Currency') }}</dt><dd>{{ supplier.currency }}</dd></div>
              <div class="sp-dl-item"><dt>{{ $t('Credit Limit') }}</dt><dd class="sp-num">{{ $money(supplier.credit_limit, supplier.currency) }}</dd></div>
              <div class="sp-dl-item sp-dl-wide"><dt>{{ $t('Bank') }}</dt><dd>{{ supplier.bank_name || '-' }} / {{ supplier.bank_account || '-' }}</dd></div>
            </dl>
          </div>
        </section>

        <section class="lg-card sp-balance-card" :class="supplier.balance > 0 ? 'is-owed' : 'is-clear'">
          <span class="lg-card-ico" :class="supplier.balance > 0 ? 'lg-ico-amber' : 'lg-ico-emerald'"><icons name="cash" class="w-5 h-5" /></span>
          <div class="sp-balance-label">{{ $t('Outstanding Balance') }}</div>
          <div class="sp-balance-value">{{ $money(supplier.balance, supplier.currency) }}</div>
          <div class="sp-balance-foot">{{ $t('As of') }} {{ $date(supplier.balance_as_of) }}, {{ $t('from the Supplier Ledger') }}</div>
          <Link :href="route('supplier-records.suppliers.ledger.show', supplier.id)" class="lg-btn lg-btn-soft sp-balance-btn">
            <icons name="doc-text" class="w-4 h-4" />{{ $t('Ledger') }}
          </Link>
        </section>
      </div>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Transaction Timeline') }}</h2></div>
        </header>
        <ol class="sp-timeline">
          <li v-for="entry in timeline" :key="entry.type + entry.id" class="sp-tl-item">
            <span class="sp-dot" :class="timelineDotClass(entry.type)"></span>
            <Link :href="route(entry.route, entry.id)" class="sp-tl-row">
              <div class="min-w-0">
                <span class="sp-tl-date">{{ $date(entry.date) }}</span>
                <div class="sp-tl-title">{{ entry.type }} / {{ entry.label }}</div>
              </div>
              <div class="sp-tl-end">
                <div v-if="entry.amount !== null" class="sp-tl-amount">{{ $money(entry.amount, supplier.currency) }}</div>
                <span class="lg-chip" :class="statusBadgeClass(entry.status)" style="text-transform: none">{{ entry.status }}</span>
              </div>
            </Link>
          </li>
          <li v-if="!timeline.length" class="lg-empty sp-tl-empty">
            <icons name="clock" class="w-8 h-8 mx-auto mb-2 opacity-60" />
            {{ $t('No transactions yet.') }}
          </li>
        </ol>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, PageHeader },

  props: {
    supplier: Object,
    stats: Object,
    timeline: Array,
  },

  methods: {
    timelineDotClass(type) {
      return {
        Quotation: 'sp-dot-violet', 'Purchase Order': 'sp-dot-blue', 'Goods Receipt': 'sp-dot-cyan',
        'Supplier Invoice': 'sp-dot-amber', 'Payment Request': 'sp-dot-orange', Payment: 'sp-dot-green',
      }[type] || 'sp-dot-slate';
    },
    statusBadgeClass(status) {
      const map = {
        Draft: 'lg-chip-slate', Submitted: 'lg-chip-amber', Approved: 'lg-chip-blue',
        'Sent to Supplier': 'lg-chip-blue', Acknowledged: 'lg-chip-blue', 'Partially Delivered': 'lg-chip-amber',
        'Pending Match': 'lg-chip-amber', Matched: 'lg-chip-blue', 'Partially Paid': 'lg-chip-amber',
        Recorded: 'lg-chip-green', Completed: 'lg-chip-green', Paid: 'lg-chip-green',
        Rejected: 'lg-chip-red', Cancelled: 'lg-chip-red', Disputed: 'lg-chip-red',
      };
      return map[status] || 'lg-chip-slate';
    },
  },
};
</script>

<style scoped>
a.lg-stat { text-decoration: none; }
.sp-grid { display: grid; grid-template-columns: 1fr; gap: 1rem; margin-bottom: 1rem; }
@media (min-width: 1024px) { .sp-grid { grid-template-columns: 2fr 1fr; } }
.sp-grid > .lg-card { margin-bottom: 0; }

.sp-dl { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); gap: 12px; }
.sp-dl-item { min-width: 0; padding: 10px 14px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.sp-dl-wide { grid-column: 1 / -1; }
.sp-dl dt { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.sp-dl dd { margin-top: 2px; font-size: 0.88rem; font-weight: 600; color: var(--lg-ink); overflow-wrap: anywhere; }
.sp-num { font-variant-numeric: tabular-nums; }
.sp-divider { margin-top: 1.1rem; padding-top: 1.1rem; border-top: 1px solid var(--lg-line); }
.sp-sub-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.75rem; }
.sp-sub-title { font-size: 0.72rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-mute); }

.sp-balance-card { display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 4px; }
.sp-balance-card.is-owed { background: linear-gradient(160deg, rgba(245, 158, 11, 0.1), var(--lg-card) 60%); }
.sp-balance-card.is-clear { background: linear-gradient(160deg, rgba(16, 185, 129, 0.1), var(--lg-card) 60%); }
.sp-balance-card .lg-card-ico { width: 48px; height: 48px; border-radius: 16px; margin-bottom: 6px; }
.sp-balance-label { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.sp-balance-value { font-size: 1.9rem; font-weight: 800; line-height: 1.15; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.sp-balance-card.is-owed .sp-balance-value { color: #b45309; }
.sp-balance-card.is-clear .sp-balance-value { color: #047857; }
html.dark .sp-balance-card.is-owed .sp-balance-value { color: #fbbf24; }
html.dark .sp-balance-card.is-clear .sp-balance-value { color: #6ee7b7; }
.sp-balance-foot { font-size: 0.74rem; color: var(--lg-mute); }
.sp-balance-btn { margin-top: 10px; }

.sp-timeline { position: relative; margin-left: 8px; padding-left: 0; list-style: none; border-left: 2px solid var(--lg-line); }
.sp-tl-item { position: relative; margin-left: 22px; padding-bottom: 10px; }
.sp-tl-item:last-child { padding-bottom: 0; }
.sp-dot { position: absolute; left: -31px; top: 18px; width: 12px; height: 12px; border-radius: 9999px; box-shadow: 0 0 0 4px var(--lg-card); }
.sp-dot-violet { background: #8b5cf6; }
.sp-dot-blue { background: #3b82f6; }
.sp-dot-cyan { background: #06b6d4; }
.sp-dot-amber { background: #f59e0b; }
.sp-dot-orange { background: #f97316; }
.sp-dot-green { background: #10b981; }
.sp-dot-slate { background: #94a3b8; }
.sp-tl-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 14px; border-radius: 14px; border: 1px solid transparent; transition: background 0.15s ease, border-color 0.15s ease; }
.sp-tl-row:hover { background: var(--lg-soft); border-color: var(--lg-line); }
.sp-tl-date { font-size: 0.72rem; color: var(--lg-faint); }
.sp-tl-title { font-size: 0.86rem; font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }
.sp-tl-end { flex: none; display: flex; flex-direction: column; align-items: flex-end; gap: 3px; }
.sp-tl-amount { font-size: 0.86rem; font-weight: 800; color: var(--lg-ink); font-variant-numeric: tabular-nums; }
.sp-tl-empty { list-style: none; margin-left: -2px; }
@media (max-width: 479.98px) {
  .sp-tl-row { flex-direction: column; align-items: flex-start; }
  .sp-tl-end { align-items: flex-start; }
}
</style>

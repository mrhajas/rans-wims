<template>
  <admin-layout :title="$t('Supplier Records')">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="cart"
        :title="$t('Supplier Records')"
        :subtitle="$t('Suppliers, purchase orders, deliveries, invoices, and payments in one place.')"
      >
        <Link :href="route('supplier-records.purchase-requests.create')" class="lg-btn lg-btn-dark">
          <icons name="doc-text" class="w-4 h-4" />{{ $t('New Purchase Request') }}
        </Link>
        <Link :href="route('supplier-records.purchase-orders.create')" class="lg-btn lg-btn-primary">
          <icons name="truck" class="w-4 h-4" />{{ $t('New Purchase Order') }}
        </Link>
        <Link :href="route('contacts.create')" class="lg-btn lg-btn-green">
          <icons name="plus" class="w-4 h-4" />{{ $t('New Supplier') }}
        </Link>
      </page-header>

      <button type="button" class="spr-owe-banner text-left w-full" @click="goto('supplier-records.explorer')">
        <span class="spr-owe-icon flex items-center justify-center h-12 w-12 rounded-2xl flex-none relative z-[1]">
          <icons name="cash" class="w-6 h-6 text-white spr-owe-icon-glyph" />
        </span>
        <div class="flex-1 min-w-0 relative z-[1]">
          <div class="spr-owe-label">{{ $t('Suppliers Total Balance') }}</div>
          <div class="spr-owe-value">{{ $money(cards.suppliers_total_balance) }}</div>
        </div>
        <icons name="chevron-down" class="w-5 h-5 -rotate-90 flex-none spr-owe-chevron relative z-[1]" />
      </button>

      <stat-tiles :items="statTiles" @select="openTile" />

      <div class="spr-two">
        <section class="lg-card spr-col">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="users" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Top Suppliers by Spend') }}</h2>
            </div>
          </header>
          <ol class="spr-list">
            <li v-for="(row, i) in spendBySupplier" :key="row.supplier_id" class="spr-li">
              <span class="spr-rank">{{ i + 1 }}</span>
              <Link :href="route('supplier-records.suppliers.show', row.supplier_id)" class="spr-link min-w-0 flex-1 truncate">{{ row.supplier_name }}</Link>
              <span class="spr-amt">{{ $money(row.total) }}</span>
            </li>
            <li v-if="!spendBySupplier.length" class="spr-li spr-none">{{ $t('No invoices yet.') }}</li>
          </ol>
        </section>
        <section class="lg-card spr-col">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="collection" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Recent Activity') }}</h2>
            </div>
          </header>
          <ol class="spr-list">
            <li v-for="row in recentActivity" :key="row.type + row.id" class="spr-li">
              <div class="min-w-0 flex-1">
                <Link :href="route(row.url_name, row.id)" class="spr-link truncate block">{{ row.type }} / {{ row.reference }}</Link>
                <span class="lg-subtext">{{ $datetime(row.date) }}</span>
              </div>
              <span class="lg-chip shrink-0" :class="statusChip(row.status)" style="text-transform: none">{{ row.status }}</span>
            </li>
            <li v-if="!recentActivity.length" class="spr-li spr-none">{{ $t('No activity yet.') }}</li>
          </ol>
        </section>
      </div>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="truck" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Open Purchase Orders') }}</h2>
          </div>
          <span class="lg-chip lg-chip-amber" style="text-transform: none">{{ openPurchaseOrders.length }}</span>
        </header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('Supplier') }}</th>
                <th>{{ $t('Project') }}</th>
                <th class="num">{{ $t('Total') }}</th>
                <th>{{ $t('Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="po in openPurchaseOrders" :key="po.id" class="is-click" @click="goto('supplier-records.purchase-orders.show', po.id)">
                <td :data-label="$t('Reference')"><div class="lg-main">{{ po.reference }}</div></td>
                <td :data-label="$t('Supplier')">{{ supplierName(po.supplier_id) }}</td>
                <td :data-label="$t('Project')" class="spr-muted">{{ po.warehouse_name }}</td>
                <td :data-label="$t('Total')" class="num">{{ $money(po.total) }}</td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(po.status)" style="text-transform: none">{{ po.status }}</span></td>
              </tr>
              <tr v-if="!openPurchaseOrders.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('No open purchase orders.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>
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
    cards: Object,
    spendBySupplier: Array,
    recentActivity: Array,
    openPurchaseOrders: Array,
  },

  computed: {
    statTiles() {
      const c = this.cards || {};

      return [
        { icon: 'users', tone: 'slate', label: this.$t('Active Suppliers'), value: c.active_suppliers, active: false, route: 'contacts.index', query: { category: 'Suppliers' } },
        { icon: 'truck', tone: 'navy', label: this.$t('Open Purchase Orders'), value: c.open_purchase_orders, active: false, route: 'supplier-records.purchase-orders.index' },
        { icon: 'chart-pie', tone: 'amber', label: this.$t('Outstanding Payable'), value: this.$money(c.outstanding_payable), active: false, route: 'supplier-records.supplier-invoices.index' },
        { icon: 'exclamation', tone: 'rose', label: this.$t('Overdue Invoices'), value: c.overdue_invoices, active: false, route: 'supplier-records.supplier-invoices.index' },
        { icon: 'key', tone: 'violet', label: this.$t('Pending Payment Requests'), value: c.pending_payment_requests, active: false, route: 'supplier-records.payment-requests.index' },
        { icon: 'tick', tone: 'emerald', label: this.$t('Paid This Month'), value: this.$money(c.paid_this_month), active: false, route: 'supplier-records.payments.index' },
      ];
    },
  },

  methods: {
    openTile(item) {
      this.goto(item.route, null, item.query);
    },
    statusChip(status) {
      const map = {
        Draft: 'lg-chip-slate',
        'Pending Approval': 'lg-chip-amber',
        Submitted: 'lg-chip-amber',
        Approved: 'lg-chip-blue',
        'Sent to Supplier': 'lg-chip-blue',
        Acknowledged: 'lg-chip-blue',
        'Partially Delivered': 'lg-chip-amber',
        'Pending Match': 'lg-chip-amber',
        Matched: 'lg-chip-blue',
        'Partially Paid': 'lg-chip-amber',
        Recorded: 'lg-chip-green',
        Completed: 'lg-chip-green',
        Paid: 'lg-chip-green',
        Rejected: 'lg-chip-red',
        Cancelled: 'lg-chip-red',
        Disputed: 'lg-chip-red',
      };
      return map[status] || 'lg-chip-slate';
    },
    goto(name, id, query) {
      this.$inertia.visit(id ? route(name, id) : route(name, query || undefined));
    },
    supplierName(id) {
      const row = this.spendBySupplier.find(s => s.supplier_id === id);
      return row ? row.supplier_name : this.openPurchaseOrders.find(p => p.supplier_id === id)?.supplier_name || '-';
    },
  },
};
</script>

<style scoped>
.spr-owe-banner {
  position: relative;
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-bottom: 1rem;
  padding: 1.1rem 1.4rem;
  border-radius: 22px;
  background: linear-gradient(135deg, #fef2f2 0%, #fff7f7 55%, #ffffff 100%);
  box-shadow: 0 1px 2px rgba(127, 29, 29, 0.04), 0 10px 28px -10px rgba(127, 29, 29, 0.2);
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.spr-owe-banner:hover { transform: translateY(-2px); box-shadow: 0 16px 36px -10px rgba(127, 29, 29, 0.3); }
.spr-owe-banner:active { transform: translateY(0) scale(0.99); }
.spr-owe-label { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #7f1d1d; opacity: 0.75; }
.spr-owe-icon {
  background: linear-gradient(145deg, #b91c1c, #7f1d1d);
  box-shadow: 0 6px 16px -4px rgba(127, 29, 29, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.25);
  overflow: hidden;
}
.spr-owe-icon-glyph { animation: spr-cash-bounce 2.6s ease-in-out infinite; transform-origin: 50% 65%; }
@keyframes spr-cash-bounce {
  0%, 100% { transform: translateY(0) scale(1) rotate(0deg); }
  25% { transform: translateY(-2px) scale(1.05) rotate(-6deg); }
  50% { transform: translateY(-3.5px) scale(1.08) rotate(0deg); }
  75% { transform: translateY(-2px) scale(1.05) rotate(6deg); }
}
@media (prefers-reduced-motion: reduce) {
  .spr-owe-icon-glyph { animation: none; }
}
.spr-owe-value { font-size: 1.6rem; font-weight: 800; color: #7f1d1d; font-variant-numeric: tabular-nums; margin-top: 0.15rem; line-height: 1.15; overflow-wrap: anywhere; }
.spr-owe-chevron { color: #b91c1c; transition: transform 0.2s ease; }
.spr-owe-banner:hover .spr-owe-chevron { transform: rotate(-90deg) translateX(3px); }
.spr-owe-banner::before {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: inherit;
  padding: 2px;
  background: linear-gradient(115deg, rgba(148, 163, 184, 0.3) 0%, rgba(226, 232, 240, 0.95) 22%, rgba(96, 165, 250, 0.7) 45%, rgba(226, 232, 240, 0.95) 68%, rgba(148, 163, 184, 0.3) 100%);
  background-size: 260% 260%;
  -webkit-mask: linear-gradient(#000, #000) content-box, linear-gradient(#000, #000);
  -webkit-mask-composite: xor;
  mask-composite: exclude;
  animation: spr-owe-glow-drift 9s ease-in-out infinite;
  pointer-events: none;
}
@keyframes spr-owe-glow-drift {
  0%, 100% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
}
@media (prefers-reduced-motion: reduce) {
  .spr-owe-banner::before { animation: none; background-position: 30% 50%; }
}
html.dark .spr-owe-banner { background: linear-gradient(135deg, rgba(127, 29, 29, 0.18), rgba(15, 23, 42, 0.5)); }
html.dark .spr-owe-value, html.dark .spr-owe-chevron, html.dark .spr-owe-label { color: #fca5a5; }

.spr-two { display: grid; grid-template-columns: minmax(0, 1fr); gap: 0 1rem; }
@media (min-width: 1024px) { .spr-two { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.spr-list { list-style: none; margin: 0; padding: 0; }
.spr-li { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--lg-line); font-size: 0.84rem; color: var(--lg-ink); }
.spr-li:last-child { border-bottom: 0; }
.spr-none { color: var(--lg-faint); justify-content: center; padding: 22px 0; }
.spr-rank { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 24px; height: 24px; border-radius: 9999px; font-size: 0.7rem; font-weight: 800; color: var(--lg-navy); background: var(--lg-soft); border: 1px solid var(--lg-line); }
.spr-link { color: inherit; font-weight: 600; }
.spr-link:hover { color: var(--lg-navy); text-decoration: underline; }
html.dark .spr-link:hover { color: #93c5fd; }
.spr-amt { font-weight: 800; font-variant-numeric: tabular-nums; white-space: nowrap; }
.spr-muted { color: var(--lg-mute); }
</style>

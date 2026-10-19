<template>
  <div class="qc-root">
    <button ref="trigger" type="button" class="qc-btn lg-tip" :class="{ 'is-open': open }" :data-tip="$t('New: create a record in any module')" :aria-expanded="open" aria-haspopup="true" :aria-label="$t('New')" @click="toggle">
      <span class="qc-btn-ring"></span>
      <span class="qc-btn-core"><icons name="plus" class="qc-btn-plus" /></span>
    </button>

    <Teleport to="body">
      <div v-if="open" class="qc-layer" @keydown.esc="close">
        <div class="qc-backdrop" @click="close"></div>
        <div class="qc-panel" role="menu" :style="panelStyle">
          <header class="qc-head">
            <span class="qc-head-ico"><icons name="sparkles" class="w-4 h-4" /></span>
            <div class="qc-head-txt">
              <b>{{ $t('Quick Create') }}</b>
              <small>{{ $t('Start a new record in any module') }}</small>
            </div>
            <label class="qc-search">
              <icons name="search" class="w-3.5 h-3.5" />
              <input ref="search" v-model="query" type="text" :placeholder="$t('Search what to create')" autocomplete="off" @keydown.enter.prevent="openFirst" />
            </label>
            <button type="button" class="qc-x lg-tip" :data-tip="$t('Close')" :aria-label="$t('Close')" @click="close"><icons name="cross" class="w-4 h-4" /></button>
          </header>

          <div class="qc-body">
            <section v-for="g in visibleGroups" :key="g.key" class="qc-group" :class="'is-' + g.tone">
              <h3 class="qc-group-title">
                <span class="qc-group-ico"><icons :name="g.icon" class="w-4 h-4" /></span>
                {{ g.title }}
                <span class="qc-count">{{ g.items.length }}</span>
              </h3>
              <div class="qc-items">
                <Link v-for="it in g.items" :key="it.route" :href="it.href" class="qc-item" role="menuitem" @click="close">
                  <span class="qc-item-ico"><icons :name="it.icon" class="w-4 h-4" /></span>
                  <span class="qc-item-label">{{ it.label }}</span>
                  <icons name="plus" class="qc-item-plus" />
                </Link>
              </div>
            </section>
            <div v-if="!visibleGroups.length" class="qc-empty">
              <icons name="search" class="w-5 h-5" />
              <b>{{ $t('Nothing found') }}</b>
              <small>{{ $t('Try another word, or you may not have permission to create it.') }}</small>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import { SITE_ENGINEER_ROLES } from '@/Core/siteEngineerRoles';

// One group per module (colour + the module's own menu icon); items are only shown when the user may create them
// and the module's feature switch is on.
const GROUPS = [
  {
    key: 'stock', tone: 'blue', icon: 'in', title: 'Stock Transactions',
    items: [
      { label: 'Checkin', icon: 'in', route: 'checkins.create', can: 'create-checkins', feature: 'checkins' },
      { label: 'Checkout', icon: 'out', route: 'checkouts.create', can: 'create-checkouts', feature: 'checkouts' },
      { label: 'Transfer', icon: 'truck', route: 'transfers.create', can: 'create-transfers', feature: 'transfers' },
      { label: 'Adjustment', icon: 'adjustments', route: 'adjustments.create', can: 'create-adjustments', feature: 'adjustments' },
      { label: 'Super Adjustment', icon: 'adjustments', route: 'super-adjustments.create', can: 'create-super-adjustments', feature: 'super_adjustments' },
      { label: 'Issue Material', icon: 'send', route: 'issue-materials.create', fn: 'issue', feature: 'issue_return' },
      { label: 'Material Request', icon: 'doc-text', route: 'material-requests.create', fn: 'request', feature: 'material_requests' },
      { label: 'Material Reallocation', icon: 'toggle', route: 'material-reallocations.create', can: 'create-material-reallocations', feature: 'material_reallocations' },
    ],
  },
  {
    key: 'catalog', tone: 'amber', icon: 'bag', title: 'Catalog and Assets',
    items: [
      { label: 'Item', icon: 'bag', route: 'items.create', can: 'create-items', feature: 'items' },
      { label: 'Category', icon: 'collection', route: 'categories.create', can: 'create-categories', feature: 'categories' },
      { label: 'Unit', icon: 'chip', route: 'units.create', can: 'create-units', feature: 'units' },
      { label: 'Company Asset', icon: 'chip', route: 'assets.create', can: 'create-assets', feature: 'assets' },
    ],
  },
  {
    key: 'supplier', tone: 'cyan', icon: 'cart', title: 'Supplier Records',
    items: [
      { label: 'Purchase Request', icon: 'clip', route: 'supplier-records.purchase-requests.create', can: 'create-purchase-requests', feature: 'supplier_records' },
      { label: 'Quotation', icon: 'doc-text', route: 'supplier-records.quotations.create', can: 'create-quotations', feature: 'supplier_records' },
      { label: 'Purchase Order', icon: 'cart', route: 'supplier-records.purchase-orders.create', can: 'create-purchase-orders', feature: 'supplier_records' },
      { label: 'Goods Receipt', icon: 'in', route: 'supplier-records.goods-receipts.create', can: 'create-goods-receipts', feature: 'supplier_records' },
      { label: 'Supplier Invoice', icon: 'doc', route: 'supplier-records.supplier-invoices.create', can: 'create-supplier-invoices', feature: 'supplier_records' },
      { label: 'Payment Request', icon: 'send', route: 'supplier-records.payment-requests.create', can: 'create-payment-requests', feature: 'supplier_records' },
      { label: 'Supplier Payment', icon: 'cash', route: 'supplier-records.payments.create', can: 'create-payments', feature: 'supplier_records' },
    ],
  },
  {
    key: 'finance', tone: 'rose', icon: 'cash', title: 'Finance and Payroll',
    items: [
      { label: 'Payment Voucher', icon: 'doc-text', route: 'payment-vouchers.create', can: 'create-payment-vouchers', feature: 'payment_vouchers' },
      { label: 'Voucher Batch', icon: 'collection', route: 'payment-vouchers.batches.create', can: 'create-payment-vouchers', feature: 'payment_vouchers' },
      { label: 'Funds Allocation', icon: 'calculator', route: 'fund-allocations.create', can: 'create-fund-allocations', feature: 'fund_allocations' },
      { label: 'Salary Period', icon: 'calendar', route: 'salary.periods.create', can: 'create-salary-periods', feature: 'salary' },
      { label: 'Salary Advance', icon: 'cash', route: 'salary.advances.create', can: 'create-salary-advances', feature: 'salary' },
      { label: 'Salary Loan', icon: 'cash', route: 'salary.loans.create', can: 'create-salary-loans', feature: 'salary' },
      { label: 'Opening Balance', icon: 'calculator', route: 'salary.opening-balances.create', can: 'create-salary-opening-balances', feature: 'salary' },
    ],
  },
  {
    key: 'people', tone: 'emerald', icon: 'users', title: 'People and Organization',
    items: [
      { label: 'Contact', icon: 'group', route: 'contacts.create', can: 'create-contacts', feature: 'contacts' },
      { label: 'Staff', icon: 'users', route: 'staffs.create', can: 'create-staffs', feature: 'staffs' },
      { label: 'Labour List', icon: 'users', route: 'labour.create', can: 'create-labour', feature: 'labour' },
      { label: 'Workforce Agreement', icon: 'clip', route: 'workforce-agreements.create', can: 'create-workforce-agreements', feature: 'labour' },
      { label: 'Warehouse or Project', icon: 'building', route: 'warehouses.create', can: 'create-warehouses', feature: 'warehouses' },
      { label: 'Notice', icon: 'bell', route: 'notices.create', can: 'create-notices', feature: 'notices' },
    ],
  },
  {
    key: 'operations', tone: 'indigo', icon: 'truck', title: 'Operations and Compliance',
    items: [
      { label: 'Transport Record', icon: 'truck', route: 'transport-records.create', can: 'create-transport-records', feature: 'transport_management' },
      { label: 'Transport Vehicle', icon: 'truck', route: 'transport-vehicles.create', can: 'create-transport-vehicles', feature: 'transport_management' },
      { label: 'Transport Location', icon: 'map-pin', route: 'transport-locations.create', can: 'create-transport-locations', feature: 'transport_management' },
      { label: 'Daily Site Report', icon: 'doc', route: 'daily-site-reports.create', can: 'create-daily-site-reports', feature: 'daily_site_reports' },
      { label: 'Site Diary', icon: 'camera', route: 'site-diary.create', can: 'create-site-diary', feature: 'site_diary' },
      { label: 'Site Meeting', icon: 'calendar', route: 'site-meetings.create', can: 'create-site-meetings', feature: 'site_meetings' },
      { label: 'Work Package', icon: 'clip', route: 'work-packages.create', can: 'create-work-packages', feature: 'project_execution' },
      { label: 'Compliance Record', icon: 'clip', route: 'compliance-records.create', can: 'create-compliance-records', feature: 'compliance_records' },
      { label: 'Case', icon: 'exclamation', route: 'case-tracking.create', can: 'create-case-tracking', feature: 'case_tracking' },
    ],
  },
  {
    key: 'admin', tone: 'violet', icon: 'key', title: 'Administration',
    items: [
      { label: 'User', icon: 'users', route: 'users.create', can: 'create-users', feature: 'users' },
      { label: 'Role', icon: 'key', route: 'roles.create', can: 'create-roles', feature: null },
    ],
  },
];

export default {
  components: { Link },
  data() {
    return { open: false, query: '', pos: { top: 60, left: 12, width: 640 } };
  },
  computed: {
    roles() {
      return (this.$page.props.auth?.user?.roles || []).map(r => r.name);
    },
    isSiteEngineer() {
      return this.roles.some(r => SITE_ENGINEER_ROLES.includes(r));
    },
    isStoreManager() {
      return this.roles.includes('Store Manager');
    },
    canRequest() {
      return this.roles.some(r => ['Project Manager', 'Project Managers'].includes(r));
    },
    groups() {
      const features = this.$page.props.enabledFeatures || {};
      const allowed = it => {
        if (it.feature && features[it.feature] === false && !this.$super) return false;
        if (it.fn === 'issue') return !this.isStoreManager && (this.$can('create-issue-returns') || this.$admin || this.isSiteEngineer);
        if (it.fn === 'request') return !this.isStoreManager && this.canRequest;
        return !it.can || this.$can(it.can);
      };
      return GROUPS.map(g => ({
        ...g,
        title: this.$t(g.title),
        items: g.items.filter(allowed).map(it => ({ ...it, label: this.$t(it.label), href: this.route(it.route) })),
      })).filter(g => g.items.length);
    },
    visibleGroups() {
      const q = this.query.trim().toLowerCase();
      if (!q) return this.groups;
      return this.groups
        .map(g => (g.title.toLowerCase().includes(q) ? g : { ...g, items: g.items.filter(i => i.label.toLowerCase().includes(q)) }))
        .filter(g => g.items.length);
    },
    panelStyle() {
      return { top: this.pos.top + 'px', left: this.pos.left + 'px', width: this.pos.width + 'px' };
    },
  },
  methods: {
    place() {
      const r = this.$refs.trigger.getBoundingClientRect();
      const vw = window.innerWidth;
      const width = Math.min(680, vw - 16);
      let left = r.left;
      if (left + width > vw - 8) left = vw - width - 8;
      this.pos = { top: Math.round(r.bottom + 8), left: Math.max(8, Math.round(left)), width };
    },
    toggle() {
      this.open ? this.close() : this.show();
    },
    show() {
      this.place();
      this.query = '';
      this.open = true;
      this.$nextTick(() => this.$refs.search && this.$refs.search.focus());
      window.addEventListener('resize', this.place);
      document.addEventListener('keydown', this.onKey);
    },
    close() {
      this.open = false;
      window.removeEventListener('resize', this.place);
      document.removeEventListener('keydown', this.onKey);
    },
    onKey(e) {
      if (e.key === 'Escape') this.close();
    },
    openFirst() {
      const g = this.visibleGroups[0];
      if (g && g.items[0]) {
        this.close();
        this.$inertia.visit(g.items[0].href);
      }
    },
  },
  beforeUnmount() {
    window.removeEventListener('resize', this.place);
    document.removeEventListener('keydown', this.onKey);
  },
};
</script>

<style scoped>

.qc-root { display: inline-flex; align-items: center; vertical-align: middle; }
.qc-btn {
  position: relative; display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; padding: 0; border: 0;
  border-radius: 12px; cursor: pointer; flex: none; color: #fff; background: #3b82f6; box-shadow: none;
  transition: background 0.15s ease, transform 0.15s ease;
}
.qc-btn:hover, .qc-btn.is-open { background: #2563eb; }
.qc-btn:active { transform: scale(0.96); }
.qc-btn:focus-visible { outline: 2px solid rgba(37, 99, 235, 0.7); outline-offset: 2px; }
.qc-btn-ring, .qc-btn-core::before { display: none; }
.qc-btn-core { display: inline-flex; align-items: center; justify-content: center; background: none; border: 0; box-shadow: none; position: static; }
.qc-btn-plus { width: 20px; height: 20px; transition: transform 0.2s ease; }
.qc-btn.is-open .qc-btn-plus { transform: rotate(45deg); }
html.dark .qc-btn { background: #2f6ee0; }
.qc-layer { position: fixed; inset: 0; z-index: 100000; }
.qc-backdrop { position: absolute; inset: 0; background: linear-gradient(160deg, rgba(99, 102, 241, 0.18), rgba(236, 72, 153, 0.1) 60%, rgba(14, 165, 233, 0.14)); -webkit-backdrop-filter: blur(5px); backdrop-filter: blur(5px); }
.qc-panel {
  position: absolute; max-height: min(78vh, 640px); display: flex; flex-direction: column; overflow: hidden;
  border-radius: 26px;
  background: linear-gradient(150deg, rgba(255, 255, 255, 0.7), rgba(224, 231, 255, 0.55) 55%, rgba(251, 207, 232, 0.4));
  -webkit-backdrop-filter: blur(26px) saturate(190%); backdrop-filter: blur(26px) saturate(190%);
  border: 1.5px solid rgba(255, 255, 255, 0.85);
  box-shadow: 0 34px 70px -22px rgba(49, 46, 129, 0.5), inset 0 1.5px 1px rgba(255, 255, 255, 0.95), inset 0 -14px 30px rgba(129, 140, 248, 0.1);
  animation: qc-in 0.16s ease-out;
}
@keyframes qc-in { from { opacity: 0; transform: translateY(-6px) scale(0.985); } to { opacity: 1; transform: none; } }
.qc-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 12px 14px; border-bottom: 1px solid rgba(255, 255, 255, 0.7); background: rgba(255, 255, 255, 0.28); }
.qc-head-ico { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 12px; color: #fff; background: linear-gradient(145deg, #3b82f6, #1c3f66); box-shadow: 0 10px 16px -8px #1c3f66; }
.qc-head-txt { display: flex; flex-direction: column; min-width: 0; line-height: 1.2; }
.qc-head-txt b { font-size: 0.92rem; color: #0f172a; }
.qc-head-txt small { font-size: 0.7rem; color: #64748b; }
.qc-search { display: inline-flex; align-items: center; gap: 8px; flex: 1 1 200px; min-width: 150px; margin-left: auto; padding: 0 12px; height: 36px; border-radius: 12px; background: rgba(255, 255, 255, 0.55); border: 1px solid rgba(255, 255, 255, 0.9); color: #64748b; box-shadow: inset 0 1px 1px #fff, 0 6px 12px -9px rgba(15, 37, 64, 0.5); }
.qc-search:focus-within { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18); }
.qc-search input { flex: 1; min-width: 0; border: 0 !important; outline: none !important; box-shadow: none !important; background: transparent; font-size: 0.82rem; color: #0f172a; padding: 0 !important; }
.qc-x { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; padding: 0; border: 0; border-radius: 10px; background: rgba(255, 255, 255, 0.55); border: 1px solid rgba(255, 255, 255, 0.9); color: #475569; cursor: pointer; box-shadow: inset 0 1px 1px #fff; }
.qc-x:hover { background: #fee2e2; color: #b91c1c; }
.qc-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; overscroll-behavior: contain; padding: 12px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; align-content: start; }
.qc-group { --c: #2563eb; --c1: #60a5fa; --c2: #1d4ed8; --t: rgba(59, 130, 246, 0.08); border: 1px solid color-mix(in srgb, var(--c) 22%, transparent); border-radius: 18px; padding: 8px; background: linear-gradient(160deg, color-mix(in srgb, var(--c1) 22%, rgba(255, 255, 255, 0.5)), rgba(255, 255, 255, 0.28) 75%); box-shadow: inset 0 1px 1px rgba(255, 255, 255, 0.9); -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px); }
.qc-group.is-amber { --c: #b45309; --c1: #fbbf24; --c2: #b45309; --t: rgba(245, 158, 11, 0.1); }
.qc-group.is-cyan { --c: #0e7490; --c1: #22d3ee; --c2: #0e7490; --t: rgba(6, 182, 212, 0.1); }
.qc-group.is-rose { --c: #be123c; --c1: #fb7185; --c2: #be123c; --t: rgba(244, 63, 94, 0.08); }
.qc-group.is-emerald { --c: #047857; --c1: #34d399; --c2: #047857; --t: rgba(16, 185, 129, 0.1); }
.qc-group.is-indigo { --c: #4338ca; --c1: #818cf8; --c2: #4338ca; --t: rgba(99, 102, 241, 0.1); }
.qc-group.is-violet { --c: #6d28d9; --c1: #a78bfa; --c2: #6d28d9; --t: rgba(139, 92, 246, 0.1); }
.qc-group-title { display: flex; align-items: center; gap: 8px; margin: 0 0 6px; padding: 2px 4px; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--c); }
.qc-group-ico { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 9px; color: #fff; background: linear-gradient(145deg, var(--c1), var(--c2)); box-shadow: 0 8px 12px -6px var(--c2); }
.qc-count { margin-left: auto; padding: 0 7px; border-radius: 9999px; font-size: 0.66rem; background: color-mix(in srgb, var(--c) 14%, transparent); }
.qc-items { display: flex; flex-direction: column; gap: 3px; }
.qc-item { display: flex; align-items: center; gap: 10px; padding: 6px 8px; border-radius: 12px; border: 1px solid transparent; color: #1e293b; font-size: 0.84rem; font-weight: 600; text-decoration: none; transition: background 0.12s ease, border-color 0.12s ease, transform 0.12s ease; }
.qc-item:hover, .qc-item:focus-visible { outline: none; background: rgba(255, 255, 255, 0.85); border-color: color-mix(in srgb, var(--c) 40%, transparent); box-shadow: 0 8px 14px -10px var(--c2); transform: translateX(2px); }
.qc-item-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 28px; height: 28px; border-radius: 9px; color: var(--c); background: transparent; border: 1.5px solid color-mix(in srgb, var(--c) 55%, transparent); }
.qc-item:hover .qc-item-ico { color: #fff; background: linear-gradient(145deg, var(--c1), var(--c2)); border-color: transparent; }
.qc-item-label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.qc-item-plus { width: 14px; height: 14px; color: var(--c); opacity: 0; transition: opacity 0.12s ease; flex: none; }
.qc-item:hover .qc-item-plus { opacity: 1; }
.qc-empty { grid-column: 1 / -1; display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 28px 12px; color: #64748b; text-align: center; }
.qc-empty b { color: #0f172a; }
.qc-empty small { font-size: 0.75rem; }
@media (max-width: 640px) { .qc-body { grid-template-columns: minmax(0, 1fr); } .qc-search { flex-basis: 100%; margin-left: 0; order: 5; } .qc-panel { max-height: 82vh; } }
html.dark .qc-panel { background: #0f172a; border-color: rgba(255, 255, 255, 0.1); }
html.dark .qc-head { background: linear-gradient(135deg, rgba(30, 58, 138, 0.35), #0f172a 70%); border-color: rgba(255, 255, 255, 0.08); }
html.dark .qc-head-txt b, html.dark .qc-search input, html.dark .qc-empty b { color: #e2e8f0; }
html.dark .qc-search { background: #111c33; border-color: rgba(255, 255, 255, 0.12); }
html.dark .qc-item { color: #e2e8f0; }
html.dark .qc-item:hover { background: #111c33; }
html.dark .qc-x { background: #1e293b; color: #cbd5e1; }
@media (prefers-reduced-motion: reduce) { .qc-panel { animation: none; } }
</style>

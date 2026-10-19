<template>
  <admin-layout :title="invoice.invoice_number">
    <div class="lg-page px-4 md:px-0 sd-show">
      <page-header icon="doc-text" :title="invoice.invoice_number" :subtitle="invoice.supplier_name">
        <span class="sd-status" :class="'is-' + statusTone(invoice.status)">
          <span class="sd-dot" :class="{ 'is-live': ['Pending Match', 'Partially Paid'].includes(invoice.status) }"></span>
          <icons v-if="invoice.status === 'Paid'" name="tick" class="w-4 h-4" />
          {{ invoice.status }}
        </span>
        <Link :href="route('supplier-records.supplier-invoices.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Supplier Invoices') }}
        </Link>
        <a v-if="$can('print-supplier-invoices')" :href="route('supplier-records.supplier-invoices.print', invoice.id)" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </a>
        <a v-if="$can('print-supplier-invoices')" :href="route('supplier-records.supplier-invoices.pdf', invoice.id)" class="lg-btn lg-btn-dark">
          <icons name="download" class="w-4 h-4" />{{ $t('PDF') }}
        </a>
        <Link v-if="$super" :href="route('supplier-records.supplier-invoices.edit', invoice.id)" class="lg-btn lg-btn-primary">
          <icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}
        </Link>
        <Link v-if="invoice.balance > 0" :href="route('supplier-records.payment-requests.create', { invoice_id: invoice.id })" class="lg-btn lg-btn-green">
          <icons name="key" class="w-4 h-4" />{{ $t('Raise Payment Request') }}
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" />

      <!-- Trace -->
      <section v-if="invoice.trace" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="link" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Where This Comes From') }}</h2>
          </div>
        </header>
        <div class="sd-trace">
          <template v-if="invoice.trace.purchase_request">
            <Link :href="route(invoice.trace.purchase_request.route, invoice.trace.purchase_request.id)" class="sd-node">
              <span class="sd-node-kind">{{ $t('Purchase Request') }}</span>
              <span class="sd-node-ref">{{ invoice.trace.purchase_request.reference }}</span>
              <span class="sd-node-by">{{ $t('by') }} {{ invoice.trace.purchase_request.created_by_name || $t('Unknown') }}</span>
            </Link>
            <icons name="chevron-down" class="sd-arrow w-4 h-4 -rotate-90" />
          </template>
          <template v-if="invoice.trace.quotation">
            <Link :href="route(invoice.trace.quotation.route, invoice.trace.quotation.id)" class="sd-node">
              <span class="sd-node-kind">{{ $t('Quotation') }}</span>
              <span class="sd-node-ref">{{ invoice.trace.quotation.reference }}</span>
              <span class="sd-node-by">{{ $t('by') }} {{ invoice.trace.quotation.created_by_name || $t('Unknown') }}</span>
            </Link>
            <icons name="chevron-down" class="sd-arrow w-4 h-4 -rotate-90" />
          </template>
          <Link :href="route(invoice.trace.purchase_order.route, invoice.trace.purchase_order.id)" class="sd-node">
            <span class="sd-node-kind">{{ $t('Purchase Order') }}</span>
            <span class="sd-node-ref">{{ invoice.trace.purchase_order.reference }}</span>
            <span class="sd-node-by">{{ $t('by') }} {{ invoice.trace.purchase_order.created_by_name || $t('Unknown') }}</span>
          </Link>
          <icons name="chevron-down" class="sd-arrow w-4 h-4 -rotate-90" />
          <div class="sd-node is-current">
            <span class="sd-node-kind">{{ $t('Supplier Invoice') }}</span>
            <span class="sd-node-ref">{{ invoice.reference }}</span>
            <span class="sd-node-by">{{ $t('by') }} {{ invoice.created_by_name || $t('Unknown') }}</span>
          </div>
        </div>
      </section>

      <div class="sd-grid">
        <div class="sd-col">
          <!-- Overview -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-pink"><icons name="doc-text" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Overview') }}</h2>
                <p class="lg-card-sub">{{ invoice.reference }}</p>
              </div>
            </header>
            <dl class="sd-kv">
              <div>
                <dt>{{ $t('Supplier Invoice #') }}</dt>
                <dd>{{ invoice.invoice_number }}</dd>
              </div>
              <div>
                <dt>{{ $t('WIMS Reference') }}</dt>
                <dd>{{ invoice.reference }}</dd>
              </div>
              <div>
                <dt>{{ $t('Supplier') }}</dt>
                <dd>
                  <Link class="sd-link" :href="route('supplier-records.suppliers.show', invoice.supplier_id)">{{ invoice.supplier_name }}</Link>
                </dd>
              </div>
              <div>
                <dt>{{ $t('Purchase Order') }}</dt>
                <dd>
                  <Link v-if="invoice.purchase_order" class="sd-link" :href="route('supplier-records.purchase-orders.show', invoice.purchase_order.id)">
                    {{ invoice.purchase_order.reference }}
                  </Link>
                  <span v-else>-</span>
                </dd>
              </div>
              <div>
                <dt>{{ $t('Goods Receipt') }}</dt>
                <dd>
                  <Link v-if="invoice.goods_receipt" class="sd-link" :href="route('supplier-records.goods-receipts.show', invoice.goods_receipt.id)">
                    {{ invoice.goods_receipt.reference }}
                  </Link>
                  <span v-else>{{ $t('Not yet received') }}</span>
                </dd>
              </div>
              <div>
                <dt>{{ $t('Invoice Date') }}</dt>
                <dd>{{ $date(invoice.invoice_date) }}</dd>
              </div>
              <div>
                <dt>{{ $t('Due Date') }}</dt>
                <dd>{{ $date(invoice.due_date) }}</dd>
              </div>
              <div>
                <dt>{{ $t('Entered By') }}</dt>
                <dd>{{ invoice.created_by_name || '-' }}</dd>
              </div>
              <div class="sd-kv-wide">
                <dt>{{ $t('Match Status') }}</dt>
                <dd>
                  <span class="lg-chip" :class="matchStatusClass(invoice.match_status)" style="text-transform: none">{{ invoice.match_status }}</span>
                  <p v-if="matchStatusNote(invoice.match_status)" class="sd-match-note">{{ matchStatusNote(invoice.match_status) }}</p>
                </dd>
              </div>
            </dl>
            <div v-if="invoice.remarks" class="sd-remarks">{{ invoice.remarks }}</div>
          </section>

          <!-- Items -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-indigo"><icons name="bag" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1">
                <h2 class="lg-card-title">{{ $t('Items') }}</h2>
              </div>
              <span class="lg-chip lg-chip-indigo" style="text-transform: none">{{ itemCount }} {{ $t('Items') }}</span>
            </header>
            <div class="lg-table-wrap">
              <table class="lg-table lg-responsive">
                <thead>
                  <tr>
                    <th>{{ $t('Item') }}</th>
                    <th>{{ $t('Unit') }}</th>
                    <th class="num">{{ $t('Quantity') }}</th>
                    <th class="num">{{ $t('Unit Price') }}</th>
                    <th class="num">{{ $t('Line Total') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(line, index) in invoice.items" :key="index">
                    <td :data-label="$t('Item')"><span class="lg-main">{{ line.item_code ? line.item_code + ' - ' : '' }}{{ line.item_name }}</span></td>
                    <td :data-label="$t('Unit')">{{ line.unit_label || '-' }}</td>
                    <td :data-label="$t('Quantity')" class="num">{{ $number(line.quantity) }}</td>
                    <td :data-label="$t('Unit Price')" class="num">{{ $money(line.unit_price, currency) }}</td>
                    <td :data-label="$t('Line Total')" class="num font-semibold">{{ $money(line.quantity * line.unit_price, currency) }}</td>
                  </tr>
                  <tr v-if="!itemCount" class="lg-empty-row">
                    <td class="lg-empty" colspan="5">{{ $t('No items.') }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <dl class="sd-totals">
              <div class="sd-totals-row">
                <dt>{{ $t('Subtotal') }}</dt>
                <dd>{{ $money(invoice.subtotal, currency) }}</dd>
              </div>
              <div class="sd-totals-row">
                <dt>{{ $t('Tax') }}</dt>
                <dd>{{ $money(invoice.tax_amount, currency) }}</dd>
              </div>
              <div class="sd-totals-row sd-totals-grand">
                <dt>{{ $t('Total') }}</dt>
                <dd>{{ $money(invoice.total, currency) }}</dd>
              </div>
            </dl>
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
                    <td :data-label="$t('Amount')" class="num font-semibold">{{ $money(payment.amount, currency) }}</td>
                  </tr>
                  <tr v-if="!payments.length" class="lg-empty-row">
                    <td class="lg-empty" colspan="4">{{ $t('No payments recorded yet.') }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>

        <div class="sd-col">
          <!-- Quick Action -->
          <section v-if="invoice.status === 'Pending Match' || invoice.status === 'Matched'" class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-amber"><icons name="sparkles" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Quick Action') }}</h2>
              </div>
            </header>
            <p class="lg-note lg-note-amber mb-3">
              <icons name="info" class="w-4 h-4" />
              <span>{{ $t('Review the match against the purchase order and delivery, then approve or flag a dispute.') }}</span>
            </p>
            <div class="sd-actions">
              <button type="button" class="lg-btn lg-btn-red" @click="flagDisputed">
                <icons name="cross" class="w-4 h-4" />{{ $t('Flag as Disputed') }}
              </button>
              <button type="button" class="lg-btn lg-btn-green" @click="markApproved">
                <icons name="tick" class="w-4 h-4" />{{ $t('Mark as Approved') }}
              </button>
            </div>
          </section>

          <!-- Balance summary -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-navy"><icons name="cash" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Balance') }}</h2>
              </div>
            </header>
            <dl class="sd-sum">
              <div>
                <dt>{{ $t('Total') }}</dt>
                <dd>{{ $money(invoice.total, currency) }}</dd>
              </div>
              <div>
                <dt>{{ $t('Amount Paid') }}</dt>
                <dd class="is-good">{{ $money(invoice.amount_paid, currency) }}</dd>
              </div>
              <div class="sd-sum-big">
                <dt>{{ $t('Balance') }}</dt>
                <dd :class="invoice.balance > 0 ? 'is-warn' : 'is-good'">{{ $money(invoice.balance, currency) }}</dd>
              </div>
            </dl>
          </section>

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
              <li v-for="file in invoice.attachments" :key="file.id">
                <span class="sd-file-name">{{ file.name }}</span>
                <a :href="route('supplier-records.supplier-invoices.attachments.download', [invoice.id, file.id])" class="sd-dl">
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
    invoice: Object,
    payments: Array,
  },

  computed: {
    currency() {
      return this.invoice.purchase_order ? this.invoice.purchase_order.currency : null;
    },
    itemCount() {
      return (this.invoice.items || []).length;
    },
    attachmentCount() {
      return (this.invoice.attachments || []).length;
    },
    statTiles() {
      return [
        { icon: 'cash', tone: 'navy', label: this.$t('Total'), value: this.$money(this.invoice.total, this.currency) },
        { icon: 'tick', tone: 'emerald', label: this.$t('Amount Paid'), value: this.$money(this.invoice.amount_paid, this.currency) },
        {
          icon: 'clock',
          tone: this.invoice.balance > 0 ? 'amber' : 'emerald',
          label: this.$t('Balance'),
          value: this.$money(this.invoice.balance, this.currency),
          valueClass: this.invoice.balance > 0 ? 'is-bad' : 'is-good',
        },
        { icon: 'calendar', tone: 'sky', label: this.$t('Due Date'), value: this.$date(this.invoice.due_date) },
      ];
    },
  },

  methods: {
    gotoPayment(id) {
      this.$inertia.visit(route('supplier-records.payments.show', id));
    },
    markApproved() {
      this.$inertia.form({ status: 'Approved', remarks: '' }).patch(route('supplier-records.supplier-invoices.update', this.invoice.id));
    },
    flagDisputed() {
      this.$inertia.form({ status: 'Disputed', remarks: '' }).patch(route('supplier-records.supplier-invoices.update', this.invoice.id));
    },
    matchStatusClass(status) {
      const map = {
        Matched: 'lg-chip-green',
        'Quantity Variance': 'lg-chip-amber',
        'Price Variance': 'lg-chip-amber',
        Unmatched: 'lg-chip-red',
      };
      return map[status] || 'lg-chip-slate';
    },
    matchStatusNote(status) {
      const map = {
        Matched: this.$t('Invoiced quantities match what was received against the purchase order.'),
        'Quantity Variance': this.$t('Quantity Variance: invoiced quantity differs from what was received.'),
        'Price Variance': this.$t('Price Variance: invoiced price differs from the purchase order price.'),
        Unmatched: this.$t('Unmatched: no goods receipt found yet for this purchase order.'),
      };
      return map[status] || '';
    },
    statusTone(status) {
      const map = {
        'Pending Match': 'amber',
        Matched: 'blue',
        Approved: 'blue',
        'Partially Paid': 'amber',
        Paid: 'green',
        Disputed: 'red',
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

.sd-kv { display: grid; gap: 14px 22px; grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr)); margin: 0; }
.sd-kv > div { min-width: 0; }
.sd-kv-wide { grid-column: 1 / -1; }
.sd-kv dt { margin-bottom: 3px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.sd-kv dd { font-size: 0.9rem; font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }
.sd-match-note { margin-top: 4px; font-size: 0.76rem; font-weight: 500; color: var(--lg-mute); }
.sd-link { font-weight: 800; color: var(--lg-navy); }
.sd-link:hover { text-decoration: underline; }
html.dark .sd-link { color: #93c5fd; }
.sd-remarks { margin-top: 16px; padding: 0.75rem 1rem; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); font-size: 0.85rem; color: var(--lg-ink); white-space: pre-wrap; }
.sd-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
.sd-none { font-size: 0.84rem; color: var(--lg-faint); }

.sd-totals { width: 100%; max-width: 22rem; margin: 1rem 0 0 auto; padding: 0.75rem 1rem; border-radius: 16px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.sd-totals-row { display: flex; justify-content: space-between; gap: 12px; padding: 4px 0; font-size: 0.85rem; color: var(--lg-mute); }
.sd-totals-row dd { font-weight: 700; color: var(--lg-ink); font-variant-numeric: tabular-nums; text-align: right; }
.sd-totals-grand { margin-top: 4px; padding-top: 8px; border-top: 1px solid var(--lg-line); font-size: 1rem; font-weight: 800; color: var(--lg-ink); }
.sd-totals-grand dd { font-size: 1.05rem; }

.sd-sum { margin: 0; }
.sd-sum > div { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--lg-line); }
.sd-sum > div:last-child { border-bottom: 0; }
.sd-sum dt { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.sd-sum dd { font-size: 0.95rem; font-weight: 800; color: var(--lg-ink); font-variant-numeric: tabular-nums; text-align: right; }
.sd-sum dd.is-good { color: #047857; }
.sd-sum dd.is-warn { color: #b45309; }
.sd-sum-big dd { font-size: 1.3rem; }
html.dark .sd-sum dd.is-good { color: #6ee7b7; }
html.dark .sd-sum dd.is-warn { color: #fcd34d; }

.sd-trace { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.sd-node { display: flex; flex-direction: column; gap: 1px; min-width: 150px; padding: 9px 14px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); font-size: 0.8rem; color: var(--lg-ink); transition: transform 0.15s ease, border-color 0.15s ease; }
a.sd-node:hover { transform: translateY(-1px); border-color: #93c5fd; }
.sd-node.is-current { background: rgba(236, 72, 153, 0.08); border: 1px dashed rgba(236, 72, 153, 0.55); }
.sd-node-kind { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.sd-node-ref { font-weight: 800; }
.sd-node-by { font-size: 0.72rem; color: var(--lg-mute); }
.sd-arrow { color: var(--lg-faint); flex: none; }

.sd-files { list-style: none; margin: 0; padding: 0; border: 1px solid var(--lg-line); border-radius: 14px; overflow: hidden; }
.sd-files li { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 12px; border-bottom: 1px solid var(--lg-line); font-size: 0.82rem; color: var(--lg-ink); }
.sd-files li:last-child { border-bottom: 0; }
.sd-file-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sd-dl { display: inline-flex; flex: none; align-items: center; gap: 5px; font-weight: 800; color: var(--lg-navy); }
.sd-dl:hover { text-decoration: underline; }
html.dark .sd-dl { color: #93c5fd; }
</style>

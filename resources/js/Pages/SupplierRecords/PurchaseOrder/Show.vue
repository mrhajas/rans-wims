<template>
  <admin-layout :title="purchaseOrder.reference">
    <div class="lg-page px-4 md:px-0 sd-show">
      <page-header
        icon="truck"
        :title="purchaseOrder.reference"
        :subtitle="[purchaseOrder.supplier_name, purchaseOrder.warehouse_name || $t('No project')].filter(Boolean).join(' · ')"
      >
        <span class="sd-status" :class="'is-' + statusTone(purchaseOrder.status)">
          <span class="sd-dot" :class="{ 'is-live': isLive(purchaseOrder.status) }"></span>
          {{ purchaseOrder.status }}
        </span>
        <Link :href="route('supplier-records.purchase-orders.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Purchase Orders') }}
        </Link>
        <a v-if="$can('print-purchase-orders')" :href="route('supplier-records.purchase-orders.print', purchaseOrder.id)" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </a>
        <a v-if="$can('print-purchase-orders')" :href="route('supplier-records.purchase-orders.pdf', purchaseOrder.id)" class="lg-btn lg-btn-dark">
          <icons name="download" class="w-4 h-4" />{{ $t('PDF') }}
        </a>
        <Link v-if="canEdit" :href="route('supplier-records.purchase-orders.edit', purchaseOrder.id)" class="lg-btn lg-btn-primary">
          <icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}
        </Link>
        <button type="button" class="lg-btn lg-btn-red" @click="deleteRecord">
          <icons name="trash" class="w-4 h-4" />{{ $t('Delete') }}
        </button>
      </page-header>

      <stat-tiles :items="statTiles" />

      <div class="sd-grid">
        <div class="sd-col">
          <!-- Trace -->
          <section v-if="purchaseOrder.purchase_request_id || purchaseOrder.quotation_id" class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-violet"><icons name="link" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Where This Comes From') }}</h2>
              </div>
            </header>
            <div class="sd-trace">
              <template v-if="purchaseOrder.purchase_request_id">
                <Link :href="route('supplier-records.purchase-requests.show', purchaseOrder.purchase_request_id)" class="sd-node">
                  <span class="sd-node-kind">{{ $t('Purchase Request') }}</span>
                  <span class="sd-node-ref">{{ purchaseOrder.purchase_request_reference }}</span>
                </Link>
                <icons name="chevron-down" class="sd-arrow w-4 h-4 -rotate-90" />
              </template>
              <template v-if="purchaseOrder.quotation_id">
                <Link :href="route('supplier-records.quotations.show', purchaseOrder.quotation_id)" class="sd-node">
                  <span class="sd-node-kind">{{ $t('Quotation') }}</span>
                  <span class="sd-node-ref">{{ purchaseOrder.quotation_reference }}</span>
                </Link>
                <icons name="chevron-down" class="sd-arrow w-4 h-4 -rotate-90" />
              </template>
              <div class="sd-node is-active">
                <span class="sd-node-kind">{{ $t('Purchase Order') }}</span>
                <span class="sd-node-ref">{{ purchaseOrder.reference }}</span>
              </div>
            </div>
          </section>

          <!-- Order Overview -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-emerald"><icons name="truck" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Order Overview') }}</h2>
                <p class="lg-card-sub">{{ purchaseOrder.reference }}</p>
              </div>
            </header>
            <dl class="sd-kv">
              <div>
                <dt>{{ $t('Reference') }}</dt>
                <dd>{{ purchaseOrder.reference }}</dd>
              </div>
              <div>
                <dt>{{ $t('Supplier') }}</dt>
                <dd>
                  <Link :href="route('supplier-records.suppliers.show', purchaseOrder.supplier_id)" class="sd-link">{{ purchaseOrder.supplier_name }}</Link>
                </dd>
              </div>
              <div>
                <dt>{{ $t('Project') }}</dt>
                <dd>
                  {{ purchaseOrder.warehouse_name || '-' }}
                  <span v-if="!purchaseOrder.warehouse_id" class="lg-chip lg-chip-blue ml-1" style="text-transform: none">{{ $t('Company') }}</span>
                </dd>
              </div>
              <div>
                <dt>{{ $t('Date Issued') }}</dt>
                <dd>{{ $date(purchaseOrder.date_issued) }}</dd>
              </div>
              <div>
                <dt>{{ $t('Expected Delivery') }}</dt>
                <dd>{{ purchaseOrder.expected_delivery_date ? $date(purchaseOrder.expected_delivery_date) : '-' }}</dd>
              </div>
              <div>
                <dt>{{ $t('Currency') }}</dt>
                <dd>{{ purchaseOrder.currency }}</dd>
              </div>
              <div>
                <dt>{{ $t('Created By') }}</dt>
                <dd>{{ purchaseOrder.user_name || '-' }}</dd>
              </div>
              <div>
                <dt>{{ $t('Status') }}</dt>
                <dd>
                  <span class="lg-chip" :class="'lg-chip-' + statusTone(purchaseOrder.status)" style="text-transform: none">{{ purchaseOrder.status }}</span>
                </dd>
              </div>
            </dl>
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
                  <tr v-for="(item, index) in purchaseOrder.items" :key="index">
                    <td :data-label="$t('Item')"><span class="lg-main">{{ item.item_code ? item.item_code + ' - ' : '' }}{{ item.item_name }}</span></td>
                    <td :data-label="$t('Unit')">{{ item.unit_label || '-' }}</td>
                    <td :data-label="$t('Quantity')" class="num">{{ $number(item.quantity) }}</td>
                    <td :data-label="$t('Unit Price')" class="num">{{ $money(item.unit_price, purchaseOrder.currency) }}</td>
                    <td :data-label="$t('Line Total')" class="num font-semibold">{{ $money(item.quantity * item.unit_price, purchaseOrder.currency) }}</td>
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
                <dd>{{ $money(purchaseOrder.subtotal, purchaseOrder.currency) }}</dd>
              </div>
              <div v-if="purchaseOrder.tax_included" class="sd-totals-row">
                <dt>{{ $t('Tax') }} ({{ purchaseOrder.tax_percentage }}%)</dt>
                <dd>{{ $money(purchaseOrder.tax_amount, purchaseOrder.currency) }}</dd>
              </div>
              <div v-else class="sd-totals-row">
                <dt>{{ $t('Tax: Not Applied') }}</dt>
                <dd class="sd-dim">-</dd>
              </div>
              <div class="sd-totals-row sd-totals-grand">
                <dt>{{ $t('Total') }}</dt>
                <dd>{{ $money(purchaseOrder.total, purchaseOrder.currency) }}</dd>
              </div>
            </dl>
          </section>

          <!-- Goods Receipts -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-teal"><icons name="download" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1">
                <h2 class="lg-card-title">{{ $t('Goods Receipts') }}</h2>
              </div>
              <span class="lg-chip lg-chip-slate">{{ goodsReceipts.length }}</span>
            </header>
            <ol v-if="goodsReceipts.length" class="sd-rows">
              <li v-for="gr in goodsReceipts" :key="gr.id">
                <a href="#" class="sd-row" @click.prevent="goto('supplier-records.goods-receipts.show', gr.id)">
                  <span class="min-w-0">
                    <span class="sd-row-main">{{ gr.reference }}</span>
                    <span class="sd-row-meta">{{ $date(gr.date_received) }}</span>
                  </span>
                  <span class="lg-chip" :class="'lg-chip-' + statusTone(gr.status)" style="text-transform: none">{{ gr.status }}</span>
                </a>
              </li>
            </ol>
            <p v-else class="sd-none">{{ $t('No goods receipts recorded yet.') }}</p>
          </section>

          <!-- Supplier Invoices -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-amber"><icons name="doc-text" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1">
                <h2 class="lg-card-title">{{ $t('Supplier Invoices') }}</h2>
              </div>
              <span class="lg-chip lg-chip-slate">{{ invoices.length }}</span>
            </header>
            <ol v-if="invoices.length" class="sd-rows">
              <li v-for="inv in invoices" :key="inv.id">
                <a href="#" class="sd-row" @click.prevent="goto('supplier-records.supplier-invoices.show', inv.id)">
                  <span class="min-w-0">
                    <span class="sd-row-main">{{ inv.reference }}</span>
                    <span class="sd-row-meta">{{ inv.invoice_date ? $date(inv.invoice_date) : '' }}</span>
                  </span>
                  <span class="sd-row-end">
                    <span class="sd-row-amt">{{ $money(inv.total, purchaseOrder.currency) }}</span>
                    <span class="lg-chip" :class="'lg-chip-' + statusTone(inv.status)" style="text-transform: none">{{ inv.status }}</span>
                  </span>
                </a>
              </li>
            </ol>
            <p v-else class="sd-none">{{ $t('No supplier invoices recorded yet.') }}</p>
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
              <li v-for="file in purchaseOrder.attachments" :key="file.id">
                <span class="sd-file-name">{{ file.name }}</span>
                <a :href="route('supplier-records.purchase-orders.attachments.download', [purchaseOrder.id, file.id])" class="sd-dl">
                  <icons name="download" class="w-4 h-4" />{{ $t('Download') }}
                </a>
              </li>
            </ul>
            <p v-else class="sd-none">{{ $t('No attachments.') }}</p>
          </section>
        </div>

        <div class="sd-col">
          <!-- Workflow actions -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-navy"><icons name="send" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Workflow') }}</h2>
                <p class="lg-card-sub">{{ $t('Next steps for this order') }}</p>
              </div>
            </header>

            <!-- Submit -->
            <div v-if="canSubmit" class="sd-act">
              <h3 class="sd-act-title">{{ $t('Submit for Approval') }}</h3>
              <button type="button" class="lg-btn lg-btn-primary" :disabled="submitForm.processing" @click="submit">
                <icons :name="submitForm.processing ? 'refresh' : 'plane'" class="w-4 h-4" :class="{ 'animate-spin': submitForm.processing }" />{{ $t('Submit') }}
              </button>
            </div>

            <!-- Review (Approve / Reject) -->
            <div v-if="canApprove || canReject" class="sd-act">
              <h3 class="sd-act-title">{{ $t('Review Submission') }}</h3>
              <textarea-input v-model="reviewForm.approval_remarks" :label="$t('Remarks')" :error="$page.props.errors.approval_remarks" />
              <div class="sd-actions mt-3">
                <button type="button" class="lg-btn lg-btn-red" @click="reject">
                  <icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}
                </button>
                <button type="button" class="lg-btn lg-btn-green" :disabled="reviewForm.processing" @click="approve">
                  <icons :name="reviewForm.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': reviewForm.processing }" />{{ $t('Approve') }}
                </button>
              </div>
            </div>

            <!-- Send to Supplier -->
            <div v-if="canSend" class="sd-act">
              <h3 class="sd-act-title">{{ $t('Send to Supplier') }}</h3>
              <button type="button" class="lg-btn lg-btn-primary" :disabled="sendForm.processing" @click="send">
                <icons :name="sendForm.processing ? 'refresh' : 'plane'" class="w-4 h-4" :class="{ 'animate-spin': sendForm.processing }" />{{ $t('Send to Supplier') }}
              </button>
            </div>

            <!-- Acknowledge -->
            <div v-if="canAcknowledge" class="sd-act">
              <h3 class="sd-act-title">{{ $t('Acknowledge Receipt by Supplier') }}</h3>
              <button type="button" class="lg-btn lg-btn-green" :disabled="acknowledgeForm.processing" @click="acknowledge">
                <icons :name="acknowledgeForm.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': acknowledgeForm.processing }" />{{ $t('Acknowledge') }}
              </button>
            </div>

            <!-- Downstream documents -->
            <div v-if="canRecordGoodsReceipt || canRecordInvoice" class="sd-act">
              <div class="sd-actions sd-actions-start">
                <Link v-if="canRecordGoodsReceipt" :href="route('supplier-records.goods-receipts.create', { purchase_order_id: purchaseOrder.id })" class="lg-btn sd-btn-cyan">
                  <icons name="download" class="w-4 h-4" />{{ $t('Record Goods Receipt') }}
                </Link>
                <Link v-if="canRecordInvoice" :href="route('supplier-records.supplier-invoices.create', { purchase_order_id: purchaseOrder.id })" class="lg-btn lg-btn-dark">
                  <icons name="doc-text" class="w-4 h-4" />{{ $t('Record Supplier Invoice') }}
                </Link>
              </div>
            </div>

            <!-- Cancel -->
            <div v-if="canCancel" class="sd-act">
              <h3 class="sd-act-title">{{ $t('Cancel Order') }}</h3>
              <button type="button" class="lg-btn lg-btn-soft sd-btn-cancel" @click="cancel">
                <icons name="cross" class="w-4 h-4" />{{ $t('Cancel Order') }}
              </button>
            </div>

            <p v-if="!hasActions" class="sd-none">{{ $t('No actions are available right now.') }}</p>
          </section>

          <!-- Chain of Custody -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Chain of Custody') }}</h2>
              </div>
            </header>
            <ul v-if="hasStages" class="sd-timeline">
              <li v-if="purchaseOrder.submitted_on">
                <span class="sd-tl-dot is-amber"></span>
                <div class="sd-tl-title">{{ $t('Submitted') }}</div>
                <div class="sd-tl-meta">{{ $datetime(purchaseOrder.submitted_on) }}</div>
              </li>
              <li v-if="purchaseOrder.approved_on">
                <span class="sd-tl-dot is-blue"></span>
                <div class="sd-tl-title">{{ $t('Approved') }}</div>
                <div class="sd-tl-meta">
                  <span v-if="purchaseOrder.approved_by_user_id">{{ purchaseOrder.approved_by_name || $t('Unknown') }} {{ $t('on') }} </span>{{ $datetime(purchaseOrder.approved_on) }}
                </div>
                <div v-if="purchaseOrder.approval_remarks" class="sd-tl-note">{{ purchaseOrder.approval_remarks }}</div>
              </li>
              <li v-if="purchaseOrder.sent_on">
                <span class="sd-tl-dot is-indigo"></span>
                <div class="sd-tl-title">{{ $t('Sent to Supplier') }}</div>
                <div class="sd-tl-meta">{{ $datetime(purchaseOrder.sent_on) }}</div>
              </li>
              <li v-if="purchaseOrder.acknowledged_on">
                <span class="sd-tl-dot is-green"></span>
                <div class="sd-tl-title">{{ $t('Acknowledged') }}</div>
                <div class="sd-tl-meta">{{ $datetime(purchaseOrder.acknowledged_on) }}</div>
              </li>
              <li v-if="purchaseOrder.cancelled_on">
                <span class="sd-tl-dot is-red"></span>
                <div class="sd-tl-title">{{ $t('Cancelled') }}</div>
                <div class="sd-tl-meta">{{ $datetime(purchaseOrder.cancelled_on) }}</div>
              </li>
              <li v-if="purchaseOrder.status === 'Rejected' && purchaseOrder.approval_remarks">
                <span class="sd-tl-dot is-red"></span>
                <div class="sd-tl-title">{{ $t('Rejected') }}</div>
                <div class="sd-tl-note">{{ purchaseOrder.approval_remarks }}</div>
              </li>
            </ul>
            <p v-else class="sd-none">{{ $t('No stage transitions recorded yet.') }}</p>
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
    purchaseOrder: Object,
    goodsReceipts: Array,
    invoices: Array,
    companyName: String,
  },

  data() {
    return {
      submitForm: this.$inertia.form({}),
      reviewForm: this.$inertia.form({ approval_remarks: '' }),
      sendForm: this.$inertia.form({}),
      acknowledgeForm: this.$inertia.form({}),
      cancelForm: this.$inertia.form({}),
    };
  },

  computed: {
    canEdit() {
      return this.purchaseOrder.status === 'Draft' || this.$super;
    },
    canSubmit() {
      return this.purchaseOrder.status === 'Draft';
    },
    canApprove() {
      return this.purchaseOrder.status === 'Submitted';
    },
    canReject() {
      return this.purchaseOrder.status === 'Submitted';
    },
    canSend() {
      return this.purchaseOrder.status === 'Approved';
    },
    canAcknowledge() {
      return this.purchaseOrder.status === 'Sent to Supplier';
    },
    canCancel() {
      return !['Completed', 'Cancelled', 'Rejected'].includes(this.purchaseOrder.status);
    },
    canRecordGoodsReceipt() {
      return ['Sent to Supplier', 'Acknowledged', 'Partially Delivered'].includes(this.purchaseOrder.status);
    },
    canRecordInvoice() {
      return ['Sent to Supplier', 'Acknowledged', 'Partially Delivered', 'Completed'].includes(this.purchaseOrder.status);
    },
    hasActions() {
      return (
        this.canSubmit ||
        this.canApprove ||
        this.canReject ||
        this.canSend ||
        this.canAcknowledge ||
        this.canCancel ||
        this.canRecordGoodsReceipt ||
        this.canRecordInvoice
      );
    },
    hasStages() {
      const po = this.purchaseOrder;
      return !!(po.submitted_on || po.approved_on || po.sent_on || po.acknowledged_on || po.cancelled_on || (po.status === 'Rejected' && po.approval_remarks));
    },
    itemCount() {
      return (this.purchaseOrder.items || []).length;
    },
    attachmentCount() {
      return (this.purchaseOrder.attachments || []).length;
    },
    statTiles() {
      const po = this.purchaseOrder;
      return [
        { icon: 'cash', tone: 'emerald', label: this.$t('Total'), value: this.$money(po.total, po.currency) },
        { icon: 'bag', tone: 'indigo', label: this.$t('Items'), value: this.itemCount },
        { icon: 'calendar', tone: 'sky', label: this.$t('Expected Delivery'), value: po.expected_delivery_date ? this.$date(po.expected_delivery_date) : '-' },
        { icon: 'download', tone: 'teal', label: this.$t('Goods Receipts'), value: this.goodsReceipts.length },
        { icon: 'doc-text', tone: 'amber', label: this.$t('Supplier Invoices'), value: this.invoices.length },
      ];
    },
  },

  methods: {
    submit() {
      this.submitForm.patch(route('supplier-records.purchase-orders.submit', this.purchaseOrder.id));
    },
    approve() {
      this.reviewForm.patch(route('supplier-records.purchase-orders.approve', this.purchaseOrder.id));
    },
    async reject() {
      if (!this.reviewForm.approval_remarks || !this.reviewForm.approval_remarks.trim()) {
        await this.$alert(this.$t('Please enter remarks explaining the reason before rejecting.'));
        return;
      }
      this.reviewForm.patch(route('supplier-records.purchase-orders.reject', this.purchaseOrder.id));
    },
    send() {
      this.sendForm.patch(route('supplier-records.purchase-orders.send', this.purchaseOrder.id));
    },
    acknowledge() {
      this.acknowledgeForm.patch(route('supplier-records.purchase-orders.acknowledge', this.purchaseOrder.id));
    },
    async cancel() {
      if (!await this.$confirm(this.$t('Are you sure you want to cancel this purchase order?'), { variant: 'danger' })) {
        return;
      }
      this.cancelForm.patch(route('supplier-records.purchase-orders.cancel', this.purchaseOrder.id));
    },
    async deleteRecord() {
      if (!await this.$confirm(this.$t('Are you sure you want to delete this purchase order?'), { variant: 'danger' })) {
        return;
      }
      this.$inertia.delete(route('supplier-records.purchase-orders.destroy', this.purchaseOrder.id));
    },
    goto(name, id) {
      this.$inertia.visit(route(name, id));
    },
    isLive(status) {
      return ['Submitted', 'Sent to Supplier', 'Acknowledged', 'Partially Delivered'].includes(status);
    },
    statusTone(status) {
      const map = {
        Draft: 'slate',
        Submitted: 'amber',
        Approved: 'blue',
        'Sent to Supplier': 'indigo',
        Acknowledged: 'cyan',
        'Partially Delivered': 'violet',
        'Pending Match': 'amber',
        Matched: 'blue',
        'Partially Paid': 'amber',
        Confirmed: 'green',
        Completed: 'green',
        Paid: 'green',
        Rejected: 'red',
        Cancelled: 'red',
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
.sd-status.is-indigo { --sc: #4338ca; --sb: rgba(99, 102, 241, 0.14); }
.sd-status.is-violet { --sc: #6d28d9; --sb: rgba(139, 92, 246, 0.15); }
.sd-status.is-cyan { --sc: #0e7490; --sb: rgba(6, 182, 212, 0.16); }
.sd-status.is-green { --sc: #047857; --sb: rgba(16, 185, 129, 0.16); }
.sd-status.is-red { --sc: #be123c; --sb: rgba(244, 63, 94, 0.14); }
html.dark .sd-status { --sc: #cbd5e1; }
html.dark .sd-status.is-amber { --sc: #fcd34d; }
html.dark .sd-status.is-blue { --sc: #93c5fd; }
html.dark .sd-status.is-indigo { --sc: #c7d2fe; }
html.dark .sd-status.is-violet { --sc: #c4b5fd; }
html.dark .sd-status.is-cyan { --sc: #67e8f9; }
html.dark .sd-status.is-green { --sc: #6ee7b7; }
html.dark .sd-status.is-red { --sc: #fda4af; }
.sd-dot { position: relative; width: 9px; height: 9px; border-radius: 9999px; background: var(--sc); flex: none; }
.sd-dot.is-live::after { content: ''; position: absolute; inset: -4px; border-radius: 9999px; border: 2px solid var(--sc); opacity: 0; animation: sd-live 1.8s ease-out infinite; }
@keyframes sd-live { 0% { transform: scale(0.6); opacity: 0.8; } 100% { transform: scale(1.6); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .sd-dot.is-live::after { animation: none; } }

.sd-btn-cyan { color: #fff; background: linear-gradient(145deg, #22d3ee, #0e7490); box-shadow: 0 10px 18px -10px rgba(14, 116, 144, 0.8); }
.sd-btn-cancel { border-color: #fda4af !important; color: #be123c !important; box-shadow: 0 10px 18px -10px rgba(190, 18, 60, 0.6), 0 2px 4px rgba(15, 37, 64, 0.1); }
html.dark .sd-btn-cancel { color: #fda4af !important; }

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
.sd-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
.sd-actions-start { justify-content: flex-start; }
.sd-act { padding: 0 0 14px; margin-bottom: 14px; border-bottom: 1px dashed var(--lg-line); }
.sd-act:last-of-type { padding-bottom: 0; margin-bottom: 0; border-bottom: 0; }
.sd-act-title { margin-bottom: 8px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }

.sd-totals { width: 100%; max-width: 22rem; margin: 1rem 0 0 auto; padding: 0.75rem 1rem; border-radius: 16px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.sd-totals-row { display: flex; justify-content: space-between; gap: 12px; padding: 4px 0; font-size: 0.85rem; color: var(--lg-mute); }
.sd-totals-row dd { font-weight: 700; color: var(--lg-ink); font-variant-numeric: tabular-nums; text-align: right; }
.sd-totals-row dd.sd-dim { color: var(--lg-faint); }
.sd-totals-grand { margin-top: 4px; padding-top: 8px; border-top: 1px solid var(--lg-line); font-size: 1rem; font-weight: 800; color: var(--lg-ink); }
.sd-totals-grand dd { font-size: 1.05rem; }

.sd-trace { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.sd-node { display: flex; flex-direction: column; gap: 1px; min-width: 140px; padding: 9px 14px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); font-size: 0.8rem; color: var(--lg-ink); transition: transform 0.15s ease, border-color 0.15s ease; }
a.sd-node:hover { transform: translateY(-1px); border-color: #93c5fd; }
.sd-node.is-active { background: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.45); }
.sd-node-kind { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.sd-node-ref { font-weight: 800; }
.sd-arrow { color: var(--lg-faint); flex: none; }

.sd-none { font-size: 0.84rem; color: var(--lg-faint); }
.sd-rows { list-style: none; margin: 0; padding: 0; border: 1px solid var(--lg-line); border-radius: 14px; overflow: hidden; }
.sd-rows li { border-bottom: 1px solid var(--lg-line); }
.sd-rows li:last-child { border-bottom: 0; }
.sd-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 14px; color: var(--lg-ink); cursor: pointer; transition: background 0.15s ease; }
.sd-row:hover { background: var(--lg-soft); }
.sd-row-main { font-size: 0.86rem; font-weight: 800; }
.sd-row-meta { margin-left: 8px; font-size: 0.74rem; color: var(--lg-faint); }
.sd-row-end { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; flex: none; }
.sd-row-amt { font-size: 0.86rem; font-weight: 800; font-variant-numeric: tabular-nums; }

.sd-files { list-style: none; margin: 0; padding: 0; border: 1px solid var(--lg-line); border-radius: 14px; overflow: hidden; }
.sd-files li { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 12px; border-bottom: 1px solid var(--lg-line); font-size: 0.82rem; color: var(--lg-ink); }
.sd-files li:last-child { border-bottom: 0; }
.sd-file-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sd-dl { display: inline-flex; flex: none; align-items: center; gap: 5px; font-weight: 800; color: var(--lg-navy); }
.sd-dl:hover { text-decoration: underline; }
html.dark .sd-dl { color: #93c5fd; }

.sd-timeline { list-style: none; margin: 0; padding: 0 0 0 6px; }
.sd-timeline li { position: relative; padding: 0 0 16px 18px; border-left: 2px solid var(--lg-line); }
.sd-timeline li:last-child { border-left-color: transparent; padding-bottom: 0; }
.sd-tl-dot { position: absolute; left: -7px; top: 3px; width: 12px; height: 12px; border-radius: 9999px; background: #64748b; box-shadow: 0 0 0 3px var(--lg-card); }
.sd-tl-dot.is-amber { background: #f59e0b; }
.sd-tl-dot.is-blue { background: #3b82f6; }
.sd-tl-dot.is-indigo { background: #6366f1; }
.sd-tl-dot.is-green { background: #10b981; }
.sd-tl-dot.is-red { background: #f43f5e; }
.sd-tl-title { font-size: 0.86rem; font-weight: 800; color: var(--lg-ink); }
.sd-tl-meta { font-size: 0.74rem; color: var(--lg-faint); }
.sd-tl-note { margin-top: 4px; font-size: 0.78rem; color: var(--lg-mute); white-space: pre-wrap; }
</style>

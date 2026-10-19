<template>
  <admin-layout :title="purchaseRequest.reference">
    <div class="lg-page px-4 md:px-0 sd-show">
      <page-header icon="doc-text" :title="purchaseRequest.reference" :subtitle="subtitle">
        <span class="sd-status" :class="'is-' + statusTone(purchaseRequest.status)">
          <span class="sd-dot" :class="{ 'is-live': isLive(purchaseRequest.status) }"></span>
          {{ purchaseRequest.status }}
        </span>
        <Link :href="route('supplier-records.purchase-requests.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Purchase Requests') }}
        </Link>
        <a v-if="$can('print-purchase-requests')" :href="route('supplier-records.purchase-requests.print', purchaseRequest.id)" target="_blank" rel="noopener" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </a>
        <a v-if="$can('print-purchase-requests')" :href="route('supplier-records.purchase-requests.pdf', purchaseRequest.id)" class="lg-btn lg-btn-dark">
          <icons name="download" class="w-4 h-4" />{{ $t('Download') }}
        </a>
        <Link v-if="canEdit" :href="route('supplier-records.purchase-requests.edit', purchaseRequest.id)" class="lg-btn lg-btn-primary">
          <icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" />

      <div class="sd-grid">
        <div class="sd-col">
          <!-- Request overview -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-pink"><icons name="doc-text" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Request Overview') }}</h2>
                <p class="lg-card-sub">{{ purchaseRequest.reference }}</p>
              </div>
            </header>
            <dl class="sd-kv">
              <div>
                <dt>{{ $t('Reference') }}</dt>
                <dd>{{ purchaseRequest.reference }}</dd>
              </div>
              <div>
                <dt>{{ $t('Date Requested') }}</dt>
                <dd>{{ $date(purchaseRequest.date_requested) }}</dd>
              </div>
              <div>
                <dt>{{ $t('Warehouse / Project') }}</dt>
                <dd>{{ purchaseRequest.warehouse_name }}</dd>
              </div>
              <div>
                <dt>{{ $t('Requested By') }}</dt>
                <dd>{{ purchaseRequest.user_name }}</dd>
              </div>
            </dl>
            <div v-if="purchaseRequest.reason" class="sd-remarks">
              <span class="sd-remarks-label">{{ $t('Reason') }}</span>
              {{ purchaseRequest.reason }}
            </div>
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
                    <th>{{ $t('Remarks') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(item, index) in purchaseRequest.items" :key="index">
                    <td :data-label="$t('Item')"><span class="lg-main">{{ item.item_code }} - {{ item.item_name }}</span></td>
                    <td :data-label="$t('Unit')">{{ item.unit_label || '-' }}</td>
                    <td :data-label="$t('Quantity')" class="num font-semibold">{{ $number(item.quantity) }}</td>
                    <td :data-label="$t('Remarks')">{{ item.remarks }}</td>
                  </tr>
                  <tr v-if="!purchaseRequest.items || !purchaseRequest.items.length" class="lg-empty-row">
                    <td class="lg-empty" colspan="4">{{ $t('No items.') }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <!-- Linked records -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-violet"><icons name="link" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1">
                <h2 class="lg-card-title">{{ $t('Linked Records') }}</h2>
              </div>
              <Link
                v-if="purchaseRequest.status === 'Approved'"
                :href="route('supplier-records.quotations.create', { purchase_request_id: purchaseRequest.id })"
                class="lg-btn sd-btn-cyan"
              >
                <icons name="plus" class="w-4 h-4" />{{ $t('Create Quotation from this Request') }}
              </Link>
            </header>

            <div class="sd-sub">{{ $t('Quotations') }}</div>
            <ul v-if="quotations.length" class="sd-rows">
              <li v-for="q in quotations" :key="q.id">
                <Link :href="route('supplier-records.quotations.show', q.id)" class="sd-row">
                  <span class="sd-row-main">{{ q.reference }}</span>
                  <span class="lg-chip" :class="chipClass(quotationTone(q.status))" style="text-transform: none">{{ q.status }}</span>
                </Link>
              </li>
            </ul>
            <p v-else class="sd-none">{{ $t('No quotations yet.') }}</p>

            <div class="sd-sub mt-4">{{ $t('Purchase Orders') }}</div>
            <ul v-if="purchaseOrders.length" class="sd-rows">
              <li v-for="po in purchaseOrders" :key="po.id">
                <Link :href="route('supplier-records.purchase-orders.show', po.id)" class="sd-row">
                  <span class="sd-row-main">{{ po.reference }}</span>
                  <span class="lg-chip" :class="chipClass(statusTone(po.status))" style="text-transform: none">{{ po.status }}</span>
                </Link>
              </li>
            </ul>
            <p v-else class="sd-none">{{ $t('No purchase orders yet.') }}</p>
          </section>
        </div>

        <div class="sd-col">
          <!-- Approval -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Approval') }}</h2>
              </div>
            </header>

            <template v-if="purchaseRequest.status === 'Pending Approval'">
              <textarea-input v-model="statusForm.approval_remarks" :label="$t('Remarks (required to reject)')" :error="$page.props.errors.approval_remarks" />
              <div class="sd-actions mt-3">
                <button type="button" class="lg-btn lg-btn-red" @click="reject">
                  <icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}
                </button>
                <button type="button" class="lg-btn lg-btn-green" :disabled="statusForm.processing" @click="approve">
                  <icons :name="statusForm.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': statusForm.processing }" />{{ $t('Approve') }}
                </button>
              </div>
            </template>
            <template v-else>
              <p class="lg-note" :class="purchaseRequest.status === 'Rejected' ? 'lg-note-red' : 'lg-note-blue'">
                <icons name="info" class="w-4 h-4" />
                <span class="sd-pre">{{ purchaseRequest.approval_remarks || $t('No approval remarks recorded.') }}</span>
              </p>
            </template>
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
              <li v-for="file in purchaseRequest.attachments" :key="file.id">
                <span class="sd-file-name">{{ file.name }}</span>
                <a :href="route('supplier-records.purchase-requests.attachments.download', [purchaseRequest.id, file.id])" class="sd-dl">
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
import TextareaInput from '@/Shared/TextareaInput.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, TextareaInput, PageHeader, StatTiles },

  props: {
    purchaseRequest: Object,
    quotations: Array,
    purchaseOrders: Array,
  },

  data() {
    return {
      statusForm: this.$inertia.form({
        status: '',
        approval_remarks: this.purchaseRequest.approval_remarks || '',
      }),
    };
  },

  computed: {
    canEdit() {
      return ['Draft', 'Pending Approval'].includes(this.purchaseRequest.status);
    },
    subtitle() {
      return [this.purchaseRequest.warehouse_name, this.purchaseRequest.user_name].filter(Boolean).join(' · ');
    },
    itemCount() {
      return (this.purchaseRequest.items || []).length;
    },
    attachmentCount() {
      return (this.purchaseRequest.attachments || []).length;
    },
    statTiles() {
      return [
        { icon: 'doc-text', tone: 'pink', label: this.$t('Items'), value: this.itemCount },
        { icon: 'calendar', tone: 'sky', label: this.$t('Date Requested'), value: this.$date(this.purchaseRequest.date_requested) },
        { icon: 'cash', tone: 'indigo', label: this.$t('Quotations'), value: this.quotations.length },
        { icon: 'truck', tone: 'violet', label: this.$t('Purchase Orders'), value: this.purchaseOrders.length },
      ];
    },
  },

  methods: {
    approve() {
      this.statusForm
        .transform(() => ({ status: 'Approved', approval_remarks: this.statusForm.approval_remarks }))
        .patch(route('supplier-records.purchase-requests.status', this.purchaseRequest.id));
    },
    async reject() {
      if (!this.statusForm.approval_remarks || !this.statusForm.approval_remarks.trim()) {
        await this.$alert(this.$t('Please enter remarks explaining the reason before rejecting.'));
        return;
      }
      this.statusForm
        .transform(() => ({ status: 'Rejected', approval_remarks: this.statusForm.approval_remarks }))
        .patch(route('supplier-records.purchase-requests.status', this.purchaseRequest.id));
    },
    chipClass(tone) {
      return 'lg-chip-' + tone;
    },
    isLive(status) {
      return ['Pending Approval', 'Submitted', 'Sent to Supplier', 'Acknowledged', 'Partially Delivered'].includes(status);
    },
    statusTone(status) {
      const map = {
        Draft: 'slate',
        'Pending Approval': 'amber',
        Submitted: 'amber',
        Approved: 'blue',
        'Sent to Supplier': 'indigo',
        Acknowledged: 'cyan',
        'Partially Delivered': 'violet',
        Completed: 'green',
        'Converted to PO': 'green',
        Rejected: 'red',
        Cancelled: 'red',
      };
      return map[status] || 'slate';
    },
    quotationTone(status) {
      const map = {
        Draft: 'slate',
        Sent: 'blue',
        Received: 'amber',
        Selected: 'green',
        Rejected: 'red',
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

.sd-grid { display: grid; gap: 0 1rem; grid-template-columns: minmax(0, 1fr); align-items: start; }
@media (min-width: 1024px) { .sd-grid { grid-template-columns: minmax(0, 1fr) 22rem; } }
.sd-col { min-width: 0; }

.sd-kv { display: grid; gap: 14px 22px; grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr)); margin: 0; }
.sd-kv > div { min-width: 0; }
.sd-kv dt { margin-bottom: 3px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.sd-kv dd { font-size: 0.9rem; font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }
.sd-remarks { margin-top: 16px; padding: 0.75rem 1rem; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); font-size: 0.85rem; color: var(--lg-ink); white-space: pre-wrap; }
.sd-remarks-label { display: block; margin-bottom: 2px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.sd-pre { white-space: pre-wrap; overflow-wrap: anywhere; }
.sd-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }

.sd-sub { margin-bottom: 8px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.sd-none { font-size: 0.84rem; color: var(--lg-faint); }
.sd-rows { list-style: none; margin: 0; padding: 0; border: 1px solid var(--lg-line); border-radius: 14px; overflow: hidden; }
.sd-rows li { border-bottom: 1px solid var(--lg-line); }
.sd-rows li:last-child { border-bottom: 0; }
.sd-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 14px; color: var(--lg-ink); transition: background 0.15s ease; }
.sd-row:hover { background: var(--lg-soft); }
.sd-row-main { font-size: 0.86rem; font-weight: 800; }

.sd-files { list-style: none; margin: 0; padding: 0; border: 1px solid var(--lg-line); border-radius: 14px; overflow: hidden; }
.sd-files li { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 12px; border-bottom: 1px solid var(--lg-line); font-size: 0.82rem; color: var(--lg-ink); }
.sd-files li:last-child { border-bottom: 0; }
.sd-file-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sd-dl { display: inline-flex; flex: none; align-items: center; gap: 5px; font-weight: 800; color: var(--lg-navy); }
.sd-dl:hover { text-decoration: underline; }
html.dark .sd-dl { color: #93c5fd; }
</style>

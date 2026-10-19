<template>
  <admin-layout :title="goodsReceipt.reference">
    <div class="lg-page px-4 md:px-0 sd-show">
      <page-header icon="download" :title="goodsReceipt.reference" :subtitle="goodsReceipt.warehouse_name || $t('No project')">
        <span class="sd-status is-green">
          <span class="sd-dot"></span>
          {{ goodsReceipt.status }}
        </span>
        <Link :href="route('supplier-records.goods-receipts.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Goods Receipts') }}
        </Link>
        <a v-if="$can('print-goods-receipts')" :href="route('supplier-records.goods-receipts.print', goodsReceipt.id)" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </a>
        <a v-if="$can('print-goods-receipts')" :href="route('supplier-records.goods-receipts.pdf', goodsReceipt.id)" class="lg-btn lg-btn-dark">
          <icons name="download" class="w-4 h-4" />{{ $t('PDF') }}
        </a>
        <Link v-if="$super" :href="route('supplier-records.goods-receipts.edit', goodsReceipt.id)" class="lg-btn lg-btn-primary">
          <icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" />

      <!-- Trace -->
      <section v-if="goodsReceipt.trace" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="link" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Where This Comes From') }}</h2>
          </div>
        </header>
        <div class="sd-trace">
          <template v-if="goodsReceipt.trace.purchase_request">
            <Link :href="route(goodsReceipt.trace.purchase_request.route, goodsReceipt.trace.purchase_request.id)" class="sd-node">
              <span class="sd-node-kind">{{ $t('Purchase Request') }}</span>
              <span class="sd-node-ref">{{ goodsReceipt.trace.purchase_request.reference }}</span>
              <span class="sd-node-by">{{ $t('by') }} {{ goodsReceipt.trace.purchase_request.created_by_name || $t('Unknown') }}</span>
            </Link>
            <icons name="chevron-down" class="sd-arrow w-4 h-4 -rotate-90" />
          </template>
          <template v-if="goodsReceipt.trace.quotation">
            <Link :href="route(goodsReceipt.trace.quotation.route, goodsReceipt.trace.quotation.id)" class="sd-node">
              <span class="sd-node-kind">{{ $t('Quotation') }}</span>
              <span class="sd-node-ref">{{ goodsReceipt.trace.quotation.reference }}</span>
              <span class="sd-node-by">{{ $t('by') }} {{ goodsReceipt.trace.quotation.created_by_name || $t('Unknown') }}</span>
            </Link>
            <icons name="chevron-down" class="sd-arrow w-4 h-4 -rotate-90" />
          </template>
          <Link :href="route(goodsReceipt.trace.purchase_order.route, goodsReceipt.trace.purchase_order.id)" class="sd-node is-active">
            <span class="sd-node-kind">{{ $t('Purchase Order') }}</span>
            <span class="sd-node-ref">{{ goodsReceipt.trace.purchase_order.reference }}</span>
            <span class="sd-node-by">{{ $t('by') }} {{ goodsReceipt.trace.purchase_order.created_by_name || $t('Unknown') }}</span>
          </Link>
          <icons name="chevron-down" class="sd-arrow w-4 h-4 -rotate-90" />
          <div class="sd-node is-current">
            <span class="sd-node-kind">{{ $t('Goods Receipt') }}</span>
            <span class="sd-node-ref">{{ goodsReceipt.reference }}</span>
            <span class="sd-node-by">{{ $t('by') }} {{ goodsReceipt.received_by_name || $t('Unknown') }}</span>
          </div>
        </div>
      </section>

      <div class="sd-grid">
        <div class="sd-col">
          <!-- Overview -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-teal"><icons name="download" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Receipt Overview') }}</h2>
                <p class="lg-card-sub">{{ goodsReceipt.reference }}</p>
              </div>
            </header>
            <dl class="sd-kv">
              <div>
                <dt>{{ $t('Reference') }}</dt>
                <dd>{{ goodsReceipt.reference }}</dd>
              </div>
              <div>
                <dt>{{ $t('Purchase Order') }}</dt>
                <dd>
                  <Link v-if="goodsReceipt.purchase_order" :href="route('supplier-records.purchase-orders.show', goodsReceipt.purchase_order.id)" class="sd-link">
                    {{ goodsReceipt.purchase_order.reference }}
                  </Link>
                  <span v-else>-</span>
                </dd>
              </div>
              <div>
                <dt>{{ $t('Project') }}</dt>
                <dd>{{ goodsReceipt.warehouse_name || '-' }}</dd>
              </div>
              <div>
                <dt>{{ $t('Date Received') }}</dt>
                <dd>{{ $date(goodsReceipt.date_received) }}</dd>
              </div>
              <div>
                <dt>{{ $t('Received By') }}</dt>
                <dd>{{ goodsReceipt.received_by_name || '-' }}</dd>
              </div>
              <div>
                <dt>{{ $t('Status') }}</dt>
                <dd><span class="lg-chip lg-chip-green" style="text-transform: none">{{ goodsReceipt.status }}</span></dd>
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
              <span class="lg-chip lg-chip-indigo" style="text-transform: none">{{ itemCount + missingPoItems.length }} {{ $t('Items') }}</span>
            </header>
            <div class="lg-table-wrap">
              <table class="lg-table lg-responsive">
                <thead>
                  <tr>
                    <th>{{ $t('Item') }}</th>
                    <th>{{ $t('Unit') }}</th>
                    <th class="num">{{ $t('Ordered') }}</th>
                    <th class="num">{{ $t('Received') }}</th>
                    <th>{{ $t('Variance') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(item, index) in goodsReceipt.items" :key="'r' + index">
                    <td :data-label="$t('Item')"><span class="lg-main">{{ item.item_code ? item.item_code + ' - ' : '' }}{{ item.item_name }}</span></td>
                    <td :data-label="$t('Unit')">{{ item.unit_label || '-' }}</td>
                    <td :data-label="$t('Ordered')" class="num">{{ $number(item.ordered_quantity) }}</td>
                    <td :data-label="$t('Received')" class="num font-semibold">{{ $number(item.received_quantity) }}</td>
                    <td :data-label="$t('Variance')">
                      <span class="lg-chip" :class="varianceInfo(item).class" style="text-transform: none">{{ varianceInfo(item).text }}</span>
                      <span v-if="item.variance_type" class="sd-row-meta">({{ item.variance_type }})</span>
                    </td>
                  </tr>
                  <tr v-for="(item, index) in missingPoItems" :key="'m' + index" class="sd-missing">
                    <td :data-label="$t('Item')"><span class="lg-main">{{ item.item_code ? item.item_code + ' - ' : '' }}{{ item.item_name }}</span></td>
                    <td :data-label="$t('Unit')">{{ item.unit_label || '-' }}</td>
                    <td :data-label="$t('Ordered')" class="num">{{ $number(item.quantity) }}</td>
                    <td :data-label="$t('Received')" class="num font-semibold">{{ $number(0) }}</td>
                    <td :data-label="$t('Variance')">
                      <span class="lg-chip lg-chip-red" style="text-transform: none">{{ $t('Not Received') }}</span>
                      <span class="sd-row-meta">({{ $t('not on this receipt') }})</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="sd-legend">
              <span><i class="sd-lg-dot" style="background: #10b981"></i>{{ $t('Matches') }}</span>
              <span><i class="sd-lg-dot" style="background: #ef4444"></i>{{ $t('Short / Not Received') }}</span>
              <span><i class="sd-lg-dot" style="background: #f59e0b"></i>{{ $t('Over: received more than ordered') }}</span>
              <span><i class="sd-lg-dot" style="background: #8b5cf6"></i>{{ $t('Added / Not on PO') }}</span>
            </div>
          </section>
        </div>

        <div class="sd-col">
          <!-- Remarks -->
          <section v-if="goodsReceipt.remarks" class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-amber"><icons name="doc-text" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Remarks') }}</h2>
              </div>
            </header>
            <p class="sd-pre">{{ goodsReceipt.remarks }}</p>
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
              <li v-for="file in goodsReceipt.attachments" :key="file.id">
                <span class="sd-file-name">{{ file.name }}</span>
                <a :href="route('supplier-records.goods-receipts.attachments.download', [goodsReceipt.id, file.id])" class="sd-dl">
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
    goodsReceipt: Object,
  },

  computed: {
    attachmentCount() {
      return (this.goodsReceipt.attachments || []).length;
    },
    itemCount() {
      return (this.goodsReceipt.items || []).length;
    },
    statTiles() {
      const missing = this.missingPoItems.length;
      return [
        { icon: 'calendar', tone: 'sky', label: this.$t('Date Received'), value: this.$date(this.goodsReceipt.date_received) },
        { icon: 'bag', tone: 'indigo', label: this.$t('Items'), value: this.itemCount },
        {
          icon: missing ? 'exclamation' : 'tick',
          tone: missing ? 'rose' : 'emerald',
          label: this.$t('Not Received'),
          value: missing,
        },
        { icon: 'user', tone: 'slate', label: this.$t('Received By'), value: this.goodsReceipt.received_by_name || '-' },
      ];
    },
    // Items that were on the linked Purchase Order but don't appear at all
    // in this receipt's items array (dropped entirely, not even a
    // 0-received row) — surfaced separately so nothing silently disappears
    // from the variance picture. Matched by item_id when available, falling
    // back to code+name for legacy/manual rows without an item_id.
    missingPoItems() {
      const poItems = (this.goodsReceipt.purchase_order && this.goodsReceipt.purchase_order.items) || [];
      const receiptKeys = new Set(
        (this.goodsReceipt.items || []).map(i => (i.item_id ? `id:${i.item_id}` : `nk:${i.item_code}|${i.item_name}`))
      );
      return poItems.filter(pi => {
        const key = pi.item_id ? `id:${pi.item_id}` : `nk:${pi.item_code}|${pi.item_name}`;
        return !receiptKeys.has(key);
      });
    },
  },

  methods: {
    // Primary source of truth is computed client-side from ordered vs
    // received quantities — new rows created through the form never get a
    // `variance_type` from the backend (GoodsReceiptController@store
    // doesn't compute one), and even the two seeded example records only
    // carry it as a historical hint (rendered separately, in parentheses).
    varianceInfo(row) {
      const ordered = parseFloat(row.ordered_quantity) || 0;
      const receivedRaw = row.received_quantity;
      const received = receivedRaw === null || receivedRaw === undefined || receivedRaw === '' ? 0 : parseFloat(receivedRaw);
      const receivedValid = !isNaN(received);

      if (ordered === 0 && receivedValid && received > 0) {
        return { text: this.$t('Added / Not on PO'), class: 'lg-chip-violet' };
      }
      if (!receivedValid || received === 0) {
        return { text: this.$t('Not Received'), class: 'lg-chip-red' };
      }
      const diff = received - ordered;
      if (Math.abs(diff) < 0.0001) {
        return { text: this.$t('Matches'), class: 'lg-chip-green' };
      }
      if (diff < 0) {
        return { text: `${this.$t('Short')} (${this.$number(Math.abs(diff))})`, class: 'lg-chip-red' };
      }
      return { text: `${this.$t('Over')} (${this.$number(diff)})`, class: 'lg-chip-amber' };
    },
  },
};
</script>

<style scoped>
.sd-status { --sc: #475569; --sb: rgba(100, 116, 139, 0.14); display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px 8px 12px; border-radius: 9999px; font-size: 0.82rem; font-weight: 800; letter-spacing: 0.02em; color: var(--sc); background: var(--sb); border: 1px solid color-mix(in srgb, var(--sc) 30%, transparent); box-shadow: 0 10px 18px -12px var(--sc); }
.sd-status.is-green { --sc: #047857; --sb: rgba(16, 185, 129, 0.16); }
html.dark .sd-status.is-green { --sc: #6ee7b7; }
.sd-dot { position: relative; width: 9px; height: 9px; border-radius: 9999px; background: var(--sc); flex: none; }

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
.sd-pre { font-size: 0.86rem; color: var(--lg-ink); white-space: pre-wrap; overflow-wrap: anywhere; }
.sd-none { font-size: 0.84rem; color: var(--lg-faint); }

.sd-trace { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.sd-node { display: flex; flex-direction: column; gap: 1px; min-width: 150px; padding: 9px 14px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); font-size: 0.8rem; color: var(--lg-ink); transition: transform 0.15s ease, border-color 0.15s ease; }
a.sd-node:hover { transform: translateY(-1px); border-color: #93c5fd; }
.sd-node.is-active { background: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.45); }
.sd-node.is-current { background: rgba(236, 72, 153, 0.08); border: 1px dashed rgba(236, 72, 153, 0.55); }
.sd-node-kind { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.sd-node-ref { font-weight: 800; }
.sd-node-by { font-size: 0.72rem; color: var(--lg-mute); }
.sd-arrow { color: var(--lg-faint); flex: none; }

.sd-row-meta { margin-left: 6px; font-size: 0.7rem; color: var(--lg-faint); }
.sd-missing td { background: rgba(244, 63, 94, 0.05); }
.sd-legend { display: flex; flex-wrap: wrap; gap: 6px 16px; margin-top: 12px; font-size: 0.74rem; color: var(--lg-mute); }
.sd-legend span { display: inline-flex; align-items: center; gap: 6px; }
.sd-lg-dot { display: inline-block; width: 10px; height: 10px; border-radius: 9999px; }

.sd-files { list-style: none; margin: 0; padding: 0; border: 1px solid var(--lg-line); border-radius: 14px; overflow: hidden; }
.sd-files li { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 12px; border-bottom: 1px solid var(--lg-line); font-size: 0.82rem; color: var(--lg-ink); }
.sd-files li:last-child { border-bottom: 0; }
.sd-file-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sd-dl { display: inline-flex; flex: none; align-items: center; gap: 5px; font-weight: 800; color: var(--lg-navy); }
.sd-dl:hover { text-decoration: underline; }
html.dark .sd-dl { color: #93c5fd; }
</style>

<template>
  <admin-layout :title="quotation.reference">
    <div class="lg-page px-4 md:px-0 sd-show">
      <page-header icon="doc-text" :title="quotation.reference" :subtitle="quotation.supplier_name">
        <span class="sd-status" :class="'is-' + statusTone(quotation.status)">
          <span class="sd-dot" :class="{ 'is-live': ['Sent', 'Received'].includes(quotation.status) }"></span>
          {{ quotation.status }}
        </span>
        <Link :href="route('supplier-records.quotations.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Quotations') }}
        </Link>
        <a v-if="$can('print-quotations')" :href="route('supplier-records.quotations.print', quotation.id)" target="_blank" rel="noopener" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </a>
        <a v-if="$can('print-quotations')" :href="route('supplier-records.quotations.pdf', quotation.id)" class="lg-btn lg-btn-dark">
          <icons name="download" class="w-4 h-4" />{{ $t('Download') }}
        </a>
        <Link :href="route('supplier-records.quotations.edit', quotation.id)" class="lg-btn lg-btn-primary">
          <icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" />

      <div class="sd-grid">
        <div class="sd-col">
          <!-- Overview -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-pink"><icons name="doc-text" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Quotation Overview') }}</h2>
                <p class="lg-card-sub">{{ quotation.reference }}</p>
              </div>
            </header>
            <dl class="sd-kv">
              <div>
                <dt>{{ $t('Reference') }}</dt>
                <dd>{{ quotation.reference }}</dd>
              </div>
              <div>
                <dt>{{ $t('Supplier') }}</dt>
                <dd>{{ quotation.supplier_name }}</dd>
              </div>
              <div>
                <dt>{{ $t('Warehouse / Project') }}</dt>
                <dd>{{ quotation.warehouse_name || '-' }}</dd>
              </div>
              <div>
                <dt>{{ $t('Date') }}</dt>
                <dd>{{ $date(quotation.date) }}</dd>
              </div>
              <div>
                <dt>{{ $t('Valid Until') }}</dt>
                <dd>{{ quotation.valid_until ? $date(quotation.valid_until) : '-' }}</dd>
              </div>
              <div v-if="quotation.purchase_request_id">
                <dt>{{ $t('Purchase Request') }}</dt>
                <dd>
                  <Link class="sd-link" :href="route('supplier-records.purchase-requests.show', quotation.purchase_request_id)">
                    #{{ quotation.purchase_request_id }}
                  </Link>
                </dd>
              </div>
              <div>
                <dt>{{ $t('Created By') }}</dt>
                <dd>{{ quotation.created_by_name || '-' }}</dd>
              </div>
            </dl>
            <div v-if="quotation.remarks" class="sd-remarks">
              <span class="sd-remarks-label">{{ $t('Remarks') }}</span>
              {{ quotation.remarks }}
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
                    <th class="num">{{ $t('Unit Price') }}</th>
                    <th class="num">{{ $t('Line Total') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(item, index) in quotation.items" :key="index">
                    <td :data-label="$t('Item')"><span class="lg-main">{{ item.item_code }} - {{ item.item_name }}</span></td>
                    <td :data-label="$t('Unit')">{{ item.unit_label || '-' }}</td>
                    <td :data-label="$t('Quantity')" class="num">{{ $number(item.quantity) }}</td>
                    <td :data-label="$t('Unit Price')" class="num">{{ $money(item.unit_price) }}</td>
                    <td :data-label="$t('Line Total')" class="num font-semibold">{{ $money(item.quantity * item.unit_price) }}</td>
                  </tr>
                  <tr v-if="!quotation.items || !quotation.items.length" class="lg-empty-row">
                    <td class="lg-empty" colspan="5">{{ $t('No items.') }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <dl v-if="quotation.items && quotation.items.length" class="sd-totals">
              <div class="sd-totals-row sd-totals-grand">
                <dt>{{ $t('Grand Total') }}</dt>
                <dd>{{ $money(quotation.total) }}</dd>
              </div>
            </dl>
          </section>
        </div>

        <div class="sd-col">
          <!-- Status / workflow -->
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Status') }}</h2>
              </div>
            </header>

            <ol class="sd-steps">
              <li v-for="(step, i) in steps" :key="step" :class="{ 'is-done': stepIndex > i, 'is-now': stepIndex === i }">
                <span class="sd-step-dot">{{ i + 1 }}</span>
                <span class="sd-step-label">{{ $t(step) }}</span>
              </li>
            </ol>

            <template v-if="quotation.status === 'Draft'">
              <p class="lg-note lg-note-blue mb-3"><icons name="info" class="w-4 h-4" /><span>{{ $t('Send this quotation once it is ready to be considered.') }}</span></p>
              <div class="sd-actions">
                <button type="button" class="lg-btn lg-btn-primary" :disabled="statusForm.processing" @click="setStatus('Sent')">
                  <icons :name="statusForm.processing ? 'refresh' : 'plane'" class="w-4 h-4" :class="{ 'animate-spin': statusForm.processing }" />{{ $t('Mark as Sent') }}
                </button>
              </div>
            </template>

            <template v-else-if="quotation.status === 'Sent'">
              <p class="lg-note lg-note-blue mb-3"><icons name="info" class="w-4 h-4" /><span>{{ $t('Once the supplier responds, mark this quotation as received.') }}</span></p>
              <div class="sd-actions">
                <button type="button" class="lg-btn lg-btn-primary" :disabled="statusForm.processing" @click="setStatus('Received')">
                  <icons :name="statusForm.processing ? 'refresh' : 'download'" class="w-4 h-4" :class="{ 'animate-spin': statusForm.processing }" />{{ $t('Mark as Received') }}
                </button>
              </div>
            </template>

            <template v-else-if="quotation.status === 'Received'">
              <p class="lg-note lg-note-amber mb-3"><icons name="info" class="w-4 h-4" /><span>{{ $t('Select this quotation to proceed with a purchase order, or reject it.') }}</span></p>
              <div class="sd-actions">
                <button type="button" class="lg-btn lg-btn-red" @click="setStatus('Rejected')">
                  <icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}
                </button>
                <button type="button" class="lg-btn lg-btn-green" :disabled="statusForm.processing" @click="setStatus('Selected')">
                  <icons :name="statusForm.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': statusForm.processing }" />{{ $t('Select') }}
                </button>
              </div>
            </template>

            <template v-else-if="quotation.status === 'Selected'">
              <p class="sd-good mb-3">
                <icons name="tick" class="w-4 h-4" />
                <span>{{ $t('This quotation has been selected. You can now create a Purchase Order for it.') }}</span>
              </p>
              <div class="sd-actions">
                <Link :href="route('supplier-records.purchase-orders.create')" class="lg-btn sd-btn-cyan">
                  <icons name="truck" class="w-4 h-4" />{{ $t('Create Purchase Order from this Quotation') }}
                </Link>
              </div>
            </template>

            <template v-else>
              <p class="lg-note lg-note-red"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t('This quotation has been rejected.') }}</span></p>
            </template>
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
    quotation: Object,
  },

  data() {
    return {
      statusForm: this.$inertia.form({}),
      steps: ['Draft', 'Sent', 'Received', 'Selected'],
    };
  },

  computed: {
    itemCount() {
      return (this.quotation.items || []).length;
    },
    stepIndex() {
      return this.steps.indexOf(this.quotation.status);
    },
    statTiles() {
      return [
        { icon: 'cash', tone: 'emerald', label: this.$t('Total'), value: this.$money(this.quotation.total) },
        { icon: 'bag', tone: 'indigo', label: this.$t('Items'), value: this.itemCount },
        { icon: 'calendar', tone: 'sky', label: this.$t('Date'), value: this.$date(this.quotation.date) },
        { icon: 'clock', tone: 'amber', label: this.$t('Valid Until'), value: this.quotation.valid_until ? this.$date(this.quotation.valid_until) : '-' },
      ];
    },
  },

  methods: {
    setStatus(status) {
      this.statusForm.transform(() => ({ status })).patch(route('supplier-records.quotations.status', this.quotation.id));
    },
    statusTone(status) {
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

.sd-btn-cyan { color: #fff; background: linear-gradient(145deg, #22d3ee, #0e7490); box-shadow: 0 10px 18px -10px rgba(14, 116, 144, 0.8); }

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
.sd-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }

.sd-totals { width: 100%; max-width: 22rem; margin: 1rem 0 0 auto; padding: 0.75rem 1rem; border-radius: 16px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.sd-totals-row { display: flex; justify-content: space-between; gap: 12px; padding: 4px 0; font-size: 0.85rem; color: var(--lg-mute); }
.sd-totals-row dd { font-weight: 700; color: var(--lg-ink); font-variant-numeric: tabular-nums; text-align: right; }
.sd-totals-grand { font-size: 1rem; font-weight: 800; color: var(--lg-ink); }
.sd-totals-grand dd { font-size: 1.05rem; }

.sd-steps { list-style: none; display: flex; align-items: flex-start; gap: 6px; margin: 0 0 1rem; padding: 0; }
.sd-steps li { position: relative; flex: 1 1 0; min-width: 0; display: flex; flex-direction: column; align-items: center; gap: 5px; text-align: center; }
.sd-steps li:not(:last-child)::after { content: ''; position: absolute; top: 13px; left: calc(50% + 16px); right: calc(-50% + 16px); height: 2px; border-radius: 2px; background: var(--lg-line); }
.sd-steps li.is-done:not(:last-child)::after { background: #10b981; }
.sd-step-dot { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800; color: var(--lg-faint); background: var(--lg-soft); border: 2px solid var(--lg-line); }
.sd-steps li.is-done .sd-step-dot { color: #fff; background: #10b981; border-color: #10b981; }
.sd-steps li.is-now .sd-step-dot { color: #fff; background: linear-gradient(145deg, #3b82f6, #1c3f66); border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }
.sd-step-label { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.03em; text-transform: uppercase; color: var(--lg-faint); }
.sd-steps li.is-now .sd-step-label, .sd-steps li.is-done .sd-step-label { color: var(--lg-ink); }

.sd-good { display: flex; align-items: flex-start; gap: 8px; padding: 0.65rem 0.85rem; border-radius: 14px; font-size: 0.82rem; font-weight: 600; color: #065f46; background: rgba(209, 250, 229, 0.7); border: 1px solid rgba(16, 185, 129, 0.35); }
.sd-good svg, .sd-good > div { flex: none; margin-top: 2px; }
html.dark .sd-good { color: #6ee7b7; background: rgba(16, 185, 129, 0.1); }
</style>

<template>
  <tec-dialog-modal :show="show" max-width="7xl" @close="$emit('close')">
    <template #title>
      <div class="vr-title">
        <span class="vr-title__ico"><icons name="calculator" class="w-5 h-5" /></span>
        <div><b>{{ title }}</b><small>{{ subtitle }}</small></div>
        <span v-if="report" class="lg-chip vr-status" :class="statusChip">{{ report.status }}</span>
      </div>
    </template>

    <template #content>
      <div v-if="report" class="vr">
        <div class="vr-cards">
          <div class="vr-card is-items"><small><icons name="collection" class="w-3.5 h-3.5" />{{ $t('Items') }}</small><b>{{ t.items }}</b></div>
          <div class="vr-card is-before"><small><icons name="building" class="w-3.5 h-3.5" />{{ report.labels.before_total }}</small><b>{{ money(t.before) }}</b><em>{{ qty(t.before_qty) }} {{ $t('units') }}</em></div>
          <div class="vr-card is-after"><small><icons name="tick" class="w-3.5 h-3.5" />{{ report.labels.after_total }}</small><b>{{ money(t.after) }}</b><em>{{ qty(t.after_qty) }} {{ $t('units') }}</em></div>
          <div class="vr-card" :class="cls(t.diff)"><small><icons name="adjustments" class="w-3.5 h-3.5" />{{ $t('Difference in value') }}</small><b>{{ signed(t.diff) }}</b><em>{{ signedQty(t.diff_qty) }} {{ $t('units') }}</em></div>
          <div class="vr-card is-up"><small><icons name="up" class="w-3.5 h-3.5" />{{ $t('Above') }}</small><b>{{ t.above ? '+' : '' }}{{ money(t.above) }}</b><em>{{ t.above_items }} {{ $t('items') }}</em></div>
          <div class="vr-card is-down"><small><icons name="down" class="w-3.5 h-3.5" />{{ $t('Below') }}</small><b>{{ t.below ? '-' : '' }}{{ money(t.below) }}</b><em>{{ t.below_items }} {{ $t('items') }}</em></div>
        </div>

        <p class="vr-how"><icons name="info" class="w-4 h-4" /><span>{{ report.basis }} {{ $t('The value of a line is the unit price multiplied by the quantity. The difference of a line is its value after minus its value before, and each total is the sum of the lines.') }}</span></p>

        <div class="vr-wrap">
          <table class="vr-table">
            <thead>
              <tr>
                <th class="idx">{{ $t('No') }}</th><th class="l">{{ $t('Item') }}</th><th class="l">{{ $t('Unit') }}</th><th class="n">{{ $t('Unit price') }}</th>
                <th class="n is-before">{{ report.labels.before }}</th><th class="n is-before">{{ report.labels.before_total }}</th>
                <th class="n is-after">{{ report.labels.after }}</th><th class="n is-after">{{ report.labels.after_total }}</th>
                <th class="n">{{ $t('Difference') }}</th><th class="n">{{ $t('Difference in value') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(r, i) in rows" :key="i">
                <td class="idx" :data-label="$t('No')">{{ i + 1 }}</td>
                <td class="l item" :data-label="$t('Item')"><b>{{ r.name }}</b> <span class="vr-code" v-if="r.code">({{ r.code }})</span><span v-if="sort === 'category' && r.category" class="vr-cat">{{ r.category }}</span></td>
                <td class="l" :data-label="$t('Unit')">{{ r.unit || '-' }}</td>
                <td class="n" :data-label="$t('Unit price')">{{ money(r.price) }}</td>
                <td class="n is-before" :data-label="report.labels.before">{{ qty(r.before) }}</td><td class="n is-before" :data-label="report.labels.before_total">{{ money(r.before_total) }}</td>
                <td class="n is-after" :data-label="report.labels.after">{{ qty(r.after) }}</td><td class="n is-after" :data-label="report.labels.after_total">{{ money(r.after_total) }}</td>
                <td class="n" :data-label="$t('Difference')"><span class="vr-d" :class="cls(r.diff)">{{ signedQty(r.diff) }}</span></td>
                <td class="n" :data-label="$t('Difference in value')"><span class="vr-d" :class="cls(r.diff_value)">{{ signed(r.diff_value) }}</span></td>
              </tr>
              <tr v-if="!report.rows.length"><td colspan="10" class="vr-empty">{{ $t('There is nothing to show.') }}</td></tr>
            </tbody>
            <tfoot v-if="report.rows.length">
              <tr>
                <td class="l tot" colspan="4">{{ $t('Totals') }}</td>
                <td class="n is-before" :data-label="report.labels.before">{{ qty(t.before_qty) }}</td><td class="n is-before" :data-label="report.labels.before_total">{{ money(t.before) }}</td>
                <td class="n is-after" :data-label="report.labels.after">{{ qty(t.after_qty) }}</td><td class="n is-after" :data-label="report.labels.after_total">{{ money(t.after) }}</td>
                <td class="n" :data-label="$t('Difference')"><span class="vr-d" :class="cls(t.diff_qty)">{{ signedQty(t.diff_qty) }}</span></td>
                <td class="n" :data-label="$t('Difference in value')"><span class="vr-d" :class="cls(t.diff)">{{ signed(t.diff) }}</span></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <p v-if="t.no_price" class="vr-warn"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t(':n item(s) have no unit price in the Items module, so their value is 0.', { n: t.no_price }) }}</span></p>
      </div>
    </template>

    <template #footer>
      <div class="vr-foot">
        <a v-if="printUrl" :href="printUrl" target="_blank" rel="noopener" class="lg-btn tone" style="--tone: #2563eb"><span class="tone-ico"><icons name="printer" class="w-4 h-4" /></span>{{ $t('Print') }}</a>
        <a v-if="pdfUrl" :href="pdfUrl" class="lg-btn tone" style="--tone: #dc2626"><span class="tone-ico"><icons name="doc-text" class="w-4 h-4" /></span>{{ $t('Download PDF') }}</a>
        <a v-if="excelUrl" :href="excelUrl" class="lg-btn tone" style="--tone: #059669"><span class="tone-ico"><icons name="download" class="w-4 h-4" /></span>{{ $t('Download Excel') }}</a>
        <button type="button" class="lg-btn lg-btn-soft" @click="$emit('close')">{{ $t('Close') }}</button>
      </div>
    </template>
  </tec-dialog-modal>
</template>

<script>
import { sortLines } from '@/Core/itemSort';
import TecDialogModal from '@/Jetstream/DialogModal.vue';

// Every figure comes from the server (App\Services\ValueReport): this window only shows it, so the page, the print, the PDF and the Excel always agree.
export default {
  components: { TecDialogModal },
  emits: ['close'],
  props: { show: { type: Boolean, default: false }, title: { type: String, default: '' }, subtitle: { type: String, default: '' }, report: { type: Object, default: null }, sort: { type: String, default: null }, printUrl: String, pdfUrl: String, excelUrl: String },
  computed: {
    // the order chosen on the page (the print, PDF and Excel come out in the same order)
    rows() { return this.report ? sortLines(this.report.rows, this.sort, { qty: r => r.after }) : []; },
    t() { return this.report ? this.report.totals : {}; },
    statusChip() { return { Approved: 'lg-chip-green', Rejected: 'lg-chip-red', Draft: 'lg-chip-amber', Pending: 'lg-chip-amber' }[this.report && this.report.status] || 'lg-chip-sky'; },
  },
  methods: {
    money(v) { return Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    qty(v) { return Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 4 }); },
    signed(v) { return (v > 0 ? '+' : v < 0 ? '-' : '') + this.money(Math.abs(v)); },
    signedQty(v) { return (v > 0 ? '+' : v < 0 ? '-' : '') + this.qty(Math.abs(v)); },
    cls(v) { return v > 0 ? 'is-up' : v < 0 ? 'is-down' : 'is-same'; },
  },
};
</script>

<style scoped>
.vr-title { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; } .vr-title b { display: block; font-size: 1.05rem; } .vr-title small { display: block; margin-top: 2px; font-size: 0.78rem; font-weight: 500; color: #64748b; } .vr-status { margin-left: auto; }
.vr-title__ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 40px; height: 40px; border-radius: 13px; color: #fff; background: linear-gradient(145deg, #0ea5e9, #4f46e5); box-shadow: 0 10px 16px -10px #3730a3; } .vr-title__ico > div { flex: none; margin: 0; }
.vr-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; margin-bottom: 10px; }
.vr-card { display: grid; gap: 1px; align-content: start; padding: 9px 12px; border-radius: 16px; background: #f8fafc; box-shadow: inset 0 0 0 1px #e2e8f0; border-top: 3px solid #64748b; } .vr-card small { display: flex; align-items: center; gap: 5px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: #64748b; } .vr-card small :deep(div) { flex: none; margin: 0; } .vr-card b { font-size: 1.08rem; font-variant-numeric: tabular-nums; color: #0f2540; overflow-wrap: anywhere; } .vr-card em { font-style: normal; font-size: 0.7rem; font-weight: 700; color: #64748b; }
.vr-card.is-items { border-top-color: #4f46e5; background: #eef2ff; } .vr-card.is-before { border-top-color: #0284c7; background: #f0f9ff; } .vr-card.is-after { border-top-color: #7c3aed; background: #f5f3ff; } .vr-card.is-up { border-top-color: #16a34a; background: #f0fdf4; } .vr-card.is-up b { color: #166534; } .vr-card.is-down { border-top-color: #dc2626; background: #fef2f2; } .vr-card.is-down b { color: #991b1b; } .vr-card.is-same { border-top-color: #64748b; }
.vr-how, .vr-warn { display: flex; align-items: flex-start; gap: 8px; margin: 0 0 10px; padding: 8px 12px; border-radius: 12px; font-size: 0.78rem; font-weight: 600; color: #1e40af; background: #eff6ff; box-shadow: inset 0 0 0 1px #bfdbfe; } .vr-warn { color: #92400e; background: #fffbeb; box-shadow: inset 0 0 0 1px #fde68a; margin: 10px 0 0; } .vr-how :deep(div), .vr-warn :deep(div) { flex: none; margin: 2px 0 0; }
.vr-wrap { border-radius: 14px; box-shadow: inset 0 0 0 1px #e2e8f0; overflow: hidden; }
.vr-table { width: 100%; border-collapse: collapse; table-layout: auto; font-size: 0.78rem; } .vr-table th { padding: 8px 6px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.03em; text-transform: uppercase; color: #1e3a8a; background: #dbeafe; text-align: right; } .vr-table th.l, .vr-table td.l { text-align: left; } .vr-table th.idx, .vr-table td.idx { width: 2.2rem; text-align: center; color: #94a3b8; }
.vr-table td { padding: 7px 6px; border-bottom: 1px solid #eef2f7; text-align: right; font-variant-numeric: tabular-nums; } .vr-table td.item { min-width: 11rem; } .vr-table tbody tr:nth-child(even) { background: #fafcff; }
.vr-table th.is-before, .vr-table td.is-before { background-color: rgba(2, 132, 199, 0.06); } .vr-table th.is-before { color: #075985; background: #bae6fd; } .vr-table th.is-after, .vr-table td.is-after { background-color: rgba(124, 58, 237, 0.06); } .vr-table th.is-after { color: #5b21b6; background: #ddd6fe; }
.vr-table tfoot td { padding: 9px 8px; font-weight: 800; background: #e2e8f0; border-top: 2px solid #94a3b8; } .vr-table tfoot td.tot { text-transform: uppercase; letter-spacing: 0.04em; font-size: 0.72rem; color: #334155; }
.vr-cat { display: block; margin-top: 1px; font-size: 0.68rem; font-weight: 700; color: #7c3aed; }
.vr-code { color: #94a3b8; font-weight: 500; } .vr-empty { text-align: center !important; padding: 26px; color: #64748b; }
.vr-d { display: inline-block; padding: 1px 8px; border-radius: 999px; font-weight: 800; white-space: nowrap; } .vr-d.is-up { color: #166534; background: #dcfce7; } .vr-d.is-down { color: #991b1b; background: #fee2e2; } .vr-d.is-same { color: #475569; background: #e2e8f0; }
.vr-foot { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
html.dark .vr-card { background: #1b2342; box-shadow: inset 0 0 0 1px rgba(255,255,255,0.12); } html.dark .vr-card b { color: #eef0fa; } html.dark .vr-table th { background: #26305a; color: #bfdbfe; } html.dark .vr-table td { border-bottom-color: rgba(255,255,255,0.08); color: #e2e8f0; } html.dark .vr-table tbody tr:nth-child(even) { background: rgba(255,255,255,0.03); } html.dark .vr-table tfoot td { background: #26305a; color: #e2e8f0; } html.dark .vr-wrap { box-shadow: inset 0 0 0 1px rgba(255,255,255,0.12); }
/* on a narrower screen every line becomes a small card, so nothing needs a sideways scroll */
@media (max-width: 940px) {
  .vr-table thead { display: none; }
  .vr-table, .vr-table tbody, .vr-table tfoot, .vr-table tr { display: block; width: 100%; }
  .vr-table tbody tr, .vr-table tfoot tr { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 2px 14px; padding: 10px 12px; border-bottom: 1px solid #e2e8f0; }
  .vr-table td { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 3px 0; border: 0; text-align: right !important; background: transparent !important; }
  .vr-table td::before { content: attr(data-label); flex: none; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.03em; text-transform: uppercase; color: #64748b; text-align: left; }
  .vr-table td.idx { display: none; } .vr-table td.item { grid-column: 1 / -1; min-width: 0; justify-content: flex-start; text-align: left !important; font-size: 0.9rem; } .vr-table td.item::before { display: none; }
  .vr-table tfoot td.tot { grid-column: 1 / -1; display: block; } .vr-table tfoot td.tot::before { display: none; }
  .vr-table tbody tr:nth-child(even) { background: #fafcff; }
}
@media (max-width: 520px) { .vr-table tbody tr, .vr-table tfoot tr { grid-template-columns: 1fr; } .vr-foot .lg-btn { flex: 1 1 100%; } .vr-cards { grid-template-columns: 1fr 1fr; } }
</style>

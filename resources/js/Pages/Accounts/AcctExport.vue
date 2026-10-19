<template>
  <span v-if="$can(['export-acct-transactions'])" class="sc-exports">
    <a :href="url('print')" target="_blank" rel="noopener" class="lg-btn lg-btn-soft sc-sm lg-tip" :data-tip="$t('Open the printable page in a new tab')"><icons name="printer" class="w-4 h-4" /><span>{{ $t('Print') }}</span></a>
    <a :href="url('pdf')" class="lg-btn lg-btn-soft sc-sm lg-tip" :data-tip="$t('Download as a PDF file')"><icons name="download" class="w-4 h-4" /><span>PDF</span></a>
    <a v-if="sheets" :href="url('xlsx')" class="lg-btn lg-btn-soft sc-sm lg-tip" :data-tip="$t('Download as an Excel workbook')"><icons name="download" class="w-4 h-4" /><span>Excel</span></a>
    <a v-if="sheets" :href="url('csv')" class="lg-btn lg-btn-soft sc-sm lg-tip" :data-tip="$t('Download as a CSV file')"><icons name="download" class="w-4 h-4" /><span>CSV</span></a>
  </span>
</template>

<script>
// Print / PDF / Excel / CSV of an Accounts Tracking report in the WIMS report layout; the server logs every export.
export default {
  props: { type: { type: String, required: true }, params: { type: Object, default: () => ({}) }, sheets: { type: Boolean, default: true } },
  methods: {
    url(fmt) {
      const q = { type: this.type };
      Object.keys(this.params || {}).forEach(k => { const v = this.params[k]; if (v !== '' && v !== null && v !== undefined) q[k] = v; });
      return route('acct.reports.' + fmt, q);
    },
  },
};
</script>

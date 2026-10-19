<template>
  <span v-if="allowed" class="sc-exports">
    <a :href="url('print')" target="_blank" rel="noopener" class="lg-btn lg-btn-soft t-slate sc-sm lg-tip" :data-tip="$t('Open the printable page in a new tab')"><icons name="printer" class="w-4 h-4" /><span>{{ $t('Print') }}</span></a>
    <a :href="url('pdf')" class="lg-btn lg-btn-soft t-teal sc-sm lg-tip" :data-tip="$t('Download as a PDF file')"><icons name="download" class="w-4 h-4" /><span>PDF</span></a>
    <a v-if="sheets" :href="url('xlsx')" class="lg-btn lg-btn-soft t-teal sc-sm lg-tip" :data-tip="$t('Download as an Excel workbook')"><icons name="download" class="w-4 h-4" /><span>Excel</span></a>
    <a v-if="sheets" :href="url('csv')" class="lg-btn lg-btn-soft t-teal sc-sm lg-tip" :data-tip="$t('Download as a CSV file')"><icons name="download" class="w-4 h-4" /><span>CSV</span></a>
  </span>
</template>

<script>
// Print / PDF / Excel / CSV of a subcontract report in the WIMS report layout. Every export is recorded in the activity log by the server.
// `type` is a report of Subcontracts > Reports; `params` are its filters (the current filters of the page).
export default {
  props: { type: { type: String, required: true }, params: { type: Object, default: () => ({}) }, sheets: { type: Boolean, default: true }, needsMoney: { type: Boolean, default: false }, money: { type: Boolean, default: true } },
  computed: { allowed() { return (!this.needsMoney || this.money) && this.$can(['print-subcontract-reports']); } },
  methods: {
    url(fmt) {
      const q = { type: this.type };
      Object.keys(this.params || {}).forEach(k => { const v = this.params[k]; if (v !== '' && v !== null && v !== undefined) q[k] = v; });
      return route('subcontracts.reports.' + fmt, q);
    },
  },
};
</script>

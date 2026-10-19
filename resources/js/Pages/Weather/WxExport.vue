<template>
  <span v-if="$can(['print-weather-reports'])" class="sc-exports">
    <a v-if="project" :href="url('print')" target="_blank" rel="noopener" class="lg-btn lg-btn-soft sc-sm lg-tip" :data-tip="$t('Open the printable Weather & Site Risk report for this project and period')"><icons name="printer" class="w-4 h-4" /><span>{{ $t('Print') }}</span></a>
    <a v-if="project" :href="url('pdf')" class="lg-btn lg-btn-soft sc-sm lg-tip" :data-tip="$t('Download the report as a PDF file')"><icons name="download" class="w-4 h-4" /><span>PDF</span></a>
    <Link v-else :href="route('weather.reports.index')" class="lg-btn lg-btn-soft sc-sm lg-tip" :data-tip="$t('Choose a project above to print or download its report, or open the Reports page')"><icons name="printer" class="w-4 h-4" /><span>{{ $t('Print / Download') }}</span></Link>
  </span>
</template>

<script>
import { Link } from '@inertiajs/vue3';

// Print / PDF of the Weather & Site Risk report for the project and period chosen on a list page. The server logs every export.
export default {
  components: { Link },
  props: { project: { type: [String, Number], default: '' }, from: { type: String, default: '' }, to: { type: String, default: '' } },
  methods: {
    url(kind) {
      const pad = n => String(n).padStart(2, '0'); const fmt = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
      const end = new Date(); const start = new Date(); start.setDate(start.getDate() - 29);
      return route('weather.reports.' + kind) + '?' + new URLSearchParams({ project: this.project, from: this.from || fmt(start), to: this.to || fmt(end) }).toString();
    },
  },
};
</script>

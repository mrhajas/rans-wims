<template>
  <admin-layout :title="$t('Material Used')">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="chart-bar"
        :title="$t('Material Used')"
        :subtitle="$t('Top 10 used materials from checkout transactions by selected date range')"
      >
        <button type="button" class="lg-btn lg-btn-dark" @click="openPrint">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </button>
      </page-header>

      <stat-tiles :items="statTiles" />

      <section class="lg-card mu-filters print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="filter" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Filters') }}</h2>
            <p class="lg-card-sub">{{ $t('From Date') }} / {{ $t('To Date') }}</p>
          </div>
        </header>
        <form @submit.prevent="applyFilters">
          <div class="lg-fields">
            <date-picker v-model="form.start_date" :label="$t('From Date')" clearable />
            <date-picker v-model="form.end_date" :label="$t('To Date')" clearable />
          </div>
          <div class="mu-btns">
            <button type="submit" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Apply') }}</button>
            <button type="button" class="lg-btn lg-btn-soft" @click="resetFilters"><icons name="cross" class="w-4 h-4" />{{ $t('Reset') }}</button>
          </div>
        </form>
      </section>

      <section class="lg-card print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="chart-bar" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Bar Graph') }}</h2>
          </div>
        </header>
        <div v-if="rows.length" class="mu-chart overflow-x-auto">
          <vue-highcharts type="chart" :options="chartOptions" :redrawOnUpdate="true" :oneToOneUpdate="false" :animateOnUpdate="true" style="min-width: 480px" />
        </div>
        <p v-else class="lg-empty" style="padding: 24px 12px !important">{{ $t('There is no data to display.') }}</p>
      </section>

      <!-- Desktop / tablet: table -->
      <section class="lg-card hidden md:block">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="table" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Material Used') }}</h2>
          </div>
          <span class="lg-chip lg-chip-amber" style="text-transform: none">{{ rows.length }} {{ $t('Material') }}</span>
        </header>
        <div class="lg-table-wrap">
          <table v-labelize class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Material') }}</th>
                <th class="num">{{ $t('Total Used') }}</th>
                <th v-for="warehouse in warehouses" :key="warehouse.id" class="num">{{ warehouse.name }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in rows" :key="row.item_id">
                <td class="mu-name">
                  <div class="flex items-center gap-2">
                    <span class="txn-rank-badge flex-none" :class="rankClass(index)">{{ index + 1 }}</span>
                    <span class="lg-main">{{ row.item_name }}</span>
                  </div>
                </td>
                <td class="num lg-main">{{ fmt(row.total_quantity) }} <span v-if="row.unit" class="mu-unit">{{ row.unit }}</span></td>
                <td v-for="site in row.per_site" :key="`${row.item_id}-${site.warehouse_id}`" class="num" :class="{ 'mu-zero': !Number(site.quantity) }">
                  {{ fmt(site.quantity) }} <span v-if="row.unit && site.quantity" class="mu-unit">{{ row.unit }}</span>
                </td>
              </tr>
              <tr v-if="rows.length === 0" class="lg-empty-row">
                <td class="lg-empty" :colspan="warehouses.length + 2">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Mobile: cards -->
      <div class="md:hidden space-y-3 relative z-[1]">
        <div v-for="(row, index) in rows" :key="`m-${row.item_id}`" class="txn-material-card">
          <div class="flex items-center justify-between gap-3">
            <span class="inline-flex items-center gap-2 min-w-0">
              <span class="txn-rank-badge flex-none" :class="rankClass(index)">{{ index + 1 }}</span>
              <span class="lg-main truncate">{{ row.item_name }}</span>
            </span>
            <span class="lg-main flex-none">{{ fmt(row.total_quantity) }} <span v-if="row.unit" class="mu-unit">{{ row.unit }}</span></span>
          </div>
          <div v-if="usedSites(row).length" class="mt-2 flex flex-wrap gap-1">
            <span v-for="site in usedSites(row)" :key="`m-${row.item_id}-${site.warehouse_id}`" class="lg-chip lg-chip-indigo" style="text-transform: none">
              {{ warehouseName(site.warehouse_id) }}: {{ fmt(site.quantity) }}<template v-if="row.unit"> {{ row.unit }}</template>
            </span>
          </div>
        </div>
        <div v-if="rows.length === 0" class="txn-material-card lg-empty">{{ $t('There is no data to display.') }}</div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import VueHighcharts from 'vue3-highcharts';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import DatePicker from '@/Shared/DatePicker.vue';

const PALETTE = ['#4f46e5', '#7c3aed', '#0284c7', '#059669', '#d97706', '#db2777', '#0891b2', '#dc2626'];

export default {
  components: {
    AdminLayout,
    PageHeader,
    StatTiles,
    VueHighcharts,
    DatePicker,
  },

  props: {
    filters: Object,
    warehouses: Array,
    rows: Array,
  },

  data() {
    return {
      form: {
        start_date: this.filters?.start_date || '',
        end_date: this.filters?.end_date || '',
      },
    };
  },

  computed: {
    statTiles() {
      const top = (this.rows || [])[0];
      const total = (this.rows || []).reduce((sum, r) => sum + (Number(r.total_quantity) || 0), 0);
      return [
        { icon: 'collection', tone: 'indigo', label: this.$t('Material'), value: (this.rows || []).length },
        { icon: 'chart-bar', tone: 'amber', label: this.$t('Total Used'), value: this.fmt(total) },
        { icon: 'building', tone: 'sky', label: this.$t('Warehouse'), value: (this.warehouses || []).length },
        ...(top ? [{ icon: 'sparkles', tone: 'emerald', label: top.item_name, value: this.fmt(top.total_quantity) }] : []),
      ];
    },
    chartOptions() {
      const sorted = [...this.rows].slice(0, 10);
      const vm = this;
      return {
        chart: {
          type: 'bar',
          spacingTop: 10,
          style: { fontFamily: "'WIMS Avenir', 'Avenir Next', Avenir, Montserrat, sans-serif" },
          height: Math.max(220, sorted.length * 36),
        },
        credits: { enabled: false },
        title: { text: undefined },
        colors: PALETTE,
        xAxis: {
          categories: sorted.map(r => r.item_name),
          labels: { style: { fontSize: '11px' } },
        },
        yAxis: {
          min: 0,
          title: { text: undefined },
        },
        legend: { enabled: false },
        tooltip: {
          shadow: false,
          useHTML: true,
          borderRadius: 8,
          borderColor: '#1F2937',
          style: { color: '#fff' },
          backgroundColor: '#1F2937',
          formatter() {
            return `<div style="min-width:150px"><div style="font-weight:700;margin-bottom:2px">${this.point.category}</div><div>${vm.$t('Total Used')}: ${vm.fmt(this.point.y)}</div></div>`;
          },
        },
        plotOptions: {
          series: {
            animation: { duration: 900 },
            colorByPoint: true,
            borderRadius: 4,
            dataLabels: {
              enabled: true,
              formatter() {
                return vm.fmt(this.y);
              },
              style: { fontSize: '10px', fontWeight: '600', textOutline: 'none', color: '#475569' },
            },
          },
        },
        series: [{ name: this.$t('Total Used'), data: sorted.map(r => Number(r.total_quantity) || 0) }],
      };
    },
  },

  methods: {
    rankClass(index) {
      if (index === 0) return 'txn-rank-gold';
      if (index === 1) return 'txn-rank-silver';
      if (index === 2) return 'txn-rank-bronze';
      return 'txn-rank-default';
    },
    warehouseName(id) {
      const wh = (this.warehouses || []).find(w => w.id === id);
      return wh ? wh.name : '-';
    },
    usedSites(row) {
      return (row.per_site || []).filter(site => Number(site.quantity) > 0);
    },
    currentFilters(output = null) {
      const params = {
        start_date: this.form.start_date,
        end_date: this.form.end_date,
      };
      if (output) {
        params.output = output;
      }
      return params;
    },
    applyFilters() {
      this.$inertia.get(
        this.route('reports.most_used_material'),
        this.currentFilters(),
        {
          preserveState: true,
          replace: true,
        }
      );
    },
    openPrint() {
      window.open(this.route('reports.most_used_material', this.currentFilters('print')), '_blank');
    },
    downloadPdf() {
      window.open(this.route('reports.most_used_material', this.currentFilters('pdf')), '_blank');
    },
    resetFilters() {
      this.form.start_date = '';
      this.form.end_date = '';
      this.$inertia.get(this.route('reports.most_used_material'), {}, { preserveState: false, replace: true });
    },
    fmt(value) {
      return this.$number(Number(value) || 0);
    },
  },
};
</script>

<style scoped>
.fx-label {
  display: block;
}
.fx-form :deep(label:not(:has(input[type='checkbox']))) {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
}
.fx-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):not([type='hidden'])) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
}
.mu-filters {
  position: relative;
  z-index: 20;
}
.mu-btns {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 1rem;
}
.mu-chart {
  padding: 0.5rem;
  border-radius: 16px;
  border: 1px solid var(--lg-line);
  background: var(--lg-soft);
}
.mu-name {
  max-width: 24rem;
  white-space: normal;
}
.mu-unit {
  font-size: 0.72rem;
  font-weight: 500;
  color: var(--lg-faint);
}
.mu-zero {
  color: var(--lg-faint);
}

.txn-rank-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 1.65rem;
  min-width: 1.65rem;
  flex-shrink: 0;
  padding: 0 0.35rem;
  border-radius: 9999px;
  font-size: 0.72rem;
  font-weight: 700;
  color: #fff;
  line-height: 1;
}
.txn-rank-gold { background: linear-gradient(145deg, #fbbf24, #b45309); }
.txn-rank-silver { background: linear-gradient(145deg, #cbd5e1, #64748b); }
.txn-rank-bronze { background: linear-gradient(145deg, #fb923c, #c2410c); }
.txn-rank-default { background: linear-gradient(145deg, #818cf8, #4338ca); }

.txn-material-card {
  padding: 1rem;
  border-radius: 1rem;
  border: 1px solid var(--lg-line);
  background: var(--lg-card);
  box-shadow: var(--lg-shadow);
}
@media (max-width: 767.98px) {
  .mu-btns .lg-btn {
    flex: 1 1 auto;
  }
}
</style>

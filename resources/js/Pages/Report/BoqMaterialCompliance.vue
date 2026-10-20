<template>
  <admin-layout :title="$t('BOQ Material Compliance')">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="collection"
        :title="$t('BOQ Material Compliance')"
        :subtitle="$t('Track BOQ baseline vs actual issued quantity and cost')"
      >
        <button type="button" class="lg-btn lg-btn-soft" @click="openPrint"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</button>
        <button type="button" class="lg-btn lg-btn-red" @click="downloadPdf"><icons name="doc" class="w-4 h-4" />{{ $t('PDF') }}</button>
        <button type="button" class="lg-btn lg-btn-green" @click="downloadExcel"><icons name="download" class="w-4 h-4" />{{ $t('Excel') }}</button>
      </page-header>

      <stat-tiles :items="statTiles" />

      <section class="lg-card rb-filters print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="filter" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Filters') }}</h2>
            <p class="lg-card-sub">{{ $t('Project / Site') }} / {{ $t('Date From') }} / {{ $t('Date To') }}</p>
          </div>
        </header>
        <form @submit.prevent="applyFilters">
          <div class="lg-fields">
            <div class="rb-project">
              <label class="fx-label">
                <span class="inline-flex items-center gap-1"><icons name="building" class="h-4 w-4" />{{ $t('Project / Site') }}</span>
              </label>
              <auto-complete
                v-model="form.project_warehouse_id"
                :suggestions="projects"
                label-key="name"
                value-key="id"
                :placeholder="$t('Search project / site')"
                :clearable="true"
                :absolute-dropdown="false"
              />
            </div>
            <div>
              <label class="fx-label">{{ $t('Date From') }}</label>
              <date-picker v-model="form.start_date" clearable />
            </div>
            <div>
              <label class="fx-label">{{ $t('Date To') }}</label>
              <date-picker v-model="form.end_date" clearable />
            </div>
          </div>
          <div class="rb-btns">
            <button type="submit" class="lg-btn lg-btn-primary"><icons name="tick" class="w-4 h-4" />{{ $t('Generate') }}</button>
            <button type="button" class="lg-btn lg-btn-soft" @click="resetFilters"><icons name="cross" class="w-4 h-4" />{{ $t('Reset') }}</button>
          </div>
        </form>
      </section>

      <section class="lg-card print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="chart-bar" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Status Distribution') }} / {{ $t('Top Variance by Cost') }}</h2>
          </div>
        </header>
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
          <div class="rb-chart">
            <h3 class="rb-chart-title">{{ $t('Status Distribution') }}</h3>
            <vue-highcharts type="chart" :options="statusChartOptions" :redrawOnUpdate="true" :oneToOneUpdate="false" />
          </div>
          <div class="rb-chart">
            <h3 class="rb-chart-title">{{ $t('Top Variance by Cost') }}</h3>
            <vue-highcharts type="chart" :options="varianceChartOptions" :redrawOnUpdate="true" :oneToOneUpdate="false" />
          </div>
        </div>
      </section>

      <section class="lg-card rb-add print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="plus" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Add Material') }}</h2>
          </div>
        </header>
        <div class="lg-fields">
          <div>
            <label class="fx-label">{{ $t('Add Material') }}</label>
            <auto-complete
              v-model="selectedMaterialId"
              :suggestions="availableMaterialOptions"
              label-key="name"
              value-key="id"
              :placeholder="form.project_warehouse_id ? $t('Search material to add') : $t('Select project / site first')"
              :clearable="true"
              :absolute-dropdown="false"
            />
          </div>
          <div>
            <label class="fx-label">{{ $t('Search') }}</label>
            <div class="relative">
              <icons name="search" class="h-4 w-4 rb-search-ico" />
              <input v-model="form.search" type="text" class="rb-input rb-search" :placeholder="$t('Search by material, status, or remarks')" />
            </div>
          </div>
        </div>
        <div class="rb-btns">
          <button type="button" class="lg-btn lg-btn-soft" @click="addMaterial"><icons name="plus" class="w-4 h-4" />{{ $t('Add to Table') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" @click="saveBaseline"><icons name="tick" class="w-4 h-4" />{{ $t('Save Baseline') }}</button>
        </div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="table" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('BOQ Material Compliance') }}</h2>
          </div>
          <span class="lg-chip lg-chip-amber" style="text-transform: none">{{ filteredRows.length }} {{ $t('Materials') }}</span>
        </header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th class="num">{{ $t('No') }}</th>
                <th>{{ $t('Material') }}</th>
                <th>{{ $t('Unit') }}</th>
                <th class="num">{{ $t('BOQ Qty') }}</th>
                <th class="num">{{ $t('Issued Qty') }}</th>
                <th class="num">{{ $t('Variance Qty') }}</th>
                <th class="num">{{ $t('Compliance %') }}</th>
                <th class="num">{{ $t('Unit Price') }}</th>
                <th class="num">{{ $t('BOQ Cost') }}</th>
                <th class="num">{{ $t('Issued Cost') }}</th>
                <th class="num">{{ $t('Variance Cost') }}</th>
                <th>{{ $t('Status') }}</th>
                <th>{{ $t('Remarks') }}</th>
                <th class="print:hidden">{{ $t('Action') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in filteredRows" :key="row.item_id">
                <td :data-label="$t('No')" class="num" style="color: var(--lg-faint)">{{ index + 1 }}</td>
                <td :data-label="$t('Material')" class="rb-wrap">
                  <a
                    v-if="row.item_id"
                    :href="route('items.show', row.item_id)"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="rb-link lg-main"
                  >
                    {{ row.item_name }}
                  </a>
                  <span v-else class="lg-main">{{ row.item_name }}</span>
                </td>
                <td :data-label="$t('Unit')">{{ row.unit }}</td>
                <td :data-label="$t('BOQ Qty')" class="num">
                  <input v-model.number="row.boq_quantity" type="number" min="0" step="0.01" class="rb-input rb-num" @input="recalculateRow(row)" />
                </td>
                <td :data-label="$t('Issued Qty')" class="num">{{ formatQty(row.issued_quantity) }}</td>
                <td :data-label="$t('Variance Qty')" class="num" :class="row.variance_quantity > 0 ? 'rb-bad' : 'rb-good'">{{ formatQty(row.variance_quantity) }}</td>
                <td :data-label="$t('Compliance %')" class="num">{{ formatPercent(row.consumption_percent) }}</td>
                <td :data-label="$t('Unit Price')" class="num">
                  <input v-model.number="row.unit_price" type="number" min="0" step="0.01" class="rb-input rb-num" @input="recalculateRow(row)" />
                </td>
                <td :data-label="$t('BOQ Cost')" class="num">{{ formatMoney(row.boq_total_cost) }}</td>
                <td :data-label="$t('Issued Cost')" class="num">{{ formatMoney(row.issued_total_cost) }}</td>
                <td :data-label="$t('Variance Cost')" class="num" :class="row.variance_cost > 0 ? 'rb-bad' : 'rb-good'">{{ formatMoney(row.variance_cost) }}</td>
                <td :data-label="$t('Status')">
                  <span class="lg-chip" :class="statusClass(row.status)" style="text-transform: none">{{ row.status }}</span>
                </td>
                <td :data-label="$t('Remarks')">
                  <input v-model="row.remarks" type="text" class="rb-input rb-remarks" :placeholder="$t('Optional note')" />
                </td>
                <td :data-label="$t('Action')" class="print:hidden">
                  <button type="button" class="lg-btn lg-btn-soft rb-remove" @click="removeRow(row.item_id)">
                    <icons name="trash" class="w-3 h-3" />{{ $t('Remove') }}
                  </button>
                </td>
              </tr>
              <tr v-if="filteredRows.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="14">{{ $t('Select project and add materials to begin BOQ compliance analysis.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <div class="lg-note lg-note-amber mb-4">
        <icons name="exclamation" class="w-4 h-4" />
        <p>
          <strong>{{ $t('Important') }}:</strong>
          {{ $t('To keep compliance and cost results accurate, enter BOQ Qty for every listed material. Missing BOQ Qty values can distort compliance percentages and variance calculations.') }}
        </p>
      </div>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="sparkles" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Insights') }}</h2>
          </div>
        </header>
        <div class="rb-insights">
          <p><span class="lg-chip lg-chip-green" style="text-transform: none">{{ $t('On Track') }}</span> <strong>{{ statusCount('On Track') }}</strong></p>
          <p><span class="lg-chip lg-chip-cyan" style="text-transform: none">{{ $t('Under Usage') }}</span> <strong>{{ statusCount('Under Usage') }}</strong></p>
          <p><span class="lg-chip lg-chip-amber" style="text-transform: none">{{ $t('Slight Overuse') }}</span> <strong>{{ statusCount('Slight Overuse') }}</strong></p>
          <p><span class="lg-chip lg-chip-red" style="text-transform: none">{{ $t('Overused') }}</span> <strong>{{ statusCount('Overused') }}</strong></p>
          <p><span class="lg-chip lg-chip-slate" style="text-transform: none">{{ $t('Not Set') }}</span> <strong>{{ statusCount('Not Set') }}</strong></p>
        </div>
        <div class="rb-insight-lines">
          <template v-if="canViewManagementInsights">
            <p>{{ $t('Materials With BOQ Set') }}: <strong>{{ overview.materials_with_boq_count || 0 }}</strong></p>
            <p>{{ $t('Project Cost vs BOQ') }}: <strong>{{ formatMoney(overview.project_cost) }}</strong> / <strong>{{ formatMoney(overview.total_boq_cost) }}</strong></p>
            <p>{{ $t('Cost Variance') }}: <strong :class="overview.total_variance_cost > 0 ? 'rb-bad' : 'rb-good'">{{ formatMoney(overview.total_variance_cost) }}</strong></p>
          </template>
          <p>{{ $t('System Note') }}: {{ smartNote }}</p>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import VueHighcharts from 'vue3-highcharts';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';

export default {
  components: {
    AdminLayout,
    PageHeader,
    StatTiles,
    VueHighcharts,
    AutoComplete,
    DatePicker,
  },

  props: {
    filters: { type: Object, default: () => ({}) },
    projects: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    overview: { type: Object, default: () => ({}) },
    chart: { type: Object, default: () => ({}) },
    materialOptions: { type: Array, default: () => [] },
    canViewManagementInsights: { type: Boolean, default: false },
  },

  data() {
    return {
      form: {
        project_warehouse_id: this.filters.project_warehouse_id ? String(this.filters.project_warehouse_id) : '',
        start_date: this.filters.start_date || '',
        end_date: this.filters.end_date || '',
        search: this.filters.search || '',
      },
      selectedMaterialId: '',
      localRows: this.rows.map((row) => ({ ...row })),
    };
  },

  computed: {
    statTiles() {
      const o = this.overview || {};
      const tiles = [
        { icon: 'collection', tone: 'sky', label: this.$t('Materials'), value: this.rows.length },
        { icon: 'tick', tone: 'emerald', label: this.$t('Overall Compliance'), value: this.formatPercent(o.overall_compliance_percent) },
        { icon: 'calculator', tone: 'indigo', label: this.$t('Total BOQ Cost'), value: this.formatMoney(o.total_boq_cost) },
      ];
      if (this.canViewManagementInsights) {
        tiles.push({ icon: 'cash', tone: 'navy', label: this.$t('Project Cost'), value: this.formatMoney(o.project_cost) });
      }
      tiles.push({
        icon: 'exclamation',
        tone: o.total_variance_cost > 0 ? 'rose' : 'emerald',
        label: this.$t('Variance Cost'),
        value: this.formatMoney(o.total_variance_cost),
        valueClass: o.total_variance_cost > 0 ? 'is-bad' : 'is-good',
      });
      if (this.canViewManagementInsights) {
        tiles.push({ icon: 'chart-pie', tone: 'violet', label: this.$t('Budget Utilization'), value: this.formatPercent(o.budget_utilization_percent) });
        tiles.push({
          icon: 'chart-bar',
          tone: o.cost_variance_percent > 0 ? 'amber' : 'teal',
          label: this.$t('Cost Variance %'),
          value: this.formatPercent(o.cost_variance_percent),
          valueClass: o.cost_variance_percent > 0 ? 'is-bad' : 'is-good',
        });
      }
      return tiles;
    },
    filteredRows() {
      const needle = String(this.form.search || '').trim().toLowerCase();
      if (!needle) {
        return this.localRows;
      }

      return this.localRows.filter((row) => {
        const material = String(row.item_name || '').toLowerCase();
        const status = String(row.status || '').toLowerCase();
        const remarks = String(row.remarks || '').toLowerCase();
        return material.includes(needle) || status.includes(needle) || remarks.includes(needle);
      });
    },
    selectedIds() {
      return this.localRows.map((row) => Number(row.item_id));
    },
    availableMaterialOptions() {
      if (!this.form.project_warehouse_id) {
        return [];
      }
      return this.materialOptions.filter((option) => !this.selectedIds.includes(Number(option.id)));
    },
    statusChartOptions() {
      return {
        chart: { type: 'column', style: { fontFamily: 'Nunito' } },
        title: { text: '' },
        credits: { enabled: false },
        xAxis: { categories: this.chart.status_labels || [] },
        yAxis: { title: { text: this.$t('Count') }, allowDecimals: false },
        colors: ['#10b981', '#0ea5e9', '#f59e0b', '#ef4444', '#64748b'],
        series: [{ name: this.$t('Materials'), data: this.chart.status_series || [] }],
      };
    },
    varianceChartOptions() {
      const labels = (this.chart.top_variance_materials || []).map((row) => row.name);
      const data = (this.chart.top_variance_materials || []).map((row) => Number(row.value || 0));
      return {
        chart: { type: 'bar', style: { fontFamily: 'Nunito' } },
        title: { text: '' },
        credits: { enabled: false },
        xAxis: { categories: labels },
        yAxis: { title: { text: this.$t('Variance Cost') } },
        colors: ['#f97316'],
        series: [{ name: this.$t('Variance'), data }],
      };
    },
    smartNote() {
      const overused = this.statusCount('Overused');
      const under = this.statusCount('Under Usage');
      if (overused >= 3) {
        return this.$t('High risk: multiple materials exceeded BOQ significantly. Prioritize immediate quantity controls and approvals.');
      }
      if (under >= 3) {
        return this.$t('Trend indicates slow utilization against BOQ. Verify schedule readiness and demand timing.');
      }
      return this.$t('Overall trend is stable. Keep tracking top variance items weekly for early correction.');
    },
  },

  watch: {
    rows: {
      handler(newRows) {
        this.localRows = (newRows || []).map((row) => ({ ...row }));
      },
      deep: true,
    },
    'form.project_warehouse_id'(newValue, oldValue) {
      if (newValue !== oldValue) {
        this.selectedMaterialId = '';
        if (newValue) {
          this.applyFilters();
        } else {
          this.localRows = [];
        }
      }
    },
  },

  methods: {
    async applyFilters() {
      if (!this.form.project_warehouse_id) {
        await this.$alert(this.$t('Please select Project / Site first.'));
        return;
      }

      this.$inertia.get(this.route('reports.boq_material_compliance'), {
        project_warehouse_id: this.form.project_warehouse_id || undefined,
        start_date: this.form.start_date || undefined,
        end_date: this.form.end_date || undefined,
        search: this.form.search || undefined,
      }, {
        preserveState: true,
        replace: true,
      });
    },
    resetFilters() {
      this.form = {
        project_warehouse_id: '',
        start_date: '',
        end_date: '',
        search: '',
      };
      this.localRows = [];
      this.$inertia.get(this.route('reports.boq_material_compliance'), {}, { preserveState: true, replace: true });
    },
    statusCount(name) {
      return this.localRows.filter((row) => row.status === name).length;
    },
    async addMaterial() {
      if (!this.form.project_warehouse_id) {
        await this.$alert(this.$t('Please select Project / Site first.'));
        return;
      }
      if (!this.selectedMaterialId) {
        await this.$alert(this.$t('Please select Item / Material first.'));
        return;
      }
      const id = Number(this.selectedMaterialId);
      if (this.selectedIds.includes(id)) {
        await this.$alert(this.$t('This Item / Material is already in the table.'));
        return;
      }
      const option = this.materialOptions.find((item) => Number(item.id) === id);
      if (!option) {
        return;
      }

      const fallbackRow = {
        item_id: id,
        item_name: option.name,
        unit: '-',
        issued_quantity: 0,
        boq_quantity: 0,
        variance_quantity: 0,
        consumption_percent: 0,
        unit_price: 0,
        boq_total_cost: 0,
        issued_total_cost: 0,
        variance_cost: 0,
        status: 'Not Set',
        remarks: '',
      };

      try {
        const response = await axios.get(this.route('reports.boq_material_compliance'), {
          params: {
            json: 1,
            project_warehouse_id: this.form.project_warehouse_id || undefined,
            start_date: this.form.start_date || undefined,
            end_date: this.form.end_date || undefined,
            additional_item_ids: [id],
          },
        });

        const serverRows = Array.isArray(response?.data?.rows) ? response.data.rows : [];
        const refreshed = serverRows.find((row) => Number(row.item_id) === id);
        this.localRows = [...this.localRows, refreshed ? { ...fallbackRow, ...refreshed } : fallbackRow];
      } catch (error) {
        this.localRows = [...this.localRows, fallbackRow];
      }

      this.selectedMaterialId = '';
    },
    removeRow(itemId) {
      this.localRows = this.localRows.filter((row) => Number(row.item_id) !== Number(itemId));
    },
    recalculateRow(row) {
      const boq = Number(row.boq_quantity || 0);
      const issued = Number(row.issued_quantity || 0);
      const price = Number(row.unit_price || 0);

      row.boq_total_cost = boq * price;
      row.issued_total_cost = issued * price;
      row.variance_quantity = issued - boq;
      row.variance_cost = row.issued_total_cost - row.boq_total_cost;
      row.consumption_percent = boq > 0 ? (issued / boq) * 100 : 0;
      row.status = this.computeStatus(issued, boq);
    },
    computeStatus(issued, boq) {
      if (boq <= 0) {
        return 'Not Set';
      }
      const ratio = issued / boq;
      if (ratio < 0.8) {
        return 'Under Usage';
      }
      if (ratio <= 1.0) {
        return 'On Track';
      }
      if (ratio <= 1.1) {
        return 'Slight Overuse';
      }
      return 'Overused';
    },
    async saveBaseline() {
      if (!this.form.project_warehouse_id) {
        await this.$alert(this.$t('Please select Project / Site first.'));
        return;
      }

      if (!this.localRows.length) {
        await this.$alert(this.$t('Please add at least one Item / Material first.'));
        return;
      }

      this.$inertia.post(this.route('reports.boq_material_compliance.save'), {
        project_warehouse_id: Number(this.form.project_warehouse_id),
        materials: this.localRows.map((row) => ({
          item_id: Number(row.item_id),
          boq_quantity: Number(row.boq_quantity || 0),
          unit_price: Number(row.unit_price || 0),
          remarks: row.remarks || '',
        })),
      }, {
        preserveScroll: true,
        onSuccess: () => {
          this.applyFilters();
        },
      });
    },
    reportParams(output) {
      return {
        project_warehouse_id: this.form.project_warehouse_id || undefined,
        start_date: this.form.start_date || undefined,
        end_date: this.form.end_date || undefined,
        search: this.form.search || undefined,
        output,
      };
    },
    async openPrint() {
      if (!this.form.project_warehouse_id) {
        await this.$alert(this.$t('Please select Project / Site first.'));
        return;
      }
      window.open(this.route('reports.boq_material_compliance', this.reportParams('print')), '_blank');
    },
    async downloadPdf() {
      if (!this.form.project_warehouse_id) {
        await this.$alert(this.$t('Please select Project / Site first.'));
        return;
      }
      window.open(this.route('reports.boq_material_compliance', this.reportParams('pdf')), '_blank');
    },
    async downloadExcel() {
      if (!this.form.project_warehouse_id) {
        await this.$alert(this.$t('Please select Project / Site first.'));
        return;
      }
      window.open(this.route('reports.boq_material_compliance', this.reportParams('excel')), '_blank');
    },
    formatQty(value) {
      return Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    formatMoney(value) {
      return Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    formatPercent(value) {
      return `${Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
    },
    statusClass(status) {
      const classes = {
        'On Track': 'lg-chip-green',
        'Under Usage': 'lg-chip-cyan',
        'Slight Overuse': 'lg-chip-amber',
        'Overused': 'lg-chip-red',
        'Not Set': 'lg-chip-slate',
      };
      return classes[status] || classes['Not Set'];
    },
  },
};
</script>

<style scoped>
.fx-label {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
  margin-bottom: 0.25rem;
}
.fx-form :deep(label:not(:has(input[type='checkbox']))) {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
}
.fx-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):not([type='hidden'])),
.rb-input {
  border-radius: 0.85rem !important;
  border: 1px solid var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
}
.rb-input {
  display: block;
  padding: 0.45rem 0.7rem;
  font-size: 0.83rem;
}
.rb-input:focus {
  outline: none;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
  border-color: #60a5fa !important;
}
.rb-num {
  width: 7rem;
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.rb-remarks {
  width: 11rem;
}
.rb-search {
  width: 100%;
  padding-left: 2.2rem;
}
.rb-search-ico {
  position: absolute;
  left: 0.8rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--lg-faint);
  z-index: 1;
}
.rb-filters {
  position: relative;
  z-index: 40;
}
.rb-add {
  position: relative;
  z-index: 30;
}
.rb-btns {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 1rem;
}
.rb-chart {
  min-width: 0;
  padding: 0.75rem;
  border-radius: 16px;
  border: 1px solid var(--lg-line);
  background: var(--lg-soft);
}
.rb-chart-title {
  margin-bottom: 0.5rem;
  font-size: 0.82rem;
  font-weight: 800;
  color: var(--lg-ink);
}
.rb-link {
  color: var(--lg-navy);
}
.rb-link:hover {
  text-decoration: underline;
}
html.dark .rb-link {
  color: #93c5fd;
}
.rb-wrap {
  white-space: normal;
}
.rb-good {
  color: #047857;
}
.rb-bad {
  color: #be123c;
  font-weight: 700;
}
html.dark .rb-good {
  color: #34d399;
}
html.dark .rb-bad {
  color: #fb7185;
}
.rb-remove {
  padding: 4px 10px;
  font-size: 0.72rem;
  color: #be123c;
}
.rb-insights {
  display: flex;
  flex-wrap: wrap;
  gap: 10px 22px;
  font-size: 0.85rem;
  color: var(--lg-ink);
}
.rb-insights p {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.rb-insight-lines {
  margin-top: 0.85rem;
  display: grid;
  gap: 4px;
  font-size: 0.83rem;
  color: var(--lg-mute);
}
.rb-insight-lines strong {
  color: var(--lg-ink);
}
.rb-insight-lines strong.rb-bad {
  color: #be123c;
}
.rb-insight-lines strong.rb-good {
  color: #047857;
}
@media (max-width: 767.98px) {
  .rb-btns .lg-btn {
    flex: 1 1 auto;
  }
  .rb-num,
  .rb-remarks {
    width: 100%;
    max-width: 11rem;
  }
}
</style>

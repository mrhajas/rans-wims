<template>
  <admin-layout :title="$t('Issue & Return Reports')">
    <div class="lg-page px-4 md:px-0 irr-page">
      <page-header icon="chart-bar" :title="$t('Issue & Return Reports')" :subtitle="$t('Admin reports with search, filters, print, PDF, and Excel.')">
        <button type="button" class="lg-btn lg-btn-green" @click="open('print')"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</button>
        <button type="button" class="lg-btn lg-btn-dark" @click="open('pdf')"><icons name="doc-text" class="w-4 h-4" />{{ $t('PDF') }}</button>
        <button type="button" class="lg-btn lg-btn-soft" @click="open('excel')"><icons name="download" class="w-4 h-4" />{{ $t('Excel') }}</button>
      </page-header>

      <section class="lg-card irr-filter-card relative z-20 print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="adjustments" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Filters') }}</h2></div>
        </header>
        <div class="lg-fields">
          <select-input v-model="form.report_type" :label="$t('Report Type')">
            <option value="issue">{{ $t('Issue Report') }}</option>
            <option value="return">{{ $t('Return Report') }}</option>
            <option value="fully_returned">{{ $t('Fully Returned Report') }}</option>
            <option value="partially_returned">{{ $t('Partially Returned Report') }}</option>
            <option value="by_employee">{{ $t('Issued Items by Employee') }}</option>
            <option value="by_warehouse">{{ $t('Issued Items by Project(s) / Warehouse(s)') }}</option>
            <option value="item_history">{{ $t('Item History Report') }}</option>
          </select-input>

          <text-input v-model="form.start_date" type="date" :label="$t('Start Date')" />
          <text-input v-model="form.end_date" type="date" :label="$t('End Date')" />
          <auto-complete
            clearable
            :label="$t('Project(s) / Warehouse(s)')"
            :placeholder="$t('All Project(s) / Warehouse(s)')"
            :suggestions="warehouses"
            v-model="form.warehouse_id"
          />

          <select-input v-model="form.issued_to" :label="$t('Issued To')">
            <option :value="null">{{ $t('All Contacts') }}</option>
            <option v-for="contact in contacts" :key="contact.id" :value="contact.name">{{ contact.name }}</option>
          </select-input>
          <select-input v-model="form.item_id" :label="$t('Item (for Item History)')">
            <option :value="null">{{ $t('All Items') }}</option>
            <option v-for="item in items" :key="item.id" :value="item.id">{{ item.code ? `${item.code} - ${item.name}` : item.name }}</option>
          </select-input>
          <select-input v-model="form.status" :label="$t('Status')">
            <option :value="null">{{ $t('All') }}</option>
            <option value="open">{{ $t('Open') }}</option>
            <option value="partially_returned">{{ $t('Partially Returned') }}</option>
            <option value="fully_returned">{{ $t('Fully Returned') }}</option>
            <option value="overdue">{{ $t('Overdue') }}</option>
          </select-input>
          <div class="irr-apply">
            <button type="button" class="lg-btn lg-btn-primary w-full" @click="apply"><icons name="search" class="w-4 h-4" />{{ $t('Apply') }}</button>
          </div>
        </div>
      </section>

      <div class="lg-table-wrap -mx-4 md:mx-0">
        <table v-labelize class="lg-table lg-responsive">
          <thead>
            <tr>
              <th v-for="c in report.columns" :key="c">{{ c }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, i) in report.rows" :key="i">
              <td v-for="(v, k) in row" :key="k">{{ v }}</td>
            </tr>
            <tr v-if="!report.rows || report.rows.length === 0" class="lg-empty-row">
              <td class="lg-empty" :colspan="report.columns.length">{{ $t('No data to display.') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TextInput from '@/Shared/TextInput.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';

export default {
  components: { AdminLayout, PageHeader, TextInput, SelectInput, AutoComplete },
  props: {
    filters: Object,
    report: Object,
    warehouses: Array,
    contacts: Array,
    items: Array,
  },
  data() {
    return {
      form: {
        report_type: this.filters.report_type || 'issue',
        start_date: this.filters.start_date || '',
        end_date: this.filters.end_date || '',
        warehouse_id: this.filters.warehouse_id || null,
        status: this.filters.status || null,
        issued_to: this.filters.issued_to || null,
        item_id: this.filters.item_id || null,
      },
    };
  },
  methods: {
    params(output = null) {
      const params = { ...this.form };
      Object.keys(params).forEach(k => {
        if (params[k] === null || params[k] === '') delete params[k];
      });
      if (output) params.output = output;
      return params;
    },
    apply() {
      this.$inertia.get(route('issue-return-reports.index', this.params()), {}, { preserveState: true, replace: true });
    },
    open(output) {
      window.open(route('issue-return-reports.index', this.params(output)), '_blank');
    },
  },
};
</script>

<style scoped>
.irr-filter-card :deep(.col-span-6.sm\:col-span-4) { grid-column: span 1 / span 1 !important; }
.irr-page :deep(.lg-fields label:not(:has(input[type='checkbox']))) { display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-mute); }
.irr-page :deep(.lg-fields input:not([type='checkbox']):not([type='radio'])),
.irr-page :deep(.lg-fields select) { border-radius: 0.85rem !important; border-color: var(--lg-line) !important; background: var(--lg-card); color: var(--lg-ink); transition: border-color 0.15s ease, box-shadow 0.15s ease; }
.irr-page :deep(.lg-fields input:focus), .irr-page :deep(.lg-fields select:focus) { border-color: #38bdf8 !important; box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2) !important; }
.irr-apply { display: flex; align-items: flex-end; }
@media (max-width: 767.98px) {
  .irr-page .lg-table-wrap { border: 1px solid var(--lg-line); overflow-x: auto; background: var(--lg-card); }
}
</style>

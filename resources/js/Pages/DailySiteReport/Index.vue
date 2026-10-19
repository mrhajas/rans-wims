<template>
  <admin-layout :title="$t('Site Reports')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="doc" :title="$t('Site Reports')" :subtitle="$t('Submit and review daily site documentation')">
        <Link v-if="!isSiteEngineer" :href="route('daily-site-reports.compliance.index')" class="lg-btn lg-btn-soft">
          <icons name="chart-bar" class="w-4 h-4" />{{ $t('Compliance') }}
        </Link>
        <button type="button" @click="openPrint" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </button>
        <button type="button" @click="downloadPdf" class="lg-btn lg-btn-soft">
          <icons name="doc-text" class="w-4 h-4" />{{ $t('PDF') }}
        </button>
        <a :href="route('daily-site-reports.export')" class="lg-btn lg-btn-soft">
          <icons name="download" class="w-4 h-4" />{{ $t('Export Excel') }}
        </a>
        <Link v-if="canCreate" :href="route('daily-site-reports.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />{{ $t('New Report') }}
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" @select="pickTile" />

      <div v-if="stats" class="dsr-breakdown print:hidden">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="collection" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Reports by Project') }}</h2>
              <p class="lg-card-sub">{{ $t('Click a row to filter the list') }}</p>
            </div>
          </header>
          <div v-if="stats.by_project && stats.by_project.length" class="dsr-rows">
            <button
              type="button"
              v-for="row in stats.by_project"
              :key="row.warehouse_id"
              class="dsr-row"
              :class="{ 'is-active': form.warehouse_id == row.warehouse_id }"
              @click="toggleWarehouseFilter(row.warehouse_id)"
            >
              <span class="truncate">{{ row.label }}</span>
              <span class="lg-chip lg-chip-indigo" style="text-transform: none">{{ row.total }} {{ reportWord(row.total) }}</span>
            </button>
          </div>
          <div v-else class="lg-subtext">{{ $t('No data yet.') }}</div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Reports by Type') }}</h2>
              <p class="lg-card-sub">{{ $t('Click a row to filter the list') }}</p>
            </div>
          </header>
          <div v-if="stats.by_type && stats.by_type.length" class="dsr-rows">
            <button
              type="button"
              v-for="row in stats.by_type"
              :key="row.label"
              class="dsr-row"
              :class="{ 'is-active': form.report_type === row.label }"
              @click="toggleReportTypeFilter(row.label)"
            >
              <span class="truncate">{{ row.label }}</span>
              <span class="lg-chip lg-chip-cyan" style="text-transform: none">{{ row.total }} {{ reportWord(row.total) }}</span>
            </button>
          </div>
          <div v-else class="lg-subtext">{{ $t('No data yet.') }}</div>
        </section>
      </div>

      <div class="txn-toolbar mb-6 flex flex-col lg:flex-row gap-4 lg:items-end lg:justify-between print:hidden relative z-20">
        <search-filter v-model="form.search" class="w-full max-w-lg" @reset="reset">
          <auto-complete id="status" position="left" :label="$t('Status')" v-model="form.status" class="mt-1 w-full" :suggestions="statusOptions" />
          <auto-complete id="report-type" position="left" :label="$t('Report Type')" v-model="form.report_type" class="mt-1 w-full" :suggestions="reportTypeOptions" />
          <auto-complete v-if="!isSiteEngineer" id="warehouse" position="left" :label="$t('Project / Warehouse')" v-model="form.warehouse_id" class="mt-1 w-full" :suggestions="warehouses" />
          <auto-complete v-if="!isSiteEngineer" id="site-engineer" position="left" :label="$t('Site Engineer')" v-model="form.submitted_by" class="mt-1 w-full" :suggestions="siteEngineers" />
          <text-input type="date" v-model="form.start_date" :label="$t('Date From')" class="mt-1 w-full" />
          <text-input type="date" v-model="form.end_date" :label="$t('Date To')" class="mt-1 w-full" />
        </search-filter>
      </div>

      <section class="lg-card dsr-card">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th class="is-sortable" @click="setSort('report_number')"><span class="lg-sort">{{ $t('Report Number') }}<span class="text-xs">{{ sortIndicator('report_number') }}</span></span></th>
                <th class="is-sortable" @click="setSort('warehouse_name')"><span class="lg-sort">{{ $t('Project') }}<span class="text-xs">{{ sortIndicator('warehouse_name') }}</span></span></th>
                <th class="is-sortable" @click="setSort('report_type')"><span class="lg-sort">{{ $t('Report Type') }}<span class="text-xs">{{ sortIndicator('report_type') }}</span></span></th>
                <th class="is-sortable" @click="setSort('submitted_by_name')"><span class="lg-sort">{{ $t('Site Engineer') }}<span class="text-xs">{{ sortIndicator('submitted_by_name') }}</span></span></th>
                <th class="is-sortable" @click="setSort('report_date')"><span class="lg-sort">{{ $t('Report Date') }}<span class="text-xs">{{ sortIndicator('report_date') }}</span></span></th>
                <th>{{ $t('Days Left to Archive') }}</th>
                <th>{{ $t('Attachments') }}</th>
                <th class="is-sortable" @click="setSort('status')"><span class="lg-sort">{{ $t('Status') }}<span class="text-xs">{{ sortIndicator('status') }}</span></span></th>
                <th>{{ $t('Schedule Status') }}</th>
                <th class="is-sortable" @click="setSort('created_at')"><span class="lg-sort">{{ $t('Submitted At') }}<span class="text-xs">{{ sortIndicator('created_at') }}</span></span></th>
                <th class="is-sortable" @click="setSort('viewed_by_name')"><span class="lg-sort">{{ $t('Last Viewed By') }}<span class="text-xs">{{ sortIndicator('viewed_by_name') }}</span></span></th>
                <th class="lg-sticky-end">{{ $t('Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="report in dailySiteReports.data" :key="report.id" class="is-click" @click="goto(report.id)">
                <td :data-label="$t('Report Number')">
                  <div class="lg-main">
                    <hover-preview category="daily-site-reports" :id="report.id">{{ report.report_number }}</hover-preview>
                  </div>
                </td>
                <td :data-label="$t('Project')">{{ report.warehouse?.name }}</td>
                <td :data-label="$t('Report Type')">{{ report.report_type }}</td>
                <td :data-label="$t('Site Engineer')">{{ report.submitted_by_user?.name }}</td>
                <td :data-label="$t('Report Date')">{{ $date(report.report_date) }}</td>
                <td :data-label="$t('Days Left to Archive')">
                  <span class="dsr-archive-countdown inline-flex items-center gap-1.5">
                    <icons :name="report.days_left_to_archive === null ? 'lock-closed' : 'clock'" class="w-3.5 h-3.5 flex-none" />
                    <template v-if="report.days_left_to_archive === null">{{ $t('Archived') }}</template>
                    <template v-else-if="report.days_left_to_archive > 0">{{ report.days_left_to_archive }} {{ $t('day(s)') }}</template>
                    <template v-else>{{ $t('Archives tonight') }}</template>
                  </span>
                </td>
                <td :data-label="$t('Attachments')">
                  <span class="lg-chip lg-chip-slate"><icons name="clip" class="w-3 h-3" />{{ report.attachments?.length || 0 }}</span>
                </td>
                <td :data-label="$t('Status')">
                  <span class="lg-chip" :class="statusChip(report.status)" style="text-transform: none">{{ $t(report.status) }}</span>
                </td>
                <td :data-label="$t('Schedule Status')">
                  <span class="lg-chip" :class="report.schedule_status === 'Overdue' ? 'lg-chip-red' : 'lg-chip-green'" style="text-transform: none">{{ $t(report.schedule_status) }}</span>
                </td>
                <td :data-label="$t('Submitted At')">{{ report.submitted_at || '-' }}</td>
                <td :data-label="$t('Last Viewed By')">{{ report.viewed_by_user?.name || '-' }}</td>
                <td :data-label="$t('Actions')" class="lg-sticky-end">
                  <div class="lg-actions" @click.stop>
                    <Link :href="route('daily-site-reports.show', report.id)" class="lg-icon-btn lg-tip" :data-tip="$t('View')">
                      <icons name="eye-flat" class="w-4 h-4" />
                    </Link>
                    <Link v-if="report.can_edit" :href="route('daily-site-reports.edit', report.id)" class="lg-icon-btn is-amber lg-tip" :data-tip="$t('Edit')">
                      <icons name="edit" class="w-4 h-4" />
                    </Link>
                    <button v-if="report.can_delete" type="button" @click="destroy(report)" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Delete')">
                      <icons name="trash" class="w-4 h-4" />
                    </button>
                    <button v-if="report.can_archive && report.status !== 'Archived'" type="button" @click="archive(report)" class="lg-icon-btn lg-tip" :data-tip="$t('Archive')">
                      <icons name="folder" class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="dailySiteReports.data.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="12">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <pagination class="mt-6" :meta="dailySiteReports.meta" :links="dailySiteReports.links" />
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import { SITE_ENGINEER_ROLES } from '@/Core/siteEngineerRoles';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import Pagination from '@/Shared/Pagination.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TextInput from '@/Shared/TextInput.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';
import HoverPreview from '@/Shared/HoverPreview/HoverPreview.vue';

export default {
  components: {
    PageHeader,
    StatTiles,
    Pagination,
    AdminLayout,
    TextInput,
    AutoComplete,
    SearchFilter,
    HoverPreview,
  },

  props: {
    filters: Object,
    statuses: Array,
    reportTypes: Array,
    warehouses: Array,
    siteEngineers: Array,
    dailySiteReports: Object,
    stats: { type: Object, default: null },
  },

  data() {
    return {
      form: {
        search: this.filters.search,
        status: this.filters.status,
        report_type: this.filters.report_type,
        warehouse_id: this.filters.warehouse_id,
        submitted_by: this.filters.submitted_by,
        start_date: this.filters.start_date,
        end_date: this.filters.end_date,
        sort: this.filters.sort,
        sort_direction: this.filters.sort_direction,
      },
    };
  },

  computed: {
    statTiles() {
      if (!this.stats) {
        return [];
      }

      return [
        { icon: 'doc', tone: 'navy', label: this.$t('Total Reports'), value: this.stats.total ?? 0, active: !this.form.status, status: null },
        { icon: 'calendar', tone: 'sky', label: this.$t('Submitted Today'), value: this.stats.submitted_today },
        { icon: 'collection', tone: 'indigo', label: this.$t('This Month'), value: this.stats.this_month ?? 0 },
        { icon: 'clock', tone: 'amber', label: this.$t('Pending Review'), value: this.stats.pending_review },
        { icon: 'exclamation', tone: 'rose', label: this.$t('Late Submissions'), value: this.stats.overdue ?? 0 },
        { icon: 'download', tone: 'emerald', label: this.$t('Downloaded'), value: this.stats.downloaded, active: this.form.status === 'Downloaded', status: 'Downloaded' },
        { icon: 'folder', tone: 'slate', label: this.$t('Archived'), value: this.stats.archived, active: this.form.status === 'Archived', status: 'Archived' },
      ];
    },
    isSiteEngineer() {
      const roles = this.$user.roles || [];
      return roles.some(r => SITE_ENGINEER_ROLES.includes(r.name)) && !roles.some(r => ['Admin', 'Super Admin'].includes(r.name));
    },
    canCreate() {
      const roles = this.$user.roles || [];
      return roles.some(r => [...SITE_ENGINEER_ROLES, 'Admin', 'Super Admin'].includes(r.name));
    },
    statusOptions() {
      return (this.statuses || []).map(s => ({ label: this.$t(s), value: s }));
    },
    reportTypeOptions() {
      return (this.reportTypes || []).map(t => ({ label: t, value: t }));
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('daily-site-reports.index', Object.keys(query).length ? query : { remember: 'forget' }), {
          preserveState: true,
          replace: true,
        });
      }, 250),
      deep: true,
    },
  },

  methods: {
    pickTile(item) {
      this.form.status = item.status || null;
    },
    statusChip(status) {
      return (
        {
          Submitted: 'lg-chip-blue',
          Viewed: 'lg-chip-amber',
          Downloaded: 'lg-chip-green',
          Archived: 'lg-chip-slate',
        }[status] || 'lg-chip-slate'
      );
    },
    reset() {
      this.form = mapValues(this.form, () => null);
    },
    goto(id) {
      this.$inertia.visit(route('daily-site-reports.show', id));
    },
    async destroy(report) {
      if (!(await this.$confirm(this.$t('Are you sure you want to delete this report?'), { variant: 'danger' }))) {
        return;
      }

      this.$inertia.delete(route('daily-site-reports.destroy', report.id));
    },
    async archive(report) {
      if (!(await this.$confirm(this.$t('Archiving will permanently delete the uploaded attachments. Continue?'), { variant: 'danger' }))) {
        return;
      }

      this.$inertia.patch(route('daily-site-reports.archive', report.id));
    },
    reportWord(total) {
      return total === 1 ? this.$t('Report') : this.$t('Reports');
    },
    toggleWarehouseFilter(warehouseId) {
      this.form.warehouse_id = this.form.warehouse_id == warehouseId ? null : warehouseId;
    },
    toggleReportTypeFilter(reportType) {
      this.form.report_type = this.form.report_type === reportType ? null : reportType;
    },
    setSort(column) {
      if (this.form.sort === column) {
        this.form.sort_direction = this.form.sort_direction === 'asc' ? 'desc' : 'asc';
      } else {
        this.form.sort = column;
        this.form.sort_direction = 'asc';
      }
    },
    sortIndicator(column) {
      if (this.form.sort !== column) {
        return '';
      }

      return this.form.sort_direction === 'asc' ? '▲' : '▼';
    },
    openPrint() {
      const query = pickBy(this.form);
      window.open(this.route('daily-site-reports.print-list', query), '_blank');
    },
    downloadPdf() {
      const query = pickBy(this.form);
      window.open(this.route('daily-site-reports.pdf-list', query), '_blank');
    },
  },
};
</script>

<style scoped>
.dsr-archive-countdown { color: #7f1d1d; font-weight: 700; font-size: 0.8rem; }
html.dark .dsr-archive-countdown { color: #f87171; }

.dsr-card { padding: 0.9rem; }
.dsr-breakdown { display: grid; gap: 1rem; grid-template-columns: minmax(0, 1fr); }
@media (min-width: 768px) {
  .dsr-breakdown { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
.dsr-rows { display: flex; flex-direction: column; gap: 4px; max-height: 18rem; overflow-y: auto; }
.dsr-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; width: 100%; padding: 7px 10px; border-radius: 12px; font-size: 0.82rem; font-weight: 600; text-align: left; color: var(--lg-ink); transition: background 0.15s ease; }
.dsr-row:hover { background: var(--lg-soft); }
.dsr-row.is-active { background: rgba(99, 102, 241, 0.14); color: #4338ca; }
html.dark .dsr-row.is-active { color: #c7d2fe; }

.lg-icon-btn.is-amber { color: #b45309; background: rgba(245, 158, 11, 0.16); }
html.dark .lg-icon-btn.is-amber { color: #fcd34d; }

@media (max-width: 767.98px) {
  .dsr-card { padding: 0; background: transparent; border: 0; box-shadow: none; }
}
</style>

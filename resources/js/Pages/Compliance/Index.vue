<template>
  <admin-layout :title="$t('Compliance Register')">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="clip"
        :title="$t('Compliance Register')"
        :subtitle="$t('Certificates, licences, guarantees, loans, insurance, permits, and contracts: tracked so nothing expires unnoticed.')"
      >
        <Link :href="route('compliance-records.dashboard')" class="lg-btn lg-btn-soft">
          <icons name="chart-pie" class="w-4 h-4" /> {{ $t('Dashboard') }}
        </Link>
        <Link :href="route('compliance-records.calendar')" class="lg-btn lg-btn-soft">
          <icons name="calendar" class="w-4 h-4" /> {{ $t('Calendar') }}
        </Link>
        <Link v-if="$can('create-compliance-records')" :href="route('compliance-records.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" /> {{ $t('Add Record') }}
        </Link>
      </page-header>

      <section class="lg-card cp-filters">
        <div class="lg-fields cp-filter-grid">
          <text-input class="w-full" :label="$t('Search')" v-model="form.search" :placeholder="$t('Number, title, reference…')" />
          <auto-complete id="compliance_category_id" :label="$t('Category')" v-model="form.compliance_category_id" class="w-full" :suggestions="categoryOptions" clearable />
          <auto-complete id="warehouse_id" :label="$t('Project')" v-model="form.warehouse_id" class="w-full" :suggestions="warehouseOptions" clearable />
          <auto-complete id="status" :label="$t('Status')" v-model="form.status" class="w-full" :suggestions="statusOptions" clearable />
          <auto-complete id="priority" :label="$t('Priority')" v-model="form.priority" class="w-full" :suggestions="priorityOptions" clearable />
          <div class="cp-reset-cell">
            <button type="button" class="lg-btn lg-btn-soft cp-reset" @click="reset">
              <icons name="refresh" class="w-4 h-4" /> {{ $t('Reset') }}
            </button>
          </div>
        </div>
      </section>

      <section class="lg-card cp-list">
        <div class="cp-list-head">
          <span class="lg-chip lg-chip-slate" style="text-transform: none">{{ records.meta ? records.meta.total : 0 }} {{ $t('result(s)') }}</span>
          <div class="cp-list-actions" v-if="$can('export-compliance-records')">
            <a :href="route('compliance-records.print-list') + queryString" target="_blank" class="lg-btn lg-btn-soft">
              <icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}
            </a>
            <a :href="route('compliance-records.pdf-list') + queryString" class="lg-btn lg-btn-dark">
              <icons name="download" class="w-4 h-4" /> {{ $t('Download') }}
            </a>
          </div>
        </div>

        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th class="cp-no">{{ $t('No') }}</th>
                <th class="cp-thumb-col"></th>
                <th>{{ $t('Compliance') }}</th>
                <th>{{ $t('Category') }}</th>
                <th>{{ $t('Responsible') }}</th>
                <th>{{ $t('Project') }}</th>
                <th>{{ $t('Days Remaining') }}</th>
                <th>{{ $t('Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(r, ri) in records.data" :key="r.id" class="is-click" @click="goto(r.id)">
                <td :data-label="$t('No')" class="cp-no">{{ rowNumber(ri) }}</td>
                <td class="cp-thumb-cell" @click.stop>
                  <img
                    v-if="r.photo_url"
                    :src="r.photo_url"
                    class="cp-thumb"
                    alt=""
                    @mouseenter="showZoom($event, r)"
                    @mouseleave="hideZoom"
                  />
                  <div v-else class="cp-thumb-placeholder">
                    <icons name="clip" class="w-4 h-4" />
                  </div>
                </td>
                <td :data-label="$t('Compliance')">
                  <div class="lg-main">{{ r.compliance_number }}</div>
                  <div class="cp-title">{{ r.title }}</div>
                  <div class="lg-subtext">{{ r.compliance_type }}</div>
                </td>
                <td :data-label="$t('Category')">{{ r.category || '-' }}</td>
                <td :data-label="$t('Responsible')">{{ r.responsible || '-' }}</td>
                <td :data-label="$t('Project')">{{ r.warehouse || '-' }}</td>
                <td :data-label="$t('Days Remaining')">
                  <span class="lg-chip" :class="daysBadgeClass(r.days_remaining)" style="text-transform: none">{{ r.days_remaining_label }}</span>
                </td>
                <td :data-label="$t('Status')">
                  <span class="lg-chip" :class="statusBadgeClass(r.status)" style="text-transform: none">{{ r.status }}</span>
                  <div class="lg-subtext">{{ r.priority }}</div>
                </td>
              </tr>
              <tr v-if="records.data.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="8">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <pagination v-if="records.data.length" class="mt-6" :meta="records.meta" :links="records.links" />
    </div>

    <teleport to="body">
      <div class="cp-zoom" :class="{ 'cp-zoom-show': zoom.show }" :style="{ left: zoom.x + 'px', top: zoom.y + 'px' }">
        <img v-if="zoom.record && zoom.record.photo_url" :src="zoom.record.photo_url" alt="" />
        <template v-if="zoom.record">
          <div class="cp-zoom-name">{{ zoom.record.title }}</div>
          <div class="cp-zoom-row"><span>{{ $t('Number') }}</span><strong>{{ zoom.record.compliance_number }}</strong></div>
          <div class="cp-zoom-row"><span>{{ $t('Type') }}</span><strong>{{ zoom.record.compliance_type }}</strong></div>
          <div class="cp-zoom-row" v-if="zoom.record.issuing_authority"><span>{{ $t('Authority') }}</span><strong>{{ zoom.record.issuing_authority }}</strong></div>
          <div class="cp-zoom-row"><span>{{ $t('Status') }}</span><strong>{{ zoom.record.status }}</strong></div>
          <div class="cp-zoom-row"><span>{{ $t('Expires') }}</span><strong>{{ zoom.record.days_remaining_label }}</strong></div>
        </template>
      </div>
    </teleport>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import TextInput from '@/Shared/TextInput.vue';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import { Link } from '@inertiajs/vue3';

export default {
  components: { AdminLayout, AutoComplete, TextInput, Pagination, PageHeader, Link },

  props: {
    filters: Object,
    records: Object,
    categories: Array,
    warehouses: Array,
    statuses: Array,
    priorities: Array,
  },

  data() {
    return {
      zoom: { show: false, record: null, x: 0, y: 0 },
      form: {
        search: this.filters.search || '',
        compliance_category_id: this.filters.compliance_category_id || null,
        warehouse_id: this.filters.warehouse_id || null,
        status: this.filters.status || null,
        priority: this.filters.priority || null,
      },
    };
  },

  computed: {
    categoryOptions() {
      return this.categories.map(c => ({ label: `${c.group} / ${c.name}`, value: c.id }));
    },
    warehouseOptions() {
      return this.warehouses.map(w => ({ label: w.name, value: w.id }));
    },
    statusOptions() {
      return this.statuses.map(s => ({ label: this.$t(s), value: s }));
    },
    priorityOptions() {
      return this.priorities.map(p => ({ label: this.$t(p), value: p }));
    },
    queryString() {
      const query = pickBy(this.form);
      const qs = new URLSearchParams(query).toString();
      return qs ? '?' + qs : '';
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('compliance-records.index', Object.keys(query).length ? query : { remember: 'forget' }), {
          preserveState: true,
          preserveScroll: true,
          replace: true,
        });
      }, 200),
      deep: true,
    },
  },

  methods: {
    // Row number across pages (page 2 of 20 per page starts at 21).
    rowNumber(index) {
      return (this.records.meta && this.records.meta.from ? this.records.meta.from : 1) + index;
    },
    reset() {
      this.form = mapValues(this.form, () => null);
      this.form.search = '';
    },
    goto(id) {
      this.$inertia.visit(this.route('compliance-records.show', id));
    },
    showZoom(event, record) {
      const rect = event.currentTarget.getBoundingClientRect();
      this.zoom = { show: true, record, x: rect.right + 14, y: rect.top + rect.height / 2 };
    },
    hideZoom() {
      this.zoom.show = false;
    },
    statusBadgeClass(status) {
      const map = {
        Active: 'lg-chip-green',
        'Due Soon': 'lg-chip-amber',
        'Renewal in Progress': 'lg-chip-blue',
        Submitted: 'lg-chip-blue',
        'Pending Approval': 'lg-chip-blue',
        Renewed: 'lg-chip-green',
        Expired: 'lg-chip-red',
        Cancelled: 'lg-chip-slate',
        Suspended: 'lg-chip-slate',
        Rejected: 'lg-chip-red',
        Completed: 'lg-chip-slate',
        Draft: 'lg-chip-slate',
      };
      return map[status] || 'lg-chip-slate';
    },
    daysBadgeClass(days) {
      if (days === null || days === undefined) return 'lg-chip-slate';
      if (days < 0) return 'lg-chip-red';
      if (days <= 7) return 'lg-chip-red';
      if (days <= 30) return 'lg-chip-amber';
      return 'lg-chip-green';
    },
  },
};
</script>

<style scoped>
.cp-filters { position: relative; z-index: 20; }
.cp-filter-grid { grid-template-columns: repeat(auto-fit, minmax(min(170px, 100%), 1fr)); align-items: end; }
.cp-reset-cell { display: flex; align-items: flex-end; padding-bottom: 2px; }
.cp-reset { width: 100%; min-height: 42px; }
.cp-list { padding: 0.85rem; }
.cp-list-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; padding: 0 0.35rem 0.75rem; }
.cp-list-actions { display: flex; gap: 8px; }
.cp-title { font-size: 0.8rem; color: var(--lg-mute); white-space: normal; }
.cp-thumb-col { width: 3.5rem; }
.cp-no { width: 1%; font-weight: 800; color: var(--lg-mute); font-variant-numeric: tabular-nums; }
.cp-thumb { width: 2.4rem; height: 2.4rem; min-width: 2.4rem; max-width: none; border-radius: 12px; object-fit: cover; cursor: zoom-in; border: 1px solid var(--lg-line); }
.cp-thumb-placeholder { width: 2.4rem; height: 2.4rem; border-radius: 12px; background: var(--lg-soft); border: 1px solid var(--lg-line); color: var(--lg-faint); display: flex; align-items: center; justify-content: center; }
@media (max-width: 767.98px) {
  .cp-list { padding: 0.5rem; }
  .lg-table.lg-responsive tbody tr:not(.lg-empty-row) td.cp-no { width: auto; }
  .lg-table.lg-responsive tbody tr:not(.lg-empty-row) td.cp-thumb-cell { justify-content: flex-start; }
}

.cp-zoom { position: fixed; transform: translate(0, -50%) scale(0.85); z-index: 9999; background: #fff; border-radius: 1rem; padding: 0.75rem; box-shadow: 0 20px 40px -10px rgba(15, 23, 42, 0.35), 0 0 0 1px rgba(15, 23, 42, 0.05); opacity: 0; pointer-events: none; transition: opacity 0.16s ease, transform 0.16s ease; width: 200px; }
.cp-zoom-show { opacity: 1; transform: translate(0, -50%) scale(1); }
.cp-zoom img { display: block; width: 100%; height: 130px; object-fit: cover; border-radius: 0.7rem; margin-bottom: 0.5rem; }
.cp-zoom-name { font-size: 0.82rem; font-weight: 800; color: #1f2937; margin-bottom: 0.35rem; }
.cp-zoom-row { display: flex; justify-content: space-between; gap: 0.5rem; font-size: 0.7rem; color: #6b7280; padding: 0.15rem 0; }
.cp-zoom-row strong { color: #111827; font-weight: 700; text-align: right; }
html.dark .cp-zoom { background: #1e293b; box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.08); }
html.dark .cp-zoom-name { color: #f1f5f9; }
html.dark .cp-zoom-row strong { color: #e2e8f0; }
</style>

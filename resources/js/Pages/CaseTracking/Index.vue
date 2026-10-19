<template>
  <admin-layout :title="filters.mine ? $t('My Cases') : $t('Case Tracking')">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="exclamation"
        :title="filters.mine ? $t('My Cases') : $t('Case Tracking')"
        :subtitle="filters.mine ? $t('Cases you reported or that are assigned to you.') : $t('Report and track issues, requests and incidents.')"
      >
        <Link v-if="can.viewAnalytics" :href="route('case-tracking.analytics')" class="lg-btn lg-btn-soft">
          <icons name="chart-pie" class="w-4 h-4" /> {{ $t('Analytics') }}
        </Link>
        <a :href="route('case-tracking.print-list', filters)" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}
        </a>
        <a :href="route('case-tracking.pdf-list', filters)" class="lg-btn lg-btn-soft">
          <icons name="download" class="w-4 h-4" /> {{ $t('Download PDF') }}
        </a>
        <a v-if="can.export" :href="route('case-tracking.export', filters)" class="lg-btn lg-btn-soft">
          <icons name="download" class="w-4 h-4" /> {{ $t('Export CSV') }}
        </a>
        <Link v-if="can.create" :href="route('case-tracking.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" /> {{ $t('New Case') }}
        </Link>
      </page-header>

      <stat-tiles v-if="stats" :items="statTiles" />

      <section class="lg-card ct-filters">
        <div class="lg-fields ct-filter-grid">
          <text-input :label="$t('Search')" v-model="form.search" :placeholder="$t('Case number or subject…')" @update:modelValue="search" />
          <auto-complete :label="$t('Status')" v-model="form.status" :suggestions="statusOptions" clearable @update:modelValue="search" />
          <auto-complete :label="$t('Category')" v-model="form.case_category_id" :suggestions="categoryOptions" clearable @update:modelValue="search" />
          <auto-complete :label="$t('Priority')" v-model="form.case_priority_id" :suggestions="priorityOptions" clearable @update:modelValue="search" />
        </div>
      </section>

      <section class="lg-card ct-list">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Case #') }}</th>
                <th>{{ $t('Subject') }}</th>
                <th>{{ $t('Category') }}</th>
                <th>{{ $t('Priority') }}</th>
                <th>{{ $t('Status') }}</th>
                <th>{{ $t('Assigned To') }}</th>
                <th>{{ $t('Created') }}</th>
                <th class="lg-sticky-end">{{ $t('Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in cases.data" :key="c.id" class="is-click" @click="$inertia.visit(route('case-tracking.show', c.id))">
                <td :data-label="$t('Case #')">
                  <span class="lg-main">{{ c.case_number }}</span>
                  <icons v-if="c.is_long_running" name="exclamation" class="w-3.5 h-3.5 text-amber-600 inline-block align-middle ml-1 lg-tip tip-left" :data-tip="$t('Open for more than 14 days')" />
                </td>
                <td :data-label="$t('Subject')" class="ct-subject">{{ c.subject }}</td>
                <td :data-label="$t('Category')">{{ c.category_name || '—' }}</td>
                <td :data-label="$t('Priority')">
                  <span v-if="c.priority_name" class="lg-chip" :class="priorityChip(c.priority_color)" style="text-transform: none">{{ c.priority_name }}</span>
                  <span v-else>—</span>
                </td>
                <td :data-label="$t('Status')">
                  <span class="lg-chip" :class="statusChip(c.status)" style="text-transform: none">{{ c.status }}</span>
                </td>
                <td :data-label="$t('Assigned To')">{{ c.assigned_to_name || '—' }}</td>
                <td :data-label="$t('Created')">
                  <div class="lg-main">{{ c.created_by_name || '—' }}</div>
                  <div class="lg-subtext">{{ formatDate(c.created_at) }}</div>
                </td>
                <td :data-label="$t('Actions')" class="lg-sticky-end">
                  <div class="lg-actions" @click.stop>
                    <Link :href="route('case-tracking.show', c.id)" class="lg-icon-btn lg-tip" :data-tip="$t('View')">
                      <icons name="eye-flat" class="w-4 h-4" />
                    </Link>
                  </div>
                </td>
              </tr>
              <tr v-if="!cases.data.length" class="lg-empty-row">
                <td colspan="8" class="lg-empty">{{ $t('No cases found.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <pagination class="mt-6" :meta="cases.meta" :links="cases.links" />
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import TextInput from '@/Shared/TextInput.vue';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import debounce from 'lodash/debounce';

export default {
  components: { AdminLayout, Link, AutoComplete, TextInput, Pagination, PageHeader, StatTiles },

  props: {
    filters: Object,
    statuses: Array,
    categories: Array,
    priorities: Array,
    warehouses: Array,
    cases: Object,
    cards: Object,
    stats: { type: Object, default: null },
    can: Object,
  },

  data() {
    return {
      form: {
        search: this.filters.search || '',
        status: this.filters.status || null,
        case_category_id: this.filters.case_category_id || null,
        case_priority_id: this.filters.case_priority_id || null,
      },
    };
  },

  computed: {
    statusOptions() {
      return this.statuses.map(s => ({ id: s, label: s }));
    },
    categoryOptions() {
      return this.categories.map(c => ({ id: c.id, label: c.name }));
    },
    priorityOptions() {
      return this.priorities.map(p => ({ id: p.id, label: p.name }));
    },
    statTiles() {
      const s = this.stats || {};
      return [
        { icon: 'collection', tone: 'navy', label: this.filters.mine ? this.$t('My Cases') : this.$t('Total'), value: s.total || 0 },
        { icon: 'folder', tone: 'sky', label: this.$t('Open'), value: s.open || 0 },
        { icon: 'refresh', tone: 'indigo', label: this.$t('In Progress'), value: s.in_progress || 0 },
        { icon: 'tick', tone: 'emerald', label: this.$t('Resolved') + ' / ' + this.$t('Closed'), value: s.done || 0 },
        { icon: 'exclamation', tone: 'rose', label: this.$t('High Priority'), value: s.high_priority || 0 },
        { icon: 'clock', tone: 'amber', label: this.$t('Long Running'), value: s.long_running || 0 },
      ];
    },
  },

  methods: {
    statusSlug(status) {
      return String(status).toLowerCase().replace(/\s+/g, '_');
    },
    statusChip(status) {
      const map = {
        draft: 'lg-chip-slate',
        submitted: 'lg-chip-indigo',
        in_review: 'lg-chip-amber',
        in_progress: 'lg-chip-blue',
        returned: 'lg-chip-red',
        resolved: 'lg-chip-green',
        closed: 'lg-chip-slate',
      };
      return map[this.statusSlug(status)] || 'lg-chip-slate';
    },
    priorityChip(color) {
      const map = { slate: 'lg-chip-slate', amber: 'lg-chip-amber', rose: 'lg-chip-red', red: 'lg-chip-red' };
      return map[color] || 'lg-chip-slate';
    },
    formatDate(v) {
      return v ? this.$date(v) : '—';
    },
    search: debounce(function () {
      const target = this.filters.mine ? 'case-tracking.mine' : 'case-tracking.index';
      this.$inertia.get(this.route(target), this.form, { preserveState: true, replace: true });
    }, 300),
  },
};
</script>

<style scoped>
.ct-filters { position: relative; z-index: 20; }
.ct-filter-grid { grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr)); }
.ct-list { padding: 0.85rem; }
.ct-subject { white-space: normal; min-width: 14rem; max-width: 26rem; }
@media (max-width: 640px) {
  .ct-list { padding: 0.5rem; }
  .ct-subject { max-width: none; min-width: 0; }
}
</style>

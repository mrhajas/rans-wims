<template>
  <admin-layout :title="$t('Work Packages')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="clip" :title="$t('Work Packages')" :subtitle="$t('Every construction activity being tracked for execution')">
        <Link v-if="canCreate" :href="route('work-packages.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />
          <span>{{ $t('Create Work Package') }}</span>
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" @select="filterStatus" />

      <div class="txn-toolbar mb-4 flex flex-col lg:flex-row gap-4 lg:items-center lg:justify-between print:hidden relative z-20">
        <search-filter v-model="form.search" class="w-full max-w-lg" @reset="reset">
          <auto-complete
            id="warehouse"
            position="left"
            :label="$t('Project(s) / Warehouse(s)')"
            v-model="form.warehouse_id"
            class="mt-1 w-full"
            :suggestions="warehouses.map((w) => ({ label: w.name, value: w.id }))"
          />
          <auto-complete
            id="status"
            position="left"
            :label="$t('Status')"
            v-model="form.status"
            class="mt-1 w-full"
            :suggestions="statuses.map((s) => ({ label: $t(s), value: s }))"
          />
          <auto-complete
            id="priority"
            position="left"
            :label="$t('Priority')"
            v-model="form.priority"
            class="mt-1 w-full"
            :suggestions="priorities.map((p) => ({ label: $t(p), value: p }))"
          />
        </search-filter>
      </div>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th>{{ $t('Reference') }}</th>
              <th>{{ $t('Title') }}</th>
              <th>{{ $t('Project') }}</th>
              <th>{{ $t('Engineer') }}</th>
              <th>{{ $t('Health') }}</th>
              <th>{{ $t('Progress') }}</th>
              <th>{{ $t('Status') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="workPackage in workPackages.data" :key="workPackage.id" class="is-click" @click="goto(workPackage.id)">
              <td :data-label="$t('Reference')">
                <span class="lg-chip lg-chip-indigo lg-mono" style="text-transform: none">{{ workPackage.reference }}</span>
              </td>
              <td :data-label="$t('Title')">
                <div class="lg-main wp-title">{{ workPackage.title }}</div>
                <div v-if="workPackage.priority" class="mt-1">
                  <span class="lg-chip" :class="priorityChip(workPackage.priority)">{{ $t(workPackage.priority) }}</span>
                </div>
              </td>
              <td :data-label="$t('Project')">{{ workPackage.warehouse?.name }}</td>
              <td :data-label="$t('Engineer')">
                <div v-if="workPackage.engineer?.name" class="lg-person">
                  <span class="lg-avatar">{{ initials(workPackage.engineer.name) }}</span>
                  <span>{{ workPackage.engineer.name }}</span>
                </div>
                <span v-else>-</span>
              </td>
              <td :data-label="$t('Health')">
                <span class="lg-chip" :class="healthChip(workPackage.schedule.health)" style="text-transform: none">{{ healthLabel(workPackage.schedule.health) }}</span>
              </td>
              <td :data-label="$t('Progress')">
                <div class="wp-progress">
                  <div class="wp-bar"><span :style="{ width: Math.min(100, Math.max(0, workPackage.actual_progress_percent)) + '%' }"></span></div>
                  <strong>{{ workPackage.actual_progress_percent }}%</strong>
                </div>
              </td>
              <td :data-label="$t('Status')">
                <span class="lg-chip" :class="statusChip(workPackage.status)" style="text-transform: none">{{ $t(workPackage.status) }}</span>
              </td>
            </tr>
            <tr v-if="workPackages.data.length === 0" class="lg-empty-row">
              <td class="lg-empty" colspan="7">{{ $t('There is no data to display.') }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <pagination class="mt-6" :meta="workPackages.meta" :links="workPackages.links" />
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import Pagination from '@/Shared/Pagination.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';

export default {
  components: { AdminLayout, PageHeader, StatTiles, Pagination, AutoComplete, SearchFilter },

  props: {
    filters: Object,
    workPackages: Object,
    warehouses: Array,
    statuses: Array,
    priorities: Array,
    canCreate: Boolean,
    stats: { type: Object, default: null },
  },

  data() {
    return {
      form: {
        search: this.filters.search,
        warehouse_id: this.filters.warehouse_id,
        status: this.filters.status,
        priority: this.filters.priority,
      },
    };
  },

  computed: {
    statTiles() {
      const s = this.stats;
      if (!s) return [];
      const st = this.form.status || null;
      return [
        { icon: 'clip', tone: 'navy', label: this.$t('Total Work Packages'), value: s.total, status: null, active: st === null },
        { icon: 'clock', tone: 'slate', label: this.$t('Not Started'), value: s.not_started, status: 'Not Started', active: st === 'Not Started' },
        { icon: 'refresh', tone: 'sky', label: this.$t('In Progress'), value: s.in_progress, status: 'In Progress', active: st === 'In Progress' },
        { icon: 'exclamation', tone: 'amber', label: this.$t('On Hold'), value: s.on_hold, status: 'On Hold', active: st === 'On Hold' },
        { icon: 'tick', tone: 'emerald', label: this.$t('Completed'), value: s.completed, status: 'Completed', active: st === 'Completed' },
      ];
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.get(this.route('work-packages.index', Object.keys(query).length ? query : { remember: 'forget' }), {}, { preserveState: true, preserveScroll: true, replace: true });
      }, 250),
      deep: true,
    },
  },

  methods: {
    initials(name) {
      return (name || '?').split(' ').filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase();
    },
    filterStatus(item) {
      this.form.status = item.status;
    },
    healthChip(health) {
      return { green: 'lg-chip-green', yellow: 'lg-chip-amber', orange: 'lg-chip-amber', red: 'lg-chip-red' }[health] || 'lg-chip-slate';
    },
    statusChip(status) {
      return { 'Not Started': 'lg-chip-slate', 'In Progress': 'lg-chip-blue', 'On Hold': 'lg-chip-amber', Completed: 'lg-chip-green' }[status] || 'lg-chip-slate';
    },
    priorityChip(priority) {
      return { Low: 'lg-chip-slate', Normal: 'lg-chip-blue', High: 'lg-chip-amber', Urgent: 'lg-chip-red' }[priority] || 'lg-chip-slate';
    },
    reset() {
      this.form = mapValues(this.form, () => null);
    },
    goto(id) {
      this.$inertia.visit(route('work-packages.show', id));
    },
    healthLabel(health) {
      return { green: this.$t('Ahead'), yellow: this.$t('Slight Delay'), orange: this.$t('Behind'), red: this.$t('Critical') }[health] || health;
    },
  },
};
</script>

<style scoped>
.wp-title {
  max-width: 320px;
  overflow: hidden;
  text-overflow: ellipsis;
}
.wp-progress {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-width: 120px;
}
.wp-bar {
  flex: 1;
  height: 7px;
  min-width: 70px;
  border-radius: 9999px;
  overflow: hidden;
  background: var(--lg-line);
}
.wp-bar span {
  display: block;
  height: 100%;
  border-radius: 9999px;
  background: linear-gradient(90deg, #60a5fa, #1d4ed8);
}
</style>

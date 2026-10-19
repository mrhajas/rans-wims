<template>
  <admin-layout :title="$t('Issues')">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="exclamation"
        :title="$t('Issues')"
        :subtitle="isPlanner ? $t('All issues raised across your projects') : $t('Issues raised on your assigned work packages')"
      />

      <stat-tiles :items="statTiles" />

      <div class="txn-toolbar mb-4 flex flex-wrap gap-2 print:hidden relative z-20">
        <button
          type="button"
          @click="setStatus(null)"
          class="lg-btn"
          :class="!filters.status ? 'lg-btn-primary' : 'lg-btn-soft'"
        >
          {{ $t('All') }}
        </button>
        <button
          v-for="option in statusOptions"
          :key="option"
          type="button"
          @click="setStatus(option)"
          class="lg-btn"
          :class="filters.status === option ? 'lg-btn-primary' : 'lg-btn-soft'"
        >
          {{ $t(option) }}
        </button>
      </div>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th>{{ $t('Reference') }}</th>
              <th>{{ $t('Work Package') }}</th>
              <th>{{ $t('Title') }}</th>
              <th>{{ $t('Priority') }}</th>
              <th>{{ $t('Status') }}</th>
              <th>{{ $t('Assigned To') }}</th>
              <th>{{ $t('Date') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="issue in issues.data" :key="issue.id">
              <td :data-label="$t('Reference')">
                <Link :href="route('work-packages.show', issue.work_package.id)" class="lg-chip lg-chip-indigo lg-mono hover:underline" style="text-transform: none">
                  {{ issue.reference }}
                </Link>
              </td>
              <td :data-label="$t('Work Package')">
                <div class="lg-main">{{ issue.work_package.reference }}</div>
                <div class="lg-subtext">{{ issue.work_package.warehouse_name }}</div>
              </td>
              <td :data-label="$t('Title')">{{ issue.title }}</td>
              <td :data-label="$t('Priority')">
                <span class="lg-chip" :class="priorityChip(issue.priority)" style="text-transform: none">{{ $t(issue.priority) }}</span>
              </td>
              <td :data-label="$t('Status')">
                <span class="lg-chip" :class="statusChip(issue.status)" style="text-transform: none">{{ $t(issue.status) }}</span>
              </td>
              <td :data-label="$t('Assigned To')">{{ issue.assignee_name || '-' }}</td>
              <td :data-label="$t('Date')">{{ $datetime(issue.created_at) }}</td>
            </tr>
            <tr v-if="issues.data.length === 0" class="lg-empty-row">
              <td class="lg-empty" colspan="7">{{ $t('There is no data to display.') }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <pagination class="mt-6" :meta="issues.meta" :links="issues.links" />
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, Pagination, PageHeader, StatTiles },

  props: {
    issues: Object,
    filters: Object,
    statuses: Array,
    priorities: Array,
    isPlanner: Boolean,
    stats: { type: Object, default: null },
  },

  data() {
    return {
      statusOptions: this.statuses,
    };
  },

  computed: {
    statTiles() {
      const s = this.stats;
      if (!s) return [];
      return [
        { icon: 'exclamation', tone: 'navy', label: this.$t('Total Issues'), value: s.total },
        { icon: 'info', tone: 'rose', label: this.$t('Open'), value: s.open },
        { icon: 'refresh', tone: 'sky', label: this.$t('In Progress'), value: s.in_progress },
        { icon: 'tick', tone: 'emerald', label: this.$t('Resolved or closed'), value: s.done },
        { icon: 'bell', tone: 'pink', label: this.$t('Critical and unresolved'), value: s.critical },
      ];
    },
  },

  methods: {
    statusChip(status) {
      return { Open: 'lg-chip-red', 'In Progress': 'lg-chip-blue', Resolved: 'lg-chip-green', Closed: 'lg-chip-slate' }[status] || 'lg-chip-slate';
    },
    priorityChip(priority) {
      return { Low: 'lg-chip-slate', Medium: 'lg-chip-blue', High: 'lg-chip-amber', Critical: 'lg-chip-red' }[priority] || 'lg-chip-slate';
    },
    setStatus(status) {
      this.$inertia.get(this.route('work-package-issues.index', { status }), {}, { preserveState: true, preserveScroll: true });
    },
  },
};
</script>

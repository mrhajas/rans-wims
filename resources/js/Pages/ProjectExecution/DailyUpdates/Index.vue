<template>
  <admin-layout :title="$t('Daily Updates')">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="edit"
        :title="$t('Daily Updates')"
        :subtitle="isPlanner ? $t('Review and approve progress updates submitted from site') : $t('Your submitted progress updates')"
      />

      <stat-tiles :items="statTiles" />

      <div v-if="isPlanner" class="txn-toolbar mb-4 flex flex-wrap gap-2 print:hidden relative z-20">
        <button
          v-for="option in statusOptions"
          :key="option.value"
          type="button"
          @click="setStatus(option.value)"
          class="lg-btn"
          :class="filters.status === option.value ? 'lg-btn-primary' : 'lg-btn-soft'"
        >
          {{ option.label }}
          <span v-if="option.value === 'Pending Approval' && pendingCount" class="lg-chip lg-chip-red" style="text-transform: none">{{ pendingCount }}</span>
        </button>
      </div>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th>{{ $t('Work Package') }}</th>
              <th>{{ $t('Project') }}</th>
              <th>{{ $t('Submitted By') }}</th>
              <th>{{ $t('Progress') }}</th>
              <th>{{ $t('Status') }}</th>
              <th>{{ $t('Date') }}</th>
              <th v-if="isPlanner">{{ $t('Actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="update in updates.data" :key="update.id">
              <td :data-label="$t('Work Package')">
                <Link :href="route('work-packages.show', update.work_package.id)" class="lg-chip lg-chip-indigo lg-mono hover:underline" style="text-transform: none">
                  {{ update.work_package.reference }}
                </Link>
              </td>
              <td :data-label="$t('Project')">{{ update.work_package.warehouse_name }}</td>
              <td :data-label="$t('Submitted By')">
                <div class="lg-person">
                  <span class="lg-avatar">{{ initials(update.submitter_name) }}</span>
                  <span>{{ update.submitter_name }}</span>
                </div>
              </td>
              <td :data-label="$t('Progress')">
                <div class="du-progress">
                  <div class="du-bar"><span :style="{ width: Math.min(100, Math.max(0, update.progress_percent)) + '%' }"></span></div>
                  <strong>{{ update.progress_percent }}%</strong>
                </div>
              </td>
              <td :data-label="$t('Status')">
                <span class="lg-chip" :class="statusChip(update.status)" style="text-transform: none">{{ $t(update.status) }}</span>
              </td>
              <td :data-label="$t('Date')">{{ $datetime(update.created_at) }}</td>
              <td v-if="isPlanner" :data-label="$t('Actions')">
                <div v-if="update.status === 'Pending Approval'" class="flex items-center gap-2">
                  <button type="button" @click="approveUpdate(update)" class="lg-btn lg-btn-green"><icons name="tick" class="w-4 h-4" />{{ $t('Approve') }}</button>
                  <button type="button" @click="rejectUpdate(update)" class="lg-btn lg-btn-red"><icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}</button>
                </div>
              </td>
            </tr>
            <tr v-if="updates.data.length === 0" class="lg-empty-row">
              <td class="lg-empty" :colspan="isPlanner ? 7 : 6">{{ $t('There is no data to display.') }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <pagination class="mt-6" :meta="updates.meta" :links="updates.links" />
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
    updates: Object,
    filters: Object,
    isPlanner: Boolean,
    pendingCount: Number,
    stats: { type: Object, default: null },
  },

  data() {
    return {
      statusOptions: [
        { value: 'Pending Approval', label: this.$t('Pending Approval') },
        { value: 'Approved', label: this.$t('Approved') },
        { value: 'Rejected', label: this.$t('Rejected') },
      ],
    };
  },

  computed: {
    statTiles() {
      const s = this.stats;
      if (!s) return [];
      const items = [
        { icon: 'edit', tone: 'navy', label: this.$t('Total Updates'), value: s.total },
        { icon: 'clock', tone: 'amber', label: this.$t('Pending Approval'), value: s.pending },
        { icon: 'tick', tone: 'emerald', label: this.$t('Approved'), value: s.approved },
        { icon: 'cross', tone: 'rose', label: this.$t('Rejected'), value: s.rejected },
        { icon: 'calendar', tone: 'sky', label: this.$t('Submitted today'), value: s.today },
      ];
      return items;
    },
  },

  methods: {
    initials(name) {
      return (name || '?').split(' ').filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase();
    },
    statusChip(status) {
      return { 'Pending Approval': 'lg-chip-amber', Approved: 'lg-chip-green', Rejected: 'lg-chip-red' }[status] || 'lg-chip-slate';
    },
    setStatus(status) {
      this.$inertia.get(this.route('work-package-daily-updates.index', { status }), {}, { preserveState: true, preserveScroll: true });
    },
    statusClass(status) {
      return { 'Pending Approval': 'amber', Approved: 'emerald', Rejected: 'rose' }[status] || 'slate';
    },
    async approveUpdate(update) {
      if (!(await this.$confirm(this.$t('Approve this daily update? The reported progress will be applied to the Work Package.')))) {
        return;
      }
      this.$inertia.put(this.route('work-package-daily-updates.approve', update.id), {}, { preserveScroll: true });
    },
    async rejectUpdate(update) {
      const remarks = await this.$prompt(this.$t('Reason for rejecting this update:'));
      if (!remarks) {
        return;
      }
      this.$inertia.put(this.route('work-package-daily-updates.reject', update.id), { approval_remarks: remarks }, { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.du-progress {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-width: 120px;
}
.du-bar {
  flex: 1;
  height: 7px;
  min-width: 70px;
  border-radius: 9999px;
  overflow: hidden;
  background: var(--lg-line);
}
.du-bar span {
  display: block;
  height: 100%;
  border-radius: 9999px;
  background: linear-gradient(90deg, #34d399, #047857);
}
</style>

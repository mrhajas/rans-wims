<template>
  <admin-layout :title="$t('Task Manager Report')">
    <div class="tm-bg-decor" aria-hidden="true">
      <span class="tm-blob tm-blob-1"></span>
      <span class="tm-blob tm-blob-2"></span>
    </div>
    <div class="w-full relative z-[1]">
      <div class="tm-hero rounded-2xl p-6 mb-6 relative overflow-hidden">
        <div class="relative flex flex-col md:flex-row md:items-center md:justify-between gap-4">
          <div class="flex items-center gap-4">
            <div class="tm-hero-icon flex items-center justify-center h-14 w-14 rounded-2xl flex-none">
              <icons name="chart-bar" class="w-7 h-7 text-white" />
            </div>
            <div>
              <h1 class="text-xl font-bold text-gray-900">{{ $t('Task Manager Report') }}</h1>
              <p class="text-sm text-gray-500 mt-0.5">{{ $t('Filter, sort, print, and download task activity across the team.') }}</p>
            </div>
          </div>
          <div class="flex items-center gap-2" v-if="$can('print-reports-task-manager')">
            <a :href="printUrl" target="_blank" class="tm-report-btn inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold">
              <icons name="printer" class="w-4 h-4" />
              {{ $t('Print') }}
            </a>
            <a :href="pdfUrl" class="tm-new-btn tm-new-btn-team inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-white font-semibold text-sm">
              <icons name="download" class="w-4 h-4" />
              {{ $t('Download PDF') }}
            </a>
          </div>
        </div>

        <div class="relative grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mt-6">
          <div class="tm-report-stat">
            <span class="tm-report-stat-dot" style="background:#64748b"></span>
            <div class="text-lg font-bold text-slate-700">{{ stats.total }}</div>
            <div class="text-[11px] text-gray-500">{{ $t('Total') }}</div>
          </div>
          <div class="tm-report-stat">
            <span class="tm-report-stat-dot" style="background:#94a3b8"></span>
            <div class="text-lg font-bold" style="color:#94a3b8">{{ stats.todo }}</div>
            <div class="text-[11px] text-gray-500">{{ $t('To Do') }}</div>
          </div>
          <div class="tm-report-stat">
            <span class="tm-report-stat-dot" style="background:#2563eb"></span>
            <div class="text-lg font-bold text-blue-600">{{ stats.in_progress }}</div>
            <div class="text-[11px] text-gray-500">{{ $t('In Progress') }}</div>
          </div>
          <div class="tm-report-stat">
            <span class="tm-report-stat-dot" style="background:#d97706"></span>
            <div class="text-lg font-bold text-amber-600">{{ stats.review }}</div>
            <div class="text-[11px] text-gray-500">{{ $t('Review') }}</div>
          </div>
          <div class="tm-report-stat">
            <span class="tm-report-stat-dot" style="background:#059669"></span>
            <div class="text-lg font-bold text-emerald-600">{{ stats.completed }}</div>
            <div class="text-[11px] text-gray-500">{{ $t('Completed') }}</div>
          </div>
          <div class="tm-report-stat">
            <span class="tm-report-stat-dot" style="background:#e11d48"></span>
            <div class="text-lg font-bold text-rose-600">{{ stats.overdue }}</div>
            <div class="text-[11px] text-gray-500">{{ $t('Overdue') }}</div>
          </div>
        </div>

        <div class="relative grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 mt-6">
          <div class="tm-field-container">
            <span class="tm-field-label"><icons name="calendar" class="w-3.5 h-3.5" />{{ $t('From') }}</span>
            <date-picker v-model="form.date_from" class="w-full" @update:modelValue="apply" />
          </div>
          <div class="tm-field-container">
            <span class="tm-field-label"><icons name="calendar" class="w-3.5 h-3.5" />{{ $t('To') }}</span>
            <date-picker v-model="form.date_to" class="w-full" @update:modelValue="apply" />
          </div>
          <div class="tm-field-container">
            <span class="tm-field-label"><icons name="user" class="w-3.5 h-3.5" />{{ $t('Assignee') }}</span>
            <simple-autocomplete v-model="form.assignee_id" :options="userOptions" placeholder="Anyone" @update:modelValue="apply" />
          </div>
          <div class="tm-field-container">
            <span class="tm-field-label"><icons name="exclamation" class="w-3.5 h-3.5" />{{ $t('Priority') }}</span>
            <simple-autocomplete v-model="form.priority" :options="priorityOptions" placeholder="Any" @update:modelValue="apply" />
          </div>
          <div class="tm-field-container">
            <span class="tm-field-label"><icons name="collection" class="w-3.5 h-3.5" />{{ $t('Status') }}</span>
            <simple-autocomplete v-model="form.status" :options="statusOptions" placeholder="Any" @update:modelValue="apply" />
          </div>
          <div class="tm-field-container">
            <span class="tm-field-label"><icons name="folder" class="w-3.5 h-3.5" />{{ $t('Category') }}</span>
            <simple-autocomplete v-model="form.category" :options="categoryOptions" placeholder="Any" @update:modelValue="apply" />
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <div class="tm-side-card">
          <div class="tm-side-header"><icons name="chart-pie" class="w-4 h-4" />{{ $t('Tasks by Status') }}</div>
          <div v-for="(count, status) in byStatus" :key="status" class="tm-bar-row">
            <span class="tm-bar-label"><span class="tm-bar-dot" :style="{ background: statusColor(status) }"></span>{{ statusLabel(status) }}</span>
            <div class="tm-bar-track"><div class="tm-bar-fill" :style="{ width: barPct(count, byStatus) + '%', background: statusColor(status) }"></div></div>
            <span class="tm-bar-count">{{ count }}</span>
          </div>
        </div>
        <div class="tm-side-card">
          <div class="tm-side-header"><icons name="chart-bar" class="w-4 h-4" />{{ $t('Tasks by Priority') }}</div>
          <div v-for="(count, name) in byPriority" :key="name" class="tm-bar-row">
            <span class="tm-bar-label">{{ priorityLabel(name) }}</span>
            <div class="tm-bar-track"><div class="tm-bar-fill tm-bar-fill-rose" :style="{ width: barPct(count, byPriority) + '%' }"></div></div>
            <span class="tm-bar-count">{{ count }}</span>
          </div>
        </div>
      </div>

      <div class="tm-side-card">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-gray-200">
                <th v-for="col in columns" :key="col.key" class="text-left py-2.5 px-3 cursor-pointer select-none whitespace-nowrap" :class="{ 'min-w-[140px]': col.key === 'status' }" @click="toggleSort(col.key)">
                  <span class="inline-flex items-center gap-1 text-xs font-bold text-gray-500 uppercase tracking-wide">
                    {{ col.label }}
                    <icons v-if="form.sort === col.key" :name="form.direction === 'asc' ? 'up' : 'down'" class="w-3 h-3 text-indigo-600" />
                  </span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="task in tasks.data" :key="task.id" class="border-b border-gray-100 hover:bg-indigo-50/40 cursor-pointer" @click="openTask(task)">
                <td class="py-2.5 px-3 font-semibold text-gray-800">{{ task.title }}</td>
                <td class="py-2.5 px-3">
                  <span class="tm-priority-badge" :class="`tm-priority-badge-${task.priority}`">{{ priorityLabel(task.priority) }}</span>
                </td>
                <td class="py-2.5 px-3">
                  <span class="tm-status-badge" :class="`tm-status-badge-${task.status}`">{{ statusLabel(task.status) }}</span>
                </td>
                <td class="py-2.5 px-3 text-gray-600">{{ task.category || '-' }}</td>
                <td class="py-2.5 px-3 text-gray-600">{{ (task.assignees || []).map(a => a.assignee?.name).filter(Boolean).join(', ') || '-' }}</td>
                <td class="py-2.5 px-3 text-gray-600">{{ task.due_date ? formatDate(task.due_date) : '-' }}</td>
              </tr>
              <tr v-if="!tasks.data.length">
                <td colspan="6" class="text-center py-10 text-gray-400">{{ $t('No tasks match these filters.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="mt-4 tm-pagination-wrap">
          <pagination :meta="tasks.meta" :links="tasks.links" />
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import debounce from 'lodash/debounce';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import Pagination from '@/Shared/Pagination.vue';
import SimpleAutocomplete from '@/Shared/SimpleAutocomplete.vue';

export default {
  props: {
    stats: { type: Object, required: true },
    byPriority: { type: Object, default: () => ({}) },
    byStatus: { type: Object, default: () => ({}) },
    tasks: { type: Object, required: true },
    assignableUsers: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
  },

  components: { AdminLayout, DatePicker, Pagination, SimpleAutocomplete },

  data() {
    return {
      form: {
        date_from: this.filters.date_from || '',
        date_to: this.filters.date_to || '',
        assignee_id: this.filters.assignee_id || '',
        priority: this.filters.priority || '',
        status: this.filters.status || '',
        category: this.filters.category || '',
        sort: this.filters.sort || 'due_date',
        direction: this.filters.direction || 'asc',
      },
      columns: [
        { key: 'title', label: 'Title' },
        { key: 'priority', label: 'Priority' },
        { key: 'status', label: 'Status' },
        { key: 'category', label: 'Category' },
        { key: 'assignees', label: 'Assignees' },
        { key: 'due_date', label: 'Due Date' },
      ],
    };
  },

  computed: {
    printUrl() {
      return this.route('task-manager.reports.print', this.form);
    },
    pdfUrl() {
      return this.route('task-manager.reports.pdf', this.form);
    },
    userOptions() {
      return this.assignableUsers.map(u => ({ value: u.id, label: u.name, icon: 'user' }));
    },
    priorityOptions() {
      return [
        { value: 'low', label: this.$t('Low'), icon: 'down' },
        { value: 'normal', label: this.$t('Normal'), icon: 'toggle' },
        { value: 'high', label: this.$t('High'), icon: 'up' },
        { value: 'urgent', label: this.$t('Urgent'), icon: 'exclamation' },
      ];
    },
    statusOptions() {
      return [
        { value: 'todo', label: this.$t('To Do'), icon: 'collection' },
        { value: 'in_progress', label: this.$t('In Progress'), icon: 'refresh' },
        { value: 'review', label: this.$t('Review'), icon: 'eye' },
        { value: 'done', label: this.$t('Completed'), icon: 'tick' },
      ];
    },
    categoryOptions() {
      return this.categories.map(c => ({ value: c, label: c, icon: 'folder' }));
    },
  },

  methods: {
    apply: debounce(function () {
      this.$inertia.get(this.route('task-manager.reports'), this.form, { preserveState: true, replace: true });
    }, 300),
    openTask(task) {
      this.$inertia.visit(this.route('task-manager.index', { task: task.id }));
    },
    toggleSort(key) {
      if (!['title', 'priority', 'status', 'category', 'due_date'].includes(key)) return;
      if (this.form.sort === key) {
        this.form.direction = this.form.direction === 'asc' ? 'desc' : 'asc';
      } else {
        this.form.sort = key;
        this.form.direction = 'asc';
      }
      this.apply();
    },
    statusLabel(status) {
      return { todo: this.$t('To Do'), in_progress: this.$t('In Progress'), review: this.$t('Review'), done: this.$t('Completed') }[status] || status;
    },
    statusColor(status) {
      return { todo: '#94a3b8', in_progress: '#2563eb', review: '#d97706', done: '#059669' }[status] || '#818cf8';
    },
    priorityLabel(value) {
      return { low: this.$t('Low'), normal: this.$t('Normal'), high: this.$t('High'), urgent: this.$t('Urgent') }[value] || value;
    },
    formatDate(date) {
      return new Date(date).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    },
    barPct(count, obj) {
      const max = Math.max(1, ...Object.values(obj));
      return Math.max(4, Math.round((count / max) * 100));
    },
  },
};
</script>

<style scoped>
.tm-bg-decor { position: fixed; inset: 0; z-index: 0; overflow: hidden; pointer-events: none; }
.tm-blob { position: absolute; border-radius: 9999px; filter: blur(80px); opacity: 0.25; }
.tm-blob-1 { top: -80px; left: -60px; height: 320px; width: 320px; background: radial-gradient(circle, #c7d2fe, transparent 70%); }
.tm-blob-2 { top: 100px; right: -100px; height: 360px; width: 360px; background: radial-gradient(circle, #bfdbfe, transparent 70%); }

.tm-hero {
  background: linear-gradient(135deg, rgba(255, 255, 255, 0.9), rgba(238, 242, 255, 0.6));
  border: 1px solid rgba(255, 255, 255, 0.8);
  backdrop-filter: blur(20px) saturate(160%);
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 20px 40px -12px rgba(99, 102, 241, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.7);
}
.tm-hero-icon { background: linear-gradient(145deg, #818cf8, #6366f1); box-shadow: 0 8px 20px -6px rgba(99, 102, 241, 0.5); }
.tm-new-btn { transition: transform 0.15s ease; }
.tm-new-btn:hover { transform: translateY(-1px); }
.tm-new-btn-team { background: linear-gradient(135deg, #6366f1, #8b5cf6); box-shadow: 0 6px 16px -4px rgba(99, 102, 241, 0.5); }
.tm-report-btn {
  background: rgba(255, 255, 255, 0.7);
  color: #4338ca;
  border: 1.5px solid rgba(99, 102, 241, 0.25);
}
.tm-report-btn:hover { background: rgba(238, 242, 255, 0.9); }

.tm-report-stat {
  background: rgba(255, 255, 255, 0.6);
  border: 1px solid rgba(255, 255, 255, 0.9);
  border-radius: 12px;
  padding: 10px;
  text-align: center;
  position: relative;
}
.tm-report-stat-dot { position: absolute; top: 8px; right: 8px; width: 7px; height: 7px; border-radius: 9999px; }

.tm-field-container {
  display: flex;
  flex-direction: column;
  background: rgba(255, 255, 255, 0.5);
  border: 1px solid rgba(15, 23, 42, 0.06);
  border-radius: 12px;
  padding: 10px 12px;
}
.tm-field-label {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 0.68rem;
  font-weight: 700;
  color: #4f46e5;
  text-transform: uppercase;
  margin-bottom: 6px;
}
.tm-side-card {
  background: rgba(255, 255, 255, 0.7);
  border: 1px solid rgba(255, 255, 255, 0.9);
  border-radius: 16px;
  padding: 16px;
  backdrop-filter: blur(14px);
}
.tm-side-header { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; font-weight: 700; color: #334155; margin-bottom: 12px; }

.tm-bar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.tm-bar-label { display: inline-flex; align-items: center; gap: 6px; width: 110px; font-size: 0.78rem; color: #475569; text-transform: capitalize; flex: none; }
.tm-bar-dot { height: 8px; width: 8px; border-radius: 9999px; flex: none; }
.tm-bar-track { flex: 1; height: 8px; border-radius: 9999px; background: rgba(100, 116, 139, 0.12); overflow: hidden; }
.tm-bar-fill { height: 100%; background: linear-gradient(90deg, #818cf8, #6366f1); border-radius: 9999px; transition: background 0.15s ease; }
.tm-bar-fill-rose { background: linear-gradient(90deg, #fb7185, #e11d48); }
.tm-bar-count { width: 30px; text-align: right; font-size: 0.78rem; font-weight: 700; color: #1e293b; flex: none; }

.tm-priority-badge { display: inline-flex; align-items: center; font-size: 0.66rem; font-weight: 700; padding: 2px 8px; border-radius: 9999px; text-transform: uppercase; }
.tm-priority-badge-low { background: #f1f5f9; color: #64748b; }
.tm-priority-badge-normal { background: #e0e7ff; color: #4338ca; }
.tm-priority-badge-high { background: #fef3c7; color: #d97706; }
.tm-priority-badge-urgent { background: #fee2e2; color: #dc2626; }

.tm-status-badge { display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; font-size: 0.7rem; font-weight: 700; padding: 3px 10px; border-radius: 9999px; }
.tm-status-badge::before { content: ''; height: 6px; width: 6px; border-radius: 9999px; background: currentColor; }
.tm-status-badge-todo { background: rgba(148, 163, 184, 0.16); color: #64748b; }
.tm-status-badge-in_progress { background: rgba(37, 99, 235, 0.14); color: #2563eb; }
.tm-status-badge-review { background: rgba(217, 119, 6, 0.14); color: #d97706; }
.tm-status-badge-done { background: rgba(5, 150, 105, 0.14); color: #059669; }

/* Scoped override of the shared Pagination.vue's own info-text color —
   doesn't touch the component itself, so every other page using it is
   unaffected. */
.tm-pagination-wrap :deep(.hidden.xl\:flex > div:first-child),
.tm-pagination-wrap :deep(.xl\:hidden > div.mx-3) {
  color: #7f1d1d;
  font-weight: 600;
}
</style>

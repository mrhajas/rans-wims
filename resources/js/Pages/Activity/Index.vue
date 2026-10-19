<template>
  <admin-layout :title="$t('System Logs')">
    <div class="lg-page px-4 md:px-0">
      <header class="lg-hero print:hidden">
        <span class="lg-hero-icon"><icons name="collection" class="w-6 h-6" /></span>
        <div class="lg-hero-body">
          <h1 class="lg-title">{{ $t('System Logs') }}</h1>
          <p class="lg-sub">{{ $t('The full audit trail of every significant action in WIMS.') }}</p>
        </div>
        <div class="lg-hero-actions">
          <button type="button" class="lg-btn lg-btn-primary" @click="printPdf"><icons name="printer" class="w-4 h-4" />{{ $t('Print PDF') }}</button>
        </div>
      </header>

      <section class="lg-stats print:hidden">
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-navy"><icons name="list" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value">{{ activities.meta.total.toLocaleString() }}</div>
            <div class="lg-stat-label">{{ $t('Total Logged Events') }}</div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-indigo"><icons name="users" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value">{{ users.length.toLocaleString() }}</div>
            <div class="lg-stat-label">{{ $t('Users') }}</div>
          </div>
        </div>
      </section>

      <section class="lg-card lg-filters relative z-20 print:hidden">
        <search-filter :dropdown="false" v-model="form.search" @reset="reset" />
        <div class="lg-grid">
          <date-range-picker v-model="dateRange" :placeholder="$t('Date range')" clearable />
          <auto-complete v-model="form.user_id" :suggestions="userOptions" :placeholder="$t('All users')" clearable />
          <auto-complete v-model="form.module" :suggestions="moduleOptions" :placeholder="$t('All modules')" clearable />
        </div>
      </section>

      <section class="lg-card" style="padding: 1rem">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('When') }}</th>
                <th>{{ $t('Module') }}</th>
                <th>{{ $t('Description') }}</th>
                <th>{{ $t('By') }}</th>
                <th>{{ $t('Device / Location') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="activity in activities.data" :key="activity.id" class="is-click" @click="showDetails(activity)">
                <td :data-label="$t('When')">
                  <div class="lg-main">{{ $date(activity.created_at) }}</div>
                  <div class="lg-subtext">{{ $time(activity.created_at) }}</div>
                </td>
                <td :data-label="$t('Module')"><span class="lg-chip" :class="chipClass(activity.log_name)">{{ activity.log_name }}</span></td>
                <td :data-label="$t('Description')">
                  <div class="lg-clip lg-tip tip-left" :data-tip="activity.description">{{ activity.description }}</div>
                </td>
                <td :data-label="$t('By')">
                  <div v-if="activity.causer" class="lg-person">
                    <span class="lg-avatar">{{ initials(activity.causer.name) }}</span>
                    <span class="lg-main">{{ activity.causer.name }}</span>
                  </div>
                  <span v-else class="lg-subtext">—</span>
                </td>
                <td :data-label="$t('Device / Location')">
                  <div>{{ ctx(activity).device || '—' }}</div>
                  <div class="lg-subtext">{{ ctx(activity).location || ctx(activity).ip || '—' }}</div>
                </td>
              </tr>
              <tr v-if="activities.data.length === 0" class="lg-empty-row">
                <td colspan="5" class="lg-empty">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <pagination class="mt-2 print:hidden" :meta="activities.meta" :links="activities.links" />
    </div>

    <!-- Activity Details Modal -->
    <modal :show="details" max-width="2xl" :closeable="true" @close="hideDetails">
      <div class="px-6 py-5 print:px-0">
        <div class="flex items-center justify-between print:hidden mb-1">
          <div class="flex items-center gap-3">
            <div class="act-modal-icon flex items-center justify-center h-9 w-9 rounded-xl flex-none">
              <icons name="collection" class="w-4 h-4 text-white" />
            </div>
            <div class="text-base font-bold text-gray-900">{{ $t('Activity Details') }}</div>
          </div>
          <button type="button" @click="hideDetails()" class="act-close-btn">
            <icons name="cross" class="h-4 w-4" />
          </button>
        </div>

        <div v-if="activity" class="mt-4">
          <div class="act-detail-panel">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
              <div class="act-field"><span class="act-label">{{ $t('Created at') }}</span><span class="act-value">{{ $datetime(activity.created_at) }}</span></div>
              <div class="act-field"><span class="act-label">{{ $t('Name') }}</span><span class="act-value"><span class="act-name-chip">{{ activity.log_name }}</span></span></div>
              <div class="act-field sm:col-span-2"><span class="act-label">{{ $t('Description') }}</span><span class="act-value">{{ activity.description }}</span></div>
              <div class="act-field"><span class="act-label">{{ $t('By') }}</span><span class="act-value">{{ activity.causer ? activity.causer.name : '—' }}</span></div>
              <div class="act-field"><span class="act-label">{{ $t('Subject Type') }}</span><span class="act-value mono text-xs">{{ activity.subject_type }}</span></div>
              <div class="act-field"><span class="act-label">{{ $t('Subject Id') }}</span><span class="act-value">{{ activity.subject_id }}</span></div>
              <div class="act-field"><span class="act-label">{{ $t('IP Address') }}</span><span class="act-value mono">{{ ctx(activity).ip || '—' }}</span></div>
              <div class="act-field"><span class="act-label">{{ $t('Location') }}</span><span class="act-value">{{ ctx(activity).location || '—' }}</span></div>
              <div class="act-field"><span class="act-label">{{ $t('Device') }}</span><span class="act-value">{{ ctx(activity).device || '—' }}</span></div>
              <div class="act-field"><span class="act-label">{{ $t('OS') }}</span><span class="act-value">{{ ctx(activity).os || '—' }}</span></div>
            </div>
          </div>
        </div>
      </div>
    </modal>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import Modal from '@/Jetstream/Modal.vue';
import Pagination from '@/Shared/Pagination.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DateRangePicker from '@/Shared/DateRangePicker.vue';

export default {
  components: {
    Modal,
    Pagination,
    AdminLayout,
    SearchFilter,
    AutoComplete,
    DateRangePicker,
  },

  props: {
    filters: Object,
    activities: Object,
    users: { type: Array, default: () => [] },
    modules: { type: Array, default: () => [] },
  },

  data() {
    return {
      activity: null,
      details: false,
      form: {
        search: this.filters.search ?? null,
        from: this.filters.from ?? null,
        to: this.filters.to ?? null,
        user_id: this.filters.user_id ?? null,
        module: this.filters.module ?? null,
      },
    };
  },

  computed: {
    moduleOptions() {
      return this.modules.map(m => ({ id: m.value, label: this.$t(m.label) }));
    },
    userOptions() {
      return this.users.map(u => ({ id: u.id, label: u.name }));
    },
    dateRange: {
      get() {
        return { start: this.form.from, end: this.form.to };
      },
      set(value) {
        this.form.from = value?.start || null;
        this.form.to = value?.end || null;
      },
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        let query = pickBy(this.form);
        this.$inertia.visit(this.route('activity', Object.keys(query).length ? query : { remember: 'forget' }), {
          onFinish: () => {
            document.getElementById('page-search').focus();
          },
        });
      }, 150),
      deep: true,
    },
  },

  methods: {
    // Same module name always gets the same colour.
    chipClass(name) {
      const palette = ['lg-chip-blue', 'lg-chip-green', 'lg-chip-violet', 'lg-chip-amber', 'lg-chip-cyan', 'lg-chip-red', 'lg-chip-slate'];
      const sum = String(name || '').split('').reduce((a, c) => a + c.charCodeAt(0), 0);
      return palette[sum % palette.length];
    },
    initials(name) {
      return String(name || '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map(w => w[0].toUpperCase())
        .join('');
    },
    ctx(activity) {
      return (activity && activity.properties && activity.properties.context) || {};
    },
    showDetails(activity) {
      this.activity = activity;
      this.details = true;
    },
    hideDetails() {
      this.activity = null;
      this.details = false;
    },
    reset() {
      this.form = mapValues(this.form, () => null);
    },
    printPdf() {
      const query = pickBy(this.form);
      query.print = 1;
      const url = this.route('activity', query);
      window.open(url, '_blank');
    },
  },
};
</script>

<style scoped>
.act-modal-icon { background: linear-gradient(145deg, #1c3f66, #0f2540); }
.act-close-btn { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; border-radius: 9999px; color: #64748b; transition: background 0.15s ease, color 0.15s ease; }
.act-close-btn:hover { background: #f1f5f9; color: #1f2937; }
.act-name-chip { display: inline-flex; align-items: center; padding: 0.2rem 0.6rem; border-radius: 9999px; background: rgba(59, 130, 246, 0.13); color: #1d4ed8; font-size: 0.72rem; font-weight: 800; }
.act-detail-panel { background: #f8fafc; border-radius: 1rem; padding: 1.1rem 1.25rem; }
.act-field { display: flex; flex-direction: column; gap: 0.2rem; }
.act-label { font-size: 0.66rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; }
.act-value { font-size: 0.86rem; color: #1f2937; font-weight: 600; word-break: break-word; }
.mono { font-family: inherit; font-variant-numeric: tabular-nums; }
html.dark .act-detail-panel { background: #1e293b; }
html.dark .act-value { color: #e2e8f0; }
html.dark .act-name-chip { color: #93c5fd; }
</style>

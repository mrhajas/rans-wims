<template>
  <admin-layout :title="$t('Download / Print Logs')">
    <div class="lg-page px-4 md:px-0">
      <header class="lg-hero print:hidden">
        <span class="lg-hero-icon"><icons name="download" class="w-6 h-6" /></span>
        <div class="lg-hero-body">
          <h1 class="lg-title">{{ $t('Download / Print Logs') }}</h1>
          <p class="lg-sub">{{ $t('Audit trail for all print and download actions across the system.') }}</p>
        </div>
        <div class="lg-hero-actions">
          <button type="button" class="lg-btn lg-btn-soft" @click="printPage"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" @click="downloadPdf"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</button>
        </div>
      </header>

      <section class="lg-stats print:hidden">
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-navy"><icons name="list" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value">{{ logs.total.toLocaleString() }}</div><div class="lg-stat-label">{{ $t('Total Events') }}</div></div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-emerald"><icons name="download" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value is-good">{{ stats.downloads.toLocaleString() }}</div><div class="lg-stat-label">{{ $t('Downloads') }}</div></div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-indigo"><icons name="printer" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value">{{ stats.prints.toLocaleString() }}</div><div class="lg-stat-label">{{ $t('Prints') }}</div></div>
        </div>
      </section>

      <section class="lg-card lg-filters relative z-20 print:hidden">
        <search-filter v-model="form.search" :dropdown="false" @reset="reset" />
        <div class="lg-grid">
          <auto-complete v-model="form.action_type" :suggestions="actionOptions" :placeholder="$t('All Actions')" clearable />
          <auto-complete v-model="form.module" :suggestions="moduleOptions" :placeholder="$t('All Modules')" clearable />
          <date-picker v-model="form.start_date" :placeholder="$t('Date From')" clearable />
          <date-picker v-model="form.end_date" :placeholder="$t('Date To')" clearable />
        </div>
      </section>

      <section class="lg-card" style="padding: 1rem">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th class="is-sortable" @click="setSort('performed_at')"><span class="lg-sort">{{ $t('When') }}<icons v-if="form.sort === 'performed_at'" :name="form.sort_direction === 'asc' ? 'up' : 'down'" class="w-3 h-3" /></span></th>
                <th class="is-sortable" @click="setSort('user_name')"><span class="lg-sort">{{ $t('User') }}<icons v-if="form.sort === 'user_name'" :name="form.sort_direction === 'asc' ? 'up' : 'down'" class="w-3 h-3" /></span></th>
                <th class="is-sortable" @click="setSort('action_type')"><span class="lg-sort">{{ $t('Action') }}<icons v-if="form.sort === 'action_type'" :name="form.sort_direction === 'asc' ? 'up' : 'down'" class="w-3 h-3" /></span></th>
                <th class="is-sortable" @click="setSort('module')"><span class="lg-sort">{{ $t('Module') }}<icons v-if="form.sort === 'module'" :name="form.sort_direction === 'asc' ? 'up' : 'down'" class="w-3 h-3" /></span></th>
                <th>{{ $t('File') }}</th>
                <th>{{ $t('IP') }}</th>
                <th class="is-sortable" @click="setSort('browser')"><span class="lg-sort">{{ $t('Device') }}<icons v-if="form.sort === 'browser'" :name="form.sort_direction === 'asc' ? 'up' : 'down'" class="w-3 h-3" /></span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="log in logs.data" :key="log.id">
                <td :data-label="$t('When')">
                  <div class="lg-main">{{ $date(log.performed_at) }}</div>
                  <div class="lg-subtext">{{ $time(log.performed_at) }}</div>
                </td>
                <td :data-label="$t('User')">
                  <div class="lg-person">
                    <span class="lg-avatar">{{ initials(log.user_name || (log.user && log.user.name)) }}</span>
                    <div class="min-w-0">
                      <div class="lg-main">{{ log.user_name || (log.user && log.user.name) || '—' }}</div>
                      <div class="lg-subtext">{{ log.role || '—' }}</div>
                    </div>
                  </div>
                </td>
                <td :data-label="$t('Action')">
                  <span class="lg-chip" :class="log.action_type === 'print' ? 'lg-chip-indigo' : 'lg-chip-green'">
                    <icons :name="log.action_type === 'print' ? 'printer' : 'download'" class="w-3 h-3" />{{ log.action_type }}
                  </span>
                </td>
                <td :data-label="$t('Module')"><span class="lg-chip lg-chip-blue">{{ log.module ? titleCase(log.module) : '—' }}</span></td>
                <td :data-label="$t('File')">
                  <div class="lg-clip lg-tip tip-left" :data-tip="log.file_name || log.route_name">{{ log.file_name || '—' }}</div>
                  <div class="lg-subtext lg-clip">{{ log.route_name || '—' }}</div>
                </td>
                <td :data-label="$t('IP')">
                  <div class="lg-mono">{{ log.ip_address || '—' }}</div>
                  <div class="lg-subtext lg-clip">{{ log.location || '—' }}</div>
                </td>
                <td :data-label="$t('Device')">
                  <div>{{ log.device || '—' }}</div>
                  <div class="lg-subtext">{{ [log.os, log.browser].filter(Boolean).join(' · ') || '—' }}</div>
                </td>
              </tr>
              <tr v-if="logs.data.length === 0" class="lg-empty-row">
                <td colspan="7" class="lg-empty">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <pagination class="mt-2 print:hidden" :meta="logs" :links="{ prev: logs.prev_page_url, next: logs.next_page_url }" />
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import Pagination from '@/Shared/Pagination.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';

export default {
  components: {
    Pagination,
    AdminLayout,
    SearchFilter,
    AutoComplete,
    DatePicker,
  },

  props: {
    filters: Object,
    modules: Array,
    logs: Object,
    stats: { type: Object, default: () => ({ downloads: 0, prints: 0 }) },
  },

  data() {
    return {
      form: {
        search: this.filters.search ?? null,
        action_type: this.filters.action_type ?? null,
        module: this.filters.module ?? null,
        start_date: this.filters.start_date ?? null,
        end_date: this.filters.end_date ?? null,
        sort: this.filters.sort ?? 'performed_at',
        sort_direction: this.filters.sort_direction ?? 'desc',
      },
    };
  },

  computed: {
    actionOptions() {
      return [
        { id: 'download', label: this.$t('Download') },
        { id: 'print', label: this.$t('Print') },
      ];
    },
    moduleOptions() {
      return this.modules.map(module => ({ id: module, label: this.titleCase(module) }));
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('download-print-logs.index', Object.keys(query).length ? query : { remember: 'forget' }), {
          preserveState: true,
          replace: true,
        });
      }, 250),
      deep: true,
    },
  },

  methods: {
    initials(name) {
      return String(name || '?')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map(w => w[0].toUpperCase())
        .join('');
    },
    titleCase(value) {
      return String(value)
        .replace(/[-_]+/g, ' ')
        .split(' ')
        .filter(Boolean)
        .map(word => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
    },
    reset() {
      this.form = mapValues(this.form, () => null);
      this.form.sort = 'performed_at';
      this.form.sort_direction = 'desc';
    },
    setSort(column) {
      if (this.form.sort === column) {
        this.form.sort_direction = this.form.sort_direction === 'asc' ? 'desc' : 'asc';
        return;
      }
      this.form.sort = column;
      this.form.sort_direction = 'asc';
    },
    printPage() {
      const query = pickBy(this.form);
      window.open(this.route('download-print-logs.print', Object.keys(query).length ? query : {}), '_blank');
    },
    downloadPdf() {
      const query = pickBy(this.form);
      window.open(this.route('download-print-logs.pdf', Object.keys(query).length ? query : {}), '_blank');
    },
  },
};
</script>

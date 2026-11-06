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
          <button type="button" class="lg-btn lg-btn-primary" :disabled="pdfRunning" :class="{ 'opacity-60 cursor-not-allowed': pdfRunning }" @click="downloadPdf"><icons name="download" class="w-4 h-4" />{{ pdfRunning ? $t('Already running') : $t('Download') }}</button>
        </div>
      </header>

      <!-- older events were moved to the archive by WIMS Logs: said here, with a way to read them -->
      <section v-if="archived" class="dpl-arch print:hidden" role="note">
        <span class="dpl-arch-ico"><icons name="archive-box" class="w-5 h-5" /></span>
        <div class="dpl-arch-text">
          <b>{{ $t(':n older events are in the archive', { n: archived.rows.toLocaleString() }) }}</b>
          <span>{{ $t('From :a to :b. Events stay in this list for :d days, then WIMS Logs moves them to a compressed archive. Nothing is deleted.', { a: day(archived.from), b: day(archived.to), d: archived.keep_days }) }}</span>
        </div>
        <Link v-if="archived.can_read" :href="route('wims-logs.browse', { source: 'downloads' })" class="lg-btn tone tone-solid dpl-arch-go" style="--tone: #059669"><span class="tone-ico"><icons name="search" class="w-4 h-4" /></span>{{ $t('Read archived events') }}</Link>
      </section>

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
import { Link } from '@inertiajs/vue3';
import pickBy from 'lodash/pickBy';
import { startBackgroundTask, isTaskRunning } from '@/Core/backgroundTasks';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import Pagination from '@/Shared/Pagination.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';

export default {
  components: {
    Link,
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
    archived: { type: Object, default: null },
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
    pdfRunning() { return isTaskRunning('download-print-logs-pdf'); },
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
    day(d) { return d ? String(d).slice(0, 10) : '-'; },
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
    async downloadPdf() {
      const query = pickBy(this.form);
      // made in the background (the toast shows it and says when it is ready); if that is switched off or cannot start, the old way still works
      if (await startBackgroundTask(this, 'download-print-logs-pdf', query)) return;
      window.open(this.route('download-print-logs.pdf', Object.keys(query).length ? query : {}), '_blank');
    },
  },
};
</script>

<style scoped>
.dpl-arch { display: flex; align-items: center; gap: 0.9rem; flex-wrap: wrap; margin: 0 0 1rem; padding: 0.85rem 1rem; border-radius: 1.1rem; border: 1.5px solid rgba(5, 150, 105, 0.28); background: linear-gradient(135deg, rgba(5, 150, 105, 0.1), rgba(99, 102, 241, 0.07)); animation: dpl-in 0.45s cubic-bezier(0.2, 0.8, 0.2, 1) backwards; }
.dpl-arch-ico { flex: none; width: 2.5rem; height: 2.5rem; display: grid; place-items: center; border-radius: 0.9rem; color: #fff; background: linear-gradient(135deg, #34d399, #059669); box-shadow: 0 8px 14px -8px #059669; }
.dpl-arch-text { display: grid; gap: 0.1rem; flex: 1 1 18rem; min-width: 0; } .dpl-arch-text b { font-size: 0.92rem; } .dpl-arch-text span { font-size: 0.78rem; color: #64748b; line-height: 1.4; }
.dpl-arch-go { transition: transform 0.2s ease; } .dpl-arch-go:hover { transform: translateY(-2px); }
@keyframes dpl-in { from { opacity: 0; transform: translateY(10px); } }
@media (max-width: 640px) { .dpl-arch-go { width: 100%; justify-content: center; } }
@media (prefers-reduced-motion: reduce) { .dpl-arch { animation: none; } }
</style>

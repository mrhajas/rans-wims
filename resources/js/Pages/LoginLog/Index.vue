<template>
  <admin-layout :title="$t('Login Logs')">
    <div class="lg-page px-4 md:px-0">
      <header class="lg-hero print:hidden">
        <span class="lg-hero-icon"><icons name="key" class="w-6 h-6" /></span>
        <div class="lg-hero-body">
          <h1 class="lg-title">{{ $t('Login Logs') }}</h1>
          <p class="lg-sub">{{ $t('Track successful and failed login attempts.') }}</p>
        </div>
        <div class="lg-hero-actions">
          <button v-if="canExport" type="button" class="lg-btn lg-btn-soft" @click="exportExcel"><icons name="table" class="w-4 h-4" />{{ $t('Export Excel') }}</button>
          <button v-if="canPrint" type="button" class="lg-btn lg-btn-soft" @click="openPrint"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</button>
          <button v-if="canPrint" type="button" class="lg-btn lg-btn-primary" @click="downloadPdf"><icons name="download" class="w-4 h-4" />{{ $t('PDF') }}</button>
        </div>
      </header>

      <address-tabs :counts="addressTabs" active="logs" />

      <section class="lg-stats print:hidden">
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-navy"><icons name="list" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value">{{ loginLogs.total.toLocaleString() }}</div><div class="lg-stat-label">{{ $t('Total Attempts') }}</div></div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value is-good">{{ stats.success.toLocaleString() }}</div><div class="lg-stat-label">{{ $t('Successful') }}</div></div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-rose"><icons name="exclamation" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value is-bad">{{ stats.failed.toLocaleString() }}</div><div class="lg-stat-label">{{ $t('Failed') }}</div></div>
        </div>
      </section>

      <section class="lg-card lg-filters relative z-20 print:hidden">
        <search-filter v-model="form.search" :dropdown="false" @reset="reset" />
        <div class="lg-grid">
          <auto-complete v-model="form.status" :suggestions="statusOptions" :placeholder="$t('All Status')" clearable />
          <auto-complete v-model="form.role" :suggestions="roleOptions" :placeholder="$t('All Roles')" clearable />
          <date-picker v-model="form.start_date" :placeholder="$t('Date From')" clearable />
          <date-picker v-model="form.end_date" :placeholder="$t('Date To')" clearable />
        </div>
      </section>

      <section class="lg-card" style="padding: 1rem">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th class="is-sortable" @click="setSort('attempted_at')"><span class="lg-sort">{{ $t('When') }}<icons v-if="form.sort === 'attempted_at'" :name="form.sort_direction === 'asc' ? 'up' : 'down'" class="w-3 h-3" /></span></th>
                <th class="is-sortable" @click="setSort('username')"><span class="lg-sort">{{ $t('User') }}<icons v-if="form.sort === 'username'" :name="form.sort_direction === 'asc' ? 'up' : 'down'" class="w-3 h-3" /></span></th>
                <th class="is-sortable" @click="setSort('role')"><span class="lg-sort">{{ $t('Role') }}<icons v-if="form.sort === 'role'" :name="form.sort_direction === 'asc' ? 'up' : 'down'" class="w-3 h-3" /></span></th>
                <th class="is-sortable" @click="setSort('status')"><span class="lg-sort">{{ $t('Status') }}<icons v-if="form.sort === 'status'" :name="form.sort_direction === 'asc' ? 'up' : 'down'" class="w-3 h-3" /></span></th>
                <th class="is-sortable" @click="setSort('ip_address')"><span class="lg-sort">{{ $t('IP Address') }}<icons v-if="form.sort === 'ip_address'" :name="form.sort_direction === 'asc' ? 'up' : 'down'" class="w-3 h-3" /></span></th>
                <th class="is-sortable" @click="setSort('browser')"><span class="lg-sort">{{ $t('Device') }}<icons v-if="form.sort === 'browser'" :name="form.sort_direction === 'asc' ? 'up' : 'down'" class="w-3 h-3" /></span></th>
                <th class="print:hidden"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="log in loginLogs.data" :key="log.id">
                <td :data-label="$t('When')">
                  <div class="lg-main">{{ $date(log.attempted_at) }}</div>
                  <div class="lg-subtext">{{ $time(log.attempted_at) }}</div>
                </td>
                <td :data-label="$t('User')">
                  <div class="lg-person">
                    <span class="lg-avatar">{{ initials(log.user ? log.user.name : log.username) }}</span>
                    <div class="min-w-0">
                      <div class="lg-main">{{ log.user ? log.user.name : '—' }}</div>
                      <div class="lg-subtext">{{ log.username || '—' }}</div>
                    </div>
                  </div>
                </td>
                <td :data-label="$t('Role')">{{ log.role || '—' }}</td>
                <td :data-label="$t('Status')">
                  <span
                    class="lg-chip lg-tip tip-left"
                    :class="log.status === 'success' ? 'lg-chip-green' : 'lg-chip-red'"
                    :data-tip="log.error_message || (log.status === 'success' ? $t('Login successful') : $t('Invalid credentials'))"
                  >
                    <icons :name="log.status === 'success' ? 'tick' : 'exclamation'" class="w-3 h-3" />{{ log.status }}
                  </span>
                </td>
                <td :data-label="$t('IP Address')">
                  <div class="lg-mono">{{ log.ip_address || '—' }}</div>
                  <div class="lg-subtext lg-clip">{{ log.location || '—' }}</div>
                </td>
                <td :data-label="$t('Device')">
                  <div>{{ log.device || '—' }}</div>
                  <div class="lg-subtext">{{ [log.os, log.browser].filter(Boolean).join(' · ') || '—' }}</div>
                </td>
                <td class="print:hidden" :data-label="$t('Details')">
                  <div class="lg-actions">
                    <Link :href="route('login-logs.show', log.id)" class="lg-icon-btn lg-tip" :data-tip="$t('View Details')"><icons name="view" class="w-4 h-4" /></Link>
                    <template v-if="addressTabs && log.ip_address">
                      <button type="button" class="lg-icon-btn lg-tip is-red" :data-tip="$t('Blacklist this address')" :aria-label="$t('Blacklist this address')" @click="markAddress(log, 'black')"><icons name="lock-closed" class="w-4 h-4" /></button>
                      <button type="button" class="lg-icon-btn lg-tip is-green" :data-tip="$t('Whitelist this address')" :aria-label="$t('Whitelist this address')" @click="markAddress(log, 'white')"><icons name="tick" class="w-4 h-4" /></button>
                    </template>
                  </div>
                </td>
              </tr>
              <tr v-if="loginLogs.data.length === 0" class="lg-empty-row">
                <td colspan="7" class="lg-empty">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <pagination class="mt-2 print:hidden" :meta="loginLogs" :links="{ prev: loginLogs.prev_page_url, next: loginLogs.next_page_url }" />
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
import AddressTabs from './AddressTabs.vue';

export default {
  components: {
    AddressTabs,
    Pagination,
    AdminLayout,
    SearchFilter,
    AutoComplete,
    DatePicker,
  },

  props: {
    filters: Object,
    roles: Array,
    loginLogs: Object,
    addressTabs: { type: Object, default: null },
    canExport: Boolean,
    canPrint: Boolean,
    stats: { type: Object, default: () => ({ success: 0, failed: 0 }) },
  },

  data() {
    return {
      form: {
        search: this.filters.search ?? null,
        status: this.filters.status ?? null,
        role: this.filters.role ?? null,
        start_date: this.filters.start_date ?? null,
        end_date: this.filters.end_date ?? null,
        sort: this.filters.sort ?? 'attempted_at',
        sort_direction: this.filters.sort_direction ?? 'desc',
      },
    };
  },

  computed: {
    statusOptions() {
      return [
        { id: 'success', label: this.$t('Success') },
        { id: 'failed', label: this.$t('Failed') },
      ];
    },
    roleOptions() {
      return this.roles.map(role => ({ id: role, label: role }));
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('login-logs.index', Object.keys(query).length ? query : { remember: 'forget' }), {
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
    // block or trust the address of this attempt (Super Admin)
    markAddress(log, type) {
      const ask = type === 'black' ? this.$t('Blacklist :ip? Nobody will be able to sign in from it.', { ip: log.ip_address }) : this.$t('Whitelist :ip? It will never be counted or blocked.', { ip: log.ip_address });
      if (!confirm(ask)) return;
      this.$inertia.post(route('login-logs.addresses.store'), { ip: log.ip_address, type, reason: this.$t('From Login Log of :u', { u: log.username || '-' }) }, { preserveScroll: true });
    },
    reset() {
      this.form = mapValues(this.form, () => null);
      this.form.sort = 'attempted_at';
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
    exportExcel() {
      const query = pickBy(this.form);
      window.open(this.route('login-logs.export', query), '_blank');
    },
    openPrint() {
      const query = pickBy(this.form);
      window.open(this.route('login-logs.print', query), '_blank');
    },
    downloadPdf() {
      const query = pickBy(this.form);
      window.open(this.route('login-logs.pdf', query), '_blank');
    },
  },
};
</script>

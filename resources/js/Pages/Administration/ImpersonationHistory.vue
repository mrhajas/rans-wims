<template>
  <admin-layout :title="$t('Impersonation History')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="clock" :title="$t('Impersonation History')" :subtitle="$t('Every &quot;Login As User&quot; session ever started, with who, why, and how long.')">
        <button type="button" class="lg-btn lg-btn-dark" @click="print">
          <icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}
        </button>
        <button type="button" class="lg-btn lg-btn-primary" @click="download">
          <icons name="download" class="w-4 h-4" /> {{ $t('Download') }}
        </button>
        <Link :href="route('impersonation.active')" class="lg-btn lg-btn-soft">
          <icons name="impersonate" class="w-3.5 h-3.5" /> {{ $t('Active Impersonations') }}
        </Link>
      </page-header>

      <section class="lg-card lg-filters imp-filters relative z-20 print:hidden">
        <div class="lg-grid">
          <auto-complete
            id="imp-filter-reason"
            :label="$t('Reason')"
            :placeholder="$t('All Reasons')"
            v-model="form.reason"
            :clearable="true"
            :suggestions="reasons.map(r => ({ label: r, value: r }))"
          />
          <auto-complete
            id="imp-filter-target"
            :label="$t('User Impersonated')"
            :placeholder="$t('All Users')"
            v-model="form.target_user_id"
            :clearable="true"
            :suggestions="targets.map(u => ({ label: u.name, value: u.id }))"
          />
          <auto-complete
            id="imp-filter-admin"
            :label="$t('Super Admin')"
            :placeholder="$t('All Super Admins')"
            v-model="form.original_user_id"
            :clearable="true"
            :suggestions="admins.map(u => ({ label: u.name, value: u.id }))"
          />
          <auto-complete
            id="imp-filter-status"
            :label="$t('Status')"
            :placeholder="$t('All Statuses')"
            v-model="form.status"
            :clearable="true"
            :suggestions="statuses.map(s => ({ label: statusLabel(s), value: s }))"
          />
          <date-picker v-model="form.date_from" :label="$t('From')" clearable />
          <date-picker v-model="form.date_to" :label="$t('To')" clearable />
        </div>
        <div class="mt-3">
          <button type="button" class="lg-btn lg-btn-soft" @click="clear">
            <icons name="cross" class="w-3.5 h-3.5" /> {{ $t('Clear') }}
          </button>
        </div>
      </section>

      <section class="lg-card" style="padding: 1rem">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Super Admin') }}</th>
                <th>{{ $t('User') }}</th>
                <th>{{ $t('Reason') }}</th>
                <th>{{ $t('Started') }}</th>
                <th>{{ $t('Duration') }}</th>
                <th>{{ $t('Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in sessions.data" :key="s.id">
                <td :data-label="$t('Super Admin')">
                  <div class="lg-person">
                    <span class="lg-avatar">{{ initials(s.original?.name) }}</span>
                    <span class="lg-main">{{ s.original?.name || '—' }}</span>
                  </div>
                </td>
                <td :data-label="$t('User')">{{ s.target?.name || '—' }}</td>
                <td :data-label="$t('Reason')">
                  <div class="lg-tip tip-left" :data-tip="s.reason"><div class="lg-clip truncate">{{ s.reason }}</div></div>
                </td>
                <td :data-label="$t('Started')"><span class="lg-subtext">{{ s.started_at ? $datetime(s.started_at) : '—' }}</span></td>
                <td :data-label="$t('Duration')"><span class="lg-subtext">{{ duration(s) }}</span></td>
                <td :data-label="$t('Status')">
                  <span class="lg-chip" :class="statusTone(s.status)">{{ statusLabel(s.status) }}</span>
                </td>
              </tr>
              <tr v-if="sessions.data.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="6">{{ $t('No impersonation sessions match these filters.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <pagination class="mt-2 print:hidden" :meta="sessions.meta" :links="sessions.links" />
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Shared/Pagination.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, Link, Pagination, AutoComplete, DatePicker, PageHeader },

  props: {
    sessions: Object,
    filters: Object,
    admins: Array,
    targets: Array,
    reasons: Array,
    statuses: Array,
  },

  data() {
    return {
      form: {
        reason: this.filters.reason || null,
        target_user_id: this.filters.target_user_id ? Number(this.filters.target_user_id) : null,
        original_user_id: this.filters.original_user_id ? Number(this.filters.original_user_id) : null,
        status: this.filters.status || null,
        date_from: this.filters.date_from,
        date_to: this.filters.date_to,
      },
    };
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('impersonation.history', query), { preserveState: true, preserveScroll: true, replace: true });
      }, 250),
      deep: true,
    },
  },

  methods: {
    statusTone(status) {
      return { active: 'lg-chip-green', ended: 'lg-chip-slate', expired: 'lg-chip-amber', terminated: 'lg-chip-red' }[status] || 'lg-chip-slate';
    },
    initials(name) {
      return String(name || '').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('');
    },
    clear() {
      this.form = { reason: null, target_user_id: null, original_user_id: null, status: null, date_from: null, date_to: null };
    },
    statusLabel(status) {
      return { active: this.$t('Active'), ended: this.$t('Ended'), expired: this.$t('Expired'), terminated: this.$t('Terminated') }[status] || status;
    },
    duration(s) {
      if (!s.started_at) return '—';
      const end = s.ended_at ? new Date(s.ended_at) : new Date();
      const minutes = Math.max(0, Math.round((end.getTime() - new Date(s.started_at).getTime()) / 60000));
      if (minutes < 1) return '<1 ' + this.$t('min');
      return minutes + ' ' + this.$t('min');
    },
    currentQuery() {
      return pickBy(this.form);
    },
    print() {
      window.open(this.route('impersonation.history.print', this.currentQuery()), '_blank');
    },
    download() {
      window.open(this.route('impersonation.history.download', this.currentQuery()), '_blank');
    },
  },
};
</script>

<style scoped>
/* DatePicker's own root div hardcodes col-span-6 sm:col-span-4 (for a 6-col form grid); neutralise it in this grid. */
.imp-filters :deep(.col-span-6.sm\:col-span-4) { grid-column: span 1 / span 1 !important; }
</style>

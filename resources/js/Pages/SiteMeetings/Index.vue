<template>
  <admin-layout :title="$t('All Site Meetings')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="calendar" :title="$t('All Site Meetings')" :subtitle="$t('Every scheduled, held, postponed and cancelled meeting, with its minutes.')">
        <Link :href="route('site-meetings.index')" class="lg-btn lg-btn-soft"><icons name="calendar" class="w-4 h-4" />{{ $t('Weekly schedule') }}</Link>
        <Link v-if="can.create" :href="route('site-meetings.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" />{{ $t('Schedule meeting') }}</Link>
      </page-header>

      <stat-tiles :items="tiles" @select="pickTile" />

      <section class="lg-card lg-filters">
        <div class="lg-fields sm-filter-grid">
          <div class="sm-field"><label for="smf-search">{{ $t('Search') }}</label><input id="smf-search" v-model="form.search" class="sm-input" type="search" :placeholder="$t('Reference, site, venue, agenda…')" /></div>
          <div class="sm-field">
            <label>{{ $t('Site') }}</label>
            <auto-complete id="smf-site" v-model="form.warehouse_id" :suggestions="sites" clearable :placeholder="$t('All sites')" />
          </div>
          <div class="sm-field">
            <label>{{ $t('Status') }}</label>
            <auto-complete id="smf-status" v-model="form.status" :suggestions="statusOptions" clearable :placeholder="$t('All statuses')" />
          </div>
          <div class="sm-field">
            <label>{{ $t('Minutes') }}</label>
            <auto-complete id="smf-report" v-model="form.report_status" :suggestions="reportOptions" clearable :placeholder="$t('Any')" />
          </div>
          <div class="sm-field"><label for="smf-from">{{ $t('From') }}</label><date-picker id="smf-from" v-model="form.date_from" :max="form.date_to || undefined" /></div>
          <div class="sm-field"><label for="smf-to">{{ $t('To') }}</label><date-picker id="smf-to" v-model="form.date_to" :min="form.date_from || undefined" /></div>
          <div class="sm-filter-reset">
            <button type="button" class="lg-btn lg-btn-soft" :disabled="!hasFilters" @click="reset"><icons name="refresh" class="w-4 h-4" />{{ $t('Reset') }}</button>
            <button v-if="can.delete" type="button" class="lg-btn" :class="form.trashed ? 'lg-btn-red' : 'lg-btn-soft'" @click="form.trashed = form.trashed ? null : 'only'"><icons name="trash" class="w-4 h-4" />{{ $t('Deleted') }}</button>
          </div>
        </div>
      </section>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th>{{ $t('No') }}</th>
              <th>{{ $t('Meeting') }}</th>
              <th>{{ $t('Date & time') }}</th>
              <th>{{ $t('Venue') }}</th>
              <th>{{ $t('People') }}</th>
              <th>{{ $t('Status') }}</th>
              <th class="lg-sticky-end"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(m, i) in meetings.data" :key="m.id" class="is-click" @click="$inertia.visit(route('site-meetings.show', m.id))">
              <td :data-label="$t('No')" class="sm-no">{{ (meetings.meta.from || 1) + i }}</td>
              <td :data-label="$t('Meeting')" class="sm-wrap"><div class="sm-cell">
                <span class="lg-main">{{ m.site.name }}</span>
                <div class="sm-sub">{{ m.title }} · <span class="lg-mono">{{ m.reference }}</span></div>
              </div></td>
              <td :data-label="$t('Date & time')"><div class="sm-cell">
                <Link :href="route('site-meetings.index', { week: m.date })" class="lg-main sm-datelink lg-tip" :data-tip="$t('Show this week on the schedule')" @click.stop>{{ $date(m.date) }}</Link>
                <div class="sm-sub">{{ m.start ? m.start + (m.end ? ' – ' + m.end : '') : '—' }}</div>
              </div></td>
              <td :data-label="$t('Venue')" class="sm-wrap">{{ m.venue || '—' }}</td>
              <td :data-label="$t('People')" class="sm-wrap"><div class="sm-cell">
                <span v-if="m.chairperson"><b>{{ $t('Chair') }}:</b> {{ m.chairperson }}</span>
                <div class="sm-sub">{{ m.attendees_count }} {{ $t('invited') }}<template v-if="m.recorder"> · {{ $t('Minutes') }}: {{ m.recorder }}</template></div>
              </div></td>
              <td :data-label="$t('Status')"><div class="sm-cell">
                <span v-if="m.deleted_at" class="sm-chip sm-c-red"><icons name="trash" class="w-3 h-3" />{{ $t('Deleted') }}</span>
                <template v-else>
                  <span class="sm-chip" :class="'sm-c-' + m.status_color">{{ m.status_label }}</span>
                  <div class="mt-1"><span class="sm-chip" :class="'sm-c-' + m.report_color"><icons name="doc-text" class="w-3 h-3" />{{ m.report_label }}</span></div>
                </template>
              </div></td>
              <td class="lg-sticky-end" :data-label="$t('Actions')" @click.stop>
                <div class="lg-actions sm-actions">
                  <Link :href="route('site-meetings.show', m.id)" class="sm-ab sm-ab-sky lg-tip" :data-tip="$t('View')" :aria-label="$t('View')"><icons name="doc" class="w-4 h-4" /></Link>
                  <Link v-if="!m.deleted_at" :href="route('site-meetings.report', m.id)" class="sm-ab sm-ab-violet lg-tip" :data-tip="$t('Minutes')" :aria-label="$t('Minutes')"><icons name="doc-text" class="w-4 h-4" /></Link>
                  <a v-if="can.print && !m.deleted_at" :href="route('site-meetings.report.print', m.id)" target="_blank" rel="noopener" class="sm-ab sm-ab-teal lg-tip" :data-tip="$t('Print minutes')" :aria-label="$t('Print minutes')"><icons name="printer" class="w-4 h-4" /></a>
                  <a v-if="can.print && !m.deleted_at" :href="route('site-meetings.report.pdf', m.id)" class="sm-ab sm-ab-indigo lg-tip" :data-tip="$t('Download minutes')" :aria-label="$t('Download minutes')"><icons name="download" class="w-4 h-4" /></a>
                  <Link v-if="can.create && m.can_work && !m.deleted_at" :href="route('site-meetings.create', { from: m.id })" class="sm-ab sm-ab-amber lg-tip" :data-tip="$t('Duplicate')" :aria-label="$t('Duplicate')"><icons name="duplicate" class="w-4 h-4" /></Link>
                  <Link v-if="can.update && m.can_work && !m.deleted_at" :href="route('site-meetings.edit', m.id)" class="sm-ab sm-ab-green lg-tip" :data-tip="$t('Edit')" :aria-label="$t('Edit')"><icons name="edit" class="w-4 h-4" /></Link>
                  <button v-if="m.deleted_at && can.delete && m.can_work" type="button" class="sm-ab sm-ab-green lg-tip" :data-tip="$t('Restore')" :aria-label="$t('Restore')" @click="restore(m)"><icons name="refresh" class="w-4 h-4" /></button>
                </div>
              </td>
            </tr>
            <tr v-if="!meetings.data.length" class="lg-empty-row"><td class="lg-empty" colspan="7">{{ $t('There is no data to display.') }}</td></tr>
          </tbody>
        </table>
      </div>
      <pagination v-if="meetings.data.length" class="mt-4" :meta="meetings.meta" :links="meetings.links" />
    </div>
  </admin-layout>
</template>

<script>
import throttle from 'lodash/throttle';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';

export default {
  components: { AdminLayout, Pagination, PageHeader, StatTiles, AutoComplete, DatePicker },

  props: { meetings: Object, filters: Object, stats: Object, sites: Array, statuses: Object, can: Object },

  data() {
    return {
      form: {
        search: this.filters.search || '',
        warehouse_id: this.filters.warehouse_id ? Number(this.filters.warehouse_id) : null,
        status: this.filters.status || null,
        report_status: this.filters.report_status || null,
        date_from: this.filters.date_from || '',
        date_to: this.filters.date_to || '',
        view: this.filters.view || null,
        trashed: this.filters.trashed || null,
      },
    };
  },

  computed: {
    statusOptions() {
      return ['Scheduled', 'Held', 'Postponed', 'Cancelled'].map(k => ({ label: this.statuses[k].label, value: k }));
    },
    reportOptions() {
      return ['Not started', 'Draft', 'Submitted', 'Finalised'].map(k => ({ label: this.statuses[k].label, value: k }));
    },
    hasFilters() {
      return Object.values(this.form).some(v => v !== null && v !== '');
    },
    tiles() {
      const s = this.stats;
      const on = (status, view) => (this.form.status || null) === status && (this.form.view || null) === view;
      return [
        { icon: 'calendar', tone: 'indigo', label: this.$t('All meetings'), value: s.total, status: null, view: null, active: on(null, null) },
        { icon: 'clock', tone: 'sky', label: this.statuses.Scheduled.label, value: s.Scheduled, status: 'Scheduled', view: null, active: on('Scheduled', null) },
        { icon: 'tick', tone: 'emerald', label: this.statuses.Held.label, value: s.Held, status: 'Held', view: null, active: on('Held', null) },
        { icon: 'exclamation', tone: 'amber', label: this.$t('Postponed / cancelled'), value: s.moved, status: null, view: 'moved', active: on(null, 'moved') },
        { icon: 'doc-text', tone: 'rose', label: this.$t('Minutes due'), value: s.reports_due, status: null, view: 'reports_due', active: on(null, 'reports_due') },
      ];
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const q = {};
        Object.entries(this.form).forEach(([k, v]) => v !== null && v !== '' && (q[k] = v));
        this.$inertia.get(this.route('site-meetings.list'), q, { preserveState: true, replace: true });
      }, 350),
      deep: true,
    },
  },

  methods: {
    pickTile(tile) {
      const same = (this.form.status || null) === tile.status && (this.form.view || null) === tile.view;
      this.form.status = same ? null : tile.status;
      this.form.view = same ? null : tile.view;
    },
    reset() {
      Object.keys(this.form).forEach(k => (this.form[k] = k === 'search' || k.startsWith('date') ? '' : null));
    },
    restore(m) {
      this.$inertia.put(this.route('site-meetings.restore', m.id), {}, { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.sm-filter-grid { grid-template-columns: repeat(auto-fit, minmax(min(170px, 100%), 1fr)); align-items: end; }
.sm-filter-reset { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; }
.sm-datelink { text-decoration: underline dotted; text-underline-offset: 3px; }
.sm-datelink:hover { color: #4338ca; }
.sm-no { width: 1%; font-weight: 800; color: var(--lg-faint); font-variant-numeric: tabular-nums; }
.sm-wrap { white-space: normal; min-width: 150px; max-width: 260px; }
@media (max-width: 767.98px) { .sm-cell { flex: 1 1 0; min-width: 0; text-align: right; } .sm-cell .sm-chip { max-width: 100%; } .sm-actions { justify-content: flex-end; flex-wrap: wrap; } }
.sm-sub { margin-top: 2px; font-size: 0.74rem; color: var(--lg-mute); }
</style>

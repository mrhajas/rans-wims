<template>
  <admin-layout :title="$t('Attendance Records')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="table" :title="$t('Attendance Records')" :subtitle="$t('Every clock-in across the team, with corrections and voids kept in the audit trail.')">
        <a v-if="$can('export-digital-attendance')" :href="route('digital-attendance-reports.export', filters)" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />{{ $t('Export') }}</a>
        <a :href="route('digital-attendance-reports.print', filters)" target="_blank" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
      </page-header>

      <stat-tiles :items="statTiles" @select="filterByTile" />

      <section class="lg-card lg-filters relative z-20 print:hidden">
        <div class="lg-grid" style="margin-top: 0">
          <input v-model="form.search" type="text" :placeholder="$t('Search employee…')" class="rec-input" />
          <auto-complete v-model="form.user_id" :suggestions="userOptions" :placeholder="$t('All Employees')" clearable />
          <auto-complete v-model="form.status" :suggestions="statusOptions" :placeholder="$t('All Statuses')" clearable />
          <date-picker v-model="form.date_from" :placeholder="$t('Date From')" clearable />
          <date-picker v-model="form.date_to" :placeholder="$t('Date To')" clearable />
        </div>
      </section>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th>{{ $t('Employee') }}</th>
              <th>{{ $t('Date') }}</th>
              <th>{{ $t('In') }}</th>
              <th>{{ $t('Out') }}</th>
              <th>{{ $t('Hours') }}</th>
              <th>{{ $t('Status') }}</th>
              <th class="lg-sticky-end"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in records.data" :key="r.id">
              <td :data-label="$t('Employee')">
                <div class="lg-person">
                  <span class="lg-avatar">{{ initials(r.user ? r.user.name : '') }}</span>
                  <span class="lg-main">{{ r.user ? r.user.name : '—' }}</span>
                </div>
              </td>
              <td :data-label="$t('Date')">{{ $date(r.attendance_date) }} <span v-if="r.sessions_count > 1" class="lg-chip lg-chip-cyan lg-tip tip-left" :data-tip="$t('Multiple sessions today')">&times;{{ r.sessions_count }}</span></td>
              <td :data-label="$t('In')">{{ r.clock_in_label || '—' }}</td>
              <td :data-label="$t('Out')">{{ r.clock_out_label || '—' }}</td>
              <td :data-label="$t('Hours')"><span class="lg-main rec-hours">{{ r.worked_label }}</span></td>
              <td :data-label="$t('Status')">
                <span class="lg-chip" :class="badgeClass(r)">{{ r.status.replace('_', ' ') }}</span>
                <span v-if="r.corrected" class="lg-chip lg-chip-slate ml-1">{{ $t('Corrected') }}</span>
              </td>
              <td :data-label="$t('Actions')" class="lg-sticky-end">
                <div class="lg-actions">
                  <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('View')" @click="openDetail(r)"><icons name="eye-flat" class="w-4 h-4" /></button>
                  <button v-if="canCorrect" type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Correct')" @click="openCorrect(r)"><icons name="edit" class="w-4 h-4" /></button>
                  <button v-if="canVoid && r.status !== 'VOIDED'" type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Void')" @click="openVoid(r)"><icons name="trash" class="w-4 h-4" /></button>
                </div>
              </td>
            </tr>
            <tr v-if="!records.data.length" class="lg-empty-row"><td colspan="7" class="lg-empty">{{ $t('No attendance records found.') }}</td></tr>
          </tbody>
        </table>
      </div>

      <pagination class="mt-6" :meta="records.meta" :links="records.links" />
    </div>

    <!-- Correct modal -->
    <modal :show="modal === 'correct'" @close="modal = null" max-width="md">
      <div class="lg-page rec-modal" v-if="active">
        <div class="rec-mhead rec-mhead-amber">
          <span class="lg-card-ico lg-ico-amber"><icons name="edit" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-card-title">{{ $t('Correct Attendance') }}</div>
            <div class="lg-card-sub truncate">{{ active.user ? active.user.name : '' }} &middot; {{ $date(active.attendance_date) }}</div>
          </div>
          <span class="lg-chip ml-auto flex-none" :class="badgeClass(active)">{{ active.status.replace('_', ' ') }}</span>
        </div>

        <div class="rec-mbody">
          <div class="lg-fields">
            <div class="rec-field rec-field-in">
              <label class="rec-field-label"><icons name="in" class="w-3.5 h-3.5" /> {{ $t('Clock In') }}</label>
              <input v-model="correctForm.clock_in_at" type="datetime-local" class="rec-input" />
            </div>
            <div class="rec-field rec-field-out">
              <label class="rec-field-label"><icons name="out" class="w-3.5 h-3.5" /> {{ $t('Clock Out') }}</label>
              <input v-model="correctForm.clock_out_at" type="datetime-local" class="rec-input" />
            </div>
            <div class="rec-field lg-span-all">
              <label class="rec-field-label"><icons name="doc-text" class="w-3.5 h-3.5" /> {{ $t('Reason for correction') }}</label>
              <textarea v-model="correctForm.reason" rows="3" class="rec-input resize-none" :placeholder="$t('e.g. User forgot to clock out.')"></textarea>
            </div>
          </div>
        </div>

        <div class="rec-mfoot">
          <button type="button" class="lg-btn lg-btn-soft" @click="modal = null"><icons name="cross" class="w-3.5 h-3.5" />{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" :disabled="!correctForm.reason" @click="submitCorrect"><icons name="tick" class="w-3.5 h-3.5" />{{ $t('Save Correction') }}</button>
        </div>
      </div>
    </modal>

    <!-- Void modal -->
    <modal :show="modal === 'void'" @close="modal = null" max-width="md">
      <div class="lg-page rec-modal" v-if="active">
        <div class="rec-mhead rec-mhead-red">
          <span class="lg-card-ico lg-ico-rose"><icons name="trash" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-card-title">{{ $t('Void Attendance Record') }}</div>
            <div class="lg-card-sub truncate">{{ active.user ? active.user.name : '' }} &middot; {{ $date(active.attendance_date) }}</div>
          </div>
        </div>

        <div class="rec-mbody">
          <div class="lg-note lg-note-amber mb-4">
            <icons name="exclamation" class="w-4 h-4" />
            <div>{{ $t('This record will be marked VOIDED and excluded from reports. History is preserved, not deleted.') }}</div>
          </div>
          <div class="rec-field">
            <label class="rec-field-label"><icons name="doc-text" class="w-3.5 h-3.5" /> {{ $t('Reason') }}</label>
            <textarea v-model="voidForm.reason" rows="2" class="rec-input resize-none"></textarea>
          </div>
        </div>

        <div class="rec-mfoot">
          <button type="button" class="lg-btn lg-btn-soft" @click="modal = null"><icons name="cross" class="w-3.5 h-3.5" />{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-red" :disabled="!voidForm.reason" @click="submitVoid"><icons name="trash" class="w-3.5 h-3.5" />{{ $t('Void Record') }}</button>
        </div>
      </div>
    </modal>

    <!-- Detail modal -->
    <modal :show="modal === 'detail'" @close="modal = null" max-width="lg">
      <div class="lg-page rec-modal" v-if="detail">
        <div class="rec-mhead rec-mhead-blue">
          <span class="lg-card-ico lg-ico-navy"><icons name="user" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-card-title truncate">{{ detail.record.user ? detail.record.user.name : '' }}</div>
            <div class="lg-card-sub">{{ $date(detail.record.attendance_date) }}</div>
          </div>
          <span class="lg-chip ml-auto flex-none" :class="badgeClass(detail.record)">{{ detail.record.status.replace('_', ' ') }}</span>
        </div>

        <div class="rec-mbody">
          <div class="rec-det-card">
            <div class="rec-det-title"><icons name="clock" class="w-3.5 h-3.5" /> {{ $t('Time') }}</div>
            <div class="grid grid-cols-2 gap-3 text-sm">
              <div><dt class="rec-label">{{ $t('Clock In') }}</dt><dd class="font-semibold">{{ detail.record.clock_in_label || '—' }}</dd></div>
              <div><dt class="rec-label">{{ $t('Clock Out') }}</dt><dd class="font-semibold">{{ detail.record.clock_out_label || '—' }}</dd></div>
              <div><dt class="rec-label">{{ $t('Worked') }}</dt><dd class="font-semibold rec-hours">{{ detail.record.worked_label }}</dd></div>
              <div v-if="detail.record.sessions_count > 1"><dt class="rec-label">{{ $t('Sessions Today') }}</dt><dd class="font-semibold">{{ detail.record.sessions_count }}</dd></div>
            </div>
          </div>

          <div v-if="canViewLocation" class="rec-det-card">
            <div class="rec-det-title"><icons name="map-pin" class="w-3.5 h-3.5" /> {{ $t('Location & IP') }}</div>
            <div class="text-sm space-y-2">
              <div>
                <dt class="rec-label">{{ $t('Clock-In Location') }}</dt>
                <dd>
                  <a v-if="detail.record.clock_in_latitude" :href="mapsUrl(detail.record.clock_in_latitude, detail.record.clock_in_longitude)" target="_blank" rel="noopener" class="rec-map-link">
                    {{ detail.record.clock_in_location_label || $t('Unknown place') }}
                  </a>
                  <span v-else>{{ $t('Unknown place') }}</span>
                </dd>
              </div>
              <div v-if="detail.record.clock_out_location_label">
                <dt class="rec-label">{{ $t('Clock-Out Location') }}</dt>
                <dd>
                  <a v-if="detail.record.clock_out_latitude" :href="mapsUrl(detail.record.clock_out_latitude, detail.record.clock_out_longitude)" target="_blank" rel="noopener" class="rec-map-link">
                    {{ detail.record.clock_out_location_label }}
                  </a>
                  <span v-else>{{ detail.record.clock_out_location_label }}</span>
                </dd>
              </div>
              <div class="flex items-center flex-wrap gap-2 pt-1">
                <span class="lg-chip lg-chip-indigo lg-mono" style="text-transform: none"><icons name="wifi" class="w-3 h-3" /> {{ detail.record.clock_in_ip || '—' }}</span>
                <span v-if="detail.record.clock_out_ip" class="lg-chip lg-chip-indigo lg-mono" style="text-transform: none"><icons name="wifi" class="w-3 h-3" /> {{ detail.record.clock_out_ip }}</span>
              </div>
            </div>
          </div>

          <div class="rec-det-card">
            <div class="rec-det-title"><icons name="mobile" class="w-3.5 h-3.5" /> {{ $t('Device') }}</div>
            <div class="text-sm font-semibold">{{ detail.record.clock_in_device }} / {{ detail.record.clock_in_browser }}</div>
          </div>

          <h4 class="rec-det-title mt-4"><icons name="doc-text" class="w-3.5 h-3.5" /> {{ $t('Event Audit Trail') }}</h4>
          <div class="space-y-2 max-h-64 overflow-y-auto">
            <div v-for="e in detail.events" :key="e.id" class="rec-event-row">
              <div class="flex items-center justify-between gap-2">
                <span class="font-semibold text-xs flex items-center gap-1.5"><icons :name="eventIcon(e.event_type)" class="w-3 h-3 opacity-50" /> {{ e.event_type.replace('_', ' ') }}</span>
                <span class="lg-subtext">{{ $datetime(e.created_at) }}</span>
              </div>
              <div class="text-xs mt-0.5 rec-muted">{{ e.description }}</div>
              <div v-if="e.actor" class="lg-subtext">{{ $t('By') }} {{ e.actor }}</div>
            </div>
            <div v-if="!detail.events.length" class="lg-empty">{{ $t('No events recorded.') }}</div>
          </div>
        </div>

        <div class="rec-mfoot">
          <button type="button" class="lg-btn lg-btn-soft" @click="modal = null"><icons name="cross" class="w-3.5 h-3.5" />{{ $t('Close') }}</button>
        </div>
      </div>
    </modal>
  </admin-layout>
</template>

<script>
import throttle from 'lodash/throttle';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Modal from '@/Jetstream/Modal.vue';
import Pagination from '@/Shared/Pagination.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, Modal, Pagination, DatePicker, AutoComplete, PageHeader, StatTiles },
  props: { records: Object, stats: { type: Object, default: null }, filters: Object, statuses: Array, users: Array, canViewLocation: Boolean, canCorrect: Boolean, canVoid: Boolean },
  data() {
    return {
      form: { ...this.filters },
      modal: null,
      active: null,
      detail: null,
      correctForm: { clock_in_at: '', clock_out_at: '', reason: '' },
      voidForm: { reason: '' },
    };
  },
  computed: {
    statTiles() {
      if (!this.stats) return [];
      return [
        { icon: 'list', tone: 'navy', label: this.$t('Records'), value: this.stats.total },
        { icon: 'lock-open', tone: 'emerald', label: this.$t('Clocked In'), value: this.stats.clocked_in, active: this.form.status === 'CLOCKED_IN', status: 'CLOCKED_IN' },
        { icon: 'exclamation', tone: 'amber', label: this.$t('Late'), value: this.stats.late },
        { icon: 'exclamation', tone: 'rose', label: this.$t('Missing Clock-Out'), value: this.stats.missing_clock_out, active: this.form.status === 'MISSING_CLOCK_OUT', status: 'MISSING_CLOCK_OUT' },
        { icon: 'trash', tone: 'slate', label: this.$t('Voided'), value: this.stats.voided, active: this.form.status === 'VOIDED', status: 'VOIDED' },
        { icon: 'clock', tone: 'sky', label: this.$t('Total Hours'), value: this.stats.hours_label },
      ];
    },
    userOptions() {
      return this.users.map(u => ({ id: u.id, label: u.name }));
    },
    statusOptions() {
      return this.statuses.map(s => ({ id: s, label: s.replace('_', ' ') }));
    },
  },
  watch: {
    form: {
      handler: throttle(function () {
        this.$inertia.get(this.route('digital-attendance-admin.records'), this.form, { preserveState: true, preserveScroll: true, replace: true });
      }, 400),
      deep: true,
    },
  },
  methods: {
    filterByTile(item) {
      if (!item.status) return;
      this.form.status = this.form.status === item.status ? null : item.status;
    },
    badgeClass(r) {
      if (r.status === 'MISSING_CLOCK_OUT') return 'lg-chip-red';
      if (r.status === 'VOIDED') return 'lg-chip-slate';
      if (r.is_late) return 'lg-chip-amber';
      return 'lg-chip-green';
    },
    initials(name) {
      return (name || '?')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map(w => w[0].toUpperCase())
        .join('') || '?';
    },
    mapsUrl(lat, lng) {
      return `https://www.google.com/maps?q=${lat},${lng}`;
    },
    eventIcon(type) {
      const map = { CLOCK_IN: 'lock-open', CLOCK_OUT: 'lock-closed', LOCATION_CAPTURE: 'map-pin', ADMIN_CORRECTION: 'edit', ADMIN_VOID: 'trash', SETTINGS_CHANGED: 'cog' };
      return map[type] || 'info';
    },
    openDetail(r) {
      this.active = r;
      axios.get(this.route('digital-attendance-admin.show', r.id)).then(res => {
        this.detail = res.data;
        this.modal = 'detail';
      });
    },
    openCorrect(r) {
      this.active = r;
      this.correctForm = {
        clock_in_at: r.clock_in_at ? r.clock_in_at.slice(0, 16) : '',
        clock_out_at: r.clock_out_at ? r.clock_out_at.slice(0, 16) : '',
        reason: '',
      };
      this.modal = 'correct';
    },
    openVoid(r) {
      this.active = r;
      this.voidForm = { reason: '' };
      this.modal = 'void';
    },
    submitCorrect() {
      this.$inertia.put(this.route('digital-attendance-admin.correct', this.active.id), this.correctForm, {
        preserveScroll: true,
        onSuccess: () => (this.modal = null),
      });
    },
    submitVoid() {
      this.$inertia.post(this.route('digital-attendance-admin.void', this.active.id), this.voidForm, {
        preserveScroll: true,
        onSuccess: () => (this.modal = null),
      });
    },
  },
};
</script>

<style scoped>
.rec-hours { color: #047857; }
html.dark .rec-hours { color: #6ee7b7; }
.rec-input { width: 100%; height: 2.6rem; padding: 0 0.9rem; font-size: 0.83rem; color: var(--lg-ink); background: var(--lg-card); border: 1px solid var(--lg-line); border-radius: 9999px; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
textarea.rec-input { height: auto; padding: 0.6rem 0.9rem; border-radius: 16px; }
.rec-input:focus { outline: none; border-color: #34d399; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15); }

/* ---- Modals (teleported to body, so the root carries .lg-page for the --lg-* variables) ---- */
.rec-modal { overflow: hidden; border-radius: 0.375rem; background: var(--lg-card); }
.rec-mhead { display: flex; align-items: center; gap: 0.75rem; padding: 1.1rem 1.4rem; border-bottom: 1px solid var(--lg-line); }
.rec-mhead-amber { background: linear-gradient(150deg, rgba(254, 243, 199, 0.7), rgba(255, 251, 235, 0.4)); }
.rec-mhead-red { background: linear-gradient(150deg, rgba(254, 226, 226, 0.7), rgba(255, 241, 242, 0.4)); }
.rec-mhead-blue { background: linear-gradient(150deg, rgba(219, 234, 254, 0.7), rgba(238, 242, 255, 0.4)); }
html.dark .rec-mhead-amber, html.dark .rec-mhead-red, html.dark .rec-mhead-blue { background: var(--lg-soft); }
.rec-mbody { padding: 1.25rem 1.4rem; max-height: 70vh; overflow-y: auto; }
.rec-mfoot { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 0.5rem; padding: 0.9rem 1.4rem 1.2rem; border-top: 1px solid var(--lg-line); background: var(--lg-soft); }
.rec-field-label { display: flex; align-items: center; gap: 0.35rem; margin-bottom: 0.35rem; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.rec-field-in .rec-field-label { color: #059669; }
.rec-field-out .rec-field-label { color: #dc2626; }

.rec-label { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.rec-muted { color: var(--lg-mute); }
.rec-det-card { margin-bottom: 0.75rem; padding: 0.9rem 1rem; border-radius: 16px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.rec-det-title { display: flex; align-items: center; gap: 0.4rem; margin-bottom: 0.6rem; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute); }
.rec-map-link { color: #2563eb; font-weight: 600; }
.rec-map-link:hover { text-decoration: underline; }
html.dark .rec-map-link { color: #93c5fd; }
.rec-event-row { padding: 0.55rem 0.8rem; border-radius: 12px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
</style>

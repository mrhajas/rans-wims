<template>
  <admin-layout :title="$t('Site Meetings')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="calendar" :title="$t('Site Meetings')" :subtitle="$t('Management’s weekly meeting schedule: which day, at which site.')">
        <Link :href="route('site-meetings.list')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('All meetings') }}</Link>
        <a v-if="can.print" :href="printUrl('site-meetings.print-week')" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('Print this week')" :aria-label="$t('Print this week')"><icons name="printer" class="w-4 h-4" /></a>
        <a v-if="can.print" :href="printUrl('site-meetings.pdf-week')" class="lg-icon-btn lg-tip" :data-tip="$t('Download this week')" :aria-label="$t('Download this week')"><icons name="download" class="w-4 h-4" /></a>
        <Link v-if="can.settings" :href="route('site-meetings.settings')" class="lg-icon-btn lg-tip" :data-tip="$t('Settings')" :aria-label="$t('Settings')"><icons name="cog" class="w-4 h-4" /></Link>
        <Link v-if="can.create" :href="route('site-meetings.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" />{{ $t('Schedule meeting') }}</Link>
      </page-header>

      <stat-tiles :items="tiles" @select="pickTile" />

      <section class="sm-weekbar">
        <div class="sm-week-nav">
          <button type="button" class="sm-week-btn" :aria-label="$t('Previous week')" @click="goWeek(week.prev)"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" /></button>
          <button type="button" class="sm-week-btn" :class="{ 'is-on': week.is_current }" @click="goWeek(null)">{{ $t('This week') }}</button>
          <button type="button" class="sm-week-btn" :aria-label="$t('Next week')" @click="goWeek(week.next)"><icons name="chevron-down" class="w-4 h-4 transform -rotate-90" /></button>
        </div>
        <div class="sm-week-label">
          <b>{{ week.label }}</b>
          <small>{{ $t('Week') }} {{ week.number }}</small>
        </div>
        <div class="sm-week-tools">
          <div class="sm-jump"><date-picker id="smb-jump" :model-value="week.start" :clearable="false" :placeholder="$t('Jump to a date')" @update:model-value="v => v && v !== week.start && goWeek(v)" /></div>
          <div class="sm-site-pick">
            <auto-complete id="smb-site" :model-value="filters.warehouse_id ? Number(filters.warehouse_id) : null" :suggestions="sites" clearable :placeholder="$t('All sites')" @update:model-value="setSite" />
          </div>
          <button type="button" class="lg-btn lg-btn-soft sm-xall" :disabled="!meetingCount" :aria-pressed="allOpen ? 'true' : 'false'" @click="toggleAll"><icons name="chevron-down" class="w-4 h-4 sm-xall-ico" :class="{ 'is-open': allOpen }" />{{ allOpen ? $t('Collapse All') : $t('Expand All') }}</button>
          <button v-if="filters.warehouse_id || filters.status || filters.view" type="button" class="lg-btn lg-btn-soft" @click="clear"><icons name="refresh" class="w-4 h-4" />{{ $t('Reset') }}</button>
        </div>
      </section>

      <div class="sm-board" :style="{ '--cols': Math.min(days.length, 7) }">
        <section
          v-for="d in board"
          :key="d.date"
          class="sm-day"
          :class="{ 'is-today': d.is_today, 'is-past': d.is_past, 'is-drop': dragging && over === d.date && canDropOn(d), 'is-nodrop': dragging && over === d.date && !canDropOn(d) }"
          @dragover="onDragOver(d, $event)"
          @dragleave="onDragLeave(d, $event)"
          @drop.prevent="onDrop(d)"
        >
          <header class="sm-day-head">
            <span class="sm-day-date"><b>{{ d.day }}</b><small>{{ d.month }}</small></span>
            <span class="sm-day-name"><b>{{ d.name }}</b><small>{{ d.is_today ? $t('Today') : d.meetings.length ? d.meetings.length + ' ' + $t(d.meetings.length === 1 ? 'meeting' : 'meetings') : $t('Free') }}</small></span>
            <Link v-if="can.create" :href="route('site-meetings.create', { date: d.date, warehouse_id: filters.warehouse_id || undefined })" class="sm-day-add lg-tip" :data-tip="$t('Schedule a meeting on this day')" :aria-label="$t('Schedule a meeting on this day')"><icons name="plus" class="w-4 h-4" /></Link>
          </header>
          <div class="sm-day-body">
            <Link
              v-for="m in d.meetings"
              :key="m.id"
              :href="route('site-meetings.show', m.id)"
              class="sm-card"
              :class="['sm-c-' + m.status_color, { 'is-cancelled': m.status === 'Cancelled', 'is-draggable': isMovable(m), 'is-dragging': dragging && dragging.id === m.id, 'is-saving': saving === m.id }]"
              :draggable="isMovable(m) ? 'true' : 'false'"
              @dragstart="onDragStart(m, d, $event)"
              @dragend="endDrag"
            >
              <div class="sm-card-top">
                <span class="sm-card-time"><icons name="clock" class="w-3.5 h-3.5" />{{ m.start ? m.start + (m.end ? ' – ' + m.end : '') : $t('Time not set') }}</span>
                <button type="button" class="sm-xbtn lg-tip" :class="{ 'is-open': isOpen(m) }" :data-tip="isOpen(m) ? $t('Hide details') : $t('Show details')" :aria-expanded="isOpen(m) ? 'true' : 'false'" :aria-label="isOpen(m) ? $t('Hide details') : $t('Show details')" @click.prevent.stop="toggle(m)">
                  <icons name="chevron-down" class="w-4 h-4" />
                </button>
              </div>
              <div class="sm-card-site">{{ m.site.name }}</div>
              <div class="sm-card-title">{{ m.title }}</div>
              <div class="sm-card-meta">
                <div v-if="m.venue" class="sm-card-row"><span class="sm-ri"><icons name="map-pin" class="w-3.5 h-3.5" /></span><span>{{ m.venue }}</span></div>
                <div v-if="m.chairperson" class="sm-card-row"><span class="sm-ri"><icons name="user" class="w-3.5 h-3.5" /></span><span>{{ m.chairperson }}</span></div>
              </div>
              <div class="sm-card-chips">
                <span class="sm-chip" :class="'sm-c-' + m.status_color">{{ m.status_label }}</span>
                <span v-if="m.report_status !== 'Not started' || m.status === 'Held'" class="sm-chip" :class="'sm-c-' + m.report_color"><icons name="doc-text" class="w-3 h-3" />{{ m.report_label }}</span>
              </div>

              <div class="sm-more-wrap" :class="{ 'is-open': isOpen(m) }" :aria-hidden="isOpen(m) ? 'false' : 'true'" @click.prevent.stop><div class="sm-more-clip"><div class="sm-more">
                <div class="sm-more-block">
                  <div class="sm-more-h"><icons name="users" class="w-3.5 h-3.5" />{{ $t('People invited') }}<b>{{ m.attendees_count }}</b></div>
                  <ul v-if="m.more && m.more.people.length" class="sm-more-people">
                    <li v-for="(p, pi) in m.more.people" :key="pi">
                      <span class="sm-pav">{{ initials(p.name) }}</span>
                      <span class="sm-pt"><b>{{ p.name }}</b><small>{{ [p.designation, p.organisation].filter(Boolean).join(' · ') || '\u00a0' }}</small></span>
                      <span v-if="p.attendance && p.attendance !== 'Invited'" class="sm-pa" :class="'is-' + String(p.attendance).toLowerCase()">{{ p.attendance }}</span>
                    </li>
                  </ul>
                  <p v-else class="sm-more-none">{{ $t('No one has been invited yet.') }}</p>
                </div>
                <div v-if="m.more && m.more.agenda" class="sm-more-block"><div class="sm-more-h"><icons name="list" class="w-3.5 h-3.5" />{{ $t('Agenda') }}</div><p class="sm-more-text">{{ m.more.agenda }}</p></div>
                <div v-if="m.more && m.more.info" class="sm-more-block"><div class="sm-more-h"><icons name="info" class="w-3.5 h-3.5" />{{ $t('Information') }}</div><p class="sm-more-text">{{ m.more.info }}</p></div>
                <div class="sm-more-grid">
                  <div><small>{{ $t('Meeting') }}</small><b>{{ m.meeting_no ? '#' + m.meeting_no : '-' }}</b></div>
                  <div><small>{{ $t('Minutes') }}</small><b>{{ m.recorder || $t('Not assigned') }}</b></div>
                  <div><small>{{ $t('Files') }}</small><b>{{ m.attachments_count || 0 }}</b></div>
                </div>
              </div></div></div>

              <label v-if="isMovable(m)" class="sm-move" @click.stop @mousedown.stop>
                <icons name="forward" class="w-3.5 h-3.5" />
                <css-select :aria-label="$t('Move to another day')" :value="d.date" @click.stop @change.stop="e => moveTo(m, d, e.target.value)">
                  <option v-for="x in board" :key="x.date" :value="x.date">{{ x.name }} {{ x.day }} {{ x.month }}</option>
                </css-select>
              </label>
              <div class="sm-card-foot">
                <span><icons name="users" class="w-3.5 h-3.5" />{{ m.attendees_count }}</span>
                <span v-if="m.attachments_count"><icons name="clip" class="w-3.5 h-3.5" />{{ m.attachments_count }}</span>
                <span class="ml-auto sm-ref">{{ m.reference }}</span>
              </div>
            </Link>
            <div v-if="!d.meetings.length" class="sm-empty"><icons name="calendar" class="w-4 h-4" />{{ dragging && canDropOn(d) ? $t('Drop here') : $t('No meeting') }}</div>
            <div v-else-if="dragging && canDropOn(d)" class="sm-dropzone"><icons name="plus" class="w-4 h-4" />{{ $t('Drop to move here') }}</div>
          </div>
        </section>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';

export default {
  components: { AdminLayout, PageHeader, StatTiles, AutoComplete, DatePicker },

  props: { week: Object, days: Array, stats: Object, filters: Object, sites: Array, statuses: Object, can: Object },

  data() {
    return { board: this.clone(this.days), dragging: null, over: null, saving: null, open: {} };
  },

  watch: {
    // a fresh answer from the server (week change, filter, or after a move) replaces the local copy
    days(v) {
      this.board = this.clone(v);
    },
  },

  computed: {
    meetingCount() {
      return this.board.reduce((n, d) => n + d.meetings.length, 0);
    },
    allOpen() {
      return this.meetingCount > 0 && this.board.every(d => d.meetings.every(m => this.open[m.id]));
    },
    // The cards count the week (for the chosen site) and filter the board when clicked; the lit card is the one applied.
    tiles() {
      const s = this.stats;
      const f = this.filters;
      const on = (status, view) => (f.status || null) === status && (f.view || null) === view;
      return [
        { icon: 'calendar', tone: 'indigo', label: this.$t('Meetings this week'), value: s.total, status: null, view: null, active: on(null, null) },
        { icon: 'clock', tone: 'sky', label: this.statuses.Scheduled.label, value: s.Scheduled, status: 'Scheduled', view: null, active: on('Scheduled', null) },
        { icon: 'tick', tone: 'emerald', label: this.statuses.Held.label, value: s.Held, status: 'Held', view: null, active: on('Held', null) },
        { icon: 'exclamation', tone: 'amber', label: this.$t('Postponed / cancelled'), value: s.moved, status: null, view: 'moved', active: on(null, 'moved') },
        { icon: 'doc-text', tone: 'rose', label: this.$t('Minutes due'), value: s.reports_due, status: null, view: 'reports_due', active: on(null, 'reports_due') },
      ];
    },
  },

  methods: {
    isOpen(m) {
      return !!this.open[m.id];
    },
    toggle(m) {
      this.open = { ...this.open, [m.id]: !this.open[m.id] };
    },
    toggleAll() {
      const next = !this.allOpen;
      const o = {};
      this.board.forEach(d => d.meetings.forEach(m => (o[m.id] = next)));
      this.open = o;
    },
    initials(name) {
      return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase();
    },
    clone(days) {
      return JSON.parse(JSON.stringify(days || []));
    },
    isMovable(m) {
      return !!this.can.update && !!m.movable;
    },
    canDropOn(d) {
      return !!this.dragging && d.date !== this.dragging.from;
    },
    onDragStart(m, d, e) {
      if (!this.isMovable(m)) {
        e.preventDefault();
        return;
      }
      this.dragging = { id: m.id, from: d.date };
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', String(m.id));
    },
    onDragOver(d, e) {
      if (!this.dragging) return;
      this.over = d.date;
      if (this.canDropOn(d)) {
        e.preventDefault(); // this day accepts the card
        e.dataTransfer.dropEffect = 'move';
      }
    },
    onDragLeave(d, e) {
      if (!e.currentTarget.contains(e.relatedTarget) && this.over === d.date) this.over = null;
    },
    endDrag() {
      this.dragging = null;
      this.over = null;
    },
    onDrop(d) {
      const drag = this.dragging;
      this.endDrag();
      if (!drag || d.date === drag.from) return;
      const src = this.board.find(x => x.date === drag.from);
      const m = src && src.meetings.find(x => x.id === drag.id);
      if (m) this.moveTo(m, src, d.date);
    },
    // Moves the card at once on screen, then saves the new date; if the server refuses, the card goes back.
    moveTo(m, fromDay, date) {
      if (date === fromDay.date) return;
      const target = this.board.find(x => x.date === date);
      if (!target) return;
      const original = this.clone(this.board);
      fromDay.meetings.splice(fromDay.meetings.findIndex(x => x.id === m.id), 1);
      m.date = date;
      if (m.status === 'Postponed') {
        m.status = 'Scheduled';
        m.status_label = this.statuses.Scheduled.label;
        m.status_color = this.statuses.Scheduled.color;
      }
      target.meetings.push(m);
      target.meetings.sort((a, b) => (a.start || '99:99').localeCompare(b.start || '99:99'));
      this.saving = m.id;
      this.$inertia.put(this.route('site-meetings.move', m.id), { date }, {
        preserveScroll: true,
        preserveState: true,
        only: ['days', 'stats', 'week'],
        onError: errors => {
          this.board = original;
          this.$alert(Object.values(errors)[0] || this.$t('The meeting could not be moved.'));
        },
        onFinish: () => (this.saving = null),
      });
    },
    query(extra = {}) {
      const q = { week: this.week.start, warehouse_id: this.filters.warehouse_id || undefined, status: this.filters.status || undefined, view: this.filters.view || undefined, ...extra };
      Object.keys(q).forEach(k => (q[k] === undefined || q[k] === null || q[k] === '') && delete q[k]);
      return q;
    },
    visit(q) {
      this.$inertia.get(this.route('site-meetings.index'), q, { preserveScroll: true, preserveState: true });
    },
    goWeek(date) {
      this.visit(this.query({ week: date || undefined }));
    },
    setSite(id) {
      this.visit(this.query({ warehouse_id: id || undefined }));
    },
    pickTile(tile) {
      const same = (this.filters.status || null) === tile.status && (this.filters.view || null) === tile.view;
      this.visit(this.query({ status: same ? undefined : tile.status || undefined, view: same ? undefined : tile.view || undefined }));
    },
    clear() {
      this.visit({ week: this.week.start });
    },
    printUrl(name) {
      return this.route(name, { week: this.week.start, warehouse_id: this.filters.warehouse_id || undefined });
    },
  },
};
</script>

<style scoped>
.sm-jump { width: 190px; flex: none; }
@media (max-width: 639.98px) { .sm-jump { width: 100%; } }
.sm-site-pick { min-width: 240px; max-width: 320px; flex: 1 1 240px; }
@media (max-width: 639.98px) { .sm-site-pick { max-width: none; flex-basis: 100%; } }
</style>

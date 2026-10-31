<template>
  <admin-layout :title="$t('Material Requests')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="clip" :title="$t('Material Requests')" :subtitle="$t('View and manage material requests')">
        <Link v-if="canCreate" :href="route('material-requests.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />{{ $t('Create Material Request') }}
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" lively @select="pickTile" />

      <div class="txn-toolbar mb-6 flex flex-col lg:flex-row gap-4 lg:items-end lg:justify-between print:hidden relative z-20">
        <search-filter v-model="form.search" class="w-full max-w-lg" @reset="reset">
          <auto-complete
            id="status"
            position="left"
            :label="$t('Status')"
            v-model="form.status"
            class="mt-1 w-full"
            :suggestions="statusOptions"
          />

          <auto-complete
            v-if="!ownRequestsOnly"
            id="warehouse"
            position="left"
            :label="$t('Warehouse')"
            v-model="form.warehouse_id"
            class="mt-1 w-full"
            :suggestions="warehouses"
          />

          <auto-complete
            v-if="!ownRequestsOnly"
            id="site-engineer"
            position="left"
            :label="$t('Requested By')"
            v-model="form.user_id"
            class="mt-1 w-full"
            :suggestions="requesters"
          />

          <text-input type="date" v-model="form.start_date" :label="$t('Date From')" class="mt-1 w-full" />
          <text-input type="date" v-model="form.end_date" :label="$t('Date To')" class="mt-1 w-full" />
        </search-filter>
      </div>

      <transition name="mr-bulk">
        <div v-if="canForce && picked.length" class="mr-bulk print:hidden" role="region" :aria-label="$t('Selected requests')">
          <span class="mr-bulk-ico"><icons name="lock-closed" class="w-5 h-5" /></span>
          <div class="mr-bulk-text"><b>{{ picked.length }}</b> {{ $t('request(s) selected') }}<small>{{ $t('Admin Force Clearance clears them in one step, skipping approval, issue and delivery. Needs your Super Admin password.') }}</small></div>
          <button v-if="clearableIds.length > picked.length" type="button" class="lg-btn bulk-btn bulk-btn-all" @click="picked = [...clearableIds]"><icons name="collection" class="w-4 h-4" />{{ $t('Select all :n', { n: clearableIds.length }) }}</button>
          <button type="button" class="lg-btn bulk-btn bulk-btn-none" @click="picked = []"><icons name="x-circle" class="w-4 h-4" />{{ $t('Clear selection') }}</button>
          <button type="button" class="lg-btn mr-bulk-go" @click="askForce"><icons name="lock-closed" class="w-4 h-4" />{{ $t('Clear Selected') }}</button>
        </div>
      </transition>

      <section class="lg-card mr-card">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive mr-table">
            <thead>
              <tr>
                <th v-if="canForce" class="mr-th-pick">
                  <label class="mr-pick lg-tip" :data-tip="$t('Select every request on this page that can be cleared')"><input type="checkbox" :checked="allPicked" :disabled="!pickable.length" :aria-label="$t('Select every request on this page that can be cleared')" @change="toggleAll" /></label>
                </th>
                <th class="mr-th-no">{{ $t('No') }}</th>
                <th>{{ $t('Request') }}</th>
                <th>{{ $t('Warehouse / Site') }}</th>
                <th>{{ $t('Requested By') }}</th>
                <th>{{ $t('Status') }}</th>
                <th class="lg-sticky-end">{{ $t('Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(materialRequest, index) in materialRequests.data" :key="materialRequest.id" class="is-click" :class="{ 'is-picked': picked.includes(materialRequest.id) }" @click="goto(materialRequest.id)">
                <td v-if="canForce" class="mr-td-pick" :data-label="$t('Select')" @click.stop>
                  <label v-if="isClearable(materialRequest)" class="mr-pick"><input type="checkbox" :value="materialRequest.id" v-model="picked" :aria-label="$t('Select this request')" /></label>
                </td>
                <td class="mr-td-no" :data-label="$t('No')">{{ rowNo(index) }}</td>
                <td :data-label="$t('Request')">
                  <div class="mr-id">
                    <span class="mr-ic tc-navy"><icons name="doc-text" class="w-4 h-4" /></span>
                    <div>
                    <div class="lg-main mr-ref">
                      <hover-preview category="material-requests" :id="materialRequest.id">{{ materialRequest.reference }}</hover-preview>
                      <span
                        v-if="materialRequest.status === 'Cleared'"
                        class="mr-lock lg-tip"
                        tabindex="0"
                        role="img"
                        :data-tip="$t('This request has been Delivered, Cleared and Locked. Contact Administrator for more info.')"
                        :aria-label="$t('This request has been Delivered, Cleared and Locked. Contact Administrator for more info.')"
                        @click.stop
                      ><icons name="lock-closed" class="w-3.5 h-3.5" /></span>
                    </div>
                    <div class="lg-subtext mr-date"><icons name="clock" class="w-3 h-3" />{{ $date(materialRequest.date_requested) }}</div>
                    </div>
                  </div>
                </td>
                <td :data-label="$t('Warehouse / Site')" class="mr-td-wh"><div class="mr-w"><span class="mr-wt">{{ materialRequest.warehouse?.name }}</span></div></td>
                <td :data-label="$t('Requested By')" class="mr-td-by"><div class="mr-u"><span class="mr-ut">{{ materialRequest.user?.name }}</span></div></td>
                <td :data-label="$t('Status')" class="mr-status-cell">
                  <div class="mr-chips">
                    <span
                      class="lg-chip mr-chip"
                      :class="[statusChip(materialRequest), { 'lg-tip': materialRequest.force_cleared }]"
                      :data-tip="materialRequest.force_cleared ? $t('Admin Force Clearance') : null"
                      :tabindex="materialRequest.force_cleared ? 0 : null"
                      style="text-transform: none"
                    >{{ statusLabel(materialRequest) }}</span>
                    <span v-if="materialRequest.quick" class="lg-chip lg-chip-indigo mr-chip" style="text-transform: none">{{ $t('Quick') }}</span>
                    <span v-if="materialRequest.delivery_state" class="lg-chip mr-chip" :class="materialRequest.delivery_state === 'Fully Delivered' ? 'mr-chip-fdel' : 'lg-chip-amber'" style="text-transform: none">
                      <icons name="tick" class="w-3 h-3" /><icons v-if="materialRequest.delivery_state === 'Fully Delivered'" name="tick" class="w-3 h-3 mr-tick2" />{{ $t(materialRequest.delivery_state) }}
                    </span>
                  </div>
                  <ol v-if="materialRequest.history && materialRequest.history.length" class="mr-trail">
                    <li v-for="h in materialRequest.history" :key="h.status" :class="'mr-trail-' + trailTone(h.status)">
                      <span class="mr-trail-dot"></span>
                      <span class="mr-trail-text">
                        <b class="mr-tl-cap">{{ trailLabel(h.status) }}</b>
                        <span class="mr-tl-who">{{ h.by || '-' }}</span>
                        <em>{{ $datetime(h.at) }}</em>
                      </span>
                    </li>
                  </ol>
                </td>
                <td :data-label="$t('Actions')" class="lg-sticky-end">
                  <div class="lg-actions" @click.stop>
                    <Link :href="route('material-requests.show', materialRequest.id)" class="lg-icon-btn lg-tip" :data-tip="$t('View')">
                      <icons name="eye-flat" class="w-4 h-4" />
                    </Link>
                    <button
                      v-if="materialRequest.can_remind || (materialRequest.can_view_reminders && materialRequest.reminder_count)"
                      type="button"
                      class="lg-icon-btn lg-tip mr-bell"
                      :class="[materialRequest.status === 'Pending Approval' ? 'is-indigo mr-bell-chase' : 'is-amber', { 'has-sent': materialRequest.reminder_count }]"
                      :data-tip="reminderTip(materialRequest)"
                      :aria-label="$t('Reminder')"
                      @click="openReminder(materialRequest)"
                    >
                      <icons :name="materialRequest.status === 'Pending Approval' ? 'speakerphone' : 'bell'" class="w-4 h-4" />
                      <b v-if="materialRequest.reminder_unread" class="mr-bell-n">{{ materialRequest.reminder_unread }}</b>
                    </button>
                    <Link
                      v-if="canEdit(materialRequest)"
                      :href="route('material-requests.edit', materialRequest.id)"
                      class="lg-icon-btn is-amber lg-tip"
                      :data-tip="$t('Edit')"
                    >
                      <icons name="edit" class="w-4 h-4" />
                    </Link>
                    <button
                      v-if="canDelete(materialRequest)"
                      type="button"
                      @click="deleteRequest(materialRequest)"
                      class="lg-icon-btn is-red lg-tip"
                      :data-tip="$t('Delete')"
                    >
                      <icons name="trash" class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="materialRequests.data.length === 0" class="lg-empty-row">
                <td class="lg-empty" :colspan="canForce ? 7 : 6">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <pagination class="mt-6" :meta="materialRequests.meta" :links="materialRequests.links" />
    </div>

    <super-admin-password-modal
      :show="forceAsk"
      :title="$t('Admin Force Clearance')"
      :content="$t(':n request(s) will be cleared in one step and locked, without approval, issuing or delivery. This cannot be undone.', { n: picked.length })"
      :lines="pickedNames"
      :confirm-text="$t('Clear them')"
      :processing="forceBusy"
      :error="forceError"
      @confirm="forceClear"
      @close="forceAsk = false"
    />
    <reminder-modal :show="reminderId !== null" :request-id="reminderId" @close="reminderId = null" @sent="onReminderSent" @read="onRemindersRead" />
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import Pagination from '@/Shared/Pagination.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TextInput from '@/Shared/TextInput.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';
import HoverPreview from '@/Shared/HoverPreview/HoverPreview.vue';
import SuperAdminPasswordModal from '@/Shared/SuperAdminPasswordModal.vue';
import ReminderModal from './ReminderModal.vue';

export default {
  components: {
    PageHeader,
    StatTiles,
    Pagination,
    AdminLayout,
    TextInput,
    AutoComplete,
    SearchFilter,
    HoverPreview,
    SuperAdminPasswordModal,
    ReminderModal,
  },

  props: {
    filters: Object,
    statuses: Array,
    warehouses: Array,
    requesters: Array,
    canCreate: Boolean,
    ownRequestsOnly: Boolean,
    materialRequests: Object,
    stats: { type: Object, default: null },
    clearable: { type: Object, default: () => ({}) },
  },

  data() {
    return {
      reminderId: null,
      picked: [],
      forceAsk: false,
      forceBusy: false,
      forceError: '',
      form: {
        search: this.filters.search,
        status: this.filters.status,
        quick: this.filters.quick,
        warehouse_id: this.filters.warehouse_id,
        user_id: this.filters.user_id,
        start_date: this.filters.start_date,
        end_date: this.filters.end_date,
      },
    };
  },

  computed: {
    // Super Admin only: tick Approved requests and clear them in one step (Admin Force Clearance)
    canForce() {
      return !!this.$super;
    },
    pickable() {
      return (this.materialRequests.data || []).filter(m => this.isClearable(m));
    },
    clearableIds() {
      return Object.keys(this.clearable || {}).map(Number);
    },
    allPicked() {
      return this.pickable.length > 0 && this.pickable.every(m => this.picked.includes(m.id));
    },
    pickedNames() {
      // always the references of what is selected right now (never bare ids)
      const byId = { ...(this.clearable || {}), ...Object.fromEntries((this.materialRequests.data || []).map(m => [m.id, m.reference])) };
      return this.picked.map(id => byId[id]).filter(Boolean);
    },
    statTiles() {
      if (!this.stats) {
        return [];
      }

      return [
        { icon: 'clip', tone: 'navy', label: this.$t('All Requests'), value: this.stats.total, status: null, active: !this.form.status },
        { icon: 'lock-closed', tone: 'emerald', label: this.$t('Cleared'), value: this.stats.cleared, status: 'Cleared', active: this.form.status === 'Cleared' },
        { icon: 'clock', tone: 'amber', label: this.$t('Pending Approval'), value: this.stats.pending, status: 'Pending Approval', active: this.form.status === 'Pending Approval' && !this.form.quick },
        { icon: 'lightning-bolt', tone: 'indigo', label: this.$t('Quick Pending Approval'), value: this.stats.quick_pending, status: 'Pending Approval', quick: true, active: this.form.status === 'Pending Approval' && !!this.form.quick },
        { icon: 'tick', tone: 'sky', label: this.$t('Approved'), value: this.stats.approved, status: 'Approved', active: this.form.status === 'Approved' },
        { icon: 'x-circle', tone: 'rose', label: this.$t('Rejected'), value: this.stats.rejected, status: 'Rejected', active: this.form.status === 'Rejected' },
        { icon: 'truck', tone: 'teal', label: this.$t('Fully Issued'), value: this.stats.issued, status: 'Issued', active: this.form.status === 'Issued' },
        { icon: 'truck', tone: 'violet', label: this.$t('Partially Issued'), value: this.stats.partially_issued, status: 'Partially Issued', active: this.form.status === 'Partially Issued' },
      ];
    },
    statusOptions() {
      return (this.statuses || []).map(s => ({ label: this.$t(s === 'Issued' ? 'Fully Issued' : s), value: s }));
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('material-requests.index', Object.keys(query).length ? query : { remember: 'forget' }), {
          preserveState: true,
          replace: true,
          onFinish: () => {
            const search = document.getElementById('page-search');
            if (search) {
              search.focus();
              const len = search.value ? search.value.length : 0;
              search.setSelectionRange(len, len);
            }
          },
        });
      }, 250),
      deep: true,
    },
  },

  methods: {
    openReminder(m) {
      this.reminderId = m.id;
    },
    reminderTip(m) {
      const chase = m.status === 'Pending Approval';
      if (!m.reminder_count) return chase ? this.$t('Remind the approvers') : this.$t('Send a reminder');
      return (chase ? this.$t('Approver reminder (:n sent)', { n: m.reminder_count }) : this.$t('Reminder (:n sent)', { n: m.reminder_count })) + (m.last_reminder_at ? ' · ' + this.$datetime(m.last_reminder_at) : '');
    },
    // "Mark all read" in the window: the number on that row's bell goes away
    onRemindersRead({ id }) {
      const row = (this.materialRequests.data || []).find(r => r.id === id);
      if (row) row.reminder_unread = 0;
    },
    onReminderSent({ id }) {
      const rows = this.materialRequests.data || [];
      const row = rows.find(r => r.id === id);
      if (row) { row.reminder_count = (row.reminder_count || 0) + 1; row.last_reminder_at = new Date().toISOString(); }
    },
    // every status except Rejected, Cleared and Closed can be cleared by force
    isClearable(m) {
      return !['Rejected', 'Cleared', 'Closed'].includes(m.status);
    },
    rowNo(index) {
      return ((this.materialRequests.meta && this.materialRequests.meta.from) || 1) + index;
    },
    toggleAll() {
      this.picked = this.allPicked ? this.picked.filter(id => !this.pickable.some(m => m.id === id)) : [...new Set([...this.picked, ...this.pickable.map(m => m.id)])];
    },
    askForce() {
      this.forceError = '';
      this.forceAsk = true;
    },
    forceClear(password) {
      this.forceBusy = true;
      this.forceError = '';
      this.$inertia.post(route('material-requests.force-clear'), { ids: this.picked, password }, {
        preserveScroll: true,
        onSuccess: () => { this.forceAsk = false; this.picked = []; },
        onError: e => { this.forceError = e.password || e.ids || Object.values(e)[0] || this.$t('That did not work.'); },
        onFinish: () => (this.forceBusy = false),
      });
    },
    pickTile(item) {
      this.form.quick = item.quick ? 1 : null;
      this.form.status = item.status || null;
    },
    statusChip(materialRequest) {
      return (
        {
          Draft: 'lg-chip-slate',
          'Pending Approval': 'lg-chip-amber',
          Approved: 'lg-chip-blue',
          Rejected: 'lg-chip-red',
          'Partially Issued': 'lg-chip-violet',
          Issued: 'lg-chip-cyan',
          Cleared: materialRequest.force_cleared ? 'mr-chip-force' : 'mr-chip-cleared',
          Closed: 'lg-chip-slate',
        }[materialRequest.status] || 'lg-chip-slate'
      );
    },
    trailLabel(status) {
      return (
        {
          'Pending Approval': this.$t('Submitted by'),
          Approved: this.$t('Approved by'),
          Rejected: this.$t('Rejected by'),
          'Partially Issued': this.$t('Partially issued by'),
          Issued: this.$t('Fully issued by'),
          'Delivery Update': this.$t('Delivery update by'),
          'Partially Delivered': this.$t('Partially delivered by'),
          'Fully Delivered': this.$t('Fully delivered by'),
          Cleared: this.$t('Cleared by'),
          Closed: this.$t('Closed by'),
        }[status] || status
      );
    },
    trailTone(status) {
      return (
        {
          'Pending Approval': 'amber',
          Approved: 'blue',
          Rejected: 'red',
          'Partially Issued': 'violet',
          Issued: 'cyan',
          'Delivery Update': 'teal',
          'Partially Delivered': 'amber',
          'Fully Delivered': 'green',
          Cleared: 'green',
          Closed: 'slate',
        }[status] || 'slate'
      );
    },
    // "Approved by Jane · 3 Oct 2026, 14:05" — who put the request into its current status.
    statusVerb(status) {
      return (
        {
          'Pending Approval': this.$t('Submitted by'),
          Approved: this.$t('Approved by'),
          Rejected: this.$t('Rejected by'),
          'Partially Issued': this.$t('Partially issued by'),
          Issued: this.$t('Fully issued by'),
          Cleared: this.$t('Cleared by'),
          Closed: this.$t('Closed by'),
        }[status] || this.$t('By')
      );
    },
    reset() {
      this.form = mapValues(this.form, () => null);
    },
    goto(id) {
      this.$inertia.visit(route('material-requests.show', id));
    },
    statusLabel(materialRequest) {
      return this.$t(materialRequest.status_label || materialRequest.status);
    },
    canEdit(materialRequest) {
      // Backend-computed (MaterialRequestResource::can_edit, via
      // MaterialRequestPolicy::update()) — not re-derived from role here,
      // so it can never drift from what the server actually allows.
      return !!materialRequest.can_edit;
    },
    canDelete(materialRequest) {
      return !!materialRequest.can_delete;
    },
    async deleteRequest(materialRequest) {
      if (!(await this.$confirm(this.$t('Are you sure you want to delete this material request?'), { variant: 'danger' }))) {
        return;
      }

      this.$inertia.delete(route('material-requests.destroy', materialRequest.id));
    },
  },
};
</script>

<style scoped>
.mr-bell { position: relative; }
.mr-bell.has-sent { box-shadow: 0 4px 10px -4px rgba(217, 119, 6, 0.7); }
.mr-bell-chase.has-sent { box-shadow: 0 4px 10px -4px rgba(79, 70, 229, 0.7); }
.mr-bell-chase .mr-bell-n { background: #4f46e5; }
.mr-bell-chase svg { animation: mr-chase 3.2s ease-in-out infinite; transform-origin: 30% 60%; }
@keyframes mr-chase { 0%, 70%, 100% { transform: rotate(0); } 78% { transform: rotate(-14deg); } 86% { transform: rotate(10deg); } 94% { transform: rotate(-4deg); } }
@media (prefers-reduced-motion: reduce) { .mr-bell-chase svg { animation: none; } }
.mr-bell-n { position: absolute; top: -6px; right: -6px; min-width: 16px; height: 16px; padding: 0 4px; border-radius: 999px; background: #d97706; color: #fff; font-size: 0.62rem; line-height: 16px; text-align: center; box-shadow: 0 2px 6px rgba(217, 119, 6, 0.6); }
.mr-status-cell { min-width: 330px; }
/* ---- compact, easy-to-scan rows: tighter padding, names wrap instead of stretching the row, outlined icons, a soft inner shadow instead of hard lines ---- */
.mr-table td { padding-top: 9px; padding-bottom: 9px; border-bottom: 0; box-shadow: inset 0 -8px 8px -9px rgba(15, 37, 64, 0.28); }
.mr-table tbody tr:last-child td { box-shadow: none; }
.mr-table td.lg-sticky-end { box-shadow: inset 0 -8px 8px -9px rgba(15, 37, 64, 0.2), -10px 0 14px -12px rgba(15, 23, 42, 0.25); }
.mr-table tbody tr:hover td { background: color-mix(in srgb, var(--lg-soft) 70%, #fff); }
.mr-table th { padding-top: 10px; padding-bottom: 10px; }
.mr-td-wh { white-space: normal; min-width: 210px; max-width: 300px; }
.mr-td-by { white-space: normal; min-width: 150px; max-width: 200px; }
.mr-id, .mr-w, .mr-u { display: flex; align-items: center; gap: 9px; min-width: 0; }
.mr-ic { --ic: #1c3f66; flex: none; display: inline-grid; place-items: center; width: 28px; height: 28px; border-radius: 9px; color: var(--ic); background: var(--lg-card, #fff); box-shadow: inset 0 0 0 1.5px var(--ic), 0 6px 10px -6px var(--ic); }
.mr-ic.tc-navy { --ic: #1c3f66; } .mr-ic.tc-teal { --ic: #0f766e; } .mr-ic.tc-indigo { --ic: #4338ca; }
.mr-wt { font-weight: 600; color: #0f766e; line-height: 1.3; overflow-wrap: anywhere; }
.mr-ut { font-weight: 600; color: #4338ca; line-height: 1.3; overflow-wrap: anywhere; }
.mr-ref .lg-main, .mr-ref { color: var(--lg-navy, #1c3f66); }
.mr-date { display: inline-flex; align-items: center; gap: 4px; margin-top: 1px; }
html.dark .mr-wt { color: #5eead4; } html.dark .mr-ut { color: #a5b4fc; }
/* status: the chips on one line, then the approval trail as a vertical timeline, one step per line (the same reading as Super Adjustments) */
.mr-table .mr-status-cell { min-width: 400px; }
.mr-table .mr-chip { min-width: 7.6rem; padding-top: 2px; padding-bottom: 2px; }
.mr-table .mr-trail { display: grid; grid-template-columns: 1fr; margin: 5px 0 0; gap: 0; }
.mr-table .mr-trail li { position: relative; display: grid; grid-template-columns: 14px minmax(0, 1fr); gap: 0 9px; align-items: start; padding-bottom: 3px; font-size: 0.74rem; line-height: 1.25; }
.mr-table .mr-trail li:last-child { padding-bottom: 0; }
.mr-table .mr-trail li:not(:last-child)::after { content: ''; position: absolute; left: 6px; top: 14px; bottom: -3px; width: 2px; border-radius: 2px; background: linear-gradient(180deg, var(--lg-line, #cbd5e1), transparent); }
.mr-table .mr-trail-dot { width: 12px; height: 12px; margin-top: 1px; box-shadow: 0 0 0 3px color-mix(in srgb, var(--lg-card, #fff) 100%, transparent), inset 0 0 0 2px #fff; }
.mr-table .mr-trail-text { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0 8px; }
.mr-table .mr-tl-cap { font-size: 0.6rem; font-weight: 800; letter-spacing: 0.09em; text-transform: uppercase; color: var(--lg-mute); white-space: nowrap; }
.mr-table .mr-tl-who { font-weight: 700; color: var(--lg-ink); min-width: 0; }
.mr-table .mr-trail-text em { display: inline; font-size: 0.68rem; color: var(--lg-faint); }
.mr-table .mr-trail-amber .mr-tl-cap { color: #b45309; } .mr-table .mr-trail-blue .mr-tl-cap { color: #1d4ed8; } .mr-table .mr-trail-red .mr-tl-cap { color: #b91c1c; } .mr-table .mr-trail-violet .mr-tl-cap { color: #6d28d9; }
.mr-table .mr-trail-cyan .mr-tl-cap { color: #0e7490; } .mr-table .mr-trail-teal .mr-tl-cap { color: #0f766e; } .mr-table .mr-trail-green .mr-tl-cap { color: #047857; }
@media (max-width: 767.98px) {
  .mr-table .mr-status-cell { min-width: 0; }
  .mr-table .mr-trail { margin-top: 10px; }
  .mr-td-wh, .mr-td-by { max-width: none; min-width: 0; }
  .mr-table.lg-responsive tbody tr:not(.lg-empty-row) td::before { flex: 0 1 38% !important; min-width: 0; overflow-wrap: anywhere !important; word-break: break-word; white-space: normal !important; }
  .mr-id, .mr-w, .mr-u { justify-content: flex-end; text-align: right; flex: 1 1 0; min-width: 0; }
  .mr-td-wh > .mr-w, .mr-td-by > .mr-u { margin-left: auto; }
  .mr-table td { box-shadow: none; }
}

.mr-chips { display: flex; flex-wrap: wrap; gap: 6px; }
/* status and delivery chips are the same size; Cleared is the deeper green, Fully Delivered the lighter one */
.mr-chip { min-width: 8.6rem; justify-content: center; gap: 4px; }
.mr-chip-cleared { color: #064e3b; background: rgba(5, 150, 105, 0.26); }
/* Cleared by Admin Force Clearance: a cleared request in every way, shown in orange */
.mr-chip-force { color: #9a3412; background: rgba(249, 115, 22, 0.22); box-shadow: inset 0 0 0 1px rgba(234, 88, 12, 0.45); cursor: help; }
html.dark .mr-chip-force { color: #fdba74; }
.mr-pick { display: inline-flex; align-items: center; justify-content: center; padding: 4px; border-radius: 9px; background: #fff; cursor: pointer; box-shadow: 0 6px 12px -5px rgba(194, 65, 12, 0.55), 0 1px 3px rgba(15, 23, 42, 0.18), 0 0 0 1px rgba(234, 88, 12, 0.28); transition: box-shadow 0.15s ease, transform 0.15s ease; } .mr-pick:hover { transform: translateY(-1px); box-shadow: 0 9px 16px -6px rgba(194, 65, 12, 0.65), 0 1px 3px rgba(15, 23, 42, 0.2), 0 0 0 1px rgba(234, 88, 12, 0.45); } .mr-pick input { width: 18px; height: 18px; accent-color: #c2410c; cursor: pointer; }
html.dark .mr-pick { background: #1e293b; }
.mr-th-pick, .mr-td-pick { width: 44px; } .mr-th-no, .mr-td-no { width: 56px; font-variant-numeric: tabular-nums; color: var(--lg-mute); font-weight: 700; }
tr.is-picked td { background: rgba(251, 146, 60, 0.1); }
.mr-bulk { position: sticky; top: 0.5rem; z-index: 30; display: flex; flex-wrap: wrap; align-items: center; gap: 10px 14px; margin: 0 0 1rem; padding: 0.7rem 0.9rem; border-radius: 18px; background: linear-gradient(135deg, rgba(254, 215, 170, 0.92), rgba(255, 237, 213, 0.95)); border: 1px solid rgba(234, 88, 12, 0.35); box-shadow: 0 16px 28px -16px rgba(194, 65, 12, 0.55); backdrop-filter: blur(10px); }
.mr-bulk-ico { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 13px; color: #fff; background: linear-gradient(145deg, #fb923c, #c2410c); }
.mr-bulk-text { flex: 1 1 240px; min-width: 0; color: #7c2d12; font-size: 0.92rem; } .mr-bulk-text small { display: block; font-size: 0.74rem; opacity: 0.85; }
.bulk-btn { border: 0; color: #fff; }
.bulk-btn-all { background: linear-gradient(145deg, #60a5fa, #1d4ed8); box-shadow: 0 10px 18px -8px rgba(29, 78, 216, 0.85), 0 2px 4px rgba(15, 23, 42, 0.15); }
.bulk-btn-none { background: linear-gradient(145deg, #fb7185, #be123c); box-shadow: 0 10px 18px -8px rgba(190, 18, 60, 0.8), 0 2px 4px rgba(15, 23, 42, 0.15); }
.bulk-btn:hover { transform: translateY(-1px); }
.mr-bulk-go { color: #fff; background: linear-gradient(145deg, #fb923c, #c2410c); box-shadow: 0 10px 18px -10px rgba(194, 65, 12, 0.9); }
.mr-bulk-enter-active, .mr-bulk-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; } .mr-bulk-enter-from, .mr-bulk-leave-to { opacity: 0; transform: translateY(-8px); }
html.dark .mr-bulk { background: linear-gradient(135deg, rgba(124, 45, 18, 0.7), rgba(67, 20, 7, 0.75)); } html.dark .mr-bulk-text { color: #fed7aa; }
.mr-chip-fdel { color: #15803d; background: rgba(34, 197, 94, 0.16); }
html.dark .mr-chip-cleared { color: #6ee7b7; }
html.dark .mr-chip-fdel { color: #86efac; }
.mr-tick2 { margin-left: -7px; }
.mr-ref { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
@media (min-width: 768px) { .mr-ref { justify-content: flex-start; } }
.mr-lock { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 9999px; color: #064e3b; background: rgba(5, 150, 105, 0.2); cursor: help; }
html.dark .mr-lock { color: #6ee7b7; }
/* two statuses per line, so a request does not take a tall row */
.mr-trail { list-style: none; margin: 8px 0 0; padding: 0; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px 16px; }
.mr-trail li { text-align: left; display: flex; align-items: flex-start; gap: 7px; font-size: 0.74rem; line-height: 1.3; color: var(--lg-mute); }
.mr-trail-dot { flex: none; width: 8px; height: 8px; margin-top: 4px; border-radius: 9999px; background: var(--lg-faint); }
.mr-trail-text { min-width: 0; overflow-wrap: anywhere; }
.mr-trail-text b { font-weight: 800; color: var(--lg-ink); }
.mr-trail-text em { display: block; font-style: normal; font-size: 0.7rem; color: var(--lg-faint); font-variant-numeric: tabular-nums; }
.mr-trail-amber .mr-trail-dot { background: #f59e0b; }
.mr-trail-blue .mr-trail-dot { background: #3b82f6; }
.mr-trail-red .mr-trail-dot { background: #ef4444; }
.mr-trail-violet .mr-trail-dot { background: #8b5cf6; }
.mr-trail-cyan .mr-trail-dot { background: #06b6d4; }
.mr-trail-teal .mr-trail-dot { background: #14b8a6; }
.mr-trail-green .mr-trail-dot { background: #10b981; }
.mr-card {
  padding: 0.9rem;
}
.mr-status-by {
  margin-top: 4px;
}
.lg-icon-btn.is-amber {
  color: #b45309;
  background: rgba(245, 158, 11, 0.16);
}
html.dark .lg-icon-btn.is-amber {
  color: #fcd34d;
}
@media (max-width: 767.98px) {
  .mr-status-cell { min-width: 0; flex-wrap: wrap; }
  .mr-trail { flex: 1 1 100%; margin-top: 8px; gap: 8px 10px; }
  .mr-card {
    padding: 0;
    background: transparent;
    border: 0;
    box-shadow: none;
  }
}
</style>

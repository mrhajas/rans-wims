<template>
  <admin-layout :title="$t('RANS Staffs')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="users" :title="$t('RANS Staffs')" :subtitle="$t('Staff records: personal, contact, bank, and salary details, printable in the RANS format.')">
        <Link v-if="canContracts" :href="route('staffs.contracts.calendar')" class="lg-btn lg-btn-soft"><icons name="calendar" class="w-4 h-4" />{{ $t('Contracts calendar') }}</Link>
        <Link v-if="$can('create-staffs')" :href="route('staffs.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" />{{ $t('Add Staff') }}</Link>
      </page-header>

      <!-- two lines: the people (by status), then their contracts -->
      <stat-tiles class="stf-row" :style="{ '--cols': peopleTiles.length }" :items="peopleTiles" @select="pickTile" />
      <stat-tiles v-if="contractTiles.length" class="stf-row stf-row--contracts" :style="{ '--cols': contractTiles.length }" :items="contractTiles" @select="pickTile" />

      <div class="stf-panel lg-card relative z-20 grid grid-cols-2 sm:grid-cols-5 gap-4 items-end">
        <div><text-input class="w-full" :label="$t('Search')" v-model="form.search" :placeholder="$t('Name, Staff No., or Mobile…')" /></div>
        <div><auto-complete id="employment_type" :label="$t('Employment')" v-model="form.employment_type" class="w-full" :suggestions="employmentOptions" clearable /></div>
        <div><auto-complete id="status" :label="$t('Status')" v-model="form.status" class="w-full" :suggestions="statusOptions" clearable /></div>
        <div v-if="canContracts"><auto-complete id="contract_filter" :label="$t('Contract')" v-model="form.contract" class="w-full" :suggestions="contractOptions" clearable /></div>
        <div class="mb-2">
          <button type="button" class="stf-reset-btn w-full justify-center" @click="reset">
            <icons name="refresh" class="w-4 h-4" /> {{ $t('Reset') }}
          </button>
        </div>
      </div>

      <div class="flex items-center justify-between gap-4 mb-3 flex-wrap">
        <span class="text-xs text-gray-400">{{ staffs.meta ? staffs.meta.total : 0 }} {{ $t('result(s)') }}</span>
        <div class="flex items-center gap-2 shrink-0">
          <a :href="route('staffs.print-list') + queryString" target="_blank" class="stf-action-btn stf-action-btn-cyan">
            <icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}
          </a>
          <a :href="route('staffs.pdf-list') + queryString" class="stf-action-btn stf-action-btn-dark">
            <icons name="download" class="w-4 h-4" /> {{ $t('Download') }}
          </a>
        </div>
      </div>

      <div class="lg-card" style="padding: 1rem">
        <div class="lg-table-wrap">
          <table v-labelize class="lg-table lg-responsive">
            <thead>
              <tr>
                <th class="stf-no">{{ $t('No') }}</th>
                <th>{{ $t('Staff') }}</th>
                <th>{{ $t('Staff No.') }}</th>
                <th>{{ $t('Employment') }}</th>
                <th class="stf-col-mobile">{{ $t('Mobile No.') }}</th>
                <th class="num">{{ $t('Net Salary') }}</th>
                <th v-if="canContracts">{{ $t('Contract') }}</th>
                <th>{{ $t('Status') }}</th>
                <th class="stf-actions-h">{{ $t('Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(s, i) in staffs.data" :key="s.id" class="is-click" @click="goto(s.id)">
                <td class="stf-no">{{ rowNo(i) }}</td>
                <td>
                  <div class="lg-person">
                    <img
                      v-if="s.photo_url && !s.photo_failed"
                      :src="s.photo_url"
                      class="stf-photo-thumb"
                      style="width: 38px; height: 38px; border-radius: 9999px; object-fit: cover; flex: none"
                      alt=""
                      @error="s.photo_failed = true"
                      @mouseenter="showPhotoZoom($event, s)"
                      @mouseleave="hidePhotoZoom"
                    />
                    <span v-else class="lg-avatar" style="width: 38px; height: 38px">{{ initials(s.name) }}</span>
                    <div class="min-w-0">
                      <div class="lg-main">{{ s.name }}</div>
                      <div class="lg-subtext">{{ s.position || '—' }}</div>
                    </div>
                  </div>
                </td>
                <td><span class="lg-chip lg-chip-blue" style="text-transform: none">{{ s.staff_number }}</span></td>
                <td>{{ s.employment_type || '—' }}</td>
                <td class="stf-col-mobile">{{ s.mobile_no || '—' }}</td>
                <td class="num lg-main">{{ $money(s.net_salary) }}</td>
                <td v-if="canContracts">
                  <span v-if="s.contract" class="lg-chip stf-contract lg-tip" :class="contractChip(s.contract.state)" style="text-transform: none" :data-tip="s.contract.number + ' · ' + s.contract.type + (s.contract.start_date ? ' · ' + $date(s.contract.start_date) : '')">
                    <template v-if="s.contract.end_date">{{ $date(s.contract.end_date) }} · {{ daysText(s.contract.days_left) }}</template>
                    <template v-else-if="s.contract.type === 'Permanent'">{{ $t('Permanent') }}</template>
                    <template v-else>{{ $t('Open ended') }}</template>
                  </span>
                  <span v-else-if="inScope(s)" class="lg-chip lg-chip-slate" style="text-transform: none">{{ $t('No contract') }}</span>
                  <span v-else class="lg-subtext">—</span>
                  <span v-if="fileCount(s)" class="stf-clip lg-tip" tabindex="0" role="img" :data-tip="fileTip(s)" :aria-label="fileTip(s)" @click.stop>
                    <icons name="clip" class="w-4 h-4" /><b>{{ fileCount(s) }}</b>
                  </span>
                </td>
                <td><span class="lg-chip" :class="statusChip(s.status)">{{ s.status }}</span></td>
                <td class="stf-actions" @click.stop>
                  <Link :href="route('staffs.show', s.id)" class="lg-icon-btn lg-tip" :data-tip="$t('View staff')" :aria-label="$t('View staff')"><icons name="eye" class="w-4 h-4" /></Link>
                  <Link v-if="canContracts" :href="route('staffs.show', s.id) + '?tab=contracts'" class="lg-icon-btn is-green lg-tip" :data-tip="$t('View contracts')" :aria-label="$t('View contracts')"><icons name="doc-text" class="w-4 h-4" /></Link>
                  <Link v-if="canUpdate" :href="route('staffs.edit', s.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Edit')" :aria-label="$t('Edit')"><icons name="edit" class="w-4 h-4" /></Link>
                  <Link v-if="canRenew && s.contract" :href="route('staffs.show', s.id) + '?tab=contracts'" class="lg-icon-btn is-amber lg-tip" :data-tip="$t('Renew contract')" :aria-label="$t('Renew contract')"><icons name="refresh" class="w-4 h-4" /></Link>
                  <a v-if="canPrint" :href="route('staffs.print', s.id)" target="_blank" class="lg-icon-btn lg-tip" :data-tip="$t('Print')" :aria-label="$t('Print')"><icons name="printer" class="w-4 h-4" /></a>
                  <retire-staff v-if="canRetire" :staff="s" compact />
                  <delete-staff v-if="canDelete" :staff="s" compact />
                </td>
              </tr>
              <tr v-if="staffs.data.length === 0">
                <td class="lg-empty" :colspan="canContracts ? 9 : 8">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <pagination v-if="staffs.data.length" class="mt-4" :meta="staffs.meta" :links="staffs.links" />

      <teleport to="body">
        <div ref="zoomBox" class="stf-photo-zoom" :class="{ 'stf-photo-zoom-show': photoZoom.show }" :style="{ left: photoZoom.x + 'px', top: photoZoom.y + 'px', width: photoZoom.w + 'px' }">
          <img v-if="photoZoom.src" :src="photoZoom.src" alt="" />
          <div class="stf-photo-zoom-name">{{ photoZoom.name }}</div>
        </div>
      </teleport>
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import { placeZoom } from '@/Core/zoomPosition';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import TextInput from '@/Shared/TextInput.vue';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import RetireStaff from '@/Shared/Staff/RetireStaff.vue';
import DeleteStaff from '@/Shared/Staff/DeleteStaff.vue';
import { Link } from '@inertiajs/vue3';

export default {
  components: { AdminLayout, AutoComplete, TextInput, Pagination, PageHeader, StatTiles, RetireStaff, DeleteStaff, Link },

  props: {
    filters: Object,
    statuses: Array,
    employmentTypes: Array,
    staffs: Object,
    cards: Object,
    contractFilters: { type: Array, default: () => [] },
    canUpdate: Boolean,
    canDelete: Boolean,
    canPrint: Boolean,
    canRetire: Boolean,
    canContracts: Boolean,
    canRenew: Boolean,
  },

  mounted() {
    window.addEventListener('scroll', this.hideZoomOnMove, true);
    window.addEventListener('resize', this.hideZoomOnMove);
  },

  beforeUnmount() {
    window.removeEventListener('scroll', this.hideZoomOnMove, true);
    window.removeEventListener('resize', this.hideZoomOnMove);
  },

  data() {
    return {
      form: {
        search: this.filters.search || '',
        employment_type: this.filters.employment_type || null,
        status: this.filters.status || null,
        contract: this.filters.contract || null,
      },
      // Teleported to <body> and positioned via getBoundingClientRect() on
      // hover, so the enlarged preview is never clipped by the table's own
      // scroll/overflow container.
      photoZoom: { show: false, src: null, name: '', x: 0, y: 0, w: 224 },
    };
  },

  computed: {
    statusOptions() {
      return this.statuses.map(s => ({ label: this.$t(s), value: s }));
    },
    contractOptions() {
      const label = { expiring: this.$t('Ending in 60 days'), expired: this.$t('Expired'), none: this.$t('No contract'), permanent: this.$t('Permanent'), open: this.$t('Open ended') };
      return this.contractFilters.map(c => ({ label: label[c] || c, value: c }));
    },
    employmentOptions() {
      return this.employmentTypes.map(e => ({ label: this.$t(e), value: e }));
    },
    statTiles() {
      const c = this.cards || {};
      const withContracts = this.canContracts;

      const f = this.form;
      const st = (icon, tone, label, value, status) => ({ icon, tone, label, value: value || 0, status, active: f.status === status && !f.contract });
      const ct = (icon, tone, label, value, contract) => ({ icon, tone, label, value: value || 0, contract, active: f.contract === contract });

      return [
        { icon: 'users', tone: 'navy', label: this.$t('Total'), value: c.total || 0, status: null, active: f.status === null && f.contract === null },
        st('tick', 'emerald', this.$t('Active'), c.active, 'Active'),
        ...(withContracts ? [
          ct('calendar', 'amber', this.$t('Contracts ending (60 days)'), c.expiring, 'expiring'),
          ct('exclamation', 'rose', this.$t('Contracts expired'), c.expired, 'expired'),
          ct('doc-text', 'slate', this.$t('No contract'), c.no_contract, 'none'),
          ct('tick', 'sky', this.$t('Permanent'), c.permanent, 'permanent'),
        ] : []),
        st('user', 'amber', this.$t('Retired Active'), c.retired_active, 'Retired Active'),
        st('user', 'indigo', this.$t('Retired'), c.retired, 'Retired'),
        st('doc-text', 'slate', this.$t('Inactive'), c.inactive, 'Inactive'),
        st('cross', 'rose', this.$t('Terminated'), c.terminated, 'Terminated'),
      ];
    },
    // line 1: people by status; line 2: contracts (only for people who may see contracts)
    peopleTiles() { return this.statTiles.filter(t => !('contract' in t)); },
    contractTiles() { return this.statTiles.filter(t => 'contract' in t); },
    queryString() {
      const query = pickBy(this.form);
      const qs = new URLSearchParams(query).toString();
      return qs ? '?' + qs : '';
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('staffs.index', Object.keys(query).length ? query : { remember: 'forget' }), {
          preserveState: true,
          preserveScroll: true,
          replace: true,
        });
      }, 200),
      deep: true,
    },
  },

  methods: {
    // the paper-clip next to the contract: how many documents there are, and a tooltip naming them (details attachments, then each contract's)
    fileCount(s) {
      const f = s.files || {};
      return (f.details || []).length + (f.contracts || []).reduce((n, c) => n + c.files.length, 0);
    },
    fileTip(s) {
      const f = s.files || {};
      const list = names => (names.length > 5 ? names.slice(0, 5).join(', ') + ' +' + (names.length - 5) : names.join(', '));
      const lines = [];
      if ((f.details || []).length) lines.push(this.$t('Details') + ': ' + list(f.details));
      (f.contracts || []).forEach(c => lines.push(this.$t('Contract') + ' ' + c.number + ': ' + list(c.files)));
      return lines.join('\n');
    },    reset() {
      this.form = mapValues(this.form, () => null);
      this.form.search = '';
    },
    pickTile(item) {
      if (item.contract !== undefined) {
        this.form.contract = this.form.contract === item.contract ? null : item.contract;
        this.form.status = null;
      } else {
        this.form.status = item.status && this.form.status === item.status ? null : item.status;
        this.form.contract = null;
      }
    },
    // the number continues across pages (page 2 starts after the last row of page 1)
    rowNo(i) {
      return ((this.staffs.meta && this.staffs.meta.from) || 1) + i;
    },
    inScope(s) {
      return ['Active', 'Retired Active'].includes(s.status);
    },
    daysText(d) {
      if (d === null || d === undefined) return '';
      return d < 0 ? Math.abs(d) + ' ' + this.$t('days ago') : d === 0 ? this.$t('today') : d + ' ' + this.$t('days');
    },
    contractChip(state) {
      return { open: 'lg-chip-blue', ok: 'lg-chip-green', soon: 'lg-chip-amber', urgent: 'lg-chip-red', expired: 'lg-chip-red' }[state] || 'lg-chip-slate';
    },
    statusChip(status) {
      return { Active: 'lg-chip-green', 'Retired Active': 'lg-chip-amber', Retired: 'lg-chip-violet', Inactive: 'lg-chip-slate', Terminated: 'lg-chip-red' }[status] || 'lg-chip-slate';
    },
    selectStatus(status) {
      this.form.status = this.form.status === status ? null : status;
    },
    goto(id) {
      this.$inertia.visit(route('staffs.show', id));
    },
    showPhotoZoom(event, staff) {
      const rect = event.currentTarget.getBoundingClientRect();
      // Render invisibly first so the popup's real size is known, then place it inside the viewport.
      this.photoZoom = { show: false, src: staff.photo_url, name: staff.name, x: -9999, y: -9999, w: 224 };
      this.$nextTick(() => {
        const box = this.$refs.zoomBox;
        if (!box || !this.photoZoom.src) return;
        const pos = placeZoom(rect, 224, box.offsetHeight);
        this.photoZoom = { show: true, src: staff.photo_url, name: staff.name, x: pos.x, y: pos.y, w: pos.w };
      });
    },
    hidePhotoZoom() {
      this.photoZoom.show = false;
    },
    hideZoomOnMove() {
      if (this.photoZoom.show) this.photoZoom.show = false;
    },
    initials(name) {
      return (name || '')
        .split(' ')
        .map(p => p[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
    },
    statusBadgeClass(status) {
      const map = { Active: 'stf-badge-emerald', Inactive: 'stf-badge-slate', Terminated: 'stf-badge-rose' };
      return map[status] || 'stf-badge-slate';
    },
  },
};
</script>

<style scoped>
.stf-row { margin-bottom: 0.7rem; }
@media (min-width: 1024px) { .stf-row { grid-template-columns: repeat(var(--cols, 5), minmax(0, 1fr)); } }
@media (min-width: 640px) and (max-width: 1023px) { .stf-row { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
.stf-row--contracts { margin-bottom: 1rem; }
.stf-actions { white-space: nowrap; text-align: right; padding-left: 4px !important; padding-right: 8px !important; } .stf-actions > * { display: inline-flex; vertical-align: middle; margin-left: 3px; } .stf-actions :deep(.lg-icon-btn) { width: 29px; height: 29px; border-radius: 9px; }
@media (max-width: 1699px) { .stf-col-mobile { display: none; } } .stf-actions-h { text-align: right; }
.stf-contract { white-space: nowrap; } .stf-no { width: 3.2rem; text-align: center; font-variant-numeric: tabular-nums; font-weight: 700; color: #64748b; }
.stf-hero {
  background: linear-gradient(135deg, rgba(238, 242, 255, 0.75), rgba(240, 253, 250, 0.6));
  border: 1px solid rgba(255, 255, 255, 0.7);
  backdrop-filter: blur(20px) saturate(160%);
}
.stf-hero-icon { background: linear-gradient(145deg, #818cf8, #4338ca); box-shadow: 0 8px 20px -6px rgba(67, 56, 202, 0.5); }
.stf-action-btn { display: inline-flex; align-items: center; gap: 7px; padding: 0.5rem 0.9rem; border-radius: 9999px; font-size: 0.78rem; font-weight: 700; color: #fff; transition: transform 0.15s ease; }
.stf-action-btn:hover { transform: translateY(-1px); }
.stf-action-btn-indigo { background: linear-gradient(145deg, #818cf8, #4338ca); box-shadow: 0 6px 16px -6px rgba(67, 56, 202, 0.5); }
.stf-action-btn-cyan { background: linear-gradient(145deg, #22d3ee, #0e7490); box-shadow: 0 6px 16px -6px rgba(14, 116, 144, 0.5); }
.stf-action-btn-dark { background: linear-gradient(145deg, #1e293b, #0f172a); box-shadow: 0 6px 16px -6px rgba(15, 23, 42, 0.5); }

.stf-card {
  background: rgba(255, 255, 255, 0.6); border: 1px solid rgba(255, 255, 255, 0.85); border-radius: 1rem; padding: 0.9rem;
  text-align: left; backdrop-filter: blur(18px) saturate(160%); transition: border-color 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease;
}
.stf-card:hover { transform: translateY(-2px); box-shadow: 0 10px 20px -8px rgba(15, 23, 42, 0.15); }
.stf-card-active { border-color: #818cf8; box-shadow: 0 0 0 2px rgba(129, 140, 248, 0.25); }
.stf-card-icon { display: inline-flex; align-items: center; justify-content: center; width: 1.75rem; height: 1.75rem; border-radius: 0.6rem; margin-bottom: 0.5rem; }
.stf-card-icon-indigo { background: #e0e7ff; color: #4338ca; }
.stf-card-icon-emerald { background: #d1fae5; color: #047857; }
.stf-card-icon-slate { background: #f1f5f9; color: #475569; }
.stf-card-icon-rose { background: #ffe4e6; color: #be123c; }
.stf-card-value { font-size: 1.35rem; font-weight: 800; color: #1f2937; }
.stf-card-label { font-size: 0.72rem; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.02em; margin-top: 2px; }

.stf-panel {
  background: rgba(255, 255, 255, 0.6); border: 1px solid rgba(255, 255, 255, 0.85); border-radius: 1.1rem; padding: 1.1rem; position: relative; z-index: 20;
  backdrop-filter: blur(18px) saturate(160%);
}
.stf-reset-btn {
  display: inline-flex; align-items: center; gap: 6px; padding: 0.55rem 0.9rem; border-radius: 9999px; font-size: 0.8rem; font-weight: 700;
  color: #64748b; background: #f1f5f9; border: 1px solid #e2e8f0; transition: background 0.15s ease, color 0.15s ease, transform 0.15s ease;
  min-height: 42px;
}
.stf-reset-btn:hover { background: #e2e8f0; color: #334155; transform: translateY(-1px); }

.stf-table-shell { border: 1px solid #eef1f6; background: #ffffff; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 10px 24px rgba(15, 23, 42, 0.05); }
.stf-row { transition: background 0.15s ease; }
.stf-status-badge { display: inline-flex; align-items: center; padding: 2px 9px; border-radius: 9999px; font-size: 0.72rem; font-weight: 700; }
.stf-badge-emerald { background: #d1fae5; color: #047857; }
.stf-badge-slate { background: #f1f5f9; color: #475569; }
.stf-badge-rose { background: #ffe4e6; color: #be123c; }

.stf-photo-thumb { cursor: zoom-in; transition: transform 0.15s ease, box-shadow 0.15s ease; }
.stf-photo-thumb:hover { transform: scale(1.08); box-shadow: 0 4px 10px -2px rgba(15, 23, 42, 0.25); }

.stf-photo-zoom {
  position: fixed; transform: scale(0.85); transform-origin: center; z-index: 9999; box-sizing: border-box; max-width: calc(100vw - 16px);
  background: #fff; border-radius: 1rem; padding: 0.6rem; box-shadow: 0 20px 40px -10px rgba(15, 23, 42, 0.35), 0 0 0 1px rgba(15, 23, 42, 0.05);
  opacity: 0; pointer-events: none; transition: opacity 0.16s ease, transform 0.16s ease;
}
.stf-photo-zoom-show { opacity: 1; transform: scale(1); }
.stf-photo-zoom img { display: block; width: 100%; aspect-ratio: 1 / 1; height: auto; object-fit: cover; border-radius: 0.7rem; }
.stf-photo-zoom-name { text-align: center; font-size: 0.78rem; font-weight: 700; color: #374151; margin-top: 0.5rem; }

html.dark .stf-hero { background: linear-gradient(150deg, rgba(30, 41, 59, 0.75), rgba(15, 23, 42, 0.6)); border-color: rgba(255, 255, 255, 0.08); }
html.dark .stf-card,
html.dark .stf-panel,
html.dark .stf-table-shell { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.1); }
html.dark .stf-reset-btn { background: rgba(255, 255, 255, 0.08); border-color: rgba(255, 255, 255, 0.15); color: #cbd5e1; }
html.dark .stf-photo-zoom { background: #1e293b; box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.08); }
html.dark .stf-photo-zoom-name { color: #e2e8f0; }
.stf-clip { display: inline-flex; align-items: center; gap: 3px; margin-left: 8px; vertical-align: middle; color: var(--lg-mute); cursor: default; outline: none; } .stf-clip b { font-size: 0.7rem; font-weight: 800; font-variant-numeric: tabular-nums; } .stf-clip:hover, .stf-clip:focus-visible { color: #2563eb; }
</style>

<template>
  <admin-layout :title="$t('WIMS Connect / Bulk Connect History')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="list" :title="$t('Bulk Connect History')" :subtitle="$t('Every recipient of every Bulk Connect message ever sent or scheduled.')">
        <Link :href="route('wims-connect.bulk-connect.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-3.5 h-3.5 transform rotate-90" />
          {{ $t('Bulk Connect') }}
        </Link>
        <a :href="printUrl" target="_blank" class="lg-btn lg-btn-dark">
          <icons name="printer" class="w-4 h-4" />
          <span class="hidden sm:inline">{{ $t('Print') }}</span>
        </a>
        <a :href="downloadUrl" target="_blank" class="lg-btn lg-btn-primary">
          <icons name="download" class="w-4 h-4" />
          <span class="hidden sm:inline">{{ $t('Download') }}</span>
        </a>
      </page-header>

      <section class="lg-card lg-filters relative z-20 print:hidden">
        <div class="bsm-filter-grid">
          <div class="bsm-search-wrap">
            <icons name="search" class="bsm-search-icon" />
            <input v-model="form.search" type="text" class="bsm-search-input" :placeholder="$t('Search name, phone, or message…')" />
          </div>
          <dropdown-filter v-model="form.channel" :options="channelOptions" :placeholder="$t('All Channels')" />
          <dropdown-filter v-model="form.status" :options="statusOptions" :placeholder="$t('All Statuses')" />
          <div class="grid grid-cols-2 gap-2">
            <div class="bsm-date-wrap">
              <icons name="calendar" class="bsm-date-icon" />
              <div class="min-w-0">
                <span class="bsm-date-caption">{{ $t('From') }}</span>
                <date-picker v-model="form.date_from" flush />
              </div>
            </div>
            <div class="bsm-date-wrap">
              <icons name="calendar" class="bsm-date-icon" />
              <div class="min-w-0">
                <span class="bsm-date-caption">{{ $t('To') }}</span>
                <date-picker v-model="form.date_to" flush />
              </div>
            </div>
          </div>
        </div>
        <div class="flex items-center gap-2 mt-3">
          <button type="button" class="lg-btn lg-btn-primary" @click="applyFilters">
            <icons name="search" class="w-3.5 h-3.5" />
            {{ $t('Apply Filters') }}
          </button>
          <button type="button" class="lg-btn lg-btn-soft" @click="clearFilters">{{ $t('Clear') }}</button>
        </div>
      </section>

      <section class="lg-card" style="padding: 1rem">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Recipient') }}</th>
                <th>{{ $t('Channel') }}</th>
                <th>{{ $t('Message') }}</th>
                <th>{{ $t('Status') }}</th>
                <th>{{ $t('Error') }}</th>
                <th>{{ $t('Sent') }}</th>
                <th>{{ $t('Retry') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in recipients.data" :key="r.id">
                <td :data-label="$t('Recipient')">
                  <div class="lg-main">{{ r.name || '—' }}</div>
                  <div class="lg-subtext lg-mono">{{ r.phone }}</div>
                </td>
                <td :data-label="$t('Channel')">
                  <span v-if="r.message?.channel" class="lg-chip lg-chip-cyan">
                    <icons :name="r.message.channel" class="w-3.5 h-3.5" />
                    {{ r.message.channel }}
                  </span>
                  <span v-else class="lg-subtext">—</span>
                </td>
                <td :data-label="$t('Message')">
                  <div class="lg-tip tip-left bsm-msg-tip" :data-tip="r.message?.body || $t('(no message content)')">
                    <div class="lg-clip truncate">{{ r.message?.body || '—' }}</div>
                  </div>
                </td>
                <td :data-label="$t('Status')">
                  <span class="lg-chip" :class="statusClass(r.status)">{{ r.status }}</span>
                </td>
                <td :data-label="$t('Error')">
                  <div v-if="r.error_message" class="lg-tip tip-left bsm-msg-tip" :data-tip="r.error_message">
                    <div class="lg-clip truncate text-rose-600">{{ r.error_message }}</div>
                  </div>
                  <span v-else class="lg-subtext">—</span>
                </td>
                <td :data-label="$t('Sent')"><span class="lg-subtext">{{ r.sent_at ? $datetime(r.sent_at) : '—' }}</span></td>
                <td :data-label="$t('Retry')">
                  <button v-if="r.status === 'Failed'" type="button" class="lg-btn lg-btn-soft" @click="retry(r)">
                    <icons name="refresh" class="w-3.5 h-3.5" />
                    {{ $t('Retry') }}
                  </button>
                  <span v-else class="lg-subtext">—</span>
                </td>
              </tr>
              <tr v-if="!recipients.data.length" class="lg-empty-row">
                <td class="lg-empty" colspan="7">{{ $t('No messages match these filters.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <pagination class="mt-2 print:hidden" :meta="recipients.meta" :links="recipients.links" />
    </div>
  </admin-layout>
</template>

<script>
import debounce from 'lodash/debounce';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Shared/Pagination.vue';
import DropdownFilter from '@/Shared/WimsConnect/DropdownFilter.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

const STATUS_COLORS = { Sent: '#15803d', Sending: '#4338ca', Pending: '#4338ca', Failed: '#b91c1c' };

export default {
  components: { AdminLayout, Link, Pagination, DropdownFilter, PageHeader },

  props: {
    recipients: Object,
    filters: Object,
    statuses: { type: Array, default: () => [] },
    channels: { type: Array, default: () => [] },
    channelLive: { type: Object, default: () => ({}) },
  },

  data() {
    return {
      form: {
        channel: this.filters?.channel || '',
        status: this.filters?.status || '',
        search: this.filters?.search || '',
        date_from: this.filters?.date_from || '',
        date_to: this.filters?.date_to || '',
      },
    };
  },

  mounted() {
    this.scrollSidebarToBulkConnect();
  },

  computed: {
    activeFilters() {
      const out = {};
      Object.entries(this.form).forEach(([k, v]) => { if (v) out[k] = v; });
      return out;
    },
    printUrl() {
      return this.route('wims-connect.bulk-connect.history.print', this.activeFilters);
    },
    downloadUrl() {
      return this.route('wims-connect.bulk-connect.history.download', this.activeFilters);
    },
    channelOptions() {
      return this.channels.map(c => ({
        value: c,
        label: c.charAt(0).toUpperCase() + c.slice(1),
        icon: c,
        live: !!this.channelLive[c],
      }));
    },
    statusOptions() {
      return this.statuses.map(s => ({ value: s, label: s, dotColor: STATUS_COLORS[s] || '#94a3b8' }));
    },
  },

  watch: {
    // Every filter auto-applies — free text is debounced (as you type),
    // dropdowns and dates apply the instant they change (a discrete choice
    // needs no debounce).
    'form.search': debounce(function () {
      this.applyFilters();
    }, 400),
    'form.channel'() { this.applyFilters(); },
    'form.status'() { this.applyFilters(); },
    'form.date_from'() { this.applyFilters(); },
    'form.date_to'() { this.applyFilters(); },
  },

  methods: {
    // The sidebar's own scrollToActiveMenuItem() (FullMainMenu.vue) already
    // scrolls to whichever menu item is active on mount — but it fires the
    // instant that item exists in the DOM, which can be mid-way through its
    // parent dropdown's own expand animation. On a page nested several
    // groups deep that can leave the scroll targeting a position that shifts
    // again as sibling groups finish expanding after it already fired. This
    // waits for the active item's position to hold steady for a few frames
    // (i.e. the accordion has actually finished animating) before scrolling,
    // as a page-scoped supplement — deliberately not touching the shared
    // sidebar component, so this can't affect any other page's behavior.
    scrollSidebarToBulkConnect() {
      let lastTop = null;
      let stableFrames = 0;
      let attempts = 0;
      const tick = () => {
        const active = document.querySelector('.liquid-full-sidebar .liquid-menu-active, .liquid-mini-sidebar .liquid-mini-active');
        if (active) {
          const top = active.getBoundingClientRect().top;
          stableFrames = lastTop !== null && Math.abs(top - lastTop) < 1 ? stableFrames + 1 : 0;
          lastTop = top;
          if (stableFrames >= 6) {
            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            active.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
            return;
          }
        }
        attempts++;
        if (attempts < 240) requestAnimationFrame(tick);
      };
      this.$nextTick(() => requestAnimationFrame(tick));
    },

    statusClass(status) {
      return {
        Sent: 'lg-chip-green',
        Sending: 'lg-chip-indigo',
        Pending: 'lg-chip-indigo',
        Failed: 'lg-chip-red',
      }[status] || 'lg-chip-indigo';
    },

    applyFilters() {
      this.$inertia.get(this.route('wims-connect.bulk-connect.history.index'), this.form, { preserveState: true, replace: true });
    },

    clearFilters() {
      this.form = { channel: '', status: '', search: '', date_from: '', date_to: '' };
      this.$inertia.get(this.route('wims-connect.bulk-connect.history.index'), {}, { preserveState: true, replace: true });
    },

    retry(r) {
      this.$inertia.post(this.route('wims-connect.bulk-connect.history.retry', r.id), {}, { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.bsm-filter-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(min(190px, 100%), 1fr)); }
.bsm-filter-grid > * { min-width: 0; }
.bsm-search-wrap { display: flex; align-items: center; gap: 8px; border: 1px solid #e2e8f0; border-radius: 0.85rem; padding: 0 0.8rem; background: #fff; }
.bsm-search-wrap:focus-within { border-color: #d946ef; box-shadow: 0 0 0 3px rgba(217, 70, 239, 0.15); }
.bsm-search-icon { width: 14px; height: 14px; color: #94a3b8; flex: none; }
.bsm-search-input { flex: 1; min-width: 0; border: none !important; outline: none; box-shadow: none !important; background: transparent; padding: 0.55rem 0 !important; font-size: 0.82rem; color: var(--lg-ink); }

.bsm-date-wrap { display: flex; align-items: center; gap: 7px; border: 1px solid #e2e8f0; border-radius: 0.85rem; padding: 0.35rem 0.7rem; background: #fff; }
.bsm-date-wrap:focus-within { border-color: #d946ef; box-shadow: 0 0 0 3px rgba(217, 70, 239, 0.15); }
.bsm-date-wrap > div { flex: 1 1 auto; min-width: 0; }
.bsm-date-icon { width: 13px; height: 13px; color: #94a3b8; flex: none; }
.bsm-date-caption { display: block; font-size: 0.66rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; line-height: 1; margin-bottom: 2px; }
.bsm-date-input { display: block; width: 100%; border: none !important; outline: none; box-shadow: none !important; background: transparent; padding: 0 !important; font-size: 0.76rem; color: #475569; }

.bsm-msg-tip::after { white-space: pre-line; min-width: 220px; max-width: 320px; }
html.dark .bsm-search-wrap, html.dark .bsm-date-wrap { background: #1e293b; border-color: rgba(255, 255, 255, 0.08); color: #e2e8f0; }
html.dark .bsm-date-input { color: #cbd5e1; }
</style>

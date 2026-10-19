<template>
  <admin-layout :title="$t('Security Reminders')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="lock-closed" :title="$t('Security Reminders')" :subtitle="$t('Nudge users to set up a strong password and Two-Factor Authentication.')">
        <Link :href="route('security-reminders.history')" class="lg-btn lg-btn-dark">
          <icons name="clock" class="w-4 h-4" />
          <span class="hidden sm:inline">{{ $t('History') }}</span>
        </Link>
        <button v-if="$can('send-security-reminders')" type="button" class="lg-btn lg-btn-red" @click="openConfirm('all')">
          <icons name="bell" class="w-4 h-4" />
          {{ $t('Send Reminder to All Users') }}
        </button>
      </page-header>

      <stat-tiles :items="statItems" @select="item => setStatusFilter(item.status)" />

      <div v-if="selectedIds.length" class="lg-note lg-note-red sr-bulk-bar">
        <span class="font-bold">{{ selectedIds.length }} {{ $t('selected') }}</span>
        <div class="flex items-center gap-2 ml-auto flex-wrap">
          <button type="button" class="lg-btn lg-btn-soft" @click="selectedIds = []">{{ $t('Clear Selection') }}</button>
          <button v-if="$can('send-security-reminders')" type="button" class="lg-btn lg-btn-red" @click="openConfirm('selected')">
            <icons name="bell" class="w-4 h-4" /> {{ $t('Send Reminder to Selected') }}
          </button>
        </div>
      </div>

      <section class="lg-card lg-filters relative z-20">
        <div class="flex flex-col sm:flex-row sm:items-center gap-3">
          <div class="sr-search-wrap flex-1">
            <icons name="search" class="sr-search-icon" />
            <input v-model="form.search" type="text" class="sr-search-input" :placeholder="$t('Search name or email…')" />
          </div>
          <button type="button" class="lg-btn lg-btn-soft sr-quick-btn" @click="selectAllWithoutTwoFactor">
            <icons name="exclamation" class="w-3.5 h-3.5" />
            {{ $t('Select all without 2FA') }}
          </button>
        </div>
      </section>

      <section class="lg-card" style="padding: 1rem">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th class="w-10">
                  <input type="checkbox" class="sr-checkbox" :checked="allVisibleSelected" :aria-label="$t('Select all')" @change="toggleSelectAll" />
                </th>
                <th>{{ $t('Name') }}</th>
                <th>{{ $t('Email') }}</th>
                <th>{{ $t('Role') }}</th>
                <th>{{ $t('2FA Status') }}</th>
                <th>{{ $t('Last Reminded') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="u in filteredUsers" :key="u.id">
                <td @click.stop>
                  <input type="checkbox" class="sr-checkbox" :checked="selectedIds.includes(u.id)" :aria-label="$t('Select')" @change="toggleSelected(u.id)" />
                </td>
                <td :data-label="$t('Name')"><span class="lg-main">{{ u.name }}</span></td>
                <td :data-label="$t('Email')">{{ u.email }}</td>
                <td :data-label="$t('Role')"><span v-if="u.role" class="lg-chip lg-chip-indigo" style="text-transform: none">{{ u.role }}</span><span v-else class="lg-subtext">—</span></td>
                <td :data-label="$t('2FA Status')">
                  <span class="lg-chip" :class="u.two_factor_enabled ? 'lg-chip-green' : 'lg-chip-red'">
                    <icons v-if="u.two_factor_enabled" name="otp" class="w-3 h-3" />
                    <span class="sr-status-dot" v-else></span>
                    {{ u.two_factor_enabled ? $t('Enabled') : $t('Not Enabled') }}
                  </span>
                </td>
                <td :data-label="$t('Last Reminded')"><span class="lg-subtext">{{ u.last_reminded_at ? $datetime(u.last_reminded_at) : $t('Never') }}</span></td>
              </tr>
              <tr v-if="!filteredUsers.length" class="lg-empty-row">
                <td class="lg-empty" colspan="6">{{ $t('No users match these filters.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <!-- Confirm / compose panel -->
    <div v-if="confirmOpen" class="sr-modal-overlay" @click.self="confirmOpen = false">
      <div class="sr-modal-panel">
        <div class="flex items-center gap-3 mb-4">
          <span class="lg-card-ico lg-ico-rose">
            <icons name="bell" class="w-5 h-5" />
          </span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Send Security Reminder') }}</h2>
            <p class="lg-card-sub">{{ confirmMessage }}</p>
          </div>
          <button type="button" class="sr-close-btn" :aria-label="$t('Close')" @click="confirmOpen = false">
            <icons name="cross" class="w-4 h-4" />
          </button>
        </div>
        <label class="sr-label">{{ $t('Message') }}</label>
        <textarea v-model="sendForm.message" class="sr-textarea" rows="3"></textarea>
        <div class="flex items-center justify-end gap-2 mt-4">
          <button type="button" class="lg-btn lg-btn-soft" @click="confirmOpen = false">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-red" :disabled="sending" @click="confirmSend">
            <icons name="bell" class="w-4 h-4" />
            {{ sending ? $t('Sending…') : $t('Send Now') }}
          </button>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import debounce from 'lodash/debounce';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

// Mirrors SecurityReminderController::send()'s own fallback text exactly —
// shown here so the admin sees (and can edit) precisely what a user with a
// blank message would otherwise receive, rather than compose blind.
const DEFAULT_MESSAGE = 'We recommend setting a strong password and enabling Two-Factor Authentication to keep your account secure. This only takes a minute.';

export default {
  components: { AdminLayout, Link, PageHeader, StatTiles },

  props: {
    users: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({ total: 0, two_factor_enabled: 0, two_factor_disabled: 0 }) },
  },

  data() {
    return {
      form: {
        search: this.filters?.search || '',
        two_factor: this.filters?.two_factor || '',
      },
      selectedIds: [],
      confirmOpen: false,
      confirmMode: 'selected',
      sending: false,
      sendForm: { message: '' },
    };
  },

  computed: {
    statItems() {
      return [
        { label: this.$t('Total Users'), value: this.stats.total, icon: 'users', tone: 'slate', status: '', active: this.form.two_factor === '' },
        { label: this.$t('2FA Enabled'), value: this.stats.two_factor_enabled, icon: 'tick', tone: 'emerald', status: 'enabled', active: this.form.two_factor === 'enabled' },
        { label: this.$t('2FA Not Enabled'), value: this.stats.two_factor_disabled, icon: 'exclamation', tone: 'rose', status: 'disabled', active: this.form.two_factor === 'disabled' },
      ];
    },
    filteredUsers() {
      return this.users;
    },
    allVisibleSelected() {
      return this.filteredUsers.length > 0 && this.filteredUsers.every(u => this.selectedIds.includes(u.id));
    },
    confirmTargetCount() {
      return this.confirmMode === 'all' ? this.stats.total : this.selectedIds.length;
    },
    confirmMessage() {
      // vue-i18n only interpolates {placeholders} for keys already present
      // in lang/en.json (resources/js/Core/i18n.js's `missing` handler
      // returns the raw key, uninterpolated, for anything not yet
      // registered there) — plain JS replace works regardless of whether
      // this string has been added to the catalog yet.
      return this.$t('This will notify {count} user(s).').replace('{count}', this.confirmTargetCount);
    },
  },

  watch: {
    'form.search': debounce(function () {
      this.applyFilters();
    }, 400),
  },

  methods: {
    applyFilters() {
      this.$inertia.get(this.route('security-reminders.index'), this.form, { preserveState: true, replace: true });
    },

    setStatusFilter(status) {
      this.form.two_factor = status;
      this.applyFilters();
    },

    toggleSelected(id) {
      const index = this.selectedIds.indexOf(id);
      if (index === -1) {
        this.selectedIds.push(id);
      } else {
        this.selectedIds.splice(index, 1);
      }
    },

    toggleSelectAll() {
      const visibleIds = this.filteredUsers.map(u => u.id);
      if (this.allVisibleSelected) {
        this.selectedIds = this.selectedIds.filter(id => !visibleIds.includes(id));
      } else {
        this.selectedIds = [...new Set([...this.selectedIds, ...visibleIds])];
      }
    },

    selectAllWithoutTwoFactor() {
      this.selectedIds = this.users.filter(u => !u.two_factor_enabled).map(u => u.id);
    },

    openConfirm(mode) {
      if (mode === 'selected' && !this.selectedIds.length) return;
      this.confirmMode = mode;
      this.sendForm.message = this.$t(DEFAULT_MESSAGE);
      this.confirmOpen = true;
    },

    confirmSend() {
      this.sending = true;
      this.$inertia.post(this.route('security-reminders.send'), {
        mode: this.confirmMode,
        user_ids: this.confirmMode === 'selected' ? this.selectedIds : [],
        message: this.sendForm.message,
      }, {
        preserveScroll: true,
        onFinish: () => { this.sending = false; },
        onSuccess: () => {
          this.confirmOpen = false;
          this.selectedIds = [];
        },
      });
    },
  },
};
</script>

<style scoped>
.sr-bulk-bar { align-items: center; margin-bottom: 1rem; padding: 0.7rem 1rem; font-size: 0.85rem; }
.sr-search-wrap { display: flex; align-items: center; gap: 8px; border: 1px solid #e2e8f0; border-radius: 9999px; padding: 0 0.9rem; background: #fff; }
.sr-search-wrap:focus-within { border-color: #e11d48; box-shadow: 0 0 0 3px rgba(225, 29, 72, 0.12); }
.sr-search-icon { width: 14px; height: 14px; color: #94a3b8; flex: none; }
.sr-search-input { flex: 1; min-width: 0; border: none !important; outline: none; box-shadow: none !important; background: transparent; padding: 0.55rem 0 !important; font-size: 0.82rem; color: var(--lg-ink); }
.sr-quick-btn { color: #9a3412; border-color: #fed7aa; background: #fff7ed; }
html.dark .sr-quick-btn { color: #fdba74; background: rgba(251, 146, 60, 0.1); border-color: rgba(251, 146, 60, 0.3); }
.sr-checkbox { width: 1rem; height: 1rem; border-radius: 0.3rem; border: 1px solid #cbd5e1; accent-color: #e11d48; cursor: pointer; }
.sr-status-dot { width: 6px; height: 6px; border-radius: 9999px; background: currentColor; }

.sr-modal-overlay {
  position: fixed; inset: 0; z-index: 60; background: rgba(15, 23, 42, 0.45);
  display: flex; align-items: center; justify-content: center; padding: 1rem;
  backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
}
.sr-modal-panel {
  width: 100%; max-width: 28rem; border-radius: 22px; padding: 1.4rem;
  background: #fff; border: 1px solid #e6ebf3;
  box-shadow: 0 24px 60px -16px rgba(15, 23, 42, 0.45);
}
.sr-close-btn { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 2rem; height: 2rem; border-radius: 9999px; color: #64748b; }
.sr-close-btn:hover { background: #f1f5f9; color: #1f2937; }
.sr-label { display: block; margin-bottom: 4px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #94a3b8; }
.sr-textarea {
  width: 100%; border: 1px solid #e2e8f0; border-radius: 0.85rem; padding: 0.6rem 0.8rem;
  font-size: 0.82rem; resize: vertical; background: #fff;
}
.sr-textarea:focus { outline: none; border-color: #e11d48; box-shadow: 0 0 0 3px rgba(225, 29, 72, 0.15); }

html.dark .sr-search-wrap { background: #1e293b; border-color: rgba(255, 255, 255, 0.08); color: #e2e8f0; }
html.dark .sr-modal-panel { background: #0f172a; border-color: rgba(255, 255, 255, 0.1); }
html.dark .sr-close-btn:hover { background: rgba(255, 255, 255, 0.08); color: #e2e8f0; }
html.dark .sr-textarea { background: #1e293b; border-color: rgba(255, 255, 255, 0.1); color: #e2e8f0; }
</style>

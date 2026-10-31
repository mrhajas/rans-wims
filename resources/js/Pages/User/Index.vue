<template>
  <admin-layout :title="$t('Users')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="users" :title="$t('Users')" :subtitle="$t('Review users, roles, and warehouse access below.')">
        <Link v-if="$can('create-users')" :href="route('users.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />{{ $t('create_x', { x: $t('User') }) }}
        </Link>
        <button v-if="canResetPassword" type="button" class="lg-btn tone" style="--tone: #d97706" @click="resetOpen = true">
          <span class="tone-ico"><icons name="key" class="w-4 h-4" /></span>{{ $t('Reset Password') }}
        </button>
        <button v-if="$can('audit-users')" type="button" class="lg-btn tone" style="--tone: #4f46e5" @click="openAudit(null)">
          <span class="tone-ico"><icons name="clock" class="w-4 h-4" /></span>{{ $t('Audit History') }}
        </button>
      </page-header>

      <stat-tiles :items="statTiles" />

      <!-- Filters -->
      <div class="lg-card usr-filter print:hidden relative z-20">
        <search-filter v-model="form.search" class="w-full lg:max-w-sm" @reset="reset">
          <label class="block text-gray-700 inline-flex items-center gap-2"><icons name="group" class="w-4 h-4 text-slate-600" />{{ $t('Role') }}:</label>
          <select-input v-model="form.role" class="mt-1 w-full">
            <option :value="null">{{ $t('All') }}</option>
            <template v-for="role in roles" :key="role">
              <option :value="role.name">{{ $t(role.name) }}</option>
            </template>
          </select-input>
          <label class="mt-4 block text-gray-700 inline-flex items-center gap-2"><icons name="trash" class="w-4 h-4 text-slate-600" />{{ $t('Trashed') }}:</label>
          <select-input v-model="form.trashed" class="mt-1 w-full">
            <option :value="null">{{ $t('Not Trashed') }}</option>
            <option value="with">{{ $t('With Trashed') }}</option>
            <option value="only">{{ $t('Only Trashed') }}</option>
          </select-input>
        </search-filter>

        <div class="usr-chips" role="tablist" :aria-label="$t('Filter by status')">
          <button
            v-for="c in statusChips"
            :key="c.key"
            type="button"
            role="tab"
            :aria-selected="(form.status || '') === c.key"
            class="usr-fchip"
            :class="{ 'usr-fchip-on': (form.status || '') === c.key }"
            @click="form.status = c.key || null"
          >
            <icons :name="c.icon" class="w-3.5 h-3.5" />
            <span>{{ c.label }}</span>
          </button>
        </div>
      </div>

      <div class="lg-card" style="padding: 1rem">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('User') }}</th>
                <th>{{ $t('Contact') }}</th>
                <th>{{ $t('Role') }}</th>
                <th>{{ $t('Warehouses Access') }}</th>
                <th>{{ $t('Status') }}</th>
                <th class="num"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="user in users.data"
                :key="user.id"
                @click="goto(route('users.edit', user.id))"
                :class="{ 'is-click': $can('update-users'), 'usr-row-muted': user.deleted_at || !user.is_active }"
              >
                <td :data-label="$t('User')">
                  <div class="lg-person">
                    <span class="usr-avatar-wrap lg-tip tip-left" :data-tip="isOnline(user) ? $t('Online') : $t('Offline')">
                      <img v-if="user.photo && !user.photo_failed && !user.photo.includes('ui-avatars') && !user.photo.includes('default-avatar')" class="usr-avatar" :src="user.photo" alt="" @error="user.photo_failed = true" />
                      <span v-else class="lg-avatar usr-avatar usr-avatar-initials">{{ userInitials(user.name) }}</span>
                      <span class="usr-presence" :class="isOnline(user) ? 'usr-presence-on' : 'usr-presence-off'"></span>
                    </span>
                    <div class="min-w-0 usr-who">
                      <div class="lg-main truncate">{{ user.name }}</div>
                      <div class="lg-subtext truncate">
                        <span v-if="user.staff_number" class="lg-chip lg-chip-blue lg-tip tip-left usr-staff" :data-tip="$t('Linked to staff profile')">{{ user.staff_number }}</span>@{{ user.username }} &middot;
                        <span :class="isOnline(user) ? 'usr-on-txt' : ''">{{ isOnline(user) ? $t('Online') : lastSeen(user) }}</span>
                      </div>
                    </div>
                  </div>
                </td>
                <td :data-label="$t('Contact')">
                  <div class="usr-email lg-tip tip-left" :data-tip="user.email">{{ user.email }}</div>
                  <div class="lg-subtext">{{ user.phone || '—' }}</div>
                </td>
                <td :data-label="$t('Role')">
                  <div class="flex flex-wrap items-center gap-1 usr-end">
                    <span v-for="(r, ri) in user.roles || []" :key="ri" class="lg-chip" :class="roleClass(r)" style="text-transform: none">{{ $t(r) }}</span>
                    <span v-if="!(user.roles || []).length" class="lg-subtext">—</span>
                  </div>
                </td>
                <td :data-label="$t('Warehouses Access')">
                  <div class="flex flex-wrap items-center gap-1 usr-end">
                    <template v-if="user.warehouses_access && user.warehouses_access.length">
                      <span v-for="(w, i) in user.warehouses_access.slice(0, 1)" :key="`${user.id}-wh-${i}`" class="lg-chip lg-chip-slate usr-wh" style="text-transform: none">{{ w }}</span>
                      <span
                        v-if="user.warehouses_access.length > 1"
                        class="lg-chip lg-chip-indigo lg-tip tip-left"
                        :data-tip="user.warehouses_access.slice(1).join(', ')"
                      >+{{ user.warehouses_access.length - 1 }}</span>
                    </template>
                    <span v-else class="lg-subtext">—</span>
                  </div>
                </td>
                <td :data-label="$t('Status')">
                  <div class="flex flex-wrap items-center gap-1.5 usr-end">
                    <span class="lg-chip" :class="statusPill(user).cls"><span class="usr-dot"></span>{{ statusPill(user).label }}</span>
                    <span v-if="user.two_factor_enabled" class="lg-chip lg-chip-cyan lg-tip tip-left" :data-tip="$t('Two-Factor Enabled')"><icons name="otp" class="w-3.5 h-3.5" />{{ $t('2-step') }}</span>
                  </div>
                </td>
                <td class="num">
                  <div class="lg-actions items-center">
                    <span v-if="$can('audit-users')" class="usr-audit-wrap">
                      <button type="button" class="usr-audit" :aria-label="$t('Audit History')" @click.stop="openAudit(user)"><icons name="clock" class="w-4 h-4" /></button>
                      <span class="usr-audit-tip">{{ $t('Audit History') }}</span>
                    </span>
                    <login-as-user-action v-if="canImpersonate(user)" :user="user" @click.stop />
                    <icons v-if="$can('update-users')" name="chevron-down" class="usr-go transform -rotate-90 block w-4 h-4" />
                  </div>
                </td>
              </tr>
              <tr v-if="users.data.length === 0" class="lg-empty-row">
                <td colspan="6" class="lg-empty">
                  <icons name="users" class="w-8 h-8 mx-auto mb-2 text-slate-300" />
                  {{ $t('There is no data to display.') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <pagination class="mt-6" :meta="users.meta" :links="users.links" />

      <reset-password-modal v-if="canResetPassword" :show="resetOpen" :users="resetUsers" @close="resetOpen = false" />
      <audit-modal v-if="$can('audit-users')" :show="auditOpen" :user-id="auditUser ? auditUser.id : null" :user-name="auditUser ? auditUser.name : ''" @close="auditOpen = false" />
    </div>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import Pagination from '@/Shared/Pagination.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import LoginAsUserAction from '@/Shared/Administration/LoginAsUserAction.vue';
import AuditModal from './Parts/AuditModal.vue';
import ResetPasswordModal from './ResetPasswordModal.vue';

export default {
  components: {
    Pagination,
    AdminLayout,
    SelectInput,
    SearchFilter,
    PageHeader,
    StatTiles,
    LoginAsUserAction,
    AuditModal,
    ResetPasswordModal,
  },

  props: {
    roles: Object,
    users: Object,
    filters: Object,
    stats: Object,
    canResetPassword: Boolean,
    resetUsers: { type: Array, default: () => [] },
  },

  data() {
    return {
      resetOpen: false,
      auditOpen: false,
      auditUser: null,
      form: {
        role: this.filters.role,
        search: this.filters.search,
        trashed: this.filters.trashed,
        status: this.filters.status,
      },
    };
  },

  computed: {
    statTiles() {
      const s = this.stats || {};
      return [
        { icon: 'users', tone: 'indigo', label: this.$t('Total users'), value: s.total },
        { icon: 'tick', tone: 'emerald', label: this.$t('Active'), value: s.active },
        { icon: 'exclamation', tone: 'rose', label: this.$t('Disabled'), value: s.disabled },
        { icon: 'otp', tone: 'sky', label: this.$t('Two-step protected') + ' (' + this.protectedPercent + '% ' + this.$t('of all users') + ')', value: s.protected },
        { icon: 'wifi', tone: 'amber', label: this.$t('Online now'), value: s.online },
      ];
    },
    protectedPercent() {
      return this.stats.total ? Math.round((this.stats.protected / this.stats.total) * 100) : 0;
    },
    statusChips() {
      return [
        { key: '', label: this.$t('All'), icon: 'users' },
        { key: 'active', label: this.$t('Active'), icon: 'tick' },
        { key: 'disabled', label: this.$t('Disabled'), icon: 'exclamation' },
        { key: 'protected', label: this.$t('2-step on'), icon: 'otp' },
        { key: 'unprotected', label: this.$t('2-step off'), icon: 'lock-open' },
        { key: 'online', label: this.$t('Online now'), icon: 'wifi' },
      ];
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        let query = pickBy(this.form);
        this.$inertia.visit(this.route('users.index', Object.keys(query).length ? query : { remember: 'forget' }), {
          onFinish: () => {
            const el = document.getElementById('page-search');
            if (el) el.focus();
          },
        });
      }, 150),
      deep: true,
    },
  },

  methods: {
    // the audit history window: of one user (the clock beside the row) or of everyone (the button at the top)
    openAudit(user) {
      this.auditUser = user ? { id: user.id, name: user.name } : null;
      this.auditOpen = true;
    },
    goto(link) {
      if (this.$can('update-users')) {
        this.$inertia.visit(link);
      }
    },
    canImpersonate(user) {
      if (!this.$can('start-impersonation')) return false;
      if (user.id === this.$page.props.auth.user.id) return false;
      if (user.deleted_at) return false;
      if (!user.is_active) return false;
      if ((user.roles || []).includes('Super Admin') && !this.$page.props.licenceManager) return false;
      return true;
    },
    reset() {
      this.form = mapValues(this.form, () => null);
    },
    userInitials(name) {
      return (name || '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map(w => w[0].toUpperCase())
        .join('');
    },
    isOnline(user) {
      return !!user.last_seen_at && Date.now() - new Date(user.last_seen_at).getTime() < 5 * 60 * 1000;
    },
    statusPill(user) {
      if (user.deleted_at) return { label: this.$t('Trashed'), cls: 'lg-chip-red' };
      if (!user.is_active) return { label: this.$t('Disabled'), cls: 'lg-chip-amber' };
      return { label: this.$t('Active'), cls: 'lg-chip-green' };
    },
    roleClass(role) {
      if (role === 'Super Admin') return 'lg-chip-amber';
      if (role === 'Admin') return 'lg-chip-blue';
      return 'lg-chip-indigo';
    },
    lastSeen(user) {
      if (!user.last_seen_at) return this.$t('Never');
      const t = new Date(user.last_seen_at);
      const mins = Math.floor((Date.now() - t.getTime()) / 60000);
      if (mins < 2) return this.$t('Just now');
      if (mins < 60) return mins + ' ' + this.$t('min ago');
      if (mins < 60 * 24) return Math.floor(mins / 60) + ' ' + this.$t('h ago');
      return this.$date(user.last_seen_at);
    },
  },
};
</script>

<style scoped>
.usr-filter { display: flex; flex-direction: column; gap: 0.75rem; padding: 0.9rem 1.1rem; }
@media (min-width: 1024px) { .usr-filter { flex-direction: row; align-items: center; justify-content: space-between; } }
.usr-chips { display: flex; gap: 6px; overflow-x: auto; padding: 4px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); scrollbar-width: none; }
.usr-chips::-webkit-scrollbar { display: none; }
.usr-fchip { flex: none; display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 10px; border: 1px solid transparent; background: transparent; font-size: 0.76rem; font-weight: 700; color: var(--lg-mute); white-space: nowrap; cursor: pointer; transition: background 0.15s ease, color 0.15s ease, box-shadow 0.15s ease; }
.usr-fchip:hover { color: var(--lg-navy); background: var(--lg-card); }
.usr-fchip-on, .usr-fchip-on:hover { color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); box-shadow: 0 8px 14px -8px rgba(15, 37, 64, 0.9); }

.usr-row-muted .usr-avatar, .usr-row-muted .usr-avatar-initials { filter: grayscale(0.7); opacity: 0.8; }
/* a deactivated (or deleted) person: maroon row, white text */
.usr-row-muted td, .usr-row-muted:hover td { background: #6b1020 !important; color: #fff; }
.usr-row-muted .lg-main, .usr-row-muted .lg-subtext, .usr-row-muted .usr-email, .usr-row-muted .usr-on-txt, .usr-row-muted .usr-go { color: #fff !important; }
.usr-row-muted .lg-chip { color: #6b1020 !important; background: #fff !important; }
.usr-row-muted .usr-avatar-initials { color: #6b1020; background: #fff; opacity: 1; filter: none; }
.usr-go { color: var(--lg-faint); transition: color 0.15s ease, transform 0.15s ease; }
tr:hover .usr-go { color: #4f6df5; transform: translateX(2px) rotate(-90deg); }

.usr-avatar-wrap { position: relative; display: inline-flex; flex: none; }
.usr-avatar { display: block; width: 38px; height: 38px; border-radius: 9999px; object-fit: cover; box-shadow: 0 0 0 2px var(--lg-card), 0 4px 10px -4px rgba(15, 23, 42, 0.35); }
.usr-avatar-initials { display: inline-flex; align-items: center; justify-content: center; font-size: 0.78rem; }
.usr-presence { position: absolute; right: -1px; bottom: -1px; width: 11px; height: 11px; border-radius: 9999px; border: 2px solid var(--lg-card); }
.usr-presence-on { background: #22c55e; box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2); }
.usr-presence-off { background: #cbd5e1; }
.usr-who { max-width: 15rem; }
.usr-on-txt { color: #059669; font-weight: 700; }
.usr-staff { margin-right: 6px; padding: 0 6px; font-size: 0.66rem; vertical-align: 1px; text-transform: none; }
.usr-email { display: inline-block; max-width: 13rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.83rem; }
.usr-wh { max-width: 9rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; }
.usr-dot { width: 6px; height: 6px; border-radius: 9999px; background: currentColor; }

@media (max-width: 767.98px) {
  .usr-end { justify-content: flex-end; }
  .usr-who { max-width: 12rem; text-align: left; }
  .usr-email { max-width: 11rem; }
  .usr-go { display: none; }
}
@media (prefers-reduced-motion: reduce) { .usr-go { transition: none; } }
.usr-audit-wrap { position: relative; display: inline-flex; }
.usr-audit { display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box; flex: none; width: 1.9rem; height: 1.9rem; min-width: 0 !important; min-height: 0 !important; padding: 0 !important; border: 0; border-radius: 9999px; cursor: pointer; color: #fff; background: linear-gradient(145deg, #6366f1, #3730a3); box-shadow: 0 2px 6px -2px rgba(55, 48, 163, 0.65); transition: background 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease; }
.usr-audit:hover { background: linear-gradient(145deg, #818cf8, #4338ca); box-shadow: 0 6px 14px -4px rgba(55, 48, 163, 0.7); transform: translateY(-1px); }
.usr-audit :deep(div) { flex: none; margin: 0; }
.usr-audit :deep(svg) { width: 16px !important; height: 16px !important; }
.usr-audit-tip { position: absolute; left: 50%; bottom: calc(100% + 8px); transform: translateX(-50%) translateY(4px); white-space: nowrap; padding: 0.35rem 0.6rem; border-radius: 0.45rem; font-size: 0.68rem; font-weight: 600; background: #0f172a; color: #f1f5f9; box-shadow: 0 10px 24px -8px rgba(15, 23, 42, 0.45); opacity: 0; visibility: hidden; pointer-events: none; z-index: 30; transition: opacity 0.15s ease, transform 0.15s ease; }
.usr-audit-wrap:hover .usr-audit-tip { opacity: 1; visibility: visible; transform: translateX(-50%) translateY(0); }
</style>

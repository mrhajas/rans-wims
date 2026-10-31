<template>
  <admin-layout :title="$t('Profile')">
    <div class="lg-page px-4 md:px-0 pf-shell">
      <!-- Hero -->
      <header class="lg-hero pf-hero">
        <div class="pf-avatar-wrap lg-tip tip-left" :data-tip="$t('Online')">
          <span class="pf-avatar-ring" aria-hidden="true"></span>
          <img :src="user.profile_photo_url" :alt="user.name" class="pf-avatar" />
          <span class="pf-avatar-dot"></span>
        </div>

        <div class="pf-hero-main">
          <div class="pf-eyebrow">{{ $t('My Profile') }}</div>
          <h1 class="pf-name">{{ user.name }}</h1>
          <div class="pf-sub">
            <span class="pf-sub-item"><icons name="user" class="w-3.5 h-3.5" /> @{{ user.username }}</span>
            <span class="pf-sub-item break-all"><icons name="email" class="w-3.5 h-3.5" /> {{ user.email }}</span>
          </div>
          <div class="pf-chips">
            <span v-for="role in user.roles" :key="role.id" class="lg-chip lg-chip-indigo" style="text-transform: none"><icons name="key" class="w-3 h-3" /> {{ role.name }}</span>
            <span class="lg-chip lg-chip-green"><span class="pf-dot"></span>{{ $t('Active') }}</span>
            <a v-if="protectedBy.length" href="#two-factor" class="lg-chip lg-chip-green pf-chip-link" style="text-transform: none" @click.prevent="go(firstSecurityId)">
              <icons name="tick" class="w-3 h-3" /> {{ $t('Two-step on') }}: {{ protectedBy.join(' · ') }}
            </a>
            <a v-else href="#two-factor" class="lg-chip lg-chip-amber pf-chip-link" style="text-transform: none" @click.prevent="go(firstSecurityId)">
              <icons name="exclamation" class="w-3 h-3" /> {{ $t('Two-step verification is off') }}
            </a>
            <a v-if="$page.props.faceLogin && $page.props.faceLogin.enabled && $page.props.faceLogin.enrolled" href="#face-login" class="pf-face-chip" @click.prevent="go('face-login')">
              <icons name="camera" class="w-3 h-3" /><span>{{ $t('Face Login ID') }} - {{ $t('Active') }}</span>
            </a>
          </div>
        </div>
      </header>

      <stat-tiles :items="factTiles" />

      <div class="pf-layout">
        <!-- Section menu -->
        <nav class="pf-nav" :aria-label="$t('Profile sections')">
          <div v-for="group in visibleGroups" :key="group.title" class="pf-nav-group">
            <div class="pf-nav-title">{{ group.title }}</div>
            <button
              v-for="item in group.items"
              :key="item.id"
              type="button"
              class="pf-nav-item"
              :class="{ 'pf-nav-item-active': active === item.id, 'pf-nav-item-danger': item.danger }"
              @click="go(item.id)"
            >
              <span class="pf-nav-icon"><icons :name="item.icon" class="w-4 h-4" /></span>
              <span class="truncate">{{ item.label }}</span>
            </button>
          </div>
        </nav>

        <!-- Sections -->
        <div class="pf-content">
          <section v-if="$page.props.jetstream.canUpdateProfileInformation" id="profile" class="pf-section">
            <update-profile-information-form :user="user" />
          </section>

          <section v-if="$page.props.jetstream.canUpdatePassword" id="password" class="pf-section">
            <update-password-form />
          </section>

          <template v-if="$page.props.jetstream.canManageTwoFactorAuthentication">
            <section id="two-factor" class="pf-section">
              <two-factor-authentication-form />
            </section>
            <section id="otp-codes" class="pf-section">
              <two-factor-otp-form />
            </section>
          </template>

          <section v-if="$page.props.faceLogin && $page.props.faceLogin.enabled" id="face-login" class="pf-section">
            <face-login-form />
          </section>

          <section id="sessions" class="pf-section">
            <logout-other-browser-sessions-form :sessions="sessions" />
          </section>

          <section id="auto-logout" class="pf-section">
            <auto-logout-form />
          </section>

          <section id="raucher" class="pf-section">
            <rauncher-shortcut-form />
          </section>

          <section id="scan" class="pf-section">
            <scan-shortcut-form />
          </section>

          <section id="reminders" class="pf-section">
            <task-reminder-preferences-form />
          </section>

          <section id="chime" class="pf-section">
            <notification-chime-form />
          </section>

          <section id="toasts" class="pf-section">
            <toast-alerts-form />
          </section>

          <section v-if="$page.props.superAdjustmentBypass" id="sa-bypass" class="pf-section">
            <super-adjustment-bypass-form />
          </section>

          <section v-if="$can('customize-dashboard-cards')" id="dashboard-cards" class="pf-section">
            <dashboard-cards-form />
          </section>

          <section id="quick-menu" class="pf-section">
            <quick-menu-preference-form />
          </section>

          <section v-if="$page.props.jetstream.hasAccountDeletionFeatures" id="delete" class="pf-section">
            <delete-user-form />
          </section>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import DeleteUserForm from '@/Pages/Profile/DeleteUserForm.vue';
import UpdatePasswordForm from '@/Pages/Profile/UpdatePasswordForm.vue';
import TwoFactorAuthenticationForm from '@/Pages/Profile/TwoFactorAuthenticationForm.vue';
import TwoFactorOtpForm from '@/Pages/Profile/TwoFactorOtpForm.vue';
import FaceLoginForm from '@/Pages/Profile/FaceLoginForm.vue';
import UpdateProfileInformationForm from '@/Pages/Profile/UpdateProfileInformationForm.vue';
import LogoutOtherBrowserSessionsForm from '@/Pages/Profile/LogoutOtherBrowserSessionsForm.vue';
import RauncherShortcutForm from '@/Pages/Profile/RauncherShortcutForm.vue';
import ScanShortcutForm from '@/Pages/Profile/ScanShortcutForm.vue';
import TaskReminderPreferencesForm from '@/Pages/Profile/TaskReminderPreferencesForm.vue';
import NotificationChimeForm from '@/Pages/Profile/NotificationChimeForm.vue';
import ToastAlertsForm from '@/Pages/Profile/ToastAlertsForm.vue';
import QuickMenuPreferenceForm from '@/Pages/Profile/QuickMenuPreferenceForm.vue';
import DashboardCardsForm from '@/Pages/Profile/DashboardCardsForm.vue';
import AutoLogoutForm from '@/Pages/Profile/AutoLogoutForm.vue';
import SuperAdjustmentBypassForm from '@/Pages/Profile/SuperAdjustmentBypassForm.vue';

export default {
  props: ['sessions'],

  components: {
    AdminLayout,
    StatTiles,
    DeleteUserForm,
    UpdatePasswordForm,
    TwoFactorAuthenticationForm,
    TwoFactorOtpForm,
    FaceLoginForm,
    UpdateProfileInformationForm,
    LogoutOtherBrowserSessionsForm,
    RauncherShortcutForm,
    ScanShortcutForm,
    TaskReminderPreferencesForm,
    NotificationChimeForm,
    ToastAlertsForm,
    QuickMenuPreferenceForm,
    DashboardCardsForm,
    AutoLogoutForm,
    SuperAdjustmentBypassForm,
  },

  data() {
    return { active: 'profile', observer: null };
  },

  computed: {
    user() {
      return this.$page.props.auth.user;
    },

    // Which second-step methods are on, from props that refresh when the user changes them.
    protectedBy() {
      const list = [];
      const otp = this.$page.props.twoFactorOtp;
      if (this.user.two_factor_enabled) list.push(this.$t('Authenticator app'));
      if (otp && otp.email.enabled) list.push(this.$t('Email code'));
      if (otp && otp.sms.enabled) list.push(this.$t('SMS code'));
      return list;
    },

    factTiles() {
      return [
        { icon: 'calendar', tone: 'sky', label: this.$t('Member since'), value: this.user.created_at ? this.$date(this.user.created_at) : '—' },
        { icon: 'clock', tone: 'indigo', label: this.$t('Signed in at'), value: this.$page.props.sessionStartedAt ? this.$time(this.$page.props.sessionStartedAt) : '—' },
        {
          icon: 'lock-closed',
          tone: this.protectedBy.length ? 'emerald' : 'amber',
          label: this.$t('Security'),
          value: this.protectedBy.length ? this.$t('Protected') : this.$t('Needs attention'),
          valueClass: this.protectedBy.length ? 'is-good' : '',
        },
      ];
    },

    firstSecurityId() {
      return this.$page.props.jetstream.canManageTwoFactorAuthentication ? 'two-factor' : 'sessions';
    },

    visibleGroups() {
      const js = this.$page.props.jetstream;
      const groups = [
        {
          title: this.$t('Account'),
          items: [
            { id: 'profile', label: this.$t('Profile information'), icon: 'user', show: js.canUpdateProfileInformation },
            { id: 'password', label: this.$t('Password'), icon: 'lock-closed', show: js.canUpdatePassword },
          ],
        },
        {
          title: this.$t('Security'),
          items: [
            { id: 'two-factor', label: this.$t('Authenticator app'), icon: 'otp', show: js.canManageTwoFactorAuthentication },
            { id: 'otp-codes', label: this.$t('Email & SMS codes'), icon: 'sms', show: js.canManageTwoFactorAuthentication },
            { id: 'face-login', label: this.$t('Face login'), icon: 'camera', show: !!(this.$page.props.faceLogin && this.$page.props.faceLogin.enabled) },
            { id: 'sessions', label: this.$t('Browser sessions'), icon: 'wifi', show: true },
            { id: 'auto-logout', label: this.$t('Auto logout'), icon: 'clock', show: true },
          ],
        },
        {
          title: this.$t('Preferences'),
          items: [
            { id: 'raucher', label: this.$t('Raucher shortcut'), icon: 'search', show: true },
            { id: 'scan', label: this.$t('Scan shortcut'), icon: 'menu', show: true },
            { id: 'reminders', label: this.$t('Task reminders'), icon: 'bell', show: true },
            { id: 'chime', label: this.$t('Notification chime'), icon: 'microphone', show: true },
            { id: 'toasts', label: this.$t('Toast Alerts'), icon: 'bell', show: true },
            { id: 'dashboard-cards', label: this.$t('Dashboard cards'), icon: 'collection', show: this.$can('customize-dashboard-cards') },
            { id: 'quick-menu', label: this.$t('Quick menu'), icon: 'sparkles', show: true },
            { id: 'sa-bypass', label: this.$t('SuperAdjustment ByPass'), icon: 'adjustments', show: !!this.$page.props.superAdjustmentBypass },
          ],
        },
        {
          title: this.$t('Danger zone'),
          items: [{ id: 'delete', label: this.$t('Delete account'), icon: 'trash', show: js.hasAccountDeletionFeatures, danger: true }],
        },
      ];

      return groups.map(g => ({ ...g, items: g.items.filter(i => i.show) })).filter(g => g.items.length);
    },
  },

  mounted() {
    this.$nextTick(() => this.watchSections());
  },

  beforeUnmount() {
    if (this.observer) this.observer.disconnect();
  },

  methods: {
    go(id) {
      const el = document.getElementById(id);
      if (!el) return;
      this.active = id;
      el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    },

    // Highlights the menu entry of the section currently near the top of the viewport.
    watchSections() {
      if (typeof IntersectionObserver === 'undefined') return;
      const visible = new Map();
      this.observer = new IntersectionObserver(
        entries => {
          entries.forEach(e => (e.isIntersecting ? visible.set(e.target.id, e.boundingClientRect.top) : visible.delete(e.target.id)));
          if (visible.size) {
            this.active = [...visible.entries()].sort((a, b) => a[1] - b[1])[0][0];
          }
        },
        { rootMargin: '-15% 0px -65% 0px', threshold: 0 }
      );
      document.querySelectorAll('.pf-section').forEach(el => this.observer.observe(el));
    },
  },
};
</script>

<style>
/* ===== Profile page (unscoped on purpose: the pf-* helpers below are shared by every Profile/*Form.vue card) ===== */
.pf-shell { position: relative; }

/* Hero */
.pf-hero { align-items: center; gap: 1.25rem 1.5rem; padding: 1.4rem 1.5rem; }
.pf-avatar-wrap { position: relative; width: 104px; height: 104px; flex: none; }
.pf-avatar-ring {
  position: absolute; inset: -5px; border-radius: 9999px; filter: blur(0.3px);
  background: conic-gradient(from 0deg, #38bdf8, #818cf8, #f472b6, #fbbf24, #34d399, #38bdf8);
  animation: pf-spin 6s linear infinite;
}
.pf-avatar-ring::after { content: ''; position: absolute; inset: 4px; border-radius: 9999px; background: var(--lg-card, #fff); }
.pf-avatar { position: absolute; inset: 7px; width: calc(100% - 14px); height: calc(100% - 14px); border-radius: 9999px; object-fit: cover; box-shadow: 0 8px 18px -8px rgba(15, 23, 42, 0.5); }
.pf-avatar-dot { position: absolute; right: 6px; bottom: 6px; width: 18px; height: 18px; border-radius: 9999px; background: #22c55e; border: 3px solid var(--lg-card, #fff); box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.2); }
@keyframes pf-spin { to { transform: rotate(360deg); } }

.pf-hero-main { min-width: 0; flex: 1 1 260px; }
.pf-eyebrow { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; color: #4f6df5; }
.pf-name { margin-top: 2px; font-size: 1.6rem; line-height: 1.2; font-weight: 800; letter-spacing: -0.01em; color: var(--lg-ink); overflow-wrap: anywhere; }
.pf-sub { display: flex; flex-wrap: wrap; gap: 4px 16px; margin-top: 6px; font-size: 0.82rem; color: var(--lg-mute); }
.pf-sub-item { display: inline-flex; align-items: center; gap: 6px; }
.pf-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; }
.pf-chip-link { text-decoration: none; transition: transform 0.15s ease, box-shadow 0.15s ease; }
a.pf-chip-link:hover { transform: translateY(-1px); box-shadow: 0 6px 12px -8px rgba(15, 23, 42, 0.5); }
.pf-dot { width: 7px; height: 7px; border-radius: 9999px; background: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.22); }

/* Face Login ID - Active: same green-to-orange shimmer as the licence label in the footer */
.pf-face-chip { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800; text-decoration: none; border: 1.5px solid transparent; background: linear-gradient(var(--lg-card, #fff), var(--lg-card, #fff)) padding-box, linear-gradient(90deg, #16a34a, #f59e0b, #16a34a) border-box; background-size: 100% 100%, 250% 100%; animation: pf-face-border 6s linear infinite; transition: transform 0.15s ease, box-shadow 0.15s ease; }
.pf-face-chip svg { color: #16a34a; }
.pf-face-chip span { background: linear-gradient(90deg, #16a34a, #f59e0b, #16a34a); background-size: 250% 100%; -webkit-background-clip: text; background-clip: text; color: transparent; animation: pf-face-shimmer 6s linear infinite; }
.pf-face-chip:hover { transform: translateY(-1px); box-shadow: 0 6px 12px -8px rgba(22, 163, 74, 0.7); }
@keyframes pf-face-shimmer { 0% { background-position: 0% 0; } 100% { background-position: 250% 0; } }
@keyframes pf-face-border { 0% { background-position: 0 0, 0% 0; } 100% { background-position: 0 0, 250% 0; } }
@media (prefers-reduced-motion: reduce) { .pf-face-chip, .pf-face-chip span { animation: none; } }

/* Layout: sticky section menu + content */
.pf-layout { position: relative; display: grid; grid-template-columns: minmax(0, 1fr); gap: 1.25rem; align-items: start; }
.pf-content { display: flex; flex-direction: column; min-width: 0; }
.pf-content .lg-card { margin-bottom: 0; }
.pf-content .pf-section + .pf-section { margin-top: 1.1rem; }
.pf-section { scroll-margin-top: 18px; animation: pf-rise 0.5s cubic-bezier(0.16, 1, 0.3, 1) both; }
@keyframes pf-rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

.pf-nav { display: flex; gap: 6px; overflow-x: auto; padding: 6px; border-radius: 16px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); position: sticky; top: 0; z-index: 5; backdrop-filter: blur(14px); scrollbar-width: none; }
.pf-nav::-webkit-scrollbar { display: none; }
.pf-nav-group { display: contents; }
.pf-nav-title { display: none; }
.pf-nav-item { flex: none; display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 11px; border: 1px solid transparent; background: transparent; font-size: 0.8rem; font-weight: 700; color: var(--lg-mute); white-space: nowrap; cursor: pointer; transition: background 0.15s ease, color 0.15s ease, box-shadow 0.15s ease; }
.pf-nav-icon { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 9px; background: var(--lg-soft); color: var(--lg-mute); transition: background 0.15s ease, color 0.15s ease; }
.pf-nav-item:hover { background: var(--lg-soft); color: var(--lg-navy); }
.pf-nav-item-active { background: var(--lg-card); color: var(--lg-navy); border-color: var(--lg-line); box-shadow: 0 6px 14px -8px rgba(63, 99, 240, 0.6); }
.pf-nav-item-active .pf-nav-icon { background: linear-gradient(145deg, #2f6fb3, #0f2540); color: #fff; }
.pf-nav-item-danger { color: #b42318; }
.pf-nav-item-danger .pf-nav-icon { background: rgba(239, 68, 68, 0.12); color: #dc2626; }
.pf-nav-item-danger.pf-nav-item-active .pf-nav-icon { background: linear-gradient(145deg, #f87171, #dc2626); color: #fff; }

@media (min-width: 1024px) {
  .pf-layout { grid-template-columns: 230px minmax(0, 1fr); }
  .pf-nav { flex-direction: column; overflow: visible; padding: 12px; gap: 2px; top: 14px; }
  .pf-nav-group { display: flex; flex-direction: column; gap: 2px; }
  .pf-nav-group + .pf-nav-group { margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--lg-line); }
  .pf-nav-title { display: block; padding: 0 8px 4px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; color: var(--lg-faint); }
  .pf-nav-item { width: 100%; }
}
@media (max-width: 560px) {
  .pf-hero { padding: 1.1rem; justify-content: center; text-align: center; }
  .pf-sub, .pf-chips { justify-content: center; }
}

/* ---- Shared helpers used by the Profile/*Form.vue cards ---- */
.pf-head-chip { margin-left: auto; flex: none; }
.pf-foot { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 10px; margin-top: 1.1rem; padding-top: 0.95rem; border-top: 1px solid var(--lg-line); }
.pf-box { padding: 0.9rem 1rem; border-radius: 16px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.pf-lead { font-size: 0.84rem; line-height: 1.55; color: var(--lg-mute); }
.pf-title { font-size: 0.95rem; font-weight: 800; color: var(--lg-ink); }
.pf-kbd { display: inline-flex; align-items: center; justify-content: center; min-width: 2rem; padding: 4px 10px; border-radius: 9px; font-family: inherit; font-size: 0.8rem; font-weight: 700; color: var(--lg-ink); background: var(--lg-card); border: 1px solid var(--lg-line); border-bottom-width: 3px; }
.pf-hint { margin-top: 8px; font-size: 0.74rem; color: var(--lg-faint); }
.pf-err { margin-top: 8px; font-size: 0.8rem; font-weight: 700; color: #b42318; }
html.dark .pf-err { color: #fda4af; }
.pf-lbl { display: block; margin-bottom: 4px; font-size: 0.78rem; font-weight: 700; color: var(--lg-mute); }
.pf-card-danger { border-color: rgba(220, 38, 38, 0.28); }
.pf-modal-text { font-size: 0.86rem; line-height: 1.55; color: #475569; }
.pf-toggle-row { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; padding: 0.85rem 1rem; border-radius: 16px; background: var(--lg-soft); border: 1px solid var(--lg-line); }

@media (prefers-reduced-motion: reduce) {
  .pf-avatar-ring, .pf-section { animation: none; }
}
</style>

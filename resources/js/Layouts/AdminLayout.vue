<template>
  <Head :title="title" />
  <div class="app-shell wims-float bg-gray-100 min-h-screen print:bg-white" :class="{ 'wims-float-dash': isMainDashboard }">
    <div class="md:flex md:flex-col">
      <div class="md:h-screen md:flex md:flex-col">
        <top-bar
          class="print:hidden"
          :mobile-menu-open="show"
          :sidebar-hidden="sidebarHidden"
          :menu-unavailable="offlineReadMode"
          @on-mobile-menu-change="v => (show = v)"
          @on-sidebar-toggle="toggleSidebar"
          :class="{ 'md:fixed md:top-0 w-full z-20': $page.props.settings.sidebar == 'mini' }"
        />
        <impersonation-banner class="print:hidden" />
        <simulation-badge class="print:hidden" />
        <div v-if="offlineReadMode" class="md:flex md:grow md:items-stretch overflow-hidden">
          <div class="md:flex-1 md:px-4 py-6 md:p-6 overflow-x-hidden md:overflow-y-auto" scroll-region>
            <div class="app-canvas p-4 md:p-6">
              <offline-shell />
            </div>
            <app-footer />
          </div>
        </div>
        <div v-else class="md:flex md:grow md:items-stretch" :class="$page.props.settings.sidebar != 'mini' ? 'overflow-hidden' : 'pt'">
          <mini-main-menu v-if="sidebarHidden || $page.props.settings.sidebar == 'mini'" class="hidden nav:grid print:hidden ondark" />
          <full-main-menu v-else class="hidden nav:grid print:hidden ondark" />

          <!-- Off-canvas mobile/tablet drawer (below the `nav` breakpoint) -->
          <!-- a faint handle on the left edge: swipe from it to the right to open the menu (phones and tablets) -->
          <transition name="swipe-hint">
            <div v-if="swipeHint && !show" class="swipe-hint nav:hidden print:hidden" aria-hidden="true"><i></i></div>
          </transition>
          <transition name="drawer-backdrop">
            <div v-if="show" class="drawer-backdrop-root fixed inset-0 z-[100] bg-black/40 nav:hidden print:hidden" @click="show = false"></div>
          </transition>
          <transition name="drawer-panel">
            <div v-if="show" class="drawer-panel-root fixed inset-y-0 left-0 z-[101] w-72 max-w-[85vw] nav:hidden print:hidden">
              <full-main-menu class="h-full ondark" style="width: 100%" />
            </div>
          </transition>

          <div
            class="md:flex-1 md:px-4 py-6 md:p-6 overflow-x-hidden md:overflow-y-auto print:m-0 print:p-0 print:overflow-visible"
            scroll-region
          >
            <div class="app-canvas p-4 md:p-6 print:bg-white print:border-0 print:shadow-none print:rounded-none">
              <qr-record-button v-if="$page.props.qrRecord" :key="$page.props.qrRecord.type + $page.props.qrRecord.id" class="print:hidden" />
              <slot />
            </div>
            <app-footer />
            <print-animation-host />
          </div>
        </div>
      </div>
    </div>
    <install-pwa-banner />
    <update-available-banner />
    <connection-status-banner />
    <quick-access-menu />
    <wims-chat-launcher />
    <scroll-jump />
    <rauncher :show="rauncherOpen" @close="rauncherOpen = false" />
    <prayer-layer v-if="$page.props.prayer" />
    <gate-reveal :show="!!$page.props.flash.just_logged_in" />
    <security-reminder-popup />
  </div>
</template>

<script>
import { installScanListener } from '@/Core/scanListener';
import TopBar from '@/Shared/Top/Navbar.vue';
import AppFooter from '@/Shared/AppFooter.vue';
import PrintAnimationHost from '@/Shared/PrintAnimationHost.vue';
import FullMainMenu from '@/Shared/Sidebar/FullMainMenu.vue';
import MiniMainMenu from '@/Shared/Sidebar/MiniMainMenu.vue';
import InstallPwaBanner from '@/Shared/InstallPwaBanner.vue';
import UpdateAvailableBanner from '@/Shared/UpdateAvailableBanner.vue';
import ConnectionStatusBanner from '@/Shared/ConnectionStatusBanner.vue';
import OfflineShell from '@/Shared/OfflineShell.vue';
import Rauncher from '@/Shared/Rauncher/Rauncher.vue';
import PrayerLayer from '@/Shared/Prayer/PrayerLayer.vue';
import { syncPrayer } from '@/Shared/Prayer/prayerStore';
import { DEFAULT_SHORTCUT, matchesShortcut } from '@/Shared/Rauncher/shortcut';
import QuickAccessMenu from '@/Shared/QuickAccessMenu/QuickAccessMenu.vue';
import QrRecordButton from '@/Shared/Qr/QrRecordButton.vue';
import WimsChatLauncher from '@/Shared/WimsChat/WimsChatLauncher.vue';
import ScrollJump from '@/Shared/ScrollJump.vue';
import GateReveal from '@/Shared/GateReveal.vue';
import ImpersonationBanner from '@/Shared/Administration/ImpersonationBanner.vue';
import SimulationBadge from '@/Shared/SimulationBadge.vue';
import SecurityReminderPopup from '@/Shared/SecurityReminderPopup.vue';

// Module-level, not component state — every page currently embeds
// <admin-layout> directly in its own template rather than going through
// Inertia's persistent-layout mechanism, so this component fully unmounts
// and remounts on every single in-app navigation. A component-scoped timer
// would therefore get torn down and restarted on every click instead of
// running once per session; keeping it here (surviving every remount) is
// what actually fixes that, without needing to restructure all 223 pages.
let heartbeatTimer = null;
// Presence: we only report "I'm here" while the tab is visible AND the person touched the page in the last 3 minutes,
// so a forgotten open tab goes Idle instead of looking online all day.
let lastActivityAt = Date.now();
let lastPingAt = 0;
const PRESENCE_ACTIVE_MS = 3 * 60 * 1000;
const PRESENCE_PING_MS = 100 * 1000; // the server counts a person as online for 3 minutes after the last heartbeat
const sendPresence = () => {
  if (document.hidden || Date.now() - lastActivityAt > PRESENCE_ACTIVE_MS || Date.now() - lastPingAt < PRESENCE_PING_MS - 2000) {
    return;
  }
  lastPingAt = Date.now();
  axios.post(route('presence.heartbeat')).catch(() => {});
};

export default {
  props: ['title'],

  components: {
    PrintAnimationHost,
    TopBar,
    AppFooter,
    FullMainMenu,
    MiniMainMenu,
    InstallPwaBanner,
    UpdateAvailableBanner,
    ConnectionStatusBanner,
    OfflineShell,
    Rauncher,
    PrayerLayer,
    QuickAccessMenu,
    QrRecordButton,
    WimsChatLauncher,
    ScrollJump,
    GateReveal,
    ImpersonationBanner,
    SimulationBadge,
    SecurityReminderPopup,
  },

  // watch: {
  //   title: {
  //     immediate: true,
  //     handler(title) {
  //       document.title = (title ? title + ' • ' : '') + this.$settings.name;
  //     },
  //   },
  // },

  // created() {
  //   this.$root.$i18n.locale(this.$page.props.language);
  // },

  data() {
    return { show: false, rauncherOpen: false, sidebarHidden: false, swipeHint: false };
  },

  computed: {
    isMainDashboard() {
      return this.$page.component === 'Dashboard/Index';
    },
    readModeEnabled() {
      return this.$page.props.enabledFeatures?.read_mode !== false || this.$super;
    },
    // Offline read mode replaces the page and the side menu with the cached-data shell.
    offlineReadMode() {
      return this.$connection.status === 'offline' && this.readModeEnabled;
    },
  },

  watch: {
    show(open) {
      document.body.classList.toggle('overflow-hidden', open);
    },
    '$page.props.prayer': { immediate: true, handler(v) { syncPrayer(v); } },
  },

  mounted() {
    installScanListener();
    window.addEventListener('keydown', this.onRauncherShortcut);
    this.startMenuSwipe();
    window.addEventListener('wims:rauncher', this.toggleRauncher);
    this.stopDrawerAutoClose = this.$inertia.on('navigate', () => {
      this.show = false;
    });
    this.sidebarHidden = window.localStorage.getItem('wims_sidebar_hidden') === '1';

    if (!heartbeatTimer && this.$connection.status !== 'offline' && !this.$page.props.impersonation?.active) {
      const touch = () => {
        lastActivityAt = Date.now();
        sendPresence();
      };
      ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(e => window.addEventListener(e, touch, { passive: true }));
      document.addEventListener('visibilitychange', () => !document.hidden && touch());
      sendPresence();
      heartbeatTimer = setInterval(sendPresence, PRESENCE_PING_MS);
    }
  },
  beforeUnmount() {
    window.removeEventListener('keydown', this.onRauncherShortcut);
    window.removeEventListener('wims:rauncher', this.toggleRauncher);
    this.stopDrawerAutoClose?.();
    this.stopMenuSwipe?.();
    document.body.classList.remove('overflow-hidden');
    // heartbeatTimer is deliberately NOT cleared here — it's a module-level
    // singleton meant to persist across every Inertia navigation (see the
    // comment above its declaration), not a resource scoped to this one
    // AdminLayout mount.
  },

  methods: {
    // Swipe from the left edge to the right to open the menu, and swipe it back to the left to close it (phones and tablets, below the
    // 1024px desktop menu). The menu button stays. Ignored while typing, over charts / sliders / anything marked data-no-swipe, and
    // for mostly vertical moves, so scrolling is never disturbed.
    startMenuSwipe() {
      const EDGE = 36; // px from the left edge where an opening swipe may begin
      const FAR = 64; // px the finger must travel
      let t = null;
      const small = () => window.innerWidth < 1024;
      const blocked = el => !!(el && el.closest && el.closest('input, textarea, select, [contenteditable="true"], [data-no-swipe], .highcharts-container, .kanban, .vue-slider, input[type=range]'));
      const onStart = e => {
        if (!small() || e.touches.length !== 1 || [...document.querySelectorAll('[role="dialog"]')].some(d => d.getClientRects().length)) { t = null; return; }
        const p = e.touches[0];
        const inDrawer = !!(e.target.closest && e.target.closest('.drawer-panel-root, .drawer-backdrop-root'));
        if (this.show ? inDrawer : p.clientX <= EDGE) t = { x: p.clientX, y: p.clientY, open: !this.show, done: false, blocked: blocked(e.target) && !inDrawer };
        else t = null;
      };
      const onMove = e => {
        if (!t || t.done || t.blocked) return;
        const p = e.touches[0];
        const dx = p.clientX - t.x, dy = p.clientY - t.y;
        if (Math.abs(dy) > Math.abs(dx) * 0.7 && Math.abs(dy) > 12) { t = null; return; } // a scroll, not a swipe
        if (t.open && dx > FAR) { this.show = true; t.done = true; this.swipeHint = false; try { window.localStorage.setItem('wims_swipe_hint_done', '1'); } catch (er) { /* no storage */ } }
        else if (!t.open && dx < -FAR) { this.show = false; t.done = true; }
      };
      const onEnd = () => { t = null; };
      document.addEventListener('touchstart', onStart, { passive: true });
      document.addEventListener('touchmove', onMove, { passive: true });
      document.addEventListener('touchend', onEnd, { passive: true });
      document.addEventListener('touchcancel', onEnd, { passive: true });
      this.stopMenuSwipe = () => {
        document.removeEventListener('touchstart', onStart);
        document.removeEventListener('touchmove', onMove);
        document.removeEventListener('touchend', onEnd);
        document.removeEventListener('touchcancel', onEnd);
      };
      // a gentle hint for people who have not used the swipe yet: the edge handle shows for a few seconds after the page opens
      let seen = false;
      try { seen = window.localStorage.getItem('wims_swipe_hint_done') === '1'; } catch (er) { /* no storage */ }
      if (small() && !seen && 'ontouchstart' in window) {
        this.swipeHint = true;
        setTimeout(() => { this.swipeHint = false; }, 5000);
      }
    },
    toggleSidebar() {
      this.sidebarHidden = !this.sidebarHidden;
      window.localStorage.setItem('wims_sidebar_hidden', this.sidebarHidden ? '1' : '0');
    },
    toggleRauncher() {
      this.rauncherOpen = !this.rauncherOpen;
    },
    onRauncherShortcut(e) {
      const shortcut = this.$page.props.auth?.user?.extra_attributes?.launcher_shortcut || DEFAULT_SHORTCUT;
      if (matchesShortcut(e, shortcut)) {
        e.preventDefault();
        this.rauncherOpen = !this.rauncherOpen;
      }
    },
  },
};
</script>

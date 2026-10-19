<template>
  <div class="px-1 md:px-4">
    <!-- <a
      target="_blank"
      v-if="$page.props.demo"
      href="https://tecdiary.net/products/warehouse-inventory-management-solution-wims"
      class="
        flex
        items-center
        justify-center
        p-2
        bg-blue-600
        text-white
        hover:text-white
        hover:bg-blue-700
        rounded
        font-bold
        focus:outline-none
      "
    >
      <icons name="cart" class="lg:mr-2"></icons>
      <span class="hidden lg:inline"> Buy Now</span>
    </a> -->
    <Link
      :href="route('settings')"
      v-if="$page.props.auth.user && $page.props.auth.user.roles.find(r => r.name == 'Super Admin')"
      class="icon-live icon-live--violet inline-flex items-center justify-center p-2 bg-violet-50 text-violet-600 rounded font-bold lg-tip"
      data-tip="Settings"
      aria-label="Settings"
    >
      <icons name="cog"></icons>
    </Link>
    <button
      type="button"
      v-if="$can('use-construction-toolbox') && featureEnabled('construction_toolbox')"
      @click.stop.prevent="toolboxOpen = true"
      class="icon-live icon-live--blue hidden lg:inline-flex relative z-10 items-center justify-center min-w-[44px] min-h-[44px] p-2 bg-sky-50 text-sky-600 rounded font-bold focus:outline-none lg-tip"
      data-tip="Construction Toolbox (Ctrl+Shift+K)"
     aria-label="Construction Toolbox (Ctrl+Shift+K)">
      <icons name="calculator"></icons>
    </button>
    <construction-toolbox v-if="$can('use-construction-toolbox') && featureEnabled('construction_toolbox')" :show="toolboxOpen" @close="toolboxOpen = false" />
    <button
      type="button"
      v-if="$can('use-ai-assistant') && featureEnabled('ai_assistant')"
      @click.stop.prevent="ansOpen = true"
      class="icon-live icon-live--rai inline-flex relative z-10 items-center justify-center min-w-[44px] min-h-[44px] p-2 bg-indigo-50 text-indigo-600 rounded font-bold focus:outline-none lg-tip"
      data-tip="ARIS (Ctrl+Cmd/Win+R)"
     aria-label="ARIS (Ctrl+Cmd/Win+R)">
      <icons name="sparkles"></icons>
    </button>
    <ans-modal v-if="$can('use-ai-assistant') && featureEnabled('ai_assistant')" :show="ansOpen" @close="ansOpen = false" />
    <button
      type="button"
      @click.stop.prevent="openAlerts"
      @touchend.stop.prevent="openAlerts"
      class="icon-live icon-live--amber relative z-10 inline-flex items-center justify-center min-w-[44px] min-h-[44px] p-2 bg-amber-50 text-amber-600 rounded font-bold focus:outline-none touch-manipulation lg-tip"
      data-tip="Alerts and notifications"
      aria-label="Alerts and notifications"
    >
      <icons name="bell" :class="{ 'bell-ring-active': unreadCount > 0 }"></icons>
      <span
        v-if="unreadCount > 0"
        class="pointer-events-none absolute -top-1 -right-1 min-w-[1.1rem] h-[1.1rem] px-1 inline-flex items-center justify-center rounded-full bg-red-600 text-white text-[10px] leading-none badge-liquid-pulse"
      >
        {{ unreadCountLabel }}
      </span>
    </button>
    <alerts-modal :show="alerts" @close="alerts = false" @unread-count-change="localUnreadCount = $event" />
    <!-- <a
      href="#"
      class="inline-flex items-center justify-center p-2 bg-white text-gray-700 hover:text-white hover:bg-blue-700 rounded font-bold"
    >
      <icons name="menu"></icons>
    </a> -->
  </div>
</template>

<script>
import AlertsModal from './AlertsModal.vue';
import AnsModal from './AnsModal.vue';
import ConstructionToolbox from '../ConstructionToolbox/ConstructionToolbox.vue';

export default {
  components: { AlertsModal, AnsModal, ConstructionToolbox },

  data() {
    return {
      alerts: false,
      scaled: false,
      toolboxOpen: false,
      ansOpen: false,
      localUnreadCount: Number(this.$page.props.materialRequestUnreadCount || 0),
      previousUnreadCount: Number(this.$page.props.materialRequestUnreadCount || 0),
      pollTimer: null,
      audioContext: null,
    };
  },

  mounted() {
    window.addEventListener('keydown', this.onToolboxShortcut);
    window.addEventListener('keydown', this.onRaiShortcut);
    document.addEventListener('click', this.unlockAudio, { once: true });
    document.addEventListener('visibilitychange', this.onVisibilityChange);
    this.startPolling();
  },
  beforeUnmount() {
    window.removeEventListener('keydown', this.onToolboxShortcut);
    window.removeEventListener('keydown', this.onRaiShortcut);
    document.removeEventListener('click', this.unlockAudio);
    document.removeEventListener('visibilitychange', this.onVisibilityChange);
    this.stopPolling();
  },

  watch: {
    '$page.props.materialRequestUnreadCount'(value) {
      this.localUnreadCount = Number(value || 0);
    },

    unreadCount: {
      immediate: true,
      handler(count) {
        if (!('setAppBadge' in navigator)) {
          return;
        }

        if (count > 0) {
          navigator.setAppBadge(count).catch(() => {});
        } else {
          navigator.clearAppBadge().catch(() => {});
        }
      },
    },
  },

  computed: {
    unreadCount() {
      const count = Number(this.localUnreadCount || 0);
      return Number.isFinite(count) ? count : 0;
    },

    unreadCountLabel() {
      return this.unreadCount > 99 ? '99+' : String(this.unreadCount);
    },
  },

  methods: {
    featureEnabled(key) {
      return this.$page.props.enabledFeatures?.[key] !== false || this.$super;
    },

    openAlerts() {
      this.alerts = true;
    },

    startPolling() {
      this.pollTimer = setInterval(() => {
        this.pollAlerts();
      }, 20000);
    },

    stopPolling() {
      if (this.pollTimer) {
        clearInterval(this.pollTimer);
        this.pollTimer = null;
      }
    },

    onVisibilityChange() {
      if (document.visibilityState === 'visible') {
        this.pollAlerts();
      }
    },

    pollAlerts() {
      // A hidden tab or a dead connection has nothing to refresh: skip the request instead of queueing it.
      if (document.hidden || this.$connection.status === 'offline') {
        return;
      }
      axios
        .get('/alerts', { params: { light: 1 } })
        .then(res => {
          const count = Number(res.data?.unread_count ?? res.data?.notifications?.length ?? 0);

          if (count > this.previousUnreadCount) {
            this.playChime();
          }

          this.previousUnreadCount = count;
          this.localUnreadCount = count;
        })
        .catch(() => {});
    },

    unlockAudio() {
      if (this.audioContext) {
        return;
      }

      const Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) {
        return;
      }

      this.audioContext = new Ctx();
    },

    playChime() {
      if (this.$page.props.auth.user.chime_enabled === false) {
        return;
      }

      this.unlockAudio();

      const ctx = this.audioContext;
      if (!ctx) {
        return;
      }

      if (ctx.state === 'suspended') {
        ctx.resume().catch(() => {});
      }

      // A simple two-note ascending chime — no external audio file needed.
      const notes = [
        { freq: 880, start: 0, duration: 0.14 },
        { freq: 1318.5, start: 0.12, duration: 0.22 },
      ];

      notes.forEach(({ freq, start, duration }) => {
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();

        oscillator.type = 'sine';
        oscillator.frequency.value = freq;

        const startTime = ctx.currentTime + start;
        gain.gain.setValueAtTime(0, startTime);
        gain.gain.linearRampToValueAtTime(0.22, startTime + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.001, startTime + duration);

        oscillator.connect(gain);
        gain.connect(ctx.destination);

        oscillator.start(startTime);
        oscillator.stop(startTime + duration + 0.02);
      });
    },

    onToolboxShortcut(e) {
      if (
        e.ctrlKey &&
        e.shiftKey &&
        e.key.toLowerCase() === 'k' &&
        this.$can('use-construction-toolbox') &&
        this.featureEnabled('construction_toolbox') &&
        window.innerWidth >= 1024
      ) {
        e.preventDefault();
        this.toolboxOpen = !this.toolboxOpen;
      }
    },

    onRaiShortcut(e) {
      if (e.ctrlKey && e.metaKey && e.key.toLowerCase() === 'r' && this.$can('use-ai-assistant') && this.featureEnabled('ai_assistant')) {
        e.preventDefault();
        this.ansOpen = !this.ansOpen;
      }
    },
  },
};
</script>

<style scoped>
.icon-live {
  position: relative;
  isolation: isolate;
  animation: icon-live-breathe 4.5s ease-in-out infinite;
  transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.icon-live::before {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: inherit;
  z-index: -1;
  opacity: 0;
  transition: opacity 0.25s ease;
}
.icon-live:hover {
  transform: translateY(-2px) scale(1.1);
  animation-play-state: paused;
}
/* keep each icon's own colour on hover (no white-out) and lift it with a tinted shadow instead */
.icon-live:hover::before {
  opacity: 0;
}
.icon-live--violet { --glow: 124, 58, 237; }
.icon-live--blue { --glow: 14, 165, 233; }
.icon-live--rai { --glow: 79, 70, 229; }
.icon-live--amber { --glow: 245, 158, 11; }
.icon-live { box-shadow: 0 4px 10px -4px rgba(var(--glow, 15, 23, 42), 0.35); }
.icon-live:hover { box-shadow: 0 12px 22px -6px rgba(var(--glow, 15, 23, 42), 0.6), 0 3px 8px rgba(var(--glow, 15, 23, 42), 0.25); }
.icon-live:active {
  transform: scale(0.94);
}

.icon-live--violet::before {
  background: linear-gradient(135deg, #8b5cf6, #6366f1);
}
.icon-live--blue::before {
  background: linear-gradient(135deg, #38bdf8, #6366f1);
}
.icon-live--amber::before {
  background: linear-gradient(135deg, #f59e0b, #f472b6);
}
.icon-live--rai::before {
  background: linear-gradient(135deg, #6366f1, #d946ef);
}

@keyframes icon-live-breathe {
  0%,
  100% {
    transform: scale(1);
  }
  50% {
    transform: scale(1.07);
  }
}
</style>

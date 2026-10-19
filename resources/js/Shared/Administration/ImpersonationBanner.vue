<template>
  <div v-if="data" class="imp-banner print:hidden" role="alert">
    <div class="imp-banner-inner">
      <span class="imp-banner-dot"></span>
      <icons name="impersonate" class="w-4 h-4 flex-none" />
      <span class="imp-banner-text">
        <span class="imp-banner-title">{{ $t('IMPERSONATING') }} {{ data.target.name.toUpperCase() }}</span>
        <span class="imp-banner-sub">
          <template v-if="data.target.role">{{ $t(data.target.role) }} · </template>{{ $t('Reason') }}: {{ data.reason }}
          <template v-if="data.mode === 'read_only'"> · {{ $t('Read-Only') }}</template>
        </span>
      </span>
      <span class="imp-banner-timer">
        <icons name="clock" class="w-4 h-4 flex-none" />
        {{ remainingLabel }}
      </span>
      <button type="button" class="imp-banner-exit" :disabled="exiting" @click="exit">
        <icons name="out" class="w-3.5 h-3.5" />
        {{ exiting ? $t('Exiting…') : $t('EXIT IMPERSONATION') }}
      </button>
    </div>
  </div>
</template>

<script>
export default {
  data() {
    return {
      exiting: false,
      now: Date.now(),
      tick: null,
    };
  },

  computed: {
    data() {
      return this.$page.props.impersonation || null;
    },
    remainingLabel() {
      if (!this.data?.expires_at) return '';
      const remainingMs = new Date(this.data.expires_at).getTime() - this.now;
      if (remainingMs <= 0) return this.$t('Expiring…');
      const minutes = Math.floor(remainingMs / 60000);
      const seconds = Math.floor((remainingMs % 60000) / 1000);
      return `${minutes}:${String(seconds).padStart(2, '0')} ${this.$t('left')}`;
    },
  },

  mounted() {
    this.tick = setInterval(() => {
      this.now = Date.now();
    }, 1000);
  },

  beforeUnmount() {
    clearInterval(this.tick);
  },

  methods: {
    exit() {
      this.exiting = true;
      this.$inertia.post(this.route('impersonation.exit'), {}, {
        onFinish: () => {
          this.exiting = false;
        },
      });
    },
  },
};
</script>

<style scoped>
.imp-banner {
  position: sticky;
  top: 0;
  z-index: 1;
  background: linear-gradient(90deg, #7f1d1d, #500724, #7f1d1d);
  color: #fff;
  box-shadow: 0 2px 10px -2px rgba(80, 7, 36, 0.6);
}
.imp-banner::after {
  content: '';
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 2px;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.95), transparent);
  background-size: 40% 100%;
  background-repeat: no-repeat;
  animation: imp-glow-sweep 2.8s linear infinite;
  pointer-events: none;
}
@keyframes imp-glow-sweep {
  0% { background-position: -40% 0; }
  100% { background-position: 140% 0; }
}
.imp-banner-inner {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.5rem 1rem;
  max-width: 100%;
}
.imp-banner-dot {
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 9999px;
  background: #fff;
  flex: none;
  animation: imp-banner-pulse 1.6s ease-in-out infinite;
}
@keyframes imp-banner-pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.4; transform: scale(0.75); }
}
.imp-banner-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}
.imp-banner-title {
  font-size: 0.8rem;
  font-weight: 800;
  letter-spacing: 0.02em;
}
.imp-banner-sub {
  font-size: 0.68rem;
  opacity: 0.92;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.imp-banner-timer {
  display: none;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.92rem;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  background: rgba(255, 255, 255, 0.14);
  border: 1px solid rgba(255, 255, 255, 0.3);
  padding: 0.35rem 0.85rem;
  border-radius: 9999px;
  flex: none;
  animation: imp-timer-glow 2s ease-in-out infinite;
}
@keyframes imp-timer-glow {
  0%, 100% { box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.35); background: rgba(255, 255, 255, 0.14); }
  50% { box-shadow: 0 0 0 6px rgba(255, 255, 255, 0); background: rgba(255, 255, 255, 0.22); }
}
@media (min-width: 640px) {
  .imp-banner-timer { display: inline-flex; }
}
.imp-banner-exit {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  flex: none;
  padding: 0.35rem 0.7rem;
  border-radius: 9999px;
  background: #fff;
  color: #7f1d1d;
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.01em;
  transition: transform 0.15s ease;
}
.imp-banner-exit:hover {
  transform: translateY(-1px);
}
.imp-banner-exit:disabled {
  opacity: 0.7;
}
</style>

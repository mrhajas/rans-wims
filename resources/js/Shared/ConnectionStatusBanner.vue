<template>
  <transition name="connection-banner">
    <div v-if="visible" class="fixed inset-x-0 bottom-0 z-50 px-4 pb-4 print:hidden">
      <div class="mx-auto max-w-lg flex items-center gap-3 rounded-lg text-white shadow-lg px-4 py-3" :class="tone === 'warning' ? 'bg-amber-800' : 'bg-emerald-800'">
        <icons name="wifi" class="h-6 w-6 flex-shrink-0 opacity-90"></icons>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-semibold leading-tight">{{ title }}</p>
          <p class="text-xs leading-tight" :class="tone === 'warning' ? 'text-amber-200' : 'text-emerald-200'">{{ subtitle }}</p>
        </div>
      </div>
    </div>
  </transition>
</template>

<script>
export default {
  data() {
    return {
      visible: false,
      tone: 'warning',
      title: '',
      subtitle: '',
      hideTimer: null,
    };
  },

  mounted() {
    window.addEventListener('wims-connection-changed', this.onChange);
  },

  beforeUnmount() {
    window.removeEventListener('wims-connection-changed', this.onChange);
    clearTimeout(this.hideTimer);
  },

  methods: {
    onChange(event) {
      const to = event?.detail?.to;

      // Only real online<->offline transitions are worth interrupting the
      // user for — 'checking'/'degraded' are intermediate states, and this
      // only fires on an actual status change to begin with (see
      // resources/js/Core/offlineStatus.js), so steady-state offline never
      // re-triggers the toast.
      if (to === 'offline') {
        this.tone = 'warning';
        this.title = this.$t('WIMS is offline');
        this.subtitle = this.$t("You're in Read Mode. You can still view cached data, search items, and review projects.");
      } else if (to === 'online') {
        this.tone = 'success';
        this.title = this.$t('WIMS is back online');
        this.subtitle = this.$t('Your local WIMS data is being refreshed.');
      } else {
        return;
      }

      this.visible = true;
      clearTimeout(this.hideTimer);
      this.hideTimer = setTimeout(() => {
        this.visible = false;
      }, 4000);
    },
  },
};
</script>

<style scoped>
.connection-banner-enter-active,
.connection-banner-leave-active {
  transition: transform 0.2s ease-out, opacity 0.2s ease-out;
}
.connection-banner-enter-from,
.connection-banner-leave-to {
  transform: translateY(100%);
  opacity: 0;
}
</style>

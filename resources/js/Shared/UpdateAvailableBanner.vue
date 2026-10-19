<template>
  <transition name="update-banner">
    <div
      v-if="visible"
      class="fixed inset-x-0 bottom-0 z-50 px-4 pb-4 print:hidden"
    >
      <div class="mx-auto max-w-lg flex items-center gap-3 rounded-lg bg-sky-900 text-white shadow-lg px-4 py-3">
        <icons name="refresh" class="h-6 w-6 flex-shrink-0 opacity-90"></icons>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-semibold leading-tight">A new version of WIMS is available</p>
          <p class="text-xs text-sky-200 leading-tight">Refresh to load the latest version.</p>
        </div>
        <button
          type="button"
          @click="refresh"
          class="flex-shrink-0 rounded-md bg-white text-sky-900 text-sm font-semibold px-3 py-2 hover:bg-sky-100"
        >
          Refresh
        </button>
      </div>
    </div>
  </transition>
</template>

<script>
export default {
  data() {
    return {
      visible: false,
    };
  },

  mounted() {
    window.addEventListener('wims-pwa-update-available', this.onUpdateAvailable);
  },

  beforeUnmount() {
    window.removeEventListener('wims-pwa-update-available', this.onUpdateAvailable);
  },

  methods: {
    onUpdateAvailable() {
      this.visible = true;
    },

    refresh() {
      if (typeof window.__wimsApplyUpdate === 'function') {
        window.__wimsApplyUpdate(true);
      } else {
        window.location.reload();
      }
    },
  },
};
</script>

<style scoped>
.update-banner-enter-active,
.update-banner-leave-active {
  transition: transform 0.2s ease-out, opacity 0.2s ease-out;
}
.update-banner-enter-from,
.update-banner-leave-to {
  transform: translateY(100%);
  opacity: 0;
}
</style>

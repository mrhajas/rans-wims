<template>
  <transition name="install-banner">
    <div
      v-if="visible"
      class="fixed inset-x-0 bottom-0 z-40 px-4 pb-4 print:hidden"
    >
      <div class="mx-auto max-w-lg flex items-center gap-3 rounded-lg bg-slate-900 text-white shadow-lg px-4 py-3">
        <icons name="download" class="h-6 w-6 flex-shrink-0 opacity-90"></icons>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-semibold leading-tight">Install WIMS</p>
          <p class="text-xs text-slate-300 leading-tight">Add it to your home screen for quick, app-like access.</p>
        </div>
        <button
          type="button"
          @click="install"
          class="flex-shrink-0 rounded-md bg-white text-slate-900 text-sm font-semibold px-3 py-2 hover:bg-slate-100"
        >
          Install
        </button>
        <button
          type="button"
          @click="dismiss"
          aria-label="Dismiss"
          class="flex-shrink-0 text-slate-400 hover:text-white p-1"
        >
          &times;
        </button>
      </div>
    </div>
  </transition>
</template>

<script>
import { installApp, startPwaWatch } from '@/Core/pwaInstall';

const DISMISS_KEY = 'wims-pwa-install-dismissed';

export default {
  data() {
    return {
      visible: false,
      deferredPrompt: null,
    };
  },

  mounted() {
    startPwaWatch();
    if (this.isStandalone() || window.localStorage.getItem(DISMISS_KEY)) {
      return;
    }

    if (window.__wimsInstallPrompt) {
      this.deferredPrompt = window.__wimsInstallPrompt;
      this.visible = true;
    }

    window.addEventListener('wims-install-prompt-ready', this.onPromptReady);
    window.addEventListener('beforeinstallprompt', this.onBeforeInstallPrompt);
    window.addEventListener('appinstalled', this.onInstalled);
  },

  beforeUnmount() {
    window.removeEventListener('wims-install-prompt-ready', this.onPromptReady);
    window.removeEventListener('beforeinstallprompt', this.onBeforeInstallPrompt);
    window.removeEventListener('appinstalled', this.onInstalled);
  },

  methods: {
    isStandalone() {
      return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    },

    onBeforeInstallPrompt(event) {
      event.preventDefault();
      this.deferredPrompt = event;
      this.visible = true;
    },

    onPromptReady() {
      if (window.__wimsInstallPrompt) {
        this.deferredPrompt = window.__wimsInstallPrompt;
        this.visible = true;
      }
    },

    onInstalled() {
      this.visible = false;
      this.deferredPrompt = null;
      window.localStorage.setItem(DISMISS_KEY, '1');
    },

    async install() {
      if (!this.deferredPrompt) {
        return;
      }

      // the shared installer shows the browser's prompt and remembers the answer (the profile menu item reads the same state)
      await installApp();
      this.deferredPrompt = null;
      this.visible = false;
    },

    dismiss() {
      this.visible = false;
      window.localStorage.setItem(DISMISS_KEY, '1');
    },
  },
};
</script>

<style scoped>
.install-banner-enter-active,
.install-banner-leave-active {
  transition: transform 0.2s ease-out, opacity 0.2s ease-out;
}
.install-banner-enter-from,
.install-banner-leave-to {
  transform: translateY(100%);
  opacity: 0;
}
</style>

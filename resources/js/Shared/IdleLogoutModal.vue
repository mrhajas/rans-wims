<template>
  <div v-if="idleLogoutState.visible" class="idle-logout-overlay fixed inset-0 z-[999999] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/70 backdrop-blur-sm"></div>

    <div class="idle-logout-card relative w-full max-w-md rounded-2xl bg-white shadow-2xl ring-1 ring-black/5 overflow-hidden">
      <div class="bg-gradient-to-r from-amber-500 via-orange-500 to-red-500 px-6 py-5 flex items-center gap-3">
        <div class="flex-shrink-0 h-11 w-11 rounded-full bg-white/20 flex items-center justify-center">
          <icons name="exclamation" class="h-6 w-6 text-white" />
        </div>
        <div>
          <h2 class="text-white font-extrabold text-lg leading-tight">{{ $t('Session Expiring Soon') }}</h2>
          <p class="text-white/90 text-xs font-medium">{{ $t("You've been idle for a while") }}</p>
        </div>
      </div>

      <div class="px-6 py-6 text-center">
        <p class="text-gray-600 text-sm mb-5 leading-relaxed">
          {{ $t('For your security, your session will end automatically when the timer below reaches zero. Choose an option to continue.') }}
        </p>

        <div class="mx-auto mb-5 flex items-center justify-center">
          <div class="relative h-24 w-24">
            <svg class="h-24 w-24 -rotate-90" viewBox="0 0 100 100">
              <circle cx="50" cy="50" r="44" fill="none" stroke="#fee2e2" stroke-width="8" />
              <circle
                cx="50"
                cy="50"
                r="44"
                fill="none"
                stroke="#dc2626"
                stroke-width="8"
                stroke-linecap="round"
                :stroke-dasharray="circumference"
                :stroke-dashoffset="dashOffset"
                style="transition: stroke-dashoffset 1s linear"
              />
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
              <icons name="clock" class="h-4 w-4 text-red-500 mb-0.5" />
              <span class="text-2xl font-extrabold text-gray-800 leading-none">{{ idleLogoutState.secondsLeft }}</span>
            </div>
          </div>
        </div>

        <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
          <button
            type="button"
            @click="stayLoggedIn"
            class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-3 rounded-md font-semibold text-xs text-white uppercase tracking-widest bg-emerald-600 hover:bg-emerald-700 focus:outline-none focus:ring focus:ring-emerald-300 transition ease-in-out duration-150"
          >
            <icons name="refresh" class="h-4 w-4" />
            {{ $t('Stay Logged In') }}
          </button>
          <button
            type="button"
            @click="logoutNow"
            class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-3 rounded-md font-semibold text-xs text-red-700 uppercase tracking-widest bg-red-50 hover:bg-red-100 border border-red-200 focus:outline-none focus:ring focus:ring-red-300 transition ease-in-out duration-150"
          >
            <icons name="out" class="h-4 w-4" />
            {{ $t('Logout Now') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import Icons from '@/Shared/Icons.vue';
import { idleLogoutState, stayLoggedIn, logoutNow, COUNTDOWN_SECONDS } from '@/Core/idleLogout';

export default {
  components: { Icons },

  data() {
    return {
      idleLogoutState,
      circumference: 2 * Math.PI * 44,
    };
  },

  computed: {
    dashOffset() {
      const fraction = Math.max(0, Math.min(1, this.idleLogoutState.secondsLeft / COUNTDOWN_SECONDS));

      return this.circumference * (1 - fraction);
    },
  },

  methods: {
    stayLoggedIn,
    logoutNow,
  },
};
</script>

<style scoped>
.idle-logout-card {
  animation: idle-logout-pop 0.22s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes idle-logout-pop {
  from {
    opacity: 0;
    transform: scale(0.92) translateY(8px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}
</style>

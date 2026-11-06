<template>
  <!-- A Super Admin opening a switched-off module: the menu stays around them, with a notice window on top that says what is off and why -->
  <admin-layout v-if="isSuper" :title="$t('Module deactivated')">
    <div class="fu-stage">
      <div class="fu-modal" role="alertdialog" aria-modal="true" aria-labelledby="fu-title">
        <div class="fu-scene" aria-hidden="true">
          <span class="fu-ring"></span>
          <span class="fu-switch"><span class="fu-thumb"></span></span>
        </div>

        <p class="fu-eyebrow">{{ $t('Module deactivated') }}</p>
        <h1 id="fu-title" class="fu-title">{{ featureName || $t('This function') }}</h1>
        <p v-if="module" class="fu-module">{{ module }}</p>

        <p class="fu-text">
          <template v-if="heldBy">{{ $t('This function is held off because') }} <b>{{ heldBy.name }}</b> {{ $t('is switched off. Everything under it is off for everyone, Super Admins included.') }}</template>
          <template v-else>{{ $t('This module has been switched off in Global Feature Control. It is off for everyone, Super Admins included. No data has been deleted.') }}</template>
        </p>
        <p v-if="subCount && !heldBy" class="fu-note">{{ subCount }} {{ $t('sub-function(s) under it are held off with it.') }}</p>

        <div class="fu-actions">
          <button type="button" class="fu-btn fu-btn-on" :disabled="busy" @click="switchOn">
            <icons name="lock-open" class="w-4 h-4" />{{ busy ? $t('Switching on...') : (heldBy ? $t('Switch on') + ' ' + heldBy.name : $t('Switch it on now')) }}
          </button>
          <Link :href="route('system-features.index', { focus: heldBy ? heldBy.key : featureKey })" class="fu-btn fu-btn-soft"><icons name="toggle" class="w-4 h-4" />{{ $t('Open Feature Control') }}</Link>
          <Link :href="route('dashboard')" class="fu-btn fu-btn-ghost">{{ $t('Back to Dashboard') }}</Link>
        </div>
      </div>
    </div>
  </admin-layout>

  <div v-else class="fu-page min-h-screen w-full flex items-center justify-center bg-gray-100 px-4" :class="{ 'fu-glass': glassUi }">
    <div class="fu-card max-w-md w-full bg-white rounded-lg shadow-md overflow-hidden text-center">
      <div class="p-8">
        <div class="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-amber-100 text-amber-600 mb-4">
          <icons name="lock-closed" className="h-7 w-7" />
        </div>
        <h1 class="text-xl font-bold text-gray-900 mb-1">{{ $t('Feature Temporarily Unavailable') }}</h1>
        <p v-if="featureName" class="text-sm font-semibold text-gray-500 mb-2">{{ featureName }}</p>
        <p class="text-sm text-gray-500">{{ message }}</p>
        <p class="text-xs text-gray-400 mt-3">{{ $t('Please contact your administrator if you believe you should have access.') }}</p>
      </div>
      <div class="px-8 py-4 bg-gray-50 border-t">
        <Link :href="route('dashboard')" class="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 hover:text-blue-800">
          <icons name="chevron-down" className="h-3.5 w-3.5 rotate-90" />
          {{ $t('Back to Dashboard') }}
        </Link>
      </div>
    </div>
  </div>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link } from '@inertiajs/vue3';
import { glassUiOn } from '@/Core/glassUi';

export default {
  components: { AdminLayout, Link },
  props: {
    featureName: String,
    message: String,
    isSuper: { type: Boolean, default: false },
    module: String,
    featureKey: String,
    featureId: Number,
    ownSwitchOff: Boolean,
    heldBy: { type: Object, default: null },
    subCount: { type: Number, default: 0 },
    from: { type: String, default: null },
  },
  data() {
    return { glassUi: glassUiOn(), busy: false };
  },
  methods: {
    // turns on whatever is holding this function off (its own switch, or the function above it), then goes back to the page that was wanted
    switchOn() {
      const id = this.heldBy ? this.heldBy.id : this.featureId;
      if (!id || this.busy) return;
      this.busy = true;
      this.$inertia.put(route('system-features.update', id), { enabled: true }, {
        preserveScroll: true,
        onSuccess: () => { this.$inertia.visit(this.from || route('dashboard')); },
        onFinish: () => { this.busy = false; },
      });
    },
  },
};
</script>

<style scoped>
.fu-stage { position: relative; display: flex; align-items: flex-start; justify-content: center; min-height: 70vh; padding: 6vh 16px 40px; animation: fu-veil 0.4s ease backwards; }
.fu-stage::before { content: ''; position: absolute; inset: 0; border-radius: 24px; background: radial-gradient(60% 60% at 50% 30%, rgba(244, 63, 94, 0.1), transparent 70%), rgba(15, 23, 42, 0.04); pointer-events: none; }
.fu-modal { position: relative; width: min(480px, 100%); padding: 28px 26px 22px; text-align: center; border-radius: 28px; background: var(--lg-card, #fff); border: 1px solid var(--lg-line, #e2e8f0); box-shadow: 0 40px 70px -30px rgba(15, 23, 42, 0.55), 0 2px 6px rgba(15, 23, 42, 0.08); animation: fu-pop 0.5s cubic-bezier(0.2, 0.9, 0.25, 1.15) backwards; }
.fu-scene { position: relative; display: grid; place-items: center; width: 120px; height: 84px; margin: 0 auto 10px; }
.fu-ring { position: absolute; width: 86px; height: 86px; border-radius: 9999px; border: 2px solid rgba(244, 63, 94, 0.55); animation: fu-ring 1.1s ease-out 0.55s 1 both; }
.fu-switch { position: relative; width: 76px; height: 42px; border-radius: 9999px; background: #cbd5e1; box-shadow: inset 0 2px 5px rgba(0, 0, 0, 0.25); animation: fu-track 0.7s ease 0.15s both, fu-shake 0.4s ease 0.8s 1; }
.fu-thumb { position: absolute; top: 5px; left: 5px; width: 32px; height: 32px; border-radius: 9999px; background: #fff; box-shadow: 0 3px 8px rgba(0, 0, 0, 0.35); animation: fu-thumb 0.7s cubic-bezier(0.5, 0, 0.2, 1) 0.15s both; }
.fu-eyebrow { margin: 0; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.14em; text-transform: uppercase; color: #be123c; }
.fu-title { margin: 6px 0 0; font-size: 1.45rem; font-weight: 800; line-height: 1.2; color: var(--lg-ink, #0f172a); text-wrap: balance; }
.fu-module { display: inline-block; margin: 8px 0 0; padding: 2px 11px; border-radius: 9999px; font-size: 0.7rem; font-weight: 800; color: #475569; background: var(--lg-soft, #f1f5f9); box-shadow: inset 0 0 0 1px var(--lg-line, #e2e8f0); }
.fu-text { margin: 14px 0 0; font-size: 0.88rem; line-height: 1.55; color: var(--lg-mute, #475569); }
.fu-note { margin: 8px 0 0; font-size: 0.78rem; font-weight: 700; color: #b45309; }
.fu-actions { display: flex; flex-direction: column; gap: 8px; margin-top: 20px; }
.fu-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 42px; padding: 0 16px; border-radius: 14px; border: 1px solid transparent; font-size: 0.85rem; font-weight: 800; cursor: pointer; text-decoration: none; transition: transform 0.15s ease, box-shadow 0.15s ease; }
.fu-btn:hover { transform: translateY(-1px); }
.fu-btn:disabled { opacity: 0.6; cursor: wait; }
.fu-btn-on { color: #fff; background: linear-gradient(145deg, #10b981, #047857); box-shadow: 0 14px 22px -14px #047857; }
.fu-btn-soft { color: #1c3f66; background: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.28); }
.fu-btn-ghost { color: var(--lg-mute, #64748b); background: transparent; }

@keyframes fu-veil { from { opacity: 0; } }
@keyframes fu-pop { from { opacity: 0; transform: translateY(26px) scale(0.92); } }
@keyframes fu-track { from { background: #10b981; } to { background: #cbd5e1; } }
@keyframes fu-thumb { from { transform: translateX(34px); } to { transform: translateX(0); } }
@keyframes fu-shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-4px); } 75% { transform: translateX(4px); } }
@keyframes fu-ring { from { transform: scale(0.5); opacity: 0.9; } to { transform: scale(1.5); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .fu-stage, .fu-modal, .fu-ring, .fu-switch, .fu-thumb { animation: none !important; } }
</style>

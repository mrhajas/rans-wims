<template>
  <jetstream-modal :show="show" max-width="md" @close="close">
    <div class="sap-panel p-6">
      <div class="flex items-center gap-3 mb-4">
        <span class="sap-icon"><icons name="lock-closed" class="w-5 h-5" /></span>
        <div>
          <h3 class="font-bold text-gray-900 leading-tight">{{ title }}</h3>
          <p class="text-xs text-gray-500 mt-0.5">{{ $t('Super Admin verification required') }}</p>
        </div>
      </div>
      <p class="text-sm text-gray-600 mb-2">{{ content }}</p>
      <ul v-if="lines && lines.length" class="sap-lines"><li v-for="l in lines" :key="l">{{ l }}</li></ul>
      <!-- its own form, with the account name beside the password: the browser's saved-password helper then fills THESE two fields and never the page's search box behind the dialog -->
      <form autocomplete="on" @submit.prevent="confirm">
        <input type="text" name="username" class="sap-hidden" :value="username" autocomplete="username" tabindex="-1" aria-hidden="true" readonly />
        <label class="sap-label" for="sap-password">{{ $t('Your Super Admin password') }}</label>
        <input id="sap-password" ref="password" v-model="password" name="password" type="password" class="sap-input" :class="{ 'sap-input-error': shownError }" autocomplete="current-password" />
        <p v-if="shownError" class="text-xs text-rose-600 font-semibold mt-1.5" role="alert">{{ shownError }}</p>
        <div class="flex justify-end gap-3 mt-6">
          <button type="button" class="sap-plain" @click="close">{{ $t('Cancel') }}</button>
          <button type="submit" class="sap-go" :class="{ 'opacity-60': processing }" :disabled="processing"><icons name="lock-open" class="w-4 h-4" /> {{ processing ? $t('Working...') : confirmText || $t('Confirm') }}</button>
        </div>
      </form>
    </div>
  </jetstream-modal>
</template>

<script>
import JetstreamModal from '@/Jetstream/Modal.vue';

// Asks the Super Admin for their own password and hands it to the page, which sends it with the action: the SERVER checks it.
export default {
  components: { JetstreamModal },
  props: { show: Boolean, title: String, content: String, lines: Array, confirmText: String, processing: Boolean, error: String },
  emits: ['confirm', 'close'],
  data() { return { password: '', localError: '' }; },
  computed: {
    username() { const u = (this.$page.props.auth || {}).user || {}; return u.username || u.email || ''; },
    shownError() { return this.localError || this.error; },
  },
  watch: { password() { this.localError = ''; }, show(v) { if (v) { this.password = ''; this.localError = ''; this.$nextTick(() => this.$refs.password && this.$refs.password.focus()); } } },
  methods: {
    close() { this.password = ''; this.$emit('close'); },
    confirm() {
      if (!this.password) { this.localError = this.$t('Enter your password to continue.'); this.$refs.password && this.$refs.password.focus(); return; }
      this.$emit('confirm', this.password);
    },
  },
};
</script>

<style scoped>
.sap-panel { background: linear-gradient(165deg, #fff, #fff7ed); }
.sap-icon { display: inline-flex; align-items: center; justify-content: center; width: 2.4rem; height: 2.4rem; border-radius: 0.75rem; flex: none; background: linear-gradient(145deg, #fb923c, #c2410c); color: #fff; box-shadow: 0 6px 14px -6px rgba(194, 65, 12, 0.5); }
.sap-hidden { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; overflow: hidden; }
.sap-lines { margin: 0 0 12px; padding: 8px 12px 8px 28px; max-height: 150px; overflow: auto; list-style: disc; border-radius: 10px; background: rgba(251, 146, 60, 0.1); font-size: 0.8rem; color: #7c2d12; }
.sap-label { display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; color: #64748b; margin: 8px 0 0.4rem; }
.sap-input { width: 100%; border-radius: 0.6rem; border: 1.5px solid #e5e7eb; padding: 0.55rem 0.75rem; font-size: 0.9rem; background: #fff; }
.sap-input:focus { outline: none; border-color: #c2410c; box-shadow: 0 0 0 3px rgba(194, 65, 12, 0.12); } .sap-input-error { border-color: #fca5a5; }
.sap-plain { padding: 0.55rem 1.1rem; border-radius: 0.6rem; border: 1px solid #d1d5db; color: #374151; font-size: 0.85rem; font-weight: 600; }
.sap-go { display: inline-flex; align-items: center; gap: 6px; padding: 0.55rem 1.1rem; border-radius: 0.6rem; font-size: 0.85rem; font-weight: 700; color: #fff; background: linear-gradient(145deg, #fb923c, #c2410c); box-shadow: 0 6px 16px -6px rgba(194, 65, 12, 0.5); }
html.dark .sap-panel { background: linear-gradient(165deg, #0f172a, #2a1408); } html.dark .sap-input { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.15); color: #e2e8f0; } html.dark .sap-plain { border-color: rgba(255, 255, 255, 0.15); color: #e2e8f0; } html.dark .sap-lines { color: #fed7aa; }
</style>

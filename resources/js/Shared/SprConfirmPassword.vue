<template>
  <span>
    <span @click="open">
      <slot />
    </span>

    <jetstream-modal :show="showing" @close="close" max-width="sm">
      <div class="scp-panel p-6">
        <div class="flex items-center gap-3 mb-4">
          <span class="scp-icon"><icons name="lock-closed" class="w-5 h-5" /></span>
          <div>
            <h3 class="font-bold text-gray-900 leading-tight">{{ title }}</h3>
            <p class="text-xs text-gray-500 mt-0.5">{{ $t('Super Admin verification required') }}</p>
          </div>
        </div>

        <p class="text-sm text-gray-600 mb-4">{{ content }}</p>

        <label class="scp-field-label">{{ $t('Your Password') }}</label>
        <input
          ref="password"
          type="password"
          class="scp-input"
          :class="{ 'scp-input-error': error }"
          v-model="password"
          autocomplete="current-password"
          @keyup.enter="confirm"
        />
        <p v-if="error" class="text-xs text-rose-600 font-semibold mt-1.5">{{ error }}</p>

        <div class="flex justify-end gap-3 mt-6">
          <button type="button" class="scp-btn-plain" @click="close">{{ $t('Cancel') }}</button>
          <button type="button" class="scp-btn-confirm" :class="{ 'opacity-60': processing }" :disabled="processing" @click="confirm">
            <icons name="lock-open" class="w-4 h-4" /> {{ processing ? $t('Verifying...') : $t('Confirm') }}
          </button>
        </div>
      </div>
    </jetstream-modal>
  </span>
</template>

<script>
import JetstreamModal from '@/Jetstream/Modal.vue';

export default {
  components: { JetstreamModal },

  emits: ['confirmed'],

  props: {
    title: { type: String, default: 'Confirm Password' },
    content: { type: String, default: 'For your security, please confirm your password to continue.' },
  },

  data() {
    return {
      showing: false,
      password: '',
      error: '',
      processing: false,
    };
  },

  methods: {
    open() {
      this.showing = true;
      this.$nextTick(() => this.$refs.password && this.$refs.password.focus());
    },
    close() {
      this.showing = false;
      this.password = '';
      this.error = '';
      this.processing = false;
    },
    confirm() {
      if (!this.password) {
        this.error = this.$t('Please enter your password.');
        return;
      }
      this.processing = true;
      this.error = '';
      axios
        .post(route('password.confirm'), { password: this.password })
        .then(() => {
          this.processing = false;
          this.showing = false;
          this.password = '';
          this.$nextTick(() => this.$emit('confirmed'));
        })
        .catch(error => {
          this.processing = false;
          this.error = error.response?.data?.errors?.password?.[0] || this.$t('That password is incorrect.');
          this.$refs.password.focus();
        });
    },
  },
};
</script>

<style scoped>
.scp-panel {
  background: linear-gradient(165deg, #fff, #fdf2f8);
}
.scp-icon {
  display: inline-flex; align-items: center; justify-content: center; width: 2.4rem; height: 2.4rem; border-radius: 0.75rem; flex: none;
  background: linear-gradient(145deg, #f472b6, #be185d); color: #fff; box-shadow: 0 6px 14px -6px rgba(190, 24, 93, 0.5);
}
.scp-field-label { display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; color: #64748b; margin-bottom: 0.4rem; }
.scp-input {
  width: 100%; border-radius: 0.6rem; border: 1.5px solid #e5e7eb; padding: 0.55rem 0.75rem; font-size: 0.9rem;
  background: #fff; transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.scp-input:focus { outline: none; border-color: #be185d; box-shadow: 0 0 0 3px rgba(190, 24, 93, 0.12); }
.scp-input-error { border-color: #fca5a5; }
.scp-btn-plain { padding: 0.55rem 1.1rem; border-radius: 0.6rem; border: 1px solid #d1d5db; color: #374151; font-size: 0.85rem; font-weight: 600; transition: background 0.15s ease; }
.scp-btn-plain:hover { background: #f9fafb; }
.scp-btn-confirm {
  display: inline-flex; align-items: center; gap: 6px; padding: 0.55rem 1.1rem; border-radius: 0.6rem; font-size: 0.85rem; font-weight: 700;
  color: #fff; background: linear-gradient(145deg, #f472b6, #be185d); box-shadow: 0 6px 16px -6px rgba(190, 24, 93, 0.5); transition: transform 0.15s ease;
}
.scp-btn-confirm:hover { transform: translateY(-1px); }

html.dark .scp-panel { background: linear-gradient(165deg, #0f172a, #1e1024); }
html.dark .scp-input { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.15); color: #e2e8f0; }
html.dark .scp-btn-plain { border-color: rgba(255, 255, 255, 0.15); color: #e2e8f0; }
</style>

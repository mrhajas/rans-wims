<template>
  <span>
    <span @click="startConfirmingPassword">
      <slot />
    </span>

    <jet-modal :show="confirmingPassword" :closeable="true" max-width="md" :transparent="true" @close="closeModal">
      <div class="cp-card" @keydown.esc="closeModal">
        <button type="button" class="cp-x" :aria-label="$t('Close')" @click="closeModal"><svg viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg></button>

        <span class="cp-badge" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5c0 4.4-2.9 8.2-7 10-4.1-1.8-7-5.6-7-10V6l7-3z" /><rect x="9" y="10.5" width="6" height="4.5" rx="1" /><path d="M10.5 10.5V9a1.5 1.5 0 013 0v1.5" /></svg>
        </span>
        <h3 class="cp-title">{{ title === 'Confirm Password' ? $t('Confirm your password') : title }}</h3>
        <p class="cp-text">{{ content === 'For your security, please confirm your password to continue.' ? $t('For your security, please confirm your password to continue.') : content }}</p>

        <label class="cp-field" :class="{ 'is-bad': form.error }">
          <svg viewBox="0 0 20 20" fill="currentColor" class="cp-field__ico" aria-hidden="true"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd" /></svg>
          <input ref="password" :type="reveal ? 'text' : 'password'" v-model="form.password" :placeholder="$t('Password')" autocomplete="current-password" :aria-label="$t('Password')" :aria-invalid="!!form.error" @keyup.enter="confirmPassword" @input="form.error = ''" />
          <button type="button" class="cp-eye" :aria-label="reveal ? $t('Hide password') : $t('Show password')" @click="reveal = !reveal; $refs.password.focus()">
            <svg v-if="!reveal" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" /><circle cx="12" cy="12" r="3" /></svg>
            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18M10.6 5.1A9.8 9.8 0 0112 5c6.4 0 10 7 10 7a17 17 0 01-3.2 4M6.7 6.7C3.9 8.5 2 12 2 12s3.6 7 10 7a9.7 9.7 0 004.2-.9" /><path d="M9.9 9.9a3 3 0 004.2 4.2" /></svg>
          </button>
        </label>
        <p v-if="form.error" class="cp-err" role="alert"><svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 shrink-0" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" /></svg>{{ form.error }}</p>

        <div v-if="$slots.below" class="cp-below"><slot name="below" /></div>

        <div class="cp-actions">
          <button type="button" class="cp-btn cp-btn--ghost" @click="closeModal">{{ $t('Cancel') }}</button>
          <button type="button" class="cp-btn cp-btn--go" :disabled="form.processing || !form.password" @click="confirmPassword">
            <span v-if="form.processing" class="cp-spin" aria-hidden="true"></span>
            {{ form.processing ? $t('Checking...') : (button === 'Confirm' ? $t('Confirm') : button) }}
          </button>
        </div>
        <p class="cp-note">{{ $t('Your password is only checked, never stored or shown.') }}</p>
      </div>
    </jet-modal>
  </span>
</template>

<script>
import JetModal from './Modal.vue';

export default {
  emits: ['confirmed', 'cancelled'],

  props: {
    title: {
      default: 'Confirm Password',
    },
    content: {
      default: 'For your security, please confirm your password to continue.',
    },
    button: {
      default: 'Confirm',
    },
    // how recent a confirmation must be (seconds). Must match the `password.confirm:...,seconds` on the route being protected,
    // otherwise this box says "confirmed" while the server still asks. Left out = the site-wide window.
    seconds: {
      type: Number,
      default: null,
    },
  },

  components: {
    JetModal,
  },

  data() {
    return {
      confirmingPassword: false,
      reveal: false,
      form: {
        password: '',
        error: '',
        processing: false,
      },
    };
  },

  methods: {
    startConfirmingPassword() {
      axios.get(route('password.confirmation'), this.seconds ? { params: { seconds: this.seconds } } : undefined).then(response => {
        if (response.data.confirmed) {
          this.$emit('confirmed');
        } else {
          this.confirmingPassword = true;

          setTimeout(() => this.$refs.password && this.$refs.password.focus(), 250);
        }
      });
    },

    confirmPassword() {
      if (this.form.processing || !this.form.password) return;
      this.form.processing = true;
      this.form.error = '';

      axios
        .post(route('password.confirm'), {
          password: this.form.password,
        })
        .then(() => {
          this.form.processing = false;
          this.closeModal(true);
          this.$nextTick(() => this.$emit('confirmed'));
        })
        .catch(error => {
          this.form.processing = false;
          const st = error.response && error.response.status;
          this.form.error = st === 422
            ? (error.response.data?.errors?.password?.[0] || this.$t('That password is not right. Please try again.'))
            : st === 429 ? this.$t('Too many tries. Please wait a moment and try again.')
            : st === 419 ? this.$t('This page was open for a while. Please close this box and try again.')
            : this.$t('Could not check your password. Please try again.');
          this.form.password = '';
          this.$nextTick(() => this.$refs.password && this.$refs.password.focus());
        });
    },

    closeModal(done = false) {
      if (this.confirmingPassword && done !== true) this.$emit('cancelled');
      this.confirmingPassword = false;
      this.reveal = false;
      this.form.password = '';
      this.form.error = '';
    },
  },
};
</script>

<style>
.cp-card { position: relative; margin: 0 auto; padding: 1.7rem 1.5rem 1.2rem; max-width: 26rem; border-radius: 22px; text-align: center; color: #0f172a; background: linear-gradient(180deg, #ffffff, #f8fafc); box-shadow: 0 30px 70px -20px rgba(15, 23, 42, 0.55), 0 0 0 1px rgba(148, 163, 184, 0.25); }
.cp-x { position: absolute; top: 0.7rem; right: 0.7rem; display: grid; place-items: center; width: 32px; height: 32px; border: 0; border-radius: 50%; color: #64748b; background: transparent; cursor: pointer; transition: background 0.15s; } .cp-x:hover { background: #f1f5f9; color: #0f172a; }
.cp-badge { display: grid; place-items: center; width: 58px; height: 58px; margin: 0 auto 0.8rem; border-radius: 18px; color: #fff; background: linear-gradient(145deg, #3b82f6, #1e3a8a); box-shadow: 0 12px 22px -10px rgba(30, 58, 138, 0.8); } .cp-badge svg { width: 30px; height: 30px; }
.cp-title { margin: 0; font-size: 1.2rem; font-weight: 800; letter-spacing: -0.01em; }
.cp-text { margin: 0.35rem 0 1.1rem; font-size: 0.88rem; line-height: 1.5; color: #475569; }
.cp-field { display: flex; align-items: center; gap: 0.5rem; padding: 0 0.4rem 0 0.8rem; border-radius: 14px; background: #fff; box-shadow: inset 0 0 0 1.5px #cbd5e1; transition: box-shadow 0.2s; text-align: left; }
.cp-field:focus-within { box-shadow: inset 0 0 0 2px #3b82f6, 0 0 0 4px rgba(59, 130, 246, 0.18); } .cp-field.is-bad { box-shadow: inset 0 0 0 2px #f43f5e, 0 0 0 4px rgba(244, 63, 94, 0.14); animation: cp-shake 0.4s both; }
.cp-field__ico { flex: none; width: 18px; height: 18px; color: #94a3b8; } .cp-field:focus-within .cp-field__ico { color: #3b82f6; }
.cp-field input { flex: 1; min-width: 0; padding: 0.8rem 0.2rem; border: 0; outline: 0; box-shadow: none !important; background: transparent; font-size: 0.95rem; color: #0f172a; } .cp-field input:focus { box-shadow: none !important; }
.cp-eye { display: grid; place-items: center; flex: none; width: 36px; height: 36px; min-height: 36px !important; border: 0; border-radius: 10px; color: #64748b; background: transparent; cursor: pointer; } .cp-eye:hover { background: #f1f5f9; color: #0f172a; } .cp-eye svg { width: 20px; height: 20px; }
.cp-err { display: flex; align-items: center; justify-content: center; gap: 0.4rem; margin: 0.6rem 0 0; font-size: 0.82rem; font-weight: 700; color: #be123c; }
.cp-actions { display: flex; gap: 0.6rem; margin-top: 1.1rem; }
.cp-btn { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; min-height: 46px !important; padding: 0 1rem; border: 0; border-radius: 14px; font-size: 0.88rem; font-weight: 800; letter-spacing: 0.04em; cursor: pointer; transition: transform 0.15s, box-shadow 0.2s, opacity 0.2s; }
.cp-btn--ghost { color: #334155; background: #fff; box-shadow: inset 0 0 0 1.5px #cbd5e1; } .cp-btn--ghost:hover { background: #f1f5f9; }
.cp-btn--go { color: #fff; background: linear-gradient(135deg, #1e3a8a, #0f2540); box-shadow: 0 12px 22px -12px rgba(15, 37, 64, 0.9); } .cp-btn--go:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 16px 26px -12px rgba(15, 37, 64, 0.95); } .cp-btn--go:disabled { opacity: 0.5; cursor: not-allowed; box-shadow: none; }
.cp-spin { width: 16px; height: 16px; border-radius: 50%; border: 2.5px solid rgba(255, 255, 255, 0.35); border-top-color: #fff; animation: cp-rot 0.7s linear infinite; }
.cp-note { margin: 0.8rem 0 0; font-size: 0.72rem; color: #94a3b8; }
@keyframes cp-rot { to { transform: rotate(360deg); } } @keyframes cp-shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }
html.dark .cp-card { color: #e2e8f0; background: linear-gradient(180deg, #1e293b, #0f172a); box-shadow: 0 30px 70px -20px rgba(0, 0, 0, 0.8), 0 0 0 1px rgba(255, 255, 255, 0.1); } html.dark .cp-text { color: #94a3b8; } html.dark .cp-field { background: #0f172a; box-shadow: inset 0 0 0 1.5px #334155; } html.dark .cp-field input { color: #f1f5f9; } html.dark .cp-btn--ghost { color: #e2e8f0; background: transparent; box-shadow: inset 0 0 0 1.5px #475569; } html.dark .cp-btn--ghost:hover { background: rgba(255, 255, 255, 0.06); } html.dark .cp-x:hover, html.dark .cp-eye:hover { background: rgba(255, 255, 255, 0.08); color: #fff; }
@media (prefers-reduced-motion: reduce) { .cp-field.is-bad, .cp-spin { animation: none; } }
.cp-below { margin: 0.9rem 0 0; }
</style>

<template>
  <span>
    <span class="lau-trigger-wrap">
      <button type="button" class="lau-trigger" @click.stop="open">
        <icons name="impersonate" class="w-4 h-4" />
      </button>
      <span class="lau-trigger-tip">{{ $t('Login As User') }}</span>
    </span>

    <tec-dialog-modal :show="show" :closeable="!submitting" max-width="lg" @close="close">
      <template #title>
        <span class="lau-modal-title">
          <span class="lau-modal-title-icon"><icons name="impersonate" class="w-4 h-4" /></span>
          {{ $t('Login As') }} {{ user.name }}?
        </span>
      </template>

      <template #content>
        <div v-if="successUrl" class="lau-success-box">
          <icons name="tick" class="w-5 h-5 flex-none text-emerald-600" />
          <div class="min-w-0">
            <p class="font-semibold text-emerald-800">{{ $t('Password verified. Session started.') }}</p>
            <p class="text-emerald-700">{{ $t("Your browser blocked the automatic new tab. Open it manually:") }}</p>
            <a :href="successUrl" target="_blank" rel="noopener" class="lau-open-link" @click="show = false">
              <icons name="impersonate" class="w-3.5 h-3.5" /> {{ $t('Open impersonation session') }}
            </a>
          </div>
        </div>

        <form v-else @submit.prevent="submit">
          <div class="lau-info-box">
            <icons name="exclamation" class="w-4 h-4 flex-none mt-0.5 text-amber-600" />
            <div>
              <p>{{ $t('You will temporarily experience WIMS exactly as this user.') }}</p>
              <p>{{ $t('Your Super Admin session will remain active.') }}</p>
              <p>{{ $t('All activity during impersonation will be recorded.') }}</p>
            </div>
          </div>

          <div class="lau-reason-field">
            <auto-complete
              v-model="reason"
              :label="$t('Reason for impersonation')"
              :suggestions="reasonSuggestions"
              :freeform="true"
              :error="error"
              :placeholder="$t('Select or type a reason…')"
            />
          </div>

          <div class="lau-mode-row">
            <label class="lau-mode-option" :class="{ 'is-active': mode === 'full' }">
              <input type="radio" value="full" v-model="mode" class="lau-mode-radio" />
              <span>
                <span class="lau-mode-title">{{ $t('Full User Experience') }}</span>
                <span class="lau-mode-desc">{{ $t('Reproduce whatever this user can normally do.') }}</span>
              </span>
            </label>
            <label class="lau-mode-option" :class="{ 'is-active': mode === 'read_only' }">
              <input type="radio" value="read_only" v-model="mode" class="lau-mode-radio" />
              <span>
                <span class="lau-mode-title">{{ $t('Read-Only') }}</span>
                <span class="lau-mode-desc">{{ $t('View only: no data can be changed.') }}</span>
              </span>
            </label>
          </div>

          <div class="lau-password-field">
            <tec-label :value="$t('Confirm your password')" />
            <!-- Hidden username hint, in the SAME form as the password field —
                 without it, Chrome/Safari's autofill has nothing reliable to
                 pair the password with and has been seen reaching for an
                 unrelated visible text input elsewhere on the page (e.g. the
                 Users list's own search box) instead. -->
            <input
              type="text"
              :value="$page.props.auth.user.username"
              autocomplete="username"
              readonly
              tabindex="-1"
              class="lau-hidden-username"
            />
            <tec-input
              v-model="password"
              type="password"
              name="impersonation_super_admin_password"
              autocomplete="current-password"
              :placeholder="$t('Your own Super Admin password')"
              class="block w-full mt-1"
            />
            <tec-input-error v-if="passwordError" :message="passwordError" class="mt-1" />
          </div>
        </form>
      </template>

      <template #footer v-if="!successUrl">
        <button type="button" class="lau-cancel-btn" :disabled="submitting" @click="close">{{ $t('Cancel') }}</button>
        <button type="button" class="lau-continue-btn" :disabled="!canSubmit" @click="submit">
          <icons name="impersonate" class="w-4 h-4" />
          {{ submitting ? $t('Verifying…') : $t('Verify & Continue') }}
        </button>
      </template>
    </tec-dialog-modal>
  </span>
</template>

<script>
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import TecLabel from '@/Jetstream/Label.vue';
import TecInput from '@/Jetstream/Input.vue';
import TecInputError from '@/Jetstream/InputError.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';

export default {
  components: { TecDialogModal, TecLabel, TecInput, TecInputError, AutoComplete },

  props: {
    user: { type: Object, required: true },
  },

  data() {
    return {
      show: false,
      reason: '',
      mode: 'full',
      password: '',
      submitting: false,
      error: '',
      passwordError: '',
      successUrl: null,
      reasonSuggestions: [],
    };
  },

  computed: {
    canSubmit() {
      return !!this.reason.trim() && !!this.password && !this.submitting;
    },
  },

  methods: {
    open() {
      this.reason = '';
      this.mode = 'full';
      this.password = '';
      this.error = '';
      this.passwordError = '';
      this.successUrl = null;
      this.show = true;
      axios
        .get(this.route('impersonation.reasons'))
        .then(res => {
          this.reasonSuggestions = res.data.reasons || [];
        })
        .catch(() => {});
    },
    close() {
      if (this.submitting) return;
      this.show = false;
    },
    // The new tab is only ever opened here, in the success branch, after the
    // password has actually been verified server-side — nothing is opened
    // (and nothing flashes open-then-closed) on a wrong password or any
    // other failure. window.open() from inside this .then() callback is
    // still close enough to the original click for Chrome/Firefox's "sticky"
    // user-activation window to allow it on a fast local request; if a
    // browser blocks it anyway, window.open() returns null/a closed handle
    // and the fallback link below lets the Super Admin open it themselves.
    submit() {
      if (!this.canSubmit) {
        return;
      }
      this.error = '';
      this.passwordError = '';
      this.submitting = true;
      axios
        .post(this.route('users.impersonate', this.user.id), { reason: this.reason, mode: this.mode, password: this.password })
        .then(response => {
          this.submitting = false;
          this.password = '';
          const win = window.open(response.data.redirect_url, '_blank');
          if (win && !win.closed) {
            this.show = false;
          } else {
            this.successUrl = response.data.redirect_url;
          }
        })
        .catch(err => {
          this.submitting = false;
          this.password = '';
          const errors = err.response?.data?.errors || {};
          this.passwordError = errors.password?.[0] || '';
          this.error = errors.reason?.[0] || (!this.passwordError ? err.response?.data?.message || this.$t('Could not start impersonation.') : '');
        });
    },
  },
};
</script>

<style scoped>
.lau-trigger-wrap {
  position: relative;
  display: inline-flex;
}
.lau-trigger {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  box-sizing: border-box;
  width: 1.9rem;
  height: 1.9rem;
  min-width: 0 !important;
  min-height: 0 !important;
  padding: 0 !important;
  border-radius: 9999px;
  background: linear-gradient(145deg, #7f1d1d, #500724);
  color: #fff;
  box-shadow: 0 2px 6px -2px rgba(80, 7, 36, 0.6);
  transition: background 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease;
}
.lau-trigger:hover {
  background: linear-gradient(145deg, #991b1b, #7f1d1d);
  box-shadow: 0 6px 14px -4px rgba(80, 7, 36, 0.65);
  transform: translateY(-1px);
}
.lau-trigger :deep(svg) {
  flex-shrink: 0;
  width: 16px !important;
  height: 16px !important;
}

.lau-trigger-tip {
  position: absolute;
  left: 50%;
  bottom: calc(100% + 8px);
  transform: translateX(-50%) translateY(4px);
  white-space: nowrap;
  padding: 0.35rem 0.6rem;
  border-radius: 0.45rem;
  font-size: 0.68rem;
  font-weight: 600;
  background: #0f172a;
  color: #f1f5f9;
  box-shadow: 0 10px 24px -8px rgba(15, 23, 42, 0.45);
  opacity: 0;
  visibility: hidden;
  pointer-events: none;
  z-index: 30;
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.lau-trigger-tip::after {
  content: '';
  position: absolute;
  top: 100%;
  left: 50%;
  transform: translateX(-50%);
  border: 5px solid transparent;
  border-top-color: #0f172a;
}
.lau-trigger-wrap:hover .lau-trigger-tip {
  opacity: 1;
  visibility: visible;
  transform: translateX(-50%) translateY(0);
}

.lau-hidden-username {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

.lau-reason-field :deep(input) {
  border: 1px solid #cbd5e1 !important;
}
.lau-reason-field :deep(input:focus) {
  border-color: #f59e0b !important;
}

.lau-password-field {
  margin-top: 1rem;
}
.lau-password-field :deep(input) {
  border: 1px solid #cbd5e1 !important;
}
.lau-password-field :deep(input:focus) {
  border-color: #f59e0b !important;
}

.lau-modal-title {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  font-weight: 700;
}
.lau-modal-title-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border-radius: 0.6rem;
  background: linear-gradient(145deg, #fbbf24, #b45309);
  color: #fff;
  flex: none;
}
.lau-modal-title-icon :deep(svg) {
  flex-shrink: 0;
  width: 16px !important;
  height: 16px !important;
}

.lau-info-box {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  background: rgba(245, 158, 11, 0.08);
  border: 1px solid rgba(245, 158, 11, 0.25);
  border-radius: 0.65rem;
  padding: 0.65rem 0.8rem;
  margin-bottom: 1rem;
  font-size: 0.78rem;
  color: #92400e;
  line-height: 1.5;
}

.lau-success-box {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
  background: rgba(16, 185, 129, 0.08);
  border: 1px solid rgba(16, 185, 129, 0.3);
  border-radius: 0.65rem;
  padding: 0.9rem 1rem;
  font-size: 0.8rem;
  line-height: 1.5;
}
.lau-open-link {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  margin-top: 0.6rem;
  padding: 0.45rem 0.9rem;
  border-radius: 0.5rem;
  background: linear-gradient(145deg, #34d399, #047857);
  color: #fff;
  font-size: 0.78rem;
  font-weight: 700;
}

.lau-mode-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.6rem;
  margin-top: 1rem;
}
.lau-mode-option {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  border: 1px solid #e2e8f0;
  border-radius: 0.65rem;
  padding: 0.6rem 0.7rem;
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease;
}
.lau-mode-option.is-active {
  border-color: #f59e0b;
  background: rgba(245, 158, 11, 0.06);
}
.lau-mode-radio {
  margin-top: 0.2rem;
  accent-color: #d97706;
}
.lau-mode-title {
  display: block;
  font-size: 0.78rem;
  font-weight: 700;
  color: #1f2937;
}
.lau-mode-desc {
  display: block;
  font-size: 0.68rem;
  color: #6b7280;
  margin-top: 0.1rem;
}

.lau-cancel-btn {
  padding: 0.45rem 0.9rem;
  border-radius: 0.5rem;
  background: #e5e7eb;
  color: #374151;
  font-size: 0.8rem;
  font-weight: 600;
}
.lau-continue-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  margin-left: 0.5rem;
  padding: 0.45rem 1rem;
  border-radius: 0.5rem;
  background: linear-gradient(145deg, #fbbf24, #b45309);
  color: #fff;
  font-size: 0.8rem;
  font-weight: 700;
  box-shadow: 0 6px 16px -6px rgba(180, 83, 9, 0.5);
}
.lau-continue-btn :deep(svg) {
  flex-shrink: 0;
  width: 16px !important;
  height: 16px !important;
}
.lau-continue-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

html.dark .lau-mode-option { border-color: rgba(255, 255, 255, 0.1); }
html.dark .lau-mode-title { color: #e2e8f0; }
html.dark .lau-cancel-btn { background: rgba(255, 255, 255, 0.1); color: #e2e8f0; }
</style>

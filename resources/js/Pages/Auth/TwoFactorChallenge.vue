<template>
  <auth-shell>
    <template #form>
      <div class="mb-6">
        <img :src="loginLogoUrl" alt="RANS Logo" class="login-logo h-20 max-w-[242px] w-auto object-contain" @error="loginLogoFailed = true" />
        <h1 class="mt-4 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-[1.7rem]">{{ $t('Two-step verification') }}</h1>
        <p class="mt-2 text-sm text-slate-500">{{ $t('One more step to protect your account. Choose how you want to confirm it is you.') }}</p>
      </div>

      <!-- Method switcher -->
      <div v-if="tabs.length > 1" class="tfa-tabs mb-5" role="tablist">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          type="button"
          role="tab"
          :aria-selected="method === tab.key"
          class="tfa-tab"
          :class="{ 'tfa-tab-active': method === tab.key, 'tfa-tab-off': tab.available === false }"
          @click="selectMethod(tab.key)"
        >
          <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" v-html="tab.icon"></svg>
          <span>{{ tab.label }}</span>
        </button>
      </div>

      <form @submit.prevent="submit" novalidate>
        <!-- Explanation for the chosen method -->
        <div class="auth-note mb-4">
          <span class="auth-note-icon">
            <svg class="h-3.5 w-3.5 text-white" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" v-html="currentTab.icon"></svg>
          </span>
          <span>{{ helpText }}</span>
        </div>

        <!-- Email / SMS: send step -->
        <div v-if="isChannel && !codeSent" class="mb-5">
          <button type="button" class="auth-submit block w-full" :disabled="sending || currentTab.available === false" @click="sendCode">
            <span class="inline-flex items-center gap-2">
              <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z" /></svg>
              <span>{{ sending ? $t('Sending...') : sendLabel }}</span>
            </span>
          </button>
          <p v-if="sending" class="tfa-wait mt-3" role="status"><span class="tfa-spin" aria-hidden="true"></span>{{ $t('Sending the code. This can take up to 30 seconds, please wait.') }}</p>
          <p v-if="currentTab.available === false" class="mt-2 text-xs font-semibold text-amber-700">{{ $t('SMS codes are not available right now. Please choose another method.') }}</p>
        </div>

        <!-- Code entry: app + sent email/SMS -->
        <div v-if="method === 'recovery'">
          <label for="recovery_code">{{ $t('Recovery code') }}</label>
          <div class="relative mt-1">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
              <svg class="auth-field-icon h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 8a6 6 0 01-7.743 5.743L10 14l-1 1-1 1H6v2H2v-4l4.257-4.257A6 6 0 1118 8zm-6-4a1 1 0 100 2 2 2 0 012 2 1 1 0 102 0 4 4 0 00-4-4z" clip-rule="evenodd" /></svg>
            </span>
            <input
              id="recovery_code"
              ref="recovery"
              v-model="form.recovery_code"
              type="text"
              autocomplete="one-time-code"
              spellcheck="false"
              class="auth-input tfa-recovery block w-full pl-10 pr-3"
              :placeholder="$t('Enter one of your emergency recovery codes')"
            />
          </div>
        </div>

        <div v-else>
          <label>{{ $t('6-digit code') }}</label>
          <div class="tfa-boxes mt-1.5" :class="{ 'tfa-boxes-idle': isChannel && !codeSent }" @paste.prevent="onPaste">
            <input
              v-for="(d, i) in digits"
              :key="i"
              :ref="el => (boxes[i] = el)"
              :value="d"
              type="text"
              inputmode="numeric"
              pattern="[0-9]*"
              maxlength="1"
              :disabled="isChannel && !codeSent"
              :autocomplete="i === 0 ? 'one-time-code' : 'off'"
              :aria-label="$t('Digit') + ' ' + (i + 1)"
              class="tfa-box"
              :class="{ 'tfa-box-filled': d !== '', 'tfa-box-error': !!errorText }"
              @input="onInput(i, $event)"
              @keydown="onKeydown(i, $event)"
              @focus="$event.target.select()"
            />
          </div>

          <p v-if="isChannel && !codeSent" class="tfa-idle-hint mt-2">{{ $t('The boxes open as soon as the code has been sent.') }}</p>

          <!-- Sent-code status + resend -->
          <div v-if="isChannel && codeSent" class="tfa-sent mt-3">
            <span class="tfa-sent-dot"></span>
            <span class="min-w-0 flex-1">
              {{ $t('Code sent to') }} <strong>{{ sentMasked }}</strong>
              <template v-if="expiresIn > 0"> &middot; {{ $t('expires in') }} {{ clock(expiresIn) }}</template>
              <template v-else> &middot; {{ $t('expired') }}</template>
            </span>
            <button type="button" class="tfa-resend" :disabled="cooldown > 0 || sending" @click="sendCode">
              {{ cooldown > 0 ? $t('Resend in') + ' ' + clock(cooldown) : $t('Resend code') }}
            </button>
          </div>
        </div>

        <p v-if="errorText" class="tfa-error mt-3" role="alert">
          <svg class="h-4 w-4 flex-none" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>
          <span>{{ errorText }}</span>
        </p>

        <div class="mt-6 w-full">
          <loading-button
            type="submit"
            class="auth-submit block w-full"
            :loading="form.processing"
            :disabled="form.processing || !canSubmit || (isChannel && !codeSent)"
          >
            <span class="inline-flex items-center gap-2">
              <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
              <span>{{ $t('Verify & Sign In') }}</span>
            </span>
          </loading-button>

          <div class="tfa-links">
            <button v-if="hasRecovery && method !== 'recovery'" type="button" class="tfa-link" @click="selectMethod('recovery')">
              {{ $t('Use a recovery code') }}
            </button>
            <button v-else-if="method === 'recovery' && tabs.length > 1" type="button" class="tfa-link" @click="selectMethod(primaryMethod)">
              {{ $t('Use a verification code instead') }}
            </button>
          </div>

          <Link :href="route('login')" class="auth-back">{{ $t('Back to Login') }}</Link>

          <p class="mt-3 text-center text-xs font-semibold tracking-wide text-slate-500">{{ $page.props.settings.footer_credit || 'Developed by Extrion' }}</p>
        </div>
      </form>
    </template>

    <template #aside-head>
      <p class="auth-art-badge">{{ $t('Account Security') }}</p>
      <h2 class="auth-art-title">{{ $t('Two-Step Verification') }}</h2>
      <p class="auth-art-text">{{ $t('A second check, on top of your password, keeps your WIMS account safe even if the password is ever exposed.') }}</p>
    </template>

    <template #aside-items>
      <div class="auth-row">
        <span class="auth-row-icon"><svg class="h-[18px] w-[18px]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M2.94 6.34A2 2 0 014.73 5h10.54a2 2 0 011.79 1.34L10 10.6 2.94 6.34z" /><path d="M18 8.28l-7.45 4.5a1 1 0 01-1.1 0L2 8.28V14a2 2 0 002 2h12a2 2 0 002-2V8.28z" /></svg></span>
        <span>{{ $t('Codes sent by email or SMS work once and expire in a few minutes.') }}</span>
      </div>
      <div class="auth-row">
        <span class="auth-row-icon"><svg class="h-[18px] w-[18px]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd" /></svg></span>
        <span>{{ $t('Never share a verification code with anyone, including WIMS staff.') }}</span>
      </div>
      <div class="auth-row">
        <span class="auth-row-icon"><svg class="h-[18px] w-[18px]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" /></svg></span>
        <span>{{ $t('Lost your phone or inbox? Use a recovery code, or ask your administrator to reset two-step verification.') }}</span>
      </div>
    </template>
  </auth-shell>
</template>

<script>
import guestKeepalive from '@/Core/guestKeepalive';
import { Link } from '@inertiajs/vue3';
import LoadingButton from '@/Shared/LoadingButton.vue';
import AuthShell from '@/Shared/Auth/AuthShell.vue';

const ICONS = {
  app: '<path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />',
  email: '<path d="M2.94 6.34A2 2 0 014.73 5h10.54a2 2 0 011.79 1.34L10 10.6 2.94 6.34z" /><path d="M18 8.28l-7.45 4.5a1 1 0 01-1.1 0L2 8.28V14a2 2 0 002 2h12a2 2 0 002-2V8.28z" />',
  sms: '<path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd" />',
  recovery: '<path fill-rule="evenodd" d="M18 8a6 6 0 01-7.743 5.743L10 14l-1 1-1 1H6v2H2v-4l4.257-4.257A6 6 0 1118 8zm-6-4a1 1 0 100 2 2 2 0 012 2 1 1 0 102 0 4 4 0 00-4-4z" clip-rule="evenodd" />',
};

export default {
  mixins: [guestKeepalive([])],

  components: { AuthShell, LoadingButton, Link },

  props: {
    methods: { type: Array, default: () => [] },
    hasRecovery: { type: Boolean, default: false },
    initialMethod: { type: String, default: 'app' },
    otp: { type: Object, default: null },
    otpTtlMinutes: { type: Number, default: 5 },
    autoSend: { type: Boolean, default: false },
  },

  data() {
    return {
      method: this.initialMethod,
      digits: ['', '', '', '', '', ''],
      boxes: [],
      form: this.$inertia.form({ method: this.initialMethod, code: '', recovery_code: '' }),
      sendForm: this.$inertia.form({ channel: '' }),
      sending: false,
      cooldown: this.otp ? this.otp.cooldown : 0,
      expiresIn: this.otp ? this.otp.expires_in : 0,
      timer: null,
      autoTimer: null,
      loginLogoFailed: false,
    };
  },

  computed: {
    loginLogoUrl() {
      return this.loginLogoFailed ? '/images/logo.png' : '/images/login-logo.png';
    },
    tabs() {
      const list = this.methods.map(m => ({
        key: m.key,
        available: m.available,
        masked: m.masked,
        icon: ICONS[m.key],
        label: m.key === 'app' ? this.$t('Authenticator app') : m.key === 'email' ? this.$t('Email') : this.$t('SMS'),
      }));
      if (this.hasRecovery) {
        list.push({ key: 'recovery', available: true, masked: null, icon: ICONS.recovery, label: this.$t('Recovery code') });
      }
      return list;
    },
    currentTab() {
      return this.tabs.find(t => t.key === this.method) || this.tabs[0] || { key: 'app', icon: ICONS.app };
    },
    // The non-recovery tab to fall back to from the recovery view.
    primaryMethod() {
      const t = this.tabs.find(x => x.key !== 'recovery');
      return t ? t.key : 'app';
    },
    isChannel() {
      return this.method === 'email' || this.method === 'sms';
    },
    codeSent() {
      return this.isChannel && !!this.otp && this.otp.channel === this.method;
    },
    sentMasked() {
      return this.otp ? this.otp.masked : '';
    },
    sendLabel() {
      return this.method === 'sms' ? this.$t('Send code by SMS') : this.$t('Send code by email');
    },
    helpText() {
      if (this.method === 'app') return this.$t('Open your authenticator app and enter the 6-digit code it shows for WIMS.');
      if (this.method === 'recovery') return this.$t('Enter one of the emergency recovery codes you saved when you set up two-step verification. Each code works once.');
      const dest = this.currentTab.masked ? ' ' + this.currentTab.masked : '';
      return (this.method === 'sms' ? this.$t('We will text a 6-digit code to') : this.$t('We will email a 6-digit code to')) + dest + '.';
    },
    code() {
      return this.digits.join('');
    },
    canSubmit() {
      return this.method === 'recovery' ? this.form.recovery_code.trim().length > 0 : this.code.length === 6;
    },
    errorText() {
      const e = this.form.errors || {};
      const s = this.sendForm.errors || {};
      return e.code || e.recovery_code || s.code || s.channel || null;
    },
  },

  watch: {
    // The server re-renders the page after a (re)send: pick up the fresh countdowns.
    otp: {
      handler(val) {
        this.cooldown = val ? val.cooldown : 0;
        this.expiresIn = val ? val.expires_in : 0;
        if (val && val.channel === this.method) this.$nextTick(() => this.focusBox(0));
      },
    },
  },

  mounted() {
    this.timer = setInterval(() => {
      if (this.cooldown > 0) this.cooldown -= 1;
      if (this.expiresIn > 0) this.expiresIn -= 1;
    }, 1000);
    this.$nextTick(() => (this.method === 'recovery' ? this.$refs.recovery?.focus() : this.focusBox(0)));
    // someone whose only second step is one e-mail / SMS code: the page opens at once and the code is sent from here (sending from the server
    // while the page loads kept the page blank for as long as the mail server took)
    if (this.autoSend && this.isChannel && !this.codeSent) this.sendCode();
  },

  beforeUnmount() {
    clearInterval(this.timer);
    clearTimeout(this.autoTimer);
  },

  methods: {
    clock(seconds) {
      const s = Math.max(0, Math.floor(seconds));
      return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    },

    selectMethod(key) {
      if (this.method === key) return;
      this.method = key;
      this.digits = ['', '', '', '', '', ''];
      this.form.clearErrors();
      this.form.recovery_code = '';
      this.sendForm.clearErrors();
      this.$nextTick(() => (key === 'recovery' ? this.$refs.recovery?.focus() : this.focusBox(0)));
    },

    async sendCode() {
      if (this.sending || !this.isChannel) return;
      this.sending = true;
      await this.$guestKeepalive.ensureFresh(0);
      this.form.clearErrors();
      this.sendForm.channel = this.method;
      this.sendForm.post(this.route('two-factor.otp.send'), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
          this.digits = ['', '', '', '', '', ''];
          this.$nextTick(() => this.focusBox(0));
        },
        onFinish: () => (this.sending = false),
      });
    },

    focusBox(i) {
      const el = this.boxes[i];
      if (el) el.focus();
    },

    onInput(i, event) {
      const digit = event.target.value.replace(/\D/g, '').slice(-1);
      this.digits[i] = digit;
      event.target.value = digit;
      if (this.errorText) this.form.clearErrors();
      if (digit && i < 5) this.focusBox(i + 1);
      this.maybeAutoSubmit();
    },

    onKeydown(i, event) {
      if (event.key === 'Backspace' && !this.digits[i] && i > 0) {
        this.digits[i - 1] = '';
        this.focusBox(i - 1);
        event.preventDefault();
      } else if (event.key === 'ArrowLeft' && i > 0) {
        this.focusBox(i - 1);
        event.preventDefault();
      } else if (event.key === 'ArrowRight' && i < 5) {
        this.focusBox(i + 1);
        event.preventDefault();
      }
    },

    onPaste(event) {
      const text = ((event.clipboardData || window.clipboardData).getData('text') || '').replace(/\D/g, '').slice(0, 6);
      if (!text) return;
      this.digits = text.split('').concat(['', '', '', '', '', '']).slice(0, 6);
      this.$nextTick(() => this.focusBox(Math.min(text.length, 5)));
      this.maybeAutoSubmit();
    },

    // Submit by itself once all six digits are in (typed or pasted) — one try per completed code.
    maybeAutoSubmit() {
      clearTimeout(this.autoTimer);
      if (this.code.length === 6 && !this.form.processing) {
        this.autoTimer = setTimeout(() => this.submit(), 150);
      }
    },

    async submit() {
      if (!this.canSubmit || this.form.processing) return;
      await this.$guestKeepalive.ensureFresh(0);
      this.form.method = this.method;
      this.form.code = this.method === 'recovery' ? '' : this.code;
      if (this.method !== 'recovery') this.form.recovery_code = '';
      this.form.post(this.route('two-factor.login'), {
        preserveScroll: true,
        onError: () => {
          if (this.method !== 'recovery') {
            this.digits = ['', '', '', '', '', ''];
            this.$nextTick(() => this.focusBox(0));
          }
        },
      });
    },
  },
};
</script>

<style>
/* Page-specific bits; everything else comes from AuthShell's shared .auth-* look. */
.auth-shell .tfa-tabs { display: flex; flex-wrap: wrap; gap: 6px; padding: 5px; border-radius: 14px; background: rgba(255, 255, 255, 0.7); border: 1px solid #e7ebf3; box-shadow: inset 0 1px 2px rgba(16, 24, 40, 0.04); }
.auth-shell .tfa-tab {
  flex: 1 1 auto; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  padding: 8px 10px; border-radius: 10px; border: 1px solid transparent; background: transparent;
  font-size: 0.74rem; font-weight: 700; color: #64748b; white-space: nowrap; cursor: pointer;
  transition: background 0.18s ease, color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
}
.auth-shell .tfa-tab:hover { color: #1e3a8a; background: rgba(255, 255, 255, 0.9); }
.auth-shell .tfa-tab-active, .auth-shell .tfa-tab-active:hover {
  color: #fff; background: linear-gradient(145deg, #7aa2ff, #4f6df5);
  box-shadow: 0 8px 14px -8px rgba(63, 99, 240, 0.95), inset 0 1px 0 rgba(255, 255, 255, 0.35);
}
.auth-shell .tfa-tab-off { opacity: 0.55; }

.auth-shell .tfa-wait { display: flex; align-items: center; gap: 10px; font-size: 0.8rem; font-weight: 600; color: #475569; }
.auth-shell .tfa-spin { flex: none; width: 16px; height: 16px; border-radius: 9999px; border: 2.5px solid rgba(99, 102, 241, 0.25); border-top-color: #4f46e5; animation: tfa-rot 0.8s linear infinite; }
@keyframes tfa-rot { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .auth-shell .tfa-spin { animation: none; } }
.auth-shell .tfa-boxes-idle { opacity: 0.55; }
.auth-shell .tfa-boxes-idle .tfa-box { background: rgba(148, 163, 184, 0.14); border-style: dashed; cursor: not-allowed; }
.auth-shell .tfa-idle-hint { font-size: 0.76rem; font-weight: 600; color: #64748b; }
.auth-shell .tfa-boxes { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 8px; }
.auth-shell .tfa-box {
  width: 100%; height: 54px; text-align: center; font-size: 1.35rem; font-weight: 800; color: #0f172a;
  font-variant-numeric: tabular-nums; caret-color: #4f6df5;
  border: 1px solid #e1e5ec; border-radius: 12px; background: #fff; box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
  padding: 0; transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease, background 0.18s ease;
}
.auth-shell .tfa-box:hover { border-color: #c9d2e3; }
/* Glowing outline: every square softly pulses (staggered left to right), the active one glows brighter. */
.auth-shell .tfa-box { border-color: #9db2ff; animation: tfa-glow 2.6s ease-in-out infinite; }
.auth-shell .tfa-box:nth-child(2) { animation-delay: 0.18s; }
.auth-shell .tfa-box:nth-child(3) { animation-delay: 0.36s; }
.auth-shell .tfa-box:nth-child(4) { animation-delay: 0.54s; }
.auth-shell .tfa-box:nth-child(5) { animation-delay: 0.72s; }
.auth-shell .tfa-box:nth-child(6) { animation-delay: 0.9s; }
@keyframes tfa-glow {
  0%, 100% { box-shadow: 0 0 0 1px rgba(111, 141, 255, 0.35), 0 0 6px 0 rgba(111, 141, 255, 0.35); }
  50% { box-shadow: 0 0 0 1px rgba(111, 141, 255, 0.75), 0 0 16px 3px rgba(99, 132, 255, 0.5); }
}
.auth-shell .tfa-box:focus { outline: none; border-color: #4f6df5; animation: none; box-shadow: 0 0 0 2px rgba(79, 109, 245, 0.9), 0 0 22px 5px rgba(79, 109, 245, 0.55); transform: translateY(-1px); }
.auth-shell .tfa-box-filled { border-color: #6f8dff; background: #f6f8ff; }
.auth-shell .tfa-box-error { border-color: #f4a6a6; background: #fff7f7; animation: tfa-shake 0.35s ease; }
@keyframes tfa-shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-4px); } 75% { transform: translateX(4px); } }

.auth-shell .tfa-recovery { letter-spacing: 0.06em; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }

.auth-shell .tfa-sent {
  display: flex; flex-wrap: wrap; align-items: center; gap: 6px 8px; padding: 8px 10px; border-radius: 12px;
  background: #f1f5ff; border: 1px solid #dbe5ff; font-size: 0.74rem; color: #334155;
}
.auth-shell .tfa-sent-dot { flex: none; width: 8px; height: 8px; border-radius: 9999px; background: #22c55e; box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2); }
.auth-shell .tfa-resend {
  margin-left: auto; flex: none; padding: 4px 10px; border-radius: 9px; border: 0; background: transparent;
  font-size: 0.74rem; font-weight: 800; color: #3d5af1; cursor: pointer; transition: background 0.15s ease, color 0.15s ease;
}
.auth-shell .tfa-resend:hover:not(:disabled) { background: #fff; color: #1e3a8a; }
.auth-shell .tfa-resend:disabled { color: #94a3b8; cursor: default; }

.auth-shell .tfa-error { display: flex; align-items: flex-start; gap: 8px; padding: 9px 12px; border-radius: 12px; background: #fff5f5; border: 1px solid #fbd0d0; font-size: 0.78rem; font-weight: 600; color: #b42318; }

.auth-shell .tfa-links { display: flex; justify-content: center; margin-top: 12px; }
.auth-shell .tfa-link { border: 0; background: transparent; font-size: 0.8rem; font-weight: 700; color: #3d5af1; cursor: pointer; padding: 4px 8px; border-radius: 8px; transition: color 0.15s ease, background 0.15s ease; }
.auth-shell .tfa-link:hover { color: #1e3a8a; background: rgba(255, 255, 255, 0.8); }

@media (max-width: 380px) {
  .auth-shell .tfa-boxes { gap: 5px; }
  .auth-shell .tfa-box { height: 48px; font-size: 1.15rem; }
}
@media (prefers-reduced-motion: reduce) {
  .auth-shell .tfa-box-error, .auth-shell .tfa-box { animation: none; }
}
</style>

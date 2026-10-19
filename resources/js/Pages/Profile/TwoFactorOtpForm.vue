<template>
  <section v-if="info" class="lg-card">
    <header class="lg-card-head">
      <span class="lg-card-ico lg-ico-sky"><icons name="sms" class="w-5 h-5" /></span>
      <div class="min-w-0">
        <h2 class="lg-card-title">{{ $t('Email & SMS Verification Codes') }}</h2>
        <p class="lg-card-sub">{{ $t('Receive a one-time code by email or text message as your second step when signing in. You choose which method to use at login.') }}</p>
      </div>
    </header>

    <p class="pf-lead">
      {{ $t('Codes are 6 digits, work once and expire after') }} {{ info.ttl_minutes }} {{ $t('minutes') }}.
      {{ $t('Email codes and SMS codes can both be on, and you pick one each time you sign in. They replace the authenticator app: you can use one or the other, not both.') }}
    </p>

    <div v-if="info.authenticator" class="lg-note lg-note-amber mt-4">
      <icons name="exclamation" class="w-4 h-4" />
      <span class="font-semibold">{{ $t('The authenticator app is turned on for your account. You can use either the authenticator app or Email/SMS codes, not both. Disable the authenticator app above first to use Email or SMS codes.') }}</span>
    </div>

    <div class="mt-4 space-y-3">
      <div v-for="ch in channels" :key="ch.key" class="otp-card">
        <div class="flex items-start gap-3 otp-row">
          <span class="otp-icon" :class="ch.state.enabled ? 'otp-icon-on' : ''">
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" v-html="ch.icon"></svg>
          </span>

          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2 flex-wrap">
              <h3 class="pf-title">{{ ch.title }}</h3>
              <span class="lg-chip" :class="ch.state.enabled ? 'lg-chip-green' : 'lg-chip-slate'">{{ ch.state.enabled ? $t('On') : $t('Off') }}</span>
            </div>
            <p class="pf-lead mt-0.5">
              <template v-if="ch.state.enabled">{{ $t('Codes are sent to') }} <strong class="otp-strong">{{ ch.state.masked }}</strong></template>
              <template v-else-if="ch.key === 'email'">{{ $t('We will email the code to') }} <strong class="otp-strong">{{ ch.state.masked }}</strong></template>
              <template v-else-if="ch.state.available">{{ $t('We will text the code to a mobile number you confirm.') }}</template>
              <template v-else>{{ $t('SMS is not enabled on this system yet. Ask your administrator to turn on SMS in WIMS Connect.') }}</template>
            </p>

            <!-- Turning on: confirm the address/number with a code -->
            <div v-if="draft[ch.key].open" class="otp-panel mt-3">
              <div v-if="ch.key === 'sms'" class="mb-3">
                <label class="pf-lbl" :for="'otp-phone-' + ch.key">{{ $t('Mobile number') }}</label>
                <input
                  :id="'otp-phone-' + ch.key"
                  v-model="draft.sms.phone"
                  type="tel"
                  inputmode="tel"
                  autocomplete="tel"
                  :disabled="draft.sms.sent"
                  class="otp-input"
                  placeholder="0712 345 678 or +255 712 345 678"
                />
              </div>

              <div v-if="draft[ch.key].sent">
                <label class="pf-lbl" :for="'otp-code-' + ch.key">{{ $t('Enter the 6-digit code we sent to') }} {{ draft[ch.key].masked }}</label>
                <input
                  :id="'otp-code-' + ch.key"
                  v-model="draft[ch.key].code"
                  type="text"
                  inputmode="numeric"
                  maxlength="6"
                  autocomplete="one-time-code"
                  class="otp-input otp-input-code"
                  placeholder="••••••"
                  @keyup.enter="verify(ch.key)"
                />
              </div>

              <p v-if="draft[ch.key].error" class="pf-err">{{ draft[ch.key].error }}</p>

              <div class="mt-3 flex items-center gap-2 flex-wrap">
                <template v-if="!draft[ch.key].sent">
                  <tec-confirms-password @confirmed="send(ch.key)">
                    <button type="button" class="lg-btn lg-btn-primary" :disabled="draft[ch.key].busy"><icons name="send" class="w-4 h-4" />{{ $t('Send code') }}</button>
                  </tec-confirms-password>
                </template>
                <template v-else>
                  <button type="button" class="lg-btn lg-btn-green" :disabled="draft[ch.key].busy || draft[ch.key].code.length < 6" @click="verify(ch.key)">
                    <icons name="tick" class="w-4 h-4" />{{ $t('Verify & turn on') }}
                  </button>
                  <tec-confirms-password @confirmed="send(ch.key)">
                    <button type="button" class="lg-btn lg-btn-soft" :disabled="draft[ch.key].cooldown > 0 || draft[ch.key].busy">
                      {{ draft[ch.key].cooldown > 0 ? $t('Resend in') + ' ' + draft[ch.key].cooldown + 's' : $t('Resend code') }}
                    </button>
                  </tec-confirms-password>
                </template>
                <button type="button" class="lg-btn lg-btn-soft" @click="cancel(ch.key)">{{ $t('Cancel') }}</button>
              </div>
            </div>
          </div>

          <div class="flex-none">
            <template v-if="ch.state.enabled">
              <tec-confirms-password @confirmed="disable(ch.key)">
                <button type="button" class="lg-btn lg-btn-red" :disabled="draft[ch.key].busy">{{ $t('Turn off') }}</button>
              </tec-confirms-password>
            </template>
            <template v-else-if="!draft[ch.key].open">
              <button type="button" class="lg-btn lg-btn-primary" :disabled="turnOnBlocked(ch)" @click="open(ch.key)">
                {{ $t('Turn on') }}
              </button>
            </template>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script>
import TecConfirmsPassword from '@/Jetstream/ConfirmsPassword.vue';

const blank = () => ({ open: false, sent: false, busy: false, code: '', error: '', masked: '', cooldown: 0, phone: '' });

export default {
  components: { TecConfirmsPassword },

  data() {
    return {
      draft: { email: blank(), sms: { ...blank(), phone: (this.$page.props.twoFactorOtp && this.$page.props.twoFactorOtp.sms.suggested_phone) || '' } },
      timer: null,
    };
  },

  computed: {
    info() {
      return this.$page.props.twoFactorOtp;
    },
    channels() {
      return [
        {
          key: 'email',
          title: this.$t('Email code'),
          state: this.info.email,
          icon: '<path d="M2.94 6.34A2 2 0 014.73 5h10.54a2 2 0 011.79 1.34L10 10.6 2.94 6.34z" /><path d="M18 8.28l-7.45 4.5a1 1 0 01-1.1 0L2 8.28V14a2 2 0 002 2h12a2 2 0 002-2V8.28z" />',
        },
        {
          key: 'sms',
          title: this.$t('SMS code'),
          state: this.info.sms,
          icon: '<path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd" />',
        },
      ];
    },
  },

  mounted() {
    this.timer = setInterval(() => {
      ['email', 'sms'].forEach(k => {
        if (this.draft[k].cooldown > 0) this.draft[k].cooldown -= 1;
      });
    }, 1000);
  },

  beforeUnmount() {
    clearInterval(this.timer);
  },

  methods: {
    turnOnBlocked(ch) {
      return this.info.authenticator || (ch.key === 'sms' && !ch.state.available);
    },

    message(error, fallback) {
      const errors = error?.response?.data?.errors;
      if (errors) return Object.values(errors).flat()[0] || fallback;
      return error?.response?.data?.message || fallback;
    },

    open(key) {
      this.draft[key].open = true;
      this.draft[key].error = '';
    },

    cancel(key) {
      const phone = this.draft[key].phone;
      this.draft[key] = { ...blank(), phone };
    },

    send(key) {
      const d = this.draft[key];
      d.busy = true;
      d.error = '';
      axios
        .post('/user/two-factor-otp/send', { channel: key, phone: key === 'sms' ? d.phone : undefined })
        .then(({ data }) => {
          d.open = true;
          d.sent = true;
          d.code = '';
          d.masked = data.masked;
          d.cooldown = data.cooldown || 45;
        })
        .catch(e => (d.error = this.message(e, this.$t('We could not send the code. Please try again.'))))
        .finally(() => (d.busy = false));
    },

    verify(key) {
      const d = this.draft[key];
      if (d.busy || d.code.length < 6) return;
      d.busy = true;
      d.error = '';
      axios
        .post('/user/two-factor-otp/enable', { channel: key, code: d.code })
        .then(() => {
          this.cancel(key);
          this.$inertia.reload({ only: ['twoFactorOtp'], preserveScroll: true });
        })
        .catch(e => {
          d.error = this.message(e, this.$t('That code is incorrect or has expired. Request a new code.'));
          d.code = '';
        })
        .finally(() => (d.busy = false));
    },

    disable(key) {
      const d = this.draft[key];
      d.busy = true;
      axios
        .delete('/user/two-factor-otp/' + key)
        .then(() => this.$inertia.reload({ only: ['twoFactorOtp'], preserveScroll: true }))
        .finally(() => (d.busy = false));
    },
  },
};
</script>

<style scoped>
.otp-card { border: 1px solid var(--lg-line); border-radius: 16px; padding: 14px 16px; background: var(--lg-soft); }
.otp-icon { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 40px; height: 40px; border-radius: 12px; background: var(--lg-card); border: 1px solid var(--lg-line); color: var(--lg-mute); }
.otp-icon-on { background: linear-gradient(145deg, #34d399, #047857); border-color: transparent; color: #fff; box-shadow: 0 8px 14px -8px rgba(4, 120, 87, 0.9); }
.otp-strong { color: var(--lg-ink); }
.otp-panel { padding: 12px; border-radius: 14px; background: var(--lg-card); border: 1px solid var(--lg-line); }
.otp-input { width: 100%; max-width: 20rem; height: 42px; padding: 0 12px; border: 1px solid var(--lg-line); border-radius: 10px; background: var(--lg-card); font-size: 0.9rem; color: var(--lg-ink); transition: border-color 0.15s ease, box-shadow 0.15s ease; }
.otp-input:focus { outline: none; border-color: #6f8dff; box-shadow: 0 0 0 4px rgba(111, 141, 255, 0.2); }
.otp-input:disabled { opacity: 0.65; }
.otp-input-code { max-width: 11rem; text-align: center; letter-spacing: 0.35em; font-size: 1.1rem; font-weight: 800; font-variant-numeric: tabular-nums; }
@media (max-width: 560px) {
  .otp-row { flex-wrap: wrap; }
  .otp-row > .flex-none:last-child { width: 100%; }
}
</style>

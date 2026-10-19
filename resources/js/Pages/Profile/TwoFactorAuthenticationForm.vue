<template>
  <section class="lg-card">
    <header class="lg-card-head">
      <span class="lg-card-ico lg-ico-emerald"><icons name="otp" class="w-5 h-5" /></span>
      <div class="min-w-0">
        <h2 class="lg-card-title">{{ $t('Two Factor Authentication') }}</h2>
        <p class="lg-card-sub">{{ $t('Add additional security to your account using two factor authentication.') }}</p>
      </div>
      <span v-if="twoFactorEnabled" class="lg-chip lg-chip-green pf-head-chip"><icons name="tick" class="w-3 h-3" />{{ $t('On') }}</span>
      <span v-else-if="otpActive" class="lg-chip lg-chip-amber pf-head-chip">{{ $t('Email & SMS codes') }}</span>
      <span v-else class="lg-chip lg-chip-slate pf-head-chip">{{ $t('Off') }}</span>
    </header>

    <div class="pf-box tfa-status" :class="twoFactorEnabled ? 'tfa-on' : otpActive ? 'tfa-other' : 'tfa-off'">
      <span class="tfa-status-ico"><icons :name="twoFactorEnabled ? 'tick' : otpActive ? 'info' : 'exclamation'" class="w-4 h-4" /></span>
      <div class="min-w-0">
        <h3 class="pf-title" v-if="twoFactorEnabled">{{ $t('You have enabled two factor authentication.') }}</h3>

        <h3 class="pf-title" v-else-if="otpActive">{{ $t('Your second step is set to Email/SMS codes, not the authenticator app.') }}</h3>

        <h3 class="pf-title" v-else>{{ $t('You have not enabled two factor authentication.') }}</h3>

        <p class="pf-lead mt-1">
          {{
            $t(
              "When two factor authentication is enabled, you will be prompted for a secure, random token during authentication. You may retrieve this token from your phone's Google Authenticator application."
            )
          }}
        </p>
      </div>
    </div>

    <div v-if="twoFactorEnabled">
      <div v-if="qrCode" class="mt-4">
        <p class="pf-title">
          {{ $t("Two factor authentication is enabled. Scan the following QR code using your phone's authenticator application.") }}
        </p>

        <div class="tfa-qr mt-3" v-html="qrCode"></div>
      </div>

      <div v-if="recoveryCodes.length > 0" class="mt-4">
        <p class="pf-title">
          {{
            $t(
              'Store these recovery codes in a secure password manager. They can be used to recover access to your account if your two factor authentication device is lost.'
            )
          }}
        </p>

        <div class="tfa-codes mt-3">
          <div v-for="code in recoveryCodes" :key="code" class="tfa-code">
            {{ code }}
          </div>
        </div>
      </div>
    </div>

    <div v-if="!twoFactorEnabled && otpActive" class="lg-note lg-note-amber mt-4">
      <icons name="exclamation" class="w-4 h-4" />
      <span class="font-semibold">{{ $t('Email/SMS codes are turned on for your account. You can use either the authenticator app or Email/SMS codes, not both. Turn off Email and SMS codes below first to use the authenticator app.') }}</span>
    </div>
    <p v-if="$page.props.errors && $page.props.errors.two_factor" class="pf-err">{{ $page.props.errors.two_factor }}</p>

    <div class="pf-foot" style="justify-content: flex-start">
      <div v-if="!twoFactorEnabled">
        <tec-confirms-password @confirmed="enableTwoFactorAuthentication">
          <button type="button" class="lg-btn lg-btn-green" :disabled="enabling || otpActive"><icons name="otp" class="w-4 h-4" />{{ $t('Enable') }}</button>
        </tec-confirms-password>
      </div>

      <div v-else class="flex items-center gap-2 flex-wrap">
        <tec-confirms-password @confirmed="showQrCode">
          <button v-if="!qrCode" type="button" class="lg-btn lg-btn-soft"><icons name="view" class="w-4 h-4" />{{ $t('Show QRCode') }}</button>
        </tec-confirms-password>

        <tec-confirms-password @confirmed="showRecoveryCodes">
          <button v-if="!recoveryCodes.length" type="button" class="lg-btn lg-btn-soft"><icons name="key" class="w-4 h-4" />{{ $t('Show Recovery Codes') }}</button>
        </tec-confirms-password>

        <tec-confirms-password @confirmed="regenerateRecoveryCodes">
          <button v-if="recoveryCodes.length > 0" type="button" class="lg-btn lg-btn-soft"><icons name="refresh" class="w-4 h-4" />{{ $t('Regenerate Recovery Codes') }}</button>
        </tec-confirms-password>

        <tec-confirms-password @confirmed="showRecoveryCodes">
          <button v-if="recoveryCodes.data && recoveryCodes.data.length === 0" type="button" class="lg-btn lg-btn-soft">{{ $t('Show Recovery Codes') }}</button>
        </tec-confirms-password>

        <tec-confirms-password @confirmed="disableTwoFactorAuthentication">
          <button type="button" class="lg-btn lg-btn-red" :disabled="disabling"><icons name="lock-open" class="w-4 h-4" />{{ $t('Disable') }}</button>
        </tec-confirms-password>
      </div>
    </div>
  </section>
</template>

<script>
import TecConfirmsPassword from '@/Jetstream/ConfirmsPassword.vue';

export default {
  components: {
    TecConfirmsPassword,
  },

  data() {
    return {
      enabling: false,
      disabling: false,

      qrCode: null,
      recoveryCodes: [],
    };
  },

  methods: {
    enableTwoFactorAuthentication() {
      this.enabling = true;

      this.$inertia.post(
        '/user/two-factor-authentication',
        {},
        {
          preserveScroll: true,
          onSuccess: () => Promise.all([this.showQrCode(), this.showRecoveryCodes()]),
          onFinish: () => (this.enabling = false),
        }
      );
    },

    showQrCode() {
      return axios.get('/user/two-factor-qr-code').then(response => {
        this.qrCode = response.data.svg;
      });
    },

    showRecoveryCodes() {
      return axios.get('/user/two-factor-recovery-codes').then(response => {
        this.recoveryCodes = response.data;
      });
    },

    regenerateRecoveryCodes() {
      axios.post('/user/two-factor-recovery-codes').then(response => {
        this.showRecoveryCodes();
      });
    },

    disableTwoFactorAuthentication() {
      this.disabling = true;

      this.$inertia.delete('/user/two-factor-authentication', {
        preserveScroll: true,
        onSuccess: () => (this.disabling = false),
      });
    },
  },

  computed: {
    // Email or SMS codes are on — the authenticator app can't be started until they are turned off.
    otpActive() {
      const o = this.$page.props.twoFactorOtp;
      return !!o && (o.email.enabled || o.sms.enabled);
    },
    twoFactorEnabled() {
      return !this.enabling && this.$page.props.auth.user.two_factor_enabled;
    },
  },
};
</script>

<style scoped>
.tfa-status { display: flex; align-items: flex-start; gap: 12px; }
.tfa-status-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 30px; height: 30px; border-radius: 10px; color: #fff; background: #64748b; }
.tfa-on .tfa-status-ico { background: linear-gradient(145deg, #34d399, #047857); }
.tfa-other .tfa-status-ico { background: linear-gradient(145deg, #fbbf24, #b45309); }
.tfa-off .tfa-status-ico { background: linear-gradient(145deg, #fb7185, #be123c); }
.tfa-qr { display: inline-block; padding: 12px; border-radius: 16px; background: #fff; border: 1px solid var(--lg-line); }
.tfa-codes { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(150px, 100%), 1fr)); gap: 8px; max-width: 36rem; }
.tfa-code { padding: 8px 12px; border-radius: 10px; font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8rem; font-weight: 700; text-align: center; color: var(--lg-ink); background: var(--lg-soft); border: 1px dashed var(--lg-line); }
</style>

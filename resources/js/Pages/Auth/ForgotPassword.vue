<template>
  <auth-shell>
    <template #form>
      <div class="mb-7">
            <img :src="loginLogoUrl" alt="RANS Logo" class="h-20 max-w-[242px] w-auto object-contain" @error="loginLogoFailed = true" />
            <h1 class="mt-4 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-[1.7rem]">Reset your password</h1>
            <p class="mt-2 text-sm text-slate-500">
              {{
                $t(
                  'Forgot your password? No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.'
                )
              }}
            </p>
          </div>

      <div v-if="status" class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700">
            {{ status }}
          </div>

          <tec-validation-errors class="mb-4" />

          <div class="auth-note mb-4">
            <span class="auth-note-icon"><svg class="h-3.5 w-3.5 text-white" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
              <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.2-9.6a1 1 0 10-1.4-1.4L9 9.8 8.2 9a1 1 0 10-1.4 1.4l1.5 1.5a1 1 0 001.4 0l3.5-3.5z" clip-rule="evenodd" />
            </svg></span>
            <span>We will send a secure reset link to the email associated with your account.</span>
          </div>

          <form @submit.prevent="submit">
            <div>
              <tec-label for="email" :value="$t('Email')" />
              <div class="relative mt-1">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                  <svg class="auth-field-icon h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M2.94 6.34A2 2 0 014.73 5h10.54a2 2 0 011.79 1.34L10 10.6 2.94 6.34z" />
                    <path d="M18 8.28l-7.45 4.5a1 1 0 01-1.1 0L2 8.28V14a2 2 0 002 2h12a2 2 0 002-2V8.28z" />
                  </svg>
                </span>
                <tec-input
                  id="email"
                  type="email"
                  class="auth-input block w-full pl-10"
                  v-model="form.email"
                  required
                  autofocus
                />
              </div>
            </div>

            <div class="mt-6 w-full">
              <loading-button type="submit" class="auth-submit block w-full" :loading="form.processing" :disabled="form.processing">
                <span class="inline-flex items-center gap-2">
                  <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M3 10a1 1 0 011-1h8.59L10.3 6.7a1 1 0 111.4-1.4l4 4a1 1 0 010 1.4l-4 4a1 1 0 01-1.4-1.4L12.59 11H4a1 1 0 01-1-1z" clip-rule="evenodd" />
                  </svg>
                  <span>{{ $t('Email Password Reset Link') }}</span>
                </span>
              </loading-button>

              <Link
                :href="route('login')"
                class="auth-back"
              >
                {{ $t('Back to Login') }}
              </Link>

              <p class="mt-3 text-center text-xs font-semibold tracking-wide text-slate-500">{{ $page.props.settings.footer_credit || 'Developed by Extrion' }}</p>
            </div>
          </form>
    </template>

    <template #aside-head>
      <p class="auth-art-badge">Password Recovery</p>
      <h2 class="auth-art-title">Quick Account Recovery</h2>
      <p class="auth-art-text">
        Request a reset link and recover account access quickly with secure email verification.
      </p>
    </template>

    <template #aside-items>
      <div class="auth-row">
        <span class="auth-row-icon"><svg class="h-[18px] w-[18px]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.7-9.3a1 1 0 00-1.4-1.4L9 10.6 7.7 9.3a1 1 0 00-1.4 1.4l2 2a1 1 0 001.4 0l4-4z" clip-rule="evenodd"/></svg></span>
        <span>Reset links are time-limited and can only be used once.</span>
      </div>
      <div class="auth-row">
        <span class="auth-row-icon"><svg class="h-[18px] w-[18px]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd"/></svg></span>
        <span>Keep your credentials private and update passwords regularly.</span>
      </div>
      <div class="auth-row">
        <span class="auth-row-icon"><svg class="h-[18px] w-[18px]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M2.94 6.34A2 2 0 014.73 5h10.54a2 2 0 011.79 1.34L10 10.6 2.94 6.34z"/><path d="M18 8.28l-7.45 4.5a1 1 0 01-1.1 0L2 8.28V14a2 2 0 002 2h12a2 2 0 002-2V8.28z"/></svg></span>
        <span>Contact your administrator if reset emails are blocked by your domain policy.</span>
      </div>
    </template>
  </auth-shell>
</template>

<script>
import guestKeepalive from '@/Core/guestKeepalive';
import TecInput from '@/Jetstream/Input.vue';
import TecLabel from '@/Jetstream/Label.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import TecValidationErrors from '@/Jetstream/ValidationErrors.vue';
import AuthShell from '@/Shared/Auth/AuthShell.vue';

export default {
  mixins: [guestKeepalive([])],

  components: {
    TecInput,
    TecLabel,
    LoadingButton,
    TecValidationErrors,
    AuthShell,
  },

  props: {
    status: String,
  },

  data() {
    return {
      form: this.$inertia.form({
        email: '',
      }),
      loginLogoFailed: false,
    };
  },

  computed: {
    loginLogoUrl() {
      return this.loginLogoFailed ? '/images/logo.png' : '/images/login-logo.png';
    },
  },

  created() {
    document.title = this.$t('Forgot Password') + ' • ' + this.$settings.name;
  },
  mounted() {
    document.body.classList.add('spinner-normal');
  },
  unmounted() {
    document.body.classList.remove('spinner-normal');
  },

  methods: {
    async submit() {
      await this.$guestKeepalive.ensureFresh(0);
      this.form.post(this.route('password.email'));
    },
  },
};
</script>

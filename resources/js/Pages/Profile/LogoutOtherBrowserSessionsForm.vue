<template>
  <section class="lg-card">
    <header class="lg-card-head">
      <span class="lg-card-ico lg-ico-slate"><icons name="wifi" class="w-5 h-5" /></span>
      <div class="min-w-0">
        <h2 class="lg-card-title">{{ $t('Browser Sessions') }}</h2>
        <p class="lg-card-sub">{{ $t('Manage and log out your active sessions on other browsers and devices.') }}</p>
      </div>
      <span v-if="sessions.length > 0" class="lg-chip lg-chip-blue pf-head-chip">{{ sessions.length }}</span>
    </header>

    <p class="pf-lead">
      {{
        $t(
          'If necessary, you may log out of all of your other browser sessions across all of your devices. Some of your recent sessions are listed below; however, this list may not be exhaustive. If you feel your account has been compromised, you should also update your password.'
        )
      }}
    </p>

    <!-- Other Browser Sessions -->
    <div class="ses-list" v-if="sessions.length > 0">
      <div class="ses-item" :class="{ 'ses-current': session.is_current_device }" v-for="(session, i) in sessions" :key="i">
        <span class="ses-ico">
          <svg
            fill="none"
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            viewBox="0 0 24 24"
            stroke="currentColor"
            class="w-6 h-6"
            v-if="session.agent.is_desktop"
          >
            <path d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
          </svg>

          <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            stroke-width="2"
            stroke="currentColor"
            fill="none"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="w-6 h-6"
            v-else
          >
            <path d="M0 0h24v24H0z" stroke="none"></path>
            <rect x="7" y="4" width="10" height="16" rx="1"></rect>
            <path d="M11 5h2M12 17v.01"></path>
          </svg>
        </span>

        <div class="min-w-0 flex-1">
          <div class="lg-main">{{ session.agent.platform }} - {{ session.agent.browser }}</div>
          <div class="lg-subtext">{{ session.ip_address }}</div>
        </div>

        <span class="lg-chip lg-chip-green" v-if="session.is_current_device"><span class="ses-dot"></span>{{ $t('This device') }}</span>
        <span class="lg-chip lg-chip-green" v-else-if="session.is_online"><span class="ses-dot"></span>{{ $t('Online now') }}</span>
        <span class="lg-chip lg-chip-slate" style="text-transform: none" v-else>{{ $t('Last active') }} {{ session.last_active }}</span>
        <button v-if="!session.is_current_device && session.token" type="button" class="ses-out" :disabled="working" @click="signOutOne(session)"><icons name="out" class="w-3.5 h-3.5" />{{ $t('Sign out') }}</button>
      </div>
    </div>

    <div class="pf-foot" style="justify-content: space-between">
      <tec-action-message :on="form.recentlySuccessful" class="text-sm font-semibold text-emerald-600">{{ $t('Done.') }}</tec-action-message>
      <div class="ses-btns">
        <button type="button" class="lg-btn lg-btn-dark" @click="confirmLogout"><icons name="out" class="w-4 h-4" />{{ $t('Log Out Other Browser Sessions') }}</button>
        <button type="button" class="lg-btn lg-btn-soft" :disabled="working" @click="signOutAll"><icons name="lock-closed" class="w-4 h-4" />{{ $t('Sign out of every device') }}</button>
      </div>
    </div>

    <!-- Log Out Other Devices Confirmation Modal -->
    <tec-dialog-modal :show="confirmingLogout" @close="closeModal">
      <template #title>
        <div class="flex items-center gap-3">
          <span class="lg-card-ico lg-ico-slate"><icons name="out" class="w-5 h-5" /></span>
          <div class="lg-card-title">{{ $t('Log Out Other Browser Sessions') }}</div>
        </div>
      </template>

      <template #content>
        <p class="pf-modal-text">
          {{
            $t('Please enter your password to confirm you would like to log out of your other browser sessions across all of your devices.')
          }}
        </p>

        <div class="mt-4">
          <tec-input
            ref="password"
            type="password"
            placeholder="Password"
            v-model="form.password"
            class="mt-1 block w-3/4"
            @keyup.enter="logoutOtherBrowserSessions"
          />

          <tec-input-error :message="form.errors.password" class="mt-2" />
        </div>
      </template>

      <template #footer>
        <div class="flex items-center justify-end gap-2">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeModal">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-dark" :disabled="form.processing" @click="logoutOtherBrowserSessions">
            <icons :name="form.processing ? 'refresh' : 'out'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />{{ $t('Log Out Other Browser Sessions') }}
          </button>
        </div>
      </template>
    </tec-dialog-modal>
  </section>
</template>

<script>
import TecInput from '@/Jetstream/Input.vue';
import TecInputError from '@/Jetstream/InputError.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import TecActionMessage from '@/Jetstream/ActionMessage.vue';

export default {
  props: ['sessions'],

  components: {
    TecInput,
    TecInputError,
    TecDialogModal,
    TecActionMessage,
  },

  data() {
    return {
      confirmingLogout: false,
      working: false,

      form: this.$inertia.form({
        password: '',
      }),
    };
  },

  methods: {
    confirmLogout() {
      this.confirmingLogout = true;

      setTimeout(() => this.$refs.password.focus(), 250);
    },

    logoutOtherBrowserSessions() {
      this.form.delete(route('other-browser-sessions.destroy'), {
        preserveScroll: true,
        onSuccess: () => this.closeModal(),
        onError: () => this.$refs.password.focus(),
        onFinish: () => this.form.reset(),
      });
    },

    // one device, no password: you are already signed in here (a device that used "Remember me" signs in again unless you sign out of every device)
    signOutOne(session) {
      if (!window.confirm(this.$t('Sign out :d?', { d: session.agent.platform + ' - ' + session.agent.browser + ' (' + session.ip_address + ')' }))) return;
      this.working = true;
      this.$inertia.post(route('device-limit.sign-out', session.token), {}, { preserveScroll: true, onFinish: () => (this.working = false) });
    },

    signOutAll() {
      if (!window.confirm(this.$t('Sign out of every device, this one too?'))) return;
      this.working = true;
      this.$inertia.post(route('device-limit.sign-out-all'), {}, { onFinish: () => (this.working = false) });
    },

    closeModal() {
      this.confirmingLogout = false;

      this.form.reset();
    },
  },
};
</script>

<style scoped>
.ses-list { display: flex; flex-direction: column; gap: 8px; margin-top: 1rem; }
.ses-item { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 10px 14px; border-radius: 16px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.ses-current { border-color: rgba(16, 185, 129, 0.4); background: rgba(16, 185, 129, 0.06); }
.ses-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 42px; height: 42px; border-radius: 13px; color: var(--lg-mute); background: var(--lg-card); border: 1px solid var(--lg-line); }
.ses-current .ses-ico { color: #fff; background: linear-gradient(145deg, #34d399, #047857); border-color: transparent; }
.ses-out { display: inline-flex; align-items: center; gap: 5px; padding: 5px 11px; border-radius: 999px; border: 1px solid #fecaca; background: #fff5f5; color: #b91c1c; font-size: 0.74rem; font-weight: 800; cursor: pointer; }
.ses-out:disabled { opacity: 0.55; cursor: not-allowed; }
.ses-btns { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; }
@media (max-width: 560px) { .ses-btns { width: 100%; } .ses-btns .lg-btn { flex: 1 1 100%; justify-content: center; } .ses-item .ses-out { margin-left: auto; } }
.ses-dot { width: 6px; height: 6px; border-radius: 9999px; background: currentColor; }
</style>

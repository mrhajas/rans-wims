<template>
  <section class="lg-card">
    <header class="lg-card-head">
      <span class="lg-card-ico lg-ico-indigo"><icons name="key" class="w-5 h-5" /></span>
      <div class="min-w-0">
        <h2 class="lg-card-title">{{ $t('Update Password') }}</h2>
        <p class="lg-card-sub">{{ $t('Ensure your account is using a long, random password to stay secure.') }}</p>
      </div>
    </header>

    <form @submit.prevent="updatePassword">
      <div class="lg-fields">
        <div class="lg-span-all">
          <tec-label for="current_password" :value="$t('Current Password')" />
          <tec-input
            type="password"
            id="current_password"
            ref="current_password"
            class="mt-1 block w-full"
            v-model="form.current_password"
            autocomplete="current-password"
          />
          <tec-input-error :message="form.errors.current_password" class="mt-2" />
        </div>

        <div>
          <tec-label for="password" :value="$t('New Password')" />
          <tec-input
            id="password"
            ref="password"
            type="password"
            v-model="form.password"
            class="mt-1 block w-full"
            autocomplete="new-password"
          />
          <tec-input-error :message="form.errors.password" class="mt-2" />
        </div>

        <div>
          <tec-label for="password_confirmation" :value="$t('Confirm Password')" />
          <tec-input
            type="password"
            class="mt-1 block w-full"
            id="password_confirmation"
            autocomplete="new-password"
            v-model="form.password_confirmation"
          />
          <tec-input-error :message="form.errors.password_confirmation" class="mt-2" />
        </div>
      </div>

      <div class="pf-foot">
        <tec-action-message :on="form.recentlySuccessful && !$page.props.flash.error" class="text-sm font-semibold text-emerald-600">{{ $t('Saved.') }}</tec-action-message>
        <button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing">
          <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />{{ $t('Save') }}
        </button>
      </div>
    </form>
  </section>
</template>

<script>
import TecInput from '@/Jetstream/Input.vue';
import TecLabel from '@/Jetstream/Label.vue';
import TecInputError from '@/Jetstream/InputError.vue';
import TecActionMessage from '@/Jetstream/ActionMessage.vue';

export default {
  components: {
    TecLabel,
    TecInput,
    TecInputError,
    TecActionMessage,
  },

  data() {
    return {
      form: this.$inertia.form({
        current_password: '',
        password: '',
        password_confirmation: '',
      }),
    };
  },

  methods: {
    updatePassword() {
      this.form.put(route('user-password.update'), {
        errorBag: 'updatePassword',
        preserveScroll: true,
        onSuccess: () => this.form.reset(),
        onError: () => {
          if (this.form.errors.password) {
            this.form.reset('password', 'password_confirmation');
            this.$refs.password.focus();
          }

          if (this.form.errors.current_password) {
            this.form.reset('current_password');
            this.$refs.current_password.focus();
          }
        },
      });
    },
  },
};
</script>

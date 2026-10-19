<template>
  <section class="lg-card pf-card-danger">
    <header class="lg-card-head">
      <span class="lg-card-ico lg-ico-rose"><icons name="trash" class="w-5 h-5" /></span>
      <div class="min-w-0">
        <h2 class="lg-card-title">{{ $t('delete_x', { x: $t('Account') }) }}</h2>
        <p class="lg-card-sub">{{ $t('Permanently delete your account.') }}</p>
      </div>
    </header>

    <div class="lg-note lg-note-red">
      <icons name="exclamation" class="w-4 h-4" />
      <span>
        {{
          $t(
            'Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.'
          )
        }}
      </span>
    </div>

    <div class="pf-foot">
      <button type="button" class="lg-btn lg-btn-red" @click="confirmUserDeletion"><icons name="trash" class="w-4 h-4" />{{ $t('delete_x', { x: $t('Account') }) }}</button>
    </div>

    <!-- Delete Account Confirmation Modal -->
    <tec-dialog-modal :show="confirmingUserDeletion" @close="closeModal">
      <template #title>
        <div class="flex items-center gap-3">
          <span class="lg-card-ico lg-ico-rose"><icons name="trash" class="w-5 h-5" /></span>
          <div class="lg-card-title">{{ $t('delete_x', { x: $t('Account') }) }}</div>
        </div>
      </template>

      <template #content>
        <p class="pf-modal-text">
          {{
            $t(
              'Are you sure you want to delete your account? Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.'
            )
          }}
        </p>

        <div class="mt-4">
          <tec-input
            ref="password"
            type="password"
            class="mt-1 block w-3/4"
            placeholder="Password"
            v-model="form.password"
            @keyup.enter="deleteUser"
          />

          <tec-input-error :message="form.errors.password" class="mt-2" />
        </div>
      </template>

      <template #footer>
        <div class="flex items-center justify-end gap-2">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeModal">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-red" @click="deleteUser" :disabled="form.processing">
            <icons :name="form.processing ? 'refresh' : 'trash'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />{{ $t('delete_x', { x: $t('Account') }) }}
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

export default {
  components: {
    TecInput,
    TecInputError,
    TecDialogModal,
  },

  data() {
    return {
      confirmingUserDeletion: false,

      form: this.$inertia.form({
        password: '',
      }),
    };
  },

  methods: {
    confirmUserDeletion() {
      this.confirmingUserDeletion = true;

      setTimeout(() => this.$refs.password.focus(), 250);
    },

    deleteUser() {
      this.form.delete(route('current-user.destroy'), {
        preserveScroll: true,
        onSuccess: () => this.closeModal(),
        onError: () => this.$refs.password.focus(),
        onFinish: () => this.form.reset(),
      });
    },

    closeModal() {
      this.confirmingUserDeletion = false;

      this.form.reset();
    },
  },
};
</script>

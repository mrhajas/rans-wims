<template>
  <admin-layout :title="$t(edit ? 'edit_x' : 'create_x', { x: $t('Activity') })">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="clock"
        :title="$t(edit ? 'edit_x' : 'create_x', { x: $t('Activity') })"
        :subtitle="edit ? $t('Update the record by modifying the details in the form below') : $t('Please fill the form below to add new record.')"
      >
        <span v-if="edit" class="lg-chip lg-chip-violet" style="text-transform: none">{{ edit.name }}</span>
        <Link :href="route('activities.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Activities') }}</Link>
      </page-header>

      <form @submit.prevent="update">
        <trashed-message v-if="edit && edit.deleted_at" class="mb-4" @restore="restore">
          {{ $t('This record has been deleted.') }}
        </trashed-message>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-pink"><icons name="clock" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Name') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <text-input v-model="form.name" :error="$page.props.errors.name" :label="$t('Name')" />
            </div>
          </div>
        </section>

        <div class="lg-actionbar">
          <template v-if="edit">
            <button type="button" @click="destroy" v-if="!edit.deleted_at" class="lg-btn lg-btn-red">
              <icons name="trash" class="w-4 h-4" />
              {{ $t('delete_x', { x: $t('Activity') }) }}
            </button>
            <button v-else type="button" @click="deletePermanently" class="lg-btn lg-btn-red">
              <icons name="trash" class="w-4 h-4" />
              {{ $t('delete_x', { x: $t('Permanently') }) }}
            </button>
          </template>
          <div v-else></div>
          <div class="lg-actionbar-btns">
            <tec-action-message :on="form.recentlySuccessful" class="lg-actionbar-note">{{ $t('Saved.') }}</tec-action-message>
            <button type="submit" :disabled="form.processing" class="lg-btn lg-btn-primary">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" /> {{ $t('Save') }}
            </button>
          </div>
        </div>
      </form>
    </div>

    <!-- Delete Permanently Confirmation Modal -->
    <Dialog
      max-width="md"
      :show="permanent"
      action-type="delete"
      title="Delete Activity?"
      :close="closePermanentModal"
      :action="deleteActivityPermanently"
      action-text="Delete Permanently"
      :content="`<p class='mb-2'>${$t('Are you sure you want to delete the record permanently?')}</p>
        <p>${$t('Once deleted, all of its resources and data will be permanently deleted.')}</p>`"
    />

    <!-- Delete Confirmation Modal -->
    <Dialog
      :show="confirm"
      :close="closeModal"
      :action="deleteActivity"
      action-type="delete"
      title="Delete Activity?"
      action-text="Delete Activity"
      :content="$t('Are you sure you want to delete the record?')"
    />

    <!-- Restore Confirmation Modal -->
    <Dialog
      :show="restoreConf"
      :action="restoreActivity"
      title="Restore Activity!"
      :close="closeRestoreModal"
      action-text="Restore Activity"
      :content="$t('Are you sure you want to restore the record?')"
    />
  </admin-layout>
</template>

<script>
import Dialog from '@/Shared/Dialog.vue';
import TextInput from '@/Shared/TextInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TrashedMessage from '@/Shared/TrashedMessage.vue';
import TecFormSection from '@/Jetstream/FormSection.vue';
import TecSectionTitle from '@/Jetstream/SectionTitle.vue';
import TecActionMessage from '@/Jetstream/ActionMessage.vue';

export default {
  props: ['edit'],

  components: {
    Dialog,
    TextInput,
    AdminLayout,
    PageHeader,
    TrashedMessage,
    TecSectionTitle,
    TecActionMessage,
  },

  data() {
    return {
      confirm: false,
      permanent: false,
      restoreConf: false,
      form: this.$inertia.form({
        _method: this.edit ? 'put' : 'post',
        name: this.edit ? this.edit.name : null,
      }),
    };
  },

  methods: {
    update() {
      this.form.post(this.edit ? this.route('activities.update', this.edit.id) : this.route('activities.store'), {
        preserveScroll: true,
      });
    },
    destroy() {
      this.confirm = true;
    },
    deleteActivity() {
      this.form.delete(route('activities.destroy', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    closeModal() {
      this.confirm = false;
    },
    restore() {
      this.restoreConf = true;
    },
    restoreActivity() {
      this.$inertia.put(this.route('activities.restore', this.edit.id), {
        onSuccess: () => (this.restoreConf = false),
      });
    },
    closeRestoreModal() {
      this.restoreConf = false;
    },
    deletePermanently() {
      this.permanent = true;
    },
    deleteActivityPermanently() {
      this.form.delete(route('activities.destroy.permanently', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    closePermanentModal() {
      this.permanent = false;
    },
  },
};
</script>

<style scoped>
.fx-form :deep(.lg-fields label:not(:has(input[type='checkbox']))) {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
}
.fx-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio'])),
.fx-form :deep(.lg-fields select),
.fx-form :deep(.lg-fields textarea) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  padding-top: 0.6rem !important;
  padding-bottom: 0.6rem !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.fx-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):hover),
.fx-form :deep(.lg-fields select:hover) {
  border-color: rgba(236, 72, 153, 0.6) !important;
}
.fx-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):focus),
.fx-form :deep(.lg-fields select:focus),
.fx-form :deep(.lg-fields textarea:focus) {
  border-color: #ec4899 !important;
  box-shadow: 0 0 0 3px rgba(236, 72, 153, 0.18) !important;
}
</style>

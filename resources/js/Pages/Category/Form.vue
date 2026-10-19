<template>
  <admin-layout :title="$t(edit ? 'edit_x' : 'create_x', { x: $t('Category') })">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="collection"
        :title="$t(edit ? 'edit_x' : 'create_x', { x: $t('Category') })"
        :subtitle="edit ? $t('Update the record by modifying the details in the form below') : $t('Please fill the form below to add new record.')"
      >
        <span v-if="edit" class="lg-chip lg-chip-amber" style="text-transform: none">{{ edit.name }}</span>
        <Link :href="route('categories.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Categories') }}</Link>
      </page-header>

      <form @submit.prevent="update">
        <trashed-message v-if="edit && edit.deleted_at" class="mb-4" @restore="restore">
          {{ $t('This record has been deleted.') }}
        </trashed-message>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="collection" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Name') }} / {{ $t('Code') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <text-input v-model="form.name" :error="$page.props.errors.name" :label="$t('Name')" />
            </div>
            <div>
              <text-input v-model="form.code" :error="$page.props.errors.code" :label="$t('Code')" />
            </div>
            <div v-if="parents && parents.length > 1">
              <select-input v-model="form.parent_id" :label="$t('Parent Category')" :error="$page.props.errors.parent_id">
                <option value="">{{ $t('Select') }}</option>
                <template v-for="category in parents" :key="category.id">
                  <template v-if="category.id != edit?.id">
                    <option :value="category.id">{{ category.name }}</option>
                  </template>
                </template>
              </select-input>
            </div>
          </div>
        </section>

        <div class="lg-actionbar">
          <template v-if="edit">
            <button type="button" @click="destroy" v-if="!edit.deleted_at" class="lg-btn lg-btn-red">
              <icons name="trash" class="w-4 h-4" />
              {{ $t('delete_x', { x: $t('Category') }) }}
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

    <!-- Delete User Confirmation Modal -->
    <Dialog
      max-width="md"
      :show="permanent"
      action-type="delete"
      title="Delete Category?"
      :close="closePermanentModal"
      action-text="Delete Permanently"
      :action="deleteCategoryPermanently"
      :content="`<p class='mb-2'>${$t('Are you sure you want to delete the record permanently?')}</p>
        <p>${$t('Once deleted, all of its resources and data will be permanently deleted.')}</p>`"
    />

    <!-- Delete Account Confirmation Modal -->
    <Dialog
      :show="confirm"
      :close="closeModal"
      action-type="delete"
      :action="deleteCategory"
      title="Delete Category?"
      action-text="Delete Category"
      :content="$t('Are you sure you want to delete the record?')"
    />

    <!-- Restore Account Confirmation Modal -->
    <Dialog
      :show="restoreConf"
      :action="restoreCategory"
      title="Restore Category!"
      :close="closeRestoreModal"
      action-text="Restore Category"
      :content="$t('Are you sure you want to restore the record?')"
    />
  </admin-layout>
</template>

<script>
import Dialog from '@/Shared/Dialog.vue';
import TextInput from '@/Shared/TextInput.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import TrashedMessage from '@/Shared/TrashedMessage.vue';
import TecDangerButton from '@/Jetstream/DangerButton.vue';
import TecSectionTitle from '@/Jetstream/SectionTitle.vue';
import TecActionMessage from '@/Jetstream/ActionMessage.vue';
import TecSecondaryButton from '@/Jetstream/SecondaryButton.vue';

export default {
  props: ['edit', 'parents'],

  components: {
    Dialog,
    TextInput,
    AdminLayout,
    SelectInput,
    PageHeader,
    TecDialogModal,
    TecDangerButton,
    TrashedMessage,
    TecSectionTitle,
    TecActionMessage,
    TecSecondaryButton,
  },

  data() {
    return {
      confirm: false,
      permanent: false,
      restoreConf: false,
      form: this.$inertia.form({
        _method: this.edit ? 'put' : 'post',
        name: this.edit ? this.edit.name : null,
        code: this.edit ? this.edit.code : null,
        parent_id: this.edit ? this.edit.parent_id : null,
      }),
    };
  },

  methods: {
    update() {
      this.form.post(this.edit ? this.route('categories.update', this.edit.id) : this.route('categories.store'), {
        preserveScroll: true,
      });
    },
    destroy() {
      this.confirm = true;
    },
    deleteCategory() {
      this.form.delete(route('categories.destroy', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    closeModal() {
      this.confirm = false;
    },
    restore() {
      this.restoreConf = true;
    },
    restoreCategory() {
      this.$inertia.put(this.route('categories.restore', this.edit.id), {
        onSuccess: () => (this.restoreConf = false),
      });
    },
    closeRestoreModal() {
      this.restoreConf = false;
    },
    deletePermanently() {
      this.permanent = true;
    },
    deleteCategoryPermanently() {
      this.form.delete(route('categories.destroy.permanently', this.edit.id), {
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
  border-color: rgba(245, 158, 11, 0.6) !important;
}
.fx-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):focus),
.fx-form :deep(.lg-fields select:focus),
.fx-form :deep(.lg-fields textarea:focus) {
  border-color: #f59e0b !important;
  box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.18) !important;
}
</style>

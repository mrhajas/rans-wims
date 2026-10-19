<template>
  <admin-layout :title="$t(edit ? 'edit_x' : 'create_x', { x: $t('Contact') })">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="user"
        :title="$t(edit ? 'edit_x' : 'create_x', { x: $t('Contact') })"
        :subtitle="edit ? $t('Update the record by modifying the details in the form below') : $t('Please fill the form below to add new record.')"
      >
        <span v-if="edit" class="lg-chip lg-chip-indigo" style="text-transform: none">
          <img v-if="edit.photo" class="block w-4 h-4 rounded-full" :src="edit.photo" />
          {{ edit.name }}
        </span>
        <Link :href="route('contacts.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Contacts') }}</Link>
      </page-header>

      <form @submit.prevent="update">
        <trashed-message v-if="edit && edit.deleted_at" class="mb-4" @restore="restore">
          {{ $t('This record has been deleted.') }}
        </trashed-message>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="user" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Name') }} / {{ $t('Category') }} / {{ $t('Email') }} / {{ $t('Phone') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <text-input v-model="form.name" :error="$page.props.errors.name" :label="$t('Name')" />
            </div>
            <div>
              <auto-complete
                clearable
                freeform
                id="category"
                :label="$t('Category')"
                v-model="form.category"
                :suggestions="categories"
                :error="$page.props.errors.category"
              />
            </div>
            <div>
              <text-input type="email" v-model="form.email" :error="$page.props.errors.email" :label="$t('Email')" />
            </div>
            <div>
              <text-input v-model="form.phone" :error="$page.props.errors.phone" :label="$t('Phone')" />
            </div>
            <div>
              <text-input v-model="form.location" :error="$page.props.errors.location" :label="$t('Location')" />
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-slate"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Notes') }}</h2>
              <p class="lg-card-sub">{{ $t('Details') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div class="lg-span-all">
              <textarea-input v-model="form.details" :error="$page.props.errors.details" :label="$t('Details')" />
            </div>
          </div>
        </section>

        <section v-if="edit && form.category === 'Suppliers'" class="lg-card">
          <div class="fx-link-row">
            <span class="lg-card-ico lg-ico-pink"><icons name="key" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Supplier Financial Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Tax ID, payment terms, currency, credit limit, and bank details, managed in Supplier Records.') }}</p>
            </div>
            <Link :href="route('supplier-records.suppliers.details.edit', edit.id)" class="lg-btn lg-btn-primary">
              <icons name="edit" class="w-4 h-4" /> {{ $t('Manage') }}
            </Link>
          </div>
        </section>
        <p v-else-if="!edit && form.category === 'Suppliers'" class="lg-note lg-note-blue mb-4">
          <icons name="info" class="w-4 h-4" />
          <span>{{ $t('Save this contact first, then open it again to add supplier financial and bank details.') }}</span>
        </p>

        <section v-if="edit" class="lg-card">
          <div class="fx-link-row">
            <span class="lg-card-ico lg-ico-pink"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Payment Vouchers') }}</h2>
              <p class="lg-card-sub">{{ $t('Payment vouchers made out to this contact.') }}</p>
            </div>
            <Link :href="route('payment-vouchers.index', { payee_contact_id: edit.id })" class="lg-btn lg-btn-soft">
              <icons name="view" class="w-4 h-4" /> {{ $t('View') }}
            </Link>
          </div>
        </section>

        <div class="lg-actionbar">
          <template v-if="edit">
            <button type="button" @click="destroy" v-if="!edit.deleted_at" class="lg-btn lg-btn-red">
              <icons name="trash" class="w-4 h-4" />
              {{ $t('delete_x', { x: $t('Contact') }) }}
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
      title="Delete Contact?"
      :close="closePermanentModal"
      :action="deleteContactPermanently"
      action-text="Delete Permanently"
      :content="`<p class='mb-2'>${$t('Are you sure you want to delete the record permanently?')}</p>
        <p>${$t('Once deleted, all of its resources and data will be permanently deleted.')}</p>`"
    />

    <!-- Delete Account Confirmation Modal -->
    <Dialog
      max-width="md"
      :show="confirm"
      :close="closeModal"
      :action="deleteContact"
      action-type="delete"
      title="Delete Contact?"
      action-text="Delete Contact"
      :content="$t('Are you sure you want to delete the record?')"
    />

    <!-- Restore Account Confirmation Modal -->
    <Dialog
      max-width="md"
      :show="restoreConf"
      :action="restoreContact"
      title="Restore Contact!"
      :close="closeRestoreModal"
      action-text="Restore Contact"
      :content="$t('Are you sure you want to restore the record?')"
    />
  </admin-layout>
</template>

<script>
import Dialog from '@/Shared/Dialog.vue';
import TextInput from '@/Shared/TextInput.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import TrashedMessage from '@/Shared/TrashedMessage.vue';
import TecDangerButton from '@/Jetstream/DangerButton.vue';
import TecSectionTitle from '@/Jetstream/SectionTitle.vue';
import TecActionMessage from '@/Jetstream/ActionMessage.vue';
import TecSecondaryButton from '@/Jetstream/SecondaryButton.vue';

export default {
  remember: 'form',

  props: ['edit', 'categories', 'defaultCategory'],

  components: {
    Dialog,
    TextInput,
    AutoComplete,
    AdminLayout,
    SelectInput,
    PageHeader,
    TextareaInput,
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
        category: this.edit ? this.edit.category : this.defaultCategory || null,
        email: this.edit ? this.edit.email : null,
        phone: this.edit ? this.edit.phone : null,
        location: this.edit ? this.edit.location : null,
        details: this.edit ? this.edit.details : null,
      }),
    };
  },

  methods: {
    update() {
      this.form.post(this.edit ? this.route('contacts.update', this.edit.id) : this.route('contacts.store'), {
        preserveScroll: true,
        onSuccess: () => this.form.reset('password', 'photo'),
      });
    },
    destroy() {
      this.confirm = true;
    },
    deleteContact() {
      this.form.delete(route('contacts.destroy', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    closeModal() {
      this.confirm = false;
    },
    restore() {
      this.restoreConf = true;
    },
    restoreContact() {
      this.$inertia.put(this.route('contacts.restore', this.edit.id), {
        onSuccess: () => (this.restoreConf = false),
      });
    },
    closeRestoreModal() {
      this.restoreConf = false;
    },
    deletePermanently() {
      this.permanent = true;
    },
    deleteContactPermanently() {
      this.form.delete(route('contacts.destroy.permanently', this.edit.id), {
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
  border-color: rgba(20, 184, 166, 0.6) !important;
}
.fx-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):focus),
.fx-form :deep(.lg-fields select:focus),
.fx-form :deep(.lg-fields textarea:focus) {
  border-color: #14b8a6 !important;
  box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.18) !important;
}
.fx-link-row {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
</style>

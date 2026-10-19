<template>
  <admin-layout :title="$t(edit ? 'edit_x' : 'create_x', { x: $t('Unit') })">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="calculator"
        :title="$t(edit ? 'edit_x' : 'create_x', { x: $t('Unit') })"
        :subtitle="edit ? $t('Update the record by modifying the details in the form below') : $t('Please fill the form below to add new record.')"
      >
        <span v-if="edit" class="lg-chip lg-chip-indigo" style="text-transform: none">{{ edit.name }}</span>
        <Link :href="route('units.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Units') }}</Link>
      </page-header>

      <form @submit.prevent="update">
        <trashed-message v-if="edit && edit.deleted_at" class="mb-4" @restore="restore">
          {{ $t('This record has been deleted.') }}
        </trashed-message>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="calculator" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Name') }} / {{ $t('Code') }} / {{ $t('Base Unit') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <text-input v-model="form.name" :error="$page.props.errors.name" :label="$t('Name')" />
            </div>
            <div>
              <text-input v-model="form.code" :error="$page.props.errors.code" :label="$t('Code')" />
            </div>
            <div v-if="base_units && base_units.length > 1">
              <select-input :label="$t('Base Unit')" v-model="form.base_unit_id" :error="$page.props.errors.base_unit_id">
                <option value="">{{ $t('Select') }}</option>
                <template v-for="unit in base_units" :key="unit.id">
                  <template v-if="unit.id != edit?.id">
                    <option :value="unit.id">{{ unit.name }}</option>
                  </template>
                </template>
              </select-input>
            </div>
          </div>
        </section>

        <transition name="slidedown">
          <section v-if="form.base_unit_id" class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-violet"><icons name="adjustments" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Conversion') }}</h2>
                <p class="lg-card-sub">{{ $t('Operator') }} / {{ $t('Operation Value') }}</p>
              </div>
            </header>
            <div class="lg-fields">
              <div>
                <select-input :label="$t('Operator')" v-model="form.operator" :error="$page.props.errors.operator">
                  <option value="*">{{ $t('Multiply') }}</option>
                  <option value="/">{{ $t('Divide') }}</option>
                  <option value="+">{{ $t('Plus') }}</option>
                  <option value="-">{{ $t('Minus') }}</option>
                </select-input>
              </div>
              <div>
                <text-input
                  type="number"
                  v-model="form.operation_value"
                  :label="$t('Operation Value')"
                  :error="$page.props.errors.operation_value"
                />
              </div>
            </div>
            <div v-if="form.operator && form.operation_value" class="ufm-formula">
              <icons name="calculator" class="w-4 h-4 flex-none" />
              <span>{{ formula }}</span>
            </div>
          </section>
        </transition>

        <div class="lg-actionbar">
          <template v-if="edit">
            <button type="button" @click="destroy" v-if="!edit.deleted_at" class="lg-btn lg-btn-red">
              <icons name="trash" class="w-4 h-4" />
              {{ $t('delete_x', { x: $t('Unit') }) }}
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
      title="Delete Role?"
      :close="closePermanentModal"
      :action="deleteRolePermanently"
      action-text="Delete Permanently"
      :content="`<p class='mb-2'>${$t('Are you sure you want to delete the record permanently?')}</p>
        <p>${$t('Once deleted, all of its resources and data will be permanently deleted.')}</p>`"
    />

    <!-- Delete Account Confirmation Modal -->
    <Dialog
      :show="confirm"
      :close="closeModal"
      :action="deleteUnit"
      action-type="delete"
      title="Delete Unit?"
      action-text="Delete Unit"
      :content="$t('Are you sure you want to delete the record?')"
    />

    <!-- Restore Account Confirmation Modal -->
    <Dialog
      :show="restoreConf"
      :action="restoreUnit"
      title="Restore Unit!"
      :close="closeRestoreModal"
      action-text="Restore Unit"
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
import TecFormSection from '@/Jetstream/FormSection.vue';
import TecDangerButton from '@/Jetstream/DangerButton.vue';
import TecSectionTitle from '@/Jetstream/SectionTitle.vue';
import TecActionMessage from '@/Jetstream/ActionMessage.vue';
import TecSecondaryButton from '@/Jetstream/SecondaryButton.vue';

export default {
  props: ['edit', 'base_units'],

  components: {
    Dialog,
    TextInput,
    AdminLayout,
    SelectInput,
    PageHeader,
    TecDialogModal,
    TecFormSection,
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
        code: this.edit ? this.edit.code : null,
        name: this.edit ? this.edit.name : null,
        operator: this.edit ? this.edit.operator : null,
        base_unit_id: this.edit ? this.edit.base_unit_id : null,
        operation_value: this.edit ? parseFloat(this.edit.operation_value) : null,
      }),
    };
  },

  computed: {
    formula() {
      let base_unit = this.base_units.find(u => u.id == this.form.base_unit_id);
      if (base_unit) {
        return (
          base_unit.name +
          ' (' +
          base_unit.code +
          ') ' +
          (this.form.operator || '') +
          ' ' +
          (this.form.operation_value || '') +
          ' = ' +
          (this.form.name ? this.form.name : '') +
          (this.form.code ? '(' + this.form.code + ')' : '')
        );
      }
      return null;
    },
  },

  methods: {
    update() {
      this.form.post(this.edit ? this.route('units.update', this.edit.id) : this.route('units.store'), {
        preserveScroll: true,
      });
    },
    destroy() {
      this.confirm = true;
    },
    deleteUnit() {
      this.form.delete(route('units.destroy', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    closeModal() {
      this.confirm = false;
    },
    restore() {
      this.restoreConf = true;
    },
    restoreUnit() {
      this.$inertia.put(this.route('units.restore', this.edit.id), {
        onSuccess: () => (this.restoreConf = false),
      });
    },
    closeRestoreModal() {
      this.restoreConf = false;
    },
    deletePermanently() {
      this.permanent = true;
    },
    deleteRolePermanently() {
      this.form.delete(route('units.destroy.permanently', this.edit.id), {
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
  border-color: rgba(99, 102, 241, 0.6) !important;
}
.fx-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):focus),
.fx-form :deep(.lg-fields select:focus),
.fx-form :deep(.lg-fields textarea:focus) {
  border-color: #6366f1 !important;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.18) !important;
}
.ufm-formula {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin-top: 1rem;
  padding: 0.75rem 1rem;
  border-radius: 14px;
  background: rgba(99, 102, 241, 0.1);
  border: 1px solid rgba(99, 102, 241, 0.22);
  color: #4338ca;
  font-weight: 700;
  font-size: 0.88rem;
  word-break: break-word;
}
html.dark .ufm-formula {
  background: rgba(99, 102, 241, 0.16);
  border-color: rgba(129, 140, 248, 0.32);
  color: #c7d2fe;
}
</style>

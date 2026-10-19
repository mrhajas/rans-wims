<template>
  <modal :show="show" max-width="md" transparent @close="close">
    <div class="qac-panel">
      <button type="button" class="qac-close lg-tip" :data-tip="$t('Close')" @click="close" :aria-label="$t('Close')">
        <icons name="cross" class="w-4 h-4" />
      </button>

      <div class="qac-header">
        <div class="qac-header-icon">
          <icons name="users" className="h-6 w-6" />
        </div>
        <div>
          <h3 class="qac-title">{{ $t('Add New Contact') }}</h3>
          <p class="qac-subtitle">{{ $t('Create a contact without leaving this voucher.') }}</p>
        </div>
      </div>

      <div class="qac-body">
        <text-input :label="$t('Name')" v-model="form.name" :error="errors.name" required @keyup.enter="submit" />
        <auto-complete
          id="quick-contact-category"
          :label="$t('Category (optional)')"
          v-model="form.category"
          :suggestions="categoryOptions"
          :error="errors.category"
          freeform
          clearable
        />
        <div class="grid grid-cols-2 gap-3">
          <text-input :label="$t('Phone (optional)')" v-model="form.phone" :error="errors.phone" @keyup.enter="submit" />
          <text-input :label="$t('Email (optional)')" v-model="form.email" :error="errors.email" @keyup.enter="submit" />
        </div>
      </div>

      <div class="qac-footer">
        <button type="button" class="qac-btn-cancel" @click="close">{{ $t('Cancel') }}</button>
        <loading-button class="qac-btn-save" :loading="saving" :disabled="!form.name || saving" @click="submit">
          <icons name="tick" class="w-4 h-4 mr-1" /> {{ $t('Save Contact') }}
        </loading-button>
      </div>
    </div>
  </modal>
</template>

<script>
import Modal from '@/Jetstream/Modal.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import TextInput from '@/Shared/TextInput.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';

const CATEGORIES = [
  'Engineers', 'Technicians', 'Suppliers', 'Foreman', 'SubContractors', 'Workers', 'General',
  'Drivers', 'Dispatchers', 'Movers', 'Logistics', 'Consultants', 'Architects', 'Surveyors', 'Security', 'Equipment Rental',
  'Clients', 'Loading & UnLoading',
];

export default {
  components: { Modal, LoadingButton, TextInput, AutoComplete },

  props: {
    show: { type: Boolean, default: false },
  },

  emits: ['close', 'created'],

  data() {
    return {
      form: { name: '', category: null, phone: '', email: '' },
      errors: {},
      saving: false,
    };
  },

  computed: {
    categoryOptions() {
      return CATEGORIES.map(c => ({ label: c, value: c }));
    },
  },

  watch: {
    show(value) {
      if (value) {
        this.form = { name: '', category: null, phone: '', email: '' };
        this.errors = {};
      }
    },
  },

  methods: {
    close() {
      this.$emit('close');
    },
    submit() {
      if (!this.form.name || this.saving) {
        return;
      }
      this.saving = true;
      this.errors = {};
      window.axios.post(route('contacts.store'), this.form)
        .then(response => {
          this.$emit('created', response.data);
          this.close();
        })
        .catch(async error => {
          if (error.response && error.response.status === 422) {
            this.errors = error.response.data.errors
              ? Object.fromEntries(Object.entries(error.response.data.errors).map(([k, v]) => [k, v[0]]))
              : {};
          } else {
            await this.$alert(this.$t('Something went wrong while saving the contact.'));
          }
        })
        .finally(() => {
          this.saving = false;
        });
    },
  },
};
</script>

<style scoped>
@keyframes qac-panel-in {
  from { opacity: 0; transform: translateY(12px) scale(0.98); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}

.qac-panel {
  position: relative;
  margin: 0 auto;
  background: linear-gradient(150deg, rgba(255, 255, 255, 0.88), rgba(253, 242, 248, 0.8));
  backdrop-filter: blur(28px) saturate(180%);
  -webkit-backdrop-filter: blur(28px) saturate(180%);
  border: 1px solid rgba(255, 255, 255, 0.65);
  border-radius: 1.5rem;
  box-shadow: 0 25px 60px -12px rgba(190, 24, 93, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.4) inset;
  padding: 1.75rem;
  animation: qac-panel-in 0.3s cubic-bezier(0.16, 1, 0.3, 1) both;
}

.qac-close {
  position: absolute; top: 1.1rem; right: 1.1rem; display: inline-flex; align-items: center; justify-content: center;
  width: 2rem; height: 2rem; border-radius: 9999px; color: #9ca3af; background: rgba(255, 255, 255, 0.6);
  border: 1px solid rgba(255, 255, 255, 0.7); transition: background 0.15s ease, color 0.15s ease, transform 0.15s ease;
}
.qac-close:hover { background: #fff; color: #be185d; transform: rotate(90deg); }

.qac-header { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; padding-right: 2rem; }
.qac-header-icon {
  flex: none; display: inline-flex; align-items: center; justify-content: center; width: 3rem; height: 3rem; border-radius: 1rem;
  background: linear-gradient(145deg, #f472b6, #be185d); color: #fff; box-shadow: 0 10px 24px -8px rgba(190, 24, 93, 0.55);
}
.qac-title { font-size: 1.05rem; font-weight: 800; color: #1f2937; margin: 0; }
.qac-subtitle { font-size: 0.78rem; color: #6b7280; margin: 2px 0 0; }

.qac-body { display: flex; flex-direction: column; gap: 1.1rem; }

/* Round every input rendered inside this modal (TextInput/AutoComplete are
   shared components used all over WIMS, so this is scoped to .qac-body via
   :deep() rather than touching their own global styles). */
.qac-body :deep(label) {
  font-size: 0.7rem; font-weight: 700; color: #9d174d; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.35rem; display: inline-block;
}
.qac-body :deep(input) {
  border-radius: 9999px !important;
  border-color: rgba(244, 114, 182, 0.35) !important;
  background: rgba(255, 255, 255, 0.75) !important;
  padding-left: 1.1rem !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}
.qac-body :deep(input:focus) {
  border-color: #f472b6 !important;
  box-shadow: 0 0 0 4px rgba(244, 114, 182, 0.18) !important;
  background: #fff !important;
  outline: none;
}
.qac-body :deep(input.border-red-500) { border-color: #f87171 !important; }
/* AutoComplete's chevron/clear icon buttons sit absolutely positioned at
   inset-0 — nudge them in slightly so they read as centered inside the now
   fully-rounded pill rather than hugging a corner that no longer exists. */
.qac-body :deep(input[readonly]) { padding-right: 2.4rem !important; }

.qac-footer { display: flex; align-items: center; justify-content: space-between; margin-top: 1.75rem; padding-top: 1.25rem; border-top: 1px solid rgba(255, 255, 255, 0.5); }
.qac-btn-cancel {
  padding: 0.55rem 1.1rem; border-radius: 9999px; font-size: 0.82rem; font-weight: 700; color: #64748b;
  background: rgba(255, 255, 255, 0.6); border: 1px solid rgba(226, 232, 240, 0.8); transition: background 0.15s ease, transform 0.15s ease;
}
.qac-btn-cancel:hover { background: #fff; transform: translateY(-1px); }
.qac-btn-save {
  display: inline-flex; align-items: center; padding: 0.55rem 1.2rem; border-radius: 9999px; font-size: 0.82rem; font-weight: 700; color: #fff;
  background: linear-gradient(145deg, #f472b6, #be185d); box-shadow: 0 8px 20px -6px rgba(190, 24, 93, 0.5); border: none; transition: transform 0.15s ease;
}
.qac-btn-save:hover:not(:disabled) { transform: translateY(-1px); }
.qac-btn-save:disabled { opacity: 0.55; cursor: not-allowed; }

html.dark .qac-panel {
  background: linear-gradient(160deg, rgba(30, 41, 59, 0.88), rgba(15, 23, 42, 0.82));
  border-color: rgba(255, 255, 255, 0.1);
  box-shadow: 0 25px 60px -12px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.05) inset;
}
html.dark .qac-title { color: #f8fafc; }
html.dark .qac-subtitle { color: #94a3b8; }
html.dark .qac-close { background: rgba(255, 255, 255, 0.08); border-color: rgba(255, 255, 255, 0.12); color: #cbd5e1; }
html.dark .qac-footer { border-color: rgba(255, 255, 255, 0.08); }
html.dark .qac-btn-cancel { background: rgba(255, 255, 255, 0.08); border-color: rgba(255, 255, 255, 0.12); color: #cbd5e1; }
html.dark .qac-body :deep(label) { color: #f9a8d4; }
html.dark .qac-body :deep(input) {
  background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(244, 114, 182, 0.25) !important; color: #f1f5f9 !important;
}
html.dark .qac-body :deep(input::placeholder) { color: #64748b; }
html.dark .qac-body :deep(input:focus) { background: rgba(255, 255, 255, 0.1) !important; }
</style>

<template>
  <modal :show="show" max-width="md" transparent @close="close">
    <div class="alp-panel">
      <button type="button" class="alp-close lg-tip" :data-tip="$t('Close')" @click="close" :aria-label="$t('Close')">
        <icons name="cross" class-name="w-4 h-4" />
      </button>

      <div class="alp-header">
        <div class="alp-header-icon">
          <icons name="users" class-name="h-6 w-6" />
        </div>
        <div>
          <h3 class="alp-title">{{ $t('Add Labour Personnel') }}</h3>
          <p class="alp-subtitle">{{ $t('Add a worker, subcontractor, or foreman to the personnel list.') }}</p>
        </div>
      </div>

      <div class="alp-body">
        <text-input :label="$t('Name')" v-model="form.name" :error="errors.name" required @keyup.enter="submit" />

        <div>
          <label class="alp-field-label">{{ $t('Category') }}</label>
          <div class="alp-category-toggle">
            <button
              v-for="cat in categories"
              :key="cat.value"
              type="button"
              class="alp-category-btn"
              :class="{ 'alp-category-active': form.category === cat.value }"
              @click="form.category = cat.value"
            >
              {{ cat.label }}
            </button>
          </div>
          <p v-if="errors.category" class="alp-error wims-field-error">{{ errors.category }}</p>
        </div>

        <text-input :label="$t('Mobile Number')" v-model="form.phone" :error="errors.phone" required @keyup.enter="submit" />
        <textarea-input :label="$t('Remarks (optional)')" v-model="form.details" :error="errors.details" rows="3" />
      </div>

      <div class="alp-footer">
        <button type="button" class="alp-btn-cancel" @click="close">{{ $t('Cancel') }}</button>
        <loading-button class="alp-btn-save" :loading="saving" :disabled="!canSave || saving" @click="submit">
          <icons name="tick" class="w-4 h-4 mr-1" /> {{ $t('Save Personnel') }}
        </loading-button>
      </div>
    </div>
  </modal>
</template>

<script>
import Modal from '@/Jetstream/Modal.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import TextInput from '@/Shared/TextInput.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';

// Deliberately a fixed subset of App\Models\Contact::CATEGORIES — Labour
// Personnel are always one of these three, never the full contact category list.
const CATEGORIES = [
  { value: 'Workers', label: 'Workers' },
  { value: 'SubContractors', label: 'Subcontractors' },
  { value: 'Foreman', label: 'Foremen' },
];

export default {
  components: { Modal, LoadingButton, TextInput, TextareaInput },

  props: {
    show: { type: Boolean, default: false },
  },

  emits: ['close', 'created'],

  data() {
    return {
      form: { name: '', category: 'Workers', phone: '', details: '' },
      errors: {},
      saving: false,
      categories: CATEGORIES,
    };
  },

  computed: {
    canSave() {
      return this.form.name.trim() && this.form.phone.trim() && this.form.category;
    },
  },

  watch: {
    show(value) {
      if (value) {
        this.form = { name: '', category: 'Workers', phone: '', details: '' };
        this.errors = {};
      }
    },
  },

  methods: {
    close() {
      this.$emit('close');
    },
    submit() {
      if (!this.canSave || this.saving) {
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
            await this.$alert(this.$t('Something went wrong while saving this personnel record.'));
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
@keyframes alp-panel-in {
  from { opacity: 0; transform: translateY(12px) scale(0.98); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}

.alp-panel {
  position: relative;
  margin: 0 auto;
  background: linear-gradient(150deg, rgba(255, 255, 255, 0.9), rgba(238, 242, 250, 0.82));
  backdrop-filter: blur(28px) saturate(180%);
  -webkit-backdrop-filter: blur(28px) saturate(180%);
  border: 1px solid rgba(255, 255, 255, 0.65);
  border-radius: 1.5rem;
  box-shadow: 0 25px 60px -12px rgba(28, 63, 102, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.4) inset;
  padding: 1.75rem;
  animation: alp-panel-in 0.3s cubic-bezier(0.16, 1, 0.3, 1) both;
}

.alp-close {
  position: absolute; top: 1.1rem; right: 1.1rem; display: inline-flex; align-items: center; justify-content: center;
  width: 2rem; height: 2rem; border-radius: 9999px; color: #9ca3af; background: rgba(255, 255, 255, 0.6);
  border: 1px solid rgba(255, 255, 255, 0.7); transition: background 0.15s ease, color 0.15s ease, transform 0.15s ease;
}
.alp-close:hover { background: #fff; color: #1c3f66; transform: rotate(90deg); }

.alp-header { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; padding-right: 2rem; }
.alp-header-icon {
  flex: none; display: inline-flex; align-items: center; justify-content: center; width: 3rem; height: 3rem; border-radius: 1rem;
  background: linear-gradient(145deg, #1c3f66, #0f2540); color: #fff; box-shadow: 0 10px 24px -8px rgba(28, 63, 102, 0.55);
}
.alp-title { font-size: 1.05rem; font-weight: 800; color: #1f2937; margin: 0; }
.alp-subtitle { font-size: 0.78rem; color: #6b7280; margin: 2px 0 0; }

.alp-body { display: flex; flex-direction: column; gap: 1.1rem; }
.alp-field-label { display: block; font-size: 0.7rem; font-weight: 700; color: #1c3f66; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.5rem; }
.alp-error { color: #dc2626; font-size: 0.72rem; margin-top: 0.35rem; }

.alp-category-toggle { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.alp-category-btn {
  flex: 1; min-width: 88px; padding: 0.55rem 0.75rem; border-radius: 9999px; font-size: 0.78rem; font-weight: 700;
  color: #64748b; background: rgba(255, 255, 255, 0.7); border: 1px solid rgba(28, 63, 102, 0.15); transition: all 0.15s ease;
}
.alp-category-btn:hover { background: #fff; }
.alp-category-active { background: linear-gradient(145deg, #1c3f66, #0f2540); color: #fff; border-color: transparent; box-shadow: 0 6px 16px -6px rgba(28, 63, 102, 0.5); }

.alp-body :deep(label) {
  font-size: 0.7rem; font-weight: 700; color: #1c3f66; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.35rem; display: inline-block;
}
.alp-body :deep(input),
.alp-body :deep(textarea) {
  border-radius: 0.9rem !important;
  border-color: rgba(28, 63, 102, 0.18) !important;
  background: rgba(255, 255, 255, 0.75) !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}
/* TextareaInput's own mounted()/auto-resize hook sets an inline height
   based on scrollHeight, which an empty textarea resolves to ~1 line
   regardless of a `rows` attribute — min-height still wins over that
   smaller inline height, so it's the one override guaranteed to hold. */
.alp-body :deep(textarea) { min-height: 76px !important; resize: vertical; }
.alp-body :deep(input:focus),
.alp-body :deep(textarea:focus) {
  border-color: #1c3f66 !important;
  box-shadow: 0 0 0 4px rgba(28, 63, 102, 0.12) !important;
  background: #fff !important;
  outline: none;
}
.alp-body :deep(input.border-red-500),
.alp-body :deep(textarea.border-red-500) { border-color: #f87171 !important; }

.alp-footer { display: flex; align-items: center; justify-content: space-between; margin-top: 1.75rem; padding-top: 1.25rem; border-top: 1px solid rgba(255, 255, 255, 0.5); }
.alp-btn-cancel {
  padding: 0.55rem 1.1rem; border-radius: 9999px; font-size: 0.82rem; font-weight: 700; color: #64748b;
  background: rgba(255, 255, 255, 0.6); border: 1px solid rgba(226, 232, 240, 0.8); transition: background 0.15s ease, transform 0.15s ease;
}
.alp-btn-cancel:hover { background: #fff; transform: translateY(-1px); }
.alp-btn-save {
  display: inline-flex; align-items: center; padding: 0.55rem 1.2rem; border-radius: 9999px; font-size: 0.82rem; font-weight: 700; color: #fff;
  background: linear-gradient(145deg, #1c3f66, #0f2540); box-shadow: 0 8px 20px -6px rgba(28, 63, 102, 0.5); border: none; transition: transform 0.15s ease;
}
.alp-btn-save:hover:not(:disabled) { transform: translateY(-1px); }
.alp-btn-save:disabled { opacity: 0.55; cursor: not-allowed; }

html.dark .alp-panel {
  background: linear-gradient(160deg, rgba(30, 41, 59, 0.9), rgba(15, 23, 42, 0.85));
  border-color: rgba(255, 255, 255, 0.1);
  box-shadow: 0 25px 60px -12px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.05) inset;
}
html.dark .alp-title { color: #f8fafc; }
html.dark .alp-subtitle { color: #94a3b8; }
html.dark .alp-close { background: rgba(255, 255, 255, 0.08); border-color: rgba(255, 255, 255, 0.12); color: #cbd5e1; }
html.dark .alp-footer { border-color: rgba(255, 255, 255, 0.08); }
html.dark .alp-btn-cancel { background: rgba(255, 255, 255, 0.08); border-color: rgba(255, 255, 255, 0.12); color: #cbd5e1; }
html.dark .alp-field-label { color: #93c5fd; }
html.dark .alp-category-btn { background: rgba(255, 255, 255, 0.06); border-color: rgba(255, 255, 255, 0.12); color: #cbd5e1; }
html.dark .alp-body :deep(label) { color: #93c5fd; }
html.dark .alp-body :deep(input),
html.dark .alp-body :deep(textarea) {
  background: rgba(255, 255, 255, 0.06) !important; border-color: rgba(28, 63, 102, 0.35) !important; color: #f1f5f9 !important;
}
html.dark .alp-body :deep(input::placeholder),
html.dark .alp-body :deep(textarea::placeholder) { color: #64748b; }
html.dark .alp-body :deep(input:focus),
html.dark .alp-body :deep(textarea:focus) { background: rgba(255, 255, 255, 0.1) !important; }
</style>

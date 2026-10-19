<template>
  <admin-layout :title="edit ? $t('Edit Case') : $t('New Case')">
    <div class="lg-page px-4 md:px-0 ct-form">
      <page-header
        icon="exclamation"
        :title="edit ? $t('Edit Case') + ' (' + edit.case_number + ')' : $t('New Case')"
        :subtitle="edit ? $t('Update the record by modifying the details in the form below') : $t('Please fill the form below to add new record.')"
      >
        <Link :href="route('case-tracking.index')" class="lg-btn lg-btn-soft">
          <icons name="list" class="w-4 h-4" /> {{ $t('Case Tracking') }}
        </Link>
      </page-header>

      <form @submit.prevent="submit">
        <!-- Issue -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-rose"><icons name="exclamation" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Issue') }}</h2>
              <p class="lg-card-sub">{{ $t('What happened, what you expected, steps to reproduce…') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div class="lg-span-all">
              <label class="ct-label">{{ $t('Subject') }}</label>
              <input v-model="form.subject" type="text" class="ct-input w-full" :class="{ 'is-error': $page.props.errors.subject }" :placeholder="$t('Short summary of the issue…')" required />
              <p v-if="$page.props.errors.subject" class="ct-error wims-field-error">{{ $page.props.errors.subject }}</p>
            </div>
            <div class="lg-span-all">
              <label class="ct-label">{{ $t('Description') }}</label>
              <textarea v-model="form.description" class="ct-input w-full" :class="{ 'is-error': $page.props.errors.description }" rows="5" :placeholder="$t('What happened, what you expected, steps to reproduce…')" required></textarea>
              <p v-if="$page.props.errors.description" class="ct-error wims-field-error">{{ $page.props.errors.description }}</p>
            </div>
          </div>
        </section>

        <!-- Classification -->
        <section class="lg-card ct-classify">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="collection" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Classification') }}</h2>
              <p class="lg-card-sub">{{ $t('Category') }} / {{ $t('Priority') }} / {{ $t('Project / Site (optional)') }} / {{ $t('Module / Reference') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <label class="ct-label">{{ $t('Category') }}</label>
              <creatable-select v-model="categoryName" :options="categoryNames" :placeholder="$t('Search or type to add...')" />
            </div>
            <div>
              <label class="ct-label">{{ $t('Priority') }}</label>
              <creatable-select v-model="priorityName" :options="priorityNames" :placeholder="$t('Search or type to add...')" />
            </div>
            <div>
              <label class="ct-label">{{ $t('Project / Site (optional)') }}</label>
              <auto-complete v-model="form.warehouse_id" :suggestions="warehouseOptions" clearable />
            </div>
            <div>
              <label class="ct-label">{{ $t('Module / Reference') }} <span class="ct-required">*</span></label>
              <auto-complete v-model="form.module_reference" :suggestions="moduleOptions" :placeholder="$t('Search WIMS modules…')" :error="$page.props.errors.module_reference" />
            </div>
          </div>
        </section>

        <!-- Link + attachments -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
              <p class="lg-card-sub">{{ $t('Screenshots, documents, supporting files · up to 10 files.') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div class="lg-span-all">
              <label class="ct-label">{{ $t('External URL / Link (optional)') }}</label>
              <input v-model="form.external_url" type="url" class="ct-input w-full" :class="{ 'is-error': $page.props.errors.external_url }" placeholder="https://…" />
              <p v-if="$page.props.errors.external_url" class="ct-error wims-field-error">{{ $page.props.errors.external_url }}</p>
            </div>
            <div class="lg-span-all">
              <label class="ct-label">{{ $t('Attachments (optional)') }}</label>
              <attachment-picker v-model="form.attachments" :max-files="10" :help-text="$t('Screenshots, documents, supporting files · up to 10 files.')" />
              <div v-if="edit && edit.attachments && edit.attachments.length" class="flex flex-wrap gap-2 mt-2">
                <a v-for="a in edit.attachments" :key="a.id" :href="a.download_url" class="lg-chip lg-chip-slate ct-attachment-chip" style="text-transform: none"><icons name="download" class="w-3.5 h-3.5" /> {{ a.name }}</a>
              </div>
            </div>
          </div>
        </section>

        <!-- Sticky action bar -->
        <div class="lg-actionbar">
          <span class="lg-actionbar-note">{{ edit ? edit.case_number : $t('New Case') }}</span>
          <div class="lg-actionbar-btns">
            <Link :href="route('case-tracking.index')" class="lg-btn lg-btn-soft">
              {{ $t('Cancel') }}
            </Link>
            <loading-button type="button" :loading="savingDraft" :disabled="form.processing" class="lg-btn lg-btn-soft gap-1.5" @click="submit(false)">
              <icons name="doc-text" class="w-4 h-4" /> {{ $t('Save Draft') }}
            </loading-button>
            <loading-button type="button" :loading="savingSubmit" :disabled="form.processing" class="lg-btn lg-btn-primary gap-1.5" @click="submit(true)">
              <icons name="tick" class="w-4 h-4" /> {{ $t('Save & Submit') }}
            </loading-button>
          </div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import CreatableSelect from '@/Shared/CreatableSelect.vue';
import AttachmentPicker from '@/Shared/AttachmentPicker.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, Link, AutoComplete, CreatableSelect, AttachmentPicker, LoadingButton, PageHeader },

  props: {
    edit: { type: Object, default: null },
    categories: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    wimsModules: { type: Array, default: () => [] },
    prefillModuleReference: { type: String, default: null },
    prefillExternalUrl: { type: String, default: null },
  },

  data() {
    return {
      savingDraft: false,
      savingSubmit: false,
      localCategories: [...this.categories],
      localPriorities: [...this.priorities],
      form: this.$inertia.form({
        subject: this.edit?.subject || '',
        description: this.edit?.description || '',
        case_category_id: this.edit?.category?.id || null,
        case_priority_id: this.edit?.priority?.id || null,
        warehouse_id: this.edit?.warehouse?.id || null,
        module_reference: this.edit?.module_reference || this.prefillModuleReference || '',
        external_url: this.edit?.external_url || this.prefillExternalUrl || '',
        attachments: [],
        should_submit: false,
      }),
    };
  },

  computed: {
    categoryNames() {
      return this.localCategories.map(c => c.name);
    },
    priorityNames() {
      return this.localPriorities.map(p => p.name);
    },
    categoryName: {
      get() {
        return this.localCategories.find(c => c.id === this.form.case_category_id)?.name || '';
      },
      set(name) {
        this.resolveLookup(name, this.localCategories, 'case-tracking.categories.quick-create', (list) => { this.localCategories = list; }, (id) => { this.form.case_category_id = id; });
      },
    },
    priorityName: {
      get() {
        return this.localPriorities.find(p => p.id === this.form.case_priority_id)?.name || '';
      },
      set(name) {
        this.resolveLookup(name, this.localPriorities, 'case-tracking.priorities.quick-create', (list) => { this.localPriorities = list; }, (id) => { this.form.case_priority_id = id; });
      },
    },
    warehouseOptions() {
      return this.warehouses.map(w => ({ id: w.id, label: w.name }));
    },
    moduleOptions() {
      return this.wimsModules.map(m => ({ id: m, label: m }));
    },
  },

  methods: {
    // Shared by the Category/Priority "type to search or add" fields —
    // resolves a typed name to an existing lookup row's id (case-
    // insensitive), or quick-creates one via the given route when it's
    // genuinely new, then hands the resulting id back to the caller.
    resolveLookup(name, list, routeName, setList, setId) {
      const trimmed = (name || '').trim();
      if (!trimmed) {
        setId(null);
        return;
      }
      const existing = list.find(item => item.name.toLowerCase() === trimmed.toLowerCase());
      if (existing) {
        setId(existing.id);
        return;
      }
      axios.post(this.route(routeName), { name: trimmed }).then(res => {
        setList([...list, { id: res.data.id, name: res.data.name }]);
        setId(res.data.id);
      });
    },
    submit(shouldSubmit) {
      this.form.should_submit = shouldSubmit;
      if (shouldSubmit) {
        this.savingSubmit = true;
      } else {
        this.savingDraft = true;
      }

      const onFinish = () => { this.savingDraft = false; this.savingSubmit = false; };

      if (this.edit) {
        this.form.transform(data => ({ ...data, _method: 'put' })).post(this.route('case-tracking.update', this.edit.id), { onFinish, forceFormData: true });
      } else {
        this.form.post(this.route('case-tracking.store'), { onFinish });
      }
    },
  },
};
</script>

<style scoped>
.ct-label { display: block; font-size: 0.7rem; font-weight: 800; color: var(--lg-faint); margin-bottom: 0.35rem; text-transform: uppercase; letter-spacing: 0.05em; }
.ct-input { border: 1px solid rgba(148, 163, 184, 0.45); border-radius: 0.85rem; padding: 0.6rem 0.8rem; font-size: 0.88rem; color: var(--lg-ink); background: var(--lg-card); transition: border-color 0.15s ease, box-shadow 0.15s ease; }
.ct-input:focus { outline: none; border-color: #2f6fb3; box-shadow: 0 0 0 3px rgba(47, 111, 179, 0.18); }
.ct-input.is-error { border-color: #ef4444; }
.ct-error { font-size: 0.72rem; color: #dc2626; margin-top: 0.25rem; font-weight: 600; }
.ct-required { color: #dc2626; font-weight: 700; }
.ct-attachment-chip { padding: 5px 12px; }
.ct-attachment-chip:hover { filter: brightness(0.95); }
.ct-classify { position: relative; z-index: 15; }
@media (max-width: 480px) {
  .lg-card { padding: 1rem 0.9rem 1.1rem; }
}
html.dark .ct-input { background: rgba(15, 23, 42, 0.55); border-color: rgba(255, 255, 255, 0.14); }
</style>

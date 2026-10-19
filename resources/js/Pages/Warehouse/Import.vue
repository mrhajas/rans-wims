<template>
  <admin-layout :title="$t('import_x', { x: $t('Warehouses') })">
    <div class="lg-page px-4 md:px-0 wi-page">
      <page-header icon="upload" :title="$t('import_x', { x: $t('Warehouses') })" :subtitle="$t('Please upload the excel file to import records.')">
        <Link :href="route('warehouses.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Warehouses') }}</Link>
      </page-header>

      <form @submit.prevent="submit">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Excel File') }}</h2>
              <p class="lg-card-sub">{{ $t('Excel file should have code, name, phone, email, address & active columns.') }}</p>
            </div>
          </header>

          <label
            for="file-upload"
            class="wi-drop"
            :class="{ 'is-error': $page.props.errors.excel, 'has-file': selected }"
            @dragover.prevent
            @drop.prevent="dropFile"
          >
            <icons name="doc-text" class="mx-auto h-12 w-12 wi-drop-ico" />
            <div class="flex items-center justify-center py-2">
              <span class="wi-pick">{{ selected ? $t('Change file') : $t('Select file') }}</span>
              <input
                ref="file"
                type="file"
                class="sr-only"
                id="file-upload"
                name="file-upload"
                @change="updateFile"
                accept=".xls,.xlsx,application/vnd.ms-excel"
              />
              <p class="pl-2 wi-hint">{{ $t('or drag and drop') }}</p>
            </div>
            <div v-if="selected" class="mt-2">
              <span class="lg-chip lg-chip-green" style="text-transform: none"><icons name="tick" class="w-3.5 h-3.5" />{{ $t('Selected File') }}: {{ selected }}</span>
            </div>
          </label>

          <div class="lg-note lg-note-blue mt-4">
            <icons name="info" class="w-4 h-4" />
            <div>{{ $t('You must fill the name & code columns along with active as `yes` or `no`') }}</div>
          </div>

          <div v-if="$page.props.errors.excel" class="lg-note lg-note-red mt-3">
            <icons name="exclamation" class="w-4 h-4" />
            <div>{{ $page.props.errors.excel }}</div>
          </div>
        </section>

        <div class="lg-actionbar">
          <div></div>
          <div class="lg-actionbar-btns">
            <Link :href="route('warehouses.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" :disabled="form.processing" class="lg-btn lg-btn-primary">
              <icons :name="form.processing ? 'refresh' : 'upload'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />{{ $t('Import') }}
            </button>
          </div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import FileInput from '@/Shared/FileInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import TecFormSection from '@/Jetstream/FormSection.vue';
import TecSecondaryButton from '@/Jetstream/SecondaryButton.vue';

export default {
  props: ['parents'],

  components: {
    FileInput,
    AdminLayout,
    PageHeader,
    LoadingButton,
    TecFormSection,
    TecSecondaryButton,
  },

  data() {
    return {
      selected: null,
      form: this.$inertia.form({ _method: 'POST', excel: null }),
    };
  },

  methods: {
    dropFile(e) {
      const files = e.dataTransfer && e.dataTransfer.files;
      if (files && files.length && this.$refs.file) {
        this.$refs.file.files = files;
        this.selected = files[0].name;
      }
    },
    updateFile(e) {
      this.selected = e.target.files[0].name;
    },
    submit() {
      if (this.$refs.file) {
        this.form.excel = this.$refs.file.files[0];
      }

      // var data = new FormData();
      // data.append('excel', this.form.excel);
      // data.append('_method', this.form._method);
      // this.$inertia.post(route('warehouses.import.save'), data);
      this.form.post(route('warehouses.import.save'), { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.wi-drop {
  display: block;
  cursor: pointer;
  padding: 2rem 1.5rem;
  text-align: center;
  border-radius: 1.1rem;
  border: 2px dashed #cbd5e1;
  background: var(--lg-soft);
  transition: border-color 0.15s ease, background 0.15s ease;
}
.wi-drop:hover,
.wi-drop:focus-within {
  border-color: #3b82f6;
  background: rgba(59, 130, 246, 0.06);
}
.wi-drop.has-file {
  border-color: #34d399;
}
.wi-drop.is-error {
  border-color: #f87171;
}
.wi-drop-ico {
  color: var(--lg-faint);
}
.wi-pick {
  font-weight: 800;
  color: #1d4ed8;
}
.wi-hint {
  font-size: 0.85rem;
  color: var(--lg-mute);
}
html.dark .wi-pick {
  color: #93c5fd;
}
html.dark .wi-drop {
  border-color: rgba(255, 255, 255, 0.18);
}
</style>

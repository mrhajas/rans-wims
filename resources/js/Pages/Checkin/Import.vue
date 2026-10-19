<template>
  <admin-layout :title="$t('import_x', { x: $t('Items') })">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="upload" :title="$t('import_x', { x: $t('Items') })" :subtitle="$t('Please upload the excel file to import records.')">
        <Link :href="route('items.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('Items') }}</Link>
        <button type="button" class="lg-btn lg-btn-soft" @click="downloadSample"><icons name="download" class="w-4 h-4" />{{ $t('Download sample file') }}</button>
      </page-header>

      <form @submit.prevent="submit" class="imp-grid">
        <!-- Upload -->
        <section class="lg-card imp-upload">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="upload" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Excel File') }}</h2>
              <p class="lg-card-sub">.xls, .xlsx</p>
            </div>
          </header>

          <label
            for="file-upload"
            class="imp-drop"
            :class="{ 'is-over': dragging, 'is-error': $page.props.errors.excel, 'has-file': selected }"
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
          >
            <span class="imp-drop-ico"><icons :name="selected ? 'tick' : 'upload'" class="w-7 h-7" /></span>
            <span class="imp-drop-main">
              <span v-if="selected" class="imp-drop-link">{{ $t('Change file') }}</span>
              <span v-else class="imp-drop-link">{{ $t('Select file') }}</span>
              <span class="imp-drop-or">{{ $t('or drag and drop') }}</span>
            </span>
            <input
              ref="file"
              type="file"
              class="sr-only"
              id="file-upload"
              name="file-upload"
              accept=".xls,.xlsx,application/vnd.ms-excel"
              @change="updateFile"
            />
          </label>

          <div v-if="selected" class="imp-picked">
            <icons name="doc-text" class="w-4 h-4 flex-none" />
            <span class="imp-picked-label">{{ $t('Selected File') }}:</span>
            <span class="imp-picked-name">{{ selected }}</span>
          </div>
          <div v-if="$page.props.errors.excel" class="lg-note lg-note-red mt-3">
            <icons name="exclamation" class="w-4 h-4" /><span>{{ $page.props.errors.excel }}</span>
          </div>

          <div class="imp-actions">
            <button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing">
              <icons name="upload" class="w-4 h-4" />{{ $t('Import') }}
            </button>
          </div>
        </section>

        <!-- Columns and rules -->
        <section class="lg-card imp-guide">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="table" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Columns') }}</h2>
              <p class="lg-card-sub">{{ $t('Use these column headings in the first row of your file.') }}</p>
            </div>
          </header>
          <div class="imp-cols">
            <span v-for="c in columns" :key="c.name" class="lg-chip lg-mono imp-col" :class="c.required ? 'lg-chip-red' : 'lg-chip-slate'">{{ c.name }}<b v-if="c.required">*</b></span>
          </div>
          <p class="imp-legend"><b>*</b> {{ $t('Required') }}</p>

          <ol class="imp-steps">
            <li><span class="imp-step-n">1</span><span>{{ $t('Excel file should have name, code, sku, symbology, unit, categories, details, has_serial, track_weight, track_quantity, alert_quantity, has_variants & variants columns.') }}</span></li>
            <li><span class="imp-step-n">2</span><span>{{ $t('You must fill the name, code and categories columns.') }} {{ $t('Unit and categories should be unit/category code and already added to the system before importing items.') }}</span></li>
            <li><span class="imp-step-n">3</span><span>{{ $t('has_serial, track_weight, track_quantity & has_variants are booleans and should have `yes` value to enable.') }}</span></li>
            <li><span class="imp-step-n">4</span><span>{{ $t('Variants should be separated by pipe `|` as [Variant Name=Options(separated by comma)] e.g. Color=Red,Green|Size:S,M,L') }}</span></li>
          </ol>
        </section>
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
      dragging: false,
      columns: [
        { name: 'name', required: true },
        { name: 'code', required: true },
        { name: 'sku', required: false },
        { name: 'symbology', required: false },
        { name: 'unit', required: false },
        { name: 'categories', required: true },
        { name: 'details', required: false },
        { name: 'has_serial', required: false },
        { name: 'track_weight', required: false },
        { name: 'track_quantity', required: false },
        { name: 'alert_quantity', required: false },
        { name: 'has_variants', required: false },
        { name: 'variants', required: false },
      ],
      form: this.$inertia.form({ _method: 'POST', excel: null }),
    };
  },

  methods: {
    updateFile(e) {
      this.selected = e.target.files[0] ? e.target.files[0].name : null;
    },
    onDrop(e) {
      this.dragging = false;
      const files = e.dataTransfer && e.dataTransfer.files;
      if (files && files.length && this.$refs.file) {
        this.$refs.file.files = files;
        this.selected = files[0].name;
      }
    },
    // A header-only CSV the user can open in Excel, fill in and save as .xlsx.
    downloadSample() {
      const blob = new Blob([this.columns.map(c => c.name).join(',') + '\n'], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'items-import-sample.csv';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    },
    submit() {
      if (this.$refs.file) {
        this.form.excel = this.$refs.file.files[0];
      }

      this.form.post(route('items.import.save'), { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.imp-grid { display: grid; gap: 1rem; grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr); align-items: start; }
.imp-grid > .lg-card { margin-bottom: 0; min-width: 0; }
.imp-drop { position: relative; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 2.2rem 1rem; text-align: center; cursor: pointer; border-radius: 20px; border: 2px dashed var(--lg-line); background: var(--lg-soft); transition: border-color 0.15s ease, background 0.15s ease, transform 0.15s ease; }
.imp-drop:hover, .imp-drop:focus-within, .imp-drop.is-over { border-color: #6366f1; background: rgba(99, 102, 241, 0.07); }
.imp-drop.is-over { transform: scale(1.01); }
.imp-drop.has-file { border-color: #10b981; background: rgba(16, 185, 129, 0.07); }
.imp-drop.is-error { border-color: #f43f5e; background: rgba(244, 63, 94, 0.06); }
.imp-drop-ico { display: inline-flex; align-items: center; justify-content: center; width: 60px; height: 60px; border-radius: 9999px; color: #4338ca; background: rgba(99, 102, 241, 0.14); }
.imp-drop.has-file .imp-drop-ico { color: #047857; background: rgba(16, 185, 129, 0.16); }
.imp-drop.is-error .imp-drop-ico { color: #be123c; background: rgba(244, 63, 94, 0.14); }
.imp-drop-main { display: flex; flex-wrap: wrap; justify-content: center; gap: 6px; font-size: 0.88rem; }
.imp-drop-link { font-weight: 800; color: #4338ca; }
html.dark .imp-drop-link { color: #a5b4fc; }
.imp-drop-or { color: var(--lg-mute); }
.imp-picked { display: flex; align-items: center; gap: 8px; margin-top: 12px; padding: 10px 14px; border-radius: 14px; font-size: 0.84rem; color: var(--lg-ink); background: var(--lg-soft); border: 1px solid var(--lg-line); }
.imp-picked-label { flex: none; font-weight: 700; color: var(--lg-mute); }
.imp-picked-name { min-width: 0; overflow-wrap: anywhere; font-weight: 800; }
.imp-actions { display: flex; justify-content: flex-end; margin-top: 1.1rem; }
.imp-cols { display: flex; flex-wrap: wrap; gap: 8px; }
.imp-col { text-transform: none; }
.imp-col b { margin-left: 2px; }
.imp-legend { margin-top: 8px; font-size: 0.72rem; color: var(--lg-mute); }
.imp-steps { display: flex; flex-direction: column; gap: 10px; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--lg-line); }
.imp-steps li { display: flex; align-items: flex-start; gap: 10px; font-size: 0.8rem; line-height: 1.5; color: var(--lg-mute); }
.imp-step-n { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 22px; height: 22px; border-radius: 9999px; font-size: 0.7rem; font-weight: 800; color: #fff; background: linear-gradient(145deg, #3b82f6, #1c3f66); }
@media (max-width: 900px) { .imp-grid { grid-template-columns: minmax(0, 1fr); } }
</style>

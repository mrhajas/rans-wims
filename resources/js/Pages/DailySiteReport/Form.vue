<template>
  <admin-layout :title="$t(edit ? 'Edit Site Report' : 'Create Site Report')">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="doc"
        :title="$t(edit ? 'Edit Site Report' : 'Create Site Report')"
        :subtitle="$t('Fill in the details below to submit the site report.')"
      >
        <span v-if="edit" class="lg-chip lg-chip-blue lg-mono" style="text-transform: none">{{ edit.report_number }}</span>
        <Link :href="route('daily-site-reports.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Site Reports') }}</Link>
      </page-header>

      <div v-if="!edit" class="lg-note lg-note-blue mb-4">
        <icons name="clock" class="w-4 h-4" />
        <div class="space-y-1">
          <p class="font-semibold">{{ $t('Submission timing rules') }}</p>
          <ul class="list-disc list-inside space-y-0.5">
            <li>{{ $t('Submit your report on the same calendar day as the Report Date to be marked "OnTime".') }}</li>
            <li>{{ $t('Submitting after midnight (into the next day) will still be accepted, but marked "Overdue".') }}</li>
            <li>{{ $t('For the "Daily Site Report" type, the Report Date cannot be more than 3 days in the past or any date in the future. Reports outside this window cannot be submitted at all.') }}</li>
          </ul>
        </div>
      </div>

      <div v-if="isSiteEngineer && warehouses.length === 0" class="lg-note lg-note-amber mb-4">
        <icons name="exclamation" class="w-4 h-4" />
        <span>{{ $t('No project has been assigned to your account. Please contact your administrator.') }}</span>
      </div>

      <form v-else @submit.prevent="submit">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="doc" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Report Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Report Number') }} / {{ $t('Project / Warehouse') }} / {{ $t('Report Type') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <text-input :label="$t('Report Number')" :model-value="reportNumber" disabled />

            <auto-complete
              id="warehouse"
              :label="$t('Project / Warehouse')"
              :suggestions="warehouseSuggestions"
              v-model="form.warehouse_id"
              :error="$page.props.errors.warehouse_id"
              :searchable="!isSiteEngineer || warehouses.length > 1"
            />

            <text-input v-if="isSiteEngineer" :label="$t('Site Engineer')" :model-value="submittedBy?.name" disabled />
            <auto-complete
              v-else
              id="site-engineer"
              :label="$t('Site Engineer')"
              :suggestions="siteEngineerSuggestions"
              v-model="form.submitted_by"
              :error="$page.props.errors.submitted_by"
            />

            <auto-complete
              id="report-type"
              :label="$t('Report Type')"
              :suggestions="reportTypeSuggestions"
              v-model="form.report_type"
              :error="$page.props.errors.report_type"
            />

            <div>
              <tec-label :for="'report_date'" :value="$t('Report Date')" />
              <date-picker
                id="report_date"
                v-model="form.report_date"
                :min="effectiveMinReportDate"
                :max="reportDate"
                class="mt-1 block w-full"
              />
              <div v-if="dateError" class="text-red-600 text-sm mt-1">{{ dateError }}</div>
              <div v-else-if="$page.props.errors.report_date" class="text-red-600 text-sm mt-1">{{ $page.props.errors.report_date }}</div>
            </div>

            <div>
              <tec-label :value="$t('Priority')" />
              <select-input v-model="form.priority" class="w-full mt-1">
                <option v-for="priority in priorities" :key="priority" :value="priority">{{ $t(priority) }}</option>
              </select-input>
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Remarks') }}</h2>
              <p class="lg-card-sub">{{ $t('Notes and observations for this report') }}</p>
            </div>
          </header>
          <textarea-input v-model="form.remarks" :error="$page.props.errors.remarks" :label="$t('Remarks')" />
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="upload" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
              <p class="lg-card-sub">{{ $t('PDF, DOC, DOCX, XLS, XLSX, JPG, JPEG, PNG, or WEBP files. Maximum {max} files.', { max: maxAttachments }) }}</p>
            </div>
            <span v-if="chosenFiles.length" class="lg-chip lg-chip-amber" style="text-transform: none">{{ chosenFiles.length }}</span>
          </header>

          <div class="flex flex-col gap-3 items-start">
            <button type="button" class="lg-btn lg-btn-soft" @click.prevent="$refs.file?.click()">
              <icons name="upload" class="w-4 h-4" />
              {{ chosenFiles.length ? $t('Add more files') : $t('Choose files') }}
            </button>
            <input
              ref="file"
              id="daily-site-report-attachments"
              type="file"
              multiple
              accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp"
              class="sr-only"
              @change="onFilesChosen"
            />
            <ul v-if="chosenFiles.length" class="fx-files fx-files-list">
              <li v-for="(file, index) in chosenFiles" :key="index">
                <span class="flex items-center gap-2 min-w-0">
                  <icons :name="fileIcon(file)" class="w-4 h-4 flex-none" :class="fileIconClass(file)" />
                  <span class="truncate">{{ file.name }}</span>
                  <span class="lg-subtext flex-none">({{ formatBytes(file.size) }})</span>
                </span>
                <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" @click="removeChosenFile(index)">
                  <icons name="trash" class="w-4 h-4" />
                </button>
              </li>
            </ul>
          </div>
          <div v-if="fileError" class="text-red-600 text-sm mt-2">{{ fileError }}</div>
          <div v-if="$page.props.errors.attachments" class="text-red-600 text-sm mt-2">{{ $page.props.errors.attachments }}</div>
          <div v-if="edit && edit.attachments && edit.attachments.length" class="mt-4">
            <ul class="fx-files fx-files-list">
              <li v-for="attachment in edit.attachments" :key="attachment.id">
                <span class="truncate">{{ attachment.title }}</span>
                <a class="fx-dl" :href="route('daily-site-reports.attachments.download', [edit.id, attachment.id])">{{ $t('Download') }}</a>
              </li>
            </ul>
          </div>
        </section>

        <div class="lg-actionbar">
          <span class="lg-actionbar-note">{{ chosenFiles.length }} {{ $t('Attachments') }}</span>
          <div class="lg-actionbar-btns">
            <Link :href="route('daily-site-reports.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" :disabled="form.processing" class="lg-btn lg-btn-primary">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
              {{ $t(edit ? 'Save Changes' : 'Submit Report') }}
            </button>
          </div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import pick from 'lodash/pick';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import TextInput from '@/Shared/TextInput.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import TecLabel from '@/Jetstream/Label.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';

export default {
  components: {
    AdminLayout,
    TextareaInput,
    TextInput,
    AutoComplete,
    SelectInput,
    TecLabel,
    PageHeader,
    DatePicker,
  },

  props: {
    edit: Object,
    reportTypes: Array,
    priorities: Array,
    warehouses: Array,
    siteEngineers: Array,
    isSiteEngineer: Boolean,
    reportNumber: String,
    submittedBy: Object,
    reportDate: String,
    minReportDate: String,
    maxAttachments: Number,
    maxAttachmentSizeKb: Number,
  },

  data() {
    return {
      fileError: null,
      dateError: null,
      chosenFiles: [],
      form: this.$inertia.form({
        _method: this.edit ? 'PUT' : 'POST',
        warehouse_id: this.edit ? this.edit.warehouse_id : (this.warehouses.length === 1 ? this.warehouses[0].id : null),
        submitted_by: this.edit ? this.edit.submitted_by : null,
        report_date: this.edit ? this.edit.report_date : this.reportDate,
        report_type: this.edit ? this.edit.report_type : null,
        priority: this.edit ? this.edit.priority : 'Normal',
        remarks: this.edit ? this.edit.remarks : '',
        attachments: null,
      }),
    };
  },

  computed: {
    warehouseSuggestions() {
      return this.warehouses.map(w => ({ id: w.id, label: w.name }));
    },
    siteEngineerSuggestions() {
      return this.siteEngineers.map(u => ({ id: u.id, label: u.name }));
    },
    reportTypeSuggestions() {
      return this.reportTypes.map(t => ({ label: t, value: t }));
    },
    isDailySiteReportType() {
      return this.form.report_type === 'Daily Site Report';
    },
    effectiveMinReportDate() {
      return this.isDailySiteReportType ? this.minReportDate : null;
    },
  },

  watch: {
    'form.report_type'() {
      this.validateReportDate();
    },
    'form.report_date'() {
      this.validateReportDate();
    },
  },

  methods: {
    onFilesChosen() {
      const newFiles = this.$refs.file?.files ? Array.from(this.$refs.file.files) : [];
      this.fileError = null;

      const maxBytes = this.maxAttachmentSizeKb * 1024;
      const combined = [...this.chosenFiles];

      for (const file of newFiles) {
        if (file.size > maxBytes) {
          this.fileError = this.$t('"{name}" exceeds the maximum file size.', { name: file.name });
          continue;
        }

        const isDuplicate = combined.some(f => f.name === file.name && f.size === file.size);
        if (!isDuplicate) {
          combined.push(file);
        }
      }

      if (combined.length > this.maxAttachments) {
        this.fileError = this.$t('You may upload a maximum of {max} files.', { max: this.maxAttachments });
        this.chosenFiles = combined.slice(0, this.maxAttachments);
      } else {
        this.chosenFiles = combined;
      }

      // Reset the native input so choosing the same file again still fires @change.
      if (this.$refs.file) {
        this.$refs.file.value = '';
      }
    },
    removeChosenFile(index) {
      this.chosenFiles.splice(index, 1);
      this.fileError = null;
    },
    fileIcon(file) {
      const type = file.type || '';
      if (type.startsWith('image/')) {
        return 'image';
      }
      if (type === 'application/pdf') {
        return 'doc';
      }
      if (['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'].includes(type)) {
        return 'doc-text';
      }
      if (['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'].includes(type)) {
        return 'table';
      }
      return 'clip';
    },
    fileIconClass(file) {
      const type = file.type || '';
      if (type.startsWith('image/')) {
        return 'text-purple-500';
      }
      if (type === 'application/pdf') {
        return 'text-red-500';
      }
      if (['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'].includes(type)) {
        return 'text-blue-600';
      }
      if (['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'].includes(type)) {
        return 'text-green-600';
      }
      return 'text-gray-400';
    },
    formatBytes(bytes) {
      if (!bytes) {
        return '0 B';
      }

      const units = ['B', 'KB', 'MB', 'GB'];
      const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);

      return `${(bytes / Math.pow(1024, exponent)).toFixed(1)} ${units[exponent]}`;
    },
    validateReportDate() {
      this.dateError = null;

      if (!this.form.report_date) {
        return;
      }

      if (this.form.report_date > this.reportDate) {
        this.dateError = this.$t('Report date cannot be in the future.');
        return;
      }

      if (this.isDailySiteReportType && this.form.report_date < this.minReportDate) {
        this.dateError = this.$t('Report date must be within the last 3 days and not in the future.');
      }
    },
    submit() {
      this.validateReportDate();
      if (this.dateError) {
        return;
      }

      if (this.fileError) {
        return;
      }

      this.form.attachments = this.chosenFiles;

      const payload = pick(this.form, ['warehouse_id', 'submitted_by', 'report_date', 'report_type', 'priority', 'remarks', 'attachments', '_method']);
      this.form.transform(() => payload);

      if (this.edit) {
        this.form.post(route('daily-site-reports.update', this.edit.id));
      } else {
        this.form.post(route('daily-site-reports.store'));
      }
    },
  },
};
</script>

<style scoped>
.fx-form :deep(label:not(:has(input[type='checkbox']))) {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
  margin-bottom: 0.25rem;
}
.fx-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):not([type='hidden'])),
.fx-form :deep(select),
.fx-form :deep(textarea) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  padding-top: 0.55rem !important;
  padding-bottom: 0.55rem !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.fx-form :deep(input:disabled) {
  background: var(--lg-soft);
}
.fx-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):focus),
.fx-form :deep(select:focus),
.fx-form :deep(textarea:focus) {
  border-color: #3b82f6 !important;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18) !important;
}

.fx-files { width: 100%; font-size: 0.8rem; color: var(--lg-ink); }
.fx-files li { display: flex; align-items: center; justify-content: space-between; gap: 8px; min-width: 0; padding: 8px 12px; border-bottom: 1px solid var(--lg-line); }
.fx-files-list { border: 1px solid var(--lg-line); border-radius: 14px; }
.fx-files-list li:last-child { border-bottom: 0; }
.fx-dl { flex: none; font-weight: 700; color: var(--lg-navy); }
.fx-dl:hover { text-decoration: underline; }
html.dark .fx-dl { color: #93c5fd; }
</style>

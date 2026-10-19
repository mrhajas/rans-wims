<template>
  <div>
    <label class="block font-medium text-gray-700 mb-2">{{ label }}</label>
    <div class="flex flex-col gap-2">
      <button
        type="button"
        class="inline-flex w-fit items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:border-gray-400 hover:bg-gray-50"
        @click.prevent="$refs.file?.click()"
      >
        <icons name="upload" class="mr-2 h-4 w-4" />
        {{ chosenFiles.length ? $t('Add more files') : $t('Choose files') }}
      </button>
      <input ref="file" type="file" multiple :accept="accept" class="sr-only" @change="onFilesChosen" />
      <p class="text-xs text-gray-500">{{ hint }}</p>

      <ul v-if="chosenFiles.length" class="border border-gray-200 rounded-md divide-y divide-gray-200 bg-white">
        <li v-for="(file, index) in chosenFiles" :key="index" class="px-3 py-2 text-sm flex items-center justify-between">
          <span class="flex items-center gap-2 truncate">
            <icons name="clip" class="w-4 h-4 text-gray-400 shrink-0" />
            <span class="truncate">{{ file.name }}</span>
          </span>
          <button type="button" class="text-red-600 hover:text-red-700 shrink-0 lg-tip" @click="removeChosenFile(index)" :data-tip="$t('Remove')" :aria-label="$t('Remove')">
            <icons name="trash" class="w-4 h-4" />
          </button>
        </li>
      </ul>
    </div>

    <div v-if="fileError" class="text-red-600 text-sm mt-1">{{ fileError }}</div>
    <div v-if="error" class="text-red-600 text-sm mt-1">{{ error }}</div>

    <div v-if="localExisting.length" class="mt-3">
      <ul class="border border-gray-200 rounded-md divide-y divide-gray-200">
        <li v-for="attachment in localExisting" :key="attachment.id" class="px-3 py-2 text-sm flex items-center justify-between">
          <span class="flex items-center gap-2 truncate">
            <icons :name="attachmentIcon(attachment)" class="w-4 h-4 shrink-0" :class="attachmentIconClass(attachment)" />
            <span class="truncate">{{ attachment.title }}</span>
          </span>
          <span class="flex items-center gap-3 shrink-0">
            <button type="button" class="text-indigo-600 hover:text-indigo-500" @click="preview(attachment)">{{ $t('Preview') }}</button>
            <a class="text-indigo-600 hover:text-indigo-500" :href="downloadUrl(attachment)">{{ $t('Download') }}</a>
            <button
              type="button"
              class="text-red-600 hover:text-red-700 disabled:opacity-50 lg-tip"
              :disabled="deletingId === attachment.id"
              @click="destroyAttachment(attachment)"
              :data-tip="$t('Delete')"
             :aria-label="$t('Delete')">
              <icons name="trash" class="w-4 h-4" />
            </button>
          </span>
        </li>
      </ul>
    </div>

    <modal :show="previewing" max-width="4xl" :closeable="true" @close="closePreview">
      <div class="p-4">
        <div class="flex items-center justify-between mb-3">
          <div class="text-lg font-medium">{{ previewAttachment?.title }}</div>
          <button @click="closePreview" class="flex items-center justify-center h-8 w-8 rounded-full text-gray-600 hover:text-gray-800 hover:bg-gray-200">
            <icons name="cross" class="h-5 w-5" />
          </button>
        </div>

        <div v-if="previewLoading" class="py-16 text-center text-gray-500">{{ $t('Loading preview...') }}</div>

        <template v-else-if="previewAttachment">
          <img v-if="isImage(previewAttachment)" :src="downloadUrl(previewAttachment, true)" class="w-full h-auto rounded-md" />
          <iframe v-else-if="isPdf(previewAttachment)" :src="downloadUrl(previewAttachment, true)" class="w-full" style="height: 75vh"></iframe>
          <div
            v-else-if="previewHtml"
            class="preview-document overflow-auto rounded-md border border-gray-200 bg-white p-4"
            style="max-height: 75vh"
            v-html="previewHtml"
          ></div>
          <div v-else-if="previewError" class="py-16 text-center text-gray-500">
            {{ previewError }}
            <div class="mt-3">
              <a class="text-indigo-600 hover:text-indigo-500" :href="downloadUrl(previewAttachment)">{{ $t('Download instead') }}</a>
            </div>
          </div>
        </template>
      </div>
    </modal>
  </div>
</template>

<script>
import Modal from '@/Jetstream/Modal.vue';

export default {
  components: { Modal },

  props: {
    modelValue: { type: Array, default: () => [] },
    existingAttachments: { type: Array, default: () => [] },
    label: { type: String, required: true },
    accept: { type: String, default: '' },
    max: { type: Number, default: 20 },
    error: { type: String, default: null },
    parentId: { type: [Number, String], default: null },
    downloadRouteName: { type: String, required: true },
    destroyRouteName: { type: String, required: true },
  },

  emits: ['update:modelValue'],

  data() {
    return {
      chosenFiles: [...this.modelValue],
      localExisting: [...this.existingAttachments],
      fileError: null,
      deletingId: null,
      previewing: false,
      previewAttachment: null,
      previewLoading: false,
      previewHtml: null,
      previewError: null,
    };
  },

  watch: {
    existingAttachments(value) {
      this.localExisting = [...value];
    },
    chosenFiles: {
      handler(value) {
        this.$emit('update:modelValue', value);
      },
      deep: true,
    },
  },

  computed: {
    hint() {
      return this.accept === 'image/*'
        ? this.$t('You can select multiple images at once. Maximum {max} files.', { max: this.max })
        : this.$t('You can select multiple files of any type at once. Maximum {max} files.', { max: this.max });
    },
  },

  methods: {
    onFilesChosen() {
      const newFiles = this.$refs.file?.files ? Array.from(this.$refs.file.files) : [];
      this.fileError = null;

      const combined = [...this.chosenFiles];
      for (const file of newFiles) {
        const isDuplicate = combined.some(f => f.name === file.name && f.size === file.size);
        if (!isDuplicate) {
          combined.push(file);
        }
      }

      if (combined.length > this.max) {
        this.fileError = this.$t('You may upload a maximum of {max} files.', { max: this.max });
        this.chosenFiles = combined.slice(0, this.max);
      } else {
        this.chosenFiles = combined;
      }

      if (this.$refs.file) {
        this.$refs.file.value = '';
      }
    },

    removeChosenFile(index) {
      this.chosenFiles.splice(index, 1);
      this.fileError = null;
    },

    downloadUrl(attachment, preview = false) {
      const url = this.route(this.downloadRouteName, [this.parentId, attachment.id]);
      return preview ? url + '?preview=1' : url;
    },

    isImage(attachment) {
      return (attachment.filetype || '').startsWith('image/');
    },
    isPdf(attachment) {
      return (attachment.filetype || '') === 'application/pdf';
    },
    isSpreadsheet(attachment) {
      return ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'].includes(attachment.filetype);
    },
    isWordDoc(attachment) {
      return attachment.filetype === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    },
    isLegacyDoc(attachment) {
      return attachment.filetype === 'application/msword';
    },
    attachmentIcon(attachment) {
      if (this.isImage(attachment)) {
        return 'image';
      }
      if (this.isPdf(attachment)) {
        return 'doc';
      }
      if (this.isWordDoc(attachment) || this.isLegacyDoc(attachment)) {
        return 'doc-text';
      }
      if (this.isSpreadsheet(attachment)) {
        return 'table';
      }
      return 'clip';
    },
    attachmentIconClass(attachment) {
      if (this.isImage(attachment)) {
        return 'text-purple-500';
      }
      if (this.isPdf(attachment)) {
        return 'text-red-500';
      }
      if (this.isWordDoc(attachment) || this.isLegacyDoc(attachment)) {
        return 'text-blue-600';
      }
      if (this.isSpreadsheet(attachment)) {
        return 'text-green-600';
      }
      return 'text-gray-400';
    },

    async preview(attachment) {
      this.previewAttachment = attachment;
      this.previewing = true;
      this.previewHtml = null;
      this.previewError = null;
      this.previewLoading = false;

      if (this.isImage(attachment) || this.isPdf(attachment)) {
        return;
      }

      if (this.isLegacyDoc(attachment)) {
        this.previewError = this.$t('Preview is not available for the old .doc format. Please download to view it.');
        return;
      }

      if (!this.isSpreadsheet(attachment) && !this.isWordDoc(attachment)) {
        this.previewError = this.$t('Preview is not available for this file type. Please download to view it.');
        return;
      }

      this.previewLoading = true;

      try {
        const response = await window.axios.get(this.downloadUrl(attachment, true), { responseType: 'arraybuffer' });

        if (this.isSpreadsheet(attachment)) {
          const XLSX = await import('xlsx');
          const workbook = XLSX.read(response.data, { type: 'array' });
          const firstSheetName = workbook.SheetNames[0];
          this.previewHtml = XLSX.utils.sheet_to_html(workbook.Sheets[firstSheetName]);
        } else {
          const { default: mammoth } = await import('mammoth');
          const result = await mammoth.convertToHtml({ arrayBuffer: response.data });
          this.previewHtml = result.value;
        }
      } catch (e) {
        this.previewError = this.$t('Failed to load preview.');
      } finally {
        this.previewLoading = false;
      }
    },

    closePreview() {
      this.previewing = false;
      this.previewAttachment = null;
      this.previewHtml = null;
      this.previewError = null;
    },

    async destroyAttachment(attachment) {
      if (!(await this.$confirm(this.$t('Are you sure you want to delete this file?'), { variant: 'danger' }))) {
        return;
      }

      this.deletingId = attachment.id;

      window.axios
        .delete(this.route(this.destroyRouteName, [this.parentId, attachment.id]))
        .then(() => {
          this.localExisting = this.localExisting.filter(a => a.id !== attachment.id);
        })
        .catch(() => {
          this.fileError = this.$t('Could not delete this file. Please try again.');
        })
        .finally(() => {
          this.deletingId = null;
        });
    },
  },
};
</script>

<style scoped>
.preview-document :deep(table) {
  border-collapse: collapse;
  font-size: 0.8rem;
}

.preview-document :deep(td),
.preview-document :deep(th) {
  border: 1px solid #e5e7eb;
  padding: 4px 8px;
  white-space: nowrap;
}

.preview-document :deep(p) {
  margin: 0.5em 0;
}
</style>

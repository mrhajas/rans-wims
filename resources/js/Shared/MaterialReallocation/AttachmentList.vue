<template>
  <ul class="border border-gray-200 rounded-md divide-y divide-gray-200">
    <li v-for="attachment in attachments" :key="attachment.id" class="px-3 py-2 text-sm flex items-center justify-between gap-3">
      <span class="flex items-center gap-2 truncate">
        <icons :name="attachmentIcon(attachment)" class="w-4 h-4 shrink-0" :class="attachmentIconClass(attachment)" />
        <span class="truncate">{{ attachment.title }}</span>
      </span>
      <div class="flex items-center gap-3 shrink-0">
        <button type="button" class="text-indigo-600 hover:text-indigo-500" @click="preview(attachment)">{{ $t('Preview') }}</button>
        <a class="text-indigo-600 hover:text-indigo-500" :href="downloadUrl(attachment)">{{ $t('Download') }}</a>
        <button v-if="deletable" type="button" class="text-red-600 hover:text-red-500" @click="$emit('delete', attachment.id)">{{ $t('Remove') }}</button>
      </div>
    </li>
  </ul>

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
</template>

<script>
import Modal from '@/Jetstream/Modal.vue';

export default {
  components: { Modal },

  props: {
    attachments: { type: Array, default: () => [] },
    deletable: { type: Boolean, default: false },
  },

  emits: ['delete'],

  data() {
    return {
      previewing: false,
      previewAttachment: null,
      previewLoading: false,
      previewHtml: null,
      previewError: null,
    };
  },

  methods: {
    downloadUrl(attachment, preview = false) {
      const url = this.route('media.download', attachment.id);
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

<template>
  <jetstream-modal :show="show" @close="$emit('close')" max-width="lg">
    <div class="ram-panel p-6">
      <div class="flex items-center justify-between mb-5">
        <div class="flex items-center gap-3">
          <span class="ram-icon"><icons name="clip" class="w-5 h-5" /></span>
          <h3 class="font-bold text-gray-900">{{ title || $t('Attachments') }}</h3>
        </div>
        <button type="button" class="ram-close-btn" @click="$emit('close')">
          <icons name="cross" class="w-4 h-4" />
        </button>
      </div>

      <ul v-if="attachments.length" class="ram-list mb-5">
        <li v-for="file in attachments" :key="file.id" class="ram-item">
          <a :href="file.download_url" target="_blank" class="ram-item-link">
            <span class="ram-item-icon"><icons name="doc" class="w-4 h-4" /></span>
            <span class="ram-item-name">{{ file.name }}</span>
          </a>
          <div class="flex items-center gap-1 flex-none">
            <a :href="file.download_url" target="_blank" class="ram-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')">
              <icons name="download" class="w-4 h-4" />
            </a>
            <button type="button" class="ram-icon-btn ram-icon-btn-danger lg-tip" @click="remove(file)" :data-tip="$t('Remove')" :aria-label="$t('Remove')">
              <icons name="trash" class="w-4 h-4" />
            </button>
          </div>
        </li>
      </ul>
      <div v-else class="ram-empty mb-5">
        <icons name="clip" class="w-6 h-6" />
        <span>{{ $t('No attachments yet.') }}</span>
      </div>

      <attachment-picker v-model="newFiles" :max-files="5" :help-text="$t('PDF, DOC, DOCX, JPG, JPEG, or PNG, up to 5 files.')" />

      <div class="flex justify-end gap-3 mt-5">
        <button type="button" class="ram-btn-plain" @click="$emit('close')">{{ $t('Close') }}</button>
        <loading-button v-if="newFiles.length" type="button" :loading="uploading" :disabled="uploading" @click="upload">
          <icons name="upload" class="w-4 h-4 mr-1" /> {{ $t('Upload') }}
        </loading-button>
      </div>
    </div>
  </jetstream-modal>
</template>

<script>
import JetstreamModal from '@/Jetstream/Modal.vue';
import AttachmentPicker from '@/Shared/AttachmentPicker.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';

export default {
  components: { JetstreamModal, AttachmentPicker, LoadingButton },

  props: {
    show: { type: Boolean, default: false },
    title: { type: String, default: null },
    attachments: { type: Array, default: () => [] },
    uploadUrl: { type: String, default: null },
  },

  emits: ['close'],

  data() {
    return {
      newFiles: [],
      uploading: false,
    };
  },

  watch: {
    show(value) {
      if (!value) {
        this.newFiles = [];
      }
    },
  },

  methods: {
    upload() {
      this.uploading = true;
      this.$inertia.post(this.uploadUrl, { attachments: this.newFiles }, {
        preserveScroll: true,
        onFinish: () => { this.uploading = false; this.newFiles = []; },
      });
    },
    async remove(file) {
      if (!(await this.$confirm(this.$t('Remove this attachment? This cannot be undone.'), { variant: 'danger' }))) {
        return;
      }
      this.$inertia.delete(file.delete_url, { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.ram-icon {
  display: inline-flex; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem; border-radius: 0.7rem;
  background: linear-gradient(145deg, #f472b6, #be185d); color: #fff; box-shadow: 0 6px 14px -6px rgba(190, 24, 93, 0.5);
}
.ram-close-btn {
  display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; border-radius: 9999px;
  color: #9ca3af; transition: background 0.15s ease, color 0.15s ease;
}
.ram-close-btn:hover { background: #f1f5f9; color: #475569; }
.ram-list { border: 1px solid #eef1f6; border-radius: 0.85rem; overflow: hidden; }
.ram-item { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.6rem 0.85rem; border-top: 1px solid #eef1f6; background: #fff; }
.ram-item:first-child { border-top: none; }
.ram-item-link { display: flex; align-items: center; gap: 0.6rem; min-width: 0; color: #374151; }
.ram-item-link:hover { color: #be185d; }
.ram-item-icon { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 1.75rem; height: 1.75rem; border-radius: 0.5rem; background: #f1f5f9; color: #64748b; }
.ram-item-name { font-size: 0.85rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ram-icon-btn {
  display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; border-radius: 0.55rem;
  color: #64748b; background: #f8fafc; border: 1px solid #eef1f6; transition: background 0.15s ease, color 0.15s ease;
}
.ram-icon-btn:hover { background: #eef2f7; color: #1f2937; }
.ram-icon-btn-danger:hover { background: #ffe4e6; color: #be123c; border-color: #fecdd3; }
.ram-empty {
  display: flex; flex-direction: column; align-items: center; gap: 0.4rem; padding: 1.75rem 1rem; color: #9ca3af;
  border: 1px dashed #e2e8f0; border-radius: 0.85rem; font-size: 0.85rem;
}
.ram-btn-plain { padding: 0.5rem 1rem; border-radius: 0.6rem; border: 1px solid #d1d5db; color: #374151; font-size: 0.85rem; font-weight: 600; transition: background 0.15s ease; }
.ram-btn-plain:hover { background: #f9fafb; }

html.dark .ram-panel { background: #0f172a; }
html.dark .ram-close-btn:hover { background: rgba(255, 255, 255, 0.08); color: #e2e8f0; }
html.dark .ram-list { border-color: rgba(255, 255, 255, 0.1); }
html.dark .ram-item { background: transparent; border-color: rgba(255, 255, 255, 0.08); }
html.dark .ram-item-link { color: #e2e8f0; }
html.dark .ram-item-icon { background: rgba(255, 255, 255, 0.08); color: #cbd5e1; }
html.dark .ram-icon-btn { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.1); color: #cbd5e1; }
html.dark .ram-empty { border-color: rgba(255, 255, 255, 0.15); color: #64748b; }
html.dark .ram-btn-plain { border-color: rgba(255, 255, 255, 0.15); color: #e2e8f0; }
</style>

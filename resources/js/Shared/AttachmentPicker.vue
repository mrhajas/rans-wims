<template>
  <div>
    <div class="flex flex-col gap-2">
      <div class="flex flex-wrap items-center gap-2">
        <button
          type="button"
          class="inline-flex w-fit items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm transition hover:border-gray-400 hover:bg-gray-50"
          @click.prevent="$refs.file?.click()"
        >
          <icons name="upload" class="mr-2 h-4 w-4" />
          {{ modelValue.length ? $t('Add more files') : $t('Choose files') }}
        </button>
        <!-- scan: take a photo of the paper with the camera; it is cut out, straightened and cleaned, then added as a file -->
        <button
          v-if="scan"
          type="button"
          class="inline-flex w-fit items-center rounded-md border border-teal-300 bg-teal-50 px-3 py-2 text-sm font-medium text-teal-800 shadow-sm transition hover:border-teal-400 hover:bg-teal-100"
          @click.prevent="scanning = true"
        >
          <icons name="camera" class="mr-2 h-4 w-4" />
          {{ $t('Scan with camera') }}
        </button>
      </div>
      <doc-scanner v-if="scanning" @close="scanning = false" @done="onScanned" />
      <input
        ref="file"
        :id="inputId"
        type="file"
        multiple
        :accept="accept"
        class="sr-only"
        @change="onFilesChosen"
      />
      <p class="text-xs text-gray-500">{{ helpText || defaultHelpText }}</p>
      <ul v-if="modelValue.length" class="border border-gray-200 rounded-md divide-y divide-gray-200 bg-white">
        <li v-for="(file, index) in modelValue" :key="index" class="px-3 py-2 text-sm flex items-center justify-between">
          <span class="flex items-center gap-2">
            <icons :name="fileIcon(file)" class="w-4 h-4" :class="fileIconClass(file)" />
            {{ file.name }}
            <span class="text-gray-400">({{ formatBytes(file.size) }})</span>
          </span>
          <button type="button" class="text-red-600 hover:text-red-700 lg-tip" @click="removeChosenFile(index)" :data-tip="$t('Remove')" :aria-label="$t('Remove')">
            <icons name="trash" class="w-4 h-4" />
          </button>
        </li>
      </ul>
    </div>
    <div v-if="fileError" class="text-red-600 text-sm mt-1">{{ fileError }}</div>
  </div>
</template>

<script>
import DocScanner from '@/Shared/DocScanner.vue';

export default {
  components: { DocScanner },
  props: {
    scan: { type: Boolean, default: false },
    modelValue: { type: Array, default: () => [] },
    maxFiles: { type: Number, default: 10 },
    maxSizeKb: { type: Number, default: 10240 },
    accept: { type: String, default: '.png,.jpg,.jpeg,.pdf,.doc,.docx,.xls,.xlsx,.zip' },
    inputId: { type: String, default: 'attachment-picker-input' },
    helpText: { type: String, default: null },
  },

  emits: ['update:modelValue'],

  data() {
    return {
      fileError: null,
      scanning: false,
    };
  },

  computed: {
    defaultHelpText() {
      return this.$t('Maximum {max} files.', { max: this.maxFiles });
    },
  },

  methods: {
    // a scanned page joins the list like any chosen file
    onScanned(file) {
      this.scanning = false;
      this.fileError = null;
      if (this.modelValue.length >= this.maxFiles) {
        this.fileError = this.$t('You may upload a maximum of {max} files.', { max: this.maxFiles });
        return;
      }
      this.$emit('update:modelValue', [...this.modelValue, file]);
    },
    onFilesChosen() {
      const newFiles = this.$refs.file?.files ? Array.from(this.$refs.file.files) : [];
      this.fileError = null;

      const maxBytes = this.maxSizeKb * 1024;
      const combined = [...this.modelValue];

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

      if (combined.length > this.maxFiles) {
        this.fileError = this.$t('You may upload a maximum of {max} files.', { max: this.maxFiles });
        this.$emit('update:modelValue', combined.slice(0, this.maxFiles));
      } else {
        this.$emit('update:modelValue', combined);
      }

      if (this.$refs.file) {
        this.$refs.file.value = '';
      }
    },
    removeChosenFile(index) {
      const updated = [...this.modelValue];
      updated.splice(index, 1);
      this.fileError = null;
      this.$emit('update:modelValue', updated);
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
  },
};
</script>

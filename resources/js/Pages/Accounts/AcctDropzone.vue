<template>
  <div class="ac-dz-wrap">
    <div
      class="ac-dz"
      :class="{ 'is-over': over, 'is-bad': !!problem, 'is-disabled': disabled }"
      role="button"
      tabindex="0"
      :aria-label="label || $t('Add files')"
      @click="browse"
      @keydown.enter.prevent="browse"
      @keydown.space.prevent="browse"
      @dragenter.prevent="over = !disabled"
      @dragover.prevent="over = !disabled"
      @dragleave.prevent="over = false"
      @drop.prevent="drop"
    >
      <span class="ac-dz-ico"><icons name="upload" class="w-6 h-6" /></span>
      <div class="ac-dz-text">
        <b>{{ label || (multiple ? $t('Drop files here or click to choose') : $t('Drop the file here or click to choose')) }}</b>
        <small>{{ hint || $t('PDF, images and office files up to :n MB each', { n: maxSize }) }}</small>
      </div>
      <span class="ac-dz-btn"><icons name="clip" class="w-4 h-4" />{{ $t('Browse') }}</span>
      <input ref="input" type="file" class="ac-dz-input" :accept="accept" :multiple="multiple" tabindex="-1" @change="pick" />
    </div>
    <p v-if="problem" class="ac-dz-problem" role="alert"><icons name="exclamation" class="w-4 h-4" />{{ problem }}</p>

    <ul v-if="modelValue.length" class="ac-files">
      <li v-for="(f, i) in modelValue" :key="f.name + f.size + i" class="ac-file" :class="'tone-' + kind(f).tone">
        <span v-if="thumbs[i]" class="ac-file-thumb"><img :src="thumbs[i]" :alt="f.name" /></span>
        <span v-else class="ac-rico is-sm"><icons :name="kind(f).icon" class="w-4 h-4" /></span>
        <div class="ac-file-text"><b :title="f.name">{{ f.name }}</b><small><span class="ac-file-kind">{{ kind(f).label }}</span>{{ size(f.size) }}</small></div>
        <button type="button" class="ac-file-x" :aria-label="$t('Remove')" @click.stop="remove(i)"><icons name="trash" class="w-4 h-4" /></button>
      </li>
    </ul>
    <p v-if="modelValue.length > 1" class="ac-dz-total">{{ modelValue.length }} {{ $t('files') }}, {{ size(total) }}</p>
  </div>
</template>

<script>
import { fileKind, fileSize } from './fileKinds';

// Drag and drop (or browse) with the checks done on the spot: type, size, how many. The files are handed to the form as real File objects.
export default {
  props: {
    modelValue: { type: Array, default: () => [] },
    accept: { type: String, default: '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.csv,.txt' },
    maxSize: { type: Number, default: 8 },
    max: { type: Number, default: 10 },
    multiple: { type: Boolean, default: true },
    label: String,
    hint: String,
    disabled: Boolean,
  },
  emits: ['update:modelValue'],
  data() { return { over: false, problem: '', thumbs: [] }; },
  computed: { total() { return this.modelValue.reduce((a, f) => a + f.size, 0); } },
  watch: { modelValue: { handler() { this.makeThumbs(); }, deep: false, immediate: true } },
  beforeUnmount() { this.revoke(); },
  methods: {
    kind(f) { return fileKind(f.name, f.type); },
    size(b) { return fileSize(b); },
    browse() { if (!this.disabled) this.$refs.input.click(); },
    pick(e) { this.add(Array.from(e.target.files || [])); e.target.value = ''; },
    drop(e) { this.over = false; if (!this.disabled) this.add(Array.from((e.dataTransfer && e.dataTransfer.files) || [])); },
    add(files) {
      this.problem = '';
      const exts = this.accept.split(',').map(x => x.trim().toLowerCase());
      const ok = [];
      for (const f of files) {
        const ext = '.' + f.name.split('.').pop().toLowerCase();
        if (!exts.includes(ext)) { this.problem = this.$t('":f" is not an allowed type. Allowed: :t', { f: f.name, t: exts.join(' ') }); continue; }
        if (f.size > this.maxSize * 1024 * 1024) { this.problem = this.$t('":f" is :s, above the :n MB limit.', { f: f.name, s: fileSize(f.size), n: this.maxSize }); continue; }
        if (f.size === 0) { this.problem = this.$t('":f" is empty.', { f: f.name }); continue; }
        if (this.modelValue.some(x => x.name === f.name && x.size === f.size) || ok.some(x => x.name === f.name && x.size === f.size)) { this.problem = this.$t('":f" is already in the list.', { f: f.name }); continue; }
        ok.push(f);
      }
      const next = this.multiple ? [...this.modelValue, ...ok] : ok.slice(0, 1);
      if (next.length > this.max) { this.problem = this.$t('At most :n files at a time.', { n: this.max }); next.length = this.max; }
      this.$emit('update:modelValue', next);
    },
    remove(i) { this.problem = ''; this.$emit('update:modelValue', this.modelValue.filter((_, k) => k !== i)); },
    revoke() { this.thumbs.forEach(u => u && URL.revokeObjectURL(u)); },
    makeThumbs() { this.revoke(); this.thumbs = this.modelValue.map(f => (fileKind(f.name, f.type).key === 'image' ? URL.createObjectURL(f) : null)); },
  },
};
</script>

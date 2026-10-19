<template>
  <div>
    <ul v-if="files.length" class="ac-files">
      <li v-for="f in files" :key="f.id" class="ac-file is-saved" :class="'tone-' + kind(f).tone">
        <button v-if="isImg(f)" type="button" class="ac-file-thumb is-click" :aria-label="$t('Preview')" @click="look = f"><img :src="viewUrl(f)" :alt="f.title" loading="lazy" /></button>
        <span v-else class="ac-rico is-sm"><icons :name="kind(f).icon" class="w-4 h-4" /></span>
        <div class="ac-file-text">
          <a :href="downloadUrl(f)" :title="f.title" class="ac-file-name">{{ f.title }}</a>
          <small><span class="ac-file-kind">{{ kind(f).label }}</span>{{ size(f.size) }}<template v-if="f.doc_type"> · {{ typeLabel(f.doc_type) }}</template><template v-if="f.by"> · {{ f.by }}</template><template v-if="f.at"> · {{ f.at }}</template></small>
        </div>
        <div class="ac-file-actions">
          <a v-if="canView(f)" :href="viewUrl(f)" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('Open')" :aria-label="$t('Open')" @click="isImg(f) && ($event.preventDefault(), (look = f))"><icons name="eye-flat" class="w-4 h-4" /></a>
          <a :href="downloadUrl(f)" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" class="w-4 h-4" /></a>
          <button v-if="removable" type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="$emit('remove', f)"><icons name="trash" class="w-4 h-4" /></button>
        </div>
      </li>
    </ul>
    <div v-else class="ac-files-empty"><span class="ac-rico is-sm tone-slate"><icons name="clip" class="w-4 h-4" /></span>{{ empty || $t('No documents attached.') }}</div>

    <Teleport to="body">
      <div v-if="look" class="ac-lightbox" role="dialog" aria-modal="true" :aria-label="look.title" @click.self="look = null" @keydown.esc="look = null">
        <figure><img :src="viewUrl(look)" :alt="look.title" /><figcaption>{{ look.title }}<a :href="downloadUrl(look)" class="ac-lb-dl"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</a></figcaption></figure>
        <button type="button" class="ac-lb-x" :aria-label="$t('Close')" @click="look = null">&times;</button>
      </div>
    </Teleport>
  </div>
</template>

<script>
import { fileKind, fileSize } from './fileKinds';

export default {
  props: { files: { type: Array, default: () => [] }, downloadUrl: { type: Function, required: true }, removable: Boolean, docTypes: { type: Array, default: () => [] }, empty: String },
  emits: ['remove'],
  data() { return { look: null }; },
  mounted() { this.onKey = e => { if (e.key === 'Escape') this.look = null; }; window.addEventListener('keydown', this.onKey); },
  beforeUnmount() { window.removeEventListener('keydown', this.onKey); },
  methods: {
    kind(f) { return fileKind(f.title, f.mime); },
    size(b) { return fileSize(b); },
    isImg(f) { return this.kind(f).key === 'image'; },
    canView(f) { return ['image', 'pdf'].includes(this.kind(f).key); },
    viewUrl(f) { return this.downloadUrl(f) + (this.downloadUrl(f).includes('?') ? '&' : '?') + 'inline=1'; },
    typeLabel(v) { const d = this.docTypes.find(x => x.value === v); return d ? d.label : v; },
  },
};
</script>

<template>
  <jetstream-modal :show="!!file" max-width="5xl" @close="$emit('close')">
    <div v-if="file" class="av">
      <div class="av-bar">
        <div class="min-w-0"><b>{{ file.name }}</b><small v-if="subtitle">{{ subtitle }}</small></div>
        <div class="av-btns">
          <template v-if="canShow">
            <button type="button" class="lg-btn lg-btn-soft av-zoom" :aria-label="$t('Zoom out')" @click="zoom = Math.max(0.5, +(zoom - 0.25).toFixed(2))" v-if="file.is_image"><icons name="zoom-out" class="w-4 h-4" /></button>
            <button type="button" class="lg-btn lg-btn-soft av-zoom" :aria-label="$t('Zoom in')" @click="zoom = Math.min(4, +(zoom + 0.25).toFixed(2))" v-if="file.is_image"><icons name="zoom-in" class="w-4 h-4" /></button>
            <button type="button" class="lg-btn lg-btn-primary" @click="print"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</button>
          </template>
          <a v-if="canShow" :href="file.preview_url" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="link" class="w-4 h-4" />{{ $t('Open in New Tab') }}</a>
          <a :href="file.download_url" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</a>
          <button type="button" class="lg-btn lg-btn-soft" @click="$emit('close')"><icons name="cross" class="w-4 h-4" />{{ $t('Close') }}</button>
        </div>
      </div>
      <div class="av-stage">
        <img v-if="file.is_image" :src="file.preview_url" :alt="file.name" :style="{ width: zoom === 1 ? 'auto' : (zoom * 100) + '%', maxWidth: zoom === 1 ? '100%' : 'none' }" />
        <iframe v-else-if="file.is_pdf" ref="frame" :src="file.preview_url" :title="file.name"></iframe>
        <video v-else-if="isVideo" :src="file.preview_url" controls></video>
        <audio v-else-if="isAudio" :src="file.preview_url" controls></audio>
        <div v-else class="av-other"><icons name="doc-text" class="w-10 h-10" /><p>{{ $t('This kind of file cannot be shown here.') }}</p><a :href="file.download_url" class="lg-btn lg-btn-primary">{{ $t('Download it') }}</a></div>
      </div>
    </div>
  </jetstream-modal>
</template>

<script>
import JetstreamModal from '@/Jetstream/Modal.vue';

/*
 * One preview window for any attachment: pictures (with zoom), PDFs, video and audio are shown right here; anything else offers the download.
 * `file` needs { name, preview_url, download_url } and, so the right viewer is chosen, { is_image, is_pdf, mime }. Print, open in a new tab
 * and download are always at hand.
 */
export default {
  components: { JetstreamModal },
  props: {
    file: { type: Object, default: null },
    subtitle: { type: String, default: '' },
  },
  emits: ['close'],
  data() {
    return { zoom: 1 };
  },
  computed: {
    mime() { return String((this.file && this.file.mime) || '').toLowerCase(); },
    isVideo() { return this.mime.startsWith('video/'); },
    isAudio() { return this.mime.startsWith('audio/'); },
    canShow() { return !!this.file && (this.file.is_image || this.file.is_pdf || this.isVideo || this.isAudio); },
  },
  watch: {
    file() { this.zoom = 1; },
  },
  methods: {
    print() {
      const f = this.$refs.frame;
      try { if (f && f.contentWindow) { f.contentWindow.focus(); f.contentWindow.print(); return; } } catch (e) { /* the browser blocks printing inside the frame */ }
      const w = window.open(this.file.preview_url, '_blank');
      if (w) w.addEventListener('load', () => { try { w.print(); } catch (e) { /* ignore */ } });
    },
  },
};
</script>

<style>
.av { display: grid; grid-template-rows: auto 1fr; height: min(86vh, 900px); }
.av-bar { display: flex; align-items: center; justify-content: space-between; gap: 0.8rem; flex-wrap: wrap; padding: 0.8rem 1rem; border-bottom: 1px solid #e2e8f0; } .av-bar b { display: block; font-size: 0.95rem; overflow-wrap: anywhere; } .av-bar small { font-size: 0.75rem; color: #64748b; } .av-btns { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.av-stage { min-height: 0; background: #e2e8f0; display: grid; place-items: center; overflow: auto; } .av-stage iframe { width: 100%; height: 100%; border: 0; background: #fff; } .av-stage img { max-height: 100%; object-fit: contain; } .av-stage video { max-width: 100%; max-height: 100%; } .av-other { text-align: center; display: grid; gap: 0.6rem; justify-items: center; color: #475569; padding: 2rem; }
html.dark .av-bar { border-color: rgba(255, 255, 255, 0.12); } html.dark .av-stage { background: #0b1020; } html.dark .av-bar small, html.dark .av-other { color: #94a3b8; }
@media (max-width: 640px) { .av { height: 90vh; } }
</style>

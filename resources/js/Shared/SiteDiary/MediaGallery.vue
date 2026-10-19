<template>
  <div class="mg">
    <section v-if="photos.length" class="mg-section">
      <h3 class="mg-title"><icons name="image" class="w-4 h-4" />{{ $t('Photo Gallery') }} <span class="mg-count">{{ photos.length }}</span></h3>
      <div class="mg-grid">
        <figure v-for="(m, i) in photos" :key="m.id" class="mg-tile" :style="{ '--i': i }">
          <button type="button" class="mg-thumb" :aria-label="$t('Preview') + ' ' + m.name" @click="openBox(m)">
            <img v-if="m.thumb_url || !m.expired" :src="m.thumb_url || m.view_url" :alt="m.name" loading="lazy" :class="{ 'mg-gone': m.expired }" />
            <span v-if="m.expired" class="mg-state is-expired">{{ $t('Erased from Google Drive') }}</span>
            <span v-else-if="m.status !== 'uploaded'" class="mg-state" :class="'is-' + m.status">{{ statusText(m) }}</span>
          </button>
          <figcaption class="mg-cap">
            <span class="mg-name" :title="m.name">{{ m.name }}</span>
            <span class="mg-size"><icons name="clip" class="w-3 h-3" />{{ size(m.size) }}</span>
            <span class="mg-meta">{{ $datetime(m.uploaded_at) }}<template v-if="m.uploaded_by"> · {{ m.uploaded_by }}</template></span>
            <span class="mg-actions">
              <a v-if="!m.expired" :href="m.open_url" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('View (opens in a new tab)')" :aria-label="$t('View')"><icons name="eye-flat" class="w-4 h-4" /></a>
              <a v-if="m.download_url && !m.expired" :href="m.download_url" class="lg-icon-btn is-green lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" class="w-4 h-4" /></a>
              <button v-if="m.status === 'failed' && can.addPhotos" type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Retry upload to Google Drive')" :aria-label="$t('Retry')" @click="retry(m)"><icons name="refresh" class="w-4 h-4" /></button>
              <offline-lock v-if="can.deletePhotos">
                <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove photo')" :aria-label="$t('Remove photo')" @click="del(m)"><icons name="trash" class="w-4 h-4" /></button>
              </offline-lock>
            </span>
          </figcaption>
        </figure>
      </div>
    </section>

    <section v-if="videos.length" class="mg-section">
      <h3 class="mg-title"><icons name="video" class="w-4 h-4" />{{ $t('Videos') }} <span class="mg-count">{{ videos.length }}</span></h3>
      <div class="mg-grid mg-grid-video">
        <figure v-for="(m, i) in videos" :key="m.id" class="mg-tile" :style="{ '--i': i }">
          <button type="button" class="mg-thumb mg-vthumb" :aria-label="$t('Play') + ' ' + m.name" @click="openBox(m)">
            <span class="mg-play"><icons name="video" class="w-7 h-7" /></span>
            <span class="mg-vsize">{{ size(m.size) }}</span>
            <span v-if="m.expired" class="mg-state is-expired">{{ $t('Erased from Google Drive') }}</span>
            <span v-else-if="m.status !== 'uploaded'" class="mg-state" :class="'is-' + m.status">{{ statusText(m) }}</span>
          </button>
          <figcaption class="mg-cap">
            <span class="mg-name" :title="m.name">{{ m.name }}</span>
            <span class="mg-size"><icons name="clip" class="w-3 h-3" />{{ size(m.size) }}</span>
            <span class="mg-meta">{{ $datetime(m.uploaded_at) }}<template v-if="m.uploaded_by"> · {{ m.uploaded_by }}</template></span>
            <span class="mg-actions">
              <a v-if="!m.expired" :href="m.open_url" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('View (opens in a new tab)')" :aria-label="$t('View')"><icons name="eye-flat" class="w-4 h-4" /></a>
              <a v-if="m.download_url && !m.expired" :href="m.download_url" class="lg-icon-btn is-green lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" class="w-4 h-4" /></a>
              <button v-if="m.status === 'failed' && can.addVideos" type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Retry upload to Google Drive')" :aria-label="$t('Retry')" @click="retry(m)"><icons name="refresh" class="w-4 h-4" /></button>
              <offline-lock v-if="can.deleteVideos">
                <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove video')" :aria-label="$t('Remove video')" @click="del(m)"><icons name="trash" class="w-4 h-4" /></button>
              </offline-lock>
            </span>
          </figcaption>
        </figure>
      </div>
    </section>

    <p v-if="!photos.length && !videos.length" class="lg-empty">{{ emptyText || $t('No photos or videos yet.') }}</p>

    <!-- Lightbox -->
    <Teleport to="body">
      <div v-if="box" class="mg-lb" role="dialog" aria-modal="true" @click.self="close" @keydown.esc="close">
        <button type="button" class="mg-lb-x" :aria-label="$t('Close')" @click="close"><icons name="cross" class="w-6 h-6" /></button>
        <button v-if="list.length > 1" type="button" class="mg-lb-nav is-prev" :aria-label="$t('Previous')" @click="step(-1)"><icons name="chevron-down" class="w-7 h-7 transform rotate-90" /></button>
        <button v-if="list.length > 1" type="button" class="mg-lb-nav is-next" :aria-label="$t('Next')" @click="step(1)"><icons name="chevron-down" class="w-7 h-7 transform -rotate-90" /></button>
        <div class="mg-lb-stage" @touchstart.passive="touchStart" @touchend.passive="touchEnd">
          <img v-if="box.type === 'photo'" :key="box.id" :src="box.view_url" :alt="box.name" class="mg-lb-media" />
          <video v-else :key="box.id" :src="box.view_url" class="mg-lb-media" controls autoplay playsinline preload="metadata"></video>
        </div>
        <div class="mg-lb-bar">
          <div class="mg-lb-info">
            <b>{{ box.name }}</b>
            <span>{{ index + 1 }} / {{ list.length }} · {{ size(box.size) }} · {{ $datetime(box.uploaded_at) }}<template v-if="box.uploaded_by"> · {{ box.uploaded_by }}</template></span>
          </div>
          <div class="mg-lb-links">
            <a :href="box.open_url" target="_blank" rel="noopener" class="mg-lb-link"><icons name="eye-flat" class="w-4 h-4" />{{ $t('View in new tab') }}</a>
            <a v-if="box.download_url" :href="box.download_url" class="mg-lb-link"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</a>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script>
export default {
  props: {
    items: { type: Array, default: () => [] },
    can: { type: Object, required: true },
    diaryId: { type: Number, required: true },
    emptyText: { type: String, default: null },
    deleteFiles: { type: Boolean, default: false },
  },

  emits: ['removed', 'updated'],

  data() {
    return { box: null, touchX: null };
  },

  computed: {
    photos() {
      return this.items.filter(m => m.type === 'photo');
    },
    videos() {
      return this.items.filter(m => m.type === 'video');
    },
    list() {
      return [...this.photos, ...this.videos];
    },
    index() {
      return this.box ? this.list.findIndex(m => m.id === this.box.id) : -1;
    },
  },

  beforeUnmount() {
    document.removeEventListener('keydown', this.onKey);
    document.documentElement.style.overflow = '';
  },

  methods: {
    size(b) {
      return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
    },
    statusText(m) {
      return { pending: this.$t('Waiting for Google Drive'), uploading: this.$t('Uploading…'), failed: this.$t('Drive upload failed') }[m.status] || '';
    },
    openBox(m) {
      if (m.expired) return;
      this.box = m;
      document.addEventListener('keydown', this.onKey);
      document.documentElement.style.overflow = 'hidden';
    },
    close() {
      this.box = null;
      document.removeEventListener('keydown', this.onKey);
      document.documentElement.style.overflow = '';
    },
    step(d) {
      const n = this.list.length;
      this.box = this.list[(this.index + d + n) % n];
    },
    onKey(e) {
      if (e.key === 'Escape') this.close();
      else if (e.key === 'ArrowRight') this.step(1);
      else if (e.key === 'ArrowLeft') this.step(-1);
    },
    touchStart(e) {
      this.touchX = e.changedTouches[0].clientX;
    },
    touchEnd(e) {
      if (this.touchX === null) return;
      const dx = e.changedTouches[0].clientX - this.touchX;
      this.touchX = null;
      if (Math.abs(dx) > 60 && this.list.length > 1) this.step(dx < 0 ? 1 : -1);
    },
    async del(m) {
      if (this.$offlineBlocked) return;
      if (!(await this.$confirm(this.deleteFiles ? this.$t('Remove “:name” from this diary? The file itself is also deleted from storage (Google Drive Trash). The action is logged.', { name: m.name }) : this.$t('Remove “:name” from this diary? The action is logged.', { name: m.name }), { variant: 'danger', confirmText: this.$t('Remove') }))) return;
      try {
        const { data } = await window.axios.delete(this.route('site-diary.media.destroy', [this.diaryId, m.id]));
        this.$emit('removed', { media: m, counts: data });
      } catch (e) {
        const msg = (e.response && e.response.data && e.response.data.message) || this.$t('Could not remove the file.');
        if (this.$alert) await this.$alert(msg); else alert(msg);
      }
    },
    async retry(m) {
      try {
        const { data } = await window.axios.post(this.route('site-diary.media.retry', [this.diaryId, m.id]));
        this.$emit('updated', data.media);
      } catch (e) {
        alert((e.response && e.response.data && e.response.data.message) || this.$t('Google Drive Connection Problem'));
      }
    },
  },
};
</script>

<style scoped>
.mg-section + .mg-section { margin-top: 1.4rem; }
.mg-title { display: flex; align-items: center; gap: 8px; margin: 0 0 0.7rem; font-size: 0.78rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute); }
.mg-count { display: inline-grid; place-items: center; min-width: 22px; height: 20px; padding: 0 6px; border-radius: 9999px; background: rgba(47, 111, 179, 0.14); color: #2f6fb3; font-size: 0.7rem; }
html.dark .mg-count { color: #93c5fd; }
.mg-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(168px, 1fr)); gap: 12px; }
.mg-grid-video { grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); }
.mg-tile { margin: 0; border: 1px solid var(--lg-line); border-radius: 1rem; background: var(--lg-card); overflow: hidden; box-shadow: var(--lg-shadow); animation: mg-rise 0.4s ease backwards; animation-delay: calc(var(--i, 0) * 35ms); transition: transform 0.18s ease, box-shadow 0.18s ease; }
.mg-tile:hover { transform: translateY(-2px); }
.mg-thumb { position: relative; display: block; width: 100%; aspect-ratio: 4 / 3; padding: 0; border: 0; background: var(--lg-soft); cursor: zoom-in; overflow: hidden; }
.mg-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform 0.3s ease; }
.mg-tile:hover .mg-thumb img { transform: scale(1.04); }
.mg-vthumb { background: linear-gradient(145deg, #0f2540, #1f4f7a); display: grid; place-items: center; cursor: pointer; }
.mg-play { display: grid; place-items: center; width: 54px; height: 54px; border-radius: 9999px; background: rgba(255, 255, 255, 0.16); color: #fff; backdrop-filter: blur(4px); }
.mg-vsize { position: absolute; right: 8px; bottom: 6px; font-size: 0.68rem; font-weight: 800; color: rgba(255, 255, 255, 0.85); }
.mg-state { position: absolute; left: 6px; top: 6px; padding: 2px 8px; border-radius: 9999px; font-size: 0.64rem; font-weight: 800; background: #f59e0b; color: #fff; }
.mg-state.is-failed { background: #e11d48; }
.mg-state.is-expired { background: #475569; }
.mg-gone { filter: grayscale(1) opacity(0.45); }
.mg-cap { display: flex; flex-direction: column; gap: 2px; padding: 8px 10px 9px; }
.mg-name { font-size: 0.78rem; font-weight: 700; color: var(--lg-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mg-size { display: inline-flex; align-items: center; gap: 4px; align-self: flex-start; margin: 1px 0 2px; padding: 1px 8px; border-radius: 9999px; background: rgba(127, 29, 29, 0.1); color: #7f1d1d; font-size: 0.68rem; font-weight: 900; font-variant-numeric: tabular-nums; }
html.dark .mg-size { background: rgba(252, 165, 165, 0.16); color: #fca5a5; }
.mg-meta { font-size: 0.66rem; color: var(--lg-faint); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mg-actions { display: flex; flex-wrap: wrap; gap: 2px; margin-top: 4px; }
@keyframes mg-rise { from { opacity: 0; transform: translateY(8px); } }

/* lightbox */
.mg-lb { position: fixed; inset: 0; z-index: 10000; display: flex; flex-direction: column; background: rgba(8, 15, 28, 0.94); }
.mg-lb-stage { flex: 1; min-height: 0; display: grid; place-items: center; padding: 3.2rem 3.4rem 0.6rem; }
.mg-lb-media { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 0.5rem; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5); background: #000; }
.mg-lb-x { position: absolute; top: 12px; right: 14px; z-index: 2; width: 42px; height: 42px; display: grid; place-items: center; border-radius: 9999px; border: 0; color: #fff; background: rgba(255, 255, 255, 0.14); cursor: pointer; }
.mg-lb-nav { position: absolute; top: 50%; z-index: 2; width: 46px; height: 46px; margin-top: -23px; display: grid; place-items: center; border-radius: 9999px; border: 0; color: #fff; background: rgba(255, 255, 255, 0.14); cursor: pointer; }
.mg-lb-nav.is-prev { left: 10px; }
.mg-lb-nav.is-next { right: 10px; }
.mg-lb-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 16px; padding: 0.7rem 1.2rem 1rem; color: #e2e8f0; }
.mg-lb-info { display: flex; flex-direction: column; min-width: 0; }
.mg-lb-info b { font-size: 0.9rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mg-lb-info span { font-size: 0.74rem; color: #94a3b8; }
.mg-lb-links { display: flex; flex-wrap: wrap; gap: 8px; }
.mg-lb-link { display: inline-flex; align-items: center; gap: 6px; padding: 7px 13px; border-radius: 9999px; background: rgba(255, 255, 255, 0.14); color: #fff; font-size: 0.8rem; font-weight: 700; text-decoration: none; }
.mg-lb-link:hover { background: rgba(255, 255, 255, 0.24); }
@media (max-width: 640px) {
  .mg-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; }
  .mg-grid-video { grid-template-columns: 1fr; }
  .mg-lb-stage { padding: 3.2rem 0.4rem 0.4rem; }
  .mg-lb-nav { width: 38px; height: 38px; margin-top: -19px; }
}
@media (prefers-reduced-motion: reduce) { .mg-tile { animation: none; transition: none; } }
</style>

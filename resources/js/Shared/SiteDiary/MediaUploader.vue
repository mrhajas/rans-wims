<template>
  <div class="mu" :class="{ 'is-over': over }" @dragover.prevent="over = true" @dragleave.prevent="over = false" @drop.prevent="onDrop">
    <!-- capture tiles -->
    <div class="mu-tiles">
      <button v-if="canPhotos || canVideos" type="button" class="mu-tile is-camera" :disabled="uploading" @click="cameraOpen = true">
        <span class="mu-tile-ico"><icons name="camera" class="w-7 h-7" /></span>
        <b>{{ $t('Camera') }}</b>
        <small>{{ $t('Take pictures') }}</small>
      </button>
      <label v-if="canPhotos" class="mu-tile is-gallery" :class="{ 'is-disabled': uploading }">
        <span class="mu-tile-ico"><icons name="image" class="w-7 h-7" /></span>
        <b>{{ $t('Gallery') }}</b>
        <small>{{ $t('Choose photos') }}</small>
        <input type="file" class="sr-only" multiple :accept="acceptFor('photo')" :disabled="uploading" @change="pick($event, 'photo')" />
      </label>
      <label v-if="canVideos" class="mu-tile is-video" :class="{ 'is-disabled': uploading }">
        <span class="mu-tile-ico"><icons name="video" class="w-7 h-7" /></span>
        <b>{{ $t('Videos') }}</b>
        <small>{{ $t('Choose videos') }}</small>
        <input type="file" class="sr-only" multiple :accept="acceptFor('video')" :disabled="uploading" @change="pick($event, 'video')" />
      </label>
      <!-- the phone's own camera app, used when the live camera is not available (e.g. no https) -->
      <input ref="nativeCam" type="file" class="sr-only" accept="image/*" capture="environment" @change="pick($event, 'photo')" />
    </div>

    <!-- allowance meters -->
    <div class="mu-meters">
      <div v-if="canPhotos" class="mu-meter is-photo">
        <div class="mu-meter-top"><span><icons name="image" class="w-3.5 h-3.5" />{{ $t('Photos') }}</span><b>{{ total('photo') }} / {{ limits.photos.max }}</b></div>
        <div class="mu-meter-bar"><span :style="{ width: pct('photo') + '%' }" :class="{ 'is-full': photoFull }"></span></div>
      </div>
      <div v-if="canVideos" class="mu-meter is-vid">
        <div class="mu-meter-top"><span><icons name="video" class="w-3.5 h-3.5" />{{ $t('Videos') }}</span><b>{{ total('video') }} / {{ limits.videos.max }}</b></div>
        <div class="mu-meter-bar"><span :style="{ width: pct('video') + '%' }" :class="{ 'is-full': videoFull }"></span></div>
      </div>
    </div>
    <p class="mu-rules">{{ rulesText }}</p>

    <camera-capture
      v-if="cameraOpen"
      :can-photos="canPhotos"
      :can-videos="canVideos"
      :photo-left="photoLeft"
      :video-left="videoLeft"
      :max-px="limits.capture_max_px || 2048"
      :video-types="limits.videos.types"
      :video-max-mb="limits.videos.max_mb"
      @capture="onCapture"
      @fallback="$refs.nativeCam && $refs.nativeCam.click()"
      @close="cameraOpen = false"
    />

    <p v-if="message" class="mu-msg" role="alert"><icons name="exclamation" class="w-4 h-4" />{{ message }}</p>

    <!-- overall progress -->
    <div v-if="uploading || (queue.length && doneCount)" class="mu-overall">
      <div class="mu-overall-top">
        <b><icons name="upload" class="w-4 h-4" />{{ uploading ? $t('Uploading :n of :t…', { n: currentIndex, t: batchSize }) : $t('Uploaded') }}</b>
        <span>{{ doneCount }} / {{ queue.length }} · {{ percent }}%</span>
      </div>
      <div class="mu-obar" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100"><span :style="{ width: percent + '%' }"></span></div>
    </div>

    <!-- thumbnails with a progress ring on each -->
    <ul v-if="queue.length" class="mu-grid">
      <li v-for="q in queue" :key="q.uid" class="mu-thumb" :class="'is-' + q.status">
        <img v-if="q.preview" :src="q.preview" alt="" />
        <span v-else class="mu-vid"><icons name="video" class="w-8 h-8" /></span>

        <span v-if="q.status === 'uploading'" class="mu-shade"><span class="mu-ring" :style="{ '--p': q.progress }"><i>{{ q.progress }}%</i></span></span>
        <span v-else-if="q.status === 'uploaded'" class="mu-done"><icons name="tick" class="w-4 h-4" /></span>
        <span v-else-if="q.status === 'failed'" class="mu-shade is-bad"><icons name="exclamation" class="w-6 h-6" /><small>{{ q.error }}</small></span>

        <button v-if="q.status === 'queued'" type="button" class="mu-x" :aria-label="$t('Remove')" @click="remove(q)"><icons name="cross" class="w-3.5 h-3.5" /></button>
        <button v-else-if="q.status === 'failed' && !uploading" type="button" class="mu-retry" :aria-label="$t('Try again')" @click="retryOne(q)"><icons name="refresh" class="w-4 h-4" />{{ $t('Retry') }}</button>

        <span class="mu-cap"><icons :name="q.type === 'video' ? 'video' : 'image'" class="w-3 h-3" />{{ size(q.size) }}</span>
      </li>
    </ul>
    <p v-else class="mu-empty"><icons name="camera" class="w-5 h-5" />{{ $t('Nothing added yet, tap Camera or Gallery.') }}</p>
  </div>
</template>

<script>
import CameraCapture from '@/Shared/SiteDiary/CameraCapture.vue';
import { overallPercent } from '@/Core/uploadQueue';

// Multi-file uploader for Site Diary. Every file is sent in its own request (so one bad file never loses the rest, and
// each gets a real progress ring). The server enforces every limit again; the checks here only give instant feedback.
let counter = 0;
const uid = () => 'u' + Date.now().toString(36) + '-' + (++counter) + '-' + Math.random().toString(36).slice(2, 8);

export default {
  components: { CameraCapture },

  props: {
    limits: { type: Object, required: true },
    can: { type: Object, required: true },
    // media already saved on the diary, so the counters / limits include them
    existing: { type: Object, default: () => ({ photos: 0, videos: 0 }) },
  },

  emits: ['uploaded', 'queue-change', 'batch-done'],

  data() {
    return { cameraOpen: false, lastUrl: null, queue: [], over: false, message: null, uploading: false, currentIndex: 0, batchSize: 0 };
  },

  computed: {
    canPhotos() {
      return !!(this.can.addPhotos && this.limits.photos.enabled);
    },
    canVideos() {
      return !!(this.can.addVideos && this.limits.videos.enabled);
    },
    photoLeft() {
      return this.canPhotos ? Math.max(0, this.limits.photos.max - this.total('photo')) : 0;
    },
    videoLeft() {
      return this.canVideos ? Math.max(0, this.limits.videos.max - this.total('video')) : 0;
    },
    photoFull() {
      return this.total('photo') >= this.limits.photos.max;
    },
    videoFull() {
      return this.total('video') >= this.limits.videos.max;
    },
    rulesText() {
      const parts = [];
      if (this.canPhotos) parts.push(this.$t('Photos: :types, max :mb MB', { types: this.limits.photos.types.join(', ').toUpperCase(), mb: this.limits.photos.max_mb }));
      if (this.canVideos) parts.push(this.$t('Videos: :types, max :mb MB', { types: this.limits.videos.types.join(', ').toUpperCase(), mb: this.limits.videos.max_mb }));
      return parts.join(' · ');
    },
    waiting() {
      return this.queue.filter(q => q.status === 'queued' || q.status === 'failed');
    },
    doneCount() {
      return this.queue.filter(q => q.status === 'uploaded').length;
    },
    percent() {
      return overallPercent(this.queue);
    },
  },

  watch: {
    queue: {
      deep: true,
      handler() {
        this.$emit('queue-change', { waiting: this.queue.filter(q => q.status === 'queued').length, failed: this.queue.filter(q => q.status === 'failed').length });
      },
    },
  },

  beforeUnmount() {
    this.queue.forEach(q => q.preview && URL.revokeObjectURL(q.preview));
  },

  methods: {
    acceptFor(type) {
      return this.limits[type + 's'].types.map(t => '.' + t).join(',') + (type === 'photo' ? ',image/*' : ',video/*');
    },
    size(b) {
      return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
    },
    pct(type) {
      const max = this.limits[type + 's'].max || 1;
      return Math.min(100, Math.round((this.total(type) / max) * 100));
    },
    // how many of this type exist: the saved ones (the page keeps `existing` current, uploads included) + files still
    // waiting in the queue (failed files count until they are removed)
    total(type) {
      return (type === 'photo' ? this.existing.photos : this.existing.videos) + this.queue.filter(q => q.type === type && q.status !== 'uploaded').length;
    },
    ext(name) {
      return (name.split('.').pop() || '').toLowerCase();
    },
    kindOf(file) {
      const e = this.ext(file.name);
      if (this.limits.photos.types.includes(e) || file.type.startsWith('image/')) return 'photo';
      if (this.limits.videos.types.includes(e) || file.type.startsWith('video/')) return 'video';
      return null;
    },
    onCapture(file, type) {
      this.add([file], type);
    },
    onDrop(e) {
      this.over = false;
      if (this.uploading) return;
      this.add(Array.from(e.dataTransfer.files || []), null);
    },
    pick(e, forced) {
      this.add(Array.from(e.target.files || []), forced);
      e.target.value = '';
    },
    add(files, forced) {
      this.message = null;
      const problems = [];
      for (const file of files) {
        const type = forced || this.kindOf(file);
        if (!type) { problems.push(this.$t('":name" is not a photo or video.', { name: file.name })); continue; }
        const cfg = this.limits[type + 's'];
        const allowed = type === 'photo' ? this.canPhotos : this.canVideos;
        if (!allowed) { problems.push(type === 'photo' ? this.$t('Photos are not enabled.') : this.$t('Videos are not enabled.')); continue; }
        if (!cfg.types.includes(this.ext(file.name))) { problems.push(this.$t('":name": type not allowed (:types).', { name: file.name, types: cfg.types.join(', ').toUpperCase() })); continue; }
        if (file.size > cfg.max_mb * 1048576) { problems.push(this.$t('":name" is larger than :mb MB.', { name: file.name, mb: cfg.max_mb })); continue; }
        if (this.total(type) >= cfg.max) {
          problems.push(type === 'photo'
            ? this.$t('Maximum of :n photos is allowed for this Site Diary.', { n: cfg.max })
            : this.$t('Maximum of :n videos is allowed for this Site Diary.', { n: cfg.max }));
          break;
        }
        if (this.queue.some(q => q.name === file.name && q.size === file.size && q.status !== 'uploaded')) continue;
        this.queue.push({ uid: uid(), file, type, name: file.name, size: file.size, status: 'queued', progress: 0, error: null, media: null, preview: type === 'photo' ? URL.createObjectURL(file) : null });
      }
      if (problems.length) this.message = [...new Set(problems)].join(' ');
    },
    remove(q) {
      if (q.preview) URL.revokeObjectURL(q.preview);
      this.queue = this.queue.filter(x => x.uid !== q.uid);
    },

    // ---- uploading ------------------------------------------------------------------------------------------------
    async sendOne(q, url) {
      q.status = 'uploading';
      q.progress = 0;
      q.error = null;
      const fd = new FormData();
      fd.append('file', q.file);
      fd.append('type', q.type);
      fd.append('client_uuid', q.uid);
      try {
        const { data } = await window.axios.post(url, fd, {
          headers: { 'Content-Type': 'multipart/form-data' },
          timeout: 0,
          onUploadProgress: e => { if (e.total) q.progress = Math.min(99, Math.round((e.loaded / e.total) * 100)); },
        });
        q.progress = 100;
        q.status = 'uploaded';
        q.media = data.media;
        this.$emit('uploaded', data);
        return true;
      } catch (e) {
        q.status = 'failed';
        const r = e.response;
        q.error = (r && r.data && (r.data.errors ? Object.values(r.data.errors)[0][0] : r.data.message)) || (r ? this.$t('Upload failed.') : this.$t('Connection lost. Try again.'));
        return false;
      }
    },
    /** Uploads everything waiting. Resolves with the counts so the page can finish (notify, redirect). */
    async upload(url) {
      if (this.$offlineBlocked) {
        this.message = this.$t('You are offline. Uploading is available again when the connection is back.');
        return { photos: 0, videos: 0, failed: this.waiting.length };
      }
      this.lastUrl = url;
      const list = this.queue.filter(q => q.status === 'queued' || q.status === 'failed');
      this.uploading = true;
      this.batchSize = list.length;
      let photos = 0, videos = 0, failed = 0;
      for (let i = 0; i < list.length; i++) {
        this.currentIndex = i + 1;
        const ok = await this.sendOne(list[i], url);
        if (ok) { list[i].type === 'photo' ? photos++ : videos++; } else { failed++; }
      }
      this.uploading = false;
      return { photos, videos, failed };
    },
    async retryOne(q) {
      if (!this.lastUrl) return;
      this.uploading = true;
      this.batchSize = 1;
      this.currentIndex = 1;
      const ok = await this.sendOne(q, this.lastUrl);
      this.uploading = false;
      if (ok) this.$emit('batch-done', { photos: q.type === 'photo' ? 1 : 0, videos: q.type === 'video' ? 1 : 0, failed: 0 });
    },
    hasWaiting() {
      return this.queue.some(q => q.status === 'queued' || q.status === 'failed');
    },
  },
};
</script>

<style scoped>
.mu { position: relative; border-radius: 1.2rem; transition: box-shadow 0.2s; }
.mu.is-over { box-shadow: 0 0 0 3px rgba(47, 111, 179, 0.4); }

/* capture tiles */
.mu-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
.mu-tile { position: relative; display: flex; flex-direction: column; align-items: flex-start; gap: 2px; padding: 16px 16px 14px; border: 0; border-radius: 1.3rem; color: #fff; text-align: left; cursor: pointer; overflow: hidden; box-shadow: 0 14px 28px -14px var(--sh, rgba(15, 23, 42, 0.5)), inset 0 1px 0 rgba(255, 255, 255, 0.25); transition: transform 0.15s ease, box-shadow 0.15s ease; touch-action: manipulation; }
.mu-tile::after { content: ''; position: absolute; right: -28px; top: -28px; width: 110px; height: 110px; border-radius: 9999px; background: rgba(255, 255, 255, 0.16); }
.mu-tile:hover { transform: translateY(-2px); }
.mu-tile:active { transform: scale(0.97); }
.mu-tile.is-disabled, .mu-tile:disabled { opacity: 0.55; pointer-events: none; }
.mu-tile b { font-size: 1.05rem; font-weight: 900; margin-top: 10px; }
.mu-tile small { font-size: 0.74rem; opacity: 0.85; font-weight: 600; }
.mu-tile-ico { display: grid; place-items: center; width: 48px; height: 48px; border-radius: 0.95rem; background: rgba(255, 255, 255, 0.22); backdrop-filter: blur(4px); }
.mu-tile.is-camera { background: linear-gradient(145deg, #fb923c, #ea580c); --sh: rgba(234, 88, 12, 0.7); }
.mu-tile.is-gallery { background: linear-gradient(145deg, #8b5cf6, #5b3df5); --sh: rgba(91, 61, 245, 0.7); }
.mu-tile.is-video { background: linear-gradient(145deg, #14b8a6, #0f766e); --sh: rgba(15, 118, 110, 0.7); }

/* meters */
.mu-meters { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px; margin-top: 14px; }
.mu-meter { padding: 10px 12px; border-radius: 1rem; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.mu-meter-top { display: flex; justify-content: space-between; align-items: center; gap: 8px; font-size: 0.78rem; color: var(--lg-mute); font-weight: 700; }
.mu-meter-top span { display: inline-flex; align-items: center; gap: 6px; }
.mu-meter-top b { color: var(--lg-ink); font-variant-numeric: tabular-nums; }
.mu-meter-bar { height: 8px; margin-top: 7px; border-radius: 9999px; background: var(--lg-line); overflow: hidden; }
.mu-meter-bar span { display: block; height: 100%; border-radius: inherit; transition: width 0.3s ease; background: linear-gradient(90deg, #fb923c, #f59e0b); }
.mu-meter.is-vid .mu-meter-bar span { background: linear-gradient(90deg, #14b8a6, #22d3ee); }
.mu-meter-bar span.is-full { background: linear-gradient(90deg, #e11d48, #f43f5e); }
.mu-rules { margin: 8px 2px 0; font-size: 0.72rem; color: var(--lg-faint); }

.mu-msg { display: flex; align-items: flex-start; gap: 7px; margin: 0.8rem 0 0; padding: 0.6rem 0.85rem; border-radius: 0.9rem; background: rgba(225, 29, 72, 0.1); color: #be123c; font-size: 0.82rem; font-weight: 700; }
html.dark .mu-msg { color: #fda4af; }

/* overall progress */
.mu-overall { margin-top: 14px; padding: 11px 14px; border-radius: 1rem; background: linear-gradient(135deg, rgba(47, 111, 179, 0.12), rgba(15, 118, 110, 0.12)); border: 1px solid rgba(47, 111, 179, 0.25); }
.mu-overall-top { display: flex; justify-content: space-between; gap: 8px; font-size: 0.82rem; color: var(--lg-ink); }
.mu-overall-top b { display: inline-flex; align-items: center; gap: 6px; }
.mu-overall-top span { color: var(--lg-mute); font-weight: 800; font-variant-numeric: tabular-nums; }
.mu-obar { height: 10px; margin-top: 8px; border-radius: 9999px; background: var(--lg-line); overflow: hidden; }
.mu-obar span { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #2f6fb3, #0f766e); transition: width 0.2s linear; }

/* thumbnails */
.mu-grid { list-style: none; margin: 14px 0 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(104px, 1fr)); gap: 10px; }
.mu-thumb { position: relative; aspect-ratio: 1; border-radius: 1.1rem; overflow: hidden; background: var(--lg-soft); box-shadow: 0 8px 18px -10px rgba(15, 23, 42, 0.5); animation: mu-pop 0.28s ease backwards; }
.mu-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.mu-vid { width: 100%; height: 100%; display: grid; place-items: center; color: #fff; background: linear-gradient(145deg, #0f2540, #1f4f7a); }
.mu-thumb.is-uploaded { box-shadow: 0 0 0 2px #059669, 0 8px 18px -10px rgba(5, 150, 105, 0.6); }
.mu-thumb.is-failed { box-shadow: 0 0 0 2px #e11d48; }
.mu-shade { position: absolute; inset: 0; display: grid; place-items: center; background: rgba(8, 15, 28, 0.55); color: #fff; backdrop-filter: blur(1.5px); }
.mu-shade.is-bad { background: rgba(159, 18, 57, 0.78); align-content: center; justify-items: center; gap: 4px; padding: 6px; text-align: center; }
.mu-shade.is-bad small { font-size: 0.6rem; line-height: 1.15; max-height: 3.4em; overflow: hidden; }
.mu-ring { --p: 0; position: relative; width: 58px; height: 58px; border-radius: 9999px; display: grid; place-items: center; background: conic-gradient(#fff calc(var(--p) * 1%), rgba(255, 255, 255, 0.25) 0); }
.mu-ring::before { content: ''; position: absolute; inset: 5px; border-radius: inherit; background: rgba(8, 15, 28, 0.85); }
.mu-ring i { position: relative; font-style: normal; font-weight: 900; font-size: 0.78rem; font-variant-numeric: tabular-nums; }
.mu-done { position: absolute; right: 6px; top: 6px; width: 24px; height: 24px; display: grid; place-items: center; border-radius: 9999px; background: #059669; color: #fff; box-shadow: 0 4px 10px rgba(5, 150, 105, 0.5); }
.mu-x { position: absolute; right: 6px; top: 6px; width: 26px; height: 26px; display: grid; place-items: center; border: 0; border-radius: 9999px; background: rgba(8, 15, 28, 0.7); color: #fff; cursor: pointer; }
.mu-retry { position: absolute; left: 50%; bottom: 8px; transform: translateX(-50%); display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border: 0; border-radius: 9999px; background: #fff; color: #be123c; font-size: 0.7rem; font-weight: 800; cursor: pointer; }
.mu-cap { position: absolute; left: 6px; bottom: 6px; display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px; border-radius: 9999px; background: rgba(8, 15, 28, 0.62); color: #fff; font-size: 0.62rem; font-weight: 800; }
.mu-thumb.is-failed .mu-cap { display: none; }
.mu-empty { display: flex; align-items: center; justify-content: center; gap: 8px; margin: 14px 0 0; padding: 1.1rem; border: 2px dashed var(--lg-line); border-radius: 1.1rem; color: var(--lg-faint); font-size: 0.85rem; font-weight: 600; text-align: center; }
@keyframes mu-pop { from { opacity: 0; transform: scale(0.85); } }
@media (max-width: 575.98px) {
  .mu-tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .mu-tile.is-camera { grid-column: 1 / -1; flex-direction: row; align-items: center; gap: 12px; padding: 14px 16px; }
  .mu-tile.is-camera b { margin-top: 0; }
  .mu-tile.is-camera small { margin-left: auto; }
  .mu-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
}
@media (prefers-reduced-motion: reduce) { .mu-thumb, .mu-tile { animation: none; transition: none; } }
</style>

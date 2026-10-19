<template>
  <admin-layout :title="diary.reference">
    <div class="lg-page sdy px-4 md:px-0">
      <header class="lg-hero">
        <span class="lg-hero-icon"><icons name="camera" class="w-6 h-6" /></span>
        <div class="lg-hero-body">
          <h1 class="lg-title">{{ diary.project.name }} <small v-if="diary.project.code" class="sdy-code">({{ diary.project.code }})</small></h1>
          <p class="lg-sub">{{ diary.reference }} · {{ $date(diary.date) }} · {{ $t('Recorded by') }} {{ diary.recorder.name }}</p>
          <div class="sdy-chips">
            <span class="lg-chip lg-chip-blue" style="text-transform: none"><icons name="image" class="w-3 h-3" />{{ photoCount }} {{ $t('photo(s)') }}</span>
            <span v-if="limits.videos.enabled || videoCount" class="lg-chip lg-chip-indigo" style="text-transform: none"><icons name="video" class="w-3 h-3" />{{ videoCount }} {{ $t('video(s)') }}</span>
            <span class="lg-chip lg-chip-red lg-tip" style="text-transform: none" :data-tip="$t('Total size of all attachments on this diary')"><icons name="clip" class="w-3 h-3" />{{ fmt(diary.media_bytes || 0) }}</span>
            <span class="lg-chip lg-chip-green" style="text-transform: none">{{ diary.status }}</span>
          </div>
        </div>
        <div class="lg-hero-actions">
          <Link :href="route('site-diary.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('All') }}</Link>
          <a v-if="can.print && can.viewPhotos && photoCount" :href="route('site-diary.reports.gallery.print', { diary_id: diary.id })" target="_blank" class="lg-btn lg-btn-soft lg-tip" :data-tip="$t('Printable photo gallery report of this diary')"><icons name="printer" class="w-4 h-4" />{{ $t('Gallery report') }}</a>
          <offline-lock v-if="can.update || can.addPhotos || can.addVideos">
            <Link :href="route('site-diary.edit', diary.id)" class="lg-btn lg-btn-primary"><icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}</Link>
          </offline-lock>
          <offline-lock v-if="can.delete">
            <button type="button" class="lg-btn lg-btn-red" @click="askDelete"><icons name="trash" class="w-4 h-4" />{{ $t('Delete') }}</button>
          </offline-lock>
        </div>
      </header>

      <p v-if="diary.status === 'Draft'" class="lg-note lg-note-amber mb-3">
        <icons name="exclamation" class="w-4 h-4" />
        <span>{{ $t('This diary is a draft: it has no photo or video yet, so it is hidden from lists and reports. Add one below to save it.') }}</span>
      </p>

      <p v-if="drive_problem && (can.addPhotos || can.addVideos)" class="lg-note lg-note-amber mb-3">
        <icons name="exclamation" class="w-4 h-4" />
        <span>{{ $t('Google Drive Connection Problem, some files are saved in WIMS but not yet in Google Drive. Use the retry button on the file, or they will be retried automatically.') }}</span>
      </p>

      <section class="lg-card sdy-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Remarks') }}</h2></div>
        </header>
        <p v-if="diary.remarks" class="sdy-remarks">{{ diary.remarks }}</p>
        <p v-else class="lg-empty">{{ $t('No remarks were entered.') }}</p>
      </section>

      <diary-weather-panel v-if="weather" :weather="weather" />

      <section v-if="can.viewPhotos || can.viewVideos" class="lg-card sdy-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-teal"><icons name="image" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Photos & Videos') }}</h2><p class="lg-card-sub">{{ $t('Opened securely through WIMS. Every view and download is logged.') }}</p></div>
        </header>
        <media-gallery :items="mediaList" :can="can" :diary-id="diary.id" :delete-files="!!limits.delete_files_on_remove" @removed="onRemoved" @updated="onUpdated" />
      </section>

      <section v-if="canAddHere" class="lg-card sdy-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="upload" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Add photos / videos') }}</h2><p class="lg-card-sub">{{ $t('Existing files are never replaced, new ones are added.') }}</p></div>
        </header>
        <media-uploader ref="uploader" :limits="limits" :can="can" :existing="existingCounts" @uploaded="onUploaded" @queue-change="q => (queueInfo = q)" />
        <div class="sdy-submit">
          <offline-lock>
            <button type="button" class="lg-btn lg-btn-primary" :disabled="uploading || $offlineBlocked || !(queueInfo.waiting + queueInfo.failed)" @click="uploadNow">
              <icons name="upload" class="w-4 h-4" />{{ uploading ? $t('Uploading…') : $t('Upload files') }}
            </button>
          </offline-lock>
        </div>
        <p v-if="uploadMsg" class="lg-note mt-3" :class="uploadBad ? 'lg-note-red' : 'lg-note-blue'"><icons name="info" class="w-4 h-4" /><span>{{ uploadMsg }}</span></p>
      </section>

      <section v-if="history" id="history" class="lg-card sdy-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('History') }}</h2><p class="lg-card-sub">{{ $t('Everything that happened to this diary. History is never deleted.') }}</p></div>
        </header>
        <div v-if="viewers" class="sdy-viewed">
          <b class="sdy-viewed-k"><icons name="eye-flat" class="w-4 h-4" />{{ $t('Viewed by') }}</b>
          <span v-if="!viewers.length" class="lg-subtext">{{ $t('Nobody else has viewed this diary yet.') }}</span>
          <span v-for="v in viewers" :key="v.id" class="lg-chip lg-chip-slate lg-tip" style="text-transform: none" :data-tip="v.views + '× · ' + $t('last') + ' ' + $datetime(v.last_at)">{{ v.name }} <small>· {{ v.views }}×</small></span>
        </div>
        <p v-if="!history.length" class="lg-empty">{{ $t('No history recorded yet.') }}</p>
        <ol v-else class="sdy-timeline">
          <li v-for="h in history" :key="h.id">
            <span class="sdy-dot" :class="dotClass(h.action)"></span>
            <div>
              <div class="sdy-tl-head"><span class="sdy-tl-desc">{{ h.description }}</span><span class="sdy-tl-time">{{ $datetime(h.at) }}</span></div>
              <div class="sdy-tl-meta"><span class="lg-chip lg-chip-slate" style="text-transform: none">{{ h.action.replace(/_/g, ' ') }}</span><span v-if="h.device">{{ h.device }}</span><span v-if="h.ip">{{ h.ip }}</span></div>
            </div>
          </li>
        </ol>
      </section>
    </div>

    <!-- delete -->
    <modal :show="deleting" max-width="md" @close="deleting = false">
      <div class="sdy-modal">
        <h3 class="sdy-modal-title"><icons name="trash" class="w-5 h-5" />{{ $t('Delete this Site Diary?') }}</h3>
        <p class="sdy-modal-sub">{{ $t('The diary is moved out of the lists (it is not destroyed) and the deletion is logged with your reason.') }}</p>
        <label class="sdy-label">{{ $t('Reason') }}</label>
        <textarea v-model="del.reason" rows="3" class="sdy-textarea" :placeholder="$t('Why is this diary being deleted?')"></textarea>
        <template v-if="limits.password_on_delete">
          <label class="sdy-label mt-3">{{ $t('Confirm with your password') }}</label>
          <input v-model="del.password" type="password" autocomplete="current-password" class="sdy-input" @keydown.enter.prevent="doDelete" />
        </template>
        <p v-if="del.error" class="sdy-err">{{ del.error }}</p>
        <div class="sdy-modal-actions">
          <button type="button" class="lg-btn lg-btn-soft" @click="deleting = false">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-red" :disabled="del.busy || del.reason.trim().length < 3" @click="doDelete"><icons name="trash" class="w-4 h-4" />{{ del.busy ? $t('Deleting…') : $t('Delete diary') }}</button>
        </div>
      </div>
    </modal>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Modal from '@/Jetstream/Modal.vue';
import MediaGallery from '@/Shared/SiteDiary/MediaGallery.vue';
import MediaUploader from '@/Shared/SiteDiary/MediaUploader.vue';
import DiaryWeatherPanel from '@/Shared/Weather/DiaryWeatherPanel.vue';
import { formatBytes } from '@/Core/archiveFormat';

export default {
  components: { AdminLayout, Link, Modal, MediaGallery, MediaUploader, DiaryWeatherPanel },

  props: { diary: Object, media: Array, history: Array, viewers: Array, can: Object, limits: Object, drive_problem: Boolean, weather: { type: Object, default: null } },

  data() {
    return {
      mediaList: [...this.media],
      queueInfo: { waiting: 0, failed: 0 },
      uploading: false,
      uploadMsg: null,
      uploadBad: false,
      deleting: false,
      del: { reason: '', password: '', error: null, busy: false },
    };
  },

  computed: {
    photoCount() {
      return this.diary.photos_count;
    },
    videoCount() {
      return this.diary.videos_count;
    },
    canAddHere() {
      return (this.can.addPhotos && this.limits.photos.enabled) || (this.can.addVideos && this.limits.videos.enabled);
    },
    existingCounts() {
      return { photos: this.diary.photos_count, videos: this.diary.videos_count };
    },
  },

  methods: {
    fmt: formatBytes,
    onUploaded({ media, photos_count, videos_count }) {
      this.mediaList.push(media);
      this.diary.media_bytes = (this.diary.media_bytes || 0) + (media.size || 0);
      this.diary.photos_count = photos_count;
      this.diary.videos_count = videos_count;
    },
    onRemoved({ media, counts }) {
      this.mediaList = this.mediaList.filter(m => m.id !== media.id);
      this.diary.media_bytes = Math.max(0, (this.diary.media_bytes || 0) - (media.size || 0));
      this.diary.photos_count = counts.photos_count;
      this.diary.videos_count = counts.videos_count;
    },
    onUpdated(media) {
      const i = this.mediaList.findIndex(m => m.id === media.id);
      if (i !== -1) this.mediaList.splice(i, 1, { ...this.mediaList[i], ...media });
    },
    async uploadNow() {
      this.uploading = true;
      this.uploadMsg = null;
      const res = await this.$refs.uploader.upload(this.route('site-diary.media.store', this.diary.id));
      if (res.photos + res.videos > 0) {
        try {
          await window.axios.post(this.route('site-diary.uploads-complete', this.diary.id), { photos: res.photos, videos: res.videos });
        } catch (e) { /* the files are saved; only the notification failed */ }
        // a draft has just become a saved diary: reload everything (status, counts, history)
        this.$inertia.reload({ preserveScroll: true, onSuccess: () => { this.mediaList = [...this.media]; } });
      }
      this.uploadBad = res.failed > 0;
      this.uploadMsg = res.failed > 0
        ? this.$t(':n file(s) could not be uploaded, use the retry button next to the file.', { n: res.failed })
        : this.$t('Uploaded.');
      this.uploading = false;
    },
    askDelete() {
      this.del = { reason: '', password: '', error: null, busy: false };
      this.deleting = true;
    },
    async doDelete() {
      if (this.$offlineBlocked) return;
      this.del.busy = true;
      this.del.error = null;
      try {
        const { data } = await window.axios.delete(this.route('site-diary.destroy', this.diary.id), { data: { reason: this.del.reason, password: this.del.password } });
        this.$inertia.visit(data.url);
      } catch (e) {
        const errs = e.response && e.response.data && e.response.data.errors;
        this.del.error = (errs && Object.values(errs)[0][0]) || (e.response && e.response.data && e.response.data.message) || this.$t('Could not delete the diary.');
      } finally {
        this.del.busy = false;
      }
    },
    dotClass(a) {
      if (/DOWNLOAD/.test(a)) return 'is-download';
      if (/VIEW|OPEN/.test(a)) return 'is-view';
      if (/DELETE/.test(a)) return 'is-delete';
      if (/FAILED/.test(a)) return 'is-delete';
      if (/ADD|CREATE|UPLOAD/.test(a)) return 'is-add';
      return '';
    },
  },
};
</script>

<style scoped>
.sdy-card { margin-bottom: 1rem; }
.sdy-code { font-size: 0.7em; font-weight: 600; opacity: 0.7; }
.sdy-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.sdy-remarks { margin: 0; white-space: pre-wrap; word-break: break-word; font-size: 0.95rem; line-height: 1.6; color: var(--lg-ink); }
.sdy-submit { display: flex; justify-content: flex-end; margin-top: 0.9rem; }
.sdy-viewed { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 8px; margin-bottom: 1rem; padding: 0.7rem 0.9rem; border-radius: 0.9rem; background: var(--lg-soft); }
.sdy-viewed-k { display: inline-flex; align-items: center; gap: 6px; font-size: 0.74rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--lg-faint); margin-right: 4px; }
.sdy-viewed small { opacity: 0.75; }
.sdy-timeline { margin: 0; padding: 0 0 0 4px; list-style: none; }
.sdy-timeline li { position: relative; display: flex; gap: 12px; padding: 0 0 16px 18px; border-left: 2px solid var(--lg-line); margin-left: 6px; }
.sdy-timeline li:last-child { border-left-color: transparent; padding-bottom: 0; }
.sdy-dot { position: absolute; left: -7px; top: 3px; width: 12px; height: 12px; border-radius: 9999px; background: #64748b; box-shadow: 0 0 0 3px var(--lg-card); }
.sdy-dot.is-view { background: #0284c7; } .sdy-dot.is-download { background: #d97706; } .sdy-dot.is-add { background: #059669; } .sdy-dot.is-delete { background: #e11d48; }
.sdy-tl-head { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 12px; }
.sdy-tl-desc { font-weight: 700; font-size: 0.85rem; color: var(--lg-ink); }
.sdy-tl-time { font-size: 0.72rem; color: var(--lg-faint); white-space: nowrap; }
.sdy-tl-meta { display: flex; flex-wrap: wrap; gap: 4px 10px; margin-top: 4px; font-size: 0.72rem; color: var(--lg-mute); }
.sdy-modal { padding: 1.4rem; }
.sdy-modal-title { display: flex; align-items: center; gap: 8px; margin: 0 0 4px; font-size: 1.05rem; font-weight: 800; color: #be123c; }
.sdy-modal-sub { margin: 0 0 1rem; font-size: 0.82rem; color: #64748b; }
.sdy-label { display: block; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: #64748b; margin-bottom: 4px; }
.sdy-textarea, .sdy-input { width: 100%; padding: 0.6rem 0.8rem; border-radius: 0.8rem; border: 1px solid #cbd5e1; font-size: 0.9rem; }
.sdy-err { margin: 0.6rem 0 0; font-size: 0.8rem; font-weight: 700; color: #be123c; }
.sdy-modal-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 1.1rem; }
</style>

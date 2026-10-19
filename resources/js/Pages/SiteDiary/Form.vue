<template>
  <admin-layout :title="isEdit ? $t('Edit Site Diary') : $t('New Site Diary')">
    <div class="lg-page sdy sdf px-4 md:px-0">
      <!-- app header -->
      <header class="sdf-hero">
        <Link :href="isEdit ? route('site-diary.show', diary.id) : route('site-diary.index')" class="sdf-back" :aria-label="$t('Back')"><icons name="chevron-down" class="w-5 h-5 transform rotate-90" /></Link>
        <div class="sdf-hero-main">
          <span class="sdf-hero-ico"><icons name="camera" class="w-6 h-6" /></span>
          <div class="min-w-0">
            <h1>{{ isEdit ? diary.reference : $t('New Site Diary') }}</h1>
            <p>{{ projectName || $t('Pick the project, add photos, save.') }}</p>
          </div>
        </div>
        <div class="sdf-hero-chips">
          <span class="sdf-chip"><icons name="calendar" class="w-3.5 h-3.5" />{{ $date(form.diary_date || today) }}</span>
          <span class="sdf-chip"><span class="sdf-avatar">{{ initials }}</span>{{ recorder }}</span>
        </div>
        <nav class="sdf-steps" aria-label="progress">
          <span v-for="(s, i) in steps" :key="s.key" class="sdf-step" :class="{ 'is-done': s.done, 'is-now': activeStep === i }">
            <i><icons v-if="s.done" name="tick" class="w-3 h-3" /><template v-else>{{ i + 1 }}</template></i>{{ s.label }}
          </span>
        </nav>
      </header>

      <p v-if="$offlineBlocked" class="lg-note lg-note-amber mb-3"><icons name="wifi" class="w-4 h-4" /><span>{{ $t('You are offline. A Site Diary can only be saved while WIMS is online.') }}</span></p>

      <form @submit.prevent="save">
        <!-- 1 · project -->
        <section class="sdf-card" :class="{ 'has-error': errors.warehouse_id }">
          <h2 class="sdf-h"><span class="sdf-ico is-orange"><icons name="building" class="w-5 h-5" /></span><span>{{ $t('Where are you working?') }}<small>{{ $t('Project / Site') }}</small></span></h2>
          <auto-complete
            id="sd_project"
            v-model="form.warehouse_id"
            :suggestions="projects"
            :disable="isEdit && !can.changeProject"
            :error="errors.warehouse_id"
            :placeholder="$t('Search project…')"
          />
          <div class="sdf-tiles">
            <div v-if="can.backdate" class="sdf-tile sdf-tile-picker">
              <date-picker :label="$t('Date')" v-model="form.diary_date" :max="today" :error="errors.diary_date" :clearable="false" />
            </div>
            <div v-else class="sdf-tile">
              <span class="sdf-tile-ico is-sky"><icons name="calendar" class="w-5 h-5" /></span>
              <span class="sdf-tile-t"><small>{{ $t('Date') }}</small><b>{{ $date(isEdit ? diary.date : today) }}</b></span>
            </div>
            <div class="sdf-tile">
              <span class="sdf-tile-ico is-violet"><span class="sdf-avatar is-big">{{ initials }}</span></span>
              <span class="sdf-tile-t"><small>{{ $t('Recorded by') }}</small><b>{{ recorder }}</b></span>
            </div>
          </div>
        </section>

        <!-- saved media (edit / after the first save) -->
        <section v-if="mediaList.length" class="sdf-card">
          <h2 class="sdf-h"><span class="sdf-ico is-teal"><icons name="image" class="w-5 h-5" /></span><span>{{ $t('Saved media') }}<small>{{ $t('Removing a file is logged. The original stays in Google Drive.') }}</small></span></h2>
          <media-gallery :items="mediaList" :can="can" :diary-id="diaryId" :delete-files="!!limits.delete_files_on_remove" @removed="onRemoved" @updated="onUpdated" />
        </section>

        <!-- 2 · capture -->
        <section v-if="showUploader" class="sdf-card" :class="{ 'has-error': errors.media }">
          <h2 class="sdf-h"><span class="sdf-ico is-violet"><icons name="camera" class="w-5 h-5" /></span><span>{{ $t('Photos & Videos') }}<b v-if="limits.require_media" class="sdy-req"> *</b><small>{{ limits.require_media ? $t('At least one photo or video is required.') : $t('Sent to secure Google Drive storage when you save.') }}</small></span></h2>
          <media-uploader ref="uploader" :limits="limits" :can="can" :existing="existingCounts" @uploaded="onUploaded" @queue-change="q => (queueInfo = q)" @batch-done="finishBatch" />
          <p v-if="errors.media" class="sdf-err"><icons name="exclamation" class="w-4 h-4" />{{ errors.media }}</p>
        </section>

        <!-- 3 · remarks -->
        <section class="sdf-card" :class="{ 'has-error': errors.remarks }">
          <h2 class="sdf-h"><span class="sdf-ico is-amber"><icons name="doc-text" class="w-5 h-5" /></span><span>{{ $t('Remarks') }}<b v-if="limits.remarks_required" class="sdy-req"> *</b><small>{{ $t('What happened on site today?') }}</small></span></h2>
          <div class="sdf-quick">
            <button v-for="q in quick" :key="q" type="button" class="sdf-q" :disabled="isEdit && !can.update" @click="addQuick(q)">{{ q }}</button>
          </div>
          <textarea id="sd_remarks" v-model="form.remarks" rows="4" class="sdf-textarea" :readonly="isEdit && !can.update" :maxlength="limits.remarks_max" :placeholder="$t('Work progress, site conditions, deliveries, problems, delays…')"></textarea>
          <div class="sdf-under">
            <span v-if="errors.remarks" class="sdf-err-t">{{ errors.remarks }}</span>
            <span class="sdy-count">{{ (form.remarks || '').length }} / {{ limits.remarks_max }}</span>
          </div>
        </section>

        <p v-if="notice" class="lg-note mb-3" :class="noticeBad ? 'lg-note-red' : 'lg-note-blue'"><icons name="info" class="w-4 h-4" /><span>{{ notice }}</span></p>

        <!-- floating action bar -->
        <div class="sdf-bar">
          <div class="sdf-bar-sum">
            <span class="sdf-bar-ico"><icons name="camera" class="w-5 h-5" /></span>
            <span class="sdf-bar-t"><b>{{ summaryTitle }}</b><small>{{ summarySub }}</small></span>
          </div>
          <div class="sdf-bar-btns">
            <Link :href="isEdit ? route('site-diary.show', diary.id) : route('site-diary.index')" class="sdf-btn is-ghost">{{ $t('Cancel') }}</Link>
            <Link v-if="createdId && !isEdit" :href="route('site-diary.show', createdId)" class="sdf-btn is-ghost">{{ $t('Open the diary') }}</Link>
            <offline-lock>
              <button type="submit" class="sdf-btn is-save" :disabled="saving || $offlineBlocked">
                <icons name="tick" class="w-5 h-5" />{{ saving ? (stage || $t('Saving…')) : $t('Save Site Diary') }}
              </button>
            </offline-lock>
          </div>
        </div>
        <div class="sdf-spacer" aria-hidden="true"></div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import MediaUploader from '@/Shared/SiteDiary/MediaUploader.vue';
import MediaGallery from '@/Shared/SiteDiary/MediaGallery.vue';

export default {
  components: { AdminLayout, Link, AutoComplete, DatePicker, MediaUploader, MediaGallery },

  props: {
    diary: Object,
    projects: Array,
    preset_project: [Number, String],
    today: String,
    recorder: String,
    media: Array,
    can: Object,
    limits: Object,
  },

  data() {
    return {
      form: {
        warehouse_id: this.diary ? this.diary.project.id : this.preset_project || null,
        diary_date: this.diary ? this.diary.date : this.today,
        remarks: this.diary ? this.diary.remarks || '' : '',
      },
      errors: {},
      saving: false,
      stage: null,
      notice: null,
      noticeBad: false,
      createdId: null, // set once the diary row exists, so a retry after a failed upload updates instead of duplicating
      mediaList: [...(this.media || [])],
      queueInfo: { waiting: 0, failed: 0 },
    };
  },

  computed: {
    isEdit() {
      return !!this.diary;
    },
    diaryId() {
      return this.diary ? this.diary.id : this.createdId || 0;
    },
    showUploader() {
      return (this.can.addPhotos && this.limits.photos.enabled) || (this.can.addVideos && this.limits.videos.enabled);
    },
    existingCounts() {
      return { photos: this.mediaList.filter(m => m.type === 'photo').length, videos: this.mediaList.filter(m => m.type === 'video').length };
    },
    initials() {
      return (this.recorder || '?').split(/\s+/).map(w => w[0]).filter(Boolean).slice(0, 2).join('').toUpperCase();
    },
    projectName() {
      const p = this.projects.find(x => x.value === this.form.warehouse_id);
      return p ? p.name : '';
    },
    attachCount() {
      return this.mediaList.length + this.queueInfo.waiting + this.queueInfo.failed;
    },
    steps() {
      return [
        { key: 'p', label: this.$t('Project'), done: !!this.form.warehouse_id },
        { key: 'm', label: this.$t('Capture'), done: this.attachCount > 0 },
        { key: 'r', label: this.$t('Remarks'), done: (this.form.remarks || '').trim().length > 0 },
      ];
    },
    activeStep() {
      const i = this.steps.findIndex(s => !s.done);
      return i === -1 ? this.steps.length : i;
    },
    summaryTitle() {
      const n = this.attachCount;
      return n ? this.$t(':n attachment(s) ready', { n }) : this.$t('No photos yet');
    },
    summarySub() {
      return this.projectName || this.$t('Choose a project to continue');
    },
    quick() {
      return [this.$t('Concrete works'), this.$t('Reinforcement'), this.$t('Blockwork'), this.$t('Material delivery'), this.$t('Safety talk'), this.$t('Weather delay'), this.$t('Inspection'), this.$t('Site clean-up')];
    },
  },

  methods: {
    onUploaded({ media }) {
      this.mediaList.push(media);
    },
    onRemoved({ media }) {
      this.mediaList = this.mediaList.filter(m => m.id !== media.id);
    },
    onUpdated(media) {
      const i = this.mediaList.findIndex(m => m.id === media.id);
      if (i !== -1) this.mediaList.splice(i, 1, { ...this.mediaList[i], ...media });
    },
    finishBatch() {},
    addQuick(text) {
      const cur = (this.form.remarks || '').trimEnd();
      this.form.remarks = (cur ? cur + '. ' : '') + text + ': ';
      this.$nextTick(() => { const el = document.getElementById('sd_remarks'); if (el) { el.focus(); el.setSelectionRange(el.value.length, el.value.length); } });
    },

    async save() {
      if (this.saving || this.$offlineBlocked) return;
      this.saving = true;
      this.errors = {};
      this.notice = null;
      // the attachment rule: stop here, before anything is saved, when there is nothing to attach
      if (this.limits.require_media && this.attachCount === 0) {
        this.errors = { media: this.$t('Add at least one photo or video before saving the diary.') };
        this.saving = false;
        this.stage = null;
        this.$nextTick(() => { const el = this.$el.querySelector('.mu'); if (el && el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'center' }); });
        return;
      }
      this.stage = this.$t('Saving diary…');
      try {
        const existingId = this.diary ? this.diary.id : this.createdId;
        const payload = { warehouse_id: this.form.warehouse_id, diary_date: this.form.diary_date, remarks: this.form.remarks };
        // someone who may only add media to a diary (no edit right) skips the details update and just uploads
        const { data } = existingId
          ? (this.can.update || this.createdId
              ? await window.axios.put(this.route('site-diary.update', existingId), payload)
              : { data: { id: existingId, url: this.route('site-diary.show', existingId), upload_url: this.route('site-diary.media.store', existingId) } })
          : await window.axios.post(this.route('site-diary.store'), payload);
        const wasNew = !existingId;
        if (wasNew) this.createdId = data.id;

        let res = { photos: 0, videos: 0, failed: 0 };
        const uploader = this.$refs.uploader;
        if (uploader && uploader.hasWaiting()) {
          this.stage = this.$t('Uploading files…');
          res = await uploader.upload(data.upload_url);
        }
        // always confirm: this is what turns a draft into a saved diary (it checks the attachment server-side)
        await window.axios.post(this.route('site-diary.uploads-complete', data.id), { photos: res.photos, videos: res.videos });
        if (res.failed > 0) {
          this.noticeBad = true;
          this.notice = this.$t(':n file(s) could not be uploaded. The diary is saved, use the retry button on the file, or Save again.', { n: res.failed });
          return;
        }
        this.$inertia.visit(data.url);
      } catch (e) {
        const r = e.response;
        if (r && r.status === 422 && r.data && r.data.errors) {
          this.errors = Object.fromEntries(Object.entries(r.data.errors).map(([k, v]) => [k, v[0]]));
        } else {
          this.noticeBad = true;
          this.notice = (r && r.data && r.data.message) || this.$t('The diary could not be saved. Please try again.');
        }
      } finally {
        this.saving = false;
        this.stage = null;
      }
    },
  },
};
</script>

<style scoped>
.sdy-req { color: #e11d48; }
.sdy-count { margin-left: auto; color: var(--lg-faint); font-size: 0.72rem; }

/* header */
.sdf-hero { position: relative; overflow: hidden; margin-bottom: 1rem; padding: 1.1rem 1.2rem 1rem; border-radius: 1.6rem; color: #fff; background: linear-gradient(135deg, #0f2540 0%, #1f4f7a 55%, #0f766e 100%); box-shadow: 0 22px 40px -22px rgba(15, 37, 64, 0.85); display: grid; gap: 0.9rem; }
.sdf-hero::before, .sdf-hero::after { content: ''; position: absolute; border-radius: 9999px; background: rgba(255, 255, 255, 0.07); pointer-events: none; }
.sdf-hero::before { width: 220px; height: 220px; right: -60px; top: -90px; }
.sdf-hero::after { width: 150px; height: 150px; right: 120px; bottom: -90px; }
.sdf-back { position: absolute; left: 12px; top: 12px; z-index: 2; width: 38px; height: 38px; display: none; place-items: center; border-radius: 9999px; background: rgba(255, 255, 255, 0.16); color: #fff; }
.sdf-hero-main { position: relative; display: flex; align-items: center; gap: 12px; min-width: 0; }
.sdf-hero-ico { flex: none; width: 52px; height: 52px; display: grid; place-items: center; border-radius: 1.1rem; background: rgba(255, 255, 255, 0.18); box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.3); }
.sdf-hero h1 { margin: 0; font-size: 1.35rem; font-weight: 900; letter-spacing: -0.01em; }
.sdf-hero p { margin: 2px 0 0; font-size: 0.85rem; opacity: 0.85; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sdf-hero-chips { position: relative; display: flex; flex-wrap: wrap; gap: 8px; }
.sdf-chip { display: inline-flex; align-items: center; gap: 7px; padding: 5px 12px 5px 8px; border-radius: 9999px; background: rgba(255, 255, 255, 0.16); font-size: 0.78rem; font-weight: 700; backdrop-filter: blur(4px); }
.sdf-avatar { display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 9999px; background: #fff; color: #0f2540; font-size: 0.62rem; font-weight: 900; }
.sdf-avatar.is-big { width: 100%; height: 100%; font-size: 0.85rem; background: transparent; color: #fff; }
.sdf-steps { position: relative; display: flex; gap: 8px; overflow-x: auto; scrollbar-width: none; }
.sdf-steps::-webkit-scrollbar { display: none; }
.sdf-step { flex: none; display: inline-flex; align-items: center; gap: 7px; padding: 5px 13px 5px 6px; border-radius: 9999px; background: rgba(255, 255, 255, 0.1); font-size: 0.78rem; font-weight: 700; opacity: 0.75; }
.sdf-step i { display: inline-grid; place-items: center; width: 20px; height: 20px; border-radius: 9999px; background: rgba(255, 255, 255, 0.22); font-style: normal; font-size: 0.68rem; font-weight: 900; }
.sdf-step.is-now { opacity: 1; background: rgba(255, 255, 255, 0.22); }
.sdf-step.is-done { opacity: 1; }
.sdf-step.is-done i { background: #34d399; color: #064e3b; }

/* cards */
.sdf-card { margin-bottom: 1rem; padding: 1.1rem; border-radius: 1.6rem; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: 0 18px 36px -24px rgba(15, 23, 42, 0.45); animation: sdf-rise 0.4s ease backwards; }
.sdf-card:nth-of-type(2) { animation-delay: 0.05s; }
.sdf-card:nth-of-type(3) { animation-delay: 0.1s; }
.sdf-card.has-error { border-color: rgba(225, 29, 72, 0.55); box-shadow: 0 0 0 3px rgba(225, 29, 72, 0.1); }
.sdf-h { display: flex; align-items: center; gap: 12px; margin: 0 0 0.9rem; font-size: 1.02rem; font-weight: 900; color: var(--lg-ink); }
.sdf-h small { display: block; margin-top: 1px; font-size: 0.74rem; font-weight: 600; color: var(--lg-faint); }
.sdf-ico { flex: none; width: 44px; height: 44px; display: grid; place-items: center; border-radius: 1.05rem; color: #fff; box-shadow: 0 10px 18px -10px var(--sh, rgba(15, 23, 42, 0.5)); }
.sdf-ico.is-orange { background: linear-gradient(145deg, #fb923c, #ea580c); --sh: rgba(234, 88, 12, 0.8); }
.sdf-ico.is-violet { background: linear-gradient(145deg, #8b5cf6, #5b3df5); --sh: rgba(91, 61, 245, 0.8); }
.sdf-ico.is-teal { background: linear-gradient(145deg, #14b8a6, #0f766e); --sh: rgba(15, 118, 110, 0.8); }
.sdf-ico.is-amber { background: linear-gradient(145deg, #fbbf24, #d97706); --sh: rgba(217, 119, 6, 0.8); }

/* date / recorder tiles */
.sdf-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 10px; margin-top: 12px; }
.sdf-tile { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 1.1rem; background: var(--lg-soft); border: 1px solid var(--lg-line); min-width: 0; }
.sdf-tile-picker { display: block; padding: 6px 12px; }
.sdf-tile-ico { flex: none; width: 40px; height: 40px; display: grid; place-items: center; border-radius: 0.95rem; color: #fff; overflow: hidden; }
.sdf-tile-ico.is-sky { background: linear-gradient(145deg, #38bdf8, #0284c7); }
.sdf-tile-ico.is-violet { background: linear-gradient(145deg, #8b5cf6, #5b3df5); }
.sdf-tile-t { display: flex; flex-direction: column; min-width: 0; }
.sdf-tile-t small { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.sdf-tile-t b { font-size: 0.95rem; color: var(--lg-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* remarks */
.sdf-quick { display: flex; flex-wrap: wrap; gap: 7px; margin-bottom: 10px; }
.sdf-q { padding: 6px 13px; border-radius: 9999px; border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-mute); font-size: 0.78rem; font-weight: 700; cursor: pointer; transition: all 0.15s ease; touch-action: manipulation; }
.sdf-q:hover { border-color: #d97706; color: #b45309; background: rgba(217, 119, 6, 0.08); }
.sdf-q:disabled { opacity: 0.5; pointer-events: none; }
.sdf-textarea { width: 100%; padding: 0.8rem 1rem; border-radius: 1.1rem; border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-ink); font-size: 0.95rem; line-height: 1.55; resize: vertical; }
.sdf-textarea:focus { outline: 2px solid #d97706; outline-offset: 1px; }
.sdf-under { display: flex; justify-content: space-between; gap: 8px; margin-top: 4px; }
.sdf-err { display: flex; align-items: center; gap: 7px; margin: 0.8rem 0 0; font-size: 0.84rem; font-weight: 800; color: #be123c; }
.sdf-err-t { font-size: 0.78rem; font-weight: 700; color: #be123c; }

/* floating action bar */
.sdf-bar { position: sticky; bottom: 12px; z-index: 30; display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 0.4rem; padding: 10px 12px 10px 14px; border-radius: 1.5rem; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: 0 -4px 6px -4px rgba(15, 23, 42, 0.1), 0 22px 40px -12px rgba(15, 23, 42, 0.55); }
.sdf-bar-sum { display: flex; align-items: center; gap: 10px; min-width: 0; }
.sdf-bar-ico { flex: none; width: 40px; height: 40px; display: grid; place-items: center; border-radius: 0.95rem; color: #fff; background: linear-gradient(145deg, #fb923c, #ea580c); }
.sdf-bar-t { display: flex; flex-direction: column; min-width: 0; }
.sdf-bar-t b { font-size: 0.9rem; color: var(--lg-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sdf-bar-t small { font-size: 0.72rem; color: var(--lg-faint); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sdf-bar-btns { display: flex; align-items: center; gap: 8px; flex: none; }
.sdf-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 3rem; padding: 0 1.3rem; border: 0; border-radius: 1.1rem; font-size: 0.92rem; font-weight: 800; cursor: pointer; text-decoration: none; touch-action: manipulation; transition: transform 0.12s ease, box-shadow 0.12s ease; }
.sdf-btn:active { transform: scale(0.97); }
.sdf-btn.is-ghost { background: var(--lg-soft); color: var(--lg-mute); }
.sdf-btn.is-save { color: #fff; background: linear-gradient(145deg, #10b981, #047857); box-shadow: 0 14px 24px -12px rgba(4, 120, 87, 0.9); min-width: 11rem; }
.sdf-btn.is-save:disabled { opacity: 0.6; }
@keyframes sdf-rise { from { opacity: 0; transform: translateY(10px); } }

@media (max-width: 767.98px) {
  .sdf-back { display: grid; }
  .sdf-hero { padding-top: 3.6rem; margin-left: -0.25rem; margin-right: -0.25rem; border-radius: 1.4rem; }
  /* the page scrolls inside the layout, so on phones the bar is fixed to the screen (sticky would not hold) */
  .sdf-bar { position: fixed; left: 10px; right: 10px; bottom: calc(10px + env(safe-area-inset-bottom, 0px)); margin: 0; padding: 8px; z-index: 60; }
  .sdf-spacer { height: 5.5rem; }
  .sdf-bar-sum { display: none; }
  .sdf-bar-btns { width: 100%; }
  .sdf-bar-btns .sdf-btn.is-save { flex: 1; }
  .sdf-bar-btns > span { flex: 1; display: flex; }
  .sdf-bar-btns > span .sdf-btn { flex: 1; }
}
@media (max-width: 420px) { .sdf-bar-btns .is-ghost { display: none; } }
@media (prefers-reduced-motion: reduce) { .sdf-card { animation: none; } }
</style>

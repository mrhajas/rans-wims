<template>
  <admin-layout :title="record.reference">
    <!-- Confidential / Restricted: nothing about the record is sent until the password is confirmed. -->
    <div v-if="locked" class="lg-page arx ar-page px-4 md:px-0">
      <div class="lg-card arx-lockcard arx-pop">
        <folder-icon tone="rose" :size="88" locked />
        <h3>{{ $t('Confidential record') }} · {{ record.reference }}</h3>
        <p>{{ $t('This record is') }} {{ record.confidentiality }}. {{ $t('Enter your WIMS password to open it. It stays open for') }} {{ minutes }} {{ $t('minutes.') }}</p>
        <form class="arx-pw" :class="{ 'arx-shake': shake }" style="width: min(360px, 100%); padding: 0" @submit.prevent="submitUnlock">
          <input v-model="password" type="password" autocomplete="current-password" :placeholder="$t('Your password')" autofocus />
          <p v-if="unlockError" style="color: #be123c; font-size: 0.8rem; font-weight: 600; margin: 0">{{ unlockError }}</p>
          <button type="submit" class="lg-btn lg-btn-primary" :disabled="busy || !password"><icons name="lock-open" class="w-4 h-4" />{{ busy ? $t('Checking…') : $t('Unlock record') }}</button>
        </form>
        <Link :href="route('archive.records.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('All Records') }}</Link>
      </div>
    </div>

    <div v-else class="lg-page arx ar-page px-4 md:px-0">
      <header class="lg-hero print:hidden">
        <span class="lg-hero-icon"><icons name="folder" class="w-6 h-6" /></span>
        <div class="lg-hero-body">
          <h1 class="lg-title">{{ record.title }}</h1>
          <p class="lg-sub">{{ record.reference }} &middot; {{ record.category }}<span v-if="record.document_type"> &middot; {{ record.document_type }}</span></p>
          <div class="ar-chips">
            <span class="lg-chip" :class="statusChip(record.status)" style="text-transform: none">{{ record.status }}</span>
            <span class="lg-chip" :class="confChip(record.confidentiality)" style="text-transform: none"><icons v-if="restricted" name="lock-closed" class="w-3 h-3" />{{ record.confidentiality }}</span>
            <span v-if="record.is_expired" class="lg-chip lg-chip-red" style="text-transform: none">{{ $t('Expired') }} {{ $date(record.expiry_date) }}</span>
            <span v-else-if="record.expires_soon" class="lg-chip lg-chip-amber" style="text-transform: none">{{ $t('Expires') }} {{ $date(record.expiry_date) }}</span>
          </div>
        </div>
        <div class="lg-hero-actions">
          <Link :href="route('archive.records.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('All Records') }}</Link>
          <a v-if="can.print" :href="route('archive.records.print', record.id)" target="_blank" rel="noopener" class="lg-btn lg-btn-green lg-tip" :data-tip="$t('Print the record sheet (logged)')"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
          <Link v-if="can.update" :href="route('archive.records.edit', record.id)" class="lg-btn lg-btn-primary"><icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}</Link>
          <button v-if="can.delete" type="button" class="lg-btn lg-btn-red" @click="remove"><icons name="trash" class="w-4 h-4" />{{ $t('Delete') }}</button>
        </div>
      </header>

      <div class="ar-tabs" role="tablist">
        <button v-for="t in tabs" :key="t.key" type="button" role="tab" class="ar-tab" :class="{ 'is-active': tab === t.key }" :aria-selected="tab === t.key" @click="setTab(t.key)">
          <icons :name="t.icon" class="w-4 h-4" />{{ t.label }}<span v-if="t.count !== undefined" class="ar-tab-count">{{ t.count }}</span>
        </button>
      </div>

      <!-- Details -->
      <div v-if="tab === 'details'" class="ar-grid">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0"><h3 class="lg-card-title">{{ $t('Document') }}</h3></div></header>
          <dl class="ar-dl">
            <div><dt>{{ $t('Reference') }}</dt><dd>{{ record.reference }}</dd></div>
            <div><dt>{{ $t('Document RefNo') }}</dt><dd>{{ record.reference_number || '—' }}</dd></div>
            <div><dt>{{ $t('Category') }}</dt><dd>{{ record.category }}<span v-if="record.subcategory"> / {{ record.subcategory }}</span></dd></div>
            <div><dt>{{ $t('Document Type') }}</dt><dd>{{ record.document_type || '—' }}</dd></div>
            <div class="ar-wide"><dt>{{ $t('Description') }}</dt><dd class="ar-text">{{ record.description || '—' }}</dd></div>
          </dl>
        </section>
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="building" class="w-5 h-5" /></span><div class="min-w-0"><h3 class="lg-card-title">{{ $t('Source & Parties') }}</h3></div></header>
          <dl class="ar-dl">
            <div><dt>{{ $t('Source') }}</dt><dd>{{ record.source_type || '—' }}</dd></div>
            <div><dt>{{ $t('Organization') }}</dt><dd>{{ record.organization || '—' }}</dd></div>
            <div><dt>{{ $t('Sender') }}</dt><dd>{{ record.sender || '—' }}</dd></div>
            <div><dt>{{ $t('Recipient') }}</dt><dd>{{ record.recipient || '—' }}</dd></div>
            <div><dt>{{ $t('Project') }}</dt><dd>{{ record.project || '—' }}</dd></div>
            <div><dt>{{ $t('Client / Bank / Supplier') }}</dt><dd>{{ record.contact || '—' }}</dd></div>
            <div><dt>{{ $t('Department') }}</dt><dd>{{ record.department || '—' }}</dd></div>
          </dl>
        </section>
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="calendar" class="w-5 h-5" /></span><div class="min-w-0"><h3 class="lg-card-title">{{ $t('Dates') }}</h3></div></header>
          <dl class="ar-dl">
            <div><dt>{{ $t('Document Date') }}</dt><dd>{{ record.document_date ? $date(record.document_date) : '—' }}</dd></div>
            <div><dt>{{ $t('Received Date') }}</dt><dd>{{ record.received_date ? $date(record.received_date) : '—' }}</dd></div>
            <div><dt>{{ $t('Expiry Date') }}</dt><dd :class="{ 'ar-bad': record.is_expired, 'ar-warn': record.expires_soon }">{{ record.expiry_date ? $date(record.expiry_date) : '—' }}</dd></div>
            <div><dt>{{ $t('Created') }}</dt><dd>{{ $datetime(record.created_at) }} <span class="lg-subtext">{{ record.created_by }}</span></dd></div>
            <div><dt>{{ $t('Last Updated') }}</dt><dd>{{ $datetime(record.updated_at) }} <span class="lg-subtext">{{ record.updated_by }}</span></dd></div>
          </dl>
        </section>
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-rose"><icons name="lock-closed" class="w-5 h-5" /></span><div class="min-w-0"><h3 class="lg-card-title">{{ $t('Classification') }}</h3></div></header>
          <dl class="ar-dl">
            <div><dt>{{ $t('Confidentiality') }}</dt><dd>{{ record.confidentiality }}</dd></div>
            <div><dt>{{ $t('Status') }}</dt><dd>{{ record.status }}</dd></div>
            <div><dt>{{ $t('Attachments') }}</dt><dd>{{ record.files_count }}</dd></div>
            <div v-if="can.storage"><dt>{{ $t('Storage Used') }}</dt><dd class="ar-maroon">{{ fmt(record.storage_bytes) }}</dd></div>
            <div class="ar-wide"><dt>{{ $t('Tags') }}</dt><dd><span v-for="t in record.tags" :key="t" class="ar-tag">{{ t }}</span><span v-if="!record.tags.length">—</span></dd></div>
            <div class="ar-wide"><dt>{{ $t('Keywords') }}</dt><dd class="ar-text">{{ record.keywords || '—' }}</dd></div>
          </dl>
        </section>
      </div>

      <!-- Attachments -->
      <div v-if="tab === 'files'">
        <form v-if="can.update" class="lg-card ar-upload" @submit.prevent="upload">
          <label class="lg-btn lg-btn-soft ar-choose"><icons name="upload" class="w-4 h-4" />{{ pending.length ? pending.length + ' ' + $t('file(s) selected') : $t('Choose files') }}<input type="file" multiple class="sr-only" :disabled="uploading" @change="onPick" /></label>
          <button type="submit" class="lg-btn lg-btn-primary" :disabled="!pending.length || uploading"><icons name="upload" class="w-4 h-4" />{{ uploading ? $t('Uploading…') : $t('Add Attachment') }}</button>
          <p v-if="uploadError" class="ar-err">{{ uploadError }}</p>
          <upload-progress class="ar-up-full" :items="pending" :removable="!uploading" @remove="i => (pending = pending.filter(x => x.uid !== i.uid))" />
        </form>

        <div v-if="fileList.data.length" class="ar-files">
          <article v-for="f in fileList.data" :key="f.id" class="ar-file">
            <span class="ar-file-ico" :class="'lg-ico-' + look(f.file_type).tone"><icons :name="look(f.file_type).icon" class="w-5 h-5" /></span>
            <div class="ar-file-body">
              <div class="ar-file-name" :title="f.name">{{ f.name }}</div>
              <div class="lg-subtext">{{ f.extension }} &middot; {{ fmt(f.size_bytes) }} &middot; {{ f.uploaded_by || '—' }} &middot; {{ f.uploaded_at ? $datetime(f.uploaded_at) : '' }}</div>
              <span v-if="f.processing_status !== 'not_applicable'" class="lg-chip" :class="processChip(f.processing_status)" style="text-transform: none">{{ processLabel(f.processing_status) }}</span>
            </div>
            <div class="ar-file-actions">
              <button v-if="can.preview" type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Preview')" :aria-label="$t('Preview')" @click="openPreview(f)"><icons name="eye-flat" class="w-4 h-4" /></button>
              <a v-if="can.preview" :href="fileUrl('open', f)" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('Open in New Tab')" :aria-label="$t('Open in New Tab')"><icons name="link" class="w-4 h-4" /></a>
              <a v-if="can.download" :href="fileUrl('download', f)" class="lg-icon-btn is-green lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" class="w-4 h-4" /></a>
              <button v-if="can.delete" type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove attachment')" :aria-label="$t('Remove attachment')" @click="removeFile(f)"><icons name="trash" class="w-4 h-4" /></button>
            </div>
          </article>
          <div v-if="fileList.current_page < fileList.last_page" class="ar-more"><button type="button" class="lg-btn lg-btn-soft" :disabled="loadingMore" @click="loadMore"><icons name="down" class="w-4 h-4" />{{ loadingMore ? $t('Loading…') : $t('Load more') }}</button></div>
        </div>
        <div v-else class="lg-card ar-empty"><icons name="clip" class="w-8 h-8" /><strong>{{ $t('No attachments have been added.') }}</strong></div>
      </div>

      <!-- History -->
      <section v-if="tab === 'history'" id="history" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h3 class="lg-card-title">{{ $t('Record History') }}</h3><p class="lg-card-sub">{{ $t('Creation, edits, uploads and deletions.') }}</p></div></header>
        <p v-if="!changeHistory.length" class="lg-empty">{{ $t('No history recorded yet.') }}</p>
        <ol v-else class="ar-timeline">
          <li v-for="h in changeHistory" :key="h.id"><span class="ar-dot"></span><div><div class="ar-tl-head"><span class="ar-tl-desc">{{ h.description }}</span><span class="ar-tl-time">{{ $datetime(h.at) }}</span></div></div></li>
        </ol>
      </section>

      <!-- Access history -->
      <section v-if="tab === 'access' && accessHistory" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-teal"><icons name="eye" class="w-5 h-5" /></span><div class="min-w-0"><h3 class="lg-card-title">{{ $t('Document Access') }}</h3><p class="lg-card-sub">{{ $t('Every view, preview, new-tab open, download and print is logged.') }}</p></div></header>
        <p v-if="!accessHistory.length" class="lg-empty">{{ $t('No document access recorded yet.') }}</p>
        <ol v-else class="ar-timeline">
          <li v-for="h in accessHistory" :key="h.id">
            <span class="ar-dot" :class="'is-' + h.action.toLowerCase()"></span>
            <div>
              <div class="ar-tl-head"><span class="ar-tl-desc">{{ h.description }}</span><span class="ar-tl-time">{{ $datetime(h.at) }}</span></div>
              <div class="ar-tl-meta"><span class="lg-chip lg-chip-slate" style="text-transform: none">{{ h.action }}</span><span v-if="h.ip">{{ h.ip }}</span><span v-if="h.browser">{{ h.browser }}</span><span v-if="h.device">{{ h.device }}</span></div>
            </div>
          </li>
        </ol>
      </section>
    </div>

    <!-- Preview viewer -->
    <modal v-if="!locked" :show="!!viewer.file" max-width="2xl" @close="closePreview">
      <div class="ar-viewer">
        <header class="ar-viewer-head">
          <div class="min-w-0"><strong class="ar-viewer-name">{{ viewer.file && viewer.file.name }}</strong><span class="lg-subtext">{{ viewer.file && viewer.file.extension }} &middot; {{ viewer.file && fmt(viewer.file.size_bytes) }}</span></div>
          <div class="ar-viewer-actions">
            <a v-if="viewer.file && viewer.file.can_preview" :href="fileUrl('open', viewer.file)" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('Open in New Tab')" :aria-label="$t('Open in New Tab')"><icons name="link" class="w-4 h-4" /></a>
            <a v-if="can.download && viewer.file" :href="fileUrl('download', viewer.file)" class="lg-icon-btn is-green lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" class="w-4 h-4" /></a>
            <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Close')" :aria-label="$t('Close')" @click="closePreview"><icons name="cross" class="w-4 h-4" /></button>
          </div>
        </header>
        <div class="ar-viewer-body">
          <div v-if="viewer.loading" class="ar-viewer-state"><span class="ar-spinner"></span>{{ $t('Loading preview…') }}</div>
          <div v-else-if="viewer.unsupported" class="ar-viewer-state"><icons name="doc" class="w-8 h-8" /><strong>{{ $t('Preview unavailable for this file type.') }}</strong><a v-if="can.download" :href="fileUrl('download', viewer.file)" class="lg-btn lg-btn-primary"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</a></div>
          <div v-else-if="viewer.error" class="ar-viewer-state"><icons name="exclamation" class="w-8 h-8" /><strong>{{ $t('Unable to preview this document.') }}</strong>
            <div class="ar-viewer-btns"><button type="button" class="lg-btn lg-btn-soft" @click="openPreview(viewer.file)"><icons name="refresh" class="w-4 h-4" />{{ $t('Retry') }}</button><a v-if="can.download" :href="fileUrl('download', viewer.file)" class="lg-btn lg-btn-primary"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</a></div></div>
          <img v-else-if="viewer.kind === 'image'" :src="viewer.url" :alt="viewer.file.name" class="ar-viewer-img" />
          <iframe v-else-if="viewer.kind === 'pdf'" :src="viewer.url + (can.download ? '' : '#toolbar=0&navpanes=0')" class="ar-viewer-frame" :title="viewer.file.name"></iframe>
        </div>
      </div>
    </modal>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Modal from '@/Jetstream/Modal.vue';
import FolderIcon from '@/Shared/Archive/FolderIcon.vue';
import UploadProgress from '@/Shared/UploadProgress.vue';
import { makeItem, uploadSequential } from '@/Core/uploadQueue';
import { formatBytes, confidentialityChip, statusChip, FILE_TYPE_LOOK } from '@/Core/archiveFormat';

export default {
  components: { AdminLayout, Modal, FolderIcon, UploadProgress, Link },

  props: { locked: Boolean, minutes: Number, record: Object, files: Object, changeHistory: Array, accessHistory: { type: Array, default: null }, can: Object },

  data() {
    return {
      tab: 'details',
      fileList: this.files ? { data: [...this.files.data], current_page: this.files.current_page, last_page: this.files.last_page, total: this.files.total } : { data: [], current_page: 1, last_page: 1, total: 0 },
      password: '',
      unlockError: null,
      busy: false,
      shake: false,
      loadingMore: false,
      pending: [],
      uploading: false,
      uploadError: null,
      viewer: { file: null, loading: false, error: false, unsupported: false, kind: null, url: null },
    };
  },

  computed: {
    restricted() {
      return this.record.confidentiality === 'Confidential' || this.record.confidentiality === 'Restricted';
    },
    tabs() {
      const tabs = [
        { key: 'details', label: this.$t('Details'), icon: 'doc-text' },
        { key: 'files', label: this.$t('Attachments'), icon: 'clip', count: this.record.files_count },
        { key: 'history', label: this.$t('History'), icon: 'clock' },
      ];
      if (this.accessHistory) tabs.push({ key: 'access', label: this.$t('Document Access'), icon: 'eye' });
      return tabs;
    },
  },

  mounted() {
    const hash = (window.location.hash || '').replace('#', '');
    if (['files', 'history', 'access'].includes(hash)) this.tab = hash;
  },

  beforeUnmount() {
    this.revoke();
  },

  methods: {
    fmt: formatBytes,
    confChip: confidentialityChip,
    statusChip,
    look(type) {
      return FILE_TYPE_LOOK[type] || FILE_TYPE_LOOK.Other;
    },
    async submitUnlock() {
      this.busy = true;
      this.unlockError = null;
      try {
        await window.axios.post(this.route('archive.restricted.unlock'), { password: this.password });
        this.password = '';
        this.$inertia.reload();
      } catch (e) {
        this.password = '';
        this.unlockError = e.response && e.response.status === 429 ? this.$t('Too many attempts. Please wait a minute.') : ((e.response && e.response.data && e.response.data.errors && e.response.data.errors.password && e.response.data.errors.password[0]) || this.$t('Unable to unlock this record.'));
        this.shake = true;
        setTimeout(() => { this.shake = false; }, 400);
      } finally {
        this.busy = false;
      }
    },
    setTab(key) {
      this.tab = key;
      try { history.replaceState(null, '', '#' + key); } catch (e) { /* ignore */ }
    },
    processChip(s) {
      return { processing: 'lg-chip-amber', pending: 'lg-chip-amber', optimized: 'lg-chip-green', failed: 'lg-chip-red' }[s] || 'lg-chip-slate';
    },
    processLabel(s) {
      return { processing: this.$t('Processing'), pending: this.$t('Processing'), optimized: this.$t('Optimized'), failed: this.$t('Failed') }[s] || this.$t('Not Applicable');
    },
    fileUrl(kind, f) {
      return this.route('archive.records.files.' + kind, [this.record.id, f.id]);
    },
    // Preview: fetched with the user's session, shown from a blob — so failures can be reported and retried,
    // and the document never gets a shareable URL. The server logs it as PREVIEW.
    async openPreview(f) {
      this.revoke();
      this.viewer = { file: f, loading: true, error: false, unsupported: !f.can_preview, kind: null, url: null };
      if (!f.can_preview) { this.viewer.loading = false; return; }
      try {
        const res = await window.axios.get(this.fileUrl('preview', f), { responseType: 'blob', headers: { 'X-Archive-Viewer': '1' } });
        const type = (res.data.type || '').toLowerCase();
        this.viewer.kind = type.startsWith('image/') ? 'image' : type === 'application/pdf' ? 'pdf' : null;
        if (!this.viewer.kind) { this.viewer.unsupported = true; } else { this.viewer.url = URL.createObjectURL(res.data); }
      } catch (e) {
        if (e.response && e.response.status === 423) {
          // The unlock window ran out while the page was open: reload, which asks for the password again.
          this.closePreview();
          this.$inertia.reload();
          return;
        }
        this.viewer.error = true;
      } finally {
        this.viewer.loading = false;
      }
    },
    closePreview() {
      this.revoke();
      this.viewer = { file: null, loading: false, error: false, unsupported: false, kind: null, url: null };
    },
    revoke() {
      if (this.viewer && this.viewer.url) URL.revokeObjectURL(this.viewer.url);
    },
    onPick(e) {
      this.pending = Array.from(e.target.files || []).slice(0, 20).map(f => makeItem(f));
      e.target.value = '';
      this.uploadError = null;
    },
    // one request per file, so each attachment shows its own progress
    async upload() {
      this.uploading = true;
      this.uploadError = null;
      const out = await uploadSequential(this.pending, this.route('archive.records.files.store', this.record.id));
      this.uploading = false;
      if (out.failed) {
        this.uploadError = this.$t(':n file(s) did not upload, see the list below, then try again.', { n: out.failed });
      }
      if (out.done) {
        this.$inertia.reload({ preserveScroll: true, onSuccess: () => { this.pending = this.pending.filter(f => f.status !== 'uploaded'); } });
      }
    },
    async loadMore() {
      this.loadingMore = true;
      try {
        const res = await window.axios.get(this.route('archive.records.files.index', this.record.id), { params: { page: this.fileList.current_page + 1 } });
        this.fileList = { ...res.data, data: [...this.fileList.data, ...res.data.data] };
      } finally {
        this.loadingMore = false;
      }
    },
    async removeFile(f) {
      if (await this.$confirm(this.$t('Remove attachment') + ' "' + f.name + '"?', { variant: 'danger', confirmText: this.$t('Remove') })) {
        this.$inertia.delete(this.route('archive.records.files.destroy', [this.record.id, f.id]), { preserveScroll: true });
      }
    },
    async remove() {
      if (await this.$confirm(this.$t('Delete archive record') + ' ' + this.record.reference + '?', { variant: 'danger', confirmText: this.$t('Delete') })) {
        this.$inertia.delete(this.route('archive.records.destroy', this.record.id));
      }
    },
  },

  watch: {
    // After an upload/removal Inertia hands back fresh files; keep the visible list in step.
    files(val) {
      if (!val) return;
      this.fileList = { data: [...val.data], current_page: val.current_page, last_page: val.last_page, total: val.total };
    },
  },
};
</script>

<style scoped>
.ar-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.ar-tabs { display: flex; gap: 6px; overflow-x: auto; margin-bottom: 1rem; padding: 6px; border-radius: 9999px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); }
.ar-tab { display: inline-flex; align-items: center; gap: 7px; flex: none; padding: 0.5rem 1rem; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; white-space: nowrap; color: var(--lg-mute); transition: background 0.15s ease, color 0.15s ease; }
.ar-tab:hover { color: var(--lg-ink); background: var(--lg-soft); }
.ar-tab.is-active { color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.9); }
.ar-tab-count { min-width: 20px; padding: 0 6px; border-radius: 9999px; background: rgba(255, 255, 255, 0.22); font-size: 0.68rem; line-height: 20px; text-align: center; }
.ar-tab:not(.is-active) .ar-tab-count { background: var(--lg-soft); color: var(--lg-mute); }

.ar-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; align-items: start; }
.ar-grid > .lg-card { margin-bottom: 0; min-width: 0; }
.ar-dl { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 16px; margin: 0; }
.ar-dl dt { font-size: 0.62rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.ar-dl dd { margin: 3px 0 0; font-size: 0.86rem; font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }
.ar-wide { grid-column: 1 / -1; }
.ar-text { white-space: pre-line; font-weight: 600 !important; }
.ar-maroon { color: #7f1d1d !important; } html.dark .ar-maroon { color: #fda4af !important; }
.ar-bad { color: #be123c !important; } .ar-warn { color: #b45309 !important; }
.ar-tag { display: inline-block; margin: 0 6px 4px 0; padding: 2px 10px; border-radius: 9999px; background: rgba(79, 70, 229, 0.12); color: #4338ca; font-size: 0.76rem; font-weight: 700; }
html.dark .ar-tag { color: #c7d2fe; }

.ar-upload { display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem; }
.ar-choose { cursor: pointer; }
.ar-err { flex-basis: 100%; font-size: 0.78rem; font-weight: 600; color: #be123c; }
.ar-files { display: flex; flex-direction: column; gap: 8px; }
.ar-file { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 16px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); min-width: 0; }
.ar-file-ico { display: inline-flex; flex: none; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 13px; color: #fff; }
.ar-file-body { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 2px; align-items: flex-start; }
.ar-file-name { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 800; font-size: 0.86rem; color: var(--lg-ink); }
.ar-file-actions { display: flex; flex: none; gap: 6px; }
.ar-more { display: flex; justify-content: center; margin-top: 4px; }
.ar-empty { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 28px 16px; color: var(--lg-faint); }
.ar-empty strong { color: var(--lg-mute); font-size: 0.9rem; }

.ar-timeline { margin: 0; padding: 0 0 0 4px; list-style: none; }
.ar-timeline li { position: relative; display: flex; gap: 12px; padding: 0 0 16px 18px; border-left: 2px solid var(--lg-line); margin-left: 6px; }
.ar-timeline li:last-child { border-left-color: transparent; padding-bottom: 0; }
.ar-timeline li > div { min-width: 0; flex: 1; }
.ar-dot { position: absolute; left: -7px; top: 3px; width: 12px; height: 12px; border-radius: 9999px; background: #64748b; box-shadow: 0 0 0 3px var(--lg-card); }
.ar-dot.is-view, .ar-dot.is-preview { background: #0284c7; } .ar-dot.is-open_new_tab { background: #7c3aed; } .ar-dot.is-download { background: #059669; } .ar-dot.is-print { background: #d97706; }
.ar-tl-head { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 12px; }
.ar-tl-desc { font-weight: 700; font-size: 0.85rem; color: var(--lg-ink); overflow-wrap: anywhere; }
.ar-tl-time { font-size: 0.72rem; color: var(--lg-faint); white-space: nowrap; }
.ar-tl-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 10px; margin-top: 4px; font-size: 0.72rem; color: var(--lg-mute); }

.ar-viewer { display: flex; flex-direction: column; background: var(--lg-card, #fff); color: var(--lg-ink, #0f172a); }
.ar-viewer-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; border-bottom: 1px solid var(--lg-line, #e2e8f0); }
.ar-viewer-head > div:first-child { display: flex; flex-direction: column; }
.ar-viewer-name { display: block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.9rem; }
.ar-viewer-actions { display: flex; flex: none; gap: 6px; }
.ar-viewer-body { position: relative; height: min(72vh, 760px); background: var(--lg-soft, #f1f5f9); overflow: auto; display: flex; align-items: center; justify-content: center; }
.ar-viewer-frame { width: 100%; height: 100%; border: 0; background: #fff; }
.ar-viewer-img { max-width: 100%; max-height: 100%; object-fit: contain; }
.ar-viewer-state { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 24px; text-align: center; color: var(--lg-mute, #64748b); }
.ar-viewer-btns { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; }
.ar-spinner { width: 26px; height: 26px; border-radius: 9999px; border: 3px solid var(--lg-line, #cbd5e1); border-top-color: #4f46e5; animation: ar-spin 0.8s linear infinite; }
@keyframes ar-spin { to { transform: rotate(360deg); } }

@media (max-width: 1023.98px) { .ar-grid { grid-template-columns: minmax(0, 1fr); } }
@media (max-width: 575.98px) {
  .ar-dl { grid-template-columns: minmax(0, 1fr); }
  .ar-file { flex-wrap: wrap; } .ar-file-actions { width: 100%; justify-content: flex-end; }
  .ar-viewer-body { height: 68vh; }
}
@media (prefers-reduced-motion: reduce) { .ar-spinner { animation-duration: 2s; } }
.ar-up-full { flex-basis: 100%; width: 100%; }
.ar-upload { flex-wrap: wrap; }
</style>

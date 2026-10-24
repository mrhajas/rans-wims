<template>
  <admin-layout :title="$t('Performance & Data')">
    <div class="lg-page px-4 md:px-0 pf-page">
      <page-header icon="chart-bar" :title="$t('Performance & Data')" :subtitle="$t('How long things are kept, the activity archive, what is slow, and sending messages in the background.')">
        <Link :href="route('settings')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Back to Settings') }}</Link>
      </page-header>

      <stat-tiles :items="tiles" compact />

      <!-- how long things are kept -->
      <section class="lg-card" id="pf-retention">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="archive-box" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('How long things are kept') }}</h2>
            <p class="lg-card-sub">{{ $t('Activity history is never deleted: after the number of months below it moves to the activity archive, where it stays and can be downloaded.') }}</p>
          </div>
          <button type="button" class="lg-btn tone tone-solid" style="--tone: #4f46e5" :disabled="form.processing" @click="save"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Save') }}</button>
        </header>
        <div class="pf-grid">
          <label v-for="f in fields" :key="f.key" class="pf-field" :class="{ 'is-main': f.main }">
            <span class="pf-label"><icons :name="f.icon" class="w-4 h-4" />{{ f.label }}</span>
            <span class="pf-input"><input v-model.number="form[f.key]" type="number" :min="limits[f.key][0]" :max="limits[f.key][1]" inputmode="numeric" /><em>{{ f.unit }}</em></span>
            <small>{{ f.hint }}</small>
            <small v-if="form.errors[f.key]" class="pf-err">{{ form.errors[f.key] }}</small>
          </label>
        </div>
        <div class="pf-actions">
          <span class="pf-last" v-if="last"><icons name="clock" class="w-4 h-4" />{{ $t('Last clean-up') }}: {{ $datetime(last.at) }} · {{ count(last.activity_archived) }} {{ $t('activity records archived') }}</span>
          <span class="pf-last" v-else><icons name="clock" class="w-4 h-4" />{{ $t('The clean-up runs every night at 02:10.') }}</span>
          <button type="button" class="lg-btn tone" style="--tone: #d97706" :disabled="busy" @click="askClean"><span class="tone-ico"><icons name="refresh" class="w-4 h-4" /></span>{{ $t('Run clean-up now') }}</button>
        </div>
      </section>

      <!-- the activity archive -->
      <section class="lg-card" id="pf-archive">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="archive-box" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Activity archive') }}</h2>
            <p class="lg-card-sub">{{ $t(':n record(s) kept', { n: count(stats.archive) }) }}<template v-if="stats.oldest_archive"> · {{ $t('from') }} {{ $date(stats.oldest_archive) }}</template></p>
          </div>
        </header>
        <div class="pf-filters">
          <label><span>{{ $t('From') }}</span><date-picker v-model="af.from" @change="filterChanged" flush /></label>
          <label><span>{{ $t('To') }}</span><date-picker v-model="af.to" @change="filterChanged" flush /></label>
          <label><span>{{ $t('Log') }}</span>
            <css-select v-model="af.log" class="w-full" @change="filterChanged"><option value="">{{ $t('All logs') }}</option><option v-for="l in logNames" :key="l" :value="l">{{ l }}</option></css-select>
          </label>
          <label class="pf-search"><span>{{ $t('Search') }}</span><input v-model="af.search" type="search" :placeholder="$t('Words in the description...')" @input="searchSoon" /></label>
        </div>
        <p class="pf-scope"><icons name="info" class="w-4 h-4" /><span>{{ scopeText }}</span></p>
        <div class="pf-actions pf-archive-actions">
          <button type="button" class="lg-btn tone tone-solid" style="--tone: #dc2626" :disabled="busy || !archive.total" @click="downloadPdf"><span class="tone-ico"><icons name="doc-text" class="w-4 h-4" /></span>{{ $t('Download PDF') }}</button>
          <a :href="csvUrl" class="lg-btn tone" style="--tone: #059669" :class="{ 'is-off': !archive.total }"><span class="tone-ico"><icons name="download" class="w-4 h-4" /></span>{{ $t('Download CSV (Excel)') }}</a>
          <button type="button" class="lg-btn tone" style="--tone: #b91c1c" :disabled="busy || !archive.total" @click="askClear"><span class="tone-ico"><icons name="trash" class="w-4 h-4" /></span>{{ $t('Clear logs') }}</button>
        </div>

        <div v-if="exportsList.length" class="pf-exports">
          <h3><icons name="archive-box" class="w-4 h-4" />{{ $t('My PDF exports') }}</h3>
          <div v-for="e in exportsList" :key="e.id" class="pf-export" :class="'is-' + e.status">
            <div class="pf-export-main">
              <b>{{ e.period }}<template v-if="e.log"> · {{ e.log }}</template><template v-if="e.search"> · "{{ e.search }}"</template></b>
              <small>{{ count(e.rows) }} {{ $t('records') }} · {{ e.parts }} {{ $t('file(s)') }} · {{ $datetime(e.at) }}</small>
              <span v-if="e.status === 'queued' || e.status === 'running'" class="pf-bar"><i :style="{ width: Math.round((e.done / e.parts) * 100) + '%' }"></i></span>
              <small v-if="e.status === 'failed'" class="pf-err">{{ e.error }}</small>
            </div>
            <span class="lg-chip" :class="{ 'lg-chip-green': e.status === 'done', 'lg-chip-amber': e.status === 'queued' || e.status === 'running', 'lg-chip-red': e.status === 'failed' }" style="text-transform: none">{{ e.status === 'done' ? $t('Ready') : e.status === 'failed' ? $t('Failed') : e.status === 'queued' ? $t('Waiting') : e.done + ' / ' + e.parts }}</span>
            <a v-if="e.status === 'done'" :href="route('performance.archive.export.download', e.id)" class="lg-btn tone tone-solid" style="--tone: #059669"><span class="tone-ico"><icons name="download" class="w-4 h-4" /></span>{{ $t('Download ZIP') }}</a>
            <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="removeExport(e)"><icons name="trash" class="w-4 h-4" /></button>
          </div>
        </div>

        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead><tr><th>{{ $t('Date') }}</th><th>{{ $t('Log') }}</th><th>{{ $t('What happened') }}</th><th>{{ $t('Done by') }}</th><th>{{ $t('Record') }}</th></tr></thead>
            <tbody>
              <tr v-for="r in archive.data" :key="r.id">
                <td :data-label="$t('Date')" class="pf-nowrap">{{ $datetime(r.at) }}</td>
                <td :data-label="$t('Log')"><span class="lg-chip lg-chip-slate" style="text-transform: none">{{ r.log || '-' }}</span></td>
                <td :data-label="$t('What happened')" class="pf-wrap">{{ r.description }}</td>
                <td :data-label="$t('Done by')">{{ r.by || '-' }}</td>
                <td :data-label="$t('Record')">{{ r.subject || '-' }}</td>
              </tr>
              <tr v-if="!archive.data.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ archiveLoading ? $t('Loading...') : stats.archive ? $t('Nothing matches these filters.') : $t('Nothing has been archived yet. History older than :m months moves here.', { m: form.perf_activity_keep_months }) }}</td></tr>
            </tbody>
          </table>
        </div>
        <div v-if="archive.last_page > 1" class="pf-pager">
          <button type="button" class="lg-btn lg-btn-soft" :disabled="archive.page <= 1 || archiveLoading" @click="loadArchive(archive.page - 1)"><icons name="arrow-left" class="w-4 h-4" />{{ $t('Previous') }}</button>
          <span>{{ archive.page }} / {{ archive.last_page }} · {{ count(archive.total) }} {{ $t('records') }}</span>
          <button type="button" class="lg-btn lg-btn-soft" :disabled="archive.page >= archive.last_page || archiveLoading" @click="loadArchive(archive.page + 1)">{{ $t('Next') }}<icons name="arrow-left" class="w-4 h-4 transform rotate-180" /></button>
        </div>
      </section>

      <!-- sending in the background -->
      <section class="lg-card" id="pf-queue">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="send" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Send messages in the background') }}</h2>
            <p class="lg-card-sub">{{ $t('SMS, e-mail and bell alerts are sent a few seconds after the save, so people do not wait for them.') }}</p>
          </div>
          <span class="lg-chip" :class="queue.active ? 'lg-chip-green' : queue.wanted ? 'lg-chip-amber' : 'lg-chip-slate'" style="text-transform: none">{{ queue.active ? $t('Running in the background') : queue.wanted ? $t('Waiting for the worker') : $t('Sent at once (off)') }}</span>
        </header>
        <label class="pf-switch">
          <input v-model="queueOn" type="checkbox" @change="saveQueue" />
          <span class="pf-switch-ui" aria-hidden="true"></span>
          <span><b>{{ $t('Send in the background') }}</b><small>{{ $t('Used only while the worker below is running. If it stops, WIMS goes back to sending at once, so nothing is left waiting.') }}</small></span>
        </label>
        <div class="pf-queue-stats">
          <div><b>{{ queue.beat_ago === null ? '-' : ago(queue.beat_ago) }}</b><span>{{ $t('Worker last seen') }}</span></div>
          <div><b>{{ queue.pending }}</b><span>{{ $t('Waiting to be sent') }}</span></div>
          <div :class="{ 'is-bad': queue.failed }"><b>{{ queue.failed }}</b><span>{{ $t('Failed') }}</span></div>
        </div>
        <p v-if="queue.wanted && !queue.alive" class="lg-note lg-note-amber"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t('The worker has not been seen. It starts by itself when the scheduled task runs every minute on the server (php artisan schedule:run). Until then messages are sent at once.') }}</span></p>
        <ul v-if="queue.failed_list.length" class="pf-failed">
          <li v-for="f in queue.failed_list" :key="f.id"><b>{{ $datetime(f.at) }}</b> <span>{{ f.error }}</span></li>
        </ul>
        <div class="pf-actions">
          <button type="button" class="lg-btn tone" style="--tone: #0284c7" :disabled="busy || !queue.pending" @click="post('performance.queue.run', $t('Sending waiting messages'))"><span class="tone-ico"><icons name="send" class="w-4 h-4" /></span>{{ $t('Send waiting messages now') }}</button>
          <button type="button" class="lg-btn tone" style="--tone: #059669" :disabled="busy || !queue.failed" @click="post('performance.queue.retry', $t('Retrying'))"><span class="tone-ico"><icons name="refresh" class="w-4 h-4" /></span>{{ $t('Retry failed') }}</button>
          <button type="button" class="lg-btn tone" style="--tone: #dc2626" :disabled="busy || !queue.failed" @click="askFlush"><span class="tone-ico"><icons name="trash" class="w-4 h-4" /></span>{{ $t('Clear failed') }}</button>
        </div>
      </section>

      <!-- what was slow -->
      <section class="lg-card" id="pf-slow">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('What was slow (last 7 days)') }}</h2>
            <p class="lg-card-sub">{{ $t('Pages and database queries slower than the limits above. Only the query text is kept, never the values.') }}</p>
          </div>
          <button type="button" class="lg-btn tone" style="--tone: #dc2626" :disabled="!slow.length || busy" @click="clearSlow"><span class="tone-ico"><icons name="trash" class="w-4 h-4" /></span>{{ $t('Clear') }}</button>
        </header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead><tr><th>{{ $t('Type') }}</th><th>{{ $t('What') }}</th><th class="num">{{ $t('Times') }}</th><th class="num">{{ $t('Average') }}</th><th class="num">{{ $t('Slowest') }}</th><th>{{ $t('Page') }}</th><th>{{ $t('Last seen') }}</th></tr></thead>
            <tbody>
              <tr v-for="s in slow" :key="s.fingerprint + s.kind">
                <td :data-label="$t('Type')"><span class="lg-chip" :class="s.kind === 'query' ? 'lg-chip-violet' : 'lg-chip-sky'" style="text-transform: none">{{ s.kind === 'query' ? $t('Query') : $t('Page') }}</span></td>
                <td :data-label="$t('What')" class="pf-wrap pf-mono"><span class="pf-clamp" :title="s.label">{{ s.label }}</span></td>
                <td :data-label="$t('Times')" class="num">{{ s.n }}</td>
                <td :data-label="$t('Average')" class="num">{{ ms(s.avg_ms) }}</td>
                <td :data-label="$t('Slowest')" class="num"><b :class="{ 'pf-hot': s.max_ms >= 3000 }">{{ ms(s.max_ms) }}</b></td>
                <td :data-label="$t('Page')" class="pf-mono pf-wrap"><span class="pf-clamp" :title="s.path">{{ s.path || '-' }}</span></td>
                <td :data-label="$t('Last seen')" class="pf-nowrap">{{ $datetime(s.last_at) }}</td>
              </tr>
              <tr v-if="!slow.length" class="lg-empty-row"><td class="lg-empty" colspan="7">{{ $t('Nothing slow was recorded. That is good news.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- a PDF too large for one file: made in the background -->
      <tec-dialog-modal :show="plan.open" max-width="md" @close="plan.open = false">
        <template #title><span class="pf-dlg-title"><icons name="doc-text" class="w-5 h-5" />{{ $t('Prepare the PDF') }}</span></template>
        <template #content>
          <p class="pf-dlg-text">{{ $t(':n records (:period) make :p PDF files, which are collected in one ZIP.', { n: count(plan.total), period: plan.period, p: plan.parts }) }}</p>
          <p class="pf-dlg-text">{{ $t('WIMS prepares them in the background, about :m minute(s). You can leave this page: the ZIP appears under "My PDF exports" when it is ready.', { m: plan.minutes }) }}</p>
        </template>
        <template #footer>
          <button type="button" class="lg-btn lg-btn-soft" @click="plan.open = false">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" :disabled="busy" @click="startExport"><icons name="tick" class="w-4 h-4" />{{ $t('Prepare PDF') }}</button>
        </template>
      </tec-dialog-modal>

      <!-- password for the actions that remove or move data -->
      <tec-dialog-modal :show="pw.open" max-width="md" @close="pw.open = false">
        <template #title><span class="pf-dlg-title"><icons name="lock-closed" class="w-5 h-5" />{{ pw.title }}</span></template>
        <template #content>
          <p class="pf-dlg-text">{{ pw.text }}</p>
          <input v-model="pw.password" type="password" class="pf-dlg-input" autocomplete="current-password" :placeholder="$t('Enter your password to confirm')" @keyup.enter="confirmPw" />
          <p v-if="pw.error" class="pf-err">{{ pw.error }}</p>
        </template>
        <template #footer>
          <button type="button" class="lg-btn lg-btn-soft" @click="pw.open = false">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" :disabled="!pw.password || busy" @click="confirmPw"><icons name="tick" class="w-4 h-4" />{{ $t('Confirm') }}</button>
        </template>
      </tec-dialog-modal>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import { Link } from '@inertiajs/vue3';

export default {
  components: { AdminLayout, PageHeader, StatTiles, TecDialogModal, Link },
  props: { perf: Object, limits: Object, defaults: Object, stats: Object, queue: Object, slow: Array, last: Object, exports: { type: Array, default: () => [] }, logNames: { type: Array, default: () => [] } },
  data() {
    return {
      form: this.$inertia.form({ ...this.perf }),
      queueOn: !!this.perf.perf_queue_background,
      busy: false,
      archive: { data: [], total: 0, page: 1, last_page: 1 },
      archiveLoading: false,
      af: { from: '', to: '', log: '', search: '' },
      searchTimer: null,
      pw: { open: false, action: '', title: '', text: '', password: '', error: '' },
      plan: { open: false, total: 0, parts: 1, minutes: 1, period: '' },
      exportsList: [...this.exports],
      exportTimer: null,
    };
  },
  computed: {
    tiles() {
      return [
        { icon: 'doc-text', tone: 'sky', label: this.$t('Activity in the live log'), value: this.count(this.stats.live) },
        { icon: 'archive-box', tone: 'forest', label: this.$t('In the activity archive'), value: this.count(this.stats.archive) },
        { icon: 'clock', tone: this.stats.slow_week ? 'orange' : 'forest', label: this.$t('Slow items (7 days)'), value: this.stats.slow_week },
        { icon: 'send', tone: this.queue.failed ? 'maroon' : 'violet', label: this.$t('Waiting messages'), value: this.queue.pending },
      ];
    },
    fields() {
      const t = this.$t;
      return [
        { key: 'perf_activity_keep_months', main: true, icon: 'archive-box', label: t('Keep activity history in the live log'), unit: t('months'), hint: t('Older history moves to the archive (nothing is deleted). 0 = never move it.') + (this.stats.due ? ' ' + t(':n record(s) are due to move.', { n: this.count(this.stats.due) }) : '') },
        { key: 'perf_notification_keep_days', icon: 'bell', label: t('Keep read bell alerts'), unit: t('days'), hint: t('Alerts you have read are removed after this. Unread ones stay. 0 = keep for ever.') },
        { key: 'perf_weather_keep_days', icon: 'cloud', label: t('Keep weather readings'), unit: t('days'), hint: t('Older readings are removed. 0 = keep for ever.') },
        { key: 'perf_log_keep_days', icon: 'doc', label: t('Keep server log files'), unit: t('days'), hint: t('Daily error log files older than this are removed. 0 = keep for ever.') },
        { key: 'perf_slow_keep_days', icon: 'clock', label: t('Keep slow-query records'), unit: t('days'), hint: t('How long the list below remembers.') },
        { key: 'perf_slow_query_ms', icon: 'chart-bar', label: t('Record queries slower than'), unit: 'ms', hint: t('0 = do not record. 300 is a good start.') },
        { key: 'perf_slow_request_ms', icon: 'chart-bar', label: t('Record pages slower than'), unit: 'ms', hint: t('0 = do not record. 1500 is a good start.') },
      ];
    },
    query() {
      return new URLSearchParams(Object.fromEntries(Object.entries(this.af).filter(([, v]) => v))).toString();
    },
    csvUrl() {
      return this.route('performance.archive.download') + (this.query ? '?' + this.query : '');
    },
    // what a download or a clear will cover, in plain words
    whenText() {
      const a = this.af;
      const when = a.from && a.to ? this.$t('from :a to :b', { a: this.$date(a.from), b: this.$date(a.to) }) : a.from ? this.$t('from :a until today', { a: this.$date(a.from) }) : a.to ? this.$t('from the first record until :b', { b: this.$date(a.to) }) : this.$t('all dates');
      return [when, a.log ? this.$t('log :l', { l: a.log }) : this.$t('all logs'), a.search ? '"' + a.search + '"' : ''].filter(Boolean).join(' · ');
    },
    scopeText() {
      return this.$t('Download PDF, CSV and Clear logs cover:') + ' ' + this.count(this.archive.total) + ' ' + this.$t('record(s)') + ' (' + this.whenText + '). ' + this.$t('Leave the filters empty to include everything.');
    },
  },
  mounted() {
    this.loadArchive(1);
    this.pollExports();
  },
  beforeUnmount() {
    clearTimeout(this.exportTimer);
  },
  watch: {
    exports(v) { this.exportsList = [...v]; this.pollExports(); },
  },
  methods: {
    count(v) { return Number(v || 0).toLocaleString(); },
    ms(v) { return Number(v) >= 1000 ? (Number(v) / 1000).toFixed(1) + ' s' : Number(v) + ' ms'; },
    ago(s) { return s < 90 ? s + ' s' : s < 5400 ? Math.round(s / 60) + ' min' : Math.round(s / 3600) + ' h'; },
    save() {
      this.form.perf_queue_background = this.queueOn ? 1 : 0;
      this.form.put(this.route('performance.update'), { preserveScroll: true });
    },
    saveQueue() {
      this.save();
    },
    filterChanged() {
      this.loadArchive(1);
    },
    // the PDF: one file at once when it is small, else prepared in the background
    async downloadPdf() {
      this.busy = true;
      try {
        const { data } = await window.axios.get(this.route('performance.archive.plan'), { params: this.af, headers: { 'X-Idle-Ignore': '1' } });
        if (!data.total) {
          this.$page.props.flash.error = this.$t('No archived record matches these filters.');
        } else if (data.now) {
          window.location.assign(this.route('performance.archive.pdf') + (this.query ? '?' + this.query : ''));
        } else {
          this.plan = { open: true, ...data };
        }
      } finally {
        this.busy = false;
      }
    },
    startExport() {
      this.busy = true;
      this.$inertia.post(this.route('performance.archive.export') + (this.query ? '?' + this.query : ''), {}, {
        preserveScroll: true,
        onSuccess: () => { this.plan.open = false; this.refreshExports(); },
        onFinish: () => (this.busy = false),
      });
    },
    refreshExports() {
      return window.axios.get(this.route('performance.archive.jobs'), { headers: { 'X-Idle-Ignore': '1' } }).then(r => { this.exportsList = r.data; this.pollExports(); }).catch(() => {});
    },
    pollExports() {
      clearTimeout(this.exportTimer);
      if (this.exportsList.some(e => e.status === 'queued' || e.status === 'running')) {
        this.exportTimer = setTimeout(() => !document.hidden ? this.refreshExports() : this.pollExports(), 6000);
      }
    },
    removeExport(e) {
      this.$inertia.delete(this.route('performance.archive.export.delete', e.id), { preserveScroll: true, onSuccess: () => this.refreshExports() });
    },
    askClear() {
      this.pw = { open: true, action: 'archive', title: this.$t('Clear logs'), text: this.$t('This permanently deletes :n archived record(s) (:s). They cannot be brought back. Download the PDF or CSV first if you want to keep a copy.', { n: this.count(this.archive.total), s: this.whenText }), password: '', error: '', expected: this.archive.total };
    },
    searchSoon() {
      clearTimeout(this.searchTimer);
      this.searchTimer = setTimeout(() => this.loadArchive(1), 350);
    },
    loadArchive(page) {
      this.archiveLoading = true;
      window.axios.get(this.route('performance.archive.list'), { params: { ...this.af, page }, headers: { 'X-Idle-Ignore': '1' } })
        .then(r => { this.archive = r.data; })
        .catch(() => {})
        .finally(() => { this.archiveLoading = false; });
    },
    post(name, label) {
      this.busy = true;
      this.$inertia.post(this.route(name), {}, { preserveScroll: true, headers: { 'X-Progress-Label': label, 'X-Progress-Long': '1' }, onFinish: () => (this.busy = false) });
    },
    clearSlow() {
      this.busy = true;
      this.$inertia.delete(this.route('performance.slow.clear'), { preserveScroll: true, onFinish: () => (this.busy = false) });
    },
    askClean() {
      this.pw = { open: true, action: 'clean', title: this.$t('Run the clean-up now'), text: this.$t('This moves activity history older than :m months to the archive and removes old weather readings, read alerts, log files and slow-query records, as set above. Nothing in the audit trail is deleted.', { m: this.form.perf_activity_keep_months }), password: '', error: '' };
    },
    askFlush() {
      this.pw = { open: true, action: 'flush', title: this.$t('Clear failed messages'), text: this.$t('The failed messages are removed for good (they could not be sent).'), password: '', error: '' };
    },
    confirmPw() {
      if (!this.pw.password || this.busy) return;
      this.busy = true;
      const clean = this.pw.action === 'clean';
      const opts = {
        preserveScroll: true,
        headers: clean ? { 'X-Progress-Label': this.$t('Cleaning up'), 'X-Progress-Done': this.$t('Clean-up finished'), 'X-Progress-Long': '1' } : {},
        onSuccess: () => { this.pw.open = false; if (clean) this.loadArchive(1); },
        onError: e => { this.pw.error = e.password || this.$t('Could not do that.'); },
        onFinish: () => { this.busy = false; },
      };
      if (clean) this.$inertia.post(this.route('performance.housekeeping'), { password: this.pw.password }, opts);
      else if (this.pw.action === 'archive') this.$inertia.delete(this.route('performance.archive.clear'), { ...opts, headers: { 'X-Progress-Label': this.$t('Clearing logs'), 'X-Progress-Done': this.$t('Logs cleared'), 'X-Progress-Long': '1' }, data: { password: this.pw.password, expected: this.pw.expected, ...this.af }, onSuccess: () => { this.pw.open = false; this.loadArchive(1); this.$inertia.reload({ only: ['stats'], preserveScroll: true }); } });
      else this.$inertia.delete(this.route('performance.queue.flush'), { ...opts, data: { password: this.pw.password } });
    },
  },
};
</script>

<style scoped>
.pf-grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(min(100%, 250px), 1fr)); }
.pf-field { display: grid; gap: 6px; padding: 12px 14px; border-radius: 16px; border: 1px solid var(--lg-line); background: var(--lg-soft); align-content: start; }
.pf-field.is-main { border-color: rgba(79, 70, 229, 0.45); background: rgba(79, 70, 229, 0.06); }
.pf-label { display: flex; align-items: center; gap: 8px; font-size: 0.78rem; font-weight: 800; color: var(--lg-ink); }
.pf-input { display: flex; align-items: center; gap: 8px; }
.pf-input input { width: 100%; min-height: 2.4rem; padding: 0 12px; border-radius: 12px; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-ink); font-weight: 700; font-variant-numeric: tabular-nums; }
.pf-input input:focus { outline: 2px solid rgba(79, 70, 229, 0.4); }
.pf-input em { font-style: normal; font-size: 0.78rem; font-weight: 700; color: var(--lg-mute); white-space: nowrap; }
.pf-field small { font-size: 0.72rem; line-height: 1.35; color: var(--lg-mute); }
.pf-err { color: #dc2626; font-size: 0.78rem; font-weight: 600; }
.pf-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-top: 14px; }
.pf-last { display: inline-flex; align-items: center; gap: 6px; font-size: 0.78rem; font-weight: 600; color: var(--lg-mute); }
.pf-filters { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); margin-bottom: 12px; }
.pf-filters label { display: grid; gap: 4px; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-mute); }
.pf-filters input, .pf-filters select { min-height: 2.3rem; padding: 0 10px; border-radius: 12px; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-ink); text-transform: none; font-weight: 600; letter-spacing: 0; }
.pf-search { grid-column: span 2; }
.pf-pager { display: flex; align-items: center; justify-content: center; gap: 12px; margin-top: 12px; font-size: 0.8rem; font-weight: 700; color: var(--lg-mute); flex-wrap: wrap; }
.pf-nowrap { white-space: nowrap; }
.pf-scope { display: flex; align-items: flex-start; gap: 8px; margin: 0 0 12px; padding: 9px 12px; border-radius: 12px; background: rgba(14, 165, 233, 0.08); box-shadow: inset 0 0 0 1px rgba(14, 165, 233, 0.3); font-size: 0.78rem; line-height: 1.4; color: var(--lg-ink); }
.pf-scope svg { flex: none; margin-top: 1px; color: #0284c7; }
.pf-archive-actions { justify-content: flex-start; margin: 0 0 14px; }
.lg-btn.is-off { opacity: 0.5; pointer-events: none; }
.pf-exports { margin: 0 0 14px; display: grid; gap: 8px; }
.pf-exports h3 { display: flex; align-items: center; gap: 6px; margin: 0; font-size: 0.78rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: var(--lg-mute); }
.pf-export { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 10px 12px; border-radius: 14px; border: 1px solid var(--lg-line); background: var(--lg-soft); }
.pf-export-main { display: grid; gap: 3px; flex: 1 1 220px; min-width: 0; } .pf-export-main b { font-size: 0.84rem; } .pf-export-main small { font-size: 0.72rem; color: var(--lg-mute); }
.pf-bar { display: block; height: 6px; border-radius: 9999px; background: rgba(148, 163, 184, 0.3); overflow: hidden; margin-top: 3px; } .pf-bar i { display: block; height: 100%; border-radius: 9999px; background: linear-gradient(90deg, #22c55e, #06b6d4, #6366f1); transition: width 0.4s ease; }
.pf-export.is-done { border-color: rgba(22, 163, 74, 0.4); } .pf-export.is-failed { border-color: rgba(220, 38, 38, 0.4); }
.pf-wrap { min-width: 220px; max-width: 520px; white-space: normal !important; overflow-wrap: anywhere; word-break: break-word; }
.pf-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.74rem; }
.pf-hot { color: #dc2626; }
.pf-clamp { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.pf-page .lg-table td, .pf-page .lg-table th { vertical-align: top; }
.pf-switch { display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; border-radius: 16px; border: 1px solid var(--lg-line); background: var(--lg-soft); cursor: pointer; }
.pf-switch input { position: absolute; opacity: 0; pointer-events: none; }
.pf-switch-ui { position: relative; flex: none; width: 46px; height: 26px; border-radius: 9999px; background: #94a3b8; transition: background 0.2s ease; margin-top: 2px; }
.pf-switch-ui::after { content: ''; position: absolute; top: 3px; left: 3px; width: 20px; height: 20px; border-radius: 50%; background: #fff; box-shadow: 0 2px 6px rgba(15, 23, 42, 0.3); transition: transform 0.2s ease; }
.pf-switch input:checked + .pf-switch-ui { background: linear-gradient(135deg, #22c55e, #059669); }
.pf-switch input:checked + .pf-switch-ui::after { transform: translateX(20px); }
.pf-switch input:focus-visible + .pf-switch-ui { outline: 3px solid rgba(79, 70, 229, 0.4); outline-offset: 2px; }
.pf-switch span:last-child { display: grid; gap: 2px; } .pf-switch b { font-size: 0.9rem; } .pf-switch small { font-size: 0.74rem; color: var(--lg-mute); line-height: 1.35; }
.pf-queue-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin: 12px 0; }
.pf-queue-stats > div { display: grid; gap: 2px; padding: 10px 12px; border-radius: 14px; border: 1px solid var(--lg-line); background: var(--lg-soft); }
.pf-queue-stats b { font-size: 1.2rem; font-variant-numeric: tabular-nums; } .pf-queue-stats span { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--lg-mute); }
.pf-queue-stats .is-bad { border-color: rgba(220, 38, 38, 0.4); background: rgba(220, 38, 38, 0.07); } .pf-queue-stats .is-bad b { color: #dc2626; }
.pf-failed { list-style: none; margin: 0 0 10px; padding: 0; display: grid; gap: 4px; font-size: 0.76rem; } .pf-failed li { padding: 6px 10px; border-radius: 10px; background: rgba(220, 38, 38, 0.06); } .pf-failed b { margin-right: 6px; }
.pf-dlg-title { display: inline-flex; align-items: center; gap: 8px; }
.pf-dlg-text { margin: 0 0 12px; font-size: 0.86rem; line-height: 1.45; }
.pf-dlg-input { width: 100%; min-height: 2.6rem; padding: 0 12px; border-radius: 12px; border: 1px solid var(--lg-line, #e2e8f0); background: transparent; color: inherit; }
@media (max-width: 560px) { .pf-search { grid-column: auto; } .pf-queue-stats { grid-template-columns: 1fr; } }
</style>

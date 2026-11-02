<template>
  <admin-layout :title="$t('Optimize')">
    <div class="lg-page px-4 md:px-0 op-page">
      <page-header icon="chart-bar" :title="$t('Optimize')" :subtitle="$t('Make the data already on the server smaller and faster, and keep a record of who did it and what was gained.')">
        <Link :href="route('settings')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Back to Settings') }}</Link>
      </page-header>

      <stat-tiles :items="tiles" compact />

      <!-- what can be done -->
      <section class="lg-card" id="op-tasks">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="chart-bar" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('What can be optimised') }}</h2>
            <p class="lg-card-sub">{{ $t('Scan first: it only looks and changes nothing. The gain shown is an estimate; the real gain is measured and kept in the history after the run.') }}</p>
          </div>
          <button v-if="canRun" type="button" class="lg-btn lg-btn-soft" :disabled="scanning || busy" @click="scan"><icons name="refresh" class="w-4 h-4" :class="{ 'op-spin': scanning }" />{{ scanning ? $t('Scanning...') : $t('Scan again') }}</button>
        </header>

        <ul class="op-tasklist">
          <li v-for="t in rows" :key="t.key" class="op-task" :class="{ 'is-on': picked.includes(t.key) }" :style="{ '--tone': t.tone }">
            <label class="op-check">
              <input type="checkbox" :value="t.key" v-model="picked" :disabled="!canRun" />
              <span class="op-ico"><icons :name="t.icon" class="w-5 h-5" /></span>
              <span class="op-main"><b>{{ t.title }}</b><small>{{ t.what }}</small></span>
            </label>
            <span class="op-est">
              <template v-if="t.scanned"><em>{{ t.bytes ? '~ ' + fmt(t.bytes) : $t('measured after') }}</em><small>{{ t.note }}</small></template>
              <small v-else>{{ scanning ? $t('Scanning...') : '' }}</small>
            </span>
          </li>
        </ul>

        <div class="op-actions">
          <span class="op-hint" v-if="canRun"><icons name="info" class="w-4 h-4" />{{ $t('Nothing that belongs to a record is deleted. Pictures keep their names; a copy of each original is kept for :n day(s).', { n: form.opt_keep_originals_days }) }}</span>
          <span class="op-hint" v-else><icons name="lock-closed" class="w-4 h-4" />{{ $t('You can read the history. Running an optimisation needs the permission "Optimize: scan and run".') }}</span>
          <button v-if="canRun" type="button" class="lg-btn tone tone-solid" style="--tone: #4f46e5" :disabled="!picked.length || busy || scanning" @click="ask"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Optimize now') }}</button>
        </div>
      </section>

      <!-- the latest result -->
      <section v-if="last" class="lg-card op-last">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Last optimisation') }} · {{ last.ref }} <span class="op-rate" :class="'is-' + last.rating">{{ last.rating_label }}</span></h2>
            <p class="lg-card-sub">{{ $datetime(last.at) }} · {{ last.user }} · {{ $t(':n s', { n: last.seconds }) }}</p>
          </div>
          <Link :href="route('optimizer.show', last.id)" class="lg-btn lg-btn-soft">{{ $t('Open') }}</Link>
        </header>
        <div class="op-gain">
          <div><b>{{ last.saved_text }}</b><span>{{ $t('space gained') }}</span></div>
          <div><b>{{ last.speed_pct === null ? '-' : (last.speed_pct > 0 ? '+' : '') + last.speed_pct + '%' }}</b><span>{{ $t('database speed') }}</span></div>
          <div><b>{{ last.files }}</b><span>{{ $t('files changed') }}</span></div>
          <div><b>{{ last.tables }}</b><span>{{ $t('tables rebuilt') }}</span></div>
        </div>
      </section>

      <!-- the numbers -->
      <section class="lg-card" id="op-numbers">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="cog" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('The numbers used') }}</h2>
            <p class="lg-card-sub">{{ $t('Every limit is a setting. The defaults are safe; change them only if you know what you want.') }}</p>
          </div>
          <button v-if="canRun" type="button" class="lg-btn tone tone-solid" style="--tone: #4f46e5" :disabled="form.processing" @click="save"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Save') }}</button>
        </header>
        <div class="op-grid">
          <label v-for="f in fields" :key="f.key" class="op-field">
            <span class="op-label"><icons :name="f.icon" class="w-4 h-4" />{{ f.label }}</span>
            <span class="op-input"><input v-model.number="form[f.key]" type="number" :min="limits[f.key][0]" :max="limits[f.key][1]" inputmode="numeric" :disabled="!canRun" /><em>{{ f.unit }}</em></span>
            <small>{{ f.hint }}</small>
            <small v-if="form.errors[f.key]" class="op-err">{{ form.errors[f.key] }}</small>
          </label>
        </div>
      </section>

      <!-- the audit history -->
      <section class="lg-card" id="op-history">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Audit history') }}</h2>
            <p class="lg-card-sub">{{ $t(':n optimisation(s) on record: who ran it, when, what was done and what was gained.', { n: runs.total }) }}</p>
          </div>
        </header>
        <div v-if="!runs.data.length" class="op-empty"><icons name="clock" class="w-5 h-5" />{{ $t('Nothing has been optimised yet.') }}</div>
        <div v-else class="lg-table-wrap">
          <table v-labelize class="lg-table lg-responsive">
            <thead><tr><th>{{ $t('Reference') }}</th><th>{{ $t('When') }}</th><th>{{ $t('Done by') }}</th><th>{{ $t('What') }}</th><th>{{ $t('Space gained') }}</th><th>{{ $t('Speed') }}</th><th>{{ $t('Status') }}</th><th>{{ $t('Actions') }}</th></tr></thead>
            <tbody>
              <tr v-for="r in runs.data" :key="r.id">
                <td class="op-nw"><b>{{ r.ref }}</b></td>
                <td class="op-nw">{{ $datetime(r.at) }}</td>
                <td>{{ r.user }}</td>
                <td><span v-for="k in r.tasks" :key="k" class="op-chip">{{ catalog[k] ? catalog[k].title : k }}</span></td>
                <td class="op-nw"><b>{{ r.saved_text }}</b></td>
                <td class="op-nw">{{ r.speed_pct === null ? '-' : (r.speed_pct > 0 ? '+' : '') + r.speed_pct + '%' }}</td>
                <td><span v-if="r.rating" class="op-rate" :class="'is-' + r.rating">{{ r.rating_label }}</span><span v-else class="op-status" :class="'is-' + r.status">{{ statusText(r.status) }}</span></td>
                <td class="op-row-actions">
                  <Link :href="route('optimizer.show', r.id)" class="lg-btn lg-btn-soft op-sm">{{ $t('View') }}</Link>
                  <a :href="route('optimizer.print', r.id)" target="_blank" rel="noopener" class="lg-btn lg-btn-soft op-sm"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="runs.last_page > 1" class="op-pager">
          <Link v-if="runs.prev_page_url" :href="runs.prev_page_url" preserve-scroll class="lg-btn lg-btn-soft op-sm">{{ $t('Previous') }}</Link>
          <span>{{ $t('Page :a of :b', { a: runs.current_page, b: runs.last_page }) }}</span>
          <Link v-if="runs.next_page_url" :href="runs.next_page_url" preserve-scroll class="lg-btn lg-btn-soft op-sm">{{ $t('Next') }}</Link>
        </div>
      </section>

      <run-overlay :open="ov.open" :steps="ov.steps" :finished="ov.finished" :failed="ov.failed" :error="ov.error" :rating-label="ov.rating" :saved-text="ov.saved" :started="ov.started" />

      <!-- the password: running changes data on the server -->
      <tec-dialog-modal :show="pw.open" max-width="md" @close="pw.open = false">
        <template #title><span class="op-dlg-title"><icons name="lock-closed" class="w-5 h-5" />{{ $t('Optimize now') }}</span></template>
        <template #content>
          <p class="op-dlg-text">{{ $t('This changes data on the server: :list. Enter your password to confirm.', { list: pickedTitles }) }}</p>
          <input v-model="pw.password" type="password" class="op-dlg-input" autocomplete="current-password" :placeholder="$t('Enter your password to confirm')" @keyup.enter="run" />
          <p v-if="pw.error" class="op-err">{{ pw.error }}</p>
        </template>
        <template #footer>
          <button type="button" class="lg-btn lg-btn-soft" @click="pw.open = false">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" :disabled="!pw.password || busy" @click="run"><icons name="tick" class="w-4 h-4" />{{ $t('Confirm') }}</button>
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
import RunOverlay from '@/Shared/Optimize/RunOverlay.vue';
import { Link } from '@inertiajs/vue3';

export default {
  components: { AdminLayout, PageHeader, StatTiles, TecDialogModal, RunOverlay, Link },
  // not named "settings": that name is the app-wide shared prop the layout and menu read
  props: { opt: Object, limits: Object, defaults: Object, catalog: Object, defaultTasks: Array, runs: Object, canRun: Boolean, last: Object },
  data() {
    return {
      form: this.$inertia.form({ ...this.opt }),
      picked: [...this.defaultTasks],
      scanData: null,
      scanning: false,
      busy: false,
      pw: { open: false, password: '', error: '' },
      ov: { open: false, steps: [], finished: false, failed: false, error: '', rating: '', saved: '', started: 0 },
    };
  },
  computed: {
    rows() {
      const est = {};
      ((this.scanData && this.scanData.tasks) || []).forEach(t => { est[t.key] = t; });
      return Object.keys(this.catalog).map(k => ({ key: k, ...this.catalog[k], scanned: !!est[k], bytes: est[k] ? est[k].bytes : 0, note: est[k] ? est[k].note : '' }));
    },
    tiles() {
      const s = this.scanData ? this.scanData.snapshot : null;
      const v = x => (s ? this.fmt(x) : '...');
      return [
        { icon: 'database', tone: 'violet', label: this.$t('Database (with its free space)'), value: v(s && s.db_total_bytes) },
        { icon: 'image', tone: 'forest', label: this.$t('Uploaded pictures'), value: v(s && s.images_bytes) },
        { icon: 'doc-text', tone: 'orange', label: this.$t('Server log files'), value: v(s && s.logs_bytes) },
        { icon: 'archive-box', tone: 'sky', label: this.$t('Free disk space'), value: v(s && s.disk_free_bytes) },
      ];
    },
    fields() {
      const t = this.$t;
      return [
        { key: 'opt_image_max_px', icon: 'image', label: t('Longest side of a picture'), unit: 'px', hint: t('A bigger picture is scaled down to this. 1600 is sharp on every screen.') },
        { key: 'opt_image_quality', icon: 'image', label: t('Picture quality'), unit: '%', hint: t('Used when a picture is saved again. 80 looks the same and is much smaller.') },
        { key: 'opt_image_min_kb', icon: 'image', label: t('Leave pictures smaller than'), unit: 'KB', hint: t('Small pictures are not touched (unless they are oversize).') },
        { key: 'opt_keep_originals_days', icon: 'archive-box', label: t('Keep a copy of each original'), unit: t('days'), hint: t('So a picture can be recovered by hand. 0 = keep no copy (the space is freed at once).') },
        { key: 'opt_log_gzip_days', icon: 'doc-text', label: t('Zip log files older than'), unit: t('days'), hint: t('Today and yesterday stay readable as they are.') },
        { key: 'opt_seconds', icon: 'clock', label: t('One run works for at most'), unit: t('seconds'), hint: t('If there is more to do, run it again to continue.') },
      ];
    },
    pickedTitles() { return this.picked.map(k => (this.catalog[k] ? this.catalog[k].title : k)).join(', '); },
  },
  mounted() { if (this.canRun) this.scan(); },
  methods: {
    fmt(b) {
      b = Number(b || 0);
      const neg = b < 0; b = Math.abs(b);
      const u = ['B', 'KB', 'MB', 'GB', 'TB'];
      let i = 0;
      while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
      return (neg ? '-' : '') + (i === 0 ? String(Math.round(b)) : b.toFixed(b < 10 ? 2 : 1)) + ' ' + u[i];
    },
    statusText(s) { return { done: this.$t('Completed'), partial: this.$t('Partly done'), failed: this.$t('Failed'), running: this.$t('Running') }[s] || s; },
    scan() {
      this.scanning = true;
      window.axios.get(this.route('optimizer.scan'), { headers: { 'X-Quiet': '1' } })
        .then(r => { this.scanData = r.data; })
        .catch(() => { this.$alert && this.$alert(this.$t('The scan could not be completed.')); })
        .finally(() => { this.scanning = false; });
    },
    save() {
      this.form.put(this.route('optimizer.settings'), { preserveScroll: true });
    },
    ask() { this.pw = { open: true, password: '', error: '' }; },
    // starts the run (the password is checked there), then asks for each task in turn so the window can show what is happening; a task that reached its time limit is asked again
    async run() {
      if (!this.pw.password || this.busy) return;
      this.busy = true;
      this.pw.error = '';
      let started;
      try {
        started = (await window.axios.post(this.route('optimizer.run'), { tasks: this.picked, password: this.pw.password }, { headers: { 'X-Quiet': '1' } })).data;
      } catch (e) {
        const er = e.response && e.response.data && e.response.data.errors;
        this.pw.error = (er && (er.password || er.tasks) && (er.password || er.tasks)[0]) || this.$t('Could not do that.');
        this.busy = false;
        return;
      }
      this.pw.open = false;
      this.ov = { open: true, steps: started.tasks.map(t => ({ key: t.key, title: t.title, state: 'wait', summary: '' })), finished: false, failed: false, error: '', rating: '', saved: '', started: Date.now() };
      const guard = e => { e.preventDefault(); e.returnValue = ''; };
      window.addEventListener('beforeunload', guard);
      try {
        for (const step of this.ov.steps) {
          step.state = 'running';
          for (let i = 0; i < 8; i++) {
            const r = (await window.axios.post(this.route('optimizer.step', started.id), { task: step.key }, { headers: { 'X-Quiet': '1' }, timeout: 0 })).data;
            step.summary = r.summary + (r.bytes ? ' · ' + r.bytes_text : '');
            step.state = r.ok ? 'done' : 'error';
            if (!r.more) break;
            step.state = 'running';
          }
        }
        const f = (await window.axios.post(this.route('optimizer.finish', started.id), {}, { headers: { 'X-Quiet': '1' } })).data;
        this.ov.finished = true;
        this.ov.failed = f.status === 'failed';
        this.ov.rating = f.rating_label;
        this.ov.saved = f.saved_text;
        await new Promise(r => setTimeout(r, 1400));
        window.removeEventListener('beforeunload', guard);
        this.ov.open = false;
        this.$inertia.visit(f.url);
      } catch (e) {
        window.removeEventListener('beforeunload', guard);
        this.ov.finished = true;
        this.ov.failed = true;
        this.ov.error = (e.response && e.response.data && e.response.data.message) || this.$t('The optimisation could not be completed. What was done is kept in the history.');
        this.ov.steps.forEach(s => { if (s.state === 'running') s.state = 'error'; });
        setTimeout(() => { this.ov.open = false; this.$inertia.visit(this.route('optimizer.index')); }, 4500);
      } finally {
        this.busy = false;
      }
    },
  },
};
</script>

<style scoped>
.op-tasklist { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.op-task { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; padding: 12px 14px; border-radius: 16px; border: 1px solid var(--lg-line); background: var(--lg-soft); }
.op-task.is-on { border-color: color-mix(in srgb, var(--tone) 55%, transparent); background: color-mix(in srgb, var(--tone) 7%, transparent); }
.op-check { display: flex; align-items: center; gap: 12px; flex: 1 1 360px; min-width: 0; cursor: pointer; }
.op-check input { width: 18px; height: 18px; flex: none; accent-color: var(--tone); }
.op-ico { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 12px; flex: none; color: #fff; background: var(--tone); }
.op-main { display: grid; gap: 2px; min-width: 0; } .op-main b { font-size: 0.9rem; color: var(--lg-ink); } .op-main small { font-size: 0.75rem; line-height: 1.4; color: var(--lg-mute); }
.op-est { display: grid; gap: 2px; text-align: right; min-width: 150px; } .op-est em { font-style: normal; font-weight: 800; font-size: 0.95rem; color: var(--tone); } .op-est small { font-size: 0.72rem; color: var(--lg-mute); }
.op-actions { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-top: 14px; }
.op-hint { display: inline-flex; align-items: flex-start; gap: 6px; max-width: 640px; font-size: 0.78rem; line-height: 1.4; font-weight: 600; color: var(--lg-mute); } .op-hint svg { flex: none; margin-top: 1px; }
.op-gain { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
.op-gain div { display: grid; gap: 2px; padding: 12px 14px; border-radius: 14px; border: 1px solid var(--lg-line); background: var(--lg-soft); } .op-gain b { font-size: 1.3rem; color: var(--lg-ink); } .op-gain span { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--lg-mute); }
.op-grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(min(100%, 250px), 1fr)); }
.op-field { display: grid; gap: 6px; padding: 12px 14px; border-radius: 16px; border: 1px solid var(--lg-line); background: var(--lg-soft); align-content: start; }
.op-label { display: flex; align-items: center; gap: 8px; font-size: 0.78rem; font-weight: 800; color: var(--lg-ink); }
.op-input { display: flex; align-items: center; gap: 8px; }
.op-input input { width: 100%; min-height: 2.4rem; padding: 0 12px; border-radius: 12px; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-ink); font-weight: 700; font-size: 0.9rem; }
.op-input input:focus { outline: 2px solid rgba(79, 70, 229, 0.4); }
.op-input em { font-style: normal; font-size: 0.78rem; font-weight: 700; color: var(--lg-mute); white-space: nowrap; }
.op-field small { font-size: 0.72rem; line-height: 1.35; color: var(--lg-mute); }
.op-err { color: #dc2626; font-size: 0.78rem; font-weight: 600; }
.op-nw { white-space: nowrap; }
.op-rate { display: inline-block; margin-left: 6px; padding: 2px 10px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.02em; vertical-align: middle; background: rgba(100, 116, 139, 0.16); color: #475569; }
.op-rate.is-excellent { background: rgba(5, 150, 105, 0.16); color: #047857; } .op-rate.is-very_good { background: rgba(13, 148, 136, 0.16); color: #0f766e; }
.op-rate.is-good { background: rgba(2, 132, 199, 0.15); color: #0369a1; } .op-rate.is-attention { background: rgba(220, 38, 38, 0.14); color: #b91c1c; }
.op-chip { display: inline-block; margin: 0 4px 4px 0; padding: 2px 8px; border-radius: 9999px; font-size: 0.7rem; font-weight: 700; background: var(--lg-soft); border: 1px solid var(--lg-line); color: var(--lg-ink); }
.op-status { display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800; background: rgba(22, 163, 74, 0.12); color: #15803d; }
.op-status.is-partial, .op-status.is-running { background: rgba(217, 119, 6, 0.14); color: #b45309; } .op-status.is-failed { background: rgba(220, 38, 38, 0.12); color: #b91c1c; }
.op-row-actions { display: flex; flex-wrap: wrap; gap: 6px; } .op-sm { min-height: 2rem; padding: 0 12px; font-size: 0.78rem; }
.op-empty { display: flex; align-items: center; gap: 8px; padding: 18px; font-weight: 600; color: var(--lg-mute); }
.op-pager { display: flex; align-items: center; justify-content: center; gap: 12px; margin-top: 12px; font-size: 0.8rem; font-weight: 700; color: var(--lg-mute); flex-wrap: wrap; }
.op-dlg-title { display: inline-flex; align-items: center; gap: 8px; } .op-dlg-text { font-size: 0.85rem; line-height: 1.5; margin-bottom: 12px; }
.op-dlg-input { width: 100%; min-height: 2.6rem; padding: 0 12px; border-radius: 12px; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-ink); }
.op-spin { animation: op-spin 0.9s linear infinite; } @keyframes op-spin { to { transform: rotate(360deg); } }
@media (max-width: 640px) { .op-est { text-align: left; min-width: 0; } }
</style>

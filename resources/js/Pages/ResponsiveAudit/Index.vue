<template>
  <admin-layout :title="$t('Responsive Audit')">
    <div class="lg-page sb px-4 md:px-0">
      <sb-hero icon="adjustments" :title="$t('Responsive Audit')" :subtitle="$t('Opens WIMS pages at phone, tablet and desktop widths and records buttons that wrap, clipped controls, small touch targets and sideways scrolling.')">
        <Link :href="route('responsive-audit.workflow')" class="sb-gbtn"><icons name="bell" class="w-4 h-4" />{{ $t('Alerts') }}</Link>
      </sb-hero>

      <!-- run -->
      <section v-if="can.run" class="sb-card mb-4" style="--i: 0">
        <h3 class="sb-card__title"><span class="sb-ico g-teal"><icons name="play" class="w-4 h-4" /></span>{{ $t('Run an audit') }}<small>{{ pages.length }} {{ $t('pages you can open') }}</small></h3>
        <div class="sb-fields">
          <div class="sb-field"><label><icons name="mobile" class="w-3 h-3" />{{ $t('Screen width') }}</label><auto-complete v-model="width" :suggestions="widthOptions" :placeholder="$t('Choose a width...')" :disabled="running" /></div>
          <div class="sb-field"><label><icons name="search" class="w-3 h-3" />{{ $t('Only pages containing') }}</label><input v-model="only" type="search" class="sb-input" :placeholder="$t('Leave empty for every page')" :disabled="running" /></div>
        </div>
        <div class="ra-actions">
          <button type="button" class="sb-btn is-primary" :disabled="running || !width || !chosen.length" @click="start"><icons name="play" class="w-4 h-4" />{{ running ? $t('Auditing...') : $t('Run audit') + ' (' + chosen.length + ')' }}</button>
          <button v-if="running" type="button" class="sb-btn" @click="stop"><icons name="pause" class="w-4 h-4" />{{ $t('Stop and save') }}</button>
        </div>
        <div v-if="running || progress.total" class="ra-progress">
          <div class="ra-progress__top"><b>{{ progress.done }} / {{ progress.total }}</b><span>{{ progress.current }}</span><em>{{ progress.found }} {{ $t('findings so far') }}</em></div>
          <i class="sb-meter g-teal"><u :style="{ '--w': pct + '%', '--i': 0 }"></u></i>
        </div>
        <p v-if="message" class="ra-msg" :class="{ 'is-bad': messageBad }"><icons :name="messageBad ? 'exclamation' : 'tick'" class="w-4 h-4" />{{ message }}</p>
        <p class="ra-note"><icons name="info" class="w-4 h-4 flex-none" />{{ $t('The pages open in a hidden frame in your own browser, as you. Nothing is clicked or saved. Keep this tab open until it finishes.') }}</p>
      </section>
      <iframe v-if="can.run" ref="frame" class="ra-frame" title="audit" aria-hidden="true" tabindex="-1" :style="{ width: (width || 390) + 'px' }"></iframe>

      <!-- earlier runs -->
      <section class="sb-card mb-4" style="--i: 1">
        <h3 class="sb-card__title"><span class="sb-ico g-navy"><icons name="list" class="w-4 h-4" /></span>{{ $t('Earlier audits') }}<small>{{ runs.length }}</small></h3>
        <p v-if="!runs.length" class="sb-none">{{ $t('No audit has been run yet.') }}</p>
        <div class="ra-runs">
          <button v-for="(r, i) in runs" :key="r.id" type="button" class="ra-run" :class="[tone(r), { 'is-on': open && open.run.id === r.id }]" :style="{ '--i': i }" @click="load(r.id)">
            <b>{{ r.findings }}</b><span>{{ r.width }}px</span><small>{{ r.pages }} {{ $t('pages') }} · {{ (r.at || '').slice(0, 16).replace('T', ' ') }}</small>
          </button>
        </div>
      </section>

      <!-- one run -->
      <section v-if="open" class="sb-card mb-4" style="--i: 2">
        <h3 class="sb-card__title"><span class="sb-ico g-violet"><icons name="chart-bar" class="w-4 h-4" /></span>{{ $t('Audit at') }} {{ open.run.width }}px
          <small>{{ open.run.findings }} {{ $t('findings') }}<template v-if="open.previous !== null"> · {{ $t('the run before had') }} {{ open.previous }}</template></small>
          <span class="ml-auto flex gap-2" v-if="can.print"><a :href="route('responsive-audit.runs.print', open.run.id)" target="_blank" rel="noopener" class="sb-btn"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a><a :href="route('responsive-audit.runs.csv', open.run.id)" class="sb-btn"><icons name="table" class="w-4 h-4" />CSV</a></span>
        </h3>
        <div class="sb-tiles mb-3">
          <div v-for="(c, i) in categories" :key="c.key" class="sb-tile" :class="'g-' + toneOf(i)" :style="{ '--i': i }"><span class="sb-tile__ico"><icons :name="iconOf(c.key)" /></span><b><sb-count :value="open.run.summary[c.key] || 0" /></b><span class="sb-tile__lbl">{{ $t(c.label) }}</span></div>
        </div>
        <div class="sb-fields mb-3">
          <div class="sb-field"><label><icons name="filter" class="w-3 h-3" />{{ $t('Problem') }}</label><auto-complete v-model="fCat" :suggestions="catOptions" :placeholder="$t('All problems')" clearable /></div>
          <div class="sb-field"><label><icons name="search" class="w-3 h-3" />{{ $t('Page') }}</label><input v-model="fPage" type="search" class="sb-input" :placeholder="$t('Search pages and controls..')" /></div>
        </div>
        <div class="sb-tablewrap"><table class="sb-table">
          <thead><tr><th>{{ $t('Page') }}</th><th>{{ $t('Problem') }}</th><th>{{ $t('Control') }}</th><th>{{ $t('Label') }}</th><th>{{ $t('Detail') }}</th></tr></thead>
          <tbody>
            <tr v-for="(f, i) in shown" :key="i"><td>{{ f.page }}</td><td>{{ $t(catLabel(f.category)) }}</td><td>{{ f.selector }}</td><td>{{ f.label }}</td><td>{{ f.detail }}</td></tr>
            <tr v-if="!shown.length"><td colspan="5" class="sbr-empty">{{ open.findings.length ? $t('No finding matches.') : $t('The audit found nothing to fix at this width.') }}</td></tr>
          </tbody>
        </table></div>
        <p v-if="open.findings.length > 300" class="sb-none mt-2">{{ $t('The table shows the first 300 matches. Print and CSV hold all of them.') }}</p>
      </section>

      <div class="sb-cols">
        <section class="sb-card" style="--i: 3">
          <h3 class="sb-card__title"><span class="sb-ico g-green"><icons name="tick" class="w-4 h-4" /></span>{{ $t('The standards') }}</h3>
          <ul class="ra-standards"><li v-for="(s, i) in standards" :key="i">{{ $t(s) }}</li></ul>
        </section>

        <form v-if="can.settings" class="sb-card" style="--i: 4" @submit.prevent="saveSettings">
          <h3 class="sb-card__title"><span class="sb-ico g-amber"><icons name="cog" class="w-4 h-4" /></span>{{ $t('Audit settings') }}</h3>
          <div class="ss-grid" style="margin-bottom: 0.8rem">
            <div class="ss-field"><label for="ra-min">{{ $t('Smallest touch target (px)') }}</label><input id="ra-min" v-model.number="form.min_target_px" type="number" min="20" max="64" /></div>
            <div class="ss-field"><label for="ra-w">{{ $t('Widths to audit (px, comma separated)') }}</label><input id="ra-w" v-model="widthsText" type="text" class="sb-input" /></div>
            <div class="ss-field ss-wide"><label for="ra-ex">{{ $t('Pages left out (one per line)') }}</label><textarea id="ra-ex" v-model="excludedText" rows="3" placeholder="/example"></textarea></div>
          </div>
          <div v-if="form.errors && Object.keys(form.errors).length" class="ss-err"><p v-for="(e, k) in form.errors" :key="k">{{ e }}</p></div>
          <button type="submit" class="sb-btn is-primary" :disabled="form.processing"><icons name="tick" class="w-4 h-4" />{{ $t('Save settings') }}</button>
        </form>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import SbHero from '@/Shared/SmartBrief/SbHero.vue';
import SbCount from '@/Shared/SmartBrief/SbCount.vue';
import { auditPage } from '@/Core/uiAudit';

const TONES = ['navy', 'teal', 'violet', 'green', 'rose', 'amber'];
const ICONS = { wrap: 'list', over: 'arrow-left', clipped: 'cross', small: 'search', scroll: 'menu', dialog: 'info' };

export default {
  components: { AdminLayout, Link, AutoComplete, SbHero, SbCount },
  props: { runs: Array, prefs: Object, standards: Array, categories: Array, pages: Array, can: Object },

  data() {
    return {
      width: this.prefs.widths[1] || this.prefs.widths[0] || 390, only: '', running: false, stopping: false,
      progress: { done: 0, total: 0, current: '', found: 0 }, message: '', messageBad: false,
      open: null, fCat: '', fPage: '',
      form: this.$inertia.form({ min_target_px: this.prefs.min_target_px, widths: [], excluded: [] }),
      widthsText: this.prefs.widths.join(', '), excludedText: (this.prefs.excluded || []).join('\n'),
    };
  },

  computed: {
    widthOptions() { return this.prefs.widths.map(w => ({ value: w, label: w + 'px ' + (w < 500 ? this.$t('(phone)') : w < 900 ? this.$t('(tablet)') : this.$t('(desktop)')) })); },
    chosen() { const q = this.only.trim().toLowerCase(); return q ? this.pages.filter(p => p.toLowerCase().includes(q)) : this.pages; },
    pct() { return this.progress.total ? Math.round((this.progress.done / this.progress.total) * 100) : 0; },
    catOptions() { return this.categories.map(c => ({ value: c.key, label: this.$t(c.label) })); },
    shown() {
      if (!this.open) return [];
      const q = this.fPage.trim().toLowerCase();
      return this.open.findings.filter(f => (!this.fCat || f.category === this.fCat) && (!q || (f.page + ' ' + (f.selector || '') + ' ' + (f.label || '')).toLowerCase().includes(q))).slice(0, 300);
    },
  },

  methods: {
    toneOf(i) { return TONES[i % TONES.length]; },
    iconOf(k) { return ICONS[k] || 'info'; },
    tone(r) { return r.findings === 0 ? 'g-green' : r.findings < 15 ? 'g-amber' : 'g-rose'; },
    catLabel(k) { return (this.categories.find(c => c.key === k) || {}).label || k; },
    load(id) {
      axios.get(route('responsive-audit.runs.show', id)).then(r => { this.open = r.data; this.fCat = ''; this.fPage = ''; this.$nextTick(() => window.scrollTo({ top: document.querySelector('.ra-runs').getBoundingClientRect().bottom + window.scrollY, behavior: 'smooth' })); });
    },
    stop() { this.stopping = true; },
    async start() {
      this.running = true; this.stopping = false; this.message = ''; this.messageBad = false;
      const list = this.chosen.slice(); const frame = this.$refs.frame; const findings = []; let scanned = 0; const started = Date.now();
      this.progress = { done: 0, total: list.length, current: '', found: 0 };
      for (const url of list) {
        if (this.stopping) break;
        this.progress.current = url;
        const r = await auditPage(frame, url, { minTarget: this.prefs.min_target_px });
        if (r.findings) { scanned++; r.findings.forEach(f => findings.push({ ...f, page: url })); this.progress.found = findings.length; }
        else if (r.skipped) { /* a page without the app shell (an error or redirect page) is not audited */ }
        this.progress.done++;
      }
      frame.src = 'about:blank';
      try {
        const { data } = await axios.post(route('responsive-audit.runs.store'), { width: this.width, pages_scanned: scanned, duration_seconds: Math.round((Date.now() - started) / 1000), findings: findings.slice(0, 3000) });
        this.message = this.$t('Saved: :n finding(s) on :p page(s).', { n: data.findings, p: scanned }) + (this.stopping ? ' ' + this.$t('(stopped early)') : '');
        this.$inertia.reload({ only: ['runs'], onSuccess: () => this.load(data.id) });
      } catch (e) {
        this.messageBad = true; this.message = (e.response && e.response.data && e.response.data.message) || this.$t('The result could not be saved.');
      }
      this.running = false;
    },
    saveSettings() {
      this.form.widths = this.widthsText.split(/[ ,]+/).map(Number).filter(n => n >= 240);
      this.form.excluded = this.excludedText.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
      this.form.put(route('responsive-audit.settings.update'), { preserveScroll: true });
    },
  },
};
</script>

<style>
.ra-frame { position: fixed; left: -12000px; top: 0; height: 900px; border: 0; visibility: hidden; pointer-events: none; }
.ra-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.9rem; }
.ra-progress { margin-top: 1rem; } .ra-progress__top { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.3rem 0.8rem; margin-bottom: 6px; font-size: 0.8rem; } .ra-progress__top span { min-width: 0; overflow-wrap: anywhere; color: var(--lg-mute); } .ra-progress__top em { margin-left: auto; font-style: normal; font-weight: 700; color: var(--lg-faint); }
.ra-progress .sb-meter u { transition: width 0.4s ease; animation: none; transform: none; }
.ra-msg { display: flex; align-items: center; gap: 0.5rem; margin: 0.9rem 0 0; padding: 0.55rem 0.9rem; border-radius: 14px; font-size: 0.85rem; font-weight: 700; color: #047857; background: rgba(16, 185, 129, 0.1); } .ra-msg.is-bad { color: #be123c; background: rgba(244, 63, 94, 0.09); }
.ra-note { display: flex; gap: 0.5rem; margin: 0.9rem 0 0; font-size: 0.78rem; line-height: 1.5; color: var(--lg-faint); }
.ra-runs { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 0.6rem; }
.app-shell .ra-run { position: relative; overflow: hidden; display: grid; gap: 1px; min-height: 0; padding: 0.7rem 0.9rem; text-align: left; font-family: inherit; border: 0; border-radius: 18px; color: #fff; cursor: pointer; background: linear-gradient(135deg, var(--g1), var(--g2)); box-shadow: 0 16px 22px -16px var(--g1); transition: transform 0.2s, box-shadow 0.2s; animation: sb-pop 0.5s both; animation-delay: calc(var(--i, 0) * 40ms); }
.app-shell .ra-run:hover { transform: translateY(-3px); } .app-shell .ra-run.is-on { box-shadow: 0 0 0 3px var(--lg-card), 0 0 0 5px var(--g2), 0 16px 22px -16px var(--g1); }
.ra-run b { font-size: 1.5rem; font-weight: 800; line-height: 1.05; } .ra-run span { font-size: 0.78rem; font-weight: 800; } .ra-run small { font-size: 0.68rem; opacity: 0.9; }
.ra-standards { margin: 0; padding-left: 1.1rem; display: grid; gap: 0.5rem; font-size: 0.84rem; line-height: 1.55; color: var(--lg-ink); } .ra-standards li::marker { color: #059669; }
</style>

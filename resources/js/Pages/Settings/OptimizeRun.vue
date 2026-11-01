<template>
  <admin-layout :title="run.ref">
    <div class="lg-page px-4 md:px-0 or-page">
      <page-header icon="chart-bar" :title="run.ref" :subtitle="$t('Optimisation report: what was done, who did it and what was gained.')">
        <Link :href="route('optimizer.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Back to Optimize') }}</Link>
        <a :href="route('optimizer.print', run.id)" target="_blank" rel="noopener" class="lg-btn tone tone-solid" style="--tone: #4f46e5"><span class="tone-ico"><icons name="printer" class="w-4 h-4" /></span>{{ $t('Print') }}</a>
        <a :href="route('optimizer.pdf', run.id)" class="lg-btn tone" style="--tone: #dc2626"><span class="tone-ico"><icons name="doc-text" class="w-4 h-4" /></span>{{ $t('PDF') }}</a>
      </page-header>

      <stat-tiles :items="tiles" compact />

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="user" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Run') }}</h2>
            <p class="lg-card-sub">{{ $t('Done by :n on :d, took :s s.', { n: run.user, d: $datetime(run.started), s: run.seconds }) }} <span class="or-status" :class="'is-' + run.status">{{ statusText(run.status) }}</span></p>
          </div>
        </header>
        <p v-if="run.error" class="or-error">{{ run.error }}</p>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="chart-bar" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Before and after') }}</h2>
            <p class="lg-card-sub">{{ $t('Sizes measured by the system at the start and at the end of the run.') }}</p>
          </div>
        </header>
        <div class="or-tablewrap">
          <table class="or-table">
            <thead><tr><th>{{ $t('What') }}</th><th class="r">{{ $t('Before') }}</th><th class="r">{{ $t('After') }}</th><th class="r">{{ $t('Gained') }}</th></tr></thead>
            <tbody>
              <tr v-for="p in run.pairs" :key="p.key">
                <td>{{ p.label }}</td><td class="r nw">{{ fmt(p.before) }}</td><td class="r nw">{{ fmt(p.after) }}</td>
                <td class="r nw"><b :class="gain(p) > 0 ? 'or-good' : (gain(p) < 0 ? 'or-bad' : '')">{{ gain(p) > 0 ? '+' : '' }}{{ fmt(gain(p)) }}</b></td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="or-foot">{{ $t('Backups use :b and are never touched by an optimisation. Kept originals of compressed pictures use :o.', { b: run.backups_text, o: run.originals_text }) }}</p>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Database speed') }}</h2>
            <p class="lg-card-sub">{{ $t('The same typical reads, timed before and after (the middle of five tries). Small differences are normal noise.') }}</p>
          </div>
        </header>
        <div class="or-tablewrap">
          <table class="or-table">
            <thead><tr><th>{{ $t('Read') }}</th><th class="r">{{ $t('Before (ms)') }}</th><th class="r">{{ $t('After (ms)') }}</th><th class="r">{{ $t('Change') }}</th></tr></thead>
            <tbody>
              <tr v-for="s in probes" :key="s.name">
                <td>{{ s.name }}</td><td class="r nw">{{ s.before.toFixed(2) }}</td><td class="r nw">{{ s.after.toFixed(2) }}</td>
                <td class="r nw"><b :class="s.pct > 0 ? 'or-good' : (s.pct < 0 ? 'or-bad' : '')">{{ s.pct > 0 ? '+' : '' }}{{ s.pct }}%</b></td>
              </tr>
              <tr class="or-total"><td>{{ $t('All together') }}</td><td class="r nw">{{ Number(run.speed_before).toFixed(2) }}</td><td class="r nw">{{ Number(run.speed_after).toFixed(2) }}</td>
                <td class="r nw"><b :class="(run.speed_pct || 0) > 0 ? 'or-good' : ((run.speed_pct || 0) < 0 ? 'or-bad' : '')">{{ run.speed_pct === null ? '-' : (run.speed_pct > 0 ? '+' : '') + run.speed_pct + '%' }}</b></td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-for="(r, key) in run.results" :key="key" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico" :class="r.ok ? 'lg-ico-teal' : 'lg-ico-maroon'"><icons :name="r.ok ? 'tick' : 'exclamation'" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ r.title }}</h2>
            <p class="lg-card-sub">{{ r.summary }} · {{ $t(':n changed', { n: r.changed }) }} · {{ r.bytes ? fmt(r.bytes) + ' ' + $t('gained') : $t('measured in the totals above') }} · {{ r.seconds }} s</p>
          </div>
        </header>
        <ul v-if="(r.notes || []).length" class="or-notes"><li v-for="(n, i) in r.notes" :key="i">{{ n }}</li></ul>
        <div v-if="(r.items || []).length" class="or-tablewrap">
          <table class="or-table or-small">
            <thead><tr><th>{{ $t('Item') }}</th><th class="r">{{ $t('Before') }}</th><th class="r">{{ $t('After') }}</th><th>{{ $t('Note') }}</th></tr></thead>
            <tbody>
              <tr v-for="(it, i) in shown(r.items)" :key="i">
                <td class="or-wrap">{{ it.name }}</td>
                <td class="r nw">{{ it.note === 'count' ? it.before : fmt(it.before) }}</td>
                <td class="r nw">{{ it.note === 'count' ? '-' : fmt(it.after) }}</td>
                <td class="nw">{{ it.note === 'count' ? '' : it.note }}</td>
              </tr>
            </tbody>
          </table>
          <p v-if="r.items.length > 15" class="or-foot">{{ $t('The first 15 of :n are shown here; the complete list is kept in the history.', { n: r.items.length }) }}</p>
        </div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="cog" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('The numbers used in this run') }}</h2></div>
        </header>
        <p class="or-foot">{{ $t('Longest side :a px, quality :b%, pictures under :c KB left alone, originals kept :d day(s), log files older than :e day(s) zipped, :f s at most.', { a: run.options.opt_image_max_px, b: run.options.opt_image_quality, c: run.options.opt_image_min_kb, d: run.options.opt_keep_originals_days, e: run.options.opt_log_gzip_days, f: run.options.opt_seconds }) }}</p>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import { Link } from '@inertiajs/vue3';

export default {
  components: { AdminLayout, PageHeader, StatTiles, Link },
  props: { run: Object },
  computed: {
    tiles() {
      const r = this.run;
      return [
        { icon: 'archive-box', tone: r.saved >= 0 ? 'forest' : 'maroon', label: this.$t('Space gained'), value: this.fmt(r.saved) },
        { icon: 'clock', tone: 'sky', label: this.$t('Database speed'), value: r.speed_pct === null ? '-' : (r.speed_pct > 0 ? '+' : '') + r.speed_pct + '%' },
        { icon: 'doc-text', tone: 'orange', label: this.$t('Files changed'), value: r.files },
        { icon: 'database', tone: 'violet', label: this.$t('Tables rebuilt'), value: r.tables },
      ];
    },
    probes() {
      const after = {};
      ((this.run.speed.after || {}).items || []).forEach(i => { after[i.name] = i.ms; });
      return (((this.run.speed.before || {}).items) || []).map(i => {
        const a = after[i.name] ?? i.ms;
        return { name: i.name, before: i.ms, after: a, pct: i.ms > 0 ? Math.round(((i.ms - a) / i.ms) * 1000) / 10 : 0 };
      });
    },
  },
  methods: {
    fmt(b) {
      b = Number(b || 0);
      const neg = b < 0; b = Math.abs(b);
      const u = ['B', 'KB', 'MB', 'GB', 'TB'];
      let i = 0;
      while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
      return (neg ? '-' : '') + (i === 0 ? String(Math.round(b)) : b.toFixed(b < 10 ? 2 : 1)) + ' ' + u[i];
    },
    // space gained for one line: smaller is better, except free disk space where more is better
    gain(p) { return p.more_is_better ? p.after - p.before : p.before - p.after; },
    shown(items) { return (items || []).slice(0, 15); },
    statusText(s) { return { done: this.$t('Completed'), partial: this.$t('Partly done'), failed: this.$t('Failed'), running: this.$t('Running') }[s] || s; },
  },
};
</script>

<style scoped>
.or-tablewrap { overflow-x: auto; }
.or-table { width: 100%; border-collapse: collapse; font-size: 0.84rem; }
.or-table th { text-align: left; padding: 8px 10px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: var(--lg-mute); border-bottom: 1px solid var(--lg-line); white-space: nowrap; }
.or-table td { padding: 9px 10px; border-bottom: 1px solid var(--lg-line); }
.or-table .r { text-align: right; } .or-table .nw { white-space: nowrap; } .or-wrap { overflow-wrap: anywhere; }
.or-small { font-size: 0.78rem; }
.or-total td { font-weight: 800; background: var(--lg-soft); }
.or-good { color: #15803d; } .or-bad { color: #b91c1c; }
.or-foot { margin: 10px 0 0; font-size: 0.78rem; line-height: 1.45; color: var(--lg-mute); }
.or-notes { margin: 0 0 10px; padding-left: 18px; font-size: 0.8rem; line-height: 1.5; color: var(--lg-mute); }
.or-error { margin: 0; padding: 10px 12px; border-radius: 12px; background: rgba(220, 38, 38, 0.08); color: #b91c1c; font-size: 0.82rem; }
.or-status { display: inline-block; margin-left: 6px; padding: 2px 10px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800; background: rgba(22, 163, 74, 0.12); color: #15803d; }
.or-status.is-partial, .or-status.is-running { background: rgba(217, 119, 6, 0.14); color: #b45309; } .or-status.is-failed { background: rgba(220, 38, 38, 0.12); color: #b91c1c; }
</style>

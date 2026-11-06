<template>
  <admin-layout :title="run.ref">
    <div class="lg-page px-4 md:px-0 wlr-page">
      <page-header icon="archive-box" :title="run.ref" :subtitle="$t('WIMS Logs run: what was archived, how big it was before and after, and who did it.')">
        <Link :href="route('wims-logs.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Back to WIMS Logs') }}</Link>
        <a :href="route('wims-logs.print', run.id)" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
        <a :href="route('wims-logs.pdf', run.id)" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />{{ $t('PDF') }}</a>
      </page-header>

      <stat-tiles :items="tiles" compact />

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="user" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('The run') }} <span class="wlr-status" :class="'is-' + run.status">{{ statusText(run.status) }}</span></h2>
            <p class="lg-card-sub">{{ run.trigger === 'schedule' ? $t('Started by the schedule') : $t('Run by :n', { n: run.user }) }} · {{ $datetime(run.started) }}<template v-if="run.finished"> - {{ $datetime(run.finished) }}</template> · {{ $t(':n s', { n: run.seconds }) }}</p>
          </div>
        </header>
        <p v-if="run.error" class="wlr-err">{{ run.error }}</p>
        <div class="wlr-sizes">
          <div v-for="p in pairs" :key="p.label"><span>{{ p.label }}</span><b>{{ fmt(p.before) }} <i>&gt;</i> {{ fmt(p.after) }}</b><em :class="{ 'is-good': p.before >= p.after }">{{ p.before - p.after >= 0 ? '-' : '+' }}{{ fmt(Math.abs(p.before - p.after)) }}</em></div>
        </div>
        <p class="wlr-keep"><icons name="clock" class="w-4 h-4" />{{ $t('Days each log stays live') }}: <template v-for="(d, k) in run.keep_days" :key="k"><b>{{ run.labels[k] || k }}</b> {{ d }} {{ $t('days') }}; </template></p>
      </section>

      <!-- every archive of the run -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="archive-box" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('What was archived') }}</h2>
            <p class="lg-card-sub">{{ $t('One archive for each log. Open it to read every record, with the size it had before and the size it has now.') }}</p>
          </div>
        </header>
        <div v-if="!run.archives.length" class="wlr-empty">{{ $t('Nothing was old enough to move in this run.') }}</div>
        <div v-else class="lg-table-wrap">
          <table v-labelize class="lg-table lg-responsive">
            <thead><tr><th>{{ $t('Archive') }}</th><th>{{ $t('Log') }}</th><th>{{ $t('Records') }}</th><th>{{ $t('Before') }}</th><th>{{ $t('Compressed') }}</th><th>{{ $t('On disk') }}</th><th>{{ $t('Saved') }}</th><th>{{ $t('Period') }}</th><th>{{ $t('Check') }}</th><th>{{ $t('Actions') }}</th></tr></thead>
            <tbody>
              <tr v-for="a in run.archives" :key="a.id">
                <td class="wlr-nw"><b>{{ a.ref }}</b></td>
                <td>{{ a.label }}<small class="wlr-sub">{{ a.table }}</small></td>
                <td class="wlr-nw">{{ num(a.rows) }}<small v-if="a.skipped" class="wlr-sub is-warn">{{ a.skipped }} {{ $t('left live (could not be archived safely)') }}</small></td>
                <td class="wlr-nw">{{ fmt(a.original) }}</td>
                <td class="wlr-nw">{{ fmt(a.compressed) }}</td>
                <td class="wlr-nw">{{ fmt(a.stored) }}</td>
                <td class="wlr-nw"><b class="is-good">{{ fmt(a.saved) }}</b><small v-if="a.saved_pct !== null" class="wlr-sub">{{ a.saved_pct }}%</small></td>
                <td class="wlr-nw">{{ day(a.date_from) }} - {{ day(a.date_to) }}</td>
                <td><span class="wlr-chip" :class="a.verified ? 'is-ok' : 'is-bad'">{{ a.verified ? $t('Verified') : $t('Not verified') }}</span><small v-if="a.error" class="wlr-sub is-warn">{{ a.error }}</small></td>
                <td class="wlr-actions">
                  <Link v-if="a.available" :href="route('wims-logs.archives.show', a.id)" class="lg-btn lg-btn-primary wlr-sm"><icons name="search" class="w-4 h-4" />{{ $t('Read records') }}</Link>
                  <a v-if="a.available" :href="route('wims-logs.archives.download', a.id)" class="lg-btn lg-btn-soft wlr-sm"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</a>
                  <span v-else class="wlr-sub is-warn">{{ $t('File not found on this server') }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="run.optimized.length" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="database" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Database tables compacted') }}</h2>
            <p class="lg-card-sub">{{ $t('After records leave a table, the table is rebuilt so its files really get smaller.') }}</p>
          </div>
        </header>
        <ul class="wlr-list">
          <li v-for="t in run.optimized" :key="t.table"><b>{{ t.table }}</b><span>{{ fmt(t.before) }} <i>&gt;</i> {{ fmt(t.after) }}</span><em :class="{ 'is-good': t.before >= t.after }">{{ t.before - t.after >= 0 ? '-' : '+' }}{{ fmt(Math.abs(t.before - t.after)) }}</em><small v-if="!t.ok">{{ $t('not compacted (not allowed by the server)') }}</small></li>
        </ul>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Database speed, read by read') }}</h2>
            <p class="lg-card-sub">{{ $t('A fixed set of typical reads, timed before and after (the middle of five tries). Small differences are normal noise.') }}</p>
          </div>
        </header>
        <ul class="wlr-list">
          <li v-for="r in reads" :key="r.name"><b>{{ r.name }}</b><span>{{ r.before.toFixed(2) }} ms <i>&gt;</i> {{ r.after.toFixed(2) }} ms</span><em :class="{ 'is-good': r.after <= r.before }">{{ r.before > 0 ? (r.after <= r.before ? '-' : '+') + Math.abs(Math.round(((r.before - r.after) / r.before) * 100)) + '%' : '' }}</em></li>
        </ul>
      </section>

      <section v-if="run.printed.length" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="printer" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Printed and downloaded by') }}</h2></div></header>
        <ul class="wlr-list"><li v-for="(p, i) in run.printed" :key="i"><b>{{ p.who }}</b><span>{{ p.action }}</span><em>{{ $datetime(p.at) }}</em></li></ul>
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
        { icon: 'doc-text', tone: 'orange', label: this.$t('Records archived'), value: this.num(r.rows) },
        { icon: 'tick', tone: 'forest', label: this.$t('Saved by compression'), value: this.fmt(r.saved) },
        { icon: 'database', tone: 'violet', label: this.$t('Database made smaller'), value: this.fmt(r.freed) },
        { icon: 'clock', tone: 'sky', label: this.$t('Database speed'), value: r.speed_pct === null ? '-' : (r.speed_pct > 0 ? '+' : '') + r.speed_pct + '%' },
      ];
    },
    pairs() {
      const r = this.run;
      return [
        { label: this.$t('Whole database (with its free space)'), before: r.db.before, after: r.db.after },
        { label: this.$t('Archived records: in the database > in the archive'), before: r.original, after: r.stored },
      ];
    },
    reads() {
      const after = {};
      ((this.run.speed.after && this.run.speed.after.items) || []).forEach(i => { after[i.name] = i.ms; });
      return ((this.run.speed.before && this.run.speed.before.items) || []).map(i => ({ name: i.name, before: Number(i.ms), after: Number(after[i.name] !== undefined ? after[i.name] : i.ms) }));
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
    num(n) { return Number(n || 0).toLocaleString(); },
    day(d) { return d ? String(d).slice(0, 10) : '-'; },
    statusText(s) { return { done: this.$t('Completed'), partial: this.$t('Partly done'), failed: this.$t('Failed'), running: this.$t('Running') }[s] || s; },
  },
};
</script>

<style scoped>
.wlr-status { display: inline-block; margin-left: 0.4rem; padding: 0.15rem 0.6rem; border-radius: 999px; font-size: 0.7rem; font-weight: 800; background: #e2e8f0; color: #334155; vertical-align: middle; }
.wlr-status.is-done { background: #d1fae5; color: #065f46; } .wlr-status.is-partial { background: #fef3c7; color: #92400e; } .wlr-status.is-failed { background: #fee2e2; color: #991b1b; }
.wlr-sizes { display: grid; gap: 0.5rem; } .wlr-sizes > div { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.4rem 0.9rem; padding: 0.6rem 0.85rem; border-radius: 0.9rem; background: rgba(148, 163, 184, 0.1); }
.wlr-sizes span { flex: 1 1 16rem; font-size: 0.8rem; font-weight: 700; color: #475569; } .wlr-sizes b { font-size: 1rem; font-variant-numeric: tabular-nums; } .wlr-sizes i, .wlr-list i { font-style: normal; color: #94a3b8; margin: 0 0.2rem; } .wlr-sizes em, .wlr-list em { font-style: normal; font-weight: 800; font-size: 0.82rem; color: #b45309; }
.is-good { color: #047857 !important; } html.dark .is-good { color: #34d399 !important; }
.wlr-keep { display: flex; flex-wrap: wrap; gap: 0.3rem 0.5rem; align-items: center; margin: 0.8rem 0 0; font-size: 0.76rem; color: #64748b; }
.wlr-err { color: #dc2626; font-size: 0.82rem; font-weight: 700; margin: 0 0 0.6rem; } .wlr-empty { padding: 1rem 0.3rem; color: #64748b; font-weight: 600; }
.wlr-nw { white-space: nowrap; } .wlr-sub { display: block; font-size: 0.72rem; color: #64748b; font-weight: 600; } .wlr-sub.is-warn { color: #b45309; white-space: normal; max-width: 16rem; }
.wlr-chip { display: inline-block; padding: 0.15rem 0.6rem; border-radius: 999px; font-size: 0.7rem; font-weight: 800; } .wlr-chip.is-ok { background: #d1fae5; color: #065f46; } .wlr-chip.is-bad { background: #fee2e2; color: #991b1b; }
.wlr-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; } .wlr-sm { padding: 0.3rem 0.7rem !important; font-size: 0.74rem !important; }
.wlr-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.35rem; } .wlr-list li { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.3rem 0.9rem; padding: 0.45rem 0.8rem; border-radius: 0.8rem; background: rgba(148, 163, 184, 0.1); } .wlr-list b { flex: 1 1 14rem; font-size: 0.82rem; } .wlr-list span { font-variant-numeric: tabular-nums; font-size: 0.82rem; } .wlr-list small { color: #b45309; font-size: 0.72rem; }
</style>

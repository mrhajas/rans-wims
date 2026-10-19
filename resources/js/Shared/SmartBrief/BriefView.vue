<template>
  <div class="sb-brief">
    <div v-if="loading && !brief" class="sb-state"><span class="sb-spin"></span>{{ $t('Preparing the brief...') }}</div>
    <div v-else-if="error" class="sb-state sb-state--bad"><icons name="exclamation" class="w-6 h-6" /><b>{{ error }}</b></div>
    <div v-else-if="brief" class="grid gap-4" :key="brief.generated_at">
      <!-- who and when -->
      <div class="sb-person">
        <span class="sb-av">{{ initials(brief.subject.name) }}</span>
        <div class="min-w-0">
          <h2>{{ brief.subject.is_self ? $t('My Smart Brief') : brief.subject.name }}</h2>
          <p>
            <span v-for="r in (brief.subject.is_self ? [] : brief.subject.roles)" :key="r" class="sb-role">{{ r }}</span>
            <span class="inline-flex items-center gap-1"><icons name="calendar" class="w-3.5 h-3.5" />{{ periodText }}</span>
          </p>
        </div>
        <span v-if="loading" class="sb-spin ml-auto"></span>
      </div>

      <!-- the summary -->
      <section class="sb-card" style="--i: 0">
        <div class="sb-card__title"><span class="sb-secno">{{ nums.summary }}</span><span class="sb-ico g-violet"><icons name="sparkles" class="w-4 h-4" /></span>{{ $t('Summary') }}<small>{{ $t('Built only from records WIMS holds. Select a sentence to see the records behind it; links open in a new tab.') }}</small></div>
        <ul class="sb-sentences">
          <li v-for="(s, i) in brief.summary.sentences" :key="i" :class="['is-' + s.tone, { 'is-picked': picked === i, 'is-click': s.evidence.length || (s.links && s.links.length) }]" :style="{ '--i': i }">
            <span class="sb-no">{{ i + 1 }}</span>
            <div class="sb-sentence__body">
              <button v-if="s.evidence.length" type="button" :disabled="!brief.sections.timeline" class="sb-sentence lg-tip" :data-tip="brief.sections.timeline ? $t('Show the records behind this sentence') : $t('The timeline is not available to you')" @click="pick(i, s)">
                <span>{{ s.text }}</span><em>{{ s.count }}</em>
              </button>
              <div v-else class="sb-sentence is-static"><span>{{ s.text }}</span></div>
              <span v-if="s.links && s.links.length" class="sb-slinks"><a v-for="l in s.links" :key="l.url" :href="l.url" target="_blank" rel="noopener"><icons name="link" class="w-3 h-3" />{{ l.label }}</a></span>
            </div>
          </li>
          <li v-if="!brief.summary.sentences.length" class="sb-none">{{ $t('There is no recorded activity for this period.') }}</li>
        </ul>
        <p v-if="brief.summary.ai" class="sb-note-line mt-3"><icons name="sparkles" class="w-3.5 h-3.5" />{{ $t('Wording improved by AI. The facts come from the records above.') }}</p>
      </section>

      <!-- numbers -->
      <div class="sb-tiles">
        <div v-for="(t, i) in tiles" :key="t.label" class="sb-tile" :class="'g-' + t.tone" :style="{ '--i': i }">
          <span class="sb-tile__ico"><icons :name="t.icon" /></span>
          <b><sb-count :value="t.value" /></b><span class="sb-tile__lbl">{{ t.label }}</span>
        </div>
      </div>

      <div class="sb-cols">
        <!-- sessions -->
        <section v-if="brief.sections.sessions" class="sb-card" style="--i: 1">
          <div class="sb-card__title"><span class="sb-secno">{{ nums.sessions }}</span><span class="sb-ico g-teal"><icons name="key" class="w-4 h-4" /></span>{{ $t('Sign-in and sessions') }}</div>
          <p v-if="!brief.sessions.length" class="sb-none">{{ $t('No sign-in in this period.') }}</p>
          <ul v-else class="sb-sessions">
            <li v-for="(s, i) in brief.sessions.slice(0, 12)" :key="s.login_id" :style="{ '--i': i }">
              <span class="sb-dot" :class="{ 'is-live': s.end === 'active' }"></span>
              <div class="min-w-0"><b>{{ clock(s.login_at) }}</b> <small>{{ day(s.login_at) }}</small>
                <span class="sb-end" :class="'is-' + s.end">{{ endText(s) }}</span>
                <small v-if="s.last_activity_at" class="sb-sub">{{ $t('Last recorded activity') }}: {{ clock(s.last_activity_at) }}</small>
                <small v-if="s.device" class="sb-sub">{{ s.device }}<template v-if="s.ip"> · {{ s.ip }}</template><template v-if="s.location"> · {{ s.location }}</template></small>
              </div>
            </li>
          </ul>
          <p v-if="brief.sessions.length > 12" class="sb-none mt-2">+ {{ brief.sessions.length - 12 }} {{ $t('more sign-ins are in the timeline') }}</p>
        </section>

        <!-- waiting -->
        <section v-if="brief.pending.length || brief.sections.own" class="sb-card" style="--i: 2">
          <div class="sb-card__title"><span class="sb-secno">{{ nums.waiting }}</span><span class="sb-ico g-amber"><icons name="clock" class="w-4 h-4" /></span>{{ $t('Waiting right now') }}</div>
          <p v-if="brief.pending.length" class="sb-wait-sub">{{ $t('Right now, not for the chosen period. Each line opens its list in a new tab.') }}</p>
          <p v-if="!brief.pending.length" class="sb-none">{{ $t('Nothing is waiting for action.') }}</p>
          <waiting-list v-else :items="brief.pending" />
        </section>
      </div>

      <!-- by module -->
      <section v-if="brief.stats.modules.length" class="sb-card" style="--i: 3">
        <div class="sb-card__title"><span class="sb-secno">{{ nums.modules }}</span><span class="sb-ico g-sky"><icons name="chart-bar" class="w-4 h-4" /></span>{{ $t('Activity by module') }}</div>
        <ul class="sb-bars">
          <li v-for="(m, i) in brief.stats.modules.slice(0, 10)" :key="m.module" :style="{ '--i': i }">
            <span><i class="sb-no is-sm">{{ i + 1 }}</i>{{ m.module }}</span>
            <i class="sb-meter" :class="palette(i)"><u :style="{ '--w': Math.max(5, (m.count / maxModule) * 100) + '%', '--i': i }"></u></i>
            <b>{{ m.count }}</b>
          </li>
        </ul>
      </section>

      <!-- timeline -->
      <section v-if="brief.sections.timeline" ref="tl" class="sb-card" style="--i: 4">
        <div class="sb-card__title"><span class="sb-secno">{{ nums.timeline }}</span><span class="sb-ico g-navy"><icons name="list" class="w-4 h-4" /></span>{{ $t('Activity timeline') }}
          <small>{{ shownRows.length }} / {{ visibleRows.length }}<template v-if="visibleRows.length !== brief.timeline.length"> ({{ brief.timeline.length }})</template></small>
          <label class="sb-lines"><input type="checkbox" :checked="showLines" @change="$emit('lines', $event.target.checked)" /> {{ $t('Show line entries') }}</label>
        </div>
        <div class="sb-fields mb-3">
          <div class="sb-field"><label><icons name="search" class="w-3 h-3" />{{ $t('Search') }}</label><input v-model="fText" type="search" class="sb-input" :placeholder="$t('Search the timeline..')" autocomplete="off" /></div>
          <div class="sb-field"><label><icons name="filter" class="w-3 h-3" />{{ $t('Type') }}</label><auto-complete v-model="fKind" :suggestions="kindOptions" :placeholder="$t('All types')" clearable /></div>
          <div class="sb-field"><label><icons name="collection" class="w-3 h-3" />{{ $t('Module') }}</label><auto-complete v-model="fModule" :suggestions="moduleOptions" :placeholder="$t('All modules')" clearable /></div>
        </div>
        <div v-if="hiddenCount || expanded" class="sb-more is-top">
          <button type="button" class="sb-expander" :aria-expanded="expanded ? 'true' : 'false'" @click="toggleExpanded">
            <span class="sb-expander__ico"><svg viewBox="0 0 20 20" aria-hidden="true"><path :d="expanded ? 'M5 12.5l5-5 5 5' : 'M5 7.5l5 5 5-5'" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" /></svg></span>
            <span v-if="!expanded">{{ $t('Show') }} {{ hiddenCount }} {{ $t('more activities') }}</span>
            <span v-else>{{ $t('Show only the latest') }} {{ limit }}</span>
          </button>
        </div>
        <p v-if="!visibleRows.length" class="sb-none">{{ $t('No matching activity.') }}</p>
        <div v-for="g in grouped" :key="g.date" class="sb-day">
          <h4 v-if="grouped.length > 1 || !brief.period.single_day">{{ dayLabel(g.date) }}</h4>
          <ol class="sb-tl">
            <li v-for="(e, i) in g.rows" :key="e.id" :style="{ '--i': i }" :class="['k-' + e.kind, { 'is-line': e.line, 'is-hl': highlight && highlight.has(e.id) }]">
              <time>{{ e.time }}</time>
              <span class="sb-pin" :class="'k-' + e.kind"><icons :name="kindIcon(e.kind)" class="w-3 h-3" /></span>
              <div class="sb-ev" :class="{ 'is-link': e.url }" :title="e.url ? $t('Open in a new tab') : null" @click="go(e, $event)">
                <div class="sb-ev__top">
                  <component :is="e.url ? 'a' : 'b'" :href="e.url || undefined" :target="e.url ? '_blank' : undefined" :rel="e.url ? 'noopener' : undefined" class="sb-ev__title">{{ e.title }}<icons v-if="e.url" name="link" class="w-3 h-3" /></component>
                  <span v-if="e.record" class="sb-rec">{{ e.record }}</span>
                  <span class="sb-mod" :class="palette(modIndex(e.module))">{{ e.module }}</span>
                </div>
                <p v-if="e.note" class="sb-note">{{ e.note }}</p>
                <p v-if="e.device || e.ip" class="sb-note">{{ e.device }}<template v-if="e.ip"> · {{ e.ip }}</template><template v-if="e.location"> · {{ e.location }}</template></p>
                <button v-if="e.changes && e.changes.length" type="button" class="sb-link" @click="toggle(e.id)">{{ open[e.id] ? $t('Hide changes') : $t('Show') + ' ' + e.changes.length + ' ' + $t('changed fields') }}</button>
                <table v-if="open[e.id]" class="sb-chg"><thead><tr><th>{{ $t('Field') }}</th><th>{{ $t('Before') }}</th><th>{{ $t('After') }}</th></tr></thead><tbody><tr v-for="c in e.changes" :key="c.field"><td>{{ c.field }}</td><td>{{ c.from || '-' }}</td><td>{{ c.to || '-' }}</td></tr></tbody></table>
              </div>
            </li>
          </ol>
        </div>
      </section>

      <!-- limits -->
      <section class="sb-card sb-cov" style="--i: 5">
        <button type="button" class="sb-cov-btn" @click="cov = !cov"><span class="sb-ico g-pink"><icons name="info" class="w-4 h-4" /></span>{{ $t('What this brief cannot show') }}<icons name="chevron-down" class="w-4 h-4 sb-chev" :class="{ up: cov }" /></button>
        <ul v-if="cov"><li v-for="(n, i) in brief.coverage" :key="i">{{ $t(n) }}</li></ul>
      </section>
    </div>
  </div>
</template>

<script>
import AutoComplete from '@/Shared/AutoComplete.vue';
import SbCount from '@/Shared/SmartBrief/SbCount.vue';
import WaitingList from '@/Shared/SmartBrief/WaitingList.vue';

const KIND_ICON = { login: 'key', logout: 'key', login_failed: 'exclamation', created: 'plus', updated: 'edit', submitted: 'send', approved: 'tick', rejected: 'cross', returned: 'reply', deleted: 'trash', restored: 'refresh', completed: 'tick', comment: 'chat', report: 'printer', clock: 'clock', viewed: 'eye', other: 'info' };
const PALETTE = ['g-navy', 'g-teal', 'g-violet', 'g-green', 'g-amber', 'g-rose', 'g-sky', 'g-pink'];

export default {
  components: { AutoComplete, SbCount, WaitingList },
  props: { brief: Object, loading: Boolean, error: String, showLines: Boolean },
  emits: ['lines'],
  data() {
    return { fText: '', fKind: '', fModule: '', picked: null, highlight: null, open: {}, cov: false, expanded: false, limit: 10 };
  },
  computed: {
    periodText() {
      const p = this.brief.period;
      return p.single_day ? this.fmtDate(p.from) : this.fmtDate(p.from) + ' - ' + this.fmtDate(p.to);
    },
    tiles() {
      const s = this.brief.stats;
      const t = [
        { label: this.$t('Records created'), value: s.created, tone: 'navy', icon: 'plus' },
        { label: this.$t('Records updated'), value: s.updated, tone: 'sky', icon: 'edit' },
        { label: this.$t('Submitted'), value: s.submitted, tone: 'violet', icon: 'send' },
        { label: this.$t('Approved'), value: s.approved, tone: 'green', icon: 'tick' },
        { label: this.$t('Rejected'), value: s.rejected, tone: 'rose', icon: 'cross' },
        { label: this.$t('Reports and downloads'), value: s.reports, tone: 'pink', icon: 'printer' },
      ];
      if (this.brief.sections.sessions) t.unshift({ label: this.$t('Sign-ins'), value: s.logins, tone: 'teal', icon: 'key' });
      if (this.brief.sections.sensitive && s.failed_logins) t.push({ label: this.$t('Failed sign-ins'), value: s.failed_logins, tone: 'amber', icon: 'exclamation' });
      return t;
    },
    maxModule() { return Math.max(1, ...this.brief.stats.modules.map(m => m.count)); },
    // section numbers follow what this person is actually shown
    nums() {
      const b = this.brief; const on = { summary: true, sessions: !!b.sections.sessions, waiting: !!(b.pending.length || b.sections.own), modules: !!b.stats.modules.length, timeline: !!b.sections.timeline };
      let n = 0; const out = {};
      ['summary', 'sessions', 'waiting', 'modules', 'timeline'].forEach(k => { out[k] = on[k] ? ++n : ''; });
      return out;
    },
    kindOptions() { return [...new Set(this.brief.timeline.map(e => e.kind))].map(k => ({ value: k, label: this.kindLabel(k) })); },
    moduleOptions() { return [...new Set(this.brief.timeline.map(e => e.module))].sort().map(m => ({ value: m, label: m })); },
    moduleList() { return this.moduleOptions.map(m => m.value); },
    visibleRows() {
      const q = (this.fText || '').trim().toLowerCase();
      return this.brief.timeline.filter(e => {
        if (!this.showLines && e.line) return false;
        if (this.highlight && !this.highlight.has(e.id)) return false;
        if (this.fKind && e.kind !== this.fKind) return false;
        if (this.fModule && e.module !== this.fModule) return false;
        return !q || (e.title + ' ' + (e.record || '') + ' ' + e.module + ' ' + (e.note || '')).toLowerCase().includes(q);
      });
    },
    // only the first 10 lines are shown until the person expands the list
    shownRows() { return this.expanded ? this.visibleRows : this.visibleRows.slice(0, this.limit); },
    hiddenCount() { return Math.max(0, this.visibleRows.length - this.limit); },
    grouped() {
      const out = [];
      this.shownRows.forEach(e => {
        let g = out[out.length - 1];
        if (!g || g.date !== e.date) { g = { date: e.date, rows: [] }; out.push(g); }
        g.rows.push(e);
      });
      return out;
    },
  },
  watch: { brief() { this.clear(); this.open = {}; } },
  methods: {
    toggleExpanded() { this.expanded = !this.expanded; if (!this.expanded) this.$nextTick(() => this.$refs.tl && this.$refs.tl.scrollIntoView({ block: 'start', behavior: 'smooth' })); },
    // the whole line opens its page in a new tab; the changed-fields button, links and tables inside it keep their own behaviour
    go(e, ev) { if (!e.url || ev.target.closest('a, button, table, input, select')) return; window.open(e.url, '_blank', 'noopener'); },
    palette(i) { return PALETTE[i % PALETTE.length]; },
    modIndex(m) { return Math.max(0, this.moduleList.indexOf(m)); },
    initials(n) { return (n || '').split(' ').filter(Boolean).slice(0, 2).map(p => p[0].toUpperCase()).join(''); },
    fmtDate(d) { return new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }); },
    dayLabel(d) { return new Date(d + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'long', day: '2-digit', month: 'short' }); },
    clock(at) { return String(at).slice(11, 16); },
    day(at) { return new Date(String(at).slice(0, 10) + 'T00:00:00').toLocaleDateString(undefined, { day: '2-digit', month: 'short' }); },
    kindIcon(k) { return KIND_ICON[k] || 'info'; },
    kindLabel(k) { return { login: this.$t('Sign-in'), logout: this.$t('Sign-out'), login_failed: this.$t('Failed sign-in') }[k] || k.charAt(0).toUpperCase() + k.slice(1); },
    endText(s) {
      if (s.end === 'logout') return this.$t('Signed out') + ' ' + this.clock(s.logout_at);
      if (s.end === 'active') return this.$t('Still signed in');
      return this.$t('No sign-out recorded');
    },
    pick(i, s) {
      if (this.picked === i) return this.clear();
      this.picked = i; this.highlight = new Set(s.evidence);
      this.$nextTick(() => this.$refs.tl && this.$refs.tl.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    },
    clear() { this.picked = null; this.highlight = null; this.fText = ''; this.fKind = ''; this.fModule = ''; },
    toggle(id) { this.open = { ...this.open, [id]: !this.open[id] }; },
  },
};
</script>

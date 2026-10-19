<template>
  <admin-layout :title="mode === 'management' ? $t('Management Smart Brief') : $t('My Smart Brief')">
    <div class="lg-page sb sc-ui px-4 md:px-0">
      <sb-hero icon="sparkles" :title="mode === 'management' ? $t('Management Smart Brief') : $t('My Smart Brief')" :subtitle="mode === 'management' ? $t('See what was recorded for the people you are allowed to view. Everything you open is logged.') : $t('Your own day, from the records WIMS holds.')">
        <template v-if="mode === 'my' && can.print">
          <a :href="printUrl('print')" target="_blank" rel="noopener" class="sb-gbtn"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
          <a :href="printUrl('pdf')" class="sb-gbtn"><icons name="download" class="w-4 h-4" />{{ $t('PDF') }}</a>
        </template>
        <button v-if="mode === 'my'" type="button" class="sb-gbtn lg-tip" :disabled="clearing" :data-tip="$t('Clear Smart Brief\'s own log lines. Cleared automatically every :n days.', { n: prefs.auto_clear_days })" @click="clearLog"><icons name="trash" class="w-4 h-4" />{{ clearing ? $t('Clearing...') : $t('Clear') }}</button>
        <Link v-if="can.reports" :href="route('smart-brief.reports.index')" class="sb-gbtn"><icons name="doc-text" class="w-4 h-4" />{{ $t('Reports') }}</Link>
        <Link v-if="can.settings" :href="route('smart-brief.settings.index')" class="sb-gbtn"><icons name="cog" class="w-4 h-4" />{{ $t('Settings') }}</Link>
      </sb-hero>

      <nav v-if="can.own && can.management" class="sb-seg" role="tablist">
        <Link :href="route('smart-brief.my.index')" :class="{ 'is-on': mode === 'my' }" role="tab"><icons name="user" class="w-4 h-4" />{{ $t('My Smart Brief') }}</Link>
        <Link :href="route('smart-brief.management.index')" :class="{ 'is-on': mode === 'management' }" role="tab"><icons name="group" class="w-4 h-4" />{{ $t('Management') }}</Link>
      </nav>

      <!-- period -->
      <section class="sb-card sb-bar" style="--i: 0">
        <span class="sb-ico g-teal"><icons name="calendar" class="w-4 h-4" /></span>
        <div class="sb-periods" role="group" :aria-label="$t('Period')">
          <button v-for="p in periods" :key="p.key" type="button" class="sb-chip" :class="{ 'is-on': period === p.key }" @click="setPeriod(p.key)">{{ $t(p.label) }}</button>
        </div>
        <div v-if="period === 'custom'" class="sb-range">
          <date-picker v-model="from" :max="to || undefined" /><span>-</span><date-picker v-model="to" :min="from || undefined" />
          <button type="button" class="sb-btn is-primary" :disabled="!from || !to" @click="load">{{ $t('Apply') }}</button>
        </div>
      </section>

      <!-- management: who -->
      <template v-if="mode === 'management'">
        <section class="sb-card mb-4" style="--i: 1">
          <div class="sb-fields">
            <div class="sb-field sb-wide-2">
              <label><icons name="user" class="w-3 h-3" />{{ $t('Person') }}</label>
              <auto-complete v-model="personId" :suggestions="people" :placeholder="$t('Search a person by name or role..')" clearable />
            </div>
            <div class="sb-field"><label><icons name="users" class="w-3 h-3" />{{ $t('Role') }}</label><auto-complete v-model="role" :suggestions="roleOptions" :placeholder="$t('All roles')" clearable /></div>
            <div class="sb-field"><label><icons name="building" class="w-3 h-3" />{{ $t('Project') }}</label><auto-complete v-model="project" :suggestions="projectOptions" :placeholder="$t('All projects')" clearable /></div>
            <template v-if="selected">
              <div class="sb-field"><label><icons name="collection" class="w-3 h-3" />{{ $t('Module') }}</label><auto-complete v-model="fModule" :suggestions="moduleOptions" :placeholder="$t('All modules')" clearable /></div>
              <div class="sb-field"><label><icons name="filter" class="w-3 h-3" />{{ $t('Type') }}</label><auto-complete v-model="fKind" :suggestions="kindOptions" :placeholder="$t('All types')" clearable /></div>
            </template>
          </div>
        </section>

        <section v-if="can.team && !selected" class="grid gap-4 mb-4">
          <div v-if="teamLoading" class="sb-state"><span class="sb-spin"></span>{{ $t('Loading the team summary...') }}</div>
          <template v-else-if="team">
            <div class="sb-tiles">
              <div v-for="(t, i) in teamTiles" :key="t.label" class="sb-tile" :class="'g-' + t.tone" :style="{ '--i': i }"><span class="sb-tile__ico"><icons :name="t.icon" /></span><b><sb-count :value="t.value" /></b><span class="sb-tile__lbl">{{ t.label }}</span></div>
            </div>
            <div class="sb-grid2">
              <section class="sb-card" style="--i: 2">
                <h3 class="sb-card__title"><span class="sb-ico g-navy"><icons name="users" class="w-4 h-4" /></span>{{ $t('People') }}<small>{{ $t('Listed alphabetically. Not a ranking.') }}</small></h3>
                <div class="sb-tablewrap"><table class="sb-table">
                  <thead><tr><th>{{ $t('Name') }}</th><th class="num">{{ $t('Actions') }}</th><th class="num">{{ $t('Sign-ins') }}</th><th class="num">{{ $t('Reports') }}</th><th>{{ $t('Last recorded') }}</th></tr></thead>
                  <tbody><tr v-for="p in team.people" :key="p.id" class="sb-row" @click="personId = p.id">
                    <td><div class="sb-who"><span class="sb-av">{{ initials(p.name) }}</span><span class="min-w-0"><b>{{ p.name }}</b><small>{{ p.roles.join(', ') }}</small></span></div></td>
                    <td class="num">{{ p.activity || '-' }}</td><td class="num">{{ p.logins || '-' }}</td><td class="num">{{ p.reports || '-' }}</td><td>{{ p.last_at ? p.last_at.slice(0, 16).replace('T', ' ') : '-' }}</td></tr></tbody>
                </table></div>
                <p v-if="!team.totals.activity && !team.totals.logins" class="sb-empty-hint"><icons name="info" class="w-4 h-4 flex-none" /><span>{{ $t('Nothing was recorded for these people in') }} {{ team.period.label }}. {{ $t('Try a longer period:') }} <button type="button" class="sb-link" @click="setPeriod('last_7_days')">{{ $t('Last 7 days') }}</button> &middot; <button type="button" class="sb-link" @click="setPeriod('this_month')">{{ $t('This month') }}</button></span></p>
              </section>
              <div class="sb-stack">
                <section class="sb-card" v-if="team.modules.length" style="--i: 3">
                  <h3 class="sb-card__title"><span class="sb-ico g-sky"><icons name="chart-bar" class="w-4 h-4" /></span>{{ $t('Activity by module') }}</h3>
                  <ul class="sb-bars"><li v-for="(m, i) in team.modules.slice(0, 10)" :key="m.key" :style="{ '--i': i }"><span>{{ m.module }}</span><i class="sb-meter" :class="palette(i)"><u :style="{ '--w': Math.max(5, (m.count / team.modules[0].count) * 100) + '%', '--i': i }"></u></i><b>{{ m.count }}</b></li></ul>
                </section>
                <section class="sb-card" v-if="team.waiting.length" style="--i: 4">
                  <h3 class="sb-card__title"><span class="sb-ico g-amber"><icons name="clock" class="w-4 h-4" /></span>{{ $t('Waiting') }}</h3>
                  <p class="sb-wait-sub">{{ $t('Right now, not for the chosen period. Each line opens its list in a new tab.') }}</p>
                  <waiting-list :items="team.waiting" />
                </section>
              </div>
            </div>
            <p class="sb-note-line"><icons name="info" class="w-4 h-4 flex-none" />{{ $t(team.note) }}</p>
          </template>
        </section>
      </template>

      <!-- one person's brief -->
      <div v-if="mode === 'my' || selected" class="grid gap-3">
        <div v-if="selected"><button type="button" class="sb-back" @click="personId = ''"><icons name="arrow-left" class="w-4 h-4" />{{ $t('Back to the team') }}</button></div>
        <brief-view :brief="brief" :loading="loading" :error="error" :show-lines="lines" @lines="setLines" />
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import BriefView from '@/Shared/SmartBrief/BriefView.vue';
import SbHero from '@/Shared/SmartBrief/SbHero.vue';
import SbCount from '@/Shared/SmartBrief/SbCount.vue';
import WaitingList from '@/Shared/SmartBrief/WaitingList.vue';

const PALETTE = ['g-navy', 'g-teal', 'g-violet', 'g-green', 'g-amber', 'g-rose', 'g-sky', 'g-pink'];

export default {
  components: { AdminLayout, Link, AutoComplete, DatePicker, BriefView, SbHero, SbCount, WaitingList },
  props: { mode: String, can: Object, prefs: Object, periods: Array, filters: Object, people: { type: Array, default: () => [] }, openUser: { type: Number, default: null } },

  data() {
    return {
      clearing: false, period: this.prefs.default_period, from: '', to: '', lines: !!this.prefs.show_line_items,
      brief: null, loading: false, error: '',
      personId: this.openUser || '', role: '', project: '', fModule: '', fKind: '',
      team: null, teamLoading: false,
    };
  },

  computed: {
    selected() { return this.personId ? this.people.find(p => String(p.value) === String(this.personId)) || { value: this.personId, name: '' } : null; },
    query() {
      const p = { period: this.period };
      if (this.period === 'custom') { p.from = this.from; p.to = this.to; }
      return p;
    },
    roleOptions() { return ((this.filters && this.filters.roles) || []).map(r => ({ value: r, label: r })); },
    projectOptions() { return ((this.filters && this.filters.projects) || []).map(p => ({ value: String(p.id), label: p.name })); },
    moduleOptions() { return ((this.filters && this.filters.modules) || []).map(m => ({ value: m.key, label: m.label })); },
    kindOptions() { return ((this.filters && this.filters.kinds) || []).map(k => ({ value: k.key, label: k.label })); },
    teamTiles() {
      const t = this.team.totals;
      const out = [
        { label: this.$t('People in view'), value: t.people, tone: 'navy', icon: 'users' },
        { label: this.$t('With recorded activity'), value: t.active, tone: 'teal', icon: 'sparkles' },
        { label: this.$t('Recorded actions'), value: t.activity, tone: 'violet', icon: 'list' },
        { label: this.$t('Sign-ins'), value: t.logins, tone: 'sky', icon: 'key' },
        { label: this.$t('Reports and downloads'), value: t.reports, tone: 'pink', icon: 'printer' },
      ];
      if (t.failed !== null) out.push({ label: this.$t('Failed sign-ins'), value: t.failed, tone: 'amber', icon: 'exclamation' });
      return out;
    },
  },

  watch: {
    personId(v) {
      this.brief = null; this.error = '';
      if (v) { this.fetchBrief(route('smart-brief.management.user', v)); window.scrollTo({ top: 0, behavior: 'smooth' }); } else this.loadTeam();
    },
    role() { this.loadTeam(); },
    project() { this.loadTeam(); },
    fModule() { this.reloadBrief(); },
    fKind() { this.reloadBrief(); },
  },

  mounted() {
    if (this.mode === 'my') this.load();
    else if (this.personId) this.fetchBrief(route('smart-brief.management.user', this.personId));
    else this.loadTeam();
  },

  methods: {
    palette(i) { return PALETTE[i % PALETTE.length]; },
    initials(n) { return (n || '').split(' ').filter(Boolean).slice(0, 2).map(p => p[0].toUpperCase()).join(''); },
    printUrl(kind) {
      // a printed brief is always about one person: here, the signed-in person
      const q = new URLSearchParams({ type: 'individual_daily', period: this.period, user_id: String(this.$page.props.auth.user.id) });
      if (this.period === 'custom') { q.set('from', this.from); q.set('to', this.to); }
      // Print opens the PDF itself in a new tab, so the printed page is the PDF design
      if (kind === 'print') { q.set('inline', '1'); return route('smart-brief.export.pdf') + '?' + q.toString(); }
      return route('smart-brief.export.' + kind) + '?' + q.toString();
    },
    clearLog() {
      if (!window.confirm(this.$t('Clear Smart Brief\'s own log lines for you? Records of real work are not touched.'))) return;
      this.clearing = true;
      axios.post(route('smart-brief.my.clear')).then(r => { this.$inertia.reload({ only: [], preserveScroll: true }); this.load(); window.alert(r.data.message); }).catch(e => window.alert((e.response && e.response.data && e.response.data.message) || this.$t('It could not be cleared.'))).finally(() => { this.clearing = false; });
    },
    setPeriod(k) { this.period = k; if (k !== 'custom') this.load(); },
    setLines(v) { this.lines = v; this.reloadBrief(); },
    load() {
      if (this.period === 'custom' && !(this.from && this.to)) return;
      if (this.mode === 'my') this.fetchBrief(route('smart-brief.my.data'));
      else if (this.selected) this.reloadBrief();
      else this.loadTeam();
    },
    reloadBrief() {
      if (this.mode === 'my') return this.fetchBrief(route('smart-brief.my.data'));
      if (this.selected) this.fetchBrief(route('smart-brief.management.user', this.selected.value));
    },
    fetchBrief(url) {
      this.loading = true; this.error = '';
      const params = { ...this.query, lines: this.lines ? 1 : 0 };
      if (this.mode === 'management') { if (this.fModule) params.module = this.fModule; if (this.fKind) params.kind = this.fKind; }
      return axios.get(url, { params }).then(r => { this.brief = r.data; }).catch(e => {
        this.brief = null;
        this.error = (e.response && e.response.data && e.response.data.message) || this.$t('The brief could not be prepared.');
      }).finally(() => { this.loading = false; });
    },
    loadTeam() {
      if (!this.can.team || this.mode !== 'management' || this.selected) return;
      if (this.period === 'custom' && !(this.from && this.to)) return;
      this.teamLoading = true;
      axios.get(route('smart-brief.team.overview'), { params: { ...this.query, role: this.role, project: this.project } })
        .then(r => { this.team = r.data; }).catch(() => { this.team = null; }).finally(() => { this.teamLoading = false; });
    },
  },
};
</script>

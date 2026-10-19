<template>
  <admin-layout :title="$t('Project Name Mapping')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <div ref="headBar">
      <page-header icon="building" :title="$t('Project Name Mapping')" :subtitle="$t('Match every project or location name typed in the Excel workbook to a Project(s) / Warehouse(s). Your choices stay a draft until you confirm them; then the accounts show the Project(s) / Warehouse(s) name.')">
        <a :href="route('subcontracts.project-mapping.template')" class="lg-btn lg-btn-soft t-teal lg-tip" :data-tip="$t('An Excel file with every name, ready to fill in')"><icons name="download" class="w-4 h-4" /><span>{{ $t('Download Excel Template') }}</span></a>
        <button type="button" class="lg-btn lg-btn-soft" @click="openUpload"><icons name="upload" class="w-4 h-4" /><span>{{ $t('Upload Matched Excel') }}</span></button>
        <button type="button" class="lg-btn lg-btn-soft t-slate lg-tip" :data-tip="$t('Prints the table as it is now, with the filter and search you have set')" @click="printList"><icons name="printer" class="w-4 h-4" /><span>{{ $t('Print') }}</span></button>
        <button type="button" class="lg-btn lg-btn-soft t-slate lg-tip" :data-tip="$t('The same table as a PDF')" @click="pdfList"><icons name="download" class="w-4 h-4" /><span>{{ $t('PDF') }}</span></button>
        <button type="button" class="lg-btn lg-btn-primary" :disabled="!dirtyKeys.length || busy" @click="saveDraft()"><icons name="edit" class="w-4 h-4" /><span>{{ $t('Save Draft') }}</span><b v-if="dirtyKeys.length" class="pm-badge">{{ dirtyKeys.length }}</b></button>
        <button type="button" class="lg-btn lg-btn-green" :disabled="busy || (!counts.pending && !dirtyKeys.length)" @click="openConfirm"><icons name="tick" class="w-4 h-4" /><span>{{ $t('Confirm Mapping') }}</span></button>
      </page-header>
      </div>

      <p v-if="notice" class="lg-note lg-note-green pm-notice" role="status"><icons name="tick" class="w-4 h-4" /><span>{{ notice }}</span><button type="button" class="pm-x" :aria-label="$t('Close')" @click="notice = ''"><icons name="cross" class="w-4 h-4" /></button></p>
      <p v-if="problem" class="lg-note lg-note-red pm-notice" role="alert"><icons name="exclamation" class="w-4 h-4" /><span>{{ problem }}</span><button type="button" class="pm-x" :aria-label="$t('Close')" @click="problem = ''"><icons name="cross" class="w-4 h-4" /></button></p>

      <div class="sc-over sc-reg-cards">
        <overview-card icon="list" :title="$t('Excel Names')" :sub="$t('Different project names in the Excel')" :value="String(counts.names)" :foot="$t('Show All')" :class="{ 'is-on': filter === 'all' }" role="button" tabindex="0" @click="filter = 'all'" @keydown.enter="filter = 'all'" />
        <overview-card variant="hero" icon="tick" :title="$t('Matched')" :sub="counts.confirmed + ' ' + $t('confirmed')" :value="String(counts.matched)" :foot="$t('Show Matched')" :class="{ 'is-on': filter === 'matched' }" role="button" tabindex="0" @click="filter = 'matched'" @keydown.enter="filter = 'matched'" />
        <overview-card variant="amber" icon="clock" :title="$t('Waiting To Be Confirmed')" :sub="$t('Saved, not yet applied')" :value="String(counts.pending)" :foot="$t('Show Waiting')" :class="{ 'is-on': filter === 'pending' }" role="button" tabindex="0" @click="filter = 'pending'" @keydown.enter="filter = 'pending'" />
        <overview-card variant="rose" icon="exclamation" :title="$t('Not Matched')" :sub="$t('Still without a Project(s) / Warehouse(s)')" :value="String(counts.none)" :foot="$t('Show Not Matched')" :class="{ 'is-on': filter === 'none' }" role="button" tabindex="0" @click="filter = 'none'" @keydown.enter="filter = 'none'" />
      </div>

      <search-bar v-model="q" :placeholder="$t('Search by Excel name, Project(s) / Warehouse(s) or account...')" :count="shown.length" />

      <div class="lg-table-wrap"><table class="lg-table lg-responsive pm-table">
        <thead><tr>
          <sort-th col="excel_name" tone="key" icon="list" :sort="sk" :dir="sd" @sort="sortBy">{{ $t('Excel Name') }}</sort-th>
          <sort-th col="agreements" tone="text" icon="doc-text" :sort="sk" :dir="sd" @sort="sortBy">{{ $t('Used In') }}</sort-th>
          <sort-th col="items" tone="date" num icon="table" :sort="sk" :dir="sd" @sort="sortBy">{{ $t('Items') }}</sort-th>
          <sort-th v-if="money" col="contract" tone="money" num icon="cash" :sort="sk" :dir="sd" @sort="sortBy">{{ $t('Contract') }}</sort-th>
          <sort-th col="project" tone="num" icon="building" :sort="sk" :dir="sd" @sort="sortBy">{{ $t('Project(s) / Warehouse(s)') }}</sort-th>
          <sort-th col="state" tone="state" icon="info" :sort="sk" :dir="sd" @sort="sortBy">{{ $t('Status') }}</sort-th>
        </tr></thead>
        <tbody>
          <tr v-for="r in shown" :key="r.key" class="pm-row" :class="{ 'is-dirty': isDirty(r) }">
            <td :data-label="$t('Excel Name')"><span class="lg-main">{{ r.excel_name }}</span><div class="lg-subtext">{{ r.groups }} {{ r.groups === 1 ? $t('work group') : $t('work groups') }}</div></td>
            <td :data-label="$t('Used In')">
              <span class="sc-pill pill-blue pm-used" tabindex="0" :aria-label="usedText(r)" @mouseenter="showTip($event, r)" @mouseleave="tip = null" @focus="showTip($event, r)" @blur="tip = null" @click.stop="toggleTip($event, r)"><icons name="doc-text" class="w-3 h-3" />{{ $t('Used in :n accounts', { n: r.agreements }) }}</span>
              <span v-if="r.archived" class="sc-pill pill-slate" :title="$t('Archived accounts')"><icons name="archive-box" class="w-3 h-3" />{{ r.archived }}</span>
            </td>
            <td :data-label="$t('Items')" class="ac-num">{{ r.items }}</td>
            <td v-if="money" :data-label="$t('Contract')" class="ac-num"><div v-for="(v, c) in r.totals" :key="c"><b class="amt">{{ mc(v, c) }}</b></div><span v-if="!Object.keys(r.totals || {}).length" class="lg-subtext">-</span></td>
            <td :data-label="$t('Project(s) / Warehouse(s)')" class="pm-sel"><div class="pm-selrow"><acct-select :model-value="choice[r.key]" :options="projects" clearable :placeholder="$t('Choose a Project(s) / Warehouse(s)...')" @update:model-value="setChoice(r.key, $event)" /><button v-if="can.create_project" type="button" class="lg-btn lg-btn-soft t-teal pm-new lg-tip" :data-tip="$t('Create a new Project(s) / Warehouse(s) named like this')" :aria-label="$t('Create Project(s) / Warehouse(s)')" @click="openCreate(r)"><icons name="plus" class="w-4 h-4" /><span>{{ $t('Create') }}</span></button></div></td>
            <td :data-label="$t('Status')">
              <span class="lg-chip" :class="chipOf(r.state)">{{ labelOf(r.state) }}</span>
              <span v-if="isDirty(r)" class="lg-chip lg-chip-violet pm-unsaved">{{ $t('Unsaved') }}</span>
            </td>
          </tr>
          <tr v-if="!shown.length" class="lg-empty-row"><td class="lg-empty" :colspan="money ? 6 : 5">{{ rows.length ? $t('Nothing matches your search.') : $t('There is no data to display.') }}</td></tr>
        </tbody>
      </table></div>
    </div>

    <!-- the buttons of the page header follow the person down the list: they show here once the header has scrolled out of view -->
    <transition name="pm-float">
      <div v-if="headOut" class="pm-float sc-ui" role="toolbar" :aria-label="$t('Mapping actions')">
        <span class="pm-float-n" :class="{ 'is-on': dirtyKeys.length }"><i></i>{{ dirtyKeys.length ? $t(':n unsaved', { n: dirtyKeys.length }) : (counts.pending ? $t(':n waiting to be confirmed', { n: counts.pending }) : $t('Nothing waiting')) }}</span>
        <button type="button" class="lg-btn lg-btn-primary" :disabled="!dirtyKeys.length || busy" @click="saveDraft()"><icons name="edit" class="w-4 h-4" /><span>{{ $t('Save Draft') }}</span></button>
        <button type="button" class="lg-btn lg-btn-green" :disabled="busy || (!counts.pending && !dirtyKeys.length)" @click="openConfirm"><icons name="tick" class="w-4 h-4" /><span>{{ $t('Confirm Mapping') }}</span></button>
        <button type="button" class="lg-icon-btn" :aria-label="$t('Print')" @click="printList"><icons name="printer" class="w-4 h-4" /></button>
        <button type="button" class="lg-icon-btn pm-float-top" :aria-label="$t('Back to the top')" @click="toTop"><icons name="chevron-down" class="w-4 h-4" style="transform: rotate(180deg)" /></button>
      </div>
    </transition>

    <div v-if="tip" class="pm-tipbox sc-ui" :style="tip.style" role="tooltip">
      <b>{{ tip.row.excel_name }}</b>
      <ul><li v-for="a in tip.row.agreement_list" :key="a.id"><span>{{ a.reference }}</span> {{ a.title }}<em v-if="a.status === 'Archived'">{{ $t('Archived') }}</em></li></ul>
      <small v-if="tip.row.agreements > tip.row.agreement_list.length">{{ $t('and :n more', { n: tip.row.agreements - tip.row.agreement_list.length }) }}</small>
    </div>

    <!-- upload -->
    <tec-dialog-modal :show="uploading" max-width="3xl" @close="closeUpload">
      <template #title><span class="pm-title"><icons name="upload" class="w-5 h-5" />{{ $t('Upload Matched Excel') }}</span></template>
      <template #content>
        <div class="sc-ui pm-modal">
          <p class="lg-sub">{{ $t('Upload the Excel template after typing the Project(s) / Warehouse(s) next to each name. You see what was understood first; nothing is saved until you press Use These Matches, and even then it stays a draft.') }}</p>
          <acct-dropzone v-model="files" :multiple="false" accept=".xlsx,.csv" :max-size="4" :max="1" :label="$t('Drop the matched Excel file here or click to choose')" :hint="$t('Excel (.xlsx) or CSV, up to 4 MB')" :disabled="reading" />
          <p v-if="reading" class="lg-sub pm-reading"><icons name="refresh" class="w-4 h-4 animate-spin" />{{ $t('Reading the file...') }}</p>
          <p v-if="uploadError" class="lg-note lg-note-red" role="alert"><icons name="exclamation" class="w-4 h-4" /><span>{{ uploadError }}</span></p>
          <template v-if="preview">
            <div class="pm-sum">
              <button v-for="s in previewTabs" :key="s.key" type="button" class="pm-sumchip" :class="['t-' + s.tone, { 'is-on': pfilter === s.key }]" @click="pfilter = s.key"><b>{{ s.n }}</b><span>{{ s.label }}</span></button>
            </div>
            <div class="lg-table-wrap pm-prev"><table class="lg-table lg-responsive">
              <thead><tr><th>{{ $t('Excel Name') }}</th><th>{{ $t('Typed In The File') }}</th><th>{{ $t('Project(s) / Warehouse(s)') }}</th><th>{{ $t('Result') }}</th></tr></thead>
              <tbody>
                <tr v-for="p in previewShown" :key="p.row + p.key">
                  <td :data-label="$t('Excel Name')"><span class="lg-main">{{ p.excel_name }}</span></td>
                  <td :data-label="$t('Typed In The File')">{{ p.typed || '-' }}</td>
                  <td :data-label="$t('Project(s) / Warehouse(s)')"><b v-if="p.warehouse_name">{{ p.warehouse_name }}</b><span v-else class="lg-subtext">-</span></td>
                  <td :data-label="$t('Result')"><span class="lg-chip" :class="pchip(p.status)">{{ plabel(p.status) }}</span></td>
                </tr>
                <tr v-if="!previewShown.length" class="lg-empty-row"><td class="lg-empty" colspan="4">{{ $t('There is no data to display.') }}</td></tr>
              </tbody>
            </table></div>
            <p v-if="preview.counts.unmatched" class="lg-sub pm-hint"><icons name="info" class="w-4 h-4" />{{ $t('A row is Not Matched when the typed text is not the name or code of a Project(s) / Warehouse(s). Fix it in the file, or choose the project on the page afterwards.') }}</p>
          </template>
        </div>
      </template>
      <template #footer>
        <div class="sc-ui flex items-center justify-between gap-2 w-full">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeUpload"><icons name="cross" class="w-4 h-4" /><span>{{ $t('Cancel') }}</span></button>
          <button type="button" class="lg-btn lg-btn-primary" :disabled="!preview || !preview.counts.matched || busy" @click="useMatches"><icons name="tick" class="w-4 h-4" /><span>{{ $t('Use These Matches') }}</span><b v-if="preview && preview.counts.matched" class="pm-badge">{{ preview.counts.matched }}</b></button>
        </div>
      </template>
    </tec-dialog-modal>

    <!-- create a Project(s) / Warehouse(s) from an Excel name -->
    <tec-dialog-modal :show="!!creating" max-width="3xl" @close="closeCreate">
      <template #title><span class="pm-title"><icons name="building" class="w-5 h-5" />{{ $t('Create Project(s) / Warehouse(s)') }}</span></template>
      <template #content>
        <form v-if="creating" class="ac sc-ui pm-modal" autocomplete="off" @submit.prevent="saveCreate">
          <p class="lg-note lg-note-blue"><icons name="info" class="w-4 h-4" /><span>{{ $t('For the Excel name') }} <b>{{ creating.excel_name }}</b>. {{ $t('Key in the main details. When you save, it is matched to this name and the list behind refreshes.') }}</span></p>
          <div class="lg-fields pm-cf">
            <div class="ac-f" style="grid-column: 1 / -1"><label for="pc-name">{{ $t('Name') }} *</label><input id="pc-name" ref="cname" v-model="cf.name" maxlength="50" /><span class="ac-err">{{ cerr('name') }}</span><small v-if="creating.excel_name.length > 50" class="lg-subtext">{{ $t('The Excel name is longer than 50 characters: shorten it here.') }}</small></div>
            <div class="ac-f"><label for="pc-code">{{ $t('Code') }} *</label><input id="pc-code" v-model="cf.code" maxlength="20" /><span class="ac-err">{{ cerr('code') }}</span></div>
            <div class="ac-f"><label>{{ $t('Category') }} *</label><acct-select v-model="cf.status" :options="categoryOptions" /><span class="ac-err">{{ cerr('status') }}</span></div>
            <div class="ac-f"><label for="pc-for">{{ $t('For Who') }}</label><input id="pc-for" v-model="cf.for_who" maxlength="255" /><span class="ac-err">{{ cerr('for_who') }}</span></div>
            <div class="ac-f"><label for="pc-stage">{{ $t('Project(s) / Warehouse(s) Stage') }}</label><input id="pc-stage" v-model="cf.stage" maxlength="255" /><span class="ac-err">{{ cerr('stage') }}</span></div>
            <div class="ac-f"><label for="pc-pm">{{ $t('Project Manager / Site Engineer') }}</label><input id="pc-pm" v-model="cf.project_manager" maxlength="190" /><span class="ac-err">{{ cerr('project_manager') }}</span></div>
            <div class="ac-f"><label for="pc-phone">{{ $t('Phone') }}</label><input id="pc-phone" v-model="cf.phone" maxlength="40" inputmode="tel" /><span class="ac-err">{{ cerr('phone') }}</span></div>
            <div class="ac-f" style="grid-column: 1 / -1"><label for="pc-addr">{{ $t('Address') }}</label><input id="pc-addr" v-model="cf.address" maxlength="255" /><span class="ac-err">{{ cerr('address') }}</span></div>
            <div class="ac-f"><label>{{ $t('Start Date') }}</label><date-picker v-model="cf.start_date" /><span class="ac-err">{{ cerr('start_date') }}</span></div>
            <div class="ac-f"><label>{{ $t('Expected End Date') }}</label><date-picker v-model="cf.expected_end_date" /><span class="ac-err">{{ cerr('expected_end_date') }}</span></div>
            <div class="ac-f"><label for="pc-bud">{{ $t('Site Budget') }}</label><input id="pc-bud" v-model="cf.site_budget" inputmode="decimal" /><span class="ac-err">{{ cerr('site_budget') }}</span></div>
          </div>
          <p v-if="createProblem" class="lg-note lg-note-red" role="alert"><icons name="exclamation" class="w-4 h-4" /><span>{{ createProblem }}</span></p>
          <button type="submit" class="pm-hidden" tabindex="-1" aria-hidden="true"></button>
        </form>
      </template>
      <template #footer>
        <div class="sc-ui flex items-center justify-between gap-2 w-full">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeCreate"><icons name="cross" class="w-4 h-4" /><span>{{ $t('Cancel') }}</span></button>
          <button type="button" class="lg-btn lg-btn-primary" :disabled="busy" @click="saveCreate"><icons name="tick" class="w-4 h-4" /><span>{{ busy ? $t('Working...') : $t('Create And Match') }}</span></button>
        </div>
      </template>
    </tec-dialog-modal>

    <!-- confirm -->
    <tec-dialog-modal :show="confirming" max-width="xl" @close="closeConfirm">
      <template #title><span class="pm-title"><icons name="tick" class="w-5 h-5" />{{ done ? $t('Mapping Confirmed') : $t('Confirm Mapping') }}</span></template>
      <template #content>
        <div class="sc-ui pm-modal">
          <template v-if="!done">
            <div class="pm-wait"><b>{{ waiting.names }}</b><span>{{ waiting.names === 1 ? $t('waiting mapping') : $t('waiting mappings') }}</span><i></i><b>{{ waiting.groups }}</b><span>{{ $t('work groups') }}</span></div>
            <ul class="pm-steps">
              <li><icons name="building" class="w-4 h-4" />{{ $t('Every work group with a matched name shows the name of its Project(s) / Warehouse(s). The Excel name stays beside it as a small label.') }}</li>
              <li><icons name="link" class="w-4 h-4" />{{ $t('An account whose work groups all belong to one Project(s) / Warehouse(s) gets that project. With several projects, all of them are listed.') }}</li>
              <li><icons name="refresh" class="w-4 h-4" />{{ $t('A match you cleared goes back to the Excel name. Amounts, payments and balances do not change.') }}</li>
            </ul>
            <form autocomplete="on" @submit.prevent="doConfirm">
              <input type="text" name="username" class="pm-hidden" :value="username" autocomplete="username" tabindex="-1" aria-hidden="true" readonly />
              <label class="pm-label" for="pm-pass">{{ $t('Your Password') }}</label>
              <input id="pm-pass" ref="pass" v-model="password" type="password" class="pm-input" :class="{ 'is-bad': confirmError }" autocomplete="current-password" :placeholder="$t('Enter your password to confirm')" />
              <p v-if="confirmError" class="pm-err" role="alert">{{ confirmError }}</p>
            </form>
          </template>
          <template v-else>
            <p class="lg-note lg-note-green"><icons name="tick" class="w-4 h-4" /><span>{{ $t('The mapping is applied. Open any account to see the Project(s) / Warehouse(s) names on its work groups.') }}</span></p>
            <div class="pm-result">
              <div><b>{{ done.groups }}</b><span>{{ $t('Work Groups Updated') }}</span></div>
              <div><b>{{ done.agreements_with_project }}</b><span>{{ $t('Accounts With A Project') }}</span></div>
              <div><b>{{ done.agreements_several }}</b><span>{{ $t('Accounts With Several Projects') }}</span></div>
            </div>
          </template>
        </div>
      </template>
      <template #footer>
        <div class="sc-ui flex items-center justify-between gap-2 w-full">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeConfirm"><icons name="cross" class="w-4 h-4" /><span>{{ done ? $t('Close') : $t('Cancel') }}</span></button>
          <button v-if="!done" type="button" class="lg-btn lg-btn-green" :disabled="busy" @click="doConfirm"><icons name="lock-open" class="w-4 h-4" /><span>{{ busy ? $t('Working...') : $t('Confirm Mapping') }}</span></button>
        </div>
      </template>
    </tec-dialog-modal>
  </admin-layout>
</template>

<script>
import OverviewCard from './OverviewCard.vue';
import SearchBar from './SearchBar.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import AcctDropzone from '@/Pages/Accounts/AcctDropzone.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import SortTh from './SortTh.vue';
import { router } from '@inertiajs/vue3';
import { sc } from './sc';

// Project Name Mapping: the project / location names typed in the Excel matched to Project(s) / Warehouse(s). Choices are saved as a draft; Confirm Mapping (with the person's password) applies them.
export default {
  components: { SortTh, DatePicker, OverviewCard, SearchBar, AdminLayout, PageHeader, TecDialogModal, AcctSelect, AcctDropzone },
  mixins: [sc],
  props: { categories: { type: Array, default: () => [] }, rows: Array, projects: Array, counts: Object, money: Boolean, can: Object },
  data() {
    return {
      sk: '', sd: 'asc', creating: null, cf: {}, cerrors: {}, createProblem: '',
      q: '', filter: 'all', choice: {}, busy: false, notice: '', problem: '', tip: null, headOut: false, headObs: null,
      uploading: false, files: [], reading: false, uploadError: '', preview: null, pfilter: 'all',
      confirming: false, password: '', confirmError: '', done: null,
    };
  },
  computed: {
    username() { const u = (this.$page.props.auth || {}).user || {}; return u.username || u.email || ''; },
    projectName() { const m = {}; (this.projects || []).forEach(p => { m[p.value] = p.label; }); return m; },
    dirtyKeys() { return this.rows.filter(r => this.isDirty(r)).map(r => r.key); },
    categoryOptions() { return this.categories.map(c => ({ value: c, label: c })); },
    shown() {
      const q = this.q.trim().toLowerCase();
      const list = this.rows.filter(r => {
        if (this.filter === 'matched' && r.state === 'none') return false;
        if (this.filter === 'pending' && r.state !== 'pending') return false;
        if (this.filter === 'none' && r.state !== 'none') return false;
        if (!q) return true;
        const p = this.projectName[this.choice[r.key]] || '';
        return [r.excel_name, p, ...r.agreement_list.map(a => a.reference + ' ' + a.title)].some(v => String(v || '').toLowerCase().includes(q));
      });
      if (!this.sk) return list;
      const val = r => ({ excel_name: String(r.excel_name || '').toLowerCase(), agreements: Number(r.agreements || 0), items: Number(r.items || 0), contract: Object.values(r.totals || {}).reduce((a, b) => a + Number(b), 0), project: (this.projectName[this.choice[r.key]] || '').toLowerCase(), state: r.state }[this.sk]);
      const d = this.sd === 'desc' ? -1 : 1;
      return [...list].sort((a, b) => { const x = val(a); const y = val(b); const empty = v => v === '' || v === null || v === undefined; if (empty(x) && empty(y)) return 0; if (empty(x)) return 1; if (empty(y)) return -1; return (typeof x === 'string' ? x.localeCompare(y, undefined, { numeric: true }) : x - y) * d; });
    },
    waiting() { const w = this.rows.filter(r => r.state === 'pending'); return { names: w.length, groups: w.reduce((n, r) => n + r.groups, 0) }; },
    previewTabs() {
      const c = this.preview.counts;
      return [{ key: 'all', label: this.$t('All Rows'), n: c.rows, tone: 'navy' }, { key: 'matched', label: this.$t('Matched'), n: c.matched, tone: 'green' }, { key: 'unmatched', label: this.$t('Not Matched'), n: c.unmatched, tone: 'red' },
        { key: 'blank', label: this.$t('Left Blank'), n: c.blank, tone: 'slate' }, { key: 'unknown_name', label: this.$t('Unknown Name'), n: c.unknown_name, tone: 'amber' }];
    },
    previewShown() { return this.preview.rows.filter(p => this.pfilter === 'all' || p.status === this.pfilter); },
  },
  mounted() {
    // the header buttons are watched: when they scroll out of sight the floating bar takes over
    try {
      if (this.$refs.headBar && 'IntersectionObserver' in window) {
        this.headObs = new IntersectionObserver(([e]) => { this.headOut = !e.isIntersecting && e.boundingClientRect.top < 0; }, { threshold: 0 });
        this.headObs.observe(this.$refs.headBar);
      }
    } catch (e) { /* the header buttons still work */ }
  },
  beforeUnmount() { if (this.headObs) this.headObs.disconnect(); },
  watch: {
    rows: { handler() { this.initChoice(); }, immediate: true },
    files(v) { if (v.length) this.readFile(v[0]); else { this.preview = null; this.uploadError = ''; } },
  },
  methods: {
    // the table as it is: the same filter, search and sort as on the screen (saved choices only)
    listQuery() { return { filter: this.filter, q: this.q.trim(), sk: this.sk || undefined, sd: this.sk ? this.sd : undefined }; },
    printList() { window.open(route('subcontracts.project-mapping.print', this.listQuery()), '_blank'); },
    pdfList() { window.location.href = route('subcontracts.project-mapping.pdf', this.listQuery()); },
    toTop() { try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch (e) { window.scrollTo(0, 0); } },
    // click once: ascending; again: descending; again: the usual order
    sortBy(col) { if (this.sk !== col) { this.sk = col; this.sd = 'asc'; } else if (this.sd === 'asc') { this.sd = 'desc'; } else { this.sk = ''; this.sd = 'asc'; } },
    cerr(k) { return (this.cerrors[k] || [])[0] || ''; },
    suggestCode(n) { const w = String(n || '').toUpperCase().replace(/[^A-Z0-9 ]/g, ' ').trim().split(/\s+/).filter(Boolean); const c = w.length > 1 ? w.map(x => x[0]).join('') : (w[0] || '').slice(0, 6); return c.slice(0, 12); },
    openCreate(r) {
      this.creating = r; this.cerrors = {}; this.createProblem = '';
      this.cf = { name: String(r.excel_name || '').slice(0, 50), code: this.suggestCode(r.excel_name), status: (this.categories || []).includes('General') ? 'General' : (this.categories[0] || 'General'), for_who: '', stage: '', project_manager: '', phone: '', address: '', start_date: null, expected_end_date: null, site_budget: '' };
      this.$nextTick(() => this.$refs.cname && this.$refs.cname.focus());
    },
    closeCreate() { this.creating = null; },
    async saveCreate() {
      if (!this.creating || this.busy) return;
      this.busy = true; this.cerrors = {}; this.createProblem = '';
      const key = this.creating.key;
      try {
        const r = await window.axios.post(route('subcontracts.project-mapping.project'), this.cf);
        this.choice = { ...this.choice, [key]: r.data.project.value };
        this.creating = null; this.busy = false;
        await this.saveDraft(); // the new project is matched to its name as a draft; the list and the project choices refresh behind
        router.reload({ only: ['rows', 'counts', 'projects'], preserveScroll: true });
        this.notice = r.data.message;
      } catch (e) {
        const d = (e.response && e.response.data) || {};
        if (d.errors) this.cerrors = d.errors; else this.createProblem = d.message || this.$t('That did not work.');
      } finally { this.busy = false; }
    },
    initChoice() { const c = {}; this.rows.forEach(r => { c[r.key] = r.warehouse_id; }); this.choice = c; },
    setChoice(key, v) { this.choice = { ...this.choice, [key]: v === '' || v === undefined ? null : v }; },
    isDirty(r) { return (this.choice[r.key] ?? null) !== (r.warehouse_id ?? null); },
    chipOf(s) { return { confirmed: 'lg-chip-green', pending: 'lg-chip-amber', none: 'lg-chip-slate' }[s]; },
    labelOf(s) { return { confirmed: this.$t('Confirmed'), pending: this.$t('Waiting'), none: this.$t('Not Set') }[s]; },
    pchip(s) { return { matched: 'lg-chip-green', unmatched: 'lg-chip-red', blank: 'lg-chip-slate', unknown_name: 'lg-chip-amber' }[s]; },
    plabel(s) { return { matched: this.$t('Matched'), unmatched: this.$t('Not Matched'), blank: this.$t('Left Blank'), unknown_name: this.$t('Unknown Name') }[s]; },
    usedText(r) { return this.$t('Used in :n accounts', { n: r.agreements }) + ': ' + r.agreement_list.map(a => a.reference).join(', '); },
    reload() { router.reload({ only: ['rows', 'counts'], preserveScroll: true }); },
    firstError(e) { const d = (e.response && e.response.data) || {}; const er = d.errors || {}; const k = Object.keys(er)[0]; return (k && er[k][0]) || d.message || this.$t('That did not work.'); },

    // the list of Project(s) / Warehouse(s) shown under the cursor (fixed box so the table never clips it)
    showTip(ev, r) {
      const b = ev.currentTarget.getBoundingClientRect(); const w = Math.min(360, window.innerWidth - 24);
      const left = Math.max(12, Math.min(b.left, window.innerWidth - w - 12)); const below = b.bottom + 8 + 220 < window.innerHeight;
      this.tip = { row: r, key: r.key, style: { left: left + 'px', width: w + 'px', ...(below ? { top: b.bottom + 8 + 'px' } : { bottom: window.innerHeight - b.top + 8 + 'px' }) } };
    },
    toggleTip(ev, r) { if (this.tip && this.tip.key === r.key) this.tip = null; else this.showTip(ev, r); },

    async saveDraft(after) {
      if (!this.dirtyKeys.length) { if (after) after(); return; }
      this.busy = true; this.problem = '';
      try {
        const r = await window.axios.put(route('subcontracts.project-mapping.save'), { mappings: this.dirtyKeys.map(k => ({ key: k, warehouse_id: this.choice[k] ?? null })) });
        this.notice = r.data.message; this.reload();
        if (after) after();
      } catch (e) { this.problem = this.firstError(e); } finally { this.busy = false; }
    },

    openUpload() { this.uploading = true; this.files = []; this.preview = null; this.uploadError = ''; this.pfilter = 'all'; },
    closeUpload() { this.uploading = false; },
    async readFile(f) {
      this.reading = true; this.uploadError = ''; this.preview = null;
      const fd = new FormData(); fd.append('file', f);
      try { const r = await window.axios.post(route('subcontracts.project-mapping.upload'), fd); this.preview = r.data; this.pfilter = 'all'; } catch (e) { this.uploadError = this.firstError(e); } finally { this.reading = false; }
    },
    async useMatches() {
      this.busy = true;
      try {
        const rows = this.preview.rows.filter(p => p.status === 'matched').map(p => ({ key: p.key, warehouse_id: p.warehouse_id }));
        const r = await window.axios.post(route('subcontracts.project-mapping.import'), { rows });
        this.notice = r.data.message; this.uploading = false; this.reload();
      } catch (e) { this.uploadError = this.firstError(e); } finally { this.busy = false; }
    },

    openConfirm() { this.saveDraft(() => { this.password = ''; this.confirmError = ''; this.done = null; this.confirming = true; this.$nextTick(() => this.$refs.pass && this.$refs.pass.focus()); }); },
    closeConfirm() { this.confirming = false; this.password = ''; },
    async doConfirm() {
      if (!this.password) { this.confirmError = this.$t('Enter your password to continue.'); return; }
      this.busy = true; this.confirmError = '';
      try {
        const r = await window.axios.post(route('subcontracts.project-mapping.confirm'), { password: this.password });
        this.done = r.data.result; this.password = ''; this.reload();
      } catch (e) { this.confirmError = this.firstError(e); } finally { this.busy = false; }
    },
  },
};
</script>

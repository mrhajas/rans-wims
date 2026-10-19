<template>
  <admin-layout :title="claim ? $t('Edit :r', { r: claim.reference }) : $t('New Contract Payment Request')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="collection" :title="claim ? $t('Edit :r', { r: claim.reference }) : $t('New Contract Payment Request')" :subtitle="$t('Choose the subcontractor, then the completed contracts to be paid and the amount for each. Nothing is paid by this request: it is decided line by line and paid with a Payment Voucher.')">
        <Link :href="claim ? route('subcontracts.claims.show', claim.id) : route('subcontracts.claims.index')" class="lg-btn lg-btn-soft"><icons name="arrow-left" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
      </page-header>

      <form @submit.prevent="save(false)">
        <collapse-section>
          <template #head><span class="lg-card-ico lg-ico-sky"><icons name="users" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Step 1. Subcontractor and project') }}</h2><p class="lg-card-sub">{{ $t('The contracts of the subcontractor that are completed and can be paid are listed below.') }}</p></div></template>
          <div class="lg-fields">
            <div class="ac-f"><label>{{ $t('Subcontractor') }} *</label><acct-select v-model="form.contact_id" :options="contacts" :placeholder="$t('Choose the subcontractor...')" :disabled="!!claim" @update:model-value="reload(true)" /><span class="ac-err">{{ err('contact_id') }}</span></div>
            <div class="ac-f"><label>{{ $t('Project(s) / Warehouse(s)') }} <small class="lg-subtext">({{ $t('optional') }})</small></label><acct-select v-model="form.warehouse_id" :options="projects" :placeholder="$t('Every project')" clearable @update:model-value="reload(false)" /><span class="ac-err">{{ err('warehouse_id') }}</span></div>
            <div class="ac-f"><label>{{ $t('Request date') }} *</label><date-picker v-model="form.request_date" :clearable="false" /><span class="ac-err">{{ err('request_date') }}</span></div>
          </div>
        </collapse-section>

        <collapse-section>
          <template #head><span class="lg-card-ico lg-ico-teal"><icons name="check-double" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Step 2. Contracts to be paid') }}</h2><p class="lg-card-sub">{{ $t('Tick the contracts and check the amount of each. The amount starts as the whole balance that is still open to request; a lower amount is allowed when partial requests are on.') }}</p></div></template>

          <div v-if="!form.contact_id" class="lg-note lg-note-blue"><icons name="info" class="w-4 h-4" /><span>{{ $t('Choose the subcontractor first.') }}</span></div>
          <template v-else>
            <div class="cl-tools">
              <search-bar v-model="tq" :placeholder="$t('Search by contract, description or project...')" :count="tq.trim() ? shown.length : null" />
              <div class="ac-f cl-pf" v-if="projectOptions.length > 1"><label>{{ $t('Project') }}</label><acct-select v-model="pf" :options="projectOptions" :placeholder="$t('Every project')" clearable /></div>
              <label class="ac-check cl-blocked"><input v-model="showBlocked" type="checkbox" @change="reload(false)" /><span>{{ $t('Show contracts that cannot be requested') }}</span></label>
            </div>
            <div v-if="loadError" class="lg-note lg-note-red" role="alert"><icons name="exclamation" class="w-4 h-4" /><span>{{ loadError }}</span></div>
            <div v-if="loading" class="cl-wait"><icons name="refresh" class="w-5 h-5 animate-spin" /><span>{{ $t('Loading...') }}</span></div>

            <div v-else class="lg-table-wrap"><table class="lg-table lg-responsive cl-table cl-pick">
              <thead><tr>
                <th class="cl-chk"><label class="ac-check cl-allbox"><input type="checkbox" :checked="allOn" :disabled="!selectable.length" :aria-label="$t('Select every contract shown')" @change="toggleAll($event.target.checked)" /></label></th>
                <sort-th tone="key" icon="doc-text">{{ $t('Contract') }}</sort-th>
                <sort-th v-if="money" tone="money" num icon="tick">{{ $t('Open to request') }}</sort-th>
                <sort-th v-if="money" tone="money" num icon="send">{{ $t('Amount to request') }}</sort-th>
                <sort-th tone="text" icon="building">{{ $t('Project / location') }}</sort-th>
                <sort-th v-if="money" tone="money" num icon="cash">{{ $t('Value') }}</sort-th>
                <sort-th tone="state" icon="tick">{{ $t('Completion') }}</sort-th>
                <sort-th v-if="money" tone="money" num icon="cash">{{ $t('Paid before') }}</sort-th>
                <sort-th v-if="money" tone="money" num icon="clock">{{ $t('Pending requests') }}</sort-th>
                <sort-th v-if="money" tone="money" num icon="clock">{{ $t('Approved, not paid') }}</sort-th>
              </tr></thead>
              <tbody>
                <tr v-for="r in shown" :key="r.agreement_id" :class="{ 'cl-off': !r.eligible, 'cl-other': otherCur(r), 'is-on': isSel(r) }">
                  <td class="cl-chk" :data-label="$t('Select')"><label class="ac-check"><input type="checkbox" :checked="isSel(r)" :disabled="!canPick(r)" :aria-label="$t('Select :r', { r: r.reference })" @change="toggle(r, $event.target.checked)" /></label></td>
                  <td :data-label="$t('Contract')"><div class="cl-cell"><a :href="r.url" target="_blank" rel="noopener" class="lg-main"><mark-hit :text="r.reference" :q="tq" /></a><div class="lg-subtext"><mark-hit :text="r.title" :q="tq" /></div></div></td>
                  <td v-if="money" :data-label="$t('Open to request')" class="ac-num"><b :class="neg(r.remaining_eligible_signed) ? 'amt-neg' : 'amt-out'">{{ mc(r.remaining_eligible, r.currency) }}</b></td>
                  <td v-if="money" :data-label="$t('Amount to request')" class="ac-num cl-amt">
                    <div v-if="isSel(r)" class="cl-cell"><input v-model="sel[r.agreement_id].amount" class="cl-in" :class="{ 'is-bad': !!rowError(r) }" inputmode="decimal" :readonly="!settings.allow_partial" :aria-label="$t('Amount to request for :r', { r: r.reference })" /><div v-if="rowError(r)" class="cl-err">{{ rowError(r) }}</div><div v-else-if="isPartial(r)" class="lg-subtext">{{ $t('Partial: :n left', { n: leftAfter(r) }) }}</div></div>
                    <span v-else class="lg-subtext">-</span>
                  </td>
                  <td :data-label="$t('Project / location')"><mark-hit :text="r.project || '-'" :q="tq" /><div v-if="r.projects && r.projects.length > 1" class="lg-subtext">+{{ r.projects.length - 1 }}</div></td>
                  <td v-if="money" :data-label="$t('Value')" class="ac-num"><div class="cl-cell"><b class="amt-rev">{{ mc(r.revised, r.currency) }}</b><div v-if="r.original !== r.revised" class="lg-subtext">{{ $t('Original') }} {{ mc(r.original, r.currency) }}</div></div></td>
                  <td :data-label="$t('Completion')"><div class="cl-cell"><span class="lg-chip" :class="stateChip(r)">{{ stateLabel(r) }}</span><div class="lg-subtext"><template v-if="r.completed_on">{{ $date(r.completed_on) }}</template><template v-if="r.evidence_count"> · {{ r.evidence_count }} {{ $t('document(s)') }}</template></div>
                    <div v-if="r.blocked_message" class="cl-why">{{ r.blocked_message }}</div><div v-else-if="otherCur(r)" class="cl-why">{{ $t('Another currency: a request has one currency (:c).', { c: lockCur }) }}</div></div></td>
                  <td v-if="money" :data-label="$t('Paid before')" class="ac-num"><span class="amt-paid">{{ mc(r.previous_paid, r.currency) }}</span></td>
                  <td v-if="money" :data-label="$t('Pending requests')" class="ac-num"><span class="amt-appr">{{ mc(r.pending, r.currency) }}</span></td>
                  <td v-if="money" :data-label="$t('Approved, not paid')" class="ac-num"><span class="amt-appr">{{ mc(r.approved_unpaid, r.currency) }}</span></td>
                </tr>
                <tr v-if="!shown.length" class="lg-empty-row"><td class="lg-empty" :colspan="money ? 10 : 4">{{ rows.length ? $t('No contract matches your search.') : $t('This subcontractor has no completed contract that can be requested now.') }}</td></tr>
              </tbody></table></div>
            <p v-if="!money && rows.length" class="lg-sub" style="margin-top: 8px">{{ $t('You cannot see amounts: the whole balance of each ticked contract is requested.') }}</p>
            <span class="ac-err">{{ err('lines') }}</span>
          </template>
        </collapse-section>

        <collapse-section>
          <template #head><span class="lg-card-ico lg-ico-indigo"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Description and documents') }}</h2></div></template>
          <div class="lg-fields">
            <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Description') }}</label><textarea v-model="form.description" :placeholder="$t('What is being requested and why (optional)')"></textarea><span class="ac-err">{{ err('description') }}</span></div>
          </div>
          <acct-file-list v-if="claim && attachments && attachments.length" :files="attachments" :download-url="f => route('subcontracts.claims.download', [claim.id, f.id])" style="margin: 10px 0" />
          <div style="margin-top: 10px"><acct-dropzone v-model="form.attachments" :hint="$t('PDF, images and office files up to 8 MB each.')" /><span class="ac-err">{{ err('attachments') || err('attachments.0') }}</span></div>
        </collapse-section>

        <div class="lg-actionbar cl-bar">
          <div class="cl-sum" :class="{ 'is-bad': problems.length }">
            <div class="cl-sum-main"><span><b>{{ chosen.length }}</b> {{ chosen.length === 1 ? $t('contract selected') : $t('contracts selected') }}</span>
              <span v-if="money && chosen.length" class="cl-total">{{ $t('Total requested') }}: <b class="amt-rev">{{ mc(totalText, lockCur) }}</b></span></div>
            <ul v-if="problems.length" class="cl-warn"><li v-for="(p, i) in problems.slice(0, 4)" :key="i"><icons name="exclamation" class="w-4 h-4" />{{ p }}</li><li v-if="problems.length > 4">{{ $t(':n more warnings', { n: problems.length - 4 }) }}</li></ul>
          </div>
          <div class="lg-actionbar-btns"><Link :href="claim ? route('subcontracts.claims.show', claim.id) : route('subcontracts.claims.index')" class="lg-btn t-slate"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</Link>
            <button type="button" class="lg-btn lg-btn-soft" :disabled="form.processing || !chosen.length || hasRowErrors" @click="save(false)"><icons name="tick" class="w-4 h-4" />{{ form.processing && !goSubmit ? $t('Saving...') : $t('Save draft') }}</button>
            <button v-if="canSubmit" type="button" class="lg-btn lg-btn-primary" :disabled="form.processing || !chosen.length || hasRowErrors" @click="save(true)"><icons name="send" class="w-4 h-4" />{{ form.processing && goSubmit ? $t('Saving...') : $t('Save and submit') }}</button></div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import { h } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import AcctDropzone from '@/Pages/Accounts/AcctDropzone.vue';
import AcctFileList from '@/Pages/Accounts/AcctFileList.vue';
import CollapseSection from '../CollapseSection.vue';
import SearchBar from '../SearchBar.vue';
import SortTh from '../SortTh.vue';
import { sc } from '../sc';
import { toCents, fromCents, ZERO } from './money';

// the part of a text that matches the search is marked
const MarkHit = {
  props: { text: [String, Number], q: String },
  render() {
    const t = String(this.text === null || this.text === undefined ? '' : this.text); const q = String(this.q || '').trim();
    const i = q ? t.toLowerCase().indexOf(q.toLowerCase()) : -1;
    if (i < 0) return t;
    return [t.slice(0, i), h('mark', { class: 'sc-mark' }, t.slice(i, i + q.length)), t.slice(i + q.length)];
  },
};

export default {
  components: { MarkHit, CollapseSection, SearchBar, SortTh, AdminLayout, PageHeader, Link, DatePicker, AcctSelect, AcctDropzone, AcctFileList },
  mixins: [sc],
  props: { claim: Object, contacts: Array, projects: Array, eligible_url: String, money: Boolean, today: String, attachments: Array, preset: Object, settings: Object },
  data() {
    const c = this.claim || {};
    const sel = {}; const order = [];
    (c.lines || []).forEach(l => { sel[l.agreement_id] = { amount: l.amount || '', note: l.note || '', fresh: false }; order.push(l.agreement_id); });
    return {
      rows: [], loading: false, loadError: '', showBlocked: false, tq: '', pf: null, sel, order, goSubmit: false, seq: 0,
      form: this.$inertia.form({ contact_id: c.contact_id || (this.preset && this.preset.contact_id) || null, warehouse_id: c.warehouse_id || (this.preset && this.preset.warehouse_id) || null, request_date: c.request_date || this.today, description: c.description || '', attachments: [] }),
    };
  },
  mounted() { if (this.form.contact_id) this.load(); },
  computed: {
    canSubmit() { return this.$can(['submit-subcontract-claims']); },
    chosen() { return this.order.filter(id => this.sel[id]).map(id => this.rows.find(r => r.agreement_id === id) || this.stub(id)); },
    lockCur() { const f = this.chosen[0]; return f ? f.currency : ''; },
    projectOptions() { const s = new Set(); this.rows.forEach(r => (r.projects && r.projects.length ? r.projects : r.project ? [r.project] : []).forEach(p => s.add(p))); return [...s].sort().map(p => ({ value: p, label: p })); },
    shown() {
      const q = this.tq.trim().toLowerCase();
      return this.rows.filter(r => (this.showBlocked || r.eligible || this.isSel(r))
        && (!this.pf || (r.projects || [r.project]).includes(this.pf))
        && (!q || [r.reference, r.title, r.project, r.contact].some(v => v && String(v).toLowerCase().includes(q))));
    },
    selectable() { return this.shown.filter(r => this.canPick(r)); },
    allOn() { return this.selectable.length > 0 && this.selectable.every(r => this.isSel(r)); },
    // the total of the ticked lines in exact cents (the claim has one currency)
    total() { return this.chosen.reduce((t, r) => { const c = toCents(this.amountOf(r)); return c === null ? t : t + c; }, ZERO); },
    totalText() { return fromCents(this.total); },
    hasRowErrors() { return this.chosen.some(r => !!this.rowError(r)); },
    problems() {
      const out = [];
      if (!this.chosen.length) out.push(this.$t('Tick at least one contract.'));
      this.chosen.forEach(r => { const e = this.rowError(r); if (e) out.push(r.reference + ': ' + e); if (!r.eligible) out.push(r.reference + ': ' + (r.blocked_message || this.$t('This contract cannot be requested now.'))); });
      const errs = (this.$page.props.errors || {});
      Object.keys(errs).filter(k => k === 'claim' || k.startsWith('lines.') || k === 'lines').forEach(k => out.push(errs[k]));
      return [...new Set(out)];
    },
  },
  methods: {
    stub(id) { const l = ((this.claim && this.claim.lines) || []).find(x => x.agreement_id === id) || {}; return { agreement_id: id, reference: l.reference, title: l.title, currency: this.lockCur || (this.claim && this.claim.currency) || '', eligible: false, blocked_message: this.$t('This contract is not open to request any more.'), remaining_eligible: null, projects: [], url: route('subcontracts.agreements.show', id) }; },
    isSel(r) { return !!this.sel[r.agreement_id]; },
    otherCur(r) { return !!this.lockCur && r.currency !== this.lockCur; },
    canPick(r) { return (r.eligible || this.isSel(r)) && !this.otherCur(r); },
    amountOf(r) { const s = this.sel[r.agreement_id]; return s ? s.amount : ''; },
    stateLabel(r) { if (r.status !== 'Completed') return this.$t(r.status); return r.verified ? this.$t('Completed and verified') : this.$t('Completed, not verified'); },
    stateChip(r) { if (r.status !== 'Completed') return 'lg-chip-slate'; return r.verified ? 'lg-chip-green' : 'lg-chip-amber'; },
    isPartial(r) { const a = toCents(this.amountOf(r)); const w = toCents(r.remaining_eligible); return a !== null && w !== null && a > ZERO && a < w; },
    leftAfter(r) { return this.mc(fromCents(toCents(r.remaining_eligible) - toCents(this.amountOf(r))), r.currency); },
    // live validation of one ticked line (the server checks everything again)
    rowError(r) {
      if (!this.money || !this.isSel(r)) return '';
      const a = toCents(this.amountOf(r)); const w = toCents(r.remaining_eligible);
      if (a === null) return this.$t('Enter an amount, for example 1500000.00');
      if (a <= ZERO) return this.$t('The amount must be above zero.');
      if (w !== null && a > w) return this.$t('More than the :a still open to request.', { a: this.mc(r.remaining_eligible, r.currency) });
      if (!this.settings.allow_partial && w !== null && a !== w) return this.$t('Partial requests are off: ask for the whole balance.');
      return '';
    },
    toggle(r, on) {
      if (on) {
        if (!this.canPick(r)) return;
        this.sel = { ...this.sel, [r.agreement_id]: { amount: r.default_amount || '', note: '', fresh: true } };
        if (!this.order.includes(r.agreement_id)) this.order.push(r.agreement_id);
      } else {
        const s = { ...this.sel }; delete s[r.agreement_id]; this.sel = s; this.order = this.order.filter(i => i !== r.agreement_id);
      }
    },
    toggleAll(on) { this.shown.forEach(r => { if (on ? this.canPick(r) : this.isSel(r)) this.toggle(r, on); }); },
    async load() {
      const n = ++this.seq; this.loading = true; this.loadError = '';
      try {
        const res = await window.axios.get(this.eligible_url, { params: { contact_id: this.form.contact_id, ...(this.form.warehouse_id ? { warehouse_id: this.form.warehouse_id } : {}), all: this.showBlocked ? 1 : 0 } });
        if (n !== this.seq) return;
        this.rows = res.data.rows;
        // a ticked contract that is no longer listed stays visible as a stub; one the server now lists gets the live default only when new
        ((this.claim && this.claim.lines) || []).forEach(l => { if (this.sel[l.agreement_id] && !this.rows.some(r => r.agreement_id === l.agreement_id)) this.rows.push({ ...this.stub(l.agreement_id), currency: this.claim.currency }); });
        Object.keys(this.sel).forEach(id => { const r = this.rows.find(x => x.agreement_id === Number(id)); if (r && !this.sel[id].amount && this.money) this.sel[id].amount = r.default_amount || ''; });
      } catch (e) { if (n === this.seq) { this.rows = []; this.loadError = (e.response && e.response.data && e.response.data.message) || this.$t('The contracts could not be loaded.'); } } finally { if (n === this.seq) this.loading = false; }
    },
    reload(reset) { if (reset) { this.sel = {}; this.order = []; this.pf = null; this.rows = []; } if (this.form.contact_id) this.load(); },
    save(submit) {
      this.goSubmit = submit;
      const lines = this.chosen.map(r => ({ agreement_id: r.agreement_id, amount: this.money ? this.amountOf(r) : '', note: (this.sel[r.agreement_id] || {}).note || '' }));
      const edit = !!this.claim;
      this.form.transform(d => ({ ...d, lines, ...(edit ? { _method: 'put' } : {}) }))
        .post(edit ? route('subcontracts.claims.update', this.claim.id) : route('subcontracts.claims.store'), {
          forceFormData: true, preserveScroll: true,
          onSuccess: page => { if (submit && page.props.claim && page.props.claim.id) router.post(route('subcontracts.claims.submit', page.props.claim.id), {}, { preserveScroll: true }); },
        });
    },
  },
};
</script>

<template>
  <admin-layout :title="b.reference">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="upload" :title="b.reference" :subtitle="b.file">
        <Link :href="route('subcontracts.import.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Batches') }}</span></Link>
        <Link v-if="b.status === 'imported'" :href="route('subcontracts.reconciliation.index')" class="lg-btn lg-btn-soft t-teal"><icons name="tick" class="w-4 h-4" /><span>{{ $t('Reconciliation') }}</span></Link>
        <button v-if="can.confirm" type="button" class="lg-btn lg-btn-green" :disabled="busy" @click="confirm"><icons name="tick" class="w-4 h-4" /><span>{{ $t('Confirm the import') }}</span></button>
        <button v-if="can.revert" type="button" class="lg-btn lg-btn-red" :disabled="busy" @click="revert"><icons name="refresh" class="w-4 h-4" /><span>{{ $t('Take the import back') }}</span></button>
              <export-buttons type="import" />
      </page-header>
      <section class="lg-card">
        <div class="ac-attn">
          <div class="ac-tile ac-glow tone-navy"><div><div class="n">{{ sheets.length }}</div><div class="l">{{ $t('Account sheets') }}</div></div></div>
          <div class="ac-tile ac-glow tone-emerald"><div><div class="n">{{ chosen.length }}</div><div class="l">{{ $t('To import') }}</div></div></div>
          <div class="ac-tile ac-glow tone-amber"><div><div class="n">{{ sheets.filter(s => s.decision === 'pending').length }}</div><div class="l">{{ $t('Waiting for your decision') }}</div></div></div>
          <div class="ac-tile ac-glow tone-slate"><div><div class="n">{{ sheets.filter(s => s.decision === 'skip').length }}</div><div class="l">{{ $t('Skipped') }}</div></div></div>
        </div>
        <div class="sc-note-grid">
          <div class="lg-note lg-note-blue"><icons name="info" class="w-4 h-4" /><span>{{ $t('The SUMMARY sheet of the workbook lists :n lines totalling :c contract and :p paid (TZS).', { n: b.summary.summary_rows, c: m(b.summary.summary_total_contract, 'TZS'), p: m(b.summary.summary_total_paid, 'TZS') }) }}</span></div>
          <div v-if="b.summary.summary_duplicates && b.summary.summary_duplicates.length" class="lg-note lg-note-amber"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t('Listed twice in SUMMARY (so counted twice there):') }} <b>{{ b.summary.summary_duplicates.join(', ') }}</b></span></div>
          <div class="lg-note lg-note-blue"><icons name="info" class="w-4 h-4" /><span>{{ $t('What would be imported (after the currency decisions) is :c contract and :p paid, from :n sheets.', { c: m(totalChosen.contract, 'TZS'), p: m(totalChosen.paid, 'TZS'), n: chosen.length }) }}</span></div>
        </div>
        <p v-if="err('batch')" class="ac-err">{{ err('batch') }}</p>
      </section>

      <div v-if="sheets.length > 6" class="sc-tfind"><search-bar v-model="tq" :placeholder="$t('Find a sheet by name or title...')" :count="sheetRows.length" /></div>
      <section v-for="s in sheetRows" :key="s.id" class="lg-card sc-sheet" :class="'is-' + s.decision">
        <header class="lg-card-head"><span class="lg-card-ico" :class="['import', 'archive'].includes(s.decision) ? 'lg-ico-emerald' : s.decision === 'skip' ? 'lg-ico-slate' : 'lg-ico-amber'"><icons :name="s.decision === 'import' ? 'tick' : s.decision === 'skip' ? 'x-circle' : 'clock'" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ s.sheet }} <span v-if="s.state === 'hidden'" class="lg-chip lg-chip-slate" style="text-transform: none">{{ $t('hidden sheet') }}</span> <span v-if="s.agreement_id" class="lg-chip lg-chip-green" style="text-transform: none">{{ $t('imported') }}</span></h2><p class="lg-card-sub">{{ s.title }}<template v-if="s.as_at"> · {{ $t('as at') }} {{ $date(s.as_at) }}</template> · {{ s.items }} {{ $t('items') }}, {{ s.pays }} {{ $t('dated payments') }}, {{ $t('layout') }} {{ s.layout }}</p></div>
          <div class="sc-figs-sm"><div><small>{{ $t('Contract') }}</small><b>{{ m(s.totals.converted_contract, 'TZS') }}</b></div><div><small>{{ $t('Paid') }}</small><b>{{ m(s.totals.converted_paid, 'TZS') }}</b></div><div v-if="s.totals.contract !== null"><small>{{ $t('Sheet total') }}</small><b>{{ m(s.totals.contract, s.currency || 'TZS') }}</b></div></div></header>
        <p v-if="s.error" class="ac-err">{{ s.error }}</p>
        <ul v-if="s.flags.length" class="sc-flags"><li v-for="(f, i) in s.flags" :key="i" :class="'is-' + f.level"><icons :name="f.level === 'warn' || f.level === 'error' ? 'exclamation' : 'info'" class="w-4 h-4" />{{ f.text }}</li></ul>
        <form v-if="can.edit && !s.error" class="sc-form" @submit.prevent="saveSheet(s)">
          <div class="ac-filters">
            <div class="ac-f"><label>{{ $t('Decision') }}</label><acct-select v-model="forms[s.id].decision" :options="decisions" /></div>
            <template v-if="['import', 'archive'].includes(forms[s.id].decision)">
              <div class="ac-f"><label>{{ $t('Subcontractor (Contact)') }}</label><acct-select v-model="forms[s.id].contact_id" :options="contacts" :placeholder="s.totals.match_contact_id ? $t('Suggested match is below') : $t('Choose, or create a new one')" clearable /><small v-if="s.totals.match_contact_id && !forms[s.id].contact_id" class="lg-subtext">{{ $t('Closest Contact:') }} {{ contactName(s.totals.match_contact_id) }} ({{ Math.round(s.totals.match_score * 100) }}%) <a href="#" @click.prevent="forms[s.id].contact_id = s.totals.match_contact_id">{{ $t('use it') }}</a></small></div>
              <div v-if="!forms[s.id].contact_id" class="ac-f"><label>{{ $t('Or create the Contact') }}</label><input v-model="forms[s.id].new_contact" :placeholder="can.create_contacts ? $t('Name of the new subcontractor') : $t('You may not create Contacts')" :disabled="!can.create_contacts" /></div>
              <div class="ac-f"><label>{{ $t('Agreement title') }}</label><input v-model="forms[s.id].name" /></div>
              <div class="ac-f"><label>{{ $t('Project(s) / Warehouse(s)') }}</label><acct-select v-model="forms[s.id].warehouse_id" :options="projects" :placeholder="$t('Not mapped')" clearable /></div>
              <div class="ac-f"><label>{{ $t('Currency of the agreement') }}</label><acct-select v-model="forms[s.id].currency" :options="currencies" /></div>
            </template>
          </div>
          <div v-if="['import', 'archive'].includes(forms[s.id].decision) && s.groups.some(g => g.currency)" class="sc-grp"><b>{{ $t('Work groups in US dollars') }}</b>
            <div v-for="(g, gi) in s.groups" :key="gi"><template v-if="g.currency"><span>{{ g.name }}</span><acct-select v-model="forms[s.id].groups[gi].currency" :options="[{ value: 'TZS', label: 'TZS' }, { value: 'USD', label: 'USD' }]" /><input v-model="forms[s.id].groups[gi].rate" :placeholder="$t('Rate to TZS')" inputmode="decimal" /></template></div></div>
          <div class="ac-actions"><button type="submit" class="lg-btn lg-btn-soft" :disabled="forms[s.id].processing"><icons name="tick" class="w-4 h-4" />{{ $t('Save the decision') }}</button></div>
        </form>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import ExportButtons from '../ExportButtons.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import SearchBar from '../SearchBar.vue';
import { sc, tableFind } from '../sc';

export default {
  components: { SearchBar, ExportButtons, AdminLayout, PageHeader, Link, AcctSelect },
  mixins: [sc, tableFind],
  props: { b: Object, sheets: Array, contacts: Array, projects: Array, currencies: Array, can: Object },
  data() {
    const forms = {};
    this.sheets.forEach(s => { forms[s.id] = this.$inertia.form({ decision: s.decision, contact_id: s.contact_id, new_contact: s.new_contact || '', warehouse_id: s.warehouse_id, currency: s.currency, name: s.name, groups: s.groups.map(g => ({ currency: g.currency, rate: g.rate })) }); });
    return { forms, busy: false };
  },
  computed: {
    sheetRows() { return this.sheets.filter(s => this.has(s.sheet, s.title, s.state)); },
    decisions() { return [{ value: 'import', label: this.$t('Import as a live agreement') }, { value: 'archive', label: this.$t('Import into the archive') }, { value: 'skip', label: this.$t('Skip') }, { value: 'pending', label: this.$t('Decide later') }]; },
    chosen() { return this.sheets.filter(s => ['import', 'archive'].includes(s.decision)); },
    totalChosen() { return { contract: this.chosen.reduce((a, s) => a + Number(s.totals.converted_contract || 0), 0).toFixed(2), paid: this.chosen.reduce((a, s) => a + Number(s.totals.converted_paid || 0), 0).toFixed(2) }; },
  },
  methods: {
    contactName(id) { const c = this.contacts.find(x => x.value === id); return c ? c.label : ''; },
    saveSheet(s) { this.forms[s.id].put(route('subcontracts.import.sheets.update', [this.b.id, s.id]), { preserveScroll: true }); },
    confirm() { this.$confirm(this.$t('Create :n agreements from this workbook? Historical payments are imported as counted opening payments, not as Payment Vouchers.', { n: this.chosen.length })).then(ok => { if (ok) { this.busy = true; this.$inertia.post(route('subcontracts.import.confirm', this.b.id), {}, { onFinish: () => (this.busy = false) }); } }); },
    revert() { this.$confirm(this.$t('Take this import back? The agreements it made are removed (only possible while they have no requests or variations).')).then(ok => { if (ok) { this.busy = true; this.$inertia.post(route('subcontracts.import.revert', this.b.id), {}, { onFinish: () => (this.busy = false) }); } }); },
  },
};
</script>

<style scoped>
.sc-sheet.is-skip { opacity: 0.7; } .sc-flags { list-style: none; margin: 6px 0; padding: 0; display: grid; gap: 4px; } .sc-flags li { display: flex; gap: 6px; font-size: 0.82rem; align-items: flex-start; } .sc-flags li.is-warn, .sc-flags li.is-error { color: #b45309; } .sc-flags li.is-info { color: var(--lg-mute); }
.sc-figs-sm { display: flex; gap: 14px; flex-wrap: wrap; } .sc-figs-sm small { display: block; font-size: 0.64rem; font-weight: 800; text-transform: uppercase; color: var(--lg-mute); } .sc-figs-sm b { font-size: 0.92rem; font-variant-numeric: tabular-nums; }
.sc-form { margin-top: 8px; } .sc-grp { display: grid; gap: 6px; margin: 8px 0; } .sc-grp > div { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 8px; align-items: center; }
.sc-note-grid { display: grid; gap: 8px; margin-top: 10px; }
</style>

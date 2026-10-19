<template>
  <admin-layout :title="$t('Subcontract Excel Import')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="upload" :title="$t('Subcontract Excel Import')" :subtitle="$t('Bring in the historical workbook safely: it is staged and reviewed first, and nothing reaches the records until a person with the right confirms it.')">
        <Link :href="route('subcontracts.reconciliation.index')" class="lg-btn lg-btn-soft"><icons name="tick" class="w-4 h-4" /><span>{{ $t('Reconciliation') }}</span></Link>
        <Link :href="route('subcontracts.reports.index', { type: 'import' })" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Import report') }}</span></Link>
      </page-header>
      <section v-if="can.import" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="upload" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Upload the workbook') }}</h2><p class="lg-card-sub">{{ $t('An .xlsx file of the SUB CONTRACTS workbook (up to 30 MB). Every sheet is read; nothing is imported yet.') }}</p></div></header>
        <form @submit.prevent="upload">
          <acct-dropzone v-model="form.files" accept=".xlsx" :multiple="false" :max-size="30" :hint="$t('Excel workbook (.xlsx) up to 30 MB.')" :label="$t('Drop the workbook here or click to choose')" />
          <span class="ac-err">{{ err('file') }}</span>
          <div class="ac-actions" style="margin-top: 10px"><button type="submit" class="lg-btn lg-btn-primary" :disabled="!form.files.length || form.processing"><icons name="upload" class="w-4 h-4" />{{ form.processing ? $t('Reading the workbook...') : $t('Read and stage') }}</button></div>
        </form>
      </section>
      <div class="sc-over sc-reg-cards">
        <overview-card icon="collection" :title="$t('Batches')" :sub="$t('Every upload')" :value="String(batches.length)" :foot="$t('Show All')" :class="{ 'is-on': !st }" role="button" tabindex="0" @click="st = ''" @keydown.enter="st = ''" />
        <overview-card variant="amber" icon="clock" :title="$t('Staged')" :sub="$t('Waiting to be confirmed')" :value="String(n('staged'))" :foot="$t('Show Staged')" :class="{ 'is-on': st === 'staged' }" role="button" tabindex="0" @click="st = 'staged'" @keydown.enter="st = 'staged'" />
        <overview-card variant="hero" icon="tick" :title="$t('Imported')" :sub="$t('In the records')" :value="String(n('imported'))" :foot="$t('Show Imported')" :class="{ 'is-on': st === 'imported' }" role="button" tabindex="0" @click="st = 'imported'" @keydown.enter="st = 'imported'" />
        <overview-card icon="refresh" :title="$t('Reverted')" :sub="$t('Taken back out')" :value="String(n('reverted'))" :foot="$t('Show Reverted')" :class="{ 'is-on': st === 'reverted' }" role="button" tabindex="0" @click="st = 'reverted'" @keydown.enter="st = 'reverted'" />
      </div>
      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Batches') }}</h2></div></header>
        <search-bar v-model="tq" :placeholder="$t('Search by batch, file or status...')" :count="shown.length" />
        <div class="lg-table-wrap"><table class="lg-table lg-responsive">
          <thead><tr>
            <th><span class="sc-th"><icons name="hash" class="w-4 h-4" />{{ $t('Batch') }}</span></th>
            <th><span class="sc-th"><icons name="clip" class="w-4 h-4" />{{ $t('File') }}</span></th>
            <th class="ac-num"><span class="sc-th"><icons name="table" class="w-4 h-4" />{{ $t('Sheets') }}</span></th>
            <th><span class="sc-th"><icons name="tick" class="w-4 h-4" />{{ $t('Status') }}</span></th>
            <th><span class="sc-th"><icons name="calendar" class="w-4 h-4" />{{ $t('Staged') }}</span></th>
            <th></th></tr></thead>
          <tbody>
            <tr v-for="b in shown" :key="b.id" class="is-click" tabindex="0" @click="open(b)" @keydown.enter="open(b)">
              <td :data-label="$t('Batch')"><div class="sc-who"><span class="sc-av av-sq" :class="'av-' + tone(b.id)"><icons name="upload" class="w-4 h-4" /></span><span class="lg-main"><mark-hit :text="b.reference" :q="tq" /></span></div></td>
              <td :data-label="$t('File')"><mark-hit :text="b.file" :q="tq" /></td>
              <td :data-label="$t('Sheets')" class="ac-num"><span class="sc-pill pill-blue">{{ b.sheets }}</span></td>
              <td :data-label="$t('Status')"><span class="lg-chip" :class="chip(b.status)">{{ b.status }}</span></td>
              <td :data-label="$t('Staged')" class="sc-nowrap">{{ b.at }}</td>
              <td class="lg-sticky-end" @click.stop><Link :href="route('subcontracts.import.show', b.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Open')" :aria-label="$t('Open')"><icons name="eye-flat" class="w-4 h-4" /></Link></td>
            </tr>
            <tr v-if="!shown.length" class="lg-empty-row"><td class="lg-empty" colspan="6">{{ batches.length ? $t('There is no data to display.') : $t('Nothing has been staged yet.') }}</td></tr>
          </tbody></table></div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { h } from 'vue';
import { Link } from '@inertiajs/vue3';
import OverviewCard from '../OverviewCard.vue';
import SearchBar from '../SearchBar.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AcctDropzone from '@/Pages/Accounts/AcctDropzone.vue';
import { sc, tableFind } from '../sc';

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
  components: { MarkHit, OverviewCard, SearchBar, AdminLayout, PageHeader, Link, AcctDropzone },
  mixins: [sc, tableFind],
  props: { batches: Array, can: Object },
  data() { return { form: this.$inertia.form({ files: [] }), st: '' }; },
  computed: { shown() { return this.batches.filter(b => (!this.st || b.status === this.st) && this.has(b.reference, b.file, b.status, b.at, b.sheets)); } },
  methods: {
    n(k) { return this.batches.filter(b => b.status === k).length; },
    tone(id) { return ['blue', 'green', 'violet', 'amber', 'rose', 'teal'][id % 6]; },
    open(b) { this.$inertia.visit(route('subcontracts.import.show', b.id)); },
    upload() { this.form.transform(d => ({ file: d.files[0] })).post(route('subcontracts.import.store'), { forceFormData: true }); },
  },
};
</script>

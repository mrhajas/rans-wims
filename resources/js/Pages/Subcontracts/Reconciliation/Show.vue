<template>
  <admin-layout :title="a.reference">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="tick" :title="$t('Reconciliation') + ' ' + a.reference" :subtitle="a.contact + ' - ' + a.title">
        <Link :href="route('subcontracts.reconciliation.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('All') }}</span></Link>
        <Link :href="route('subcontracts.agreements.show', a.id)" class="lg-btn lg-btn-soft"><icons name="doc-text" class="w-4 h-4" /><span>{{ $t('Agreement') }}</span></Link>
      </page-header>
      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="cash" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Workbook against WIMS') }} ({{ a.currency }})</h2><p v-if="a.as_at" class="lg-card-sub">{{ $t('Position as at') }} {{ $date(a.as_at) }}</p></div><span class="lg-chip" :class="chip(r.status)">{{ r.status === 'open' ? $t('Open') : $t('Approved') }}</span></header>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th>{{ $t('Figure') }}</th><th class="ac-num"><span class="sc-th amt-appr"><icons name="doc-text" class="w-4 h-4" />{{ $t('Workbook') }}</span></th><th class="ac-num"><span class="sc-th amt-rev"><icons name="cash" class="w-4 h-4" />{{ $t('WIMS') }}</span></th><th class="ac-num"><span class="sc-th amt-out"><icons name="exclamation" class="w-4 h-4" />{{ $t('Difference') }}</span></th></tr></thead>
          <tbody><tr><td :data-label="$t('Figure')" class="lg-main">{{ $t('Contract value') }}</td><td :data-label="$t('Workbook')" class="ac-num"><span class="amt-appr">{{ r.source_contract !== null ? m(r.source_contract) : '-' }}</span></td><td :data-label="$t('WIMS')" class="ac-num"><span class="amt-rev">{{ m(r.imported_contract) }}</span></td><td :data-label="$t('Difference')" class="ac-num"><b :class="Number(r.diff_contract) !== 0 ? 'amt-neg' : 'amt-paid'">{{ m(r.diff_contract) }}</b></td></tr>
            <tr><td :data-label="$t('Figure')" class="lg-main">{{ $t('Paid (counted)') }}</td><td :data-label="$t('Workbook')" class="ac-num"><span class="amt-appr">{{ r.source_paid !== null ? m(r.source_paid) : '-' }}</span></td><td :data-label="$t('WIMS')" class="ac-num"><span class="amt-rev">{{ m(r.imported_paid) }}</span></td><td :data-label="$t('Difference')" class="ac-num"><b :class="Number(r.diff_paid) !== 0 ? 'amt-neg' : 'amt-paid'">{{ m(r.diff_paid) }}</b></td></tr>
            <tr><td :data-label="$t('Figure')" class="lg-main">{{ $t('Dated payment list (evidence)') }}</td><td :data-label="$t('Workbook')" class="ac-num">-</td><td :data-label="$t('WIMS')" class="ac-num"><span class="amt-rev">{{ m(r.dated_list) }}</span></td><td :data-label="$t('Difference')" class="ac-num">{{ $t('paid without a date in the list') }}: <span class="amt-out">{{ m(r.dated_gap) }}</span></td></tr></tbody></table></div>
        <p v-if="r.status === 'approved' && r.note" class="lg-note lg-note-blue" style="margin-top: 10px">{{ r.note }} <small v-if="r.approved_at">({{ r.approved_at }})</small></p>
        <form v-if="r.status === 'open' && (can.approve || can.note)" class="sc-form" @submit.prevent>
          <div class="ac-f"><label>{{ $t('Explanation') }}</label><textarea v-model="form.note" rows="3" :placeholder="$t('Why the workbook and WIMS differ, and why it is acceptable')"></textarea><span class="ac-err">{{ err('note') }}</span></div>
          <div class="ac-actions"><button v-if="can.note" type="button" class="lg-btn lg-btn-soft" :disabled="form.processing" @click="saveNote"><icons name="edit" class="w-4 h-4" />{{ $t('Save the note') }}</button><button v-if="can.approve" type="button" class="lg-btn lg-btn-green" :disabled="form.processing" @click="approve"><icons name="tick" class="w-4 h-4" />{{ $t('Approve the difference') }}</button></div>
        </form>
      </section>
      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="archive-box" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Imported payment lines') }}</h2><p class="lg-card-sub">{{ $t('"Counted" lines make the paid figure; "evidence" lines only show dates and are never added.') }}</p></div></header>
        <div v-if="history.length > 6" class="sc-tfind"><search-bar v-model="tq" :placeholder="$t('Find a line by note, source or kind...')" :count="lines.length" /></div>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th>{{ $t('Kind') }}</th><th><span class="sc-th"><icons name="calendar" class="w-4 h-4" />{{ $t('Date') }}</span></th><th class="ac-num"><span class="sc-th amt-paid"><icons name="cash" class="w-4 h-4" />{{ $t('Amount') }}</span></th><th>{{ $t('Source') }}</th><th>{{ $t('Note') }}</th></tr></thead>
          <tbody><tr v-for="h in lines" :key="h.id"><td :data-label="$t('Kind')"><span class="lg-chip" :class="h.counted ? 'lg-chip-green' : 'lg-chip-slate'">{{ h.counted ? $t('Counted') : $t('Evidence') }}</span></td><td :data-label="$t('Date')">{{ h.payment_date ? $date(h.payment_date) : '-' }}</td><td :data-label="$t('Amount')" class="ac-num"><span :class="h.counted ? 'amt-paid' : 'amt-appr'">{{ mc(h.amount, h.currency) }}</span></td><td :data-label="$t('Source')">{{ h.source_sheet }} #{{ h.source_row }}</td><td :data-label="$t('Note')">{{ h.note }}</td></tr>
            <tr v-if="!history.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('No lines.') }}</td></tr>
            <tr v-else-if="!lines.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('Nothing matches your search.') }}</td></tr></tbody></table></div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import SearchBar from '../SearchBar.vue';
import { sc, tableFind } from '../sc';

export default {
  components: { SearchBar, AdminLayout, PageHeader, Link },
  mixins: [sc, tableFind],
  props: { a: Object, r: Object, history: Array, can: Object },
  data() { return { form: this.$inertia.form({ note: this.r.note || '' }) }; },
  computed: { lines() { return this.history.filter(h => this.has(h.note, h.source_sheet, h.source_row, h.counted ? 'counted' : 'evidence', h.payment_date)); } },
  methods: {
    saveNote() { this.form.put(route('subcontracts.reconciliation.note', this.a.id), { preserveScroll: true }); },
    approve() { this.$confirm(this.$t('Approve this difference with your note? It is recorded.')).then(ok => { if (ok) this.form.post(route('subcontracts.reconciliation.approve', this.a.id), { preserveScroll: true }); }); },
  },
};
</script>

<style scoped>.sc-form { margin-top: 10px; display: grid; gap: 8px; }</style>

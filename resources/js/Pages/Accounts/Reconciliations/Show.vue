<template>
  <admin-layout :title="r.reference">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header :icon="r.kind === 'cash' ? 'in' : 'cash'" :title="r.reference" :subtitle="(r.kind === 'cash' ? $t('Cash count') : $t('Bank reconciliation')) + ' - ' + r.account">
        <Link :href="route('acct.reconciliations.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
              <acct-export type="reconciliation_detail" :params="{ record_id: r.id }" :sheets="false" />
      </page-header>

      <section class="lg-card">
        <div class="ac-grid" style="margin-bottom: 0">
          <div class="ac-cur"><div class="ac-cur-code">{{ $t('WIMS says') }} ({{ $date(r.period_to) }})</div><div class="ac-cur-total">{{ mc(r.expected, r.currency) }}</div></div>
          <div class="ac-cur"><div class="ac-cur-code">{{ r.kind === 'cash' ? $t('Counted') : $t('Bank statement') }}</div><div class="ac-cur-total">{{ mc(r.counted, r.currency) }}</div></div>
          <div class="ac-cur" :style="Number(r.difference) !== 0 ? 'box-shadow: 0 0 0 1.5px #e11d48' : ''"><div class="ac-cur-code">{{ $t('Difference') }}</div><div class="ac-cur-total" :class="Number(r.difference) !== 0 ? 'ac-out' : 'ac-in'">{{ mc(r.difference, r.currency) }}</div><div class="ac-split"><span class="lg-chip" :class="statusChip(r.status === 'approved' ? 'posted' : r.status)">{{ $t(r.status) }}</span><span v-if="r.dual && Number(r.difference) !== 0" class="lg-chip lg-chip-amber">{{ $t('Two approvers needed') }}</span></div></div>
        </div>
        <div v-if="r.reject_reason && r.status === 'rejected'" class="lg-note lg-note-red" style="margin-top: 10px">{{ $t('Rejected') }}: {{ r.reject_reason }}</div>
        <div v-if="r.adjustment_txn_id" class="lg-note lg-note-blue" style="margin-top: 10px">{{ $t('An adjustment was proposed for the difference:') }} <Link :href="route('acct.transactions.show', r.adjustment_txn_id)">{{ $t('open it') }}</Link></div>
        <div class="ac-actions" style="margin-top: 12px">
          <button v-if="can.submit" type="button" class="lg-btn lg-btn-primary" @click="$inertia.post(route('acct.reconciliations.submit', r.id), {}, { preserveScroll: true })"><icons name="send" class="w-4 h-4" />{{ $t('Submit for approval') }}</button>
          <template v-if="can.approve">
            <label v-if="Number(r.difference) !== 0" class="ac-check"><input v-model="propose" type="checkbox" />{{ $t('Propose an adjustment entry for the difference') }}</label>
            <button type="button" class="lg-btn lg-btn-green" @click="$inertia.post(route('acct.reconciliations.approve', r.id), { propose }, { preserveScroll: true })"><icons name="tick" class="w-4 h-4" />{{ $t('Approve') }}</button>
          </template>
          <button v-if="can.reject" type="button" class="lg-btn lg-btn-red" @click="rejecting = true"><icons name="x-circle" class="w-4 h-4" />{{ $t('Reject') }}</button>
        </div>
        <form v-if="rejecting" class="ac-reason" @submit.prevent="reject"><input v-model="reason" :placeholder="$t('Reason (required)')" required /><button type="submit" class="lg-btn lg-btn-primary"><icons name="tick" class="w-4 h-4" />{{ $t('Confirm') }}</button><button type="button" class="lg-btn t-slate" @click="rejecting = false"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></form>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Details') }}</h2></div></header>
        <dl class="ac-kv">
          <div><dt>{{ $t('Account') }}</dt><dd><Link :href="route('acct.accounts.show', r.account_id)">{{ r.account }}</Link></dd></div>
          <div v-if="r.period_from"><dt>{{ $t('Statement from') }}</dt><dd>{{ $date(r.period_from) }}</dd></div>
          <div><dt>{{ $t('Prepared by') }}</dt><dd>{{ r.prepared_by_name }}</dd></div>
          <div v-if="r.witness_name"><dt>{{ $t('Witness') }}</dt><dd>{{ r.witness_name }}</dd></div>
          <div v-if="r.approved_by_name"><dt>{{ $t('Approved by') }}</dt><dd>{{ r.approved_by_name }} <small class="lg-subtext">{{ r.approved_at }}</small></dd></div>
        </dl>
        <div v-if="r.denominations" style="margin-top: 10px"><b class="lg-sub">{{ $t('Notes and coins counted') }}</b>
          <div class="ac-split" style="margin-top: 4px"><span v-for="(n, d) in r.denominations" :key="d">{{ Number(d).toLocaleString() }} x <b>{{ n }}</b></span></div></div>
        <div v-if="can.edit" class="ac-f" style="margin-top: 12px"><label>{{ $t('Explanation of the difference') }}</label><textarea v-model="explanation" :placeholder="$t('Required when there is a difference')"></textarea><button type="button" class="lg-btn lg-btn-soft" style="width: fit-content" @click="$inertia.put(route('acct.reconciliations.explain', r.id), { explanation }, { preserveScroll: true })"><icons name="tick" class="w-4 h-4" />{{ $t('Save explanation') }}</button></div>
        <p v-else-if="r.explanation" class="lg-sub" style="margin-top: 8px"><b>{{ $t('Explanation') }}:</b> {{ r.explanation }}</p>
      </section>

      <section v-if="r.kind === 'bank'" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Statement Lines') }}</h2><p class="lg-card-sub">{{ $t('Import a CSV with Date, Description, Reference and Debit/Credit (or Amount) columns, or add lines by hand. Matching changes nothing in the ledger.') }}</p></div></header>
        <div v-if="can.edit && r.status === 'draft'">
          <form class="ac-attach" @submit.prevent="importCsv">
            <acct-dropzone v-model="csv" accept=".csv,.txt" :multiple="false" :max-size="4" :max="1" :label="$t('Drop the bank statement (CSV) here or click to choose')" :hint="$t('A CSV with Date, Description, Reference and Debit / Credit (or Amount) columns, up to 4 MB.')" />
            <div class="ac-actions" style="margin: 0"><button type="submit" class="lg-btn lg-btn-primary" :disabled="!csv.length"><icons name="upload" class="w-4 h-4" />{{ $t('Import and match') }}</button><button type="button" class="lg-btn lg-btn-soft" @click="$inertia.post(route('acct.reconciliations.match', r.id), {}, { preserveScroll: true })"><icons name="refresh" class="w-4 h-4" />{{ $t('Match again') }}</button></div>
          </form>
          <form class="ac-filters" style="margin-top: 10px" @submit.prevent="addLine"><div class="ac-f"><label>{{ $t('Date') }}</label><date-picker v-model="line.entry_date" :clearable="false" /></div><div class="ac-f"><label>{{ $t('Description') }}</label><input v-model="line.description" /></div><div class="ac-f"><label>{{ $t('Reference') }}</label><input v-model="line.reference" /></div>
            <div class="ac-f"><label>{{ $t('In / out') }}</label><acct-select v-model="line.direction" :options="[{ value: 'in', label: $t('Money in (credit)') }, { value: 'out', label: $t('Money out (debit)') }]" /></div><div class="ac-f"><label>{{ $t('Amount') }}</label><input v-model="line.amount" inputmode="decimal" required /></div><div class="ac-f"><button type="submit" class="lg-btn lg-btn-soft"><icons name="tick" class="w-4 h-4" />{{ $t('Add line') }}</button></div></form>
        </div>
        <div class="lg-table-wrap" style="margin-top: 10px"><table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('Date') }}</th><th>{{ $t('Description') }}</th><th>{{ $t('Reference') }}</th><th class="ac-num">{{ $t('Amount') }}</th><th>{{ $t('Matched to') }}</th><th></th></tr></thead>
          <tbody>
            <tr v-for="i in items" :key="i.id" :class="{ 'ac-row-hot': i.status === 'unmatched' || i.status === 'duplicate' }">
              <td :data-label="$t('Date')">{{ $date(i.date) }}</td><td :data-label="$t('Description')"><span class="ac-clip">{{ i.description }}</span></td><td :data-label="$t('Reference')">{{ i.reference }}</td>
              <td :data-label="$t('Amount')" class="ac-num" :class="i.direction === 'in' ? 'ac-in' : 'ac-out'">{{ i.direction === 'in' ? '+' : '-' }}{{ mc(i.amount, r.currency) }}</td>
              <td :data-label="$t('Matched to')"><template v-if="i.txn"><Link :href="route('acct.transactions.show', i.txn.id)">{{ i.txn.reference }}</Link></template><span v-else class="lg-chip" :class="i.status === 'ignored' ? 'lg-chip-slate' : 'lg-chip-red'">{{ $t(i.status) }}</span><div v-if="i.note" class="lg-subtext">{{ i.note }}</div></td>
              <td class="lg-sticky-end"><div class="lg-actions" v-if="can.edit && r.status === 'draft'">
                <acct-select v-if="i.status !== 'matched'" :model-value="''" :options="candidates(i).map(u => ({ value: u.id, label: u.reference + ' ' + u.txn_date }))" :placeholder="$t('Match to...')" style="min-width: 190px" @update:model-value="v => v && pickTxn(i, v)" />
                <button v-if="i.status === 'matched'" type="button" class="lg-btn t-violet" @click="item(i, 'unmatch')"><icons name="refresh" class="w-4 h-4" />{{ $t('Unmatch') }}</button>
                <button v-if="i.status !== 'ignored' && i.status !== 'matched'" type="button" class="lg-btn t-slate" @click="item(i, 'ignore')"><icons name="cross" class="w-4 h-4" />{{ $t('Set aside') }}</button></div></td>
            </tr>
            <tr v-if="!items.length" class="lg-empty-row"><td class="lg-empty" colspan="6">{{ $t('No statement lines yet.') }}</td></tr>
          </tbody></table></div>
      </section>

      <section class="lg-card" v-if="timeline.length || (history && history.length)">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('History') }}</h2></div></header>
        <ul class="ac-timeline"><li v-for="(x, i) in timeline" :key="'t' + i"><b>{{ $t(x.action) }}</b> - {{ x.user }} <small>{{ x.at }}</small></li><li v-for="(h, i) in history" :key="'h' + i"><b>{{ h.event }}</b> - {{ h.user || '-' }} <small>{{ h.at }}</small><div v-if="h.reason">{{ h.reason }}</div></li></ul>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import AcctExport from '../AcctExport.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AcctSelect from '../AcctSelect.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctDropzone from '../AcctDropzone.vue';
import { acct } from '../acct';

export default {
  components: { AcctExport, AcctDropzone, DatePicker, AcctSelect, AdminLayout, PageHeader, Link },
  mixins: [acct],
  props: { cur: Object, r: Object, items: Array, unmatchedWims: Array, timeline: Array, history: Array, can: Object },
  data() { return { propose: false, rejecting: false, reason: '', csv: [], explanation: this.r.explanation || '', line: { entry_date: new Date().toISOString().slice(0, 10), description: '', reference: '', direction: 'in', amount: '' } }; },
  methods: {
    candidates(i) { return this.unmatchedWims.filter(u => u.direction === i.direction && Number(u.amount) === Number(i.amount)); },
    pickTxn(i, id) { this.$inertia.post(route('acct.reconciliations.match-item', [this.r.id, i.id]), { action: 'match', txn_id: Number(id) }, { preserveScroll: true }); },
    item(i, action) { this.$inertia.post(route('acct.reconciliations.match-item', [this.r.id, i.id]), { action }, { preserveScroll: true }); },
    importCsv() { this.$inertia.post(route('acct.reconciliations.import', this.r.id), { file: this.csv[0] }, { forceFormData: true, preserveScroll: true, onSuccess: () => (this.csv = []) }); },
    addLine() { this.$inertia.post(route('acct.reconciliations.items', this.r.id), this.line, { preserveScroll: true, onSuccess: () => (this.line.amount = '') }); },
    reject() { this.$inertia.post(route('acct.reconciliations.reject', this.r.id), { reason: this.reason }, { preserveScroll: true, onSuccess: () => (this.rejecting = false) }); },
  },
};
</script>

<template>
  <admin-layout :title="v.reference">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="adjustments" :title="v.reference" :subtitle="v.title">
        <Link :href="route('subcontracts.agreements.show', v.agreement_id)" class="lg-btn lg-btn-soft"><icons name="doc-text" class="w-4 h-4" /><span>{{ v.agreement }}</span></Link>
        <Link :href="route('subcontracts.variations.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Variations') }}</span></Link>
              <export-buttons type="variation_detail" :params="{ variation_id: v.id }" :sheets="false" needs-money :money="money" />
      </page-header>
      <section class="lg-card">
        <div class="ac-grid-2" style="align-items: center">
          <div><span class="lg-chip" :class="chip(v.status)">{{ v.status_label }}</span> <span class="lg-chip lg-chip-slate" style="text-transform: none">{{ v.direction === 'reduction' ? $t('Reduction') : $t('Increase') }}</span>
            <div v-if="money" class="ac-cur-total" style="margin-top: 6px" :class="v.direction === 'reduction' ? 'ac-out' : 'ac-in'">{{ v.direction === 'reduction' ? '-' : '+' }}{{ mc(v.amount, v.currency) }}</div></div>
          <div class="ac-actions" style="justify-content: flex-end; margin: 0">
            <button v-if="can.submit" type="button" class="lg-btn lg-btn-primary" @click="doit('submit')"><icons name="send" class="w-4 h-4" />{{ $t('Submit') }}</button>
            <button v-if="can.review" type="button" class="lg-btn lg-btn-soft t-teal" @click="doit('review')"><icons name="eye-flat" class="w-4 h-4" />{{ $t('Mark as reviewed') }}</button>
            <button v-if="can.approve" type="button" class="lg-btn lg-btn-green" @click="doit('approve')"><icons name="tick" class="w-4 h-4" />{{ $t('Approve') }}</button>
            <button v-if="can.reject" type="button" class="lg-btn lg-btn-red" @click="asking = true"><icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}</button>
            <button v-if="can.delete" type="button" class="lg-btn lg-btn-soft t-red" @click="remove"><icons name="trash" class="w-4 h-4" />{{ $t('Delete draft') }}</button>
          </div>
        </div>
        <form v-if="asking" class="ac-reason" @submit.prevent="doit('reject', reason)"><input v-model="reason" :placeholder="$t('Why is it rejected? (required)')" required /><button type="submit" class="lg-btn lg-btn-primary"><icons name="tick" class="w-4 h-4" />{{ $t('Confirm') }}</button><button type="button" class="lg-btn lg-btn-soft" @click="asking = false"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></form>
        <div v-if="v.status === 'Draft' || v.status === 'Submitted'" class="lg-note lg-note-blue" style="margin-top: 10px"><icons name="info" class="w-4 h-4" /><span>{{ $t('This variation changes nothing yet: the revised contract value changes only when it is approved.') }}</span></div>
        <div v-if="v.status === 'Rejected'" class="lg-note lg-note-red" style="margin-top: 10px">{{ $t('Rejected') }}: {{ v.decision_remarks }}</div>
        <div v-if="v.status === 'Approved' && figures" class="lg-note lg-note-blue" style="margin-top: 10px">{{ $t('Revised contract value now') }}: <b>{{ mc(figures.revised, v.currency) }}</b> ({{ $t('original') }} {{ m(figures.original) }}).</div>
      </section>
      <section class="lg-card"><header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Details') }}</h2></div></header>
        <dl class="ac-kv"><div><dt>{{ $t('Subcontractor') }}</dt><dd>{{ v.contact }}</dd></div><div><dt>{{ $t('Agreement') }}</dt><dd>{{ v.agreement }} - {{ v.agreement_title }}</dd></div><div><dt>{{ $t('Requested by') }}</dt><dd>{{ v.by || '-' }} <small class="lg-subtext">{{ v.created_at }}</small></dd></div><div v-if="v.effective_date"><dt>{{ $t('Effective') }}</dt><dd>{{ $date(v.effective_date) }}</dd></div><div v-if="v.approved_at"><dt>{{ $t('Approved on') }}</dt><dd>{{ v.approved_at }}</dd></div></dl>
        <dl v-if="fields.custom.length" class="ac-kv" style="margin-top: 8px"><custom-view :defs="fields.custom" :values="custom" /></dl>
        <p v-if="v.reason" class="lg-sub" style="margin-top: 8px"><b>{{ $t('Reason') }}:</b> {{ v.reason }}</p>
      </section>
      <section class="lg-card"><header class="lg-card-head"><span class="lg-card-ico lg-ico-teal"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Items of this variation') }}</h2><p class="lg-card-sub">{{ $t('Optional: with items, the amount of an increase is their total.') }}</p></div><button v-if="can.edit && v.direction === 'increase'" type="button" class="lg-btn lg-btn-soft" @click="adding = !adding"><icons name="plus" class="w-4 h-4" />{{ $t('Item') }}</button></header>
        <item-form :fields="item_fields" v-if="adding" :agreement-id="v.agreement_id" :variation-id="v.id" :units="units" :categories="categories" :pricing="pricing" @close="adding = false" />
        <div v-if="items.length > 6" class="sc-tfind"><search-bar v-model="tq" :placeholder="$t('Find an item by description, unit or beneficiary...')" :count="itemRows.length" /></div>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th>{{ $t('Description') }}</th><th>{{ $t('Unit') }}</th><th class="ac-num">{{ $t('Qty') }}</th><th v-if="money" class="ac-num"><span class="sc-th amt-rev"><icons name="cash" class="w-4 h-4" />{{ $t('Rate') }}</span></th><th v-if="money" class="ac-num"><span class="sc-th amt-appr"><icons name="cash" class="w-4 h-4" />{{ $t('Amount') }}</span></th></tr></thead>
          <tbody><tr v-for="i in itemRows" :key="i.id"><td :data-label="$t('Description')">{{ i.description }}<div v-if="i.beneficiary" class="lg-subtext">{{ i.beneficiary }}</div></td><td :data-label="$t('Unit')">{{ i.unit || '-' }}</td><td :data-label="$t('Qty')" class="ac-num">{{ i.quantity || '-' }}</td><td v-if="money" :data-label="$t('Rate')" class="ac-num"><span v-if="i.rate !== null" class="amt-rev">{{ m(i.rate) }}</span><template v-else>-</template></td><td v-if="money" :data-label="$t('Amount')" class="ac-num"><b class="amt-appr">{{ m(i.amount) }}</b></td></tr>
            <tr v-if="!items.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('No items: the amount above is used.') }}</td></tr>
            <tr v-else-if="!itemRows.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('Nothing matches your search.') }}</td></tr></tbody></table></div>
        <form v-if="can.edit && !items.length" class="sc-item-form" @submit.prevent="saveAmount"><div class="ac-filters"><div class="ac-f"><label>{{ $t('Title') }}</label><input v-model="ef.title" /></div><div v-if="money" class="ac-f"><label>{{ $t('Amount') }}</label><input v-model="ef.amount" inputmode="decimal" /><span class="ac-err">{{ err('amount') }}</span></div><div class="ac-f"><label>{{ $t('Kind') }}</label><acct-select v-model="ef.direction" :options="[{ value: 'increase', label: $t('Increase') }, { value: 'reduction', label: $t('Reduction') }]" /></div><div v-if="fon(fields, 'reason')" class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Reason') }}{{ fstar(fields, 'reason') }}</label><textarea v-model="ef.reason"></textarea><span class="ac-err">{{ err('reason') }}</span></div>
          <custom-fields v-if="fields.custom.length" v-model="ef.custom" :defs="fields.custom" :errors="ef.errors" /></div><div class="ac-actions"><button type="submit" class="lg-btn lg-btn-primary" :disabled="ef.processing"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button></div></form>
      </section>
      <section v-if="can.documents" class="lg-card"><header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Documents') }}</h2></div></header>
        <acct-file-list :files="attachments" :download-url="f => route('subcontracts.variations.download', [v.id, f.id])" :removable="can.edit" @remove="removeFile" />
        <form v-if="can.attach" class="ac-attach" @submit.prevent="upload"><acct-dropzone v-model="up.attachments" :hint="$t('PDF, images and office files up to 8 MB each.')" /><div><button type="submit" class="lg-btn lg-btn-primary" :disabled="!up.attachments.length || up.processing"><icons name="upload" class="w-4 h-4" />{{ $t('Attach :n file(s)', { n: up.attachments.length }) }}</button></div></form></section>
      <section v-if="history.length" class="lg-card"><header class="lg-card-head"><span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('History') }}</h2></div></header>
        <ul class="sc-list"><li v-for="(h, i) in history" :key="i"><b>{{ h.description }}</b> <small>{{ h.causer }} · {{ String(h.created_at).slice(0, 16) }}</small></li></ul></section>
    </div>
  </admin-layout>
</template>

<script>
import ExportButtons from '../ExportButtons.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import AcctDropzone from '@/Pages/Accounts/AcctDropzone.vue';
import AcctFileList from '@/Pages/Accounts/AcctFileList.vue';
import ItemForm from '../ItemForm.vue';
import CustomFields from '../CustomFields.vue';
import CustomView from '../CustomView.vue';
import SearchBar from '../SearchBar.vue';
import { sc, fieldCfg, tableFind } from '../sc';

export default {
  components: { SearchBar, CustomFields, CustomView, ExportButtons, AdminLayout, PageHeader, Link, AcctSelect, AcctDropzone, AcctFileList, ItemForm },
  mixins: [sc, fieldCfg, tableFind],
  props: { fields: Object, custom: Object, item_fields: Object, v: Object, items: Array, figures: Object, attachments: Array, history: Array, units: Array, categories: Array, pricing: Array, money: Boolean, can: Object },
  data() { return { asking: false, reason: '', adding: false, up: this.$inertia.form({ attachments: [] }), ef: this.$inertia.form({ custom: { ...(this.custom || {}) }, title: this.v.title, direction: this.v.direction, amount: this.v.amount || '', reason: this.v.reason || '' }) }; },
  computed: { itemRows() { return this.items.filter(i => this.has(i.description, i.unit, i.beneficiary)); } },
  methods: {
    doit(action, reason) { this.$inertia.post(route('subcontracts.variations.transition', [this.v.id, action]), { reason: reason || '' }, { preserveScroll: true }); },
    remove() { this.$confirm(this.$t('Delete this draft variation?')).then(ok => { if (ok) this.$inertia.delete(route('subcontracts.variations.destroy', this.v.id)); }); },
    saveAmount() { this.ef.put(route('subcontracts.variations.update', this.v.id), { preserveScroll: true }); },
    upload() { this.up.post(route('subcontracts.variations.attach', this.v.id), { forceFormData: true, preserveScroll: true, onSuccess: () => this.up.reset('attachments') }); },
    removeFile(f) { this.$inertia.delete(route('subcontracts.variations.remove-file', [this.v.id, f.id]), { preserveScroll: true }); },
  },
};
</script>

<style scoped>
.sc-item-form { padding: 12px; border-radius: 16px; background: rgba(148, 163, 184, 0.08); box-shadow: inset 0 0 0 1px var(--lg-line); margin: 10px 0; }
.sc-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; } .sc-list li { font-size: 0.86rem; } .sc-list small { color: var(--lg-mute); margin-left: 6px; }
</style>

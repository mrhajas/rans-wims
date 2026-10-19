<template>
  <admin-layout :title="r ? $t('Edit :r', { r: r.reference }) : $t('New Payment Request')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="send" :title="r ? $t('Edit :r', { r: r.reference }) : $t('New Payment Request')" :subtitle="$t('Saved as a draft. A request asks for money; it is paid only after approval, through a Payment Voucher.')">
        <Link :href="r ? route('subcontracts.requests.show', r.id) : route('subcontracts.requests.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
      </page-header>
      <form @submit.prevent="save">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Request') }}</h2></div></header>
          <div class="lg-fields">
            <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Agreement') }} *</label><acct-select v-model="form.agreement_id" :options="agreements" :placeholder="$t('Choose the agreement...')" :disabled="!!r" @update:model-value="pick" /><span class="ac-err">{{ err('agreement_id') }}</span></div>
            <div class="ac-f"><label>{{ $t('Request date') }} *</label><date-picker v-model="form.request_date" :clearable="false" /></div>
            <div class="ac-f"><label>{{ $t('Kind') }} *</label><acct-select v-model="form.kind" :options="kinds" /></div>
            <div v-if="!hasItems" class="ac-f"><label>{{ $t('Amount requested') }} * <span v-if="currency">({{ currency }})</span></label><input v-model="form.requested_amount" inputmode="decimal" @input="auto = false" /><span class="ac-err">{{ err('requested_amount') }}</span><small v-if="auto" class="lg-subtext">{{ $t('Filled with the balance left on this agreement. Change it to what you are asking for.') }}</small></div>
            <div v-else class="ac-f"><label>{{ $t('Amount requested') }} ({{ currency }})</label><input :value="itemsTotal" readonly class="sc-ro" /><small class="lg-subtext">{{ $t('The total of the lines below.') }}</small></div>
            <div v-if="fon(fields, 'description')" class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Description and justification') }}{{ fstar(fields, 'description') }}</label><textarea v-model="form.description" :placeholder="$t('What the request is for: the work done, the valuation or claim it comes from')"></textarea></div>
          </div>
          <div v-if="fields && fields.custom.length" class="lg-fields" style="margin-top: 8px"><custom-fields v-model="form.custom" :defs="fields.custom" :errors="form.errors" /></div>
          <div v-if="balanceWarning" class="lg-note lg-note-red sc-balance" style="margin-top: 8px" role="alert"><icons name="exclamation" class="w-4 h-4" /><span><b>{{ balanceWarning.title }}</b> {{ balanceWarning.text }}</span></div>
          <div v-if="figures" class="lg-note lg-note-blue" style="margin-top: 8px"><icons name="info" class="w-4 h-4" /><span>{{ $t('Revised contract value') }} {{ m(figures.revised) }} {{ currency }}; {{ $t('already paid or approved') }} {{ m(figures.approved_work) }}; {{ $t('still open for approval') }} <b :class="{ 'ac-neg': neg(figures.headroom) }">{{ m(figures.headroom) }}</b>.</span></div>
        </section>
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-teal"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Work items (optional)') }}</h2><p class="lg-card-sub">{{ $t('Allocate the request to work items. Leave empty to request one amount for the agreement.') }}</p></div><button type="button" class="lg-btn lg-btn-soft" @click="addLine"><icons name="plus" class="w-4 h-4" />{{ $t('Line') }}</button></header>
          <div v-for="(l, i) in form.items" :key="i" class="sc-line"><div class="ac-f"><label>{{ $t('Work item') }}</label><acct-select v-model="l.work_item_id" :options="workItems" :placeholder="$t('Any / agreement level')" clearable /></div><div class="ac-f"><label>{{ $t('Note') }}</label><input v-model="l.description" /></div><div class="ac-f"><label>{{ $t('Amount') }}</label><input v-model="l.amount" inputmode="decimal" /><span class="ac-err">{{ err('items.' + i + '.amount') }}</span></div><button type="button" class="lg-icon-btn is-red" :aria-label="$t('Remove')" @click="form.items.splice(i, 1)"><icons name="trash" class="w-4 h-4" /></button></div>
          <p v-if="!form.items.length" class="lg-sub">{{ $t('No lines: one amount for the whole request.') }}</p>
        </section>
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Supporting documents') }}</h2><p class="lg-card-sub">{{ $t('Invoices, claims, certificates or completion evidence.') }}</p></div></header>
          <acct-file-list v-if="r && attachments && attachments.length" :files="attachments" :download-url="f => route('subcontracts.requests.download', [r.id, f.id])" style="margin-bottom: 10px" />
          <acct-dropzone v-model="form.attachments" :hint="$t('PDF, images and office files up to 8 MB each.')" /><span class="ac-err">{{ err('attachments') || err('attachments.0') }}</span>
        </section>
        <div class="lg-actionbar"><span class="lg-actionbar-note">{{ $t('A request is not a payment. Submit it from its page when it is ready.') }}</span><div class="lg-actionbar-btns"><Link :href="r ? route('subcontracts.requests.show', r.id) : route('subcontracts.requests.index')" class="lg-btn t-slate"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</Link><button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing"><icons name="tick" class="w-4 h-4" />{{ form.processing ? $t('Saving...') : $t('Save') }}</button></div></div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import AcctDropzone from '@/Pages/Accounts/AcctDropzone.vue';
import AcctFileList from '@/Pages/Accounts/AcctFileList.vue';
import CustomFields from '../CustomFields.vue';
import { sc, fieldCfg } from '../sc';

export default {
  components: { CustomFields, AdminLayout, PageHeader, Link, DatePicker, AcctSelect, AcctDropzone, AcctFileList },
  mixins: [sc, fieldCfg],
  props: { fields: Object, r: Object, agreements: Array, work_items: Array, agreement_id: Number, kinds: Array, figures: Object, currency: String, money: Boolean, today: String, attachments: Array },
  data() {
    const r = this.r || {};
    // a NEW request for a chosen agreement starts with the balance that agreement still has (what is open for approval); nothing when none is left
    const left = !this.r && this.figures && parseFloat(this.figures.headroom) > 0 ? String(this.figures.headroom) : '';
    return { auto: !!left, form: this.$inertia.form({ custom: { ...(r.custom || {}) }, agreement_id: r.agreement_id || this.agreement_id || null, request_date: r.request_date || this.today, kind: r.kind || 'work', requested_amount: r.items && r.items.length ? '' : r.requested_amount || left, description: r.description || '', items: (r.items || []).map(i => ({ ...i })), attachments: [] }) };
  },
  computed: {
    workItems() { return this.work_items || []; },
    // the amount asked for against the balance left on the agreement: the excess is carried forward as a negative balance once approved
    requestedNumber() { return this.hasItems ? this.form.items.reduce((a, l) => a + (parseFloat(l.amount) || 0), 0) : parseFloat(this.form.requested_amount) || 0; },
    balanceWarning() {
      if (!this.figures) return null;
      const head = parseFloat(this.figures.headroom); const after = head - this.requestedNumber;
      const f = v => this.m(String(v.toFixed(2)), this.currency);
      // nothing typed yet but the agreement has no balance: say so straight away
      if (!(this.requestedNumber > 0)) return head <= 0 ? { title: this.$t('No balance left.'), text: this.$t('The balance of this agreement is :b. Any amount requested here is carried forward as a negative balance once it is approved.', { b: f(head) }) } : null;
      if (after >= 0) return null;
      return head <= 0
        ? { title: this.$t('No balance left.'), text: this.$t('The balance of this agreement is :b. If this request is approved, :n is carried forward as a negative balance.', { b: f(head), n: f(after) }) }
        : { title: this.$t('Not enough balance.'), text: this.$t('The balance left is :b and this request is :o more. If it is approved, the excess is carried forward as a negative balance of :n.', { b: f(head), o: f(this.requestedNumber - head), n: f(after) }) };
    },
    hasItems() { return this.form.items.length > 0; },
    itemsTotal() { return this.form.items.reduce((s, l) => s + (parseFloat(l.amount) || 0), 0).toLocaleString(undefined, { minimumFractionDigits: 2 }); },
  },
  methods: {
    addLine() { this.form.items.push({ work_item_id: null, description: '', amount: '' }); },
    pick(id) { if (id && !this.r) this.$inertia.get(route('subcontracts.requests.create', { agreement: id }), {}, { preserveState: false, preserveScroll: true }); },
    save() { const d = this.form.transform(x => ({ ...x, ...(this.r ? { _method: 'put' } : {}) })); d.post(this.r ? route('subcontracts.requests.update', this.r.id) : route('subcontracts.requests.store'), { preserveScroll: true, forceFormData: true }); },
  },
};
</script>

<style scoped>
.sc-line { display: grid; gap: 10px; grid-template-columns: 2fr 2fr 1fr auto; align-items: end; margin-bottom: 8px; } @media (max-width: 800px) { .sc-line { grid-template-columns: 1fr; } }
.sc-ro { background: rgba(148, 163, 184, 0.14) !important; cursor: default; }
</style>

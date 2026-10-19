<template>
  <admin-layout :title="a ? $t('Edit :r', { r: a.reference }) : $t('New Subcontract Agreement')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="doc-text" :title="a ? $t('Edit :r', { r: a.reference }) : $t('New Subcontract Agreement')" :subtitle="a && a.status !== 'Draft' ? $t('Only the administration details can change after the agreement left Draft.') : $t('Saved as a draft. It becomes a payable commitment only when it is approved.')">
        <Link :href="a ? route('subcontracts.agreements.show', a.id) : route('subcontracts.agreements.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
      </page-header>
      <form @submit.prevent="save">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="users" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Subcontractor and project') }}</h2></div></header>
          <div class="lg-fields">
            <div class="ac-f"><label>{{ $t('Subcontractor') }} *</label><acct-select v-model="form.contact_id" :options="contacts" :placeholder="$t('Choose...')" :disabled="locked" /><span class="ac-err">{{ err('contact_id') }}</span><small class="lg-subtext">{{ $t('Not in the list?') }} <a :href="newContactUrl" target="_blank" rel="noopener">{{ $t('Add them in Contacts (category SubContractors)') }}</a></small></div>
            <div v-if="fon(fields, 'warehouse_id')" class="ac-f"><label>{{ $t('Project(s) / Warehouse(s)') }}{{ fstar(fields, 'warehouse_id') }}</label><acct-select v-model="form.warehouse_id" :options="projects" :placeholder="$t('Choose...')" clearable :disabled="locked" /><span class="ac-err">{{ err('warehouse_id') }}</span></div>
            <div v-if="fon(fields, 'site')" class="ac-f"><label>{{ $t('Site / building / phase') }}{{ fstar(fields, 'site') }}</label><input v-model="form.site" /></div>
            <div v-if="fon(fields, 'administrator_user_id')" class="ac-f"><label>{{ $t('Administrator') }}{{ fstar(fields, 'administrator_user_id') }}</label><acct-select v-model="form.administrator_user_id" :options="staff" :placeholder="$t('Choose...')" clearable /></div>
          </div>
        </section>
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Agreement') }}</h2></div></header>
          <div class="lg-fields">
            <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Title') }} *</label><input v-model="form.title" required /><span class="ac-err">{{ err('title') }}</span></div>
            <div class="ac-f"><label>{{ $t('Type') }} *</label><acct-select v-model="form.agreement_type" :options="types" :placeholder="$t('Choose...')" :disabled="locked" /></div>
            <div class="ac-f"><label>{{ $t('Currency') }} *</label><acct-select v-model="form.currency" :options="currencies" :placeholder="$t('Choose...')" :disabled="locked" /></div>
            <div v-if="money" class="ac-f"><label>{{ $t('Original agreed amount') }} *</label><input v-model="form.original_amount" inputmode="decimal" :disabled="locked" /><span class="ac-err">{{ err('original_amount') }}</span><small class="lg-subtext">{{ $t('Kept as it is for ever. Later changes are variations.') }}</small></div>
            <div v-if="fon(fields, 'agreement_date')" class="ac-f"><label>{{ $t('Agreement date') }}{{ fstar(fields, 'agreement_date') }}</label><date-picker v-model="form.agreement_date" /></div>
            <div v-if="fon(fields, 'start_date')" class="ac-f"><label>{{ $t('Start date') }}{{ fstar(fields, 'start_date') }}</label><date-picker v-model="form.start_date" /></div>
            <div v-if="fon(fields, 'expected_completion')" class="ac-f"><label>{{ $t('Expected completion') }}{{ fstar(fields, 'expected_completion') }}</label><date-picker v-model="form.expected_completion" /><span class="ac-err">{{ err('expected_completion') }}</span></div>
            <div v-if="fon(fields, 'scope')" class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Scope of work') }}{{ fstar(fields, 'scope') }}</label><textarea v-model="form.scope"></textarea></div>
          </div>
        </section>
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="cash" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Payment terms') }}</h2></div></header>
          <div class="lg-fields">
            <div v-if="fon(fields, 'payment_terms')" class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Payment terms') }}{{ fstar(fields, 'payment_terms') }}</label><textarea v-model="form.payment_terms"></textarea></div>
            <div v-if="fon(fields, 'advance_terms')" class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Advance payment terms') }}{{ fstar(fields, 'advance_terms') }}</label><textarea v-model="form.advance_terms"></textarea></div>
            <div v-if="fon(fields, 'retention_percent')" class="ac-f"><label>{{ $t('Retention %') }}{{ fstar(fields, 'retention_percent') }}</label><input v-model="form.retention_percent" inputmode="decimal" :disabled="locked" /><small class="lg-subtext">{{ $t('Recorded as a term. WIMS does not deduct it automatically.') }}</small></div>
            <div v-if="fon(fields, 'vat_treatment')" class="ac-f"><label>{{ $t('VAT / tax treatment') }}{{ fstar(fields, 'vat_treatment') }}</label><input v-model="form.vat_treatment" :placeholder="$t('e.g. VAT inclusive')" /></div>
            <div v-if="fon(fields, 'remarks')" class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Remarks') }}{{ fstar(fields, 'remarks') }}</label><textarea v-model="form.remarks"></textarea></div>
          </div>
        </section>
        <section v-if="fields && fields.custom.length" class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="adjustments" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('More details') }}</h2></div></header>
          <div class="lg-fields"><custom-fields v-model="form.custom" :defs="fields.custom" :errors="form.errors" /></div>
        </section>
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Documents') }}</h2><p class="lg-card-sub">{{ $t('The signed agreement, scope of work, bills of quantities (optional).') }}</p></div></header>
          <acct-file-list v-if="a && attachments && attachments.length" :files="attachments" :download-url="f => route('subcontracts.agreements.download', [a.id, f.id])" style="margin-bottom: 10px" />
          <acct-dropzone v-model="form.attachments" :hint="$t('PDF, images and office files up to 8 MB each.')" />
          <span class="ac-err">{{ err('attachments') || err('attachments.0') }}</span>
        </section>
        <div class="lg-actionbar"><span class="lg-actionbar-note">{{ $t('Saved as a draft: nothing can be paid until the agreement is approved.') }}</span>
          <div class="lg-actionbar-btns"><Link :href="a ? route('subcontracts.agreements.show', a.id) : route('subcontracts.agreements.index')" class="lg-btn t-slate"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</Link><button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing"><icons name="tick" class="w-4 h-4" />{{ form.processing ? $t('Saving...') : $t('Save') }}</button></div></div>
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
  props: { fields: Object, a: Object, contacts: Array, projects: Array, currencies: Array, types: Array, staff: Array, newContactUrl: String, default_retention: String, money: Boolean, attachments: Array, today: String, preset: Object },
  data() {
    const a = this.a || {};
    return { form: this.$inertia.form({ custom: { ...(a.custom || {}) }, contact_id: a.contact_id || (this.preset && this.preset.contact_id) || null, warehouse_id: a.warehouse_id || null, site: a.site || '', title: a.title || '', scope: a.scope || '', agreement_type: a.agreement_type || 'quantity', currency: a.currency || 'TZS', original_amount: a.original_amount || '',
      agreement_date: this.a ? (a.agreement_date || '') : this.today, start_date: a.start_date || '', expected_completion: a.expected_completion || '', administrator_user_id: a.administrator_user_id || null, payment_terms: a.payment_terms || '', advance_terms: a.advance_terms || '', retention_percent: a.retention_percent !== undefined ? a.retention_percent : this.default_retention || '0', vat_treatment: a.vat_treatment || '', remarks: a.remarks || '', attachments: [] }) };
  },
  computed: { locked() { return !!this.a && this.a.status !== 'Draft'; } },
  methods: {
    save() {
      const d = this.form.transform(x => ({ ...x, ...(this.a ? { _method: 'put' } : {}) }));
      d.post(this.a ? route('subcontracts.agreements.update', this.a.id) : route('subcontracts.agreements.store'), { preserveScroll: true, forceFormData: true });
    },
  },
};
</script>

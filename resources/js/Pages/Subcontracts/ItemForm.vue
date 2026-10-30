<template>
  <form class="sc-item-form" @submit.prevent="save">
    <div class="ac-filters">
      <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Work description') }} <b class="wims-req">*</b></label><textarea v-model="form.description" rows="2" required></textarea><span class="ac-err">{{ err('description') }}</span></div>
      <div v-if="groups && groups.length" class="ac-f"><label>{{ $t('Group / location') }}</label><acct-select v-model="form.group_id" :options="groupOptions" :placeholder="$t('No group')" clearable /><span class="ac-err">{{ err('group_id') }}</span></div>
      <div v-if="fon(fields, 'category')" class="ac-f"><label>{{ $t('Work category') }}{{ fstar(fields, 'category') }}</label><input v-model="form.category" list="sc-cats" /><datalist id="sc-cats"><option v-for="c in categories" :key="c" :value="c" /></datalist></div>
      <div v-if="fon(fields, 'beneficiary_name')" class="ac-f"><label>{{ $t('Beneficiary') }}{{ fstar(fields, 'beneficiary_name') }}</label><input v-model="form.beneficiary_name" :placeholder="$t('Who the work is given to')" /></div>
      <div class="ac-f"><label>{{ $t('Pricing') }} <b class="wims-req">*</b></label><acct-select v-model="form.pricing_method" :options="pricing" :placeholder="$t('Choose...')" /></div>
      <div v-if="fon(fields, 'unit')" class="ac-f"><label>{{ $t('Unit') }}{{ fstar(fields, 'unit') }}</label><input v-model="form.unit" list="sc-units" /><datalist id="sc-units"><option v-for="u in units" :key="u" :value="u" /></datalist></div>
      <template v-if="form.pricing_method === 'qty_rate'">
        <div class="ac-f"><label>{{ $t('Quantity') }} <b class="wims-req">*</b></label><input v-model="form.quantity" inputmode="decimal" /><span class="ac-err">{{ err('quantity') }}</span></div>
        <div class="ac-f"><label>{{ $t('Rate') }} <b class="wims-req">*</b></label><input v-model="form.rate" inputmode="decimal" /><span class="ac-err">{{ err('rate') }}</span></div>
        <div class="ac-f"><label>{{ $t('Amount (calculated)') }}</label><input :value="preview" readonly class="sc-ro" /></div>
      </template>
      <div v-else class="ac-f"><label>{{ form.pricing_method === 'fixed' ? $t('Fixed amount') : $t('Lump sum') }} <b class="wims-req">*</b></label><input v-model="form.amount" inputmode="decimal" /><span class="ac-err">{{ err('amount') }}</span></div>
      <div v-if="fon(fields, 'remarks')" class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Remarks') }}{{ fstar(fields, 'remarks') }}</label><input v-model="form.remarks" /></div>
      <custom-fields v-if="fields && fields.custom.length" v-model="form.custom" :defs="fields.custom" :errors="form.errors" />
    </div>
    <div class="ac-actions"><button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing"><icons name="tick" class="w-4 h-4" />{{ item ? $t('Save item') : $t('Add item') }}</button><button type="button" class="lg-btn t-slate" @click="$emit('close')"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></div>
  </form>
</template>

<script>
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import CustomFields from './CustomFields.vue';
import { sc, fieldCfg } from './sc';

export default {
  components: { AcctSelect, CustomFields },
  mixins: [sc, fieldCfg],
  props: { fields: { type: Object, default: null }, agreementId: Number, variationId: { type: Number, default: null }, item: { type: Object, default: null }, groups: { type: Array, default: () => [] }, units: Array, categories: Array, pricing: Array, defaultGroup: { type: Number, default: null } },
  emits: ['close'],
  data() {
    const i = this.item || {};
    return { form: this.$inertia.form({ custom: { ...(i.custom || {}) }, group_id: i.group_id || this.defaultGroup || null, variation_id: this.variationId, description: i.description || '', category: i.category || '', beneficiary_name: i.beneficiary || '', pricing_method: i.pricing_method || 'qty_rate', unit: i.unit || '', quantity: i.quantity || '', rate: i.rate || '', amount: i.pricing_method && i.pricing_method !== 'qty_rate' ? i.amount : '', remarks: i.remarks || '' }) };
  },
  computed: {
    groupOptions() { return this.groups.map(g => ({ value: g.id, label: g.name })); },
    preview() { const q = parseFloat(this.form.quantity), r = parseFloat(this.form.rate); return isNaN(q) || isNaN(r) ? '' : (Math.round(q * r * 100) / 100).toLocaleString(undefined, { minimumFractionDigits: 2 }); },
  },
  methods: {
    save() {
      const url = this.item ? route('subcontracts.agreements.items.update', [this.agreementId, this.item.id]) : route('subcontracts.agreements.items.store', this.agreementId);
      const opts = { preserveScroll: true, onSuccess: () => this.$emit('close') };
      this.item ? this.form.put(url, opts) : this.form.post(url, opts);
    },
  },
};
</script>

<style scoped>
.sc-item-form { padding: 12px; border-radius: 16px; background: rgba(148, 163, 184, 0.08); box-shadow: inset 0 0 0 1px var(--lg-line); margin: 10px 0; }
.sc-ro { background: rgba(148, 163, 184, 0.14) !important; cursor: default; }
</style>

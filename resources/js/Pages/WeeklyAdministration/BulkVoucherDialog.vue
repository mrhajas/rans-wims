<template>
  <travel-modal :show="show" :title="$t('Vouchers for each Beneficiary')" :subtitle="doc ? doc.reference : ''" icon="collection" tone="amber" :busy="busy" max-width="xl" @close="$emit('close')">
    <form id="wa-bulk" class="wa-ui" @submit.prevent="go">
      <p class="lg-sub" style="margin: 0 0 10px">{{ $t('One Payment Voucher is made for each beneficiary, for exactly the amount of their lines. They are sent for approval at once and go into one Payment Voucher Batch, so approving the batch approves every voucher together.') }}</p>
      <div class="wa-paper"><table>
        <thead><tr><th class="no">{{ $t('No') }}</th><th>{{ $t('Beneficiary') }}</th><th class="num">{{ $t('Lines') }}</th><th class="num">{{ $t('Voucher amount') }} ({{ doc ? doc.currency : '' }})</th></tr></thead>
        <tbody><tr v-for="(g, i) in groups" :key="g.name"><td class="no">{{ i + 1 }}</td><td>{{ g.name }}</td><td class="num">{{ g.lines }}</td><td class="num tr-m">{{ money(g.total) }}</td></tr></tbody>
        <tfoot><tr><td colspan="2" style="text-align: right">{{ groups.length }} {{ $t('vouchers') }}</td><td class="num">{{ groups.reduce((s, g) => s + g.lines, 0) }}</td><td class="num tr-tm">{{ money(groups.reduce((s, g) => s + g.total, 0)) }}</td></tr></tfoot>
      </table></div>
      <div class="ac-f" style="margin-top: 12px"><label>{{ $t('Notes') }}</label><input v-model="notes" maxlength="1000" :placeholder="$t('Optional')" /></div>
      <span class="ac-err">{{ err('lines') }}</span>
      <p class="wa-note"><icons name="info" class="w-4 h-4" />{{ $t('The amounts are held for the vouchers. Nothing counts as paid until each voucher is approved, authorized and marked paid in the Payment Vouchers module.') }}</p>
    </form>
    <template #footer>
      <button type="button" class="lg-btn t-slate" :disabled="busy" @click="$emit('close')">{{ $t('Cancel') }}</button>
      <button type="submit" form="wa-bulk" class="lg-btn lg-btn-primary" :disabled="busy || !groups.length"><icons name="doc-text" class="w-4 h-4" />{{ busy ? $t('Working...') : $t('Make :n vouchers', { n: groups.length }) }}</button>
    </template>
  </travel-modal>
</template>

<script>
import TravelModal from '../Travels/TravelModal.vue';

export default {
  components: { TravelModal },
  props: { show: Boolean, doc: Object, mode: String, rows: { type: Array, default: () => [] }, amounts: { type: Object, default: () => ({}) } },
  emits: ['close'],
  data() { return { busy: false, notes: '' }; },
  watch: { show(v) { if (v) this.notes = ''; } },
  computed: {
    // the same grouping the server does: by the beneficiary of each line (a line with none belongs to the requester of the document)
    groups() {
      const by = {};
      const chosen = this.mode === 'all' ? this.rows : this.rows.filter(r => this.amounts[r.key] !== undefined);
      chosen.forEach(r => {
        const amt = this.mode === 'all' ? Number(r.balance) : parseFloat(this.amounts[r.key]) || 0;
        if (amt <= 0) return;
        const name = ((r.beneficiary || '').trim() || (this.doc && this.doc.beneficiary) || '').replace(/\s+/g, ' ');
        const k = name.toLowerCase();
        by[k] = by[k] || { name, lines: 0, total: 0 };
        by[k].lines++; by[k].total = Math.round((by[k].total + amt) * 100) / 100;
      });
      return Object.values(by);
    },
  },
  methods: {
    err(k) { return (this.$page.props.errors || {})[k]; },
    money(v) { return Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    go() {
      this.busy = true;
      const lines = this.mode === 'all' ? [] : this.rows.filter(r => this.amounts[r.key] !== undefined).map(r => ({ key: r.key, amount: this.amounts[r.key] }));
      this.$inertia.post(route('weekly-administration.payments.vouchers', this.doc.id), { mode: this.mode, lines, notes: this.notes }, { onSuccess: () => this.$emit('close'), onFinish: () => (this.busy = false) });
    },
  },
};
</script>

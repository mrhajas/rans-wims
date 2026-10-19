<template>
  <teleport to="body">
    <div class="tlm-overlay" @click.self="$emit('close')">
      <form class="tlm-card" role="dialog" aria-modal="true" @submit.prevent="submit">
        <header class="tlm-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h3 class="lg-card-title">{{ $t('Pay personnel') }}</h3>
            <p class="lg-card-sub">{{ worker.name }} · {{ $t('available to pay') }} <b>{{ $money(available) }}</b></p>
            <p v-if="worker.pending > 0" class="lg-card-sub tlm-pending">{{ $money(worker.pending) }} {{ $t('is already on a Payment Voucher waiting to be paid.') }}</p>
          </div>
        </header>

        <div class="tlm-body">
          <div v-if="can.voucher && can.pay" class="tlm-mode" role="group" :aria-label="$t('How to pay')">
            <button type="button" :class="{ 'is-on': mode === 'voucher' }" :aria-pressed="mode === 'voucher'" @click="mode = 'voucher'"><icons name="doc-text" class="w-4 h-4" />{{ $t('Payment Voucher') }}</button>
            <button type="button" :class="{ 'is-on': mode === 'direct' }" :aria-pressed="mode === 'direct'" @click="mode = 'direct'"><icons name="cash" class="w-4 h-4" />{{ $t('Direct payment') }}</button>
          </div>

          <p class="tlm-hint">
            <template v-if="mode === 'voucher'">{{ $t('Generates a Payment Voucher for approval. When it is recorded as paid, the payment appears in the personnel ledger and the balance goes down.') }}</template>
            <template v-else>{{ $t('Records money paid now (cash, bank or mobile). The balance goes down straight away.') }}</template>
          </p>

          <div class="tlm-grid">
            <div class="tlm-field">
              <label for="tlm_amount">{{ $t('Amount') }}</label>
              <input id="tlm_amount" v-model="form.amount" type="number" min="0" step="0.01" class="tlm-input" autofocus />
              <p v-if="errors.amount" class="tlm-err">{{ errors.amount }}</p>
            </div>
            <div v-if="mode === 'direct'" class="tlm-field">
              <label>{{ $t('Payment date') }}</label>
              <date-picker v-model="form.payment_date" :max="today" />
              <p v-if="errors.payment_date" class="tlm-err">{{ errors.payment_date }}</p>
            </div>
            <div v-if="mode === 'direct'" class="tlm-field">
              <label>{{ $t('Method') }}</label>
              <auto-complete id="tl_pay_method" v-model="form.method" :suggestions="methods" freeform clearable :placeholder="$t('Cash, bank, mobile money…')" />
            </div>
            <div v-if="mode === 'direct'" class="tlm-field">
              <label for="tlm_ref">{{ $t('Reference (optional)') }}</label>
              <input id="tlm_ref" v-model="form.reference" type="text" maxlength="100" class="tlm-input" />
            </div>
            <div class="tlm-field tlm-wide">
              <label for="tlm_notes">{{ $t('Notes (optional)') }}</label>
              <input id="tlm_notes" v-model="form.notes" type="text" maxlength="500" class="tlm-input" />
            </div>
          </div>

          <label v-if="mode === 'direct' && exceeds" class="tlm-check"><input v-model="form.allow_advance" type="checkbox" /> {{ $t('Pay as advance (more than the balance available)') }}</label>
          <p v-if="errors.contact_id" class="tlm-err">{{ errors.contact_id }}</p>
        </div>

        <footer class="tlm-foot">
          <button type="button" class="lg-btn lg-btn-soft" @click="$emit('close')"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button>
          <button type="submit" class="lg-btn lg-btn-green" :disabled="processing || !(Number(form.amount) > 0)">
            <icons :name="processing ? 'refresh' : mode === 'voucher' ? 'doc-text' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': processing }" />
            {{ mode === 'voucher' ? $t('Generate voucher') : $t('Save payment') }}
          </button>
        </footer>
      </form>
    </div>
  </teleport>
</template>

<script>
import { router } from '@inertiajs/vue3';
import DatePicker from '@/Shared/DatePicker.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';

export default {
  components: { DatePicker, AutoComplete },
  props: { worker: Object, can: { type: Object, default: () => ({ voucher: false, pay: true }) } },
  emits: ['close'],
  data() {
    const available = this.worker.available ?? this.worker.balance;
    return {
      today: new Date().toLocaleDateString('en-CA'),
      processing: false,
      methods: ['Cash', 'Bank transfer', 'Mobile money'],
      mode: this.can.voucher ? 'voucher' : 'direct',
      form: { contact_id: this.worker.contact_id, amount: available > 0 ? available : '', payment_date: new Date().toLocaleDateString('en-CA'), method: 'Cash', reference: '', notes: '', allow_advance: false },
    };
  },
  computed: {
    errors() { return this.$page.props.errors || {}; },
    available() { return this.worker.available ?? this.worker.balance; },
    exceeds() { return Number(this.form.amount) > Number(this.available) + 0.001; },
  },
  methods: {
    submit() {
      this.processing = true;
      const opts = { preserveScroll: true, onSuccess: () => this.$emit('close'), onFinish: () => (this.processing = false) };
      if (this.mode === 'voucher') {
        router.post(this.route('transport-loading.vouchers.store'), { contact_id: this.form.contact_id, amount: this.form.amount, notes: this.form.notes }, opts);
      } else {
        router.post(this.route('transport-loading.payments.store'), this.form, opts);
      }
    },
  },
};
</script>

<style scoped>
.tlm-overlay { position: fixed; inset: 0; z-index: 9998; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(15, 23, 42, 0.55); backdrop-filter: blur(3px); }
.tlm-card { width: 100%; max-width: 34rem; max-height: 92vh; overflow: auto; border-radius: 20px; background: var(--lg-card, #fff); box-shadow: 0 30px 60px -20px rgba(15, 23, 42, 0.6); }
.tlm-head { display: flex; gap: 12px; align-items: center; padding: 16px 18px; border-bottom: 1px solid var(--lg-line, #e3e8f0); }
.tlm-pending { color: #b45309; }
.tlm-body { display: grid; gap: 14px; padding: 16px 18px; }
.tlm-mode { display: grid; grid-template-columns: 1fr 1fr; border-radius: 14px; overflow: hidden; border: 1px solid var(--lg-line, #e3e8f0); box-shadow: 0 3px 8px -4px rgba(15, 23, 42, 0.3); }
.tlm-mode button { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 10px 12px; font-weight: 800; font-size: 0.85rem; color: #64748b; background: var(--lg-card, #fff); min-height: 44px; }
.tlm-mode button.is-on { color: #fff; background: linear-gradient(135deg, #4f46e5, #7c3aed); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.2); }
.tlm-hint { margin: 0; padding: 8px 12px; border-radius: 12px; font-size: 0.8rem; line-height: 1.4; color: #475569; background: var(--lg-soft, #f4f6fb); }
/* Every field is one column wide and the same height, so labels and boxes line up in straight rows */
.tlm-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 14px; align-items: start; }
.tlm-wide { grid-column: 1 / -1; }
.tlm-field { min-width: 0; }
.tlm-field > label { display: block; margin-bottom: 4px; min-height: 1rem; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: var(--lg-mute, #64748b); }
.tlm-input { display: block; width: 100%; height: 43px; padding: 8px 12px; border-radius: 12px; border: 1px solid var(--lg-line, #e3e8f0); background: var(--lg-card, #fff); box-shadow: 0 3px 8px -4px rgba(15, 23, 42, 0.28); }
.tlm-input:focus { outline: none; border-color: #818cf8; box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.25); }
/* The shared pickers bring their own wrapper (width fractions, bottom margin): make them fill the cell */
.tlm-field :deep(.wdp-root), .tlm-field :deep(.col-span-6) { width: 100%; margin: 0; }
.tlm-field :deep(.relative.mb-2) { margin-bottom: 0; }
.tlm-field :deep(.wdp-trigger) { height: 43px; min-height: 43px; }
.tlm-field :deep(.wdp-input) { padding-top: 0 !important; padding-bottom: 0 !important; }
.tlm-field :deep(input[autocomplete="off"]) { min-height: 43px; }
.tlm-check { display: flex; gap: 8px; align-items: center; font-size: 0.84rem; font-weight: 600; color: #b45309; }
.tlm-err { color: #e11d48; font-size: 0.8rem; margin: 2px 0 0; }
.tlm-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 18px; border-top: 1px solid var(--lg-line, #e3e8f0); }
html.dark .tlm-card { background: #111a2e; }
html.dark .tlm-input { background: rgba(15, 23, 42, 0.6); }
@media (max-width: 520px) { .tlm-grid { grid-template-columns: 1fr; } }
</style>

<template>
  <teleport to="body">
    <div class="tlb-overlay" @click.self="$emit('close')">
      <form class="tlb-card" role="dialog" aria-modal="true" @submit.prevent="submit">
        <header class="tlb-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h3 class="lg-card-title">{{ $t('Generate Payment Vouchers') }}</h3>
            <p class="lg-card-sub">{{ $t('One separate voucher is made for each person below. Each amount starts at the person\'s available balance and can be changed.') }}</p>
          </div>
        </header>

        <div class="tlb-body">
          <div class="lg-table-wrap">
            <table class="lg-table lg-responsive">
              <thead>
                <tr>
                  <th class="w-12">{{ $t('No') }}</th>
                  <th>{{ $t('Personnel') }}</th>
                  <th class="num">{{ $t('Balance') }}</th>
                  <th class="num">{{ $t('Already on a voucher') }}</th>
                  <th class="num">{{ $t('Available') }}</th>
                  <th class="tlb-amount-col">{{ $t('Amount to pay') }}</th>
                  <th class="num">{{ $t('Left after') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(r, i) in items" :key="r.contact_id">
                  <td :data-label="$t('No')" class="tlb-no">{{ i + 1 }}</td>
                  <td :data-label="$t('Personnel')"><span class="lg-main">{{ r.name }}</span></td>
                  <td :data-label="$t('Balance')" class="num">{{ $money(r.balance) }}</td>
                  <td :data-label="$t('Already on a voucher')" class="num">{{ r.pending ? $money(r.pending) : '-' }}</td>
                  <td :data-label="$t('Available')" class="num"><b>{{ $money(r.available) }}</b></td>
                  <td :data-label="$t('Amount to pay')" class="tlb-amount-col">
                    <input v-model.number="amounts[r.contact_id]" type="number" min="0" step="0.01" class="tlb-input" :class="{ 'is-bad': bad(r) }" :aria-label="$t('Amount to pay') + ' ' + r.name" @focus="$event.target.select()" />
                    <p v-if="errorFor(i)" class="tlb-err">{{ errorFor(i) }}</p>
                  </td>
                  <td :data-label="$t('Left after')" class="num"><span :class="{ 'tlb-neg': left(r) < 0 }">{{ $money(left(r)) }}</span></td>
                </tr>
              </tbody>
              <tfoot>
                <tr class="tlb-foot">
                  <td></td><td><b>{{ $t('Total') }} ({{ items.length }} {{ $t('vouchers') }})</b></td>
                  <td class="num"><b>{{ $money(sum('balance')) }}</b></td><td class="num"><b>{{ $money(sum('pending')) }}</b></td><td class="num"><b>{{ $money(sum('available')) }}</b></td>
                  <td class="tlb-amount-col"><b>{{ $money(total) }}</b></td><td class="num"><b>{{ $money(sum('available') - total) }}</b></td>
                </tr>
              </tfoot>
            </table>
          </div>

          <div class="tlb-field">
            <label for="tlb_notes">{{ $t('Note on every voucher (optional)') }}</label>
            <input id="tlb_notes" v-model="notes" type="text" maxlength="500" class="tlb-input" />
          </div>
        </div>

        <footer class="tlb-foot-bar">
          <button type="button" class="lg-btn lg-btn-soft" @click="$emit('close')"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button>
          <button type="submit" class="lg-btn lg-btn-green" :disabled="processing || hasBad || !items.length">
            <icons :name="processing ? 'refresh' : 'doc-text'" class="w-4 h-4" :class="{ 'animate-spin': processing }" />{{ $t('Generate') }} {{ items.length }} {{ $t('separate vouchers') }}
          </button>
        </footer>
      </form>
    </div>
  </teleport>
</template>

<script>
import { router } from '@inertiajs/vue3';

export default {
  props: { items: { type: Array, required: true } },
  emits: ['close', 'done'],
  data() {
    return {
      processing: false,
      notes: '',
      // by default each person is paid their whole available balance; every amount can be changed
      amounts: Object.fromEntries(this.items.map(r => [r.contact_id, Number(r.available)])),
    };
  },
  computed: {
    total() { return this.items.reduce((s, r) => s + (Number(this.amounts[r.contact_id]) || 0), 0); },
    hasBad() { return this.items.some(r => this.bad(r)); },
    errors() { return this.$page.props.errors || {}; },
  },
  methods: {
    sum(key) { return this.items.reduce((s, r) => s + Number(r[key] || 0), 0); },
    left(r) { return Number(r.available) - (Number(this.amounts[r.contact_id]) || 0); },
    bad(r) { const a = Number(this.amounts[r.contact_id]); return !(a > 0) || a > Number(r.available) + 0.001; },
    errorFor(i) { return this.errors['items.' + i + '.amount']; },
    submit() {
      this.processing = true;
      router.post(this.route('transport-loading.vouchers.bulk'), {
        items: this.items.map(r => ({ contact_id: r.contact_id, amount: Number(this.amounts[r.contact_id]) })),
        notes: this.notes || null,
      }, {
        preserveScroll: true,
        onSuccess: () => this.$emit('done'),
        onFinish: () => (this.processing = false),
      });
    },
  },
};
</script>

<style scoped>
.tlb-overlay { position: fixed; inset: 0; z-index: 9998; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(15, 23, 42, 0.55); backdrop-filter: blur(3px); }
.tlb-card { width: 100%; max-width: 62rem; max-height: 92vh; display: flex; flex-direction: column; border-radius: 20px; background: var(--lg-card, #fff); box-shadow: 0 30px 60px -20px rgba(15, 23, 42, 0.6); }
.tlb-head { display: flex; gap: 12px; align-items: center; padding: 16px 18px; border-bottom: 1px solid var(--lg-line, #e3e8f0); }
.tlb-body { display: grid; gap: 14px; padding: 16px 18px; overflow: auto; }
.tlb-no { width: 1%; font-weight: 800; color: var(--lg-faint, #94a3b8); }
.tlb-amount-col { min-width: 150px; }
.tlb-input { display: block; width: 100%; height: 40px; padding: 6px 10px; border-radius: 10px; border: 1px solid var(--lg-line, #e3e8f0); background: var(--lg-card, #fff); font-weight: 800; font-variant-numeric: tabular-nums; box-shadow: 0 3px 8px -4px rgba(15, 23, 42, 0.28); }
.tlb-input:focus { outline: none; border-color: #818cf8; box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.25); }
.tlb-input.is-bad { border-color: #f43f5e; box-shadow: 0 0 0 3px rgba(244, 63, 94, 0.2); }
.tlb-err { color: #e11d48; font-size: 0.74rem; margin: 3px 0 0; }
.tlb-neg { color: #e11d48; font-weight: 800; }
.tlb-field label { display: block; margin-bottom: 4px; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: var(--lg-mute, #64748b); }
.tlb-foot td { border-top: 2px solid var(--lg-line, #e3e8f0); background: var(--lg-soft, #f4f6fb); }
.tlb-foot-bar { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 18px; border-top: 1px solid var(--lg-line, #e3e8f0); }
html.dark .tlb-card { background: #111a2e; }
html.dark .tlb-input { background: rgba(15, 23, 42, 0.6); }
</style>

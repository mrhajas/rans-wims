<template>
  <div class="hg-auto">
    <label class="hg-check hg-auto-switch"><input :checked="modelValue.enabled" type="checkbox" @change="set({ enabled: $event.target.checked })" /> {{ $t('Schedule the greetings to be sent automatically') }}</label>
    <div v-if="modelValue.enabled" class="hg-auto-body">
      <div class="hg-field">
        <label>{{ $t('How long before the holiday begins') }}</label>
        <div class="hg-chips hg-auto-presets">
          <button v-for="p in presets" :key="p.m" type="button" :class="{ 'is-on': modelValue.minutes === p.m }" @click="set({ minutes: p.m })">{{ p.label }}</button>
        </div>
        <div class="hg-auto-custom">
          <input :value="amount" type="number" min="0" :max="maxAmount" @input="setAmount($event.target.value)" />
          <simple-autocomplete :model-value="unit" :options="units" :clearable="false" class="hg-auto-unit" @update:model-value="setUnit" />
          <span class="hg-hint">{{ $t('before the holiday begins') }}</span>
        </div>
        <span class="hg-hint">{{ $t('A holiday begins at midnight (00:00). Use 0 to send at that moment. At most 7 days before.') }}</span>
        <span class="ac-err">{{ errors.minutes || errors.auto_minutes }}</span>
      </div>
      <div class="hg-field">
        <label>{{ $t('Send it to') }}</label>
        <simple-autocomplete :model-value="modelValue.bundle_id || 0" :options="bundleOptions" icon="collection" :clearable="false" @update:model-value="set({ bundle_id: Number($event) || 0 })" />
        <span class="ac-err">{{ errors.bundle_id || errors.auto_bundle }}</span>
      </div>
      <div class="hg-auto-when"><icons name="clock" class="w-4 h-4" /><span v-if="when">{{ $t('It will be sent on :t.', { t: when }) }}</span><span v-else>{{ $t(':t before each holiday begins.', { t: human(modelValue.minutes) }) }}</span></div>
    </div>
  </div>
</template>

<script>
import SimpleAutocomplete from '@/Shared/SimpleAutocomplete.vue';

const MAX = 10080;

export default {
  components: { SimpleAutocomplete },
  props: { modelValue: Object, bundles: { type: Array, default: () => [] }, date: { type: String, default: '' }, errors: { type: Object, default: () => ({}) } },
  emits: ['update:modelValue'],
  computed: {
    presets() { return [0, 10, 30, 60, 180, 480, 1440, 2880].map(m => ({ m, label: m === 0 ? this.$t('At the start of the holiday') : this.human(m) + ' ' + this.$t('before') })); },
    units() { return [{ value: 'minutes', label: this.$t('minutes') }, { value: 'hours', label: this.$t('hours') }, { value: 'days', label: this.$t('days') }]; },
    bundleOptions() { return [{ value: 0, label: this.$t('The whole greeting list') }, ...this.bundles.map(b => ({ value: b.id, label: b.name }))]; },
    unit() { const m = this.modelValue.minutes; return m > 0 && m % 1440 === 0 ? 'days' : m > 0 && m % 60 === 0 ? 'hours' : 'minutes'; },
    amount() { const m = this.modelValue.minutes; return this.unit === 'days' ? m / 1440 : this.unit === 'hours' ? m / 60 : m; },
    maxAmount() { return this.unit === 'days' ? 7 : this.unit === 'hours' ? 168 : MAX; },
    when() {
      if (!this.date) return '';
      const [y, mo, d] = this.date.split('-').map(Number);
      const t = new Date(Date.UTC(y, mo - 1, d) - this.modelValue.minutes * 60000);
      const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], z = n => String(n).padStart(2, '0');
      return days[t.getUTCDay()] + ' ' + t.getUTCDate() + ' ' + months[t.getUTCMonth()] + ' ' + t.getUTCFullYear() + ', ' + z(t.getUTCHours()) + ':' + z(t.getUTCMinutes());
    },
  },
  methods: {
    set(patch) { this.$emit('update:modelValue', { ...this.modelValue, ...patch }); },
    mult(u) { return u === 'days' ? 1440 : u === 'hours' ? 60 : 1; },
    setAmount(v) { const n = Math.max(0, Math.floor(Number(v) || 0)); this.set({ minutes: Math.min(MAX, n * this.mult(this.unit)) }); },
    setUnit(u) { this.set({ minutes: Math.min(MAX, this.amount * this.mult(u)) }); },
    human(m) {
      if (m === 0) return this.$t('At the start of the holiday');
      if (m % 1440 === 0) { const n = m / 1440; return n + ' ' + (n === 1 ? this.$t('day') : this.$t('days')); }
      if (m % 60 === 0) { const n = m / 60; return n + ' ' + (n === 1 ? this.$t('hour') : this.$t('hours')); }
      return m + ' ' + this.$t('minutes');
    },
  },
};
</script>

<style>
.hg-auto { display: grid; gap: 12px; }
.hg-auto-body { display: grid; gap: 14px; padding: 14px; border-radius: 16px; background: var(--lg-card); box-shadow: inset 0 0 0 1px var(--lg-line); }
.hg-auto-custom { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 6px; }
.hg-auto-custom > input { width: 90px; }
.hg-auto-unit { width: 150px; }
.hg-auto-presets button.is-on { background: #be185d; color: #fff; box-shadow: 0 6px 14px -6px rgba(190, 24, 93, 0.6); }
.hg-auto-when { display: flex; align-items: center; gap: 8px; padding: 10px 14px; border-radius: 12px; background: rgba(5, 150, 105, 0.1); color: #047857; font-weight: 700; font-size: 0.9rem; }
.hg-auto .hg-check { display: flex; align-items: center; gap: 8px; margin: 4px 0; font-weight: 600; }
.hg-auto .hg-check input { width: 18px; height: 18px; flex: none; padding: 0; }
.hg-auto .hg-field { margin: 0; }
</style>

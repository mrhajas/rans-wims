<template>
  <input
    ref="el"
    type="text"
    inputmode="decimal"
    autocomplete="off"
    class="fx-amount-input"
    :class="{ 'is-error': error }"
    :value="display"
    :disabled="disabled"
    :readonly="readonly"
    :placeholder="placeholder"
    :aria-label="ariaLabel || placeholder"
    @focus="onFocus"
    @input="onInput"
    @blur="onBlur"
  />
</template>

<script>
// Money input: thousands separators while idle, plain digits while typing, emits a Number (or null when empty).
export default {
  props: {
    modelValue: { type: [Number, String], default: null },
    placeholder: { type: String, default: '0' },
    ariaLabel: { type: String, default: '' },
    decimals: { type: Number, default: 2 },
    disabled: Boolean,
    readonly: Boolean,
    error: Boolean,
  },
  emits: ['update:modelValue'],
  data() {
    return { focused: false, raw: '' };
  },
  computed: {
    display() {
      if (this.focused) return this.raw;
      if (this.modelValue === null || this.modelValue === '' || isNaN(Number(this.modelValue))) return '';
      return Number(this.modelValue).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: this.decimals });
    },
  },
  methods: {
    onFocus(e) {
      this.raw = this.modelValue === null || this.modelValue === '' ? '' : String(this.modelValue);
      this.focused = true;
      this.$nextTick(() => e.target && e.target.select && e.target.select());
    },
    onInput(e) {
      const cleaned = e.target.value.replace(/,/g, '').replace(/\s/g, '');
      const re = new RegExp('^\\d*\\.?\\d{0,' + this.decimals + '}$');
      if (!re.test(cleaned)) {
        e.target.value = this.raw;
        return;
      }
      this.raw = cleaned;
      this.$emit('update:modelValue', cleaned === '' || cleaned === '.' ? null : parseFloat(cleaned));
    },
    onBlur() {
      this.focused = false;
    },
  },
};
</script>

<style scoped>
.fx-amount-input {
  width: 100%;
  min-width: 0;
  text-align: right;
  font-variant-numeric: tabular-nums;
  font-weight: 700;
  padding: 0.5rem 0.7rem;
  border: 1px solid var(--lg-line, #e6ebf3);
  border-radius: 0.8rem;
  background: var(--lg-card, #fff);
  color: var(--lg-ink, #0f172a);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.fx-amount-input:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18);
}
.fx-amount-input:disabled,
.fx-amount-input:read-only {
  background: var(--lg-soft, #f6f8fc);
}
.fx-amount-input.is-error {
  border-color: #ef4444;
}
</style>

<template>
  <date-picker
    v-if="type == 'date'"
    :id="id"
    :label="label"
    :error="error"
    :disabled="disabled || readonly"
    :placeholder="placeholder"
    :model-value="modelValue"
    @update:model-value="$emit('update:modelValue', $event)"
    @change="$emit('change', $event)"
  />
  <div v-else class="col-span-6 sm:col-span-4 relative" :class="{ 'mb-2': size != 'small' }">
    <tec-label v-if="label" :for="id" :value="label" />
    <template v-if="type == 'number' && money">
      <!-- an amount: commas show as it is typed; the value that is saved stays the plain number -->
      <tec-input
        v-money="moneyOptions"
        :id="id"
        ref="input"
        type="number"
        :disabled="disabled"
        :readonly="readonly"
        :value="modelValue"
        class="block w-full"
        :placeholder="placeholder || label"
        @input="$emit('update:modelValue', $event.target.value)"
        :class="{ 'border-red-500': error, 'py-0 pr-0 h-8': size == 'small', 'mt-1': label }"
      />
    </template>
    <template v-else-if="type == 'number'">
      <tec-input
        :id="id"
        ref="input"
        step="0.01"
        :type="type"
        :disabled="disabled"
        :readonly="readonly"
        :value="modelValue"
        class="block w-full"
        :placeholder="placeholder || label"
        @input="$emit('update:modelValue', $event.target.value)"
        :class="{ 'border-red-500': error, 'py-0 pr-0 h-8': size == 'small', 'mt-1': label }"
      />
    </template>
    <template v-else>
      <tec-input
        :id="id"
        ref="input"
        :type="type"
        :disabled="disabled"
        :readonly="readonly"
        :value="modelValue"
        class="block w-full"
        :placeholder="placeholder || label"
        @input="$emit('update:modelValue', $event.target.value)"
        :class="{ 'border-red-500': error, 'py-0 pr-0 h-8': size == 'small', 'mt-1': label }"
      />
    </template>
    <tec-input-error v-if="error" :message="error" :class="size === 'small' ? 'absolute mt-0' : 'mt-1'" />
  </div>
</template>

<script>
import { v4 as uuidv4 } from 'uuid';
import TecLabel from '@/Jetstream/Label.vue';
import TecInput from '@/Jetstream/Input.vue';
import TecInputError from '@/Jetstream/InputError.vue';
import DatePicker from '@/Shared/DatePicker.vue';

export default {
  emits: ['update:modelValue', 'change'],
  components: { TecLabel, TecInput, TecInputError, DatePicker },
  props: {
    id: {
      type: String,
      default() {
        return uuidv4();
      },
    },
    type: {
      type: String,
      default: 'text',
    },
    size: String,
    label: String,
    error: String,
    disabled: Boolean,
    readonly: Boolean,
    placeholder: String,
    modelValue: [String, Number],
    // an amount field: thousands commas while typing. `money` alone, or `:money="{ decimals: 8 }"` for a rate with more decimals.
    money: { type: [Boolean, Object], default: false },
  },
  computed: {
    moneyOptions() {
      const given = this.money && typeof this.money === 'object' ? this.money : {};
      // min="0" (or more) on the text-input means no minus sign (the attribute lands on the wrapper, so it is read here)
      const min = this.$attrs.min;
      const noMinus = min !== undefined && min !== null && min !== '' && Number(min) >= 0;

      return { negative: !noMinus, ...given };
    },
  },
  methods: {
    focus() {
      this.$refs.input.focus();
    },
    select() {
      this.$refs.input.select();
    },
    setSelectionRange(start, end) {
      this.$refs.input.setSelectionRange(start, end);
    },
  },
};
</script>

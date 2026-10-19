<template>
  <template v-for="c in defs" :key="c.key">
    <div class="ac-f" :style="c.type === 'textarea' ? 'grid-column: 1 / -1' : ''">
      <label>{{ c.label }}<b v-if="c.required"> *</b></label>
      <textarea v-if="c.type === 'textarea'" :value="modelValue[c.key] || ''" rows="2" @input="set(c.key, $event.target.value)"></textarea>
      <date-picker v-else-if="c.type === 'date'" :model-value="modelValue[c.key] || ''" @update:model-value="v => set(c.key, v)" />
      <acct-select v-else-if="c.type === 'select'" :model-value="modelValue[c.key] || null" :options="options(c)" :placeholder="$t('Choose...')" clearable @update:model-value="v => set(c.key, v)" />
      <label v-else-if="c.type === 'yesno'" class="ac-check"><input type="checkbox" :checked="modelValue[c.key] === true" @change="set(c.key, $event.target.checked)" />{{ $t('Yes') }}</label>
      <input v-else-if="c.type === 'number'" :value="modelValue[c.key] || ''" inputmode="decimal" @input="set(c.key, $event.target.value)" />
      <input v-else :value="modelValue[c.key] || ''" maxlength="190" @input="set(c.key, $event.target.value)" />
      <small v-if="c.help" class="lg-subtext">{{ c.help }}</small>
      <span class="ac-err">{{ errors['custom.' + c.key] }}</span>
    </div>
  </template>
</template>

<script>
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';

// The user-defined fields of a record, drawn from their definitions (Subcontract Settings > Fields). The values go to the server as `custom[key]`.
export default {
  components: { DatePicker, AcctSelect },
  props: { defs: { type: Array, default: () => [] }, modelValue: { type: Object, default: () => ({}) }, errors: { type: Object, default: () => ({}) } },
  emits: ['update:modelValue'],
  methods: {
    options(c) { return c.options.map(o => ({ value: o, label: o })); },
    set(k, v) { this.$emit('update:modelValue', { ...this.modelValue, [k]: v }); },
  },
};
</script>

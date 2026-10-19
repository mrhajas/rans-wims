<template>
  <div class="ac-sel">
    <auto-complete
      :id="id"
      :suggestions="options"
      :model-value="modelValue === null || modelValue === undefined ? '' : modelValue"
      :placeholder="placeholder"
      :clearable="clearable && !disabled"
      :disable="undefined"
      :class="{ 'is-disabled': disabled }"
      @update:model-value="pick"
    />
    <div v-if="disabled" class="ac-sel-lock" aria-hidden="true"></div>
  </div>
</template>

<script>
import { v4 as uuidv4 } from 'uuid';
import AutoComplete from '@/Shared/AutoComplete.vue';

// The one dropdown of Accounts Tracking: always a searchable list (type to filter), never a plain browser select.
export default {
  components: { AutoComplete },
  props: { modelValue: { type: [String, Number, null], default: '' }, options: { type: Array, default: () => [] }, placeholder: String, clearable: Boolean, disabled: Boolean, id: { type: String, default: () => 'acs-' + uuidv4() }, emptyNull: { type: Boolean, default: true } },
  emits: ['update:modelValue', 'change'],
  methods: {
    pick(v) {
      const value = v === '' || v === undefined ? (this.emptyNull ? null : '') : v;
      this.$emit('update:modelValue', value);
      this.$emit('change', value);
    },
  },
};
</script>

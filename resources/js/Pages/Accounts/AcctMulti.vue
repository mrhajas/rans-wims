<template>
  <div class="ac-multi">
    <div v-if="chosen.length" class="ac-multi-chips">
      <span v-for="o in chosen" :key="o.value" class="lg-chip lg-chip-blue" style="text-transform: none">{{ o.label }}<button type="button" class="ac-multi-x" :aria-label="$t('Remove')" @click="remove(o.value)">&times;</button></span>
    </div>
    <acct-select :model-value="''" :options="rest" :placeholder="placeholder || $t('Search to add...')" @update:model-value="add" />
  </div>
</template>

<script>
import AcctSelect from './AcctSelect.vue';

// Several choices from a searchable list: pick one at a time, each becomes a removable chip.
export default {
  components: { AcctSelect },
  props: { modelValue: { type: Array, default: () => [] }, options: { type: Array, default: () => [] }, placeholder: String },
  emits: ['update:modelValue'],
  computed: {
    chosen() { return this.modelValue.map(v => this.options.find(o => o.value == v)).filter(Boolean); },
    rest() { return this.options.filter(o => !this.modelValue.some(v => v == o.value)); },
  },
  methods: {
    add(v) { if (v !== null && v !== '' && v !== undefined) this.$emit('update:modelValue', [...this.modelValue, v]); },
    remove(v) { this.$emit('update:modelValue', this.modelValue.filter(x => x != v)); },
  },
};
</script>

<template>
  <div class="fx-fsel">
    <tec-label v-if="label" :value="label" />
    <simple-autocomplete :model-value="current" :options="allOptions" :placeholder="placeholder || label" icon="search" :clearable="false" @update:modelValue="onPick" />
  </div>
</template>

<script>
import TecLabel from '@/Jetstream/Label.vue';
import SimpleAutocomplete from '@/Shared/SimpleAutocomplete.vue';

/**
 * A labelled, type-to-search dropdown for filters. `options` is [{ value, label }]; `emptyLabel` adds the first
 * "no filter" choice (value ''), e.g. "All statuses". The model stays '' / null when nothing is picked.
 */
export default {
  components: { TecLabel, SimpleAutocomplete },

  props: {
    modelValue: { type: [String, Number], default: '' },
    options: { type: Array, default: () => [] },
    label: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    emptyLabel: { type: String, default: '' },
  },

  emits: ['update:modelValue'],

  computed: {
    current() {
      return this.modelValue === null || this.modelValue === undefined || this.modelValue === '' ? '__none' : this.modelValue;
    },
    allOptions() {
      const list = this.options.map(o => ({ value: o.value, label: String(o.label) }));

      return this.emptyLabel ? [{ value: '__none', label: this.emptyLabel }, ...list] : list;
    },
  },

  methods: {
    onPick(v) {
      this.$emit('update:modelValue', v === '__none' ? '' : v);
    },
  },
};
</script>

<style scoped>
.fx-fsel { position: relative; margin-bottom: 0.5rem; }
.fx-fsel :deep(.sac-input) { display: block; box-sizing: border-box; height: 41.5px; margin-top: 3px; }
.fx-fsel :deep(.sac-icon) { top: calc(50% + 3px); }
</style>

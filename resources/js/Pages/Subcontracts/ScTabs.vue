<template>
  <nav class="sc-tabs" role="tablist">
    <button v-for="t in tabs" :key="t.key" type="button" role="tab" class="sc-tab" :class="['t-' + (t.tone || 'navy'), { 'is-on': modelValue === t.key }]" :aria-selected="modelValue === t.key" :title="t.label" @click="$emit('update:modelValue', t.key)">
      <i><icons :name="t.icon || fallback[t.tone || 'navy']" class="w-4 h-4" /></i><span>{{ t.label }}</span><b v-if="t.count !== undefined && t.count !== null">{{ t.count }}</b>
    </button>
  </nav>
</template>

<script>
// Equal-size folder tabs: icon, centred label, optional count pill. The active tab lifts, rings and shows a bar under it. Pure presentation: the page keeps the state.
export default {
  props: { tabs: Array, modelValue: { type: [String, Number], default: '' } },
  emits: ['update:modelValue'],
  data() { return { fallback: { navy: 'collection', slate: 'edit', amber: 'clock', sky: 'eye-flat', emerald: 'tick', rose: 'x-circle', violet: 'refresh', teal: 'list' } }; },
};
</script>

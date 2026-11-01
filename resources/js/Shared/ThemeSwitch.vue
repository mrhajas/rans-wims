<template>
  <div class="ts" :class="['ts-' + modelValue, { 'on-dark': dark }]" :style="{ '--i': index }" role="radiogroup" :aria-label="$t('Themes')">
    <i class="ts-thumb" aria-hidden="true"><b></b></i>
    <button v-for="o in options" :key="o.key" type="button" role="radio" class="ts-btn" :class="{ on: modelValue === o.key }" :aria-checked="String(modelValue === o.key)" @click.stop.prevent="$emit('pick', o.key)">
      <svg class="ts-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <template v-if="o.key === 'classic'"><path d="M4 6.5h16M4 12h16M4 17.5h10" /></template>
        <template v-else-if="o.key === 'pro'"><rect x="3.5" y="4" width="5" height="16" rx="2" /><path d="M13 7h7.5M13 12h7.5M13 17h5" /></template>
        <template v-else><path d="M12 3.2c3.4 4 6 6.7 6 10.3a6 6 0 0 1-12 0c0-3.6 2.6-6.3 6-10.3z" /><path d="M9.3 14.2a2.8 2.8 0 0 0 2.2 2" /></template>
      </svg>
      <span class="ts-lab">{{ o.label }}</span>
    </button>
  </div>
</template>

<script>
// The slider for the three looks of WIMS (Classic, Pro, Glass): a coloured pill glides to the one you choose, with a springy stop and a light that runs across it.
export default {
  props: { modelValue: { type: String, default: 'classic' }, dark: Boolean },
  emits: ['pick'],
  computed: {
    options() { return [{ key: 'classic', label: this.$t('Classic') }, { key: 'pro', label: this.$t('Pro') }, { key: 'glass', label: this.$t('Glass') }]; },
    index() { return Math.max(0, this.options.findIndex(o => o.key === this.modelValue)); },
  },
};
</script>

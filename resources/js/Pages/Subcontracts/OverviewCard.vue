<template>
  <component :is="to ? 'Link' : 'div'" :href="to" class="sc-ov" :class="variant ? 'is-' + variant : ''">
    <div class="sc-ov-top">
      <div class="sc-ov-head"><span class="sc-ov-ico"><icons :name="icon" class="w-5 h-5" /></span><div class="min-w-0"><div class="sc-ov-title">{{ title }}</div><div class="sc-ov-sub">{{ sub }}</div></div><slot name="side" /></div>
      <div class="sc-ov-num"><span>{{ value }}</span><span v-if="delta" class="sc-delta" :class="deltaTone ? 'is-' + deltaTone : ''">{{ delta }}</span></div>
      <slot />
    </div>
    <div v-if="foot" class="sc-ov-foot"><span>{{ foot }}</span><icons name="forward" class="w-4 h-4" /></div>
  </component>
</template>

<script>
import { Link } from '@inertiajs/vue3';

// An overview card: icon, title, one big number with an optional change chip, and a "see details" footer that links on.
export default { components: { Link }, props: { title: String, sub: String, value: [String, Number], delta: String, deltaTone: String, icon: { type: String, default: 'cash' }, foot: String, to: String, variant: String } };
</script>

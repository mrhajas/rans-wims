<template><span class="sc-ring" :class="[tone ? 'is-' + tone : '', dark ? 'on-dark' : '']" :style="{ '--p': pct, '--s': size + 'px' }" :title="label"><span>{{ pct }}%</span></span></template>

<script>
export default { props: { value: { type: [Number, String], default: 0 }, size: { type: Number, default: 56 }, tone: String, dark: Boolean, label: String }, computed: { pct() { const v = Math.round(Number(this.value) || 0); return Math.max(0, Math.min(100, v)); } } };
</script>

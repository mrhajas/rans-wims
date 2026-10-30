<template>
  <span class="idc" :class="{ 'is-lg': large }" :style="{ '--c': color, '--ink': ink }"><i class="idc-ico" :style="{ '--m': 'url(/images/idpass-icons/' + icon + '.png)' }"></i><span class="idc-t">{{ name }}</span></span>
</template>

<script>
// A category as a coloured chip with its own icon, so Staff, Aluminium, Carpenter, Visitor and Gate records are told apart at a glance in any list.
export default { props: { name: String, color: { type: String, default: '#64748b' }, ink: { type: String, default: '#ffffff' }, icon: { type: String, default: 'user' }, large: Boolean } };
</script>

<style scoped>
.idc { display: inline-flex; align-items: center; gap: 7px; padding: 4px 11px 4px 5px; border-radius: 999px; background: var(--c); color: var(--ink); font-size: 0.74rem; font-weight: 800; line-height: 1.2; white-space: nowrap; box-shadow: 0 8px 14px -9px var(--c); }
.idc-ico { width: 22px; height: 22px; border-radius: 50%; flex: none; background: color-mix(in srgb, var(--ink) 22%, transparent); position: relative; }
.idc-ico::after { content: ''; position: absolute; inset: 4px; background: var(--ink); -webkit-mask: var(--m) center / contain no-repeat; mask: var(--m) center / contain no-repeat; }
.idc.is-lg { font-size: 0.9rem; padding: 6px 15px 6px 7px; } .idc.is-lg .idc-ico { width: 30px; height: 30px; } .idc.is-lg .idc-ico::after { inset: 6px; }
</style>

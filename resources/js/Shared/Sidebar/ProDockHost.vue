<template>
  <div v-if="proDock.visible" class="pro-dock-fixed" :class="{ 'is-collapsed': proDock.collapsed, 'is-glass': proDock.style === 'glass' }">
    <glass-menu v-if="proDock.style === 'glass'" :collapsed="proDock.collapsed" @toggle="proDockCollapse(!proDock.collapsed, true)" />
    <pro-menu v-else :collapsed="proDock.collapsed" @toggle="proDockCollapse(!proDock.collapsed, true)" />
  </div>
</template>

<script>
import ProMenu from '@/Shared/Sidebar/ProMenu.vue';
import GlassMenu from '@/Shared/Sidebar/GlassMenu.vue';
import { proDock, proDockCollapse } from '@/Core/proDock';

export default {
  components: { ProMenu, GlassMenu },
  data() { return { proDock }; },
  methods: { proDockCollapse },
};
</script>

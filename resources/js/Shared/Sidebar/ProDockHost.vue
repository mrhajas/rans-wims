<template>
  <div v-if="proDock.visible" class="pro-dock-fixed" :class="{ 'is-collapsed': proDock.collapsed }">
    <pro-menu :collapsed="proDock.collapsed" @toggle="proDockCollapse(!proDock.collapsed)" />
  </div>
</template>

<script>
import ProMenu from '@/Shared/Sidebar/ProMenu.vue';
import { proDock, proDockCollapse } from '@/Core/proDock';

export default {
  components: { ProMenu },
  data() { return { proDock }; },
  methods: { proDockCollapse },
};
</script>

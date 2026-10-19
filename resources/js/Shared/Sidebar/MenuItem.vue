<template>
  <Link
    :href="href"
    :class="space == 'less' ? 'md:py-2' : 'md:py-3'"
    class="liquid-menu-item mx-2 my-1 flex items-center rounded-xl border px-4 md:px-2 py-3 lg:px-4 text-[13.5px]"
  >
    <slot name="icon">
      <icons v-if="!hideIcon" name="link" class="mr-3"></icons>
    </slot>
    <slot></slot>
  </Link>
</template>

<script>
export default {
  props: ['href', 'space', 'hideIcon'],
  data() {
    return { open: false };
  },
};
</script>

<style scoped>
.liquid-menu-item {
  color: #1e293b;
  border-color: transparent;
  background: transparent;
  transition: background-color 140ms ease, border-color 140ms ease;
}

.liquid-menu-item:hover,
.liquid-menu-item:focus {
  border-color: #eef1f6;
  background: #f8fafc;
}
</style>

<template>
  <section class="lg-card sc-cs" :class="{ 'is-closed': !isOpen }">
    <header class="lg-card-head sc-ch" role="button" tabindex="0" :aria-expanded="isOpen ? 'true' : 'false'" :title="isOpen ? $t('Click to collapse') : $t('Click to expand')" @click="onHead" @keydown.enter.self.prevent="toggle" @keydown.space.self.prevent="toggle">
      <slot name="head" />
      <span class="sc-chev" aria-hidden="true"><icons name="chevron-down" class="w-4 h-4" /></span>
    </header>
    <transition name="sc-fold">
      <div v-show="isOpen" class="sc-cbody"><slot /></div>
    </transition>
  </section>
</template>

<script>
// A card whose header folds and unfolds the card: click anywhere on the header (buttons, links and fields inside it keep doing their own job).
export default {
  props: { open: { type: Boolean, default: true } },
  data() { return { isOpen: this.open }; },
  watch: { open(v) { this.isOpen = v; } },
  methods: {
    toggle() { this.isOpen = !this.isOpen; },
    onHead(e) {
      if (e.target.closest && e.target.closest('button, a, input, select, textarea, label, .lg-btn, .lg-icon-btn, [data-nocollapse]')) return;
      this.toggle();
    },
  },
};
</script>

<template>
  <header class="lg-hero print:hidden">
    <span class="lg-hero-icon"><icons :name="icon" class="w-6 h-6" /></span>
    <div class="lg-hero-body">
      <h1 class="lg-title">{{ title }}</h1>
      <p v-if="subtitle" class="lg-sub">{{ subtitle }}</p>
    </div>
    <div v-if="$slots.default" class="lg-hero-actions"><slot /></div>
  </header>
</template>

<script>
export default {
  props: {
    icon: { type: String, default: 'collection' },
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
  },
};
</script>

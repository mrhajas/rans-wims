<template>
  <header class="sb-hero">
    <span class="sb-hero__blob b1"></span><span class="sb-hero__blob b2"></span><span class="sb-hero__blob b3"></span>
    <svg class="sb-hero__wave" viewBox="0 0 1440 54" preserveAspectRatio="none" aria-hidden="true"><path d="M0,27 q180,-40 360,0 t360,0 t360,0 t360,0 V54 H0Z" /></svg>
    <svg class="sb-hero__wave w2" viewBox="0 0 1440 42" preserveAspectRatio="none" aria-hidden="true"><path d="M0,21 q120,32 240,0 t240,0 t240,0 t240,0 t240,0 t240,0 V42 H0Z" /></svg>
    <div class="sb-hero__row">
      <span class="sb-hero__tile"><icons :name="icon" class="w-7 h-7" /></span>
      <div class="sb-hero__text">
        <h1>{{ title }}</h1>
        <p v-if="subtitle">{{ subtitle }}</p>
      </div>
      <div class="sb-hero__actions"><slot /></div>
    </div>
  </header>
</template>

<script>
export default { props: { icon: { type: String, default: 'sparkles' }, title: String, subtitle: String } };
</script>

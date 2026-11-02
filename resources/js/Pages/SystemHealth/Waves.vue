<template>
  <svg class="wv" viewBox="0 0 400 40" preserveAspectRatio="none" aria-hidden="true">
    <path class="wv-a" d="M0 20 Q 25 4 50 20 T 100 20 T 150 20 T 200 20 T 250 20 T 300 20 T 350 20 T 400 20 T 450 20 T 500 20 T 550 20 T 600 20 V40 H0z" />
    <path class="wv-b" d="M0 24 Q 25 10 50 24 T 100 24 T 150 24 T 200 24 T 250 24 T 300 24 T 350 24 T 400 24 T 450 24 T 500 24 T 550 24 T 600 24 V40 H0z" />
  </svg>
</template>

<script>
// Two slow waves along the foot of a card, in the colour of the card's rating. Pure decoration: it carries no number.
export default {};
</script>

<style scoped>
.wv { position: absolute; left: 0; right: 0; bottom: 0; width: 100%; height: 1.6rem; pointer-events: none; color: var(--tone, #64748b); }
.wv path { fill: currentColor; }
.wv-a { opacity: 0.1; animation: wv-slide 9s linear infinite; }
.wv-b { opacity: 0.07; animation: wv-slide 14s linear infinite reverse; }
@keyframes wv-slide { to { transform: translateX(-200px); } }
@media (prefers-reduced-motion: reduce) { .wv path { animation: none; } }
</style>

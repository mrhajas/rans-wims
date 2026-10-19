<template>
  <span class="arx-folder" :class="['is-' + tone, { 'is-open': open, 'is-locked': locked }]" :style="{ width: size + 'px', height: Math.round(size * 0.82) + 'px' }" aria-hidden="true">
    <svg viewBox="0 0 64 52" xmlns="http://www.w3.org/2000/svg">
      <path class="arx-f-back" d="M4 10a6 6 0 016-6h13.2a4 4 0 012.8 1.2L30 9h24a6 6 0 016 6v29a6 6 0 01-6 6H10a6 6 0 01-6-6z" />
      <g class="arx-f-paper"><rect x="10" y="11" width="44" height="26" rx="3" /><rect x="14" y="16" width="24" height="2.4" rx="1.2" /><rect x="14" y="21" width="30" height="2.4" rx="1.2" /></g>
      <g class="arx-f-front">
        <path class="arx-f-face" d="M4 22a6 6 0 016-6h44a6 6 0 016 6v22a6 6 0 01-6 6H10a6 6 0 01-6-6z" />
        <path class="arx-f-shine" d="M10 16h44a6 6 0 016 6v3H4v-3a6 6 0 016-6z" />
      </g>
    </svg>
    <span v-if="locked" class="arx-folder-lock"><icons name="lock-closed" class="w-full h-full" /></span>
  </span>
</template>

<script>
export default {
  props: {
    tone: { type: String, default: 'amber' },
    size: { type: Number, default: 48 },
    open: Boolean,
    locked: Boolean,
  },
};
</script>

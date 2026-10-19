<template>
  <span class="nav:hidden">
    <button
      type="button"
      class="mm-btn"
      :class="{ 'is-open': show }"
      :aria-label="show ? $t('Close menu') : $t('Open menu')"
      :aria-expanded="show ? 'true' : 'false'"
      @click="$emit('on-change', !show)"
    >
      <span class="mm-bars" aria-hidden="true"><i></i><i></i><i></i></span>
    </button>
  </span>
</template>

<script>
export default {
  props: {
    show: { type: Boolean, default: false },
  },
  emits: ['on-change'],
};
</script>

<style>
/* The menu button of phones and tablets: a calm glass key with three equal bars, centred. Open the menu and the bars fold into a cross.
   (.app-shell prefix: the app-wide button rule adds padding and a minimum height that would push the bars off centre.) */
.app-shell .mm-btn { position: relative; display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box; width: 40px; height: 40px; min-width: 0; min-height: 0; padding: 0; border: 0; border-radius: 14px; cursor: pointer; color: #fff; background: linear-gradient(145deg, rgba(255, 255, 255, 0.2), rgba(255, 255, 255, 0.05)); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.24), inset 0 1px 0 rgba(255, 255, 255, 0.3), 0 8px 16px -8px rgba(2, 6, 23, 0.7); transition: transform 0.18s ease, box-shadow 0.25s ease; -webkit-tap-highlight-color: transparent; }
.app-shell .mm-btn:hover { box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.35), 0 8px 16px -8px rgba(2, 6, 23, 0.7); }
.app-shell .mm-btn:active { transform: scale(0.94); }
.app-shell .mm-btn:focus-visible { outline: 2px solid #fff; outline-offset: 3px; }
.mm-bars { position: relative; display: block; flex: none; width: 18px; height: 12.6px; }
.mm-bars i { position: absolute; left: 0; width: 18px; height: 2.2px; border-radius: 3px; background: #fff; transition: transform 0.3s cubic-bezier(0.34, 1.4, 0.64, 1), top 0.3s ease, opacity 0.2s ease; }
.mm-bars i:nth-child(1) { top: 0; }
.mm-bars i:nth-child(2) { top: 5.2px; }
.mm-bars i:nth-child(3) { top: 10.4px; }
.app-shell .mm-btn.is-open .mm-bars i:nth-child(1) { top: 5.2px; transform: rotate(45deg); }
.app-shell .mm-btn.is-open .mm-bars i:nth-child(2) { opacity: 0; }
.app-shell .mm-btn.is-open .mm-bars i:nth-child(3) { top: 5.2px; transform: rotate(-45deg); }
@media (prefers-reduced-motion: reduce) { .mm-bars i, .app-shell .mm-btn { transition: none; } }
</style>

<template>
  <div v-if="visible" class="wims-gate" :class="{ 'wims-gate-open': opening }" aria-hidden="true">
    <div class="wims-gate-panel wims-gate-left"></div>
    <div class="wims-gate-panel wims-gate-right"></div>
    <img src="/images/logo.png" alt="" class="wims-gate-logo" :class="{ 'wims-gate-logo-fade': opening }" />
  </div>
</template>

<script>
// Plays once when `show` is true on mount — right after a fresh login (the
// dashboard, driven by CaptureSuccessfulLoginAttempt's one-request-only
// `just_logged_in` flash flag) or right after a logout (the login page,
// driven by a `logged_out` query param since Fortify's own session
// invalidation on logout happens *before* the redirect, wiping out any
// flash data set in a Logout event listener — a query param survives that
// where session flash can't). Never plays on an ordinary page visit either
// way. Pure CSS transform/opacity on two fixed-position panels, no JS
// animation loop and no work at all once `visible` goes false, so it costs
// nothing on any page except the one immediately after login/logout.
export default {
  props: {
    show: { type: Boolean, default: false },
  },

  data() {
    return {
      visible: false,
      opening: false,
    };
  },

  created() {
    if (this.show) {
      this.visible = true;
      // One frame to paint the closed gate before animating it open, so the
      // "close then open" motion is actually visible instead of starting
      // mid-transition.
      requestAnimationFrame(() => requestAnimationFrame(() => { this.opening = true; }));
      window.setTimeout(() => { this.visible = false; }, 900);
    }
  },
};
</script>

<style scoped>
.wims-gate {
  position: fixed;
  inset: 0;
  z-index: 9999;
  pointer-events: none;
  overflow: hidden;
}
.wims-gate-panel {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: transform 0.7s cubic-bezier(0.65, 0, 0.35, 1);
  will-change: transform;
}
.wims-gate-left {
  left: 0;
  background: linear-gradient(135deg, #fdf2f8, #eef2ff);
}
.wims-gate-right {
  right: 0;
  background: linear-gradient(150deg, #1e293b, #0f172a);
}
.wims-gate-open .wims-gate-left { transform: translateX(-100%); }
.wims-gate-open .wims-gate-right { transform: translateX(100%); }

.wims-gate-logo {
  position: absolute;
  top: 50%;
  left: 50%;
  height: 56px;
  width: auto;
  transform: translate(-50%, -50%);
  opacity: 1;
  transition: opacity 0.35s ease;
}
.wims-gate-logo-fade { opacity: 0; }

@media (prefers-reduced-motion: reduce) {
  .wims-gate-panel { transition: opacity 0.4s ease; }
  .wims-gate-open .wims-gate-left,
  .wims-gate-open .wims-gate-right { transform: none; opacity: 0; }
}
</style>

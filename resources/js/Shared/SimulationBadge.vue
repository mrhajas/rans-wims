<template>
  <div v-if="env" class="sim-strip" role="status" aria-live="polite">
    <span class="sim-dot" aria-hidden="true"></span>
    <b>{{ $t('SIMULATION') }}</b>
    <span v-if="env.scenario" class="sim-sc">{{ env.scenario }}</span>
    <span class="sim-note">{{ $t('Practice data, not official figures') }}</span>
  </div>
</template>

<script>
// Always shown, on every page, for a signed-in Simulation WIMS user. It comes from the server (the session's environment), never from the browser.
export default { computed: { env() { return this.$page.props.environment || null; } } };
</script>

<style scoped>
.sim-strip { display: flex; align-items: center; justify-content: center; gap: 10px; flex-wrap: wrap; padding: 4px 16px; font-size: 0.72rem; letter-spacing: 0.04em; color: #3b3f7a; background: linear-gradient(90deg, rgba(66, 123, 255, 0.12), rgba(168, 85, 247, 0.12), rgba(240, 82, 112, 0.14)); border-bottom: 1px solid transparent; border-image: linear-gradient(90deg, #427bff, #6258ff, #a855f7, #f05270) 1; }
.sim-strip b { font-weight: 800; letter-spacing: 0.14em; }
.sim-dot { width: 7px; height: 7px; border-radius: 999px; background: linear-gradient(135deg, #427bff, #f05270); box-shadow: 0 0 0 3px rgba(98, 88, 255, 0.18); }
.sim-sc { padding: 1px 8px; border-radius: 999px; background: rgba(98, 88, 255, 0.14); font-weight: 700; }
.sim-note { opacity: 0.75; }
/* the app's own dark mode (html.dark), not the browser's preference */
:global(html.dark) .sim-strip { color: #d9dcff; background: linear-gradient(90deg, rgba(66, 123, 255, 0.22), rgba(168, 85, 247, 0.22), rgba(240, 82, 112, 0.24)); }
</style>

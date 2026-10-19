<template>
  <span class="offline-lock-wrap lg-tip" :class="{ 'offline-lock-wrap--locked': locked }" :data-tip="locked ? lockMessage : null" :aria-label="locked ? lockMessage : null">
    <slot :locked="locked" />
    <icons v-if="locked" name="lock-closed" class="offline-lock-icon" />
  </span>
</template>

<script>
// Thin, opt-in wrapper for mutating controls (Create/Edit/Delete/Checkout/…).
// Deliberately not a global auto-disable-every-button directive — see
// resources/js/Core/mixin.js's $offlineBlocked and the plan's rationale:
// there's no reliable way to tell a read link from a mutating action purely
// from the DOM, so this gets added at each mutating call-site instead.
export default {
  props: {
    message: { type: String, default: null },
  },
  computed: {
    locked() {
      return this.$offlineBlocked;
    },
    lockMessage() {
      return this.message || this.$t('Unavailable offline. Please reconnect to WIMS to continue.');
    },
  },
};
</script>

<style scoped>
.offline-lock-wrap {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.offline-lock-wrap--locked {
  cursor: not-allowed;
}
.offline-lock-wrap--locked :deep(button),
.offline-lock-wrap--locked :deep(a) {
  pointer-events: none;
  opacity: 0.55;
}
.offline-lock-icon {
  width: 12px;
  height: 12px;
  color: #b45309;
  flex-shrink: 0;
}
</style>

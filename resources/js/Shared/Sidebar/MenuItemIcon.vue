<template>
  <div class="cursor-pointer flex relative" ref="trigger" @mouseenter="open" @mouseleave="close">
    <Link
      :href="href"
      class="liquid-icon-item flex items-center justify-center w-16 h-12 px-2 lg:px-4"
      :class="{ 'liquid-icon-item-hover': showPopout }"
    >
      <slot name="icon">
        <icons v-if="!hideIcon" name="link"></icons>
      </slot>
    </Link>

    <!-- Teleported to <body> so it can't be clipped by the sidebar's own
         overflow-y:auto (needed for the icon rail to scroll — but per the
         CSS overflow spec, setting one axis to auto/hidden forces the other
         axis non-visible too, so the popout can never escape via CSS alone
         once the sidebar scrolls). Positioned with JS from the trigger's
         own bounding rect instead. -->
    <Teleport to="body">
      <div
        v-if="showPopout"
        class="z-[200] fixed rounded-br flex w-48 flex-wrap"
        :style="{ top: popoutTop + 'px', left: popoutLeft + 'px' }"
        @mouseenter="open"
        @mouseleave="close"
      >
        <div
          class="liquid-icon-popout h-12 w-48 flex items-center rounded-r px-2 py-4 lg:px-4 text-[13.5px]"
          :class="{ 'liquid-icon-popout-active': active }"
        >
          <slot></slot>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script>
export default {
  props: ['href', 'hideIcon', 'active'],

  data() {
    return {
      showPopout: false,
      popoutTop: 0,
      popoutLeft: 0,
      closeTimer: null,
    };
  },

  beforeUnmount() {
    clearTimeout(this.closeTimer);
  },

  methods: {
    open() {
      clearTimeout(this.closeTimer);
      const rect = this.$refs.trigger.getBoundingClientRect();
      this.popoutTop = rect.top;
      this.popoutLeft = rect.right;
      this.showPopout = true;
    },
    close() {
      clearTimeout(this.closeTimer);
      this.closeTimer = setTimeout(() => {
        this.showPopout = false;
      }, 80);
    },
  },
};
</script>

<style scoped>
.liquid-icon-item {
  border-bottom: 1px solid rgba(255, 255, 255, 0.25);
  color: #1e293b;
  transition: background 140ms ease, box-shadow 140ms ease;
}

.liquid-icon-item:hover,
.liquid-icon-item:focus,
.liquid-icon-item-hover {
  background: linear-gradient(145deg, rgba(255, 255, 255, 0.78), rgba(224, 242, 254, 0.48));
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.82), 0 8px 14px rgba(56, 105, 170, 0.16);
}

.liquid-icon-item :deep(svg) {
  transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), color 0.2s ease;
}

.liquid-icon-item:hover :deep(svg),
.liquid-icon-item:focus :deep(svg),
.liquid-icon-item-hover :deep(svg) {
  transform: scale(1.18) rotate(-6deg);
  color: #2563eb;
}

.liquid-mini-active .liquid-icon-item :deep(svg) {
  animation: mini-icon-live 2.6s ease-in-out infinite;
}

@keyframes mini-icon-live {
  0%,
  100% {
    filter: drop-shadow(0 0 0 rgba(37, 99, 235, 0));
  }
  50% {
    filter: drop-shadow(0 0 4px rgba(37, 99, 235, 0.55));
  }
}

.liquid-icon-popout {
  border: 1px solid rgba(255, 255, 255, 0.72);
  color: #0f172a;
  background: linear-gradient(145deg, rgba(255, 255, 255, 0.9), rgba(224, 242, 254, 0.58));
  backdrop-filter: blur(12px) saturate(145%);
  -webkit-backdrop-filter: blur(12px) saturate(145%);
  font-weight: 500;
}

.liquid-icon-popout-active {
  color: #ca8a04;
  font-weight: 700;
}
</style>

<template>
  <div class="flex relative" ref="trigger" @mouseenter="open" @mouseleave="close">
    <a
      :class="{ 'liquid-icon-sub-active': active }"
      class="liquid-icon-sub-trigger cursor-pointer flex items-center justify-center w-16 h-12 px-2 lg:px-4"
    >
      <div class="flex items-center">
        <slot name="icon">
          <icons name="folder" v-if="!isOpen"></icons>
          <icons name="open-folder" v-else></icons>
        </slot>
      </div>
    </a>

    <!-- Teleported for the same reason as MenuItemIcon.vue: the sidebar's
         own overflow-y:auto (needed so the icon rail can scroll) would
         otherwise clip this since it must overflow horizontally outside
         the 64px rail to be visible. -->
    <Teleport to="body">
      <div
        v-if="isOpen"
        class="z-[200] fixed rounded-br flex w-56 flex-wrap"
        :style="{ top: popoutTop + 'px', left: popoutLeft + 'px' }"
        @mouseenter="open"
        @mouseleave="close"
      >
        <div
          class="liquid-icon-sub-title h-12 w-56 flex items-center rounded-tr px-2 lg:px-4 text-[13.5px]"
          :class="{ 'liquid-icon-sub-title-active': active }"
        >
          <slot></slot>
        </div>
        <div class="liquid-icon-sub-panel w-56 rounded-br flex flex-col flex-nowrap">
          <slot name="dropdown"></slot>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script>
import Icons from '../Icons.vue';
export default {
  components: { Icons },
  props: ['active'],

  data() {
    return {
      isOpen: false,
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
      this.isOpen = true;
    },
    close() {
      clearTimeout(this.closeTimer);
      this.closeTimer = setTimeout(() => {
        this.isOpen = false;
      }, 80);
    },
  },
};
</script>

<style scoped>
.liquid-icon-sub-trigger {
  border-bottom: 1px solid rgba(255, 255, 255, 0.25);
  color: #1e293b;
  transition: background 140ms ease, box-shadow 140ms ease;
}

.liquid-icon-sub-trigger:hover,
.liquid-icon-sub-trigger:focus,
.liquid-icon-sub-active {
  background: linear-gradient(145deg, rgba(255, 255, 255, 0.8), rgba(186, 230, 253, 0.48));
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.84), 0 8px 14px rgba(56, 105, 170, 0.16);
}

.liquid-icon-sub-trigger :deep(svg) {
  transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), color 0.2s ease;
}

.liquid-icon-sub-trigger:hover :deep(svg),
.liquid-icon-sub-trigger:focus :deep(svg) {
  transform: scale(1.18) rotate(-6deg);
  color: #2563eb;
}

.liquid-icon-sub-active :deep(svg) {
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

.liquid-icon-sub-title {
  border: 1px solid rgba(255, 255, 255, 0.7);
  color: #0f172a;
  background: linear-gradient(145deg, rgba(255, 255, 255, 0.9), rgba(224, 242, 254, 0.6));
  backdrop-filter: blur(12px) saturate(145%);
  -webkit-backdrop-filter: blur(12px) saturate(145%);
  font-weight: 500;
}

.liquid-icon-sub-title-active {
  color: #ca8a04;
  font-weight: 700;
}

.liquid-icon-sub-panel {
  border: 1px solid rgba(255, 255, 255, 0.62);
  border-top: 0;
  background: linear-gradient(160deg, rgba(255, 255, 255, 0.62), rgba(224, 242, 254, 0.46));
  backdrop-filter: blur(12px) saturate(140%);
  -webkit-backdrop-filter: blur(12px) saturate(140%);
}
</style>

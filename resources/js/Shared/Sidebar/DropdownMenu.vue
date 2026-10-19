<template>
  <div>
    <a
      @click="open = !open"
      :class="open ? 'liquid-dropdown-active text-slate-900' : ''"
      class="liquid-dropdown-trigger mx-2 my-1 cursor-pointer rounded-xl border flex items-center px-4 py-3 md:py-3 text-[13.5px]"
    >
      <span class="flex items-center grow">
        <slot name="icon">
          <Icons v-if="icon" :name="icon" class="mr-3" />
          <span v-else>
            <icons v-if="open" name="open-folder" class="mr-3"></icons>
            <icons v-else name="folder" class="mr-3"></icons>
          </span>
        </slot>
        <slot></slot>
      </span>
      <icons name="chevron-down" :class="{ '-rotate-180': open }" class="transition transform duration-300"></icons>
    </a>
    <!-- <transition name="slidedown"> -->
    <div v-if="open" class="liquid-dropdown-panel mx-2 mb-1 rounded-xl py-2 md:py-2">
      <slot name="dropdown"></slot>
    </div>
    <!-- </transition> -->
  </div>
</template>

<script>
export default {
  props: ['icon', 'active'],
  data() {
    return { open: false };
  },
  mounted() {
    this.open = this.active ? true : false;
  },
  watch: {
    // Inertia's persistent layout means this component doesn't remount on
    // navigation — without this watcher, jumping straight into a route
    // under a still-collapsed group (e.g. via Rauncher or a bookmark)
    // left it collapsed with no way to see the newly-active item.
    // Symmetric (open = value, not just open-on-true): since every
    // DropdownMenu instance persists for the whole session, a one-way
    // ratchet here means every group you ever visit stays permanently
    // expanded, and a long session ends with the entire sidebar open at
    // once. Mirroring `active` in both directions keeps exactly the
    // current page's group open, same as a fresh page load would.
    active(value) {
      this.open = !!value;
    },
  },
};
</script>

<style scoped>
.liquid-dropdown-trigger {
  color: #1e293b;
  border-color: transparent;
  transition: background-color 140ms ease, border-color 140ms ease;
}

.liquid-dropdown-trigger:hover,
.liquid-dropdown-trigger:focus {
  border-color: #eef1f6;
  background: #f8fafc;
}

.liquid-dropdown-active {
  border-color: #dbeafe !important;
  background: #eff6ff !important;
  font-weight: 700;
}

.liquid-dropdown-panel {
  border: none;
  background: transparent;
}
</style>

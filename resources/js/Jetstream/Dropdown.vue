<template>
  <div class="relative" :style="rootStyle">
    <div @click="open = !open" id="dd-div" ref="triggerEl">
      <slot name="trigger"></slot>
    </div>

    <!-- Full Screen Dropdown Overlay -->
    <div v-show="open" class="fixed inset-0 z-[90]" @click="open = false"></div>

    <transition
      enter-active-class="transition ease-out duration-200"
      enter-from-class="transform opacity-0 scale-95"
      enter-to-class="transform opacity-100 scale-100"
      leave-active-class="transition ease-in duration-75"
      leave-from-class="transform opacity-100 scale-100"
      leave-to-class="transform opacity-0 scale-95"
    >
      <div
        v-show="open"
        ref="panelEl"
        @click="close"
        style="display: none"
        :class="[effectiveWidthClass, positionClasses]"
        class="z-[100] rounded-xl shadow-xl"
      >
        <div class="rounded-xl border border-slate-100 overflow-hidden flex flex-col max-h-full" :class="contentClasses">
          <slot name="content"></slot>
        </div>
      </div>
    </transition>
  </div>
</template>

<script>
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

export default {
  props: {
    align: {
      default: 'right',
    },
    width: {
      default: '48',
    },
    contentClasses: {
      default: () => ['py-2', 'bg-white'],
    },
    closeOnClick: {
      default: true,
    },
    // Opt-in for triggers that sit near the right edge of the screen: keeps the
    // exact original `align="left"` expansion on desktop, but clamps the panel
    // to the right edge below the `nav` breakpoint so it can't run off-screen
    // on narrow viewports. Defaults to false so no other dropdown is affected.
    mobileAlignRight: {
      type: Boolean,
      default: false,
    },
    // Opt-in for triggers whose right-aligned/left-aligned panel would run
    // off-screen on a narrow phone (a fixed w-* panel anchored to a small
    // icon button overflows the viewport edge). Below `sm:` the panel
    // detaches from the trigger's left/right anchor and instead spans
    // horizontally with equal side margins, positioned just under the
    // trigger icon (not anchored to it, so it can't overflow) — capped to
    // 80vh with its own scroll for tall content. Desktop keeps the normal
    // anchored dropdown. Defaults to false so no other dropdown is affected.
    centerOnMobile: {
      type: Boolean,
      default: false,
    },
  },

  // watch: {
  //   open: {
  //     immediate: true,
  //     handler: open => {
  //       setTimeout(() => {
  //         document.getElementById('dd-table').style.overflow = open ? 'visible' : 'auto';
  //       }, 50);
  //     },
  //   },
  // },

  setup(props) {
    let open = ref(false);
    const triggerEl = ref(null);
    const panelEl = ref(null);
    const mobileTop = ref(null);

    // Every dropdown, not only the opt-in ones, must stay inside the screen: a panel anchored to a trigger near the left
    // or right edge of a phone used to hang off the viewport. The panel is nudged sideways with the CSS `translate`
    // property (it does not touch `transform`, which the open/close animation owns, nor Vue's v-show/:style).
    const clampToViewport = () => {
      const el = panelEl.value;
      if (!el || (props.centerOnMobile && window.innerWidth < 640)) return;
      el.style.translate = '';
      const r = el.getBoundingClientRect();
      if (!r.width) return;
      const margin = 8;
      let dx = 0;
      if (r.right > window.innerWidth - margin) dx = window.innerWidth - margin - r.right;
      if (r.left + dx < margin) dx = margin - r.left;
      if (dx) el.style.translate = Math.round(dx) + 'px 0';
    };

    // The app's top bar is fixed/sticky, so the trigger's viewport position
    // doesn't move on scroll — measuring once on mount (+ on resize, +
    // freshly right before opening) is enough to place the panel directly
    // under the icon without tracking scroll.
    const measureMobileTop = () => {
      if (!props.centerOnMobile || !triggerEl.value || window.innerWidth >= 640) {
        mobileTop.value = null;
        return;
      }
      const rect = triggerEl.value.getBoundingClientRect();
      mobileTop.value = Math.round(rect.bottom + 8);
    };

    const closeOnEscape = e => {
      if (open.value && e.keyCode === 27) {
        open.value = false;
      }
    };

    watch(open, val => {
      if (val) {
        nextTick(() => {
          measureMobileTop();
          clampToViewport();
          // the panel starts slightly scaled down while it animates in - measure again once it has settled
          setTimeout(clampToViewport, 230);
        });
      }
    });

    onMounted(() => {
      document.addEventListener('keydown', closeOnEscape);
      measureMobileTop();
      window.addEventListener('resize', measureMobileTop);
    });
    onUnmounted(() => {
      document.removeEventListener('keydown', closeOnEscape);
      window.removeEventListener('resize', measureMobileTop);
    });

    return {
      open,
      triggerEl,
      panelEl,
      mobileTop,
    };
  },

  computed: {
    widthClass() {
      return {
        40: 'w-40',
        48: 'w-48',
        56: 'w-56',
        64: 'w-64',
        72: 'w-72',
        80: 'w-80',
      }[this.width.toString()];
    },

    // On the centerOnMobile mobile layout, width comes from the inset-x-4
    // utility instead (equal left/right margins = true centering) — an
    // explicit width class here would win over that and leave unused space
    // on one side instead of centering. So the fixed width only applies from
    // sm: up there; desktop/normal dropdowns keep it unprefixed as before.
    effectiveWidthClass() {
      return this.centerOnMobile ? `sm:${this.widthClass}` : this.widthClass;
    },

    alignmentClasses() {
      if (this.align === 'left') {
        return this.mobileAlignRight ? 'origin-top-left right-0 nav:right-auto nav:left-0' : 'origin-top-left left-0';
      } else if (this.align === 'right') {
        return 'origin-top-right right-0';
      } else if (this.align === 'top-right') {
        return 'origin-bottom-right right-0 bottom-100';
      } else {
        return 'origin-top';
      }
    },

    positionClasses() {
      if (!this.centerOnMobile) {
        return `absolute my-2 ${this.alignmentClasses}`;
      }

      // Every alignment utility (left-0, origin-top-left, ...) only makes sense
      // once the panel goes back to being absolute/anchored at sm: — prefix
      // each one so none of them fight the mobile centering below that.
      const smAlignmentClasses = this.alignmentClasses
        .split(' ')
        .map(cls => `sm:${cls}`)
        .join(' ');

      // No top-* utility here on purpose — dd-mobile-anchored (below) reads
      // the actual offset from a CSS var set on the root wrapper, and
      // sm:top-auto clears it again at sm:+. It's a plain CSS rule rather
      // than a direct :style binding on this element because this element
      // also carries v-show inside a <transition> — Vue's transition/v-show
      // integration owns this element's `display` toggling, and a reactive
      // :style patch on the very same element fights that and can leave the
      // panel stuck invisible even once `open` is true. Keeping any :style
      // binding off of it avoids that entirely.
      return [
        'dd-mobile-anchored fixed inset-x-4 max-h-[80vh]',
        `sm:absolute sm:inset-x-auto sm:top-auto sm:my-2 sm:max-h-none ${smAlignmentClasses}`,
      ].join(' ');
    },

    rootStyle() {
      if (!this.centerOnMobile || this.mobileTop === null) {
        return {};
      }

      return { '--dd-mobile-top': `${this.mobileTop}px` };
    },
  },

  methods: {
    close() {
      if (this.closeOnClick) {
        this.open = false;
      }
    },
  },
};
</script>

<style scoped>
/* Scoped (not sm:top-auto) so it can never fight that utility on specificity —
   it simply doesn't apply at sm:+ at all. */
@media (max-width: 639.98px) {
  .dd-mobile-anchored {
    top: var(--dd-mobile-top, 96px);
  }
}
</style>

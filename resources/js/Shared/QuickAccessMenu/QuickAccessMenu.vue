<template>
  <div
    v-if="enabled && !offline && topItems.length"
    class="qam-wrap fixed print:hidden"
    :style="wrapStyle"
    @mouseenter="onMouseEnter"
    @mouseleave="onMouseLeave"
  >
    <button
      type="button"
      class="qam-trigger"
      :class="{ 'qam-trigger-open': open }"
      @pointerdown="onDragStart"
      @click="onTriggerClick"
      :aria-expanded="open ? 'true' : 'false'"
      :aria-label="$t('Quick Access Menu')"
    >
      <span class="qam-trigger-glow" aria-hidden="true"></span>
      <img src="/icons/icon-512x512.png" alt="" class="qam-trigger-icon" draggable="false" />
      <span v-if="!open" class="qam-trigger-tip" aria-hidden="true">{{ $t('Quick Access Menu') }}</span>
    </button>

    <transition name="qam-panel">
      <div v-if="open" class="qam-panel" role="menu">
        <template v-for="(item, i) in topItems" :key="item.key">
          <!-- "New Case" is the one shortcut that deliberately breaks from
               the rest: it opens in a new tab (so the page you were on stays
               put) and its destination carries that page along as
               Module/Reference + External URL prefill, so it can't be a
               plain Inertia <Link> like every other item here. -->
          <a
            v-if="item.key === 'new-case'"
            :href="newCaseHref"
            target="_blank"
            rel="noopener"
            class="qam-item tec-tooltip group relative"
            :style="{ transitionDelay: `${i * 25}ms` }"
            role="menuitem"
          >
            <icons :name="item.icon" class="w-5 h-5" />
            <span class="tec-tooltip-bubble">{{ trans(item.lang) }}</span>
          </a>
          <Link
            v-else
            :href="route(item.route)"
            class="qam-item tec-tooltip group relative"
            :class="{ 'qam-item-qr': item.key === 'qr-codes' }"
            :style="{ transitionDelay: `${i * 25}ms` }"
            role="menuitem"
            :aria-label="trans(item.lang)"
          >
            <icons :name="item.icon" class="w-5 h-5" />
            <span class="tec-tooltip-bubble">{{ trans(item.lang) }}</span>
          </Link>
        </template>
      </div>
    </transition>
  </div>
</template>

<script>
import { buildCandidates, resolveTopItems, wimsModuleForRoute } from '@/Core/quickMenu';

const POSITION_KEY = 'wims-quick-menu-position';
const MIN_TOP_PERCENT = 8;
const MAX_TOP_PERCENT = 78; // keeps the draggable quick-menu button clear of the fixed WIMSChat button at the bottom right
const DRAG_THRESHOLD = 6;

export default {
  data() {
    return {
      open: false,
      topPercent: this.loadPosition(),
      candidates: buildCandidates(),
      drag: null,
      closeTimer: null,
    };
  },

  computed: {
    enabled() {
      return this.$page.props.auth.user.quick_menu_enabled !== false;
    },
    offline() {
      return this.$connection.status === 'offline';
    },
    wrapStyle() {
      return { top: `${this.topPercent}%`, transform: 'translateY(-50%)' };
    },
    isSuperAdmin() {
      return (this.$page.props.auth?.user?.roles || []).some(r => r.name === 'Super Admin');
    },
    canCreateCase() {
      return this.canAccess({ key: 'new-case', permissions: ['create-case-tracking'], feature: 'case_tracking' });
    },
    topItems() {
      // Dashboard is always pinned first. Super Admins additionally pin WIMS
      // Connect, and anyone who can create a case additionally pins New Case
      // — each pinned slot bumps the limit by one so it never shrinks the
      // usage-ranked pool everyone else already relies on.
      const pinnedKeys = ['dashboard'];
      let limit = 5;
      if (this.isSuperAdmin) {
        pinnedKeys.push('wims-connect');
        limit += 1;
      }
      // the QR scanner is always there, right after Dashboard (and WIMS Connect for a Super Admin)
      pinnedKeys.push('qr-codes');
      limit += 1;
      if (this.canCreateCase) {
        pinnedKeys.push('new-case');
        limit += 1;
      }
      return resolveTopItems(this.candidates, { canAccess: item => this.canAccess(item) }, this.$page.props.sectionVisitCounts, limit, pinnedKeys);
    },
    newCaseHref() {
      // this.$page.url is read (not otherwise needed below) purely so this
      // computed is reactive across Inertia's client-side navigations —
      // route().current() and window.location itself aren't Vue-reactive,
      // so without this the href would go stale after the first page visit.
      void this.$page.url;
      const moduleReference = wimsModuleForRoute(route().current());
      return route('case-tracking.create', {
        module_reference: moduleReference,
        external_url: window.location.href,
      });
    },
  },

  mounted() {
    window.addEventListener('wims-quick-menu-reset-position', this.onResetPositionEvent);
  },

  beforeUnmount() {
    window.removeEventListener('pointermove', this.onDragMove);
    window.removeEventListener('pointerup', this.onDragEnd);
    window.removeEventListener('wims-quick-menu-reset-position', this.onResetPositionEvent);
    document.removeEventListener('click', this.onOutsideClick);
    clearTimeout(this.closeTimer);
  },

  methods: {
    trans(lang) {
      if (!lang) return '';
      if (lang.helper) {
        return this.$t(lang.helper, { x: this.$t(lang.main) });
      }
      return this.$t(lang.main);
    },

    canAccess(item) {
      if (!this.$can(item.permissions)) {
        return false;
      }
      if (item.feature && this.$page.props.enabledFeatures?.[item.feature] === false && !this.$super) {
        return false;
      }
      if (item.roles && item.roles.length) {
        const userRoles = (this.$page.props.auth?.user?.roles || []).map(r => r.name);
        if (!item.roles.some(r => userRoles.includes(r))) {
          return false;
        }
      }
      return true;
    },

    loadPosition() {
      const stored = Number(window.localStorage.getItem(POSITION_KEY));
      if (Number.isFinite(stored) && stored >= MIN_TOP_PERCENT && stored <= MAX_TOP_PERCENT) {
        return stored;
      }
      return 50;
    },

    savePosition() {
      window.localStorage.setItem(POSITION_KEY, String(Math.round(this.topPercent)));
    },

    onResetPositionEvent() {
      this.topPercent = 50;
    },

    onMouseEnter() {
      clearTimeout(this.closeTimer);
      if (window.matchMedia('(hover: hover)').matches) {
        this.open = true;
      }
    },

    onMouseLeave() {
      if (window.matchMedia('(hover: hover)').matches) {
        this.closeTimer = setTimeout(() => {
          this.open = false;
        }, 150);
      }
    },

    onTriggerClick() {
      if (this.drag?.moved) {
        return;
      }
      this.open = !this.open;
      if (this.open) {
        // Deferred past this click's own bubble phase — attaching synchronously
        // lets this same click reach the just-added document listener and
        // close the panel the instant it opens.
        setTimeout(() => {
          document.addEventListener('click', this.onOutsideClick);
        }, 0);
      }
    },

    onOutsideClick(e) {
      if (!this.$el.contains(e.target)) {
        this.open = false;
        document.removeEventListener('click', this.onOutsideClick);
      }
    },

    onDragStart(e) {
      this.drag = { startY: e.clientY, startPercent: this.topPercent, moved: false };
      window.addEventListener('pointermove', this.onDragMove);
      window.addEventListener('pointerup', this.onDragEnd);
    },

    onDragMove(e) {
      if (!this.drag) return;
      const deltaY = e.clientY - this.drag.startY;
      if (Math.abs(deltaY) > DRAG_THRESHOLD) {
        this.drag.moved = true;
      }
      const deltaPercent = (deltaY / window.innerHeight) * 100;
      this.topPercent = Math.min(Math.max(this.drag.startPercent + deltaPercent, MIN_TOP_PERCENT), MAX_TOP_PERCENT);
    },

    onDragEnd() {
      window.removeEventListener('pointermove', this.onDragMove);
      window.removeEventListener('pointerup', this.onDragEnd);
      if (this.drag?.moved) {
        this.savePosition();
      }
      // Clear on next tick so the click handler (fired after pointerup) can still see `moved`.
      setTimeout(() => {
        this.drag = null;
      }, 0);
    },
  },
};
</script>

<style scoped>
.qam-wrap {
  right: 14px;
  z-index: 45;
}

.qam-trigger {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 48px;
  width: 48px;
  border-radius: 9999px;
  background: linear-gradient(155deg, #1e3a5f, #14294e);
  backdrop-filter: blur(12px) saturate(160%);
  -webkit-backdrop-filter: blur(12px) saturate(160%);
  border: 1px solid rgba(255, 255, 255, 0.15);
  box-shadow: 0 10px 24px -8px rgba(15, 23, 42, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.12);
  cursor: grab;
  touch-action: none;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.qam-trigger:active {
  cursor: grabbing;
}
.qam-trigger:hover,
.qam-trigger-open {
  transform: scale(1.06);
  box-shadow: 0 14px 30px -8px rgba(15, 23, 42, 0.65), inset 0 1px 0 rgba(255, 255, 255, 0.14);
}

.qam-trigger-icon {
  height: 26px;
  width: 26px;
  position: relative;
  z-index: 1;
  pointer-events: none;
}

.qam-trigger-glow {
  position: absolute;
  inset: -8px;
  border-radius: 9999px;
  background: radial-gradient(circle, rgba(30, 58, 95, 0.55) 0%, rgba(20, 41, 78, 0.22) 55%, transparent 75%);
  animation: qam-pulse 2.4s ease-in-out infinite;
}
.qam-trigger-glow::after {
  content: '';
  position: absolute;
  inset: 4px;
  border-radius: 9999px;
  background: radial-gradient(circle, rgba(56, 189, 248, 0.4) 0%, transparent 70%);
  animation: qam-pulse-inner 2.4s ease-in-out infinite;
}

@keyframes qam-pulse {
  0%, 100% { transform: scale(0.88); opacity: 0.55; }
  50% { transform: scale(1.28); opacity: 1; }
}
@keyframes qam-pulse-inner {
  0%, 100% { transform: scale(0.85); opacity: 0.4; }
  50% { transform: scale(1.1); opacity: 0.85; }
}

@media (prefers-reduced-motion: reduce) {
  .qam-trigger-glow,
  .qam-trigger-glow::after {
    animation: none;
  }
}

.qam-panel {
  position: absolute;
  right: calc(100% + 12px);
  top: 50%;
  transform: translateY(-50%);
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 8px;
  border-radius: 1.1rem;
  background: linear-gradient(150deg, rgba(255, 255, 255, 0.92), rgba(240, 249, 255, 0.85));
  backdrop-filter: blur(14px) saturate(160%);
  -webkit-backdrop-filter: blur(14px) saturate(160%);
  border: 1px solid rgba(255, 255, 255, 0.8);
  box-shadow: 0 16px 36px -12px rgba(15, 23, 42, 0.3);
}

.qam-item {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 40px;
  width: 40px;
  border-radius: 9999px;
  color: #1d4ed8;
  background: rgba(219, 234, 254, 0.7);
  transition: background 0.15s ease, color 0.15s ease, transform 0.15s ease;
}
.qam-item-qr { color: #fff; background: linear-gradient(145deg, #6366f1, #0ea5e9); box-shadow: 0 8px 16px -8px rgba(14, 165, 233, 0.85); }
.qam-item-qr:hover { background: linear-gradient(145deg, #4f46e5, #0284c7); }
.qam-trigger-tip { position: absolute; right: calc(100% + 10px); top: 50%; transform: translateY(-50%) translateX(4px); padding: 0.35rem 0.7rem; border-radius: 0.5rem; white-space: nowrap; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.02em; color: #f8fafc; background: linear-gradient(145deg, rgba(15, 23, 42, 0.96), rgba(30, 41, 59, 0.92)); box-shadow: 0 10px 20px rgba(15, 23, 42, 0.25); opacity: 0; pointer-events: none; transition: opacity 0.15s ease, transform 0.15s ease; }
.qam-trigger:hover .qam-trigger-tip, .qam-trigger:focus-visible .qam-trigger-tip { opacity: 1; transform: translateY(-50%) translateX(0); }
.qam-item:hover {
  background: #2563eb;
  color: #fff;
  transform: scale(1.08);
}

.qam-panel-enter-active,
.qam-panel-leave-active {
  transition: opacity 0.18s ease, transform 0.18s ease;
}
.qam-panel-enter-from,
.qam-panel-leave-to {
  opacity: 0;
  transform: translateY(-50%) translateX(6px) scale(0.92);
}

.qam-item .tec-tooltip-bubble {
  position: absolute;
  right: calc(100% + 10px);
  top: 50%;
  left: auto;
  transform: translateY(-50%) translateX(4px);
  z-index: 20;
  padding: 0.35rem 0.7rem;
  border-radius: 0.5rem;
  white-space: nowrap;
  font-size: 0.7rem;
  font-weight: 600;
  letter-spacing: 0.02em;
  color: #f8fafc;
  background: linear-gradient(145deg, rgba(15, 23, 42, 0.96), rgba(30, 41, 59, 0.92));
  box-shadow: 0 10px 20px rgba(15, 23, 42, 0.25);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.qam-item .tec-tooltip-bubble::after {
  content: '';
  position: absolute;
  left: 100%;
  top: 50%;
  transform: translateY(-50%);
  border: 5px solid transparent;
  border-left-color: rgba(15, 23, 42, 0.96);
}
.qam-item:hover .tec-tooltip-bubble,
.qam-item:focus-visible .tec-tooltip-bubble {
  opacity: 1;
  transform: translateY(-50%) translateX(0);
}

html.dark .qam-trigger {
  background: linear-gradient(155deg, rgba(30, 41, 59, 0.85), rgba(15, 23, 42, 0.75));
  border-color: rgba(255, 255, 255, 0.1);
}
html.dark .qam-panel {
  background: linear-gradient(150deg, rgba(30, 41, 59, 0.92), rgba(15, 23, 42, 0.85));
  border-color: rgba(255, 255, 255, 0.1);
}
html.dark .qam-item {
  background: rgba(37, 99, 235, 0.25);
  color: #93c5fd;
}

@media (max-width: 640px) {
  .qam-wrap {
    right: 10px;
  }
  .qam-trigger {
    height: 44px;
    width: 44px;
  }
  .qam-item {
    height: 44px;
    width: 44px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .qam-trigger-glow {
    animation: none;
  }
  .qam-trigger,
  .qam-item,
  .qam-panel-enter-active,
  .qam-panel-leave-active {
    transition: none;
  }
}
</style>

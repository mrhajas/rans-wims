<template>
  <span class="hover-preview-trigger" ref="trigger" @mouseenter="onEnter" @mouseleave="onLeave">
    <slot />
    <teleport to="body">
      <div v-if="visible" class="hover-preview-card glass-panel" :style="cardStyle" @mouseenter="onCardEnter" @mouseleave="onLeave">
        <div class="hover-preview-header">
          <span class="hover-preview-icon">
            <icons :name="state?.icon || 'info'" className="w-4 h-4" />
          </span>
          <span class="hover-preview-title">{{ state?.title || '…' }}</span>
        </div>

        <div v-if="loading" class="hover-preview-skeleton">
          <div class="hover-preview-skeleton-line" v-for="n in 3" :key="n"></div>
        </div>
        <div v-else-if="failed" class="hover-preview-error">{{ $t("Couldn't load preview.") }}</div>
        <template v-else>
          <dl class="hover-preview-fields">
            <template v-for="([label, value], i) in state?.fields || []" :key="i">
              <dt>{{ label }}</dt>
              <dd>{{ value }}</dd>
            </template>
          </dl>

          <div v-if="state?.lines?.length" class="hover-preview-lines">
            <div class="hover-preview-lines-header">
              <span>{{ $t('Item') }}</span>
              <span>{{ $t('Qty') }}</span>
            </div>
            <div v-for="(line, i) in state.lines" :key="i" class="hover-preview-line">
              <span class="hover-preview-line-name">{{ line.name }}</span>
              <span class="hover-preview-line-qty">{{ line.quantity }} <span class="hover-preview-line-unit">{{ line.unit }}</span></span>
            </div>
            <div v-if="state.linesTotal > state.lines.length" class="hover-preview-lines-more">
              +{{ state.linesTotal - state.lines.length }} {{ $t('more') }}
            </div>
          </div>
        </template>
      </div>
    </teleport>
  </span>
</template>

<script>
const cache = new Map();
const SHOW_DELAY = 300;
const HIDE_DELAY = 120;

export default {
  props: {
    category: { type: String, required: true },
    id: { type: [Number, String], required: true },
  },

  data() {
    return {
      visible: false,
      loading: false,
      failed: false,
      state: null,
      position: { top: 0, left: 0, openUp: false },
      showTimer: null,
      hideTimer: null,
    };
  },

  computed: {
    cacheKey() {
      return `${this.category}:${this.id}`;
    },
    cardStyle() {
      return {
        top: `${this.position.top}px`,
        left: `${this.position.left}px`,
        transformOrigin: this.position.openUp ? 'bottom left' : 'top left',
      };
    },
  },

  beforeUnmount() {
    clearTimeout(this.showTimer);
    clearTimeout(this.hideTimer);
  },

  methods: {
    onEnter() {
      clearTimeout(this.hideTimer);
      this.showTimer = setTimeout(() => this.open(), SHOW_DELAY);
    },

    onCardEnter() {
      clearTimeout(this.hideTimer);
    },

    onLeave() {
      clearTimeout(this.showTimer);
      this.hideTimer = setTimeout(() => {
        this.visible = false;
      }, HIDE_DELAY);
    },

    open() {
      this.computePosition();
      this.visible = true;

      if (cache.has(this.cacheKey)) {
        this.state = cache.get(this.cacheKey);
        this.loading = false;
        this.failed = false;
        return;
      }

      this.loading = true;
      this.failed = false;
      this.state = null;

      window.axios
        .get(this.route('hover-preview.show'), { params: { category: this.category, id: this.id } })
        .then(res => {
          cache.set(this.cacheKey, res.data);
          this.state = res.data;
        })
        .catch(error => {
          this.failed = true;
          // eslint-disable-next-line no-console
          console.error(
            '[HoverPreview] failed to load preview for',
            this.cacheKey,
            '— status:', error.response?.status,
            '— url:', error.config?.url,
            '— body:', error.response?.data
          );
        })
        .finally(() => {
          this.loading = false;
        });
    },

    computePosition() {
      const rect = this.$refs.trigger.getBoundingClientRect();
      const cardWidth = 300;
      const cardHeight = 260;
      const margin = 8;

      let left = rect.left;
      if (left + cardWidth > window.innerWidth - margin) {
        left = Math.max(window.innerWidth - cardWidth - margin, margin);
      }

      const spaceBelow = window.innerHeight - rect.bottom;
      const openUp = spaceBelow < cardHeight + margin && rect.top > cardHeight + margin;
      const top = openUp ? rect.top - cardHeight - 8 : rect.bottom + 8;

      this.position = { top, left, openUp };
    },
  },
};
</script>

<style scoped>
.hover-preview-trigger {
  display: inline;
}
</style>

<style>
.hover-preview-card {
  position: fixed;
  z-index: 300;
  width: 300px;
  max-height: 340px;
  overflow-y: auto;
  border-radius: 14px;
  padding: 12px 14px;
  pointer-events: auto;
  box-shadow: 0 20px 45px -15px rgba(15, 23, 42, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.7);
  animation: hover-preview-in 120ms ease-out;
}

@keyframes hover-preview-in {
  from {
    opacity: 0;
    transform: scale(0.97) translateY(-2px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}

.hover-preview-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
  padding-bottom: 8px;
  border-bottom: 1px solid rgba(148, 163, 184, 0.25);
}

.hover-preview-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border-radius: 8px;
  background: rgba(99, 102, 241, 0.12);
  color: #4f46e5;
  flex: none;
}

.hover-preview-title {
  font-size: 13px;
  font-weight: 700;
  color: #111827;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.hover-preview-fields {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 4px 10px;
  margin: 0;
}

.hover-preview-fields dt {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.02em;
  color: #6b7280;
  white-space: nowrap;
}

.hover-preview-fields dd {
  margin: 0;
  font-size: 12.5px;
  color: #1f2937;
  text-align: right;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.hover-preview-lines {
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid rgba(148, 163, 184, 0.25);
}

.hover-preview-lines-header {
  display: flex;
  justify-content: space-between;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: #9ca3af;
  margin-bottom: 4px;
}

.hover-preview-line {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
  padding: 3px 0;
  font-size: 12px;
}

.hover-preview-line-name {
  color: #1f2937;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  flex: 1;
  min-width: 0;
}

.hover-preview-line-qty {
  flex: none;
  font-weight: 600;
  color: #111827;
  white-space: nowrap;
}

.hover-preview-line-unit {
  font-weight: 400;
  color: #6b7280;
  font-size: 11px;
}

.hover-preview-lines-more {
  margin-top: 4px;
  font-size: 11px;
  color: #9ca3af;
  font-style: italic;
}

.hover-preview-skeleton-line {
  height: 9px;
  border-radius: 5px;
  margin-bottom: 6px;
  background: linear-gradient(90deg, rgba(148, 163, 184, 0.2) 25%, rgba(148, 163, 184, 0.35) 37%, rgba(148, 163, 184, 0.2) 63%);
  background-size: 200% 100%;
  animation: hover-preview-shimmer 1.2s ease-in-out infinite;
}
.hover-preview-skeleton-line:last-child {
  width: 60%;
}

@keyframes hover-preview-shimmer {
  0% {
    background-position: 200% 0;
  }
  100% {
    background-position: -200% 0;
  }
}

.hover-preview-error {
  font-size: 12px;
  color: #6b7280;
  font-style: italic;
}

html.dark .hover-preview-card {
  background: linear-gradient(140deg, rgba(30, 41, 59, 0.92), rgba(15, 23, 42, 0.88));
  border-color: rgba(255, 255, 255, 0.08);
  box-shadow: 0 20px 45px -15px rgba(0, 0, 0, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.06);
}
html.dark .hover-preview-header {
  border-bottom-color: rgba(255, 255, 255, 0.08);
}
html.dark .hover-preview-title {
  color: #f1f5f9;
}
html.dark .hover-preview-fields dt {
  color: #94a3b8;
}
html.dark .hover-preview-fields dd {
  color: #e2e8f0;
}
html.dark .hover-preview-lines {
  border-top-color: rgba(255, 255, 255, 0.08);
}
html.dark .hover-preview-line-name,
html.dark .hover-preview-line-qty {
  color: #e2e8f0;
}
html.dark .hover-preview-line-unit {
  color: #94a3b8;
}
</style>

<template>
  <teleport to="body">
    <transition name="sd-backdrop">
      <div v-if="show" class="sd-backdrop" @click="$emit('close')"></div>
    </transition>
    <transition name="sd-panel">
      <div v-if="show" class="sd-drawer" :style="{ '--sd-accent': accent }">
        <div class="sd-header">
          <span v-if="icon" class="sd-header-icon"><icons :name="icon" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <div class="sd-title">{{ title }}</div>
            <div v-if="subtitle" class="sd-subtitle">{{ subtitle }}</div>
          </div>
          <button type="button" class="sd-close-btn" :aria-label="$t('Close')" @click="$emit('close')">
            <icons name="cross" class="w-4 h-4" />
          </button>
        </div>

        <div v-if="$slots.tabs" class="sd-tabs">
          <slot name="tabs"></slot>
        </div>

        <div class="sd-body">
          <slot></slot>
        </div>

        <div v-if="$slots.footer" class="sd-footer">
          <slot name="footer"></slot>
        </div>
      </div>
    </transition>
  </teleport>
</template>

<script>
export default {
  props: {
    show: { type: Boolean, default: false },
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    icon: { type: String, default: '' },
    accent: { type: String, default: '#4f46e5' },
  },
  emits: ['close'],
  watch: {
    show(value) {
      document.body.style.overflow = value ? 'hidden' : '';
    },
  },
  beforeUnmount() {
    document.body.style.overflow = '';
  },
};
</script>

<style scoped>
.sd-backdrop { position: fixed; inset: 0; z-index: 1000; background: rgba(15, 23, 42, 0.45); backdrop-filter: blur(3px); }
.sd-backdrop-enter-active, .sd-backdrop-leave-active { transition: opacity 0.25s ease; }
.sd-backdrop-enter-from, .sd-backdrop-leave-to { opacity: 0; }

.sd-drawer {
  --lg-card: #ffffff; --lg-line: #e6ebf3; --lg-soft: #f6f8fc; --lg-ink: #0f172a; --lg-mute: #64748b; --lg-faint: #94a3b8; --lg-navy: #1c3f66;
  position: fixed; inset-block: 0; right: 0; z-index: 1001; width: 100%; max-width: 620px;
  background: #fff;
  box-shadow: -24px 0 64px -12px rgba(15, 23, 42, 0.35);
  display: flex; flex-direction: column;
}
html.dark .sd-drawer {
  --lg-card: #1e293b; --lg-line: rgba(255, 255, 255, 0.1); --lg-soft: rgba(255, 255, 255, 0.05); --lg-ink: #f1f5f9; --lg-mute: #94a3b8; --lg-faint: #64748b; --lg-navy: #cbd5e1;
  background: #0f172a;
}
.sd-panel-enter-active, .sd-panel-leave-active { transition: transform 0.32s cubic-bezier(0.16, 1, 0.3, 1); }
.sd-panel-enter-from, .sd-panel-leave-to { transform: translateX(100%); }

.sd-header {
  display: flex; align-items: center; gap: 14px; padding: 18px 22px;
  border-bottom: 1px solid var(--lg-line);
  background: linear-gradient(120deg, color-mix(in srgb, var(--sd-accent) 14%, transparent), transparent 65%);
}
.sd-header-icon {
  display: inline-flex; align-items: center; justify-content: center; height: 44px; width: 44px; border-radius: 15px;
  flex: none; color: #fff; background: var(--sd-accent);
  box-shadow: 0 10px 22px -8px color-mix(in srgb, var(--sd-accent) 70%, transparent);
}
.sd-title { font-size: 1.05rem; font-weight: 800; color: var(--lg-ink); overflow-wrap: anywhere; }
.sd-subtitle { font-size: 0.76rem; color: var(--lg-mute); margin-top: 2px; }
.sd-close-btn {
  display: inline-flex; align-items: center; justify-content: center; height: 34px; width: 34px; border-radius: 9999px; flex: none;
  color: var(--lg-mute); background: var(--lg-soft); border: 1px solid var(--lg-line); transition: background 0.15s ease;
}
.sd-close-btn:hover { background: var(--lg-line); color: var(--lg-ink); }

.sd-tabs { display: flex; gap: 4px; padding: 10px 18px 0; border-bottom: 1px solid var(--lg-line); overflow-x: auto; }

.sd-body { flex: 1; overflow-y: auto; padding: 18px 22px; display: flex; flex-direction: column; gap: 18px; }
.sd-footer { padding: 14px 22px; border-top: 1px solid var(--lg-line); display: flex; align-items: center; gap: 10px; flex-wrap: wrap; background: var(--lg-soft); }

/* Styling for the content pages put inside the drawer slot */
.sd-body :deep(.sd-stat) { padding: 0.75rem 0.6rem; text-align: center; border-radius: 16px; background: var(--lg-soft); border: 1px solid var(--lg-line); min-width: 0; }
.sd-body :deep(.sd-stat-rose) { background: rgba(244, 63, 94, 0.08); border-color: rgba(244, 63, 94, 0.25); }
.sd-body :deep(.sd-stat-value) { font-size: 0.92rem; font-weight: 800; color: var(--lg-ink); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.sd-body :deep(.sd-stat-rose .sd-stat-value) { color: #be123c; }
html.dark .sd-body :deep(.sd-stat-rose .sd-stat-value) { color: #fda4af; }
.sd-body :deep(.sd-stat-label) { margin-top: 2px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.sd-body :deep(.sd-section-title) { margin-bottom: 10px; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.sd-body :deep(.sd-items) { display: flex; flex-direction: column; border: 1px solid var(--lg-line); border-radius: 16px; background: var(--lg-card); overflow: hidden; }
.sd-body :deep(.sd-item-row) { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 14px; font-size: 0.84rem; border-bottom: 1px solid var(--lg-line); }
.sd-body :deep(.sd-items .sd-item-row:last-child) { border-bottom: 0; }
.sd-body :deep(.sd-item-row-total) { margin-top: 8px; border: 1px solid var(--lg-line); border-radius: 14px; background: var(--lg-soft); font-weight: 800; }
.sd-body :deep(.sd-item-label) { color: var(--lg-mute); font-weight: 600; min-width: 0; overflow-wrap: anywhere; }
.sd-body :deep(.sd-item-amount) { font-weight: 800; font-variant-numeric: tabular-nums; color: var(--lg-ink); white-space: nowrap; }
.sd-body :deep(.sd-item-positive) { color: #047857; }
.sd-body :deep(.sd-item-negative) { color: #be123c; }
.sd-body :deep(.sd-item-muted) { color: var(--lg-faint); font-weight: 600; }
html.dark .sd-body :deep(.sd-item-positive) { color: #6ee7b7; }
html.dark .sd-body :deep(.sd-item-negative) { color: #fda4af; }
.sd-body :deep(.sd-empty) { padding: 22px 12px; text-align: center; font-size: 0.82rem; color: var(--lg-faint); border: 1px dashed var(--lg-line); border-radius: 16px; }
.sd-body :deep(.sd-mini-form) { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.sd-body :deep(.sd-mini-input) { padding: 0.5rem 0.65rem; border-radius: 12px; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-ink); font-size: 0.8rem; min-width: 0; }
.sd-body :deep(.sd-mini-input:focus) { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
.sd-body :deep(.sd-mini-btn) { flex: none; padding: 0.5rem 1rem; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); }
.sd-body :deep(.sd-remove-btn) { margin-left: 6px; padding: 1px 8px; border-radius: 9999px; font-size: 0.7rem; font-weight: 800; color: #be123c; background: rgba(244, 63, 94, 0.12); }
.sd-body :deep(.sd-remove-btn:hover) { background: rgba(244, 63, 94, 0.22); }
.sd-body :deep(.sd-reversal-tag) { margin-left: 6px; padding: 1px 8px; border-radius: 9999px; font-size: 0.66rem; font-weight: 800; text-transform: uppercase; color: #92400e; background: rgba(245, 158, 11, 0.17); }
.sd-body :deep(.sd-hint) { margin-top: 6px; font-size: 0.72rem; color: var(--lg-faint); }

@media (max-width: 640px) {
  .sd-drawer { max-width: 100%; }
  .sd-header, .sd-body, .sd-footer { padding-left: 16px; padding-right: 16px; }
}
@media (prefers-reduced-motion: reduce) { .sd-panel-enter-active, .sd-panel-leave-active { transition: none; } }
</style>

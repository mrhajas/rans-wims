<!--
  Reusable icon/dot-aware, type-to-search filter dropdown, shared by
  DeliveryLogs.vue and BulkConnect/History.vue. A plain <select> can't
  render a channel icon or a colored live/status dot per option, so this
  replaces it with a button + floating searchable option list. A real
  standalone .vue file (unlike an inline object component defined inside a
  page's <script> block) gets compiled by the normal SFC pipeline, so plain
  <template> syntax works here — no h()/render() workaround needed.
-->
<template>
  <div class="wc-dd">
    <button type="button" class="wc-select wc-dd-trigger" @click="toggle">
      <icons v-if="selected?.icon" :name="selected.icon" class="w-4 h-4 text-gray-600 flex-none" />
      <span v-else-if="selected?.dotColor" class="wc-dd-status-dot flex-none" :style="{ background: selected.dotColor }"></span>
      <span v-else class="w-4 h-4 flex-none"></span>
      <span class="flex-1 text-left truncate">{{ selected ? selected.label : placeholder }}</span>
      <icons name="chevron-down" class="w-3.5 h-3.5 text-gray-400 flex-none transition-transform" :class="{ 'rotate-180': open }" />
    </button>
    <div v-if="open" class="wc-dd-panel">
      <div class="wc-dd-search-wrap">
        <icons name="search" class="w-3.5 h-3.5 text-gray-400 flex-none" />
        <input
          ref="search"
          v-model="search"
          type="text"
          class="wc-dd-search"
          :placeholder="$t('Search…')"
          @keydown.esc="close"
        />
      </div>
      <ul class="wc-dd-list">
        <li v-if="!search" class="wc-dd-item" @click="select('')">
          <span class="w-4 h-4 flex-none"></span>
          <span class="flex-1">{{ placeholder }}</span>
        </li>
        <li v-for="opt in filteredOptions" :key="opt.value" class="wc-dd-item" @click="select(opt.value)">
          <icons v-if="opt.icon" :name="opt.icon" class="w-4 h-4 text-gray-600 flex-none" />
          <span v-else-if="opt.dotColor" class="wc-dd-status-dot flex-none" :style="{ background: opt.dotColor }"></span>
          <span v-else class="w-4 h-4 flex-none"></span>
          <span class="flex-1 truncate">{{ opt.label }}</span>
          <span
            v-if="opt.live !== undefined"
            class="wc-dd-dot lg-tip"
            :class="opt.live ? 'wc-dd-dot-live' : 'wc-dd-dot-off'"
            :data-tip="opt.live ? $t('Channel is live') : $t('Channel is off or not connected')"
           :aria-label="opt.live ? $t('Channel is live') : $t('Channel is off or not connected')"></span>
        </li>
        <li v-if="search && !filteredOptions.length" class="wc-dd-empty">{{ $t('No matches') }}</li>
      </ul>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    modelValue: { type: String, default: '' },
    // Each option: { value, label, icon?, dotColor?, live? }
    options: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'All' },
  },

  emits: ['update:modelValue'],

  data() {
    return { open: false, search: '' };
  },

  computed: {
    selected() {
      return this.options.find(o => o.value === this.modelValue) || null;
    },
    filteredOptions() {
      if (!this.search) return this.options;
      const q = this.search.toLowerCase();
      return this.options.filter(o => o.label.toLowerCase().includes(q));
    },
  },

  mounted() {
    this._onDocClick = e => { if (!this.$el.contains(e.target)) this.close(); };
    document.addEventListener('click', this._onDocClick);
  },

  unmounted() {
    document.removeEventListener('click', this._onDocClick);
  },

  methods: {
    toggle() {
      this.open = !this.open;
      if (this.open) {
        this.$nextTick(() => this.$refs.search && this.$refs.search.focus());
      }
    },
    close() {
      this.open = false;
      this.search = '';
    },
    select(value) {
      this.$emit('update:modelValue', value);
      this.close();
    },
  },
};
</script>

<style scoped>
.wc-dd { position: relative; }
.wc-select { width: 100%; border: 1px solid #e2e8f0; border-radius: 0.6rem; padding: 0.45rem 0.6rem; font-size: 0.8rem; background: #fff; }
.wc-select:focus { outline: none; border-color: #d946ef; box-shadow: 0 0 0 3px rgba(217, 70, 239, 0.15); }
.wc-dd-trigger { display: flex; align-items: center; gap: 8px; cursor: pointer; }

.wc-dd-panel {
  position: absolute; z-index: 20; left: 0; right: 0; top: calc(100% + 4px);
  background: #fff; border: 1px solid #eef1f6; border-radius: 0.65rem;
  box-shadow: 0 12px 28px -8px rgba(15, 23, 42, 0.18); padding: 0.3rem;
}
.wc-dd-search-wrap {
  display: flex; align-items: center; gap: 6px;
  padding: 0.4rem 0.55rem; margin-bottom: 0.25rem;
  border: 1px solid #e2e8f0; border-radius: 0.5rem; background: #f8fafc;
}
.wc-dd-search { flex: 1; border: none; background: transparent; font-size: 0.8rem; outline: none; min-width: 0; }
.wc-dd-list { max-height: 220px; overflow-y: auto; margin: 0; padding: 0; list-style: none; }
.wc-dd-item { display: flex; align-items: center; gap: 8px; padding: 0.45rem 0.6rem; border-radius: 0.5rem; font-size: 0.8rem; cursor: pointer; }
.wc-dd-item:hover { background: #fdf4ff; }
.wc-dd-empty { padding: 0.6rem; text-align: center; font-size: 0.78rem; color: #94a3b8; }
.wc-dd-dot { width: 6px; height: 6px; border-radius: 9999px; flex: none; }
.wc-dd-dot-live { background: #22c55e; }
.wc-dd-dot-off { background: #cbd5e1; }
.wc-dd-status-dot { width: 8px; height: 8px; border-radius: 9999px; }

html.dark .wc-select, html.dark .wc-dd-panel { background: #1e293b; border-color: rgba(255, 255, 255, 0.08); color: #e2e8f0; }
html.dark .wc-dd-search-wrap { background: rgba(255, 255, 255, 0.04); border-color: rgba(255, 255, 255, 0.1); }
html.dark .wc-dd-search { color: #e2e8f0; }
html.dark .wc-dd-item:hover { background: rgba(217, 70, 239, 0.12); }
</style>

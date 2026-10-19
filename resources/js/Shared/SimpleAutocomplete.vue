<template>
  <div class="relative sac-root">
    <icons v-if="icon" :name="icon" class="sac-icon" />
    <input
      ref="input"
      v-model="search"
      type="text"
      class="sac-input"
      :class="{ 'sac-input-icon': icon, 'sac-input-clear': clearable && modelValue }"
      :placeholder="placeholder"
      @focus="onFocus"
      @blur="onBlur"
    />
    <button v-if="clearable && modelValue" type="button" class="sac-clear" @click="clear">
      <icons name="cross" class="w-3.5 h-3.5" />
    </button>
    <Teleport to="body">
      <div v-if="open" class="sac-dropdown" :style="dropdownStyle">
        <button v-for="opt in results" :key="opt.value" type="button" class="sac-option" @mousedown.prevent="select(opt)">
          <icons v-if="opt.icon" :name="opt.icon" class="w-3.5 h-3.5 sac-option-icon" />
          {{ opt.label }}
        </button>
        <div v-if="!results.length" class="sac-empty">{{ $t('No matches.') }}</div>
      </div>
    </Teleport>
  </div>
</template>

<script>
export default {
  props: {
    modelValue: { type: [String, Number], default: '' },
    options: { type: Array, default: () => [] }, // [{ value, label, icon? }]
    placeholder: { type: String, default: null },
    icon: { type: String, default: null },
    clearable: { type: Boolean, default: true },
  },

  emits: ['update:modelValue'],

  data() {
    return { search: '', open: false, dropdownStyle: {} };
  },

  computed: {
    selectedLabel() {
      return this.options.find(o => String(o.value) === String(this.modelValue))?.label || '';
    },
    results() {
      const q = this.search.trim().toLowerCase();
      if (!this.open || this.search === this.selectedLabel) return this.options;
      return this.options.filter(o => o.label.toLowerCase().includes(q));
    },
  },

  watch: {
    modelValue: {
      immediate: true,
      handler() {
        this.search = this.selectedLabel;
      },
    },
  },

  beforeUnmount() {
    window.removeEventListener('resize', this.positionDropdown);
    window.removeEventListener('scroll', this.positionDropdown, true);
  },

  methods: {
    onFocus() {
      this.open = true;
      this.search = '';
      this.$nextTick(this.positionDropdown);
      window.addEventListener('resize', this.positionDropdown);
      window.addEventListener('scroll', this.positionDropdown, true);
    },
    positionDropdown() {
      if (!this.$refs.input) return;
      const rect = this.$refs.input.getBoundingClientRect();
      this.dropdownStyle = { position: 'fixed', left: rect.left + 'px', top: rect.bottom + 6 + 'px', width: rect.width + 'px', zIndex: 9999 };
    },
    select(opt) {
      this.$emit('update:modelValue', opt.value);
      this.search = opt.label;
      this.open = false;
    },
    clear() {
      this.$emit('update:modelValue', '');
      this.search = '';
    },
    onBlur() {
      setTimeout(() => {
        this.search = this.selectedLabel;
        this.open = false;
        window.removeEventListener('resize', this.positionDropdown);
        window.removeEventListener('scroll', this.positionDropdown, true);
      }, 150);
    },
  },
};
</script>

<style scoped>
.sac-icon {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  width: 15px;
  height: 15px;
  color: #94a3b8;
  pointer-events: none;
  z-index: 1;
}
.sac-input {
  width: 100%;
  border: 1.5px solid rgba(15, 23, 42, 0.14);
  background: rgba(255, 255, 255, 0.85);
  border-radius: 10px;
  padding: 9px 12px;
  font-size: 0.85rem;
}
.sac-input.sac-input-icon { padding-left: 34px; }
.sac-input.sac-input-clear { padding-right: 32px; }
.sac-input:focus {
  outline: none;
  border-color: #818cf8;
  box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.22);
}
.sac-clear {
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  color: #94a3b8;
}
.sac-clear:hover { color: #dc2626; }
.sac-dropdown {
  max-height: 260px;
  overflow-y: auto;
  background: #fff;
  border: 1px solid rgba(15, 23, 42, 0.1);
  border-radius: 12px;
  box-shadow: 0 16px 32px rgba(15, 23, 42, 0.16);
  padding: 6px;
}
.sac-option {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  text-align: left;
  padding: 7px 10px;
  border-radius: 8px;
  font-size: 0.85rem;
  color: #1e293b;
}
.sac-option:hover { background: rgba(99, 102, 241, 0.08); }
.sac-option-icon { color: #6366f1; flex: none; }
.sac-empty { padding: 10px 8px; font-size: 0.8rem; color: #94a3b8; text-align: center; }

html.dark .sac-input { background: rgba(255, 255, 255, 0.06); border-color: rgba(255, 255, 255, 0.14); color: #e2e8f0; }
html.dark .sac-dropdown { background: #1e293b; border-color: rgba(255, 255, 255, 0.1); }
html.dark .sac-option:hover { background: rgba(255, 255, 255, 0.06); }
html.dark .sac-option { color: #e2e8f0; }
</style>

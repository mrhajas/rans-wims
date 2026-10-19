<template>
  <div class="ap-root">
    <div class="ap-chips" v-if="modelValue.length">
      <span v-for="item in modelValue" :key="item.id" class="ap-chip">
        <icons name="user" class="w-3 h-3" />
        {{ item.label }}
        <button type="button" class="ap-chip-remove" :aria-label="$t('Remove')" @click="remove(item)">
          <icons name="cross" class="w-3 h-3" />
        </button>
      </span>
    </div>

    <div class="relative">
      <icons name="search" class="ap-input-icon" />
      <input
        ref="input"
        v-model="search"
        type="text"
        class="ap-input"
        :placeholder="placeholder || $t('Search users...')"
        @focus="onFocus"
        @blur="onBlur"
      />
      <Teleport to="body">
        <div v-if="open" class="ap-dropdown" :style="dropdownStyle">
          <button v-for="p in results" :key="p.id" type="button" class="ap-option" @mousedown.prevent="select(p)">
            <span class="ap-option-avatar"><icons name="user" class="w-3.5 h-3.5" /></span>
            <span class="ap-option-name">{{ p.name }}</span>
          </button>
          <div v-if="!results.length" class="ap-empty">{{ $t('No matches.') }}</div>
        </div>
      </Teleport>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    modelValue: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    placeholder: { type: String, default: null },
  },

  emits: ['update:modelValue'],

  data() {
    return { search: '', open: false, dropdownStyle: {} };
  },

  computed: {
    selectedIds() {
      return new Set(this.modelValue.map(v => v.id));
    },
    results() {
      const q = this.search.trim().toLowerCase();
      const match = name => !q || name.toLowerCase().includes(q);
      return this.users.filter(p => !this.selectedIds.has(p.id) && match(p.name)).slice(0, 30);
    },
  },

  beforeUnmount() {
    window.removeEventListener('resize', this.positionDropdown);
    window.removeEventListener('scroll', this.positionDropdown, true);
  },

  methods: {
    onFocus() {
      this.open = true;
      this.$nextTick(this.positionDropdown);
      window.addEventListener('resize', this.positionDropdown);
      window.addEventListener('scroll', this.positionDropdown, true);
    },
    // Teleported + fixed-positioned from the input's own rect (not a plain
    // absolutely-positioned sibling) so the list always renders on top,
    // never clipped by the Add Task modal's own overflow-y:auto scrolling —
    // same technique AutoComplete.vue already uses for this exact problem.
    positionDropdown() {
      if (!this.$refs.input) return;
      const rect = this.$refs.input.getBoundingClientRect();
      this.dropdownStyle = {
        position: 'fixed',
        left: rect.left + 'px',
        top: rect.bottom + 6 + 'px',
        width: rect.width + 'px',
        zIndex: 9999,
      };
    },
    select(person) {
      this.$emit('update:modelValue', [...this.modelValue, { id: person.id, label: person.name }]);
      this.search = '';
      this.$refs.input?.focus();
      this.$nextTick(this.positionDropdown);
    },
    remove(item) {
      this.$emit('update:modelValue', this.modelValue.filter(v => v.id !== item.id));
    },
    onBlur() {
      setTimeout(() => {
        this.open = false;
        window.removeEventListener('resize', this.positionDropdown);
        window.removeEventListener('scroll', this.positionDropdown, true);
      }, 150);
    },
  },
};
</script>

<style scoped>
.ap-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 8px;
}
.ap-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 6px 4px 10px;
  border-radius: 9999px;
  font-size: 0.76rem;
  font-weight: 600;
  background: rgba(99, 102, 241, 0.1);
  color: #4338ca;
  border: 1px solid rgba(99, 102, 241, 0.2);
}
.ap-chip-remove {
  display: inline-flex !important;
  align-items: center;
  justify-content: center;
  height: 18px !important;
  width: 18px !important;
  min-height: 0 !important;
  padding: 0 !important;
  border-radius: 9999px;
  background: rgba(67, 56, 202, 0.16);
  color: #4338ca;
  margin-left: 2px;
  flex: none;
}
.ap-chip-remove:hover {
  background: rgba(220, 38, 38, 0.18);
  color: #dc2626;
}
.ap-chip-remove :deep(div) {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
}
.ap-chip-remove :deep(svg) {
  width: 9px;
  height: 9px;
  flex: none;
}
.ap-input-icon {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  width: 15px;
  height: 15px;
  color: #94a3b8;
  pointer-events: none;
}
.ap-input {
  width: 100%;
  border: 1.5px solid rgba(15, 23, 42, 0.14);
  background: rgba(255, 255, 255, 0.85);
  border-radius: 10px;
  padding: 9px 12px 9px 34px;
  font-size: 0.85rem;
}
.ap-input:focus {
  outline: none;
  border-color: #818cf8;
  box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.22);
}
.ap-dropdown {
  max-height: 260px;
  overflow-y: auto;
  background: #fff;
  border: 1px solid rgba(15, 23, 42, 0.1);
  border-radius: 12px;
  box-shadow: 0 16px 32px rgba(15, 23, 42, 0.16);
  padding: 6px;
}
.ap-option {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  text-align: left;
  padding: 7px 8px;
  border-radius: 8px;
}
.ap-option:hover {
  background: rgba(99, 102, 241, 0.08);
}
.ap-option-avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 22px;
  width: 22px;
  border-radius: 9999px;
  background: rgba(99, 102, 241, 0.14);
  color: #4f46e5;
  flex: none;
}
.ap-option-name {
  font-size: 0.85rem;
  font-weight: 600;
  color: #1e293b;
}
.ap-empty {
  padding: 10px 8px;
  font-size: 0.8rem;
  color: #94a3b8;
  text-align: center;
}

html.dark .ap-input {
  background: rgba(255, 255, 255, 0.06);
  border-color: rgba(255, 255, 255, 0.14);
  color: #e2e8f0;
}
html.dark .ap-dropdown {
  background: #1e293b;
  border-color: rgba(255, 255, 255, 0.1);
}
html.dark .ap-option:hover {
  background: rgba(255, 255, 255, 0.06);
}
html.dark .ap-option-name {
  color: #e2e8f0;
}
</style>

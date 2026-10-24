<template>
  <div ref="root" class="sm" :class="{ 'is-open': open }">
    <button type="button" class="lg-btn tone sm-btn" :style="{ '--tone': current.tone }" aria-haspopup="listbox" :aria-expanded="open" :disabled="disabled" @click="open = !open" @keydown.esc="open = false">
      <span class="tone-ico"><icons :name="current.icon" class="w-4 h-4" /></span>
      <span class="sm-label">{{ modelValue ? current.label : $t('Sort') }}</span>
      <icons name="chevron-down" class="w-3.5 h-3.5 sm-caret" />
    </button>
    <transition name="sm-pop">
      <ul v-if="open" class="sm-panel" role="listbox">
        <li v-for="o in options" :key="o.key || 'default'" role="option" :aria-selected="(modelValue || null) === o.key">
          <button type="button" class="sm-opt" :class="{ 'is-on': (modelValue || null) === o.key }" :style="{ '--tone': o.tone }" @click="pick(o.key)">
            <span class="sm-opt-ico"><icons :name="o.icon" class="w-4 h-4" /></span>
            <span class="sm-opt-text"><b>{{ o.label }}</b><small>{{ o.hint }}</small></span>
            <icons v-if="(modelValue || null) === o.key" name="tick" class="w-4 h-4 sm-tick" />
          </button>
        </li>
      </ul>
    </transition>
  </div>
</template>

<script>
// Auto sorting dropdown of the item lines: it reorders the table at once, and the same order is used by the value report, the print, the PDF and the Excel file.
export default {
  props: { modelValue: { type: String, default: null }, disabled: Boolean },
  emits: ['update:modelValue'],
  data() { return { open: false }; },
  computed: {
    options() {
      return [
        { key: null, label: this.$t('Entered order'), hint: this.$t('As the count was entered'), icon: 'list', tone: '#64748b' },
        { key: 'az', label: this.$t('A - Z'), hint: this.$t('Item name'), icon: 'search', tone: '#2563eb' },
        { key: 'category', label: this.$t('Categories'), hint: this.$t('Grouped by category'), icon: 'folder', tone: '#7c3aed' },
        { key: 'high', label: this.$t('High - Low'), hint: this.$t('Largest physical count first'), icon: 'up', tone: '#059669' },
        { key: 'low', label: this.$t('Low - High'), hint: this.$t('Smallest physical count first'), icon: 'down', tone: '#ea580c' },
      ];
    },
    current() { return this.modelValue ? this.options.find(o => o.key === this.modelValue) : { ...this.options[0], icon: 'adjustments', tone: '#0891b2' }; },
  },
  mounted() { document.addEventListener('click', this.away); },
  beforeUnmount() { document.removeEventListener('click', this.away); },
  methods: {
    away(e) { if (this.open && this.$refs.root && !this.$refs.root.contains(e.target)) this.open = false; },
    pick(key) { this.$emit('update:modelValue', key); this.open = false; },
  },
};
</script>

<style scoped>
.sm { position: relative; display: inline-block; }
.sm-btn { min-width: 8.5rem; justify-content: flex-start; }
.sm-label { font-weight: 800; }
.sm-caret { margin-left: auto; transition: transform 0.18s ease; }
.is-open .sm-caret { transform: rotate(180deg); }
.sm-panel { position: absolute; z-index: 40; top: calc(100% + 8px); right: 0; min-width: 17rem; margin: 0; padding: 6px; list-style: none; border-radius: 1rem; background: var(--lg-card, #fff); border: 1px solid var(--lg-line, #e6ebf3); box-shadow: 0 24px 40px -16px rgba(15, 23, 42, 0.35), 0 4px 10px rgba(15, 23, 42, 0.08); }
.sm-opt { display: flex; align-items: center; gap: 10px; width: 100%; padding: 8px 10px; border: 0; border-radius: 0.75rem; background: transparent; color: inherit; text-align: left; cursor: pointer; transition: background 0.15s ease; }
.sm-opt:hover { background: color-mix(in srgb, var(--tone) 10%, transparent); }
.sm-opt.is-on { background: color-mix(in srgb, var(--tone) 15%, transparent); box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--tone) 40%, transparent); }
.sm-opt-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 30px; height: 30px; border-radius: 10px; color: #fff; background: linear-gradient(145deg, var(--tone), color-mix(in srgb, var(--tone) 65%, #000)); box-shadow: 0 6px 12px -6px var(--tone); }
.sm-opt-text { display: grid; min-width: 0; line-height: 1.25; }
.sm-opt-text b { font-size: 0.86rem; font-weight: 800; }
.sm-opt-text small { font-size: 0.72rem; color: var(--lg-mute, #64748b); }
.sm-tick { margin-left: auto; color: var(--tone); }
.sm-pop-enter-active, .sm-pop-leave-active { transition: opacity 0.14s ease, transform 0.14s ease; }
.sm-pop-enter-from, .sm-pop-leave-to { opacity: 0; transform: translateY(-6px) scale(0.98); }
@media (max-width: 560px) { .sm-panel { right: auto; left: 0; min-width: min(17rem, 86vw); } }
</style>

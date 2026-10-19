<template>
  <div class="sx-pick">
    <div class="sx-pick-bar">
      <div class="sx-search"><icons name="search" class="w-4 h-4" /><input v-model="q" type="text" :placeholder="$t('Search modules and abilities')" :aria-label="$t('Search modules and abilities')" autocomplete="off" /></div>
      <span class="sx-count"><b>{{ modelValue.length }}</b> {{ $t('of') }} {{ total }} {{ $t('abilities selected') }}</span>
      <button type="button" class="lg-btn t-slate" @click="setAll(true)"><icons name="tick" class="w-4 h-4" />{{ q ? $t('Select shown') : $t('Select all') }}</button>
      <button type="button" class="lg-btn t-slate" @click="setAll(false)"><icons name="cross" class="w-4 h-4" />{{ q ? $t('Clear shown') : $t('Clear all') }}</button>
    </div>
    <div class="sx-pick-body">
      <ul class="sx-mods" role="listbox" :aria-label="$t('Modules')">
        <li v-for="m in shown" :key="m.key">
          <button type="button" class="sx-mod" :class="{ 'is-on': current && current.key === m.key }" role="option" :aria-selected="current && current.key === m.key" @click="cur = m.key">
            <span class="sx-box" :class="{ 'is-on': allOf(m), 'is-part': someOf(m) }" @click.stop="toggleAll(m, !allOf(m))"><svg v-if="allOf(m)" viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7" /></svg><i v-else-if="someOf(m)"></i></span>
            <span class="sx-mod-name">{{ m.label }}</span>
            <span class="sx-mod-n" :class="{ 'is-full': allOf(m) }">{{ countOf(m) }}/{{ m.abilities.length }}</span>
          </button>
        </li>
        <li v-if="!shown.length" class="sx-none">{{ $t('Nothing matches your search.') }}</li>
      </ul>
      <div class="sx-abs" v-if="current">
        <div class="sx-abs-head"><b>{{ current.label }}</b><button type="button" class="lg-btn t-slate" @click="toggleAll(current, !allOf(current))"><icons :name="allOf(current) ? 'cross' : 'tick'" class="w-4 h-4" />{{ allOf(current) ? $t('Clear this module') : $t('Select this module') }}</button></div>
        <div class="sx-abs-grid">
          <label v-for="a in current.abilities" :key="a.name" class="sx-check"><input type="checkbox" class="sx-in" :checked="modelValue.includes(a.name)" @change="toggle(a.name, $event.target.checked)" /><span class="sx-box"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7" /></svg></span><span class="sx-lbl">{{ a.label }}</span></label>
        </div>
      </div>
      <div v-else class="sx-abs sx-none">{{ $t('Pick a module on the left to see its abilities.') }}</div>
    </div>
  </div>
</template>

<script>
// Picks the abilities a simulation account may use: modules on the left (tick the box to take the whole module), the abilities of the open module on the right.
export default {
  props: { modules: Array, modelValue: { type: Array, default: () => [] } },
  emits: ['update:modelValue'],
  data() { return { q: '', cur: '' }; },
  computed: {
    total() { return this.modules.reduce((n, m) => n + m.abilities.length, 0); },
    shown() {
      const q = this.q.trim().toLowerCase();
      if (!q) return this.modules;
      return this.modules.map(m => ((m.label || '').toLowerCase().includes(q) ? m : { ...m, abilities: m.abilities.filter(a => (a.label + ' ' + a.name).toLowerCase().includes(q)) })).filter(m => m.abilities.length);
    },
    current() { return this.shown.find(m => m.key === this.cur) || this.shown[0] || null; },
  },
  methods: {
    countOf(m) { return m.abilities.filter(a => this.modelValue.includes(a.name)).length; },
    allOf(m) { return m.abilities.length > 0 && this.countOf(m) === m.abilities.length; },
    someOf(m) { const c = this.countOf(m); return c > 0 && c < m.abilities.length; },
    toggle(n, on) { const s = new Set(this.modelValue); on ? s.add(n) : s.delete(n); this.$emit('update:modelValue', [...s]); },
    toggleAll(m, on) { const s = new Set(this.modelValue); m.abilities.forEach(a => (on ? s.add(a.name) : s.delete(a.name))); this.$emit('update:modelValue', [...s]); },
    setAll(on) { const s = new Set(this.modelValue); this.shown.forEach(m => m.abilities.forEach(a => (on ? s.add(a.name) : s.delete(a.name)))); this.$emit('update:modelValue', [...s]); },
  },
};
</script>

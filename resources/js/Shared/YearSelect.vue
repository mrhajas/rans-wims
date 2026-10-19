<template>
  <div ref="root" class="wys-root">
    <button type="button" class="wys-btn" :aria-haspopup="'listbox'" :aria-expanded="open" :aria-label="label" @click="toggle">
      {{ modelValue }}
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6" /></svg>
    </button>
    <div v-if="open" ref="list" class="wys-list" role="listbox" :aria-label="label" @scroll.passive="onScroll">
      <button
        v-for="y in years"
        :key="y"
        type="button"
        role="option"
        class="wys-item"
        :class="{ 'is-selected': y === modelValue }"
        :aria-selected="y === modelValue"
        :data-year="y"
        @click="choose(y)"
      >{{ y }}</button>
    </div>
  </div>
</template>

<script>
// Year dropdown that opens on 5 years either side of the current year and loads 5 more whenever the list is scrolled
// to its top (older) or bottom (newer) — no 100-year list to wade through.
const HARD_MIN = 1900;
const HARD_MAX = 2200;
const WINDOW = 5;

export default {
  name: 'YearSelect',

  props: {
    modelValue: { type: Number, required: true },
    min: { type: Number, default: HARD_MIN },
    max: { type: Number, default: HARD_MAX },
    label: { type: String, default: 'Year' },
  },

  emits: ['update:modelValue'],

  data() {
    return { open: false, from: 0, to: 0 };
  },

  computed: {
    floor() { return Math.max(this.min, HARD_MIN); },
    ceil() { return Math.min(this.max, HARD_MAX); },
    years() {
      const out = [];
      for (let y = this.from; y <= this.to; y++) out.push(y);

      return out;
    },
  },

  beforeUnmount() {
    document.removeEventListener('mousedown', this.onOutside, true);
  },

  methods: {
    toggle() {
      this.open ? this.close() : this.openList();
    },
    openList() {
      this.from = Math.max(this.floor, this.modelValue - WINDOW);
      this.to = Math.min(this.ceil, this.modelValue + WINDOW);
      this.open = true;
      document.addEventListener('mousedown', this.onOutside, true);
      this.$nextTick(() => {
        const list = this.$refs.list;
        const cur = list && list.querySelector('.is-selected');
        if (list && cur) list.scrollTop = cur.offsetTop - list.clientHeight / 2 + cur.offsetHeight / 2;
      });
    },
    close() {
      this.open = false;
      document.removeEventListener('mousedown', this.onOutside, true);
    },
    onOutside(e) {
      if (this.$refs.root && !this.$refs.root.contains(e.target)) this.close();
    },
    choose(y) {
      this.$emit('update:modelValue', y);
      this.close();
    },
    onScroll() {
      const list = this.$refs.list;
      if (!list) return;

      // Near the top: add 5 older years, keeping the row that was on screen where it was.
      if (list.scrollTop < 24 && this.from > this.floor) {
        const anchor = list.scrollHeight;
        this.from = Math.max(this.floor, this.from - WINDOW);
        this.$nextTick(() => { list.scrollTop += list.scrollHeight - anchor; });
      } else if (list.scrollHeight - list.scrollTop - list.clientHeight < 24 && this.to < this.ceil) {
        this.to = Math.min(this.ceil, this.to + WINDOW);
      }
    },
  },
};
</script>

<style scoped>
.wys-root { position: relative; display: inline-block; }
.wys-btn { display: inline-flex; align-items: center; gap: 0.2rem; min-height: 0; padding: 0.2rem 0.35rem 0.2rem 0.5rem; border: 1px solid #e3e9f2; border-radius: 0.5rem; background: #fff; color: #101c33; font-size: 0.78rem; font-weight: 800; font-variant-numeric: tabular-nums; cursor: pointer; }
.wys-btn:hover, .wys-btn[aria-expanded='true'] { border-color: #a5b4fc; }
.wys-btn svg { width: 0.7rem; height: 0.7rem; }
.wys-list { position: absolute; z-index: 5; top: calc(100% + 4px); left: 50%; transform: translateX(-50%); width: 4.8rem; max-height: 9.6rem; overflow-y: auto; overscroll-behavior: contain; padding: 0.25rem; border: 1px solid #e3e9f2; border-radius: 0.7rem; background: #fff; box-shadow: 0 14px 30px -10px rgba(15, 23, 42, 0.35); }
.wys-item { display: block; width: 100%; min-height: 0; padding: 0.3rem 0; border: 0; border-radius: 0.45rem; background: transparent; color: #101c33; font-size: 0.8rem; font-weight: 700; font-variant-numeric: tabular-nums; text-align: center; cursor: pointer; }
.wys-item:hover { background: #e5edf7; }
.wys-item.is-selected { background: #1c3f66; color: #fff; }
html.dark .wys-btn { background: #1e293b; border-color: rgba(255, 255, 255, 0.1); color: #e2e8f0; }
html.dark .wys-list { background: #0f172a; border-color: rgba(255, 255, 255, 0.1); }
html.dark .wys-item { color: #e2e8f0; }
html.dark .wys-item:hover { background: #334155; }
html.dark .wys-item.is-selected { background: #4f46e5; }
@media (max-width: 480px) {
  .wys-btn { font-size: 0.9rem; padding: 0.35rem 0.5rem; }
  .wys-item { padding: 0.5rem 0; font-size: 0.95rem; }
  .wys-list { max-height: 12rem; }
}
</style>

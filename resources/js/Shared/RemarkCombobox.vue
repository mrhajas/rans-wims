<template>
  <div class="rcb" :class="{ 'is-open': open }">
    <label v-if="label" class="rcb-label" :for="id">{{ label }}</label>
    <div class="rcb-field">
      <input
        :id="id"
        ref="input"
        v-model="text"
        type="text"
        class="rcb-input"
        role="combobox"
        autocomplete="off"
        :aria-expanded="open ? 'true' : 'false'"
        :aria-controls="id + '-list'"
        :placeholder="placeholder"
        maxlength="300"
        @focus="open = true"
        @input="open = true; active = 0"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.enter="pickActive"
        @keydown.esc.stop="open = false"
        @blur="closeSoon"
      />
      <button v-if="text" type="button" class="rcb-clear" tabindex="-1" :aria-label="$t('Clear')" @mousedown.prevent="clear"><icons name="cross" class="w-3 h-3" /></button>
      <button type="button" class="rcb-chevron" tabindex="-1" :aria-label="$t('Show remarks')" @mousedown.prevent="toggle"><icons name="chevron-down" class="w-4 h-4" /></button>
    </div>
    <transition name="rcb-slide">
      <ul v-if="open && (shown.length || text)" :id="id + '-list'" class="rcb-list" role="listbox">
        <li v-for="(o, i) in shown" :key="o" role="option" :aria-selected="i === active ? 'true' : 'false'" :class="{ on: i === active, chosen: o === text }" @mousedown.prevent="choose(o)" @mousemove="active = i">
          <icons name="tick" class="w-3.5 h-3.5 rcb-tick" /><span>{{ o }}</span>
        </li>
        <li v-if="!shown.length" class="rcb-own"><icons name="edit" class="w-3.5 h-3.5" /><span>{{ $t('Use your own words: :text', { text }) }}</span></li>
      </ul>
    </transition>
  </div>
</template>

<script>
import Icons from '@/Shared/Icons.vue';

// A search-as-you-type list of ready-made remarks the person can pick, or type their own words into (the text is the value).
export default {
  components: { Icons },
  props: { modelValue: { type: String, default: '' }, options: { type: Array, default: () => [] }, label: String, placeholder: String, id: { type: String, default: 'remark' } },
  emits: ['update:modelValue'],
  data() { return { open: false, active: 0, closeT: null }; },
  computed: {
    text: {
      get() { return this.modelValue || ''; },
      set(v) { this.$emit('update:modelValue', v); },
    },
    shown() {
      const q = this.text.trim().toLowerCase();
      if (!q) return this.options;
      const exact = this.options.some(o => o.toLowerCase() === q);
      return exact ? this.options : this.options.filter(o => o.toLowerCase().includes(q));
    },
  },
  methods: {
    choose(o) { this.text = o; this.open = false; },
    clear() { this.text = ''; this.open = true; this.$refs.input.focus(); },
    toggle() { this.open = !this.open; if (this.open) this.$refs.input.focus(); },
    move(d) { this.open = true; if (this.shown.length) this.active = (this.active + d + this.shown.length) % this.shown.length; },
    pickActive(e) { if (this.open && this.shown[this.active] && this.text.trim().toLowerCase() !== this.shown[this.active].toLowerCase()) { e.preventDefault(); e.stopPropagation(); this.choose(this.shown[this.active]); } else { this.open = false; } },
    closeSoon() { clearTimeout(this.closeT); this.closeT = setTimeout(() => { this.open = false; }, 120); },
  },
  beforeUnmount() { clearTimeout(this.closeT); },
};
</script>

<style scoped>
.rcb { display: grid; gap: 0.35rem; }
.rcb-label { font-size: 0.72rem; font-weight: 800; letter-spacing: 0.03em; text-transform: uppercase; color: #64748b; }
.rcb-field { position: relative; }
.rcb-input { width: 100%; padding: 0.6rem 4.2rem 0.6rem 0.8rem; border-radius: 0.65rem; border: 1.5px solid #e5e7eb; background: #fff; font-size: 0.88rem; color: #111827; transition: border-color 0.15s, box-shadow 0.15s; }
.rcb-input:focus { outline: none; border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.16); }
.rcb-clear, .rcb-chevron { position: absolute; top: 50%; transform: translateY(-50%); display: inline-flex; align-items: center; justify-content: center; width: 1.6rem; height: 1.6rem; border-radius: 999px; color: #64748b; }
.rcb-clear { right: 2.1rem; } .rcb-chevron { right: 0.4rem; transition: transform 0.2s; }
.rcb-clear:hover, .rcb-chevron:hover { background: rgba(100, 116, 139, 0.14); }
.rcb.is-open .rcb-chevron { transform: translateY(-50%) rotate(180deg); }
.rcb-list { list-style: none; margin: 0; padding: 0.3rem; max-height: 11rem; overflow: auto; border-radius: 0.7rem; border: 1px solid #e5e7eb; background: #fff; box-shadow: 0 10px 24px -14px rgba(15, 23, 42, 0.35); }
.rcb-list li { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.65rem; border-radius: 0.5rem; font-size: 0.85rem; color: #1f2937; cursor: pointer; }
.rcb-list li.on { background: rgba(22, 163, 74, 0.1); }
.rcb-tick { color: transparent; flex: none; } .rcb-list li.chosen .rcb-tick { color: #16a34a; }
.rcb-own { color: #64748b !important; cursor: default !important; font-style: italic; }
.rcb-slide-enter-active, .rcb-slide-leave-active { transition: opacity 0.16s ease, transform 0.16s ease; transform-origin: top; }
.rcb-slide-enter-from, .rcb-slide-leave-to { opacity: 0; transform: translateY(-4px) scaleY(0.96); }
@media (prefers-reduced-motion: reduce) { .rcb-slide-enter-active, .rcb-slide-leave-active, .rcb-chevron { transition: none; } }
html.dark .rcb-input, html.dark .rcb-list { background: #0f172a; border-color: rgba(255, 255, 255, 0.14); color: #e2e8f0; }
html.dark .rcb-list li { color: #e2e8f0; }
</style>

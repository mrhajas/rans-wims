<template>
  <div class="ps" :class="{ 'is-open': open }">
    <div v-if="modelValue" class="ps-chosen">
      <span class="ps-ico"><icons name="user" class="w-4 h-4" /></span>
      <div class="ps-txt"><b>{{ modelValue.name }}</b><small>{{ modelValue.sub }}</small></div>
      <button type="button" class="lg-icon-btn" :aria-label="$t('Change')" @click="clear"><icons name="cross" class="w-4 h-4" /></button>
    </div>
    <template v-else>
      <input ref="input" v-model="q" type="search" autocomplete="off" :placeholder="placeholder" @focus="show" @input="debounced" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="enter" @blur="hide" />
      <div v-if="open" class="ps-list">
        <button v-for="(r, i) in rows" :key="r.id" type="button" class="ps-row" :class="{ 'is-on': i === cur, 'is-taken': !!r.card }" @mousedown.prevent="pick(r)">
          <span class="ps-name">{{ r.name }}</span><small>{{ r.sub }}</small>
          <em v-if="r.card" class="ps-has">{{ $t('Already has :n', { n: r.card }) }}</em>
        </button>
        <div v-if="!rows.length && !busy" class="ps-none">{{ $t('No match.') }}</div>
        <div v-if="busy" class="ps-none">{{ $t('Searching...') }}</div>
      </div>
    </template>
  </div>
</template>

<script>
export default {
  props: { modelValue: Object, source: { type: String, required: true }, categoryId: [Number, String], placeholder: String },
  emits: ['update:modelValue'],
  data() { return { q: '', rows: [], open: false, busy: false, cur: 0, timer: null, seq: 0 }; },
  watch: { source() { this.rows = []; this.q = ''; }, categoryId() { this.rows = []; } },
  methods: {
    show() { this.open = true; this.load(); },
    hide() { setTimeout(() => (this.open = false), 120); },
    debounced() { clearTimeout(this.timer); this.timer = setTimeout(this.load, 220); },
    async load() {
      const n = ++this.seq; this.busy = true;
      try { const { data } = await window.axios.get(route('id-passes.lookup.people'), { params: { source: this.source, q: this.q, category_id: this.categoryId || undefined } }); if (n === this.seq) { this.rows = data; this.cur = 0; } } catch (e) { if (n === this.seq) this.rows = []; }
      if (n === this.seq) this.busy = false;
    },
    move(d) { if (this.rows.length) this.cur = (this.cur + d + this.rows.length) % this.rows.length; },
    enter() { if (this.rows[this.cur]) this.pick(this.rows[this.cur]); },
    pick(r) { if (r.card) return; this.$emit('update:modelValue', r); this.open = false; this.q = ''; },
    clear() { this.$emit('update:modelValue', null); this.$nextTick(() => this.$refs.input && this.$refs.input.focus()); },
  },
};
</script>

<style scoped>
.ps { position: relative; }
.ps-chosen { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 14px; background: var(--lg-card, #fff); box-shadow: 0 8px 16px -10px rgba(15, 37, 64, 0.5), 0 0 0 1px var(--lg-line, #e2e8f0); }
.ps-ico { width: 32px; height: 32px; border-radius: 10px; display: grid; place-items: center; background: rgba(21, 101, 192, 0.12); color: #1565c0; flex: none; }
.ps-txt { flex: 1; min-width: 0; display: grid; } .ps-txt small { color: var(--lg-mute, #64748b); font-size: 0.76rem; }
.ps-list { position: absolute; z-index: 30; left: 0; right: 0; top: calc(100% + 6px); max-height: 280px; overflow: auto; border-radius: 14px; background: var(--lg-card, #fff); box-shadow: 0 22px 40px -18px rgba(15, 37, 64, 0.55), 0 0 0 1px var(--lg-line, #e2e8f0); padding: 6px; }
.ps-row { width: 100%; display: grid; grid-template-columns: 1fr auto; gap: 0 10px; text-align: left; padding: 8px 10px; border-radius: 10px; border: 0; background: transparent; cursor: pointer; }
.ps-row small { grid-column: 1; color: var(--lg-mute, #64748b); font-size: 0.74rem; }
.ps-row.is-on, .ps-row:hover { background: rgba(21, 101, 192, 0.1); }
.ps-row.is-taken { opacity: 0.55; cursor: not-allowed; }
.ps-has { grid-column: 2; grid-row: 1 / span 2; align-self: center; font-style: normal; font-size: 0.7rem; font-weight: 800; color: #b45309; background: rgba(217, 119, 6, 0.14); padding: 3px 8px; border-radius: 999px; }
.ps-name { font-weight: 700; }
.ps-none { padding: 12px; color: var(--lg-mute, #64748b); font-size: 0.85rem; }
</style>

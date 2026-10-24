<script>
// A drop-in for the browser's own <select>: the same markup (<css-select v-model="x"><option value="1">One</option>...</css-select>, v-for and v-if included) shows the WIMS
// dropdown instead: a styled button that opens a panel with a search box (when the list is long), the options, keyboard control, light and dark. Because it reads the
// <option> children itself, a page changes from <select> to <css-select> without touching its options or its handlers. A multiple select, which the panel cannot show, is
// left as the browser's own control.
import { Fragment, Comment, Text, h, Teleport, Transition } from 'vue';

const textOf = node => {
  if (node == null) return '';
  if (typeof node === 'string' || typeof node === 'number') return String(node);
  if (Array.isArray(node)) return node.map(textOf).join('');
  if (node.type === Text) return String(node.children ?? '');
  if (node.children) return textOf(node.children);
  return '';
};

function collect(nodes, out, group) {
  (nodes || []).forEach(n => {
    if (n == null || n.type === Comment) return;
    if (n.type === Fragment) return collect(n.children, out, group);
    if (n.type === 'optgroup') return collect(n.children?.default ? n.children.default() : n.children, out, n.props?.label || group);
    if (n.type === 'option') {
      const label = textOf(n.children).replace(/\s+/g, ' ').trim();
      const p = n.props || {};
      const has = 'value' in p;
      out.push({ value: has ? p.value : label, label: label || (has && p.value !== '' && p.value != null ? String(p.value) : ''), disabled: p.disabled === '' || p.disabled === true, group, selected: p.selected === '' || p.selected === true });
    }
  });
  return out;
}

export default {
  name: 'CssSelect',
  props: {
    modelValue: { default: undefined },
    modelModifiers: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
    placeholder: { type: String, default: null },
    multiple: { type: Boolean, default: false },
    searchFrom: { type: Number, default: 7 }, // a search box is shown from this many options
  },
  emits: ['update:modelValue', 'change', 'input'],
  data() {
    return { open: false, query: '', active: -1, style: {}, picked: undefined };
  },
  methods: {
    options() {
      const slot = this.$slots.default ? this.$slots.default() : [];
      return collect(slot, [], null);
    },
    same(a, b) {
      return String(a ?? '') === String(b ?? '');
    },
    current(opts) {
      const v = this.modelValue !== undefined ? this.modelValue : this.picked;
      let hit = opts.find(o => this.same(o.value, v));
      if (!hit && v === undefined) hit = opts.find(o => o.selected) || null;
      return hit || null;
    },
    pos() {
      const el = this.$refs.btn;
      if (!el) return;
      const r = el.getBoundingClientRect();
      const below = window.innerHeight - r.bottom;
      const up = below < 260 && r.top > below;
      this.style = { position: 'fixed', left: Math.max(8, Math.min(r.left, window.innerWidth - Math.max(r.width, 220) - 8)) + 'px', minWidth: Math.max(r.width, 180) + 'px', zIndex: 10000, ...(up ? { bottom: window.innerHeight - r.top + 6 + 'px' } : { top: r.bottom + 6 + 'px' }) };
    },
    toggle() {
      if (this.disabled) return;
      this.open ? this.close() : this.show();
    },
    show() {
      this.query = '';
      this.open = true;
      const opts = this.options();
      this.active = Math.max(0, opts.findIndex(o => this.same(o.value, this.modelValue !== undefined ? this.modelValue : this.picked)));
      this.$nextTick(() => {
        this.pos();
        this.$refs.search && this.$refs.search.focus();
        const el = this.$refs.panel && this.$refs.panel.querySelector('.cs-opt.is-on');
        el && el.scrollIntoView({ block: 'nearest' });
      });
      window.addEventListener('resize', this.pos);
      window.addEventListener('scroll', this.onScroll, true);
      document.addEventListener('mousedown', this.away, true);
    },
    close() {
      this.open = false;
      window.removeEventListener('resize', this.pos);
      window.removeEventListener('scroll', this.onScroll, true);
      document.removeEventListener('mousedown', this.away, true);
    },
    onScroll(e) {
      if (this.$refs.panel && this.$refs.panel.contains(e.target)) return;
      this.pos();
    },
    away(e) {
      if ((this.$refs.panel && this.$refs.panel.contains(e.target)) || (this.$refs.btn && this.$refs.btn.contains(e.target))) return;
      this.close();
    },
    choose(o) {
      if (o.disabled) return;
      let v = o.value;
      if (this.modelModifiers.number && v !== '' && v != null && !isNaN(Number(v))) v = Number(v);
      this.picked = v;
      this.$emit('update:modelValue', v);
      // what an <select @change="..."> handler expects to find on the event
      const target = { value: v == null ? '' : String(v), selectedIndex: this.options().findIndex(x => x === o) };
      const ev = { type: 'change', target, currentTarget: target, preventDefault() {}, stopPropagation() {} };
      this.$emit('input', ev);
      this.$emit('change', ev);
      this.close();
      this.$refs.btn && this.$refs.btn.focus();
    },
    key(e, filtered) {
      if (e.key === 'ArrowDown') { e.preventDefault(); this.active = Math.min(filtered.length - 1, this.active + 1); this.scrollActive(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); this.active = Math.max(0, this.active - 1); this.scrollActive(); }
      else if (e.key === 'Enter') { e.preventDefault(); if (filtered[this.active]) this.choose(filtered[this.active]); }
      else if (e.key === 'Escape') { e.preventDefault(); this.close(); this.$refs.btn && this.$refs.btn.focus(); }
      else if (e.key === 'Tab') this.close();
    },
    scrollActive() {
      this.$nextTick(() => {
        const el = this.$refs.panel && this.$refs.panel.querySelector('.cs-opt.is-active');
        el && el.scrollIntoView({ block: 'nearest' });
      });
    },
    btnKey(e) {
      if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key) && !this.open) { e.preventDefault(); this.show(); }
    },
  },
  beforeUnmount() {
    this.close();
  },
  render() {
    // a list the panel cannot show (several choices at once) stays the browser's own control
    if (this.multiple) {
      return h('select', { ...this.$attrs, multiple: true, disabled: this.disabled, onChange: e => this.$emit('change', e) }, this.$slots.default ? this.$slots.default() : []);
    }
    const opts = this.options();
    const cur = this.current(opts);
    const q = this.query.trim().toLowerCase();
    const filtered = q ? opts.filter(o => o.label.toLowerCase().includes(q)) : opts;
    const shown = cur ? cur.label : this.placeholder || '';
    const isEmpty = !cur || cur.value === '' || cur.value == null;

    // the button is the one root element, so the page's own classes, its scoped styles and its attributes (id, aria-label...) land on it exactly as they did on the <select>
    const sizer = h('span', { class: 'cs-sizer', 'aria-hidden': 'true' }, opts.map(o => h('i', o.label || '\u00a0')));
    let panel = null;
    if (this.open) {
      let lastGroup = null;
      const rows = [];
      filtered.forEach((o, i) => {
        if (o.group && o.group !== lastGroup) rows.push(h('div', { class: 'cs-group' }, o.group));
        lastGroup = o.group;
        rows.push(h('button', { type: 'button', role: 'option', class: ['cs-opt', { 'is-on': cur === o, 'is-active': i === this.active, 'is-off': o.disabled }], onMousedown: e => e.preventDefault(), onClick: e => { e.stopPropagation(); this.choose(o); }, onMouseenter: () => (this.active = i) }, [h('span', o.label || '\u00a0'), cur === o ? h('svg', { viewBox: '0 0 20 20', class: 'cs-tick' }, [h('path', { d: 'M4.5 10.5l3.5 3.5 7.5-8', fill: 'none', stroke: 'currentColor', 'stroke-width': '2.2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' })]) : null]));
      });
      panel = h(Teleport, { to: 'body' }, [h('div', { ref: 'panel', class: 'cs-panel', style: this.style, onKeydown: e => this.key(e, filtered), onClick: e => e.stopPropagation() }, [
        opts.length >= this.searchFrom ? h('input', { ref: 'search', class: 'cs-search', type: 'text', value: this.query, placeholder: this.$t ? this.$t('Search...') : 'Search...', autocomplete: 'off', onInput: e => { this.query = e.target.value; this.active = 0; }, onKeydown: e => this.key(e, filtered) }) : null,
        h('div', { class: 'cs-list', role: 'listbox' }, rows.length ? rows : [h('div', { class: 'cs-none' }, this.$t ? this.$t('No matches.') : 'No matches.')]),
      ])]);
    }

    return h('button', {
      ref: 'btn', type: 'button', disabled: this.disabled, class: ['cs-btn', { 'is-open': this.open, 'is-empty': isEmpty }],
      'aria-haspopup': 'listbox', 'aria-expanded': this.open ? 'true' : 'false', onClick: this.toggle, onKeydown: this.btnKey,
    }, [
      h('span', { class: 'cs-val' }, [h('span', { class: 'cs-txt' }, shown || '\u00a0'), sizer]),
      h('svg', { class: 'cs-caret', viewBox: '0 0 20 20', 'aria-hidden': 'true' }, [h('path', { d: 'M5.5 7.5l4.5 4.5 4.5-4.5', fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' })]),
      panel,
    ]);
  },
};
</script>

<style>
.cs-btn { position: relative; display: inline-flex; align-items: center; justify-content: space-between; gap: 8px; max-width: 100%; min-height: 2.25rem; padding: 0.4rem 0.7rem; border: 1px solid var(--lg-line, #d1d5db); border-radius: 0.75rem; background: var(--lg-card, #fff); color: var(--lg-ink, #0f172a); font: inherit; font-size: 0.875rem; line-height: 1.25; text-align: left; cursor: pointer; vertical-align: middle; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
.cs-btn:hover:not(:disabled) { border-color: #818cf8; }
.cs-btn:focus-visible, .cs-btn.is-open { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.22); }
.cs-btn:disabled { opacity: 0.6; cursor: not-allowed; background: var(--lg-soft, #f1f5f9); }
.cs-btn.is-bad { border-color: #ef4444; }
.cs-btn.is-empty .cs-txt { color: #94a3b8; }
/* the label and an invisible stack of every option share one grid cell, so the button is as wide as the longest option (like the browser's own select) */
.cs-val { display: grid; flex: 1 1 auto; min-width: 0; overflow: hidden; }
.cs-txt { grid-area: 1 / 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cs-sizer { grid-area: 1 / 1; display: grid; height: 0; overflow: hidden; visibility: hidden; pointer-events: none; }
.cs-sizer i { font-style: normal; white-space: nowrap; height: 0; }
.cs-caret { flex: none; width: 16px; height: 16px; color: #64748b; transition: transform 0.15s ease; }
.cs-btn.is-open .cs-caret { transform: rotate(180deg); }
.cs-panel { display: flex; flex-direction: column; max-height: min(320px, 60vh); padding: 6px; border-radius: 0.9rem; background: #fff; border: 1px solid rgba(15, 23, 42, 0.1); box-shadow: 0 22px 40px -14px rgba(15, 23, 42, 0.35), 0 4px 10px rgba(15, 23, 42, 0.08); color: #0f172a; font-size: 0.875rem; text-align: left; }
.cs-search { flex: none; width: 100%; margin-bottom: 6px; padding: 7px 10px; border-radius: 0.6rem; border: 1px solid rgba(15, 23, 42, 0.14); background: #f8fafc; font-size: 0.85rem; color: inherit; }
.cs-search:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2); }
.cs-list { overflow-y: auto; display: grid; gap: 1px; }
.cs-group { padding: 8px 10px 3px; font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; }
.cs-opt { display: flex; align-items: center; justify-content: space-between; gap: 10px; width: 100%; padding: 7px 10px; border: 0; border-radius: 0.55rem; background: transparent; color: inherit; font: inherit; font-size: 0.85rem; text-align: left; cursor: pointer; }
.cs-opt.is-active { background: rgba(99, 102, 241, 0.1); }
.cs-opt.is-on { font-weight: 700; color: #4338ca; background: rgba(99, 102, 241, 0.14); }
.cs-opt.is-off { opacity: 0.45; cursor: not-allowed; }
.cs-tick { flex: none; width: 15px; height: 15px; }
.cs-none { padding: 12px 8px; text-align: center; font-size: 0.8rem; color: #94a3b8; }
html.dark .cs-btn { background: rgba(255, 255, 255, 0.06); border-color: rgba(255, 255, 255, 0.16); color: #e2e8f0; }
html.dark .cs-panel { background: #1e293b; border-color: rgba(255, 255, 255, 0.12); color: #e2e8f0; }
html.dark .cs-search { background: rgba(255, 255, 255, 0.06); border-color: rgba(255, 255, 255, 0.14); }
html.dark .cs-opt.is-active { background: rgba(255, 255, 255, 0.08); }
html.dark .cs-opt.is-on { color: #a5b4fc; background: rgba(129, 140, 248, 0.2); }
</style>

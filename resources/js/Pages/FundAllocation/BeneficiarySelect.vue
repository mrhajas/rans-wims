<template>
  <div class="fx-bsel" ref="root">
    <div class="fx-bsel-box" :class="{ 'is-error': error, 'is-open': open }" @click="focusInput">
      <span v-if="kind" class="fx-bsel-ico" :class="'is-' + kind"><icons :name="ico(kind)" class="w-3.5 h-3.5" /></span>
      <input
        ref="input"
        v-model="query"
        class="fx-bsel-input"
        autocomplete="off"
        :placeholder="placeholder"
        :title="selectedName"
        @focus="openList"
        @input="openList"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.enter.prevent="pickActive"
        @keydown.esc="close"
        @blur="onBlur"
      />
      <button v-if="hasValue" type="button" class="fx-bsel-clear" :aria-label="$t('Clear')" @mousedown.prevent="clear"><icons name="cross" class="w-3.5 h-3.5" /></button>
    </div>
    <ul v-if="open" class="fx-bsel-list" role="listbox" @mousedown.prevent>
      <template v-for="g in groups" :key="g.key">
        <li class="fx-bsel-group"><icons :name="ico(g.key)" class="w-3 h-3" />{{ g.title }}<span>{{ g.total }}</span></li>
        <li
          v-for="o in g.items"
          :key="g.key + o.id"
          class="fx-bsel-opt"
          :class="{ 'is-active': flat[active] && flat[active].kind === g.key && flat[active].id === o.id, 'is-on': kind === g.key && currentId === o.id }"
          role="option"
          @mouseenter="active = flat.findIndex(f => f.kind === g.key && f.id === o.id)"
          @click="pick(g.key, o)"
        >
          <span class="fx-bsel-av" :class="'is-' + g.key">{{ initials(o.name) }}</span>
          <span class="fx-bsel-name">{{ o.name }}</span>
          <small v-if="o.sub">{{ o.sub }}</small>
        </li>
        <li v-if="g.total > g.items.length" class="fx-bsel-more">{{ $t('Type to narrow down') }} ({{ g.total - g.items.length }} {{ $t('more') }})</li>
        <li v-if="!g.items.length" class="fx-bsel-none">{{ $t('No match') }}</li>
      </template>
    </ul>
  </div>
</template>

<script>
const LIMIT = 8;

export default {
  props: {
    contacts: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    warehouseId: { type: [Number, String], default: null },
    contactId: { type: [Number, String], default: null },
    userId: { type: [Number, String], default: null },
    legacy: { type: String, default: '' },
    error: { type: Boolean, default: false },
    placeholder: { type: String, default: '' },
  },
  emits: ['select'],
  data() {
    return { open: false, query: '', active: 0, searching: false };
  },
  computed: {
    kind() {
      return this.contactId ? 'contact' : this.userId ? 'user' : this.warehouseId ? 'project' : null;
    },
    currentId() {
      return this.contactId || this.userId || this.warehouseId || null;
    },
    selectedName() {
      if (this.contactId) return (this.contacts.find(c => c.id === this.contactId) || {}).name || '';
      if (this.userId) return (this.users.find(u => u.id === this.userId) || {}).name || '';
      if (this.warehouseId) return (this.projects.find(w => w.id === this.warehouseId) || {}).name || '';
      return this.legacy || '';
    },
    hasValue() {
      return !!(this.contactId || this.userId || this.warehouseId || this.legacy);
    },
    groups() {
      const q = this.searching ? this.query.trim().toLowerCase() : '';
      const f = (list, sub) => {
        const rows = list.map(x => ({ id: x.id, name: x.name, sub: sub(x) })).filter(x => !q || (x.name + ' ' + (x.sub || '')).toLowerCase().includes(q));
        return { total: rows.length, items: rows.slice(0, LIMIT) };
      };
      const c = f(this.contacts, x => x.category || '');
      const u = f(this.users, x => (x.username ? '@' + x.username : ''));
      const w = f(this.projects, x => x.status || '');
      return [
        { key: 'contact', title: this.$t('Contacts'), ...c },
        { key: 'user', title: this.$t('Users'), ...u },
        { key: 'project', title: this.$t('Projects'), ...w },
      ];
    },
    flat() {
      return this.groups.flatMap(g => g.items.map(o => ({ kind: g.key, id: o.id, name: o.name })));
    },
  },
  watch: {
    // keep the visible text in step with the chosen record
    selectedName: {
      immediate: true,
      handler(v) {
        if (!this.open) this.query = v;
      },
    },
  },
  methods: {
    ico(k) {
      return { contact: 'user', user: 'users', project: 'building' }[k] || 'user';
    },
    initials(n) {
      return String(n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase();
    },
    focusInput() {
      this.$refs.input.focus();
    },
    openList(e) {
      this.searching = !!(e && e.type === 'input');
      this.open = true;
      this.active = 0;
      if (e && e.type === 'focus') this.$refs.input.select();
    },
    close() {
      this.open = false;
      this.searching = false;
      this.query = this.selectedName;
    },
    onBlur() {
      setTimeout(() => this.close(), 120);
    },
    move(d) {
      if (!this.open) return this.openList();
      const n = this.flat.length;
      if (n) this.active = (this.active + d + n) % n;
    },
    pickActive() {
      const f = this.flat[this.active];
      if (f) this.pick(f.kind, f);
    },
    pick(kind, o) {
      this.$emit('select', { contact_id: kind === 'contact' ? o.id : null, beneficiary_user_id: kind === 'user' ? o.id : null, beneficiary_warehouse_id: kind === 'project' ? o.id : null, name: o.name });
      this.open = false;
      this.searching = false;
      this.query = o.name;
    },
    clear() {
      this.$emit('select', { contact_id: null, beneficiary_user_id: null, beneficiary_warehouse_id: null, name: '' });
      this.query = '';
      this.open = false;
    },
  },
};
</script>

<style scoped>
.fx-bsel { position: relative; }
.fx-bsel-box { display: flex; align-items: center; gap: 8px; padding: 0 0.5rem 0 0.6rem; min-height: 38px; border: 1px solid var(--lg-line); border-radius: 0.8rem; background: var(--lg-card); transition: border-color 0.15s ease, box-shadow 0.15s ease; cursor: text; }
.fx-bsel-box.is-open { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18); }
.fx-bsel-box.is-error { border-color: #ef4444; }
.fx-bsel-input { flex: 1; min-width: 0; border: 0 !important; outline: none !important; box-shadow: none !important; background: transparent; padding: 0.45rem 0 !important; font-size: 0.85rem; color: var(--lg-ink); text-overflow: ellipsis; }
.fx-bsel-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 22px; height: 22px; border-radius: 8px; color: #fff; }
.fx-bsel-ico.is-contact, .fx-bsel-av.is-contact { background: linear-gradient(145deg, #22d3ee, #0e7490); }
.fx-bsel-ico.is-user, .fx-bsel-av.is-user { background: linear-gradient(145deg, #a78bfa, #6d28d9); }
.fx-bsel-ico.is-project, .fx-bsel-av.is-project { background: linear-gradient(145deg, #fbbf24, #b45309); }
.fx-bsel-clear { display: inline-flex; padding: 0 !important; min-height: 0 !important; color: #94a3b8; background: none; border: 0; cursor: pointer; }
.fx-bsel-clear:hover { color: #ef4444; }
.fx-bsel-list { position: absolute; z-index: 60; left: 0; right: 0; top: calc(100% + 6px); margin: 0; padding: 6px; list-style: none; max-height: 330px; overflow-y: auto; border-radius: 16px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: 0 22px 40px -16px rgba(15, 37, 64, 0.5), 0 2px 6px rgba(15, 37, 64, 0.1); min-width: 260px; }
.fx-bsel-group { display: flex; align-items: center; gap: 6px; padding: 8px 10px 4px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: #64748b; position: sticky; top: -6px; background: var(--lg-card); }
.fx-bsel-group span { margin-left: auto; padding: 0 7px; border-radius: 9999px; background: var(--lg-soft); font-size: 0.66rem; }
.fx-bsel-opt { display: flex; align-items: center; gap: 10px; padding: 6px 10px; border-radius: 12px; cursor: pointer; font-size: 0.84rem; color: var(--lg-ink); }
.fx-bsel-opt.is-active { background: rgba(59, 130, 246, 0.12); }
.fx-bsel-opt.is-on { font-weight: 800; }
.fx-bsel-av { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 26px; height: 26px; border-radius: 9999px; color: #fff; font-size: 0.66rem; font-weight: 800; }
.fx-bsel-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.fx-bsel-opt small { color: #94a3b8; font-size: 0.7rem; }
.fx-bsel-more, .fx-bsel-none { padding: 4px 12px 6px; font-size: 0.72rem; color: #94a3b8; }
</style>

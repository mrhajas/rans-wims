<template>
  <admin-layout :title="$t('Compliance Categories')">
    <div class="lg-page px-4 md:px-0 cat-page">
      <page-header
        icon="collection"
        :title="$t('Compliance Categories')"
        :subtitle="$t('Grouped compliance sub-types used across the register.')"
      >
        <button type="button" class="lg-btn lg-btn-primary" @click="openCreate()">
          <icons name="plus" class="w-4 h-4" /> {{ $t('Add Category') }}
        </button>
      </page-header>

      <stat-tiles :items="statTiles" />

      <div class="cat-toolbar">
        <div class="cat-search">
          <icons name="search" class="w-4 h-4 cat-search-ico" />
          <input v-model="search" type="text" :placeholder="$t('Search categories...')" class="cat-search-input" />
        </div>
        <div class="cat-toolbar-btns">
          <button type="button" class="lg-btn lg-btn-soft" @click="expandAll">{{ $t('Expand All') }}</button>
          <button type="button" class="lg-btn lg-btn-soft" @click="collapseAll">{{ $t('Collapse All') }}</button>
        </div>
      </div>

      <div class="cmp-segments">
        <div v-for="(group, gi) in groups" :key="group" v-show="filteredCategories[group] && filteredCategories[group].length" class="cmp-segment">
          <button type="button" class="cmp-segment-header" :aria-expanded="isExpanded(group)" @click="toggleExpanded(group)">
            <span class="lg-card-ico" :class="'lg-ico-' + groupTone(gi)"><icons :name="groupIcon(group)" class="w-5 h-5" /></span>
            <span class="cmp-segment-name">{{ group }}</span>
            <span class="lg-chip lg-chip-slate">{{ filteredCategories[group] ? filteredCategories[group].length : 0 }}</span>
            <icons name="chevron-down" class="w-4 h-4 cmp-segment-chevron flex-none ml-auto" :class="{ 'cmp-segment-chevron-open': isExpanded(group) }" />
          </button>
          <div class="cmp-segment-body" :class="{ 'cmp-segment-body-open': isExpanded(group) }">
            <div class="cmp-segment-body-inner">
              <div v-for="cat in filteredCategories[group]" :key="cat.id" class="cmp-child-row">
                <input
                  v-if="editingId === cat.id"
                  :ref="el => setEditInputRef(el, cat.id)"
                  v-model="editingName"
                  type="text"
                  class="cmp-child-edit-input"
                  @keyup.enter="saveInline(cat)"
                  @keyup.esc="cancelInline"
                />
                <span v-else class="cmp-child-name">{{ cat.name }}</span>
                <span class="cmp-child-actions">
                  <template v-if="editingId === cat.id">
                    <button type="button" class="lg-icon-btn is-green lg-tip" :data-tip="$t('Save')" @click="saveInline(cat)" :aria-label="$t('Save')"><icons name="tick" class="w-4 h-4" /></button>
                    <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Cancel')" @click="cancelInline" :aria-label="$t('Cancel')"><icons name="cross" class="w-4 h-4" /></button>
                  </template>
                  <template v-else>
                    <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Edit')" @click="startInline(cat)" :aria-label="$t('Edit')"><icons name="edit" class="w-4 h-4" /></button>
                    <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Delete')" @click="destroy(cat)" :aria-label="$t('Delete')"><icons name="trash" class="w-4 h-4" /></button>
                  </template>
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div v-if="!hasAnyResults" class="cmp-empty-state">
        <icons name="search" class="w-6 h-6" />
        <span>{{ $t('No categories match your search.') }}</span>
      </div>
    </div>

    <modal :show="showModal" max-width="md" transparent @close="showModal = false">
      <div class="lg-page cmp-modal">
        <header class="cmp-modal-head">
          <span class="lg-card-ico" :class="form.id ? 'lg-ico-amber' : 'lg-ico-indigo'"><icons :name="form.id ? 'edit' : 'plus'" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ form.id ? $t('Edit Category') : $t('Add Category') }}</h2>
            <p class="lg-card-sub">{{ $t('Group') }} / {{ $t('Name') }}</p>
          </div>
          <button type="button" class="lg-icon-btn" :aria-label="$t('Cancel')" @click="showModal = false"><icons name="cross" class="w-4 h-4" /></button>
        </header>
        <div class="cmp-modal-body">
          <div class="lg-fields cmp-modal-fields">
            <div class="lg-span-all">
              <label class="cmp-label">{{ $t('Group') }}</label>
              <select v-model="form.group" class="cmp-ctl">
                <option v-for="g in groups" :key="g" :value="g">{{ g }}</option>
              </select>
            </div>
            <div class="lg-span-all">
              <label class="cmp-label">{{ $t('Name') }}</label>
              <input v-model="form.name" type="text" class="cmp-ctl" />
            </div>
          </div>
        </div>
        <footer class="cmp-modal-foot">
          <button type="button" class="lg-btn lg-btn-soft" @click="showModal = false">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" @click="save"><icons name="tick" class="w-4 h-4" /> {{ $t('Save') }}</button>
        </footer>
      </div>
    </modal>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import Modal from '@/Jetstream/Modal.vue';

const GROUP_TONES = ['navy', 'emerald', 'indigo', 'amber', 'rose', 'teal', 'violet', 'sky', 'pink', 'slate'];

const GROUP_ICONS = {
  'Company/Business': 'bag',
  'Government/Statutory': 'building',
  Construction: 'cog',
  Financial: 'calculator',
  Insurance: 'lock-closed',
  Vehicles: 'truck',
  'Assets/Equipment': 'collection',
  People: 'users',
  Contracts: 'doc-text',
  Project: 'folder',
  Other: 'doc',
};

export default {
  components: { AdminLayout, PageHeader, StatTiles, Modal },

  props: { categories: Object, groups: Array },

  data() {
    return {
      showModal: false,
      search: '',
      // Default collapsed — every segment starts closed until the user
      // opens it (or types a search that auto-expands matches).
      expandedGroups: new Set(),
      form: { id: null, name: '', group: this.groups[0] },
      editingId: null,
      editingName: '',
      editInputRefs: {},
    };
  },

  computed: {
    filteredCategories() {
      const q = this.search.trim().toLowerCase();
      if (!q) return this.categories;
      const result = {};
      this.groups.forEach(group => {
        result[group] = (this.categories[group] || []).filter(c => c.name.toLowerCase().includes(q));
      });
      return result;
    },
    statTiles() {
      const lists = this.groups.map(g => (this.categories[g] || []).length);
      const matching = this.groups.reduce((n, g) => n + ((this.filteredCategories[g] || []).length), 0);
      return [
        { icon: 'collection', tone: 'indigo', label: this.$t('Categories'), value: lists.reduce((a, b) => a + b, 0) },
        { icon: 'folder', tone: 'amber', label: this.$t('Groups'), value: lists.filter(n => n > 0).length },
        { icon: 'search', tone: 'teal', label: this.$t('Showing'), value: matching },
      ];
    },
    hasAnyResults() {
      return Object.values(this.filteredCategories).some(list => list && list.length);
    },
  },

  watch: {
    search(q) {
      if (q.trim()) {
        this.groups.forEach(group => {
          if (this.filteredCategories[group] && this.filteredCategories[group].length) {
            this.expandedGroups.add(group);
          }
        });
      }
    },
  },

  methods: {
    groupTone(index) {
      return GROUP_TONES[index % GROUP_TONES.length];
    },
    groupIcon(group) {
      return GROUP_ICONS[group] || 'folder';
    },
    isExpanded(group) {
      return this.expandedGroups.has(group);
    },
    toggleExpanded(group) {
      if (this.expandedGroups.has(group)) {
        this.expandedGroups.delete(group);
      } else {
        this.expandedGroups.add(group);
      }
    },
    expandAll() {
      this.expandedGroups = new Set(this.groups);
    },
    collapseAll() {
      this.expandedGroups = new Set();
    },
    openCreate() {
      this.form = { id: null, name: '', group: this.groups[0] };
      this.showModal = true;
    },
    openEdit(category) {
      this.form = { id: category.id, name: category.name, group: category.group };
      this.showModal = true;
    },
    save() {
      const payload = { name: this.form.name, group: this.form.group };
      if (this.form.id) {
        this.$inertia.patch(this.route('compliance-records.categories.update', this.form.id), payload, { onSuccess: () => (this.showModal = false) });
      } else {
        this.$inertia.post(this.route('compliance-records.categories.store'), payload, { onSuccess: () => (this.showModal = false) });
      }
    },
    async destroy(category) {
      if (await this.$confirm(this.$t('Delete this category?'), { variant: 'danger' })) {
        this.$inertia.delete(this.route('compliance-records.categories.destroy', category.id));
      }
    },
    setEditInputRef(el, id) {
      if (el) this.editInputRefs[id] = el;
    },
    startInline(cat) {
      this.editingId = cat.id;
      this.editingName = cat.name;
      this.$nextTick(() => {
        const input = this.editInputRefs[cat.id];
        if (input) {
          input.focus();
          input.select();
        }
      });
    },
    cancelInline() {
      this.editingId = null;
      this.editingName = '';
    },
    saveInline(cat) {
      const name = this.editingName.trim();
      if (!name || name === cat.name) {
        this.cancelInline();
        return;
      }
      this.$inertia.patch(
        this.route('compliance-records.categories.update', cat.id),
        { name, group: cat.group },
        { preserveScroll: true, onSuccess: () => this.cancelInline() }
      );
    },
  },
};
</script>

<style scoped>
.cat-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 1rem; padding: 0.7rem 0.85rem; border-radius: 22px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); }
.cat-search { position: relative; flex: 1 1 240px; min-width: 0; max-width: 28rem; }
.cat-search-ico { position: absolute; left: 0.9rem; top: 50%; transform: translateY(-50%); color: var(--lg-faint); pointer-events: none; }
.cat-search-input { width: 100%; padding: 0.55rem 0.9rem 0.55rem 2.5rem; font-size: 0.85rem; border-radius: 9999px; border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-ink); transition: border-color 0.15s ease, box-shadow 0.15s ease; }
.cat-search-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.18); background: var(--lg-card); }
.cat-toolbar-btns { display: flex; gap: 8px; margin-left: auto; flex-wrap: wrap; }

.cmp-label { display: block; margin-bottom: 0.3rem; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-mute); }
.cmp-ctl { display: block; width: 100%; padding: 0.6rem 0.8rem; font-size: 0.875rem; border-radius: 0.85rem; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-ink); transition: border-color 0.15s ease, box-shadow 0.15s ease; }
.cmp-ctl:hover { border-color: rgba(99, 102, 241, 0.6); }
.cmp-ctl:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.18); }

.cmp-segments { display: flex; flex-direction: column; gap: 0.75rem; }
.cmp-segment { border: 1px solid var(--lg-line); background: var(--lg-card); border-radius: 22px; overflow: hidden; box-shadow: var(--lg-shadow); }
.cmp-segment-header { width: 100%; display: flex; align-items: center; gap: 0.8rem; padding: 0.8rem 1.1rem; text-align: left; background: transparent; transition: background 0.15s ease; }
.cmp-segment-header:hover { background: var(--lg-soft); }
.cmp-segment-name { min-width: 0; font-weight: 800; color: var(--lg-ink); font-size: 0.95rem; overflow-wrap: anywhere; }
.cmp-segment-chevron { color: var(--lg-faint); transition: transform 0.25s ease; }
.cmp-segment-chevron-open { transform: rotate(180deg); }

.cmp-segment-body { display: grid; grid-template-rows: 0fr; transition: grid-template-rows 0.25s ease; }
.cmp-segment-body-open { grid-template-rows: 1fr; }
.cmp-segment-body-inner { overflow: hidden; min-height: 0; }

.cmp-child-row { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.55rem 1.1rem 0.55rem 4.4rem; border-top: 1px solid var(--lg-line); }
.cmp-child-row:hover { background: var(--lg-soft); }
.cmp-child-name { min-width: 0; color: var(--lg-ink); font-size: 0.85rem; font-weight: 600; overflow-wrap: anywhere; }
.cmp-child-edit-input { flex: 1; min-width: 0; font-size: 0.85rem; color: var(--lg-ink); padding: 0.4rem 0.75rem; border: 1px solid #a5b4fc; border-radius: 0.75rem; background: var(--lg-card); }
.cmp-child-edit-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.18); }
.cmp-child-actions { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
@media (max-width: 640px) { .cmp-child-row { padding-left: 1.1rem; } }

.cmp-empty-state { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; border: 1px dashed var(--lg-line); border-radius: 22px; padding: 2.5rem 1rem; text-align: center; color: var(--lg-faint); font-size: 0.85rem; }

/* Add / edit category modal (teleported to body, so it brings its own .lg-page variables) */
.cmp-modal { overflow: hidden; border-radius: 22px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: 0 30px 60px -20px rgba(15, 23, 42, 0.55); }
.cmp-modal-head { display: flex; align-items: center; gap: 12px; padding: 1.1rem 1.25rem; border-bottom: 1px solid var(--lg-line); background: linear-gradient(135deg, rgba(224, 233, 250, 0.7), rgba(255, 251, 235, 0.55)); }
.cmp-modal-body { padding: 1.25rem; }
.cmp-modal-fields { grid-template-columns: 1fr; }
.cmp-modal-foot { display: flex; align-items: center; justify-content: flex-end; gap: 8px; padding: 0.85rem 1.25rem; border-top: 1px solid var(--lg-line); background: var(--lg-soft); }
.cmp-modal .lg-card-title, .cmp-modal .lg-card-sub { text-align: left; }

html.dark .cmp-modal-head { background: linear-gradient(135deg, rgba(28, 63, 102, 0.45), rgba(15, 23, 42, 0.7)); }
html.dark .cmp-modal { background: #0f172a; }
html.dark .cmp-child-edit-input { border-color: rgba(129, 140, 248, 0.5); }
</style>

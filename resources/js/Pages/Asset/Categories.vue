<template>
  <admin-layout :title="$t('Asset Categories')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="folder" :title="$t('Asset Categories')" :subtitle="$t('Organize assets into groups and sub-types.')">
        <button type="button" class="lg-btn lg-btn-primary" @click="openCreate(null)">
          <icons name="plus" class="w-4 h-4" />{{ $t('Add Category') }}
        </button>
      </page-header>

      <div class="lg-card ac-filters relative z-10">
        <div class="ac-toolbar">
          <div class="ac-search">
            <icons name="search" class="w-4 h-4 ac-search-ico" />
            <input v-model="search" type="text" :placeholder="$t('Search categories...')" class="ac-search-input" />
          </div>
          <div class="ac-toolbar-btns">
            <button type="button" class="lg-btn lg-btn-soft" @click="expandAll"><icons name="chevron-down" class="w-4 h-4" />{{ $t('Expand All') }}</button>
            <button type="button" class="lg-btn lg-btn-soft" @click="collapseAll"><icons name="chevron-down" class="w-4 h-4 transform rotate-180" />{{ $t('Collapse All') }}</button>
          </div>
        </div>
      </div>

      <div class="ast-segments">
        <div v-for="group in filteredCategories" :key="group.id" class="ast-segment">
          <div class="ast-segment-header" role="button" tabindex="0" @click="toggleExpanded(group.id)" @keyup.enter="toggleExpanded(group.id)">
            <span class="lg-card-ico lg-ico-indigo ast-segment-icon"><icons :name="groupIcon(group.name)" class="w-5 h-5" /></span>
            <span class="ast-segment-name">{{ group.name }}</span>
            <span class="lg-chip lg-chip-indigo">{{ group.children.length }}</span>
            <span class="ast-segment-actions" @click.stop>
              <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Edit Group')" :aria-label="$t('Edit Group')" @click="openEdit(group)"><icons name="edit" class="w-4 h-4" /></button>
              <button type="button" class="lg-icon-btn is-green lg-tip" :data-tip="$t('Add Sub-type')" :aria-label="$t('Add Sub-type')" @click="openCreate(group)"><icons name="plus" class="w-4 h-4" /></button>
              <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Delete Group')" :aria-label="$t('Delete Group')" @click="destroy(group)"><icons name="trash" class="w-4 h-4" /></button>
            </span>
            <icons name="chevron-down" class="w-4 h-4 ast-segment-chevron flex-none" :class="{ 'ast-segment-chevron-open': isExpanded(group.id) }" />
          </div>
          <div class="ast-segment-body" :class="{ 'ast-segment-body-open': isExpanded(group.id) }">
            <div class="ast-segment-body-inner">
              <div v-for="child in group.children" :key="child.id" class="ast-child-row">
                <input
                  v-if="editingId === child.id"
                  :ref="el => setEditInputRef(el, child.id)"
                  v-model="editingName"
                  type="text"
                  class="ast-child-edit-input"
                  @keyup.enter="saveInline(child)"
                  @keyup.esc="cancelInline"
                />
                <span v-else class="ast-child-name"><span class="ast-child-dot"></span>{{ child.name }}</span>
                <span class="ast-child-actions">
                  <template v-if="editingId === child.id">
                    <button type="button" class="lg-icon-btn is-green lg-tip" :data-tip="$t('Save')" :aria-label="$t('Save')" @click="saveInline(child)"><icons name="tick" class="w-4 h-4" /></button>
                    <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Cancel')" :aria-label="$t('Cancel')" @click="cancelInline"><icons name="cross" class="w-4 h-4" /></button>
                  </template>
                  <template v-else>
                    <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Edit')" :aria-label="$t('Edit')" @click="startInline(child)"><icons name="edit" class="w-4 h-4" /></button>
                    <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Delete')" :aria-label="$t('Delete')" @click="destroy(child)"><icons name="trash" class="w-4 h-4" /></button>
                  </template>
                </span>
              </div>
              <div v-if="!group.children.length" class="ast-child-empty">{{ $t('No sub-types yet.') }}</div>
            </div>
          </div>
        </div>
        <div v-if="!filteredCategories.length" class="ast-empty-state">{{ $t('No categories match your search.') }}</div>
      </div>
    </div>

    <modal :show="showModal" max-width="md" @close="showModal = false">
      <div class="lg-page ac-modal">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="folder" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ form.id ? $t('Edit Category') : $t('Add Category') }}</h2></div>
        </header>
        <div class="lg-fields ac-fields">
          <div class="lg-span-all">
            <label class="ac-label">{{ $t('Name') }}</label>
            <input v-model="form.name" type="text" class="ac-input" />
          </div>
          <div class="lg-span-all" v-if="!form.parent_id_locked">
            <label class="ac-label">{{ $t('Parent Group') }}</label>
            <css-select v-model="form.parent_id" class="ac-input">
              <option :value="null">{{ $t('(Top-level group)') }}</option>
              <option v-for="g in categories" :key="g.id" :value="g.id">{{ g.name }}</option>
            </css-select>
          </div>
        </div>
        <div class="ac-modal-foot">
          <button type="button" class="lg-btn lg-btn-soft" @click="showModal = false">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" @click="save"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button>
        </div>
      </div>
    </modal>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Modal from '@/Jetstream/Modal.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

const GROUP_ICONS = {
  'Land & Buildings': 'building',
  Vehicles: 'truck',
  'Construction Machinery': 'cog',
  'Tools & Equipment': 'adjustments',
  'IT & Electronics': 'chip',
  'Office Equipment': 'table',
  'Furniture & Fixtures': 'collection',
  'Plant & Equipment': 'group',
  'Electrical Equipment': 'toggle',
  'Communication Equipment': 'wifi',
  'Other Assets': 'folder',
};

export default {
  components: { AdminLayout, Modal, PageHeader },

  props: { categories: Array },

  data() {
    return {
      showModal: false,
      search: '',
      // Default collapsed — every segment starts closed until the user
      // opens it (or types a search that auto-expands matches).
      expandedIds: new Set(),
      form: { id: null, name: '', parent_id: null, parent_id_locked: false },
      editingId: null,
      editingName: '',
      editInputRefs: {},
    };
  },

  computed: {
    filteredCategories() {
      const q = this.search.trim().toLowerCase();
      if (!q) return this.categories;
      return this.categories
        .map(group => {
          const groupMatches = group.name.toLowerCase().includes(q);
          const children = groupMatches ? group.children : group.children.filter(c => c.name.toLowerCase().includes(q));
          return { ...group, children };
        })
        .filter(group => group.name.toLowerCase().includes(q) || group.children.length);
    },
  },

  watch: {
    search(q) {
      // Auto-expand any group that matches so results are visible without
      // an extra click; manual collapses elsewhere are left untouched.
      if (q.trim()) {
        this.filteredCategories.forEach(g => this.expandedIds.add(g.id));
      }
    },
  },

  methods: {
    groupIcon(name) {
      return GROUP_ICONS[name] || 'folder';
    },
    isExpanded(id) {
      return this.expandedIds.has(id);
    },
    toggleExpanded(id) {
      if (this.expandedIds.has(id)) {
        this.expandedIds.delete(id);
      } else {
        this.expandedIds.add(id);
      }
    },
    expandAll() {
      this.expandedIds = new Set(this.categories.map(g => g.id));
    },
    collapseAll() {
      this.expandedIds = new Set();
    },
    openCreate(group) {
      this.form = { id: null, name: '', parent_id: group ? group.id : null, parent_id_locked: !!group };
      this.showModal = true;
    },
    openEdit(category) {
      this.form = { id: category.id, name: category.name, parent_id: category.parent_id, parent_id_locked: false };
      this.showModal = true;
    },
    save() {
      const payload = { name: this.form.name, parent_id: this.form.parent_id };
      if (this.form.id) {
        this.$inertia.patch(this.route('assets.categories.update', this.form.id), payload, { onSuccess: () => (this.showModal = false) });
      } else {
        this.$inertia.post(this.route('assets.categories.store'), payload, { onSuccess: () => (this.showModal = false) });
      }
    },
    async destroy(category) {
      if (await this.$confirm(this.$t('Delete this category?'), { variant: 'danger' })) {
        this.$inertia.delete(this.route('assets.categories.destroy', category.id));
      }
    },
    setEditInputRef(el, id) {
      if (el) this.editInputRefs[id] = el;
    },
    startInline(child) {
      this.editingId = child.id;
      this.editingName = child.name;
      this.$nextTick(() => {
        const input = this.editInputRefs[child.id];
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
    saveInline(child) {
      const name = this.editingName.trim();
      if (!name || name === child.name) {
        this.cancelInline();
        return;
      }
      this.$inertia.patch(
        this.route('assets.categories.update', child.id),
        { name, parent_id: child.parent_id },
        { preserveScroll: true, onSuccess: () => this.cancelInline() }
      );
    },
  },
};
</script>

<style scoped>
.ac-filters { padding-bottom: 1.15rem; }
.ac-toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.ac-search { position: relative; flex: 1 1 260px; max-width: 28rem; }
.ac-search-ico { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--lg-faint); }
.ac-search-input { width: 100%; padding: 0.6rem 0.9rem 0.6rem 2.3rem; font-size: 0.86rem; border-radius: 9999px; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-ink); }
.ac-search-input:focus { outline: none; border-color: #38bdf8; box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2); }
.ac-toolbar-btns { display: flex; gap: 8px; flex-wrap: wrap; margin-left: auto; }

.ast-segments { display: flex; flex-direction: column; gap: 0.75rem; }
.ast-segment { border: 1px solid var(--lg-line); background: var(--lg-card); border-radius: 20px; box-shadow: var(--lg-shadow); }
.ast-segment-header { width: 100%; display: flex; align-items: center; gap: 0.75rem; padding: 0.8rem 1.1rem; text-align: left; cursor: pointer; border-radius: 20px; transition: background 0.15s ease; }
.ast-segment-header:hover { background: var(--lg-soft); }
.ast-segment-icon { width: 36px; height: 36px; border-radius: 12px; }
.ast-segment-name { font-weight: 800; color: var(--lg-ink); font-size: 0.92rem; min-width: 0; overflow-wrap: anywhere; }
.ast-segment-actions { display: flex; align-items: center; gap: 6px; margin-left: auto; }
.ast-segment-chevron { color: var(--lg-faint); transition: transform 0.25s ease; }
.ast-segment-chevron-open { transform: rotate(180deg); }

.ast-segment-body { display: grid; grid-template-rows: 0fr; transition: grid-template-rows 0.25s ease; }
.ast-segment-body-open { grid-template-rows: 1fr; }
.ast-segment-body-inner { overflow: hidden; min-height: 0; }
.ast-segment-body-open .ast-segment-body-inner { overflow: visible; }

.ast-child-row { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.5rem 1.1rem 0.5rem 3.6rem; border-top: 1px solid var(--lg-line); }
.ast-child-row:hover { background: var(--lg-soft); }
.ast-child-row:last-child { border-radius: 0 0 20px 20px; }
.ast-child-name { display: flex; align-items: center; gap: 10px; color: var(--lg-ink); font-size: 0.85rem; font-weight: 600; min-width: 0; }
.ast-child-dot { flex: none; width: 7px; height: 7px; border-radius: 9999px; background: #818cf8; }
.ast-child-edit-input { flex: 1; min-width: 0; font-size: 0.85rem; color: var(--lg-ink); padding: 0.3rem 0.65rem; border: 1px solid #a5b4fc; border-radius: 0.65rem; background: var(--lg-card); }
.ast-child-edit-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15); }
.ast-child-actions { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.ast-child-empty { padding: 0.75rem 1.1rem 0.9rem 3.6rem; font-size: 0.8rem; color: var(--lg-faint); font-style: italic; border-top: 1px solid var(--lg-line); }
.ast-empty-state { border: 1px dashed var(--lg-line); border-radius: 20px; padding: 2.5rem; text-align: center; color: var(--lg-faint); font-size: 0.85rem; }

/* modal (always white card, so pin light tokens) */
html .ac-modal.lg-page { --lg-card: #ffffff; --lg-line: #e6ebf3; --lg-soft: #f6f8fc; --lg-ink: #0f172a; --lg-mute: #64748b; --lg-faint: #94a3b8; --lg-navy: #1c3f66; padding: 1.4rem 1.5rem; border-radius: 0.375rem; }
.ac-label { display: block; margin-bottom: 4px; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.ac-input { width: 100%; padding: 0.6rem 0.8rem; border: 1px solid var(--lg-line); border-radius: 0.85rem; background: var(--lg-card); color: var(--lg-ink); font-size: 0.88rem; }
.ac-input:focus { outline: none; border-color: #38bdf8; box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2); }
.ac-modal-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 1.25rem; }

@media (max-width: 640px) {
  .ast-segment-header { flex-wrap: wrap; }
  .ast-child-row { padding-left: 1.2rem; }
  .ast-child-empty { padding-left: 1.2rem; }
}
</style>

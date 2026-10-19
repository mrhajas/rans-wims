<template>
  <admin-layout :title="$t('Case Tracking Settings')">
    <div class="lg-page px-4 md:px-0 cs-settings">
      <page-header icon="cog" :title="$t('Categories & Priorities')" :subtitle="$t('Case Tracking Settings')">
        <Link :href="route('case-tracking.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" /> {{ $t('Case Tracking') }}</Link>
      </page-header>

      <section v-if="canOpenWorkflow" class="lg-card cs-notif">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="bell" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Notifications') }}</h2>
            <p class="lg-card-sub">{{ $t('Who gets notified for each Case Tracking event, on which channels and with which words, is now set on the Case Tracking workflow page.') }}</p>
          </div>
          <Link :href="route('case-tracking.workflow')" class="lg-btn lg-btn-primary"><icons name="bell" class="w-4 h-4" /> {{ $t('Open Case Tracking Workflow') }}</Link>
        </header>
      </section>

      <div class="cs-two">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="folder" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Categories') }}</h2></div>
            <span class="lg-chip lg-chip-slate">{{ localCategories.length }}</span>
          </header>
          <div class="cs-list">
            <div v-for="c in localCategories" :key="c.id" class="cs-row-item">
              <input v-model="c.name" type="text" class="cs-input flex-1" @change="saveCategory(c)" />
              <label class="cs-toggle">
                <input type="checkbox" v-model="c.is_active" @change="saveCategory(c)" />
                <span>{{ $t('Active') }}</span>
              </label>
              <button type="button" class="lg-icon-btn is-red" :aria-label="$t('Delete')" @click="removeCategory(c)"><icons name="trash" class="w-4 h-4" /></button>
            </div>
            <p v-if="!localCategories.length" class="cs-empty">{{ $t('No categories yet.') }}</p>
          </div>
          <div class="cs-add">
            <input v-model="newCategoryName" type="text" class="cs-input flex-1" :placeholder="$t('New category name…')" @keyup.enter="addCategory" />
            <button type="button" class="lg-btn lg-btn-primary" @click="addCategory"><icons name="plus" class="w-4 h-4" /> {{ $t('Add') }}</button>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-rose"><icons name="exclamation" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Priorities') }}</h2></div>
            <span class="lg-chip lg-chip-slate">{{ localPriorities.length }}</span>
          </header>
          <div class="cs-list">
            <div v-for="p in localPriorities" :key="p.id" class="cs-row-item">
              <input v-model="p.name" type="text" class="cs-input flex-1 min-w-[120px]" @change="savePriority(p)" />
              <span class="lg-tip" :data-tip="$t('Urgency level: higher sorts first')">
                <input v-model.number="p.level" type="number" min="1" max="10" class="cs-input w-16" :aria-label="$t('Urgency level: higher sorts first')" @change="savePriority(p)" />
              </span>
              <select v-model="p.color" class="cs-input" @change="savePriority(p)">
                <option value="slate">{{ $t('Slate') }}</option>
                <option value="amber">{{ $t('Amber') }}</option>
                <option value="rose">{{ $t('Rose') }}</option>
                <option value="red">{{ $t('Red') }}</option>
              </select>
              <label class="cs-toggle">
                <input type="checkbox" v-model="p.is_active" @change="savePriority(p)" />
                <span>{{ $t('Active') }}</span>
              </label>
              <button type="button" class="lg-icon-btn is-red" :aria-label="$t('Delete')" @click="removePriority(p)"><icons name="trash" class="w-4 h-4" /></button>
            </div>
            <p v-if="!localPriorities.length" class="cs-empty">{{ $t('No priorities yet.') }}</p>
          </div>
          <div class="cs-add flex-wrap">
            <input v-model="newPriority.name" type="text" class="cs-input flex-1" :placeholder="$t('New priority name…')" />
            <input v-model.number="newPriority.level" type="number" min="1" max="10" class="cs-input w-16" :placeholder="$t('Level')" />
            <select v-model="newPriority.color" class="cs-input">
              <option value="slate">{{ $t('Slate') }}</option>
              <option value="amber">{{ $t('Amber') }}</option>
              <option value="rose">{{ $t('Rose') }}</option>
              <option value="red">{{ $t('Red') }}</option>
            </select>
            <button type="button" class="lg-btn lg-btn-primary" @click="addPriority"><icons name="plus" class="w-4 h-4" /> {{ $t('Add') }}</button>
          </div>
        </section>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, Link, PageHeader },

  props: {
    categories: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    canOpenWorkflow: { type: Boolean, default: false },
  },

  data() {
    return {
      localCategories: this.categories.map(c => ({ ...c })),
      localPriorities: this.priorities.map(p => ({ ...p })),
      newCategoryName: '',
      newPriority: { name: '', level: 1, color: 'slate' },
    };
  },

  methods: {
    addCategory() {
      if (!this.newCategoryName.trim()) return;
      this.$inertia.post(this.route('case-tracking.settings.categories.store'), { name: this.newCategoryName }, {
        preserveScroll: true,
        onSuccess: () => { this.newCategoryName = ''; },
      });
    },
    saveCategory(c) {
      this.$inertia.patch(this.route('case-tracking.settings.categories.update', c.id), { name: c.name, is_active: c.is_active }, { preserveScroll: true });
    },
    async removeCategory(c) {
      if (!(await this.$confirm(this.$t('Remove this category?'), { variant: 'danger' }))) return;
      this.$inertia.delete(this.route('case-tracking.settings.categories.destroy', c.id), { preserveScroll: true });
    },
    addPriority() {
      if (!this.newPriority.name.trim()) return;
      this.$inertia.post(this.route('case-tracking.settings.priorities.store'), this.newPriority, {
        preserveScroll: true,
        onSuccess: () => { this.newPriority = { name: '', level: 1, color: 'slate' }; },
      });
    },
    savePriority(p) {
      this.$inertia.patch(this.route('case-tracking.settings.priorities.update', p.id), { name: p.name, level: p.level, color: p.color, is_active: p.is_active }, { preserveScroll: true });
    },
    async removePriority(p) {
      if (!(await this.$confirm(this.$t('Remove this priority?'), { variant: 'danger' }))) return;
      this.$inertia.delete(this.route('case-tracking.settings.priorities.destroy', p.id), { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.cs-two { display: grid; gap: 1rem; grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: start; }
@media (max-width: 1023px) { .cs-two { grid-template-columns: minmax(0, 1fr); } }
.cs-rules { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(min(320px, 100%), 1fr)); }
@media (max-width: 640px) { .cs-rules { grid-template-columns: minmax(0, 1fr); } }
.cs-notif-card { min-width: 0; padding: 0.95rem 1.05rem; border-radius: 18px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.cs-rule-head { margin-bottom: 10px; }
.cs-rule-name { font-size: 0.9rem; font-weight: 800; color: var(--lg-ink); }
.cs-rule-desc { margin-top: 1px; font-size: 0.74rem; color: var(--lg-mute); }
.cs-toggle-row { display: flex; flex-wrap: wrap; gap: 8px 16px; margin-bottom: 12px; }
.cs-role-list { display: flex; flex-wrap: wrap; gap: 6px 16px; margin-top: 4px; }
.cs-label { display: block; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); margin-bottom: 0.25rem; }
.cs-toggle { display: inline-flex; align-items: center; gap: 6px; font-size: 0.78rem; font-weight: 600; color: var(--lg-ink); white-space: nowrap; }
.cs-input { border: 1px solid var(--lg-line); border-radius: 12px; padding: 0.5rem 0.75rem; font-size: 0.84rem; color: var(--lg-ink); background: var(--lg-card); }
.cs-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
.cs-chip { display: inline-flex; align-items: center; gap: 5px; padding: 0.2rem 0.3rem 0.2rem 0.7rem; border-radius: 9999px; background: rgba(99, 102, 241, 0.14); color: #4338ca; font-size: 0.74rem; font-weight: 700; }
html.dark .cs-chip { color: #c7d2fe; }
.cs-chip button { display: inline-flex; align-items: center; justify-content: center; width: 1.1rem; height: 1.1rem; border-radius: 9999px; background: rgba(99, 102, 241, 0.2); }
.cs-chip button:hover { background: rgba(99, 102, 241, 0.38); }
.cs-confirm { display: grid; gap: 12px; align-items: end; grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr)); margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--lg-line); }
.cs-confirm-actions { display: flex; align-items: center; justify-content: flex-end; gap: 12px; flex-wrap: wrap; }
.cs-error { margin-top: 4px; font-size: 0.74rem; font-weight: 700; color: #dc2626; }
.cs-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 1rem; }
.cs-row-item { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; padding: 8px; border-radius: 16px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.cs-row-item .cs-input { background: var(--lg-card); }
.cs-add { display: flex; flex-wrap: wrap; gap: 8px; padding-top: 1rem; border-top: 1px dashed var(--lg-line); }
.cs-empty { padding: 20px; text-align: center; font-size: 0.82rem; color: var(--lg-faint); border: 1px dashed var(--lg-line); border-radius: 14px; }
</style>

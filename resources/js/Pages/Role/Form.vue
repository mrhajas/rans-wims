<template>
  <admin-layout :title="$t(edit ? 'edit_x' : 'create_x', { x: $t('Role') })">
    <div class="lg-page px-4 md:px-0 us-role">
      <page-header
        icon="key"
        :title="edit ? edit.name : $t('create_x', { x: $t('Role') })"
        :subtitle="edit ? $t('Update the record by modifying the details in the form below') : $t('Please fill the form below to add new record.')"
      >
        <span v-if="edit && edit.deleted_at" class="lg-chip lg-chip-red"><icons name="trash" class="w-3 h-3" />{{ $t('Trashed') }}</span>
        <Link :href="route('roles.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Roles') }}</Link>
      </page-header>

      <form @submit.prevent="update">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="users" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Role') }}</h2>
              <p class="lg-card-sub">{{ $t('Name') }}</p>
            </div>
          </header>
          <trashed-message v-if="edit && edit.deleted_at" class="mb-6" @restore="restore">
            {{ $t('This record has been deleted.') }}
          </trashed-message>
          <div class="lg-fields">
            <div class="lg-span-2"><text-input v-model="form.name" :error="$page.props.errors.name" :label="$t('Name')" /></div>
          </div>
        </section>

        <div class="lg-actionbar">
          <div class="flex items-center gap-2 flex-wrap">
            <template v-if="edit">
              <button v-if="!edit.deleted_at" type="button" @click="destroy" class="lg-btn lg-btn-soft us-danger-soft">
                <icons name="trash" class="w-4 h-4" />
                {{ $t('delete_x', { x: $t('Role') }) }}
              </button>
              <button v-else type="button" @click="deletePermanently" class="lg-btn lg-btn-soft us-danger-soft">
                <icons name="trash" class="w-4 h-4" />
                {{ $t('delete_x', { x: $t('Permanently') }) }}
              </button>
              <button v-if="!edit.deleted_at && $can('create-roles')" type="button" @click="openDuplicateModal" class="lg-btn lg-btn-soft">
                <icons name="collection" class="w-4 h-4" />
                {{ $t('Duplicate Role') }}
              </button>
            </template>
          </div>
          <div class="lg-actionbar-btns">
            <tec-action-message :on="form.recentlySuccessful" class="mr-1">{{ $t('Saved.') }} </tec-action-message>
            <button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
              {{ $t('Save') }}
            </button>
          </div>
        </div>
      </form>

      <!-- Delete User Confirmation Modal -->
      <Dialog
        max-width="md"
        :show="permanent"
        action-type="delete"
        title="Delete Role?"
        :close="closePermanentModal"
        :action="deleteRolePermanently"
        action-text="Delete Permanently"
        :content="`<p class='mb-2'>${$t('Are you sure you want to delete the record permanently?')}</p>
        <p>${$t('Once deleted, all of its resources and data will be permanently deleted.')}</p>`"
      />

      <!-- Delete Account Confirmation Modal -->
      <Dialog
        :show="confirm"
        :close="closeModal"
        :action="deleteRole"
        action-type="delete"
        title="Delete Role?"
        action-text="Delete Role"
        :content="$t('Are you sure you want to delete the record?')"
      />

      <!-- Restore Account Confirmation Modal -->
      <Dialog
        :show="restoreConf"
        :action="restoreRole"
        title="Restore Role!"
        :close="closeRestoreModal"
        action-text="Restore Role"
        :content="$t('Are you sure you want to restore the record?')"
      />

      <!-- Duplicate Role Modal -->
      <tec-dialog-modal :show="duplicating" @close="closeDuplicateModal" max-width="sm">
        <template #title>
          <div class="flex items-center gap-3">
            <span class="lg-card-ico lg-ico-indigo"><icons name="collection" class="w-5 h-5" /></span>
            <div class="lg-card-title">{{ $t('Duplicate Role') }}</div>
          </div>
        </template>

        <template #content>
          <p class="mb-4 text-sm text-gray-700">
            {{ $t('This creates a brand new role with a copy of all {name} permissions. Give it a different name.', { name: edit ? edit.name : '' }) }}
          </p>
          <text-input
            v-model="dupForm.name"
            :error="dupForm.errors.name"
            :label="$t('New Role Name')"
            @keyup.enter="duplicateRole"
          />
        </template>

        <template #footer>
          <div class="flex items-center justify-between">
            <button type="button" @click="closeDuplicateModal" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</button>
            <button type="button" class="lg-btn lg-btn-primary" :disabled="dupForm.processing" @click="duplicateRole">
              <icons :name="dupForm.processing ? 'refresh' : 'collection'" class="w-4 h-4" :class="{ 'animate-spin': dupForm.processing }" />
              {{ $t('Duplicate Role') }}
            </button>
          </div>
        </template>
      </tec-dialog-modal>

      <!-- Role Permissions Form -->
      <div class="mt-8" v-if="edit">
        <section class="lg-card" style="margin-bottom: 0.75rem">
          <header class="lg-card-head" style="margin-bottom: 0.75rem">
            <span class="lg-card-ico lg-ico-emerald"><icons name="key" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Role Permissions') }}</h2>
              <p class="lg-card-sub">{{ $t('Choose exactly what the') }} {{ edit.name }} {{ $t('role can do, grouped by module.') }}</p>
            </div>
          </header>

          <stat-tiles :items="statTiles" />

          <div class="us-search">
            <icons name="search" class="w-4 h-4 us-search-ico" />
            <input v-model="moduleSearch" type="text" :placeholder="$t('Search modules or permissions...')" class="us-search-input" />
          </div>

          <div class="flex items-center justify-end gap-2 mt-3">
            <button type="button" class="lg-btn lg-btn-soft" @click="expandAll">{{ $t('Expand All') }}</button>
            <button type="button" class="lg-btn lg-btn-soft" @click="collapseAll">{{ $t('Collapse All') }}</button>
          </div>
        </section>

        <div v-if="!Object.keys(filteredModules).length" class="lg-card lg-empty" style="padding: 36px 16px !important">
          {{ $t('No modules match your search.') }}
        </div>

        <div v-else class="permission-stack md:grid md:grid-cols-2 md:gap-4 md:items-start">
          <div v-for="(module, key) in filteredModules" :key="key" class="us-mod permission-module-card">
            <button type="button" class="us-mod-head w-full text-left" @mousedown.prevent @click="toggleExpand(key)">
              <span class="lg-card-ico" :class="moduleIconClass(key)">
                <icons :name="moduleIcon(key)" class="w-5 h-5" />
              </span>
              <div class="min-w-0 flex-1">
                <div class="lg-card-title truncate">{{ module.label }}</div>
                <div class="lg-card-sub">{{ modulePermissionCount(module) }} / {{ Object.keys(module.abilities).length }} {{ $t('granted') }}</div>
              </div>
              <span
                v-if="!isModuleFullyLocked(module)"
                role="button"
                tabindex="0"
                class="lg-chip us-mod-toggle"
                :class="isModuleFullyGranted(module) ? 'lg-chip-slate' : 'lg-chip-blue'"
                @click.stop="toggleModule(module)"
              >
                <icons :name="isModuleFullyGranted(module) ? 'cross' : 'tick'" class="w-3 h-3" />
                {{ isModuleFullyGranted(module) ? $t('Clear') : $t('Select All') }}
              </span>
              <span v-else class="lg-chip lg-chip-slate">
                <icons name="lock-closed" class="w-3 h-3" />
                {{ $t('Locked') }}
              </span>
              <icons
                name="chevron-down"
                class="w-4 h-4 us-mod-caret shrink-0 transition-transform"
                :class="{ 'rotate-180': isExpanded(key) }"
              />
            </button>

            <div v-if="isExpanded(key)" class="us-mod-body">
              <div class="flex flex-wrap gap-2">
                <label
                  v-for="(abilityLabel, permName) in module.abilities"
                  :key="permName"
                  class="permission-chip"
                  :class="{ 'permission-chip-active': permissions.includes(permName), 'permission-chip-readonly': isAbilityLocked(module, permName) }"
                >
                  <input
                    type="checkbox"
                    class="sr-only"
                    :checked="permissions.includes(permName)"
                    :disabled="isAbilityLocked(module, permName)"
                    @change="updatePermissions(permName)"
                  />
                  <icons :name="isAbilityLocked(module, permName) ? 'lock-closed' : abilityIcon(permName)" class="w-3.5 h-3.5 shrink-0" />
                  {{ abilityLabel }}
                  <icons name="tick" class="w-3.5 h-3.5 permission-chip-tick" />
                </label>
              </div>
            </div>
          </div>
        </div>

        <div class="lg-actionbar mt-4">
          <span class="lg-actionbar-note">{{ permissions.length }} / {{ totalAbilities }} {{ $t('granted') }}</span>
          <div class="lg-actionbar-btns">
            <tec-action-message :on="pform.recentlySuccessful">{{ $t('Saved.') }}</tec-action-message>
            <button type="button" class="lg-btn lg-btn-primary" :disabled="pform.processing" @click="submit">
              <icons :name="pform.processing ? 'refresh' : 'key'" class="w-4 h-4" :class="{ 'animate-spin': pform.processing }" />
              {{ $t('Save Permissions') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import Dialog from '@/Shared/Dialog.vue';
import CheckBox from '@/Shared/CheckBox.vue';
import TextInput from '@/Shared/TextInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import TrashedMessage from '@/Shared/TrashedMessage.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import TecActionMessage from '@/Jetstream/ActionMessage.vue';

export default {
  props: ['edit', 'permissionRegistry'],

  components: {
    Dialog,
    CheckBox,
    TextInput,
    AdminLayout,
    LoadingButton,
    TecDialogModal,
    PageHeader,
    StatTiles,
    TrashedMessage,
    TecActionMessage,
  },

  data() {
    return {
      confirm: false,
      permissions: [],
      permanent: false,
      restoreConf: false,
      duplicating: false,
      moduleSearch: '',
      expandedModules: [],
      moduleIcons: {
        checkins: 'in',
        checkouts: 'out',
        adjustments: 'adjustments',
        transfers: 'truck',
        items: 'chip',
        contacts: 'users',
        categories: 'folder',
        units: 'table',
        warehouses: 'building',
        users: 'user',
        roles: 'key',
        issue_returns: 'refresh',
        material_requests: 'doc-text',
        material_reallocations: 'truck',
        daily_site_reports: 'clip',
        work_packages: 'clip',
        work_package_daily_updates: 'clock',
        work_package_issues: 'exclamation',
        work_package_forecasts: 'chart-bar',
        notices: 'bell',
        login_logs: 'lock-closed',
        download_print_logs: 'download',
        reports: 'chart-bar',
        activity: 'list',
        deleted_records: 'trash',
        email_notifications: 'email',
        backup: 'collection',
        maintenance: 'cog',
        system_lockdown: 'lock-closed',
        construction_toolbox: 'calculator',
        transaction_overrides: 'toggle',
      },
      moduleIconColors: ['blue', 'cyan', 'emerald', 'amber', 'violet', 'rose', 'slate'],
      form: this.$inertia.form({
        _method: this.edit ? 'put' : 'post',
        name: this.edit ? this.edit.name : null,
      }),
      pform: this.$inertia.form({
        _method: 'post',
        permissions: this.edit ? this.edit.permissions : [],
      }),
      dupForm: this.$inertia.form({
        name: '',
      }),
    };
  },

  mounted() {
    if (this.edit) {
      this.permissions = this.edit.permissions;
      delete this.edit.permissions;
    }
  },

  computed: {
    statTiles() {
      return [
        { icon: 'cog', tone: 'navy', label: this.$t('Modules'), value: this.moduleCount },
        { icon: 'tick', tone: 'emerald', label: this.$t('Permissions Granted'), value: this.permissions.length },
        { icon: 'list', tone: 'indigo', label: this.$t('Total Available'), value: this.totalAbilities },
      ];
    },
    moduleCount() {
      return Object.keys(this.permissionRegistry || {}).length;
    },
    totalAbilities() {
      return Object.values(this.permissionRegistry || {}).reduce((sum, m) => sum + Object.keys(m.abilities).length, 0);
    },
    filteredModules() {
      const query = this.moduleSearch.trim().toLowerCase();
      if (!query) {
        return this.permissionRegistry || {};
      }

      const result = {};
      Object.entries(this.permissionRegistry || {}).forEach(([key, module]) => {
        const labelMatch = module.label.toLowerCase().includes(query);
        const abilityMatch = Object.values(module.abilities).some(a => a.toLowerCase().includes(query));
        if (labelMatch || abilityMatch) {
          result[key] = module;
        }
      });
      return result;
    },
  },

  methods: {
    isExpanded(key) {
      return this.moduleSearch.trim() !== '' || this.expandedModules.includes(key);
    },
    toggleExpand(key) {
      if (this.expandedModules.includes(key)) {
        this.expandedModules = this.expandedModules.filter(k => k !== key);
      } else {
        this.expandedModules = [...this.expandedModules, key];
      }
    },
    expandAll() {
      this.expandedModules = Object.keys(this.filteredModules);
    },
    collapseAll() {
      this.expandedModules = [];
    },
    moduleIcon(key) {
      return this.moduleIcons[key] || 'cog';
    },
    lockedAbilitiesFor(module) {
      return module.lockedAbilities || [];
    },
    isAbilityLocked(module, permName) {
      return this.lockedAbilitiesFor(module).includes(permName);
    },
    hasLockedAbilities(module) {
      return this.lockedAbilitiesFor(module).length > 0;
    },
    unlockedAbilityKeys(module) {
      return Object.keys(module.abilities).filter(p => !this.isAbilityLocked(module, p));
    },
    isModuleFullyLocked(module) {
      return Object.keys(module.abilities).length > 0 && this.unlockedAbilityKeys(module).length === 0;
    },
    abilityIcon(permName) {
      if (permName.startsWith('read-') || permName.startsWith('view-')) return 'view';
      if (permName.startsWith('create-')) return 'plus';
      if (permName.startsWith('update-')) return 'edit';
      if (permName.startsWith('delete-')) return 'trash';
      return 'toggle';
    },
    moduleIconClass(key) {
      const keys = Object.keys(this.permissionRegistry || {});
      const index = keys.indexOf(key);
      const color = this.moduleIconColors[index % this.moduleIconColors.length];
      return `us-ico-${color}`;
    },
    modulePermissionCount(module) {
      return Object.keys(module.abilities).filter(p => this.permissions.includes(p)).length;
    },
    isModuleFullyGranted(module) {
      const keys = this.unlockedAbilityKeys(module);
      return keys.length > 0 && keys.every(p => this.permissions.includes(p));
    },
    toggleModule(module) {
      const keys = this.unlockedAbilityKeys(module);
      if (this.isModuleFullyGranted(module)) {
        this.permissions = this.permissions.filter(p => !keys.includes(p));
      } else {
        this.permissions = [...new Set([...this.permissions, ...keys])];
      }
    },
    update() {
      this.form.post(this.edit ? this.route('roles.update', this.edit.id) : this.route('roles.store'), {
        preserveScroll: true,
      });
    },
    updatePermissions(v) {
      if (this.permissions.includes(v)) {
        this.permissions = this.permissions.filter(r => r != v);
      } else {
        this.permissions = [...this.permissions, v];
      }
    },
    submit() {
      this.pform
        .transform(data => ({ ...data, permissions: this.permissions }))
        .post(this.route('roles.permissions', this.edit.id), { preserveScroll: true });
    },
    destroy() {
      this.confirm = true;
    },
    deleteRole() {
      this.form.delete(route('roles.destroy', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    closeModal() {
      this.confirm = false;
    },
    restore() {
      this.restoreConf = true;
    },
    restoreRole() {
      this.$inertia.put(this.route('roles.restore', this.edit.id), {
        onSuccess: () => (this.restoreConf = false),
      });
    },
    closeRestoreModal() {
      this.restoreConf = false;
    },
    deletePermanently() {
      this.permanent = true;
    },
    deleteRolePermanently() {
      this.form.delete(route('roles.destroy.permanently', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    closePermanentModal() {
      this.permanent = false;
    },
    openDuplicateModal() {
      this.dupForm.reset();
      this.dupForm.clearErrors();
      this.duplicating = true;
    },
    closeDuplicateModal() {
      this.duplicating = false;
    },
    duplicateRole() {
      this.dupForm.post(this.route('roles.duplicate', this.edit.id), {
        preserveScroll: true,
        onSuccess: () => (this.duplicating = false),
      });
    },
  },
};
</script>

<style scoped>
/* Single column on mobile (plain block stack — every card's height is
   exactly its own content, so no CSS multi-column height-balancing here,
   which is what caused the old trailing-blank-page bug). Two columns from
   md upward via CSS Grid with items-start, which — unlike multi-column
   (column-count) — never redistributes content across columns and so can't
   leave a phantom blank column; items-start stops grid's default
   align-items:stretch from inflating a short card to match a taller
   sibling in the same row. */
.permission-stack > .permission-module-card {
  margin-bottom: 16px;
}

.permission-stack > .permission-module-card:last-child {
  margin-bottom: 0;
}

@media (min-width: 768px) {
  .permission-stack > .permission-module-card {
    margin-bottom: 0;
  }
}

.permission-chip {
  /* The checkbox inside is `.sr-only` (position:absolute). Without this,
     it has no positioned ancestor closer than <body>, so its offsetParent
     becomes <body> itself — when the browser scrolls a just-focused
     checkbox into view, it scrolls the OUTER window (which isn't supposed
     to scroll at all in this layout; only the inner [scroll-region] pane
     is) instead of the chip's own scroll container, leaving the whole app
     shell scrolled thousands of pixels off-screen with nothing rendered in
     the viewport. Scoping the checkbox to its own chip keeps focus-scroll
     inside the intended scroll container. */
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 12px;
  border-radius: 999px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  font-size: 0.8rem;
  font-weight: 600;
  color: #64748b;
  cursor: pointer;
  transition: background 140ms ease, border-color 140ms ease, color 140ms ease;
}

.permission-chip:hover {
  border-color: #bfdbfe;
  background: #eff6ff;
  color: #1d4ed8;
}

.permission-chip-tick {
  display: none;
}

.permission-chip-active,
.permission-chip-active:hover {
  background: #2563eb;
  border-color: #2563eb;
  color: #ffffff;
}

.permission-chip-active:hover {
  background: #1d4ed8;
  border-color: #1d4ed8;
}

.permission-chip-active .permission-chip-tick {
  display: inline-block;
}

.permission-chip-readonly {
  cursor: not-allowed;
  opacity: 0.7;
}

.permission-chip-readonly:hover {
  border-color: #e2e8f0;
  background: #f8fafc;
  color: #64748b;
}

.permission-chip-active.permission-chip-readonly:hover {
  background: #2563eb;
  border-color: #2563eb;
  color: #ffffff;
}

.permission-readonly-notice {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  background: rgba(219, 234, 254, 0.6);
  border: 1px solid rgba(147, 197, 253, 0.4);
  border-radius: 0.65rem;
  padding: 0.6rem 0.75rem;
  margin-bottom: 0.75rem;
  color: #1e3a8a;
  font-size: 0.78rem;
  line-height: 1.4;
}

.permission-readonly-link {
  display: inline-flex;
  align-items: center;
  font-weight: 700;
  color: #1d4ed8;
  text-decoration: underline;
  white-space: nowrap;
}

.permission-readonly-link:hover {
  color: #1e40af;
}

html.dark .permission-readonly-notice {
  background: rgba(59, 130, 246, 0.1);
  border-color: rgba(59, 130, 246, 0.3);
  color: #bfdbfe;
}

html.dark .permission-readonly-link {
  color: #93c5fd;
}

/* ---- Module cards ---- */
.us-mod { border-radius: 18px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); overflow: hidden; }
.us-mod-head { display: flex; align-items: center; gap: 12px; padding: 12px 14px; }
.us-mod-head:hover { background: var(--lg-soft); }
.us-mod-body { padding: 4px 14px 14px; }
.us-mod-caret { color: var(--lg-faint); }
.us-mod-toggle { cursor: pointer; }
.us-ico-blue { background: linear-gradient(145deg, #60a5fa, #1d4ed8); }
.us-ico-cyan { background: linear-gradient(145deg, #22d3ee, #0e7490); }
.us-ico-emerald { background: linear-gradient(145deg, #34d399, #047857); }
.us-ico-amber { background: linear-gradient(145deg, #fbbf24, #b45309); }
.us-ico-violet { background: linear-gradient(145deg, #a78bfa, #6d28d9); }
.us-ico-rose { background: linear-gradient(145deg, #fb7185, #be123c); }
.us-ico-slate { background: linear-gradient(145deg, #64748b, #334155); }

.us-search { position: relative; }
.us-search-ico { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--lg-faint); pointer-events: none; }
.us-search-input { width: 100%; padding: 10px 14px 10px 38px; border-radius: 9999px; font-size: 0.84rem; color: var(--lg-ink); background: var(--lg-soft); border: 1px solid var(--lg-line); }
.us-search-input:focus { outline: none; border-color: #818cf8; box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.2); }
.us-danger-soft { color: #b91c1c; }
html.dark .us-danger-soft { color: #fca5a5; }
html.dark .permission-chip { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.1); color: #cbd5e1; }
html.dark .permission-chip-active, html.dark .permission-chip-active:hover { background: #2563eb; border-color: #2563eb; color: #fff; }
</style>

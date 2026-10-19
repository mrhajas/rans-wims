<template>
  <admin-layout :title="$t('Roles')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="users" :title="$t('Roles')" :subtitle="$t('Please review the data in the table below')">
        <Link v-if="$can('create-roles')" :href="route('roles.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />{{ $t('create_x', { x: $t('Role') }) }}
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" />

      <div class="lg-card lg-filters relative z-20 print:hidden" style="padding: 0.9rem 1.1rem">
        <search-filter v-model="form.search" class="w-full max-w-md" @reset="reset">
          <label class="mt-4 block text-gray-700">{{ $t('Trashed') }}:</label>
          <select-input v-model="form.trashed" class="mt-1 w-full">
            <option :value="null">{{ $t('Not Trashed') }}</option>
            <option value="with">{{ $t('With Trashed') }}</option>
            <option value="only">{{ $t('Only Trashed') }}</option>
          </select-input>
        </search-filter>
      </div>

      <div class="lg-card" style="padding: 1rem">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Name') }}</th>
                <th>{{ $t('Status') }}</th>
                <th class="num">{{ $t('Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                :key="role.id"
                v-for="role in roles.data"
                @click="goto(route('roles.edit', role.id))"
                :class="{ 'is-click': canEdit(role) }"
              >
                <td :data-label="$t('Name')">
                  <div class="lg-person">
                    <span class="lg-avatar" :class="role.name == 'Super Admin' ? 'rl-avatar-lock' : ''">
                      <icons :name="role.name == 'Super Admin' ? 'lock-closed' : 'users'" class="w-4 h-4" />
                    </span>
                    <div class="lg-main">{{ $t(role.name) }}</div>
                  </div>
                </td>
                <td :data-label="$t('Status')">
                  <span v-if="role.deleted_at" class="lg-chip lg-chip-red"><icons name="trash" class="w-3 h-3" />{{ $t('Trashed') }}</span>
                  <span v-else-if="role.name == 'Super Admin'" class="lg-chip lg-chip-indigo"><icons name="lock-closed" class="w-3 h-3" />{{ $t('Protected') }}</span>
                  <span v-else class="lg-chip lg-chip-green">{{ $t('Active') }}</span>
                </td>
                <td class="num" :data-label="$t('Actions')">
                  <div class="lg-actions">
                    <template v-if="role.name != 'Super Admin' && !role.deleted_at && $can('update-roles')">
                      <button
                        type="button"
                        @click.stop="openDuplicateModal(role)"
                        class="lg-icon-btn lg-tip"
                        :data-tip="$t('Duplicate Role')"
                        :aria-label="$t('Duplicate Role')"
                      >
                        <icons name="collection" />
                      </button>
                      <button
                        v-if="$can('delete-roles')"
                        type="button"
                        @click.stop="confirmDelete(role)"
                        class="lg-icon-btn is-red lg-tip"
                        :data-tip="$t('delete_x', { x: $t('Role') })"
                        :aria-label="$t('delete_x', { x: $t('Role') })"
                      >
                        <icons name="trash" />
                      </button>
                    </template>
                    <icons v-if="canEdit(role)" name="chevron-down" class="transform -rotate-90 block w-4 h-4 self-center rl-chevron" />
                  </div>
                </td>
              </tr>
              <tr v-if="roles.data.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="3">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <pagination class="mt-6" :meta="roles.meta" :links="roles.links" />
    </div>

    <!-- Delete Role Confirmation Modal -->
    <Dialog
      :show="!!deleteTarget"
      :close="closeDeleteModal"
      :action="deleteRole"
      action-type="delete"
      title="Delete Role?"
      action-text="Delete Role"
      :content="$t('Are you sure you want to delete the record?')"
    />

    <!-- Duplicate Role Modal -->
    <tec-dialog-modal :show="!!duplicateTarget" @close="closeDuplicateModal" max-width="sm">
      <template #title>
        <div class="flex items-center gap-3">
          <span class="lg-card-ico lg-ico-indigo"><icons name="collection" class="w-5 h-5" /></span>
          <div>
            <div class="lg-card-title">{{ $t('Duplicate Role') }}</div>
          </div>
        </div>
      </template>

      <template #content>
        <p class="mb-4 text-sm text-gray-700">
          {{
            $t('This creates a brand new role with a copy of all {name} permissions. Give it a different name.', {
              name: duplicateTarget ? duplicateTarget.name : '',
            })
          }}
        </p>
        <text-input v-model="dupForm.name" :error="dupForm.errors.name" :label="$t('New Role Name')" @keyup.enter="duplicateRole" />
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
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import { Link } from '@inertiajs/vue3';
import Dialog from '@/Shared/Dialog.vue';
import TextInput from '@/Shared/TextInput.vue';
import Pagination from '@/Shared/Pagination.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: {
    Link,
    Dialog,
    TextInput,
    Pagination,
    AdminLayout,
    SelectInput,
    LoadingButton,
    SearchFilter,
    TecDialogModal,
    PageHeader,
    StatTiles,
  },

  props: {
    roles: Object,
    filters: Object,
  },

  data() {
    return {
      form: {
        search: this.filters.search,
        trashed: this.filters.trashed,
      },
      deleteTarget: null,
      duplicateTarget: null,
      dupForm: this.$inertia.form({
        name: '',
      }),
    };
  },

  computed: {
    total() {
      return this.roles.meta ? this.roles.meta.total : this.roles.data.length;
    },
    statTiles() {
      const rows = this.roles.data || [];
      return [
        { icon: 'users', tone: 'navy', label: this.$t('Roles'), value: this.total },
        { icon: 'tick', tone: 'emerald', label: this.$t('Editable'), value: rows.filter(r => r.name != 'Super Admin' && !r.deleted_at).length },
        { icon: 'lock-closed', tone: 'indigo', label: this.$t('Protected'), value: rows.filter(r => r.name == 'Super Admin').length },
        { icon: 'trash', tone: 'rose', label: this.$t('Trashed'), value: rows.filter(r => r.deleted_at).length },
      ];
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        let query = pickBy(this.form);
        this.$inertia.visit(this.route('roles.index', Object.keys(query).length ? query : { remember: 'forget' }), {
          onFinish: () => {
            document.getElementById('page-search').focus();
          },
        });
      }, 150),
      deep: true,
    },
  },

  methods: {
    canEdit(role) {
      return role.name != 'Super Admin' && this.$can('update-roles');
    },
    goto(link) {
      if (this.$can('update-roles')) {
        this.$inertia.visit(link);
      }
    },
    reset() {
      this.form = mapValues(this.form, () => null);
    },
    confirmDelete(role) {
      this.deleteTarget = role;
    },
    closeDeleteModal() {
      this.deleteTarget = null;
    },
    deleteRole() {
      this.$inertia.delete(this.route('roles.destroy', this.deleteTarget.id), {
        preserveScroll: true,
        onSuccess: () => this.closeDeleteModal(),
      });
    },
    openDuplicateModal(role) {
      this.dupForm.reset();
      this.dupForm.clearErrors();
      this.duplicateTarget = role;
    },
    closeDuplicateModal() {
      this.duplicateTarget = null;
    },
    duplicateRole() {
      this.dupForm.post(this.route('roles.duplicate', this.duplicateTarget.id), {
        preserveScroll: true,
        onSuccess: () => this.closeDuplicateModal(),
      });
    },
  },
};
</script>

<style scoped>
.rl-avatar-lock { color: #4338ca; background: linear-gradient(135deg, #e0e7ff, #c7d2fe); }
.rl-chevron { color: var(--lg-faint); }
@media (max-width: 767.98px) {
  .rl-chevron { display: none; }
}
html.dark .rl-avatar-lock { color: #c7d2fe; background: rgba(99, 102, 241, 0.25); }
</style>

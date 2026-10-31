<template>
  <admin-layout :title="$t(edit ? 'edit_x' : 'create_x', { x: $t('User') })">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="user"
        :title="edit ? edit.name : $t('create_x', { x: $t('User') })"
        :subtitle="edit ? $t('Update the record by modifying the details in the form below') : $t('Please fill the form below to add new record.')"
      >
        <span v-if="edit && edit.deleted_at" class="lg-chip lg-chip-red"><icons name="trash" class="w-3 h-3" />{{ $t('Trashed') }}</span>
        <span v-else-if="edit && edit.is_active === false" class="lg-chip lg-chip-amber"><icons name="lock-closed" class="w-3 h-3" />{{ $t('Disabled') }}</span>
        <span v-if="edit && edit.two_factor_enabled" class="lg-chip lg-chip-cyan"><icons name="otp" class="w-3 h-3" />{{ $t('2-step') }}</span>
        <Link :href="route('users.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Users') }}</Link>
      </page-header>

      <form @submit.prevent="update">
        <trashed-message v-if="edit && edit.deleted_at" class="mb-6" @restore="restore">
          {{ $t('This user has been deleted.') }}
        </trashed-message>

        <div v-if="edit && edit.is_active === false" class="lg-note lg-note-red mb-4">
          <icons name="lock-closed" class="h-5 w-5 shrink-0" />
          <div class="text-sm">
            <p class="font-semibold">{{ $t('This account is disabled.') }}</p>
            <p class="mt-0.5">
              {{ $t('The user cannot log in until this account is re-enabled.') }}
              <span v-if="edit.disabled_by_name">
                {{ $t('Disabled by') }} {{ edit.disabled_by_name }}<span v-if="edit.disabled_at"> {{ $t('on') }} {{ $datetime(edit.disabled_at) }}</span>.
              </span>
            </p>
          </div>
        </div>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="user" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('User') }}</h2>
              <p class="lg-card-sub">{{ $t('Name') }} / {{ $t('Username') }} / {{ $t('Email') }} / {{ $t('Phone') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div><text-input v-model="form.name" :error="$page.props.errors.name" :label="$t('Name')" /></div>
            <div><text-input v-model="form.username" :error="$page.props.errors.username" :label="$t('Username')" /></div>
            <div><text-input type="email" v-model="form.email" :error="$page.props.errors.email" :label="$t('Email')" /></div>
            <div><text-input v-model="form.phone" :error="$page.props.errors.phone" :label="$t('Phone')" placeholder="0674020223 or +255674020223" /></div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-rose"><icons name="key" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Password') }}</h2>
              <p class="lg-card-sub">{{ $t('Password') }} / {{ $t('Confirm Password') }}</p>
            </div>
            <button type="button" class="lg-btn tone ml-auto" style="--tone: #d97706" @click="generatePw">
              <span class="tone-ico"><icons name="key" class="w-4 h-4" /></span>{{ $t('Generate password') }}
            </button>
          </header>
          <div class="lg-fields">
            <div>
              <text-input id="password" type="password" :label="$t('Password')" v-model="form.password" :error="$page.props.errors.password" />
            </div>
            <div class="lg-span-all">
              <ul class="pw-rules" :aria-label="$t('Password rules')">
                <li v-for="r in passwordRules" :key="r.key" :class="{ 'is-ok': r.ok, 'is-bad': !r.ok && form.password }">
                  <icons :name="r.ok ? 'tick' : 'info'" class="w-3.5 h-3.5" />{{ r.label }}
                </li>
              </ul>
              <div v-if="generated" class="pw-gen" role="status">
                <div class="pw-gen-top">{{ $t('New password (filled in both boxes)') }}</div>
                <div class="pw-gen-box">
                  <code class="pw-gen-code" :title="$t('Click to copy')" @click="copyPw"><decrypt-text :text="generated" /></code>
                  <button type="button" class="lg-btn tone" style="--tone: #4f46e5" @click="copyPw">
                    <span class="tone-ico"><icons :name="copied ? 'tick' : 'duplicate'" class="w-4 h-4" /></span>{{ copied ? $t('Copied') : $t('Copy') }}
                  </button>
                  <button type="button" class="lg-btn tone" style="--tone: #475569" @click="generatePw">
                    <span class="tone-ico"><icons name="refresh" class="w-4 h-4" /></span>{{ $t('Another one') }}
                  </button>
                </div>
                <p class="pw-gen-note">{{ $t('It is applied when you save the user. Copy it now: it is not shown again after that.') }}</p>
              </div>
              <p v-if="!edit" class="lg-sub" style="margin-top: 6px">{{ $t('Type the password twice (below) so a typing mistake is caught.') }}</p>
              <p v-else class="lg-sub" style="margin-top: 6px">{{ $t('Leave both password boxes empty to keep the current password.') }}</p>
            </div>
            <div>
              <text-input
                type="password"
                id="password_confirmation"
                :label="$t('Confirm Password')"
                v-model="form.password_confirmation"
                :error="$page.props.errors.password_confirmation"
              />
            </div>
          </div>
        </section>

        <section v-if="warehouses && warehouses.length" class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="home" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Warehouses') }}</h2>
              <p class="lg-card-sub">
                {{ $t('Select the warehouse(s) this user can manage. They can switch the active one from the top bar.') }}
              </p>
            </div>
            <span class="lg-chip lg-chip-blue">{{ (form.warehouses || []).length }} / {{ warehouses.length }}</span>
          </header>

          <div class="flex flex-wrap items-center gap-2 mb-3">
            <button type="button" @click="checkAllWarehouses" :disabled="allWarehousesSelected" class="lg-btn lg-btn-soft us-qbtn">
              <icons name="tick" class="h-4 w-4" />
              <span>{{ $t('Check All') }}</span>
            </button>
            <button type="button" @click="checkWarehousesByCategory('Government')" :disabled="isCategoryFullySelected('Government')" class="lg-btn lg-btn-soft us-qbtn">
              <icons name="building" class="h-4 w-4" />
              <span>{{ $t('Government Projects') }}</span>
            </button>
            <button type="button" @click="checkWarehousesByCategory('Private')" :disabled="isCategoryFullySelected('Private')" class="lg-btn lg-btn-soft us-qbtn">
              <icons name="lock-closed" class="h-4 w-4" />
              <span>{{ $t('Private Projects') }}</span>
            </button>
            <button type="button" @click="checkWarehousesByCategory('Main Warehouse')" :disabled="isCategoryFullySelected('Main Warehouse')" class="lg-btn lg-btn-soft us-qbtn">
              <icons name="home" class="h-4 w-4" />
              <span>{{ $t('Main Warehouses') }}</span>
            </button>
            <button type="button" @click="resetWarehouses" :disabled="!(form.warehouses || []).length" class="lg-btn lg-btn-soft us-qbtn">
              <icons name="refresh" class="h-4 w-4" />
              <span>{{ $t('Reset') }}</span>
            </button>
          </div>

          <div class="us-search mb-3">
            <icons name="search" class="w-4 h-4 us-search-ico" />
            <input v-model="warehouseSearch" type="text" :placeholder="$t('Search project(s) / warehouse(s)…')" class="us-search-input" />
          </div>

          <div class="us-check-grid">
            <template v-for="warehouse in filteredWarehouses" :key="warehouse.id">
              <div class="us-check-item">
                <check-box
                  :id="'warehouse-' + warehouse.id"
                  :value="warehouse.id"
                  :label="warehouse.name"
                  v-model:checked="form.warehouses"
                  :error="$page.props.errors.warehouses"
                />
                <span
                  class="us-dot lg-tip"
                  :class="warehouse.completed_at ? 'us-dot-red' : 'us-dot-green'"
                  :data-tip="warehouse.completed_at ? $t('Completed') : $t('Active')"
                  :aria-label="warehouse.completed_at ? $t('Completed') : $t('Active')"
                ></span>
              </div>
            </template>
          </div>
          <p v-if="warehouseSearch.trim() && !filteredWarehouses.length" class="w-full text-sm lg-subtext">
            {{ $t('No project(s) / warehouse(s) match your search.') }}
          </p>
          <tec-input-error v-if="$page.props.errors.warehouses" :message="$page.props.errors.warehouses" class="w-full mt-1" />
        </section>

        <section v-if="roles && roles.length" class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="users" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Roles') }}</h2>
              <p class="lg-card-sub">{{ (form.roles || []).length }} / {{ roles.length }}</p>
            </div>
          </header>
          <div class="us-check-grid">
            <template v-for="role in roles" :key="role.name">
              <div class="us-check-item">
                <check-box :id="role.name" :value="role.name" :label="$t(role.name)" v-model:checked="form.roles" :error="$page.props.errors.roles" />
              </div>
            </template>
          </div>
          <tec-input-error v-if="$page.props.errors.roles" :message="$page.props.errors.roles" class="w-full mt-1" />
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="toggle" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Permissions') }}</h2>
              <p class="lg-card-sub">{{ $t('Can view all record') }} / {{ $t('Can edit all record') }}</p>
            </div>
          </header>
          <div class="us-check-grid">
            <div class="us-check-item"><check-box id="view_all" v-model:checked="form.view_all" :label="$t('Can view all record')" /></div>
            <div class="us-check-item"><check-box id="edit_all" v-model:checked="form.edit_all" :label="$t('Can edit all record')" /></div>
          </div>
        </section>

        <div class="lg-actionbar">
          <div class="flex items-center gap-2 flex-wrap">
            <template v-if="edit">
              <button v-if="!edit.deleted_at" type="button" @click="destroy" class="lg-btn lg-btn-soft us-danger-soft">
                <icons name="trash" class="h-4 w-4" />{{ $t('delete_x', { x: $t('User') }) }}
              </button>
              <button v-else type="button" @click="deletePermanently" class="lg-btn lg-btn-soft us-danger-soft">
                <icons name="trash" class="h-4 w-4" />{{ $t('delete_x', { x: $t('Permanently') }) }}
              </button>
              <button
                v-if="edit.two_factor_enabled"
                type="button"
                class="lg-btn lg-btn-red"
                :disabled="disabling"
                @click="disableTwoFactorAuthentication"
              >
                <icons name="lock-open" class="h-4 w-4" /><span>{{ $t('Disable 2FA') }}</span>
              </button>
              <button v-if="faceEnrolled" type="button" class="lg-btn lg-btn-red" @click="resetFace">
                <icons name="camera" class="h-4 w-4" /><span>{{ $t('Remove face login') }}</span>
              </button>
              <button v-if="!edit.deleted_at && edit.is_active !== false" type="button" @click="openDisableModal" class="lg-btn lg-btn-dark">
                <icons name="lock-closed" class="h-4 w-4" />
                <span>{{ $t('Disable User Account') }}</span>
              </button>
              <button v-if="!edit.deleted_at && edit.is_active === false" type="button" @click="openEnableModal" class="lg-btn lg-btn-green">
                <icons name="lock-open" class="h-4 w-4" />
                <span>{{ $t('Enable User Account') }}</span>
              </button>
            </template>
          </div>
          <div class="lg-actionbar-btns">
            <tec-action-message :on="form.recentlySuccessful" class="mx-1">{{ $t('Saved.') }}</tec-action-message>
            <button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="h-4 w-4" :class="{ 'animate-spin': form.processing }" />
              <span>{{ $t('Save') }}</span>
            </button>
          </div>
        </div>
      </form>

      <staff-link-panel v-if="edit && !edit.deleted_at && staffLink && staffLink.can.view" :link="staffLink" :user-id="edit.id" class="mt-4" />
    </div>

    <!-- Delete User Confirmation Modal -->
    <Dialog
      max-width="md"
      :show="permanent"
      action-type="delete"
      title="Delete User?"
      :close="closePermanentModal"
      :action="deleteUserPermanently"
      action-text="Delete Permanently"
      :content="`<p class='mb-2'>${$t('Are you sure you want to delete the record permanently?')}</p>
        <p>${$t('Once deleted, all of its resources and data will be permanently deleted.')}</p>`"
    />

    <!-- Delete User Confirmation Modal -->
    <Dialog
      :show="confirm"
      :close="closeModal"
      :action="deleteUser"
      action-type="delete"
      title="Delete User?"
      action-text="Delete User"
      :content="$t('Are you sure you want to delete the record?')"
    />

    <!-- Restore User Confirmation Modal -->
    <Dialog
      :show="restoreConf"
      :action="restoreUser"
      title="Restore User!"
      :close="closeRestoreModal"
      action-text="Restore User"
      :content="$t('Are you sure you want to restore the record?')"
    />

    <!-- Disable User Account Confirmation Modal -->
    <tec-dialog-modal :show="disableModal" @close="closeDisableModal" max-width="sm">
      <template #title>
        <div class="flex items-center gap-3">
          <span class="lg-card-ico lg-ico-rose"><icons name="lock-closed" class="h-5 w-5" /></span>
          <div class="lg-card-title">{{ $t('Disable User Account') }}</div>
        </div>
      </template>
      <template #content>
        <p class="text-sm text-slate-700 mb-4">
          {{ $t('This user will no longer be able to log in. All of their existing data will remain intact.') }}
          {{ $t('For your security, please confirm your own password to continue.') }}
        </p>
        <text-input
          type="password"
          id="disable_account_password"
          :label="$t('Your Password')"
          v-model="disableForm.password"
          :error="disableForm.errors.password"
          autofocus
          @keyup.enter="confirmDisable"
        />
      </template>
      <template #footer>
        <div class="flex items-center justify-end gap-2">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeDisableModal">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-red" :disabled="disableForm.processing" @click="confirmDisable">
            <icons name="lock-closed" class="h-4 w-4" />
            <span>{{ $t('Disable Account') }}</span>
          </button>
        </div>
      </template>
    </tec-dialog-modal>

    <!-- Enable User Account Confirmation Modal -->
    <tec-dialog-modal :show="enableModal" @close="closeEnableModal" max-width="sm">
      <template #title>
        <div class="flex items-center gap-3">
          <span class="lg-card-ico lg-ico-emerald"><icons name="lock-open" class="h-5 w-5" /></span>
          <div class="lg-card-title">{{ $t('Enable User Account') }}</div>
        </div>
      </template>
      <template #content>
        <p class="text-sm text-slate-700 mb-4">
          {{ $t('This user will be able to log in again.') }}
          {{ $t('For your security, please confirm your own password to continue.') }}
        </p>
        <text-input
          type="password"
          id="enable_account_password"
          :label="$t('Your Password')"
          v-model="enableForm.password"
          :error="enableForm.errors.password"
          autofocus
          @keyup.enter="confirmEnable"
        />
      </template>
      <template #footer>
        <div class="flex items-center justify-end gap-2">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeEnableModal">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-green" :disabled="enableForm.processing" @click="confirmEnable">
            <icons name="lock-open" class="h-4 w-4" />
            <span>{{ $t('Enable Account') }}</span>
          </button>
        </div>
      </template>
    </tec-dialog-modal>
  </admin-layout>
</template>

<script>
import StaffLinkPanel from '@/Shared/User/StaffLinkPanel.vue';
import Dialog from '@/Shared/Dialog.vue';
import CheckBox from '@/Shared/CheckBox.vue';
import TextInput from '@/Shared/TextInput.vue';
import FileInput from '@/Shared/FileInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import TecInputError from '@/Jetstream/InputError.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import TrashedMessage from '@/Shared/TrashedMessage.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TecSectionTitle from '@/Jetstream/SectionTitle.vue';
import TecActionMessage from '@/Jetstream/ActionMessage.vue';
import DecryptText from '@/Shared/DecryptText.vue';
import { generatePassword } from '@/Core/passwordGen';

export default {
  props: ['edit', 'roles', 'warehouses', 'staffLink', 'faceEnrolled', 'passwordHint'],

  components: {
    StaffLinkPanel,
    Dialog,
    CheckBox,
    FileInput,
    TextInput,
    AdminLayout,
    AutoComplete,
    LoadingButton,
    TecInputError,
    TecDialogModal,
    PageHeader,
    TrashedMessage,
    TecSectionTitle,
    TecActionMessage,
    DecryptText,
  },

  data() {
    return {
      generated: '',
      copied: false,
      confirm: false,
      disabling: false,
      permanent: false,
      restoreConf: false,
      warehouseSearch: '',
      disableModal: false,
      disableForm: this.$inertia.form({
        password: '',
      }),
      enableModal: false,
      enableForm: this.$inertia.form({
        password: '',
      }),
      form: this.$inertia.form({
        _method: this.edit ? 'put' : 'post',
        photo: null,
        password: null,
        password_confirmation: null,
        name: this.edit ? this.edit.name : null,
        roles: this.edit ? this.edit.roles : [],
        email: this.edit ? this.edit.email : null,
        phone: this.edit ? this.edit.phone : null,
        username: this.edit ? this.edit.username : null,
        view_all: this.edit ? this.edit.view_all == 1 : false,
        edit_all: this.edit ? this.edit.edit_all == 1 : false,
        warehouse_id: this.edit ? this.edit.warehouse_id : null,
        warehouses: this.edit ? this.edit.warehouses : [],
      }),
    };
  },

  watch: {
    // typing a different password by hand hides the generated one
    'form.password'(v) { if (this.generated && v !== this.generated) this.generated = ''; },
    // Choosing Super Admin ticks every project / warehouse and both "all record" boxes (they can still be unticked afterwards).
    'form.roles'(now, before) {
      const had = (before || []).includes('Super Admin');
      if (!had && (now || []).includes('Super Admin')) {
        this.checkAllWarehouses();
        this.form.view_all = true;
        this.form.edit_all = true;
      }
    },
  },

  computed: {
    // what a password must have (the same rule the server applies), ticked off as it is typed
    passwordRules() {
      const p = this.form.password || '';
      const rules = [
        { key: 'len', label: this.$t('At least :n characters', { n: 8 }), ok: p.length >= 8 },
        { key: 'up', label: this.$t('An upper case letter (A-Z)'), ok: /[A-Z]/.test(p) },
        { key: 'low', label: this.$t('A lower case letter (a-z)'), ok: /[a-z]/.test(p) },
        { key: 'num', label: this.$t('A number (0-9)'), ok: /[0-9]/.test(p) },
      ];
      if (p) rules.push({ key: 'same', label: this.$t('Both password boxes are the same'), ok: p === (this.form.password_confirmation || '') });

      return rules;
    },
    filteredWarehouses() {
      const q = this.warehouseSearch.trim().toLowerCase();
      if (!q) {
        return this.warehouses || [];
      }
      return (this.warehouses || []).filter(warehouse => warehouse.name.toLowerCase().includes(q));
    },
    allWarehousesSelected() {
      if (!this.warehouses || !this.warehouses.length) {
        return false;
      }

      return this.warehouses.every(warehouse => (this.form.warehouses || []).includes(warehouse.id));
    },
  },

  methods: {
    // fills both password boxes with a new password that follows the rule, and shows it (decrypting) so it can be copied
    generatePw() {
      const pw = generatePassword(10);
      this.form.password = pw;
      this.form.password_confirmation = pw;
      this.generated = pw;
      this.copied = false;
    },
    copyPw() {
      const done = () => { this.copied = true; setTimeout(() => (this.copied = false), 2000); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(this.generated).then(done, done);
      else done();
    },
    checkAllWarehouses() {
      this.form.warehouses = this.warehouses.map(warehouse => warehouse.id);
    },
    warehousesByCategory(category) {
      return (this.warehouses || []).filter(warehouse => warehouse.status === category);
    },
    isCategoryFullySelected(category) {
      const categoryWarehouses = this.warehousesByCategory(category);
      if (!categoryWarehouses.length) {
        return false;
      }

      return categoryWarehouses.every(warehouse => (this.form.warehouses || []).includes(warehouse.id));
    },
    checkWarehousesByCategory(category) {
      const categoryIds = this.warehousesByCategory(category).map(warehouse => warehouse.id);
      this.form.warehouses = Array.from(new Set([...(this.form.warehouses || []), ...categoryIds]));
    },
    resetWarehouses() {
      this.form.warehouses = [];
    },
    update() {
      this.form.post(this.edit ? this.route('users.update', this.edit.id) : this.route('users.store'), {
        preserveScroll: true,
        onSuccess: () => this.form.reset('password', 'password_confirmation', 'photo'),
      });
    },
    destroy() {
      this.confirm = true;
    },
    restore() {
      this.restoreConf = true;
    },
    deletePermanently() {
      this.permanent = true;
    },

    restoreUser() {
      this.$inertia.put(this.route('users.restore', this.edit.id), {
        onSuccess: () => (this.restoreConf = false),
      });
    },
    deleteUser() {
      this.form.delete(route('users.destroy', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    deleteUserPermanently() {
      this.form.delete(route('users.destroy.permanently', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    closeRestoreModal() {
      this.restoreConf = false;
    },
    closePermanentModal() {
      this.permanent = false;
    },
    closeModal() {
      this.confirm = false;
    },
    async resetFace() {
      if (!(await this.$confirm(this.$t('Remove this user\'s face login? They can enrol again from their profile.')))) return;
      this.$inertia.delete(route('users.face.reset', this.edit.id), { preserveScroll: true });
    },
    disableTwoFactorAuthentication() {
      this.disabling = true;
      this.$inertia.delete(route('users.disable.2fa', this.edit.id), {
        preserveScroll: true,
        onSuccess: () => (this.disabling = false),
      });
    },
    openDisableModal() {
      this.disableForm.reset('password');
      this.disableForm.clearErrors();
      this.disableModal = true;
    },
    closeDisableModal() {
      this.disableModal = false;
      this.disableForm.reset('password');
      this.disableForm.clearErrors();
    },
    confirmDisable() {
      this.disableForm.post(this.route('users.disable.account', this.edit.id), {
        preserveScroll: true,
        onSuccess: () => {
          this.disableModal = false;
          this.disableForm.reset('password');
        },
      });
    },
    openEnableModal() {
      this.enableForm.reset('password');
      this.enableForm.clearErrors();
      this.enableModal = true;
    },
    closeEnableModal() {
      this.enableModal = false;
      this.enableForm.reset('password');
      this.enableForm.clearErrors();
    },
    confirmEnable() {
      this.enableForm.post(this.route('users.enable.account', this.edit.id), {
        preserveScroll: true,
        onSuccess: () => {
          this.enableModal = false;
          this.enableForm.reset('password');
        },
      });
    },
  },
};
</script>

<style scoped>
.us-qbtn { padding: 7px 13px; }
.us-search { position: relative; }
.us-search-ico { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--lg-faint); pointer-events: none; }
.us-search-input { width: 100%; padding: 10px 14px 10px 38px; border-radius: 9999px; font-size: 0.84rem; color: var(--lg-ink); background: var(--lg-soft); border: 1px solid var(--lg-line); }
.us-search-input:focus { outline: none; border-color: #818cf8; box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.2); }
.us-check-grid { display: grid; gap: 8px; grid-template-columns: repeat(auto-fill, minmax(min(230px, 100%), 1fr)); }
.us-check-item { display: flex; align-items: center; gap: 8px; min-width: 0; padding: 8px 12px; border-radius: 12px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.us-dot { flex: none; width: 8px; height: 8px; border-radius: 9999px; }
.us-dot-red { background: #ef4444; }
.us-dot-green { background: #10b981; }
.us-danger-soft { color: #b91c1c; }
html.dark .us-danger-soft { color: #fca5a5; }
</style>

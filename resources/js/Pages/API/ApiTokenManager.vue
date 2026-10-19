<template>
  <div class="lg-page">
    <!-- Generate API Token -->
    <tec-form-section @submitted="createApiToken">
      <template #title> {{ $t('Create API Token') }} </template>

      <template #description> {{ $t('API tokens allow third-party services to authenticate with our application on your behalf.') }} </template>

      <template #form>
        <!-- Token Name -->
        <div class="col-span-6 sm:col-span-4">
          <tec-label for="name" :value="$t('Name')" />
          <tec-input id="name" type="text" class="mt-1 block w-full" v-model="createApiTokenForm.name" autofocus />
          <tec-input-error :message="createApiTokenForm.errors.name" class="mt-2" />
        </div>

        <!-- Token Permissions -->
        <div class="col-span-6" v-if="availablePermissions.length > 0">
          <tec-label for="permissions" :value="$t('Permissions')" />

          <div class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-3">
            <label v-for="permission in availablePermissions" :key="permission" class="api-perm" :class="{ 'is-on': createApiTokenForm.permissions.includes(permission) }">
              <tec-checkbox :value="permission" v-model:checked="createApiTokenForm.permissions" />
              <span>{{ permission }}</span>
            </label>
          </div>
        </div>
      </template>

      <template #actions>
        <tec-action-message :on="createApiTokenForm.recentlySuccessful" class="mr-3"> {{ $t('Created.') }} </tec-action-message>

        <tec-button :class="{ 'opacity-25': createApiTokenForm.processing }" :disabled="createApiTokenForm.processing"> {{ $t('Create') }} </tec-button>
      </template>
    </tec-form-section>

    <div v-if="tokens.length > 0">
      <tec-section-border />

      <!-- Manage API Tokens -->
      <div class="mt-10 sm:mt-0">
        <tec-action-section>
          <template #title> {{ $t('Manage API Tokens') }} </template>

          <template #description> {{ $t('You may delete any of your existing tokens if they are no longer needed.') }} </template>

          <!-- API Token List -->
          <template #content>
            <div class="api-list">
              <div class="api-row" v-for="token in tokens" :key="token.id">
                <div class="flex items-center gap-3 min-w-0">
                  <span class="lg-card-ico lg-ico-indigo" style="width: 36px; height: 36px; border-radius: 12px"><icons name="key" class="w-4 h-4" /></span>
                  <div class="min-w-0">
                    <div class="lg-main truncate">{{ token.name }}</div>
                    <div class="lg-subtext" v-if="token.last_used_ago">{{ $t('Last used') }} {{ token.last_used_ago }}</div>
                  </div>
                </div>

                <div class="flex items-center gap-2 flex-wrap justify-end">
                  <button type="button" class="lg-btn lg-btn-soft" @click="manageApiTokenPermissions(token)" v-if="availablePermissions.length > 0">
                    <icons name="lock-closed" class="w-3.5 h-3.5" />
                    {{ $t('Permissions') }}
                  </button>

                  <button type="button" class="lg-btn lg-btn-red" @click="confirmApiTokenDeletion(token)">
                    <icons name="trash" class="w-3.5 h-3.5" />
                    {{ $t('Delete') }}
                  </button>
                </div>
              </div>
            </div>
          </template>
        </tec-action-section>
      </div>
    </div>

    <!-- Token Value Modal -->
    <tec-dialog-modal :show="displayingToken" @close="displayingToken = false">
      <template #title>
        <div class="flex items-center gap-3">
          <span class="lg-card-ico lg-ico-emerald"><icons name="key" class="w-5 h-5" /></span>
          <span class="lg-card-title">{{ $t('API Token') }}</span>
        </div>
      </template>

      <template #content>
        <div class="text-sm">{{ $t('Please copy your new API token. For your security, it won\'t be shown again.') }}</div>

        <div class="api-token-box" v-if="$page.props.jetstream.flash.token">
          {{ $page.props.jetstream.flash.token }}
        </div>
      </template>

      <template #footer>
        <tec-secondary-button @click="displayingToken = false"> {{ $t('Close') }} </tec-secondary-button>
      </template>
    </tec-dialog-modal>

    <!-- API Token Permissions Modal -->
    <tec-dialog-modal :show="managingPermissionsFor" @close="managingPermissionsFor = null">
      <template #title>
        <div class="flex items-center gap-3">
          <span class="lg-card-ico lg-ico-indigo"><icons name="lock-closed" class="w-5 h-5" /></span>
          <span class="lg-card-title">{{ $t('API Token Permissions') }}</span>
        </div>
      </template>

      <template #content>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label v-for="permission in availablePermissions" :key="permission" class="api-perm" :class="{ 'is-on': updateApiTokenForm.permissions.includes(permission) }">
            <tec-checkbox :value="permission" v-model:checked="updateApiTokenForm.permissions" />
            <span>{{ permission }}</span>
          </label>
        </div>
      </template>

      <template #footer>
        <tec-secondary-button @click="managingPermissionsFor = null"> {{ $t('Cancel') }} </tec-secondary-button>

        <tec-button
          class="ml-2"
          @click="updateApiToken"
          :class="{ 'opacity-25': updateApiTokenForm.processing }"
          :disabled="updateApiTokenForm.processing"
        >
          {{ $t('Save') }}
        </tec-button>
      </template>
    </tec-dialog-modal>

    <!-- Delete Token Confirmation Modal -->
    <tec-confirmation-modal :show="apiTokenBeingDeleted" @close="apiTokenBeingDeleted = null">
      <template #title> {{ $t('Delete API Token') }} </template>

      <template #content> {{ $t('Are you sure you would like to delete this API token?') }} </template>

      <template #footer>
        <tec-secondary-button @click="apiTokenBeingDeleted = null"> {{ $t('Cancel') }} </tec-secondary-button>

        <tec-danger-button
          class="ml-2"
          @click="deleteApiToken"
          :class="{ 'opacity-25': deleteApiTokenForm.processing }"
          :disabled="deleteApiTokenForm.processing"
        >
          {{ $t('Delete') }}
        </tec-danger-button>
      </template>
    </tec-confirmation-modal>
  </div>
</template>

<script>
import TecInput from '@/Jetstream/Input.vue';
import TecLabel from '@/Jetstream/Label.vue';
import TecButton from '@/Jetstream/Button.vue';
import TecCheckbox from '@/Jetstream/Checkbox.vue';
import TecInputError from '@/Jetstream/InputError.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import TecFormSection from '@/Jetstream/FormSection.vue';
import TecDangerButton from '@/Jetstream/DangerButton.vue';
import TecActionMessage from '@/Jetstream/ActionMessage.vue';
import TecActionSection from '@/Jetstream/ActionSection.vue';
import TecSectionBorder from '@/Jetstream/SectionBorder.vue';
import TecSecondaryButton from '@/Jetstream/SecondaryButton.vue';
import TecConfirmationModal from '@/Jetstream/ConfirmationModal.vue';

export default {
  components: {
    TecInput,
    TecLabel,
    TecButton,
    TecCheckbox,
    TecInputError,
    TecDialogModal,
    TecFormSection,
    TecDangerButton,
    TecActionMessage,
    TecActionSection,
    TecSectionBorder,
    TecSecondaryButton,
    TecConfirmationModal,
  },

  props: ['tokens', 'availablePermissions', 'defaultPermissions'],

  data() {
    return {
      createApiTokenForm: this.$inertia.form({
        name: '',
        permissions: this.defaultPermissions,
      }),

      updateApiTokenForm: this.$inertia.form({
        permissions: [],
      }),

      deleteApiTokenForm: this.$inertia.form(),

      displayingToken: false,
      managingPermissionsFor: null,
      apiTokenBeingDeleted: null,
    };
  },

  methods: {
    createApiToken() {
      this.createApiTokenForm.post(route('api-tokens.store'), {
        preserveScroll: true,
        onSuccess: () => {
          this.displayingToken = true;
          this.createApiTokenForm.reset();
        },
      });
    },

    manageApiTokenPermissions(token) {
      this.updateApiTokenForm.permissions = token.abilities;

      this.managingPermissionsFor = token;
    },

    updateApiToken() {
      this.updateApiTokenForm.put(route('api-tokens.update', this.managingPermissionsFor), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (this.managingPermissionsFor = null),
      });
    },

    confirmApiTokenDeletion(token) {
      this.apiTokenBeingDeleted = token;
    },

    deleteApiToken() {
      this.deleteApiTokenForm.delete(route('api-tokens.destroy', this.apiTokenBeingDeleted), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (this.apiTokenBeingDeleted = null),
      });
    },
  },
};
</script>

<style scoped>
.api-list { display: grid; gap: 10px; }
.api-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 0.75rem 1rem; border-radius: 16px; background: var(--lg-soft, #f6f8fc); border: 1px solid var(--lg-line, #e6ebf3); }
.api-perm { display: flex; align-items: center; gap: 10px; padding: 0.55rem 0.8rem; border-radius: 12px; font-size: 0.82rem; font-weight: 600; color: #475569; border: 1px solid #e6ebf3; cursor: pointer; transition: all 0.15s ease; }
.api-perm:hover { border-color: #c7d2fe; }
.api-perm.is-on { color: #4338ca; background: rgba(99, 102, 241, 0.1); border-color: rgba(99, 102, 241, 0.4); }
html.dark .api-perm { color: #cbd5e1; border-color: rgba(255, 255, 255, 0.1); }
html.dark .api-perm.is-on { color: #c7d2fe; }
.api-token-box { margin-top: 1rem; padding: 0.7rem 1rem; border-radius: 14px; background: rgba(100, 116, 139, 0.12); font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 0.8rem; color: #475569; overflow-wrap: anywhere; }
html.dark .api-token-box { color: #cbd5e1; }
</style>

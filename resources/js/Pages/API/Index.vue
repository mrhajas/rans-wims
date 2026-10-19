<template>
  <app-layout>
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $t('API Tokens') }}</h2>
    </template>

    <div class="lg-page">
      <div class="max-w-7xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
        <page-header icon="key" :title="$t('API Tokens')" :subtitle="$t('Create and manage tokens that let other services connect to WIMS on your behalf.')" />
        <api-token-manager :tokens="tokens" :available-permissions="availablePermissions" :default-permissions="defaultPermissions" />
      </div>
    </div>
  </app-layout>
</template>

<script>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import ApiTokenManager from '@/Pages/API/ApiTokenManager.vue';

export default {
  components: { ApiTokenManager, AppLayout, PageHeader },
  props: ['tokens', 'availablePermissions', 'defaultPermissions'],
};
</script>

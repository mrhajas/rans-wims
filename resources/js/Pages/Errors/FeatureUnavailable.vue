<template>
  <div class="min-h-screen w-full flex items-center justify-center bg-gray-100 px-4">
    <div class="max-w-md w-full bg-white rounded-lg shadow-md overflow-hidden text-center">
      <div class="p-8">
        <div class="mx-auto flex items-center justify-center h-14 w-14 rounded-full bg-amber-100 text-amber-600 mb-4">
          <icons name="lock-closed" className="h-7 w-7" />
        </div>
        <h1 class="text-xl font-bold text-gray-900 mb-1">{{ $t('Feature Temporarily Unavailable') }}</h1>
        <p v-if="featureName" class="text-sm font-semibold text-gray-500 mb-2">{{ featureName }}</p>
        <p class="text-sm text-gray-500">{{ message }}</p>
        <p class="text-xs text-gray-400 mt-3">{{ $t('Please contact your administrator if you believe you should have access.') }}</p>
      </div>
      <div class="px-8 py-4 bg-gray-50 border-t">
        <Link :href="route('dashboard')" class="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 hover:text-blue-800">
          <icons name="chevron-down" className="h-3.5 w-3.5 rotate-90" />
          {{ $t('Back to Dashboard') }}
        </Link>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    featureName: String,
    message: String,
  },
};
</script>

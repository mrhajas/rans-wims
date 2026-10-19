<template>
  <div class="min-h-screen flex flex-col justify-center text-gray-800 items-center py-8 bg-gradient-to-b from-slate-100 to-gray-200">
    <div>
      <slot name="logo" />
    </div>

    <div class="w-full sm:max-w-md mt-6 px-6 py-5 bg-white shadow-xl border border-gray-100 overflow-hidden sm:rounded-xl">
      <slot />
    </div>

    <div v-if="$slots.footer" class="mt-6">
      <slot name="footer" />
    </div>
  </div>
</template>

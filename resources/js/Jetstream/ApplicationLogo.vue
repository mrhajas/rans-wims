<template>
  <div class="flex items-center">
    <tec-application-mark class="h-[48px] w-[48px] mr-2 flex-shrink-0" />
    <div class="min-w-0 w-max leading-tight">
      <h1 class="brand-wordmark text-[1.44375rem] leading-none whitespace-nowrap">RANS WIMS</h1>
      <p class="text-orange-400 text-[10px] sm:text-xs font-semibold tracking-wide leading-none mt-1 whitespace-nowrap text-right">Your Contractor</p>
    </div>
  </div>
</template>

<script>
import TecApplicationMark from '@/Jetstream/ApplicationMark.vue';

export default {
  components: { TecApplicationMark },
};
</script>

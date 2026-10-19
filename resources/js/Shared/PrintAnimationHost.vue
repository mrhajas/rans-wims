<template>
  <print-animation :show="state.show" :title="state.sheet.title" :code="state.sheet.code" :rows="state.sheet.rows" :footer="state.sheet.footer" @done="done" @cancel="cancel" />
</template>

<script>
import PrintAnimation from '@/Shared/PrintAnimation.vue';
import { printState, printAnimationDone, printAnimationCancel } from '@/Core/printAnimation';

// The one print card of the whole app (mounted in AdminLayout); any page starts it with printWithAnimation().
export default {
  components: { PrintAnimation },
  data() { return { state: printState }; },
  methods: { done: printAnimationDone, cancel: printAnimationCancel },
};
</script>

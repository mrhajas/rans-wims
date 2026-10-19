<template>
  <div>
    <transition name="toast-bounce">
      <div
        @click="show = false"
        v-if="page.props.flash.message && show"
        class="flash-card flash-card-success fixed z-[140] top-24 right-4 w-full max-w-xs cursor-pointer"
      >
        <div class="flex items-center gap-2">
          <span class="flash-icon-circle">
            <icons name="tick" class="w-3.5 h-3.5" />
          </span>
          <span class="flash-title">{{ $t('Success!') }}</span>
          <span class="flash-time ml-auto">{{ timestamp }}</span>
        </div>
        <div class="flash-body">{{ page.props.flash.message }}</div>
        <div class="flash-footer">{{ $t('Workflow Integrated Management System') }}</div>
      </div>
    </transition>

    <transition name="toast-bounce">
      <div
        @click="show = false"
        v-if="(page.props.flash.error || Object.keys(page.props.errors).length > 0) && show"
        class="flash-card flash-card-error fixed z-[140] top-24 right-4 w-full max-w-xs cursor-pointer"
      >
        <div class="flex items-center gap-2">
          <span class="flash-icon-circle">
            <icons name="cross" class="w-3.5 h-3.5" />
          </span>
          <span class="flash-title">{{ $t('Error') }}</span>
          <span class="flash-time ml-auto">{{ timestamp }}</span>
        </div>
        <div v-if="page.props.flash.error" class="flash-body" v-html="page.props.flash.error"></div>
        <div v-else class="flash-body">
          <span v-if="Object.keys(page.props.errors).length === 1">There is one form error.</span>
          <span v-else>There are {{ Object.keys(page.props.errors).length }} form errors.</span>
        </div>
        <div class="flash-footer">{{ $t('Workflow Integrated Management System') }}</div>
      </div>
    </transition>
  </div>
</template>

<script>
import { usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

export default {
  setup() {
    const page = usePage();
    const show = ref(false);
    const timestamp = ref('');

    const reveal = () => {
      show.value = true;
      timestamp.value = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true });
      setTimeout(() => (show.value = false), 5000);
    };

    watch(
      () => page.props.flash,
      (flash) => {
        if (flash?.message || flash?.error) {
          reveal();
        }
      },
      { deep: true, immediate: true },
    );

    // Validation failures redirect back with `errors` populated but no
    // `flash.error` set — without this, the error card would never appear
    // for plain form-validation failures, only for explicit flash.error calls.
    watch(
      () => page.props.errors,
      (errors) => {
        if (errors && Object.keys(errors).length > 0) {
          reveal();
        }
      },
      { deep: true, immediate: true },
    );

    return { page, show, timestamp };
  },
};
</script>

<style scoped>
.flash-card {
  background: #ffffff;
  border: 1.5px solid;
  border-radius: 0.85rem;
  padding: 0.85rem 1rem;
  box-shadow: 0 4px 6px -1px rgba(15, 23, 42, 0.08), 0 10px 24px -6px rgba(15, 23, 42, 0.1);
}
.flash-card-success {
  border-color: #86efac;
}
.flash-card-error {
  border-color: #fca5a5;
}

.flash-icon-circle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.75rem;
  height: 1.75rem;
  border-radius: 9999px;
  border: 1.5px solid currentColor;
  flex-shrink: 0;
}
.flash-icon-circle :deep(svg) {
  display: block;
}
.flash-card-success .flash-icon-circle,
.flash-card-success .flash-title {
  color: #16a34a;
}
.flash-card-error .flash-icon-circle,
.flash-card-error .flash-title {
  color: #dc2626;
}

.flash-title {
  font-weight: 700;
  font-size: 0.9rem;
}
.flash-time {
  font-size: 0.72rem;
  font-weight: 500;
  color: #94a3b8;
  white-space: nowrap;
}
.flash-body {
  margin-top: 0.5rem;
  font-size: 0.85rem;
  line-height: 1.4;
  color: #334155;
}
.flash-footer {
  margin-top: 0.6rem;
  padding-top: 0.45rem;
  border-top: 1px solid #f1f5f9;
  font-size: 0.66rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #94a3b8;
}

.toast-bounce-enter-active {
  animation: toast-bounce-in 0.55s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.toast-bounce-leave-active {
  transition: opacity 0.15s ease-in, transform 0.15s ease-in;
}
.toast-bounce-leave-from {
  opacity: 1;
  transform: scale(1) translateY(0);
}
.toast-bounce-leave-to {
  opacity: 0;
  transform: scale(0.92) translateY(-6px);
}
@keyframes toast-bounce-in {
  0% {
    opacity: 0;
    transform: scale(0.3) translateY(-28px);
  }
  55% {
    opacity: 1;
    transform: scale(1.05) translateY(4px);
  }
  75% {
    transform: scale(0.97) translateY(-2px);
  }
  100% {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}
@media (prefers-reduced-motion: reduce) {
  .toast-bounce-enter-active {
    animation: none;
    transition: opacity 0.15s ease-out;
  }
}
</style>

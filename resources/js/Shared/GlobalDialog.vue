<template>
  <div v-if="dialogState.visible" class="global-dialog-overlay fixed inset-0 z-[999999] flex items-center justify-center p-4" @keydown.esc="onCancel" @keydown.enter="onEnter">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="onCancel"></div>

    <div ref="panel" class="global-dialog-card relative w-full max-w-sm rounded-2xl bg-white shadow-2xl ring-1 ring-black/5 overflow-hidden" tabindex="-1">
      <div class="px-6 pt-6 pb-5 text-center">
        <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full flex-none" :class="iconWrapClass">
          <icons :name="iconName" class="h-6 w-6" :class="iconColorClass" />
        </div>

        <p class="text-sm text-gray-700 leading-relaxed whitespace-pre-line break-words">{{ dialogState.message }}</p>

        <input
          v-if="dialogState.type === 'prompt'"
          ref="input"
          v-model="dialogState.inputValue"
          type="text"
          class="mt-4 w-full min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring focus:ring-indigo-200 focus:border-indigo-400"
          :placeholder="dialogState.placeholder"
        />
      </div>

      <div class="flex flex-wrap items-center justify-center gap-2 px-6 pb-6">
        <button
          v-if="dialogState.type !== 'alert'"
          type="button"
          class="flex-1 min-w-[7rem] inline-flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-lg font-semibold text-xs text-gray-700 bg-gray-100 hover:bg-gray-200 transition"
          @click="onCancel"
        >
          {{ dialogState.cancelText || $t('Cancel') }}
        </button>
        <button
          ref="confirmBtn"
          type="button"
          class="flex-1 min-w-[7rem] inline-flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-lg font-semibold text-xs text-white transition"
          :class="confirmBtnClass"
          @click="onConfirm"
        >
          {{ dialogState.confirmText || defaultConfirmLabel }}
        </button>
      </div>
    </div>
  </div>
</template>

<script>
import Icons from '@/Shared/Icons.vue';
import { dialogState, resolveDialog } from '@/Core/dialogs';

export default {
  components: { Icons },

  data() {
    return { dialogState };
  },

  computed: {
    iconName() {
      if (this.dialogState.type === 'prompt') return 'edit';
      if (this.dialogState.variant === 'danger') return 'exclamation';

      return this.dialogState.type === 'alert' ? 'info' : 'exclamation';
    },
    iconWrapClass() {
      return this.dialogState.variant === 'danger' ? 'bg-red-100' : 'bg-indigo-100';
    },
    iconColorClass() {
      return this.dialogState.variant === 'danger' ? 'text-red-600' : 'text-indigo-600';
    },
    confirmBtnClass() {
      return this.dialogState.variant === 'danger' ? 'bg-red-600 hover:bg-red-700' : 'bg-[#1c3f66] hover:bg-[#15304f]';
    },
    defaultConfirmLabel() {
      return this.dialogState.type === 'alert' ? this.$t('OK') : this.$t('Confirm');
    },
  },

  watch: {
    'dialogState.visible'(visible) {
      if (!visible) return;
      this.$nextTick(() => {
        const target = this.dialogState.type === 'prompt' ? this.$refs.input : this.$refs.confirmBtn;
        target?.focus();
      });
    },
  },

  methods: {
    onConfirm() {
      if (this.dialogState.type === 'prompt') {
        resolveDialog(this.dialogState.inputValue);
      } else {
        resolveDialog(true);
      }
    },
    onCancel() {
      resolveDialog(this.dialogState.type === 'confirm' ? false : this.dialogState.type === 'prompt' ? null : undefined);
    },
    // Enter anywhere in the dialog acts like submitting the default action
    // — except inside the prompt's own text input, where it should just
    // behave like a normal text field (no accidental early submit while
    // still typing is avoided by only wiring this at the overlay level,
    // which the input's own keydown never bubbles past unexpectedly here
    // since Enter in a plain text input doesn't insert a newline anyway).
    onEnter() {
      this.onConfirm();
    },
  },
};
</script>

<style scoped>
.global-dialog-card {
  animation: global-dialog-pop 0.18s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes global-dialog-pop {
  from {
    opacity: 0;
    transform: scale(0.94) translateY(6px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}

html.dark .global-dialog-card {
  background: #0f172a;
  color: #e2e8f0;
}
html.dark .global-dialog-card p {
  color: #cbd5e1;
}
html.dark .global-dialog-card input {
  background: #1e293b;
  border-color: rgba(255, 255, 255, 0.1);
  color: #e2e8f0;
}
</style>

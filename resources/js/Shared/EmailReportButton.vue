<template>
  <div class="inline-block">
    <button
      type="button"
      @click="open = true"
      :data-tip="$t('Send report by email')"
      class="er-trigger-btn inline-flex items-center justify-center h-8 w-8 rounded-full text-white hover:opacity-90 focus:outline-none lg-tip"
     :aria-label="$t('Send report by email')">
      <icons name="email" class="h-4 w-4" />
    </button>

    <modal :show="open" max-width="sm" :closeable="!sending" :transparent="true" @close="close">
      <div class="er-modal-panel">
        <div class="er-modal-header">
          <span class="er-modal-header-icon">
            <icons name="email" class="w-4.5 h-4.5" />
          </span>
          <div class="min-w-0 flex-1">
            <div class="er-modal-title">{{ $t('Email report') }}</div>
            <div class="er-modal-subtitle">{{ $t('A PDF copy of this record will be sent to:') }}</div>
          </div>
          <button type="button" @click="close" class="er-modal-close-btn" :disabled="sending">
            <icons name="cross" class="h-4 w-4" />
          </button>
        </div>

        <div class="er-modal-body">
          <div class="relative">
            <icons name="at" class="er-modal-field-icon-svg" />
            <input
              v-model="email"
              type="email"
              :placeholder="$t('recipient@example.com')"
              class="er-modal-field w-full rounded-xl"
              :disabled="sending"
              @keydown.enter.prevent="send"
            />
          </div>

          <p v-if="error" class="er-modal-message er-modal-message-error">
            <icons name="exclamation" class="w-3.5 h-3.5 flex-none" />
            {{ error }}
          </p>
          <p v-if="message" class="er-modal-message er-modal-message-success">
            <icons name="tick" class="w-3.5 h-3.5 flex-none" />
            {{ message }}
          </p>
        </div>

        <div class="er-modal-footer">
          <button type="button" class="er-modal-cancel-btn px-3 py-2 rounded-xl text-sm" @click="close" :disabled="sending">
            {{ $t('Cancel') }}
          </button>
          <button
            type="button"
            @click="send"
            :disabled="sending || !email"
            class="er-submit-btn inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-sm font-semibold text-white disabled:opacity-50"
          >
            <icons v-if="!sending" name="send" class="w-4 h-4" />
            <span v-else class="er-spinner"></span>
            {{ sending ? $t('Sending...') : $t('Send') }}
          </button>
        </div>
      </div>
    </modal>
  </div>
</template>

<script>
import Modal from '@/Jetstream/Modal.vue';

export default {
  components: { Modal },
  props: {
    type: { type: String, required: true },
    recordId: { type: [String, Number], default: null },
  },
  data() {
    return {
      open: false,
      email: '',
      sending: false,
      message: null,
      error: null,
    };
  },
  methods: {
    close() {
      if (this.sending) return;
      this.open = false;
      this.message = null;
      this.error = null;
    },
    send() {
      if (!this.recordId && this.type !== 'general') {
        this.error = this.$t('No record selected.');
        return;
      }
      this.sending = true;
      this.error = null;
      this.message = null;
      const payload = { type: this.type, email: this.email };
      if (this.recordId) payload.id = this.recordId;
      axios
        .post(this.route('reports.email'), payload)
        .then((res) => {
          this.message = res.data.message || this.$t('Sent successfully');
          setTimeout(() => this.close(), 1200);
        })
        .catch((err) => {
          const data = err?.response?.data;
          this.error = data?.message || data?.error || this.$t('Failed to send. Please try again.');
        })
        .finally(() => {
          this.sending = false;
        });
    },
  },
};
</script>

<style scoped>
.er-trigger-btn {
  background: linear-gradient(145deg, #38bdf8, #1d4ed8);
  box-shadow: 0 6px 16px -6px rgba(29, 78, 216, 0.55);
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.er-trigger-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 10px 20px -6px rgba(29, 78, 216, 0.6);
}
.er-trigger-btn:active {
  transform: translateY(0);
}

.er-modal-panel {
  background: linear-gradient(160deg, rgba(255, 255, 255, 0.88), rgba(224, 242, 254, 0.76));
  backdrop-filter: blur(32px) saturate(180%);
  -webkit-backdrop-filter: blur(32px) saturate(180%);
  border: 1px solid rgba(255, 255, 255, 0.7);
  border-radius: 26px;
  box-shadow: 0 2px 4px rgba(15, 23, 42, 0.04), 0 30px 70px -16px rgba(29, 78, 216, 0.3), 0 12px 24px -8px rgba(15, 23, 42, 0.16),
    inset 0 1px 0 rgba(255, 255, 255, 0.7);
  overflow: hidden;
  text-align: left;
  animation: er-modal-pop 0.35s cubic-bezier(0.16, 1, 0.3, 1) both;
}
@keyframes er-modal-pop {
  from {
    opacity: 0;
    transform: scale(0.96) translateY(6px);
  }
  to {
    opacity: 1;
    transform: scale(1) translateY(0);
  }
}

.er-modal-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 18px 22px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.5);
}
.er-modal-header-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 42px;
  width: 42px;
  border-radius: 14px;
  flex: none;
  background: linear-gradient(145deg, #38bdf8, #1d4ed8);
  color: #fff;
  box-shadow: 0 8px 18px -6px rgba(29, 78, 216, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.35);
  animation: er-icon-float 3s ease-in-out infinite;
}
@keyframes er-icon-float {
  0%, 100% {
    transform: translateY(0) rotate(0deg);
  }
  50% {
    transform: translateY(-2px) rotate(4deg);
  }
}
.er-modal-title {
  font-size: 1.05rem;
  font-weight: 700;
  color: #1e293b;
  line-height: 1.25;
}
.er-modal-subtitle {
  font-size: 0.76rem;
  color: #6b7280;
  margin-top: 1px;
}
.er-modal-close-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 30px;
  width: 30px;
  min-height: 0;
  padding: 0;
  border-radius: 10px;
  flex: none;
  background: rgba(255, 255, 255, 0.6);
  border: 1px solid rgba(255, 255, 255, 0.8);
  color: #64748b;
  transition: background 0.15s ease, color 0.15s ease, transform 0.15s ease;
}
.er-modal-close-btn:hover {
  background: #fee2e2;
  color: #dc2626;
  transform: rotate(90deg);
}
.er-modal-close-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.er-modal-body {
  padding: 18px 22px 20px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.er-modal-field-icon-svg {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  width: 15px;
  height: 15px;
  color: #38bdf8;
  pointer-events: none;
  z-index: 1;
}
.er-modal-field {
  border: 1px solid rgba(15, 23, 42, 0.1);
  background: rgba(255, 255, 255, 0.65);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  padding: 9px 12px 9px 2.25rem;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}
.er-modal-field:focus {
  border-color: #7dd3fc;
  background: rgba(255, 255, 255, 0.92);
  box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.25);
  outline: none;
}

.er-modal-message {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.8rem;
  padding: 6px 10px;
  border-radius: 10px;
}
.er-modal-message-error {
  color: #b91c1c;
  background: rgba(254, 226, 226, 0.7);
}
.er-modal-message-success {
  color: #15803d;
  background: rgba(220, 252, 231, 0.7);
}

.er-modal-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 22px;
  background: rgba(255, 255, 255, 0.35);
  border-top: 1px solid rgba(255, 255, 255, 0.5);
}
.er-modal-cancel-btn {
  background: rgba(255, 255, 255, 0.6);
  color: #374151;
  border: 1px solid rgba(255, 255, 255, 0.8);
  transition: background 0.15s ease, transform 0.15s ease;
}
.er-modal-cancel-btn:hover {
  background: rgba(255, 255, 255, 0.9);
}
.er-modal-cancel-btn:active {
  transform: scale(0.97);
}
.er-submit-btn {
  background: linear-gradient(135deg, #38bdf8, #1d4ed8);
  border: none;
  box-shadow: 0 8px 18px -6px rgba(29, 78, 216, 0.5);
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.er-submit-btn:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 10px 22px -6px rgba(29, 78, 216, 0.6);
}
.er-submit-btn:active:not(:disabled) {
  transform: translateY(0);
}

.er-spinner {
  display: inline-block;
  width: 14px;
  height: 14px;
  border-radius: 9999px;
  border: 2px solid rgba(255, 255, 255, 0.4);
  border-top-color: #fff;
  animation: er-spin 0.7s linear infinite;
}
@keyframes er-spin {
  to {
    transform: rotate(360deg);
  }
}

html.dark .er-modal-panel {
  background: linear-gradient(160deg, rgba(15, 23, 42, 0.92), rgba(30, 41, 59, 0.85));
  border-color: rgba(255, 255, 255, 0.08);
}
html.dark .er-modal-header {
  border-bottom-color: rgba(255, 255, 255, 0.08);
}
html.dark .er-modal-title {
  color: #e2e8f0;
}
html.dark .er-modal-subtitle {
  color: #94a3b8;
}
html.dark .er-modal-close-btn {
  background: rgba(255, 255, 255, 0.06);
  border-color: rgba(255, 255, 255, 0.1);
  color: #94a3b8;
}
html.dark .er-modal-field {
  background: rgba(15, 23, 42, 0.6);
  border-color: rgba(255, 255, 255, 0.1);
  color: #e2e8f0;
}
html.dark .er-modal-footer {
  background: rgba(15, 23, 42, 0.4);
  border-top-color: rgba(255, 255, 255, 0.08);
}
html.dark .er-modal-cancel-btn {
  background: rgba(255, 255, 255, 0.06);
  border-color: rgba(255, 255, 255, 0.1);
  color: #cbd5e1;
}
</style>

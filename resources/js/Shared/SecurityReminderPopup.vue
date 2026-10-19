<template>
  <div v-if="visible" class="srp-overlay fixed inset-0 z-[999998] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="dismiss"></div>

    <div class="srp-card relative w-full max-w-md rounded-2xl overflow-hidden">
      <div class="srp-header px-6 py-5 flex items-center gap-3">
        <div class="flex-shrink-0 h-11 w-11 rounded-full bg-white/20 flex items-center justify-center">
          <icons name="lock-closed" class="h-6 w-6 text-white" />
        </div>
        <div>
          <h2 class="text-white font-extrabold text-lg leading-tight">{{ reminder.title }}</h2>
          <p class="text-white/85 text-xs font-medium">{{ $t('A note from your Super Admin') }}</p>
        </div>
      </div>

      <div class="px-6 py-6">
        <p class="text-gray-600 text-sm leading-relaxed mb-5">{{ reminder.message }}</p>

        <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
          <button type="button" class="srp-btn srp-btn-primary" :disabled="working" @click="setupNow">
            <icons name="lock-closed" class="h-4 w-4" />
            {{ $t('Set Up Now') }}
          </button>
          <button type="button" class="srp-btn srp-btn-secondary" :disabled="working" @click="dismiss">
            {{ $t('Maybe Later') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import Icons from '@/Shared/Icons.vue';

export default {
  components: { Icons },

  data() {
    return {
      dismissed: false,
      working: false,
    };
  },

  computed: {
    reminder() {
      return this.$page.props.pendingSecurityReminder || {};
    },
    visible() {
      return !this.dismissed && !!this.$page.props.pendingSecurityReminder;
    },
  },

  methods: {
    // Mirrors AlertsModal.vue's openNotification() — same endpoint, same
    // "mark read, then it's gone" behavior, just triggered from a pop-up
    // instead of the bell dropdown.
    markRead() {
      if (this.working || !this.reminder.id) {
        return Promise.resolve();
      }
      this.working = true;

      return axios.post(this.route('notifications.alerts.read', this.reminder.id))
        .finally(() => { this.working = false; });
    },

    dismiss() {
      this.dismissed = true;
      this.markRead();
    },

    setupNow() {
      const url = this.reminder.url;
      this.dismissed = true;
      this.markRead().finally(() => {
        if (url) {
          this.$inertia.visit(url);
        }
      });
    },
  },
};
</script>

<style scoped>
.srp-card {
  background: rgba(255, 255, 255, 0.96);
  box-shadow: 0 24px 60px -16px rgba(15, 23, 42, 0.4);
  animation: srp-pop 0.22s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.srp-header { background: linear-gradient(135deg, #fb923c, #e11d48); }

.srp-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  width: 100%; padding: 0.7rem 1.2rem; border-radius: 9999px;
  font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.srp-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.srp-btn-primary { color: #fff; background: linear-gradient(145deg, #fb923c, #e11d48); box-shadow: 0 6px 16px -6px rgba(225, 29, 72, 0.5); }
.srp-btn-primary:hover:not(:disabled) { transform: translateY(-1px); }
.srp-btn-secondary { color: #64748b; background: #f1f5f9; }
.srp-btn-secondary:hover:not(:disabled) { background: #e2e8f0; color: #334155; }

@media (min-width: 640px) {
  .srp-btn { width: auto; }
}

@keyframes srp-pop {
  from { opacity: 0; transform: scale(0.92) translateY(8px); }
  to { opacity: 1; transform: scale(1) translateY(0); }
}

html.dark .srp-card { background: rgba(15, 23, 42, 0.96); }
html.dark .srp-card p { color: #cbd5e1; }
html.dark .srp-btn-secondary { background: rgba(255, 255, 255, 0.08); color: #cbd5e1; }
html.dark .srp-btn-secondary:hover:not(:disabled) { background: rgba(255, 255, 255, 0.14); }
</style>

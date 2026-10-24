<template>
  <admin-layout :title="$t('Task Manager Settings')">
    <div class="tm-bg-decor" aria-hidden="true">
      <span class="tm-blob tm-blob-1"></span>
      <span class="tm-blob tm-blob-2"></span>
    </div>
    <div class="w-full relative z-[1]">
      <div class="tm-hero rounded-2xl p-6 mb-6 relative overflow-hidden">
        <div class="flex items-center gap-4">
          <div class="tm-hero-icon flex items-center justify-center h-14 w-14 rounded-2xl flex-none">
            <icons name="bell" class="w-7 h-7 text-white" />
          </div>
          <div>
            <h1 class="text-xl font-bold text-gray-900">{{ $t('Task Manager Settings') }}</h1>
            <p class="text-sm text-gray-500 mt-0.5">{{ $t('Who gets notified, on which channels and with which words is now set on the Task Manager workflow page.') }}</p>
          </div>
        </div>
      </div>

      <div class="tm-side-card mb-4">
        <div class="flex items-start justify-between gap-4 flex-wrap">
          <div class="flex items-start gap-3">
            <span class="tm-rule-icon"><icons name="bell" class="w-4 h-4" /></span>
            <div>
              <div class="font-semibold text-sm text-gray-800">{{ $t('Task Manager Workflow') }}</div>
              <p class="text-xs text-gray-500 mt-1 max-w-xl">
                {{ $t('Task assigned, status changed, comment added, due reminder, deleted, Super Admin alerts and personal reminders: choose the people, the channels (app, email, SMS, WhatsApp) and the wording of each one there.') }}
              </p>
            </div>
          </div>
          <Link v-if="canEdit" :href="route('task-manager.workflow')" class="tm-submit-btn tm-submit-btn-team">
            <icons name="bell" class="w-4 h-4 mr-1 -ml-0.5" />{{ $t('Open Task Manager Workflow') }}
          </Link>
          <p v-else class="text-xs text-gray-500">{{ $t('Only Super Admin can edit it.') }}</p>
        </div>
      </div>

      <div class="tm-side-card">
        <div class="flex items-center gap-2 mb-1">
          <span class="tm-rule-icon"><icons name="clock" class="w-4 h-4" /></span>
          <div class="font-semibold text-sm text-gray-800">{{ $t('Task Due Reminder timing') }}</div>
        </div>
        <p class="text-xs text-gray-500 mb-4">{{ $t('When a task\'s due reminder goes out. Who receives it is set on the workflow page.') }}</p>

        <div v-if="!canEdit" class="text-sm text-gray-500">{{ $t('Only Super Admin can change this.') }}</div>
        <template v-else>
          <div class="tm-field-label"><icons name="clock" class="w-3 h-3" />{{ $t('Remind') }}</div>
          <css-select v-model.number="offset" class="tm-search-input w-full sm:w-80 px-3 py-2 text-sm rounded-lg mt-1.5 mb-4">
            <option :value="0">{{ $t('On Due Date (default)') }}</option>
            <option :value="8">{{ $t('8 Hours Earlier') }}</option>
            <option :value="12">{{ $t('12 Hours Earlier') }}</option>
            <option :value="24">{{ $t('24 Hours Earlier') }}</option>
          </css-select>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-end">
            <div>
              <div class="tm-field-label"><icons name="lock-closed" class="w-3 h-3" />{{ $t('Confirm your password') }}</div>
              <input v-model="notifPassword" type="password" class="tm-search-input w-full px-3 py-2.5 text-sm rounded-lg mt-1" autocomplete="current-password" />
              <p v-if="notifError" class="text-xs text-red-600 font-semibold mt-1">{{ notifError }}</p>
            </div>
            <div class="flex items-center justify-between sm:justify-end gap-3">
              <span v-if="notifSaved" class="text-xs font-bold text-emerald-600 inline-flex items-center gap-1"><icons name="tick" class="w-3.5 h-3.5" />{{ $t('Saved!') }}</span>
              <button type="button" class="tm-submit-btn tm-submit-btn-team" :disabled="notifSaving" @click="save">
                <icons name="lock-closed" class="w-4 h-4 mr-1 -ml-0.5" />
                {{ notifSaving ? $t('Saving...') : $t('Save') }}
              </button>
            </div>
          </div>
        </template>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

export default {
  props: {
    reminderOffsetHours: { type: Number, default: 0 },
    canEdit: { type: Boolean, default: false },
  },

  components: { AdminLayout, Link },

  data() {
    return {
      offset: this.reminderOffsetHours,
      notifPassword: '',
      notifSaving: false,
      notifSaved: false,
      notifError: '',
    };
  },

  methods: {
    save() {
      if (this.notifSaving) return;
      this.notifError = '';
      if (!this.notifPassword) {
        this.notifError = this.$t('Enter your password to confirm this change.');
        return;
      }
      this.notifSaving = true;
      this.notifSaved = false;
      axios
        .put(this.route('task-manager.settings.notifications.update'), { password: this.notifPassword, reminder_offset_hours: this.offset })
        .then(() => {
          this.notifSaving = false;
          this.notifPassword = '';
          this.notifSaved = true;
          setTimeout(() => { this.notifSaved = false; }, 3000);
        })
        .catch(err => {
          this.notifSaving = false;
          this.notifError = err.response?.data?.errors?.password?.[0] || this.$t('Could not save right now.');
        });
    },
  },
};
</script>

<style scoped>
.tm-bg-decor { position: fixed; inset: 0; z-index: 0; overflow: hidden; pointer-events: none; }
.tm-blob { position: absolute; border-radius: 9999px; filter: blur(80px); opacity: 0.25; }
.tm-blob-1 { top: -80px; left: -60px; height: 320px; width: 320px; background: radial-gradient(circle, #c7d2fe, transparent 70%); }
.tm-blob-2 { top: 100px; right: -100px; height: 360px; width: 360px; background: radial-gradient(circle, #bfdbfe, transparent 70%); }

.tm-hero { background: linear-gradient(135deg, rgba(255, 255, 255, 0.9), rgba(238, 242, 255, 0.6)); border: 1px solid rgba(255, 255, 255, 0.8); backdrop-filter: blur(20px) saturate(160%); box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 20px 40px -12px rgba(99, 102, 241, 0.12); }
.tm-hero-icon { background: linear-gradient(145deg, #818cf8, #6366f1); box-shadow: 0 8px 20px -6px rgba(99, 102, 241, 0.5); }

.tm-side-card {
  background: rgba(255, 255, 255, 0.7);
  border: 1.5px solid rgba(99, 102, 241, 0.16);
  border-radius: 16px;
  padding: 16px;
  backdrop-filter: blur(14px);
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 12px 28px -10px rgba(99, 102, 241, 0.22);
  animation: tm-card-glow 3.2s ease-in-out infinite;
}
@keyframes tm-card-glow {
  0%, 100% { box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 12px 28px -10px rgba(99, 102, 241, 0.22), 0 0 0 0 rgba(99, 102, 241, 0.18); }
  50% { box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 12px 28px -10px rgba(99, 102, 241, 0.22), 0 0 0 4px rgba(99, 102, 241, 0); }
}
.tm-rule-icon { display: inline-flex; align-items: center; justify-content: center; height: 26px; width: 26px; border-radius: 8px; background: rgba(99, 102, 241, 0.12); color: #4f46e5; }
.tm-field-label { display: inline-flex; align-items: center; gap: 5px; font-size: 0.7rem; font-weight: 700; color: #4f46e5; text-transform: uppercase; letter-spacing: 0.02em; }
.tm-toggle { display: inline-flex; align-items: center; gap: 6px; font-size: 0.82rem; color: #334155; }
.tm-channel-chip { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 9999px; font-size: 0.76rem; font-weight: 700; background: rgba(100, 116, 139, 0.1); color: #64748b; border: 1.5px solid transparent; }
.tm-channel-chip-inapp { opacity: 0.7; cursor: default; }
.tm-channel-chip-active { background: rgba(99, 102, 241, 0.14); color: #4338ca; border-color: rgba(99, 102, 241, 0.35); }
.tm-channel-note { display: flex; align-items: flex-start; gap: 5px; font-size: 0.7rem; color: #b45309; background: rgba(217, 119, 6, 0.1); border-radius: 8px; padding: 6px 8px; margin-top: 8px; }
.tm-channel-note-info { color: #4338ca; background: rgba(99, 102, 241, 0.1); }
.tm-add-user-btn { display: inline-flex; align-items: center; justify-content: center; height: 44px; width: 44px; border-radius: 12px; background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; flex: none; box-shadow: 0 6px 16px -4px rgba(99, 102, 241, 0.5); }
.tm-add-user-btn :deep(div) { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; }
.tm-add-user-btn :deep(svg) { width: 16px; height: 16px; flex: none; color: #fff; }
.tm-add-user-btn:disabled { opacity: 0.5; }
.tm-user-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 5px 3px 10px; border-radius: 9999px; font-size: 0.74rem; font-weight: 600; background: rgba(99, 102, 241, 0.1); color: #4338ca; }
.tm-user-chip-remove { display: inline-flex !important; align-items: center; justify-content: center; height: 17px !important; width: 17px !important; min-height: 0 !important; padding: 0 !important; border-radius: 9999px; background: rgba(67, 56, 202, 0.18); color: #4338ca; flex: none; }
.tm-user-chip-remove:hover { background: rgba(220, 38, 38, 0.18); color: #dc2626; }
.tm-user-chip-remove :deep(div) { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; }
.tm-user-chip-remove :deep(svg) { width: 9px; height: 9px; flex: none; }
.tm-search-input { border: 1.5px solid rgba(15, 23, 42, 0.14); background: rgba(255, 255, 255, 0.85); }
.tm-search-input:focus { outline: none; border-color: #818cf8; background: #fff; box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.2); }
.tm-submit-btn { border: none; border-radius: 12px; padding: 10px 18px; min-height: 0; text-transform: none; letter-spacing: normal; color: #fff; display: inline-flex; align-items: center; }
.tm-submit-btn-team { background: linear-gradient(135deg, #6366f1, #8b5cf6); box-shadow: 0 8px 18px -5px rgba(99, 102, 241, 0.5); }
.tm-submit-btn:disabled { opacity: 0.6; }

.tm-superadmin-card { border-color: rgba(220, 38, 38, 0.18); animation: none; }
.tm-rule-icon-superadmin { background: rgba(220, 38, 38, 0.12); color: #dc2626; }
.tm-switch { position: relative; display: inline-flex; align-items: center; cursor: pointer; flex: none; }
.tm-switch input { position: absolute; opacity: 0; width: 1px; height: 1px; }
.tm-switch-track { display: inline-flex; align-items: center; width: 42px; height: 24px; border-radius: 9999px; background: rgba(100, 116, 139, 0.3); transition: background 0.2s ease; padding: 3px; }
.tm-switch input:checked + .tm-switch-track { background: #dc2626; }
.tm-switch-thumb { height: 18px; width: 18px; border-radius: 9999px; background: #fff; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.3); transition: transform 0.2s ease; }
.tm-switch input:checked + .tm-switch-track .tm-switch-thumb { transform: translateX(18px); }
.tm-switch input:focus-visible + .tm-switch-track { outline: 2px solid #dc2626; outline-offset: 2px; }
.tm-superadmin-confirm { margin-top: 14px; padding-top: 14px; border-top: 1px dashed rgba(220, 38, 38, 0.25); }
.tm-submit-btn-danger { background: linear-gradient(135deg, #dc2626, #b91c1c); box-shadow: 0 8px 18px -5px rgba(220, 38, 38, 0.5); }
.tm-cancel-btn { padding: 10px 16px; border-radius: 12px; font-size: 0.82rem; font-weight: 600; color: #64748b; background: rgba(100, 116, 139, 0.1); min-height: 0; }

html.dark .tm-side-card { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.1); }
html.dark .tm-superadmin-card { border-color: rgba(220, 38, 38, 0.3); }
html.dark .tm-toggle { color: #cbd5e1; }
html.dark .tm-search-input { background: rgba(255, 255, 255, 0.06); border-color: rgba(255, 255, 255, 0.14); color: #e2e8f0; }
</style>
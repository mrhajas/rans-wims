<template>
  <tec-dialog-modal :show="show" max-width="md" @close="close">
    <template #title>
      <div class="flex items-center gap-3">
        <span class="lg-card-ico lg-ico-amber"><icons name="key" class="h-5 w-5" /></span>
        <div class="lg-card-title">{{ $t('Reset Password') }}</div>
      </div>
    </template>

    <template #content>
      <p class="text-sm text-slate-700 mb-4">{{ $t('Choose a user and generate a new password. It is shown once, here: give it to the user, who should change it after signing in.') }}</p>

      <auto-complete id="reset-user" :label="$t('User')" :suggestions="users" v-model="userId" :disabled="busy || !!result" />
      <div v-if="error" class="text-red-600 text-sm mt-2">{{ error }}</div>

      <div v-if="result" class="rp-result" role="status">
        <div class="rp-for">{{ $t('New password for') }} <b>{{ result.user.name }}</b> <span class="rp-un">@{{ result.user.username }}</span></div>
        <div class="rp-box">
          <code class="rp-code" :title="$t('Click to copy')" @click="copy"><decrypt-text :text="result.password" /></code>
          <button type="button" class="lg-btn tone" style="--tone: #4f46e5" @click="copy">
            <span class="tone-ico"><icons :name="copied ? 'tick' : 'duplicate'" class="w-4 h-4" /></span>{{ copied ? $t('Copied') : $t('Copy') }}
          </button>
        </div>
        <p class="rp-note">{{ $t('This password will not be shown again. The old password no longer works.') }}</p>
      </div>
    </template>

    <template #footer>
      <div class="flex items-center justify-end gap-2">
        <button type="button" class="lg-btn lg-btn-soft" @click="close">{{ result ? $t('Done') : $t('Cancel') }}</button>
        <button v-if="!result" type="button" class="lg-btn lg-btn-primary" :disabled="busy || !userId" @click="generate">
          <icons :name="busy ? 'refresh' : 'key'" class="h-4 w-4" :class="{ 'animate-spin': busy }" />
          <span>{{ $t('Generate New Password') }}</span>
        </button>
        <button v-else type="button" class="lg-btn tone" style="--tone: #475569" @click="again">
          <span class="tone-ico"><icons name="refresh" class="w-4 h-4" /></span>{{ $t('Reset Another User') }}
        </button>
      </div>
    </template>
  </tec-dialog-modal>
</template>

<script>
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DecryptText from '@/Shared/DecryptText.vue';

export default {
  components: { TecDialogModal, AutoComplete, DecryptText },
  props: { show: Boolean, users: { type: Array, default: () => [] } },
  emits: ['close'],
  data() { return { userId: null, busy: false, error: '', result: null, copied: false }; },
  watch: { show(v) { if (v) this.again(); } },
  methods: {
    again() { this.userId = null; this.error = ''; this.result = null; this.copied = false; },
    close() { this.result = null; this.$emit('close'); },
    generate() {
      if (!this.userId || this.busy) return;
      this.busy = true;
      this.error = '';
      window.axios
        .post(this.route('users.reset-password'), { user_id: this.userId })
        .then(res => { this.result = res.data; })
        .catch(e => { this.error = (e.response && e.response.data && (e.response.data.message || Object.values(e.response.data.errors || {})[0])) || this.$t('The password could not be reset.'); })
        .finally(() => { this.busy = false; });
    },
    copy() {
      const text = this.result && this.result.password;
      if (!text) return;
      const done = () => { this.copied = true; setTimeout(() => (this.copied = false), 2000); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, done);
      else done();
    },
  },
};
</script>

<style>
.rp-result { margin-top: 16px; padding: 14px; border-radius: 14px; background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(79, 70, 229, 0.08)); border: 1px solid rgba(16, 185, 129, 0.35); }
.rp-for { font-size: 0.85rem; color: #334155; }
.rp-un { color: #64748b; }
.rp-box { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
.rp-code { flex: 1 1 200px; padding: 10px 14px; border-radius: 10px; background: #fff; border: 1px dashed #94a3b8; font-size: 1.25rem; font-weight: 800; letter-spacing: 0.08em; color: #0f172a; user-select: all; cursor: copy; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; overflow-wrap: anywhere; }
.rp-note { margin-top: 10px; font-size: 0.78rem; color: #b45309; font-weight: 600; }
html.dark .rp-for { color: #cbd5e1; } html.dark .rp-code { background: #0f172a; color: #f1f5f9; border-color: #475569; }
</style>

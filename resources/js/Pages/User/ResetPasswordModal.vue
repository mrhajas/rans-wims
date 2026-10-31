<template>
  <tec-dialog-modal :show="show" max-width="md" @close="close">
    <template #title>
      <div class="flex items-center gap-3">
        <span class="lg-card-ico lg-ico-amber"><icons name="key" class="h-5 w-5" /></span>
        <div class="min-w-0">
          <div class="lg-card-title">{{ $t('Reset Password') }}</div>
          <div class="rp-sub">{{ result ? $t('Give this password to the user') : $t('Make a new password for a user') }}</div>
        </div>
      </div>
    </template>

    <template #content>
      <div v-if="!result" @keydown.enter.prevent="generate">
        <p class="rp-help">{{ $t('Choose a user and generate a new password. It is shown once, here: give it to the user, who should change it after signing in.') }}</p>
        <auto-complete id="reset-user" :label="$t('User')" :suggestions="users" v-model="userId" :disabled="busy" />
        <p v-if="error" class="rp-error" role="alert"><icons name="info" class="w-4 h-4" />{{ error }}</p>
        <p class="rp-warn"><icons name="lock-closed" class="w-4 h-4" />{{ $t('The old password stops working as soon as you generate the new one.') }}</p>
      </div>

      <div v-else class="rp-result" role="status">
        <div class="rp-ok"><span class="rp-check"><icons name="tick" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="rp-ok-title">{{ $t('New password ready') }}</div>
            <div class="rp-ok-for"><b>{{ result.user.name }}</b> <span class="rp-un">@{{ result.user.username }}</span></div>
          </div>
        </div>
        <button type="button" class="rp-code" :title="$t('Click to copy')" @click="copy('password')">
          <decrypt-text :text="result.password" />
        </button>
        <div class="rp-btns">
          <button type="button" class="lg-btn tone tone-solid" style="--tone: #4f46e5" @click="copy('password')">
            <span class="tone-ico"><icons :name="copied === 'password' ? 'tick' : 'duplicate'" class="w-4 h-4" /></span>{{ copied === 'password' ? $t('Copied') : $t('Copy password') }}
          </button>
          <button type="button" class="lg-btn tone" style="--tone: #0f766e" @click="copy('login')">
            <span class="tone-ico"><icons :name="copied === 'login' ? 'tick' : 'user'" class="w-4 h-4" /></span>{{ copied === 'login' ? $t('Copied') : $t('Copy username and password') }}
          </button>
        </div>
        <p class="rp-note"><icons name="exclamation" class="w-4 h-4" />{{ $t('This password will not be shown again. The old password no longer works.') }}</p>
      </div>
    </template>

    <template #footer>
      <div class="rp-foot">
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
  data() { return { userId: null, busy: false, error: '', result: null, copied: '' }; },
  watch: { show(v) { if (v) this.again(); else this.wipe(); } },
  beforeUnmount() { clearTimeout(this._c); },
  methods: {
    again() { this.userId = null; this.error = ''; this.result = null; this.copied = ''; },
    // the password is not kept in the page once the window is closed
    wipe() { this.result = null; this.copied = ''; },
    close() { this.wipe(); this.$emit('close'); },
    generate() {
      if (!this.userId || this.busy) return;
      this.busy = true;
      this.error = '';
      window.axios
        .post(this.route('users.reset-password'), { user_id: this.userId })
        .then(res => { this.result = res.data; })
        .catch(e => {
          const d = (e.response && e.response.data) || {};
          this.error = d.message || Object.values(d.errors || {})[0] || this.$t('The password could not be reset. Please try again.');
        })
        .finally(() => { this.busy = false; });
    },
    copy(what) {
      if (!this.result) return;
      const text = what === 'login' ? this.$t('Username') + ': ' + this.result.user.username + '\n' + this.$t('Password') + ': ' + this.result.password : this.result.password;
      const done = () => { this.copied = what; clearTimeout(this._c); this._c = setTimeout(() => (this.copied = ''), 2000); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, done);
      else done();
    },
  },
};
</script>

<style>
.rp-sub { font-size: 0.78rem; color: #64748b; font-weight: 600; margin-top: 1px; }
.rp-help { font-size: 0.88rem; color: #475569; margin-bottom: 14px; line-height: 1.5; }
.rp-error { display: flex; align-items: flex-start; gap: 6px; margin-top: 10px; font-size: 0.82rem; font-weight: 700; color: #dc2626; }
.rp-warn { display: flex; align-items: center; gap: 6px; margin-top: 14px; padding: 9px 12px; border-radius: 12px; font-size: 0.78rem; font-weight: 600; color: #92400e; background: rgba(245, 158, 11, 0.12); }
.rp-result { display: grid; gap: 14px; }
.rp-ok { display: flex; align-items: center; gap: 12px; }
.rp-check { display: grid; place-items: center; width: 40px; height: 40px; flex: none; border-radius: 50%; color: #fff; background: linear-gradient(145deg, #34d399, #059669); box-shadow: 0 8px 16px -8px rgba(5, 150, 105, 0.8); animation: rp-pop 0.5s cubic-bezier(0.2, 0.9, 0.3, 1.5) both; }
.rp-ok-title { font-size: 1rem; font-weight: 800; color: #0f172a; }
.rp-ok-for { font-size: 0.85rem; color: #334155; overflow-wrap: anywhere; }
.rp-un { color: #64748b; }
.rp-code { display: block; width: 100%; padding: 16px 14px; border-radius: 16px; border: 1px dashed #94a3b8; background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(79, 70, 229, 0.08)); text-align: center; font-size: 1.7rem; font-weight: 800; letter-spacing: 0.14em; color: #0f172a; cursor: copy; user-select: all; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; overflow-wrap: anywhere; transition: border-color 0.2s, box-shadow 0.2s; }
.rp-code:hover, .rp-code:focus-visible { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2); }
.rp-btns { display: flex; flex-wrap: wrap; gap: 8px; }
.rp-btns .lg-btn { flex: 1 1 200px; justify-content: center; }
.rp-note { display: flex; align-items: flex-start; gap: 6px; font-size: 0.78rem; color: #b45309; font-weight: 700; }
.rp-foot { display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
@keyframes rp-pop { from { transform: scale(0.3) rotate(-20deg); opacity: 0; } to { transform: none; opacity: 1; } }
html.dark .rp-sub { color: #94a3b8; } html.dark .rp-help, html.dark .rp-ok-for { color: #cbd5e1; } html.dark .rp-ok-title, html.dark .rp-code { color: #f1f5f9; } html.dark .rp-code { border-color: #475569; }
@media (max-width: 520px) { .rp-code { font-size: 1.35rem; letter-spacing: 0.1em; } .rp-foot { flex-direction: column-reverse; align-items: stretch; } .rp-foot .lg-btn { width: 100%; justify-content: center; } }
@media (prefers-reduced-motion: reduce) { .rp-check { animation: none; } }
</style>

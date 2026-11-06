<template>
  <tec-dialog-modal :show="show" max-width="md" @close="$emit('close')">
    <template #title>
      <span class="rd-head"><span class="rd-badge"><icons name="refresh" class="w-5 h-5" /></span><span><b>{{ $t('Put back into the live log') }}</b><small>{{ label }}</small></span></span>
    </template>
    <template #content>
      <template v-if="!result">
        <p class="rd-text">{{ $t('The records go back into their own log exactly as they were, with the same numbers and dates. They stay there: the next run of WIMS Logs does not archive them again. The archive keeps its own copy.') }}</p>
        <div class="rd-pass">
          <icons name="lock-closed" class="w-4 h-4 rd-pass-ico" />
          <input v-model="password" :type="showPw ? 'text' : 'password'" class="rd-input" autocomplete="current-password" :placeholder="$t('Enter your password to confirm')" @keyup.enter="go" />
          <button type="button" class="rd-eye" :aria-label="showPw ? $t('Hide the password') : $t('Show the password')" @click="showPw = !showPw"><icons :name="showPw ? 'eye-off' : 'eye'" class="w-4 h-4" /></button>
        </div>
        <p v-if="error" class="rd-err">{{ error }}</p>
      </template>
      <div v-else class="rd-done">
        <span class="rd-tick"><icons name="tick" class="w-6 h-6" /></span>
        <b>{{ $t(':n record(s) put back', { n: result.restored }) }}</b>
        <small v-if="result.already_live">{{ $t(':n were already in the live log.', { n: result.already_live }) }}</small>
        <small v-if="result.previously">{{ $t(':n had been put back before.', { n: result.previously }) }}</small>
        <small v-if="result.missing" class="is-warn">{{ $t(':n could not be found in the archive.', { n: result.missing }) }}</small>
      </div>
    </template>
    <template #footer>
      <button type="button" class="lg-btn lg-btn-soft" @click="$emit('close')">{{ result ? $t('Close') : $t('Cancel') }}</button>
      <button v-if="!result" type="button" class="lg-btn tone tone-solid" style="--tone: #0f766e" :disabled="!password || busy" @click="go"><span class="tone-ico"><icons name="refresh" class="w-4 h-4" :class="{ 'rd-spin': busy }" /></span>{{ busy ? $t('Putting back...') : $t('Put back') }}</button>
    </template>
  </tec-dialog-modal>
</template>

<script>
import TecDialogModal from '@/Jetstream/DialogModal.vue';

// "Put back": asks for the password, then the page does the putting back and hands the result in.
export default {
  components: { TecDialogModal },
  props: { show: Boolean, label: String, busy: Boolean, error: String, result: { type: Object, default: null } },
  emits: ['close', 'confirm'],
  data() { return { password: '', showPw: false }; },
  watch: { show(v) { if (v) { this.password = ''; this.showPw = false; } } },
  methods: { go() { if (this.password && !this.busy) this.$emit('confirm', this.password); } },
};
</script>

<style scoped>
.rd-head { display: flex; align-items: center; gap: 0.75rem; } .rd-head b { display: block; font-size: 1.05rem; font-weight: 900; } .rd-head small { display: block; font-size: 0.76rem; font-weight: 600; color: #64748b; }
.rd-badge { flex: none; width: 2.6rem; height: 2.6rem; display: grid; place-items: center; border-radius: 0.95rem; color: #fff; background: linear-gradient(135deg, #14b8a6, #0f766e); box-shadow: 0 10px 18px -10px #0f766e; }
.rd-text { margin: 0 0 1rem; font-size: 0.84rem; line-height: 1.55; color: #475569; } html.dark .rd-text { color: #cbd5e1; }
.rd-pass { position: relative; } .rd-input { width: 100%; min-height: 2.7rem; padding: 0 2.6rem 0 2.3rem; border-radius: 0.8rem; border: 1.5px solid #e2e8f0; background: transparent; color: inherit; } .rd-input:focus { outline: none; border-color: #0f766e; box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.18); }
.rd-pass-ico { position: absolute; left: 0.8rem; top: 50%; transform: translateY(-50%); color: #64748b; pointer-events: none; }
.rd-eye { position: absolute; right: 0.35rem; top: 50%; transform: translateY(-50%); width: 2rem; height: 2rem; display: grid; place-items: center; border: 0; border-radius: 0.6rem; cursor: pointer; color: #64748b; background: transparent; } .rd-eye:hover { background: rgba(148, 163, 184, 0.2); }
.rd-err { margin: 0.6rem 0 0; font-size: 0.8rem; font-weight: 700; color: #dc2626; }
.rd-done { display: grid; justify-items: center; gap: 0.35rem; padding: 0.8rem 0; text-align: center; animation: rd-pop 0.45s cubic-bezier(0.3, 1.5, 0.5, 1); } .rd-done b { font-size: 1.05rem; } .rd-done small { font-size: 0.78rem; color: #64748b; } .rd-done small.is-warn { color: #b45309; font-weight: 700; }
.rd-tick { width: 3.2rem; height: 3.2rem; display: grid; place-items: center; border-radius: 50%; color: #fff; background: linear-gradient(135deg, #34d399, #059669); box-shadow: 0 12px 20px -10px #059669; }
.rd-spin { animation: rd-rot 0.8s linear infinite; } @keyframes rd-rot { to { transform: rotate(360deg); } } @keyframes rd-pop { from { opacity: 0; transform: scale(0.85); } }
@media (prefers-reduced-motion: reduce) { .rd-done, .rd-spin { animation: none; } }
</style>

<template>
  <modal :show="show" max-width="lg" :closeable="!sending" @close="close">
    <div class="ea-panel" :class="{ 'is-sending': sending }">
      <header class="ea-head">
        <span class="ea-ico"><icons name="email" class="w-5 h-5" /></span>
        <div class="min-w-0 flex-1">
          <h3 class="ea-title">{{ $t('Email attendance report') }}</h3>
          <p class="ea-sub">{{ $t('A PDF of the report you are looking at is attached.') }}</p>
        </div>
        <button type="button" class="ea-x" :disabled="sending" :aria-label="$t('Close')" @click="close"><icons name="cross" class="w-4 h-4" /></button>
      </header>

      <transition name="ea-swap" mode="out-in">
        <div v-if="done" key="done" class="ea-done" role="status">
          <span class="ea-done-ring"><icons name="tick" class="w-7 h-7" /></span>
          <b>{{ $t('Report sent') }}</b>
          <p>{{ done }}</p>
        </div>

        <div v-else key="form" class="ea-body">
          <div class="ea-file">
            <span class="ea-file-ico">PDF</span>
            <div class="min-w-0 flex-1">
              <b class="ea-file-name">{{ $t('Attendance report') }}</b>
              <span class="ea-file-meta">
                <span><icons name="calendar" class="w-3.5 h-3.5" />{{ periodText }}</span>
                <span><icons name="user" class="w-3.5 h-3.5" />{{ employee ? employee.name : $t('All employees') }}</span>
              </span>
            </div>
            <icons name="clip" class="w-4 h-4 ea-file-clip" />
          </div>

          <div class="ea-section">
            <span class="ea-label">{{ $t('Recipients') }}</span>
            <span class="ea-count" :class="{ 'is-zero': !recipientCount }">{{ recipientCount }}</span>
          </div>

          <label class="ea-person" :class="{ 'is-on': toEmployee && employeeReady, 'is-off': !employeeReady }">
            <span class="ea-avatar" :class="{ 'is-empty': !employee }">
              <template v-if="employee">{{ initials }}</template>
              <icons v-else name="user" class="w-4 h-4" />
            </span>
            <span class="ea-person-main">
              <b>{{ $t('Send to the employee') }}</b>
              <span v-if="employeeReady">{{ employee.name }} &middot; {{ employee.email }}</span>
              <span v-else-if="employee">{{ $t(':name has no email address on their profile.', { name: employee.name }) }}</span>
              <span v-else>{{ $t('Choose an employee in the filter first, then you can send the report to them.') }}</span>
            </span>
            <input v-model="toEmployee" class="ea-switch-input" type="checkbox" :disabled="!employeeReady || sending" />
            <span class="ea-switch" aria-hidden="true"></span>
          </label>

          <div class="ea-field">
            <label for="ea-typed" class="ea-label">{{ $t('Other email addresses') }}</label>
            <div class="ea-chipbox" :class="{ 'is-bad': hasBad, 'is-disabled': sending }" @click="$refs.draft && $refs.draft.focus()">
              <transition-group name="ea-chip">
                <span v-for="(a, i) in addresses" :key="a.address" class="ea-chip" :class="{ 'is-bad': !a.ok }" :title="a.ok ? a.address : $t('not valid')">
                  <icons :name="a.ok ? 'email' : 'exclamation'" class="w-3 h-3" />{{ a.address }}
                  <button type="button" class="ea-chip-x" :disabled="sending" :aria-label="$t('Remove') + ' ' + a.address" @click.stop="remove(i)"><icons name="cross" class="w-2.5 h-2.5" /></button>
                </span>
              </transition-group>
              <input
                id="ea-typed"
                ref="draft"
                v-model="draft"
                class="ea-draft"
                type="text"
                inputmode="email"
                autocomplete="off"
                :disabled="sending || addresses.length >= 10"
                :placeholder="addresses.length ? '' : $t('name@company.com, another@company.com')"
                @keydown="onKey"
                @blur="commit"
                @paste.prevent="onPaste"
              />
            </div>
            <p class="ea-hint">
              <span>{{ $t('Press Enter or a comma after each address. Up to 10.') }}</span>
              <span v-if="addresses.length" class="ea-hint-count">{{ addresses.length }}/10</span>
            </p>
          </div>

          <transition name="ea-msg">
            <p v-if="error" class="ea-msg is-error"><icons name="exclamation" class="w-4 h-4 flex-none" />{{ error }}</p>
          </transition>
        </div>
      </transition>

      <footer class="ea-foot">
        <span class="ea-summary">
          <template v-if="done">{{ $t('Closing…') }}</template>
          <template v-else-if="hasBad">{{ $t('Remove or fix the addresses marked in red.') }}</template>
          <template v-else-if="recipientCount">{{ recipientCount === 1 ? $t('Sending to 1 recipient') : $t('Sending to :count recipients', { count: recipientCount }) }}</template>
          <template v-else>{{ $t('Add at least one recipient.') }}</template>
        </span>
        <div class="ea-actions">
          <button type="button" class="lg-btn lg-btn-soft" :disabled="sending" @click="close">{{ done ? $t('Close') : $t('Cancel') }}</button>
          <button v-if="!done" type="button" class="lg-btn lg-btn-primary ea-send" :disabled="sending || !canSend" @click="send">
            <span v-if="sending" class="ea-spinner" aria-hidden="true"></span>
            <icons v-else name="send" class="w-4 h-4" />{{ sending ? $t('Sending...') : $t('Send report') }}
          </button>
        </div>
      </footer>
    </div>
  </modal>
</template>

<script>
import Modal from '@/Jetstream/Modal.vue';

const EMAIL = /^[^\s@,;]+@[^\s@,;]+\.[^\s@,;]+$/;

// Sends the attendance report as it is on screen (same period, employee and search) to the employee and/or to typed addresses.
export default {
  components: { Modal },
  props: {
    show: Boolean,
    // the filters the report was made with (user_id, date, date_from, date_to, search, status)
    query: { type: Object, default: () => ({}) },
    employee: { type: Object, default: null },
    periodText: { type: String, default: '' },
  },
  emits: ['close'],
  data() {
    return { toEmployee: false, addresses: [], draft: '', sending: false, error: '', done: '' };
  },
  computed: {
    employeeReady() {
      return !!(this.employee && this.employee.email && EMAIL.test(this.employee.email));
    },
    initials() {
      const parts = (this.employee?.name || '').trim().split(/\s+/);
      return ((parts[0] || '').charAt(0) + (parts.length > 1 ? parts[parts.length - 1].charAt(0) : '')).toUpperCase();
    },
    // the chips plus whatever is still being typed, so nothing typed is lost when Send is pressed
    parsed() {
      const seen = new Set(this.addresses.map(a => a.address));
      const pending = this.split(this.draft).filter(a => !seen.has(a.address));
      return [...this.addresses, ...pending];
    },
    hasBad() {
      return this.parsed.some(a => !a.ok);
    },
    recipientCount() {
      return this.parsed.filter(a => a.ok).length + (this.toEmployee && this.employeeReady ? 1 : 0);
    },
    canSend() {
      return !this.hasBad && this.parsed.length <= 10 && this.recipientCount > 0;
    },
  },
  watch: {
    show(v) {
      if (v) {
        this.error = '';
        this.done = '';
        this.toEmployee = this.employeeReady;
      }
    },
    employeeReady(v) {
      if (!v) this.toEmployee = false;
    },
  },
  methods: {
    split(text) {
      const seen = new Set();
      return String(text || '')
        .split(/[\s,;]+/)
        .map(a => a.trim().toLowerCase())
        .filter(a => a && !seen.has(a) && seen.add(a))
        .map(address => ({ address, ok: EMAIL.test(address) }));
    },
    add(text) {
      const known = new Set(this.addresses.map(a => a.address));
      this.split(text).forEach(a => {
        if (!known.has(a.address) && this.addresses.length < 10) {
          known.add(a.address);
          this.addresses.push(a);
        }
      });
      this.error = '';
    },
    commit() {
      if (this.draft.trim()) this.add(this.draft);
      this.draft = '';
    },
    remove(index) {
      this.addresses.splice(index, 1);
      this.$nextTick(() => this.$refs.draft && this.$refs.draft.focus());
    },
    onKey(event) {
      if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
        event.preventDefault();
        this.send();
      } else if (['Enter', ',', ';', ' ', 'Tab'].includes(event.key) && this.draft.trim()) {
        event.preventDefault();
        this.commit();
      } else if (event.key === 'Backspace' && !this.draft && this.addresses.length) {
        this.addresses.pop();
      }
    },
    onPaste(event) {
      this.add(this.draft + ' ' + (event.clipboardData || window.clipboardData).getData('text'));
      this.draft = '';
    },
    close() {
      if (!this.sending) this.$emit('close');
    },
    send() {
      this.commit();
      if (!this.canSend || this.sending) return;
      this.sending = true;
      this.error = '';
      this.done = '';
      axios
        .post(this.route('digital-attendance-reports.email'), { ...this.query, to_employee: this.toEmployee && this.employeeReady, addresses: this.parsed.map(a => a.address).join(', ') })
        .then(res => {
          this.done = res.data.message;
          this.addresses = [];
          setTimeout(() => this.close(), 2400);
        })
        .catch(err => {
          const d = err && err.response && err.response.data;
          const first = d && d.errors ? Object.values(d.errors)[0][0] : null;
          this.error = first || (d && d.message) || this.$t('Failed to send. Please try again.');
        })
        .finally(() => (this.sending = false));
    },
  },
};
</script>

<style scoped>
.ea-panel { position: relative; overflow: hidden; border-radius: 24px; background: var(--lg-card, #fff); border: 1px solid var(--lg-line, #e2e8f0); }
.ea-panel::before { content: ''; position: absolute; inset: 0 0 auto; height: 3px; background: linear-gradient(90deg, #38bdf8, #1d4ed8, #0f2540); }
.ea-panel.is-sending::before { background-size: 200% 100%; background-image: linear-gradient(90deg, #38bdf8, #1d4ed8, #38bdf8); animation: ea-bar 1s linear infinite; }

.ea-head { display: flex; align-items: center; gap: 12px; padding: 18px 20px 14px; }
.ea-ico { display: grid; place-items: center; flex: none; width: 42px; height: 42px; border-radius: 14px; color: #fff; background: linear-gradient(145deg, #38bdf8, #1d4ed8); box-shadow: 0 10px 18px -10px #1d4ed8; animation: ea-pop 0.45s cubic-bezier(0.22, 1, 0.36, 1) both; }
.ea-title { margin: 0; font-size: 1.05rem; font-weight: 800; color: var(--lg-ink, #0f172a); }
.ea-sub { margin: 2px 0 0; font-size: 0.78rem; color: var(--lg-mute, #64748b); }
.ea-x { display: grid; place-items: center; flex: none; width: 32px; height: 32px; min-height: 0 !important; padding: 0 !important; border-radius: 10px; color: var(--lg-mute, #64748b); background: var(--lg-soft, #f1f5f9); transition: background 0.15s, color 0.15s, transform 0.2s; }
.ea-x:hover:not(:disabled) { color: #dc2626; background: rgba(239, 68, 68, 0.12); transform: rotate(90deg); }

.ea-body { display: grid; gap: 12px; padding: 4px 20px 18px; }

.ea-file { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 16px; border: 1px dashed var(--lg-line, #cbd5e1); background: var(--lg-soft, #f8fafc); }
.ea-file-ico { display: grid; place-items: center; flex: none; width: 38px; height: 44px; border-radius: 6px 12px 6px 6px; font-size: 0.62rem; font-weight: 900; letter-spacing: 0.06em; color: #fff; background: linear-gradient(160deg, #f87171, #dc2626); box-shadow: 0 6px 12px -8px #dc2626; }
.ea-file-name { display: block; font-size: 0.86rem; font-weight: 800; color: var(--lg-ink, #0f172a); }
.ea-file-meta { display: flex; flex-wrap: wrap; gap: 4px 12px; margin-top: 2px; font-size: 0.76rem; color: var(--lg-mute, #64748b); }
.ea-file-meta > span { display: inline-flex; align-items: center; gap: 4px; min-width: 0; overflow-wrap: anywhere; }
.ea-file-clip { flex: none; color: var(--lg-faint, #94a3b8); }

.ea-section { display: flex; align-items: center; gap: 8px; margin-top: 4px; }
.ea-label { font-size: 0.7rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-mute, #64748b); }
.ea-count { display: inline-grid; place-items: center; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 9999px; font-size: 0.7rem; font-weight: 800; color: #fff; background: #1d4ed8; transition: background 0.2s; }
.ea-count.is-zero { color: var(--lg-mute, #64748b); background: var(--lg-soft, #f1f5f9); }

.ea-person { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 16px; cursor: pointer; background: var(--lg-soft, #f8fafc); box-shadow: inset 0 0 0 1px var(--lg-line, #e2e8f0); transition: box-shadow 0.2s ease, background 0.2s ease; }
.ea-person:hover:not(.is-off) { box-shadow: inset 0 0 0 1px #7dd3fc; }
.ea-person.is-on { background: rgba(16, 185, 129, 0.08); box-shadow: inset 0 0 0 1.5px rgba(16, 185, 129, 0.55); }
.ea-person.is-off { cursor: not-allowed; opacity: 0.72; }
.ea-avatar { display: grid; place-items: center; flex: none; width: 38px; height: 38px; border-radius: 12px; font-size: 0.8rem; font-weight: 800; color: #fff; background: linear-gradient(145deg, #34d399, #059669); }
.ea-avatar.is-empty { color: var(--lg-faint, #94a3b8); background: var(--lg-card, #fff); box-shadow: inset 0 0 0 1px var(--lg-line, #e2e8f0); }
.ea-person-main { display: grid; gap: 2px; flex: 1; min-width: 0; font-size: 0.79rem; color: var(--lg-mute, #64748b); overflow-wrap: anywhere; }
.ea-person-main b { font-size: 0.88rem; color: var(--lg-ink, #0f172a); }
.ea-switch-input { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
.ea-switch { position: relative; flex: none; width: 40px; height: 23px; border-radius: 9999px; background: var(--lg-line, #cbd5e1); transition: background 0.2s; }
.ea-switch::after { content: ''; position: absolute; top: 3px; left: 3px; width: 17px; height: 17px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.3); transition: transform 0.25s cubic-bezier(0.22, 1, 0.36, 1); }
.ea-switch-input:checked + .ea-switch { background: #10b981; }
.ea-switch-input:checked + .ea-switch::after { transform: translateX(17px); }
.ea-switch-input:focus-visible + .ea-switch { box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.4); }

.ea-field { display: grid; gap: 6px; }
.ea-chipbox { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; min-height: 48px; padding: 7px 8px; border-radius: 14px; cursor: text; border: 1px solid var(--lg-line, #cbd5e1); background: var(--lg-card, #fff); transition: border-color 0.15s, box-shadow 0.15s; }
.ea-chipbox:focus-within { border-color: #7dd3fc; box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.22); }
.ea-chipbox.is-bad { border-color: #fca5a5; }
.ea-chipbox.is-disabled { opacity: 0.7; pointer-events: none; }
.ea-draft { flex: 1 0 9rem; min-width: 9rem; height: 30px; padding: 0 6px !important; border: 0 !important; outline: none !important; box-shadow: none !important; background: transparent !important; font-size: 0.86rem; color: var(--lg-ink, #0f172a); }
.ea-draft::placeholder { color: var(--lg-faint, #94a3b8); }
.ea-chip { display: inline-flex; align-items: center; gap: 5px; max-width: 100%; padding: 4px 4px 4px 9px; border-radius: 9999px; font-size: 0.76rem; font-weight: 700; color: #1d4ed8; background: rgba(59, 130, 246, 0.12); overflow-wrap: anywhere; }
.ea-chip.is-bad { color: #b91c1c; background: rgba(239, 68, 68, 0.12); box-shadow: inset 0 0 0 1px rgba(239, 68, 68, 0.35); }
.ea-chip-x { display: grid; place-items: center; flex: none; width: 18px; height: 18px; min-height: 0 !important; padding: 0 !important; border-radius: 50%; color: inherit; background: transparent; transition: background 0.15s; }
.ea-chip-x:hover { background: rgba(15, 23, 42, 0.12); }
.ea-hint { display: flex; justify-content: space-between; gap: 8px; margin: 0; font-size: 0.74rem; color: var(--lg-faint, #94a3b8); }
.ea-hint-count { flex: none; font-weight: 700; font-variant-numeric: tabular-nums; }

.ea-msg { display: flex; align-items: center; gap: 8px; margin: 0; padding: 9px 12px; border-radius: 12px; font-size: 0.82rem; }
.ea-msg.is-error { color: #b91c1c; background: rgba(239, 68, 68, 0.1); }

.ea-done { display: grid; justify-items: center; gap: 6px; padding: 26px 24px 30px; text-align: center; }
.ea-done b { font-size: 1rem; color: var(--lg-ink, #0f172a); }
.ea-done p { margin: 0; max-width: 34ch; font-size: 0.84rem; color: var(--lg-mute, #64748b); }
.ea-done-ring { display: grid; place-items: center; width: 64px; height: 64px; margin-bottom: 6px; border-radius: 50%; color: #fff; background: #10b981; animation: ea-pop 0.55s cubic-bezier(0.22, 1, 0.36, 1) both, ea-halo 1.2s 0.3s ease-out 2; }

.ea-foot { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; padding: 14px 20px; border-top: 1px solid var(--lg-line, #e2e8f0); background: var(--lg-soft, rgba(248, 250, 252, 0.6)); }
.ea-summary { font-size: 0.78rem; font-weight: 600; color: var(--lg-mute, #64748b); }
.ea-actions { display: flex; gap: 8px; margin-left: auto; }
.ea-send { transition: transform 0.15s, opacity 0.2s; }
.ea-send:active:not(:disabled) { transform: scale(0.97); }
.ea-spinner { width: 15px; height: 15px; border: 2px solid currentColor; border-right-color: transparent; border-radius: 50%; animation: ea-spin 0.7s linear infinite; }

.ea-swap-enter-active, .ea-swap-leave-active { transition: opacity 0.22s ease, transform 0.28s cubic-bezier(0.22, 1, 0.36, 1); }
.ea-swap-enter-from { opacity: 0; transform: translateY(10px) scale(0.98); }
.ea-swap-leave-to { opacity: 0; transform: translateY(-6px); }
.ea-chip-enter-active { transition: opacity 0.2s, transform 0.25s cubic-bezier(0.22, 1, 0.36, 1); }
.ea-chip-leave-active { transition: opacity 0.15s, transform 0.15s; }
.ea-chip-enter-from, .ea-chip-leave-to { opacity: 0; transform: scale(0.7); }
.ea-msg-enter-active, .ea-msg-leave-active { transition: opacity 0.2s, transform 0.2s; }
.ea-msg-enter-from, .ea-msg-leave-to { opacity: 0; transform: translateY(-4px); }

@keyframes ea-pop { 0% { opacity: 0; transform: scale(0.6); } 60% { opacity: 1; transform: scale(1.08); } 100% { transform: none; } }
@keyframes ea-halo { from { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.45); } to { box-shadow: 0 0 0 18px rgba(16, 185, 129, 0); } }
@keyframes ea-spin { to { transform: rotate(360deg); } }
@keyframes ea-bar { to { background-position: -200% 0; } }

@media (max-width: 480px) {
  .ea-actions { width: 100%; }
  .ea-actions .lg-btn { flex: 1; justify-content: center; }
}
@media (prefers-reduced-motion: reduce) {
  .ea-ico, .ea-done-ring, .ea-spinner, .ea-panel.is-sending::before { animation: none; }
  .ea-swap-enter-active, .ea-swap-leave-active, .ea-chip-enter-active, .ea-chip-leave-active { transition: none; }
}
html.dark .ea-chip { color: #93c5fd; }
html.dark .ea-msg.is-error, html.dark .ea-chip.is-bad { color: #fca5a5; }
html.dark .ea-count:not(.is-zero) { background: #2563eb; }
</style>

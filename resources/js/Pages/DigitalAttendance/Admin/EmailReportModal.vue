<template>
  <modal :show="show" max-width="lg" :closeable="!sending" @close="close">
    <div class="ea-panel">
      <header class="ea-head">
        <span class="ea-ico"><icons name="email" class="w-5 h-5" /></span>
        <div class="min-w-0 flex-1">
          <h3 class="ea-title">{{ $t('Email attendance report') }}</h3>
          <p class="ea-sub">{{ periodText }} &middot; {{ employee ? employee.name : $t('All employees') }}</p>
        </div>
        <button type="button" class="ea-x" :disabled="sending" :aria-label="$t('Close')" @click="close"><icons name="cross" class="w-4 h-4" /></button>
      </header>

      <div class="ea-body">
        <p class="ea-lead">{{ $t('A PDF of the report you are looking at is attached. Choose who receives it.') }}</p>

        <label class="ea-choice" :class="{ 'is-on': toEmployee && employeeReady, 'is-off': !employeeReady }">
          <input v-model="toEmployee" type="checkbox" :disabled="!employeeReady || sending" />
          <span class="ea-choice-main">
            <b>{{ $t('Send to the employee') }}</b>
            <span v-if="employeeReady">{{ employee.name }} &middot; {{ employee.email }}</span>
            <span v-else-if="employee">{{ $t(':name has no email address on their profile.', { name: employee.name }) }}</span>
            <span v-else>{{ $t('Choose an employee in the filter first, then you can send the report to them.') }}</span>
          </span>
        </label>

        <div class="ea-field">
          <label for="ea-typed" class="ea-label">{{ $t('Other email addresses') }}</label>
          <textarea id="ea-typed" v-model="typed" class="ea-input" rows="2" :disabled="sending" :placeholder="$t('name@company.com, another@company.com')" @keydown.ctrl.enter="send"></textarea>
          <div v-if="parsed.length" class="ea-chips">
            <span v-for="a in parsed" :key="a.address" class="ea-chip" :class="{ 'is-bad': !a.ok }">{{ a.address }}<small v-if="!a.ok">{{ $t('not valid') }}</small></span>
          </div>
          <p class="ea-hint">{{ $t('Separate addresses with a comma, a space or a new line. Up to 10.') }}</p>
        </div>

        <p v-if="error" class="ea-msg is-error"><icons name="exclamation" class="w-4 h-4 flex-none" />{{ error }}</p>
        <p v-if="done" class="ea-msg is-ok"><icons name="tick" class="w-4 h-4 flex-none" />{{ done }}</p>
      </div>

      <footer class="ea-foot">
        <button type="button" class="lg-btn lg-btn-soft" :disabled="sending" @click="close">{{ $t('Cancel') }}</button>
        <button type="button" class="lg-btn lg-btn-primary" :disabled="sending || !canSend" @click="send">
          <icons name="send" class="w-4 h-4" />{{ sending ? $t('Sending...') : $t('Send report') }}
        </button>
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
    return { toEmployee: false, typed: '', sending: false, error: '', done: '' };
  },
  computed: {
    employeeReady() {
      return !!(this.employee && this.employee.email && EMAIL.test(this.employee.email));
    },
    parsed() {
      const seen = new Set();
      return this.typed
        .split(/[\s,;]+/)
        .map(a => a.trim().toLowerCase())
        .filter(a => a && !seen.has(a) && seen.add(a))
        .map(address => ({ address, ok: EMAIL.test(address) }));
    },
    canSend() {
      const typedOk = this.parsed.length > 0 && this.parsed.every(a => a.ok) && this.parsed.length <= 10;
      const typedBad = this.parsed.some(a => !a.ok);
      return !typedBad && (typedOk || (this.toEmployee && this.employeeReady));
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
    close() {
      if (!this.sending) this.$emit('close');
    },
    send() {
      if (!this.canSend || this.sending) return;
      this.sending = true;
      this.error = '';
      this.done = '';
      axios
        .post(this.route('digital-attendance-reports.email'), { ...this.query, to_employee: this.toEmployee && this.employeeReady, addresses: this.parsed.map(a => a.address).join(', ') })
        .then(res => {
          this.done = res.data.message;
          this.typed = '';
          setTimeout(() => this.close(), 2200);
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
.ea-panel { overflow: hidden; border-radius: 24px; background: var(--lg-card, #fff); border: 1px solid var(--lg-line, #e2e8f0); }
.ea-head { display: flex; align-items: center; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--lg-line, #e2e8f0); }
.ea-ico { display: grid; place-items: center; flex: none; width: 42px; height: 42px; border-radius: 14px; color: #fff; background: linear-gradient(145deg, #38bdf8, #1d4ed8); box-shadow: 0 10px 18px -10px #1d4ed8; }
.ea-title { margin: 0; font-size: 1.02rem; font-weight: 800; color: var(--lg-ink, #0f172a); }
.ea-sub { margin: 1px 0 0; font-size: 0.78rem; color: var(--lg-mute, #64748b); overflow-wrap: anywhere; }
.ea-x { display: grid; place-items: center; flex: none; width: 32px; height: 32px; min-height: 0 !important; padding: 0 !important; border-radius: 10px; color: var(--lg-mute, #64748b); background: var(--lg-soft, #f1f5f9); }
.ea-x:hover { color: #dc2626; background: rgba(239, 68, 68, 0.12); }
.ea-body { display: grid; gap: 14px; padding: 16px 20px; }
.ea-lead { margin: 0; font-size: 0.85rem; color: var(--lg-mute, #64748b); }
.ea-choice { display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; border-radius: 16px; cursor: pointer; background: var(--lg-soft, #f8fafc); box-shadow: inset 0 0 0 1px var(--lg-line, #e2e8f0); transition: box-shadow 0.15s ease, background 0.15s ease; }
.ea-choice.is-on { background: rgba(16, 185, 129, 0.09); box-shadow: inset 0 0 0 2px rgba(16, 185, 129, 0.55); }
.ea-choice.is-off { cursor: not-allowed; opacity: 0.7; }
.ea-choice input { margin-top: 3px; width: 17px; height: 17px; flex: none; }
.ea-choice-main { display: grid; gap: 2px; min-width: 0; font-size: 0.8rem; color: var(--lg-mute, #64748b); overflow-wrap: anywhere; }
.ea-choice-main b { font-size: 0.9rem; color: var(--lg-ink, #0f172a); }
.ea-field { display: grid; gap: 6px; }
.ea-label { font-size: 0.72rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute, #64748b); }
.ea-input { width: 100%; padding: 10px 12px; font-size: 0.88rem; border-radius: 14px; resize: vertical; border: 1px solid var(--lg-line, #cbd5e1); background: var(--lg-card, #fff); color: var(--lg-ink, #0f172a); }
.ea-input:focus { outline: none; border-color: #7dd3fc; box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.25); }
.ea-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.ea-chip { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 9999px; font-size: 0.76rem; font-weight: 700; color: #1d4ed8; background: rgba(59, 130, 246, 0.12); }
.ea-chip.is-bad { color: #b91c1c; background: rgba(239, 68, 68, 0.12); }
.ea-chip small { font-size: 0.66rem; font-weight: 800; text-transform: uppercase; }
.ea-hint { margin: 0; font-size: 0.74rem; color: var(--lg-faint, #94a3b8); }
.ea-msg { display: flex; align-items: center; gap: 8px; margin: 0; padding: 8px 12px; border-radius: 12px; font-size: 0.82rem; }
.ea-msg.is-error { color: #b91c1c; background: rgba(239, 68, 68, 0.1); }
.ea-msg.is-ok { color: #047857; background: rgba(16, 185, 129, 0.12); }
.ea-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 14px 20px; border-top: 1px solid var(--lg-line, #e2e8f0); background: var(--lg-soft, rgba(248, 250, 252, 0.6)); }
html.dark .ea-chip { color: #93c5fd; }
html.dark .ea-msg.is-ok { color: #6ee7b7; }
html.dark .ea-msg.is-error, html.dark .ea-chip.is-bad { color: #fca5a5; }
</style>

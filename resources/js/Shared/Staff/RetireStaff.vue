<template>
  <span>
    <button v-if="compact" type="button" class="lg-icon-btn lg-tip" :class="isRetired ? 'is-green' : 'is-amber'" :data-tip="isRetired ? $t('Reinstate (make Active again)') : $t('Mark as retired')" :aria-label="isRetired ? $t('Reinstate') : $t('Mark as retired')" @click.stop="open">
      <icons :name="isRetired ? 'refresh' : 'user'" class="w-4 h-4" />
    </button>
    <button v-else type="button" class="lg-btn" :class="isRetired ? 'lg-btn-soft' : 'lg-btn-soft'" @click="open">
      <icons :name="isRetired ? 'refresh' : 'user'" class="w-4 h-4" />{{ isRetired ? $t('Reinstate') : $t('Mark as retired') }}
    </button>

    <jetstream-modal v-if="showing" :show="showing" max-width="lg" @close="close">
      <form class="rs-panel" @submit.prevent="submit">
        <div class="rs-head">
          <span class="rs-badge" :class="{ 'is-back': isRetired }"><icons :name="isRetired ? 'refresh' : 'user'" class="w-6 h-6" /></span>
          <div class="min-w-0">
            <h3>{{ isRetired ? $t('Reinstate') : $t('Mark as retired') }}</h3>
            <p>{{ staff.staff_number }} · {{ staff.name }}</p>
          </div>
        </div>

        <template v-if="isRetired">
          <p class="rs-text">{{ $t('This person is') }} <b>{{ staff.status }}</b>{{ staff.retired_at ? ' ' + $t('since') + ' ' + $date(staff.retired_at) : '' }}. {{ $t('Reinstating makes them Active again, back on payroll.') }}</p>
        </template>
        <template v-else>
          <p class="rs-text">{{ $t('Choose what retirement means for payroll:') }}</p>
          <div class="rs-modes">
            <label class="rs-mode" :class="{ on: form.mode === 'Retired Active' }">
              <input v-model="form.mode" type="radio" value="Retired Active" />
              <b>{{ $t('Retired Active') }}</b>
              <span>{{ $t('Retired, but still paid through payroll (a pension or retainer).') }}</span>
            </label>
            <label class="rs-mode" :class="{ on: form.mode === 'Retired' }">
              <input v-model="form.mode" type="radio" value="Retired" />
              <b>{{ $t('Retired') }}</b>
              <span>{{ $t('Retired and off payroll. No more salary is prepared.') }}</span>
            </label>
          </div>
          <label class="rs-label">{{ $t('Retirement date') }}</label>
          <input v-model="form.retired_at" type="date" class="rs-input" :class="{ bad: errors.retired_at }" />
          <p v-if="errors.retired_at" class="rs-err">{{ errors.retired_at }}</p>
          <label class="rs-label">{{ $t('Note (optional)') }}</label>
          <textarea v-model="form.note" rows="2" maxlength="2000" class="rs-input" :placeholder="$t('For example: reached retirement age, agreed early retirement...')"></textarea>
          <label class="rs-check"><input v-model="form.end_contract" type="checkbox" />{{ $t('End the active contract on the retirement date') }}</label>
        </template>

        <div class="rs-actions">
          <button type="button" class="rs-btn rs-btn--ghost" @click="close">{{ $t('Cancel') }}</button>
          <button type="submit" class="rs-btn rs-btn--go" :disabled="busy">{{ busy ? $t('Saving...') : (isRetired ? $t('Reinstate') : $t('Save')) }}</button>
        </div>
      </form>
    </jetstream-modal>
  </span>
</template>

<script>
import JetstreamModal from '@/Jetstream/Modal.vue';

export default {
  components: { JetstreamModal },
  props: { staff: { type: Object, required: true }, compact: { type: Boolean, default: false } },
  data() { return { showing: false, busy: false, errors: {}, form: { mode: 'Retired Active', retired_at: new Date().toISOString().slice(0, 10), note: '', end_contract: true } }; },
  computed: { isRetired() { return ['Retired', 'Retired Active'].includes(this.staff.status); } },
  methods: {
    open() { this.errors = {}; this.showing = true; },
    close() { this.showing = false; },
    submit() {
      this.busy = true; this.errors = {};
      const url = this.isRetired ? this.route('staffs.reinstate', this.staff.id) : this.route('staffs.retire', this.staff.id);
      this.$inertia.post(url, this.isRetired ? {} : this.form, {
        preserveScroll: true,
        onSuccess: () => this.close(),
        onError: e => (this.errors = e),
        onFinish: () => (this.busy = false),
      });
    },
  },
};
</script>

<style>
.rs-panel { padding: 1.4rem 1.4rem 1.1rem; }
.rs-head { display: flex; align-items: center; gap: 0.8rem; margin-bottom: 0.9rem; } .rs-head h3 { margin: 0; font-size: 1.1rem; font-weight: 800; } .rs-head p { margin: 2px 0 0; font-size: 0.8rem; color: #64748b; }
.rs-badge { display: grid; place-items: center; flex: none; width: 46px; height: 46px; border-radius: 14px; color: #fff; background: linear-gradient(145deg, #f59e0b, #b45309); box-shadow: 0 10px 18px -10px rgba(180, 83, 9, 0.8); } .rs-badge.is-back { background: linear-gradient(145deg, #10b981, #047857); box-shadow: 0 10px 18px -10px rgba(4, 120, 87, 0.8); }
.rs-text { margin: 0 0 0.7rem; font-size: 0.88rem; line-height: 1.5; color: #475569; }
.rs-modes { display: grid; gap: 0.5rem; margin-bottom: 0.8rem; } .rs-mode { display: grid; gap: 2px; padding: 0.65rem 0.85rem; border-radius: 14px; cursor: pointer; box-shadow: inset 0 0 0 1.5px #cbd5e1; transition: box-shadow 0.15s, background 0.15s; } .rs-mode input { position: absolute; opacity: 0; } .rs-mode b { font-size: 0.92rem; } .rs-mode span { font-size: 0.78rem; color: #64748b; }
.rs-mode.on { background: rgba(245, 158, 11, 0.1); box-shadow: inset 0 0 0 2px #f59e0b; }
.rs-label { display: block; margin: 0.6rem 0 0.25rem; font-size: 0.74rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: #64748b; }
.rs-input { width: 100%; padding: 0.6rem 0.75rem; border: 0; border-radius: 12px; box-shadow: inset 0 0 0 1.5px #cbd5e1; font-size: 0.9rem; } .rs-input:focus { outline: 0; box-shadow: inset 0 0 0 2px #3b82f6, 0 0 0 4px rgba(59, 130, 246, 0.16); } .rs-input.bad { box-shadow: inset 0 0 0 2px #f43f5e; }
.rs-err { margin: 0.3rem 0 0; font-size: 0.78rem; font-weight: 700; color: #be123c; }
.rs-check { display: flex; align-items: center; gap: 0.5rem; margin-top: 0.8rem; font-size: 0.84rem; font-weight: 600; color: #334155; }
.rs-actions { display: flex; gap: 0.6rem; margin-top: 1.2rem; }
.rs-btn { flex: 1; min-height: 44px !important; border: 0; border-radius: 13px; font-size: 0.86rem; font-weight: 800; letter-spacing: 0.03em; cursor: pointer; } .rs-btn--ghost { color: #334155; background: #fff; box-shadow: inset 0 0 0 1.5px #cbd5e1; } .rs-btn--go { color: #fff; background: linear-gradient(135deg, #1e3a8a, #0f2540); box-shadow: 0 12px 20px -12px rgba(15, 37, 64, 0.9); } .rs-btn--go:disabled { opacity: 0.5; }
html.dark .rs-text, html.dark .rs-mode span { color: #94a3b8; } html.dark .rs-check { color: #e2e8f0; } html.dark .rs-input { background: #0f172a; color: #f1f5f9; box-shadow: inset 0 0 0 1.5px #334155; } html.dark .rs-mode { box-shadow: inset 0 0 0 1.5px #334155; } html.dark .rs-btn--ghost { color: #e2e8f0; background: transparent; box-shadow: inset 0 0 0 1.5px #475569; }

/* polish: same look as the contract boxes */
.rs-panel { position: relative; padding: 0 0 1.1rem; overflow: hidden; } .rs-panel > * { margin-left: 1.4rem; margin-right: 1.4rem; }
.rs-head { margin: 0 0 1.1rem !important; padding: 1.2rem 1.4rem; color: #fff; position: relative; overflow: hidden; background: linear-gradient(135deg, #d97706, #7c2d12); }
.rs-head.is-back, .rs-panel:has(.rs-badge.is-back) .rs-head { background: linear-gradient(135deg, #059669, #064e3b); }
.rs-head::before { content: ''; position: absolute; width: 180px; height: 180px; right: -40px; top: -80px; border-radius: 50%; background: radial-gradient(circle, rgba(255, 255, 255, 0.28), transparent 70%); animation: rs-drift 8s ease-in-out infinite; }
.rs-head h3 { color: #fff; } .rs-head p { color: rgba(255, 255, 255, 0.8); }
.rs-badge { background: rgba(255, 255, 255, 0.18) !important; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.4) !important; animation: rs-pop 0.5s cubic-bezier(0.2, 1.5, 0.4, 1) both; }
.rs-mode { transition: transform 0.15s ease, box-shadow 0.18s ease, background 0.18s ease; animation: rs-slide 0.4s ease both; } .rs-mode:nth-child(2) { animation-delay: 0.08s; } .rs-mode:hover { transform: translateY(-2px); box-shadow: inset 0 0 0 1.5px #f59e0b; }
.rs-btn { position: relative; overflow: hidden; transition: transform 0.15s ease, box-shadow 0.2s ease; } .rs-btn:hover { transform: translateY(-2px); }
@keyframes rs-drift { 0%, 100% { transform: translate(0, 0); } 50% { transform: translate(-18px, 14px); } }
@keyframes rs-pop { from { opacity: 0; transform: scale(0.4) rotate(-20deg); } to { opacity: 1; transform: none; } }
@keyframes rs-slide { from { opacity: 0; transform: translateX(-12px); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) { .rs-head::before, .rs-badge, .rs-mode { animation: none !important; } }

/* fit the box: fields sit inside the side margins (they used to run past the right edge), tall content scrolls, narrow screens stack */
.rs-panel { overflow-x: hidden; overflow-y: auto; max-height: calc(100vh - 2rem); max-height: calc(100dvh - 2rem); -webkit-overflow-scrolling: touch; }
.rs-panel > .rs-input { display: block; box-sizing: border-box; width: calc(100% - 2.8rem); min-width: 0; max-width: calc(100% - 2.8rem); font-family: inherit; }
.rs-panel > textarea.rs-input { resize: vertical; min-height: 4.2rem; }
.rs-panel > .rs-input[type='date'] { -webkit-appearance: none; appearance: none; min-height: 2.75rem; line-height: 1.2; }
.rs-panel > .rs-label, .rs-panel > .rs-text, .rs-panel > .rs-err, .rs-panel > .rs-check { box-sizing: border-box; }
.rs-head > div { min-width: 0; } .rs-head h3, .rs-head p { overflow-wrap: anywhere; }
@media (max-width: 480px) {
  .rs-panel > * { margin-left: 1rem; margin-right: 1rem; }
  .rs-head { padding: 1rem !important; margin-left: 0 !important; margin-right: 0 !important; }
  .rs-panel > .rs-input { width: calc(100% - 2rem); max-width: calc(100% - 2rem); }
  .rs-actions { flex-direction: column-reverse; }
  .rs-btn { width: 100%; }
}
</style>

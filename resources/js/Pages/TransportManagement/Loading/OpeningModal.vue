<template>
  <teleport to="body">
    <div class="tlm-overlay" @click.self="$emit('close')">
      <form class="tlm-card" role="dialog" aria-modal="true" @submit.prevent="submit">
        <header class="tlm-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h3 class="lg-card-title">{{ $t('Previous balance') }}</h3>
            <p class="lg-card-sub">{{ worker.name }}</p>
          </div>
        </header>

        <div class="tlm-body">
          <p class="tlm-hint">{{ $t('Money still owed to this person from before this module was used. It is added to what they earn, so it shows in their balance, can be paid by voucher, and appears in the reports. Use a minus (-) if they already took money in advance.') }}</p>

          <div class="tlm-grid">
            <div class="tlm-field">
              <label for="tlo_amount">{{ $t('Amount') }}</label>
              <input id="tlo_amount" v-model="form.amount" type="number" step="0.01" class="tlm-input" autofocus />
              <p v-if="errors.amount" class="tlm-err">{{ errors.amount }}</p>
            </div>
            <div class="tlm-field">
              <label>{{ $t('Balance as at') }}</label>
              <date-picker v-model="form.as_of_date" :max="today" />
              <p v-if="errors.as_of_date" class="tlm-err">{{ errors.as_of_date }}</p>
            </div>
            <div class="tlm-field tlm-wide">
              <label for="tlo_notes">{{ $t('Notes (optional)') }}</label>
              <input id="tlo_notes" v-model="form.notes" type="text" maxlength="500" class="tlm-input" :placeholder="$t('e.g. unpaid work before the module started')" />
            </div>
          </div>
          <p v-if="errors.contact_id" class="tlm-err">{{ errors.contact_id }}</p>
        </div>

        <footer class="tlm-foot">
          <button v-if="Number(worker.opening)" type="button" class="lg-btn lg-btn-soft tlo-remove" :disabled="processing" @click="remove"><icons name="trash" class="w-4 h-4" />{{ $t('Remove') }}</button>
          <span class="tlo-spacer"></span>
          <button type="button" class="lg-btn lg-btn-soft" @click="$emit('close')"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button>
          <button type="submit" class="lg-btn lg-btn-green" :disabled="processing || !Number(form.amount)">
            <icons :name="processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': processing }" />{{ $t('Save') }}
          </button>
        </footer>
      </form>
    </div>
  </teleport>
</template>

<script>
import { router } from '@inertiajs/vue3';
import DatePicker from '@/Shared/DatePicker.vue';

export default {
  components: { DatePicker },
  props: { worker: Object },
  emits: ['close'],
  data() {
    const today = new Date().toLocaleDateString('en-CA');
    return {
      today,
      processing: false,
      form: { contact_id: this.worker.contact_id, amount: Number(this.worker.opening) || '', as_of_date: this.worker.opening_date || today, notes: this.worker.opening_notes || '' },
    };
  },
  computed: {
    errors() { return this.$page.props.errors || {}; },
  },
  methods: {
    submit() {
      this.processing = true;
      router.post(this.route('transport-loading.opening.store'), this.form, { preserveScroll: true, onSuccess: () => this.$emit('close'), onFinish: () => (this.processing = false) });
    },
    remove() {
      if (!window.confirm(this.$t('Remove this previous balance? The balance will go down by that amount.'))) return;
      this.processing = true;
      router.delete(this.route('transport-loading.opening.destroy', this.worker.contact_id), { preserveScroll: true, onSuccess: () => this.$emit('close'), onFinish: () => (this.processing = false) });
    },
  },
};
</script>

<style scoped>
.tlm-overlay { position: fixed; inset: 0; z-index: 9998; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(15, 23, 42, 0.55); backdrop-filter: blur(3px); }
.tlm-card { width: 100%; max-width: 32rem; max-height: 92vh; overflow: auto; border-radius: 20px; background: var(--lg-card, #fff); box-shadow: 0 30px 60px -20px rgba(15, 23, 42, 0.6); }
.tlm-head { display: flex; gap: 12px; align-items: center; padding: 16px 18px; border-bottom: 1px solid var(--lg-line, #e3e8f0); }
.tlm-body { display: grid; gap: 14px; padding: 16px 18px; }
.tlm-hint { margin: 0; padding: 8px 12px; border-radius: 12px; font-size: 0.8rem; line-height: 1.4; color: #475569; background: var(--lg-soft, #f4f6fb); }
.tlm-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 14px; align-items: start; }
.tlm-wide { grid-column: 1 / -1; }
.tlm-field { min-width: 0; }
.tlm-field > label { display: block; margin-bottom: 4px; min-height: 1rem; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: var(--lg-mute, #64748b); }
.tlm-input { display: block; width: 100%; height: 43px; padding: 8px 12px; border-radius: 12px; border: 1px solid var(--lg-line, #e3e8f0); background: var(--lg-card, #fff); box-shadow: 0 3px 8px -4px rgba(15, 23, 42, 0.28); }
.tlm-input:focus { outline: none; border-color: #818cf8; box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.25); }
.tlm-field :deep(.wdp-root), .tlm-field :deep(.col-span-6) { width: 100%; margin: 0; }
.tlm-field :deep(.relative.mb-2) { margin-bottom: 0; }
.tlm-field :deep(.wdp-trigger) { height: 43px; min-height: 43px; }
.tlm-field :deep(.wdp-input) { padding-top: 0 !important; padding-bottom: 0 !important; }
.tlm-err { color: #e11d48; font-size: 0.8rem; margin: 2px 0 0; }
.tlm-foot { display: flex; align-items: center; justify-content: flex-end; gap: 8px; padding: 14px 18px; border-top: 1px solid var(--lg-line, #e3e8f0); }
.tlo-spacer { flex: 1; }
.tlo-remove { color: #be123c; }
html.dark .tlm-card { background: #111a2e; }
html.dark .tlm-input { background: rgba(15, 23, 42, 0.6); }
@media (max-width: 520px) { .tlm-grid { grid-template-columns: 1fr; } }
</style>

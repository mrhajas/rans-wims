<template>
  <span class="tra-root" v-if="state && (state.route || state.can_generate || state.blocked)" @click.stop>
    <!-- Compact (list rows): open the trip that was generated -->
    <a v-if="state.route && compact" :href="state.route.url" target="_blank" rel="noopener" class="tra-icon tra-icon-open lg-tip" :data-tip="$t('Transport Route') + ' ' + state.route.trip_number" :aria-label="$t('Transport Route') + ' ' + state.route.trip_number">
      <icons name="road" class="w-4 h-4" />
    </a>
    <!-- Compact: generate (or why not yet) -->
    <button v-else-if="state.can_generate && compact" type="button" class="tra-icon tra-icon-new lg-tip" :data-tip="$t('Generate Transport Route')" :aria-label="$t('Generate Transport Route')" @click.stop="openModal">
      <icons name="road" class="w-4 h-4" />
      <span class="tra-icon-plus">+</span>
    </button>
    <span v-else-if="state.blocked && compact" class="tra-icon tra-icon-off lg-tip" :data-tip="state.blocked">
      <icons name="road" class="w-4 h-4" />
    </span>

    <!-- Full: link to the trip -->
    <a v-else-if="state.route" :href="state.route.url" target="_blank" rel="noopener" class="tra-link lg-tip" :data-tip="linkTitle" :aria-label="linkTitle">
      <span class="tra-link-icon"><icons name="road" class="w-4 h-4" /></span>
      <span class="tra-link-text">
        <span class="tra-link-label">{{ $t('Transport Route') }}</span>
        <strong>{{ state.route.trip_number }}</strong>
      </span>
      <span class="tra-pill" :class="state.route.verified ? 'tra-pill-ok' : 'tra-pill-wait'">{{ state.route.verified ? $t('Verified') : $t('Pending verification') }}</span>
      <icons name="link" class="w-3.5 h-3.5 tra-link-arrow" />
    </a>
    <!-- Full: generate -->
    <button v-else-if="state.can_generate" type="button" class="tra-btn" @click="openModal">
      <icons name="road" class="w-4 h-4" />
      {{ $t('Generate Transport Route') }}
    </button>
    <span v-else-if="state.blocked" class="tra-btn tra-btn-off lg-tip" :data-tip="state.blocked">
      <icons name="road" class="w-4 h-4" />
      {{ $t('Generate Transport Route') }}
    </span>

    <teleport to="body">
      <dialog-modal v-if="open" :show="open" max-width="lg" @close="close">
        <template #title>
          <div class="flex items-center gap-3">
            <span class="tra-modal-icon"><icons name="road" class="w-5 h-5" /></span>
            <div>
              <div class="font-bold text-gray-900">{{ $t('Generate Transport Route') }}</div>
              <div class="text-xs font-normal text-gray-500">{{ state.transfer_reference }} &middot; {{ $t('The trip is saved as waiting for verification.') }}</div>
            </div>
          </div>
        </template>

        <template #content>
          <form @submit.prevent="submit" class="space-y-4" id="tra-form">
            <section class="tra-block">
              <div class="tra-block-head"><icons name="road" class="w-4 h-4" />{{ $t('Taken from the Transfer and its Gate Pass') }}</div>
              <dl class="tra-facts">
                <div><dt>{{ $t('From') }}</dt><dd>{{ state.from || '-' }}</dd></div>
                <div><dt>{{ $t('To') }}</dt><dd>{{ state.to || '-' }}</dd></div>
                <div><dt>{{ $t('Vehicle') }}</dt><dd>{{ state.vehicle || '-' }}</dd></div>
                <div><dt>{{ $t('Driver') }}</dt><dd>{{ state.driver || '-' }}</dd></div>
              </dl>
            </section>

            <section class="tra-block tra-block-input">
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <text-input :label="$t('Price per trip')" type="number" step="0.01" min="0" v-model="form.unit_price" :error="form.errors.unit_price" />
                <text-input :label="$t('Number of trips')" type="number" step="1" min="1" max="50" v-model="form.trips_count" :error="form.errors.trips_count" />
              </div>
              <p class="tra-hint">{{ $t('Default price per trip') }}: {{ $money(state.defaults.unit_price) }}</p>
              <div class="tra-total" aria-live="polite">
                <span>{{ form.trips_count || 0 }} &times; {{ $money(form.unit_price || 0) }}</span>
                <strong>{{ $money(total) }}</strong>
              </div>
              <div class="mt-3">
                <date-picker :label="$t('Trip date')" v-model="form.trip_date" :error="form.errors.trip_date" />
              </div>
              <div class="mt-3">
                <text-input :label="$t('Notes (optional)')" v-model="form.notes" :error="form.errors.notes" />
              </div>
            </section>
          </form>
        </template>

        <template #footer>
          <button type="button" class="tra-cancel" @click="close">{{ $t('Cancel') }}</button>
          <button type="submit" form="tra-form" class="tra-submit" :disabled="form.processing">
            <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
            {{ $t('Generate Transport Route') }}
          </button>
        </template>
      </dialog-modal>
    </teleport>
  </span>
</template>

<script>
import DialogModal from '@/Jetstream/DialogModal.vue';
import TextInput from '@/Shared/TextInput.vue';
import DatePicker from '@/Shared/DatePicker.vue';

// "Generate Transport Route" beside the Gate Pass button on a Transfer; once generated, a link (new tab) to the trip.
export default {
  components: { DialogModal, TextInput, DatePicker },

  props: {
    state: { type: Object, default: null },
    compact: { type: Boolean, default: false },
  },

  data() {
    return {
      open: false,
      form: this.$inertia.form({ unit_price: this.state ? this.state.defaults.unit_price : 0, trips_count: 1, trip_date: this.state ? this.state.defaults.trip_date : null, notes: '' }),
    };
  },

  computed: {
    total() { return Math.round((Number(this.form.unit_price) || 0) * (Number(this.form.trips_count) || 0) * 100) / 100; },
    linkTitle() {
      const r = this.state.route;
      return [this.$t('Transport Route') + ' ' + r.trip_number, r.trips + ' × ' + this.$money(r.amount / Math.max(1, r.trips)), r.verified ? this.$t('Verified') : this.$t('Pending verification')].join(' · ');
    },
  },

  methods: {
    openModal() {
      this.form.clearErrors();
      this.form.unit_price = this.state.defaults.unit_price;
      this.form.trips_count = this.state.defaults.trips_count;
      this.form.trip_date = this.state.defaults.trip_date;
      this.form.notes = '';
      this.open = true;
    },
    close() { if (!this.form.processing) this.open = false; },
    submit() {
      if (this.form.processing) return;
      this.form.post(this.state.generate_url, { preserveScroll: true, onSuccess: () => (this.open = false) });
    },
  },
};
</script>

<style scoped>
.tra-root { display: inline-flex; align-items: center; }

/* Compact icon (list rows) */
.tra-icon { position: relative; display: inline-flex; align-items: center; justify-content: center; height: 2.1rem; width: 2.1rem; min-height: 0; padding: 0; border-radius: 9999px; transition: transform 0.15s ease, box-shadow 0.15s ease; }
.tra-icon:hover { transform: translateY(-1px); }
.tra-icon-open { background: #dcfce7; color: #15803d; box-shadow: 0 0 0 2px #22c55e, 0 0 10px 2px rgba(34, 197, 94, 0.55); animation: tra-glow 2.6s ease-in-out 0.8s infinite; }
@keyframes tra-glow { 0%, 100% { box-shadow: 0 0 0 2px #22c55e, 0 0 6px 1px rgba(34, 197, 94, 0.4); } 50% { box-shadow: 0 0 0 2px #22c55e, 0 0 14px 4px rgba(34, 197, 94, 0.75); } }
.tra-icon-new { background: linear-gradient(145deg, #34d399, #0f9f6e); color: #fff; box-shadow: 0 6px 12px -6px rgba(15, 159, 110, 0.85); }
.tra-icon-off { background: #f1f5f9; color: #94a3b8; box-shadow: inset 0 0 0 1.5px #e2e8f0; cursor: help; }
.tra-icon-plus { position: absolute; top: -3px; right: -3px; width: 14px; height: 14px; border-radius: 9999px; display: inline-flex; align-items: center; justify-content: center; background: #8b5cf6; color: #fff; font-size: 11px; font-weight: 800; line-height: 1; box-shadow: 0 0 0 1.5px #fff; }

/* Full button / link */
.tra-btn, .tra-link { display: inline-flex; align-items: center; gap: 8px; min-height: 38px; padding: 0 16px; border-radius: 9999px; font-size: 0.8rem; font-weight: 700; white-space: nowrap; transition: transform 0.15s ease, box-shadow 0.15s ease; }
.tra-btn { color: #fff; background: linear-gradient(145deg, #34d399, #0f9f6e); box-shadow: 0 8px 18px -8px rgba(15, 159, 110, 0.75); }
.tra-btn:hover { transform: translateY(-1px); filter: brightness(1.06); }
.tra-btn-off { color: #94a3b8; background: #f1f5f9; box-shadow: inset 0 0 0 1.5px #e2e8f0; cursor: help; }
.tra-btn-off:hover { transform: none; filter: none; }
.tra-link { padding: 0 14px 0 6px; color: #166534; background: #f0fdf4; border: 1px solid #bbf7d0; box-shadow: 0 6px 16px -10px rgba(15, 159, 110, 0.6); }
.tra-link:hover { transform: translateY(-1px); box-shadow: 0 10px 20px -10px rgba(15, 159, 110, 0.7); }
.tra-link-icon { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 9999px; color: #fff; background: linear-gradient(145deg, #34d399, #0f9f6e); }
.tra-link-text { display: inline-flex; flex-direction: column; line-height: 1.1; }
.tra-link-label { font-size: 0.6rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #0f9f6e; }
.tra-link-arrow { color: #0f9f6e; }
.tra-pill { padding: 2px 8px; border-radius: 9999px; font-size: 0.62rem; font-weight: 800; letter-spacing: 0.02em; }
.tra-pill-wait { background: #fef3c7; color: #b45309; }
.tra-pill-ok { background: #dcfce7; color: #15803d; }

/* Modal */
.tra-modal-icon { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 12px; color: #fff; background: linear-gradient(145deg, #34d399, #0f9f6e); box-shadow: 0 8px 16px -8px rgba(15, 159, 110, 0.8); }
.tra-block { padding: 14px 16px; border-radius: 14px; background: #f3fbf7; border: 1px solid #cdeedd; }
.tra-block-input { background: #fff; border-color: #e2e8f0; }
.tra-block-head { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #0f7a55; }
.tra-facts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 16px; margin: 0; }
.tra-facts dt { font-size: 0.66rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; }
.tra-facts dd { margin: 2px 0 0; font-size: 0.86rem; font-weight: 600; color: #0f172a; overflow-wrap: anywhere; }
.tra-hint { margin-top: 6px; font-size: 0.75rem; color: #64748b; }
.tra-total { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin-top: 10px; padding: 10px 14px; border-radius: 12px; background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
.tra-total span { font-size: 0.8rem; font-weight: 600; }
.tra-total strong { font-size: 1.15rem; font-variant-numeric: tabular-nums; }
.tra-cancel { margin-right: 8px; padding: 9px 18px; border-radius: 9999px; font-size: 0.82rem; font-weight: 700; color: #475569; background: #fff; border: 1px solid #dfe3ec; }
.tra-cancel:hover { background: #f8fafc; }
.tra-submit { display: inline-flex; align-items: center; gap: 8px; padding: 9px 20px; border-radius: 9999px; font-size: 0.82rem; font-weight: 700; color: #fff; background: linear-gradient(145deg, #34d399, #0f9f6e); box-shadow: 0 8px 18px -8px rgba(15, 159, 110, 0.75); }
.tra-submit:disabled { opacity: 0.65; cursor: wait; }
@media (prefers-reduced-motion: reduce) { .tra-icon-open { animation: none; } }
</style>

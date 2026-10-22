<template>
  <span class="gp-root" v-if="state && (state.note || state.can_generate)" @click.stop>
    <!-- Compact (list rows): open an existing pass -->
    <a v-if="state.note && compact" :href="state.note.url" target="_blank" rel="noopener" class="gp-icon gp-icon-open lg-tip" :data-tip="$t('Gate Pass') + ' ' + state.note.reference" :aria-label="$t('Gate Pass') + ' ' + state.note.reference">
      <icons name="out" class="w-4 h-4" />
    </a>
    <!-- Compact: generate -->
    <button v-else-if="state.can_generate && compact" type="button" class="gp-icon gp-icon-new lg-tip" :data-tip="$t('Generate Gate Pass')" @click.stop="openModal" :aria-label="$t('Generate Gate Pass')">
      <icons name="out" class="w-4 h-4" />
      <span class="gp-icon-plus">+</span>
    </button>
    <!-- Full: existing pass link (+ edit driver/vehicle) -->
    <span v-else-if="state.note" class="gp-linkwrap">
      <a :href="state.note.url" target="_blank" rel="noopener" class="gp-link lg-tip" :data-tip="linkTitle" :aria-label="linkTitle">
        <span class="gp-link-icon"><icons name="out" class="w-4 h-4" /></span>
        <span class="gp-link-text">
          <span class="gp-link-label">{{ $t('Gate Pass') }}</span>
          <strong>{{ state.note.reference }}</strong>
        </span>
        <icons name="link" class="w-3.5 h-3.5 gp-link-arrow" />
      </a>
      <button v-if="state.can_edit" type="button" class="gp-edit lg-tip" :data-tip="$t('Change driver / vehicle')" @click.stop="openModal" :aria-label="$t('Change driver / vehicle')">
        <icons name="edit" class="w-4 h-4" />
      </button>
    </span>
    <!-- Full: generate -->
    <button v-else-if="state.can_generate" type="button" class="gp-btn" @click="openModal">
      <icons name="out" class="w-4 h-4" />
      {{ $t('Generate Gate Pass') }}
    </button>

    <!-- print: the printer animation, then the browser's print dialog on the pass -->
    <button v-if="state.note && $can('print-gate-passes')" type="button" class="gp-print lg-tip" :data-tip="$t('Print Gate Pass')" :aria-label="$t('Print Gate Pass')" @click.stop="printNote">
      <icons name="printer" class="w-4 h-4" />
    </button>

    <!-- Driver + vehicle form -->
    <teleport to="body">
      <dialog-modal v-if="open" :show="open" max-width="lg" @close="close">
        <template #title>
          <div class="flex items-center gap-3">
            <span class="gp-modal-icon"><icons name="out" class="w-5 h-5" /></span>
            <div>
              <div class="font-bold text-gray-900">{{ editing ? $t('Change driver / vehicle') : $t('Generate Gate Pass') }}</div>
              <div class="text-xs font-normal text-gray-500">{{ state.record_reference }} &middot; {{ $t('The driver and vehicle are printed on the pass.') }}</div>
            </div>
          </div>
        </template>

        <template #content>
          <div v-if="loading" class="gp-loading">{{ $t('Loading drivers and vehicles…') }}</div>
          <form v-else @submit.prevent="submit" class="space-y-5" id="gp-form">
            <!-- Driver -->
            <section class="gp-block">
              <div class="gp-block-head"><icons name="user" class="w-4 h-4" />{{ $t('Assigned driver') }}</div>
              <auto-complete
                v-if="!manualDriver"
                id="gp_driver"
                :label="$t('Driver')"
                v-model="form.driver_contact_id"
                :suggestions="drivers"
                value-key="id"
                label-key="label"
                :error="form.errors.driver_name || form.errors.driver_contact_id"
                clearable
              />
              <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <text-input :label="$t('Driver name')" v-model="form.driver_name" :error="form.errors.driver_name" />
                <text-input :label="$t('Driver phone')" v-model="form.driver_phone" :error="form.errors.driver_phone" />
              </div>
              <label class="gp-switch">
                <input type="checkbox" v-model="manualDriver" @change="onManualDriver" />
                <span>{{ $t('Driver is not in the list, type the details') }}</span>
              </label>
            </section>

            <!-- Vehicle -->
            <section class="gp-block">
              <div class="gp-block-head"><icons name="truck" class="w-4 h-4" />{{ $t('Car / vehicle') }}</div>
              <auto-complete
                v-if="!manualVehicle"
                id="gp_vehicle"
                :label="$t('Vehicle')"
                v-model="form.asset_id"
                :suggestions="vehicles"
                value-key="id"
                label-key="label"
                :error="form.errors.vehicle_plate || form.errors.asset_id"
                clearable
              />
              <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <text-input :label="$t('Registration number')" v-model="form.vehicle_plate" :error="form.errors.vehicle_plate" />
                <text-input :label="$t('Make / model / colour')" v-model="form.vehicle_details" :error="form.errors.vehicle_details" />
              </div>
              <label class="gp-switch">
                <input type="checkbox" v-model="manualVehicle" @change="onManualVehicle" />
                <span>{{ $t('Vehicle is not in the list, type the details') }}</span>
              </label>
              <p v-if="!vehicles.length && !manualVehicle" class="gp-hint">{{ $t('No vehicles are registered yet. Tick the box above to type the registration number.') }}</p>
            </section>

            <p v-if="loadError" class="gp-error">{{ loadError }}</p>
          </form>
        </template>

        <template #footer>
          <button type="button" class="gp-cancel" @click="close">{{ $t('Cancel') }}</button>
          <button type="submit" form="gp-form" class="gp-submit" :disabled="form.processing || loading">
            <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
            {{ editing ? $t('Save changes') : $t('Generate Gate Pass') }}
          </button>
        </template>
      </dialog-modal>
    </teleport>
  </span>
</template>

<script>
import { printWithAnimation, printUrlInFrame, paperworkPrintSheet } from '@/Core/printAnimation';
import DialogModal from '@/Jetstream/DialogModal.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import TextInput from '@/Shared/TextInput.vue';

// Drivers/vehicles are the same for every Gate Pass button on the page — fetch them once.
let optionsCache = null;

export default {
  components: { DialogModal, AutoComplete, TextInput },

  props: {
    state: { type: Object, default: null },
    compact: { type: Boolean, default: false },
  },

  data() {
    return {
      open: false,
      loading: false,
      loadError: '',
      drivers: [],
      vehicles: [],
      manualDriver: false,
      manualVehicle: false,
      form: this.$inertia.form({
        driver_contact_id: null,
        driver_name: '',
        driver_phone: '',
        asset_id: null,
        vehicle_plate: '',
        vehicle_details: '',
      }),
    };
  },

  computed: {
    editing() {
      return !!(this.state && this.state.note);
    },
    linkTitle() {
      const d = this.state.details;
      return d ? [d.driver_label, d.vehicle_label].filter(Boolean).join(' · ') : this.$t('Open Gate Pass');
    },
  },

  watch: {
    // A message about a field goes away as soon as that field is changed.
    'form.driver_contact_id'() { this.form.clearErrors('driver_name', 'driver_contact_id'); },
    'form.driver_name'() { this.form.clearErrors('driver_name'); },
    'form.vehicle_plate'() { this.form.clearErrors('vehicle_plate'); },

    // Picking a vehicle that has its own assigned driver pre-selects that driver (can still be changed).
    'form.asset_id'(id) {
      this.form.clearErrors('vehicle_plate', 'asset_id');
      if (!id || this.form.driver_contact_id || this.manualDriver) return;
      const v = this.vehicles.find(x => x.id == id);
      if (v && v.default_driver_id && this.drivers.some(d => d.id == v.default_driver_id)) {
        this.form.driver_contact_id = v.default_driver_id;
      }
    },
  },

  methods: {
    printNote() {
      printWithAnimation(paperworkPrintSheet(this, this.state, 'Gate Pass'), () => printUrlInFrame(this.route('gate-passes.print', this.state.note.id)));
    },
    async openModal() {
      this.form.clearErrors();
      this.loadError = '';
      this.open = true;
      this.fillFromState();

      if (!optionsCache) {
        this.loading = true;
        try {
          const { data } = await axios.get(this.state.options_url);
          optionsCache = data;
        } catch (e) {
          this.loadError = this.$t('Could not load the drivers and vehicles. You can still type them in.');
          optionsCache = { drivers: [], vehicles: [] };
          this.manualDriver = this.manualVehicle = true;
        }
        this.loading = false;
      }
      this.drivers = optionsCache.drivers;
      this.vehicles = optionsCache.vehicles;
      if (!this.drivers.length && !this.editing) this.manualDriver = true;
      if (!this.vehicles.length && !this.editing) this.manualVehicle = true;
    },

    fillFromState() {
      // a movement that already knows its driver and vehicle (External Stock) starts the pass from them
      const d = this.state.details || this.state.prefill;
      if (d) {
        this.form.driver_contact_id = d.driver_contact_id;
        this.form.driver_name = d.driver_name || '';
        this.form.driver_phone = d.driver_phone || '';
        this.form.asset_id = d.asset_id;
        this.form.vehicle_plate = d.vehicle_plate || '';
        this.form.vehicle_details = d.vehicle_details || '';
        this.manualDriver = !d.driver_contact_id && !!d.driver_name;
        this.manualVehicle = !d.asset_id && !!d.vehicle_plate;
      } else {
        this.form.reset();
        this.manualDriver = false;
        this.manualVehicle = false;
      }
    },

    // Switching between "pick" and "type" clears the other side so a pass never carries both.
    onManualDriver() {
      this.form.clearErrors('driver_name', 'driver_contact_id');
      if (this.manualDriver) this.form.driver_contact_id = null;
      else { this.form.driver_name = ''; this.form.driver_phone = ''; }
    },
    onManualVehicle() {
      this.form.clearErrors('vehicle_plate', 'asset_id');
      if (this.manualVehicle) this.form.asset_id = null;
      else { this.form.vehicle_plate = ''; this.form.vehicle_details = ''; }
    },

    close() {
      if (this.form.processing) return;
      this.open = false;
    },

    submit() {
      if (this.form.processing) return;
      const options = { preserveScroll: true, onSuccess: () => (this.open = false) };
      if (this.editing) this.form.patch(this.state.update_url, options);
      else this.form.post(this.state.generate_url, options);
    },
  },
};
</script>

<style scoped>
.gp-root { display: inline-flex; align-items: center; }

/* Compact icon (list rows) */
.gp-icon { position: relative; display: inline-flex; align-items: center; justify-content: center; height: 2.1rem; width: 2.1rem; min-height: 0; padding: 0; border-radius: 9999px; transition: transform 0.15s ease, box-shadow 0.15s ease; }
.gp-icon:hover { transform: translateY(-1px); }
.gp-print { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; min-height: 0; padding: 0; margin-left: 6px; border-radius: 9999px; color: #0f766e; background: #e6f7f5; border: 0; cursor: pointer; transition: transform 0.15s, background 0.15s; }
.gp-print:hover { background: #d1f0ec; transform: translateY(-1px); }
.gp-icon-open { background: #ece9ff; color: #4f46e5; box-shadow: 0 0 0 2px #6d5ff0, 0 0 10px 2px rgba(109, 95, 240, 0.55); animation: gp-glow 2.6s ease-in-out 0.4s infinite; }
@keyframes gp-glow { 0%, 100% { box-shadow: 0 0 0 2px #6d5ff0, 0 0 6px 1px rgba(109, 95, 240, 0.4); } 50% { box-shadow: 0 0 0 2px #6d5ff0, 0 0 14px 4px rgba(109, 95, 240, 0.75); } }
.gp-icon-new { background: linear-gradient(145deg, #8f86f4, #5b4fdb); color: #fff; box-shadow: 0 6px 12px -6px rgba(91, 79, 219, 0.85); }
.gp-icon-plus { position: absolute; top: -3px; right: -3px; width: 14px; height: 14px; border-radius: 9999px; display: inline-flex; align-items: center; justify-content: center; background: #ec4899; color: #fff; font-size: 10px; font-weight: 800; line-height: 1; box-shadow: 0 0 0 1.5px #fff; }

/* Full button / link */
.gp-btn, .gp-link { display: inline-flex; align-items: center; gap: 8px; min-height: 38px; padding: 0 16px; border-radius: 9999px; font-size: 0.8rem; font-weight: 700; white-space: nowrap; transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease; }
.gp-btn { color: #fff; background: linear-gradient(145deg, #8f86f4, #5b4fdb); box-shadow: 0 8px 18px -8px rgba(91, 79, 219, 0.75); }
.gp-btn:hover { transform: translateY(-1px); filter: brightness(1.06); }
.gp-linkwrap { display: inline-flex; align-items: center; gap: 6px; }
.gp-link { padding: 0 14px 0 6px; color: #3b2fb8; background: #f3f1ff; border: 1px solid #d3cef9; box-shadow: 0 6px 16px -10px rgba(91, 79, 219, 0.6); }
.gp-link:hover { transform: translateY(-1px); box-shadow: 0 10px 20px -10px rgba(91, 79, 219, 0.7); }
.gp-link-icon { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 9999px; color: #fff; background: linear-gradient(145deg, #8f86f4, #5b4fdb); }
.gp-link-text { display: inline-flex; flex-direction: column; line-height: 1.1; }
.gp-link-label { font-size: 0.6rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #5b4fdb; }
.gp-link-arrow { color: #5b4fdb; }
.gp-edit { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; min-height: 0; padding: 0; border-radius: 9999px; color: #5b4fdb; background: #f3f1ff; border: 1px solid #d3cef9; transition: background 0.15s ease, transform 0.15s ease; }
.gp-edit:hover { background: #e6e3ff; transform: translateY(-1px); }

/* Modal */
.gp-modal-icon { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 12px; color: #fff; background: linear-gradient(145deg, #8f86f4, #5b4fdb); box-shadow: 0 8px 16px -8px rgba(91, 79, 219, 0.85); flex: none; }
.gp-loading { padding: 1.5rem 0; text-align: center; color: #64748b; font-size: 0.9rem; }
.gp-block { padding: 14px 16px; border-radius: 14px; background: #f7f6ff; border: 1px solid #e0dcfb; }
.gp-block-head { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #4338ca; }
.gp-switch { display: flex; align-items: center; gap: 8px; margin-top: 10px; font-size: 0.8rem; color: #475569; cursor: pointer; }
.gp-switch input { width: 16px; height: 16px; accent-color: #5b4fdb; }
.gp-hint { margin-top: 8px; font-size: 0.75rem; color: #64748b; }
.gp-error { font-size: 0.8rem; font-weight: 600; color: #b42318; }
.gp-cancel { margin-right: 8px; padding: 9px 18px; border-radius: 9999px; font-size: 0.82rem; font-weight: 700; color: #475569; background: #fff; border: 1px solid #dfe3ec; }
.gp-cancel:hover { background: #f8fafc; }
.gp-submit { display: inline-flex; align-items: center; gap: 8px; padding: 9px 20px; border-radius: 9999px; font-size: 0.82rem; font-weight: 700; color: #fff; background: linear-gradient(145deg, #8f86f4, #5b4fdb); box-shadow: 0 8px 16px -8px rgba(91, 79, 219, 0.8); }
.gp-submit:disabled { opacity: 0.65; cursor: wait; }
@media (prefers-reduced-motion: reduce) { .gp-icon-open { animation: none; } }
</style>

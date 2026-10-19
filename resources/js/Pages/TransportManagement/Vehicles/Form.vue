<template>
  <admin-layout :title="$t(vehicle ? 'edit_x' : 'create_x', { x: $t('Vehicle') })">
    <div class="lg-page px-4 md:px-0 tv-form">
      <page-header
        icon="car"
        :title="$t(vehicle ? 'edit_x' : 'create_x', { x: $t('Vehicle') })"
        :subtitle="$t('Register a vehicle for use in Transport Routes trips, along with its ownership and current driver.')"
      >
        <span v-if="vehicle" class="lg-chip lg-chip-blue" style="text-transform: none">{{ vehicle.name }}</span>
        <Link :href="route('transport-vehicles.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Vehicles') }}</Link>
      </page-header>

      <div v-if="canManageOpeningBalance" class="tv-tab-group mb-4">
        <button
          type="button"
          class="tv-tab-btn"
          :class="{ 'tv-tab-btn-active': activeTab === 'details' }"
          @click="activeTab = 'details'"
        >
          <icons name="car" class="w-4 h-4 inline-flex mr-1.5" /> {{ $t('Vehicle Details') }}
        </button>
        <button
          type="button"
          class="tv-tab-btn"
          :class="{ 'tv-tab-btn-active': activeTab === 'opening_balance' }"
          @click="activeTab = 'opening_balance'"
        >
          <icons name="calculator" class="w-4 h-4 inline-flex mr-1.5" /> {{ $t('Opening Balance') }}
          <span v-if="form.opening_balance" class="tv-tab-dot"></span>
        </button>
      </div>

      <form @submit.prevent="submit">
        <section class="lg-card" v-show="activeTab === 'details'">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="car" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Vehicle Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Name') }} / {{ $t('Registration Number') }} / {{ $t('Ownership Type') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <tec-label for="name" :value="$t('Name')" />
              <text-input id="name" type="text" class="mt-1 block w-full" v-model="form.name" required />
              <input-error :message="$page.props.errors.name" class="mt-1" />
            </div>
            <div>
              <tec-label :value="$t('Category')" />
              <auto-complete
                id="asset_category_id"
                class="mt-1"
                :suggestions="categorySuggestions"
                v-model="form.asset_category_id"
                :placeholder="$t('Select a category…')"
                :error="$page.props.errors.asset_category_id"
                clearable
              />
            </div>
            <div>
              <tec-label for="registration_number" :value="$t('Registration Number')" />
              <text-input id="registration_number" type="text" class="mt-1 block w-full" v-model="form.registration_number" />
              <input-error :message="$page.props.errors.registration_number" class="mt-1" />
            </div>
            <div>
              <tec-label :value="$t('Make / Manufacturer')" />
              <auto-complete
                id="manufacturer"
                class="mt-1"
                :suggestions="makeSuggestions"
                v-model="form.manufacturer"
                :placeholder="$t('Select or type a make…')"
                :error="$page.props.errors.manufacturer"
                freeform
                clearable
              />
            </div>
            <div>
              <tec-label :value="$t('Model')" />
              <auto-complete
                id="model"
                class="mt-1"
                :suggestions="modelSuggestions"
                v-model="form.model"
                :placeholder="$t('Select or type a model…')"
                :error="$page.props.errors.model"
                freeform
                clearable
              />
            </div>
            <div>
              <tec-label :value="$t('Year')" />
              <auto-complete
                id="year"
                class="mt-1"
                :suggestions="yearOptions"
                v-model="form.year"
                :placeholder="$t('Select year…')"
                :error="$page.props.errors.year"
                clearable
              />
            </div>
            <div>
              <tec-label :value="$t('Color')" />
              <auto-complete
                id="color"
                class="mt-1"
                :suggestions="colorSuggestions"
                v-model="form.color"
                :placeholder="$t('Select or type a color…')"
                :error="$page.props.errors.color"
                freeform
                clearable
              />
              <input-error :message="$page.props.errors.color" class="mt-1" />
            </div>
            <div>
              <tec-label for="vin_chassis_number" :value="$t('VIN / Chassis Number')" />
              <text-input id="vin_chassis_number" type="text" class="mt-1 block w-full" v-model="form.vin_chassis_number" />
              <input-error :message="$page.props.errors.vin_chassis_number" class="mt-1" />
            </div>
            <div>
              <tec-label for="engine_number" :value="$t('Engine Number')" />
              <text-input id="engine_number" type="text" class="mt-1 block w-full" v-model="form.engine_number" />
              <input-error :message="$page.props.errors.engine_number" class="mt-1" />
            </div>
            <div>
              <tec-label :value="$t('Ownership Type')" />
              <auto-complete
                id="ownership_type"
                class="mt-1"
                :suggestions="ownershipTypes"
                v-model="form.ownership_type"
                :placeholder="$t('Select ownership type…')"
                :error="$page.props.errors.ownership_type"
              />
            </div>
            <div>
              <tec-label for="owner_name" :value="$t('Owner / Company Name')" />
              <text-input id="owner_name" type="text" class="mt-1 block w-full" v-model="form.owner_name" />
              <input-error :message="$page.props.errors.owner_name" class="mt-1" />
            </div>
            <div>
              <tec-label for="capacity" :value="$t('Capacity')" />
              <div class="tv-stepper mt-1">
                <button type="button" class="tv-stepper-btn" @click="stepCapacity(-1)" :aria-label="$t('Decrease')">−</button>
                <input
                  id="capacity"
                  type="number"
                  min="0"
                  step="1"
                  class="tv-stepper-input"
                  v-model.number="form.capacity"
                  :placeholder="$t('0')"
                />
                <button type="button" class="tv-stepper-btn" @click="stepCapacity(1)" :aria-label="$t('Increase')">+</button>
              </div>
              <input-error :message="$page.props.errors.capacity" class="mt-1" />
            </div>
            <div>
              <tec-label :value="$t('Fuel Type')" />
              <auto-complete
                id="fuel_type"
                class="mt-1"
                :suggestions="fuelTypes"
                v-model="form.fuel_type"
                :placeholder="$t('Select fuel type…')"
                :error="$page.props.errors.fuel_type"
                clearable
              />
            </div>
            <div>
              <tec-label :value="$t('Status')" />
              <auto-complete
                id="status"
                class="mt-1"
                :suggestions="statuses"
                v-model="form.status"
                :placeholder="$t('Select status…')"
                :error="$page.props.errors.status"
              />
            </div>
          </div>
        </section>

        <section class="lg-card" v-show="activeTab === 'details'">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-violet"><icons name="user" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Current Driver') }}</h2>
              <p class="lg-card-sub">{{ $t('Operating Personnel Contact') }}</p>
            </div>
          </header>

          <div class="tv-toggle-group mb-4">
            <button
              type="button"
              v-for="type in operatingPersonnelTypes"
              :key="type"
              class="tv-toggle-btn"
              :class="{ 'tv-toggle-btn-active': form.operating_personnel_type === type }"
              @click="form.operating_personnel_type = type"
            >
              {{ $t(type) }}
            </button>
          </div>

          <div v-if="form.operating_personnel_type === 'Contact'" class="lg-fields">
            <div>
              <tec-label :value="$t('Operating Personnel Contact')" />
              <auto-complete
                id="operating_personnel_contact_id"
                class="mt-1"
                :suggestions="driverSuggestions"
                v-model="form.operating_personnel_contact_id"
                :placeholder="$t('Search drivers…')"
                :error="$page.props.errors.operating_personnel_contact_id"
                clearable
              />
            </div>
          </div>
          <p v-else class="lg-note lg-note-blue">
            <icons name="info" class="w-4 h-4" />
            <span>{{ $t('Staff assignment is managed in the Assets module.') }}</span>
          </p>
        </section>

        <section class="lg-card" v-show="activeTab === 'details'">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-slate"><icons name="doc" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Notes') }}</h2>
              <p class="lg-card-sub">{{ $t('Any additional context about this vehicle…') }}</p>
            </div>
          </header>
          <textarea
            id="notes"
            class="block w-full rounded-md border-gray-300"
            rows="3"
            v-model="form.notes"
            :placeholder="$t('Any additional context about this vehicle…')"
          ></textarea>
          <input-error :message="$page.props.errors.notes" class="mt-1" />
        </section>

        <section class="lg-card" v-if="canManageOpeningBalance && activeTab === 'opening_balance'">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="calculator" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Opening Balance') }}</h2>
              <p class="lg-card-sub">{{ $t('Opening Balance Amount') }} / {{ $t('Effective Date') }}</p>
            </div>
          </header>

          <div v-if="vehicle && vehicle.opening_balance !== null" class="tv-ob-readonly">
            <p class="text-sm text-gray-600 mb-3">
              {{ $t('This vehicle already has an opening balance on record. Correcting it requires a fresh password confirmation from the vehicle\'s own page.') }}
            </p>
            <div class="lg-fields text-sm">
              <div><p class="text-xs text-gray-500">{{ $t('Amount') }}</p><p class="font-semibold">{{ $money(vehicle.opening_balance) }}</p></div>
              <div><p class="text-xs text-gray-500">{{ $t('Effective Date') }}</p><p class="font-semibold">{{ vehicle.opening_balance_date ? $date(vehicle.opening_balance_date) : '—' }}</p></div>
            </div>
            <Link :href="route('transport-vehicles.show', vehicle.id)" class="tv-ob-link mt-4">
              <icons name="lock-closed" class="w-3.5 h-3.5 inline-flex mr-1" /> {{ $t('Go to vehicle page to correct it') }}
            </Link>
          </div>

          <div v-else>
            <p class="text-sm text-gray-500 mb-4">
              {{ $t('If this vehicle already had trips or costs before joining WIMS, record the outstanding amount here so this vehicle\'s totals start from the right position. Leave blank if there isn\'t one, this can only be set once without a password confirmation.') }}
            </p>
            <div class="lg-fields">
              <div>
                <tec-label for="opening_balance" :value="$t('Opening Balance Amount')" />
                <text-input id="opening_balance" type="number" min="0" step="0.01" class="mt-1 block w-full" v-model="form.opening_balance" />
                <input-error :message="$page.props.errors.opening_balance" class="mt-1" />
              </div>
              <div>
                <tec-label for="opening_balance_date" :value="$t('Effective Date')" />
                <text-input id="opening_balance_date" type="date" class="mt-1 block w-full" v-model="form.opening_balance_date" />
                <input-error :message="$page.props.errors.opening_balance_date" class="mt-1" />
              </div>
            </div>
            <div class="mt-4">
              <tec-label for="opening_balance_notes" :value="$t('Notes')" />
              <textarea
                id="opening_balance_notes"
                class="block w-full rounded-md border-gray-300 mt-1"
                rows="2"
                v-model="form.opening_balance_notes"
                :placeholder="$t('Where this figure comes from…')"
              ></textarea>
              <input-error :message="$page.props.errors.opening_balance_notes" class="mt-1" />
            </div>
          </div>
        </section>

        <div class="lg-actionbar">
          <span v-if="hasErrors" class="lg-actionbar-note tv-err-note">{{ $t('Please fix the highlighted fields.') }}</span>
          <div v-else></div>
          <div class="lg-actionbar-btns">
            <Link :href="route('transport-vehicles.index')" class="lg-btn lg-btn-soft">
              <icons name="cross" class="w-4 h-4" />
              {{ $t('Cancel') }}
            </Link>
            <button type="submit" :disabled="form.processing" class="lg-btn lg-btn-green">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
              {{ $t(vehicle ? 'Save' : 'Create Vehicle') }}
            </button>
          </div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TecLabel from '@/Jetstream/Label.vue';
import TextInput from '@/Shared/TextInput.vue';
import InputError from '@/Jetstream/InputError.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { Link, AdminLayout, TecLabel, TextInput, InputError, AutoComplete, PageHeader },

  props: {
    vehicle: Object,
    categories: Array,
    ownershipTypes: Array,
    statuses: Array,
    operatingPersonnelTypes: Array,
    fuelTypes: Array,
    makeSuggestions: Array,
    modelSuggestions: Array,
    colorSuggestions: Array,
    drivers: Array,
    canManageOpeningBalance: Boolean,
  },

  data() {
    return {
      activeTab: 'details',
      form: this.$inertia.form({
        name: this.vehicle ? this.vehicle.name : '',
        asset_category_id: this.vehicle ? this.vehicle.asset_category_id : null,
        registration_number: this.vehicle ? this.vehicle.registration_number : '',
        manufacturer: this.vehicle ? this.vehicle.manufacturer : '',
        model: this.vehicle ? this.vehicle.model : '',
        year: this.vehicle ? this.vehicle.year : '',
        color: this.vehicle ? this.vehicle.color : '',
        vin_chassis_number: this.vehicle ? this.vehicle.vin_chassis_number : '',
        engine_number: this.vehicle ? this.vehicle.engine_number : '',
        ownership_type: this.vehicle ? this.vehicle.ownership_type : '',
        // Defaults to the company's own name on a brand-new vehicle — most
        // registered vehicles are company-owned, so this saves re-typing it
        // every time; still freely editable for a leased/rented vehicle.
        owner_name: this.vehicle ? this.vehicle.owner_name : 'Rans Company Limited',
        capacity: this.vehicle ? this.vehicle.capacity : '',
        fuel_type: this.vehicle ? this.vehicle.fuel_type : '',
        status: this.vehicle ? this.vehicle.status : '',
        // Only 'Contact' assignment has real data available to this form (no
        // staff options prop is provided here), so it's the only usable
        // default — even when editing a vehicle that already has a contact.
        operating_personnel_type: 'Contact',
        operating_personnel_contact_id: this.vehicle ? this.vehicle.operating_personnel_contact_id : null,
        // Explicitly nulled (not just omitted) so saving a vehicle whose
        // stored assignment was 'Staff' actually clears
        // operating_personnel_staff_id server-side instead of leaving it
        // populated alongside a type this form just force-set to 'Contact'
        // — validated() only clears a field Laravel actually receives.
        operating_personnel_staff_id: null,
        notes: this.vehicle ? this.vehicle.notes : '',
        // Only meaningful when the vehicle has no opening balance yet — left
        // blank they're silently ignored server-side; once one exists, this
        // tab renders read-only instead and these never get typed into.
        opening_balance: '',
        opening_balance_date: '',
        opening_balance_notes: '',
      }),
    };
  },

  computed: {
    hasErrors() {
      return Object.keys(this.$page.props.errors || {}).length > 0;
    },
    // Flattens the Vehicles category + children into one suggestions list,
    // prefixing children with an em dash so the hierarchy still reads
    // (e.g. "Vehicles" vs "— Cars").
    categorySuggestions() {
      const categories = this.categories || [];
      const ids = new Set(categories.map(c => c.id));
      return categories.map(c => ({
        id: c.id,
        name: c.parent_id && ids.has(c.parent_id) ? '— ' + c.name : c.name,
      }));
    },
    driverSuggestions() {
      return (this.drivers || []).map(d => ({ id: d.id, name: d.phone ? `${d.name} (${d.phone})` : d.name }));
    },
    // Plain strings, not numbers — AutoComplete's client-side filter calls
    // .toLowerCase() on each suggestion when searching, which throws on a
    // raw Number. Newest first, since that's what's registered most often.
    yearOptions() {
      const years = [];
      for (let y = 2050; y >= 1980; y--) years.push(String(y));
      return years;
    },
  },

  watch: {
    'form.operating_personnel_type'(type) {
      if (type !== 'Contact') {
        this.form.operating_personnel_contact_id = null;
      }
    },
  },

  methods: {
    stepCapacity(delta) {
      const next = (Number(this.form.capacity) || 0) + delta;
      this.form.capacity = Math.max(0, next);
    },
    submit() {
      if (this.vehicle) {
        this.form.put(this.route('transport-vehicles.update', this.vehicle.id), { preserveScroll: true });
      } else {
        this.form.post(this.route('transport-vehicles.store'), { preserveScroll: true });
      }
    },
  },
};
</script>

<style scoped>
.tv-stepper {
  display: flex;
  align-items: stretch;
  border: 1px solid var(--lg-line);
  border-radius: 0.85rem;
  overflow: hidden;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}
.tv-stepper-btn {
  flex: none;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.75rem;
  font-size: 1.5rem;
  line-height: 1;
  font-weight: 700;
  color: #475569;
  background: #f8fafc;
  cursor: pointer;
  transition: background 0.15s ease, color 0.15s ease;
}
.tv-stepper-btn:hover {
  background: #e2e8f0;
  color: #1e293b;
}
.tv-stepper-btn:first-child { border-right: 1px solid #d1d5db; }
.tv-stepper-btn:last-child { border-left: 1px solid #d1d5db; }
.tv-stepper-input {
  flex: 1 1 auto;
  min-width: 0;
  text-align: center;
  border: none;
  padding: 0.5rem 0.5rem;
  font-size: 1rem;
  background: transparent;
}
.tv-stepper-input:focus { outline: none; }
/* Hide the browser's native up/down spinner — the +/- buttons replace it. */
.tv-stepper-input::-webkit-outer-spin-button,
.tv-stepper-input::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
.tv-stepper-input[type='number'] { -moz-appearance: textfield; }

.tv-toggle-group {
  display: inline-flex;
  gap: 0.35rem;
  padding: 0.3rem;
  background: var(--lg-soft);
  border-radius: 9999px;
}
.tv-toggle-btn {
  padding: 0.4rem 1rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: #64748b;
  border-radius: 9999px;
  transition: all 0.15s ease;
}
.tv-toggle-btn:hover { color: #1e293b; }
.tv-toggle-btn-active {
  background: #ffffff;
  color: #2563eb;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
}

.tv-tab-group {
  display: inline-flex;
  gap: 0.35rem;
  padding: 0.3rem;
  background: var(--lg-card);
  border: 1px solid var(--lg-line);
  border-radius: 9999px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}
.tv-tab-btn {
  position: relative;
  display: inline-flex;
  align-items: center;
  padding: 0.5rem 1.1rem;
  font-size: 0.85rem;
  font-weight: 600;
  color: #64748b;
  border-radius: 9999px;
  transition: all 0.15s ease;
}
.tv-tab-btn:hover { color: #1e293b; background: rgba(255, 255, 255, 0.5); }
.tv-tab-btn-active {
  background: #ffffff;
  color: #0284c7;
  box-shadow: 0 4px 10px -4px rgba(2, 132, 199, 0.35);
}
.tv-tab-dot {
  display: inline-block;
  width: 0.4rem;
  height: 0.4rem;
  border-radius: 9999px;
  background: #f59e0b;
  margin-left: 0.4rem;
}

.tv-ob-readonly {
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 0.75rem;
  padding: 1rem;
}
.tv-ob-link {
  display: inline-flex;
  align-items: center;
  font-size: 0.8rem;
  font-weight: 600;
  color: #b45309;
}
.tv-ob-link:hover { color: #92400e; text-decoration: underline; }

html.dark .tv-tab-group { background: rgba(255, 255, 255, 0.04); border-color: rgba(255, 255, 255, 0.1); }
html.dark .tv-tab-btn { color: #94a3b8; }
html.dark .tv-tab-btn:hover { background: rgba(255, 255, 255, 0.06); color: #e2e8f0; }
html.dark .tv-tab-btn-active { background: rgba(255, 255, 255, 0.08); color: #38bdf8; }
html.dark .tv-ob-readonly { background: rgba(245, 158, 11, 0.08); border-color: rgba(245, 158, 11, 0.25); }

/* inputs / labels: same field look as the other redone forms */
.tv-form :deep(.lg-fields label:not(:has(input[type='checkbox']))) {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
}
.tv-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file'])),
.tv-form :deep(.lg-fields select),
.tv-form :deep(textarea) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.tv-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file']):focus),
.tv-form :deep(.lg-fields select:focus),
.tv-form :deep(textarea:focus) {
  border-color: #38bdf8 !important;
  box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2) !important;
}
.tv-err-note { color: #be123c; }
html.dark .tv-err-note { color: #fda4af; }

html.dark .tv-stepper { border-color: rgba(255, 255, 255, 0.12); }
html.dark .tv-stepper-btn {
  background: rgba(255, 255, 255, 0.04);
  color: #cbd5e1;
  border-color: rgba(255, 255, 255, 0.12) !important;
}
html.dark .tv-stepper-btn:hover { background: rgba(255, 255, 255, 0.08); color: #f1f5f9; }
html.dark .tv-stepper-input { color: #f1f5f9; }
</style>

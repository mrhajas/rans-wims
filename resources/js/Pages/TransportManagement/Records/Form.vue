<template>
  <admin-layout :title="$t(isEdit ? 'edit_x' : 'create_x', { x: $t('Transport Routes Record') })">
    <div class="lg-page px-4 md:px-0 tr-form">
      <page-header
        icon="truck"
        :title="$t(isEdit ? 'edit_x' : 'create_x', { x: $t('Transport Routes Record') })"
        :subtitle="$t('Log a vehicle trip, or several identical trips made on the same day, as one record.')"
      >
        <span v-if="isEdit" class="lg-chip lg-chip-blue" style="text-transform: none">{{ record.trip_number }}</span>
        <Link :href="route('transport-records.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Transport Routes Records') }}</Link>
      </page-header>

      <div v-if="isEdit && record.requires_password_to_edit" class="tr-paid-edit-banner mb-4">
        <icons name="lock-closed" class="w-4 h-4 inline-flex mr-2 flex-none" />
        {{ $t('This trip already has a payment recorded against it. Saving a correction here requires a fresh password confirmation.') }}
      </div>

      <form @submit.prevent="handleFormSubmit">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="truck" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Trip Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Date') }} / {{ $t('Vehicle') }} / {{ $t('Driver (optional)') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <date-picker :label="$t('Date')" v-model="form.trip_date" :error="$page.props.errors.trip_date" />
            </div>
            <div>
              <tec-label for="trip_time" :value="$t('Time (optional)')" />
              <input
                id="trip_time"
                type="time"
                v-model="form.trip_time"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
              />
              <input-error :message="$page.props.errors.trip_time" class="mt-1" />
            </div>
            <div>
              <tec-label :value="$t('Vehicle')" />
              <auto-complete
                id="asset_id"
                class="mt-1"
                :suggestions="vehicleOptions"
                v-model="form.asset_id"
                :placeholder="$t('Search vehicle…')"
                :error="$page.props.errors.asset_id"
              />
            </div>
            <div>
              <tec-label :value="$t('Driver (optional)')" />
              <auto-complete
                id="driver_contact_id"
                class="mt-1"
                :suggestions="drivers"
                v-model="form.driver_contact_id"
                :placeholder="$t('Search driver…')"
                :error="$page.props.errors.driver_contact_id"
                clearable
              />
            </div>
            <div>
              <tec-label :value="$t('Route')" />
              <auto-complete id="route_mode" class="mt-1" :suggestions="routeOptions" v-model="routeMode" :placeholder="$t('Select route…')" />
              <p class="lg-subtext" v-if="form.is_campus">{{ $t('Moving from one place to another inside the same site campus. No destination is needed.') }}</p>
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="map-pin" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ form.is_campus ? $t('Site Campus') : $t('Departure') }}</h2>
              <p class="lg-card-sub">{{ form.is_campus ? $t('Which site is the movement inside?') : $t('Departure Type') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <tec-label for="departure_type" :value="form.is_campus ? $t('Site type') : $t('Departure Type')" />
              <auto-complete
                id="departure_type"
                class="mt-1"
                :suggestions="form.is_campus ? campusTypeOptions : destinationTypeOptions"
                v-model="form.departure_type"
                :placeholder="$t('Select departure type…')"
                :error="$page.props.errors.departure_type"
                :clearable="isEdit"
              />
            </div>

            <div v-if="departureGroup === 'mixed'" class="tr-mixed-row">
              <tec-label :value="mainLabel + ' (' + $t('select two or more') + ')'" />
              <div class="tr-multi" :class="{ 'is-error': $page.props.errors.departure_warehouse_ids }">
                <button v-for="w in mainWarehouses" :key="'d' + w.id" type="button" class="tr-chip" :class="{ 'is-on': form.departure_warehouse_ids.includes(w.id) }" :aria-pressed="form.departure_warehouse_ids.includes(w.id)" @click="toggleId(form.departure_warehouse_ids, w.id)">{{ w.name }}</button>
              </div>
              <p class="lg-subtext">{{ form.departure_warehouse_ids.length }} {{ $t('selected') }}</p>
              <input-error :message="$page.props.errors.departure_warehouse_ids" class="mt-1" />
            </div>
            <div v-else-if="departureGroup === 'warehouse'">
              <tec-label :value="form.departure_type === 'Project' ? $t('Project') : mainLabel" />
              <auto-complete
                id="departure_warehouse_id"
                class="mt-1"
                :suggestions="form.departure_type === 'Project' ? projectWarehouses : mainWarehouses"
                v-model="form.departure_warehouse_id"
                :placeholder="$t('Search') + '…'"
                :error="$page.props.errors.departure_warehouse_id"
              />
            </div>
            <div v-else-if="departureGroup === 'contact'">
              <tec-label :value="form.departure_type === 'Supplier' ? $t('Supplier') : $t('Client')" />
              <auto-complete
                id="departure_contact_id"
                class="mt-1"
                :suggestions="form.departure_type === 'Supplier' ? suppliers : clients"
                v-model="form.departure_contact_id"
                :placeholder="$t('Search') + '…'"
                :error="$page.props.errors.departure_contact_id"
              />
            </div>
            <div v-else-if="departureGroup === 'location'">
              <tec-label :value="$t('Location')" />
              <auto-complete
                id="departure_transport_location_id"
                class="mt-1"
                :suggestions="transportLocationsByType[form.departure_type] || []"
                v-model="form.departure_transport_location_id"
                :placeholder="$t('Search location…')"
                :error="$page.props.errors.departure_transport_location_id"
              />
            </div>

            <div v-if="form.departure_type">
              <tec-label for="departure_location" :value="form.is_campus ? $t('From (area / block / gate, optional)') : $t('Departure Address / Gate (optional)')" />
              <text-input id="departure_location" type="text" class="mt-1 block w-full" v-model="form.departure_location" :error="$page.props.errors.departure_location" />
            </div>
            <div v-if="form.is_campus && form.departure_type">
              <tec-label for="campus_to" :value="$t('To (area / block / gate, optional)')" />
              <text-input id="campus_to" type="text" class="mt-1 block w-full" v-model="form.destination_location" :error="$page.props.errors.destination_location" />
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-violet"><icons name="map-pin" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ form.is_campus ? $t('Delivery details') : $t('Destination') }}</h2>
              <p class="lg-card-sub">{{ form.is_campus ? $t('Same site campus, so no destination is needed.') : $t('Destination Type') }}</p>
            </div>
          </header>
          <div v-if="!form.is_campus" class="lg-fields">
            <div>
              <tec-label for="destination_type" :value="$t('Destination Type')" />
              <auto-complete
                id="destination_type"
                class="mt-1"
                :suggestions="destinationTypeOptions"
                v-model="form.destination_type"
                :placeholder="$t('Select destination type…')"
                :error="$page.props.errors.destination_type"
              />
            </div>

            <div v-if="destinationGroup === 'mixed'" class="tr-mixed-row">
              <tec-label :value="mainLabel + ' (' + $t('select two or more') + ')'" />
              <div class="tr-multi" :class="{ 'is-error': $page.props.errors.destination_warehouse_ids }">
                <button v-for="w in mainWarehouses" :key="'t' + w.id" type="button" class="tr-chip" :class="{ 'is-on': form.destination_warehouse_ids.includes(w.id) }" :aria-pressed="form.destination_warehouse_ids.includes(w.id)" @click="toggleId(form.destination_warehouse_ids, w.id)">{{ w.name }}</button>
              </div>
              <p class="lg-subtext">{{ form.destination_warehouse_ids.length }} {{ $t('selected') }}</p>
              <input-error :message="$page.props.errors.destination_warehouse_ids" class="mt-1" />
            </div>
            <div v-else-if="destinationGroup === 'warehouse'">
              <tec-label :value="form.destination_type === 'Project' ? $t('Project') : mainLabel" />
              <auto-complete
                id="warehouse_id"
                class="mt-1"
                :suggestions="form.destination_type === 'Project' ? projectWarehouses : mainWarehouses"
                v-model="form.warehouse_id"
                :placeholder="$t('Search') + '…'"
                :error="$page.props.errors.warehouse_id"
              />
            </div>
            <div v-else-if="destinationGroup === 'contact'">
              <tec-label :value="form.destination_type === 'Supplier' ? $t('Supplier') : $t('Client')" />
              <auto-complete
                id="destination_contact_id"
                class="mt-1"
                :suggestions="form.destination_type === 'Supplier' ? suppliers : clients"
                v-model="form.destination_contact_id"
                :placeholder="$t('Search') + '…'"
                :error="$page.props.errors.destination_contact_id"
              />
            </div>
            <div v-else-if="destinationGroup === 'location'">
              <tec-label :value="$t('Location')" />
              <auto-complete
                id="transport_location_id"
                class="mt-1"
                :suggestions="transportLocationsByType[form.destination_type] || []"
                v-model="form.transport_location_id"
                :placeholder="$t('Search location…')"
                :error="$page.props.errors.transport_location_id"
              />
            </div>

            <div v-if="form.destination_type">
              <tec-label for="destination_location" :value="$t('Destination Address / Gate (optional)')" />
              <text-input id="destination_location" type="text" class="mt-1 block w-full" v-model="form.destination_location" :error="$page.props.errors.destination_location" />
            </div>
          </div>

          <div class="lg-fields mt-4">
          <div>
            <tec-label for="delivery_note_ref" :value="$t('Delivery Note / Issue Note Ref No (optional)')" />
            <text-input id="delivery_note_ref" type="text" class="mt-1 block w-full" v-model="form.delivery_note_ref" :error="$page.props.errors.delivery_note_ref" :placeholder="$t('e.g. DN-00123 or Issue Note number')" />
            <input-error :message="$page.props.errors.delivery_note_ref" class="mt-1" />
          </div>

          <div class="lg-span-all">
            <tec-label for="purpose" :value="$t('Purpose / Work Performed (optional)')" />
            <textarea
              id="purpose"
              class="mt-1 block w-full rounded-md border-gray-300"
              rows="2"
              v-model="form.purpose"
              :placeholder="$t('What was this trip for?')"
            ></textarea>
            <input-error :message="$page.props.errors.purpose" class="mt-1" />
          </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Amount and Trips') }}</h2>
              <p class="lg-card-sub">{{ $t('Default per trip') }}: {{ $money(isEdit ? record.default_amount : defaultAmount) }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <tec-label for="amount" :value="$t('Amount per trip')" />
              <text-input id="amount" type="number" step="0.01" class="mt-1 block w-full" v-model="form.amount" :error="$page.props.errors.amount" />
              <p class="text-xs text-gray-500 mt-1">{{ $t('Default') }}: {{ $money(isEdit ? record.default_amount : defaultAmount) }}</p>
            </div>
            <div>
              <tec-label :value="$t('Number of trips')" />
              <auto-complete id="trips_count" class="mt-1" :suggestions="tripOptions" v-model="form.trips_count" :placeholder="$t('Search number of trips…')" :error="$page.props.errors.trips_count" />
              <p class="text-xs text-gray-500 mt-1">{{ $t('One trip by default, up to :n trips on one record.', { n: maxTrips }) }}</p>
            </div>
            <div>
              <tec-label :value="$t('Total Amount')" />
              <div class="tr-total" aria-live="polite">
                <strong>{{ $money(totalAmount) }}</strong>
                <span>{{ form.trips_count }} × {{ $money(form.amount || 0) }}</span>
              </div>
              <p class="text-xs text-gray-500 mt-1">{{ $t('This total is what is paid, vouchered and counted in balances.') }}</p>
            </div>
          </div>

          <div v-if="amountChanged" class="mt-4">
            <tec-label :value="$t('Reason for change')" />
            <auto-complete
              id="amount_change_reason"
              class="mt-1"
              :suggestions="amountChangeReasonSuggestions"
              v-model="form.amount_change_reason"
              :placeholder="$t('Select a reason or type your own…')"
              :error="$page.props.errors.amount_change_reason"
              freeform
              clearable
            />
            <input-error :message="$page.props.errors.amount_change_reason" class="mt-1" />
            <p class="tr-amount-note mt-2">
              <icons name="exclamation" class="w-3.5 h-3.5 inline-flex mr-1" />
              {{ $t('Changing the amount from the default will be logged and requires a reason.') }}
            </p>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
              <p class="lg-card-sub">{{ $t('Select files') }}</p>
            </div>
            <span class="lg-chip lg-chip-slate tr-att-count">{{ form.attachments.length }} {{ $t('file(s)') }}</span>
          </header>

          <ul v-if="isEdit && record.attachments && record.attachments.length" class="tr-att-list mb-3">
            <li v-for="a in record.attachments" :key="a.id" class="tr-att-item py-2 px-3 flex items-center justify-between gap-3">
              <span class="inline-flex items-center gap-2 min-w-0">
                <icons :name="isImage(a) ? 'image' : 'doc'" class="w-4 h-4 text-gray-400 flex-none" />
                <span class="truncate text-sm text-gray-700">{{ a.title || a.filename }}</span>
              </span>
              <span class="flex items-center gap-1 flex-none">
                <a :href="previewUrl(a)" target="_blank" rel="noopener" class="sa-att-icon-btn lg-tip" :data-tip="$t('Preview')"><icons name="eye" class="w-4 h-4" /></a>
                <a :href="downloadUrl(a)" class="sa-att-icon-btn lg-tip" :data-tip="$t('Download')"><icons name="download" class="w-4 h-4" /></a>
                <button type="button" class="sa-att-icon-btn sa-att-icon-btn-danger lg-tip" :data-tip="$t('Remove')" @click="deleteExistingAttachment(a)">
                  <icons name="trash" class="w-4 h-4" />
                </button>
              </span>
            </li>
          </ul>

          <input id="tr-attachments" type="file" multiple class="sr-only" @change="onAttachmentsChange" accept=".png,.jpg,.jpeg,.pdf,.doc,.docx,.xls,.xlsx,.zip" />
          <label for="tr-attachments" class="tr-pick w-full sm:w-auto min-h-[44px] inline-flex items-center justify-between gap-3 px-4 py-2 cursor-pointer">
            <span class="inline-flex items-center gap-2 text-sm text-gray-700">
              <icons name="clip" class="w-4 h-4 inline-flex text-blue-600" />
              <span>{{ $t('Select files') }}</span>
            </span>
            <span class="text-xs font-medium px-2 py-1 rounded bg-blue-50 text-blue-700">
              {{ form.attachments.length ? form.attachments.length + ' ' + $t('file(s)') : $t('No files chosen') }}
            </span>
          </label>

          <ul class="space-y-1.5 mt-3" v-if="form.attachments.length">
            <li v-for="(file, fi) in form.attachments" :key="fi" class="sa-file-row tr-file-row flex items-center justify-between gap-2 text-xs px-3 py-2">
              <span class="inline-flex items-center gap-2 min-w-0">
                <icons name="doc" class="w-3.5 h-3.5 inline-flex text-gray-400 flex-none" />
                <span class="truncate">{{ fileLabel(file) }}</span>
              </span>
              <span class="inline-flex items-center gap-1 flex-none">
                <button type="button" class="sa-att-icon-btn lg-tip" :data-tip="$t('Preview')" @click="previewAttachment(file)">
                  <icons name="eye" class="w-3.5 h-3.5 inline-flex" />
                </button>
                <button type="button" class="sa-att-icon-btn sa-att-icon-btn-danger lg-tip" :data-tip="$t('Remove')" @click="removeAttachment(fi)"><icons name="cross" class="w-3.5 h-3.5 inline-flex" /></button>
              </span>
            </li>
          </ul>
          <input-error :message="$page.props.errors.attachments" class="mt-2" />
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-slate"><icons name="doc" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Notes') }}</h2>
              <p class="lg-card-sub">{{ $t('Any additional context about this trip…') }}</p>
            </div>
          </header>
          <textarea
            class="block w-full rounded-md border-gray-300"
            rows="3"
            v-model="form.notes"
            :placeholder="$t('Any additional context about this trip…')"
          ></textarea>
          <input-error :message="$page.props.errors.notes" class="mt-1" />
        </section>

        <div class="lg-actionbar">
          <span v-if="hasErrors" class="lg-actionbar-note tr-err-note">{{ $t('Please fix the highlighted fields.') }}</span>
          <div v-else></div>
          <div class="lg-actionbar-btns">
          <Link :href="route('transport-records.index')" class="lg-btn lg-btn-soft">
            <icons name="cross" class="w-4 h-4" />
            {{ $t('Cancel') }}
          </Link>
          <spr-confirm-password
            v-if="isEdit && record.requires_password_to_edit"
            :title="$t('Correct Trip Amount')"
            :content="$t('This trip already has a payment recorded against it. Correcting it changes its financial record. Please confirm your password to continue.')"
            @confirmed="submit"
          >
            <button type="button" :disabled="form.processing" class="lg-btn lg-btn-green">
              <icons :name="form.processing ? 'refresh' : 'lock-closed'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
              {{ $t('Save Correction') }}
            </button>
          </spr-confirm-password>
          <button v-else type="submit" :disabled="form.processing" class="lg-btn lg-btn-green">
            <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
            {{ $t(isEdit ? 'Save' : 'Create Route') }}
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
import DatePicker from '@/Shared/DatePicker.vue';
import SprConfirmPassword from '@/Shared/SprConfirmPassword.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import { transportTypeLabel, MIXED_TYPE, MAIN_WAREHOUSES } from '@/Core/transportTypes';

const WAREHOUSE_DESTINATION_TYPES = ['Project', 'Warehouse'];
const CONTACT_DESTINATION_TYPES = ['Supplier', 'Client'];

export default {
  components: { Link, AdminLayout, TecLabel, TextInput, InputError, AutoComplete, DatePicker, SprConfirmPassword, PageHeader },

  props: {
    record: Object,
    vehicles: Array,
    drivers: Array,
    projectWarehouses: Array,
    mainWarehouses: Array,
    suppliers: Array,
    clients: Array,
    transportLocationsByType: Object,
    destinationTypes: Array,
    defaultAmount: [Number, String],
    amountChangeReasonSuggestions: Array,
    today: String,
  },

  data() {
    const isEdit = !!this.record;

    return {
      form: this.$inertia.form({
        trip_date: isEdit ? (this.record.trip_date || '').toString().slice(0, 10) : (this.today || ''),
        trip_time: isEdit ? this.record.trip_time || '' : '',
        asset_id: isEdit ? this.record.asset_id : null,
        driver_contact_id: isEdit ? this.record.driver_contact_id : null,
        departure_type: isEdit ? this.record.departure_type || '' : '',
        departure_warehouse_id: isEdit ? this.record.departure_warehouse_id : null,
        departure_warehouse_ids: isEdit ? [...(this.record.departure_warehouse_ids || [])] : [],
        departure_contact_id: isEdit ? this.record.departure_contact_id : null,
        departure_transport_location_id: isEdit ? this.record.departure_transport_location_id : null,
        destination_type: isEdit ? this.record.destination_type : '',
        warehouse_id: isEdit ? this.record.warehouse_id : null,
        destination_warehouse_ids: isEdit ? [...(this.record.destination_warehouse_ids || [])] : [],
        destination_contact_id: isEdit ? this.record.destination_contact_id : null,
        transport_location_id: isEdit ? this.record.transport_location_id : null,
        departure_location: isEdit ? this.record.departure_location || '' : '',
        destination_location: isEdit ? this.record.destination_location || '' : '',
        delivery_note_ref: isEdit ? this.record.delivery_note_ref || '' : '',
        purpose: isEdit ? this.record.purpose || '' : '',
        is_campus: isEdit ? !!this.record.is_campus : false,
        trips_count: isEdit ? this.record.trips_count || 1 : 1,
        amount: isEdit ? this.record.unit_amount : this.defaultAmount,
        amount_change_reason: isEdit ? this.record.amount_change_reason || '' : '',
        notes: isEdit ? this.record.notes || '' : '',
        attachments: [],
      }),
    };
  },

  computed: {
    mainLabel() {
      return MAIN_WAREHOUSES;
    },
    hasErrors() {
      return Object.keys(this.$page.props.errors || {}).length > 0;
    },
    isEdit() {
      return !!this.record;
    },
    vehicleOptions() {
      return (this.vehicles || []).map(v => ({ id: v.id, name: (v.registration_number ? v.registration_number + ' - ' : '') + v.name }));
    },
    destinationTypeOptions() {
      return this.destinationTypes.map(d => ({ value: d, label: transportTypeLabel(this.$t, d) }));
    },
    departureGroup() {
      if (this.form.departure_type === MIXED_TYPE) return 'mixed';
      if (WAREHOUSE_DESTINATION_TYPES.includes(this.form.departure_type)) return 'warehouse';
      if (CONTACT_DESTINATION_TYPES.includes(this.form.departure_type)) return 'contact';
      if (this.form.departure_type) return 'location';
      return null;
    },
    destinationGroup() {
      if (this.form.destination_type === MIXED_TYPE) return 'mixed';
      if (WAREHOUSE_DESTINATION_TYPES.includes(this.form.destination_type)) return 'warehouse';
      if (CONTACT_DESTINATION_TYPES.includes(this.form.destination_type)) return 'contact';
      if (this.form.destination_type) return 'location';
      return null;
    },
    maxTrips() {
      return 50;
    },
    tripOptions() {
      return Array.from({ length: this.maxTrips }, (_, i) => ({ value: i + 1, label: i + 1 === 1 ? this.$t('1 trip') : this.$t(':n trips', { n: i + 1 }) }));
    },
    totalAmount() {
      return Math.round((Number(this.form.amount) || 0) * (Number(this.form.trips_count) || 1) * 100) / 100;
    },
    routeOptions() {
      return [
        { value: 'between', label: this.$t('Between two places') },
        { value: 'campus', label: this.$t('Within one site campus') },
      ];
    },
    routeMode: {
      get() {
        return this.form.is_campus ? 'campus' : 'between';
      },
      set(v) {
        this.form.is_campus = v === 'campus';
      },
    },
    campusTypeOptions() {
      return this.destinationTypeOptions.filter(o => ['Project', 'Site'].includes(o.value));
    },
    amountChanged() {
      const base = this.isEdit ? this.record.default_amount : this.defaultAmount;
      return Number(this.form.amount) !== Number(base);
    },
  },

  watch: {
    // Clears whichever of warehouse_id / destination_contact_id /
    // transport_location_id is no longer relevant whenever the destination
    // type changes, so a stale hidden value never gets submitted — mirrors
    // the tagged-union enforced server-side by TransportRecordRequest.
    // Switching to a campus movement only keeps a departure that can be a site (Project / Site); switching back
    // clears the "to" area text that only the campus layout shows.
    'form.is_campus'(on) {
      if (on && this.form.departure_type && !['Project', 'Site'].includes(this.form.departure_type)) this.form.departure_type = '';
      if (!on) this.form.destination_location = '';
    },
    'form.departure_type'(newVal, oldVal) {
      if (newVal === oldVal) return;
      this.form.departure_warehouse_id = null;
      this.form.departure_warehouse_ids = [];
      this.form.departure_contact_id = null;
      this.form.departure_transport_location_id = null;
    },
    'form.destination_type'(newVal, oldVal) {
      if (newVal === oldVal) return;
      this.form.warehouse_id = null;
      this.form.destination_warehouse_ids = [];
      this.form.destination_contact_id = null;
      this.form.transport_location_id = null;
    },
    // Defaults the trip's driver to whichever vehicle was just picked —
    // only fires on an actual change (not on initial mount, so opening an
    // existing trip for editing never clobbers its stored driver), and the
    // field stays a normal picker the user can still change afterward.
    'form.asset_id'(newVal, oldVal) {
      if (newVal === oldVal) return;
      const vehicle = (this.vehicles || []).find(v => v.id === newVal);
      this.form.driver_contact_id = (vehicle && vehicle.operating_personnel_contact_id) || null;
    },
  },

  methods: {
    toggleId(list, id) {
      const i = list.indexOf(id);
      if (i === -1) list.push(id); else list.splice(i, 1);
    },
    onAttachmentsChange(e) {
      const newFiles = Array.from(e.target.files || []);
      const existingKeys = new Set(this.form.attachments.map(f => `${f.name}:${f.size}:${f.lastModified}`));
      newFiles.forEach(file => {
        const key = `${file.name}:${file.size}:${file.lastModified}`;
        if (!existingKeys.has(key)) {
          this.form.attachments.push(file);
          existingKeys.add(key);
        }
      });
      e.target.value = '';
    },
    removeAttachment(index) {
      this.form.attachments.splice(index, 1);
    },
    fileLabel(file) {
      if (!file || typeof file !== 'object') return '';
      return file.name || 'Attachment';
    },
    // Files staged here haven't been uploaded yet (this is the create form,
    // no attachment id to fetch from the server), so preview a local blob:
    // URL for the in-browser File object instead — opened in a new tab
    // exactly like every other attachment preview in the app. The browser
    // renders it inline for images/PDFs and falls back to a download for
    // types it can't display (.docx, .xlsx, .zip), which is normal browser
    // behavior for any "preview" link, not something this page controls.
    // URLs are tracked and revoked on unmount so previewing many files in
    // one session doesn't leak memory.
    previewAttachment(file) {
      if (!file) return;
      const url = URL.createObjectURL(file);
      this._previewUrls = this._previewUrls || [];
      this._previewUrls.push(url);
      window.open(url, '_blank', 'noopener');
    },
    // Already-uploaded attachments (edit mode only) have a real server URL
    // and id, so these hit the actual attachment routes directly — same as
    // Records/Show.vue — unlike previewAttachment()/removeAttachment()
    // above, which only ever operate on local, not-yet-uploaded File objects.
    previewUrl(a) {
      return this.route('transport-records.attachments.download', [this.record.id, a.id]) + '?disposition=inline';
    },
    downloadUrl(a) {
      return this.route('transport-records.attachments.download', [this.record.id, a.id]);
    },
    isImage(a) {
      return !!(a.filetype && a.filetype.startsWith('image/'));
    },
    async deleteExistingAttachment(a) {
      if (!(await this.$confirm(this.$t('Remove this attachment?'), { variant: 'danger' }))) return;
      this.$inertia.delete(this.route('transport-records.attachments.destroy', [this.record.id, a.id]), { preserveScroll: true });
    },
    // The password-confirmed correction path (SprConfirmPassword-wrapped
    // button, type="button") is the only way to submit when
    // requires_password_to_edit is true — pressing Enter must never
    // silently reach transport-records.update-paid without that
    // confirmation ever happening.
    handleFormSubmit() {
      if (this.isEdit && this.record.requires_password_to_edit) return;
      this.submit();
    },
    submit() {
      if (this.isEdit) {
        // PHP never parses a multipart/form-data body on a literal PUT/PATCH
        // request (only POST) — Inertia's form.put() doesn't spoof around
        // this automatically, so a real PUT/PATCH carrying new file
        // attachments arrives at Laravel with a completely empty request,
        // failing every "field is required" rule. Posting with a spoofed
        // _method is the documented Inertia/Laravel workaround for file
        // uploads on an update — mirrors transport-records.store()'s own
        // forceFormData handling just below. update-paid is registered as
        // PATCH only (routes/web.php), so it must spoof 'patch', not 'put'.
        const usingPaidCorrection = this.record.requires_password_to_edit;
        const routeName = usingPaidCorrection ? 'transport-records.update-paid' : 'transport-records.update';
        const method = usingPaidCorrection ? 'patch' : 'put';
        this.form
          .transform(data => ({ ...data, _method: method }))
          .post(this.route(routeName, this.record.id), { forceFormData: true, preserveScroll: true });
      } else {
        this.form.post(this.route('transport-records.store'), { forceFormData: true, preserveScroll: true });
      }
    },
  },

  beforeUnmount() {
    (this._previewUrls || []).forEach(url => URL.revokeObjectURL(url));
  },
};
</script>

<style scoped>
/* inputs / labels: same field look as the other redone forms */
.tr-form :deep(.lg-card label:not(:has(input[type='checkbox'])):not(.tr-pick)) {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
}
.tr-form :deep(.lg-card input:not([type='checkbox']):not([type='radio']):not([type='file'])),
.tr-form :deep(.lg-card select),
.tr-form :deep(.lg-card textarea) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.tr-form :deep(.lg-card input:not([type='checkbox']):not([type='radio']):not([type='file']):focus),
.tr-form :deep(.lg-card select:focus),
.tr-form :deep(.lg-card textarea:focus) {
  border-color: #38bdf8 !important;
  box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2) !important;
}

.tr-att-count { margin-left: auto; font-variant-numeric: tabular-nums; }
.tr-att-list { border: 1px solid var(--lg-line); border-radius: 16px; background: var(--lg-soft); }
.tr-att-item + .tr-att-item { border-top: 1px solid var(--lg-line); }
.tr-pick {
  border: 1px dashed #93c5fd; border-radius: 9999px; background: var(--lg-soft); color: var(--lg-ink);
  transition: background 0.15s ease, border-color 0.15s ease;
}
.tr-pick:hover { background: rgba(59, 130, 246, 0.08); border-color: #3b82f6; }
.tr-file-row { color: var(--lg-mute); background: var(--lg-soft); border: 1px solid var(--lg-line); border-radius: 12px; }

.sa-att-icon-btn {
  display: inline-flex; align-items: center; justify-content: center; width: 1.85rem; height: 1.85rem; min-height: 0; padding: 0; flex-shrink: 0;
  color: var(--lg-mute); background: var(--lg-card); border: 1px solid var(--lg-line); border-radius: 0.6rem; transition: background 0.15s ease, color 0.15s ease, transform 0.15s ease;
}
.sa-att-icon-btn:hover { background: var(--lg-soft); color: var(--lg-ink); transform: translateY(-1px); }
.sa-att-icon-btn-danger:hover { background: #fee2e2; color: #b91c1c; }

.sa-file-row {
  animation: sa-file-in 0.18s ease-out;
}
@keyframes sa-file-in {
  from { opacity: 0; transform: translateY(-4px); }
  to { opacity: 1; transform: translateY(0); }
}

.tr-total { margin-top: 4px; display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 10px; padding: 8px 12px; min-height: 42px; border-radius: 10px; background: #ecfdf5; border: 1px solid #a7f3d0; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06); }
.tr-total strong { font-size: 1.05rem; font-weight: 800; color: #047857; font-variant-numeric: tabular-nums; }
.tr-total span { font-size: 0.74rem; color: #475569; font-variant-numeric: tabular-nums; }
html.dark .tr-total { background: rgba(16, 185, 129, 0.12); border-color: rgba(16, 185, 129, 0.3); }
html.dark .tr-total strong { color: #6ee7b7; }
.tr-amount-note {
  display: flex;
  align-items: center;
  font-size: 0.75rem;
  font-weight: 600;
  color: #b45309;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 0.85rem;
  padding: 0.45rem 0.65rem;
}
html.dark .tr-amount-note { background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.25); color: #fbbf24; }

.tr-paid-edit-banner {
  display: flex;
  align-items: center;
  background: #fffbeb;
  border: 1px solid #fde68a;
  color: #92400e;
  font-size: 0.82rem;
  font-weight: 600;
  padding: 0.75rem 1rem;
  border-radius: 1rem;
}
html.dark .tr-paid-edit-banner { background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.25); color: #fbbf24; }

.tr-err-note { color: #be123c; }
html.dark .tr-err-note { color: #fda4af; }

@media (prefers-reduced-motion: reduce) {
  .sa-file-row { animation: none; }
}
.tr-mixed-row { grid-column: 1 / -1; }
.tr-multi { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 0.3rem; padding: 8px; border: 1px solid var(--lg-line); border-radius: 0.85rem; background: var(--lg-soft); }
.tr-multi.is-error { border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.18); }
.tr-chip { padding: 5px 12px; border-radius: 9999px; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-mute); font-size: 0.78rem; font-weight: 700; transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease; }
.tr-chip:hover { border-color: #4f46e5; }
.tr-chip.is-on { background: rgba(79, 70, 229, 0.15); border-color: rgba(79, 70, 229, 0.55); color: #4338ca; }
html.dark .tr-chip.is-on { color: #c7d2fe; }
</style>

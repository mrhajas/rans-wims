<template>
  <admin-layout :title="asset ? $t('Edit Asset') : $t('Add Asset')">
    <div class="lg-page px-4 md:px-0 af-form">
      <page-header
        icon="chip"
        :title="asset ? $t('Edit Asset') : $t('Register Asset')"
        :subtitle="asset ? $t('Update the record by modifying the details in the form below') : $t('Please fill the form below to add new record.')"
      >
        <span v-if="asset" class="lg-chip lg-chip-blue" style="text-transform: none">{{ asset.asset_number }}</span>
        <Link :href="route('assets.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Assets') }}</Link>
      </page-header>

      <form @submit.prevent="submit">
        <!-- Basic details: name, then type straight after it -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="chip" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Basic Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Asset Name') }} / {{ $t('Asset Type') }} / {{ $t('Category') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <text-input :label="$t('Asset Name')" v-model="form.name" :error="$page.props.errors.name" required />
            <auto-complete
              id="asset_type"
              :label="$t('Asset Type')"
              v-model="form.asset_type"
              :suggestions="assetTypeOptions"
              :searchable="false"
              :error="$page.props.errors.asset_type"
              clearable
            />
            <auto-complete id="asset_category_id" :label="$t('Category')" v-model="form.asset_category_id" :suggestions="categoryOptions" clearable />
            <div v-if="isLegacyType" class="lg-span-all">
              <p class="lg-note lg-note-amber">
                <icons name="exclamation" class="w-4 h-4" />
                <span>{{ $t('This asset has an older type ({type}). You can keep it or switch to Fixed Asset / UnFixed Asset.', { type: legacyType }) }}</span>
              </p>
            </div>
            <div class="lg-span-all">
              <label class="af-label">{{ $t('Description') }}</label>
              <textarea v-model="form.description" rows="2" class="w-full rounded-md border-gray-300"></textarea>
            </div>
          </div>
        </section>

        <!-- Photos: up to 3, hover lens + lightbox slideshow -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="image" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Photos') }}</h2>
              <p class="lg-card-sub">{{ $t('Up to {max} photos. Hover to zoom, click to view one after another.', { max: maxPhotos }) }}</p>
            </div>
            <span class="lg-chip lg-chip-slate af-photo-count">{{ galleryPhotos.length }} / {{ maxPhotos }}</span>
          </header>
          <photo-gallery :photos="galleryPhotos" :max="maxPhotos" :size="96" :zoom="230" removable @remove="removePhoto" @add="$refs.photoInput.click()" />
          <input ref="photoInput" type="file" multiple accept="image/png,image/jpeg,image/webp" class="hidden" @change="onPhotosChosen" />
          <div class="af-photo-actions">
            <button type="button" class="lg-btn lg-btn-soft" :disabled="galleryPhotos.length >= maxPhotos" @click="$refs.photoInput.click()">
              <icons name="upload" class="w-4 h-4" /> {{ galleryPhotos.length ? $t('Add more photos') : $t('Choose photos') }}
            </button>
            <button v-if="removedPhotoIds.length" type="button" class="lg-btn lg-btn-soft" @click="undoPhotoRemovals">
              <icons name="refresh" class="w-4 h-4" /> {{ $t('Undo removal') }} ({{ removedPhotoIds.length }})
            </button>
          </div>
          <p class="af-hint">{{ $t('JPG, PNG or WEBP, up to 5 MB each.') }}</p>
          <p v-if="removedPhotoIds.length" class="lg-note lg-note-amber mt-3">
            <icons name="exclamation" class="w-4 h-4" />
            <span>{{ $t('{n} photo(s) will be removed when you save.', { n: removedPhotoIds.length }) }}</span>
          </p>
          <p v-if="photoError || $page.props.errors.photos" class="lg-note lg-note-red mt-3">
            <icons name="exclamation" class="w-4 h-4" />
            <span>{{ photoError || $page.props.errors.photos }}</span>
          </p>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="hash" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Identification') }}</h2>
              <p class="lg-card-sub">{{ $t('Manufacturer') }} / {{ $t('Model') }} / {{ $t('Serial Number') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <text-input :label="$t('Manufacturer')" v-model="form.manufacturer" />
            <text-input :label="$t('Model')" v-model="form.model" />
            <text-input :label="$t('Serial Number')" v-model="form.serial_number" />
            <text-input :label="$t('Registration Number')" v-model="form.registration_number" />
            <text-input :label="$t('Tag Number / Plate Number')" v-model="form.tag_number" />
            <text-input v-if="isVehicleCategory" :label="$t('VIN / Chassis Number')" v-model="form.vin_chassis_number" />
            <text-input v-if="isVehicleCategory" :label="$t('Engine Number')" v-model="form.engine_number" />
            <auto-complete id="operating_personnel_type" :label="$t('Operating Personnel From')" v-model="form.operating_personnel_type" :suggestions="operatingPersonnelTypeOptions" />
            <auto-complete
              v-if="form.operating_personnel_type === 'Contact'"
              id="operating_personnel_contact_id"
              :label="$t('Operating Personnel')"
              v-model="form.operating_personnel_contact_id"
              :suggestions="operatingPersonnelContactOptions"
              clearable
            />
            <auto-complete
              v-else
              id="operating_personnel_staff_id"
              :label="$t('Operating Personnel')"
              v-model="form.operating_personnel_staff_id"
              :suggestions="staffOptions"
              clearable
            />
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-violet"><icons name="key" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Ownership') }}</h2>
              <p class="lg-card-sub">{{ $t('Ownership Type') }} / {{ $t('Owner') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <auto-complete id="ownership_type" :label="$t('Ownership Type')" v-model="form.ownership_type" :suggestions="ownershipTypeOptions" />
            <text-input v-if="form.ownership_type !== 'Company Owned'" :label="$t('Owner')" v-model="form.owner_name" />
            <template v-if="['Leased', 'Rented'].includes(form.ownership_type)">
              <text-input :label="$t('Contract Reference')" v-model="form.lease_contract_reference" />
              <date-picker :label="$t('Lease Start')" v-model="form.lease_start_date" />
              <date-picker :label="$t('Lease End')" v-model="form.lease_end_date" />
              <text-input type="number" :label="$t('Monthly Cost')" v-model="form.lease_monthly_cost" />
              <text-input type="number" :label="$t('Deposit')" v-model="form.lease_deposit" />
            </template>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Acquisition') }}</h2>
              <p class="lg-card-sub">{{ $t('Acquisition Date') }} / {{ $t('Supplier') }} / {{ $t('Acquisition Cost') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <date-picker :label="$t('Acquisition Date')" v-model="form.acquisition_date" />
            <auto-complete id="supplier_id" :label="$t('Supplier')" v-model="form.supplier_id" :suggestions="supplierOptions" clearable />
            <auto-complete id="warehouse_id" :label="$t('Project')" v-model="form.warehouse_id" :suggestions="warehouseOptions" clearable />
            <text-input :label="$t('Invoice Reference')" v-model="form.invoice_reference" />
            <text-input type="number" :label="$t('Acquisition Cost') + ' (' + $t('per item') + ')'" v-model="form.acquisition_cost" />
            <auto-complete id="currency" :label="$t('Currency')" v-model="form.currency" :suggestions="currencies" placeholder="TZS" freeform clearable />
            <div>
              <text-input type="number" :label="$t('Tax Amount') + ' (' + $t('per item') + ')'" v-model="form.tax_amount" />
              <p class="af-hint">{{ $t('Defaults to 15% of Acquisition Cost. Adjust if needed.') }}</p>
            </div>
            <text-input type="number" :label="$t('Transport Cost') + ' (' + $t('per item') + ')'" v-model="form.transport_cost" />
            <text-input type="number" :label="$t('Installation Cost') + ' (' + $t('per item') + ')'" v-model="form.installation_cost" />
            <text-input type="number" :label="$t('Other Capitalised Cost') + ' (' + $t('per item') + ')'" v-model="form.other_capitalised_cost" />
            <text-input :label="$t('Funding Source')" v-model="form.funding_source" />
          </div>
          <div v-if="isForeign" class="af-fx">
            <div class="af-fx__head"><icons name="refresh" class="w-4 h-4" /><b>{{ $t('Exchange rate') }}</b><span>{{ $t('required') }}</span></div>
            <div class="lg-fields af-line af-line--qty">
              <text-input type="number" step="any" min="0" :label="'1 ' + currencyCode + ' = ... TZS'" v-model="form.exchange_rate" :error="$page.props.errors.exchange_rate" />
            </div>
            <p class="af-hint">{{ $t('Assets are always saved in TZS. When you save, every amount on this form (cost, tax, transport, installation, other, residual value) is multiplied by this rate.') }}</p>
          </div>
          <p v-else-if="asset && asset.original_currency" class="af-fx-note"><icons name="info" class="w-4 h-4" />{{ $t('Originally entered in') }} {{ asset.original_currency }} {{ $t('at a rate of') }} {{ Number(asset.exchange_rate).toLocaleString(undefined, { maximumFractionDigits: 6 }) }} ({{ Number(asset.original_acquisition_cost).toLocaleString(undefined, { minimumFractionDigits: 2 }) }} {{ asset.original_currency }} {{ $t('per item') }}). {{ $t('The amounts below are in TZS.') }}</p>

          <div class="af-subbox">
            <h3 class="af-subbox__title"><icons name="collection" class="w-4 h-4" />{{ $t('Quantity & Unit') }}</h3>
            <div class="lg-fields af-line af-line--qty">
              <text-input type="number" step="any" min="0" :label="$t('Quantity')" v-model="form.quantity" />
              <auto-complete id="asset_unit_id" :label="$t('Unit')" v-model="form.unit_id" :suggestions="unitOptions" clearable />
            </div>
            <p class="af-hint">{{ $t('How many pieces this asset record covers. Check-Out, Check-In and Transfer can move only some of them.') }}</p>
          </div>

          <div class="lg-note lg-note-amber mt-4">
            <icons name="info" class="w-4 h-4" />
            <span>{{ $t('The amounts you enter here are for ONE item. The total cost is calculated for you: Total Capitalised Cost (per item) x Quantity.') }}</span>
          </div>
          <div class="af-totals">
            <div><small>{{ $t('Total Capitalised Cost') }} ({{ $t('per item') }})</small><b>{{ isForeign ? currencyCode + ' ' + num(totalCapitalisedCost) : $money(totalCapitalisedCost) }}</b><em v-if="isForeign">&asymp; {{ $money(perItemTzs) }}</em><em v-else>{{ $t('Acquisition + Transport + Installation + Other, tax excluded') }}</em></div>
            <div class="af-times">&times;</div>
            <div><small>{{ $t('Quantity') }}</small><b>{{ qtyShown + (unitName ? ' ' + unitName : '') }}</b></div>
            <div class="af-times">=</div>
            <div class="af-total-main"><small>{{ $t('Asset(s) Total Amount') }}<template v-if="isForeign"> ({{ $t('saved in TZS') }})</template></small><b>{{ $money(assetTotalAmount) }}</b><em v-if="isForeign">{{ currencyCode }} {{ num(totalCapitalisedCost * qtyValue) }}</em></div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="adjustments" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Status & Condition') }}</h2>
              <p class="lg-card-sub">{{ $t('Status') }} / {{ $t('Condition') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <auto-complete id="status" :label="$t('Status')" v-model="form.status" :suggestions="statusOptions" />
            <auto-complete id="condition" :label="$t('Condition')" v-model="form.condition" :suggestions="conditionOptions" />
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="map-pin" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Location & Assignment') }}</h2>
              <p class="lg-card-sub">{{ $t('Current Location (Project / Warehouse)') }} / {{ $t('Assigned To (User)') }}</p>
            </div>
          </header>
          <!-- one straight line: every field sits on the same baseline, whatever its label does -->
          <div class="lg-fields af-line">
            <auto-complete id="current_warehouse_id" :label="$t('Current Location (Project / Warehouse)')" v-model="form.current_warehouse_id" :suggestions="warehouseOptions" clearable />
            <text-input :label="$t('Location Label')" v-model="form.current_location_label" />
            <auto-complete id="assigned_to_type" :label="$t('Assigned To Type')" v-model="form.assigned_to_type" :suggestions="assignedToTypeOptions" clearable />
            <auto-complete id="assigned_to_user_id" :label="$t('Assigned To (User)')" v-model="form.assigned_to_user_id" :suggestions="userOptions" clearable />
            <text-input :label="$t('Assigned To Label')" v-model="form.assigned_to_label" />
            <date-picker :label="$t('Assignment Date')" v-model="form.assignment_date" />
            <date-picker :label="$t('Expected Return Date')" v-model="form.expected_return_date" />
          </div>
          <p class="af-hint af-hints">
            <span><b>{{ $t('Location Label') }}:</b> {{ $t('Free-text location: used when no Project/Warehouse applies.') }}</span>
            <span><b>{{ $t('Assigned To Label') }}:</b> {{ $t('Free-text assignee: e.g. a department, project, or site name.') }}</span>
          </p>

          <p class="lg-note lg-note-blue mt-4">
            <icons name="info" class="w-4 h-4" />
            <span>{{ $t('Saving a change here is recorded in the asset\'s Movement History, same as Check-Out/Check-In/Transfer.') }}</span>
          </p>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-rose"><icons name="chart-bar" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Depreciation') }}</h2>
              <p class="lg-card-sub">{{ $t('Method') }} / {{ $t('Frequency') }} / {{ $t('Residual Value') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <auto-complete id="depreciation_method" :label="$t('Method')" v-model="form.depreciation_method" :suggestions="depreciationMethodOptions" />
            <auto-complete id="depreciation_frequency" :label="$t('Frequency')" v-model="form.depreciation_frequency" :suggestions="depreciationFrequencyOptions" />
            <text-input type="number" :label="$t('Useful Life (years)') " v-model="form.useful_life_years" />
            <text-input type="number" :label="$t('Residual Value')" v-model="form.residual_value" />
            <date-picker :label="$t('Depreciation Start Date')" v-model="form.depreciation_start_date" />
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Warranty') }}</h2>
              <p class="lg-card-sub">{{ $t('Warranty Provider') }} / {{ $t('Warranty End') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <text-input :label="$t('Warranty Provider')" v-model="form.warranty_provider" />
            <text-input :label="$t('Warranty Reference')" v-model="form.warranty_reference" />
            <date-picker :label="$t('Warranty Start')" v-model="form.warranty_start" />
            <date-picker :label="$t('Warranty End')" v-model="form.warranty_end" />
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="lock-closed" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Insurance') }}</h2>
              <p class="lg-card-sub">{{ $t('Insurance Provider') }} / {{ $t('Policy Number') }} / {{ $t('Insurance Expiry') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <auto-complete id="insurance_provider" :label="$t('Insurance Provider')" v-model="form.insurance_provider" :suggestions="insuranceProviders" freeform clearable />
            <text-input :label="$t('Policy Number')" v-model="form.insurance_policy_number" />
            <date-picker :label="$t('Insurance Start')" v-model="form.insurance_start" />
            <date-picker :label="$t('Insurance Expiry')" v-model="form.insurance_expiry" />
            <text-input type="number" :label="$t('Insured Value')" v-model="form.insurance_insured_value" />
            <text-input type="number" :label="$t('Premium')" v-model="form.insurance_premium" />
          </div>
        </section>

        <section class="lg-card" v-if="isVehicleCategory">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="truck" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Vehicle / Machinery Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Fuel Type') }} / {{ $t('Current Odometer') }} / {{ $t('Next Service Date') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <text-input :label="$t('Fuel Type')" v-model="form.fuel_type" />
            <text-input type="number" :label="$t('Fuel Capacity')" v-model="form.fuel_capacity" />
            <text-input type="number" :label="$t('Current Meter')" v-model="form.current_meter" />
            <text-input type="number" :label="$t('Current Odometer')" v-model="form.current_odometer" />
            <text-input type="number" :label="$t('Operating Hours')" v-model="form.operating_hours" />
            <auto-complete id="service_interval_unit" :label="$t('Service Interval Unit')" v-model="form.service_interval_unit" :suggestions="serviceIntervalUnitOptions" clearable />
            <text-input type="number" :label="$t('Service Interval Value')" v-model="form.service_interval_value" />
            <date-picker :label="$t('Next Service Date')" v-model="form.next_service_date" />
            <date-picker :label="$t('Registration Expiry')" v-model="form.registration_expiry" />
            <date-picker :label="$t('Inspection Expiry')" v-model="form.inspection_expiry" />
            <date-picker :label="$t('License Expiry')" v-model="form.license_expiry" />
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-slate"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
              <p class="lg-card-sub">{{ $t('Invoices, warranty cards, registration documents') }}</p>
            </div>
          </header>
          <attachment-picker v-model="selectedFiles" :max-files="10" :help-text="$t('Photos, invoices, warranty cards, registration documents: PDF, DOC, DOCX, JPG, JPEG, or PNG, up to 10 files.')" />
          <ul v-if="asset && asset.attachments && asset.attachments.length" class="af-files">
            <li v-for="file in asset.attachments" :key="file.id" class="af-file">
              <span class="af-file-name">{{ file.title }}</span>
              <div class="flex items-center gap-1 shrink-0">
                <a
                  :href="file.preview_url"
                  target="_blank"
                  class="ast-att-icon-btn lg-tip"
                  :data-tip="$t('Preview')"
                  @mouseenter="showAttachmentZoom($event, file)"
                  @mouseleave="hidePhotoZoom"
                >
                  <icons name="eye-flat" class="w-4 h-4 flex-none" />
                </a>
                <a :href="file.download_url" class="ast-att-icon-btn lg-tip" :data-tip="$t('Download')">
                  <icons name="download" class="w-4 h-4 flex-none" />
                </a>
              </div>
            </li>
          </ul>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-slate"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Notes') }}</h2>
              <p class="lg-card-sub">{{ $t('Anything else worth recording about this asset') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div class="lg-span-all">
              <textarea v-model="form.notes" rows="3" class="w-full rounded-md border-gray-300"></textarea>
            </div>
          </div>
        </section>

        <div class="lg-actionbar">
          <span v-if="errorCount" class="lg-actionbar-note af-err-note">{{ $t('Please fix the highlighted fields.') }}</span>
          <div v-else></div>
          <div class="lg-actionbar-btns">
            <Link :href="route('assets.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" class="lg-btn lg-btn-primary" :disabled="processing">
              <icons :name="processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': processing }" /> {{ asset ? $t('Save Changes') : $t('Register Asset') }}
            </button>
          </div>
        </div>
      </form>
    </div>

    <teleport to="body">
      <div v-if="photoZoom.show" class="ast-photo-zoom ast-photo-zoom-show" :style="{ left: photoZoom.x + 'px', top: photoZoom.y + 'px' }">
        <img v-if="photoZoom.isImage" :src="photoZoom.src" alt="" />
        <div v-else class="ast-photo-zoom-file">
          <icons name="doc" class="w-8 h-8" />
          <span>{{ $t('No preview available') }}</span>
        </div>
        <div v-if="photoZoom.name" class="ast-photo-zoom-name">{{ photoZoom.name }}</div>
      </div>
    </teleport>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import TextInput from '@/Shared/TextInput.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AttachmentPicker from '@/Shared/AttachmentPicker.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import PhotoGallery from '@/Shared/Page/PhotoGallery.vue';

const MAX_PHOTOS = 3;
const MAX_PHOTO_BYTES = 5 * 1024 * 1024;

export default {
  components: { AdminLayout, AutoComplete, TextInput, DatePicker, AttachmentPicker, PageHeader, PhotoGallery },

  props: {
    asset: Object,
    categories: Array,
    warehouses: Array,
    suppliers: Array,
    staffs: Array,
    statuses: Array,
    conditions: Array,
    ownershipTypes: Array,
    assignedToTypes: Array,
    assetTypes: Array,
    currencies: Array,
    users: Array,
    units: { type: Array, default: () => [] },
    defaultUnitId: { type: Number, default: null },
    operatingPersonnelContacts: Array,
    operatingPersonnelTypes: Array,
    insuranceProviders: Array,
  },

  data() {
    const a = this.asset || {};
    const initialAcquisitionCost = Number(a.acquisition_cost) || 0;
    const initialTax = a.tax_amount !== undefined && a.tax_amount !== null && a.tax_amount !== ''
      ? Number(a.tax_amount)
      : Math.round(initialAcquisitionCost * 0.15 * 100) / 100;
    return {
      processing: false,
      legacyType: (this.asset && this.asset.asset_type) || null,
      selectedFiles: [],
      maxPhotos: MAX_PHOTOS,
      // New uploads: [{ key, file, url (object URL), name }]; existing photos
      // the user has taken out are kept as ids and deleted server-side on save.
      newPhotos: [],
      removedPhotoIds: [],
      photoError: null,
      // Tracks the last value we auto-computed as 15% of Acquisition Cost —
      // as long as Tax Amount still matches it, changing the cost keeps
      // recomputing it; once the user types a different value, it's theirs.
      lastAutoTax: initialTax,
      photoZoom: { show: false, src: null, name: '', isImage: true, x: 0, y: 0 },
      // Sensible per-group useful-life/frequency defaults applied when a
      // category is picked — a starting point the user can still edit.
      categoryDepreciationDefaults: {
        'Land & Buildings': { useful_life_years: 20, depreciation_frequency: 'Annually' },
        Vehicles: { useful_life_years: 5, depreciation_frequency: 'Annually' },
        'Construction Machinery': { useful_life_years: 8, depreciation_frequency: 'Annually' },
        'Tools & Equipment': { useful_life_years: 3, depreciation_frequency: 'Annually' },
        'IT & Electronics': { useful_life_years: 3, depreciation_frequency: 'Annually' },
        'Office Equipment': { useful_life_years: 5, depreciation_frequency: 'Annually' },
        'Furniture & Fixtures': { useful_life_years: 7, depreciation_frequency: 'Annually' },
        'Plant & Equipment': { useful_life_years: 8, depreciation_frequency: 'Annually' },
        'Electrical Equipment': { useful_life_years: 5, depreciation_frequency: 'Annually' },
        'Communication Equipment': { useful_life_years: 3, depreciation_frequency: 'Annually' },
        'Other Assets': { useful_life_years: 5, depreciation_frequency: 'Annually' },
      },
      form: {
        name: a.name || '',
        asset_category_id: a.asset_category_id || null,
        asset_type: a.asset_type || '',
        description: a.description || '',
        manufacturer: a.manufacturer || '',
        model: a.model || '',
        serial_number: a.serial_number || '',
        registration_number: a.registration_number || '',
        vin_chassis_number: a.vin_chassis_number || '',
        engine_number: a.engine_number || '',
        tag_number: a.tag_number || '',
        operating_personnel_type: a.operating_personnel_type || 'Staff',
        operating_personnel_staff_id: a.operating_personnel_staff_id || null,
        operating_personnel_contact_id: a.operating_personnel_contact_id || null,

        ownership_type: a.ownership_type || 'Company Owned',
        owner_name: a.owner_name || '',
        lease_contract_reference: a.lease_contract_reference || '',
        lease_start_date: a.lease_start_date || '',
        lease_end_date: a.lease_end_date || '',
        lease_monthly_cost: a.lease_monthly_cost || '',
        lease_deposit: a.lease_deposit || '',

        acquisition_date: a.acquisition_date || this.$formatJSDate(new Date()),
        supplier_id: a.supplier_id || null,
        warehouse_id: a.warehouse_id || null,
        invoice_reference: a.invoice_reference || '',
        acquisition_cost: a.acquisition_cost || 0,
        currency: a.currency || 'TZS',
        exchange_rate: '',
        tax_amount: initialTax,
        transport_cost: a.transport_cost || 0,
        installation_cost: a.installation_cost || 0,
        other_capitalised_cost: a.other_capitalised_cost || 0,
        funding_source: a.funding_source || '',

        status: a.status || 'Available',
        condition: a.condition || 'New',

        current_warehouse_id: a.current_warehouse_id || null,
        current_location_label: a.current_location_label || '',
        quantity: a.quantity ?? 1,
        unit_id: a.unit_id || this.defaultUnitId || null,
        assigned_to_type: a.assigned_to_type || null,
        assigned_to_user_id: a.assigned_to_user_id || null,
        assigned_to_label: a.assigned_to_label || '',
        assignment_date: a.assignment_date || '',
        expected_return_date: a.expected_return_date || '',

        depreciation_method: a.depreciation_method || 'Straight-Line Method',
        depreciation_frequency: a.depreciation_frequency || 'Annually',
        useful_life_years: a.useful_life_years || '',
        residual_value: a.residual_value || 0,
        depreciation_start_date: a.depreciation_start_date || '',

        warranty_provider: a.warranty_provider || '',
        warranty_reference: a.warranty_reference || '',
        // Defaults to today → +2 years on a new asset, since most warranties
        // run from the purchase date; the user can still change either date.
        warranty_start: a.warranty_start || this.$formatJSDate(new Date()),
        warranty_end: a.warranty_end || this.$formatJSDate(new Date(new Date().setFullYear(new Date().getFullYear() + 2))),

        insurance_provider: a.insurance_provider || '',
        insurance_policy_number: a.insurance_policy_number || '',
        insurance_start: a.insurance_start || '',
        insurance_expiry: a.insurance_expiry || '',
        insurance_insured_value: a.insurance_insured_value || '',
        insurance_premium: a.insurance_premium || '',

        fuel_type: a.fuel_type || '',
        fuel_capacity: a.fuel_capacity || '',
        current_meter: a.current_meter || '',
        current_odometer: a.current_odometer || '',
        operating_hours: a.operating_hours || '',
        service_interval_value: a.service_interval_value || '',
        service_interval_unit: a.service_interval_unit || null,
        next_service_date: a.next_service_date || '',
        registration_expiry: a.registration_expiry || '',
        inspection_expiry: a.inspection_expiry || '',
        license_expiry: a.license_expiry || '',

        notes: a.notes || '',
      },
    };
  },

  computed: {
    categoryOptions() {
      return this.categories.map(c => ({ label: c.parent_id ? '   ' + c.name : c.name, value: c.id }));
    },
    unitOptions() {
      return this.units || [];
    },
    warehouseOptions() {
      return this.warehouses.map(w => ({ label: w.name, value: w.id }));
    },
    supplierOptions() {
      return this.suppliers.map(s => ({ label: s.name, value: s.id }));
    },
    staffOptions() {
      return this.staffs.map(s => ({ label: s.name, value: s.id }));
    },
    userOptions() {
      return this.users.map(u => ({ label: u.name, value: u.id }));
    },
    operatingPersonnelContactOptions() {
      return this.operatingPersonnelContacts.map(c => ({ label: c.name + (c.category ? ` (${c.category})` : ''), value: c.id }));
    },
    operatingPersonnelTypeOptions() {
      return (this.operatingPersonnelTypes || ['Staff', 'Contact']).map(t => ({ label: this.$t(t), value: t }));
    },
    ownershipTypeOptions() {
      return this.ownershipTypes.map(o => ({ label: this.$t(o), value: o }));
    },
    statusOptions() {
      return this.statuses.map(s => ({ label: this.$t(s), value: s }));
    },
    conditionOptions() {
      return this.conditions.map(c => ({ label: this.$t(c), value: c }));
    },
    assignedToTypeOptions() {
      return this.assignedToTypes.map(t => ({ label: this.$t(t), value: t }));
    },
    depreciationMethodOptions() {
      return [
        'Straight-Line Method', 'Declining Balance Method', 'Double Declining Balance Method',
        "Sum-of-the-Years'-Digits Method", 'Units of Production Method', 'Reducing Balance Method',
        'Annuity Method', 'Straight-Line with Residual Value', 'Custom Method',
      ].map(m => ({ label: this.$t(m), value: m }));
    },
    depreciationFrequencyOptions() {
      return ['Monthly', 'Quarterly', 'Annually'].map(f => ({ label: this.$t(f), value: f }));
    },
    serviceIntervalUnitOptions() {
      return [
        { label: this.$t('Kilometres'), value: 'km' },
        { label: this.$t('Hours'), value: 'hours' },
        { label: this.$t('Months'), value: 'months' },
      ];
    },
    currencyCode() {
      return String(this.form.currency || '').trim().toUpperCase();
    },
    isForeign() {
      return this.currencyCode !== '' && !['TZS', 'TSH'].includes(this.currencyCode);
    },
    rateValue() {
      const r = Number(this.form.exchange_rate);
      return this.isForeign && r > 0 ? r : 1;
    },
    perItemTzs() {
      return Math.round(this.totalCapitalisedCost * this.rateValue * 100) / 100;
    },
    qtyValue() {
      const q = Number(this.form.quantity);
      return q > 0 ? q : 1;
    },
    qtyShown() {
      return Number.isInteger(this.qtyValue) ? this.qtyValue : Number(this.qtyValue.toFixed(3));
    },
    unitName() {
      const u = (this.unitOptions || []).find(x => x.value === this.form.unit_id);
      return u ? String(u.label).replace(/\s*\([^)]*\)\s*$/, '') : '';
    },
    assetTotalAmount() {
      return Math.round(this.totalCapitalisedCost * this.rateValue * this.qtyValue * 100) / 100;
    },
    totalCapitalisedCost() {
      return (Number(this.form.acquisition_cost) || 0)
        + (Number(this.form.transport_cost) || 0)
        + (Number(this.form.installation_cost) || 0)
        + (Number(this.form.other_capitalised_cost) || 0);
    },
    selectedCategory() {
      // eslint-disable-next-line eqeqeq
      return this.categories.find(c => c.id == this.form.asset_category_id) || null;
    },
    categoryGroupName() {
      if (!this.selectedCategory) return '';
      // eslint-disable-next-line eqeqeq
      const parent = this.selectedCategory.parent_id ? this.categories.find(c => c.id == this.selectedCategory.parent_id) : this.selectedCategory;
      return parent ? parent.name : '';
    },
    isVehicleCategory() {
      return ['Vehicles', 'Construction Machinery', 'Plant & Equipment'].includes(this.categoryGroupName);
    },
    assetTypeOptions() {
      const list = (this.assetTypes && this.assetTypes.length ? this.assetTypes : ['Fixed Asset', 'UnFixed Asset']).map(t => ({ label: this.$t(t), value: t }));
      // An asset saved before the list was reduced to two keeps its old value
      // as an extra option so the form can still show it and save it.
      if (this.isLegacyType) {
        list.push({ label: `${this.legacyType} (${this.$t('Legacy')})`, value: this.legacyType });
      }
      return list;
    },
    isLegacyType() {
      const t = this.legacyType;
      return !!t && !(this.assetTypes || ['Fixed Asset', 'UnFixed Asset']).includes(t);
    },
    existingPhotos() {
      return ((this.asset && this.asset.photos) || []).filter(p => !this.removedPhotoIds.includes(p.id));
    },
    galleryPhotos() {
      return [
        ...this.existingPhotos.map((p, i) => ({ key: 'e' + (p.id || 'legacy' + i), id: p.id, url: p.url, kind: 'existing', removable: p.id !== null && p.id !== undefined })),
        ...this.newPhotos.map(p => ({ key: p.key, url: p.url, name: p.name, kind: 'new' })),
      ];
    },
    errorCount() {
      return Object.keys(this.$page.props.errors || {}).length;
    },
  },

  watch: {
    'form.acquisition_cost'(cost) {
      if (Number(this.form.tax_amount) === Number(this.lastAutoTax)) {
        const computed = Math.round((Number(cost) || 0) * 0.15 * 100) / 100;
        this.form.tax_amount = computed;
        this.lastAutoTax = computed;
      }
    },
    'form.asset_category_id'() {
      const defaults = this.categoryDepreciationDefaults[this.categoryGroupName];
      if (defaults) {
        this.form.useful_life_years = defaults.useful_life_years;
        this.form.depreciation_frequency = defaults.depreciation_frequency;
      }
    },
    // Clears the other picker's leftover selection the moment the type is
    // switched — the server also normalizes this on save (see
    // AssetController::normalizeOperatingPersonnel()), but doing it here too
    // keeps the UI itself from showing a stale Staff pick after switching to
    // Contact (or vice versa) and back, before ever saving.
    'form.operating_personnel_type'(type) {
      if (type === 'Contact') {
        this.form.operating_personnel_staff_id = null;
      } else {
        this.form.operating_personnel_contact_id = null;
      }
    },
  },

  beforeUnmount() {
    this.newPhotos.forEach(p => URL.revokeObjectURL(p.url));
  },

  methods: {
    num(n) {
      return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    onPhotosChosen(e) {
      const files = e.target.files ? Array.from(e.target.files) : [];
      e.target.value = '';
      this.photoError = null;
      for (const file of files) {
        if (this.galleryPhotos.length >= MAX_PHOTOS) {
          this.photoError = this.$t('You can attach up to {max} photos.', { max: MAX_PHOTOS });
          break;
        }
        if (!/^image\/(png|jpe?g|webp)$/i.test(file.type)) {
          this.photoError = this.$t('"{name}" is not a JPG, PNG or WEBP image.', { name: file.name });
          continue;
        }
        if (file.size > MAX_PHOTO_BYTES) {
          this.photoError = this.$t('"{name}" exceeds the maximum file size.', { name: file.name });
          continue;
        }
        this.newPhotos.push({ key: 'n' + Date.now() + '-' + this.newPhotos.length, file, url: URL.createObjectURL(file), name: file.name });
      }
    },
    removePhoto(photo) {
      this.photoError = null;
      if (photo.kind === 'new') {
        const idx = this.newPhotos.findIndex(p => p.key === photo.key);
        if (idx > -1) {
          URL.revokeObjectURL(this.newPhotos[idx].url);
          this.newPhotos.splice(idx, 1);
        }
      } else if (photo.id !== null && photo.id !== undefined) {
        this.removedPhotoIds.push(photo.id);
      }
    },
    undoPhotoRemovals() {
      this.photoError = null;
      const room = MAX_PHOTOS - this.galleryPhotos.length;
      if (room <= 0) {
        this.photoError = this.$t('You can attach up to {max} photos.', { max: MAX_PHOTOS });
        return;
      }
      this.removedPhotoIds.splice(0, room);
    },
    showAttachmentZoom(event, file) {
      const rect = event.currentTarget.getBoundingClientRect();
      this.photoZoom = { show: true, src: file.preview_url, name: file.title, isImage: file.is_image, x: rect.right + 14, y: rect.top + rect.height / 2 };
    },
    hidePhotoZoom() {
      this.photoZoom.show = false;
    },
    submit() {
      this.processing = true;
      const payload = {
        ...this.form,
        photos: this.newPhotos.map(p => p.file),
        remove_photo_ids: this.removedPhotoIds,
        attachments: this.selectedFiles,
        ...(this.asset ? { _method: 'put' } : {}),
      };
      const url = this.asset ? this.route('assets.update', this.asset.id) : this.route('assets.store');
      this.$inertia.post(url, payload, {
        forceFormData: true,
        onFinish: () => (this.processing = false),
      });
    },
  },
};
</script>

<style scoped>
.af-form :deep(.lg-fields label:not(:has(input[type='checkbox']))),
.af-label {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
}
.af-label { margin-bottom: 0.25rem; }
.af-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file'])),
.af-form :deep(.lg-fields select),
.af-form :deep(.lg-fields textarea) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  padding-top: 0.6rem !important;
  padding-bottom: 0.6rem !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.af-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file']):hover),
.af-form :deep(.lg-fields select:hover) {
  border-color: rgba(56, 189, 248, 0.7) !important;
}
.af-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file']):focus),
.af-form :deep(.lg-fields select:focus),
.af-form :deep(.lg-fields textarea:focus) {
  border-color: #38bdf8 !important;
  box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2) !important;
}

.af-line { display: grid; gap: 1rem 1.25rem; align-items: start; grid-template-columns: repeat(4, minmax(0, 1fr)); } .af-line :deep(label:not(:has(input[type='checkbox']))) { display: flex; align-items: flex-end; min-height: 2.5em; line-height: 1.25; }
@media (max-width: 1100px) { .af-line { grid-template-columns: repeat(3, minmax(0, 1fr)); } } @media (max-width: 760px) { .af-line { grid-template-columns: repeat(2, minmax(0, 1fr)); } } @media (max-width: 520px) { .af-line { grid-template-columns: 1fr; } } .af-line > * { min-width: 0; } .af-line :deep(.relative), .af-line :deep(.mb-2), .af-line :deep(.mb-4), .af-line :deep(.mb-6) { margin-bottom: 0 !important; }
.af-line--qty { grid-template-columns: repeat(2, minmax(0, 320px)); } @media (max-width: 760px) { .af-line--qty { grid-template-columns: 1fr; } }
.af-hints { display: grid; gap: 0.2rem; margin-top: 0.7rem; } .af-hints b { font-weight: 700; color: var(--lg-text); }
.af-subbox { margin-top: 1.1rem; padding: 0.9rem 1rem 1rem; border-radius: 16px; background: var(--lg-soft, #f8fafc); box-shadow: inset 0 0 0 1px var(--lg-line, #e2e8f0); } .af-subbox__title { display: flex; align-items: center; gap: 0.45rem; margin: 0 0 0.7rem; font-size: 0.78rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-mute); }
.af-totals { display: flex; align-items: stretch; flex-wrap: wrap; gap: 0.6rem; margin-top: 0.8rem; } .af-totals > div { padding: 0.6rem 0.9rem; border-radius: 14px; background: var(--lg-soft, #f8fafc); box-shadow: inset 0 0 0 1px var(--lg-line, #e2e8f0); } .af-totals small { display: block; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute); } .af-totals b { font-size: 1.05rem; font-variant-numeric: tabular-nums; } .af-totals em { display: block; font-size: 0.68rem; font-style: normal; color: var(--lg-mute); }
.af-totals .af-times { display: grid; place-items: center; padding: 0 0.3rem; background: none; box-shadow: none; font-size: 1.3rem; font-weight: 800; color: var(--lg-mute); } .af-totals .af-total-main { background: #ecfdf5; box-shadow: inset 0 0 0 1.5px #10b981; } .af-totals .af-total-main b { color: #047857; font-size: 1.2rem; }
.af-fx { margin-top: 1.1rem; padding: 0.8rem 1rem 0.9rem; border-radius: 16px; background: #fffbeb; box-shadow: inset 0 0 0 1.5px #fcd34d; } .af-fx__head { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.6rem; color: #92400e; } .af-fx__head span { padding: 0 8px; border-radius: 99px; font-size: 0.68rem; font-weight: 800; color: #fff; background: #d97706; }
.af-fx-note { display: flex; align-items: center; gap: 0.5rem; margin: 1rem 0 0; font-size: 0.8rem; color: #1e3a8a; }
.af-hint { margin-top: 0.3rem; font-size: 0.74rem; color: var(--lg-mute); }
.af-total-sub { margin-left: 0.4rem; font-size: 0.72rem; opacity: 0.8; }
.af-err-note { color: #be123c; }
html.dark .af-err-note { color: #fda4af; }

/* photos */
.af-photo-count { margin-left: auto; font-variant-numeric: tabular-nums; }
.af-photo-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 1rem; }

/* attachments list */
.af-files { margin-top: 0.9rem; border: 1px solid var(--lg-line); border-radius: 16px; background: var(--lg-soft); }
.af-file { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 0.55rem 0.8rem; font-size: 0.84rem; color: var(--lg-ink); }
.af-file + .af-file { border-top: 1px solid var(--lg-line); }
.af-file-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--lg-mute); }

.ast-att-icon-btn {
  display: inline-flex; align-items: center; justify-content: center; width: 1.85rem; height: 1.85rem; flex-shrink: 0;
  color: var(--lg-mute); background: var(--lg-card); border: 1px solid var(--lg-line); border-radius: 0.5rem; transition: background 0.15s ease, color 0.15s ease, transform 0.15s ease;
}
.ast-att-icon-btn:hover { background: var(--lg-soft); color: var(--lg-ink); transform: translateY(-1px); }

.ast-photo-zoom {
  position: fixed; transform: translate(0, -50%) scale(0.85); z-index: 9999;
  background: #fff; border-radius: 1rem; padding: 0.6rem; box-shadow: 0 20px 40px -10px rgba(15, 23, 42, 0.35), 0 0 0 1px rgba(15, 23, 42, 0.05);
  opacity: 0; pointer-events: none; transition: opacity 0.16s ease, transform 0.16s ease; max-width: 220px;
}
.ast-photo-zoom-show { opacity: 1; transform: translate(0, -50%) scale(1); }
.ast-photo-zoom img { display: block; width: 200px; max-height: 260px; object-fit: contain; border-radius: 0.7rem; background: #f1f5f9; }
.ast-photo-zoom-file {
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.4rem; width: 200px; height: 140px;
  background: #f1f5f9; color: #94a3b8; border-radius: 0.7rem; font-size: 0.72rem; text-align: center; padding: 0.5rem;
}
.ast-photo-zoom-name { text-align: center; font-size: 0.75rem; font-weight: 700; color: #374151; margin-top: 0.5rem; word-break: break-word; }

html.dark .ast-photo-zoom { background: #1e293b; box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.08); }
html.dark .ast-photo-zoom-name { color: #e2e8f0; }
</style>

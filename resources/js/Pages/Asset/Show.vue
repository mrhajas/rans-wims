<template>
  <admin-layout :title="asset.name">
    <div class="lg-page px-4 md:px-0 as-show">
      <header class="lg-hero print:hidden">
        <photo-gallery v-if="asset.photos && asset.photos.length" class="ast-hero-gallery" :photos="asset.photos" :size="56" :zoom="200" />
        <span v-else class="lg-hero-icon"><icons name="chip" class="w-6 h-6" /></span>
        <div class="lg-hero-body">
          <h1 class="lg-title">{{ asset.name }}</h1>
          <p class="lg-sub">{{ asset.asset_number }} <span v-if="asset.category">&middot; {{ asset.category.name }}</span></p>
          <div class="as-chips">
            <span class="lg-chip" :class="statusBadgeClass(asset.status)" style="text-transform: none">{{ asset.status }}</span>
            <span class="lg-chip lg-chip-slate" style="text-transform: none">{{ asset.condition }}</span>
            <Link v-if="convertedItem" :href="route('items.edit', convertedItem.id)" class="lg-chip lg-chip-green as-conv-chip" style="text-transform: none"><icons name="tick" class="w-3 h-3" />{{ $t('Converted to item') }} {{ convertedItem.code }}</Link>
          </div>
        </div>
        <div class="lg-hero-actions">
          <a v-if="$can('print-assets')" :href="route('assets.print', asset.id) + '?tab=' + activeTab" target="_blank" class="lg-btn lg-btn-green lg-tip" :data-tip="$t('Print this tab') + ': ' + activeTabLabel"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
          <a v-if="$can('print-assets')" :href="route('assets.pdf', asset.id) + '?tab=' + activeTab" class="lg-btn lg-btn-dark lg-tip" :data-tip="$t('Download this tab as PDF') + ': ' + activeTabLabel"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</a>
          <Link v-if="$can('update-assets')" :href="route('assets.edit', asset.id)" class="lg-btn lg-btn-soft"><icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}</Link>
          <button v-if="$can('check-out-assets') && !isDisposed" type="button" class="lg-btn lg-btn-primary" @click="openModal('checkOut')"><icons name="upload" class="w-4 h-4" />{{ $t('Check Out') }}</button>
          <button v-if="$can('check-in-assets') && !isDisposed" type="button" class="lg-btn lg-btn-primary" @click="openModal('checkIn')"><icons name="download" class="w-4 h-4" />{{ $t('Check In') }}</button>
          <button v-if="$can('transfer-assets') && !isDisposed" type="button" class="lg-btn lg-btn-primary" @click="openModal('transfer')"><icons name="truck" class="w-4 h-4" />{{ $t('Transfer') }}</button>
          <button v-if="convert" type="button" class="lg-btn lg-btn-soft as-convert-btn" @click="openConvert"><icons name="refresh" class="w-4 h-4" />{{ $t('Convert to Item') }}</button>
          <Link v-if="$can('dispose-assets') && !isDisposed" :href="route('assets.dispose.create', asset.id)" class="lg-btn lg-btn-red"><icons name="trash" class="w-4 h-4" />{{ $t('Dispose') }}</Link>
        </div>
      </header>

      <div class="as-tabs">
        <button v-for="tab in visibleTabs" :key="tab.key" type="button" class="as-tab" :class="{ 'is-active': activeTab === tab.key }" @click="activeTab = tab.key">
          <icons :name="tabIcon(tab.key)" class="w-4 h-4" />{{ $t(tab.label) }}
        </button>
      </div>

      <!-- Overview / Details -->
      <div v-if="activeTab === 'overview'" class="as-grid">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="hash" class="w-5 h-5" /></span>
            <div class="min-w-0"><h3 class="lg-card-title">{{ $t('Identification') }}</h3></div>
          </header>
          <dl class="as-dl">
            <div><dt>{{ $t('Asset Type') }}</dt><dd>{{ asset.asset_type ? $t(asset.asset_type) : '-' }}</dd></div>
            <div><dt>{{ $t('Manufacturer') }}</dt><dd>{{ asset.manufacturer || '-' }}</dd></div>
            <div><dt>{{ $t('Model') }}</dt><dd>{{ asset.model || '-' }}</dd></div>
            <div><dt>{{ $t('Serial Number') }}</dt><dd>{{ asset.serial_number || '-' }}</dd></div>
            <div><dt>{{ $t('Quantity') }}</dt><dd>{{ asset.quantity != null ? qtyText(asset.quantity) : '-' }}{{ asset.unit_name ? ' ' + asset.unit_name : '' }}</dd></div>
            <div><dt>{{ $t('Registration Number') }}</dt><dd>{{ asset.registration_number || '-' }}</dd></div>
            <div><dt>{{ $t('Tag Number / Plate Number') }}</dt><dd>{{ asset.tag_number || '-' }}</dd></div>
            <div><dt>{{ $t('Date Registered') }}</dt><dd>{{ asset.created_at ? $datetime(asset.created_at) : '-' }}</dd></div>
            <div><dt>{{ $t('Registered By') }}</dt><dd>{{ asset.created_by_name || '-' }}</dd></div>
          </dl>
        </section>
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="map-pin" class="w-5 h-5" /></span>
            <div class="min-w-0"><h3 class="lg-card-title">{{ $t('Location & Assignment') }}</h3></div>
          </header>
          <dl class="as-dl">
            <div><dt>{{ $t('Current Location') }}</dt><dd>{{ (asset.current_warehouse && asset.current_warehouse.name) || asset.current_location_label || '-' }}</dd></div>
            <div><dt>{{ $t('Assigned To') }}</dt><dd>{{ (asset.assigned_user && asset.assigned_user.name) || asset.assigned_to_label || '-' }}</dd></div>
            <div><dt>{{ $t('Assignment Date') }}</dt><dd>{{ asset.assignment_date ? $date(asset.assignment_date) : '-' }}</dd></div>
            <div><dt>{{ $t('Operating Personnel') }}</dt><dd>{{ (asset.operating_personnel && asset.operating_personnel.name) || '-' }}</dd></div>
          </dl>
        </section>
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
            <div class="min-w-0"><h3 class="lg-card-title">{{ $t('Acquisition') }}</h3></div>
          </header>
          <dl class="as-dl">
            <div><dt>{{ $t('Acquisition Date') }}</dt><dd>{{ asset.acquisition_date ? $date(asset.acquisition_date) : '-' }}</dd></div>
            <div><dt>{{ $t('Supplier') }}</dt><dd>{{ (asset.supplier && asset.supplier.name) || '-' }}</dd></div>
            <div><dt>{{ $t('Acquisition Cost') }} ({{ $t('per item') }})</dt><dd class="as-num">{{ $money(asset.acquisition_cost) }}</dd></div>
            <div v-if="asset.original_currency"><dt>{{ $t('Originally entered in') }}</dt><dd class="as-num">{{ asset.original_currency }} {{ Number(asset.original_acquisition_cost).toLocaleString(undefined, { minimumFractionDigits: 2 }) }} @ {{ Number(asset.exchange_rate).toLocaleString(undefined, { maximumFractionDigits: 6 }) }}</dd></div>
            <div><dt>{{ $t('Total Capitalised Cost') }} ({{ $t('per item') }})</dt><dd class="as-num">{{ $money(asset.cost_per_item) }}</dd></div>
            <div v-if="Number(asset.quantity) !== 1"><dt>{{ $t('Quantity') }}</dt><dd class="as-num">{{ qtyText(asset.quantity) }}{{ asset.unit_name ? ' ' + asset.unit_name : '' }}</dd></div>
            <div><dt>{{ $t('Asset(s) Total Amount') }}</dt><dd class="as-num as-total">{{ $money(asset.total_capitalised_cost) }}</dd></div>
            <div><dt>{{ $t('Current Book Value') }}</dt><dd class="as-num as-good">{{ $money(asset.current_book_value) }}</dd></div>
          </dl>
        </section>
        <section class="lg-card as-qty-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="collection" class="w-5 h-5" /></span>
            <div class="min-w-0"><h3 class="lg-card-title">{{ $t('Quantities') }}</h3></div>
          </header>
          <div class="as-qty-total">
            <div><small>{{ $t('All quantities') }}</small><b>{{ qtyText(asset.quantity) }} <span>{{ asset.unit_name }}</span></b></div>
            <div><small>{{ $t('In other places') }}</small><b>{{ qtyText(otherPlacesQty) }} <span>{{ asset.unit_name }}</span></b></div>
          </div>
          <table class="as-qty-table">
            <thead><tr><th>{{ $t('Place') }}</th><th>{{ $t('Held by') }}</th><th class="num">{{ $t('Quantity') }}</th></tr></thead>
            <tbody>
              <tr v-for="p in asset.placements" :key="p.id" :class="{ main: p.is_main }">
                <td>{{ p.place }}<em v-if="p.is_main">{{ $t('Main place') }}</em></td>
                <td>{{ p.responsible_name || '-' }}</td>
                <td class="num">{{ qtyText(p.quantity) }}</td>
              </tr>
              <tr v-if="!(asset.placements || []).length"><td colspan="3" class="as-qty-empty">{{ $t('No quantity is recorded for this asset.') }}</td></tr>
            </tbody>
          </table>
        </section>
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="lock-closed" class="w-5 h-5" /></span>
            <div class="min-w-0"><h3 class="lg-card-title">{{ $t('Warranty & Insurance') }}</h3></div>
          </header>
          <dl class="as-dl">
            <div><dt>{{ $t('Warranty End') }}</dt><dd :class="{ 'as-warn': asset.warranty_expiring_soon }">{{ asset.warranty_end ? $date(asset.warranty_end) : '-' }}</dd></div>
            <div><dt>{{ $t('Insurance Expiry') }}</dt><dd :class="{ 'as-warn': asset.insurance_expiring_soon }">{{ asset.insurance_expiry ? $date(asset.insurance_expiry) : '-' }}</dd></div>
            <div><dt>{{ $t('Insurance Provider') }}</dt><dd>{{ asset.insurance_provider || '-' }}</dd></div>
          </dl>
        </section>
      </div>

      <!-- Movement History -->
      <div v-if="activeTab === 'movements'">
        <div class="lg-table-wrap -mx-4 md:mx-0">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Date') }}</th>
                <th>{{ $t('Movement') }}</th>
                <th class="num">{{ $t('Quantity') }}</th>
                <th>{{ $t('From') }}</th>
                <th>{{ $t('To') }}</th>
                <th>{{ $t('Condition') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="m in asset.movements" :key="m.id">
                <td :data-label="$t('Date')">{{ $date(m.movement_date) }}</td>
                <td :data-label="$t('Movement')"><span class="lg-chip lg-chip-blue" style="text-transform: none">{{ m.movement_type }}</span></td>
                <td :data-label="$t('Quantity')" class="num as-mq">{{ qtyText(m.quantity) }}{{ asset.unit_name ? ' ' + asset.unit_name : '' }}</td>
                <td :data-label="$t('From')">{{ placeText(m.from_warehouse, m.from_label, m.from_responsible_user, m.from_responsible_label) }}</td>
                <td :data-label="$t('To')">{{ placeText(m.to_warehouse, m.to_label, m.to_responsible_user, m.to_responsible_label) }}</td>
                <td :data-label="$t('Condition')">{{ m.condition_after || m.condition_before || '-' }}</td>
              </tr>
              <tr v-if="!asset.movements.length" class="lg-empty-row"><td class="lg-empty" colspan="6">{{ $t('There is no data to display.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Maintenance -->
      <div v-if="activeTab === 'maintenance'">
        <div class="as-tab-actions">
          <button v-if="$can('record-asset-maintenance')" type="button" class="lg-btn lg-btn-primary" @click="openModal('maintenance')"><icons name="plus" class="w-4 h-4" />{{ $t('Add Maintenance') }}</button>
        </div>
        <div class="lg-table-wrap -mx-4 md:mx-0">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Date') }}</th>
                <th>{{ $t('Type') }}</th>
                <th>{{ $t('Provider') }}</th>
                <th class="num">{{ $t('Cost') }}</th>
                <th>{{ $t('Next Service') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="m in asset.maintenance_records" :key="m.id">
                <td :data-label="$t('Date')">{{ $date(m.maintenance_date) }}</td>
                <td :data-label="$t('Type')">{{ m.maintenance_type }}</td>
                <td :data-label="$t('Provider')">{{ m.service_provider || '-' }}</td>
                <td :data-label="$t('Cost')" class="num">{{ $money(m.cost) }}</td>
                <td :data-label="$t('Next Service')">{{ m.next_service_date ? $date(m.next_service_date) : '-' }}</td>
              </tr>
              <tr v-if="!asset.maintenance_records.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('There is no data to display.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Depreciation -->
      <div v-if="activeTab === 'depreciation'">
        <div class="as-tab-actions">
          <button v-if="$can('record-asset-depreciation')" type="button" class="lg-btn lg-btn-primary" @click="openModal('depreciation')"><icons name="plus" class="w-4 h-4" />{{ $t('Post Depreciation') }}</button>
        </div>
        <div class="lg-table-wrap -mx-4 md:mx-0">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Period') }}</th>
                <th class="num">{{ $t('Opening') }}</th>
                <th class="num">{{ $t('Depreciation') }}</th>
                <th class="num">{{ $t('Accumulated') }}</th>
                <th class="num">{{ $t('Closing') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="d in asset.depreciation_entries" :key="d.id">
                <td :data-label="$t('Period')">{{ $date(d.period_start) }} &ndash; {{ $date(d.period_end) }}</td>
                <td :data-label="$t('Opening')" class="num">{{ $money(d.opening_book_value) }}</td>
                <td :data-label="$t('Depreciation')" class="num">{{ $money(d.period_depreciation) }}</td>
                <td :data-label="$t('Accumulated')" class="num">{{ $money(d.accumulated_depreciation) }}</td>
                <td :data-label="$t('Closing')" class="num font-bold">{{ $money(d.closing_book_value) }}</td>
              </tr>
              <tr v-if="!asset.depreciation_entries.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('There is no data to display.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Appreciation -->
      <div v-if="activeTab === 'appreciation'">
        <div class="as-tab-actions">
          <button v-if="$can('record-asset-appreciation')" type="button" class="lg-btn lg-btn-primary" @click="openModal('appreciation')"><icons name="plus" class="w-4 h-4" />{{ $t('Post Appreciation') }}</button>
        </div>
        <div class="lg-table-wrap -mx-4 md:mx-0">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Period') }}</th>
                <th class="num">{{ $t('Opening') }}</th>
                <th class="num">{{ $t('Appreciation') }}</th>
                <th class="num">{{ $t('Accumulated') }}</th>
                <th class="num">{{ $t('Closing') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="d in asset.appreciation_entries" :key="d.id">
                <td :data-label="$t('Period')">{{ $date(d.period_start) }} &ndash; {{ $date(d.period_end) }}</td>
                <td :data-label="$t('Opening')" class="num">{{ $money(d.opening_value) }}</td>
                <td :data-label="$t('Appreciation')" class="num" :class="d.period_appreciation < 0 ? 'as-bad' : 'as-good'">{{ $money(d.period_appreciation) }}</td>
                <td :data-label="$t('Accumulated')" class="num">{{ $money(d.accumulated_appreciation) }}</td>
                <td :data-label="$t('Closing')" class="num font-bold">{{ $money(d.closing_value) }}</td>
              </tr>
              <tr v-if="!asset.appreciation_entries.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('There is no data to display.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Documents -->
      <div v-if="activeTab === 'documents'">
        <form class="lg-card as-upload" @submit.prevent="uploadDocuments">
          <label class="lg-btn lg-btn-soft as-upload-choose">
            <icons name="upload" class="w-4 h-4" />
            {{ pendingFiles.length ? pendingFiles.length + ' ' + $t('file(s) selected') : $t('Choose files') }}
            <input type="file" multiple @change="onFilesChosen" class="hidden" />
          </label>
          <button type="submit" class="lg-btn lg-btn-primary" :disabled="!pendingFiles.length"><icons name="upload" class="w-4 h-4" />{{ $t('Upload') }}</button>
        </form>
        <div class="lg-card as-doc-list">
          <div v-for="doc in asset.attachments" :key="doc.id" class="as-doc-row">
            <div class="as-doc-icon">
              <img v-if="doc.is_image" :src="doc.preview_url" alt="" @mouseenter="showAttachmentZoom($event, doc)" @mouseleave="hidePhotoZoom" />
              <icons v-else name="doc" class="w-5 h-5" />
            </div>
            <div class="min-w-0 flex-1">
              <div class="lg-main truncate">{{ doc.title }}</div>
              <div class="lg-subtext">{{ doc.group }}<span v-if="doc.filesize"> &middot; {{ formatBytes(doc.filesize) }}</span></div>
            </div>
            <div class="flex items-center gap-1.5 flex-none">
              <a :href="doc.preview_url" target="_blank" class="lg-icon-btn lg-tip" :data-tip="$t('Preview')" :aria-label="$t('Preview')"><icons name="eye-flat" class="w-4 h-4" /></a>
              <a :href="doc.download_url" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" class="w-4 h-4" /></a>
              <button v-if="$can('update-assets')" type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Delete')" :aria-label="$t('Delete')" @click="deleteAttachment(doc)"><icons name="trash" class="w-4 h-4" /></button>
            </div>
          </div>
          <div v-if="!asset.attachments.length" class="as-empty">{{ $t('No documents uploaded yet.') }}</div>
        </div>
      </div>

      <!-- Disposal -->
      <section v-if="activeTab === 'disposal' && asset.disposal" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-rose"><icons name="trash" class="w-5 h-5" /></span>
          <div class="min-w-0"><h3 class="lg-card-title">{{ $t('Disposal') }}</h3></div>
        </header>
        <dl class="as-dl">
          <div><dt>{{ $t('Method') }}</dt><dd>{{ asset.disposal.disposal_method }}</dd></div>
          <div><dt>{{ $t('Date') }}</dt><dd>{{ $date(asset.disposal.disposal_date) }}</dd></div>
          <div><dt>{{ $t('Disposal Value') }}</dt><dd class="as-num">{{ $money(asset.disposal.disposal_value) }}</dd></div>
          <div><dt>{{ $t('Final Book Value') }}</dt><dd class="as-num">{{ $money(asset.disposal.final_book_value) }}</dd></div>
          <div><dt>{{ $t('Gain / Loss') }}</dt><dd class="as-num">{{ $money(asset.disposal.gain_loss) }}</dd></div>
          <div><dt>{{ $t('Reason') }}</dt><dd>{{ asset.disposal.reason || '-' }}</dd></div>
        </dl>
      </section>

      <!-- Trails (audit log) -->
      <section v-if="activeTab === 'trails'" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0"><h3 class="lg-card-title">{{ $t('Trails') }}</h3></div>
        </header>
        <div v-if="!activity.length" class="as-empty">{{ $t('No history recorded yet.') }}</div>
        <ul v-else class="as-timeline">
          <li v-for="(entry, idx) in activity" :key="idx">
            <span class="as-tl-dot"></span>
            <div class="as-tl-body">
              <div class="as-tl-head">
                <span class="as-tl-desc">{{ entry.description }}</span>
                <span class="as-tl-time">{{ entry.created_at ? $datetime(entry.created_at) : '' }}</span>
              </div>
              <div class="as-tl-meta">{{ $t('By') }} {{ entry.causer }}</div>
            </div>
          </li>
        </ul>
      </section>
    </div>

    <!-- Convert to Item modal -->
    <modal v-if="convert" :show="modal === 'convert'" max-width="xl" @close="modal = null">
      <div class="lg-page as-modal as-conv">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="refresh" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Convert to Item') }}</h2>
            <p class="lg-card-sub">{{ asset.asset_number }} &middot; {{ asset.name }}</p>
          </div>
        </header>
        <p class="as-conv__lead">{{ $t('A new item is created in the Items list from this asset: its name, description, photo, unit and cost per unit are copied. No stock is added; receive stock for it through Check-in or an Adjustment.') }}</p>
        <div class="lg-fields as-fields">
          <text-input class="lg-span-all" :label="$t('Item name')" v-model="convertForm.name" :error="convertErrors.name" />
          <auto-complete class="lg-span-all" id="cv_category" :label="$t('Item category')" v-model="convertForm.category_id" :suggestions="convert.categories" :error="convertErrors.category_id" />
          <auto-complete class="lg-span-all" id="cv_unit" :label="$t('Unit')" v-model="convertForm.unit_id" :suggestions="convert.units" clearable />
        </div>

        <h3 class="as-conv__ask">{{ $t('What should happen to this asset record?') }} <em>{{ $t('Choose one to continue') }}</em></h3>
        <div class="as-conv__choices" role="radiogroup">
          <label class="as-conv__opt" :class="{ on: convertForm.after === 'keep' }">
            <input v-model="convertForm.after" type="radio" value="keep" />
            <span class="as-conv__ico is-keep"><icons name="collection" class="w-5 h-5" /></span>
            <span><b>{{ $t('Leave it') }}</b><small>{{ $t('The asset stays in the Asset Register and the new item is added to the Items list, so it is in both lists.') }}</small></span>
          </label>
          <label class="as-conv__opt" :class="{ on: convertForm.after === 'delete', disabled: !convert.can_delete }">
            <input v-model="convertForm.after" type="radio" value="delete" :disabled="!convert.can_delete" />
            <span class="as-conv__ico is-del"><icons name="trash" class="w-5 h-5" /></span>
            <span><b>{{ $t('Delete the asset') }}</b><small>{{ convert.can_delete ? $t('The asset record is deleted after the item is created. It then exists only as an item.') : $t('You do not have permission to delete assets.') }}</small></span>
          </label>
        </div>
        <p v-if="convertErrors.after" class="as-conv__err">{{ convertErrors.after }}</p>

        <div class="as-modal-foot">
          <button type="button" class="lg-btn lg-btn-soft" @click="modal = null">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" :disabled="!convertForm.after || !convertForm.category_id || !convertForm.name || converting" @click="submitConvert"><icons name="tick" class="w-4 h-4" />{{ $t('Convert to Item') }}</button>
        </div>
      </div>
    </modal>

    <!-- Check-Out modal -->
    <modal :show="modal === 'checkOut'" max-width="lg" @close="modal = null">
      <div class="lg-page as-modal">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="upload" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Check Out Asset') }}</h2>
            <p class="lg-card-sub">{{ asset.asset_number }} &middot; {{ asset.name }}</p>
          </div>
        </header>
        <div class="lg-fields as-fields">
          <asset-pieces :form="checkOutForm" :placements="asset.placements || []" :unit="asset.unit_name || ''" id="co" :errors="$page.props.errors || {}" />
          <auto-complete class="lg-span-all" id="co_to_warehouse_id" :label="$t('To Project / Warehouse')" v-model="checkOutForm.to_warehouse_id" :suggestions="warehouseOptionsForModal" clearable />
          <auto-complete class="lg-span-all" id="co_to_user" :label="$t('Responsible Person')" v-model="checkOutForm.to_responsible_user_id" :suggestions="userOptionsForModal" clearable />
          <text-input type="date" :label="$t('Date')" v-model="checkOutForm.movement_date" />
          <text-input :label="$t('Reason')" v-model="checkOutForm.reason" />
        </div>
        <div class="as-modal-foot">
          <button type="button" class="lg-btn lg-btn-soft" @click="modal = null">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" @click="submitCheckOut"><icons name="tick" class="w-4 h-4" />{{ $t('Check Out') }}</button>
        </div>
      </div>
    </modal>

    <!-- Check-In modal -->
    <modal :show="modal === 'checkIn'" max-width="lg" @close="modal = null">
      <div class="lg-page as-modal">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="download" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Check In Asset') }}</h2>
            <p class="lg-card-sub">{{ asset.asset_number }} &middot; {{ asset.name }}</p>
          </div>
        </header>
        <div class="lg-fields as-fields">
          <asset-pieces :form="checkInForm" :placements="asset.placements || []" :unit="asset.unit_name || ''" id="ci" :errors="$page.props.errors || {}" />
          <auto-complete class="lg-span-all" id="ci_to_warehouse_id" :label="$t('Return To')" v-model="checkInForm.to_warehouse_id" :suggestions="warehouseOptionsForModal" clearable />
          <text-input type="date" :label="$t('Date')" v-model="checkInForm.movement_date" />
          <div>
            <label class="as-label">{{ $t('Condition After') }}</label>
            <css-select v-model="checkInForm.condition_after" class="w-full rounded-md border-gray-300">
              <option v-for="c in conditionList" :key="c" :value="c">{{ $t(c) }}</option>
            </css-select>
          </div>
          <text-input class="lg-span-all" :label="$t('Damage Notes')" v-model="checkInForm.damage_notes" />
        </div>
        <div class="as-modal-foot">
          <button type="button" class="lg-btn lg-btn-soft" @click="modal = null">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" @click="submitCheckIn"><icons name="tick" class="w-4 h-4" />{{ $t('Check In') }}</button>
        </div>
      </div>
    </modal>

    <!-- Transfer modal -->
    <modal :show="modal === 'transfer'" max-width="lg" @close="modal = null">
      <div class="lg-page as-modal">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="truck" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Transfer Asset') }}</h2>
            <p class="lg-card-sub">{{ asset.asset_number }} &middot; {{ asset.name }}</p>
          </div>
        </header>
        <div class="lg-fields as-fields">
          <asset-pieces :form="transferForm" :placements="asset.placements || []" :unit="asset.unit_name || ''" id="tr" :errors="$page.props.errors || {}" />
          <auto-complete class="lg-span-all" id="tr_to_warehouse_id" :label="$t('To Project / Warehouse')" v-model="transferForm.to_warehouse_id" :suggestions="warehouseOptionsForModal" clearable />
          <auto-complete class="lg-span-all" id="tr_to_user" :label="$t('Responsible Person')" v-model="transferForm.to_responsible_user_id" :suggestions="userOptionsForModal" clearable />
          <text-input type="date" :label="$t('Date')" v-model="transferForm.movement_date" />
          <text-input :label="$t('Reason')" v-model="transferForm.reason" />
        </div>
        <div class="as-modal-foot">
          <button type="button" class="lg-btn lg-btn-soft" @click="modal = null">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" @click="submitTransfer"><icons name="tick" class="w-4 h-4" />{{ $t('Transfer') }}</button>
        </div>
      </div>
    </modal>

    <!-- Maintenance modal -->
    <modal :show="modal === 'maintenance'" max-width="lg" @close="modal = null">
      <div class="lg-page as-modal">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="cog" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Add Maintenance Record') }}</h2>
            <p class="lg-card-sub">{{ asset.asset_number }} &middot; {{ asset.name }}</p>
          </div>
        </header>
        <div class="lg-fields as-fields">
          <text-input type="date" :label="$t('Date')" v-model="maintenanceForm.maintenance_date" />
          <div>
            <label class="as-label">{{ $t('Type') }}</label>
            <css-select v-model="maintenanceForm.maintenance_type" class="w-full rounded-md border-gray-300">
              <option v-for="t in maintenanceTypes" :key="t" :value="t">{{ $t(t) }}</option>
            </css-select>
          </div>
          <text-input class="lg-span-all" :label="$t('Service Provider')" v-model="maintenanceForm.service_provider" />
          <text-input type="number" :label="$t('Cost')" v-model="maintenanceForm.cost" />
          <text-input type="date" :label="$t('Next Service Date')" v-model="maintenanceForm.next_service_date" />
        </div>
        <div class="as-modal-foot">
          <button type="button" class="lg-btn lg-btn-soft" @click="modal = null">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" @click="submitMaintenance"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button>
        </div>
      </div>
    </modal>

    <!-- Depreciation modal -->
    <modal :show="modal === 'depreciation'" max-width="lg" @close="modal = null">
      <div class="lg-page as-modal">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-rose"><icons name="chart-bar" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Post Depreciation') }}</h2>
            <p class="lg-card-sub">{{ asset.asset_number }} &middot; {{ asset.name }}</p>
          </div>
        </header>
        <div class="lg-fields as-fields">
          <text-input type="date" :label="$t('Period Start')" v-model="depreciationForm.period_start" />
          <text-input type="date" :label="$t('Period End')" v-model="depreciationForm.period_end" />
        </div>
        <div class="as-modal-foot">
          <button type="button" class="lg-btn lg-btn-soft" @click="modal = null">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" @click="submitDepreciation"><icons name="tick" class="w-4 h-4" />{{ $t('Post') }}</button>
        </div>
      </div>
    </modal>

    <!-- Appreciation modal -->
    <modal :show="modal === 'appreciation'" max-width="lg" @close="modal = null">
      <div class="lg-page as-modal">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="chart-pie" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Post Appreciation') }}</h2>
            <p class="lg-card-sub">{{ asset.asset_number }} &middot; {{ asset.name }}</p>
          </div>
        </header>
        <div class="lg-fields as-fields">
          <text-input type="date" :label="$t('Period Start')" v-model="appreciationForm.period_start" />
          <text-input type="date" :label="$t('Period End')" v-model="appreciationForm.period_end" />
          <text-input class="lg-span-all" type="number" :label="$t('New Appraised Value')" v-model="appreciationForm.new_value" />
        </div>
        <div class="as-modal-foot">
          <button type="button" class="lg-btn lg-btn-soft" @click="modal = null">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" @click="submitAppreciation"><icons name="tick" class="w-4 h-4" />{{ $t('Post') }}</button>
        </div>
      </div>
    </modal>

    <teleport to="body">
      <div v-if="photoZoom.show" class="ast-photo-zoom ast-photo-zoom-show" :style="{ left: photoZoom.x + 'px', top: photoZoom.y + 'px' }">
        <img :src="photoZoom.src" alt="" />
        <div v-if="photoZoom.name" class="ast-photo-zoom-name">{{ photoZoom.name }}</div>
      </div>
    </teleport>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import TextInput from '@/Shared/TextInput.vue';
import Modal from '@/Jetstream/Modal.vue';
import PhotoGallery from '@/Shared/Page/PhotoGallery.vue';
import AssetPieces from './AssetPieces.vue';

export default {
  components: { AdminLayout, AutoComplete, TextInput, Modal, PhotoGallery, AssetPieces },

  props: { asset: Object },

  data() {
    const today = this.$formatJSDate(new Date());
    return {
      activeTab: 'overview',
      modal: null,
      pendingFiles: [],
      photoZoom: { show: false, src: null, name: '', x: 0, y: 0 },
      conditionList: ['New', 'Excellent', 'Good', 'Fair', 'Damaged', 'Under Repair', 'Unserviceable', 'Scrapped'],
      maintenanceTypes: ['Routine Service', 'Preventive Maintenance', 'Corrective Maintenance', 'Repair', 'Major Overhaul', 'Inspection', 'Calibration', 'Other'],
      convertForm: { name: '', category_id: null, unit_id: null, after: '' },
      converting: false,
      checkOutForm: { from_placement_id: null, quantity: null, to_warehouse_id: null, to_responsible_user_id: null, movement_date: today, reason: '' },
      checkInForm: { from_placement_id: null, quantity: null, to_warehouse_id: null, movement_date: today, condition_after: 'Good', damage_notes: '' },
      transferForm: { from_placement_id: null, quantity: null, to_warehouse_id: null, to_responsible_user_id: null, movement_date: today, reason: '' },
      maintenanceForm: { maintenance_date: today, maintenance_type: 'Routine Service', service_provider: '', cost: 0, next_service_date: '' },
      depreciationForm: { period_start: '', period_end: today },
      appreciationForm: { period_start: '', period_end: today, new_value: '' },
    };
  },

  computed: {
    otherPlacesQty() {
      const list = this.asset.placements || [];
      return list.slice(1).reduce((t, p) => t + Number(p.quantity || 0), 0);
    },
    convert() {
      return this.$page.props.convert || null;
    },
    convertErrors() {
      return this.$page.props.errors || {};
    },
    convertedItem() {
      const x = this.asset.extra_attributes || {};
      return x.converted_item_id ? { id: x.converted_item_id, code: x.converted_item_code } : null;
    },
    isDisposed() {
      return !!this.asset.disposed_at;
    },
    visibleTabs() {
      const tabs = [
        { key: 'overview', label: 'Overview' },
        { key: 'movements', label: 'Movement History' },
        { key: 'maintenance', label: 'Maintenance' },
        { key: 'depreciation', label: 'Depreciation' },
        { key: 'appreciation', label: 'Appreciation' },
        { key: 'documents', label: 'Documents' },
        { key: 'trails', label: 'Trails' },
      ];
      if (this.asset.disposal) {
        tabs.push({ key: 'disposal', label: 'Disposal' });
      }
      return tabs;
    },
    activeTabLabel() {
      const tab = this.visibleTabs.find(t => t.key === this.activeTab);
      return tab ? this.$t(tab.label) : '';
    },
    warehouseOptionsForModal() {
      return (this.$page.props.warehouses || []).map(w => ({ label: w.name, value: w.id }));
    },
    userOptionsForModal() {
      return (this.$page.props.users || []).map(u => ({ label: u.name, value: u.id }));
    },
    activity() {
      return this.$page.props.activity || [];
    },
  },

  methods: {
    qtyText(n) {
      return Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 3 });
    },
    placeText(warehouse, label, user, userLabel) {
      const place = [warehouse && warehouse.name, label].filter(Boolean).join(' / ') || '-';
      const who = (user && user.name) || userLabel;
      return who ? place + ' · ' + who : place;
    },
    openConvert() {
      this.convertForm = { name: this.asset.name, category_id: this.convert.suggested_category_id || null, unit_id: this.asset.unit_id || null, after: '' };
      this.modal = 'convert';
    },
    submitConvert() {
      this.converting = true;
      this.$inertia.post(this.route('assets.convert-to-item', this.asset.id), this.convertForm, { onFinish: () => (this.converting = false) });
    },
    openModal(name) {
      // the pieces start on the main place (where most of them are), all of them
      const main = (this.asset.placements || [])[0];
      const holder = name === 'checkIn' ? ((this.asset.placements || []).find(p => p.responsible_name) || main) : main;
      const f = { checkOut: this.checkOutForm, checkIn: this.checkInForm, transfer: this.transferForm }[name];
      if (f && holder) { f.from_placement_id = holder.id; f.quantity = holder.quantity; }
      this.modal = name;
    },
    tabIcon(key) {
      return { overview: 'chip', movements: 'truck', maintenance: 'cog', depreciation: 'chart-bar', appreciation: 'chart-pie', documents: 'doc', trails: 'clock', disposal: 'trash' }[key] || 'chip';
    },
    statusBadgeClass(status) {
      const map = {
        Available: 'lg-chip-green', Active: 'lg-chip-green',
        Assigned: 'lg-chip-blue', 'In Use': 'lg-chip-blue',
        'Under Maintenance': 'lg-chip-amber', 'Under Repair': 'lg-chip-amber', 'In Transit': 'lg-chip-amber',
        Lost: 'lg-chip-red', Stolen: 'lg-chip-red', Damaged: 'lg-chip-red',
      };
      return map[status] || 'lg-chip-slate';
    },
    submitCheckOut() {
      this.$inertia.post(this.route('assets.check-out', this.asset.id), this.checkOutForm, { onSuccess: () => (this.modal = null) });
    },
    submitCheckIn() {
      this.$inertia.post(this.route('assets.check-in', this.asset.id), this.checkInForm, { onSuccess: () => (this.modal = null) });
    },
    submitTransfer() {
      this.$inertia.post(this.route('assets.transfer', this.asset.id), this.transferForm, { onSuccess: () => (this.modal = null) });
    },
    submitMaintenance() {
      this.$inertia.post(this.route('assets.maintenance.store', this.asset.id), this.maintenanceForm, { onSuccess: () => (this.modal = null) });
    },
    submitDepreciation() {
      this.$inertia.post(this.route('assets.depreciation.store', this.asset.id), this.depreciationForm, { onSuccess: () => (this.modal = null) });
    },
    submitAppreciation() {
      this.$inertia.post(this.route('assets.appreciation.store', this.asset.id), this.appreciationForm, { onSuccess: () => (this.modal = null) });
    },
    onFilesChosen(e) {
      this.pendingFiles = Array.from(e.target.files);
    },
    uploadDocuments() {
      const form = this.$inertia.form({ attachments: this.pendingFiles, document_type: 'Supporting Documents' });
      form.post(this.route('assets.documents.store', this.asset.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => (this.pendingFiles = []),
      });
    },
    async deleteAttachment(doc) {
      if (!(await this.$confirm(this.$t('Delete this document? This cannot be undone.'), { variant: 'danger' }))) {
        return;
      }
      this.$inertia.delete(doc.delete_url, { preserveScroll: true });
    },
    showPhotoZoom(event, src, name) {
      const rect = event.currentTarget.getBoundingClientRect();
      this.photoZoom = { show: true, src, name: name || '', x: rect.right + 14, y: rect.top + rect.height / 2 };
    },
    showAttachmentZoom(event, doc) {
      const rect = event.currentTarget.getBoundingClientRect();
      this.photoZoom = { show: true, src: doc.preview_url, name: doc.title, x: rect.right + 14, y: rect.top + rect.height / 2 };
    },
    hidePhotoZoom() {
      this.photoZoom.show = false;
    },
    formatBytes(bytes) {
      if (!bytes) return '0 B';
      const units = ['B', 'KB', 'MB', 'GB'];
      const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
      return `${(bytes / Math.pow(1024, exponent)).toFixed(1)} ${units[exponent]}`;
    },
  },
};
</script>

<style scoped>
.as-total { font-weight: 800; color: #047857; }
.as-qty-total { display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem; margin-bottom: 0.8rem; } .as-qty-total > div { padding: 0.6rem 0.8rem; border-radius: 14px; background: var(--lg-soft, #f8fafc); box-shadow: inset 0 0 0 1px var(--lg-line, #e2e8f0); } .as-qty-total small { display: block; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute); } .as-qty-total b { font-size: 1.25rem; font-variant-numeric: tabular-nums; } .as-qty-total b span { font-size: 0.78rem; font-weight: 700; color: var(--lg-mute); }
.as-qty-table { width: 100%; border-collapse: collapse; font-size: 0.84rem; } .as-qty-table th { padding: 0.35rem 0.4rem; text-align: left; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute); } .as-qty-table td { padding: 0.45rem 0.4rem; border-top: 1px solid var(--lg-line, #e2e8f0); } .as-qty-table .num { text-align: right; font-variant-numeric: tabular-nums; font-weight: 700; } .as-qty-table tr.main td { background: rgba(59, 130, 246, 0.05); } .as-qty-table em { margin-left: 0.4rem; padding: 0 7px; border-radius: 99px; font-style: normal; font-size: 0.66rem; font-weight: 800; color: #1d4ed8; background: #dbeafe; } .as-qty-empty { text-align: center; color: var(--lg-mute); }
.as-mq { font-weight: 700; font-variant-numeric: tabular-nums; }
.as-convert-btn { color: #047857; } .as-conv-chip { display: inline-flex; align-items: center; gap: 4px; text-decoration: none; }
.as-conv__lead { margin: 0 0 0.9rem; font-size: 0.84rem; color: var(--lg-mute); }
.as-conv__ask { display: flex; align-items: baseline; flex-wrap: wrap; gap: 0.5rem; margin: 1.1rem 0 0.6rem; font-size: 0.92rem; font-weight: 800; } .as-conv__ask em { font-style: normal; font-size: 0.72rem; font-weight: 800; padding: 1px 9px; border-radius: 99px; color: #92400e; background: #fef3c7; }
.as-conv__choices { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.7rem; }
.as-conv__opt { position: relative; display: flex; gap: 0.7rem; align-items: flex-start; padding: 0.8rem 0.9rem; border-radius: 16px; cursor: pointer; background: var(--lg-soft, #f8fafc); box-shadow: inset 0 0 0 1.5px var(--lg-line, #e2e8f0); transition: box-shadow 0.18s ease, transform 0.15s ease, background 0.18s ease; } .as-conv__opt input { position: absolute; opacity: 0; pointer-events: none; }
.as-conv__opt:hover:not(.disabled) { transform: translateY(-1px); box-shadow: inset 0 0 0 1.5px rgba(59, 130, 246, 0.5); } .as-conv__opt.on { background: #eff6ff; box-shadow: inset 0 0 0 2px #2563eb, 0 12px 22px -16px #2563eb; } .as-conv__opt.disabled { opacity: 0.5; cursor: not-allowed; }
.as-conv__opt b { display: block; font-size: 0.9rem; } .as-conv__opt small { display: block; margin-top: 2px; font-size: 0.76rem; line-height: 1.4; color: var(--lg-mute); }
.as-conv__ico { flex: none; display: grid; place-items: center; width: 38px; height: 38px; border-radius: 12px; color: #fff; } .as-conv__ico.is-keep { background: linear-gradient(145deg, #3b82f6, #1d4ed8); } .as-conv__ico.is-del { background: linear-gradient(145deg, #f87171, #b91c1c); }
.as-conv__err { margin: 0.5rem 0 0; font-size: 0.8rem; color: #dc2626; }
.as-chips { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.ast-hero-gallery { gap: 8px; flex: none; }

.as-tabs { display: flex; gap: 6px; overflow-x: auto; margin-bottom: 1rem; padding: 6px; border-radius: 9999px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); }
.as-tab { display: inline-flex; align-items: center; gap: 7px; flex: none; padding: 0.5rem 1rem; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; white-space: nowrap; color: var(--lg-mute); transition: background 0.15s ease, color 0.15s ease; }
.as-tab:hover { color: var(--lg-ink); background: var(--lg-soft); }
.as-tab.is-active { color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.9); }
.as-tab-actions { display: flex; justify-content: flex-end; margin-bottom: 0.75rem; }

.as-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 380px), 1fr)); gap: 1rem; }
.as-grid > .lg-card { margin-bottom: 0; }
.as-dl > div { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; padding: 0.55rem 0; border-bottom: 1px dashed var(--lg-line); font-size: 0.85rem; }
.as-dl > div:last-child { border-bottom: 0; }
.as-dl dt { flex: none; color: var(--lg-mute); font-weight: 600; }
.as-dl dd { min-width: 0; text-align: right; color: var(--lg-ink); font-weight: 700; overflow-wrap: anywhere; }
.as-num { font-variant-numeric: tabular-nums; }
.as-good { color: #047857 !important; }
.as-bad { color: #be123c !important; }
.as-warn { color: #b45309 !important; }
html.dark .as-good { color: #6ee7b7 !important; }
html.dark .as-bad { color: #fda4af !important; }
html.dark .as-warn { color: #fcd34d !important; }

.as-empty { padding: 36px 16px; text-align: center; color: var(--lg-faint); font-size: 0.85rem; }

.as-upload { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 0.9rem 1.1rem; }
.as-upload-choose { cursor: pointer; }
.as-doc-list { padding: 0.5rem; }
.as-doc-row { display: flex; align-items: center; gap: 0.75rem; padding: 0.6rem 0.75rem; border-radius: 14px; transition: background 0.15s ease; }
.as-doc-row:hover { background: var(--lg-soft); }
.as-doc-row + .as-doc-row { border-top: 1px solid var(--lg-line); }
.as-doc-icon { width: 2.4rem; height: 2.4rem; border-radius: 0.75rem; overflow: hidden; display: flex; align-items: center; justify-content: center; background: var(--lg-soft); color: var(--lg-faint); flex-shrink: 0; }
.as-doc-icon img { width: 100%; height: 100%; object-fit: cover; cursor: zoom-in; }

.as-timeline { list-style: none; margin: 0; padding: 0 0 0 6px; max-height: 480px; overflow-y: auto; }
.as-timeline li { position: relative; padding: 0 0 16px 18px; border-left: 2px solid var(--lg-line); }
.as-timeline li:last-child { border-left-color: transparent; padding-bottom: 0; }
.as-tl-dot { position: absolute; left: -7px; top: 3px; width: 12px; height: 12px; border-radius: 9999px; background: #3b82f6; box-shadow: 0 0 0 3px var(--lg-card); }
.as-tl-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.as-tl-desc { font-size: 0.86rem; font-weight: 700; color: var(--lg-ink); }
.as-tl-time { font-size: 0.72rem; color: var(--lg-faint); }
.as-tl-meta { margin-top: 2px; font-size: 0.74rem; color: var(--lg-mute); }

/* modals are always a white card, so pin the light tokens */
html .as-modal.lg-page { --lg-card: #ffffff; --lg-line: #e6ebf3; --lg-soft: #f6f8fc; --lg-ink: #0f172a; --lg-mute: #64748b; --lg-faint: #94a3b8; --lg-navy: #1c3f66; padding: 1.4rem 1.5rem; border-radius: 0.375rem; }
.as-label, .as-modal :deep(.lg-fields label:not(:has(input[type='checkbox']))) { display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-mute); }
.as-label { margin-bottom: 0.25rem; }
.as-modal :deep(.lg-fields input:not([type='checkbox']):not([type='radio'])),
.as-modal :deep(.lg-fields select) { border-radius: 0.85rem !important; border-color: var(--lg-line) !important; padding-top: 0.6rem !important; padding-bottom: 0.6rem !important; }
.as-modal :deep(.lg-fields input:focus),
.as-modal :deep(.lg-fields select:focus) { border-color: #38bdf8 !important; box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2) !important; }
.as-modal-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 1.25rem; }

.ast-photo-zoom {
  position: fixed; transform: translate(0, -50%) scale(0.85); z-index: 9999;
  background: #fff; border-radius: 1rem; padding: 0.6rem; box-shadow: 0 20px 40px -10px rgba(15, 23, 42, 0.35), 0 0 0 1px rgba(15, 23, 42, 0.05);
  opacity: 0; pointer-events: none; transition: opacity 0.16s ease, transform 0.16s ease; max-width: 220px;
}
.ast-photo-zoom-show { opacity: 1; transform: translate(0, -50%) scale(1); }
.ast-photo-zoom img { display: block; width: 200px; max-height: 260px; object-fit: contain; border-radius: 0.7rem; background: #f1f5f9; }
.ast-photo-zoom-name { text-align: center; font-size: 0.75rem; font-weight: 700; color: #374151; margin-top: 0.5rem; word-break: break-word; }
html.dark .ast-photo-zoom { background: #1e293b; box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.08); }
html.dark .ast-photo-zoom-name { color: #e2e8f0; }

@media (max-width: 640px) {
  .as-dl > div { flex-direction: column; align-items: flex-start; gap: 2px; }
  .as-dl dd { text-align: left; }
}
</style>

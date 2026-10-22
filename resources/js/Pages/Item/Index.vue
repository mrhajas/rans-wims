<template>
  <admin-layout :title="$t('Items')">
    <div class="lg-page px-4 md:px-0 txn-shell">
      <page-header icon="bag" :title="$t('Items')" :subtitle="$t('Your full catalogue: prices, stock levels and tracking options.')">
        <button v-if="$can('read-items')" type="button" class="lg-btn lg-btn-green" @click="openCameraScanner">
          <icons name="search" class="w-4 h-4" />{{ $t('Scan Barcode') }}
        </button>
        <Link v-if="$can('read-items')" :href="route('items.barcodes', form.search ? { search: form.search } : {})" class="lg-btn lg-btn-soft">
          <icons name="doc" class="w-4 h-4" />{{ $t('Barcodes') }}
        </Link>
        <offline-lock v-if="$can('create-items')">
          <Link :href="route('items.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" />{{ $t('create_x', { x: $t('Item') }) }}</Link>
        </offline-lock>
      </page-header>

      <div v-if="$connection.status === 'offline'" class="mb-4 flex items-center gap-2 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 print:hidden">
        <icons name="wifi" class="w-3.5 h-3.5 flex-shrink-0" />
        <span>{{ $t('Showing cached items.') }} <span v-if="$connection.lastSyncAt">{{ $t('Last synced') }}: {{ $date($connection.lastSyncAt) }} {{ $time($connection.lastSyncAt) }}</span></span>
      </div>

      <stat-tiles v-if="stats" :items="statTiles" @select="pickTile" />

      <transition name="item-bulk">
        <div v-if="canBulk && picked.length" class="item-bulk print:hidden" role="region" :aria-label="$t('Selected items')">
          <span class="item-bulk-ico"><icons name="refresh" class="w-5 h-5" /></span>
          <div class="item-bulk-text"><b>{{ picked.length }}</b> {{ $t('item(s) selected') }} <span class="item-bulk-split">({{ pickedConsumable.length }} {{ $t('consumable') }}, {{ pickedUsed.length }} {{ $t('used') }})</span><small>{{ $t('Change the Type of the selected items. Needs your Super Admin password.') }}</small></div>
          <button type="button" class="lg-btn bulk-btn bulk-btn-none" @click="picked = []"><icons name="x-circle" class="w-4 h-4" />{{ $t('Clear selection') }}</button>
          <button v-if="pickedUsed.length" type="button" class="lg-btn bulk-btn bulk-btn-cons" @click="ask('consumable')"><icons name="refresh" class="w-4 h-4" />{{ $t('Convert to Consumable') }} ({{ pickedUsed.length }})</button>
          <button v-if="pickedConsumable.length" type="button" class="lg-btn item-bulk-go" @click="ask('used')"><icons name="lock-closed" class="w-4 h-4" />{{ $t('Convert to Used') }} ({{ pickedConsumable.length }})</button>
        </div>
      </transition>

      <div class="txn-toolbar mb-6 flex flex-col md:flex-row md:justify-between md:items-center gap-3 print:hidden relative z-20">
        <search-filter
          v-model="form.search"
          class="w-full min-w-0 md:max-w-md md:mr-4"
          :close="close"
          @reset="reset"
          @enter="scanSearch"
        >
          <!-- <label class="mt-4 block text-gray-700">{{ $t('Trashed') }}:</label> -->
          <auto-complete
            id="trashed"
            position="left"
            :label="$t('Trashed')"
            v-model="form.trashed"
            class="mt-1 w-full"
            :suggestions="[
              { label: $t('Not Trashed'), value: null },
              { label: $t('With Trashed'), value: 'with' },
              { label: $t('Only Trashed'), value: 'only' },
            ]"
          >
          </auto-complete>
        </search-filter>
        <div class="w-full md:w-auto flex flex-wrap md:flex-nowrap items-center justify-end gap-2">
          <tec-dropdown align="right" width="48" v-if="$can(['import-items', 'export-items'])">
          <template #trigger>
            <button class="lg-btn lg-btn-soft"><icons name="menu" class="w-4 h-4" />{{ $t('More') }}</button>
          </template>

          <template #content>
            <a
              v-if="$can('export-items')"
              :href="route('items.export')"
              class="block px-4 py-2 leading-5 text-gray-700 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 transition duration-150 ease-in-out"
            >
              {{ $t('export_x', { x: $t('Items') }) }}
            </a>
            <tec-dropdown-link v-if="$can('import-items')" :href="route('items.import')">
              {{ $t('import_x', { x: $t('Items') }) }}
            </tec-dropdown-link>
          </template>
          </tec-dropdown>
        </div>
      </div>
      <!-- Phones show the list as cards, which have no clickable column headers - so sorting gets its own control. -->
      <div class="lg-sortbar md:hidden print:hidden">
        <label for="item-sort">{{ $t('Sort by') }}</label>
        <select id="item-sort" v-model="form.sort">
          <option :value="null">{{ $t('Default') }}</option>
          <option value="name">{{ $t('Name') }}</option>
          <option value="type">{{ $t('Type') }}</option>
          <option value="price">{{ $t('Item Price') }}</option>
          <option value="quantity">{{ $t('Quantity') }}</option>
        </select>
        <button type="button" :disabled="!form.sort" :aria-label="$t('Reverse order')" @click="form.direction = form.direction === 'asc' ? 'desc' : 'asc'">
          <icons name="chevron-down" class="w-4 h-4 transition-transform" :class="{ 'rotate-180': form.direction === 'asc' }" />
        </button>
      </div>
      <div id="dd-table" class="txn-table-shell overflow-x-auto relative z-[1]">
        <table v-labelize class="txn-cards w-full overflow-y-visible">
          <tr class="text-center font-bold">
            <th class="px-6 pt-6 pb-4 item-th item-th-indigo" @click="toggleSort('name')">
              <span class="item-th-inner">
                <label v-if="canBulk" class="item-pick item-pick-all lg-tip" :data-tip="$t('Select all items on this page')" @click.stop>
                  <input type="checkbox" :checked="allPicked" :disabled="!pickable.length" :aria-label="$t('Select all items on this page')" @change="toggleAll" />
                </label>
                <icons name="doc-text" class="w-3.5 h-3.5" />
                {{ $t('Name') }}
                <icons v-if="form.sort === 'name'" :name="form.direction === 'asc' ? 'up' : 'down'" class="w-3 h-3 item-th-sort-icon" />
              </span>
            </th>
            <th class="px-6 pt-6 pb-4 item-th item-th-blue" @click="toggleSort('type')">
              <span class="item-th-inner">
                <icons name="collection" class="w-3.5 h-3.5" />
                {{ $t('Type') }}
                <icons v-if="form.sort === 'type'" :name="form.direction === 'asc' ? 'up' : 'down'" class="w-3 h-3 item-th-sort-icon" />
              </span>
            </th>
            <th class="px-6 pt-6 pb-4 item-th item-th-emerald" @click="toggleSort('price')">
              <span class="item-th-inner">
                <icons name="cash" class="w-3.5 h-3.5" />
                {{ $t('Item Price') }}
                <icons v-if="form.sort === 'price'" :name="form.direction === 'asc' ? 'up' : 'down'" class="w-3 h-3 item-th-sort-icon" />
              </span>
            </th>
            <th class="px-6 pt-6 pb-4 item-th-static item-th-violet">
              <span class="item-th-inner">
                <icons name="folder" class="w-3.5 h-3.5" />
                {{ $t('Relations') }}
              </span>
            </th>
            <th class="px-6 pt-6 pb-4 item-th item-th-amber" @click="toggleSort('quantity')">
              <span class="item-th-inner">
                <icons name="cart" class="w-3.5 h-3.5" />
                {{ $t('Quantity') }}
                <icons v-if="form.sort === 'quantity'" :name="form.direction === 'asc' ? 'up' : 'down'" class="w-3 h-3 item-th-sort-icon" />
              </span>
            </th>
            <th class="px-6 pt-6 pb-4 item-th-static item-th-slate">
              <span class="item-th-inner">
                <icons name="cog" class="w-3.5 h-3.5" />
                {{ $t('Options') }}
              </span>
            </th>
          </tr>
          <tr :key="item.id" v-for="item in displayItems" class="hover:bg-gray-100 focus-within:bg-gray-100">
            <td class="item-row-line" @click="goto(item)" :class="{ 'cursor-pointer': $can('read-items') }">
              <div class="px-6 py-4 max-w-md flex items-center focus:text-indigo-500">
                <label v-if="canBulk && !item.deleted_at" class="item-pick mr-3" @click.stop>
                  <input type="checkbox" :value="item.id" v-model="picked" :aria-label="$t('Select this item')" />
                </label>
                <img
                  v-if="item.photo"
                  class="item-photo-zoom block w-14 h-14 mr-3 -my-1 rounded-xl object-cover"
                  :src="item.photo"
                  @mouseenter="showPhotoZoom($event, item)"
                  @mouseleave="hidePhotoZoom"
                />
                <div>
                  <div class="font-bold">
                    <hover-preview category="items" :id="item.id">{{ item.name }}</hover-preview>
                  </div>
                  <div>
                    {{ $t('Code') }}: <strong>{{ item.code }}</strong>
                  </div>
                  <div v-if="item.sku" class="text-gray-600">{{ $t('SKU') }}: {{ item.sku }}</div>
                  <div class="flex items-center">
                    <span class="text-gray-600 mr-1">{{ $t('Symbology') }}:</span> <span class="uppercase">{{ item.symbology }}</span>
                  </div>

                </div>
                <icons v-if="item.deleted_at" name="trash" class="shrink-0 w-4 h-4 text-red-500 ml-2" />
              </div>
            </td>
            <td class="item-row-line" @click="goto(item)" :class="{ 'cursor-pointer': $can('read-items') }">
              <div class="px-6 py-4">
                <span class="lg-chip" :class="item.is_used ? 'lg-chip-amber' : 'lg-chip-green'" style="text-transform: none">
                  {{ item.is_used ? $t('Used') : $t('Consumable') }}
                </span>
              </div>
            </td>
            <td class="item-row-line" @click="goto(item)" :class="{ 'cursor-pointer': $can('read-items') }">
              <div class="px-6 py-4 w-48 text-center">
                <span class="item-price-value">{{ $number(item.price) }} Tsh</span>
              </div>
            </td>
            <td class="item-row-line" @click="goto(item)" :class="{ 'cursor-pointer': $can('read-items') }">
              <div class="px-6 py-4 w-48">
                {{ item.categories.map(c => c.name).join(', ') }}
                <div v-if="item.unit">
                  <span class="item-unit-label">{{ $t('Unit') }}</span>: {{ item.unit.name }}
                </div>
              </div>
            </td>
            <td class="item-row-line" @click="goto(item)" :class="{ 'cursor-pointer': $can('read-items') }">
              <div class="px-6 py-4 w-56 whitespace-nowrap">
                <div class="w-full flex flex-col items-center justify-between text-right">
                  <div
                    class="w-full flex items-center justify-center item-qty-tooltip-anchor"
                    @mouseenter="showWarehouseTooltip($event, item)"
                    @mouseleave="hideWarehouseTooltip"
                  >
                    <span class="lg-chip item-qty-chip" :class="qtyChip(item)" style="font-size: 0.9rem; padding: 3px 14px">{{ $number(item.stock.reduce((a, c) => a + parseFloat(c.quantity), 0)) }}</span>
                  </div>
                  <div v-if="item.track_weight == 1" class="w-full flex items-center justify-between">
                    <span class="text-gray-600">{{ $t('Weight') }}:</span>
                    <span class="font-bold">{{ $number(item.stock.reduce((a, c) => a + parseFloat(c.weight), 0)) }}</span>
                  </div>
                </div>
              </div>
            </td>
            <td class="item-row-line" @click="goto(item)" :class="{ 'cursor-pointer': $can('read-items') }">
              <div class="px-6 py-4 w-48">
                <!-- <div class="flex items-center">
                  <span class="text-gray-600 mr-1">{{ $t('Track Serials') }}</span>
                  <boolean :value="item.has_serials" class="w-3 h-3 ml-1" />
                </div> -->
                <div class="flex items-center">
                  <span class="text-gray-600 mr-1">{{ $t('Track Weight') }}</span>
                  <boolean :value="item.track_weight" class="w-3 h-3 ml-1" />
                </div>
                <div class="flex items-center">
                  <span class="text-gray-600 mr-1">{{ $t('Track Quantity') }}</span>
                  <boolean :value="item.track_quantity" class="w-3 h-3 ml-2" />
                </div>
                <div v-if="item.track_quantity == 1" class="flex items-center">
                  <span class="text-gray-600 mr-1">{{ $t('Alert on') }}:</span>
                  <span>{{ $number(item.alert_quantity || 0) }}</span>
                </div>
              </div>
            </td>
            <!-- <td class="border-t" @click="goto(item)" :class="{ 'cursor-pointer': $can('read-items') }">
              <div class="px-6 py-4 flex items-center max-w-lg min-w-56 w-64">
                <div class="w-full whitespace-normal line-clamp-4">
                  {{ item.details }}
                </div>
              </div>
            </td> -->
            <td class="item-row-line w-16">
              <div class="px-4 flex items-center print:hidden">
                <div class="txn-action-toolbar flex items-center gap-1.5" v-if="$can(['create-items', 'import-items', 'export-items'])">
                  <Link
                    v-if="$can('trail-items')"
                    :href="route('items.trail', item.id)"
                    :aria-label="$t('Trail')"
                    class="tec-tooltip group relative txn-icon-btn txn-icon-btn-blue"
                  >
                    <icons name="list"></icons>
                    <span class="tec-tooltip-bubble">{{ $t('Trail') }}</span>
                  </Link>
                  <Link
                    :href="route('items.show', item.id)"
                    :aria-label="$t('View')"
                    class="tec-tooltip group relative txn-icon-btn txn-icon-btn-blue"
                  >
                    <icons name="doc"></icons>
                    <span class="tec-tooltip-bubble">{{ $t('View') }}</span>
                  </Link>
                  <Link
                    v-if="canViewPriceHistory"
                    :href="route('items.price-history.index', item.id)"
                    :aria-label="$t('Price Inflation')"
                    class="tec-tooltip group relative txn-icon-btn txn-icon-btn-cyan"
                  >
                    <icons name="chart-bar"></icons>
                    <span class="tec-tooltip-bubble">{{ $t('Price Inflation') }}</span>
                  </Link>
                  <Link
                    v-if="$can('update-items')"
                    :href="route('items.edit', item.id)"
                    :aria-label="$t('Edit')"
                    class="tec-tooltip group relative txn-icon-btn txn-icon-btn-amber"
                  >
                    <icons name="edit"></icons>
                    <span class="tec-tooltip-bubble">{{ $t('Edit') }}</span>
                  </Link>
                  <template v-if="item.deleted_at">
                    <button
                      type="button"
                      @click="restore(item)"
                      :aria-label="$t('Restore')"
                      class="tec-tooltip group relative txn-icon-btn txn-icon-btn-blue"
                    >
                      <icons name="refresh"></icons>
                      <span class="tec-tooltip-bubble">{{ $t('Restore') }}</span>
                    </button>
                    <button
                      type="button"
                      @click="deletePermanently(item)"
                      :aria-label="$t('Delete Permanently')"
                      class="tec-tooltip group relative txn-icon-btn txn-icon-btn-red"
                    >
                      <icons name="trash"></icons>
                      <span class="tec-tooltip-bubble">{{ $t('Delete Permanently') }}</span>
                    </button>
                  </template>
                  <template v-else>
                    <button
                      type="button"
                      @click="destroy(item)"
                      :aria-label="$t('Delete')"
                      class="tec-tooltip group relative txn-icon-btn txn-icon-btn-red"
                    >
                      <icons name="trash"></icons>
                      <span class="tec-tooltip-bubble">{{ $t('Delete') }}</span>
                    </button>
                  </template>
                </div>
              </div>
            </td>
          </tr>
          <tr v-if="displayItems.length === 0">
            <td class="lg-empty" colspan="6">{{ $t('There is no data to display.') }}</td>
          </tr>
        </table>
      </div>
      <pagination v-if="$connection.status !== 'offline'" class="mt-6" :meta="items.meta" :links="items.links" />
    </div>

    <super-admin-password-modal
      :show="usedAsk"
      :title="mode === 'consumable' ? $t('Convert to Consumable') : $t('Convert to Used')"
      :content="mode === 'consumable' ? $t('These Used items will become Consumable items again, and \' - USED\' is taken off their names. Their stock, prices and history stay as they are.') : $t('These consumable items will become Used items / materials, and \' - USED\' is added to their names. Their stock, prices and history stay as they are.')"
      :lines="pickedNames"
      :confirm-text="$t('Convert')"
      :processing="usedBusy"
      :error="usedError"
      @confirm="convertUsed"
      @close="usedAsk = false"
    />

    <Teleport to="body">
      <div v-if="warehouseTooltip.visible" class="item-wh-tooltip" :style="{ left: warehouseTooltip.x + 'px', top: warehouseTooltip.y + 'px' }">
        <div class="item-wh-tooltip-title">{{ $t('Quantity per Project(s) / Warehouse(s)') }}</div>
        <div v-if="warehouseTooltip.rows.length" class="item-wh-tooltip-rows">
          <div v-for="row in warehouseTooltip.rows" :key="row.key" class="item-wh-tooltip-row">
            <span class="item-wh-tooltip-name">{{ row.name }}</span>
            <span class="item-wh-tooltip-qty text-maroon">{{ row.quantity }}</span>
          </div>
        </div>
        <div v-else class="item-wh-tooltip-empty">{{ $t('No stock recorded in any warehouse.') }}</div>
      </div>
    </Teleport>

    <!-- Teleported to <body> for the same reason as the warehouse tooltip
         above: the table wrapper is overflow-x-auto, which clips anything
         scaled/positioned past the row's own bounds. -->
    <Teleport to="body">
      <div v-if="photoZoom.visible" class="item-photo-zoom-preview" :style="{ left: photoZoom.x + 'px', top: photoZoom.y + 'px' }">
        <img :src="photoZoom.src" class="item-photo-zoom-preview-img" />
      </div>
    </Teleport>

    <!-- Item Details Modal -->
    <modal :show="details" max-width="4xl" :closeable="true" @close="hideDetails">
      <div class="rounded-md overflow-hidden">
        <div class="idm-head print:hidden">
          <span class="idm-ico"><icons name="collection" class="w-5 h-5" /></span>
          <div class="idm-title">
            <b>{{ $t('Item Details') }}</b>
            <span v-if="details && item">{{ $t(item.name) }}</span>
          </div>
          <div class="idm-actions">
            <a
              v-if="item"
              target="_blank"
              :data-tip="$t('Print Barcode')"
              :href="route('items.barcodes', { ids: item.id })"
              class="idm-btn lg-tip"
             :aria-label="$t('Print Barcode')">
              <icons name="doc" class="h-5 w-5" />
            </a>
            <a v-if="item" :href="route('items.report.pdf', item.id)" :data-tip="$t('Download PDF')" class="idm-btn is-green lg-tip" :aria-label="$t('Download PDF')"><icons name="download" class="h-5 w-5" /></a>
            <button
              @click="startPrint()"
              class="idm-btn is-blue lg-tip"
              :data-tip="$t('Print Report')"
              :aria-label="$t('Print')"
            >
              <icons name="printer" class="h-5 w-5" />
            </button>
            <button
              @click="hideDetails()"
              class="idm-btn is-red"
              :aria-label="$t('Close')"
            >
              <icons name="cross" class="h-5 w-5" />
            </button>
          </div>
        </div>

        <div class="p-6 print:block print:h-full bg-gray-100">
          <Item-details v-if="item" :item="item" :modal="true" />
        </div>
      </div>
    </modal>

    <modal :show="cameraScanOpen" max-width="lg" :closeable="true" @close="closeCameraScanner">
      <div class="rounded-md overflow-hidden">
        <div class="px-6 py-4 bg-white border-b flex items-center justify-between">
          <div class="text-lg font-semibold">{{ $t('Scan Barcode') }}</div>
          <button
            @click="closeCameraScanner"
            class="flex items-center justify-center h-8 w-8 rounded-full text-gray-600 hover:text-gray-800 hover:bg-gray-300 focus:outline-none"
          >
            <icons name="cross" class="h-5 w-5" />
          </button>
        </div>

        <div class="p-6 bg-gray-50 space-y-4">
          <p class="text-sm text-gray-600">{{ $t('Point your camera at the barcode. The item details will open automatically after scan.') }}</p>
          <div class="flex items-center gap-2">
            <button
              type="button"
              @click="openCameraCapture"
              class="inline-flex items-center px-3 py-2 rounded-md bg-blue-700 text-white text-xs font-semibold uppercase tracking-widest hover:bg-blue-800"
            >
              {{ $t('Scan From Camera Photo') }}
            </button>
            <span v-if="!canUseLiveCamera" class="text-xs text-amber-700">
              {{ $t('Live camera requires HTTPS on mobile. Use camera photo scan or open app on HTTPS.') }}
            </span>
          </div>
          <input
            ref="cameraCaptureInput"
            type="file"
            accept="image/*"
            capture="environment"
            class="hidden"
            @change="scanCapturedImage"
          />
          <div class="grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-2">
            <input
              v-model="manualBarcode"
              type="text"
              class="w-full rounded-md border-gray-300 focus:border-blue-500 focus:ring-blue-500"
              :placeholder="$t('Or enter barcode manually e.g. RSI-0001')"
            />
            <button
              type="button"
              @click="submitManualBarcode"
              class="inline-flex items-center justify-center px-3 py-2 rounded-md bg-gray-700 text-white text-xs font-semibold uppercase tracking-widest hover:bg-gray-800"
            >
              {{ $t('Find Item') }}
            </button>
          </div>
          <div id="item-camera-scanner" class="w-full min-h-[260px] rounded-md overflow-hidden bg-black"></div>
          <p v-if="cameraLoading" class="text-sm text-gray-600">{{ $t('Opening camera...') }}</p>
          <p v-if="cameraStatusMessage" class="text-sm text-blue-700">{{ cameraStatusMessage }}</p>
          <p v-if="cameraError" class="text-sm text-red-600">{{ cameraError }}</p>
        </div>
      </div>
    </modal>

    <!-- Delete User Confirmation Modal -->
    <Dialog
      max-width="md"
      :show="permanent"
      action-type="delete"
      title="Delete Item?"
      :close="closePermanentModal"
      action-text="Delete Permanently"
      :action="deleteCategoryPermanently"
      :content="`<p class='mb-2'>${$t('Are you sure you want to delete the record permanently?')}</p>
        <p>${$t('Once deleted, all of its resources and data will be permanently deleted.')}</p>`"
    />

    <!-- Delete Account Confirmation Modal -->
    <Dialog
      :show="confirm"
      :close="closeModal"
      :action="deleteItem"
      action-type="delete"
      title="Delete Item?"
      action-text="Delete Item"
      :content="$t('Are you sure you want to delete the record?')"
    />

    <!-- Restore Account Confirmation Modal -->
    <Dialog
      :show="restoreConf"
      :action="restoreItem"
      title="Restore Item!"
      :close="closeRestoreModal"
      action-text="Restore Item"
      :content="$t('Are you sure you want to restore the record?')"
    />

    <loading v-if="loading" />
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import ItemDetails from './Details.vue';
import Dialog from '@/Shared/Dialog.vue';
import Modal from '@/Jetstream/Modal.vue';
import { printWithAnimation, itemPrintSheet, printUrlInFrame } from '@/Core/printAnimation';
import TecButton from '@/Jetstream/Button.vue';
import Pagination from '@/Shared/Pagination.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import TecDropdown from '@/Jetstream/Dropdown.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';
import TecDropdownLink from '@/Jetstream/DropdownLink.vue';
import TecSectionTitle from '@/Jetstream/SectionTitle.vue';
import HoverPreview from '@/Shared/HoverPreview/HoverPreview.vue';
import SuperAdminPasswordModal from '@/Shared/SuperAdminPasswordModal.vue';
import { loadIndexData, loadDetail, loadItemStock, groupStockByWarehouse } from '@/Core/offlineDataSource';

export default {
  components: {
    PageHeader,
    StatTiles,
    Modal,
    Dialog,
    TecButton,
    Pagination,
    ItemDetails,
    AdminLayout,
    TecDropdown,
    SelectInput,
    AutoComplete,
    SearchFilter,
    HoverPreview,
    TecDropdownLink,
    TecSectionTitle,
    SuperAdminPasswordModal,
  },

  props: {
    items: Object,
    stats: Object,
    filters: Object,
    warehouse: Array,
  },

  data() {
    return {
      picked: [],
      usedAsk: false,
      mode: 'used',
      seen: {},
      usedBusy: false,
      usedError: '',
      edit: null,
      item: null,
      close: false,
      confirm: false,
      details: false,
      loading: false,
      permanent: false,
      restoreConf: false,
      scanning: false,
      cameraScanOpen: false,
      cameraLoading: false,
      cameraError: null,
      cameraStatusMessage: null,
      cameraScanner: null,
      lastScannedCode: null,
      manualBarcode: '',
      canUseLiveCamera: typeof window !== 'undefined' ? window.isSecureContext && !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia) : false,
      form: {
        search: this.filters.search,
        trashed: this.filters.trashed,
        type: this.filters.type || null,
        sort: this.filters.sort || null,
        direction: this.filters.direction || null,
      },
      offlineItems: null,
      warehouseTooltip: { visible: false, rows: [], x: 0, y: 0 },
      photoZoom: { visible: false, src: '', x: 0, y: 0 },
    };
  },

  mounted() {
    if (this.$connection.status === 'offline') {
      this.loadOfflineItems();
    }
  },

  unmounted() {
    this.stopCameraScanner();
  },

  computed: {
    statTiles() {
      const s = this.stats || {};

      // Clickable (and highlighted when applied) while online; the filtering itself is done by the server.
      const online = this.$connection.status !== 'offline';
      const tile = (type, icon, tone, label, value) => ({ icon, tone, label, value: Number(value || 0), type, ...(online ? { active: (this.form.type || null) === type } : {}) });

      return [
        tile(null, 'bag', 'indigo', this.$t('Total Items'), s.total),
        tile('consumable', 'collection', 'emerald', this.$t('Consumable'), s.consumable),
        tile('used', 'truck', 'sky', this.$t('Used'), s.used),
        tile('low', 'exclamation', 'amber', this.$t('Low Stock'), s.low),
        tile('out', 'x-circle', 'rose', this.$t('Out of Stock'), s.out),
      ];
    },
    canViewPriceHistory() {
      const roles = this.$user.roles || [];
      return roles.some(r => ['Super Admin', 'Admin', 'Project Manager', 'Project Managers', 'Procurement Manager', 'Procurement Manger'].includes(r.name));
    },
    // Read-only offline fallback for the `items` Inertia prop — see
    // resources/js/Core/offlineDataSource.js. Everything else in the
    // template reads this instead of `items.data` directly so the offline
    // branch is a single swap point, not a per-row special case.
    // Super Admin only: tick consumable items and convert their Type to Used (with their password)
    canBulk() {
      return !!this.$super && this.$connection.status !== 'offline';
    },
    pickable() {
      return (this.items.data || []).filter(i => !i.deleted_at);
    },
    // what each ticked item is (kept across pages, so a selection can be made over several pages)
    pickedConsumable() {
      return this.picked.filter(id => this.seen[id] && !this.seen[id].is_used);
    },
    pickedUsed() {
      return this.picked.filter(id => this.seen[id] && this.seen[id].is_used);
    },
    allPicked() {
      return this.pickable.length > 0 && this.pickable.every(i => this.picked.includes(i.id));
    },
    pickedNames() {
      return (this.mode === 'consumable' ? this.pickedUsed : this.pickedConsumable).map(id => this.seen[id].name + ' (' + this.seen[id].code + ')');
    },
    displayItems() {
      if (this.$connection.status === 'offline' && this.offlineItems) {
        return this.offlineItems;
      }
      return this.items.data;
    },
  },

  created() {
    this.remember();
  },

  watch: {
    'items.data'() {
      this.remember();
    },
    form: {
      handler: throttle(function () {
        if (this.$connection.status === 'offline') {
          this.loadOfflineItems();
          return;
        }
        let query = pickBy(this.form);
        this.$inertia.visit(this.route('items.index', Object.keys(query).length ? query : { remember: 'forget' }), {
          onFinish: () => {
            document.getElementById('page-search').focus();
          },
        });
      }, 150),
      deep: true,
    },
  },

  methods: {
    toggleAll() {
      this.picked = this.allPicked ? this.picked.filter(id => !this.pickable.some(i => i.id === id)) : [...new Set([...this.picked, ...this.pickable.map(i => i.id)])];
    },
    ask(mode) {
      this.mode = mode;
      this.usedError = '';
      this.usedAsk = true;
    },
    remember() {
      (this.items.data || []).forEach(i => { this.seen[i.id] = { name: i.name, code: i.code, is_used: !!i.is_used }; });
    },
    convertUsed(password) {
      this.usedBusy = true;
      this.usedError = '';
      const ids = this.mode === 'consumable' ? this.pickedUsed : this.pickedConsumable;
      this.$inertia.post(route(this.mode === 'consumable' ? 'items.bulk-consumable' : 'items.bulk-used'), { ids, password }, {
        preserveScroll: true,
        onSuccess: () => { this.usedAsk = false; this.picked = []; },
        onError: e => { this.usedError = e.password || e.ids || Object.values(e)[0] || this.$t('That did not work.'); },
        onFinish: () => (this.usedBusy = false),
      });
    },
    // Clicking a tile filters the list to it; clicking the lit tile (or "Total Items") shows everything again.
    pickTile(tile) {
      this.form.type = tile.type && this.form.type !== tile.type ? tile.type : null;
    },
    qtyChip(item) {
      const qty = (item.stock || []).reduce((a, c) => a + parseFloat(c.quantity), 0);
      if (item.track_quantity == 1 && qty <= 0) return 'lg-chip-red';
      if (item.track_quantity == 1 && parseFloat(item.alert_quantity) > 0 && qty <= parseFloat(item.alert_quantity)) return 'lg-chip-amber';
      return 'lg-chip-blue';
    },
    toggleSort(column) {
      if (this.form.sort === column) {
        this.form.direction = this.form.direction === 'asc' ? 'desc' : 'asc';
      } else {
        this.form.sort = column;
        this.form.direction = 'asc';
      }
    },
    // Teleported to <body> (not a plain CSS ::after tooltip anchored in the
    // cell) because this table's wrapper is overflow-x-auto — per CSS spec
    // that forces overflow-y to compute as auto too, clipping any tooltip
    // taller than the row it's anchored in. x is clamped so the box stays
    // fully on-screen on narrow/mobile viewports instead of running off the
    // right edge.
    showWarehouseTooltip(event, item) {
      const rows = (item.stock || [])
        .filter(s => parseFloat(s.quantity) !== 0)
        .map(s => ({
          key: s.id,
          name: s.warehouse ? s.warehouse.name : this.$t('Unknown'),
          quantity: this.$number(s.quantity),
        }));

      const rect = event.currentTarget.getBoundingClientRect();
      const tooltipWidth = 260;
      const x = Math.min(Math.max(rect.left + rect.width / 2, tooltipWidth / 2 + 8), window.innerWidth - tooltipWidth / 2 - 8);

      this.warehouseTooltip = { visible: true, rows, x, y: rect.top };
    },
    hideWarehouseTooltip() {
      this.warehouseTooltip.visible = false;
    },
    showPhotoZoom(event, item) {
      const rect = event.currentTarget.getBoundingClientRect();
      const previewWidth = 199;
      const x = Math.min(Math.max(rect.left + rect.width / 2, previewWidth / 2 + 8), window.innerWidth - previewWidth / 2 - 8);

      this.photoZoom = { visible: true, src: item.photo, x, y: rect.top };
    },
    hidePhotoZoom() {
      this.photoZoom.visible = false;
    },
    async loadOfflineItems() {
      const result = await loadIndexData('items', { search: this.form.search });
      this.offlineItems = result.data;
    },
    async goto(item) {
      if (this.item && this.item.id == item.id) {
        this.details = true;
        return;
      }

      if (this.$connection.status === 'offline') {
        const [cachedItem, stockRows] = await Promise.all([loadDetail('items', item.id), loadItemStock(item.id)]);
        if (!cachedItem) {
          return;
        }
        this.item = { ...cachedItem, all_stock: stockRows, stock: groupStockByWarehouse(stockRows) };
        this.details = true;
        return;
      }

      this.loading = true;
      axios.get(route('items.show', item.id) + '?json=yes').then(res => {
        this.item = res.data;
        this.details = true;
        this.loading = false;
      });
    },
    reset() {
      this.form = mapValues(this.form, () => null);
    },
    // destroy(id, deleted_at) {
    //   this.$inertia.delete(route(deleted_at ? 'items.destroy.permanently' : 'items.destroy', id));
    // },
    destroy(edit) {
      this.edit = edit;
      this.confirm = true;
    },
    deleteItem() {
      this.$inertia.delete(route('items.destroy', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    showDetails() {
      this.details = false;
    },
    hideDetails() {
      this.details = false;
    },
    startPrint() {
      printWithAnimation(itemPrintSheet(this, this.item), () => printUrlInFrame(this.route('items.report.print', this.item.id)));
    },
    print() {
      document.body.classList.add('printing-details');
      window.addEventListener('afterprint', () => document.body.classList.remove('printing-details'), { once: true });
      window.print();
    },
    normalizeBarcode(value) {
      const raw = (value || '').trim();
      if (!raw) return '';
      const noGroupSeparator = raw.replace(/[\u001D]/g, '');
      const withoutPrefix = noGroupSeparator.replace(/^\][A-Za-z0-9]{2}/, '');
      return withoutPrefix.replace(/\s+/g, '');
    },
    scanSearch(value, { showNotFound = false } = {}) {
      const code = this.normalizeBarcode(value);
      if (!code || this.scanning) return Promise.resolve(false);
      this.scanning = true;
      return axios
        .post(route('items.scan'), { code })
        .then(res => {
          if (res.data.found) {
            this.cameraError = null;
            this.goto({ id: res.data.id });
            return true;
          }

          if (showNotFound) {
            this.cameraError = this.$t('Barcode scanned but no item matched. Please verify the barcode is saved as Item Code/SKU.');
          }

          return false;
        })
        .catch(() => {
          if (showNotFound) {
            this.cameraError = this.$t('Could not validate scanned barcode. Please try again.');
          }

          return false;
        })
        .finally(() => (this.scanning = false));
    },
    openCameraScanner() {
      this.cameraScanOpen = true;
      this.cameraError = null;
      this.cameraStatusMessage = this.$t('Scanner ready. Point the barcode inside the frame.');
      this.lastScannedCode = null;
      this.manualBarcode = '';
      this.$nextTick(() => this.startCameraScanner());
    },
    closeCameraScanner() {
      this.cameraScanOpen = false;
      this.cameraStatusMessage = null;
      this.stopCameraScanner();
    },
    async startCameraScanner() {
      if (!this.cameraScanOpen || this.cameraLoading) {
        return;
      }

      if (!this.canUseLiveCamera) {
        this.cameraError = this.$t('Live camera scanner is unavailable on this connection. Use "Scan From Camera Photo" or open the app over HTTPS.');
        return;
      }

      this.cameraLoading = true;
      this.cameraStatusMessage = this.$t('Opening camera...');

      try {
        const { Html5Qrcode, Html5QrcodeSupportedFormats } = await import('html5-qrcode');

        if (this.cameraScanner) {
          await this.stopCameraScanner();
        }

        this.cameraScanner = new Html5Qrcode('item-camera-scanner');

        await this.cameraScanner.start(
          { facingMode: 'environment' },
          {
            fps: 10,
            qrbox: (viewfinderWidth, viewfinderHeight) => ({
              width: Math.floor(viewfinderWidth * 0.9),
              height: Math.floor(viewfinderHeight * 0.4),
            }),
            aspectRatio: 1.333334,
            rememberLastUsedCamera: true,
            formatsToSupport: [
              Html5QrcodeSupportedFormats.CODE_128,
              Html5QrcodeSupportedFormats.CODE_39,
              Html5QrcodeSupportedFormats.CODE_93,
              Html5QrcodeSupportedFormats.CODABAR,
              Html5QrcodeSupportedFormats.EAN_13,
              Html5QrcodeSupportedFormats.EAN_8,
              Html5QrcodeSupportedFormats.UPC_A,
              Html5QrcodeSupportedFormats.UPC_E,
              Html5QrcodeSupportedFormats.ITF,
              Html5QrcodeSupportedFormats.QR_CODE,
              Html5QrcodeSupportedFormats.DATA_MATRIX,
            ],
          },
          decodedText => {
            const code = (decodedText || '').trim();
            if (!code || this.lastScannedCode === code || this.scanning) {
              return;
            }

            this.lastScannedCode = code;
            this.cameraStatusMessage = this.$t('Detected barcode') + ': ' + code;
              this.scanSearch(code, { showNotFound: true }).then(found => {
                if (found) {
                  this.closeCameraScanner();
                } else {
                  this.lastScannedCode = null;
                }
              });
          },
          () => {}
        );

        this.cameraStatusMessage = this.$t('Camera started. Hold barcode steady for 1-2 seconds.');
      } catch (error) {
        this.cameraError = this.$t('Unable to start camera scanner. Please allow camera access and try again.');
        this.cameraStatusMessage = null;
      } finally {
        this.cameraLoading = false;
      }
    },
    openCameraCapture() {
      this.cameraError = null;
      this.cameraStatusMessage = this.$t('Opening camera photo capture...');
      this.$refs.cameraCaptureInput?.click();
    },
    submitManualBarcode() {
      const code = this.normalizeBarcode(this.manualBarcode);
      if (!code) {
        this.cameraError = this.$t('Please enter a barcode value.');
        return;
      }

      this.cameraError = null;
      this.cameraStatusMessage = this.$t('Searching item for') + ': ' + code;
      this.scanSearch(code, { showNotFound: true }).then(found => {
        if (found) {
          this.closeCameraScanner();
        }
      });
    },
    async scanCapturedImage(event) {
      const file = event?.target?.files?.[0];
      if (!file || this.scanning) {
        return;
      }

      this.cameraLoading = true;
      this.cameraError = null;
      this.cameraStatusMessage = this.$t('Processing selected image...');

      try {
        let code = '';

        // Pass 1: html5-qrcode
        try {
          const { Html5Qrcode } = await import('html5-qrcode');
          const fileScanner = new Html5Qrcode('item-camera-scanner');
          const decodedText = await fileScanner.scanFile(file, true);
          code = (decodedText || '').trim();

          try {
            await fileScanner.clear();
          } catch (e) {
          }
        } catch (e) {
        }

        // Pass 2: ZXing fallback
        if (!code) {
          const { BrowserMultiFormatReader } = await import('@zxing/browser');
          const reader = new BrowserMultiFormatReader();
          const imageUrl = URL.createObjectURL(file);

          try {
            const result = await reader.decodeFromImageUrl(imageUrl);
            code = (result?.getText?.() || '').trim();
          } finally {
            URL.revokeObjectURL(imageUrl);
            reader.reset();
          }
        }

        if (!code) {
          this.cameraError = this.$t('No barcode detected in the selected image.');
          return;
        }

        this.lastScannedCode = code;
        this.cameraStatusMessage = this.$t('Detected barcode') + ': ' + code;
        const found = await this.scanSearch(code, { showNotFound: true });
        if (found) {
          this.closeCameraScanner();
        }
      } catch (error) {
        this.cameraError = this.$t('Could not read barcode from image. Please retake with clearer focus and better lighting.');
        this.cameraStatusMessage = null;
      } finally {
        this.cameraLoading = false;
        if (event?.target) {
          event.target.value = '';
        }
      }
    },
    async stopCameraScanner() {
      if (!this.cameraScanner) {
        return;
      }

      try {
        await this.cameraScanner.stop();
      } catch (e) {
      }

      try {
        await this.cameraScanner.clear();
      } catch (e) {
      }

      this.cameraScanner = null;
      this.cameraLoading = false;
    },
    closeModal() {
      this.edit = null;
      this.confirm = false;
    },
    restore(edit) {
      this.edit = edit;
      this.restoreConf = true;
    },
    restoreItem() {
      this.$inertia.put(this.route('items.restore', this.edit.id), {
        onSuccess: () => (this.restoreConf = false),
      });
    },
    closeRestoreModal() {
      this.edit = null;
      this.restoreConf = false;
    },
    deletePermanently(edit) {
      this.edit = edit;
      this.permanent = true;
    },
    deleteCategoryPermanently() {
      this.$inertia.delete(route('items.destroy.permanently', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    closePermanentModal() {
      this.edit = null;
      this.permanent = false;
    },
  },
};
</script>

<style scoped>
.item-pick { display: inline-flex; align-items: center; justify-content: center; flex: none; padding: 4px; border-radius: 9px; background: #fff; cursor: pointer; box-shadow: 0 6px 12px -5px rgba(194, 65, 12, 0.55), 0 1px 3px rgba(15, 23, 42, 0.18), 0 0 0 1px rgba(234, 88, 12, 0.28); }
html.dark .item-pick { background: #1e293b; }
.item-pick input { width: 18px; height: 18px; accent-color: #c2410c; cursor: pointer; }
.item-pick-all { margin-right: 8px; }
.item-bulk { position: sticky; top: 0.5rem; z-index: 30; display: flex; flex-wrap: wrap; align-items: center; gap: 10px 14px; margin: 0 0 1rem; padding: 0.7rem 0.9rem; border-radius: 18px; background: linear-gradient(135deg, rgba(254, 215, 170, 0.92), rgba(255, 237, 213, 0.95)); border: 1px solid rgba(234, 88, 12, 0.35); box-shadow: 0 16px 28px -16px rgba(194, 65, 12, 0.55); backdrop-filter: blur(10px); }
.item-bulk-ico { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 13px; color: #fff; background: linear-gradient(145deg, #fb923c, #c2410c); }
.item-bulk-text { flex: 1 1 220px; min-width: 0; color: #7c2d12; font-size: 0.92rem; } .item-bulk-text small { display: block; font-size: 0.74rem; opacity: 0.85; }
.bulk-btn { border: 0; color: #fff; }
.bulk-btn-none { background: linear-gradient(145deg, #fb7185, #be123c); box-shadow: 0 10px 18px -8px rgba(190, 18, 60, 0.8), 0 2px 4px rgba(15, 23, 42, 0.15); }
.bulk-btn:hover { transform: translateY(-1px); }
.bulk-btn-cons { background: linear-gradient(145deg, #34d399, #047857); box-shadow: 0 10px 18px -8px rgba(4, 120, 87, 0.8), 0 2px 4px rgba(15, 23, 42, 0.15); }
.item-bulk-split { font-size: 0.78rem; opacity: 0.85; }
.item-bulk-go { color: #fff; background: linear-gradient(145deg, #fb923c, #c2410c); box-shadow: 0 10px 18px -10px rgba(194, 65, 12, 0.9); }
.item-bulk-enter-active, .item-bulk-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; } .item-bulk-enter-from, .item-bulk-leave-to { opacity: 0; transform: translateY(-8px); }
html.dark .item-bulk { background: linear-gradient(135deg, rgba(124, 45, 18, 0.7), rgba(67, 20, 7, 0.75)); } html.dark .item-bulk-text { color: #fed7aa; }
</style>
<style scoped>
:deep(#dd-table table) { border-collapse: collapse; }
:deep(#dd-table th),
:deep(#dd-table td) { border-left: none; border-right: none; }
.item-th-inner { display: inline-flex; align-items: center; justify-content: center; gap: 6px; width: 100%; }
.item-th, .item-th-static { white-space: nowrap; text-align: center; padding-top: 0.9rem; padding-bottom: 0.9rem; }
.item-th { cursor: pointer; user-select: none; transition: filter 0.15s ease; }
.item-th:hover { filter: brightness(0.96); }
.item-th-sort-icon { opacity: 0.75; }
/* !important needed: .txn-table-shell :deep(th) (below, pre-existing)
   already sets a flat background on every <th> in this table — these
   per-column tints need to win over that shared rule. */
.item-th-indigo { color: #4338ca; background-color: rgba(79, 70, 229, 0.12) !important; }
.item-th-blue { color: #1d4ed8; background-color: rgba(37, 99, 235, 0.1) !important; }
.item-th-emerald { color: #047857; background-color: rgba(5, 150, 105, 0.1) !important; }
.item-th-violet { color: #6d28d9; background-color: rgba(124, 58, 237, 0.1) !important; }
.item-th-amber { color: #92400e; background-color: rgba(217, 119, 6, 0.12) !important; }
.item-th-slate { color: #334155; background-color: rgba(71, 85, 105, 0.08) !important; }
.item-price-value { color: #1e3a8a; font-weight: 700; }
.item-unit-label { color: #047857; font-weight: 700; }
html.dark .item-price-value { color: #93c5fd; }
html.dark .item-unit-label { color: #6ee7b7; }
.item-row-line {
  border-top: 1px solid #e5e7eb;
  /* inset, not a regular drop shadow — a non-inset shadow's blur radius
     extends sideways past the cell's own edges too, which (since each td
     casts its own) showed up as a faint vertical seam between columns. */
  box-shadow: inset 0 2px 3px -2px rgba(15, 23, 42, 0.18);
}
html.dark .item-th-indigo { background-color: rgba(99, 102, 241, 0.25) !important; color: #c7d2fe; }
html.dark .item-th-blue { background-color: rgba(59, 130, 246, 0.25) !important; color: #bfdbfe; }
html.dark .item-th-emerald { background-color: rgba(16, 185, 129, 0.25) !important; color: #a7f3d0; }
html.dark .item-th-violet { background-color: rgba(139, 92, 246, 0.25) !important; color: #ddd6fe; }
html.dark .item-th-amber { background-color: rgba(245, 158, 11, 0.25) !important; color: #fde68a; }
html.dark .item-th-slate { background-color: rgba(148, 163, 184, 0.22) !important; color: #e2e8f0; }
html.dark .item-th:hover { filter: brightness(1.15); }
html.dark .item-row-line {
  border-top-color: rgba(255, 255, 255, 0.1);
  box-shadow: inset 0 2px 3px -2px rgba(0, 0, 0, 0.5);
}
.item-photo-zoom {
  border-radius: 0.6rem;
  border: 1px solid rgba(15, 23, 42, 0.12);
  object-fit: cover;
  cursor: zoom-in;
  transition: border-color 0.15s ease;
}
.item-photo-zoom:hover {
  border-color: rgba(79, 70, 229, 0.5);
}
html.dark .item-photo-zoom {
  border-color: rgba(255, 255, 255, 0.14);
}
html.dark .item-photo-zoom:hover {
  border-color: rgba(129, 140, 248, 0.55);
}

.txn-shell {
  position: relative;
}
.txn-bg-decor {
  position: absolute;
  inset: 0;
  z-index: 0;
  overflow: hidden;
  pointer-events: none;
  border-radius: inherit;
}
.txn-blob {
  position: absolute;
  border-radius: 9999px;
  filter: blur(70px);
  opacity: 0.25;
}
.txn-blob-1 {
  top: -60px;
  left: -40px;
  height: 220px;
  width: 220px;
  background: radial-gradient(circle, #38bdf8, transparent 70%);
}
.txn-blob-2 {
  top: 40px;
  right: -60px;
  height: 240px;
  width: 240px;
  background: radial-gradient(circle, #34d399, transparent 70%);
}

.txn-hero {
  position: relative;
  background: linear-gradient(140deg, rgba(224, 242, 254, 0.65), rgba(255, 255, 255, 0.5));
  border: 1px solid rgba(255, 255, 255, 0.7);
  backdrop-filter: blur(20px) saturate(160%);
  -webkit-backdrop-filter: blur(20px) saturate(160%);
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 16px 32px -12px rgba(56, 189, 248, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.6);
}
.txn-hero-icon {
  background: linear-gradient(145deg, #38bdf8, #0284c7);
  box-shadow: 0 8px 20px -6px rgba(2, 132, 199, 0.5);
}

.txn-table-shell {
  border: 1px solid #eef1f6;
  background: #ffffff;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 10px 24px rgba(15, 23, 42, 0.05);
}
.txn-table-shell :deep(th) {
  background: rgba(248, 250, 252, 0.88);
  color: #334155;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.txn-pill-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.65rem 1rem;
  border-radius: 9999px;
  font-weight: 700;
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #ffffff;
  transition: all 0.15s ease;
  min-height: 0;
}
.txn-pill-btn-emerald {
  background: linear-gradient(135deg, #10b981, #047857);
  box-shadow: 0 8px 18px -6px rgba(4, 120, 87, 0.45);
}
.txn-pill-btn-emerald:hover {
  transform: translateY(-1px);
  box-shadow: 0 10px 22px -6px rgba(4, 120, 87, 0.55);
}
.txn-pill-btn-dark {
  background: linear-gradient(135deg, #334155, #1e293b);
  box-shadow: 0 8px 18px -6px rgba(30, 41, 59, 0.45);
}
.txn-pill-btn-dark:hover {
  transform: translateY(-1px);
  box-shadow: 0 10px 22px -6px rgba(30, 41, 59, 0.55);
}

.txn-icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 2.1rem;
  width: 2.1rem;
  min-height: 0;
  padding: 0;
  border-radius: 9999px;
  transition: all 0.15s ease;
}
.txn-icon-btn-blue {
  background: #dbeafe;
  color: #1d4ed8;
}
.txn-icon-btn-blue:hover {
  background: #2563eb;
  color: #fff;
}
.txn-icon-btn-cyan {
  background: #cffafe;
  color: #0e7490;
}
.txn-icon-btn-cyan:hover {
  background: #0891b2;
  color: #fff;
}
.txn-icon-btn-amber {
  background: #fef3c7;
  color: #b45309;
}
.txn-icon-btn-amber:hover {
  background: #d97706;
  color: #fff;
}
.txn-icon-btn-red {
  background: #fee2e2;
  color: #b91c1c;
}
.txn-icon-btn-red:hover {
  background: #dc2626;
  color: #fff;
}

html.dark .txn-hero {
  background: linear-gradient(150deg, rgba(30, 41, 59, 0.75), rgba(15, 23, 42, 0.6));
  border-color: rgba(255, 255, 255, 0.08);
}
html.dark .txn-table-shell {
  background: #0f172a;
  border-color: rgba(255, 255, 255, 0.08);
}
html.dark .txn-table-shell :deep(th) {
  background: rgba(30, 41, 59, 0.7);
  color: #cbd5e1;
}

@media (max-width: 640px) {
  .txn-icon-btn {
    height: 2.4rem;
    width: 2.4rem;
  }
}

.tec-tooltip-bubble {
  position: absolute;
  bottom: calc(100% + 10px);
  left: 50%;
  transform: translateX(-50%) translateY(4px);
  z-index: 20;
  padding: 0.35rem 0.7rem;
  border-radius: 0.5rem;
  white-space: nowrap;
  font-size: 0.7rem;
  font-weight: 600;
  letter-spacing: 0.02em;
  color: #f8fafc;
  background: linear-gradient(145deg, rgba(15, 23, 42, 0.96), rgba(30, 41, 59, 0.92));
  box-shadow: 0 10px 20px rgba(15, 23, 42, 0.25);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.15s ease, transform 0.15s ease;
}

.tec-tooltip-bubble::after {
  content: '';
  position: absolute;
  top: 100%;
  left: 50%;
  transform: translateX(-50%);
  border: 5px solid transparent;
  border-top-color: rgba(15, 23, 42, 0.96);
}

.tec-tooltip:hover .tec-tooltip-bubble,
.tec-tooltip:focus-visible .tec-tooltip-bubble {
  opacity: 1;
  transform: translateX(-50%) translateY(0);
}

.text-maroon { color: #7f1d1d; }
html.dark .text-maroon { color: #f87171; }

.item-qty-tooltip-anchor { cursor: default; }

.item-wh-tooltip {
  position: fixed;
  transform: translate(-50%, calc(-100% - 10px));
  width: 260px;
  max-height: 260px;
  overflow-y: auto;
  background: #1f2937;
  color: #fff;
  padding: 0.6rem 0.75rem;
  border-radius: 0.6rem;
  font-size: 0.72rem;
  font-weight: 500;
  line-height: 1.5;
  text-align: left;
  pointer-events: none;
  z-index: 9999;
  box-shadow: 0 10px 24px rgba(0, 0, 0, 0.25);
}

.item-wh-tooltip-title {
  font-size: 0.66rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: #94a3b8;
  margin-bottom: 0.35rem;
}

.item-wh-tooltip-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.15rem 0;
}

.item-wh-tooltip-name { color: #e2e8f0; }
.item-wh-tooltip-qty { font-weight: 700; }
.item-wh-tooltip-qty.text-maroon { color: #f87171; }

.item-wh-tooltip-empty { color: #94a3b8; }

.item-photo-zoom-preview {
  position: fixed;
  transform: translate(-50%, calc(-100% - 12px));
  z-index: 9999;
  pointer-events: none;
  padding: 6px;
  background: #fff;
  border-radius: 0.85rem;
  border: 1px solid rgba(15, 23, 42, 0.08);
  box-shadow: 0 16px 36px -8px rgba(15, 23, 42, 0.4);
}
.item-photo-zoom-preview-img {
  display: block;
  width: 187px;
  height: 187px;
  object-fit: cover;
  border-radius: 0.6rem;
}
html.dark .item-photo-zoom-preview {
  background: #1f2937;
  border-color: rgba(255, 255, 255, 0.1);
}

/* Calm, uniform table headers: the old per-column tints are replaced by the shared look (coloured icons stay). */
.item-th-indigo, .item-th-blue, .item-th-emerald, .item-th-violet, .item-th-amber, .item-th-slate { background-color: var(--lg-soft) !important; }
.item-th-indigo { color: #4338ca; } .item-th-blue { color: #1d4ed8; } .item-th-emerald { color: #047857; } .item-th-violet { color: #6d28d9; } .item-th-amber { color: #b45309; } .item-th-slate { color: #475569; }
.item-th, .item-th-static { text-align: left; padding: 13px 16px !important; }
.item-th-inner { justify-content: flex-start; width: auto; }
html.dark .item-th-indigo, html.dark .item-th-blue, html.dark .item-th-emerald, html.dark .item-th-violet, html.dark .item-th-amber, html.dark .item-th-slate { background-color: var(--lg-soft) !important; }
.item-row-line { border-top: 0 !important; box-shadow: none !important; }

/* the item details window: a clear header with the item name and round action buttons */
.idm-head { display: flex; align-items: center; gap: 12px; padding: 14px 18px; background: var(--lg-card, #fff); border-bottom: 1px solid var(--lg-line, #e5e7eb); }
.idm-ico { flex: none; width: 40px; height: 40px; border-radius: 13px; display: grid; place-items: center; color: #fff; background: linear-gradient(135deg, #2f6199, #1c3f66); }
.idm-title { display: grid; min-width: 0; flex: 1; line-height: 1.2; } .idm-title b { font-size: 1rem; } .idm-title span { font-size: 0.82rem; color: var(--lg-mute, #64748b); overflow-wrap: anywhere; }
.idm-actions { display: flex; align-items: center; gap: 8px; flex: none; }
.idm-btn { width: 38px; height: 38px; border-radius: 50%; display: grid; place-items: center; border: 0; cursor: pointer; background: rgba(148, 163, 184, 0.16); color: #475569; transition: background 0.15s, transform 0.15s; }
.idm-btn:hover { transform: translateY(-1px); background: rgba(148, 163, 184, 0.3); }
.idm-btn.is-blue { background: rgba(59, 130, 196, 0.16); color: #1c3f66; } .idm-btn.is-blue:hover { background: rgba(59, 130, 196, 0.3); }
.idm-btn.is-green { background: rgba(16, 185, 129, 0.16); color: #047857; } .idm-btn.is-green:hover { background: rgba(16, 185, 129, 0.3); }
.idm-btn.is-red { background: rgba(225, 29, 72, 0.12); color: #be123c; } .idm-btn.is-red:hover { background: rgba(225, 29, 72, 0.24); }
.idm-btn:focus-visible { outline: 3px solid rgba(59, 130, 196, 0.5); outline-offset: 2px; }
@media (max-width: 479.98px) { .idm-head { padding: 12px; } .idm-actions { gap: 5px; } .idm-btn { width: 34px; height: 34px; } .idm-ico { display: none; } }
</style>

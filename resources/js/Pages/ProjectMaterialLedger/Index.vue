<template>
  <admin-layout :title="$t('Project Material Ledger')">
    <div class="lg-page pml-page px-4 md:px-0">
      <page-header icon="clip" :title="$t('Project Material Ledger')" :subtitle="$t('Every Checked In, Checked Out, Transfer In, and Transfer Out movement for one project: quantity, value, source, destination, date, and who was responsible.')">
        <template v-if="selectedProject">
          <a :href="printUrl" target="_blank" class="lg-btn lg-btn-dark">
            <icons name="printer" class="w-4 h-4" />
            <span>{{ $t('Print') }}</span>
          </a>
          <a :href="downloadUrl" target="_blank" class="lg-btn lg-btn-primary">
            <icons name="download" class="w-4 h-4" />
            <span>{{ $t('Download') }}</span>
          </a>
        </template>
      </page-header>

      <!-- Project selector -->
      <div class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="building" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-card-title">{{ $t('Project / Site') }}</div>
            <div class="lg-card-sub">{{ $t('Pick a project to open its material ledger.') }}</div>
          </div>
        </div>
        <div class="relative mb-3 max-w-sm">
          <icons name="search" class="w-4 h-4 pml-search-ico absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
          <input v-model="projectSearch" type="text" :placeholder="$t('Search project / site…')" class="pml-search-input" />
        </div>
        <div class="pml-project-grid">
          <button
            v-for="p in filteredProjects"
            :key="p.id"
            type="button"
            class="pml-project-chip"
            :class="{ 'pml-project-chip-active': form.project_warehouse_id === p.id }"
            @click="selectProject(p.id)"
          >
            <icons name="building" class="w-4 h-4 pml-project-chip-icon flex-none" />
            <span class="min-w-0">
              <span class="block truncate">{{ p.name }}</span>
              <span class="pml-project-chip-status">{{ $t(p.status) }}</span>
            </span>
          </button>
          <div v-if="!projects.length" class="lg-empty col-span-full">
            {{ $t('No projects available to you.') }}
          </div>
          <div v-else-if="!filteredProjects.length" class="lg-empty col-span-full">
            {{ $t('No projects match your search.') }}
          </div>
        </div>
      </div>

      <template v-if="selectedProject">
        <!-- Filters -->
        <div class="lg-card lg-filters pml-filters-panel">
          <div class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="filter" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <div class="lg-card-title">{{ $t('Filters') }}</div>
              <div class="lg-card-sub">{{ selectedProject.name }}</div>
            </div>
          </div>
          <div class="pml-filter-grid">
            <div>
              <label class="pml-field-label">{{ $t('Item') }}</label>
              <auto-complete
                v-model="form.item_id"
                :suggestions="availableItems"
                :clearable="true"
                :placeholder="$t('All Items')"
                class="pml-autocomplete"
              />
            </div>
            <div>
              <label class="pml-field-label">{{ $t('Category') }}</label>
              <auto-complete
                v-model="form.category_id"
                :suggestions="availableCategories"
                :clearable="true"
                :placeholder="$t('All Categories')"
                class="pml-autocomplete"
              />
            </div>
            <div>
              <label class="pml-field-label">{{ $t('Transaction Type') }}</label>
              <css-select v-model="form.transaction_type" class="pml-select">
                <option :value="null">{{ $t('All Transactions') }}</option>
                <option value="checkin">{{ $t('Checked In') }}</option>
                <option value="checkout">{{ $t('Checked Out') }}</option>
                <option value="transfer_in">{{ $t('Transfer In') }}</option>
                <option value="transfer_out">{{ $t('Transfer Out') }}</option>
              </css-select>
            </div>
            <div>
              <label class="pml-field-label">{{ $t('Status') }}</label>
              <css-select v-model="form.status" class="pml-select">
                <option :value="null">{{ $t('All Statuses') }}</option>
                <option value="completed">{{ $t('Completed') }}</option>
                <option value="draft">{{ $t('Draft') }}</option>
              </css-select>
            </div>
            <div>
              <label class="pml-field-label">{{ $t('Responsible Person') }}</label>
              <css-select v-model="form.user_id" class="pml-select">
                <option :value="null">{{ $t('Anyone') }}</option>
                <option v-for="u in availableUsers" :key="u.id" :value="u.id">{{ u.name }}</option>
              </css-select>
            </div>
            <div>
              <label class="pml-field-label">{{ $t('Supplier / Contact') }}</label>
              <css-select v-model="form.contact_id" class="pml-select">
                <option :value="null">{{ $t('Anyone') }}</option>
                <option v-for="c in availableContacts" :key="c.id" :value="c.id">{{ c.name }}</option>
              </css-select>
            </div>
          </div>

          <div class="flex flex-wrap items-end gap-3 mt-4">
            <date-picker v-model="form.date_from" :label="$t('From')" :disabled="form.entire_project" />
            <date-picker v-model="form.date_to" :label="$t('To')" :disabled="form.entire_project" />
            <label class="pml-entire-toggle">
              <input type="checkbox" v-model="form.entire_project" class="sr-only" />
              <span class="pml-entire-check"><icons name="tick" class="w-3 h-3" /></span>
              {{ $t('Entire project (no date limit)') }}
            </label>
            <div class="flex-1 min-w-[180px]">
              <label class="pml-field-label">{{ $t('Search') }}</label>
              <div class="relative">
                <icons name="search" class="w-4 h-4 pml-search-ico absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
                <input v-model="form.search" type="text" :placeholder="$t('Reference, item, from, to, responsible…')" class="pml-search-input" @keyup.enter="applyFilters" />
              </div>
            </div>
            <div class="flex items-center gap-2">
              <button type="button" class="lg-btn lg-btn-primary" :disabled="loading" @click="applyFilters">
                <icons name="search" class="w-4 h-4" />
                {{ $t('Apply Filters') }}
              </button>
              <button type="button" class="lg-btn lg-btn-soft" @click="resetFilters">
                <icons name="refresh" class="w-3.5 h-3.5" />
                {{ $t('Reset') }}
              </button>
            </div>
          </div>
        </div>

        <!-- Summary tiles -->
        <stat-tiles v-if="summary" :items="statItems" />

        <!-- Tabs + quick filter -->
        <div class="pml-toolbar print:hidden">
          <div class="flex flex-wrap items-center gap-1.5">
            <button type="button" class="pml-tab" :class="{ 'pml-tab-active': activeTab === 'ledger' }" @click="activeTab = 'ledger'">{{ $t('Ledger') }}</button>
            <button type="button" class="pml-tab" :class="{ 'pml-tab-active': activeTab === 'item_summary' }" @click="activeTab = 'item_summary'">{{ $t('Item Summary') }}</button>
            <button type="button" class="pml-tab" :class="{ 'pml-tab-active': activeTab === 'movement_summary' }" @click="activeTab = 'movement_summary'">{{ $t('Source / Destination') }}</button>
            <button type="button" class="pml-tab" :class="{ 'pml-tab-active': activeTab === 'supplier_summary' }" @click="activeTab = 'supplier_summary'">{{ $t('Supplier Summary') }}</button>
            <button type="button" class="pml-tab" :class="{ 'pml-tab-active': activeTab === 'reconciliation' }" @click="activeTab = 'reconciliation'">{{ $t('Stock Reconciliation') }}</button>
            <button v-if="boq.length" type="button" class="pml-tab" :class="{ 'pml-tab-active': activeTab === 'boq' }" @click="activeTab = 'boq'">{{ $t('BOQ') }}</button>
          </div>
          <!-- Quick filter over the currently visible result table -->
          <div class="relative pml-quick">
            <icons name="search" class="w-4 h-4 pml-search-ico absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input v-model="quickFilter" type="text" :placeholder="$t('Quick filter this table…')" class="pml-search-input" />
          </div>
        </div>

        <!-- Ledger table -->
        <div v-if="activeTab === 'ledger'">
          <div class="lg-table-wrap pml-scroll">
            <table class="lg-table lg-responsive pml-sticky">
              <thead>
                <tr>
                  <th class="is-sortable" @click="sortBy('date')"><span class="lg-sort">{{ $t('Date') }} <icons v-if="form.sort_by === 'date'" name="chevron-down" class="w-3 h-3 inline-block align-middle transition-transform" :class="{ 'rotate-180': form.sort_dir === 'asc' }" /></span></th>
                  <th class="is-sortable" @click="sortBy('transaction_type')">{{ $t('Type') }}</th>
                  <th>{{ $t('Reference') }}</th>
                  <th class="is-sortable" @click="sortBy('item_name')">{{ $t('Item') }}</th>
                  <th class="num is-sortable" @click="sortBy('quantity')">{{ $t('Quantity') }}</th>
                  <th class="text-center">{{ $t('Unit') }}</th>
                  <th class="num">{{ $t('Unit Value') }}</th>
                  <th class="num is-sortable" @click="sortBy('total_value')">{{ $t('Total Value') }}</th>
                  <th>{{ $t('From') }}</th>
                  <th>{{ $t('To') }}</th>
                  <th>{{ $t('Responsible') }}</th>
                  <th>{{ $t('Status') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in filteredLedgerRows" :key="`${row.transaction_key}-${row.transaction_id}-${row.item_id}`" class="is-click" @click="openTransaction(row)">
                  <td :data-label="$t('Date')">{{ row.date }}</td>
                  <td :data-label="$t('Type')"><span class="lg-chip" :class="typeChip(row.transaction_key)">{{ $t(row.transaction_type) }}</span></td>
                  <td :data-label="$t('Reference')" class="lg-mono">{{ row.reference }}</td>
                  <td :data-label="$t('Item')">
                    <div class="lg-main">{{ row.item_name }}</div>
                    <div v-if="row.item_code" class="lg-subtext">{{ row.item_code }}</div>
                  </td>
                  <td class="num" :data-label="$t('Quantity')">{{ formatQty(row.quantity) }}</td>
                  <td class="text-center" :data-label="$t('Unit')"><span v-if="row.unit" class="lg-chip lg-chip-slate">{{ row.unit }}</span></td>
                  <td class="num" :data-label="$t('Unit Value')">{{ formatMoney(row.unit_value) }}</td>
                  <td class="num lg-main" :data-label="$t('Total Value')">{{ formatMoney(row.total_value) }}</td>
                  <td :data-label="$t('From')">{{ row.from }}</td>
                  <td :data-label="$t('To')">{{ row.to }}</td>
                  <td :data-label="$t('Responsible')">{{ row.responsible_person }}</td>
                  <td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(row.status)">{{ $t(row.status) }}</span></td>
                </tr>
                <tr v-if="!filteredLedgerRows.length" class="lg-empty-row">
                  <td class="lg-empty" colspan="12">{{ $t('No material movement found for the selected filters.') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="!quickFilter && ledger && ledger.last_page > 1" class="pml-pager print:hidden">
            <span class="lg-subtext">{{ $t('Page') }} {{ ledger.current_page }} {{ $t('of') }} {{ ledger.last_page }} ({{ ledger.total }} {{ $t('total') }})</span>
            <div class="flex items-center gap-2">
              <button type="button" class="lg-btn lg-btn-soft" :disabled="ledger.current_page <= 1" @click="goToPage(ledger.current_page - 1)">{{ $t('Previous') }}</button>
              <button type="button" class="lg-btn lg-btn-soft" :disabled="ledger.current_page >= ledger.last_page" @click="goToPage(ledger.current_page + 1)">{{ $t('Next') }}</button>
            </div>
          </div>
        </div>

        <!-- Item summary -->
        <div v-if="activeTab === 'item_summary'" class="lg-table-wrap pml-scroll">
          <table class="lg-table lg-responsive pml-sticky">
            <thead>
              <tr>
                <th>{{ $t('Item') }}</th>
                <th class="text-center">{{ $t('Unit') }}</th>
                <th class="num">{{ $t('Checked In') }}</th>
                <th class="num">{{ $t('Checked Out') }}</th>
                <th class="num">{{ $t('Transfer In') }}</th>
                <th class="num">{{ $t('Transfer Out') }}</th>
                <th class="num">{{ $t('Net Movement') }}</th>
                <th class="num">{{ $t('Total Value') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in filteredItemSummary" :key="`${row.item_id}-${row.unit}`">
                <td :data-label="$t('Item')">
                  <div class="lg-main">{{ row.item_name }}</div>
                  <div v-if="row.item_code" class="lg-subtext">{{ row.item_code }}</div>
                </td>
                <td class="text-center" :data-label="$t('Unit')"><span v-if="row.unit" class="lg-chip lg-chip-slate">{{ row.unit }}</span></td>
                <td class="num pml-c-in" :data-label="$t('Checked In')">{{ formatQty(row.checked_in) }}</td>
                <td class="num pml-c-out" :data-label="$t('Checked Out')">{{ formatQty(row.checked_out) }}</td>
                <td class="num pml-c-tin" :data-label="$t('Transfer In')">{{ formatQty(row.transfer_in) }}</td>
                <td class="num pml-c-tout" :data-label="$t('Transfer Out')">{{ formatQty(row.transfer_out) }}</td>
                <td class="num lg-main" :class="{ 'pml-c-bad': row.net_movement < 0 }" :data-label="$t('Net Movement')">{{ row.net_movement >= 0 ? '+' : '' }}{{ formatQty(row.net_movement) }}</td>
                <td class="num lg-main" :data-label="$t('Total Value')">{{ formatMoney(row.total_value) }}</td>
              </tr>
              <tr v-if="!filteredItemSummary.length" class="lg-empty-row"><td class="lg-empty" colspan="8">{{ $t('No data for the selected filters.') }}</td></tr>
            </tbody>
          </table>
        </div>

        <!-- Movement summary -->
        <div v-if="activeTab === 'movement_summary'" class="lg-table-wrap pml-scroll">
          <table class="lg-table lg-responsive pml-sticky">
            <thead>
              <tr>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('Item') }}</th>
                <th>{{ $t('From') }}</th>
                <th>{{ $t('To') }}</th>
                <th>{{ $t('Type') }}</th>
                <th class="num">{{ $t('Quantity') }}</th>
                <th class="text-center">{{ $t('Unit') }}</th>
                <th class="num">{{ $t('Value') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in filteredMovementSummary" :key="`${row.transaction_key}-${row.transaction_id}-${row.item_id}`">
                <td :data-label="$t('Reference')">
                  <button type="button" class="pml-ref-link" @click="openTransaction(row)">{{ row.reference }}</button>
                </td>
                <td :data-label="$t('Item')">
                  <div class="lg-main">{{ row.item_name }}</div>
                  <div v-if="row.item_code" class="lg-subtext">{{ row.item_code }}</div>
                </td>
                <td :data-label="$t('From')">{{ row.from }}</td>
                <td :data-label="$t('To')">{{ row.to }}</td>
                <td :data-label="$t('Type')"><span class="lg-chip" :class="typeChip(row.transaction_key)">{{ $t(row.transaction_type) }}</span></td>
                <td class="num" :data-label="$t('Quantity')">{{ formatQty(row.quantity) }}</td>
                <td class="text-center" :data-label="$t('Unit')"><span v-if="row.unit" class="lg-chip lg-chip-slate">{{ row.unit }}</span></td>
                <td class="num lg-main" :data-label="$t('Value')">{{ formatMoney(row.value) }}</td>
              </tr>
              <tr v-if="!filteredMovementSummary.length" class="lg-empty-row"><td class="lg-empty" colspan="8">{{ $t('No data for the selected filters.') }}</td></tr>
            </tbody>
          </table>
        </div>

        <!-- Supplier summary -->
        <div v-if="activeTab === 'supplier_summary'" class="lg-table-wrap pml-scroll">
          <table class="lg-table lg-responsive pml-sticky">
            <thead>
              <tr>
                <th>{{ $t('Supplier') }} / {{ $t('Item') }}</th>
                <th class="num">{{ $t('Quantity') }}</th>
                <th class="text-center">{{ $t('Unit') }}</th>
                <th class="num">{{ $t('Value') }}</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="row in filteredSupplierSummary" :key="`${row.contact_id}-${row.unit}`">
                <tr class="pml-supplier-group-row">
                  <td class="lg-main" :data-label="$t('Supplier')">
                    {{ row.supplier_name }}
                    <span class="pml-supplier-item-count">({{ row.item_count }} {{ row.item_count === 1 ? $t('item') : $t('items') }})</span>
                  </td>
                  <td class="num lg-main" :data-label="$t('Quantity')">{{ formatQty(row.quantity) }}</td>
                  <td class="text-center" :data-label="$t('Unit')"><span v-if="row.unit" class="lg-chip lg-chip-slate">{{ row.unit }}</span></td>
                  <td class="num lg-main" :data-label="$t('Value')">{{ formatMoney(row.value) }}</td>
                </tr>
                <tr v-for="item in row.items" :key="`${row.contact_id}-${row.unit}-${item.item_id}`" class="pml-supplier-item-row">
                  <td :data-label="$t('Item')">
                    <span class="pml-supplier-item-name">{{ item.item_name }}</span>
                    <span v-if="item.item_code" class="lg-subtext">({{ item.item_code }})</span>
                  </td>
                  <td class="num" :data-label="$t('Quantity')">{{ formatQty(item.quantity) }}</td>
                  <td class="text-center pml-c-mute" :data-label="$t('Unit')">{{ row.unit }}</td>
                  <td class="num" :data-label="$t('Value')">{{ formatMoney(item.value) }}</td>
                </tr>
              </template>
              <tr v-if="!filteredSupplierSummary.length" class="lg-empty-row"><td class="lg-empty" colspan="4">{{ $t('No supplier deliveries for the selected filters.') }}</td></tr>
            </tbody>
          </table>
        </div>

        <!-- Stock reconciliation -->
        <div v-if="activeTab === 'reconciliation'">
          <div class="lg-note lg-note-blue mb-3">
            <icons name="info" class="w-4 h-4" />
            <span>{{ $t('Opening/closing figures are derived from the current live stock balance and this ledger\'s own movement totals. Variance is only shown when the date range reaches today.') }}</span>
          </div>
          <div class="lg-table-wrap pml-scroll">
            <table class="lg-table lg-responsive pml-sticky">
              <thead>
                <tr>
                  <th>{{ $t('Item') }}</th>
                  <th class="text-center">{{ $t('Unit') }}</th>
                  <th class="num">{{ $t('Opening Stock') }}</th>
                  <th class="num">{{ $t('Period Movement') }}</th>
                  <th class="num">{{ $t('Expected Closing') }}</th>
                  <th class="num">{{ $t('Actual Current Stock') }}</th>
                  <th class="num">{{ $t('Variance') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in filteredReconciliation" :key="row.item_id">
                  <td :data-label="$t('Item')">
                    <div class="lg-main">{{ row.item_name }}</div>
                    <div v-if="row.item_code" class="lg-subtext">{{ row.item_code }}</div>
                  </td>
                  <td class="text-center" :data-label="$t('Unit')"><span v-if="row.unit" class="lg-chip lg-chip-slate">{{ row.unit }}</span></td>
                  <td class="num" :data-label="$t('Opening Stock')">{{ formatQty(row.opening_stock) }}</td>
                  <td class="num" :class="row.period_movement < 0 ? 'pml-c-bad' : 'pml-c-in'" :data-label="$t('Period Movement')">{{ row.period_movement >= 0 ? '+' : '' }}{{ formatQty(row.period_movement) }}</td>
                  <td class="num lg-main" :data-label="$t('Expected Closing')">{{ formatQty(row.expected_closing) }}</td>
                  <td class="num" :data-label="$t('Actual Current Stock')">{{ row.actual_current_stock === null ? '—' : formatQty(row.actual_current_stock) }}</td>
                  <td class="num lg-main" :class="row.variance === null ? 'pml-c-mute' : (Math.abs(row.variance) > 0.001 ? 'pml-c-bad' : 'pml-c-in')" :data-label="$t('Variance')">
                    {{ row.variance === null ? $t('N/A') : (row.variance >= 0 ? '+' : '') + formatQty(row.variance) }}
                  </td>
                </tr>
                <tr v-if="!filteredReconciliation.length" class="lg-empty-row"><td class="lg-empty" colspan="7">{{ $t('No items to reconcile for the selected filters.') }}</td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- BOQ tie-in -->
        <div v-if="activeTab === 'boq' && boq.length">
          <div class="lg-note lg-note-blue mb-3">
            <icons name="info" class="w-4 h-4" />
            <span>{{ $t('Matches the existing BOQ Material Compliance report exactly. Checkout quantities only.') }}</span>
          </div>
          <div class="lg-table-wrap pml-scroll">
            <table class="lg-table lg-responsive pml-sticky">
              <thead>
                <tr>
                  <th>{{ $t('BOQ Item') }}</th>
                  <th class="text-center">{{ $t('Unit') }}</th>
                  <th class="num">{{ $t('BOQ Quantity') }}</th>
                  <th class="num">{{ $t('Used Quantity') }}</th>
                  <th class="num">{{ $t('Remaining') }}</th>
                  <th class="num">{{ $t('Material Value') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in filteredBoq" :key="row.item_id">
                  <td :data-label="$t('BOQ Item')">
                    <div class="lg-main">{{ row.item_name }}</div>
                    <div v-if="row.item_code" class="lg-subtext">{{ row.item_code }}</div>
                  </td>
                  <td class="text-center" :data-label="$t('Unit')"><span v-if="row.unit" class="lg-chip lg-chip-slate">{{ row.unit }}</span></td>
                  <td class="num" :data-label="$t('BOQ Quantity')">{{ formatQty(row.boq_quantity) }}</td>
                  <td class="num" :data-label="$t('Used Quantity')">{{ formatQty(row.used_quantity) }}</td>
                  <td class="num lg-main" :class="{ 'pml-c-bad': row.remaining_quantity < 0 }" :data-label="$t('Remaining')">{{ formatQty(row.remaining_quantity) }}</td>
                  <td class="num lg-main" :data-label="$t('Material Value')">{{ formatMoney(row.material_value) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>

      <div v-else class="lg-card text-center py-14">
        <span class="lg-card-ico lg-ico-slate mx-auto mb-3"><icons name="clip" class="w-5 h-5" /></span>
        <p class="lg-card-sub">{{ $t('Select a project above to view its material ledger.') }}</p>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, AutoComplete, DatePicker, PageHeader, StatTiles },

  props: {
    filters: { type: Object, required: true },
    projects: { type: Array, default: () => [] },
    availableItems: { type: Array, default: () => [] },
    availableCategories: { type: Array, default: () => [] },
    availableUsers: { type: Array, default: () => [] },
    availableContacts: { type: Array, default: () => [] },
    summary: { type: Object, default: null },
    ledger: { type: Object, default: null },
    itemSummary: { type: Array, default: () => [] },
    movementSummary: { type: Array, default: () => [] },
    supplierSummary: { type: Array, default: () => [] },
    reconciliation: { type: Array, default: () => [] },
    boq: { type: Array, default: () => [] },
  },

  data() {
    return {
      form: { ...this.filters },
      activeTab: this.filters.view || 'ledger',
      loading: false,
      quickFilter: '',
      projectSearch: '',
    };
  },

  computed: {
    // Tiles re-present the server-computed summary; nothing is recalculated.
    statItems() {
      const s = this.summary;
      if (!s) return [];
      return [
        { icon: 'down', tone: 'emerald', label: this.$t('Checked In'), value: 'Tsh ' + this.formatMoney(s.checked_in.value) },
        { icon: 'up', tone: 'rose', label: this.$t('Checked Out'), value: 'Tsh ' + this.formatMoney(s.checked_out.value) },
        { icon: 'in', tone: 'sky', label: this.$t('Transfer In'), value: 'Tsh ' + this.formatMoney(s.transfer_in.value) },
        { icon: 'out', tone: 'amber', label: this.$t('Transfer Out'), value: 'Tsh ' + this.formatMoney(s.transfer_out.value) },
        { icon: 'cash', tone: 'navy', label: this.$t('Total Material Value'), value: 'Tsh ' + this.formatMoney(s.grand_total_value) },
      ];
    },
    selectedProject() {
      return this.projects.find(p => p.id === this.form.project_warehouse_id) || null;
    },
    filteredProjects() {
      const q = this.projectSearch.trim().toLowerCase();
      if (!q) {
        return this.projects;
      }
      return this.projects.filter(p => p.name.toLowerCase().includes(q));
    },
    printUrl() {
      return this.route('project-material-ledger.index', { ...this.buildParams(this.exportOverrides()), output: 'print' });
    },
    downloadUrl() {
      return this.route('project-material-ledger.index', { ...this.buildParams(this.exportOverrides()), output: 'pdf' });
    },
    quickFilterQuery() {
      return this.quickFilter.trim().toLowerCase();
    },
    filteredLedgerRows() {
      const rows = this.ledger ? this.ledger.data : [];
      if (!this.quickFilterQuery) return rows;
      return rows.filter(r => [r.reference, r.item_name, r.item_code, r.from, r.to, r.responsible_person, r.transaction_type].some(v => (v || '').toLowerCase().includes(this.quickFilterQuery)));
    },
    filteredItemSummary() {
      if (!this.quickFilterQuery) return this.itemSummary;
      return this.itemSummary.filter(r => [r.item_name, r.item_code].some(v => (v || '').toLowerCase().includes(this.quickFilterQuery)));
    },
    filteredMovementSummary() {
      if (!this.quickFilterQuery) return this.movementSummary;
      return this.movementSummary.filter(r => [r.from, r.to, r.transaction_type, r.reference, r.item_name, r.item_code].some(v => (v || '').toLowerCase().includes(this.quickFilterQuery)));
    },
    filteredSupplierSummary() {
      if (!this.quickFilterQuery) return this.supplierSummary;
      return this.supplierSummary.filter(r => [r.supplier_name, ...(r.items || []).map(i => i.item_name)].some(v => (v || '').toLowerCase().includes(this.quickFilterQuery)));
    },
    filteredReconciliation() {
      if (!this.quickFilterQuery) return this.reconciliation;
      return this.reconciliation.filter(r => [r.item_name, r.item_code].some(v => (v || '').toLowerCase().includes(this.quickFilterQuery)));
    },
    filteredBoq() {
      if (!this.quickFilterQuery) return this.boq;
      return this.boq.filter(r => [r.item_name, r.item_code].some(v => (v || '').toLowerCase().includes(this.quickFilterQuery)));
    },
  },

  watch: {
    activeTab() {
      this.quickFilter = '';
    },
  },

  methods: {
    // Print/Download must match what's actually on screen: the active tab
    // (form.view only reflects the tab at last page load/filter-apply, not
    // client-side tab clicks) and the quick filter (client-side only, so
    // the server-side `search` filter alone isn't enough once it has
    // narrowed the currently visible table further than that did).
    exportOverrides() {
      const overrides = { view: this.activeTab };
      if (this.quickFilter.trim()) overrides.search = this.quickFilter.trim();
      return overrides;
    },
    buildParams(overrides = {}) {
      const params = {};
      Object.entries({ ...this.form, ...overrides }).forEach(([key, value]) => {
        if (value !== null && value !== '' && value !== false) {
          params[key] = value === true ? 1 : value;
        }
      });
      return params;
    },
    reload(overrides = {}) {
      this.quickFilter = '';
      this.loading = true;
      this.$inertia.get(this.route('project-material-ledger.index'), this.buildParams(overrides), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onFinish: () => { this.loading = false; },
      });
    },
    selectProject(id) {
      this.form.project_warehouse_id = id;
      this.reload({ project_warehouse_id: id, page: 1 });
    },
    applyFilters() {
      this.reload({ page: 1 });
    },
    resetFilters() {
      const projectId = this.form.project_warehouse_id;
      this.form = {
        project_warehouse_id: projectId, item_id: null, category_id: null, transaction_type: null,
        user_id: null, contact_id: null, status: null, date_from: null, date_to: null,
        entire_project: false, search: '', sort_by: 'date', sort_dir: 'desc', page: 1, view: this.activeTab,
      };
      this.reload();
    },
    sortBy(column) {
      if (this.form.sort_by === column) {
        this.form.sort_dir = this.form.sort_dir === 'asc' ? 'desc' : 'asc';
      } else {
        this.form.sort_by = column;
        this.form.sort_dir = 'desc';
      }
      this.reload();
    },
    goToPage(page) {
      this.form.page = page;
      this.reload({ page });
    },
    openTransaction(row) {
      window.open(this.route('project-material-ledger.show', [row.transaction_key === 'transfer_in' || row.transaction_key === 'transfer_out' ? 'transfer' : row.transaction_key, row.transaction_id]), '_blank');
    },
    typeChip(key) {
      return { checkin: 'lg-chip-green', checkout: 'lg-chip-red', transfer_in: 'lg-chip-blue', transfer_out: 'lg-chip-amber' }[key] || 'lg-chip-slate';
    },
    statusChip(status) {
      const s = String(status || '').toLowerCase();
      if (s.includes('draft')) return 'lg-chip-amber';
      if (s.includes('complet')) return 'lg-chip-green';
      return 'lg-chip-slate';
    },
    formatMoney(value) {
      return Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    formatQty(value) {
      const n = Number(value || 0);
      return n % 1 === 0 ? n.toLocaleString() : n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
  },
};
</script>

<style scoped>
.pml-search-ico { color: var(--lg-faint); }
.pml-field-label { display: block; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); margin-bottom: 4px; }

/* Project picker ----------------------------------------------------- */
.pml-project-grid { display: grid; grid-template-columns: repeat(1, minmax(0, 1fr)); gap: 8px; max-height: 220px; overflow-y: auto; padding: 2px; }
@media (min-width: 640px) { .pml-project-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (min-width: 1024px) { .pml-project-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
.pml-project-chip { display: flex; align-items: center; gap: 8px; text-align: left; padding: 9px 12px; border-radius: 12px; background: var(--lg-soft); border: 1px solid var(--lg-line); font-size: 0.82rem; font-weight: 700; color: var(--lg-ink); transition: all 0.15s ease; }
.pml-project-chip:hover { border-color: #c7d2fe; }
.pml-project-chip-active { background: rgba(99, 102, 241, 0.11); border-color: #a5b4fc; color: #4338ca; }
html.dark .pml-project-chip-active { color: #c7d2fe; }
.pml-project-chip-icon { color: var(--lg-faint); }
.pml-project-chip-active .pml-project-chip-icon { color: #6366f1; }
.pml-project-chip-status { display: block; font-size: 0.68rem; font-weight: 600; color: var(--lg-faint); margin-top: 1px; }

/* backdrop-less card, but the Item dropdown (z-index 9999) still has to
   paint above the siblings that follow (tiles, tabs, tables). */
.pml-filters-panel { position: relative; z-index: 30; }

.pml-filter-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr)); }
.pml-filter-grid > * { min-width: 0; }

.pml-select { width: 100%; color: var(--lg-ink); border: 1px solid var(--lg-line); border-radius: 0.7rem; padding: 0.5rem 0.7rem; font-size: 0.82rem; background: var(--lg-soft); }
.pml-select:focus { outline: none; border-color: #a5b4fc; box-shadow: 0 0 0 3px rgba(165, 180, 252, 0.3); }
.pml-select:disabled { opacity: 0.5; }
.pml-search-input { width: 100%; color: var(--lg-ink); border: 1px solid var(--lg-line); border-radius: 0.7rem; padding: 0.5rem 0.7rem 0.5rem 2.25rem; font-size: 0.82rem; background: var(--lg-soft); }
.pml-search-input:focus { outline: none; border-color: #a5b4fc; box-shadow: 0 0 0 3px rgba(165, 180, 252, 0.3); }

.pml-autocomplete :deep(input) { border-radius: 0.7rem !important; border-color: var(--lg-line) !important; padding: 0.5rem 0.7rem !important; font-size: 0.82rem !important; }
.pml-autocomplete :deep(input:focus) { border-color: #a5b4fc !important; box-shadow: 0 0 0 3px rgba(165, 180, 252, 0.3) !important; }

/* position:relative — contains an .sr-only checkbox; see Role/Form.vue's .permission-chip for why this matters (offsetParent otherwise falls through to <body>). */
.pml-entire-toggle { position: relative; display: inline-flex; align-items: center; gap: 7px; font-size: 0.78rem; font-weight: 700; color: var(--lg-ink); cursor: pointer; padding-bottom: 9px; }
.pml-entire-check { display: inline-flex; align-items: center; justify-content: center; height: 17px; width: 17px; border-radius: 5px; border: 1.5px solid #cbd5e1; color: transparent; flex: none; transition: all 0.15s ease; }
.pml-entire-toggle:has(input:checked) .pml-entire-check { background: #4f46e5; border-color: #4f46e5; color: #fff; }

/* Tabs --------------------------------------------------------------- */
.pml-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
.pml-quick { flex: 1 1 220px; max-width: 320px; }
.pml-tab { display: inline-flex; align-items: center; gap: 5px; padding: 6px 14px; border-radius: 9999px; font-size: 0.78rem; font-weight: 700; color: var(--lg-mute); background: var(--lg-card); border: 1px solid var(--lg-line); transition: all 0.15s ease; }
.pml-tab:hover { border-color: #c7d2fe; color: #4338ca; }
.pml-tab-active, .pml-tab-active:hover { background: linear-gradient(145deg, #2f6fb3, #0f2540); border-color: transparent; color: #fff; box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.9); }

/* Tables ------------------------------------------------------------- */
.pml-sticky th { position: sticky; top: 0; z-index: 2; background-color: #f1f5f9; }
html.dark .pml-sticky th { background-color: #17213a; }
@media (min-width: 768px) { .pml-scroll { max-height: 68vh; overflow: auto; } }

.pml-pager { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-top: 12px; }

.pml-c-in { color: #047857; } .pml-c-out { color: #be123c; } .pml-c-tin { color: #1d4ed8; } .pml-c-tout { color: #b45309; }
.pml-c-bad { color: #be123c; } .pml-c-mute { color: var(--lg-faint); }
html.dark .pml-c-in { color: #6ee7b7; } html.dark .pml-c-out, html.dark .pml-c-bad { color: #fda4af; }
html.dark .pml-c-tin { color: #93c5fd; } html.dark .pml-c-tout { color: #fcd34d; }

.pml-ref-link { font-weight: 800; color: #4338ca; cursor: pointer; }
.pml-ref-link:hover { text-decoration: underline; }
html.dark .pml-ref-link { color: #a5b4fc; }

.pml-supplier-group-row td { background: var(--lg-soft); }
.pml-supplier-item-count { margin-left: 0.35rem; font-size: 0.72rem; font-weight: 600; color: var(--lg-faint); }
.pml-supplier-item-row td { padding-top: 8px; padding-bottom: 8px; color: var(--lg-mute); }
.pml-supplier-item-name { padding-left: 1.1rem; position: relative; color: var(--lg-ink); }
.pml-supplier-item-name::before { content: '\2013'; position: absolute; left: 0; color: var(--lg-faint); }
@media (max-width: 767.98px) { .pml-supplier-item-name { padding-left: 0; } .pml-supplier-item-name::before { display: none; } }
</style>

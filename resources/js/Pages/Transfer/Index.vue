<template>
  <admin-layout :title="$t('Transfers')">
    <div class="lg-page px-4 md:px-0 txn-shell">
      <page-header icon="truck" :title="$t('Transfers')" :subtitle="$t('Please review the data in the table below')">
        <button v-if="$admin" type="button" class="lg-btn lg-btn-red" @click="openCleanup()">
          <icons name="trash" class="w-4 h-4" />
          <span>{{ $t('Cleanup Warehouse') }}</span>
        </button>
        <offline-lock>
          <Link :href="route('transfers.create')" class="lg-btn lg-btn-primary">
            <icons name="plus" class="w-4 h-4" />
            <span>{{ $t('create_x', { x: $t('Transfer') }) }}</span>
          </Link>
        </offline-lock>
      </page-header>

      <stat-tiles v-if="$connection.status !== 'offline'" :items="statItems" @select="pickTile" />

      <div v-if="$connection.status === 'offline'" class="mb-4 flex items-center gap-2 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 print:hidden">
        <icons name="wifi" class="w-3.5 h-3.5 flex-shrink-0" />
        <span>{{ $t('Showing cached transfers from the last 90 days. Search by reference only.') }} <span v-if="$connection.lastSyncAt">{{ $t('Last synced') }}: {{ $date($connection.lastSyncAt) }} {{ $time($connection.lastSyncAt) }}</span></span>
      </div>

      <div class="txn-toolbar mb-6 flex flex-wrap gap-3 justify-between items-center print:hidden relative z-20">
        <search-filter v-model="form.search" class="w-full sm:max-w-md sm:mr-4 flex-1 min-w-[220px]" @reset="reset">
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
          <auto-complete
            id="draft"
            position="left"
            :label="$t('Draft')"
            v-model="form.draft"
            class="mt-1 w-full"
            :suggestions="[
              { label: $t('All'), value: null },
              { label: $t('Yes'), value: 'yes' },
              { label: $t('No'), value: 'no' },
            ]"
          >
          </auto-complete>
        </search-filter>
      </div>
      <div v-if="$can('deliver-transfers') && selectedIds.length" class="txn-selection-bar mb-4 flex items-center justify-between gap-3 flex-wrap relative z-20 print:hidden">
        <span class="text-sm font-semibold text-emerald-800">
          {{ selectedIds.length }} {{ $t('transfer(s) selected') }}
        </span>
        <button type="button" class="txn-bulk-receive-btn" :disabled="markingReceived" @click="bulkMarkReceived">
          <icons name="tick" class="w-4 h-4 mr-1" />
          {{ markingReceived ? $t('Marking…') : $t('Mark Selected as Received') }}
        </button>
      </div>
      <div class="txn-table-shell liquid-table-shell -mx-4 md:mx-0 overflow-x-auto glass-panel glass-table relative z-[1]">
        <table v-labelize class="txn-cards w-full whitespace-nowrap">
          <tr class="text-left font-bold">
            <th v-if="$can('deliver-transfers')" class="px-4 pt-6 pb-4">
              <input
                v-if="eligibleIds.length"
                type="checkbox"
                class="txn-checkbox-glow"
                :checked="allEligibleSelected"
                v-tooltip="$t('Select all eligible transfers on this page')"
                @change="toggleSelectAll"
              />
            </th>
            <th class="px-4 pt-6 pb-4 txn-no-th" data-nolabel>{{ $t('No') }}</th>
            <th class="px-6 pt-6 pb-4">{{ $t('Transfer') }}</th>
            <th class="px-6 pt-6 pb-4" data-nolabel>{{ $t('Relations') }}</th>
            <th class="px-6 pt-6 pb-4">{{ $t('Status') }}</th>
            <th class="px-6 pt-6 pb-4" colspan="2">{{ $t('Details') }}</th>
          </tr>
          <tr
            :key="transfer.id"
            v-for="(transfer, ci) in displayTransfers"
            class="txn-row hover:bg-gray-100 focus-within:bg-gray-100"
            :class="rowClass(transfer)"
          >
            <td v-if="$can('deliver-transfers')" class="border-t px-4 txn-select" @click.stop>
              <input
                v-if="isEligibleForReceiving(transfer)"
                type="checkbox"
                class="txn-checkbox-glow"
                :checked="selectedIds.includes(transfer.id)"
                @change="toggleSelect(transfer.id)"
              />
            </td>
            <td class="border-t txn-no" @click="goto(transfer.id)" :class="{ 'cursor-pointer': $can('read-transfers') }">
              <div class="txn-no-n">{{ rowNo(ci) }}</div>
            </td>
            <td class="border-t txn-title" @click="goto(transfer.id)" :class="{ 'cursor-pointer': $can('read-transfers') }">
              <div class="px-6 py-4 flex items-center focus:text-indigo-500">
                <div>
                  <div class="flex items-center gap-2 flex-wrap">
                    <hover-preview category="transfers" :id="transfer.id"><span class="lg-ref">{{ transfer.reference }}</span></hover-preview>
                    <span v-if="transfer.draft == 1 && !isHeld(transfer)" class="lg-chip lg-chip-amber">{{ $t('Draft') }}</span>
                    <span
                      v-if="transfer.from_is_main"
                      class="lg-chip lg-chip-violet lg-tip tip-left"
                      :data-tip="$t('Leaves a MAIN warehouse: stock only moves after it is approved.')"
                    >
                      <icons name="building" class="w-3 h-3" />{{ $t('Main') }}
                    </span>
                  </div>
                  <div class="lg-subtext">{{ $t('Transaction Date') }}: {{ $date(transfer.date) }}</div>
                  <div class="lg-subtext">{{ $t('Date Created') }}: {{ transfer.created_at ? $datetime(transfer.created_at) : '-' }}</div>
                </div>

                <icons v-if="transfer.deleted_at" name="trash" class="shrink-0 w-4 h-4 text-red-500 ml-2" />
              </div>
            </td>
            <td class="border-t" @click="goto(transfer.id)" :class="{ 'cursor-pointer': $can('read-transfers') }">
              <div class="px-6 py-4">
                <div class="lg-meta">
                  <span class="lg-meta-k rel-to">{{ $t('To') }}</span>
                  <span class="rel-val rel-val-to">{{ transfer.to_warehouse.name }}</span>
                </div>
                <div class="lg-meta">
                  <span class="lg-meta-k rel-from">{{ $t('From') }}</span>
                  <span class="rel-val rel-val-from">{{ transfer.from_warehouse.name }}</span>
                </div>
                <div class="lg-meta">
                  <span class="lg-meta-k rel-user">{{ $t('User') }}</span>
                  <span class="rel-val rel-val-user">{{ transfer.user.name }}</span>
                </div>
                <!-- Approval trail (transfers leaving a main warehouse) -->
                <div v-if="transfer.approval_status === 'approved' && transfer.from_is_main" class="lg-meta">
                  <span class="lg-meta-k rel-approved">{{ $t('Approved by') }}</span>
                  <span class="rel-val rel-val-approved lg-tip tip-left" :data-tip="transfer.approved_at ? $datetime(transfer.approved_at) : $t('Approved before approval tracking began')">
                    <icons name="tick" class="w-3 h-3 inline-block" /> {{ transfer.approved_by_user ? transfer.approved_by_user.name : $t('System (earlier record)') }}
                  </span>
                </div>
                <div v-else-if="transfer.approval_status === 'pending'" class="lg-meta">
                  <span class="lg-meta-k rel-pending">{{ $t('Approval') }}</span>
                  <span class="rel-val rel-val-pending">{{ $t('Waiting for an approver') }}</span>
                </div>
                <div v-else-if="transfer.approval_status === 'cancelled'" class="lg-meta">
                  <span class="lg-meta-k rel-cancelled">{{ $t('Cancelled by') }}</span>
                  <span class="rel-val rel-val-cancelled lg-tip tip-left" :data-tip="transfer.cancel_reason || (transfer.cancelled_at ? $datetime(transfer.cancelled_at) : '')">
                    {{ transfer.cancelled_by_user ? transfer.cancelled_by_user.name : '—' }}
                  </span>
                </div>
              </div>
            </td>
            <td class="border-t" @click="goto(transfer.id)" :class="{ 'cursor-pointer': $can('read-transfers') }">
              <div class="px-6 py-4">
                <span class="txn-status-badge" :class="`txn-status-${statusSlug(transfer)}`">{{ $t(transferStatus(transfer)) }}</span>
                <div v-if="transfer.received_at" class="txn-received-meta">
                  {{ $t('By') }} {{ transfer.received_by_user ? transfer.received_by_user.name : '—' }}
                  <br />
                  {{ $datetime(transfer.received_at) }}
                </div>
              </div>
            </td>
            <td class="border-t max-w-lg min-w-56" @click="goto(transfer.id)" :class="{ 'cursor-pointer': $can('read-transfers') }">
              <div class="px-6 py-4 flex items-center">
                <div class="w-full whitespace-normal line-clamp-3 lg-muted-text">
                  {{ transfer.details }}
                </div>
              </div>
            </td>
            <td class="border-t w-16">
              <div class="px-4 flex items-center print:hidden">
                <div class="txn-action-toolbar flex items-center gap-1.5" v-if="$can(['create-transfers', 'import-transfers', 'export-transfers'])">
                  <Link :href="route('transfers.show', transfer.id)" class="txn-icon-btn lg-tip txn-icon-btn-blue" :data-tip="$t('View')" :aria-label="$t('View')">
                    <icons name="doc"></icons>
                  </Link>
                  <a v-if="$can('print-transfers')" :href="route('transfers.print', transfer.id)" target="_blank" class="txn-icon-btn lg-tip txn-icon-btn-teal" :data-tip="$t('Print')" :aria-label="$t('Print')">
                    <icons name="printer"></icons>
                  </a>
                  <a v-if="$can('print-transfers')" :href="route('transfers.pdf', transfer.id)" class="txn-icon-btn lg-tip txn-icon-btn-cyan" :data-tip="$t('Download')" :aria-label="$t('Download')">
                    <icons name="download"></icons>
                  </a>
                  <template v-if="!isHeld(transfer)">
                    <delivery-note-action compact :state="(deliveryNotes || {})[transfer.id]" />
                    <gate-pass-action compact :state="(gatePasses || {})[transfer.id]" />
                    <transport-route-action compact :state="(transportRoutes || {})[transfer.id]" />
                  </template>
                  <button
                    v-if="transfer.approval_status === 'pending' && $can('approve-transfers')"
                    type="button"
                    class="txn-icon-btn lg-tip txn-icon-btn-green"
                    :data-tip="$t('Approve transfer')"
                    :aria-label="$t('Approve transfer')"
                    @click="approveTransfer(transfer)"
                  >
                    <icons name="tick"></icons>
                  </button>
                  <button
                    v-if="transfer.approval_status === 'pending' && $can('cancel-transfers')"
                    type="button"
                    class="txn-icon-btn lg-tip txn-icon-btn-red"
                    :data-tip="$t('Cancel transfer')"
                    :aria-label="$t('Cancel transfer')"
                    @click="cancelTransfer(transfer)"
                  >
                    <icons name="cross"></icons>
                  </button>
                  <button @click="sendEmail(transfer.id)" class="txn-icon-btn lg-tip txn-icon-btn-indigo" :data-tip="$t('Email')" :aria-label="$t('Email')">
                    <icons name="email"></icons>
                  </button>
                  <Link
                    v-if="$can('update-transfers') && transfer.approval_status !== 'cancelled'"
                    :href="route('transfers.edit', transfer.id)"
                    class="txn-icon-btn lg-tip txn-icon-btn-amber"
                    :data-tip="$t('Edit')" :aria-label="$t('Edit')"
                  >
                    <icons name="edit"></icons>
                  </Link>
                  <template v-if="transfer.deleted_at">
                    <button type="button" @click="restore(transfer)" class="txn-icon-btn lg-tip txn-icon-btn-blue" :data-tip="$t('Restore')" :aria-label="$t('Restore')">
                      <icons name="refresh"></icons>
                    </button>
                    <button type="button" @click="deletePermanently(transfer)" class="txn-icon-btn lg-tip txn-icon-btn-red" :data-tip="$t('Delete Permanently')" :aria-label="$t('Delete Permanently')">
                      <icons name="trash"></icons>
                    </button>
                  </template>
                  <template v-else>
                    <button type="button" @click="destroy(transfer)" class="txn-icon-btn lg-tip txn-icon-btn-red" :data-tip="$t('Delete')" :aria-label="$t('Delete')">
                      <icons name="trash"></icons>
                    </button>
                  </template>
                </div>
              </div>
            </td>
          </tr>
          <tr v-if="displayTransfers.length === 0">
            <td class="border-t px-6 py-4" :colspan="$can('deliver-transfers') ? 7 : 6">{{ $t('There is no data to display.') }}</td>
          </tr>
        </table>
      </div>
      <pagination v-if="$connection.status !== 'offline'" class="mt-6" :meta="transfers.meta" :links="transfers.links" />
    </div>

    <!-- Item Details Modal -->
    <modal :show="details" max-width="4xl" :closeable="true" @close="hideDetails">
      <div class="px-6 py-4 print:px-0">
        <div v-if="details && transfer" class="flex flex-wrap items-center justify-between gap-2 print:hidden">
          <div class="text-lg">
            {{ $t('Transfer Details') }} <span class="hidden sm:inline">({{ transfer.reference }})</span>
          </div>
          <div class="txn-modal-actions flex flex-wrap items-center justify-end gap-2 min-w-0 max-w-full">
            <!-- <button
              @click="print()"
              class="flex items-center justify-center mr-2 h-8 w-8 rounded-full text-gray-600 hover:text-gray-800 hover:bg-gray-300 focus:outline-none"
            >
              <icons name="printer" class="h-5 w-5" />
            </button>
            <Link
              v-if="$can('update-transfers')"
              :href="route('transfers.edit', transfer.id)"
              class="flex items-center justify-center mr-2 h-8 w-8 rounded-full text-gray-600 hover:text-gray-800 hover:bg-gray-300 focus:outline-none"
            >
              <icons name="edit" class="h-5 w-5" />
            </Link> -->
            <button
              v-if="isEligibleForReceiving(transfer) && ['Pending', 'Approved'].includes(transferStatus(transfer))"
              type="button"
              @click="markReceivedInModal()"
              :disabled="markingReceivedModal"
              class="txn-modal-receive-btn mr-2 lg-tip"
              :data-tip="$t('Mark as Received')"
             :aria-label="$t('Mark as Received')">
              <icons name="tick" class="h-3.5 w-3.5 mr-1" />
              {{ markingReceivedModal ? $t('Marking…') : $t('Mark as Received') }}
            </button>
            <delivery-note-action class="mr-2" :state="(deliveryNotes || {})[transfer.id]" />
            <gate-pass-action class="mr-2" :state="(gatePasses || {})[transfer.id]" />
            <transport-route-action class="mr-2" :state="(transportRoutes || {})[transfer.id]" />
            <button
              @click="hideDetails()"
              class="flex items-center justify-center h-8 w-8 rounded-full text-gray-600 hover:text-gray-800 hover:bg-gray-300 focus:outline-none"
            >
              <icons name="cross" class="h-5 w-5" />
            </button>
          </div>
        </div>

        <div class="mt-4 print-mt-0">
          <transfer-details v-if="transfer" :transfer="transfer" />
        </div>
      </div>
    </modal>

    <!-- Delete User Confirmation Modal -->
    <Dialog
      max-width="md"
      :show="permanent"
      action-type="delete"
      title="Delete Transfer!"
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
      title="Delete Transfer!"
      action-text="Delete Transfer"
      :content="$t('Are you sure you want to delete the record?')"
    />

    <!-- Restore Account Confirmation Modal -->
    <Dialog
      :show="restoreConf"
      :action="restoreItem"
      title="Restore Transfer!"
      :close="closeRestoreModal"
      action-text="Restore Transfer"
      :content="$t('Are you sure you want to restore the record?')"
    />

    <!-- Cleanup Confirmation Modal -->
    <Dialog
      :show="cleanup.confirm"
      action-type="delete"
      :title="$t('Cleanup Warehouse')"
      :close="closeCleanupConfirm"
      :action="confirmCleanup"
      :action-text="$t('Cleanup')"
      :content="cleanupConfirmMessage"
    />

    <!-- Cleanup Warehouse Modal -->
    <modal :show="cleanup.show" max-width="4xl" :closeable="!cleanup.form.processing" @close="closeCleanup">
      <div class="px-6 py-4">
        <div class="flex items-center justify-between mb-4">
          <div class="text-lg">{{ $t('Cleanup Warehouse') }}</div>
          <button
            @click="closeCleanup()"
            :disabled="cleanup.form.processing"
            class="flex items-center justify-center h-8 w-8 rounded-full text-gray-600 hover:text-gray-800 hover:bg-gray-300 focus:outline-none disabled:opacity-50"
          >
            <icons name="cross" class="h-5 w-5" />
          </button>
        </div>
        <p class="text-sm text-gray-600 mb-2">
          {{ $t('All items still in stock at the selected warehouse that originally arrived via transfers will be returned to their source warehouses in their remaining quantities.') }}
        </p>
        <p class="mb-4 text-sm font-semibold text-red-600">This action is irreversible and cannot be undone.</p>
        <auto-complete
          clearable
          :label="$t('Warehouse')"
          v-model="cleanup.form.warehouse_id"
          :suggestions="warehouses"
        />
        <div class="mt-4">
          <label class="block text-sm font-medium text-gray-700 mb-1">Your password</label>
          <input
            v-model="cleanup.form.password"
            type="password"
            autocomplete="current-password"
            class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-indigo-300 focus:outline-none focus:ring focus:ring-indigo-200"
            :class="{ 'border-red-500': cleanup.passwordError }"
            placeholder="Password"
            @input="validateCleanupPassword"
          />
          <p v-if="cleanup.passwordChecking" class="mt-2 text-sm text-gray-500">Validating password...</p>
          <p v-else-if="cleanup.passwordError" class="mt-2 text-sm text-red-600">{{ cleanup.passwordError }}</p>
          <p v-else-if="cleanup.passwordValid" class="mt-2 text-sm text-green-600">Password verified.</p>
        </div>
        <div id="cleanup-preview-printable" class="hidden print:block">
          <div class="border-b border-gray-300 pb-4 mb-6">
            <img src="/images/logo.png" alt="Logo" class="h-16 w-auto mb-3" />
            <h1 class="text-2xl font-bold text-gray-900">Cleanup Project(s) / Warehouse(s) Preview Report</h1>
            <div class="mt-2 space-y-1 text-sm text-gray-700">
              <p><span class="font-semibold">Warehouse:</span> {{ selectedWarehouseName }}</p>
              <p><span class="font-semibold">Generated by:</span> {{ currentUserName }}</p>
              <p><span class="font-semibold">Generated date:</span> {{ generatedDate }}</p>
            </div>
          </div>
          <table class="min-w-full text-xs">
            <thead class="bg-gray-50 text-left">
              <tr>
                <th class="px-2 py-1.5">No</th>
                <th class="px-2 py-1.5">Item</th>
                <th class="px-2 py-1.5 text-right">Quantity</th>
                <th class="px-2 py-1.5">Unit</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(item, index) in cleanup.previewItems" :key="item.item_id" class="border-t border-gray-200">
                <td class="px-2 py-1.5">{{ index + 1 }}</td>
                <td class="px-2 py-1.5">{{ item.code ? item.code + ' - ' + item.name : item.name }}</td>
                <td class="px-2 py-1.5 text-right">{{ item.quantity }}</td>
                <td class="px-2 py-1.5">{{ item.unit }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="cleanup.previewLoading" class="mt-4 text-sm text-gray-500">
          Loading preview...
        </div>
        <div v-if="cleanup.lastDistribution.length" class="mt-4 rounded-md border border-green-200 bg-green-50 px-3 py-2 print:hidden">
          <p class="text-sm font-semibold text-green-800 mb-1">
            {{ $t('Transfer created. The following items were moved:') }}
          </p>
          <ul class="text-xs text-green-700 space-y-0.5">
            <li v-for="row in cleanup.lastDistribution" :key="row.item_id">
              {{ row.label }}: {{ row.quantity }} {{ row.unit }} moved to <strong>{{ row.destinationName }}</strong>
            </li>
          </ul>
        </div>
        <div v-if="!cleanup.previewLoading && cleanup.previewItems.length" class="mt-4 print:hidden">
          <div class="flex items-center justify-between mb-2">
            <div class="text-sm font-semibold text-gray-700">Items that will remain in the warehouse</div>
            <div class="flex gap-2">
              <button type="button" class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-indigo-600 hover:bg-gray-200 lg-tip" @click="printCleanupPreview" data-tip="Print report" aria-label="Print report">
                <icons name="printer" class="h-5 w-5" />
              </button>
              <button type="button" class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-indigo-600 hover:bg-gray-200 lg-tip" @click="downloadCleanupPreview" data-tip="Download report" aria-label="Download report">
                <icons name="download" class="h-5 w-5" />
              </button>
            </div>
          </div>
          <input
            v-model="cleanup.itemSearch"
            type="text"
            placeholder="Search items by name or code..."
            class="w-full mb-2 rounded-md border border-gray-300 px-3 py-1.5 text-xs shadow-sm focus:border-indigo-300 focus:outline-none focus:ring focus:ring-indigo-200"
          />
          <div id="cleanup-preview-table" class="max-h-64 overflow-auto border border-gray-200 rounded-md">
            <table class="min-w-full text-xs">
              <thead class="bg-gray-50 text-left">
                <tr>
                  <th class="px-2 py-1.5">No</th>
                  <th class="px-2 py-1.5">Item</th>
                  <th class="px-2 py-1.5 text-right">Quantity</th>
                  <th class="px-2 py-1.5">Unit</th>
                  <th class="px-2 py-1.5 min-w-[20rem]">Destination Warehouse</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(item, index) in filteredDistributeItems" :key="item.item_id" class="border-t border-gray-200">
                  <td class="px-2 py-1.5">{{ index + 1 }}</td>
                  <td class="px-2 py-1.5">{{ item.code ? item.code + ' - ' + item.name : item.name }}</td>
                  <td class="px-2 py-1.5 text-right">{{ item.quantity }}</td>
                  <td class="px-2 py-1.5">{{ item.unit }}</td>
                  <td class="px-2 py-1.5">
                    <auto-complete
                      clearable
                      position="left"
                      v-model="item.to_warehouse_id"
                      :suggestions="destinationWarehouseOptions"
                      placeholder="Choose destination"
                      class="text-xs"
                    />
                  </td>
                </tr>
                <tr v-if="!filteredDistributeItems.length">
                  <td colspan="5" class="px-2 py-4 text-center text-gray-400">No items match your search.</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="unassignedDistributeCount" class="mt-2 text-xs text-amber-600">
            {{ unassignedDistributeCount }} item(s) have no destination warehouse selected and will remain in this warehouse.
          </p>
          <div class="mt-2 flex items-center justify-between">
            <p class="text-xs text-gray-500">These items will stay in the warehouse unless you send them somewhere below.</p>
            <tec-button
              type="button"
              class="bg-indigo-600 hover:bg-indigo-700 shrink-0 ml-3"
              :disabled="cleanup.distributing"
              @click="submitDistribution"
            >
              <span v-if="cleanup.distributing">{{ $t('Processing') }}...</span>
              <span v-else>{{ $t('Create Transfers') }}</span>
            </tec-button>
          </div>
        </div>
        <div v-else-if="cleanup.form.warehouse_id && !cleanup.previewLoading" class="mt-4 rounded-md border border-yellow-200 bg-yellow-50 px-3 py-2 text-sm text-yellow-700">
          This warehouse is empty for cleanup or there is no remaining transferred stock to return.
        </div>
        <div class="flex justify-end mt-6">
          <tec-button type="button" class="bg-gray-200 text-gray-800 hover:bg-gray-300 mr-4" @click="closeCleanup()" :disabled="cleanup.form.processing">
            {{ $t('Cancel') }}
          </tec-button>
          <tec-button
            type="button"
            class="bg-red-600 hover:bg-red-700"
            :disabled="!cleanup.form.warehouse_id || !cleanup.passwordValid || cleanup.form.processing"
            :tabindex="!cleanup.form.warehouse_id || !cleanup.passwordValid || cleanup.form.processing ? -1 : 0"
            @click="handleCleanupClick"
          >
            <span v-if="cleanup.form.processing">{{ $t('Processing') }}...</span>
            <span v-else>{{ $t('Cleanup') }}</span>
          </tec-button>
        </div>
      </div>
    </modal>

    <loading v-if="loading" />
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import Dialog from '@/Shared/Dialog.vue';
import Modal from '@/Jetstream/Modal.vue';
import HoverPreview from '@/Shared/HoverPreview/HoverPreview.vue';
import TransferDetails from './Details.vue';
import TecButton from '@/Jetstream/Button.vue';
import DeliveryNoteAction from '@/Shared/DeliveryNoteAction.vue';
import GatePassAction from '@/Shared/GatePassAction.vue';
import TransportRouteAction from '@/Shared/TransportRouteAction.vue';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import TecDropdown from '@/Jetstream/Dropdown.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import SearchFilter from '@/Shared/SearchFilter.vue';
import TecDropdownLink from '@/Jetstream/DropdownLink.vue';
import TecSectionTitle from '@/Jetstream/SectionTitle.vue';
import { loadIndexData, loadDetail } from '@/Core/offlineDataSource';

export default {
  components: {
    DeliveryNoteAction,
    GatePassAction,
    TransportRouteAction,
    Modal,
    Dialog,
    TecButton,
    Pagination,
    PageHeader,
    StatTiles,
    AdminLayout,
    TecDropdown,
    SelectInput,
    AutoComplete,
    SearchFilter,
    TransferDetails,
    HoverPreview,
    TecDropdownLink,
    TecSectionTitle,
  },

  props: {
    deliveryNotes: { type: Object, default: () => ({}) },
    gatePasses: { type: Object, default: () => ({}) },
    transportRoutes: { type: Object, default: () => ({}) },
    filters: Object,
    stats: { type: Object, default: null },
    transfers: Object,
    warehouses: { type: Array, default: () => [] },
  },

  data() {
    return {
      edit: null,
      close: false,
      transfer: null,
      details: false,
      confirm: false,
      loading: false,
      permanent: false,
      restoreConf: false,
      form: {
        search: this.filters.search,
        trashed: this.filters.trashed,
        view: this.filters.view || null,
      },
      cleanup: {
        show: false,
        confirm: false,
        previewItems: [],
        previewLoading: false,
        passwordValid: false,
        passwordChecking: false,
        passwordError: '',
        form: this.$inertia.form({ warehouse_id: null, password: '' }),
        distributeItems: [],
        distributing: false,
        itemSearch: '',
        lastDistribution: [],
      },
      offlineTransfers: null,
      selectedIds: [],
      markingReceived: false,
      markingReceivedModal: false,
    };
  },

  mounted() {
    if (this.$connection.status === 'offline') {
      this.loadOfflineTransfers();
    }
  },

  computed: {
    statItems() {
      const s = this.stats;
      if (!s) return [];
      // Clickable (and highlighted when applied) while online; the server does the filtering.
      const online = this.$connection.status !== 'offline';
      const tile = (view, icon, tone, label, value) => ({ icon, tone, label, value, view, ...(online ? { active: (this.form.view || null) === view } : {}) });
      return [
        tile(null, 'collection', 'navy', this.$t('Total transfers'), s.total),
        tile('today', 'clock', 'sky', this.$t('Created today'), s.today),
        tile('month', 'calendar', 'indigo', this.$t('This month'), s.month),
        tile('pending', 'truck', 'amber', this.$t('Awaiting receipt'), s.pending),
        tile('received', 'tick', 'emerald', this.$t('Received'), s.received),
        tile('awaiting', 'exclamation', 'pink', this.$t('Awaiting approval'), s.awaiting || 0),
        tile('drafts', 'doc-text', 'slate', this.$t('Drafts'), s.drafts),
      ];
    },
    displayTransfers() {
      if (this.$connection.status === 'offline' && this.offlineTransfers) {
        return this.offlineTransfers;
      }
      return this.transfers.data;
    },
    // Mirrors receive()'s own guards server-side (not draft, not already
    // received) — the warehouse-completed check can't be known client-side,
    // so a stale selection just gets silently skipped by the backend, same
    // as it would for the existing single-row action.
    eligibleIds() {
      return this.displayTransfers.filter(t => this.isEligibleForReceiving(t)).map(t => t.id);
    },
    allEligibleSelected() {
      return this.eligibleIds.length > 0 && this.eligibleIds.every(id => this.selectedIds.includes(id));
    },
    cleanupConfirmMessage() {
      const w = (this.warehouses || []).find(x => x.id === this.cleanup.form.warehouse_id);
      const name = w ? w.name : '';
      return this.$t('Return all remaining transferred stock back to source warehouses?') + (name ? ' (' + name + ')' : '') + '\nThis action is irreversible and cannot be undone.';
    },
    selectedWarehouseName() {
      const w = (this.warehouses || []).find(x => x.id === this.cleanup.form.warehouse_id);
      return w ? w.name : 'Not selected';
    },
    currentUserName() {
      return this.$page && this.$page.props && this.$page.props.auth && this.$page.props.auth.user ? this.$page.props.auth.user.name : 'System';
    },
    generatedDate() {
      return new Date().toLocaleString();
    },
    destinationWarehouseOptions() {
      return (this.warehouses || []).filter(w => w.id !== this.cleanup.form.warehouse_id);
    },
    unassignedDistributeCount() {
      return this.cleanup.distributeItems.filter(i => !i.to_warehouse_id).length;
    },
    filteredDistributeItems() {
      const q = (this.cleanup.itemSearch || '').trim().toLowerCase();
      if (!q) return this.cleanup.distributeItems;
      return this.cleanup.distributeItems.filter(i =>
        (i.name || '').toLowerCase().includes(q) || (i.code || '').toLowerCase().includes(q)
      );
    },
  },

  watch: {
    // Paginating or reloading the list can change which transfers are on
    // screen — a selection carried over from a previous page would silently
    // include ids no longer visible, so it's cleared whenever the visible
    // set changes.
    displayTransfers() {
      this.selectedIds = [];
    },
    'cleanup.form.warehouse_id': function (warehouseId) {
      this.cleanup.passwordValid = false;
      this.cleanup.passwordError = '';
      this.cleanup.itemSearch = '';
      this.cleanup.lastDistribution = [];
      if (!warehouseId) {
        this.cleanup.previewItems = [];
        this.cleanup.distributeItems = [];
        this.cleanup.previewLoading = false;
        return;
      }

      this.fetchCleanupPreview(warehouseId);
    },
    form: {
      handler: throttle(function () {
        if (this.$connection.status === 'offline') {
          this.loadOfflineTransfers();
          return;
        }
        let query = pickBy(this.form);
        this.$inertia.get(
          this.route('transfers.index', Object.keys(query).length ? query : { remember: 'forget' }),
          {},
          {
            onFinish: () => {
              document.getElementById('page-search').focus();
            },
          }
        );
      }, 150),
      deep: true,
    },
  },

  methods: {
    // Clicking a summary card filters the list to it; clicking the lit card (or the Total card) shows everything again.
    pickTile(tile) {
      this.form.view = tile.view && this.form.view !== tile.view ? tile.view : null;
    },
    // Row number across pages (page 2 of 20 per page starts at 21).
    rowNo(index) {
      return (this.$connection.status === 'offline' ? 1 : this.transfers.meta?.from || 1) + index;
    },
    // Same precedence as ProjectMaterialLedgerController's transfer status
    // CASE and the transfers.print blade — a non-draft transfer is only
    // "Pending" until approved_at, and "Approved" until received_at.
    // Waiting for / refused approval: nothing has moved yet, so no paperwork (delivery note, gate pass) applies.
    isHeld(transfer) {
      return transfer.approval_status === 'pending' || transfer.approval_status === 'cancelled';
    },
    statusSlug(transfer) {
      return this.transferStatus(transfer).toLowerCase().replace(/\s+/g, '-');
    },
    rowClass(transfer) {
      if (!transfer.from_is_main) return '';
      return ['tr-main', 'tr-main-' + (transfer.approval_status || 'approved')];
    },
    async approveTransfer(transfer) {
      if (!(await this.$confirm(this.$t('Approve :ref? Stock will move from :from to :to.', { ref: transfer.reference, from: transfer.from_warehouse.name, to: transfer.to_warehouse.name })))) {
        return;
      }
      this.$inertia.post(this.route('transfers.approve', transfer.id), {}, { preserveScroll: true });
    },
    async cancelTransfer(transfer) {
      const reason = await this.$prompt(this.$t('Cancel :ref? You can add a reason (optional).', { ref: transfer.reference }), { variant: 'danger', placeholder: this.$t('Reason (optional)') });
      if (reason === null || reason === false || reason === undefined) {
        return;
      }
      this.$inertia.post(this.route('transfers.cancel', transfer.id), { reason: typeof reason === 'string' ? reason : '' }, { preserveScroll: true });
    },
    transferStatus(transfer) {
      if (transfer.approval_status === 'pending') return 'Awaiting Approval';
      if (transfer.approval_status === 'cancelled') return 'Cancelled';
      if (transfer.draft == 1) return 'Draft';
      if (transfer.received_at) return 'Received';
      // Released transfers (approved, or from a warehouse that needs no approval) wait here until received.
      return 'Approved';
    },
    // Mirrors TransferController::userMayDeliver() — Super Admin/Admin can
    // mark any transfer received; anyone else holding 'deliver-transfers'
    // (Engineers/"Site Engineer" by default) is limited to their own. The
    // backend enforces this regardless, but hiding the checkbox for a
    // transfer a restricted user can't act on avoids a confusing
    // select-then-silently-skipped result.
    isEligibleForReceiving(transfer) {
      if (transfer.draft == 1 || transfer.received_at) return false;
      if (this.$admin) return true;
      const currentUserId = this.$page.props.auth?.user?.id;
      return currentUserId && transfer.user_id === currentUserId;
    },
    toggleSelect(id) {
      const i = this.selectedIds.indexOf(id);
      if (i === -1) {
        this.selectedIds.push(id);
      } else {
        this.selectedIds.splice(i, 1);
      }
    },
    toggleSelectAll() {
      this.selectedIds = this.allEligibleSelected ? [] : [...this.eligibleIds];
    },
    async bulkMarkReceived() {
      if (!this.selectedIds.length || this.markingReceived) return;
      // Plain concatenation, not $t(str, {count}) — vue-i18n interpolates
      // '{count}', not Laravel's ':count' syntax, so passing params through
      // $t() here would render the placeholder literally instead of the
      // actual number.
      const confirmMessage = `${this.selectedIds.length} ${this.$t('transfer(s) will be marked as received. Continue?')}`;
      if (!(await this.$confirm(confirmMessage))) {
        return;
      }

      this.markingReceived = true;
      this.$inertia.patch(
        this.route('transfers.bulk-receive'),
        { transfer_ids: this.selectedIds },
        {
          preserveScroll: true,
          onSuccess: () => { this.selectedIds = []; },
          onFinish: () => { this.markingReceived = false; },
        }
      );
    },
    async loadOfflineTransfers() {
      const result = await loadIndexData('transfers', { search: this.form.search });
      this.offlineTransfers = result.data;
    },
    async goto(id) {
      if (this.transfer && this.transfer.id == id) {
        this.details = true;
        return;
      }

      if (this.$connection.status === 'offline') {
        const cached = await loadDetail('transfers', id);
        if (!cached) {
          return;
        }
        this.transfer = cached;
        this.details = true;
        return;
      }

      this.loading = true;
      axios.get(route('transfers.show', id) + '?json=yes').then(res => {
        this.transfer = res.data;
        this.details = true;
        this.loading = false;
      });
      // if (this.$can('read-transfers')) {
      //   this.$inertia.visit(route('transfers.show', id));
      // }
    },
    showDetails() {
      this.details = false;
    },
    hideDetails() {
      this.details = false;
    },
    markReceivedInModal() {
      if (this.markingReceivedModal || !this.transfer) return;
      this.markingReceivedModal = true;
      const id = this.transfer.id;
      this.$inertia.post(route('transfers.receive', id), {}, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
          axios.get(route('transfers.show', id) + '?json=yes').then(res => { this.transfer = res.data; });
        },
        onFinish: () => { this.markingReceivedModal = false; },
      });
    },
    reset() {
      this.form = mapValues(this.form, () => null);
    },
    sendEmail(id) {
      this.$inertia.get(route('notifications.transfer', id));
    },
    destroy(edit) {
      this.edit = edit;
      this.confirm = true;
    },
    deleteItem() {
      this.$inertia.delete(route('transfers.destroy', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
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
      this.$inertia.put(this.route('transfers.restore', this.edit.id), {
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
      this.$inertia.delete(route('transfers.destroy.permanently', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    closePermanentModal() {
      this.edit = null;
      this.permanent = false;
    },
    print() {
      window.print();
    },
    openCleanup() {
      this.cleanup.form.warehouse_id = null;
      this.cleanup.form.password = '';
      this.cleanup.passwordValid = false;
      this.cleanup.passwordChecking = false;
      this.cleanup.passwordError = '';
      this.cleanup.previewItems = [];
      this.cleanup.distributeItems = [];
      this.cleanup.itemSearch = '';
      this.cleanup.lastDistribution = [];
      this.cleanup.previewLoading = false;
      this.cleanup.show = true;
    },
    closeCleanup() {
      if (this.cleanup.form.processing) return;
      this.cleanup.show = false;
      this.cleanup.previewItems = [];
      this.cleanup.distributeItems = [];
      this.cleanup.itemSearch = '';
      this.cleanup.lastDistribution = [];
      this.cleanup.previewLoading = false;
      this.cleanup.form.password = '';
      this.cleanup.passwordValid = false;
      this.cleanup.passwordChecking = false;
      this.cleanup.passwordError = '';
    },
    fetchCleanupPreview(warehouseId) {
      this.cleanup.previewLoading = true;
      axios.post(this.route('transfers.cleanup.preview'), { warehouse_id: warehouseId })
        .then(res => {
          this.cleanup.previewItems = res.data || [];
          this.cleanup.distributeItems = this.cleanup.previewItems.map(item => ({
            item_id: item.item_id,
            name: item.name,
            code: item.code,
            quantity: item.quantity,
            unit: item.unit,
            unit_id: item.unit_id,
            to_warehouse_id: null,
          }));
        })
        .catch(() => {
          this.cleanup.previewItems = [];
          this.cleanup.distributeItems = [];
        })
        .finally(() => {
          this.cleanup.previewLoading = false;
        });
    },
    async submitDistribution() {
      if (this.cleanup.distributing) return;

      const selectedRows = this.cleanup.distributeItems.filter(i => i.to_warehouse_id);
      const items = selectedRows.map(i => ({
        item_id: i.item_id,
        quantity: i.quantity,
        unit_id: i.unit_id,
        to_warehouse_id: i.to_warehouse_id,
      }));

      if (!items.length) {
        await this.$alert('Please select a destination warehouse for at least one item before creating transfers.');
        return;
      }

      if (!this.cleanup.form.password) {
        await this.$alert('Please enter your password to confirm creating these transfers.');
        return;
      }

      if (!this.cleanup.passwordValid) {
        await this.$alert(this.cleanup.passwordChecking
          ? 'Still verifying your password. Please wait a moment and try again.'
          : (this.cleanup.passwordError || 'The password you entered is incorrect.'));
        return;
      }

      if (this.unassignedDistributeCount > 0) {
        const proceed = await this.$confirm(
          `${this.unassignedDistributeCount} item(s) have no destination warehouse selected and will remain in this warehouse. Continue with the ${items.length} item(s) you did assign?`
        );
        if (!proceed) return;
      }

      this.cleanup.distributing = true;
      axios.post(this.route('transfers.cleanup.distribute'), {
        warehouse_id: this.cleanup.form.warehouse_id,
        items,
      })
        .then(() => {
          this.cleanup.lastDistribution = selectedRows.map(row => {
            const destination = (this.warehouses || []).find(w => w.id === row.to_warehouse_id);
            return {
              item_id: row.item_id,
              label: row.code ? `${row.code} - ${row.name}` : row.name,
              quantity: row.quantity,
              unit: row.unit,
              destinationName: destination ? destination.name : '',
            };
          });
          this.fetchCleanupPreview(this.cleanup.form.warehouse_id);
          this.$inertia.reload({ only: ['transfers'] });
        })
        .catch(async err => {
          const message = err.response?.data?.errors?.items?.[0] || err.response?.data?.message || 'Failed to create transfers.';
          await this.$alert(message);
        })
        .finally(() => {
          this.cleanup.distributing = false;
        });
    },
    handleCleanupClick() {
      if (!this.cleanup.form.warehouse_id || !this.cleanup.passwordValid || this.cleanup.form.processing) return;
      this.submitCleanup();
    },
    submitCleanup() {
      if (!this.cleanup.form.warehouse_id || !this.cleanup.passwordValid || this.cleanup.form.processing) return;
      this.cleanup.show = false;
      this.cleanup.confirm = true;
    },
    closeCleanupConfirm() {
      this.cleanup.confirm = false;
      this.cleanup.show = true;
    },
    validateCleanupPassword() {
      const password = this.cleanup.form.password || '';
      this.cleanup.passwordError = '';
      this.cleanup.passwordValid = false;
      if (!password) {
        this.cleanup.passwordChecking = false;
        return;
      }

      this.cleanup.passwordChecking = true;
      axios.post(this.route('transfers.validate.password'), { password })
        .then(() => {
          this.cleanup.passwordValid = true;
          this.cleanup.passwordChecking = false;
        })
        .catch(() => {
          this.cleanup.passwordValid = false;
          this.cleanup.passwordChecking = false;
          this.cleanup.passwordError = 'The password you entered is incorrect.';
        });
    },
    confirmCleanup() {
      this.cleanup.form.clearErrors();
      this.cleanup.form.post(this.route('transfers.cleanup'), {
        preserveScroll: true,
        onSuccess: () => {
          this.cleanup.show = false;
          this.cleanup.confirm = false;
          this.cleanup.previewItems = [];
          this.cleanup.distributeItems = [];
          this.cleanup.previewLoading = false;
          this.cleanup.form.reset();
        },
      });
    },
    printCleanupPreview() {
      const rows = this.cleanup.previewItems || [];
      if (!rows.length) return;

      const printWindow = window.open('', '_blank', 'width=900,height=700');
      if (!printWindow) return;

      const rowsMarkup = rows.map((item, index) => {
        const label = item.code ? `${item.code} - ${item.name}` : item.name;
        return `<tr><td>${index + 1}</td><td>${label}</td><td>${item.quantity}</td><td>${item.unit}</td></tr>`;
      }).join('');

      const reportHtml = `<!DOCTYPE html>
        <html>
          <head>
            <meta charset="utf-8" />
            <title>Cleanup Project(s) / Warehouse(s) Preview Report</title>
            <style>
              @page { margin: 16mm; }
              body { font-family: Arial, sans-serif; color: #111827; margin: 0; padding: 24px; }
              .header { border-bottom: 1px solid #d1d5db; padding-bottom: 16px; margin-bottom: 20px; }
              .logo { height: 48px; width: auto; margin-bottom: 12px; }
              h1 { font-size: 20px; margin: 0 0 8px; }
              .meta { font-size: 12px; line-height: 1.5; }
              table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 12px; }
              th, td { border-bottom: 1px solid #e5e7eb; padding: 8px 6px; text-align: left; vertical-align: top; word-break: break-word; }
              th { background: #f9fafb; font-weight: 600; }
              tr { page-break-inside: avoid; break-inside: avoid-page; }
              thead { display: table-header-group; }
            </style>
          </head>
          <body>
            <div class="header">
              <img src="${window.location.origin}/images/logo.png" alt="Logo" class="logo" />
              <h1>Cleanup Project(s) / Warehouse(s) Preview Report</h1>
              <div class="meta">
                <div><strong>Warehouse:</strong> ${this.selectedWarehouseName}</div>
                <div><strong>Generated by:</strong> ${this.currentUserName}</div>
                <div><strong>Generated date:</strong> ${this.generatedDate}</div>
              </div>
            </div>
            <table>
              <thead>
                <tr><th>No</th><th>Item</th><th>Quantity</th><th>Unit</th></tr>
              </thead>
              <tbody>${rowsMarkup}</tbody>
            </table>
          </body>
        </html>`;

      printWindow.document.open();
      printWindow.document.write(reportHtml);
      printWindow.document.close();
      printWindow.focus();
      printWindow.print();
      printWindow.onafterprint = () => printWindow.close();
    },
    downloadCleanupPreview() {
      const rows = this.cleanup.previewItems || [];
      if (!rows.length) return;

      const printWindow = window.open('', '_blank', 'width=900,height=700');
      if (!printWindow) return;

      const rowsMarkup = rows.map((item, index) => {
        const label = item.code ? `${item.code} - ${item.name}` : item.name;
        return `<tr><td>${index + 1}</td><td>${label}</td><td>${item.quantity}</td><td>${item.unit}</td></tr>`;
      }).join('');

      const reportHtml = `<!DOCTYPE html>
        <html>
          <head>
            <meta charset="utf-8" />
            <title>Cleanup Project(s) / Warehouse(s) Preview Report</title>
            <style>
              @page { margin: 16mm; }
              body { font-family: Arial, sans-serif; color: #111827; margin: 0; padding: 24px; }
              .header { border-bottom: 1px solid #d1d5db; padding-bottom: 16px; margin-bottom: 20px; }
              .logo { height: 48px; width: auto; margin-bottom: 12px; }
              h1 { font-size: 20px; margin: 0 0 8px; }
              .meta { font-size: 12px; line-height: 1.5; }
              table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 12px; }
              th, td { border-bottom: 1px solid #e5e7eb; padding: 8px 6px; text-align: left; vertical-align: top; word-break: break-word; }
              th { background: #f9fafb; font-weight: 600; }
              tr { page-break-inside: avoid; break-inside: avoid-page; }
              thead { display: table-header-group; }
            </style>
          </head>
          <body>
            <div class="header">
              <img src="${window.location.origin}/images/logo.png" alt="Logo" class="logo" />
              <h1>Cleanup Project(s) / Warehouse(s) Preview Report</h1>
              <div class="meta">
                <div><strong>Warehouse:</strong> ${this.selectedWarehouseName}</div>
                <div><strong>Generated by:</strong> ${this.currentUserName}</div>
                <div><strong>Generated date:</strong> ${this.generatedDate}</div>
              </div>
            </div>
            <table>
              <thead>
                <tr><th>No</th><th>Item</th><th>Quantity</th><th>Unit</th></tr>
              </thead>
              <tbody>${rowsMarkup}</tbody>
            </table>
          </body>
        </html>`;

      printWindow.document.open();
      printWindow.document.write(reportHtml);
      printWindow.document.close();
      printWindow.focus();
      printWindow.print();
      printWindow.onafterprint = () => printWindow.close();
    },
  },
};
</script>

<style>
@media print {
  body {
    background: white !important;
  }
}
</style>

<style scoped>
/* first data row sits right under the table header / scroll edge: open its tooltips downward */
.txn-table-shell tr:nth-child(2) .lg-tip::after {
  bottom: auto;
  top: calc(100% + 8px);
}
.txn-table-shell .lg-tip::after {
  z-index: 40;
}
.lg-ref {
  font-weight: 800;
  color: var(--lg-navy);
}
html.dark .lg-ref {
  color: #bfdbfe;
}
.lg-meta {
  display: flex;
  align-items: baseline;
  gap: 5px;
  font-size: 0.8rem;
  line-height: 1.5;
}
.lg-meta-k {
  flex: none;
  font-size: 0.66rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--lg-faint);
}
.lg-muted-text {
  color: var(--lg-mute);
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
  background: radial-gradient(circle, #a78bfa, transparent 70%);
}
.txn-blob-2 {
  top: 40px;
  right: -60px;
  height: 240px;
  width: 240px;
  background: radial-gradient(circle, #38bdf8, transparent 70%);
}

.txn-hero {
  position: relative;
  background: linear-gradient(140deg, rgba(237, 233, 254, 0.65), rgba(255, 255, 255, 0.5));
  border: 1px solid rgba(255, 255, 255, 0.7);
  backdrop-filter: blur(20px) saturate(160%);
  -webkit-backdrop-filter: blur(20px) saturate(160%);
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 16px 32px -12px rgba(167, 139, 250, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.6);
}
.txn-hero-icon {
  background: linear-gradient(145deg, #a78bfa, #7c3aed);
  box-shadow: 0 8px 20px -6px rgba(124, 58, 237, 0.5);
}

.txn-create-btn {
  border-radius: 9999px !important;
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
.txn-row {
  transition: background 0.15s ease;
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
.txn-icon-btn-indigo {
  background: #e0e7ff;
  color: #4338ca;
}
.txn-icon-btn-indigo:hover {
  background: #4f46e5;
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
.txn-icon-btn-teal {
  background: #ccfbf1;
  color: #0f766e;
}
.txn-icon-btn-teal:hover {
  background: #0d9488;
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

.txn-status-badge {
  display: inline-flex;
  align-items: center;
  padding: 3px 10px;
  border-radius: 9999px;
  font-size: 0.7rem;
  font-weight: 700;
}
.txn-status-draft {
  background: #f1f5f9;
  color: #475569;
}
.txn-status-pending {
  background: #fef3c7;
  color: #b45309;
}
.txn-status-approved {
  background: #dbeafe;
  color: #1d4ed8;
}
.txn-status-received {
  background: #d1fae5;
  color: #047857;
}

.txn-received-meta {
  margin-top: 6px;
  font-size: 0.7rem;
  line-height: 1.4;
  color: #6b7280;
}

html.dark .txn-received-meta { color: #94a3b8; }

.txn-selection-bar {
  background: linear-gradient(135deg, rgba(209, 250, 229, 0.9), rgba(236, 253, 245, 0.85));
  border: 1px solid rgba(4, 120, 87, 0.2);
  border-radius: 1rem;
  padding: 0.85rem 1.1rem;
  box-shadow: 0 8px 20px -10px rgba(4, 120, 87, 0.35);
}
.txn-bulk-receive-btn {
  display: inline-flex;
  align-items: center;
  padding: 0.55rem 1.1rem;
  border-radius: 9999px;
  font-size: 0.8rem;
  font-weight: 700;
  color: #fff;
  background: linear-gradient(145deg, #34d399, #047857);
  box-shadow: 0 6px 16px -6px rgba(4, 120, 87, 0.5);
  transition: transform 0.15s ease;
}
.txn-bulk-receive-btn:hover { transform: translateY(-1px); }
.txn-bulk-receive-btn:disabled { opacity: 0.6; transform: none; }

.txn-modal-receive-btn {
  display: inline-flex;
  align-items: center;
  padding: 0.4rem 0.85rem;
  border-radius: 9999px;
  font-size: 0.76rem;
  font-weight: 700;
  color: #fff;
  background: linear-gradient(145deg, #34d399, #047857);
  box-shadow: 0 4px 12px -4px rgba(4, 120, 87, 0.5);
  transition: transform 0.15s ease;
}
.txn-modal-receive-btn:hover { transform: translateY(-1px); }
.txn-modal-receive-btn:disabled { opacity: 0.6; transform: none; }

/* Checkbox with a soft shadow and a continuously pulsing outline glow, so
   it reads as clearly interactive/selectable rather than blending into the
   row — matches the explicit "shadow and outline glow" ask for both the
   Super Admin bulk-select and the Site Engineer bulk-select paths, since
   both share this same checkbox. */
.txn-checkbox-glow {
  width: 1.05rem;
  height: 1.05rem;
  accent-color: #059669;
  cursor: pointer;
  border-radius: 4px;
  border: 1.5px solid #6ee7b7;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.15);
  animation: txn-checkbox-pulse 2.2s ease-in-out infinite;
}
.txn-checkbox-glow:hover { border-color: #34d399; }
.txn-checkbox-glow:focus-visible { outline: 2px solid #059669; outline-offset: 1px; }
@keyframes txn-checkbox-pulse {
  0%, 100% { box-shadow: 0 1px 3px rgba(15, 23, 42, 0.15), 0 0 0 0 rgba(5, 150, 105, 0.4); }
  50% { box-shadow: 0 1px 3px rgba(15, 23, 42, 0.15), 0 0 0 4px rgba(5, 150, 105, 0); }
}
@media (prefers-reduced-motion: reduce) {
  .txn-checkbox-glow { animation: none; }
}

html.dark .txn-selection-bar { background: linear-gradient(135deg, rgba(6, 78, 59, 0.4), rgba(6, 95, 70, 0.3)); border-color: rgba(52, 211, 153, 0.25); }

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
/* Different colour per relation, plus the main-warehouse stripe */
.rel-to { color: #047857; } .rel-val-to { color: #065f46; font-weight: 700; }
.rel-from { color: #6d28d9; } .rel-val-from { color: #5b21b6; font-weight: 700; }
.rel-user { color: #0369a1; } .rel-val-user { color: #075985; font-weight: 600; }
.rel-approved { color: #15803d; } .rel-val-approved { color: #15803d; font-weight: 800; cursor: help; }
.rel-pending { color: #b45309; } .rel-val-pending { color: #b45309; font-weight: 700; }
.rel-cancelled { color: #be123c; } .rel-val-cancelled { color: #be123c; font-weight: 700; cursor: help; }
html.dark .rel-val-to { color: #6ee7b7; } html.dark .rel-val-from { color: #c4b5fd; } html.dark .rel-val-user { color: #7dd3fc; } html.dark .rel-val-approved { color: #86efac; } html.dark .rel-val-pending { color: #fcd34d; } html.dark .rel-val-cancelled { color: #fda4af; }
.tr-main > td:first-child { box-shadow: inset 4px 0 0 #8b5cf6; }
.tr-main-pending > td:first-child { box-shadow: inset 4px 0 0 #f59e0b; }
.tr-main-approved > td:first-child { box-shadow: inset 4px 0 0 #10b981; }
.tr-main-cancelled > td:first-child { box-shadow: inset 4px 0 0 #f43f5e; }
.tr-main-cancelled { opacity: 0.72; }
/* On phones each row is a card and its first cell is just the select checkbox — no coloured edge stripe there */
@media (max-width: 767.98px) {
  .tr-main > td:first-child,
  .tr-main-pending > td:first-child,
  .tr-main-approved > td:first-child,
  .tr-main-cancelled > td:first-child { box-shadow: none; }
}
.txn-status-awaiting-approval { background: #fef3c7; color: #b45309; animation: txn-await-pulse 2s ease-in-out infinite; }
.txn-status-cancelled { background: #ffe4e6; color: #be123c; }
@keyframes txn-await-pulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.45); } 50% { box-shadow: 0 0 0 6px rgba(245, 158, 11, 0); } }
.txn-icon-btn-green { color: #047857; background: rgba(16, 185, 129, 0.16); }
.txn-icon-btn-green:hover { background: rgba(16, 185, 129, 0.28); }
</style>

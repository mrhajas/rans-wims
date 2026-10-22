<template>
  <admin-layout :title="$t('Material Reallocation Details')">
    <div class="lg-page px-4 md:px-0 ms-show">
      <page-header icon="toggle" :title="materialReallocation.reference" :subtitle="$t('Review the reallocation request and act on the current stage below')">
        <span class="lg-chip" :class="statusChipClass(materialReallocation.status)" style="text-transform: none">{{ materialReallocation.status }}</span>
        <Link :href="route('material-reallocations.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Material Reallocation') }}</Link>
        <button v-if="materialReallocation.can_print" type="button" @click="printReport" class="lg-btn lg-btn-green">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </button>
        <button v-if="materialReallocation.can_download_pdf" type="button" @click="downloadPdf" class="lg-btn lg-btn-dark">
          <icons name="doc-text" class="w-4 h-4" />{{ $t('Download PDF') }}
        </button>
        <Link v-if="materialReallocation.can_edit" :href="route('material-reallocations.edit', materialReallocation.id)" class="lg-btn lg-btn-primary">
          <icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}
        </Link>
        <button v-if="materialReallocation.can_delete" type="button" @click="deleteRecord" class="lg-btn lg-btn-red">
          <icons name="trash" class="w-4 h-4" />{{ $t('Delete') }}
        </button>
      </page-header>

      <!-- Workflow progress -->
      <div v-if="stageIndex >= 0" class="ms-steps print:hidden">
        <div v-for="(stage, si) in stages" :key="stage" class="ms-step" :class="{ 'is-done': si < stageIndex, 'is-current': si === stageIndex }">
          <span class="ms-step-dot"><icons v-if="si < stageIndex" name="tick" class="w-3 h-3" /><template v-else>{{ si + 1 }}</template></span>
          <span class="ms-step-label">{{ $t(stage) }}</span>
        </div>
      </div>

      <div>
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0"><h3 class="lg-card-title">{{ $t('Request Overview') }}</h3></div>
          </header>
          <div class="ms-kv">
            <div><div class="ms-k">{{ $t('Reference') }}</div><div class="ms-v">{{ materialReallocation.reference }}</div></div>
            <div><div class="ms-k">{{ $t('Date Requested') }}</div><div class="ms-v">{{ $date(materialReallocation.date_requested) }}</div></div>
            <div><div class="ms-k">{{ $t('Source Warehouse / Site') }}</div><div class="ms-v">{{ materialReallocation.source_warehouse?.name }}</div></div>
            <div><div class="ms-k">{{ $t('Requested By') }}</div><div class="ms-v">{{ materialReallocation.user?.name }}</div></div>
            <div><div class="ms-k">{{ $t('Status') }}</div><div class="ms-v"><span class="lg-chip" :class="statusChipClass(materialReallocation.status)" style="text-transform: none">{{ materialReallocation.status }}</span></div></div>
          </div>
        </section>

        <!-- Chain of Custody -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="link" class="w-5 h-5" /></span>
            <div class="min-w-0"><h3 class="lg-card-title">{{ $t('Chain of Custody') }}</h3></div>
          </header>
          <ul class="ms-timeline">
            <li v-if="materialReallocation.submitted_on">
              <span class="ms-tl-dot"></span>
              <div class="ms-tl-title">{{ $t('Submitted') }}</div>
              <div class="ms-tl-meta">{{ materialReallocation.user?.name }} on {{ $datetime(materialReallocation.submitted_on) }}</div>
            </li>
            <li v-if="materialReallocation.cleared_on">
              <span class="ms-tl-dot"></span>
              <div class="ms-tl-title">{{ $t('PM Clearance') }}</div>
              <div class="ms-tl-meta">{{ materialReallocation.cleared_by_name }} on {{ $datetime(materialReallocation.cleared_on) }}</div>
            </li>
            <li v-if="materialReallocation.warehouse_allocated_on">
              <span class="ms-tl-dot"></span>
              <div class="ms-tl-title">{{ $t('Warehouse Allocation') }}</div>
              <div class="ms-tl-meta">{{ materialReallocation.warehouse_allocated_by_name }} on {{ $datetime(materialReallocation.warehouse_allocated_on) }}</div>
            </li>
            <li v-if="materialReallocation.dispatch_date_time">
              <span class="ms-tl-dot"></span>
              <div class="ms-tl-title">{{ $t('Dispatched') }}</div>
              <div class="ms-tl-meta">{{ materialReallocation.dispatched_by_name }} on {{ $datetime(materialReallocation.dispatch_date_time) }}</div>
              <div class="ms-tl-meta">{{ $t('Driver') }}: {{ materialReallocation.driver_contact?.name }} ({{ materialReallocation.driver_contact?.phone }}); {{ $t('Vehicle') }}: {{ materialReallocation.vehicle }}</div>
            </li>
            <li v-if="materialReallocation.received_date_time">
              <span class="ms-tl-dot"></span>
              <div class="ms-tl-title">{{ $t('Received') }}</div>
              <div class="ms-tl-meta">{{ materialReallocation.received_by_name }} on {{ $datetime(materialReallocation.received_date_time) }}</div>
            </li>
            <li v-if="materialReallocation.final_approval_date_time">
              <span class="ms-tl-dot is-green"></span>
              <div class="ms-tl-title">{{ $t('Final Approval') }}</div>
              <div class="ms-tl-meta">{{ materialReallocation.final_approved_by_name }} on {{ $datetime(materialReallocation.final_approval_date_time) }}</div>
            </li>
          </ul>
        </section>

        <!-- Items -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="table" class="w-5 h-5" /></span>
            <div class="min-w-0"><h3 class="lg-card-title">{{ $t('Items') }}</h3></div>
          </header>
          <div class="lg-table-wrap">
            <table class="lg-table lg-responsive">
              <thead>
                <tr>
                  <th>{{ $t('Item') }}</th>
                  <th>{{ $t('Unit') }}</th>
                  <th class="num">{{ $t('Requested') }}</th>
                  <th>{{ $t('Destination Warehouse') }}</th>
                  <th class="num">{{ $t('Allocated') }}</th>
                  <th class="num">{{ $t('Received') }}</th>
                  <th>{{ $t('Remarks') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in materialReallocation.items" :key="item.id">
                  <td :data-label="$t('Item')"><span class="lg-main">{{ item.item?.code }} - {{ item.item?.name }}</span></td>
                  <td :data-label="$t('Unit')">{{ item.unit?.code || item.unit?.name || '-' }}</td>
                  <td :data-label="$t('Requested')" class="num">{{ $number(item.requested_quantity) }}</td>
                  <td :data-label="$t('Destination Warehouse')">{{ item.destination_warehouse?.name || '-' }}</td>
                  <td :data-label="$t('Allocated')" class="num">{{ item.allocated_quantity != null ? $number(item.allocated_quantity) : '-' }}</td>
                  <td :data-label="$t('Received')" class="num">{{ item.received_quantity != null ? $number(item.received_quantity) : '-' }}</td>
                  <td :data-label="$t('Remarks')">{{ item.remarks }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Reason & Remarks -->
        <section v-if="materialReallocation.reason || materialReallocation.approval_remarks" class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="info" class="w-5 h-5" /></span>
            <div class="min-w-0"><h3 class="lg-card-title">{{ $t('Reason & Remarks') }}</h3></div>
          </header>
          <div v-if="materialReallocation.reason">
            <h4 class="ms-k">{{ $t('Reason for Allocation') }}</h4>
            <p class="ms-text">{{ materialReallocation.reason }}</p>
            <p v-if="materialReallocation.reason_details" class="ms-text ms-mute">{{ materialReallocation.reason_details }}</p>
          </div>
          <div v-if="materialReallocation.approval_remarks" :class="materialReallocation.reason ? 'mt-4' : ''">
            <h4 class="ms-k">{{ $t('Latest Remarks') }}</h4>
            <p class="ms-text">{{ materialReallocation.approval_remarks }}</p>
          </div>
        </section>

        <!-- Attachments -->
        <section v-if="attachmentGroup('other').length || materialReallocation.can_edit || attachmentGroup('dispatch').length" class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0"><h3 class="lg-card-title">{{ $t('Attachments') }}</h3></div>
          </header>
          <div v-if="attachmentGroup('other').length || materialReallocation.can_edit">
            <h4 class="ms-k mb-2">{{ $t('Request Attachments') }}</h4>
            <attachment-list
              v-if="attachmentGroup('other').length"
              :attachments="attachmentGroup('other')"
              :deletable="materialReallocation.can_edit"
              @delete="deleteAttachment"
            />
            <p v-if="materialReallocation.can_edit" class="ms-hint">
              {{ $t('To add new attachments, use Edit above. You can attach multiple files and remove any of them there.') }}
            </p>
          </div>
          <div v-if="attachmentGroup('dispatch').length" :class="(attachmentGroup('other').length || materialReallocation.can_edit) ? 'mt-4' : ''">
            <h4 class="ms-k mb-2">{{ $t('Dispatch Attachments') }}</h4>
            <attachment-list :attachments="attachmentGroup('dispatch')" />
          </div>
        </section>

        <!-- Submit (Site Engineer, Draft/Request Changes) -->
        <section v-if="materialReallocation.can_submit" class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="plane" class="w-5 h-5" /></span>
            <div class="min-w-0"><h3 class="lg-card-title">{{ $t('Submit for Review') }}</h3></div>
          </header>
          <loading-button type="button" :loading="submitForm.processing" :disabled="submitForm.processing" @click="submit">
            <icons name="plane" class="w-4 h-4 mr-1" /> {{ $t('Submit') }}
          </loading-button>
        </section>

        <!-- Project Manager Clearance -->
        <section v-if="materialReallocation.can_clear || materialReallocation.can_request_changes || materialReallocation.can_reject" class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="key" class="w-5 h-5" /></span>
            <div class="min-w-0"><h3 class="lg-card-title">{{ $t('Project Manager Clearance') }}</h3></div>
          </header>
          <textarea-input v-model="clearForm.remarks" :label="$t('Remarks')" :error="$page.props.errors.remarks" />
          <div class="ms-btnrow">
            <button v-if="materialReallocation.can_reject" type="button" class="lg-btn lg-btn-red" @click="reject">
              <icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}
            </button>
            <button v-if="materialReallocation.can_request_changes" type="button" class="lg-btn lg-btn-soft" @click="requestChanges">
              <icons name="refresh" class="w-4 h-4" />{{ $t('Request Changes') }}
            </button>
            <loading-button v-if="materialReallocation.can_clear" type="button" :loading="clearForm.processing" :disabled="clearForm.processing" @click="clear">
              <icons name="tick" class="w-4 h-4 mr-1" /> {{ $t('Cleared for Approval') }}
            </loading-button>
          </div>

          <div v-if="pmRemarksTrail.length" class="ms-trail">
            <h4 class="ms-k mb-2">{{ $t('Remarks Trail') }}</h4>
            <ul class="ms-trail-list">
              <li v-for="log in pmRemarksTrail" :key="log.id">
                <div class="ms-trail-head">
                  <span class="ms-trail-act" :class="pmActionColor(log.action)">{{ pmActionLabel(log.action) }}</span>
                  <span class="ms-mute">{{ log.user?.name }} on {{ $datetime(log.created_at) }}</span>
                </div>
                <p>{{ log.remarks }}</p>
              </li>
            </ul>
          </div>
        </section>

        <!-- Warehouse Allocation -->
        <section v-if="materialReallocation.can_allocate" class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="building" class="w-5 h-5" /></span>
            <div class="min-w-0"><h3 class="lg-card-title">{{ $t('Warehouse Allocation') }}</h3></div>
          </header>
          <div v-for="row in allocateRows" :key="row.id" class="ms-alloc">
            <div class="ms-alloc-head">
              <span class="lg-main">{{ row.itemLabel }}</span>
              <span class="lg-chip lg-chip-slate" style="text-transform: none">{{ $t('Requested') }}: {{ $number(row.requested_quantity) }}</span>
            </div>
            <div v-for="(destination, dIndex) in row.destinations" :key="dIndex" class="ms-alloc-row">
              <div class="ms-alloc-wh">
                <select-input v-model="destination.warehouse_id" class="w-full">
                  <option :value="null">{{ $t('Select Main Warehouse') }}</option>
                  <option v-for="wh in mainWarehouses" :key="wh.id" :value="wh.id" :disabled="!!$page.props.stockHalts?.[wh.id]">{{ wh.name }}{{ $page.props.stockHalts?.[wh.id] ? ' - ' + $t('stock movement halted by :n', { n: $page.props.stockHalts[wh.id].by }) : '' }}</option>
                </select-input>
              </div>
              <div class="ms-alloc-qty">
                <text-input type="number" step="0.01" v-model="destination.quantity" />
              </div>
              <div class="ms-alloc-del">
                <button type="button" v-if="row.destinations.length > 1" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="removeDestination(row, dIndex)">
                  <icons name="trash" class="w-4 h-4" />
                </button>
              </div>
            </div>
            <div class="ms-alloc-foot">
              <button type="button" class="ms-link" @click="addDestination(row)">
                + {{ $t('Split into another Main Warehouse') }}
              </button>
              <span class="lg-chip" :class="allocatedTotal(row) === row.requested_quantity ? 'lg-chip-green' : 'lg-chip-red'" style="text-transform: none">
                {{ $t('Allocated') }}: {{ $number(allocatedTotal(row)) }} / {{ $number(row.requested_quantity) }}
              </span>
            </div>
          </div>
          <div class="ms-btnrow">
            <loading-button type="button" :loading="allocateForm.processing" :disabled="allocateForm.processing" @click="allocate">
              <icons name="building" class="w-4 h-4 mr-1" /> {{ $t('Save Allocation') }}
            </loading-button>
          </div>
        </section>

        <!-- Dispatch -->
        <section v-if="materialReallocation.can_dispatch || materialReallocation.dispatch_date_time" class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="truck" class="w-5 h-5" /></span>
            <div class="min-w-0"><h3 class="lg-card-title">{{ $t('Dispatch Preparation') }}</h3></div>
          </header>
          <template v-if="materialReallocation.can_dispatch">
            <div class="lg-fields ms-fields">
              <auto-complete
                id="driver"
                :label="$t('Driver')"
                :suggestions="driverSuggestions"
                v-model="dispatchForm.driver_contact_id"
                :error="$page.props.errors.driver_contact_id"
              />
              <text-input :label="$t('Vehicle')" v-model="dispatchForm.vehicle" :error="$page.props.errors.vehicle" />
              <text-input type="datetime-local" :label="$t('Dispatch Date & Time')" v-model="dispatchForm.dispatch_date_time" :error="$page.props.errors.dispatch_date_time" />
            </div>
            <div class="ms-files">
              <label class="ms-label">{{ $t('Dispatch Attachments') }}</label>
              <button type="button" class="lg-btn lg-btn-soft" @click.prevent="$refs.dispatchFile?.click()">
                <icons name="upload" class="w-4 h-4" /> {{ dispatchSelectedFiles.length ? $t('Add more files') : $t('Choose files') }}
              </button>
              <input ref="dispatchFile" type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="sr-only" @change="onDispatchFilesChosen" />
              <p class="ms-hint">
                {{ $t('You can select PDF, DOC, DOCX, JPG, JPEG, or PNG files. Pick files in as many rounds as you need. Everything listed below is uploaded together when you save, and you can remove any of them first.') }}
              </p>
              <div v-if="$page.props.errors.attachments" class="ms-err">{{ $page.props.errors.attachments }}</div>
              <div v-if="dispatchSelectedFiles.length" class="mt-3">
                <p class="ms-hint">{{ $t('Selected files (not yet uploaded)') }}</p>
                <ul class="ms-filelist">
                  <li v-for="(file, index) in dispatchSelectedFiles" :key="index">
                    <span class="truncate">{{ file.name }}</span>
                    <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="removeDispatchFile(index)"><icons name="trash" class="w-4 h-4" /></button>
                  </li>
                </ul>
              </div>
            </div>
            <div class="ms-btnrow">
              <loading-button type="button" :loading="dispatchForm.processing" :disabled="dispatchForm.processing" @click="dispatch">
                <icons name="truck" class="w-4 h-4 mr-1" /> {{ $t('Save Dispatch') }}
              </loading-button>
            </div>
          </template>

          <template v-else>
            <div class="ms-kv">
              <div>
                <div class="ms-k">{{ $t('Driver') }}</div>
                <div class="ms-v">
                  {{ materialReallocation.driver_contact?.name }}
                  <span v-if="materialReallocation.driver_contact?.phone" class="ms-mute">({{ materialReallocation.driver_contact.phone }})</span>
                </div>
              </div>
              <div><div class="ms-k">{{ $t('Vehicle') }}</div><div class="ms-v">{{ materialReallocation.vehicle }}</div></div>
              <div><div class="ms-k">{{ $t('Dispatched On') }}</div><div class="ms-v">{{ $datetime(materialReallocation.dispatch_date_time) }}</div></div>
              <div><div class="ms-k">{{ $t('Dispatched By') }}</div><div class="ms-v">{{ materialReallocation.dispatched_by_name }}</div></div>
            </div>
          </template>
        </section>

        <!-- Warehouse Receiving -->
        <section v-if="materialReallocation.can_receive || materialReallocation.received_date_time" class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="download" class="w-5 h-5" /></span>
            <div class="min-w-0"><h3 class="lg-card-title">{{ $t('Warehouse Receiving') }}</h3></div>
          </header>
          <template v-if="materialReallocation.can_receive">
          <div class="lg-table-wrap">
            <table class="lg-table lg-responsive">
              <thead>
                <tr>
                  <th>{{ $t('Item') }}</th>
                  <th>{{ $t('Unit') }}</th>
                  <th>{{ $t('Destination') }}</th>
                  <th class="num">{{ $t('Allocated') }}</th>
                  <th class="num">{{ $t('Received Quantity') }}</th>
                  <th>{{ $t('Variance') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in receiveFormObj.items" :key="row.id">
                  <td :data-label="$t('Item')"><span class="lg-main">{{ row.itemLabel }}</span></td>
                  <td :data-label="$t('Unit')">{{ row.unit }}</td>
                  <td :data-label="$t('Destination')">{{ row.destinationLabel }}</td>
                  <td :data-label="$t('Allocated')" class="num">{{ $number(row.allocated_quantity) }}</td>
                  <td :data-label="$t('Received Quantity')" class="num ms-qty-cell">
                    <text-input type="number" step="0.01" v-model="row.received_quantity" class="w-32 ml-auto" />
                  </td>
                  <td :data-label="$t('Variance')">
                    <span class="lg-chip" :class="varianceInfo(row).class" style="text-transform: none">{{ varianceInfo(row).text }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
            <div class="ms-legend">
              <span><i class="ms-dot is-green"></i>{{ $t('Matches Allocation') }}</span>
              <span><i class="ms-dot is-red"></i>{{ $t('Short: received less than allocated') }}</span>
              <span><i class="ms-dot is-amber"></i>{{ $t('Exceeds: received more than allocated') }}</span>
            </div>
            <div class="ms-files">
              <label class="ms-label">{{ $t('Receiving Attachments') }}</label>
              <button type="button" class="lg-btn lg-btn-soft" @click.prevent="$refs.receiveFile?.click()">
                <icons name="upload" class="w-4 h-4" /> {{ receiveSelectedFiles.length ? $t('Add more files') : $t('Choose files') }}
              </button>
              <input ref="receiveFile" type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="sr-only" @change="onReceiveFilesChosen" />
              <p class="ms-hint">
                {{ $t('You can select PDF, DOC, DOCX, JPG, JPEG, or PNG files. Pick files in as many rounds as you need. Everything listed below is uploaded together when you save, and you can remove any of them first.') }}
              </p>
              <div v-if="$page.props.errors.attachments" class="ms-err">{{ $page.props.errors.attachments }}</div>
              <div v-if="receiveSelectedFiles.length" class="mt-3">
                <p class="ms-hint">{{ $t('Selected files (not yet uploaded)') }}</p>
                <ul class="ms-filelist">
                  <li v-for="(file, index) in receiveSelectedFiles" :key="index">
                    <span class="truncate">{{ file.name }}</span>
                    <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="removeReceiveFile(index)"><icons name="trash" class="w-4 h-4" /></button>
                  </li>
                </ul>
              </div>
            </div>
            <div class="ms-btnrow">
              <loading-button type="button" :loading="receiveFormObj.processing" :disabled="receiveFormObj.processing" @click="receive">
                <icons name="download" class="w-4 h-4 mr-1" /> {{ $t('Save Receiving') }}
              </loading-button>
            </div>
          </template>

          <template v-else>
          <div class="lg-table-wrap">
            <table class="lg-table lg-responsive">
              <thead>
                <tr>
                  <th>{{ $t('Item') }}</th>
                  <th>{{ $t('Unit') }}</th>
                  <th>{{ $t('Destination') }}</th>
                  <th class="num">{{ $t('Allocated') }}</th>
                  <th class="num">{{ $t('Received') }}</th>
                  <th>{{ $t('Variance') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in materialReallocation.items" :key="item.id">
                  <td :data-label="$t('Item')"><span class="lg-main">{{ item.item?.code }} - {{ item.item?.name }}</span></td>
                  <td :data-label="$t('Unit')">{{ item.unit?.code || item.unit?.name || '-' }}</td>
                  <td :data-label="$t('Destination')">{{ item.destination_warehouse?.name || '-' }}</td>
                  <td :data-label="$t('Allocated')" class="num">{{ $number(item.allocated_quantity) }}</td>
                  <td :data-label="$t('Received')" class="num">{{ item.received_quantity != null ? $number(item.received_quantity) : '-' }}</td>
                  <td :data-label="$t('Variance')">
                    <span class="lg-chip" :class="varianceInfo(item).class" style="text-transform: none">{{ varianceInfo(item).text }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
            <div class="ms-legend">
              <span><i class="ms-dot is-green"></i>{{ $t('Matches Allocation') }}</span>
              <span><i class="ms-dot is-red"></i>{{ $t('Short: received less than allocated') }}</span>
              <span><i class="ms-dot is-amber"></i>{{ $t('Exceeds: received more than allocated') }}</span>
            </div>
            <div v-if="attachmentGroup('receiving').length" class="mt-4">
              <h4 class="ms-k mb-2">{{ $t('Attachments') }}</h4>
              <attachment-list :attachments="attachmentGroup('receiving')" />
            </div>
          </template>
        </section>

        <!-- Final Approval -->
        <section v-if="materialReallocation.can_final_approve" class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span>
            <div class="min-w-0"><h3 class="lg-card-title">{{ $t('Final Approval') }}</h3></div>
          </header>
          <p class="lg-note lg-note-amber">
            <icons name="exclamation" class="w-4 h-4" /><span>{{ $t('This will move inventory using the verified received quantities and cannot be undone.') }}</span>
          </p>
          <div class="ms-btnrow">
            <loading-button type="button" :loading="finalApproveForm.processing" :disabled="finalApproveForm.processing" @click="finalApprove">
              <icons name="tick" class="w-4 h-4 mr-1" /> {{ $t('Final Approve') }}
            </loading-button>
          </div>
        </section>

        <!-- Cancel -->
        <section v-if="materialReallocation.can_cancel" class="lg-card">
          <header class="lg-card-head" style="margin-bottom: 0">
            <span class="lg-card-ico lg-ico-rose"><icons name="cross" class="w-5 h-5" /></span>
            <div class="min-w-0"><h3 class="lg-card-title">{{ $t('Cancel Request') }}</h3></div>
            <button type="button" class="lg-btn lg-btn-dark ml-auto" @click="cancel">
              <icons name="cross" class="w-4 h-4" />{{ $t('Cancel Request') }}
            </button>
          </header>
        </section>

        <!-- Audit Trail -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span>
            <div class="min-w-0"><h3 class="lg-card-title">{{ $t('Audit Trail') }}</h3></div>
          </header>
          <div class="lg-table-wrap">
            <table class="lg-table lg-responsive">
              <thead>
                <tr>
                  <th>{{ $t('Action') }}</th>
                  <th>{{ $t('Status') }}</th>
                  <th>{{ $t('By') }}</th>
                  <th>{{ $t('Date & Time') }}</th>
                  <th>{{ $t('Remarks') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="log in materialReallocation.logs" :key="log.id">
                  <td :data-label="$t('Action')"><span class="lg-main">{{ log.action }}</span></td>
                  <td :data-label="$t('Status')">
                    <span v-if="log.previous_status" class="ms-mute">{{ log.previous_status }} → </span>{{ log.new_status }}
                  </td>
                  <td :data-label="$t('By')">{{ log.user?.name }} <span v-if="log.role" class="ms-mute">({{ log.role }})</span></td>
                  <td :data-label="$t('Date & Time')">{{ $datetime(log.created_at) }}</td>
                  <td :data-label="$t('Remarks')">{{ log.remarks }}</td>
                </tr>
                <tr v-if="!materialReallocation.logs || !materialReallocation.logs.length" class="lg-empty-row">
                  <td class="lg-empty" colspan="5">{{ $t('No logs available.') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TextInput from '@/Shared/TextInput.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import AttachmentList from '@/Shared/MaterialReallocation/AttachmentList.vue';

export default {
  components: {
    AdminLayout,
    PageHeader,
    TextInput,
    TextareaInput,
    SelectInput,
    AutoComplete,
    LoadingButton,
    AttachmentList,
  },

  props: {
    materialReallocation: Object,
    statuses: Array,
    mainWarehouses: Array,
    drivers: Array,
  },

  data() {
    return {
      stages: ['Draft', 'Submitted', 'Project Manager Clearance', 'Warehouse Allocation', 'Dispatch Preparation', 'Warehouse Receiving', 'Completed'],
      submitForm: this.$inertia.form({}),
      clearForm: this.$inertia.form({ remarks: '' }),
      remarksForm: this.$inertia.form({ remarks: '' }),
      allocateForm: this.$inertia.form({ items: [] }),
      dispatchSelectedFiles: [],
      receiveSelectedFiles: [],
      dispatchForm: this.$inertia.form({
        driver_contact_id: null,
        vehicle: '',
        dispatch_date_time: '',
        attachments: null,
      }),
      receiveFormObj: this.$inertia.form({
        items: (this.materialReallocation.items || []).map(item => ({
          id: item.id,
          itemLabel: `${item.item?.code || ''} - ${item.item?.name || ''}`,
          unit: item.unit?.code || item.unit?.name || '-',
          destinationLabel: item.destination_warehouse?.name || '-',
          allocated_quantity: item.allocated_quantity || 0,
          received_quantity: item.received_quantity ?? item.allocated_quantity ?? 0,
        })),
      }),
      finalApproveForm: this.$inertia.form({}),
      cancelForm: this.$inertia.form({ remarks: '' }),
      allocateRows: (this.materialReallocation.items || [])
        .filter(item => !item.parent?.id)
        .map(item => ({
          id: item.id,
          itemLabel: `${item.item?.code || ''} - ${item.item?.name || ''}`,
          requested_quantity: item.requested_quantity,
          destinations: [{ warehouse_id: item.destination_warehouse?.id || null, quantity: item.requested_quantity }],
        })),
    };
  },

  computed: {
    stageIndex() {
      return this.stages.indexOf(this.materialReallocation.status);
    },
    driverSuggestions() {
      return (this.drivers || []).map(d => ({ id: d.id, label: `${d.name}${d.phone ? ' (' + d.phone + ')' : ''}` }));
    },
    pmRemarksTrail() {
      const pmActions = ['cleared', 'request_changes', 'reject'];
      return (this.materialReallocation.logs || []).filter(log => pmActions.includes(log.action) && log.remarks);
    },
  },

  methods: {
    attachmentGroup(group) {
      return (this.materialReallocation.attachments && this.materialReallocation.attachments[group]) || [];
    },
    printReport() {
      window.open(route('material-reallocations.print', this.materialReallocation.id), '_blank');
    },
    downloadPdf() {
      window.open(route('material-reallocations.pdf', this.materialReallocation.id), '_blank');
    },
    varianceInfo(row) {
      const allocated = parseFloat(row.allocated_quantity) || 0;
      const receivedRaw = row.received_quantity;
      if (receivedRaw === null || receivedRaw === undefined || receivedRaw === '') {
        return { text: '—', class: 'lg-chip-slate' };
      }
      const received = parseFloat(receivedRaw);
      if (isNaN(received)) {
        return { text: '—', class: 'lg-chip-slate' };
      }
      const diff = received - allocated;
      if (Math.abs(diff) < 0.0001) {
        return { text: this.$t('Matches Allocation'), class: 'lg-chip-green' };
      }
      if (diff < 0) {
        return { text: `${this.$t('Short')} (${this.$number(Math.abs(diff))})`, class: 'lg-chip-red' };
      }
      return { text: `${this.$t('Exceeds')} (${this.$number(diff)})`, class: 'lg-chip-amber' };
    },
    pmActionLabel(action) {
      return {
        cleared: this.$t('Cleared for Approval'),
        request_changes: this.$t('Requested Changes'),
        reject: this.$t('Rejected'),
      }[action] || action;
    },
    pmActionColor(action) {
      return {
        cleared: 'ms-good',
        request_changes: 'ms-warn',
        reject: 'ms-bad',
      }[action] || '';
    },
    statusChipClass(status) {
      const map = {
        Draft: 'lg-chip-slate',
        Submitted: 'lg-chip-amber',
        'Project Manager Clearance': 'lg-chip-amber',
        'Warehouse Allocation': 'lg-chip-amber',
        'Dispatch Preparation': 'lg-chip-blue',
        'Warehouse Receiving': 'lg-chip-amber',
        Completed: 'lg-chip-green',
        Rejected: 'lg-chip-red',
        Cancelled: 'lg-chip-red',
      };
      return map[status] || 'lg-chip-slate';
    },
    async deleteAttachment(id) {
      if (!(await this.$confirm(this.$t('Remove this attachment?'), { variant: 'danger' }))) {
        return;
      }
      this.$inertia.form({ _method: 'DELETE' }).delete(route('media.delete', id));
    },
    allocatedTotal(row) {
      return row.destinations.reduce((sum, d) => sum + (parseFloat(d.quantity) || 0), 0);
    },
    addDestination(row) {
      row.destinations.push({ warehouse_id: null, quantity: null });
    },
    removeDestination(row, index) {
      row.destinations.splice(index, 1);
    },
    submit() {
      this.submitForm.patch(route('material-reallocations.submit', this.materialReallocation.id));
    },
    clear() {
      this.clearForm.patch(route('material-reallocations.clear', this.materialReallocation.id));
    },
    async requestChanges() {
      if (!this.clearForm.remarks || !this.clearForm.remarks.trim()) {
        await this.$alert(this.$t('Please enter remarks explaining what needs to change before requesting changes.'));
        return;
      }
      this.remarksForm.transform(() => ({ remarks: this.clearForm.remarks }))
        .patch(route('material-reallocations.request-changes', this.materialReallocation.id));
    },
    async reject() {
      if (!this.clearForm.remarks || !this.clearForm.remarks.trim()) {
        await this.$alert(this.$t('Please enter remarks explaining the reason before rejecting.'));
        return;
      }
      this.remarksForm.transform(() => ({ remarks: this.clearForm.remarks }))
        .patch(route('material-reallocations.reject', this.materialReallocation.id));
    },
    allocate() {
      this.allocateForm.transform(() => ({
        items: this.allocateRows.map(row => ({
          id: row.id,
          destinations: row.destinations.map(d => ({ warehouse_id: d.warehouse_id, quantity: d.quantity })),
        })),
      })).patch(route('material-reallocations.allocate', this.materialReallocation.id));
    },
    onDispatchFilesChosen() {
      const newFiles = this.$refs.dispatchFile?.files ? Array.from(this.$refs.dispatchFile.files) : [];
      const combined = [...this.dispatchSelectedFiles];
      for (const file of newFiles) {
        const isDuplicate = combined.some(f => f.name === file.name && f.size === file.size);
        if (!isDuplicate) {
          combined.push(file);
        }
      }
      this.dispatchSelectedFiles = combined;
      if (this.$refs.dispatchFile) {
        this.$refs.dispatchFile.value = '';
      }
    },
    removeDispatchFile(index) {
      this.dispatchSelectedFiles.splice(index, 1);
    },
    dispatch() {
      this.dispatchForm
        .transform(data => ({
          _method: 'PATCH',
          driver_contact_id: data.driver_contact_id,
          vehicle: data.vehicle,
          dispatch_date_time: data.dispatch_date_time,
          attachments: this.dispatchSelectedFiles,
        }))
        .post(route('material-reallocations.dispatch', this.materialReallocation.id), {
          onSuccess: () => {
            this.dispatchSelectedFiles = [];
          },
        });
    },
    onReceiveFilesChosen() {
      const newFiles = this.$refs.receiveFile?.files ? Array.from(this.$refs.receiveFile.files) : [];
      const combined = [...this.receiveSelectedFiles];
      for (const file of newFiles) {
        const isDuplicate = combined.some(f => f.name === file.name && f.size === file.size);
        if (!isDuplicate) {
          combined.push(file);
        }
      }
      this.receiveSelectedFiles = combined;
      if (this.$refs.receiveFile) {
        this.$refs.receiveFile.value = '';
      }
    },
    removeReceiveFile(index) {
      this.receiveSelectedFiles.splice(index, 1);
    },
    receive() {
      this.receiveFormObj
        .transform(data => ({
          _method: 'PATCH',
          items: data.items.map(row => ({ id: row.id, received_quantity: row.received_quantity })),
          attachments: this.receiveSelectedFiles,
        }))
        .post(route('material-reallocations.receive', this.materialReallocation.id), {
          onSuccess: () => {
            this.receiveSelectedFiles = [];
          },
        });
    },
    async finalApprove() {
      if (!(await this.$confirm(this.$t('This will move inventory using the verified received quantities and cannot be undone. Continue?'), { variant: 'danger' }))) {
        return;
      }
      this.finalApproveForm.patch(route('material-reallocations.final-approve', this.materialReallocation.id));
    },
    async cancel() {
      const remarks = (await this.$prompt(this.$t('Optional remarks for cancellation:'))) || '';
      this.cancelForm.transform(() => ({ remarks })).patch(route('material-reallocations.cancel', this.materialReallocation.id));
    },
    async deleteRecord() {
      if (!(await this.$confirm(this.$t('Are you sure you want to delete this material reallocation?'), { variant: 'danger' }))) {
        return;
      }
      this.$inertia.delete(route('material-reallocations.destroy', this.materialReallocation.id));
    },
  },
};
</script>
<style scoped>
.ms-steps { display: flex; gap: 6px; overflow-x: auto; margin-bottom: 1rem; padding: 10px 12px; border-radius: 9999px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); }
.ms-step { display: inline-flex; align-items: center; flex: none; gap: 8px; padding: 4px 12px 4px 4px; border-radius: 9999px; font-size: 0.74rem; font-weight: 700; color: var(--lg-faint); }
.ms-step-dot { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 9999px; font-size: 0.68rem; font-weight: 800; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.ms-step.is-done { color: var(--lg-mute); }
.ms-step.is-done .ms-step-dot { color: #fff; background: linear-gradient(145deg, #34d399, #047857); border-color: transparent; }
.ms-step.is-current { color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.9); }
.ms-step.is-current .ms-step-dot { color: #1c3f66; background: #fff; border-color: transparent; }

.ms-kv { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); gap: 0.9rem 1.25rem; }
.ms-k { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.ms-v { margin-top: 2px; font-size: 0.9rem; font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }
.ms-text { margin-top: 4px; font-size: 0.88rem; color: var(--lg-ink); }
.ms-mute { color: var(--lg-mute); font-weight: 500; }
.ms-hint { margin-top: 0.35rem; font-size: 0.76rem; color: var(--lg-mute); }
.ms-err { margin-top: 0.3rem; font-size: 0.8rem; color: #be123c; }
html.dark .ms-err { color: #fda4af; }
.ms-label { display: block; margin-bottom: 0.4rem; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-mute); }
.ms-good { color: #047857; } .ms-warn { color: #b45309; } .ms-bad { color: #be123c; }
html.dark .ms-good { color: #6ee7b7; } html.dark .ms-warn { color: #fcd34d; } html.dark .ms-bad { color: #fda4af; }

.ms-timeline { list-style: none; margin: 0; padding: 0 0 0 6px; }
.ms-timeline li { position: relative; padding: 0 0 16px 18px; border-left: 2px solid var(--lg-line); }
.ms-timeline li:last-child { border-left-color: transparent; padding-bottom: 0; }
.ms-tl-dot { position: absolute; left: -7px; top: 3px; width: 12px; height: 12px; border-radius: 9999px; background: #3b82f6; box-shadow: 0 0 0 3px var(--lg-card); }
.ms-tl-dot.is-green { background: #10b981; }
.ms-tl-title { font-size: 0.86rem; font-weight: 800; color: var(--lg-ink); }
.ms-tl-meta { margin-top: 1px; font-size: 0.76rem; color: var(--lg-mute); }

.ms-btnrow { display: flex; justify-content: flex-end; align-items: center; flex-wrap: wrap; gap: 10px; margin-top: 1rem; }
.ms-trail { margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--lg-line); }
.ms-trail-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.ms-trail-list li { padding: 0.6rem 0.85rem; font-size: 0.85rem; border-radius: 14px; border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-ink); }
.ms-trail-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 4px; font-size: 0.74rem; }
.ms-trail-act { font-weight: 800; }

.ms-alloc { padding: 0.85rem 1rem; margin-bottom: 0.75rem; border-radius: 16px; border: 1px solid var(--lg-line); background: var(--lg-soft); }
.ms-alloc-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 0.6rem; }
.ms-alloc-row { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 1fr) 40px; gap: 10px; align-items: end; margin-bottom: 0.4rem; }
.ms-alloc-row :deep(.mb-2) { margin-bottom: 0 !important; }
.ms-alloc-del { display: flex; justify-content: flex-end; padding-bottom: 4px; }
.ms-alloc-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-top: 0.4rem; }
.ms-link { font-size: 0.8rem; font-weight: 700; color: #1d4ed8; }
.ms-link:hover { text-decoration: underline; }
html.dark .ms-link { color: #93c5fd; }
@media (max-width: 640px) { .ms-alloc-row { grid-template-columns: minmax(0, 1fr); } .ms-alloc-del { justify-content: flex-start; } }

.ms-fields { margin-bottom: 1rem; }
.ms-files { margin-top: 1rem; }
.ms-filelist { list-style: none; margin: 0.35rem 0 0; padding: 0; border: 1px solid var(--lg-line); border-radius: 14px; overflow: hidden; }
.ms-filelist li { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 0.4rem 0.6rem 0.4rem 0.9rem; font-size: 0.84rem; color: var(--lg-ink); }
.ms-filelist li + li { border-top: 1px solid var(--lg-line); }

.ms-legend { display: flex; flex-wrap: wrap; gap: 6px 16px; margin: 0.75rem 0 1rem; font-size: 0.74rem; color: var(--lg-mute); }
.ms-legend span { display: inline-flex; align-items: center; gap: 6px; }
.ms-dot { display: inline-block; width: 10px; height: 10px; border-radius: 9999px; }
.ms-dot.is-green { background: #10b981; } .ms-dot.is-red { background: #ef4444; } .ms-dot.is-amber { background: #f59e0b; }
.ms-qty-cell :deep(.mb-2) { margin-bottom: 0 !important; }

.ms-show :deep(.lg-card input:not([type='checkbox']):not([type='radio']):not([type='file'])),
.ms-show :deep(.lg-card select),
.ms-show :deep(.lg-card textarea) { border-radius: 0.85rem !important; border-color: var(--lg-line) !important; background: var(--lg-card); color: var(--lg-ink); transition: border-color 0.15s ease, box-shadow 0.15s ease; }
.ms-show :deep(.lg-card input:focus), .ms-show :deep(.lg-card select:focus), .ms-show :deep(.lg-card textarea:focus) { border-color: #38bdf8 !important; box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2) !important; }
.ms-show :deep(.lg-fields label:not(:has(input[type='checkbox']))) { display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-mute); }
</style>

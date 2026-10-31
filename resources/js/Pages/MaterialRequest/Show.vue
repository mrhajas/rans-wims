<template>
  <admin-layout :title="$t('Material Request Details')">
    <div class="lg-page mr-show px-4 md:px-0">
      <page-header icon="clip" :title="materialRequest.reference" :subtitle="$t('Please review the material request details below')">
        <span
                class="lg-chip"
                :class="[materialRequest.force_cleared ? 'mr-chip-force lg-tip' : 'lg-chip-' + headerTone]"
                :data-tip="materialRequest.force_cleared ? $t('Admin Force Clearance') : null"
                :tabindex="materialRequest.force_cleared ? 0 : null"
                style="text-transform: none"
              >{{ statusLabel }}</span>
        <span v-if="materialRequest.delivery_state" class="lg-chip" :class="materialRequest.delivery_state === 'Fully Delivered' ? 'mr-chip-fdel' : 'lg-chip-amber'" style="text-transform: none">
          <icons name="tick" class="w-3 h-3" /><icons v-if="materialRequest.delivery_state === 'Fully Delivered'" name="tick" class="w-3 h-3 mr-tick2" />{{ $t(materialRequest.delivery_state) }}
        </span>
        <Link :href="route('material-requests.index')" class="lg-btn lg-btn-soft">
          <icons name="list" class="w-4 h-4" />{{ $t('Material Requests') }}
        </Link>
        <button
          v-for="target in materialRequest.allowed_statuses"
          :key="target"
          type="button"
          class="lg-btn mr-act print:hidden"
          :class="actionClass(target)"
          :disabled="changingStatus"
          @click="changeStatus(target)"
        >
          <icons :name="actionIcon(target)" class="w-4 h-4" />{{ actionLabel(target) }}
        </button>
        <button v-if="materialRequest.can_print" type="button" @click="printReport" class="lg-btn lg-btn-primary">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </button>
        <button v-if="materialRequest.can_download_pdf" type="button" @click="downloadPdf" class="lg-btn lg-btn-dark">
          <icons name="doc-text" class="w-4 h-4" />{{ $t('Download PDF') }}
        </button>
        <button v-if="materialRequest.can_remind || (materialRequest.can_view_reminders && materialRequest.reminder_count)" type="button" class="lg-btn tone print:hidden" :style="{ '--tone': materialRequest.status === 'Pending Approval' ? '#4f46e5' : '#d97706' }" @click="reminderOpen = true">
          <span class="tone-ico"><icons :name="materialRequest.status === 'Pending Approval' ? 'speakerphone' : 'bell'" class="w-4 h-4" /></span>{{ materialRequest.status === 'Pending Approval' ? $t('Remind approvers') : $t('Reminder') }}<b v-if="materialRequest.reminder_unread" class="mr-bell-n2">{{ materialRequest.reminder_unread }}</b>
        </button>
        <Link v-if="materialRequest.can_edit" :href="route('material-requests.edit', materialRequest.id)" class="lg-btn mr-btn-amber">
          <icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}
        </Link>
        <button v-if="materialRequest.can_delete" type="button" @click="deleteRequest" class="lg-btn lg-btn-red">
          <icons name="trash" class="w-4 h-4" />{{ $t('Delete') }}
        </button>
      </page-header>

      <stat-tiles :items="summaryTiles" />

      <section class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Details') }}</h2>
          </div>
        </div>
        <dl class="mr-dl">
          <div>
            <dt>{{ $t('Request Number') }}</dt>
            <dd>{{ materialRequest.reference }}</dd>
          </div>
          <div>
            <dt>{{ $t('Date Requested') }}</dt>
            <dd>{{ $date(materialRequest.date_requested) }}</dd>
          </div>
          <div>
            <dt>{{ $t('Warehouse / Site') }}</dt>
            <dd>{{ materialRequest.warehouse?.name || '-' }}</dd>
          </div>
          <div>
            <dt>{{ $t('Requested By') }}</dt>
            <dd>{{ materialRequest.user?.name || '-' }}</dd>
          </div>
          <div>
            <dt>{{ $t('Status') }}</dt>
            <dd>
              <span class="lg-chip" :class="'lg-chip-' + headerTone" style="text-transform: none">{{ statusLabel }}</span>
              <span v-if="materialRequest.quick" class="lg-chip lg-chip-indigo" style="text-transform: none">{{ $t('Quick') }}</span>
              <span v-if="materialRequest.delivery_state" class="lg-chip mr-delivery-gap" :class="materialRequest.delivery_state === 'Fully Delivered' ? 'mr-chip-fdel' : 'lg-chip-amber'" style="text-transform: none">
                <icons name="tick" class="w-3 h-3" /><icons v-if="materialRequest.delivery_state === 'Fully Delivered'" name="tick" class="w-3 h-3 mr-tick2" />{{ $t(materialRequest.delivery_state) }}
              </span>
              <div v-if="materialRequest.status === 'Cleared' && materialRequest.cleared_by_name" class="mr-cleared">
                <icons name="tick" class="w-3.5 h-3.5" />
                {{ $t('Cleared by') }} - {{ materialRequest.cleared_by_name }}<template v-if="materialRequest.cleared_at"> · {{ $datetime(materialRequest.cleared_at) }}</template>
              </div>
            </dd>
          </div>
        </dl>
      </section>

      <section class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="collection" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Items') }}</h2>
          </div>
        </div>

        <p v-if="materialRequest.is_final" class="lg-note lg-note-amber mb-4 print:hidden">
          <icons name="lock-closed" class="w-4 h-4" />
          <span>{{ $t('This request has been cleared / completed. It is locked: nothing can be changed any more.') }}</span>
        </p>
        <p v-else-if="materialRequest.can_confirm_delivery" class="lg-note mb-4 print:hidden">
          <icons name="truck" class="w-4 h-4" />
          <span>{{ $t('Tick each item as it arrives on site: one tick = Partially Delivered, two ticks = Fully Delivered. Add your remarks below and save. You can update this again whenever Procurement issues more.') }}</span>
        </p>

        <div v-if="showAvailability" class="mr-legend print:hidden">
          <span class="lg-chip lg-chip-green"><span class="mr-dot bg-emerald-500"></span>{{ $t('Available in Main Warehouse') }}</span>
          <span class="lg-chip lg-chip-amber"><span class="mr-dot bg-amber-500"></span>{{ $t('Available at Sites') }}</span>
          <span class="lg-chip lg-chip-cyan"><span class="mr-dot bg-teal-500"></span>{{ $t('Partially Available at Main Warehouse / Available at Sites') }}</span>
          <span class="lg-chip lg-chip-orange"><span class="mr-dot bg-orange-500"></span>{{ $t('Partially Available (Main Warehouses and/or Sites)') }}</span>
          <span class="lg-chip lg-chip-red"><span class="mr-dot bg-red-500"></span>{{ $t('Not Available') }}</span>
        </div>

        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th class="mr-no-col">{{ $t('No') }}</th>
                <th>{{ $t('Item') }}</th>
                <th class="num">{{ $t('Quantity') }}</th>
                <th>{{ $t('Unit') }}</th>
                <th v-if="showAvailability">{{ $t('Availability') }}</th>
                <th>{{ $t('Priority') }}</th>
                <th v-if="materialRequest.can_view_procurement_procedures">{{ $t('Procedure') }}</th>
                <th v-if="materialRequest.show_delivery">{{ $t('Delivery') }}</th>
                <th>{{ $t('Remarks') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(item, rowIndex) in materialRequest.items" :key="item.id">
                <td :data-label="$t('No')" class="mr-no-col lg-main">{{ rowIndex + 1 }}</td>
                <td :data-label="$t('Item')">
                  <button
                    v-if="!isSiteEngineer"
                    type="button"
                    @click="openItemDetails(item)"
                    class="mr-item-link lg-tip tip-left"
                    :data-tip="$t('Item Details')"
                  >
                    <icons name="view" class="w-4 h-4" />
                    <span>{{ item.item?.code }} - {{ item.item?.name }}</span>
                  </button>
                  <span v-else class="lg-main">{{ item.item?.code }} - {{ item.item?.name }}</span>
                  <div v-if="transfersOfItem(item).length" class="mr-item-refs">
                    <template v-for="t in transfersOfItem(item)" :key="t.id">
                      <Link v-if="mrTransfers.can_open" :href="route('transfers.edit', t.id)" class="mr-ref-chip" :title="t.status + ': ' + t.quantity"><icons name="truck" class="w-3 h-3" />{{ t.reference }}<small>{{ t.status }}</small></Link>
                      <span v-else class="mr-ref-chip"><icons name="truck" class="w-3 h-3" />{{ t.reference }}<small>{{ t.status }}</small></span>
                    </template>
                  </div>
                </td>
                <td :data-label="$t('Quantity')" class="num lg-main">{{ $number(item.quantity) }}</td>
                <td :data-label="$t('Unit')">{{ item.unit?.name || item.unit?.code }}</td>
                <td v-if="showAvailability" :data-label="$t('Availability')">
                  <span class="lg-chip mr-avail" :class="availabilityForItem(item).badgeClass" style="text-transform: none">
                    <span class="mr-dot" :class="availabilityForItem(item).dotClass"></span>
                    {{ availabilityForItem(item).label }}
                  </span>
                </td>
                <td :data-label="$t('Priority')">{{ item.priority }}</td>
                <td v-if="materialRequest.can_view_procurement_procedures" :data-label="$t('Procedure')">
                  <div v-if="materialRequest.can_edit_procurement_procedures && procRows[item.id]" class="mr-proc">
                    <simple-autocomplete
                      v-model="procRows[item.id].procedure"
                      :options="procedureChoices"
                      :placeholder="$t('Select Procedure')"
                      icon="search"
                      @update:modelValue="onProcedureChange(item.id)"
                    />
                    <simple-autocomplete
                      v-if="isSupplierProcedure(procRows[item.id].procedure)"
                      :class="{ 'mr-field-bad': procFieldError(item.id, 'contact_id') }"
                      @update:modelValue="clearProcError(item.id, 'contact_id')"
                      v-model="procRows[item.id].contact_id"
                      :options="supplierOptions"
                      :placeholder="$t('Search supplier…')"
                      icon="search"
                      :clearable="false"
                    />
                    <simple-autocomplete
                      v-else-if="procRows[item.id].procedure === 'Transfer from Site'"
                      :class="{ 'mr-field-bad': procFieldError(item.id, 'warehouse_id') }"
                      @update:modelValue="clearProcError(item.id, 'warehouse_id')"
                      v-model="procRows[item.id].warehouse_id"
                      :options="siteOptions"
                      :placeholder="$t('Search project / warehouse…')"
                      icon="search"
                      :clearable="false"
                    />
                    <simple-autocomplete
                      v-else-if="procRows[item.id].procedure === 'MainWarehouse'"
                      :class="{ 'mr-field-bad': procFieldError(item.id, 'warehouse_id') }"
                      @update:modelValue="clearProcError(item.id, 'warehouse_id')"
                      v-model="procRows[item.id].warehouse_id"
                      :options="mainWarehouseOptions"
                      :placeholder="$t('Search main warehouse…')"
                      icon="search"
                      :clearable="false"
                    />
                    <input
                      v-else-if="procRows[item.id].procedure === 'Other'"
                      v-model="procRows[item.id].note"
                      type="text"
                      maxlength="255"
                      class="mr-proc-input"
                      :class="{ 'mr-field-bad': procFieldError(item.id, 'note') }"
                      @input="clearProcError(item.id, 'note')"
                      :placeholder="$t('Type the procedure…')"
                    />
                    <p v-if="procError(item.id)" class="mr-proc-err" role="alert">{{ procError(item.id) }}</p>
                  </div>
                  <div v-else class="mr-proc-view">
                    <span class="lg-main">{{ item.procedure ? procedureLabel(item.procedure) : '-' }}</span>
                    <span v-if="procedureDetail(item)" class="lg-subtext">{{ procedureDetail(item) }}</span>
                  </div>
                </td>
                <td v-if="materialRequest.show_delivery" :data-label="$t('Delivery')">
                  <div v-if="materialRequest.can_confirm_delivery && item.has_procedure" class="mr-dv">
                    <button
                      type="button"
                      class="mr-dv-btn lg-tip"
                      :class="{ 'is-on': delivery[item.id] === 'Partially Delivered' }"
                      :data-tip="$t('Partially Delivered')"
                      :aria-label="$t('Partially Delivered')"
                      :aria-pressed="delivery[item.id] === 'Partially Delivered'"
                      @click="setDelivery(item.id, 'Partially Delivered')"
                    ><icons name="tick" class="w-3.5 h-3.5" /></button>
                    <button
                      type="button"
                      class="mr-dv-btn mr-dv-full lg-tip"
                      :class="{ 'is-on': delivery[item.id] === 'Fully Delivered' }"
                      :data-tip="$t('Fully Delivered')"
                      :aria-label="$t('Fully Delivered')"
                      :aria-pressed="delivery[item.id] === 'Fully Delivered'"
                      @click="setDelivery(item.id, 'Fully Delivered')"
                    ><icons name="tick" class="w-3.5 h-3.5" /><icons name="tick" class="w-3.5 h-3.5 mr-dv-second" /></button>
                  </div>
                  <div v-else-if="item.delivery_status" class="mr-dv-view">
                    <span class="lg-chip" :class="item.delivery_status === 'Fully Delivered' ? 'lg-chip-green' : 'lg-chip-amber'" style="text-transform: none">{{ $t(item.delivery_status) }}</span>
                    <span v-if="item.delivered_by" class="lg-subtext">{{ item.delivered_by.name }} · {{ $datetime(item.delivered_at) }}</span>
                  </div>
                  <span v-else class="lg-subtext">{{ item.has_procedure ? $t('Awaiting delivery') : '-' }}</span>
                </td>
                <td :data-label="$t('Remarks')">{{ item.remarks }}</td>
              </tr>
              <tr v-if="!materialRequest.items || !materialRequest.items.length" class="lg-empty-row">
                <td class="lg-empty" colspan="9">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- one place for the save buttons: Procurement saves procedures, Site saves delivery (each its own colour) -->
        <div v-if="materialRequest.can_edit_procurement_procedures || materialRequest.can_confirm_delivery" class="mr-dv-foot print:hidden">
          <template v-if="materialRequest.can_confirm_delivery">
            <label class="mr-dv-lbl" for="mr-delivery-remarks">{{ $t('Delivery remarks') }}</label>
            <textarea id="mr-delivery-remarks" v-model="deliveryRemarks" rows="3" maxlength="2000" class="mr-dv-text" :placeholder="$t('e.g. 40 of 50 bags arrived, the rest is on the next truck')"></textarea>
            <p v-if="deliveryForm.errors.items" class="mr-proc-err">{{ deliveryForm.errors.items }}</p>
          </template>
          <div class="mr-save-row">
            <button v-if="materialRequest.can_edit_procurement_procedures" type="button" class="mr-save-btn mr-save-proc" :disabled="procedureForm.processing" @click="saveProcedures">
              <icons :name="procedureForm.processing ? 'refresh' : 'doc-text'" class="w-4 h-4" :class="{ 'animate-spin': procedureForm.processing }" />{{ $t('Save Procedures') }}
            </button>
            <button v-if="materialRequest.can_confirm_delivery" type="button" class="mr-save-btn mr-save-del" :disabled="deliveryForm.processing" @click="saveDelivery">
              <icons :name="deliveryForm.processing ? 'refresh' : 'truck'" class="w-4 h-4" :class="{ 'animate-spin': deliveryForm.processing }" />{{ $t('Save Delivery') }}
            </button>
          </div>
        </div>
      </section>

      <section v-if="mrTransfers && (mrTransfers.groups.length || mrTransfers.made.length)" class="lg-card mr-transfers">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-teal"><icons name="truck" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <div class="lg-card-title">{{ $t('Transfers') }}</div>
            <div class="lg-card-sub">{{ $t('Items issued from a Main Warehouse can be moved here. Say how many of each, and one draft transfer is made for each warehouse.') }}</div>
          </div>
        </div>

        <template v-if="mrTransfers.groups.length">
          <div class="lg-note lg-note-blue" style="margin-bottom: 12px"><icons name="info" class="w-4 h-4 flex-none" /><span>{{ materialRequest.status === 'Issued' ? $t('Fully Issued: the full quantity is filled in for you.') : $t('Partially Issued: enter how many of each item to move now. What is not moved stays as the balance.') }} {{ $t('Drafts do not check stock. When you turn a draft into a normal transfer, the quantity in that warehouse is checked then.') }}</span></div>
          <div class="mr-tr-groups">
            <div v-for="(g, gi) in mrTransfers.groups" :key="gi" class="mr-tr-group">
              <div class="mr-tr-group-head">
                <div class="min-w-0"><b>{{ $t('From :w', { w: g.from_warehouse }) }}</b><small>{{ g.items.length }} {{ $t('item(s) still to move') }} &middot; {{ $t('one draft transfer') }}</small></div>
                <span class="lg-chip lg-chip-slate">{{ $t('Draft') }}</span>
              </div>
              <ul class="mr-tr-items">
                <li v-for="l in g.items" :key="l.id">
                  <span class="mr-tr-name">{{ l.name }} <small>{{ l.code }}</small><small v-if="!l.plain" class="block">{{ $t('Has variations or serial numbers: add it on a transfer by hand.') }}</small></span>
                  <span class="mr-tr-qty">
                    <input v-model="trQty[l.item_id]" class="mr-tr-input" inputmode="decimal" :disabled="!l.plain || !mrTransfers.can_create" :placeholder="'0 - ' + l.remaining" :aria-label="$t('Quantity to move')" />
                    <span class="mr-tr-unit">{{ l.unit }} <small>/ {{ l.remaining }} {{ $t('left') }}</small></span>
                  </span>
                </li>
              </ul>
            </div>
          </div>
          <div v-if="mrTransfers.can_create" class="mr-tr-actions">
            <span class="lg-sub">{{ trSummary }}</span>
            <button type="button" class="lg-btn lg-btn-primary" :disabled="trBusy || !trCount" @click="createTransfers"><icons name="plus" class="w-4 h-4" /><span>{{ $t('Create Draft Transfers') }}</span></button>
          </div>
        </template>
        <div v-if="mrTransfers.lines && mrTransfers.lines.length" class="mr-tr-group" style="margin-top: 12px">
          <div class="mr-tr-group-head"><div class="min-w-0"><b>{{ $t('Already on a transfer') }}</b><small>{{ $t('These items are not given another transfer. Change the quantity on their transfer to move the balance.') }}</small></div></div>
          <ul class="mr-tr-items">
            <li v-for="l in mrTransfers.lines" :key="l.id">
              <span class="mr-tr-name">{{ l.name }} <small>{{ l.code }}</small></span>
              <span class="mr-tr-qty">
                <template v-for="t in l.transfers" :key="t.id"><Link v-if="mrTransfers.can_open" :href="route('transfers.edit', t.id)" class="mr-ref-chip">{{ t.reference }}<small>{{ t.quantity }} {{ l.unit }}</small></Link><span v-else class="mr-ref-chip">{{ t.reference }}<small>{{ t.quantity }} {{ l.unit }}</small></span></template>
                <span class="mr-tr-unit"><small>{{ $t('Balance') }}: {{ l.remaining }} {{ l.unit }}</small></span>
              </span>
            </li>
          </ul>
        </div>
        <p v-if="!mrTransfers.groups.length && !(mrTransfers.lines && mrTransfers.lines.length)" class="lg-sub">{{ materialRequest.status === 'Partially Issued' || materialRequest.status === 'Issued' ? $t('Nothing is left to move by transfer.') : $t('Transfers can be made once the request is Partially or Fully Issued.') }}</p>

        <div v-if="mrTransfers.made.length" class="lg-table-wrap" style="margin-top: 12px">
          <table class="lg-table lg-responsive">
            <thead><tr><th>{{ $t('Transfer') }}</th><th>{{ $t('From') }}</th><th>{{ $t('Date') }}</th><th>{{ $t('Items') }}</th><th>{{ $t('Quantity') }}</th><th>{{ $t('Status') }}</th></tr></thead>
            <tbody>
              <tr v-for="t in mrTransfers.made" :key="t.id">
                <td :data-label="$t('Transfer')"><Link v-if="mrTransfers.can_open" :href="route('transfers.edit', t.id)" class="lg-main">{{ t.reference }}</Link><span v-else class="lg-main">{{ t.reference }}</span></td>
                <td :data-label="$t('From')">{{ t.from }}</td><td :data-label="$t('Date')">{{ t.date }}</td><td :data-label="$t('Items')">{{ t.lines }}</td><td :data-label="$t('Quantity')">{{ t.quantity }}</td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="{ Received: 'lg-chip-green', Approved: 'lg-chip-blue', 'Awaiting Approval': 'lg-chip-amber', Draft: 'lg-chip-violet', Cancelled: 'lg-chip-red' }[t.status] || 'lg-chip-slate'">{{ $t(t.status) }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="materialRequest.reason || materialRequest.comments" class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Notes') }}</h2>
          </div>
        </div>
        <div class="mr-notes">
          <div v-if="materialRequest.reason" class="mr-note">
            <h3>{{ $t('Reason for Request') }}</h3>
            <p>{{ materialRequest.reason }}</p>
          </div>
          <div v-if="materialRequest.comments" class="mr-note">
            <h3>{{ $t('Comments & Notes') }}</h3>
            <p>{{ materialRequest.comments }}</p>
          </div>
        </div>
      </section>

      <section v-if="reminders.length" class="lg-card print:hidden">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="bell" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Reminders') }} ({{ reminders.length }})</h2>
            <p class="lg-card-sub">{{ $t('Every reminder sent about this request, with the note, the files and who was told.') }}</p>
          </div>
          <button type="button" class="lg-btn lg-btn-soft" @click="reminderOpen = true">{{ $t('Open') }}</button>
        </div>
        <div class="mr-rem-list"><reminder-card v-for="r in reminders.slice(0, 3)" :key="r.id" :reminder="r" /></div>
        <p v-if="reminders.length > 3" class="mr-rem-more"><button type="button" @click="reminderOpen = true">{{ $t('Show all :n reminders', { n: reminders.length }) }}</button></p>
      </section>

      <section v-if="materialRequest.attachments && materialRequest.attachments.length" class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-teal"><icons name="clip" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
          </div>
        </div>
        <ul class="mr-files">
          <li v-for="attachment in materialRequest.attachments" :key="attachment.id">
            <span class="mr-file-ico"><icons name="doc" class="w-4 h-4" /></span>
            <span class="mr-file-name">{{ attachment.title }}</span>
            <a class="lg-btn lg-btn-soft" :href="route('media.download', attachment.id)">
              <icons name="download" class="w-4 h-4" />{{ $t('Download') }}
            </a>
          </li>
        </ul>
      </section>

      <section class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Audit Logs') }}</h2>
          </div>
        </div>
        <ol v-if="timeline.length" class="mr-tl">
          <li v-for="(log, index) in timeline" :key="log.id" :class="{ 'is-current': index === 0 }">
            <span class="mr-tl-dot" :class="'mr-dot-' + logTone(log)"><icons :name="logIcon(log)" class="w-3.5 h-3.5" /></span>
            <div class="mr-tl-body">
              <div class="mr-tl-head">
                <span class="lg-chip" :class="'lg-chip-' + logTone(log)" style="text-transform: none">{{ displayLogStatus(log) }}</span>
                <span class="mr-tl-who">{{ log.user?.name }}</span>
                <span class="mr-tl-time">{{ $datetime(log.created_at) }}</span>
              </div>
              <p v-if="log.remarks" class="mr-tl-remarks">{{ log.remarks }}</p>
            </div>
          </li>
        </ol>
        <p v-else class="lg-empty">{{ $t('No logs available.') }}</p>
      </section>

      <tec-dialog-modal :show="showItemDialog" max-width="4xl" @close="closeItemDialog">
        <template #title>
          <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
              <p class="text-lg font-semibold text-gray-900 truncate">{{ selectedItem?.item?.name || $t('Item Details') }}</p>
              <p class="text-sm text-gray-500 mt-1">{{ selectedItem?.item?.code || '-' }}</p>
            </div>
            <button type="button" class="inline-flex items-center px-3 py-1.5 rounded-md bg-indigo-600 text-white hover:bg-indigo-700" @click="printItemSubform">
              <icons name="printer" class="w-4 h-4 mr-1" /> {{ $t('Print') }}
            </button>
          </div>
        </template>

        <template #content>
          <div id="material-request-item-subform" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3 text-sm">
              <div class="rounded-md border border-gray-200 p-3">
                <p class="text-xs text-gray-500">{{ $t('Request Number') }}</p>
                <p class="font-semibold text-gray-900 mt-1">{{ materialRequest.reference }}</p>
              </div>
              <div class="rounded-md border border-gray-200 p-3">
                <p class="text-xs text-gray-500">{{ $t('Item Barcode') }}</p>
                <div class="mt-1 min-h-[52px]">
                  <svg ref="itemBarcodeSvg" class="max-w-full"></svg>
                </div>
              </div>
              <div class="rounded-md border border-gray-200 p-3">
                <p class="text-xs text-gray-500">{{ $t('Requested Quantity') }}</p>
                <p class="font-semibold text-gray-900 mt-1">{{ selectedItem ? $number(selectedItem.quantity) : '-' }}</p>
              </div>
              <div class="rounded-md border border-gray-200 p-3">
                <p class="text-xs text-gray-500">{{ $t('Unit') }}</p>
                <p class="font-semibold text-gray-900 mt-1">{{ selectedItem?.unit?.name || selectedItem?.unit?.code || '-' }}</p>
              </div>
            </div>

            <div v-if="selectedItemCategories.length" class="space-y-4">
              <div v-for="category in selectedItemCategories" :key="category.category" class="rounded-md border border-gray-200 overflow-hidden">
                <div class="px-4 py-2 bg-gray-50 border-b border-gray-200">
                  <p class="text-sm font-semibold text-gray-900">{{ category.category }}</p>
                </div>
                <table class="w-full text-sm">
                  <thead>
                    <tr class="text-left bg-white">
                      <th class="px-4 py-2">{{ $t('Warehouse') }}</th>
                      <th class="px-4 py-2">{{ $t('Unit') }}</th>
                      <th class="px-4 py-2 text-right">{{ $t('Available Quantity') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="warehouse in category.warehouses" :key="warehouse.id" class="border-t border-gray-100">
                      <td class="px-4 py-2">{{ warehouse.name }}</td>
                      <td class="px-4 py-2">{{ selectedItem?.unit?.name || selectedItem?.unit?.code || '-' }}</td>
                      <td class="px-4 py-2 text-right font-semibold text-emerald-700">{{ $number(warehouse.quantity) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
            <div v-else class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
              {{ $t('No warehouses currently have available quantity for this item.') }}
            </div>
          </div>
        </template>

        <template #footer>
          <button type="button" class="inline-flex items-center px-3 py-2 rounded-md bg-gray-100 text-gray-800 hover:bg-gray-200" @click="closeItemDialog">
            {{ $t('Close') }}
          </button>
        </template>
      </tec-dialog-modal>
    </div>
    <reminder-modal :show="reminderOpen" :request-id="materialRequest.id" @close="reminderOpen = false" @sent="$inertia.reload({ only: ['reminders', 'materialRequest'], preserveScroll: true })" @read="$inertia.reload({ only: ['materialRequest'], preserveScroll: true })" />
  </admin-layout>
</template>

<script>
import JsBarcode from 'jsbarcode';
import { Link } from '@inertiajs/vue3';
import { SITE_ENGINEER_ROLES } from '@/Core/siteEngineerRoles';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import TextInput from '@/Shared/TextInput.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import SimpleAutocomplete from '@/Shared/SimpleAutocomplete.vue';
import ReminderModal from './ReminderModal.vue';
import ReminderCard from '@/Pages/MrReminders/ReminderCard.vue';
import { printWithAnimation, printUrlInFrame, materialRequestPrintSheet } from '@/Core/printAnimation';

export default {
  components: {
    Link,
    AdminLayout,
    PageHeader,
    StatTiles,
    TextInput,
    SelectInput,
    LoadingButton,
    TecDialogModal,
    SimpleAutocomplete,
    ReminderModal,
    ReminderCard,
  },

  props: {
    reminders: { type: Array, default: () => [] },
    statuses: Array,
    materialRequest: Object,
    mrTransfers: { type: Object, default: null },
    availableStockByItem: {
      type: Object,
      default: () => ({}),
    },
    supplierOptions: { type: Array, default: () => [] },
    siteOptions: { type: Array, default: () => [] },
    mainWarehouseOptions: { type: Array, default: () => [] },
  },

  data() {
    return {
      reminderOpen: false,
      trQty: {},
      trBusy: false,
      changingStatus: false,
      procedureForm: this.$inertia.form({
        items: [],
      }),
      deliveryForm: this.$inertia.form({
        items: [],
        remarks: '',
      }),
      procRows: this.buildProcRows(),
      procPayloadIds: [],
      lastSavedSnapshot: JSON.stringify((this.materialRequest.items || []).map(i => [i.id, i.procedure, i.procedure_contact_id, i.procedure_warehouse_id, i.procedure_note, i.delivery_status])),
      delivery: this.buildDelivery(),
      deliveryRemarks: '',
      showItemDialog: false,
      selectedItem: null,
    };
  },

  created() {
    this.initTransferQuantities();
  },

  watch: {
    // After Inertia reloads the request (save, status change) start the editors from the fresh server state, but ONLY when
    // the saved data really changed: a validation error reloads the same data, and must not wipe what the user is typing.
    'materialRequest.items': {
      handler() {
        const saved = this.savedItemsSnapshot();
        if (saved === this.lastSavedSnapshot) return;
        this.lastSavedSnapshot = saved;
        this.procRows = this.buildProcRows();
        this.delivery = this.buildDelivery();
      },
      deep: true,
    },
  },

  computed: {
    // transfers made from the request: the quantities typed so far
    trEntered() {
      return Object.entries(this.trQty).filter(([, v]) => v !== '' && v !== null && Number(v) > 0).map(([k, v]) => ({ item_id: Number(k), qty: Number(v) }));
    },
    trCount() {
      return this.trEntered.length;
    },
    trSummary() {
      if (!this.trCount) return this.$t('Enter how many of each item to move. Leave an item empty to skip it.');
      const items = new Set(this.trEntered.map(e => e.item_id));
      const groups = ((this.mrTransfers && this.mrTransfers.groups) || []).filter(g => g.items.some(l => items.has(l.item_id))).length;
      return this.$t(':i item(s) in :t draft transfer(s), one for each warehouse.', { i: this.trCount, t: groups });
    },
    // the procedures as choices for the auto-search list
    procedureChoices() { return (this.materialRequest.procedure_options || []).map(o => ({ value: o, label: this.procedureLabel(o) })); },
    statusLabel() {
      return this.$t(this.materialRequest.status_label || this.materialRequest.status);
    },
    headerTone() {
      return this.statusTone(this.materialRequest.status);
    },
    summaryTiles() {
      const tileTone = { slate: 'slate', amber: 'amber', blue: 'sky', red: 'rose', violet: 'violet', cyan: 'teal', green: 'emerald' };
      const items = this.materialRequest.items || [];
      return [
        { icon: 'collection', tone: 'navy', label: this.$t('Items'), value: items.length },
        { icon: 'tick', tone: tileTone[this.headerTone] || 'slate', label: this.$t('Status'), value: this.statusLabel },
        { icon: 'calendar', tone: 'indigo', label: this.$t('Date Requested'), value: this.$date(this.materialRequest.date_requested) },
        { icon: 'building', tone: 'teal', label: this.$t('Warehouse / Site'), value: this.materialRequest.warehouse?.name || '-' },
      ];
    },
    // Newest first: the latest step is always on top.
    timeline() {
      return [...(this.materialRequest.logs || [])].sort((a, b) => new Date(b.created_at) - new Date(a.created_at) || b.id - a.id);
    },
    // Stock availability only helps while a request is still being decided / fulfilled, and is not for Site Engineers.
    showAvailability() {
      return !this.isSiteEngineer && !this.materialRequest.is_site_view && !['Rejected', 'Cleared', 'Closed'].includes(this.materialRequest.status);
    },
    isSiteEngineer() {
      const roles = this.$page?.props?.auth?.user?.roles || [];
      const lowerCaseRoles = SITE_ENGINEER_ROLES.map(name => name.toLowerCase());
      return roles.some((role) => lowerCaseRoles.includes(String(role?.name || '').toLowerCase()));
    },
    selectedItemCategories() {
      if (!this.selectedItem?.item_id) {
        return [];
      }

      const key = String(this.selectedItem.item_id);
      return this.availableStockByItem[key] || this.availableStockByItem[this.selectedItem.item_id] || [];
    },
  },

  methods: {
    transfersOfItem(item) {
      return (this.mrTransfers && this.mrTransfers.by_item && this.mrTransfers.by_item[item.item_id]) || [];
    },
    // Fully Issued: the quantity still to move is filled in; Partially Issued: left empty, the user says how many
    initTransferQuantities() {
      const q = {};
      if (this.materialRequest.status === 'Issued' && this.mrTransfers) {
        this.mrTransfers.groups.forEach(g => g.items.forEach(l => { if (l.plain) q[l.item_id] = String(l.remaining); }));
      }
      this.trQty = q;
    },
    createTransfers() {
      this.$confirm(this.trSummary + ' ' + this.$t('They are saved as drafts: no stock is checked or moved yet.')).then(ok => {
        if (!ok) return;
        this.trBusy = true;
        this.$inertia.post(route('material-requests.create-transfers', this.materialRequest.id), { quantities: this.trQty }, {
          preserveScroll: true,
          onSuccess: () => { this.initTransferQuantities(); },
          onFinish: () => { this.trBusy = false; },
        });
      });
    },
    statusTone(status) {
      return (
        {
          Draft: 'slate',
          'Pending Approval': 'amber',
          Approved: 'blue',
          Rejected: 'red',
          'Partially Issued': 'violet',
          Issued: 'cyan',
          Cleared: 'green',
          Closed: 'slate',
        }[status] || 'slate'
      );
    },
    logTone(log) {
      if (log?.status === 'Partially Delivered') return 'amber';
      if (log?.status === 'Fully Delivered') return 'green';
      if (log?.status === 'Delivery Update') return 'cyan';
      if (log?.status === 'Update Procedure') return 'violet';
      return this.statusTone(log?.status);
    },
    logIcon(log) {
      const tone = this.logTone(log);
      return { green: 'tick', red: 'x-circle', amber: 'clock', blue: 'tick', violet: 'truck', cyan: 'truck', slate: 'edit' }[tone] || 'edit';
    },
    itemCategories(item) {
      const itemId = item?.item_id;
      if (!itemId) {
        return [];
      }
      const key = String(itemId);
      return this.availableStockByItem[key] || this.availableStockByItem[itemId] || [];
    },
    normalizeCategory(value) {
      return String(value || '')
        .toLowerCase()
        .trim()
        .replace(/\s+/g, ' ');
    },
    displayLogStatus(log) {
      const status = log?.status || '-';
      if (status === 'Issued') return this.$t('Fully Issued');
      if (status === 'Cleared') return this.$t('Cleared / Completed');
      if (status === 'Update Procedure') return this.$t('Procedures set');
      if (status === 'Delivery Update') return this.$t('Delivery update');
      if (status === 'Partially Delivered') return this.$t('Partially Delivered');
      if (status === 'Fully Delivered') return this.$t('Fully Delivered');

      return this.$t(status);
    },
    isMainWarehouseCategory(categoryName) {
      const normalized = this.normalizeCategory(categoryName);
      return ['main warehouse', 'main warehouses'].includes(normalized);
    },
    availabilityForItem(item) {
      const categories = this.itemCategories(item);
      const requestedQty = Number(item?.quantity || 0);

      if (!categories.length) {
        return {
          label: this.$t('Not Available'),
          badgeClass: 'lg-chip-red',
          dotClass: 'bg-red-500',
        };
      }

      const mainWarehouseQty = categories
        .filter((category) => this.isMainWarehouseCategory(category.category))
        .reduce((sum, category) => {
          const categoryQty = (category.warehouses || []).reduce((qty, warehouse) => qty + Number(warehouse.quantity || 0), 0);
          return sum + categoryQty;
        }, 0);

      const sitesAndOtherQty = categories
        .filter((category) => !this.isMainWarehouseCategory(category.category))
        .reduce((sum, category) => {
          const categoryQty = (category.warehouses || []).reduce((qty, warehouse) => qty + Number(warehouse.quantity || 0), 0);
          return sum + categoryQty;
        }, 0);

      if (requestedQty <= 0) {
        return {
          label: this.$t('Not Available'),
          badgeClass: 'lg-chip-red',
          dotClass: 'bg-red-500',
        };
      }

      const mainFull = mainWarehouseQty >= requestedQty;
      const sitesFull = sitesAndOtherQty >= requestedQty;
      const mainPartial = mainWarehouseQty > 0 && !mainFull;
      const sitesPartial = sitesAndOtherQty > 0 && !sitesFull;

      if (mainFull) {
        return {
          label: this.$t('Available in Main Warehouse'),
          badgeClass: 'lg-chip-green',
          dotClass: 'bg-emerald-500',
        };
      }

      if (mainPartial && sitesFull) {
        return {
          label: this.$t('Partially Available at Main Warehouse / Available at Sites'),
          badgeClass: 'lg-chip-cyan',
          dotClass: 'bg-teal-500',
        };
      }

      if (sitesFull) {
        return {
          label: this.$t('Available at Sites'),
          badgeClass: 'lg-chip-amber',
          dotClass: 'bg-amber-500',
        };
      }

      if (mainPartial && sitesPartial) {
        return {
          label: this.$t('Partially Available at Main Warehouses and Sites'),
          badgeClass: 'lg-chip-orange',
          dotClass: 'bg-orange-500',
        };
      }

      if (mainPartial) {
        return {
          label: this.$t('Partially Available at Main Warehouses'),
          badgeClass: 'lg-chip-orange',
          dotClass: 'bg-orange-500',
        };
      }

      if (sitesPartial) {
        return {
          label: this.$t('Partially Available at Sites'),
          badgeClass: 'lg-chip-orange',
          dotClass: 'bg-orange-500',
        };
      }

      return {
        label: this.$t('Not Available'),
        badgeClass: 'lg-chip-red',
        dotClass: 'bg-red-500',
      };
    },
    openItemDetails(item) {
      if (this.isSiteEngineer) {
        return;
      }
      this.selectedItem = item;
      this.showItemDialog = true;
      this.$nextTick(this.renderItemBarcode);
    },
    closeItemDialog() {
      this.showItemDialog = false;
      this.selectedItem = null;
    },
    renderItemBarcode() {
      const svg = this.$refs.itemBarcodeSvg;
      const code = this.selectedItem?.item?.code;
      if (!svg || !code) {
        return;
      }
      try {
        JsBarcode(svg, code, {
          format: this.selectedItem?.item?.symbology || 'CODE128',
          displayValue: true,
          margin: 0,
          height: 45,
          width: 1.5,
          fontSize: 11,
        });
      } catch (e) {
        JsBarcode(svg, code, {
          format: 'CODE128',
          displayValue: true,
          margin: 0,
          height: 45,
          width: 1.5,
          fontSize: 11,
        });
      }
    },
    formatNum(value) {
      return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value || 0));
    },
    printableHtml() {
      if (!this.selectedItem) {
        return '';
      }

      const header = `
        <h2 style="margin:0 0 6px;font-size:18px;">Material Request Item Availability</h2>
        <p style="margin:0 0 12px;color:#555;">${this.materialRequest.reference}</p>
        <table style="width:100%;border-collapse:collapse;margin-bottom:12px;font-size:13px;">
          <tr>
            <td style="border:1px solid #ddd;padding:6px;"><strong>Item</strong><br>${this.selectedItem.item?.name || '-'}</td>
            <td style="border:1px solid #ddd;padding:6px;"><strong>Barcode</strong><br>${this.selectedItem.item?.code || '-'}</td>
            <td style="border:1px solid #ddd;padding:6px;"><strong>Requested Quantity</strong><br>${this.formatNum(this.selectedItem.quantity)}</td>
            <td style="border:1px solid #ddd;padding:6px;"><strong>Unit</strong><br>${this.selectedItem.unit?.name || this.selectedItem.unit?.code || '-'}</td>
          </tr>
        </table>`;

      const sections = this.selectedItemCategories
        .map((category) => {
          const rows = category.warehouses
            .map((warehouse) => `<tr>
                <td style="border:1px solid #ddd;padding:6px;">${warehouse.name}</td>
                <td style="border:1px solid #ddd;padding:6px;">${this.selectedItem.unit?.name || this.selectedItem.unit?.code || '-'}</td>
                <td style="border:1px solid #ddd;padding:6px;text-align:right;">${this.formatNum(warehouse.quantity)}</td>
              </tr>`)
            .join('');

          return `
            <h3 style="margin:14px 0 6px;font-size:14px;">${category.category}</h3>
            <table style="width:100%;border-collapse:collapse;font-size:12px;">
              <thead>
                <tr>
                  <th style="border:1px solid #ddd;padding:6px;text-align:left;">Project(s) / Warehouse(s)</th>
                  <th style="border:1px solid #ddd;padding:6px;text-align:left;">Unit</th>
                  <th style="border:1px solid #ddd;padding:6px;text-align:right;">Available Quantity</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>`;
        })
        .join('');

      const emptyState = '<p style="font-size:12px;color:#92400e;background:#fef3c7;padding:8px;border:1px solid #fcd34d;border-radius:4px;">No warehouses currently have available quantity for this item.</p>';

      return `${header}${sections || emptyState}`;
    },
    printItemSubform() {
      const content = this.printableHtml();
      if (!content) {
        return;
      }
      const popup = window.open('', '_blank', 'width=900,height=700');
      if (!popup) {
        return;
      }
      popup.document.write(`<!doctype html><html><head><title>Item Availability</title></head><body style="font-family:Arial,sans-serif;padding:16px;">${content}</body></html>`);
      popup.document.close();
      popup.focus();
      popup.print();
    },
    // ---- workflow buttons at the top of the page
    actionLabel(status) {
      return {
        Approved: this.$t('Approve'),
        Rejected: this.$t('Reject'),
        'Partially Issued': this.$t('Partially Issued'),
        Issued: this.$t('Fully Issued'),
        Cleared: this.$t('Clear / Complete'),
      }[status] || status;
    },
    actionIcon(status) {
      return { Approved: 'tick', Rejected: 'x-circle', 'Partially Issued': 'truck', Issued: 'truck', Cleared: 'lock-closed' }[status] || 'refresh';
    },
    actionClass(status) {
      return { Approved: 'lg-btn-green', Rejected: 'lg-btn-red', 'Partially Issued': 'mr-btn-violet', Issued: 'mr-btn-teal', Cleared: 'mr-btn-clear' }[status] || 'lg-btn-soft';
    },
    async changeStatus(status) {
      const ref = this.materialRequest.reference;
      const messages = {
        Approved: ref + ' - ' + this.$t('Approve this request? Add remarks if you like.'),
        Rejected: ref + ' - ' + this.$t('Reject this request? Tell the requester why (optional).'),
        'Partially Issued': ref + ' - ' + this.$t('Mark this request as Partially Issued? Add remarks if you like.'),
        Issued: ref + ' - ' + this.$t('Mark this request as Fully Issued? Add remarks if you like.'),
        Cleared: ref + ' - ' + this.$t('Clear / Complete this request? This locks it for everybody.'),
      };
      // Issuing needs the procurement procedures: say so right away (before asking for remarks), instead of failing afterwards.
      if (['Partially Issued', 'Issued'].includes(status) && this.materialRequest.can_view_procurement_procedures) {
        const items = this.materialRequest.items || [];
        const done = items.filter(i => i.procedure).length;
        if (this.hasUnsavedProcedures()) {
          await this.$alert(this.$t('You have procedures that are not saved yet. Click "Save Procedures" first, then mark the request as issued.'));
          return;
        }
        if (status === 'Partially Issued' && done === 0) {
          await this.$alert(this.$t('Set the procurement procedure of at least one item and click "Save Procedures" before marking the request as Partially Issued.'));
          return;
        }
        if (status === 'Issued' && done < items.length) {
          await this.$alert(this.$t('Every item needs a procurement procedure before the request can be Fully Issued') + ' (' + done + ' / ' + items.length + ' ' + this.$t('set') + '). ' + this.$t('Set the rest and click "Save Procedures", or use Partially Issued for now.'));
          return;
        }
      }

      // Clear / Complete with items not yet Fully Delivered: depending on the setting, warn first (or the server refuses).
      let confirmUndelivered = false;
      const undelivered = this.materialRequest.undelivered_items || [];
      if (status === 'Cleared' && undelivered.length && this.materialRequest.clear_delivery_mode === 'warn') {
        const proceed = await this.$confirm(
          this.$t('Not everything has been delivered on site yet') + ' (' + undelivered.length + '):\n\n' + undelivered.map(line => '• ' + line).join('\n') + '\n\n' + this.$t('Clear / Complete anyway? The request will be locked and this is written into its history.'),
          { variant: 'danger', confirmText: this.$t('Clear anyway'), cancelText: this.$t('Go back') }
        );
        if (!proceed) return;
        confirmUndelivered = true;
      }

      const remarks = await this.$prompt(messages[status] || this.$t('Change status?'), {
        variant: status === 'Rejected' ? 'danger' : 'default',
        placeholder: this.$t('Remarks (optional)'),
        confirmText: this.actionLabel(status),
      });
      if (remarks === null || remarks === false || remarks === undefined) return;

      this.sendStatus(status, typeof remarks === 'string' ? remarks : '', confirmUndelivered);
    },
    sendStatus(status, remarks, confirmUndelivered) {
      this.changingStatus = true;
      this.$inertia.patch(
        route('material-requests.status', this.materialRequest.id),
        { status, remarks, confirm_undelivered: confirmUndelivered },
        {
          preserveScroll: true,
          onError: async errors => {
            // The page was stale and the server wants the "not fully delivered" confirmation after all.
            if (errors.undelivered) {
              let list = [];
              try { list = JSON.parse(errors.undelivered); } catch (e) { /* ignore */ }
              const proceed = await this.$confirm(
                this.$t('Not everything has been delivered on site yet') + ' (' + list.length + '):\n\n' + list.map(line => '• ' + line).join('\n') + '\n\n' + this.$t('Clear / Complete anyway? The request will be locked and this is written into its history.'),
                { variant: 'danger', confirmText: this.$t('Clear anyway'), cancelText: this.$t('Go back') }
              );
              if (proceed) this.sendStatus(status, remarks, true);
              return;
            }
            this.$alert(errors.status || Object.values(errors)[0] || this.$t('That action could not be completed.'));
          },
          onFinish: () => (this.changingStatus = false),
        }
      );
    },

    // ---- procurement procedures
    savedItemsSnapshot() {
      return JSON.stringify((this.materialRequest.items || []).map(i => [i.id, i.procedure, i.procedure_contact_id, i.procedure_warehouse_id, i.procedure_note, i.delivery_status]));
    },
    buildProcRows() {
      const rows = {};
      (this.materialRequest.items || []).forEach(item => {
        rows[item.id] = {
          procedure: item.procedure || '',
          contact_id: item.procedure_contact_id || '',
          warehouse_id: item.procedure_warehouse_id || '',
          note: item.procedure_note || '',
        };
      });
      return rows;
    },
    isSupplierProcedure(procedure) {
      return procedure === 'Supplier Procure' || procedure === 'Supplier Credit';
    },
    // The stored value is "MainWarehouse"; people read "Main Warehouse".
    procedureLabel(option) {
      return option === 'MainWarehouse' ? this.$t('Main Warehouse') : option;
    },
    onProcedureChange(itemId) {
      // Each procedure has its own detail field, so drop the ones that no longer apply.
      const row = this.procRows[itemId];
      if (!this.isSupplierProcedure(row.procedure)) row.contact_id = '';
      // Transfer from Site and Main Warehouse each have their own list of places: never keep one from the other.
      if (!['Transfer from Site', 'MainWarehouse'].includes(row.procedure) || row.warehouse_id !== this.savedWarehouseFor(itemId, row.procedure)) row.warehouse_id = this.savedWarehouseFor(itemId, row.procedure);
      if (row.procedure !== 'Other') row.note = '';
    },
    // True when a procedure picked in the table differs from what is saved on the server.
    hasUnsavedProcedures() {
      return (this.materialRequest.items || []).some(item => {
        const row = this.procRows[item.id];
        if (!row || !row.procedure) return false;
        return (
          row.procedure !== (item.procedure || '') ||
          String(row.contact_id || '') !== String(item.procedure_contact_id || '') ||
          String(row.warehouse_id || '') !== String(item.procedure_warehouse_id || '') ||
          String(row.note || '') !== String(item.procedure_note || '')
        );
      });
    },
    savedWarehouseFor(itemId, procedure) {
      const item = (this.materialRequest.items || []).find(i => i.id === itemId);
      return item && item.procedure === procedure && item.procedure_warehouse_id ? item.procedure_warehouse_id : '';
    },
    procedureDetail(item) {
      if (item.procedure_contact) return item.procedure_contact.name;
      if (item.procedure_warehouse) return item.procedure_warehouse.name;
      return item.procedure_note || '';
    },
    // The error of ONE field of one item (contact_id / warehouse_id / note / procedure), so only that field turns red.
    procFieldError(itemId, field) {
      const index = this.procPayloadIds.indexOf(itemId);
      if (index < 0) return '';
      return (this.procedureForm.errors || {})[`items.${index}.${field}`] || '';
    },
    procError(itemId) {
      return ['contact_id', 'warehouse_id', 'note', 'procedure'].map(f => this.procFieldError(itemId, f)).find(Boolean) || '';
    },
    // Typing / picking in a field clears its red state.
    clearProcError(itemId, field) {
      const index = this.procPayloadIds.indexOf(itemId);
      if (index >= 0) this.procedureForm.clearErrors(`items.${index}.${field}`);
    },
    async saveProcedures() {
      const rows = (this.materialRequest.items || [])
        .filter(item => this.procRows[item.id] && this.procRows[item.id].procedure)
        .map(item => ({ id: item.id, ...this.procRows[item.id] }));

      if (!rows.length) {
        await this.$alert(this.$t('Select a procedure for at least one item before saving.'));
        return;
      }

      this.procPayloadIds = rows.map(row => row.id);
      this.procedureForm.items = rows.map(row => ({
        id: row.id,
        procedure: row.procedure,
        contact_id: row.contact_id || null,
        warehouse_id: row.warehouse_id || null,
        note: row.note || null,
      }));
      this.procedureForm.patch(route('material-requests.procedures', this.materialRequest.id), {
        preserveScroll: true,
        onError: () => {
          // Bring the first problem into view so it cannot be missed.
          this.$nextTick(() => {
            const bad = document.querySelector('.mr-field-bad, .mr-proc-err');
            if (bad && bad.scrollIntoView) bad.scrollIntoView({ block: 'center', behavior: 'smooth' });
          });
        },
      });
    },

    // ---- delivery on site
    buildDelivery() {
      const map = {};
      (this.materialRequest.items || []).forEach(item => {
        map[item.id] = item.delivery_status || null;
      });
      return map;
    },
    setDelivery(itemId, status) {
      // Clicking the active tick again clears it.
      this.delivery[itemId] = this.delivery[itemId] === status ? null : status;
    },
    saveDelivery() {
      this.deliveryForm.items = (this.materialRequest.items || [])
        .filter(item => item.has_procedure)
        .map(item => ({ id: item.id, delivery_status: this.delivery[item.id] || null }));
      this.deliveryForm.remarks = this.deliveryRemarks;
      this.deliveryForm.patch(route('material-requests.delivery', this.materialRequest.id), {
        preserveScroll: true,
        onSuccess: () => (this.deliveryRemarks = ''),
      });
    },
    printReport() {
      printWithAnimation(materialRequestPrintSheet(this, this.materialRequest), () => printUrlInFrame(route('material-requests.print', this.materialRequest.id)));
    },
    downloadPdf() {
      window.open(route('material-requests.pdf', this.materialRequest.id), '_blank');
    },
    async deleteRequest() {
      if (!(await this.$confirm(this.$t('Are you sure you want to delete this material request?'), { variant: 'danger' }))) {
        return;
      }

      this.$inertia.delete(route('material-requests.destroy', this.materialRequest.id));
    },
  },
};
</script>

<style scoped>
.mr-rem-list { display: grid; gap: 10px; margin-top: 12px; }
.mr-rem-more { margin: 10px 0 0; }
.mr-rem-more button { font-weight: 800; font-size: 0.82rem; color: #be185d; cursor: pointer; }
.mr-bell-n2 { margin-left: 6px; min-width: 18px; padding: 0 5px; border-radius: 999px; background: #d97706; color: #fff; font-size: 0.68rem; line-height: 18px; text-align: center; }
.mr-item-refs { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.mr-ref-chip { display: inline-flex; align-items: center; gap: 5px; padding: 2px 9px; border-radius: 999px; font-size: 0.74rem; font-weight: 700; color: #0f766e; background: rgba(20, 184, 166, 0.12); box-shadow: 0 0 0 1px rgba(20, 184, 166, 0.35); text-decoration: none; }
.mr-ref-chip small { font-weight: 600; color: var(--lg-mute); }
.mr-tr-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-top: 12px; }
.mr-tr-input { width: 110px; min-height: 40px; padding: 0 10px; text-align: right; border-radius: 12px; border: 1px solid var(--lg-line); background: var(--lg-card); box-shadow: 0 8px 16px -10px rgba(15, 37, 64, 0.5); font-variant-numeric: tabular-nums; }
.mr-tr-input:disabled { opacity: 0.5; }
.mr-tr-items li { align-items: center; } .mr-tr-qty { display: flex; align-items: center; gap: 10px; } .mr-tr-unit { min-width: 90px; }

.mr-tr-groups { display: grid; gap: 12px; }
.mr-tr-group { border-radius: 18px; padding: 12px 14px; background: var(--lg-card); box-shadow: 0 12px 20px -16px rgba(15, 37, 64, 0.55), 0 0 0 1px var(--lg-line); }
.mr-tr-group-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.mr-tr-group-head b { display: block; } .mr-tr-group-head small { color: var(--lg-mute); font-size: 0.78rem; }
.mr-tr-items { list-style: none; margin: 10px 0 0; padding: 0; display: grid; gap: 6px; }
.mr-tr-items li { display: flex; justify-content: space-between; gap: 12px; padding: 8px 10px; border-radius: 12px; background: rgba(148, 163, 184, 0.1); }
.mr-tr-name small, .mr-tr-qty small { color: var(--lg-mute); } .mr-tr-qty { white-space: nowrap; font-variant-numeric: tabular-nums; }
.lg-chip-orange { color: #c2410c; background: rgba(249, 115, 22, 0.14); }
html.dark .lg-chip-orange { color: #fdba74; }
.mr-btn-amber { color: #fff; background: linear-gradient(145deg, #f59e0b, #b45309); box-shadow: 0 10px 18px -10px rgba(180, 83, 9, 0.9); }

.mr-dl { display: grid; gap: 1rem 1.5rem; grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr)); margin: 0; }
.mr-dl > div { min-width: 0; padding: 0.7rem 0.9rem; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); box-shadow: 0 12px 24px -14px rgba(15, 37, 64, 0.38), 0 2px 6px rgba(15, 37, 64, 0.08); }
.mr-show :deep(.lg-stat-value) { font-size: 1.01rem; }
.mr-show :deep(.lg-stats > .lg-stat) { box-shadow: 0 14px 28px -14px rgba(15, 37, 64, 0.4), 0 2px 6px rgba(15, 37, 64, 0.08); }
html.dark .mr-dl > div, html.dark .mr-show :deep(.lg-stats > .lg-stat) { box-shadow: 0 14px 28px -14px rgba(0, 0, 0, 0.7), 0 2px 6px rgba(0, 0, 0, 0.3); }
.mr-dl dt { font-size: 0.626rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.mr-dl dd { margin: 4px 0 0; font-size: 0.978rem; font-family: inherit; font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }
.mr-chip-force { color: #9a3412; background: rgba(249, 115, 22, 0.22); box-shadow: inset 0 0 0 1px rgba(234, 88, 12, 0.45); cursor: help; }
html.dark .mr-chip-force { color: #fdba74; }
.mr-cleared { display: flex; align-items: center; gap: 5px; margin-top: 6px; font-size: 0.74rem; font-weight: 700; color: #047857; }
html.dark .mr-cleared { color: #6ee7b7; }

.mr-legend { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 0.9rem; }
.mr-legend .lg-chip { text-transform: none; font-weight: 700; }
.mr-dot { display: inline-block; flex: none; width: 8px; height: 8px; border-radius: 9999px; }
.mr-avail { white-space: normal; text-align: left; }

.mr-item-link { display: inline-flex; align-items: center; gap: 8px; font-weight: 700; text-align: left; color: #4338ca; }
.mr-item-link:hover { text-decoration: underline; }
html.dark .mr-item-link { color: #a5b4fc; }
.mr-proc { min-width: 210px; }

.mr-notes { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr)); }
.mr-note { min-width: 0; padding: 0.8rem 1rem; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.mr-note h3 { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.mr-note p { margin-top: 4px; font-size: 0.86rem; line-height: 1.55; color: var(--lg-ink); white-space: pre-line; overflow-wrap: anywhere; }

.mr-files { margin: 0; padding: 0; list-style: none; display: grid; gap: 8px; }
.mr-files li { display: flex; align-items: center; gap: 12px; padding: 0.6rem 0.8rem; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.mr-file-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 32px; height: 32px; border-radius: 10px; color: var(--lg-navy); background: rgba(28, 63, 102, 0.09); }
.mr-file-name { flex: 1; min-width: 0; font-size: 0.84rem; font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }

.mr-tl { margin: 0; padding: 0; list-style: none; }
.mr-tl li { position: relative; display: flex; gap: 14px; padding-bottom: 20px; }
.mr-tl li:last-child { padding-bottom: 0; }
.mr-tl li::before { content: ''; position: absolute; left: 13px; top: 28px; bottom: -2px; width: 2px; border-radius: 2px; background: var(--lg-line); }
.mr-tl li:last-child::before { display: none; }
.mr-tl-dot { position: relative; z-index: 1; display: inline-flex; align-items: center; justify-content: center; flex: none; width: 28px; height: 28px; border-radius: 9999px; color: #fff; box-shadow: 0 0 0 4px var(--lg-card); }
.mr-tl li.is-current .mr-tl-dot { box-shadow: 0 0 0 4px var(--lg-card), 0 0 0 6px rgba(99, 102, 241, 0.25); }
.mr-tl-body { flex: 1; min-width: 0; padding: 0.55rem 0.9rem; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.mr-tl-head { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; }
.mr-tl-who { font-size: 0.8rem; font-weight: 700; color: var(--lg-ink); }
.mr-tl-time { margin-left: auto; font-size: 0.72rem; color: var(--lg-faint); }
.mr-tl-remarks { margin-top: 6px; font-size: 0.83rem; line-height: 1.5; color: var(--lg-mute); overflow-wrap: anywhere; }
.mr-dot-slate { background: linear-gradient(145deg, #64748b, #334155); }
.mr-dot-amber { background: linear-gradient(145deg, #fbbf24, #b45309); }
.mr-dot-blue { background: linear-gradient(145deg, #3b82f6, #1c3f66); }
.mr-dot-red { background: linear-gradient(145deg, #fb7185, #be123c); }
.mr-dot-violet { background: linear-gradient(145deg, #a78bfa, #6d28d9); }
.mr-dot-cyan { background: linear-gradient(145deg, #22d3ee, #0e7490); }
.mr-dot-green { background: linear-gradient(145deg, #34d399, #047857); }

@media (max-width: 767.98px) {
  .mr-proc { min-width: 0; width: 100%; }
  .lg-table td .mr-item-link { justify-content: flex-end; }
  .mr-tl-time { margin-left: 0; width: 100%; }
}

/* workflow buttons: on phones they share a full-width row so the main decision is easy to hit */
@media (max-width: 639.98px) {
  .mr-act { flex: 1 1 calc(50% - 4px); min-width: 0; padding-top: 11px; padding-bottom: 11px; font-size: 0.86rem; }
}
/* the little eye icon in front of an item must never be squeezed into a blob by a long item name */
.mr-item-link > div { flex: none; }

/* delivery badge beside the status: Fully Delivered is a lighter green than Cleared, with two ticks */
.mr-chip-fdel { color: #15803d; background: rgba(34, 197, 94, 0.16); }
html.dark .mr-chip-fdel { color: #86efac; }
.mr-tick2 { margin-left: -7px; }
/* delivery badge beside the status */
.mr-delivery-gap { margin-left: 6px; gap: 4px; }

/* workflow buttons */
.mr-btn-violet { color: #fff; background: linear-gradient(145deg, #8b5cf6, #5b21b6); box-shadow: 0 10px 18px -10px rgba(91, 33, 182, 0.9); }
.mr-btn-teal { color: #fff; background: linear-gradient(145deg, #14b8a6, #0f766e); box-shadow: 0 10px 18px -10px rgba(15, 118, 110, 0.9); }
.mr-btn-clear { color: #fff; background: linear-gradient(145deg, #065f46, #022c22); box-shadow: 0 10px 18px -10px rgba(2, 44, 34, 0.95); }

/* items table: No column + procedure editor + delivery ticks */
.mr-no-col { width: 48px; text-align: center; font-variant-numeric: tabular-nums; }
.mr-proc { display: flex; flex-direction: column; gap: 6px; min-width: 210px; }
.mr-proc-input { width: 100%; padding: 0.5rem 0.7rem; border: 1px solid var(--lg-line); border-radius: 0.6rem; background: var(--lg-card); color: var(--lg-ink); font-size: 0.85rem; }
.mr-proc-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18); }
.mr-proc-err { color: #be123c; font-size: 0.74rem; font-weight: 600; margin: 0; }
html.dark .mr-proc-err { color: #fda4af; }
.mr-proc-view { display: flex; flex-direction: column; gap: 2px; }
.mr-dv { display: inline-flex; gap: 6px; }
.mr-dv-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; height: 30px; padding: 0 8px; border-radius: 9999px; color: var(--lg-faint); background: var(--lg-soft); border: 1px solid var(--lg-line); transition: transform 0.15s ease, background 0.15s ease, color 0.15s ease; }
.mr-dv-btn:hover { transform: translateY(-1px); color: var(--lg-ink); }
.mr-dv-btn.is-on { color: #fff; background: linear-gradient(145deg, #f59e0b, #b45309); border-color: transparent; box-shadow: 0 8px 14px -8px rgba(180, 83, 9, 0.9); }
.mr-dv-full.is-on { background: linear-gradient(145deg, #10b981, #047857); box-shadow: 0 8px 14px -8px rgba(4, 120, 87, 0.9); }
.mr-dv-second { margin-left: -7px; }
.mr-dv-view { display: flex; flex-direction: column; gap: 3px; align-items: flex-start; }
.mr-dv-foot { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--lg-line); }
.mr-dv-lbl { display: block; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); margin-bottom: 4px; }
.mr-dv-text { width: 100%; padding: 0.6rem 0.8rem; border: 1px solid var(--lg-line); border-radius: 0.8rem; background: var(--lg-card); color: var(--lg-ink); font-size: 0.9rem; resize: vertical; }
.mr-dv-text:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18); }

/* the two save buttons share one row under the items, each with its own colour, icon and shadow */
.mr-save-row { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 10px; margin-top: 12px; }
.mr-save-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 20px; border-radius: 9999px; font-size: 0.86rem; font-weight: 800; color: #fff; border: 0; cursor: pointer; transition: transform 0.15s ease, filter 0.15s ease; }
.mr-save-btn:hover:not(:disabled) { transform: translateY(-1px); filter: brightness(1.06); }
.mr-save-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.mr-save-proc { background: linear-gradient(145deg, #8b5cf6, #4c1d95); box-shadow: 0 12px 20px -10px rgba(76, 29, 149, 0.9), 0 2px 4px rgba(76, 29, 149, 0.25); }
.mr-save-del { background: linear-gradient(145deg, #10b981, #047857); box-shadow: 0 12px 20px -10px rgba(4, 120, 87, 0.9), 0 2px 4px rgba(4, 120, 87, 0.25); }
@media (max-width: 639.98px) { .mr-save-row { flex-direction: column; } .mr-save-btn { width: 100%; } }

/* a missing supplier / project / note: only that field glows red */
.mr-field-bad :deep(.sac-input), .mr-proc-input.mr-field-bad {
  border-color: #ef4444 !important;
  box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.28), 0 0 16px rgba(239, 68, 68, 0.5) !important;
  animation: mr-bad-pulse 1.6s ease-in-out 3;
}
html.dark .mr-field-bad :deep(.sac-input), html.dark .mr-proc-input.mr-field-bad { border-color: #fb7185 !important; box-shadow: 0 0 0 3px rgba(251, 113, 133, 0.3), 0 0 18px rgba(251, 113, 133, 0.55) !important; }
@keyframes mr-bad-pulse { 0%, 100% { box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.28), 0 0 10px rgba(239, 68, 68, 0.35); } 50% { box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.4), 0 0 22px rgba(239, 68, 68, 0.75); } }
@media (prefers-reduced-motion: reduce) { .mr-field-bad :deep(.sac-input), .mr-proc-input.mr-field-bad { animation: none; } }
</style>

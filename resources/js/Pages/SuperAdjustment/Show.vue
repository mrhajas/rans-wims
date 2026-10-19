<template>
  <admin-layout :title="superAdjustment.reference">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="adjustments" :title="superAdjustment.reference" :subtitle="$t('Super Adjustments')">
        <span class="lg-chip" :class="statusTone"><icons v-if="superAdjustment.status === 'Approved'" name="tick" class="w-3.5 h-3.5 inline-flex" />{{ statusText }}</span>
        <Link :href="route('super-adjustments.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('Super Adjustments') }}</Link>
        <a v-if="superAdjustment.can_print" :href="route('super-adjustments.print', superAdjustment.id)" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </a>
        <a v-if="superAdjustment.can_print" :href="route('super-adjustments.pdf', superAdjustment.id)" class="lg-btn lg-btn-soft">
          <icons name="download" class="w-4 h-4" />{{ $t('Download') }}
        </a>
        <change-status :adjustment="superAdjustment" route-prefix="super-adjustments" />
        <button v-if="superAdjustment.can_edit" type="button" class="lg-btn lg-btn-soft" @click="goEdit">
          <icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}
        </button>
        <approval-actions :adjustment="superAdjustment" route-prefix="super-adjustments" />
      </page-header>

      <stat-tiles :items="tiles" />

      <!-- Every approval phase of this record: who, in which role, when; the phases still missing; the ones voided -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Approvals') }}</h2>
            <p class="lg-card-sub">{{ approval.required > 1 ? $t(':n different Super Admins must approve, each with their own password. The stock changes only after the last phase.', { n: approval.required }) : $t('One Super Admin approval is needed.') }}</p>
          </div>
          <span class="lg-chip" :class="statusTone"><icons v-if="superAdjustment.status === 'Approved'" name="tick" class="w-3.5 h-3.5 inline-flex" />{{ statusText }}</span>
        </header>
        <div class="sa-phases">
          <div v-for="ph in approval.phases" :key="ph.phase" class="sa-phase" :class="{ 'is-done': ph.done, ['is-p' + ph.phase]: ph.done, 'is-next': !ph.done && ph.phase === nextPhase }">
            <span class="sa-phase-n">{{ $t('Phase :n', { n: ph.phase }) }}</span>
            <template v-if="ph.done">
              <b class="sa-phase-who"><icons name="tick" class="w-4 h-4" /> {{ ph.user }}</b>
              <span class="sa-phase-meta">{{ ph.role }} · {{ $datetime(ph.at) }}</span>
              <span v-if="ph.ip" class="sa-phase-meta">{{ $t('From') }} {{ ph.ip }}</span>
              <span v-if="ph.note" class="sa-phase-meta">{{ ph.note }}</span>
            </template>
            <template v-else>
              <b class="sa-phase-who sa-wait"><icons name="clock" class="w-4 h-4" /> {{ ph.phase === nextPhase && superAdjustment.status !== 'Rejected' ? $t('Waiting for a Super Admin') : $t('Not given') }}</b>
              <span class="sa-phase-meta">{{ ph.phase === 2 ? $t('A different Super Admin from phase 1') : $t('Any Super Admin') }}</span>
            </template>
          </div>
        </div>
        <p v-if="superAdjustment.status === 'Awaiting Phase 2' && superAdjustment.can_approve" class="lg-sub mt-2">{{ $t('You can give the second approval.') }}</p>
        <p v-else-if="superAdjustment.status === 'Awaiting Phase 2' && superAdjustment.i_approved_phase1" class="lg-sub mt-2">{{ $t('You gave phase 1. A different Super Admin must give phase 2.') }}</p>
        <details v-if="approval.history.length" class="sa-void">
          <summary>{{ $t('Approvals that no longer stand (:n)', { n: approval.history.length }) }}</summary>
          <ul><li v-for="(h, i) in approval.history" :key="i">{{ $t('Phase :n', { n: h.phase }) }}: {{ h.user }} · {{ $datetime(h.at) }} — {{ $t('voided') }} {{ $datetime(h.voided_at) }}: {{ h.reason }}</li></ul>
        </details>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-section-ico-blue"><icons name="info" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Details') }}</h2>
          </div>
          <a
            v-if="superAdjustment.warehouse_id"
            :href="route('reports.warehousestock', { warehouseId: superAdjustment.warehouse_id })"
            target="_blank"
            rel="noopener"
            class="lg-btn lg-btn-soft"
          >
            <icons name="collection" class="w-4 h-4" /> {{ $t('Live Stock') }}
          </a>
        </header>
        <div class="sa-kv">
          <div><span class="sa-k">{{ $t('Reason') }}</span><span class="sa-v">{{ superAdjustment.reason }}</span></div>
          <div v-if="superAdjustment.approver">
            <span class="sa-k">{{ $t('Approved By') }}</span>
            <span class="sa-v">
              {{ superAdjustment.approver.name }}
              <span v-if="superAdjustment.approved_by_role" class="lg-chip lg-chip-indigo sa-role">{{ superAdjustment.approved_by_role }}</span>
              · {{ $datetime(superAdjustment.approved_at) }}
              <span v-if="superAdjustment.self_approved" class="sa-auto">{{ $t('Approved automatically, submitted by a Super Admin') }}</span>
            </span>
          </div>
          <div v-if="superAdjustment.rejecter"><span class="sa-k">{{ $t('Rejected By') }}</span><span class="sa-v">{{ superAdjustment.rejecter.name }} · {{ $datetime(superAdjustment.rejected_at) }}</span></div>
          <div v-if="superAdjustment.addition_adjustment"><span class="sa-k">{{ $t('Addition Adjustment') }}</span><a class="sa-v sa-link" :href="route('adjustments.show', superAdjustment.created_addition_adjustment_id)" target="_blank" rel="noopener">{{ superAdjustment.addition_adjustment.reference }}</a></div>
          <div v-if="superAdjustment.subtraction_adjustment"><span class="sa-k">{{ $t('Subtraction Adjustment') }}</span><a class="sa-v sa-link" :href="route('adjustments.show', superAdjustment.created_subtraction_adjustment_id)" target="_blank" rel="noopener">{{ superAdjustment.subtraction_adjustment.reference }}</a></div>
          <div v-if="superAdjustment.details" class="sa-wide"><span class="sa-k">{{ $t('Details') }}</span><span class="sa-v sa-prose">{{ superAdjustment.details }}</span></div>
          <div v-if="superAdjustment.remarks" class="sa-wide"><span class="sa-k">{{ $t('Remarks') }}</span><span class="sa-v sa-prose">{{ superAdjustment.remarks }}</span></div>
        </div>
        <div v-if="superAdjustment.rejection_reason" class="lg-note lg-note-red mt-3">
          <icons name="exclamation" class="w-4 h-4" /><span><b>{{ $t('Rejection Reason') }}:</b> {{ superAdjustment.rejection_reason }}</span>
        </div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="collection" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Physical Stock Count') }}</h2>
            <p class="lg-card-sub">{{ sortedItems.length }} / {{ items.length }} {{ $t('items') }}</p>
          </div>
        </header>

        <div class="sa-search">
          <icons name="search" class="w-4 h-4 sa-items-search-icon" />
          <input v-model="itemsSearch" type="text" class="sa-items-search-input" :placeholder="$t('Search item name or code…')" />
        </div>

        <div class="lg-table-wrap" @scroll.passive="hideTooltip">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('No') }}</th>
                <th class="is-sortable" @click="setItemsSort('name')">
                  <span class="lg-sort">{{ $t('Item') }} <span class="sa-sort-indicator">{{ itemsSortIndicator('name') }}</span></span>
                </th>
                <th class="is-sortable" @click="setItemsSort('unit')">
                  <span class="lg-sort">{{ $t('Unit') }} <span class="sa-sort-indicator">{{ itemsSortIndicator('unit') }}</span></span>
                </th>
                <th class="num is-sortable" @click="setItemsSort('system_quantity_at_submission')">
                  <span class="lg-sort">{{ $t('System (at submission)') }} <span class="sa-sort-indicator">{{ itemsSortIndicator('system_quantity_at_submission') }}</span></span>
                </th>
                <th class="num is-sortable" @click="setItemsSort('system_quantity_live')">
                  <span class="lg-sort">{{ $t('System (now)') }} <span class="sa-sort-indicator">{{ itemsSortIndicator('system_quantity_live') }}</span></span>
                </th>
                <th class="num is-sortable" @click="setItemsSort('physical_quantity')">
                  <span class="lg-sort">{{ $t('Physical Count') }} <span class="sa-sort-indicator">{{ itemsSortIndicator('physical_quantity') }}</span></span>
                </th>
                <th class="num is-sortable" @click="setItemsSort('variance')">
                  <span class="lg-sort">{{ $t('Variance') }} <span class="sa-sort-indicator">{{ itemsSortIndicator('variance') }}</span></span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in sortedItems" :key="row.id" :class="{ 'is-shifted': stockShifted(row) }">
                <td class="sa-muted" :data-label="$t('No')">{{ index + 1 }}</td>
                <td :data-label="$t('Item')">
                  <div class="sa-itemcell">
                    <span class="lg-main">{{ row.name }}</span>
                    <span class="sa-itemmeta">
                      <span class="sa-muted">({{ row.code }})</span>
                      <span
                        v-if="row.auto_cleared"
                        class="sa-cleared-tag"
                        tabindex="0"
                        @mouseenter="showTooltip($event, $t('Auto-added at zero via Clear, not physically counted'))"
                        @mouseleave="hideTooltip"
                        @focus="showTooltip($event, $t('Auto-added at zero via Clear, not physically counted'))"
                        @blur="hideTooltip"
                      >{{ $t('Cleared') }}</span>
                    </span>
                  </div>
                </td>
                <td :data-label="$t('Unit')">{{ row.unit }}</td>
                <td class="num" :data-label="$t('System (at submission)')">{{ $number(row.system_quantity_at_submission) }}</td>
                <td class="num" :class="{ 'sa-shift-val': stockShifted(row) }" :data-label="$t('System (now)')">
                  <span>
                    {{ $number(row.system_quantity_live) }}
                    <span
                      v-if="stockShifted(row)"
                      class="sa-tooltip-icon ml-1"
                      tabindex="0"
                      @mouseenter="showTooltip($event, stockShiftReason(row))"
                      @mouseleave="hideTooltip"
                      @focus="showTooltip($event, stockShiftReason(row))"
                      @blur="hideTooltip"
                    >⚠</span>
                  </span>
                </td>
                <td class="num lg-main" :data-label="$t('Physical Count')">{{ $number(row.physical_quantity) }}</td>
                <td class="num" :data-label="$t('Variance')">
                  <span
                    class="sa-variance-pill"
                    :class="variancePillClass(row)"
                    tabindex="0"
                    @mouseenter="showTooltip($event, varianceTooltip(row))"
                    @mouseleave="hideTooltip"
                    @focus="showTooltip($event, varianceTooltip(row))"
                    @blur="hideTooltip"
                  >
                    <icons :name="varianceIcon(row)" class="w-3 h-3 inline-flex" />
                    {{ varianceLabel(row) }}
                  </span>
                </td>
              </tr>
              <tr v-if="!sortedItems.length" class="lg-empty-row"><td class="lg-empty" colspan="7">{{ $t('No matching item found.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="lg-card" v-if="attachments.length">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
          </div>
        </header>
        <ul class="sa-files">
          <li v-for="a in attachments" :key="a.id" class="sa-file">
            <span class="sa-file-ico"><icons :name="a.is_image ? 'image' : 'doc'" class="w-4 h-4 flex-none" /></span>
            <span class="sa-file-name">{{ a.title }}</span>
            <span class="flex items-center gap-1.5 flex-none">
              <a :href="a.preview_url" target="_blank" class="lg-icon-btn lg-tip" :data-tip="$t('View')" :aria-label="$t('View')"><icons name="eye-flat" class="w-4 h-4" /></a>
              <a :href="a.download_url" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" class="w-4 h-4" /></a>
            </span>
          </li>
        </ul>
      </section>
    </div>

    <!-- Single shared tooltip, teleported to <body> and positioned via
         getBoundingClientRect (like AutoComplete.vue's option tooltip) so it
         escapes the items table's own overflow-x-auto clipping and stays
         within the viewport regardless of horizontal scroll position. -->
    <Teleport to="body">
      <div
        v-if="tooltip.visible"
        class="sa-teleport-tooltip"
        :class="'sa-tooltip-' + tooltip.placement"
        :style="{ top: tooltip.top + 'px', left: tooltip.left + 'px', width: tooltip.width + 'px' }"
      >
        {{ tooltip.text }}
        <span class="sa-tooltip-arrow" :style="{ left: tooltip.arrowLeft + 'px' }"></span>
      </div>
    </Teleport>

    <tec-dialog-modal :show="rejecting" @close="rejecting = false" max-width="sm">
      <template #title>
        <span class="sa-modal-title">
          <span class="lg-card-ico lg-ico-rose"><icons name="x-circle" class="w-5 h-5" /></span>
          <span>{{ $t('Reject Super Adjustment?') }}</span>
        </span>
      </template>
      <template #content>
        <textarea v-model="rejectionReason" class="sa-textarea" rows="3" :placeholder="$t('Reason for rejecting…')"></textarea>
      </template>
      <template #footer>
        <div class="flex items-center justify-between gap-2 w-full">
          <button type="button" class="lg-btn lg-btn-soft" @click="rejecting = false">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-red" :disabled="rejectForm.processing || !rejectionReason" @click="submitReject">
            <icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}
          </button>
        </div>
      </template>
    </tec-dialog-modal>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import ChangeStatus from '@/Shared/Adjustment/ChangeStatus.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import ApprovalActions from '@/Shared/Adjustment/ApprovalActions.vue';

export default {
  components: { AdminLayout, TecDialogModal, PageHeader, StatTiles, ChangeStatus, ApprovalActions },

  props: {
    superAdjustment: Object,
    items: Array,
    attachments: Array,
    approval: { type: Object, default: () => ({ required: 1, phases: [], history: [] }) },
  },

  data() {
    return {
      rejecting: false,
      rejectionReason: '',
      rejectForm: this.$inertia.form({ rejection_reason: '' }),
      tooltip: { visible: false, text: '', top: 0, left: 0, arrowLeft: 0, width: 260, placement: 'above' },
      itemsSearch: '',
      itemsSort: null,
      itemsSortDirection: 'asc',
    };
  },

  computed: {
    statusTone() {
      return { Draft: 'lg-chip-amber', 'Awaiting Phase 2': 'lg-chip-sky', Approved: 'sa-final', Rejected: 'lg-chip-red' }[this.superAdjustment.status] || 'lg-chip-slate';
    },
    statusText() {
      return this.$t(this.superAdjustment.status === 'Awaiting Phase 2' ? 'Awaiting 2nd approval' : this.superAdjustment.status === 'Draft' ? 'Pending approval' : this.superAdjustment.status === 'Approved' ? 'APPROVED AND COMPLETED' : this.superAdjustment.status);
    },
    nextPhase() {
      const d = (this.approval.phases || []).filter(p => p.done).length;
      return d + 1;
    },
    tiles() {
      return [
        { label: this.$t('Warehouse'), value: (this.superAdjustment.warehouse && this.superAdjustment.warehouse.name) || '-', icon: 'building', tone: 'navy' },
        { label: this.$t('Date'), value: this.$date(this.superAdjustment.date), icon: 'calendar', tone: 'amber' },
        { label: this.$t('Submitted by'), value: (this.superAdjustment.user && this.superAdjustment.user.name) || '-', icon: 'user', tone: 'violet' },
        { label: this.$t('Items'), value: (this.items || []).length, icon: 'collection', tone: 'indigo' },
      ];
    },
    filteredItems() {
      const query = this.itemsSearch.trim().toLowerCase();
      if (!query) {
        return this.items;
      }
      return this.items.filter(row => (row.name ?? '').toLowerCase().includes(query) || (row.code ?? '').toLowerCase().includes(query));
    },
    sortedItems() {
      if (!this.itemsSort) {
        return this.filteredItems;
      }

      const column = this.itemsSort;
      const direction = this.itemsSortDirection === 'asc' ? 1 : -1;
      const numericColumns = ['system_quantity_at_submission', 'system_quantity_live', 'physical_quantity'];

      return [...this.filteredItems].sort((a, b) => {
        let valueA;
        let valueB;
        if (column === 'variance') {
          valueA = this.signedOutcomeQuantity(a);
          valueB = this.signedOutcomeQuantity(b);
        } else if (numericColumns.includes(column)) {
          valueA = Number(a[column] ?? 0);
          valueB = Number(b[column] ?? 0);
        } else {
          valueA = (a[column] ?? '').toString().toLowerCase();
          valueB = (b[column] ?? '').toString().toLowerCase();
        }

        if (valueA < valueB) return -1 * direction;
        if (valueA > valueB) return 1 * direction;
        return 0;
      });
    },
  },

  mounted() {
    // Capture phase so this also fires for scrolling inside the items
    // table's own overflow-x-auto container, not just the window itself.
    window.addEventListener('scroll', this.hideTooltip, true);
    window.addEventListener('resize', this.hideTooltip);
  },

  beforeUnmount() {
    window.removeEventListener('scroll', this.hideTooltip, true);
    window.removeEventListener('resize', this.hideTooltip);
  },

  methods: {
    // Editing an Approved or Rejected count reopens it as Draft (an Approved one has its stock changes reversed), so ask first.
    async goEdit() {
      if (this.superAdjustment.approval_locked) {
        await this.$alert(this.superAdjustment.approval_locked_message);
        return;
      }
      if (this.superAdjustment.status !== 'Draft') {
        const ok = await this.$confirm(
          this.$t('Editing :ref returns it to Draft', { ref: this.superAdjustment.reference }) +
            (this.superAdjustment.status === 'Approved' ? ' ' + this.$t('and reverses the stock changes it applied. It must be approved again afterwards.') : '. ' + this.$t('It must be approved again afterwards.')),
          { confirmText: this.$t('Edit') }
        );
        if (!ok) return;
      }
      this.$inertia.visit(this.route('super-adjustments.edit', this.superAdjustment.id));
    },
    // Positions the single shared, teleported tooltip against whichever
    // trigger fired it — clamped horizontally to the viewport and flipped
    // to appear below the trigger when there isn't room above, so it always
    // stays fully visible regardless of table scroll position or screen size.
    showTooltip(event, text) {
      const rect = event.currentTarget.getBoundingClientRect();
      const width = Math.min(260, Math.max(180, window.innerWidth - 24));
      const gap = 10;
      const placement = rect.top < 110 ? 'below' : 'above';
      let left = rect.left + rect.width / 2 - width / 2;
      left = Math.max(8, Math.min(left, window.innerWidth - width - 8));
      const triggerCenter = rect.left + rect.width / 2;
      const arrowLeft = Math.max(12, Math.min(width - 12, triggerCenter - left));

      this.tooltip = {
        visible: true,
        text,
        top: placement === 'above' ? rect.top - gap : rect.bottom + gap,
        left,
        arrowLeft,
        width,
        placement,
      };
    },
    hideTooltip() {
      this.tooltip.visible = false;
    },
    setItemsSort(column) {
      if (this.itemsSort === column) {
        this.itemsSortDirection = this.itemsSortDirection === 'asc' ? 'desc' : 'asc';
      } else {
        this.itemsSort = column;
        this.itemsSortDirection = 'asc';
      }
    },
    itemsSortIndicator(column) {
      if (this.itemsSort !== column) return '';
      return this.itemsSortDirection === 'asc' ? '▲' : '▼';
    },
    stockShifted(row) {
      return Math.abs(row.system_quantity_at_submission - row.system_quantity_live) > 0.0001;
    },
    // Explains WHY the live figure differs from the submission-time snapshot —
    // the reason is different depending on whether this Super Adjustment's
    // own approval is what moved it, or something else did.
    stockShiftReason(row) {
      const from = this.$number(row.system_quantity_at_submission);
      const now = this.$number(row.system_quantity_live);
      const physical = this.$number(row.physical_quantity);
      const matchesPhysical = Math.abs(row.system_quantity_live - row.physical_quantity) < 0.0001;

      if (this.superAdjustment.status === 'Approved' && matchesPhysical) {
        return (
          this.$t('Approving this Super Adjustment corrected the stock figure') +
          ` (${from} → ${physical}). ` +
          this.$t("That correction is this record's own effect, not an unrelated change.")
        );
      }
      if (this.superAdjustment.status === 'Draft') {
        return (
          this.$t('Stock has changed in the system since this item was counted') +
          ` (${from} → ${now}). ` +
          this.$t('Approval will be calculated against this live figure, not the one captured at submission.')
        );
      }
      if (this.superAdjustment.status === 'Rejected') {
        return (
          this.$t('Stock has changed in the system since this item was counted') +
          ` (${from} → ${now}). ` +
          this.$t('This Super Adjustment was rejected, so it never affected stock, this change came from something else.')
        );
      }
      return (
        this.$t('Stock has moved again since this Super Adjustment was processed') +
        ` (${this.$t('now')} ${now}). ` +
        this.$t('A change unrelated to this record affected it.')
      );
    },
    // outcome/outcome_quantity are computed server-side
    // (SuperAdjustmentController::itemOutcome) — for an Approved record this
    // reflects which real Adjustment the item actually landed in (fixed
    // history), not a client-side recomputation that could drift from what
    // approve() actually decided if stock moved between submission and
    // approval. For Draft/Rejected it's the live-stock-based preview of what
    // approving right now would do.
    signedOutcomeQuantity(row) {
      if (row.outcome === 'Addition') return row.outcome_quantity;
      if (row.outcome === 'Subtraction') return -row.outcome_quantity;
      return 0;
    },
    variancePillClass(row) {
      if (row.outcome === 'Addition') return 'sa-pill-addition';
      if (row.outcome === 'Subtraction') return 'sa-pill-subtraction';
      return 'sa-pill-match';
    },
    varianceIcon(row) {
      if (row.outcome === 'Addition') return 'up';
      if (row.outcome === 'Subtraction') return 'down';
      return 'tick';
    },
    varianceLabel(row) {
      if (row.outcome === 'Match') return this.$t('Match');
      const sign = row.outcome === 'Addition' ? '+' : '-';
      return sign + this.$number(row.outcome_quantity);
    },
    varianceTooltip(row) {
      const abs = this.$number(row.outcome_quantity);
      const approved = this.superAdjustment.status === 'Approved';

      if (row.outcome === 'Match') {
        return approved
          ? this.$t('Physical count matched system stock exactly, no correction was made for this item.')
          : this.$t('Physical count matched system stock exactly, no correction is needed for this item.');
      }
      if (row.outcome === 'Addition') {
        return (
          this.$t('Addition') +
          ' - ' +
          this.$t('the physical count was higher than system stock by') +
          ` ${abs}. ` +
          (approved
            ? this.$t('That amount was added to stock when this Super Adjustment was approved.')
            : this.$t('Approving will add that amount to stock.'))
        );
      }
      return (
        this.$t('Subtraction') +
        ' - ' +
        this.$t('the physical count was lower than system stock by') +
        ` ${abs}. ` +
        (approved
          ? this.$t('That amount was removed from stock when this Super Adjustment was approved.')
          : this.$t('Approving will remove that amount from stock.'))
      );
    },
    async confirmApprove() {
      if (!(await this.$confirm(this.$t('Approving will create real Adjustment(s) and immediately change stock quantities. Continue?'), { variant: 'danger' }))) {
        return;
      }
      this.$inertia.put(this.route('super-adjustments.approve', this.superAdjustment.id), {}, { preserveScroll: true });
    },
    openReject() {
      this.rejectionReason = '';
      this.rejecting = true;
    },
    submitReject() {
      this.rejectForm.rejection_reason = this.rejectionReason;
      this.rejectForm.put(this.route('super-adjustments.reject', this.superAdjustment.id), {
        preserveScroll: true,
        onSuccess: () => (this.rejecting = false),
      });
    },
  },
};
</script>

<style scoped>
.sa-phases { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(min(260px, 100%), 1fr)); }
.sa-phase { display: flex; flex-direction: column; gap: 3px; padding: 12px 14px; border-radius: 16px; background: var(--lg-soft); box-shadow: inset 0 0 0 1px var(--lg-line, rgba(148, 163, 184, 0.3)); }
.sa-phase.is-done { background: rgba(16, 185, 129, 0.1); box-shadow: inset 0 0 0 1px rgba(16, 185, 129, 0.45); }
.sa-phase.is-next { box-shadow: inset 0 0 0 2px rgba(47, 143, 230, 0.55); }
.sa-phase-n { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-mute, #64748b); }
.sa-phase.is-done.is-p1 { background: rgba(249, 115, 22, 0.12); box-shadow: inset 0 0 0 1px rgba(234, 88, 12, 0.55); }
.sa-phase.is-done.is-p1 .sa-phase-who { color: #c2410c; }
.sa-final { display: inline-flex; align-items: center; gap: 6px; background: #0b5d3b; color: #fff; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; }
.sa-phase-who { display: inline-flex; align-items: center; gap: 6px; font-size: 0.98rem; color: #047857; }
.sa-phase-who.sa-wait { color: #b45309; }
.sa-phase-meta { font-size: 0.78rem; color: var(--lg-mute, #64748b); }
.sa-void { margin-top: 10px; font-size: 0.8rem; color: var(--lg-mute, #64748b); }
.sa-void ul { margin: 6px 0 0 18px; list-style: disc; }
.sa-kv { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(min(230px, 100%), 1fr)); }
.sa-kv > div { display: flex; flex-direction: column; align-items: flex-start; gap: 3px; min-width: 0; padding: 10px 14px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.sa-wide { grid-column: 1 / -1; }
.sa-k { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.sa-v { font-size: 0.9rem; font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }
.sa-prose { font-weight: 500; white-space: pre-line; line-height: 1.55; }
.sa-link { color: #2563eb; }
.sa-link:hover { text-decoration: underline; }
html.dark .sa-link { color: #93c5fd; }
.sa-muted { color: var(--lg-faint); }

.sa-search { position: relative; max-width: 24rem; margin-bottom: 1rem; }
.sa-items-search-icon { position: absolute; left: 0.8rem; top: 50%; transform: translateY(-50%); color: var(--lg-faint); pointer-events: none; }
.sa-items-search-input { width: 100%; padding: 8px 12px 8px 2.3rem; border-radius: 9999px; font-size: 0.82rem; color: var(--lg-ink); background: var(--lg-soft); border: 1px solid var(--lg-line); transition: border-color 0.15s ease, box-shadow 0.15s ease; }
.sa-items-search-input:focus { outline: none; border-color: #818cf8; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.18); }
.sa-sort-indicator { display: inline-block; width: 0.7rem; font-size: 0.65rem; color: #6366f1; }

.lg-table tr.is-shifted td { background: rgba(245, 158, 11, 0.07); }
.sa-shift-val { font-weight: 800; color: #b45309; }
html.dark .sa-shift-val { color: #fcd34d; }
.sa-tooltip-icon { display: inline-flex; align-items: center; justify-content: center; cursor: help; outline: none; }

.sa-variance-pill { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.15rem 0.65rem; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; cursor: help; outline: none; font-variant-numeric: tabular-nums; }
.sa-pill-match { background: var(--lg-soft); color: var(--lg-faint); border: 1px solid var(--lg-line); }
.sa-pill-addition { background: rgba(16, 185, 129, 0.14); color: #047857; }
.sa-pill-subtraction { background: rgba(244, 63, 94, 0.13); color: #be123c; }
html.dark .sa-pill-addition { color: #6ee7b7; }
html.dark .sa-pill-subtraction { color: #fda4af; }

/* Teleported to <body> — fixed positioning computed in JS (showTooltip) so
   it escapes the items table's overflow-x-auto clipping and stays inside
   the viewport (flips below the trigger, clamps horizontally) regardless of
   scroll position or screen size. Vue keeps this scoped style's data-v-*
   attribute on Teleport children even after they move in the DOM, so it
   still applies here despite living outside this component's own tree. */
.sa-teleport-tooltip {
  position: fixed;
  z-index: 9999;
  background: #1f2937;
  color: #f8fafc;
  font-size: 0.72rem;
  font-weight: 500;
  line-height: 1.42;
  text-align: left;
  padding: 0.55rem 0.7rem;
  border-radius: 0.6rem;
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.3);
  pointer-events: none;
}
.sa-tooltip-above { transform: translateY(-100%); }
.sa-tooltip-below { transform: translateY(0); }
.sa-tooltip-arrow {
  position: absolute;
  width: 0;
  height: 0;
  border: 5px solid transparent;
  margin-left: -5px;
}
.sa-tooltip-above .sa-tooltip-arrow { top: 100%; border-top-color: #1f2937; }
.sa-tooltip-below .sa-tooltip-arrow { bottom: 100%; border-bottom-color: #1f2937; }

@media (max-width: 480px) {
  .sa-teleport-tooltip { font-size: 0.68rem; }
}

.sa-cleared-tag { display: inline-block; white-space: nowrap; margin-left: 0.4rem; padding: 0.05rem 0.55rem; border-radius: 9999px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.02em; background: rgba(244, 63, 94, 0.13); color: #be123c; vertical-align: middle; cursor: help; outline: none; }
html.dark .sa-cleared-tag { color: #fda4af; }
/* the item cell is ONE block (name, then reference and Cleared tag), so on a phone it never splits into narrow columns */
.sa-itemcell { min-width: 0; }
.sa-itemmeta { white-space: nowrap; }
@media (max-width: 720px) {
  .sa-itemcell { text-align: right; display: grid; justify-items: end; gap: 4px; min-width: 0; }
  .sa-itemcell .lg-main { display: block; white-space: normal; overflow-wrap: anywhere; }
  .sa-itemmeta { display: flex; flex-wrap: wrap; justify-content: flex-end; align-items: center; gap: 6px; }
  .sa-itemmeta .sa-cleared-tag { margin-left: 0; }
}

.sa-files { display: flex; flex-direction: column; gap: 8px; }
.sa-file { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.sa-file-ico { display: inline-flex; flex: none; color: var(--lg-faint); }
.sa-file-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.85rem; font-weight: 600; color: var(--lg-ink); }

.sa-role { margin-left: 6px; vertical-align: middle; }
.sa-auto { display: block; margin-top: 2px; font-size: 0.72rem; font-weight: 600; color: var(--lg-faint); }
.sa-modal-title { display: inline-flex; align-items: center; gap: 12px; font-weight: 800; }
.sa-textarea { width: 100%; padding: 10px 12px; border-radius: 12px; font-size: 0.86rem; border: 1px solid #d1d5db; background: transparent; }
.sa-textarea:focus { outline: none; border-color: #f43f5e; box-shadow: 0 0 0 3px rgba(244, 63, 94, 0.18); }
html.dark .sa-textarea { border-color: rgba(255, 255, 255, 0.15); color: #f1f5f9; }
</style>

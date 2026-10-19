<template>
  <div v-if="adjustment" class="lg-page dt-doc print:h-full print:block print:m-0 print:pt-0 print:bg-white">
    <div class="hidden print:flex items-center justify-between gap-6 mb-5 pb-4 border-b border-slate-200">
      <div class="flex items-center gap-4">
        <img src="/images/logo.png" alt="RANS Logo" class="h-16 w-auto" />
        <div>
          <div class="text-2xl font-bold text-slate-800">Adjustment</div>
          <div class="text-sm text-slate-500">RANS WIMS</div>
        </div>
      </div>
      <div class="text-right text-sm text-slate-600">
        <div class="font-semibold text-slate-800">{{ adjustment.reference }}</div>
        <div>{{ adjustment.warehouse.name }} ({{ adjustment.warehouse.code }})</div>
      </div>
    </div>

    <div class="relative z-[1]">
      <div v-if="adjustment.status" class="dt-status print:hidden" :class="'is-' + String(adjustment.status).toLowerCase()" role="status">
        <span class="dt-status-ico"><icons :name="statusIcon" class="w-5 h-5" /></span>
        <div class="min-w-0 flex-1">
          <b>{{ statusHeadline }}</b>
          <span v-if="statusDetail" class="dt-status-sub">{{ statusDetail }}</span>
          <span v-if="adjustment.status === 'Rejected' && adjustment.rejection_reason" class="dt-status-sub"><b>{{ $t('Reason') }}:</b> {{ adjustment.rejection_reason }}</span>
        </div>
      </div>
      <trashed-message v-if="adjustment.deleted_at" class="mb-4 h-12 print:hidden" :action="false">
        {{ $t('This record has been deleted.') }}
      </trashed-message>

      <!-- Hero -->
      <section class="dt-hero print:hidden">
        <span class="lg-card-ico dt-hero-ico" :class="isSuperAdjustment ? 'lg-ico-rose' : 'lg-ico-violet'"><icons name="adjustments" class="w-6 h-6" /></span>
        <div class="min-w-0 flex-1">
          <div class="dt-kicker" :class="{ 'dt-super': isSuperAdjustment }">{{ isSuperAdjustment ? $t('Super Adjustment') : $t('Adjustment') }}</div>
          <h2 class="dt-ref">{{ adjustment.reference }}</h2>
          <div class="dt-chips">
            <span class="lg-chip lg-chip-blue"><icons name="building" class="w-3 h-3" />{{ adjustment.warehouse.name }} ({{ adjustment.warehouse.code }})</span>
            <span class="lg-chip lg-chip-slate"><icons name="calendar" class="w-3 h-3" />{{ $date(adjustment.date) }}</span>
            <span v-if="adjustment.type" class="lg-chip lg-chip-violet">{{ adjustment.type }}</span>
            <span v-if="adjustment.status" class="lg-chip" :class="chipClass"><icons :name="statusIcon" class="w-3 h-3" />{{ $t(statusWord) }}</span>
            <span v-if="adjustment.approved_at" class="lg-chip lg-chip-green"><icons name="tick" class="w-3 h-3" />{{ $t('Approved at') }} {{ $datetime(adjustment.approved_at) }}</span>
          </div>
        </div>
        <div class="dt-barcode">
          <svg id="barcode" class="mx-auto"></svg>
        </div>
      </section>

      <div class="hidden print:block mb-4">
        <svg id="barcode-print" class="mx-auto"></svg>
      </div>

      <stat-tiles :items="tiles" />

      <!-- Info -->
      <section class="lg-card dt-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h3 class="lg-card-title">{{ $t('Details') }}</h3>
          </div>
        </header>
        <div class="dt-kv">
          <div>
            <span class="dt-k">{{ $t('Type') }}</span>
            <span class="dt-v">{{ adjustment.type }}</span>
          </div>
          <div>
            <span class="dt-k">{{ $t('Reference') }}</span>
            <span class="dt-v">{{ adjustment.reference }}</span>
          </div>
          <div>
            <span class="dt-k">{{ $t('Date') }}</span>
            <span class="dt-v">{{ $date(adjustment.date) }}</span>
          </div>
          <div>
            <span class="dt-k">{{ $t('Created at') }}</span>
            <span class="dt-v">{{ $datetime(adjustment.created_at) }}</span>
          </div>
          <div v-if="adjustment.status">
            <span class="dt-k">{{ $t('Status') }}</span>
            <span class="dt-v"><span class="lg-chip" :class="chipClass"><icons :name="statusIcon" class="w-3 h-3" />{{ $t(statusWord) }}</span></span>
          </div>
          <div v-if="adjustment.user">
            <span class="dt-k">{{ $t('Created by') }}</span>
            <span class="dt-v">{{ adjustment.user.name }}</span>
          </div>
          <div v-if="adjustment.approver && adjustment.approved_at">
            <span class="dt-k">{{ $t('Approved by') }}</span>
            <span class="dt-v">{{ adjustment.approver.name }}<span v-if="adjustment.approved_by_role" class="lg-chip lg-chip-indigo dt-role">{{ adjustment.approved_by_role }}</span></span>
          </div>
          <div v-if="adjustment.rejecter && adjustment.rejected_at">
            <span class="dt-k">{{ $t('Rejected by') }}</span>
            <span class="dt-v">{{ adjustment.rejecter.name }}</span>
          </div>
          <div v-if="adjustment.rejected_at">
            <span class="dt-k">{{ $t('Rejected at') }}</span>
            <span class="dt-v">{{ $datetime(adjustment.rejected_at) }}</span>
          </div>
          <div v-if="adjustment.rejection_reason">
            <span class="dt-k">{{ $t('Rejection reason') }}</span>
            <span class="dt-v">{{ adjustment.rejection_reason }}</span>
          </div>
          <div v-if="adjustment.approved_at">
            <span class="dt-k">{{ $t('Approved at') }}</span>
            <span class="dt-v">{{ $datetime(adjustment.approved_at) }}</span>
          </div>
        </div>
      </section>

      <!-- Items -->
      <section class="lg-card dt-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="collection" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h3 class="lg-card-title">{{ $t('Items') }}</h3>
            <p class="lg-card-sub">{{ (adjustment.items || []).length }} {{ $t('items') }}</p>
          </div>
        </header>
        <div class="lg-table-wrap" @scroll.passive="hideTooltip">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('No') }}</th>
                <th>{{ $t('Item') }}</th>
                <th class="num">{{ $t('Quantity') }}</th>
                <th class="num">{{ $t('Unit') }}</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="(item, ii) in adjustment.items" :key="'i_' + item.id">
                <template v-if="item.variations.length">
                  <tr v-for="(variation, vi) in item.variations" :key="'v_' + variation.id">
                    <td class="dt-muted" :data-label="$t('No')">{{ ii + 1 }}</td>
                    <td :data-label="$t('Item')">
                      <div class="lg-main">{{ $t(item.item.name) }} <span class="dt-code">({{ item.item.code }})</span></div>
                      <div class="lg-subtext"><span v-html="$meta(variation.meta)"></span></div>
                    </td>
                    <td class="num" :data-label="$t('Quantity')">{{ $number(variation.pivot.quantity) }}</td>
                    <td class="num dt-muted" :data-label="$t('Unit')">{{ item.unit ? item.unit.code : '—' }}</td>
                  </tr>
                </template>
                <tr v-else>
                  <td class="dt-muted" :data-label="$t('No')">{{ ii + 1 }}</td>
                  <td :data-label="$t('Item')">
                    <div class="lg-main">
                      {{ $t(item.item.name) }} <span class="dt-code">({{ item.item.code }})</span>
                      <span
                        v-if="isCleared(item)"
                        class="dt-cleared-tag"
                        tabindex="0"
                        @mouseenter="showTooltip($event, $t('Not physically counted, cleared to zero by the Super Adjustment that generated this record, because it still had stock in the system.'))"
                        @mouseleave="hideTooltip"
                        @focus="showTooltip($event, $t('Not physically counted, cleared to zero by the Super Adjustment that generated this record, because it still had stock in the system.'))"
                        @blur="hideTooltip"
                      >{{ $t('Cleared') }}</span>
                    </div>
                  </td>
                  <td class="num lg-main" :data-label="$t('Quantity')">{{ $number(item.quantity) }}</td>
                  <td class="num dt-muted" :data-label="$t('Unit')">{{ item.unit ? item.unit.code : '—' }}</td>
                </tr>
              </template>
              <tr v-if="!adjustment.items || !adjustment.items.length" class="lg-empty-row">
                <td colspan="4" class="lg-empty">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Attachments -->
      <section v-if="adjustment.attachments && adjustment.attachments.length" class="lg-card dt-card print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h3 class="lg-card-title">{{ $t('Attachments') }}</h3>
          </div>
        </header>
        <ul class="dt-files">
          <li v-for="attachment in adjustment.attachments" :key="attachment.id" class="dt-file">
            <icons name="clip" class="w-4 h-4 flex-none dt-muted" />
            <span class="dt-file-name">{{ attachment.title }}</span>
            <a class="lg-btn lg-btn-soft" :href="route('media.download', attachment.id)">
              <icons name="download" class="w-3.5 h-3.5" />{{ $t('Download') }}
            </a>
          </li>
        </ul>
      </section>

      <section v-if="adjustment.details" class="lg-card dt-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h3 class="lg-card-title">{{ $t('Notes') }}</h3>
          </div>
        </header>
        <p class="dt-notes">{{ $t(adjustment.details) }}</p>
      </section>

      <div class="pt-2 pb-4 w-full text-center text-sm text-gray-500 hidden print:block">
        {{ $t('This is computer generated document, no signature required.') }}
      </div>
    </div>

    <!-- Single shared tooltip, teleported to <body> and positioned via
         getBoundingClientRect (same mechanism as SuperAdjustment/Show.vue) so
         it escapes any ancestor overflow clipping and stays within the
         viewport regardless of scroll position or screen size. -->
    <Teleport to="body">
      <div
        v-if="tooltip.visible"
        class="dt-teleport-tooltip"
        :class="'dt-tooltip-' + tooltip.placement"
        :style="{ top: tooltip.top + 'px', left: tooltip.left + 'px', width: tooltip.width + 'px' }"
      >
        {{ tooltip.text }}
        <span class="dt-tooltip-arrow" :style="{ left: tooltip.arrowLeft + 'px' }"></span>
      </div>
    </Teleport>
  </div>
</template>

<script>
import JsBarcode from 'jsbarcode';
import TrashedMessage from '@/Shared/TrashedMessage.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import { adjustmentStatus } from '@/Shared/Adjustment/status';

export default {
  props: { adjustment: Object },

  components: { TrashedMessage, StatTiles },

  data() {
    return {
      loading: false,
      tooltip: { visible: false, text: '', top: 0, left: 0, arrowLeft: 0, width: 260, placement: 'above' },
    };
  },

  computed: {
    statusWord() {
      return adjustmentStatus.label(this.adjustment.status);
    },
    statusIcon() {
      return adjustmentStatus.icon(this.adjustment.status);
    },
    chipClass() {
      return adjustmentStatus.chip(this.adjustment.status);
    },
    statusHeadline() {
      const a = this.adjustment;
      if (a.status === 'Approved') return this.$t('Approved') + (a.approver ? ' · ' + a.approver.name + (a.approved_by_role ? ' (' + a.approved_by_role + ')' : '') : '');
      if (a.status === 'Rejected') return this.$t('Rejected') + (a.rejecter ? ' · ' + a.rejecter.name : '');
      return this.$t('Pending approval');
    },
    statusDetail() {
      const a = this.adjustment;
      if (a.status === 'Approved') return a.approved_at ? this.$datetime(a.approved_at) + ' - ' + this.$t('stock has been updated.') : '';
      if (a.status === 'Rejected') return a.rejected_at ? this.$datetime(a.rejected_at) + ' - ' + this.$t('stock was not changed.') : '';
      return this.$t('Stock changes are applied once an Admin approves this record.');
    },
    isSuperAdjustment() {
      return !!this.adjustment?.extra_attributes?.super_adjustment_reference;
    },
    tiles() {
      return [
        { label: this.$t('Items'), value: (this.adjustment.items || []).length, icon: 'collection', tone: 'violet' },
        { label: this.$t('Type'), value: this.adjustment.type || '-', icon: 'adjustments', tone: 'indigo' },
        { label: this.$t('Attachments'), value: (this.adjustment.attachments || []).length, icon: 'clip', tone: 'slate' },
      ];
    },
  },

  mounted() {
    this.renderBarcode();
    // Capture phase so this also fires for scrolling inside any ancestor
    // overflow-x-auto container, not just the window itself.
    window.addEventListener('scroll', this.hideTooltip, true);
    window.addEventListener('resize', this.hideTooltip);
  },

  updated() {
    this.renderBarcode();
  },

  beforeUnmount() {
    window.removeEventListener('scroll', this.hideTooltip, true);
    window.removeEventListener('resize', this.hideTooltip);
  },

  methods: {
    renderBarcode() {
      JsBarcode('#barcode', this.adjustment.reference, { width: 1.4, margin: 0, fontSize: 0, height: 32, format: 'CODE128' });
      JsBarcode('#barcode-print', this.adjustment.reference, { width: 1, margin: 0, fontSize: 0, height: 20, format: 'CODE128' });
    },
    isCleared(item) {
      const ids = this.adjustment?.extra_attributes?.cleared_item_ids || [];
      return ids.includes(item.item_id);
    },
    // Positions the single shared, teleported tooltip against whichever
    // trigger fired it — clamped horizontally to the viewport and flipped
    // to appear below the trigger when there isn't room above.
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
  },
};
</script>
<style scoped>
.dt-hero { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding: 1rem 1.2rem; margin-bottom: 1rem; border-radius: 22px; background: linear-gradient(135deg, rgba(224, 233, 250, 0.8), rgba(255, 251, 235, 0.65)); border: 1px solid rgba(255, 255, 255, 0.8); box-shadow: var(--lg-shadow); }
html.dark .dt-hero { background: linear-gradient(135deg, rgba(28, 63, 102, 0.45), rgba(15, 23, 42, 0.7)); border-color: var(--lg-line); }
.dt-hero-ico { width: 48px; height: 48px; border-radius: 16px; }
.dt-kicker { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.dt-ref { font-size: 1.2rem; font-weight: 800; line-height: 1.2; color: var(--lg-ink); overflow-wrap: anywhere; }
.dt-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.dt-barcode { flex: none; max-width: 100%; padding: 6px 12px; border-radius: 14px; background: #fff; border: 1px solid var(--lg-line); overflow: hidden; }
.dt-barcode svg { max-width: 100%; height: auto; }


.dt-kv { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr)); }
.dt-kv > div { display: flex; flex-direction: column; align-items: flex-start; gap: 3px; min-width: 0; padding: 10px 14px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.dt-k { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.dt-v { font-size: 0.9rem; font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }

.dt-status { display: flex; align-items: center; gap: 12px; margin-bottom: 1rem; padding: 0.8rem 1rem; border-radius: 18px; border: 1px solid transparent; }
.dt-status-ico { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 12px; }
.dt-status b { display: block; font-size: 0.92rem; color: var(--lg-ink); overflow-wrap: anywhere; }
.dt-status-sub { display: block; margin-top: 2px; font-size: 0.78rem; color: var(--lg-mute); overflow-wrap: anywhere; }
.dt-status.is-pending { background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.35); }
.dt-status.is-pending .dt-status-ico { background: rgba(245, 158, 11, 0.2); color: #b45309; }
.dt-status.is-approved { background: rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.35); }
.dt-status.is-approved .dt-status-ico { background: rgba(16, 185, 129, 0.2); color: #047857; }
.dt-status.is-rejected { background: rgba(239, 68, 68, 0.09); border-color: rgba(239, 68, 68, 0.35); }
.dt-status.is-rejected .dt-status-ico { background: rgba(239, 68, 68, 0.18); color: #b91c1c; }
.dt-role { margin-left: 8px; vertical-align: middle; }
.dt-super { color: #800000 !important; }
html.dark .dt-super { color: #f87171 !important; }
.dt-cleared-tag { display: inline-block; margin-left: 0.4rem; padding: 0.05rem 0.55rem; border-radius: 9999px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.02em; background: rgba(244, 63, 94, 0.13); color: #be123c; vertical-align: middle; cursor: help; outline: none; }
html.dark .dt-cleared-tag { color: #fda4af; }
/* Teleported to <body>: fixed position computed in JS (showTooltip), so it escapes any overflow clipping. */
.dt-teleport-tooltip { position: fixed; z-index: 9999; background: #1f2937; color: #f8fafc; font-size: 0.72rem; font-weight: 500; line-height: 1.42; text-align: left; padding: 0.55rem 0.7rem; border-radius: 0.6rem; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.3); pointer-events: none; }
.dt-tooltip-above { transform: translateY(-100%); }
.dt-tooltip-below { transform: translateY(0); }
.dt-tooltip-arrow { position: absolute; width: 0; height: 0; border: 5px solid transparent; margin-left: -5px; }
.dt-tooltip-above .dt-tooltip-arrow { top: 100%; border-top-color: #1f2937; }
.dt-tooltip-below .dt-tooltip-arrow { bottom: 100%; border-bottom-color: #1f2937; }
@media (max-width: 480px) { .dt-teleport-tooltip { font-size: 0.68rem; } }

.dt-code { font-weight: 600; color: var(--lg-faint); }
.dt-muted { color: var(--lg-faint); }
.dt-total { display: flex; align-items: center; justify-content: flex-end; gap: 14px; margin-top: 12px; padding: 10px 16px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); font-size: 0.78rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute); }
.dt-total b { font-size: 1.05rem; letter-spacing: 0; text-transform: none; color: var(--lg-ink); font-variant-numeric: tabular-nums; }

.dt-files { display: flex; flex-direction: column; gap: 8px; }
.dt-file { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.dt-file-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.85rem; font-weight: 600; color: var(--lg-ink); }
.dt-notes { font-size: 0.88rem; line-height: 1.6; white-space: pre-line; color: var(--lg-ink); }

@media (max-width: 767.98px) {
  .dt-barcode { width: 100%; text-align: center; }
}
@media print {
  .dt-doc .lg-card { padding: 12px 14px; border-radius: 0; box-shadow: none; border: 1px solid #e2e8f0; background: #fff; }
  .dt-doc .lg-table-wrap { border: 0; }
}
</style>

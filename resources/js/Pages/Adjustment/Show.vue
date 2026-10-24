<template>
  <admin-layout :title="$t('Adjustment Details')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="adjustments" :title="adjustment.reference" :subtitle="$t('Please review the adjustment details below')">
        <span class="lg-chip" :class="statusChip(adjustment.status)"><icons :name="statusIcon(adjustment.status)" class="w-3 h-3" />{{ $t(statusLabel(adjustment.status)) }}</span>
        <approval-actions :adjustment="adjustment" />
        <change-status :adjustment="adjustment" />
        <Link :href="route('adjustments.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('Adjustments') }}</Link>
        <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Print')" :aria-label="$t('Print')" @click="print()">
          <icons name="printer" class="w-4 h-4" />
        </button>
        <a v-if="$can('print-adjustments')" :href="route('adjustments.pdf', adjustment.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')">
          <icons name="download" class="w-4 h-4" />
        </a>
        <Link v-if="$can('update-adjustments') && (adjustment.status !== 'Rejected' || $super)" :href="route('adjustments.edit', adjustment.id)" class="lg-btn lg-btn-primary"><icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}</Link>
      </page-header>

      <adjustment-details v-if="adjustment" :adjustment="adjustment" :differences="differences" :value-report="valueReport" />
    </div>
  </admin-layout>
</template>

<script>
import AdjustmentDetails from './Details.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import ApprovalActions from '@/Shared/Adjustment/ApprovalActions.vue';
import ChangeStatus from '@/Shared/Adjustment/ChangeStatus.vue';
import { adjustmentStatusMethods } from '@/Shared/Adjustment/status';

export default {
  props: { adjustment: Object, differences: { type: Object, default: null }, valueReport: { type: Object, default: null } },
  components: { AdminLayout, AdjustmentDetails, PageHeader, ApprovalActions, ChangeStatus },
  methods: {
    ...adjustmentStatusMethods,
    print() {
      // Open the redesigned print document when the user may print; otherwise fall back to the page print.
      if (this.$can('print-adjustments')) {
        window.open(this.route('adjustments.print', this.adjustment.id), '_blank');
        return;
      }
      window.print();
    },
  },
};
</script>

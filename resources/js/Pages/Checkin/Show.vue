<template>
  <admin-layout :title="$t('Checkin Details')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="in" :title="checkin.reference" :subtitle="$t('Please review the checkin details below')">
        <Link :href="route('checkins.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('Checkins') }}</Link>
        <delivery-note-action :state="deliveryNote" />
        <gate-pass-action :state="gatePass" />
        <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Print')" :aria-label="$t('Print')" @click="print()">
          <icons name="printer" class="w-4 h-4" />
        </button>
        <Link v-if="$can('update-checkins')" :href="route('checkins.edit', checkin.id)" class="lg-btn lg-btn-primary"><icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}</Link>
      </page-header>

      <checkin-details v-if="checkin" :checkin="checkin" />
    </div>
  </admin-layout>
</template>

<script>
import CheckinDetails from './Details.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DeliveryNoteAction from '@/Shared/DeliveryNoteAction.vue';
import GatePassAction from '@/Shared/GatePassAction.vue';

export default {
  props: { checkin: Object, deliveryNote: Object, gatePass: Object },
  components: { AdminLayout, CheckinDetails, PageHeader, DeliveryNoteAction, GatePassAction },
  methods: {
    print() {
      // Open the redesigned print document when the user may print checkins; otherwise fall back to the page print.
      if (this.$can('print-checkins')) {
        window.open(this.route('checkins.print', this.checkin.id), '_blank');
        return;
      }
      window.print();
    },
  },
};
</script>

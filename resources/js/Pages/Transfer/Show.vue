<template>
  <admin-layout :title="$t('Transfer Details')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="truck" :title="transfer.reference" :subtitle="$t('Please review the transfer details below')">
        <Link :href="route('transfers.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('Transfers') }}</Link>
        <template v-if="transfer.approval_status === 'pending'">
          <span class="lg-chip lg-chip-amber"><icons name="clock" class="w-3.5 h-3.5" /> {{ $t('Awaiting Approval') }}</span>
          <button v-if="$can('approve-transfers')" type="button" class="lg-btn lg-btn-green" @click="approveThis">
            <icons name="tick" class="w-4 h-4" /> {{ $t('Approve') }}
          </button>
          <button v-if="$can('cancel-transfers')" type="button" class="lg-btn lg-btn-red" @click="cancelThis">
            <icons name="cross" class="w-4 h-4" /> {{ $t('Cancel transfer') }}
          </button>
        </template>
        <span v-else-if="transfer.approval_status === 'cancelled'" class="lg-chip lg-chip-red">
          <icons name="cross" class="w-3.5 h-3.5" /> {{ $t('Cancelled by') }} {{ transfer.cancelled_by_user ? transfer.cancelled_by_user.name : '—' }}
        </span>
        <span v-if="transfer.approval_status === 'approved' && transfer.approved_by_user" class="lg-chip lg-chip-blue">
          <icons name="tick" class="w-3.5 h-3.5" /> {{ $t('Approved by') }} {{ transfer.approved_by_user.name }}
        </span>
        <span v-if="transfer.received_at" class="lg-chip lg-chip-green">
          <icons name="tick" class="w-3.5 h-3.5" />
          {{ $t('Received') }} {{ $date(transfer.received_at) }}<template v-if="transfer.received_by_user"> · {{ transfer.received_by_user.name }}</template>
        </span>
        <button
          v-else-if="!transfer.draft && canDeliverThisTransfer"
          type="button"
          class="lg-btn lg-btn-green"
          :disabled="receiving"
          @click="markReceived()"
        >
          <icons name="tick" class="w-4 h-4" />
          {{ receiving ? $t('Saving…') : $t('Mark as Received') }}
        </button>
        <delivery-note-action :state="deliveryNote" />
        <gate-pass-action :state="gatePass" />
        <transport-route-action :state="transportRoute" />
        <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Print')" :aria-label="$t('Print')" @click="print()">
          <icons name="printer" class="w-4 h-4" />
        </button>
        <Link v-if="$can('update-transfers')" :href="route('transfers.edit', transfer.id)" class="lg-btn lg-btn-primary"><icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}</Link>
      </page-header>

      <transfer-details v-if="transfer" :transfer="transfer" />
    </div>
  </admin-layout>
</template>

<script>
import TransferDetails from './Details.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DeliveryNoteAction from '@/Shared/DeliveryNoteAction.vue';
import GatePassAction from '@/Shared/GatePassAction.vue';
import TransportRouteAction from '@/Shared/TransportRouteAction.vue';

export default {
  props: { transfer: Object, deliveryNote: Object, gatePass: Object, transportRoute: Object },
  components: { AdminLayout, TransferDetails, PageHeader, DeliveryNoteAction, GatePassAction, TransportRouteAction },
  data() {
    return { receiving: false };
  },
  computed: {
    // Mirrors TransferController::userMayDeliver() — Super Admin/Admin can
    // mark any transfer received; anyone else holding 'deliver-transfers'
    // (Engineers/"Site Engineer" by default) is limited to their own.
    canDeliverThisTransfer() {
      if (!this.$can('deliver-transfers')) return false;
      if (this.$admin) return true;
      const currentUserId = this.$page.props.auth?.user?.id;
      return currentUserId && this.transfer.user_id === currentUserId;
    },
  },
  methods: {
    approveThis() {
      this.$confirm(this.$t('Approve :ref? Stock will move from :from to :to.', { ref: this.transfer.reference, from: (this.transfer.from_warehouse || {}).name || '', to: (this.transfer.to_warehouse || {}).name || '' })).then(ok => {
        if (ok) this.$inertia.post(this.route('transfers.approve', this.transfer.id), {}, { preserveScroll: true });
      });
    },
    cancelThis() {
      this.$prompt(this.$t('Cancel :ref? You can add a reason (optional).', { ref: this.transfer.reference }), { variant: 'danger', placeholder: this.$t('Reason (optional)') }).then(reason => {
        if (reason === null || reason === false || reason === undefined) return;
        this.$inertia.post(this.route('transfers.cancel', this.transfer.id), { reason: typeof reason === 'string' ? reason : '' }, { preserveScroll: true });
      });
    },
    print() {
      // Open the redesigned print document when the user may print transfers; otherwise fall back to the page print.
      if (this.$can('print-transfers')) {
        window.open(this.route('transfers.print', this.transfer.id), '_blank');
        return;
      }
      window.print();
    },
    markReceived() {
      this.receiving = true;
      this.$inertia.post(this.route('transfers.receive', this.transfer.id), {}, {
        preserveScroll: true,
        onFinish: () => { this.receiving = false; },
      });
    },
  },
};
</script>

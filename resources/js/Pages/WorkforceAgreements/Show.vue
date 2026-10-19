<template>
  <admin-layout :title="agreement.reference">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="doc-text" :title="agreement.payee_name" :subtitle="agreement.reference + ' · ' + agreement.warehouse_name">
        <span class="lg-chip" :class="statusChip(agreement.status)">{{ agreement.status }}</span>
        <span class="lg-chip" :class="payChip(agreement.payment_status)">{{ agreement.payment_status }}</span>
        <Link v-if="can.update" :href="route('workforce-agreements.edit', agreement.id)" class="lg-btn lg-btn-primary">
          <icons name="edit" class="w-4 h-4" /> {{ $t('Edit') }}
        </Link>
        <button v-if="can.cancel" type="button" class="lg-btn lg-btn-red" @click="cancelAgreement"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel Agreement') }}</button>
        <Link :href="route('workforce-agreements.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Workforce Agreements') }}</Link>
        <a v-if="$can('print-workforce-agreements')" :href="route('workforce-agreements.document.print', agreement.id)" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </a>
        <a v-if="$can('print-workforce-agreements')" :href="route('workforce-agreements.document.pdf', agreement.id)" class="lg-btn lg-btn-dark">
          <icons name="download" class="w-4 h-4" />{{ $t('PDF') }}
        </a>
      </page-header>

      <stat-tiles :items="statTiles" />

      <div v-if="agreement.is_locked" class="lg-note lg-note-amber mb-4">
        <icons name="lock-closed" class="w-4 h-4" />
        <span>{{ $t('This agreement is locked: one or more Labour List requests already reference it. Corrections require a new agreement.') }}</span>
      </div>

      <section v-if="agreement.type_of_work" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="collection" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Type of Work') }}</h2></div>
        </header>
        <div class="text-sm wfa-body">{{ agreement.type_of_work }}</div>
      </section>

      <section v-if="agreement.scope_of_work" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-teal"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Scope of Work') }}</h2></div>
        </header>
        <div class="text-sm wfa-body">{{ agreement.scope_of_work }}</div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="send" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Payment Requests Against This Agreement') }}</h2></div>
        </header>
        <div v-if="agreement.requests.length" class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Labour List') }}</th>
                <th class="num">{{ $t('Requested') }}</th>
                <th class="num">{{ $t('Approved') }}</th>
                <th>{{ $t('Voucher') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in agreement.requests" :key="r.id">
                <td :data-label="$t('Labour List')">
                  <Link :href="route('labour.show', r.labour_list_id)" class="lg-chip lg-chip-blue lg-mono" style="text-transform: none">{{ r.labour_list_reference }}</Link>
                </td>
                <td class="num" :data-label="$t('Requested')">{{ money(r.requested_amount) }}</td>
                <td class="num" :data-label="$t('Approved')">
                  <span v-if="r.approved_amount !== null" class="lg-main" :class="{ 'lg-tip': r.revision_reason }" :data-tip="r.revision_reason || null">{{ money(r.approved_amount) }}</span>
                  <span v-else class="lg-subtext">—</span>
                </td>
                <td :data-label="$t('Voucher')">
                  <Link v-if="r.voucher" :href="route('payment-vouchers.show', r.voucher.id)" class="lg-chip" :class="r.voucher.paid ? 'lg-chip-green' : 'lg-chip-blue'">{{ r.voucher.pv_number }}</Link>
                  <span v-else class="lg-subtext">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="lg-empty" style="padding: 24px 16px !important">{{ $t('No requests have been made against this agreement yet.') }}</div>
      </section>

      <section v-if="(agreement.attachments || []).length" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Attachments') }}</h2></div>
        </header>
        <div class="flex flex-wrap gap-2">
          <a v-for="a in agreement.attachments" :key="a.id" :href="a.download_url" class="lg-btn lg-btn-soft">
            <icons name="download" class="w-3.5 h-3.5" /> {{ a.name }}
          </a>
        </div>
      </section>

      <section v-if="agreement.notes" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="edit" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Notes') }}</h2></div>
        </header>
        <div class="text-sm wfa-body">{{ agreement.notes }}</div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, PageHeader, StatTiles },

  props: {
    agreement: Object,
    can: Object,
  },

  computed: {
    statTiles() {
      const a = this.agreement;
      return [
        { icon: 'doc-text', tone: 'navy', label: a.opening_balance > 0 ? this.$t('Agreed') + ' (+ ' + this.$t('Opening Balance') + ': ' + this.money(a.opening_balance) + ')' : this.$t('Agreed'), value: this.money(a.agreed_amount) },
        { icon: 'send', tone: 'indigo', label: this.$t('Requested'), value: this.money(a.total_requested) },
        { icon: 'tick', tone: 'sky', label: this.$t('Approved'), value: this.money(a.total_approved) },
        { icon: 'cash', tone: 'emerald', label: this.$t('Paid'), value: this.money(a.total_paid), valueClass: 'is-good' },
        { icon: 'clock', tone: 'amber', label: this.$t('Outstanding'), value: this.money(a.outstanding_balance) },
      ];
    },
  },

  methods: {
    payChip(s) {
      return { unpaid: 'lg-chip-red', partially_paid: 'lg-chip-amber', fully_paid: 'lg-chip-green' }[this.slug(s)] || 'lg-chip-slate';
    },
    statusChip(s) {
      return { active: 'lg-chip-blue', cancelled: 'lg-chip-slate' }[this.slug(s)] || 'lg-chip-slate';
    },
    money(v) {
      return 'TZS ' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    slug(s) {
      return (s || '').toLowerCase().replace(/[^a-z0-9]+/g, '_');
    },
    async cancelAgreement() {
      if (!(await this.$confirm(this.$t('Cancel this agreement? It will no longer be selectable for new requests.')))) return;
      this.$inertia.patch(this.route('workforce-agreements.cancel', this.agreement.id));
    },
  },
};
</script>

<style scoped>
.wfa-body { color: var(--lg-ink); white-space: pre-line; }
</style>

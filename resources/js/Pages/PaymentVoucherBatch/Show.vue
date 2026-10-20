<template>
  <admin-layout :title="batch.batch_number">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="folder" :title="batch.batch_number" :subtitle="[batch.type, batch.warehouse_name, batch.voucher_count + ' ' + $t('vouchers')].filter(Boolean).join(' · ')">
        <Link :href="route('payment-vouchers.batches.index')" class="lg-btn lg-btn-soft">
          <icons name="list" class="w-4 h-4" />{{ $t('Batches') }}
        </Link>
        <a v-if="canPrint" :href="route('payment-vouchers.batches.print', batch.id)" target="_blank" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print All') }}
        </a>
        <a v-if="canPrint" :href="route('payment-vouchers.batches.pdf', batch.id)" class="lg-btn lg-btn-dark">
          <icons name="download" class="w-4 h-4" />{{ $t('Download All') }}
        </a>
      </page-header>

      <stat-tiles :items="statTiles" />

      <section class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="folder" class="w-5 h-5" /></span>
          <div class="flex-1 min-w-0">
            <div class="lg-card-title">{{ $t('Batch Overview') }}</div>
          </div>
          <span class="pvb-status pvb-status--lg" :class="'pv-st-' + statusKey(batch.status)"><icons :name="statusIcon(batch.status)" class="pvb-status__ico" />{{ batch.status }}</span>
        </div>
        <dl class="pvb-dl">
          <div><dt>{{ $t('Batch Number') }}</dt><dd>{{ batch.batch_number }}</dd></div>
          <div><dt>{{ $t('Voucher Type') }}</dt><dd>{{ batch.type }}</dd></div>
          <div><dt>{{ $t('Payee Type') }}</dt><dd>{{ batch.payee_type }}</dd></div>
          <div><dt>{{ $t('Project') }}</dt><dd>{{ batch.warehouse_name }}</dd></div>
          <div><dt>{{ $t('Date') }}</dt><dd>{{ $date(batch.voucher_date) }}</dd></div>
          <div><dt>{{ $t('Created By') }}</dt><dd>{{ batch.created_by_name || '-' }}</dd></div>
          <div><dt>{{ $t('Created') }}</dt><dd>{{ $datetime(batch.created_at) }}</dd></div>
          <div><dt>{{ $t('Voucher Count') }}</dt><dd>{{ batch.voucher_count }}</dd></div>
          <div><dt>{{ $t('Total Amount') }}</dt><dd>{{ $money(batch.total_amount) }}</dd></div>
          <div v-if="batch.reference"><dt>{{ $t('Reference') }}</dt><dd>{{ batch.reference }}</dd></div>
          <div v-if="batch.labour_list_id">
            <dt>{{ $t('Source') }}</dt>
            <dd><Link class="pvb-link" :href="route('labour.show', batch.labour_list_id)">{{ $t('Labour List') }} {{ batch.labour_list_reference }}</Link></dd>
          </div>
        </dl>
        <div class="pvb-sep">
          <div class="pvb-sep-label">{{ $t('Common Particulars') }}</div>
          <div class="pvb-sep-text">{{ $t('Being Paid for :') }} {{ batch.common_particulars }}</div>
          <p v-if="batch.common_notes" class="lg-subtext mt-2">
            <span class="font-semibold">{{ $t('Common Notes') }}:</span> {{ batch.common_notes }}
          </p>
        </div>
      </section>

      <section class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-pink"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="flex-1 min-w-0">
            <div class="lg-card-title">{{ $t('Vouchers in this Batch') }}</div>
          </div>
          <div class="flex items-center gap-2 flex-wrap">
            <button v-if="$can('approve-payment-vouchers')" type="button" class="lg-btn lg-btn-dark" :disabled="!toApprove.length" :title="toApprove.length ? '' : idleReason" @click="bulkApprove">
              <icons name="tick" class="w-4 h-4" />{{ $t('Approve All Eligible') }}<template v-if="toApprove.length"> ({{ toApprove.length }})</template>
            </button>
            <button v-if="$can('approve-payment-vouchers') && $can('authorise-payment-vouchers')" type="button" class="lg-btn lg-btn-primary" :disabled="!toAuthorise.length" :title="toAuthorise.length ? '' : idleReason" @click="bulkApproveAndAuthorise">
              <icons name="lock-closed" class="w-4 h-4" />{{ $t('Approve & Authorise All Eligible') }}<template v-if="toAuthorise.length"> ({{ toAuthorise.length }})</template>
            </button>
          </div>
        </div>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('PV Number') }}</th>
                <th>{{ $t('Payee') }}</th>
                <th class="num">{{ $t('Amount') }}</th>
                <th>{{ $t('Status') }}</th>
                <th class="num">{{ $t('Printed') }}</th>
                <th class="lg-sticky-end"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="v in batch.vouchers" :key="v.id">
                <td :data-label="$t('PV Number')" class="lg-main">{{ v.pv_number }}</td>
                <td :data-label="$t('Payee')">{{ v.payee_name }}</td>
                <td :data-label="$t('Amount')" class="num font-semibold">{{ $money(v.total_amount) }}</td>
                <td :data-label="$t('Status')">
                  <span class="pvb-status" :class="'pv-st-' + statusKey(v.display_status || v.status)"><icons :name="statusIcon(v.display_status || v.status)" class="pvb-status__ico" />{{ v.display_status || v.status }}</span>
                </td>
                <td :data-label="$t('Printed')" class="num">{{ v.printed_count }}</td>
                <td :data-label="$t('View')" class="lg-sticky-end">
                  <div class="lg-actions">
                    <Link :href="route('payment-vouchers.show', v.id)" class="lg-icon-btn lg-tip" :data-tip="$t('View')">
                      <icons name="eye-flat" class="w-4 h-4" />
                    </Link>
                  </div>
                </td>
              </tr>
              <tr v-if="!batch.vouchers || !batch.vouchers.length" class="lg-empty-row">
                <td class="lg-empty" colspan="6">{{ $t('There is no data to display.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="clock" class="w-5 h-5" /></span>
          <div class="flex-1 min-w-0">
            <div class="lg-card-title">{{ $t('Activity History') }}</div>
          </div>
        </div>
        <div v-if="!activity.length" class="lg-empty">{{ $t('No history recorded yet.') }}</div>
        <div v-else class="pvb-activity-list">
          <div v-for="(entry, idx) in activity" :key="idx" class="pvb-activity-row">
            <div class="flex items-center justify-between gap-3 flex-wrap">
              <span class="font-semibold text-sm pvb-strong">{{ entry.description }}</span>
              <span class="lg-subtext shrink-0">{{ entry.created_at ? $datetime(entry.created_at) : '' }}</span>
            </div>
            <div class="lg-subtext">{{ $t('By') }} {{ entry.causer }}</div>
          </div>
        </div>
      </section>

      <section class="lg-card">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="clip" class="w-5 h-5" /></span>
          <div class="flex-1 min-w-0">
            <div class="lg-card-title">{{ $t('Batch Attachments') }}</div>
          </div>
        </div>
        <ul class="pvb-files text-sm">
          <li v-for="file in batch.attachments" :key="file.id" class="py-2 flex items-center justify-between gap-3">
            <span class="truncate pvb-strong">{{ file.name }}</span>
            <a :href="route('payment-vouchers.batches.attachments.download', [batch.id, file.id])" class="pvb-link shrink-0 inline-flex items-center gap-1">
              <icons name="download" class="w-4 h-4" /> {{ $t('Download') }}
            </a>
          </li>
          <li v-if="!batch.attachments || !batch.attachments.length" class="py-2 lg-subtext">{{ $t('No attachments.') }}</li>
        </ul>
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
    separation: { type: Boolean, default: false },
    batch: Object,
    companyName: String,
    activity: { type: Array, default: () => [] },
  },

  computed: {
    // only vouchers that still wait for a step can be approved / authorised; once they are approved, authorised or paid the buttons stay off
    me() { return this.$page.props.auth && this.$page.props.auth.user ? Number(this.$page.props.auth.user.id) : 0; },
    // separation of duties: a person does not approve what they prepared (the batch creator prepared all of them), nor authorise what they approved or prepared
    toApprove() { return this.batch.vouchers.filter(v => v.status === 'Submitted' && !(this.separation && this.mine(v))); },
    toAuthorise() { return this.batch.vouchers.filter(v => (v.status === 'Submitted' && !(this.separation && this.mine(v))) || (v.status === 'Approved' && !(this.separation && (this.mine(v) || Number(v.approved_by_user_id) === this.me)))); },
    idleReason() {
      const vs = this.batch.vouchers;
      if (vs.length && vs.every(v => v.display_status === 'Paid')) return this.$t('All the vouchers of this batch are paid.');
      return this.$t('No voucher of this batch is waiting for this step.');
    },
    statTiles() {
      const b = this.batch;
      return [
        { icon: 'doc-text', tone: 'sky', label: this.$t('Voucher Count'), value: b.voucher_count },
        { icon: 'chart-bar', tone: 'pink', label: this.$t('Total Amount'), value: this.$money(b.total_amount) },
        { icon: 'calendar', tone: 'indigo', label: this.$t('Date'), value: this.$date(b.voucher_date) },
        { icon: this.statusIcon(b.status), tone: { Draft: 'slate', Submitted: 'orange', Approved: 'violet', Authorised: 'sky', Paid: 'forest', Rejected: 'maroon', Cancelled: 'slate', Mixed: 'teal' }[b.status] || 'navy', label: this.$t('Batch Status'), value: b.status },
      ];
    },
    canPrint() {
      return this.$can('print-payment-vouchers');
    },
  },

  methods: {
    mine(v) { return (v.prepared_by_ids || []).map(Number).includes(this.me); },
    async bulkApprove() {
      const ids = this.toApprove.map(v => v.id);
      if (!ids.length) return;
      if (!await this.$confirm(this.$t('Approve') + ' ' + ids.length + ' ' + this.$t('voucher(s) in this batch (eligible ones only)?'))) {
        return;
      }
      this.$inertia.post(route('payment-vouchers.bulk-approve'), { ids });
    },
    async bulkApproveAndAuthorise() {
      const ids = this.toAuthorise.map(v => v.id);
      if (!ids.length) return;
      if (!await this.$confirm(this.$t('Approve and authorise') + ' ' + ids.length + ' ' + this.$t('voucher(s) in this batch (eligible ones only)?'))) {
        return;
      }
      this.$inertia.post(route('payment-vouchers.bulk-approve-authorise'), { ids });
    },
    // the same colour and icon per status as on the vouchers
    statusKey(status) { return String(status || 'Draft').toLowerCase(); },
    statusIcon(status) { return { Draft: 'doc-text', Submitted: 'clock', Approved: 'tick', Authorised: 'lock-closed', Paid: 'cash', Rejected: 'x-circle', Cancelled: 'cross', Mixed: 'collection' }[status] || 'doc-text'; },
    statusChip(status) {
      return (
        {
          Draft: 'lg-chip-slate',
          Submitted: 'lg-chip-amber',
          Approved: 'lg-chip-blue',
          Authorised: 'lg-chip-green',
          Rejected: 'lg-chip-red',
          Cancelled: 'lg-chip-red',
          Mixed: 'lg-chip-amber',
        }[status] || 'lg-chip-slate'
      );
    },
    statusBadgeClass(status) {
      const map = {
        Draft: 'pv-badge-slate',
        Submitted: 'pv-badge-amber',
        Approved: 'pv-badge-blue',
        Authorised: 'pv-badge-emerald',
        Rejected: 'pv-badge-rose',
        Cancelled: 'pv-badge-rose',
        Mixed: 'pv-badge-amber',
      };
      return map[status] || 'pv-badge-slate';
    },
  },
};
</script>

<style scoped>
.pvb-dl { display: grid; gap: 14px 24px; grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr)); }
.pvb-dl > div { min-width: 0; }
.pvb-dl dt, .pvb-sep-label { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.pvb-dl dd { margin-top: 2px; font-size: 0.88rem; font-weight: 600; color: var(--lg-ink); overflow-wrap: anywhere; }
.pvb-sep { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--lg-line); }
.pvb-sep-text { margin-top: 2px; font-size: 0.88rem; font-weight: 600; color: var(--lg-ink); overflow-wrap: anywhere; }
.pvb-link { color: #4338ca; font-weight: 600; }
.pvb-link:hover { text-decoration: underline; }
html.dark .pvb-link { color: #a5b4fc; }
.pvb-strong { color: var(--lg-ink); }
.pvb-files > li + li { border-top: 1px solid var(--lg-line); }
.pvb-activity-list { display: flex; flex-direction: column; gap: 0.65rem; max-height: 320px; overflow-y: auto; }
.pvb-activity-row { padding: 0.6rem 0.75rem; border: 1px solid var(--lg-line); border-radius: 0.8rem; background: var(--lg-soft); }
</style>

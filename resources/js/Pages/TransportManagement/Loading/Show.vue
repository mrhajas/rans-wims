<template>
  <admin-layout :title="worker.name + ' - ' + $t('Loading & Unloading')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="users" :title="worker.name" :subtitle="$t('Loading & Unloading ledger') + (worker.phone ? ' · ' + worker.phone : '')">
        <Link :href="route('transport-loading.index', { tab: 'balances' })" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Loading & Unloading') }}</Link>
        <a v-if="can.print" :href="statementUrl('print')" target="_blank" rel="noopener" class="lg-btn lg-btn-primary"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
        <a v-if="can.print" :href="statementUrl('pdf')" class="lg-btn lg-btn-dark"><icons name="download" class="w-4 h-4" />{{ $t('Download PDF') }}</a>
        <button v-if="can.opening" type="button" class="lg-btn lg-btn-soft" @click="openingOpen = true"><icons name="clock" class="w-4 h-4" />{{ opening ? $t('Change previous balance') : $t('Add previous balance') }}</button>
        <button v-if="can.pay || can.voucher" type="button" class="lg-btn lg-btn-green" @click="pay = { contact_id: worker.id, name: worker.name, balance: totals.balance, pending: totals.pending, available: totals.available }"><icons name="cash" class="w-4 h-4" />{{ $t('Pay personnel') }}</button>
      </page-header>

      <stat-tiles :items="tiles" />

      <p v-if="opening" class="lg-note lg-note-amber tl-opening-note">
        <icons name="clock" class="w-4 h-4" />
        <span><b>{{ $t('Previous balance') }}: {{ $money(opening.amount) }}</b> {{ $t('as at') }} {{ $date(opening.as_of_date) }}<template v-if="opening.notes && opening.notes !== 'SAMPLE'"> · {{ opening.notes }}</template><template v-if="opening.by"> · {{ $t('recorded by') }} {{ opening.by }}</template>. {{ $t('It is included in the balance below: balance = previous balance + earned - paid.') }}</span>
      </p>

      <section class="lg-card">
        <div class="lg-fields tls-fields">
          <div><date-picker :label="$t('From')" v-model="from" :max="to || undefined" clearable /></div>
          <div><date-picker :label="$t('To')" v-model="to" :min="from || undefined" clearable /></div>
        </div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="calendar" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Attendance') }}</h2><p class="lg-card-sub">{{ attendance.length }} {{ $t('day(s) shown') }}</p></div>
        </header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead><tr><th class="w-12">{{ $t('No') }}</th><th>{{ $t('Date') }}</th><th>{{ $t('Status') }}</th><th>{{ $t('Project / site') }}</th><th class="num">{{ $t('Rate') }}</th><th class="num">{{ $t('Earned') }}</th><th v-if="can.delete" class="lg-sticky-end"></th></tr></thead>
            <tbody>
              <tr v-for="(a, i) in attendance" :key="a.id">
                <td :data-label="$t('No')" class="tls-no">{{ i + 1 }}</td>
                <td :data-label="$t('Date')">{{ $date(a.date) }}</td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="a.status === 'Present' ? 'lg-chip-green' : 'lg-chip-red'" style="text-transform: none">{{ $t(a.status) }}</span></td>
                <td :data-label="$t('Project / site')">{{ a.project || '-' }}</td>
                <td :data-label="$t('Rate')" class="num">
                  <span v-if="a.rate !== a.standard" class="lg-tip tl-custom" :data-tip="$t('Custom rate: :rate was used for this day instead of the standard :std.', { rate: $money(a.rate), std: $money(a.standard) })">{{ $money(a.rate) }}<icons name="exclamation" class="w-3 h-3 inline-flex tl-custom-ico" /></span>
                  <template v-else>{{ $money(a.rate) }}</template>
                </td>
                <td :data-label="$t('Earned')" class="num"><b>{{ $money(a.amount) }}</b></td>
                <td v-if="can.delete" class="lg-sticky-end" data-label=""><div class="lg-actions"><button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove this day')" :aria-label="$t('Remove this day')" @click="removeDay(a)"><icons name="trash" /></button></div></td>
              </tr>
              <tr v-if="!attendance.length" class="lg-empty-row"><td class="lg-empty" :colspan="can.delete ? 7 : 6">{{ $t('There is no data to display.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="cash" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Payments') }}</h2><p class="lg-card-sub">{{ payments.length }} {{ $t('payment(s) shown') }}</p></div>
        </header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead><tr><th class="w-12">{{ $t('No') }}</th><th>{{ $t('Date') }}</th><th class="num">{{ $t('Amount') }}</th><th>{{ $t('Status') }}</th><th>{{ $t('Method') }}</th><th>{{ $t('Reference') }}</th><th>{{ $t('Paid by') }}</th><th v-if="can.delete" class="lg-sticky-end"></th></tr></thead>
            <tbody>
              <tr v-for="(p, i) in payments" :key="p.id">
                <td :data-label="$t('No')" class="tls-no">{{ i + 1 }}</td>
                <td :data-label="$t('Date')">{{ $date(p.date) }}</td>
                <td :data-label="$t('Amount')" class="num"><b>{{ $money(p.amount) }}</b></td>
                <td :data-label="$t('Status')">
                  <span class="lg-chip" :class="p.status === 'Paid' ? 'lg-chip-green' : 'lg-chip-amber'" style="text-transform: none">{{ p.status === 'Paid' ? $t('Paid') : $t('Waiting on voucher') }}</span>
                  <div v-if="p.voucher" class="lg-subtext"><a :href="route('payment-vouchers.show', p.voucher_id)" target="_blank" rel="noopener" class="tl-pv">{{ p.voucher }}</a> ({{ $t(p.voucher_status) }})</div>
                </td>
                <td :data-label="$t('Method')">{{ p.method || '-' }}</td>
                <td :data-label="$t('Reference')">
                  <!-- paid through a Payment Voucher: the reference IS that voucher, opened in a new tab -->
                  <a v-if="p.voucher_id" :href="route('payment-vouchers.show', p.voucher_id)" target="_blank" rel="noopener" class="tl-pv lg-tip" :data-tip="$t('Open the Payment Voucher in a new tab')">{{ p.voucher }}</a>
                  <template v-else>{{ p.reference || '-' }}</template>
                  <div v-if="p.voucher_id && p.reference && p.reference !== p.voucher" class="lg-subtext">{{ p.reference }}</div>
                  <div v-if="p.notes && p.notes !== 'SAMPLE'" class="lg-subtext">{{ p.notes }}</div>
                </td>
                <td :data-label="$t('Paid by')">{{ p.by || '-' }}</td>
                 <td v-if="can.delete" class="lg-sticky-end" data-label=""><div class="lg-actions"><button v-if="!p.voucher_id" type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove payment')" :aria-label="$t('Remove payment')" @click="removePayment(p)"><icons name="trash" /></button></div></td>
              </tr>
              <tr v-if="!payments.length" class="lg-empty-row"><td class="lg-empty" :colspan="can.delete ? 8 : 7">{{ $t('There is no data to display.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <pay-modal v-if="pay" :worker="pay" :can="can" @close="pay = null" />
      <opening-modal v-if="openingOpen" :worker="{ contact_id: worker.id, name: worker.name, opening: opening ? opening.amount : 0, opening_date: opening ? opening.as_of_date : null, opening_notes: opening ? opening.notes : '' }" @close="openingOpen = false" />
    </div>
  </admin-layout>
</template>

<script>
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import PayModal from './PayModal.vue';
import OpeningModal from './OpeningModal.vue';

export default {
  components: { Link, AdminLayout, PageHeader, StatTiles, DatePicker, PayModal, OpeningModal },
  props: { worker: Object, filters: Object, attendance: Array, payments: Array, totals: Object, opening: { type: Object, default: null }, rate: Number, can: Object },
  data() {
    return { from: this.filters.date_from || '', to: this.filters.date_to || '', pay: null, openingOpen: false };
  },
  computed: {
    tiles() {
      return [
        { icon: 'calendar', tone: 'indigo', label: this.$t('Days present (all time)'), value: this.totals.days },
        ...(this.totals.opening ? [{ icon: 'clock', tone: 'amber', label: this.$t('Previous balance'), value: this.$money(this.totals.opening) }] : []),
        { icon: 'cash', tone: 'emerald', label: this.$t('Earned (all time)'), value: this.$money(this.totals.earned) },
        { icon: 'tick', tone: 'sky', label: this.$t('Paid (all time)'), value: this.$money(this.totals.paid) },
        { icon: 'exclamation', tone: 'amber', label: this.$t('Balance owed'), value: this.$money(this.totals.balance) },
        ...(this.totals.pending > 0 ? [{ icon: 'doc-text', tone: 'violet', label: this.$t('On a voucher, not paid yet'), value: this.$money(this.totals.pending) }] : []),
      ];
    },
  },
  watch: {
    from() { this.reload(); },
    to() { this.reload(); },
  },
  methods: {
    // The statement follows the period chosen on this page.
    statementUrl(kind) {
      const q = {};
      if (this.from) q.date_from = this.from;
      if (this.to) q.date_to = this.to;
      return this.route('transport-loading.workers.' + kind, { contact: this.worker.id, ...q });
    },
    reload() {
      const q = {};
      if (this.from) q.date_from = this.from;
      if (this.to) q.date_to = this.to;
      router.get(this.route('transport-loading.show', this.worker.id), q, { preserveState: true, preserveScroll: true, replace: true });
    },
    async removeDay(a) {
      if (!(await this.$confirm(this.$t('Remove the attendance of') + ' ' + this.$date(a.date) + '?', { variant: 'danger' }))) return;
      router.delete(this.route('transport-loading.attendance.destroy', a.id), { preserveScroll: true });
    },
    async removePayment(p) {
      if (!(await this.$confirm(this.$t('Remove this payment? The balance owed goes back up.'), { variant: 'danger' }))) return;
      router.delete(this.route('transport-loading.payments.destroy', p.id), { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.tl-custom { color: #92400e; font-weight: 800; padding: 2px 8px; border-radius: 8px; background: rgba(245, 158, 11, 0.16); box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.25); cursor: help; }
.tl-custom-ico { color: #d97706; margin-left: 4px; }
.tl-pv { color: #1d4ed8; font-weight: 700; text-decoration: underline dotted; text-underline-offset: 2px; }
.tl-opening-note { display: flex; gap: 8px; align-items: flex-start; margin: 0; }
.tls-no { width: 1%; font-weight: 800; color: var(--lg-faint, #94a3b8); }
.tls-fields { grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr)); }
</style>

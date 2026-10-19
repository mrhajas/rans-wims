<template>
  <admin-layout :title="record.trip_number">
    <div class="lg-page px-4 md:px-0">
      <header class="lg-hero print:hidden">
        <span class="lg-hero-icon"><icons name="truck" class="w-6 h-6" /></span>
        <div class="lg-hero-body">
          <nav class="tm-crumbs">
            <Link :href="route('transport-records.index')">{{ $t('Transport Routes Records') }}</Link>
            <span>/</span>
            <span>{{ record.trip_number }}</span>
          </nav>
          <h1 class="lg-title tm-title">
            {{ record.trip_number }}
            <span class="lg-chip" :class="'tm-pay-' + statusSlug(record.payment_status)" style="text-transform: none">
              {{ $t(record.payment_status) }}
              <span v-if="record.paid_without_voucher" class="lg-tip tip-left tm-inline-tip" :data-tip="$t('Paid without a Payment Voucher')">
                <icons name="exclamation" class="w-3 h-3 inline-flex" />
              </span>
            </span>
            <span class="lg-chip lg-chip-indigo" style="text-transform: none">{{ typeLabel(record.destination_type) }}</span>
            <span v-if="record.is_verified" class="lg-chip lg-chip-green" style="text-transform: none"><icons name="tick" class="w-3 h-3 inline-flex" /> {{ $t('Verified') }}</span>
            <span v-else class="lg-chip lg-chip-slate" style="text-transform: none">{{ $t('Unverified') }}</span>
          </h1>
          <div class="tm-meta">
            <span class="lg-chip lg-chip-blue" style="text-transform: none">
              <icons name="truck" class="w-3.5 h-3.5 inline-flex" />
              {{ record.asset?.registration_number }}<span v-if="record.asset?.name"> - {{ record.asset.name }}</span>
            </span>
            <span v-if="record.driver" class="lg-chip lg-chip-violet" style="text-transform: none">
              <icons name="user" class="w-3.5 h-3.5 inline-flex" />
              {{ record.driver.name }}
            </span>
            <span class="lg-chip lg-chip-amber" style="text-transform: none">
              <icons name="calendar" class="w-3.5 h-3.5 inline-flex" />
              {{ $date(record.trip_date) }}<span v-if="record.trip_time">, {{ record.trip_time }}</span>
            </span>
            <a v-if="record.transfer" :href="record.transfer.url" target="_blank" rel="noopener" class="lg-chip lg-chip-green lg-tip tm-transfer-link" style="text-transform: none" :data-tip="$t('Open the Transfer this trip was generated from (new tab)')">
              <icons name="truck" class="w-3.5 h-3.5 inline-flex" />
              {{ $t('Transfer') }} {{ record.transfer.reference }}<span v-if="record.transfer.deleted"> ({{ $t('deleted') }})</span>
              <icons name="link" class="w-3 h-3 inline-flex" />
            </a>
          </div>
        </div>
        <div class="lg-hero-actions tm-actions">
          <a v-if="$can('print-transport-records')" :href="route('transport-records.print', record.id)" target="_blank" class="lg-btn tm-act lg-btn-primary print:hidden">
            <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
          </a>
          <a v-if="$can('print-transport-records')" :href="route('transport-records.pdf', record.id)" class="lg-btn tm-act lg-btn-dark print:hidden">
            <icons name="download" class="w-4 h-4" />{{ $t('Download') }}
          </a>
          <button v-if="record.can_verify" type="button" class="lg-btn tm-btn-violet" @click="verifyRecord">
            <icons name="tick" class="w-4 h-4" /> {{ $t('Verify') }}
          </button>
          <button v-if="record.can_create_payment_voucher" type="button" class="lg-btn lg-btn-primary" @click="createVoucherForRecord(record)">
            <icons name="doc-text" class="w-4 h-4" /> {{ $t('Create Payment Voucher') }}
          </button>
          <button v-if="record.can_mark_paid" type="button" class="lg-btn lg-btn-green" @click="handleMarkPaidClick">
            <icons name="cash" class="w-4 h-4" /> {{ $t('Mark Paid') }}
          </button>
          <Link v-if="record.can_edit" :href="route('transport-records.edit', record.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Edit')" :aria-label="$t('Edit')">
            <icons name="edit" />
          </Link>
          <button v-if="record.can_cancel" type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Cancel Trip')" :aria-label="$t('Cancel Trip')" @click="cancelTrip">
            <icons name="x-circle" />
          </button>
        </div>
      </header>

      <stat-tiles
        :items="[
          { icon: 'cash', tone: record.amount_overridden ? 'amber' : 'navy', label: record.trips_count > 1 ? $t('Total Amount') : $t('Amount'), value: $money(record.amount) },
          ...(record.trips_count > 1 ? [{ icon: 'truck', tone: 'sky', label: $t('Trips'), value: record.trips_count + ' × ' + $money(record.unit_amount) }] : []),
          { icon: 'tick', tone: 'emerald', label: $t('Paid Amount'), value: $money(record.paid_amount) },
          { icon: 'clock', tone: 'rose', label: $t('Outstanding'), value: $money(record.outstanding_amount) },
        ]"
      />

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="map-pin" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Trip Details') }}</h2>
          </div>
        </header>
        <div class="tm-kv">
          <div v-if="record.is_campus">
            <p class="tm-k">{{ $t('Route') }}</p>
            <p class="tm-v">{{ $t('Within site campus') }}: {{ record.destination_label }}</p>
          </div>
          <div v-if="record.departure_label && !record.is_campus">
            <p class="tm-k">{{ $t('Departure') }}<span v-if="record.departure_type"> · {{ typeLabel(record.departure_type) }}</span></p>
            <p class="tm-v">{{ record.departure_label }}</p>
          </div>
          <div v-if="record.departure_location"><p class="tm-k">{{ record.is_campus ? $t('From (area / block / gate)') : $t('Departure Address / Gate') }}</p><p class="tm-v">{{ record.departure_location }}</p></div>
          <div v-if="!record.is_campus">
            <p class="tm-k">{{ $t('Destination') }}<span v-if="record.destination_type"> · {{ typeLabel(record.destination_type) }}</span></p>
            <p class="tm-v">{{ record.destination_label || '—' }}</p>
          </div>
          <div v-if="record.destination_location"><p class="tm-k">{{ record.is_campus ? $t('To (area / block / gate)') : $t('Destination Address / Gate') }}</p><p class="tm-v">{{ record.destination_location }}</p></div>
          <div v-if="record.delivery_note_ref"><p class="tm-k">{{ $t('Delivery Note / Issue Note Ref No') }}</p><p class="tm-v">{{ record.delivery_note_ref }}</p></div>
          <div v-if="record.transfer"><p class="tm-k">{{ $t('Linked Transfer') }}</p><p class="tm-v"><a :href="record.transfer.url" target="_blank" rel="noopener" class="tm-transfer-a">{{ record.transfer.reference }} <icons name="link" class="w-3 h-3 inline-flex" /></a></p></div>
        </div>
        <div v-if="record.purpose" class="tm-block"><p class="tm-k">{{ $t('Purpose / Work Performed') }}</p><p class="tm-text">{{ record.purpose }}</p></div>
        <div v-if="record.notes" class="tm-block"><p class="tm-k">{{ $t('Notes') }}</p><p class="tm-text">{{ record.notes }}</p></div>

        <div v-if="record.is_verified" class="lg-note tm-note-green tm-block">
          <icons name="tick" class="w-4 h-4" />
          <div>
            <p class="tm-note-title">{{ $t('Verified') }}</p>
            <p v-if="record.verified_by" class="tm-note-sub">
              {{ $t('By') }} {{ record.verified_by.name }}<span v-if="record.verified_at"> · {{ $datetime(record.verified_at) }}</span>
            </p>
          </div>
        </div>
        <div v-else class="lg-note tm-note-slate tm-block">
          <icons name="lock-closed" class="w-4 h-4" />
          <div>{{ $t('This trip has not been verified yet, it cannot be marked paid or added to a Payment Voucher until it is.') }}</div>
        </div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Financial') }}</h2>
          </div>
        </header>

        <div v-if="record.amount_overridden" class="lg-note lg-note-amber">
          <icons name="exclamation" class="w-4 h-4" />
          <div>
            <p class="tm-note-title">{{ $t('Reason for change') }}</p>
            <p>{{ record.amount_change_reason }}</p>
            <p v-if="record.amount_changed_by" class="tm-note-sub">
              {{ $t('By') }} {{ record.amount_changed_by.name }}<span v-if="record.amount_changed_at"> · {{ $datetime(record.amount_changed_at) }}</span>
            </p>
          </div>
        </div>

        <div v-if="record.paid_without_voucher" class="lg-note lg-note-red tm-block">
          <icons name="exclamation" class="w-4 h-4" />
          <div>
            <p class="tm-note-title">{{ $t('Paid Without a Payment Voucher') }}</p>
            <p class="tm-note-sub">{{ $t('This trip was marked paid directly, no Payment Voucher was created for it.') }}</p>
          </div>
        </div>

        <div class="tm-kv" :class="{ 'tm-block': record.amount_overridden || record.paid_without_voucher }">
          <div>
            <p class="tm-k">{{ $t('Payment Status') }}</p>
            <p>
              <span class="lg-chip" :class="'tm-pay-' + statusSlug(record.payment_status)" style="text-transform: none">
                {{ $t(record.payment_status) }}
                <span v-if="record.paid_without_voucher" class="lg-tip tip-left tm-inline-tip" :data-tip="$t('Paid without a Payment Voucher')">
                  <icons name="exclamation" class="w-3 h-3 inline-flex" />
                </span>
              </span>
            </p>
          </div>
          <div v-if="record.payment_date"><p class="tm-k">{{ $t('Payment Date') }}</p><p class="tm-v">{{ $date(record.payment_date) }}</p></div>
          <div v-if="record.payment_reference"><p class="tm-k">{{ $t('Payment Reference') }}</p><p class="tm-v">{{ record.payment_reference }}</p></div>
          <div v-if="record.payment_method"><p class="tm-k">{{ $t('Payment Method') }}</p><p class="tm-v">{{ record.payment_method }}</p></div>
        </div>
        <div v-if="record.payment_notes" class="tm-block"><p class="tm-k">{{ $t('Payment Notes') }}</p><p class="tm-text">{{ record.payment_notes }}</p></div>

        <div v-if="record.payment_voucher_id" class="tm-block">
          <p class="tm-k">{{ $t('Payment Voucher') }}</p>
          <Link :href="route('payment-vouchers.show', record.payment_voucher_id)" class="lg-chip lg-chip-blue tm-link" style="text-transform: none">
            <icons name="doc-text" class="w-3.5 h-3.5 inline-flex" />
            {{ record.payment_voucher?.pv_number }}
          </Link>
        </div>
      </section>

      <section v-if="record.attachments && record.attachments.length" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
          </div>
        </header>
        <ul class="tm-att-list">
          <li v-for="a in record.attachments" :key="a.id" class="tm-att-item">
            <span class="tm-att-name">
              <icons :name="isImage(a) ? 'image' : 'doc'" class="w-4 h-4 flex-none" />
              <span class="truncate">{{ a.title || a.filename }}</span>
            </span>
            <span class="lg-actions">
              <a :href="previewUrl(a)" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('View')" :aria-label="$t('View')"><icons name="eye-flat" /></a>
              <a :href="downloadUrl(a)" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" /></a>
              <button v-if="record.can_edit" type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="deleteAttachment(a)">
                <icons name="trash" />
              </button>
            </span>
          </li>
        </ul>
      </section>

      <section v-if="record.activity && record.activity.length" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Activity History') }}</h2>
          </div>
          <button v-if="record.activity.length > 3" type="button" class="lg-btn lg-btn-soft" @click="showAllActivity = !showAllActivity">
            {{ showAllActivity ? $t('Collapse') : $t('Show All') }} ({{ record.activity.length }})
            <icons name="chevron-down" class="w-3.5 h-3.5 inline-flex tr-activity-toggle-icon" :class="{ 'tr-activity-toggle-icon-up': showAllActivity }" />
          </button>
        </header>
        <div class="tm-timeline">
          <div v-for="(entry, idx) in visibleActivity" :key="idx" class="tm-tl-row">
            <span class="tm-tl-dot"></span>
            <div class="min-w-0 flex-1">
              <div class="tm-tl-top">
                <span class="tm-tl-desc">{{ entry.description }}</span>
                <span class="tm-tl-time">{{ entry.created_at ? $datetime(entry.created_at) : '' }}</span>
              </div>
              <div class="lg-subtext">{{ $t('By') }} {{ entry.causer }}</div>
            </div>
          </div>
        </div>
      </section>

      <div v-if="paymentChoiceModal.open" class="tm-modal-overlay" @click.self="closePaymentChoice">
        <div class="tm-modal-card" style="max-width: 30rem;">
          <div class="tm-modal-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="exclamation" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h3 class="lg-card-title">{{ $t('No Payment Voucher Yet') }}</h3>
              <p class="lg-card-sub">
                {{ $t('This trip doesn’t have a Payment Voucher. We recommend creating one so this payment goes through the normal approval process, or you can proceed and mark it paid directly.') }}
              </p>
            </div>
          </div>

          <div class="tm-modal-stack">
            <button type="button" class="lg-btn lg-btn-primary" @click="chooseCreateVoucher">
              <icons name="doc-text" class="w-4 h-4 inline-flex" /> {{ $t('Create Payment Voucher') }}
            </button>
            <button type="button" class="lg-btn tr-proceed-btn" @click="chooseMarkPaidDirect">
              <icons name="exclamation" class="w-4 h-4 inline-flex" /> {{ $t('Proceed with Mark as Paid') }}
            </button>
            <button type="button" class="lg-btn lg-btn-soft" @click="closePaymentChoice">
              {{ $t('Cancel') }}
            </button>
          </div>
        </div>
      </div>

      <div v-if="markPaidModal.open" class="tm-modal-overlay" @click.self="closeMarkPaid">
        <div class="tm-modal-card">
          <div class="tm-modal-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h3 class="lg-card-title">{{ $t('Mark Paid') }} - {{ record.trip_number }}</h3>
              <p class="lg-card-sub">{{ $t('Record a payment for this trip.') }}</p>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <tec-label :value="$t('Paid Amount')" />
              <text-input type="number" step="0.01" class="mt-1 block w-full" v-model="markPaidForm.paid_amount" :error="$page.props.errors.paid_amount" />
            </div>
            <div>
              <date-picker :label="$t('Payment Date')" v-model="markPaidForm.payment_date" :error="$page.props.errors.payment_date" />
            </div>
            <div>
              <tec-label :value="$t('Payment Method (optional)')" />
              <text-input class="mt-1 block w-full" v-model="markPaidForm.payment_method" :error="$page.props.errors.payment_method" />
            </div>
            <div>
              <tec-label :value="$t('Payment Reference (optional)')" />
              <text-input class="mt-1 block w-full" v-model="markPaidForm.payment_reference" :error="$page.props.errors.payment_reference" />
            </div>
            <div class="sm:col-span-2">
              <tec-label :value="$t('Notes (optional)')" />
              <textarea class="mt-1 block w-full rounded-md border-gray-300" rows="2" v-model="markPaidForm.payment_notes"></textarea>
              <input-error :message="$page.props.errors.payment_notes" class="mt-1" />
            </div>
          </div>

          <div class="tm-modal-foot">
            <button type="button" class="lg-btn lg-btn-soft" @click="closeMarkPaid">
              <icons name="cross" class="w-4 h-4 inline-flex" />
              {{ $t('Cancel') }}
            </button>
            <loading-button type="button" :loading="markPaidForm.processing" :disabled="markPaidForm.processing" class="tr-modal-submit-btn" @click="submitMarkPaid">
              <icons v-if="!markPaidForm.processing" name="tick" class="w-4 h-4 inline-flex mr-2" />
              {{ $t('Record Payment') }}
            </loading-button>
          </div>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { transportTypeLabel } from '@/Core/transportTypes';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TecLabel from '@/Jetstream/Label.vue';
import TextInput from '@/Shared/TextInput.vue';
import InputError from '@/Jetstream/InputError.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { Link, AdminLayout, TecLabel, TextInput, InputError, DatePicker, LoadingButton, StatTiles },

  props: {
    record: Object,
  },

  data() {
    return {
      paymentChoiceModal: { open: false },
      markPaidModal: { open: false },
      markPaidForm: this.$inertia.form({
        paid_amount: '',
        payment_date: '',
        payment_method: '',
        payment_reference: '',
        payment_notes: '',
      }),
      showAllActivity: false,
    };
  },

  computed: {
    visibleActivity() {
      const activity = this.record.activity || [];
      return this.showAllActivity ? activity : activity.slice(0, 3);
    },
  },

  methods: {
    typeLabel(type) {
      return transportTypeLabel(this.$t, type);
    },
    // Normalizes a status label into the CSS-safe suffix used by the
    // .tv-pay-* badge modifiers below, matching the same convention as
    // TransportManagement/Vehicles' own tv-status-*/tv-pay-* badges, e.g.
    // "Pending Payment" -> "pending-payment".
    statusSlug(status) {
      return (status || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
    },
    // TransportRecordResource passes attachments through raw (whenLoaded),
    // unlike SuperAdjustment which precomputes preview_url/download_url in
    // the controller — so the URLs are built here from the download route.
    previewUrl(a) {
      return this.route('transport-records.attachments.download', [this.record.id, a.id]) + '?disposition=inline';
    },
    downloadUrl(a) {
      return this.route('transport-records.attachments.download', [this.record.id, a.id]);
    },
    isImage(a) {
      return !!(a.filetype && a.filetype.startsWith('image/'));
    },
    async deleteAttachment(a) {
      if (!(await this.$confirm(this.$t('Remove this attachment?'), { variant: 'danger' }))) return;
      this.$inertia.delete(this.route('transport-records.attachments.destroy', [this.record.id, a.id]), { preserveScroll: true });
    },
    async verifyRecord() {
      if (!(await this.$confirm(this.$t('Verify') + ' ' + this.record.trip_number + '? ' + this.$t('This makes it eligible for payment.')))) {
        return;
      }
      this.$inertia.patch(this.route('transport-records.verify', this.record.id), {}, { preserveScroll: true });
    },
    // Mark Paid is only ever offered for a trip with no Payment Voucher yet
    // (can_mark_paid requires payment_voucher_id === null) — so whenever
    // creating a voucher is also still possible, jumping straight into the
    // amount/date form skips a real choice the user should get to make.
    // This intercepts that click with a choice instead of forcing one path.
    handleMarkPaidClick() {
      if (this.record.can_create_payment_voucher) {
        this.paymentChoiceModal.open = true;
      } else {
        this.openMarkPaid();
      }
    },
    closePaymentChoice() {
      this.paymentChoiceModal.open = false;
    },
    chooseCreateVoucher() {
      this.paymentChoiceModal.open = false;
      this.createVoucherForRecord(this.record);
    },
    chooseMarkPaidDirect() {
      this.paymentChoiceModal.open = false;
      this.openMarkPaid();
    },
    async createVoucherForRecord(r) {
      if (!(await this.$confirm(this.$t('Create a Payment Voucher for') + ' ' + r.trip_number + '?'))) return;
      this.$inertia.post(this.route('transport-records.create-payment-voucher'), { transport_record_ids: [r.id] });
    },
    openMarkPaid() {
      this.markPaidForm.reset();
      this.markPaidForm.paid_amount = this.record.outstanding_amount;
      this.markPaidForm.payment_date = new Date().toISOString().slice(0, 10);
      this.markPaidModal.open = true;
    },
    closeMarkPaid() {
      this.markPaidModal.open = false;
    },
    submitMarkPaid() {
      this.markPaidForm.patch(this.route('transport-records.mark-paid', this.record.id), {
        preserveScroll: true,
        onSuccess: () => (this.markPaidModal.open = false),
      });
    },
    async cancelTrip() {
      if (!(await this.$confirm(this.$t('Cancel this trip? It will be excluded from outstanding totals and reports.'), { variant: 'danger' }))) return;
      this.$inertia.patch(this.route('transport-records.cancel', this.record.id), {}, { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.tm-crumbs { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin-bottom: 2px; font-size: 0.72rem; color: var(--lg-faint); }
.tm-crumbs a { color: var(--lg-mute); }
.tm-crumbs a:hover { color: #2563eb; }
.tm-title { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.tm-meta { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.tm-btn-violet { color: #fff; background: linear-gradient(145deg, #a78bfa, #6d28d9); box-shadow: 0 10px 18px -10px rgba(109, 40, 217, 0.9); }
.tm-inline-tip { display: inline-flex; cursor: help; }

/* payment status chips */
.tm-pay-unpaid { color: #92400e; background: rgba(245, 158, 11, 0.17); }
.tm-pay-pending-payment { color: #1d4ed8; background: rgba(59, 130, 246, 0.13); }
.tm-pay-partially-paid { color: #6d28d9; background: rgba(139, 92, 246, 0.14); }
.tm-pay-paid { color: #047857; background: rgba(16, 185, 129, 0.14); }
.tm-pay-cancelled { color: #475569; background: rgba(100, 116, 139, 0.14); }
html.dark .tm-pay-unpaid { color: #fcd34d; } html.dark .tm-pay-pending-payment { color: #93c5fd; }
html.dark .tm-pay-partially-paid { color: #c4b5fd; } html.dark .tm-pay-paid { color: #6ee7b7; } html.dark .tm-pay-cancelled { color: #cbd5e1; }

/* key / value grid */
.tm-kv { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr)); gap: 16px 20px; }
.tm-k { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); margin-bottom: 3px; }
.tm-v { font-size: 0.88rem; font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }
.tm-text { font-size: 0.86rem; color: var(--lg-ink); line-height: 1.55; white-space: pre-line; overflow-wrap: anywhere; }
.tm-block { margin-top: 14px; }
.tm-link { text-decoration: none; }
.tm-note-title { font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; font-size: 0.68rem; margin-bottom: 2px; }
.tm-note-sub { font-size: 0.72rem; opacity: 0.8; margin-top: 2px; }
.tm-note-green { color: #065f46; background: rgba(209, 250, 229, 0.65); border: 1px solid rgba(16, 185, 129, 0.35); }
.tm-note-slate { color: #475569; background: rgba(241, 245, 249, 0.8); border: 1px solid rgba(148, 163, 184, 0.35); }
html.dark .tm-note-green { color: #6ee7b7; background: rgba(16, 185, 129, 0.1); }
html.dark .tm-note-slate { color: #cbd5e1; background: rgba(255, 255, 255, 0.04); }

/* attachments */
.tm-att-list { border: 1px solid var(--lg-line); border-radius: 16px; background: var(--lg-soft); overflow: hidden; }
.tm-att-item { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 14px; }
.tm-att-item + .tm-att-item { border-top: 1px solid var(--lg-line); }
.tm-att-name { display: inline-flex; align-items: center; gap: 10px; min-width: 0; font-size: 0.84rem; font-weight: 600; color: var(--lg-ink); }

/* activity timeline */
.tr-activity-toggle-icon { transition: transform 0.15s ease; }
.tr-activity-toggle-icon-up { transform: rotate(180deg); }
.tm-timeline { position: relative; display: flex; flex-direction: column; gap: 14px; padding-left: 4px; }
.tm-timeline::before { content: ''; position: absolute; left: 8px; top: 8px; bottom: 8px; width: 2px; background: var(--lg-line); }
.tm-tl-row { position: relative; display: flex; gap: 14px; }
.tm-tl-dot { position: relative; flex: none; width: 10px; height: 10px; margin-top: 5px; border-radius: 9999px; background: #3b82f6; box-shadow: 0 0 0 3px var(--lg-card); }
.tm-tl-top { display: flex; align-items: baseline; justify-content: space-between; flex-wrap: wrap; gap: 4px 12px; }
.tm-tl-desc { font-size: 0.85rem; font-weight: 700; color: var(--lg-ink); }
.tm-tl-time { font-size: 0.72rem; color: var(--lg-faint); font-variant-numeric: tabular-nums; }

/* ---- modals (restyled; dark-mode safe) ---- */
.tm-modal-overlay {
  position: fixed; inset: 0; z-index: 99999;
  display: flex; align-items: center; justify-content: center; padding: 1rem;
  background: rgba(15, 23, 42, 0.55);
  backdrop-filter: blur(3px);
  animation: tm-fade 0.15s ease-out;
}
@keyframes tm-fade { from { opacity: 0; } to { opacity: 1; } }
.tm-modal-card {
  width: 100%; max-width: 32rem; max-height: 88vh; overflow-y: auto;
  background: var(--lg-card); color: var(--lg-ink);
  border: 1px solid var(--lg-line); border-radius: 22px; padding: 1.35rem 1.4rem;
  box-shadow: 0 30px 60px -20px rgba(15, 23, 42, 0.5);
  animation: tm-pop 0.18s ease-out;
}
html.dark .tm-modal-card { background: #0f172a; }
@keyframes tm-pop { from { opacity: 0; transform: translateY(8px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
.tm-modal-head { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 1.1rem; }
.tm-modal-head .lg-card-ico { flex: none; }
.tm-modal-stack { display: flex; flex-direction: column; gap: 8px; margin-top: 1.2rem; }
.tm-modal-stack .lg-btn { width: 100%; padding: 10px 15px; }
.tm-modal-foot { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 10px; margin-top: 1.3rem; }
.tm-modal-card :deep(label) { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-mute); }
.tm-modal-card :deep(input:not([type='checkbox']):not([type='radio'])),
.tm-modal-card :deep(select),
.tm-modal-card :deep(textarea) {
  border-radius: 0.85rem; border-color: var(--lg-line); background: var(--lg-card); color: var(--lg-ink);
}
.tm-modal-card :deep(input:focus), .tm-modal-card :deep(textarea:focus) { border-color: #38bdf8; box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2); }
.tm-modal-card :deep(input), .tm-modal-card :deep(textarea) { border: 1px solid var(--lg-line); }
.tr-modal-submit-btn {
  display: inline-flex; align-items: center; justify-content: center; padding: 8px 18px; border-radius: 9999px;
  font-size: 0.78rem; font-weight: 800; color: #fff; background: linear-gradient(145deg, #34d399, #047857) !important;
  box-shadow: 0 10px 18px -10px rgba(4, 120, 87, 0.9); transition: transform 0.15s ease;
}
.tr-modal-submit-btn:hover:not(:disabled) { transform: translateY(-1px); }
.tr-modal-submit-btn:disabled { opacity: 0.6; }
.tr-modal-submit-violet { background: linear-gradient(145deg, #a78bfa, #6d28d9) !important; box-shadow: 0 10px 18px -10px rgba(109, 40, 217, 0.9); }
.tr-proceed-btn { color: #b45309; background: #fffbeb; border: 1px solid #fde68a; }
.tr-proceed-btn:hover { background: #fef3c7; }
html.dark .tr-proceed-btn { color: #fbbf24; background: rgba(245, 158, 11, 0.12); border-color: rgba(245, 158, 11, 0.3); }
@media (prefers-reduced-motion: reduce) { .tm-modal-card, .tm-modal-overlay { animation: none; } }

/* header buttons wrap and never push the page wider than the screen; on phones they share the width */
.tm-actions { flex-wrap: wrap; max-width: 100%; }
.tm-actions > * { min-width: 0; max-width: 100%; }
@media (max-width: 639.98px) {
  .tm-actions { width: 100%; justify-content: flex-start; }
  .tm-actions > .tm-act, .tm-actions > .lg-btn { flex: 1 1 calc(50% - 4px); justify-content: center; }
}
.tm-transfer-link { text-decoration: none; cursor: pointer; }
.tm-transfer-link:hover { filter: brightness(0.96); }
.tm-transfer-a { color: #0f7a55; font-weight: 700; text-decoration: underline; text-underline-offset: 2px; }
</style>

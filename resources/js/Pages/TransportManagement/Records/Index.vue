<template>
  <admin-layout :title="$t('Transport Routes Records')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="truck" :title="$t('Transport Routes Records')" :subtitle="$t('Every individual vehicle trip, logged separately.')">
        <a :href="printUrl" target="_blank" class="lg-icon-btn lg-tip" :data-tip="$t('Print')" :aria-label="$t('Print')">
          <icons name="printer"></icons>
        </a>
        <a :href="pdfUrl" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')">
          <icons name="download"></icons>
        </a>
        <Link v-if="canCreate" :href="route('transport-records.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />{{ $t('New Transport Routes Record') }}
        </Link>
      </page-header>

      <div class="lg-card tm-filters relative z-20">
        <div class="lg-fields tm-filter-grid">
          <div><date-picker class="w-full" :placeholder="$t('Date From')" v-model="form.date_from" /></div>
          <div><date-picker class="w-full" :placeholder="$t('Date To')" v-model="form.date_to" /></div>
          <div><auto-complete id="filter_asset" class="w-full" :suggestions="vehicleOptions" v-model="form.asset_id" :placeholder="$t('Vehicle')" clearable /></div>
          <div class="sa-search-wrap">
            <icons name="search" class="w-4 h-4 sa-search-icon" />
            <input v-model="form.search" type="text" :placeholder="$t('Search trip #, destination…')" class="sa-search-input" />
          </div>
          <div><auto-complete id="filter_driver" class="w-full" :suggestions="driverOptions" v-model="form.driver_contact_id" :placeholder="$t('Driver')" clearable /></div>
          <div>
            <auto-complete id="filter_destination_type" class="w-full" :suggestions="destinationTypeOptions" v-model="form.destination_type" :placeholder="$t('All Destination Types')" clearable />
          </div>
          <div>
            <auto-complete id="filter_payment_status" class="w-full" :suggestions="paymentStatusOptions" v-model="form.payment_status" :placeholder="$t('All Payment Statuses')" clearable />
          </div>
        </div>
      </div>

      <div v-if="canCreatePaymentVoucher && selectedIds.length" class="tm-selbar">
        <span class="tm-selbar-text">
          <span class="lg-card-ico lg-ico-navy tm-selbar-ico"><icons name="tick" class="w-4 h-4" /></span>
          {{ selectedIds.length }} {{ $t('trip(s) selected') }}
        </span>
        <button type="button" class="lg-btn lg-btn-primary" @click="createVoucher">
          <icons name="doc-text" class="w-4 h-4" /> {{ $t('Create Payment Voucher from') }} {{ selectedIds.length }} {{ $t('trip(s)') }}
        </button>
      </div>

      <div v-if="canBulkVerify && selectedVerifyIds.length" class="tm-selbar tm-selbar-violet">
        <span class="tm-selbar-text">
          <span class="lg-card-ico lg-ico-violet tm-selbar-ico"><icons name="tick" class="w-4 h-4" /></span>
          {{ selectedVerifyIds.length }} {{ $t('unverified trip(s) selected to verify (they cannot be paid yet)') }}
        </span>
        <button type="button" class="lg-btn tm-btn-violet" @click="openBulkVerify">
          <icons name="tick" class="w-4 h-4" /> {{ $t('Verify') }} {{ selectedVerifyIds.length }} {{ $t('trip(s)') }}
        </button>
      </div>

      <div class="lg-table-wrap -mx-4 md:mx-0">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th v-if="canCreatePaymentVoucher || canBulkVerify" class="tm-check-col">
                <span v-if="canCreatePaymentVoucher && eligibleIds.length" class="lg-tip tip-left tm-check-tip" :data-tip="$t('Select all eligible trips on this page')">
                  <input
                    type="checkbox"
                    class="tr-checkbox"
                    :checked="allEligibleSelected"
                    :aria-label="$t('Select all eligible trips on this page')"
                    @change="toggleSelectAll"
                  />
                </span>
                <span v-else-if="canBulkVerify && verifyEligibleIds.length" class="lg-tip tip-left tm-check-tip" :data-tip="$t('Select all unverified trips on this page')">
                  <input
                    type="checkbox"
                    class="tr-checkbox tr-checkbox-violet"
                    :checked="allVerifyEligibleSelected"
                    :aria-label="$t('Select all unverified trips on this page')"
                    @change="toggleVerifySelectAll"
                  />
                </span>
              </th>
              <th class="w-12">{{ $t('No') }}</th>
              <th class="is-sortable" @click="sortBy('trip_number')">
                <span class="lg-sort">{{ $t('Trip #') }}<span class="tr-sort-caret">{{ sortCaret('trip_number') }}</span></span>
              </th>
              <th class="is-sortable" @click="sortBy('trip_date')">
                <span class="lg-sort">{{ $t('Date') }}<span class="tr-sort-caret">{{ sortCaret('trip_date') }}</span></span>
              </th>
              <th class="is-sortable" @click="sortBy('vehicle')">
                <span class="lg-sort">{{ $t('Vehicle') }}<span class="tr-sort-caret">{{ sortCaret('vehicle') }}</span></span>
              </th>
              <th class="is-sortable" @click="sortBy('driver')">
                <span class="lg-sort">{{ $t('Driver') }}<span class="tr-sort-caret">{{ sortCaret('driver') }}</span></span>
              </th>
              <th>{{ $t('Destination') }}</th>
              <th class="is-sortable num" @click="sortBy('amount')">
                <span class="lg-sort">{{ $t('Amount') }}<span class="tr-sort-caret">{{ sortCaret('amount') }}</span></span>
              </th>
              <th class="is-sortable" @click="sortBy('payment_status')">
                <span class="lg-sort">{{ $t('Payment Status') }}<span class="tr-sort-caret">{{ sortCaret('payment_status') }}</span></span>
              </th>
              <th class="is-sortable" @click="sortBy('verified')">
                <span class="lg-sort">{{ $t('Verified') }}<span class="tr-sort-caret">{{ sortCaret('verified') }}</span></span>
              </th>
              <th class="lg-sticky-end"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(r, idx) in records.data" :key="r.id" class="is-click" @click="$inertia.visit(route('transport-records.show', r.id))">
              <td v-if="canCreatePaymentVoucher || canBulkVerify" class="tm-check-col" data-label="" @click.stop>
                <input
                  v-if="canCreatePaymentVoucher && isEligible(r)"
                  type="checkbox"
                  class="tr-checkbox"
                  :checked="selectedIds.includes(r.id)"
                  :aria-label="$t('Select')"
                  @change="toggleSelect(r.id)"
                />
                <!-- an unverified trip can never be paid: it gets a lock, not a payment checkbox. A person who may verify ticks the violet box to VERIFY it. -->
                <label
                  v-else-if="!r.is_verified && canBulkVerify && r.can_verify"
                  class="tr-verify-box lg-tip tip-left"
                  :class="{ 'is-on': selectedVerifyIds.includes(r.id) }"
                  :data-tip="$t('Unverified: it cannot be paid until it is verified. Tick this box to verify it.')"
                >
                  <input type="checkbox" :checked="selectedVerifyIds.includes(r.id)" :aria-label="$t('Select to verify (an unverified trip cannot be paid)')" @change="toggleVerifySelect(r.id)" />
                  <span aria-hidden="true"><icons name="lock-closed" class="tr-vb-lock" /><icons name="tick" class="tr-vb-tick" /></span>
                </label>
                <span v-else-if="!r.is_verified && r.payment_status !== 'Cancelled'" class="tr-locked lg-tip tip-left" :data-tip="$t('Unverified: it cannot be paid until it is verified.')"><icons name="lock-closed" class="w-3.5 h-3.5" /></span>
                <span v-else class="tr-checkbox-placeholder"></span>
              </td>
              <td :data-label="$t('No')" class="tm-no">{{ (records.meta?.from ?? 1) + idx }}</td>
              <td :data-label="$t('Trip #')">
                <span class="lg-main">{{ r.trip_number }}</span>
                <a v-if="r.transfer" :href="r.transfer.url" target="_blank" rel="noopener" class="tr-transfer-chip lg-tip" :data-tip="$t('Generated from Transfer') + ' ' + r.transfer.reference + ' · ' + $t('opens in a new tab')" @click.stop>
                  <icons name="truck" class="w-3 h-3" />{{ r.transfer.reference }}
                </a>
              </td>
              <td :data-label="$t('Date')" class="tm-nowrap">
                <div class="tm-tab">{{ $date(r.trip_date) }}</div>
                <div v-if="r.trip_time" class="lg-subtext">{{ r.trip_time }}</div>
              </td>
              <td :data-label="$t('Vehicle')"><span class="tr-maroon">{{ r.asset?.registration_number || r.asset?.name || '—' }}</span></td>
              <td :data-label="$t('Driver')">
                <span v-if="r.driver" class="lg-person">
                  <span class="lg-avatar">{{ initials(r.driver.name) }}</span>
                  <span class="lg-main">{{ r.driver.name }}</span>
                </span>
                <span v-else class="lg-subtext">—</span>
              </td>
              <td :data-label="$t('Destination')">
                <span class="lg-chip lg-chip-indigo" style="text-transform: none">{{ r.is_campus ? $t('Site campus') : typeLabel(r.destination_type) }}</span>
                <div class="lg-subtext tm-dest">
                  <template v-if="r.is_campus">{{ r.destination_label }}<template v-if="r.departure_location || r.destination_location"> · {{ r.departure_location || '…' }} → {{ r.destination_location || '…' }}</template></template>
                  <template v-else><template v-if="r.departure_label">{{ r.departure_label }} → </template>{{ r.destination_label }}</template>
                </div>
              </td>
              <td :data-label="$t('Amount')" class="num">
                <span
                  class="lg-main tr-maroon"
                  :class="{ 'tr-override-flag': r.amount_overridden }"
                  @mouseenter="r.amount_overridden && showAmountTooltip($event, r)"
                  @mouseleave="r.amount_overridden && hideTooltip()"
                >{{ $money(r.amount) }}</span>
                <div v-if="r.trips_count > 1" class="lg-subtext tm-nowrap">{{ r.trips_count }} {{ $t('trips') }} × {{ $money(r.unit_amount) }}</div>
              </td>
              <td :data-label="$t('Payment Status')">
                <span class="lg-chip" :class="'tm-pay-' + statusSlug(r.payment_status)" style="text-transform: none">
                  {{ $t(r.payment_status) }}
                  <span v-if="r.paid_without_voucher" class="lg-tip tip-left tm-inline-tip" :data-tip="$t('Paid without a Payment Voucher')">
                    <icons name="exclamation" class="w-3 h-3 inline-flex" />
                  </span>
                </span>
                <div v-if="r.payment_voucher_id" class="lg-subtext tm-nowrap mt-1">
                  {{ $t('On voucher') }}
                  <a v-if="$can('read-payment-vouchers')" :href="route('payment-vouchers.show', r.payment_voucher_id)" target="_blank" rel="noopener" class="tm-pv-link" @click.stop>{{ r.payment_voucher?.pv_number || $t('Payment Voucher') }}</a>
                  <template v-else>{{ r.payment_voucher?.pv_number }}</template>
                  <span v-if="r.payment_voucher?.status"> ({{ $t(r.payment_voucher.status) }})</span>
                </div>
              </td>
              <td :data-label="$t('Verified')">
                <span v-if="r.is_verified" class="lg-chip lg-chip-green" :class="{ 'lg-tip tip-left': r.verified_at }" :data-tip="r.verified_at ? $date(r.verified_at) : null" style="text-transform: none">
                  <icons name="tick" class="w-3 h-3 inline-flex" /> {{ $t('Verified') }}
                </span>
                <span v-else class="lg-chip lg-chip-slate" style="text-transform: none">{{ $t('Unverified') }}</span>
              </td>
              <td class="lg-sticky-end" data-label="" @click.stop>
                <div class="lg-actions">
                  <Link :href="route('transport-records.show', r.id)" class="lg-icon-btn lg-tip" :data-tip="$t('View')" :aria-label="$t('View')">
                    <icons name="eye-flat"></icons>
                  </Link>
                  <button
                    v-if="r.can_verify"
                    type="button"
                    class="lg-icon-btn lg-tip tm-ib-violet"
                    :data-tip="$t('Verify Record')"
                    :aria-label="$t('Verify Record')"
                    @click="verifyRecord(r)"
                  >
                    <icons name="tick"></icons>
                  </button>
                  <a
                    v-if="r.payment_voucher_id && $can('read-payment-vouchers')"
                    :href="route('payment-vouchers.show', r.payment_voucher_id)"
                    target="_blank"
                    rel="noopener"
                    class="lg-icon-btn lg-tip tm-ib-blue"
                    :data-tip="$t('View Payment Voucher') + (r.payment_voucher?.pv_number ? ' ' + r.payment_voucher.pv_number : '') + ' (' + $t('new tab') + ')'"
                    :aria-label="$t('View Payment Voucher')"
                  >
                    <icons name="doc-text"></icons>
                  </a>
                  <button v-if="r.can_create_payment_voucher" type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Create Payment Voucher')" :aria-label="$t('Create Payment Voucher')" @click="createVoucherForRecord(r)">
                    <icons name="doc-text"></icons>
                  </button>
                  <button v-if="r.can_mark_paid" type="button" class="lg-icon-btn is-green lg-tip" :data-tip="$t('Mark Paid')" :aria-label="$t('Mark Paid')" @click="handleMarkPaidClick(r)">
                    <icons name="cash"></icons>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!records.data.length" class="lg-empty-row">
              <td class="lg-empty" :colspan="(canCreatePaymentVoucher || canBulkVerify) ? 11 : 10">
                <icons name="truck" class="w-8 h-8 mx-auto mb-2 opacity-50" />
                {{ $t('There is no data to display.') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <pagination class="mt-6" :meta="records.meta" :links="records.links" />

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
              <h3 class="lg-card-title">{{ $t('Mark Paid') }} - {{ markPaidModal.record?.trip_number }}</h3>
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

      <div v-if="bulkVerifyModal.open" class="tm-modal-overlay" @click.self="closeBulkVerify">
        <div class="tm-modal-card">
          <div class="tm-modal-head">
            <span class="lg-card-ico lg-ico-violet"><icons name="tick" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h3 class="lg-card-title">{{ $t('Verify') }} {{ selectedVerifyIds.length }} {{ $t('trip(s)') }}</h3>
              <p class="lg-card-sub">{{ $t('This is a sensitive bulk action, confirm your password to continue.') }}</p>
            </div>
          </div>

          <tec-label for="bulk_verify_password" :value="$t('Your Password')" />
          <input
            id="bulk_verify_password"
            type="password"
            autocomplete="current-password"
            class="mt-1 block w-full rounded-md border-gray-300"
            v-model="bulkVerifyForm.password"
          />
          <input-error :message="bulkVerifyForm.errors.password" class="mt-1" />

          <div class="tm-modal-foot">
            <button type="button" class="lg-btn lg-btn-soft" @click="closeBulkVerify">
              <icons name="cross" class="w-4 h-4 inline-flex" />
              {{ $t('Cancel') }}
            </button>
            <loading-button type="button" :loading="bulkVerifyForm.processing" :disabled="bulkVerifyForm.processing" class="tr-modal-submit-btn tr-modal-submit-violet" @click="submitBulkVerify">
              <icons v-if="!bulkVerifyForm.processing" name="tick" class="w-4 h-4 inline-flex mr-2" />
              {{ $t('Verify') }}
            </loading-button>
          </div>
        </div>
      </div>
    </div>

    <!-- Teleported to <body>, positioned from the trigger's own
         getBoundingClientRect, so it escapes the table wrapper's overflow
         clipping. Kept bespoke (not the shared v-tooltip directive) because it
         renders a structured before/after/reason panel, not a plain string. -->
    <Teleport to="body">
      <div v-if="tooltip.visible" class="tr-tooltip" :style="tooltip.style">
        <div class="tr-tooltip-row"><span>{{ $t('Default Amount') }}</span><strong>{{ $money(tooltip.record.default_amount) }}</strong></div>
        <div class="tr-tooltip-row"><span>{{ $t('Amount per trip') }}</span><strong>{{ $money(tooltip.record.unit_amount) }}</strong></div>
        <div v-if="tooltip.record.trips_count > 1" class="tr-tooltip-row"><span>{{ $t('Trips') }}</span><strong>{{ tooltip.record.trips_count }}</strong></div>
        <div class="tr-tooltip-row"><span>{{ $t('Total Amount') }}</span><strong>{{ $money(tooltip.record.amount) }}</strong></div>
        <div v-if="tooltip.record.amount_change_reason" class="tr-tooltip-reason">
          <span>{{ $t('Reason for change') }}</span>
          <div>{{ tooltip.record.amount_change_reason }}</div>
        </div>
      </div>
    </Teleport>
  </admin-layout>
</template>

<script>
import { transportTypeLabel } from '@/Core/transportTypes';
import throttle from 'lodash/throttle';
import pickBy from 'lodash/pickBy';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TecLabel from '@/Jetstream/Label.vue';
import TextInput from '@/Shared/TextInput.vue';
import InputError from '@/Jetstream/InputError.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import Pagination from '@/Shared/Pagination.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { Link, AdminLayout, TecLabel, TextInput, InputError, AutoComplete, DatePicker, LoadingButton, Pagination, PageHeader },

  props: {
    filters: Object,
    records: Object,
    destinationTypes: Array,
    paymentStatuses: Array,
    vehicles: Array,
    drivers: Array,
    canCreate: Boolean,
    canCreatePaymentVoucher: Boolean,
    canBulkVerify: Boolean,
  },

  data() {
    return {
      form: {
        search: this.filters.search || '',
        asset_id: this.filters.asset_id || null,
        driver_contact_id: this.filters.driver_contact_id || null,
        destination_type: this.filters.destination_type || null,
        payment_status: this.filters.payment_status || null,
        date_from: this.filters.date_from || '',
        date_to: this.filters.date_to || '',
        sort: this.filters.sort || null,
        direction: this.filters.direction || 'desc',
      },
      selectedIds: [],
      selectedVerifyIds: [],
      paymentChoiceModal: { open: false, record: null },
      markPaidModal: { open: false, record: null },
      markPaidForm: this.$inertia.form({
        paid_amount: '',
        payment_date: '',
        payment_method: '',
        payment_reference: '',
        payment_notes: '',
      }),
      bulkVerifyModal: { open: false },
      bulkVerifyForm: this.$inertia.form({
        transport_record_ids: [],
        password: '',
      }),
      tooltip: { visible: false, record: null, style: {} },
    };
  },

  computed: {
    vehicleOptions() {
      return (this.vehicles || []).map(v => ({ id: v.id, name: (v.registration_number ? v.registration_number + ' - ' : '') + v.name }));
    },
    driverOptions() {
      return (this.drivers || []).map(d => ({ id: d.id, name: d.name + (d.phone ? ' (' + d.phone + ')' : '') }));
    },
    destinationTypeOptions() {
      return this.destinationTypes.map(d => ({ value: d, label: transportTypeLabel(this.$t, d) }));
    },
    paymentStatusOptions() {
      return this.paymentStatuses.map(s => ({ value: s, label: this.$t(s) }));
    },
    queryString() {
      const query = pickBy(this.form);
      const qs = new URLSearchParams(query).toString();
      return qs ? '?' + qs : '';
    },
    printUrl() {
      return this.route('transport-records.print-list') + this.queryString;
    },
    pdfUrl() {
      return this.route('transport-records.pdf-list') + this.queryString;
    },
    // Mirrors the backend's scopeUnpaidAvailableForVoucher — only Unpaid
    // trips that aren't already linked to a Payment Voucher can be combined
    // into a new one.
    eligibleIds() {
      return this.records.data.filter(r => this.isEligible(r)).map(r => r.id);
    },
    allEligibleSelected() {
      return this.eligibleIds.length > 0 && this.eligibleIds.every(id => this.selectedIds.includes(id));
    },
    verifyEligibleIds() {
      return this.records.data.filter(r => r.can_verify).map(r => r.id);
    },
    allVerifyEligibleSelected() {
      return this.verifyEligibleIds.length > 0 && this.verifyEligibleIds.every(id => this.selectedVerifyIds.includes(id));
    },
  },

  watch: {
    form: {
      handler: throttle(function () {
        this.$inertia.visit(this.route('transport-records.index', pickBy(this.form)), {
          preserveState: true,
          preserveScroll: true,
          replace: true,
        });
      }, 300),
      deep: true,
    },
    // A trip selected under one filter/page can silently fall out of view
    // under the next (the shared <pagination> component's own links use
    // preserveState too, so this can't be caught from the filter watcher
    // above alone) — the action bar still showed the old count, so a
    // "Create Payment Voucher from N trip(s)"/"Verify N trip(s)" click
    // could act on trips no longer on screen. `records` is a whole new
    // prop object on every Inertia navigation, filter or page, so this
    // fires exactly when the visible trip list actually changed.
    records() {
      this.selectedIds = [];
      this.selectedVerifyIds = [];
    },
  },

  methods: {
    typeLabel(type) {
      return transportTypeLabel(this.$t, type);
    },
    isEligible(r) {
      return r.payment_status === 'Unpaid' && !r.payment_voucher_id && r.is_verified;
    },
    async verifyRecord(r) {
      if (!(await this.$confirm(this.$t('Verify') + ' ' + r.trip_number + '? ' + this.$t('This makes it eligible for payment.')))) {
        return;
      }
      this.$inertia.patch(this.route('transport-records.verify', r.id), {}, { preserveScroll: true });
    },
    toggleSelect(id) {
      // only a verified, unpaid trip without a voucher can ever be selected for a Payment Voucher
      if (!this.eligibleIds.includes(id)) return;
      const i = this.selectedIds.indexOf(id);
      if (i === -1) {
        this.selectedIds.push(id);
      } else {
        this.selectedIds.splice(i, 1);
      }
    },
    toggleSelectAll() {
      this.selectedIds = this.allEligibleSelected ? [] : [...this.eligibleIds];
    },
    toggleVerifySelectAll() {
      this.selectedVerifyIds = this.allVerifyEligibleSelected ? [] : [...this.verifyEligibleIds];
    },
    // Normalizes a status label into the CSS-safe suffix used by the
    // .tv-pay-* badge modifiers below, matching the same convention as
    // TransportManagement/Vehicles' own tv-status-*/tv-pay-* badges, e.g.
    // "Pending Payment" -> "pending-payment".
    statusSlug(status) {
      return (status || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
    },
    // Server-side sort (the list is paginated, so a client-only sort would
    // only ever reorder the current page) — toggling the same column flips
    // direction, picking a new one starts it ascending, matching the
    // Payment Vouchers list's own sortBy()/isSorted() convention.
    initials(name) {
      return (name || '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map(w => w[0].toUpperCase())
        .join('');
    },
    sortBy(column) {
      if (this.form.sort === column) {
        this.form.direction = this.form.direction === 'asc' ? 'desc' : 'asc';
      } else {
        this.form.sort = column;
        this.form.direction = 'asc';
      }
    },
    sortCaret(column) {
      if (this.form.sort !== column) return '';
      return this.form.direction === 'asc' ? '▲' : '▼';
    },
    async createVoucher() {
      if (!(await this.$confirm(this.$t('Create one Payment Voucher covering the selected trips? All selected trips must share the same driver.'), { variant: 'danger' }))) {
        return;
      }
      this.$inertia.post(
        this.route('transport-records.create-payment-voucher'),
        { transport_record_ids: this.selectedIds.filter(id => this.eligibleIds.includes(id)) },
        { onSuccess: () => (this.selectedIds = []) }
      );
    },
    // Mark Paid is only ever offered for a trip with no Payment Voucher yet
    // (can_mark_paid requires payment_voucher_id === null) — so whenever
    // creating a voucher is also still possible, jumping straight into the
    // amount/date form skips a real choice the user should get to make.
    // This intercepts that click with a choice instead of forcing one path.
    handleMarkPaidClick(r) {
      if (r.can_create_payment_voucher) {
        this.paymentChoiceModal = { open: true, record: r };
      } else {
        this.openMarkPaid(r);
      }
    },
    closePaymentChoice() {
      this.paymentChoiceModal.open = false;
    },
    chooseCreateVoucher() {
      const r = this.paymentChoiceModal.record;
      this.paymentChoiceModal.open = false;
      this.createVoucherForRecord(r);
    },
    chooseMarkPaidDirect() {
      const r = this.paymentChoiceModal.record;
      this.paymentChoiceModal.open = false;
      this.openMarkPaid(r);
    },
    async createVoucherForRecord(r) {
      if (!(await this.$confirm(this.$t('Create a Payment Voucher for') + ' ' + r.trip_number + '?'))) return;
      this.$inertia.post(this.route('transport-records.create-payment-voucher'), { transport_record_ids: [r.id] });
    },
    openMarkPaid(r) {
      this.markPaidModal = { open: true, record: r };
      this.markPaidForm.reset();
      this.markPaidForm.paid_amount = r.outstanding_amount;
      this.markPaidForm.payment_date = new Date().toISOString().slice(0, 10);
    },
    closeMarkPaid() {
      this.markPaidModal.open = false;
    },
    submitMarkPaid() {
      this.markPaidForm.patch(this.route('transport-records.mark-paid', this.markPaidModal.record.id), {
        preserveScroll: true,
        onSuccess: () => (this.markPaidModal.open = false),
      });
    },
    toggleVerifySelect(id) {
      const i = this.selectedVerifyIds.indexOf(id);
      if (i === -1) {
        this.selectedVerifyIds.push(id);
      } else {
        this.selectedVerifyIds.splice(i, 1);
      }
    },
    openBulkVerify() {
      this.bulkVerifyForm.reset();
      this.bulkVerifyForm.clearErrors();
      this.bulkVerifyForm.transport_record_ids = [...this.selectedVerifyIds];
      this.bulkVerifyModal.open = true;
    },
    closeBulkVerify() {
      this.bulkVerifyModal.open = false;
    },
    submitBulkVerify() {
      this.bulkVerifyForm.patch(this.route('transport-records.bulk-verify'), {
        preserveScroll: true,
        onSuccess: () => {
          this.bulkVerifyModal.open = false;
          this.selectedVerifyIds = [];
        },
      });
    },
    // Positions the Teleported amount-diff tooltip from the trigger's own
    // rect (viewport coordinates, matching `position: fixed`), flipping
    // onto whichever side actually has room — same technique as the shared
    // v-tooltip directive and the Payment Vouchers list's PV Number tooltip.
    showAmountTooltip(event, record) {
      const rect = event.currentTarget.getBoundingClientRect();
      const gap = 8;
      const width = Math.min(280, window.innerWidth - 24);
      const openUp = rect.top > 220 || window.innerHeight - rect.bottom < 180;
      this.tooltip = {
        visible: true,
        record,
        style: {
          left: Math.max(12, Math.min(rect.left, window.innerWidth - width - 12)) + 'px',
          width: width + 'px',
          ...(openUp ? { bottom: window.innerHeight - rect.top + gap + 'px' } : { top: rect.bottom + gap + 'px' }),
        },
      };
    },
    hideTooltip() {
      this.tooltip.visible = false;
    },
  },
};
</script>

<style scoped>
.tr-maroon { color: #800000 !important; font-weight: 800 !important; }
html.dark .tr-maroon { color: #f08a8a !important; }
.tm-filters { padding-bottom: 1.15rem; }
.tm-filter-grid { grid-template-columns: repeat(auto-fit, minmax(min(190px, 100%), 1fr)); align-items: center; }

.sa-search-wrap { position: relative; display: flex; align-items: center; }
.sa-search-icon { position: absolute; left: 0.8rem; color: var(--lg-faint); pointer-events: none; }
.sa-search-input {
  width: 100%; border: 1px solid var(--lg-line); border-radius: 9999px; color: var(--lg-ink);
  padding: 0.55rem 0.9rem 0.55rem 2.3rem; font-size: 0.82rem; background: var(--lg-soft);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.sa-search-input:focus { outline: none; border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12); }

/* selection bars */
.tm-selbar {
  display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
  margin-bottom: 1rem; padding: 0.7rem 1rem; border-radius: 18px;
  background: var(--lg-card); border: 1px solid rgba(59, 130, 246, 0.35); box-shadow: var(--lg-shadow);
  animation: tm-bar-in 0.18s ease-out;
}
.tm-selbar-violet { border-color: rgba(139, 92, 246, 0.4); }
.tm-selbar-text { display: inline-flex; align-items: center; gap: 10px; font-size: 0.85rem; font-weight: 700; color: var(--lg-ink); }
.tm-selbar-ico { width: 30px; height: 30px; border-radius: 10px; }
.tm-btn-violet { color: #fff; background: linear-gradient(145deg, #a78bfa, #6d28d9); box-shadow: 0 10px 18px -10px rgba(109, 40, 217, 0.9); }
@keyframes tm-bar-in { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }

.tm-check-col { width: 1%; }
.tm-check-tip { display: inline-flex; }
.tm-inline-tip { display: inline-flex; cursor: help; }
.tm-ib-blue { color: #1d4ed8; background: rgba(59, 130, 246, 0.14); }
html.dark .tm-ib-blue { color: #93c5fd; }
.tm-pv-link { color: #1d4ed8; font-weight: 700; text-decoration: underline dotted; text-underline-offset: 2px; }
html.dark .tm-pv-link { color: #93c5fd; }
.tm-ib-violet { color: #6d28d9; background: rgba(139, 92, 246, 0.14); }
html.dark .tm-ib-violet { color: #c4b5fd; }
.tm-nowrap { white-space: nowrap; }
.tm-tab { font-variant-numeric: tabular-nums; }
.tm-dest { max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.tr-sort-caret { display: inline-block; margin-left: 2px; font-size: 0.6rem; color: #818cf8; }
.lg-table th.num .lg-sort { justify-content: flex-end; }

/* payment status chips */
.tm-pay-unpaid { color: #92400e; background: rgba(245, 158, 11, 0.17); }
.tm-pay-pending-payment { color: #1d4ed8; background: rgba(59, 130, 246, 0.13); }
.tm-pay-partially-paid { color: #6d28d9; background: rgba(139, 92, 246, 0.14); }
.tm-pay-paid { color: #047857; background: rgba(16, 185, 129, 0.14); }
.tm-pay-cancelled { color: #475569; background: rgba(100, 116, 139, 0.14); }
html.dark .tm-pay-unpaid { color: #fcd34d; } html.dark .tm-pay-pending-payment { color: #93c5fd; }
html.dark .tm-pay-partially-paid { color: #c4b5fd; } html.dark .tm-pay-paid { color: #6ee7b7; } html.dark .tm-pay-cancelled { color: #cbd5e1; }

.tr-override-flag { cursor: help; color: #dc2626 !important; }
html.dark .tr-override-flag { color: #f87171 !important; }

.tr-checkbox {
  width: 1rem; height: 1rem; accent-color: #4f46e5; cursor: pointer;
  border: 1.5px solid #93c5fd; border-radius: 4px; outline-offset: 1px;
}
.tr-checkbox:focus-visible { outline: 2px solid #3b82f6; }
.tr-checkbox-violet { accent-color: #7c3aed; border-color: #c4b5fd; }
.tr-verify-box { position: relative; display: inline-grid; place-items: center; width: 1.25rem; height: 1.25rem; cursor: pointer; }
.tr-verify-box input { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; opacity: 0; cursor: pointer; }
.tr-verify-box > span { display: grid; place-items: center; width: 1.25rem; height: 1.25rem; border-radius: 9999px; border: 1.5px dashed #a78bfa; color: #7c3aed; background: rgba(237, 233, 254, 0.7); transition: all 0.15s ease; }
.tr-verify-box .tr-vb-lock { width: 0.7rem; height: 0.7rem; } .tr-verify-box .tr-vb-tick { display: none; width: 0.7rem; height: 0.7rem; }
.tr-verify-box.is-on > span { border-style: solid; color: #fff; background: linear-gradient(145deg, #8b5cf6, #6d28d9); }
.tr-verify-box.is-on .tr-vb-lock { display: none; } .tr-verify-box.is-on .tr-vb-tick { display: block; }
.tr-verify-box input:focus-visible + span { outline: 2px solid #7c3aed; outline-offset: 2px; }
.tr-locked { display: inline-grid; place-items: center; width: 1.25rem; height: 1.25rem; border-radius: 9999px; color: #94a3b8; background: rgba(148, 163, 184, 0.15); cursor: help; }
.tr-checkbox-placeholder { display: inline-block; width: 1rem; height: 1rem; }

/* Teleported amount-diff tooltip: position fixed, above the modal overlays */
.tr-tooltip {
  position: fixed; z-index: 999999; min-width: 180px; max-width: calc(100vw - 24px);
  background: linear-gradient(145deg, rgba(15, 23, 42, 0.97), rgba(30, 41, 59, 0.94)); color: #f8fafc;
  border-radius: 0.65rem; padding: 0.6rem 0.75rem; font-size: 0.78rem;
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.25); pointer-events: none;
}
.tr-tooltip-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 0.72rem; font-weight: 600; padding: 2px 0; white-space: nowrap; }
.tr-tooltip-row span { color: #94a3b8; text-transform: uppercase; letter-spacing: 0.02em; font-size: 0.65rem; }
.tr-tooltip-row strong { color: #f8fafc; font-weight: 700; }
.tr-tooltip-reason { margin-top: 4px; padding-top: 4px; border-top: 1px solid rgba(255, 255, 255, 0.12); }
.tr-tooltip-reason span { display: block; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.02em; font-size: 0.65rem; font-weight: 600; margin-bottom: 2px; }
.tr-tooltip-reason div { font-size: 0.72rem; font-weight: 600; color: #e2e8f0; white-space: normal; line-height: 1.4; }

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
@media (prefers-reduced-motion: reduce) { .tm-selbar, .tm-modal-card, .tm-modal-overlay { animation: none; } }
.tr-transfer-chip { display: inline-flex; align-items: center; gap: 4px; margin-top: 3px; padding: 1px 8px; border-radius: 9999px; font-size: 0.66rem; font-weight: 700; color: #166534; background: #dcfce7; border: 1px solid #bbf7d0; text-decoration: none; white-space: nowrap; }
.tr-transfer-chip:hover { background: #bbf7d0; }
.tr-transfer-chip { display: flex; width: fit-content; }
</style>

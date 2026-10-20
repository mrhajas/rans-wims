<template>
  <admin-layout :title="$t('Payment Vouchers')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="doc-text" :title="$t('Payment Vouchers')" :subtitle="$t('Prepared, approved, and authorised payment vouchers, printable in the RANS voucher format.')">
        <a :href="route('payment-vouchers.export') + queryString" class="lg-btn lg-btn-green">
          <icons name="table" class="w-4 h-4" />{{ $t('Export Excel') }}
        </a>
        <Link :href="route('payment-vouchers.batches.index')" class="lg-btn lg-btn-dark">
          <icons name="folder" class="w-4 h-4" />{{ $t('Batches') }}
        </Link>
        <Link v-if="$can('create-payment-vouchers')" :href="route('payment-vouchers.batches.create')" class="lg-btn lg-btn-soft">
          <icons name="plus" class="w-4 h-4" />{{ $t('Create Bulk Vouchers') }}
        </Link>
        <Link :href="route('payment-vouchers.create')" class="lg-btn lg-btn-primary">
          <icons name="plus" class="w-4 h-4" />{{ $t('Create Payment Voucher') }}
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" @select="pickTile" />

      <!-- separation of duties: nobody takes two steps of the same voucher (a Super Admin can change it, with their password) -->
      <section class="lg-card pv-rule">
        <span class="lg-card-ico" :class="separation ? 'lg-ico-emerald' : 'lg-ico-amber'"><icons name="lock-closed" class="w-5 h-5" /></span>
        <div class="min-w-0 flex-1">
          <h2 class="lg-card-title">{{ separation ? $t('Separation of duties is on') : $t('Separation of duties is off') }}</h2>
          <p class="lg-card-sub">{{ separation ? $t('The person who prepares a voucher cannot approve or authorise it, the person who approves it cannot authorise it, and the person who authorises it cannot record its payment. Super Admins are not bound by this rule.') : $t('One person can take several steps of the same voucher. Switch it on so that every step is done by a different person.') }}</p>
        </div>
        <button v-if="canChangeRules" type="button" class="lg-btn lg-btn-soft" @click="openRules"><icons name="cog" class="w-4 h-4" />{{ $t('Change rule') }}</button>
      </section>
      <modal :show="rulesOpen" max-width="md" @close="rulesOpen = false">
        <div class="pv-paydlg">
          <header class="pv-paydlg__head"><icons name="lock-closed" class="w-5 h-5" /><div><h3>{{ $t('Separation of duties') }}</h3><p>{{ $t('Applies to everyone except Super Admins.') }}</p></div></header>
          <div class="pv-paydlg__body">
            <label class="pv-opt" :class="{ 'is-on': rulesForm.separation === true }"><input v-model="rulesForm.separation" type="radio" :value="true" /><span><b>{{ $t('On') }}</b><small>{{ $t('Prepared, approved, authorised and paid by different people.') }}</small></span></label>
            <label class="pv-opt" :class="{ 'is-on': rulesForm.separation === false }"><input v-model="rulesForm.separation" type="radio" :value="false" /><span><b>{{ $t('Off') }}</b><small>{{ $t('One person may take several steps of the same voucher.') }}</small></span></label>
            <text-input v-model="rulesForm.password" type="password" :label="$t('Your password')" :error="rulesForm.errors.password" autocomplete="current-password" />
          </div>
          <footer class="pv-paydlg__foot">
            <button type="button" class="lg-btn lg-btn-soft" @click="rulesOpen = false">{{ $t('Cancel') }}</button>
            <button type="button" class="lg-btn lg-btn-green" :disabled="rulesForm.processing || !rulesForm.password" @click="saveRules"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button>
          </footer>
        </div>
      </modal>

      <div v-if="selectedIds.length" class="pv-bulk-bar mb-4 flex items-center justify-between gap-3 flex-wrap">
        <span class="text-sm font-bold pv-bulk-count">{{ selectedIds.length }} {{ $t('selected') }}<small v-if="bulk.pay.length || bulk.approve.length || bulk.authorise.length" class="pv-bulk-hint"> · <template v-if="bulk.approve.length">{{ bulk.approve.length }} {{ $t('to approve') }}</template><template v-if="bulk.authorise.length"><template v-if="bulk.approve.length">, </template>{{ bulk.authorise.length }} {{ $t('to authorise') }}</template><template v-if="bulk.pay.length"><template v-if="bulk.approve.length || bulk.authorise.length">, </template>{{ bulk.pay.length }} {{ $t('to pay') }}</template></small></span>
        <div class="flex items-center gap-2 flex-wrap">
          <button type="button" class="lg-btn lg-btn-soft" @click="selectedIds = []">{{ $t('Clear Selection') }}</button>
          <!-- what can be done depends on what is checked: approving only when some checked voucher is not approved yet, paying only when some is Authorised and unpaid -->
          <button v-if="$can('approve-payment-vouchers') && bulk.approve.length" type="button" class="lg-btn lg-btn-dark" @click="bulkApprove">
            <icons name="tick" class="w-4 h-4" />{{ $t('Approve Selected') }} ({{ bulk.approve.length }})
          </button>
          <button v-if="$can('approve-payment-vouchers') && $can('authorise-payment-vouchers') && (bulk.approve.length || bulk.authorise.length)" type="button" class="lg-btn lg-btn-primary" :title="separation && !$super && bulk.approve.length ? $t('Vouchers you approve here stay Approved: the person who approves a voucher cannot also authorise it.') : ''" @click="bulkApproveAndAuthorise">
            <icons name="lock-closed" class="w-4 h-4" />{{ $t('Approve & Authorise Selected') }} ({{ bulk.approve.length + bulk.authorise.length }})
          </button>
          <button v-if="$can('record-payment-payment-vouchers') && bulk.pay.length" type="button" class="lg-btn lg-btn-green" @click="openPay">
            <icons name="cash" class="w-4 h-4" />{{ $t('Mark Paid') }} ({{ bulk.pay.length }})
          </button>
        </div>
      </div>

      <section class="lg-card pv-filters">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="filter" class="w-5 h-5" /></span>
          <div class="flex-1 min-w-0">
            <div class="lg-card-title">{{ $t('Filters') }}</div>
            <div class="lg-card-sub">{{ $t('Narrow the list by status, project, payee, type or date.') }}</div>
          </div>
          <button type="button" class="lg-btn lg-btn-soft" @click="reset">
            <icons name="refresh" class="w-4 h-4" />{{ $t('Reset') }}
          </button>
        </div>
        <div class="lg-fields">
          <div><text-input class="w-full" :label="$t('Search')" v-model="form.search" :placeholder="$t('PV Number or Payee…')" /></div>
          <div><text-input class="w-full" :label="$t('Batch Number')" v-model="form.batch_number" :placeholder="$t('PVB-2026-…')" /></div>
          <div><auto-complete id="status" :label="$t('Status')" v-model="form.status" class="w-full" :suggestions="statusOptions" clearable /></div>
          <div><auto-complete id="warehouse" :label="$t('Project')" v-model="form.warehouse_id" class="w-full" :suggestions="warehouseSuggestions" clearable /></div>
          <div v-if="contacts.length"><auto-complete id="payee" :label="$t('Payee')" v-model="form.payee_contact_id" class="w-full" :suggestions="contactSuggestions" clearable /></div>
          <div v-if="staffs.length"><auto-complete id="staff" :label="$t('RANS Staff')" v-model="form.staff_id" class="w-full" :suggestions="staffSuggestions" clearable /></div>
          <div><auto-complete id="type" :label="$t('Type')" v-model="form.type" class="w-full" :suggestions="typeOptions" clearable /></div>
          <div><auto-complete id="source" :label="$t('Source')" v-model="form.source" class="w-full" :suggestions="[{ id: 'labour', label: $t('Labour') }]" :placeholder="$t('All Sources')" clearable /></div>
          <div><text-input class="w-full" type="date" :label="$t('Date From')" v-model="form.date_from" /></div>
          <div><text-input class="w-full" type="date" :label="$t('Date To')" v-model="form.date_to" /></div>
        </div>
      </section>

      <div class="pv-table-toolbar flex items-center justify-between gap-3 flex-wrap mb-3 print:hidden">
        <span class="lg-subtext">{{ paymentVouchers.meta ? paymentVouchers.meta.total : 0 }} {{ $t('result(s)') }}</span>
        <div class="flex items-center gap-2 flex-wrap">
          <a :href="route('payment-vouchers.print-list') + queryString" target="_blank" class="lg-btn lg-btn-soft">
            <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
          </a>
          <a :href="route('payment-vouchers.pdf-list') + queryString" class="lg-btn lg-btn-soft">
            <icons name="download" class="w-4 h-4" />{{ $t('Download') }}
          </a>
        </div>
      </div>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead>
            <tr>
              <th class="w-10">
                <input type="checkbox" class="pv-checkbox" :checked="allVisibleSelected" @change="toggleSelectAll" />
              </th>
              <th class="is-sortable" @click="sortBy('pv_number')">
                <span class="lg-sort">{{ $t('PV Number') }} <sort-icon :active="isSorted('pv_number')" :direction="form.direction" /></span>
              </th>
              <th class="is-sortable" @click="sortBy('payee_name')">
                <span class="lg-sort">{{ $t('Payee/Staff') }} <sort-icon :active="isSorted('payee_name')" :direction="form.direction" /></span>
              </th>
              <th>{{ $t('Project') }}</th>
              <th class="is-sortable" @click="sortBy('voucher_date')">
                <span class="lg-sort">{{ $t('Date') }} <sort-icon :active="isSorted('voucher_date')" :direction="form.direction" /></span>
              </th>
              <th class="is-sortable text-right" @click="sortBy('total_amount')">
                <span class="lg-sort">{{ $t('Amount') }} <sort-icon :active="isSorted('total_amount')" :direction="form.direction" /></span>
              </th>
              <th>{{ $t('Prepared By') }}</th>
              <th>{{ $t('Batch') }}</th>
              <th class="is-sortable" @click="sortBy('status')">
                <span class="lg-sort">{{ $t('Status') }} <sort-icon :active="isSorted('status')" :direction="form.direction" /></span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="v in paymentVouchers.data" :key="v.id" class="is-click">
              <td :data-label="$t('Select')" @click.stop>
                <input type="checkbox" class="pv-checkbox" :checked="selectedIds.includes(v.id)" @change="toggleSelected(v.id)" />
              </td>
              <td :data-label="$t('PV Number')" class="lg-main" @click="goto(v.id)">
                <span class="pv-tooltip-wrap" @mouseenter="showPvTooltip($event, v)" @mouseleave="hidePvTooltip">
                  {{ v.pv_number }}
                </span>
              </td>
              <td :data-label="$t('Payee/Staff')" @click="goto(v.id)">{{ v.payee_name }}</td>
              <td :data-label="$t('Project')" @click="goto(v.id)">{{ v.warehouse_name }}</td>
              <td :data-label="$t('Date')" @click="goto(v.id)">{{ $date(v.voucher_date) }}</td>
              <td :data-label="$t('Amount')" class="num font-semibold" @click="goto(v.id)">{{ $money(v.total_amount) }}</td>
              <td :data-label="$t('Prepared By')" @click="goto(v.id)">{{ v.prepared_by_name || '-' }}</td>
              <td :data-label="$t('Batch')" @click.stop>
                <Link v-if="v.batch_number" :href="route('payment-vouchers.batches.show', v.payment_voucher_batch_id)" class="pv-batch" :title="$t('Open this batch')">
                  <icons name="collection" class="pv-batch__ico" />{{ v.batch_number }}
                </Link>
                <span v-else class="lg-subtext">-</span>
              </td>
              <td :data-label="$t('Status')" @click="goto(v.id)">
                <span class="pv-status" :class="'pv-st-' + statusKey(v.display_status || v.status)"><icons :name="statusIcon(v.display_status || v.status)" class="pv-status__ico" />{{ $t(v.display_status || v.status) }}</span>
              </td>
            </tr>
            <tr v-if="paymentVouchers.data.length === 0" class="lg-empty-row">
              <td class="lg-empty" colspan="9">{{ $t('There is no data to display.') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <pagination v-if="paymentVouchers.data.length" class="mt-6" :meta="paymentVouchers.meta" :links="paymentVouchers.links" />
    </div>

    <!-- Teleported to <body> and positioned from the trigger's own
         getBoundingClientRect (not a plain absolutely-positioned sibling) —
         a fixed-position child of .pv-table-shell's overflow-x-auto wrapper
         gets clipped the moment it opens past that box's edge, exactly the
         clipping problem AutoComplete.vue's dropdown and option tooltip
         solve the same way. Flips to stay on-screen on both axes so it
         never runs off a narrow/mobile viewport either. -->
    <Teleport to="body">
      <div v-if="pvTooltip.visible" class="pv-tooltip" :style="pvTooltip.style">
        <div class="pv-tooltip-row"><span>{{ $t('Payee/Staff') }}</span><strong>{{ pvTooltip.voucher.payee_name }}</strong></div>
        <div class="pv-tooltip-row"><span>{{ $t('Project') }}</span><strong>{{ pvTooltip.voucher.warehouse_name }}</strong></div>
        <div class="pv-tooltip-row"><span>{{ $t('Amount') }}</span><strong>{{ $money(pvTooltip.voucher.total_amount) }}</strong></div>
        <div class="pv-tooltip-row"><span>{{ $t('Status') }}</span><strong>{{ pvTooltip.voucher.display_status || pvTooltip.voucher.status }}</strong></div>
        <div v-if="pvTooltip.voucher.particulars_summary" class="pv-tooltip-particulars">
          <span>{{ $t('Particulars') }}</span>
          <div>{{ pvTooltip.voucher.particulars_summary }}</div>
        </div>
      </div>
    </Teleport>
  
    <modal :show="payOpen" max-width="lg" @close="payOpen = false">
      <div class="pv-paydlg">
        <header class="pv-paydlg__head"><icons name="cash" class="w-5 h-5" /><div><h3>{{ $t('Mark the checked vouchers as paid') }}</h3><p>{{ bulk.pay.length }} {{ $t('Authorised voucher(s)') }} · {{ $t('Total') }} {{ $money(bulkPayTotal) }}</p></div></header>
        <div class="pv-paydlg__body">
          <p class="pv-paydlg__note">{{ $t('All of them are marked paid together, with the same method and reference. Vouchers that cannot be paid (already paid, or a required signature is missing) are left as they are and named in the answer.') }}</p>
          <div class="lg-fields">
            <text-input :label="$t('Payment Reference (optional)')" v-model="payForm.payment_reference" />
            <text-input :label="$t('Payment Method (optional)')" v-model="payForm.payment_method" />
          </div>
          <ul class="pv-paydlg__list"><li v-for="v in bulk.pay" :key="v.id"><b>{{ v.pv_number }}</b><span>{{ v.payee_name || '-' }}</span><em>{{ $money(v.total_amount) }}</em></li></ul>
        </div>
        <footer class="pv-paydlg__foot">
          <button type="button" class="lg-btn lg-btn-soft" :disabled="paying" @click="payOpen = false">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-green" :disabled="paying" @click="submitPay"><icons name="tick" class="w-4 h-4" />{{ paying ? $t('Saving...') : $t('Mark') + ' ' + bulk.pay.length + ' ' + $t('as Paid') }}</button>
        </footer>
      </div>
    </modal>
  </admin-layout>
</template>

<script>
import pickBy from 'lodash/pickBy';
import throttle from 'lodash/throttle';
import mapValues from 'lodash/mapValues';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import TextInput from '@/Shared/TextInput.vue';
import Pagination from '@/Shared/Pagination.vue';
import Modal from '@/Jetstream/Modal.vue';
import { h } from 'vue';

const SortIcon = {
  props: { active: Boolean, direction: String },
  render() {
    const isDesc = !this.active || this.direction === 'desc';
    const path = isDesc
      ? 'M10 3a.75.75 0 01.75.75v10.638l3.96-4.158a.75.75 0 111.08 1.04l-5.25 5.5a.75.75 0 01-1.08 0l-5.25-5.5a.75.75 0 111.08-1.04l3.96 4.158V3.75A.75.75 0 0110 3z'
      : 'M10 17a.75.75 0 01-.75-.75V5.612L5.29 9.77a.75.75 0 01-1.08-1.04l5.25-5.5a.75.75 0 011.08 0l5.25 5.5a.75.75 0 01-1.08 1.04l-3.96-4.158V16.25A.75.75 0 0110 17z';
    return h('span', { class: ['pv-sort-icon', { 'pv-sort-icon-active': this.active }] }, [
      h('svg', { xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 20 20', fill: 'currentColor', class: 'w-3 h-3' }, [
        h('path', { 'fill-rule': 'evenodd', d: path, 'clip-rule': 'evenodd' }),
      ]),
    ]);
  },
};

export default {
  components: { AdminLayout, PageHeader, StatTiles, AutoComplete, TextInput, Pagination, SortIcon, Modal },

  props: {
    separation: { type: Boolean, default: false },
    canChangeRules: { type: Boolean, default: false },
    filters: Object,
    statuses: Array,
    warehouses: Array,
    // only sent to people who can create or edit vouchers; a read-only user filters by the search box instead
    contacts: { type: Array, default: () => [] },
    staffs: { type: Array, default: () => [] },
    types: Array,
    paymentVouchers: Object,
    cards: Object,
  },

  data() {
    return {
      selectedIds: [],
      seen: {},
      rulesOpen: false,
      rulesForm: this.$inertia.form({ separation: true, password: '' }),
      payOpen: false,
      paying: false,
      payForm: { payment_reference: '', payment_method: '' },
      pvTooltip: { visible: false, voucher: null, style: {} },
      form: {
        search: this.filters.search || '',
        status: this.filters.status || null,
        warehouse_id: this.filters.warehouse_id ? Number(this.filters.warehouse_id) : null,
        payee_contact_id: this.filters.payee_contact_id ? Number(this.filters.payee_contact_id) : null,
        staff_id: this.filters.staff_id ? Number(this.filters.staff_id) : null,
        type: this.filters.type || null,
        source: this.filters.source || null,
        batch_number: this.filters.batch_number || '',
        date_from: this.filters.date_from || null,
        date_to: this.filters.date_to || null,
        sort: this.filters.sort || null,
        direction: this.filters.direction || 'desc',
      },
    };
  },

  computed: {
    statTiles() {
      if (!this.cards) {
        return [];
      }

      const selecting = this.selectedIds.length > 0;

      return [
        { icon: 'doc-text', tone: 'slate', label: this.$t('Draft'), value: this.cards.draft, status: 'Draft', active: this.form.status === 'Draft' },
        { icon: 'clock', tone: 'orange', label: this.$t('Submitted'), value: this.cards.submitted, status: 'Submitted', active: this.form.status === 'Submitted' },
        { icon: 'tick', tone: 'violet', label: this.$t('Approved'), value: this.cards.approved, status: 'Approved', active: this.form.status === 'Approved' },
        { icon: 'lock-closed', tone: 'sky', label: this.$t('Authorised'), value: this.cards.authorised, status: 'Authorised', active: this.form.status === 'Authorised' },
        { icon: 'cash', tone: 'forest', label: this.$t('Paid'), value: this.cards.paid, status: 'Paid', active: this.form.status === 'Paid' },
        {
          icon: 'chart-bar',
          tone: 'pink',
          label: selecting ? this.$t('Selected') + ' (' + this.selectedIds.length + ')' : this.$t('Total Value'),
          value: this.$money(selecting ? this.selectedTotal : this.cards.total_value),
        },
      ];
    },
    statusOptions() {
      return this.statuses.map(s => ({ label: this.$t(s), value: s }));
    },
    warehouseSuggestions() {
      return this.warehouses.map(w => ({ id: w.id, name: w.name }));
    },
    contactSuggestions() {
      return this.contacts.map(c => ({ id: c.id, name: c.name }));
    },
    staffSuggestions() {
      return this.staffs.map(s => ({ id: s.id, name: `${s.name} (${s.staff_number})` }));
    },
    typeOptions() {
      return this.types.map(t => ({ label: t, value: t }));
    },
    queryString() {
      const query = pickBy(this.form);
      const qs = new URLSearchParams(query).toString();
      return qs ? '?' + qs : '';
    },
    // what the checked vouchers (also those on other pages) are waiting for
    bulk() {
      const out = { approve: [], authorise: [], pay: [] };
      const me = this.me;
      const bound = this.separation && !this.$super; // Super Admins are not bound by the rule
      this.selectedIds.forEach(id => {
        const v = this.seen[id];
        if (!v) return;
        // separation of duties: a person does not take two steps of the same voucher, so those are not counted for them
        if (v.status === 'Submitted') { if (!(bound && (v.prepared_by_ids || []).map(Number).includes(me))) out.approve.push(v); }
        else if (v.status === 'Approved') { if (!(bound && [Number(v.approved_by_user_id), ...(v.prepared_by_ids || []).map(Number)].includes(me))) out.authorise.push(v); }
        else if (v.status === 'Authorised' && !v.paid_at) { if (!(bound && Number(v.authorised_by_user_id) === me)) out.pay.push(v); }
      });
      return out;
    },
    me() { return this.$page.props.auth && this.$page.props.auth.user ? Number(this.$page.props.auth.user.id) : 0; },
    bulkPayTotal() { return this.bulk.pay.reduce((sum, v) => sum + (parseFloat(v.total_amount) || 0), 0); },
    selectedTotal() {
      return this.paymentVouchers.data
        .filter(v => this.selectedIds.includes(v.id))
        .reduce((sum, v) => sum + (parseFloat(v.total_amount) || 0), 0);
    },
    allVisibleSelected() {
      return this.paymentVouchers.data.length > 0 && this.paymentVouchers.data.every(v => this.selectedIds.includes(v.id));
    },
  },

  watch: {
    // remember the status of every voucher that was shown, so a checked voucher on another page still counts for the buttons
    paymentVouchers: {
      handler(list) { ((list && list.data) || []).forEach(r => { this.seen[r.id] = { id: r.id, pv_number: r.pv_number, payee_name: r.payee_name, total_amount: r.total_amount, status: r.status, paid_at: r.paid_at, prepared_by_ids: r.prepared_by_ids || [r.prepared_by_user_id], approved_by_user_id: r.approved_by_user_id, authorised_by_user_id: r.authorised_by_user_id }; }); },
      immediate: true,
    },
    form: {
      handler: throttle(function () {
        const query = pickBy(this.form);
        this.$inertia.visit(this.route('payment-vouchers.index', Object.keys(query).length ? query : { remember: 'forget' }), {
          preserveState: true,
          preserveScroll: true,
          replace: true,
        });
      }, 200),
      deep: true,
    },
  },

  methods: {
    reset() {
      this.form = mapValues(this.form, () => null);
      this.form.search = '';
      this.form.batch_number = '';
      this.form.direction = 'desc';
    },
    selectCard(status) {
      this.form.status = this.form.status === status ? null : status;
    },
    pickTile(item) {
      if (item.status) {
        this.selectCard(item.status);
      }
    },
    sortBy(column) {
      if (this.form.sort === column) {
        this.form.direction = this.form.direction === 'asc' ? 'desc' : 'asc';
      } else {
        this.form.sort = column;
        this.form.direction = 'asc';
      }
    },
    isSorted(column) {
      return this.form.sort === column;
    },
    goto(id) {
      this.$inertia.visit(route('payment-vouchers.show', id));
    },
    // Computed from the trigger's own rect (viewport coordinates, matching
    // the Teleported tooltip's `position: fixed`) rather than the CSS-only
    // `bottom: 100%` the markup used to use — that always opened upward
    // regardless of whether there was room, and never adapted to viewport
    // width. Flips both axes so it always fits on screen, mobile included.
    showPvTooltip(event, voucher) {
      const rect = event.currentTarget.getBoundingClientRect();
      const gap = 8;
      const tooltipWidth = Math.min(320, window.innerWidth - 24);
      const openUp = rect.top > 220 || window.innerHeight - rect.bottom < 180;
      const style = {
        left: Math.max(12, Math.min(rect.left, window.innerWidth - tooltipWidth - 12)) + 'px',
        width: tooltipWidth + 'px',
        ...(openUp ? { bottom: window.innerHeight - rect.top + gap + 'px' } : { top: rect.bottom + gap + 'px' }),
      };
      this.pvTooltip = { visible: true, voucher, style };
    },
    hidePvTooltip() {
      this.pvTooltip.visible = false;
    },
    toggleSelected(id) {
      const index = this.selectedIds.indexOf(id);
      if (index === -1) {
        this.selectedIds.push(id);
      } else {
        this.selectedIds.splice(index, 1);
      }
    },
    toggleSelectAll() {
      if (this.allVisibleSelected) {
        const visibleIds = this.paymentVouchers.data.map(v => v.id);
        this.selectedIds = this.selectedIds.filter(id => !visibleIds.includes(id));
      } else {
        const visibleIds = this.paymentVouchers.data.map(v => v.id);
        this.selectedIds = [...new Set([...this.selectedIds, ...visibleIds])];
      }
    },
    openRules() { this.rulesForm.separation = this.separation; this.rulesForm.password = ''; this.rulesForm.clearErrors(); this.rulesOpen = true; },
    saveRules() { this.rulesForm.put(route('payment-vouchers.rules'), { preserveScroll: true, onSuccess: () => { this.rulesOpen = false; this.rulesForm.password = ''; } }); },
    openPay() {
      this.payForm = { payment_reference: '', payment_method: '' };
      this.payOpen = true;
    },
    submitPay() {
      const ids = this.bulk.pay.map(v => v.id);
      if (!ids.length || this.paying) return;
      this.paying = true;
      this.$inertia.post(route('payment-vouchers.bulk-mark-paid'), { ids, ...this.payForm }, {
        preserveScroll: true,
        onSuccess: () => { this.selectedIds = []; this.payOpen = false; },
        onFinish: () => { this.paying = false; },
      });
    },
    async bulkApprove() {
      const ids = this.bulk.approve.map(v => v.id);
      if (!ids.length || !await this.$confirm(this.$t('Approve') + ' ' + ids.length + ' ' + this.$t('selected payment voucher(s)?'))) {
        return;
      }
      this.$inertia.post(route('payment-vouchers.bulk-approve'), { ids }, {
        onSuccess: () => { this.selectedIds = []; },
      });
    },
    async bulkApproveAndAuthorise() {
      const ids = [...this.bulk.approve, ...this.bulk.authorise].map(v => v.id);
      if (!ids.length || !await this.$confirm(this.$t('Approve and authorise') + ' ' + ids.length + ' ' + this.$t('selected payment voucher(s)?'))) {
        return;
      }
      this.$inertia.post(route('payment-vouchers.bulk-approve-authorise'), { ids }, {
        onSuccess: () => { this.selectedIds = []; },
      });
    },
    statusBadgeClass(status) {
      const map = {
        Draft: 'pv-badge-slate',
        Submitted: 'pv-badge-amber',
        Approved: 'pv-badge-blue',
        Authorised: 'pv-badge-emerald',
        Paid: 'pv-badge-blue',
        Rejected: 'pv-badge-rose',
        Cancelled: 'pv-badge-rose',
      };
      return map[status] || 'pv-badge-slate';
    },
    // the same colour and icon per status as on the voucher page
    statusKey(status) { return String(status || 'Draft').toLowerCase(); },
    statusIcon(status) { return { Draft: 'doc-text', Submitted: 'clock', Approved: 'tick', Authorised: 'lock-closed', Paid: 'cash', Rejected: 'x-circle', Cancelled: 'cross' }[status] || 'doc-text'; },
    statusChip(status) {
      return (
        {
          Draft: 'lg-chip-slate',
          Submitted: 'lg-chip-amber',
          Approved: 'lg-chip-blue',
          Authorised: 'lg-chip-green',
          Paid: 'lg-chip-indigo',
          Rejected: 'lg-chip-red',
          Cancelled: 'lg-chip-red',
        }[status] || 'lg-chip-slate'
      );
    },
  },
};
</script>

<style scoped>
.pv-filters { position: relative; z-index: 20; }
/* status of a voucher in the table: one colour and icon per status (the same as on the voucher page); the batch label is teal so it never looks like a status */
.pv-status { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.22rem 0.6rem 0.22rem 0.3rem; border-radius: 999px; color: #fff; font-size: 0.6rem; font-weight: 800; line-height: 1.2; white-space: nowrap; background: #64748b; box-shadow: 0 8px 14px -10px var(--pv-glow, rgba(100, 116, 139, 0.7)); }
.pv-status__ico { width: 0.71rem; height: 0.71rem; flex: none; padding: 0.11rem; box-sizing: content-box; border-radius: 50%; background: rgba(255, 255, 255, 0.24); }
.pv-st-draft { background: linear-gradient(135deg, #64748b, #475569); --pv-glow: rgba(71, 85, 105, 0.7); }
.pv-st-submitted { background: linear-gradient(135deg, #f97316, #c2410c); --pv-glow: rgba(234, 88, 12, 0.7); }
.pv-st-approved { background: linear-gradient(135deg, #8b5cf6, #5b21b6); --pv-glow: rgba(109, 40, 217, 0.7); }
.pv-st-authorised { background: linear-gradient(135deg, #0ea5e9, #075985); --pv-glow: rgba(3, 105, 161, 0.7); }
.pv-st-paid { background: linear-gradient(135deg, #15803d, #14532d); --pv-glow: rgba(20, 83, 45, 0.75); }
.pv-st-rejected { background: linear-gradient(135deg, #9f1239, #6b0f1a); --pv-glow: rgba(127, 29, 29, 0.75); }
.pv-st-cancelled { background: linear-gradient(135deg, #78716c, #44403c); --pv-glow: rgba(68, 64, 60, 0.7); }
.pv-batch { display: inline-flex; align-items: center; gap: 0.26rem; padding: 0.19rem 0.52rem; border-radius: 8px; font-size: 0.57rem; font-weight: 800; color: #0f766e; background: rgba(20, 184, 166, 0.12); box-shadow: inset 0 0 0 1.1px rgba(20, 184, 166, 0.55); text-decoration: none; white-space: nowrap; }
.pv-batch:hover { background: rgba(20, 184, 166, 0.22); } .pv-batch:focus-visible { outline: 2px solid #0f766e; outline-offset: 2px; }
.pv-batch__ico { width: 0.65rem; height: 0.65rem; flex: none; }
html.dark .pv-batch { color: #5eead4; }
.pv-rule { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 14px; } @media (max-width: 720px) { .pv-rule > .lg-btn { width: 100%; justify-content: center; } }
.pv-opt { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border-radius: 14px; margin-bottom: 8px; cursor: pointer; box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.4); } .pv-opt.is-on { box-shadow: inset 0 0 0 2px #10b981; background: rgba(16, 185, 129, 0.08); } .pv-opt span { display: grid; gap: 2px; } .pv-opt small { color: var(--lg-mute, #64748b); }
.pv-bulk-bar {
  background: rgba(190, 24, 93, 0.06); border: 1px solid rgba(190, 24, 93, 0.2); border-radius: 1rem; padding: 0.7rem 1rem;
}
.pv-bulk-count { color: var(--lg-ink); } .pv-bulk-hint { font-weight: 600; color: var(--lg-mute, #64748b); }
/* the dialog is teleported out of the page, so it carries its own colours */
.pv-paydlg { --lg-card: #ffffff; --lg-line: #e6ebf3; --lg-soft: #f6f8fc; --lg-ink: #0f172a; --lg-mute: #64748b; background: var(--lg-card); color: var(--lg-ink); border-radius: 0.5rem; overflow: hidden; }
html.dark .pv-paydlg { --lg-card: #1e293b; --lg-line: rgba(255, 255, 255, 0.1); --lg-soft: rgba(255, 255, 255, 0.05); --lg-ink: #f1f5f9; --lg-mute: #94a3b8; }
.pv-paydlg__head { display: flex; align-items: center; gap: 0.8rem; padding: 0.9rem 1.1rem; color: #fff; background: linear-gradient(135deg, #16a34a, #166534); } .pv-paydlg__head h3 { margin: 0; font-size: 1rem; font-weight: 800; } .pv-paydlg__head p { margin: 0.1rem 0 0; font-size: 0.8rem; opacity: 0.92; }
.pv-paydlg__body { display: grid; gap: 0.8rem; padding: 1rem 1.1rem; max-height: 65vh; overflow-y: auto; } .pv-paydlg__note { margin: 0; font-size: 0.82rem; color: var(--lg-mute); line-height: 1.45; }
.pv-paydlg__list { list-style: none; margin: 0; padding: 0; display: grid; gap: 4px; max-height: 190px; overflow-y: auto; border: 1px solid var(--lg-line); border-radius: 10px; padding: 6px; }
.pv-paydlg__list li { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; gap: 10px; align-items: baseline; font-size: 0.8rem; padding: 3px 6px; border-radius: 6px; } .pv-paydlg__list li:nth-child(odd) { background: var(--lg-soft); } .pv-paydlg__list li span { overflow-wrap: anywhere; color: var(--lg-mute); } .pv-paydlg__list li em { font-style: normal; font-weight: 700; }
.pv-paydlg__foot { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 0.6rem; padding: 0.8rem 1.1rem 1rem; border-top: 1px solid var(--lg-line); }
@media (max-width: 480px) { .pv-paydlg__foot .lg-btn { flex: 1 1 100%; justify-content: center; } .pv-paydlg__list li { grid-template-columns: minmax(0, 1fr) auto; } .pv-paydlg__list li span { grid-column: 1 / -1; order: 3; } }
.pv-checkbox { width: 1rem; height: 1rem; border-radius: 0.3rem; border: 1px solid #cbd5e1; accent-color: #be185d; cursor: pointer; }
.pv-sort-icon { display: inline-flex; color: #cbd5e1; transition: color 0.15s ease; }
.pv-sort-icon-active { color: #be185d; }
.lg-table th.text-right .lg-sort { justify-content: flex-end; }
.pv-tooltip-wrap { position: relative; display: inline-block; cursor: pointer; border-bottom: 1px dashed #cbd5e1; }
html.dark .pv-bulk-bar { background: rgba(190, 24, 93, 0.12); border-color: rgba(190, 24, 93, 0.3); }

/* JS-positioned tooltip, Teleported to <body> (see showPvTooltip/hidePvTooltip)
   so it escapes the table wrapper's overflow clipping box entirely.
   `position: fixed` matches the viewport-relative coordinates from
   getBoundingClientRect(), and the width is clamped to the viewport in
   showPvTooltip so it never overflows a narrow/mobile screen. */
.pv-tooltip {
  position: fixed; z-index: 9999; min-width: 220px; max-width: calc(100vw - 24px);
  background: linear-gradient(145deg, rgba(15, 23, 42, 0.97), rgba(30, 41, 59, 0.94)); color: #f8fafc;
  border-radius: 0.65rem; padding: 0.6rem 0.75rem; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.25);
  pointer-events: none;
}
.pv-tooltip-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 0.72rem; font-weight: 600; padding: 2px 0; white-space: nowrap; }
.pv-tooltip-row span { color: #94a3b8; text-transform: uppercase; letter-spacing: 0.02em; font-size: 0.65rem; }
.pv-tooltip-row strong { color: #f8fafc; font-weight: 700; }
.pv-tooltip-particulars { margin-top: 4px; padding-top: 4px; border-top: 1px solid rgba(255, 255, 255, 0.12); }
.pv-tooltip-particulars span { display: block; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.02em; font-size: 0.65rem; font-weight: 600; margin-bottom: 2px; }
.pv-tooltip-particulars div { font-size: 0.72rem; font-weight: 600; color: #e2e8f0; white-space: normal; line-height: 1.4; }
@media (max-width: 767.98px) {
  .lg-table td .pv-tooltip-wrap { border-bottom: 0; }
}
</style>

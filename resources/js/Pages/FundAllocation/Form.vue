<template>
  <admin-layout :title="isEdit ? $t('Edit Allocation Sheet') : $t('New Allocation Sheet')">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="cash"
        :title="isEdit ? $t('Edit Allocation Sheet') : $t('New Allocation Sheet')"
        :subtitle="$t('List the day\'s available balances, then what will be paid out and how.')"
      >
        <span v-if="isEdit" class="lg-chip lg-chip-blue" style="text-transform: none">{{ allocation.reference }}</span>
        <Link :href="route('fund-allocations.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Funds Allocation') }}</Link>
      </page-header>

      <div v-if="isPost" class="lg-note lg-note-amber mb-4">
        <icons name="exclamation" class="w-4 h-4" />
        <span>{{ $t('This sheet is') }} <b>{{ $t(allocation.status) }}</b>. {{ $t('You are editing it as Super Admin. Payments already ticked are kept; the status is recalculated when you save.') }}</span>
      </div>
      <div v-if="isEdit && allocation.status === 'Rejected' && allocation.rejection_reason" class="lg-note lg-note-red mb-4">
        <icons name="exclamation" class="w-4 h-4" />
        <span><b>{{ $t('Rejected') }}:</b> {{ allocation.rejection_reason }}</span>
      </div>
      <div v-if="form.errors.submit" class="lg-note lg-note-red mb-4 fx-prewrap">
        <icons name="exclamation" class="w-4 h-4" />
        <span><b>{{ $t('This sheet was saved but could not be submitted') }}:</b><br />{{ form.errors.submit }}</span>
      </div>
      <div v-if="form.errors.workflow" class="lg-note lg-note-red mb-4">
        <icons name="exclamation" class="w-4 h-4" /><span>{{ form.errors.workflow }}</span>
      </div>
      <div v-else-if="otherErrorCount" class="lg-note lg-note-amber mb-4">
        <icons name="exclamation" class="w-4 h-4" />
        <span>{{ $t('Please fix the highlighted fields') }} ({{ otherErrorCount }}).</span>
      </div>

      <!-- Always-visible summary -->
      <div class="fx-summary" :class="{ 'is-over': balanceAfter < 0 }">
        <div class="fx-sum-card fx-c-emerald">
          <span class="fx-sum-ico"><icons name="cash" class="w-5 h-5" /></span>
          <div class="fx-sum-txt">
            <span class="fx-sum-label">{{ $t('Available') }}</span>
            <span class="fx-sum-value">{{ fmt(totalAvailable) }}<i class="fx-cur">TZS</i></span>
          </div>
        </div>
        <div class="fx-sum-card fx-c-sky">
          <span class="fx-sum-ico"><icons name="doc" class="w-5 h-5" /></span>
          <div class="fx-sum-txt">
            <span class="fx-sum-label">{{ $t('Total Request') }}</span>
            <span class="fx-sum-value">{{ fmt(totalRequest) }}<i class="fx-cur">TZS</i></span>
          </div>
        </div>
        <div class="fx-sum-card fx-c-violet">
          <span class="fx-sum-ico"><icons name="calculator" class="w-5 h-5" /></span>
          <div class="fx-sum-txt">
            <span class="fx-sum-label">{{ $t('Total Allocation') }}</span>
            <span class="fx-sum-value">{{ fmt(totalAllocation) }}<i class="fx-cur">TZS</i></span>
          </div>
        </div>
        <div class="fx-sum-card" :class="balanceAfter < 0 ? 'fx-c-rose' : 'fx-c-teal'">
          <span class="fx-sum-ico"><icons :name="balanceAfter < 0 ? 'exclamation' : 'chart-bar'" class="w-5 h-5" /></span>
          <div class="fx-sum-txt">
            <span class="fx-sum-label">{{ $t('Balance after') }}</span>
            <span class="fx-sum-value">{{ fmt(balanceAfter) }}<i class="fx-cur">TZS</i></span>
          </div>
        </div>
        <div v-if="methodTotals.length" class="fx-sum-card fx-c-amber fx-sum-methods">
          <span class="fx-sum-ico"><icons name="send" class="w-5 h-5" /></span>
          <div class="fx-sum-txt">
            <span class="fx-sum-label">{{ $t('By payment method') }}</span>
            <span class="fx-sum-mchips">
              <span v-for="m in methodTotals" :key="m.id" class="fx-mchip" :class="{ 'is-usd': m.usd }"><b>{{ m.short }}</b> <template v-if="m.usd">{{ fmt(m.usdTotal) }}<i class="fx-cur">USD</i></template><template v-else>{{ fmt(m.total) }}<i class="fx-cur">TZS</i></template></span>
            </span>
          </div>
        </div>
        <div v-if="unbalancedCount" class="fx-sum-card fx-c-rose">
          <span class="fx-sum-ico"><icons name="exclamation" class="w-5 h-5" /></span>
          <div class="fx-sum-txt">
            <span class="fx-sum-label">{{ $t('Check') }}</span>
            <span class="fx-sum-value fx-sum-small">{{ unbalancedCount }} {{ $t('item(s) not split correctly') }}</span>
          </div>
        </div>
      </div>

      <form @submit.prevent="save(false)">
        <!-- Header -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="calendar" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Sheet details') }}</h2>
              <p class="lg-card-sub">{{ $t('Date, title and notes') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <date-picker v-model="form.allocation_date" :label="$t('Date')" :clearable="false" :error="form.errors.allocation_date" />
            </div>
            <div class="lg-span-2">
              <text-input v-model="form.title" :label="$t('Title')" :placeholder="$t('e.g. Funds allocation in progress')" :error="form.errors.title" />
            </div>
            <div class="lg-span-all">
              <textarea-input v-model="form.notes" :label="$t('Notes')" rows="2" :error="form.errors.notes" />
            </div>
          </div>
        </section>

        <!-- Available balances -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Available balances') }}</h2>
              <p class="lg-card-sub">{{ $t('Cash and bank balances on hand for this date. Foreign currency needs an exchange rate (1 unit in TZS).') }}</p>
            </div>
            <span class="lg-chip lg-chip-green" style="text-transform: none">{{ $t('Total') }}: {{ fmt(totalAvailable) }} TZS</span>
          </header>

          <div class="fx-bal-head">
            <span>{{ $t('Account') }}</span><span>{{ $t('Label') }}</span><span>{{ $t('Cur.') }}</span><span class="r">{{ $t('Amount') }}</span><span class="r">{{ $t('Rate to TZS') }}</span><span class="r">{{ $t('Value in TZS') }}</span><span></span>
          </div>
          <div v-for="(b, i) in form.balances" :key="b._key" class="fx-bal-row">
            <div class="fx-cell">
              <span class="fx-mlabel">{{ $t('Account') }}</span>
              <auto-complete
                :id="'fx-acc-' + b._key"
                :suggestions="accountOptions"
                :model-value="b.fund_account_id"
                :placeholder="$t('Choose account')"
                clearable
                @update:modelValue="v => onAccount(b, v)"
              />
            </div>
            <div class="fx-cell">
              <span class="fx-mlabel">{{ $t('Label') }}</span>
              <input v-model="b.label" class="fx-input" :class="{ 'is-error': err('balances.' + i + '.label') }" :placeholder="$t('e.g. Cash TZS')" />
              <div v-if="err('balances.' + i + '.label')" class="fx-err">{{ err('balances.' + i + '.label') }}</div>
            </div>
            <div class="fx-cell">
              <span class="fx-mlabel">{{ $t('Currency') }}</span>
              <currency-select :id="'fx-cur-' + b._key" v-model="b.currency" @update:modelValue="v => onCurrency(b, v)" />
            </div>
            <div class="fx-cell">
              <span class="fx-mlabel">{{ $t('Amount') }}</span>
              <div class="fx-unitwrap"><amount-input v-model="b.amount" :error="!!err('balances.' + i + '.amount')" /><i class="fx-unit">{{ b.currency || 'TZS' }}</i></div>
            </div>
            <div class="fx-cell">
              <span class="fx-mlabel">{{ $t('Rate to TZS') }}</span>
              <amount-input v-if="!isTzs(b)" v-model="b.exchange_rate" :decimals="6" :placeholder="$t('Required')" :error="!!err('balances.' + i + '.exchange_rate')" />
              <span v-else class="fx-dash">1</span>
              <div v-if="err('balances.' + i + '.exchange_rate')" class="fx-err">{{ err('balances.' + i + '.exchange_rate') }}</div>
            </div>
            <div class="fx-cell fx-tzs">
              <span class="fx-mlabel">{{ $t('Value in TZS') }}</span>
              <span class="fx-tzs-val">{{ fmt(balanceTzs(b)) }}<i class="fx-cur">TZS</i></span>
            </div>
            <div class="fx-cell fx-rm">
              <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="removeBalance(i)"><icons name="trash" /></button>
            </div>
          </div>
          <div v-if="!form.balances.length" class="fx-empty">{{ $t('No balances yet. Add the cash and bank balances available today.') }}</div>
          <div class="mt-3">
            <button type="button" class="lg-btn lg-btn-soft" @click="addBalance"><icons name="plus" class="w-4 h-4" />{{ $t('Add balance') }}</button>
            <span v-if="!accounts.length" class="fx-hint">{{ $t('No accounts are set up yet. You can still type a label and currency.') }}</span>
          </div>
        </section>

        <!-- Items by category -->
        <section v-for="sec in sections" :key="sec.id" class="lg-card fx-cat">
          <header class="lg-card-head fx-cat-head" @click="toggle(sec.id)">
            <span class="lg-card-ico lg-ico-indigo fx-letter">{{ sec.letter }}</span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ sec.name }}</h2>
              <p class="lg-card-sub">{{ sec.rows.length }} {{ $t('items') }}</p>
            </div>
            <div class="fx-cat-tot">
              <span><small>{{ $t('Request') }}</small>{{ fmt(sec.request) }}<i class="fx-cur">TZS</i></span>
              <span><small>{{ $t('Allocation') }}</small><b>{{ fmt(sec.allocation) }}<i class="fx-cur">TZS</i></b></span>
            </div>
            <icons name="chevron-down" class="w-5 h-5 fx-chev" :class="{ 'is-closed': collapsed[sec.id] }" />
          </header>

          <div v-show="!collapsed[sec.id]">
            <div v-for="row in sec.rows" :key="row.item._key" class="fx-item" :class="{ 'is-bad': isBad(row.item) }">
              <div class="fx-item-main">
                <span class="fx-sn">{{ row.n }}</span>
                <div class="fx-cell fx-desc">
                  <span class="fx-mlabel">{{ $t('Description') }}</span>
                  <input v-model="row.item.description" class="fx-input" :class="{ 'is-error': err(ip(row.idx, 'description')) }" :placeholder="$t('What is being paid for')" />
                  <div v-if="err(ip(row.idx, 'description'))" class="fx-err">{{ err(ip(row.idx, 'description')) }}</div>
                </div>
                <div class="fx-cell fx-ben">
                  <span class="fx-mlabel">{{ $t('Beneficiary') }}</span>
                  <beneficiary-select
                    :contacts="contacts"
                    :users="users"
                    :projects="projects"
                    :warehouse-id="row.item.beneficiary_warehouse_id"
                    :contact-id="row.item.contact_id"
                    :user-id="row.item.beneficiary_user_id"
                    :legacy="row.item.beneficiary"
                    :error="!!err(ip(row.idx, 'beneficiary'))"
                    :placeholder="$t('Search contacts, users or projects')"
                    @select="v => onBeneficiary(row.item, v)"
                  />
                  <div v-if="err(ip(row.idx, 'beneficiary'))" class="fx-err">{{ err(ip(row.idx, 'beneficiary')) }}</div>
                </div>
                <div class="fx-cell fx-amt">
                  <span class="fx-mlabel">{{ $t('Request') }}</span>
                  <div class="fx-unitwrap"><amount-input v-model="row.item.request_amount" :error="!!err(ip(row.idx, 'request_amount'))" /><i class="fx-unit">TZS</i></div>
                </div>
                <div class="fx-cell fx-amt">
                  <span class="fx-mlabel">{{ $t('Allocation') }}</span>
                  <div class="fx-unitwrap"><amount-input :model-value="row.item.allocated_amount" :error="!!err(ip(row.idx, 'allocated_amount'))" @update:modelValue="v => onAllocation(row.item, v)" /><i class="fx-unit">TZS</i></div>
                </div>
                <div class="fx-cell fx-rm">
                  <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove item')" :aria-label="$t('Remove item')" @click="removeItem(row.idx)"><icons name="trash" /></button>
                </div>
              </div>

              <div class="fx-split">
                <span class="fx-split-label">{{ $t('Paid by') }}</span>
                <div v-for="(l, li) in row.item.lines" :key="l._key" class="fx-pchip" :class="{ 'is-usd': isUsd(l) }">
                  <span v-if="isUsd(l)" class="fx-usdtag"><i class="fx-usd-sign">$</i>USD</span>
                  <select v-else v-model="l.fund_payment_method_id" class="fx-sel" :disabled="l.is_paid" :aria-label="$t('Payment method')">
                    <option :value="null" disabled>{{ $t('Method') }}</option>
                    <option v-for="m in tzsMethods" :key="m.id" :value="m.id">{{ m.short_label }}</option>
                  </select>
                  <amount-input v-model="l.amount" :disabled="l.is_paid" /><i class="fx-unit-inline">TZS</i>
                  <template v-if="isUsd(l)">
                    <span class="fx-rate-at">@</span>
                    <amount-input class="fx-rate-in" :model-value="l.exchange_rate" :decimals="4" :disabled="l.is_paid" :error="!l.is_paid && !(num(l.exchange_rate) > 0)" :placeholder="$t('Rate')" :aria-label="$t('Exchange rate (TZS per 1 USD)')" @update:modelValue="v => onRate(l, v)" /><i class="fx-unit-inline">TZS/$</i>
                    <b class="fx-usd-eq" :class="{ 'is-missing': usdOf(l) === null }">= {{ usdOf(l) === null ? '?' : fmt(usdOf(l)) }}<i class="fx-cur">USD</i></b>
                  </template>
                  <button v-if="usdMethod && !l.is_paid" type="button" class="fx-usd-toggle lg-tip" :class="{ on: isUsd(l) }" :data-tip="isUsd(l) ? $t('Pay this part in TZS instead') : $t('Pay this part in USD (asks for the exchange rate)')" :aria-label="$t('Pay in USD')" @click="toggleUsd(row.item, l)"><i class="fx-usd-sign">$</i></button>
                  <span v-if="l.is_paid" class="fx-paidlock lg-tip" :data-tip="$t('Already paid - un-tick it on the sheet page to change it')"><icons name="lock-closed" class="w-3.5 h-3.5" /></span>
                  <button v-else type="button" class="fx-x" :aria-label="$t('Remove')" @click="row.item.lines.splice(li, 1)"><icons name="cross" class="w-3.5 h-3.5" /></button>
                </div>
                <button type="button" class="fx-addsplit" @click="addSplit(row.item)"><icons name="plus" class="w-3.5 h-3.5" />{{ $t('Split') }}</button>
                <span class="fx-quick-label">{{ $t('All on') }}:</span>
                <button v-for="m in tzsMethods" :key="m.id" type="button" class="fx-quick" :disabled="row.item.lines.some(l => l.is_paid)" @click="allIn(row.item, m)">{{ m.short_label }}</button>
                <button v-if="usdMethod" type="button" class="fx-quick fx-quick-usd lg-tip" :data-tip="$t('Pay the whole item in USD - you will be asked for the exchange rate')" :disabled="row.item.lines.some(l => l.is_paid)" @click="allIn(row.item, usdMethod)"><i class="fx-usd-sign">$</i>USD</button>

                <span class="fx-grow"></span>
                <span v-if="itemState(row.item) === 'ok'" class="fx-badge ok"><icons name="tick" class="w-3.5 h-3.5" />{{ $t('Split matches') }}</span>
                <span v-else-if="itemState(row.item) === 'warn'" class="fx-badge warn"><icons name="exclamation" class="w-3.5 h-3.5" /><template v-if="needsRate(row.item) && Math.abs(diff(row.item)) < 0.01">{{ $t('Enter the USD exchange rate') }}</template><template v-else>{{ $t('diff') }} {{ fmt(Math.abs(diff(row.item))) }}<i class="fx-cur">TZS</i></template></span>
                <button type="button" class="fx-more" @click="row.item._open = !row.item._open">
                  <icons name="link" class="w-3.5 h-3.5" />{{ row.item._open ? $t('Hide links') : $t('Links & notes') }}
                  <span v-if="!row.item._open && linkCount(row.item)" class="fx-count">{{ linkCount(row.item) }}</span>
                </button>
              </div>
              <div v-if="err(ip(row.idx, 'lines'))" class="fx-err">{{ err(ip(row.idx, 'lines')) }}</div>

              <div v-if="row.item._open" class="fx-details lg-fields">
                <auto-complete
                  :id="'fx-wh-' + row.item._key"
                  :label="$t('Project / Warehouse')"
                  :suggestions="warehouseOptions"
                  :model-value="row.item.warehouse_id"
                  clearable
                  @update:modelValue="v => (row.item.warehouse_id = v || null)"
                />
                <div class="lg-span-all">
                  <label class="fx-lbl">{{ $t('Item notes') }}</label>
                  <input v-model="row.item.notes" class="fx-input" :placeholder="$t('Optional')" />
                </div>
              </div>
              <div v-else-if="linkCount(row.item)" class="fx-links">
                <span v-if="row.item.warehouse_id" class="lg-chip lg-chip-blue"><icons name="building" class="w-3 h-3" />{{ nameOf(warehouses, row.item.warehouse_id) }}</span>
              </div>
            </div>

            <div class="fx-cat-foot">
              <button type="button" class="lg-btn lg-btn-soft" @click="addItem(sec.id)"><icons name="plus" class="w-4 h-4" />{{ $t('Add item') }}</button>
              <div class="fx-cat-sub">
                <span>{{ $t('Subtotal') }} {{ sec.name }}</span>
                <span class="fx-sub-n">{{ fmt(sec.request) }}<i class="fx-cur">TZS</i></span>
                <span class="fx-sub-n"><b>{{ fmt(sec.allocation) }}<i class="fx-cur">TZS</i></b></span>
              </div>
            </div>
          </div>
        </section>

        <section class="lg-card fx-addcat">
          <div class="fx-addcat-row">
            <div class="fx-addcat-head">
              <span class="fx-addcat-ico"><icons name="collection" class="w-6 h-6" /></span>
              <div>
              <h2 class="lg-card-title fx-addcat-title">{{ $t('Add a category section') }}</h2>
              <p class="lg-card-sub">{{ availableCategories.length ? $t('Pick a category to start adding items under it.') : $t('Every category is already on this sheet.') }}</p>
              </div>
            </div>
            <div v-if="availableCategories.length" class="fx-addcat-pick">
              <auto-complete
                id="fx-pick-cat"
                class="fx-pick-ac"
                :suggestions="categoryOptions"
                :model-value="pickCat"
                :placeholder="$t('Search or choose a category')"
                clearable
                @update:modelValue="v => (pickCat = v || null)"
              />
              <button type="button" class="lg-btn lg-btn-soft" :disabled="!pickCat" @click="addCategory"><icons name="plus" class="w-4 h-4" />{{ $t('Add section') }}</button>
            </div>
          </div>
          <div v-if="!categories.length" class="lg-note lg-note-amber mt-3"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t('No categories are set up yet. Add them in Funds Allocation settings.') }}</span></div>
        </section>

        <!-- Totals by method (bottom, like the Excel sheet) -->
        <section v-if="methodTotals.length" class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="calculator" class="w-5 h-5" /></span>
            <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Totals by payment method') }}</h2></div>
          </header>
          <div class="fx-method-grid">
            <div v-for="m in methodTotals" :key="m.id" class="fx-method-tile" :class="{ 'is-usd': m.usd }"><span>{{ m.name }}</span><b v-if="m.usd">{{ fmt(m.usdTotal) }}<i class="fx-cur">USD</i></b><b v-else>{{ fmt(m.total) }}<i class="fx-cur">TZS</i></b><small v-if="m.usd">{{ fmt(m.total) }} TZS {{ $t('allocated') }}</small></div>
          </div>
        </section>

        <div class="lg-actionbar">
          <span class="lg-actionbar-note" :class="{ 'fx-neg': balanceAfter < 0 }">
            <span class="fx-ab-label">{{ $t('Balance after') }}:</span>
            <span class="fx-ab-value">{{ fmt(balanceAfter) }}<i class="fx-cur">TZS</i></span>
            <template v-if="balanceAfter < 0"> &middot; {{ $t('Overspent') }}</template>
          </span>
          <div class="lg-actionbar-btns">
            <Link :href="isEdit ? route('fund-allocations.show', allocation.id) : route('fund-allocations.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" class="lg-btn lg-btn-dark" :disabled="form.processing"><icons name="doc" class="w-4 h-4" />{{ isPost ? $t('Save changes') : $t('Save Draft') }}</button>
            <button v-if="!isPost" type="button" class="lg-btn lg-btn-primary" :disabled="form.processing" @click="save(true)"><icons name="send" class="w-4 h-4" />{{ $t('Save & Submit for Approval') }}</button>
          </div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TextInput from '@/Shared/TextInput.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import AmountInput from './AmountInput.vue';
import CurrencySelect from './CurrencySelect.vue';
import BeneficiarySelect from './BeneficiarySelect.vue';
import { fmt, round2, num } from './fundFormat';

let uid = 0;
const key = () => 'k' + ++uid;

export default {
  components: { AdminLayout, PageHeader, TextInput, TextareaInput, DatePicker, AutoComplete, AmountInput, CurrencySelect, BeneficiarySelect },

  props: {
    allocation: { type: Object, default: null },
    accounts: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    methods: { type: Array, default: () => [] },
    contacts: { type: Array, default: () => [] },
    staffs: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    defaults: { type: Object, default: () => ({}) },
  },

  data() {
    return {
      form: this.buildForm(),
      collapsed: {},
      pickCat: null,
      sentMap: [],
      lastRate: null, // the USD rate last typed, offered to the next USD line
      // Large lists: frozen so the autocomplete's deep watcher does not walk thousands of rows.
      contactOptions: Object.freeze(this.contacts.map(c => Object.freeze({ id: c.id, label: c.name }))),
      staffOptions: Object.freeze(this.staffs.map(s => Object.freeze({ id: s.id, label: s.name + (s.staff_number ? ' (' + s.staff_number + ')' : '') }))),
      warehouseOptions: Object.freeze(this.warehouses.map(w => Object.freeze({ id: w.id, label: w.name }))),
    };
  },

  computed: {
    isEdit() {
      return !!this.allocation;
    },
    // A Super Admin editing a sheet that is already approved / paid / cleared: no resubmission.
    isPost() {
      return !!this.allocation && !['Draft', 'Rejected'].includes(this.allocation.status);
    },
    accountOptions() {
      return this.accounts.map(a => ({ id: a.id, label: this.accountLabel(a) }));
    },
    totalAvailable() {
      return round2(this.form.balances.reduce((s, b) => s + this.balanceTzs(b), 0));
    },
    totalRequest() {
      return round2(this.form.items.reduce((s, i) => s + num(i.request_amount), 0));
    },
    totalAllocation() {
      return round2(this.form.items.reduce((s, i) => s + num(i.allocated_amount), 0));
    },
    balanceAfter() {
      return round2(this.totalAvailable - this.totalAllocation);
    },
    methodTotals() {
      const totals = {};
      this.form.items.forEach(i =>
        i.lines.forEach(l => {
          if (l.fund_payment_method_id) totals[l.fund_payment_method_id] = (totals[l.fund_payment_method_id] || 0) + num(l.amount);
        })
      );
      const usdTotals = {};
      this.form.items.forEach(i =>
        i.lines.forEach(l => {
          const u = this.usdOf(l);
          if (u !== null) usdTotals[l.fund_payment_method_id] = (usdTotals[l.fund_payment_method_id] || 0) + u;
        })
      );
      return this.methods
        .filter(m => totals[m.id])
        .map(m => ({ id: m.id, name: m.name, short: m.short_label, usd: m.currency === 'USD', total: round2(totals[m.id]), usdTotal: round2(usdTotals[m.id] || 0) }));
    },
    tzsMethods() {
      return this.methods.filter(m => m.currency !== 'USD');
    },
    usdMethod() {
      return this.methods.find(m => m.currency === 'USD') || null;
    },
    unbalancedCount() {
      return this.form.items.filter(i => this.itemState(i) === 'warn').length;
    },
    otherErrorCount() {
      return Object.keys(this.form.errors).filter(k => k !== 'submit' && k !== 'workflow').length;
    },
    sections() {
      const groups = {};
      this.form.items.forEach((item, idx) => {
        (groups[item.fund_category_id] = groups[item.fund_category_id] || []).push({ item, idx });
      });
      const known = new Set(this.categories.map(c => c.id));
      const order = this.categories.filter(c => groups[c.id]).map(c => ({ id: c.id, name: c.name }));
      Object.keys(groups).forEach(id => {
        if (!known.has(Number(id))) order.push({ id: Number(id), name: this.$t('Other') });
      });
      return order.map((c, ci) => {
        const rows = groups[c.id].map((r, n) => ({ ...r, n: n + 1 }));
        return {
          ...c,
          letter: String.fromCharCode(65 + (ci % 26)),
          rows,
          request: round2(rows.reduce((s, r) => s + num(r.item.request_amount), 0)),
          allocation: round2(rows.reduce((s, r) => s + num(r.item.allocated_amount), 0)),
        };
      });
    },
    categoryOptions() {
      return this.availableCategories.map(c => ({ id: c.id, label: c.name }));
    },
    availableCategories() {
      const used = new Set(this.form.items.map(i => i.fund_category_id));
      return this.categories.filter(c => !used.has(c.id));
    },
  },

  created() {
    // Offer the sheet's own USD rate (an existing USD line, else a USD balance) to new USD lines.
    for (const i of this.form.items) for (const l of i.lines) if (num(l.exchange_rate) > 0) return (this.lastRate = num(l.exchange_rate));
    const usdBalance = this.form.balances.find(b => b.currency === 'USD' && num(b.exchange_rate) > 0);
    if (usdBalance) this.lastRate = num(usdBalance.exchange_rate);
  },

  watch: {
    // Saving a new sheet whose submit check failed redirects to its edit page: reuse of this component must reload the form.
    'allocation.id'(next, prev) {
      if (next === prev) return;
      const errors = { ...this.$page.props.errors };
      this.form = this.buildForm();
      this.sentMap = [];
      this.$nextTick(() => this.form.setError(errors));
    },
  },

  methods: {
    num,
    buildForm() {
      const a = this.allocation;
      const lineOf = l => ({ _key: key(), id: l.id, fund_payment_method_id: l.fund_payment_method_id, amount: l.amount, exchange_rate: l.exchange_rate || null, reference: l.reference || null, is_paid: !!l.is_paid });
      return this.$inertia.form({
        allocation_date: a ? a.allocation_date : this.defaults.allocation_date,
        title: a ? a.title || '' : '',
        notes: a ? a.notes || '' : '',
        balances: a
          ? a.balances.map(b => ({ _key: key(), id: b.id, fund_account_id: b.fund_account_id, label: b.label, currency: b.currency, amount: b.amount, exchange_rate: b.currency === 'TZS' ? 1 : b.exchange_rate }))
          : [{ _key: key(), id: null, fund_account_id: null, label: '', currency: 'TZS', amount: null, exchange_rate: 1 }],
        items: a
          ? a.items.map(i => ({
              _key: key(),
              _open: !!(i.warehouse_id || i.notes),
              id: i.id,
              fund_category_id: i.fund_category_id,
              description: i.description,
              beneficiary: i.beneficiary || '',
              contact_id: i.contact_id,
              beneficiary_user_id: i.beneficiary_user_id || null,
              beneficiary_warehouse_id: i.beneficiary_warehouse_id || null,
              staff_id: i.staff_id,
              warehouse_id: i.warehouse_id,
              request_amount: i.request_amount,
              allocated_amount: i.allocated_amount,
              notes: i.notes || '',
              lines: i.lines.map(lineOf),
            }))
          : [],
      });
    },
    fmt,
    err(path) {
      return this.form.errors[path];
    },
    // Error path of a field of the item at flat index idx, as numbered in the payload that was sent.
    ip(idx, field) {
      const p = this.sentMap.indexOf(idx);
      return 'items.' + (p === -1 ? idx : p) + '.' + field;
    },
    isTzs(b) {
      return !b.currency || b.currency.toUpperCase() === 'TZS';
    },
    balanceTzs(b) {
      return this.isTzs(b) ? num(b.amount) : num(b.amount) * num(b.exchange_rate);
    },
    nameOf(list, id) {
      const f = list.find(x => x.id === id);
      return f ? f.name : '';
    },
    linkCount(item) {
      return item.warehouse_id ? 1 : 0;
    },
    toggle(id) {
      this.collapsed = { ...this.collapsed, [id]: !this.collapsed[id] };
    },

    // balances
    addBalance() {
      this.form.balances.push({ _key: key(), id: null, fund_account_id: null, label: '', currency: 'TZS', amount: null, exchange_rate: 1 });
    },
    removeBalance(i) {
      this.form.balances.splice(i, 1);
    },
    // Account Name (Account Number) — the number is left out when the account has none.
    accountLabel(a) {
      return a.account_number ? a.name + ' (' + a.account_number + ')' : a.name;
    },
    onAccount(b, id) {
      b.fund_account_id = id || null;
      const acc = this.accounts.find(a => a.id === id);
      if (acc) {
        b.label = this.accountLabel(acc);
        b.currency = acc.currency;
        if (acc.currency === 'TZS') b.exchange_rate = 1;
        else if (!num(b.exchange_rate) || b.exchange_rate === 1) b.exchange_rate = null;
      }
    },

    // items
    blankItem(catId) {
      return {
        _key: key(), _open: false, id: null, fund_category_id: catId, description: '', beneficiary: '', contact_id: null, beneficiary_user_id: null, beneficiary_warehouse_id: null, staff_id: null,
        warehouse_id: null, request_amount: null, allocated_amount: null, notes: '', lines: [],
      };
    },
    addItem(catId) {
      this.form.items.push(this.blankItem(catId));
    },
    onCurrency(b, v) {
      b.currency = String(v || '').toUpperCase();
      if (b.currency === 'TZS') b.exchange_rate = 1;
      else if (num(b.exchange_rate) === 1) b.exchange_rate = null;
    },
    addCategory() {
      if (!this.pickCat) return;
      this.addItem(this.pickCat);
      this.collapsed = { ...this.collapsed, [this.pickCat]: false };
      this.pickCat = null;
    },
    removeItem(idx) {
      this.form.items.splice(idx, 1);
    },
    onAllocation(item, v) {
      item.allocated_amount = v;
      if (item.lines.length === 1) item.lines[0].amount = v;
    },
    onBeneficiary(item, v) {
      item.contact_id = v.contact_id;
      item.beneficiary_user_id = v.beneficiary_user_id;
      item.beneficiary_warehouse_id = v.beneficiary_warehouse_id;
      item.beneficiary = v.name || '';
      if (v.contact_id || v.beneficiary_user_id || v.beneficiary_warehouse_id) item.staff_id = null;
    },

    // payment split
    diff(item) {
      return round2(num(item.allocated_amount) - item.lines.reduce((s, l) => s + num(l.amount), 0));
    },
    itemState(item) {
      if (!num(item.allocated_amount) && !item.lines.length) return null;
      if (item.lines.some(l => !l.fund_payment_method_id || !(num(l.amount) > 0))) return 'warn';
      if (item.lines.some(l => this.isUsd(l) && !l.is_paid && !(num(l.exchange_rate) > 0))) return 'warn';
      return Math.abs(this.diff(item)) < 0.01 ? 'ok' : 'warn';
    },
    needsRate(item) {
      return item.lines.some(l => this.isUsd(l) && !l.is_paid && !(num(l.exchange_rate) > 0));
    },
    isBad(item) {
      return this.itemState(item) === 'warn';
    },
    allIn(item, method) {
      if (item.lines.some(l => l.is_paid)) return; // paid lines are locked
      const keep = item.lines[0] || {};
      item.lines = [{ _key: keep._key || key(), id: keep.id || null, fund_payment_method_id: method.id, amount: num(item.allocated_amount) || null, exchange_rate: method.currency === 'USD' ? this.lastRate || null : null, reference: null }];
    },
    // USD is a currency, not a method: a line paid in USD carries the exchange rate (TZS per 1 USD) and the USD it works out to.
    isUsd(l) {
      return !!this.usdMethod && l.fund_payment_method_id === this.usdMethod.id;
    },
    usdOf(l) {
      return this.isUsd(l) && num(l.exchange_rate) > 0 && num(l.amount) > 0 ? round2(num(l.amount) / num(l.exchange_rate)) : null;
    },
    onRate(l, v) {
      l.exchange_rate = v;
      if (num(v) > 0) this.lastRate = num(v);
    },
    toggleUsd(item, l) {
      if (this.isUsd(l)) {
        const used = new Set(item.lines.map(x => x.fund_payment_method_id));
        const next = this.tzsMethods.find(m => !used.has(m.id)) || this.tzsMethods[0];
        l.fund_payment_method_id = next ? next.id : null;
        l.exchange_rate = null;
      } else {
        l.fund_payment_method_id = this.usdMethod.id;
        l.exchange_rate = this.lastRate || null;
      }
    },
    addSplit(item) {
      const used = new Set(item.lines.map(l => l.fund_payment_method_id));
      const next = this.tzsMethods.find(m => !used.has(m.id)) || this.tzsMethods[0];
      const rest = this.diff(item);
      item.lines.push({ _key: key(), id: null, fund_payment_method_id: next ? next.id : null, amount: rest > 0 ? rest : null, reference: null });
    },

    // saving
    payload(submit) {
      const sentMap = [];
      const items = [];
      this.form.items.forEach((i, idx) => {
        const blank = !i.description.trim() && !num(i.request_amount) && !num(i.allocated_amount) && !i.beneficiary.trim() && !i.lines.length;
        if (blank) return;
        sentMap.push(idx);
        items.push({
          id: i.id,
          fund_category_id: i.fund_category_id,
          description: i.description,
          beneficiary: i.beneficiary,
          contact_id: i.contact_id,
          beneficiary_user_id: i.beneficiary_user_id,
          beneficiary_warehouse_id: i.beneficiary_warehouse_id,
          staff_id: i.staff_id,
          warehouse_id: i.warehouse_id,
          request_amount: num(i.request_amount),
          allocated_amount: num(i.allocated_amount),
          notes: i.notes,
          lines: i.lines
            .filter(l => l.fund_payment_method_id)
            .map(l => ({ id: l.id, fund_payment_method_id: l.fund_payment_method_id, amount: num(l.amount), exchange_rate: this.isUsd(l) ? num(l.exchange_rate) || null : null, reference: l.reference })),
        });
      });
      this.sentMap = sentMap;
      return {
        allocation_date: this.form.allocation_date,
        title: this.form.title,
        notes: this.form.notes,
        balances: this.form.balances.map(b => ({
          id: b.id,
          fund_account_id: b.fund_account_id,
          label: b.label,
          currency: (b.currency || 'TZS').toUpperCase(),
          amount: num(b.amount),
          exchange_rate: this.isTzs(b) ? 1 : num(b.exchange_rate),
        })),
        items,
        submit: !!submit,
      };
    },
    save(submit) {
      if (this.form.processing) return;
      const data = this.payload(submit);
      this.form.transform(() => data);
      const opts = { preserveScroll: true, onError: () => this.$nextTick(this.scrollToError) };
      if (this.isEdit) this.form.put(this.route('fund-allocations.update', this.allocation.id), opts);
      else this.form.post(this.route('fund-allocations.store'), opts);
    },
    scrollToError() {
      const el = document.querySelector('.fx-form .is-error, .fx-form .lg-note-red');
      if (el && el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    },
  },
};
</script>

<style scoped>
.fx-neg { color: #be123c; }
.fx-pos { color: #047857; }
.fx-prewrap { white-space: pre-line; }

/* summary strip: coloured icon cards */
.fx-summary {
  position: sticky; top: 0; z-index: 25; display: grid; grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr)); gap: 10px; margin-bottom: 1rem;
  padding: 0.6rem; border-radius: 20px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow);
  backdrop-filter: blur(14px);
}
.fx-summary.is-over { border-color: rgba(244, 63, 94, 0.55); box-shadow: 0 0 0 3px rgba(244, 63, 94, 0.12), var(--lg-shadow); }
.fx-sum-card {
  --c1: #3b82f6; --c2: #1c3f66; --tint: rgba(59, 130, 246, 0.1);
  display: flex; align-items: center; gap: 12px; min-width: 0; padding: 0.6rem 0.8rem; border-radius: 15px;
  background: linear-gradient(135deg, var(--tint), transparent 85%), var(--lg-soft); border: 1px solid color-mix(in srgb, var(--c1) 28%, transparent);
}
.fx-c-emerald { --c1: #34d399; --c2: #047857; --tint: rgba(16, 185, 129, 0.14); }
.fx-c-sky { --c1: #38bdf8; --c2: #0369a1; --tint: rgba(14, 165, 233, 0.14); }
.fx-c-violet { --c1: #a78bfa; --c2: #6d28d9; --tint: rgba(139, 92, 246, 0.14); }
.fx-c-teal { --c1: #2dd4bf; --c2: #0f766e; --tint: rgba(20, 184, 166, 0.14); }
.fx-c-amber { --c1: #fbbf24; --c2: #b45309; --tint: rgba(245, 158, 11, 0.16); }
.fx-c-rose { --c1: #fb7185; --c2: #be123c; --tint: rgba(244, 63, 94, 0.14); }
.fx-sum-ico {
  flex: none; display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 13px; color: #fff;
  background: linear-gradient(145deg, var(--c1), var(--c2)); box-shadow: 0 10px 18px -8px var(--c2);
}
.fx-sum-txt { display: flex; flex-direction: column; min-width: 0; }
.fx-sum-label { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.fx-sum-value { font-size: 1.2rem; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--lg-ink); line-height: 1.25; }
.fx-sum-small { font-size: 0.8rem; }
.fx-sum-card.fx-c-rose .fx-sum-value { color: #be123c; }
html.dark .fx-sum-card.fx-c-rose .fx-sum-value { color: #fda4af; }
.fx-sum-methods { grid-column: span 2; }
.fx-sum-mchips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 3px; }
.fx-mchip { font-size: 0.72rem; font-variant-numeric: tabular-nums; padding: 2px 9px; border-radius: 9999px; background: var(--lg-soft); border: 1px solid var(--lg-line); color: var(--lg-ink); }
.fx-mchip b { color: var(--lg-navy); margin-right: 3px; }
html.dark .fx-mchip b { color: #93c5fd; }

/* inputs */
.fx-input, .fx-sel {
  width: 100%; min-width: 0; padding: 0.5rem 0.7rem; border: 1px solid var(--lg-line); border-radius: 0.8rem;
  background: var(--lg-card); color: var(--lg-ink); font-size: 0.85rem; transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.fx-input:focus, .fx-sel:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18); }
.fx-input.is-error { border-color: #ef4444; }
.fx-cur { text-transform: uppercase; text-align: center; font-weight: 700; }
.fx-err { margin-top: 3px; font-size: 0.7rem; color: #dc2626; }
.fx-lbl, .fx-mlabel { display: block; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); margin-bottom: 3px; }
.fx-cell { min-width: 0; }
.fx-cell :deep(.mb-2) { margin-bottom: 0; }
.fx-cell :deep(input) { border-radius: 0.8rem; font-size: 0.85rem; padding-top: 0.5rem; padding-bottom: 0.5rem; }
.fx-hint { margin-left: 10px; font-size: 0.75rem; color: var(--lg-mute); }
.fx-empty { padding: 18px; text-align: center; color: var(--lg-faint); font-size: 0.82rem; border: 1px dashed var(--lg-line); border-radius: 14px; }
.fx-dash { display: block; padding: 0.55rem 0.7rem; text-align: right; color: var(--lg-faint); font-variant-numeric: tabular-nums; }

/* balances grid */
.fx-bal-head, .fx-bal-row { display: grid; gap: 10px; align-items: start; grid-template-columns: minmax(170px, 1.5fr) minmax(130px, 1.2fr) 170px minmax(120px, 1fr) minmax(110px, 0.9fr) minmax(130px, 1fr) 34px; }
.fx-bal-head { padding: 0 2px 6px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.fx-bal-head .r { text-align: right; }
.fx-bal-row { padding: 6px 0; border-top: 1px solid var(--lg-line); }
.fx-tzs-val { display: block; padding: 0.5rem 0.2rem; text-align: right; font-weight: 800; font-variant-numeric: tabular-nums; }
.fx-rm { display: flex; justify-content: flex-end; padding-top: 2px; }
.fx-mlabel { display: none; }

/* categories */
.fx-cat-head { cursor: pointer; user-select: none; margin-bottom: 0.9rem; }
.fx-letter { font-weight: 800; font-size: 1.05rem; }
.fx-cat-tot { display: flex; gap: 18px; font-variant-numeric: tabular-nums; font-size: 0.86rem; text-align: right; }
.fx-cat-tot small { display: block; font-size: 0.6rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.fx-chev { flex: none; color: var(--lg-faint); transition: transform 0.2s ease; }
.fx-chev.is-closed { transform: rotate(-90deg); }

.fx-item { padding: 10px 12px 12px; margin-bottom: 10px; border-radius: 16px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.fx-item.is-bad { border-color: rgba(245, 158, 11, 0.6); background: rgba(254, 243, 199, 0.28); }
html.dark .fx-item.is-bad { background: rgba(245, 158, 11, 0.07); }
.fx-item-main { display: grid; gap: 10px; align-items: start; grid-template-columns: 26px minmax(180px, 2fr) minmax(130px, 1.2fr) minmax(110px, 0.9fr) minmax(110px, 0.9fr) 34px; }
.fx-sn { align-self: center; justify-self: center; width: 24px; height: 24px; line-height: 24px; text-align: center; border-radius: 9999px; font-size: 0.7rem; font-weight: 800; color: var(--lg-mute); background: var(--lg-card); border: 1px solid var(--lg-line); margin-top: 14px; }

.fx-split { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 8px; margin-top: 10px; padding-top: 10px; border-top: 1px dashed var(--lg-line); }
.fx-split-label, .fx-quick-label { font-size: 0.64rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.fx-quick-label { margin-left: 8px; }
.fx-grow { flex: 1 1 8px; }
.fx-pchip { display: inline-flex; align-items: center; gap: 4px; padding: 3px 4px 3px 4px; border-radius: 9999px; background: var(--lg-card); border: 1px solid #bfdbfe; }
html.dark .fx-pchip { border-color: rgba(147, 197, 253, 0.35); }
.fx-pchip .fx-sel { width: auto; padding: 0.25rem 0.5rem; border-radius: 9999px; font-weight: 800; font-size: 0.74rem; border-color: transparent; background: rgba(59, 130, 246, 0.12); color: #1d4ed8; }
html.dark .fx-pchip .fx-sel { color: #93c5fd; }
.fx-pchip :deep(.fx-amount-input) { width: 118px; padding: 0.3rem 0.5rem; border-radius: 9999px; border-color: transparent; background: var(--lg-soft); }
.fx-usd-sign { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 17px; height: 17px; border-radius: 9999px; font-style: normal; font-size: 0.7rem; font-weight: 800; line-height: 1; color: #fff; background: linear-gradient(145deg, #34d399, #059669); }
.fx-pchip.is-usd { border-color: #6ee7b7; background: linear-gradient(135deg, #ecfdf5, #fff); }
html.dark .fx-pchip.is-usd { border-color: rgba(52, 211, 153, 0.45); background: rgba(16, 185, 129, 0.08); }
.fx-usdtag { display: inline-flex; align-items: center; gap: 5px; padding: 0.25rem 0.6rem 0.25rem 0.3rem; border-radius: 9999px; font-size: 0.74rem; font-weight: 800; color: #047857; background: rgba(16, 185, 129, 0.16); }
.fx-rate-at { font-weight: 800; color: #059669; }
.fx-pchip :deep(.fx-rate-in) { width: 86px; }
.fx-usd-eq { font-size: 0.78rem; font-weight: 800; color: #047857; font-variant-numeric: tabular-nums; white-space: nowrap; }
.fx-usd-eq.is-missing { color: #b45309; }
.fx-usd-toggle { box-sizing: border-box; padding: 0 !important; min-height: 0 !important; min-width: 0 !important; display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 9999px; border: 1px dashed #6ee7b7; background: var(--lg-card); opacity: 0.85; transition: all 0.15s ease; }
.fx-usd-toggle .fx-usd-sign { filter: grayscale(0.6); opacity: 0.8; }
.fx-usd-toggle:hover, .fx-usd-toggle.on { opacity: 1; border-style: solid; background: #d1fae5; }
.fx-usd-toggle:hover .fx-usd-sign, .fx-usd-toggle.on .fx-usd-sign { filter: none; opacity: 1; }
.fx-quick-usd { gap: 5px; border-color: #6ee7b7; color: #047857; background: linear-gradient(135deg, #ecfdf5, #fff); }
.fx-quick-usd:hover { background: #d1fae5; }
html.dark .fx-quick-usd { color: #6ee7b7; background: rgba(16, 185, 129, 0.1); }
.fx-mchip.is-usd { color: #047857; }
.fx-method-tile.is-usd { border-color: #6ee7b7; background: linear-gradient(135deg, #ecfdf5, #fff); }
.fx-method-tile.is-usd span, .fx-method-tile.is-usd b, .fx-method-tile.is-usd b .fx-cur { color: #047857; }
.fx-method-tile small { font-size: 0.7rem; color: var(--lg-faint); font-variant-numeric: tabular-nums; }
.fx-x { box-sizing: border-box; padding: 0 !important; min-height: 0 !important; min-width: 0 !important; display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 9999px; color: var(--lg-faint); }
.fx-x:hover { color: #dc2626; background: rgba(239, 68, 68, 0.12); }
.fx-addsplit, .fx-quick, .fx-more {
  display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800;
  border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-navy); transition: background 0.15s ease, transform 0.15s ease;
}
.fx-addsplit:hover, .fx-quick:hover, .fx-more:hover { background: rgba(28, 63, 102, 0.1); transform: translateY(-1px); }
html.dark .fx-addsplit, html.dark .fx-quick, html.dark .fx-more { color: #bfdbfe; }
.fx-quick { text-transform: uppercase; letter-spacing: 0.03em; }
.fx-count { margin-left: 2px; padding: 0 6px; border-radius: 9999px; background: #3b82f6; color: #fff; font-size: 0.64rem; }
.fx-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800; font-variant-numeric: tabular-nums; }
.fx-badge.ok { color: #047857; background: rgba(16, 185, 129, 0.14); }
.fx-badge.warn { color: #92400e; background: rgba(245, 158, 11, 0.2); }
html.dark .fx-badge.ok { color: #6ee7b7; } html.dark .fx-badge.warn { color: #fcd34d; }
.fx-details { margin-top: 10px; padding-top: 10px; border-top: 1px dashed var(--lg-line); }
.fx-links { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }

.fx-cat-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-top: 4px; }
.fx-cat-sub { display: flex; align-items: center; gap: 18px; font-size: 0.82rem; font-weight: 700; color: var(--lg-mute); }
.fx-sub-n { min-width: 96px; text-align: right; font-variant-numeric: tabular-nums; color: var(--lg-ink); }

.fx-addcat-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
.fx-addcat-pick { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.fx-addcat-pick :deep(.fx-pick-ac) { width: min(340px, 100%); }
.fx-addcat-pick :deep(.fx-pick-ac > div) { margin-bottom: 0; }
.fx-sel-wide { width: 260px; max-width: 100%; }
.fx-method-grid { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(min(160px, 100%), 1fr)); }
.fx-method-tile { display: flex; flex-direction: column; padding: 10px 14px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.fx-method-tile span { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.fx-method-tile b { font-size: 1.05rem; font-variant-numeric: tabular-nums; color: var(--lg-ink); }

/* medium screens: wrap the item row */
@media (max-width: 1100px) {
  .fx-item-main { grid-template-columns: 26px minmax(0, 1fr) minmax(0, 1fr) 34px; }
  .fx-item-main .fx-desc { grid-column: 2 / 4; }
  .fx-item-main .fx-ben { grid-column: 2 / 4; }
  .fx-item-main .fx-amt { grid-column: span 1; }
  .fx-bal-head { display: none; }
  .fx-mlabel { display: block; }
  .fx-bal-row { grid-template-columns: repeat(auto-fit, minmax(min(150px, 100%), 1fr)); padding: 10px 0; }
  .fx-bal-row .fx-rm { justify-content: flex-start; }
  .fx-tzs-val { text-align: left; }
  .fx-dash { text-align: left; }
}
/* phones: rows become stacked cards */
@media (max-width: 640px) {
  .fx-item-main { grid-template-columns: 1fr 34px; }
  .fx-item-main .fx-sn { display: none; }
  .fx-item-main .fx-desc, .fx-item-main .fx-ben { grid-column: 1 / 3; }
  .fx-item-main .fx-amt { grid-column: span 1; }
  .fx-item-main .fx-amt:nth-of-type(3) { grid-column: 1 / 2; }
  .fx-item-main .fx-rm { grid-column: 2 / 3; grid-row: 1; }
  .fx-bal-row { grid-template-columns: 1fr 1fr; border: 1px solid var(--lg-line); border-radius: 14px; padding: 10px; margin-bottom: 8px; background: var(--lg-soft); }
  .fx-bal-row .fx-cell:first-child, .fx-bal-row .fx-cell:nth-child(2) { grid-column: 1 / 3; }
  .fx-pchip { width: 100%; justify-content: space-between; }
  .fx-pchip :deep(.fx-amount-input) { flex: 1; width: auto; }
  .fx-cat-tot { display: none; }
  .fx-summary { gap: 6px; padding: 0.4rem; grid-template-columns: 1fr 1fr; }
  .fx-sum-card { padding: 0.45rem 0.55rem; gap: 8px; }
  .fx-sum-ico { width: 32px; height: 32px; border-radius: 10px; }
  .fx-sum-value { font-size: 0.95rem; }
  .fx-sum-methods { grid-column: span 2; }
  .fx-sub-n { min-width: 0; }
  .fx-cat-sub { gap: 10px; }
  .fx-sel-wide { width: 100%; }
}

.fx-cur { font-style: normal; font-size: 0.72em; font-weight: 700; margin-left: 4px; opacity: 0.8; letter-spacing: 0.03em; }
.fx-unitwrap { position: relative; }
.fx-unitwrap :deep(.fx-amount-input) { padding-right: 3rem; }
.fx-unit { position: absolute; right: 11px; top: 50%; transform: translateY(-50%); font-style: normal; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.04em; color: var(--lg-faint); pointer-events: none; }
.fx-unit-inline { font-style: normal; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.04em; color: var(--lg-faint); margin-right: 4px; }
.fx-bal-head { font-size: 0.7rem !important; font-weight: 800 !important; color: #7f1d1d !important; }
html.dark .fx-bal-head { color: #fca5a5 !important; }
.fx-cat-sub, .fx-cat-sub .fx-sub-n, .fx-cat-sub .fx-sub-n b { color: #7f1d1d; font-weight: 800; }
html.dark .fx-cat-sub, html.dark .fx-cat-sub .fx-sub-n, html.dark .fx-cat-sub .fx-sub-n b { color: #fca5a5; }

/* method tiles + balance in maroon bold */
.fx-method-tile span, .fx-method-tile b, .fx-method-tile b .fx-cur { color: #7f1d1d; font-weight: 800; }
html.dark .fx-method-tile span, html.dark .fx-method-tile b { color: #fca5a5; }
.lg-actionbar-note { color: #7f1d1d !important; font-weight: 800 !important; }
html.dark .lg-actionbar-note { color: #fca5a5 !important; }
.lg-actionbar-note.fx-neg { color: #be123c !important; }
/* add a category section */
.fx-addcat { position: relative; overflow: hidden; background: linear-gradient(135deg, rgba(99, 132, 255, 0.14), rgba(16, 185, 129, 0.1) 55%, rgba(255, 255, 255, 0.7)); border: 1px solid rgba(99, 132, 255, 0.35); box-shadow: 0 18px 30px -20px rgba(28, 63, 102, 0.6); }
html.dark .fx-addcat { background: linear-gradient(135deg, rgba(67, 56, 202, 0.3), rgba(4, 120, 87, 0.2)); border-color: rgba(147, 197, 253, 0.3); }
.fx-addcat-head { display: flex; align-items: center; gap: 14px; }
.fx-addcat-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 48px; height: 48px; border-radius: 16px; color: #fff; background: linear-gradient(145deg, #818cf8, #4338ca); box-shadow: 0 12px 20px -8px #4338ca; }
.fx-addcat-title { color: #1c3f66; }
html.dark .fx-addcat-title { color: #bfdbfe; }
.fx-addcat .lg-btn { background: linear-gradient(145deg, #34d399, #047857); color: #fff; border-color: transparent; box-shadow: 0 10px 18px -8px rgba(4, 120, 87, 0.7); }
.fx-addcat .lg-btn:disabled { opacity: 0.5; box-shadow: none; }

.lg-actionbar-note { font-size: 1.1rem !important; }
.lg-actionbar-note .fx-ab-label { color: #1c3f66; font-weight: 900; margin-right: 6px; }
.lg-actionbar-note .fx-ab-value { color: #7f1d1d; font-weight: 900; font-size: 1.2rem; font-variant-numeric: tabular-nums; }
.lg-actionbar-note .fx-ab-value .fx-cur { font-size: 0.65em; }
html.dark .lg-actionbar-note .fx-ab-label { color: #bfdbfe; }
html.dark .lg-actionbar-note .fx-ab-value { color: #fca5a5; }
.lg-actionbar-note.fx-neg .fx-ab-value { color: #be123c; }
.fx-paidlock { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; color: #047857; }
.fx-quick:disabled { opacity: 0.4; cursor: not-allowed; }
</style>

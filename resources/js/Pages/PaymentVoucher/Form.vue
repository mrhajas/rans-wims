<template>
  <admin-layout :title="paymentVoucher ? $t('Edit Payment Voucher') : $t('Create Payment Voucher')">
    <div class="lg-page px-4 md:px-0 pv-form">
      <page-header
        icon="bag"
        :title="paymentVoucher ? $t('Edit Payment Voucher') : $t('Create Payment Voucher')"
        :subtitle="$t('Fill in the details below to prepare the payment voucher.')"
      >
        <span v-if="paymentVoucher" class="lg-chip lg-chip-green lg-mono" style="text-transform: none">{{ paymentVoucher.pv_number }}</span>
        <Link :href="route('payment-vouchers.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Payment Vouchers') }}
        </Link>
      </page-header>

      <div v-if="prefillWarning && !paymentVoucher" class="pv-fund-banner pv-fund-banner-warn mb-4" role="alert">
        <icons name="info" class="w-5 h-5 flex-none" />
        <span class="flex-1">{{ prefillWarning }}</span>
      </div>
      <div v-if="prefill && prefill.is_subcontract && !paymentVoucher" class="pv-fund-banner mb-4">
        <icons name="info" class="w-5 h-5 flex-none" />
        <span class="flex-1">
          {{ prefill.is_subcontract_claim ? $t('Creating the voucher for contract payment request') : $t('Creating the voucher for subcontract request') }} <strong>{{ prefill.fund_item_summary.reference }}</strong> - {{ prefill.fund_item_summary.description }}
          <template v-if="prefill.fund_item_summary.category"> ({{ prefill.fund_item_summary.category }})</template>.
          {{ $t('The payee and the total are fixed by what was approved.') }}
          <span v-if="$page.props.errors.subcontract_payment_id" class="block text-red-600 font-semibold mt-1 wims-field-error">{{ $page.props.errors.subcontract_payment_id }}</span>
          <span v-if="$page.props.errors.subcontract_claim_id" class="block text-red-600 font-semibold mt-1 wims-field-error">{{ $page.props.errors.subcontract_claim_id }}</span>
        </span>
      </div>
      <div v-else-if="prefill && !paymentVoucher && showFundBanner" class="pv-fund-banner mb-4">
        <icons name="info" class="w-5 h-5 flex-none" />
        <span class="flex-1">
          {{ $t('Creating a voucher for Funds Allocation') }} <strong>{{ prefill.fund_item_summary.reference }}</strong>
          <template v-if="prefill.fund_item_summary.description"> - {{ prefill.fund_item_summary.description }}</template>
          <template v-if="prefill.fund_item_summary.category"> ({{ prefill.fund_item_summary.category }})</template>
          <span v-if="$page.props.errors.fund_allocation_item_id" class="block text-red-600 font-semibold mt-1 wims-field-error">{{ $page.props.errors.fund_allocation_item_id }}</span>
        </span>
        <button type="button" class="pv-fund-banner-close" :aria-label="$t('Dismiss')" @click="showFundBanner = false">&times;</button>
      </div>
      <div v-else-if="prefill && !paymentVoucher && $page.props.errors.fund_allocation_item_id" class="text-red-600 text-sm font-semibold mb-4">{{ $page.props.errors.fund_allocation_item_id }}</div>

      <section v-if="prefill && prefill.is_subcontract_claim && prefill.claim_allocations && prefill.claim_allocations.length && !paymentVoucher" class="lg-card pv-claim-alloc mb-4">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-teal"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('What this voucher pays') }}</h2>
            <p class="lg-card-sub">{{ $t('One voucher for several contracts. The amount of each contract is kept, so each can be traced. The payee and the total are fixed and cannot be changed here.') }}</p>
          </div>
        </header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead><tr><th>{{ $t('Contract') }}</th><th>{{ $t('Payment request') }}</th><th class="text-right">{{ $t('Amount') }}</th><th v-if="claimAllocForeign" class="text-right">{{ $t('In TZS') }}</th></tr></thead>
            <tbody>
              <tr v-for="(a, i) in prefill.claim_allocations" :key="i">
                <td :data-label="$t('Contract')">{{ a.agreement || '-' }}</td>
                <td :data-label="$t('Payment request')">{{ a.request || '-' }}</td>
                <td :data-label="$t('Amount')" class="text-right">{{ a.currency }} {{ Number(a.amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</td>
                <td v-if="claimAllocForeign" :data-label="$t('In TZS')" class="text-right">TZS {{ Number(a.amount_tzs).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr><td :colspan="claimAllocForeign ? 3 : 2"><strong>{{ $t('Voucher total') }}</strong></td><td class="text-right"><strong>TZS {{ Number(prefill.total_amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</strong></td></tr>
            </tfoot>
          </table>
        </div>
      </section>

      <form @submit.prevent="submit()">
        <section class="lg-card pv-payee-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-pink"><icons name="users" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Payee') }}</h2>
              <p class="lg-card-sub">{{ $t('Payee Type') }}</p>
            </div>
          </header>

          <div class="mb-4">
            <label class="pv-payee-type-label block mb-2">{{ $t('This payment is for') }}</label>
            <div class="pv-payee-type-toggle">
              <button type="button" class="pv-payee-type-btn" :class="{ 'pv-payee-type-active': form.payee_type === 'External Personnel' }" @click="setPayeeType('External Personnel')">
                <icons name="users" class="w-4 h-4" /> {{ $t('External Personnel') }}
              </button>
              <button type="button" class="pv-payee-type-btn" :class="{ 'pv-payee-type-active': form.payee_type === 'Internal Staff' }" @click="setPayeeType('Internal Staff')">
                <icons name="users" class="w-4 h-4" /> {{ $t('Internal Staff') }}
              </button>
            </div>
          </div>

          <div class="lg-fields">
            <template v-if="form.payee_type === 'Internal Staff'">
              <div>
                <auto-complete
                  id="staff"
                  :label="$t('RANS Staff')"
                  v-model="form.staff_id"
                  :suggestions="staffSuggestions"
                  :error="$page.props.errors.staff_id"
                />
              </div>
              <div class="lg-subtext self-center pt-5">{{ $t('Payee name is taken from the selected staff record.') }}</div>
            </template>
            <template v-else>
              <div>
                <div class="flex items-end gap-3">
                  <auto-complete
                    :key="payeeFieldKey"
                    id="payee"
                    class="flex-1"
                    :label="$t('Payee')"
                    :model-value="form.payee_contact_id"
                    @update:modelValue="onPayeeChange"
                    :suggestions="contactSuggestions"
                    :error="$page.props.errors.payee_contact_id"
                  />
                  <button v-if="$can('create-contacts')" type="button" class="pv-add-contact-btn lg-tip" :data-tip="$t('Add New Contact')" @click="showContactModal = true">
                    <icons name="plus" class="w-5 h-5" />
                  </button>
                </div>
                <p v-if="$can('create-contacts')" class="lg-subtext mt-1">
                  {{ $t("Can't find who you're paying? Click + to add them in seconds.") }}
                </p>
              </div>
              <div>
                <text-input :label="$t('Payee Name')" v-model="form.payee_name" :error="$page.props.errors.payee_name" required />
                <p class="lg-subtext mt-1">{{ $t('Auto-filled from the selected payee. Edit freely if paying someone not yet in Contacts.') }}</p>
              </div>
            </template>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Voucher Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Project') }} / {{ $t('Type of Voucher') }} / {{ $t('Date') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <auto-complete
                id="project"
                :label="$t('Project (optional)')"
                v-model="form.warehouse_id"
                :suggestions="warehouseSuggestions"
                :error="$page.props.errors.warehouse_id"
                clearable
                :placeholder="companyName"
              />
            </div>
            <div>
              <auto-complete
                id="type"
                :label="$t('Type of Voucher')"
                :model-value="form.type"
                @update:modelValue="v => (form.type = v)"
                :suggestions="types"
                freeform
                :error="$page.props.errors.type"
              />
            </div>
            <div><date-picker :label="$t('Date')" v-model="form.voucher_date" :error="$page.props.errors.voucher_date" required /></div>
            <div><text-input :label="$t('Recipient Name (optional)')" v-model="form.recipient_name" :error="$page.props.errors.recipient_name" /></div>
            <div v-if="form.type === 'Transport / Fuel'">
              <auto-complete id="asset_id" :label="$t('Asset (Vehicle)')" v-model="form.asset_id" :suggestions="assetSuggestions" :error="$page.props.errors.asset_id" clearable />
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="list" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h3 class="lg-card-title">{{ $t('Particulars') }}</h3>
              <p class="lg-card-sub">{{ $t('Amount in Words') }}: {{ amountInWordsPreview }}</p>
            </div>
            <button type="button" class="lg-btn lg-btn-soft" @click="addRow">
              <icons name="plus" class="w-4 h-4" />{{ $t('Add Line') }}
            </button>
          </header>
          <div v-if="$page.props.errors.items" class="text-red-600 text-sm mb-2">{{ $page.props.errors.items }}</div>

          <div class="lg-table-wrap">
            <table class="lg-table lg-responsive pv-lines">
              <thead>
                <tr>
                  <th class="w-12">{{ $t('SN') }}</th>
                  <th>{{ $t('Particulars') }}</th>
                  <th class="num">{{ $t('Amount') }}</th>
                  <th class="w-12"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, index) in form.items" :key="index" class="align-top">
                  <td :data-label="$t('SN')" class="lg-subtext">{{ index + 1 }}</td>
                  <td :data-label="$t('Particulars')" class="pv-part-td">
                    <div class="pv-particulars-input" :class="{ 'pv-particulars-input-error': $page.props.errors['items.' + index + '.particulars_remainder'] }">
                      <span class="pv-particulars-prefix">{{ $t('Being Paid for :') }}</span>
                      <input
                        v-model="row.particularsRemainder"
                        list="pv-particulars-list"
                        class="pv-particulars-remainder"
                        :placeholder="$t('e.g. cement purchase for site')"
                      />
                    </div>
                    <p v-if="$page.props.errors['items.' + index + '.particulars_remainder']" class="text-red-600 text-xs mt-1 wims-field-error">
                      {{ $page.props.errors['items.' + index + '.particulars_remainder'] }}
                    </p>
                  </td>
                  <td :data-label="$t('Amount')" class="num">
                    <text-input type="number" step="0.01" v-model="row.amount" class="pv-amount" :error="$page.props.errors['items.' + index + '.amount']" />
                  </td>
                  <td :data-label="$t('Remove')">
                    <button type="button" v-if="form.items.length > 1" class="lg-icon-btn is-red lg-tip tip-left" @click="removeRow(index)" :data-tip="$t('Remove')">
                      <icons name="trash" class="w-4 h-4" />
                    </button>
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr class="pv-total-row">
                  <td colspan="2" class="num font-bold">{{ $t('Total') }}</td>
                  <td class="num font-bold">{{ $money(total) }}</td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>
          <datalist id="pv-particulars-list">
            <option v-for="p in particulars" :key="p" :value="p" />
          </datalist>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h3 class="lg-card-title">{{ $t('Notes & Attachments') }}</h3>
              <p class="lg-card-sub">{{ $t('Supporting documents: PDF, DOC, DOCX, JPG, JPEG, or PNG, up to 5 files.') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div class="lg-span-all pv-notes">
              <div><textarea-input :label="$t('Remarks / Notes (printed on voucher)')" v-model="form.remarks" :error="$page.props.errors.remarks" rows="4" /></div>
              <div><textarea-input :label="$t('Internal Notes (not printed)')" v-model="form.internal_notes" :error="$page.props.errors.internal_notes" rows="4" /></div>
            </div>
            <div class="lg-span-all">
              <label class="block font-medium mb-2 text-sm pv-attach-label">{{ $t('Attachments') }}</label>
              <attachment-picker v-model="selectedFiles" :max-files="5" :help-text="$t('Supporting documents: PDF, DOC, DOCX, JPG, JPEG, or PNG, up to 5 files.')" />
              <div v-if="$page.props.errors.attachments" class="text-red-600 text-sm mt-1">{{ $page.props.errors.attachments }}</div>
              <ul v-if="paymentVoucher && paymentVoucher.attachments && paymentVoucher.attachments.length" class="pv-files mt-3">
                <li v-for="file in paymentVoucher.attachments" :key="file.id">
                  <span class="truncate">{{ file.name }}</span>
                  <a :href="route('payment-vouchers.attachments.download', [paymentVoucher.id, file.id])" class="pv-dl-link shrink-0">{{ $t('Download') }}</a>
                  <button v-if="$can('update-payment-vouchers')" type="button" class="pv-rm shrink-0" :title="$t('Remove this file')" :aria-label="$t('Remove this file')" @click="removeFile(file)"><icons name="trash" class="w-4 h-4" /></button>
                </li>
              </ul>
            </div>
          </div>
        </section>

        <div class="lg-actionbar">
          <span class="lg-actionbar-note">{{ $t('Total') }}: {{ $money(total) }}</span>
          <div class="lg-actionbar-btns">
            <Link :href="route('payment-vouchers.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <loading-button type="submit" :loading="form.processing" :disabled="form.processing">
              <icons name="tick" class="w-4 h-4 mr-1" /> {{ paymentVoucher ? $t('Save Changes') : $t('Save as Draft') }}
            </loading-button>
          </div>
        </div>
      </form>

      <quick-add-contact-modal :show="showContactModal" @close="showContactModal = false" @created="onContactCreated" />
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TextInput from '@/Shared/TextInput.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import AttachmentPicker from '@/Shared/AttachmentPicker.vue';
import QuickAddContactModal from '@/Shared/QuickAddContactModal.vue';

// Mirrors App\Models\PaymentVoucher::PARTICULARS_PREFIX — the backend owns
// prefixing on save, this is only used to strip it back off when loading an
// existing item into the form for editing.
const PARTICULARS_PREFIX = 'Being Paid for : ';

export default {
  components: { AdminLayout, PageHeader, TextInput, DatePicker, TextareaInput, AutoComplete, LoadingButton, AttachmentPicker, QuickAddContactModal },

  props: {
    paymentVoucher: Object,
    contacts: Array,
    staffs: Array,
    warehouses: Array,
    particulars: Array,
    types: Array,
    assets: Array,
    companyName: String,
    // Funds Allocation hand-off (create mode only) - see PaymentVoucherController::create().
    prefill: { type: Object, default: null },
    prefillWarning: { type: String, default: null },
  },

  data() {
    const pv = this.paymentVoucher || {};
    const pf = !this.paymentVoucher && this.prefill ? this.prefill : null;
    return {
      showFundBanner: true,
      selectedFiles: [],
      showContactModal: false,
      payeeFieldKey: 0,
      localContacts: [...this.contacts],
      form: this.$inertia.form({
        payee_type: pv.payee_type || (pf && pf.payee_type) || 'External Personnel',
        payee_contact_id: pv.payee_contact_id || (pf && pf.payee_contact_id) || null,
        staff_id: pv.staff_id || (pf && pf.staff_id) || null,
        payee_name: pv.payee_name || (pf && pf.payee_name) || '',
        warehouse_id: pv.warehouse_id || (pf && pf.warehouse_id) || null,
        type: pv.type || (pf && pf.type) || 'Administration',
        asset_id: pv.asset_id || null,
        voucher_date: pv.voucher_date || (pf && pf.voucher_date) || new Date().toISOString().slice(0, 10),
        recipient_name: pv.recipient_name || '',
        internal_notes: pv.internal_notes || '',
        remarks: pv.remarks || (pf && pf.remarks) || '',
        fund_allocation_item_id: pf ? pf.fund_item_id : null,
        subcontract_payment_id: pf && pf.subcontract_payment_id ? pf.subcontract_payment_id : null,
        subcontract_claim_id: pf && pf.subcontract_claim_id ? pf.subcontract_claim_id : null,
        items: pv.items && pv.items.length ? pv.items.map(item => ({
          id: item.id,
          particularsRemainder: this.stripParticularsPrefix(item.particulars),
          amount: item.amount,
        })) : (pf ? [{ particularsRemainder: pf.particulars_remainder, amount: pf.total_amount }] : [this.emptyRow()]),
      }),
    };
  },

  computed: {
    claimAllocForeign() { return !!(this.prefill && this.prefill.claim_allocations && this.prefill.claim_allocations.some(a => a.currency !== 'TZS')); },
    contactSuggestions() {
      return this.localContacts.map(c => ({ id: c.id, name: c.name, category: c.category }));
    },
    staffSuggestions() {
      return this.staffs.map(s => ({ id: s.id, name: `${s.name} (${s.staff_number})` }));
    },
    warehouseSuggestions() {
      return this.warehouses.map(w => ({ id: w.id, name: w.name }));
    },
    assetSuggestions() {
      return this.assets.map(a => ({ id: a.id, name: `${a.asset_number} / ${a.name}` }));
    },
    total() {
      return this.form.items.reduce((sum, row) => sum + (parseFloat(row.amount) || 0), 0);
    },
    amountInWordsPreview() {
      // Client-side estimate only — the server recomputes and stores the
      // authoritative amount-in-words snapshot on save.
      return this.$money(this.total);
    },
  },

  watch: {
    'form.type'(type) {
      if (type !== 'Transport / Fuel') {
        this.form.asset_id = null;
      }
    },
  },

  methods: {
    // a wrongly attached file is removed from a voucher that can still be edited
    async removeFile(file) {
      if (!await this.$confirm(this.$t('Remove :f from this voucher?', { f: file.name }), { variant: 'danger' })) return;
      this.$inertia.delete(route('payment-vouchers.attachments.destroy', [this.paymentVoucher.id, file.id]), { preserveScroll: true, preserveState: true });
    },
    emptyRow() {
      return { particularsRemainder: '', amount: 0 };
    },
    stripParticularsPrefix(text) {
      return (text || '').startsWith(PARTICULARS_PREFIX) ? text.slice(PARTICULARS_PREFIX.length) : (text || '');
    },
    onContactCreated(contact) {
      this.localContacts = [contact, ...this.localContacts];
      this.onPayeeChange(contact.id);
      // Forces the Payee auto-complete to remount so it re-reads the new
      // selection's label from the just-updated suggestions list — it only
      // syncs its own visible search text from `modelValue` on mount, not on
      // an externally-driven prop change.
      this.payeeFieldKey++;
    },
    onPayeeChange(contactId) {
      this.form.payee_contact_id = contactId;
      const contact = this.localContacts.find(c => c.id === contactId);
      if (contact) {
        this.form.payee_name = contact.name;
      }
    },
    setPayeeType(type) {
      if (this.form.payee_type === type) return;
      this.form.payee_type = type;
      this.form.payee_contact_id = null;
      this.form.staff_id = null;
      this.form.payee_name = '';
    },
    addRow() {
      this.form.items.push(this.emptyRow());
    },
    removeRow(index) {
      this.form.items.splice(index, 1);
    },
    submit(confirmDuplicate = false) {
      this.form.transform(data => ({
        payee_type: data.payee_type,
        payee_contact_id: data.payee_type === 'Internal Staff' ? null : data.payee_contact_id,
        staff_id: data.payee_type === 'Internal Staff' ? data.staff_id : null,
        payee_name: data.payee_name,
        warehouse_id: data.warehouse_id,
        asset_id: data.type === 'Transport / Fuel' ? data.asset_id : null,
        type: data.type,
        voucher_date: data.voucher_date,
        recipient_name: data.recipient_name,
        internal_notes: data.internal_notes,
        remarks: data.remarks,
        ...(data.fund_allocation_item_id ? { fund_allocation_item_id: data.fund_allocation_item_id } : {}),
        ...(data.subcontract_payment_id ? { subcontract_payment_id: data.subcontract_payment_id } : {}),
        ...(data.subcontract_claim_id ? { subcontract_claim_id: data.subcontract_claim_id } : {}),
        items: data.items.map(row => ({
          ...(row.id ? { id: row.id } : {}),
          particulars_remainder: row.particularsRemainder,
          amount: row.amount,
        })),
        attachments: this.selectedFiles,
        confirm_duplicate: confirmDuplicate,
      }));

      const options = {
        onError: async errors => {
          if (errors.duplicate && await this.$confirm(errors.duplicate + ' ' + this.$t('Continue anyway?'))) {
            this.submit(true);
          }
        },
      };

      if (this.paymentVoucher) {
        this.form.put(route('payment-vouchers.update', this.paymentVoucher.id), options);
      } else {
        this.form.post(route('payment-vouchers.store'), options);
      }
    },
  },
};
</script>

<style scoped>
/* The two note boxes sit side by side at the same width; boxes share a bottom edge so they align even when one label wraps to two lines */
.pv-notes { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem 1.25rem; align-items: end; }
.pv-notes :deep(.col-span-6) { width: 100%; max-width: none; }
.pv-notes :deep(textarea) { display: block; width: 100%; min-height: 7.5rem; resize: vertical; }
@media (max-width: 640px) { .pv-notes { grid-template-columns: 1fr; } }
.pv-form :deep(label:not(:has(input[type='checkbox']))),
.pv-payee-type-label,
.pv-attach-label {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
  margin-bottom: 0.25rem;
}
.pv-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):not([type='hidden'])),
.pv-form :deep(select),
.pv-form :deep(textarea) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.pv-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):focus),
.pv-form :deep(select:focus),
.pv-form :deep(textarea:focus) {
  border-color: #db2777 !important;
  box-shadow: 0 0 0 3px rgba(219, 39, 119, 0.15) !important;
}
.pv-payee-card { position: relative; z-index: 3; }
.pv-payee-type-toggle { display: inline-flex; gap: 4px; background: var(--lg-soft); border: 1px solid var(--lg-line); border-radius: 9999px; padding: 4px; max-width: 100%; flex-wrap: wrap; }
.pv-payee-type-btn {
  display: inline-flex; align-items: center; gap: 6px; padding: 0.5rem 1rem; border-radius: 9999px; font-size: 0.82rem; font-weight: 700;
  color: var(--lg-mute); transition: background 0.15s ease, color 0.15s ease;
}
.pv-payee-type-active { background: linear-gradient(145deg, #f472b6, #be185d); color: #fff; box-shadow: 0 6px 16px -6px rgba(190, 24, 93, 0.5); }
.pv-add-contact-btn {
  position: relative; flex: none; display: inline-flex; align-items: center; justify-content: center; width: 46px; height: 46px; margin-bottom: 0.5rem; border-radius: 0.9rem;
  color: #fff; background: linear-gradient(145deg, #f472b6, #be185d); box-shadow: 0 8px 18px -6px rgba(190, 24, 93, 0.55);
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.pv-add-contact-btn:hover { transform: translateY(-2px) scale(1.03); box-shadow: 0 12px 24px -6px rgba(190, 24, 93, 0.6); }

.pv-fund-banner {
  display: flex; align-items: flex-start; gap: 0.65rem; padding: 0.75rem 1rem; border-radius: 0.85rem; font-size: 0.85rem;
  color: #1e40af; background: #eff6ff; border: 1px solid #bfdbfe;
}
.pv-fund-banner-warn { color: #92400e; background: #fffbeb; border-color: #fde68a; }
.pv-fund-banner-close { flex: none; font-size: 1.25rem; line-height: 1; padding: 0 0.35rem; color: inherit; opacity: 0.6; }
.pv-fund-banner-close:hover { opacity: 1; }
html.dark .pv-fund-banner { color: #bfdbfe; background: rgba(59, 130, 246, 0.12); border-color: rgba(59, 130, 246, 0.3); }
html.dark .pv-fund-banner-warn { color: #fde68a; background: rgba(245, 158, 11, 0.12); border-color: rgba(245, 158, 11, 0.3); }

.pv-part-td { min-width: 300px; }
.pv-amount { width: 9rem; margin-left: auto; }
.pv-total-row td { background: var(--lg-soft); border-bottom: 0; }
.pv-particulars-input {
  display: flex; align-items: stretch; border: 1px solid var(--lg-line); border-radius: 0.85rem; overflow: hidden; background: var(--lg-card);
}
.pv-particulars-input:focus-within { border-color: #db2777; box-shadow: 0 0 0 3px rgba(219, 39, 119, 0.15); }
.pv-particulars-input-error { border-color: #ef4444; }
.pv-particulars-prefix {
  flex: none; display: flex; align-items: center; padding: 0 0.65rem; font-size: 0.78rem; font-weight: 700; color: var(--lg-mute);
  background: var(--lg-soft); border-right: 1px solid var(--lg-line); white-space: nowrap;
}
.pv-form .pv-particulars-remainder { flex: 1; min-width: 0; border: 0 !important; border-radius: 0 !important; padding: 0.55rem 0.65rem; font-size: 0.85rem; color: var(--lg-ink); background: transparent; box-shadow: none !important; }
.pv-particulars-remainder:focus { outline: none; }

.pv-files { border: 1px solid var(--lg-line); border-radius: 14px; overflow: hidden; font-size: 0.82rem; color: var(--lg-ink); }
.pv-files li { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 8px 12px; border-bottom: 1px solid var(--lg-line); }
.pv-files li:last-child { border-bottom: 0; }
.pv-dl-link { font-weight: 700; color: var(--lg-navy); }
.pv-dl-link:hover { text-decoration: underline; }
html.dark .pv-dl-link { color: #93c5fd; }

@media (max-width: 767.98px) {
  .pv-part-td { min-width: 0; }
  .pv-part-td > * { flex: 1 1 auto; min-width: 0; width: 100%; }
  .pv-amount { width: 100%; }
  .pv-total-row { display: flex !important; justify-content: space-between; }
  .pv-total-row td { background: transparent; }
}

@media (max-width: 767.98px) {
  /* The "Being Paid for :" prefix sat beside the text box and squeezed it to ~25px; on phones it becomes a caption above it. */
  .pv-part-td { min-width: 0; }
  .pv-particulars-input { flex-wrap: wrap; }
  .pv-particulars-prefix { flex: 1 1 100%; border-right: 0; border-bottom: 1px solid var(--lg-line); padding: 0.4rem 0.65rem; white-space: normal; }
  .pv-form .pv-particulars-remainder, .pvb-form .pv-particulars-remainder, .pv-particulars-remainder { flex: 1 1 100%; width: 100%; }
}
.pv-rm { display: inline-grid; place-items: center; width: 28px; height: 28px; border: 0; border-radius: 8px; color: #b91c1c; background: rgba(244, 63, 94, 0.1); cursor: pointer; } .pv-rm:hover { background: rgba(244, 63, 94, 0.2); }
</style>

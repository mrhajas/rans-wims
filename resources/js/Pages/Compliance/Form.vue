<template>
  <admin-layout :title="record ? $t('Edit Compliance Record') : $t('Add Compliance Record')">
    <div class="lg-page px-4 md:px-0 cmp-form">
      <page-header
        icon="clip"
        :title="record ? $t('Edit Compliance Record') : $t('Add Compliance Record')"
        :subtitle="record ? $t('Update the record by modifying the details in the form below') : $t('Please fill the form below to add new record.')"
      >
        <span v-if="record && record.compliance_number" class="lg-chip lg-chip-indigo" style="text-transform: none">{{ record.compliance_number }}</span>
        <Link :href="route('compliance-records.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Compliance Records') }}</Link>
      </page-header>

      <form @submit.prevent="submit">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Identification') }}</h2>
              <p class="lg-card-sub">{{ $t('Compliance Type') }} / {{ $t('Title') }} / {{ $t('Reference Number') }}</p>
            </div>
          </header>
          <div class="cmp-ident">
            <div class="cmp-photo-col">
              <div class="cmp-photo-picker" :title="$t('Photo')" @click="$refs.photoInput.click()">
              <img v-if="photoPreviewUrl" :src="photoPreviewUrl" class="cmp-photo-img" alt="" />
              <div v-else class="cmp-photo-placeholder">{{ $t('PHOTO') }}</div>
              <button v-if="photoPreviewUrl" type="button" class="cmp-photo-remove lg-tip" @click.stop="removePhoto" :data-tip="$t('Remove')" :aria-label="$t('Remove')">
                <icons name="cross" class="w-3 h-3" />
              </button>
            </div>
              <input ref="photoInput" type="file" accept="image/png,image/jpeg,image/svg+xml" class="hidden" @change="onPhotoChosen" />
              <p class="cmp-photo-hint">{{ $t('Click to upload a photo (PNG or JPG)') }}</p>
            </div>
            <div class="cmp-ident-fields">
          <div class="lg-fields">
            <auto-complete id="compliance_type" :label="$t('Compliance Type')" v-model="form.compliance_type" :suggestions="types" freeform />
            <auto-complete id="compliance_category_id" :label="$t('Category')" v-model="form.compliance_category_id" :suggestions="categoryOptions" clearable />
            <text-input :label="$t('Title')" v-model="form.title" :error="$page.props.errors.title" required />
            <text-input :label="$t('Reference Number')" v-model="form.reference_number" />
            <auto-complete id="issuing_authority" :label="$t('Issuing Authority')" v-model="form.issuing_authority" :suggestions="authorities" freeform clearable />
            <text-input :label="$t('Issued To')" v-model="form.issued_to" />
            <auto-complete id="responsible_user_id" :label="$t('Responsible Person')" v-model="form.responsible_user_id" :suggestions="userOptions" clearable />
            <text-input :label="$t('Department')" v-model="form.department" />
            <div>
              <label class="cmp-label">{{ $t('Scope') }}</label>
              <css-select v-model="form.scope" class="cmp-ctl">
                <option v-for="s in scopes" :key="s" :value="s">{{ $t(s) }}</option>
              </css-select>
            </div>
            <div>
              <label class="cmp-label">{{ $t('Priority') }}</label>
              <css-select v-model="form.priority" class="cmp-ctl" :disabled="!form.priority_is_manual_override">
                <option v-for="p in priorities" :key="p" :value="p">{{ $t(p) }}</option>
              </css-select>
              <label class="cmp-check">
                <input type="checkbox" v-model="form.priority_is_manual_override" />
                {{ $t('Set manually (otherwise auto-calculated from expiry date)') }}
              </label>
            </div>
            <div class="lg-span-all">
              <label class="cmp-label">{{ $t('Description') }}</label>
              <textarea v-model="form.description" rows="2" class="cmp-ctl"></textarea>
            </div>
          </div>
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="calendar" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Dates') }}</h2>
              <p class="lg-card-sub">{{ $t('Issue Date') }} / {{ $t('Expiry Date') }} / {{ $t('Renewal Date') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <date-picker :label="$t('Issue Date')" v-model="form.issue_date" />
            <date-picker :label="$t('Effective Date')" v-model="form.effective_date" />
            <date-picker :label="$t('Expiry Date')" v-model="form.expiry_date" />
            <date-picker :label="$t('Renewal Date')" v-model="form.renewal_date" />
            <date-picker :label="$t('Submission Date')" v-model="form.submission_date" />
            <date-picker :label="$t('Next Review Date')" v-model="form.next_review_date" />
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="link" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Relationships') }}</h2>
              <p class="lg-card-sub">{{ $t('Project') }} / {{ $t('Supplier') }} / {{ $t('Asset') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <auto-complete id="warehouse_id" :label="$t('Project')" v-model="form.warehouse_id" :suggestions="warehouseOptions" clearable />
            <auto-complete v-if="form.scope === 'Supplier'" id="supplier_id" :label="$t('Supplier')" v-model="form.supplier_id" :suggestions="supplierOptions" clearable />
            <auto-complete v-if="['Asset', 'Vehicle'].includes(form.scope)" id="asset_id" :label="$t('Asset')" v-model="form.asset_id" :suggestions="assetOptions" clearable />
            <text-input v-if="form.compliance_type === 'Bank Guarantee' || form.compliance_type === 'Loan'" :label="$t('Bank')" v-model="form.bank_name" />
            <text-input v-if="form.scope === 'Contract'" :label="$t('Contract Reference')" v-model="form.contract_reference" />
          </div>
        </section>

        <!-- Bank Guarantee fields -->
        <section class="lg-card" v-if="isGuaranteeType">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-violet"><icons name="lock-closed" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Bank Guarantee Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Guarantee Number') }} / {{ $t('Bank') }} / {{ $t('Amount') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <text-input :label="$t('Guarantee Number')" v-model="form.guarantee_number" />
            <text-input :label="$t('Bank')" v-model="form.guarantee_bank" />
            <text-input :label="$t('Beneficiary')" v-model="form.guarantee_beneficiary" />
            <text-input :label="$t('Applicant')" v-model="form.guarantee_applicant" />
            <div>
              <label class="cmp-label">{{ $t('Guarantee Type') }}</label>
              <css-select v-model="form.guarantee_type" class="cmp-ctl">
                <option :value="null">-</option>
                <option v-for="t in guaranteeTypes" :key="t" :value="t">{{ $t(t) }}</option>
              </css-select>
            </div>
            <text-input type="number" :label="$t('Amount')" v-model="form.guarantee_amount" />
            <text-input :label="$t('Currency')" v-model="form.guarantee_currency" placeholder="TZS" />
            <date-picker :label="$t('Claim Expiry')" v-model="form.guarantee_claim_expiry" />
            <text-input type="number" :label="$t('Commission')" v-model="form.guarantee_commission" />
            <div class="lg-span-all">
              <label class="cmp-label">{{ $t('Purpose') }}</label>
              <textarea v-model="form.guarantee_purpose" rows="2" class="cmp-ctl"></textarea>
            </div>
            <div class="lg-span-all">
              <label class="cmp-label">{{ $t('Renewal Terms') }}</label>
              <textarea v-model="form.guarantee_renewal_terms" rows="2" class="cmp-ctl"></textarea>
            </div>
          </div>
        </section>

        <!-- Loan fields -->
        <section class="lg-card" v-if="form.compliance_type === 'Loan'">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Loan Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Lender') }} / {{ $t('Principal Amount') }} / {{ $t('Interest Rate %') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <text-input :label="$t('Lender')" v-model="form.loan_lender" />
            <text-input :label="$t('Loan Reference')" v-model="form.loan_reference" />
            <text-input :label="$t('Facility Type')" v-model="form.loan_facility_type" />
            <text-input type="number" :label="$t('Principal Amount')" v-model="form.loan_principal_amount" />
            <text-input :label="$t('Currency')" v-model="form.loan_currency" placeholder="TZS" />
            <text-input type="number" :label="$t('Interest Rate %')" v-model="form.loan_interest_rate" />
            <date-picker :label="$t('Start Date')" v-model="form.loan_start_date" />
            <date-picker :label="$t('Maturity Date')" v-model="form.loan_maturity_date" />
            <div>
              <label class="cmp-label">{{ $t('Instalment Frequency') }}</label>
              <css-select v-model="form.loan_instalment_frequency" class="cmp-ctl">
                <option :value="null">-</option>
                <option value="Monthly">{{ $t('Monthly') }}</option>
                <option value="Quarterly">{{ $t('Quarterly') }}</option>
                <option value="Semi-Annual">{{ $t('Semi-Annual') }}</option>
                <option value="Annual">{{ $t('Annual') }}</option>
              </css-select>
            </div>
            <text-input type="number" :label="$t('Instalment Amount')" v-model="form.loan_instalment_amount" />
            <text-input type="number" :label="$t('Outstanding Balance')" v-model="form.loan_outstanding_balance" />
            <date-picker :label="$t('Next Payment Date')" v-model="form.loan_next_payment_date" />
            <div class="lg-span-all">
              <label class="cmp-label">{{ $t('Security') }}</label>
              <textarea v-model="form.loan_security" rows="2" class="cmp-ctl"></textarea>
            </div>
          </div>
        </section>

        <!-- Insurance fields -->
        <section class="lg-card" v-if="form.compliance_type === 'Insurance' || form.compliance_type === 'Vehicle Insurance'">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-rose"><icons name="heart" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Insurance Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Policy Number') }} / {{ $t('Provider') }} / {{ $t('Premium') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <text-input :label="$t('Policy Number')" v-model="form.insurance_policy_number" />
            <text-input :label="$t('Provider')" v-model="form.insurance_provider" />
            <text-input :label="$t('Policy Type')" v-model="form.insurance_policy_type" />
            <text-input :label="$t('Insured Party')" v-model="form.insurance_insured_party" />
            <text-input type="number" :label="$t('Insured Value')" v-model="form.insurance_insured_value" />
            <text-input type="number" :label="$t('Premium')" v-model="form.insurance_premium" />
          </div>
        </section>

        <!-- Contract fields -->
        <section class="lg-card" v-if="form.compliance_type === 'Contract' || form.compliance_type === 'Lease'">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Contract Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Contract Number') }} / {{ $t('Contract Value') }} / {{ $t('Parties') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <text-input :label="$t('Contract Number')" v-model="form.contract_number" />
            <text-input :label="$t('Contract Type')" v-model="form.contract_type" />
            <text-input type="number" :label="$t('Contract Value')" v-model="form.contract_value" />
            <text-input type="number" :label="$t('Notice Period (days)')" v-model="form.contract_notice_period_days" />
            <div class="lg-span-all">
              <label class="cmp-label">{{ $t('Parties') }}</label>
              <textarea v-model="form.contract_parties" rows="2" class="cmp-ctl"></textarea>
            </div>
            <div class="lg-span-all">
              <label class="cmp-label">{{ $t('Renewal Terms') }}</label>
              <textarea v-model="form.contract_renewal_terms" rows="2" class="cmp-ctl"></textarea>
            </div>
          </div>
        </section>

        <!-- Licence / Certificate fields -->
        <section class="lg-card" v-if="isLicenceOrCertificateType">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="key" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Licence / Certificate Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Holder') }} / {{ $t('Renewal Fee') }} / {{ $t('Renewal Requirements') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <text-input :label="$t('Holder')" v-model="form.licence_or_certificate_holder" />
            <text-input type="number" :label="$t('Renewal Fee')" v-model="form.renewal_fee" />
            <div class="lg-span-all">
              <label class="cmp-label">{{ $t('Renewal Requirements') }}</label>
              <textarea v-model="form.renewal_requirements" rows="2" class="cmp-ctl"></textarea>
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-pink"><icons name="bell" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Reminders & Recipients') }}</h2>
              <p class="lg-card-sub">{{ $t('Escalation Recipient') }} / {{ $t('Reminder Intervals') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <auto-complete id="escalation_recipient_user_id" :label="$t('Escalation Recipient')" v-model="form.escalation_recipient_user_id" :suggestions="userOptions" clearable />
            <text-input :label="$t('Responsible Role')" v-model="form.responsible_role" />
            <div class="lg-span-all">
              <label class="cmp-label">{{ $t('Reminder Intervals (days before expiry, comma-separated)') }}</label>
              <input v-model="reminderIntervalsText" type="text" class="cmp-ctl" placeholder="90, 60, 30, 14, 7, 3, 1, 0" />
              <p class="cmp-hint">{{ $t('Leave blank to use the account default: 90, 60, 30, 14, 7, 3, 1, 0.') }}</p>
            </div>
          </div>
        </section>

        <section class="lg-card ar-section" :class="{ 'is-on': form.auto_renew }">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="refresh" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Auto Renewal') }}</h2>
              <p class="lg-card-sub">{{ $t('Renew this record by itself on its expiry date and tell the owners before it happens.') }}</p>
            </div>
          </header>
          <label class="ar-toggle" :class="{ 'is-locked': !autoRenewal.can }">
            <input type="checkbox" :checked="form.auto_renew" :disabled="!autoRenewal.can" @change="onAutoToggle($event)" />
            <span class="ar-toggle-text"><b>{{ $t('Renew this record automatically') }}</b><small v-if="autoRenewal.can">{{ $t('You choose after how many days and when to be told.') }}</small><small v-else><icons name="lock-closed" class="w-3 h-3" /> {{ $t('Only a Super Admin, or a role given "Set Up Auto Renewal", can change this.') }}</small></span>
          </label>
          <div v-if="form.auto_renew" class="ar-summary">
            <span class="ar-pill is-teal"><icons name="refresh" class="w-4 h-4" />{{ $t('Renews for :n days', { n: form.auto_renew_days }) }}</span>
            <span v-for="n in form.auto_renew_notify_days" :key="n" class="ar-pill is-amber"><icons name="bell" class="w-4 h-4" />{{ n === 0 ? $t('Told on the day') : $t('Told :n day(s) before', { n }) }}</span>
            <button v-if="autoRenewal.can" type="button" class="ar-edit" @click="arOpen = true"><icons name="edit" class="w-4 h-4" />{{ $t('Change') }}</button>
            <p v-if="$page.props.errors.auto_renew || $page.props.errors.auto_renew_days" class="ar-err">{{ $page.props.errors.auto_renew || $page.props.errors.auto_renew_days }}</p>
          </div>
          <auto-renewal-modal :show="arOpen" :title="form.title" :days="form.auto_renew_days" :notify="form.auto_renew_notify_days" :expiry="form.expiry_date" :can-turn-off="!!form.auto_renew" @save="saveAuto" @cancel="cancelAuto" @turnoff="turnOffAuto" />
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
              <p class="lg-card-sub">{{ $t('Certificates, policies, contracts, photos') }}</p>
            </div>
          </header>
          <attachment-picker v-model="selectedFiles" :max-files="10" :help-text="$t('Certificates, policies, contracts, photos: PDF, DOC, DOCX, JPG, JPEG, or PNG, up to 10 files.')" />
          <ul v-if="record && record.attachments && record.attachments.length" class="cmp-files">
            <li v-for="file in record.attachments" :key="file.id" class="cmp-file">
              <span class="cmp-file-name">{{ file.title }}</span>
              <div class="cmp-file-actions">
                <a
                  :href="file.preview_url"
                  target="_blank"
                  class="cmp-att-icon-btn lg-tip"
                  :data-tip="$t('Preview')"
                  @mouseenter="showAttachmentZoom($event, file)"
                  @mouseleave="hidePhotoZoom"
                >
                  <icons name="eye-flat" class="w-4 h-4 flex-none" />
                </a>
                <a :href="file.download_url" class="cmp-att-icon-btn lg-tip" :data-tip="$t('Download')">
                  <icons name="download" class="w-4 h-4 flex-none" />
                </a>
              </div>
            </li>
          </ul>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-slate"><icons name="edit" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Notes') }}</h2>
              <p class="lg-card-sub">{{ $t('Additional notes') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div class="lg-span-all"><textarea v-model="form.notes" rows="3" class="cmp-ctl"></textarea></div>
          </div>
        </section>

        <div class="lg-actionbar">
          <div class="lg-actionbar-btns">
            <Link :href="route('compliance-records.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" class="lg-btn lg-btn-primary" :disabled="processing">
              <icons :name="processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': processing }" /> {{ record ? $t('Save Changes') : $t('Create Record') }}
            </button>
          </div>
        </div>
      </form>
    </div>

    <teleport to="body">
      <div v-if="photoZoom.show" class="cmp-photo-zoom cmp-photo-zoom-show" :style="{ left: photoZoom.x + 'px', top: photoZoom.y + 'px' }">
        <img v-if="photoZoom.isImage" :src="photoZoom.src" alt="" />
        <div v-else class="cmp-photo-zoom-file">
          <icons name="doc" class="w-8 h-8" />
          <span>{{ $t('No preview available') }}</span>
        </div>
        <div v-if="photoZoom.name" class="cmp-photo-zoom-name">{{ photoZoom.name }}</div>
      </div>
    </teleport>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import TextInput from '@/Shared/TextInput.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AttachmentPicker from '@/Shared/AttachmentPicker.vue';
import AutoRenewalModal from './AutoRenewalModal.vue';

export default {
  components: { AdminLayout, PageHeader, AutoComplete, TextInput, DatePicker, AttachmentPicker, AutoRenewalModal },

  props: {
    record: Object,
    categories: Array,
    warehouses: Array,
    suppliers: Array,
    assets: Array,
    users: Array,
    statuses: Array,
    priorities: Array,
    types: Array,
    authorities: Array,
    priorities: Array,
    scopes: Array,
    guaranteeTypes: Array,
    autoRenewal: { type: Object, default: () => ({ can: false, choices: [] }) },
  },

  data() {
    const r = this.record || {};
    return {
      processing: false,
      selectedFiles: [],
      photoFile: null,
      photoRemoved: false,
      photoZoom: { show: false, src: null, name: '', isImage: true, x: 0, y: 0 },
      arOpen: false,
      reminderIntervalsText: (r.reminder_intervals || []).join(', '),
      form: {
        compliance_type: r.compliance_type || this.types[0],
        compliance_category_id: r.compliance_category_id || null,
        title: r.title || '',
        description: r.description || '',
        reference_number: r.reference_number || '',
        issuing_authority: r.issuing_authority || '',
        issued_to: r.issued_to || '',
        responsible_user_id: r.responsible_user_id || null,
        department: r.department || '',

        issue_date: r.issue_date || '',
        effective_date: r.effective_date || '',
        expiry_date: r.expiry_date || '',
        renewal_date: r.renewal_date || '',
        submission_date: r.submission_date || '',
        next_review_date: r.next_review_date || '',

        warehouse_id: r.warehouse_id || null,
        supplier_id: r.supplier_id || null,
        contact_id: r.contact_id || null,
        asset_id: r.asset_id || null,
        bank_name: r.bank_name || '',
        contract_reference: r.contract_reference || '',

        scope: r.scope || 'Company',
        priority: r.priority || 'Normal',
        priority_is_manual_override: r.priority_is_manual_override || false,

        guarantee_number: r.guarantee_number || '',
        guarantee_bank: r.guarantee_bank || '',
        guarantee_beneficiary: r.guarantee_beneficiary || '',
        guarantee_applicant: r.guarantee_applicant || '',
        guarantee_type: r.guarantee_type || null,
        guarantee_amount: r.guarantee_amount || '',
        guarantee_currency: r.guarantee_currency || 'TZS',
        guarantee_claim_expiry: r.guarantee_claim_expiry || '',
        guarantee_purpose: r.guarantee_purpose || '',
        guarantee_commission: r.guarantee_commission || '',
        guarantee_renewal_terms: r.guarantee_renewal_terms || '',

        loan_lender: r.loan_lender || '',
        loan_reference: r.loan_reference || '',
        loan_facility_type: r.loan_facility_type || '',
        loan_principal_amount: r.loan_principal_amount || '',
        loan_currency: r.loan_currency || 'TZS',
        loan_interest_rate: r.loan_interest_rate || '',
        loan_start_date: r.loan_start_date || '',
        loan_maturity_date: r.loan_maturity_date || '',
        loan_instalment_frequency: r.loan_instalment_frequency || null,
        loan_instalment_amount: r.loan_instalment_amount || '',
        loan_outstanding_balance: r.loan_outstanding_balance || '',
        loan_next_payment_date: r.loan_next_payment_date || '',
        loan_security: r.loan_security || '',

        insurance_policy_number: r.insurance_policy_number || '',
        insurance_provider: r.insurance_provider || '',
        insurance_policy_type: r.insurance_policy_type || '',
        insurance_insured_party: r.insurance_insured_party || '',
        insurance_insured_value: r.insurance_insured_value || '',
        insurance_premium: r.insurance_premium || '',

        contract_number: r.contract_number || '',
        contract_type: r.contract_type || '',
        contract_parties: r.contract_parties || '',
        contract_value: r.contract_value || '',
        contract_notice_period_days: r.contract_notice_period_days || '',
        contract_renewal_terms: r.contract_renewal_terms || '',

        licence_or_certificate_holder: r.licence_or_certificate_holder || '',
        renewal_fee: r.renewal_fee || '',
        renewal_requirements: r.renewal_requirements || '',

        is_recurring: r.is_recurring || false,
        recurrence_frequency: r.recurrence_frequency || null,
        recurrence_custom_days: r.recurrence_custom_days || '',

        auto_renew: !!r.auto_renew,
        auto_renew_days: r.auto_renew_days || '',
        auto_renew_notify_days: r.auto_renew_notify_days || [],
        escalation_recipient_user_id: r.escalation_recipient_user_id || null,
        additional_recipient_user_ids: r.additional_recipient_user_ids || [],
        responsible_role: r.responsible_role || '',

        notes: r.notes || '',
      },
    };
  },

  computed: {
    categoryOptions() {
      return this.categories.map(c => ({ label: `${c.group} / ${c.name}`, value: c.id }));
    },
    warehouseOptions() {
      return this.warehouses.map(w => ({ label: w.name, value: w.id }));
    },
    supplierOptions() {
      return this.suppliers.map(s => ({ label: s.name, value: s.id }));
    },
    assetOptions() {
      return this.assets.map(a => ({ label: `${a.asset_number} / ${a.name}`, value: a.id }));
    },
    userOptions() {
      return this.users.map(u => ({ label: u.name, value: u.id }));
    },
    isGuaranteeType() {
      return ['Bank Guarantee', 'Performance Guarantee', 'Advance Payment Guarantee', 'Bid Bond'].includes(this.form.compliance_type);
    },
    isLicenceOrCertificateType() {
      return ['Business Licence', 'Contractor Licence', 'Vehicle Licence', 'Professional Certificate', 'Employee Certificate', 'Tax Certificate', 'Safety Certification', 'Equipment Certification'].includes(this.form.compliance_type);
    },
    photoPreviewUrl() {
      if (this.photoRemoved) return null;
      if (this.photoFile) return URL.createObjectURL(this.photoFile);
      return this.record && this.record.photo_url ? this.record.photo_url : null;
    },
  },

  methods: {
    onPhotoChosen(e) {
      const file = e.target.files && e.target.files[0];
      if (file) {
        this.photoFile = file;
        this.photoRemoved = false;
      }
    },
    async removePhoto() {
      if (!this.photoFile && this.record && this.record.photo_url) {
        if (!(await this.$confirm(this.$t('Remove the current photo?'), { variant: 'danger' }))) {
          return;
        }
        this.$inertia.delete(this.route('compliance-records.photo.destroy', this.record.id), {
          preserveScroll: true,
          onSuccess: () => {
            this.record.photo_url = null;
          },
        });
        return;
      }
      this.photoFile = null;
      this.photoRemoved = true;
      this.$refs.photoInput.value = '';
    },
    showAttachmentZoom(event, file) {
      const rect = event.currentTarget.getBoundingClientRect();
      this.photoZoom = { show: true, src: file.preview_url, name: file.title, isImage: file.is_image, x: rect.right + 14, y: rect.top + rect.height / 2 };
    },
    hidePhotoZoom() {
      this.photoZoom.show = false;
    },
    onAutoToggle(e) {
      const on = e.target.checked;
      // the box only shows what is saved on the form: it ticks once the settings are saved in the dialog
      e.target.checked = !!this.form.auto_renew;
      if (on) { this.arOpen = true; } else { this.turnOffAuto(); }
    },
    saveAuto(v) { this.form.auto_renew = true; this.form.auto_renew_days = v.days; this.form.auto_renew_notify_days = v.notify; this.arOpen = false; },
    cancelAuto() { this.arOpen = false; if (!this.form.auto_renew_days) this.form.auto_renew = false; },
    turnOffAuto() { this.form.auto_renew = false; this.form.auto_renew_days = ''; this.form.auto_renew_notify_days = []; this.arOpen = false; },
    submit() {
      this.processing = true;
      const payload = { ...this.form };
      payload.reminder_intervals = this.reminderIntervalsText
        .split(',')
        .map(s => parseInt(s.trim(), 10))
        .filter(n => !Number.isNaN(n));
      payload.photo = this.photoFile;
      payload.attachments = this.selectedFiles;
      if (this.record) {
        payload._method = 'put';
      }

      const url = this.record ? this.route('compliance-records.update', this.record.id) : this.route('compliance-records.store');
      this.$inertia.post(url, payload, {
        forceFormData: true,
        onFinish: () => (this.processing = false),
      });
    },
  },
};
</script>

<style scoped>
.cmp-form :deep(.lg-fields label:not(:has(input[type='checkbox']))),
.cmp-label {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
}
.cmp-label { margin-bottom: 0.25rem; }
.cmp-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file'])),
.cmp-form :deep(.lg-fields select),
.cmp-form :deep(.lg-fields textarea),
.cmp-ctl {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  padding-top: 0.6rem !important;
  padding-bottom: 0.6rem !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.cmp-ctl { display: block; width: 100%; border: 1px solid var(--lg-line); padding-left: 0.75rem; padding-right: 0.75rem; font-size: 0.875rem; }
.cmp-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file']):hover),
.cmp-form :deep(.lg-fields select:hover),
.cmp-ctl:hover {
  border-color: rgba(99, 102, 241, 0.6) !important;
}
.cmp-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file']):focus),
.cmp-form :deep(.lg-fields select:focus),
.cmp-form :deep(.lg-fields textarea:focus),
.cmp-ctl:focus {
  outline: none;
  border-color: #6366f1 !important;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.18) !important;
}
.cmp-ctl:disabled { opacity: 0.6; cursor: not-allowed; }
.cmp-check { display: flex; align-items: center; gap: 0.4rem; margin-top: 0.45rem; font-size: 0.74rem; line-height: 1.4; color: var(--lg-mute); cursor: pointer; }
.cmp-check input { flex: none; accent-color: #4338ca; }
.cmp-hint { margin-top: 0.3rem; font-size: 0.74rem; color: var(--lg-mute); }

.cmp-photo-picker {
  position: relative; width: 200px; height: 200px; border-radius: 22px; border: 2px dashed rgba(99, 102, 241, 0.45); background: rgba(99, 102, 241, 0.06);
  display: flex; align-items: center; justify-content: center; cursor: pointer; overflow: hidden; transition: border-color 0.15s ease, transform 0.15s ease; flex-shrink: 0;
}
.cmp-ident { display: grid; grid-template-columns: 200px minmax(0, 1fr); gap: 1.4rem; align-items: start; }
.cmp-photo-col { display: flex; flex-direction: column; align-items: center; gap: 8px; }
.cmp-photo-hint { font-size: 0.7rem; color: var(--lg-faint); text-align: center; }
.cmp-ident-fields { min-width: 0; }
.cmp-photo-placeholder { font-size: 0.8rem !important; }
@media (max-width: 767.98px) { .cmp-ident { grid-template-columns: minmax(0, 1fr); } .cmp-photo-picker { width: 160px !important; height: 160px !important; } }
.cmp-photo-picker:hover { border-color: #818cf8; transform: translateY(-1px); }
.cmp-photo-img { width: 100%; height: 100%; object-fit: cover; }
.cmp-photo-placeholder { font-size: 0.64rem; font-weight: 800; letter-spacing: 0.05em; color: #6366f1; }
.cmp-photo-remove {
  position: absolute; top: 4px; right: 4px; display: inline-flex; align-items: center; justify-content: center;
  width: 20px; height: 20px; border-radius: 9999px; background: rgba(190, 18, 60, 0.9); color: #fff;
}

.cmp-files { margin-top: 0.9rem; border: 1px solid var(--lg-line); border-radius: 16px; background: var(--lg-soft); }
.cmp-file { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 0.55rem 0.8rem; font-size: 0.84rem; }
.cmp-file + .cmp-file { border-top: 1px solid var(--lg-line); }
.cmp-file-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--lg-ink); }
.cmp-file-actions { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
.cmp-att-icon-btn {
  display: inline-flex; align-items: center; justify-content: center; width: 1.85rem; height: 1.85rem; flex-shrink: 0;
  color: var(--lg-mute); background: var(--lg-card); border: 1px solid var(--lg-line); border-radius: 0.5rem; transition: background 0.15s ease, color 0.15s ease, transform 0.15s ease;
}
.cmp-att-icon-btn:hover { background: var(--lg-soft); color: var(--lg-ink); transform: translateY(-1px); }

.cmp-photo-zoom {
  position: fixed; transform: translate(0, -50%) scale(0.85); z-index: 9999;
  background: #fff; border-radius: 1rem; padding: 0.6rem; box-shadow: 0 20px 40px -10px rgba(15, 23, 42, 0.35), 0 0 0 1px rgba(15, 23, 42, 0.05);
  opacity: 0; pointer-events: none; transition: opacity 0.16s ease, transform 0.16s ease; max-width: 220px;
}
.cmp-photo-zoom-show { opacity: 1; transform: translate(0, -50%) scale(1); }
.cmp-photo-zoom img { display: block; width: 200px; max-height: 260px; object-fit: contain; border-radius: 0.7rem; background: #f1f5f9; }
.cmp-photo-zoom-file {
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.4rem; width: 200px; height: 140px;
  background: #f1f5f9; color: #94a3b8; border-radius: 0.7rem; font-size: 0.72rem; text-align: center; padding: 0.5rem;
}
.cmp-photo-zoom-name { text-align: center; font-size: 0.75rem; font-weight: 700; color: #374151; margin-top: 0.5rem; word-break: break-word; }

html.dark .cmp-photo-picker { background: rgba(255, 255, 255, 0.04); border-color: rgba(129, 140, 248, 0.4); }
html.dark .cmp-photo-placeholder { color: #a5b4fc; }
html.dark .cmp-photo-zoom { background: #1e293b; box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.08); }
html.dark .cmp-photo-zoom-name { color: #e2e8f0; }
.ar-section.is-on { box-shadow: 0 0 0 1.5px rgba(13, 148, 136, 0.45), 0 18px 28px -18px rgba(13, 148, 136, 0.55); }
.ar-toggle { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 16px; cursor: pointer; background: linear-gradient(135deg, rgba(13, 148, 136, 0.08), transparent); box-shadow: inset 0 0 0 1px rgba(13, 148, 136, 0.25); }
.ar-toggle.is-locked { cursor: not-allowed; opacity: 0.75; }
.ar-toggle input { width: 20px; height: 20px; accent-color: #0d9488; flex: none; }
.ar-toggle-text { display: grid; gap: 2px; } .ar-toggle-text b { font-size: 0.92rem; } .ar-toggle-text small { font-size: 0.76rem; color: var(--lg-mute, #64748b); display: flex; align-items: center; gap: 4px; }
.ar-summary { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 12px; }
.ar-pill { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 999px; font-size: 0.76rem; font-weight: 800; background: var(--lg-card, #fff); }
.ar-pill.is-teal { color: #0f766e; box-shadow: inset 0 0 0 1.5px #0d9488, 0 8px 12px -8px #0d9488; } .ar-pill.is-amber { color: #b45309; box-shadow: inset 0 0 0 1.5px #d97706, 0 8px 12px -8px #d97706; }
.ar-edit { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border: 0; border-radius: 999px; cursor: pointer; font-size: 0.76rem; font-weight: 800; color: #fff; background: linear-gradient(135deg, #14b8a6, #0f766e); }
.ar-err { flex-basis: 100%; margin: 0; color: #b91c1c; font-size: 0.78rem; font-weight: 700; }
</style>

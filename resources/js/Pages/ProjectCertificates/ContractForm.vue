<template>
  <admin-layout :title="$t('Contract Setup')">
    <div class="lg-page px-4 md:px-0 pc-form pc-contract">
      <page-header icon="cog" :title="$t('Contract Details')" :subtitle="warehouse.name + ' (' + warehouse.code + ')'">
        <Link :href="route('project-certificates.project.index', warehouse.id)" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ warehouse.name }}
        </Link>
        <template v-if="contract">
          <a
            :href="route('project-certificates.contract.print', warehouse.id)" target="_blank"
            class="lg-btn lg-btn-soft lg-tip" :data-tip="$t('Print entire Contract Setup page')"
          >
            <icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}
          </a>
          <a
            :href="route('project-certificates.contract.download', warehouse.id)"
            class="lg-btn lg-btn-soft lg-tip" :data-tip="$t('Download entire Contract Setup page as PDF')"
          >
            <icons name="download" class="w-4 h-4" /> {{ $t('Download') }}
          </a>
        </template>
      </page-header>

      <stat-tiles :items="sumTiles" />

      <!-- Funding Clients -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-teal"><icons name="users" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Funding Clients') }}</h2>
            <p class="lg-card-sub">{{ $t('Who is funding this project, and in what currency · at least one is required.') }}</p>
          </div>
          <button type="button" class="lg-btn lg-btn-soft pc-sm" @click="addClientRow"><icons name="plus" class="w-3.5 h-3.5" /> {{ $t('Add Client') }}</button>
        </header>

        <div v-for="(row, idx) in form.clients" :key="idx" class="pc-client-row">
          <div class="pc-client-n">{{ idx + 1 }}</div>
          <div class="pc-client-fields">
            <div class="pc-client-field pc-client-field-name">
              <label class="pc-label">{{ $t('Client') }}</label>
              <div class="flex items-center gap-1.5">
                <auto-complete v-model="row.client_contact_id" :suggestions="clientOptions" :placeholder="$t('Select client…')" clearable class="flex-1 min-w-0" />
                <button type="button" class="lg-icon-btn lg-tip flex-none" :data-tip="$t('Add new client')" :aria-label="$t('Add new client')" @click="openContactModal('Clients', idx)"><icons name="plus" class="w-4 h-4" /></button>
              </div>
            </div>
            <div class="pc-client-field">
              <label class="pc-label">{{ $t('Currency') }}</label>
              <auto-complete v-model="row.currency" :suggestions="currencyOptions" />
            </div>
            <div class="pc-client-field">
              <label class="pc-label">{{ $t('Amount') }}</label>
              <input
                :value="displayClientAmount(idx)"
                @focus="onClientAmountFocus(idx)"
                @input="onClientAmountInput(idx, $event)"
                @blur="onClientAmountBlur(idx)"
                type="text" inputmode="decimal" class="pc-input pc-num"
              />
            </div>
            <div class="pc-client-field">
              <label class="pc-label">
                {{ $t('Agreed Exchange Rate') }}
                <span
                  class="pc-info lg-tip" tabindex="0"
                  :data-tip="$t('1 :currency = ? TZS · used to convert this client\'s amount into the Original Contract Sum. Not needed for a TZS client.', { currency: row.currency || '?' })"
                >
                  <icons name="info" class="w-3 h-3" />
                </span>
              </label>
              <input
                v-if="!isTzsRow(row)" v-model.number="row.exchange_rate" type="number" step="0.000001" min="0"
                class="pc-input pc-num" :placeholder="$t('Rate for the day')"
              />
              <input v-else type="text" class="pc-input is-readonly" value="—" disabled />
            </div>
            <div class="pc-client-field">
              <label class="pc-label">{{ $t('Amount in TZS') }}</label>
              <div class="pc-client-tzs">
                {{ clientAmountInTzs(row) !== null ? 'TZS ' + clientAmountInTzs(row).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : $t('Enter exchange rate') }}
              </div>
            </div>
          </div>
          <button v-if="form.clients.length > 1" type="button" class="lg-icon-btn is-red pc-client-rm" :aria-label="$t('Remove')" @click="removeClientRow(idx)"><icons name="trash" class="w-4 h-4" /></button>
        </div>

        <div class="pc-funding-total">
          <span>{{ $t('Total Funding (Original Contract Sum)') }}</span>
          <span class="pc-funding-total-value">TZS {{ totalClientsInTzs.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</span>
        </div>
      </section>

      <!-- Contract terms -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Contract Details') }}</h2>
            <p class="lg-card-sub">{{ warehouse.name }} ({{ warehouse.code }})</p>
          </div>
        </header>

        <div class="lg-fields">
          <div>
            <div class="flex items-center justify-between gap-2">
              <label class="pc-label">{{ $t('Consultant') }}</label>
              <button type="button" class="pc-linkbtn" @click="openContactModal('Consultants')"><icons name="plus" class="w-3 h-3" /> {{ $t('Add Consultant') }}</button>
            </div>
            <auto-complete v-model="form.consultant_contact_id" :suggestions="consultantOptions" :placeholder="$t('None')" clearable />
          </div>
          <div>
            <label class="pc-label">{{ $t('Contractor Name') }}</label>
            <input v-model="form.contractor_name" type="text" class="pc-input" />
          </div>
          <div>
            <label class="pc-label">{{ $t('Contract Reference') }}</label>
            <input v-model="form.contract_reference" type="text" class="pc-input" />
          </div>
          <div>
            <label class="pc-label">
              {{ $t('Currency') }}
              <span
                class="pc-info lg-tip" tabindex="0"
                :data-tip="$t('Always TZS · this contract can now be funded by multiple clients, each in their own currency (see Funding Clients above), so its own reporting currency is fixed to keep every total comparable.')"
              >
                <icons name="info" class="w-3 h-3" />
              </span>
            </label>
            <input value="TZS" type="text" class="pc-input" disabled />
          </div>
          <div>
            <!-- An invisible label-height spacer — same .pc-label row as
                 every sibling field, so the checkbox below starts at the
                 same y as Contract VAT (TAX)'s own rate input. -->
            <label class="pc-label" aria-hidden="true">&nbsp;</label>
            <label class="pc-check">
              <input v-model="form.vat_included" type="checkbox" class="pc-checkbox" />
              {{ $t('Include VAT') }}
              <span
                class="pc-info lg-tip" tabindex="0"
                :data-tip="$t('When on, the amounts above are treated as already including VAT · WIMS backs out the VAT-exclusive figure. When off, they are treated as VAT-exclusive and VAT is added on top. Either way, the rate used comes from Contract VAT (TAX).')"
              >
                <icons name="info" class="w-3 h-3" />
              </span>
            </label>
          </div>
          <div>
            <label class="pc-label">
              {{ $t('Contract VAT (TAX)') }}
              <span
                class="pc-info lg-tip" tabindex="0"
                :data-tip="$t('The VAT rate applied throughout this project · certificate calculations, the addendum totals below, and Include VAT above all read from this one rate.')"
              >
                <icons name="info" class="w-3 h-3" />
              </span>
            </label>
            <div class="flex items-center gap-3 flex-wrap">
              <div class="pc-vat-rate-field" :class="{ 'is-off': form.vat_exempted }">
                <input
                  :value="displayVatRate"
                  @focus="onVatRateFocus"
                  @input="onVatRateInput"
                  @blur="onVatRateBlur"
                  :disabled="form.vat_exempted"
                  type="text" inputmode="decimal" class="pc-input pc-input-sm pc-num"
                />
                <span class="pc-vat-rate-suffix">%</span>
              </div>
              <label class="pc-check">
                <input v-model="form.vat_exempted" type="checkbox" class="pc-checkbox" />
                {{ $t('VAT Exempted') }}
              </label>
            </div>
          </div>
          <div>
            <label class="pc-label">
              {{ $t('Original Contract Sum (TZS)') }}
              <span
                class="pc-info lg-tip" tabindex="0"
                :data-tip="$t('Computed from every Funding Client above, converted to TZS · no longer typed directly. Add or edit clients to change it.')"
              >
                <icons name="info" class="w-3 h-3" />
              </span>
            </label>
            <div class="pc-input is-readonly pc-num">TZS {{ totalClientsInTzs.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</div>
          </div>
          <div>
            <label class="pc-label">{{ $t('Contract Variation Amount') }}</label>
            <input
              :value="displayAmount('contract_variation_amount')"
              @focus="onAmountFocus('contract_variation_amount')"
              @input="onAmountInput('contract_variation_amount', $event)"
              @blur="onAmountBlur('contract_variation_amount')"
              type="text" inputmode="decimal" class="pc-input pc-num"
            />
          </div>
          <div>
            <label class="pc-label">{{ $t('Contract Start Date') }}</label>
            <date-picker v-model="form.contract_start_date" class="w-full" />
          </div>
          <div>
            <label class="pc-label">{{ $t('Contract End Date') }}</label>
            <date-picker v-model="form.contract_end_date" class="w-full" />
          </div>
        </div>
      </section>

      <!-- Attachments -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="clip" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
            <p class="lg-card-sub">{{ $t('Signed contracts, correspondence, or other supporting documents · up to 10 files.') }}</p>
          </div>
        </header>
        <ul v-if="existingAttachments.length" class="pc-filelist mb-3">
          <li v-for="file in existingAttachments" :key="file.id">
            <span>{{ file.name }}</span>
            <div class="flex items-center gap-1 flex-none">
              <a :href="file.download_url" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')">
                <icons name="download" class="w-4 h-4" />
              </a>
              <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="removeAttachment(file)">
                <icons name="trash" class="w-4 h-4" />
              </button>
            </div>
          </li>
        </ul>
        <attachment-picker v-model="newAttachments" :max-files="10" :help-text="$t('Signed contracts, correspondence, or other supporting documents · up to 10 files.')" />
      </section>

      <div class="lg-actionbar pc-bar">
        <span class="lg-actionbar-note">{{ warehouse.name }} ({{ warehouse.code }})</span>
        <div class="lg-actionbar-btns">
          <button type="button" class="lg-btn lg-btn-primary" :disabled="saving" @click="save">
            <icons name="tick" class="w-4 h-4" /> {{ saving ? $t('Saving…') : $t('Save Contract Details') }}
          </button>
        </div>
      </div>

      <!-- Addendums -->
      <section v-if="contract" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Contract Addendums') }}</h2>
            <p class="lg-card-sub">{{ $t('Variations to the original contract sum, itemized and tracked separately.') }}</p>
          </div>
          <Link v-if="canCreateAddendum" :href="route('project-certificates.contract.addendums.create', warehouse.id)" class="lg-btn lg-btn-soft pc-sm">
            <icons name="plus" class="w-3.5 h-3.5" /> {{ $t('Add Contract Addendum') }}
          </Link>
        </header>

        <div v-if="contract.addendums && contract.addendums.length" class="lg-table-wrap mb-4">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th style="width: 60px;">{{ $t('No.') }}</th>
                <th>{{ $t('Title') }}</th>
                <th>{{ $t('Date') }}</th>
                <th class="num">{{ $t('Amount') }}</th>
                <th>{{ $t('Status') }}</th>
                <th class="num">{{ $t('Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="a in contract.addendums" :key="a.id" class="is-click"
                @click="$inertia.visit(route('project-certificates.contract.addendums.show', [warehouse.id, a.id]))"
              >
                <td class="lg-main" :data-label="$t('No.')">#{{ a.addendum_number }}</td>
                <td class="pc-wrap" :data-label="$t('Title')">{{ a.title }}</td>
                <td class="pc-faint" :data-label="$t('Date')">{{ a.addendum_date || '—' }}</td>
                <td class="num lg-main" :data-label="$t('Amount')">{{ form.currency }} {{ Number(a.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="statusTone(a.status)">{{ a.status.replace('_', ' ') }}</span></td>
                <td class="num" :data-label="$t('Actions')" @click.stop>
                  <div class="lg-actions">
                    <a
                      :href="route('project-certificates.contract.addendums.print', [warehouse.id, a.id])" target="_blank"
                      class="lg-icon-btn lg-tip" :data-tip="$t('Print')" :aria-label="$t('Print')"
                    >
                      <icons name="printer" class="w-4 h-4" />
                    </a>
                    <a
                      :href="route('project-certificates.contract.addendums.download', [warehouse.id, a.id])"
                      class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"
                    >
                      <icons name="download" class="w-4 h-4" />
                    </a>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="lg-empty pc-empty-box mb-4">{{ $t('No addendums recorded for this contract yet.') }}</div>

        <div class="pc-totals">
          <div class="pc-total-tile">
            <span class="pc-total-label">
              {{ $t('Total Contract Sum (VAT Exclusive)') }}
              <span
                class="pc-info lg-tip" tabindex="0"
                :data-tip="$t('Same basis as Revised Contract Sum (VAT Exclusive) above, so it adds cleanly with addendum amounts (which carry no VAT of their own).')"
              >
                <icons name="info" class="w-3 h-3" />
              </span>
            </span>
            <span class="pc-total-value">{{ form.currency }} {{ Number(addendumStats.total_contract_sum || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</span>
            <div class="pc-vat-box">
              <template v-if="form.vat_exempted">
                <span class="pc-vat-note">{{ $t('This Project is VAT Exempted') }}</span>
              </template>
              <template v-else>
                <span class="pc-vat-label">{{ $t('VAT Inclusive') }} ({{ displayVatRate }}%)</span>
                <span class="pc-vat-value">{{ form.currency }} {{ vatInclusive(addendumStats.total_contract_sum).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</span>
              </template>
            </div>
          </div>
          <div class="pc-total-tile">
            <span class="pc-total-label">{{ $t('Total Addendum Sum (VAT Exclusive)') }}</span>
            <span class="pc-total-value is-indigo">{{ form.currency }} {{ Number(addendumStats.total_addendum_sum || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</span>
            <div class="pc-vat-box">
              <template v-if="form.vat_exempted">
                <span class="pc-vat-note">{{ $t('This Project is VAT Exempted') }}</span>
              </template>
              <template v-else>
                <span class="pc-vat-label">{{ $t('VAT Inclusive') }} ({{ displayVatRate }}%)</span>
                <span class="pc-vat-value">{{ form.currency }} {{ vatInclusive(addendumStats.total_addendum_sum).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</span>
              </template>
            </div>
          </div>
          <div class="pc-total-tile">
            <span class="pc-total-label">{{ $t('Total Project Amount (VAT Exclusive)') }}</span>
            <span class="pc-total-value is-rose">{{ form.currency }} {{ Number(addendumStats.total_project_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</span>
            <div class="pc-vat-box">
              <template v-if="form.vat_exempted">
                <span class="pc-vat-note">{{ $t('This Project is VAT Exempted') }}</span>
              </template>
              <template v-else>
                <span class="pc-vat-label">{{ $t('VAT Inclusive') }} ({{ displayVatRate }}%)</span>
                <span class="pc-vat-value">{{ form.currency }} {{ vatInclusive(addendumStats.total_project_amount).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</span>
              </template>
            </div>
          </div>
        </div>
      </section>

      <!-- Certificates -->
      <section id="project-certificates-section" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="collection" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Project Certificates') }}</h2>
          </div>
          <Link v-if="contract" :href="route('project-certificates.create', warehouse.id)" class="lg-btn lg-btn-soft pc-sm"><icons name="plus" class="w-3.5 h-3.5" /> {{ $t('Create Certificate') }}</Link>
        </header>
        <div v-if="certificates.length" class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('Date') }}</th>
                <th class="num">{{ $t('Net Payment') }}</th>
                <th>{{ $t('Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in certificates" :key="c.id" class="is-click" @click="$inertia.visit(route('project-certificates.show', c.id))">
                <td class="lg-main" :data-label="$t('Reference')">{{ c.reference || ('#' + c.certificate_number) }}<span v-if="c.version_letter" class="pc-faint">-{{ c.version_letter }}</span></td>
                <td class="pc-faint" :data-label="$t('Date')">{{ c.certificate_date || '—' }}</td>
                <td class="num" :class="{ 'pc-faint': c.status !== 'issued' }" :data-label="$t('Net Payment')">
                  {{ c.status === 'issued' ? (c.currency || form.currency) + ' ' + Number(c.net_payment_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }) : $t('Not yet issued') }}
                </td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="statusTone(c.status)">{{ c.status }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="lg-empty pc-empty-box">{{ $t('No certificates created for this project yet.') }}</div>
      </section>
    </div>

    <!-- Inline "Add Client/Consultant" modal -->
    <Teleport to="body">
      <div v-if="contactModalOpen" class="pc-overlay lg-page pc-form" @click.self="contactModalOpen = false">
        <div class="pc-dialog" role="dialog" aria-modal="true">
          <header class="pc-dialog-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="users" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Add') }} {{ contactModalCategory === 'Clients' ? $t('Client') : $t('Consultant') }}</h2>
            </div>
            <button type="button" class="lg-icon-btn" :aria-label="$t('Close')" @click="contactModalOpen = false"><icons name="cross" class="w-4 h-4" /></button>
          </header>

          <div class="pc-dialog-body">
            <div class="lg-fields">
              <div class="lg-span-all">
                <label class="pc-label">{{ $t('Client Name') }} <span class="pc-req">*</span></label>
                <input v-model="contactModal.name" type="text" class="pc-input" @keyup.enter="submitContact" />
              </div>
              <div>
                <label class="pc-label">{{ $t('Mobile') }}</label>
                <input v-model="contactModal.phone" type="text" class="pc-input" :placeholder="$t('e.g. +255 7XX XXX XXX')" @keyup.enter="submitContact" />
              </div>
              <div>
                <label class="pc-label">{{ $t('Email') }}</label>
                <input v-model="contactModal.email" type="email" class="pc-input" @keyup.enter="submitContact" />
              </div>
            </div>
            <div v-if="contactModalError" class="lg-note lg-note-red mt-3"><icons name="exclamation" class="w-4 h-4" /><span>{{ contactModalError }}</span></div>
          </div>

          <footer class="pc-dialog-foot">
            <button type="button" class="lg-btn lg-btn-soft" @click="contactModalOpen = false"><icons name="cross" class="w-4 h-4" /> {{ $t('Cancel') }}</button>
            <button type="button" class="lg-btn lg-btn-primary" :disabled="!contactModal.name || contactModalSaving" @click="submitContact">
              <icons name="tick" class="w-4 h-4" /> {{ contactModalSaving ? $t('Saving…') : $t('Add') }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>

    <!-- Success/error toast for the quick-add-contact action -->
    <Teleport to="body">
      <transition name="pc-toast-fade">
        <div v-if="toast.visible" class="pc-toast" :class="toast.type === 'error' ? 'pc-toast-error' : 'pc-toast-success'" role="status">
          <icons :name="toast.type === 'error' ? 'cross' : 'tick'" class="w-4 h-4 flex-none" />
          <span>{{ toast.text }}</span>
        </div>
      </transition>
    </Teleport>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AttachmentPicker from '@/Shared/AttachmentPicker.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, Link, AutoComplete, DatePicker, AttachmentPicker, PageHeader, StatTiles },

  props: {
    warehouse: Object,
    contract: Object,
    certificates: { type: Array, default: () => [] },
    clients: Array,
    consultants: Array,
    currencies: Array,
    defaultCurrency: String,
    defaultContractorName: String,
    addendumStats: { type: Object, default: () => ({ total_contract_sum: 0, total_addendum_sum: 0, total_project_amount: 0 }) },
    canCreateAddendum: Boolean,
  },

  data() {
    return {
      saving: false,
      existingAttachments: this.contract?.attachments ? [...this.contract.attachments] : [],
      newAttachments: [],
      localClients: [...this.clients],
      localConsultants: [...this.consultants],
      contactModalOpen: false,
      contactModalCategory: 'Clients',
      // Which form.clients row's picker to fill in once the quick-add
      // modal succeeds — null when adding a Consultant (still a single
      // field) or when there's no Funding Clients row context at all.
      contactModalTargetIndex: null,
      contactModal: { name: '', phone: '', email: '' },
      contactModalSaving: false,
      contactModalError: '',
      toast: { visible: false, type: 'success', text: '' },
      toastTimer: null,
      focusedAmountField: null,
      focusedVatRate: false,
      focusedClientAmountIndex: null,
      form: {
        consultant_contact_id: this.contract?.consultant_contact_id ?? null,
        contractor_name: this.contract?.contractor_name ?? this.defaultContractorName ?? '',
        contract_reference: this.contract?.contract_reference ?? '',
        // Fixed — a contract can be funded by several clients, each in
        // their own currency (see `clients` below), so its own reporting
        // currency stays TZS so every downstream total is comparable.
        currency: 'TZS',
        vat_included: this.contract?.vat_included ?? false,
        vat_rate: Number(this.contract?.vat_rate ?? 18),
        vat_exempted: this.contract?.vat_exempted ?? false,
        contract_variation_amount: Number(this.contract?.contract_variation_amount ?? 0),
        contract_start_date: this.contract?.contract_start_date ?? '',
        contract_end_date: this.contract?.contract_end_date ?? '',
        // One row per client funding this project — at least one is
        // required. amount is in the row's OWN currency; exchange_rate
        // (client currency → TZS) only matters once currency isn't TZS.
        clients: this.contract?.clients?.length
          ? this.contract.clients.map(c => ({
            client_contact_id: c.client_contact_id,
            currency: c.currency || 'TZS',
            amount: Number(c.amount) || 0,
            exchange_rate: c.exchange_rate === null || c.exchange_rate === undefined ? null : Number(c.exchange_rate),
          }))
          : [{ client_contact_id: null, currency: this.defaultCurrency ?? 'TZS', amount: 0, exchange_rate: null }],
      },
    };
  },

  computed: {
    sumTiles() {
      const fmt = v => Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      const tiles = [
        { label: this.$t('Original Contract Sum (TZS)'), value: 'TZS ' + fmt(this.totalClientsInTzs), icon: 'cash', tone: 'navy' },
        { label: this.$t('Revised Contract Sum (VAT Exclusive)'), value: this.form.currency + ' ' + fmt(this.revisedSumExclusive), icon: 'calculator', tone: 'indigo' },
        { label: this.$t('Revised Contract Sum (VAT Inclusive)'), value: this.form.currency + ' ' + fmt(this.revisedSumInclusive), icon: 'calculator', tone: 'emerald' },
      ];
      if (this.contract) {
        tiles.push({ label: this.$t('Total Project Amount (VAT Exclusive)'), value: this.form.currency + ' ' + fmt(this.addendumStats.total_project_amount), icon: 'building', tone: 'rose' });
      }

      return tiles;
    },
    // Original Contract Sum, TZS — the sum of every Funding Client's
    // amountInTzs, treating an incomplete row (non-TZS with no rate yet)
    // as 0 for this live total rather than blocking it; save() below is
    // what actually refuses to submit until every row is complete.
    totalClientsInTzs() {
      const total = this.form.clients.reduce((sum, row) => sum + (this.clientAmountInTzs(row) || 0), 0);
      return Math.round(total * 100) / 100;
    },
    revisedSum() {
      return this.totalClientsInTzs + (Number(this.form.contract_variation_amount) || 0);
    },
    // 0% whenever this project is VAT Exempted, regardless of whatever
    // rate is still sitting in the (disabled) Contract VAT (TAX) field —
    // exemption overrides the rate everywhere it's used, without erasing
    // it, so toggling exemption back off restores the QS's typed rate.
    effectiveVatRate() {
      return this.form.vat_exempted ? 0 : (Number(this.form.vat_rate) || 0);
    },
    // The typed Original + Variation amounts, taken as-is, either already
    // include VAT (checkbox on) or don't (checkbox off) — either way the
    // rate lets WIMS back out both the exclusive and inclusive figures.
    revisedSumExclusive() {
      const value = this.form.vat_included ? this.revisedSum / (1 + this.effectiveVatRate / 100) : this.revisedSum;
      return Math.round(value * 100) / 100;
    },
    revisedSumInclusive() {
      const value = this.form.vat_included ? this.revisedSum : this.revisedSum * (1 + this.effectiveVatRate / 100);
      return Math.round(value * 100) / 100;
    },
    displayVatRate() {
      if (this.focusedVatRate) {
        return this.form.vat_rate === null || this.form.vat_rate === undefined ? '' : String(this.form.vat_rate);
      }
      return Number(this.form.vat_rate || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    clientOptions() {
      return this.localClients.map(c => ({ id: c.id, label: c.name }));
    },
    consultantOptions() {
      return this.localConsultants.map(c => ({ id: c.id, label: c.name }));
    },
    // "TZS Tanzanian Shilling" — code and name, no dash — matches
    // Builder.vue's own currency picker rather than Currencies::all()'s
    // shared "CODE — Name" label (still used elsewhere in the app).
    currencyOptions() {
      return this.currencies.map(c => ({ id: c.code, label: `${c.code} ${c.name}` }));
    },
  },


  methods: {
    statusTone(status) {
      return { draft: 'lg-chip-slate', submitted: 'lg-chip-indigo', approved: 'lg-chip-blue', issued: 'lg-chip-green', revised: 'lg-chip-amber', cancelled: 'lg-chip-red', pending_approval: 'lg-chip-amber', rejected: 'lg-chip-red' }[status] || 'lg-chip-slate';
    },
    // Comma-formatted while settled, plain digits while actively being
    // typed into — reformatting on every keystroke fights the cursor
    // position, so the field only re-formats on blur.
    displayAmount(field) {
      if (this.focusedAmountField === field) {
        return this.form[field] === null || this.form[field] === undefined ? '' : String(this.form[field]);
      }

      return Number(this.form[field] || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    onAmountFocus(field) {
      this.focusedAmountField = field;
    },
    onAmountInput(field, event) {
      const raw = event.target.value.replace(/[^0-9.-]/g, '');
      this.form[field] = raw === '' || raw === '-' ? null : Number(raw);
    },
    onAmountBlur(field) {
      if (this.focusedAmountField === field) this.focusedAmountField = null;
      if (this.form[field] === null || Number.isNaN(this.form[field])) this.form[field] = 0;
    },

    // --- Funding Clients -------------------------------------------------
    isTzsRow(row) {
      return (row.currency || 'TZS').toUpperCase() === 'TZS';
    },
    // null (not 0) when a non-TZS row has no rate yet — a real "not
    // computable" signal, not a fabricated amount, matching every other
    // amountInTzs()-style helper in this module.
    clientAmountInTzs(row) {
      if (this.isTzsRow(row)) return Number(row.amount) || 0;
      if (!row.exchange_rate) return null;
      return Math.round((Number(row.amount) || 0) * Number(row.exchange_rate) * 100) / 100;
    },
    addClientRow() {
      this.form.clients.push({ client_contact_id: null, currency: this.defaultCurrency ?? 'TZS', amount: 0, exchange_rate: null });
    },
    removeClientRow(idx) {
      this.form.clients.splice(idx, 1);
    },
    displayClientAmount(idx) {
      const row = this.form.clients[idx];
      if (this.focusedClientAmountIndex === idx) {
        return row.amount === null || row.amount === undefined ? '' : String(row.amount);
      }
      return Number(row.amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    onClientAmountFocus(idx) {
      this.focusedClientAmountIndex = idx;
    },
    onClientAmountInput(idx, event) {
      const raw = event.target.value.replace(/[^0-9.-]/g, '');
      this.form.clients[idx].amount = raw === '' || raw === '-' ? null : Number(raw);
    },
    onClientAmountBlur(idx) {
      if (this.focusedClientAmountIndex === idx) this.focusedClientAmountIndex = null;
      const row = this.form.clients[idx];
      if (row.amount === null || Number.isNaN(row.amount)) row.amount = 0;
    },
    // ----------------------------------------------------------------------

    onVatRateFocus() {
      this.focusedVatRate = true;
    },
    onVatRateInput(event) {
      const raw = event.target.value.replace(/[^0-9.]/g, '');
      this.form.vat_rate = raw === '' ? null : Number(raw);
    },
    onVatRateBlur() {
      this.focusedVatRate = false;
      if (this.form.vat_rate === null || Number.isNaN(this.form.vat_rate)) this.form.vat_rate = 0;
      if (this.form.vat_rate > 100) this.form.vat_rate = 100;
    },
    // Total Contract Sum / Total Addendum Sum / Total Project Amount are
    // all VAT-exclusive baseline figures — this is what each becomes with
    // Contract VAT (TAX) added on top, for a project that isn't exempted.
    vatInclusive(value) {
      return Math.round((Number(value) || 0) * (1 + this.effectiveVatRate / 100) * 100) / 100;
    },
    save() {
      if (!this.form.clients.length || this.form.clients.some(r => !r.client_contact_id)) {
        this.showToast('error', this.$t('Select a client for every Funding Client row (or remove the empty one).'));
        return;
      }
      const incomplete = this.form.clients.find(r => !this.isTzsRow(r) && !r.exchange_rate);
      if (incomplete) {
        this.showToast('error', this.$t('Enter the agreed exchange rate for every non-TZS Funding Client.'));
        return;
      }

      this.saving = true;
      this.$inertia.post(this.route('project-certificates.contract.update', this.warehouse.id), { ...this.form, attachments: this.newAttachments }, {
        onSuccess: () => { this.newAttachments = []; },
        onFinish: () => { this.saving = false; },
      });
    },

    async removeAttachment(file) {
      if (!(await this.$confirm(this.$t('Remove this attachment? This cannot be undone.'), { variant: 'danger' }))) {
        return;
      }
      this.$inertia.delete(file.delete_url, {
        preserveScroll: true,
        onSuccess: () => {
          this.existingAttachments = this.existingAttachments.filter(f => f.id !== file.id);
        },
      });
    },

    showToast(type, text) {
      clearTimeout(this.toastTimer);
      this.toast = { visible: true, type, text };
      this.toastTimer = setTimeout(() => { this.toast.visible = false; }, 4000);
    },

    openContactModal(category, targetClientIndex = null) {
      this.contactModalCategory = category;
      this.contactModalTargetIndex = targetClientIndex;
      this.contactModal = { name: '', phone: '', email: '' };
      this.contactModalError = '';
      this.contactModalOpen = true;
    },

    submitContact() {
      if (!this.contactModal.name) return;
      this.contactModalSaving = true;
      this.contactModalError = '';

      axios.post(this.route('project-certificates.contacts.quick-store'), {
        name: this.contactModal.name,
        category: this.contactModalCategory,
        phone: this.contactModal.phone || null,
        email: this.contactModal.email || null,
      })
        .then(res => {
          if (this.contactModalCategory === 'Clients') {
            this.localClients.push(res.data);
            if (this.contactModalTargetIndex !== null && this.form.clients[this.contactModalTargetIndex]) {
              this.form.clients[this.contactModalTargetIndex].client_contact_id = res.data.id;
            }
          } else {
            this.localConsultants.push(res.data);
            this.form.consultant_contact_id = res.data.id;
          }
          this.contactModalOpen = false;
          this.showToast('success', this.$t(':name added successfully.', { name: res.data.name }));
        })
        .catch(err => {
          const message = err.response?.data?.errors?.name?.[0]
            || err.response?.data?.errors?.email?.[0]
            || err.response?.data?.errors?.phone?.[0]
            || this.$t('Something went wrong.');
          this.contactModalError = message;
          this.showToast('error', message);
        })
        .finally(() => { this.contactModalSaving = false; });
    },
  },
};
</script>

<style scoped>
/* ---------- shared Project Certificates chrome (pc- prefix) ---------- */
.pc-label {
  display: flex;
  align-items: center;
  gap: 6px;
  min-height: 1.1rem;
  font-size: 0.7rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--lg-mute);
  margin-bottom: 0.3rem;
}
.pc-req { color: #be123c; }
.pc-input {
  display: block;
  width: 100%;
  border: 1px solid var(--lg-line);
  border-radius: 0.85rem;
  padding: 0.55rem 0.8rem;
  font-size: 0.85rem;
  color: var(--lg-ink);
  background: var(--lg-card);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.pc-input::placeholder { color: var(--lg-faint); }
.pc-input:hover:not(:disabled) { border-color: rgba(47, 111, 179, 0.55); }
.pc-input:focus { outline: none; border-color: #2f6fb3; box-shadow: 0 0 0 3px rgba(47, 111, 179, 0.18); }
.pc-input:disabled, .pc-input.is-readonly { background: var(--lg-soft); color: var(--lg-mute); cursor: not-allowed; }
.pc-input.is-readonly { font-weight: 800; }
textarea.pc-input { resize: vertical; }

.pc-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):not([type='hidden'])),
.pc-form :deep(select),
.pc-form :deep(textarea) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.pc-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):focus),
.pc-form :deep(select:focus),
.pc-form :deep(textarea:focus) {
  border-color: #2f6fb3 !important;
  box-shadow: 0 0 0 3px rgba(47, 111, 179, 0.18) !important;
}
html.dark .pc-form :deep(input),
html.dark .pc-form :deep(select),
html.dark .pc-form :deep(textarea) { color-scheme: dark; }

.pc-checkbox { width: 1rem; height: 1rem; border-radius: 0.3rem; accent-color: #2f6fb3; flex: none; }

.pc-info { display: inline-flex; align-items: center; color: var(--lg-faint); cursor: help; text-transform: none; letter-spacing: 0; }
.pc-info:hover { color: var(--lg-navy); }
html.dark .pc-info:hover { color: #93c5fd; }
.pc-info.lg-tip::after { right: auto; left: 50%; width: 240px; margin-left: -120px; z-index: 300; font-weight: 600; }
@media (max-width: 640px) { .pc-info.lg-tip::after { margin-left: -60px; width: 220px; } }

.pc-linkbtn { display: inline-flex; align-items: center; gap: 4px; padding: 0 !important; min-height: 0 !important; font-size: 0.74rem; font-weight: 800; color: var(--lg-navy); }
.pc-linkbtn:hover { text-decoration: underline; }
html.dark .pc-linkbtn { color: #93c5fd; }

.pc-sec { padding: 0.9rem 1rem; border: 1px solid var(--lg-line); border-radius: 16px; background: var(--lg-soft); }
.pc-sec-title { display: flex; align-items: center; gap: 7px; margin-bottom: 0.75rem; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-navy); }
html.dark .pc-sec-title { color: #93c5fd; }

.pc-sm { padding: 6px 12px; font-size: 0.72rem; }

.pc-file { display: inline-flex; align-items: center; gap: 6px; max-width: 100%; padding: 6px 12px; border-radius: 9999px; font-size: 0.74rem; font-weight: 700; color: var(--lg-navy); background: var(--lg-soft); border: 1px solid var(--lg-line); transition: background 0.15s ease, transform 0.15s ease; }
.pc-file:hover { transform: translateY(-1px); background: rgba(47, 111, 179, 0.12); }
.pc-file > span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
html.dark .pc-file { color: #bfdbfe; }

.pc-filelist { border: 1px solid var(--lg-line); border-radius: 14px; }
.pc-filelist li { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 12px; font-size: 0.82rem; color: var(--lg-ink); border-bottom: 1px solid var(--lg-line); }
.pc-filelist li:last-child { border-bottom: 0; }
.pc-filelist li > span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* ---------- dialogs (teleported to body) ---------- */
.pc-overlay { --pc-solid: #ffffff; position: fixed; inset: 0; z-index: 200; display: flex; justify-content: center; padding: 1rem; overflow-y: auto; background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(4px); }
html.dark .pc-overlay { --pc-solid: #0f172a; }
.pc-dialog { display: flex; flex-direction: column; width: 100%; max-width: 30rem; margin: auto; border-radius: 22px; background: var(--pc-solid); border: 1px solid var(--lg-line); box-shadow: 0 30px 70px -20px rgba(15, 23, 42, 0.55); }
.pc-dialog-wide { max-width: 38rem; }
.pc-dialog-sm { max-width: 24rem; }
.pc-dialog-head { display: flex; align-items: center; gap: 12px; padding: 1.05rem 1.3rem; border-bottom: 1px solid var(--lg-line); }
.pc-dialog-body { padding: 1.1rem 1.3rem 1.2rem; }
.pc-dialog-foot { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 8px; padding: 0.85rem 1.3rem; border-top: 1px solid var(--lg-line); background: var(--lg-soft); border-radius: 0 0 22px 22px; }
.pc-dialog-center { align-items: center; text-align: center; padding: 1.6rem 1.4rem 1.3rem; gap: 4px; }
.pc-dialog-center .pc-dialog-foot { align-self: stretch; margin: 1rem -1.4rem -1.3rem; justify-content: center; }
.pc-stack > * + * { margin-top: 0.85rem; }


/* ---------- Contract form ---------- */
.pc-num { font-variant-numeric: tabular-nums; }
.pc-faint { color: var(--lg-faint); }
.pc-wrap { white-space: normal; }
.pc-empty-box { border: 1px dashed var(--lg-line); border-radius: 16px; }
.pc-bar { margin-bottom: 1rem; }
.pc-check { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.55rem 0; font-size: 0.85rem; font-weight: 600; color: var(--lg-ink); cursor: pointer; }
.pc-vat-rate-field { position: relative; display: inline-flex; align-items: center; }
.pc-vat-rate-field.is-off { opacity: 0.55; }
.pc-input-sm { width: 6rem; padding-right: 1.7rem; text-align: right; }
.pc-vat-rate-suffix { position: absolute; right: 0.75rem; font-size: 0.78rem; font-weight: 700; color: var(--lg-faint); pointer-events: none; }

/* Funding clients */
.pc-client-row { position: relative; display: flex; align-items: flex-start; gap: 10px; padding: 0.9rem; margin-bottom: 0.7rem; border: 1px solid var(--lg-line); border-radius: 18px; background: var(--lg-soft); }
.pc-client-n { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; margin-top: 1.35rem; border-radius: 9999px; font-size: 0.74rem; font-weight: 800; color: var(--lg-navy); background: rgba(47, 111, 179, 0.14); }
html.dark .pc-client-n { color: #bfdbfe; }
.pc-client-fields { flex: 1; display: grid; grid-template-columns: 1fr; gap: 0.75rem; min-width: 0; }
@media (min-width: 480px) { .pc-client-fields { grid-template-columns: repeat(2, 1fr); } }
@media (min-width: 900px) { .pc-client-fields { grid-template-columns: repeat(4, 1fr); } }
.pc-client-field { min-width: 0; }
.pc-client-field-name { grid-column: 1 / -1; }
.pc-client-tzs { padding: 0.55rem 0.8rem; border-radius: 0.85rem; border: 1px solid rgba(16, 185, 129, 0.3); background: rgba(16, 185, 129, 0.12); color: #047857; font-size: 0.83rem; font-weight: 800; font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
html.dark .pc-client-tzs { color: #6ee7b7; }
.pc-client-rm { margin-top: 1.35rem; }
@media (max-width: 480px) {
  .pc-client-row { flex-wrap: wrap; }
  .pc-client-n { margin-top: 0; }
  .pc-client-rm { position: absolute; top: 0.6rem; right: 0.6rem; margin-top: 0; }
}
.pc-funding-total { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-top: 0.4rem; padding: 0.85rem 1.1rem; border-radius: 16px; border: 1px solid rgba(6, 182, 212, 0.35); background: rgba(6, 182, 212, 0.1); font-size: 0.78rem; font-weight: 800; color: #0e7490; }
.pc-funding-total-value { font-size: 1.15rem; font-weight: 800; font-variant-numeric: tabular-nums; }
html.dark .pc-funding-total { color: #67e8f9; }

/* Addendum totals */
.pc-totals { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.75rem; }
@media (max-width: 900px) { .pc-totals { grid-template-columns: 1fr; } }
.pc-total-tile { display: flex; flex-direction: column; gap: 0.3rem; min-width: 0; padding: 0.95rem 1.05rem; border-radius: 18px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.pc-total-label { display: flex; align-items: center; gap: 6px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.pc-total-value { font-size: 1.1rem; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--lg-ink); overflow-wrap: anywhere; }
.pc-total-value.is-indigo { color: #4338ca; }
.pc-total-value.is-rose { color: #be123c; }
html.dark .pc-total-value.is-indigo { color: #a5b4fc; }
html.dark .pc-total-value.is-rose { color: #fda4af; }
.pc-vat-box { display: flex; flex-direction: column; gap: 0.1rem; margin-top: 0.35rem; padding: 0.5rem 0.75rem; border-radius: 12px; background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.3); }
.pc-vat-label { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: #047857; }
.pc-vat-value { font-size: 0.86rem; font-weight: 800; font-variant-numeric: tabular-nums; color: #065f46; overflow-wrap: anywhere; }
.pc-vat-note { font-size: 0.72rem; font-weight: 800; color: #047857; }
html.dark .pc-vat-label, html.dark .pc-vat-note { color: #6ee7b7; }
html.dark .pc-vat-value { color: #a7f3d0; }

/* Toast */
.pc-toast { position: fixed; top: 5.5rem; right: 1.25rem; z-index: 400; display: flex; align-items: center; gap: 8px; max-width: calc(100vw - 2.5rem); padding: 0.7rem 1.1rem; border-radius: 16px; font-size: 0.85rem; font-weight: 700; box-shadow: 0 14px 30px -10px rgba(15, 23, 42, 0.35); }
.pc-toast-success { background: #ecfdf5; color: #047857; border: 1px solid #86efac; }
.pc-toast-error { background: #fef2f2; color: #b91c1c; border: 1px solid #fca5a5; }
html.dark .pc-toast-success { background: #064e3b; color: #a7f3d0; border-color: rgba(52, 211, 153, 0.4); }
html.dark .pc-toast-error { background: #4c0519; color: #fecdd3; border-color: rgba(251, 113, 133, 0.4); }
.pc-toast-fade-enter-active, .pc-toast-fade-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.pc-toast-fade-enter-from, .pc-toast-fade-leave-to { opacity: 0; transform: translateY(-8px); }
</style>

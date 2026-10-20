<template>
  <admin-layout :title="$t('Supplier Ledger') + ' / ' + supplier.name">
    <div class="lg-page sp-form px-4 md:px-0">
      <page-header
        icon="doc-text"
        :title="supplier.name + ' / ' + $t('Ledger')"
        :subtitle="$t('Supplier Account: the single running balance across all obligations, invoices, and payments.')"
      >
        <Link :href="route('supplier-records.suppliers.show', supplier.id)" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Supplier Profile') }}
        </Link>
        <Link :href="route('supplier-records.suppliers.reconciliations.index', supplier.id)" class="lg-btn lg-btn-soft">
          <icons name="list" class="w-4 h-4" />{{ $t('Reconciliations') }}
        </Link>
        <button type="button" class="lg-btn lg-btn-dark" @click="showManual = !showManual; showOpening = false; editingEntry = null">
          <icons name="plus" class="w-4 h-4" />{{ $t('Manual Entry') }}
        </button>
        <button type="button" class="lg-btn lg-btn-primary" @click="showOpening = !showOpening; showManual = false; editingEntry = null">
          <icons name="plus" class="w-4 h-4" />{{ $t('Opening Balance') }}
        </button>
      </page-header>

      <stat-tiles :items="statTiles" />

      <section v-if="projectBalances.length" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-teal"><icons name="map-pin" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Balance by Project') }}</h2>
          </div>
        </header>
        <div class="sp-projects">
          <div v-for="pb in projectBalances" :key="pb.warehouse_id || 'legacy'" class="sp-project">
            <span class="sp-project-name">{{ pb.warehouse_name }}</span>
            <span class="sp-project-val" :class="pb.balance > 0 ? 'sp-bal-owed' : 'sp-bal-credit'">{{ $money(pb.balance, supplier.currency) }}</span>
          </div>
        </div>
      </section>

      <section v-if="showOpening" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="calendar" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Record Opening Balance') }}</h2>
          </div>
        </header>
        <form @submit.prevent="submitOpening">
          <div class="lg-fields">
            <text-input type="date" :label="$t('Effective Date')" v-model="openingForm.effective_date" :error="$page.props.errors.effective_date" required />
            <text-input type="number" step="0.01" :label="$t('Amount (positive = owed to supplier)')" v-model="openingForm.amount" :error="$page.props.errors.amount" required />
            <select-input :label="$t('Project / Warehouse')" v-model="openingForm.warehouse_id" :error="$page.props.errors.warehouse_id">
              <option :value="null">{{ $t('Company-wide / Legacy') }}</option>
              <warehouse-options :items="warehouses" />
            </select-input>
            <text-input :label="$t('Source (e.g. legacy system, handover)')" v-model="openingForm.source" :error="$page.props.errors.source" />
            <div>
              <text-input :label="$t('Reference')" :model-value="openingReferencePreview" readonly />
              <p class="sp-hint">{{ $t('Auto-generated when you save. Can\'t be typed in.') }}</p>
            </div>
            <div class="lg-span-all"><textarea-input :label="$t('Description')" v-model="openingForm.description" :error="$page.props.errors.description" /></div>
            <div class="lg-span-all"><textarea-input :label="$t('Notes')" v-model="openingForm.notes" :error="$page.props.errors.notes" /></div>
            <div class="lg-span-all">
              <label class="sp-label">{{ $t('Attachments') }}</label>
              <attachment-picker v-model="openingFiles" :max-files="5" />
            </div>
          </div>
          <div class="lg-note lg-note-blue sp-gap">
            <icons name="info" class="w-4 h-4" />
            <span>{{ $t('This will be recorded as Pending. A director must verify it before it locks in and updates the supplier profile balance.') }}</span>
          </div>
          <div class="sp-form-btns">
            <button type="button" class="lg-btn lg-btn-soft" @click="showOpening = false"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button>
            <button type="submit" class="lg-btn lg-btn-primary" :disabled="openingForm.processing">
              <icons :name="openingForm.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': openingForm.processing }" />{{ $t('Save Opening Balance') }}
            </button>
          </div>
        </form>
      </section>

      <section v-if="editingEntry" class="lg-card sp-card-warn">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="lock-open" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Correct Opening Balance') }} / {{ editingEntry.reference }}</h2>
          </div>
        </header>
        <div class="lg-note lg-note-amber sp-gap-b">
          <icons name="exclamation" class="w-4 h-4" />
          <span>
            <template v-if="editingEntry.status === 'Verified'">{{ $t('This entry is already verified/locked. Saving will change the supplier\'s balance immediately.') }}</template>
            <template v-else>{{ $t('This changes the supplier\'s balance once verified.') }}</template>
          </span>
        </div>
        <form @submit.prevent="submitEditOpening">
          <div class="lg-fields">
            <text-input type="date" :label="$t('Effective Date')" v-model="editOpeningForm.effective_date" :error="$page.props.errors.effective_date" required />
            <text-input type="number" step="0.01" :label="$t('Amount (positive = owed to supplier)')" v-model="editOpeningForm.amount" :error="$page.props.errors.amount" required />
            <div>
              <text-input :label="$t('Reference')" v-model="editOpeningForm.reference" readonly />
              <p class="sp-hint">{{ $t('Auto-generated when the entry was created. Can\'t be changed.') }}</p>
            </div>
            <div class="lg-span-all"><textarea-input :label="$t('Description')" v-model="editOpeningForm.description" :error="$page.props.errors.description" /></div>
            <div class="lg-span-all"><textarea-input :label="$t('Notes')" v-model="editOpeningForm.notes" :error="$page.props.errors.notes" /></div>
          </div>
          <div class="sp-form-btns sp-between">
            <spr-confirm-password
              v-if="$super"
              :title="$t('Delete Opening Balance')"
              :content="$t('This permanently removes this opening balance entry and recalculates the supplier\'s balance. This cannot be undone. Please confirm your password to continue.')"
              @confirmed="deleteOpeningBalance"
            >
              <button type="button" class="lg-btn lg-btn-red" :disabled="editOpeningForm.processing">
                <icons name="trash" class="w-4 h-4" />{{ $t('Delete') }}
              </button>
            </spr-confirm-password>
            <div v-else></div>
            <div class="sp-form-btns-inner">
              <button type="button" class="lg-btn lg-btn-soft" @click="editingEntry = null"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button>
              <button type="submit" class="lg-btn lg-btn-primary" :disabled="editOpeningForm.processing">
                <icons :name="editOpeningForm.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': editOpeningForm.processing }" />{{ $t('Save Correction') }}
              </button>
            </div>
          </div>
        </form>
      </section>

      <section v-if="showManual" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="edit" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Manual Ledger Entry') }}</h2>
          </div>
        </header>
        <form @submit.prevent="submitManual">
          <div class="lg-fields">
            <select-input :label="$t('Type')" v-model="manualForm.type" :error="$page.props.errors.type">
              <option v-for="t in manualTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select-input>
            <text-input type="date" :label="$t('Date')" v-model="manualForm.entry_date" :error="$page.props.errors.entry_date" required />
            <text-input type="number" step="0.01" :label="$t('Amount')" v-model="manualForm.amount" :error="$page.props.errors.amount" required />
            <select-input :label="$t('Project / Warehouse')" v-model="manualForm.warehouse_id" :error="$page.props.errors.warehouse_id">
              <option :value="null">{{ $t('Company-wide / Legacy') }}</option>
              <warehouse-options :items="warehouses" />
            </select-input>
            <text-input :label="$t('Reference')" v-model="manualForm.reference" :error="$page.props.errors.reference" required />
            <div v-if="isTaxType" class="lg-span-all">
              <div class="lg-note lg-note-blue">
                <icons name="info" class="w-4 h-4" />
                <span>
                  {{ $t('Supplier default rate') }}: {{ manualForm.type === 'withholding_tax' ? supplier.withholding_tax_percentage : supplier.retention_percentage }}%.
                  {{ $t('The amount above is still what actually posts; adjust it if this transaction differs from the default.') }}
                </span>
              </div>
            </div>
            <div class="lg-span-all"><textarea-input :label="$t('Description')" v-model="manualForm.description" :error="$page.props.errors.description" /></div>
            <div class="lg-span-all">
              <label class="sp-label">{{ $t('Attachments') }}</label>
              <attachment-picker v-model="manualFiles" :max-files="5" />
            </div>
          </div>
          <div class="sp-form-btns">
            <button type="button" class="lg-btn lg-btn-soft" @click="showManual = false"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button>
            <button type="submit" class="lg-btn lg-btn-primary" :disabled="manualForm.processing">
              <icons :name="manualForm.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': manualForm.processing }" />{{ $t('Save Entry') }}
            </button>
          </div>
        </form>
      </section>

      <section class="lg-card lg-filters">
        <div class="lg-fields">
          <div>
            <label class="sp-label">{{ $t('Type') }}</label>
            <select v-model="filters.type" class="sp-select"><option :value="null">{{ $t('All Types') }}</option><option v-for="(label, key) in typeLabels" :key="key" :value="key">{{ label }}</option></select>
          </div>
          <div>
            <label class="sp-label">{{ $t('Project') }}</label>
            <select v-model="filters.warehouse_id" class="sp-select"><option :value="null">{{ $t('All Projects') }}</option><warehouse-options :items="warehouses" /></select>
          </div>
          <div>
            <label class="sp-label">{{ $t('Date From') }}</label>
            <input type="date" v-model="filters.date_from" class="sp-select" />
          </div>
          <div>
            <label class="sp-label">{{ $t('Date To') }}</label>
            <input type="date" v-model="filters.date_to" class="sp-select" />
          </div>
          <div class="sp-filter-reset">
            <button type="button" class="lg-btn lg-btn-soft" @click="resetFilters">
              <icons name="refresh" class="w-4 h-4" />{{ $t('Reset Filters') }}
            </button>
          </div>
        </div>
      </section>

      <section class="lg-card sp-table-card">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Date') }}</th>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('Type') }}</th>
                <th>{{ $t('Project') }}</th>
                <th>{{ $t('Description') }}</th>
                <th class="num">{{ $t('Debit') }}</th>
                <th class="num">{{ $t('Credit') }}</th>
                <th class="num">{{ $t('Balance') }}</th>
                <th class="lg-sticky-end"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="e in entries" :key="e.id">
                <td :data-label="$t('Date')">{{ $date(e.entry_date) }}</td>
                <td :data-label="$t('Reference')"><span class="lg-main">{{ e.reference }}</span></td>
                <td :data-label="$t('Type')"><span class="lg-chip" :class="typeBadgeClass(e.type)" style="text-transform: none">{{ e.type_label }}</span></td>
                <td :data-label="$t('Project')">{{ e.warehouse_name || '-' }}</td>
                <td :data-label="$t('Description')" class="sp-desc">{{ e.description || '-' }}</td>
                <td :data-label="$t('Debit')" class="num" :class="e.debit ? 'sp-debit' : 'sp-nil'">{{ e.debit ? $money(e.debit, supplier.currency) : '-' }}</td>
                <td :data-label="$t('Credit')" class="num" :class="e.credit ? 'sp-credit' : 'sp-nil'">{{ e.credit ? $money(e.credit, supplier.currency) : '-' }}</td>
                <td :data-label="$t('Balance')" class="num"><span class="sp-balance" :class="balanceClass(e.running_balance)">{{ $money(e.running_balance, supplier.currency) }}</span></td>
                <td :data-label="$t('Actions')" class="lg-sticky-end">
                  <div class="lg-actions sp-row-actions">
                    <button
                      v-if="e.status === 'Pending' && $super"
                      type="button"
                      class="sp-mini sp-mini-green"
                      @click="verifyOpening(e)"
                    >
                      <icons name="tick" class="w-3.5 h-3.5" />{{ $t('Verify') }}
                    </button>
                    <span v-else-if="e.status === 'Pending'" class="lg-chip lg-chip-amber" style="text-transform: none">{{ $t('Pending') }}</span>
                    <spr-confirm-password
                      v-if="e.type === 'opening_balance' && $can('edit-supplier-ledger')"
                      :title="$t('Correct Opening Balance')"
                      :content="$t('Correcting an Opening Balance changes the supplier\'s balance. Please confirm your password to continue.')"
                      @confirmed="openEditOpening(e)"
                    >
                      <button type="button" class="sp-mini sp-mini-blue">
                        <icons name="edit" class="w-3.5 h-3.5" />{{ $t('Edit') }}
                      </button>
                    </spr-confirm-password>
                    <button
                      type="button"
                      class="sp-attach lg-tip"
                      :class="{ 'is-active': e.attachments.length }"
                      :data-tip="$t('Attachments')"
                      :aria-label="$t('Attachments')"
                      @click="openAttachments(e.id)"
                    >
                      <icons name="clip" class="w-4 h-4" /><span v-if="e.attachments.length">{{ e.attachments.length }}</span>
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="entries.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="9">
                  <icons name="doc-text" class="w-8 h-8 mx-auto mb-2 opacity-60" />
                  {{ $t('There is no data to display.') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <record-attachments-modal
        :show="attachmentsEntryId !== null"
        :title="$t('Entry Attachments')"
        :attachments="activeAttachments"
        :upload-url="attachmentsUploadUrl"
        @close="attachmentsEntryId = null"
      />
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TextInput from '@/Shared/TextInput.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import WarehouseOptions from '@/Shared/WarehouseOptions.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import AttachmentPicker from '@/Shared/AttachmentPicker.vue';
import RecordAttachmentsModal from '@/Shared/RecordAttachmentsModal.vue';
import SprConfirmPassword from '@/Shared/SprConfirmPassword.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { WarehouseOptions, AdminLayout, TextInput, TextareaInput, SelectInput, LoadingButton, AttachmentPicker, RecordAttachmentsModal, SprConfirmPassword, PageHeader, StatTiles },

  props: {
    supplier: Object,
    entries: Array,
    projectBalances: Array,
    summary: Object,
    warehouses: Array,
    manualTypes: Array,
    filters: Object,
  },

  data() {
    return {
      showOpening: false,
      showManual: false,
      openingFiles: [],
      manualFiles: [],
      attachmentsEntryId: null,
      editingEntry: null,
      filters: {
        type: this.filters.type || null,
        warehouse_id: this.filters.warehouse_id ? Number(this.filters.warehouse_id) : null,
        date_from: this.filters.date_from || null,
        date_to: this.filters.date_to || null,
      },
      openingForm: this.$inertia.form({
        effective_date: new Date().toISOString().slice(0, 10),
        amount: '',
        warehouse_id: null,
        source: '',
        description: '',
        notes: '',
      }),
      manualForm: this.$inertia.form({
        type: this.manualTypes[0] ? this.manualTypes[0].value : '',
        entry_date: new Date().toISOString().slice(0, 10),
        amount: '',
        warehouse_id: null,
        reference: '',
        description: '',
      }),
      editOpeningForm: this.$inertia.form({
        effective_date: '',
        amount: '',
        reference: '',
        description: '',
        notes: '',
      }),
      typeLabels: {
        opening_balance: this.$t('Opening Balance'), material_obligation: this.$t('Material Obligation'),
        purchase: this.$t('Purchase'), supplier_invoice: this.$t('Supplier Invoice'), other_charge: this.$t('Other Charge'),
        debit_adjustment: this.$t('Debit Adjustment'), payment: this.$t('Payment'), credit_note: this.$t('Credit Note'),
        discount: this.$t('Discount'), retention: this.$t('Retention'), withholding_tax: this.$t('Withholding Tax'),
        credit_adjustment: this.$t('Credit Adjustment'),
      },
    };
  },

  computed: {
    statTiles() {
      const bal = Number(this.summary.balance) || 0;
      return [
        { icon: 'cash', tone: bal > 0 ? 'amber' : bal < 0 ? 'emerald' : 'navy', label: this.$t('Current Balance'), value: this.$money(this.summary.balance, this.supplier.currency), valueClass: bal < 0 ? 'is-good' : '' },
        { icon: 'up', tone: 'rose', label: this.$t('Total Debits'), value: this.$money(this.summary.total_debits, this.supplier.currency) },
        { icon: 'down', tone: 'emerald', label: this.$t('Total Credits'), value: this.$money(this.summary.total_credits, this.supplier.currency) },
        { icon: 'clock', tone: this.summary.pending_opening_balances ? 'amber' : 'slate', label: this.$t('Pending Opening Balances'), value: Number(this.summary.pending_opening_balances) || 0 },
      ];
    },
    isTaxType() {
      return ['withholding_tax', 'retention'].includes(this.manualForm.type);
    },
    // Mirrors Ledger::recordOpeningBalance()'s format, minus the id suffix
    // (the real id doesn't exist until the entry is saved) — a preview only,
    // never actually submitted; the server assigns the real reference.
    openingReferencePreview() {
      const today = new Date().toISOString().slice(0, 10).replace(/-/g, '');
      return `OB-${this.supplier.id}-${today}-…`;
    },
    activeAttachments() {
      const entry = this.entries.find(e => e.id === this.attachmentsEntryId);
      return entry ? entry.attachments : [];
    },
    attachmentsUploadUrl() {
      return this.attachmentsEntryId
        ? route('supplier-records.suppliers.ledger.entries.attachments.store', [this.supplier.id, this.attachmentsEntryId])
        : null;
    },
  },

  watch: {
    filters: {
      handler() {
        const query = {};
        Object.keys(this.filters).forEach(k => { if (this.filters[k]) query[k] = this.filters[k]; });
        this.$inertia.visit(route('supplier-records.suppliers.ledger.show', this.supplier.id), {
          data: query, preserveState: true, preserveScroll: true, replace: true,
        });
      },
      deep: true,
    },
  },

  methods: {
    resetFilters() {
      this.filters = { type: null, warehouse_id: null, date_from: null, date_to: null };
    },
    typeBadgeClass(type) {
      const credit = ['payment', 'credit_note', 'discount', 'retention', 'withholding_tax', 'credit_adjustment'];
      return credit.includes(type) ? 'lg-chip-green' : 'lg-chip-amber';
    },
    balanceClass(value) {
      const v = Number(value) || 0;
      return v > 0 ? 'sp-bal-owed' : v < 0 ? 'sp-bal-credit' : '';
    },
    submitOpening() {
      this.openingForm
        .transform(data => ({ ...data, attachments: this.openingFiles }))
        .post(route('supplier-records.suppliers.ledger.opening-balance.store', this.supplier.id), {
          onSuccess: () => { this.showOpening = false; this.openingForm.reset(); this.openingFiles = []; },
        });
    },
    submitManual() {
      this.manualForm
        .transform(data => ({ ...data, attachments: this.manualFiles }))
        .post(route('supplier-records.suppliers.ledger.entries.store', this.supplier.id), {
          onSuccess: () => { this.showManual = false; this.manualForm.reset(); this.manualFiles = []; },
        });
    },
    verifyOpening(entry) {
      this.$inertia.patch(route('supplier-records.suppliers.ledger.entries.verify', [this.supplier.id, entry.id]));
    },
    openAttachments(entryId) {
      this.attachmentsEntryId = entryId;
    },
    openEditOpening(entry) {
      this.showOpening = false;
      this.showManual = false;
      this.editingEntry = entry;
      this.editOpeningForm.defaults({
        effective_date: (entry.entry_date || '').slice(0, 10),
        amount: entry.signed_amount,
        reference: entry.reference,
        description: entry.description || '',
        notes: entry.notes || '',
      }).reset();
    },
    submitEditOpening() {
      this.editOpeningForm.patch(
        route('supplier-records.suppliers.ledger.entries.opening-balance.update', [this.supplier.id, this.editingEntry.id]),
        { onSuccess: () => { this.editingEntry = null; } }
      );
    },
    deleteOpeningBalance() {
      if (!this.editingEntry) {
        return;
      }
      this.$inertia.delete(
        route('supplier-records.suppliers.ledger.entries.opening-balance.destroy', [this.supplier.id, this.editingEntry.id]),
        { onSuccess: () => { this.editingEntry = null; } }
      );
    },
  },
};
</script>

<style scoped>
.sp-form :deep(label:not(:has(input[type='checkbox']))),
.sp-label {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
  margin-bottom: 0.25rem;
}
.sp-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):not([type='hidden'])),
.sp-form :deep(select),
.sp-form :deep(textarea),
.sp-select {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  padding-top: 0.55rem !important;
  padding-bottom: 0.55rem !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.sp-select { width: 100%; border: 1px solid var(--lg-line); padding-left: 0.75rem; padding-right: 0.75rem; font-size: 0.85rem; }
.sp-form :deep(input[readonly]) { background: var(--lg-soft); }
.sp-form :deep(input:not([type='checkbox']):not([type='radio']):not([type='file']):focus),
.sp-form :deep(select:focus),
.sp-form :deep(textarea:focus),
.sp-select:focus {
  outline: none;
  border-color: #3b82f6 !important;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2) !important;
}
.sp-hint { margin-top: 0.3rem; font-size: 0.72rem; color: var(--lg-faint); }
.sp-gap { margin-top: 1rem; }
.sp-gap-b { margin-bottom: 1rem; }
.sp-form-btns { display: flex; justify-content: flex-end; gap: 10px; flex-wrap: wrap; margin-top: 1.1rem; }
.sp-form-btns.sp-between { justify-content: space-between; align-items: center; }
.sp-form-btns-inner { display: flex; gap: 10px; flex-wrap: wrap; }
.sp-card-warn { border-color: rgba(245, 158, 11, 0.45); }
.sp-filter-reset { display: flex; align-items: flex-end; }

.sp-projects { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(220px, 100%), 1fr)); gap: 10px; }
.sp-project { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 14px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.sp-project-name { min-width: 0; font-size: 0.8rem; font-weight: 600; color: var(--lg-mute); overflow-wrap: anywhere; }
.sp-project-val { font-size: 0.88rem; font-weight: 800; font-variant-numeric: tabular-nums; white-space: nowrap; }

.sp-bal-owed { color: #b45309; }
.sp-bal-credit { color: #047857; }
html.dark .sp-bal-owed { color: #fbbf24; }
html.dark .sp-bal-credit { color: #6ee7b7; }
.sp-debit { color: #b45309; font-weight: 700; }
.sp-credit { color: #047857; font-weight: 700; }
html.dark .sp-debit { color: #fbbf24; }
html.dark .sp-credit { color: #6ee7b7; }
.sp-nil { color: var(--lg-faint); }
.sp-balance { display: inline-block; padding: 2px 10px; border-radius: 9px; font-weight: 800; color: var(--lg-ink); background: var(--lg-soft); }
.sp-balance.sp-bal-owed { color: #b45309; background: rgba(245, 158, 11, 0.12); }
.sp-balance.sp-bal-credit { color: #047857; background: rgba(16, 185, 129, 0.12); }
html.dark .sp-balance.sp-bal-owed { color: #fbbf24; }
html.dark .sp-balance.sp-bal-credit { color: #6ee7b7; }
.sp-desc { white-space: normal !important; min-width: 12rem; max-width: 22rem; color: var(--lg-mute); }

.sp-row-actions { align-items: center; flex-wrap: nowrap; }
.sp-mini { display: inline-flex; align-items: center; gap: 4px; padding: 5px 11px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800; transition: transform 0.15s ease, background 0.15s ease; }
.sp-mini:hover { transform: translateY(-1px); }
.sp-mini-green { color: #047857; background: rgba(16, 185, 129, 0.14); }
.sp-mini-blue { color: #1d4ed8; background: rgba(59, 130, 246, 0.13); }
.sp-mini-red { color: #be123c; background: rgba(244, 63, 94, 0.13); }
html.dark .sp-mini-green { color: #6ee7b7; }
html.dark .sp-mini-blue { color: #93c5fd; }
html.dark .sp-mini-red { color: #fda4af; }
.sp-attach {
  display: inline-flex; align-items: center; gap: 4px; min-height: 32px; padding: 0 10px; border-radius: 10px; font-size: 0.72rem; font-weight: 800;
  color: var(--lg-faint); background: var(--lg-soft); border: 1px solid var(--lg-line); transition: background 0.15s ease, color 0.15s ease;
}
.sp-attach:hover { color: var(--lg-navy); background: rgba(28, 63, 102, 0.1); }
.sp-attach.is-active { color: #1d4ed8; background: rgba(59, 130, 246, 0.12); border-color: rgba(59, 130, 246, 0.3); }
html.dark .sp-attach.is-active { color: #93c5fd; }

@media (max-width: 767.98px) {
  .sp-desc { max-width: none; min-width: 0; }
  .sp-row-actions { flex-wrap: wrap; justify-content: flex-end; }
  .sp-table-card { padding: 0; background: transparent; border: 0; box-shadow: none; }
  .sp-form-btns > *, .sp-form-btns-inner > * { flex: 1 1 auto; }
}
</style>

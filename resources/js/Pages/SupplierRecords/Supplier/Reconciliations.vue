<template>
  <admin-layout :title="$t('Supplier Reconciliation') + ' / ' + supplier.name">
    <div class="lg-page sp-form px-4 md:px-0">
      <page-header
        icon="list"
        :title="supplier.name + ' / ' + $t('Reconciliation')"
        :subtitle="$t('WIMS Balance') + ': ' + $money(currentBalance, supplier.currency)"
      >
        <Link :href="route('supplier-records.suppliers.ledger.show', supplier.id)" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Ledger') }}
        </Link>
        <button type="button" class="lg-btn lg-btn-primary" @click="showForm = !showForm">
          <icons name="plus" class="w-4 h-4" />{{ $t('Compare Against Statement') }}
        </button>
      </page-header>

      <stat-tiles :items="statTiles" />

      <section v-if="showForm" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="list" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('New Reconciliation') }}</h2>
          </div>
        </header>
        <form @submit.prevent="submit">
          <div class="lg-fields">
            <text-input type="date" :label="$t('Reconciliation Date')" v-model="form.reconciliation_date" :error="$page.props.errors.reconciliation_date" required />
            <text-input type="date" :label="$t('Supplier Statement Date')" v-model="form.statement_date" :error="$page.props.errors.statement_date" required />
            <text-input type="number" step="0.01" :label="$t('Supplier Statement Balance')" v-model="form.statement_balance" :error="$page.props.errors.statement_balance" required />
            <div class="lg-span-all">
              <div class="sp-diff" :class="difference === 0 ? 'is-ok' : 'is-off'">
                <span class="sp-diff-label">{{ $t('Difference (Statement − WIMS)') }}</span>
                <span class="sp-diff-val">{{ $money(difference, supplier.currency) }}</span>
              </div>
            </div>
            <select-input v-if="difference !== 0" :label="$t('Reason')" v-model="form.reason" :error="$page.props.errors.reason">
              <option :value="null">{{ $t('Select a reason') }}</option>
              <option v-for="r in reasons" :key="r" :value="r">{{ r }}</option>
            </select-input>
            <div class="lg-span-all"><textarea-input :label="$t('Notes')" v-model="form.notes" :error="$page.props.errors.notes" /></div>
            <div class="lg-span-all">
              <label class="sp-label">{{ $t('Attach Supplier Statement') }}</label>
              <attachment-picker v-model="files" :max-files="5" />
            </div>
          </div>
          <div class="sp-form-btns">
            <button type="button" class="lg-btn lg-btn-soft" @click="showForm = false"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button>
            <button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />{{ $t('Save Reconciliation') }}
            </button>
          </div>
        </form>
      </section>

      <section class="lg-card sp-table-card">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Reconciliation Date') }}</th>
                <th>{{ $t('Statement Date') }}</th>
                <th class="num">{{ $t('Statement Balance') }}</th>
                <th class="num">{{ $t('WIMS Balance') }}</th>
                <th class="num">{{ $t('Difference') }}</th>
                <th>{{ $t('Reason') }}</th>
                <th>{{ $t('Status') }}</th>
                <th class="lg-sticky-end"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in reconciliations" :key="r.id">
                <td :data-label="$t('Reconciliation Date')"><span class="lg-main">{{ $date(r.reconciliation_date) }}</span></td>
                <td :data-label="$t('Statement Date')">{{ $date(r.statement_date) }}</td>
                <td :data-label="$t('Statement Balance')" class="num">{{ $money(r.statement_balance, supplier.currency) }}</td>
                <td :data-label="$t('WIMS Balance')" class="num">{{ $money(r.wims_balance, supplier.currency) }}</td>
                <td :data-label="$t('Difference')" class="num">
                  <span class="sp-balance" :class="r.difference === 0 ? 'sp-bal-credit' : 'sp-bal-owed'">{{ $money(r.difference, supplier.currency) }}</span>
                </td>
                <td :data-label="$t('Reason')" class="sp-desc">{{ r.reason || '-' }}</td>
                <td :data-label="$t('Status')"><span class="lg-chip" :class="statusBadgeClass(r.status)" style="text-transform: none">{{ r.status }}</span></td>
                <td :data-label="$t('Actions')" class="lg-sticky-end">
                  <div class="lg-actions sp-row-actions">
                    <template v-if="r.status === 'Open' && $super">
                      <button type="button" class="sp-mini sp-mini-green" @click="resolve(r, 'Resolved')"><icons name="tick" class="w-3.5 h-3.5" />{{ $t('Resolve') }}</button>
                      <button type="button" class="sp-mini sp-mini-red" @click="resolve(r, 'Disputed')"><icons name="x-circle" class="w-3.5 h-3.5" />{{ $t('Dispute') }}</button>
                    </template>
                    <button
                      type="button"
                      class="sp-attach lg-tip"
                      :class="{ 'is-active': r.attachments.length }"
                      :data-tip="$t('Attachments')"
                      :aria-label="$t('Attachments')"
                      @click="openAttachments(r.id)"
                    >
                      <icons name="clip" class="w-4 h-4" /><span v-if="r.attachments.length">{{ r.attachments.length }}</span>
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="reconciliations.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="8">
                  <icons name="list" class="w-8 h-8 mx-auto mb-2 opacity-60" />
                  {{ $t('There is no data to display.') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <record-attachments-modal
        :show="attachmentsReconciliationId !== null"
        :title="$t('Reconciliation Attachments')"
        :attachments="activeAttachments"
        :upload-url="attachmentsUploadUrl"
        @close="attachmentsReconciliationId = null"
      />
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TextInput from '@/Shared/TextInput.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import AttachmentPicker from '@/Shared/AttachmentPicker.vue';
import RecordAttachmentsModal from '@/Shared/RecordAttachmentsModal.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, TextInput, TextareaInput, SelectInput, LoadingButton, AttachmentPicker, RecordAttachmentsModal, PageHeader, StatTiles },

  props: {
    supplier: Object,
    reconciliations: Array,
    reasons: Array,
    currentBalance: Number,
  },

  data() {
    return {
      showForm: false,
      files: [],
      attachmentsReconciliationId: null,
      form: this.$inertia.form({
        reconciliation_date: new Date().toISOString().slice(0, 10),
        statement_date: new Date().toISOString().slice(0, 10),
        statement_balance: '',
        reason: null,
        notes: '',
      }),
    };
  },

  computed: {
    statTiles() {
      const count = status => this.reconciliations.filter(r => r.status === status).length;
      return [
        { icon: 'cash', tone: 'navy', label: this.$t('WIMS Balance'), value: this.$money(this.currentBalance, this.supplier.currency) },
        { icon: 'clock', tone: 'amber', label: this.$t('Open'), value: count('Open') },
        { icon: 'tick', tone: 'emerald', label: this.$t('Resolved'), value: count('Resolved') },
        { icon: 'x-circle', tone: 'rose', label: this.$t('Disputed'), value: count('Disputed') },
      ];
    },
    difference() {
      const stmt = parseFloat(this.form.statement_balance);
      return isNaN(stmt) ? 0 : Math.round((stmt - this.currentBalance) * 100) / 100;
    },
    activeAttachments() {
      const reconciliation = this.reconciliations.find(r => r.id === this.attachmentsReconciliationId);
      return reconciliation ? reconciliation.attachments : [];
    },
    attachmentsUploadUrl() {
      return this.attachmentsReconciliationId
        ? route('supplier-records.suppliers.reconciliations.attachments.store', [this.supplier.id, this.attachmentsReconciliationId])
        : null;
    },
  },

  methods: {
    statusBadgeClass(status) {
      return { Open: 'lg-chip-amber', Resolved: 'lg-chip-green', Disputed: 'lg-chip-red' }[status] || 'lg-chip-slate';
    },
    submit() {
      this.form
        .transform(data => ({ ...data, attachments: this.files }))
        .post(route('supplier-records.suppliers.reconciliations.store', this.supplier.id), {
          onSuccess: () => { this.showForm = false; this.form.reset(); this.files = []; },
        });
    },
    resolve(reconciliation, status) {
      this.$inertia.patch(route('supplier-records.suppliers.reconciliations.resolve', [this.supplier.id, reconciliation.id]), { status });
    },
    openAttachments(reconciliationId) {
      this.attachmentsReconciliationId = reconciliationId;
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
.sp-diff { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 12px 16px; border-radius: 16px; border: 1px solid var(--lg-line); background: var(--lg-soft); }
.sp-diff-label { font-size: 0.72rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute); }
.sp-diff-val { font-size: 1.2rem; font-weight: 800; font-variant-numeric: tabular-nums; }
.sp-diff.is-ok { border-color: rgba(16, 185, 129, 0.35); background: rgba(16, 185, 129, 0.08); }
.sp-diff.is-ok .sp-diff-val { color: #047857; }
.sp-diff.is-off { border-color: rgba(245, 158, 11, 0.4); background: rgba(245, 158, 11, 0.09); }
.sp-diff.is-off .sp-diff-val { color: #b45309; }
html.dark .sp-diff.is-ok .sp-diff-val { color: #6ee7b7; }
html.dark .sp-diff.is-off .sp-diff-val { color: #fbbf24; }
</style>

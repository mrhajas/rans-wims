<template>
  <admin-layout :title="edit ? $t('Edit Labour List') : $t('New Labour List')">
    <div class="lg-page px-4 md:px-0 lbr-form">
      <page-header
        icon="users"
        :title="edit ? $t('Edit Labour List') : $t('New Labour List')"
        :subtitle="edit ? $t('Update the record by modifying the details in the form below') : $t('Please fill the form below to add new record.')"
      >
        <span v-if="reference" class="lg-chip lg-chip-blue" style="text-transform: none">{{ reference }}</span>
        <Link :href="route('labour.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Labour') }}</Link>
      </page-header>

      <form @submit.prevent="submit">
        <!-- Project, period and description -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="building" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Project / Site') }}</h2>
              <p class="lg-card-sub">{{ $t('Period From') }} / {{ $t('Period To') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div class="lg-span-all lbr-project"><auto-complete id="warehouse_id" :label="$t('Project / Site')" v-model="form.warehouse_id" :suggestions="warehouseOptions" :error="$page.props.errors.warehouse_id" /></div>
            <div><date-picker :label="$t('Period From')" v-model="form.period_start" :error="$page.props.errors.period_start" /></div>
            <div><date-picker :label="$t('Period To')" v-model="form.period_end" :error="$page.props.errors.period_end" /></div>
            <div class="lg-span-all"><textarea-input :label="$t('Description (optional)')" v-model="form.description" :error="$page.props.errors.description" rows="3" /></div>
          </div>
        </section>

        <!-- Labour personnel line items -->
        <section class="lg-card">
          <header class="lg-card-head lbr-personnel-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="users" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Labour Personnel') }}</h2>
              <p class="lg-card-sub">{{ form.items.length }} {{ $t('Labour Personnel') }}</p>
            </div>
            <div class="lbr-head-actions">
              <button type="button" class="lg-btn lg-btn-soft" @click="quickAddOpen = true">
                <icons name="plus" class="w-4 h-4" /> {{ $t('Add New Labour Personnel') }}
              </button>
              <button type="button" class="lg-btn lg-btn-primary" @click="addItem">
                <icons name="plus" class="w-4 h-4" /> {{ $t('Add Labour Personnel') }}
              </button>
            </div>
          </header>
          <div v-if="$page.props.errors.items" class="lg-note lg-note-red mb-3">
            <icons name="exclamation" class="w-4 h-4" />
            <span>{{ $page.props.errors.items }}</span>
          </div>

          <div v-for="(item, index) in form.items" :key="item.key" class="lbr-item-card">
            <div class="lbr-item-head">
              <span class="lbr-item-badge">{{ index + 1 }}</span>
              <span class="lbr-item-title">{{ $t('Labour Personnel') }} #{{ index + 1 }}</span>
              <button type="button" class="lbr-remove-btn lg-tip" :data-tip="$t('Remove')" @click="removeItem(index)">
                <icons name="cross" class="w-3.5 h-3.5" />
              </button>
            </div>

            <div class="lbr-payee-toggle mb-3">
              <button type="button" class="lbr-payee-btn" :class="{ 'lbr-payee-active': item.payee_type === 'External Personnel' }" @click="item.payee_type = 'External Personnel'">{{ $t('External Personnel') }}</button>
              <button type="button" class="lbr-payee-btn" :class="{ 'lbr-payee-active': item.payee_type === 'Internal Staff' }" @click="item.payee_type = 'Internal Staff'">{{ $t('Internal Staff') }}</button>
            </div>

            <div class="lg-fields mb-3">
              <div v-if="item.payee_type === 'Internal Staff'"><auto-complete :label="$t('Staff')" v-model="item.staff_id" :suggestions="staffOptions" :error="$page.props.errors['items.' + index + '.staff_id']" /></div>
              <div v-else>
                <auto-complete
                  :ref="el => setPersonnelRef(el, index)"
                  :label="$t('Labour Personnel')"
                  v-model="item.contact_id"
                  :suggestions="contactOptions"
                  :error="$page.props.errors['items.' + index + '.contact_id']"
                />
              </div>
              <div><auto-complete :label="$t('Priority')" v-model="item.priority" :suggestions="priorityOptions" :error="$page.props.errors['items.' + index + '.priority']" /></div>
            </div>

            <p v-if="agreementLoadFailed[item.key]" class="lbr-agreement-warn mb-3">
              {{ $t('Could not load Workforce Agreements for this worker, the outstanding-balance check will not apply unless this loads successfully. Try reselecting the worker, or contact an admin if this keeps happening.') }}
            </p>
            <div v-if="agreementOptionsFor(item).length" class="lbr-agreement-box mb-3">
              <label class="lbr-label">{{ $t('Workforce Agreement') }}</label>
              <css-select v-model="item.workforce_agreement_id" class="lbr-select" @change="onAgreementChanged(item)">
                <option v-for="a in agreementOptionsFor(item)" :key="a.id" :value="a.id">{{ a.reference }}: {{ $t('Agreed') }} {{ money(a.agreed_amount) }}</option>
              </css-select>
              <p v-if="selectedAgreementFor(item)" class="lbr-agreement-hint">
                {{ $t('Paid') }}: <strong>{{ money(selectedAgreementFor(item).total_paid) }}</strong>
                &nbsp;·&nbsp;
                {{ $t('Outstanding Balance') }}: <strong>{{ money(selectedAgreementFor(item).outstanding_balance) }}</strong>
              </p>
              <p v-if="exceedsBalance(item)" class="lbr-agreement-warn">
                {{ $t('This amount exceeds the outstanding balance of {balance} on this agreement. A new Workforce Agreement is required to cover the difference.', { balance: money(selectedAgreementFor(item).outstanding_balance) }) }}
              </p>
              <a v-if="exceedsBalance(item)" :href="createAgreementUrl(item)" target="_blank" rel="noopener" class="lbr-create-agreement-link">
                <icons name="plus" class="w-3.5 h-3.5" /> {{ $t('Create Work Agreement') }}
              </a>
            </div>
            <div v-else-if="hasPersonnelAndProject(item)" class="lbr-agreement-note mb-3">
              {{ $t('No active Workforce Agreement found for this worker on this project. This request will not be linked to a standing balance.') }}
            </div>

            <div class="mb-3">
              <auto-complete :label="$t('Work Performed')" v-model="item.work_description" :suggestions="workItems" freeform />
            </div>

            <div class="lg-fields lbr-rate-grid">
              <div class="mb-2">
                <label class="lbr-label">{{ $t('Rate Type') }}</label>
                <css-select v-model="item.rate_type" class="lbr-select mt-1">
                  <option value="day">{{ $t('Per Day') }}</option>
                  <option value="hour">{{ $t('Per Hour') }}</option>
                  <option value="fixed">{{ $t('Fixed') }}</option>
                </css-select>
              </div>
              <div v-if="item.rate_type === 'day'"><text-input type="number" :label="$t('Days')" v-model="item.days" /></div>
              <div v-if="item.rate_type === 'hour'"><text-input type="number" :label="$t('Hours')" v-model="item.hours" /></div>
              <div><text-input type="number" :label="$t('Rate (TZS)')" v-model="item.rate" :error="$page.props.errors['items.' + index + '.rate']" /></div>
              <div class="mb-2">
                <label class="lbr-label">{{ $t('Amount') }}</label>
                <div class="lbr-amount-display mt-1">{{ money(computeAmount(item)) }}</div>
              </div>
            </div>
          </div>

          <div class="lbr-total-bar">
            <span class="lbr-total-count"><icons name="users" class="w-4 h-4" /> {{ form.items.length }} {{ $t('Labour Personnel') }}</span>
            <span class="lbr-total-value">{{ $t('Total') }}: {{ money(totalAmount) }}</span>
          </div>
        </section>

        <!-- Attachments -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Attachments (optional)') }}</h2>
              <p class="lg-card-sub">{{ attachmentRows.length }} {{ $t('Files') }}</p>
            </div>
          </header>

          <ul v-if="attachmentRows.length" class="lbr-attachment-list mb-3">
            <li v-for="(row, i) in attachmentRows" :key="row.key" class="lbr-attachment-row">
              <icons name="clip" class="w-4 h-4 lbr-attachment-ico shrink-0" />
              <span class="lbr-attachment-name">{{ row.file.name }}</span>
              <span class="lbr-attachment-size">{{ formatBytes(row.file.size) }}</span>
              <button type="button" class="lbr-attachment-remove lg-tip" :data-tip="$t('Remove')" @click="removeAttachment(i)">
                <icons name="cross" class="w-3.5 h-3.5" />
              </button>
            </li>
          </ul>

          <label class="lbr-file-pick">
            <icons name="plus" class="w-4 h-4" /> {{ $t('Add Files') }}
            <input type="file" multiple class="hidden" @change="onFilesPicked" />
          </label>
          <p v-if="$page.props.errors.attachments" class="lbr-err mt-2">{{ $page.props.errors.attachments }}</p>
        </section>

        <div class="lg-actionbar">
          <span class="lbr-bar-total">{{ $t('Total') }}: <strong>{{ money(totalAmount) }}</strong></span>
          <div class="lg-actionbar-btns">
            <Link :href="route('labour.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" :disabled="form.processing" class="lg-btn lg-btn-primary">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
              {{ edit ? $t('Save Changes') : $t('Save as Draft') }}
            </button>
          </div>
        </div>
      </form>
    </div>

    <add-labour-personnel-modal :show="quickAddOpen" @close="quickAddOpen = false" @created="onPersonnelCreated" />
  </admin-layout>
</template>

<script>
import { nextTick } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import TextInput from '@/Shared/TextInput.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AddLabourPersonnelModal from '@/Shared/AddLabourPersonnelModal.vue';

let keySeq = 0;
let attachmentKeySeq = 0;
const DEFAULT_WORK_ITEM = 'Construction';

export default {
  components: { AdminLayout, PageHeader, AutoComplete, TextInput, TextareaInput, DatePicker, AddLabourPersonnelModal },

  props: {
    edit: Object,
    warehouses: Array,
    staff: Array,
    contacts: Array,
    workItems: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => ['Low', 'Normal', 'High', 'Extreme'] },
    reference: String,
  },

  data() {
    return {
      form: this.$inertia.form({
        warehouse_id: this.edit?.warehouse_id || '',
        period_start: this.edit?.period_start || '',
        period_end: this.edit?.period_end || '',
        description: this.edit?.description || '',
        items: this.edit
          ? this.edit.items.map(i => ({ ...i, key: ++keySeq }))
          : [this.blankItem()],
        attachments: [],
      }),
      attachmentRows: [],
      localContacts: [...this.contacts],
      quickAddOpen: false,
      personnelRefs: {},
      agreementsByItem: {},
      agreementLoadFailed: {},
    };
  },

  watch: {
    'form.warehouse_id'() {
      this.form.items.forEach(item => this.loadAgreements(item));
    },
    'form.items': {
      deep: true,
      handler(items) {
        items.forEach(item => this.loadAgreements(item));
      },
    },
  },

  mounted() {
    this.form.items.forEach(item => this.loadAgreements(item));
  },

  computed: {
    warehouseOptions() {
      return this.warehouses.map(w => ({ id: w.id, label: w.name }));
    },
    staffOptions() {
      return this.staff.map(s => ({ id: s.id, label: s.name }));
    },
    contactOptions() {
      return this.localContacts.map(c => ({ id: c.id, label: c.name }));
    },
    priorityOptions() {
      return this.priorities.map(p => ({ id: p, label: this.$t(p) }));
    },
    totalAmount() {
      return this.form.items.reduce((sum, item) => sum + this.computeAmount(item), 0);
    },
  },

  methods: {
    blankItem() {
      return {
        key: ++keySeq, payee_type: 'External Personnel', contact_id: null, staff_id: null,
        priority: 'Normal', work_description: DEFAULT_WORK_ITEM, rate_type: 'day', days: 1, hours: '', rate: '',
        workforce_agreement_id: null,
      };
    },
    setPersonnelRef(el, index) {
      if (el) this.personnelRefs[index] = el;
      else delete this.personnelRefs[index];
    },
    async addItem() {
      this.form.items.push(this.blankItem());
      const newIndex = this.form.items.length - 1;
      await nextTick();
      const target = this.personnelRefs[newIndex];
      const input = target?.$el?.querySelector('input');
      input?.focus();
    },
    removeItem(index) {
      if (this.form.items.length > 1) this.form.items.splice(index, 1);
    },
    onPersonnelCreated(contact) {
      this.localContacts = [...this.localContacts, contact].sort((a, b) => a.name.localeCompare(b.name));
    },
    personnelIdFor(item) {
      return item.payee_type === 'Internal Staff' ? item.staff_id : item.contact_id;
    },
    hasPersonnelAndProject(item) {
      return !!(this.personnelIdFor(item) && this.form.warehouse_id);
    },
    agreementFetchKey(item) {
      return [item.payee_type, this.personnelIdFor(item) || '', this.form.warehouse_id || ''].join('|');
    },
    loadAgreements(item) {
      const personnelId = this.personnelIdFor(item);
      if (!personnelId || !this.form.warehouse_id) {
        if (this.agreementsByItem[item.key]) {
          const next = { ...this.agreementsByItem };
          delete next[item.key];
          this.agreementsByItem = next;
        }
        this._agreementKeys = this._agreementKeys || {};
        delete this._agreementKeys[item.key];
        return;
      }

      const key = this.agreementFetchKey(item);
      this._agreementKeys = this._agreementKeys || {};
      if (this._agreementKeys[item.key] === key) return;
      this._agreementKeys[item.key] = key;

      this.agreementLoadFailed = { ...this.agreementLoadFailed, [item.key]: false };

      axios.get(this.route('workforce-agreements.for-personnel'), {
        params: {
          warehouse_id: this.form.warehouse_id,
          payee_type: item.payee_type,
          contact_id: item.payee_type === 'External Personnel' ? personnelId : null,
          staff_id: item.payee_type === 'Internal Staff' ? personnelId : null,
        },
      }).then(res => {
        const agreements = res.data.agreements;
        this.agreementsByItem = { ...this.agreementsByItem, [item.key]: agreements };
        if (agreements.length === 1) {
          item.workforce_agreement_id = agreements[0].id;
        } else if (!agreements.some(a => a.id === item.workforce_agreement_id)) {
          item.workforce_agreement_id = null;
        }
      }).catch(err => {
        // Without this, a permission gap (the user can create Labour but
        // wasn't also granted read-workforce-agreements) or any other
        // failure left the agreement dropdown silently empty — identical to
        // "this worker genuinely has no agreement" — and since a line with
        // no agreement selected is never balance-checked, the whole
        // outstanding-balance safeguard disappeared with no indication
        // anything had gone wrong.
        console.error('Failed to load workforce agreements for this worker.', err);
        this.agreementLoadFailed = { ...this.agreementLoadFailed, [item.key]: true };
      });
    },
    onAgreementChanged(item) {
      item.workforce_agreement_id = item.workforce_agreement_id ? Number(item.workforce_agreement_id) : null;
    },
    agreementOptionsFor(item) {
      return this.agreementsByItem[item.key] || [];
    },
    selectedAgreementFor(item) {
      return this.agreementOptionsFor(item).find(a => a.id === item.workforce_agreement_id);
    },
    exceedsBalance(item) {
      const agreement = this.selectedAgreementFor(item);
      if (!agreement) return false;
      return this.computeAmount(item) > Number(agreement.outstanding_balance || 0);
    },
    createAgreementUrl(item) {
      const params = new URLSearchParams();
      if (this.form.warehouse_id) params.set('warehouse_id', this.form.warehouse_id);
      if (item.payee_type) params.set('payee_type', item.payee_type);
      if (item.payee_type === 'Internal Staff' && item.staff_id) params.set('staff_id', item.staff_id);
      if (item.payee_type === 'External Personnel' && item.contact_id) params.set('contact_id', item.contact_id);
      const query = params.toString();
      return this.route('workforce-agreements.create') + (query ? '?' + query : '');
    },
    computeAmount(item) {
      const rate = Number(item.rate || 0);
      if (item.rate_type === 'day') return rate * Number(item.days || 0);
      if (item.rate_type === 'hour') return rate * Number(item.hours || 0);
      return rate;
    },
    money(v) {
      return 'TZS ' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    onFilesPicked(e) {
      const files = Array.from(e.target.files || []);
      files.forEach(file => this.attachmentRows.push({ key: ++attachmentKeySeq, file }));
      this.syncAttachments();
      e.target.value = '';
    },
    removeAttachment(index) {
      this.attachmentRows.splice(index, 1);
      this.syncAttachments();
    },
    syncAttachments() {
      this.form.attachments = this.attachmentRows.map(r => r.file);
    },
    formatBytes(bytes) {
      if (!bytes) return '0 KB';
      const kb = bytes / 1024;
      return kb < 1024 ? `${kb.toFixed(0)} KB` : `${(kb / 1024).toFixed(1)} MB`;
    },
    submit() {
      const url = this.edit ? this.route('labour.update', this.edit.id) : this.route('labour.store');
      const method = this.edit ? 'put' : 'post';
      this.form.transform(data => ({ ...data, _method: method })).post(url, { forceFormData: true });
    },
  },
};
</script>

<style scoped>
.lbr-form :deep(.lg-fields label:not(:has(input[type='checkbox']))),
.lbr-label {
  display: block;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
}
.lbr-label { margin-bottom: 0.25rem; }
.lbr-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file'])),
.lbr-form :deep(.lg-fields select),
.lbr-form :deep(.lg-fields textarea),
.lbr-select {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  padding-top: 0.6rem !important;
  padding-bottom: 0.6rem !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.lbr-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file']):hover),
.lbr-form :deep(.lg-fields select:hover),
.lbr-select:hover {
  border-color: rgba(99, 102, 241, 0.6) !important;
}
.lbr-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file']):focus),
.lbr-form :deep(.lg-fields select:focus),
.lbr-form :deep(.lg-fields textarea:focus),
.lbr-select:focus {
  outline: none;
  border-color: #6366f1 !important;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.18) !important;
}
/* TextareaInput's own auto-resize hook sets an inline height based on
   scrollHeight, which resolves to ~1 line for empty content regardless of a
   `rows` attribute; min-height still wins over that smaller inline height. */
.lbr-form :deep(textarea) { min-height: 76px; }

.lbr-personnel-head { flex-wrap: wrap; }
.lbr-head-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-left: auto; }

.lbr-item-card { border: 1px solid var(--lg-line); border-radius: 18px; padding: 1rem 1.05rem 0.6rem; margin-bottom: 0.9rem; background: var(--lg-soft); }
.lbr-item-head { display: flex; align-items: center; gap: 10px; margin-bottom: 0.85rem; }
.lbr-item-badge { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 26px; height: 26px; border-radius: 9px; font-size: 0.74rem; font-weight: 800; color: #fff; background: linear-gradient(145deg, #818cf8, #4338ca); }
.lbr-item-title { flex: 1; min-width: 0; font-size: 0.74rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.lbr-remove-btn { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; flex: none; border-radius: 9999px; background: #fee2e2; color: #b91c1c; transition: background 0.15s ease, transform 0.15s ease; }
.lbr-remove-btn:hover { background: #fecaca; transform: translateY(-1px); }

.lbr-payee-toggle { display: inline-flex; max-width: 100%; flex-wrap: wrap; border-radius: 9999px; background: var(--lg-card); border: 1px solid var(--lg-line); padding: 3px; gap: 3px; }
.lbr-payee-btn { padding: 0.4rem 0.95rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 800; color: var(--lg-mute); transition: background 0.15s ease, color 0.15s ease; }
.lbr-payee-btn:hover { color: var(--lg-ink); }
.lbr-payee-active, .lbr-payee-active:hover { background: linear-gradient(145deg, #2f6fb3, #0f2540); color: #fff; box-shadow: 0 8px 14px -8px rgba(15, 37, 64, 0.9); }

.lbr-rate-grid { grid-template-columns: repeat(auto-fit, minmax(min(150px, 100%), 1fr)); }
.lbr-rate-grid .mb-2 { margin-bottom: 0 !important; }
.lbr-select { display: block; width: 100%; min-height: 42px; border: 1px solid var(--lg-line); padding: 0.5rem 0.75rem; font-size: 0.875rem; }
.lbr-amount-display { display: flex; align-items: center; min-height: 42px; box-sizing: border-box; padding: 0.5rem 0.85rem; border: 1px solid var(--lg-line); border-radius: 0.85rem; background: rgba(16, 185, 129, 0.08); font-weight: 800; color: #047857; font-size: 0.875rem; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }

.lbr-agreement-box { border: 1px solid rgba(147, 197, 253, 0.55); background: rgba(219, 234, 254, 0.55); border-radius: 16px; padding: 0.8rem 0.95rem; font-size: 0.82rem; }
.lbr-agreement-hint { margin-top: 0.4rem; font-size: 0.78rem; color: #1d4ed8; }
.lbr-agreement-warn { margin-top: 0.5rem; padding: 0.55rem 0.75rem; border: 1px solid rgba(248, 113, 113, 0.4); background: rgba(254, 226, 226, 0.65); border-radius: 12px; font-size: 0.74rem; font-weight: 600; color: #b91c1c; }
.lbr-create-agreement-link { display: inline-flex; align-items: center; gap: 5px; margin-top: 0.5rem; padding: 0.4rem 0.85rem; border-radius: 9999px; font-size: 0.74rem; font-weight: 800; color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); box-shadow: 0 8px 14px -8px rgba(15, 37, 64, 0.9); transition: transform 0.15s ease; }
.lbr-create-agreement-link:hover { transform: translateY(-1px); }
.lbr-agreement-note { border: 1px solid rgba(245, 158, 11, 0.35); background: rgba(254, 243, 199, 0.6); border-radius: 14px; padding: 0.6rem 0.9rem; font-size: 0.76rem; color: #92400e; }

.lbr-total-bar { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; padding: 0.85rem 1.1rem; border-radius: 16px; background: linear-gradient(135deg, rgba(224, 231, 255, 0.7), rgba(219, 234, 254, 0.5)); border: 1px solid rgba(165, 180, 252, 0.45); font-size: 0.85rem; font-weight: 700; color: var(--lg-mute); }
.lbr-total-count { display: inline-flex; align-items: center; gap: 7px; }
.lbr-total-value { font-size: 1.1rem; font-weight: 800; color: #1c3f66; font-variant-numeric: tabular-nums; }
.lbr-bar-total { font-size: 0.78rem; font-weight: 700; color: var(--lg-mute); font-variant-numeric: tabular-nums; }
.lbr-bar-total strong { color: var(--lg-ink); font-weight: 800; }
@media (max-width: 640px) { .lbr-bar-total { display: none; } }

.lbr-attachment-list { display: flex; flex-direction: column; gap: 0.5rem; }
.lbr-attachment-row { display: flex; align-items: center; gap: 0.6rem; padding: 0.55rem 0.8rem; border: 1px solid var(--lg-line); border-radius: 14px; background: var(--lg-soft); }
.lbr-attachment-ico { color: var(--lg-faint); }
.lbr-attachment-name { flex: 1; min-width: 0; font-size: 0.83rem; color: var(--lg-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lbr-attachment-size { font-size: 0.72rem; color: var(--lg-faint); flex: none; }
.lbr-attachment-remove { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; flex: none; border-radius: 9999px; background: #fee2e2; color: #b91c1c; }
.lbr-attachment-remove:hover { background: #fecaca; }
.lbr-file-pick { display: inline-flex; align-items: center; gap: 6px; padding: 0.5rem 1rem; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; background: rgba(99, 102, 241, 0.1); color: #4338ca; border: 1px dashed rgba(99, 102, 241, 0.45); cursor: pointer; transition: background 0.15s ease; }
.lbr-file-pick:hover { background: rgba(99, 102, 241, 0.18); }
.lbr-err { font-size: 0.78rem; font-weight: 600; color: #be123c; }

html.dark .lbr-remove-btn, html.dark .lbr-attachment-remove { background: rgba(185, 28, 28, 0.25); color: #fecaca; }
html.dark .lbr-amount-display { background: rgba(16, 185, 129, 0.12); color: #6ee7b7; }
html.dark .lbr-agreement-box { background: rgba(29, 78, 216, 0.12); border-color: rgba(191, 219, 254, 0.25); }
html.dark .lbr-agreement-hint { color: #93c5fd; }
html.dark .lbr-agreement-note { background: rgba(146, 64, 14, 0.14); border-color: rgba(253, 230, 138, 0.25); color: #fcd34d; }
html.dark .lbr-agreement-warn { background: rgba(185, 28, 28, 0.15); border-color: rgba(254, 202, 202, 0.25); color: #fecaca; }
html.dark .lbr-total-bar { background: rgba(99, 102, 241, 0.12); border-color: rgba(165, 180, 252, 0.25); }
html.dark .lbr-total-value { color: #bfdbfe; }
html.dark .lbr-file-pick { color: #c7d2fe; background: rgba(99, 102, 241, 0.16); }
html.dark .lbr-err { color: #fda4af; }
</style>

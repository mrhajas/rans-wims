<template>
  <admin-layout :title="isNew ? $t('New Contract Addendum') : $t('Addendum No.') + ' ' + addendum.addendum_number">
    <div class="lg-page px-4 md:px-0 pc-form pc-addendum">
      <page-header
        icon="doc-text"
        :title="isNew ? $t('New Contract Addendum') : $t('Addendum No.') + ' ' + addendum.addendum_number"
        :subtitle="warehouse.name + ' (' + warehouse.code + ')'"
      >
        <span v-if="!isNew" class="lg-chip" :class="statusTone(addendum.status)">{{ addendum.status.replace('_', ' ') }}</span>
        <Link :href="route('project-certificates.contract.edit', warehouse.id)" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Contract Setup') }}
        </Link>
      </page-header>

      <stat-tiles :items="contextTiles" />

      <!-- Details -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Title / Description') }}</h2>
            <p class="lg-card-sub">{{ warehouse.name }} &middot; {{ $t('Contract Setup') }}</p>
          </div>
        </header>
        <div class="lg-fields">
          <div class="lg-span-all">
            <label class="pc-label">{{ $t('Title / Description') }} <span class="pc-req">*</span></label>
            <input v-model="form.title" type="text" class="pc-input" :disabled="!canEdit" />
          </div>
          <div class="lg-span-all">
            <label class="pc-label">{{ $t('Reason / Purpose') }}</label>
            <textarea v-model="form.reason" rows="2" class="pc-input" :disabled="!canEdit"></textarea>
          </div>
          <div>
            <label class="pc-label">{{ $t('Addendum Date') }}</label>
            <input v-model="form.addendum_date" type="date" class="pc-input" :disabled="!canEdit" />
          </div>
          <div>
            <label class="pc-label">{{ $t('Reference Number') }}</label>
            <input v-model="form.reference_number" type="text" class="pc-input" :disabled="!canEdit" />
          </div>
          <div>
            <label class="pc-label">{{ $t('Effective Date') }}</label>
            <input v-model="form.effective_date" type="date" class="pc-input" :disabled="!canEdit" />
          </div>
        </div>
      </section>

      <!-- Items -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="table" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Addendum Items') }}</h2>
            <p class="lg-card-sub">{{ form.items.length }} &middot; {{ contract.currency }} {{ grandTotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</p>
          </div>
          <button v-if="canEdit" type="button" class="lg-btn lg-btn-soft pc-sm" @click="addItem"><icons name="plus" class="w-3.5 h-3.5" /> {{ $t('Add Item') }}</button>
        </header>

        <div v-for="(item, idx) in form.items" :key="idx" class="pc-item-card">
          <div class="pc-item-head">
            <span class="lg-chip lg-chip-indigo">{{ $t('Item') }} {{ idx + 1 }}</span>
            <button v-if="canEdit && form.items.length > 1" type="button" class="lg-icon-btn is-red" :aria-label="$t('Remove')" @click="removeItem(idx)"><icons name="trash" class="w-4 h-4" /></button>
          </div>

          <div class="lg-fields">
            <div class="lg-span-all">
              <label class="pc-label">{{ $t('Description') }}</label>
              <input v-model="item.description" type="text" class="pc-input" :disabled="!canEdit" />
            </div>
            <div class="lg-span-all pc-dims">
              <div>
                <label class="pc-label">{{ $t('Length') }}</label>
                <input v-model="item.length" @input="onDimensionInput(item)" type="number" step="0.001" class="pc-input pc-num min-w-0" :disabled="!canEdit" />
              </div>
              <div>
                <label class="pc-label">{{ $t('Width') }}</label>
                <input v-model="item.width" @input="onDimensionInput(item)" type="number" step="0.001" class="pc-input pc-num min-w-0" :disabled="!canEdit" />
              </div>
              <div>
                <label class="pc-label">{{ $t('Depth') }}</label>
                <input v-model="item.height" @input="onDimensionInput(item)" type="number" step="0.001" class="pc-input pc-num min-w-0" :disabled="!canEdit" />
              </div>
            </div>
            <div>
              <label class="pc-label">{{ $t('Unit') }}</label>
              <auto-complete v-if="canEdit" v-model="item.unit" :suggestions="unitOptions" freeform placeholder="e.g. m², m³, No." />
              <input v-else :value="item.unit" type="text" class="pc-input" disabled />
            </div>
            <div>
              <label class="pc-label">
                {{ $t('Quantity') }}
                <span
                  v-if="hasDimensions(item)" class="pc-info lg-tip" tabindex="0"
                  :data-tip="$t('Auto-filled from Length × Width × Depth when you enter them (blank dimensions count as 1) · edit it directly any time to override.')"
                >
                  <icons name="info" class="w-3 h-3" />
                </span>
              </label>
              <input v-model="item.quantity" type="number" step="0.001" class="pc-input pc-num" :disabled="!canEdit" />
            </div>
            <div>
              <label class="pc-label">{{ $t('Unit Rate') }}</label>
              <input
                :value="displayItemRate(item)"
                @focus="focusedRateIndex = idx"
                @input="onRateInput(item, $event)"
                @blur="focusedRateIndex = null"
                type="text" inputmode="decimal" class="pc-input pc-num" :disabled="!canEdit"
              />
            </div>
            <div>
              <label class="pc-label">{{ $t('Amount') }}</label>
              <div class="pc-item-amount">{{ contract.currency }} {{ itemAmount(item).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</div>
            </div>
          </div>
        </div>

        <div class="pc-grand">
          <span class="pc-grand-label">{{ $t('Total Addendum Value') }}</span>
          <span class="pc-grand-value">{{ contract.currency }} {{ grandTotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</span>
        </div>
      </section>

      <!-- Signing, approval & audit -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-teal"><icons name="tick" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Signing, Approval & Audit') }}</h2>
          </div>
        </header>
        <div class="lg-fields">
          <div>
            <label class="pc-label">{{ $t('Signed Date') }}</label>
            <input v-model="form.signed_date" type="date" class="pc-input" :disabled="!canEdit" />
          </div>
          <div>
            <label class="pc-label">{{ $t('Signed By') }}</label>
            <auto-complete v-if="canEdit" v-model="form.signed_by" :suggestions="clientOptions" freeform :placeholder="$t('Client contact name')" />
            <input v-else :value="form.signed_by" type="text" class="pc-input" disabled />
          </div>
          <div class="lg-span-all">
            <label class="pc-label">{{ $t('Notes & Remarks') }}</label>
            <textarea v-model="form.notes" rows="3" class="pc-input" :disabled="!canEdit"></textarea>
          </div>
        </div>

        <div v-if="!isNew" class="pc-audit">
          <div>
            <span class="pc-label"><icons name="user" class="w-3 h-3" /> {{ $t('Created By') }}</span>
            <span class="pc-audit-value">{{ addendum.created_by_name || '—' }} · {{ shortDate(addendum.created_at) }}</span>
          </div>
          <div>
            <span class="pc-label"><icons name="tick" class="w-3 h-3" /> {{ $t('Approved By') }}</span>
            <span class="pc-audit-value">{{ addendum.approved_by_name || '—' }} <template v-if="addendum.approved_at">· {{ shortDate(addendum.approved_at) }}</template></span>
          </div>
        </div>

        <div v-if="!isNew && canApprove" class="pc-approval">
          <span class="pc-label !mb-0 mr-1">{{ $t('Change Status') }}:</span>
          <span class="lg-chip lg-chip-blue pc-status-now">{{ addendum.status.replace('_', ' ') }}</span>
          <button
            v-for="s in allowedStatusTargets" :key="s"
            type="button" class="lg-btn lg-btn-soft pc-sm pc-cap"
            :disabled="statusSaving"
            @click="setStatus(s)"
          >
            {{ s.replace('_', ' ') }}
          </button>
          <span v-if="!allowedStatusTargets.length" class="lg-subtext">{{ $t('Locked: no further status changes.') }}</span>
        </div>
      </section>

      <!-- Attachments -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="clip" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
            <p class="lg-card-sub">{{ $t('Signed addendums, drawings, BOQs, quotations, or other supporting documents.') }}</p>
          </div>
        </header>
        <ul v-if="existingAttachments.length" class="pc-filelist mb-3">
          <li v-for="file in existingAttachments" :key="file.id">
            <span>{{ file.name }}</span>
            <div class="flex items-center gap-1 flex-none">
              <a :href="file.download_url" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')">
                <icons name="download" class="w-4 h-4" />
              </a>
              <button v-if="canEdit" type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="removeAttachment(file)">
                <icons name="trash" class="w-4 h-4" />
              </button>
            </div>
          </li>
        </ul>
        <attachment-picker v-if="canEdit" v-model="newAttachments" :max-files="10" :help-text="$t('Up to 10 files.')" />
      </section>

      <div v-if="canEdit" class="lg-actionbar pc-bar">
        <span class="lg-actionbar-note">{{ contract.currency }} {{ grandTotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</span>
        <div class="lg-actionbar-btns">
          <button v-if="!isNew" type="button" class="lg-btn lg-btn-red" :disabled="deleting" @click="destroyAddendum">
            <icons name="trash" class="w-4 h-4" /> {{ $t('Delete Addendum') }}
          </button>
          <button type="button" class="lg-btn lg-btn-primary" :disabled="saving || !form.title" @click="save">
            <icons name="tick" class="w-4 h-4" /> {{ saving ? $t('Saving…') : (isNew ? $t('Create Addendum') : $t('Save Changes')) }}
          </button>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AttachmentPicker from '@/Shared/AttachmentPicker.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, Link, AttachmentPicker, AutoComplete, PageHeader, StatTiles },

  props: {
    warehouse: Object,
    contract: Object,
    addendum: Object,
    canEdit: Boolean,
    canApprove: Boolean,
    units: { type: Array, default: () => [] },
    clientContacts: { type: Array, default: () => [] },
  },

  data() {
    const today = this.todayIso();

    return {
      saving: false,
      deleting: false,
      statusSaving: false,
      focusedRateIndex: null,
      newAttachments: [],
      existingAttachments: this.addendum?.attachments ? [...this.addendum.attachments] : [],
      form: {
        title: this.addendum?.title ?? '',
        reason: this.addendum?.reason ?? '',
        // A brand-new addendum defaults its 3 dates to today — the QS
        // changes whichever one isn't actually today (e.g. backdating to
        // match a signed paper variation order). An existing addendum
        // keeps whatever is already saved, blank or not.
        addendum_date: this.addendum?.addendum_date ?? today,
        reference_number: this.addendum?.reference_number ?? '',
        effective_date: this.addendum?.effective_date ?? today,
        notes: this.addendum?.notes ?? '',
        signed_date: this.addendum?.signed_date ?? today,
        signed_by: this.addendum?.signed_by ?? '',
        items: this.addendum?.items?.length
          ? this.addendum.items.map(i => ({ ...i }))
          : [this.blankItem()],
      },
    };
  },

  computed: {
    contextTiles() {
      const c = this.contract;

      return [
        { label: this.$t('Original Contract Sum'), value: c.currency + ' ' + this.money(c.original_contract_sum), icon: 'cash', tone: 'navy' },
        { label: this.$t('Approved Addendums to Date'), value: c.currency + ' ' + this.money(c.total_addendum_sum), icon: 'doc-text', tone: 'indigo' },
        { label: this.$t('Current Total Project Amount'), value: c.currency + ' ' + this.money(c.total_project_amount), icon: 'building', tone: 'rose' },
      ];
    },
    isNew() {
      return !this.addendum;
    },
    grandTotal() {
      return this.form.items.reduce((sum, item) => sum + this.itemAmount(item), 0);
    },
    unitOptions() {
      return this.units.map(u => ({ id: u.name, label: u.name }));
    },
    clientOptions() {
      return this.clientContacts.map(c => ({ id: c.name, label: c.name }));
    },
    allowedStatusTargets() {
      // Mirrors ProjectCertificateContractAddendumController::updateStatus()'s
      // transition map — keeps the buttons shown in sync with what the
      // server will actually allow, so a click never just bounces off a 422.
      const transitions = {
        draft: ['pending_approval'],
        pending_approval: ['approved', 'rejected', 'draft'],
        rejected: ['draft', 'pending_approval'],
        approved: [],
      };

      return transitions[this.addendum?.status] || [];
    },
  },

  methods: {
    statusTone(status) {
      return { draft: 'lg-chip-slate', pending_approval: 'lg-chip-amber', approved: 'lg-chip-blue', rejected: 'lg-chip-red' }[status] || 'lg-chip-slate';
    },
    todayIso() {
      const d = new Date();
      return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    },
    blankItem() {
      return { description: '', length: '', width: '', height: '', unit: '', quantity: 0, unit_rate: 0 };
    },
    hasDimensions(item) {
      return !!(item.length || item.width || item.height);
    },
    onDimensionInput(item) {
      if (this.hasDimensions(item)) {
        const l = Number(item.length) || 1;
        const w = Number(item.width) || 1;
        const h = Number(item.height) || 1;
        item.quantity = Math.round(l * w * h * 1000) / 1000;
      }
    },
    // Rounds quantity to 3dp and unit_rate to 2dp BEFORE multiplying — matches
    // ProjectCertificateContractAddendumController::syncItems() exactly, so
    // this live total never drifts a fraction of a cent from what saves.
    itemAmount(item) {
      const quantity = Math.round((Number(item.quantity) || 0) * 1000) / 1000;
      const unitRate = Math.round((Number(item.unit_rate) || 0) * 100) / 100;

      return Math.round(quantity * unitRate * 100) / 100;
    },
    displayItemRate(item) {
      return Number(item.unit_rate || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    onRateInput(item, event) {
      const raw = event.target.value.replace(/[^0-9.]/g, '');
      item.unit_rate = raw === '' ? 0 : Number(raw);
    },
    money(v) {
      return Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    shortDate(v) {
      return v ? String(v).slice(0, 10) : '—';
    },
    addItem() {
      this.form.items.push(this.blankItem());
    },
    removeItem(idx) {
      this.form.items.splice(idx, 1);
    },
    save() {
      this.saving = true;
      const payload = { ...this.form, attachments: this.newAttachments };
      const url = this.isNew
        ? this.route('project-certificates.contract.addendums.store', this.warehouse.id)
        : this.route('project-certificates.contract.addendums.update', [this.warehouse.id, this.addendum.id]);

      this.$inertia.post(url, payload, {
        onSuccess: () => { this.newAttachments = []; },
        onFinish: () => { this.saving = false; },
      });
    },
    setStatus(status) {
      this.statusSaving = true;
      this.$inertia.post(this.route('project-certificates.contract.addendums.status', [this.warehouse.id, this.addendum.id]), { status }, {
        preserveScroll: true,
        onFinish: () => { this.statusSaving = false; },
      });
    },
    async destroyAddendum() {
      if (!(await this.$confirm(this.$t('Delete this addendum? This cannot be undone.'), { variant: 'danger' }))) return;
      this.deleting = true;
      this.$inertia.delete(this.route('project-certificates.contract.addendums.destroy', [this.warehouse.id, this.addendum.id]), {
        onFinish: () => { this.deleting = false; },
      });
    },
    async removeAttachment(file) {
      if (!(await this.$confirm(this.$t('Remove this attachment? This cannot be undone.'), { variant: 'danger' }))) return;
      this.$inertia.delete(file.delete_url, {
        preserveScroll: true,
        onSuccess: () => { this.existingAttachments = this.existingAttachments.filter(f => f.id !== file.id); },
      });
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


/* ---------- Addendum form ---------- */
.pc-num { font-variant-numeric: tabular-nums; }
.pc-bar { margin-bottom: 1.5rem; }
.pc-cap { text-transform: capitalize; }
.pc-item-card { padding: 1rem; margin-bottom: 0.85rem; border: 1px solid var(--lg-line); border-radius: 18px; background: var(--lg-soft); }
.pc-item-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 0.8rem; }
.pc-dims { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.6rem; }
.pc-item-amount { padding: 0.55rem 0.8rem; border-radius: 0.85rem; border: 1px solid rgba(190, 18, 60, 0.25); background: rgba(244, 63, 94, 0.08); font-size: 0.85rem; font-weight: 800; font-variant-numeric: tabular-nums; color: #be123c; overflow-wrap: anywhere; }
html.dark .pc-item-amount { color: #fda4af; }
.pc-grand { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 0.9rem 1.15rem; border-radius: 16px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.pc-grand-label { font-size: 0.7rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-mute); }
.pc-grand-value { font-size: 1.35rem; font-weight: 800; font-variant-numeric: tabular-nums; color: #be123c; }
html.dark .pc-grand-value { color: #fda4af; }
.pc-audit { display: grid; grid-template-columns: 1fr; gap: 1rem; margin-top: 1rem; padding: 0.85rem 1.1rem; border-radius: 16px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
@media (min-width: 640px) { .pc-audit { grid-template-columns: repeat(2, 1fr); } }
.pc-audit .pc-label { margin-bottom: 0.15rem; }
.pc-audit-value { display: block; font-size: 0.85rem; font-weight: 700; color: var(--lg-ink); }
.pc-approval { display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-top: 1rem; }
.pc-status-now { text-transform: capitalize; }
</style>

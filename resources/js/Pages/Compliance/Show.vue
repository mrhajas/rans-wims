<template>
  <admin-layout :title="record.title">
    <div class="lg-page px-4 md:px-0 cp-show">
      <header class="lg-hero print:hidden">
        <img
          v-if="record.photo_url"
          :src="record.photo_url"
          class="cp-hero-photo"
          alt=""
          @mouseenter="showPhotoZoom($event, record.photo_url, record.title)"
          @mouseleave="hidePhotoZoom"
        />
        <span v-else class="lg-hero-icon"><icons name="clip" class="w-6 h-6" /></span>
        <div class="lg-hero-body">
          <h1 class="lg-title">{{ record.title }}</h1>
          <p class="lg-sub">{{ record.compliance_number }} &middot; {{ record.compliance_type }}</p>
          <div class="cp-chips">
            <span class="lg-chip" :class="statusBadgeClass(record.status)" style="text-transform: none">{{ record.status }}</span>
            <span class="lg-chip lg-chip-slate" style="text-transform: none">{{ record.priority }}</span>
            <span class="lg-chip" :class="daysBadgeClass(record.days_remaining)" style="text-transform: none"><icons name="clock" class="w-3 h-3" />{{ record.days_remaining_label }}</span>
          </div>
        </div>
        <div class="lg-hero-actions">
          <a v-if="$can('read-compliance-records')" :href="route('compliance-records.print', record.id)" target="_blank" class="lg-btn lg-btn-soft lg-tip" :data-tip="$t('Print full record: all tabs')"><icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}</a>
          <a v-if="$can('read-compliance-records')" :href="route('compliance-records.pdf', record.id)" class="lg-btn lg-btn-soft lg-tip" :data-tip="$t('Download full record as PDF')"><icons name="download" class="w-4 h-4" /> {{ $t('Download') }}</a>
          <Link v-if="$can('update-compliance-records')" :href="route('compliance-records.edit', record.id)" class="lg-btn lg-btn-primary"><icons name="edit" class="w-4 h-4" /> {{ $t('Edit') }}</Link>
          <button v-if="$can('start-compliance-renewal') && !hasActiveRenewal && !isTerminal" type="button" class="lg-btn lg-btn-green" @click="startRenewal"><icons name="refresh" class="w-4 h-4" /> {{ $t('Start Renewal') }}</button>
          <template v-if="hasActiveRenewal">
            <button v-if="$can('start-compliance-renewal') && activeRenewal.status === 'Renewal in Progress'" type="button" class="lg-btn lg-btn-green" @click="openModal('submit')">{{ $t('Submit') }}</button>
            <button v-if="$can('approve-compliance-renewal') && activeRenewal.status === 'Submitted'" type="button" class="lg-btn lg-btn-green" @click="approveRenewal">{{ $t('Approve') }}</button>
            <button v-if="$can('complete-compliance-renewal') && activeRenewal.status === 'Pending Approval'" type="button" class="lg-btn lg-btn-primary" @click="openModal('complete')">{{ $t('Complete Renewal') }}</button>
            <button v-if="$can('approve-compliance-renewal')" type="button" class="lg-btn lg-btn-red" @click="rejectRenewal">{{ $t('Reject') }}</button>
            <button v-if="$can('start-compliance-renewal')" type="button" class="lg-btn lg-btn-red" @click="cancelRenewal">{{ $t('Cancel Renewal') }}</button>
          </template>
        </div>
      </header>

      <div class="cp-tabs">
        <button v-for="tab in tabs" :key="tab.key" type="button" class="cp-tab" :class="{ 'is-active': activeTab === tab.key }" @click="activeTab = tab.key">
          <icons :name="tabIcon(tab.key)" class="w-4 h-4" />{{ $t(tab.label) }}
        </button>
      </div>

      <!-- Overview -->
      <div v-if="activeTab === 'overview'" class="cp-grid">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="hash" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h3 class="lg-card-title">{{ $t('Identification') }}</h3></div>
          </header>
          <dl class="cp-dl">
            <div><dt>{{ $t('Reference Number') }}</dt><dd>{{ record.reference_number || '-' }}</dd></div>
            <div><dt>{{ $t('Issuing Authority') }}</dt><dd>{{ record.issuing_authority || '-' }}</dd></div>
            <div><dt>{{ $t('Responsible Person') }}</dt><dd>{{ (record.responsible_user && record.responsible_user.name) || '-' }}</dd></div>
            <div><dt>{{ $t('Department') }}</dt><dd>{{ record.department || '-' }}</dd></div>
            <div><dt>{{ $t('Scope') }}</dt><dd>{{ record.scope }}</dd></div>
          </dl>
        </section>
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="calendar" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h3 class="lg-card-title">{{ $t('Dates') }}</h3></div>
          </header>
          <dl class="cp-dl">
            <div><dt>{{ $t('Issue Date') }}</dt><dd>{{ record.issue_date ? $date(record.issue_date) : '-' }}</dd></div>
            <div><dt>{{ $t('Expiry Date') }}</dt><dd>{{ record.expiry_date ? $date(record.expiry_date) : '-' }}</dd></div>
            <div><dt>{{ $t('Renewal Date') }}</dt><dd>{{ record.renewal_date ? $date(record.renewal_date) : '-' }}</dd></div>
            <div><dt>{{ $t('Next Review Date') }}</dt><dd>{{ record.next_review_date ? $date(record.next_review_date) : '-' }}</dd></div>
          </dl>
        </section>
        <section class="lg-card" v-if="record.warehouse || record.supplier || record.asset">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="link" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h3 class="lg-card-title">{{ $t('Relationships') }}</h3></div>
          </header>
          <dl class="cp-dl">
            <div v-if="record.warehouse"><dt>{{ $t('Project') }}</dt><dd>{{ record.warehouse.name }}</dd></div>
            <div v-if="record.supplier"><dt>{{ $t('Supplier') }}</dt><dd>{{ record.supplier.name }}</dd></div>
            <div v-if="record.asset"><dt>{{ $t('Asset') }}</dt><dd>{{ record.asset.name }} ({{ record.asset.asset_number }})</dd></div>
          </dl>
        </section>
        <section class="lg-card" v-if="record.guarantee_number || record.loan_reference || record.insurance_policy_number || record.contract_number">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h3 class="lg-card-title">{{ $t('Financial') }}</h3></div>
          </header>
          <dl class="cp-dl">
            <div v-if="record.guarantee_amount"><dt>{{ $t('Guarantee Amount') }}</dt><dd class="cp-num">{{ $money(record.guarantee_amount) }}</dd></div>
            <div v-if="record.loan_outstanding_balance"><dt>{{ $t('Outstanding Balance') }}</dt><dd class="cp-num">{{ $money(record.loan_outstanding_balance) }}</dd></div>
            <div v-if="record.insurance_insured_value"><dt>{{ $t('Insured Value') }}</dt><dd class="cp-num">{{ $money(record.insurance_insured_value) }}</dd></div>
            <div v-if="record.contract_value"><dt>{{ $t('Contract Value') }}</dt><dd class="cp-num">{{ $money(record.contract_value) }}</dd></div>
          </dl>
        </section>
        <section class="lg-card" v-if="record.licence_or_certificate_holder || record.renewal_fee || record.renewal_requirements">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h3 class="lg-card-title">{{ $t('Licence / Certificate Details') }}</h3></div>
          </header>
          <dl class="cp-dl">
            <div v-if="record.licence_or_certificate_holder"><dt>{{ $t('Holder') }}</dt><dd>{{ record.licence_or_certificate_holder }}</dd></div>
            <div v-if="record.renewal_fee"><dt>{{ $t('Renewal Fee') }}</dt><dd class="cp-num">{{ $money(record.renewal_fee) }}</dd></div>
            <div v-if="record.renewal_requirements"><dt>{{ $t('Renewal Requirements') }}</dt><dd>{{ record.renewal_requirements }}</dd></div>
          </dl>
        </section>
      </div>

      <!-- Renewal -->
      <section v-if="activeTab === 'renewal'" class="lg-card cp-flush">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Status') }}</th>
                <th>{{ $t('Previous Expiry') }}</th>
                <th>{{ $t('New Expiry') }}</th>
                <th>{{ $t('Started') }}</th>
                <th>{{ $t('Renewed') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="rh in record.renewal_history" :key="rh.id">
                <td :data-label="$t('Status')"><span class="lg-chip" :class="statusBadgeClass(rh.status)" style="text-transform: none">{{ rh.status }}</span></td>
                <td :data-label="$t('Previous Expiry')">{{ rh.previous_expiry_date ? $date(rh.previous_expiry_date) : '-' }}</td>
                <td :data-label="$t('New Expiry')">{{ rh.new_expiry_date ? $date(rh.new_expiry_date) : '-' }}</td>
                <td :data-label="$t('Started')" class="cp-dim">{{ rh.renewal_started_at ? $datetime(rh.renewal_started_at) : '-' }}</td>
                <td :data-label="$t('Renewed')" class="cp-dim">{{ rh.renewed_at ? $datetime(rh.renewed_at) : '-' }}</td>
              </tr>
              <tr v-if="!record.renewal_history.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('No renewal cycles yet.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Documents -->
      <div v-if="activeTab === 'documents'">
        <form class="lg-card cp-upload-bar" @submit.prevent="uploadDocuments">
          <input v-model="documentType" type="text" :placeholder="$t('Document Type (e.g. Certificate)')" class="cp-input" />
          <label class="cp-upload-choose">
            <icons name="upload" class="w-4 h-4" />
            {{ pendingFiles.length ? pendingFiles.length + ' ' + $t('file(s) selected') : $t('Choose files') }}
            <input type="file" multiple @change="onFilesChosen" class="hidden" />
          </label>
          <button type="submit" class="lg-btn lg-btn-primary" :disabled="!pendingFiles.length"><icons name="upload" class="w-4 h-4" /> {{ $t('Upload') }}</button>
        </form>
        <section class="lg-card cp-doc-list">
          <div v-for="doc in record.attachments" :key="doc.id" class="cp-doc-row">
            <div class="cp-doc-icon">
              <img v-if="doc.is_image" :src="doc.preview_url" alt="" @mouseenter="showAttachmentZoom($event, doc)" @mouseleave="hidePhotoZoom" />
              <icons v-else name="doc" class="w-5 h-5" />
            </div>
            <div class="min-w-0 flex-1">
              <div class="cp-doc-title">{{ doc.title }}</div>
              <div class="cp-doc-meta">{{ doc.group }}<span v-if="doc.filesize"> &middot; {{ formatBytes(doc.filesize) }}</span></div>
            </div>
            <div class="cp-doc-actions">
              <a :href="doc.preview_url" target="_blank" class="lg-icon-btn lg-tip" :data-tip="$t('Preview')" :aria-label="$t('Preview')"><icons name="eye-flat" class="w-4 h-4" /></a>
              <a :href="doc.download_url" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" class="w-4 h-4" /></a>
              <button v-if="$can('update-compliance-records')" type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Delete')" :aria-label="$t('Delete')" @click="deleteAttachment(doc)"><icons name="trash" class="w-4 h-4" /></button>
            </div>
          </div>
          <div v-if="!record.attachments.length" class="cp-empty">{{ $t('No documents uploaded yet.') }}</div>
        </section>
      </div>

      <!-- Reminders -->
      <section v-if="activeTab === 'reminders'" class="lg-card cp-flush">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Sent') }}</th>
                <th>{{ $t('Interval') }}</th>
                <th>{{ $t('Channel') }}</th>
                <th>{{ $t('Recipient') }}</th>
                <th>{{ $t('Status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="log in record.reminder_log" :key="log.id">
                <td :data-label="$t('Sent')">{{ $datetime(log.sent_at) }}</td>
                <td :data-label="$t('Interval')">{{ log.interval_days }} {{ $t('days') }}</td>
                <td :data-label="$t('Channel')"><span class="lg-chip lg-chip-slate">{{ log.channel }}</span></td>
                <td :data-label="$t('Recipient')">{{ (log.recipient && log.recipient.name) || '-' }}</td>
                <td :data-label="$t('Status')">{{ log.status }}</td>
              </tr>
              <tr v-if="!record.reminder_log.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('No reminders sent yet.') }}</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- History -->
      <section v-if="activeTab === 'history'" class="lg-card">
        <div v-if="record.history.length" class="cp-timeline">
          <div v-for="entry in record.history" :key="entry.id" class="cp-tl-item">
            <span class="cp-tl-dot"></span>
            <div class="cp-tl-head">
              <span class="cp-tl-title">{{ entry.event_type }}</span>
              <span class="cp-tl-time">{{ $datetime(entry.created_at) }}</span>
            </div>
            <div class="cp-tl-desc">{{ entry.description }}</div>
            <div class="cp-tl-by" v-if="entry.user">{{ $t('By') }} {{ entry.user.name }}</div>
          </div>
        </div>
        <div v-else class="cp-empty">{{ $t('No history recorded yet.') }}</div>
      </section>
    </div>

    <!-- Submit renewal modal -->
    <modal :show="modal === 'submit'" @close="modal = null">
      <div class="cp-modal">
        <header class="cp-modal-head">
          <span class="cp-modal-ico lg-ico-indigo"><icons name="send" class="w-5 h-5" /></span>
          <div class="cp-modal-title">{{ $t('Submit Renewal') }}</div>
        </header>
        <div class="grid grid-cols-1 gap-3">
          <text-input :label="$t('Application Number')" v-model="submitForm.application_number" />
          <text-input type="number" :label="$t('Renewal Fee')" v-model="submitForm.renewal_fee" />
          <text-input type="date" :label="$t('Expected Completion Date')" v-model="submitForm.expected_completion_date" />
        </div>
        <div class="cp-modal-foot">
          <button type="button" class="lg-btn lg-btn-soft" @click="modal = null">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" @click="submitRenewal">{{ $t('Submit') }}</button>
        </div>
      </div>
    </modal>

    <!-- Complete renewal modal -->
    <modal :show="modal === 'complete'" @close="modal = null">
      <div class="cp-modal">
        <header class="cp-modal-head">
          <span class="cp-modal-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span>
          <div class="cp-modal-title">{{ $t('Complete Renewal') }}</div>
        </header>
        <div class="grid grid-cols-1 gap-3">
          <text-input type="date" :label="$t('New Issue Date')" v-model="completeForm.new_issue_date" />
          <text-input type="date" :label="$t('New Expiry Date')" v-model="completeForm.new_expiry_date" />
        </div>
        <div class="cp-modal-foot">
          <button type="button" class="lg-btn lg-btn-soft" @click="modal = null">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" @click="completeRenewal">{{ $t('Complete') }}</button>
        </div>
      </div>
    </modal>

    <teleport to="body">
      <div v-if="photoZoom.show" class="cp-photo-zoom cp-photo-zoom-show" :style="{ left: photoZoom.x + 'px', top: photoZoom.y + 'px' }">
        <img :src="photoZoom.src" alt="" />
        <div v-if="photoZoom.name" class="cp-photo-zoom-name">{{ photoZoom.name }}</div>
      </div>
    </teleport>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TextInput from '@/Shared/TextInput.vue';
import Modal from '@/Jetstream/Modal.vue';

export default {
  components: { AdminLayout, Link, TextInput, Modal },

  props: { record: Object },

  data() {
    const today = this.$formatJSDate(new Date());
    return {
      activeTab: 'overview',
      modal: null,
      documentType: 'Supporting Documents',
      pendingFiles: [],
      photoZoom: { show: false, src: null, name: '', x: 0, y: 0 },
      submitForm: { application_number: '', renewal_fee: '', expected_completion_date: '' },
      completeForm: { new_issue_date: today, new_expiry_date: '' },
    };
  },

  computed: {
    tabs() {
      return [
        { key: 'overview', label: 'Overview' },
        { key: 'renewal', label: 'Renewal' },
        { key: 'documents', label: 'Documents' },
        { key: 'reminders', label: 'Reminders' },
        { key: 'history', label: 'History' },
      ];
    },
    activeRenewal() {
      return (this.record.renewal_history || []).find(rh => ['Renewal in Progress', 'Submitted', 'Pending Approval'].includes(rh.status));
    },
    hasActiveRenewal() {
      return !!this.activeRenewal;
    },
    isTerminal() {
      return ['Cancelled', 'Rejected', 'Completed'].includes(this.record.status);
    },
  },

  methods: {
    tabIcon(key) {
      return { overview: 'list', renewal: 'refresh', documents: 'doc', reminders: 'bell', history: 'clock' }[key] || 'list';
    },
    openModal(name) {
      this.modal = name;
    },
    statusBadgeClass(status) {
      const map = {
        Active: 'lg-chip-green', 'Due Soon': 'lg-chip-amber',
        'Renewal in Progress': 'lg-chip-blue', Submitted: 'lg-chip-blue', 'Pending Approval': 'lg-chip-blue',
        Renewed: 'lg-chip-green', Completed: 'lg-chip-green', Expired: 'lg-chip-red', Rejected: 'lg-chip-red',
      };
      return map[status] || 'lg-chip-slate';
    },
    daysBadgeClass(days) {
      if (days === null || days === undefined) return 'lg-chip-slate';
      if (days <= 7) return 'lg-chip-red';
      if (days <= 30) return 'lg-chip-amber';
      return 'lg-chip-green';
    },
    startRenewal() {
      this.$inertia.post(this.route('compliance-records.renewal.start', this.record.id));
    },
    submitRenewal() {
      this.$inertia.patch(this.route('compliance-records.renewal.submit', this.record.id), this.submitForm, { onSuccess: () => (this.modal = null) });
    },
    approveRenewal() {
      this.$inertia.patch(this.route('compliance-records.renewal.approve', this.record.id));
    },
    completeRenewal() {
      this.$inertia.post(this.route('compliance-records.renewal.complete', this.record.id), this.completeForm, { onSuccess: () => (this.modal = null) });
    },
    async rejectRenewal() {
      if (await this.$confirm(this.$t('Reject this renewal?'), { variant: 'danger' })) {
        this.$inertia.patch(this.route('compliance-records.renewal.reject', this.record.id));
      }
    },
    async cancelRenewal() {
      if (await this.$confirm(this.$t('Cancel this renewal?'), { variant: 'danger' })) {
        this.$inertia.patch(this.route('compliance-records.renewal.cancel', this.record.id));
      }
    },
    onFilesChosen(e) {
      this.pendingFiles = Array.from(e.target.files);
    },
    uploadDocuments() {
      const form = this.$inertia.form({ attachments: this.pendingFiles, document_type: this.documentType });
      form.post(this.route('compliance-records.documents.store', this.record.id), {
        forceFormData: true,
        onSuccess: () => (this.pendingFiles = []),
      });
    },
    async deleteAttachment(doc) {
      if (!(await this.$confirm(this.$t('Delete this document? This cannot be undone.'), { variant: 'danger' }))) {
        return;
      }
      this.$inertia.delete(doc.delete_url, { preserveScroll: true });
    },
    showPhotoZoom(event, src, name) {
      const rect = event.currentTarget.getBoundingClientRect();
      this.photoZoom = { show: true, src, name: name || '', x: rect.right + 14, y: rect.top + rect.height / 2 };
    },
    showAttachmentZoom(event, doc) {
      const rect = event.currentTarget.getBoundingClientRect();
      this.photoZoom = { show: true, src: doc.preview_url, name: doc.title, x: rect.right + 14, y: rect.top + rect.height / 2 };
    },
    hidePhotoZoom() {
      this.photoZoom.show = false;
    },
    formatBytes(bytes) {
      if (!bytes) return '0 B';
      const units = ['B', 'KB', 'MB', 'GB'];
      const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
      return `${(bytes / Math.pow(1024, exponent)).toFixed(1)} ${units[exponent]}`;
    },
  },
};
</script>

<style scoped>
.cp-hero-photo { width: 52px; height: 52px; flex: none; border-radius: 16px; object-fit: cover; border: 2px solid #fff; box-shadow: 0 10px 20px -8px rgba(15, 37, 64, 0.5); cursor: zoom-in; }
.cp-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }

.cp-tabs { display: flex; gap: 6px; margin-bottom: 1rem; padding: 6px; overflow-x: auto; border-radius: 9999px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); }
.cp-tab { display: inline-flex; align-items: center; gap: 7px; padding: 8px 16px; border-radius: 9999px; font-size: 0.8rem; font-weight: 800; color: var(--lg-mute); white-space: nowrap; transition: background 0.15s ease, color 0.15s ease; }
.cp-tab:hover { color: var(--lg-navy); background: var(--lg-soft); }
.cp-tab.is-active { color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.9); }

.cp-grid { display: grid; gap: 1rem; grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: start; }
@media (max-width: 900px) { .cp-grid { grid-template-columns: minmax(0, 1fr); } }
.cp-dl > div { display: flex; justify-content: space-between; gap: 16px; padding: 9px 0; border-bottom: 1px solid var(--lg-line); font-size: 0.85rem; }
.cp-dl > div:last-child { border-bottom: 0; padding-bottom: 0; }
.cp-dl dt { flex: none; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); padding-top: 3px; }
.cp-dl dd { min-width: 0; text-align: right; font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }
.cp-num { font-variant-numeric: tabular-nums; }
.cp-dim { color: var(--lg-mute); }
.cp-flush { padding: 0.85rem; }
@media (max-width: 640px) { .cp-flush { padding: 0.5rem; } .cp-dl > div { flex-direction: column; gap: 2px; } .cp-dl dd { text-align: left; } }

.cp-upload-bar { display: flex; align-items: center; flex-wrap: wrap; gap: 0.6rem; padding: 0.9rem 1.1rem; }
.cp-input { flex: 1 1 220px; min-width: 0; padding: 0.55rem 0.8rem; border-radius: 12px; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-ink); font-size: 0.84rem; }
.cp-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
.cp-upload-choose { display: inline-flex; align-items: center; gap: 7px; padding: 8px 15px; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; color: var(--lg-navy); background: var(--lg-soft); border: 1px dashed #93a9c6; cursor: pointer; transition: border-color 0.15s ease; }
.cp-upload-choose:hover { border-color: #3b82f6; }

.cp-doc-list { padding: 0.5rem; }
.cp-doc-row { display: flex; align-items: center; gap: 0.75rem; padding: 0.6rem 0.75rem; border-radius: 14px; transition: background 0.15s ease; }
.cp-doc-row:hover { background: var(--lg-soft); }
.cp-doc-row + .cp-doc-row { border-top: 1px solid var(--lg-line); }
.cp-doc-icon { width: 2.5rem; height: 2.5rem; border-radius: 12px; overflow: hidden; display: flex; align-items: center; justify-content: center; background: var(--lg-soft); border: 1px solid var(--lg-line); color: var(--lg-faint); flex-shrink: 0; }
.cp-doc-icon img { width: 100%; height: 100%; object-fit: cover; cursor: zoom-in; }
.cp-doc-title { font-size: 0.86rem; font-weight: 800; color: var(--lg-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cp-doc-meta { font-size: 0.72rem; color: var(--lg-faint); }
.cp-doc-actions { display: flex; align-items: center; gap: 6px; flex: none; }
.cp-empty { padding: 28px; text-align: center; font-size: 0.85rem; color: var(--lg-faint); }

.cp-timeline { position: relative; margin-left: 6px; padding-left: 1.4rem; border-left: 2px solid var(--lg-line); }
.cp-tl-item { position: relative; padding-bottom: 1.1rem; }
.cp-tl-item:last-child { padding-bottom: 0; }
.cp-tl-dot { position: absolute; left: calc(-1.4rem - 6px); top: 0.3rem; width: 10px; height: 10px; border-radius: 9999px; background: linear-gradient(145deg, #60a5fa, #1d4ed8); box-shadow: 0 0 0 3px var(--lg-card); }
.cp-tl-head { display: flex; align-items: baseline; justify-content: space-between; flex-wrap: wrap; gap: 4px 12px; }
.cp-tl-title { font-size: 0.86rem; font-weight: 800; color: var(--lg-ink); }
.cp-tl-time { font-size: 0.7rem; color: var(--lg-faint); }
.cp-tl-desc { margin-top: 2px; font-size: 0.8rem; color: var(--lg-mute); overflow-wrap: anywhere; }
.cp-tl-by { margin-top: 2px; font-size: 0.7rem; color: var(--lg-faint); }

.cp-modal { padding: 1.4rem; }
.cp-modal-head { display: flex; align-items: center; gap: 12px; margin-bottom: 1rem; }
.cp-modal-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 40px; height: 40px; border-radius: 13px; color: #fff; }
.cp-modal-title { font-size: 1.02rem; font-weight: 800; color: #0f172a; }
html.dark .cp-modal-title { color: #f1f5f9; }
.cp-modal-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 1.1rem; }
.cp-modal .lg-btn-soft { --lg-card: #fff; --lg-navy: #1c3f66; }
html.dark .cp-modal .lg-btn-soft { --lg-card: rgba(255, 255, 255, 0.06); --lg-navy: #cbd5e1; }

.cp-photo-zoom { position: fixed; transform: translate(0, -50%) scale(0.85); z-index: 9999; background: #fff; border-radius: 1rem; padding: 0.6rem; box-shadow: 0 20px 40px -10px rgba(15, 23, 42, 0.35), 0 0 0 1px rgba(15, 23, 42, 0.05); opacity: 0; pointer-events: none; transition: opacity 0.16s ease, transform 0.16s ease; max-width: 220px; }
.cp-photo-zoom-show { opacity: 1; transform: translate(0, -50%) scale(1); }
.cp-photo-zoom img { display: block; width: 200px; max-height: 260px; object-fit: contain; border-radius: 0.7rem; background: #f1f5f9; }
.cp-photo-zoom-name { text-align: center; font-size: 0.75rem; font-weight: 700; color: #374151; margin-top: 0.5rem; word-break: break-word; }
html.dark .cp-photo-zoom { background: #1e293b; box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.08); }
html.dark .cp-photo-zoom-name { color: #e2e8f0; }
</style>

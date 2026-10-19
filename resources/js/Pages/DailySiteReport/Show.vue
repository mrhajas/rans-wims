<template>
  <admin-layout :title="$t('Site Report Details')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="doc" :title="dailySiteReport.report_number" :subtitle="$t('Please review the report details below')">
        <Link :href="route('daily-site-reports.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Site Reports') }}</Link>
        <a :href="route('daily-site-reports.print', dailySiteReport.id)" target="_blank" v-if="dailySiteReport.can_print" class="lg-btn lg-btn-soft">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </a>
        <a :href="route('daily-site-reports.pdf', dailySiteReport.id)" v-if="dailySiteReport.can_print" class="lg-btn lg-btn-soft">
          <icons name="doc-text" class="w-4 h-4" />{{ $t('Download PDF') }}
        </a>
        <Link v-if="dailySiteReport.can_edit" :href="route('daily-site-reports.edit', dailySiteReport.id)" class="lg-btn lg-btn-primary">
          <icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}
        </Link>
        <button v-if="dailySiteReport.can_archive && dailySiteReport.status !== 'Archived'" type="button" @click="archive" class="lg-btn lg-btn-dark">
          <icons name="folder" class="w-4 h-4" />{{ $t('Archive') }}
        </button>
        <button v-if="dailySiteReport.can_delete" type="button" @click="destroy" class="lg-btn lg-btn-red">
          <icons name="trash" class="w-4 h-4" />{{ $t('Delete') }}
        </button>
      </page-header>

      <div v-if="isArchived" class="lg-note lg-note-amber mb-4">
        <icons name="lock-closed" class="w-4 h-4" />
        <div>
          <p class="font-semibold">{{ $t('Archived') }}</p>
          <p>
            <span v-if="dailySiteReport.archived_by_user">{{ $t('By') }}: {{ dailySiteReport.archived_by_user.name }}</span>
            <span v-else-if="dailySiteReport.auto_archived">{{ $t('System') }}</span>
            <span v-if="dailySiteReport.archived_at"> &middot; {{ $datetime(dailySiteReport.archived_at) }}</span>
          </p>
        </div>
      </div>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="doc" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Report Details') }}</h2>
            <p class="lg-card-sub">{{ dailySiteReport.report_type }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <span class="lg-chip" :class="statusChip" style="text-transform: none">{{ $t(dailySiteReport.status) }}</span>
            <span class="lg-chip" :class="dailySiteReport.schedule_status === 'Overdue' ? 'lg-chip-red' : 'lg-chip-green'" style="text-transform: none">{{ $t(dailySiteReport.schedule_status) }}</span>
          </div>
        </header>

        <dl class="dsr-facts">
          <div><dt>{{ $t('Report Number') }}</dt><dd class="lg-mono">{{ dailySiteReport.report_number }}</dd></div>
          <div><dt>{{ $t('Report Date') }}</dt><dd>{{ $date(dailySiteReport.report_date) }}</dd></div>
          <div><dt>{{ $t('Submitted At') }}</dt><dd>{{ dailySiteReport.submitted_at || '-' }}</dd></div>
          <div><dt>{{ $t('Project / Warehouse') }}</dt><dd>{{ dailySiteReport.warehouse?.name || '-' }}</dd></div>
          <div><dt>{{ $t('Site Engineer') }}</dt><dd>{{ dailySiteReport.submitted_by_user?.name || '-' }}</dd></div>
          <div><dt>{{ $t('Report Type') }}</dt><dd>{{ dailySiteReport.report_type }}</dd></div>
          <div>
            <dt>{{ $t('Priority') }}</dt>
            <dd><span class="lg-chip" :class="priorityChip" style="text-transform: none">{{ dailySiteReport.priority }}</span></dd>
          </div>
          <div v-if="dailySiteReport.viewed_by_user"><dt>{{ $t('Last Viewed By') }}</dt><dd>{{ dailySiteReport.viewed_by_user.name }}</dd></div>
        </dl>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Remarks') }}</h2>
          </div>
        </header>
        <div class="dsr-remarks">{{ dailySiteReport.remarks || '-' }}</div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="clip" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
          </div>
          <span class="lg-chip lg-chip-amber" style="text-transform: none">{{ dailySiteReport.attachments?.length || 0 }}</span>
        </header>

        <div v-if="isArchived && dailySiteReport.attachments && dailySiteReport.attachments.length" class="lg-note lg-note-amber mb-3">
          <icons name="exclamation" class="w-4 h-4" />
          <span>{{ $t('Attachments have been archived. The files below have been permanently removed from storage.') }}</span>
        </div>
        <ul v-if="dailySiteReport.attachments && dailySiteReport.attachments.length" class="dsr-files">
          <li v-for="attachment in dailySiteReport.attachments" :key="attachment.id">
            <span class="flex items-center gap-3 min-w-0" :class="{ 'dsr-muted': isArchived }">
              <span class="dsr-file-ico"><icons :name="attachmentIcon(attachment)" class="w-4 h-4" :class="[isArchived ? 'text-gray-400' : attachmentIconClass(attachment)]" /></span>
              <span class="min-w-0">
                <span class="lg-main block truncate">{{ attachment.title }}</span>
                <span class="lg-subtext">{{ formatBytes(attachment.filesize) }}</span>
              </span>
            </span>
            <span v-if="!isArchived" class="flex items-center gap-2 flex-none">
              <button type="button" class="lg-btn lg-btn-soft" @click="preview(attachment)">
                <icons name="eye-flat" class="w-4 h-4" />{{ $t('Preview') }}
              </button>
              <a class="lg-btn lg-btn-soft" :href="downloadUrl(attachment)">
                <icons name="download" class="w-4 h-4" />{{ $t('Download') }}
              </a>
            </span>
          </li>
        </ul>
        <div v-else class="lg-subtext">{{ $t('No attachments uploaded.') }}</div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Audit Trail') }}</h2>
          </div>
        </header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Action') }}</th>
                <th>{{ $t('By') }}</th>
                <th>{{ $t('Date & Time') }}</th>
                <th>{{ $t('Remarks') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="log in dailySiteReport.logs" :key="log.id">
                <td :data-label="$t('Action')"><span class="lg-chip lg-chip-slate" style="text-transform: none">{{ actionLabel(log.action) }}</span></td>
                <td :data-label="$t('By')">{{ log.user?.name || $t('System') }}</td>
                <td :data-label="$t('Date & Time')">{{ $datetime(log.created_at) }}</td>
                <td :data-label="$t('Remarks')" class="dsr-log-remarks">{{ log.remarks }}</td>
              </tr>
              <tr v-if="!dailySiteReport.logs || dailySiteReport.logs.length === 0" class="lg-empty-row">
                <td class="lg-empty" colspan="4">{{ $t('No logs available.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <modal :show="previewing" max-width="4xl" :closeable="true" @close="closePreview">
      <div class="p-4">
        <div class="flex items-center justify-between gap-3 mb-3">
          <div class="flex items-center gap-3 min-w-0">
            <span class="dsr-modal-ico"><icons name="eye-flat" class="w-5 h-5" /></span>
            <div class="text-lg font-semibold truncate">{{ previewAttachment?.title }}</div>
          </div>
          <button @click="closePreview" class="flex flex-none items-center justify-center h-8 w-8 rounded-full text-gray-600 hover:text-gray-800 hover:bg-gray-200">
            <icons name="cross" class="h-5 w-5" />
          </button>
        </div>

        <div v-if="previewLoading" class="py-16 text-center text-gray-500">{{ $t('Loading preview...') }}</div>

        <template v-else-if="previewAttachment">
          <img v-if="isImage(previewAttachment)" :src="downloadUrl(previewAttachment, true)" class="w-full h-auto rounded-md" />
          <iframe v-else-if="isPdf(previewAttachment)" :src="downloadUrl(previewAttachment, true)" class="w-full" style="height: 75vh"></iframe>
          <div
            v-else-if="previewHtml"
            class="preview-document overflow-auto rounded-md border border-gray-200 bg-white p-4"
            style="max-height: 75vh"
            v-html="previewHtml"
          ></div>
          <div v-else-if="previewError" class="py-16 text-center text-gray-500">
            {{ previewError }}
            <div class="mt-3">
              <a class="lg-btn lg-btn-soft" :href="downloadUrl(previewAttachment)"><icons name="download" class="w-4 h-4" />{{ $t('Download instead') }}</a>
            </div>
          </div>
        </template>
      </div>
    </modal>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Modal from '@/Jetstream/Modal.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: {
    AdminLayout,
    Modal,
    PageHeader,
  },

  props: {
    dailySiteReport: Object,
  },

  data() {
    return {
      previewing: false,
      previewAttachment: null,
      previewLoading: false,
      previewHtml: null,
      previewError: null,
    };
  },

  computed: {
    isArchived() {
      return this.dailySiteReport.status === 'Archived';
    },
    statusChip() {
      return (
        {
          Submitted: 'lg-chip-blue',
          Viewed: 'lg-chip-amber',
          Downloaded: 'lg-chip-green',
          Archived: 'lg-chip-slate',
        }[this.dailySiteReport.status] || 'lg-chip-slate'
      );
    },
    priorityChip() {
      return (
        {
          Low: 'lg-chip-slate',
          Normal: 'lg-chip-blue',
          High: 'lg-chip-amber',
          Urgent: 'lg-chip-red',
        }[this.dailySiteReport.priority] || 'lg-chip-slate'
      );
    },
  },

  methods: {
    isImage(attachment) {
      return (attachment.filetype || '').startsWith('image/');
    },
    isPdf(attachment) {
      return (attachment.filetype || '') === 'application/pdf';
    },
    isSpreadsheet(attachment) {
      return ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'].includes(attachment.filetype);
    },
    isWordDoc(attachment) {
      return attachment.filetype === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    },
    isLegacyDoc(attachment) {
      return attachment.filetype === 'application/msword';
    },
    attachmentIcon(attachment) {
      if (this.isImage(attachment)) {
        return 'image';
      }
      if (this.isPdf(attachment)) {
        return 'doc';
      }
      if (this.isWordDoc(attachment) || this.isLegacyDoc(attachment)) {
        return 'doc-text';
      }
      if (this.isSpreadsheet(attachment)) {
        return 'table';
      }
      return 'clip';
    },
    attachmentIconClass(attachment) {
      if (this.isImage(attachment)) {
        return 'text-purple-500';
      }
      if (this.isPdf(attachment)) {
        return 'text-red-500';
      }
      if (this.isWordDoc(attachment) || this.isLegacyDoc(attachment)) {
        return 'text-blue-600';
      }
      if (this.isSpreadsheet(attachment)) {
        return 'text-green-600';
      }
      return 'text-gray-400';
    },
    downloadUrl(attachment, preview = false) {
      const url = route('daily-site-reports.attachments.download', [this.dailySiteReport.id, attachment.id]);
      return preview ? url + '?preview=1' : url;
    },
    async preview(attachment) {
      this.previewAttachment = attachment;
      this.previewing = true;
      this.previewHtml = null;
      this.previewError = null;
      this.previewLoading = false;

      if (this.isImage(attachment) || this.isPdf(attachment)) {
        return;
      }

      if (this.isLegacyDoc(attachment)) {
        this.previewError = this.$t('Preview is not available for the old .doc format. Please download to view it.');
        return;
      }

      if (!this.isSpreadsheet(attachment) && !this.isWordDoc(attachment)) {
        this.previewError = this.$t('Preview is not available for this file type. Please download to view it.');
        return;
      }

      this.previewLoading = true;

      try {
        const response = await axios.get(this.downloadUrl(attachment, true), { responseType: 'arraybuffer' });

        if (this.isSpreadsheet(attachment)) {
          const XLSX = await import('xlsx');
          const workbook = XLSX.read(response.data, { type: 'array' });
          const firstSheetName = workbook.SheetNames[0];
          this.previewHtml = XLSX.utils.sheet_to_html(workbook.Sheets[firstSheetName]);
        } else {
          const { default: mammoth } = await import('mammoth');
          const result = await mammoth.convertToHtml({ arrayBuffer: response.data });
          this.previewHtml = result.value;
        }
      } catch (e) {
        this.previewError = this.$t('Failed to load preview.');
      } finally {
        this.previewLoading = false;
      }
    },
    closePreview() {
      this.previewing = false;
      this.previewAttachment = null;
      this.previewHtml = null;
      this.previewError = null;
    },
    formatBytes(bytes) {
      if (!bytes) {
        return '0 B';
      }

      const units = ['B', 'KB', 'MB', 'GB'];
      const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);

      return `${(bytes / Math.pow(1024, exponent)).toFixed(1)} ${units[exponent]}`;
    },
    actionLabel(action) {
      return (action || '')
        .split('_')
        .map(w => w.charAt(0).toUpperCase() + w.slice(1))
        .join(' ');
    },
    async archive() {
      if (!(await this.$confirm(this.$t('Archiving will permanently delete the uploaded attachments. Continue?'), { variant: 'danger' }))) {
        return;
      }

      this.$inertia.patch(route('daily-site-reports.archive', this.dailySiteReport.id));
    },
    async destroy() {
      if (!(await this.$confirm(this.$t('Are you sure you want to delete this report?'), { variant: 'danger' }))) {
        return;
      }

      this.$inertia.delete(route('daily-site-reports.destroy', this.dailySiteReport.id));
    },
  },
};
</script>

<style scoped>
.dsr-facts { display: grid; gap: 1rem 1.5rem; grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr)); }
.dsr-facts > div { min-width: 0; padding: 0.7rem 0.9rem; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.dsr-facts dt { font-size: 0.64rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.dsr-facts dd { margin-top: 3px; font-size: 0.9rem; font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }
.dsr-remarks { white-space: pre-line; font-size: 0.88rem; line-height: 1.6; color: var(--lg-ink); overflow-wrap: anywhere; }

.dsr-files { border: 1px solid var(--lg-line); border-radius: 16px; }
.dsr-files li { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 10px 14px; border-bottom: 1px solid var(--lg-line); }
.dsr-files li:last-child { border-bottom: 0; }
.dsr-muted { opacity: 0.6; }
.dsr-file-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 36px; height: 36px; border-radius: 12px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.dsr-log-remarks { white-space: normal !important; min-width: 14rem; }
.dsr-modal-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 38px; height: 38px; border-radius: 13px; color: #fff; background: linear-gradient(145deg, #3b82f6, #1c3f66); }

.preview-document :deep(table) {
  border-collapse: collapse;
  font-size: 0.8rem;
}

.preview-document :deep(td),
.preview-document :deep(th) {
  border: 1px solid #e5e7eb;
  padding: 4px 8px;
  white-space: nowrap;
}

.preview-document :deep(p) {
  margin: 0.5em 0;
}
</style>

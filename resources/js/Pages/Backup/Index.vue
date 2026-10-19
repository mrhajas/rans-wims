<template>
  <admin-layout :title="$t('Backup')">
    <div class="lg-page px-4 md:px-0">
      <header class="lg-hero">
        <span class="lg-hero-icon"><icons name="folder" class="w-6 h-6" /></span>
        <div class="lg-hero-body">
          <h1 class="lg-title">{{ $t('Database Backup & Restore') }}</h1>
          <p class="lg-sub">{{ $t('Download a full SQL backup or restore the database from a previous backup.') }}</p>
        </div>
      </header>

      <section class="lg-stats">
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-navy"><icons name="collection" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value" style="font-size: 1.05rem">{{ database }}</div><div class="lg-stat-label">{{ $t('Database') }}</div></div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-emerald"><icons name="download" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value" style="font-size: 1.05rem">{{ lastBackup ? formatDate(lastBackup.created_at) : $t('Never') }}</div>
            <div class="lg-stat-label">{{ $t('Last backup') }}</div>
          </div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-indigo"><icons name="list" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value">{{ backupCount }}</div><div class="lg-stat-label">{{ $t('Recent backups') }}</div></div>
        </div>
        <div class="lg-stat">
          <span class="lg-stat-ico" :class="lastRestore ? 'lg-ico-amber' : 'lg-ico-slate'"><icons name="refresh" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value" style="font-size: 1.05rem">{{ lastRestore ? formatDate(lastRestore.created_at) : $t('Never') }}</div>
            <div class="lg-stat-label">{{ $t('Last restore') }}</div>
          </div>
        </div>
      </section>

      <div v-if="actionError" class="lg-note lg-note-red mb-4"><icons name="exclamation" class="w-4 h-4" />{{ actionError }}</div>
      <div v-if="$page.props.flash && $page.props.flash.restoreErrors && $page.props.flash.restoreErrors.length" class="lg-note lg-note-red mb-4">
        <icons name="exclamation" class="w-4 h-4" />
        <div>
          <div class="font-semibold mb-1">{{ $t('First errors') }}:</div>
          <ul class="list-disc pl-5">
            <li v-for="(err, i) in $page.props.flash.restoreErrors" :key="i">{{ err }}</li>
          </ul>
        </div>
      </div>

      <div class="bk-grid">
        <!-- Backup -->
        <section class="lg-card" style="margin-bottom: 0">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="download" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Download Backup') }}</h2>
              <p class="lg-card-sub">{{ $t('Generates a complete SQL dump of the current database, including schema and data.') }}</p>
            </div>
          </header>
          <button type="button" class="lg-btn lg-btn-dark" :disabled="downloadProcessing" @click="openDownloadConfirm">
            <icons name="download" class="w-4 h-4" />{{ downloadProcessing ? $t('Preparing') + '…' : $t('Download Backup') }}
          </button>

          <div class="bk-divider"></div>

          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="folder" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Create System Backup') }}</h2>
              <p class="lg-card-sub">{{ $t('Creates a server-saved database backup labeled as System BackUp in the activity log.') }}</p>
            </div>
          </header>
          <p class="lg-note lg-note-amber mb-4"><icons name="info" class="w-4 h-4" />{{ $t('Only one System BackUp is kept at a time. Creating a new one automatically replaces the previous System BackUp file.') }}</p>
          <button type="button" class="lg-btn lg-btn-green" :disabled="systemBackupProcessing" @click="createSystemBackup">
            <icons name="folder" class="w-4 h-4" />{{ systemBackupProcessing ? $t('Creating') + '…' : $t('Create System Backup') }}
          </button>
        </section>

        <!-- Restore -->
        <section class="lg-card bk-danger" style="margin-bottom: 0">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-rose"><icons name="refresh" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title" style="color: #be123c">{{ $t('Restore Database') }}</h2>
              <p class="lg-card-sub">{{ $t('Upload a previously downloaded SQL backup file. This will overwrite the current database.') }}</p>
            </div>
          </header>
          <p class="lg-note lg-note-red mb-4"><icons name="exclamation" class="w-4 h-4" />{{ $t('This action cannot be undone. Take a fresh backup before restoring.') }}</p>

          <form @submit.prevent="openConfirm">
            <input ref="fileInput" id="bk-file" type="file" accept=".sql,.txt" class="sr-only" @change="onFileChange" />
            <label for="bk-file" class="bk-drop" :class="{ 'has-file': form.file }">
              <span class="bk-drop-ico"><icons :name="form.file ? 'tick' : 'upload'" class="w-5 h-5" /></span>
              <span class="min-w-0">
                <span class="bk-drop-title">{{ form.file ? form.file.name : $t('Choose a .sql backup file') }}</span>
                <span class="bk-drop-sub">{{ form.file ? formatBytes(form.file.size) : $t('Click to browse your computer') }}</span>
              </span>
            </label>
            <div v-if="form.errors.file" class="bk-error">{{ form.errors.file }}</div>

            <label class="bk-label" for="bk-confirm">{{ $t('Type RESTORE to enable the button') }}</label>
            <input id="bk-confirm" v-model="form.confirm" type="text" placeholder="RESTORE" class="bk-input" :class="{ 'is-error': form.errors.confirm }" autocomplete="off" />
            <div v-if="form.errors.confirm" class="bk-error wims-field-error">{{ form.errors.confirm }}</div>

            <button type="submit" class="lg-btn lg-btn-red mt-4" :disabled="!canSubmit">
              <icons name="refresh" class="w-4 h-4" />{{ form.processing ? $t('Restoring') + '…' : $t('Restore Database') }}
            </button>
          </form>
        </section>
      </div>

      <!-- Activity -->
      <section class="lg-card mt-4">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="collection" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Backup Activity Log') }}</h2>
            <p class="lg-card-sub">{{ $t('Recent backup and restore actions for the database.') }}</p>
          </div>
        </header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('When') }}</th>
                <th>{{ $t('Activity') }}</th>
                <th>{{ $t('User') }}</th>
                <th>{{ $t('Filename') }}</th>
                <th class="num">{{ $t('File Size') }}</th>
                <th class="num">{{ $t('Errors') }}</th>
                <th class="num lg-sticky-end">{{ $t('Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="entry in activities" :key="entry.id">
                <td :data-label="$t('When')" class="lg-main">{{ formatDate(entry.created_at) }}</td>
                <td :data-label="$t('Activity')"><span class="lg-chip" :class="activityChip(entry.activity)">{{ entry.activity }}</span></td>
                <td :data-label="$t('User')">{{ entry.user ? entry.user.name : entry.activity === 'Auto Back Up' ? 'RANS WIMS System' : $t('System') }}</td>
                <td :data-label="$t('Filename')"><span class="lg-mono lg-clip lg-tip tip-left" style="display: inline-block; max-width: 220px; vertical-align: bottom" :data-tip="entry.filename || ''">{{ entry.filename || $t('N/A') }}</span></td>
                <td :data-label="$t('File Size')" class="num">{{ formatBytes(entry.file_size) }}</td>
                <td :data-label="$t('Errors')" class="num">
                  <span v-if="entry.errors_count" class="lg-chip lg-chip-red">{{ entry.errors_count }}</span>
                  <span v-else class="lg-subtext">{{ entry.errors_count === 0 ? '0' : '-' }}</span>
                </td>
                <td :data-label="$t('Actions')" class="num lg-sticky-end">
                  <div v-if="hasBackupActions(entry)" class="lg-actions">
                    <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download auto backup')" @click="openAutoAction(entry, 'download')"><icons name="download" class="w-4 h-4" /></button>
                    <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Restore')" :aria-label="$t('Restore auto backup')" @click="openAutoAction(entry, 'restore')"><icons name="refresh" class="w-4 h-4" /></button>
                  </div>
                  <span v-else class="lg-subtext">-</span>
                </td>
              </tr>
              <tr v-if="activities.length === 0" class="lg-empty-row">
                <td colspan="7" class="lg-empty">{{ $t('No backup activity yet.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <!-- restore an uploaded backup -->
    <tec-dialog-modal :show="confirm" @close="closeConfirm" :max-width="'md'">
      <template #title><span class="bk-title is-red"><icons name="refresh" class="w-5 h-5" />{{ $t('Restore Database') }}</span></template>
      <template #content>
        <div class="bk-body">
          <div class="bk-hero is-red"><span class="bk-hero-ico"><icons name="exclamation" class="w-7 h-7" /></span>
            <div><b>{{ $t('All current data will be replaced') }}</b><span>{{ $t('This cannot be undone. Take a fresh backup first if you are not sure.') }}</span></div></div>
          <div class="bk-file"><icons name="doc" class="w-5 h-5" /><div><small>{{ $t('Restore from') }}</small><b>{{ form.file ? form.file.name : '' }}</b></div></div>
          <label class="bk-label" for="bk-restore-pass">{{ $t('Enter your password to continue') }}</label>
          <div class="bk-field" :class="{ 'is-bad': form.errors.password }"><icons name="lock-closed" class="w-4 h-4 bk-field-ico" /><input id="bk-restore-pass" v-model="restorePassword" :type="showPass ? 'text' : 'password'" autocomplete="current-password" /><button type="button" class="bk-eye" :aria-label="showPass ? $t('Hide') : $t('Show')" @click="showPass = !showPass"><icons name="eye-flat" class="w-4 h-4" /></button></div>
          <p v-if="form.errors.password" class="bk-err wims-field-error" role="alert">{{ form.errors.password }}</p>
        </div>
      </template>
      <template #footer>
        <div class="bk-foot">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeConfirm"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-red" :disabled="!canSubmitRestoreFromModal" @click="submitRestore"><icons name="refresh" class="w-4 h-4" />{{ form.processing ? $t('Restoring') + '...' : $t('Restore Database') }}</button>
        </div>
      </template>
    </tec-dialog-modal>

    <!-- download a backup of the database now -->
    <tec-dialog-modal :show="downloadConfirmVisible" @close="closeDownloadConfirm" :max-width="'md'">
      <template #title><span class="bk-title"><icons name="download" class="w-5 h-5" />{{ $t('Download Backup') }}</span></template>
      <template #content>
        <div class="bk-body">
          <div class="bk-hero"><span class="bk-hero-ico"><icons name="download" class="w-7 h-7" /></span>
            <div><b>{{ $t('A full copy of the database') }}</b><span>{{ $t('Every table and record, as one SQL file you can keep safe or restore later.') }}</span></div></div>
          <ul class="bk-facts">
            <li><icons name="tick" class="w-4 h-4" />{{ $t('Structure and data of the whole system') }}</li>
            <li><icons name="tick" class="w-4 h-4" />{{ $t('Saved as a .sql file on your computer') }}</li>
            <li><icons name="tick" class="w-4 h-4" />{{ $t('Recorded in the backup activity list') }}</li>
          </ul>
          <label class="bk-label" for="bk-dl-pass">{{ $t('Enter your current login password to download this backup.') }}</label>
          <div class="bk-field" :class="{ 'is-bad': downloadError }"><icons name="lock-closed" class="w-4 h-4 bk-field-ico" /><input id="bk-dl-pass" v-model="downloadPassword" :type="showPass ? 'text' : 'password'" autocomplete="current-password" @keyup.enter="downloadPassword && !downloadProcessing && submitDownload()" /><button type="button" class="bk-eye" :aria-label="showPass ? $t('Hide') : $t('Show')" @click="showPass = !showPass"><icons name="eye-flat" class="w-4 h-4" /></button></div>
          <p v-if="downloadError" class="bk-err" role="alert">{{ downloadError }}</p>
        </div>
      </template>
      <template #footer>
        <div class="bk-foot">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeDownloadConfirm"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" :disabled="!downloadPassword || downloadProcessing" @click="submitDownload"><icons :name="downloadProcessing ? 'refresh' : 'download'" class="w-4 h-4" :class="{ 'animate-spin': downloadProcessing }" />{{ downloadProcessing ? $t('Preparing') + '...' : $t('Download Backup') }}</button>
        </div>
      </template>
    </tec-dialog-modal>

    <!-- download or restore one of the automatic backups -->
    <tec-dialog-modal :show="autoConfirmVisible" @close="closeAutoConfirm" :max-width="'md'">
      <template #title><span class="bk-title" :class="{ 'is-red': autoActionType !== 'download' }"><icons :name="autoActionType === 'download' ? 'download' : 'refresh'" class="w-5 h-5" />{{ autoActionType === 'download' ? $t('Download Auto Backup') : $t('Restore Auto Backup') }}</span></template>
      <template #content>
        <div class="bk-body">
          <div v-if="autoActionType === 'restore'" class="bk-hero is-red"><span class="bk-hero-ico"><icons name="exclamation" class="w-7 h-7" /></span>
            <div><b>{{ $t('All current data will be replaced') }}</b><span>{{ $t('This cannot be undone. Take a fresh backup first if you are not sure.') }}</span></div></div>
          <div class="bk-file"><icons name="doc" class="w-5 h-5" /><div><small>{{ autoActionType === 'download' ? $t('You are about to download') : $t('Restore from') }}</small><b>{{ autoConfirmActivity ? autoConfirmActivity.filename : '' }}</b></div></div>
          <template v-if="autoActionType === 'restore'">
            <label class="bk-label" for="bk-auto-word">{{ $t('Type RESTORE to enable the restore button') }}</label>
            <div class="bk-field"><icons name="exclamation" class="w-4 h-4 bk-field-ico" /><input id="bk-auto-word" v-model="autoConfirmInput" type="text" placeholder="RESTORE" autocomplete="off" /></div>
          </template>
          <label class="bk-label" for="bk-auto-pass">{{ $t('Enter your password to continue') }}</label>
          <div class="bk-field" :class="{ 'is-bad': autoActionError }"><icons name="lock-closed" class="w-4 h-4 bk-field-ico" /><input id="bk-auto-pass" v-model="autoActionPassword" :type="showPass ? 'text' : 'password'" autocomplete="current-password" /><button type="button" class="bk-eye" :aria-label="showPass ? $t('Hide') : $t('Show')" @click="showPass = !showPass"><icons name="eye-flat" class="w-4 h-4" /></button></div>
          <p v-if="autoActionError" class="bk-err" role="alert">{{ autoActionError }}</p>
        </div>
      </template>
      <template #footer>
        <div class="bk-foot">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeAutoConfirm"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn" :class="autoActionType === 'download' ? 'lg-btn-primary' : 'lg-btn-red'" :disabled="!canSubmitAutoAction" @click="submitAutoAction"><icons :name="autoActionType === 'download' ? 'download' : 'refresh'" class="w-4 h-4" />{{ autoProcessing ? (autoActionType === 'download' ? $t('Preparing') : $t('Restoring')) + '...' : (autoActionType === 'download' ? $t('Download Backup') : $t('Restore Database')) }}</button>
        </div>
      </template>
    </tec-dialog-modal>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TecSectionTitle from '@/Jetstream/SectionTitle.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';

export default {
  components: { AdminLayout, TecSectionTitle, TecDialogModal },

  props: {
    database: { type: String, default: '' },
    activities: { type: Array, default: () => [] },
  },

  data() {
    return {
      confirm: false,
      actionError: '',
      form: this.$inertia.form({
        file: null,
        confirm: '',
        password: '',
      }),
        restorePassword: '',
        showPass: false,
        downloadConfirmVisible: false,
        downloadPassword: '',
        downloadError: '',
        downloadProcessing: false,
        systemBackupProcessing: false,
        autoConfirmVisible: false,
        autoConfirmActivity: null,
        autoConfirmInput: '',
        autoActionType: 'restore',
        autoActionPassword: '',
        autoActionError: '',
        autoProcessing: false,
    };
  },

  computed: {
    lastBackup() {
      return this.activities.find(a => !/restore/i.test(a.activity || '')) || null;
    },
    lastRestore() {
      return this.activities.find(a => /restore/i.test(a.activity || '')) || null;
    },
    backupCount() {
      return this.activities.filter(a => !/restore/i.test(a.activity || '')).length;
    },
    canSubmit() {
      return this.form.file && this.form.confirm === 'RESTORE' && !this.form.processing;
    },
    canSubmitRestoreFromModal() {
      return !!this.restorePassword && !this.form.processing;
    },
    canSubmitAutoAction() {
      if (this.autoProcessing || !this.autoActionPassword) {
        return false;
      }

      if (this.autoActionType === 'restore') {
        return this.autoConfirmInput === 'RESTORE';
      }

      return true;
    },
  },

  methods: {
    activityChip(activity) {
      const a = String(activity || '');
      if (/restore/i.test(a)) return 'lg-chip-red';
      if (/auto/i.test(a)) return 'lg-chip-blue';
      if (/system/i.test(a)) return 'lg-chip-green';
      return 'lg-chip-slate';
    },
    csrfToken() {
      // Read the live XSRF-TOKEN cookie (refreshed by Laravel on every response)
      // instead of the <meta> tag, which is only set once at initial page load
      // and goes stale if the session token rotates while the tab stays open.
      const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
      return match ? decodeURIComponent(match[1]) : '';
    },
    async downloadViaFetch(url, password, fallbackFilename) {
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-XSRF-TOKEN': this.csrfToken(),
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ password }),
        credentials: 'same-origin',
      });

      const contentType = response.headers.get('content-type') || '';
      const isJsonPayload = contentType.includes('application/json');
      // A redirect (e.g. expired session) that fetch silently followed lands on
      // an HTML page with a 200 status — never treat that as a valid file.
      const isUnexpectedHtml = contentType.includes('text/html');

      if (!response.ok || isJsonPayload || isUnexpectedHtml) {
        let message = this.$t('Password is incorrect or the backup could not be downloaded.');
        if (isJsonPayload) {
          try {
            const data = await response.json();
            if (data?.message) {
              message = data.message;
            }
          } catch (e) {
            // Keep default message when the JSON payload can't be parsed.
          }
        } else if (isUnexpectedHtml) {
          message = this.$t('Your session may have expired. Please refresh the page and try again.');
        }
        throw new Error(message);
      }

      const blob = await response.blob();
      const contentDisposition = response.headers.get('content-disposition') || '';
      const filenameMatch = contentDisposition.match(/filename\*=UTF-8''([^;]+)|filename="?([^";]+)"?/i);
      const filename = decodeURIComponent(filenameMatch?.[1] || filenameMatch?.[2] || fallbackFilename || 'backup.sql');
      const fileURL = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = fileURL;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(fileURL);
    },
    onFileChange(e) {
      this.form.file = e.target.files[0] || null;
    },
    openDownloadConfirm() {
      this.actionError = '';
      this.downloadPassword = '';
      this.downloadError = '';
      this.downloadConfirmVisible = true;
    },
    closeDownloadConfirm() {
      this.downloadPassword = '';
      this.downloadError = '';
      this.downloadConfirmVisible = false;
      this.downloadProcessing = false;
    },
    async submitDownload() {
      if (!this.downloadPassword || this.downloadProcessing) {
        return;
      }

      this.downloadProcessing = true;
      try {
        await this.downloadViaFetch(this.route('backup.download'), this.downloadPassword, `${this.database}-backup.sql`);
        this.closeDownloadConfirm();
      } catch (error) {
        this.downloadError = error.message;
        this.actionError = error.message;
        this.downloadProcessing = false;
      }
    },
    openAutoAction(entry, actionType) {
      this.actionError = '';
      this.autoConfirmActivity = entry;
      this.autoActionType = actionType;
      this.autoConfirmInput = '';
      this.autoActionPassword = '';
      this.autoActionError = '';
      this.autoConfirmVisible = true;
      this.autoProcessing = false;
    },
    hasBackupActions(entry) {
      return ['Auto Back Up', 'System BackUp'].includes(entry.activity);
    },
    createSystemBackup() {
      if (this.systemBackupProcessing) {
        return;
      }

      this.actionError = '';
      this.systemBackupProcessing = true;
      this.$inertia.post(this.route('backup.system.create'), {}, {
        preserveScroll: true,
        onError: () => {
          this.actionError = this.$t('Could not create system backup.');
        },
        onFinish: () => {
          this.systemBackupProcessing = false;
        },
      });
    },
    closeAutoConfirm() {
      this.autoConfirmVisible = false;
      this.autoConfirmActivity = null;
      this.autoConfirmInput = '';
      this.autoActionType = 'restore';
      this.autoActionPassword = '';
      this.autoActionError = '';
      this.autoProcessing = false;
    },
    async submitAutoAction() {
      if (!this.autoConfirmActivity) return;
      if (!this.canSubmitAutoAction) return;
      this.autoProcessing = true;

      if (this.autoActionType === 'download') {
        try {
          await this.downloadViaFetch(
            this.route('backup.download.auto', this.autoConfirmActivity.id),
            this.autoActionPassword,
            this.autoConfirmActivity.filename || 'auto-backup.sql'
          );
          this.closeAutoConfirm();
        } catch (error) {
          this.autoProcessing = false;
          this.autoActionError = error.message;
          this.actionError = error.message;
        }
        return;
      }

      this.$inertia.post(
        this.route('backup.restore.auto', this.autoConfirmActivity.id),
        {
          confirm: this.autoConfirmInput,
          password: this.autoActionPassword,
        },
        {
          preserveScroll: true,
          onSuccess: () => this.closeAutoConfirm(),
          onFinish: () => (this.autoProcessing = false),
        }
      );
    },
    openConfirm() {
      if (!this.canSubmit) return;
      this.actionError = '';
      this.restorePassword = '';
      this.confirm = true;
    },
    closeConfirm() {
      this.restorePassword = '';
      this.confirm = false;
    },
    submitRestore() {
      if (!this.restorePassword) {
        return;
      }
      this.form.password = this.restorePassword;
      this.form.post(this.route('backup.restore'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
          this.confirm = false;
          this.form.file = null;
          this.form.confirm = '';
          this.form.password = '';
          this.restorePassword = '';
          if (this.$refs.fileInput) this.$refs.fileInput.value = '';
        },
        onFinish: () => {
          this.form.password = '';
        },
      });
    },
    formatDate(value) {
      if (!value) {
        return '-';
      }

      return new Date(value).toLocaleString();
    },
    formatBytes(bytes) {
      if (bytes === null || bytes === undefined) {
        return '-';
      }

      if (bytes === 0) {
        return '0 B';
      }

      const units = ['B', 'KB', 'MB', 'GB'];
      const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
      const value = bytes / 1024 ** exponent;

      return `${exponent === 0 ? value : value.toFixed(2)} ${units[exponent]}`;
    },
  },
};
</script>

<style scoped>
.bk-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; align-items: start; }
.bk-divider { height: 1px; margin: 1.4rem 0; background: var(--lg-line); }
.bk-danger { border-color: rgba(244, 63, 94, 0.28); box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 14px 30px -18px rgba(190, 18, 60, 0.35); }
.bk-drop { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-radius: 16px; border: 2px dashed #cbd5e1; background: var(--lg-soft); cursor: pointer; transition: border-color 0.15s ease, background 0.15s ease; }
.bk-drop:hover { border-color: #f43f5e; background: rgba(244, 63, 94, 0.05); }
.bk-drop.has-file { border-style: solid; border-color: #34d399; background: rgba(16, 185, 129, 0.07); }
.bk-drop-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 42px; height: 42px; border-radius: 13px; color: #be123c; background: rgba(244, 63, 94, 0.12); }
.bk-drop.has-file .bk-drop-ico { color: #047857; background: rgba(16, 185, 129, 0.16); }
.bk-drop-title { display: block; font-size: 0.86rem; font-weight: 800; color: var(--lg-ink); overflow-wrap: anywhere; }
.bk-drop-sub { display: block; margin-top: 1px; font-size: 0.74rem; color: var(--lg-mute); }
.bk-label { display: block; margin: 1.1rem 0 0.4rem; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--lg-mute); }
.bk-input { width: 100%; padding: 0.55rem 0.8rem; border-radius: 12px; font-size: 0.9rem; letter-spacing: 0.08em; background: var(--lg-card); border: 1.5px solid var(--lg-line); color: var(--lg-ink); }
.bk-input:focus { outline: none; border-color: #f43f5e; box-shadow: 0 0 0 3px rgba(244, 63, 94, 0.15); }
.bk-input.is-error { border-color: #fca5a5; }
.bk-error { margin-top: 6px; font-size: 0.76rem; font-weight: 700; color: #e11d48; }
@media (max-width: 900px) { .bk-grid { grid-template-columns: minmax(0, 1fr); } }

/* the three password windows */
.bk-title { display: inline-flex; align-items: center; gap: 10px; font-weight: 800; color: #1c3f66; } .bk-title.is-red { color: #9f1239; }
.bk-body { display: grid; gap: 12px; }
.bk-hero { display: flex; gap: 14px; align-items: center; padding: 14px 16px; border-radius: 18px; background: linear-gradient(135deg, rgba(224, 233, 250, 0.9), rgba(236, 253, 245, 0.7)); border: 1px solid rgba(59, 130, 196, 0.25); }
.bk-hero.is-red { background: linear-gradient(135deg, rgba(255, 228, 230, 0.95), rgba(255, 247, 237, 0.8)); border-color: rgba(225, 29, 72, 0.3); }
.bk-hero-ico { flex: none; width: 52px; height: 52px; border-radius: 16px; display: grid; place-items: center; color: #fff; background: linear-gradient(135deg, #2f6199, #1c3f66); box-shadow: 0 12px 20px -12px rgba(28, 63, 102, 0.9); }
.bk-hero.is-red .bk-hero-ico { background: linear-gradient(135deg, #e11d48, #9f1239); box-shadow: 0 12px 20px -12px rgba(159, 18, 57, 0.9); }
.bk-hero > div { display: grid; gap: 2px; min-width: 0; } .bk-hero b { font-size: 0.98rem; color: #1e293b; } .bk-hero span:not(.bk-hero-ico) { font-size: 0.82rem; color: #64748b; }
.bk-facts { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
.bk-facts li { display: flex; align-items: center; gap: 9px; font-size: 0.84rem; font-weight: 600; color: #334155; } .bk-facts svg { flex: none; color: #059669; background: #d8f1e5; border-radius: 50%; padding: 2px; width: 20px; height: 20px; }
.bk-file { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 14px; background: rgba(148, 163, 184, 0.14); }
.bk-file > div { display: grid; min-width: 0; } .bk-file small { font-size: 0.64rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: #94a3b8; } .bk-file b { font-size: 0.86rem; overflow-wrap: anywhere; }
.bk-label { font-size: 0.74rem; font-weight: 800; letter-spacing: 0.04em; color: #64748b; margin-top: 2px; }
.bk-field { position: relative; display: flex; align-items: center; }
.bk-field input { width: 100%; height: 46px; padding: 0 44px 0 40px; border-radius: 14px; border: 1px solid #d6dfeb; background: #fff; font-size: 1rem; }
.bk-field input:focus { outline: none; border-color: #3b82c4; box-shadow: 0 0 0 3px rgba(59, 130, 196, 0.22); }
.bk-field.is-bad input { border-color: #e11d48; box-shadow: 0 0 0 3px rgba(225, 29, 72, 0.14); }
.bk-field-ico { position: absolute; left: 13px; color: #94a3b8; pointer-events: none; }
.bk-eye { position: absolute; right: 6px; width: 34px; height: 34px; border: 0; border-radius: 10px; background: transparent; color: #64748b; cursor: pointer; display: grid; place-items: center; } .bk-eye:hover { background: rgba(148, 163, 184, 0.2); }
.bk-err { margin: 0; font-size: 0.8rem; font-weight: 700; color: #be123c; }
.bk-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; width: 100%; }
.bk-foot .lg-btn { flex: 1 1 150px; justify-content: center; }
@media (max-width: 479.98px) { .bk-hero { padding: 12px; } .bk-hero-ico { width: 44px; height: 44px; } }
</style>

<template>
  <admin-layout :title="$t('Site Diary Settings')">
    <div class="lg-page sdy px-4 md:px-0">
      <page-header icon="cog" :title="$t('Site Diary Settings')" :subtitle="$t('Limits, Google Drive storage, security and notifications. Every change is logged.')">
        <Link :href="route('site-diary.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('All Site Diaries') }}</Link>
      </page-header>

      <!-- Photos -->
      <section class="lg-card ss-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="image" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Photos') }}</h2><p class="lg-card-sub">{{ $t('Limits are enforced on the server, whatever the browser sends.') }}</p></div>
          <label class="ss-switch lg-tip" :data-tip="s.photos_enabled ? $t('On') : $t('Off')"><input type="checkbox" class="sdy-cb" v-model="s.photos_enabled" /><span></span></label>
        </header>
        <p v-if="!feature.photos" class="lg-note lg-note-amber mb-3"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t('Photos are switched off in Global Feature Control, so they stay off whatever is set here.') }}</span></p>
        <div class="lg-fields">
          <label class="ss-field"><span>{{ $t('Maximum photos per diary') }}</span><input type="number" min="1" max="500" v-model.number="s.max_photos" /></label>
          <label class="ss-field"><span>{{ $t('Photo Size') }}</span><input type="number" min="640" max="6000" step="64" v-model.number="s.capture_max_px" /><small>{{ $t('Longest side, in pixels, of pictures taken with the camera. Smaller = faster uploads on mobile data.') }}</small></label>
          <label class="ss-field"><span>{{ $t('Maximum photo size (MB)') }}</span><input type="number" min="1" :max="Math.min(500, server_max_mb)" v-model.number="s.max_photo_mb" /><small>{{ $t('Server limit: :n MB per file', { n: server_max_mb }) }}</small></label>
          <div class="ss-field lg-span-all"><span>{{ $t('Allowed photo types') }}</span>
            <div class="ss-checks"><label v-for="t in supported.photo" :key="t" class="sdy-type-chip" :class="{ 'is-on': s.photo_types.includes(t) }"><input type="checkbox" class="sr-only" :value="t" v-model="s.photo_types" /><span class="sdy-type-mark"><icons :name="s.photo_types.includes(t) ? 'tick' : 'cross'" class="w-3 h-3" /></span>{{ t.toUpperCase() }}</label></div>
          </div>
        </div>
      </section>

      <!-- Videos -->
      <section class="lg-card ss-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="video" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Videos') }}</h2><p class="lg-card-sub">{{ $t('Off by default. Turning videos off hides the controls and blocks uploads; existing videos are kept.') }}</p></div>
          <label class="ss-switch lg-tip" :data-tip="s.videos_enabled ? $t('On') : $t('Off')"><input type="checkbox" class="sdy-cb" v-model="s.videos_enabled" /><span></span></label>
        </header>
        <p v-if="!feature.videos" class="lg-note lg-note-amber mb-3"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t('Videos are switched off in Global Feature Control, so they stay off whatever is set here.') }}</span></p>
        <div class="lg-fields">
          <label class="ss-field"><span>{{ $t('Maximum videos per diary') }}</span><input type="number" min="1" max="100" v-model.number="s.max_videos" /></label>
          <label class="ss-field"><span>{{ $t('Maximum video size (MB)') }}</span><input type="number" min="1" :max="Math.min(5000, server_max_mb)" v-model.number="s.max_video_mb" /><small>{{ $t('Server limit: :n MB per file', { n: server_max_mb }) }}</small></label>
          <div class="ss-field lg-span-all"><span>{{ $t('Allowed video types') }}</span>
            <div class="ss-checks"><label v-for="t in supported.video" :key="t" class="sdy-type-chip" :class="{ 'is-on': s.video_types.includes(t) }"><input type="checkbox" class="sr-only" :value="t" v-model="s.video_types" /><span class="sdy-type-mark"><icons :name="s.video_types.includes(t) ? 'tick' : 'cross'" class="w-3 h-3" /></span>{{ t.toUpperCase() }}</label></div>
          </div>
        </div>
      </section>

      <!-- Storage location -->
      <section class="lg-card ss-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-teal"><icons name="upload" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Storage location') }}</h2><p class="lg-card-sub">{{ $t('Choose where photos and videos are kept. Either way they are private and only opened through WIMS.') }}</p></div>
        </header>

        <div class="ss-choices" role="radiogroup" :aria-label="$t('Storage location')">
          <button type="button" role="radio" :aria-checked="!s.drive_enabled" class="ss-choice" :class="{ 'is-on': !s.drive_enabled }" @click="s.drive_enabled = false">
            <span class="ss-choice-ico is-cloud"><icons name="building" class="w-6 h-6" /></span>
            <span class="ss-choice-t"><b>{{ $t('WIMS Cloud') }}</b><small>{{ $t('Files are kept on the WIMS server. Works right now, nothing to connect.') }}</small></span>
            <span class="ss-choice-mark"><icons :name="!s.drive_enabled ? 'tick' : 'cross'" class="w-3.5 h-3.5" /></span>
          </button>
          <button type="button" role="radio" :aria-checked="!!s.drive_enabled" class="ss-choice" :class="{ 'is-on': s.drive_enabled }" @click="s.drive_enabled = true">
            <span class="ss-choice-ico is-drive"><icons name="upload" class="w-6 h-6" /></span>
            <span class="ss-choice-t"><b>{{ $t('Google Drive') }}</b><small>{{ drive.connected ? $t('Connected, new files are sent to your Google Drive.') : $t('Needs Google to be connected first (set up below).') }}</small></span>
            <span class="ss-choice-mark"><icons :name="s.drive_enabled ? 'tick' : 'cross'" class="w-3.5 h-3.5" /></span>
          </button>
        </div>

        <div class="ss-status">
          <span class="lg-chip" :class="s.drive_enabled ? 'lg-chip-blue' : 'lg-chip-green'" style="text-transform: none">{{ s.drive_enabled ? $t('Active after saving: Google Drive') : $t('Active: WIMS Cloud') }}</span>
          <span class="lg-subtext">{{ $t('Waiting') }}: {{ queue.waiting }} · {{ $t('Failed') }}: {{ queue.failed }} · {{ $t('Kept on the WIMS server') }}: {{ queue.local }}</span>
        </div>

        <label class="ss-chk mt-2"><input type="checkbox" class="sdy-cb" v-model="s.thumbnails" />{{ $t('Keep a small preview of each photo on the WIMS server (fast galleries; the original is never changed)') }}</label>

        <p v-if="!s.drive_enabled" class="lg-note lg-note-blue mt-3"><icons name="info" class="w-4 h-4" /><span>{{ $t('WIMS Cloud is the storage location, so the Google Drive settings below are optional. You can save the page even if they are empty or incomplete, they are not checked until you choose Google Drive.') }}</span></p>

        <!-- Google Drive set-up -->
        <div class="ss-gd" :class="{ 'is-idle': !s.drive_enabled }">
          <div class="ss-conn" :class="drive.connected ? 'is-ok' : drive.client_saved ? 'is-wait' : 'is-off'" role="status">
            <span class="ss-conn-ico"><icons :name="drive.connected ? 'tick' : drive.client_saved ? 'clock' : 'cross'" class="w-6 h-6" /></span>
            <div class="ss-conn-t">
              <b class="ss-conn-state">{{ drive.connected ? $t('Connected') : drive.client_saved ? $t('Key saved, press Connect') : $t('Not connected') }}</b>
              <span v-if="drive.connected && drive.identity" class="ss-conn-acc">
                <small>{{ drive.type === 'oauth' ? $t('Google account') : $t('Service account') }}:</small>
                <strong>{{ drive.identity }}</strong>
              </span>
              <small v-if="drive.connected && !s.drive_enabled" class="ss-conn-hint">{{ $t('Ready to use. Choose “Google Drive” above and press Save Settings to start sending new files there.') }}</small>
              <small v-else-if="drive.connected" class="ss-conn-hint">{{ $t('New photos and videos are being sent to this Google Drive.') }}</small>
            </div>
            <div v-if="drive.connected" class="ss-conn-actions">
              <a v-if="driveFolderUrl" :href="driveFolderUrl" target="_blank" rel="noopener" class="ss-conn-link"><icons name="link" class="w-4 h-4" />{{ $t('Open folder in Google Drive') }}</a>
              <button type="button" class="ss-conn-link is-danger" :disabled="busy" @click="clearOpen = !clearOpen"><icons name="trash" class="w-4 h-4" />{{ $t('Clear Drive') }}</button>
            </div>
          </div>

          <div v-if="clearOpen && drive.connected" class="ss-clear" role="alertdialog" :aria-label="$t('Clear Drive')">
            <b><icons name="exclamation" class="w-5 h-5" />{{ $t('Clear Drive, this cannot be undone') }}</b>
            <p>{{ $t('Every Site Diary photo and video inside the storage folder is erased from Google Drive for good (not sent to the Trash). The folder itself stays. The diaries, remarks and history stay too, but their attachments will show as erased. Only files WIMS created are touched.') }}</p>
            <div class="ss-clear-row">
              <label class="ss-field"><span>{{ $t('Type CLEAR to confirm') }}</span><input type="text" v-model.trim="clearText" autocomplete="off" placeholder="CLEAR" /></label>
              <button type="button" class="lg-btn lg-btn-red" :disabled="busy || clearText !== 'CLEAR' || !password" @click="clearDrive"><icons name="trash" class="w-4 h-4" />{{ $t('Erase everything now') }}</button>
              <button type="button" class="lg-btn lg-btn-soft" @click="clearOpen = false; clearText = ''">{{ $t('Cancel') }}</button>
            </div>
            <small v-if="!password" class="ss-hint">{{ $t('Type your password in the bar at the bottom first.') }}</small>
          </div>

          <div v-if="drive.connected" class="ss-usage" :class="{ 'is-bad': usage && !usage.ok }">
            <div class="ss-usage-head">
              <b><icons name="chart-bar" class="w-4 h-4" />{{ $t('Google Drive storage') }}</b>
              <button type="button" class="ss-usage-r lg-tip" :data-tip="$t('Refresh')" :aria-label="$t('Refresh')" :disabled="usageBusy" @click="loadUsage"><icons name="refresh" class="w-4 h-4" /></button>
            </div>
            <p v-if="usageBusy && !usage" class="lg-subtext">{{ $t('Checking…') }}</p>
            <p v-else-if="usage && !usage.ok" class="lg-subtext">{{ usage.message }}</p>
            <template v-else-if="usage">
              <div v-if="usage.limit" class="ss-bar" role="progressbar" :aria-valuenow="usedPct" aria-valuemin="0" aria-valuemax="100"><span :style="{ width: usedPct + '%' }" :class="{ 'is-hot': usedPct >= 90 }"></span></div>
              <div class="ss-usage-grid">
                <div><small>{{ $t('Used') }}</small><strong>{{ bytes(usage.used) }}</strong></div>
                <div><small>{{ $t('Left') }}</small><strong>{{ usage.limit ? bytes(usage.left) : $t('Unlimited') }}</strong></div>
                <div><small>{{ $t('Total') }}</small><strong>{{ usage.limit ? bytes(usage.limit) : $t('Unlimited') }}</strong></div>
                <div><small>{{ $t('Site Diary files') }}</small><strong>{{ bytes(usage.wims_bytes) }}</strong><em>{{ usage.wims_files }} {{ $t('file(s)') }}</em></div>
              </div>
            </template>
          </div>

          <div class="lg-fields">
            <label class="ss-field lg-span-all"><span>{{ $t('Google Drive folder name - WIMS creates it in your Drive when you connect (e.g. WIMS Site Diaries)') }}</span><input type="text" v-model.trim="s.drive_root_folder_id" placeholder="WIMS Site Diaries" autocomplete="off" /></label>
          </div>

          <div class="ss-connect">
            <h3 class="ss-h3"><icons name="link" class="w-4 h-4" />{{ $t('Connect your Google account') }}</h3>
            <ol class="ss-steps">
              <li>
                <b>{{ $t('Create the Google sign-in key (once)') }}</b>
                <small>{{ $t('In Google Cloud Console → APIs & Services: enable “Google Drive API”, then Credentials → Create credentials → OAuth client ID → Web application. Add this address under “Authorised redirect URIs”:') }}</small>
                <div class="ss-copy"><code>{{ redirect_uri }}</code><button type="button" class="lg-btn lg-btn-soft" @click="copyRedirect">{{ copied ? $t('Copied') : $t('Copy') }}</button></div>
              </li>
              <li>
                <b>{{ $t('Paste the Client ID and Client secret Google gives you') }}</b>
                <div class="lg-fields mt-2">
                  <label class="ss-field"><span>{{ $t('Client ID') }}</span><input type="text" v-model.trim="oauth.client_id" autocomplete="off" :placeholder="drive.client_saved ? $t('Saved, paste again only to replace') : ''" /></label>
                  <label class="ss-field"><span>{{ $t('Client secret') }}</span><input type="password" v-model.trim="oauth.client_secret" autocomplete="new-password" :placeholder="drive.client_saved ? '••••••••' : ''" /></label>
                </div>
              </li>
              <li>
                <b>{{ $t('Click Connect and approve') }}</b>
                <small>{{ $t('WIMS creates a folder called “WIMS Site Diaries” in that Google Drive and keeps every diary photo and video there. It can only see files it created itself. Your changes on this page are saved first.') }}</small>
                <button type="button" class="lg-btn lg-btn-primary mt-2" :disabled="busy || !password || !(drive.client_saved || (oauth.client_id && oauth.client_secret))" @click="connectGoogle"><icons name="link" class="w-4 h-4" />{{ drive.connected && drive.type === 'oauth' ? $t('Reconnect Google account') : $t('Connect Google account') }}</button>
                <small v-if="!password" class="ss-hint">{{ $t('Type your password in the bar at the bottom first.') }}</small>
              </li>
            </ol>
          </div>

          <details class="ss-cred">
            <summary>{{ $t('Advanced: service account or manual token') }}</summary>
            <p class="lg-note lg-note-blue"><icons name="info" class="w-4 h-4" /><span>{{ $t('Only needed if you prefer a service account (its email must be an Editor of a Shared Drive folder) or already have an OAuth refresh token. Anything typed here is saved with the Save Settings button, encrypted, and can never be shown again.') }}</span></p>
            <div class="ss-seg"><button type="button" :class="{ 'is-on': cred.type === 'service_account' }" @click="cred.type = 'service_account'">{{ $t('Service account') }}</button><button type="button" :class="{ 'is-on': cred.type === 'oauth' }" @click="cred.type = 'oauth'">{{ $t('OAuth refresh token') }}</button></div>
            <div class="lg-fields mt-3">
              <label v-if="cred.type === 'service_account'" class="ss-field lg-span-all"><span>{{ $t('Service-account key file (paste the JSON)') }}</span><textarea rows="5" v-model="cred.json" spellcheck="false" placeholder='{ "type": "service_account", "client_email": "...", "private_key": "..." }'></textarea></label>
              <template v-else>
                <label class="ss-field"><span>{{ $t('Client ID') }}</span><input type="text" v-model.trim="cred.client_id" autocomplete="off" /></label>
                <label class="ss-field"><span>{{ $t('Client secret') }}</span><input type="password" v-model.trim="cred.client_secret" autocomplete="new-password" /></label>
                <label class="ss-field lg-span-all"><span>{{ $t('Refresh token') }}</span><input type="password" v-model.trim="cred.refresh_token" autocomplete="new-password" /></label>
              </template>
            </div>
          </details>

          <div class="ss-row">
            <button type="button" class="lg-btn lg-btn-soft" :disabled="busy || !drive.connected" @click="testConn"><icons name="wifi" class="w-4 h-4" />{{ $t('Test connection') }}</button>
            <button type="button" class="lg-btn lg-btn-soft" :disabled="busy || !drive.ready" @click="syncNow"><icons name="refresh" class="w-4 h-4" />{{ $t('Send waiting files now') }}</button>
            <button v-if="drive.connected || drive.client_saved" type="button" class="lg-btn lg-btn-red" :disabled="busy || !password" @click="disconnect"><icons name="trash" class="w-4 h-4" />{{ $t('Disconnect Google Drive') }}</button>
          </div>
          <p v-if="info" class="lg-note mt-3" :class="infoBad ? 'lg-note-red' : 'lg-note-blue'"><icons name="info" class="w-4 h-4" /><span>{{ info }}</span></p>
        </div>
      </section>

      <!-- Security + content -->
      <section class="lg-card ss-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-rose"><icons name="lock-closed" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Security & content') }}</h2></div>
        </header>
        <div class="ss-list">
          <label class="ss-chk"><input type="checkbox" class="sdy-cb" v-model="s.downloads_allowed" />{{ $t('Allow downloading photos and videos (for users who also hold the download permission)') }}</label>
          <label class="ss-chk"><input type="checkbox" class="sdy-cb" v-model="s.delete_files_on_remove" />{{ $t('When a photo or video is removed from a diary, also delete the file itself (it goes to the Google Drive Trash and is erased from the WIMS server). The record and its history are kept.') }}</label>
          <label class="ss-chk"><input type="checkbox" class="sdy-cb" v-model="s.password_on_delete" />{{ $t('Ask for the password when deleting a diary') }}</label>
          <label class="ss-chk"><input type="checkbox" class="sdy-cb" v-model="s.require_media" />{{ $t('Every diary must have at least one photo or video before it can be saved') }}</label>
          <label class="ss-chk"><input type="checkbox" class="sdy-cb" v-model="s.remarks_required" />{{ $t('Remarks are required') }}</label>
          <div class="ss-retain">
            <b class="ss-retain-t"><icons name="clock" class="w-4 h-4" />{{ $t('Delete files from Google Drive after') }}</b>
            <small>{{ $t('Photos and videos older than this are erased from Google Drive automatically (checked once a day). The diary, remarks and history are always kept. “Forever” never deletes anything.') }}</small>
            <div class="ss-retain-opts">
              <label v-for="o in retentionOptions" :key="o.value" class="ss-chk ss-retain-opt" :class="{ 'is-on': s.drive_retention_days === o.value }"><input type="checkbox" class="sdy-cb" :checked="s.drive_retention_days === o.value" @change="s.drive_retention_days = o.value" />{{ o.label }}</label>
            </div>
          </div>
          <div class="ss-fixed"><icons name="lock-closed" class="w-4 h-4" />{{ $t('External sharing: always off, media is never public and Google Drive links are never exposed.') }}</div>
          <div class="ss-fixed"><icons name="trash" class="w-4 h-4" />{{ $t('Delete policy: a deleted diary is hidden and logged with a reason; it is never destroyed.') }}</div>
        </div>
        <div class="lg-fields mt-3">
          <label class="ss-field"><span>{{ $t('Maximum remarks length (characters)') }}</span><input type="number" min="100" max="20000" v-model.number="s.remarks_max" /></label>
        </div>
      </section>

      <!-- Daily diary -->
      <section class="lg-card ss-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="calendar" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Require a daily Site Diary') }}</h2><p class="lg-card-sub">{{ $t('Used by the “missing diary” notification. Off for every project unless you switch it on.') }}</p></div>
          <label class="ss-switch lg-tip" :data-tip="s.require_daily ? $t('On for all projects') : $t('Off')"><input type="checkbox" class="sdy-cb" v-model="s.require_daily" /><span></span></label>
        </header>
        <details class="ss-cred">
          <summary>{{ $t('Per-project override') }} ({{ overrides }})</summary>
          <div class="lg-table-wrap mt-2">
            <table class="lg-table lg-responsive">
              <thead><tr><th>{{ $t('Project') }}</th><th>{{ $t('Daily diary') }}</th></tr></thead>
              <tbody>
                <tr v-for="p in projectRules" :key="p.value">
                  <td :data-label="$t('Project')"><span class="lg-main">{{ p.name }}</span></td>
                  <td class="ss-pdd-cell" :data-label="$t('Daily diary')">
                    <auto-complete class="ss-pdd" :id="'ss_pd_' + p.value" :suggestions="dailyOptions" :model-value="dailyValue(p)" :placeholder="$t('Choose…')" @update:modelValue="v => setDaily(p, v)" />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </details>
      </section>

      <!-- Notifications -->
      <section class="lg-card ss-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="bell" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Notifications') }}</h2><p class="lg-card-sub">{{ $t('Who is told about Site Diary events, on which channels (bell, email, SMS, WhatsApp) and with which words is set on the Site Diary Workflow page.') }}</p></div>
          <Link v-if="canOpenWorkflow" :href="route('site-diary.workflow')" class="lg-btn lg-btn-soft ml-auto"><icons name="bell" class="w-4 h-4" />{{ $t('Open the Site Diary Workflow') }}</Link>
        </header>
        <div class="lg-fields">
          <label class="ss-field ss-time"><span>{{ $t('Send the “diary missing” message at') }}</span><input type="time" v-model="missingTime" /><small>{{ $t('Once a day, for the projects that require a daily diary and have none yet. Switch it on, and choose who receives it, on the Site Diary Workflow page.') }}</small></label>
        </div>
      </section>

      <div class="ss-save lg-card">
        <label class="ss-pw"><span>{{ $t('Confirm with your password to save') }}</span>
          <input ref="pw" v-model="password" type="password" autocomplete="current-password" :placeholder="$t('Your password')" @keydown.enter.prevent="save" />
        </label>
        <p v-if="error" class="ss-err">{{ error }}</p>
        <p v-for="w in warnings" :key="w" class="ss-warn"><icons name="exclamation" class="w-4 h-4" />{{ w }}</p>
        <p v-if="saved" class="ss-ok"><icons name="tick" class="w-4 h-4" />{{ saved }}</p>
        <button type="button" class="lg-btn lg-btn-primary" :disabled="busy" @click="save"><icons name="tick" class="w-4 h-4" />{{ busy ? $t('Saving…') : $t('Save Settings') }}</button>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';

const split = v => String(v || '').split(',').map(x => x.trim()).filter(Boolean);

export default {
  components: { AdminLayout, PageHeader, Link, AutoComplete },

  props: { sd_settings: Object, redirect_uri: String, server_max_mb: Number, supported: Object, feature: Object, drive: Object, queue: Object, schedule: Object, canOpenWorkflow: Boolean, projects: Array },

  data() {
    const st = this.sd_settings;
    return {
      s: {
        photos_enabled: !!st.photos_enabled, max_photos: st.max_photos, max_photo_mb: st.max_photo_mb, photo_types: split(st.photo_types),
        videos_enabled: !!st.videos_enabled, max_videos: st.max_videos, max_video_mb: st.max_video_mb, video_types: split(st.video_types),
        drive_enabled: !!st.drive_enabled, drive_root_folder_id: st.drive_root_folder_id || '', thumbnails: !!st.thumbnails,
        downloads_allowed: !!st.downloads_allowed, password_on_delete: !!st.password_on_delete, remarks_required: !!st.remarks_required, require_media: !!st.require_media, delete_files_on_remove: !!st.delete_files_on_remove,
        remarks_max: st.remarks_max, require_daily: !!st.require_daily, capture_max_px: st.capture_max_px, drive_retention_days: Number(st.drive_retention_days || 0),
      },
      projectRules: this.projects.map(p => ({ ...p })),
      missingTime: (this.schedule && this.schedule.send_time) || '17:30',
      oauth: { client_id: '', client_secret: '' }, copied: false,
      cred: { type: this.drive.type === 'oauth' ? 'oauth' : 'service_account', json: '', client_id: '', client_secret: '', refresh_token: '' },
      clearOpen: false, clearText: '', usage: null, usageBusy: false,
      password: '', lastPassword: '', warnings: [], error: null, saved: null, busy: false, info: null, infoBad: false,
    };
  },

  computed: {
    credReady() {
      return this.cred.type === 'service_account' ? this.cred.json.trim().length > 20 : !!(this.cred.client_id && this.cred.client_secret && this.cred.refresh_token);
    },
    // the three choices of the per-project "daily diary" dropdown (a searchable list: type to filter, click to choose)
    dailyOptions() {
      return [
        { label: this.$t('Follow the global setting'), value: 'inherit' },
        { label: this.$t('Required'), value: '1' },
        { label: this.$t('Not required'), value: '0' },
      ];
    },
    // link to the storage folder in the connected Drive (only when the box holds a real folder id)
    driveFolderUrl() {
      const id = String(this.s.drive_root_folder_id || '');
      return /^[A-Za-z0-9_-]{20,128}$/.test(id) ? 'https://drive.google.com/drive/folders/' + id : null;
    },
    retentionOptions() {
      return [
        { value: 0, label: this.$t('Forever (default)') }, { value: 30, label: this.$t('1 Month') }, { value: 90, label: this.$t('3 Months') },
        { value: 180, label: this.$t('6 Months') }, { value: 365, label: this.$t('1 Year') },
      ];
    },
    usedPct() {
      return this.usage && this.usage.limit ? Math.min(100, Math.round((this.usage.used / this.usage.limit) * 100)) : 0;
    },
    overrides() {
      return this.projectRules.filter(p => p.require_daily !== null && p.require_daily !== undefined).length;
    },
  },

  mounted() {
    if (this.drive.connected) this.loadUsage();
  },

  methods: {
    bytes(b) {
      b = Number(b || 0);
      if (b >= 1099511627776) return (b / 1099511627776).toFixed(2) + ' TB';
      if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
      if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
      return Math.round(b / 1024) + ' KB';
    },
    async loadUsage() {
      this.usageBusy = true;
      try { const { data } = await window.axios.get(this.route('site-diary.settings.usage')); this.usage = data; }
      catch (e) { this.usage = { ok: false, message: (e.response && e.response.data && e.response.data.message) || this.$t('Google Drive Connection Problem') }; }
      finally { this.usageBusy = false; }
    },
    clearDrive() {
      return this.run(async () => {
        const { data } = await window.axios.post(this.route('site-diary.settings.clear-drive'), { password: this.password });
        this.info = this.$t('Drive cleared: :f file(s) and :d folder(s) erased, :r diary attachment(s) marked as erased.', { f: data.files, d: data.folders, r: data.records }) + (data.skipped ? ' ' + this.$t(':n item(s) could not be erased (not created by WIMS).', { n: data.skipped }) : '');
        this.infoBad = false; this.clearOpen = false; this.clearText = '';
        this.loadUsage();
      }, this.$t('Google Drive cleared.'));
    },
    dailyValue(p) {
      return p.require_daily === null || p.require_daily === undefined ? 'inherit' : String(p.require_daily);
    },
    setDaily(p, v) {
      p.require_daily = v === 'inherit' || v === '' || v === null || v === undefined ? null : Number(v);
    },
    fail(e) {
      const errs = e.response && e.response.data && e.response.data.errors;
      this.error = (errs && Object.values(errs)[0][0]) || (e.response && e.response.data && e.response.data.message) || this.$t('Unable to save.');
    },
    async run(fn, okText) {
      this.busy = true; this.error = null; this.saved = null;
      this.lastPassword = this.password;
      try { await fn(); this.saved = okText; this.password = ''; setTimeout(() => (this.saved = null), 3500); return true; }
      catch (e) { this.fail(e); return false; }
      finally { this.busy = false; }
    },
    // Builds the Google credential block from whatever was typed on the page (nothing typed = nothing sent).
    googleBlock() {
      if (this.oauth.client_id && this.oauth.client_secret) return { type: 'oauth_client', client_id: this.oauth.client_id, client_secret: this.oauth.client_secret };
      if (this.cred.type === 'service_account' && this.cred.json.trim()) return { type: 'service_account', service_account_json: this.cred.json };
      if (this.cred.type === 'oauth' && this.cred.client_id && this.cred.client_secret) return { type: 'oauth', client_id: this.cred.client_id, client_secret: this.cred.client_secret, refresh_token: this.cred.refresh_token };
      return null;
    },
    // ONE save for the whole page: settings, storage location, Google fields, project rules and the time of the daily “diary missing” message.
    save() {
      if (!this.password) {
        this.saved = null;
        this.error = this.$t('Type your password in the box first, it confirms that you are the one saving these settings.');
        this.$nextTick(() => this.$refs.pw && this.$refs.pw.focus());
        return Promise.resolve(false);
      }
      return this.run(async () => {
        const body = {
          password: this.password,
          settings: { ...this.s, photo_types: this.s.photo_types, video_types: this.s.video_types },
          project_rules: this.projectRules.map(p => ({ warehouse_id: p.value, require_daily: p.require_daily === null || p.require_daily === undefined ? null : !!p.require_daily })),
          missing_time: this.missingTime,
        };
        const g = this.googleBlock();
        if (g) body.google = g;
        const { data } = await window.axios.put(this.route('site-diary.settings.update'), body);
        this.oauth = { client_id: '', client_secret: '' };
        this.cred.json = this.cred.client_secret = this.cred.refresh_token = '';
        this.warnings = data.warnings || [];
        this.s.drive_enabled = !!(data.settings && data.settings.drive_enabled); // the server falls back to WIMS Cloud when Drive is not ready
        this.$inertia.reload({ only: ['sd_settings', 'drive', 'queue'] });
      }, this.$t('Everything on this page has been saved.'));
    },
    async connectGoogle() {
      // everything on the page (including a key just typed) is saved first, so Connect always sees the latest key
      const ok = await this.save();
      if (!ok) return;
      this.password = this.lastPassword;
      this.busy = true; this.error = null;
      try {
        const { data } = await window.axios.post(this.route('site-diary.settings.google.connect'), { password: this.password });
        window.location.href = data.url;
      } catch (e) {
        this.fail(e);
        this.busy = false;
      }
    },
    async copyRedirect() {
      try { await navigator.clipboard.writeText(this.redirect_uri); this.copied = true; setTimeout(() => (this.copied = false), 2000); } catch (e) { /* user can select the text */ }
    },
    disconnect() {
      return this.run(async () => {
        await window.axios.delete(this.route('site-diary.settings.disconnect'), { data: { password: this.password } });
        this.s.drive_enabled = false;
        this.$inertia.reload({ only: ['drive', 'sd_settings'] });
      }, this.$t('Google Drive disconnected.'));
    },
    async testConn() {
      this.busy = true; this.info = null;
      try { const { data } = await window.axios.post(this.route('site-diary.settings.test')); this.info = data.message; this.infoBad = false; }
      catch (e) { this.info = (e.response && e.response.data && e.response.data.message) || this.$t('Google Drive Connection Problem'); this.infoBad = true; }
      finally { this.busy = false; }
    },
    async syncNow() {
      this.busy = true; this.info = null;
      try { const { data } = await window.axios.post(this.route('site-diary.settings.sync')); this.info = this.$t(':a file(s) sent, :b failed.', { a: data.done, b: data.failed }); this.infoBad = data.failed > 0; this.$inertia.reload({ only: ['queue'] }); }
      catch (e) { this.info = (e.response && e.response.data && e.response.data.message) || this.$t('Google Drive Connection Problem'); this.infoBad = true; }
      finally { this.busy = false; }
    },
  },
};
</script>

<style scoped>
.ss-card { margin-bottom: 1rem; }
.ss-pdd-cell { min-width: 16rem; }
.ss-pdd { margin: 0 !important; max-width: 20rem; }
.ss-pdd :deep(input) { height: 2.6rem; border-radius: 0.9rem !important; border: 1px solid var(--lg-line) !important; background: var(--lg-card) !important; color: var(--lg-ink); font-weight: 700; cursor: pointer; box-shadow: 0 10px 20px -12px rgba(15, 23, 42, 0.55), 0 2px 4px rgba(15, 23, 42, 0.08) !important; transition: box-shadow 0.15s ease, border-color 0.15s ease; }
.ss-pdd :deep(input:hover) { border-color: #2f6fb3 !important; box-shadow: 0 14px 24px -12px rgba(47, 111, 179, 0.6), 0 2px 4px rgba(15, 23, 42, 0.08) !important; }
.ss-pdd :deep(input:focus) { border-color: #2f6fb3 !important; outline: 2px solid rgba(47, 111, 179, 0.35); outline-offset: 1px; }
.ss-switch { position: relative; margin-left: auto; flex: none; width: 44px; height: 24px; cursor: pointer; }
.ss-switch input { position: absolute; opacity: 0; inset: 0; width: 100%; height: 100%; cursor: pointer; }
.ss-switch span { position: absolute; inset: 0; border-radius: 9999px; background: var(--lg-line); transition: background 0.2s ease; }
.ss-switch span::after { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 9999px; background: #fff; box-shadow: 0 2px 4px rgba(15, 23, 42, 0.3); transition: transform 0.2s ease; }
.ss-switch input:checked + span { background: #059669; }
.ss-switch input:checked + span::after { transform: translateX(20px); }
.ss-switch input:focus-visible + span { outline: 2px solid #4f46e5; outline-offset: 2px; }
.ss-field { display: flex; flex-direction: column; gap: 4px; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--lg-faint); }
.ss-field input, .ss-field textarea, .ss-select { padding: 0.55rem 0.85rem; border-radius: 0.85rem; border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-ink); font-size: 0.9rem; text-transform: none; letter-spacing: 0; font-weight: 600; }
.ss-field input { height: 2.6rem; padding-top: 0; padding-bottom: 0; }
.ss-field small { text-transform: none; letter-spacing: 0; font-weight: 600; }
.ss-checks { display: flex; flex-wrap: wrap; gap: 8px 18px; text-transform: none; letter-spacing: 0; }
.ss-chk { display: inline-flex; align-items: flex-start; gap: 8px; font-size: 0.84rem; font-weight: 700; color: var(--lg-ink); cursor: pointer; text-transform: none; letter-spacing: 0; }
.ss-status { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; margin-bottom: 0.9rem; }
.ss-connect { margin: 0.4rem 0 1rem; padding: 1rem; border: 1px solid var(--lg-line); border-radius: 1rem; background: var(--lg-soft); }
.ss-h3 { display: flex; align-items: center; gap: 8px; margin: 0 0 0.7rem; font-size: 0.95rem; font-weight: 800; color: var(--lg-ink); }
.ss-steps { margin: 0; padding-left: 1.3rem; display: grid; gap: 1rem; font-size: 0.86rem; color: var(--lg-ink); }
.ss-steps li > small { display: block; margin-top: 3px; color: var(--lg-mute); font-size: 0.78rem; line-height: 1.45; }
.ss-copy { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 8px; }
.ss-copy code { flex: 1 1 260px; min-width: 0; padding: 0.5rem 0.7rem; border-radius: 0.7rem; background: var(--lg-card); border: 1px dashed var(--lg-line); font-size: 0.76rem; word-break: break-all; }
.ss-hint { color: #b45309 !important; font-weight: 700; }
.ss-cred { margin: 1rem 0; padding: 0.7rem 0.9rem; border: 1px dashed var(--lg-line); border-radius: 0.9rem; }
.ss-cred summary { cursor: pointer; font-size: 0.82rem; font-weight: 800; color: var(--lg-ink); }
.ss-row { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 0.9rem; }
.ss-seg { display: inline-flex; border: 1px solid var(--lg-line); border-radius: 0.9rem; overflow: hidden; margin-top: 0.7rem; }
.ss-seg button { padding: 0.5rem 1rem; font-size: 0.8rem; font-weight: 700; color: var(--lg-mute); background: var(--lg-soft); border: 0; }
.ss-seg button + button { border-left: 1px solid var(--lg-line); }
.ss-seg button.is-on { background: rgba(47, 111, 179, 0.16); color: #2f6fb3; }
.ss-list { display: grid; gap: 10px; }
.ss-fixed { display: flex; align-items: flex-start; gap: 8px; font-size: 0.8rem; color: var(--lg-mute); padding: 0.6rem 0.8rem; border-radius: 0.8rem; background: var(--lg-soft); }
.ss-rules { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(380px, 100%), 1fr)); gap: 12px; }
.ss-rule { border: 1px solid var(--lg-line); border-radius: 1rem; padding: 0.9rem; display: flex; flex-direction: column; gap: 0.7rem; background: var(--lg-card); }
.ss-rule.is-off { opacity: 0.7; }
.ss-rule-head { display: flex; align-items: flex-start; gap: 10px; }
.ss-rule-head b { display: block; font-size: 0.9rem; color: var(--lg-ink); }
.ss-rule-head small { color: var(--lg-faint); font-size: 0.74rem; }
.ss-rule-body { display: flex; flex-direction: column; gap: 0.6rem; }
.ss-time { max-width: 10rem; }
.ss-chips { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.ss-role { padding: 4px 11px; border-radius: 9999px; border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-mute); font-size: 0.74rem; font-weight: 700; }
.ss-role.is-on { background: rgba(79, 70, 229, 0.14); border-color: rgba(79, 70, 229, 0.5); color: #4338ca; }
html.dark .ss-role.is-on { color: #c7d2fe; }
.ss-x { margin-left: 5px; font-weight: 900; }
.ss-add { height: 2rem; padding: 0 1.6rem 0 0.7rem; border-radius: 9999px; border: 1px dashed var(--lg-line); background-color: transparent; color: var(--lg-mute); font-size: 0.74rem; font-weight: 700; max-width: 100%; }
.ss-foot { display: flex; flex-wrap: wrap; gap: 6px 16px; padding-top: 0.6rem; border-top: 1px dashed var(--lg-line); }
.ss-save { position: sticky; bottom: 12px; z-index: 20; display: flex; flex-wrap: wrap; align-items: flex-end; gap: 0.8rem; box-shadow: 0 -10px 30px -14px rgba(15, 23, 42, 0.4); }
.ss-pw { display: flex; flex-direction: column; gap: 4px; flex: 1 1 260px; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--lg-faint); }
.ss-pw input { height: 2.6rem; padding: 0 0.9rem; border-radius: 0.9rem; border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-ink); font-size: 0.9rem; text-transform: none; letter-spacing: 0; }
.ss-warn { flex-basis: 100%; display: flex; align-items: flex-start; gap: 6px; margin: 0; font-size: 0.78rem; font-weight: 700; color: #b45309; }
.ss-choices { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
.ss-choice { position: relative; display: flex; align-items: center; gap: 14px; padding: 14px 16px; border: 2px solid var(--lg-line); border-radius: 1.3rem; background: var(--lg-soft); text-align: left; cursor: pointer; transition: all 0.15s ease; touch-action: manipulation; }
.ss-choice:hover { border-color: #2f6fb3; }
.ss-choice.is-on { border-color: #059669; background: rgba(5, 150, 105, 0.09); box-shadow: 0 14px 26px -18px rgba(5, 150, 105, 0.8); }
.ss-choice-ico { flex: none; width: 50px; height: 50px; display: grid; place-items: center; border-radius: 1.1rem; color: #fff; }
.ss-choice-ico.is-cloud { background: linear-gradient(145deg, #fb923c, #ea580c); }
.ss-choice-ico.is-drive { background: linear-gradient(145deg, #3b82f6, #1d4ed8); }
.ss-choice-t { display: flex; flex-direction: column; min-width: 0; }
.ss-choice-t b { font-size: 1rem; color: var(--lg-ink); }
.ss-choice-t small { font-size: 0.76rem; color: var(--lg-mute); line-height: 1.35; }
.ss-choice-mark { margin-left: auto; flex: none; width: 24px; height: 24px; display: grid; place-items: center; border-radius: 9999px; background: #cbd5e1; color: #fff; }
.ss-choice.is-on .ss-choice-mark { background: #059669; }
.ss-gd { margin-top: 1rem; padding-top: 1rem; border-top: 1px dashed var(--lg-line); transition: opacity 0.2s ease; }
.ss-gd.is-idle { opacity: 0.72; }
.ss-conn { display: flex; flex-wrap: wrap; align-items: center; gap: 14px; margin-bottom: 1rem; padding: 14px 16px; border-radius: 1.3rem; border: 2px solid var(--lg-line); background: var(--lg-soft); }
.ss-conn-ico { flex: none; width: 52px; height: 52px; display: grid; place-items: center; border-radius: 9999px; color: #fff; background: #94a3b8; }
.ss-conn-t { display: flex; flex-direction: column; gap: 3px; min-width: 0; flex: 1 1 260px; }
.ss-conn-state { font-size: 1.35rem; font-weight: 900; letter-spacing: -0.01em; color: var(--lg-ink); line-height: 1.1; }
.ss-conn-acc { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 8px; font-size: 0.95rem; }
.ss-conn-acc small { font-size: 0.78rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute); }
.ss-conn-acc strong { font-size: 1.1rem; font-weight: 900; color: var(--lg-ink); word-break: break-all; }
.ss-conn-hint { font-size: 0.78rem; color: var(--lg-mute); }
.ss-conn-link { display: inline-flex; align-items: center; gap: 7px; padding: 0.55rem 1rem; border-radius: 9999px; background: #fff; border: 1px solid var(--lg-line); color: #1d4ed8; font-size: 0.82rem; font-weight: 800; text-decoration: none; box-shadow: 0 8px 18px -12px rgba(15, 23, 42, 0.5); }
.ss-conn-link:hover { border-color: #2563eb; }
html.dark .ss-conn-link { background: var(--lg-card); color: #93c5fd; }
.ss-conn.is-ok { border-color: #059669; background: rgba(5, 150, 105, 0.1); box-shadow: 0 16px 28px -22px rgba(5, 150, 105, 0.9); }
.ss-conn.is-ok .ss-conn-ico { background: linear-gradient(145deg, #10b981, #047857); box-shadow: 0 10px 18px -8px rgba(4, 120, 87, 0.8); }
.ss-conn.is-ok .ss-conn-state { color: #047857; }
html.dark .ss-conn.is-ok .ss-conn-state { color: #6ee7b7; }
.ss-conn.is-wait { border-color: #d97706; background: rgba(217, 119, 6, 0.08); }
.ss-conn.is-wait .ss-conn-ico { background: linear-gradient(145deg, #fbbf24, #d97706); }
.ss-conn.is-off .ss-conn-ico { background: linear-gradient(145deg, #94a3b8, #64748b); }
.ss-gd-head { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; margin-bottom: 0.8rem; font-size: 0.95rem; color: var(--lg-ink); }
.ss-ok { flex-basis: 100%; display: flex; align-items: center; gap: 6px; margin: 0; font-size: 0.82rem; font-weight: 700; color: #047857; }
.ss-err { flex-basis: 100%; margin: 0; font-size: 0.8rem; font-weight: 600; color: #be123c; }
.ss-conn-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.ss-conn-link { cursor: pointer; }
.ss-conn-link.is-danger { color: #be123c; }
.ss-conn-link.is-danger:hover { border-color: #be123c; background: rgba(190, 18, 60, 0.07); }
html.dark .ss-conn-link.is-danger { color: #fda4af; }
.ss-clear { margin-bottom: 1rem; padding: 14px 16px; border: 2px solid #be123c; border-radius: 1.2rem; background: rgba(190, 18, 60, 0.07); display: grid; gap: 8px; }
.ss-clear b { display: flex; align-items: center; gap: 8px; color: #be123c; font-size: 1rem; }
.ss-clear p { margin: 0; font-size: 0.82rem; color: var(--lg-ink); line-height: 1.5; }
.ss-clear-row { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px; }
.ss-clear-row .ss-field { flex: 1 1 200px; max-width: 18rem; }
.ss-usage { margin-bottom: 1rem; padding: 14px 16px; border: 1px solid var(--lg-line); border-radius: 1.2rem; background: var(--lg-card); box-shadow: 0 12px 24px -20px rgba(15, 23, 42, 0.6); }
.ss-usage-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.ss-usage-head b { display: flex; align-items: center; gap: 8px; font-size: 0.92rem; color: var(--lg-ink); }
.ss-usage-r { width: 2rem; height: 2rem; display: grid; place-items: center; border-radius: 9999px; border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-mute); cursor: pointer; }
.ss-bar { height: 12px; border-radius: 9999px; background: var(--lg-line); overflow: hidden; margin-bottom: 10px; }
.ss-bar span { display: block; height: 100%; border-radius: 9999px; background: linear-gradient(90deg, #10b981, #059669); transition: width 0.4s ease; }
.ss-bar span.is-hot { background: linear-gradient(90deg, #f59e0b, #be123c); }
.ss-usage-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px; }
.ss-usage-grid > div { display: flex; flex-direction: column; padding: 8px 10px; border-radius: 0.9rem; background: var(--lg-soft); min-width: 0; }
.ss-usage-grid small { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.ss-usage-grid strong { font-size: 1.05rem; font-weight: 900; color: var(--lg-ink); overflow-wrap: anywhere; }
.ss-usage-grid em { font-style: normal; font-size: 0.72rem; color: var(--lg-mute); }
.ss-retain { display: grid; gap: 6px; padding: 0.8rem 0.9rem; border: 1px solid var(--lg-line); border-radius: 1rem; background: var(--lg-soft); }
.ss-retain-t { display: flex; align-items: center; gap: 8px; font-size: 0.9rem; color: var(--lg-ink); }
.ss-retain > small { font-size: 0.76rem; color: var(--lg-mute); line-height: 1.45; }
.ss-retain-opts { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px; }
.ss-retain-opt { align-items: center; padding: 0.45rem 0.9rem; border: 1px solid var(--lg-line); border-radius: 9999px; background: var(--lg-card); }
.ss-retain-opt.is-on { border-color: #059669; background: rgba(5, 150, 105, 0.1); }
@media (max-width: 640px) {
  .ss-conn { padding: 12px; gap: 10px; }
  .ss-conn-ico { width: 44px; height: 44px; }
  .ss-conn-state { font-size: 1.15rem; }
  .ss-conn-actions { width: 100%; }
  .ss-conn-actions .ss-conn-link { flex: 1 1 100%; justify-content: center; }
  .ss-clear-row .ss-field { max-width: none; flex-basis: 100%; }
  .ss-clear-row .lg-btn { flex: 1 1 100%; justify-content: center; }
  .ss-row .lg-btn { flex: 1 1 100%; justify-content: center; }
  .ss-choice { padding: 12px; gap: 10px; }
  .ss-choice-ico { width: 42px; height: 42px; }
  .ss-retain-opts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .ss-retain-opt { border-radius: 0.9rem; }
  .ss-connect { padding: 0.75rem; }
  .ss-steps { padding-left: 1.1rem; }
  .ss-save { bottom: 8px; padding: 0.7rem; }
  .ss-save .lg-btn { flex: 1 1 100%; justify-content: center; }
  .ss-usage-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>

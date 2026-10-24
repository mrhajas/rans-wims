<template>
  <admin-layout :title="$t('WIMSChat Settings')">
    <div class="lg-page wcs px-4 md:px-0">
      <page-header icon="cog" :title="$t('WIMSChat Settings')" :subtitle="$t('File limits, daily quotas, file types, reactions, presence and notifications. Every change is logged.')">
        <Link :href="route('wimschat.index')" class="lg-btn lg-btn-soft"><icons name="chat" class="w-4 h-4" />{{ $t('Open WIMSChat') }}</Link>
        <Link v-if="$super" :href="route('system-features.index')" class="lg-btn lg-btn-soft"><icons name="toggle" class="w-4 h-4" />{{ $t('Global Feature Control') }}</Link>
      </page-header>

      <!-- Files -->
      <section class="lg-card wcs-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="clip" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Attachments') }}</h2><p class="lg-card-sub">{{ $t('Size limits are enforced on the server for every upload, whatever the browser sends.') }}</p></div>
        </header>
        <div class="lg-fields">
          <label class="wcs-field"><span>{{ $t('Maximum file size (MB)') }}</span><input v-model.number="s.max_file_mb" type="number" min="1" :max="server_max_mb" /><small>{{ $t('Default 3 MB. The server allows up to :n MB per upload.', { n: server_max_mb }) }}</small></label>
          <label class="wcs-field"><span>{{ $t('Files per message') }}</span><input v-model.number="s.max_files_per_message" type="number" min="1" max="20" /></label>
          <label class="wcs-field"><span>{{ $t('Keep files, images & voice notes for') }}</span>
            <css-select v-model.number="s.attachment_retention_days" class="wcs-sel"><option v-for="o in retention" :key="o.v" :value="o.v">{{ o.label }}</option></css-select>
            <small>{{ $t('Older files are erased from the server every night. The conversation still shows that a file was shared.') }}</small></label>
          <label class="wcs-field lg-span-all"><span>{{ $t('Blocked extensions (never allowed, whatever the file types below say)') }}</span><input v-model="blocked" type="text" placeholder="exe, bat, php …" /></label>
        </div>
        <div class="wcs-list">
          <label class="wcs-chk"><input v-model="s.image_compress" type="checkbox" class="sdy-cb" />{{ $t('Shrink big images on upload') }}</label>
          <label v-if="s.image_compress" class="wcs-field wcs-inline"><span>{{ $t('Longest side (px)') }}</span><input v-model.number="s.image_max_px" type="number" min="640" max="6000" step="64" /></label>
        </div>
      </section>

      <!-- File types -->
      <section class="lg-card wcs-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-teal"><icons name="doc" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Allowed file types') }}</h2><p class="lg-card-sub">{{ $t('Each category has its own extensions, accepted real file types (MIME), size limit, preview and download switch.') }}</p></div>
        </header>
        <div class="wcs-cats">
          <div v-for="(p, key) in s.file_policies" :key="key" class="wcs-cat" :class="{ 'is-off': !p.enabled }">
            <div class="wcs-cat-head">
              <label class="wcs-switch"><input v-model="p.enabled" type="checkbox" class="sdy-cb" /><span></span></label>
              <b>{{ p.label }}</b>
            </div>
            <div class="lg-fields">
              <label class="wcs-field"><span>{{ $t('Extensions') }}</span><input :value="(p.extensions || []).join(', ')" type="text" @change="p.extensions = list($event.target.value)" /></label>
              <label class="wcs-field"><span>{{ $t('Max size (MB, blank = general limit)') }}</span><input v-model.number="p.max_mb" type="number" min="1" :max="server_max_mb" /></label>
              <label class="wcs-field lg-span-all"><span>{{ $t('Accepted real file types (MIME)') }}</span><textarea :value="(p.mimes || []).join(', ')" rows="2" @change="p.mimes = list($event.target.value)"></textarea></label>
            </div>
            <div class="wcs-list is-row">
              <label class="wcs-chk"><input v-model="p.preview" type="checkbox" class="sdy-cb" />{{ $t('Allow preview in WIMSChat') }}</label>
              <label class="wcs-chk"><input v-model="p.download" type="checkbox" class="sdy-cb" />{{ $t('Allow download') }}</label>
            </div>
          </div>
        </div>
      </section>

      <!-- Quotas -->
      <section class="lg-card wcs-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="chart-bar" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Daily upload quota') }}</h2><p class="lg-card-sub">{{ $t('Total file size one person may upload per day. A person with several roles gets the largest of their quotas. 0 switches uploads off.') }}</p></div>
        </header>
        <div class="lg-fields">
          <label class="wcs-field wcs-shadow"><span>{{ $t('Default quota (MB per day)') }}</span><input v-model.number="s.daily_quota_default_mb" type="number" min="0" /><small>{{ $t('Used for any role without its own value below.') }}</small></label>
        </div>
        <div class="lg-table-wrap mt-3">
          <table class="lg-table lg-responsive">
            <thead><tr><th>{{ $t('Role') }}</th><th>{{ $t('Quota (MB per day)') }}</th></tr></thead>
            <tbody>
              <tr v-for="r in quotaRows" :key="r.name">
                <td :data-label="$t('Role')"><span class="lg-main">{{ r.name }}</span></td>
                <td :data-label="$t('Quota')"><input v-model="r.mb" type="number" min="0" class="wcs-num" :placeholder="$t('default')" :aria-label="r.name" /></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Messages -->
      <section class="lg-card wcs-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="chat" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Messages & groups') }}</h2></div>
        </header>
        <div class="lg-fields">
          <label class="wcs-field"><span>{{ $t('Keep chat messages for') }}</span>
            <css-select v-model.number="s.message_retention_days" class="wcs-sel"><option v-for="o in retention" :key="o.v" :value="o.v">{{ o.label }}</option></css-select>
            <small>{{ $t('Messages older than this are permanently deleted every night, with their reactions. “Forever” keeps everything.') }}</small></label>
          <label class="wcs-field"><span>{{ $t('Edit window (minutes)') }}</span><input v-model.number="s.edit_window_minutes" type="number" min="0" /><small>{{ $t('0 = no limit.') }}</small></label>
          <label class="wcs-field"><span>{{ $t('Delete window (minutes)') }}</span><input v-model.number="s.delete_window_minutes" type="number" min="0" /><small>{{ $t('0 = no limit. Deleted messages stay in the audit trail.') }}</small></label>
          <label class="wcs-field"><span>{{ $t('Maximum message length (characters)') }}</span><input v-model.number="s.max_message_chars" type="number" min="50" max="20000" /></label>
          <label class="wcs-field"><span>{{ $t('Maximum group members') }}</span><input v-model.number="s.group_max_members" type="number" min="2" max="2000" /></label>
          <label class="wcs-field"><span>{{ $t('Nudge cooldown (seconds)') }}</span><input v-model.number="s.nudge_cooldown_seconds" type="number" min="0" /></label>
          <label class="wcs-field"><span>{{ $t('Messages loaded at a time') }}</span><input v-model.number="s.page_size" type="number" min="10" max="100" /></label>
          <label class="wcs-field"><span>{{ $t('Search needs at least (characters)') }}</span><input v-model.number="s.search_min_chars" type="number" min="1" max="10" /></label>

          <label class="wcs-field"><span>{{ $t('List preview length (characters)') }}</span><input v-model.number="s.message_preview_chars" type="number" min="20" max="300" /></label>
        </div>
        <div class="wcs-list">
          <label class="wcs-chk"><input v-model="s.group_invite_required" type="checkbox" class="sdy-cb" />{{ $t('People added to a group must accept an invitation before they can enter it') }}</label>
        </div>
      </section>

      <!-- Voice notes -->
      <section class="lg-card wcs-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-rose"><icons name="microphone" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Voice notes') }}</h2><p class="lg-card-sub">{{ $t('People record with the microphone button. Needs the attach-files permission and the Voice notes feature switch.') }}</p></div>
          <label class="wcs-switch lg-tip" :data-tip="s.voice_enabled ? $t('On') : $t('Off')" style="margin-left:auto"><input v-model="s.voice_enabled" type="checkbox" class="sdy-cb" /><span></span></label>
        </header>
        <div class="lg-fields">
          <label class="wcs-field"><span>{{ $t('Longest voice note (seconds)') }}</span><input v-model.number="s.voice_max_seconds" type="number" min="5" max="300" /><small>{{ $t('Default 30. Recording stops by itself at this length, and the server refuses anything longer.') }}</small></label>
          <label class="wcs-field"><span>{{ $t('Size ceiling (kbps)') }}</span><input v-model.number="s.voice_max_kbps" type="number" min="16" max="512" /><small>{{ $t('A recording bigger than length × this speed is refused. Leave at 192 unless voice notes fail to upload.') }}</small></label>
        </div>
      </section>

      <!-- Look & feel -->
      <section class="lg-card wcs-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="sparkles" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Chat colours') }}</h2><p class="lg-card-sub">{{ $t('The colour themes people can choose for their own chat. At least one must stay on.') }}</p></div>
        </header>
        <div class="wcs-themes">
          <label v-for="t in allThemes" :key="t" class="wcs-theme" :class="'t-' + t"><input v-model="s.themes" type="checkbox" class="sdy-cb" :value="t" :disabled="s.themes.length === 1 && s.themes.includes(t)" /><i></i>{{ t }}</label>
        </div>
      </section>

      <!-- Reactions -->
      <section class="lg-card wcs-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-rose"><icons name="smile" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Reactions') }}</h2><p class="lg-card-sub">{{ $t('The quick reactions people can add to a message.') }}</p></div>
        </header>
        <div class="wcs-react-list">
          <span v-for="(e, i) in s.reactions" :key="e + i" class="wcs-react">{{ e }}<button type="button" :aria-label="$t('Remove')" @click="s.reactions.splice(i, 1)"><icons name="cross" class="w-3 h-3" /></button></span>
          <form class="wcs-react-add" @submit.prevent="addReaction"><input v-model="newReaction" maxlength="8" :placeholder="$t('Add emoji')" :aria-label="$t('Add emoji')" /><button type="submit" class="lg-btn lg-btn-soft"><icons name="plus" class="w-4 h-4" />{{ $t('Add') }}</button></form>
        </div>
      </section>

      <!-- Presence -->
      <section class="lg-card wcs-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="wifi" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Presence & status') }}</h2><p class="lg-card-sub">{{ $t('Online means seen in WIMS recently; Away means signed in but inactive; Offline is everything else.') }}</p></div>
        </header>
        <div class="lg-fields">
          <label class="wcs-field"><span>{{ $t('Active within (seconds)') }}</span><input v-model.number="s.active_within_seconds" type="number" min="60" max="3600" /></label>
          <label class="wcs-field"><span>{{ $t('Away until (minutes)') }}</span><input v-model.number="s.away_timeout_minutes" type="number" min="5" max="1440" /></label>
          <label class="wcs-field"><span>{{ $t('Typing indicator lasts (seconds)') }}</span><input v-model.number="s.typing_ttl_seconds" type="number" min="3" max="60" /></label>
        </div>
        <div class="wcs-list">
          <label class="wcs-chk"><input v-model="s.show_last_seen" type="checkbox" class="sdy-cb" />{{ $t('Show “last seen”') }}</label>
          <label class="wcs-chk"><input v-model="s.allow_manual_status" type="checkbox" class="sdy-cb" />{{ $t('Let people choose their own status') }}</label>
          <label class="wcs-chk"><input v-model="s.read_receipts" type="checkbox" class="sdy-cb" />{{ $t('Show read receipts (people can still switch theirs off)') }}</label>
        </div>
        <div class="lg-table-wrap mt-3">
          <table class="lg-table lg-responsive">
            <thead><tr><th>{{ $t('Key') }}</th><th>{{ $t('Label') }}</th><th>{{ $t('Colour') }}</th><th>{{ $t('Others see it as') }}</th><th>{{ $t('Selectable') }}</th><th>{{ $t('Quiet') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="(st, i) in s.statuses" :key="st.key + i">
                <td :data-label="$t('Key')"><code>{{ st.key }}</code></td>
                <td :data-label="$t('Label')"><input v-model="st.label" maxlength="40" class="wcs-in" :aria-label="$t('Label')" /></td>
                <td :data-label="$t('Colour')"><input v-model="st.color" type="color" class="wcs-color" :aria-label="$t('Colour')" /></td>
                <td :data-label="$t('Others see it as')"><css-select v-model="st.appears_as" class="wcs-in"><option v-for="a in appears" :key="a" :value="a">{{ a }}</option></css-select></td>
                <td :data-label="$t('Selectable')"><input v-model="st.selectable" type="checkbox" class="sdy-cb" :disabled="core.includes(st.key) && st.key === 'offline'" /></td>
                <td :data-label="$t('Quiet')"><input v-model="st.quiet" type="checkbox" class="sdy-cb" /></td>
                <td><button v-if="!core.includes(st.key)" type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="s.statuses.splice(i, 1)"><icons name="trash" class="w-4 h-4" /></button></td>
              </tr>
            </tbody>
          </table>
        </div>
        <form class="wcs-addstatus" @submit.prevent="addStatus">
          <input v-model="ns.label" :placeholder="$t('New status name')" maxlength="40" :aria-label="$t('New status name')" />
          <input v-model="ns.color" type="color" class="wcs-color" :aria-label="$t('Colour')" />
          <button type="submit" class="lg-btn lg-btn-soft" :disabled="!ns.label.trim()"><icons name="plus" class="w-4 h-4" />{{ $t('Add status') }}</button>
        </form>
      </section>

      <!-- Notifications -->
      <section class="lg-card wcs-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="bell" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Notifications') }}</h2><p class="lg-card-sub">{{ $t('Which chat events notify people (new message while away, mention, reply, nudge, group changes), on which channels (bell, email, SMS, WhatsApp) and with which words is set on the WIMSChat Workflow page. Plain messages only notify people who are away, and are folded into one line per conversation.') }}</p></div>
          <Link v-if="canOpenWorkflow" :href="route('wimschat.workflow')" class="lg-btn lg-btn-soft ml-auto"><icons name="bell" class="w-4 h-4" />{{ $t('Open the WIMSChat Workflow') }}</Link>
        </header>
      </section>

      <!-- Real-time -->
      <section class="lg-card wcs-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="refresh" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Refresh speed') }}</h2><p class="lg-card-sub">{{ $t('WIMSChat checks for news at these intervals (paused while the tab is hidden or WIMS is offline). Faster = more server load.') }}</p></div>
        </header>
        <div class="lg-fields">
          <label class="wcs-field"><span>{{ $t('While chat is open (ms)') }}</span><input v-model.number="s.poll_open_ms" type="number" min="1500" max="30000" step="500" /></label>
          <label class="wcs-field"><span>{{ $t('While chat is closed (ms)') }}</span><input v-model.number="s.poll_idle_ms" type="number" min="10000" max="300000" step="1000" /></label>
        </div>
      </section>

      <div class="wcs-save lg-card">
        <label class="wcs-pw"><span>{{ $t('Confirm with your password to save') }}</span><input ref="pw" v-model="password" type="password" autocomplete="current-password" :placeholder="$t('Your password')" @keydown.enter.prevent="save" /></label>
        <p v-if="error" class="wcs-err">{{ error }}</p>
        <p v-if="saved" class="wcs-ok"><icons name="tick" class="w-4 h-4" />{{ saved }}</p>
        <button type="button" class="lg-btn lg-btn-primary" :disabled="busy" @click="save"><icons name="tick" class="w-4 h-4" />{{ busy ? $t('Saving…') : $t('Save Settings') }}</button>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, PageHeader, Link },
  props: { wc_settings: Object, server_max_mb: Number, roles: Array, user_defaults: Object, canOpenWorkflow: Boolean },
  data() {
    const s = JSON.parse(JSON.stringify(this.wc_settings));
    Object.values(s.file_policies).forEach(p => { if (p.max_mb === undefined) p.max_mb = null; });
    return {
      s, blocked: (s.blocked_extensions || []).join(', '), newReaction: '', password: '', error: null, saved: null, busy: false,
      quotaRows: this.roles.map(r => ({ name: r, mb: (s.daily_quota_roles || {})[r] ?? '' })),
      allThemes: ['ocean', 'teal', 'violet', 'rose', 'sunset', 'forest'], ns: { label: '', color: '#6366f1' }, appears: ['online', 'away', 'busy', 'dnd', 'offline'], core: ['online', 'away', 'offline'],
    };
  },
  computed: {
    retention() {
      return [{ v: 30, label: this.$t('30 days') }, { v: 60, label: this.$t('60 days') }, { v: 90, label: this.$t('90 days (default)') }, { v: 180, label: this.$t('180 days') }, { v: 365, label: this.$t('1 year') }, { v: 0, label: this.$t('Forever') }];
    },
  },
  methods: {
    list(v) {
      return String(v || '').split(/[\s,;]+/).map(x => x.trim().replace(/^\./, '').toLowerCase()).filter(Boolean);
    },
    addReaction() {
      const e = this.newReaction.trim();
      if (e && !this.s.reactions.includes(e) && this.s.reactions.length < 24) this.s.reactions.push(e);
      this.newReaction = '';
    },
    addStatus() {
      const key = this.ns.label.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 20) || 'status_' + (this.s.statuses.length + 1);
      if (this.s.statuses.some(x => x.key === key)) { this.error = this.$t('A status with that name already exists.'); return; }
      this.s.statuses.splice(this.s.statuses.length - 1, 0, { key, label: this.ns.label.trim(), color: this.ns.color, selectable: true, quiet: false, appears_as: 'busy' });
      this.ns = { label: '', color: '#6366f1' };
    },
    async save() {
      if (!this.password) {
        this.error = this.$t('Type your password in the box first, it confirms that you are the one saving these settings.');
        this.$nextTick(() => this.$refs.pw && this.$refs.pw.focus());
        return;
      }
      this.busy = true;
      this.error = null;
      this.saved = null;
      const quotas = {};
      this.quotaRows.forEach(r => { if (r.mb !== '' && r.mb !== null) quotas[r.name] = Number(r.mb); });
      const body = { password: this.password, settings: { ...this.s, blocked_extensions: this.list(this.blocked), daily_quota_roles: quotas } };
      body.settings.file_policies = Object.fromEntries(Object.entries(this.s.file_policies).map(([k, p]) => [k, { ...p, max_mb: p.max_mb ? Number(p.max_mb) : null }]));
      try {
        await window.axios.put(this.route('wimschat.settings.update'), body);
        this.saved = this.$t('Everything on this page has been saved.');
        this.password = '';
        setTimeout(() => (this.saved = null), 3500);
      } catch (e) {
        const errs = e.response && e.response.data && e.response.data.errors;
        this.error = (errs && Object.values(errs)[0][0]) || (e.response && e.response.data && e.response.data.message) || this.$t('Unable to save.');
      } finally {
        this.busy = false;
      }
    },
  },
};
</script>

<style scoped>
.wcs-card { margin-bottom: 1rem; }
/* wide enough columns that every label fits on ONE line */
.wcs .lg-fields { grid-template-columns: repeat(auto-fit, minmax(min(300px, 100%), 1fr)); }
.wcs-field { display: flex; flex-direction: column; gap: 4px; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--lg-faint); }
.wcs-field input, .wcs-field textarea, .wcs-in { padding: 0.55rem 0.85rem; border-radius: 0.85rem; border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-ink); font-size: 0.9rem; text-transform: none; letter-spacing: 0; font-weight: 600; min-width: 0; }
.wcs-field input { height: 2.6rem; padding-top: 0; padding-bottom: 0; }
.wcs-field > span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
.wcs-field small, .wcs-sub { text-transform: none; letter-spacing: 0; font-weight: 600; color: var(--lg-mute); display: block; }
.wcs-inline { max-width: 14rem; margin-top: 0.4rem; }
.wcs-list { display: grid; gap: 10px; margin-top: 0.8rem; }
.wcs-list.is-row { display: flex; flex-wrap: wrap; gap: 8px 22px; }
.wcs-chk { display: inline-flex; align-items: flex-start; gap: 8px; font-size: 0.84rem; font-weight: 700; color: var(--lg-ink); cursor: pointer; }
.wcs-cats { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(420px, 100%), 1fr)); gap: 12px; }
.wcs-cat { border: 1px solid var(--lg-line); border-radius: 1rem; padding: 0.9rem; background: var(--lg-card); }
.wcs-cat.is-off { opacity: 0.65; }
.wcs-cat-head { display: flex; align-items: center; gap: 10px; margin-bottom: 0.6rem; }
.wcs-switch { position: relative; flex: none; width: 44px; height: 24px; cursor: pointer; }
.wcs-switch input { position: absolute; opacity: 0; inset: 0; width: 100%; height: 100%; cursor: pointer; }
.wcs-switch span { position: absolute; inset: 0; border-radius: 9999px; background: var(--lg-line); transition: background 0.2s; }
.wcs-switch span::after { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 9999px; background: #fff; box-shadow: 0 2px 4px rgba(15, 23, 42, 0.3); transition: transform 0.2s; }
.wcs-switch input:checked + span { background: #059669; }
.wcs-switch input:checked + span::after { transform: translateX(20px); }
.wcs-num { width: 9rem; max-width: 100%; height: 2.4rem; padding: 0 0.7rem; border: 1px solid var(--lg-line); border-radius: 0.8rem; background: var(--lg-card); color: var(--lg-ink); font-weight: 700; box-shadow: 0 10px 20px -12px rgba(15, 23, 42, 0.55), 0 2px 4px rgba(15, 23, 42, 0.08); transition: box-shadow 0.15s ease, border-color 0.15s ease; }
.wcs-num:hover, .wcs-shadow input:hover { border-color: #2f6fb3; box-shadow: 0 14px 24px -12px rgba(47, 111, 179, 0.6), 0 2px 4px rgba(15, 23, 42, 0.08); }
.wcs-num:focus, .wcs-shadow input:focus { outline: none; border-color: #2f6fb3; box-shadow: 0 0 0 3px rgba(47, 111, 179, 0.2), 0 14px 24px -12px rgba(47, 111, 179, 0.6); }
.wcs-shadow input { background: var(--lg-card); box-shadow: 0 10px 20px -12px rgba(15, 23, 42, 0.55), 0 2px 4px rgba(15, 23, 42, 0.08); transition: box-shadow 0.15s ease, border-color 0.15s ease; }
.wcs-in { height: 2.4rem; width: 100%; max-width: 14rem; }
.wcs-color { width: 2.6rem; height: 2.2rem; padding: 2px; border: 1px solid var(--lg-line); border-radius: 0.6rem; background: var(--lg-soft); cursor: pointer; }
.wcs-sel { height: 2.6rem; padding: 0 0.85rem; border-radius: 0.85rem; border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-ink); font-weight: 700; }
.wcs-themes { display: flex; flex-wrap: wrap; gap: 10px; }
.wcs-theme { display: inline-flex; align-items: center; gap: 8px; padding: 0.45rem 0.9rem 0.45rem 0.6rem; border: 1px solid var(--lg-line); border-radius: 999px; background: var(--lg-soft); font-weight: 700; text-transform: capitalize; cursor: pointer; }
.wcs-theme i { width: 18px; height: 18px; border-radius: 50%; background: linear-gradient(135deg, var(--c1), var(--c2)); box-shadow: 0 2px 6px -1px var(--c2); }
.wcs-theme.t-ocean { --c1: #3b82f6; --c2: #1c3f66; } .wcs-theme.t-teal { --c1: #2dd4bf; --c2: #0f766e; } .wcs-theme.t-violet { --c1: #a78bfa; --c2: #6d28d9; }
.wcs-theme.t-rose { --c1: #fb7185; --c2: #be123c; } .wcs-theme.t-sunset { --c1: #fbbf24; --c2: #ea580c; } .wcs-theme.t-forest { --c1: #4ade80; --c2: #15803d; }
.wcs-react-list { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.wcs-react { display: inline-flex; align-items: center; gap: 6px; padding: 4px 6px 4px 12px; border: 1px solid var(--lg-line); border-radius: 999px; background: var(--lg-soft); font-size: 1.2rem; }
.wcs-react button { display: grid; place-items: center; width: 22px; height: 22px; border: 0; border-radius: 50%; background: transparent; color: var(--lg-mute); }
.wcs-react-add { display: inline-flex; gap: 6px; }
.wcs-react-add input, .wcs-addstatus input:not([type='color']) { height: 2.4rem; padding: 0 0.8rem; border: 1px solid var(--lg-line); border-radius: 0.8rem; background: var(--lg-soft); color: var(--lg-ink); min-width: 0; }
.wcs-addstatus { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 0.8rem; }
.wcs-save { position: sticky; bottom: 12px; z-index: 20; display: flex; flex-wrap: wrap; align-items: flex-end; gap: 0.8rem; box-shadow: 0 -10px 30px -14px rgba(15, 23, 42, 0.4); }
.wcs-pw { display: flex; flex-direction: column; gap: 4px; flex: 1 1 260px; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--lg-faint); }
.wcs-pw input { height: 2.6rem; padding: 0 0.9rem; border-radius: 0.9rem; border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-ink); font-size: 0.9rem; text-transform: none; letter-spacing: 0; }
.wcs-err { flex-basis: 100%; margin: 0; font-size: 0.8rem; font-weight: 600; color: #be123c; }
.wcs-ok { flex-basis: 100%; display: flex; align-items: center; gap: 6px; margin: 0; font-size: 0.82rem; font-weight: 700; color: #047857; }
@media (max-width: 640px) { .wcs-save .lg-btn { flex: 1 1 100%; justify-content: center; } .wcs-in { max-width: none; } .wcs-num { width: 100%; } .wcs-save { bottom: 8px; padding: 0.7rem; } }
</style>

<template>
  <admin-layout :title="$t('Material Request Alerts')">
    <div class="lg-page mrw-page px-4 md:px-0">
      <page-header
        icon="adjustments"
        :title="$t('Material Request Alerts')"
        :subtitle="$t('Decide who gets notified at each step of a material request, and the SMS and email messages they receive. New roles show up automatically.')"
      />

      <div class="lg-note lg-note-blue mb-4">
        <icons name="info" class="w-4 h-4" />
        <p>
          {{ $t('This page only decides who is told. Who may approve, procure, issue, confirm delivery, clear, print or download a request, and who sees which requests, is set per role on the Roles screen (Roles, then the Material Requests permissions).') }}
        </p>
      </div>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="lock-closed" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Clear / Complete: delivery check') }}</h2>
            <p class="lg-card-sub">{{ $t('What should happen when the request is cleared while some items are not Fully Delivered on site yet?') }}</p>
          </div>
        </header>
        <div class="mrw-modes" role="radiogroup" :aria-label="$t('Delivery check when clearing')">
          <label v-for="m in clearModes" :key="m.value" class="mrw-mode" :class="{ 'is-on': localClearMode === m.value }">
            <input v-model="localClearMode" type="radio" name="clear-mode" :value="m.value" class="sr-only" />
            <span class="mrw-mode-dot"></span>
            <span class="mrw-mode-text"><b>{{ m.label }}</b><small>{{ m.hint }}</small></span>
          </label>
        </div>
      </section>

      <nav class="mrw-tabs" role="tablist">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          type="button"
          role="tab"
          class="mrw-tab"
          :class="{ 'is-active': activeTab === tab.key }"
          :aria-selected="activeTab === tab.key"
          @click="setTab(tab.key)"
        >
          <icons :name="tab.icon" class="w-4 h-4" />
          <span>{{ tab.label }}</span>
          <span v-if="tab.count !== null" class="mrw-tab-count">{{ tab.count }}</span>
        </button>
      </nav>

      <!-- ============ WHO GETS NOTIFIED ============ -->
      <div v-show="activeTab === 'notifications'">
        <div class="lg-note lg-note-blue mb-4">
          <icons name="info" class="w-4 h-4" />
          <p>{{ $t('Whoever holds the next step\'s permission on the Roles screen (for example Procurement: Set Procedures after a request is approved) is always told it is their turn. That follows the permissions, so it is not chosen here.') }}</p>
        </div>

        <section v-for="rule in localNotifications" :key="rule.event_key" class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-violet"><icons :name="notificationIcon(rule.event_key)" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t(rule.label) }}</h2>
              <p class="lg-card-sub">{{ $t(rule.description) }}</p>
            </div>
          </header>

          <div class="mrw-block">
            <div class="mrw-label">{{ $t('Roles') }}</div>
            <div v-if="roles.length || rule.role_names.length" class="mrw-toggles">
              <button
                v-for="role in roles"
                :key="role"
                type="button"
                class="mrw-toggle"
                :class="{ 'is-on': rule.role_names.includes(role) }"
                :aria-pressed="rule.role_names.includes(role)"
                @click="toggleIn(rule.role_names, role)"
              >
                <icons v-if="rule.role_names.includes(role)" name="tick" class="w-3.5 h-3.5" />
                {{ role }}
              </button>
              <button
                v-for="role in staleRoles(rule.role_names)"
                :key="'stale-' + role"
                type="button"
                class="mrw-toggle is-stale lg-tip"
                :data-tip="$t('This role no longer exists. It is ignored. Click to remove it.')"
                @click="toggleIn(rule.role_names, role)"
              >
                <icons name="exclamation" class="w-3.5 h-3.5" />
                {{ role }} <em>({{ $t('deleted role') }})</em>
              </button>
            </div>
          </div>

          <div class="mrw-block">
            <div class="mrw-label">{{ $t('Specific Users') }}</div>
            <p class="mrw-muted">{{ $t('Optionally notifies a person directly, regardless of their role.') }}</p>
            <div class="mrw-adder">
              <auto-complete
                :id="`mrw-notify-user-${rule.event_key}`"
                v-model="rule._pendingUserId"
                :clearable="true"
                :suggestions="availableUsersFor(rule).map(u => ({ label: u.name, value: u.id }))"
                :placeholder="$t('Search a user…')"
                class="flex-1"
              />
              <button type="button" class="lg-btn lg-btn-primary lg-tip" :data-tip="$t('Add this user')" :disabled="!rule._pendingUserId" @click="addUser(rule)">
                <icons name="plus" class="w-4 h-4" />{{ $t('Add') }}
              </button>
            </div>
            <div v-if="rule.user_ids.length" class="mrw-chips">
              <span v-for="uid in rule.user_ids" :key="uid" class="lg-chip lg-chip-indigo">
                {{ userName(uid) }}
                <button type="button" class="mrw-chip-x" :aria-label="$t('Remove')" @click="removeUser(rule, uid)"><icons name="cross" class="w-3 h-3" /></button>
              </span>
            </div>
          </div>

          <label class="mrw-switch">
            <check-box v-model:checked="rule.notify_requester" />
            <span>{{ $t('Notify the Requester') }}</span>
          </label>
          <label class="mrw-switch">
            <check-box v-model:checked="rule.notify_site_engineers" />
            <span>{{ $t('Notify the Site Engineers assigned to the request\'s project / warehouse') }}</span>
          </label>
        </section>
      </div>

      <!-- ============ SMS & EMAIL MESSAGES ============ -->
      <div v-show="activeTab === 'messages'">
        <div class="lg-note lg-note-blue mb-4">
          <icons name="info" class="w-4 h-4" />
          <p>
            {{ $t('For each step switch SMS and / or Email on, then edit the text. Click a tag in the box on the right to drop it into the field you are typing in. The people who receive it are set in the Who Gets Notified tab; SMS needs a phone number on their WIMS Connect profile. SMS never carries a link.') }}
          </p>
        </div>

        <div class="mrw-msg-layout">
          <div class="mrw-msg-main">
            <section v-for="rule in localNotifications" :key="'msg-' + rule.event_key" class="lg-card">
              <header class="lg-card-head mrw-msg-head">
                <span class="lg-card-ico lg-ico-violet"><icons :name="notificationIcon(rule.event_key)" class="w-5 h-5" /></span>
                <div class="min-w-0 flex-1">
                  <h2 class="lg-card-title">{{ $t(rule.label) }}</h2>
                  <p class="lg-card-sub">{{ audienceSummary(rule) }}</p>
                </div>
                <div class="mrw-channels">
                  <label class="mrw-chan" :class="{ 'is-on': rule.sms_enabled }">
                    <check-box v-model:checked="rule.sms_enabled" />
                    <icons name="sms" class="w-4 h-4" /><span>{{ $t('SMS') }}</span>
                  </label>
                  <label class="mrw-chan" :class="{ 'is-on': rule.email_enabled }">
                    <check-box v-model:checked="rule.email_enabled" />
                    <icons name="email" class="w-4 h-4" /><span>{{ $t('Email') }}</span>
                  </label>
                  <button type="button" class="lg-btn lg-btn-soft mrw-edit-btn" :aria-expanded="!!openEvents[rule.event_key]" @click="openEvents[rule.event_key] = !openEvents[rule.event_key]">
                    <icons name="edit" class="w-4 h-4" />{{ openEvents[rule.event_key] ? $t('Hide text') : $t('Edit text') }}
                  </button>
                </div>
              </header>

              <div v-if="openEvents[rule.event_key]" class="mrw-msg-body">
                <!-- SMS -->
                <div class="mrw-msg-col" :class="{ 'is-off': !rule.sms_enabled }">
                  <div class="mrw-label"><icons name="sms" class="w-3.5 h-3.5" /> {{ $t('SMS text') }}
                    <span v-if="!rule.sms_enabled" class="mrw-muted">({{ $t('switched off') }})</span>
                  </div>
                  <textarea
                    v-model="rule.sms_template"
                    rows="6"
                    maxlength="1000"
                    class="mrw-area"
                    :aria-label="$t('SMS text')"
                    @focus="setTarget(rule, 'sms_template', $event)"
                  ></textarea>
                  <div class="mrw-count">{{ smsStats(rule).chars }} {{ $t('characters') }} · ≈ {{ smsStats(rule).parts }} {{ $t('SMS') }}<span v-if="smsStats(rule).unicode"> · {{ $t('emoji / special characters make each SMS shorter') }}</span></div>
                  <div class="mrw-preview mrw-preview-sms"><div class="mrw-bubble">{{ preview(rule.sms_template, true) }}</div></div>
                  <button type="button" class="mrw-link" @click="resetField(rule, 'sms_template')">{{ $t('Reset to default text') }}</button>
                </div>

                <!-- Email -->
                <div class="mrw-msg-col" :class="{ 'is-off': !rule.email_enabled }">
                  <div class="mrw-label"><icons name="email" class="w-3.5 h-3.5" /> {{ $t('Email') }}
                    <span v-if="!rule.email_enabled" class="mrw-muted">({{ $t('switched off') }})</span>
                  </div>
                  <label class="mrw-sublabel">{{ $t('Subject') }}</label>
                  <input v-model="rule.email_subject" type="text" maxlength="255" class="mrw-input" :aria-label="$t('Email subject')" @focus="setTarget(rule, 'email_subject', $event)" />
                  <label class="mrw-sublabel">{{ $t('Message') }}</label>
                  <textarea v-model="rule.email_body" rows="6" maxlength="5000" class="mrw-area" :aria-label="$t('Email message')" @focus="setTarget(rule, 'email_body', $event)"></textarea>
                  <div class="mrw-switches mrw-switches-tight">
                    <label class="mrw-switch"><check-box v-model:checked="rule.email_show_details" /><span>{{ $t('Add the facts table (project, who did what and when)') }}</span></label>
                    <label class="mrw-switch"><check-box v-model:checked="rule.email_show_items" /><span>{{ $t('Add the item list') }}</span></label>
                  </div>
                  <div class="mrw-preview mrw-preview-mail">
                    <div class="mrw-mail-head">{{ preview(rule.email_subject, false, true) }}</div>
                    <div class="mrw-mail-body">{{ preview(rule.email_body, false) }}</div>
                    <div v-if="rule.email_show_details" class="mrw-mail-note">{{ $t('+ facts table') }}</div>
                    <div v-if="rule.email_show_items" class="mrw-mail-note">{{ $t('+ item list') }}</div>
                  </div>
                  <button type="button" class="mrw-link" @click="resetField(rule, 'email_subject'); resetField(rule, 'email_body')">{{ $t('Reset to default text') }}</button>
                </div>
              </div>
            </section>
          </div>

          <!-- the container of every tag -->
          <aside class="mrw-tags lg-card" :aria-label="$t('Tags you can use')">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-teal"><icons name="hash" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Tags you can use') }}</h2>
                <p class="lg-card-sub">{{ target ? $t('Click a tag to insert it where you are typing.') : $t('Click inside a text field first, then click a tag.') }}</p>
              </div>
            </header>
            <input v-model="tagSearch" type="search" class="mrw-input mb-3" :placeholder="$t('Search tags…')" :aria-label="$t('Search tags')" />
            <div v-for="group in tagGroups" :key="group.name" class="mrw-tag-group">
              <div class="mrw-label">{{ $t(group.name) }}</div>
              <button v-for="t in group.tags" :key="t.tag" type="button" class="mrw-tag" :title="t.description + ' - ' + $t('e.g.') + ' ' + t.sample" @mousedown.prevent @click="insertTag(t.tag)">
                <code>{{ t.tag }}</code>
                <span>{{ $t(t.label) }}</span>
              </button>
            </div>
            <p v-if="!tagGroups.length" class="lg-empty">{{ $t('No tags match.') }}</p>
          </aside>
        </div>
      </div>

      <div v-if="formErrors.length" class="lg-note lg-note-red mb-3">
        <icons name="exclamation" class="w-4 h-4" />
        <ul class="list-disc pl-4">
          <li v-for="(e, i) in formErrors" :key="i">{{ e }}</li>
        </ul>
      </div>

      <!-- ============ STICKY SAVE BAR ============ -->
      <div class="lg-actionbar">
        <span class="lg-actionbar-note">
          <template v-if="dirty">{{ $t('Unsaved changes') }}</template>
          <template v-else>{{ $t('No changes') }}</template>
        </span>
        <div class="lg-actionbar-btns mrw-save-row">
          <div class="mrw-pw">
            <input
              v-model="password"
              type="password"
              autocomplete="current-password"
              class="mrw-pw-input"
              :class="{ 'is-error': passwordError }"
              :placeholder="$t('Your own Super Admin password')"
              :aria-label="$t('Confirm your password')"
              @keyup.enter="save"
            />
            <span v-if="passwordError" class="mrw-pw-error">{{ passwordError }}</span>
          </div>
          <button type="button" class="lg-btn lg-btn-primary" :disabled="saving" @click="save">
            <icons name="lock-closed" class="w-4 h-4" />{{ saving ? $t('Saving…') : $t('Save Alert Settings') }}
          </button>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import CheckBox from '@/Shared/CheckBox.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';

const TAB_KEY = 'mrw.activeTab';

export default {
  components: { AdminLayout, PageHeader, CheckBox, AutoComplete },

  props: {
    notificationRules: Array,
    roles: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    clearDeliveryMode: { type: String, default: 'warn' },
    messageDefaults: { type: Object, default: () => ({}) },
    messageTags: { type: Array, default: () => [] },
  },

  data() {
    let tab = 'notifications';
    try {
      const saved = window.localStorage.getItem(TAB_KEY);
      if (['notifications', 'messages'].includes(saved)) tab = saved;
    } catch (e) {
      /* storage unavailable: fall back to the first tab */
    }

    const data = {
      activeTab: tab,
      localNotifications: this.notificationRules.map(n => ({
        ...n,
        role_names: [...(n.role_names || [])],
        user_ids: [...(n.user_ids || [])],
        notify_requester: !!n.notify_requester,
        notify_site_engineers: !!n.notify_site_engineers,
        sms_enabled: !!n.sms_enabled,
        email_enabled: !!n.email_enabled,
        sms_template: n.sms_template || '',
        email_subject: n.email_subject || '',
        email_body: n.email_body || '',
        email_show_details: n.email_show_details !== false,
        email_show_items: n.email_show_items !== false,
        _pendingUserId: null,
      })),
      localClearMode: this.clearDeliveryMode,
      openEvents: {},
      tagSearch: '',
      target: null,
      password: '',
      passwordError: '',
      formErrors: [],
      saving: false,
      saved: false,
      savedSnapshot: '',
    };

    return data;
  },

  computed: {
    tabs() {
      return [
        { key: 'notifications', label: this.$t('Who Gets Notified'), icon: 'bell', count: null },
        { key: 'messages', label: this.$t('SMS & Email'), icon: 'email', count: this.localNotifications.filter(n => n.sms_enabled || n.email_enabled).length },
      ];
    },
    clearModes() {
      return [
        { value: 'allow', label: this.$t('Allow'), hint: this.$t('Clear whenever, no questions asked.') },
        { value: 'warn', label: this.$t('Warn first'), hint: this.$t('List the items not fully delivered and ask to confirm. Written into the history.') },
        { value: 'block', label: this.$t('Block'), hint: this.$t('Refuse until every item is Fully Delivered.') },
      ];
    },
    tagGroups() {
      const q = this.tagSearch.trim().toLowerCase();
      const groups = [];
      this.messageTags
        .filter(t => !q || (t.tag + ' ' + t.label + ' ' + t.description).toLowerCase().includes(q))
        .forEach(t => {
          let g = groups.find(x => x.name === t.group);
          if (!g) groups.push((g = { name: t.group, tags: [] }));
          g.tags.push(t);
        });
      return groups;
    },
    payload() {
      return {
        clearDeliveryMode: this.localClearMode,
        notificationRules: this.localNotifications.map(n => ({
          event_key: n.event_key,
          role_names: n.role_names,
          user_ids: n.user_ids,
          notify_requester: !!n.notify_requester,
          notify_site_engineers: !!n.notify_site_engineers,
          sms_enabled: !!n.sms_enabled,
          email_enabled: !!n.email_enabled,
          sms_template: n.sms_template,
          email_subject: n.email_subject,
          email_body: n.email_body,
          email_show_details: !!n.email_show_details,
          email_show_items: !!n.email_show_items,
        })),
      };
    },
    dirty() {
      return JSON.stringify(this.payload) !== this.savedSnapshot;
    },
  },

  created() {
    this.savedSnapshot = JSON.stringify(this.payload);
  },

  mounted() {
    window.addEventListener('beforeunload', this.warnUnsaved);
  },

  beforeUnmount() {
    window.removeEventListener('beforeunload', this.warnUnsaved);
  },

  methods: {
    warnUnsaved(e) {
      if (this.dirty && !this.saving) {
        e.preventDefault();
        e.returnValue = '';
      }
    },
    setTab(key) {
      this.activeTab = key;
      try {
        window.localStorage.setItem(TAB_KEY, key);
      } catch (e) {
        /* storage unavailable: tab simply is not remembered */
      }
    },
    notificationIcon(key) {
      return { created: 'plus', updated: 'edit', deleted: 'trash', status_changed: 'refresh', approved: 'tick', rejected: 'cross', partially_issued: 'truck', issued: 'truck', delivery_updated: 'tick', cleared: 'lock-closed', closed: 'lock-closed' }[key] || 'bell';
    },
    audienceSummary(rule) {
      const who = [];
      if (rule.notify_requester) who.push(this.$t('the requester'));
      if (rule.notify_site_engineers) who.push(this.$t('assigned Site Engineers'));
      rule.role_names.forEach(r => who.push(r));
      if (rule.user_ids.length) who.push(rule.user_ids.length + ' ' + this.$t('selected user(s)'));
      return who.length ? this.$t('Goes to') + ': ' + who.join(', ') : this.$t('Goes to the owner of the next step only. Choose more people in Who Gets Notified.');
    },
    // ---- tag container: remember which field was last focused, insert at the cursor
    setTarget(rule, field, event) {
      this.target = { rule, field, el: event.target };
    },
    insertTag(tag) {
      if (!this.target) return;
      const { rule, field, el } = this.target;
      const current = rule[field] || '';
      const start = typeof el.selectionStart === 'number' ? el.selectionStart : current.length;
      const end = typeof el.selectionEnd === 'number' ? el.selectionEnd : start;
      rule[field] = current.slice(0, start) + tag + current.slice(end);
      this.$nextTick(() => {
        el.focus();
        const pos = start + tag.length;
        try { el.setSelectionRange(pos, pos); } catch (e) { /* ignore */ }
      });
    },
    resetField(rule, field) {
      const d = (this.messageDefaults || {})[rule.event_key] || {};
      rule[field] = d[field] || '';
    },
    // Live preview with example values — same rules as the server (unknown tags stay, empty lines vanish).
    preview(text, sms = false, oneLine = false) {
      const samples = {};
      this.messageTags.forEach(t => (samples[t.tag.slice(1).toLowerCase()] = t.sample));
      if (sms) samples.url = '';
      const out = [];
      String(text || '').split(/\r?\n/).forEach(line => {
        let known = 0;
        let filled = 0;
        const rendered = line.replace(/#([a-z]+)/gi, (m, k) => {
          const key = k.toLowerCase();
          if (!(key in samples)) return m;
          known++;
          if (String(samples[key]).trim() !== '') filled++;
          return samples[key];
        });
        if (known > 0 && filled === 0) return;
        out.push(rendered.trimEnd());
      });
      let result = out.join('\n').replace(sms ? /\n{2,}/g : /\n{3,}/g, sms ? '\n' : '\n\n').trim();
      if (oneLine) result = result.replace(/\s+/g, ' ');
      return result;
    },
    smsStats(rule) {
      const text = this.preview(rule.sms_template, true);
      const unicode = /[^\x00-\x7F]/.test(text);
      const single = unicode ? 70 : 160;
      const multi = unicode ? 67 : 153;
      const chars = text.length;
      return { chars, unicode, parts: chars <= single ? (chars ? 1 : 0) : Math.ceil(chars / multi) };
    },
    toggleIn(list, value) {
      const i = list.indexOf(value);
      if (i === -1) list.push(value);
      else list.splice(i, 1);
    },
    staleRoles(selected) {
      return selected.filter(r => !this.roles.includes(r));
    },
    availableUsersFor(step) {
      return this.users.filter(u => !step.user_ids.includes(u.id));
    },
    userName(id) {
      return this.users.find(u => u.id === id)?.name || `#${id}`;
    },
    addUser(step) {
      if (!step._pendingUserId || step.user_ids.includes(step._pendingUserId)) return;
      step.user_ids.push(step._pendingUserId);
      step._pendingUserId = null;
    },
    removeUser(step, uid) {
      step.user_ids = step.user_ids.filter(id => id !== uid);
    },
    save() {
      if (this.saving) return;
      this.passwordError = '';
      this.formErrors = [];
      if (!this.password) {
        this.passwordError = this.$t('Enter your password to confirm this change.');
        return;
      }
      this.saving = true;
      const body = { password: this.password, ...this.payload };
      // saved through Inertia so the app's own notification alert (top right) reports the result
      this.$inertia.put(this.route('material-requests.workflow.update'), body, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
          this.password = '';
          this.$nextTick(() => {
            this.savedSnapshot = JSON.stringify(this.payload);
          });
        },
        onError: errors => {
          if (errors.password) this.passwordError = errors.password;
          this.formErrors = Object.entries(errors)
            .filter(([key]) => key !== 'password')
            .map(([, msg]) => msg);
        },
        onFinish: () => {
          this.saving = false;
        },
      });
    },
  },
};
</script>

<style scoped>
.mrw-off .mrw-block, .mrw-off .mrw-switches { opacity: 0.45; pointer-events: none; filter: grayscale(0.4); }
.mrw-onoff { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; color: var(--lg-navy); background: var(--lg-soft); border: 1px solid var(--lg-line); cursor: pointer; }
.mrw-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 1rem; }
.mrw-tab {
  display: inline-flex; align-items: center; gap: 7px; padding: 8px 16px; border-radius: 9999px;
  font-size: 0.8rem; font-weight: 800; color: var(--lg-navy); background: var(--lg-card);
  border: 1px solid var(--lg-line); transition: transform 0.15s ease, background 0.15s ease;
}
.mrw-tab:hover { transform: translateY(-1px); }
.mrw-tab.is-active { color: #fff; border-color: transparent; background: linear-gradient(145deg, #2f6fb3, #0f2540); box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.9); }
.mrw-tab-count { min-width: 20px; padding: 0 6px; border-radius: 9999px; font-size: 0.68rem; text-align: center; background: rgba(100, 116, 139, 0.18); }
.mrw-tab.is-active .mrw-tab-count { background: rgba(255, 255, 255, 0.25); }
html.dark .mrw-tab { color: #bfdbfe; }

.mrw-block { margin-bottom: 1rem; }
.mrw-block:last-child { margin-bottom: 0; }
.mrw-label { margin-bottom: 6px; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.mrw-muted { font-size: 0.72rem; font-weight: 500; letter-spacing: 0; text-transform: none; color: var(--lg-mute); }
p.mrw-muted { margin-bottom: 8px; }
.mrw-label .mrw-muted { margin: 0; }

.mrw-toggles, .mrw-chips { display: flex; flex-wrap: wrap; gap: 8px; }
.mrw-chips { margin-top: 10px; }
.mrw-toggle {
  display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 9999px;
  font-size: 0.76rem; font-weight: 700; color: var(--lg-mute); background: var(--lg-soft);
  border: 1px solid var(--lg-line); transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
}
.mrw-toggle:hover { border-color: #818cf8; color: var(--lg-ink); }
.mrw-toggle.is-on { color: #4338ca; background: rgba(99, 102, 241, 0.14); border-color: rgba(99, 102, 241, 0.45); }
html.dark .mrw-toggle.is-on { color: #c7d2fe; }
.mrw-toggle.is-stale { color: #be123c; background: rgba(244, 63, 94, 0.1); border-color: rgba(244, 63, 94, 0.4); }
.mrw-toggle em { font-style: normal; font-weight: 600; opacity: 0.8; }
html.dark .mrw-toggle.is-stale { color: #fda4af; }

.mrw-adder { display: flex; align-items: flex-start; gap: 8px; }
.mrw-adder > :first-child { flex: 1 1 auto; min-width: 0; }
.mrw-adder .lg-btn { margin-top: 0; flex: none; height: 2.25rem; }
.mrw-adder-row { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }

.mrw-chip-x { display: inline-flex; align-items: center; justify-content: center; width: 16px; height: 16px; margin-right: -4px; border-radius: 9999px; background: rgba(67, 56, 202, 0.14); }
.mrw-chip-x:hover { background: rgba(67, 56, 202, 0.3); }

.mrw-switches { display: flex; flex-wrap: wrap; gap: 8px 24px; padding-top: 12px; border-top: 1px dashed var(--lg-line); }
.mrw-switch { display: inline-flex; align-items: center; gap: 8px; font-size: 0.82rem; font-weight: 600; color: var(--lg-ink); cursor: pointer; }
.mrw-card-stale { border-color: rgba(244, 63, 94, 0.45); }

.mrw-save-row { align-items: flex-start; flex-wrap: wrap; }
.mrw-pw { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.mrw-pw-input {
  width: 15rem; max-width: 100%; padding: 7px 14px; border-radius: 9999px; font-size: 0.8rem;
  color: var(--lg-ink); background: var(--lg-soft); border: 1px solid var(--lg-line);
}
.mrw-pw-input:focus { outline: none; border-color: #818cf8; box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.25); }
.mrw-pw-input.is-error { border-color: #ef4444; }
.mrw-pw-error { padding-left: 12px; font-size: 0.7rem; font-weight: 700; color: #be123c; }
html.dark .mrw-pw-error { color: #fda4af; }

@media (max-width: 640px) {
  .mrw-adder { flex-wrap: wrap; }
  .mrw-pw, .mrw-pw-input { width: 100%; }
  .mrw-save-row { width: 100%; }
  .mrw-save-row .lg-btn { width: 100%; }
}

/* ---- SMS & Email tab ---- */
.mrw-msg-layout { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 16px; align-items: start; }
.mrw-msg-main { min-width: 0; }
.mrw-tags { position: sticky; top: 84px; max-height: calc(100vh - 110px); overflow-y: auto; margin-bottom: 0; }
.mrw-msg-head { flex-wrap: wrap; gap: 10px; }
.mrw-channels { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-left: auto; }
.mrw-chan { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; cursor: pointer; color: var(--lg-mute); background: var(--lg-soft); border: 1px solid var(--lg-line); }
.mrw-chan.is-on { color: #047857; background: rgba(16, 185, 129, 0.14); border-color: rgba(16, 185, 129, 0.4); }
html.dark .mrw-chan.is-on { color: #6ee7b7; }
.mrw-edit-btn { padding: 6px 12px; }
.mrw-msg-body { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; padding-top: 14px; margin-top: 4px; border-top: 1px solid var(--lg-line); }
.mrw-msg-col { min-width: 0; display: flex; flex-direction: column; gap: 6px; }
.mrw-msg-col.is-off .mrw-area, .mrw-msg-col.is-off .mrw-input, .mrw-msg-col.is-off .mrw-preview { opacity: 0.55; }
.mrw-sublabel { font-size: 0.7rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.mrw-input, .mrw-area { width: 100%; padding: 0.55rem 0.75rem; border: 1px solid var(--lg-line); border-radius: 0.8rem; background: var(--lg-card); color: var(--lg-ink); font-size: 0.86rem; }
.mrw-area { resize: vertical; line-height: 1.5; font-family: inherit; }
.mrw-input:focus, .mrw-area:focus { outline: none; border-color: #818cf8; box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.2); }
.mrw-count { font-size: 0.72rem; font-weight: 700; color: var(--lg-faint); }
.mrw-link { align-self: flex-start; font-size: 0.74rem; font-weight: 800; color: #4f46e5; background: none; border: 0; padding: 2px 0; cursor: pointer; }
.mrw-link:hover { text-decoration: underline; }
html.dark .mrw-link { color: #a5b4fc; }
.mrw-preview { margin-top: 4px; padding: 10px; border-radius: 14px; background: var(--lg-soft); border: 1px dashed var(--lg-line); }
.mrw-bubble { white-space: pre-line; max-width: 100%; padding: 9px 12px; border-radius: 16px 16px 16px 4px; font-size: 0.8rem; line-height: 1.45; color: #fff; background: linear-gradient(145deg, #34d399, #047857); overflow-wrap: anywhere; }
.mrw-mail-head { font-weight: 800; font-size: 0.82rem; color: var(--lg-ink); padding-bottom: 6px; border-bottom: 1px solid var(--lg-line); margin-bottom: 8px; overflow-wrap: anywhere; }
.mrw-mail-body { white-space: pre-line; font-size: 0.8rem; line-height: 1.5; color: var(--lg-mute); overflow-wrap: anywhere; }
.mrw-mail-note { margin-top: 6px; font-size: 0.7rem; font-weight: 700; color: var(--lg-faint); }
.mrw-switches-tight { padding-top: 6px; margin-top: 2px; border-top: 0; }
.mrw-tag-group { margin-bottom: 12px; }
.mrw-tag { display: flex; flex-direction: column; align-items: flex-start; width: 100%; gap: 1px; margin-bottom: 6px; padding: 7px 10px; text-align: left; border-radius: 12px; background: var(--lg-soft); border: 1px solid var(--lg-line); cursor: pointer; transition: transform 0.12s ease, border-color 0.12s ease; }
.mrw-tag:hover { transform: translateY(-1px); border-color: #818cf8; }
.mrw-tag code { font-size: 0.8rem; font-weight: 800; color: #4338ca; background: none; padding: 0; }
html.dark .mrw-tag code { color: #c4b5fd; }
.mrw-tag span { font-size: 0.7rem; color: var(--lg-mute); }
@media (max-width: 1023.98px) {
  .mrw-msg-layout { grid-template-columns: minmax(0, 1fr); }
  .mrw-tags { position: static; max-height: 340px; order: -1; }
}
@media (max-width: 767.98px) {
  .mrw-msg-body { grid-template-columns: minmax(0, 1fr); }
  .mrw-channels { margin-left: 0; width: 100%; }
}

.mrw-modes { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.mrw-mode { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); cursor: pointer; transition: border-color 0.12s ease, background 0.12s ease; }
.mrw-mode:hover { border-color: #818cf8; }
.mrw-mode.is-on { background: rgba(99, 102, 241, 0.12); border-color: #6366f1; }
.mrw-mode-dot { flex: none; width: 16px; height: 16px; margin-top: 2px; border-radius: 9999px; border: 2px solid var(--lg-faint); }
.mrw-mode.is-on .mrw-mode-dot { border-color: #6366f1; background: radial-gradient(circle, #6366f1 45%, transparent 50%); }
.mrw-mode-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.mrw-mode-text b { font-size: 0.86rem; color: var(--lg-ink); }
.mrw-mode-text small { font-size: 0.74rem; color: var(--lg-mute); }
@media (max-width: 767.98px) { .mrw-modes { grid-template-columns: minmax(0, 1fr); } }
</style>

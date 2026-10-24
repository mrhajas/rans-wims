<template>
  <tec-dialog-modal :show="show" max-width="4xl" @close="$emit('close')">
    <template #title>
      <div class="ua-title">
        <span class="ua-title__ico"><icons name="clock" class="w-5 h-5" /></span>
        <div>
          <b>{{ userId ? $t('Audit history of :n', { n: subjectName }) : $t('User audit history') }}</b>
          <small>{{ $t('The major things that happen to an account: password, details, roles, access, security.') }}</small>
        </div>
      </div>
    </template>

    <template #content>
      <div class="ua">
        <div class="ua-hero">
          <span class="ua-hero__ico"><icons name="clock" class="w-6 h-6" /></span>
          <div class="ua-hero__txt"><b>{{ total }}</b><small>{{ $t('recorded events') }}<template v-if="userId || scope"> · {{ subjectName }}</template></small></div>
          <div class="ua-hero__tiles">
            <span v-for="g in groupChips.slice(1)" :key="g.key" class="ua-tile" :style="{ '--tone': g.tone }"><icons :name="g.icon" class="w-3.5 h-3.5" /><b>{{ counts[g.key] || 0 }}</b><small>{{ g.label }}</small></span>
          </div>
        </div>

        <div class="ua-filters">
          <label class="ua-search"><icons name="search" class="w-4 h-4" /><input v-model="f.search" type="search" :placeholder="$t('Search a person or a detail')" /></label>
          <div v-if="!userId" class="ua-field"><span class="ua-lbl"><icons name="user" class="w-3 h-3" />{{ $t('Person') }}</span>
            <auto-complete id="ua-user" :suggestions="users" :model-value="scope" :placeholder="$t('Anyone')" clearable @update:modelValue="v => setUser(v)" />
          </div>
          <div class="ua-field"><span class="ua-lbl"><icons name="filter" class="w-3 h-3" />{{ $t('Type of event') }}</span>
            <auto-complete id="ua-kind" :suggestions="kindOptions" :model-value="f.kind" :placeholder="$t('Every type of event')" clearable @update:modelValue="v => (f.kind = v || '')" />
          </div>
          <div class="ua-field"><span class="ua-lbl"><icons name="calendar" class="w-3 h-3" />{{ $t('From') }}</span>
            <date-picker id="ua-from" v-model="f.from" :max="f.to || undefined" :placeholder="$t('Any date')" />
          </div>
          <div class="ua-field"><span class="ua-lbl"><icons name="calendar" class="w-3 h-3" />{{ $t('To') }}</span>
            <date-picker id="ua-to" v-model="f.to" :min="f.from || undefined" :placeholder="$t('Any date')" />
          </div>
        </div>
        <div class="ua-chips" role="tablist">
          <button v-for="g in groupChips" :key="g.key" type="button" class="ua-chip" :class="{ 'is-on': f.group === g.key }" :style="{ '--tone': g.tone }" @click="setGroup(g.key)"><icons :name="g.icon" class="w-3.5 h-3.5" />{{ g.label }}<em>{{ counts[g.key] || 0 }}</em></button>
        </div>

        <div v-if="loading && !rows.length" class="ua-skel"><div v-for="n in 4" :key="n"><i></i><i></i></div></div>
        <p v-else-if="error" class="ua-empty is-err"><icons name="exclamation" class="w-5 h-5" />{{ error }}</p>
        <p v-else-if="!rows.length" class="ua-empty"><icons name="info" class="w-5 h-5" />{{ $t('Nothing was recorded for this choice.') }}</p>

        <ol v-else class="ua-list" :class="{ 'is-busy': loading }">
          <li v-for="e in rows" :key="e.id" class="ua-item" :style="{ '--tone': kindOf(e.kind).tone }">
            <span class="ua-ico"><icons :name="kindOf(e.kind).icon" class="w-4 h-4" /></span>
            <div class="ua-body">
              <div class="ua-head">
                <b>{{ kindOf(e.kind).label }}</b>
                <button v-if="!userId && e.user_id" type="button" class="ua-who" :title="$t('Show only this user')" @click="onlyUser(e)"><icons name="user" class="w-3 h-3" />{{ nameOf(e) }}</button>
                <span v-else-if="!userId && e.kind === 'role_permissions'" class="ua-who is-role"><icons name="users" class="w-3 h-3" />{{ e.user }}</span>
                <time class="ua-time" :title="e.at">{{ $datetime(e.at) }}</time>
              </div>
              <p class="ua-sum">{{ summary(e) }}</p>

              <ul v-if="e.details.changes && e.details.changes.length" class="ua-changes">
                <li v-for="(c, i) in e.details.changes" :key="i"><span>{{ $t(c.label) }}</span><s v-if="c.from !== undefined && c.from !== null">{{ c.from }}</s><icons v-if="c.from !== undefined && c.from !== null" name="arrow-left" class="w-3 h-3 ua-arrow" /><b>{{ c.to }}</b></li>
              </ul>
              <div v-if="(e.details.added && e.details.added.length) || (e.details.removed && e.details.removed.length)" class="ua-diff">
                <span v-for="n in e.details.added" :key="'a' + n" class="ua-tag is-add">+ {{ n }}</span>
                <span v-for="n in e.details.removed" :key="'r' + n" class="ua-tag is-rem">− {{ n }}</span>
              </div>
              <ul v-if="e.details.methods && e.details.methods.length" class="ua-changes">
                <li v-for="(m, i) in e.details.methods" :key="i"><span>{{ $t(m.label) }}</span><b :class="m.to === 'On' ? 'is-on' : 'is-off'">{{ $t(m.to) }}</b></li>
              </ul>
              <p v-if="e.details.reason" class="ua-note">{{ $t('Reason') }}: {{ e.details.reason }}</p>

              <div class="ua-meta">
                <span v-if="e.actor"><icons name="user" class="w-3 h-3" />{{ $t('By') }} {{ e.actor }}</span>
                <span v-else><icons name="user" class="w-3 h-3" />{{ $t('Nobody was signed in') }}</span>
                <span v-if="e.ip"><icons name="globe" class="w-3 h-3" />{{ e.ip }}</span>
                <span v-if="e.device"><icons name="desktop-computer" class="w-3 h-3" />{{ e.device }}</span>
              </div>
            </div>
          </li>
        </ol>

        <div v-if="meta && meta.last_page > 1" class="ua-pager">
          <button type="button" class="lg-btn lg-btn-soft" :disabled="loading || meta.current_page <= 1" @click="go(meta.current_page - 1)"><icons name="chevron-down" class="w-4 h-4 rotate-90" />{{ $t('Newer') }}</button>
          <span>{{ meta.from }}-{{ meta.to }} {{ $t('of') }} {{ meta.total }}</span>
          <button type="button" class="lg-btn lg-btn-soft" :disabled="loading || meta.current_page >= meta.last_page" @click="go(meta.current_page + 1)">{{ $t('Older') }}<icons name="chevron-down" class="w-4 h-4 -rotate-90" /></button>
        </div>
      </div>
    </template>

    <template #footer>
      <button type="button" class="lg-btn lg-btn-soft" @click="$emit('close')">{{ $t('Close') }}</button>
    </template>
  </tec-dialog-modal>
</template>

<script>
import debounce from 'lodash/debounce';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';

export default {
  components: { TecDialogModal, AutoComplete, DatePicker },
  emits: ['close'],
  props: { show: { type: Boolean, default: false }, userId: { type: Number, default: null }, userName: { type: String, default: '' } },

  data() {
    return { f: { search: '', from: '', to: '', group: '', kind: '' }, scope: null, page: 1, rows: [], meta: null, kinds: [], users: [], counts: {}, loading: false, error: '', subject: '' };
  },

  computed: {
    subjectName() { return this.userName || this.subject || ''; },
    groupChips() {
      return [
        { key: '', label: this.$t('All'), icon: 'collection', tone: '#475569' },
        { key: 'Account', label: this.$t('Account'), icon: 'users', tone: '#059669' },
        { key: 'Security', label: this.$t('Security'), icon: 'lock-closed', tone: '#d97706' },
        { key: 'Information', label: this.$t('Information'), icon: 'edit', tone: '#2563eb' },
        { key: 'Access', label: this.$t('Access'), icon: 'key', tone: '#4f46e5' },
      ];
    },
    kindOptions() { return this.kinds.filter(k => !this.f.group || k.group === this.f.group).map(k => ({ value: k.key, label: k.label })); },
    total() { return this.counts[''] || 0; },
  },

  watch: {
    show(v) { if (v) this.open(); },
    'f.search': 'refresh', 'f.from': 'refresh', 'f.to': 'refresh', 'f.kind': 'refresh',
  },

  created() {
    this.refresh = debounce(() => { this.page = 1; this.load(); }, 350);
  },

  mounted() { if (this.show) this.open(); },

  methods: {
    open() {
      this.f = { search: '', from: '', to: '', group: '', kind: '' };
      this.scope = this.userId;
      this.page = 1;
      this.rows = [];
      this.load();
    },
    setGroup(g) { this.f.group = g; this.f.kind = ''; this.page = 1; this.load(); },
    onlyUser(e) { this.scope = e.user_id; this.subject = e.user; this.page = 1; this.load(); },
    setUser(v) { this.scope = v ? Number(v) : null; if (!v) this.subject = ''; this.page = 1; this.load(); },
    go(p) { this.page = p; this.load(); },
    // old lines of an account that was deleted long ago may not have its name any more
    nameOf(e) { return e.user || (e.user_id ? this.$t('user #:n', { n: e.user_id }) : this.$t('an unknown user')); },
    kindOf(key) { return this.kinds.find(k => k.key === key) || { label: key, icon: 'info', tone: '#64748b', group: '' }; },
    async load() {
      this.loading = true;
      this.error = '';
      try {
        const params = { page: this.page, ...Object.fromEntries(Object.entries(this.f).filter(([, v]) => v)) };
        const uid = this.scope || this.userId;
        if (uid) params.user_id = uid;
        if (!this.userId && !this.users.length) params.with_users = 1;
        const { data } = await axios.get(this.route('users.audit-history'), { params });
        this.rows = data.data; this.meta = data.meta; this.kinds = data.kinds; this.counts = data.counts || {};
        if (data.users) this.users = data.users;
        if (data.subject) this.subject = data.subject.name;
      } catch (e) {
        this.error = (e.response && e.response.data && e.response.data.message) || this.$t('The history could not be loaded.');
      } finally {
        this.loading = false;
      }
    },
    // one plain sentence per event
    summary(e) {
      const actor = e.actor || this.$t('Someone');
      const who = this.nameOf(e);
      const d = e.details || {};
      switch (e.kind) {
        case 'user_created': return this.$t(':a created the account of :u.', { a: actor, u: who });
        case 'user_deleted': return this.$t(':a deleted the account of :u.', { a: actor, u: who });
        case 'user_restored': return this.$t(':a restored the account of :u.', { a: actor, u: who });
        case 'user_deleted_permanently': return this.$t(':a deleted the account of :u for good.', { a: actor, u: who });
        case 'account_disabled': return this.$t(':a disabled the account of :u. They can no longer sign in.', { a: actor, u: who });
        case 'account_enabled': return this.$t(':a enabled the account of :u again.', { a: actor, u: who });
        case 'password_changed':
          if (d.source === 'self') return this.$t(':u changed their own password.', { u: who });
          if (d.source === 'reset') return this.$t('The password of :u was reset with the forgot password link.', { u: who });
          return this.$t(':a set a new password for :u.', { a: actor, u: who });
        case 'info_updated': return this.$t(':a updated the information of :u.', { a: actor, u: who });
        case 'roles_updated': return this.$t(':a changed the roles of :u.', { a: actor, u: who });
        case 'warehouses_updated': return this.$t(':a changed the projects / warehouses of :u.', { a: actor, u: who });
        case 'two_factor_changed': return this.$t('The two-step sign-in of :u was changed.', { u: who });
        case 'face_reset': return this.$t(':a removed the face login of :u.', { a: actor, u: who });
        case 'impersonated': return this.$t(':a signed in as :u.', { a: actor, u: who });
        case 'staff_link': return d.action === 'unlinked' ? this.$t(':a unlinked :u from the staff profile :s.', { a: actor, u: who, s: d.staff_number || d.staff }) : this.$t(':a linked :u to the staff profile :s.', { a: actor, u: who, s: d.staff_number || d.staff });
        case 'role_permissions': return this.$t(':a changed the permissions of the role :r (:p added, :m removed).', { a: actor, r: d.role || who, p: d.added_count || 0, m: d.removed_count || 0 });
        default: return '';
      }
    },
  },
};
</script>

<style scoped>
.ua-title { display: flex; align-items: center; gap: 12px; } .ua-title b { display: block; font-size: 1.05rem; } .ua-title small { display: block; margin-top: 2px; font-size: 0.78rem; font-weight: 500; color: #64748b; }
.ua-title__ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 40px; height: 40px; border-radius: 13px; color: #fff; background: linear-gradient(145deg, #6366f1, #1d4ed8); box-shadow: 0 10px 16px -10px #4338ca; }
.ua-title__ico > div { flex: none; margin: 0; }
.ua-hero { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 16px; margin-bottom: 12px; padding: 12px 14px; border-radius: 18px; color: #fff; background: radial-gradient(circle at 12% 0%, rgba(255, 255, 255, 0.22), transparent 55%), linear-gradient(135deg, #0ea5e9, #4f46e5); box-shadow: 0 14px 24px -16px #4338ca; }
.ua-hero__ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 44px; height: 44px; border-radius: 14px; background: rgba(255, 255, 255, 0.2); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.3); } .ua-hero__ico > div { flex: none; margin: 0; }
.ua-hero__txt b { display: block; font-size: 1.5rem; line-height: 1.1; font-variant-numeric: tabular-nums; } .ua-hero__txt small { font-size: 0.78rem; font-weight: 700; opacity: 0.9; }
.ua-hero__tiles { display: flex; flex-wrap: wrap; gap: 6px; margin-left: auto; }
.ua-tile { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border-radius: 12px; background: rgba(255, 255, 255, 0.18); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.28); font-size: 0.74rem; } .ua-tile b { font-size: 0.92rem; font-variant-numeric: tabular-nums; } .ua-tile small { font-weight: 700; opacity: 0.92; } .ua-tile :deep(div) { flex: none; margin: 0; }
.ua-filters { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; align-items: end; margin-bottom: 10px; padding: 12px; border-radius: 18px; background: #f8fafc; box-shadow: inset 0 0 0 1px #e2e8f0; }
.ua-search { grid-column: 1 / -1; display: flex; align-items: center; gap: 8px; padding: 0 14px; height: 42px; border-radius: 14px; background: #fff; box-shadow: inset 0 0 0 1px #cbd5e1; color: #64748b; } .ua-search:focus-within { box-shadow: inset 0 0 0 2px #6366f1; } .ua-search input { flex: 1; min-width: 0; border: 0; background: transparent; outline: 0; font-size: 0.88rem; color: #0f172a; padding: 0; box-shadow: none; }
.ua-field { min-width: 0; } .ua-field :deep(.relative) { margin-bottom: 0; } .ua-field :deep(input) { height: 42px; border-radius: 14px; }
.ua-lbl { display: flex; align-items: center; gap: 5px; margin-bottom: 4px; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: #64748b; } .ua-lbl :deep(div) { flex: none; margin: 0; }
.ua-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
.ua-chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 8px 6px 12px; border: 0; border-radius: 999px; cursor: pointer; font-size: 0.78rem; font-weight: 800; color: var(--tone); background: color-mix(in srgb, var(--tone) 10%, #fff); box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--tone) 28%, transparent); transition: transform 0.15s ease; } .ua-chip:hover { transform: translateY(-1px); } .ua-chip em { min-width: 20px; padding: 0 6px; border-radius: 999px; font-style: normal; font-size: 0.7rem; text-align: center; color: #fff; background: var(--tone); } .ua-chip.is-on { color: #fff; background: linear-gradient(135deg, var(--tone), color-mix(in srgb, var(--tone) 65%, #000)); box-shadow: 0 8px 14px -8px var(--tone); } .ua-chip.is-on em { color: var(--tone); background: #fff; } .ua-chip :deep(div) { flex: none; margin: 0; }
.ua-list { list-style: none; margin: 0; padding: 0; max-height: 52vh; overflow-y: auto; display: grid; gap: 8px; padding-right: 4px; } .ua-list.is-busy { opacity: 0.55; }
.ua-item { display: flex; gap: 12px; padding: 11px 13px; border-radius: 16px; background: color-mix(in srgb, var(--tone) 5%, #fff); box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--tone) 18%, transparent); }
.ua-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 34px; height: 34px; border-radius: 11px; color: #fff; background: linear-gradient(145deg, var(--tone), color-mix(in srgb, var(--tone) 62%, #000)); box-shadow: 0 8px 12px -8px var(--tone); } .ua-ico > div { flex: none; margin: 0; }
.ua-body { min-width: 0; flex: 1; }
.ua-head { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 10px; } .ua-head b { color: color-mix(in srgb, var(--tone) 78%, #0f172a); font-size: 0.9rem; }
.ua-who { display: inline-flex; align-items: center; gap: 4px; padding: 2px 9px; border: 0; border-radius: 999px; cursor: pointer; font-size: 0.74rem; font-weight: 800; color: #0f172a; background: #e2e8f0; } .ua-who:hover { background: #cbd5e1; } .ua-who.is-role { cursor: default; background: #e0e7ff; color: #3730a3; }
.ua-time { margin-left: auto; font-size: 0.74rem; font-weight: 700; color: #64748b; font-variant-numeric: tabular-nums; }
.ua-sum { margin: 3px 0 0; font-size: 0.86rem; color: #334155; overflow-wrap: anywhere; }
.ua-changes { list-style: none; margin: 7px 0 0; padding: 0; display: grid; gap: 4px; } .ua-changes li { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: 0.8rem; } .ua-changes span { min-width: 7.5rem; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: #64748b; } .ua-changes s { color: #94a3b8; overflow-wrap: anywhere; } .ua-changes b { color: #0f172a; overflow-wrap: anywhere; } .ua-changes b.is-on { color: #15803d; } .ua-changes b.is-off { color: #b91c1c; } .ua-arrow { transform: rotate(180deg); color: #94a3b8; }
.ua-diff { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 7px; } .ua-tag { padding: 2px 10px; border-radius: 999px; font-size: 0.76rem; font-weight: 800; overflow-wrap: anywhere; } .ua-tag.is-add { color: #166534; background: #dcfce7; } .ua-tag.is-rem { color: #991b1b; background: #fee2e2; }
.ua-note { margin: 6px 0 0; font-size: 0.8rem; color: #475569; font-style: italic; }
.ua-meta { display: flex; flex-wrap: wrap; gap: 4px 14px; margin-top: 8px; font-size: 0.72rem; font-weight: 600; color: #64748b; } .ua-meta span { display: inline-flex; align-items: center; gap: 4px; } .ua-meta :deep(div) { flex: none; }
.ua-empty { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 34px 12px; border-radius: 16px; font-size: 0.86rem; font-weight: 700; color: #64748b; background: #f8fafc; } .ua-empty.is-err { color: #b91c1c; background: #fef2f2; }
.ua-skel { display: grid; gap: 8px; } .ua-skel div { display: grid; gap: 6px; padding: 14px; border-radius: 16px; background: #f1f5f9; } .ua-skel i { height: 10px; border-radius: 6px; background: linear-gradient(90deg, #e2e8f0, #f8fafc, #e2e8f0); background-size: 200% 100%; animation: ua-sh 1.2s linear infinite; } .ua-skel i:last-child { width: 60%; } @keyframes ua-sh { to { background-position: -200% 0; } }
.ua-pager { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 12px; font-size: 0.8rem; font-weight: 700; color: #64748b; }
html.dark .ua-filters { background: #11172d; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.1); } html.dark .ua-search { background: #1b2342; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.14); color: #cfd8ff; } html.dark .ua-search input { color: #eef0fa; }
html.dark .ua-item { background: color-mix(in srgb, var(--tone) 14%, #11172d); } html.dark .ua-sum, html.dark .ua-changes b { color: #e2e8f0; } html.dark .ua-head b { color: color-mix(in srgb, var(--tone) 55%, #fff); }
html.dark .ua-chip { background: color-mix(in srgb, var(--tone) 18%, transparent); color: color-mix(in srgb, var(--tone) 50%, #fff); } html.dark .ua-chip.is-on { color: #fff; }
html.dark .ua-who { background: #26305a; color: #eef0fa; } html.dark .ua-empty, html.dark .ua-skel div { background: #1b2342; }
@media (max-width: 640px) { .ua-time { margin-left: 0; width: 100%; } .ua-changes span { min-width: 100%; } .ua-hero__tiles { margin-left: 0; width: 100%; } .ua-filters { grid-template-columns: 1fr 1fr; } .ua-item { padding: 10px; gap: 9px; } }
@media (max-width: 420px) { .ua-filters { grid-template-columns: 1fr 1fr; } .ua-filters > .ua-field:nth-of-type(-n+2) { grid-column: 1 / -1; } .ua-pager .lg-btn { padding-left: 10px; padding-right: 10px; } }
@media (prefers-reduced-motion: reduce) { .ua-skel i { animation: none; } }
</style>

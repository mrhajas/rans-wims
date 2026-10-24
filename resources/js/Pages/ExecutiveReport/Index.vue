<template>
  <admin-layout :title="$t('Executive Intelligence Report')">
    <div class="lg-page er-page px-4 md:px-0">
      <page-header
        icon="chart-bar"
        :title="$t('Executive Intelligence Report')"
        :subtitle="$t('Configure the weekly AI-generated executive briefing: who receives it, and when it goes out.')"
      >
        <button type="button" class="lg-btn lg-btn-dark" :disabled="sendingTest" @click="sendTest">
          <icons name="email" class="w-4 h-4" />{{ sendingTest ? $t('Sending...') : $t('Send Test Now') }}
        </button>
      </page-header>

      <stat-tiles :items="statItems" />

      <nav class="er-tabs print:hidden" role="tablist">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          type="button"
          role="tab"
          :aria-selected="activeTab === tab.key"
          class="er-tab"
          :class="{ 'is-active': activeTab === tab.key }"
          @click="activeTab = tab.key"
        >
          <icons :name="tab.icon" class="w-4 h-4" />
          <span>{{ tab.label }}</span>
          <span v-if="tabCount(tab.key) !== null" class="er-tab-count">{{ tabCount(tab.key) }}</span>
        </button>
      </nav>

      <!-- Settings tab -->
      <section v-if="activeTab === 'settings'" class="lg-card er-narrow">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="cog" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Delivery Settings') }}</h2>
            <p class="lg-card-sub">{{ $t('Choose the day and time the weekly briefing goes out') }}</p>
          </div>
        </header>

        <form @submit.prevent="saveSettings">
          <div class="er-toggle-row" :class="{ 'is-on': settingsForm.enabled }">
            <div class="min-w-0">
              <div class="er-toggle-title">{{ $t('Enabled') }}</div>
              <div class="er-toggle-sub">{{ $t('Automatically generate and send the weekly report') }}</div>
            </div>
            <span class="lg-chip" :class="settingsForm.enabled ? 'lg-chip-green' : 'lg-chip-slate'">{{ settingsForm.enabled ? $t('Yes') : $t('No') }}</span>
            <button
              type="button"
              role="switch"
              :aria-checked="settingsForm.enabled ? 'true' : 'false'"
              :aria-label="$t('Enabled')"
              class="er-switch"
              :class="{ 'is-on': settingsForm.enabled }"
              @click="settingsForm.enabled = !settingsForm.enabled"
            ><span class="er-switch-knob"></span></button>
          </div>

          <div class="er-fields">
            <div>
              <label class="er-label" for="er-day">{{ $t('Day of Week') }}</label>
              <css-select id="er-day" v-model="settingsForm.day" class="er-input">
                <option v-for="day in days" :key="day" :value="day">{{ $t(day.charAt(0).toUpperCase() + day.slice(1)) }}</option>
              </css-select>
            </div>
            <div>
              <label class="er-label" for="er-time">{{ $t('Time') }}</label>
              <input id="er-time" v-model="settingsForm.time" type="time" class="er-input" />
            </div>
          </div>

          <div class="er-btn-row">
            <button type="submit" class="lg-btn lg-btn-primary"><icons name="tick" class="w-4 h-4" />{{ $t('Save Settings') }}</button>
            <button type="button" class="lg-btn lg-btn-soft" :disabled="sendingTest" @click="sendTest">
              <icons name="email" class="w-4 h-4" />{{ sendingTest ? $t('Sending...') : $t('Send Test Now') }}
            </button>
          </div>
        </form>
      </section>

      <!-- Report content tab -->
      <template v-if="activeTab === 'content'">
        <section class="lg-card er-content-bar">
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('What is in the report') }}</h2><p class="lg-card-sub">{{ $t('Choose the sections, the transactions counted, the projects and the items the report covers. The next weekly report follows this exactly.') }}</p></div>
          <div class="er-btn-row" style="margin: 0">
            <button type="button" class="lg-btn lg-btn-soft" :disabled="savingContent" @click="saveContent(true)"><icons name="eye" class="w-4 h-4" />{{ $t('Save and preview PDF') }}</button>
            <button type="button" class="lg-btn lg-btn-primary" :disabled="savingContent" @click="saveContent(false)"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button>
          </div>
        </section>

        <div class="er-groups">
          <section v-for="g in catalogue" :key="g.group" class="lg-card er-group">
            <header class="er-group-head"><h3>{{ $t(g.group) }}</h3><span><button type="button" @click="setGroup(g, true)">{{ $t('All') }}</button><button type="button" @click="setGroup(g, false)">{{ $t('None') }}</button></span></header>
            <label v-for="it in g.items" :key="it.key" class="er-opt" :class="{ on: content.sections[it.key] }">
              <input v-model="content.sections[it.key]" type="checkbox" /><i></i>
              <span><b>{{ $t(it.label) }}</b><small>{{ $t(it.hint) }}</small></span>
            </label>
            <div v-if="g.group === 'Stock movements'" class="er-tx">
              <small>{{ $t('Transactions counted') }}</small>
              <label v-for="t in transactionTypes" :key="t.key" class="er-chip" :class="{ on: content.transactions.includes(t.key) }"><input v-model="content.transactions" type="checkbox" :value="t.key" />{{ $t(t.label) }}</label>
            </div>
          </section>
        </div>

        <section class="lg-card er-scope">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="building" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Which projects') }}</h2><p class="lg-card-sub">{{ $t('Every figure, list and pipeline is limited to these projects / warehouses.') }}</p></div></header>
          <div class="er-modes"><label :class="{ on: content.projects.mode === 'all' }"><input v-model="content.projects.mode" type="radio" value="all" />{{ $t('All projects') }}</label><label :class="{ on: content.projects.mode === 'selected' }"><input v-model="content.projects.mode" type="radio" value="selected" />{{ $t('Only selected projects') }}</label></div>
          <div v-if="content.projects.mode === 'selected'" class="er-projects">
            <input v-model="projectSearch" class="er-input" type="search" :placeholder="$t('Filter projects')" />
            <div class="er-plist"><label v-for="p in filteredProjects" :key="p.id" class="er-chip" :class="{ on: content.projects.ids.includes(p.id) }"><input v-model="content.projects.ids" type="checkbox" :value="p.id" />{{ p.name }}</label></div>
            <p class="er-hint">{{ content.projects.ids.length }} {{ $t('selected') }} <button type="button" class="er-link-btn" @click="content.projects.ids = projectOptions.map(p => p.id)">{{ $t('Select all') }}</button> <button type="button" class="er-link-btn" @click="content.projects.ids = []">{{ $t('Clear') }}</button></p>
          </div>
        </section>

        <section class="lg-card er-scope">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="collection" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Which items') }}</h2><p class="lg-card-sub">{{ $t('Stock value, consumption and the materials list are limited to these items.') }}</p></div></header>
          <div class="er-modes"><label :class="{ on: content.items.mode === 'all' }"><input v-model="content.items.mode" type="radio" value="all" />{{ $t('All items') }}</label><label :class="{ on: content.items.mode === 'selected' }"><input v-model="content.items.mode" type="radio" value="selected" />{{ $t('Only selected items') }}</label></div>
          <div v-if="content.items.mode === 'selected'" class="er-projects">
            <auto-complete id="er_pick_item" json keep-focus reset-search :suggestions="route('items.search')" :placeholder="$t('Search an item to add')" @update:model-value="itemPicked" />
            <div class="er-plist"><span v-for="it in pickedItems" :key="it.id" class="er-pill">{{ it.name }} <em v-if="it.code">{{ it.code }}</em><button type="button" :aria-label="$t('Remove')" @click="removeItem(it.id)">×</button></span><span v-if="!pickedItems.length" class="er-hint">{{ $t('No items chosen yet. Nothing will be counted until you add some.') }}</span></div>
          </div>
        </section>

        <section class="lg-card er-scope">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('How many records') }}</h2><p class="lg-card-sub">{{ $t('How many rows each list shows, and when something counts as stuck.') }}</p></div></header>
          <div class="er-fields">
            <div v-for="l in limitFields" :key="l.key"><label class="er-label">{{ $t(l.label) }}</label><input v-model.number="content.limits[l.key]" type="number" min="1" max="50" class="er-input" /></div>
          </div>
        </section>
      </template>

      <!-- Subscribers tab -->
      <template v-if="activeTab === 'subscribers'">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="users" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Add Subscriber') }}</h2>
              <p class="lg-card-sub">{{ $t('Management personnel who receive the weekly briefing') }}</p>
            </div>
          </header>
          <div class="er-pick">
            <auto-complete id="er_pick_user" v-model="pickedUser" :suggestions="pickableUsers" reset-search :placeholder="$t('Search a user to add as a subscriber')" />
            <p class="er-hint">{{ $t('Subscribers are WIMS users. They get the report at the email on their account. Remove anyone any time and add more.') }} · {{ pickableUsers.length }} {{ $t('users available') }}</p>
          </div>
        </section>

        <section class="lg-card" style="padding: 1rem">
          <div class="lg-table-wrap">
            <table class="lg-table lg-responsive">
              <thead>
                <tr>
                  <th>{{ $t('Name') }}</th>
                  <th>{{ $t('Email') }}</th>
                  <th>{{ $t('Active') }}</th>
                  <th>{{ $t('Added') }}</th>
                  <th class="num lg-sticky-end"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="sub in subscribersList" :key="sub.id">
                  <td :data-label="$t('Name')">
                    <div class="lg-person">
                      <span class="lg-avatar">{{ initials(sub.user ? sub.user.name : sub.name) }}</span>
                      <Link v-if="sub.user" :href="route('users.edit', sub.user.id)" class="lg-main er-link">{{ sub.user.name }}</Link>
                      <span v-else class="lg-main">{{ sub.name }} <small class="er-ext">{{ $t('outside email') }}</small></span>
                    </div>
                  </td>
                  <td :data-label="$t('Email')">
                    <span>{{ sub.user && sub.user.email ? sub.user.email : sub.email }}</span>
                  </td>
                  <td :data-label="$t('Active')">
                    <label v-if="editId === sub.id" class="er-check">
                      <input type="checkbox" v-model="editForm.active" />
                      <span>{{ editForm.active ? $t('Yes') : $t('No') }}</span>
                    </label>
                    <span v-else class="lg-chip" :class="sub.active ? 'lg-chip-green' : 'lg-chip-slate'">
                      <icons :name="sub.active ? 'tick' : 'cross'" class="w-3 h-3" />{{ sub.active ? $t('Yes') : $t('No') }}
                    </span>
                  </td>
                  <td :data-label="$t('Added')"><span class="lg-subtext" style="margin-top: 0">{{ formatDate(sub.created_at) }}</span></td>
                  <td :data-label="$t('Actions')" class="num lg-sticky-end">
                    <div class="lg-actions">
                      <template v-if="editId === sub.id">
                        <button type="button" class="lg-icon-btn is-green lg-tip" :data-tip="$t('Save')" :aria-label="$t('Save')" @click="saveEdit(sub)"><icons name="tick" class="w-4 h-4" /></button>
                        <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Cancel')" :aria-label="$t('Cancel')" @click="cancelEdit"><icons name="cross" class="w-4 h-4" /></button>
                      </template>
                      <template v-else>
                        <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Edit')" :aria-label="$t('Edit')" @click="startEdit(sub)"><icons name="edit" class="w-4 h-4" /></button>
                        <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Delete')" :aria-label="$t('Delete')" @click="remove(sub)"><icons name="trash" class="w-4 h-4" /></button>
                      </template>
                    </div>
                  </td>
                </tr>
                <tr v-if="subscribersList.length === 0" class="lg-empty-row">
                  <td colspan="5" class="lg-empty">{{ $t('No subscribers yet.') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </template>

      <!-- Logs tab -->
      <section v-if="activeTab === 'logs'" class="lg-card" style="padding: 1rem">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Week') }}</th>
                <th class="num">{{ $t('Recipients') }}</th>
                <th>{{ $t('Status') }}</th>
                <th>{{ $t('Sent At') }}</th>
                <th>{{ $t('Notes') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="log in logs" :key="log.id">
                <td :data-label="$t('Week')" class="lg-main">{{ log.week_start }} &rarr; {{ log.week_end }}</td>
                <td :data-label="$t('Recipients')" class="num">{{ log.recipient_count }}</td>
                <td :data-label="$t('Status')">
                  <span class="lg-chip" :class="log.status === 'sent' ? 'lg-chip-green' : 'lg-chip-red'">
                    <icons :name="log.status === 'sent' ? 'tick' : 'cross'" class="w-3 h-3" />{{ log.status }}
                  </span>
                </td>
                <td :data-label="$t('Sent At')"><span class="lg-subtext" style="margin-top: 0">{{ log.sent_at || '—' }}</span></td>
                <td :data-label="$t('Notes')">
                  <span v-if="log.notes" class="lg-clip lg-tip" style="display: inline-block; vertical-align: bottom; font-size: 0.78rem; color: var(--lg-mute)" :data-tip="log.notes">{{ log.notes }}</span>
                  <span v-else class="lg-subtext">—</span>
                </td>
              </tr>
              <tr v-if="logs.length === 0" class="lg-empty-row">
                <td colspan="5" class="lg-empty">{{ $t('No reports sent yet.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';

export default {
  components: { AdminLayout, PageHeader, StatTiles, AutoComplete },

  props: {
    subscribers: Array,
    logs: Array,
    reportSettings: Object,
    contentConfig: Object,
    catalogue: Array,
    transactionTypes: Array,
    projectOptions: Array,
    selectedItems: Array,
    userOptions: Array,
  },

  data() {
    return {
      activeTab: 'settings',
      tabs: [
        { key: 'settings', label: this.$t('Settings'), icon: 'cog' },
        { key: 'content', label: this.$t('Report Content'), icon: 'collection' },
        { key: 'subscribers', label: this.$t('Subscribers'), icon: 'users' },
        { key: 'logs', label: this.$t('Send Logs'), icon: 'doc-text' },
      ],
      days: ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
      settingsForm: { day: this.reportSettings.day, time: this.reportSettings.time, enabled: this.reportSettings.enabled },
      sendingTest: false,
      form: { name: '', email: '' },
      editId: null,
      editForm: { name: '', email: '', active: true },
      subscribersList: [...this.subscribers],
      content: JSON.parse(JSON.stringify(this.contentConfig)),
      savingContent: false,
      projectSearch: '',
      pickedUser: '',
      pickedItems: [...(this.selectedItems || [])],
      limitFields: [
        { key: 'top_projects', label: 'Top consuming projects shown' },
        { key: 'top_materials', label: 'Top used materials shown' },
        { key: 'stale_days', label: 'Days before a request counts as stuck' },
        { key: 'critical_items', label: 'Out-of-stock items listed' },
        { key: 'boq_projects', label: 'BOQ variance projects listed' },
      ],
    };
  },

  watch: {
    // choosing a user in the box adds them straight away, then the box is ready for the next one
    pickedUser(id) {
      if (!id) return;
      this.$inertia.post(this.route('executive-report.subscribers.store'), { user_ids: [id] }, { preserveScroll: true, onFinish: () => { this.pickedUser = ''; } });
    },
    // keep the table in sync after add / update / delete round-trips
    subscribers(value) {
      this.subscribersList = [...value];
    },
  },

  computed: {
    statItems() {
      return [
        { icon: 'users', tone: 'emerald', label: this.$t('Active Subscribers'), value: this.activeSubscriberCount },
        { icon: 'clock', tone: this.settingsForm.enabled ? 'navy' : 'slate', label: this.$t('Next Scheduled Send'), value: this.nextSendLabel },
        {
          icon: this.lastLog ? (this.lastLog.status === 'sent' ? 'tick' : 'exclamation') : 'doc-text',
          tone: this.lastLog ? (this.lastLog.status === 'sent' ? 'teal' : 'rose') : 'slate',
          label: this.$t('Last Report'),
          value: this.lastLog ? this.$t(this.lastLog.status) : this.$t('None yet'),
        },
        { icon: 'collection', tone: 'indigo', label: this.$t('Reports Sent'), value: this.logs.length },
      ];
    },
    pickableUsers() {
      const taken = new Set(this.subscribersList.map(s => s.user_id).filter(Boolean));
      return this.userOptions.filter(u => !taken.has(u.id)).map(u => ({ id: u.id, label: u.label }));
    },
    filteredProjects() {
      const q = this.projectSearch.trim().toLowerCase();
      return this.projectOptions.filter(p => !q || p.name.toLowerCase().includes(q));
    },
    activeSubscriberCount() {
      return this.subscribersList.filter(sub => sub.active).length;
    },
    lastLog() {
      return this.logs && this.logs.length ? this.logs[0] : null;
    },
    nextSendLabel() {
      const day = this.settingsForm.day ? this.settingsForm.day.charAt(0).toUpperCase() + this.settingsForm.day.slice(1) : '';
      return this.settingsForm.enabled ? `${this.$t(day)} ${this.settingsForm.time}` : this.$t('Paused');
    },
  },

  methods: {
    saveSettings() {
      this.$inertia.put(this.route('executive-report.settings.update'), this.settingsForm, { preserveScroll: true });
    },
    sendTest() {
      this.sendingTest = true;
      this.$inertia.post(this.route('executive-report.send-test'), {}, {
        preserveScroll: true,
        onFinish: () => { this.sendingTest = false; },
      });
    },
    setGroup(g, on) { g.items.forEach(it => { this.content.sections[it.key] = on; }); },
    itemPicked(it) {
      if (!it || typeof it !== 'object' || !it.id) return;
      if (!this.content.items.ids.includes(it.id)) { this.content.items.ids.push(it.id); this.pickedItems.push({ id: it.id, name: it.name, code: it.code }); }
    },
    removeItem(id) {
      this.content.items.ids = this.content.items.ids.filter(x => x !== id);
      this.pickedItems = this.pickedItems.filter(x => x.id !== id);
    },
    saveContent(thenPreview) {
      this.savingContent = true;
      this.$inertia.put(this.route('executive-report.content.update'), this.content, {
        preserveScroll: true,
        onSuccess: () => { if (thenPreview) window.open(this.route('executive-report.preview'), '_blank'); },
        onFinish: () => { this.savingContent = false; },
      });
    },
    addSubscriber() {
      this.$inertia.post(this.route('executive-report.subscribers.store'), this.form, {
        onSuccess: () => { this.form = { name: '', email: '' }; },
        preserveScroll: true,
      });
    },
    startEdit(sub) {
      this.editId = sub.id;
      this.editForm = { name: sub.name, email: sub.email, active: sub.active };
    },
    cancelEdit() {
      this.editId = null;
    },
    saveEdit(sub) {
      this.$inertia.put(this.route('executive-report.subscribers.update', sub.id), this.editForm, {
        onSuccess: () => { this.editId = null; },
        preserveScroll: true,
      });
    },
    async remove(sub) {
      if (!(await this.$confirm(this.$t('Remove this subscriber?'), { variant: 'danger' }))) return;
      this.$inertia.delete(this.route('executive-report.subscribers.destroy', sub.id), { preserveScroll: true });
    },
    initials(name) {
      return (name || '?').toString().trim().split(/\s+/).slice(0, 2).map(w => w.charAt(0).toUpperCase()).join('');
    },
    tabCount(key) {
      if (key === 'subscribers') return this.subscribersList.length;
      if (key === 'logs') return this.logs.length;
      return null;
    },
    formatDate(dt) {
      if (!dt) return '—';
      return new Date(dt).toLocaleDateString();
    },
  },
};
</script>

<style scoped>
.er-tabs { display: inline-flex; flex-wrap: wrap; gap: 4px; max-width: 100%; margin-bottom: 1rem; padding: 4px; border-radius: 9999px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); }
.er-tab { display: inline-flex; align-items: center; gap: 7px; padding: 8px 16px; border-radius: 9999px; font-size: 0.8rem; font-weight: 800; color: var(--lg-mute); transition: background 0.15s ease, color 0.15s ease; }
.er-tab:hover:not(.is-active) { color: var(--lg-ink); background: var(--lg-soft); }
.er-tab.is-active { color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.9); }
.er-tab-count { min-width: 20px; padding: 0 6px; border-radius: 9999px; font-size: 0.68rem; line-height: 18px; text-align: center; color: var(--lg-mute); background: var(--lg-soft); }
.er-tab.is-active .er-tab-count { color: #fff; background: rgba(255, 255, 255, 0.2); }

.er-narrow { max-width: 760px; }

.er-toggle-row { display: flex; align-items: center; gap: 12px; padding: 0.85rem 1rem; border-radius: 16px; background: var(--lg-soft); border: 1px solid var(--lg-line); transition: border-color 0.15s ease, background 0.15s ease; }
.er-toggle-row.is-on { border-color: rgba(16, 185, 129, 0.45); background: rgba(16, 185, 129, 0.07); }
.er-toggle-row > .min-w-0 { flex: 1; }
.er-toggle-title { font-size: 0.9rem; font-weight: 800; color: var(--lg-ink); }
.er-toggle-sub { margin-top: 1px; font-size: 0.76rem; color: var(--lg-mute); }
.er-switch { position: relative; flex: none; width: 46px; height: 26px; border-radius: 9999px; background: #cbd5e1; transition: background 0.2s ease; }
html.dark .er-switch { background: #475569; }
.er-switch.is-on { background: linear-gradient(145deg, #34d399, #047857); }
.er-switch:focus-visible { outline: 2px solid #3b82f6; outline-offset: 2px; }
.er-switch-knob { position: absolute; top: 3px; left: 3px; width: 20px; height: 20px; border-radius: 9999px; background: #fff; box-shadow: 0 2px 5px rgba(15, 23, 42, 0.3); transition: transform 0.2s ease; }
.er-switch.is-on .er-switch-knob { transform: translateX(20px); }

.er-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr)); gap: 14px; margin-top: 1.1rem; }
.er-fields > * { min-width: 0; }
.er-label { display: block; margin-bottom: 6px; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute); }
.er-input { width: 100%; min-width: 0; padding: 0.55rem 0.85rem; border-radius: 12px; font-size: 0.86rem; color: var(--lg-ink); background: var(--lg-soft); border: 1.5px solid var(--lg-line); transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease; }
.er-input::placeholder { color: var(--lg-faint); }
.er-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18); background: var(--lg-card); }
.er-input-sm { padding: 0.35rem 0.65rem; border-radius: 10px; font-size: 0.82rem; min-width: 150px; }
html.dark .er-input { color-scheme: dark; }
.er-btn-row { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 1.4rem; }

.er-add { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto; gap: 12px; align-items: center; }
.er-link { color: var(--lg-navy); }
html.dark .er-link { color: #93c5fd; }
.er-link:hover { text-decoration: underline; }
.er-check { display: inline-flex; align-items: center; gap: 8px; font-size: 0.8rem; font-weight: 700; cursor: pointer; }
.er-check input { width: 16px; height: 16px; accent-color: #047857; }


.er-content-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
.er-groups { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(320px, 100%), 1fr)); gap: 14px; margin: 14px 0; }
.er-group { padding: 1rem 1.1rem; } .er-group-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; } .er-group-head h3 { margin: 0; font-size: 0.82rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-navy); } .er-group-head span { display: inline-flex; gap: 4px; } .er-group-head button { padding: 2px 9px; border-radius: 99px; font-size: 0.68rem; font-weight: 800; color: var(--lg-mute); background: var(--lg-soft); } .er-group-head button:hover { color: var(--lg-ink); }
.er-opt { display: flex; align-items: flex-start; gap: 10px; padding: 8px 6px; border-radius: 12px; cursor: pointer; transition: background 0.15s; } .er-opt:hover { background: var(--lg-soft); } .er-opt input { position: absolute; opacity: 0; }
.er-opt i { flex: none; position: relative; width: 38px; height: 22px; margin-top: 1px; border-radius: 99px; background: #cbd5e1; transition: background 0.2s; } .er-opt i::after { content: ''; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3); transition: transform 0.2s; } .er-opt input:checked + i { background: linear-gradient(145deg, #34d399, #047857); } .er-opt input:checked + i::after { transform: translateX(16px); } .er-opt input:focus-visible + i { outline: 2px solid #3b82f6; outline-offset: 2px; }
.er-opt b { display: block; font-size: 0.85rem; color: var(--lg-ink); } .er-opt small { display: block; font-size: 0.74rem; color: var(--lg-mute); line-height: 1.35; } .er-opt:not(.on) b { color: var(--lg-mute); }
.er-tx { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 8px; padding-top: 8px; border-top: 1px dashed var(--lg-line); } .er-tx small { width: 100%; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-mute); }
.er-chip { display: inline-flex; align-items: center; padding: 5px 12px; border-radius: 99px; font-size: 0.78rem; font-weight: 700; cursor: pointer; color: var(--lg-mute); background: var(--lg-soft); box-shadow: inset 0 0 0 1px var(--lg-line); transition: all 0.15s; } .er-chip input { position: absolute; opacity: 0; } .er-chip.on { color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); box-shadow: 0 6px 12px -8px rgba(15, 37, 64, 0.9); }
.er-scope { margin-bottom: 14px; } .er-modes { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; } .er-modes label { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 12px; font-size: 0.82rem; font-weight: 700; cursor: pointer; background: var(--lg-soft); box-shadow: inset 0 0 0 1.5px var(--lg-line); } .er-modes label.on { box-shadow: inset 0 0 0 2px #2f6fb3; color: var(--lg-navy); }
.er-projects { display: grid; gap: 10px; } .er-plist { display: flex; flex-wrap: wrap; gap: 6px; max-height: 240px; overflow: auto; } .er-hint { margin: 0; font-size: 0.76rem; color: var(--lg-mute); } .er-link-btn { margin-left: 6px; font-weight: 800; color: #2f6fb3; }
.er-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 6px 4px 12px; border-radius: 99px; font-size: 0.78rem; font-weight: 700; color: #065f46; background: #d1fae5; } .er-pill em { font-style: normal; font-size: 0.68rem; opacity: 0.7; } .er-pill button { width: 20px; height: 20px; border-radius: 50%; background: rgba(0, 0, 0, 0.12); color: inherit; line-height: 1; } .er-pill button:hover { background: rgba(0, 0, 0, 0.25); }
.er-pick { max-width: 560px; } .er-pick .mb-2 { margin-bottom: 4px; } .er-ext { margin-left: 6px; padding: 1px 7px; border-radius: 99px; font-size: 0.65rem; font-weight: 800; color: #9a3412; background: #ffedd5; }

@media (max-width: 767.98px) {
  .er-tabs { display: flex; width: 100%; border-radius: 20px; }
  .er-tab { flex: 1 1 auto; justify-content: center; padding: 8px 10px; }
  .er-add { grid-template-columns: minmax(0, 1fr); }
  .er-add .lg-btn { width: 100%; }
  .er-toggle-row { flex-wrap: wrap; }
  .er-input-sm { min-width: 0; width: 100%; max-width: 220px; }
}
@media print { .er-page .lg-card { box-shadow: none; } }
</style>

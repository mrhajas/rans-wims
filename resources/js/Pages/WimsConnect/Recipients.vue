<template>
  <admin-layout :title="$t('WIMS Connect / Recipients')">
    <div class="lg-page px-4 md:px-0 ca-page">
      <page-header icon="users" :title="$t('Recipients')" :subtitle="$t('The contact book. Every module\'s workflow page sends alerts to the details kept here.')">
        <Link :href="route('wims-connect.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-3.5 h-3.5 transform rotate-90" />
          {{ $t('WIMS Connect') }}
        </Link>
      </page-header>

      <!-- how it works -->
      <p class="wr-note">
        <icons name="info" class="w-4 h-4 flex-none" />
        <span>{{ $t('Details are pulled from each person\'s login and staff record. A pulled detail stays Off and receives nothing until you switch it on. People without a number or email are skipped quietly when a message is sent.') }}</span>
      </p>

      <!-- summary -->
      <div class="lg-stats lg-stats-tight">
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-navy"><icons name="users" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value">{{ users.length }}</div><div class="lg-stat-label">{{ $t('People') }}</div></div>
        </div>
        <div v-for="c in channelDefs" :key="c.key" class="lg-stat">
          <span class="lg-stat-ico lg-ico-navy"><icons :name="c.icon" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-stat-value" :class="{ 'is-good': stats[c.key].on > 0 }">{{ stats[c.key].on }}<small class="wr-of"> / {{ users.length }}</small></div>
            <div class="lg-stat-label">{{ c.label }} {{ $t('on') }}</div>
          </div>
        </div>
      </div>

      <!-- waiting to be enabled -->
      <section v-if="waitingTotal" class="wr-wait">
        <span class="wr-wait__badge"><icons name="clock" class="w-6 h-6" /><i>{{ waitingTotal }}</i></span>
        <div class="wr-wait__copy">
          <b>{{ waitingTotal }} {{ waitingTotal === 1 ? $t('pulled detail is waiting to be switched on') : $t('pulled details are waiting to be switched on') }}</b>
          <span>{{ $t('WIMS found these on the login and staff records. Nothing is sent to them until you switch them on.') }}</span>
        </div>
        <div class="wr-wait__acts">
          <button v-for="c in channelDefs.filter(x => stats[x.key].waiting)" :key="c.key" type="button" class="wr-act" :disabled="busy" @click="enableWaiting(c)">
            <span class="wr-act__ico"><icons :name="c.icon" class="w-4 h-4" /></span>
            <span class="wr-act__t"><b>{{ c.label }}</b><small>{{ stats[c.key].waiting }} {{ $t('waiting') }}</small></span>
            <span class="wr-act__go">{{ $t('Switch all on') }}</span>
          </button>
        </div>
      </section>

      <!-- filters -->
      <section class="lg-card lg-filters wr-filters">
        <div class="ca-search">
          <icons name="search" class="w-4 h-4 text-gray-400 flex-none" />
          <input v-model="search" type="text" class="ca-search-input" :placeholder="$t('Search name or email..')" />
        </div>
        <div ref="roleBox" class="wr-combo" :class="{ open: roleOpen }">
          <button type="button" class="wr-combo__btn" aria-haspopup="listbox" :aria-expanded="roleOpen" @click="toggleRole">
            <icons name="user" class="w-4 h-4 flex-none" />
            <span class="truncate">{{ role || $t('All roles') }}</span>
            <icons name="chevron-down" class="w-3.5 h-3.5 flex-none wr-combo__chev" />
          </button>
          <div v-if="roleOpen" class="wr-combo__panel" role="listbox">
            <div class="wr-combo__search">
              <icons name="search" class="w-3.5 h-3.5 flex-none" />
              <input ref="roleSearch" v-model="roleQuery" type="text" :placeholder="$t('Search roles..')" @keydown.esc="roleOpen = false" @keydown.enter.prevent="pickFirstRole" />
            </div>
            <ul>
              <li v-if="!roleQuery.trim()"><button type="button" :class="{ on: !role }" @click="pickRole('')">{{ $t('All roles') }}<i>{{ users.length }}</i></button></li>
              <li v-for="r in matchingRoles" :key="r"><button type="button" :class="{ on: role === r }" @click="pickRole(r)">{{ r }}<i>{{ roleCounts[r] }}</i></button></li>
              <li v-if="!matchingRoles.length" class="wr-combo__none">{{ $t('No role matches.') }}</li>
            </ul>
          </div>
        </div>
        <div class="wr-seg" role="group" :aria-label="$t('Show')">
          <button v-for="f in filters" :key="f.key" type="button" :class="{ on: status === f.key }" @click="status = f.key">{{ f.label }}<i>{{ f.count }}</i></button>
        </div>
      </section>

      <section class="lg-card wr-book">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive wr-table">
            <thead>
              <tr>
                <th>{{ $t('Person') }}</th>
                <th>{{ $t('Project') }}</th>
                <th v-for="c in channelDefs" :key="c.key"><channel-header-label :live="channelLive[c.key]" :label="c.label" /></th>
                <th>{{ $t('Reach') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="user in filteredUsers" :key="user.id" class="is-click" :class="{ 'is-inactive': !user.active }" tabindex="0" @click="goto(user.id)" @keyup.enter="goto(user.id)">
                <td :data-label="$t('Person')">
                  <div class="lg-person">
                    <span class="wr-avatar" :class="'t' + (user.id % 6)">{{ initials(user.name) }}</span>
                    <div class="min-w-0 text-left">
                      <div class="wr-name truncate">{{ user.name }}<em v-if="!user.active" class="wr-off-tag">{{ $t('Inactive') }}</em></div>
                      <div class="wr-roles"><span v-for="(r, i) in user.roles" :key="i" class="wr-role">{{ r }}</span><span v-if="!user.roles.length" class="wr-sub">{{ user.email }}</span></div>
                    </div>
                  </div>
                </td>
                <td :data-label="$t('Project')">
                  <div v-if="user.warehouses.length" class="wr-proj lg-tip" tabindex="0" :data-tip="projectTip(user)" :aria-label="user.warehouses.join(', ')">
                    <span class="wr-proj__ico"><icons name="building" class="w-3.5 h-3.5" /></span>
                    <span class="wr-proj__name">{{ user.warehouses[0] }}</span>
                    <b v-if="user.warehouses.length > 1" class="wr-proj__n">+{{ user.warehouses.length - 1 }}</b>
                  </div>
                  <span v-else class="wr-proj wr-proj--none">{{ $t('No project') }}</span>
                </td>
                <td v-for="c in channelDefs" :key="c.key" :data-label="c.label" class="wr-cell-td">
                  <div v-if="cell(user, c.key).address" class="wr-cell" :class="'is-' + cellState(user, c.key)">
                    <span class="wr-cell__t">
                      <span class="wr-cell__a" :title="cell(user, c.key).address">{{ cell(user, c.key).address }}</span>
                      <small><i class="wr-dot"></i>{{ stateLabel(user, c.key) }}</small>
                    </span>
                    <button type="button" class="wr-sw" role="switch" :aria-checked="cell(user, c.key).is_enabled" :aria-label="c.label + ': ' + user.name" :disabled="busy" @click.stop="toggle(user, c)"><span class="wr-knob"></span></button>
                  </div>
                  <span v-else class="wr-none">{{ $t('No detail') }}</span>
                </td>
                <td :data-label="$t('Reach')" class="wr-reach-td">
                  <div class="wr-reach" :title="reachCount(user) + ' / 4'">
                    <span class="wr-bars"><i v-for="c in channelDefs" :key="c.key" :class="{ on: cell(user, c.key).is_enabled && cell(user, c.key).address }"></i></span>
                    <small>{{ reachCount(user) }}<span> / 4</span></small>
                  </div>
                </td>
                <td class="ca-chev">
                  <icons name="chevron-down" class="transform -rotate-90 block w-4 h-4 text-gray-400" />
                </td>
              </tr>
              <tr v-if="!filteredUsers.length" class="lg-empty-row">
                <td class="lg-empty" colspan="8">{{ $t('No matching users.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { h } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Icons from '@/Shared/Icons.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

const ChannelHeaderLabel = {
  props: { live: Boolean, label: String },
  render() {
    const tip = this.live ? this.$t('Channel is live') : this.$t('Channel is off or not connected');
    return h('span', { class: 'inline-flex items-center gap-1.5 lg-tip ca-tip-down', 'data-tip': tip, 'aria-label': tip }, [
      this.label,
      h('span', { class: ['inline-block h-1.5 w-1.5 rounded-full', this.live ? 'bg-emerald-500' : 'bg-gray-300'] }),
    ]);
  },
};

// the four channels of the contact book, in the order they are shown; `field` is the key of the row data
const CHANNELS = [
  { key: 'sms', field: 'sms', icon: 'sms', ico: 'lg-ico-navy' },
  { key: 'email', field: 'email_contact', icon: 'email', ico: 'lg-ico-amber' },
  { key: 'whatsapp', field: 'whatsapp', icon: 'whatsapp', ico: 'lg-ico-emerald' },
  { key: 'meta', field: 'meta', icon: 'meta', ico: 'lg-ico-indigo' },
];

export default {
  components: { AdminLayout, Link, ChannelHeaderLabel, PageHeader },

  props: {
    users: { type: Array, default: () => [] },
    channelLive: { type: Object, default: () => ({}) },
  },

  data() {
    return { search: '', role: '', status: 'all', busy: false, roleOpen: false, roleQuery: '' };
  },

  computed: {
    channelDefs() {
      const labels = { sms: this.$t('Mobile (SMS)'), email: this.$t('Email'), whatsapp: this.$t('WhatsApp'), meta: this.$t('Meta') };
      return CHANNELS.map(c => ({ ...c, label: labels[c.key] }));
    },
    // per channel: how many people have it switched on, and how many pulled details are still waiting
    stats() {
      const out = {};
      CHANNELS.forEach(c => {
        const cells = this.users.map(u => u[c.field] || {});
        out[c.key] = { on: cells.filter(x => x.address && x.is_enabled).length, waiting: this.users.filter(u => u.active && (u[c.field] || {}).address && !u[c.field].is_enabled && u[c.field].pulled).length };
      });
      return out;
    },
    waitingTotal() { return Object.values(this.stats).reduce((a, s) => a + s.waiting, 0); },
    roleOptions() { return [...new Set(this.users.flatMap(u => u.roles))].sort(); },
    roleCounts() {
      const out = {};
      this.users.forEach(u => u.roles.forEach(r => { out[r] = (out[r] || 0) + 1; }));
      return out;
    },
    matchingRoles() {
      const q = this.roleQuery.trim().toLowerCase();
      return this.roleOptions.filter(r => !q || r.toLowerCase().includes(q));
    },
    filters() {
      const count = key => this.users.filter(u => this.matchesStatus(u, key)).length;
      return [
        { key: 'all', label: this.$t('All'), count: this.users.length },
        { key: 'reachable', label: this.$t('Reachable'), count: count('reachable') },
        { key: 'waiting', label: this.$t('Not switched on'), count: count('waiting') },
        { key: 'none', label: this.$t('No detail'), count: count('none') },
        { key: 'inactive', label: this.$t('Inactive'), count: count('inactive') },
      ];
    },
    filteredUsers() {
      const q = this.search.trim().toLowerCase();
      return this.users.filter(u => {
        if (q && !((u.name || '').toLowerCase().includes(q) || (u.email || '').toLowerCase().includes(q))) return false;
        if (this.role && !u.roles.includes(this.role)) return false;
        return this.matchesStatus(u, this.status);
      });
    },
  },

  mounted() { document.addEventListener('click', this.closeRoleOnOutside); },
  beforeUnmount() { document.removeEventListener('click', this.closeRoleOnOutside); },

  methods: {
    closeRoleOnOutside(e) {
      if (this.roleOpen && this.$refs.roleBox && !this.$refs.roleBox.contains(e.target)) this.roleOpen = false;
    },
    toggleRole() {
      this.roleOpen = !this.roleOpen;
      this.roleQuery = '';
      if (this.roleOpen) this.$nextTick(() => this.$refs.roleSearch && this.$refs.roleSearch.focus());
    },
    pickRole(r) {
      this.role = r;
      this.roleOpen = false;
    },
    pickFirstRole() {
      if (this.matchingRoles.length) this.pickRole(this.matchingRoles[0]);
    },
    projectTip(user) {
      return user.warehouses.map(w => '\u2022 ' + w).join('\n');
    },
    initials(name) {
      return (name || '').split(' ').filter(Boolean).slice(0, 2).map(p => p[0].toUpperCase()).join('');
    },
    goto(id) {
      this.$inertia.visit(this.route('wims-connect.recipients.show', id));
    },
    reachCount(user) {
      return CHANNELS.filter(c => (user[c.field] || {}).address && user[c.field].is_enabled).length;
    },
    cell(user, key) {
      return user[CHANNELS.find(c => c.key === key).field] || {};
    },
    // on (and the channel works) = live; on but the channel cannot deliver = stale; every switched-off detail is simply off
    cellState(user, key) {
      const c = this.cell(user, key);
      if (c.is_enabled) return c.live ? 'live' : 'stale';
      return 'off';
    },
    stateLabel(user, key) {
      const name = this.channelDefs.find(c => c.key === key).label;
      return { live: this.$t('On'), stale: this.$t('On, but') + ' ' + name + ' ' + this.$t('is not connected'), off: this.$t('Off') }[this.cellState(user, key)];
    },
    matchesStatus(u, key) {
      const cells = CHANNELS.map(c => u[c.field] || {}).filter(c => c.address);
      if (key === 'reachable') return cells.some(c => c.is_enabled);
      if (key === 'waiting') return u.active && cells.some(c => !c.is_enabled && c.pulled);
      if (key === 'none') return !cells.length;
      if (key === 'inactive') return !u.active;
      return true;
    },
    toggle(user, c) {
      const on = !this.cell(user, c.key).is_enabled;
      this.busy = true;
      this.$inertia.put(this.route('wims-connect.recipients.toggle', user.id), { channel: c.key, is_enabled: on }, { preserveScroll: true, preserveState: true, onFinish: () => { this.busy = false; } });
    },
    enableWaiting(c) {
      if (!window.confirm(this.$t('Switch on every waiting') + ' ' + c.label + ' ' + this.$t('detail? Those people will start receiving alerts on this channel.'))) return;
      this.busy = true;
      this.$inertia.post(this.route('wims-connect.recipients.enable-waiting'), { channel: c.key }, { preserveScroll: true, preserveState: true, onFinish: () => { this.busy = false; } });
    },
  },
};
</script>

<style scoped>
/* palette: navy for identity, green for "on", amber only for things that need attention, slate for everything else */
.wr-note { display: flex; gap: 0.55rem; align-items: flex-start; margin: 0 0 0.9rem; padding: 0.65rem 0.95rem; border-radius: 14px; font-size: 0.8rem; line-height: 1.45; color: var(--lg-mute); background: var(--lg-soft); box-shadow: inset 0 0 0 1px var(--lg-line); } .wr-note svg { margin-top: 2px; color: var(--lg-faint); }
.wr-of { font-size: 0.78rem; font-weight: 700; color: var(--lg-faint); }

/* the "waiting to be switched on" bar */
.wr-wait { display: flex; flex-wrap: wrap; align-items: center; gap: 0.9rem 1.2rem; margin-bottom: 0.9rem; padding: 0.9rem 1.1rem; border-radius: 20px; background: rgba(245, 158, 11, 0.09); box-shadow: inset 0 0 0 1px rgba(245, 158, 11, 0.3); }
.wr-wait__badge { position: relative; flex: none; display: inline-flex; align-items: center; justify-content: center; width: 46px; height: 46px; border-radius: 15px; color: #fff; background: #d97706; }
.wr-wait__badge i { position: absolute; top: -7px; right: -7px; min-width: 21px; height: 21px; padding: 0 5px; display: inline-flex; align-items: center; justify-content: center; border-radius: 9999px; font-style: normal; font-size: 0.7rem; font-weight: 800; color: #92400e; background: var(--lg-card); box-shadow: 0 0 0 2px #fbbf24; }
.wr-wait__copy { display: grid; gap: 2px; flex: 1 1 260px; min-width: 0; } .wr-wait__copy b { font-size: 0.95rem; font-weight: 800; color: var(--lg-ink); } .wr-wait__copy span { font-size: 0.78rem; line-height: 1.4; color: var(--lg-mute); }
.wr-wait__acts { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.wr-act { display: inline-flex; align-items: center; gap: 0.7rem; padding: 0.3rem 0.35rem 0.3rem 0.85rem; border: 0; border-radius: 9999px; cursor: pointer; font-family: inherit; background: var(--lg-card); color: var(--lg-ink); box-shadow: 0 0 0 1px var(--lg-line); transition: box-shadow 0.15s; } .wr-act:hover:not(:disabled) { box-shadow: 0 0 0 1px #10b981; } .wr-act:disabled { opacity: 0.55; cursor: wait; }
.wr-act__t { display: grid; text-align: left; line-height: 1.15; } .wr-act__t b { font-size: 0.8rem; font-weight: 800; } .wr-act__t small { font-size: 0.68rem; font-weight: 700; color: var(--lg-faint); }
.wr-act__go { padding: 0.4rem 0.85rem; border-radius: 9999px; font-size: 0.74rem; font-weight: 800; color: #fff; background: #059669; }

/* filters */
.wr-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 0.7rem; }
.wr-seg { display: inline-flex; border-radius: 9999px; padding: 3px; background: var(--lg-line); margin-left: auto; } .wr-seg button { border: 0; background: transparent; padding: 0.38rem 0.8rem; border-radius: 9999px; font-size: 0.8rem; font-weight: 700; color: var(--lg-mute); cursor: pointer; display: inline-flex; gap: 0.4rem; align-items: center; font-family: inherit; } .wr-seg button i { font-style: normal; font-size: 0.7rem; opacity: 0.7; font-variant-numeric: tabular-nums; } .wr-seg button.on { background: var(--lg-card); color: var(--lg-ink); box-shadow: 0 1px 3px rgba(15, 23, 42, 0.18); }
.wr-combo { position: relative; }
.wr-combo__btn { display: inline-flex; align-items: center; gap: 0.5rem; min-width: 170px; max-width: 240px; padding: 0.5rem 0.85rem; border: 1px solid var(--lg-line); border-radius: 9999px; font-size: 0.84rem; font-weight: 600; font-family: inherit; color: var(--lg-ink); background: var(--lg-card); cursor: pointer; } .wr-combo__btn svg:first-child { color: var(--lg-faint); } .wr-combo__chev { margin-left: auto; color: var(--lg-faint); transition: transform 0.15s; } .wr-combo.open .wr-combo__chev { transform: rotate(180deg); } .wr-combo.open .wr-combo__btn, .wr-combo__btn:focus-visible { border-color: #34d399; box-shadow: 0 0 0 3px rgba(52, 211, 153, 0.18); outline: none; }
.wr-combo__panel { position: absolute; z-index: 70; top: calc(100% + 6px); left: 0; width: 270px; padding: 6px; border-radius: 16px; background: var(--lg-card); box-shadow: 0 0 0 1px var(--lg-line), 0 18px 36px -14px rgba(15, 23, 42, 0.45); }
.wr-combo__search { display: flex; align-items: center; gap: 0.5rem; padding: 0.45rem 0.7rem; border-radius: 10px; background: var(--lg-soft); color: var(--lg-faint); } .wr-combo__search input { flex: 1; min-width: 0; border: 0 !important; outline: none; box-shadow: none !important; background: transparent; padding: 0 !important; font-size: 0.84rem; font-family: inherit; color: var(--lg-ink); }
.wr-combo__panel ul { list-style: none; margin: 6px 0 0; padding: 0; max-height: 240px; overflow-y: auto; }
.wr-combo__panel li button { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; width: 100%; padding: 0.45rem 0.7rem; border: 0; border-radius: 10px; background: transparent; font-size: 0.83rem; font-weight: 600; font-family: inherit; text-align: left; color: var(--lg-ink); cursor: pointer; } .wr-combo__panel li button:hover { background: var(--lg-soft); } .wr-combo__panel li button.on { background: rgba(16, 185, 129, 0.12); color: #047857; font-weight: 800; } .wr-combo__panel li button i { font-style: normal; font-size: 0.7rem; color: var(--lg-faint); font-variant-numeric: tabular-nums; }
.wr-combo__none { padding: 0.6rem 0.7rem; font-size: 0.8rem; color: var(--lg-faint); }

/* the book */
.wr-book { padding: 0.5rem 0.75rem 0.75rem; }
.wr-table { border-spacing: 0 6px; }
.wr-table, .wr-table * { font-family: inherit; }
.wr-table th { background: transparent; border-bottom: 0; padding: 8px 8px; }
.wr-table td { border-bottom: 0; padding: 10px 8px; background: var(--lg-card); }
.wr-table td:first-child { border-radius: 16px 0 0 16px; } .wr-table td:last-child { border-radius: 0 16px 16px 0; }
.wr-table tbody tr:hover td { background: var(--lg-soft); } .wr-table tbody tr:focus-visible { outline: 2px solid #10b981; outline-offset: -2px; border-radius: 16px; }
/* a deactivated person: maroon row, white text */
.wr-table tbody tr.is-inactive td, .wr-table tbody tr.is-inactive:hover td { background: #6b1020; color: #fff; }
.wr-table tbody tr.is-inactive .wr-name, .wr-table tbody tr.is-inactive .wr-sub, .wr-table tbody tr.is-inactive .wr-cell__a, .wr-table tbody tr.is-inactive .wr-cell small, .wr-table tbody tr.is-inactive .wr-reach small, .wr-table tbody tr.is-inactive .wr-reach small span, .wr-table tbody tr.is-inactive .wr-proj__name, .wr-table tbody tr.is-inactive .wr-none, .wr-table tbody tr.is-inactive .wr-proj--none { color: #fff; }
.wr-table tbody tr.is-inactive .wr-off-tag { color: #6b1020; background: #fff; }
.wr-table tbody tr.is-inactive .wr-avatar { background: rgba(255, 255, 255, 0.2); }
.wr-table tbody tr.is-inactive .wr-role, .wr-table tbody tr.is-inactive .wr-proj, .wr-table tbody tr.is-inactive .wr-cell { color: #fff; background: rgba(255, 255, 255, 0.12); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.28); }
.wr-table tbody tr.is-inactive .wr-cell.is-live, .wr-table tbody tr.is-inactive .wr-cell.is-stale { background: rgba(255, 255, 255, 0.12); }
.wr-table tbody tr.is-inactive .wr-proj__ico { color: #6b1020; }
.wr-table tbody tr.is-inactive .wr-proj__n { color: #6b1020; background: #fff; }
.wr-table tbody tr.is-inactive .wr-none, .wr-table tbody tr.is-inactive .wr-proj--none { background: transparent; border-color: rgba(255, 255, 255, 0.35); box-shadow: none; }
.wr-table tbody tr.is-inactive .wr-bars i { background: rgba(255, 255, 255, 0.3); } .wr-table tbody tr.is-inactive .wr-bars i.on { background: #fff; }
.wr-table tbody tr.is-inactive .ca-chev svg { color: #fff; }
.wr-cell-td { white-space: nowrap; }
.wr-avatar { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 14px; font-size: 0.78rem; font-weight: 800; color: #fff; background: linear-gradient(135deg, #1c3f66, #2f5f94); }
.wr-name { font-size: 0.9rem; font-weight: 800; color: var(--lg-ink); display: flex; align-items: center; gap: 0.4rem; }
.wr-off-tag { font-style: normal; font-size: 0.62rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; padding: 1px 7px; border-radius: 9999px; color: var(--lg-mute); background: var(--lg-line); }
.wr-roles { display: flex; flex-wrap: wrap; gap: 0.25rem; margin-top: 3px; }
.wr-role { font-size: 0.68rem; font-weight: 700; padding: 1px 9px; border-radius: 9999px; color: var(--lg-mute); background: var(--lg-soft); box-shadow: inset 0 0 0 1px var(--lg-line); }
.wr-sub { font-size: 0.74rem; color: var(--lg-faint); }

/* project: a little tag with the first project, a count badge, and a tooltip listing all of them */
.wr-proj { display: inline-flex; align-items: center; gap: 0.5rem; width: 196px; padding: 4px 5px 4px 4px; border-radius: 14px; background: var(--lg-soft); box-shadow: inset 0 0 0 1px var(--lg-line); cursor: default; outline: none; transition: box-shadow 0.15s; } .wr-proj:hover, .wr-proj:focus-visible { box-shadow: inset 0 0 0 1px #1c3f66; }
.wr-proj__ico { flex: none; display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 9px; color: #1c3f66; background: var(--lg-card); box-shadow: 0 0 0 1px var(--lg-line); }
.wr-proj__name { flex: 1; min-width: 0; font-size: 0.76rem; font-weight: 700; color: var(--lg-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.wr-proj__n { flex: none; min-width: 26px; height: 22px; padding: 0 7px; display: inline-flex; align-items: center; justify-content: center; border-radius: 9999px; font-size: 0.68rem; font-weight: 800; color: #fff; background: #1c3f66; }
.wr-proj--none { justify-content: center; padding: 0.4rem 0.5rem; font-size: 0.72rem; font-weight: 600; color: var(--lg-faint); background: transparent; box-shadow: none; border: 1px dashed var(--lg-line); }

/* one contact detail: address, state, switch; every channel the same width */
.wr-cell, .wr-none { width: 212px; box-sizing: border-box; }
.wr-cell { --tone: #cbd5e1; display: flex; align-items: center; gap: 0.5rem; padding: 5px 9px 5px 11px; border-radius: 14px; text-align: left; background: var(--lg-soft); box-shadow: inset 0 0 0 1px var(--lg-line); position: relative; overflow: hidden; }
.wr-cell::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: var(--tone); }
.wr-cell.is-live { --tone: #10b981; background: rgba(16, 185, 129, 0.07); box-shadow: inset 0 0 0 1px rgba(16, 185, 129, 0.25); }
.wr-cell.is-stale { --tone: #f59e0b; background: rgba(245, 158, 11, 0.08); box-shadow: inset 0 0 0 1px rgba(245, 158, 11, 0.3); }
.wr-cell__t { display: grid; line-height: 1.25; flex: 1; min-width: 0; } .wr-cell__a { display: block; font-size: 0.78rem; font-weight: 700; color: var(--lg-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-variant-numeric: tabular-nums; }
.wr-cell small { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.02em; color: var(--lg-faint); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; } .wr-dot { flex: none; width: 6px; height: 6px; border-radius: 50%; background: var(--tone); }
.wr-cell.is-live small { color: #047857; } .wr-cell.is-stale small { color: #b45309; }
.wr-none { display: inline-flex; align-items: center; justify-content: center; padding: 0.6rem 0; border-radius: 14px; border: 1px dashed var(--lg-line); font-size: 0.72rem; font-weight: 600; color: var(--lg-faint); }
.wr-cell .wr-sw { flex: none; display: block; box-sizing: border-box; width: 34px; min-width: 34px; height: 20px; min-height: 20px; border-radius: 99px; border: 0; padding: 0; appearance: none; cursor: pointer; background: #cbd5e1; position: relative; transition: background 0.15s; } .wr-knob { position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; border-radius: 50%; background: #fff; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.3); transition: transform 0.15s; }
.wr-sw[aria-checked='true'] { background: #10b981; } .wr-sw[aria-checked='true'] .wr-knob { transform: translateX(14px); } .wr-cell.is-stale .wr-sw[aria-checked='true'] { background: #f59e0b; } .wr-sw:disabled { opacity: 0.6; cursor: wait; } .wr-sw:focus-visible { outline: 2px solid #10b981; outline-offset: 2px; }

/* reach: four bars, lit when that detail is switched on */
.wr-reach { display: inline-flex; flex-direction: column; align-items: flex-start; gap: 4px; } .wr-reach small { font-size: 0.7rem; font-weight: 800; color: var(--lg-ink); font-variant-numeric: tabular-nums; } .wr-reach small span { color: var(--lg-faint); }
.wr-bars { display: inline-flex; gap: 3px; align-items: flex-end; height: 16px; } .wr-bars i { width: 7px; border-radius: 3px; background: var(--lg-line); } .wr-bars i:nth-child(1) { height: 7px; } .wr-bars i:nth-child(2) { height: 10px; } .wr-bars i:nth-child(3) { height: 13px; } .wr-bars i:nth-child(4) { height: 16px; } .wr-bars i.on { background: #10b981; }

.ca-search { display: flex; align-items: center; gap: 8px; width: 320px; max-width: 100%; padding: 0.5rem 0.9rem; border: 1px solid var(--lg-line); border-radius: 9999px; background: var(--lg-card); }
.ca-search:focus-within { border-color: #34d399; box-shadow: 0 0 0 3px rgba(52, 211, 153, 0.18); }
.ca-search-input { flex: 1; min-width: 0; border: none !important; outline: none; box-shadow: none !important; background: transparent; padding: 0 !important; font-size: 0.85rem; font-family: inherit; color: var(--lg-ink); }
.ca-chev { width: 1px; padding-left: 0 !important; }
.ca-page :deep(.ca-tip-down)::after { bottom: auto; top: calc(100% + 8px); }
@media (max-width: 767.98px) {
  .wr-seg { margin-left: 0; width: 100%; overflow-x: auto; }
  .ca-end { justify-content: flex-end; }
  .ca-chev { display: none !important; }
  .ca-page :deep(.lg-tip)::after { display: none; }
  .wr-cell, .wr-none, .wr-proj { width: 100%; max-width: 260px; }
}
</style>

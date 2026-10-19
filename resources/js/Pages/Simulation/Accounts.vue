<template>
  <admin-layout :title="$t('Simulation Accounts')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="lock-closed" :title="$t('Simulation Accounts')" :subtitle="$t('The people who may sign in to Simulation WIMS. They exist only there: no production user or password is copied, and their work never reaches production.')">
        <a :href="workflow_url" class="lg-btn t-violet"><icons name="bell" class="w-4 h-4" /><span>{{ $t('Simulation Alerts') }}</span></a>
      </page-header>

      <div v-if="!enabled" class="lg-note lg-note-amber" role="status"><icons name="info" class="w-4 h-4" /><span>{{ $t('Simulation WIMS is switched off on this server. It is turned on in the server settings (SIMULATION_ENABLED) by the person who looks after the server, then prepared with "php artisan simulation:install".') }}</span></div>
      <div v-else-if="!ready" class="lg-note lg-note-red" role="alert"><icons name="exclamation" class="w-4 h-4" /><span>{{ error || $t('Simulation WIMS is switched on but not ready: its databases have not been prepared. Run "php artisan simulation:install".') }}</span></div>
      <div v-if="alertOnly.length" class="lg-note lg-note-blue"><icons name="bell" class="w-4 h-4" /><span>{{ $t('Test mode: sign-in alerts go only to') }} <b>{{ alertOnly.join(', ') }}</b>. {{ $t('Remove SIMULATION_ALERT_ONLY from the server settings to tell every Super Admin.') }}</span></div>

      <template v-if="ready">
        <section class="ac-attn">
          <div class="ac-tile"><span class="lg-stat-ico lg-ico-sky"><icons name="users" class="w-5 h-5" /></span><div><div class="n">{{ accounts.length }}</div><div class="l">{{ $t('Accounts') }}</div></div></div>
          <div class="ac-tile"><span class="lg-stat-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span><div><div class="n">{{ accounts.filter(a => a.active).length }}</div><div class="l">{{ $t('Active') }}</div></div></div>
          <div class="ac-tile"><span class="lg-stat-ico lg-ico-amber"><icons name="adjustments" class="w-5 h-5" /></span><div><div class="n">{{ accounts.filter(a => a.access === 'custom').length }}</div><div class="l">{{ $t('Custom access') }}</div></div></div>
          <div class="ac-tile"><span class="lg-stat-ico lg-ico-violet"><icons name="clock" class="w-5 h-5" /></span><div><div class="n">{{ logins.length }}</div><div class="l">{{ $t('Recent sign-ins') }}</div></div></div>
        </section>
        <sc-tabs :tabs="tabs" v-model="tab" />

        <template v-if="tab === 'accounts'">
          <section class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="plus" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('New account') }}</h2><p class="lg-card-sub">{{ hint }}</p></div></header>
            <form class="sx-form" @submit.prevent="create">
              <account-fields :state="newAcc" :errors="form.errors" :modules="modules" :hint="hint" :role="role" id="sxn" />
              <div class="sx-actions"><button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing"><icons name="tick" class="w-4 h-4" />{{ $t('Create account') }}</button></div>
            </form>
          </section>

          <section class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="users" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Accounts') }}</h2></div></header>
            <div class="lg-table-wrap"><table class="lg-table lg-responsive">
              <thead><tr><th>{{ $t('Name') }}</th><th>{{ $t('Username') }}</th><th>{{ $t('Access') }}</th><th>{{ $t('Status') }}</th><th>{{ $t('Last seen') }}</th><th></th></tr></thead>
              <tbody>
                <template v-for="a in accounts" :key="a.id">
                  <tr>
                    <td :data-label="$t('Name')" class="lg-main">{{ a.name }}</td><td :data-label="$t('Username')">{{ a.username }}</td>
                    <td :data-label="$t('Access')"><span class="lg-chip" :class="a.access === 'full' ? 'lg-chip-blue' : 'lg-chip-amber'">{{ a.access === 'full' ? $t('Full') : $t('Custom') + ' (' + a.direct_permissions + ')' }}</span></td>
                    <td :data-label="$t('Status')"><span class="lg-chip" :class="a.active ? 'lg-chip-green' : 'lg-chip-red'">{{ a.active ? $t('Active') : $t('Switched off') }}</span></td>
                    <td :data-label="$t('Last seen')">{{ a.last_seen || '-' }}</td>
                    <td class="ac-actions" style="margin: 0; justify-content: flex-end">
                      <button type="button" class="lg-btn t-violet" @click="openEdit(a)"><icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}</button>
                      <button type="button" class="lg-btn t-teal" @click="open(a, 'password')"><icons name="lock-closed" class="w-4 h-4" />{{ $t('Password') }}</button>
                      <button type="button" class="lg-btn" :class="a.active ? 't-amber' : 'lg-btn-green'" @click="toggle(a)"><icons :name="a.active ? 'pause' : 'tick'" class="w-4 h-4" />{{ a.active ? $t('Switch off') : $t('Switch on') }}</button>
                      <button type="button" class="lg-btn t-red" @click="open(a, 'revoke')"><icons name="cross" class="w-4 h-4" />{{ $t('Revoke') }}</button>
                    </td>
                  </tr>
                  <tr v-if="active && active.id === a.id"><td colspan="6">
                    <form v-if="mode2 === 'password'" class="ac-reason" @submit.prevent="savePassword"><input v-model="pw" type="password" autocomplete="new-password" :placeholder="$t('New password')" required /><button type="submit" class="lg-btn lg-btn-primary"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button><button type="button" class="lg-btn t-slate" @click="active = null"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></form>
                    <form v-else-if="mode2 === 'revoke'" class="ac-reason" @submit.prevent="doRevoke"><span>{{ $t('Switch this account off, sign it out everywhere and remove its tokens?') }}</span><button type="submit" class="lg-btn lg-btn-red"><icons name="cross" class="w-4 h-4" />{{ $t('Revoke access') }}</button><button type="button" class="lg-btn t-slate" @click="active = null"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></form>
                  </td></tr>
                </template>
                <tr v-if="!accounts.length"><td colspan="6" style="text-align: center">{{ $t('No simulation accounts yet.') }}</td></tr>
              </tbody>
            </table></div>
          </section>
        </template>

        <section v-else-if="tab === 'activity'" class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-teal"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('What simulation users did') }}</h2><p class="lg-card-sub">{{ $t('The latest 60 actions recorded inside Simulation WIMS.') }}</p></div></header>
          <ul class="sim-list"><li v-for="x in activity" :key="x.id"><b>{{ x.who }}</b> {{ x.what }} <small class="lg-subtext">{{ x.when }}</small></li><li v-if="!activity.length" class="lg-subtext">{{ $t('Nothing yet.') }}</li></ul>
        </section>

        <section v-else class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Sign-ins') }}</h2><p class="lg-card-sub">{{ $t('Successful and refused sign-in attempts to Simulation WIMS.') }}</p></div></header>
          <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th>{{ $t('When') }}</th><th>{{ $t('Username') }}</th><th>{{ $t('Result') }}</th><th>{{ $t('Address') }}</th><th>{{ $t('Note') }}</th></tr></thead>
            <tbody><tr v-for="l in logins" :key="l.id"><td :data-label="$t('When')">{{ l.attempted_at }}</td><td :data-label="$t('Username')">{{ l.username }}</td><td :data-label="$t('Result')"><span class="lg-chip" :class="l.status === 'success' ? 'lg-chip-green' : 'lg-chip-red'">{{ l.status }}</span></td><td :data-label="$t('Address')">{{ l.ip_address }}</td><td :data-label="$t('Note')">{{ l.error_message }}</td></tr>
              <tr v-if="!logins.length"><td colspan="5" style="text-align: center">{{ $t('No sign-ins yet.') }}</td></tr></tbody></table></div>
        </section>
      </template>
    </div>

    <tec-dialog-modal :show="!!editing" max-width="4xl" @close="closeEdit">
      <template #title><span class="sx-title"><icons name="edit" class="w-5 h-5" />{{ $t('Edit simulation account') }}</span></template>
      <template #content>
        <form v-if="editing" class="ac sc-ui sx-form" autocomplete="off" @submit.prevent="saveEdit">
          <account-fields :state="edit" :errors="editErrors" :modules="modules" :hint="hint" :role="role" edit id="sxe" />
          <button type="submit" class="sx-hidden" tabindex="-1" aria-hidden="true"></button>
        </form>
      </template>
      <template #footer>
        <div class="sc-ui flex items-center justify-between gap-2 w-full">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeEdit"><icons name="cross" class="w-4 h-4" /><span>{{ $t('Cancel') }}</span></button>
          <button type="button" class="lg-btn lg-btn-primary" :disabled="saving" @click="saveEdit"><icons name="tick" class="w-4 h-4" /><span>{{ saving ? $t('Saving...') : $t('Save changes') }}</span></button>
        </div>
      </template>
    </tec-dialog-modal>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import ScTabs from '../Subcontracts/ScTabs.vue';
import AccountFields from './AccountFields.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';

export default {
  components: { AdminLayout, PageHeader, ScTabs, AccountFields, TecDialogModal },
  props: { ready: Boolean, enabled: Boolean, accounts: Array, activity: Array, logins: Array, modules: Array, hint: String, role: String, alertOnly: Array, workflow_url: String, error: String },
  data() {
    return {
      tab: 'accounts', form: this.$inertia.form({ username: '', name: '', password: '', permissions: [] }), newAcc: { username: '', name: '', password: '', mode: 'full', permissions: [] },
      active: null, mode2: '', pw: '', editing: null, edit: { username: '', name: '', password: '', mode: 'full', permissions: [] }, editErrors: {}, saving: false,
    };
  },
  computed: {
    tabs() { return [{ key: 'accounts', label: this.$t('Accounts'), icon: 'users', tone: 'sky' }, { key: 'activity', label: this.$t('Activity'), icon: 'list', tone: 'teal' }, { key: 'signins', label: this.$t('Sign-ins'), icon: 'clock', tone: 'violet' }]; },
  },
  methods: {
    create() {
      const n = this.newAcc;
      this.form.transform(() => ({ username: n.username, name: n.name, password: n.password, permissions: n.mode === 'custom' ? n.permissions : [] })).post(route('simulation.accounts.store'), { preserveScroll: true, onSuccess: () => { this.form.reset(); this.newAcc = { username: '', name: '', password: '', mode: 'full', permissions: [] }; } });
    },
    openEdit(a) { this.editing = a; this.editErrors = {}; this.edit = { username: a.username, name: a.name, password: '', mode: a.access, permissions: [...(a.permissions || [])] }; },
    closeEdit() { this.editing = null; },
    saveEdit() {
      if (!this.editing || this.saving) return;
      this.saving = true; this.editErrors = {};
      const e = this.edit;
      this.$inertia.put(route('simulation.accounts.update', this.editing.id), { username: e.username, name: e.name, password: e.password || null, permissions: e.mode === 'custom' ? e.permissions : [] }, {
        preserveScroll: true, onSuccess: () => { this.editing = null; }, onError: err => { this.editErrors = err; }, onFinish: () => { this.saving = false; },
      });
    },
    open(a, m) { this.active = a; this.mode2 = m; this.pw = ''; },
    toggle(a) { this.$inertia.post(route('simulation.accounts.active', a.id), { active: !a.active }, { preserveScroll: true }); },
    savePassword() { this.$inertia.post(route('simulation.accounts.password', this.active.id), { password: this.pw }, { preserveScroll: true, onSuccess: () => (this.active = null) }); },
    doRevoke() { this.$inertia.delete(route('simulation.accounts.revoke', this.active.id), { preserveScroll: true, onSuccess: () => (this.active = null) }); },
  },
};
</script>

<style scoped>
.sx-form { display: grid; gap: 14px; }
.sx-actions { display: flex; gap: 8px; }
.sim-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; } .sim-list li { padding: 8px 12px; border-radius: 14px; background: var(--sc-soft, rgba(148, 163, 184, 0.1)); }
.sx-title { display: inline-flex; align-items: center; gap: 8px; font-weight: 800; }
.sx-hidden { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
</style>

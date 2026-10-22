<template>
  <admin-layout :title="$t('Visitor Logs Settings')">
    <div class="lg-page ac sc-ui tr-ui vl-ui px-4 md:px-0">
      <page-header icon="cog" :title="$t('Visitor Logs Settings')" :subtitle="$t('The lists, the reminders and the visitor fields, all changed here without any code.')">
        <Link :href="route('visitor-logs.dashboard')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Dashboard') }}</span></Link>
        <Link v-if="$can('manage-visitor-notifications')" :href="route('visitor-logs.workflow')" class="lg-btn lg-btn-soft"><icons name="bell" class="w-4 h-4" /><span>{{ $t('Alerts') }}</span></Link>
      </page-header>

      <sc-tabs :tabs="tabs" :model-value="tab" @update:model-value="k => (tab = k)" />

      <section v-if="tab === 'general'" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="cog" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Reminders, Fields and Retention') }}</h2></div></header>
        <form @submit.prevent="saveGeneral">
          <div class="lg-fields">
            <div class="ac-f"><label>{{ $t('Remind when a visitor is still on the premises after (hours)') }}</label><input v-model="g.overstay_hours" type="number" min="0" max="72" inputmode="numeric" :disabled="!can.settings" /><small class="lg-subtext">{{ $t('0 switches the reminder off. It is only a reminder: it does not mean the visitor is missing.') }}</small><span class="ac-err">{{ err('overstay_hours') }}</span></div>
            <div class="ac-f"><label>{{ $t('An item has waited too long after (days)') }}</label><input v-model="g.collection_days" type="number" min="0" max="365" inputmode="numeric" :disabled="!can.settings" /><small class="lg-subtext">{{ $t('0 switches the reminder off.') }}</small><span class="ac-err">{{ err('collection_days') }}</span></div>
            <div class="ac-f"><label>{{ $t('Reception location (shown in alerts)') }}</label><input v-model="g.location" maxlength="160" :disabled="!can.settings" :placeholder="$t('Head Office, Fumba Road')" /></div>
            <div class="ac-f"><label>{{ $t('Reception project ("Attended from" starts with it)') }}</label><acct-select v-model="g.default_warehouse" :options="warehouses" :placeholder="$t('HQ OFFICE')" clearable :disabled="!can.settings" /><small class="lg-subtext">{{ $t('For people who may use it and have more than one project. A person with a single project always starts with that one.') }}</small></div>
            <div class="ac-f"><label>{{ $t('Retention note (months)') }}</label><input v-model="g.retention_months" type="number" min="1" max="600" inputmode="numeric" :disabled="!can.settings" /><small class="lg-subtext">{{ $t('How long visitor records are meant to be kept under the company policy. Nothing is deleted automatically.') }}</small></div>
          </div>
          <h3 class="vl-sub">{{ $t('Visitor fields in use') }}</h3>
          <p class="lg-sub">{{ $t('Only collect what is really needed. Sensitive fields are shown only to people allowed to see them.') }}</p>
          <div class="vl-checks"><label v-for="o in optionalFields" :key="o.value" class="vl-check"><input v-model="g.fields" type="checkbox" :value="o.value" :disabled="!can.settings" /><span>{{ o.label }} <span v-if="o.sensitive" class="lg-chip lg-chip-red" style="text-transform: none">{{ $t('Sensitive') }}</span></span></label></div>
          <label class="vl-check" style="margin-top: 10px"><input v-model="g.require_phone" type="checkbox" :disabled="!can.settings" /><span>{{ $t('A phone number is required for every new visitor') }}</span></label>
          <h3 class="vl-sub">{{ $t('Who can be visited') }}</h3>
          <p class="lg-sub">{{ $t('Leave empty to allow every active user. Or choose the roles whose people can be chosen as the person visited or the recipient of an item.') }}</p>
          <div class="vl-checks"><label v-for="r in roles" :key="r" class="vl-check"><input v-model="g.host_roles" type="checkbox" :value="r" :disabled="!can.settings" /><span>{{ r }}</span></label></div>
          <div v-if="can.settings" class="vl-actions"><button type="submit" class="lg-btn lg-btn-primary" :disabled="busy"><icons name="tick" class="w-4 h-4" />{{ $t('Save Settings') }}</button></div>
        </form>
      </section>

      <section v-for="k in kinds" v-show="tab === k.key" :key="k.key" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ k.label }}</h2><p class="lg-card-sub">{{ help[k.key] }}</p></div></header>
        <div v-if="errors.flag || errors.name" class="lg-note lg-note-red" role="alert" style="margin-bottom: 10px"><icons name="exclamation" class="w-4 h-4" /><span>{{ errors.flag || errors.name }}</span></div>
        <ul class="vl-opts">
          <li v-for="(o, i) in lists[k.key]" :key="o.id" :class="{ 'is-off': !o.is_active }">
            <div v-if="can.options" class="vl-move"><button type="button" :disabled="i === 0 || busy" :aria-label="$t('Move up')" @click="move(k.key, i, -1)"><icons name="up" class="w-3 h-3" /></button><button type="button" :disabled="i === lists[k.key].length - 1 || busy" :aria-label="$t('Move down')" @click="move(k.key, i, 1)"><icons name="down" class="w-3 h-3" /></button></div>
            <input v-model="o.name" class="vl-name" maxlength="120" :disabled="!can.options" :aria-label="$t('Name')" @keyup.enter="saveOpt(o)" />
            <acct-select v-if="k.flags.length" v-model="o.flag" class="vl-flag" :options="k.flags" :placeholder="$t('No special meaning')" clearable :disabled="!can.options" />
            <label class="vl-on"><input v-model="o.is_active" type="checkbox" :disabled="!can.options" @change="saveOpt(o)" />{{ $t('Active') }}</label>
            <button v-if="can.options" type="button" class="lg-btn lg-btn-soft vl-save" :disabled="busy" @click="saveOpt(o)"><icons name="tick" class="w-4 h-4" /><span>{{ $t('Save') }}</span></button>
          </li>
        </ul>
        <form v-if="can.options" class="vl-add" @submit.prevent="addOpt(k)">
          <input v-model="add[k.key].name" maxlength="120" :placeholder="$t('Add a new entry...')" :aria-label="$t('New entry')" />
          <acct-select v-if="k.flags.length" v-model="add[k.key].flag" :options="k.flags" :placeholder="$t('No special meaning')" clearable />
          <button type="submit" class="lg-btn lg-btn-primary" :disabled="busy || !add[k.key].name.trim()"><icons name="plus" class="w-4 h-4" />{{ $t('Add') }}</button>
        </form>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import ScTabs from '@/Pages/Subcontracts/ScTabs.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';

export default {
  components: { ScTabs, Link, AdminLayout, PageHeader, AcctSelect },
  props: { lists: Object, kinds: Array, warehouses: { type: Array, default: () => [] }, values: Object, optionalFields: Array, roles: Array, can: Object },
  data() {
    const v = this.values;
    return {
      tab: this.can.settings ? 'general' : this.kinds[0].key, busy: false,
      g: { overstay_hours: v.overstay_hours, collection_days: v.collection_days, retention_months: v.retention_months || '', location: v.location || '', default_warehouse: v.default_warehouse ? String(v.default_warehouse) : '', fields: [...v.fields], host_roles: [...v.host_roles], require_phone: !!v.require_phone },
      add: Object.fromEntries(this.kinds.map(k => [k.key, { name: '', flag: '' }])),
    };
  },
  computed: {
    errors() { return this.$page.props.errors || {}; },
    tabs() {
      const ico = { purpose: 'meeting-calendar', item_type: 'email', item_status: 'refresh', department: 'building', visitor_category: 'users' };
      return [...(this.can.settings ? [{ key: 'general', label: this.$t('General'), icon: 'cog', tone: 'navy' }] : []), ...this.kinds.map(k => ({ key: k.key, label: k.label, icon: ico[k.key] || 'list', tone: 'sky' }))];
    },
    help() {
      return { purpose: this.$t('The purposes a receptionist can choose. A purpose that "asks for a description" shows a description field (for example "Other").'), item_type: this.$t('Letter, parcel... Mark each as a document or a package: the two register reports use it.'),
        item_status: this.$t('The steps of an item, in order. Keep one "first status" and one "handed over" status. Notified, ready for collection and returned to sender can be marked here too.'), department: this.$t('Departments or sections a visit or an item can be for.'), visitor_category: this.$t('Client, supplier, contractor...') };
    },
  },
  methods: {
    err(k) { return this.errors[k]; },
    post(method, name, data, params) { this.busy = true; this.$inertia[method](route(name, params), data, { preserveScroll: true, onFinish: () => (this.busy = false) }); },
    saveGeneral() { this.busy = true; this.$inertia.put(route('visitor-logs.settings.update'), { ...this.g, require_phone: this.g.require_phone ? 1 : 0, retention_months: this.g.retention_months || null, default_warehouse: this.g.default_warehouse || null }, { preserveScroll: true, onFinish: () => (this.busy = false) }); },
    saveOpt(o) { this.post('put', 'visitor-logs.settings.options.update', { name: o.name, flag: o.flag || '', is_active: o.is_active ? 1 : 0 }, o.id); },
    addOpt(k) { const a = this.add[k.key]; this.busy = true; this.$inertia.post(route('visitor-logs.settings.options.store'), { kind: k.key, name: a.name, flag: a.flag || '' }, { preserveScroll: true, onSuccess: () => (this.add[k.key] = { name: '', flag: '' }), onFinish: () => (this.busy = false) }); },
    move(kind, i, d) { const ids = this.lists[kind].map(o => o.id); const j = i + d; [ids[i], ids[j]] = [ids[j], ids[i]]; this.post('post', 'visitor-logs.settings.options.reorder', { kind, ids }); },
  },
};
</script>

<style scoped>
.vl-sub { margin: 18px 0 4px; font-size: 0.78rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-mute, #64748b); }
.vl-checks { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 8px 16px; margin-top: 8px; }
.vl-check { display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer; }
.vl-actions { display: flex; justify-content: flex-end; margin-top: 16px; }
.vl-opts { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.vl-opts > li { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; padding: 8px 10px; border-radius: 14px; background: var(--lg-soft, #f1f5f9); }
.vl-opts > li.is-off { opacity: 0.6; }
.vl-move { display: grid; gap: 2px; } .vl-move button { display: grid; place-items: center; width: 24px; height: 20px; border: 0; border-radius: 6px; background: var(--lg-card, #fff); cursor: pointer; color: inherit; } .vl-move button:disabled { opacity: 0.3; cursor: default; }
.vl-name { flex: 1 1 200px; min-width: 0; min-height: 40px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--lg-line, #e3e9f2); background: var(--lg-card, #fff); color: inherit; font-weight: 700; }
.vl-flag { flex: 1 1 220px; min-width: 0; }
.vl-on { display: inline-flex; align-items: center; gap: 6px; font-size: 0.82rem; font-weight: 700; }
.vl-save { min-height: 38px; padding: 0 12px; }
.vl-add { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
.vl-add input { flex: 1 1 220px; min-width: 0; min-height: 42px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--lg-line, #e3e9f2); background: var(--lg-card, #fff); color: inherit; }
.vl-add > :nth-child(2) { flex: 1 1 220px; }
@media (max-width: 560px) { .vl-save, .vl-add .lg-btn { width: 100%; justify-content: center; } }
</style>

<template>
  <admin-layout :title="$t('Subcontract Settings')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="cog" :title="$t('Subcontract Settings')" :subtitle="$t('Units, work categories, the payment limit rule and alert timing. Who is told about what is on the Alerts page.')">
        <Link :href="route('subcontracts.dashboard')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Dashboard') }}</span></Link>
        <a :href="workflow_url" class="lg-btn t-violet"><icons name="bell" class="w-4 h-4" /><span>{{ $t('Alerts') }}</span></a>
      </page-header>
      <sc-tabs :tabs="pageTabs" v-model="page" />
      <form @submit.prevent="save">
        <section v-show="page === 'limits'" class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="cash" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Payment limits') }}</h2></div></header>
          <div class="lg-fields">
            <label class="ac-check sc-opt" style="grid-column: 1 / -1"><input v-model="form.override_allowed" type="checkbox" /><span><b>{{ $t('Allow an override') }}</b><small>{{ $t('A person with the override ability may approve a request above the remaining contract value, with a reason (always recorded).') }}</small></span></label>
            <label class="ac-check sc-opt" style="grid-column: 1 / -1"><input v-model="form.claims_require_verification" type="checkbox" /><span><b>{{ $t('A completed contract must be verified before it can be paid') }}</b><small>{{ $t('When on, only a contract whose completion was verified by another person can be put on a Contract Payment Request. When off, a Completed contract is enough.') }}</small></span></label>
            <label class="ac-check sc-opt" style="grid-column: 1 / -1"><input v-model="form.claims_require_evidence" type="checkbox" /><span><b>{{ $t('Completion evidence is required') }}</b><small>{{ $t('When on, a contract needs at least one document attached to it (the evidence of completion) before its completion can be verified or the contract requested.') }}</small></span></label>
            <label class="ac-check sc-opt" style="grid-column: 1 / -1"><input v-model="form.claims_allow_partial_request" type="checkbox" /><span><b>{{ $t('A request may ask for less than the whole balance') }}</b><small>{{ $t('When on, each contract line of a Contract Payment Request may ask for part of what is still open. When off, the whole balance must be requested.') }}</small></span></label>
            <div class="ac-f"><label>{{ $t('Tolerance (an approval may go above the remaining value by this much without an override)') }}</label><input v-model="form.tolerance" inputmode="decimal" /><span class="ac-err">{{ err('tolerance') }}</span></div>
            <div class="ac-f"><label>{{ $t('Default retention % for new agreements') }}</label><input v-model="form.default_retention_percent" inputmode="decimal" /></div>
            <div class="ac-f"><label>{{ $t('Currencies (comma separated, TZS always)') }}</label><input v-model="form.currencies" /></div>
            <div class="ac-f"><label>{{ $t('Reconciliation rounding (differences up to this are closed automatically)') }}</label><input v-model="form.dated_list_tolerance" inputmode="decimal" /></div>
            <div class="ac-f"><label>{{ $t('Alert when something waits more than (hours)') }}</label><input v-model="form.review_alert_hours" inputmode="numeric" /></div>
            <div class="ac-f"><label>{{ $t('Alert before an agreement ends (days)') }}</label><input v-model="form.completion_alert_days" inputmode="numeric" /></div>
            <div class="ac-f"><label>{{ $t('Alert when approved money is not paid after (days)') }}</label><input v-model="form.unpaid_alert_days" inputmode="numeric" /><span class="ac-err">{{ err('unpaid_alert_days') }}</span></div>
            <div class="ac-f"><label>{{ $t('Alert when an account has this percent or less of its contract left') }}</label><input v-model="form.near_limit_percent" inputmode="decimal" /><span class="ac-err">{{ err('near_limit_percent') }}</span></div>
            <div class="ac-f"><label>{{ $t('Tell people about the same alert again only after (hours)') }}</label><input v-model="form.alert_cooldown_hours" inputmode="numeric" /><span class="ac-err">{{ err('alert_cooldown_hours') }}</span></div>
          </div>
        </section>
        <section v-show="page === 'lists'" class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-teal"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Units') }}</h2><p class="lg-card-sub">{{ $t('A unit and the other spellings that mean the same, so "SQM", "sqm" and "m2" become one unit.') }}</p></div><button type="button" class="lg-btn lg-btn-primary" @click="form.units.push({ name: '', aliases: '' })"><icons name="plus" class="w-4 h-4" />{{ $t('Unit') }}</button></header>
          <div v-if="form.units.length > 8" class="sc-tfind"><search-bar v-model="tq" :placeholder="$t('Search units and spellings...')" :count="tq.trim() ? form.units.filter(x => has(x.name, x.aliases)).length : null" /></div>
          <div v-for="(u, i) in form.units" v-show="has(u.name, u.aliases)" :key="i" class="sc-line"><div class="ac-f"><label>{{ $t('Unit') }}</label><input v-model="u.name" /></div><div class="ac-f"><label>{{ $t('Other spellings (comma separated)') }}</label><input v-model="u.aliases" /></div><button type="button" class="lg-icon-btn is-red" :aria-label="$t('Remove')" @click="form.units.splice(i, 1)"><icons name="trash" class="w-4 h-4" /></button></div>
          <span class="ac-err">{{ err('units') }}</span>
        </section>
        <section v-show="page === 'lists'" class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Work categories') }}</h2><p class="lg-card-sub">{{ $t('One per line.') }}</p></div></header>
          <textarea v-model="form.categories" rows="8" class="sc-cat"></textarea>
        </section>
        <section v-show="page === 'fields'" class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="adjustments" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Fields') }}</h2><p class="lg-card-sub">{{ $t('Switch optional fields off, make them required, or add your own fields. Core fields (who, what, how much, currency) stay because the money and the approval flow depend on them. Removing a field hides it; what was typed in it is kept.') }}</p></div></header>
          <sc-tabs :tabs="entityTabs" v-model="entity" />
          <div v-if="cur" class="sc-fcfg">
            <h3 class="sc-h3">{{ $t('Built-in fields') }}</h3>
            <div class="sc-brow sc-brow-head"><span>{{ $t('Field') }}</span><span>{{ $t('In use') }}</span><span>{{ $t('Required') }}</span></div>
            <div v-for="b in cur.builtin" :key="b.key" class="sc-brow"><span>{{ b.label }}</span><label class="ac-check sc-cell"><input v-model="b.on" type="checkbox" @change="b.required = b.on ? b.required : false" /><i class="sr-only">{{ $t('In use') }}</i></label><label class="ac-check sc-cell"><input v-model="b.required" type="checkbox" :disabled="!b.on" /><i class="sr-only">{{ $t('Required') }}</i></label></div>
            <h3 class="sc-h3" style="margin-top: 16px">{{ $t('Your own fields') }} <small class="lg-subtext">({{ cur.custom.length }} / {{ max_custom }})</small></h3>
            <div v-for="(c, i) in cur.custom" :key="c.key || i" class="sc-cf">
              <div class="ac-f"><label>{{ $t('Field name') }} *</label><input v-model="c.label" maxlength="60" /><span class="ac-err">{{ err('fields.' + entity + '.custom.' + i + '.label') }}</span></div>
              <div class="ac-f"><label>{{ $t('Kind') }}</label><acct-select v-model="c.type" :options="field_types" /></div>
              <div class="ac-f sc-cf-opts" v-if="c.type === 'select'"><label>{{ $t('Choices (one per line)') }}</label><textarea v-model="c.options_text" rows="3"></textarea><span class="ac-err">{{ err('fields.' + entity + '.custom.' + i + '.options') }}</span></div>
              <div class="ac-f"><label>{{ $t('Help text (optional)') }}</label><input v-model="c.help" maxlength="160" /></div>
              <div class="sc-cf-flags"><label class="ac-check" v-if="c.type !== 'yesno'"><input v-model="c.required" type="checkbox" />{{ $t('Required') }}</label><label class="ac-check"><input v-model="c.in_list" type="checkbox" />{{ $t('Show in lists and reports') }}</label></div>
              <button type="button" class="lg-btn t-red" @click="removeField(i)"><icons name="trash" class="w-4 h-4" /><span>{{ $t('Remove') }}</span></button>
            </div>
            <button type="button" class="lg-btn lg-btn-primary" :disabled="cur.custom.length >= max_custom" @click="addField"><icons name="plus" class="w-4 h-4" /><span>{{ $t('Add a field') }}</span></button>
          </div>
        </section>
        <div class="lg-actionbar"><span class="lg-actionbar-note">{{ $t('Changes are recorded in the audit log.') }}</span><div class="lg-actionbar-btns"><button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button></div></div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import ScTabs from './ScTabs.vue';
import SearchBar from './SearchBar.vue';
import { sc, tableFind } from './sc';

export default {
  components: { AdminLayout, PageHeader, Link, AcctSelect, ScTabs, SearchBar },
  mixins: [sc, tableFind],
  props: { s: Object, workflow_url: String, fields: Object, field_types: Array, max_custom: Number },
  data() { return { page: 'limits', entity: Object.keys(this.fields)[0], form: this.$inertia.form({ ...this.s, units: this.s.units.map(u => ({ ...u })), fields: JSON.parse(JSON.stringify(this.fields)) }) }; },
  computed: {
    pageTabs() { return [{ key: 'limits', label: this.$t('Limits'), icon: 'cash', tone: 'amber' }, { key: 'lists', label: this.$t('Lists'), icon: 'list', tone: 'teal', count: this.form.units.length }, { key: 'fields', label: this.$t('Fields'), icon: 'adjustments', tone: 'violet' }]; },
    cur() { return this.form.fields[this.entity]; },
    entityTabs() { const icons = { agreement: 'doc-text', work_item: 'list', variation: 'adjustments', request: 'send' }; const tones = ['navy', 'teal', 'violet', 'sky']; return Object.keys(this.form.fields).map((k, i) => ({ key: k, label: this.form.fields[k].label, icon: icons[k] || 'list', tone: tones[i % 4], count: this.form.fields[k].custom.length })); },
  },
  methods: {
    addField() { this.cur.custom.push({ key: '', label: '', type: 'text', options_text: '', help: '', required: false, in_list: false }); },
    removeField(i) { this.cur.custom.splice(i, 1); },
    save() {
      this.form.transform(d => { const f = JSON.parse(JSON.stringify(d.fields)); Object.keys(f).forEach(e => { f[e].builtin = Object.fromEntries(f[e].builtin.map(b => [b.key, { on: b.on, required: b.required }])); f[e].custom = f[e].custom.map(c => ({ ...c, options: c.type === 'select' ? c.options_text.split(/\r?\n/) : [] })); }); return { ...d, fields: f }; }).put(route('subcontracts.settings.update'), { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.sc-h3 { font-size: 0.82rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute); margin: 4px 0 8px; }
.sc-brow { display: grid; grid-template-columns: 1fr 90px 90px; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 12px; background: rgba(148, 163, 184, 0.08); margin-bottom: 6px; font-size: 0.86rem; font-weight: 600; } .sc-brow-head { background: transparent; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-mute); padding-top: 0; padding-bottom: 0; }
.sc-cf { display: grid; gap: 10px; grid-template-columns: minmax(0, 2fr) minmax(0, 1.3fr) minmax(0, 2fr) auto; align-items: end; padding: 12px; border-radius: 16px; background: rgba(148, 163, 184, 0.08); box-shadow: inset 0 0 0 1px var(--lg-line); margin-bottom: 10px; } .sc-cf-opts { grid-column: 1 / -1; } .sc-cf-flags { display: flex; gap: 14px; flex-wrap: wrap; grid-column: 1 / 4; }
@media (max-width: 900px) { .sc-cf { grid-template-columns: 1fr; } .sc-cf-flags { grid-column: auto; } .sc-brow { grid-template-columns: 1fr 70px 70px; } }
.sc-line { display: grid; gap: 10px; grid-template-columns: 1fr 3fr auto; align-items: end; margin-bottom: 8px; } @media (max-width: 800px) { .sc-line { grid-template-columns: 1fr; } }
.sc-opt { display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; border-radius: 16px; background: var(--sc-soft, rgba(148, 163, 184, 0.08)); box-shadow: inset 0 0 0 1px var(--sc-line, var(--lg-line)); } .sc-opt span { display: grid; gap: 2px; } .sc-opt small { color: var(--sc-mute, var(--lg-mute)); font-weight: 500; }
.sc-cell { justify-content: flex-start; }
.sc-cat { width: 100%; border-radius: 14px; padding: 10px 12px; border: 1px solid var(--lg-line); background: var(--lg-card); }
</style>

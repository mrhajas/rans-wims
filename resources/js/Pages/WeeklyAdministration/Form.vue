<template>
  <admin-layout :title="doc ? $t('Edit :r', { r: doc.reference }) : $t('New Administration Document')">
    <div class="lg-page ac sc-ui tr-ui wa-ui px-4 md:px-0">
      <page-header icon="doc-text" :title="doc ? $t('Edit :r', { r: doc.reference }) : $t('New Administration Document')" :subtitle="doc ? $t('Change the lines, the charity section or the beneficiary. It goes back to waiting for approval when you save.') : $t('Make the weekly document: choose where it starts, then add, change or remove lines.')">
        <Link :href="doc ? route('weekly-administration.show', doc.id) : route('weekly-administration.index')" class="lg-btn lg-btn-soft"><icons name="arrow-left" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
      </page-header>

      <!-- where does it start? -->
      <section v-if="!started" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-teal"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('How do you want to start?') }}</h2><p class="lg-card-sub">{{ $t('Both can be changed line by line afterwards.') }}</p></div></header>
        <div class="wa-choice">
          <button type="button" class="wa-pick" @click="start('template')"><span class="wa-pick-ico"><icons name="collection" class="w-7 h-7" /></span><b>{{ $t('Generate Weekly Administration from Template') }}</b><span>{{ template.length ? $t(':n standing lines are filled in for you, with their beneficiary and amount.', { n: template.length }) : $t('The template is empty. You can still start and add the lines yourself.') }}</span></button>
          <button type="button" class="wa-pick is-zero" @click="start('blank')"><span class="wa-pick-ico"><icons name="plus" class="w-7 h-7" /></span><b>{{ $t('Start from Zero') }}</b><span>{{ $t('An empty document: add only the lines you need this week.') }}</span></button>
        </div>
      </section>

      <form v-else @submit.prevent="save">
        <div v-if="doc && doc.status === 'Rejected'" class="lg-note lg-note-red" style="margin-bottom: 12px">{{ $t('Rejected') }}: {{ doc.rejection_reason }}. {{ $t('Correct it and save: it goes back for approval.') }}</div>

        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="calendar" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('The Document') }}</h2></div></header>
          <div class="wa-meta">
            <div><small>{{ $t('Department') }}</small><b>{{ department }}</b></div>
            <div><small>{{ $t('Reference') }}</small><b>{{ doc ? doc.reference : $t('given when it is saved') }}</b></div>
            <div><small>{{ $t('Currency') }}</small><b>{{ currency }}</b></div>
          </div>
          <div class="tr-fbar" style="margin-top: 12px">
            <div class="ac-f"><label>{{ $t('Date') }} *</label><date-picker v-model="form.doc_date" /><span class="ac-err">{{ err('doc_date') }}</span></div>
            <div class="ac-f"><label>{{ $t('Requested by (beneficiary)') }}</label><acct-select v-model="form.beneficiary_user_id" :options="users" :placeholder="me.name" /><span class="ac-err">{{ err('beneficiary_user_id') }}</span></div>
            <div class="ac-f tr-wide"><label>{{ $t('Notes') }}</label><input v-model="form.notes" maxlength="2000" :placeholder="$t('Anything the approver should know (optional)')" /></div>
          </div>
          <p class="wa-note"><icons name="info" class="w-4 h-4" />{{ $t('The date is today by default and the beneficiary is you, the person making the document. Change the beneficiary to anyone on the list of WIMS users.') }}</p>
        </section>

        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Lines') }}</h2><p class="lg-card-sub">{{ $t('Add and remove lines freely. A line you type is remembered as an item, so reports can follow it month by month.') }}</p></div></header>
          <datalist id="wa-items"><option v-for="i in itemNames" :key="i.id" :value="i.name"></option></datalist>
          <datalist id="wa-cats"><option v-for="c in categories" :key="c" :value="c"></option></datalist>
          <datalist id="wa-people"><option v-for="u in users" :key="u.value" :value="u.label"></option></datalist>
          <div v-if="form.lines.length" class="wa-head-row"><span>{{ $t('No') }}</span><span>{{ $t('Description') }}</span><span>{{ $t('Beneficiary') }}</span><span class="r">{{ $t('Amount') }} ({{ currency }})</span><span></span></div>
          <div class="wa-lines">
            <div v-for="(l, i) in form.lines" :key="l._k" class="wa-line">
              <span class="wa-n">{{ i + 1 }}</span>
              <input v-model="l.description" list="wa-items" :placeholder="$t('Description')" maxlength="255" @change="fillFromItem(l)" />
              <input v-model="l.beneficiary" list="wa-people" :placeholder="beneficiaryName" maxlength="190" />
              <input v-model="l.amount" class="wa-amt" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0.00" />
              <span class="wa-mv">
                <button type="button" class="wa-mini" :disabled="i === 0" :aria-label="$t('Move up')" @click="move(form.lines, i, -1)"><icons name="chevron-up" class="w-4 h-4" /></button>
                <button type="button" class="wa-mini" :disabled="i === form.lines.length - 1" :aria-label="$t('Move down')" @click="move(form.lines, i, 1)"><icons name="chevron-down" class="w-4 h-4" /></button>
                <button type="button" class="wa-mini is-red" :aria-label="$t('Remove this line')" @click="form.lines.splice(i, 1)"><icons name="trash" class="w-4 h-4" /></button>
              </span>
              <div class="wa-lmeta" style="grid-column: 1 / -1">
                <template v-if="!l._edit">
                  <span class="lg-chip lg-chip-indigo" style="text-transform: none"><icons name="collection" class="w-3 h-3" /> {{ l.category || $t('No category') }}</span>
                  <span v-if="l.staff_id" class="lg-chip lg-chip-green" style="text-transform: none"><icons name="user" class="w-3 h-3" /> {{ staffName(l.staff_id) }}</span>
                  <button type="button" class="wa-link" @click="l._edit = true">{{ $t('Category / staff member') }}</button>
                </template>
                <template v-else>
                  <div class="ac-f"><label>{{ $t('Category') }}</label><input v-model="l.category" list="wa-cats" maxlength="60" :placeholder="$t('Fuel, Cleaning...')" /></div>
                  <div class="ac-f"><label>{{ $t('Staff member') }}</label><acct-select v-model="l.staff_id" :options="staffOptions" :placeholder="$t('Not linked')" clearable /></div>
                  <button type="button" class="wa-link" @click="l._edit = false">{{ $t('Done') }}</button>
                </template>
              </div>
              <span v-if="lineErr(i)" class="ac-err" style="grid-column: 1 / -1">{{ lineErr(i) }}</span>
            </div>
          </div>
          <p v-if="!form.lines.length" class="lg-sub" style="margin: 6px 0">{{ $t('No lines yet. Add one below.') }}</p>
          <span class="ac-err">{{ err('lines') }}</span>
          <div class="wa-adds">
            <button type="button" class="lg-btn lg-btn-primary" @click="addLine()"><icons name="plus" class="w-4 h-4" />{{ $t('Add a line') }}</button>
            <div v-if="itemNames.length" class="ac-f"><label>{{ $t('Add a known item') }}</label><acct-select v-model="pickItem" :options="itemOptions" :placeholder="$t('Search the items...')" clearable @change="addKnown" /></div>
            <button v-if="template.length && started !== 'edit'" type="button" class="lg-btn t-slate" @click="addTemplate"><icons name="collection" class="w-4 h-4" />{{ $t('Add the template lines that are missing') }}</button>
          </div>
          <div class="wa-sub"><span>{{ $t('Total of the lines') }}</span><b>{{ currency }} {{ money(itemsTotal) }}</b></div>
        </section>

        <section class="lg-card wa-charity">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="heart" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Charity') }}</h2><p class="lg-card-sub">{{ $t('Normally added in the last week of the month. When it is included its total is added to the total of the document.') }}</p></div></header>
          <label class="wa-toggle"><input v-model="form.include_charity" type="checkbox" @change="onCharityToggle" /><span><b>{{ $t('Include the Charity section') }}</b><small>{{ $t('Pulls the people and their monthly amounts from the Charity list. You can change any amount, add a line or remove one.') }}</small></span></label>
          <template v-if="form.include_charity">
            <div class="wa-adds">
              <button v-if="can.charity" type="button" class="lg-btn lg-btn-primary" :disabled="pulling" @click="pull(false)"><icons name="refresh" class="w-4 h-4" />{{ pulling ? $t('Pulling...') : $t('Pull from the Charity list') }}</button>
              <button type="button" class="lg-btn t-slate" @click="addCharity"><icons name="plus" class="w-4 h-4" />{{ $t('Add a charity line') }}</button>
              <Link v-if="can.charity" :href="route('weekly-administration.charity.index')" class="lg-btn lg-btn-soft"><icons name="users" class="w-4 h-4" />{{ $t('Open the Charity list') }}</Link>
            </div>
            <p v-if="pulled" class="wa-note"><icons name="info" class="w-4 h-4" />{{ $t('Pulled for :m.', { m: pulled }) }} {{ $t('The wording and the amounts can be edited here without changing the Charity list.') }}</p>
            <p v-if="!charityPeople && !form.charity.length" class="wa-note"><icons name="exclamation" class="w-4 h-4" />{{ $t('The Charity list has no active people yet. Add them on the Charity page, or type the lines here.') }}</p>
            <div v-if="form.charity.length" class="wa-head-row is-charity" style="margin-top: 10px"><span>{{ $t('No') }}</span><span>{{ $t('Charity') }}</span><span class="r">{{ $t('Monthly') }}</span><span class="r">{{ $t('Amount') }} ({{ currency }})</span><span></span></div>
            <div class="wa-lines">
              <div v-for="(c, i) in form.charity" :key="c._k" class="wa-line is-charity">
                <span class="wa-n">{{ i + 1 }}</span>
                <input v-model="c.description" :placeholder="$t('CHARITY - NAME FOR MONTH')" maxlength="255" />
                <span class="wa-ro">{{ Number(c.monthly_amount) ? money(c.monthly_amount) : '-' }}</span>
                <input v-model="c.amount" class="wa-amt" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0.00" />
                <span class="wa-mv">
                  <button type="button" class="wa-mini" :disabled="i === 0" :aria-label="$t('Move up')" @click="move(form.charity, i, -1)"><icons name="chevron-up" class="w-4 h-4" /></button>
                  <button type="button" class="wa-mini" :disabled="i === form.charity.length - 1" :aria-label="$t('Move down')" @click="move(form.charity, i, 1)"><icons name="chevron-down" class="w-4 h-4" /></button>
                  <button type="button" class="wa-mini is-red" :aria-label="$t('Remove this line')" @click="form.charity.splice(i, 1)"><icons name="trash" class="w-4 h-4" /></button>
                </span>
              </div>
            </div>
            <div class="wa-sub"><span>{{ $t('Total of the charity') }}</span><b>{{ currency }} {{ money(charityTotal) }}</b></div>
          </template>
        </section>

        <div class="wa-bar">
          <div class="wa-sums"><span>{{ $t('Lines') }} <b>{{ money(itemsTotal) }}</b></span><span v-if="form.include_charity">{{ $t('Charity') }} <b>{{ money(charityTotal) }}</b></span><span class="g">{{ $t('Total') }} <b>{{ currency }} {{ money(grandTotal) }}</b></span></div>
          <div class="wa-btns">
            <Link :href="doc ? route('weekly-administration.show', doc.id) : route('weekly-administration.index')" class="lg-btn t-slate">{{ $t('Cancel') }}</Link>
            <button type="submit" class="lg-btn lg-btn-primary" :disabled="busy"><icons name="tick" class="w-4 h-4" />{{ busy ? $t('Saving...') : (doc ? $t('Save changes') : $t('Save and send for approval')) }}</button>
          </div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';

let key = 0;
const withKey = x => ({ ...x, _k: ++key });

export default {
  components: { AdminLayout, PageHeader, Link, DatePicker, AcctSelect },
  props: { doc: Object, mode: String, template: { type: Array, default: () => [] }, users: Array, me: Object, today: String, itemNames: { type: Array, default: () => [] }, categories: { type: Array, default: () => [] }, staffOptions: { type: Array, default: () => [] }, charityPeople: Number, department: String, currency: String, can: Object },
  data() {
    const d = this.doc;
    return {
      started: d ? 'edit' : this.mode || '', busy: false, pulling: false, pulled: '', pickItem: '',
      form: {
        doc_date: d ? d.doc_date : this.today, beneficiary_user_id: d ? d.beneficiary_user_id : this.me.id, notes: d ? d.notes || '' : '', include_charity: d ? d.include_charity : false, source: this.mode || 'blank',
        lines: d ? d.lines.map(withKey) : [], charity: d ? d.charity.map(withKey) : [],
      },
    };
  },
  created() { if (this.mode && !this.doc) this.start(this.mode); },
  computed: {
    beneficiaryName() { const u = this.users.find(x => x.value === this.form.beneficiary_user_id); return u ? u.label : this.me.name; },
    itemOptions() { return this.itemNames.map(i => ({ value: i.id, label: i.name })); },
    itemsTotal() { return this.form.lines.reduce((s, l) => s + Math.round((parseFloat(l.amount) || 0) * 100), 0) / 100; },
    charityTotal() { return this.form.include_charity ? this.form.charity.reduce((s, c) => s + Math.round((parseFloat(c.amount) || 0) * 100), 0) / 100 : 0; },
    grandTotal() { return Math.round((this.itemsTotal + this.charityTotal) * 100) / 100; },
  },
  methods: {
    err(k) { return (this.$page.props.errors || {})[k]; },
    lineErr(i) { const e = this.$page.props.errors || {}; return e['lines.' + i + '.description'] || e['lines.' + i + '.amount'] || ''; },
    money(v) { return Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    move(list, i, d) { const j = i + d; if (j < 0 || j >= list.length) return; const x = list.splice(i, 1)[0]; list.splice(j, 0, x); },
    start(mode) {
      this.started = mode; this.form.source = mode;
      if (mode === 'template') this.form.lines = this.template.map(t => withKey({ admin_item_id: t.admin_item_id, description: t.description, beneficiary: t.beneficiary || '', beneficiary_user_id: t.beneficiary_user_id || null, category: t.category || '', staff_id: t.staff_id || null, amount: t.amount }));
      if (mode === 'blank' && !this.form.lines.length) this.addLine();
    },
    addLine(extra = {}) { this.form.lines.push(withKey({ admin_item_id: null, description: '', beneficiary: '', beneficiary_user_id: null, category: '', staff_id: null, amount: '', ...extra })); },
    staffName(id) { const s = this.staffOptions.find(x => x.value === id); return s ? s.label : ''; },
    addKnown(id) { if (!id) return; const i = this.itemNames.find(x => x.id === id); if (i) this.addLine({ admin_item_id: i.id, description: i.name, beneficiary: i.beneficiary || '', category: i.category || '', staff_id: i.staff_id || null, amount: Number(i.amount) ? i.amount : '' }); this.$nextTick(() => (this.pickItem = '')); },
    addTemplate() { const have = new Set(this.form.lines.map(l => (l.description || '').trim().toLowerCase())); this.template.forEach(t => { if (!have.has(t.description.trim().toLowerCase())) this.form.lines.push(withKey({ admin_item_id: t.admin_item_id, description: t.description, beneficiary: t.beneficiary || '', beneficiary_user_id: t.beneficiary_user_id || null, category: t.category || '', staff_id: t.staff_id || null, amount: t.amount })); }); },
    // typing a name of a known item brings its usual beneficiary and amount when the line is still empty
    fillFromItem(l) { const k = (l.description || '').trim().toLowerCase(); const i = this.itemNames.find(x => x.name.toLowerCase() === k); if (!i) { l.admin_item_id = null; return; } l.admin_item_id = i.id; if (!l.category && i.category) l.category = i.category; if (!l.staff_id && i.staff_id) l.staff_id = i.staff_id; if (!l.beneficiary && i.beneficiary) l.beneficiary = i.beneficiary; if (!Number(l.amount) && Number(i.amount)) l.amount = i.amount; },
    addCharity() { this.form.charity.push(withKey({ admin_charity_person_id: null, name: '', description: '', amount: '', monthly_amount: 0 })); },
    onCharityToggle() { if (this.form.include_charity && !this.form.charity.length && this.can.charity && this.charityPeople) this.pull(true); },
    async pull(quiet) {
      this.pulling = true;
      try {
        const r = await window.axios.get(route('weekly-administration.charity-pull'), { params: { date: this.form.doc_date } });
        const have = new Set(this.form.charity.map(c => c.admin_charity_person_id).filter(Boolean));
        let added = 0;
        r.data.rows.forEach(x => { if (!have.has(x.admin_charity_person_id)) { this.form.charity.push(withKey(x)); added++; } });
        this.pulled = r.data.month;
        if (!quiet && !added) this.pulled = r.data.month + ' (' + this.$t('nothing new to add') + ')';
      } catch (e) { this.pulled = ''; } finally { this.pulling = false; }
    },
    save() {
      this.busy = true;
      const clean = a => a.map(({ _k, _edit, ...r }) => r);
      const body = { doc_date: this.form.doc_date, beneficiary_user_id: this.form.beneficiary_user_id, notes: this.form.notes, include_charity: this.form.include_charity ? 1 : 0, source: this.form.source === 'template' ? 'template' : 'blank', lines: clean(this.form.lines), charity: this.form.include_charity ? clean(this.form.charity) : [] };
      const o = { preserveScroll: true, onFinish: () => (this.busy = false) };
      if (this.doc) this.$inertia.put(route('weekly-administration.update', this.doc.id), body, o); else this.$inertia.post(route('weekly-administration.store'), body, o);
    },
  },
};
</script>

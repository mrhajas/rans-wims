<template>
  <admin-layout :title="$t('Funds Allocation Template')">
    <div class="lg-page px-4 md:px-0 ft-page">
      <page-header icon="doc-text" :title="$t('Funds Allocation Template')" :subtitle="$t('The monthly allocation sheet as a template. Change it, save it, and generate a Draft allocation sheet from it.')">
        <Link :href="route('fund-allocations.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Funds Allocation') }}</Link>
        <a :href="route('fund-allocations.template.print')" target="_blank" class="lg-btn lg-btn-soft ft-print"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
        <a :href="route('fund-allocations.template.pdf')" class="lg-btn lg-btn-dark"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</a>
        <button v-if="can.update" type="button" class="lg-btn lg-btn-soft" :disabled="busy || !dirty" @click="save"><icons name="tick" class="w-4 h-4" />{{ $t('Save template') }}</button>
        <button v-if="can.generate" type="button" class="lg-btn lg-btn-primary" :disabled="busy" @click="generate"><icons name="plus" class="w-4 h-4" />{{ $t('Generate from Template') }}</button>
      </page-header>

      <p v-if="template.updated_at" class="ft-meta"><icons name="clock" class="w-3.5 h-3.5" />{{ $t('Last saved') }} {{ $date(template.updated_at) }} {{ $time(template.updated_at) }}<template v-if="template.updated_by"> · {{ template.updated_by }}</template><span v-if="dirty" class="ft-dirty">{{ $t('Unsaved changes. Print and Download use the saved template.') }}</span></p>
      <p v-if="!can.update" class="ft-ro"><icons name="lock-closed" class="w-4 h-4" />{{ $t('You can read this template and generate a sheet from it. Changing it needs the permission to edit the template.') }}</p>

      <!-- header + balances -->
      <section class="lg-card ft-card">
        <div class="ft-head">
          <label class="ft-f ft-grow"><span>{{ $t('Sheet title') }}</span><input v-model="form.title" class="ft-in" maxlength="190" :disabled="!can.update" /></label>
          <label class="ft-f"><span>{{ $t('Date on the template') }}</span><input v-model="form.date" type="date" class="ft-in" :disabled="!can.update" /></label>
          <label class="ft-f"><span>{{ $t('USD rate (TZS per 1 USD)') }}</span><amount-input v-model="form.usd_rate" :decimals="2" :disabled="!can.update" /></label>
        </div>

        <h3 class="ft-h"><icons name="cash" class="w-4 h-4" />{{ $t('Available balances') }}</h3>
        <div v-for="(b, i) in form.balances" :key="b._key" class="ft-bal" :class="b.currency === 'TZS' ? 'is-tzs' : 'is-fx'">
          <span class="ft-cur" :class="'c-' + (b.currency === 'TZS' ? 'tzs' : 'usd')">{{ b.currency }}</span>
          <div class="ft-c ft-c-acc"><span class="ft-ml">{{ $t('Account') }}</span>
            <auto-complete :id="'ft-acc-' + b._key" :suggestions="accountOptions" :model-value="b.fund_account_id" :placeholder="$t('Choose account')" :disable="!can.update ? 1 : undefined" clearable @update:modelValue="v => onAccount(b, v)" />
          </div>
          <div class="ft-c ft-c-lab"><span class="ft-ml">{{ $t('Label') }}</span><input v-model="b.label" class="ft-in" maxlength="190" :disabled="!can.update" :placeholder="$t('e.g. Cash TZS')" /></div>
          <div class="ft-c ft-c-cur"><span class="ft-ml">{{ $t('Currency') }}</span>
            <currency-select :id="'ft-cur-' + b._key" :model-value="b.currency" @update:modelValue="v => onCurrency(b, v)" />
          </div>
          <div class="ft-c ft-c-amt"><span class="ft-ml">{{ $t('Amount') }}</span><amount-input v-model="b.amount" :decimals="2" :disabled="!can.update" /></div>
          <div class="ft-c ft-c-rate"><span class="ft-ml">{{ $t('Rate to TZS') }}</span>
            <amount-input v-if="b.currency !== 'TZS'" v-model="b.exchange_rate" :decimals="4" :disabled="!can.update" :placeholder="$t('Required')" />
            <span v-else class="ft-one">1</span>
          </div>
          <div class="ft-c ft-c-val"><span class="ft-ml">{{ $t('Value in TZS') }}</span><b :class="b.currency === 'TZS' ? 'ft-green' : 'ft-blue'">{{ money(balanceTzs(b)) }}</b></div>
          <div v-if="can.update" class="ft-c ft-c-x"><button type="button" class="ft-ib is-red" :aria-label="$t('Remove')" @click="form.balances.splice(i, 1)"><icons name="trash" class="w-4 h-4" /></button></div>
        </div>
        <button v-if="can.update" type="button" class="ft-add" @click="addBalance"><icons name="plus" class="w-4 h-4" />{{ $t('Add balance') }}</button>

        <div class="ft-totals">
          <div class="ft-tot ft-tot--tzs"><small>{{ $t('Cash and bank in TZS') }}</small><b>{{ money(balanceTzsOnly) }}<i>TZS</i></b></div>
          <div v-if="balanceUsdOnly > 0 || hasUsdRow" class="ft-tot ft-tot--usd"><small>{{ $t('Cash and bank in USD') }}</small><b>{{ money(balanceUsdOnly) }}<i>USD</i></b></div>
          <div class="ft-tot"><small>{{ $t('Total allocation') }}</small><b>{{ money(grand('allocation')) }}<i>TZS</i></b></div>
          <div class="ft-tot ft-tot--after"><small>{{ $t('Balance after this expenses (TZS)') }}</small><b :class="balanceAfter < 0 ? 'ft-red' : ''">{{ money(balanceAfter) }}<i>TZS</i></b></div>
        </div>
      </section>

      <!-- the sections (categories) -->
      <section v-for="(s, si) in form.sections" :key="s._key" class="lg-card ft-card ft-sec">
        <header class="ft-sec__head">
          <span class="ft-letter">{{ letter(si) }}</span>
          <div class="ft-sec__cat">
            <auto-complete v-if="can.update" :id="'ft-cat-' + s._key" :suggestions="categoryOptionsFor(s)" :model-value="s.category_id" :placeholder="$t('Search or choose a category')" @update:modelValue="v => (s.category_id = v || s.category_id)" />
            <b v-else>{{ categoryName(s.category_id) }}</b>
          </div>
          <span class="ft-cnt">{{ s.items.length }} {{ $t('rows') }}</span>
          <span class="ft-subtot"><small>{{ $t('Request') }}</small>{{ money(sum(s, 'request')) }}</span>
          <span class="ft-subtot"><small>{{ $t('Allocation') }}</small><b>{{ money(sum(s, 'allocation')) }}</b></span>
          <span v-if="can.update" class="ft-sec__tools">
            <button type="button" class="ft-ib" :disabled="si === 0" :aria-label="$t('Move up')" @click="moveSection(si, -1)"><icons name="chevron-down" class="w-4 h-4 rotate-180" /></button>
            <button type="button" class="ft-ib" :disabled="si === form.sections.length - 1" :aria-label="$t('Move down')" @click="moveSection(si, 1)"><icons name="chevron-down" class="w-4 h-4" /></button>
            <button type="button" class="ft-ib is-red" :aria-label="$t('Remove this section')" @click="removeSection(si)"><icons name="trash" class="w-4 h-4" /></button>
          </span>
        </header>

        <div v-for="(it, ii) in s.items" :key="it._key" class="ft-item" :class="{ 'ft-warn': splitOff(it) }">
          <div class="ft-line1">
            <span class="ft-sn">{{ ii + 1 }}</span>
            <div class="ft-c ft-desc"><span class="ft-ml">{{ $t('Description') }}</span><input v-model="it.description" class="ft-in" maxlength="255" :disabled="!can.update" :placeholder="$t('What is being paid for')" /></div>
            <div class="ft-c ft-ben"><span class="ft-ml">{{ $t('Beneficiary') }}</span>
              <beneficiary-select :contacts="contacts" :users="users" :projects="projects" :warehouse-id="it.beneficiary_warehouse_id" :contact-id="it.contact_id" :user-id="it.beneficiary_user_id" :legacy="it.beneficiary || ''" :placeholder="$t('Search contacts, users or projects')" @select="v => onBeneficiary(it, v)" />
            </div>
            <div v-if="can.update" class="ft-tools">
              <button type="button" class="ft-ib" :disabled="ii === 0" :aria-label="$t('Move up')" @click="moveItem(s, ii, -1)"><icons name="chevron-down" class="w-3.5 h-3.5 rotate-180" /></button>
              <button type="button" class="ft-ib" :disabled="ii === s.items.length - 1" :aria-label="$t('Move down')" @click="moveItem(s, ii, 1)"><icons name="chevron-down" class="w-3.5 h-3.5" /></button>
              <button type="button" class="ft-ib is-red" :aria-label="$t('Remove item')" @click="s.items.splice(ii, 1)"><icons name="trash" class="w-3.5 h-3.5" /></button>
            </div>
          </div>
          <div class="ft-line2">
            <div class="ft-c"><span class="ft-ml">{{ $t('Request') }}</span><amount-input v-model="it.request" :decimals="2" :disabled="!can.update" /></div>
            <div class="ft-c"><span class="ft-ml">{{ $t('Allocation') }}</span><amount-input v-model="it.allocation" :decimals="2" :disabled="!can.update" /></div>
            <div class="ft-c"><span class="ft-ml ft-ml--m">{{ methodLabel('TT') }}</span><amount-input v-model="it.tt" :decimals="2" :disabled="!can.update" /></div>
            <div class="ft-c"><span class="ft-ml ft-ml--tzs">{{ $t('Cash') }} (TZS)</span><amount-input v-model="it.cash" :decimals="2" :disabled="!can.update" /></div>
            <div class="ft-c"><span class="ft-ml ft-ml--usd">{{ $t('Cash') }} (USD)</span><amount-input v-model="it.usd" :decimals="2" :disabled="!can.update" /></div>
            <button type="button" class="ft-more" @click="it._open = !it._open"><icons name="link" class="w-3.5 h-3.5" />{{ it._open ? $t('Hide') : $t('More') }}<span v-if="!it._open && moreCount(it)" class="ft-count">{{ moreCount(it) }}</span></button>
          </div>
          <div v-if="it._open" class="ft-more-box">
            <div class="ft-c"><span class="ft-ml">{{ $t('Project / Warehouse') }}</span>
              <auto-complete :id="'ft-wh-' + it._key" :suggestions="warehouseOptions" :model-value="it.warehouse_id" :placeholder="$t('Search project / warehouse')" clearable @update:modelValue="v => (it.warehouse_id = v || null)" />
            </div>
            <div class="ft-c ft-notes"><span class="ft-ml">{{ $t('Item notes') }}</span><input v-model="it.notes" class="ft-in" maxlength="2000" :disabled="!can.update" :placeholder="$t('Optional')" /></div>
            <div class="ft-extra">
              <span class="ft-ml">{{ $t('Other payment methods') }}</span>
              <div v-for="(l, li) in it.extra_lines" :key="l._key" class="ft-extra__row">
                <auto-complete :id="'ft-m-' + l._key" :suggestions="otherMethodOptions" :model-value="l.fund_payment_method_id" :placeholder="$t('Search a payment method')" @update:modelValue="v => (l.fund_payment_method_id = v || null)" />
                <amount-input v-model="l.amount" :decimals="2" :disabled="!can.update" />
                <button v-if="can.update" type="button" class="ft-ib is-red" :aria-label="$t('Remove')" @click="it.extra_lines.splice(li, 1)"><icons name="cross" class="w-3.5 h-3.5" /></button>
              </div>
              <button v-if="can.update && otherMethodOptions.length" type="button" class="ft-add ft-add--sm" @click="it.extra_lines.push({ _key: key(), fund_payment_method_id: null, amount: 0 })"><icons name="plus" class="w-3.5 h-3.5" />{{ $t('Add a payment method') }}</button>
            </div>
          </div>
        </div>

        <div class="ft-sec__foot">
          <button v-if="can.update" type="button" class="ft-add" @click="s.items.push(blankItem())"><icons name="plus" class="w-4 h-4" />{{ $t('Add item') }}</button>
          <span class="ft-sec__sum"><small>TT</small>{{ money(sum(s, 'tt')) }}<small>{{ $t('Cash') }}</small>{{ money(sum(s, 'cash')) }}<small>USD</small>{{ money(sum(s, 'usd')) }}</span>
        </div>
      </section>

      <section v-if="can.update" class="lg-card ft-card ft-newsec">
        <div class="ft-newsec__t"><icons name="collection" class="w-5 h-5" /><b>{{ $t('Add a category section') }}</b></div>
        <auto-complete id="ft-newcat" class="ft-newsec__pick" :suggestions="freeCategoryOptions" :model-value="newCategory" :placeholder="$t('Search or choose a category')" clearable @update:modelValue="v => (newCategory = v || null)" />
        <button type="button" class="lg-btn lg-btn-soft" :disabled="!newCategory" @click="addSection"><icons name="plus" class="w-4 h-4" />{{ $t('Add section') }}</button>
      </section>

      <section class="lg-card ft-card ft-grand">
        <div class="ft-grand__row">
          <span><small>{{ $t('Request') }}</small>{{ money(grand('request')) }}</span>
          <span><small>{{ $t('Allocation') }}</small><b>{{ money(grand('allocation')) }}</b></span>
          <span><small>TT</small>{{ money(grand('tt')) }}</span>
          <span class="ft-green"><small>{{ $t('Cash') }} (TZS)</small>{{ money(grand('cash')) }}</span>
          <span class="ft-blue"><small>{{ $t('Cash') }} (USD)</small>{{ money(grand('usd')) }}</span>
        </div>
        <label class="ft-f"><span>{{ $t('Note (printed under the totals)') }}</span><input v-model="form.note" class="ft-in" maxlength="500" :disabled="!can.update" /></label>
        <p class="ft-help"><icons name="exclamation" class="w-4 h-4" />{{ $t('Rows marked in amber have a payment split that is different from the allocation. The generated sheet can only be submitted when every split equals its allocation.') }}</p>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import AmountInput from './AmountInput.vue';
import BeneficiarySelect from './BeneficiarySelect.vue';
import CurrencySelect from './CurrencySelect.vue';
import { fmt } from './fundFormat';

let seq = 0;
const key = () => 'k' + ++seq;

export default {
  components: { Link, AdminLayout, PageHeader, AutoComplete, AmountInput, BeneficiarySelect, CurrencySelect },
  props: { template: Object, categories: Array, accounts: Array, methods: Array, contacts: Array, users: Array, projects: Array, warehouses: Array, can: Object },
  data() {
    const f = JSON.parse(JSON.stringify(this.template.data));
    f.balances = (f.balances || []).map(b => ({ _key: key(), ...b, amount: Number(b.amount) || 0, exchange_rate: Number(b.exchange_rate) || (b.currency === 'TZS' ? 1 : f.usd_rate) }));
    f.usd_rate = Number(f.usd_rate) || 1;
    f.sections = f.sections.map(s => ({
      _key: key(), ...s,
      items: s.items.map(i => ({ _key: key(), _open: false, ...i, ...['request', 'allocation', 'tt', 'cash', 'usd'].reduce((o, k) => ({ ...o, [k]: Number(i[k]) || 0 }), {}), beneficiary: i.beneficiary || '', extra_lines: (i.extra_lines || []).map(l => ({ _key: key(), ...l })) })),
    }));
    return { form: f, saved: '', busy: false, newCategory: null };
  },
  computed: {
    dirty() { return this.snapshot() !== this.saved; },
    accountOptions() { return this.accounts.map(a => ({ id: a.id, label: this.accountLabel(a) })); },
    warehouseOptions() { return this.warehouses.map(w => ({ id: w.id, label: w.name })); },
    otherMethodOptions() { return this.methods.filter(m => !['TT', 'CASH', 'USD'].includes(String(m.short_label).toUpperCase())).map(m => ({ id: m.id, label: m.name + ' (' + m.short_label + ')' })); },
    freeCategoryOptions() { const used = new Set(this.form.sections.map(s => s.category_id)); return this.categories.filter(c => !used.has(c.id)).map(c => ({ id: c.id, label: c.name })); },
    balanceTzsOnly() { return this.form.balances.reduce((t, b) => t + (b.currency === 'TZS' ? Number(b.amount) || 0 : 0), 0); },
    balanceUsdOnly() { return this.form.balances.reduce((t, b) => t + (b.currency === 'USD' ? Number(b.amount) || 0 : 0), 0); },
    hasUsdRow() { return this.form.balances.some(b => b.currency === 'USD'); },
    balanceAfter() { return this.balanceTzsOnly - this.grand('allocation'); },
  },
  created() { this.saved = this.snapshot(); },
  methods: {
    money: fmt,
    key,
    snapshot() { return JSON.stringify(this.payload()); },
    letter(i) { return String.fromCharCode(65 + (i % 26)); },
    accountLabel(a) { return a.account_number ? a.name + ' (' + a.account_number + ')' : a.name; },
    categoryName(id) { const c = this.categories.find(x => x.id === id); return c ? c.name : ''; },
    categoryOptionsFor(s) { const used = new Set(this.form.sections.filter(x => x !== s).map(x => x.category_id)); return this.categories.filter(c => !used.has(c.id)).map(c => ({ id: c.id, label: c.name })); },
    methodLabel(short) { const m = this.methods.find(x => String(x.short_label).toUpperCase() === short); return m ? m.short_label : short; },
    balanceTzs(b) { return (Number(b.amount) || 0) * (b.currency === 'TZS' ? 1 : Number(b.exchange_rate) || 0); },
    blankItem() { return { _key: key(), _open: false, description: '', beneficiary: '', contact_id: null, beneficiary_user_id: null, beneficiary_warehouse_id: null, request: 0, allocation: 0, tt: 0, cash: 0, usd: 0, extra_lines: [], warehouse_id: null, notes: null }; },
    addBalance() { this.form.balances.push({ _key: key(), label: '', fund_account_id: null, currency: 'TZS', amount: 0, exchange_rate: 1 }); },
    onAccount(b, id) {
      b.fund_account_id = id || null;
      const a = this.accounts.find(x => x.id === id);
      if (!a) return;
      b.label = this.accountLabel(a);
      b.currency = a.currency;
      b.exchange_rate = a.currency === 'TZS' ? 1 : (Number(b.exchange_rate) > 1 ? b.exchange_rate : this.form.usd_rate);
    },
    onCurrency(b, v) { b.currency = v || 'TZS'; b.exchange_rate = b.currency === 'TZS' ? 1 : (Number(b.exchange_rate) > 1 ? b.exchange_rate : (b.currency === 'USD' ? this.form.usd_rate : null)); },
    onBeneficiary(it, v) {
      it.contact_id = v.contact_id || null; it.beneficiary_user_id = v.beneficiary_user_id || null; it.beneficiary_warehouse_id = v.beneficiary_warehouse_id || null; it.beneficiary = v.name || '';
    },
    sum(s, k) { return s.items.reduce((t, i) => t + (Number(i[k]) || 0), 0); },
    grand(k) { return this.form.sections.reduce((t, s) => t + this.sum(s, k), 0); },
    splitOff(it) {
      const extra = (it.extra_lines || []).reduce((t, l) => t + (Number(l.amount) || 0), 0);
      return (Number(it.allocation) || 0) > 0 && Math.abs((Number(it.tt) || 0) + (Number(it.cash) || 0) + (Number(it.usd) || 0) + extra - (Number(it.allocation) || 0)) > 0.009;
    },
    moreCount(it) { return (it.warehouse_id ? 1 : 0) + (it.notes ? 1 : 0) + (it.extra_lines || []).length; },
    moveItem(s, i, d) { const j = i + d; if (j < 0 || j >= s.items.length) return; s.items.splice(j, 0, s.items.splice(i, 1)[0]); },
    moveSection(i, d) { const j = i + d; if (j < 0 || j >= this.form.sections.length) return; this.form.sections.splice(j, 0, this.form.sections.splice(i, 1)[0]); },
    async removeSection(i) {
      const s = this.form.sections[i];
      if (s.items.length && !(await this.$confirm(this.$t('Remove this whole section with its :n rows?', { n: s.items.length })))) return;
      this.form.sections.splice(i, 1);
    },
    addSection() { if (!this.newCategory) return; this.form.sections.push({ _key: key(), category_id: this.newCategory, category: this.categoryName(this.newCategory), items: [this.blankItem()] }); this.newCategory = null; },
    payload() {
      const f = JSON.parse(JSON.stringify(this.form));
      f.balances = f.balances.map(({ _key, ...b }) => b);
      f.sections = f.sections.map(({ _key, ...s }) => ({ ...s, items: s.items.filter(i => String(i.description || '').trim() !== '').map(({ _key, _open, ...i }) => ({ ...i, extra_lines: (i.extra_lines || []).filter(l => l.fund_payment_method_id).map(({ _key, ...l }) => l) })) }));
      return f;
    },
    save() {
      this.busy = true;
      return new Promise(resolve => router.put(this.route('fund-allocations.template.update'), this.payload(), {
        preserveScroll: true,
        onSuccess: () => { this.saved = this.snapshot(); },
        onFinish: () => { this.busy = false; resolve(!this.dirty); },
      }));
    },
    async generate() {
      if (this.dirty && this.can.update) {
        if (!(await this.$confirm(this.$t('Save your changes to the template and generate a Draft sheet from it?')))) return;
        if (!(await this.save())) return;
      } else if (!(await this.$confirm(this.$t('Generate a Draft allocation sheet from this template?')))) return;
      this.busy = true;
      router.post(this.route('fund-allocations.template.generate'), {}, { onFinish: () => (this.busy = false) });
    },
  },
};
</script>

<style scoped>
.ft-page { max-width: 1280px; margin-inline: auto; }
.ft-meta { display: flex; align-items: center; flex-wrap: wrap; gap: 0.4rem; margin: 0 0 0.8rem; font-size: 0.8rem; color: #64748b; } .ft-dirty { margin-left: 0.5rem; padding: 1px 9px; border-radius: 99px; font-weight: 800; color: #92400e; background: #fef3c7; }
.ft-ro { display: flex; align-items: center; gap: 0.5rem; margin: 0 0 0.8rem; padding: 0.55rem 0.8rem; border-radius: 12px; font-size: 0.84rem; color: #1e3a8a; background: #eff6ff; box-shadow: inset 0 0 0 1px #bfdbfe; }
.ft-card { margin-bottom: 1rem; }
.ft-head { display: flex; flex-wrap: wrap; gap: 0.8rem; margin-bottom: 1rem; } .ft-grow { flex: 1 1 260px; }
.ft-f { display: grid; gap: 4px; min-width: 180px; } .ft-f > span, .ft-ml { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: #64748b; }
.ft-ml { display: block; margin-bottom: 3px; } .ft-ml--tzs { color: #15803d; } .ft-ml--usd { color: #1d4ed8; } .ft-ml--m { color: #6d28d9; }
.ft-in { width: 100%; padding: 0.5rem 0.65rem; border: 0; border-radius: 10px; font-size: 0.86rem; background: #fff; box-shadow: inset 0 0 0 1.5px #cbd5e1; } .ft-in:focus { outline: 0; box-shadow: inset 0 0 0 2px #3b82f6, 0 0 0 4px rgba(59, 130, 246, 0.15); } .ft-in:disabled { background: #f8fafc; box-shadow: inset 0 0 0 1px #e2e8f0; }
.ft-h { display: flex; align-items: center; gap: 0.4rem; margin: 0.4rem 0 0.6rem; font-size: 0.92rem; font-weight: 800; }

/* balances: green for TZS, blue for USD */
.ft-bal { position: relative; display: grid; grid-template-columns: 54px minmax(0, 1.5fr) minmax(0, 1.3fr) minmax(0, 1.1fr) minmax(0, 1.1fr) minmax(0, 0.9fr) minmax(0, 1fr) 36px; gap: 0.6rem; align-items: start; margin-bottom: 0.5rem; padding: 0.6rem 0.7rem; border-radius: 16px; border-left: 5px solid #16a34a; background: linear-gradient(90deg, rgba(34, 197, 94, 0.09), rgba(34, 197, 94, 0.02) 60%); box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.22); transition: transform 0.15s ease, box-shadow 0.2s ease; }
.ft-bal.is-fx { border-left-color: #2563eb; background: linear-gradient(90deg, rgba(59, 130, 246, 0.11), rgba(59, 130, 246, 0.02) 60%); box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.26); }
.ft-bal:hover { transform: translateY(-1px); }
.ft-bal :deep(.relative), .ft-line1 :deep(.relative), .ft-line2 :deep(.relative), .ft-more-box :deep(.relative) { margin-bottom: 0 !important; }
.ft-cur { align-self: end; margin-bottom: 2px; display: grid; place-items: center; height: 30px; border-radius: 99px; font-size: 0.74rem; font-weight: 800; color: #fff; } .ft-cur.c-tzs { background: linear-gradient(145deg, #22c55e, #15803d); } .ft-cur.c-usd { background: linear-gradient(145deg, #3b82f6, #1d4ed8); }
.ft-c { min-width: 0; } .ft-c-val b { display: block; padding: 0.5rem 0; font-size: 0.9rem; font-variant-numeric: tabular-nums; } .ft-c-x { align-self: end; } .ft-one { display: block; padding: 0.5rem 0.2rem; color: #64748b; font-weight: 700; }
.ft-green { color: #15803d !important; } .ft-blue { color: #1d4ed8 !important; } .ft-red { color: #dc2626 !important; }
.ft-totals { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 0.6rem; margin-top: 0.9rem; }
.ft-tot { padding: 0.6rem 0.8rem; border-radius: 14px; background: #f1f5f9; box-shadow: inset 0 0 0 1px #e2e8f0; } .ft-tot small { display: block; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: #64748b; } .ft-tot b { font-size: 1.08rem; font-variant-numeric: tabular-nums; } .ft-tot b i { margin-left: 4px; font-size: 0.66rem; font-style: normal; opacity: 0.7; }
.ft-tot--tzs { background: #f0fdf4; box-shadow: inset 0 0 0 1px #bbf7d0; } .ft-tot--tzs b { color: #15803d; } .ft-tot--usd { background: #eff6ff; box-shadow: inset 0 0 0 1px #bfdbfe; } .ft-tot--usd b { color: #1d4ed8; } .ft-tot--after { background: #fff1f2; box-shadow: inset 0 0 0 1px #fecdd3; } .ft-tot--after b { color: #be123c; }

/* sections and rows */
.ft-sec__head { display: flex; align-items: center; gap: 0.7rem; flex-wrap: wrap; margin-bottom: 0.7rem; } .ft-sec__cat { flex: 1 1 260px; min-width: 220px; max-width: 420px; } .ft-sec__cat :deep(.relative) { margin-bottom: 0; } .ft-sec__cat b { font-size: 1rem; }
.ft-letter { display: grid; place-items: center; width: 34px; height: 34px; border-radius: 11px; font-weight: 800; color: #fff; background: linear-gradient(145deg, #3b82f6, #1d4ed8); box-shadow: 0 8px 14px -8px #1d4ed8; }
.ft-cnt { padding: 1px 10px; border-radius: 99px; font-size: 0.74rem; font-weight: 800; background: rgba(100, 116, 139, 0.16); }
.ft-subtot { font-size: 0.86rem; font-variant-numeric: tabular-nums; } .ft-subtot small { display: block; font-size: 0.62rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: #94a3b8; } .ft-sec__tools { margin-left: auto; display: flex; gap: 0.3rem; }
.ft-item { margin-bottom: 0.55rem; padding: 0.65rem 0.7rem; border-radius: 16px; background: var(--lg-soft, #f8fafc); box-shadow: inset 0 0 0 1px var(--lg-line, #e2e8f0); transition: box-shadow 0.18s ease, transform 0.18s ease; } .ft-item:hover { box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.4); }
.ft-warn { background: #fffbeb; box-shadow: inset 0 0 0 1.5px #fcd34d; }
.ft-line1 { display: grid; grid-template-columns: 34px minmax(0, 1.5fr) minmax(0, 1.2fr) auto; gap: 0.6rem; align-items: end; } .ft-sn { align-self: end; padding-bottom: 8px; text-align: center; font-weight: 800; color: #64748b; } .ft-tools { display: flex; gap: 0.25rem; align-self: end; padding-bottom: 3px; }
.ft-line2 { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)) auto; gap: 0.6rem; align-items: end; margin-top: 0.5rem; padding-left: 42px; }
.ft-more { align-self: end; display: inline-flex; align-items: center; gap: 0.3rem; min-height: 0 !important; padding: 0.5rem 0.8rem; border: 0; border-radius: 10px; font-size: 0.78rem; font-weight: 800; color: #1d4ed8; background: #eff6ff; cursor: pointer; } .ft-more:hover { background: #dbeafe; } .ft-count { padding: 0 6px; border-radius: 99px; color: #fff; background: #2563eb; font-size: 0.68rem; }
.ft-more-box { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr); gap: 0.6rem 0.8rem; margin: 0.6rem 0 0 42px; padding: 0.7rem; border-radius: 14px; background: rgba(59, 130, 246, 0.06); } .ft-extra { grid-column: 1 / -1; }
.ft-extra__row { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) auto; gap: 0.5rem; align-items: center; margin-bottom: 0.3rem; } .ft-extra__row :deep(.relative) { margin-bottom: 0; }
.ft-sec__foot { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.6rem; } .ft-sec__sum { font-size: 0.82rem; font-weight: 700; font-variant-numeric: tabular-nums; } .ft-sec__sum small { margin: 0 0.4rem 0 0.9rem; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: #94a3b8; }
.ft-ib { display: inline-grid; place-items: center; width: 30px; height: 30px; min-height: 0 !important; border: 0; border-radius: 9px; color: #475569; background: #f1f5f9; cursor: pointer; transition: transform 0.15s ease, background 0.15s ease; } .ft-ib:hover:not(:disabled) { background: #e2e8f0; transform: translateY(-1px); } .ft-ib:disabled { opacity: 0.35; cursor: default; } .ft-ib.is-red { color: #be123c; background: #fff1f2; }
.ft-add { display: inline-flex; align-items: center; gap: 0.4rem; margin-top: 0.3rem; min-height: 0 !important; padding: 0.45rem 0.85rem; border: 0; border-radius: 10px; font-size: 0.82rem; font-weight: 800; color: #1d4ed8; background: #eff6ff; cursor: pointer; } .ft-add:hover { background: #dbeafe; } .ft-add--sm { padding: 0.3rem 0.65rem; font-size: 0.76rem; }
.ft-newsec { display: flex; align-items: center; flex-wrap: wrap; gap: 0.8rem; } .ft-newsec__t { display: flex; align-items: center; gap: 0.5rem; color: #1d4ed8; } .ft-newsec__pick { flex: 1 1 260px; max-width: 420px; } .ft-newsec__pick :deep(.relative) { margin-bottom: 0; }
.ft-grand__row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.7rem; margin-bottom: 0.8rem; } .ft-grand__row span { padding: 0.6rem 0.8rem; border-radius: 14px; background: #eef3fa; font-weight: 800; font-size: 1rem; font-variant-numeric: tabular-nums; color: #1c3f66; } .ft-grand__row small { display: block; font-size: 0.64rem; letter-spacing: 0.05em; text-transform: uppercase; color: #64748b; } .ft-grand__row span.ft-green { background: #f0fdf4; } .ft-grand__row span.ft-blue { background: #eff6ff; }
.ft-help { display: flex; gap: 0.5rem; margin: 0.8rem 0 0; font-size: 0.78rem; color: #92400e; }
@media (max-width: 1000px) { .ft-bal { grid-template-columns: 54px minmax(0, 1fr) minmax(0, 1fr) 36px; } .ft-line2 { grid-template-columns: repeat(2, minmax(0, 1fr)); padding-left: 0; } .ft-more-box { margin-left: 0; grid-template-columns: 1fr; } .ft-line1 { grid-template-columns: 34px minmax(0, 1fr) auto; } .ft-ben { grid-column: 2 / 4; } }
html.dark .ft-in { background: #0f172a; color: #f1f5f9; box-shadow: inset 0 0 0 1.5px #334155; } html.dark .ft-tot { background: rgba(255, 255, 255, 0.06); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.1); } html.dark .ft-grand__row span { background: rgba(255, 255, 255, 0.06); color: #e2e8f0; } html.dark .ft-ib { background: rgba(255, 255, 255, 0.07); color: #cbd5e1; }
</style>

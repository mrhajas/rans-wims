<template>
  <admin-layout :title="$t('Funds Allocation Settings')">
    <div class="lg-page px-4 md:px-0 fs-page">
      <page-header icon="cog" :title="$t('Funds Allocation Settings')" :subtitle="$t('Set up the accounts, categories and payment methods used on every allocation sheet.')">
        <Link v-if="$can('read-fund-allocations')" :href="route('fund-allocations.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('Allocation Sheets') }}</Link>
        <Link v-if="$can('read-fund-allocation-reports')" :href="route('fund-allocations.reports')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" />{{ $t('Reports') }}</Link>
      </page-header>

      <div class="lg-note lg-note-blue mb-4">
        <icons name="info" class="w-4 h-4" />
        <div>
          {{ $t('When you prepare a sheet you list the money available that day by picking accounts from this page and typing the balance of each. Accounts in a currency other than TZS need an exchange rate (1 unit in TZS) on each sheet, so the total available is always worked out in TZS.') }}
        </div>
      </div>

      <!-- First-time visitors: no accounts yet -->
      <section v-if="accounts.length === 0" class="lg-card fs-first">
        <div class="fs-first-ico lg-ico-navy"><icons name="cash" class="w-8 h-8" /></div>
        <h2 class="fs-first-title">{{ $t('Start by adding your first account') }}</h2>
        <p class="fs-first-text">{{ $t('An account is a place where company money sits: a bank account, the cash box, a mobile money wallet. Add them once here and pick them whenever you prepare an allocation sheet.') }}</p>
        <button type="button" class="lg-btn lg-btn-primary fs-first-btn" @click="openForm('account')"><icons name="plus" class="w-4 h-4" />{{ $t('Add your first account') }}</button>
        <p class="fs-first-hint">{{ $t('Categories and payment methods are already set up for you, you can change them in the other tabs.') }}</p>
      </section>

      <div class="fs-tabs" role="tablist">
        <button v-for="t in tabs" :key="t.key" type="button" role="tab" class="fs-tab" :class="{ 'is-active': tab === t.key }" :aria-selected="tab === t.key" @click="tab = t.key">
          <icons :name="t.icon" class="w-4 h-4" />
          <span>{{ t.label }}</span>
          <span class="fs-tab-count">{{ t.count }}</span>
        </button>
      </div>

      <!-- Accounts -->
      <section v-show="tab === 'accounts'" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="cash" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Accounts') }}</h2>
            <p class="lg-card-sub">{{ $t('Bank, cash and mobile money accounts that hold the money you allocate.') }}</p>
          </div>
          <button type="button" class="lg-btn lg-btn-primary" @click="openForm('account')"><icons name="plus" class="w-4 h-4" />{{ $t('Add account') }}</button>
        </header>
        <div v-if="accounts.length" class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Account') }}</th>
                <th>{{ $t('Type') }}</th>
                <th>{{ $t('Currency') }}</th>
                <th>{{ $t('Account Number') }}</th>
                <th>{{ $t('Active') }}</th>
                <th class="text-right">{{ $t('Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(a, i) in accounts" :key="a.id" :class="{ 'fs-off': !a.is_active }">
                <td :data-label="$t('Account')">
                  <div class="lg-main">{{ a.name }}</div>
                  <div v-if="a.description" class="lg-subtext lg-clip">{{ a.description }}</div>
                </td>
                <td :data-label="$t('Type')"><span class="lg-chip" :class="typeTone(a.type)">{{ typeLabel(a.type) }}</span></td>
                <td :data-label="$t('Currency')"><span class="lg-chip lg-chip-slate">{{ a.currency }}</span></td>
                <td :data-label="$t('Account Number')" class="fx-num">{{ a.account_number || '-' }}</td>
                <td :data-label="$t('Active')">
                  <button type="button" class="fs-switch" :class="{ 'is-on': a.is_active }" :aria-pressed="a.is_active" @click="toggle('fund-allocations.settings.accounts.update', a)"><span></span></button>
                </td>
                <td :data-label="$t('Actions')">
                  <div class="lg-actions">
                    <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Move up')" :disabled="i === 0" @click="move('accounts', a, 'up')"><icons name="chevron-down" class="w-4 h-4 transform rotate-180" /></button>
                    <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Move down')" :disabled="i === accounts.length - 1" @click="move('accounts', a, 'down')"><icons name="chevron-down" class="w-4 h-4" /></button>
                    <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Edit')" @click="openForm('account', a)"><icons name="edit" class="w-4 h-4" /></button>
                    <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Delete')" @click="remove('fund-allocations.settings.accounts.destroy', a, $t('Delete this account? Past sheets keep their own copy of its balance.'))"><icons name="trash" class="w-4 h-4" /></button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="fs-empty">{{ $t('No accounts yet.') }}</div>
      </section>

      <!-- Categories -->
      <section v-show="tab === 'categories'" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="collection" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Categories') }}</h2>
            <p class="lg-card-sub">{{ $t('The groups of payments on a sheet (Administration, Procurement, ...). Each ends with its own subtotal.') }}</p>
          </div>
          <button type="button" class="lg-btn lg-btn-primary" @click="openForm('category')"><icons name="plus" class="w-4 h-4" />{{ $t('Add category') }}</button>
        </header>
        <div v-if="categories.length" class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th class="w-10">#</th>
                <th>{{ $t('Category') }}</th>
                <th>{{ $t('Used By') }}</th>
                <th>{{ $t('Active') }}</th>
                <th class="text-right">{{ $t('Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(c, i) in categories" :key="c.id" :class="{ 'fs-off': !c.is_active }">
                <td data-label="#"><span class="fs-letter">{{ letter(i) }}</span></td>
                <td :data-label="$t('Category')">
                  <span class="lg-main">{{ c.name }}</span>
                  <span v-if="c.is_system" class="lg-chip lg-chip-slate ml-2" style="text-transform: none">{{ $t('Built-in') }}</span>
                </td>
                <td :data-label="$t('Used By')"><span class="lg-chip" :class="c.items_count ? 'lg-chip-blue' : 'lg-chip-slate'" style="text-transform: none">{{ c.items_count }} {{ $t('items') }}</span></td>
                <td :data-label="$t('Active')">
                  <button type="button" class="fs-switch" :class="{ 'is-on': c.is_active }" :aria-pressed="c.is_active" @click="toggle('fund-allocations.settings.categories.update', c)"><span></span></button>
                </td>
                <td :data-label="$t('Actions')">
                  <div class="lg-actions">
                    <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Move up')" :disabled="i === 0" @click="move('categories', c, 'up')"><icons name="chevron-down" class="w-4 h-4 transform rotate-180" /></button>
                    <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Move down')" :disabled="i === categories.length - 1" @click="move('categories', c, 'down')"><icons name="chevron-down" class="w-4 h-4" /></button>
                    <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Edit')" @click="openForm('category', c)"><icons name="edit" class="w-4 h-4" /></button>
                    <button
                      type="button"
                      class="lg-icon-btn is-red lg-tip"
                      :data-tip="c.is_system ? $t('Built-in categories cannot be deleted, deactivate it instead.') : c.items_count ? $t('Used by sheets, deactivate it instead.') : $t('Delete')"
                      :disabled="c.is_system || c.items_count > 0"
                      @click="remove('fund-allocations.settings.categories.destroy', c, $t('Delete this category?'))"
                    >
                      <icons name="trash" class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="fs-empty">{{ $t('No categories yet.') }}</div>
        <p class="lg-subtext mt-3">{{ $t('The letter in front of each category follows this order on the printed sheet. Inactive categories are hidden when preparing a sheet but stay on old sheets.') }}</p>
      </section>

      <!-- Payment methods -->
      <section v-show="tab === 'methods'" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="mobile" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Payment Methods') }}</h2>
            <p class="lg-card-sub">{{ $t('How an allocation is paid out. One item can be split across several methods.') }}</p>
          </div>
          <button type="button" class="lg-btn lg-btn-primary" @click="openForm('method')"><icons name="plus" class="w-4 h-4" />{{ $t('Add payment method') }}</button>
        </header>
        <div v-if="methods.length" class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Method') }}</th>
                <th>{{ $t('Short Label') }}</th>
                <th>{{ $t('Used By') }}</th>
                <th>{{ $t('Active') }}</th>
                <th class="text-right">{{ $t('Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(m, i) in methods" :key="m.id" :class="{ 'fs-off': !m.is_active }">
                <td :data-label="$t('Method')"><span class="lg-main">{{ m.name }}</span></td>
                <td :data-label="$t('Short Label')"><span class="lg-chip" :class="m.currency === 'USD' ? 'lg-chip-green' : 'lg-chip-indigo'">{{ m.currency === 'USD' ? '$ ' : '' }}{{ m.short_label }}</span></td>
                <td :data-label="$t('Used By')"><span class="lg-chip" :class="m.lines_count ? 'lg-chip-blue' : 'lg-chip-slate'" style="text-transform: none">{{ m.lines_count }} {{ $t('payments') }}</span></td>
                <td :data-label="$t('Active')">
                  <button type="button" class="fs-switch" :class="{ 'is-on': m.is_active }" :aria-pressed="m.is_active" @click="toggle('fund-allocations.settings.methods.update', m)"><span></span></button>
                </td>
                <td :data-label="$t('Actions')">
                  <div class="lg-actions">
                    <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Move up')" :disabled="i === 0" @click="move('methods', m, 'up')"><icons name="chevron-down" class="w-4 h-4 transform rotate-180" /></button>
                    <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Move down')" :disabled="i === methods.length - 1" @click="move('methods', m, 'down')"><icons name="chevron-down" class="w-4 h-4" /></button>
                    <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Edit')" @click="openForm('method', m)"><icons name="edit" class="w-4 h-4" /></button>
                    <button
                      type="button"
                      class="lg-icon-btn is-red lg-tip"
                      :data-tip="m.lines_count > 0 ? $t('Used by sheets, deactivate it instead.') : $t('Delete')"
                      :disabled="m.lines_count > 0"
                      @click="remove('fund-allocations.settings.methods.destroy', m, $t('Delete this payment method?'))"
                    >
                      <icons name="trash" class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="fs-empty">{{ $t('No payment methods yet.') }}</div>
        <p class="lg-subtext mt-3">{{ $t('The order here is the order of the payment columns on the sheet (for example TT, CASH, USD).') }}</p>
      </section>
    </div>

    <!-- Add / edit modal -->
    <tec-dialog-modal :show="!!modal" @close="closeForm" max-width="lg">
      <template #title>
        <div v-if="modal" class="flex items-center gap-3">
          <span class="lg-card-ico" :class="modalMeta.tone"><icons :name="modalMeta.icon" class="w-5 h-5" /></span>
          <div>
            <div class="lg-card-title">{{ editing ? $t('Edit') : $t('Add') }}: {{ modalMeta.title }}</div>
            <div class="lg-card-sub">{{ modalMeta.sub }}</div>
          </div>
        </div>
      </template>
      <template #content>
        <form v-if="modal" id="fs-form" class="lg-fields fs-form" @submit.prevent="save">
          <div class="lg-span-all">
            <text-input v-model="form.name" :error="form.errors.name" :label="$t('Name')" />
          </div>

          <template v-if="modal === 'account'">
            <div>
              <select-input v-model="form.type" :error="form.errors.type" :label="$t('Type')">
                <option v-for="t in types" :key="t" :value="t">{{ typeLabel(t) }}</option>
              </select-input>
            </div>
            <div>
              <currency-select id="fx-set-cur" v-model="form.currency" :error="form.errors.currency" :label="$t('Currency')" shortcuts />
            </div>
            <div class="lg-span-all">
              <text-input v-model="form.account_number" :error="form.errors.account_number" :label="$t('Account Number')" />
            </div>
            <div class="lg-span-all">
              <text-input v-model="form.description" :error="form.errors.description" :label="$t('Description')" />
            </div>
          </template>

          <template v-if="modal === 'method'">
            <div>
              <text-input v-model="form.short_label" :error="form.errors.short_label" :label="$t('Short Label')" maxlength="12" placeholder="TT" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t('Pays in') }}</label>
              <css-select v-model="form.currency" class="border-gray-300 rounded-md shadow-sm w-full">
                <option value="TZS">TZS</option>
                <option value="USD">{{ $t('USD (asks for an exchange rate)') }}</option>
              </css-select>
            </div>
          </template>

          <div class="fs-check">
            <label class="flex items-center gap-2">
              <input type="checkbox" v-model="form.is_active" class="rounded border-gray-300" />
              <span>{{ $t('Active') }}</span>
            </label>
          </div>
        </form>
      </template>
      <template #footer>
        <div class="flex items-center justify-end gap-2">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeForm">{{ $t('Cancel') }}</button>
          <button type="submit" form="fs-form" class="lg-btn lg-btn-primary" :disabled="form.processing">
            <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />{{ $t('Save') }}
          </button>
        </div>
      </template>
    </tec-dialog-modal>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import TextInput from '@/Shared/TextInput.vue';
import CurrencySelect from './CurrencySelect.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';

export default {
  components: { Link, CurrencySelect, TextInput, SelectInput, AdminLayout, PageHeader, TecDialogModal },

  props: {
    accounts: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    methods: { type: Array, default: () => [] },
    types: { type: Array, default: () => ['bank', 'cash', 'mobile', 'other'] },
  },

  data() {
    return {
      tab: 'accounts',
      modal: null, // 'account' | 'category' | 'method'
      editing: null,
      form: this.$inertia.form({}),
    };
  },

  computed: {
    tabs() {
      return [
        { key: 'accounts', label: this.$t('Accounts'), icon: 'cash', count: this.accounts.length },
        { key: 'categories', label: this.$t('Categories'), icon: 'collection', count: this.categories.length },
        { key: 'methods', label: this.$t('Payment Methods'), icon: 'mobile', count: this.methods.length },
      ];
    },
    modalMeta() {
      return {
        account: { icon: 'cash', tone: 'lg-ico-navy', title: this.$t('Account'), sub: this.$t('Where the money is kept.') },
        category: { icon: 'collection', tone: 'lg-ico-amber', title: this.$t('Category'), sub: this.$t('A group of payments on the sheet.') },
        method: { icon: 'mobile', tone: 'lg-ico-emerald', title: this.$t('Payment Method'), sub: this.$t('How a payment is made.') },
      }[this.modal] || {};
    },
  },

  methods: {
    letter(i) {
      let n = i;
      let s = '';
      do {
        s = String.fromCharCode(65 + (n % 26)) + s;
        n = Math.floor(n / 26) - 1;
      } while (n >= 0);
      return s;
    },
    typeLabel(t) {
      return { bank: this.$t('Bank'), cash: this.$t('Cash'), mobile: this.$t('Mobile money'), other: this.$t('Other') }[t] || t;
    },
    typeTone(t) {
      return { bank: 'lg-chip-blue', cash: 'lg-chip-green', mobile: 'lg-chip-violet', other: 'lg-chip-slate' }[t] || 'lg-chip-slate';
    },
    openForm(type, row = null) {
      this.modal = type;
      this.editing = row;
      const base = { name: row ? row.name : '', is_active: row ? !!row.is_active : true };
      if (type === 'account') {
        Object.assign(base, {
          type: row ? row.type : 'bank',
          currency: row ? row.currency : 'TZS',
          account_number: row ? row.account_number || '' : '',
          description: row ? row.description || '' : '',
        });
      } else if (type === 'method') {
        base.short_label = row ? row.short_label : '';
        base.currency = row ? row.currency || 'TZS' : 'TZS';
      }
      this.form = this.$inertia.form(base);
    },
    closeForm() {
      this.modal = null;
      this.editing = null;
    },
    save() {
      const key = { account: 'accounts', category: 'categories', method: 'methods' }[this.modal];
      const url = this.editing ? this.route(`fund-allocations.settings.${key}.update`, this.editing.id) : this.route(`fund-allocations.settings.${key}.store`);
      const opts = { preserveScroll: true, onSuccess: () => this.closeForm() };
      if (this.modal === 'account') {
        this.form.currency = String(this.form.currency || '').toUpperCase();
      }
      this.editing ? this.form.put(url, opts) : this.form.post(url, opts);
    },
    toggle(routeName, row) {
      this.$inertia.put(this.route(routeName, row.id), { is_active: !row.is_active }, { preserveScroll: true });
    },
    move(type, row, direction) {
      this.$inertia.post(this.route('fund-allocations.settings.reorder'), { type, id: row.id, direction }, { preserveScroll: true });
    },
    async remove(routeName, row, message) {
      const ok = await this.$confirm(message, { variant: 'danger', confirmText: this.$t('Delete') });
      if (ok) {
        this.$inertia.delete(this.route(routeName, row.id), { preserveScroll: true });
      }
    },
  },
};
</script>

<style scoped>
.fs-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 1rem; }
.fs-tab { display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px; border-radius: 9999px; font-size: 0.82rem; font-weight: 800; color: var(--lg-mute); background: var(--lg-card); border: 1px solid var(--lg-line); transition: all 0.15s ease; }
.fs-tab:hover { color: var(--lg-navy); transform: translateY(-1px); }
.fs-tab.is-active { color: #fff; border-color: transparent; background: linear-gradient(145deg, #2f6fb3, #0f2540); box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.9); }
.fs-tab-count { min-width: 22px; padding: 0 7px; border-radius: 9999px; font-size: 0.7rem; text-align: center; background: rgba(100, 116, 139, 0.16); }
.fs-tab.is-active .fs-tab-count { background: rgba(255, 255, 255, 0.22); }

.fs-first { display: flex; flex-direction: column; align-items: center; text-align: center; padding: 2rem 1.3rem; border-style: dashed; }
.fs-first-ico { display: inline-flex; align-items: center; justify-content: center; width: 68px; height: 68px; border-radius: 22px; color: #fff; margin-bottom: 14px; }
.fs-first-title { font-size: 1.25rem; font-weight: 800; color: var(--lg-ink); }
.fs-first-text { max-width: 560px; margin-top: 6px; font-size: 0.85rem; color: var(--lg-mute); }
.fs-first-btn { margin-top: 16px; padding: 11px 22px; font-size: 0.9rem; }
.fs-first-hint { margin-top: 12px; font-size: 0.74rem; color: var(--lg-faint); }

.fs-empty { padding: 28px 12px; text-align: center; color: var(--lg-faint); font-size: 0.85rem; }
.fs-off td { opacity: 0.55; }
.fs-off td:last-child { opacity: 1; }
.fs-letter { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 9px; font-size: 0.75rem; font-weight: 800; color: var(--lg-navy); background: rgba(28, 63, 102, 0.09); }

.fs-switch { position: relative; width: 40px; height: 22px; border-radius: 9999px; background: #cbd5e1; transition: background 0.15s ease; }
.fs-switch span { position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 9999px; background: #fff; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3); transition: transform 0.15s ease; }
.fs-switch.is-on { background: #10b981; }
.fs-switch.is-on span { transform: translateX(18px); }

.lg-icon-btn:disabled { opacity: 0.35; cursor: not-allowed; }
.fs-check { display: flex; align-items: center; font-size: 0.85rem; font-weight: 700; }
.fs-form :deep(input:not([type='checkbox'])), .fs-form :deep(select) { border-radius: 0.85rem !important; }
</style>

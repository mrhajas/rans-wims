<template>
  <admin-layout :title="$t('Template and Items')">
    <div class="lg-page ac sc-ui tr-ui wa-ui px-4 md:px-0">
      <page-header icon="collection" :title="$t('Template and Items')" :subtitle="$t('The standing lines that start every weekly document, and every item ever typed. Reports follow an item through the months.')">
        <Link :href="route('weekly-administration.index')" class="lg-btn lg-btn-soft"><icons name="doc-text" class="w-4 h-4" /><span>{{ $t('Documents') }}</span></Link>
        <button v-if="can.manage" type="button" class="lg-btn lg-btn-primary" @click="open()"><icons name="plus" class="w-4 h-4" /><span>{{ $t('Add an Item') }}</span></button>
      </page-header>

      <sc-tabs :tabs="tabs" :model-value="f.view || ''" @update:model-value="setView" />
      <section class="lg-card">
        <div class="tr-fbar"><div class="ac-f tr-wide"><label>{{ $t('Search') }}</label><input v-model="f.search" type="search" :placeholder="$t('Item or beneficiary')" autocomplete="off" /></div></div>
        <p class="wa-note"><icons name="info" class="w-4 h-4" />{{ $t('Items marked "In the template" are filled in for you when a document starts from the template, in the order shown here. Changing an item never changes a document that was already made.') }}</p>
      </section>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive tr-table tr-wide">
          <thead><tr><th class="tr-no">{{ $t('No') }}</th><th>{{ $t('Item') }}</th><th>{{ $t('Usual beneficiary') }}</th><th class="tr-num">{{ $t('Usual amount') }}</th><th>{{ $t('Use') }}</th><th class="tr-num">{{ $t('Used') }}</th><th class="tr-num">{{ $t('Spent') }}</th><th class="lg-sticky-end"></th></tr></thead>
          <tbody>
            <tr v-for="(r, n) in rows.data" :key="r.id">
              <td :data-label="$t('No')" class="tr-no">{{ (rows.from || 1) + n }}</td>
              <td :data-label="$t('Item')" class="tr-wrap"><div class="tr-cell"><b>{{ r.name }}</b><small v-if="r.last_on">{{ $t('last paid') }} {{ $date(r.last_on) }}</small></div></td>
              <td :data-label="$t('Usual beneficiary')" class="tr-wrap">{{ r.default_beneficiary || '-' }}</td>
              <td :data-label="$t('Usual amount')" class="tr-num"><span class="tr-m">{{ Number(r.default_amount) ? money(r.default_amount) : '-' }}</span></td>
              <td :data-label="$t('Use')"><span v-if="r.in_template" class="wa-sw is-tpl"><icons name="collection" class="w-3 h-3" />{{ $t('In the template') }}</span> <span v-if="!r.is_active" class="wa-sw is-off">{{ $t('Not offered') }}</span><span v-if="!r.in_template && r.is_active" class="wa-sw is-on">{{ $t('Offered') }}</span></td>
              <td :data-label="$t('Used')" class="tr-num">{{ r.used }}</td>
              <td :data-label="$t('Spent')" class="tr-num"><span class="tr-tm">{{ currency }} {{ money(r.spent) }}</span></td>
              <td class="lg-sticky-end tr-acts-cell"><span v-if="can.manage" class="tr-acts">
                <button v-if="r.in_template" type="button" class="lg-icon-btn" :aria-label="$t('Move up')" :disabled="isFirst(r)" @click="move(r, -1)"><icons name="chevron-up" class="w-4 h-4" /></button>
                <button v-if="r.in_template" type="button" class="lg-icon-btn" :aria-label="$t('Move down')" :disabled="isLast(r)" @click="move(r, 1)"><icons name="chevron-down" class="w-4 h-4" /></button>
                <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Edit')" :aria-label="$t('Edit')" @click="open(r)"><icons name="edit" class="w-4 h-4" /></button>
                <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="del = r"><icons name="trash" class="w-4 h-4" /></button>
              </span></td>
            </tr>
            <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="8">{{ $t('There is no data to display.') }}</td></tr>
          </tbody>
        </table>
      </div>
      <pager :rows="rows" />

      <travel-modal :show="editing" :title="form.id ? $t('Edit Item') : $t('Add an Item')" icon="collection" :busy="busy" @close="editing = false">
        <form id="wa-item" @submit.prevent="save">
          <div class="ac-f"><label>{{ $t('Item') }} *</label><input v-model="form.name" maxlength="190" required :placeholder="$t('For example WEEKLY FUEL - MR NASSOR')" /><span class="ac-err">{{ err('name') }}</span></div>
          <div class="tr-fbar" style="margin-top: 10px">
            <div class="ac-f"><label>{{ $t('Usual beneficiary') }}</label><input v-model="form.default_beneficiary" list="wa-people" maxlength="190" /></div>
            <div class="ac-f"><label>{{ $t('Usual amount') }} ({{ currency }})</label><input v-model="form.default_amount" type="number" min="0" step="0.01" inputmode="decimal" /><span class="ac-err">{{ err('default_amount') }}</span></div>
          </div>
          <datalist id="wa-people"><option v-for="u in users" :key="u.value" :value="u.label"></option></datalist>
          <label class="wa-toggle" style="margin-top: 12px"><input v-model="form.in_template" type="checkbox" /><span><b>{{ $t('In the template') }}</b><small>{{ $t('Filled in for you when a document starts from the template.') }}</small></span></label>
          <label class="wa-toggle" style="margin-top: 8px"><input v-model="form.is_active" type="checkbox" /><span><b>{{ $t('Offered when typing a line') }}</b><small>{{ $t('Turn off for an item that is no longer used. Old documents keep it.') }}</small></span></label>
        </form>
        <template #footer>
          <button type="button" class="lg-btn t-slate" :disabled="busy" @click="editing = false">{{ $t('Cancel') }}</button>
          <button type="submit" form="wa-item" class="lg-btn lg-btn-primary" :disabled="busy"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button>
        </template>
      </travel-modal>
      <travel-modal :show="!!del" :title="$t('Remove this item?')" :subtitle="del ? del.name : ''" icon="trash" tone="amber" :busy="busy" @close="del = null">
        <p class="lg-sub" style="margin: 0">{{ $t('It will no longer be offered or used in the template. Documents already made keep their lines.') }}</p>
        <template #footer>
          <button type="button" class="lg-btn t-slate" @click="del = null">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-red" :disabled="busy" @click="remove"><icons name="trash" class="w-4 h-4" />{{ $t('Remove') }}</button>
        </template>
      </travel-modal>
    </div>
  </admin-layout>
</template>

<script>
import TravelModal from '../Travels/TravelModal.vue';
import ScTabs from '../Subcontracts/ScTabs.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import Pager from '@/Pages/Accounts/Pager.vue';

const blank = () => ({ id: null, name: '', default_beneficiary: '', default_amount: '', in_template: true, is_active: true });

export default {
  components: { TravelModal, ScTabs, AdminLayout, PageHeader, Link, Pager },
  props: { rows: Object, filters: Object, stats: Object, users: Array, currency: String, can: Object },
  data() { return { f: { ...this.filters }, timer: null, editing: false, del: null, busy: false, form: blank() }; },
  watch: { 'f.search'() { this.queue(); } },
  computed: {
    tabs() { const s = this.stats; return [{ key: '', label: this.$t('All'), icon: 'collection', tone: 'navy', count: s.all }, { key: 'template', label: this.$t('Template'), icon: 'list', tone: 'teal', count: s.template }, { key: 'other', label: this.$t('Other items'), icon: 'doc', tone: 'sky', count: s.other }, { key: 'inactive', label: this.$t('Not offered'), icon: 'eye-off', tone: 'slate', count: s.inactive }]; },
    tpl() { return this.rows.data.filter(r => r.in_template); },
  },
  methods: {
    err(k) { return (this.$page.props.errors || {})[k]; },
    money(v) { return Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    queue() { clearTimeout(this.timer); this.timer = setTimeout(this.go, 380); },
    go() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); this.$inertia.get(route('weekly-administration.items.index'), q, { preserveState: true, preserveScroll: true, replace: true }); },
    setView(v) { this.f.view = this.f.view === v ? '' : v; this.go(); },
    open(r) { this.form = r ? { id: r.id, name: r.name, default_beneficiary: r.default_beneficiary, default_amount: Number(r.default_amount) ? r.default_amount : '', in_template: r.in_template, is_active: r.is_active } : blank(); this.editing = true; },
    save() {
      this.busy = true; const f = this.form; const body = { name: f.name, default_beneficiary: f.default_beneficiary, default_amount: f.default_amount || 0, in_template: f.in_template ? 1 : 0, is_active: f.is_active ? 1 : 0 };
      const o = { preserveScroll: true, onSuccess: () => { this.editing = false; }, onFinish: () => (this.busy = false) };
      if (f.id) this.$inertia.put(route('weekly-administration.items.update', f.id), body, o); else this.$inertia.post(route('weekly-administration.items.store'), body, o);
    },
    remove() { this.busy = true; this.$inertia.delete(route('weekly-administration.items.destroy', this.del.id), { preserveScroll: true, onSuccess: () => { this.del = null; }, onFinish: () => (this.busy = false) }); },
    isFirst(r) { return this.tpl[0] && this.tpl[0].id === r.id; },
    isLast(r) { return this.tpl.length && this.tpl[this.tpl.length - 1].id === r.id; },
    move(r, d) {
      const ids = this.tpl.map(x => x.id); const i = ids.indexOf(r.id); const j = i + d; if (j < 0 || j >= ids.length) return;
      const x = ids.splice(i, 1)[0]; ids.splice(j, 0, x);
      this.$inertia.post(route('weekly-administration.items.reorder'), { ids }, { preserveScroll: true });
    },
  },
};
</script>

<template>
  <admin-layout :title="$t('Create Multi Transfer')">
    <div class="lg-page mt px-4 md:px-0">
      <page-header icon="collection" :title="$t('Create Multi Transfer')" :subtitle="$t('Pick one destination, then add items and choose which warehouse each one comes from. A transfer is created for every source, and each waits for approval.')">
        <Link :href="route('multi-transfers.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
      </page-header>

      <form @submit.prevent="submit" @keydown.enter="onEnter">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="info" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Details') }}</h2><p class="lg-card-sub">{{ $t('The date and the one destination everything goes to') }}</p></div></header>
          <div class="lg-fields apple-field-grid">
            <div><date-picker v-model="form.date" :error="err('date')" :label="$t('Date')" /></div>
            <div><auto-complete id="mt-to" stock-move :suggestions="warehouses" :label="$t('To Warehouse')" v-model="form.to_warehouse_id" :error="err('to_warehouse_id')" /></div>
            <div class="lg-span-all"><textarea-input v-model="form.details" :label="$t('Details')" :error="err('details')" /></div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="bag" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Items') }}</h2><p class="lg-card-sub">{{ $t('Search an item, then choose the warehouse it comes from. Add the same item again to take the rest from another warehouse.') }}</p></div></header>
          <div class="mt-add">
            <auto-complete json keep-focus reset-search id="mt-add-item" @update:model-value="addItem" :suggestions="route('items.search')" :placeholder="$t('Scan barcode or search items')" :defaultText="$t('Scan barcode or search items for next')" />
          </div>
          <p v-if="err('lines')" class="mt-err">{{ err('lines') }}</p>
          <ul class="mt-form-lines">
            <li v-for="(l, i) in form.lines" :key="l.key" :class="{ 'is-bad': lineError(i) }">
              <div class="mt-form-top">
                <span class="mt-no">{{ i + 1 }}</span>
                <span class="mt-item"><b>{{ l.name }}</b><small>{{ l.code }}</small></span>
                <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Take the rest from another warehouse')" :aria-label="$t('Split')" @click="split(i)"><icons name="duplicate" class="w-4 h-4" /></button>
                <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="form.lines.splice(i, 1)"><icons name="trash" class="w-4 h-4" /></button>
              </div>
              <p v-if="l.blocked" class="mt-err">{{ $t('This item has variants or serial numbers. Use a normal transfer for it.') }}</p>
              <p v-else-if="l.loading" class="mt-hint">{{ $t('Checking stock...') }}</p>
              <p v-else-if="!l.sources.length" class="mt-err">{{ $t('No warehouse you can use has this item in stock.') }}</p>
              <div v-else class="mt-form-grid">
                <div class="mt-f"><auto-complete :id="'mt-from-' + l.key" stock-move :label="$t('From warehouse')" :suggestions="sourceOptions(l)" v-model="l.from_warehouse_id" :placeholder="$t('Choose...')" /></div>
                <div class="mt-f"><label>{{ $t('Quantity') }}</label><input v-model.number="l.quantity" type="number" min="0" step="any" inputmode="decimal" /></div>
                <div class="mt-f"><auto-complete :id="'mt-unit-' + l.key" :label="$t('Unit')" :suggestions="unitOptions(l)" v-model="l.unit_id" /></div>
              </div>
              <p v-if="over(l)" class="mt-err">{{ $t('Only :n are in stock at this warehouse.', { n: stockOf(l) }) }}</p>
              <p v-if="lineError(i)" class="mt-err">{{ lineError(i) }}</p>
            </li>
          </ul>
          <p v-if="!form.lines.length" class="mt-empty">{{ $t('No items yet.') }}</p>
        </section>

        <section v-if="plan.length" class="lg-card mt-plan">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="truck" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('What will be created') }}</h2><p class="lg-card-sub">{{ plan.length }} {{ $t('transfers, each waiting for approval. No stock moves until they are approved.') }}</p></div></header>
          <ul class="mt-plan-list"><li v-for="p in plan" :key="p.id"><b>{{ p.name }}</b><span>{{ p.count }} {{ $t('item line(s)') }}</span></li></ul>
        </section>

        <div class="mt-submit">
          <button type="submit" class="lg-btn lg-btn-primary" :disabled="!canSave || form.processing"><icons name="tick" class="w-4 h-4" />{{ form.processing ? $t('Saving...') : $t('Save Multi Transfer') }}</button>
          <Link :href="route('multi-transfers.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
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
import AutoComplete from '@/Shared/AutoComplete.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';

let seq = 0;

export default {
  components: { AdminLayout, PageHeader, Link, DatePicker, AutoComplete, TextareaInput },
  props: { warehouses: Array },
  data() {
    return { form: this.$inertia.form({ date: new Date().toISOString().slice(0, 10), to_warehouse_id: null, details: '', lines: [] }) };
  },
  computed: {
    canSave() { return !!(this.form.to_warehouse_id && this.form.lines.length && this.form.lines.every(l => !l.blocked && !l.loading && l.from_warehouse_id && Number(l.quantity) > 0 && !this.over(l))); },
    // the transfers that will be created: one per source warehouse
    plan() {
      const by = {};
      this.form.lines.forEach(l => { if (l.from_warehouse_id) { const s = l.sources.find(x => x.id == l.from_warehouse_id); (by[l.from_warehouse_id] = by[l.from_warehouse_id] || { id: l.from_warehouse_id, name: s ? s.name : '', count: 0 }).count++; } });
      return Object.values(by);
    },
  },
  methods: {
    err(k) { return (this.$page.props.errors || {})[k]; },
    lineError(i) { const e = this.$page.props.errors || {}; return e['lines.' + i + '.quantity'] || e['lines.' + i + '.item_id'] || e['lines.' + i + '.from_warehouse_id'] || ''; },
    onEnter(e) { if (e.target.tagName !== 'TEXTAREA' && e.target.type !== 'submit') e.preventDefault(); },
    // searchable lists for a line: the warehouses that have the item (never the destination) and its units
    sourceOptions(l) {
      const unit = l.unit ? ' ' + l.unit.name : '';
      return l.sources.filter(x => x.id != this.form.to_warehouse_id).map(x => ({ label: x.name + ' (' + x.quantity + unit + ' ' + this.$t('in stock') + ')', value: x.id }));
    },
    unitOptions(l) {
      if (!l.unit) return [{ label: '-', value: '' }];
      return [{ label: l.unit.name, value: l.unit.id }, ...((l.unit.subunits || []).map(u => ({ label: u.name, value: u.id })))];
    },
    stockOf(l) { const s = l.sources.find(x => x.id == l.from_warehouse_id); return s ? s.quantity : 0; },
    // the same item taken twice from the same warehouse counts together
    over(l) {
      if (!l.from_warehouse_id) return false;
      const total = this.form.lines.filter(x => x.item_id === l.item_id && x.from_warehouse_id == l.from_warehouse_id).reduce((a, x) => a + Number(x.quantity || 0), 0);
      return !l.unit_id || (l.unit && l.unit_id == l.unit.id) ? total > this.stockOf(l) : false;
    },
    async addItem(v) {
      if (!v || !v.id) return;
      const line = { key: ++seq, item_id: v.id, name: v.name, code: v.code, unit: null, unit_id: null, quantity: 1, sources: [], from_warehouse_id: null, loading: true, blocked: false };
      this.form.lines.push(line);
      const l = this.form.lines[this.form.lines.length - 1];
      try {
        const { data } = await axios.get(route('multi-transfers.availability'), { params: { item_id: v.id } });
        l.unit = data.item.unit; l.unit_id = data.item.unit ? data.item.unit.id : null; l.sources = data.sources;
        l.blocked = !!(data.item.has_variants || data.item.has_serials);
        if (data.sources.length === 1) l.from_warehouse_id = data.sources[0].id;
      } catch (e) { l.sources = []; }
      l.loading = false;
    },
    split(i) {
      const l = this.form.lines[i];
      const used = this.form.lines.filter(x => x.item_id === l.item_id).map(x => x.from_warehouse_id);
      const next = l.sources.find(s => !used.some(u => u == s.id));
      this.form.lines.splice(i + 1, 0, { ...l, key: ++seq, quantity: 1, from_warehouse_id: next ? next.id : null });
    },
    submit() {
      if (!this.canSave) return;
      this.form.transform(d => ({ ...d, lines: d.lines.map(l => ({ item_id: l.item_id, unit_id: l.unit_id, quantity: l.quantity, from_warehouse_id: l.from_warehouse_id })) })).post(route('multi-transfers.store'), { preserveScroll: true });
    },
  },
};
</script>

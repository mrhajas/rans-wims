<template>
  <admin-layout :title="pageTitle">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="truck" :title="pageTitle" :subtitle="subtitle">
        <Link :href="m ? route('external-stock.show', m.id) : route('external-stock.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
      </page-header>

      <form @submit.prevent="save">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico" :class="{ withdrawal: 'lg-ico-rose', deposit: 'lg-ico-emerald', opening: 'lg-ico-violet' }[form.kind]"><icons :name="form.kind === 'withdrawal' ? 'out' : form.kind === 'deposit' ? 'in' : 'plus'" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Movement Details') }}</h2><p class="lg-card-sub">{{ $t('Who, where and when') }}</p></div></header>
          <sc-tabs :tabs="kindTabs" :model-value="form.kind" @update:model-value="k => (form.kind = k)" />
          <div class="lg-fields ex-top">
            <div class="ac-f"><label>{{ $t('External person') }} <b class="wims-req">*</b></label><acct-select v-model="form.contact_id" :options="peopleAll" :placeholder="$t('Choose...')" clearable /><span class="ac-err">{{ err('contact_id') }}</span><div class="ex-addwrap"><small class="lg-subtext">{{ $t('Not in the list? Add them here: they are saved in Contacts as External Personnel.') }}</small><button type="button" class="lg-btn lg-btn-soft ex-addp" @click="personOpen = true"><icons name="plus" class="w-4 h-4" />{{ $t('Add External Personnel') }}</button></div></div>
            <div class="ac-f"><label>{{ $t('Location') }}</label><input :value="personLocation" readonly tabindex="-1" class="ex-ro" :placeholder="form.contact_id ? $t('No location on this contact') : $t('Choose a person first')" /><small class="lg-subtext">{{ $t('Taken from the contact of the External person.') }}</small></div>
            <div class="ac-f"><label>{{ $t('Date') }} <b class="wims-req">*</b></label><date-picker v-model="form.date" :clearable="false" /><span class="ac-err">{{ err('date') }}</span></div>
            <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Purpose / notes') }}</label><textarea v-model="form.purpose" :placeholder="$t('Why the stock is taken or brought')"></textarea></div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="user" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ out ? $t('Collection Details') : $t('Delivery Details') }}</h2><p class="lg-card-sub">{{ out ? $t('Everything about who takes the stock and how: all of it is required and printed on the Gate Pass.') : $t('Who brings the stock and how (optional).') }}</p></div></header>
          <div class="lg-fields">
            <div class="ac-f"><label>{{ out ? $t('Collected by') : $t('Brought by') }} <b v-if="out">*</b></label><input v-model="form.collector_name" /><span class="ac-err">{{ err('collector_name') }}</span></div>
            <div class="ac-f"><label>{{ $t('Mobile') }} <b v-if="out">*</b></label><input v-model="form.collector_phone" inputmode="tel" /><span class="ac-err">{{ err('collector_phone') }}</span></div>
            <div class="ac-f"><label>{{ $t('ID / licence number') }}</label><input v-model="form.collector_id_no" /></div>
          </div>
          <div class="lg-fields" style="margin-top: 12px">
            <div class="ac-f"><label>{{ $t('Driver') }} <b v-if="out">*</b></label>
              <acct-select v-if="!typeDriver" v-model="form.driver_contact_id" :options="drivers" :placeholder="$t('Choose a driver')" clearable />
              <input v-else v-model="form.driver_name" :placeholder="$t('Driver name')" />
              <input v-if="typeDriver" v-model="form.driver_phone" :placeholder="$t('Driver mobile')" inputmode="tel" style="margin-top: 6px" />
              <label class="ac-check"><input v-model="typeDriver" type="checkbox" @change="form.driver_contact_id = null; form.driver_name = ''; form.driver_phone = ''" />{{ $t('Driver is not in the list, type the details') }}</label><span class="ac-err">{{ err('driver_name') || err('driver_contact_id') }}</span></div>
            <div class="ac-f"><label>{{ $t('Vehicle') }} <b v-if="out">*</b></label>
              <acct-select v-if="!typeVehicle" v-model="form.asset_id" :options="vehicles" :placeholder="$t('Choose a vehicle')" clearable />
              <input v-else v-model="form.vehicle_plate" :placeholder="$t('Registration number')" />
              <input v-if="typeVehicle" v-model="form.vehicle_details" :placeholder="$t('Make / model / colour')" style="margin-top: 6px" />
              <label class="ac-check"><input v-model="typeVehicle" type="checkbox" @change="form.asset_id = null; form.vehicle_plate = ''; form.vehicle_details = ''" />{{ $t('Vehicle is not in the list, type the details') }}</label><span class="ac-err">{{ err('vehicle_plate') || err('asset_id') }}</span></div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="bag" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Items') }}</h2><p class="lg-card-sub">{{ out ? $t('Search an item. The warehouse that holds this person\'s stock of it is chosen for you, and you see how much they can still take.') : $t('Search an item, choose its warehouse and enter the quantity.') }}</p></div></header>
          <div class="flex items-start gap-2" style="margin-bottom: 12px">
            <div class="flex-1 min-w-0"><auto-complete json keep-focus reset-search scan id="ex-add-item" :suggestions="route('items.search')" :placeholder="$t('Scan barcode or search items')" :defaultText="$t('Scan barcode or search items for next')" @update:model-value="addItem" /></div>
            <button type="button" class="lg-btn lg-btn-soft flex-none" :aria-label="$t('Scan barcode with camera')" @click="$refs.scanner.show()"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h3l2-2h6l2 2h3a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2z" /><circle cx="12" cy="13" r="4" /></svg>{{ $t('Scan') }}</button>
          </div>
          <camera-barcode-scanner ref="scanner" @resolved="addItem" />
          <span class="ac-err">{{ err('lines') }}</span>
          <ul class="ex-lines">
            <li v-for="(l, i) in form.lines" :key="l.key" :class="{ 'is-bad': lineBad(l) || lineErr(i) }">
              <span class="ac-rico is-sm"><icons name="bag" class="w-4 h-4" /></span>
              <div class="ex-name"><b>{{ l.name }}</b><small>{{ l.code }}<template v-if="l.unit"> · {{ l.unit }}</template></small>
                <small v-if="out && l.pos" :class="{ 'ac-neg': lineBad(l) }">{{ $t('Held:') }} {{ qty(l.pos.balance) }} · {{ $t('promised:') }} {{ qty(l.pos.reserved) }} · <b>{{ $t('can take:') }} {{ qty(l.pos.available) }}</b></small>
                <small v-if="out && l.holders && l.holders.length > 1" class="lg-subtext">{{ $t('Also held in:') }} {{ l.holders.filter(h => h.warehouse_id !== l.warehouse_id).map(h => h.warehouse + ' (' + qty(h.available) + ')').join(', ') }}</small>
                <small v-if="out && form.contact_id && l.holders && !l.holders.length && l.loaded" class="ac-neg">{{ $t('This person holds none of it in a warehouse you can use.') }}</small>
                <small v-if="lineErr(i)" class="ac-neg">{{ lineErr(i) }}</small></div>
              <div class="ex-wh ac-f"><label class="ex-whlab">{{ $t('Warehouse') }} <b>*</b></label><acct-select :model-value="l.warehouse_id" :options="warehouses" :placeholder="$t('Choose...')" @update:model-value="v => setWarehouse(l, v)" /></div>
              <div class="ex-qtywrap ac-f"><label class="ex-whlab">{{ $t('Quantity') }} <b>*</b></label><input v-model="l.quantity" class="ex-qty" inputmode="decimal" :aria-label="$t('Quantity')" /></div>
              <button type="button" class="lg-icon-btn is-red" :aria-label="$t('Remove')" @click="form.lines.splice(i, 1)"><icons name="trash" class="w-4 h-4" /></button>
            </li>
          </ul>
          <p v-if="!form.lines.length" class="lg-sub">{{ $t('No items yet.') }}</p>
        </section>

        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Attachments') }}</h2><p class="lg-card-sub">{{ $t('Delivery notes, photos, signed papers or any supporting document (optional).') }}</p></div></header>
          <acct-file-list v-if="m && attachments && attachments.length" :files="attachments" :download-url="f => route('external-stock.download', [m.id, f.id])" removable style="margin-bottom: 10px" @remove="removeFile" />
          <acct-dropzone v-model="form.attachments" :hint="$t('PDF, images and office files up to 8 MB each.')" />
          <span class="ac-err">{{ err('attachments') || err('attachments.0') }}</span>
        </section>

        <div class="lg-actionbar"><span class="lg-actionbar-note">{{ $t('Saved as waiting for approval: the balance changes only when it is approved.') }}</span>
          <div class="lg-actionbar-btns"><Link :href="m ? route('external-stock.show', m.id) : route('external-stock.index')" class="lg-btn t-slate"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</Link><button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing || !canSave"><icons name="send" class="w-4 h-4" />{{ form.processing ? $t('Saving...') : $t('Save for approval') }}</button></div></div>
      </form>
    </div>
    <person-modal :show="personOpen" @close="personOpen = false" @saved="personSaved" />
  </admin-layout>
</template>

<script>
import PersonModal from './PersonModal.vue';
import ScTabs from '../Subcontracts/ScTabs.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import CameraBarcodeScanner from '@/Shared/CameraBarcodeScanner.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import AcctDropzone from '@/Pages/Accounts/AcctDropzone.vue';
import AcctFileList from '@/Pages/Accounts/AcctFileList.vue';
import { router } from '@inertiajs/vue3';

let seq = 0;

export default {
  components: { CameraBarcodeScanner, ScTabs, AdminLayout, PageHeader, Link, DatePicker, AutoComplete, AcctSelect, AcctDropzone, AcctFileList, PersonModal },
  props: { m: Object, kind: String, kinds: Array, people: Array, warehouses: Array, drivers: Array, vehicles: Array, today: String, newPersonUrl: String, attachments: Array },
  data() {
    // (personOpen and addedPeople: the person added in the window joins the list at once)
    const m = this.m || {};
    return {
      personOpen: false, addedPeople: [],
      typeDriver: !!(m.driver_name && !m.driver_contact_id), typeVehicle: !!(m.vehicle_plate && !m.asset_id),
      form: this.$inertia.form({
        kind: m.kind || this.kind, date: m.date || this.today, contact_id: m.contact_id || null, purpose: m.purpose || '',
        collector_name: m.collector_name || '', collector_phone: m.collector_phone || '', collector_id_no: m.collector_id_no || '', driver_contact_id: m.driver_contact_id || null, driver_name: m.driver_name || '', driver_phone: m.driver_phone || '',
        asset_id: m.asset_id || null, vehicle_plate: m.vehicle_plate || '', vehicle_details: m.vehicle_details || '', attachments: [],
        lines: (m.lines || []).map(l => ({ key: ++seq, item_id: l.item_id, warehouse_id: l.warehouse_id || null, touched: true, auto: false, loaded: false, holders: [], name: l.name, code: l.code, unit: l.unit, quantity: String(l.quantity), pos: null })),
      }),
    };
  },
  computed: {
    out() { return this.form.kind === 'withdrawal'; },
    pageTitle() { return this.m ? this.$t('Edit :r', { r: this.m.reference }) : { withdrawal: this.$t('New Stock Out'), deposit: this.$t('New Stock In'), opening: this.$t('New Opening Stock') }[this.form.kind]; },
    subtitle() { return { withdrawal: this.$t('External Personnel take some of their stock out of a warehouse.'), deposit: this.$t('External Personnel bring more stock into a warehouse.'), opening: this.$t('Stock that External Personnel already have in a warehouse when the module starts.') }[this.form.kind]; },
    kindTabs() { return [{ key: 'withdrawal', label: this.$t('Stock Out'), icon: 'out', tone: 'rose' }, { key: 'deposit', label: this.$t('Stock In'), icon: 'in', tone: 'emerald' }, { key: 'opening', label: this.$t('Opening Stock'), icon: 'plus', tone: 'violet' }]; },
    peopleAll() { return [...this.people, ...this.addedPeople.filter(a => !this.people.some(p => p.value === a.value))]; },
    personLocation() { const p = this.peopleAll.find(x => x.value === this.form.contact_id); return p && p.location ? p.location : ''; },
    canSave() { return this.form.lines.length > 0 && this.form.lines.every(l => Number(l.quantity) > 0 && !!l.warehouse_id && !this.lineBad(l)); },
  },
  watch: {
    'form.contact_id'() { this.refreshAll(); },
    'form.kind'() { this.refreshAll(); },
  },
  mounted() { this.refreshAll(); },
  methods: {
    personSaved(p) { if (!this.addedPeople.some(a => a.value === p.value)) this.addedPeople.push(p); this.form.contact_id = p.value; this.personOpen = false; },
    err(k) { return (this.$page.props.errors || {})[k]; },
    lineErr(i) { return (this.$page.props.errors || {})['lines.' + i + '.quantity'] || (this.$page.props.errors || {})['lines.' + i + '.warehouse_id'] || (this.$page.props.errors || {})['lines.' + i + '.item_id'] || ''; },
    qty(v) { return Number(v).toLocaleString(undefined, { maximumFractionDigits: 4 }); },
    lineBad(l) { return this.out && l.pos && Number(l.quantity) > Number(l.pos.available) + 0.00005; },
    setWarehouse(l, v) { l.warehouse_id = v || null; l.touched = true; l.auto = false; this.position(l); },
    // what this person holds of the item: in the chosen warehouse, or (a Stock Out with none chosen yet) the warehouse that holds it, picked for them
    async position(l) {
      l.pos = null;
      if (!this.form.contact_id) { l.holders = []; l.loaded = false; return; }
      if (l.auto && !l.touched) l.warehouse_id = null; // the person changed: pick again
      try {
        const { data } = await window.axios.get(route('external-stock.availability'), { params: { contact_id: this.form.contact_id, warehouse_id: l.warehouse_id || undefined, item_id: l.item_id, except: this.m ? this.m.id : undefined } });
        l.holders = data.holders || []; l.loaded = true;
        if (data.item && data.item.blocked) l.blocked = true;
        if (this.out && !l.warehouse_id && l.holders.length) { const h = l.holders[0]; l.warehouse_id = h.warehouse_id; l.auto = true; l.pos = h; } else l.pos = data;
      } catch (e) { l.pos = null; }
    },
    removeFile(f) { router.delete(route('external-stock.remove-file', [this.m.id, f.id]), { preserveScroll: true }); },
    refreshAll() { this.form.lines.forEach(l => this.position(l)); },
    addItem(v) {
      if (!v || !v.id) return;
      // the same item again adds one to its line; for Stock In / Opening Stock a line that already has its warehouse stays, and the item gets a new line (another warehouse)
      const ex = this.form.lines.find(l => l.item_id === v.id && (this.out || !l.warehouse_id));
      if (ex) { ex.quantity = String(Number(ex.quantity || 0) + 1); return; }
      const l = { key: ++seq, item_id: v.id, warehouse_id: null, touched: false, auto: false, loaded: false, holders: [], name: v.name, code: v.code, unit: v.unit ? v.unit.name || v.unit : '', quantity: '1', pos: null };
      this.form.lines.push(l);
      this.position(this.form.lines[this.form.lines.length - 1]);
    },
    save() {
      const t = d => ({ ...d, lines: d.lines.map(l => ({ item_id: l.item_id, warehouse_id: l.warehouse_id, quantity: l.quantity })) });
      // files travel as a multipart form, so a change is sent as a POST that the server reads as a PUT
      if (this.m) this.form.transform(d => ({ ...t(d), _method: 'put' })).post(route('external-stock.update', this.m.id), { preserveScroll: true, forceFormData: true });
      else this.form.transform(t).post(route('external-stock.store'), { preserveScroll: true, forceFormData: true });
    },
  },
};
</script>

<style scoped>
.ex-addwrap { display: grid; gap: 8px; justify-items: start; margin-top: 4px; }
.ex-addp { min-height: 38px; }
.ex-ro { background: var(--lg-soft, #f1f5f9); color: var(--lg-mute); cursor: default; }
.ex-top { align-items: start; }
.ex-top .ac-f > label { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ex-opt { margin-left: 6px; font-size: 0.7rem; font-weight: 600; color: var(--lg-mute); text-transform: uppercase; letter-spacing: 0.04em; }
.ex-lines { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.ex-lines li { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 16px; background: var(--lg-card); box-shadow: 0 10px 18px -14px rgba(15, 37, 64, 0.6), 0 0 0 1px var(--lg-line); }
.ex-lines li.is-bad { box-shadow: 0 10px 18px -14px rgba(190, 18, 60, 0.5), 0 0 0 1.5px #e11d48; }
.ex-name { flex: 1; min-width: 0; display: grid; gap: 1px; } .ex-name b { overflow-wrap: anywhere; } .ex-name small { font-size: 0.74rem; color: var(--lg-mute); }
.ex-wh { width: 240px; flex: none; margin: 0; } .ex-qtywrap { margin: 0; flex: none; } .ex-whlab { display: block; font-size: 0.7rem; margin-bottom: 2px; color: var(--lg-mute); }
@media (max-width: 767.98px) { .ex-lines li { flex-wrap: wrap; } .ex-wh { width: 100%; order: 3; } .ex-qtywrap { order: 4; } }
.ex-qty { width: 120px; min-height: 42px; padding: 0 12px; text-align: right; border-radius: 12px; border: 1px solid var(--lg-line); background: var(--lg-card); font-variant-numeric: tabular-nums; box-shadow: 0 8px 16px -10px rgba(15, 37, 64, 0.5); }
</style>

<template>
  <admin-layout :title="$t('Charity')">
    <div class="lg-page ac sc-ui tr-ui wa-ui px-4 md:px-0">
      <page-header icon="heart" :title="$t('Charity')" :subtitle="$t('The people and places that receive a monthly charity. When the Charity section is added to a weekly document, its lines are pulled from this list.')">
        <Link :href="route('weekly-administration.index')" class="lg-btn lg-btn-soft"><icons name="doc-text" class="w-4 h-4" /><span>{{ $t('Documents') }}</span></Link>
        <button v-if="can.manage" type="button" class="lg-btn lg-btn-primary" @click="open()"><icons name="plus" class="w-4 h-4" /><span>{{ $t('Add a Person or Place') }}</span></button>
      </page-header>

      <div class="wa-totals">
        <div class="wa-tot"><small>{{ $t('Every month, active people') }}</small><b class="tr-tm">{{ currency }} {{ money(monthly_total) }}</b></div>
        <div class="wa-tot"><small>{{ $t('Active') }}</small><b>{{ stats.active }}</b></div>
        <div class="wa-tot"><small>{{ $t('Not active') }}</small><b>{{ stats.inactive }}</b></div>
      </div>
      <sc-tabs :tabs="tabs" :model-value="f.view || ''" @update:model-value="setView" />
      <section class="lg-card"><div class="tr-fbar"><div class="ac-f tr-wide"><label>{{ $t('Search') }}</label><input v-model="f.search" type="search" :placeholder="$t('Name, phone or notes')" autocomplete="off" /></div></div></section>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive tr-table tr-wide">
          <thead><tr><th class="tr-no">{{ $t('No') }}</th><th>{{ $t('Person / place') }}</th><th>{{ $t('Phone') }}</th><th class="tr-num">{{ $t('Monthly amount') }}</th><th>{{ $t('Status') }}</th><th class="tr-num">{{ $t('Paid so far') }}</th><th class="lg-sticky-end"></th></tr></thead>
          <tbody>
            <tr v-for="(r, n) in rows.data" :key="r.id">
              <td :data-label="$t('No')" class="tr-no">{{ (rows.from || 1) + n }}</td>
              <td :data-label="$t('Person / place')" class="tr-wrap"><div class="tr-cell"><b>{{ r.name }}</b><small v-if="r.notes">{{ r.notes }}</small></div></td>
              <td :data-label="$t('Phone')">{{ r.phone || '-' }}</td>
              <td :data-label="$t('Monthly amount')" class="tr-num"><span class="tr-m">{{ money(r.monthly_amount) }}</span></td>
              <td :data-label="$t('Status')"><span class="wa-sw" :class="r.is_active ? 'is-on' : 'is-off'">{{ r.is_active ? $t('Active') : $t('Not active') }}</span></td>
              <td :data-label="$t('Paid so far')" class="tr-num"><div class="tr-money"><b class="tr-g">{{ money(r.paid) }}</b><small v-if="r.times">{{ r.times }} {{ $t('time(s)') }}<template v-if="r.last_on"> · {{ $date(r.last_on) }}</template></small></div></td>
              <td class="lg-sticky-end tr-acts-cell"><span v-if="can.manage" class="tr-acts">
                <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Edit')" :aria-label="$t('Edit')" @click="open(r)"><icons name="edit" class="w-4 h-4" /></button>
                <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="del = r"><icons name="trash" class="w-4 h-4" /></button>
              </span></td>
            </tr>
            <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="7">{{ $t('There is no data to display.') }}</td></tr>
          </tbody>
        </table>
      </div>
      <pager :rows="rows" />

      <travel-modal :show="editing" :title="form.id ? $t('Edit') : $t('Add a Person or Place')" icon="heart" tone="amber" :busy="busy" @close="editing = false">
        <form id="wa-ch" @submit.prevent="save">
          <div class="ac-f"><label>{{ $t('Person / place') }} *</label><input v-model="form.name" maxlength="190" required :placeholder="$t('For example SHEIKH ALI ISSA or MASJID KOMBENI')" /><span class="ac-err">{{ err('name') }}</span></div>
          <div class="tr-fbar" style="margin-top: 10px">
            <div class="ac-f"><label>{{ $t('Monthly amount') }} ({{ currency }}) *</label><input v-model="form.monthly_amount" type="number" min="0" step="0.01" inputmode="decimal" required /><span class="ac-err">{{ err('monthly_amount') }}</span></div>
            <div class="ac-f"><label>{{ $t('Phone') }}</label><input v-model="form.phone" maxlength="60" /></div>
          </div>
          <div class="ac-f" style="margin-top: 10px"><label>{{ $t('Notes') }}</label><input v-model="form.notes" maxlength="1000" /></div>
          <label class="wa-toggle" style="margin-top: 12px"><input v-model="form.is_active" type="checkbox" /><span><b>{{ $t('Active') }}</b><small>{{ $t('Only active people are pulled into a document.') }}</small></span></label>
        </form>
        <template #footer>
          <button type="button" class="lg-btn t-slate" :disabled="busy" @click="editing = false">{{ $t('Cancel') }}</button>
          <button type="submit" form="wa-ch" class="lg-btn lg-btn-primary" :disabled="busy"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button>
        </template>
      </travel-modal>
      <travel-modal :show="!!del" :title="$t('Remove from the Charity list?')" :subtitle="del ? del.name : ''" icon="trash" tone="amber" :busy="busy" @close="del = null">
        <p class="lg-sub" style="margin: 0">{{ $t('They will no longer be pulled into new documents. Documents already made keep their lines.') }}</p>
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

const blank = () => ({ id: null, name: '', phone: '', notes: '', monthly_amount: '', is_active: true });

export default {
  components: { TravelModal, ScTabs, AdminLayout, PageHeader, Link, Pager },
  props: { rows: Object, filters: Object, stats: Object, monthly_total: String, currency: String, can: Object },
  data() { return { f: { ...this.filters }, timer: null, editing: false, del: null, busy: false, form: blank() }; },
  watch: { 'f.search'() { this.queue(); } },
  computed: { tabs() { const s = this.stats; return [{ key: '', label: this.$t('All'), icon: 'collection', tone: 'navy', count: s.all }, { key: 'active', label: this.$t('Active'), icon: 'tick', tone: 'emerald', count: s.active }, { key: 'inactive', label: this.$t('Not active'), icon: 'eye-off', tone: 'slate', count: s.inactive }]; } },
  methods: {
    err(k) { return (this.$page.props.errors || {})[k]; },
    money(v) { return Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    queue() { clearTimeout(this.timer); this.timer = setTimeout(this.go, 380); },
    go() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); this.$inertia.get(route('weekly-administration.charity.index'), q, { preserveState: true, preserveScroll: true, replace: true }); },
    setView(v) { this.f.view = this.f.view === v ? '' : v; this.go(); },
    open(r) { this.form = r ? { id: r.id, name: r.name, phone: r.phone, notes: r.notes, monthly_amount: r.monthly_amount, is_active: r.is_active } : blank(); this.editing = true; },
    save() {
      this.busy = true; const f = this.form; const body = { name: f.name, phone: f.phone, notes: f.notes, monthly_amount: f.monthly_amount || 0, is_active: f.is_active ? 1 : 0 };
      const o = { preserveScroll: true, onSuccess: () => { this.editing = false; }, onFinish: () => (this.busy = false) };
      if (f.id) this.$inertia.put(route('weekly-administration.charity.update', f.id), body, o); else this.$inertia.post(route('weekly-administration.charity.store'), body, o);
    },
    remove() { this.busy = true; this.$inertia.delete(route('weekly-administration.charity.destroy', this.del.id), { preserveScroll: true, onSuccess: () => { this.del = null; }, onFinish: () => (this.busy = false) }); },
  },
};
</script>

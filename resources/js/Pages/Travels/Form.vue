<template>
  <admin-layout :title="pageTitle">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="plane" :title="pageTitle" :subtitle="subtitle">
        <Link :href="t ? route('travels.show', t.id) : route('travels.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
      </page-header>

      <div v-if="limited" class="lg-note lg-note-blue" style="margin-bottom: 12px"><icons name="info" class="w-4 h-4" /><span>{{ $t('This travel is approved. You can correct the trip and the flight details; the people and the amounts can no longer be changed (cancel it and record a new one for that).') }}</span></div>

      <form @submit.prevent="save">
        <!-- the trip -->
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="plane" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('The Trip') }}</h2><p class="lg-card-sub">{{ $t('Where they are going and when. The date can be in the future.') }}</p></div></header>
          <sc-tabs :tabs="tripTabs" :model-value="form.trip_type" @update:model-value="k => (form.trip_type = k)" />
          <div class="lg-fields tr-top">
            <div class="ac-f"><label>{{ $t('Kind of travel') }}</label><acct-select v-model="form.travel_type" :options="types.map(x => ({ value: x, label: x }))" :placeholder="$t('Choose...')" clearable /></div>
            <div class="ac-f" style="grid-column: span 2"><label>{{ $t('Purpose') }}</label><input v-model="form.purpose" :placeholder="$t('Why they are travelling')" /></div>
            <div class="ac-f"><label>{{ $t('From (city / place)') }}</label><input v-model="form.from_place" :placeholder="$t('e.g. Zanzibar')" /></div>
            <div class="ac-f"><label>{{ $t('From country') }}</label><input v-model="form.from_country" list="tr-countries" :placeholder="homeCountry" /></div>
            <div class="ac-f"><label>{{ $t('Going to (city / place)') }} *</label><input v-model="form.to_place" :placeholder="$t('e.g. Dubai')" /><span class="ac-err">{{ err('to_place') }}</span></div>
            <div class="ac-f"><label>{{ $t('Going to country') }}</label><input v-model="form.to_country" list="tr-countries" :placeholder="homeCountry" /><span class="tr-scope" :class="international ? 'is-intl' : 'is-local'"><icons :name="international ? 'plane' : 'building'" class="w-3 h-3" />{{ international ? $t('International travel') : $t('Local travel in :c', { c: homeCountry }) }}</span></div>
            <div class="ac-f"><label>{{ $t('Departs') }} *</label><date-picker v-model="form.depart_date" :clearable="false" /><span class="ac-err">{{ err('depart_date') }}</span></div>
            <div v-if="form.trip_type === 'return'" class="ac-f"><label>{{ $t('Returns') }}</label><date-picker v-model="form.return_date" /><span class="ac-err">{{ err('return_date') }}</span></div>
            <div class="ac-f"><label>{{ $t('Ticket booked on') }} *</label><date-picker v-model="form.booking_date" :disabled="limited" :clearable="false" /><span class="ac-err">{{ err('booking_date') }}</span><small class="lg-subtext">{{ $t('The agent is owed from this day.') }}</small></div>
          </div>
          <datalist id="tr-countries"><option v-for="c in allCountries" :key="c" :value="c" /></datalist>
        </section>

        <!-- the flight -->
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="send" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('The Flight') }}</h2><p class="lg-card-sub">{{ $t('Which flight they use.') }}</p></div></header>
          <div class="lg-fields">
            <div class="ac-f"><label>{{ $t('Airline') }}</label><input v-model="form.airline" :placeholder="$t('e.g. Emirates')" /></div>
            <div class="ac-f"><label>{{ $t('Flight number') }}</label><input v-model="form.flight_number" :placeholder="$t('e.g. EK 726')" /></div>
            <template v-if="form.trip_type === 'return'">
              <div class="ac-f"><label>{{ $t('Return airline') }}</label><input v-model="form.return_airline" :placeholder="form.airline || $t('Same airline')" /></div>
              <div class="ac-f"><label>{{ $t('Return flight number') }}</label><input v-model="form.return_flight_number" /></div>
            </template>
            <div class="ac-f"><label>{{ $t('Booking reference (PNR)') }}</label><input v-model="form.booking_reference" /></div>
          </div>
        </section>

        <!-- the people -->
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="users" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Travellers') }}</h2><p class="lg-card-sub">{{ $t('One or more people. Each has their own ticket number and price.') }}</p></div></header>
          <div v-if="!limited" class="tr-add">
            <div class="ac-f"><label>{{ $t('Add a staff member') }}</label><acct-select :key="'s' + pickKey" v-model="pickStaff" :options="staffLeft" :placeholder="$t('Search staff...')" clearable /></div>
            <div class="ac-f"><label>{{ $t('Add a user') }}</label><acct-select :key="'u' + pickKey" v-model="pickUser" :options="usersLeft" :placeholder="$t('Search users...')" clearable /></div>
            <div class="ac-f"><label aria-hidden="true">&nbsp;</label><button type="button" class="lg-btn lg-btn-soft" @click="addOther"><icons name="plus" class="w-4 h-4" />{{ $t('Someone else') }}</button></div>
          </div>
          <span class="ac-err">{{ err('travellers') }}</span>
          <ul class="tr-people">
            <li v-for="(p, i) in form.travellers" :key="p.key">
              <div class="tr-p-head">
                <span class="tr-p-ico"><icons :name="p.traveller_type === 'other' ? 'user' : 'users'" class="w-4 h-4" /></span>
                <div class="tr-p-name">
                  <input v-if="p.traveller_type === 'other' && !limited" v-model="p.name" :placeholder="$t('Name of the traveller')" />
                  <b v-else>{{ p.name }}</b>
                  <small>{{ { staff: $t('Staff'), user: $t('User'), other: $t('Other') }[p.traveller_type] }}</small>
                  <small v-if="err('travellers.' + i + '.name') || err('travellers.' + i + '.staff_id') || err('travellers.' + i + '.user_id')" class="ac-neg">{{ err('travellers.' + i + '.name') || err('travellers.' + i + '.staff_id') || err('travellers.' + i + '.user_id') }}</small>
                </div>
                <button v-if="!limited && form.travellers.length > 1" type="button" class="lg-icon-btn is-red" :aria-label="$t('Remove')" @click="form.travellers.splice(i, 1)"><icons name="trash" class="w-4 h-4" /></button>
              </div>
              <div class="tr-p-fields">
                <div class="ac-f"><label>{{ $t('Mobile') }}</label><input v-model="p.phone" inputmode="tel" /></div>
                <div class="ac-f"><label>{{ $t('Ticket number') }}</label><input v-model="p.ticket_number" /></div>
                <div class="ac-f"><label>{{ $t('Ticket price') }} ({{ form.currency }})</label><input v-model="p.ticket_amount" inputmode="decimal" :disabled="limited" /><span class="ac-err">{{ err('travellers.' + i + '.ticket_amount') }}</span></div>
                <div v-if="form.has_visa" class="ac-f"><label>{{ $t('Visa price') }} ({{ form.currency }})</label><input v-model="p.visa_amount" inputmode="decimal" :disabled="limited" /><span class="ac-err">{{ err('travellers.' + i + '.visa_amount') }}</span></div>
              </div>
            </li>
          </ul>
        </section>

        <!-- ticket and visa -->
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="cash" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Ticket and Visa') }}</h2><p class="lg-card-sub">{{ $t('Who issued them, and what they cost. The ticket agent and the visa issuer are contacts; what they are owed is kept in Travels.') }}</p></div></header>
          <div class="lg-fields">
            <div class="ac-f"><label>{{ $t('Currency') }} *</label><acct-select v-model="form.currency" :options="currencies.map(c => ({ value: c, label: c }))" :disabled="limited" /><span class="ac-err">{{ err('currency') }}</span></div>
            <div class="ac-f" style="grid-column: span 2"><label>{{ $t('Ticket issued by (ticket agent)') }} <b v-if="ticketTotal > 0">*</b></label><acct-select v-model="form.ticket_agent_contact_id" :options="issuers" :placeholder="$t('Choose the ticket agent')" :disabled="limited" clearable /><span class="ac-err">{{ err('ticket_agent_contact_id') }}</span>
              <small class="lg-subtext">{{ $t('Not in the list?') }} <a :href="newAgentUrl" target="_blank" rel="noopener">{{ $t('Add the agent as a contact in the Ticket Agent category') }}</a></small></div>
          </div>
          <label class="ac-check" style="margin-top: 10px"><input v-model="form.has_visa" type="checkbox" :disabled="limited" />{{ $t('A visa is included') }}</label>
          <div v-if="form.has_visa" class="lg-fields" style="margin-top: 8px">
            <div class="ac-f"><label>{{ $t('Visa type') }}</label><input v-model="form.visa_type" :placeholder="$t('e.g. Tourist, Business')" /></div>
            <div class="ac-f"><label>{{ $t('Visa country') }}</label><input v-model="form.visa_country" list="tr-countries" :placeholder="form.to_country" /></div>
            <div class="ac-f"><label>{{ $t('Visa reference') }}</label><input v-model="form.visa_reference" /></div>
            <div class="ac-f" style="grid-column: span 2"><label>{{ $t('Visa issued by') }} <b v-if="visaTotal > 0">*</b></label><acct-select v-model="form.visa_agent_contact_id" :options="issuers" :placeholder="$t('Choose who issued the visa')" :disabled="limited" clearable /><span class="ac-err">{{ err('visa_agent_contact_id') }}</span>
              <small class="lg-subtext">{{ $t('Not in the list?') }} <a :href="newVisaUrl" target="_blank" rel="noopener">{{ $t('Add the issuer as a contact in the Visa Agent category') }}</a></small></div>
          </div>
          <div class="tr-total"><span>{{ $t('Tickets') }} <b>{{ form.currency }} {{ money(ticketTotal) }}</b></span><span v-if="form.has_visa">{{ $t('Visas') }} <b>{{ form.currency }} {{ money(visaTotal) }}</b></span><span class="tr-grand">{{ $t('Total') }} <b>{{ form.currency }} {{ money(ticketTotal + visaTotal) }}</b></span></div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Notes and Attachments') }}</h2><p class="lg-card-sub">{{ $t('The ticket, the visa, the invoice or any supporting document (optional).') }}</p></div></header>
          <div class="ac-f"><label>{{ $t('Notes') }}</label><textarea v-model="form.notes"></textarea></div>
          <acct-file-list v-if="t && attachments && attachments.length" :files="attachments" :download-url="f => route('travels.download', [t.id, f.id])" :removable="!limited" style="margin: 10px 0" @remove="removeFile" />
          <acct-dropzone v-model="form.attachments" :hint="$t('PDF, images and office files up to 8 MB each.')" />
          <span class="ac-err">{{ err('attachments') || err('attachments.0') }}</span>
        </section>

        <div class="lg-actionbar"><span class="lg-actionbar-note">{{ limited ? $t('Only the trip and flight details change. The amounts and the people stay as approved.') : $t('Saved as waiting for approval: the ticket agent is owed for it only when it is approved.') }}</span>
          <div class="lg-actionbar-btns"><Link :href="t ? route('travels.show', t.id) : route('travels.index')" class="lg-btn t-slate"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</Link><button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing || !canSave"><icons name="send" class="w-4 h-4" />{{ form.processing ? $t('Saving...') : (limited ? $t('Save changes') : $t('Save for approval')) }}</button></div></div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import ScTabs from '../Subcontracts/ScTabs.vue';
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import AcctDropzone from '@/Pages/Accounts/AcctDropzone.vue';
import AcctFileList from '@/Pages/Accounts/AcctFileList.vue';

let seq = 0;

export default {
  components: { ScTabs, AdminLayout, PageHeader, Link, DatePicker, AcctSelect, AcctDropzone, AcctFileList },
  props: { t: Object, types: Array, currencies: Array, homeCountry: String, countries: Array, issuers: Array, today: String, attachments: Array, staff: Array, users: Array, newAgentUrl: String, newVisaUrl: String, limited: Boolean },
  data() {
    const t = this.t || {};
    return {
      pickStaff: null, pickUser: null, pickKey: 0,
      form: this.$inertia.form({
        trip_type: t.trip_type || 'return', travel_type: t.travel_type || null, purpose: t.purpose || '', from_place: t.from_place || '', from_country: t.from_country || '', to_place: t.to_place || '', to_country: t.to_country || '',
        depart_date: t.depart_date || this.today, return_date: t.return_date || null, booking_date: t.booking_date || this.today, airline: t.airline || '', flight_number: t.flight_number || '', return_airline: t.return_airline || '', return_flight_number: t.return_flight_number || '',
        booking_reference: t.booking_reference || '', ticket_agent_contact_id: t.ticket_agent_id || null, currency: t.currency || (this.currencies[0] || 'TZS'), has_visa: !!t.has_visa, visa_type: t.visa_type || '', visa_country: t.visa_country || '', visa_reference: t.visa_reference || '',
        visa_agent_contact_id: t.visa_agent_id || null, notes: t.notes || '', attachments: [],
        travellers: (t.travellers || []).map(p => ({ key: ++seq, id: p.id, traveller_type: p.type, staff_id: p.staff_id, user_id: p.type === 'user' ? p.user_id : null, name: p.name, phone: p.phone || '', ticket_number: p.ticket_number || '', ticket_amount: String(p.ticket_amount || 0), visa_amount: String(p.visa_amount || 0) })),
      }),
    };
  },
  computed: {
    pageTitle() { return this.t ? this.$t('Edit :r', { r: this.t.reference }) : this.$t('New Travel'); },
    subtitle() { return this.t ? this.$t('Change the details.') : this.$t('Record a travel. It is held for approval; the ticket agent is owed for it once it is approved.'); },
    tripTabs() { return [{ key: 'return', label: this.$t('Return trip'), icon: 'refresh', tone: 'sky' }, { key: 'one_way', label: this.$t('One way'), icon: 'forward', tone: 'indigo' }]; },
    allCountries() { return [...new Set([this.homeCountry, ...(this.countries || [])].filter(Boolean))]; },
    international() { const c = (this.form.to_country || '').trim().toLowerCase(); return c !== '' && c !== (this.homeCountry || '').toLowerCase(); },
    ticketTotal() { return this.form.travellers.reduce((s, p) => s + (Number(p.ticket_amount) || 0), 0); },
    visaTotal() { return this.form.has_visa ? this.form.travellers.reduce((s, p) => s + (Number(p.visa_amount) || 0), 0) : 0; },
    staffLeft() { const used = new Set(this.form.travellers.filter(p => p.staff_id).map(p => p.staff_id)); return this.staff.filter(s => !used.has(s.value)); },
    usersLeft() { const used = new Set(this.form.travellers.filter(p => p.traveller_type === 'user' && p.user_id).map(p => p.user_id)); return this.users.filter(u => !used.has(u.value)); },
    canSave() { return !!this.form.to_place && this.form.travellers.length > 0 && this.form.travellers.every(p => (p.name || '').trim() !== ''); },
  },
  watch: {
    pickStaff(id) { if (!id) return; const s = this.staff.find(x => x.value === id); if (s) this.form.travellers.push({ key: ++seq, traveller_type: 'staff', staff_id: s.value, user_id: s.user_id || null, name: s.name, phone: s.phone || '', ticket_number: '', ticket_amount: '0', visa_amount: '0' }); this.pickStaff = null; this.pickKey++; },
    pickUser(id) { if (!id) return; const u = this.users.find(x => x.value === id); if (u) this.form.travellers.push({ key: ++seq, traveller_type: 'user', staff_id: null, user_id: u.value, name: u.name, phone: '', ticket_number: '', ticket_amount: '0', visa_amount: '0' }); this.pickUser = null; this.pickKey++; },
  },
  methods: {
    err(k) { return (this.$page.props.errors || {})[k]; },
    money(v) { return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    addOther() { this.form.travellers.push({ key: ++seq, traveller_type: 'other', staff_id: null, user_id: null, name: '', phone: '', ticket_number: '', ticket_amount: '0', visa_amount: '0' }); },
    removeFile(f) { router.delete(route('travels.remove-file', [this.t.id, f.id]), { preserveScroll: true }); },
    save() {
      const t = d => ({ ...d, has_visa: d.has_visa ? 1 : 0, travellers: d.travellers.map(p => ({ id: p.id, traveller_type: p.traveller_type, staff_id: p.staff_id, user_id: p.user_id, name: p.name, phone: p.phone, ticket_number: p.ticket_number, ticket_amount: p.ticket_amount, visa_amount: p.visa_amount })) });
      // files travel as a multipart form, so a change is sent as a POST that the server reads as a PUT
      if (this.t) this.form.transform(d => ({ ...t(d), _method: 'put' })).post(route('travels.update', this.t.id), { preserveScroll: true, forceFormData: true });
      else this.form.transform(t).post(route('travels.store'), { preserveScroll: true, forceFormData: true });
    },
  },
};
</script>

<style scoped>
.tr-top { align-items: start; }
.tr-scope { display: inline-flex; align-items: center; gap: 5px; margin-top: 5px; padding: 2px 9px; border-radius: 999px; font-size: 0.72rem; font-weight: 800; width: fit-content; }
.tr-scope.is-intl { background: rgba(124, 58, 237, 0.12); color: #6d28d9; } .tr-scope.is-local { background: rgba(100, 116, 139, 0.14); color: #475569; }
.tr-add { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; align-items: end; margin-bottom: 8px; }
@media (max-width: 720px) { .tr-add { grid-template-columns: 1fr; } }
.tr-people { list-style: none; margin: 8px 0 0; padding: 0; display: grid; gap: 10px; }
.tr-people > li { padding: 12px; border-radius: 16px; background: var(--lg-card, #fff); box-shadow: 0 10px 18px -14px rgba(15, 37, 64, 0.6), 0 0 0 1px var(--lg-line, #e3e9f2); }
.tr-p-head { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.tr-p-ico { flex: none; display: grid; place-items: center; width: 34px; height: 34px; border-radius: 11px; background: rgba(14, 116, 144, 0.12); color: #0e7490; }
.tr-p-name { flex: 1; min-width: 0; display: grid; gap: 1px; } .tr-p-name b { overflow-wrap: anywhere; } .tr-p-name small { font-size: 0.72rem; color: var(--lg-mute, #64748b); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
.tr-p-fields { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
@media (max-width: 900px) { .tr-p-fields { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 480px) { .tr-p-fields { grid-template-columns: 1fr; } }
.tr-total { display: flex; flex-wrap: wrap; gap: 8px 22px; justify-content: flex-end; margin-top: 14px; padding: 10px 14px; border-radius: 14px; background: var(--lg-soft, #f1f5f9); font-size: 0.85rem; color: var(--lg-mute, #64748b); }
.tr-total b { color: var(--lg-ink, #0f2540); font-variant-numeric: tabular-nums; } .tr-grand b { font-size: 1.1rem; }
</style>

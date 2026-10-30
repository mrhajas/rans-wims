<template>
  <admin-layout :title="pageTitle">
    <div class="lg-page ac sc-ui tr-ui vl-ui px-4 md:px-0">
      <page-header icon="in" :title="pageTitle" :subtitle="visit ? $t('Change the details of the visit. The times cannot be changed here.') : $t('Choose the visitor, the person they came to see and the purpose. The time is taken from the server.')">
        <Link :href="visit ? route('visitor-logs.visits.show', visit.id) : route('visitor-logs.visits.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
      </page-header>

      <form @submit.prevent="save">
        <!-- 1. the visitor -->
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="user" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('1. The Visitor') }}</h2><p class="lg-card-sub">{{ visit ? $t('The visitor of a visit cannot be changed.') : $t('Search the directory. A new visitor can be added here without losing what you have typed.') }}</p></div></header>
          <div v-if="visit" class="vl-fixed"><b>{{ visitor.name }}</b> <span class="lg-chip lg-chip-slate" style="text-transform: none">{{ visitor.reference }}</span> <small class="lg-subtext">{{ [visitor.company, visitor.phone].filter(Boolean).join(' · ') }}</small></div>
          <visitor-picker v-else v-model="form.visitor_id" :initial="visitor" :categories="categories" :fields="fields" :require-phone="requirePhone" :can="pickerCan" :can-add="can.createVisitor" :error="err('visitor_id')" @picked="onPicked" />
        </section>

        <!-- 2 and 3. who and why -->
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="users" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('2. Who They Came to See and Why') }}</h2></div></header>
          <div class="lg-fields">
            <div class="ac-f"><label>{{ $t('Person being visited') }} <b class="vl-req">*</b></label><person-picker v-model="form.host_user_id" :placeholder="$t('Search the person...')" :error="err('host_user_id')" /></div>
            <div v-if="warehouses.length" class="ac-f"><label>{{ $t('Attended from') }} <b class="vl-req">*</b></label><acct-select v-model="form.warehouse_id" :options="warehouses.map(w => ({ value: w.id, label: w.name }))" :placeholder="$t('Choose the project...')" /><span class="ac-err">{{ err('warehouse_id') }}</span></div>
            <div class="ac-f"><label>{{ $t('Department / section') }}</label><list-picker v-model="form.department" :options="departments" :placeholder="$t('Choose or type a new one')" /><span class="ac-err">{{ err('department') }}</span></div>
            <div class="ac-f"><label>{{ $t('Purpose of visit') }} <b class="vl-req">*</b></label><list-picker v-model="form.purpose" :options="purposeOptions.map(o => o.value)" :placeholder="$t('Choose or type a new purpose')" /><span class="ac-err">{{ err('purpose') }}</span></div>
            <div v-if="isOther" class="ac-f"><label>{{ $t('Describe the purpose') }} <b class="vl-req">*</b></label><input v-model="form.purpose_other" maxlength="1000" autocomplete="off" /><span class="ac-err">{{ err('purpose_other') }}</span></div>
            <div class="ac-f"><label>{{ $t('Appointment / reference') }}</label><input v-model="form.appointment_ref" maxlength="80" autocomplete="off" /></div>
            <div class="ac-f"><label>{{ $t('Accompanying visitors') }}</label><input v-model="form.companions" type="number" min="0" max="500" inputmode="numeric" /><span class="ac-err">{{ err('companions') }}</span></div>
            <div v-if="fields.includes('vehicle_registration')" class="ac-f"><label>{{ $t('Vehicle registration') }}</label><input v-model="form.vehicle_registration" maxlength="40" autocomplete="off" /></div>
            <div v-if="idPass && idPass.enabled" class="ac-f"><label>{{ $t('Visitor pass') }}</label><acct-select v-model="form.id_pass_id" :options="idPass.free" :placeholder="idPass.free.length ? $t('No pass') : $t('No free pass')" clearable /><small class="lg-subtext">{{ idPass.free.length ? $t('The lowest free number is suggested. The pass is taken back when the visitor checks out.') : $t('Make more passes in RANS ID & Pass.') }}</small><span class="ac-err">{{ err('id_pass_id') }}</span></div>
          </div>
        </section>

        <!-- 4. the time -->
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('3. Visit Date and Check-in Time') }}</h2><p class="lg-card-sub">{{ $t('Taken from the WIMS server clock (:tz), not from this device.', { tz: timezone }) }}</p></div></header>
          <template v-if="!visit">
            <div class="vl-clock" aria-live="off"><icons name="clock" class="w-5 h-5" /><b>{{ clock }}</b><small>{{ $t('Check-in time') }}</small></div>
            <div v-if="canCorrectTime" class="vl-setting"><label class="vl-check"><input v-model="setTime" type="checkbox" />{{ $t('Set an earlier check-in time (a late entry)') }}</label></div>
            <div v-if="setTime" class="lg-fields" style="margin-top: 10px">
              <div class="ac-f"><label>{{ $t('Date') }}</label><date-picker v-model="form.check_in_date" :clearable="false" /></div>
              <div class="ac-f"><label>{{ $t('Time') }}</label><time-field v-model="form.check_in_time" /></div>
              <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Reason') }} <b class="vl-req">*</b></label><input v-model="form.time_reason" maxlength="1000" :placeholder="$t('Why is the time set by hand? It is recorded.')" /><span class="ac-err">{{ err('check_in_at') || err('time_reason') }}</span></div>
            </div>
          </template>
          <div v-else class="vl-clock is-fixed"><icons name="clock" class="w-5 h-5" /><b>{{ $datetime(visit.check_in_at) }}</b><small>{{ $t('Check-in time (kept)') }}</small></div>
        </section>

        <!-- 5. more -->
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('4. Remarks and Documents') }}</h2><p class="lg-card-sub">{{ $t('Optional.') }}</p></div></header>
          <div class="ac-f"><label>{{ $t('Remarks') }}</label><textarea v-model="form.remarks" rows="2" maxlength="2000"></textarea></div>
          <attach-camera v-if="!visit" v-model="form.attachments" style="margin-top: 10px" :hint="$t('PDF, images and office files up to 8 MB each.')" />
          <span class="ac-err">{{ err('attachments') || err('attachments.0') }}</span>
        </section>

        <div class="vl-actions">
          <Link :href="visit ? route('visitor-logs.visits.show', visit.id) : route('visitor-logs.visits.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
          <button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing || !canSave"><icons :name="form.processing ? 'refresh' : 'in'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />{{ form.processing ? $t('Saving...') : visit ? $t('Save Changes') : $t('Check In Visitor') }}</button>
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
import AttachCamera from '../Parts/AttachCamera.vue';
import ListPicker from '../Parts/ListPicker.vue';
import VisitorPicker from '../Parts/VisitorPicker.vue';
import PersonPicker from '../Parts/PersonPicker.vue';
import TimeField from '../Parts/TimeField.vue';

const pad = n => String(n).padStart(2, '0');

export default {
  components: { Link, AdminLayout, PageHeader, DatePicker, AcctSelect, AttachCamera, ListPicker, VisitorPicker, PersonPicker, TimeField },
  props: { idPass: { type: Object, default: () => ({ enabled: false, free: [] }) }, visit: Object, visitor: Object, warehouses: { type: Array, default: () => [] }, defaultWarehouse: { type: [Number, String], default: null }, purposes: Array, departments: Array, categories: Array, now: String, timezone: String, fields: Array, requirePhone: Boolean, canCorrectTime: Boolean, can: Object },
  data() {
    const v = this.visit || {};
    const t = new Date(this.now);
    return {
      offset: new Date(this.now).getTime() - Date.now(), tick: Date.now(), timer: null, setTime: false,
      form: this.$inertia.form({
        visitor_id: v.visitor_id || (this.visitor ? this.visitor.id : ''), host_user_id: v.host_user_id || '', purpose: v.id ? v.purpose || '' : this.firstOf(this.purposes.map(x => x.name), 'Business meeting'), purpose_other: v.purpose_other || '', department: v.id ? v.department || '' : this.firstOf(this.departments, 'Management'), appointment_ref: v.appointment_ref || '',
        warehouse_id: v.warehouse_id || this.defaultWarehouse || '', companions: v.companions || 0, vehicle_registration: v.vehicle_registration || '', remarks: v.remarks || '', attachments: [], id_pass_id: !this.visit && this.idPass && this.idPass.suggested ? this.idPass.suggested : '',
        check_in_date: t.getFullYear() + '-' + pad(t.getMonth() + 1) + '-' + pad(t.getDate()), check_in_time: pad(t.getHours()) + ':' + pad(t.getMinutes()), time_reason: '',
      }),
    };
  },
  computed: {
    pageTitle() { return this.visit ? this.$t('Edit :r', { r: this.visit.reference }) : this.$t('New Visitor Log'); },
    purposeOptions() { const list = this.purposes.map(p => ({ value: p.name, label: p.name })); if (this.visit && this.visit.purpose && !list.some(o => o.value === this.visit.purpose)) list.push({ value: this.visit.purpose, label: this.visit.purpose }); return list; },
    isOther() { const t = (this.form.purpose || '').trim().toLowerCase(); const p = this.purposes.find(x => x.name.toLowerCase() === t); return !!(p && p.flag === 'other') || (!!this.visit && !!this.visit.purpose_other && t === (this.visit.purpose || '').toLowerCase()); },
    pickerCan() { return { history: this.can.history, visitors: this.can.visitors, sensitive: this.can.sensitive, update: this.can.updateVisitor }; },
    canSave() { return !!this.form.visitor_id && !!this.form.host_user_id && !!(this.form.purpose || '').trim() && (!this.warehouses.length || !!this.form.warehouse_id) && (!this.isOther || (this.form.purpose_other || '').trim() !== ''); },
    clock() {
      try { return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'medium', timeZone: this.timezone, hour12: true }).format(new Date(this.tick + this.offset)); } catch (e) { return new Date(this.tick + this.offset).toLocaleString(); }
    },
  },
  mounted() { if (!this.visit) this.timer = setInterval(() => (this.tick = Date.now()), 1000); },
  beforeUnmount() { clearInterval(this.timer); },
  methods: {
    // a starting value that is still in the list (the list is edited in Settings, so it may be gone)
    firstOf(list, wanted) { const f = (list || []).find(x => x.toLowerCase() === wanted.toLowerCase()); return f || ''; },
    err(k) { return (this.$page.props.errors || {})[k]; },
    onPicked(v) { if (v && v.vehicle && !this.form.vehicle_registration && this.fields.includes('vehicle_registration')) this.form.vehicle_registration = v.vehicle; },
    save() {
      const t = d => {
        const out = { ...d };
        delete out.check_in_date; delete out.check_in_time; delete out.time_reason;
        if (!this.visit && this.setTime) { out.check_in_at = d.check_in_date + ' ' + d.check_in_time; out.time_reason = d.time_reason; }
        if (!this.isOther) out.purpose_other = '';
        return out;
      };
      if (this.visit) this.form.transform(d => ({ ...t(d), _method: 'put' })).post(route('visitor-logs.visits.update', this.visit.id), { preserveScroll: true });
      else this.form.transform(t).post(route('visitor-logs.visits.store'), { preserveScroll: true, forceFormData: true });
    },
  },
};
</script>

<style scoped>
.vl-req { color: #dc2626; }
.vl-fixed { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.vl-clock { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 12px; padding: 12px 16px; border-radius: 16px; background: var(--lg-soft, #f1f5f9); box-shadow: inset 0 0 0 1px var(--lg-line, #e3e9f2); color: #0369a1; }
.vl-clock b { font-size: 1.15rem; color: var(--lg-ink, #0f2540); font-variant-numeric: tabular-nums; }
.vl-clock small { margin-left: auto; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-mute, #64748b); font-size: 0.68rem; }
.vl-setting { margin-top: 10px; }
.vl-check { display: inline-flex; align-items: center; gap: 8px; font-weight: 700; cursor: pointer; }
.vl-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 10px; margin: 4px 0 24px; }
@media (max-width: 560px) { .vl-actions { flex-direction: column-reverse; } .vl-actions .lg-btn { width: 100%; justify-content: center; } .vl-clock small { margin-left: 0; width: 100%; } }
</style>

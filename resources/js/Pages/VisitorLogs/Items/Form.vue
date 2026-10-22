<template>
  <admin-layout :title="pageTitle">
    <div class="lg-page ac sc-ui tr-ui vl-ui px-4 md:px-0">
      <page-header icon="email" :title="pageTitle" :subtitle="item ? $t('Change the details of the item.') : $t('Register a letter, package or document received at reception. The recipient is told.')">
        <Link :href="item ? route('visitor-logs.items.show', item.id) : route('visitor-logs.items.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
      </page-header>

      <form @submit.prevent="save">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="email" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('1. What and For Whom') }}</h2></div></header>
          <div class="lg-fields">
            <div v-if="warehouses.length" class="ac-f"><label>{{ $t('Attended from') }} <b class="vl-req">*</b></label><acct-select v-model="form.warehouse_id" :options="warehouses.map(w => ({ value: w.id, label: w.name }))" :placeholder="$t('Choose the project...')" /><span class="ac-err">{{ err('warehouse_id') }}</span></div>
            <div class="ac-f"><label>{{ $t('Type of item') }} <b class="vl-req">*</b></label><list-picker v-model="form.item_type" :options="typeOptions.map(o => o.value)" :placeholder="$t('Choose or type a new type')" /><span class="ac-err">{{ err('item_type') }}</span></div>
            <div class="ac-f"><label>{{ $t('Intended recipient (select from Users)') }} <b class="vl-req">*</b></label><person-picker v-model="form.recipient_user_id" :placeholder="$t('Search the person...')" :error="err('recipient_user_id')" /></div>
            <div class="ac-f"><label>{{ $t('Recipient department') }}</label><list-picker v-model="form.department" :options="departments" :placeholder="$t('Choose or type a new one')" /></div>
            <div class="ac-f"><label>{{ $t('Number of items') }}</label><input v-model="form.item_count" type="number" min="1" max="999" inputmode="numeric" /></div>
            <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Subject / short description') }}</label><input v-model="form.subject" maxlength="255" autocomplete="off" :placeholder="$t('For example: signed contract, spare parts, invoice')" /></div>
          </div>
          <label v-if="can.confidential" class="vl-check"><input v-model="form.is_confidential" type="checkbox" /><span><b>{{ $t('Confidential / sensitive') }}</b><small>{{ $t('The sender and the subject are hidden from people who may not see confidential items, and are not put in alerts.') }}</small></span></label>
        </section>

        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="user" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('2. Who Sent It') }}</h2></div></header>
          <div class="lg-fields">
            <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Sender (from Contacts)') }}</label><contact-picker v-model="form.sender_contact_id" :initial="senderContact" :error="err('sender_contact_id')" @picked="onSender" /><small class="lg-subtext">{{ $t('Not in Contacts yet? Use Add Contact: it is saved in the category Visitors.') }}</small></div>
            <div class="ac-f"><label>{{ $t('Sender company / organization') }}</label><input v-model="form.sender_company" maxlength="190" autocomplete="off" /></div>
            <div class="ac-f"><label>{{ $t('Courier / delivery company') }}</label><input v-model="form.courier" maxlength="120" autocomplete="off" /></div>
            <div class="ac-f"><label>{{ $t('Tracking / reference number') }}</label><input v-model="form.tracking_number" maxlength="120" autocomplete="off" /></div>
            <div class="ac-f"><label>{{ $t('Condition on receipt') }}</label><input v-model="form.item_condition" maxlength="160" autocomplete="off" :placeholder="$t('Sealed, damaged corner...')" /></div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('3. When and Who Brought It') }}</h2><p class="lg-card-sub">{{ $t('The time received is taken from the WIMS server clock (:tz).', { tz: timezone }) }}</p></div></header>
          <div v-if="!item" class="vl-clock"><icons name="clock" class="w-5 h-5" /><b>{{ clock }}</b><small>{{ $t('Time received') }}</small></div>
          <div v-else class="vl-clock is-fixed"><icons name="clock" class="w-5 h-5" /><b>{{ $datetime(item.received_at) }}</b><small>{{ $t('Time received (kept)') }}</small></div>
          <div v-if="!item && canCorrectTime" class="vl-setting"><label class="vl-check is-plain"><input v-model="setTime" type="checkbox" /><span><b>{{ $t('Set an earlier time received') }}</b></span></label></div>
          <div v-if="setTime" class="lg-fields" style="margin-top: 10px">
            <div class="ac-f"><label>{{ $t('Date') }}</label><date-picker v-model="form.received_date" :clearable="false" /></div>
            <div class="ac-f"><label>{{ $t('Time') }}</label><time-field v-model="form.received_time" /></div>
            <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Reason') }} <b class="vl-req">*</b></label><input v-model="form.time_reason" maxlength="1000" :placeholder="$t('Why is the time set by hand? It is recorded.')" /><span class="ac-err">{{ err('received_at') || err('time_reason') }}</span></div>
          </div>
          <div v-if="can.visitors" class="ac-f" style="margin-top: 14px"><label>{{ $t('Brought by a visitor (optional)') }}</label>
            <div v-if="fromVisit" class="vl-fixed"><b>{{ fromVisit.visitor ? fromVisit.visitor.name : '' }}</b> <span class="lg-chip lg-chip-slate" style="text-transform: none">{{ fromVisit.reference }}</span> <button type="button" class="lg-btn t-slate" @click="unlinkVisit">{{ $t('Not linked to this visit') }}</button></div>
            <visitor-picker v-else v-model="form.visitor_id" :initial="visitor" :can="{ history: false, visitors: false, sensitive: false, update: false }" :can-add="false" :error="err('visitor_id')" />
          </div>
          <div class="ac-f" style="margin-top: 14px"><label>{{ $t('Remarks') }}</label><textarea v-model="form.remarks" rows="2" maxlength="2000"></textarea></div>
          <attach-camera v-if="!item && can.files" v-model="form.attachments" style="margin-top: 10px" :hint="$t('A scan or a photo. PDF, images and office files up to 8 MB each.')" />
          <span class="ac-err">{{ err('attachments') || err('attachments.0') }}</span>
        </section>

        <div class="vl-actions">
          <Link :href="item ? route('visitor-logs.items.show', item.id) : route('visitor-logs.items.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
          <button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing || !canSave"><icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />{{ form.processing ? $t('Saving...') : item ? $t('Save Changes') : $t('Register Item') }}</button>
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
import ContactPicker from '../Parts/ContactPicker.vue';
import VisitorPicker from '../Parts/VisitorPicker.vue';
import PersonPicker from '../Parts/PersonPicker.vue';
import TimeField from '../Parts/TimeField.vue';

const pad = n => String(n).padStart(2, '0');

export default {
  components: { Link, AdminLayout, PageHeader, DatePicker, AcctSelect, AttachCamera, ListPicker, ContactPicker, VisitorPicker, PersonPicker, TimeField },
  props: { item: Object, visitor: Object, senderContact: Object, fromVisit: Object, warehouses: { type: Array, default: () => [] }, defaultWarehouse: { type: [Number, String], default: null }, types: Array, departments: Array, now: String, timezone: String, canCorrectTime: Boolean, can: Object },
  data() {
    const i = this.item || {};
    const fv = this.fromVisit || null;
    const t = new Date(this.now);
    return {
      offset: new Date(this.now).getTime() - Date.now(), tick: Date.now(), timer: null, setTime: false, linked: fv,
      form: this.$inertia.form({
        warehouse_id: i.warehouse_id || this.defaultWarehouse || '', item_type: i.id ? i.item_type || '' : this.firstOf(this.types, 'Letter'), recipient_user_id: i.recipient_user_id || (fv ? fv.host_user_id : '') || '', department: i.id ? i.department || '' : this.firstOf(this.departments, 'Management'), item_count: i.item_count || 1, subject: i.subject || '', is_confidential: !!i.is_confidential,
        sender_contact_id: i.sender_contact_id || (fv && fv.visitor ? fv.visitor.contact_id || '' : ''), sender_company: i.sender_company || (fv && fv.visitor ? fv.visitor.company || '' : ''), courier: i.courier || '', tracking_number: i.tracking_number || '', item_condition: i.item_condition || '',
        visit_id: i.visit_id || (fv ? fv.id : ''), visitor_id: i.visitor_id || (fv ? fv.visitor_id : ''), remarks: i.remarks || '', attachments: [],
        received_date: t.getFullYear() + '-' + pad(t.getMonth() + 1) + '-' + pad(t.getDate()), received_time: pad(t.getHours()) + ':' + pad(t.getMinutes()), time_reason: '',
      }),
    };
  },
  computed: {
    pageTitle() { return this.item ? this.$t('Edit :r', { r: this.item.reference }) : this.$t('Register an Incoming Item'); },
    typeOptions() { const l = this.types.map(x => ({ value: x, label: x })); if (this.item && this.item.item_type && !l.some(o => o.value === this.item.item_type)) l.push({ value: this.item.item_type, label: this.item.item_type }); return l; },
    canSave() { return !!(this.form.item_type || '').trim() && !!this.form.recipient_user_id && (!this.warehouses.length || !!this.form.warehouse_id); },
    clock() { try { return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'medium', timeZone: this.timezone, hour12: true }).format(new Date(this.tick + this.offset)); } catch (e) { return new Date(this.tick + this.offset).toLocaleString(); } },
  },
  mounted() { if (!this.item) this.timer = setInterval(() => (this.tick = Date.now()), 1000); },
  beforeUnmount() { clearInterval(this.timer); },
  methods: {
    firstOf(list, wanted) { const f = (list || []).find(x => x.toLowerCase() === wanted.toLowerCase()); return f || ''; },
    err(k) { return (this.$page.props.errors || {})[k]; },
    onSender() { /* the server keeps the contact's name and phone on the item */ },
    unlinkVisit() { this.linked = null; this.form.visit_id = ''; this.form.visitor_id = ''; },
    save() {
      const t = d => {
        const out = { ...d, is_confidential: d.is_confidential ? 1 : 0 };
        delete out.received_date; delete out.received_time; delete out.time_reason;
        if (!this.item && this.setTime) { out.received_at = d.received_date + ' ' + d.received_time; out.time_reason = d.time_reason; }
        return out;
      };
      if (this.item) this.form.transform(d => ({ ...t(d), _method: 'put' })).post(route('visitor-logs.items.update', this.item.id), { preserveScroll: true });
      else this.form.transform(t).post(route('visitor-logs.items.store'), { preserveScroll: true, forceFormData: true });
    },
  },
};
</script>

<style scoped>
.vl-req { color: #dc2626; }
.vl-fixed { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.vl-check { display: flex; align-items: flex-start; gap: 10px; margin-top: 12px; padding: 12px 14px; border-radius: 14px; background: var(--lg-soft, #f1f5f9); cursor: pointer; }
.vl-check.is-plain { padding: 0; background: none; }
.vl-check > span { display: grid; gap: 2px; } .vl-check small { color: var(--lg-mute, #64748b); font-weight: 600; font-size: 0.76rem; }
.vl-clock { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 12px; padding: 12px 16px; border-radius: 16px; background: var(--lg-soft, #f1f5f9); box-shadow: inset 0 0 0 1px var(--lg-line, #e3e9f2); color: #0369a1; }
.vl-clock b { font-size: 1.15rem; color: var(--lg-ink, #0f2540); font-variant-numeric: tabular-nums; }
.vl-clock small { margin-left: auto; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-mute, #64748b); font-size: 0.68rem; }
.vl-setting { margin-top: 10px; }
.vl-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 10px; margin: 4px 0 24px; }
@media (max-width: 560px) { .vl-actions { flex-direction: column-reverse; } .vl-actions .lg-btn { width: 100%; justify-content: center; } .vl-clock small { margin-left: 0; width: 100%; } }
</style>

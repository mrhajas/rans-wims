<template>
  <admin-layout :title="t.reference">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="plane" :title="t.reference" :subtitle="t.to_place + (t.to_country ? ', ' + t.to_country : '') + ' - ' + names">
        <Link :href="route('travels.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
        <a v-if="can.print" :href="route('travels.print', t.id)" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" /><span>{{ $t('Print') }}</span></a>
        <a v-if="can.print" :href="route('travels.pdf', t.id)" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" /><span>{{ $t('Download') }}</span></a>
        <Link v-if="can.edit" :href="route('travels.edit', t.id)" class="lg-btn lg-btn-soft"><icons name="edit" class="w-4 h-4" /><span>{{ can.edit_limited ? $t('Correct details') : $t('Edit') }}</span></Link>
      </page-header>

      <section class="lg-card">
        <div class="ac-grid-2" style="align-items: center">
          <div>
            <span class="lg-chip" :class="statusChip(t.status)">{{ t.status_label }}</span>
            <span class="lg-chip" :class="t.scope === 'international' ? 'lg-chip-violet' : 'lg-chip-slate'" style="text-transform: none">{{ t.scope_label }}</span>
            <span v-if="t.has_visa" class="lg-chip lg-chip-amber" style="text-transform: none">{{ $t('Visa included') }}</span>
            <div class="ac-cur-total" style="margin-top: 6px">{{ t.currency }} {{ money(t.total_amount) }} <small class="lg-subtext">{{ $t('for :n traveller(s)', { n: t.travellers.length }) }}</small></div>
          </div>
          <div class="ac-actions" style="justify-content: flex-end; margin: 0">
            <button v-if="can.approve" type="button" class="lg-btn lg-btn-green" :disabled="busy" @click="approve"><icons name="tick" class="w-4 h-4" />{{ $t('Approve') }}</button>
            <button v-if="can.reject" type="button" class="lg-btn lg-btn-red" @click="ask('reject')"><icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}</button>
            <button v-if="can.cancel" type="button" class="lg-btn t-red" @click="ask('cancel')"><icons name="x-circle" class="w-4 h-4" />{{ $t('Cancel it') }}</button>
          </div>
        </div>
        <form v-if="asking" class="ac-reason" @submit.prevent="send"><input v-model="reason" :placeholder="asking === 'reject' ? $t('Why is it rejected? (required)') : $t('Why is it cancelled? (required)')" required /><button type="submit" class="lg-btn lg-btn-primary" :disabled="busy"><icons name="tick" class="w-4 h-4" />{{ $t('Confirm') }}</button><button type="button" class="lg-btn t-slate" @click="asking = ''"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></form>
        <div v-if="t.status === 'Pending Approval'" class="lg-note lg-note-amber" style="margin-top: 10px"><icons name="clock" class="w-4 h-4" /><span>{{ $t('Waiting for approval. The ticket agent is not owed anything for this travel until it is approved.') }}</span></div>
        <div v-if="t.status === 'Rejected'" class="lg-note lg-note-red" style="margin-top: 10px">{{ $t('Rejected') }}<template v-if="t.decided_by"> {{ $t('by') }} {{ t.decided_by }}</template>: {{ t.decision_remarks }}</div>
        <div v-if="t.status === 'Cancelled'" class="lg-note lg-note-amber" style="margin-top: 10px">{{ $t('Cancelled') }}: {{ t.cancel_reason }}</div>
      </section>

      <!-- the journey -->
      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="plane" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('The Journey') }}</h2></div></header>
        <div class="tr-route">
          <div class="tr-stop"><small>{{ $t('From') }}</small><b>{{ t.from_place || '-' }}</b><span>{{ t.from_country }}</span></div>
          <div class="tr-line"><icons name="plane" class="w-5 h-5" /><span>{{ t.trip_label }}</span></div>
          <div class="tr-stop is-to"><small>{{ $t('To') }}</small><b>{{ t.to_place }}</b><span>{{ t.to_country }}</span></div>
        </div>
        <dl class="ac-kv" style="margin-top: 12px">
          <div><dt>{{ $t('Departs') }}</dt><dd>{{ $date(t.depart_date) }} <small v-if="t.days_left !== null && t.status !== 'Cancelled' && t.status !== 'Rejected'" class="lg-subtext">{{ t.days_left === 0 ? $t('today') : $t('in :n day(s)', { n: t.days_left }) }}</small></dd></div>
          <div v-if="t.trip_type === 'return'"><dt>{{ $t('Returns') }}</dt><dd>{{ t.return_date ? $date(t.return_date) : '-' }}</dd></div>
          <div v-if="t.travel_type"><dt>{{ $t('Kind of travel') }}</dt><dd>{{ t.travel_type }}</dd></div>
          <div><dt>{{ $t('Flight') }}</dt><dd>{{ [t.airline, t.flight_number].filter(Boolean).join(' ') || '-' }}</dd></div>
          <div v-if="t.trip_type === 'return' && (t.return_flight_number || t.return_airline)"><dt>{{ $t('Return flight') }}</dt><dd>{{ [t.return_airline || t.airline, t.return_flight_number].filter(Boolean).join(' ') }}</dd></div>
          <div v-if="t.booking_reference"><dt>{{ $t('Booking reference (PNR)') }}</dt><dd>{{ t.booking_reference }}</dd></div>
          <div><dt>{{ $t('Ticket booked on') }}</dt><dd>{{ t.booking_date ? $date(t.booking_date) : '-' }}</dd></div>
          <div><dt>{{ $t('Recorded by') }}</dt><dd>{{ t.by }} <small class="lg-subtext">{{ t.created_at }}</small></dd></div>
          <div v-if="t.decided_by && t.status === 'Approved'"><dt>{{ $t('Approved by') }}</dt><dd>{{ t.decided_by }} <small class="lg-subtext">{{ t.decided_at }}</small></dd></div>
        </dl>
        <p v-if="t.purpose" class="lg-sub" style="margin-top: 8px"><b>{{ $t('Purpose') }}:</b> {{ t.purpose }}</p>
        <p v-if="t.notes" class="lg-sub"><b>{{ $t('Notes') }}:</b> {{ t.notes }}</p>
        <p v-if="t.decision_remarks && t.status === 'Approved'" class="lg-sub"><b>{{ $t('Approval remarks') }}:</b> {{ t.decision_remarks }}</p>
      </section>

      <!-- the people and the money -->
      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="users" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Travellers and Cost') }}</h2></div></header>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('Traveller') }}</th><th>{{ $t('Ticket number') }}</th><th class="ac-num">{{ $t('Ticket') }}</th><th v-if="t.has_visa" class="ac-num">{{ $t('Visa') }}</th><th class="ac-num">{{ $t('Total') }}</th></tr></thead>
          <tbody>
            <tr v-for="p in t.travellers" :key="p.id"><td :data-label="$t('Traveller')" class="lg-main">{{ p.name }}<div class="lg-subtext">{{ { staff: $t('Staff'), user: $t('User'), other: $t('Other') }[p.type] }}<template v-if="p.staff_number"> · {{ p.staff_number }}</template><template v-if="p.phone"> · {{ p.phone }}</template></div></td>
              <td :data-label="$t('Ticket number')">{{ p.ticket_number || '-' }}</td><td :data-label="$t('Ticket')" class="ac-num">{{ money(p.ticket_amount) }}</td><td v-if="t.has_visa" :data-label="$t('Visa')" class="ac-num">{{ money(p.visa_amount) }}</td><td :data-label="$t('Total')" class="ac-num"><b>{{ money(Number(p.ticket_amount) + Number(p.visa_amount)) }}</b></td></tr>
          </tbody>
          <tfoot><tr><td colspan="2" class="ac-num"><b>{{ $t('Total') }} {{ t.currency }}</b></td><td class="ac-num"><b>{{ money(t.ticket_amount) }}</b></td><td v-if="t.has_visa" class="ac-num"><b>{{ money(t.visa_amount) }}</b></td><td class="ac-num"><b>{{ money(t.total_amount) }}</b></td></tr></tfoot>
        </table></div>
        <div class="tr-agents">
          <div class="tr-agent"><small>{{ $t('Ticket issued by') }}</small><b>{{ t.ticket_agent || '-' }}</b><span v-if="t.ticket_agent_phone">{{ t.ticket_agent_phone }}</span>
            <Link v-if="can.agents && t.ticket_agent_id" :href="route('travels.agents.show', t.ticket_agent_id)" class="tr-owed"><icons name="cash" class="w-4 h-4" />{{ $t('Their account') }}<template v-if="t.ticket_agent_owed && t.ticket_agent_owed[t.currency] !== undefined">: {{ $t('owed') }} {{ t.currency }} {{ money(t.ticket_agent_owed[t.currency]) }}</template></Link></div>
          <div v-if="t.has_visa" class="tr-agent"><small>{{ $t('Visa issued by') }}</small><b>{{ t.visa_agent || '-' }}</b><span v-if="t.visa_type || t.visa_country">{{ [t.visa_type, t.visa_country].filter(Boolean).join(', ') }}</span><span v-if="t.visa_reference">{{ $t('Reference') }}: {{ t.visa_reference }}</span>
            <Link v-if="can.agents && t.visa_agent_id && t.visa_agent_id !== t.ticket_agent_id" :href="route('travels.agents.show', t.visa_agent_id)" class="tr-owed"><icons name="cash" class="w-4 h-4" />{{ $t('Their account') }}<template v-if="t.visa_agent_owed && t.visa_agent_owed[t.currency] !== undefined">: {{ $t('owed') }} {{ t.currency }} {{ money(t.visa_agent_owed[t.currency]) }}</template></Link></div>
        </div>
        <p v-if="t.status === 'Approved'" class="lg-sub" style="margin-top: 8px">{{ $t('This travel is part of what the agent is owed. Payments to the agent are made from their account, through a Payment Voucher.') }}</p>
      </section>

      <section v-if="can.agents && payments.length" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="cash" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Payments made for this travel') }}</h2></div></header>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th>{{ $t('Payment') }}</th><th>{{ $t('Agent') }}</th><th>{{ $t('Voucher') }}</th><th>{{ $t('Date') }}</th><th class="ac-num">{{ $t('Amount') }}</th><th>{{ $t('Status') }}</th></tr></thead>
          <tbody><tr v-for="p in payments" :key="p.id" class="is-click" @click="$inertia.visit(route('travels.agents.payments.show', p.id))"><td :data-label="$t('Payment')" class="lg-main">{{ p.reference }}</td><td :data-label="$t('Agent')">{{ p.agent }}</td><td :data-label="$t('Voucher')">{{ p.pv_number || '-' }}</td><td :data-label="$t('Date')">{{ $date(p.date) }}</td><td :data-label="$t('Amount')" class="ac-num">{{ p.currency }} {{ money(p.amount) }}</td><td :data-label="$t('Status')"><span class="lg-chip" :class="payChip(p.status)">{{ p.status_label }}</span></td></tr></tbody></table></div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Attachments') }}</h2><p class="lg-card-sub">{{ $t('Tickets, visas, invoices and other supporting documents for this travel.') }}</p></div></header>
        <acct-file-list :files="attachments" :download-url="f => route('travels.download', [t.id, f.id])" :removable="can.remove_file" @remove="removeFile" />
        <form v-if="can.attach" class="ac-attach" @submit.prevent="upload">
          <acct-dropzone v-model="up.attachments" :hint="$t('PDF, images and office files up to 8 MB each.')" />
          <span class="ac-err">{{ ($page.props.errors || {}).attachments || ($page.props.errors || {})['attachments.0'] }}</span>
          <div><button type="submit" class="lg-btn lg-btn-primary" :disabled="!up.attachments.length || up.processing"><icons name="upload" class="w-4 h-4" />{{ up.processing ? $t('Uploading...') : $t('Attach :n file(s)', { n: up.attachments.length }) }}</button></div>
        </form>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AcctDropzone from '@/Pages/Accounts/AcctDropzone.vue';
import AcctFileList from '@/Pages/Accounts/AcctFileList.vue';

export default {
  components: { AdminLayout, PageHeader, Link, AcctDropzone, AcctFileList },
  props: { t: Object, attachments: Array, payments: Array, can: Object },
  data() { return { asking: '', reason: '', busy: false, up: this.$inertia.form({ attachments: [] }) }; },
  computed: { names() { return this.t.travellers.map(p => p.name).join(', '); } },
  methods: {
    money(v) { return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    statusChip(s) { return { 'Pending Approval': 'lg-chip-amber', Approved: 'lg-chip-green', Rejected: 'lg-chip-red', Cancelled: 'lg-chip-slate' }[s] || 'lg-chip-slate'; },
    payChip(s) { return { Paid: 'lg-chip-green', 'Pending Voucher': 'lg-chip-amber', Released: 'lg-chip-slate' }[s] || 'lg-chip-slate'; },
    upload() { this.up.post(route('travels.attach', this.t.id), { forceFormData: true, preserveScroll: true, onSuccess: () => this.up.reset('attachments') }); },
    removeFile(f) { this.$inertia.delete(route('travels.remove-file', [this.t.id, f.id]), { preserveScroll: true }); },
    approve() { this.busy = true; this.$inertia.post(route('travels.approve', this.t.id), {}, { preserveScroll: true, onFinish: () => (this.busy = false) }); },
    ask(a) { this.asking = a; this.reason = ''; },
    send() { this.busy = true; this.$inertia.post(route('travels.' + this.asking, this.t.id), { reason: this.reason }, { preserveScroll: true, onFinish: () => (this.busy = false), onSuccess: () => (this.asking = '') }); },
  },
};
</script>

<style scoped>
.tr-route { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); align-items: center; gap: 14px; padding: 14px 16px; border-radius: 18px; background: linear-gradient(135deg, rgba(14, 116, 144, 0.1), rgba(79, 70, 229, 0.08)); }
.tr-stop { display: grid; gap: 2px; min-width: 0; } .tr-stop.is-to { text-align: right; }
.tr-stop small { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; color: var(--lg-mute, #64748b); } .tr-stop b { font-size: 1.15rem; overflow-wrap: anywhere; } .tr-stop span { font-size: 0.82rem; color: var(--lg-mute, #64748b); }
.tr-line { display: grid; justify-items: center; gap: 2px; color: #0e7490; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; }
@media (max-width: 560px) { .tr-route { grid-template-columns: 1fr; text-align: left; } .tr-stop.is-to { text-align: left; } .tr-line { justify-items: start; grid-auto-flow: column; justify-content: start; gap: 8px; } }
.tr-agents { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; margin-top: 14px; }
.tr-agent { display: grid; gap: 3px; padding: 12px 14px; border-radius: 16px; background: var(--lg-soft, #f1f5f9); } .tr-agent small { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; color: var(--lg-mute, #64748b); } .tr-agent span { font-size: 0.82rem; color: var(--lg-mute, #64748b); }
.tr-owed { display: inline-flex; align-items: center; gap: 6px; margin-top: 4px; font-size: 0.8rem; font-weight: 700; color: #0e7490; width: fit-content; }
</style>

<template>
  <admin-layout :title="d.reference">
    <div class="lg-page ac sc-ui tr-ui wa-ui px-4 md:px-0">
      <page-header icon="doc-text" :title="d.reference" :subtitle="d.department + ' - ' + $date(d.doc_date) + ' - ' + (d.beneficiary || '')">
        <Link :href="route('weekly-administration.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Back') }}</span></Link>
        <a v-if="can.print" :href="route('weekly-administration.print', d.id)" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" /><span>{{ $t('Print') }}</span></a>
        <a v-if="can.print" :href="route('weekly-administration.pdf', d.id)" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" /><span>{{ $t('Download') }}</span></a>
        <button v-if="can.send" type="button" class="lg-btn lg-btn-soft" @click="sendOpen = true"><icons name="send" class="w-4 h-4" /><span>{{ $t('Send') }}</span></button>
        <button v-if="can.duplicate" type="button" class="lg-btn lg-btn-soft" @click="dupOpen = true"><icons name="duplicate" class="w-4 h-4" /><span>{{ $t('Duplicate') }}</span></button>
        <Link v-if="can.edit" :href="route('weekly-administration.edit', d.id)" class="lg-btn lg-btn-soft"><icons name="edit" class="w-4 h-4" /><span>{{ $t('Edit') }}</span></Link>
      </page-header>

      <section class="lg-card">
        <div class="wa-stages">
          <div v-for="s in stages" :key="s.key" class="wa-stage" :class="s.cls">
            <span class="wa-node"><icons :name="s.icon" class="w-5 h-5" /></span>
            <b>{{ s.label }}</b>
            <small>{{ s.who || (s.cls === 'is-now' ? $t('Waiting') : '-') }}</small>
            <em v-if="s.at">{{ s.at }}</em>
          </div>
        </div>
        <div class="ac-actions" style="justify-content: flex-end; margin-top: 8px">
          <button v-if="can.approve" type="button" class="lg-btn lg-btn-green" :disabled="busy" @click="ask('approve')"><icons name="tick" class="w-4 h-4" />{{ $t('Approve') }}</button>
          <button v-if="can.authorize" type="button" class="lg-btn lg-btn-green" :disabled="busy" @click="ask('authorize')"><icons name="check-double" class="w-4 h-4" />{{ $t('Authorize') }}</button>
          <button v-if="can.reject" type="button" class="lg-btn lg-btn-red" @click="ask('reject')"><icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}</button>
          <button v-if="can.cancel" type="button" class="lg-btn t-red" @click="ask('cancel')"><icons name="x-circle" class="w-4 h-4" />{{ $t('Cancel it') }}</button>
        </div>
        <form v-if="asking" class="ac-reason" @submit.prevent="send">
          <input v-model="reason" :placeholder="placeholder" :required="asking === 'reject' || asking === 'cancel'" maxlength="1000" />
          <button type="submit" class="lg-btn lg-btn-primary" :disabled="busy"><icons name="tick" class="w-4 h-4" />{{ $t('Confirm') }}</button>
          <button type="button" class="lg-btn t-slate" @click="asking = ''">{{ $t('Not now') }}</button>
        </form>
        <div v-if="d.status === 'Requested'" class="lg-note lg-note-amber" style="margin-top: 10px"><icons name="clock" class="w-4 h-4" /><span>{{ $t('Waiting for approval (stage 2 of 3).') }}</span></div>
        <div v-if="d.status === 'Approved'" class="lg-note lg-note-amber" style="margin-top: 10px"><icons name="clock" class="w-4 h-4" /><span>{{ $t('Approved. Waiting for authorization (stage 3 of 3).') }}</span></div>
        <div v-if="d.status === 'Rejected'" class="lg-note lg-note-red" style="margin-top: 10px">{{ $t('Rejected') }}<template v-if="d.rejected_by"> {{ $t('by') }} {{ d.rejected_by }}</template>: {{ d.rejection_reason }}</div>
        <div v-if="d.status === 'Cancelled'" class="lg-note lg-note-amber" style="margin-top: 10px">{{ $t('Cancelled') }}: {{ d.cancel_reason }}</div>
        <p v-if="d.approval_remarks" class="lg-sub" style="margin-top: 8px"><b>{{ $t('Approval remarks') }}:</b> {{ d.approval_remarks }}</p>
        <p v-if="d.authorization_remarks" class="lg-sub"><b>{{ $t('Authorization remarks') }}:</b> {{ d.authorization_remarks }}</p>
      </section>

      <section class="lg-card">
        <div class="wa-meta">
          <div><small>{{ $t('Department') }}</small><b>{{ d.department }}</b></div>
          <div><small>{{ $t('Date') }}</small><b>{{ $date(d.doc_date) }}</b></div>
          <div><small>{{ $t('Requested by') }}</small><b>{{ d.beneficiary || '-' }}</b></div>
          <div><small>{{ $t('Prepared by') }}</small><b>{{ d.by || '-' }}</b></div>
          <div v-if="d.duplicated_from"><small>{{ $t('Duplicated from') }}</small><b><Link :href="route('weekly-administration.show', d.duplicated_from.id)">{{ d.duplicated_from.reference }}</Link></b></div>
          <div v-if="d.sent_count"><small>{{ $t('Sent by email') }}</small><b>{{ d.sent_count }} {{ $t('time(s)') }}</b></div>
        </div>
        <p v-if="d.notes" class="lg-sub" style="margin-top: 8px"><b>{{ $t('Notes') }}:</b> {{ d.notes }}</p>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Lines') }}</h2></div></header>
        <div class="wa-paper"><table>
          <thead><tr><th class="no">{{ $t('No') }}</th><th>{{ $t('Description') }}</th><th class="hide-sm">{{ $t('Beneficiary') }}</th><th class="num">{{ $t('Amount') }} ({{ d.currency }})</th></tr></thead>
          <tbody>
            <tr v-for="l in d.lines" :key="l.no"><td class="no">{{ l.no }}</td><td>{{ l.description }}</td><td class="hide-sm">{{ l.beneficiary || '-' }}</td><td class="num tr-m">{{ money(l.amount) }}</td></tr>
            <tr v-if="!d.lines.length"><td colspan="4" class="lg-empty" style="text-align: center">{{ $t('No lines.') }}</td></tr>
          </tbody>
          <tfoot><tr><td colspan="3" style="text-align: right">{{ $t('Total') }} {{ d.currency }}</td><td class="num tr-tm">{{ money(d.items_total) }}</td></tr></tfoot>
        </table></div>
      </section>

      <section v-if="d.include_charity" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="heart" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Charity') }}</h2></div></header>
        <div class="wa-paper is-charity"><table>
          <thead><tr><th class="no">{{ $t('No') }}</th><th>{{ $t('Charity') }}</th><th class="num">{{ $t('Amount') }} ({{ d.currency }})</th></tr></thead>
          <tbody>
            <tr v-for="c in d.charity_lines" :key="c.no"><td class="no">{{ c.no }}</td><td>{{ c.description }}</td><td class="num tr-m">{{ money(c.amount) }}<div v-if="Number(c.monthly_amount) && Number(c.monthly_amount) !== Number(c.amount)" class="lg-subtext">{{ $t('monthly') }} {{ money(c.monthly_amount) }}</div></td></tr>
            <tr v-if="!d.charity_lines.length"><td colspan="3" class="lg-empty" style="text-align: center">{{ $t('No charity lines.') }}</td></tr>
          </tbody>
          <tfoot><tr><td colspan="2" style="text-align: right">{{ $t('Total') }} {{ d.currency }}</td><td class="num tr-tm">{{ money(d.charity_total) }}</td></tr></tfoot>
        </table></div>
      </section>

      <div class="wa-grand" style="margin-bottom: 12px"><span>{{ d.include_charity ? $t('Total of the document with the charity') : $t('Total of the document') }}</span><b>{{ d.currency }} {{ money(d.total_amount) }}</b></div>

      <section v-if="sends.length" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="send" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Sent by email') }}</h2></div></header>
        <ul class="wa-act"><li v-for="s in sends" :key="s.id"><small>{{ s.at }}</small><span><b>{{ s.by }}</b> {{ $t('to') }} {{ s.recipients.join(', ') }} <span class="lg-chip" :class="s.status === 'Sent' ? 'lg-chip-green' : 'lg-chip-red'" style="text-transform: none">{{ s.status }}</span><small v-if="s.error"> {{ s.error }}</small></span></li></ul>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('History') }}</h2></div></header>
        <ul class="wa-act"><li v-for="a in activity" :key="a.id"><small>{{ a.at }}</small><span>{{ a.text }}<template v-if="a.by"> - <b>{{ a.by }}</b></template></span></li><li v-if="!activity.length"><span class="lg-sub">{{ $t('Nothing yet.') }}</span></li></ul>
      </section>

      <send-dialog :show="sendOpen" :doc="d" @close="sendOpen = false" />
      <duplicate-dialog :show="dupOpen" :doc="d" @close="dupOpen = false" />
    </div>
  </admin-layout>
</template>

<script>
import SendDialog from './SendDialog.vue';
import DuplicateDialog from './DuplicateDialog.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { SendDialog, DuplicateDialog, AdminLayout, PageHeader, Link },
  props: { d: Object, sends: Array, activity: Array, can: Object, users: Array },
  data() { return { asking: '', reason: '', busy: false, sendOpen: false, dupOpen: false }; },
  computed: {
    stages() {
      const d = this.d; const order = ['Requested', 'Approved', 'Authorized']; const at = order.indexOf(d.status);
      const dead = d.status === 'Rejected' || d.status === 'Cancelled';
      const mk = (key, label, icon, who, when, i) => ({ key, label, icon, who, at: when, cls: dead ? (i === 0 ? 'is-done' : '') : (i < at ? 'is-done' : i === at ? (key === 'Authorized' ? 'is-done' : 'is-now') : '') });
      const s = [mk('Requested', this.$t('Requested'), 'doc-text', d.beneficiary, this.$date(d.doc_date), 0), mk('Approved', this.$t('Approved'), 'tick', d.approved_by, d.approved_at, 1), mk('Authorized', this.$t('Authorized'), 'check-double', d.authorized_by, d.authorized_at, 2)];
      if (dead) { const i = d.status === 'Rejected' ? (d.approved_by ? 2 : 1) : 1; s[i].cls = 'is-bad'; s[i].who = d.status === 'Rejected' ? this.$t('Rejected') : this.$t('Cancelled'); s[i].at = d.status === 'Rejected' ? d.rejected_by : ''; s[i].icon = 'cross'; }
      if (d.status === 'Authorized') s[2].cls = 'is-done';
      return s;
    },
    placeholder() { return { reject: this.$t('Why is it rejected? (required)'), cancel: this.$t('Why is it cancelled? (required)'), approve: this.$t('Remarks (optional)'), authorize: this.$t('Remarks (optional)') }[this.asking]; },
  },
  methods: {
    money(v) { return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    ask(a) { this.asking = a; this.reason = ''; },
    send() {
      const a = this.asking; this.busy = true;
      const body = a === 'reject' || a === 'cancel' ? { reason: this.reason } : { remarks: this.reason };
      this.$inertia.post(route('weekly-administration.' + a, this.d.id), body, { preserveScroll: true, onSuccess: () => { this.asking = ''; }, onFinish: () => (this.busy = false) });
    },
  },
};
</script>

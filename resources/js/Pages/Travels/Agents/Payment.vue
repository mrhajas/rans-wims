<template>
  <admin-layout :title="p.reference">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="cash" :title="p.reference" :subtitle="$t('Payment to :a', { a: p.agent })">
        <Link :href="route('travels.agents.show', p.agent_id)" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Agent account') }}</span></Link>
        <a v-if="voucher && voucher.url" :href="voucher.url" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="doc-text" class="w-4 h-4" /><span>{{ $t('Open the Payment Voucher') }}</span></a>
        <a v-if="can.print" :href="route('travels.agents.payments.print', p.id)" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" /><span>{{ $t('Print') }}</span></a>
        <a v-if="can.print" :href="route('travels.agents.payments.pdf', p.id)" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" /><span>{{ $t('Download') }}</span></a>
      </page-header>

      <section class="lg-card">
        <span class="lg-chip" :class="payChip(p.status)">{{ p.status_label }}</span>
        <div class="ac-cur-total" style="margin-top: 6px">{{ p.currency }} {{ money(p.amount) }} <small v-if="p.currency !== 'TZS'" class="lg-subtext">{{ $t('voucher TZS :a at :r', { a: money(p.voucher_amount), r: Number(p.exchange_rate).toLocaleString(undefined, { maximumFractionDigits: 6 }) }) }}</small></div>
        <div v-if="p.status === 'Pending Voucher'" class="lg-note lg-note-amber" style="margin-top: 10px"><icons name="clock" class="w-4 h-4" /><span>{{ $t('The Payment Voucher is waiting to be approved and paid. The balance of :a does not change until it is paid.', { a: p.agent }) }}</span></div>
        <div v-if="p.status === 'Released'" class="lg-note lg-note-red" style="margin-top: 10px">{{ $t('Released: the voucher was not paid') }}<template v-if="p.release_reason"> ({{ p.release_reason }})</template>. {{ $t('The amount is available again.') }}</div>
        <div v-if="p.status === 'Paid' && p.balance_after !== null" class="lg-note lg-note-green" style="margin-top: 10px"><icons name="tick" class="w-4 h-4" /><span>{{ $t('Paid. After this payment the balance owed to :a was :c :m.', { a: p.agent, c: p.currency, m: money(p.balance_after) }) }}</span></div>
      </section>

      <section v-if="voucher" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Payment Voucher :n', { n: voucher.pv_number }) }}</h2><p class="lg-card-sub">{{ $t('The voucher is approved and paid in the Payment Vouchers module; this is where it stands.') }}</p></div></header>
        <ol class="pm-steps">
          <li v-for="s in steps" :key="s.key" :class="{ 'is-done': s.done, 'is-now': s.now }"><span class="pm-dot">{{ s.done ? '' : (s.n) }}<icons v-if="s.done" name="tick" class="w-3 h-3" /></span><div><small>{{ s.label }}</small><b>{{ s.by || (s.now ? $t('Waiting') : '-') }}</b><em>{{ s.at || '' }}</em></div></li>
        </ol>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Details') }}</h2></div></header>
        <dl class="ac-kv">
          <div><dt>{{ $t('Agent') }}</dt><dd><Link :href="route('travels.agents.show', p.agent_id)">{{ p.agent }}</Link> <small v-if="p.agent_phone" class="lg-subtext">{{ p.agent_phone }}</small></dd></div>
          <div><dt>{{ $t('Asked on') }}</dt><dd>{{ $date(p.requested_on) }} <small class="lg-subtext">{{ p.by }}</small></dd></div>
          <div><dt>{{ $t('Paid on') }}</dt><dd>{{ p.paid_on ? $date(p.paid_on) : '-' }} <small v-if="p.paid_by" class="lg-subtext">{{ p.paid_by }}</small></dd></div>
          <div><dt>{{ $t('Method') }}</dt><dd>{{ p.method || '-' }}</dd></div>
          <div><dt>{{ $t('Payment reference') }}</dt><dd>{{ p.payment_reference || '-' }}</dd></div>
          <div v-if="p.travel_id"><dt>{{ $t('For travel') }}</dt><dd><Link :href="route('travels.show', p.travel_id)">{{ p.travel }}</Link></dd></div>
          <div><dt>{{ $t('Owed to the agent now') }}</dt><dd><span v-for="(v, c) in owed" :key="c" class="tr-owed-now">{{ c }} {{ money(v) }}</span><span v-if="!Object.keys(owed).length">-</span></dd></div>
        </dl>
        <p v-if="p.notes" class="lg-sub" style="margin-top: 8px"><b>{{ $t('Notes') }}:</b> {{ p.notes }}</p>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Attachments') }}</h2><p class="lg-card-sub">{{ $t('Receipts, bank slips or any proof of this payment.') }}</p></div></header>
        <acct-file-list :files="attachments" :download-url="f => route('travels.agents.payments.download', [p.id, f.id])" :removable="can.remove_file" @remove="removeFile" />
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
  props: { p: Object, voucher: Object, attachments: Array, owed: Object, can: Object },
  data() { return { up: this.$inertia.form({ attachments: [] }) }; },
  computed: {
    steps() {
      const v = this.voucher; if (!v) return [];
      const released = this.p.status === 'Released';
      const list = [{ key: 'prepared', n: 1, label: this.$t('Prepared'), by: v.prepared_by, at: '', done: true }, { key: 'approved', n: 2, label: this.$t('Approved'), by: v.approved_by, at: v.approved_at, done: !!v.approved_by }, { key: 'authorised', n: 3, label: this.$t('Authorised'), by: v.authorised_by, at: v.authorised_at, done: !!v.authorised_by }, { key: 'paid', n: 4, label: this.$t('Marked paid'), by: v.paid_by, at: v.paid_at, done: !!v.paid }];
      const firstOpen = list.findIndex(s => !s.done);
      return list.map((s, i) => ({ ...s, now: !released && i === firstOpen }));
    },
  },
  methods: {
    money(v) { return Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    payChip(s) { return { Paid: 'lg-chip-green', 'Pending Voucher': 'lg-chip-amber', Released: 'lg-chip-slate' }[s] || 'lg-chip-slate'; },
    upload() { this.up.post(route('travels.agents.payments.attach', this.p.id), { forceFormData: true, preserveScroll: true, onSuccess: () => this.up.reset('attachments') }); },
    removeFile(f) { this.$inertia.delete(route('travels.agents.payments.remove-file', [this.p.id, f.id]), { preserveScroll: true }); },
  },
};
</script>

<style scoped>
.pm-steps { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
@media (max-width: 720px) { .pm-steps { grid-template-columns: 1fr 1fr; } } @media (max-width: 420px) { .pm-steps { grid-template-columns: 1fr; } }
.pm-steps li { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border-radius: 14px; background: var(--lg-soft, #f1f5f9); }
.pm-dot { flex: none; display: grid; place-items: center; width: 26px; height: 26px; border-radius: 50%; border: 2px dashed #94a3b8; color: #64748b; font-size: 0.74rem; font-weight: 800; }
.pm-steps li.is-done .pm-dot { border: 2px solid #0d8a5a; background: #0d8a5a; color: #fff; } .pm-steps li.is-now .pm-dot { border-color: #ea7a12; color: #ea7a12; }
.pm-steps small { display: block; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--lg-mute, #64748b); } .pm-steps b { display: block; font-size: 0.86rem; overflow-wrap: anywhere; } .pm-steps em { display: block; font-style: normal; font-size: 0.7rem; color: var(--lg-mute, #64748b); }
.tr-owed-now { display: inline-block; margin-right: 12px; font-variant-numeric: tabular-nums; }
</style>

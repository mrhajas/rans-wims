<template>
  <admin-layout :title="c.name">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="users" :title="c.name" :subtitle="$t('Subcontractor profile and account')">
        <Link :href="route('subcontracts.register.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Register') }}</span></Link>
        <a v-if="c.contact_url" :href="c.contact_url" class="lg-btn lg-btn-soft"><icons name="edit" class="w-4 h-4" /><span>{{ $t('Contact details') }}</span></a>
        <Link v-if="can.create_agreement" :href="route('subcontracts.agreements.create', { contact_id: c.id })" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" /><span>{{ $t('New Agreement') }}</span></Link>
        <Link v-if="can.statement && money" :href="route('subcontracts.statements.contact', c.id)" class="lg-btn lg-btn-soft"><icons name="doc-text" class="w-4 h-4" /><span>{{ $t('Account statement') }}</span></Link>
              <export-buttons type="statement" :params="{ contact_id: c.id }" needs-money :money="money" />
      </page-header>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Contact') }}</h2><p class="lg-card-sub">{{ $t('From Contacts: change these details there.') }}</p></div></header>
        <dl class="ac-kv"><div><dt>{{ $t('Name') }}</dt><dd>{{ c.name }}</dd></div><div><dt>{{ $t('Phone') }}</dt><dd>{{ c.phone || '-' }}</dd></div><div><dt>{{ $t('Email') }}</dt><dd>{{ c.email || '-' }}</dd></div><div><dt>{{ $t('Location') }}</dt><dd>{{ c.location || '-' }}</dd></div><div><dt>{{ $t('Status') }}</dt><dd>{{ c.status }}</dd></div></dl>
        <p v-if="c.details" class="lg-sub" style="margin-top: 6px">{{ c.details }}</p>
      </section>

      <template v-if="money && totals">
        <section v-for="(x, cur) in totals" :key="cur" class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="cash" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Account') }} ({{ cur }})</h2></div></header>
          <div class="sc-figs"><div v-for="t in tiles(x)" :key="t.k" class="sc-fig" :class="[t.cls, { 'is-link': t.to }]" :role="t.to ? 'link' : null" :tabindex="t.to ? 0 : null" :title="t.to ? $t('Open') : null" @click="open(t.to)" @keydown.enter="open(t.to)"><small>{{ t.label }}</small><b :class="t.b">{{ m(t.v, cur) }}</b><em v-if="t.em">{{ t.em }}</em></div></div>
        </section>
      </template>

      <section v-if="money && archived_totals && Object.keys(archived_totals).length" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-slate"><icons name="folder" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Archived accounts') }}</h2><p class="lg-card-sub">{{ $t('Kept for reference (older versions and finished accounts). Their balances are shown here and are not in the figures above.') }}</p></div></header>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th><span class="sc-th"><icons name="cash" class="w-4 h-4" />{{ $t('Currency') }}</span></th><th class="ac-num"><span class="sc-th amt-rev"><icons name="doc-text" class="w-4 h-4" />{{ $t('Revised') }}</span></th><th class="ac-num"><span class="sc-th amt-paid"><icons name="cash" class="w-4 h-4" />{{ $t('Paid') }}</span></th><th class="ac-num"><span class="sc-th amt-out"><icons name="clock" class="w-4 h-4" />{{ $t('Balance') }}</span></th></tr></thead>
          <tbody><tr v-for="(x, cur) in archived_totals" :key="cur" class="is-click" tabindex="0" @click="openArchive" @keydown.enter="openArchive"><td :data-label="$t('Currency')" class="lg-main">{{ cur }}</td><td :data-label="$t('Revised')" class="ac-num"><span class="amt-rev">{{ m(x.revised, cur) }}</span></td><td :data-label="$t('Paid')" class="ac-num"><span class="amt-paid">{{ m(x.paid_total, cur) }}</span></td><td :data-label="$t('Balance')" class="ac-num"><b :class="neg(x.commitment_left) ? 'amt-neg' : 'amt-out'">{{ m(x.commitment_left, cur) }}</b></td></tr></tbody></table></div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Agreements') }}</h2></div></header>
        <div v-if="agreements.length > 6" class="sc-tfind"><search-bar v-model="tq" :placeholder="$t('Find an agreement by reference, title, project or status...')" :count="agreementRows.length" /></div>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th><span class="sc-th"><icons name="doc-text" class="w-4 h-4" />{{ $t('Agreement') }}</span></th><th><span class="sc-th"><icons name="building" class="w-4 h-4" />{{ $t('Project(s) / Warehouse(s)') }}</span></th><th>{{ $t('Status') }}</th><th v-if="money" class="ac-num"><span class="sc-th amt-rev"><icons name="doc-text" class="w-4 h-4" />{{ $t('Revised') }}</span></th><th v-if="money" class="ac-num"><span class="sc-th amt-paid"><icons name="cash" class="w-4 h-4" />{{ $t('Paid') }}</span></th><th v-if="money" class="ac-num"><span class="sc-th amt-out"><icons name="clock" class="w-4 h-4" />{{ $t('Outstanding') }}</span></th></tr></thead>
          <tbody><tr v-for="a in agreementRows" :key="a.id" class="is-click" tabindex="0" @click="$inertia.visit(route('subcontracts.agreements.show', a.id))" @keydown.enter="$inertia.visit(route('subcontracts.agreements.show', a.id))"><td :data-label="$t('Agreement')"><Link :href="route('subcontracts.agreements.show', a.id)" class="lg-main" @click.stop>{{ a.reference }}</Link><div class="lg-subtext">{{ a.title }}<span v-if="a.source === 'import'"> · {{ $t('imported') }}</span></div></td>
            <td :data-label="$t('Project(s) / Warehouse(s)')">{{ a.project || '-' }}<div v-if="a.site" class="lg-subtext">{{ a.site }}</div></td><td :data-label="$t('Status')"><span class="lg-chip" :class="chip(a.status)">{{ $t(a.status) }}</span></td>
            <td v-if="money" :data-label="$t('Revised')" class="ac-num"><span v-if="a.figures" class="amt-rev">{{ mc(a.figures.revised, a.currency) }}</span><template v-else>-</template></td><td v-if="money" :data-label="$t('Paid')" class="ac-num"><span v-if="a.figures" class="amt-paid">{{ m(a.figures.paid_total, a.currency) }}</span><template v-else>-</template></td><td v-if="money" :data-label="$t('Outstanding')" class="ac-num"><b v-if="a.figures" :class="neg(a.figures.commitment_left) ? 'amt-neg' : 'amt-out'">{{ m(a.figures.commitment_left, a.currency) }}</b></td></tr>
            <tr v-if="!agreements.length" class="lg-empty-row"><td class="lg-empty" colspan="6">{{ $t('No agreements yet.') }}</td></tr>
            <tr v-else-if="!agreementRows.length" class="lg-empty-row"><td class="lg-empty" colspan="6">{{ $t('Nothing matches your search.') }}</td></tr></tbody></table></div>
      </section>

      <div class="ac-grid-2">
        <section class="lg-card"><header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="send" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Payment requests') }}</h2></div></header>
          <ul class="sc-list"><li v-for="r in requests" :key="r.id"><Link :href="route('subcontracts.requests.show', r.id)" class="lg-main">{{ r.reference }}</Link> <span class="lg-chip" :class="chip(r.status)">{{ $t(r.status) }}</span> <small>{{ r.agreement }} · {{ r.date }}</small><span v-if="money" class="sc-r">{{ mc(r.requested, r.currency) }}</span></li><li v-if="!requests.length" class="lg-sub">{{ $t('None.') }}</li></ul></section>
        <section class="lg-card"><header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="adjustments" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Variations') }}</h2></div></header>
          <ul class="sc-list"><li v-for="v in variations" :key="v.id"><Link :href="route('subcontracts.variations.show', v.id)" class="lg-main">{{ v.reference }}</Link> <span class="lg-chip" :class="chip(v.status)">{{ $t(v.status) }}</span> <small>{{ v.agreement }} · {{ v.title }}</small><span v-if="money" class="sc-r">{{ v.direction === 'reduction' ? '-' : '+' }}{{ m(v.amount, v.currency) }}</span></li><li v-if="!variations.length" class="lg-sub">{{ $t('None.') }}</li></ul></section>
      </div>
      <section v-if="money && vouchers.length" class="lg-card"><header class="lg-card-head"><span class="lg-card-ico lg-ico-teal"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Payment Vouchers') }}</h2></div></header>
        <div v-if="vouchers.length > 6" class="sc-tfind"><search-bar v-model="vq" :placeholder="$t('Find a voucher by number, agreement or status...')" :count="voucherRows.length" /></div>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th><span class="sc-th"><icons name="doc-text" class="w-4 h-4" />{{ $t('Voucher') }}</span></th><th>{{ $t('Agreement') }}</th><th>{{ $t('Status') }}</th><th class="ac-num"><span class="sc-th amt-paid"><icons name="cash" class="w-4 h-4" />{{ $t('Amount (TZS)') }}</span></th><th><span class="sc-th"><icons name="calendar" class="w-4 h-4" />{{ $t('Paid on') }}</span></th></tr></thead>
          <tbody><tr v-for="v in voucherRows" :key="v.id" :class="{ 'is-click': can.vouchers }" :tabindex="can.vouchers ? 0 : null" @click="can.vouchers && $inertia.visit(route('payment-vouchers.show', v.id))" @keydown.enter="can.vouchers && $inertia.visit(route('payment-vouchers.show', v.id))"><td :data-label="$t('Voucher')"><Link v-if="can.vouchers" :href="route('payment-vouchers.show', v.id)" class="lg-main" @click.stop>{{ v.pv }}</Link><span v-else class="lg-main">{{ v.pv }}</span></td><td :data-label="$t('Agreement')">{{ v.agreement }}</td><td :data-label="$t('Status')"><span class="lg-chip" :class="chip(v.status)">{{ v.status }}</span></td><td :data-label="$t('Amount (TZS)')" class="ac-num"><span :class="v.status === 'Paid' ? 'amt-paid' : 'amt-appr'">{{ m(v.amount, 'TZS') }}</span></td><td :data-label="$t('Paid on')">{{ v.paid_at ? $date(v.paid_at) : '-' }}</td></tr>
            <tr v-if="!voucherRows.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('Nothing matches your search.') }}</td></tr></tbody></table></div></section>
    </div>
  </admin-layout>
</template>

<script>
import ExportButtons from '../ExportButtons.vue';
import SearchBar from '../SearchBar.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import { sc, tableFind } from '../sc';

export default {
  components: { ExportButtons, SearchBar, AdminLayout, PageHeader, Link },
  mixins: [sc, tableFind],
  props: { archived_totals: Object, c: Object, totals: Object, agreements: Array, requests: Array, variations: Array, vouchers: Array, money: Boolean, can: Object },
  data() { return { vq: '' }; },
  computed: {
    agreementRows() { return this.agreements.filter(a => this.has(a.reference, a.title, a.project, a.site, a.status)); },
    voucherRows() { const q = this.vq.trim().toLowerCase(); return this.vouchers.filter(v => !q || [v.pv, v.agreement, v.status].some(x => x !== null && x !== undefined && String(x).toLowerCase().includes(q))); },
  },
  methods: {
    open(url) { if (url) this.$inertia.visit(url); },
    openArchive() { this.$inertia.visit(route('subcontracts.agreements.index', { status: 'Archived', contact_id: this.c.id })); },
    // the figure tiles of one currency; each opens the list or the statement behind it
    tiles(x) {
      const ag = route('subcontracts.agreements.index', { contact_id: this.c.id });
      const rq = status => route('subcontracts.requests.index', { contact_id: this.c.id, status });
      const st = this.can.statement ? route('subcontracts.statements.contact', this.c.id) : null;
      return [
        { k: 'o', label: this.$t('Original contract value'), v: x.original, to: ag },
        { k: 'v', label: this.$t('Approved variations (net)'), v: x.variations_net, to: ag },
        { k: 'r', label: this.$t('Revised contract value'), v: x.revised, cls: 'is-strong', b: 'amt-rev', to: ag },
        { k: 'w', label: this.$t('Approved work value'), v: x.approved_work, to: ag },
        { k: 'p', label: this.$t('Pending requests'), v: x.requested_pending, b: 'amt-appr', to: rq('Submitted') },
        { k: 'a', label: this.$t('Approved, not yet paid'), v: x.approved_unpaid, b: 'amt-appr', to: rq('Approved') },
        { k: 't', label: this.$t('Total paid'), v: x.paid_total, cls: 'is-good', b: 'amt-paid', em: this.$t('vouchers') + ' ' + this.m(x.paid_live) + ' + ' + this.$t('historical') + ' ' + this.m(x.paid_legacy), to: st },
        { k: 'c', label: this.$t('Outstanding contract commitment'), v: x.commitment_left, cls: 'is-warn', b: this.neg(x.commitment_left) ? 'amt-neg' : 'amt-out', to: st },
      ];
    },
  },
};
</script>

<style scoped>
.sc-figs { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
.sc-fig { padding: 12px 14px; border-radius: 16px; background: var(--lg-card); box-shadow: 0 12px 20px -16px rgba(15, 37, 64, 0.55), 0 0 0 1px var(--lg-line); display: grid; gap: 2px; min-width: 0; }
.sc-fig small { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute); } .sc-fig b { font-size: 1.15rem; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; } .sc-fig em { font-style: normal; font-size: 0.72rem; color: var(--lg-mute); }
.sc-fig.is-strong { background: linear-gradient(135deg, rgba(37, 99, 235, 0.1), transparent); }
.sc-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; } .sc-list li { font-size: 0.86rem; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; } .sc-list small { color: var(--lg-mute); } .sc-r { margin-left: auto; font-variant-numeric: tabular-nums; font-weight: 700; }
</style>

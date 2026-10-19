<template>
  <admin-layout :title="$t('Subcontractors')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <div class="sc-topbar">
        <form class="sc-find" @submit.prevent="find"><icons name="search" class="w-5 h-5" /><input v-model="q" type="search" :placeholder="$t('Find a subcontractor...')" :aria-label="$t('Find a subcontractor')" /></form>
        <span class="sc-date">{{ today }}</span>
        <button type="button" class="lg-btn lg-btn-soft" @click="showFilters = !showFilters"><icons name="adjustments" class="w-4 h-4" /><span>{{ $t('Filters') }}</span></button>
        <export-buttons type="consolidated" :params="f" needs-money :money="money" :sheets="true" />
        <Link v-if="can.create_agreement" :href="route('subcontracts.agreements.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" /><span>{{ $t('New Agreement') }}</span></Link>
      </div>

      <form v-if="showFilters" class="lg-card" @submit.prevent="go">
        <div class="ac-filters sc-filters">
          <div class="ac-f"><label>{{ $t('Subcontractor') }}</label><acct-select v-model="f.contact" :options="contacts" :placeholder="$t('All')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Project(s) / Warehouse(s)') }}</label><acct-select v-model="f.project" :options="projects" :placeholder="$t('All')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Status') }}</label><acct-select v-model="f.status" :options="statuses" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Currency') }}</label><acct-select v-model="f.currency" :options="currencies" :placeholder="$t('All')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Agreement from') }}</label><date-picker v-model="f.from" /></div>
          <div class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
          <div class="ac-f sc-act"><label aria-hidden="true">&nbsp;</label><div><button type="submit" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Filter') }}</button><button type="button" class="lg-btn lg-btn-soft" @click="reset"><icons name="refresh" class="w-4 h-4" />{{ $t('Reset') }}</button></div></div>
        </div>
      </form>

      <div class="sc-dash">
        <div class="sc-main">
          <section class="sc-hero">
            <div>
              <h1>{{ $t('Welcome back, :n', { n: firstName }) }}</h1>
              <p>{{ heroText }}</p>
              <div class="sc-hero-act">
                <Link v-if="can.requests && counts.requests_waiting" :href="route('subcontracts.requests.index', { status: 'Submitted' })" class="lg-btn lg-btn-primary"><icons name="send" class="w-4 h-4" /><span>{{ $t('Review requests') }}</span></Link>
                <Link v-else-if="can.create_request" :href="route('subcontracts.requests.create')" class="lg-btn lg-btn-primary"><icons name="send" class="w-4 h-4" /><span>{{ $t('New Payment Request') }}</span></Link>
                <Link v-if="can.reports" :href="route('subcontracts.reports.index')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Reports') }}</span></Link>
              </div>
            </div>
            <svg viewBox="0 0 360 200" aria-hidden="true" role="img">
              <ellipse cx="200" cy="170" rx="120" ry="20" fill="#e6ecf6" />
              <polygon points="80,120 200,70 320,120 200,170" fill="#4d62d6" /><polygon points="80,120 80,138 200,188 200,170" fill="#3345b0" /><polygon points="200,170 200,188 320,138 320,120" fill="#2b3a99" />
              <polygon points="95,96 200,52 305,96 200,140" fill="#f4849c" /><polygon points="95,96 95,112 200,156 200,140" fill="#d65a79" /><polygon points="200,140 200,156 305,112 305,96" fill="#bf4a68" />
              <polygon points="120,74 200,40 280,74 200,108" fill="#eef1ff" stroke="#4d62d6" stroke-width="3" /><polygon points="150,70 200,50 250,70 200,90" fill="#dfe5ff" /><polygon points="170,68 200,56 230,68 200,80" fill="#c5cffd" />
              <circle cx="316" cy="46" r="14" fill="#fcd34d" opacity=".9" /><circle cx="46" cy="64" r="8" fill="#34b88a" opacity=".85" /><rect x="40" y="130" width="26" height="26" rx="7" fill="#7c6fe0" opacity=".8" transform="rotate(-14 53 143)" />
            </svg>
          </section>

          <section v-if="money && figures" class="lg-card">
            <header class="lg-card-head"><div class="min-w-0 flex-1"><h2 class="lg-card-title" style="font-size: 1.15rem">{{ $t('Overview') }}</h2><p class="lg-card-sub">{{ $t('Here is the summary of all subcontracts. Every figure is in one currency; currencies are never added together.') }}</p></div>
              <Link v-if="can.reports" :href="route('subcontracts.reports.index', { type: 'summary_tieout' })" class="lg-btn t-violet lg-tip" :data-tip="$t('See why these totals differ from the Excel SUMMARY sheet, line by line')"><icons name="table" class="w-4 h-4" /><span>{{ $t('Compare With Excel') }}</span></Link>
              <div class="sc-seg" role="tablist"><button v-for="c in curKeys" :key="c" type="button" :class="{ 'is-on': cur === c }" @click="cur = c">{{ c }}</button></div></header>
            <div v-if="x" class="sc-over">
              <overview-card variant="hero" icon="doc-text" :title="$t('Revised Value')" :sub="$t('Original') + ' ' + m(x.original, cur)" :value="m(x.revised, cur)" :delta="Number(x.variations_net) !== 0 ? (Number(x.variations_net) > 0 ? '+' : '') + m(x.variations_net, cur) : ''" :foot="$t('View Agreements')" :to="can.agreements ? route('subcontracts.agreements.index') : null" />
              <overview-card icon="cash" :title="$t('Paid')" :sub="$t('Vouchers') + ' ' + m(x.paid_live, cur) + ' + ' + $t('historical') + ' ' + m(x.paid_legacy, cur)" :value="m(x.paid_total, cur)" :delta="paidPct + '%'" :foot="$t('View Payments')" :to="can.reports ? route('subcontracts.reports.index', { type: 'payments' }) : null"><template #side><progress-ring :value="paidPct" :size="52" /></template></overview-card>
              <overview-card variant="rose" icon="clock" :title="$t('Outstanding')" :sub="$t('Approved, not yet paid') + ' ' + m(x.approved_unpaid, cur)" :value="m(x.commitment_left, cur)" :delta="Number(x.requested_pending) > 0 ? m(x.requested_pending, cur) + ' ' + $t('pending') : ''" delta-tone="flat" :foot="$t('View Commitments')" :to="can.reports ? route('subcontracts.reports.index', { type: 'commitments' }) : null" />
            </div>
          </section>
          <div v-else-if="!money" class="lg-note lg-note-blue"><icons name="info" class="w-4 h-4" /><span>{{ $t('Contract values and payments are shown only to people who may see financial figures.') }}</span></div>

          <section class="lg-card" v-if="queue.length">
            <header class="lg-card-head"><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Waiting for you') }}</h2></div></header>
            <div class="sc-q"><Link v-for="(c, i) in queue" :key="c.key" :href="c.to" class="sc-qc" :class="'q-' + (i + 1)"><b>{{ c.n }}</b><span>{{ c.label }}</span><small><icons :name="c.icon" class="w-4 h-4" />{{ c.hint }}</small></Link></div>
          </section>

          <section class="lg-card">
            <header class="lg-card-head"><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Quick access') }}</h2></div></header>
            <div class="sc-quick"><Link v-for="t in tiles" :key="t.key" :href="t.to" class="sc-qt" :class="'c-' + t.tone"><i><icons :name="t.icon" class="w-6 h-6" /></i>{{ t.label }}</Link></div>
          </section>

          <section v-if="archived && archived.count" class="lg-card sc-arch" role="link" tabindex="0" :title="$t('Open the archive')" @click="openArchive" @keydown.enter="openArchive">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-slate"><icons name="folder" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Archive') }}</h2><p class="lg-card-sub">{{ $t(':n archived accounts (older versions and finished accounts). Read-only; their balances are shown here and are not in the totals above.', { n: archived.count }) }}</p></div><Link :href="route('subcontracts.agreements.index', { status: 'Archived' })" class="lg-btn sc-sm sc-btn-maroon" @click.stop><icons name="folder" class="w-4 h-4" /><span>{{ $t('Open the archive') }}</span></Link></header>
            <div v-if="money && Object.keys(archived.figures).length" class="sc-wallets"><div v-for="(x, c) in archived.figures" :key="c" class="sc-wallet"><div class="sc-wallet-top"><span class="sc-flag">{{ c }}</span><b>{{ c }}</b></div><div><small>{{ $t('Balance of the archive') }}</small><div><b class="n sc-maroon">{{ m(x.commitment_left, c) }}</b></div></div><small>{{ $t('Revised') }} {{ m(x.revised, c) }} · {{ $t('Paid') }} {{ m(x.paid_total, c) }}</small></div></div>
          </section>

          <section v-if="money && curKeys.length" class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="cash" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Currencies') }}</h2></div></header>
            <div class="sc-wallets"><div v-for="c in curKeys" :key="c" class="sc-wallet" :class="{ 'is-link': can.agreements }" :role="can.agreements ? 'link' : null" :tabindex="can.agreements ? 0 : null" :title="can.agreements ? $t('View Agreements') : null" @click="openCurrency(c)" @keydown.enter="openCurrency(c)"><div class="sc-wallet-top"><span class="sc-flag">{{ c }}</span><b>{{ c }}</b><span class="ok" style="margin-left: auto">{{ $t('Active') }}</span></div>
              <div><small>{{ $t('Revised') }}</small><div><b class="n">{{ m(figures[c].revised, c) }}</b></div></div>
              <div class="sc-bar-track"><i :style="{ width: pct(figures[c]) + '%' }"></i></div><small>{{ $t('Paid') }} {{ m(figures[c].paid_total, c) }} · {{ pct(figures[c]) }}%</small></div></div>
          </section>

          <section v-if="money && months.length" class="lg-card">
            <header class="lg-card-head"><div class="min-w-0 flex-1"><small class="lg-subtext">{{ $t('Paid through vouchers (TZS)') }}</small><h2 class="lg-card-title" style="font-size: 1.5rem">{{ m(monthTotal, 'TZS') }}</h2></div><div class="sc-seg"><button type="button" class="is-on">{{ $t('Last 12 months') }}</button></div></header>
            <div class="sc-chart"><div v-for="b in months" :key="b.ym" class="sc-col" :class="{ 'is-top': b.top }" :style="{ '--h': b.h + '%' }"><div class="sc-tip"><small>{{ b.label }}</small><b>{{ m(b.s, 'TZS') }}</b></div><div class="sc-col-bar" :style="{ height: b.h + '%' }"></div><small>{{ b.short }}</small></div></div>
            <p class="lg-sub" style="margin-top: 6px">{{ $t('Historical payments have no payment date in the workbook totals and are not in this chart.') }}</p>
          </section>

          <section v-if="money && by_contact && by_contact.length" class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="users" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Largest outstanding commitments') }}</h2></div><Link :href="route('subcontracts.register.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('See all') }}</span></Link></header>
            <div v-if="by_contact.length > 6" class="sc-tfind"><search-bar v-model="tq" :placeholder="$t('Find a subcontractor...')" :count="contactRows.length" /></div>
            <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th><span class="sc-th"><icons name="users" class="w-4 h-4" />{{ $t('Subcontractor') }}</span></th><th class="ac-num"><span class="sc-th amt-rev"><icons name="doc-text" class="w-4 h-4" />{{ $t('Revised') }}</span></th><th class="ac-num"><span class="sc-th amt-paid"><icons name="cash" class="w-4 h-4" />{{ $t('Paid') }}</span></th><th class="ac-num"><span class="sc-th amt-out"><icons name="clock" class="w-4 h-4" />{{ $t('Outstanding') }}</span></th></tr></thead>
              <tbody><tr v-for="r in contactRows" :key="r.contact_id + r.currency" class="is-click" tabindex="0" @click="$inertia.visit(route('subcontracts.register.show', r.contact_id))" @keydown.enter="$inertia.visit(route('subcontracts.register.show', r.contact_id))"><td :data-label="$t('Subcontractor')"><span class="lg-main">{{ r.name }}</span></td><td :data-label="$t('Revised')" class="ac-num"><span class="amt-rev">{{ mc(r.revised, r.currency) }}</span></td><td :data-label="$t('Paid')" class="ac-num"><div class="sc-paidcell"><progress-ring :value="pct(r)" :size="40" :tone="pct(r) >= 100 ? 'blue' : ''" /><span class="amt-paid">{{ m(r.paid_total, r.currency) }}</span></div></td><td :data-label="$t('Outstanding')" class="ac-num"><b :class="neg(r.commitment_left) ? 'amt-neg' : 'amt-out'">{{ m(r.commitment_left, r.currency) }}</b></td></tr>
                <tr v-if="!contactRows.length" class="lg-empty-row"><td class="lg-empty" colspan="4">{{ $t('Nothing matches your search.') }}</td></tr></tbody></table></div>
          </section>

          <div class="ac-grid-2">
            <section v-if="money && by_project && by_project.length" class="lg-card">
              <header class="lg-card-head"><span class="lg-card-ico lg-ico-teal"><icons name="building" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('By project') }}</h2></div></header>
              <div v-if="by_project.length > 6" class="sc-tfind"><search-bar v-model="pq" :placeholder="$t('Find a project...')" :count="projectRows.length" /></div>
              <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th><span class="sc-th"><icons name="building" class="w-4 h-4" />{{ $t('Project(s) / Warehouse(s)') }}</span></th><th class="ac-num">{{ $t('Agreements') }}</th><th class="ac-num"><span class="sc-th amt-paid"><icons name="cash" class="w-4 h-4" />{{ $t('Paid') }}</span></th></tr></thead>
                <tbody><tr v-for="r in projectRows" :key="(r.warehouse_id || 0) + r.currency" :class="{ 'is-click': projectUrl(r) }" :tabindex="projectUrl(r) ? 0 : null" @click="openProject(r)" @keydown.enter="openProject(r)"><td :data-label="$t('Project(s) / Warehouse(s)')"><span class="lg-main">{{ r.name }}</span><div class="lg-subtext"><span class="amt-rev">{{ mc(r.revised, r.currency) }}</span></div></td><td :data-label="$t('Agreements')" class="ac-num"><span class="sc-pill pill-blue">{{ r.agreements }}</span></td><td :data-label="$t('Paid')" class="ac-num"><span class="amt-paid">{{ m(r.paid_total, r.currency) }}</span></td></tr>
                  <tr v-if="!projectRows.length" class="lg-empty-row"><td class="lg-empty" colspan="3">{{ $t('Nothing matches your search.') }}</td></tr></tbody></table></div>
            </section>
            <section v-if="money && recent_vouchers && recent_vouchers.length" class="lg-card">
              <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Recent Payment Vouchers') }}</h2></div></header>
              <div v-if="recent_vouchers.length > 6" class="sc-tfind"><search-bar v-model="vq" :placeholder="$t('Find a voucher...')" :count="voucherRows.length" /></div>
              <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th><span class="sc-th"><icons name="doc-text" class="w-4 h-4" />{{ $t('Voucher') }}</span></th><th>{{ $t('Status') }}</th><th class="ac-num"><span class="sc-th amt-paid"><icons name="cash" class="w-4 h-4" />{{ $t('TZS') }}</span></th></tr></thead>
                <tbody><tr v-for="v in voucherRows" :key="v.id" class="is-click" tabindex="0" @click="$inertia.visit(route('payment-vouchers.show', v.id))" @keydown.enter="$inertia.visit(route('payment-vouchers.show', v.id))"><td :data-label="$t('Voucher')"><Link :href="route('payment-vouchers.show', v.id)" class="lg-main" @click.stop>{{ v.pv }}</Link><div class="lg-subtext">{{ v.agreement }}</div></td><td :data-label="$t('Status')"><span class="lg-chip" :class="chip(v.status)">{{ v.status }}</span></td><td :data-label="$t('TZS')" class="ac-num"><span :class="v.status === 'Paid' ? 'amt-paid' : 'amt-appr'">{{ m(v.amount, 'TZS') }}</span></td></tr>
                  <tr v-if="!voucherRows.length" class="lg-empty-row"><td class="lg-empty" colspan="3">{{ $t('Nothing matches your search.') }}</td></tr></tbody></table></div>
            </section>
          </div>
        </div>

        <aside class="sc-rail">
          <section class="lg-card">
            <header class="lg-card-head"><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Dates to watch') }}</h2></div></header>
            <ul v-if="overdue.length || soon.length" class="sc-rem">
              <li v-for="a in overdue" :key="'o' + a.id"><i class="is-late"><icons name="clock" class="w-5 h-5" /></i><div><b><Link :href="route('subcontracts.agreements.show', a.id)">{{ a.reference }}</Link> {{ a.title }}</b><small>{{ $t('Overdue since') }} {{ $date(a.due) }}</small></div></li>
              <li v-for="a in soon" :key="'s' + a.id"><i><icons name="clock" class="w-5 h-5" /></i><div><b><Link :href="route('subcontracts.agreements.show', a.id)">{{ a.reference }}</Link> {{ a.title }}</b><small>{{ $t('Ends') }} {{ $date(a.due) }}</small></div></li>
            </ul>
            <p v-else class="lg-sub">{{ $t('No agreement ends soon.') }}</p>
          </section>
          <section v-if="recent.length" class="lg-card">
            <header class="lg-card-head"><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Recent activity') }}</h2></div></header>
            <ul class="sc-tl"><li v-for="(r, i) in recent" :key="i" :class="{ 'is-first': i === 0 }">{{ r.description }}<small>{{ r.who }} · {{ String(r.at).slice(0, 16) }}</small></li></ul>
          </section>
        </aside>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import OverviewCard from './OverviewCard.vue';
import ExportButtons from './ExportButtons.vue';
import ProgressRing from './ProgressRing.vue';
import SearchBar from './SearchBar.vue';
import { sc, tableFind } from './sc';

export default {
  components: { SearchBar, ExportButtons, AdminLayout, PageHeader, Link, DatePicker, AcctSelect, OverviewCard, ProgressRing },
  mixins: [sc, tableFind],
  props: { archived: Object, filters: Object, counts: Object, figures: Object, by_contact: Array, by_project: Array, by_month: Object, overdue: Array, soon: Array, recent_vouchers: Array, recent: Array, contacts: Array, projects: Array, statuses: Array, currencies: Array, money: Boolean, can: Object },
  data() { return { pq: '', vq: '', f: { ...this.filters }, q: '', showFilters: Object.values(this.filters || {}).some(v => v), cur: this.figures ? Object.keys(this.figures)[0] : '' }; },
  computed: {
    contactRows() { return (this.by_contact || []).filter(r => this.has(r.name)); },
    projectRows() { return (this.by_project || []).filter(r => this.hitq(this.pq, r.name, r.currency)); },
    voucherRows() { return (this.recent_vouchers || []).filter(v => this.hitq(this.vq, v.pv, v.agreement, v.status)); },
    curKeys() { return this.figures ? Object.keys(this.figures) : []; },
    x() { return this.figures && this.figures[this.cur] ? this.figures[this.cur] : null; },
    paidPct() { return this.x ? this.pct(this.x) : 0; },
    firstName() { const u = (this.$page.props.auth || {}).user; return u && u.name ? String(u.name).split(' ')[0] : ''; },
    today() { return new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }); },
    heroText() {
      const c = this.counts; const w = c.agreements_waiting + c.variations_waiting + c.requests_waiting;
      return w > 0 ? this.$t(':n item(s) wait for a review or a decision, and :a approved request(s) are not fully paid yet.', { n: w, a: c.requests_approved }) : this.$t('Nothing waits for a decision. :a approved request(s) are not fully paid yet.', { a: c.requests_approved });
    },
    queue() {
      const c = this.counts; const o = [];
      if (this.can.agreements) o.push({ key: 'ag', n: c.agreements_waiting, label: this.$t('Agreements Waiting'), hint: this.$t('Review Now'), icon: 'doc-text', to: route('subcontracts.agreements.index', { status: 'Submitted' }) });
      if (this.can.variations) o.push({ key: 'va', n: c.variations_waiting, label: this.$t('Variations Waiting'), hint: this.$t('Extra Works'), icon: 'adjustments', to: route('subcontracts.variations.index', { status: 'Submitted' }) });
      if (this.can.requests) { o.push({ key: 'rq', n: c.requests_waiting, label: this.$t('Requests Waiting'), hint: this.$t('Not Yet Paid'), icon: 'send', to: route('subcontracts.requests.index', { status: 'Submitted' }) }); o.push({ key: 'ap', n: c.requests_approved, label: this.$t('Approved Requests'), hint: this.$t('Start Voucher'), icon: 'tick', to: route('subcontracts.requests.index', { status: 'Approved' }) }); }
      return o;
    },
    tiles() {
      const t = [{ key: 'reg', label: this.$t('Register'), icon: 'users', tone: 'green', to: route('subcontracts.register.index') }];
      if (this.can.agreements) t.push({ key: 'ag', label: this.$t('Agreements'), icon: 'doc-text', tone: 'blue', to: route('subcontracts.agreements.index') });
      if (this.can.variations) t.push({ key: 'va', label: this.$t('Variations'), icon: 'adjustments', tone: 'violet', to: route('subcontracts.variations.index') });
      if (this.can.requests) t.push({ key: 'rq', label: this.$t('Requests'), icon: 'send', tone: 'sky', to: route('subcontracts.requests.index') });
      if (this.can.import) t.push({ key: 'im', label: this.$t('Excel Import'), icon: 'upload', tone: 'amber', to: route('subcontracts.import.index') });
      if (this.can.reconcile) t.push({ key: 'rc', label: this.$t('Reconcile'), icon: 'tick', tone: 'teal', to: route('subcontracts.reconciliation.index') });
      if (this.can.reports) t.push({ key: 'rp', label: this.$t('Reports'), icon: 'chart-bar', tone: 'rose', to: route('subcontracts.reports.index') });
      if (this.can.settings) t.push({ key: 'st', label: this.$t('Settings'), icon: 'cog', tone: 'slate', to: route('subcontracts.settings.edit') });
      return t;
    },
    months() {
      const e = Object.entries(this.by_month || {}); const max = Math.max(1, ...e.map(([, v]) => Number(v)));
      return e.map(([ym, s]) => { const d = new Date(ym + '-01T00:00:00'); return { ym, s, h: Math.max(6, (Number(s) / max) * 92), top: Number(s) === max, short: d.toLocaleDateString(undefined, { month: 'short' }), label: d.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }) }; });
    },
    monthTotal() { return Object.values(this.by_month || {}).reduce((a, v) => a + Number(v), 0).toFixed(2); },
  },
  methods: {
    hitq(q, ...vals) { const t = (q || '').trim().toLowerCase(); return !t || vals.some(v => v !== null && v !== undefined && String(v).toLowerCase().includes(t)); },
    projectUrl(r) { return this.can.agreements && r.warehouse_id ? route('subcontracts.agreements.index', { warehouse_id: r.warehouse_id, currency: r.currency }) : null; },
    openProject(r) { const u = this.projectUrl(r); if (u) this.$inertia.visit(u); },
    openCurrency(c) { if (this.can.agreements) this.$inertia.visit(route('subcontracts.agreements.index', { currency: c })); },
    openArchive() { this.$inertia.visit(route('subcontracts.agreements.index', { status: 'Archived' })); },
    pct(r) { const rev = Number(r.revised); return rev > 0 ? Math.round((Number(r.paid_total) / rev) * 100) : 0; },
    find() { this.$inertia.get(route('subcontracts.register.index'), this.q ? { search: this.q } : {}); },
    go() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); this.$inertia.get(route('subcontracts.dashboard'), q, { preserveState: true }); },
    reset() { this.f = {}; this.go(); },
  },
};
</script>

<style scoped>
.sc-filters .sc-act > div { display: flex; gap: 10px; }
@media (min-width: 1100px) { .sc-filters { grid-template-columns: repeat(6, minmax(0, 1fr)) minmax(190px, 1.1fr); } }
</style>

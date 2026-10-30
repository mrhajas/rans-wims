<template>
  <admin-layout :title="$t('RANS ID & Pass Management')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="qr-code" :title="$t('RANS ID & Pass Management')" :subtitle="$t('Staff IDs, compound worker IDs, visitor passes and gate passes in one place.')">
        <Link v-if="links.print_centre" :href="links.print_centre" class="lg-btn tone tone-solid" style="--tone: #4f46e5"><span class="tone-ico"><icons name="printer" class="w-4 h-4" /></span><span>{{ $t('Print Centre') }}</span></Link>
        <Link v-if="links.history" :href="links.history" class="lg-btn tone" style="--tone: #d97706"><span class="tone-ico"><icons name="clock" class="w-4 h-4" /></span><span>{{ $t('Print History') }}</span></Link>
        <Link v-if="links.reports" :href="links.reports" class="lg-btn tone" style="--tone: #7c3aed"><span class="tone-ico"><icons name="chart-bar" class="w-4 h-4" /></span><span>{{ $t('Reports') }}</span></Link>
        <Link v-if="links.alerts" :href="links.alerts" class="lg-btn tone" style="--tone: #e11d48"><span class="tone-ico"><icons name="bell" class="w-4 h-4" /></span><span>{{ $t('Alerts') }}</span></Link>
        <Link v-if="links.settings" :href="links.settings" class="lg-btn tone" style="--tone: #475569"><span class="tone-ico"><icons name="cog" class="w-4 h-4" /></span><span>{{ $t('Settings') }}</span></Link>
      </page-header>

      <div class="idd-tiles">
        <div v-for="t in tiles" :key="t.label" class="idd-tile" :style="{ '--c': t.color }"><b><animated-number :value="t.value" /></b><span>{{ t.label }}</span></div>
      </div>

      <div class="idd-kinds">
        <article v-for="c in cards" :key="c.kind" class="idd-kind" :style="{ '--tone': c.tone }">
          <header><span class="idd-ico"><icons :name="c.icon" class="w-6 h-6" /></span><div><h2>{{ c.label }}</h2><small>{{ c.counts.total }} {{ $t('in all') }}</small></div></header>
          <div class="idd-nums">
            <div><b>{{ c.counts.active }}</b><span>{{ $t('Active') }}</span></div>
            <div><b>{{ c.counts.expired }}</b><span>{{ $t('Expired') }}</span></div>
            <div><b>{{ c.counts.pending }}</b><span>{{ $t('Waiting') }}</span></div>
            <div><b>{{ c.counts.suspended + c.counts.revoked }}</b><span>{{ $t('Suspended / revoked') }}</span></div>
          </div>
          <footer><Link :href="c.url" class="lg-btn tone" style="--tone: #2563eb"><span class="tone-ico"><icons name="list" class="w-4 h-4" /></span>{{ $t('Open') }}</Link><Link v-if="c.create_url" :href="c.create_url" class="lg-btn tone tone-solid" style="--tone: #2563eb"><span class="tone-ico"><icons name="plus" class="w-4 h-4" /></span>{{ $t('Issue') }}</Link></footer>
        </article>
      </div>

      <div class="idd-two">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="plus" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Recently issued') }}</h2></div></header>
          <ul class="idd-list"><li v-for="r in recent" :key="r.id"><Link :href="r.url"><category-chip :name="r.number" :color="r.color" :ink="r.ink" :icon="r.icon" /><span>{{ r.name }}</span><em class="lg-chip" :class="statusChip(r.status)">{{ $t(r.status) }}</em></Link></li><li v-if="!recent.length" class="idd-none">{{ $t('Nothing issued yet.') }}</li></ul>
        </section>
        <section v-if="links.history" class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="printer" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Recent print activity') }}</h2></div></header>
          <ul class="idd-list"><li v-for="p in prints" :key="p.id"><div class="idd-row"><b>{{ p.number }}</b><span>{{ p.name }}</span><small>{{ actionText(p.action) }} · {{ p.by }} · {{ $datetime(p.at) }}</small></div></li><li v-if="!prints.length" class="idd-none">{{ $t('Nothing printed yet.') }}</li></ul>
        </section>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AnimatedNumber from '@/Shared/AnimatedNumber.vue';
import { statusChip } from './Parts/status';
import CategoryChip from './Parts/CategoryChip.vue';

export default {
  components: { AdminLayout, PageHeader, Link, AnimatedNumber, CategoryChip },
  props: { cards: Array, totals: Object, soon: Number, noticeDays: Number, recent: Array, prints: Array, links: Object },
  computed: {
    tiles() {
      const t = this.totals;
      return [{ label: this.$t('Active'), value: t.active, color: '#15803d' }, { label: this.$t('Expiring in :n days', { n: this.noticeDays || 30 }), value: this.soon, color: '#b45309' }, { label: this.$t('Expired'), value: t.expired, color: '#c2410c' },
        { label: this.$t('Waiting for approval'), value: t.pending, color: '#7c3aed' }, { label: this.$t('Suspended / revoked'), value: t.suspended + t.revoked, color: '#be123c' }, { label: this.$t('Total'), value: t.total, color: '#1e4467' }];
    },
  },
  methods: { statusChip, actionText(a) { return { print: this.$t('Print'), pdf: this.$t('PDF download'), batch_print: this.$t('Batch print'), batch_pdf: this.$t('Batch PDF export') }[a] || a; } },
};
</script>

<style scoped>
.idd-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(128px, 100%), 1fr)); gap: 12px; margin-bottom: 18px; }
.idd-tile { padding: 16px 18px; border-radius: 20px; background: var(--lg-card, #fff); box-shadow: 0 14px 26px -20px rgba(15, 37, 64, 0.55), 0 0 0 1px var(--lg-line, #e2e8f0); border-bottom: 3px solid var(--c); transition: transform 0.2s; }
.idd-tile:hover { transform: translateY(-2px); } .idd-tile b { display: block; font-size: 1.9rem; line-height: 1.1; color: var(--c); font-variant-numeric: tabular-nums; } .idd-tile > span { font-size: 0.74rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: var(--lg-mute, #64748b); }
.idd-kinds { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(250px, 100%), 1fr)); gap: 16px; margin-bottom: 18px; }
.idd-kind { border-radius: 24px; padding: 18px; background: var(--lg-card, #fff); box-shadow: 0 20px 34px -24px rgba(15, 37, 64, 0.6), 0 0 0 1px var(--lg-line, #e2e8f0); position: relative; overflow: hidden; transition: transform 0.25s, box-shadow 0.25s; }
.idd-kind::before { content: ''; position: absolute; inset: 0 0 auto 0; height: 6px; background: var(--tone); }
.idd-kind:hover { transform: translateY(-3px); box-shadow: 0 28px 40px -24px rgba(15, 37, 64, 0.6), 0 0 0 1px var(--tone); }
.idd-kind header { display: flex; gap: 12px; align-items: center; margin-bottom: 14px; } .idd-kind h2 { margin: 0; font-size: 1.05rem; } .idd-kind small { color: var(--lg-mute, #64748b); }
.idd-ico { width: 46px; height: 46px; border-radius: 16px; display: grid; place-items: center; color: #fff; background: var(--tone); box-shadow: 0 12px 20px -12px var(--tone); }
.idd-nums { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 14px; text-align: center; } .idd-nums b { display: block; font-size: 1.3rem; font-variant-numeric: tabular-nums; } .idd-nums span { font-size: 0.64rem; font-weight: 800; text-transform: uppercase; color: var(--lg-mute, #64748b); line-height: 1.2; display: block; }
.idd-kind footer { display: flex; gap: 8px; flex-wrap: wrap; }
.idd-two { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(min(320px, 100%), 1fr)); }
.idd-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 4px; grid-template-columns: minmax(0, 1fr); } .idd-list li { min-width: 0; } .idd-list a { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 12px; color: inherit; text-decoration: none; } .idd-list a:hover { background: rgba(21, 101, 192, 0.08); }
.idd-list b { letter-spacing: 0.04em; color: #1e4467; min-width: 64px; } .idd-list span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .idd-row { display: grid; padding: 6px 10px; } .idd-row small { color: var(--lg-mute, #64748b); }
.idd-list a > .idc { flex: none; min-width: 84px; }
.idd-none { padding: 14px; color: var(--lg-mute, #64748b); }
</style>

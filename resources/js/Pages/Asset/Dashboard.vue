<template>
  <admin-layout :title="$t('Asset Dashboard')">
    <div class="lg-page ad-page px-4 md:px-0">
      <page-header icon="chart-pie" :title="$t('Asset Dashboard')" :subtitle="$t('Company-wide asset overview.')">
        <span class="lg-chip lg-chip-green ad-live"><span class="ad-live-dot"></span>{{ $t('LIVE') }}</span>
        <a v-if="$can('print-assets')" :href="route('assets.dashboard.print')" target="_blank" class="lg-btn lg-btn-green"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
        <a v-if="$can('print-assets')" :href="route('assets.dashboard.pdf')" class="lg-btn lg-btn-dark"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</a>
        <Link :href="route('assets.index')" class="lg-btn lg-btn-primary"><icons name="list" class="w-4 h-4" />{{ $t('View Register') }}</Link>
      </page-header>

      <section class="lg-stats">
        <Link :href="route('assets.index')" class="lg-stat">
          <span class="lg-stat-ico lg-ico-navy"><icons name="chip" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value"><animated-number :value="cards.total_assets" use-grouping /></div><div class="lg-stat-label">{{ $t('Total Assets') }}</div></div>
        </Link>
        <Link :href="route('assets.index', { status: 'Active' })" class="lg-stat">
          <span class="lg-stat-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value"><animated-number :value="cards.active_assets" use-grouping /></div><div class="lg-stat-label">{{ $t('Active') }}</div></div>
        </Link>
        <Link :href="route('assets.index', { status: 'Assigned' })" class="lg-stat">
          <span class="lg-stat-ico lg-ico-sky"><icons name="users" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value"><animated-number :value="cards.assigned_assets" use-grouping /></div><div class="lg-stat-label">{{ $t('Assigned') }}</div></div>
        </Link>
        <Link :href="route('assets.index', { status: 'Available' })" class="lg-stat">
          <span class="lg-stat-ico lg-ico-teal"><icons name="collection" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value"><animated-number :value="cards.available_assets" use-grouping /></div><div class="lg-stat-label">{{ $t('Available') }}</div></div>
        </Link>
        <Link :href="route('assets.index', { status: 'Under Maintenance' })" class="lg-stat">
          <span class="lg-stat-ico lg-ico-amber"><icons name="cog" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value"><animated-number :value="cards.under_maintenance" use-grouping /></div><div class="lg-stat-label">{{ $t('Under Maintenance') }}</div></div>
        </Link>
        <Link :href="route('assets.index', { status: 'Lost' })" class="lg-stat">
          <span class="lg-stat-ico lg-ico-rose"><icons name="exclamation" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value"><animated-number :value="cards.lost_stolen" use-grouping /></div><div class="lg-stat-label">{{ $t('Lost / Stolen') }}</div></div>
        </Link>
        <Link :href="route('assets.index', { include_disposed: 1, status: 'Disposed' })" class="lg-stat">
          <span class="lg-stat-ico lg-ico-slate"><icons name="trash" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value"><animated-number :value="cards.disposed" use-grouping /></div><div class="lg-stat-label">{{ $t('Disposed') }}</div></div>
        </Link>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="building" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Asset Classes') }}</h2></div>
        </header>
        <div class="lg-stats ad-class-grid">
          <Link v-for="c in classCards" :key="c.key" :href="route('assets.index', c.query)" class="lg-stat ad-class">
            <div class="ad-class-top">
              <span class="lg-stat-ico" :class="'lg-ico-' + c.tone"><icons :name="c.icon" class="w-5 h-5" /></span>
              <span class="ad-class-count"><animated-number :value="c.count" use-grouping /> <small>{{ $t('Asset(s)') }}</small></span>
            </div>
            <div class="ad-class-type">{{ c.label }}</div>
            <div v-if="!isSiteEngineer && c.value !== null" class="ad-class-value" :title="$money(c.value)">{{ plainMoney(c.value) }}</div>
          </Link>
        </div>
      </section>

      <section v-if="!isSiteEngineer" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Asset Value') }}</h2></div>
        </header>
        <div class="ad-values">
          <div class="ad-value">
            <div class="lg-stat-label">{{ $t('Total Acquisition Value') }}</div>
            <div class="ad-amount">{{ $money(cards.total_acquisition_value) }}</div>
          </div>
          <div class="ad-value">
            <div class="lg-stat-label">{{ $t('Current Book Value') }}</div>
            <div class="ad-amount is-good">{{ $money(cards.current_book_value) }}</div>
          </div>
          <div class="ad-value">
            <div class="lg-stat-label">{{ $t('Accumulated Depreciation') }}</div>
            <div class="ad-amount is-warn">{{ $money(cards.accumulated_depreciation) }}</div>
          </div>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AnimatedNumber from '@/Shared/AnimatedNumber.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, AnimatedNumber, PageHeader },
  props: { cards: Object, isSiteEngineer: Boolean },

  methods: {
    // Class tiles use the short currency code (TZS) so the amount stays compact on one line.
    plainMoney(v) {
      return 'TZS ' + String(this.$money(v)).replace(/^[^\d-]+/, '');
    },
  },

  computed: {
    classCards() {
      const c = this.cards;
      const card = (key, label, icon, tone, row, query) => ({ key, label, icon, tone, count: row.count, value: row.value, query });

      return [
        card('fixed', this.$t('Fixed Assets'), 'building', 'indigo', c.fixed, { asset_type: 'Fixed Asset' }),
        card('unfixed', this.$t('UnFixed Assets'), 'truck', 'teal', c.unfixed, { asset_type: 'UnFixed Asset' }),
        card('tools_equipment', this.$t('Tools & Equipments'), 'adjustments', 'violet', c.tools_equipment, {}),
        card('land', this.$t('Land'), 'map-pin', 'emerald', c.land, {}),
        card('buildings', this.$t('Buildings'), 'home', 'sky', c.buildings, {}),
        card('vehicles', this.$t('Vehicles'), 'car', 'rose', c.vehicles, {}),
      ];
    },
  },

  mounted() {
    // Keeps the counters honestly "live" — a background partial reload
    // re-fetches just `cards` on an interval; AnimatedNumber re-triggers
    // its count-up/down automatically whenever the value actually changes.
    this.pollTimer = setInterval(() => {
      this.$inertia.reload({ only: ['cards'], preserveScroll: true });
    }, 30000);
  },

  beforeUnmount() {
    clearInterval(this.pollTimer);
  },
};
</script>

<style scoped>
.ad-live { text-transform: uppercase; letter-spacing: 0.04em; }
.ad-live-dot { width: 0.45rem; height: 0.45rem; border-radius: 9999px; background: #10b981; animation: ad-live-pulse 1.6s ease-in-out infinite; }
@keyframes ad-live-pulse { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.4; transform: scale(0.75); } }
/* Entry animation: every tile and card rises in, one after another. */
@keyframes ad-rise { from { opacity: 0; transform: translateY(14px) scale(0.96); } to { opacity: 1; transform: none; } }
.ad-page :deep(.lg-stat), .ad-page :deep(.lg-card) { animation: ad-rise 0.5s cubic-bezier(0.2, 0.8, 0.2, 1) both; animation-delay: calc(var(--i, 0) * 60ms); }
.ad-page :deep(.lg-stats > :nth-child(1)) { --i: 1; } .ad-page :deep(.lg-stats > :nth-child(2)) { --i: 2; } .ad-page :deep(.lg-stats > :nth-child(3)) { --i: 3; }
.ad-page :deep(.lg-stats > :nth-child(4)) { --i: 4; } .ad-page :deep(.lg-stats > :nth-child(5)) { --i: 5; } .ad-page :deep(.lg-stats > :nth-child(6)) { --i: 6; }
.ad-page :deep(.lg-stats > :nth-child(7)) { --i: 7; }
.ad-class-grid :deep(.lg-stat) { animation-delay: calc(var(--i, 0) * 60ms + 450ms); }
.ad-page > .lg-card:nth-of-type(2) { --i: 8; }
.ad-page > .lg-card:nth-of-type(3) { --i: 14; }
.ad-values .ad-value { animation: ad-rise 0.5s cubic-bezier(0.2, 0.8, 0.2, 1) both; }
.ad-values .ad-value:nth-child(1) { animation-delay: 1050ms; } .ad-values .ad-value:nth-child(2) { animation-delay: 1110ms; } .ad-values .ad-value:nth-child(3) { animation-delay: 1170ms; }
@media (prefers-reduced-motion: reduce) {
  .ad-page :deep(.lg-stat), .ad-page :deep(.lg-card), .ad-values .ad-value { animation: none; }
}

/* Asset Classes: all six cards stay on ONE line, squeezed as the screen narrows (wrapping only on phones). */
.ad-class-grid { margin-top: 0.25rem; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 8px; }
.ad-class-grid :deep(.lg-stat) { flex-direction: column; align-items: flex-start; padding: 10px 9px; gap: 7px; border-radius: 14px; }
.ad-class-top { display: flex; align-items: center; flex-wrap: wrap; gap: 6px 7px; width: 100%; min-width: 0; }
.ad-class-grid :deep(.lg-stat-ico) { width: 32px; height: 32px; border-radius: 10px; }
.ad-class-grid :deep(.lg-stat-ico svg) { width: 1rem; height: 1rem; }
.ad-class-count { font-size: 1.25rem; font-weight: 800; line-height: 1; color: var(--lg-ink); font-variant-numeric: tabular-nums; white-space: nowrap; }
.ad-class-count small { font-size: 0.62rem; font-weight: 800; color: var(--lg-faint); letter-spacing: 0.02em; }
.ad-class-type { width: 100%; font-size: clamp(0.66rem, 0.95vw, 0.82rem); font-weight: 800; line-height: 1.2; letter-spacing: 0.02em; text-transform: uppercase; color: var(--lg-ink); overflow-wrap: normal; word-break: normal; }
@media (max-width: 767.98px) { .ad-class-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 420px) { .ad-class-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
/* The amount: bold maroon, as large as the tile allows without breaking onto a second line. */
.ad-class-value { width: 100%; font-size: clamp(0.62rem, 0.95vw, 0.86rem); font-weight: 800; white-space: nowrap; color: #7f1d1d; font-variant-numeric: tabular-nums; }
html.dark .ad-class-value { color: #fda4af; }
.ad-values { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); gap: 12px; }
.ad-value { padding: 14px 16px; border-radius: 16px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.ad-amount { margin-top: 6px; font-size: 1.4rem; font-weight: 800; color: var(--lg-ink); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.ad-amount.is-good { color: #047857; }
.ad-amount.is-warn { color: #b45309; }
html.dark .ad-amount.is-good { color: #6ee7b7; }
html.dark .ad-amount.is-warn { color: #fcd34d; }
</style>

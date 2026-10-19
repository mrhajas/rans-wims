<template>
  <admin-layout :title="$t('Compliance Dashboard')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="chart-pie" :title="$t('Compliance Dashboard')" :subtitle="$t('Company-wide compliance overview.')">
        <span class="lg-chip lg-chip-green cp-live"><span class="cp-live-dot"></span>{{ $t('LIVE') }}</span>
        <a v-if="$can('read-compliance-records')" :href="route('compliance-records.print-list')" target="_blank" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" /> {{ $t('Print') }}</a>
        <a v-if="$can('read-compliance-records')" :href="route('compliance-records.pdf-list')" class="lg-btn lg-btn-dark"><icons name="download" class="w-4 h-4" /> {{ $t('Download') }}</a>
        <Link :href="route('compliance-records.index')" class="lg-btn lg-btn-primary">
          <icons name="list" class="w-4 h-4" /> {{ $t('View Register') }}
        </Link>
      </page-header>

      <section class="lg-stats">
        <Link :href="route('compliance-records.index')" class="lg-stat is-clickable">
          <span class="lg-stat-ico lg-ico-slate"><icons name="clip" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value"><animated-number :value="cards.total_items" use-grouping /></div><div class="lg-stat-label">{{ $t('Total Items') }}</div></div>
        </Link>
        <Link :href="route('compliance-records.index', { status: 'Active' })" class="lg-stat is-clickable">
          <span class="lg-stat-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value"><animated-number :value="cards.active" use-grouping /></div><div class="lg-stat-label">{{ $t('Active') }}</div></div>
        </Link>
        <Link :href="route('compliance-records.index', { expiry_to: dateAdd(90) })" class="lg-stat is-clickable">
          <span class="lg-stat-ico lg-ico-amber"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value"><animated-number :value="cards.due_within_90" use-grouping /></div><div class="lg-stat-label">{{ $t('Due Within 90 Days') }}</div></div>
        </Link>
        <Link :href="route('compliance-records.index', { expiry_to: dateAdd(30) })" class="lg-stat is-clickable">
          <span class="lg-stat-ico lg-ico-amber"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value"><animated-number :value="cards.due_within_30" use-grouping /></div><div class="lg-stat-label">{{ $t('Due Within 30 Days') }}</div></div>
        </Link>
        <Link :href="route('compliance-records.index', { expiry_to: dateAdd(7) })" class="lg-stat is-clickable">
          <span class="lg-stat-ico lg-ico-rose"><icons name="exclamation" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value"><animated-number :value="cards.due_within_7" use-grouping /></div><div class="lg-stat-label">{{ $t('Due Within 7 Days') }}</div></div>
        </Link>
        <Link :href="route('compliance-records.index', { expired: 1 })" class="lg-stat is-clickable">
          <span class="lg-stat-ico lg-ico-rose"><icons name="cross" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value"><animated-number :value="cards.expired" use-grouping /></div><div class="lg-stat-label">{{ $t('Expired') }}</div></div>
        </Link>
        <Link :href="route('compliance-records.index', { status: 'Renewal in Progress' })" class="lg-stat is-clickable">
          <span class="lg-stat-ico lg-ico-sky"><icons name="refresh" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value"><animated-number :value="cards.renewal_in_progress" use-grouping /></div><div class="lg-stat-label">{{ $t('Renewal in Progress') }}</div></div>
        </Link>
        <Link :href="route('compliance-records.index', { status: 'Pending Approval' })" class="lg-stat is-clickable">
          <span class="lg-stat-ico lg-ico-indigo"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value"><animated-number :value="cards.pending_approval" use-grouping /></div><div class="lg-stat-label">{{ $t('Pending Approval') }}</div></div>
        </Link>
        <div class="lg-stat">
          <span class="lg-stat-ico lg-ico-teal"><icons name="tick" class="w-5 h-5" /></span>
          <div class="min-w-0"><div class="lg-stat-value"><animated-number :value="cards.completed_this_month" use-grouping /></div><div class="lg-stat-label">{{ $t('Completed This Month') }}</div></div>
        </div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="chart-bar" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Expiry Breakdown') }}</h2></div>
        </header>
        <div class="cp-buckets">
          <button type="button" v-for="bucket in bucketList" :key="bucket.key" class="cp-bucket-row" @click="goToBucket(bucket)">
            <span class="cp-bucket-label">{{ bucket.label }}</span>
            <span class="cp-bucket-bar-wrap">
              <span class="cp-bucket-bar" :class="bucket.colorClass" :style="{ width: barWidth(bucket.value) }"></span>
            </span>
            <span class="cp-bucket-value">{{ bucket.value }}</span>
          </button>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AnimatedNumber from '@/Shared/AnimatedNumber.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import { Link } from '@inertiajs/vue3';

export default {
  components: { AdminLayout, AnimatedNumber, PageHeader, Link },
  props: { cards: Object, expiryBuckets: Object },

  computed: {
    bucketList() {
      return [
        { key: 'expired', label: this.$t('Expired'), value: this.expiryBuckets.expired, colorClass: 'cp-bar-rose', filter: { expired: 1 } },
        { key: 'due_0_7', label: this.$t('0–7 Days'), value: this.expiryBuckets.due_0_7, colorClass: 'cp-bar-rose', filter: { expiry_to: this.dateAdd(7) } },
        { key: 'due_8_30', label: this.$t('8–30 Days'), value: this.expiryBuckets.due_8_30, colorClass: 'cp-bar-amber', filter: { expiry_from: this.dateAdd(8), expiry_to: this.dateAdd(30) } },
        { key: 'due_31_60', label: this.$t('31–60 Days'), value: this.expiryBuckets.due_31_60, colorClass: 'cp-bar-blue', filter: { expiry_from: this.dateAdd(31), expiry_to: this.dateAdd(60) } },
        { key: 'due_61_90', label: this.$t('61–90 Days'), value: this.expiryBuckets.due_61_90, colorClass: 'cp-bar-emerald', filter: { expiry_from: this.dateAdd(61), expiry_to: this.dateAdd(90) } },
      ];
    },
    maxBucketValue() {
      return Math.max(1, ...this.bucketList.map(b => b.value));
    },
  },

  methods: {
    barWidth(value) {
      return `${Math.max(4, (value / this.maxBucketValue) * 100)}%`;
    },
    dateAdd(days) {
      const d = new Date();
      d.setDate(d.getDate() + days);
      return this.$formatJSDate(d);
    },
    goToBucket(bucket) {
      this.$inertia.visit(this.route('compliance-records.index', bucket.filter));
    },
  },

  mounted() {
    // Keeps the counters and expiry-bucket bars honestly "live" — a
    // background partial reload re-fetches just these two props on an
    // interval; AnimatedNumber and the bar-width transition both animate
    // automatically whenever the underlying value actually changes.
    this.pollTimer = setInterval(() => {
      this.$inertia.reload({ only: ['cards', 'expiryBuckets'], preserveScroll: true });
    }, 30000);
  },

  beforeUnmount() {
    clearInterval(this.pollTimer);
  },
};
</script>

<style scoped>
.cp-live { text-transform: none; letter-spacing: 0.04em; }
.cp-live-dot { width: 0.45rem; height: 0.45rem; border-radius: 9999px; background: #10b981; animation: cp-live-pulse 1.6s ease-in-out infinite; }
@keyframes cp-live-pulse { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.4; transform: scale(0.75); } }
@media (prefers-reduced-motion: reduce) { .cp-live-dot { animation: none; } }
.cp-buckets { display: flex; flex-direction: column; gap: 6px; }
.cp-bucket-row { display: grid; grid-template-columns: 96px 1fr 44px; align-items: center; gap: 0.85rem; width: 100%; text-align: left; padding: 8px 10px; border-radius: 14px; transition: background 0.15s ease; }
.cp-bucket-row:hover { background: var(--lg-soft); }
.cp-bucket-label { font-size: 0.78rem; color: var(--lg-mute); font-weight: 800; }
.cp-bucket-bar-wrap { height: 0.7rem; background: var(--lg-soft); border: 1px solid var(--lg-line); border-radius: 9999px; overflow: hidden; }
.cp-bucket-bar { display: block; height: 100%; border-radius: 9999px; transition: width 0.3s ease; }
.cp-bar-rose { background: linear-gradient(90deg, #fb7185, #be123c); }
.cp-bar-amber { background: linear-gradient(90deg, #fbbf24, #b45309); }
.cp-bar-blue { background: linear-gradient(90deg, #60a5fa, #1d4ed8); }
.cp-bar-emerald { background: linear-gradient(90deg, #34d399, #047857); }
.cp-bucket-value { font-size: 0.9rem; font-weight: 800; color: var(--lg-ink); text-align: right; font-variant-numeric: tabular-nums; }
@media (max-width: 480px) { .cp-bucket-row { grid-template-columns: 74px 1fr 34px; gap: 0.5rem; } }
</style>

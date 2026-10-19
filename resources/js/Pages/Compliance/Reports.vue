<template>
  <admin-layout :title="$t('Compliance Reports')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="chart-bar" :title="$t('Reports')" :subtitle="$t('Every compliance report, each with Print and Download.')">
        <Link :href="route('compliance-records.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" /> {{ $t('Compliance') }}</Link>
      </page-header>

      <div class="cp-reports">
        <Link v-for="(r, i) in reportLinks" :key="r.route" :href="route(r.route)" class="cp-report-card">
          <span class="cp-report-icon" :class="tones[i % tones.length]"><icons :name="r.icon" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <p class="cp-report-title">{{ r.label }}</p>
            <p class="cp-report-desc">{{ r.description }}</p>
          </div>
          <icons name="chevron-down" class="w-4 h-4 cp-report-go" />
        </Link>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import { Link } from '@inertiajs/vue3';

export default {
  components: { AdminLayout, PageHeader, Link },

  data() {
    return { tones: ['lg-ico-navy', 'lg-ico-amber', 'lg-ico-emerald'] };
  },

  computed: {
    reportLinks() {
      return [
        {
          route: 'compliance-records.index',
          label: this.$t('Compliance Register'),
          description: this.$t('Full list of compliance records: filterable, printable, downloadable.'),
          icon: 'table',
        },
        {
          route: 'compliance-records.calendar',
          label: this.$t('Compliance Calendar'),
          description: this.$t('Six-month calendar of expiries, renewals, and reviews.'),
          icon: 'clock',
        },
        {
          route: 'compliance-records.dashboard',
          label: this.$t('Compliance Dashboard'),
          description: this.$t('Live totals, expiry breakdown, and status at a glance.'),
          icon: 'clip',
        },
      ];
    },
  },
};
</script>

<style scoped>
.cp-reports { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(min(280px, 100%), 1fr)); }
.cp-report-card { display: flex; align-items: center; gap: 0.9rem; padding: 1.1rem 1.2rem; border-radius: 22px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); transition: transform 0.15s ease, border-color 0.15s ease; }
.cp-report-card:hover { transform: translateY(-2px); border-color: #93c5fd; }
.cp-report-icon { display: inline-flex; align-items: center; justify-content: center; width: 2.8rem; height: 2.8rem; border-radius: 15px; flex-shrink: 0; color: #fff; }
.cp-report-title { font-size: 0.92rem; font-weight: 800; line-height: 1.3; color: var(--lg-ink); }
.cp-report-desc { margin-top: 3px; font-size: 0.76rem; color: var(--lg-mute); }
.cp-report-go { flex: none; color: var(--lg-faint); transform: rotate(-90deg); transition: transform 0.15s ease; }
.cp-report-card:hover .cp-report-go { transform: rotate(-90deg) translateY(3px); color: var(--lg-navy); }
</style>

<template>
  <admin-layout :title="$t('Asset Reports')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="chart-bar" :title="$t('Reports')" :subtitle="$t('Every asset report, each with Print and Download.')">
        <Link :href="route('assets.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Assets') }}</Link>
      </page-header>

      <div class="ar-grid">
        <Link v-for="(r, i) in reportLinks" :key="r.route" :href="route(r.route)" class="lg-stat ar-card">
          <span class="lg-card-ico" :class="tones[i % tones.length]"><icons :name="r.icon" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <p class="lg-card-title">{{ r.label }}</p>
            <p class="lg-card-sub">{{ r.description }}</p>
          </div>
        </Link>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, PageHeader },

  data() {
    return { tones: ['lg-ico-navy', 'lg-ico-sky', 'lg-ico-violet', 'lg-ico-emerald'] };
  },

  computed: {
    reportLinks() {
      return [
        {
          route: 'assets.index',
          label: this.$t('Asset Register'),
          description: this.$t('Full list of company assets: filterable, printable, downloadable.'),
          icon: 'table',
        },
        {
          route: 'assets.movement-report',
          label: this.$t('Movement Report'),
          description: this.$t('Check-out, check-in, and transfer history across all assets.'),
          icon: 'truck',
        },
        {
          route: 'assets.depreciation-report',
          label: this.$t('Depreciation Report'),
          description: this.$t('Capitalised cost, accumulated depreciation, and current book value.'),
          icon: 'chart-pie',
        },
        {
          route: 'assets.dashboard',
          label: this.$t('Asset Dashboard'),
          description: this.$t('Live totals, status breakdown, and expiring warranties/insurance.'),
          icon: 'chip',
        },
      ];
    },
  },
};
</script>

<style scoped>
.ar-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(280px, 100%), 1fr)); gap: 12px; }
.ar-card { align-items: flex-start; padding: 16px 18px; }
</style>

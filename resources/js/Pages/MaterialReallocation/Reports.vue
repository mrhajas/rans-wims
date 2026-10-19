<template>
  <admin-layout :title="$t('Material Reallocation Reports')">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="chart-pie"
        :title="$t('Material Reallocation Reports')"
        :subtitle="$t('Reports covering the material reallocation workflow, from request to final approval')"
      >
        <Link :href="route('material-reallocations.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Material Reallocation') }}
        </Link>
      </page-header>

      <div class="rc-grid">
        <Link v-for="report in reportLinks" :key="report.route" :href="route(report.route)" class="lg-card rc-report-card group">
          <span class="lg-card-ico" :class="'lg-ico-' + report.tone"><icons :name="report.icon" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <p class="rc-report-title">{{ report.label }}</p>
            <p class="rc-report-desc">{{ report.description }}</p>
          </div>
          <icons name="chevron-down" class="w-4 h-4 rc-report-go transform -rotate-90" />
        </Link>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: {
    AdminLayout,
    PageHeader,
  },

  computed: {
    reportLinks() {
      return [
        {
          route: 'reports.material_reallocation_summary',
          label: this.$t('Material Reallocation Summary'),
          description: this.$t('Header-level summary of all material reallocations'),
          icon: 'toggle',
          tone: 'indigo',
        },
        {
          route: 'reports.material_movement',
          label: this.$t('Material Movement Report'),
          description: this.$t('Line-item detail of what moved from source to destination'),
          icon: 'collection',
          tone: 'sky',
        },
        {
          route: 'reports.driver_dispatch',
          label: this.$t('Driver Dispatch Report'),
          description: this.$t('Dispatched shipments by driver and vehicle'),
          icon: 'truck',
          tone: 'amber',
        },
        {
          route: 'reports.warehouse_receiving',
          label: this.$t('Warehouse Receiving Report'),
          description: this.$t('Items received at destination warehouses'),
          icon: 'doc-text',
          tone: 'teal',
        },
        {
          route: 'reports.discrepancy',
          label: this.$t('Discrepancy Report'),
          description: this.$t('Received vs allocated quantity mismatches'),
          icon: 'chart-bar',
          tone: 'rose',
        },
      ];
    },
  },
};
</script>

<style scoped>
.rc-grid {
  display: grid;
  gap: 14px;
  grid-template-columns: repeat(auto-fill, minmax(min(280px, 100%), 1fr));
}
.rc-report-card {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 0;
  padding: 1rem 1.1rem;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.rc-report-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 18px 34px -18px rgba(15, 23, 42, 0.35);
}
.rc-report-title {
  font-weight: 800;
  line-height: 1.3;
  color: var(--lg-ink);
}
.rc-report-desc {
  margin-top: 2px;
  font-size: 0.76rem;
  line-height: 1.45;
  color: var(--lg-mute);
}
.rc-report-go {
  flex: none;
  color: var(--lg-faint);
  transition: transform 0.15s ease, color 0.15s ease;
}
.rc-report-card:hover .rc-report-go {
  color: var(--lg-navy);
  transform: translateX(3px) rotate(-90deg);
}
html.dark .rc-report-card:hover .rc-report-go {
  color: #93c5fd;
}
</style>

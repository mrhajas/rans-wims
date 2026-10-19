<template>
  <admin-layout :title="$t('Projects')">
    <div class="lg-page px-4 md:px-0 pe-projects">
      <page-header icon="folder" :title="$t('Projects')" :subtitle="$t('Every active construction project and its execution health')">
        <Link :href="route('project-execution.dashboard')" class="lg-btn lg-btn-soft">
          <icons name="chart-bar" class="w-4 h-4" />
          <span>{{ $t('Project Execution Management') }}</span>
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" />

      <div class="pe-grid">
        <section v-for="project in projects" :key="project.id" class="lg-card pe-card">
          <header class="lg-card-head">
            <span class="lg-card-ico" :class="'lg-ico-' + healthIconColor(project.health)"><icons name="folder" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <Link :href="route('reports.warehousestock', { warehouseId: project.id })" class="lg-card-title truncate block hover:text-blue-600">{{ project.name }}</Link>
              <p class="lg-card-sub truncate">{{ project.code }} · {{ project.stage || $t('No stage set') }}</p>
            </div>
            <span class="lg-chip" :class="healthChip(project.health)" style="text-transform: none">{{ project.health_label }}</span>
          </header>

          <div class="flex items-center justify-between mb-2 gap-2">
            <span class="pe-k">
              {{ $t('Overall Progress') }}
              <span
                v-if="project.progress_is_baseline"
                class="pe-faint normal-case lg-tip tip-left"
                :data-tip="$t('No work packages tracked yet. Showing the manual baseline set on the project.')"
                >({{ $t('baseline') }})</span
              >
            </span>
            <strong class="pe-pct">{{ project.overall_progress }}%</strong>
          </div>
          <div class="pe-bar">
            <div :class="'health-bar-' + project.health" :style="{ width: Math.min(100, Math.max(0, project.overall_progress)) + '%' }"></div>
          </div>

          <div class="pe-facts">
            <div>
              <p class="pe-k">{{ $t('Planned Finish') }}</p>
              <p class="pe-v">{{ project.planned_finish ? $date(project.planned_finish) : '-' }}</p>
            </div>
            <div>
              <p class="pe-k">{{ $t('Forecast Finish') }}</p>
              <p class="pe-v">{{ project.forecast_finish ? $date(project.forecast_finish) : '-' }}</p>
            </div>
            <div>
              <p class="pe-k">{{ $t('Delay') }}</p>
              <p class="pe-v" :class="project.delay_days > 0 ? 'pe-bad' : 'pe-good'">
                {{ project.delay_days > 0 ? project.delay_days + ' ' + $t('days') : $t('On time') }}
              </p>
            </div>
            <div>
              <p class="pe-k">{{ $t('Active Work Packages') }}</p>
              <p class="pe-v">{{ project.active_work_packages }} / {{ project.total_work_packages }}</p>
            </div>
            <div>
              <p class="pe-k">{{ $t('Open Issues') }}</p>
              <p class="pe-v" :class="{ 'pe-bad': project.open_issues > 0 }">{{ project.open_issues }}</p>
            </div>
            <div>
              <p class="pe-k">{{ $t('Material Risks') }}</p>
              <p class="pe-v" :class="{ 'pe-warn': project.material_risks > 0 }">{{ project.material_risks }}</p>
            </div>
            <div>
              <p class="pe-k">{{ $t('Labour Forecast') }}</p>
              <p class="pe-v">{{ project.labour_forecast }}</p>
            </div>
            <div>
              <p class="pe-k">{{ $t('Equipment Forecast') }}</p>
              <p class="pe-v">{{ project.equipment_forecast }}</p>
            </div>
          </div>

          <footer class="pe-foot">
            <p class="lg-subtext">{{ $t('Last Update') }}: {{ project.last_update ? $datetime(project.last_update) : $t('Never') }}</p>
            <Link :href="route('work-packages.index', { warehouse_id: project.id })" class="lg-btn lg-btn-primary">
              <span>{{ $t('View Work Packages') }}</span>
              <icons name="chevron-down" class="w-4 h-4 transform -rotate-90" />
            </Link>
          </footer>
        </section>

        <div v-if="!projects.length" class="lg-card lg-empty pe-empty">
          {{ $t('No projects found.') }}
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, PageHeader, StatTiles },

  props: {
    projects: Array,
  },

  computed: {
    // Derived from the full project list the server already returns (no pagination here).
    statTiles() {
      const list = this.projects || [];
      return [
        { icon: 'folder', tone: 'navy', label: this.$t('Active Projects'), value: list.length },
        { icon: 'tick', tone: 'emerald', label: this.$t('On Track'), value: list.filter(p => ['green', 'yellow'].includes(p.health)).length },
        { icon: 'exclamation', tone: 'rose', label: this.$t('Delayed Projects'), value: list.filter(p => ['orange', 'red'].includes(p.health)).length },
        { icon: 'clip', tone: 'violet', label: this.$t('Active Work Packages'), value: list.reduce((n, p) => n + (p.active_work_packages || 0), 0) },
        { icon: 'info', tone: 'amber', label: this.$t('Open Issues'), value: list.reduce((n, p) => n + (p.open_issues || 0), 0) },
      ];
    },
  },

  methods: {
    healthIconColor(health) {
      return { green: 'emerald', yellow: 'amber', orange: 'amber', red: 'rose' }[health] || 'slate';
    },
    healthChip(health) {
      return { green: 'lg-chip-green', yellow: 'lg-chip-amber', orange: 'lg-chip-amber', red: 'lg-chip-red' }[health] || 'lg-chip-slate';
    },
  },
};
</script>

<style scoped>
.pe-grid {
  display: grid;
  gap: 14px;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 360px), 1fr));
}
.pe-card {
  margin-bottom: 0;
  min-width: 0;
}
.pe-empty {
  grid-column: 1 / -1;
}
.pe-k {
  font-size: 0.64rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--lg-faint);
}
.pe-v {
  margin-top: 1px;
  font-size: 0.86rem;
  font-weight: 700;
  color: var(--lg-ink);
}
.pe-pct {
  font-size: 0.9rem;
  color: var(--lg-ink);
}
.pe-faint {
  color: var(--lg-faint);
  font-weight: 600;
}
.pe-good { color: #047857; }
.pe-bad { color: #be123c; }
.pe-warn { color: #b45309; }
html.dark .pe-good { color: #6ee7b7; }
html.dark .pe-bad { color: #fda4af; }
html.dark .pe-warn { color: #fcd34d; }
.pe-bar {
  width: 100%;
  height: 9px;
  margin-bottom: 16px;
  border-radius: 9999px;
  overflow: hidden;
  background: var(--lg-soft);
  border: 1px solid var(--lg-line);
}
.pe-bar > div {
  height: 100%;
  border-radius: 9999px;
}
.pe-facts {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.pe-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 16px;
  padding-top: 12px;
  border-top: 1px solid var(--lg-line);
}

.health-bar-green { background: #10b981; }
.health-bar-yellow { background: #f59e0b; }
.health-bar-orange { background: #f97316; }
.health-bar-red { background: #ef4444; }
.health-bar-slate { background: #94a3b8; }
</style>

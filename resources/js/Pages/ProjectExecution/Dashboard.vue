<template>
  <admin-layout :title="$t('Project Execution Management')">
    <div class="lg-page px-4 md:px-0 pe-dash">
      <page-header icon="chart-bar" :title="$t('Project Execution Management')" :subtitle="$t('Live execution status across every active project')">
        <span class="lg-chip lg-chip-green"><span class="pe-live-dot"></span>{{ $t('LIVE') }}</span>
        <Link :href="route('project-execution.projects')" class="lg-btn lg-btn-primary">
          <icons name="folder" class="w-4 h-4" />
          <span>{{ $t('View All Projects') }}</span>
        </Link>
      </page-header>

      <stat-tiles :items="statTiles" />

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="folder" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Projects') }}</h2>
            <p class="lg-card-sub">{{ $t('Progress') }} / {{ $t('Active WPs') }}</p>
          </div>
        </header>

        <div class="pe-grid">
          <Link
            v-for="project in projects"
            :key="project.id"
            :href="route('work-packages.index', { warehouse_id: project.id })"
            class="pe-project"
          >
            <div class="flex items-start justify-between gap-2 mb-3">
              <div class="min-w-0">
                <p class="lg-main truncate">{{ project.name }}</p>
                <p class="lg-subtext lg-mono">{{ project.code }}</p>
              </div>
              <span class="lg-chip" :class="healthChip(project.health)" style="text-transform: none">
                <span class="pe-dot" :class="'health-' + project.health"></span>{{ project.health_label }}
              </span>
            </div>

            <div class="pe-bar">
              <div :class="'health-bar-' + project.health" :style="{ width: Math.min(100, Math.max(0, project.overall_progress)) + '%' }"></div>
            </div>

            <div class="pe-project-foot">
              <span>
                {{ $t('Progress') }}: <strong>{{ project.overall_progress }}%</strong
                ><span v-if="project.progress_is_baseline" class="pe-faint"> ({{ $t('baseline') }})</span>
              </span>
              <span>{{ $t('Active WPs') }}: <strong>{{ project.active_work_packages }}</strong></span>
              <span v-if="project.delay_days > 0" class="pe-late">{{ project.delay_days }} {{ $t('days late') }}</span>
            </div>
          </Link>

          <div v-if="!projects.length" class="lg-empty pe-empty">
            {{ $t('No projects with work packages yet.') }}
          </div>
        </div>
      </section>
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
    projectCount: Number,
    activeWorkPackages: Number,
    delayedProjects: Number,
    onTrackProjects: Number,
    projects: Array,
  },

  computed: {
    statTiles() {
      return [
        { icon: 'folder', tone: 'navy', label: this.$t('Active Projects'), value: this.projectCount || 0 },
        { icon: 'clip', tone: 'violet', label: this.$t('Active Work Packages'), value: this.activeWorkPackages || 0 },
        { icon: 'tick', tone: 'emerald', label: this.$t('On Track'), value: this.onTrackProjects || 0 },
        { icon: 'exclamation', tone: 'rose', label: this.$t('Delayed Projects'), value: this.delayedProjects || 0 },
      ];
    },
  },

  methods: {
    healthChip(health) {
      return { green: 'lg-chip-green', yellow: 'lg-chip-amber', orange: 'lg-chip-amber', red: 'lg-chip-red' }[health] || 'lg-chip-slate';
    },
  },
};
</script>

<style scoped>
.pe-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 340px), 1fr));
}
.pe-project {
  display: block;
  min-width: 0;
  padding: 14px 16px;
  border-radius: 18px;
  background: var(--lg-soft);
  border: 1px solid var(--lg-line);
  transition: transform 0.15s ease, border-color 0.15s ease;
}
.pe-project:hover {
  transform: translateY(-2px);
  border-color: #93c5fd;
}
.pe-bar {
  width: 100%;
  height: 8px;
  margin-bottom: 10px;
  border-radius: 9999px;
  overflow: hidden;
  background: var(--lg-line);
}
.pe-bar > div {
  height: 100%;
  border-radius: 9999px;
}
.pe-project-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 4px 12px;
  font-size: 0.74rem;
  color: var(--lg-mute);
}
.pe-project-foot strong {
  color: var(--lg-ink);
}
.pe-faint {
  color: var(--lg-faint);
}
.pe-late {
  font-weight: 800;
  color: #be123c;
}
html.dark .pe-late {
  color: #fda4af;
}
.pe-empty {
  grid-column: 1 / -1;
  text-align: center;
}
.pe-dot {
  width: 8px;
  height: 8px;
  border-radius: 9999px;
  flex-shrink: 0;
}
.pe-live-dot {
  width: 7px;
  height: 7px;
  border-radius: 9999px;
  background: #10b981;
  animation: pe-pulse 2s ease-in-out infinite;
}
@keyframes pe-pulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.55); }
  50% { box-shadow: 0 0 0 5px rgba(16, 185, 129, 0); }
}
@media (prefers-reduced-motion: reduce) {
  .pe-live-dot { animation: none; }
}

.health-green { background: #10b981; }
.health-yellow { background: #f59e0b; }
.health-orange { background: #f97316; }
.health-red { background: #ef4444; }
.health-slate { background: #94a3b8; }

.health-bar-green { background: #10b981; }
.health-bar-yellow { background: #f59e0b; }
.health-bar-orange { background: #f97316; }
.health-bar-red { background: #ef4444; }
.health-bar-slate { background: #94a3b8; }
</style>

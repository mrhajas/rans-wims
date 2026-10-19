<template>
  <admin-layout :title="$t('Active Impersonations')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="impersonate" :title="$t('Active Impersonations')" :subtitle="$t('Every &quot;Login As User&quot; session currently in progress. Any Super Admin can end a stuck one.')">
        <Link :href="route('impersonation.history')" class="lg-btn lg-btn-soft">
          <icons name="clock" class="w-3.5 h-3.5" /> {{ $t('Impersonation History') }}
        </Link>
      </page-header>

      <stat-tiles :items="statItems" />

      <section v-if="!sessions.length" class="lg-card imp-empty">
        <span class="lg-card-ico lg-ico-slate" style="width: 56px; height: 56px; border-radius: 18px"><icons name="impersonate" class="w-7 h-7" /></span>
        <p>{{ $t('No active impersonation sessions right now.') }}</p>
      </section>

      <div v-else class="imp-list">
        <div v-for="s in sessions" :key="s.id" class="lg-card imp-card">
          <span class="imp-card-dot"></span>
          <span class="lg-avatar">{{ initials(s.original.name) }}</span>
          <div class="min-w-0 flex-1">
            <p class="lg-main">{{ s.original.name }}</p>
            <p class="imp-card-sub">{{ $t('Viewing as') }} <strong>{{ s.target.name }}</strong> <span v-if="s.mode === 'read_only'" class="lg-chip lg-chip-indigo ml-1">{{ $t('Read-Only') }}</span></p>
            <p class="lg-subtext">{{ $t('Started') }} {{ relativeTime(s.started_at) }} · {{ $t('Reason') }}: {{ s.reason }}</p>
          </div>
          <button v-if="$can('manage-impersonation')" type="button" class="lg-btn lg-btn-red flex-none" :disabled="ending === s.id" @click="endSession(s)">
            <icons name="cross" class="w-3.5 h-3.5" />
            {{ ending === s.id ? $t('Ending…') : $t('End Session') }}
          </button>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, Link, PageHeader, StatTiles },

  props: {
    sessions: Array,
  },

  data() {
    return {
      ending: null,
      poll: null,
    };
  },

  mounted() {
    this.poll = setInterval(() => {
      router.reload({ only: ['sessions'], preserveScroll: true, preserveState: true });
    }, 15000);
  },

  beforeUnmount() {
    clearInterval(this.poll);
  },

  computed: {
    statItems() {
      return [
        { label: this.$t('Active Impersonations'), value: this.sessions.length, icon: 'impersonate', tone: 'amber' },
        { label: this.$t('Read-Only'), value: this.sessions.filter(s => s.mode === 'read_only').length, icon: 'view', tone: 'indigo' },
      ];
    },
  },

  methods: {
    initials(name) {
      return String(name || '').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join('');
    },
    relativeTime(iso) {
      const diffMs = Date.now() - new Date(iso).getTime();
      const minutes = Math.max(0, Math.round(diffMs / 60000));
      if (minutes < 1) return this.$t('just now');
      if (minutes === 1) return this.$t('1 minute ago');
      return `${minutes} ${this.$t('minutes ago')}`;
    },
    endSession(s) {
      this.ending = s.id;
      this.$inertia.post(this.route('impersonation.active.terminate', s.id), {}, {
        preserveScroll: true,
        onFinish: () => {
          this.ending = null;
        },
      });
    },
  },
};
</script>

<style scoped>
.imp-empty { display: flex; flex-direction: column; align-items: center; gap: 0.75rem; padding: 3rem 1rem; border-style: dashed; color: var(--lg-faint); font-size: 0.85rem; }
.imp-list { display: grid; gap: 12px; }
.imp-card { display: flex; align-items: center; gap: 0.9rem; margin-bottom: 0; padding: 1rem 1.2rem; border-color: rgba(245, 158, 11, 0.35); }
@media (max-width: 639.98px) { .imp-card { flex-wrap: wrap; } }
.imp-card-dot { width: 0.6rem; height: 0.6rem; border-radius: 9999px; background: #f59e0b; flex: none; animation: imp-dot-pulse 1.6s ease-in-out infinite; }
@keyframes imp-dot-pulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.5); }
  50% { box-shadow: 0 0 0 6px rgba(245, 158, 11, 0); }
}
.imp-card-sub { font-size: 0.8rem; color: var(--lg-ink); margin-top: 0.1rem; }
</style>

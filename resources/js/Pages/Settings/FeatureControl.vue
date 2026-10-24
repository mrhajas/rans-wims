<template>
  <admin-layout :title="$t('Global Feature Control')">
    <div class="lg-page px-4 md:px-0 fc-page">
      <page-header
        icon="toggle"
        :title="$t('Global Feature Control')"
        :subtitle="$t('Control which WIMS functions are currently available to users.')"
      >
        <Link :href="route('settings')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Back to Settings') }}</Link>
      </page-header>

      <stat-tiles :items="tiles" />

      <section class="lg-card fc-recon">
        <div class="fc-recon-head">
          <div>
            <h3>{{ $t('Registration check') }}</h3>
            <p>{{ $t('Lists WIMS functions, menu entries and permissions that are not registered here, so nothing exists without a switch.') }}</p>
          </div>
          <button type="button" class="lg-btn lg-btn-soft" :disabled="recon.loading" @click="checkRegistration"><icons name="refresh" class="w-4 h-4" />{{ recon.loading ? $t('Checking...') : $t('Check now') }}</button>
        </div>
        <template v-if="recon.report">
          <p class="fc-recon-sum" :class="recon.report.problems ? 'is-bad' : 'is-ok'">
            <icons :name="recon.report.problems ? 'exclamation' : 'tick'" class="w-4 h-4" />
            {{ recon.report.problems ? recon.report.problems + ' ' + $t('item(s) need attention') : $t('Everything is registered.') }}
            <small>{{ recon.report.registered }} {{ $t('registered in code') }} / {{ recon.report.in_db }} {{ $t('switches here') }}</small>
          </p>
          <div v-for="g in reconGroups" :key="g.title" class="fc-recon-group">
            <b>{{ g.title }} <em>{{ g.items.length }}</em></b>
            <ul><li v-for="i in g.items.slice(0, 40)" :key="i">{{ i }}</li><li v-if="g.items.length > 40">+ {{ g.items.length - 40 }} {{ $t('more') }}</li></ul>
          </div>
          <button v-if="recon.report.missing_in_db.length" type="button" class="lg-btn lg-btn-primary" :disabled="recon.loading" @click="addMissing"><icons name="plus" class="w-4 h-4" />{{ $t('Add the missing functions (switched on)') }}</button>
        </template>
      </section>

      <section class="lg-card fc-filters">
        <div class="fc-filter-row">
          <label class="fc-search">
            <icons name="search" class="w-4 h-4" />
            <input v-model="search" type="text" :placeholder="$t('Search features...')" />
            <button v-if="search" type="button" class="fc-search-x" :aria-label="$t('Clear')" @click="search = ''"><icons name="cross" class="w-3.5 h-3.5" /></button>
          </label>
          <div class="fc-seg" role="tablist">
            <button
              v-for="opt in statusFilters"
              :key="opt.value"
              type="button"
              role="tab"
              :aria-selected="statusFilter === opt.value"
              class="fc-seg-btn"
              :class="{ 'is-on': statusFilter === opt.value }"
              @click="statusFilter = opt.value"
            >{{ opt.label }}</button>
          </div>
          <css-select v-model="moduleFilter" class="fc-select">
            <option value="">{{ $t('All Modules') }}</option>
            <option v-for="m in modules" :key="m" :value="m">{{ m }}</option>
          </css-select>
        </div>
      </section>

      <div v-if="!filteredFeatures.length" class="lg-card fc-empty">
        <span class="fc-empty-ico"><icons name="search" class="w-6 h-6" /></span>
        {{ $t('No features match your search.') }}
      </div>

      <section v-for="(group, gi) in groupedFeatures" :key="group.module" class="fc-group" :class="'is-' + tone(gi)">
        <header class="fc-group-head">
          <span class="fc-group-ico"><icons :name="group.items[0].icon || 'toggle'" class="w-4 h-4" /></span>
          <h2 class="fc-group-title">{{ group.module }}</h2>
          <span class="fc-group-count">{{ group.items.filter(i => i.is_enabled).length }}/{{ group.items.length }} {{ $t('Active') }}</span>
        </header>
        <div class="fc-grid">
          <article v-for="feature in group.items" :key="feature.key" class="fc-card" :class="{ 'is-off': !feature.is_enabled }">
            <div class="fc-card-top">
              <span class="fc-card-ico"><icons :name="feature.icon || 'toggle'" class="w-5 h-5" /></span>
              <div class="fc-card-name">{{ feature.name }}</div>
              <span class="fc-state" :class="feature.is_enabled ? 'is-on' : 'is-off'">
                <span class="fc-state-dot"></span>
                {{ feature.is_enabled ? $t('ACTIVE') : $t('DISABLED') }}
              </span>
            </div>
            <p class="fc-card-desc">{{ feature.description }}</p>
            <p v-if="feature.depends_on" class="fc-dep"><icons name="link" class="w-3 h-3" />{{ $t('Depends on') }}: {{ featureName(feature.depends_on) }}</p>
            <button type="button" class="fc-switch" :class="feature.is_enabled ? 'is-on' : 'is-off'" :aria-pressed="feature.is_enabled" @click="askToggle(feature)">
              <span class="fc-switch-track"><span class="fc-switch-thumb"></span></span>
              <span class="fc-switch-label">{{ feature.is_enabled ? $t('Disable Feature') : $t('Enable Feature') }}</span>
            </button>
          </article>
        </div>
      </section>
    </div>

    <tec-dialog-modal :show="!!pending" @close="closePending" :max-width="'sm'">
      <template #title>
        <div class="flex items-center gap-3">
          <span
            class="inline-flex h-10 w-10 items-center justify-center rounded-full"
            :class="pending && pending.is_enabled ? 'bg-red-100 text-red-600' : 'bg-emerald-100 text-emerald-700'"
          >
            <icons :name="pending && pending.is_enabled ? 'lock-closed' : 'toggle'" className="h-5 w-5" />
          </span>
          <span>{{ confirmTitle }}</span>
        </div>
      </template>

      <template #content>
        <p class="mb-4 text-sm" :class="pending && pending.is_enabled ? 'text-red-700' : 'text-gray-700'">
          {{ confirmContent }}
        </p>

        <div v-if="pending && pending.is_enabled">
          <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $t('Admin Password') }}</label>
          <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
              <icons name="key" className="h-4 w-4" />
            </span>
            <input
              v-model="disablePassword"
              type="password"
              autocomplete="current-password"
              class="w-full rounded-md border-gray-300 pl-10 shadow-sm focus:border-red-300 focus:ring focus:ring-red-200 focus:ring-opacity-50"
              @keyup.enter="confirmToggle"
            />
          </div>
          <div v-if="passwordError" class="mt-2 text-xs text-red-600">{{ passwordError }}</div>
        </div>
      </template>

      <template #footer>
        <div class="flex items-center justify-between">
          <button @click="closePending" class="px-3 py-2 bg-gray-200 rounded-md">{{ $t('Cancel') }}</button>
          <loading-button
            @click="confirmToggle"
            :loading="submitting"
            :disabled="submitting || (pending && pending.is_enabled && !disablePassword)"
            :class="pending && pending.is_enabled ? '!bg-red-600 hover:!bg-red-700' : '!bg-emerald-600 hover:!bg-emerald-700'"
          >
            {{ pending && pending.is_enabled ? $t('Disable Feature') : $t('Enable Feature') }}
          </loading-button>
        </div>
      </template>
    </tec-dialog-modal>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import { Link } from '@inertiajs/vue3';

export default {
  props: {
    features: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({ total: 0, active: 0, disabled: 0 }) },
  },

  components: { AdminLayout, TecDialogModal, LoadingButton, PageHeader, StatTiles, Link },

  data() {
    return {
      search: '',
      statusFilter: 'all',
      moduleFilter: '',
      pending: null,
      disablePassword: '',
      passwordError: '',
      submitting: false,
      recon: { loading: false, report: null },
    };
  },

  computed: {
    reconGroups() {
      const r = this.recon.report;
      if (!r) return [];
      const g = [
        [this.$t('Registered in code but missing here'), r.missing_in_db],
        [this.$t('Here but no longer in the code registry'), r.orphan_in_db],
        [this.$t('Depends on a function that does not exist'), r.bad_dependency.map(i => i.key + ' > ' + i.depends_on)],
        [this.$t('Route prefix claimed twice'), r.duplicate_prefix.map(i => i.prefix + ': ' + i.features.join(', '))],
        [this.$t('Menu names an unregistered feature'), r.menu_unknown],
        [this.$t('Top-level menu group without a feature'), r.menu_ungated],
        [this.$t('Pages no function covers'), r.routes_ungoverned.map(i => i.prefix + '.* (' + i.routes + ')')],
        [this.$t('Permissions not created yet'), r.missing_permissions],
      ];
      return g.filter(x => x[1].length).map(x => ({ title: x[0], items: x[1] }));
    },
    tiles() {
      return [
        { icon: 'collection', tone: 'navy', label: this.$t('Total Features'), value: this.stats.total },
        { icon: 'tick', tone: 'emerald', label: this.$t('Active'), value: this.stats.active },
        { icon: 'lock-closed', tone: 'rose', label: this.$t('Disabled'), value: this.stats.disabled },
      ];
    },

    statusFilters() {
      return [
        { value: 'all', label: this.$t('All') },
        { value: 'active', label: this.$t('Active') },
        { value: 'disabled', label: this.$t('Disabled') },
      ];
    },

    modules() {
      return [...new Set(this.features.map(f => f.module))];
    },

    filteredFeatures() {
      const q = this.search.trim().toLowerCase();

      return this.features.filter(f => {
        if (this.statusFilter === 'active' && !f.is_enabled) return false;
        if (this.statusFilter === 'disabled' && f.is_enabled) return false;
        if (this.moduleFilter && f.module !== this.moduleFilter) return false;
        if (!q) return true;

        return [f.name, f.description, f.module, f.key].some(v => (v || '').toLowerCase().includes(q));
      });
    },

    groupedFeatures() {
      const groups = {};
      this.filteredFeatures.forEach(f => {
        (groups[f.module] ||= []).push(f);
      });

      return Object.keys(groups).map(module => ({ module, items: groups[module] }));
    },

    confirmTitle() {
      if (!this.pending) return '';

      return this.pending.is_enabled
        ? `${this.$t('Disable')} ${this.pending.name}?`
        : `${this.$t('Enable')} ${this.pending.name}?`;
    },

    confirmContent() {
      if (!this.pending) return '';

      return this.pending.is_enabled
        ? `${this.$t('This will hide and restrict')} ${this.pending.name} ${this.$t('functionality for all users except Super Admins. No existing data will be deleted.')}`
        : `${this.pending.name} ${this.$t('functionality will become available to users according to their existing permissions.')}`;
    },
  },

  methods: {
    checkRegistration() {
      this.recon.loading = true;
      axios.get(route('system-features.reconcile')).then(r => { this.recon.report = r.data; }).finally(() => { this.recon.loading = false; });
    },
    addMissing() {
      this.recon.loading = true;
      axios.post(route('system-features.reconcile.apply')).then(r => { this.recon.report = r.data; this.$inertia.reload({ only: ['features', 'stats'] }); }).finally(() => { this.recon.loading = false; });
    },
    tone(i) {
      return ['blue', 'emerald', 'violet', 'amber', 'cyan', 'rose', 'indigo'][i % 7];
    },

    featureName(key) {
      return this.features.find(f => f.key === key)?.name || key;
    },

    askToggle(feature) {
      this.pending = feature;
      this.disablePassword = '';
      this.passwordError = '';
    },

    closePending() {
      this.pending = null;
      this.disablePassword = '';
      this.passwordError = '';
    },

    confirmToggle() {
      const feature = this.pending;
      if (!feature) return;

      if (feature.is_enabled && !this.disablePassword) {
        return;
      }

      this.submitting = true;
      this.passwordError = '';

      this.$inertia.put(
        route('system-features.update', feature.id),
        { enabled: !feature.is_enabled, password: feature.is_enabled ? this.disablePassword : undefined },
        {
          preserveScroll: true,
          onError: errors => {
            this.passwordError = errors.password || '';
          },
          onSuccess: () => {
            this.closePending();
          },
          onFinish: () => {
            this.submitting = false;
          },
        }
      );
    },
  },
};
</script>

<style scoped>
.fc-filters { padding: 0.8rem 1rem; margin-bottom: 1rem; }
.fc-filter-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.fc-search { display: flex; align-items: center; gap: 8px; flex: 1 1 240px; min-width: 0; height: 42px; padding: 0 12px; border-radius: 14px; background: var(--lg-card); border: 1px solid var(--lg-line); color: var(--lg-faint); box-shadow: 0 6px 12px -10px rgba(15, 37, 64, 0.5); }
.fc-search:focus-within { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18); }
.fc-search input { flex: 1; min-width: 0; border: 0 !important; outline: none !important; box-shadow: none !important; background: transparent; padding: 0 !important; font-size: 0.88rem; color: var(--lg-ink); }
.fc-search-x { display: inline-flex; padding: 4px; border: 0; border-radius: 9999px; background: rgba(15, 23, 42, 0.06); color: #64748b; cursor: pointer; }
.fc-seg { display: inline-flex; padding: 3px; gap: 2px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.fc-seg-btn { padding: 7px 14px; border: 0; border-radius: 11px; background: transparent; font-size: 0.8rem; font-weight: 700; color: var(--lg-mute); cursor: pointer; transition: all 0.15s ease; }
.fc-seg-btn.is-on { color: #fff; background: linear-gradient(145deg, #3b82f6, #1c3f66); box-shadow: 0 8px 14px -8px rgba(28, 63, 102, 0.8); }
.fc-select { height: 42px; border-radius: 14px; border: 1px solid var(--lg-line); background: var(--lg-card); padding: 0 34px 0 12px; font-size: 0.85rem; color: var(--lg-ink); box-shadow: 0 6px 12px -10px rgba(15, 37, 64, 0.5); }
.fc-empty { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 36px; color: var(--lg-mute); }
.fc-empty-ico { display: inline-flex; align-items: center; justify-content: center; width: 52px; height: 52px; border-radius: 9999px; background: var(--lg-soft); color: #94a3b8; }

.fc-group { --c: #2563eb; --c1: #60a5fa; --c2: #1d4ed8; margin-top: 1.4rem; }
.fc-group.is-emerald { --c: #047857; --c1: #34d399; --c2: #047857; }
.fc-group.is-violet { --c: #6d28d9; --c1: #a78bfa; --c2: #6d28d9; }
.fc-group.is-amber { --c: #b45309; --c1: #fbbf24; --c2: #b45309; }
.fc-group.is-cyan { --c: #0e7490; --c1: #22d3ee; --c2: #0e7490; }
.fc-group.is-rose { --c: #be123c; --c1: #fb7185; --c2: #be123c; }
.fc-group.is-indigo { --c: #4338ca; --c1: #818cf8; --c2: #4338ca; }
.fc-group-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; padding: 0 4px; }
.fc-group-ico { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 11px; color: #fff; background: linear-gradient(145deg, var(--c1), var(--c2)); box-shadow: 0 10px 16px -8px var(--c2); }
.fc-group-title { margin: 0; font-size: 0.78rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--c); }
.fc-group-count { margin-left: auto; padding: 2px 10px; border-radius: 9999px; font-size: 0.68rem; font-weight: 800; color: var(--c); background: color-mix(in srgb, var(--c) 12%, transparent); }
.fc-grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fill, minmax(min(280px, 100%), 1fr)); }
.fc-card { display: flex; flex-direction: column; padding: 16px; border-radius: 20px; background: var(--lg-card); border: 1px solid color-mix(in srgb, var(--c) 18%, var(--lg-line)); box-shadow: 0 14px 26px -20px color-mix(in srgb, var(--c2) 70%, transparent), 0 1px 2px rgba(15, 37, 64, 0.06); transition: transform 0.15s ease, box-shadow 0.15s ease; }
.fc-card:hover { transform: translateY(-2px); box-shadow: 0 20px 30px -18px color-mix(in srgb, var(--c2) 75%, transparent); }
.fc-card.is-off { background: linear-gradient(160deg, rgba(244, 63, 94, 0.05), var(--lg-card) 60%); }
.fc-card-top { display: flex; align-items: center; gap: 10px; }
.fc-card-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 40px; height: 40px; border-radius: 14px; color: var(--c); background: color-mix(in srgb, var(--c) 12%, transparent); border: 1.5px solid color-mix(in srgb, var(--c) 40%, transparent); }
.fc-card.is-off .fc-card-ico { color: #94a3b8; background: var(--lg-soft); border-color: var(--lg-line); }
.fc-card-name { flex: 1; min-width: 0; font-weight: 800; font-size: 0.92rem; color: var(--lg-ink); }
.fc-state { display: inline-flex; align-items: center; gap: 6px; flex: none; padding: 3px 9px; border-radius: 9999px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.04em; }
.fc-state.is-on { color: #047857; background: rgba(16, 185, 129, 0.14); }
.fc-state.is-off { color: #be123c; background: rgba(244, 63, 94, 0.12); }
.fc-state-dot { position: relative; width: 7px; height: 7px; border-radius: 9999px; background: currentColor; }
.fc-state.is-on .fc-state-dot::after { content: ''; position: absolute; inset: -4px; border-radius: 9999px; border: 2px solid currentColor; opacity: 0; animation: fc-live 1.9s ease-out infinite; }
@keyframes fc-live { 0% { transform: scale(0.5); opacity: 0.8; } 100% { transform: scale(1.7); opacity: 0; } }
.fc-card-desc { flex: 1; margin: 10px 0 0; font-size: 0.8rem; line-height: 1.5; color: var(--lg-mute); }
.fc-dep { display: inline-flex; align-items: center; gap: 5px; margin: 8px 0 0; font-size: 0.72rem; font-weight: 700; color: #b45309; }
.fc-switch { display: flex; align-items: center; gap: 10px; width: 100%; margin-top: 14px; padding: 8px 12px; border-radius: 14px; border: 1px solid transparent; cursor: pointer; font-size: 0.8rem; font-weight: 800; transition: all 0.15s ease; }
.fc-switch.is-on { color: #be123c; background: rgba(244, 63, 94, 0.08); border-color: rgba(244, 63, 94, 0.25); }
.fc-switch.is-off { color: #047857; background: rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.3); }
.fc-switch:hover { transform: translateY(-1px); box-shadow: 0 10px 16px -10px currentColor; }
.fc-switch-track { position: relative; flex: none; width: 38px; height: 22px; border-radius: 9999px; background: #10b981; transition: background 0.2s ease; box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.25); }
.fc-switch.is-off .fc-switch-track { background: #cbd5e1; }
.fc-switch-thumb { position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 9999px; background: #fff; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3); transition: transform 0.2s ease; }
.fc-switch.is-on .fc-switch-thumb { transform: translateX(16px); }
@media (prefers-reduced-motion: reduce) { .fc-state-dot::after { animation: none !important; } }

.fc-recon { margin-bottom: 1rem; padding: 1rem 1.1rem; display: grid; gap: 0.8rem; }
.fc-recon-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.7rem; } .fc-recon-head h3 { margin: 0; font-size: 0.95rem; font-weight: 800; } .fc-recon-head p { margin: 2px 0 0; font-size: 0.8rem; color: var(--lg-faint); }
.fc-recon-sum { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; margin: 0; padding: 0.55rem 0.8rem; border-radius: 12px; font-size: 0.85rem; font-weight: 800; } .fc-recon-sum small { margin-left: auto; font-weight: 600; opacity: 0.8; } .fc-recon-sum.is-ok { color: #047857; background: rgba(16, 185, 129, 0.1); } .fc-recon-sum.is-bad { color: #b45309; background: rgba(245, 158, 11, 0.12); }
.fc-recon-group b { display: flex; align-items: center; gap: 0.5rem; font-size: 0.8rem; } .fc-recon-group em { font-style: normal; font-size: 0.68rem; padding: 1px 8px; border-radius: 999px; background: var(--lg-soft); box-shadow: inset 0 0 0 1px var(--lg-line); } .fc-recon-group ul { margin: 0.3rem 0 0; padding-left: 1.1rem; display: flex; flex-wrap: wrap; gap: 0.2rem 1.4rem; font-size: 0.78rem; color: var(--lg-mute); }
</style>

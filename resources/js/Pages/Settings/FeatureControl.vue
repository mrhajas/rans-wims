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
          <label class="fc-search" :class="{ 'has-text': !!search }">
            <icons name="search" class="w-4 h-4" />
            <input v-model="search" type="text" :placeholder="$t('Search features...')" />
            <button v-if="search" type="button" class="fc-search-x" :aria-label="$t('Clear')" @click="search = ''"><icons name="cross" class="w-3.5 h-3.5" /></button>
          </label>
          <div class="fc-seg" role="tablist" :style="{ '--n': statusFilters.length, '--at': statusIndex }">
            <span class="fc-seg-pill"></span>
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
        <transition name="fc-fade">
          <p v-if="filtering" class="fc-count">
            <icons name="search" class="w-3.5 h-3.5" />
            {{ $t('Showing') }} <b :key="shownCount" class="fc-num">{{ shownCount }}</b> {{ $t('of') }} {{ rows.length }} {{ $t('functions') }}
            <button type="button" class="fc-count-clear" @click="clearFilters">{{ $t('Clear filters') }}</button>
          </p>
        </transition>
      </section>

      <transition name="fc-fade">
        <div v-if="!groupedFeatures.length" class="lg-card fc-empty">
          <span class="fc-empty-ico"><icons name="search" class="w-6 h-6" /></span>
          {{ $t('No features match your search.') }}
        </div>
      </transition>

      <transition-group name="fc-sec" tag="div" class="fc-sections">
        <section v-for="(group, gi) in groupedFeatures" :key="group.module" class="fc-group" :class="'is-' + tone(group.tone)">
          <header class="fc-group-head">
            <span class="fc-group-ico"><icons :name="group.icon || 'toggle'" class="w-4 h-4" /></span>
            <h2 class="fc-group-title">{{ group.module }}</h2>
            <span class="fc-group-count">{{ group.on }}/{{ group.total }} {{ $t('Active') }}</span>
          </header>
          <transition-group name="fc-list" tag="div" class="fc-grid">
            <div v-for="(unit, ui) in group.units" :key="unit.root.key" class="fc-unit" :class="{ 'is-wide': unit.all }">
              <feature-card
                v-if="!unit.all"
                :feature="unit.root"
                :query="search"
                :index="ui"
                :focused="focusKey === unit.root.key"
                :note="unit.root.depends_on ? $t('Depends on') + ': ' + featureName(unit.root.depends_on) : ''"
                @toggle="toggleOne"
              />
              <article v-else class="fc-family" :class="{ 'is-off': unit.state === 'off', 'is-mixed': unit.state === 'mixed', 'is-open': isOpen(unit) }" :data-fk="unit.root.key" :style="{ '--i': Math.min(ui, 14) }">
                <header class="fc-fam-head" @click="toggleOpen(unit)">
                  <span class="fc-fam-ico"><icons :name="unit.root.icon || 'toggle'" class="w-5 h-5" /></span>
                  <div class="fc-fam-main">
                    <div class="fc-fam-name" v-html="hl(unit.root.name)"></div>
                    <p class="fc-fam-desc" v-html="hl(unit.root.description)"></p>
                  </div>
                  <span class="fc-fam-meter" :class="'is-' + unit.state" :title="unit.subOn + ' / ' + unit.all">
                    <span class="fc-fam-bar"><i :style="{ width: (unit.all ? Math.round((unit.subOn / unit.all) * 100) : 0) + '%' }"></i></span>
                    <small>{{ unit.subOn }}/{{ unit.all }} {{ $t('sub-functions on') }}</small>
                  </span>
                  <div class="fc-fam-main-switch" @click.stop>
                    <span class="fc-fam-ms-label">{{ $t('Main switch') }}</span>
                    <feature-switch
                      size="lg"
                      :state="unit.state"
                      :disabled="busyKey === unit.root.key"
                      :label="(unit.state === 'on' ? $t('Switch off') : $t('Switch on')) + ' ' + unit.root.name + ' ' + $t('and all its sub-functions')"
                      @toggle="toggleFamily(unit)"
                    />
                    <button v-if="unit.state === 'mixed'" type="button" class="fc-fam-alloff" @click="askFamily(unit, false)">{{ $t('All off') }}</button>
                  </div>
                  <button type="button" class="fc-fam-chev" :aria-expanded="isOpen(unit)" :aria-label="$t('Show or hide the sub-functions')" @click.stop="toggleOpen(unit)"><icons name="chevron-down" class="w-4 h-4" /></button>
                </header>
                <div class="fc-fam-body" :class="{ 'is-open': isOpen(unit) }">
                  <div class="fc-fam-inner">
                    <p class="fc-fam-hint"><icons name="link" class="w-3 h-3" />{{ $t('Switch the sub-functions off one by one, or use the Main switch for all of them together.') }}</p>
                    <transition-group name="fc-list" tag="div" class="fc-kids">
                      <feature-card
                        v-for="(kid, ki) in unit.kids"
                        :key="kid.key"
                        compact
                        :feature="kid"
                        :query="search"
                        :index="ki"
                        :focused="focusKey === kid.key"
                        :note="kid.depends_on && kid.depends_on !== unit.root.key ? $t('Under') + ': ' + featureName(kid.depends_on) : ''"
                        @toggle="toggleOne"
                      />
                    </transition-group>
                  </div>
                </div>
              </article>
            </div>
          </transition-group>
        </section>
      </transition-group>
    </div>

    <tec-dialog-modal :show="!!pending" @close="closePending" :max-width="'sm'">
      <template #title>
        <div class="flex items-center gap-3">
          <span
            class="inline-flex h-10 w-10 items-center justify-center rounded-full"
            :class="pending && !pending.enable ? 'bg-red-100 text-red-600' : 'bg-emerald-100 text-emerald-700'"
          >
            <icons :name="pending && !pending.enable ? 'lock-closed' : 'toggle'" className="h-5 w-5" />
          </span>
          <span>{{ confirmTitle }}</span>
        </div>
      </template>

      <template #content>
        <p class="mb-4 text-sm" :class="pending && !pending.enable ? 'text-red-700' : 'text-gray-700'">
          {{ confirmContent }}
        </p>

        <div v-if="pending && !pending.enable">
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
            :disabled="submitting || (pending && !pending.enable && !disablePassword)"
            :class="pending && !pending.enable ? '!bg-red-600 hover:!bg-red-700' : '!bg-emerald-600 hover:!bg-emerald-700'"
          >
            {{ pending && !pending.enable ? (pending.family ? $t('Switch all off') : $t('Disable Feature')) : $t('Enable Feature') }}
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
import FeatureCard from '@/Shared/FeatureControl/FeatureCard.vue';
import FeatureSwitch from '@/Shared/FeatureControl/FeatureSwitch.vue';
import { highlightText } from '@/Core/highlightText';
import { Link } from '@inertiajs/vue3';

const TONES = ['blue', 'emerald', 'violet', 'amber', 'cyan', 'rose', 'indigo'];
const AUTO_OPEN_MAX = 8; // a family with more sub-functions than this starts folded (unless you are searching)

export default {
  props: {
    features: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({ total: 0, active: 0, disabled: 0 }) },
    focus: { type: String, default: '' },
  },

  components: { AdminLayout, TecDialogModal, LoadingButton, PageHeader, StatTiles, FeatureCard, FeatureSwitch, Link },

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
      override: {}, // key -> true while a switch-on is in flight, so the switch moves at once
      busyKey: '',
      opened: {}, // root key -> folded or open, once the person has chosen
      focusKey: '',
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

    // the functions as they are right now: the saved switch (or the one just flipped on), and whether anything above them holds them off
    rows() {
      const byKey = {};
      this.features.forEach(f => { byKey[f.key] = { ...f, is_enabled: this.override[f.key] != null ? this.override[f.key] : f.is_enabled }; });
      const effective = key => {
        const seen = {};
        let cur = byKey[key];
        while (cur) {
          if (!cur.is_enabled) return false;
          if (!cur.depends_on || !byKey[cur.depends_on] || seen[cur.depends_on]) return true;
          seen[cur.key] = true;
          cur = byKey[cur.depends_on];
        }
        return true;
      };
      return Object.values(byKey).map(f => ({ ...f, effective: effective(f.key) }));
    },
    byKey() {
      const m = {};
      this.rows.forEach(f => { m[f.key] = f; });
      return m;
    },

    tiles() {
      const total = this.rows.length;
      const active = this.rows.filter(f => f.is_enabled).length;
      return [
        { icon: 'collection', tone: 'navy', label: this.$t('Total Features'), value: total },
        { icon: 'tick', tone: 'emerald', label: this.$t('Active'), value: active },
        { icon: 'lock-closed', tone: 'rose', label: this.$t('Disabled'), value: total - active },
      ];
    },

    statusFilters() {
      return [
        { value: 'all', label: this.$t('All') },
        { value: 'active', label: this.$t('Active') },
        { value: 'disabled', label: this.$t('Disabled') },
      ];
    },
    statusIndex() {
      return Math.max(0, this.statusFilters.findIndex(o => o.value === this.statusFilter));
    },
    modules() {
      return [...new Set(this.features.map(f => f.module))];
    },
    filtering() {
      return !!(this.search.trim() || this.statusFilter !== 'all' || this.moduleFilter);
    },

    // every function in its module; a function that depends on another one in the same module is shown under it (a "family" with a Main switch)
    trees() {
      const kidsOf = {};
      this.rows.forEach(f => {
        const p = f.depends_on && this.byKey[f.depends_on];
        if (p && p.module === f.module) (kidsOf[p.key] ||= []).push(f);
      });
      const below = (key, seen = {}) => {
        const out = [];
        (kidsOf[key] || []).forEach(k => {
          if (seen[k.key]) return;
          seen[k.key] = true;
          out.push(k, ...below(k.key, seen));
        });
        return out;
      };
      return this.rows
        .filter(f => !(f.depends_on && this.byKey[f.depends_on] && this.byKey[f.depends_on].module === f.module))
        .map(root => ({ root, all: below(root.key, { [root.key]: true }) }));
    },

    groupedFeatures() {
      const q = this.search.trim().toLowerCase();
      const text = f => !q || [f.name, f.description, f.module, f.key].some(v => (v || '').toLowerCase().includes(q));
      const status = f => (this.statusFilter === 'active' ? f.is_enabled : this.statusFilter === 'disabled' ? !f.is_enabled : true);
      const groups = {};
      const order = [];

      this.trees.forEach(({ root, all }) => {
        if (this.moduleFilter && root.module !== this.moduleFilter) return;
        const rootText = text(root);
        // a family whose own name matches the search shows all its sub-functions; otherwise only the ones that match
        const kids = all.filter(k => status(k) && (rootText || text(k)));
        const rootShown = status(root) && rootText;
        if (!rootShown && !kids.length) return;

        const members = [root, ...all];
        const on = members.filter(m => m.is_enabled).length;
        const state = on === members.length ? 'on' : (root.is_enabled || on) && on ? 'mixed' : 'off';
        if (!groups[root.module]) { groups[root.module] = { module: root.module, icon: root.icon, units: [], on: 0, total: 0 }; order.push(root.module); }
        const g = groups[root.module];
        g.units.push({ root, kids, all: all.length, subOn: all.filter(m => m.is_enabled).length, on, total: members.length, state: all.length ? (on === 0 ? 'off' : state) : (root.is_enabled ? 'on' : 'off') });
        g.on += members.filter(m => m.is_enabled).length;
        g.total += members.length;
      });

      return order.map(m => groups[m]).map(g => ({ ...g, tone: this.modules.indexOf(g.module) }));
    },
    shownCount() {
      return this.groupedFeatures.reduce((n, g) => n + g.units.reduce((m, u) => m + 1 + u.kids.length, 0), 0);
    },

    confirmTitle() {
      const p = this.pending;
      if (!p) return '';
      if (p.family) return p.enable ? `${this.$t('Switch on')} ${p.feature.name}?` : `${this.$t('Switch off')} ${p.feature.name} ${this.$t('and everything under it')}?`;
      return p.enable ? `${this.$t('Enable')} ${p.feature.name}?` : `${this.$t('Disable')} ${p.feature.name}?`;
    },

    confirmContent() {
      const p = this.pending;
      if (!p) return '';
      if (p.family) {
        return p.enable
          ? `${p.feature.name} ${this.$t('and its')} ${p.count} ${this.$t('sub-function(s) will be switched on.')}`
          : `${this.$t('This switches off')} ${p.feature.name} ${this.$t('and its')} ${p.count} ${this.$t('sub-function(s) for everyone, Super Admins included. Super Admins still see the menu entries, marked disabled, and get a notice when they open one. No data is deleted.')}`;
      }
      return p.enable
        ? `${p.feature.name} ${this.$t('functionality will become available to users according to their existing permissions.')}`
        : `${this.$t('This switches off')} ${p.feature.name} ${this.$t('for everyone, Super Admins included. Super Admins still see its menu entry, marked disabled, and get a notice when they open it. No data is deleted.')}`;
    },
  },

  mounted() {
    if (this.focus) this.$nextTick(() => this.goTo(this.focus));
  },

  methods: {
    hl(t) { return highlightText(t, this.search); },
    tone(i) { return TONES[(i < 0 ? 0 : i) % TONES.length]; },
    featureName(key) { return (this.byKey[key] || {}).name || key; },
    clearFilters() { this.search = ''; this.statusFilter = 'all'; this.moduleFilter = ''; },

    // folding of the families: open by default unless long; always open while filtering so the matches are visible
    isOpen(unit) {
      if (this.filtering) return true;
      const k = unit.root.key;
      return this.opened[k] != null ? this.opened[k] : unit.all <= AUTO_OPEN_MAX;
    },
    toggleOpen(unit) { this.opened = { ...this.opened, [unit.root.key]: !this.isOpen(unit) }; },

    // coming from the "module deactivated" notice: bring the card into view and ring it
    goTo(key) {
      const f = this.byKey[key];
      if (!f) return;
      let root = f;
      while (root.depends_on && this.byKey[root.depends_on] && this.byKey[root.depends_on].module === root.module) root = this.byKey[root.depends_on];
      this.opened = { ...this.opened, [root.key]: true };
      this.focusKey = key;
      this.$nextTick(() => setTimeout(() => {
        const el = document.querySelector('[data-fk="' + key + '"]');
        if (el && el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }, 120));
      setTimeout(() => { this.focusKey = ''; }, 3200);
    },

    checkRegistration() {
      this.recon.loading = true;
      axios.get(route('system-features.reconcile')).then(r => { this.recon.report = r.data; }).finally(() => { this.recon.loading = false; });
    },
    addMissing() {
      this.recon.loading = true;
      axios.post(route('system-features.reconcile.apply')).then(r => { this.recon.report = r.data; this.$inertia.reload({ only: ['features', 'stats'] }); }).finally(() => { this.recon.loading = false; });
    },

    // One switch. Switching ON is harmless, so it happens at once; switching OFF asks for the password first.
    toggleOne(feature) {
      if (feature.is_enabled) return this.ask({ feature, enable: false, family: false, count: 0 });
      this.send(route('system-features.update', feature.id), true, feature.key, [feature.key]);
    },
    // The Main switch of a family: all on, or all off together
    toggleFamily(unit) {
      this.askFamily(unit, unit.state !== 'on');
    },
    askFamily(unit, enable) {
      const keys = [unit.root.key, ...this.treeKeys(unit.root.key)];
      if (enable) return this.send(route('system-features.family', unit.root.id), true, unit.root.key, keys);
      this.ask({ feature: unit.root, enable: false, family: true, count: keys.length - 1 });
    },
    treeKeys(rootKey) {
      const t = this.trees.find(x => x.root.key === rootKey);
      return t ? t.all.map(f => f.key) : [];
    },

    ask(p) {
      this.pending = p;
      this.disablePassword = '';
      this.passwordError = '';
    },
    closePending() {
      this.pending = null;
      this.disablePassword = '';
      this.passwordError = '';
    },

    confirmToggle() {
      const p = this.pending;
      if (!p || (!p.enable && !this.disablePassword)) return;
      const url = p.family ? route('system-features.family', p.feature.id) : route('system-features.update', p.feature.id);
      this.send(url, p.enable, p.feature.key, [p.feature.key], this.disablePassword);
    },

    send(url, enable, busyKey, keys, password) {
      this.submitting = true;
      this.busyKey = busyKey;
      this.passwordError = '';
      if (enable) { const o = { ...this.override }; keys.forEach(k => { o[k] = true; }); this.override = o; }

      this.$inertia.put(url, { enabled: enable, password: enable ? undefined : password }, {
        preserveScroll: true,
        onError: errors => { this.passwordError = errors.password || ''; },
        onSuccess: () => { this.closePending(); },
        onFinish: () => {
          this.submitting = false;
          this.busyKey = '';
          const o = { ...this.override }; keys.forEach(k => { delete o[k]; }); this.override = o;
        },
      });
    },
  },
};
</script>

<style scoped>
/* page entrance: each part rises in turn */
.fc-page > * { animation: fc-rise 0.55s cubic-bezier(0.2, 0.8, 0.25, 1) backwards; }
.fc-page > *:nth-child(2) { animation-delay: 60ms; }
.fc-page > *:nth-child(3) { animation-delay: 120ms; }
.fc-page > *:nth-child(4) { animation-delay: 180ms; }
.fc-page > *:nth-child(5) { animation-delay: 240ms; }

.fc-filters { padding: 0.8rem 1rem; margin-bottom: 1rem; }
.fc-filter-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.fc-search { display: flex; align-items: center; gap: 8px; flex: 1 1 240px; min-width: 0; height: 42px; padding: 0 12px; border-radius: 14px; background: var(--lg-card); border: 1px solid var(--lg-line); color: var(--lg-faint); box-shadow: 0 6px 12px -10px rgba(15, 37, 64, 0.5); transition: border-color 0.2s ease, box-shadow 0.25s ease, color 0.2s ease; }
.fc-search:focus-within, .fc-search.has-text { color: #2563eb; }
.fc-search:focus-within { border-color: #3b82f6; box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.18); }
.fc-search input { flex: 1; min-width: 0; border: 0 !important; outline: none !important; box-shadow: none !important; background: transparent; padding: 0 !important; font-size: 0.88rem; color: var(--lg-ink); }
.fc-search-x { display: inline-grid !important; place-items: center; flex: none; width: 24px !important; height: 24px !important; min-height: 0 !important; padding: 0 !important; border: 0; border-radius: 9999px !important; background: rgba(15, 23, 42, 0.07); color: #64748b; cursor: pointer; animation: fc-pop 0.25s cubic-bezier(0.34, 1.56, 0.5, 1) backwards; }
.fc-search-x:hover { background: rgba(244, 63, 94, 0.15); color: #be123c; }

/* the status filter: one pill that slides to the chosen button */
.fc-seg { position: relative; display: inline-grid; grid-template-columns: repeat(var(--n), 1fr); padding: 3px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.fc-seg-pill { position: absolute; top: 3px; bottom: 3px; left: 3px; width: calc((100% - 6px) / var(--n)); border-radius: 11px; background: linear-gradient(145deg, #3b82f6, #1c3f66); box-shadow: 0 8px 14px -8px rgba(28, 63, 102, 0.8); transform: translateX(calc(var(--at) * 100%)); transition: transform 0.4s cubic-bezier(0.34, 1.4, 0.5, 1); }
.fc-seg-btn { position: relative; z-index: 1; min-height: 0 !important; padding: 7px 16px !important; border: 0 !important; border-radius: 11px !important; background: transparent !important; box-shadow: none !important; font-size: 0.8rem; font-weight: 700; color: var(--lg-mute); cursor: pointer; transition: color 0.25s ease; }
.fc-seg-btn.is-on { color: #fff; }
.fc-select { height: 42px; border-radius: 14px; border: 1px solid var(--lg-line); background: var(--lg-card); padding: 0 34px 0 12px; font-size: 0.85rem; color: var(--lg-ink); box-shadow: 0 6px 12px -10px rgba(15, 37, 64, 0.5); }
.fc-count { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin: 10px 0 0; font-size: 0.8rem; color: var(--lg-mute); }
.fc-count b { color: var(--lg-ink); font-variant-numeric: tabular-nums; }
.fc-num { display: inline-block; animation: fc-pop 0.35s cubic-bezier(0.34, 1.56, 0.5, 1); }
.fc-count-clear { margin-left: auto; min-height: 0 !important; padding: 3px 10px !important; border: 0; border-radius: 9999px !important; font-size: 0.72rem; font-weight: 800; color: #2563eb; background: rgba(59, 130, 246, 0.1); cursor: pointer; }
.fc-empty { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 36px; color: var(--lg-mute); }
.fc-empty-ico { display: inline-flex; align-items: center; justify-content: center; width: 52px; height: 52px; border-radius: 9999px; background: var(--lg-soft); color: #94a3b8; animation: fc-wobble 1.6s ease 1; }

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
.fc-group-count { margin-left: auto; padding: 2px 10px; border-radius: 9999px; font-size: 0.68rem; font-weight: 800; color: var(--c); background: color-mix(in srgb, var(--c) 12%, transparent); font-variant-numeric: tabular-nums; }
.fc-grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fill, minmax(min(280px, 100%), 1fr)); }

/* a function with sub-functions: one wide card, a Main switch on top, the sub-functions inside */
.fc-unit { min-width: 0; display: flex; flex-direction: column; }
.fc-unit > .fcd { flex: 1; }
.fc-unit.is-wide { grid-column: 1 / -1; }
.fc-family { border-radius: 22px; background: var(--lg-card); border: 1px solid color-mix(in srgb, var(--c) 24%, var(--lg-line)); box-shadow: 0 18px 30px -22px color-mix(in srgb, var(--c2) 75%, transparent), 0 1px 2px rgba(15, 37, 64, 0.06); animation: fcd-in 0.55s cubic-bezier(0.2, 0.8, 0.25, 1) backwards; animation-delay: calc(var(--i) * 35ms); transition: background 0.5s ease, border-color 0.5s ease; }
.fc-family.is-off { background: linear-gradient(160deg, rgba(244, 63, 94, 0.06), var(--lg-card) 55%); border-color: rgba(244, 63, 94, 0.25); }
.fc-family.is-mixed { border-color: rgba(245, 158, 11, 0.4); }
.fc-fam-head { display: flex; align-items: center; gap: 14px; padding: 14px 16px; cursor: pointer; flex-wrap: wrap; }
.fc-fam-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 46px; height: 46px; border-radius: 16px; color: #fff; background: linear-gradient(145deg, var(--c1), var(--c2)); box-shadow: 0 12px 18px -10px var(--c2); transition: filter 0.5s ease, background 0.5s ease; }
.fc-family.is-off .fc-fam-ico { filter: grayscale(1); opacity: 0.7; }
.fc-fam-main { flex: 1 1 240px; min-width: 0; }
.fc-fam-name { font-size: 1rem; font-weight: 800; color: var(--lg-ink); }
.fc-fam-desc { margin: 3px 0 0; font-size: 0.8rem; line-height: 1.45; color: var(--lg-mute); }
.fc-fam-meter { display: grid; gap: 4px; flex: none; min-width: 110px; }
.fc-fam-meter small { white-space: nowrap; font-size: 0.7rem; font-weight: 800; color: var(--lg-mute); font-variant-numeric: tabular-nums; }
.fc-fam-bar { display: block; height: 7px; border-radius: 9999px; background: var(--lg-soft); box-shadow: inset 0 0 0 1px var(--lg-line); overflow: hidden; }
.fc-fam-bar i { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #34d399, #059669); transition: width 0.7s cubic-bezier(0.3, 0.9, 0.3, 1), background 0.4s ease; }
.fc-fam-meter.is-mixed .fc-fam-bar i { background: linear-gradient(90deg, #fbbf24, #d97706); }
.fc-fam-meter.is-off .fc-fam-bar i { background: #f43f5e; }
.fc-fam-main-switch { display: flex; align-items: center; gap: 10px; flex: none; padding: 6px 8px 6px 12px; border-radius: 9999px; background: var(--lg-soft); box-shadow: inset 0 0 0 1px var(--lg-line); cursor: default; }
.fc-fam-ms-label { font-size: 0.66rem; font-weight: 900; letter-spacing: 0.08em; text-transform: uppercase; color: var(--lg-mute); }
.fc-fam-alloff { min-height: 0 !important; padding: 3px 10px !important; border: 0; border-radius: 9999px !important; font-size: 0.68rem; font-weight: 800; color: #be123c; background: rgba(244, 63, 94, 0.12); cursor: pointer; animation: fc-pop 0.3s cubic-bezier(0.34, 1.56, 0.5, 1) backwards; }
.fc-fam-chev { display: inline-grid !important; place-items: center; flex: none; width: 34px !important; height: 34px !important; min-height: 0 !important; padding: 0 !important; border: 0; border-radius: 9999px !important; background: var(--lg-soft); color: var(--lg-mute); cursor: pointer; }
.fc-fam-chev svg { transition: transform 0.35s cubic-bezier(0.34, 1.4, 0.5, 1); }
.fc-family.is-open .fc-fam-chev svg { transform: rotate(180deg); }
/* folding without measuring: the row height animates between 0fr and 1fr */
.fc-fam-body { display: grid; grid-template-rows: 0fr; transition: grid-template-rows 0.4s ease; }
.fc-fam-body.is-open { grid-template-rows: 1fr; }
.fc-fam-inner { min-height: 0; overflow: hidden; }
.fc-fam-body.is-open .fc-fam-inner { padding: 0 14px 14px; }
.fc-fam-hint { display: flex; align-items: center; gap: 6px; margin: 0 2px 10px; padding-top: 10px; border-top: 1px dashed var(--lg-line); font-size: 0.74rem; color: var(--lg-faint); }
.fc-kids { display: grid; gap: 12px; grid-template-columns: repeat(auto-fill, minmax(min(250px, 100%), 1fr)); }

/* searching and filtering: cards fade and shrink away, the rest glide to their new places, new ones rise in */
.fc-list-move, .fc-sec-move { transition: transform 0.45s cubic-bezier(0.25, 0.9, 0.3, 1); }
.fc-list-enter-active { transition: opacity 0.35s ease, transform 0.4s cubic-bezier(0.34, 1.3, 0.5, 1); }
.fc-list-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.fc-list-enter-from { opacity: 0; transform: translateY(18px) scale(0.94); }
.fc-list-leave-to { opacity: 0; transform: scale(0.92); }
.fc-sec-enter-active { transition: opacity 0.4s ease, transform 0.45s cubic-bezier(0.25, 0.9, 0.3, 1); }
.fc-sec-leave-active { transition: opacity 0.2s ease; }
.fc-sec-enter-from { opacity: 0; transform: translateY(16px); }
.fc-sec-leave-to { opacity: 0; }
.fc-fade-enter-active, .fc-fade-leave-active { transition: opacity 0.25s ease, transform 0.25s ease; }
.fc-fade-enter-from, .fc-fade-leave-to { opacity: 0; transform: translateY(-6px); }

@keyframes fc-rise { from { opacity: 0; transform: translateY(18px); } }
@keyframes fc-pop { from { opacity: 0; transform: scale(0.4); } }
@keyframes fc-wobble { 0%, 100% { transform: rotate(0); } 25% { transform: rotate(-14deg); } 60% { transform: rotate(10deg); } }
@keyframes fcd-in { from { opacity: 0; transform: translateY(16px) scale(0.98); } }
@media (prefers-reduced-motion: reduce) {
  .fc-page > *, .fc-family, .fc-search-x, .fc-fam-alloff, .fc-empty-ico { animation: none !important; }
  .fc-list-move, .fc-sec-move, .fc-list-enter-active, .fc-list-leave-active, .fc-sec-enter-active, .fc-sec-leave-active, .fc-seg-pill, .fc-fam-body, .fc-fam-bar i { transition: none !important; }
}

.fc-recon { margin-bottom: 1rem; padding: 1rem 1.1rem; display: grid; gap: 0.8rem; }
.fc-recon-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.7rem; } .fc-recon-head h3 { margin: 0; font-size: 0.95rem; font-weight: 800; } .fc-recon-head p { margin: 2px 0 0; font-size: 0.8rem; color: var(--lg-faint); }
.fc-recon-sum { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; margin: 0; padding: 0.55rem 0.8rem; border-radius: 12px; font-size: 0.85rem; font-weight: 800; } .fc-recon-sum small { margin-left: auto; font-weight: 600; opacity: 0.8; } .fc-recon-sum.is-ok { color: #047857; background: rgba(16, 185, 129, 0.1); } .fc-recon-sum.is-bad { color: #b45309; background: rgba(245, 158, 11, 0.12); }
.fc-recon-group b { display: flex; align-items: center; gap: 0.5rem; font-size: 0.8rem; } .fc-recon-group em { font-style: normal; font-size: 0.68rem; padding: 1px 8px; border-radius: 999px; background: var(--lg-soft); box-shadow: inset 0 0 0 1px var(--lg-line); } .fc-recon-group ul { margin: 0.3rem 0 0; padding-left: 1.1rem; display: flex; flex-wrap: wrap; gap: 0.2rem 1.4rem; font-size: 0.78rem; color: var(--lg-mute); }
</style>

<template>
  <teleport to="body">
    <div v-if="show" class="rl-layer">
      <div class="rl-backdrop" @click="close"></div>

      <div class="rauncher-panel rl-panel" role="dialog" aria-modal="true" :aria-label="'Launcher'">
        <div class="rl-search">
          <span class="rl-search-ico"><icons name="search" className="w-5 h-5" /></span>
          <input
            ref="input"
            v-model="query"
            type="text"
            autocomplete="off"
            spellcheck="false"
            placeholder="Search or type category/query… (e.g. items/cement)"
            class="rl-input"
            @keydown="onKeydown"
          />
          <kbd class="rl-kbd">Esc</kbd>
        </div>

        <div class="rl-body">
          <div v-if="!query.trim()" class="rl-empty">
            <span class="rl-empty-ico"><icons name="sparkles" className="w-6 h-6" /></span>
            <p>
              Type to search pages &amp; reports, or open a record with
              <code>category/query</code> (e.g. <code>items/cement</code>), or paste a transaction reference directly.
            </p>
          </div>

          <template v-else>
            <div v-for="group in groupedResults" :key="group.label" class="rl-group">
              <div class="rl-group-label">{{ group.label }}</div>
              <button
                v-for="result in group.items"
                :key="result.key"
                type="button"
                class="rl-row"
                :class="{ 'rl-row-on bg-white/70': result.key === selectedKey }"
                @click="openResult(result)"
                @mousemove="selectedKey = result.key"
              >
                <span class="rl-row-ico"><icons :name="result.icon" className="w-4 h-4" /></span>
                <span class="min-w-0 flex-1">
                  <span class="rl-row-title">{{ result.title }}</span>
                  <span v-if="result.subtitle" class="rl-row-sub">{{ result.subtitle }}</span>
                </span>
                <span class="rl-row-go" aria-hidden="true">&#8629;</span>
              </button>
            </div>

            <div v-if="loading" class="rl-state">Searching…</div>
            <div v-else-if="permissionMessage" class="rl-state rl-state-warn">
              <icons name="lock-closed" className="w-4 h-4 flex-none" />
              <span>{{ permissionMessage }}</span>
            </div>
            <div v-else-if="!flatResults.length" class="rl-state">No matches found.</div>
          </template>
        </div>

        <div class="rl-foot">
          <span><kbd>&uarr;</kbd><kbd>&darr;</kbd> navigate</span>
          <span><kbd>&crarr;</kbd> open</span>
          <span><kbd>Esc</kbd> close</span>
        </div>
      </div>
    </div>
  </teleport>
</template>

<script>
import debounce from 'lodash/debounce';
import { searchMenu } from '@/Core/menuSearch';
import { resolveCategory } from './categories';

export default {
  props: {
    show: { type: Boolean, default: false },
  },

  emits: ['close'],

  data() {
    return {
      query: '',
      pageResults: [],
      dataResults: [],
      loading: false,
      selectedKey: null,
      requestToken: 0,
      permissionMessage: null,
    };
  },

  computed: {
    parsed() {
      const slashIndex = this.query.indexOf('/');
      if (slashIndex === -1) {
        return { category: null, term: this.query };
      }
      return {
        category: resolveCategory(this.query.slice(0, slashIndex)),
        term: this.query.slice(slashIndex + 1),
      };
    },

    flatResults() {
      if (this.parsed.category) {
        return this.dataResults;
      }
      return [...this.pageResults, ...this.dataResults];
    },

    groupedResults() {
      const groups = [];
      const byLabel = {};
      for (const result of this.flatResults) {
        if (!byLabel[result.label]) {
          byLabel[result.label] = { label: result.label, items: [] };
          groups.push(byLabel[result.label]);
        }
        byLabel[result.label].items.push(result);
      }
      return groups;
    },
  },

  watch: {
    show(value) {
      if (value) {
        this.query = '';
        this.pageResults = [];
        this.dataResults = [];
        this.selectedKey = null;
        this.permissionMessage = null;
        this.$nextTick(() => this.$refs.input && this.$refs.input.focus());
      }
    },

    query() {
      this.runSearch();
    },
  },

  created() {
    this.debouncedDataSearch = debounce(this.fetchDataResults, 200);
  },

  methods: {
    close() {
      this.$emit('close');
    },

    runSearch() {
      const { category, term } = this.parsed;
      this.permissionMessage = null;

      if (!term.trim() && !category) {
        this.pageResults = [];
        this.dataResults = [];
        return;
      }

      this.pageResults = category
        ? []
        : searchMenu(this.query, {
            t: this.$t,
            can: this.$can,
            route: this.route,
            isAdmin: this.$admin,
            features: this.$page.props.enabledFeatures || {},
            roles: (this.$page.props.auth?.user?.roles || []).map(r => r.name),
          }).map(r => ({ ...r, key: `page:${r.url}` }));

      if (term.trim().length >= 2 || category) {
        this.debouncedDataSearch(term, category);
      } else {
        this.dataResults = [];
      }
    },

    fetchDataResults(term, category) {
      if (!term.trim()) {
        this.dataResults = [];
        return;
      }

      const token = ++this.requestToken;
      this.loading = true;
      window.axios
        .get(this.route('rauncher.search'), { params: { q: term, category: category || undefined } })
        .then(res => {
          if (token !== this.requestToken) return;
          this.dataResults = (res.data.results || []).map(r => ({ ...r, key: `${r.category}:${r.url}` }));
          this.permissionMessage = res.data.message || null;
        })
        .finally(() => {
          if (token === this.requestToken) this.loading = false;
        });
    },

    onKeydown(e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        this.close();
        return;
      }

      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        const flat = this.flatResults;
        if (!flat.length) return;
        const currentIndex = flat.findIndex(r => r.key === this.selectedKey);
        const delta = e.key === 'ArrowDown' ? 1 : -1;
        const nextIndex = ((currentIndex === -1 ? -1 : currentIndex) + delta + flat.length) % flat.length;
        this.selectedKey = flat[nextIndex].key;
        return;
      }

      if (e.key === 'Enter') {
        e.preventDefault();
        const selected = this.flatResults.find(r => r.key === this.selectedKey) || this.flatResults[0];
        if (selected) this.openResult(selected);
      }
    },

    openResult(result) {
      this.close();
      if (result.action === 'logout') {
        this.$inertia.post(this.route('logout'));
        return;
      }
      window.location.assign(result.url);
    },
  },
};
</script>

<style scoped>
.rl-layer { position: fixed; inset: 0; z-index: 2147482000; display: flex; align-items: flex-start; justify-content: center; padding: 96px 16px 16px; }
.rl-backdrop { position: absolute; inset: 0; background: linear-gradient(160deg, rgba(79, 70, 229, 0.28), rgba(236, 72, 153, 0.16) 55%, rgba(14, 165, 233, 0.22)); -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px); }
.rl-panel {
  position: relative; width: 100%; max-width: 640px; border-radius: 28px; overflow: hidden;
  background: linear-gradient(150deg, rgba(255, 255, 255, 0.74), rgba(224, 231, 255, 0.58) 55%, rgba(251, 207, 232, 0.44));
  -webkit-backdrop-filter: blur(30px) saturate(190%); backdrop-filter: blur(30px) saturate(190%);
  border: 1.5px solid rgba(255, 255, 255, 0.9);
  box-shadow: 0 40px 90px -26px rgba(49, 46, 129, 0.6), 0 12px 24px -12px rgba(15, 23, 42, 0.25), inset 0 1.5px 1px rgba(255, 255, 255, 0.98), inset 0 -18px 40px rgba(129, 140, 248, 0.1);
  animation: rl-in 0.18s ease-out;
}
@keyframes rl-in { from { opacity: 0; transform: translateY(-10px) scale(0.985); } to { opacity: 1; transform: none; } }
.rl-search { display: flex; align-items: center; gap: 12px; margin: 14px 14px 6px; padding: 0 14px; height: 54px; border-radius: 18px; background: rgba(255, 255, 255, 0.62); border: 1px solid rgba(255, 255, 255, 0.95); box-shadow: inset 0 1px 1px #fff, 0 12px 22px -14px rgba(49, 46, 129, 0.5); }
.rl-search:focus-within { box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.25), 0 12px 22px -14px rgba(49, 46, 129, 0.5); }
.rl-search-ico { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 12px; flex: none; color: #fff; background: linear-gradient(145deg, #818cf8, #4338ca); box-shadow: 0 10px 16px -8px #4338ca; }
.rl-input { flex: 1; min-width: 0; border: 0 !important; outline: none !important; box-shadow: none !important; background: transparent; font-size: 1rem; color: #0f172a; padding: 0 !important; }
.rl-input::placeholder { color: #94a3b8; }
.rl-kbd, .rl-foot kbd { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; padding: 1px 7px; margin-right: 3px; border-radius: 7px; font-size: 0.66rem; font-weight: 700; color: #475569; background: rgba(255, 255, 255, 0.8); border: 1px solid rgba(255, 255, 255, 0.95); box-shadow: 0 2px 0 rgba(148, 163, 184, 0.4), inset 0 1px 1px #fff; font-family: inherit; }
.rl-body { max-height: min(54vh, 420px); overflow-y: auto; padding: 6px 10px 10px; overscroll-behavior: contain; }
.rl-empty { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 26px 22px; text-align: center; font-size: 0.84rem; color: #64748b; }
.rl-empty-ico { display: inline-flex; align-items: center; justify-content: center; width: 52px; height: 52px; border-radius: 18px; color: #4338ca; background: rgba(255, 255, 255, 0.7); border: 1px solid #fff; box-shadow: inset 0 1px 1px #fff, 0 14px 22px -14px rgba(67, 56, 202, 0.6); }
.rl-empty code { padding: 1px 6px; border-radius: 6px; background: rgba(255, 255, 255, 0.7); color: #4338ca; font-size: 0.78rem; }
.rl-group { margin-top: 6px; }
.rl-group-label { padding: 8px 12px 4px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; color: #6366f1; }
.rl-row { display: flex; align-items: center; gap: 12px; width: 100%; padding: 8px 10px; border-radius: 16px; border: 1px solid transparent; text-align: left; background: transparent; cursor: pointer; transition: background 0.12s ease, border-color 0.12s ease, box-shadow 0.12s ease, transform 0.12s ease; }
.rl-row-ico { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 12px; flex: none; color: #4f46e5; background: rgba(255, 255, 255, 0.6); border: 1px solid rgba(255, 255, 255, 0.95); box-shadow: inset 0 1px 1px #fff; }
.rl-row-title { display: block; font-size: 0.9rem; font-weight: 700; color: #0f172a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rl-row-sub { display: block; font-size: 0.74rem; color: #64748b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rl-row-go { opacity: 0; color: #4f46e5; font-weight: 800; transition: opacity 0.12s ease; }
.rl-row:hover, .rl-row-on { background: rgba(255, 255, 255, 0.78) !important; border-color: rgba(255, 255, 255, 0.98); box-shadow: 0 14px 24px -16px rgba(49, 46, 129, 0.6), inset 0 1px 1px #fff; }
.rl-row-on { transform: translateX(2px); }
.rl-row-on .rl-row-ico, .rl-row:hover .rl-row-ico { color: #fff; background: linear-gradient(145deg, #818cf8, #4338ca); border-color: transparent; box-shadow: 0 10px 16px -8px #4338ca; }
.rl-row-on .rl-row-go, .rl-row:hover .rl-row-go { opacity: 1; }
.rl-state { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 22px; font-size: 0.84rem; color: #64748b; }
.rl-state-warn { color: #b45309; }
.rl-foot { display: flex; gap: 16px; flex-wrap: wrap; padding: 10px 18px 12px; font-size: 0.7rem; color: #64748b; border-top: 1px solid rgba(255, 255, 255, 0.75); background: rgba(255, 255, 255, 0.28); }
@media (max-width: 640px) { .rl-layer { padding-top: 64px; } .rl-foot { display: none; } }
html.dark .rl-panel { background: linear-gradient(150deg, rgba(30, 41, 59, 0.82), rgba(49, 46, 129, 0.5)); border-color: rgba(255, 255, 255, 0.2); }
html.dark .rl-search, html.dark .rl-row-ico { background: rgba(15, 23, 42, 0.5); border-color: rgba(255, 255, 255, 0.14); }
html.dark .rl-input, html.dark .rl-row-title { color: #e2e8f0; }
html.dark .rl-row:hover, html.dark .rl-row-on { background: rgba(255, 255, 255, 0.1) !important; border-color: rgba(255, 255, 255, 0.2); }
@media (prefers-reduced-motion: reduce) { .rl-panel { animation: none; } }
</style>

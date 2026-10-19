<template>
  <admin-layout :title="$t('QR Activation')">
    <div class="lg-page px-4 md:px-0 qa">
      <page-header icon="toggle" :title="$t('QR Activation')" :subtitle="$t('Choose which modules carry QR codes. A module that is off shows no QR button, prints its documents without a code, and a scan of its old codes is refused politely.')">
        <button type="button" class="lg-btn lg-btn-soft" :disabled="busy || !stats.off" @click="send(allTypes, true)"><icons name="tick" class="w-4 h-4" />{{ $t('Enable all') }}</button>
        <button type="button" class="lg-btn lg-btn-soft" :disabled="busy || !stats.on" @click="send(allTypes, false)"><icons name="cross" class="w-4 h-4" />{{ $t('Disable all') }}</button>
      </page-header>

      <stat-tiles :items="tiles" />

      <div class="qa-search">
        <icons name="search" class="w-5 h-5" />
        <input v-model="q" type="search" :placeholder="$t('Search a module...')" :aria-label="$t('Search a module')" />
        <button v-if="q" type="button" class="qa-clear" :aria-label="$t('Clear')" @click="q = ''"><icons name="cross" class="w-4 h-4" /></button>
        <span class="qa-count">{{ shownCount }} {{ $t('of') }} {{ stats.kinds }}</span>
      </div>
      <p v-if="!visibleGroups.length" class="qa-none"><icons name="search" class="w-5 h-5" />{{ $t('No module matches') }} "{{ q }}"</p>

      <section v-for="g in visibleGroups" :key="g.name" class="lg-card qa-group" :class="'tone-' + (g.gi % 6)" :style="{ '--g': g.gi }">
        <header class="qa-head">
          <span class="qa-gico"><icons name="collection" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1"><h2>{{ g.name }}</h2><p>{{ activeCount(g) }} {{ $t('of') }} {{ g.items.length }} {{ $t('on') }}<template v-if="q"> · {{ g.shown.length }} {{ $t('shown') }}</template></p></div>
          <button type="button" class="qa-mini is-on" :disabled="busy" @click="send(typesOf(g), true)"><icons name="tick" class="w-4 h-4" />{{ $t('All on') }}</button>
          <button type="button" class="qa-mini is-off" :disabled="busy" @click="send(typesOf(g), false)"><icons name="cross" class="w-4 h-4" />{{ $t('All off') }}</button>
        </header>
        <ul class="qa-list">
          <li v-for="(m, mi) in g.shown" :key="m.type" :class="{ 'is-off': !m.active }" :style="{ '--i': mi }">
            <span class="qa-ico"><icons :name="m.icon" class="w-5 h-5" /><i class="qa-dot" :class="m.active ? 'is-on' : 'is-off'"></i></span>
            <span class="qa-name"><b>{{ m.label }}</b><small>{{ m.codes }} {{ $t('codes') }} · {{ m.scans }} {{ $t('scans') }}</small></span>
            <span class="qa-state" :class="m.active ? 'is-on' : 'is-off'">{{ m.active ? $t('Enabled') : $t('Disabled') }}</span>
            <label class="qa-sw lg-tip" :data-tip="m.active ? $t('Switch off') : $t('Switch on')"><input type="checkbox" :checked="m.active" :disabled="busy" :aria-label="m.label" @change="send([m.type], $event.target.checked)" /><span class="qa-track"></span></label>
          </li>
        </ul>
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
  props: { groups: Array, stats: Object },
  data() {
    return { busy: false, q: '' };
  },
  computed: {
    // search as you type: the modules that match (by name or group), and only the groups that still have one
    visibleGroups() {
      const n = this.q.trim().toLowerCase();
      return this.groups.map((g, gi) => ({ ...g, gi, shown: n ? g.items.filter(m => (m.label + ' ' + g.name).toLowerCase().includes(n)) : g.items })).filter(g => g.shown.length);
    },
    shownCount() {
      return this.visibleGroups.reduce((a, g) => a + g.shown.length, 0);
    },
    allTypes() {
      return this.groups.flatMap(g => g.items.map(m => m.type));
    },
    tiles() {
      return [
        { icon: 'collection', tone: 'indigo', label: this.$t('Kinds of record'), value: this.stats.kinds },
        { icon: 'tick', tone: 'emerald', label: this.$t('Enabled'), value: this.stats.on },
        { icon: 'cross', tone: 'rose', label: this.$t('Disabled'), value: this.stats.off },
      ];
    },
  },
  methods: {
    typesOf(g) {
      return g.items.map(m => m.type);
    },
    activeCount(g) {
      return g.items.filter(m => m.active).length;
    },
    send(kinds, active) {
      this.busy = true;
      this.$inertia.put(route('qr-codes.activation.update'), { kinds, active }, { preserveScroll: true, onFinish: () => (this.busy = false) });
    },
  },
};
</script>

<style scoped>
.qa-group { margin-bottom: 1.4rem; animation: qa-rise 0.55s cubic-bezier(0.2, 0.8, 0.2, 1) both; animation-delay: calc(var(--g, 0) * 110ms + 120ms); --t1: #6366f1; --t2: #0ea5e9; }
.qa-group.tone-1 { --t1: #10b981; --t2: #14b8a6; } .qa-group.tone-2 { --t1: #f59e0b; --t2: #f97316; } .qa-group.tone-3 { --t1: #ec4899; --t2: #a855f7; } .qa-group.tone-4 { --t1: #0ea5e9; --t2: #2563eb; } .qa-group.tone-5 { --t1: #e11d48; --t2: #f97316; }
@keyframes qa-rise { from { opacity: 0; transform: translateY(26px) scale(0.98); } }
@keyframes qa-pop { from { opacity: 0; transform: translateY(14px) scale(0.9); } 60% { transform: translateY(-2px) scale(1.02); } }
@keyframes qa-spin-in { from { transform: rotate(-90deg) scale(0.4); opacity: 0; } }
.qa-head { display: flex; align-items: center; flex-wrap: wrap; gap: 0.7rem; margin-bottom: 1.1rem; }
.qa-gico { flex: none; display: inline-grid; place-items: center; width: 42px; height: 42px; border-radius: 14px; color: #fff; background: linear-gradient(145deg, var(--t1), var(--t2)); box-shadow: 0 14px 22px -12px var(--t2); animation: qa-spin-in 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) both; animation-delay: calc(var(--g, 0) * 110ms + 300ms); }
.qa-head h2 { margin: 0; font-size: 1rem; font-weight: 800; color: var(--lg-ink); } .qa-head p { margin: 1px 0 0; font-size: 0.74rem; color: var(--lg-faint); }
.qa-mini { display: inline-flex; align-items: center; gap: 6px; padding: 0.45rem 1rem 0.45rem 0.7rem; border-radius: 9999px; font-size: 0.76rem; font-weight: 800; white-space: nowrap; transition: transform 0.15s ease, box-shadow 0.15s ease; } .qa-mini:hover:not(:disabled) { transform: translateY(-1px); } .qa-mini:disabled { opacity: 0.5; }
.qa-mini.is-on { color: #065f46; background: linear-gradient(145deg, #ecfdf5, #d1fae5); box-shadow: 0 10px 16px -10px rgba(5, 150, 105, 0.8), 0 0 0 1px rgba(16, 185, 129, 0.4); }
.qa-mini.is-off { color: #9f1239; background: linear-gradient(145deg, #fff1f2, #ffe4e6); box-shadow: 0 10px 16px -10px rgba(225, 29, 72, 0.7), 0 0 0 1px rgba(244, 63, 94, 0.35); }
html.dark .qa-mini.is-on { color: #6ee7b7; background: rgba(16, 185, 129, 0.16); } html.dark .qa-mini.is-off { color: #fda4af; background: rgba(244, 63, 94, 0.16); }
.qa-search { position: relative; display: flex; align-items: center; gap: 0.7rem; margin-bottom: 1.2rem; padding: 0.35rem 0.5rem 0.35rem 1rem; border-radius: 18px; color: var(--lg-faint); background: var(--lg-card); box-shadow: 0 16px 24px -16px rgba(15, 37, 64, 0.6), 0 0 0 1px var(--lg-line); transition: box-shadow 0.2s ease; } .qa-search:focus-within { box-shadow: 0 16px 24px -16px rgba(67, 56, 202, 0.55), 0 0 0 2px #818cf8; color: #4f46e5; }
.qa-search input { flex: 1; min-width: 0; height: 42px; border: 0; background: transparent; color: var(--lg-ink); font-size: 0.95rem; outline: none; box-shadow: none !important; }
.qa-clear { display: grid; place-items: center; width: 30px; height: 30px; border-radius: 10px; color: var(--lg-mute); background: var(--lg-soft); } .qa-count { flex: none; padding: 3px 11px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800; color: #4338ca; background: rgba(99, 102, 241, 0.12); white-space: nowrap; }
.qa-none { display: flex; align-items: center; justify-content: center; gap: 10px; padding: 2.4rem 1rem; text-align: center; font-size: 0.95rem; color: var(--lg-faint); }
.qa-list { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(min(320px, 100%), 1fr)); gap: 1.05rem 0.9rem; }
.qa-list li { display: flex; align-items: center; gap: 0.7rem; min-width: 0; padding: 0.8rem 0.95rem; border-radius: 16px; background: var(--lg-card); box-shadow: 0 12px 18px -14px rgba(15, 37, 64, 0.55), 0 0 0 1px var(--lg-line); transition: opacity 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease; animation: qa-pop 0.5s cubic-bezier(0.2, 0.8, 0.2, 1) both; animation-delay: calc(var(--g, 0) * 110ms + var(--i, 0) * 45ms + 380ms); } .qa-list li:hover { transform: translateY(-2px); box-shadow: 0 18px 24px -14px rgba(15, 37, 64, 0.6), 0 0 0 1px var(--t1); } .qa-list li.is-off { opacity: 0.78; } .qa-list li.is-off .qa-ico { filter: grayscale(1); opacity: 0.7; }
.qa-ico { position: relative; flex: none; display: inline-grid; place-items: center; width: 42px; height: 42px; border-radius: 13px; color: #fff; background: linear-gradient(145deg, var(--t1), var(--t2)); box-shadow: 0 10px 16px -10px var(--t2); transition: filter 0.2s ease, opacity 0.2s ease; }
.qa-dot { position: absolute; right: -3px; top: -3px; width: 12px; height: 12px; border-radius: 9999px; border: 2px solid var(--lg-card); } .qa-dot.is-on { background: #10b981; } .qa-dot.is-off { background: #94a3b8; }
.qa-name { flex: 1; min-width: 0; display: grid; line-height: 1.25; } .qa-name b { font-size: 0.88rem; color: var(--lg-ink); overflow-wrap: anywhere; } .qa-name small { font-size: 0.7rem; color: var(--lg-faint); }
.qa-state { flex: none; padding: 2px 10px; border-radius: 9999px; font-size: 0.68rem; font-weight: 800; } .qa-state.is-on { color: #065f46; background: #d1fae5; } .qa-state.is-off { color: #475569; background: #e2e8f0; }
.qa-sw { flex: none; position: relative; cursor: pointer; } .qa-sw input { position: absolute; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
.qa-track { display: block; width: 42px; height: 24px; border-radius: 9999px; background: #cbd5e1; box-shadow: inset 0 1px 3px rgba(15, 37, 64, 0.3); transition: background-color 0.2s ease; position: relative; }
.qa-track::after { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 9999px; background: #fff; box-shadow: 0 2px 5px rgba(15, 37, 64, 0.35); transition: transform 0.2s ease; }
.qa-sw input:checked + .qa-track { background: linear-gradient(145deg, #34d399, #059669); } .qa-sw input:checked + .qa-track::after { transform: translateX(18px); } .qa-sw input:focus-visible + .qa-track { outline: 3px solid rgba(99, 102, 241, 0.4); outline-offset: 2px; }
html.dark .qa-state.is-on { color: #6ee7b7; background: rgba(16, 185, 129, 0.18); } html.dark .qa-state.is-off { color: #cbd5e1; background: rgba(148, 163, 184, 0.2); }
@media (prefers-reduced-motion: reduce) { .qa-track, .qa-track::after, .qa-list li, .qa-group, .qa-gico { transition: none; animation: none; } }

.qa-search input::-webkit-search-cancel-button { display: none; -webkit-appearance: none; }
/* glow outline: a soft light that travels round the edge of every enabled module card, and brightens on hover */
@property --qa-a { syntax: '<angle>'; initial-value: 0deg; inherits: false; }
.qa-list li { position: relative; isolation: isolate; }
.qa-list li::before { content: ''; position: absolute; inset: -1.5px; z-index: -1; border-radius: inherit; padding: 1.5px; background: conic-gradient(from var(--qa-a), transparent 0deg, transparent 250deg, var(--t1) 300deg, var(--t2) 340deg, transparent 360deg); -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0); -webkit-mask-composite: xor; mask-composite: exclude; opacity: 0.85; animation: qa-orbit 4.5s linear infinite; animation-delay: calc(var(--i, 0) * -0.35s); }
.qa-list li::after { content: ''; position: absolute; inset: 0; z-index: -2; border-radius: inherit; box-shadow: 0 0 18px 1px var(--t2); opacity: 0.22; animation: qa-breathe 3.6s ease-in-out infinite; animation-delay: calc(var(--i, 0) * -0.3s); pointer-events: none; }
.qa-list li:hover::before { opacity: 1; animation-duration: 1.8s; } .qa-list li:hover::after { opacity: 0.5; }
.qa-list li.is-off::before, .qa-list li.is-off::after { display: none; }
@keyframes qa-orbit { to { --qa-a: 360deg; } }
@keyframes qa-breathe { 0%, 100% { opacity: 0.1; } 50% { opacity: 0.3; } }
@media (prefers-reduced-motion: reduce) { .qa-list li::before, .qa-list li::after { animation: none; } }
</style>

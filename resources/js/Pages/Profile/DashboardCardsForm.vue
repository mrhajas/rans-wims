<template>
  <section class="lg-card dcf">
    <header class="lg-card-head">
      <span class="lg-card-ico lg-ico-sky"><icons name="collection" class="w-5 h-5" /></span>
      <div class="min-w-0">
        <h2 class="lg-card-title">{{ $t('Dashboard cards') }}</h2>
        <p class="lg-card-sub">{{ $t('Choose the shortcut cards on your own dashboard. Only the main modules and dashboards you are allowed to open are offered.') }}</p>
      </div>
      <span class="pf-head-chip lg-chip" :class="custom ? 'lg-chip-green' : 'lg-chip-slate'">{{ custom ? $t('Your own') : $t('Standard') }}</span>
    </header>

    <!-- the stack as it will look on the dashboard: drag a tile to arrange it, every change is saved at once -->
    <div class="dcf-head">
      <h3>{{ $t('Your stack') }} <em>{{ keys.length }} / {{ max }}</em></h3>
      <span v-if="replacing !== null" class="dcf-hint"><icons name="refresh" class="w-3.5 h-3.5" />{{ $t('Pick the card that takes its place below') }}
        <button type="button" @click="replacing = null">{{ $t('Cancel') }}</button></span>
      <span v-else class="dcf-state" :class="{ 'is-busy': busy }"><template v-if="busy">{{ $t('Saving…') }}</template><template v-else-if="justSaved"><icons name="tick" class="w-3.5 h-3.5" />{{ $t('Saved on your dashboard') }}</template><template v-else>{{ $t('Drag the cards to arrange them') }}</template></span>
    </div>
    <div
      class="dcf-stack"
      :class="{ 'is-drop': poolDrag, 'is-full': poolDrag && keys.length >= max }"
      @dragover.prevent="poolDrag && (overIndex = null)"
      @drop.prevent="onStackDrop"
    >
      <p v-if="!stack.length" class="dcf-empty">{{ $t('No cards yet. Add one from the list below.') }}</p>
      <p v-if="poolDrag" class="dcf-dropnote">{{ keys.length >= max ? $t('Full: drop on a card to replace it') : $t('Drop here to add the card') }}</p>
      <div
        v-for="(c, i) in stack"
        :key="c.key"
        class="dcf-tile"
        :class="{ 'is-replacing': replacing === i, 'is-over': overIndex === i && dragIndex !== i, 'is-dragging': dragIndex === i }"
        draggable="true"
        @dragstart="onDragStart($event, i)"
        @dragover.prevent="overIndex = i"
        @dragleave="overIndex === i && (overIndex = null)"
        @drop.prevent.stop="onDrop(i)"
        @dragend="onDragEnd"
      >
        <span class="dcf-grip lg-tip" :data-tip="$t('Drag to move')" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></span>
        <span class="dcf-ico"><icons :name="c.icon" class="w-5 h-5" /></span>
        <span class="dcf-text"><b>{{ $t(c.title) }}</b><small>{{ $t(c.description) }}</small></span>
        <span class="dcf-ctl">
          <button type="button" class="lg-tip" :data-tip="$t('Move earlier')" :aria-label="$t('Move earlier')" :disabled="i === 0" @click="move(i, -1)"><icons name="chevron-down" class="w-4 h-4 rotate-90" /></button>
          <button type="button" class="lg-tip" :data-tip="$t('Move later')" :aria-label="$t('Move later')" :disabled="i === stack.length - 1" @click="move(i, 1)"><icons name="chevron-down" class="w-4 h-4 -rotate-90" /></button>
          <button type="button" class="lg-tip" :class="{ on: replacing === i }" :data-tip="replacing === i ? $t('Cancel replacing') : $t('Replace with another card')" :aria-label="$t('Replace')" @click="replacing = replacing === i ? null : i"><icons name="refresh" class="w-4 h-4" /></button>
          <button type="button" class="lg-tip" :data-tip="$t('Remove from my dashboard')" :aria-label="$t('Remove')" :disabled="stack.length <= 1" @click="remove(i)"><icons name="cross" class="w-4 h-4" /></button>
        </span>
      </div>
    </div>

    <!-- what can be added -->
    <div class="dcf-head mt-5">
      <h3>{{ replacing !== null ? $t('Replace with') : $t('Add a card') }}</h3>
      <div class="dcf-search"><icons name="search" class="w-3.5 h-3.5" /><input v-model="q" type="text" :placeholder="$t('Search modules..')" /></div>
    </div>
    <div class="dcf-pool">
      <button v-for="c in pool" :key="c.key" type="button" class="dcf-pick lg-tip" draggable="true" @dragstart="onPoolDragStart($event, c)" @dragend="onDragEnd" :data-tip="replacing !== null ? $t('Use this card in the chosen place') : $t('Add to my dashboard')" :disabled="replacing === null && keys.length >= max" @click="pick(c)">
        <span class="dcf-pico"><icons :name="c.icon" class="w-4 h-4" /></span>
        <span class="dcf-ptext"><b>{{ $t(c.title) }}</b><small>{{ $t(c.description) }}</small></span>
        <icons :name="replacing !== null ? 'refresh' : 'plus'" class="w-4 h-4 dcf-pplus" />
      </button>
      <p v-if="!pool.length" class="dcf-empty">{{ q ? $t('No module matches.') : $t('Every module you can open is already on your dashboard.') }}</p>
    </div>
    <p v-if="replacing === null && keys.length >= max" class="dcf-note">{{ $t('Your dashboard holds up to') }} {{ max }} {{ $t('cards. Remove or replace one to change the stack.') }}</p>

    <div v-if="custom" class="pf-foot" style="justify-content: flex-start">
      <button type="button" class="lg-btn lg-btn-soft" :disabled="busy" @click="reset"><icons name="refresh" class="w-4 h-4" />{{ $t('Back to standard cards') }}</button>
    </div>
  </section>
</template>

<script>
import { cardCatalog, canOpenCard, savedCardKeys, defaultKeysFor, MAX_CARDS } from '@/Core/dashboardCards';

export default {
  data() {
    return { keys: [], q: '', replacing: null, busy: false, max: MAX_CARDS, dragIndex: null, overIndex: null, poolDrag: null, justSaved: false, timer: null, savedTimer: null };
  },
  computed: {
    catalog() {
      return cardCatalog().filter(c => canOpenCard(this, c));
    },
    byKey() {
      return Object.fromEntries(this.catalog.map(c => [c.key, c]));
    },
    custom() {
      return !!savedCardKeys(this);
    },
    stack() {
      return this.keys.map(k => this.byKey[k]).filter(Boolean);
    },
    pool() {
      const q = this.q.trim().toLowerCase();
      return this.catalog.filter(c => !this.keys.includes(c.key)).filter(c => !q || (this.$t(c.title) + ' ' + this.$t(c.description)).toLowerCase().includes(q));
    },
  },
  created() {
    this.load();
  },
  methods: {
    load() {
      const saved = savedCardKeys(this);
      const base = saved || defaultKeysFor(this);
      this.keys = base.filter(k => this.byKey[k]).slice(0, this.max);
    },
    onDragStart(e, i) {
      this.dragIndex = i;
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', String(i)); // Firefox needs data to start a drag
    },
    // a card dragged out of "Add a card": dropped on the stack it is added; dropped on a card it goes in front of it (or takes its place when the stack is full)
    onPoolDragStart(e, c) {
      this.poolDrag = c.key;
      this.dragIndex = null;
      e.dataTransfer.effectAllowed = 'copy';
      e.dataTransfer.setData('text/plain', 'pool:' + c.key);
    },
    addFromPool(key, at) {
      if (!this.byKey[key] || this.keys.includes(key)) return;
      const k = [...this.keys];
      if (at === null || at === undefined) {
        if (k.length >= this.max) return;
        k.push(key);
      } else if (k.length >= this.max) {
        k[at] = key; // full: replace the card it was dropped on
      } else {
        k.splice(at, 0, key);
      }
      this.keys = k;
      this.commit();
    },
    onStackDrop() {
      const key = this.poolDrag;
      this.onDragEnd();
      if (key) this.addFromPool(key, null);
    },
    onDrop(i) {
      if (this.poolDrag) {
        const key = this.poolDrag;
        this.onDragEnd();
        this.addFromPool(key, i);
        return;
      }
      const from = this.dragIndex;
      this.onDragEnd();
      if (from === null || from === i) return;
      const k = [...this.keys];
      const [moved] = k.splice(from, 1);
      k.splice(i, 0, moved);
      this.keys = k;
      this.commit();
    },
    onDragEnd() {
      this.dragIndex = null;
      this.overIndex = null;
      this.poolDrag = null;
    },
    // every change is saved straight away (a moment after the last one), so the dashboard always matches this page
    commit() {
      clearTimeout(this.timer);
      this.justSaved = false;
      this.timer = setTimeout(this.save, 350);
    },
    move(i, d) {
      const j = i + d;
      if (j < 0 || j >= this.keys.length) return;
      const k = [...this.keys];
      [k[i], k[j]] = [k[j], k[i]];
      this.keys = k;
      this.commit();
    },
    remove(i) {
      if (this.keys.length <= 1) return;
      this.keys = this.keys.filter((_, n) => n !== i);
      this.replacing = null;
      this.commit();
    },
    pick(c) {
      if (this.replacing !== null) {
        const k = [...this.keys];
        k[this.replacing] = c.key;
        this.keys = k;
        this.replacing = null;
        this.commit();
      } else if (this.keys.length < this.max) {
        this.keys = [...this.keys, c.key];
        this.commit();
      }
    },
    save() {
      if (!this.keys.length) return;
      this.busy = true;
      this.$inertia.put(this.route('dashboard.cards.update'), { keys: this.keys }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
          this.justSaved = true;
          clearTimeout(this.savedTimer);
          this.savedTimer = setTimeout(() => (this.justSaved = false), 2500);
        },
        onFinish: () => (this.busy = false),
      });
    },
    reset() {
      this.busy = true;
      this.$inertia.delete(this.route('dashboard.cards.reset'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => this.load(),
        onFinish: () => (this.busy = false),
      });
    },
  },
  beforeUnmount() {
    clearTimeout(this.timer);
    clearTimeout(this.savedTimer);
  },
};
</script>

<style>
.dcf-head { display: flex; align-items: center; justify-content: space-between; gap: 0.7rem; flex-wrap: wrap; margin: 0 0 0.6rem; } .dcf-head h3 { margin: 0; font-size: 0.92rem; font-weight: 800; color: var(--lg-ink); } .dcf-head h3 em { margin-left: 0.4rem; font-style: normal; font-size: 0.74rem; font-weight: 800; color: var(--lg-faint); }
.dcf-hint { display: inline-flex; align-items: center; gap: 0.4rem; font-size: 0.78rem; font-weight: 700; color: #b45309; } .dcf-hint button { border: 0; background: none; padding: 0; font-weight: 800; color: #b45309; text-decoration: underline; cursor: pointer; font-family: inherit; }
.dcf-state { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.76rem; font-weight: 700; color: var(--lg-faint); } .dcf-state.is-busy { color: #b45309; }
.dcf-stack { display: grid; grid-template-columns: repeat(auto-fill, minmax(215px, 1fr)); gap: 0.6rem; padding: 0.8rem; border-radius: 22px; background: linear-gradient(155deg, #0d9488 0%, #2563eb 52%, #7c3aed 100%); box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.28); }
.dcf-tile { position: relative; display: grid; grid-template-columns: auto auto 1fr; grid-template-areas: 'grip ico text' 'ctl ctl ctl'; align-items: center; gap: 0.5rem 0.55rem; padding: 0.55rem 0.6rem 0.5rem 0.4rem; border-radius: 16px; color: #fff; cursor: grab; background: rgba(255, 255, 255, 0.16); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.3); transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease, opacity 0.15s ease; } .dcf-tile:active { cursor: grabbing; }
.dcf-tile.is-replacing { background: rgba(255, 255, 255, 0.32); box-shadow: 0 0 0 2px #fff; } .dcf-tile.is-over { box-shadow: 0 0 0 3px #fff; transform: scale(1.03); background: rgba(255, 255, 255, 0.28); } .dcf-tile.is-dragging { opacity: 0.45; }
.dcf-grip { grid-area: grip; display: grid; grid-template-columns: repeat(2, 3px); gap: 3px; padding: 0 2px; } .dcf-grip i { width: 3px; height: 3px; border-radius: 50%; background: rgba(255, 255, 255, 0.7); }
.dcf-ico { grid-area: ico; display: grid; place-items: center; width: 38px; height: 38px; border-radius: 50%; background: rgba(255, 255, 255, 0.24); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.4); }
.dcf-text { grid-area: text; min-width: 0; display: grid; line-height: 1.2; } .dcf-text b { font-size: 0.84rem; font-weight: 700; color: #fff; overflow-wrap: anywhere; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3); } .dcf-text small { font-size: 0.7rem; font-weight: 600; color: rgba(255, 255, 255, 0.92); overflow-wrap: anywhere; }
.dcf-ctl { grid-area: ctl; display: flex; justify-content: flex-end; gap: 6px; padding-top: 0.4rem; border-top: 1px solid rgba(255, 255, 255, 0.22); } .app-shell .dcf .dcf-ctl button { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 30px; min-height: 0; padding: 0; border: 0; border-radius: 9px; color: #fff; background: rgba(255, 255, 255, 0.18); cursor: pointer; transition: background 0.15s ease; } .app-shell .dcf .dcf-ctl button:hover:not(:disabled) { background: rgba(255, 255, 255, 0.36); } .app-shell .dcf .dcf-ctl button:disabled { opacity: 0.35; cursor: default; } .app-shell .dcf .dcf-ctl button.on { background: #fff; color: #2563eb; } .app-shell .dcf .dcf-ctl button:last-child:hover:not(:disabled) { background: rgba(244, 63, 94, 0.75); }
.dcf-stack { position: relative; transition: box-shadow 0.15s ease; } .dcf-stack.is-drop { box-shadow: 0 0 0 3px #fff, 0 0 0 6px rgba(37, 99, 235, 0.55), 0 18px 30px -16px rgba(37, 99, 235, 0.7); } .dcf-stack.is-full { box-shadow: 0 0 0 3px #fff, 0 0 0 6px rgba(245, 158, 11, 0.7); }
.dcf-dropnote { grid-column: 1 / -1; margin: 0; padding: 0.45rem 0.8rem; border-radius: 12px; font-size: 0.8rem; font-weight: 800; text-align: center; color: #fff; background: rgba(255, 255, 255, 0.2); border: 1.5px dashed rgba(255, 255, 255, 0.75); }
.dcf-empty { grid-column: 1 / -1; margin: 0; padding: 0.6rem; font-size: 0.82rem; color: var(--lg-faint); } .dcf-stack .dcf-empty { color: #fff; }
.dcf-search { display: flex; align-items: center; gap: 0.4rem; padding: 0.38rem 0.8rem; border: 1px solid var(--lg-line); border-radius: 9999px; background: var(--lg-card); color: var(--lg-faint); } .dcf-search input { border: 0 !important; outline: none; box-shadow: none !important; background: transparent; padding: 0 !important; width: 170px; font-size: 0.82rem; font-family: inherit; color: var(--lg-ink); }
.dcf-pool { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 0.7rem; max-height: 360px; overflow-y: auto; padding: 4px 4px 10px; }
.app-shell .dcf .dcf-pick { display: flex; align-items: center; gap: 0.6rem; width: 100%; min-height: 0; padding: 0.5rem 0.65rem; border: 0; border-radius: 14px; text-align: left; cursor: pointer; font-family: inherit; color: var(--lg-ink); background: var(--lg-card); cursor: grab; box-shadow: inset 0 0 0 1px var(--lg-line), 0 8px 16px -10px rgba(15, 23, 42, 0.45), 0 2px 4px -2px rgba(15, 23, 42, 0.18); transition: box-shadow 0.15s, transform 0.15s; } .app-shell .dcf .dcf-pick:active { cursor: grabbing; } .app-shell .dcf .dcf-pick:hover:not(:disabled) { box-shadow: inset 0 0 0 1px #2563eb, 0 14px 24px -12px rgba(37, 99, 235, 0.55), 0 3px 6px -2px rgba(15, 23, 42, 0.2); transform: translateY(-2px); } .app-shell .dcf .dcf-pick:disabled { opacity: 0.5; cursor: not-allowed; }
.dcf-pico { flex: none; display: grid; place-items: center; width: 32px; height: 32px; border-radius: 10px; color: #1c3f66; background: var(--lg-card); box-shadow: 0 0 0 1px var(--lg-line); }
.dcf-ptext { flex: 1; min-width: 0; display: grid; line-height: 1.2; } .dcf-ptext b { font-size: 0.82rem; font-weight: 800; } .dcf-ptext small { font-size: 0.7rem; color: var(--lg-mute); }
.dcf-ctl button > div, .dcf-pick > div { display: grid; place-items: center; flex: none; }
.dcf-pplus { flex: none; color: #2563eb; }
.dcf-note { margin: 0.5rem 0 0; font-size: 0.78rem; font-weight: 700; color: #b45309; }
@media (max-width: 560px) { .dcf-search input { width: 120px; } .dcf-stack { grid-template-columns: 1fr; } }
@media (prefers-reduced-motion: reduce) { .dcf-tile { transition: none; } }
</style>

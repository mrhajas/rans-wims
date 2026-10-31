<template>
  <aside class="pm" :class="{ 'is-collapsed': collapsed && !mobile, 'is-mobile': mobile, 'is-fly': flyOpen }" :aria-label="$t('Main menu')" @mouseleave="hoverOut" @mouseover="tipOver" @mouseout="tipOut" @focusin="tipOver" @focusout="tipOut" @pointerdown="tipHide" @scroll.capture="tipHide">
    <div class="pm-brand">
      <Link :href="route('dashboard')" class="pm-logo" :aria-label="$t('Dashboard')" :data-tip="$t('Dashboard')"><animated-logo :mark="collapsed && !mobile" /></Link>
    </div>

    <div class="pm-body">
      <!-- the rail: one coloured icon for each group of the menu -->
      <nav ref="rail" class="pm-rail" :aria-label="$t('Menu groups')">
        <ul ref="railList">
          <li v-for="(g, gi) in rail" :key="g.key" :class="['pg-' + (g.tone % 8), { 'is-on': g.key === sel, 'is-here': g.key === hereKey }]" :style="{ '--d': Math.min(gi * 22, 360) + 'ms', '--sh': (gi % 8) * 480 + 'ms' }">
            <button type="button" :data-tip="g.title" :aria-label="g.title" :aria-expanded="g.key === sel && panelShown" @click="pick(g)" @mouseenter="hoverIn(g)">
              <span class="pm-tile"><Icons :name="g.icon || 'collection'" class-name="pm-ic" /></span>
              <i v-if="g.key === hereKey && g.key !== sel" class="pm-here" aria-hidden="true"></i>
            </button>
          </li>
        </ul>
        <button v-if="!mobile" type="button" class="pm-fold" :data-tip="collapsed ? $t('Show menu') : $t('Hide menu')" :aria-label="collapsed ? $t('Show menu') : $t('Hide menu')" @click="$emit('toggle')"><Icons name="chevron-down" class-name="pm-ic pm-ic-s" :class="collapsed ? '-rotate-90' : 'rotate-90'" /></button>
      </nav>

      <!-- the panel: the tree of the chosen group (on a collapsed menu it opens beside the rail, over the page) -->
      <transition name="pm-fly">
        <section v-if="panelShown" ref="panel" class="pm-panel" :class="'pg-' + ((current && current.tone) % 8)" :aria-label="current ? current.title : ''">
          <header class="pm-head">
            <span class="pm-head-ico"><Icons :name="(current && current.icon) || 'collection'" class-name="pm-ic" /></span>
            <div class="min-w-0"><small class="pm-wims" :aria-label="$t('RANS WIMS')"><span v-for="(c, ci) in wimsLetters" :key="ci" aria-hidden="true" :style="{ '--i': ci }">{{ c === ' ' ? '\u00a0' : c }}</span></small><h2>{{ current ? current.title : '' }}</h2></div>
            <button v-if="collapsed && !mobile" type="button" class="pm-close" :data-tip="$t('Close')" :aria-label="$t('Close')" @click="flyOpen = false"><Icons name="cross" class-name="pm-ic pm-ic-s" /></button>
          </header>
          <ul v-if="current" :key="current.key" class="pm-tree">
            <li v-for="(it, i) in current.items" :key="it.name" :class="{ 'is-on': it.active }" :style="{ '--d': Math.min(i * 28, 420) + 'ms' }">
              <Link :href="it.href" class="pm-link" :data-tip="it.title" @click="onNavigate">
                <i class="pm-node" aria-hidden="true"></i>
                <span class="pm-ico"><Icons :name="it.icon || 'link'" class-name="pm-ic pm-ic-m" /></span>
                <span class="pm-txt">{{ it.title }}</span>
                <span v-if="it.off" class="pm-off">{{ $t('Disabled') }}</span>
              </Link>
            </li>
          </ul>
          <div v-if="mobile" class="pm-foot"><icon-menus class="gap-x-6 flex items-center justify-between" /></div>
        </section>
      </transition>
    </div>
    <!-- the tooltip is drawn on the page itself, so no list or panel can cut it and nothing can cover it -->
    <teleport to="body">
      <div v-if="tip.on" ref="tipEl" class="pm-tip" :class="['is-' + tip.side, { 'is-ready': tip.ready }]" :style="{ left: tip.x + 'px', top: tip.y + 'px', '--ax': tip.ax + 'px', '--ay': tip.ay + 'px' }" role="tooltip">{{ tip.text }}</div>
    </teleport>
  </aside>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import Menus from '@/Core/menu';
import sortMenus from '@/Core/sortMenus';
import AnimatedLogo from '@/Shared/AnimatedLogo.vue';
import IconMenus from '@/Shared/Top/IconMenus.vue';
import FullMainMenu from '@/Shared/Sidebar/FullMainMenu.vue';

// The Pro menu: a rail of coloured group icons and, beside it, the tree of the chosen group. The rules of who sees what are exactly those of the classic menu (its own
// functions are used, so a permission, a role or a Feature Control change reads the same in both menus).
const M = FullMainMenu.methods;
const SAME = ['hasIssueReturnRole', 'isIssueReturnMenu', 'isTimelyUsageMenu', 'isReportsParentWithTimely', 'hasTimelyUsageRole', 'hasProjectManagerRole', 'hasProcurementManagerRole', 'isReportsMenu', 'isReportsParent', 'isActivitiesMenu',
  'canAccessMenu', 'allowsRole', 'featureEnabled', 'featureVisible', 'featureDisabled', 'menuHref', 'trans', 'isActiveUrl', 'isResourceIndexActive', 'isDropdownActive'];
const shared = {};
SAME.forEach(k => { shared[k] = M[k]; });

export default {
  components: { Link, AnimatedLogo, IconMenus },
  props: { collapsed: Boolean, mobile: Boolean },
  emits: ['toggle', 'navigate'],
  data() { return { rawMenus: Menus, sel: '', flyOpen: false, tick: 0, tip: { on: false, ready: false, text: '', side: 'right', x: 0, y: 0, ax: 0, ay: 0 } }; },
  computed: {
    wimsLetters() { return Array.from(this.$t('RANS WIMS')); },
    menus() { return sortMenus(this.rawMenus, m => this.trans(m.lang)); },
    // the groups of the rail. "Main" gathers the single links (Dashboard and the like); every other group is a menu that has a tree.
    rail() {
      // the menu is drawn once and stays: it follows the page by itself (the address and the page are read here, so a new page lights its own item)
      void this.tick; void this.$page.url;
      const out = [];
      const main = [];
      let tone = 0;
      this.menus.forEach(menu => {
        if (!(this.canAccessMenu(menu) && this.allowsRole(menu) && this.featureVisible(menu))) return;
        if (!menu.sub_menu) {
          if ((!menu.admin || this.$admin) && !menu.hidden) main.push({ name: menu.name, href: this.menuHref(menu), icon: menu.icon, title: this.trans(menu.lang), active: this.isActiveUrl(this.menuHref(menu)), off: this.$super && this.featureDisabled(menu) });
          return;
        }
        if (!menu.sub_menu.length) return;
        const items = menu.sub_menu.filter(sm => this.canAccessMenu(sm) && this.allowsRole(sm) && this.featureVisible(sm) && !sm.hidden)
          .map(sm => ({ name: sm.name, href: this.menuHref(sm), icon: sm.icon, title: this.trans(sm.lang), active: this.isResourceIndexActive(sm), off: false }));
        if (!items.length) return;
        out.push({ key: menu.name, icon: menu.icon, title: this.trans(menu.lang), items, tone: ++tone, here: this.isDropdownActive(menu.sub_menu, menu.name) || items.some(i => i.active) });
      });
      return [{ key: '__main', icon: 'home', title: this.$t('Main'), items: main, tone: 0, here: main.some(i => i.active) }, ...out];
    },
    hereKey() { const g = this.rail.find(x => x.here); return g ? g.key : '__main'; },
    current() { return this.rail.find(g => g.key === this.sel) || this.rail[0] || null; },
    // the panel is always there on a wide menu; on a collapsed one it is a flyout opened by a click
    panelShown() { return this.mobile || !this.collapsed || this.flyOpen; },
  },
  watch: {
    '$page.url'(a, b) {
      if ((a || '').split('?')[0] === (b || '').split('?')[0]) return;
      // the address has changed: after the page has settled the highlight moves, the group follows and the lists scroll to the new place
      setTimeout(() => {
        this.tick++;
        this.$nextTick(() => {
          this.sel = this.hereKey; this.flyOpen = false;
          this.$nextTick(() => { this.reveal(this.$refs.railList, '.is-on', true); this.reveal(this.$refs.panel && this.$refs.panel.querySelector('.pm-tree'), '.is-on', true); });
        });
      }, 0);
    },
    collapsed() { this.flyOpen = false; this._pinned = false; },
  },
  created() { this.sel = this.hereKey; },
  mounted() {
    this.$nextTick(() => { this.reveal(this.$refs.railList, '.is-on'); this.reveal(this.$refs.panel && this.$refs.panel.querySelector('.pm-tree'), '.is-on'); });
    // the light on the rail icons only runs for the icons that can be seen (a long rail would otherwise keep fifty animations alive)
    if (typeof IntersectionObserver !== 'undefined' && this.$refs.railList) {
      this._io = new IntersectionObserver(es => es.forEach(e => e.target.classList.toggle('is-seen', e.isIntersecting)), { root: this.$refs.railList, threshold: 0 });
      this.watchRail();
    }
    document.addEventListener('keydown', this.onKey);
    document.addEventListener('pointerdown', this.onOutside, true);
  },
  updated() { this.watchRail(); },
  beforeUnmount() { if (this._io) this._io.disconnect(); clearTimeout(this._tt); clearTimeout(this._hv); clearTimeout(this._hl); document.removeEventListener('keydown', this.onKey); document.removeEventListener('pointerdown', this.onOutside, true); },
  methods: {
    ...shared,
    pick(g) {
      if (this.collapsed && !this.mobile && this.sel === g.key && this.flyOpen) { this.flyOpen = false; return; }
      clearTimeout(this._hv); this._pinned = true;
      this.sel = g.key;
      this.flyOpen = true;
      this.$nextTick(() => this.reveal(this.$refs.panel && this.$refs.panel.querySelector('.pm-tree'), '.is-on'));
    },
    // on a collapsed menu a pointer resting on a group opens its tree beside the rail, and leaving the menu closes it again (a click keeps it open)
    hoverIn(g) {
      if (!this.collapsed || this.mobile || !window.matchMedia || !window.matchMedia('(hover: hover)').matches) return;
      clearTimeout(this._hv); clearTimeout(this._hl);
      if (this.sel === g.key && this.flyOpen) return;
      this._hv = setTimeout(() => { this.sel = g.key; this.flyOpen = true; this._pinned = false; this.$nextTick(() => this.reveal(this.$refs.panel && this.$refs.panel.querySelector('.pm-tree'), '.is-on')); }, 110);
    },
    hoverOut() {
      clearTimeout(this._hv);
      if (!this.collapsed || this.mobile || !this.flyOpen || this._pinned) return;
      clearTimeout(this._hl); this._hl = setTimeout(() => { if (!this._pinned) this.flyOpen = false; }, 320);
    },
    // tooltips: only where there is a pointer to rest on; the text is the data-tip of the element under it
    tipOver(e) {
      if (!window.matchMedia || !window.matchMedia('(hover: hover)').matches) return;
      const t = e.target && e.target.closest ? e.target.closest('[data-tip]') : null;
      if (!t || !this.$el.contains(t) || t === this._tipEl) return;
      clearTimeout(this._tt); this._tipEl = t; this.tip.on = false;
      this._tt = setTimeout(() => this.tipShow(t), e.type === 'focusin' ? 0 : 260);
    },
    tipOut(e) {
      const t = e.target && e.target.closest ? e.target.closest('[data-tip]') : null;
      if (t && t === this._tipEl && !(e.relatedTarget && t.contains(e.relatedTarget))) this.tipHide();
    },
    tipHide() { clearTimeout(this._tt); this._tipEl = null; if (this.tip.on) { this.tip.on = false; this.tip.ready = false; } },
    tipShow(t) {
      if (!t.isConnected || !t.dataset.tip) return;
      this.tip = { on: true, ready: false, text: t.dataset.tip, side: 'right', x: -9999, y: 0, ax: 0, ay: 0 };
      this.$nextTick(() => {
        const el = this.$refs.tipEl; if (!el || this._tipEl !== t) return;
        const r = t.getBoundingClientRect(), w = el.offsetWidth, h = el.offsetHeight, vw = window.innerWidth, vh = window.innerHeight, G = 11, M = 8;
        let side = 'right', x = r.right + G, y = r.top + r.height / 2 - h / 2;
        if (x + w > vw - M) { side = 'left'; x = r.left - G - w; }
        if (x < M) { side = r.top - h - G > M ? 'top' : 'bottom'; x = Math.min(Math.max(M, r.left + r.width / 2 - w / 2), vw - w - M); y = side === 'top' ? r.top - G - h : r.bottom + G; }
        else y = Math.min(Math.max(M, y), vh - h - M);
        const ax = r.left + r.width / 2 - x, ay = r.top + r.height / 2 - y;
        this.tip = { on: true, ready: true, text: t.dataset.tip, side, x, y, ax: Math.min(Math.max(14, ax), w - 14), ay: Math.min(Math.max(12, ay), h - 12) };
      });
    },
    watchRail() { if (this._io && this.$refs.railList) this.$refs.railList.querySelectorAll('li').forEach(li => this._io.observe(li)); },
    onNavigate() { this.flyOpen = false; this.$emit('navigate'); },
    onKey(e) { if (e.key === 'Escape' && this.flyOpen) this.flyOpen = false; },
    onOutside(e) { if (this.flyOpen && this.collapsed && !this.mobile && this.$el && !this.$el.contains(e.target)) this.flyOpen = false; },
    // brings the active icon / item into view inside its own scroll box (never scrolls the page)
    reveal(box, sel, smooth = false) {
      if (!box) return;
      const el = box.querySelector(sel);
      if (!el || !box.clientHeight) return;
      const b = box.getBoundingClientRect(), r = el.getBoundingClientRect();
      if (r.top >= b.top + 40 && r.bottom <= b.bottom - 40) return;
      const top = Math.max(0, box.scrollTop + (r.top - b.top) - (box.clientHeight / 2 - r.height / 2));
      const calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      box.scrollTo({ top, behavior: smooth && !calm ? 'smooth' : 'auto' });
    },
  },
};
</script>

<style scoped>
.pm { --rail: #0b1d36; --panel: #123763; --ink: #dbe7fb; --gold: #f2a93b; --tab: #ffffff; --r: 28px; position: relative; display: flex; flex-direction: column; height: 100%; width: 272px; flex: 0 0 auto; color: var(--ink); font-family: Montserrat, 'Segoe UI', Arial, sans-serif; font-weight: 400; border-radius: var(--r); background: linear-gradient(180deg, var(--rail) 0%, #0e2748 100%); box-shadow: 0 24px 44px -22px rgba(7, 18, 38, 0.85), inset 0 1px 0 rgba(255, 255, 255, 0.07); transition: width 0.35s cubic-bezier(0.2, 0.8, 0.2, 1); }
.pm :deep(*) { font-family: inherit; }
.pm.is-collapsed { width: 72px; }
.pm.is-mobile { width: 100%; border-radius: 0 var(--r) var(--r) 0; }
.pm-brand { flex: 0 0 auto; display: grid; place-items: center; height: 82px; padding: 2px 12px 12px; position: relative; }
.pm-brand::after { content: ''; position: absolute; left: 14px; right: 14px; bottom: 0; height: 2px; border-radius: 2px; background: linear-gradient(90deg, transparent, #ffd98a 50%, transparent) no-repeat, linear-gradient(90deg, transparent, rgba(242, 169, 59, 0.4) 50%, transparent) no-repeat; background-size: 36% 100%, 100% 100%; background-position: -64% 0, 0 0; filter: drop-shadow(0 0 4px rgba(242, 169, 59, 0.8)); animation: pm-line 3.6s cubic-bezier(0.45, 0, 0.55, 1) infinite; }
@keyframes pm-line { from { background-position: -64% 0, 0 0; } to { background-position: 164% 0, 0 0; } }
.pm-logo { display: block; width: 160px; transition: width 0.35s cubic-bezier(0.2, 0.8, 0.2, 1), transform 0.3s; filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.35)); animation: pm-drop 0.7s cubic-bezier(0.2, 0.8, 0.2, 1) both; }
.pm-logo:hover { transform: scale(1.03); }
@keyframes pm-drop { from { opacity: 0; transform: translateY(-14px) scale(0.94); } }
.pm.is-collapsed .pm-logo { width: 38px; }
.pm.is-mobile .pm-logo { width: 146px; }
.pm-body { position: relative; flex: 1 1 auto; min-height: 0; display: flex; }

/* icons: flat, the same size in every container and exactly in its middle */
.pm :deep(.pm-ic) { display: block; width: 18px; height: 18px; flex: none; transition: transform 0.35s cubic-bezier(0.2, 0.9, 0.3, 1.3); }
.pm :deep(.pm-ic-m) { width: 15px; height: 15px; } .pm :deep(.pm-ic-s) { width: 15px; height: 15px; }
.pm :deep(.pm-ic svg) { display: block; width: 100%; height: 100%; }

/* ----- the rail ----- */
.pm-rail { flex: 0 0 72px; width: 72px; display: flex; flex-direction: column; min-height: 0; padding: 2px 0 8px; }
.pm.is-mobile .pm-rail { flex-basis: 64px; width: 64px; }
.pm-rail ul { list-style: none; margin: 0; padding: 26px 0 26px; flex: 1 1 auto; min-height: 0; overflow-y: auto; overflow-x: hidden; scrollbar-width: none; display: grid; gap: 4px; align-content: start; }
.pm-rail ul::-webkit-scrollbar { display: none; }
.pm-rail li { position: relative; margin-right: 8px; height: 48px; animation: pm-in 0.55s var(--d, 0ms) cubic-bezier(0.2, 0.8, 0.2, 1) both; }
@keyframes pm-in { from { opacity: 0; transform: translateX(-16px); } }
.pm-rail li button { position: relative; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; border: 0; background: transparent; cursor: pointer; padding: 0; border-radius: 0 24px 24px 0; transition: background 0.3s ease; }
.pm-tile { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 12px; overflow: hidden; color: var(--fg); background: var(--bg); transition: transform 0.28s cubic-bezier(0.2, 0.9, 0.3, 1.3), background 0.25s ease; position: relative; z-index: 2; }
.pm-tile::after, .pm-head-ico::after { content: ''; position: absolute; inset: -10%; pointer-events: none; z-index: 3; background: linear-gradient(112deg, transparent 38%, rgba(255, 255, 255, 0.85) 50%, transparent 62%); transform: translateX(-130%); animation: pm-shine 5.2s var(--sh, 0ms) ease-in-out infinite; }
@keyframes pm-shine { 0%, 58% { transform: translateX(-130%); } 80%, 100% { transform: translateX(130%); } }
.pm-rail li:not(.is-seen) .pm-tile::after { animation-play-state: paused; }
.pm-rail li:hover .pm-tile { transform: translateY(-1px) scale(1.07); background: var(--bg2); }
.pm-rail li:hover .pm-ic { transform: scale(1.14) rotate(-8deg); }
.pm-rail li button:active .pm-tile { transform: scale(0.94); }
.pm-rail li button:focus-visible { outline: 2px solid var(--gold); outline-offset: -2px; }
/* the group you are in, when you are looking at another one: a small gold light */
.pm-here { position: absolute; right: 7px; top: 9px; width: 8px; height: 8px; border-radius: 50%; background: var(--gold); box-shadow: 0 0 0 3px rgba(242, 169, 59, 0.25); z-index: 3; animation: pm-pulse 2s ease-in-out infinite; } @keyframes pm-pulse { 50% { box-shadow: 0 0 0 6px rgba(242, 169, 59, 0); } }
/* the chosen group sits in a white tab that flares into the panel */
.pm-rail li.is-on button { background: var(--tab); }
.pm-rail li.is-on::before, .pm-rail li.is-on::after { content: ''; position: absolute; left: 0; width: 22px; height: 22px; pointer-events: none; z-index: 1; animation: pm-flare 0.4s ease both; }
@keyframes pm-flare { from { opacity: 0; } }
.pm-rail li.is-on::before { top: -22px; background: radial-gradient(circle at 100% 0, transparent 22px, var(--tab) 22.5px); }
.pm-rail li.is-on::after { bottom: -22px; background: radial-gradient(circle at 100% 100%, transparent 22px, var(--tab) 22.5px); }
.pm-rail li.is-on .pm-tile { transform: scale(1.05); background: var(--bg); }
.pm-fold { flex: 0 0 auto; align-self: center; width: 34px; height: 34px; margin: 2px 0 2px; border: 0; border-radius: 12px; cursor: pointer; display: grid; place-items: center; color: #9db4d8; background: rgba(255, 255, 255, 0.07); transition: background 0.25s, color 0.25s, transform 0.25s; } .pm-fold:hover { background: rgba(242, 169, 59, 0.2); color: var(--gold); transform: scale(1.06); } .pm-fold :deep(.pm-ic) { transition: transform 0.35s; }

/* ----- the panel ----- */
.pm-panel { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; border-radius: 0 var(--r) var(--r) 0; background: linear-gradient(180deg, var(--panel), #0f2d54); box-shadow: inset 1px 0 0 rgba(255, 255, 255, 0.05); overflow: hidden; }
.pm-head { flex: 0 0 auto; display: flex; align-items: center; gap: 10px; padding: 14px 14px 2px 16px; }
.pm-head-ico { position: relative; overflow: hidden; display: grid; place-items: center; width: 34px; height: 34px; border-radius: 12px; color: var(--fg); background: var(--bg); flex: none; transition: background 0.3s, color 0.3s; }
.pm-head small { display: block; font-size: 0.744rem; font-weight: 400; letter-spacing: 0; line-height: 1.3; color: var(--gold); white-space: nowrap; }
.pm-wims span { display: inline-block; animation: pm-write 5s calc(var(--i, 0) * 75ms) cubic-bezier(0.2, 0.8, 0.2, 1) infinite both; }
@keyframes pm-write { 0% { opacity: 0; transform: translateY(5px) scale(0.85); filter: blur(2px); } 6% { opacity: 1; transform: none; filter: none; } 100% { opacity: 1; transform: none; filter: none; } }
.pm-head h2 { margin: 1px 0 0; font-size: 0.92rem; font-weight: 400; color: #fff; line-height: 1.2; overflow-wrap: anywhere; animation: pm-title 0.45s cubic-bezier(0.2, 0.8, 0.2, 1) both; }
@keyframes pm-title { from { opacity: 0; transform: translateY(6px); } }
.pm-close { margin-left: auto; width: 28px; height: 28px; border: 0; border-radius: 10px; background: rgba(255, 255, 255, 0.08); color: #c9d8f3; cursor: pointer; display: grid; place-items: center; transition: background 0.2s; } .pm-close:hover { background: rgba(255, 255, 255, 0.16); }
.pm-tree { list-style: none; margin: 0; padding: 22px 0 24px 22px; flex: 1 1 auto; min-height: 0; overflow-y: auto; overflow-x: hidden; position: relative; scrollbar-width: thin; scrollbar-color: rgba(255, 255, 255, 0.18) transparent; scroll-behavior: auto; }
/* the spine of the tree and a branch to every item */
.pm-tree::before { content: ''; position: absolute; left: 31px; top: 28px; bottom: 30px; width: 2px; border-radius: 2px; background: linear-gradient(180deg, var(--ln), rgba(255, 255, 255, 0.08) 85%); opacity: 0.7; }
.pm-tree li { position: relative; margin: 3px 0; animation: pm-in 0.5s var(--d, 0ms) cubic-bezier(0.2, 0.8, 0.2, 1) both; }
.pm-link { position: relative; display: flex; align-items: center; gap: 9px; min-height: 38px; margin-left: 16px; padding: 4px 12px 4px 8px; color: #dbe6f8; text-decoration: none; font-size: 0.76rem; font-weight: 300; border-radius: 19px 0 0 19px; transition: background 0.25s ease, color 0.25s ease; }
.pm-link::before { content: ''; position: absolute; left: -16px; top: 50%; width: 16px; height: 2px; background: linear-gradient(90deg, var(--ln), rgba(255, 255, 255, 0.15)); border-radius: 2px; opacity: 0.8; transition: width 0.25s; }
.pm-node { position: absolute; left: -30px; top: 50%; width: 10px; height: 10px; margin-top: -5px; border-radius: 50%; background: var(--panel); box-shadow: 0 0 0 2px var(--ln); transition: transform 0.3s cubic-bezier(0.2, 0.9, 0.3, 1.4), background 0.25s, box-shadow 0.25s; z-index: 2; }
.pm-link:hover { background: rgba(255, 255, 255, 0.09); color: #fff; }
.pm-link:hover::before { width: 20px; left: -20px; }
.pm-link:hover .pm-node { background: var(--ln); transform: scale(1.25); }
.pm-link .pm-ico, .pm-link .pm-txt { transition: transform 0.3s cubic-bezier(0.2, 0.9, 0.3, 1.2); }
.pm-link:hover .pm-ico, .pm-link:hover .pm-txt { transform: translateX(3px); }
.pm-link:hover .pm-ic { transform: scale(1.12) rotate(-6deg); }
.pm-ico { display: grid; place-items: center; width: 27px; height: 27px; border-radius: 9px; flex: none; color: var(--fg); background: var(--bg); }
.pm-txt { min-width: 0; line-height: 1.25; overflow-wrap: anywhere; }
.pm-off { margin-left: auto; font-size: 0.56rem; font-weight: 400; padding: 1px 7px; border-radius: 999px; background: #fee2e2; color: #b91c1c; text-transform: uppercase; }
/* the page you are on: a white pill that reaches the edge of the menu, with flares above and below, a gold node */
.pm-tree li.is-on .pm-link { background: var(--tab); color: #0b1d36; font-weight: 400; }
.pm-tree li.is-on .pm-node { background: var(--gold); box-shadow: 0 0 0 2px var(--tab), 0 0 0 4px var(--gold), 0 0 12px 2px rgba(242, 169, 59, 0.65); animation: pm-pulse2 2.2s ease-in-out infinite; }
@keyframes pm-pulse2 { 50% { box-shadow: 0 0 0 2px var(--tab), 0 0 0 6px rgba(242, 169, 59, 0.25), 0 0 12px 2px rgba(242, 169, 59, 0.4); } }
.pm-tree li.is-on::before, .pm-tree li.is-on::after { content: ''; position: absolute; right: 0; width: 19px; height: 19px; pointer-events: none; z-index: 1; animation: pm-flare 0.4s ease both; }
.pm-tree li.is-on::before { top: -19px; background: radial-gradient(circle at 0 0, transparent 19px, var(--tab) 19.5px); }
.pm-tree li.is-on::after { bottom: -19px; background: radial-gradient(circle at 0 100%, transparent 19px, var(--tab) 19.5px); }
.pm-foot { flex: 0 0 auto; padding: 10px 14px 14px; border-top: 1px solid rgba(255, 255, 255, 0.08); color: #c9d8f3; }

/* ----- group colours: the lighter, nude version of RANS gold, blue, violet, green, orange, rose, cyan and indigo. --bg the tile, --bg2 when it is touched, --fg the icon, --ln the line of the tree ----- */
.pg-0 { --bg: #f7e6c4; --bg2: #f2d6a0; --fg: #9a6410; --ln: #e0a73c; } .pg-1 { --bg: #d4e4fc; --bg2: #bcd5fa; --fg: #1d4fb5; --ln: #6a9cf0; } .pg-2 { --bg: #e3dafb; --bg2: #d3c6f7; --fg: #5b34b8; --ln: #9a7ce8; } .pg-3 { --bg: #d0efe0; --bg2: #b9e6d0; --fg: #14704c; --ln: #52c08f; }
.pg-4 { --bg: #fbe0c8; --bg2: #f6cfaa; --fg: #a9540f; --ln: #ec9a55; } .pg-5 { --bg: #fad6dd; --bg2: #f5bcc7; --fg: #b0213e; --ln: #ee7d92; } .pg-6 { --bg: #cfeff4; --bg2: #b5e5ed; --fg: #0b6b80; --ln: #4dc3d8; } .pg-7 { --bg: #d9dcf8; --bg2: #c3c8f3; --fg: #3a41b0; --ln: #8189e3; }

/* ----- collapsed: only the rail; the tree opens beside it ----- */
.pm.is-collapsed .pm-body { overflow: visible; }
.pm.is-collapsed .pm-panel { position: absolute; left: calc(100% - 4px); top: 0; width: 248px; max-height: calc(100vh - 140px); border-radius: 0 var(--r) var(--r) var(--r); box-shadow: 24px 24px 50px -22px rgba(7, 18, 38, 0.7), inset 1px 0 0 rgba(255, 255, 255, 0.05); z-index: 60; }
.pm-fly-enter-active, .pm-fly-leave-active { transition: opacity 0.22s ease, transform 0.28s cubic-bezier(0.2, 0.8, 0.2, 1); }
.pm.is-collapsed .pm-fly-enter-from, .pm.is-collapsed .pm-fly-leave-to { opacity: 0; transform: translateX(-16px) scale(0.97); transform-origin: left top; }
.pm.is-collapsed .pm-brand { padding: 0 8px; }

/* the tooltip: drawn on the page (teleported), always above the menu and everything else, kept inside the screen */
.pm-tip { position: fixed; z-index: 2147483000; max-width: min(240px, calc(100vw - 20px)); padding: 7px 12px; border-radius: 11px; font: 400 0.72rem/1.35 Montserrat, 'Segoe UI', Arial, sans-serif; color: #f4f8ff; background: linear-gradient(160deg, #1b3f72, #0b1d36); box-shadow: 0 0 0 1px rgba(242, 169, 59, 0.45), 0 12px 26px -8px rgba(7, 18, 38, 0.75); pointer-events: none; overflow-wrap: anywhere; opacity: 0; transform: scale(0.92); }
.pm-tip.is-ready { opacity: 1; transform: none; animation: pm-tip-in 0.22s cubic-bezier(0.2, 0.9, 0.3, 1.2) both; }
.pm-tip::before { content: ''; position: absolute; width: 9px; height: 9px; background: #14315b; transform: rotate(45deg); box-shadow: -1px 1px 0 rgba(242, 169, 59, 0.45); }
.pm-tip.is-right::before { left: -4px; top: calc(var(--ay) - 4.5px); box-shadow: -1px 1px 0 rgba(242, 169, 59, 0.45); }
.pm-tip.is-left::before { right: -4px; top: calc(var(--ay) - 4.5px); box-shadow: 1px -1px 0 rgba(242, 169, 59, 0.45); }
.pm-tip.is-bottom::before { top: -4px; left: calc(var(--ax) - 4.5px); box-shadow: -1px -1px 0 rgba(242, 169, 59, 0.45); }
.pm-tip.is-top::before { bottom: -4px; left: calc(var(--ax) - 4.5px); box-shadow: 1px 1px 0 rgba(242, 169, 59, 0.45); }
@keyframes pm-tip-in { from { opacity: 0; transform: translateX(-6px) scale(0.92); } }
.pm-tip.is-left { animation-name: pm-tip-in-l; } @keyframes pm-tip-in-l { from { opacity: 0; transform: translateX(6px) scale(0.92); } }
.pm-tip.is-top, .pm-tip.is-bottom { animation-name: pm-tip-in-v; } @keyframes pm-tip-in-v { from { opacity: 0; transform: translateY(5px) scale(0.92); } }
@media (hover: none) { .pm-tip { display: none; } }

/* dark mode: the same menu, the white tab a little softer so it does not glare against a dark page */
:global(html.dark) .pm { --tab: #e4ecf9; --rail: #081426; --panel: #0f2b50; }
@media (prefers-reduced-motion: reduce) { .pm, .pm * { animation: none !important; transition: none !important; } }
</style>

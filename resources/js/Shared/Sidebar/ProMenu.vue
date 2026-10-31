<template>
  <aside class="pm" :class="{ 'is-collapsed': collapsed && !mobile, 'is-mobile': mobile, 'is-fly': flyOpen }" :aria-label="$t('Main menu')" @mouseleave="hoverOut">
    <div class="pm-brand">
      <Link :href="route('dashboard')" class="pm-logo" :aria-label="$t('Dashboard')"><animated-logo :mark="collapsed && !mobile" /></Link>
    </div>

    <div class="pm-body">
      <!-- the rail: one coloured icon for each group of the menu -->
      <nav ref="rail" class="pm-rail" :aria-label="$t('Menu groups')">
        <ul ref="railList">
          <li v-for="(g, gi) in rail" :key="g.key" :class="['pg-' + (g.tone % 8), { 'is-on': g.key === sel, 'is-here': g.key === hereKey }]" :style="{ '--d': Math.min(gi * 22, 360) + 'ms' }">
            <button type="button" :title="g.title" :aria-label="g.title" :aria-expanded="g.key === sel && panelShown" @click="pick(g)" @mouseenter="hoverIn(g)">
              <span class="pm-tile"><Icons :name="g.icon || 'collection'" class-name="pm-ic" /></span>
              <i v-if="g.key === hereKey && g.key !== sel" class="pm-here" aria-hidden="true"></i>
            </button>
          </li>
        </ul>
        <button v-if="!mobile" type="button" class="pm-fold" :title="collapsed ? $t('Show menu') : $t('Hide menu')" :aria-label="collapsed ? $t('Show menu') : $t('Hide menu')" @click="$emit('toggle')"><Icons name="chevron-down" class-name="pm-ic pm-ic-s" :class="collapsed ? '-rotate-90' : 'rotate-90'" /></button>
      </nav>

      <!-- the panel: the tree of the chosen group (on a collapsed menu it opens beside the rail, over the page) -->
      <transition name="pm-fly">
        <section v-if="panelShown" ref="panel" class="pm-panel" :class="'pg-' + ((current && current.tone) % 8)" :aria-label="current ? current.title : ''">
          <header class="pm-head">
            <span class="pm-head-ico"><Icons :name="(current && current.icon) || 'collection'" class-name="pm-ic" /></span>
            <div class="min-w-0"><small>{{ $t('RANS WIMS') }}</small><h2>{{ current ? current.title : '' }}</h2></div>
            <button v-if="collapsed && !mobile" type="button" class="pm-close" :aria-label="$t('Close')" @click="flyOpen = false"><Icons name="cross" class-name="pm-ic pm-ic-s" /></button>
          </header>
          <ul v-if="current" :key="current.key" class="pm-tree">
            <li v-for="(it, i) in current.items" :key="it.name" :class="{ 'is-on': it.active }" :style="{ '--d': Math.min(i * 28, 420) + 'ms' }">
              <Link :href="it.href" class="pm-link" :title="it.title" @click="onNavigate">
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
  data() { return { rawMenus: Menus, sel: '', flyOpen: false, tick: 0 }; },
  computed: {
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
    document.addEventListener('keydown', this.onKey);
    document.addEventListener('pointerdown', this.onOutside, true);
  },
  beforeUnmount() { document.removeEventListener('keydown', this.onKey); document.removeEventListener('pointerdown', this.onOutside, true); },
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
.pm { --rail: #0b1d36; --panel: #123763; --ink: #dbe7fb; --gold: #f2a93b; --tab: #ffffff; --r: 28px; position: relative; display: flex; flex-direction: column; height: 100%; width: 312px; flex: 0 0 auto; color: var(--ink); border-radius: var(--r); background: linear-gradient(180deg, var(--rail) 0%, #0e2748 100%); box-shadow: 0 24px 44px -22px rgba(7, 18, 38, 0.85), inset 0 1px 0 rgba(255, 255, 255, 0.07); transition: width 0.35s cubic-bezier(0.2, 0.8, 0.2, 1); }
.pm.is-collapsed { width: 84px; }
.pm.is-mobile { width: 100%; border-radius: 0 var(--r) var(--r) 0; }
.pm-brand { flex: 0 0 auto; display: grid; place-items: center; height: 84px; padding: 0 14px; position: relative; }
.pm-brand::after { content: ''; position: absolute; left: 18px; right: 18px; bottom: 0; height: 1px; background: linear-gradient(90deg, transparent, rgba(242, 169, 59, 0.55), transparent); }
.pm-logo { display: block; width: 188px; transition: width 0.35s cubic-bezier(0.2, 0.8, 0.2, 1), transform 0.3s; filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.35)); animation: pm-drop 0.7s cubic-bezier(0.2, 0.8, 0.2, 1) both; }
.pm-logo:hover { transform: scale(1.03); }
@keyframes pm-drop { from { opacity: 0; transform: translateY(-14px) scale(0.94); } }
.pm.is-collapsed .pm-logo { width: 44px; }
.pm.is-mobile .pm-logo { width: 172px; }
.pm-body { position: relative; flex: 1 1 auto; min-height: 0; display: flex; }

/* icons: flat, the same size in every container and exactly in its middle */
.pm :deep(.pm-ic) { display: block; width: 22px; height: 22px; flex: none; transition: transform 0.35s cubic-bezier(0.2, 0.9, 0.3, 1.3); }
.pm :deep(.pm-ic-m) { width: 18px; height: 18px; } .pm :deep(.pm-ic-s) { width: 18px; height: 18px; }
.pm :deep(.pm-ic svg) { display: block; width: 100%; height: 100%; }

/* ----- the rail ----- */
.pm-rail { flex: 0 0 84px; width: 84px; display: flex; flex-direction: column; min-height: 0; padding: 12px 0 8px; }
.pm.is-mobile .pm-rail { flex-basis: 72px; width: 72px; }
.pm-rail ul { list-style: none; margin: 0; padding: 2px 0 8px; flex: 1 1 auto; min-height: 0; overflow-y: auto; overflow-x: hidden; scrollbar-width: none; display: grid; gap: 4px; align-content: start; }
.pm-rail ul::-webkit-scrollbar { display: none; }
.pm-rail li { position: relative; margin-right: 10px; height: 56px; animation: pm-in 0.55s var(--d, 0ms) cubic-bezier(0.2, 0.8, 0.2, 1) both; }
@keyframes pm-in { from { opacity: 0; transform: translateX(-16px); } }
.pm-rail li button { position: relative; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; border: 0; background: transparent; cursor: pointer; padding: 0; border-radius: 0 28px 28px 0; transition: background 0.3s ease; }
.pm-tile { display: grid; place-items: center; width: 44px; height: 44px; border-radius: 15px; color: var(--fg); background: var(--bg); transition: transform 0.28s cubic-bezier(0.2, 0.9, 0.3, 1.3), background 0.25s ease; position: relative; z-index: 2; }
.pm-rail li:hover .pm-tile { transform: translateY(-1px) scale(1.07); background: var(--bg2); }
.pm-rail li:hover .pm-ic { transform: scale(1.14) rotate(-8deg); }
.pm-rail li button:active .pm-tile { transform: scale(0.94); }
.pm-rail li button:focus-visible { outline: 2px solid var(--gold); outline-offset: -2px; }
/* the group you are in, when you are looking at another one: a small gold light */
.pm-here { position: absolute; right: 9px; top: 12px; width: 8px; height: 8px; border-radius: 50%; background: var(--gold); box-shadow: 0 0 0 3px rgba(242, 169, 59, 0.25); z-index: 3; animation: pm-pulse 2s ease-in-out infinite; } @keyframes pm-pulse { 50% { box-shadow: 0 0 0 6px rgba(242, 169, 59, 0); } }
/* the chosen group sits in a white tab that flares into the panel */
.pm-rail li.is-on button { background: var(--tab); }
.pm-rail li.is-on::before, .pm-rail li.is-on::after { content: ''; position: absolute; left: 0; width: 26px; height: 26px; pointer-events: none; z-index: 1; animation: pm-flare 0.4s ease both; }
@keyframes pm-flare { from { opacity: 0; } }
.pm-rail li.is-on::before { top: -26px; background: radial-gradient(circle at 100% 0, transparent 26px, var(--tab) 26.5px); }
.pm-rail li.is-on::after { bottom: -26px; background: radial-gradient(circle at 100% 100%, transparent 26px, var(--tab) 26.5px); }
.pm-rail li.is-on .pm-tile { transform: scale(1.05); background: var(--bg); }
.pm-fold { flex: 0 0 auto; align-self: center; width: 40px; height: 40px; margin: 4px 0 2px; border: 0; border-radius: 14px; cursor: pointer; display: grid; place-items: center; color: #9db4d8; background: rgba(255, 255, 255, 0.07); transition: background 0.25s, color 0.25s, transform 0.25s; } .pm-fold:hover { background: rgba(242, 169, 59, 0.2); color: var(--gold); transform: scale(1.06); } .pm-fold :deep(.pm-ic) { transition: transform 0.35s; }

/* ----- the panel ----- */
.pm-panel { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; border-radius: 0 var(--r) var(--r) 0; background: linear-gradient(180deg, var(--panel), #0f2d54); box-shadow: inset 1px 0 0 rgba(255, 255, 255, 0.05); overflow: hidden; }
.pm-head { flex: 0 0 auto; display: flex; align-items: center; gap: 12px; padding: 18px 16px 12px 18px; }
.pm-head-ico { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 14px; color: var(--fg); background: var(--bg); flex: none; transition: background 0.3s, color 0.3s; }
.pm-head small { display: block; font-size: 0.62rem; font-weight: 800; letter-spacing: 0.2em; color: var(--gold); text-transform: uppercase; } .pm-head h2 { margin: 1px 0 0; font-size: 1.02rem; font-weight: 800; color: #fff; line-height: 1.2; overflow-wrap: anywhere; animation: pm-title 0.45s cubic-bezier(0.2, 0.8, 0.2, 1) both; }
@keyframes pm-title { from { opacity: 0; transform: translateY(6px); } }
.pm-close { margin-left: auto; width: 32px; height: 32px; border: 0; border-radius: 10px; background: rgba(255, 255, 255, 0.08); color: #c9d8f3; cursor: pointer; display: grid; place-items: center; transition: background 0.2s; } .pm-close:hover { background: rgba(255, 255, 255, 0.16); }
.pm-tree { list-style: none; margin: 0; padding: 4px 0 18px 26px; flex: 1 1 auto; min-height: 0; overflow-y: auto; overflow-x: hidden; position: relative; scrollbar-width: thin; scrollbar-color: rgba(255, 255, 255, 0.18) transparent; scroll-behavior: auto; }
/* the spine of the tree and a branch to every item */
.pm-tree::before { content: ''; position: absolute; left: 33px; top: 12px; bottom: 28px; width: 2px; border-radius: 2px; background: linear-gradient(180deg, var(--ln), rgba(255, 255, 255, 0.08) 85%); opacity: 0.7; }
.pm-tree li { position: relative; margin: 3px 0; animation: pm-in 0.5s var(--d, 0ms) cubic-bezier(0.2, 0.8, 0.2, 1) both; }
.pm-link { position: relative; display: flex; align-items: center; gap: 11px; min-height: 44px; margin-left: 14px; padding: 6px 14px 6px 12px; color: #cfdcf3; text-decoration: none; font-size: 0.86rem; font-weight: 600; border-radius: 22px 0 0 22px; transition: background 0.25s ease, color 0.25s ease; }
.pm-link::before { content: ''; position: absolute; left: -14px; top: 50%; width: 14px; height: 2px; background: linear-gradient(90deg, var(--ln), rgba(255, 255, 255, 0.15)); border-radius: 2px; opacity: 0.8; transition: width 0.25s; }
.pm-node { position: absolute; left: -20px; top: 50%; width: 12px; height: 12px; margin-top: -6px; border-radius: 50%; background: var(--panel); box-shadow: 0 0 0 2px var(--ln); transition: transform 0.3s cubic-bezier(0.2, 0.9, 0.3, 1.4), background 0.25s, box-shadow 0.25s; z-index: 2; }
.pm-link:hover { background: rgba(255, 255, 255, 0.09); color: #fff; }
.pm-link:hover::before { width: 18px; left: -18px; }
.pm-link:hover .pm-node { background: var(--ln); transform: scale(1.25); }
.pm-link .pm-ico, .pm-link .pm-txt { transition: transform 0.3s cubic-bezier(0.2, 0.9, 0.3, 1.2); }
.pm-link:hover .pm-ico, .pm-link:hover .pm-txt { transform: translateX(3px); }
.pm-link:hover .pm-ic { transform: scale(1.12) rotate(-6deg); }
.pm-ico { display: grid; place-items: center; width: 32px; height: 32px; border-radius: 11px; flex: none; color: var(--fg); background: var(--bg); }
.pm-txt { min-width: 0; line-height: 1.25; overflow-wrap: anywhere; }
.pm-off { margin-left: auto; font-size: 0.6rem; font-weight: 800; padding: 1px 7px; border-radius: 999px; background: #fee2e2; color: #b91c1c; text-transform: uppercase; }
/* the page you are on: a white pill that reaches the edge of the menu, with flares above and below, a gold node */
.pm-tree li.is-on .pm-link { background: var(--tab); color: #0b1d36; font-weight: 800; }
.pm-tree li.is-on .pm-node { background: var(--gold); box-shadow: 0 0 0 3px var(--tab), 0 0 0 5px var(--gold), 0 0 14px 2px rgba(242, 169, 59, 0.7); animation: pm-pulse2 2.2s ease-in-out infinite; }
@keyframes pm-pulse2 { 50% { box-shadow: 0 0 0 3px var(--tab), 0 0 0 8px rgba(242, 169, 59, 0.25), 0 0 14px 2px rgba(242, 169, 59, 0.4); } }
.pm-tree li.is-on::before, .pm-tree li.is-on::after { content: ''; position: absolute; right: 0; width: 22px; height: 22px; pointer-events: none; z-index: 1; animation: pm-flare 0.4s ease both; }
.pm-tree li.is-on::before { top: -22px; background: radial-gradient(circle at 0 0, transparent 22px, var(--tab) 22.5px); }
.pm-tree li.is-on::after { bottom: -22px; background: radial-gradient(circle at 0 100%, transparent 22px, var(--tab) 22.5px); }
.pm-foot { flex: 0 0 auto; padding: 12px 16px 16px; border-top: 1px solid rgba(255, 255, 255, 0.08); color: #c9d8f3; }

/* ----- group colours: the lighter, nude version of RANS gold, blue, violet, green, orange, rose, cyan and indigo. --bg the tile, --bg2 when it is touched, --fg the icon, --ln the line of the tree ----- */
.pg-0 { --bg: #f7e6c4; --bg2: #f2d6a0; --fg: #9a6410; --ln: #e0a73c; } .pg-1 { --bg: #d4e4fc; --bg2: #bcd5fa; --fg: #1d4fb5; --ln: #6a9cf0; } .pg-2 { --bg: #e3dafb; --bg2: #d3c6f7; --fg: #5b34b8; --ln: #9a7ce8; } .pg-3 { --bg: #d0efe0; --bg2: #b9e6d0; --fg: #14704c; --ln: #52c08f; }
.pg-4 { --bg: #fbe0c8; --bg2: #f6cfaa; --fg: #a9540f; --ln: #ec9a55; } .pg-5 { --bg: #fad6dd; --bg2: #f5bcc7; --fg: #b0213e; --ln: #ee7d92; } .pg-6 { --bg: #cfeff4; --bg2: #b5e5ed; --fg: #0b6b80; --ln: #4dc3d8; } .pg-7 { --bg: #d9dcf8; --bg2: #c3c8f3; --fg: #3a41b0; --ln: #8189e3; }

/* ----- collapsed: only the rail; the tree opens beside it ----- */
.pm.is-collapsed .pm-body { overflow: visible; }
.pm.is-collapsed .pm-panel { position: absolute; left: calc(100% - 4px); top: 0; width: 284px; max-height: calc(100vh - 140px); border-radius: 0 var(--r) var(--r) var(--r); box-shadow: 24px 24px 50px -22px rgba(7, 18, 38, 0.7), inset 1px 0 0 rgba(255, 255, 255, 0.05); z-index: 60; }
.pm-fly-enter-active, .pm-fly-leave-active { transition: opacity 0.22s ease, transform 0.28s cubic-bezier(0.2, 0.8, 0.2, 1); }
.pm.is-collapsed .pm-fly-enter-from, .pm.is-collapsed .pm-fly-leave-to { opacity: 0; transform: translateX(-16px) scale(0.97); transform-origin: left top; }
.pm.is-collapsed .pm-brand { padding: 0 8px; }

/* dark mode: the same menu, the white tab a little softer so it does not glare against a dark page */
:global(html.dark) .pm { --tab: #e4ecf9; --rail: #081426; --panel: #0f2b50; }
@media (prefers-reduced-motion: reduce) { .pm, .pm * { animation: none !important; transition: none !important; } }
</style>

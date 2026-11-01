<template>
  <aside class="gm" :class="{ 'is-compact': compact, 'is-mobile': mobile }" :aria-label="$t('Main menu')" @mouseover="tipOver" @mouseout="tipOut" @focusin="tipOver" @focusout="tipOut" @pointerdown="tipHide" @scroll.capture="tipHide">
    <header class="gm-head">
      <Link :href="route('dashboard')" class="gm-brand" :aria-label="$t('Dashboard')" :data-tip="compact ? $t('Dashboard') : null">
        <span class="gm-logo"><img src="/images/logo-white-mark.png" alt="" draggable="false" /></span>
        <span v-if="!compact" class="gm-title"><b>{{ appName }}</b><small>{{ roleLine }}</small></span>
      </Link>
      <button v-if="!mobile" type="button" class="gm-fold" :data-tip="collapsed ? $t('Show menu') : $t('Hide menu')" :aria-label="collapsed ? $t('Show menu') : $t('Hide menu')" @click="$emit('toggle')">
        <Icons name="chevron-down" class-name="gm-ic gm-ic-s" :class="collapsed ? '-rotate-90' : 'rotate-90'" />
      </button>
    </header>

    <button type="button" class="gm-search" :data-tip="compact ? $t('Search pages') : null" :aria-label="$t('Search pages')" @click="openSearch">
      <Icons name="search" class-name="gm-ic" />
      <span v-if="!compact" class="gm-search-txt">{{ $t('Search pages') }}</span>
      <kbd v-if="!compact && kbd" class="gm-kbd">{{ kbd }}</kbd>
    </button>

    <nav ref="scroll" class="gm-scroll" :aria-label="$t('Menu')">
      <div v-if="main.length" class="gm-sec">
        <p v-if="!compact" class="gm-label">{{ $t('Main') }}</p>
        <i v-else class="gm-div" aria-hidden="true"></i>
        <Link v-for="it in main" :key="it.name" :href="it.href" class="gm-item" :class="{ 'is-on': it.active }" :data-tip="compact ? it.title : null" :aria-current="it.active ? 'page' : null" @click="onNavigate">
          <Icons :name="it.icon || 'link'" class-name="gm-ic" />
          <span v-if="!compact" class="gm-txt">{{ it.title }}</span>
          <span v-if="it.off && !compact" class="gm-off">{{ $t('Disabled') }}</span>
        </Link>
      </div>

      <div v-if="groups.length" class="gm-sec">
        <p v-if="!compact" class="gm-label">{{ $t('Workspace') }}</p>
        <i v-else class="gm-div" aria-hidden="true"></i>
        <div v-for="(g, gi) in groups" :key="g.key" class="gm-grp">
          <button type="button" class="gm-item gm-group" :class="{ 'is-open': !compact && openKey === g.key, 'is-here': g.key === hereKey }" :data-tip="compact ? g.title : null" :aria-expanded="!compact ? String(openKey === g.key) : null" @click="toggleGroup(g)">
            <Icons :name="g.icon || 'collection'" class-name="gm-ic" />
            <span v-if="!compact" class="gm-txt">{{ g.title }}</span>
            <Icons v-if="!compact" name="chevron-down" class-name="gm-ic gm-ic-s gm-chev" />
            <i v-if="g.key === hereKey" class="gm-here" aria-hidden="true"></i>
          </button>
          <transition name="gm-open">
            <ul v-if="!compact && openKey === g.key" class="gm-tree">
              <li v-for="it in g.items" :key="it.name">
                <Link :href="it.href" class="gm-sub" :class="{ 'is-on': it.active }" :data-tip="it.title" :aria-current="it.active ? 'page' : null" @click="onNavigate">
                  <i class="gm-dot" :style="{ '--dot': dotColor(gi) }" aria-hidden="true"></i>
                  <span class="gm-txt">{{ it.title }}</span>
                </Link>
              </li>
            </ul>
          </transition>
        </div>
      </div>
    </nav>

    <footer class="gm-foot">
      <button v-if="darkAllowed" type="button" class="gm-night" role="switch" :aria-checked="String(dark)" :data-tip="compact ? (dark ? $t('Switch to normal mode') : $t('Night mode')) : null" @click="toggleTheme">
        <span class="gm-ic gm-sunmoon" aria-hidden="true">
          <svg v-if="!dark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="4" /><path d="M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M5.3 18.7l1.6-1.6M17.1 6.9l1.6-1.6" /></svg>
          <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 14.2A8.2 8.2 0 0 1 9.8 4 8.2 8.2 0 1 0 20 14.2z" /></svg>
        </span>
        <span v-if="!compact" class="gm-txt">{{ $t('Night mode') }}</span>
        <span v-if="!compact" class="gm-switch" :class="{ on: dark }" aria-hidden="true"><i></i></span>
      </button>
      <Link :href="route('profile.show')" class="gm-user" :data-tip="compact ? me.name : null" :aria-label="$t('Profile')">
        <span class="gm-av"><img v-if="me.profile_photo_url" :src="me.profile_photo_url" alt="" draggable="false" /><i class="gm-online" aria-hidden="true"></i></span>
        <span v-if="!compact" class="gm-who"><b>{{ me.name }}</b><small>{{ me.email }}</small></span>
        <Icons v-if="!compact" name="chevron-down" class-name="gm-ic gm-ic-s" />
      </Link>
    </footer>

    <!-- the tooltip is drawn on the page itself, so no panel can cut it and nothing can cover it -->
    <teleport to="body">
      <div v-if="tip.on" ref="tipEl" class="gm-tip" :class="{ 'is-ready': tip.ready }" :style="{ left: tip.x + 'px', top: tip.y + 'px' }" role="tooltip">{{ tip.text }}</div>
    </teleport>
  </aside>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import Menus from '@/Core/menu';
import sortMenus from '@/Core/sortMenus';
import FullMainMenu from '@/Shared/Sidebar/FullMainMenu.vue';
import { DEFAULT_SHORTCUT, formatShortcut } from '@/Shared/Rauncher/shortcut';

// The Glass menu: a floating panel of frosted glass. Wide: search, the single links under "Main", every group under "Workspace" that opens to its own items, the
// night mode switch and the person. Narrow: only icons, each with a tooltip. The rules of who sees what are exactly those of the classic menu (its own functions
// are used, so a permission, a role or a Feature Control change reads the same in all three menus).
const M = FullMainMenu.methods;
const SAME = ['hasIssueReturnRole', 'isIssueReturnMenu', 'isTimelyUsageMenu', 'isReportsParentWithTimely', 'hasTimelyUsageRole', 'hasProjectManagerRole', 'hasProcurementManagerRole', 'isReportsMenu', 'isReportsParent', 'isActivitiesMenu',
  'canAccessMenu', 'allowsRole', 'featureEnabled', 'featureVisible', 'featureDisabled', 'menuHref', 'trans', 'isActiveUrl', 'isResourceIndexActive', 'isDropdownActive'];
const shared = {};
SAME.forEach(k => { shared[k] = M[k]; });
const DOTS = ['#34d399', '#fb923c', '#a78bfa', '#60a5fa', '#f472b6', '#fbbf24', '#2dd4bf', '#f87171'];

export default {
  components: { Link },
  props: { collapsed: Boolean, mobile: Boolean },
  emits: ['toggle', 'navigate'],
  data() {
    return { rawMenus: Menus, openKey: '', tick: 0, dark: false, tip: { on: false, ready: false, text: '', x: 0, y: 0 } };
  },
  computed: {
    compact() { return this.collapsed && !this.mobile; },
    appName() { return this.$t('RANS WIMS'); },
    me() { return this.$page.props.auth?.user || {}; },
    roleLine() { const r = (this.me.roles || [])[0]; return r ? this.$t(r.name) : this.$t('Workflow Integrated Management System'); },
    kbd() {
      const s = this.me.extra_attributes?.launcher_shortcut || DEFAULT_SHORTCUT;
      const parts = [];
      if (s.ctrl) parts.push('Ctrl');
      if (s.meta) parts.push('⌘');
      if (s.alt) parts.push('Alt');
      if (s.shift) parts.push('Shift');
      parts.push(String(s.key || '').toUpperCase());

      return parts.length > 2 ? '' : formatShortcut(s).replace('⌘/Win', '⌘').replace(/ \+ /g, '');
    },
    darkAllowed() { return this.$page.props.enabledFeatures?.dark_mode !== false || this.$super; },
    menus() { return sortMenus(this.rawMenus, m => this.trans(m.lang)); },
    // "Main" gathers the single links (Dashboard and the like); every other group is a menu that opens to its items
    sections() {
      void this.tick; void this.$page.url; // the menu follows the page by itself: a new page lights its own item
      const groups = [];
      const main = [];
      this.menus.forEach(menu => {
        if (!(this.canAccessMenu(menu) && this.allowsRole(menu) && this.featureVisible(menu))) return;
        if (!menu.sub_menu) {
          if ((!menu.admin || this.$admin) && !menu.hidden) main.push({ name: menu.name, href: this.menuHref(menu), icon: menu.icon, title: this.trans(menu.lang), active: this.isActiveUrl(this.menuHref(menu)), off: this.$super && this.featureDisabled(menu) });
          return;
        }
        if (!menu.sub_menu.length) return;
        const items = menu.sub_menu.filter(sm => this.canAccessMenu(sm) && this.allowsRole(sm) && this.featureVisible(sm) && !sm.hidden)
          .map(sm => ({ name: sm.name, href: this.menuHref(sm), icon: sm.icon, title: this.trans(sm.lang), active: this.isResourceIndexActive(sm) }));
        if (!items.length) return;
        groups.push({ key: menu.name, icon: menu.icon, title: this.trans(menu.lang), items, here: this.isDropdownActive(menu.sub_menu, menu.name) || items.some(i => i.active) });
      });

      return { main, groups };
    },
    main() { return this.sections.main; },
    groups() { return this.sections.groups; },
    hereKey() { const g = this.groups.find(x => x.here); return g ? g.key : ''; },
  },
  watch: {
    '$page.url'(a, b) {
      if ((a || '').split('?')[0] === (b || '').split('?')[0]) return;
      setTimeout(() => { this.tick++; this.$nextTick(() => { this.openKey = this.hereKey; this.$nextTick(this.revealActive); }); }, 0);
    },
  },
  created() {
    this.openKey = this.hereKey;
    this.syncTheme();
  },
  mounted() {
    this.$nextTick(this.revealActive);
    this._mo = typeof MutationObserver !== 'undefined' ? new MutationObserver(this.syncTheme) : null;
    if (this._mo) this._mo.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
  },
  beforeUnmount() { if (this._mo) this._mo.disconnect(); clearTimeout(this._tt); },
  methods: {
    ...shared,
    dotColor(i) { return DOTS[i % DOTS.length]; },
    syncTheme() { this.dark = document.documentElement.classList.contains('dark'); },
    toggleTheme() { this.$setTheme(this.dark ? 'light' : 'dark'); this.syncTheme(); },
    // the search box opens the page launcher (the same one the keyboard shortcut opens)
    openSearch() { window.dispatchEvent(new Event('wims:rauncher')); },
    onNavigate() { this.$emit('navigate'); },
    // a group opens to its items; on a narrow menu there is no room for them, so the menu opens wide on that group
    toggleGroup(g) {
      if (this.compact) { this.openKey = g.key; this.$emit('toggle'); this.$nextTick(this.revealActive); return; }
      this.openKey = this.openKey === g.key ? '' : g.key;
    },
    // brings the active link into view inside the menu's own scroll box (never scrolls the page)
    revealActive() {
      const box = this.$refs.scroll;
      const el = box && box.querySelector('.is-on');
      if (!el || !box.clientHeight) return;
      const b = box.getBoundingClientRect(), r = el.getBoundingClientRect();
      if (r.top >= b.top + 30 && r.bottom <= b.bottom - 30) return;
      box.scrollTo({ top: Math.max(0, box.scrollTop + (r.top - b.top) - (box.clientHeight / 2 - r.height / 2)), behavior: 'auto' });
    },
    // tooltips: only where there is a pointer to rest on; the text is the data-tip of the element under it
    tipOver(e) {
      if (!window.matchMedia || !window.matchMedia('(hover: hover)').matches) return;
      const t = e.target && e.target.closest ? e.target.closest('[data-tip]') : null;
      if (!t || !this.$el.contains(t) || t === this._tipEl) return;
      clearTimeout(this._tt); this._tipEl = t; this.tip.on = false;
      this._tt = setTimeout(() => this.tipShow(t), e.type === 'focusin' ? 0 : 220);
    },
    tipOut(e) {
      const t = e.target && e.target.closest ? e.target.closest('[data-tip]') : null;
      if (t && t === this._tipEl && !(e.relatedTarget && t.contains(e.relatedTarget))) this.tipHide();
    },
    tipHide() { clearTimeout(this._tt); this._tipEl = null; if (this.tip.on) { this.tip.on = false; this.tip.ready = false; } },
    tipShow(t) {
      if (!t.isConnected || !t.dataset.tip) return;
      this.tip = { on: true, ready: false, text: t.dataset.tip, x: -9999, y: 0 };
      this.$nextTick(() => {
        const el = this.$refs.tipEl; if (!el || this._tipEl !== t) return;
        const r = t.getBoundingClientRect(), w = el.offsetWidth, h = el.offsetHeight, vh = window.innerHeight, G = 12, M = 8;
        this.tip = { on: true, ready: true, text: t.dataset.tip, x: r.right + G, y: Math.min(Math.max(M, r.top + r.height / 2 - h / 2), vh - h - M) };
      });
    },
  },
};
</script>

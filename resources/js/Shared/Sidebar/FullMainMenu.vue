<template>
  <div class="liquid-full-sidebar text-slate-800 w-full md:w-64 grid grid-cols-1 place-content-between overflow-x-hidden overflow-y-auto">
    <div>
      <div class="md:py-2">
        <template v-if="$page.props.settings.sidebar_style == 'dropdown'">
          <template v-for="(menu, mi) in menus" :key="menu.name">
            <div v-if="menu.heading" class="hidden md:block mt-2 font-bold text-[11px] uppercase tracking-wider text-slate-600 px-4 py-2">
              {{ $t(menu.heading) }}
            </div>

            <menu-item
              :href="menuHref(menu)"
              v-if="canAccessMenu(menu) && allowsRole(menu) && featureVisible(menu) && (!menu.admin || $admin) && !menu.sub_menu && !menu.hidden"
              :class="{ 'liquid-menu-active text-slate-900': isActiveUrl(menuHref(menu)) }"
              class="menu-item-in"
              :style="menuInDelay(mi)"
            >
              <template #icon v-if="menu.icon">
                <span class="menu-icon-chip" :class="iconColorClass(mi)">
                  <Icons :name="menu.icon" />
                </span>
              </template>
              {{ trans(menu.lang) }}
            </menu-item>

            <dropdown-menu
              v-else-if="canAccessMenu(menu) && allowsRole(menu) && featureVisible(menu) && menu.sub_menu.length"
              :active="isDropdownActive(menu.sub_menu, menu.name)"
              class="menu-item-in"
              :style="menuInDelay(mi)"
            >
              <template #icon v-if="menu.icon">
                <span class="menu-icon-chip" :class="iconColorClass(mi)">
                  <Icons :name="menu.icon" />
                </span>
              </template>
              {{ trans(menu.lang) }}
              <span v-if="$super && featureDisabled(menu)" class="menu-feature-disabled-badge">{{ $t('Disabled') }}</span>
              <template #dropdown>
                <div class="menu-tree">
                  <template v-for="(sm, smi) in menu.sub_menu" :key="sm.name">
                    <menu-item
                      space="less"
                      :href="menuHref(sm)"
                      v-if="canAccessMenu(sm) && allowsRole(sm) && featureVisible(sm) && !sm.hidden"
                      class="menu-tree-item menu-item-in"
                      :class="{ 'liquid-menu-active text-slate-900': isResourceIndexActive(sm), 'border-transparent': smi + 1 == menu.sub_menu.length, 'menu-tree-item-small': sm.small }"
                      :style="menuInDelay(smi, true)"
                    >
                      <template #icon v-if="sm.icon">
                        <span class="menu-icon-chip" :class="iconColorClass(mi)">
                          <Icons :name="sm.icon" />
                        </span>
                      </template>
                      {{ trans(sm.lang) }}
                    </menu-item>
                  </template>
                </div>
              </template>
            </dropdown-menu>
          </template>
        </template>

        <template v-else>
          <template v-for="(menu, mi) in menus" :key="menu.name">
            <div v-if="menu.heading" class="block mt-2 font-bold text-[11px] uppercase tracking-wider text-slate-600 px-4 py-2">
              {{ $t(menu.heading) }}
            </div>

            <menu-item
              :href="menuHref(menu)"
              :class="{ 'liquid-menu-active text-slate-900': isActiveUrl(menuHref(menu)) }"
              v-if="canAccessMenu(menu) && allowsRole(menu) && featureVisible(menu) && (!menu.admin || $admin) && !menu.sub_menu && !menu.hidden"
              class="menu-item-in"
              :style="menuInDelay(mi)"
            >
              <template #icon v-if="menu.icon">
                <span class="menu-icon-chip" :class="iconColorClass(mi)">
                  <Icons :name="menu.icon" />
                </span>
              </template>
              {{ trans(menu.lang) }}
            </menu-item>

            <template v-else-if="featureVisible(menu) && menu.sub_menu.length">
              <div v-if="canAccessMenu(menu) && allowsRole(menu)" class="block mt-2 font-bold text-[11px] uppercase tracking-wider text-slate-300 px-4 py-2">
                {{ trans(menu.lang) }}
                <span v-if="$super && featureDisabled(menu)" class="menu-feature-disabled-badge">{{ $t('Disabled') }}</span>
              </div>

              <div class="menu-tree">
              <template v-for="(sm, smi) in menu.sub_menu" :key="sm.name">
                <menu-item
                  :href="menuHref(sm)"
                  v-if="canAccessMenu(sm) && allowsRole(sm) && featureVisible(sm) && !sm.hidden"
                  class="menu-tree-item menu-item-in"
                  :class="{ 'liquid-menu-active text-slate-900': isResourceIndexActive(sm), 'menu-tree-item-small': sm.small }"
                  :style="menuInDelay(mi)"
                >
                  <template #icon v-if="sm.icon">
                    <span class="menu-icon-chip" :class="iconColorClass(mi)">
                      <Icons :name="sm.icon" />
                    </span>
                  </template>
                  {{ trans(sm.lang) }}
                </menu-item>
              </template>
              </div>
            </template>
          </template>
        </template>
      </div>
    </div>

    <!-- Other Links -->
    <div class="py-3 my-4 sm:my-0">
      <icon-menus class="mx-3 md:mx-0 gap-x-6 md:gap-x-3 flex items-center justify-between sm:hidden" />
    </div>
  </div>
</template>

<script>
import Menus from '@/Core/menu';
import sortMenus from '@/Core/sortMenus';
import { SITE_ENGINEER_ROLES } from '@/Core/siteEngineerRoles';
import IconMenus from '@/Shared/Top/IconMenus.vue';
import MenuItem from '@/Shared/Sidebar/MenuItem.vue';
import DropdownMenu from '@/Shared/Sidebar/DropdownMenu.vue';

export default {
  components: { MenuItem, DropdownMenu, IconMenus },
  data() {
    return { rawMenus: Menus, current: '' };
  },
  mounted() {
    // just opened (a page load, or the tablet / phone drawer sliding in): jump to the active item at once
    this.scrollToActiveMenuItem({ instant: true });
    ['touchstart', 'wheel', 'pointerdown'].forEach(ev => this.$el.addEventListener(ev, this.menuTouched, { passive: true }));
  },
  beforeUnmount() {
    ['touchstart', 'wheel', 'pointerdown'].forEach(ev => this.$el && this.$el.removeEventListener(ev, this.menuTouched));
    clearTimeout(this.touchTimer);
    this.scrollToken = -1;
  },
  watch: {
    // Only re-scroll on a genuine navigation to a different page — not on
    // every keystroke of an in-page search/filter, which revisits the same
    // route with just the query string changed (e.g. Transfers' search box).
    // Re-centering the active sidebar item on each of those partial reloads
    // made the whole menu visibly jump up and down while typing.
    '$page.url'(newUrl, oldUrl) {
      if ((newUrl || '').split('?')[0] === (oldUrl || '').split('?')[0]) {
        return;
      }
      this.scrollToActiveMenuItem();
    },
  },
  computed: {
    menus() {
      return sortMenus(this.rawMenus, (m) => this.trans(m.lang));
    },
  },
  methods: {
    // Brings the active item into view inside the menu's OWN scroll box. It never uses scrollIntoView (that also scrolls the page and every container
    // above the menu, which made the tablet drawer and the page behind it jump), it does nothing while the menu is hidden (the desktop menu is
    // hidden on a tablet), it jumps straight there when the menu has just opened (no long scroll animation while the drawer slides in), it leaves
    // the menu alone when the item is already in view, and it stops the moment the person touches, wheels or scrolls the menu themselves.
    scrollToActiveMenuItem(opts = {}) {
      const box = this.$el;
      if (!box || !box.getBoundingClientRect) return;
      const token = (this.scrollToken = (this.scrollToken || 0) + 1);
      let attempts = 0;
      // the next frame, or a short timer when the page is in the background and no frames are drawn
      const later = fn => {
        let done = false;
        const once = () => { if (!done) { done = true; fn(); } };
        requestAnimationFrame(once);
        setTimeout(once, 50);
      };
      const tryScroll = () => {
        if (token !== this.scrollToken || this.userTouchedMenu) return; // a newer request, or the person is scrolling the menu
        if (!box.clientHeight) return; // hidden (display: none) on this screen size
        // Prefer a specific active sub-menu leaf; only fall back to the dropdown trigger itself when the group has no single matching leaf.
        // (the group's own leaf appears a moment after the group opens, so the trigger is used only when no leaf shows up within about 12 frames)
        const active = box.querySelector('.liquid-menu-active') || (attempts >= 12 ? box.querySelector('.liquid-dropdown-active') : null);
        if (active) {
          // the menu's own scroll box: the nearest ancestor of the item that scrolls (inside this menu only)
          let scroller = active.parentElement;
          while (scroller && scroller !== box.parentElement && !(/(auto|scroll)/.test(getComputedStyle(scroller).overflowY) && scroller.scrollHeight > scroller.clientHeight + 1)) scroller = scroller.parentElement;
          // the menu may not be tall enough to scroll yet (a group is still opening): try again in a moment
          if (!scroller || scroller === box.parentElement) {
            if (++attempts < 60) later(tryScroll);
            return;
          }
          const b = scroller.getBoundingClientRect();
          const r = active.getBoundingClientRect();
          const margin = 56;
          if (r.top >= b.top + margin && r.bottom <= b.bottom - margin) return; // already comfortably in view
          const top = Math.max(0, scroller.scrollTop + (r.top - b.top) - (scroller.clientHeight / 2 - r.height / 2));
          const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
          scroller.scrollTo({ top, behavior: opts.instant || reduceMotion ? 'auto' : 'smooth' });
          return;
        }
        // a child DropdownMenu opens itself from its own watcher: wait for it (up to about a second)
        if (++attempts < 60) later(tryScroll);
      };
      this.$nextTick(() => later(tryScroll));
    },
    // the person took hold of the menu: whatever it was about to scroll to must not fight their finger
    menuTouched() {
      this.userTouchedMenu = true;
      clearTimeout(this.touchTimer);
      this.touchTimer = setTimeout(() => (this.userTouchedMenu = false), 2500);
    },
    hasIssueReturnRole() {
      const userRoles = (this.$page.props.auth?.user?.roles || []).map(role => role.name);
      return userRoles.some(role => SITE_ENGINEER_ROLES.includes(role));
    },
    isIssueReturnMenu(menu) {
      const name = String(menu?.name || '');
      const route = String(menu?.route || '');
      if (name.includes('issue-return-reports') || route.includes('issue-return-reports')) {
        return false;
      }
      return name.includes('issue-return') || name.includes('issue-material') || name.includes('return-material') || route.includes('issue-return') || route.includes('issue-material') || route.includes('return-material');
    },
    isTimelyUsageMenu(menu) {
      const name = String(menu?.name || '');
      const route = String(menu?.route || '');
      return name === 'reports.timely_material_usage' || route === 'reports.timely_material_usage';
    },
    isReportsParentWithTimely(menu) {
      if (String(menu?.name || '') !== 'reports' || !Array.isArray(menu?.sub_menu)) {
        return false;
      }

      return menu.sub_menu.some(sm => this.isTimelyUsageMenu(sm));
    },
    hasTimelyUsageRole() {
      const userRoles = (this.$page.props.auth?.user?.roles || []).map(role => role.name);
      return userRoles.some(role => SITE_ENGINEER_ROLES.includes(role));
    },
    hasProjectManagerRole() {
      const userRoles = (this.$page.props.auth?.user?.roles || []).map(role => role.name);
      return userRoles.includes('Project Manager') || userRoles.includes('Project Managers');
    },
    hasProcurementManagerRole() {
      const userRoles = (this.$page.props.auth?.user?.roles || []).map(role => role.name);
      return userRoles.includes('Procurement Manager') || userRoles.includes('Procurement Manger');
    },
    isReportsMenu(menu) {
      const route = String(menu?.route || '');
      const name = String(menu?.name || '');
      return route.startsWith('reports.') || name.startsWith('reports.');
    },
    isReportsParent(menu) {
      return String(menu?.name || '') === 'reports';
    },
    isActivitiesMenu(menu) {
      const route = String(menu?.route || '');
      const name = String(menu?.name || '');
      return route === 'activities.index' || name === 'activities';
    },
    canAccessMenu(menu) {
      if (this.$can(menu?.permissions || [])) {
        return true;
      }

      if (this.hasProjectManagerRole() && this.isActivitiesMenu(menu)) {
        return true;
      }

      if ((this.hasProjectManagerRole() || this.hasProcurementManagerRole()) && (this.isReportsMenu(menu) || this.isReportsParent(menu))) {
        return true;
      }

      if (this.hasTimelyUsageRole() && (this.isTimelyUsageMenu(menu) || this.isReportsParentWithTimely(menu))) {
        return true;
      }

      return this.hasIssueReturnRole() && this.isIssueReturnMenu(menu);
    },
    allowsRole(menu) {
      // entries that exist only in Simulation WIMS (env: 'simulation') or only in production (env: 'production')
      if (menu?.env === 'simulation' && !this.$page.props.environment) return false;
      if (menu?.env === 'production' && this.$page.props.environment) return false;
      if (!menu.roles || !menu.roles.length) {
        return true;
      }

      const userRoles = (this.$page.props.auth?.user?.roles || []).map(role => role.name);
      return menu.roles.some(role => userRoles.includes(role));
    },
    featureEnabled(menu) {
      if (!menu?.feature) {
        return true;
      }
      return this.$page.props.enabledFeatures?.[menu.feature] !== false;
    },
    featureVisible(menu) {
      return this.featureEnabled(menu) || this.$super;
    },
    featureDisabled(menu) {
      return !!menu?.feature && !this.featureEnabled(menu);
    },
    // Lets a menu entry pin extra query params onto its own link (e.g. the
    // "Suppliers" shortcut under Supplier Records opening Contacts
    // pre-filtered to that category) without needing a dedicated route.
    menuHref(item) {
      return item?.route ? route(item.route, item.params || {}) : '#';
    },
    trans(key) {
      if (key.helper) {
        return this.$t(key.helper, { x: this.$t(key.main) });
      }
      return this.$t(key.main);
    },
    isActiveUrl(url, dropdown = false) {
      let currentUrl = this.$page.props.settings.baseUrl + (this.$page.url != '/' ? this.$page.url : '');
      if (dropdown) {
        let open = false;
        url.map(q => {
          if (currentUrl.includes(q)) {
            open = true;
          }
        });
        return open;
      }
      return url.split('?')[0] == currentUrl.split('?')[0];
    },
    // A sidebar entry for a resource's ".index" route (e.g.
    // material-reallocations.index) should also read as active on that
    // same resource's create/edit/show pages — otherwise a dropdown parent
    // never auto-expands and scrollToActiveMenuItem() has nothing to
    // scroll to when landing straight on e.g. /material-reallocations/create.
    // Deliberately NOT a loose URL-prefix match: routeName is checked
    // against an explicit whitelist of Laravel's own REST action suffixes
    // on the exact same resource base, so it can never cross-match an
    // unrelated route that merely shares a short leading path segment
    // (e.g. visiting /reports/material-movement — itself an exact-match-only
    // leaf under Material Reallocation's own dropdown these days — must
    // NOT also light up the separate, short-pathed "Reports" sidebar link,
    // which a plain "currentUrl starts with /reports" test would wrongly do).
    // Accepts either a plain route-name string or a menu-item object
    // ({ route, params }) — an object is required to disambiguate two
    // sidebar links that legitimately share the same route name (see the
    // Suppliers/Contacts special case below).
    isResourceIndexActive(item) {
      const routeName = typeof item === 'string' ? item : item?.route;
      const params = typeof item === 'string' ? null : item?.params;
      const current = typeof route === 'function' && route().current ? route().current() : null;
      if (!current || !routeName) {
        return false;
      }
      if (current === routeName) {
        return true;
      }
      // The "Suppliers" sidebar link points at contacts.index (filtered to
      // category=Suppliers) since Suppliers ARE Contacts — but a supplier's
      // own profile pages (Ledger, Statement, Reconciliations, the profile
      // itself) live under a completely different route family
      // (supplier-records.suppliers.*). Recognize those here too, so opening
      // one still highlights "Suppliers" and auto-expands Supplier Records
      // instead of leaving the sidebar with nothing active to scroll to.
      // Gated on params.category === 'Suppliers' specifically — Contacts'
      // own plain "List All" item shares the exact same contacts.index
      // route name but must NOT light up when viewing a supplier profile.
      if (routeName === 'contacts.index' && params?.category === 'Suppliers' && current.startsWith('supplier-records.suppliers.')) {
        return true;
      }
      // Bulk Connect's own History/print/download pages live under
      // wims-connect.bulk-connect.history.* — a sibling of, not a suffix on,
      // the sidebar leaf's own wims-connect.bulk-connect.index route, so the
      // generic .create/.edit/.show suffix check below never matches. Without
      // this, opening History leaves nothing "active" for
      // scrollToActiveMenuItem() (mounted()/$page.url watcher, above) to find
      // and scroll to.
      if (routeName === 'wims-connect.bulk-connect.index' && current.startsWith('wims-connect.bulk-connect.')) {
        return true;
      }
      // Material Reallocation's own Reports hub lives at
      // material-reallocations.reports — a sibling of, not a suffix on, the
      // sidebar leaf's material-reallocations.index route, so the generic
      // .create/.edit/.show suffix check below never matches it either.
      if (routeName === 'material-reallocations.index' && current === 'material-reallocations.reports') {
        return true;
      }
      // The pages that belong to an item but have no sidebar entry of their own (Print Barcodes, stock trail, price history, ...) read as "Items"
      // so the Items group opens and scrolls into view; Import / Alerts / Create keep their own leaves.
      if (routeName === 'items.index' && current.startsWith('items.') && !/^items\.(import|workflow|create|index)/.test(current)) {
        return true;
      }
      if (!routeName.endsWith('.index')) {
        return false;
      }
      const base = routeName.slice(0, -'.index'.length);
      // a group with its own "Create" leaf lights that leaf on the create page, not the list above it too
      if (current === base + '.create' && this.menus.some(m => (m.sub_menu || []).some(sm => sm.route === base + '.create'))) {
        return false;
      }
      return ['.create', '.edit', '.show', '.store', '.update', '.destroy'].some(suffix => current === base + suffix);
    },
    // Whether a dropdown parent should auto-expand: derived straight from
    // its own sub_menu's real routes (exact match, same test a leaf item
    // uses) rather than a separately hand-maintained `children` substring
    // list. That list drifted out of sync the moment items moved between
    // groups — e.g. after Material Reallocation's reports moved out of
    // Reports, Reports' own `children: ['reports']` still matched any
    // /reports/* URL (a blunt substring test), so it kept auto-expanding
    // for pages it no longer owns. This can't drift: it always reflects
    // exactly what's actually nested under this parent right now.
    isDropdownActive(subMenu, menuName) {
      if ((subMenu || []).some(sm => sm.route && this.isResourceIndexActive(sm))) {
        return true;
      }
      // Project Certificates' per-project pages (warehouses/{warehouse}/
      // certificates, its contract setup, and the certificate builder/show
      // pages) live under project-certificates.project.*, .contract.*, and
      // plain project-certificates.{create,show,...} — route families with
      // no single-project sidebar link to match exactly (there's no static
      // "this project" URL to point one at). Unlike Reports (see the note
      // above), nothing else could plausibly own a project-certificates.*
      // route, so a full namespace match here is safe and won't drift.
      if (menuName === 'project-certificates') {
        const current = typeof route === 'function' && route().current ? route().current() : null;
        return !!current && current.startsWith('project-certificates.');
      }
      return false;
    },
    iconColorClass(index) {
      const palette = ['chip-blue', 'chip-violet', 'chip-emerald', 'chip-amber', 'chip-rose', 'chip-cyan', 'chip-indigo', 'chip-slate'];
      return palette[index % palette.length];
    },
    // Staggered entrance delay for the sidebar's one-time reveal — capped
    // low (300ms for top-level items, 200ms for a dropdown's own leaves) so
    // a long menu list still settles quickly rather than trickling in.
    // Since these elements keep a stable :key across Inertia navigations,
    // the CSS animation this feeds only plays once per full page load, not
    // on every in-app visit — a dropdown's leaves are the one exception,
    // replaying each time that group opens (it's genuinely re-inserted via
    // v-if), which reads as a small intentional reveal rather than a bug.
    menuInDelay(index, isSubItem = false) {
      const step = isSubItem ? 25 : 30;
      const cap = isSubItem ? 200 : 300;
      return { animationDelay: Math.min(index * step, cap) + 'ms' };
    },
  },
};
</script>

<style scoped>
.menu-feature-disabled-badge {
  display: inline-flex;
  align-items: center;
  margin-left: 6px;
  padding: 1px 6px;
  border-radius: 999px;
  font-size: 10.5px;
  font-weight: 700;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: #b91c1c;
  background: #fee2e2;
  vertical-align: middle;
}

.liquid-full-sidebar {
  border-right: 1px solid #eef1f6;
  background: #ffffff;
}

@property --menu-glow-angle {
  syntax: '<angle>';
  inherits: false;
  initial-value: 0deg;
}

/* Current page: soft blue fill, a slowly spinning gradient outline with a blurred glow
   behind it, and a shadow that falls below the item. */
.liquid-menu-active {
  position: relative;
  z-index: 1;
  isolation: isolate;
  border-color: transparent !important;
  background: #eff6ff !important;
  font-weight: 700;
  box-shadow: 0 12px 18px -10px rgba(59, 91, 245, 0.55), 0 4px 8px -4px rgba(59, 91, 245, 0.25);
}

.liquid-menu-active:not(.menu-tree-item)::before,
.liquid-menu-active:not(.menu-tree-item)::after {
  content: '';
  position: absolute;
  inset: -1px;
  border-radius: inherit;
  padding: 2px;
  background: conic-gradient(from var(--menu-glow-angle), #60a5fa, #a78bfa, #f0abfc, #38bdf8, #34d399, #60a5fa);
  -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
  -webkit-mask-composite: xor;
  mask-composite: exclude;
  pointer-events: none;
  animation: menu-glow-spin 4s linear infinite;
}
.liquid-menu-active:not(.menu-tree-item)::before { z-index: 1; }
.liquid-menu-active:not(.menu-tree-item)::after {
  z-index: 0;
  inset: -3px;
  padding: 4px;
  filter: blur(7px);
  opacity: 0.85;
}

@keyframes menu-glow-spin {
  to { --menu-glow-angle: 360deg; }
}
@media (prefers-reduced-motion: reduce) {
  .liquid-menu-active:not(.menu-tree-item)::before, .liquid-menu-active:not(.menu-tree-item)::after { animation: none; }
}

.menu-icon-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 9px;
  margin-right: 10px;
  flex-shrink: 0;
  transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.25s ease, filter 0.2s ease;
}

.liquid-menu-item:hover .menu-icon-chip,
.liquid-dropdown-trigger:hover .menu-icon-chip,
.menu-tree-item:hover .menu-icon-chip {
  transform: scale(1.14) rotate(-6deg);
  filter: saturate(1.35) brightness(1.05);
}

.liquid-menu-active .menu-icon-chip {
  animation: menu-chip-live 2.6s ease-in-out infinite;
}

@keyframes menu-chip-live {
  0%,
  100% {
    box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.22);
  }
  50% {
    box-shadow: 0 0 0 5px rgba(37, 99, 235, 0);
  }
}

.chip-blue { background: #dbeafe; color: #2563eb; }
.chip-violet { background: #ede9fe; color: #7c3aed; }
.chip-emerald { background: #d1fae5; color: #059669; }
.chip-amber { background: #fef3c7; color: #d97706; }
.chip-rose { background: #ffe4e6; color: #e11d48; }
.chip-cyan { background: #cffafe; color: #0891b2; }
.chip-indigo { background: #e0e7ff; color: #4f46e5; }
.chip-slate { background: #e2e8f0; color: #475569; }

/* The sub-menu "wire": a soft blue to violet rail with a glow, a small light that drifts down it (the workflow flowing through the
   module), and a node on the rail for every page that lights up when you hover it and glows when it is the page you are on. */
.menu-tree {
  position: relative;
  margin-left: 30px;
  padding-left: 12px;
}
.menu-tree::before {
  content: '';
  position: absolute;
  left: 0;
  top: 6px;
  bottom: 6px;
  width: 2px;
  border-radius: 2px;
  background: linear-gradient(180deg, #7dd3fc 0%, #818cf8 45%, #c4b5fd 75%, rgba(196, 181, 253, 0.2) 100%);
  box-shadow: 0 0 8px rgba(99, 102, 241, 0.38), 0 0 2px rgba(125, 211, 252, 0.7);
}
.menu-tree::after {
  content: '';
  position: absolute;
  left: -2px;
  top: 6px;
  width: 6px;
  height: 22px;
  border-radius: 6px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0), rgba(255, 255, 255, 0.95) 70%, rgba(125, 211, 252, 0.9));
  box-shadow: 0 0 10px 2px rgba(129, 140, 248, 0.55);
  opacity: 0;
  pointer-events: none;
  animation: menu-wire-flow 5.5s cubic-bezier(0.45, 0, 0.25, 1) infinite;
}
@keyframes menu-wire-flow {
  0% { top: 6px; opacity: 0; }
  12% { opacity: 1; }
  80% { opacity: 1; }
  100% { top: calc(100% - 28px); opacity: 0; }
}

.menu-tree-item {
  position: relative;
}

/* the connector from the rail to the page */
.menu-tree-item::before {
  content: '';
  position: absolute;
  left: -12px;
  top: 50%;
  width: 12px;
  height: 2px;
  margin-top: -1px;
  border-radius: 2px;
  background: linear-gradient(90deg, rgba(129, 140, 248, 0.65), rgba(129, 140, 248, 0.12));
  transition: background 0.2s ease, box-shadow 0.2s ease;
}
/* the node on the rail */
.menu-tree-item::after {
  content: '';
  position: absolute;
  left: -15px;
  top: 50%;
  width: 7px;
  height: 7px;
  margin-top: -3.5px;
  border-radius: 50%;
  background: #fff;
  border: 1.5px solid #a5b4fc;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.08);
  transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
}
.menu-tree-item:hover::before {
  background: linear-gradient(90deg, #6366f1, #38bdf8);
  box-shadow: 0 0 8px rgba(99, 102, 241, 0.55);
}
.menu-tree-item:hover::after {
  transform: scale(1.25);
  border-color: #6366f1;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.18), 0 0 10px rgba(99, 102, 241, 0.6);
}
.liquid-menu-active.menu-tree-item::before {
  background: linear-gradient(90deg, #6366f1, #38bdf8);
  box-shadow: 0 0 8px rgba(99, 102, 241, 0.5);
}
.liquid-menu-active.menu-tree-item::after {
  background: linear-gradient(135deg, #38bdf8, #6366f1);
  border-color: #fff;
  transform: scale(1.2);
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.22), 0 0 12px rgba(99, 102, 241, 0.75);
  animation: menu-node-live 2.4s ease-in-out infinite;
}
@keyframes menu-node-live {
  0%, 100% { box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.22), 0 0 10px rgba(99, 102, 241, 0.7); }
  50% { box-shadow: 0 0 0 6px rgba(99, 102, 241, 0), 0 0 16px rgba(56, 189, 248, 0.85); }
}
@media (prefers-reduced-motion: reduce) {
  .menu-tree::after { animation: none; opacity: 0; }
  .liquid-menu-active.menu-tree-item::after { animation: none; }
}

/* Opt-in (menu.js sub_menu entries set `small: true`) for items that are
   really a report/page belonging to their parent module rather than a
   peer of it — e.g. Material Reallocation Summary / Material Movement
   Report nested under Material Reallocation. MenuItem.vue sets its own
   text-[13.5px] via a Tailwind utility on the same element this class
   lands on, so this needs !important to reliably win regardless of which
   stylesheet loads later. */
.menu-tree-item-small {
  font-size: 12px !important;
  color: #64748b;
}

/* One-time staggered reveal on sidebar mount (see menuInDelay()) — a plain
   CSS animation rather than a Vue <transition>, so it never fights the
   active-item highlighting/scrolling logic above: it only affects opacity
   and position, runs once per element insertion, and leaves no lingering
   styles once finished ("both" fills forward then the animation is done). */
.menu-item-in {
  animation: menu-item-reveal 0.35s ease both;
}
@keyframes menu-item-reveal {
  from {
    opacity: 0;
    transform: translateX(-10px);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}
@media (prefers-reduced-motion: reduce) {
  .menu-item-in {
    animation: none;
  }
}
</style>

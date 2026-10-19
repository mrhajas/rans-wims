<template>
  <div class="liquid-mini-sidebar text-slate-700 w-full h-full md:w-16 grid grid-cols-1 place-content-between overflow-x-hidden overflow-y-auto">
    <div>
      <div class="lg:py-2">
        <template v-for="(menu, mi) in menus" :key="menu.name">
          <div v-if="canAccessMenu(menu) && menu.heading" class="hidden lg:block mt-1 font-bold text-xs text-slate-500 px-4 py-2">
            {{ $t(menu.heading) }}
          </div>

          <menu-item-icon
            :href="menuHref(menu)"
            :active="isActiveUrl(menuHref(menu))"
            :class="{ 'liquid-mini-active': isActiveUrl(menuHref(menu)) }"
            v-if="canAccessMenu(menu) && allowsRole(menu) && featureVisible(menu) && (!menu.admin || $admin) && !menu.sub_menu && !menu.hidden"
            class="menu-item-in"
            :style="menuInDelay(mi)"
          >
            <template #icon v-if="menu.icon">
              <Icons :name="menu.icon" />
            </template>
            {{ trans(menu.lang) }}
            <span v-if="$super && featureDisabled(menu)" class="menu-feature-disabled-badge">{{ $t('Disabled') }}</span>
          </menu-item-icon>

          <icon-sub-menu
            v-else-if="canAccessMenu(menu) && allowsRole(menu) && featureVisible(menu) && menu.sub_menu.length"
            :active="isDropdownActive(menu.sub_menu, menu.name)"
            class="menu-item-in"
            :style="menuInDelay(mi)"
          >
            <template #icon v-if="menu.icon">
              <Icons :name="menu.icon" />
            </template>
            {{ trans(menu.lang) }}
            <span v-if="$super && featureDisabled(menu)" class="menu-feature-disabled-badge">{{ $t('Disabled') }}</span>
            <template #dropdown>
              <div class="py-2">
                <template v-for="sm in menu.sub_menu" :key="sm.name">
                  <menu-item
                    space="less"
                    :hide-icon="true"
                    :href="menuHref(sm)"
                    v-if="canAccessMenu(sm) && allowsRole(sm) && featureVisible(sm) && !sm.hidden"
                    :class="{ 'liquid-mini-active': isResourceIndexActive(sm) }"
                  >
                    {{ trans(sm.lang) }}
                  </menu-item>
                </template>
              </div>
            </template>
          </icon-sub-menu>
        </template>
      </div>
    </div>

    <div class="py-4">
      <icon-menus class="mx-3 md:mx-0 gap-y-6 md:gap-y-4 flex flex-col items-center justify-center" />
    </div>
  </div>
</template>

<script>
import Menus from '@/Core/menu';
import sortMenus from '@/Core/sortMenus';
import { SITE_ENGINEER_ROLES } from '@/Core/siteEngineerRoles';
import IconMenus from '@/Shared/Top/IconMenus.vue';
import MenuItem from '@/Shared/Sidebar/MenuItem.vue';
import IconSubMenu from '@/Shared/Sidebar/IconSubMenu.vue';
import MenuItemIcon from '@/Shared/Sidebar/MenuItemIcon.vue';

export default {
  components: { MenuItem, MenuItemIcon, IconSubMenu, IconMenus },
  data() {
    return { rawMenus: Menus };
  },
  mounted() {
    this.scrollToActiveMenuItem();
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
    scrollToActiveMenuItem() {
      this.$nextTick(() => {
        const active = this.$el.querySelector('.liquid-mini-active, .liquid-icon-sub-active');
        if (!active) return;
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        active.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
      });
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
      // See FullMainMenu.vue's isResourceIndexActive for why: the
      // "Suppliers" link points at contacts.index, but a supplier's own
      // profile pages live under supplier-records.suppliers.* instead.
      // Gated on params.category === 'Suppliers' so Contacts' own plain
      // "List All" item (same route name, no params) doesn't also light up.
      if (routeName === 'contacts.index' && params?.category === 'Suppliers' && current.startsWith('supplier-records.suppliers.')) {
        return true;
      }
      // See FullMainMenu.vue: Bulk Connect's History/print/download pages
      // are siblings of, not suffixes on, its own .index route.
      if (routeName === 'wims-connect.bulk-connect.index' && current.startsWith('wims-connect.bulk-connect.')) {
        return true;
      }
      // See FullMainMenu.vue: Material Reallocation's Reports hub is a
      // sibling of, not a suffix on, its own .index route.
      if (routeName === 'material-reallocations.index' && current === 'material-reallocations.reports') {
        return true;
      }
      if (!routeName.endsWith('.index')) {
        return false;
      }
      const base = routeName.slice(0, -'.index'.length);
      return ['.create', '.edit', '.show', '.store', '.update', '.destroy'].some(suffix => current === base + suffix);
    },
    // See FullMainMenu.vue's isDropdownActive for why this derives from the
    // parent's own sub_menu routes instead of a hand-maintained substring
    // list — the latter drifts out of sync whenever items move between
    // menu groups.
    isDropdownActive(subMenu, menuName) {
      if ((subMenu || []).some(sm => sm.route && this.isResourceIndexActive(sm))) {
        return true;
      }
      // See FullMainMenu.vue's isDropdownActive for why Project Certificates
      // gets a full namespace match here instead of a sub_menu leaf match.
      if (menuName === 'project-certificates') {
        const current = typeof route === 'function' && route().current ? route().current() : null;
        return !!current && current.startsWith('project-certificates.');
      }
      return false;
    },
    // See FullMainMenu.vue's menuInDelay — same one-time staggered reveal,
    // just for the collapsed icon rail's top-level items.
    menuInDelay(index) {
      return { animationDelay: Math.min(index * 30, 300) + 'ms' };
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

.liquid-mini-sidebar {
  border-right: 1px solid rgba(255, 255, 255, 0.55);
  background: linear-gradient(165deg, rgba(255, 255, 255, 0.5), rgba(224, 242, 254, 0.3));
  backdrop-filter: blur(14px) saturate(150%);
  -webkit-backdrop-filter: blur(14px) saturate(150%);
  box-shadow:
    inset -1px 0 0 rgba(255, 255, 255, 0.45),
    16px 0 30px rgba(51, 93, 150, 0.1);
}

.liquid-mini-active {
  border: 1px solid rgba(255, 255, 255, 0.75) !important;
  background: linear-gradient(145deg, rgba(255, 255, 255, 0.88), rgba(186, 230, 253, 0.52)) !important;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.85), 0 8px 16px rgba(56, 105, 170, 0.16);
}

/* One-time staggered reveal on mount — see FullMainMenu.vue's identical
   .menu-item-in for the full rationale. */
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

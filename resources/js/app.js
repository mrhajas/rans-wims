import ProDockHost from '@/Shared/Sidebar/ProDockHost.vue';
import './bootstrap';
import '@/Core/zoomCompat';
import '@/Core/tipLayer';
import '@/Core/phoneNoWrap';
import '../css/app.css';
import '../css/dark.css';
import '../css/wimschat.css';
import '../css/sitemeetings.css';

import i18n from '@/Core/i18n';
import mixin from '@/Core/mixin';
import Icons from '@/Shared/Icons.vue';
import Boolean from '@/Shared/Boolean.vue';
import Loading from '@/Shared/Loading.vue';
import OfflineLock from '@/Shared/OfflineLock.vue';
import OfflineReportNotice from '@/Shared/OfflineReportNotice.vue';
import { LANGUAGES, SUPPORT_LOCALES, loadLocale } from '@/Core/i18n';
import { initIdleLogout } from '@/Core/idleLogout';
import IdleLogoutModal from '@/Shared/IdleLogoutModal.vue';
import GlobalDialog from '@/Shared/GlobalDialog.vue';
import { confirmDialog, alertDialog, promptDialog } from '@/Core/dialogs';
import { initOfflineStatus } from '@/Core/offlineStatus';
import { initOfflineSync } from '@/Core/offlineSync';
import { trackSectionVisit } from '@/Core/quickMenuTracking';
import tooltipDirective from '@/Core/tooltipDirective';
import { initFieldErrors } from '@/Core/fieldErrors';
import { initRequiredFields } from '@/Core/requiredFields';
import { initAutoNumber, setPagination } from '@/Core/autoNumber';
import { initSaveProgress } from '@/Core/saveProgress';
import SaveProgress from '@/Shared/SaveProgress.vue';
import CssSelect from '@/Shared/CssSelect.vue';
import DatePicker from '@/Shared/DatePicker.vue';

import { useI18n } from 'vue-i18n';
import { createApp, h } from 'vue';
import { route, ZiggyVue } from 'ziggy-js';
// the route table now arrives as its own cached script (ziggy.js), which no longer defines the global route() the page scripts call
window.route = route;
import labelizeDirective from './Core/labelizeDirective';
import { createInertiaApp, Head, Link, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { registerSW } from 'virtual:pwa-register';

if ('serviceWorker' in navigator) {
  window.__wimsApplyUpdate = registerSW({
    onNeedRefresh() {
      window.dispatchEvent(new CustomEvent('wims-pwa-update-available'));
    },
    onOfflineReady() {},
  });
}

const appName = window.document.getElementsByTagName('title')[0]?.innerText || 'WIMS';
const themeStorageKey = 'wims-theme';

const isAuthPage = component => component?.startsWith('Auth/');
const getStoredTheme = () => window.localStorage.getItem(themeStorageKey) || 'light';
// Dark mode is a client-side preference, but a Super Admin can switch it off
// site-wide via Global Feature Control. When disabled, everyone except
// Super Admins is forced back to light — even if they already had 'dark'
// stored from before the feature was turned off.
const isDarkModeAllowed = props => {
  if (props?.enabledFeatures?.dark_mode === false) {
    return (props?.auth?.user?.roles || []).some(r => r.name === 'Super Admin');
  }
  return true;
};
const applyTheme = page => {
  const component = page?.component;
  const root = document.documentElement;

  if (isAuthPage(component)) {
    root.classList.remove('dark');
    return;
  }

  const theme = getStoredTheme();
  if (theme === 'dark' && isDarkModeAllowed(page?.props)) {
    root.classList.add('dark');
  } else {
    root.classList.remove('dark');
  }
};

const setTheme = theme => {
  window.localStorage.setItem(themeStorageKey, theme);
  const root = document.documentElement;

  if (theme === 'dark') {
    root.classList.add('dark');
  } else {
    root.classList.remove('dark');
  }
};

const mountIdleLogoutModal = () => {
  const el = document.createElement('div');
  el.id = 'idle-logout-modal-root';
  document.body.appendChild(el);
  createApp(IdleLogoutModal).use(i18n).mount(el);
};

// Same standalone-mount pattern as mountIdleLogoutModal() above — a single
// global dialog instance, mounted once outside the Inertia page tree, so
// $confirm/$alert/$prompt (registered as global properties below) work
// from any component without that component owning any modal markup of
// its own.
// the small centred indicator for saves, updates, adds and deletes (and the notice that replaces the old corner message)
const mountSaveProgress = () => {
  const el = document.createElement('div');
  el.id = 'save-progress-root';
  document.body.appendChild(el);
  createApp(SaveProgress).use(i18n).mount(el);
};

const mountGlobalDialog = () => {
  const el = document.createElement('div');
  el.id = 'global-dialog-root';
  document.body.appendChild(el);
  createApp(GlobalDialog).use(i18n).mount(el);
};

createInertiaApp({
  title: title => `${title} - ${appName}`,
  resolve: name => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
  async setup({ el, App, props, plugin }) {
    // only the person's own language is downloaded now (the rest on switching)
    await loadLocale(window.Locale);

    let app = createApp({
      setup() {
        const { t } = useI18n();
        return { t };
      },
      // the Pro menu lives beside the page (it is not part of any page), so it is drawn once and stays while the pages change
      render: () => [h(App, props), h(ProDockHost)],
      mounted: () => {
        if (window.__wimsFinishLoading) {
          window.__wimsFinishLoading();
        } else {
          document.getElementById('app-loading').style.display = 'none';
        }
      },
    })
      .use(plugin)
      .use(i18n)
      .mixin(mixin)
      .mixin({
        created() {
          applyTheme(this.$page || props.initialPage);
          trackSectionVisit();
        },
        watch: {
          '$page.component'() {
            applyTheme(this.$page);
            trackSectionVisit();
          },
        },
      })
      .use(ZiggyVue, Ziggy)
      .component('Head', Head)
      .component('Link', Link)
      .component('Icons', Icons)
      .component('CssSelect', CssSelect)
      .component('DatePicker', DatePicker)
      .component('Boolean', Boolean)
      .component('Loading', Loading)
      .component('OfflineLock', OfflineLock)
      .component('OfflineReportNotice', OfflineReportNotice)
      .directive('tooltip', tooltipDirective)
      .directive('labelize', labelizeDirective);

    // The app's translation keys use Laravel-style ":name" placeholders (e.g. 'Approve :ref?'), but vue-i18n only fills
    // "{name}". Fill ":name" too when named params are passed, so those messages never show a literal ":ref".
    const baseT = app.config.globalProperties.$t;
    const wrappedT = function (key, ...rest) {
      let out = baseT.apply(this, [key, ...rest]);
      const params = rest.find(a => a && typeof a === 'object' && !Array.isArray(a));
      if (params && typeof out === 'string' && out.indexOf(':') !== -1) {
        out = out.replace(/:([A-Za-z_]\w*)/g, (m, k) => (Object.prototype.hasOwnProperty.call(params, k) ? String(params[k]) : m));
      }
      return out;
    };
    try {
      Object.defineProperty(app.config.globalProperties, '$t', { value: wrappedT, configurable: true, writable: true });
    } catch (e) {
      app.config.globalProperties.$t = wrappedT;
    }

    app.config.globalProperties.$route = route;
    app.config.globalProperties.$theme = getStoredTheme();
    app.config.globalProperties.$setTheme = theme => {
      setTheme(theme);
      app.config.globalProperties.$theme = theme;
    };
    // Drop-in async replacements for window.confirm/alert/prompt — same
    // call-site shape (await the result), rendered as a real WIMS-styled
    // dialog via GlobalDialog.vue instead of the browser's own popup.
    app.config.globalProperties.$confirm = confirmDialog;
    app.config.globalProperties.$alert = alertDialog;
    app.config.globalProperties.$prompt = promptDialog;

    applyTheme(props.initialPage);
    mountIdleLogoutModal();
    mountGlobalDialog();
    mountSaveProgress();
    initSaveProgress(router);

    // Global safety net for a failed/non-Inertia navigation — until now,
    // Inertia's own default behavior for both of these was a full,
    // completely silent browser reload (there's no other way to recover
    // from a non-Inertia response or a page chunk that failed to fetch),
    // which just looked like the click did nothing for a moment. This gives
    // the user a clear, WIMS-styled heads-up first, then reloads.
    // A rebuild replaces the hashed chunk files; a tab opened before it then 404s on the next lazy page chunk and
    // looks "empty". Reload once (not in a loop) to pick up the new build.
    window.addEventListener('vite:preloadError', event => {
      event.preventDefault();
      try {
        if (sessionStorage.getItem('wims_chunk_reload') === String(Math.floor(Date.now() / 60000))) return;
        sessionStorage.setItem('wims_chunk_reload', String(Math.floor(Date.now() / 60000)));
      } catch (e) {
        /* storage blocked: reload anyway */
      }
      window.location.reload();
    });
    initFieldErrors(router);
    initRequiredFields(router);
    // every data table gets a No column; the numbers of a paginated list continue from page to page (props of each visit tell where a page starts)
    initAutoNumber();
    setPagination(props && props.initialPage ? props.initialPage.props : null);
    router.on('navigate', event => setPagination(event.detail.page.props));
    router.on('invalid', event => {
      event.preventDefault();
      alertDialog(i18n.global.t('Your session or connection needs a refresh, reloading…')).then(() => {
        window.location.reload();
      });
    });
    router.on('exception', event => {
      event.preventDefault();
      alertDialog(i18n.global.t('Could not load that page, reloading…')).then(() => {
        window.location.reload();
      });
    });

    initIdleLogout(props.initialPage);
    if (props.initialPage?.props?.auth?.user) {
      // Connection status itself always runs (harmless, and other UI depends
      // on it) — only the actual offline data cache is gated by the feature
      // flag, so a Super Admin turning Read Mode off means nothing gets
      // synced for offline browsing in the first place.
      initOfflineStatus();
      if (props.initialPage.props.enabledFeatures?.read_mode !== false) {
        initOfflineSync(props.initialPage.props.auth.user.id);
      }
    }

    return app.mount(el);
  },
  progress: { color: '#2563EB', showSpinner: true },
});

// InertiaProgress.init({ color: '#2563EB', showSpinner: true });

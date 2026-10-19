<template>
  <div class="mt-6 mb-2 px-2 text-center text-[11px] sm:text-xs text-gray-400 print:hidden">
    <p class="hidden sm:block">{{ appName }} <span class="app-footer-licence" :class="'is-' + licenceState" :title="licenceTitle">({{ licenceLabel }})</span>, ({{ versionLabel }}). <span class="app-footer-credit">{{ footerCredit }}</span></p>
    <p class="sm:hidden">RANS WIMS <span class="app-footer-licence" :class="'is-' + licenceState">({{ licenceLabel }})</span> ({{ versionLabel }}). <span class="app-footer-credit">{{ footerCredit }}</span></p>
    <Link :href="route('terms.show')" class="af-terms-link af-tooltip" data-tooltip="WIMS - Terms and Conditions" tabindex="0">
      <icons name="lock-closed" class="w-3.5 h-3.5 flex-none" />
      <span class="hidden sm:inline">{{ $t('Terms and Conditions') }}</span>
    </Link>
  </div>
</template>

<script>
import { Link } from '@inertiajs/vue3';

export default {
  components: { Link },
  computed: {
    versionLabel() {
      return this.$page.props.settings.version_label || 'v1.6';
    },
    appName() {
      return this.$page.props.settings.app_name || 'RANS Workflow Integrated Management System';
    },
    footerCredit() {
      return this.$page.props.settings.footer_credit || 'Developed by Extrion';
    },
    licenceState() {
      return this.$page.props.licenceStatus?.state || 'active';
    },
    licenceLabel() {
      return this.$page.props.licenceStatus?.label || 'Licence Active';
    },
    licenceTitle() {
      const l = this.$page.props.licenceStatus;
      return l?.expires_at ? `WIMS Licence valid until ${l.expires_at}` : 'WIMS Licence';
    },
  },
};
</script>

<style scoped>
.app-footer-credit {
  font-weight: 600;
  background: linear-gradient(90deg, #9ca3af, #38bdf8, #9ca3af);
  background-size: 250% 100%;
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
  animation: app-footer-credit-shimmer 8s linear infinite;
}

/* WIMS Licence status: same animated gradient as the credit, green to orange while the licence is good. */
.app-footer-licence {
  font-weight: 700;
  background: linear-gradient(90deg, #16a34a, #f59e0b, #16a34a);
  background-size: 250% 100%;
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
  animation: app-footer-credit-shimmer 6s linear infinite;
}
.app-footer-licence.is-expiring { background-image: linear-gradient(90deg, #f59e0b, #ea580c, #f59e0b); background-size: 250% 100%; }
.app-footer-licence:not(.is-active):not(.is-expiring) { background-image: linear-gradient(90deg, #dc2626, #fb7185, #9f1239, #dc2626); background-size: 250% 100%; }

@keyframes app-footer-credit-shimmer {
  0% { background-position: 0% 0; }
  100% { background-position: 250% 0; }
}

@media (prefers-reduced-motion: reduce) {
  .app-footer-credit, .app-footer-licence {
    animation: none;
  }
}

.af-terms-link {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  width: 1.75rem; height: 1.75rem; margin: 0.5rem auto 0; border-radius: 9999px;
  background: #f1f5f9; color: #94a3b8; transition: background 0.15s ease, color 0.15s ease, transform 0.15s ease;
}
@media (min-width: 640px) {
  .af-terms-link { width: auto; height: auto; padding: 0.4rem 0.9rem; font-size: 0.72rem; font-weight: 600; }
}
.af-terms-link:hover, .af-terms-link:focus { background: #e0e7ff; color: #4338ca; transform: translateY(-1px); outline: none; }

.af-tooltip { position: relative; }
.af-tooltip::after {
  content: attr(data-tooltip);
  position: absolute;
  bottom: calc(100% + 0.5rem);
  left: 50%;
  transform: translateX(-50%) translateY(4px);
  background: #0f172a;
  color: #f1f5f9;
  font-size: 0.68rem;
  font-weight: 600;
  white-space: nowrap;
  padding: 0.4rem 0.65rem;
  border-radius: 0.5rem;
  box-shadow: 0 6px 16px -4px rgba(15, 23, 42, 0.35);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.15s ease, transform 0.15s ease;
  z-index: 60;
}
.af-tooltip:hover::after, .af-tooltip:focus::after, .af-tooltip:focus-visible::after { opacity: 1; transform: translateX(-50%) translateY(0); }

html.dark .af-terms-link { background: rgba(255,255,255,0.08); color: #94a3b8; }
html.dark .af-terms-link:hover, html.dark .af-terms-link:focus { background: rgba(129,140,248,0.2); color: #c7d2fe; }
</style>

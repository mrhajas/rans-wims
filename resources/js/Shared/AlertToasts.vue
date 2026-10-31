<template>
  <div class="at-host" :style="{ top: top + 'px' }" aria-live="polite">
    <transition-group name="at" tag="div" class="at-stack">
      <div v-for="t in state.list" :key="t.key" class="at-card" :style="{ '--accent': t.module_accent || '#4f46e5', '--ms': t.ms + 'ms' }" role="status"
        @mouseenter="hold(t.key)" @mouseleave="release(t.key)" @click="open(t)">
        <span class="at-badge" aria-hidden="true"><icons :name="iconFor(t.module)" class-name="at-ic" /><i class="at-shine"></i></span>
        <div class="at-body">
          <span class="at-mod">{{ t.module_label }}</span>
          <strong class="at-title">{{ t.title }}</strong>
          <span v-if="t.message" class="at-msg">{{ t.message }}</span>
        </div>
        <button type="button" class="at-x" :aria-label="$t('Close')" @click.stop="dismissAlertToast(t.key)">
          <svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" fill="none" /></svg>
        </button>
      </div>
    </transition-group>
  </div>
</template>

<script>
import { alertToasts, dismissAlertToast, dismissAllAlertToasts } from '@/Core/alertToasts';
import Icons from '@/Shared/Icons.vue';

// a flat glyph (white, one colour) for each module; anything not listed gets the bell
const MODULE_ICONS = {
  stock_alerts: 'archive-box', payment_vouchers: 'doc-text', archive: 'folder', wimschat: 'chat', signatories: 'edit', responsive_audit: 'mobile', smart_brief: 'sparkles',
  weather_risk: 'cloud', site_diary: 'camera', site_meetings: 'meeting-calendar', transport: 'truck', checkins: 'in', checkouts: 'out', transfers: 'refresh',
  external_stock: 'archive-box', travels: 'plane', visitor_logs: 'identification', mr_reminders: 'clock', id_passes: 'identification', holiday_greetings: 'gift',
  administration: 'briefcase', subcontracts: 'building', multi_transfers: 'forward', material_requests: 'clip', material_reallocations: 'duplicate', site_reports: 'doc-text',
  executive_reports: 'chart-bar', task_reminders: 'clock', compliance_records: 'shield-check', assets: 'wrench', security_reminders: 'lock-closed', labour: 'users',
  case_tracking: 'pin', task_manager: 'tick', work_package_issues: 'exclamation', digital_attendance: 'clock', notice_board: 'speakerphone', accounts_tracking: 'wallet',
  fund_allocations: 'calculator', salary: 'cash', users: 'user', contacts: 'user-circle', categories: 'collection', units: 'scale', supplier_records: 'bag',
  issue_returns: 'reply', adjustments: 'adjustments', items: 'collection', projects: 'building', staff: 'user', project_certificates: 'doc',
};

export default {
  components: { Icons },
  data() { return { state: alertToasts, top: 76, held: {}, timers: {} }; },
  watch: {
    // every toast gets its own 3 second timer (set when it appears)
    'state.list': {
      deep: true,
      handler(list) {
        this.place();
        list.forEach(t => { if (!this.timers[t.key]) this.start(t.key, t.ms); });
        Object.keys(this.timers).forEach(k => { if (!list.some(t => t.key === k)) { clearTimeout(this.timers[k].id); delete this.timers[k]; delete this.held[k]; } });
      },
    },
  },
  mounted() {
    window.addEventListener('keydown', this.onKey, true);
    document.addEventListener('pointerdown', this.onOutside, true);
  },
  beforeUnmount() {
    window.removeEventListener('keydown', this.onKey, true);
    document.removeEventListener('pointerdown', this.onOutside, true);
    Object.values(this.timers).forEach(t => clearTimeout(t.id));
  },
  methods: {
    dismissAlertToast,
    iconFor(module) { return MODULE_ICONS[module] || 'bell'; },
    // just under the top bar, whatever its height on this screen
    place() {
      const bar = document.querySelector('.nb-root');
      const b = bar ? bar.getBoundingClientRect().bottom : 0;
      this.top = Math.max(64, Math.round(b + 8));
    },
    start(key, ms) { this.timers[key] = { id: setTimeout(() => dismissAlertToast(key), ms), left: ms, at: Date.now() }; },
    // while the pointer is on a toast its time stands still, so it can be clicked
    hold(key) { const t = this.timers[key]; if (!t) return; clearTimeout(t.id); t.left = Math.max(400, t.left - (Date.now() - t.at)); this.held[key] = true; },
    release(key) { const t = this.timers[key]; if (!t) return; this.held[key] = false; t.at = Date.now(); t.id = setTimeout(() => dismissAlertToast(key), t.left); },
    onKey(e) { if (e.key === 'Escape' && this.state.list.length) dismissAllAlertToasts(); },
    onOutside(e) { if (this.state.list.length && !(e.target && e.target.closest && e.target.closest('.at-card'))) dismissAllAlertToasts(); },
    // the same as opening the alert from the bell: it is marked as read, then its record opens
    open(t) {
      dismissAlertToast(t.key);
      const go = () => { if (t.url) window.location.assign(t.url); };
      axios.post(route('notifications.alerts.read', t.id)).then(go, go);
    },
  },
};
</script>

<style>
/* about 30% smaller than the first version */
.at-host { position: fixed; left: 50%; transform: translateX(-50%); z-index: 999995; width: min(392px, calc(100vw - 48px)); pointer-events: none; }
.at-stack { display: grid; gap: 9px; }
@media (max-width: 767.98px) { .at-host { display: none; } }
.at-card { --r: 15px; position: relative; display: flex; align-items: center; gap: 10px; min-height: 52px; margin-left: 26px; padding: 8px 12px 8px 40px; border-radius: 26px var(--r) var(--r) 26px; background: #fff; color: #0f172a; cursor: pointer; pointer-events: auto; overflow: visible;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06), 0 12px 24px -9px color-mix(in srgb, var(--accent) 38%, rgba(15, 23, 42, 0.5)), 0 0 0 1px rgba(15, 23, 42, 0.05); transition: transform 0.2s ease, box-shadow 0.2s ease; }
.at-card:hover { transform: translateY(-2px); box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06), 0 16px 28px -9px color-mix(in srgb, var(--accent) 50%, rgba(15, 23, 42, 0.5)), 0 0 0 1px rgba(15, 23, 42, 0.05); }
/* the badge: a flat colour, the glyph exactly in the middle of it */
.at-badge { position: absolute; left: -26px; top: 50%; width: 52px; height: 52px; margin-top: -26px; display: flex; align-items: center; justify-content: center; border-radius: 50%; overflow: hidden; background: var(--accent); color: #fff; box-shadow: 0 7px 13px -6px color-mix(in srgb, var(--accent) 80%, transparent); }
.at-badge .at-ic, .at-badge svg { display: block; width: 20px; height: 20px; flex: none; animation: at-pop 0.7s 0.15s cubic-bezier(0.2, 0.9, 0.3, 1.5) both; }
.at-shine { position: absolute; top: -20%; bottom: -20%; left: 0; width: 45%; background: linear-gradient(105deg, transparent, rgba(255, 255, 255, 0.5), transparent); transform: translateX(-160%) skewX(-12deg); animation: at-shine 1.5s 0.5s ease-in-out both; }
.at-body { display: grid; gap: 0; min-width: 0; flex: 1 1 auto; }
.at-mod { font-size: 0.52rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--accent); }
.at-title { font-size: 0.72rem; font-weight: 800; line-height: 1.25; color: #0f172a; overflow-wrap: anywhere; }
.at-msg { font-size: 0.64rem; line-height: 1.3; color: #475569; display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; }
.at-x { flex: none; display: grid; place-items: center; width: 20px; height: 20px; border: 0; border-radius: 9999px; background: transparent; color: #94a3b8; cursor: pointer; transition: background 0.2s, color 0.2s; }
.at-x svg { width: 11px; height: 11px; }
.at-x:hover { background: rgba(15, 23, 42, 0.07); color: #334155; }
html.dark .at-card { background: #172036; color: #e5ecf8; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.4), 0 12px 24px -9px color-mix(in srgb, var(--accent) 45%, rgba(0, 0, 0, 0.7)), 0 0 0 1px rgba(255, 255, 255, 0.07); }
html.dark .at-title { color: #f1f5ff; }
html.dark .at-msg { color: #a9b8d2; }
html.dark .at-x:hover { background: rgba(255, 255, 255, 0.1); color: #fff; }
.at-enter-active { transition: transform 0.55s cubic-bezier(0.2, 0.9, 0.3, 1.25), opacity 0.35s ease; }
.at-leave-active { transition: transform 0.32s cubic-bezier(0.5, 0, 0.8, 0.4), opacity 0.28s ease; position: absolute; left: 0; right: 0; }
.at-enter-from { opacity: 0; transform: translateY(-20px) scale(0.9); }
.at-leave-to { opacity: 0; transform: translateY(-10px) scale(0.94); }
.at-move { transition: transform 0.35s cubic-bezier(0.2, 0.8, 0.2, 1); }
@keyframes at-pop { from { transform: scale(0.2) rotate(-24deg); opacity: 0; } to { transform: none; opacity: 1; } }
@keyframes at-shine { to { transform: translateX(380%) skewX(-12deg); } }
@media (prefers-reduced-motion: reduce) { .at-enter-active, .at-leave-active, .at-move { transition: opacity 0.2s; } .at-enter-from, .at-leave-to { transform: none; } .at-badge svg, .at-shine { animation: none; } .at-shine { display: none; } }
</style>

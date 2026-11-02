<template>
  <tec-dropdown align="right" width="80" :content-classes="['py-0', 'bg-white', 'wo-shell']" center-on-mobile>
    <template #trigger>
      <button
        type="button"
        class="status-pill lg-tip"
        :class="`status-pill--${$connection.status}`"
        :data-tip="statusLabel"
       :aria-label="statusLabel">
        <span class="status-dot-wrap">
          <network-icon :percent="net.percent" :level="netLevel" :type="net.type" class="status-icon h-3.5 w-3.5" />
        </span>
        <span class="hidden sm:inline nb-c-hide">{{ statusLabel }}</span>
      </button>
    </template>

    <template #content>
      <div class="wo" :class="['st-' + $connection.status, 'lv-' + netLevel]" :style="{ '--sig': sigColor }">
        <!-- the top: what WIMS is doing, in the colour of the signal -->
        <header class="wo-hero" style="--k: 0">
          <span class="wo-orb"><i class="o1"></i><i class="o2"></i><network-icon :percent="net.percent" :level="netLevel" :type="net.type" class="wo-nic" /></span>
          <div class="wo-ttl"><b>{{ statusHeading }}</b><small>{{ heroSub }}</small></div>
          <span class="wo-live"><i></i>{{ $t('Live') }}</span>
        </header>

        <network-panel />

        <!-- what the server and the session are doing -->
        <section class="wo-cards">
          <div class="wo-card" :class="'c-' + serverCard.tone" style="--k: 1"><span class="ci"><icons :name="serverCard.icon" class="w-4 h-4" /></span><span class="ct"><small>{{ $t('Server') }}</small><b>{{ serverCard.text }}</b></span></div>
          <div class="wo-card" :class="'c-' + sessionCard.tone" style="--k: 2"><span class="ci"><icons :name="sessionCard.icon" class="w-4 h-4" /></span><span class="ct"><small>{{ $t('Your session') }}</small><b>{{ sessionCard.text }}</b></span></div>
          <div class="wo-card c-violet" style="--k: 3"><span class="ci"><icons name="database" class="w-4 h-4" /></span><span class="ct"><small>{{ $t('Data on this device') }}</small><b>{{ syncText }}</b></span></div>
          <div v-if="$page.props.clientInfo" class="wo-card c-sky" style="--k: 4"><span class="ci"><icons name="globe" class="w-4 h-4" /></span><span class="ct"><small>{{ $t('Your public IP') }}</small><b>{{ $page.props.clientInfo.ip || '-' }}</b></span></div>
          <div v-if="$page.props.clientInfo" class="wo-card c-indigo wo-wide" style="--k: 5"><span class="ci"><icons :name="deviceIcon" class="w-4 h-4" /></span><span class="ct"><small>{{ $t('Device used') }}</small><b>{{ deviceText }}</b></span></div>
        </section>

        <!-- offline: what still works -->
        <section v-if="$connection.status === 'offline'" class="wo-off" style="--k: 6">
          <p class="t"><icons name="exclamation" class="w-4 h-4" />{{ $t('WIMS is currently offline.') }}</p>
          <ul class="offline-capability-list">
            <li><icons name="tick" class="h-3 w-3 text-emerald-600" />{{ $t('Search items') }}</li>
            <li><icons name="tick" class="h-3 w-3 text-emerald-600" />{{ $t('View stock, warehouses & projects') }}</li>
            <li><icons name="tick" class="h-3 w-3 text-emerald-600" />{{ $t('View cached transactions') }}</li>
          </ul>
          <p class="n">{{ $t('Data changes are disabled while offline.') }}</p>
        </section>
        <p v-else-if="$connection.slow" class="wo-slow" style="--k: 6"><icons name="clock" class="w-3.5 h-3.5" />{{ $t('Your connection is slow right now. WIMS stays in normal mode and keeps trying.') }}</p>

        <footer class="wo-foot" style="--k: 7">
          <span class="wo-last">
            <template v-if="$connection.lastSyncAt"><icons name="refresh" class="w-3 h-3" />{{ $t('Last synced') }}: {{ $date($connection.lastSyncAt, null, 'short') }} {{ $time($connection.lastSyncAt) }}</template>
            <template v-else-if="$connection.lastCheckedAt"><icons name="clock" class="w-3 h-3" />{{ $t('Last checked') }}: {{ $date($connection.lastCheckedAt, null, 'short') }} {{ $time($connection.lastCheckedAt) }}</template>
          </span>
          <button type="button" class="wo-btn" @click="onRefresh" :disabled="$connection.status === 'checking' || $connection.checking">
            <icons name="refresh" class="h-3.5 w-3.5" :class="{ 'animate-spin': $connection.status === 'checking' || $connection.checking }" />{{ $t('Check again') }}
          </button>
          <button v-if="$connection.status === 'online'" type="button" class="wo-btn is-main" @click="onSyncNow"><icons name="cloud" class="h-3.5 w-3.5" />{{ $t('Sync Now') }}</button>
        </footer>
      </div>
    </template>
  </tec-dropdown>
</template>

<script>
import TecDropdown from '@/Jetstream/Dropdown.vue';
import { refreshConnectionStatus } from '@/Core/offlineStatus';
import { runSync } from '@/Core/offlineSync';
import NetworkIcon from '@/Shared/Top/NetworkIcon.vue';
import NetworkPanel from '@/Shared/Top/NetworkPanel.vue';
import { net, LEVELS, startNetworkSignal } from '@/Core/networkSignal';

export default {
  components: { TecDropdown, NetworkIcon, NetworkPanel },
  data() { return { net }; },
  mounted() { startNetworkSignal(); },
  computed: {
    // when WIMS itself says it is offline the icon says so too
    netLevel() { return this.$connection.status === 'offline' ? 'offline' : this.net.level; },
    statusLabel() {
      return {
        online: this.$t('Online'),
        offline: this.$t('Offline'),
        checking: this.$t('Checking…'),
        syncing: this.$t('Syncing…'),
        degraded: this.$t('Degraded'),
      }[this.$connection.status];
    },
    sigColor() { return (LEVELS[this.netLevel] || LEVELS.unknown).color; },
    heroSub() {
      return {
        online: this.$t('Connected to the server'),
        offline: this.$t('No connection: you can still read what is saved on this device'),
        checking: this.$t('Looking for the server...'),
        syncing: this.$t('Bringing this device up to date'),
        degraded: this.$t('Connected, but please sign in again'),
      }[this.$connection.status];
    },
    serverCard() {
      return {
        online: { icon: 'server', tone: 'green', text: this.$t('Healthy') },
        offline: { icon: 'exclamation', tone: 'orange', text: this.$t('Not reachable') },
        checking: { icon: 'refresh', tone: 'amber', text: this.$t('Checking...') },
        syncing: { icon: 'server', tone: 'blue', text: this.$t('Reachable') },
        degraded: { icon: 'server', tone: 'amber', text: this.$t('Reachable') },
      }[this.$connection.status];
    },
    sessionCard() {
      return {
        online: { icon: 'shield-check', tone: 'green', text: this.$t('Valid') },
        offline: { icon: 'lock-closed', tone: 'slate', text: this.$t('Read only') },
        checking: { icon: 'shield-check', tone: 'amber', text: this.$t('Checking...') },
        syncing: { icon: 'shield-check', tone: 'blue', text: this.$t('Valid') },
        degraded: { icon: 'lock-closed', tone: 'amber', text: this.$t('Needs a refresh') },
      }[this.$connection.status];
    },
    syncText() {
      const c = this.$connection;
      if (c.status === 'syncing') return this.$t('Updating...');
      return c.lastSyncAt ? this.$t('Up to date') : this.$t('Not saved yet');
    },
    deviceIcon() {
      const d = String((this.$page.props.clientInfo || {}).device || '').toLowerCase();
      return d.includes('mobile') || d.includes('phone') || d.includes('tablet') ? 'mobile' : 'desktop-computer';
    },
    deviceText() {
      const c = this.$page.props.clientInfo || {};
      return [c.device, c.os, c.browser].filter(x => x && x !== 'Unknown').join(' · ') || '-';
    },
    statusHeading() {
      return {
        online: this.$t('WIMS Online'),
        offline: this.$t('Offline Read Mode'),
        checking: this.$t('Checking connection…'),
        syncing: this.$t('Syncing…'),
        degraded: this.$t('Session needs attention'),
      }[this.$connection.status];
    },
  },
  methods: {
    onRefresh() {
      refreshConnectionStatus();
    },
    onSyncNow() {
      runSync();
    },
  },
};
</script>

<style scoped>
/* ---- the WIMS Online window ---- */
:global(.wo-shell) { overflow-y: auto; overscroll-behavior: contain; scrollbar-width: thin; }
.wo { --sig: #94a3b8; position: relative; min-width: 0; }
.wo > * { animation: wo-in 0.55s cubic-bezier(0.2, 0.9, 0.3, 1.1) backwards; animation-delay: calc(var(--k, 0) * 70ms); }
.wo > .np { --k: 0; }
@keyframes wo-in { from { opacity: 0; transform: translateY(12px); } }
.wo-hero { position: relative; display: flex; align-items: center; gap: 12px; padding: 14px 16px; overflow: hidden; background: linear-gradient(120deg, color-mix(in srgb, var(--sig) 20%, #fff) 0%, color-mix(in srgb, var(--sig) 6%, #fff) 100%); border-bottom: 1px solid color-mix(in srgb, var(--sig) 20%, transparent); transition: background 0.6s ease; }
html.dark .wo-hero { background: linear-gradient(120deg, color-mix(in srgb, var(--sig) 28%, #0f172a), #0f172a); }
.wo-hero::after { content: ''; position: absolute; inset: 0; background: linear-gradient(110deg, transparent 35%, rgba(255, 255, 255, 0.55) 50%, transparent 65%); transform: translateX(-120%); animation: wo-sheen 5s ease-in-out 0.6s infinite; pointer-events: none; }
@keyframes wo-sheen { 0%, 55% { transform: translateX(-120%); } 85%, 100% { transform: translateX(120%); } }
.wo-orb { position: relative; display: grid; place-items: center; flex: none; width: 40px; height: 40px; border-radius: 14px; background: #fff; box-shadow: 0 6px 14px -6px color-mix(in srgb, var(--sig) 70%, transparent); }
html.dark .wo-orb { background: #1e293b; }
.wo-orb i { position: absolute; inset: 0; border-radius: 14px; border: 2px solid var(--sig); opacity: 0; animation: wo-ring 2.6s ease-out infinite; } .wo-orb .o2 { animation-delay: 1.3s; }
@keyframes wo-ring { 0% { transform: scale(0.9); opacity: 0.6; } 100% { transform: scale(1.8); opacity: 0; } }
.wo-nic { width: 22px; height: 22px; position: relative; z-index: 1; }
.wo-ttl { display: grid; min-width: 0; line-height: 1.2; flex: 1; } .wo-ttl b { font-size: 0.95rem; font-weight: 800; color: #0f172a; } html.dark .wo-ttl b { color: #f1f5f9; } .wo-ttl small { font-size: 0.72rem; color: #475569; } html.dark .wo-ttl small { color: #94a3b8; }
.wo-live { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 9999px; font-size: 0.62rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--sig); background: color-mix(in srgb, var(--sig) 14%, transparent); }
.wo-live i { width: 7px; height: 7px; border-radius: 50%; background: var(--sig); animation: wo-blink 1.4s ease-in-out infinite; } @keyframes wo-blink { 50% { transform: scale(0.55); opacity: 0.45; } }
.st-offline .wo-live i, .st-checking .wo-live i { animation-duration: 0.8s; }

.wo-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; padding: 0 16px 4px; }
.wo-card { display: flex; align-items: center; gap: 10px; min-width: 0; padding: 8px 10px; border-radius: 14px; --cc: #64748b; background: color-mix(in srgb, var(--cc) 8%, transparent); box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--cc) 20%, transparent); transition: transform 0.2s ease, box-shadow 0.2s ease; animation: wo-in 0.5s cubic-bezier(0.2, 0.9, 0.3, 1.1) backwards; animation-delay: calc(0.35s + var(--k) * 60ms); }
.wo-card:hover { transform: translateY(-2px); box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--cc) 45%, transparent), 0 8px 16px -10px var(--cc); }
.wo-card .ci { display: grid; place-items: center; flex: none; width: 30px; height: 30px; border-radius: 10px; color: #fff; background: var(--cc); }
.wo-card .ct { display: grid; min-width: 0; line-height: 1.15; } .wo-card small { font-size: 0.58rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #94a3b8; } .wo-card b { font-size: 0.76rem; font-weight: 700; color: #334155; overflow-wrap: anywhere; } html.dark .wo-card b { color: #e2e8f0; }
.wo-wide { grid-column: 1 / -1; }
.c-green { --cc: #16a34a; } .c-amber { --cc: #d97706; } .c-orange { --cc: #ea580c; } .c-blue { --cc: #2563eb; } .c-violet { --cc: #7c3aed; } .c-sky { --cc: #0284c7; } .c-indigo { --cc: #4f46e5; } .c-slate { --cc: #64748b; }
.st-syncing .c-blue .ci svg, .st-checking .c-amber .ci svg { animation: status-spin 1.1s linear infinite; }

.wo-off { margin: 8px 16px 0; padding: 10px 12px; border-radius: 14px; background: rgba(234, 88, 12, 0.08); box-shadow: inset 0 0 0 1px rgba(234, 88, 12, 0.25); }
.wo-off .t { display: flex; align-items: center; gap: 6px; margin: 0 0 6px; font-size: 0.78rem; font-weight: 800; color: #c2410c; } .wo-off .n { margin: 6px 0 0; font-size: 0.7rem; color: #94a3b8; }
.wo-slow { display: flex; align-items: center; gap: 6px; margin: 8px 16px 0; padding: 7px 10px; border-radius: 12px; font-size: 0.72rem; font-weight: 600; color: #92400e; background: rgba(245, 158, 11, 0.12); }
.wo-foot { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 12px 16px 14px; }
.wo-last { display: inline-flex; align-items: center; gap: 5px; flex: 1 1 100%; font-size: 0.68rem; color: #94a3b8; }
.wo-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 13px; border-radius: 9999px; font-size: 0.74rem; font-weight: 700; color: #334155; background: rgba(100, 116, 139, 0.12); transition: transform 0.15s ease, background 0.2s ease, box-shadow 0.2s ease; }
.wo-btn:hover:not(:disabled) { transform: translateY(-1px); background: rgba(100, 116, 139, 0.2); } .wo-btn:disabled { opacity: 0.6; }
.wo-btn.is-main { color: #fff; background: linear-gradient(135deg, var(--sig), color-mix(in srgb, var(--sig) 60%, #1e3a8a)); box-shadow: 0 8px 16px -8px var(--sig); }
.wo-btn.is-main:hover { filter: brightness(1.06); }
@media (prefers-reduced-motion: reduce) { .wo *, .wo { animation: none !important; } }

.sd-me { display: grid; gap: 8px; margin-top: 12px; padding-top: 10px; border-top: 1px solid #f1f5f9; }
.sd-row { display: flex; align-items: center; gap: 10px; min-width: 0; }
.sd-ico { flex: none; width: 28px; height: 28px; border-radius: 9px; display: grid; place-items: center; background: rgba(2, 132, 199, 0.12); color: #0284c7; }
.sd-ico.is-violet { background: rgba(124, 58, 237, 0.12); color: #7c3aed; }
.sd-t { display: grid; min-width: 0; line-height: 1.2; }
.sd-t small { font-size: 0.62rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: #94a3b8; }
.sd-t b { font-size: 0.78rem; font-weight: 700; color: #334155; overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }

.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  border-radius: 9999px;
  font-size: 0.72rem;
  font-weight: 600;
  letter-spacing: 0.02em;
  background: rgba(241, 245, 249, 0.9);
  color: #475569;
  transition: background 0.15s ease, color 0.15s ease;
}
.status-pill--online {
  background: rgba(220, 252, 231, 0.9);
  color: #15803d;
}
.status-pill--offline {
  background: rgba(255, 237, 213, 0.9);
  color: #c2410c;
}
.status-pill--degraded {
  background: rgba(254, 249, 195, 0.9);
  color: #a16207;
}

.status-dot-wrap {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 16px;
  width: 16px;
  flex: none;
}
.status-dot-wrap--static {
  height: 22px;
  width: 22px;
  border-radius: 9999px;
}
.status-dot-wrap--static.status-dot-wrap--online {
  background: #dcfce7;
  color: #16a34a;
}
.status-dot-wrap--static.status-dot-wrap--offline {
  background: #ffedd5;
  color: #ea580c;
}
.status-dot-wrap--static.status-dot-wrap--degraded,
.status-dot-wrap--static.status-dot-wrap--checking {
  background: #fef9c3;
  color: #ca8a04;
}
.status-dot-wrap--static.status-dot-wrap--syncing {
  background: #dbeafe;
  color: #2563eb;
}

.status-icon {
  color: inherit;
}

/* Subtle, professional pulse — never a hard blink. Respects reduced motion. */
@media (prefers-reduced-motion: no-preference) {
  .status-pill--online .status-icon {
    animation: status-pulse-soft 2.6s ease-in-out infinite;
  }
  .status-pill--offline .status-icon {
    animation: status-pulse-warn 1.8s ease-in-out infinite;
  }
  .status-pill--checking .status-icon,
  .status-pill--syncing .status-icon {
    animation: status-spin 1.1s linear infinite;
  }
}

@keyframes status-pulse-soft {
  0%, 100% {
    opacity: 1;
  }
  50% {
    opacity: 0.55;
  }
}
@keyframes status-pulse-warn {
  0%, 100% {
    opacity: 1;
    transform: scale(1);
  }
  50% {
    opacity: 0.7;
    transform: scale(0.92);
  }
}
@keyframes status-spin {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}

.offline-capability-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.offline-capability-list li {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.72rem;
  color: #475569;
}

.status-refresh-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 0.72rem;
  font-weight: 600;
  color: #0369a1;
}
.status-refresh-btn:hover {
  color: #075985;
}
.status-refresh-btn:disabled {
  opacity: 0.6;
}

html.dark .status-pill {
  background: rgba(255, 255, 255, 0.06);
  color: #cbd5e1;
}
html.dark .status-pill--online {
  background: rgba(16, 185, 129, 0.15);
  color: #6ee7b7;
}
html.dark .status-pill--offline {
  background: rgba(234, 88, 12, 0.15);
  color: #fdba74;
}
html.dark .status-pill--degraded {
  background: rgba(202, 138, 4, 0.15);
  color: #fde68a;
}
</style>

<template>
  <tec-dropdown align="right" width="72" :content-classes="['py-0', 'bg-white']" center-on-mobile>
    <template #trigger>
      <button
        type="button"
        class="status-pill lg-tip"
        :class="`status-pill--${$connection.status}`"
        :data-tip="statusLabel"
       :aria-label="statusLabel">
        <span class="status-dot-wrap">
          <icons name="wifi" class="status-icon h-3.5 w-3.5" />
        </span>
        <span class="hidden sm:inline nb-c-hide">{{ statusLabel }}</span>
      </button>
    </template>

    <template #content>
      <div class="px-4 pt-3.5 pb-3 border-b border-slate-100">
        <div class="flex items-center gap-2">
          <span class="status-dot-wrap status-dot-wrap--static" :class="`status-dot-wrap--${$connection.status}`">
            <icons name="wifi" class="h-3.5 w-3.5" />
          </span>
          <span class="text-sm font-semibold text-slate-800">{{ statusHeading }}</span>
        </div>
      </div>

      <div class="px-4 py-3">
        <template v-if="$connection.status === 'online'">
          <p class="text-xs text-slate-500">{{ $t('Server connection healthy. Normal WIMS operation.') }}</p>
          <p v-if="$connection.slow" class="text-xs text-amber-700 mt-1">{{ $t('Your connection is slow right now. WIMS stays in normal mode and keeps trying.') }}</p>
        </template>

        <template v-else-if="$connection.status === 'degraded'">
          <p class="text-xs text-slate-500">{{ $t('WIMS is reachable, but your session needs to be refreshed.') }}</p>
        </template>

        <template v-else-if="$connection.status === 'syncing'">
          <p class="text-xs text-slate-500">{{ $t('Refreshing your local WIMS data…') }}</p>
        </template>

        <template v-else-if="$connection.status === 'checking'">
          <p class="text-xs text-slate-500">{{ $t('Verifying the connection to WIMS…') }}</p>
        </template>

        <template v-else-if="$connection.status === 'offline'">
          <p class="text-xs font-medium text-amber-700 mb-2">{{ $t('WIMS is currently offline.') }}</p>
          <p class="text-xs text-slate-500 mb-2">{{ $t('You can still:') }}</p>
          <ul class="offline-capability-list mb-2">
            <li><icons name="tick" class="h-3 w-3 text-emerald-600" />{{ $t('Search items') }}</li>
            <li><icons name="tick" class="h-3 w-3 text-emerald-600" />{{ $t('View stock, warehouses & projects') }}</li>
            <li><icons name="tick" class="h-3 w-3 text-emerald-600" />{{ $t('View cached transactions') }}</li>
          </ul>
          <p class="text-xs text-slate-400">{{ $t('Data changes are disabled while offline.') }}</p>
        </template>

        <div v-if="$page.props.clientInfo" class="sd-me">
          <div class="sd-row"><span class="sd-ico is-sky"><icons name="globe" class="h-3.5 w-3.5" /></span><div class="sd-t"><small>{{ $t('Your public IP') }}</small><b>{{ $page.props.clientInfo.ip || '-' }}</b></div></div>
          <div class="sd-row"><span class="sd-ico is-violet"><icons :name="deviceIcon" class="h-3.5 w-3.5" /></span><div class="sd-t"><small>{{ $t('Device used') }}</small><b>{{ deviceText }}</b></div></div>
        </div>

        <div class="text-[11px] text-slate-400 mt-3 pt-2.5 border-t border-slate-100">
          <template v-if="$connection.lastSyncAt">
            {{ $t('Last synced') }}: {{ $date($connection.lastSyncAt, null, 'short') }} {{ $time($connection.lastSyncAt) }}
          </template>
          <template v-else-if="$connection.lastCheckedAt">
            {{ $t('Last checked') }}: {{ $date($connection.lastCheckedAt, null, 'short') }} {{ $time($connection.lastCheckedAt) }}
          </template>
        </div>

        <div class="flex items-center gap-3 mt-2.5">
          <button type="button" class="status-refresh-btn" @click="onRefresh" :disabled="$connection.status === 'checking' || $connection.checking">
            <icons name="refresh" class="h-3 w-3" :class="{ 'animate-spin': $connection.status === 'checking' || $connection.checking }" />
            {{ $t('Check again') }}
          </button>
          <button
            v-if="$connection.status === 'online'"
            type="button"
            class="status-refresh-btn"
            @click="onSyncNow"
          >
            <icons name="refresh" class="h-3 w-3" />
            {{ $t('Sync Now') }}
          </button>
        </div>
      </div>
    </template>
  </tec-dropdown>
</template>

<script>
import TecDropdown from '@/Jetstream/Dropdown.vue';
import { refreshConnectionStatus } from '@/Core/offlineStatus';
import { runSync } from '@/Core/offlineSync';

export default {
  components: { TecDropdown },
  computed: {
    statusLabel() {
      return {
        online: this.$t('Online'),
        offline: this.$t('Offline'),
        checking: this.$t('Checking…'),
        syncing: this.$t('Syncing…'),
        degraded: this.$t('Degraded'),
      }[this.$connection.status];
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

<template>
  <admin-layout :title="$t('WIMS Connect')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="send" :title="$t('WIMS Connect')" :subtitle="$t('External communication: WhatsApp, Meta, and SMS. Independent of the internal notification bell and Email Notifications.')">
        <span class="lg-chip" :class="masterOn ? 'lg-chip-green' : 'lg-chip-amber'" style="text-transform: none">
          <span class="ca-dot" :class="masterOn ? 'ca-dot-emerald' : 'ca-dot-amber'"></span>
          {{ masterOn ? $t('ENABLED') : $t('DISABLED') }}
        </span>
        <button type="button" class="lg-btn" :class="masterOn ? 'lg-btn-red' : 'lg-btn-green'" @click="askToggle(masterFeature, true)">
          <icons :name="masterOn ? 'lock-closed' : 'toggle'" class="w-4 h-4" />
          {{ masterOn ? $t('Disable WIMS Connect') : $t('Enable WIMS Connect') }}
        </button>
      </page-header>

      <div class="lg-note lg-note-blue mb-4">
        <icons name="info" class="w-4 h-4" />
        <div>{{ $t('When disabled, no external communication is sent through any gateway, regardless of individual gateway settings. Internal notifications, email, and every other WIMS function are unaffected.') }}</div>
      </div>

      <stat-tiles :items="todayStats" />

      <div class="ca-channels">
        <div v-for="ch in channelTodayStats" :key="ch.key" class="ca-channel">
          <span class="ca-dot" :class="ch.dot"></span>
          <span class="ca-channel-label">{{ ch.label }}</span>
          <span class="ca-channel-value">{{ ch.value }}</span>
        </div>
      </div>

      <div class="flex items-center justify-between mb-3">
        <h2 class="lg-card-title">{{ $t('Gateways') }}</h2>
        <Link :href="route('wims-connect.logs.index')" class="ca-link">{{ $t('View Delivery Logs') }}</Link>
      </div>
      <div class="ca-gateways">
        <div v-for="gw in gatewayCards" :key="gw.driver" class="lg-card ca-gateway">
          <div class="lg-card-head">
            <span class="lg-card-ico" :class="gw.chip"><icons :name="gw.icon" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h3 class="lg-card-title truncate">{{ gw.title }}</h3>
              <p class="lg-card-sub">{{ gw.subtitle }}</p>
            </div>
          </div>

          <div class="flex items-center justify-between mb-3">
            <span class="lg-chip" :class="gw.on ? 'lg-chip-green' : 'lg-chip-slate'">
              <span class="ca-dot" :class="gw.on ? 'ca-dot-emerald' : 'ca-dot-slate'"></span>
              {{ gw.on ? $t('ON') : $t('OFF') }}
            </span>
            <span class="lg-chip" :class="connClass(gw.connection)">{{ gw.connectionLabel }}</span>
          </div>

          <div class="lg-subtext ca-gateway-meta">
            <p v-if="gw.lastSuccess">{{ $t('Last success') }}: {{ gw.lastSuccess }}</p>
            <p v-if="gw.lastFailure">{{ $t('Last failure') }}: {{ gw.lastFailure }}</p>
            <p v-if="!gw.lastSuccess && !gw.lastFailure">{{ $t('No messages sent yet.') }}</p>
          </div>

          <div class="flex items-center gap-2 mt-auto">
            <button type="button" class="lg-btn flex-1" :class="gw.on ? 'lg-btn-soft ca-btn-danger' : 'lg-btn-soft ca-btn-ok'" @click="askToggle(gw.feature, false)">
              {{ gw.on ? $t('Disable Gateway') : $t('Enable Gateway') }}
            </button>
            <Link :href="route(gw.settingsRoute)" class="lg-icon-btn lg-tip" style="width: 36px; height: 36px" :data-tip="$t('Configure')" :aria-label="$t('Configure')">
              <icons name="cog" class="w-4 h-4" />
            </Link>
          </div>
        </div>
      </div>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-indigo"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Recent Activity') }}</h2>
          </div>
          <Link :href="route('wims-connect.logs.index')" class="ca-link">{{ $t('View all') }}</Link>
        </header>
        <div v-if="!recentLogs.length" class="lg-empty">{{ $t('No communications sent yet.') }}</div>
        <div v-else class="ca-list">
          <div v-for="log in recentLogs" :key="log.id" class="ca-list-row">
            <div class="min-w-0">
              <p class="lg-main truncate">{{ eventLabel(log.event_key) }}</p>
              <p class="lg-subtext truncate">{{ log.recipient || '—' }} · {{ log.channel }} · {{ log.created_at }}</p>
            </div>
            <span class="lg-chip flex-none" :class="statusClass(log.status)">{{ log.status }}</span>
          </div>
        </div>
      </section>
    </div>

    <tec-dialog-modal :show="!!pending" @close="closePending" :max-width="'sm'">
      <template #title>
        <div class="flex items-center gap-3">
          <span class="lg-card-ico" :class="pending && pending.is_enabled ? 'lg-ico-rose' : 'lg-ico-emerald'">
            <icons :name="pending && pending.is_enabled ? 'lock-closed' : 'toggle'" className="h-5 w-5" />
          </span>
          <span>{{ confirmTitle }}</span>
        </div>
      </template>

      <template #content>
        <p class="mb-4 text-sm" :class="pending && pending.is_enabled ? 'text-red-700' : 'text-gray-700'">
          {{ confirmContent }}
        </p>

        <div v-if="pending && pending.is_enabled">
          <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $t('Admin Password') }}</label>
          <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
              <icons name="key" className="h-4 w-4" />
            </span>
            <input
              v-model="disablePassword"
              type="password"
              autocomplete="current-password"
              class="w-full rounded-xl border-gray-300 pl-10 shadow-sm focus:border-red-300 focus:ring focus:ring-red-200 focus:ring-opacity-50"
              @keyup.enter="confirmToggle"
            />
          </div>
          <div v-if="passwordError" class="mt-2 text-xs text-red-600">{{ passwordError }}</div>
        </div>
      </template>

      <template #footer>
        <div class="flex items-center justify-between">
          <button type="button" @click="closePending" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</button>
          <loading-button
            @click="confirmToggle"
            :loading="submitting"
            :disabled="submitting || (pending && pending.is_enabled && !disablePassword)"
            :class="pending && pending.is_enabled ? '!bg-red-600 hover:!bg-red-700' : '!bg-emerald-600 hover:!bg-emerald-700'"
          >
            {{ pending && pending.is_enabled ? $t('Disable') : $t('Enable') }}
          </loading-button>
        </div>
      </template>
    </tec-dialog-modal>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, TecDialogModal, LoadingButton, Link, PageHeader, StatTiles },

  props: {
    masterFeature: Object,
    gatewayFeatures: Object,
    gateways: Object,
    todayCounts: Object,
    eventCount: Number,
    events: { type: Object, default: () => ({}) },
    recentLogs: { type: Array, default: () => [] },
  },

  data() {
    return {
      pending: null,
      isMasterPending: false,
      disablePassword: '',
      passwordError: '',
      submitting: false,
    };
  },

  computed: {
    masterOn() {
      return !!(this.masterFeature && this.masterFeature.is_enabled);
    },

    todayStats() {
      const c = this.todayCounts || {};
      return [
        { key: 'sent', label: this.$t("Today's Communications"), value: (c.whatsapp || 0) + (c.meta || 0) + (c.sms || 0), icon: 'send', tone: 'indigo' },
        { key: 'delivered', label: this.$t('Delivered'), value: c.delivered || 0, icon: 'tick', tone: 'emerald' },
        { key: 'queued', label: this.$t('Queued'), value: c.queued || 0, icon: 'clock', tone: 'amber' },
        { key: 'failed', label: this.$t('Failed'), value: c.failed || 0, icon: 'exclamation', tone: 'rose' },
      ];
    },

    channelTodayStats() {
      const c = this.todayCounts || {};
      return [
        { key: 'whatsapp', label: this.$t('WhatsApp'), value: c.whatsapp || 0, dot: 'ca-dot-emerald' },
        { key: 'meta', label: this.$t('Meta'), value: c.meta || 0, dot: 'ca-dot-indigo' },
        { key: 'sms', label: this.$t('SMS'), value: c.sms || 0, dot: 'ca-dot-amber' },
      ];
    },

    gatewayCards() {
      const defs = [
        { driver: 'whatsapp', title: this.$t('WhatsApp Gateway'), subtitle: this.$t('Self-hosted / open-source'), chip: 'lg-ico-emerald', icon: 'whatsapp', settingsRoute: 'wims-connect.gateways.whatsapp' },
        { driver: 'meta', title: this.$t('Meta Gateway'), subtitle: this.$t('Official WhatsApp Business Cloud API'), chip: 'lg-ico-indigo', icon: 'meta', settingsRoute: 'wims-connect.gateways.meta' },
        { driver: 'sms', title: this.$t('SMS Gateway'), subtitle: this.$t('Provider-agnostic'), chip: 'lg-ico-amber', icon: 'sms', settingsRoute: 'wims-connect.gateways.sms' },
      ];

      return defs.map(def => {
        const gw = (this.gateways || {})[def.driver] || {};
        const feature = (this.gatewayFeatures || {})[def.driver];
        const connection = gw.status || 'disconnected';

        return {
          ...def,
          feature,
          on: !!(feature && feature.is_enabled),
          connection,
          connectionLabel: { connected: this.$t('Connected'), disconnected: this.$t('Disconnected'), error: this.$t('Error') }[connection] || connection,
          lastSuccess: gw.last_success_at ? this.$date(gw.last_success_at) : null,
          lastFailure: gw.last_failure_at ? this.$date(gw.last_failure_at) : null,
        };
      });
    },

    confirmTitle() {
      if (!this.pending) return '';
      return this.pending.is_enabled ? `${this.$t('Disable')} ${this.pending.name}?` : `${this.$t('Enable')} ${this.pending.name}?`;
    },

    confirmContent() {
      if (!this.pending) return '';
      if (this.isMasterPending) {
        return this.pending.is_enabled
          ? this.$t('This immediately stops all external communication (WhatsApp, Meta, and SMS) regardless of individual gateway settings. Nothing else in WIMS is affected.')
          : this.$t('Gateways already switched on will resume sending once re-enabled here.');
      }
      return this.pending.is_enabled
        ? `${this.$t('This will stop external communication through')} ${this.pending.name}. ${this.$t('Other gateways are unaffected.')}`
        : `${this.pending.name} ${this.$t('will resume sending once WIMS Connect itself is also enabled.')}`;
    },
  },

  methods: {
    eventLabel(key) {
      return this.events?.[key]?.label || key;
    },

    connClass(c) {
      return { connected: 'lg-chip-blue', disconnected: 'lg-chip-slate', error: 'lg-chip-red' }[c] || 'lg-chip-slate';
    },

    statusClass(status) {
      return {
        SENT: 'lg-chip-green',
        DELIVERED: 'lg-chip-green',
        QUEUED: 'lg-chip-indigo',
        SENDING: 'lg-chip-indigo',
        RETRYING: 'lg-chip-amber',
        FAILED: 'lg-chip-red',
      }[status] || 'lg-chip-indigo';
    },

    askToggle(feature, isMaster) {
      if (!feature) return;
      this.pending = feature;
      this.isMasterPending = isMaster;
      this.disablePassword = '';
      this.passwordError = '';
    },

    closePending() {
      this.pending = null;
      this.disablePassword = '';
      this.passwordError = '';
    },

    confirmToggle() {
      const feature = this.pending;
      if (!feature) return;
      if (feature.is_enabled && !this.disablePassword) return;

      this.submitting = true;
      this.passwordError = '';

      this.$inertia.put(
        this.route('system-features.update', feature.id),
        { enabled: !feature.is_enabled, password: feature.is_enabled ? this.disablePassword : undefined },
        {
          preserveScroll: true,
          onError: errors => { this.passwordError = errors.password || ''; },
          onSuccess: () => { this.closePending(); },
          onFinish: () => { this.submitting = false; },
        }
      );
    },
  },
};
</script>

<style scoped>
.ca-dot { display: inline-block; width: 7px; height: 7px; border-radius: 9999px; background: currentColor; flex: none; }
.ca-dot-emerald { background: #34d399; }
.ca-dot-indigo { background: #818cf8; }
.ca-dot-amber { background: #fbbf24; }
.ca-dot-slate { background: #94a3b8; }

.ca-channels { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(190px, 100%), 1fr)); gap: 12px; margin-bottom: 1.25rem; }
.ca-channel { display: flex; align-items: center; gap: 10px; padding: 0.7rem 1rem; border-radius: 16px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); }
.ca-channel-label { flex: 1; font-size: 0.78rem; font-weight: 700; color: var(--lg-mute); }
.ca-channel-value { font-size: 0.95rem; font-weight: 800; color: var(--lg-ink); font-variant-numeric: tabular-nums; }

.ca-link { font-size: 0.76rem; font-weight: 800; color: #4338ca; }
.ca-link:hover { color: #312e81; }
html.dark .ca-link { color: #a5b4fc; }

.ca-gateways { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(260px, 100%), 1fr)); gap: 16px; margin-bottom: 1.25rem; }
.ca-gateway { display: flex; flex-direction: column; margin-bottom: 0; }
.ca-gateway .lg-card-head { margin-bottom: 0.85rem; }
.ca-gateway-meta { margin-bottom: 1rem; }
.ca-gateway-meta p + p { margin-top: 3px; }
.ca-btn-ok { color: #047857; border-color: rgba(16, 185, 129, 0.4); background: rgba(16, 185, 129, 0.08); }
.ca-btn-danger { color: #b91c1c; border-color: rgba(239, 68, 68, 0.4); background: rgba(239, 68, 68, 0.07); }
html.dark .ca-btn-ok { color: #6ee7b7; }
html.dark .ca-btn-danger { color: #fca5a5; }

.ca-list-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 0.7rem 0; border-bottom: 1px solid var(--lg-line); font-size: 0.83rem; }
.ca-list-row:last-child { border-bottom: 0; }
</style>

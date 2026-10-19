<template>
  <admin-layout :title="title">
    <div class="lg-page px-4 md:px-0">
      <page-header :icon="driver" :title="title" :subtitle="`${$t('Driver key')}: ${driver}`">
        <Link :href="route('wims-connect.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-3.5 h-3.5 transform rotate-90" />
          {{ $t('WIMS Connect') }}
        </Link>
        <span class="lg-chip" :class="isOn ? 'lg-chip-green' : 'lg-chip-slate'">
          <span class="ca-dot"></span>
          {{ isOn ? $t('ON') : $t('OFF') }}
        </span>
        <button v-if="isSuperAdmin" type="button" class="lg-btn" :class="isOn ? 'lg-btn-red' : 'lg-btn-green'" @click="askToggle">
          {{ isOn ? $t('Disable') : $t('Enable') }}
        </button>
      </page-header>

      <stat-tiles :items="statItems" />

      <!-- Beem Africa: SMS credit left -->
      <section v-if="driver === 'sms'" class="lg-card sb-card" :class="'is-' + balanceLevel">
        <div class="sb-ring" aria-hidden="true">
          <svg viewBox="0 0 120 120"><circle class="sb-ring__bg" cx="60" cy="60" r="50" /><circle class="sb-ring__fg" cx="60" cy="60" r="50" :style="{ strokeDashoffset: ringOffset }" /></svg>
          <icons name="sms" class="sb-ring__ico" />
        </div>
        <div class="sb-main min-w-0">
          <p class="sb-eyebrow">{{ $t('SMS credit left') }} <span v-if="bal.provider">· {{ bal.provider }}</span></p>
          <template v-if="bal.loading && bal.value === null"><p class="sb-num sb-num--wait">{{ $t('Checking...') }}</p></template>
          <template v-else-if="bal.ok"><p class="sb-num"><b>{{ shown.toLocaleString() }}</b> <small>{{ $t('SMS') }}</small></p>
            <p class="gw-state" :class="'is-' + balanceLevel"><icons :name="balanceLevel === 'good' ? 'tick' : 'exclamation'" class="w-4 h-4" /><span>{{ balanceText }}</span></p></template>
          <template v-else><p class="sb-num sb-num--bad">{{ $t('Not available') }}</p><p class="gw-state is-bad"><icons name="exclamation" class="w-4 h-4" /><span>{{ bal.error }}</span></p></template>
          <p v-if="bal.checkedAt" class="sb-time">{{ $t('Checked') }} {{ bal.checkedAt }}</p>
        </div>
        <div class="sb-side">
          <button type="button" class="lg-btn lg-btn-soft" :disabled="bal.loading" @click="loadBalance(true)"><icons name="refresh" class="w-4 h-4" :class="{ 'animate-spin': bal.loading }" />{{ $t('Refresh') }}</button>
          <a href="https://engage.beem.africa/#!/dashboard/sms/purchase-sms" target="_blank" rel="noopener noreferrer" class="sb-link">{{ $t('Top up on Beem') }} →</a>
        </div>
      </section>

      <!-- SMS Sent: per day, last 15 days (the 3 most recent show, Expand shows all 15) -->
      <section v-if="driver === 'sms' && history" class="lg-card sh-card">
        <header class="sh-head">
          <span class="sh-ico"><icons name="sms" class="w-5 h-5" /></span>
          <div class="sh-title min-w-0">
            <h2>{{ $t('SMS Sent') }}</h2>
            <p>{{ $t('Per day, last 15 days, newest first') }}</p>
          </div>
          <button type="button" class="sh-toggle" :aria-expanded="histOpen ? 'true' : 'false'" @click="histOpen = !histOpen">
            <icons name="chevron-down" class="w-4 h-4 sh-chev" :class="{ 'is-open': histOpen }" />{{ histOpen ? $t('Collapse') : $t('Expand') }}
          </button>
        </header>

        <div class="sh-tiles">
          <div class="sh-tile is-total"><span class="sh-tile__ico"><icons name="sms" class="w-5 h-5" /></span><div><small>{{ $t('Total for 15 days') }}</small><b>{{ history.totals.total.toLocaleString() }} <i>{{ $t('SMS(s)') }}</i></b></div></div>
          <div class="sh-tile is-sent"><span class="sh-tile__ico"><icons name="tick" class="w-5 h-5" /></span><div><small>{{ $t('Sent') }}</small><b>{{ history.totals.sent.toLocaleString() }} <i>{{ $t('SMS(s)') }}</i></b></div></div>
          <div class="sh-tile" :class="history.totals.failed ? 'is-failed' : 'is-none'"><span class="sh-tile__ico"><icons :name="history.totals.failed ? 'exclamation' : 'tick'" class="w-5 h-5" /></span><div><small>{{ $t('Failed') }}</small><b>{{ history.totals.failed.toLocaleString() }} <i>{{ $t('SMS(s)') }}</i></b></div></div>
        </div>

        <ul class="sh-list">
          <li v-for="d in shownDays" :key="d.date" :class="{ 'is-empty': !d.total, 'is-today': isToday(d.date) }">
            <span class="sh-cal"><icons name="calendar" class="w-4 h-4" /></span>
            <span class="sh-day"><b>{{ dayName(d.date) }}</b><small>{{ $date(d.date) }}</small></span>
            <span class="sh-bar" aria-hidden="true"><i :style="{ width: barWidth(d) + '%' }"></i></span>
            <span class="sh-num">
              <b class="sh-pill" :class="d.total ? 'is-on' : 'is-zero'">{{ d.total.toLocaleString() }} {{ $t('SMS(s)') }}</b>
              <small v-if="d.failed" class="sh-fail"><icons name="exclamation" class="w-3 h-3" />{{ d.failed }} {{ $t('failed') }}</small>
            </span>
          </li>
        </ul>
        <p v-if="!histOpen" class="sh-more">{{ $t('Showing the last 3 days.') }} <button type="button" @click="histOpen = true">{{ $t('Expand to see all 15') }}</button></p>
      </section>

      <div v-if="gateway.last_error" class="lg-note lg-note-red mb-4">
        <icons name="exclamation" class="w-4 h-4" />
        <div>{{ $t('Error information') }}: {{ gateway.last_error }}</div>
      </div>

      <Link v-if="$can('read-wims-connect-bulk-connect')" :href="route('wims-connect.bulk-connect.index')" class="lg-card ca-launch">
        <span class="lg-card-ico lg-ico-pink" style="width: 46px; height: 46px"><icons name="send" class="w-6 h-6" /></span>
        <div class="min-w-0 flex-1">
          <h2 class="lg-card-title">{{ $t('Bulk Connect') }}</h2>
          <p class="lg-card-sub">{{ $t('Compose one message, send it over WhatsApp, Meta, or SMS to many recipients, schedule for later, and track every send.') }}</p>
        </div>
        <icons name="chevron-down" class="w-4 h-4 text-gray-400 transform -rotate-90 flex-none" />
      </Link>

      <div class="ca-split">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="cog" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Connection Settings') }}</h2>
              <p class="lg-card-sub">{{ $t('Secret fields are masked below. Leave one blank to keep its current value.') }}</p>
            </div>
            <span v-if="!canManage" class="lg-chip lg-chip-slate">{{ $t('View only') }}</span>
          </header>

          <div class="lg-fields">
            <div v-for="(meta, key) in fields" :key="key" class="lg-span-all">
              <label class="ca-label">{{ meta.label }}</label>
              <input
                v-model="form[key]"
                :type="meta.secret ? 'password' : 'text'"
                :placeholder="meta.secret ? maskedHint(key) : meta.placeholder"
                autocomplete="off"
                :disabled="!canManage"
                class="ca-input w-full"
              />
              <p v-if="meta.secret && values[key]" class="lg-subtext mt-1">{{ $t('Currently set') }}: <code class="ca-code">{{ values[key] }}</code></p>
              <p v-if="form.errors && form.errors[key]" class="mt-1 text-xs text-rose-600">{{ form.errors[key] }}</p>
            </div>
          </div>

          <div v-if="canManage" class="mt-5 flex items-center gap-3">
            <button type="button" class="lg-btn lg-btn-green" :disabled="saving" @click="save">
              <icons name="tick" class="w-4 h-4" />
              {{ saving ? $t('Saving…') : $t('Save Settings') }}
            </button>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="send" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Test Message') }}</h2>
              <p class="lg-card-sub">{{ $t('Send a real test message through this gateway to confirm the connection works.') }}</p>
            </div>
            <span v-if="!canManage" class="lg-chip lg-chip-slate">{{ $t('View only') }}</span>
          </header>

          <label class="ca-label">{{ $t('Send to') }}</label>
          <input v-model="testAddress" type="text" :placeholder="$t('e.g. +255XXXXXXXXX')" :disabled="!canManage" class="ca-input w-full mb-3" />

          <button type="button" class="lg-btn lg-btn-primary w-full" :disabled="!canManage || testing || !testAddress" @click="sendTest">
            <icons name="send" class="w-4 h-4" />
            {{ testing ? $t('Sending…') : $t('Send Test Message') }}
          </button>

          <p v-if="testResult" class="lg-note mt-3" :class="testResult.ok ? 'ca-note-ok' : 'lg-note-red'">
            {{ testResult.text }}
          </p>
        </section>
      </div>
    </div>

    <tec-dialog-modal :show="showConfirm" @close="showConfirm = false" :max-width="'sm'">
      <template #title>
        <div class="flex items-center gap-3">
          <span class="lg-card-ico" :class="isOn ? 'lg-ico-rose' : 'lg-ico-emerald'">
            <icons :name="isOn ? 'lock-closed' : 'toggle'" className="h-5 w-5" />
          </span>
          <span>{{ isOn ? $t('Disable') : $t('Enable') }} {{ title }}?</span>
        </div>
      </template>

      <template #content>
        <p class="mb-4 text-sm" :class="isOn ? 'text-red-700' : 'text-gray-700'">
          {{ isOn ? $t('This gateway will stop sending external communication. Other gateways are unaffected.') : $t('This gateway will resume sending once WIMS Connect itself is also enabled.') }}
        </p>

        <div v-if="isOn">
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
          <button type="button" @click="showConfirm = false" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</button>
          <loading-button
            @click="confirmToggle"
            :loading="toggling"
            :disabled="toggling || (isOn && !disablePassword)"
            :class="isOn ? '!bg-red-600 hover:!bg-red-700' : '!bg-emerald-600 hover:!bg-emerald-700'"
          >
            {{ isOn ? $t('Disable') : $t('Enable') }}
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
    driver: String,
    title: String,
    fields: Object,
    values: Object,
    feature: Object,
    gateway: Object,
    history: Object,
  },

  data() {
    const histState = { histOpen: false };
    // Secret fields (API keys/tokens) stay blank-by-default — the backend
    // already sends those masked (••••1234), not the real value, and typing
    // one in only overwrites it if non-empty (see WimsConnectGatewayController
    // ::update). Plain-text fields have no such reason to hide their current
    // value, and leaving them blank made a successful save look like it had
    // silently failed — the field just always showed empty again on reload.
    const form = {};
    Object.keys(this.fields || {}).forEach(key => {
      const meta = this.fields[key];
      form[key] = meta.secret ? '' : (this.values[key] || '');
    });

    return {
      ...histState,
      form,
      saving: false,
      testAddress: '',
      testing: false,
      testResult: null,
      showConfirm: false,
      disablePassword: '',
      passwordError: '',
      toggling: false,
      bal: { loading: false, ok: false, value: null, error: '', provider: '', checkedAt: '' },
      shown: 0,
      balTimer: null,
    };
  },

  mounted() {
    if (this.driver === 'sms') {
      this.loadBalance(false);
      this.balTimer = setInterval(() => !document.hidden && this.loadBalance(false), 300000);
    }
  },

  beforeUnmount() { clearInterval(this.balTimer); },

  computed: {
    shownDays() {
      const days = (this.history && this.history.days) || [];
      return this.histOpen ? days : days.slice(0, 3);
    },
    histMax() {
      return Math.max(1, ...(((this.history && this.history.days) || []).map(d => d.total)));
    },
    // green from 500 credits, amber from 100, red below that
    balanceLevel() { if (!this.bal.ok) return 'none'; return this.bal.value >= 500 ? 'good' : this.bal.value >= 100 ? 'warn' : 'bad'; },
    balanceText() {
      const v = this.bal.value;
      if (v <= 0) return this.$t('Empty. No message can be sent until you top up.');
      if (v < 100) return this.$t('Very low. Top up soon so messages keep going out.');
      if (v < 500) return this.$t('Getting low. Plan a top up.');
      return this.$t('Plenty left.');
    },
    ringOffset() { const f = this.bal.ok ? Math.min(1, this.bal.value / 1000) : 0; return (314 * (1 - f)).toFixed(1); },
    isOn() {
      return !!(this.feature && this.feature.is_enabled);
    },
    canManage() {
      return this.$can('manage-wims-connect');
    },
    // The global on/off toggle hits SystemFeatureController, which is still
    // hard Super-Admin-gated (a broader settings-level switch, out of scope
    // for this page's own read/manage permissions) — hide it for anyone who
    // wouldn't be able to use it, so there's no dead button to click.
    isSuperAdmin() {
      return (this.$page.props.auth?.user?.roles || []).some(r => r.name === 'Super Admin');
    },
    connectionLabel() {
      return { connected: this.$t('Connected'), disconnected: this.$t('Disconnected'), error: this.$t('Error') }[this.gateway.status] || this.gateway.status;
    },
    statItems() {
      const tone = { connected: 'emerald', disconnected: 'slate', error: 'rose' }[this.gateway.status] || 'slate';
      return [
        { label: this.$t('Connection status'), value: this.connectionLabel, icon: 'refresh', tone },
        { label: this.$t('Last successful message'), value: this.gateway.last_success_at ? this.$date(this.gateway.last_success_at) : this.$t('None yet'), icon: 'tick', tone: 'emerald' },
        { label: this.$t('Last failed message'), value: this.gateway.last_failure_at ? this.$date(this.gateway.last_failure_at) : this.$t('None yet'), icon: 'exclamation', tone: 'rose' },
      ];
    },
    connectionTextClass() {
      return { connected: 'text-emerald-700', disconnected: 'text-gray-600', error: 'text-rose-700' }[this.gateway.status] || 'text-gray-600';
    },
  },

  methods: {
    barWidth(d) {
      return d.total ? Math.max(3, Math.round((d.total / this.histMax) * 100)) : 0;
    },
    isToday(iso) {
      return this.dayName(iso) === this.$t('Today');
    },
    dayName(iso) {
      const d = new Date(iso + 'T00:00:00');
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      const diff = Math.round((today - d) / 86400000);
      if (diff === 0) return this.$t('Today');
      if (diff === 1) return this.$t('Yesterday');
      return d.toLocaleDateString(undefined, { weekday: 'long' });
    },
    loadBalance(refresh) {
      this.bal.loading = true;
      axios.get(this.route('wims-connect.gateways.sms.balance'), { params: refresh ? { refresh: 1 } : {} })
        .then(({ data }) => {
          this.bal.provider = data.provider || '';
          this.bal.ok = !!data.ok;
          this.bal.error = data.error || '';
          if (data.ok) {
            this.bal.value = data.balance;
            this.bal.checkedAt = new Date(data.checked_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            this.countTo(data.balance);
          }
        })
        .catch(e => { this.bal.ok = false; this.bal.error = e?.response?.data?.message || this.$t('The balance could not be read.'); })
        .finally(() => { this.bal.loading = false; });
    },
    countTo(target) {
      const from = this.shown, t0 = performance.now(), dur = 900;
      const step = now => { const k = Math.min(1, (now - t0) / dur); this.shown = Math.round(from + (target - from) * (1 - Math.pow(1 - k, 3))); if (k < 1) requestAnimationFrame(step); };
      requestAnimationFrame(step);
    },

    maskedHint(key) {
      return this.values[key] || this.$t('Not set');
    },

    save() {
      this.saving = true;
      this.$inertia.put(this.route('wims-connect.gateways.update', this.driver), this.form, {
        preserveScroll: true,
        onSuccess: () => {
          // Only secret fields need clearing (the server never echoes a real
          // secret back) — blanking non-secret fields too made a successful
          // save look like it had failed. See the matching note in data().
          Object.keys(this.form).forEach(key => {
            if (this.fields[key] && this.fields[key].secret) this.form[key] = '';
          });
        },
        onFinish: () => { this.saving = false; },
      });
    },

    sendTest() {
      if (!this.testAddress) return;
      this.testing = true;
      this.testResult = null;

      axios.post(this.route('wims-connect.gateways.test', this.driver), { address: this.testAddress })
        .then(res => {
          this.testResult = { ok: true, text: res.data.message };
        })
        .catch(err => {
          const message = err?.response?.data?.message
            || err?.response?.data?.errors?.address?.[0]
            || this.$t('Test message failed. Check the gateway configuration and try again.');
          this.testResult = { ok: false, text: message };
        })
        .finally(() => { this.testing = false; });
    },

    askToggle() {
      this.showConfirm = true;
      this.disablePassword = '';
      this.passwordError = '';
    },

    confirmToggle() {
      if (!this.feature) return;
      if (this.isOn && !this.disablePassword) return;

      this.toggling = true;
      this.passwordError = '';

      this.$inertia.put(
        this.route('system-features.update', this.feature.id),
        { enabled: !this.isOn, password: this.isOn ? this.disablePassword : undefined },
        {
          preserveScroll: true,
          onError: errors => { this.passwordError = errors.password || ''; },
          onSuccess: () => { this.showConfirm = false; },
          onFinish: () => { this.toggling = false; },
        }
      );
    },
  },
};
</script>

<style scoped>
.sh-card { margin-bottom: 1rem; padding: 1.1rem 1.2rem; }
.sh-head { display: flex; align-items: center; gap: 0.8rem; margin-bottom: 0.9rem; }
.sh-ico { flex: none; display: inline-grid; place-items: center; width: 44px; height: 44px; border-radius: 15px; color: #fff; background: linear-gradient(145deg, #10b981, #0ea5e9); box-shadow: 0 12px 20px -12px rgba(14, 165, 233, 0.8), inset 0 1px 1px rgba(255, 255, 255, 0.4); }
.sh-title { flex: 1 1 auto; } .sh-title h2 { margin: 0; font-size: 1.05rem; font-weight: 800; color: var(--lg-ink); } .sh-title p { margin: 2px 0 0; font-size: 0.78rem; color: var(--lg-faint); }
.sh-toggle { flex: none; display: inline-flex; align-items: center; gap: 6px; padding: 0.5rem 0.95rem; border-radius: 9999px; font-size: 0.82rem; font-weight: 800; white-space: nowrap; color: #fff; background: linear-gradient(145deg, #1c3f66, #2f6fb3); box-shadow: 0 10px 18px -10px rgba(28, 63, 102, 0.85), inset 0 1px 1px rgba(255, 255, 255, 0.3); transition: transform 0.15s ease; }
.sh-toggle:hover { transform: translateY(-1px); }
.sh-chev { transition: transform 0.2s ease; } .sh-chev.is-open { transform: rotate(180deg); }
.sh-tiles { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.7rem; margin-bottom: 0.9rem; }
.sh-tile { display: flex; align-items: center; gap: 0.7rem; min-width: 0; padding: 0.7rem 0.85rem; border-radius: 16px; background: var(--lg-card); box-shadow: 0 10px 18px -14px rgba(15, 37, 64, 0.55), 0 0 0 1px var(--lg-line); }
.sh-tile__ico { flex: none; display: inline-grid; place-items: center; width: 38px; height: 38px; border-radius: 12px; color: #fff; background: linear-gradient(145deg, #3b82f6, #1d4ed8); }
.sh-tile.is-sent .sh-tile__ico { background: linear-gradient(145deg, #34d399, #059669); } .sh-tile.is-failed .sh-tile__ico { background: linear-gradient(145deg, #fb7185, #e11d48); } .sh-tile.is-none .sh-tile__ico { background: linear-gradient(145deg, #94a3b8, #64748b); }
.sh-tile small { display: block; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.sh-tile b { display: block; font-size: 1.25rem; font-weight: 900; line-height: 1.15; font-variant-numeric: tabular-nums; color: var(--lg-ink); } .sh-tile b i { font-style: normal; font-size: 0.72rem; font-weight: 800; color: var(--lg-mute); }
.sh-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.5rem; }
.sh-list li { display: grid; grid-template-columns: auto minmax(96px, 150px) minmax(0, 1fr) auto; align-items: center; gap: 0.8rem; padding: 0.6rem 0.8rem; border-radius: 16px; background: var(--lg-card); box-shadow: 0 10px 16px -14px rgba(15, 37, 64, 0.55), 0 0 0 1px var(--lg-line); }
.sh-list li.is-today { box-shadow: 0 10px 16px -14px rgba(16, 185, 129, 0.6), 0 0 0 1.5px rgba(16, 185, 129, 0.55); background: linear-gradient(135deg, rgba(209, 250, 229, 0.45), var(--lg-card) 60%); }
.sh-cal { display: inline-grid; place-items: center; width: 32px; height: 32px; border-radius: 10px; color: #2563eb; background: rgba(59, 130, 246, 0.12); } .is-empty .sh-cal { color: #94a3b8; background: var(--lg-soft); }
.sh-day { display: grid; min-width: 0; line-height: 1.2; } .sh-day b { font-size: 0.88rem; color: var(--lg-ink); } .sh-day small { font-size: 0.72rem; color: var(--lg-faint); }
.sh-bar { display: block; height: 10px; border-radius: 9999px; background: var(--lg-soft); overflow: hidden; }
.sh-bar i { display: block; height: 100%; border-radius: 9999px; background: linear-gradient(90deg, #10b981, #3b82f6); }
.sh-num { display: grid; justify-items: end; gap: 3px; }
.sh-pill { display: inline-flex; padding: 3px 11px; border-radius: 9999px; font-size: 0.8rem; font-weight: 800; white-space: nowrap; font-variant-numeric: tabular-nums; } .sh-pill.is-on { color: #065f46; background: #d1fae5; } .sh-pill.is-zero { color: #64748b; background: var(--lg-soft); }
.sh-fail { display: inline-flex; align-items: center; gap: 3px; font-size: 0.68rem; font-weight: 800; color: #be123c; white-space: nowrap; }
.sh-more { margin: 0.8rem 0 0; font-size: 0.8rem; color: var(--lg-faint); } .sh-more button { font-weight: 800; color: #2563eb; }
html.dark .sh-pill.is-on { color: #6ee7b7; background: rgba(16, 185, 129, 0.18); }
@media (max-width: 767.98px) { .sh-tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); } .sh-tile.is-total { grid-column: 1 / -1; } }
@media (max-width: 400px) { .sh-tile { padding: 0.55rem 0.6rem; gap: 0.5rem; } .sh-tile__ico { width: 32px; height: 32px; } .sh-tile b { font-size: 1.05rem; } }
@media (max-width: 575.98px) { .sh-list li { grid-template-columns: auto minmax(0, 1fr) auto; row-gap: 0.4rem; } .sh-bar { grid-column: 1 / -1; grid-row: 2; } .sh-card { padding: 0.9rem; } }
/* balance card message: a compact status pill (the old .sb-state class collided with Smart Brief's global style and became a big white box) */
.gw-state > svg { flex: none; margin-top: 2px; }
.gw-state { display: inline-flex; align-items: flex-start; gap: 6px; margin: 0.35rem 0 0; padding: 5px 12px 5px 9px; border-radius: 14px; line-height: 1.35; font-size: 0.8rem; font-weight: 800; color: #065f46; background: #d1fae5; box-shadow: 0 0 0 1px rgba(16, 185, 129, 0.35); }
.gw-state.is-warn { color: #92400e; background: #fef3c7; box-shadow: 0 0 0 1px rgba(245, 158, 11, 0.45); } .gw-state.is-bad, .gw-state.is-none { color: #9f1239; background: #ffe4e6; box-shadow: 0 0 0 1px rgba(225, 29, 72, 0.4); }
html.dark .gw-state { color: #6ee7b7; background: rgba(16, 185, 129, 0.16); }
.ca-dot { display: inline-block; width: 7px; height: 7px; border-radius: 9999px; background: currentColor; }
.ca-split { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 16px; align-items: start; }
@media (max-width: 1023.98px) { .ca-split { grid-template-columns: minmax(0, 1fr); } }
.ca-launch { display: flex; align-items: center; gap: 14px; transition: transform 0.15s ease; }
.ca-launch:hover { transform: translateY(-2px); }
.ca-label { display: block; margin-bottom: 4px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.ca-input { border: 1px solid #e2e8f0; border-radius: 0.85rem; padding: 0.55rem 0.8rem; font-size: 0.85rem; background: #fff; }
.ca-input:focus { outline: none; border-color: #34d399; box-shadow: 0 0 0 3px rgba(52, 211, 153, 0.18); }
.ca-input:disabled { opacity: 0.7; cursor: not-allowed; }
html.dark .ca-input { background: #0f172a; border-color: rgba(255, 255, 255, 0.12); color: #e2e8f0; }
.ca-code { font-family: ui-monospace, monospace; background: rgba(100, 116, 139, 0.14); padding: 0.05rem 0.4rem; border-radius: 0.3rem; }
.ca-note-ok { color: #047857; background: rgba(209, 250, 229, 0.7); border: 1px solid rgba(16, 185, 129, 0.35); }
html.dark .ca-note-ok { color: #6ee7b7; background: rgba(16, 185, 129, 0.1); }

.sb-card { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; gap: 1.1rem; align-items: center; padding: 1.1rem 1.3rem; margin-bottom: 1rem; background: linear-gradient(135deg, #ecfdf5, #fffbeb 70%, #fff1e0); box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.35); }
.sb-card.is-warn { background: linear-gradient(135deg, #fffbeb, #fff1e0); box-shadow: inset 0 0 0 1.5px rgba(245, 158, 11, 0.6); } .sb-card.is-bad { background: linear-gradient(135deg, #fff1f2, #fff7ed); box-shadow: inset 0 0 0 1.5px rgba(225, 29, 72, 0.5); animation: sb-pulse 2s ease-in-out infinite; } .sb-card.is-none { background: var(--lg-card); box-shadow: inset 0 0 0 1px var(--lg-line); }
@keyframes sb-pulse { 0%, 100% { box-shadow: inset 0 0 0 1.5px rgba(225, 29, 72, 0.4); } 50% { box-shadow: inset 0 0 0 2px rgba(225, 29, 72, 0.9), 0 0 18px rgba(225, 29, 72, 0.25); } }
.sb-ring { position: relative; width: 84px; height: 84px; } .sb-ring svg { width: 100%; height: 100%; transform: rotate(-90deg); } .sb-ring circle { fill: none; stroke-width: 9; } .sb-ring__bg { stroke: rgba(22, 101, 52, 0.14); } .sb-ring__fg { stroke: #22c55e; stroke-linecap: round; stroke-dasharray: 314; transition: stroke-dashoffset 1s ease; filter: drop-shadow(0 0 4px rgba(34, 197, 94, 0.5)); } .is-warn .sb-ring__fg { stroke: #f59e0b; filter: drop-shadow(0 0 4px rgba(245, 158, 11, 0.5)); } .is-bad .sb-ring__fg { stroke: #e11d48; filter: drop-shadow(0 0 4px rgba(225, 29, 72, 0.5)); }
.sb-ring__ico { position: absolute; inset: 0; margin: auto; width: 30px; height: 30px; color: #15803d; } .is-warn .sb-ring__ico { color: #b45309; } .is-bad .sb-ring__ico { color: #be123c; }
.sb-eyebrow { margin: 0; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.14em; text-transform: uppercase; color: var(--lg-mute); }
.sb-num { margin: 0.1rem 0; line-height: 1.05; color: #14532d; } .sb-num b { font-size: 2.3rem; font-weight: 800; font-variant-numeric: tabular-nums; } .sb-num small { font-size: 0.9rem; font-weight: 800; color: var(--lg-mute); } .is-warn .sb-num { color: #92400e; } .is-bad .sb-num { color: #9f1239; } .sb-num--wait, .sb-num--bad { font-size: 1.3rem; font-weight: 800; }
.sb-state { margin: 0.15rem 0 0; font-size: 0.82rem; font-weight: 700; color: #15803d; } .is-warn .sb-state { color: #b45309; } .is-bad .sb-state, .sb-state--bad { color: #be123c; } .sb-time { margin: 0.2rem 0 0; font-size: 0.7rem; color: var(--lg-faint); }
.sb-side { display: grid; gap: 0.5rem; justify-items: end; } .sb-link { font-size: 0.78rem; font-weight: 800; color: #c2410c; text-decoration: none; } .sb-link:hover { text-decoration: underline; }
html.dark .sb-card { background: linear-gradient(135deg, rgba(6, 78, 59, 0.5), rgba(7, 20, 43, 0.6)); } html.dark .sb-num { color: #ecfdf5; } html.dark .sb-state { color: #6ee7b7; } html.dark .sb-ring__ico { color: #6ee7b7; }
@media (max-width: 640px) { .sb-card { grid-template-columns: auto minmax(0, 1fr); } .sb-side { grid-column: 1 / -1; justify-items: start; grid-auto-flow: column; align-items: center; } }
@media (prefers-reduced-motion: reduce) { .sb-card.is-bad { animation: none; } }
</style>

<template>
  <admin-layout :title="$t('WIMS Connect / Delivery Logs')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="list" :title="$t('Delivery Logs')" :subtitle="$t('Everything WIMS sends out: every SMS, email, WhatsApp and Meta message and every app alert (shown here from now on). Click a message to read it exactly as it went out, and send it again from there.')">
        <Link :href="route('wims-connect.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-3.5 h-3.5 transform rotate-90" />
          {{ $t('WIMS Connect') }}
        </Link>
        <button v-if="retryableCount > 0" type="button" class="lg-btn ca-btn-amber" :disabled="retryingAll" @click="retryAll">
          <icons name="refresh" class="w-4 h-4" :class="{ 'animate-spin': retryingAll }" />
          <span class="hidden sm:inline">{{ retryingAll ? $t('Retrying…') : $t('Retry All') }} ({{ retryableCount }})</span>
          <span class="sm:hidden">{{ retryableCount }}</span>
        </button>
        <a :href="printUrl" target="_blank" class="lg-btn lg-btn-dark">
          <icons name="printer" class="w-4 h-4" />
          <span class="hidden sm:inline">{{ $t('Print') }}</span>
        </a>
        <a :href="downloadUrl" target="_blank" class="lg-btn lg-btn-primary">
          <icons name="download" class="w-4 h-4" />
          <span class="hidden sm:inline">{{ $t('Download') }}</span>
        </a>
      </page-header>

      <section class="lg-card lg-filters ca-filters relative z-20 print:hidden">
        <div class="lg-grid">
          <dropdown-filter v-model="form.channel" :options="channelOptions" :placeholder="$t('All Channels')" />
          <dropdown-filter v-model="form.status" :options="statusOptions" :placeholder="$t('All Statuses')" />
          <dropdown-filter v-model="form.event_key" :options="eventFilterOptions" :placeholder="$t('All Events')" />
          <dropdown-filter v-model="form.recipient_user_id" :options="recipientOptions" :placeholder="$t('All Recipients')" />

          <date-picker v-model="form.date_from" :placeholder="$t('From')" clearable />
          <date-picker v-model="form.date_to" :placeholder="$t('To')" clearable />
        </div>
        <div class="flex items-center gap-2 mt-3">
          <button type="button" class="lg-btn lg-btn-green" @click="applyFilters">
            <icons name="search" class="w-3.5 h-3.5" />
            {{ $t('Apply Filters') }}
          </button>
          <button type="button" class="lg-btn lg-btn-soft" @click="clearFilters">{{ $t('Clear') }}</button>
        </div>
      </section>

      <section class="lg-card" style="padding: 1rem">
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Event') }}</th>
                <th>{{ $t('Recipient') }}</th>
                <th>{{ $t('Message') }}</th>
                <th>{{ $t('Status') }}</th>
                <th>{{ $t('Sent') }}</th>
                <th class="text-center">{{ $t('Retries') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="log in logs.data" :key="log.id">
                <td :data-label="$t('Event')">
                  <div class="lg-main">{{ eventLabel(log.event_key) }}</div>
                  <div v-if="log.error_message" class="lg-tip tip-left mt-0.5" :data-tip="log.error_message"><div class="text-xs text-rose-600 lg-clip truncate">{{ log.error_message }}</div></div>
                </td>
                <td :data-label="$t('Recipient')">
                  <div class="lg-main">{{ log.recipient?.name || '—' }}</div>
                  <div class="lg-subtext">{{ log.address }}</div>
                </td>
                <td :data-label="$t('Message')" class="dl-msg-td">
                  <button type="button" class="dl-msg" :class="'is-' + log.channel" :title="$t('Open this message')" @click="openMessage(log)">
                    <span class="dl-msg__ico"><icons :name="chIcon(log.channel)" class="w-5 h-5" /></span>
                    <span class="dl-msg__t">
                      <b v-if="log.subject">{{ log.subject }}</b>
                      <span>{{ preview(log) }}</span>
                    </span>
                  </button>
                </td>
                <td :data-label="$t('Status')">
                  <span class="lg-chip" :class="statusClass(log.status)">{{ log.status }}</span>
                </td>
                <td :data-label="$t('Sent')"><span class="lg-subtext">{{ log.sent_at ? $datetime(log.sent_at) : '—' }}</span></td>
                <td :data-label="$t('Retries')" class="text-center"><span class="lg-subtext">{{ log.retry_count }}</span></td>
                <td>
                  <div class="lg-actions">
                    <button v-if="['FAILED', 'RETRYING'].includes(log.status)" type="button" class="lg-btn lg-btn-soft" @click="retry(log)">
                      <icons name="refresh" class="w-3.5 h-3.5" />
                      {{ $t('Retry') }}
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="!logs.data.length" class="lg-empty-row">
                <td class="lg-empty" colspan="7">{{ $t('No communications match these filters.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <pagination class="mt-2 print:hidden" :meta="logs.meta" :links="logs.links" />
    </div>

    <!-- the message exactly as it went out -->
    <modal :show="!!openLog" max-width="2xl" @close="closeMessage">
      <div v-if="openLog" class="dl-modal">
        <header class="dl-modal__head" :class="'is-' + openLog.channel">
          <span class="dl-modal__ico"><icons :name="chIcon(openLog.channel)" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h3>{{ channelName(openLog.channel) }} <span class="lg-chip" :class="statusClass(openLog.status)">{{ openLog.status }}</span></h3>
            <p>{{ eventLabel(openLog.event_key) }}</p>
          </div>
          <button type="button" class="dl-x" :aria-label="$t('Close')" @click="closeMessage"><icons name="cross" class="w-4 h-4" /></button>
        </header>

        <div v-if="loadingDetail" class="dl-modal__body"><p class="lg-subtext">{{ $t('Loading…') }}</p></div>
        <div v-else-if="detail" class="dl-modal__body">
          <dl class="dl-facts">
            <div><dt>{{ $t('To') }}</dt><dd>{{ detail.recipient || '—' }} <span class="lg-subtext">{{ detail.address }}</span></dd></div>
            <div v-if="detail.meta && detail.meta.from"><dt>{{ $t('From') }}</dt><dd>{{ detail.meta.from }}</dd></div>
            <div><dt>{{ $t('Sent') }}</dt><dd>{{ detail.sent_at ? $datetime(detail.sent_at) : (detail.created_at ? $datetime(detail.created_at) : '—') }}</dd></div>
            <div v-if="detail.delivered_at"><dt>{{ $t('Delivered') }}</dt><dd>{{ $datetime(detail.delivered_at) }}</dd></div>
            <div v-if="detail.retries"><dt>{{ $t('Retries') }}</dt><dd>{{ detail.retries }}</dd></div>
          </dl>
          <p v-if="detail.error" class="dl-error"><icons name="exclamation" class="w-4 h-4" />{{ detail.error }}</p>

          <template v-if="detail.channel === 'email'">
            <h4 class="dl-subject">{{ detail.subject || $t('(no subject)') }}</h4>
            <div v-if="detail.meta && detail.meta.attachments && detail.meta.attachments.length" class="dl-attach">
              <span v-for="a in detail.meta.attachments" :key="a" class="lg-chip lg-chip-slate" style="text-transform: none"><icons name="clip" class="w-3 h-3" />{{ a }}</span>
            </div>
            <iframe v-if="detail.body_html" class="dl-frame" sandbox="" :srcdoc="detail.body_html" :title="$t('Email')"></iframe>
            <pre v-else class="dl-text">{{ detail.message || $t('(no message content)') }}</pre>
          </template>
          <div v-else class="dl-bubble-wrap" :class="'is-' + detail.channel">
            <div class="dl-bubble">{{ detail.message || $t('(no message content)') }}</div>
          </div>

          <!-- send this message again by its own channel (SMS / WhatsApp / Meta through the gateway, email by the mail server, app alert in the bell) -->
          <div class="dl-resend">
            <p v-if="resendResult" class="dl-resend__msg" :class="resendResult.ok ? 'is-ok' : 'is-bad'" role="status"><icons :name="resendResult.ok ? 'tick' : 'exclamation'" class="w-4 h-4" />{{ resendResult.message }}</p>
            <p v-if="!resendResult && detail.channel === 'email' && detail.meta && detail.meta.attachments && detail.meta.attachments.length" class="lg-subtext">{{ $t('The files of this email are not kept, so they are not attached again.') }}</p>
            <div v-if="detail.resendable !== false && !(detail.meta && detail.meta.sensitive)" class="dl-resend__row">
              <template v-if="!confirmResend">
                <button type="button" class="lg-btn lg-btn-primary" :disabled="resending" @click="confirmResend = true"><icons name="refresh" class="w-4 h-4" />{{ resendLabel(detail.channel) }}</button>
              </template>
              <template v-else>
                <span class="dl-resend__ask">{{ $t('Send it again to :a?', { a: detail.address || detail.recipient || '' }) }}</span>
                <button type="button" class="lg-btn lg-btn-primary" :disabled="resending" @click="resend"><icons name="refresh" class="w-4 h-4" :class="{ 'animate-spin': resending }" />{{ resending ? $t('Sending…') : $t('Yes, send again') }}</button>
                <button type="button" class="lg-btn t-slate" :disabled="resending" @click="confirmResend = false">{{ $t('Cancel') }}</button>
              </template>
            </div>
            <p v-else class="lg-subtext">{{ $t('This message holds a code or a secret link, so it is never sent again.') }}</p>
          </div>
        </div>
      </div>
    </modal>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Pagination from '@/Shared/Pagination.vue';
import DropdownFilter from '@/Shared/WimsConnect/DropdownFilter.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import Modal from '@/Jetstream/Modal.vue';

const STATUS_COLORS = { SENT: '#15803d', DELIVERED: '#15803d', QUEUED: '#4338ca', SENDING: '#4338ca', RETRYING: '#92400e', FAILED: '#b91c1c' };

export default {
  components: { AdminLayout, Link, Pagination, DropdownFilter, DatePicker, PageHeader, Modal },

  props: {
    logs: Object,
    retryableCount: { type: Number, default: 0 },
    filters: Object,
    eventOptions: { type: Array, default: () => [] },
    eventLabels: { type: Object, default: () => ({}) },
    statuses: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    channelLive: { type: Object, default: () => ({}) },
  },

  data() {
    return {
      retryingAll: false,
      openLog: null,
      detail: null,
      loadingDetail: false,
      resending: false,
      confirmResend: false,
      resendResult: null,
      form: {
        channel: this.filters?.channel || '',
        status: this.filters?.status || '',
        event_key: this.filters?.event_key || '',
        recipient_user_id: this.filters?.recipient_user_id || '',
        date_from: this.filters?.date_from || '',
        date_to: this.filters?.date_to || '',
      },
    };
  },

  computed: {
    activeFilters() {
      const out = {};
      Object.entries(this.form).forEach(([k, v]) => { if (v) out[k] = v; });
      return out;
    },
    printUrl() {
      return this.route('wims-connect.logs.print', this.activeFilters);
    },
    downloadUrl() {
      return this.route('wims-connect.logs.download', this.activeFilters);
    },
    channelOptions() {
      return ['whatsapp', 'meta', 'sms', 'email', 'app'].map(c => ({
        value: c,
        label: this.channelName(c),
        icon: this.chIcon(c),
        live: c === 'app' ? true : !!this.channelLive[c],
      }));
    },
    statusOptions() {
      return this.statuses.map(s => ({ value: s, label: s, dotColor: STATUS_COLORS[s] || '#94a3b8' }));
    },
    eventFilterOptions() {
      return this.eventOptions;
    },
    recipientOptions() {
      return this.users.map(u => ({ value: String(u.id), label: u.name, icon: 'user' }));
    },
  },

  watch: {
    // Every filter auto-applies the instant it changes — a dropdown/date
    // pick is already a discrete, deliberate choice, so there's nothing to
    // debounce (unlike a free-text field, which this page doesn't have).
    'form.channel'() { this.applyFilters(); },
    'form.status'() { this.applyFilters(); },
    'form.event_key'() { this.applyFilters(); },
    'form.recipient_user_id'() { this.applyFilters(); },
    'form.date_from'() { this.applyFilters(); },
    'form.date_to'() { this.applyFilters(); },
  },

  methods: {
    channelName(c) {
      return { sms: this.$t('SMS'), email: this.$t('Email'), whatsapp: 'WhatsApp', meta: 'Meta', app: this.$t('App alert') }[c] || c;
    },
    chIcon(c) { return c === 'app' ? 'bell' : c; },
    resendLabel(c) { return { sms: this.$t('Resend SMS'), email: this.$t('Resend email'), whatsapp: this.$t('Resend WhatsApp'), meta: this.$t('Resend Meta message'), app: this.$t('Resend app alert') }[c] || this.$t('Resend'); },
    resend() {
      if (!this.openLog || this.resending) return;
      this.resending = true;
      axios.post(this.route('wims-connect.logs.resend', this.openLog.id))
        .then(res => { this.resendResult = { ok: true, message: res.data.message }; })
        .catch(err => { this.resendResult = { ok: false, message: (err.response && err.response.data && err.response.data.message) || this.$t('Could not send it again.') }; })
        .finally(() => {
          this.resending = false; this.confirmResend = false;
          // the new row (or the new status) shows in the list behind the modal
          this.$inertia.reload({ only: ['logs', 'retryableCount'], preserveScroll: true });
        });
    },
    // one line of the text that went out
    preview(log) {
      const t = (log.message || '').replace(/\s+/g, ' ').trim();
      return t || this.$t('(no message content)');
    },
    openMessage(log) {
      this.openLog = log;
      this.detail = null;
      this.resendResult = null;
      this.confirmResend = false;
      this.loadingDetail = true;
      axios.get(this.route('wims-connect.logs.show', log.id))
        .then(res => { this.detail = res.data; })
        .catch(() => { this.detail = { channel: log.channel, message: log.message, subject: log.subject, address: log.address, status: log.status }; })
        .finally(() => { this.loadingDetail = false; });
    },
    closeMessage() {
      this.openLog = null;
      this.detail = null;
      this.resendResult = null;
      this.confirmResend = false;
    },
    eventLabel(key) {
      return this.eventLabels?.[key] || key;
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

    applyFilters() {
      this.$inertia.get(this.route('wims-connect.logs.index'), this.form, { preserveState: true, replace: true });
    },

    clearFilters() {
      this.form = { channel: '', status: '', event_key: '', recipient_user_id: '', date_from: '', date_to: '' };
      this.$inertia.get(this.route('wims-connect.logs.index'), {}, { preserveState: true, replace: true });
    },

    retry(log) {
      this.$inertia.post(this.route('wims-connect.logs.retry', log.id), {}, { preserveScroll: true });
    },

    retryAll() {
      this.retryingAll = true;
      this.$inertia.post(this.route('wims-connect.logs.retry-all'), this.activeFilters, {
        preserveScroll: true,
        onFinish: () => { this.retryingAll = false; },
      });
    },
  },
};
</script>

<style scoped>
.ca-btn-amber { color: #fff; background: linear-gradient(145deg, #f59e0b, #b45309); box-shadow: 0 10px 18px -10px rgba(180, 83, 9, 0.9); }
.ca-msg-tip::after { white-space: pre-line; min-width: 200px; max-width: 320px; }
/* DatePicker.vue's own root div hardcodes col-span-6 sm:col-span-4 (sized for a 6-column form grid elsewhere);
   inside the filter grid that would force each date field onto its own row. */
.ca-filters :deep(.col-span-6.sm\:col-span-4) { grid-column: span 1 / span 1 !important; }
@media (max-width: 767.98px) { .ca-msg-tip { max-width: 100%; } }

.dl-msg-td { max-width: 380px; }
.dl-msg { display: flex; align-items: center; gap: 0.6rem; width: 100%; text-align: left; border: 0; background: transparent; padding: 0.15rem 0; cursor: pointer; }
.dl-msg:hover .dl-msg__t span { text-decoration: underline; }
.dl-msg__ico { flex: none; display: grid; place-items: center; width: 34px; height: 34px; border-radius: 11px; color: #fff; background: #64748b; }
.dl-msg.is-sms .dl-msg__ico { background: linear-gradient(145deg, #3b82f6, #1d4ed8); } .dl-msg.is-email .dl-msg__ico { background: linear-gradient(145deg, #f59e0b, #d97706); } .dl-msg.is-whatsapp .dl-msg__ico { background: linear-gradient(145deg, #22c55e, #15803d); } .dl-msg.is-meta .dl-msg__ico { background: linear-gradient(145deg, #6366f1, #4338ca); } .dl-msg.is-app .dl-msg__ico { background: linear-gradient(145deg, #0ea5e9, #0369a1); }
.dl-msg__t { display: grid; min-width: 0; line-height: 1.3; } .dl-msg__t b { font-size: 0.82rem; color: var(--lg-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .dl-msg__t span { font-size: 0.78rem; color: var(--lg-mute); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
/* The modal is teleported out of .lg-page, so it must carry its own colour tokens (otherwise dark mode gave light text on a white panel). */
.dl-modal { --lg-card: #ffffff; --lg-line: #e6ebf3; --lg-soft: #f6f8fc; --lg-ink: #0f172a; --lg-mute: #64748b; --lg-faint: #94a3b8; color: var(--lg-ink); background: var(--lg-card); border-radius: 0.5rem; overflow: hidden; }
html.dark .dl-error { color: #fda4af; }
html.dark .dl-modal { --lg-card: #1e293b; --lg-line: rgba(255, 255, 255, 0.1); --lg-soft: rgba(255, 255, 255, 0.05); --lg-ink: #f1f5f9; --lg-mute: #94a3b8; --lg-faint: #64748b; }
.dl-modal__head { display: flex; align-items: center; gap: 0.8rem; padding: 0.9rem 1.1rem; color: #fff; background: #475569; } .dl-modal__head h3 { margin: 0; font-size: 1rem; font-weight: 800; display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; } .dl-modal__head p { margin: 0.1rem 0 0; font-size: 0.8rem; opacity: 0.9; }
.dl-modal__head.is-sms { background: linear-gradient(135deg, #2563eb, #1e3a8a); } .dl-modal__head.is-email { background: linear-gradient(135deg, #f59e0b, #b45309); } .dl-modal__head.is-whatsapp { background: linear-gradient(135deg, #16a34a, #166534); } .dl-modal__head.is-meta { background: linear-gradient(135deg, #6366f1, #3730a3); } .dl-modal__head.is-app { background: linear-gradient(135deg, #0ea5e9, #075985); }
.dl-modal__ico { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 12px; background: rgba(255, 255, 255, 0.2); } .dl-x { border: 0; background: rgba(255, 255, 255, 0.18); color: #fff; width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center; cursor: pointer; }
.dl-modal__body { padding: 1rem 1.1rem 1.2rem; display: grid; gap: 0.8rem; max-height: 75vh; overflow-y: auto; }
.dl-facts { display: grid; gap: 0.3rem; margin: 0; font-size: 0.84rem; } .dl-facts div { display: flex; gap: 0.7rem; } .dl-facts dt { flex: none; width: 5.5rem; font-weight: 800; font-size: 0.72rem; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); padding-top: 2px; } .dl-facts dd { margin: 0; min-width: 0; overflow-wrap: anywhere; }
.dl-error { display: flex; gap: 0.4rem; align-items: flex-start; margin: 0; padding: 0.55rem 0.8rem; border-radius: 10px; font-size: 0.8rem; color: #9f1239; background: rgba(244, 63, 94, 0.1); }
.dl-subject { margin: 0.2rem 0 0; font-size: 1rem; font-weight: 800; } .dl-attach { display: flex; flex-wrap: wrap; gap: 0.4rem; }
.dl-frame { width: 100%; height: 420px; border: 1px solid var(--lg-line); border-radius: 10px; background: #fff; }
.dl-text { white-space: pre-wrap; font-family: inherit; font-size: 0.86rem; margin: 0; padding: 0.8rem; border-radius: 10px; background: rgba(100, 116, 139, 0.08); }
.dl-bubble-wrap { padding: 1rem; border-radius: 14px; background: #e2e8f0; } .dl-bubble-wrap.is-whatsapp { background: #ece5dd; } .dl-bubble-wrap.is-meta { background: #e0e7ff; }
.dl-bubble { max-width: 90%; width: fit-content; white-space: pre-wrap; overflow-wrap: anywhere; font-size: 0.9rem; line-height: 1.5; padding: 0.7rem 0.9rem; border-radius: 16px 16px 16px 4px; background: #fff; color: #0f172a; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.18); }
.dl-bubble-wrap.is-whatsapp .dl-bubble { background: #dcf8c6; }
.dl-bubble-wrap.is-app { background: #e0f2fe; }
.dl-resend { display: grid; gap: 0.6rem; padding-top: 0.8rem; border-top: 1px solid var(--lg-line); }
.dl-resend__row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.6rem; } .dl-resend__ask { font-size: 0.85rem; font-weight: 700; overflow-wrap: anywhere; }
.dl-resend__msg { display: flex; gap: 0.4rem; align-items: flex-start; margin: 0; padding: 0.55rem 0.8rem; border-radius: 10px; font-size: 0.82rem; font-weight: 600; }
.dl-resend__msg.is-ok { color: #166534; background: rgba(34, 197, 94, 0.14); } .dl-resend__msg.is-bad { color: #9f1239; background: rgba(244, 63, 94, 0.1); }
html.dark .dl-resend__msg.is-ok { color: #86efac; } html.dark .dl-resend__msg.is-bad { color: #fda4af; }
html.dark .dl-bubble-wrap { background: #1e293b; } html.dark .dl-bubble { background: #334155; color: #f1f5f9; }
</style>

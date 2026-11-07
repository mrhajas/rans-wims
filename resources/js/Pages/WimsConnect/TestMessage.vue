<template>
  <admin-layout :title="$t('Test Email / SMS')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="send" :title="$t('Test Email / SMS')" :subtitle="$t('Send a short test to one person to check that email or SMS reaches them.')">
        <Link :href="route('wims-connect.logs.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('Delivery Logs') }}</Link>
      </page-header>

      <div class="tm-grid">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="user" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('1. Who gets the test?') }}</h2>
              <p class="lg-card-sub">{{ $t('Search a user by name or username. WIMS checks which address it holds for them.') }}</p>
            </div>
          </header>
          <auto-complete id="tm_user" v-model="userId" :suggestions="userOptions" value-key="id" label-key="label" :placeholder="$t('Choose a user')" searchable clearable />

          <template v-if="person">
            <h3 class="tm-step">{{ $t('2. Choose how to send it') }}</h3>
            <div class="tm-channels" role="radiogroup">
              <button v-for="c in channelCards" :key="c.key" type="button" role="radio" :aria-checked="channel === c.key" class="tm-ch" :class="{ 'is-on': channel === c.key, 'is-off': !c.ready }" :disabled="!c.ready" @click="pick(c.key)">
                <span class="tm-ch-ico"><icons :name="c.icon" class="w-5 h-5" /></span>
                <span class="tm-ch-main">
                  <b>{{ c.label }}</b>
                  <span v-if="c.target" class="tm-ok"><icons name="tick" class="w-3.5 h-3.5" />{{ c.target.address }}</span>
                  <span v-else class="tm-no"><icons name="exclamation" class="w-3.5 h-3.5" />{{ c.missing }}</span>
                  <small v-if="c.target">{{ $t('From :source', { source: c.target.source }) }}</small>
                  <small v-if="c.note" class="tm-note">{{ c.note }}</small>
                </span>
              </button>
            </div>
            <p v-if="!person.email && !person.sms" class="tm-hint">{{ $t('This person has neither an email nor a mobile number on record. Add them on the user, or use Sync with staff on the user page.') }} <Link :href="route('users.edit', person.id)" class="tm-link">{{ $t('Open the user') }}</Link></p>

            <template v-if="channel">
              <h3 class="tm-step">{{ $t('3. Message') }}</h3>
              <textarea v-model="message" class="tm-input" rows="3" maxlength="300" :disabled="busy"></textarea>
              <div class="tm-meta"><span>{{ message.length }} / 300</span><span v-if="channel === 'sms'">{{ $t('A normal SMS holds 160 characters.') }}</span></div>

              <div class="tm-actions">
                <button type="button" class="lg-btn lg-btn-primary" :disabled="busy || !message.trim()" @click="send"><icons name="send" class="w-4 h-4" />{{ busy ? $t('Sending...') : channel === 'sms' ? $t('Send test SMS') : $t('Send test email') }}</button>
              </div>
            </template>
          </template>

          <p v-if="result" class="tm-result" :class="result.success ? 'is-ok' : 'is-bad'" role="status"><icons :name="result.success ? 'tick' : 'exclamation'" class="w-4 h-4 flex-none" />{{ result.message }}</p>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="clock" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Recent tests') }}</h2>
              <p class="lg-card-sub">{{ $t('Every test is also in the Delivery Logs.') }}</p>
            </div>
          </header>
          <p v-if="!recent.length" class="lg-empty">{{ $t('No tests sent yet.') }}</p>
          <ul v-else class="tm-recent">
            <li v-for="r in recent" :key="r.id">
              <span class="lg-chip" :class="r.status === 'FAILED' ? 'lg-chip-red' : 'lg-chip-green'">{{ r.status === 'FAILED' ? $t('Failed') : $t('Sent') }}</span>
              <div class="min-w-0">
                <b>{{ r.to || '-' }}</b> <small>{{ r.channel === 'sms' ? 'SMS' : $t('Email') }} &middot; {{ r.address }}</small>
                <small class="tm-when">{{ $datetime(r.at) }}</small>
                <small v-if="r.error" class="tm-err">{{ r.error }}</small>
              </div>
            </li>
          </ul>
        </section>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';

// WIMS Connect > Test Email / SMS: pick a person, WIMS shows the email and the number it holds for them (and where it got them), pick Email or SMS, send.
export default {
  components: { AdminLayout, PageHeader, AutoComplete, Link },
  props: { users: Array, defaults: Object, channels: Object, recent: { type: Array, default: () => [] } },
  data() {
    return { userId: null, channel: null, message: '', busy: false, result: null, edited: false };
  },
  computed: {
    userOptions() {
      return this.users.map(u => ({ id: u.id, label: u.label + (u.email || u.sms ? '' : ' - ' + this.$t('no email or number')) }));
    },
    person() {
      return this.users.find(u => u.id === this.userId) || null;
    },
    channelCards() {
      const p = this.person;
      if (!p) return [];
      return [
        { key: 'email', icon: 'email', label: this.$t('Email'), target: p.email, missing: this.$t('No email address on record'), note: this.channels.email.note, ready: !!p.email && this.channels.email.on },
        { key: 'sms', icon: 'sms', label: this.$t('SMS'), target: p.sms, missing: this.$t('No mobile number on record'), note: this.channels.sms.note, ready: !!p.sms && this.channels.sms.on },
      ];
    },
  },
  watch: {
    // another person: the channel is chosen again (the one that works is picked for you when only one does)
    userId() {
      this.result = null;
      const ready = this.channelCards.filter(c => c.ready);
      this.channel = ready.length ? ready[0].key : null;
      this.edited = false;
      this.message = this.channel ? this.defaults[this.channel] : '';
    },
  },
  methods: {
    pick(key) {
      this.channel = key;
      this.result = null;
      if (!this.edited) this.message = this.defaults[key];
    },
    send() {
      this.busy = true;
      this.result = null;
      axios
        .post(this.route('wims-connect.test.send'), { user_id: this.userId, channel: this.channel, message: this.message })
        .then(res => (this.result = res.data))
        .catch(err => (this.result = { success: false, message: (err.response && err.response.data && err.response.data.message) || this.$t('Something went wrong. Please try again.') }))
        .finally(() => {
          this.busy = false;
          this.$inertia.reload({ only: ['recent'], preserveScroll: true, preserveState: true });
        });
    },
  },
};
</script>

<style scoped>
.tm-grid { display: grid; gap: 1rem; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); align-items: start; }
@media (max-width: 900px) { .tm-grid { grid-template-columns: minmax(0, 1fr); } }
.tm-step { margin: 18px 0 8px; font-size: 0.76rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute, #64748b); }
.tm-channels { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
.tm-ch { display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; text-align: left; border-radius: 16px; background: var(--lg-soft, #f8fafc); box-shadow: inset 0 0 0 1px var(--lg-line, #e2e8f0); transition: box-shadow 0.15s ease, background 0.15s ease; }
.tm-ch.is-on { background: rgba(79, 109, 245, 0.08); box-shadow: inset 0 0 0 2px rgba(79, 109, 245, 0.6); }
.tm-ch.is-off { cursor: not-allowed; opacity: 0.75; }
.tm-ch-ico { display: grid; place-items: center; flex: none; width: 38px; height: 38px; border-radius: 12px; color: #fff; background: linear-gradient(145deg, #7aa2ff, #4f6df5); }
.tm-ch.is-off .tm-ch-ico { background: #94a3b8; }
.tm-ch-main { display: grid; gap: 2px; min-width: 0; font-size: 0.82rem; }
.tm-ch-main b { font-size: 0.92rem; color: var(--lg-ink, #0f172a); }
.tm-ch-main small { font-size: 0.72rem; color: var(--lg-mute, #64748b); }
.tm-ok, .tm-no { display: inline-flex; align-items: center; gap: 5px; font-weight: 700; overflow-wrap: anywhere; }
.tm-ok { color: #047857; }
.tm-no { color: #b45309; }
.tm-note { color: #b45309 !important; }
.tm-hint { margin: 10px 0 0; font-size: 0.8rem; color: var(--lg-mute, #64748b); }
.tm-link { color: #2563eb; font-weight: 700; }
.tm-input { width: 100%; padding: 10px 12px; font-size: 0.9rem; border-radius: 14px; resize: vertical; border: 1px solid var(--lg-line, #cbd5e1); background: var(--lg-card, #fff); color: var(--lg-ink, #0f172a); }
.tm-input:focus { outline: none; border-color: #93a8ff; box-shadow: 0 0 0 3px rgba(79, 109, 245, 0.2); }
.tm-meta { display: flex; justify-content: space-between; gap: 8px; margin-top: 4px; font-size: 0.72rem; color: var(--lg-faint, #94a3b8); }
.tm-actions { display: flex; justify-content: flex-end; margin-top: 12px; }
.tm-result { display: flex; align-items: flex-start; gap: 8px; margin: 14px 0 0; padding: 10px 12px; border-radius: 12px; font-size: 0.84rem; overflow-wrap: anywhere; }
.tm-result.is-ok { color: #047857; background: rgba(16, 185, 129, 0.12); }
.tm-result.is-bad { color: #b91c1c; background: rgba(239, 68, 68, 0.1); }
.tm-recent { display: grid; gap: 10px; margin: 0; padding: 0; list-style: none; }
.tm-recent li { display: flex; align-items: flex-start; gap: 10px; font-size: 0.84rem; }
.tm-recent small { display: block; font-size: 0.72rem; color: var(--lg-mute, #64748b); overflow-wrap: anywhere; }
.tm-when { color: var(--lg-faint, #94a3b8) !important; }
.tm-err { color: #b91c1c !important; }
html.dark .tm-ok { color: #6ee7b7; }
html.dark .tm-result.is-ok { color: #6ee7b7; }
html.dark .tm-result.is-bad, html.dark .tm-err { color: #fca5a5; }
</style>

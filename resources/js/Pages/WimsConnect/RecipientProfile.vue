<template>
  <admin-layout :title="wcUser.name">
    <div class="lg-page px-4 md:px-0 ca-page">
      <page-header icon="users" :title="wcUser.name" :subtitle="wcUser.email">
        <span v-for="(r, i) in roles" :key="i" class="lg-chip lg-chip-indigo" style="text-transform: none">{{ r }}</span>
        <Link :href="route('wims-connect.recipients.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-3.5 h-3.5 transform rotate-90" />
          {{ $t('Recipients') }}
        </Link>
      </page-header>

      <section class="lg-card ca-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="send" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Contact details') }}</h2>
            <p class="lg-card-sub">{{ $t('Where this person receives alerts. Every module\'s workflow page sends to these details: a channel receives messages only when it is switched on and has an address here.') }}</p>
          </div>
        </header>

        <div class="ca-channels">
          <div v-for="ch in channelDefs" :key="ch.key" class="ca-channel-row" :class="{ 'is-off': !channelForm[ch.key].is_enabled }">
            <div class="ca-channel-head">
              <span class="ca-channel-ico" :class="'is-' + ch.key"><icons :name="ch.icon" class="w-4 h-4" /></span>
              <label class="ca-channel-name"><input type="checkbox" v-model="channelForm[ch.key].is_enabled" class="ca-checkbox" />{{ ch.label }}</label>
              <span class="ca-live" :class="ch.live ? 'is-live' : 'is-dead'" :title="ch.live ? $t('Channel is live') : $t('Channel is off or not connected')"><i></i>{{ ch.live ? $t('Live') : $t('Not connected') }}</span>
              <span v-if="channelForm[ch.key].is_verified" class="lg-chip lg-chip-green">{{ $t('Verified') }}</span>
              <span v-if="channelForm[ch.key].pulled && channelForm[ch.key].address" class="lg-chip lg-chip-slate" style="text-transform: none">{{ $t('Pulled from the user records') }}</span>
            </div>
            <input v-model="channelForm[ch.key].address" :type="ch.key === 'email' ? 'email' : 'text'" :placeholder="ch.placeholder" class="ca-input w-full" />
            <p v-if="$page.props.errors['channels.' + ch.key + '.address']" class="ca-err">{{ $page.props.errors['channels.' + ch.key + '.address'] }}</p>
          </div>
        </div>

        <button type="button" class="lg-btn lg-btn-green mt-4" :disabled="savingChannels" @click="saveChannels">
          <icons name="tick" class="w-4 h-4" />
          {{ savingChannels ? $t('Saving…') : $t('Save contact details') }}
        </button>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, Link, PageHeader },

  props: {
    wcUser: Object,
    roles: { type: Array, default: () => [] },
    channels: Object,
    channelLive: { type: Object, default: () => ({}) },
  },

  data() {
    const channelForm = {};
    ['sms', 'email', 'whatsapp', 'meta'].forEach(key => {
      const c = (this.channels || {})[key] || {};
      channelForm[key] = { address: c.address || '', is_enabled: !!c.is_enabled, is_verified: !!c.is_verified, pulled: !!c.pulled };
    });

    return { channelForm, savingChannels: false };
  },

  computed: {
    channelDefs() {
      return [
        { key: 'sms', label: this.$t('Mobile (SMS)'), icon: 'sms', placeholder: '0674020223 or +255674020223', live: !!this.channelLive.sms },
        { key: 'email', label: this.$t('Email'), icon: 'email', placeholder: 'name@company.com', live: !!this.channelLive.email },
        { key: 'whatsapp', label: this.$t('WhatsApp'), icon: 'whatsapp', placeholder: '0674020223 or +255674020223', live: !!this.channelLive.whatsapp },
        { key: 'meta', label: this.$t('Meta'), icon: 'meta', placeholder: '+255XXXXXXXXX', live: !!this.channelLive.meta },
      ];
    },
  },

  methods: {
    saveChannels() {
      this.savingChannels = true;
      this.$inertia.put(this.route('wims-connect.recipients.channels.update', this.wcUser.id), { channels: this.channelForm }, {
        preserveScroll: true,
        onFinish: () => { this.savingChannels = false; },
      });
    },
  },
};
</script>

<style scoped>
.ca-card { max-width: 760px; margin-inline: auto; }
.ca-channel-row { padding: 0.8rem 0; border-bottom: 1px solid var(--lg-line); } .ca-channel-row:last-child { border-bottom: none; } .ca-channel-row.is-off { opacity: 0.6; }
.ca-channel-head { display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.45rem; flex-wrap: wrap; }
.ca-channel-ico { display: grid; place-items: center; width: 28px; height: 28px; border-radius: 9px; color: #fff; background: #64748b; } .ca-channel-ico.is-sms { background: linear-gradient(145deg, #3b82f6, #1d4ed8); } .ca-channel-ico.is-email { background: linear-gradient(145deg, #f59e0b, #d97706); } .ca-channel-ico.is-whatsapp { background: linear-gradient(145deg, #22c55e, #15803d); } .ca-channel-ico.is-meta { background: linear-gradient(145deg, #6366f1, #4338ca); }
.ca-channel-name { display: flex; align-items: center; gap: 0.5rem; font-weight: 800; font-size: 0.9rem; color: var(--lg-ink); }
.ca-live { margin-left: auto; display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.72rem; font-weight: 700; color: var(--lg-mute); } .ca-live i { width: 7px; height: 7px; border-radius: 50%; background: #cbd5e1; } .ca-live.is-live i { background: #22c55e; }
.ca-checkbox { height: 1rem; width: 1rem; border-radius: 0.3rem; border-color: #cbd5e1; color: #34d399; }
.ca-input { border: 1px solid #e2e8f0; border-radius: 0.85rem; padding: 0.55rem 0.8rem; font-size: 0.86rem; background: #fff; } .ca-input:focus { outline: none; border-color: #34d399; box-shadow: 0 0 0 3px rgba(52, 211, 153, 0.18); }
html.dark .ca-input { background: #0f172a; border-color: rgba(255, 255, 255, 0.12); color: #e2e8f0; }
.ca-err { margin: 0.35rem 0 0; font-size: 0.78rem; font-weight: 700; color: #be123c; }
</style>

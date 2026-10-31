<template>
  <section class="lg-card">
    <header class="lg-card-head">
      <span class="lg-card-ico lg-ico-indigo"><icons name="bell" class="w-5 h-5" /></span>
      <div class="min-w-0">
        <h2 class="lg-card-title">{{ $t('Toast Alerts') }}</h2>
        <p class="lg-card-sub">{{ $t('A small pop-up under the top bar when a new alert arrives. Click it to open the record. Shown on desktop and tablet only.') }}</p>
      </div>
    </header>

    <form @submit.prevent="save">
      <div class="pf-toggle-row">
        <label class="wims-toggle">
          <input v-model="enabled" type="checkbox" />
          <span class="wims-toggle-track"><span class="wims-toggle-thumb"></span></span>
          <span class="wims-toggle-label">{{ enabled ? $t('Show a pop-up for new alerts') : $t('Do not show pop-ups') }}</span>
        </label>
        <span class="lg-chip" :class="enabled ? 'lg-chip-green' : 'lg-chip-slate'">{{ enabled ? $t('On') : $t('Off') }}</span>
      </div>

      <div v-if="enabled" class="ta-grid">
        <div class="pf-field">
          <label class="lg-label" for="ta-seconds">{{ $t('Stays on screen') }}</label>
          <acct-select id="ta-seconds" v-model="seconds" :options="secondChoices" />
        </div>
        <div class="pf-field">
          <label class="lg-label" for="ta-max">{{ $t('Pop-ups at one time') }}</label>
          <acct-select id="ta-max" v-model="max" :options="maxChoices" />
        </div>
      </div>

      <p v-if="enabled" class="lg-sub" style="margin-top: 10px">
        {{ $t('When more alerts arrive than fit, the next ones appear as the first ones leave. Press Escape or click anywhere else to close them at once.') }}
      </p>

      <div class="pf-foot">
        <tec-action-message :on="saved" class="text-sm font-semibold text-emerald-600">{{ $t('Saved.') }}</tec-action-message>
        <button type="submit" class="lg-btn lg-btn-primary" :disabled="saving">
          <icons :name="saving ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': saving }" />{{ $t('Save') }}
        </button>
      </div>
    </form>
  </section>
</template>

<script>
import TecActionMessage from '@/Jetstream/ActionMessage.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import { toastSettings, TOAST_DEFAULTS } from '@/Core/alertToasts';

export default {
  components: { TecActionMessage, AcctSelect },

  data() {
    const s = toastSettings(this.$page.props.auth.user);
    return { enabled: s.enabled, seconds: s.seconds, max: s.max, saving: false, saved: false };
  },

  computed: {
    secondChoices() {
      return [2, 3, 4, 5, 6, 8, 10, 15, 20, 30].map(n => ({ value: n, label: n + ' ' + this.$t('seconds') + (n === TOAST_DEFAULTS.seconds ? ' (' + this.$t('default') + ')' : '') }));
    },
    maxChoices() {
      return [1, 2, 3, 4, 5].map(n => ({ value: n, label: String(n) + (n === TOAST_DEFAULTS.max ? ' (' + this.$t('default') + ')' : '') }));
    },
  },

  methods: {
    save() {
      this.saving = true;
      this.saved = false;
      window.axios
        .put(this.route('notification-preferences.toasts'), { enabled: this.enabled, seconds: Number(this.seconds), max: Number(this.max) })
        .then(res => {
          // the page's own copy of the person, so the new settings count from the next alert on (no reload)
          const u = this.$page.props.auth.user;
          u.extra_attributes = { ...(u.extra_attributes || {}), toast_alerts: res.data };
          this.saved = true;
        })
        .finally(() => {
          this.saving = false;
        });
    },
  },
};
</script>

<style>
.ta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; max-width: 560px; margin-top: 12px; }
</style>

<template>
  <section class="lg-card">
    <header class="lg-card-head">
      <span class="lg-card-ico lg-ico-rose"><icons name="clock" class="w-5 h-5" /></span>
      <div class="min-w-0">
        <h2 class="lg-card-title">{{ $t('Auto logout') }}</h2>
        <p class="lg-card-sub">{{ $t('Sign me out automatically when I have not used WIMS for a while. Idle time is counted on your whole login session: using WIMS in any tab keeps you signed in, and a tab that is only open, hidden or refreshing by itself does not.') }}</p>
      </div>
    </header>

    <form @submit.prevent="save">
      <div class="pf-toggle-row">
        <label class="wims-toggle">
          <input v-model="enabled" type="checkbox" />
          <span class="wims-toggle-track"><span class="wims-toggle-thumb"></span></span>
          <span class="wims-toggle-label">{{ enabled ? $t('Sign me out after a period of no activity') : $t('Do not log me out') }}</span>
        </label>
        <span class="lg-chip" :class="enabled ? 'lg-chip-green' : 'lg-chip-amber'">{{ enabled ? $t('On') : $t('Off') }}</span>
      </div>

      <div v-if="enabled" class="pf-field" style="margin-top: 10px; max-width: 320px">
        <label class="lg-label" for="idle-minutes">{{ $t('Sign me out after') }}</label>
        <!-- a searchable list: type to find a time (for example "2 hours" or "30") -->
        <acct-select id="idle-minutes" v-model="minutes" :options="choices" :placeholder="$t('Type to search a time...')" />
      </div>

      <p class="lg-sub" style="margin-top: 10px">
        <template v-if="enabled">{{ $t('A warning with a countdown of :s seconds appears first; any click or key press in WIMS (in any tab) keeps you signed in.', { s: 30 }) }}</template>
        <template v-else>{{ $t('Your session stays open until you sign out yourself or the system signs you out for another reason. Use this only on a device that nobody else can reach.') }}</template>
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

export default {
  components: { TecActionMessage, AcctSelect },

  data() {
    const idle = this.$page.props.idle || { minutes: 30, options: [30], default: 30 };
    return { enabled: idle.minutes > 0, minutes: idle.minutes > 0 ? idle.minutes : idle.default, options: idle.options, def: idle.default, saving: false, saved: false };
  },

  computed: {
    // the same times as before, as { value, label } for the searchable list
    choices() {
      return this.options.map(m => ({ value: m, label: this.label(m) + (m === this.def ? ' (' + this.$t('default') + ')' : '') }));
    },
  },

  methods: {
    label(m) {
      if (m < 60) return this.$t(':n minutes', { n: m });
      const h = m / 60;
      return h === 1 ? this.$t('1 hour') : this.$t(':n hours', { n: h });
    },

    save() {
      this.saving = true;
      this.saved = false;
      window.axios
        .put(this.route('idle-timeout.update'), { minutes: this.enabled ? Number(this.minutes) : 0 })
        .then(() => {
          this.saved = true;
          this.$inertia.reload({ only: ['idle'], preserveScroll: true });
        })
        .finally(() => {
          this.saving = false;
        });
    },
  },
};
</script>

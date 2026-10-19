<template>
  <section class="lg-card">
    <header class="lg-card-head">
      <span class="lg-card-ico lg-ico-amber"><icons name="bell" class="w-5 h-5" /></span>
      <div class="min-w-0">
        <h2 class="lg-card-title">{{ $t('Notification Sound') }}</h2>
        <p class="lg-card-sub">
          {{ $t('Choose whether WIMS plays a chime sound when a new notification arrives.') }}
        </p>
      </div>
    </header>

    <form @submit.prevent="save">
      <div class="pf-toggle-row">
        <label class="wims-toggle">
          <input type="checkbox" v-model="chimeEnabled" />
          <span class="wims-toggle-track"><span class="wims-toggle-thumb"></span></span>
          <span class="wims-toggle-label">{{ $t('Play a chime for new notifications') }}</span>
        </label>
        <span class="lg-chip" :class="chimeEnabled ? 'lg-chip-green' : 'lg-chip-slate'">{{ chimeEnabled ? $t('On') : $t('Off') }}</span>
      </div>

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

export default {
  components: { TecActionMessage },

  data() {
    return {
      chimeEnabled: this.$page.props.auth.user.chime_enabled !== false,
      saving: false,
      saved: false,
    };
  },

  methods: {
    save() {
      this.saving = true;
      this.saved = false;
      window.axios
        .put(this.route('notification-preferences.update'), { chime_enabled: this.chimeEnabled })
        .then(res => {
          this.chimeEnabled = res.data.chime_enabled;
          this.saved = true;
        })
        .finally(() => {
          this.saving = false;
        });
    },
  },
};
</script>

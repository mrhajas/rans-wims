<template>
  <section class="lg-card">
    <header class="lg-card-head">
      <span class="lg-card-ico lg-ico-amber"><icons name="clock" class="w-5 h-5" /></span>
      <div class="min-w-0">
        <h2 class="lg-card-title">{{ $t('Task Reminders') }}</h2>
        <p class="lg-card-sub">
          {{ $t("Choose whether we also email you at :email when one of your task reminders is due, in addition to the in-app notification.", { email: $page.props.auth.user.email }) }}
        </p>
      </div>
    </header>

    <form @submit.prevent="save">
      <div class="pf-toggle-row">
        <label class="wims-toggle">
          <input type="checkbox" v-model="emailEnabled" />
          <span class="wims-toggle-track"><span class="wims-toggle-thumb"></span></span>
          <span class="wims-toggle-label">{{ $t('Email me when a task reminder is due') }}</span>
        </label>
        <span class="lg-chip" :class="emailEnabled ? 'lg-chip-green' : 'lg-chip-slate'">{{ emailEnabled ? $t('On') : $t('Off') }}</span>
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
      emailEnabled: !!this.$page.props.auth.user.task_reminders_email_enabled,
      saving: false,
      saved: false,
    };
  },

  methods: {
    save() {
      this.saving = true;
      this.saved = false;
      window.axios
        .put(this.route('task-reminders.preferences.update'), { email_enabled: this.emailEnabled })
        .then(res => {
          this.emailEnabled = res.data.email_enabled;
          this.saved = true;
        })
        .finally(() => {
          this.saving = false;
        });
    },
  },
};
</script>

<template>
  <admin-layout :title="$t('Transport Routes Settings')">
    <div class="lg-page px-4 md:px-0 set-wrap">
      <page-header icon="truck" :title="$t('Transport Routes Settings')" />

      <form @submit.prevent="submit">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Default Transport Pricing') }}</h2>
            </div>
          </header>

          <div class="max-w-sm">
            <label class="set-field-label">{{ $t('Default Transport Price (TZS)') }}</label>
            <input v-model="form.transport_default_price" type="number" min="0" step="1" class="set-input w-full text-sm" />
            <p class="set-help mt-2">{{ $t("Auto-filled on every new Transport Routes Record, can still be changed per trip with a reason.") }}</p>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="users" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Loading & Unloading') }}</h2>
            </div>
          </header>

          <div class="max-w-sm">
            <label class="set-field-label">{{ $t('Daily rate per worker (TZS)') }}</label>
            <input v-model="form.transport_loading_daily_rate" type="number" min="0" step="1" class="set-input w-full text-sm" />
            <p class="set-help mt-2">{{ $t('Earned by each loading / unloading worker for every day marked Present. Days already recorded keep the rate they were saved with.') }}</p>
          </div>
        </section>

        <div class="lg-actionbar">
          <span v-if="saved" class="lg-chip lg-chip-green" style="text-transform: none"><icons name="tick" class="w-3 h-3 inline-flex" /> {{ $t('Saved.') }}</span>
          <div v-else></div>
          <div class="lg-actionbar-btns">
            <button type="submit" class="lg-btn lg-btn-green" :disabled="processing">
              <icons :name="processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': processing }" />
              {{ processing ? $t('Saving…') : $t('Save Settings') }}
            </button>
          </div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, PageHeader },
  props: { transport_settings: Object },
  data() {
    return { form: { ...this.transport_settings }, processing: false, saved: false };
  },
  methods: {
    submit() {
      this.processing = true;
      this.saved = false;
      this.$inertia.patch(this.route('transport-settings.update'), this.form, {
        preserveScroll: true,
        onSuccess: () => (this.saved = true),
        onFinish: () => (this.processing = false),
      });
    },
  },
};
</script>

<style scoped>
.set-wrap { max-width: 48rem; }
.set-help { font-size: 0.78rem; color: var(--lg-mute); max-width: 32rem; }
.set-field-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-mute); display: block; margin-bottom: 0.3rem; }
.set-input {
  border: 1px solid var(--lg-line); border-radius: 0.85rem; background: var(--lg-card); color: var(--lg-ink);
  padding: 0.55rem 0.8rem; transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.set-input:focus { outline: none; border-color: #38bdf8; box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2); }
</style>

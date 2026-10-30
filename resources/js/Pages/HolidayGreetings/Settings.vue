<template>
  <admin-layout :title="$t('Holiday Greeting Settings')">
    <div class="lg-page ac sc-ui tr-ui hg-ui px-4 md:px-0">
      <page-header icon="adjustments" :title="$t('Holiday Greeting Settings')" :subtitle="$t('When you are reminded, which holidays count, and how the greetings are signed.')">
        <Link :href="route('holiday-greetings.dashboard')" class="lg-btn tone" style="--tone: #475569"><span class="tone-ico"><icons name="calendar" class="w-4 h-4" /></span>{{ $t('Holidays') }}</Link>
      </page-header>

      <form @submit.prevent="save">
        <div class="hg-two">
          <section class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('The reminder') }}</h2><p class="lg-card-sub">{{ $t('The Super Admins are told before each holiday so they can review and send.') }}</p></div></header>
            <div class="hg-field"><label>{{ $t('Hours before the holiday begins') }}</label><input v-model.number="form.hours_before" type="number" min="1" max="72" /><span class="hg-hint">{{ $t('A holiday begins at midnight. With 8 hours, the reminder for a Friday holiday comes on Thursday at 16:00.') }}</span><span class="ac-err">{{ form.errors.hours_before }}</span></div>
            <label class="hg-check"><input v-model="form.skip_weekends" type="checkbox" /> {{ $t('A holiday on a Saturday or Sunday does not count') }}</label>
            <p class="hg-hint" style="margin: 4px 0 14px 26px">{{ $t('It stays on the list, with no reminder and no greetings, unless you count it on its own page.') }}</p>
            <div class="hg-field"><label>{{ $t('Remind through') }}</label>
              <label class="hg-check"><input v-model="form.remind_email" type="checkbox" /> {{ $t('Email') }}</label>
              <label class="hg-check"><input v-model="form.remind_sms" type="checkbox" /> {{ $t('SMS') }} <span v-if="!smsReady" class="lg-chip lg-chip-amber" style="text-transform: none">{{ $t('SMS is switched off in WIMS Connect') }}</span></label>
              <label class="hg-check"><input v-model="form.remind_app" type="checkbox" /> {{ $t('The bell in WIMS') }}</label></div>
            <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--lg-line)">
              <h3 class="lg-card-title" style="margin: 0 0 4px; font-size: 1rem">{{ $t('Schedule Greetings') }}</h3>
              <p class="hg-hint" style="margin: 0 0 12px">{{ $t('The default for every holiday. A holiday can have its own time on its own page. Off until you turn it on.') }}</p>
              <auto-send-fields v-model="auto" :bundles="bundles" :errors="form.errors" />
            </div>
          </section>

          <section class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-rose"><icons name="sparkles" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('How the greetings are signed') }}</h2><p class="lg-card-sub">{{ $t('Used in the email and the SMS of every holiday.') }}</p></div></header>
            <div class="hg-field"><label>{{ $t('Company name') }}</label><input v-model="form.company" maxlength="120" /><span class="ac-err">{{ form.errors.company }}</span></div>
            <div class="hg-field"><label>{{ $t('Signed by') }}</label><input v-model="form.signoff" maxlength="120" /><span class="hg-hint">{{ $t('For example: Management and Staff, or The Directors.') }}</span><span class="ac-err">{{ form.errors.signoff }}</span></div>

            <div class="hg-field"><label>{{ $t('Public address for the pictures in emails') }}</label><input v-model="form.asset_url" maxlength="190" :placeholder="assetBase" /><span class="hg-hint">{{ $t('A photo behind an email is loaded by the reader\'s mail app from this address, so it must be reachable from the internet. Leave it empty to use the address of WIMS (:u).', { u: assetBase }) }}</span><span class="ac-err">{{ form.errors.asset_url }}</span></div>

            <div class="hg-field"><label>{{ $t('Who gets the reminder') }}</label>
              <ul class="hg-people"><li v-for="p in people" :key="p.id"><b>{{ p.name }}</b><small>{{ p.role }}</small></li><li v-if="!people.length" class="hg-note">{{ $t('Nobody. Make a user a Super Admin, or give the permission "Receive the reminder".') }}</li></ul>
              <span class="hg-hint">{{ $t('Every Super Admin, and anybody with the permission to receive holiday reminders (give it on the Roles page).') }}</span></div>
          </section>
        </div>
        <section class="lg-card" v-if="photos && photos.length" style="margin-top: 16px">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="image" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Photos of the emails') }}</h2><p class="lg-card-sub">{{ $t('Each email shows a photo behind its top section, in black and white at 15%. Upload your own for a template, or put the WIMS photo back. It is used in every email of that template, in every year.') }}</p></div></header>
          <div v-if="!canPhotos" class="lg-note lg-note-blue" style="margin-bottom: 12px"><icons name="info" class="w-4 h-4" /><span>{{ $t('You can see the photos but not change them.') }}</span></div>
          <div class="hg-photo-grid"><photo-card v-for="p in photos" :key="p.theme" :photo="p" :can="canPhotos" /></div>
        </section>
        <div class="lg-actionbar">
          <span class="lg-actionbar-note">{{ $t('Changes apply from the next reminder.') }}</span>
          <div class="lg-actionbar-btns"><button type="submit" :disabled="form.processing" class="lg-btn tone tone-solid" style="--tone: #059669"><span class="tone-ico"><icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" /></span>{{ $t('Save the Settings') }}</button></div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import PhotoCard from './PhotoCard.vue';
import AutoSendFields from './AutoSendFields.vue';

export default {
  components: { AdminLayout, PageHeader, Link, PhotoCard, AutoSendFields },
  props: { prefs: Object, people: Array, smsReady: Boolean, photos: Array, canPhotos: Boolean, assetBase: String, bundles: { type: Array, default: () => [] } },
  data() {
    const p = this.prefs;
    return { form: this.$inertia.form({ hours_before: parseInt(p.holiday_hours_before, 10) || 8, skip_weekends: p.holiday_skip_weekends === '1', remind_email: p.holiday_remind_email === '1', remind_sms: p.holiday_remind_sms === '1', remind_app: p.holiday_remind_app === '1', signoff: p.holiday_signoff, company: p.holiday_company, asset_url: p.holiday_asset_url || '', auto_enabled: p.holiday_auto_enabled === '1', auto_minutes: parseInt(p.holiday_auto_minutes, 10) || 0, auto_bundle: parseInt(p.holiday_auto_bundle, 10) || 0 }) };
  },
  computed: {
    auto: {
      get() { return { enabled: this.form.auto_enabled, minutes: this.form.auto_minutes, bundle_id: this.form.auto_bundle }; },
      set(v) { this.form.auto_enabled = v.enabled; this.form.auto_minutes = v.minutes; this.form.auto_bundle = v.bundle_id || 0; },
    },
  },
  methods: { save() { this.form.put(route('holiday-greetings.settings.update'), { preserveScroll: true }); } },
};
</script>

<style scoped>
.hg-check { display: flex; align-items: center; gap: 8px; margin: 4px 0; font-weight: 600; }
.hg-check input { width: 18px; height: 18px; flex: none; padding: 0; }
.hg-photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 12px; }
@media (max-width: 520px) { .hg-photo-grid { grid-template-columns: minmax(0, 1fr); } }
.hg-people { display: grid; gap: 6px; margin: 0; padding: 0; list-style: none; }
.hg-people li { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 12px; border-radius: 12px; background: var(--lg-soft); }
.hg-people small { color: var(--lg-mute); }
</style>

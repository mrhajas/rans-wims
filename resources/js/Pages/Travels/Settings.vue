<template>
  <admin-layout :title="$t('Travels Settings')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="cog" :title="$t('Travels Settings')" :subtitle="$t('The lists and rules of the Travels module. Nothing here is fixed in the program: change it when your needs change.')">
        <Link :href="route('travels.index')" class="lg-btn lg-btn-soft"><icons name="plane" class="w-4 h-4" /><span>{{ $t('Travels') }}</span></Link>
      </page-header>
      <form @submit.prevent="save">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="globe" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Home country') }}</h2><p class="lg-card-sub">{{ $t('A travel to this country is Local; a travel to any other country is International.') }}</p></div></header>
          <div class="ac-f" style="max-width: 360px"><label>{{ $t('Country the company is in') }} *</label><input v-model="form.home_country" /><span class="ac-err">{{ err('home_country') }}</span></div>
        </section>
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="cash" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Currencies') }}</h2><p class="lg-card-sub">{{ $t('The currencies a ticket or visa can be priced in. Separate them with commas. A currency already used on a record always stays available.') }}</p></div></header>
          <div class="ac-f"><label>{{ $t('Currencies') }} *</label><input v-model="form.currencies" :placeholder="defaults.currencies" /><span class="ac-err">{{ err('currencies') }}</span></div>
        </section>
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Kinds of travel') }}</h2><p class="lg-card-sub">{{ $t('The choices offered for "Kind of travel". Separate them with commas.') }}</p></div></header>
          <div class="ac-f"><label>{{ $t('Kinds of travel') }} *</label><input v-model="form.types" :placeholder="defaults.types" /><span class="ac-err">{{ err('types') }}</span></div>
        </section>
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Departure reminders') }}</h2><p class="lg-card-sub">{{ $t('How many days before an approved travel leaves the "Departing soon" alert goes out (who is told is set on the Alerts page). Example: 7, 1. Leave empty for no reminders.') }}</p></div></header>
          <div class="ac-f" style="max-width: 360px"><label>{{ $t('Days before departure') }}</label><input v-model="form.remind_days" :placeholder="defaults.remind_days" /><span class="ac-err">{{ err('remind_days') }}</span></div>
        </section>
        <div class="lg-actionbar"><span class="lg-actionbar-note">{{ $t('Changes are recorded in the activity log.') }}</span>
          <div class="lg-actionbar-btns"><button type="button" class="lg-btn t-slate" @click="useDefaults"><icons name="refresh" class="w-4 h-4" />{{ $t('Use the starting values') }}</button><button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing"><icons name="tick" class="w-4 h-4" />{{ form.processing ? $t('Saving...') : $t('Save') }}</button></div></div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, PageHeader, Link },
  props: { values: Object, defaults: Object },
  data() { return { form: this.$inertia.form({ ...this.values }) }; },
  methods: {
    err(k) { return (this.$page.props.errors || {})[k]; },
    useDefaults() { Object.assign(this.form, { ...this.defaults }); },
    save() { this.form.put(route('travels.settings.update'), { preserveScroll: true }); },
  },
};
</script>

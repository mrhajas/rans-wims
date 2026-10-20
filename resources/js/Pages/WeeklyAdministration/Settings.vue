<template>
  <admin-layout :title="$t('Administration Settings')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="cog" :title="$t('Administration Settings')" :subtitle="$t('The wording and currency of the weekly document. Nothing here is fixed in the program: change it when your needs change.')">
        <Link :href="route('weekly-administration.index')" class="lg-btn lg-btn-soft"><icons name="doc-text" class="w-4 h-4" /><span>{{ $t('Documents') }}</span></Link>
      </page-header>
      <form @submit.prevent="save">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="building" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Department') }}</h2><p class="lg-card-sub">{{ $t('The title at the top of every document and print.') }}</p></div></header>
          <div class="ac-f" style="max-width: 420px"><label>{{ $t('Department name') }} *</label><input v-model="form.department" maxlength="120" :placeholder="defaults.department" /><span class="ac-err">{{ err('department') }}</span></div>
        </section>
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="cash" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Currency') }}</h2><p class="lg-card-sub">{{ $t('Used for new documents. Documents already made keep theirs.') }}</p></div></header>
          <div class="ac-f" style="max-width: 200px"><label>{{ $t('Currency') }} *</label><input v-model="form.currency" maxlength="3" :placeholder="defaults.currency" /><span class="ac-err">{{ err('currency') }}</span></div>
        </section>
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-rose"><icons name="heart" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Charity wording') }}</h2><p class="lg-card-sub">{{ $t('How each charity line reads when it is pulled. :name becomes the person or place and :month the month, for example CHARITY - SHEIKH ALI ISSA FOR OCTOBER 2026.') }}</p></div></header>
          <div class="ac-f"><label>{{ $t('Wording') }} *</label><input v-model="form.charity_wording" maxlength="190" :placeholder="defaults.charity_wording" /><span class="ac-err">{{ err('charity_wording') }}</span></div>
        </section>
        <div class="lg-actionbar"><span class="lg-actionbar-note">{{ $t('Changes are recorded in the activity log.') }}</span>
          <div class="lg-actionbar-btns"><button type="button" class="lg-btn t-slate" @click="useDefaults"><icons name="refresh" class="w-4 h-4" />{{ $t('Use the starting values') }}</button><button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing"><icons name="tick" class="w-4 h-4" />{{ $t('Save settings') }}</button></div></div>
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
    save() { this.form.put(route('weekly-administration.settings.update'), { preserveScroll: true }); },
  },
};
</script>

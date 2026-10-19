<template>
  <section v-if="data && data.total >= 0" class="lg-card pdp">
    <header class="lg-card-head">
      <span class="lg-card-ico lg-ico-teal"><icons name="camera" class="w-5 h-5" /></span>
      <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Site Diary') }}</h2><p class="lg-card-sub">{{ data.total }} {{ $t('diaries recorded for this project') }}</p></div>
      <Link :href="data.url" class="lg-btn lg-btn-soft ml-auto"><icons name="list" class="w-4 h-4" />{{ $t('View Site Diary') }}</Link>
    </header>
    <p v-if="data.latest" class="pdp-latest">{{ $t('Latest') }}: <Link :href="route('site-diary.show', data.latest.id)" class="pdp-link">{{ data.latest.reference }}</Link> · {{ $date(data.latest.date) }} · {{ data.latest.by }}</p>
    <p v-else class="lg-empty">{{ $t('No site diary has been recorded for this project yet.') }}</p>
    <div v-if="data.photos.length" class="pdp-photos"><Link v-for="p in data.photos" :key="p.id" :href="route('site-diary.show', p.diary)"><img :src="p.url" alt="" loading="lazy" /></Link></div>
    <ul v-if="data.remarks.length" class="pdp-remarks"><li v-for="r in data.remarks" :key="r.id"><b>{{ $date(r.date) }}</b> · {{ r.by }} - {{ r.text }}</li></ul>
  </section>
</template>

<script>
import { Link } from '@inertiajs/vue3';

// Project page panel: how many diaries, the latest one, latest photos and remarks. Silent when the user has no access.
export default {
  components: { Link },
  props: { warehouseId: { type: Number, required: true } },
  data() { return { data: null }; },
  async mounted() {
    if (!this.$can('read-site-diary') || (this.$page.props.enabledFeatures || {}).site_diary === false) return;
    try {
      const { data } = await window.axios.get(this.route('site-diary.projects.summary', this.warehouseId));
      this.data = data;
    } catch (e) { /* no access / feature off: show nothing */ }
  },
};
</script>

<style scoped>
.pdp { margin-bottom: 1rem; }
.pdp-latest { margin: 0 0 0.6rem; font-size: 0.85rem; color: var(--lg-mute); }
.pdp-link { color: #2f6fb3; font-weight: 800; }
.pdp-photos { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 0.7rem; }
.pdp-photos img { width: 84px; height: 64px; object-fit: cover; border-radius: 0.6rem; border: 1px solid var(--lg-line); }
.pdp-remarks { margin: 0; padding: 0; list-style: none; display: grid; gap: 4px; font-size: 0.82rem; color: var(--lg-mute); }
.pdp-remarks b { color: var(--lg-ink); }
</style>

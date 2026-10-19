<template>
  <nav v-if="rows.links && rows.links.length > 3" class="ac-pages" :aria-label="$t('Pages')">
    <template v-for="(l, i) in rows.links" :key="i"><Link v-if="l.url" :href="l.url" class="ac-page" :class="{ 'is-on': l.active }" preserve-scroll v-html="l.label" /><span v-else class="ac-page is-off" v-html="l.label" /></template>
    <span class="ac-page-info">{{ rows.from || 0 }}-{{ rows.to || 0 }} / {{ rows.total }}</span>
  </nav>
</template>
<script>
import { Link } from '@inertiajs/vue3';
export default { components: { Link }, props: { rows: Object } };
</script>

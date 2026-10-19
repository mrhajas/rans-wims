<template>
  <ul class="sb-wait">
    <li v-for="(w, i) in items" :key="w.key || w.label">
      <div class="sb-wait__row" :class="{ 'is-link': w.url, 'is-over': w.overdue }" @click="go(w, $event)">
        <span class="sb-no">{{ i + 1 }}</span>
        <b>{{ w.count }}</b>
        <span class="sb-wait__txt">
          <span class="sb-wait__label"><component :is="w.url ? 'a' : 'span'" :href="w.url || undefined" :target="w.url ? '_blank' : undefined" :rel="w.url ? 'noopener' : undefined">{{ $t(w.label) }}</component><em v-if="w.overdue">{{ w.overdue }} {{ $t('overdue') }}</em></span>
          <small>{{ w.module }}<template v-if="w.hint"> &middot; {{ $t(w.hint) }}</template></small>
          <span v-if="w.records && w.records.length" class="sb-refs">
            <component :is="r.url ? 'a' : 'span'" v-for="(r, j) in w.records" :key="j" :href="r.url || undefined" :target="r.url ? '_blank' : undefined" :rel="r.url ? 'noopener' : undefined" class="sb-ref" :title="r.reference">{{ r.reference }}</component>
            <small v-if="w.count > w.records.length" class="sb-refs__more">+ {{ w.count - w.records.length }} {{ $t('more') }}</small>
          </span>
        </span>
        <icons v-if="w.url" name="link" class="w-4 h-4 sb-wait__go" />
      </div>
    </li>
  </ul>
</template>

<script>
export default {
  props: { items: { type: Array, default: () => [] } },
  methods: {
    // the whole line opens the list in a new tab; the reference chips open their own record
    go(w, ev) { if (!w.url || ev.target.closest('a')) return; window.open(w.url, '_blank', 'noopener'); },
  },
};
</script>

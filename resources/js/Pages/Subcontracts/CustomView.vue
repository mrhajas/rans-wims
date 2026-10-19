<template>
  <template v-for="c in shown" :key="c.key"><div><dt>{{ c.label }}</dt><dd>{{ show(c) }}</dd></div></template>
</template>

<script>
// The custom fields of a record as definition-list rows (put it inside the <dl class="ac-kv"> of the Details card). Empty values are left out.
export default {
  props: { defs: { type: Array, default: () => [] }, values: { type: Object, default: () => ({}) } },
  computed: { shown() { return this.defs.filter(c => this.values[c.key] !== null && this.values[c.key] !== undefined && this.values[c.key] !== ''); } },
  methods: { show(c) { const v = this.values[c.key]; if (c.type === 'yesno') return v ? this.$t('Yes') : this.$t('No'); if (c.type === 'date') return this.$date(v); return v; } },
};
</script>

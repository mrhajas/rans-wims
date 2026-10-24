<template>
  <section v-if="items && items.length" class="lg-stats print:hidden" :class="{ 'lg-stats-tight': items.length > 4, 'lg-stats-compact': compact }">
    <component
      :is="item.active !== undefined ? 'button' : 'div'"
      v-for="item in items"
      :key="item.label"
      :type="item.active !== undefined ? 'button' : undefined"
      class="lg-stat"
      :class="{ 'is-clickable': item.active !== undefined, 'is-active': item.active, 'is-wide': String(format(item.value)).length > 11 }"
      @click="item.active !== undefined && $emit('select', item)"
    >
      <span class="lg-stat-ico" :class="'lg-ico-' + (item.tone || 'navy')"><icons :name="item.icon" class="w-5 h-5" /></span>
      <div class="min-w-0 text-left">
        <div class="lg-stat-value" :class="item.valueClass">{{ format(item.value) }}</div>
        <div class="lg-stat-label">{{ item.label }}</div>
      </div>
    </component>
  </section>
</template>

<script>
export default {
  props: { items: { type: Array, default: () => [] }, compact: { type: Boolean, default: false } },
  emits: ['select'],
  methods: {
    format(v) {
      return typeof v === 'number' ? v.toLocaleString() : v;
    },
  },
};
</script>

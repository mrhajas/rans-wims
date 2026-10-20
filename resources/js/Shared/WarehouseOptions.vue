<template>
  <!-- the <option>s of a project / warehouse <select>: in sections (Main Warehouses, Projects, Others) when the list is a full one, otherwise exactly as a plain list -->
  <template v-if="grouped">
    <optgroup v-for="g in sections" :key="g.key" :label="g.label">
      <option v-for="w in g.items" :key="w.id" :value="w.id" :title="w.category ? 'Category: ' + w.category : null">{{ w.name }}{{ mark(w) }}</option>
    </optgroup>
  </template>
  <template v-else>
    <option v-for="w in items" :key="w.id" :value="w.id">{{ w.name }}</option>
  </template>
</template>

<script>
import { groupWarehouses, isFullWarehouseList, progressOf } from '@/Core/warehouseGroups';

export default {
  props: { items: { type: Array, default: () => [] }, group: { type: Boolean, default: true } },
  computed: {
    grouped() {
      if (!this.group) return false;
      const page = this.$page && this.$page.props;
      if (page && page.groupedWarehouseLists === false) return false;

      return isFullWarehouseList(this.items);
    },
    sections() { return groupWarehouses(this.items); },
  },
  methods: {
    // a native option cannot hold a coloured dot, so a project says it in words
    mark(w) { const p = progressOf(w); return p === 'done' ? ' (finished)' : ''; },
  },
};
</script>

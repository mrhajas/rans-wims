<template>
  <div>
    <h3 class="text-sm font-semibold text-gray-800 mb-3">{{ config.label }}</h3>
    <div class="overflow-x-auto rounded-md border border-gray-200">
      <table class="w-full text-sm whitespace-nowrap">
        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
          <tr>
            <th v-for="col in config.columns" :key="col" class="px-3 py-2 text-left">{{ col }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, i) in config.rows" :key="i" class="border-t">
            <td v-for="(cell, j) in row" :key="j" class="px-3 py-2">{{ cell }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    config: { type: Object, required: true },
  },

  mounted() {
    this.emitResult();
  },

  methods: {
    emitResult() {
      this.$emit('result', { title: this.config.label, columns: this.config.columns, rows: this.config.rows });
    },
  },
};
</script>

<template>
  <div>
    <h3 class="text-sm font-semibold text-gray-800 mb-3">{{ config.label }}</h3>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
      <div v-for="field in config.fields" :key="field.key">
        <label class="block text-xs font-medium text-gray-600 mb-1">
          {{ field.label }}<span v-if="field.unit" class="text-gray-400"> ({{ field.unit }})</span>
        </label>
        <input
          type="number"
          step="any"
          v-model="values[field.key]"
          class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
        />
      </div>
    </div>

    <div class="rounded-md bg-slate-50 border border-slate-200 p-3 space-y-1">
      <div v-for="(line, i) in results" :key="i" class="flex items-center justify-between text-sm">
        <span class="text-gray-600">{{ line.label }}</span>
        <span class="font-semibold text-gray-900">{{ line.value }} <span class="text-gray-400 font-normal">{{ line.unit }}</span></span>
      </div>
    </div>

    <p v-if="config.notes" class="mt-3 text-xs text-gray-400">{{ config.notes }}</p>
  </div>
</template>

<script>
export default {
  props: {
    config: { type: Object, required: true },
  },

  data() {
    const values = {};
    (this.config.fields || []).forEach(field => {
      values[field.key] = field.default !== undefined ? field.default : '';
    });
    return { values };
  },

  computed: {
    results() {
      try {
        return this.config.compute(this.values) || [];
      } catch (e) {
        return [];
      }
    },
  },

  watch: {
    results: {
      immediate: true,
      handler(lines) {
        this.$emit('result', {
          title: this.config.label,
          lines: (this.config.fields || [])
            .map(f => [f.label, this.values[f.key] !== '' && this.values[f.key] !== undefined ? `${this.values[f.key]}${f.unit ? ' ' + f.unit : ''}` : '-'])
            .concat(lines.map(l => [l.label, `${l.value}${l.unit ? ' ' + l.unit : ''}`])),
        });
      },
    },
  },
};
</script>

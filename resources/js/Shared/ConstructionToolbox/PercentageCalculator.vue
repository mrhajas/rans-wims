<template>
  <div>
    <div class="flex gap-2 mb-4">
      <button
        v-for="m in modes"
        :key="m.id"
        type="button"
        @click="mode = m.id"
        class="flex-1 rounded-md px-2 py-2 text-xs font-semibold transition-colors"
        :class="mode === m.id ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
      >
        {{ m.label }}
      </button>
    </div>

    <div class="grid grid-cols-2 gap-3 mb-4">
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">{{ fieldALabel }}</label>
        <input type="number" step="any" v-model="a" class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" />
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">{{ fieldBLabel }}</label>
        <input type="number" step="any" v-model="b" class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" />
      </div>
    </div>

    <div class="rounded-md bg-slate-50 border border-slate-200 p-3 flex items-center justify-between text-sm">
      <span class="text-gray-600">{{ resultLabel }}</span>
      <span class="font-semibold text-gray-900">{{ result }}</span>
    </div>
  </div>
</template>

<script>
const num = v => (Number.isFinite(parseFloat(v)) ? parseFloat(v) : 0);

export default {
  data() {
    return {
      mode: 'of',
      a: '',
      b: '',
      modes: [
        { id: 'of', label: 'X% of Y' },
        { id: 'is-what', label: 'X is what % of Y' },
        { id: 'change', label: 'Increase/Decrease X by Y%' },
      ],
    };
  },

  computed: {
    fieldALabel() {
      return this.mode === 'of' ? 'Percentage (X)' : this.mode === 'is-what' ? 'Value (X)' : 'Base Value (X)';
    },
    fieldBLabel() {
      return this.mode === 'of' ? 'Value (Y)' : this.mode === 'is-what' ? 'Total (Y)' : 'Percentage Change (Y)';
    },
    resultLabel() {
      if (this.mode === 'of') return `${this.a || 0}% of ${this.b || 0}`;
      if (this.mode === 'is-what') return `${this.a || 0} as % of ${this.b || 0}`;
      return `${this.a || 0} changed by ${this.b || 0}%`;
    },
    result() {
      if (this.mode === 'of') {
        return this.round((num(this.a) / 100) * num(this.b));
      }
      if (this.mode === 'is-what') {
        return num(this.b) === 0 ? '-' : `${this.round((num(this.a) / num(this.b)) * 100)}%`;
      }
      return this.round(num(this.a) * (1 + num(this.b) / 100));
    },
  },

  watch: {
    result: {
      immediate: true,
      handler(value) {
        this.$emit('result', { title: 'Percentage Calculator', lines: [[this.resultLabel, String(value)]] });
      },
    },
  },

  methods: {
    round(value) {
      return Math.round(value * 1e6) / 1e6;
    },
  },
};
</script>

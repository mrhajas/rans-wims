<template>
  <div>
    <h3 class="text-sm font-semibold text-gray-800 mb-3">{{ config.label }} Converter</h3>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">From</label>
        <select v-model="fromUnit" class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
          <option v-for="unit in config.units" :key="unit.key" :value="unit.key">{{ unit.label }}</option>
        </select>
        <input
          type="number"
          step="any"
          v-model="fromValue"
          class="mt-2 w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
        />
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">To</label>
        <select v-model="toUnit" class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
          <option v-for="unit in config.units" :key="unit.key" :value="unit.key">{{ unit.label }}</option>
        </select>
        <div class="mt-2 w-full rounded-md bg-slate-50 border border-slate-200 px-3 py-2 text-sm font-semibold text-gray-900">
          {{ result }}
        </div>
      </div>
    </div>

    <button type="button" @click="swap" class="inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-500 font-medium">
      <icons name="refresh" className="w-3.5 h-3.5" />
      Swap
    </button>
  </div>
</template>

<script>
export default {
  props: {
    config: { type: Object, required: true },
  },

  data() {
    return {
      fromUnit: this.config.units[0].key,
      toUnit: this.config.units[1]?.key || this.config.units[0].key,
      fromValue: 1,
    };
  },

  computed: {
    result() {
      const value = parseFloat(this.fromValue);
      if (!Number.isFinite(value)) return '-';

      if (this.config.toBase && this.config.fromBase) {
        const base = this.config.toBase(value, this.fromUnit);
        return this.round(this.config.fromBase(base, this.toUnit));
      }

      const fromFactor = this.config.units.find(u => u.key === this.fromUnit)?.factor ?? 1;
      const toFactor = this.config.units.find(u => u.key === this.toUnit)?.factor ?? 1;
      return this.round((value * fromFactor) / toFactor);
    },
  },

  watch: {
    result: {
      immediate: true,
      handler(value) {
        this.$emit('result', {
          title: `${this.config.label} Converter`,
          lines: [
            ['From', `${this.fromValue} ${this.fromUnit}`],
            ['To', `${value} ${this.toUnit}`],
          ],
        });
      },
    },
  },

  methods: {
    round(value) {
      return Math.round(value * 1e6) / 1e6;
    },
    swap() {
      [this.fromUnit, this.toUnit] = [this.toUnit, this.fromUnit];
    },
  },
};
</script>

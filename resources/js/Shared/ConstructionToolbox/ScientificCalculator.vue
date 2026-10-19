<template>
  <div>
    <div class="flex items-center justify-between mb-2">
      <div class="rounded-md bg-slate-900 text-white px-3 py-4 text-right text-2xl font-mono flex-1 overflow-x-auto">
        {{ state.display }}
      </div>
    </div>
    <div class="flex items-center justify-between text-xs text-gray-400 mb-2">
      <span v-if="state.memory !== 0">M = {{ state.memory }}</span>
      <label class="inline-flex items-center gap-1 ml-auto cursor-pointer select-none">
        <input type="checkbox" v-model="degrees" class="rounded border-gray-300" />
        Degrees
      </label>
    </div>

    <div class="grid grid-cols-5 gap-2 mb-2">
      <button type="button" @click="unary(v => Math.sin(toRad(v)))" class="calc-btn calc-btn-fn">sin</button>
      <button type="button" @click="unary(v => Math.cos(toRad(v)))" class="calc-btn calc-btn-fn">cos</button>
      <button type="button" @click="unary(v => Math.tan(toRad(v)))" class="calc-btn calc-btn-fn">tan</button>
      <button type="button" @click="unary(v => Math.sqrt(v))" class="calc-btn calc-btn-fn">√</button>
      <button type="button" @click="unary(v => v * v)" class="calc-btn calc-btn-fn">x²</button>

      <button type="button" @click="onOperator('^')" class="calc-btn calc-btn-fn">xʸ</button>
      <button type="button" @click="unary(v => Math.log10(v))" class="calc-btn calc-btn-fn">log</button>
      <button type="button" @click="unary(v => Math.log(v))" class="calc-btn calc-btn-fn">ln</button>
      <button type="button" @click="unary(() => Math.PI)" class="calc-btn calc-btn-fn">π</button>
      <button type="button" @click="unary(v => (v === 0 ? NaN : 1 / v))" class="calc-btn calc-btn-fn">1/x</button>
    </div>

    <div class="grid grid-cols-4 gap-2">
      <button type="button" @click="onClear" class="calc-btn calc-btn-fn">C</button>
      <button type="button" @click="onBackspace" class="calc-btn calc-btn-fn">⌫</button>
      <button type="button" @click="onMemory" class="calc-btn calc-btn-fn">M+</button>
      <button type="button" @click="onOperator('÷')" class="calc-btn calc-btn-op">÷</button>

      <button type="button" @click="onDigit('7')" class="calc-btn">7</button>
      <button type="button" @click="onDigit('8')" class="calc-btn">8</button>
      <button type="button" @click="onDigit('9')" class="calc-btn">9</button>
      <button type="button" @click="onOperator('×')" class="calc-btn calc-btn-op">×</button>

      <button type="button" @click="onDigit('4')" class="calc-btn">4</button>
      <button type="button" @click="onDigit('5')" class="calc-btn">5</button>
      <button type="button" @click="onDigit('6')" class="calc-btn">6</button>
      <button type="button" @click="onOperator('-')" class="calc-btn calc-btn-op">-</button>

      <button type="button" @click="onDigit('1')" class="calc-btn">1</button>
      <button type="button" @click="onDigit('2')" class="calc-btn">2</button>
      <button type="button" @click="onDigit('3')" class="calc-btn">3</button>
      <button type="button" @click="onOperator('+')" class="calc-btn calc-btn-op">+</button>

      <button type="button" @click="onDigit('0')" class="calc-btn col-span-2">0</button>
      <button type="button" @click="onDecimal" class="calc-btn">.</button>
      <button type="button" @click="onEquals" class="calc-btn calc-btn-eq">=</button>
    </div>
  </div>
</template>

<script>
import { createEngineState, inputDigit, inputDecimal, clear, backspace, round } from './calculatorEngine';

export default {
  data() {
    return { state: createEngineState(), degrees: true };
  },

  watch: {
    'state.display': {
      immediate: true,
      handler(display) {
        this.$emit('result', { title: 'Scientific Calculator', lines: [['Result', display]] });
      },
    },
  },

  methods: {
    toRad(v) {
      return this.degrees ? (v * Math.PI) / 180 : v;
    },
    onDigit(d) {
      inputDigit(this.state, d);
    },
    onDecimal() {
      inputDecimal(this.state);
    },
    onOperator(op) {
      // x^y is the only binary operator here; reuse the same pending-value flow as +/-/×/÷.
      const current = parseFloat(this.state.display);
      if (this.state.previousValue !== null && this.state.operator && !this.state.overwrite) {
        this.state.previousValue = this.applyPending(this.state.previousValue, current, this.state.operator);
        this.state.display = String(round(this.state.previousValue));
      } else {
        this.state.previousValue = current;
      }
      this.state.operator = op;
      this.state.overwrite = true;
    },
    applyPending(a, b, op) {
      if (op === '+') return a + b;
      if (op === '-') return a - b;
      if (op === '×') return a * b;
      if (op === '÷') return b === 0 ? NaN : a / b;
      if (op === '^') return Math.pow(a, b);
      return b;
    },
    onEquals() {
      if (this.state.operator === null || this.state.previousValue === null) return;
      const current = parseFloat(this.state.display);
      const result = this.applyPending(this.state.previousValue, current, this.state.operator);
      this.state.display = String(round(result));
      this.state.previousValue = null;
      this.state.operator = null;
      this.state.overwrite = true;
    },
    onClear() {
      clear(this.state);
    },
    onBackspace() {
      backspace(this.state);
    },
    unary(fn) {
      const value = fn(parseFloat(this.state.display));
      this.state.display = String(round(value));
      this.state.overwrite = true;
    },
    onMemory() {
      this.state.memory = round(this.state.memory + parseFloat(this.state.display));
    },
  },
};
</script>

<style scoped>
.calc-btn {
  @apply rounded-md bg-gray-100 hover:bg-gray-200 text-gray-800 font-semibold py-2.5 text-sm transition-colors;
}
.calc-btn-fn {
  @apply bg-slate-200 hover:bg-slate-300 text-slate-700;
}
.calc-btn-op {
  @apply bg-indigo-100 hover:bg-indigo-200 text-indigo-700;
}
.calc-btn-eq {
  @apply bg-indigo-600 hover:bg-indigo-500 text-white;
}
</style>

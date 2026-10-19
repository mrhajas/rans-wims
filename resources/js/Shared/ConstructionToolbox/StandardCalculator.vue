<template>
  <div>
    <div class="rounded-md bg-slate-900 text-white px-3 py-4 text-right text-2xl font-mono mb-3 overflow-x-auto">
      {{ state.display }}
    </div>
    <div v-if="state.memory !== 0" class="text-xs text-gray-400 mb-2 text-right">M = {{ state.memory }}</div>

    <div class="grid grid-cols-4 gap-2">
      <button type="button" @click="onMemory('MC')" class="calc-btn calc-btn-fn">MC</button>
      <button type="button" @click="onMemory('MR')" class="calc-btn calc-btn-fn">MR</button>
      <button type="button" @click="onMemory('M-')" class="calc-btn calc-btn-fn">M-</button>
      <button type="button" @click="onMemory('M+')" class="calc-btn calc-btn-fn">M+</button>

      <button type="button" @click="onClear" class="calc-btn calc-btn-fn">C</button>
      <button type="button" @click="onBackspace" class="calc-btn calc-btn-fn">⌫</button>
      <button type="button" @click="onPercent" class="calc-btn calc-btn-fn">%</button>
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
import { createEngineState, inputDigit, inputDecimal, chooseOperator, equals, clear, backspace, round } from './calculatorEngine';

export default {
  data() {
    return { state: createEngineState() };
  },

  watch: {
    'state.display': {
      immediate: true,
      handler(display) {
        this.$emit('result', { title: 'Standard Calculator', lines: [['Result', display]] });
      },
    },
  },

  mounted() {
    window.addEventListener('keydown', this.onKeydown);
  },
  beforeUnmount() {
    window.removeEventListener('keydown', this.onKeydown);
  },

  methods: {
    onDigit(d) {
      inputDigit(this.state, d);
    },
    onDecimal() {
      inputDecimal(this.state);
    },
    onOperator(op) {
      chooseOperator(this.state, op);
    },
    onEquals() {
      equals(this.state);
    },
    onClear() {
      clear(this.state);
    },
    onBackspace() {
      backspace(this.state);
    },
    onPercent() {
      this.state.display = String(round(parseFloat(this.state.display) / 100));
      this.state.overwrite = true;
    },
    onMemory(action) {
      const current = parseFloat(this.state.display);
      if (action === 'MC') this.state.memory = 0;
      if (action === 'MR') {
        this.state.display = String(this.state.memory);
        this.state.overwrite = true;
      }
      if (action === 'M+') this.state.memory = round(this.state.memory + current);
      if (action === 'M-') this.state.memory = round(this.state.memory - current);
    },
    onKeydown(e) {
      const tag = document.activeElement && document.activeElement.tagName;
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
      if (/^[0-9]$/.test(e.key)) this.onDigit(e.key);
      else if (e.key === '.') this.onDecimal();
      else if (e.key === '+') this.onOperator('+');
      else if (e.key === '-') this.onOperator('-');
      else if (e.key === '*') this.onOperator('×');
      else if (e.key === '/') this.onOperator('÷');
      else if (e.key === 'Enter' || e.key === '=') this.onEquals();
      else if (e.key === 'Backspace') this.onBackspace();
      else if (e.key === 'Escape') this.onClear();
      else return;
      e.preventDefault();
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

const OPERATORS = {
  '+': (a, b) => a + b,
  '-': (a, b) => a - b,
  '×': (a, b) => a * b,
  '÷': (a, b) => (b === 0 ? NaN : a / b),
};

export function createEngineState() {
  return {
    display: '0',
    previousValue: null,
    operator: null,
    overwrite: true,
    memory: 0,
  };
}

export function inputDigit(state, digit) {
  if (state.overwrite || state.display === '0') {
    state.display = digit;
    state.overwrite = false;
  } else {
    state.display += digit;
  }
}

export function inputDecimal(state) {
  if (state.overwrite) {
    state.display = '0.';
    state.overwrite = false;
    return;
  }
  if (!state.display.includes('.')) {
    state.display += '.';
  }
}

export function chooseOperator(state, operator) {
  const current = parseFloat(state.display);
  if (state.previousValue !== null && state.operator && !state.overwrite) {
    state.previousValue = OPERATORS[state.operator](state.previousValue, current);
    state.display = String(round(state.previousValue));
  } else {
    state.previousValue = current;
  }
  state.operator = operator;
  state.overwrite = true;
}

export function equals(state) {
  if (state.operator === null || state.previousValue === null) return;
  const current = parseFloat(state.display);
  const result = OPERATORS[state.operator](state.previousValue, current);
  state.display = String(round(result));
  state.previousValue = null;
  state.operator = null;
  state.overwrite = true;
}

export function clear(state) {
  state.display = '0';
  state.previousValue = null;
  state.operator = null;
  state.overwrite = true;
}

export function backspace(state) {
  if (state.overwrite) return;
  state.display = state.display.length > 1 ? state.display.slice(0, -1) : '0';
  if (state.display === '') state.display = '0';
}

export function applyUnary(state, fn) {
  const value = fn(parseFloat(state.display));
  state.display = String(round(value));
  state.overwrite = true;
}

export function round(value) {
  if (!Number.isFinite(value)) return value;
  return Math.round(value * 1e10) / 1e10;
}

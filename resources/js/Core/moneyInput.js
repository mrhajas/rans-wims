/**
 * v-money: an amount field that shows thousands commas while the person types (1234567.5 shows as 1,234,567.5), the way the Item price field should.
 *
 *   <input type="number" v-model="form.amount" v-money />
 *   <text-input type="number" money v-model="form.price" />          (TextInput passes it on)
 *   <input v-model="form.rate" v-money="{ decimals: 8 }" />
 *
 * What the form and the server see never changes: the field's value is always the plain number (no commas), because the commas live only in what is
 * shown. That is done by giving this one input its own `value` property: reading it returns the clean number, writing it (a model update from Vue, a
 * form reset) shows the formatted text. So v-model, @input handlers, `event.target.value`, Inertia forms and validation keep working untouched.
 *
 * Options: `decimals` (most decimals that can be typed, default 4), `negative` (a leading minus is allowed; by default only when the field's `min`
 * is not 0 or more). The field becomes a text box with a decimal keypad on phones, because a number box cannot hold commas.
 */

const NATIVE = typeof HTMLInputElement !== 'undefined' ? Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value') : null;
const SIG = /[0-9.\-]/;

function inputOf(el) {
  return el && el.tagName === 'INPUT' ? el : el && el.querySelector ? el.querySelector('input') : null;
}

function options(binding, input) {
  const o = binding && binding.value && typeof binding.value === 'object' ? binding.value : {};
  const min = input.getAttribute('min');
  const negative = o.negative !== undefined ? !!o.negative : !(min !== null && min !== '' && Number(min) >= 0);

  return { decimals: Number.isInteger(o.decimals) && o.decimals >= 0 ? o.decimals : 4, negative };
}

/** Whatever was typed or pasted -> the plain number as text ("1,234.50" -> "1234.50"); letters and a second dot are dropped. */
export function cleanMoney(raw, opts = { decimals: 4, negative: true }) {
  let s = String(raw == null ? '' : raw).replace(/[\s, ]/g, '');
  const neg = opts.negative && s.startsWith('-');
  s = s.replace(/[^0-9.]/g, '');
  const dot = s.indexOf('.');
  let int = dot >= 0 ? s.slice(0, dot) : s;
  int = int.replace(/^0+(?=\d)/, ''); // 007 -> 7
  if (dot >= 0) {
    const frac = s.slice(dot + 1).replace(/\./g, '').slice(0, opts.decimals);
    if (opts.decimals === 0) return (neg ? '-' : '') + int;
    s = int + '.' + frac;
  } else {
    s = int;
  }

  return (neg ? '-' : '') + s;
}

/** The plain number as text -> what is shown ("1234567.5" -> "1,234,567.5"). */
export function formatMoney(clean) {
  const neg = clean.startsWith('-');
  const body = neg ? clean.slice(1) : clean;
  const dot = body.indexOf('.');
  const int = dot >= 0 ? body.slice(0, dot) : body;
  const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

  return (neg ? '-' : '') + grouped + (dot >= 0 ? body.slice(dot) : '');
}

const state = new WeakMap();

export default {
  // before Vue gives the field its first value: from here on every write is shown with commas and every read gives the plain number
  created(el, binding) {
    const input = inputOf(el);
    if (!input || !NATIVE) return;
    const st = { opts: options(binding, input), last: '', ready: false };
    state.set(input, st);

    Object.defineProperty(input, 'value', {
      configurable: true,
      get() {
        return st.ready ? cleanMoney(NATIVE.get.call(input), st.opts) : st.last;
      },
      set(v) {
        st.last = cleanMoney(v, st.opts);
        if (st.ready) NATIVE.set.call(input, formatMoney(st.last));
      },
    });
  },

  mounted(el, binding) {
    const input = inputOf(el);
    const st = input && state.get(input);
    if (!st) return;
    st.opts = options(binding, input);
    // a number box cannot hold commas: it becomes a text box that still opens the decimal keypad on a phone
    input.type = 'text';
    input.setAttribute('inputmode', st.opts.negative ? 'text' : 'decimal');
    input.setAttribute('autocomplete', 'off');
    input.removeAttribute('step');
    st.ready = true;
    NATIVE.set.call(input, formatMoney(st.last));

    st.onInput = () => {
      const native = NATIVE.get.call(input);
      const cleaned = cleanMoney(native, st.opts);
      const shown = formatMoney(cleaned);
      if (shown === native) return;
      const pos = input.selectionStart == null ? native.length : input.selectionStart;
      const before = native.slice(0, pos).split('').filter(c => SIG.test(c)).length;
      NATIVE.set.call(input, shown);
      // the caret stays after the same digit it was after
      let seen = 0;
      let idx = 0;
      while (idx < shown.length && seen < before) {
        if (SIG.test(shown[idx])) seen++;
        idx++;
      }
      try { input.setSelectionRange(idx, idx); } catch (e) { /* the field is not focused */ }
    };
    input.addEventListener('input', st.onInput);
  },

  // the field is re-rendered with another `min`: the minus rule follows it
  updated(el, binding) {
    const input = inputOf(el);
    const st = input && state.get(input);
    if (st) st.opts = options(binding, input);
  },

  beforeUnmount(el) {
    const input = inputOf(el);
    const st = input && state.get(input);
    if (st && st.onInput) input.removeEventListener('input', st.onInput);
  },
};

<template>
  <div class="relative wdp-root" :class="flush ? 'wdp-flush' : 'col-span-6 sm:col-span-4 mb-2'">
    <tec-label :for="id" :value="label" v-if="label" />
    <div class="relative" :class="{ 'mt-1': label }">
      <div
        ref="trigger"
        class="wdp-trigger"
        :class="{ 'wdp-trigger-error': error || typedError, 'wdp-trigger-disabled': disabled, 'wdp-trigger-has-clear': clearable && (modelValue || text) && !disabled, 'wdp-trigger-open': open }"
      >
        <button type="button" class="wdp-icon-btn" :disabled="disabled" :aria-label="$t('Open calendar')" tabindex="-1" @mousedown.prevent @click="toggle">
          <svg class="wdp-trigger-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3.5" y="5" width="17" height="15.5" rx="3" />
            <path d="M8 3v4M16 3v4M3.5 10h17" />
          </svg>
        </button>
        <input
          :id="id"
          ref="input"
          v-model="text"
          type="text"
          class="wdp-input"
          :class="{ 'wdp-input-placeholder': !text }"
          :disabled="disabled"
          :placeholder="placeholder || $t('DD/MM/YYYY')"
          autocomplete="off"
          spellcheck="false"
          :aria-invalid="!!typedError"
          @focus="onFocus"
          @click="openPanel"
          @input="onType"
          @keydown.enter.prevent="commit"
          @keydown.esc="close"
          @keydown.down.prevent="openPanel"
          @blur="onBlur"
        />
      </div>
      <button
        type="button"
        v-if="clearable && modelValue && !disabled"
        class="wdp-clear-btn"
        @click.stop="clear"
        :aria-label="$t('Clear')"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
      </button>

      <Teleport to="body">
        <div v-if="open" ref="panel" class="wdp-panel" :style="panelStyle">
          <div class="wdp-panel-head">
            <button type="button" class="wdp-nav" @click="shiftYear(-1)" :aria-label="$t('Previous year')" :title="$t('Previous year')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6l-6 6 6 6M19 6l-6 6 6 6" /></svg>
            </button>
            <button type="button" class="wdp-nav" @click="shiftMonth(-1)" :aria-label="$t('Previous month')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6" /></svg>
            </button>
            <span class="wdp-panel-title">
              <select v-model.number="viewMonth" class="wdp-select wdp-select-month" :aria-label="$t('Month')">
                <option v-for="(name, i) in monthNames" :key="i" :value="i">{{ name }}</option>
              </select>
              <year-select v-model="viewYear" :min="minYear" :max="maxYear" :label="$t('Year')" />
            </span>
            <button type="button" class="wdp-nav" @click="shiftMonth(1)" :aria-label="$t('Next month')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6" /></svg>
            </button>
            <button type="button" class="wdp-nav" @click="shiftYear(1)" :aria-label="$t('Next year')" :title="$t('Next year')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6l6 6-6 6M5 6l6 6-6 6" /></svg>
            </button>
          </div>

          <div class="wdp-weekdays">
            <span v-for="wd in weekdayLabels" :key="wd">{{ wd }}</span>
          </div>

          <div class="wdp-grid">
            <button
              v-for="cell in cells"
              :key="cell.key"
              type="button"
              class="wdp-day"
              :class="{
                'wdp-day-muted': cell.muted,
                'wdp-day-weekend': cell.weekend && !cell.muted,
                'wdp-day-today': cell.isToday,
                'wdp-day-selected': cell.isSelected,
              }"
              :disabled="cell.muted || cell.disabled"
              @click="pick(cell)"
            >
              {{ cell.day }}
              <span v-if="cell.isToday && !cell.isSelected" class="wdp-day-dot"></span>
            </button>
          </div>

          <div class="wdp-panel-foot">
            <button type="button" class="wdp-foot-btn" @click="clear">{{ $t('Clear') }}</button>
            <button type="button" class="wdp-foot-btn wdp-foot-btn-primary" @click="goToday">{{ $t('Today') }}</button>
          </div>
        </div>
      </Teleport>
    </div>
    <tec-input-error v-if="typedError || error" :message="typedError || error" class="mt-1" />
  </div>
</template>

<script>
import { v4 as uuidv4 } from 'uuid';
import YearSelect from '@/Shared/YearSelect.vue';
import TecLabel from '@/Jetstream/Label.vue';
import TecInputError from '@/Jetstream/InputError.vue';

export default {
  name: 'DatePicker',

  emits: ['update:modelValue', 'change'],

  components: { TecLabel, TecInputError, YearSelect },

  props: {
    id: {
      type: String,
      default() {
        return uuidv4();
      },
    },
    label: String,
    error: String,
    placeholder: String,
    disabled: Boolean,
    clearable: { type: Boolean, default: true },
    modelValue: String,
    min: String,
    max: String,
    flush: Boolean, // no outer margin or grid span: for a date field that sits inside a toolbar, a label or a table cell
  },

  data() {
    const seed = this.parseValue(this.modelValue) || new Date();

    return {
      open: false,
      viewYear: seed.getFullYear(),
      viewMonth: seed.getMonth(),
      panelStyle: {},
      text: '',
      typedError: null,
      focused: false,
      keepText: false,
    };
  },

  computed: {
    weekdayLabels() {
      return [this.$t('Mo'), this.$t('Tu'), this.$t('We'), this.$t('Th'), this.$t('Fr'), this.$t('Sa'), this.$t('Su')];
    },
    monthNames() {
      return [
        this.$t('January'), this.$t('February'), this.$t('March'), this.$t('April'), this.$t('May'), this.$t('June'),
        this.$t('July'), this.$t('August'), this.$t('September'), this.$t('October'), this.$t('November'), this.$t('December'),
      ];
    },
    // The Year list starts on 5 years either side of the one on screen and grows as it is scrolled (see YearSelect).
    minYear() {
      return this.minDate ? this.minDate.getFullYear() : 1900;
    },
    maxYear() {
      return this.maxDate ? this.maxDate.getFullYear() : 2200;
    },
    selectedKey() {
      return this.modelValue || null;
    },
    minDate() {
      return this.parseValue(this.min);
    },
    maxDate() {
      return this.parseValue(this.max);
    },
    cells() {
      const first = new Date(this.viewYear, this.viewMonth, 1);
      const lead = (first.getDay() + 6) % 7;
      const daysInMonth = new Date(this.viewYear, this.viewMonth + 1, 0).getDate();
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      const out = [];

      const pushCell = (date, muted) => {
        const key = this.toValue(date);
        const disabled = (this.minDate && date < this.minDate) || (this.maxDate && date > this.maxDate);
        out.push({
          key: key + (muted ? '-m' : ''),
          date,
          day: date.getDate(),
          muted,
          disabled,
          weekend: [5, 6].includes((date.getDay() + 6) % 7),
          isToday: !muted && date.getTime() === today.getTime(),
          isSelected: !muted && key === this.selectedKey,
        });
      };

      for (let i = lead; i > 0; i--) {
        pushCell(new Date(this.viewYear, this.viewMonth, 1 - i), true);
      }
      for (let day = 1; day <= daysInMonth; day++) {
        pushCell(new Date(this.viewYear, this.viewMonth, day), false);
      }
      while (out.length % 7 !== 0 || out.length < 42) {
        const last = out[out.length - 1].date;
        pushCell(new Date(last.getFullYear(), last.getMonth(), last.getDate() + 1), true);
        if (out.length >= 42) break;
      }

      return out;
    },
  },

  created() {
    this.text = this.formatValue(this.modelValue);
  },

  watch: {
    modelValue(v) {
      const d = this.parseValue(v);
      if (d) {
        this.viewYear = d.getFullYear();
        this.viewMonth = d.getMonth();
      }
      if (this.keepText) {
        this.keepText = false;

        return;
      }
      // The parent changed the value (reset, picked in the calendar, loaded from the server): show it, and drop any old typing error.
      if (!this.focused || d || !v) {
        this.text = this.formatValue(v);
        if (d || !v) this.typedError = null;
      }
    },
  },

  beforeUnmount() {
    document.removeEventListener('mousedown', this.onDocMouseDown, true);
    window.removeEventListener('resize', this.onWindowReposition);
    window.removeEventListener('scroll', this.onWindowReposition, true);
  },

  methods: {
    // every change of the date goes out as v-model and also as a "change" (with the shape a native <input type=date> gives a handler)
    emitDate(value) {
      this.$emit('update:modelValue', value);
      this.$emit('change', { type: 'change', target: { value }, currentTarget: { value }, preventDefault() {}, stopPropagation() {} });
    },
    parseValue(v) {
      if (!v || typeof v !== 'string') return null;
      const parts = v.split('-').map(Number);
      if (parts.length !== 3 || parts.some(isNaN)) return null;
      const [y, m, d] = parts;

      return new Date(y, m - 1, d);
    },
    // How a date reads in the field: 06 Oct 2026 (unambiguous, and it can be typed back in).
    formatValue(v) {
      const d = this.parseValue(v);
      if (!d) return '';

      return String(d.getDate()).padStart(2, '0') + ' ' + this.monthShort(d.getMonth()) + ' ' + d.getFullYear();
    },
    monthShort(i) {
      return ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][i];
    },
    // Understands 25/12/2026, 25-12-2026, 25.12.2026, 25122026, 2026-12-25, "25 Dec 2026" and 25/12/26 (day first).
    // Returns a real Date, or null when the text is not a real calendar date (31/02/2026 is rejected).
    parseTyped(raw) {
      const t = String(raw || '').trim();
      if (!t) return null;
      let y; let m; let d; let hit;

      if ((hit = t.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$/))) {
        [y, m, d] = [+hit[1], +hit[2], +hit[3]];
      } else if ((hit = t.match(/^(\d{1,2})[\s\-/.]+(\d{1,2})[\s\-/.]+(\d{2}|\d{4})$/)) || (hit = t.match(/^(\d{2})(\d{2})(\d{4})$/))) {
        [d, m, y] = [+hit[1], +hit[2], +hit[3]];
        if (hit[3].length === 2) y += y < 70 ? 2000 : 1900;
      } else if ((hit = t.match(/^(\d{1,2})[\s\-/.]+([A-Za-z]{3,9})\.?[\s\-/.,]+(\d{2}|\d{4})$/))) {
        const idx = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'].indexOf(hit[2].slice(0, 3).toLowerCase());
        if (idx === -1) return null;
        [d, m, y] = [+hit[1], idx + 1, +hit[3]];
        if (hit[3].length === 2) y += y < 70 ? 2000 : 1900;
      } else {
        return null;
      }

      const date = new Date(y, m - 1, d);
      if (y < 1900 || y > 2200 || date.getFullYear() !== y || date.getMonth() !== m - 1 || date.getDate() !== d) return null;

      return date;
    },
    onFocus() {
      this.focused = true;
      this.openPanel();
    },
    onType() {
      this.typedError = null;
      // Follow what is being typed in the calendar (without changing the value until Enter / leaving the field).
      const d = this.parseTyped(this.text);
      if (d && /\d{4}/.test(this.text)) {
        this.viewYear = d.getFullYear();
        this.viewMonth = d.getMonth();
      }
    },
    onBlur() {
      this.focused = false;
      // Clicking inside the calendar must not count as "leaving the field".
      setTimeout(() => {
        if (!this.open || !this.$refs.panel || !this.$refs.panel.matches(':hover')) this.commit();
      }, 120);
    },
    // Accept what was typed: a valid date is saved (and shown in the standard format), an empty field clears the value,
    // anything else clears the value and shows why — a half-typed date is never submitted.
    commit() {
      const raw = this.text.trim();
      if (!raw) {
        this.typedError = null;
        if (this.modelValue) this.emitDate('');

        return;
      }

      const d = this.parseTyped(raw);
      if (!d) {
        this.typedError = this.$t('Enter a valid date, for example') + ' 25/12/2026.';
        this.rejectTyped();

        return;
      }
      if (this.minDate && d < this.minDate) {
        this.typedError = this.$t('The date must be on or after') + ' ' + this.formatValue(this.min) + '.';
        this.rejectTyped();

        return;
      }
      if (this.maxDate && d > this.maxDate) {
        this.typedError = this.$t('The date must be on or before') + ' ' + this.formatValue(this.max) + '.';
        this.rejectTyped();

        return;
      }

      this.typedError = null;
      const value = this.toValue(d);
      this.text = this.formatValue(value);
      if (value !== this.modelValue) this.emitDate(value);
      this.close();
    },
    // A rejected entry empties the value (a half-typed date is never submitted) but leaves the typed text and the message on screen.
    rejectTyped() {
      if (this.modelValue) {
        this.keepText = true;
        this.emitDate('');
      }
    },
    shiftYear(delta) {
      this.viewYear += delta;
    },
    toValue(date) {
      const y = date.getFullYear();
      const m = String(date.getMonth() + 1).padStart(2, '0');
      const d = String(date.getDate()).padStart(2, '0');

      return `${y}-${m}-${d}`;
    },
    toggle() {
      if (this.disabled) return;
      this.open ? this.close() : this.openPanel();
    },
    openPanel() {
      if (this.disabled || this.open) return;
      const d = this.parseValue(this.modelValue) || new Date();
      this.viewYear = d.getFullYear();
      this.viewMonth = d.getMonth();
      this.open = true;
      this.$nextTick(() => {
        this.positionPanel();
        document.addEventListener('mousedown', this.onDocMouseDown, true);
        window.addEventListener('resize', this.onWindowReposition);
        window.addEventListener('scroll', this.onWindowReposition, true);
      });
    },
    close() {
      this.open = false;
      document.removeEventListener('mousedown', this.onDocMouseDown, true);
      window.removeEventListener('resize', this.onWindowReposition);
      window.removeEventListener('scroll', this.onWindowReposition, true);
    },
    onWindowReposition() {
      if (this.open) this.positionPanel();
    },
    onDocMouseDown(e) {
      const trigger = this.$refs.trigger;
      const panel = this.$refs.panel;
      if (trigger && trigger.contains(e.target)) return;
      if (panel && panel.contains(e.target)) return;
      this.close();
    },
    positionPanel() {
      if (!this.$refs.trigger) return;
      const rect = this.$refs.trigger.getBoundingClientRect();
      const gap = 6;
      const phone = window.innerWidth <= 480;
      const panelHeight = phone ? 430 : 360;
      const openUpward = window.innerHeight - rect.bottom < panelHeight && rect.top > panelHeight;
      // On a phone the calendar is centred with comfortable side margins and bigger tap targets instead of hanging
      // off the field's left edge at a fixed 272px.
      const width = phone ? Math.min(window.innerWidth - 24, 340) : 300;
      this.panelStyle = {
        position: 'fixed',
        left: (phone ? Math.round((window.innerWidth - width) / 2) : Math.max(8, Math.min(rect.left, window.innerWidth - 320))) + 'px',
        width: width + 'px',
        zIndex: 9999,
        ...(openUpward ? { bottom: window.innerHeight - rect.top + gap + 'px' } : { top: rect.bottom + gap + 'px' }),
      };
    },
    shiftMonth(delta) {
      let m = this.viewMonth + delta;
      let y = this.viewYear;
      if (m < 0) { m = 11; y--; }
      if (m > 11) { m = 0; y++; }
      this.viewMonth = m;
      this.viewYear = y;
    },
    pick(cell) {
      if (cell.muted || cell.disabled) return;
      this.typedError = null;
      this.text = this.formatValue(cell.key);
      this.emitDate(cell.key);
      this.close();
    },
    clear() {
      this.typedError = null;
      this.text = '';
      this.emitDate('');
      this.close();
    },
    goToday() {
      const today = new Date();
      this.viewYear = today.getFullYear();
      this.viewMonth = today.getMonth();
      this.typedError = null;
      this.text = this.formatValue(this.toValue(today));
      this.emitDate(this.toValue(today));
      this.close();
    },
  },
};
</script>

<style scoped>
/* The app applies a global `.app-shell button { min-height: 2.5rem;
   padding: 0.55rem 0.95rem; }` reset to every <button> — every element
   below is a <button> (for click/keyboard semantics). `min-height` is a
   floor no explicit height can shrink below, and no rule here ever sets
   it, so it cascades in unopposed and floors the trigger, nav arrows,
   day cells and footer buttons all to ~40-44px. `padding` isn't a
   problem the same way: every button below that needs its own padding
   (or none) sets that property itself at equal-or-higher specificity, so
   it already wins on its own — this reset only needs to cover
   min-height. Two selectors, not one: `.wdp-panel` is rendered through
   `<Teleport to="body">`, so once mounted it's no longer a DOM
   descendant of `.wdp-root` at all — `.wdp-root button` can never reach
   the nav/day/footer buttons inside it, hence the separate rule below. */
.wdp-root button,
.wdp-panel button {
  min-height: 0;
}

.wdp-trigger {
  position: relative;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  text-align: left;
  padding: 0.15rem 0.75rem;
  border: 1px solid #d1d5db;
  border-radius: 0.375rem;
  background: #fff;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
  cursor: text;
  transition: border-color 0.12s ease, box-shadow 0.12s ease;
}
.wdp-trigger:hover { border-color: #94a3b8; }
.wdp-trigger:focus-within, .wdp-trigger-open { outline: none; border-color: #a5b4fc; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2); }
.wdp-icon-btn { display: inline-flex; align-items: center; justify-content: center; flex: none; padding: 0; margin: 0; border: 0; background: transparent; border-radius: 0.4rem; cursor: pointer; }
.wdp-icon-btn:disabled { cursor: not-allowed; }
.wdp-input { flex: 1; min-width: 0; width: 100%; padding: 0.4rem 0 !important; border: 0 !important; outline: none !important; box-shadow: none !important; background: transparent !important; font-size: 0.9rem; line-height: 1.5rem; font-weight: 600; color: #111827; font-variant-numeric: tabular-nums; }
.wdp-input::placeholder { color: #9ca3af; font-weight: 500; }
.wdp-input:disabled { cursor: not-allowed; }
.wdp-select { appearance: auto; padding: 0.2rem 0.3rem; border: 1px solid #e3e9f2; border-radius: 0.5rem; background: #fff; color: #101c33; font-size: 0.78rem; font-weight: 800; cursor: pointer; }
.wdp-select:focus { outline: none; border-color: #a5b4fc; }
.wdp-select-month { max-width: 5.4rem; }
.wdp-panel-title { display: inline-flex; align-items: center; gap: 0.3rem; }
.wdp-trigger-error { border-color: #ef4444; }
.wdp-trigger-disabled { cursor: not-allowed; opacity: 0.6; background: #f8fafc; }

.wdp-trigger-icon { flex: none; width: 1.1rem; height: 1.1rem; color: #1c3f66; stroke-width: 2.2; }
.wdp-trigger-text { flex: 1; font-size: 0.9rem; line-height: 1.5rem; font-weight: 600; color: #111827; font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wdp-trigger-placeholder { color: #9ca3af; font-weight: 500; }

.wdp-trigger-has-clear { padding-right: 2.6rem; }
.wdp-trigger-open { }
.wdp-clear-btn {
  position: absolute;
  right: 0.3rem;
  top: 50%;
  transform: translateY(-50%);
  display: flex;
  align-items: center;
  justify-content: center;
  width: 1.9rem;
  height: 1.9rem;
  padding: 0;
  color: #9ca3af;
  border-radius: 9999px;
}
.wdp-clear-btn:hover { color: #ef4444; background: #fef2f2; }
.wdp-clear-btn svg { width: 0.8rem; height: 0.8rem; }

.wdp-panel {
  background: #ffffff;
  border: 1px solid #e3e9f2;
  border-radius: 1rem;
  box-shadow: 0 2px 6px rgba(15, 23, 42, 0.06), 0 18px 40px -14px rgba(15, 23, 42, 0.25);
  padding: 0.85rem;
  animation: wdp-pop 0.12s ease;
}
@keyframes wdp-pop {
  from { opacity: 0; transform: translateY(-2px); }
  to { opacity: 1; transform: translateY(0); }
}

.wdp-panel-head { display: flex; align-items: center; justify-content: space-between; gap: 0.2rem; margin-bottom: 0.6rem; }
.wdp-panel-title { font-size: 0.82rem; font-weight: 800; color: #101c33; }
.wdp-nav {
  width: 1.65rem;
  height: 1.65rem;
  padding: 0;
  border-radius: 0.5rem;
  border: 1px solid #e3e9f2;
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #101c33;
  transition: background 0.12s ease, transform 0.12s ease;
}
.wdp-nav:hover { background: #e5edf7; transform: translateY(-1px); }
.wdp-nav svg { width: 0.85rem; height: 0.85rem; }

.wdp-weekdays { display: grid; grid-template-columns: repeat(7, 1fr); margin-bottom: 0.2rem; }
.wdp-weekdays span { text-align: center; font-size: 0.66rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: #b7c1d1; padding-bottom: 0.3rem; }

.wdp-grid { display: grid; grid-template-columns: repeat(7, 1fr); row-gap: 0.15rem; }
.wdp-day {
  position: relative;
  width: 2rem;
  height: 2rem;
  margin: 0 auto;
  padding: 0;
  border: none;
  background: transparent;
  border-radius: 9999px;
  font-size: 0.78rem;
  font-weight: 700;
  color: #101c33;
  font-variant-numeric: tabular-nums;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.12s ease, color 0.12s ease, transform 0.08s ease;
}
.wdp-day:hover:not(.wdp-day-muted):not(.wdp-day-selected):not(:disabled) { background: #e5edf7; }
.wdp-day:active:not(.wdp-day-muted):not(:disabled) { transform: scale(0.92); }
.wdp-day-muted { color: #d7deea; cursor: default; }
.wdp-day-weekend:not(.wdp-day-selected) { color: #a3536b; }
.wdp-day-today { box-shadow: inset 0 0 0 1.5px #b4842f; }
.wdp-day-selected { background: #1c3f66; color: #fff; box-shadow: 0 5px 12px -4px rgba(28, 63, 102, 0.6); }
.wdp-day-dot { position: absolute; bottom: 2px; left: 50%; transform: translateX(-50%); width: 3px; height: 3px; border-radius: 9999px; background: #b4842f; }

.wdp-panel-foot { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-top: 0.7rem; padding-top: 0.6rem; border-top: 1px solid #edf1f8; }
.wdp-foot-btn {
  font-size: 0.72rem;
  font-weight: 800;
  border-radius: 0.5rem;
  padding: 0.35rem 0.7rem;
  border: 1px solid #e3e9f2;
  background: #fff;
  color: #64748b;
  cursor: pointer;
  transition: background 0.12s ease, color 0.12s ease;
}
.wdp-foot-btn:hover { color: #101c33; }
.wdp-foot-btn-primary { background: #1c3f66; border-color: #1c3f66; color: #fff; }
.wdp-foot-btn-primary:hover { background: #132c49; color: #fff; }

html.dark .wdp-trigger { background: #1e293b; border-color: rgba(255, 255, 255, 0.1); }
html.dark .wdp-input { color: #e2e8f0; }
html.dark .wdp-select { background: #1e293b; border-color: rgba(255, 255, 255, 0.1); color: #e2e8f0; }
html.dark .wdp-panel { background: #0f172a; border-color: rgba(255, 255, 255, 0.08); }
html.dark .wdp-panel-title { color: #e2e8f0; }
html.dark .wdp-nav { background: #1e293b; border-color: rgba(255, 255, 255, 0.1); color: #e2e8f0; }
html.dark .wdp-nav:hover { background: #334155; }
html.dark .wdp-day { color: #e2e8f0; }
html.dark .wdp-day:hover:not(.wdp-day-muted):not(.wdp-day-selected):not(:disabled) { background: #334155; }
html.dark .wdp-day-muted { color: #334155; }
html.dark .wdp-panel-foot { border-color: rgba(255, 255, 255, 0.08); }
html.dark .wdp-foot-btn { background: #1e293b; border-color: rgba(255, 255, 255, 0.1); color: #94a3b8; }

@media (max-width: 480px) {
  .wdp-panel { padding: 1rem; }
  .wdp-nav { width: 2.5rem; height: 2.5rem; border-radius: 0.7rem; }
  .wdp-nav svg { width: 1.05rem; height: 1.05rem; }
  .wdp-select { font-size: 0.9rem; padding: 0.35rem 0.3rem; }
  .wdp-nav { width: 2.1rem; height: 2.1rem; }
  .wdp-weekdays span { font-size: 0.7rem; }
  .wdp-day { width: 100%; max-width: 2.6rem; height: auto; aspect-ratio: 1 / 1; font-size: 0.9rem; }
  .wdp-grid { row-gap: 0.2rem; }
  .wdp-foot-btn { font-size: 0.82rem; padding: 0.6rem 1rem; min-height: 2.5rem; }
}
</style>

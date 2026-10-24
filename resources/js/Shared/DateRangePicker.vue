<template>
  <div class="col-span-6 sm:col-span-4 relative wdp-root mb-2">
    <tec-label :for="id" :value="label" v-if="label" />
    <div class="relative" :class="{ 'mt-1': label }">
      <button
        :id="id"
        ref="trigger"
        type="button"
        :disabled="disabled"
        class="wdp-trigger"
        :class="{ 'wdp-trigger-error': error, 'wdp-trigger-disabled': disabled }"
        @click="toggle"
      >
        <svg class="wdp-trigger-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3.5" y="5" width="17" height="15.5" rx="3" />
          <path d="M8 3v4M16 3v4M3.5 10h17" />
        </svg>
        <span class="wdp-trigger-text" :class="{ 'wdp-trigger-placeholder': !modelValue?.start }">{{ displayValue }}</span>
        <button
          type="button"
          v-if="clearable && (modelValue?.start || modelValue?.end) && !disabled"
          class="wdp-clear-btn"
          @click.stop="clear"
          :aria-label="$t('Clear')"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
        </button>
      </button>

      <Teleport to="body">
        <div v-if="open" ref="panel" class="wdp-panel" :style="panelStyle">
          <div class="wdp-panel-head">
            <button type="button" class="wdp-nav" @click="viewYear--" :aria-label="$t('Previous year')" :title="$t('Previous year')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6l-6 6 6 6M19 6l-6 6 6 6" /></svg>
            </button>
            <button type="button" class="wdp-nav" @click="shiftMonth(-1)" :aria-label="$t('Previous month')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6l-6 6 6 6" /></svg>
            </button>
            <span class="wdp-panel-title">
              <css-select v-model.number="viewMonth" class="wdp-select wdp-select-month" :aria-label="$t('Month')">
                <option v-for="(name, i) in monthNames" :key="i" :value="i">{{ name }}</option>
              </css-select>
              <year-select v-model="viewYear" :min="minYear" :max="maxYear" :label="$t('Year')" />
            </span>
            <button type="button" class="wdp-nav" @click="shiftMonth(1)" :aria-label="$t('Next month')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6" /></svg>
            </button>
            <button type="button" class="wdp-nav" @click="viewYear++" :aria-label="$t('Next year')" :title="$t('Next year')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6l6 6-6 6M5 6l6 6-6 6" /></svg>
            </button>
          </div>

          <div class="wdp-weekdays">
            <span v-for="wd in weekdayLabels" :key="wd">{{ wd }}</span>
          </div>

          <div class="wdp-grid">
            <div
              v-for="cell in cells"
              :key="cell.key"
              class="wdp-cell"
              :class="{ 'wdp-cell-band': cell.band, 'wdp-cell-band-l': cell.bandLeft, 'wdp-cell-band-r': cell.bandRight, 'wdp-cell-band-preview': cell.bandPreview }"
            >
              <button
                type="button"
                class="wdp-day"
                :class="{
                  'wdp-day-muted': cell.muted,
                  'wdp-day-weekend': cell.weekend && !cell.muted,
                  'wdp-day-today': cell.isToday,
                  'wdp-day-selected': cell.isStart || cell.isEnd,
                }"
                :disabled="cell.muted"
                @mouseenter="onHover(cell)"
                @click="pick(cell)"
              >
                {{ cell.day }}
                <span v-if="cell.isToday && !cell.isStart && !cell.isEnd" class="wdp-day-dot"></span>
              </button>
            </div>
          </div>

          <div class="wdp-panel-foot">
            <span class="wdp-range-summary">{{ summaryText }}</span>
            <button type="button" class="wdp-foot-btn" @click="clear">{{ $t('Clear') }}</button>
          </div>
        </div>
      </Teleport>
    </div>
    <tec-input-error v-if="error" :message="error"  class="mt-1" />
  </div>
</template>

<script>
import { v4 as uuidv4 } from 'uuid';
import YearSelect from '@/Shared/YearSelect.vue';
import TecLabel from '@/Jetstream/Label.vue';
import TecInputError from '@/Jetstream/InputError.vue';

export default {
  name: 'DateRangePicker',

  emits: ['update:modelValue'],

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
    // { start: 'YYYY-MM-DD'|null, end: 'YYYY-MM-DD'|null }
    modelValue: { type: Object, default: () => ({ start: null, end: null }) },
  },

  data() {
    const seed = this.parseValue(this.modelValue?.start) || new Date();

    return {
      open: false,
      picking: false,
      hoverDate: null,
      viewYear: seed.getFullYear(),
      viewMonth: seed.getMonth(),
      panelStyle: {},
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
      return 1900;
    },
    maxYear() {
      return 2200;
    },
    startDate() {
      return this.parseValue(this.modelValue?.start);
    },
    endDate() {
      return this.parseValue(this.modelValue?.end);
    },
    displayValue() {
      if (this.startDate && this.endDate) return this.fmt(this.startDate) + ' – ' + this.fmt(this.endDate);
      if (this.startDate) return this.fmt(this.startDate) + ' – …';

      return this.placeholder || this.$t('Select date range');
    },
    summaryText() {
      if (this.startDate && this.endDate) {
        const days = Math.round((this.endDate - this.startDate) / 86400000) + 1;

        return this.fmt(this.startDate) + ' – ' + this.fmt(this.endDate) + ' · ' + days + (days === 1 ? ' ' + this.$t('day') : ' ' + this.$t('days'));
      }
      if (this.startDate) return this.$t('Pick an end date');

      return this.$t('No range selected');
    },
    cells() {
      const first = new Date(this.viewYear, this.viewMonth, 1);
      const lead = (first.getDay() + 6) % 7;
      const daysInMonth = new Date(this.viewYear, this.viewMonth + 1, 0).getDate();
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      const out = [];

      const previewEnd = this.picking && this.hoverDate ? this.hoverDate : null;
      const lo = this.startDate;
      const hiConfirmed = !this.picking ? this.endDate : null;
      const hiPreview = this.picking ? previewEnd : null;

      const pushCell = (date, muted) => {
        const key = this.toValue(date);
        const wd = (date.getDay() + 6) % 7;
        const isStart = !muted && lo && key === this.toValue(lo);
        const isEnd = !muted && this.endDate && !this.picking && key === this.toValue(this.endDate);
        let band = false, bandLeft = false, bandRight = false, bandPreview = false;

        if (!muted && lo) {
          if (hiConfirmed && date >= (lo <= hiConfirmed ? lo : hiConfirmed) && date <= (lo <= hiConfirmed ? hiConfirmed : lo)) {
            band = true;
          } else if (hiPreview && date >= (lo <= hiPreview ? lo : hiPreview) && date <= (lo <= hiPreview ? hiPreview : lo)) {
            band = true;
            bandPreview = true;
          }
          if (band) {
            const rangeLo = (hiConfirmed || hiPreview) && lo <= (hiConfirmed || hiPreview) ? lo : (hiConfirmed || hiPreview);
            const rangeHi = (hiConfirmed || hiPreview) && lo <= (hiConfirmed || hiPreview) ? (hiConfirmed || hiPreview) : lo;
            if (this.toValue(date) === this.toValue(rangeLo) || wd === 0) bandLeft = true;
            if (this.toValue(date) === this.toValue(rangeHi) || wd === 6) bandRight = true;
          }
        }

        out.push({
          key: key + (muted ? '-m' : ''),
          date,
          day: date.getDate(),
          muted,
          weekend: wd >= 5,
          isToday: !muted && date.getTime() === today.getTime(),
          isStart,
          isEnd,
          band,
          bandLeft,
          bandRight,
          bandPreview,
        });
      };

      for (let i = lead; i > 0; i--) pushCell(new Date(this.viewYear, this.viewMonth, 1 - i), true);
      for (let day = 1; day <= daysInMonth; day++) pushCell(new Date(this.viewYear, this.viewMonth, day), false);
      while (out.length % 7 !== 0 || out.length < 42) {
        const last = out[out.length - 1].date;
        pushCell(new Date(last.getFullYear(), last.getMonth(), last.getDate() + 1), true);
        if (out.length >= 42) break;
      }

      return out;
    },
  },

  beforeUnmount() {
    document.removeEventListener('mousedown', this.onDocMouseDown, true);
    window.removeEventListener('resize', this.onWindowReposition);
    window.removeEventListener('scroll', this.onWindowReposition, true);
  },

  methods: {
    parseValue(v) {
      if (!v || typeof v !== 'string') return null;
      const parts = v.split('-').map(Number);
      if (parts.length !== 3 || parts.some(isNaN)) return null;
      const [y, m, d] = parts;

      return new Date(y, m - 1, d);
    },
    toValue(date) {
      const y = date.getFullYear();
      const m = String(date.getMonth() + 1).padStart(2, '0');
      const d = String(date.getDate()).padStart(2, '0');

      return `${y}-${m}-${d}`;
    },
    fmt(d) {
      return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    },
    toggle() {
      if (this.disabled) return;
      this.open ? this.close() : this.openPanel();
    },
    openPanel() {
      const d = this.startDate || new Date();
      this.viewYear = d.getFullYear();
      this.viewMonth = d.getMonth();
      this.picking = false;
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
      this.picking = false;
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
      const panelHeight = 380;
      const openUpward = window.innerHeight - rect.bottom < panelHeight && rect.top > panelHeight;
      this.panelStyle = {
        position: 'fixed',
        left: Math.max(8, Math.min(rect.left, window.innerWidth - 320)) + 'px',
        width: Math.min(300, window.innerWidth - 16) + 'px',
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
    onHover(cell) {
      if (this.picking && !cell.muted) this.hoverDate = cell.date;
    },
    pick(cell) {
      if (cell.muted) return;
      if (!this.picking) {
        this.$emit('update:modelValue', { start: cell.key, end: null });
        this.picking = true;
        this.hoverDate = cell.date;

        return;
      }
      const s = this.startDate;
      const e = cell.date;
      const start = s <= e ? s : e;
      const end = s <= e ? e : s;
      this.$emit('update:modelValue', { start: this.toValue(start), end: this.toValue(end) });
      this.picking = false;
      this.close();
    },
    clear() {
      this.$emit('update:modelValue', { start: null, end: null });
      this.picking = false;
      this.close();
    },
  },
};
</script>

<style scoped>
.wdp-root button,
.wdp-panel button {
  min-height: 0;
}

button.wdp-trigger {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  text-align: left;
  padding: 0.5rem 0.75rem;
  border: 1px solid #d1d5db;
  border-radius: 0.375rem;
  background: #fff;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
  cursor: pointer;
  transition: border-color 0.12s ease, box-shadow 0.12s ease;
}
.wdp-trigger:hover { border-color: #94a3b8; }
.wdp-trigger:focus-visible { outline: none; border-color: #a5b4fc; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2); }
.wdp-trigger-error { border-color: #ef4444; }
.wdp-trigger-disabled { cursor: not-allowed; opacity: 0.6; background: #f8fafc; }

.wdp-trigger-icon { flex: none; width: 1.1rem; height: 1.1rem; color: #1c3f66; stroke-width: 2.2; }
.wdp-trigger-text { flex: 1; font-size: 0.9rem; line-height: 1.5rem; font-weight: 600; color: #111827; font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wdp-trigger-placeholder { color: #9ca3af; font-weight: 500; }

.wdp-clear-btn {
  flex: none;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 1.2rem;
  height: 1.2rem;
  padding: 0;
  color: #9ca3af;
  border-radius: 9999px;
}
.wdp-clear-btn:hover { color: #ef4444; background: #fef2f2; }
.wdp-clear-btn svg { width: 0.7rem; height: 0.7rem; }

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
.wdp-panel-title { display: inline-flex; align-items: center; gap: 0.3rem; }
.wdp-select { padding: 0.2rem 0.3rem; border: 1px solid #e3e9f2; border-radius: 0.5rem; background: #fff; color: #101c33; font-size: 0.78rem; font-weight: 800; cursor: pointer; }
.wdp-select:focus { outline: none; border-color: #a5b4fc; }
.wdp-select-month { max-width: 5.4rem; }
html.dark .wdp-select { background: #1e293b; border-color: rgba(255, 255, 255, 0.1); color: #e2e8f0; }
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

.wdp-cell { position: relative; display: flex; align-items: center; justify-content: center; height: 2rem; }
.wdp-cell-band::before {
  content: '';
  position: absolute;
  inset: 3px -1px;
  background: #e5edf7;
  z-index: 0;
}
.wdp-cell-band-preview::before { background: #f6ecd9; }
.wdp-cell-band-l::before { left: 3px; border-radius: 9999px 0 0 9999px; }
.wdp-cell-band-r::before { right: 3px; border-radius: 0 9999px 9999px 0; }

.wdp-day {
  position: relative;
  z-index: 1;
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
.wdp-range-summary { font-size: 0.7rem; font-weight: 700; color: #64748b; }
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

html.dark .wdp-trigger { background: #1e293b; border-color: rgba(255, 255, 255, 0.1); }
html.dark .wdp-trigger-text { color: #e2e8f0; }
html.dark .wdp-panel { background: #0f172a; border-color: rgba(255, 255, 255, 0.08); }
html.dark .wdp-panel-title { color: #e2e8f0; }
html.dark .wdp-nav { background: #1e293b; border-color: rgba(255, 255, 255, 0.1); color: #e2e8f0; }
html.dark .wdp-nav:hover { background: #334155; }
html.dark .wdp-day { color: #e2e8f0; }
html.dark .wdp-day:hover:not(.wdp-day-muted):not(.wdp-day-selected):not(:disabled) { background: #334155; }
html.dark .wdp-day-muted { color: #334155; }
html.dark .wdp-cell-band::before { background: rgba(84, 134, 194, 0.18); }
html.dark .wdp-cell-band-preview::before { background: rgba(220, 181, 103, 0.16); }
html.dark .wdp-panel-foot { border-color: rgba(255, 255, 255, 0.08); }
html.dark .wdp-foot-btn { background: #1e293b; border-color: rgba(255, 255, 255, 0.1); color: #94a3b8; }
</style>

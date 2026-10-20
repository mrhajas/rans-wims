<template>
  <div class="mcal" :class="{ 'mcal-compact': compact }">
    <div class="mcal-header" :class="{ 'mcal-header-centered': hideNav }">
      <button v-if="!hideNav" type="button" class="mcal-nav-btn" @click="$emit('prev')"><icons name="chevron-down" class="w-4 h-4 rotate-90" /></button>
      <div class="mcal-title"><icons v-if="compact" name="calendar" class="w-3.5 h-3.5 mcal-title-icon" />{{ monthLabel }}</div>
      <button v-if="!hideNav" type="button" class="mcal-nav-btn" @click="$emit('next')"><icons name="chevron-down" class="w-4 h-4 -rotate-90" /></button>
    </div>
    <div class="mcal-weekdays">
      <span v-for="d in weekdayLabels" :key="d">{{ d }}</span>
    </div>
    <div class="mcal-grid">
      <div
        v-for="cell in cells"
        :key="cell.dateKey"
        class="mcal-cell"
        :class="[
          { 'mcal-cell-outside': !cell.inMonth, 'mcal-cell-today': cell.isToday, 'mcal-cell-clickable': compact && cell.events.length },
          compact && cell.events.length ? `mcal-cell-has-${cellSeverity(cell.events)}` : '',
        ]"
        @click="onCellClick(cell)"
        @mouseenter="compact && cell.events.length ? showTooltip($event, topEvent(cell.events)) : null"
        @mouseleave="compact && cell.events.length ? hideTooltip() : null"
      >
        <div class="mcal-cell-date">{{ cell.day }}</div>
        <div v-if="!compact" class="mcal-cell-events">
          <button
            v-for="(ev, idx) in cell.events.slice(0, 3)"
            :key="idx"
            type="button"
            class="mcal-event"
            :class="`mcal-event-${ev.severity || 'info'}`"
            @click="$emit('eventClick', ev)"
            @mouseenter="showTooltip($event, ev)"
            @mouseleave="hideTooltip"
          >
            {{ ev.title }}
          </button>
          <div v-if="cell.events.length > 3" class="mcal-event-more">+{{ cell.events.length - 3 }} {{ $t('more') }}</div>
        </div>
        <div v-else-if="cell.events.length" class="mcal-cell-icon-wrap">
          <icons :name="severityIcon(cellSeverity(cell.events))" class="w-3 h-3 mcal-cell-icon" />
          <span v-if="cell.events.length > 1" class="mcal-cell-count">{{ cell.events.length }}</span>
        </div>
      </div>
    </div>

    <teleport to="body">
      <div v-if="tooltip.show" class="mcal-tooltip" :style="{ left: tooltip.x + 'px', top: tooltip.y + 'px' }">
        <img v-if="tooltip.ev.meta && tooltip.ev.meta.photo_url" :src="tooltip.ev.meta.photo_url" alt="" />
        <div class="mcal-tooltip-title">{{ tooltip.ev.title }}</div>
        <template v-if="tooltip.ev.meta">
          <div class="mcal-tooltip-row" v-if="tooltip.ev.meta.compliance_number"><span>{{ $t('Number') }}</span><strong>{{ tooltip.ev.meta.compliance_number }}</strong></div>
          <div class="mcal-tooltip-row" v-if="tooltip.ev.meta.compliance_type"><span>{{ $t('Type') }}</span><strong>{{ tooltip.ev.meta.compliance_type }}</strong></div>
          <div class="mcal-tooltip-row" v-if="tooltip.ev.meta.issuing_authority"><span>{{ $t('Authority') }}</span><strong>{{ tooltip.ev.meta.issuing_authority }}</strong></div>
          <div class="mcal-tooltip-row" v-if="tooltip.ev.meta.status"><span>{{ $t('Status') }}</span><strong>{{ tooltip.ev.meta.status }}</strong></div>
          <div class="mcal-tooltip-row" v-if="tooltip.ev.meta.days_remaining_label"><span>{{ $t('Expires') }}</span><strong>{{ tooltip.ev.meta.days_remaining_label }}</strong></div>
          <div class="mcal-tooltip-row" v-if="tooltip.ev.meta.renews_for_days"><span>{{ $t('Renews for') }}</span><strong>{{ $t(':n days', { n: tooltip.ev.meta.renews_for_days }) }}</strong></div>
          <div class="mcal-tooltip-row" v-if="tooltip.ev.meta.new_expiry"><span>{{ $t('New expiry') }}</span><strong>{{ tooltip.ev.meta.new_expiry }}</strong></div>
          <div class="mcal-tooltip-row" v-if="tooltip.ev.meta.notice_days_before"><span>{{ $t('Notice') }}</span><strong>{{ $t(':n day(s) before renewal', { n: tooltip.ev.meta.notice_days_before }) }}</strong></div>
        </template>
        <div class="mcal-tooltip-hint">{{ $t('Click to open') }}</div>
      </div>
    </teleport>
  </div>
</template>

<script>
export default {
  props: {
    year: { type: Number, required: true },
    month: { type: Number, required: true }, // 1-12
    events: { type: Array, default: () => [] }, // [{date: 'YYYY-MM-DD', title, severity, url, meta}]
    hideNav: { type: Boolean, default: false },
    // Denser rendering for small spaces (e.g. a 2x2 dashboard modal grid) —
    // event pills collapse to small colored dots instead of full title text.
    compact: { type: Boolean, default: false },
  },

  emits: ['prev', 'next', 'eventClick'],

  data() {
    return {
      tooltip: { show: false, ev: {}, x: 0, y: 0 },
    };
  },

  methods: {
    showTooltip(event, ev) {
      const rect = event.currentTarget.getBoundingClientRect();
      const width = 220;
      const margin = 12;
      // Fixed-position + teleported to <body>, so it can land anywhere in
      // the viewport depending which of the 6 calendars/column the event
      // is in — clamp it back on-screen instead of letting it run off the
      // right/bottom edge (which is what made it feel "unresponsive").
      let x = rect.right + margin;
      if (x + width > window.innerWidth - 8) {
        x = rect.left - margin - width;
      }
      x = Math.max(8, Math.min(x, window.innerWidth - width - 8));

      const estHeight = 240;
      let y = rect.top + rect.height / 2;
      y = Math.max(estHeight / 2 + 8, Math.min(y, window.innerHeight - estHeight / 2 - 8));

      this.tooltip = { show: true, ev, x, y };
    },
    hideTooltip() {
      this.tooltip.show = false;
    },
    onCellClick(cell) {
      if (this.compact && cell.events.length) {
        this.$emit('eventClick', this.topEvent(cell.events));
      }
    },
    // Worst-first — a day with any critical event reads as critical overall,
    // regardless of what else landed on it.
    cellSeverity(events) {
      const rank = { critical: 0, warning: 1, auto: 2, info: 3 };
      return events.reduce((worst, ev) => {
        const s = ev.severity || 'info';
        return rank[s] < rank[worst] ? s : worst;
      }, 'info');
    },
    topEvent(events) {
      const worst = this.cellSeverity(events);
      return events.find(ev => (ev.severity || 'info') === worst) || events[0];
    },
    severityIcon(severity) {
      return { critical: 'exclamation', warning: 'clock', auto: 'refresh', info: 'doc-text' }[severity] || 'doc-text';
    },
  },

  computed: {
    monthLabel() {
      return new Date(this.year, this.month - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
    },
    weekdayLabels() {
      return [this.$t('Sun'), this.$t('Mon'), this.$t('Tue'), this.$t('Wed'), this.$t('Thu'), this.$t('Fri'), this.$t('Sat')];
    },
    eventsByDate() {
      const map = {};
      this.events.forEach(ev => {
        if (!map[ev.date]) map[ev.date] = [];
        map[ev.date].push(ev);
      });
      return map;
    },
    cells() {
      const firstOfMonth = new Date(this.year, this.month - 1, 1);
      const startDow = firstOfMonth.getDay();
      const gridStart = new Date(firstOfMonth);
      gridStart.setDate(gridStart.getDate() - startDow);

      const todayKey = this.$formatJSDate(new Date());
      const cells = [];
      for (let i = 0; i < 42; i++) {
        const d = new Date(gridStart);
        d.setDate(d.getDate() + i);
        // $formatJSDate (global mixin), not toISOString() — that converts
        // to UTC first and silently shifts the date back a day in any
        // timezone ahead of UTC (East Africa Time included), while the
        // server sends plain local 'YYYY-MM-DD' with no conversion.
        const dateKey = this.$formatJSDate(d);
        cells.push({
          dateKey,
          day: d.getDate(),
          inMonth: d.getMonth() === this.month - 1,
          isToday: dateKey === todayKey,
          events: this.eventsByDate[dateKey] || [],
        });
      }
      return cells;
    },
  },
};
</script>

<style scoped>
.mcal { background: #ffffff; border: 1px solid #eef1f6; border-radius: 1.1rem; padding: 1.25rem; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 8px 20px rgba(15, 23, 42, 0.04); }
.mcal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
.mcal-header-centered { justify-content: center; }
.mcal-title { font-size: 1rem; font-weight: 800; color: #1f2937; }
.mcal-nav-btn { display: inline-flex; align-items: center; justify-content: center; height: 2rem; width: 2rem; border-radius: 9999px; background: #f1f5f9; color: #475569; }
.mcal-nav-btn:hover { background: #e2e8f0; }
.mcal-weekdays { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; font-size: 0.68rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; text-align: center; margin-bottom: 4px; }
.mcal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
.mcal-cell { min-height: 5.5rem; border-radius: 0.6rem; background: #f8fafc; padding: 0.4rem; overflow: hidden; }
.mcal-cell-outside { opacity: 0.4; }
.mcal-cell-today { background: #eef2ff; box-shadow: inset 0 0 0 1.5px #818cf8; }
.mcal-cell-date { font-size: 0.72rem; font-weight: 700; color: #64748b; margin-bottom: 0.25rem; }
.mcal-cell-events { display: flex; flex-direction: column; gap: 2px; }
.mcal-event { display: block; width: 100%; text-align: left; font-size: 0.66rem; font-weight: 600; padding: 1px 4px; border-radius: 0.3rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mcal-event-critical { background: #ffe4e6; color: #be123c; }
.mcal-event-warning { background: #fef3c7; color: #b45309; }
.mcal-event-info { background: #dbeafe; color: #1d4ed8; }
.mcal-event-auto { background: #ccfbf1; color: #0f766e; box-shadow: inset 0 0 0 1px rgba(15, 118, 110, 0.35); }
.mcal-event-more { font-size: 0.6rem; color: #94a3b8; padding: 1px 4px; }

/* Compact mode — tight cells, whole-box severity coloring instead of title pills */
.mcal-compact { padding: 0.85rem; }
.mcal-compact .mcal-header { margin-bottom: 0.6rem; }
.mcal-compact .mcal-title { font-size: 0.82rem; display: flex; align-items: center; gap: 5px; }
.mcal-title-icon { color: #818cf8; }
.mcal-compact .mcal-weekdays { font-size: 0.66rem; margin-bottom: 3px; }
.mcal-compact .mcal-grid { gap: 3px; }
.mcal-compact .mcal-cell {
  min-height: 2.3rem; padding: 0.25rem; border-radius: 0.5rem; transition: transform 0.12s ease, box-shadow 0.12s ease, background 0.12s ease;
}
.mcal-compact .mcal-cell-date { font-size: 0.64rem; margin-bottom: 0.1rem; }

.mcal-cell-clickable { cursor: pointer; }
.mcal-cell-clickable:hover { transform: translateY(-1px) scale(1.04); box-shadow: 0 6px 14px -4px rgba(15, 23, 42, 0.25); z-index: 1; position: relative; }

.mcal-cell-has-critical { background: linear-gradient(155deg, #fecdd3, #fda4af); box-shadow: inset 0 0 0 1px rgba(225, 29, 72, 0.25); }
.mcal-cell-has-critical .mcal-cell-date { color: #9f1239; font-weight: 800; }
.mcal-cell-has-critical .mcal-cell-icon { color: #be123c; }
.mcal-cell-has-warning { background: linear-gradient(155deg, #fef3c7, #fde68a); box-shadow: inset 0 0 0 1px rgba(217, 119, 6, 0.25); }
.mcal-cell-has-warning .mcal-cell-date { color: #92400e; font-weight: 800; }
.mcal-cell-has-warning .mcal-cell-icon { color: #b45309; }
.mcal-cell-has-info { background: linear-gradient(155deg, #e0e7ff, #c7d2fe); box-shadow: inset 0 0 0 1px rgba(79, 70, 229, 0.2); }
.mcal-cell-has-info .mcal-cell-date { color: #3730a3; font-weight: 800; }
.mcal-cell-has-info .mcal-cell-icon { color: #4338ca; }
/* "Today" ring still needs to read even when the cell is also colored for an event */
.mcal-cell-has-auto { background: linear-gradient(155deg, #ccfbf1, #99f6e4); box-shadow: inset 0 0 0 1px rgba(13, 148, 136, 0.3); }
.mcal-cell-has-auto .mcal-cell-date { color: #115e59; font-weight: 800; }
.mcal-cell-has-auto .mcal-cell-icon { color: #0f766e; }
.mcal-cell-today.mcal-cell-has-auto,
.mcal-cell-today.mcal-cell-has-critical,
.mcal-cell-today.mcal-cell-has-warning,
.mcal-cell-today.mcal-cell-has-info { box-shadow: inset 0 0 0 1.5px #4338ca; }

.mcal-cell-icon-wrap { display: flex; align-items: center; justify-content: center; gap: 2px; margin-top: 1px; }
.mcal-cell-icon { flex-shrink: 0; }
.mcal-cell-count {
  font-size: 0.66rem; font-weight: 800; color: inherit; background: rgba(255, 255, 255, 0.6); border-radius: 9999px;
  min-width: 0.85rem; height: 0.85rem; display: inline-flex; align-items: center; justify-content: center; line-height: 1;
}

.mcal-tooltip {
  position: fixed; transform: translate(0, -50%); z-index: 9999; width: 220px; pointer-events: none;
  background: #fff; border-radius: 0.9rem; padding: 0.7rem 0.85rem; box-shadow: 0 20px 40px -10px rgba(15, 23, 42, 0.35), 0 0 0 1px rgba(15, 23, 42, 0.05);
}
.mcal-tooltip img { display: block; width: 100%; height: 100px; object-fit: cover; border-radius: 0.6rem; margin-bottom: 0.5rem; }
.mcal-tooltip-title { font-size: 0.78rem; font-weight: 800; color: #1f2937; margin-bottom: 0.35rem; }
.mcal-tooltip-row { display: flex; justify-content: space-between; gap: 0.5rem; font-size: 0.68rem; color: #6b7280; padding: 0.1rem 0; }
.mcal-tooltip-row strong { color: #111827; font-weight: 700; text-align: right; }
.mcal-tooltip-hint { margin-top: 0.4rem; font-size: 0.66rem; color: #9ca3af; font-style: italic; }

html.dark .mcal { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.1); }
html.dark .mcal-cell { background: rgba(255, 255, 255, 0.04); }
html.dark .mcal-cell-today { background: rgba(99, 102, 241, 0.15); }
html.dark .mcal-cell-has-critical { background: linear-gradient(155deg, rgba(225, 29, 72, 0.35), rgba(190, 18, 60, 0.3)); }
html.dark .mcal-cell-has-critical .mcal-cell-date { color: #fecdd3; }
html.dark .mcal-cell-has-critical .mcal-cell-icon { color: #fda4af; }
html.dark .mcal-cell-has-warning { background: linear-gradient(155deg, rgba(217, 119, 6, 0.35), rgba(180, 83, 9, 0.3)); }
html.dark .mcal-cell-has-warning .mcal-cell-date { color: #fde68a; }
html.dark .mcal-cell-has-warning .mcal-cell-icon { color: #fcd34d; }
html.dark .mcal-cell-has-auto { background: linear-gradient(155deg, rgba(13, 148, 136, 0.35), rgba(15, 118, 110, 0.3)); }
html.dark .mcal-cell-has-auto .mcal-cell-date { color: #99f6e4; }
html.dark .mcal-cell-has-auto .mcal-cell-icon { color: #5eead4; }
html.dark .mcal-event-auto { background: rgba(13, 148, 136, 0.28); color: #99f6e4; }
html.dark .mcal-cell-has-info { background: linear-gradient(155deg, rgba(99, 102, 241, 0.32), rgba(67, 56, 202, 0.28)); }
html.dark .mcal-cell-has-info .mcal-cell-date { color: #c7d2fe; }
html.dark .mcal-cell-has-info .mcal-cell-icon { color: #a5b4fc; }
html.dark .mcal-cell-count { background: rgba(15, 23, 42, 0.4); }
html.dark .mcal-tooltip { background: #1e293b; box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.08); }
html.dark .mcal-tooltip-title { color: #f1f5f9; }
html.dark .mcal-tooltip-row strong { color: #e2e8f0; }
</style>

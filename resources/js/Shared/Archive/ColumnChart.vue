<template>
  <div ref="box" class="arx-cols" @mouseleave="hover = null">
    <svg :width="w" :height="h" :viewBox="'0 0 ' + w + ' ' + h" role="img" :aria-label="ariaLabel">
      <g v-for="t in ticks" :key="t.v" class="arx-cols-grid">
        <line :x1="padL" :x2="w - 4" :y1="t.y" :y2="t.y" />
        <text :x="padL - 6" :y="t.y + 3.5" text-anchor="end">{{ t.v }}</text>
      </g>
      <g v-for="(b, i) in bars" :key="b.month" class="arx-col" :class="{ 'is-hover': hover === i, 'is-last': i === bars.length - 1 }" @mouseenter="hover = i" @focus="hover = i" @blur="hover = null" tabindex="0">
        <rect class="arx-col-hit" :x="b.x - 2" :y="padT" :width="b.w + 4" :height="h - padT - padB" />
        <path class="arx-col-bar" :d="b.d" :style="{ transitionDelay: i * 40 + 'ms', transform: shown ? 'scaleY(1)' : 'scaleY(0)', transformOrigin: b.x + b.w / 2 + 'px ' + (h - padB) + 'px' }" />
        <text class="arx-col-label" :x="b.x + b.w / 2" :y="h - 6" text-anchor="middle">{{ b.label }}</text>
        <title>{{ b.month }}: {{ b.count }}</title>
      </g>
    </svg>
    <div v-if="hover !== null && bars[hover]" class="arx-cols-tip" :style="{ left: ((bars[hover].x + bars[hover].w / 2) / w * 100) + '%' }">
      <strong>{{ bars[hover].count.toLocaleString() }}</strong> {{ unit }}<span>{{ monthName(bars[hover].month) }}</span>
    </div>
  </div>
</template>

<script>
export default {
  props: {
    data: { type: Array, default: () => [] }, // [{ month: '2026-10', label: 'Oct', count }]
    unit: { type: String, default: 'documents' },
  },

  data() {
    return { hover: null, shown: false, w: 600, h: 190, padL: 34, padT: 12, padB: 24, ro: null };
  },

  computed: {
    max() {
      const m = Math.max(...this.data.map(d => d.count), 1);
      const pow = Math.pow(10, Math.floor(Math.log10(m)));
      return Math.ceil(m / pow) * pow || 1; // round the axis up to a clean number
    },
    ticks() {
      return [0, 0.5, 1].map(f => ({ v: Math.round(this.max * f).toLocaleString(), y: this.padT + (1 - f) * (this.h - this.padT - this.padB) }));
    },
    bars() {
      const n = this.data.length || 1;
      const slot = (this.w - this.padL - 4) / n;
      const bw = Math.min(slot * 0.62, 28);
      const base = this.h - this.padB;
      return this.data.map((d, i) => {
        const x = this.padL + i * slot + (slot - bw) / 2;
        const bh = d.count > 0 ? Math.max((d.count / this.max) * (base - this.padT), 3) : 0;
        const r = Math.min(4, bw / 2, bh);
        // rounded at the data end only, square on the baseline
        const dPath = bh ? `M${x} ${base} V${base - bh + r} Q${x} ${base - bh} ${x + r} ${base - bh} H${x + bw - r} Q${x + bw} ${base - bh} ${x + bw} ${base - bh + r} V${base} Z` : '';
        return { x, w: bw, d: dPath, label: d.label, month: d.month, count: d.count };
      });
    },
    ariaLabel() {
      return 'Documents added per month: ' + this.data.map(d => d.month + ' ' + d.count).join(', ');
    },
  },

  mounted() {
    // Draw at the real pixel width so text and bars are never stretched.
    const measure = () => { const el = this.$refs.box; if (el && el.clientWidth) this.w = Math.max(Math.round(el.clientWidth), 280); };
    measure();
    if (typeof ResizeObserver !== 'undefined') { this.ro = new ResizeObserver(measure); this.ro.observe(this.$refs.box); }
    requestAnimationFrame(() => requestAnimationFrame(() => { this.shown = true; }));
  },

  beforeUnmount() {
    if (this.ro) this.ro.disconnect();
  },

  methods: {
    monthName(ym) {
      const [y, m] = ym.split('-');
      return new Date(Number(y), Number(m) - 1, 1).toLocaleString(undefined, { month: 'long', year: 'numeric' });
    },
  },
};
</script>

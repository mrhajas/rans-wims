<template>
  <div class="sp-wrap" :class="{ 'is-compact': compact, 'is-desk': geo.desk }">
    <div class="sp-stage" :style="{ height: u(geo.H) }">
      <div v-for="s in geo.sheets" :key="'s' + s.i" class="sp-sheet" :class="{ 'is-back': s.back }" :style="{ left: u(s.x), top: u(s.y), width: u(geo.sw), height: u(geo.sh) }">
        <span v-if="!compact && s.label" class="sp-cap" :style="{ fontSize: u(3.4) }">{{ s.label }}</span>
      </div>
      <transition-group tag="div" class="sp-items" :css="false" @enter="onEnter" @leave="onLeave">
        <div v-for="it in geo.items" :key="it.key" class="sp-item" :data-id="it.id" :style="{ left: u(it.x), top: u(it.y), width: u(it.w), height: u(85) }">
          <div v-for="side in it.sides" :key="side" class="sp-half" :style="{ left: side === 'back' && it.sides.length > 1 ? '51.3%' : '0', width: it.sides.length > 1 ? '48.7%' : '100%' }">
            <card-face v-if="faces[it.id]" :html="faces[it.id][side]" :css="css" />
            <div v-else class="sp-ph" :style="{ background: (meta[it.id] && meta[it.id].color) || '#64748b', color: (meta[it.id] && meta[it.id].ink) || '#fff' }"><b>{{ meta[it.id] && meta[it.id].number }}</b></div>
          </div>
          <span v-if="!compact && meta[it.id]" class="sp-no" :style="{ fontSize: u(3.2) }">{{ meta[it.id].number }}</span>
        </div>
      </transition-group>
    </div>
  </div>
</template>

<script>
import CardFace from './CardFace.vue';

// How the cards sit on the paper. The same arithmetic as App\Services\IdPass\CardPdf::grid(), so what is drawn here is what is printed.
const MARGIN = 8;
export function sheetGrid(layout, landscape) {
  if (layout === 'a4_duplex') { const gap = 5; return { cols: Math.max(1, Math.floor((210 - 2 * MARGIN + gap) / (55 + gap))), rows: Math.max(1, Math.floor((297 - 2 * MARGIN + gap) / (85 + gap))), sw: 210, sh: 297, gap }; }
  const [sw, sh] = landscape ? [297, 210] : [210, 297]; const gap = 6;
  return { cols: Math.max(1, Math.floor((sw - 2 * MARGIN + gap) / (113 + gap))), rows: Math.max(1, Math.floor((sh - 2 * MARGIN + gap) / (85 + gap))), sw, sh, gap };
}

export default {
  components: { CardFace },
  props: {
    ids: { type: Array, default: () => [] }, faces: { type: Object, default: () => ({}) }, meta: { type: Object, default: () => ({}) }, css: { type: String, default: '' },
    layout: { type: String, default: 'a4_side' }, orientation: { type: String, default: 'landscape' }, compact: Boolean, horizontal: Boolean,
    locate: { type: Function, default: null },
  },
  computed: {
    geo() {
      const n = this.ids.length, landscape = this.orientation !== 'portrait', SG = this.compact ? 4 : 7;
      let g, desk = false;
      if (this.layout === 'card') {
        const gap = 6, cols = 2, rows = Math.max(1, Math.ceil(n / cols));
        g = { cols, rows, gap, sw: 2 * 113 + gap + 2 * MARGIN, sh: rows * (85 + gap) - gap + 2 * MARGIN }; desk = true;
      } else g = sheetGrid(this.layout, landscape);
      const per = g.cols * g.rows, duplex = this.layout === 'a4_duplex', pairW = 113;
      const used = Math.max(1, duplex ? Math.ceil(n / per) * 2 : this.layout === 'card' ? 1 : Math.ceil(n / per));
      const horiz = this.horizontal;
      const W = horiz ? used * g.sw + (used - 1) * SG : g.sw, H = horiz ? g.sh : used * g.sh + (used - 1) * SG;
      const at = i => horiz ? { x: i * (g.sw + SG), y: 0 } : { x: 0, y: i * (g.sh + SG) };
      const sheets = [];
      for (let i = 0; i < used; i++) sheets.push({ i, ...at(i), back: duplex && i % 2 === 1, label: duplex ? (i % 2 ? this.$t('Backs') : this.$t('Fronts')) + ' · ' + (Math.floor(i / 2) + 1) : this.layout === 'card' ? '' : this.$t('Sheet') + ' ' + (i + 1) });
      const cellW = duplex ? 55 : pairW, totalW = g.cols * cellW + (g.cols - 1) * g.gap, left = (g.sw - totalW) / 2, top = (g.sh - (g.rows * 85 + (g.rows - 1) * g.gap)) / 2;
      const items = [];
      this.ids.forEach((id, k) => {
        const chunk = Math.floor(k / per), j = k % per, col = j % g.cols, row = Math.floor(j / g.cols);
        if (duplex) {
          const f = at(chunk * 2), b = at(chunk * 2 + 1), bc = g.cols - 1 - col;
          items.push({ key: id + 'f', id, sides: ['front'], w: 55, x: f.x + left + col * (55 + g.gap), y: f.y + top + row * (85 + g.gap) });
          items.push({ key: id + 'b', id, sides: ['back'], w: 55, x: b.x + left + bc * (55 + g.gap), y: b.y + top + row * (85 + g.gap) });
        } else {
          const o = at(this.layout === 'card' ? 0 : chunk);
          items.push({ key: String(id), id, sides: ['front', 'back'], w: pairW, x: o.x + left + col * (pairW + g.gap), y: o.y + top + row * (85 + g.gap) });
        }
      });
      return { sw: g.sw, sh: g.sh, W, H, sheets, items, desk };
    },
  },
  methods: {
    u(v) { return (v / this.geo.W * 100) + 'cqw'; },
    stagger() { const n = this._st = (this._st || 0) + 1; if (!this._stReset) { this._stReset = true; requestAnimationFrame(() => { this._st = 0; this._stReset = false; }); } return Math.min(n - 1, 12) * 45; },
    reduced() { return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; },
    // a card flies from its row in the list onto the sheet, and back when the tick is taken off
    onEnter(el, done) {
      if (this.compact || this.reduced() || !el.animate) return done();
      const id = Number(el.dataset.id), to = el.getBoundingClientRect(), from = this.locate ? this.locate(id) : null, delay = this.stagger();
      if (!to.width) return done();
      el.style.zIndex = 60;
      const end = () => { el.style.zIndex = ''; done(); };
      if (!from) { el.animate([{ opacity: 0, transform: 'scale(.55) translateY(14px)' }, { opacity: 1, transform: 'scale(1.05)', offset: 0.7 }, { opacity: 1, transform: 'none' }], { duration: 520, delay, easing: 'cubic-bezier(.2,.8,.2,1)', fill: 'backwards' }).onfinish = end; return; }
      const dx = from.x - (to.left + to.width / 2), dy = from.y - (to.top + to.height / 2), s = Math.max(0.12, Math.min(0.6, (from.w || 90) / to.width));
      el.animate([{ opacity: 0.1, transform: 'translate(' + dx + 'px,' + dy + 'px) scale(' + s + ') rotate(-6deg)' }, { opacity: 1, offset: 0.2 }, { opacity: 1, transform: 'translate(' + (dx * 0.08) + 'px,' + (dy * 0.08) + 'px) scale(1.08) rotate(1deg)', offset: 0.82 }, { opacity: 1, transform: 'none' }],
        { duration: 760, delay, easing: 'cubic-bezier(.25,.75,.25,1)', fill: 'backwards' }).onfinish = end;
    },
    onLeave(el, done) {
      if (this.compact || this.reduced() || !el.animate) return done();
      const id = Number(el.dataset.id), to = el.getBoundingClientRect(), from = this.locate ? this.locate(id) : null, delay = this.stagger() * 0.5;
      el.style.zIndex = 60; el.style.pointerEvents = 'none';
      if (!from || !to.width) { el.animate([{ opacity: 1, transform: 'none' }, { opacity: 0, transform: 'scale(.6) translateY(-12px)' }], { duration: 320, delay, easing: 'ease-in', fill: 'forwards' }).onfinish = done; return; }
      const dx = from.x - (to.left + to.width / 2), dy = from.y - (to.top + to.height / 2), s = Math.max(0.12, Math.min(0.6, (from.w || 90) / to.width));
      el.animate([{ opacity: 1, transform: 'none' }, { opacity: 1, transform: 'scale(1.06)', offset: 0.18 }, { opacity: 0, transform: 'translate(' + dx + 'px,' + dy + 'px) scale(' + s + ') rotate(5deg)' }], { duration: 560, delay, easing: 'cubic-bezier(.5,0,.7,.4)', fill: 'forwards' }).onfinish = done;
    },
  },
};
</script>

<style scoped>
.sp-wrap { container-type: inline-size; width: 100%; }
.sp-stage { position: relative; width: 100%; transition: height 0.45s cubic-bezier(.2,.8,.2,1); }
.sp-sheet { position: absolute; background: #fff; border-radius: 0.8cqw; box-shadow: 0 1px 0 rgba(15, 37, 64, 0.06), 0 10px 22px -12px rgba(15, 37, 64, 0.45), 0 0 0 1px rgba(15, 37, 64, 0.1); transition: top 0.45s cubic-bezier(.2,.8,.2,1), left 0.45s cubic-bezier(.2,.8,.2,1); }
.sp-sheet.is-back { background: #f4f7fb; }
.is-desk .sp-sheet { background: repeating-linear-gradient(45deg, rgba(21, 101, 192, 0.05) 0 8px, transparent 8px 16px), #f1f5fb; box-shadow: inset 0 0 0 1px rgba(15, 37, 64, 0.08); }
.sp-cap { position: absolute; left: 3%; bottom: 1.2%; color: #64748b; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; pointer-events: none; }
.sp-items { position: absolute; inset: 0; pointer-events: none; }
.sp-item { position: absolute; pointer-events: auto; transition: left 0.5s cubic-bezier(.2,.8,.2,1), top 0.5s cubic-bezier(.2,.8,.2,1), transform 0.22s ease, filter 0.22s ease; will-change: transform; }
.sp-half { position: absolute; top: 0; }
.sp-ph { aspect-ratio: 55 / 85; width: 100%; border-radius: 4%; display: grid; place-items: center; font-size: 2.4cqw; box-shadow: 0 0 0 1px rgba(15, 37, 64, 0.1); opacity: 0.9; }
.sp-no { position: absolute; left: 0; top: 100%; margin-top: 0.4cqw; color: #475569; font-weight: 800; letter-spacing: 0.04em; white-space: nowrap; pointer-events: none; }
@media (hover: hover) { .is-compact .sp-item { pointer-events: none; } .sp-wrap:not(.is-compact) .sp-item:hover { transform: scale(1.7); z-index: 30; filter: drop-shadow(0 14px 18px rgba(15, 37, 64, 0.35)); } }
@media (prefers-reduced-motion: reduce) { .sp-item, .sp-stage, .sp-sheet { transition: none; } }
</style>

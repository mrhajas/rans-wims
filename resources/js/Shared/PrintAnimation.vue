<template>
  <Teleport to="body">
    <transition name="pa-fade">
      <div v-if="show" class="pa-back print:hidden" role="status" aria-live="polite" @click.self="finish(false)">
        <div class="pa-card" :class="{ 'is-ready': ready }" :style="{ '--feed': feed + 'ms', '--start': start + 'ms' }">
          <div class="pa-stage" aria-hidden="true">
            <!-- the sheet rises out of the printer; every line is written as it comes out -->
            <div class="pa-window">
              <div class="pa-sheet">
                <div class="pa-row pa-head" :style="rowStyle(0)"><small>{{ brand }}</small><b>{{ title }}</b><code v-if="code">{{ code }}</code></div>
                <div v-for="(r, i) in rows" :key="r.k" class="pa-row pa-line" :style="rowStyle(i + 1)"><span>{{ r.k }}</span><i></i><b>{{ r.v }}</b></div>
                <div class="pa-row pa-foot" :style="rowStyle(rows.length + 1)"><span>{{ footer }}</span><em class="pa-ok"><icons name="tick" class="w-3 h-3" /></em></div>
              </div>
            </div>
            <div class="pa-printer">
              <span class="pa-slot"></span>
              <span class="pa-body"><span class="pa-roll"></span><span class="pa-led"></span></span>
              <span class="pa-tray"></span>
            </div>
          </div>
          <p class="pa-msg">{{ ready ? $t('Ready to print') : $t('Printing your sheet...') }}</p>
          <div class="pa-bar"><span class="pa-bar-fill"></span></div>
          <button type="button" class="pa-skip" @click="finish(false)">{{ $t('Cancel') }}</button>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<script>
// A calm "printing" moment before the browser print dialog: the printer pushes a sheet up and out, writing the REAL data of the record line by line, then the dialog opens.
const HEAD = 70; // px: header block of the sheet
const ROW = 19; // px: one line
const WINDOW = 230; // px: the visible part of the sheet above the printer

export default {
  props: {
    show: Boolean,
    title: { type: String, default: '' },
    code: { type: String, default: '' },
    rows: { type: Array, default: () => [] }, // [{ k: 'Category', v: 'General' }, ...]
    brand: { type: String, default: 'RANS WIMS' },
    footer: { type: String, default: '' },
    duration: { type: Number, default: 2400 },
  },
  emits: ['done', 'cancel'],
  data() { return { ready: false, t1: null, t2: null, reduce: false }; },
  computed: {
    start() { return this.reduce ? 0 : 350; },
    feed() { return this.reduce ? 10 : Math.max(900, this.duration - 350); },
    sheetHeight() { return HEAD + (this.rows.length + 1) * ROW + 14; },
  },
  watch: {
    show(v) {
      clearTimeout(this.t1); clearTimeout(this.t2); this.ready = false;
      if (!v) return;
      this.reduce = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;
      const total = this.start + this.feed;
      this.t1 = setTimeout(() => { this.ready = true; }, total);
      this.t2 = setTimeout(() => this.finish(true), total + (this.reduce ? 150 : 550));
    },
  },
  beforeUnmount() { clearTimeout(this.t1); clearTimeout(this.t2); },
  methods: {
    finish(ok) { clearTimeout(this.t1); clearTimeout(this.t2); this.$emit(ok ? 'done' : 'cancel'); },
    // a line is written the moment the rising sheet brings it above the printer
    rowStyle(i) {
      const y = i === 0 ? 0 : HEAD + (i - 1) * ROW;
      const H = this.sheetHeight;
      const frac = Math.min(1, Math.max(0, 1 - (WINDOW - y) / H));
      return { '--d': Math.round(this.start + this.feed * 0.9 * frac) + 'ms' };
    },
  },
};
</script>

<style scoped>
.pa-back { position: fixed; inset: 0; z-index: 9999; display: grid; place-items: center; background: rgba(15, 37, 64, 0.5); backdrop-filter: blur(3px); }
.pa-card { width: min(340px, calc(100vw - 32px)); padding: 22px 20px 16px; border-radius: 26px; background: var(--lg-card, #fff); box-shadow: 0 34px 64px -26px rgba(15, 37, 64, 0.75), inset 0 0 0 1px rgba(148, 163, 184, 0.35); text-align: center; animation: paPop 0.4s cubic-bezier(0.2, 1.15, 0.4, 1) both; }
.pa-stage { position: relative; width: 250px; height: 330px; margin: 0 auto 6px; }
/* the part of the sheet above the printer */
.pa-window { position: absolute; left: 20px; right: 20px; top: 0; height: 240px; overflow: hidden; }
.pa-sheet { position: absolute; left: 0; right: 0; bottom: 0; padding: 8px 10px 8px; border-radius: 7px 7px 0 0; background: #fff; box-shadow: inset 0 0 0 1px #d3deec, 0 8px 18px -10px rgba(15, 37, 64, 0.5); text-align: left; transform: translateY(104%); animation: paRise var(--feed) var(--start) linear forwards; will-change: transform; }
.pa-row { clip-path: inset(0 100% 0 0); animation: paWrite 0.38s var(--d) cubic-bezier(0.3, 0.7, 0.3, 1) forwards; }
.pa-head { height: 62px; display: flex; flex-direction: column; align-items: flex-start; gap: 3px; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid #dbe5f2; overflow: hidden; }
.pa-head small { font-size: 0.5rem; font-weight: 800; letter-spacing: 0.12em; color: #7b92b2; }
.pa-head b { display: block; max-height: 2.5em; font-size: 0.8rem; line-height: 1.25; color: #1c3f66; overflow: hidden; overflow-wrap: anywhere; }
.pa-head code { flex: none; font-size: 0.56rem; font-weight: 800; padding: 1px 6px; border-radius: 6px; background: #e4edf9; color: #2b5a8f; }
.pa-line { height: 19px; display: flex; align-items: center; gap: 5px; font-size: 0.6rem; color: #55657a; }
.pa-line span { flex: none; font-weight: 700; }
.pa-line i { flex: 1; align-self: end; margin-bottom: 5px; border-bottom: 1px dotted #b9c8dc; }
.pa-line b { flex: none; max-width: 62%; font-size: 0.62rem; color: #1c3f66; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-variant-numeric: tabular-nums; }
.pa-foot { margin-top: 5px; height: 19px; display: flex; align-items: center; justify-content: space-between; font-size: 0.5rem; color: #8aa0bf; letter-spacing: 0.04em; }
.pa-ok { width: 18px; height: 18px; border-radius: 50%; display: grid; place-items: center; background: #10b981; color: #fff; transform: scale(0); font-style: normal; }
.is-ready .pa-ok { animation: paStamp 0.3s cubic-bezier(0.2, 1.4, 0.4, 1) forwards; }
/* the printer */
.pa-printer { position: absolute; left: 0; right: 0; top: 230px; height: 98px; z-index: 2; }
.pa-slot { position: absolute; left: 14px; right: 14px; top: 0; height: 14px; border-radius: 8px 8px 0 0; background: #0b1f3a; box-shadow: inset 0 3px 5px rgba(0, 0, 0, 0.55); }
.pa-body { position: absolute; left: 0; right: 0; top: 8px; height: 62px; border-radius: 18px; background: linear-gradient(180deg, #2f6199, #1c3f66 70%); box-shadow: 0 14px 22px -12px rgba(15, 37, 64, 0.85), inset 0 2px 0 rgba(255, 255, 255, 0.18); }
.pa-roll { position: absolute; left: 22px; right: 22px; top: 8px; height: 5px; border-radius: 3px; background: rgba(255, 255, 255, 0.18); }
.pa-led { position: absolute; right: 18px; bottom: 14px; width: 9px; height: 9px; border-radius: 50%; background: #f59e0b; animation: paBlink 0.45s ease-in-out infinite alternate; }
.is-ready .pa-led { background: #10b981; animation: none; box-shadow: 0 0 0 5px rgba(16, 185, 129, 0.25); }
.pa-tray { position: absolute; left: 26px; right: 26px; top: 66px; height: 12px; border-radius: 0 0 10px 10px; background: #123055; }
.pa-msg { margin: 6px 0 10px; font-size: 0.92rem; font-weight: 800; color: var(--lg-ink, #1e293b); }
.pa-bar { height: 5px; border-radius: 4px; background: rgba(148, 163, 184, 0.25); overflow: hidden; }
.pa-bar-fill { display: block; height: 100%; width: 100%; border-radius: inherit; background: linear-gradient(90deg, #3b82c4, #1c3f66); transform-origin: left; transform: scaleX(0); animation: paBar var(--feed) var(--start) linear forwards; }
.is-ready .pa-bar-fill { background: #10b981; }
.pa-skip { margin-top: 10px; border: 0; background: none; font-size: 0.74rem; font-weight: 700; color: var(--lg-mute, #64748b); cursor: pointer; text-decoration: underline; }
.pa-fade-enter-active, .pa-fade-leave-active { transition: opacity 0.22s; } .pa-fade-enter-from, .pa-fade-leave-to { opacity: 0; }
@keyframes paPop { from { transform: scale(0.88); opacity: 0; } to { transform: none; opacity: 1; } }
@keyframes paRise { 0% { transform: translateY(104%); } 92% { transform: translateY(1.5%); } 100% { transform: translateY(0); } }
@keyframes paWrite { to { clip-path: inset(0 0 0 0); } }
@keyframes paStamp { from { transform: scale(0) rotate(-30deg); } to { transform: none; } }
@keyframes paBar { to { transform: scaleX(1); } }
@keyframes paBlink { to { opacity: 0.35; } }
@media (prefers-reduced-motion: reduce) { .pa-card, .pa-sheet, .pa-row, .pa-bar-fill, .pa-led, .is-ready .pa-ok { animation-duration: 0.01s !important; animation-delay: 0s !important; } }
</style>

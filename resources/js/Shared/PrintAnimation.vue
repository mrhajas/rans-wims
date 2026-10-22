<template>
  <Teleport to="body">
    <transition name="pa-fade">
      <div v-if="show" class="pa-back print:hidden" role="status" aria-live="polite" @click.self="finish(false)">
        <div class="pa-card" :class="{ 'is-ready': ready }" :style="{ '--feed': feed + 'ms', '--start': start + 'ms' }">
          <div class="pa-stage" aria-hidden="true" :style="{ '--wh': sheetHeight + 'px', '--slide': Math.round(feed * 0.8) + 'ms', height: PRINTER + sheetHeight + 24 + 'px' }">
            <!-- the printer is on top and the finished sheet slides down out of it with its contents; when it has arrived the green tick and its label appear -->
            <div class="pa-window">
              <div ref="sheet" class="pa-sheet" :class="{ 'is-measuring': !measured }">
                <div class="pa-row pa-head"><small>{{ brand }}</small><b>{{ title }}</b><code v-if="code">{{ code }}</code></div>
                <div v-for="r in shownRows" :key="r.k" class="pa-row pa-line"><span>{{ r.k }}</span><i></i><b>{{ r.v }}</b></div>
                <div class="pa-row pa-foot"><span>{{ footer }}</span><em class="pa-ok"><icons name="tick" class="w-3 h-3" /></em></div>
              </div>
            </div>
            <div class="pa-printer">
              <span class="pa-tray"></span>
              <span class="pa-body"><span class="pa-roll"></span><span class="pa-led"></span></span>
              <span class="pa-slot"></span>
            </div>
          </div>
          <p class="pa-msg">{{ ready ? $t('Ready to print') : printingLabel }}</p>
          <div class="pa-bar"><span class="pa-bar-fill"></span></div>
          <button type="button" class="pa-skip" @click="finish(false)">{{ $t('Cancel') }}</button>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<script>
// A calm "printing" moment before the browser print dialog: the printer sits on top and the sheet, carrying the REAL data of the record (the first lines only on a long report), slides down out of it; the green tick then stamps the sheet and the dialog opens.
const ROW = 19; // px: one line
const MAX_ROWS = 6; // a long report shows only its first lines
const PRINTER = 72; // px: the printer above the sheet (the sheet starts at its slot)

export default {
  props: {
    show: Boolean,
    title: { type: String, default: '' },
    report: { type: String, default: '' }, // the kind of report, for the label under the printer (Item gives Printing Item Report)
    code: { type: String, default: '' },
    rows: { type: Array, default: () => [] }, // [{ k: 'Category', v: 'General' }, ...]
    brand: { type: String, default: 'RANS WIMS' },
    footer: { type: String, default: '' },
    duration: { type: Number, default: 4600 }, // a slow, calm print so the moment can be enjoyed
  },
  emits: ['done', 'cancel'],
  data() { return { ready: false, t1: null, t2: null, reduce: false, PRINTER, measured: 0 }; },
  computed: {
    start() { return this.reduce ? 0 : 500; },
    feed() { return this.reduce ? 10 : Math.max(900, this.duration - 500); },
    // the real height of the paper once it is drawn (so every line fits on it); until then an estimate
    sheetHeight() { return this.measured || 112 + this.shownRows.length * ROW; },
    shownRows() { return (this.rows || []).slice(0, MAX_ROWS); },
    // Printing Item Report... (the word Report is never written twice)
    printingLabel() {
      const r = (this.report || '').trim();
      if (!r) return this.$t('Printing your sheet...');
      return this.$t('Printing :name...', { name: /report/i.test(r) ? r : r + ' ' + this.$t('Report') });
    },
  },
  watch: {
    show(v) {
      clearTimeout(this.t1); clearTimeout(this.t2); this.ready = false; this.measured = 0;
      if (!v) return;
      this.$nextTick(() => { const el = this.$refs.sheet; if (el) this.measured = Math.ceil(el.scrollHeight) + 4; });
      this.reduce = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;
      const total = this.start + this.feed;
      this.t1 = setTimeout(() => { this.ready = true; }, total);
      this.t2 = setTimeout(() => this.finish(true), total + (this.reduce ? 150 : 550));
    },
  },
  beforeUnmount() { clearTimeout(this.t1); clearTimeout(this.t2); },
  methods: {
    finish(ok) { clearTimeout(this.t1); clearTimeout(this.t2); this.$emit(ok ? 'done' : 'cancel'); },
  },
};
</script>

<style scoped>
.pa-back { position: fixed; inset: 0; z-index: 9999; display: grid; place-items: center; background: rgba(15, 37, 64, 0.5); backdrop-filter: blur(3px); }
.pa-card { width: min(340px, calc(100vw - 32px)); padding: 22px 20px 16px; border-radius: 26px; background: var(--lg-card, #fff); box-shadow: 0 34px 64px -26px rgba(15, 37, 64, 0.75), inset 0 0 0 1px rgba(148, 163, 184, 0.35); text-align: center; animation: paPop 0.4s cubic-bezier(0.2, 1.15, 0.4, 1) both; }
.pa-stage { position: relative; width: 250px; margin: 0 auto 6px; }
/* the sheet below the printer: it is uncovered from the slot downwards */
.pa-window { position: absolute; left: 20px; right: 20px; top: 66px; height: calc(var(--wh) + 30px); clip-path: inset(0 -34px -46px -34px); } /* hides the paper above the slot, lets its shadow show on the sides and below */
/* the paper itself slides down out of the slot; it has its own edge and a shadow so it shows clearly on the white card */
.pa-sheet { position: absolute; left: 0; right: 0; top: 0; height: var(--wh); padding-bottom: 12px; padding: 14px 10px 8px; border-radius: 0 0 6px 6px; background: linear-gradient(180deg, #ffffff 0%, #fbfcfe 70%, #f1f5fb 100%); border: 1px solid #b9c8dd; border-top: 0; box-shadow: 0 16px 22px -10px rgba(15, 37, 64, 0.55), 0 4px 8px -2px rgba(15, 37, 64, 0.3), inset 0 -6px 10px -8px rgba(15, 37, 64, 0.25); text-align: left; transform: translateY(-104%); animation: paSlide var(--slide) var(--start) cubic-bezier(0.28, 0.62, 0.3, 1) forwards; will-change: transform; }
/* the printer's shade falling on the paper where it leaves the slot */
.pa-sheet.is-measuring { height: auto; }
.pa-sheet::before { content: ''; position: absolute; left: 0; right: 0; top: 0; height: 9px; background: linear-gradient(180deg, rgba(15, 37, 64, 0.2), rgba(15, 37, 64, 0)); pointer-events: none; z-index: 1; }
.pa-head { min-height: 62px; display: flex; flex-direction: column; align-items: flex-start; gap: 3px; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid #dbe5f2; }
.pa-head small { font-size: 0.5rem; font-weight: 800; letter-spacing: 0.12em; color: #7b92b2; }
.pa-head b { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; font-size: 0.8rem; line-height: 1.25; color: #1c3f66; overflow: hidden; overflow-wrap: anywhere; }
.pa-head code { flex: none; font-size: 0.56rem; font-weight: 800; padding: 1px 6px; border-radius: 6px; background: #e4edf9; color: #2b5a8f; }
.pa-line { height: 19px; display: flex; align-items: center; gap: 5px; font-size: 0.6rem; color: #55657a; }
.pa-line span { flex: 0 1 auto; max-width: 50%; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 700; }
.pa-line i { flex: 1 1 8px; min-width: 8px; align-self: end; margin-bottom: 5px; border-bottom: 1px dotted #b9c8dc; }
.pa-line b { flex: 0 1 auto; max-width: 50%; min-width: 0; font-size: 0.62rem; color: #1c3f66; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-variant-numeric: tabular-nums; }
.pa-foot span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-right: 6px; }
.pa-foot { opacity: 0; transition: opacity 0.35s ease; margin-top: 5px; height: 19px; display: flex; align-items: center; justify-content: space-between; font-size: 0.5rem; color: #8aa0bf; letter-spacing: 0.04em; }
.pa-ok { width: 18px; height: 18px; border-radius: 50%; display: grid; place-items: center; background: #10b981; color: #fff; transform: scale(0); font-style: normal; }
.is-ready .pa-foot { opacity: 1; }
.is-ready .pa-ok { animation: paStamp 0.3s cubic-bezier(0.2, 1.4, 0.4, 1) forwards; }
/* the printer */
.pa-printer { position: absolute; left: 0; right: 0; top: 0; height: 80px; z-index: 2; }
.pa-tray { position: absolute; left: 30px; right: 30px; top: 0; height: 12px; border-radius: 8px 8px 0 0; background: #123055; }
.pa-body { position: absolute; left: 0; right: 0; top: 8px; height: 58px; border-radius: 18px; background: linear-gradient(180deg, #2f6199, #1c3f66 70%); box-shadow: 0 14px 22px -12px rgba(15, 37, 64, 0.85), inset 0 2px 0 rgba(255, 255, 255, 0.18); }
.pa-slot { position: absolute; left: 14px; right: 14px; top: 58px; height: 10px; border-radius: 0 0 8px 8px; background: #0b1f3a; box-shadow: inset 0 -3px 5px rgba(0, 0, 0, 0.55); }
.pa-roll { position: absolute; left: 22px; right: 22px; top: 8px; height: 5px; border-radius: 3px; background: rgba(255, 255, 255, 0.18); }
.pa-led { position: absolute; right: 18px; bottom: 14px; width: 9px; height: 9px; border-radius: 50%; background: #f59e0b; animation: paBlink 0.45s ease-in-out infinite alternate; }
.is-ready .pa-led { background: #10b981; animation: none; box-shadow: 0 0 0 5px rgba(16, 185, 129, 0.25); }
.pa-msg { margin: 6px 0 10px; font-size: 0.92rem; font-weight: 800; color: var(--lg-ink, #1e293b); }
.pa-bar { height: 5px; border-radius: 4px; background: rgba(148, 163, 184, 0.25); overflow: hidden; }
.pa-bar-fill { display: block; height: 100%; width: 100%; border-radius: inherit; background: linear-gradient(90deg, #3b82c4, #1c3f66); transform-origin: left; transform: scaleX(0); animation: paBar var(--feed) var(--start) linear forwards; }
.is-ready .pa-bar-fill { background: #10b981; }
.pa-skip { margin-top: 10px; border: 0; background: none; font-size: 0.74rem; font-weight: 700; color: var(--lg-mute, #64748b); cursor: pointer; text-decoration: underline; }
.pa-fade-enter-active, .pa-fade-leave-active { transition: opacity 0.22s; } .pa-fade-enter-from, .pa-fade-leave-to { opacity: 0; }
@keyframes paPop { from { transform: scale(0.88); opacity: 0; } to { transform: none; opacity: 1; } }
@keyframes paSlide { 0% { transform: translateY(-104%); } 100% { transform: translateY(0); } }
@keyframes paStamp { from { transform: scale(0) rotate(-30deg); } to { transform: none; } }
@keyframes paBar { to { transform: scaleX(1); } }
@keyframes paBlink { to { opacity: 0.35; } }
@media (prefers-reduced-motion: reduce) { .pa-card, .pa-sheet, .pa-row, .pa-bar-fill, .pa-led, .is-ready .pa-ok { animation-duration: 0.01s !important; animation-delay: 0s !important; } }
</style>

<template>
  <section class="lic-vault" :class="{ 'is-running': running, 'is-open': revealed }">
    <span class="lic-vault__scan" aria-hidden="true"></span>
    <div class="lic-vault__head">
      <div class="lic-vault__title"><icons :name="revealed ? 'lock-open' : 'key'" class="lic-vault__lock" /> {{ $t('Licence key') }} <span class="lic-vault__badge">{{ licenceKey ? licenceKey.length : 0 }} {{ $t('characters') }}</span></div>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="lic-btn lic-btn--ghost-w lic-btn--sm" @click="toggle"><icons :name="revealed ? 'eye-off' : 'eye'" />{{ revealed ? $t('Hide key') : $t('Show key') }}</button>
        <button type="button" class="lic-btn lic-btn--ghost-w lic-btn--sm" :disabled="!revealed || running" @click="copy"><icons :name="copied ? 'tick' : 'duplicate'" />{{ copied ? $t('Copied') : $t('Copy key') }}</button>
      </div>
    </div>
    <div class="lic-key" :class="{ masked: !revealed && !running }" aria-live="polite">
      <div v-for="(row, i) in 8" :key="i" class="lic-key__row">
        <i>{{ String(i + 1).padStart(2, '0') }}</i>
        <span v-for="j in 4" :key="j" class="lic-key__blk" :class="cls[i * 4 + j - 1]">{{ cells[i * 4 + j - 1] }}</span>
      </div>
    </div>
    <p class="lic-vault__hint">{{ revealed ? $t('Keep this key private. Anyone who has it can activate this licence.') : $t('The key is hidden. Press Show key to decrypt it.') }}</p>
  </section>
</template>

<script>
const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
const MASK = '••••';

export default {
  props: { licenceKey: String, blocks: Array, startRevealed: { type: Boolean, default: false } },
  data() {
    return { revealed: false, running: false, copied: false, cells: Array(32).fill(MASK), cls: Array(32).fill(''), timers: [], intervals: [] };
  },
  computed: {
    real() { return (this.blocks || []).flat(); },
  },
  mounted() {
    if (this.startRevealed) this.$nextTick(() => this.run(true));
  },
  beforeUnmount() { this.stop(); },
  methods: {
    toggle() { this.run(!this.revealed); },
    stop() {
      this.timers.forEach(clearTimeout); this.intervals.forEach(clearInterval);
      this.timers = []; this.intervals = [];
    },
    rand() { let s = ''; for (let i = 0; i < 4; i++) s += ALPHABET[Math.floor(Math.random() * ALPHABET.length)]; return s; },
    // Each of the 32 blocks scrambles like a decoder, one after another, then settles on its real characters (or hides again in the reverse order).
    run(show) {
      this.stop();
      this.revealed = show;
      this.running = true;
      const total = 32, step = 38, spin = 420;
      for (let n = 0; n < total; n++) {
        const i = show ? n : total - 1 - n;
        this.timers.push(setTimeout(() => {
          this.cls[i] = 'scr';
          const iv = setInterval(() => (this.cells[i] = this.rand()), 45);
          this.intervals.push(iv);
          this.timers.push(setTimeout(() => {
            clearInterval(iv);
            this.cells[i] = show ? this.real[i] : MASK;
            this.cls[i] = show ? 'pop' : 'gone';
            this.timers.push(setTimeout(() => (this.cls[i] = ''), 600));
          }, spin));
        }, n * step));
      }
      this.timers.push(setTimeout(() => (this.running = false), (total - 1) * step + spin + 120));
    },
    async copy() {
      try { await navigator.clipboard.writeText(this.licenceKey); } catch (e) { window.prompt(this.$t('Copy this key'), this.licenceKey); }
      this.copied = true; setTimeout(() => (this.copied = false), 1800);
    },
  },
};
</script>

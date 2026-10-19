<template>
  <div class="wc-voice" :class="{ 'is-playing': playing, 'is-mine': mine, 'is-off': !src }">
    <button type="button" class="wc-voice-btn" :aria-label="playing ? $t('Pause voice note') : $t('Play voice note')" :disabled="!src" @click.stop="toggle">
      <svg v-if="!playing" viewBox="0 0 20 20" class="w-5 h-5 fill-current" aria-hidden="true"><path d="M6.5 4.6a1 1 0 011.5-.86l8.2 5.4a1 1 0 010 1.72L8 16.26a1 1 0 01-1.5-.86V4.6z" /></svg>
      <svg v-else viewBox="0 0 20 20" class="w-5 h-5 fill-current" aria-hidden="true"><rect x="5" y="4" width="3.6" height="12" rx="1.2" /><rect x="11.4" y="4" width="3.6" height="12" rx="1.2" /></svg>
    </button>
    <div class="wc-voice-mid">
      <div ref="wave" class="wc-wave" role="slider" tabindex="0" :aria-label="$t('Seek')" :aria-valuenow="Math.round(progress * 100)" aria-valuemin="0" aria-valuemax="100" @click.stop="seek" @keydown.left.prevent="nudge(-0.1)" @keydown.right.prevent="nudge(0.1)">
        <i v-for="(h, i) in bars" :key="i" :class="{ 'is-done': (i + 1) / bars.length <= progress }" :style="{ height: h + '%', '--i': i }"></i>
      </div>
      <span class="wc-voice-time">{{ playing || progress > 0 ? fmt(current) : fmt(total) }}<small v-if="playing || progress > 0"> / {{ fmt(total) }}</small></span>
    </div>
    <button v-if="src" type="button" class="wc-voice-speed" :aria-label="$t('Playback speed')" @click.stop="cycleSpeed">{{ speed }}×</button>
    <audio ref="audio" :src="src || undefined" preload="metadata" @timeupdate="onTime" @ended="onEnd" @pause="playing = false" @play="playing = true" @loadedmetadata="onMeta"></audio>
  </div>
</template>

<script>
import { chat } from '@/Core/wimschat';

// A voice note: round play button, a waveform that fills as it plays, time and a speed toggle. Only one plays at a time.
export default {
  props: {
    src: { type: String, default: null },
    durationMs: { type: Number, default: 0 },
    seed: { type: [Number, String], default: 1 },
    mine: { type: Boolean, default: false },
  },
  data() {
    return { playing: false, current: 0, real: 0, speed: 1 };
  },
  computed: {
    total() {
      return this.real || (this.durationMs || 0) / 1000;
    },
    progress() {
      return this.total ? Math.min(1, this.current / this.total) : 0;
    },
    bars() {
      // a stable, lively-looking waveform per note (the real audio is never decoded just to draw bars)
      let s = 0;
      for (const c of String(this.seed)) s = (s * 31 + c.charCodeAt(0)) >>> 0;
      const out = [];
      for (let i = 0; i < 32; i++) {
        s = (s * 1664525 + 1013904223) >>> 0;
        const base = 28 + (s % 62);
        out.push(Math.round(base * (0.55 + 0.45 * Math.sin(i / 3.1 + (s % 7)))) + 12);
      }
      return out;
    },
  },
  watch: {
    'chat.playing'(id) {
      if (id !== this._uid && this.playing) this.$refs.audio.pause();
    },
  },
  created() {
    this._uid = 'v' + Math.random().toString(36).slice(2);
  },
  beforeUnmount() {
    if (this.$refs.audio) this.$refs.audio.pause();
  },
  methods: {
    fmt(s) {
      s = Math.max(0, Math.round(s));
      return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    },
    toggle() {
      const a = this.$refs.audio;
      if (!a) return;
      if (this.playing) { a.pause(); return; }
      chat.playing = this._uid;
      a.playbackRate = this.speed;
      a.play().catch(() => {});
    },
    onMeta() {
      const d = this.$refs.audio.duration;
      if (isFinite(d) && d > 0) this.real = d; // MediaRecorder webm often reports Infinity: then the recorded length is used
    },
    onTime() {
      this.current = this.$refs.audio.currentTime;
    },
    onEnd() {
      this.playing = false;
      this.current = 0;
    },
    seek(e) {
      const r = this.$refs.wave.getBoundingClientRect();
      this.seekTo(Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)));
    },
    nudge(d) {
      this.seekTo(Math.min(1, Math.max(0, this.progress + d)));
    },
    seekTo(f) {
      const a = this.$refs.audio;
      if (!a || !this.total) return;
      try { a.currentTime = f * this.total; this.current = a.currentTime; } catch (e) { /* not seekable yet */ }
    },
    cycleSpeed() {
      this.speed = this.speed === 1 ? 1.5 : this.speed === 1.5 ? 2 : 1;
      if (this.$refs.audio) this.$refs.audio.playbackRate = this.speed;
    },
  },
};
</script>

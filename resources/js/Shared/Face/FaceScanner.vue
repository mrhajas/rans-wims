<template>
  <div class="fs" :class="['fs--' + phase, stage ? 'fs-stage-' + stage : '', { 'fs--flash': flash, 'fs--ok': hintKind === 'good', 'fs--turn': arrow, 'fs--cue': cueActive }]">
    <div class="fs__hud">
      <div class="fs__stage" ref="stage">
        <!-- the oval: live picture, a still of the last frame once captured, and the measuring mesh on top -->
        <div class="fs__oval">
          <video v-show="phase !== 'done'" ref="video" class="fs__video" autoplay muted playsinline></video>
          <canvas v-show="phase === 'done'" ref="snap" class="fs__snap"></canvas>
          <canvas ref="mesh" class="fs__mesh"></canvas>
          <span v-if="phase === 'running' && !capturing" class="fs__sweep"></span>
          <span v-if="capturing" class="fs__lock"></span>
        </div>

        <!-- the ring: spinning ticks, progress, an orbiting WIMS-gold spark and the turn-direction arc -->
        <svg class="fs__ring" viewBox="0 0 200 250" aria-hidden="true">
          <defs>
            <linearGradient id="fsg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#22d3ee" /><stop offset="0.55" stop-color="#34d399" /><stop offset="1" stop-color="#facc15" /></linearGradient>
            <filter id="fsglow" x="-30%" y="-30%" width="160%" height="160%"><feGaussianBlur stdDeviation="2.6" result="b" /><feMerge><feMergeNode in="b" /><feMergeNode in="SourceGraphic" /></feMerge></filter>
          </defs>
          <ellipse class="fs__r-ticks" cx="100" cy="125" rx="95" ry="119" />
          <ellipse class="fs__r-ticks fs__r-ticks--b" cx="100" cy="125" rx="90" ry="114" />
          <ellipse class="fs__r-track" cx="100" cy="125" rx="84" ry="108" />
          <ellipse class="fs__r-fill" cx="100" cy="125" rx="84" ry="108" pathLength="100" :style="{ strokeDasharray: progress * 100 + ' 100' }" filter="url(#fsglow)" />
          <path v-if="arrow === 'left'" class="fs__r-dir" d="M100 17 A84 108 0 0 0 100 233" filter="url(#fsglow)" />
          <path v-if="arrow === 'right'" class="fs__r-dir fs__r-dir--r" d="M100 17 A84 108 0 0 1 100 233" filter="url(#fsglow)" />
          <g v-if="phase === 'running' || stage === 'verifying'" class="fs__spark">
            <circle r="4.2" fill="#facc15" filter="url(#fsglow)"><animateMotion dur="3.6s" repeatCount="indefinite" path="M100 17 A84 108 0 1 1 99.9 17" /></circle>
            <circle r="2.4" fill="#22d3ee" opacity="0.9"><animateMotion dur="3.6s" begin="-1.8s" repeatCount="indefinite" path="M100 17 A84 108 0 1 1 99.9 17" /></circle>
          </g>
        </svg>

        <span class="fs__corner fs__corner--tl"></span><span class="fs__corner fs__corner--tr"></span><span class="fs__corner fs__corner--bl"></span><span class="fs__corner fs__corner--br"></span>

        <!-- turn signal: a glass chip on the side to turn towards, with the WIMS mark leading chevrons -->
        <div v-if="arrow" class="fs__signal" :class="'is-' + arrow">
          <span class="fs__chev"><i></i><i></i><i></i></span>
          <span class="fs__signal-mark"><tec-application-mark /></span>
          <small>{{ arrow === 'left' ? $t('Left') : $t('Right') }}</small>
        </div>

        <!-- the cue: when the ring flashes, blink once -->
        <div v-if="cueActive" class="fs__cue" aria-live="assertive">
          <icons name="eye" class="w-4 h-4" />
          <b>{{ $t('Blink now') }}</b>
        </div>

        <div v-if="phase === 'loading'" class="fs__cover" :class="{ 'fs__cover--live': cameraOn }"><span class="fs__orbit"><tec-application-mark /></span><b>{{ $t('Getting the face scanner ready...') }}</b><face-load-bar class="fs__loadbar" :pct="faceLoad.pct" :label="''" /><small>{{ $t('The first time can take a little longer.') }}</small></div>
        <div v-if="phase === 'error'" class="fs__cover fs__cover--bad"><icons name="exclamation" class="w-9 h-9" /><b>{{ errorText }}</b></div>

        <!-- after the scan: the WIMS mark spins inside the oval while the server checks, then a tick or a cross -->
        <div v-if="phase === 'done'" class="fs__verify" :class="'is-' + (stage || 'captured')">
          <template v-if="stage === 'ok'">
            <svg class="fs__bigtick" viewBox="0 0 52 52"><circle cx="26" cy="26" r="23" /><path d="M15 27l8 8 14-17" /></svg>
            <b>{{ stageText || $t('Welcome') }}</b>
          </template>
          <template v-else-if="stage === 'bad'">
            <icons name="exclamation" class="w-12 h-12" />
            <b>{{ stageText || $t('Not recognised') }}</b>
          </template>
          <template v-else>
            <span class="fs__logo"><i class="fs__logo-r1"></i><i class="fs__logo-r2"></i><i class="fs__logo-spin"></i><span class="fs__logo-mark"><tec-application-mark /></span></span>
            <span class="fs__pct">{{ pctShown }}<small>%</small></span>
            <b>{{ stageText || $t('Verifying your face...') }}</b>
          </template>
        </div>
      </div>
    </div>

    <div class="fs__panel">
      <p class="fs__prompt" :key="promptKey">{{ prompt }}</p>
      <p class="fs__hint" :class="'is-' + hintKind"><i class="fs__hint-dot"></i><span>{{ phase === 'done' ? '' : hint }}</span></p>

      <!-- live guide for the step in progress -->
      <div v-if="phase === 'running'" class="fs__guide">
        <div v-if="curK === 'left' || curK === 'right'" class="fs__gauge" :class="'to-' + curK" :style="{ '--zone': zoneEdge + '%' }">
          <span class="fs__zone fs__zone--l"></span><span class="fs__zone fs__zone--r"></span>
          <span class="fs__flow"><i></i><i></i><i></i><i></i><i></i></span>
          <span class="fs__knob" :style="{ left: knobX + '%' }"><tec-application-mark /></span>
        </div>
        <div v-else-if="curK === 'blink' || curK === 'cue'" class="fs__eye" :class="{ shut: live.close > 0.55 }">
          <svg viewBox="0 0 96 64" aria-hidden="true">
            <g class="fs__eye-g" :style="eyeStyle">
              <path class="fs__eye-fill" d="M10 32 C26 3 70 3 86 32 C70 61 26 61 10 32 Z" />
              <circle class="fs__eye-iris" cx="48" cy="32" r="17" />
              <circle class="fs__eye-pupil" cx="48" cy="32" r="7.5" />
              <circle cx="42" cy="26" r="3.2" fill="#fff" />
              <path class="fs__eye-edge" d="M10 32 C26 3 70 3 86 32 C70 61 26 61 10 32 Z" />
            </g>
            <g class="fs__eye-lashes"><path d="M26 44 L22 51" /><path d="M37 50 L35 58" /><path d="M48 52 L48 60" /><path d="M59 50 L61 58" /><path d="M70 44 L74 51" /></g>
          </svg>
        </div>
        <div v-else class="fs__holdbar"><span :style="{ width: Math.round(pct * 100) + '%' }"></span></div>
      </div>

      <!-- progress, flat: where you are in the scan, the percentage of this step, and the whole scan -->
      <div v-if="phase === 'running' || phase === 'done'" class="fs__meter" role="status" aria-live="polite">
        <div class="fs__meter-row">
          <span class="fs__meter-step"><span class="fs__meter-ico"><icons :name="stepIcon(curK)" class="w-3.5 h-3.5" /></span><b>{{ $t('Step') }} {{ Math.min(idx + 1, plan.length) }} {{ $t('of') }} {{ plan.length }}</b><small v-if="phase === 'running'">{{ stepLabel(curK, idx) }}</small></span>
          <em v-if="phase === 'running'" class="fs__meter-pct">{{ stepPct }}%</em>
        </div>
        <div v-if="phase === 'running'" class="fs__stepbar"><span :style="{ width: stepPct + '%' }"></span></div>
        <div class="fs__total"><span :style="{ width: Math.round(progress * 100) + '%' }"></span></div>
        <div class="fs__total-label"><icons name="tick" class="w-3 h-3" />{{ $t('Total') }} {{ Math.round(progress * 100) }}%</div>
        <span class="sr-only">{{ announce }}</span>
      </div>
      <ol class="fs__dots" :aria-label="$t('Scan progress')">
        <li v-for="(s, i) in plan" :key="i" :class="{ on: i < idx, now: i === idx && phase === 'running' }">
          <span><template v-if="i < idx"><svg viewBox="0 0 20 20" fill="currentColor" class="fs__dot-tick"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.8 3.8 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd" /></svg></template><icons v-else :name="stepIcon(s.k)" class="w-3.5 h-3.5" /></span>
          <small>{{ stepLabel(s.k, i) }}</small>
        </li>
      </ol>
      <button v-if="phase === 'error'" type="button" class="fs__retry" @click="start"><icons name="refresh" class="w-4 h-4" />{{ $t('Try again') }}</button>
    </div>
  </div>
</template>

<script>
import TecApplicationMark from '@/Jetstream/ApplicationMark.vue';
import FaceLoadBar from './FaceLoadBar.vue';
import { faceLoad, startFaceEngine } from './faceLoad';
import { loadTracker, loadRecogniser, track, describe, advance, newStepState, openCamera, closeCamera, getTesselation, TURN, TURN_ENROL } from './faceEngine';

const STEP_SECONDS = 30;
const HINT_DELAY = 320; // a warning must last this long before it is shown, so the text does not flicker
// landmark numbers used for the measuring marks
const MARKS = [33, 263, 133, 362, 1, 61, 291, 152, 10, 234, 454, 105, 334];
const GOLD = '250, 204, 21';

export default {
  components: { TecApplicationMark, FaceLoadBar },
  props: {
    // [{ k: 'front' | 'blink' | 'smile' | 'left' | 'right', capture: 'front' | 'turned' }]
    plan: { type: Array, required: true },
    enrol: { type: Boolean, default: false },
    // set by the page once the scan is finished: 'verifying' | 'ok' | 'bad'
    stage: { type: String, default: '' },
    stageText: { type: String, default: '' },
  },
  emits: ['done', 'error'],
  data() {
    return {
      faceLoad,
      phase: 'loading', idx: 0, hint: '', hintKind: 'info', hintKey: '', errorText: '', flash: false, arrow: '', pct: 0, stream: null, running: false,
      capturing: false, cameraOn: false, frames: [], done: [], steps: [], cueActive: false, camera: '', t0: 0, promptKey: 0, state: null, debug: false, tick: 0, pending: null,
      live: { offset: 0, close: 0 }, pctShown: 0, pctTimer: null, announce: '', audioCtx: null,
    };
  },
  computed: {
    progress() { return this.phase === 'done' ? 1 : Math.min(1, (this.idx + (this.capturing ? 1 : this.pct)) / this.plan.length); },
    curK() { return (this.plan[this.idx] || {}).k; },
    // how far the step in progress is, 0 to 100
    stepPct() { return Math.max(0, Math.min(100, Math.round((this.capturing ? 1 : this.pct) * 100))); },
    prompt() {
      if (this.phase === 'done') return this.stage === 'ok' ? this.$t('You are in') : this.stage === 'bad' ? this.$t('Something did not match') : this.$t('Checking it is really you');
      if (this.phase !== 'running') return this.enrol ? this.$t('Look straight at the camera') : this.$t('Look at the camera');
      const s = this.plan[this.idx];
      if (!s) return '';
      if (s.k === 'cue') return this.cueActive ? this.$t('Blink now') : this.$t('Look at the camera and wait for the flash');
      return { front: this.$t('Look straight at the camera and hold still'), smile: this.$t('Smile a little at the camera'), blink: this.$t('Close your eyes for a second, then open them'), left: this.$t('Slowly turn your head to your left'), right: this.$t('Slowly turn your head to your right') }[s.k];
    },
    // where the turn gauge's target zones start (matches how far the head has to turn)
    zoneEdge() { const thr = this.enrol ? TURN_ENROL : TURN; return Math.round(50 - (thr / 0.22) * 45); },
    // follows the person's own eyelids once they move; until then the eye blinks by itself to show what to do
    eyeStyle() { return this.live.close > 0.12 ? { animation: 'none', transform: 'scaleY(' + (1 - this.live.close * 0.94).toFixed(3) + ')' } : null; },
    knobX() { return Math.max(4, Math.min(96, 50 - (this.live.offset / 0.22) * 45)); },
  },
  watch: {
    stage(v) { this.runPercent(v); },
    phase(v) { if (v === 'done' && !this.stage) this.runPercent('verifying'); },
  },
  mounted() {
    try { this.debug = localStorage.getItem('face-debug') === '1'; } catch (e) { /* no storage */ }
    if (this.debug) window.__faceScanner = this; // for support: lets the console inspect the scanner
    this.paintLoop();
    this.start();
  },
  beforeUnmount() { try { this.audioCtx && this.audioCtx.close(); } catch (e) { /* closed */ } this.stop(); this.painting = false; cancelAnimationFrame(this.paintRaf); clearInterval(this.pctTimer); },
  methods: {
    async start() {
      this.unlockAudio();
      this.stop();
      this.idx = 0; this.frames = []; this.done = []; this.steps = []; this.cueActive = false; this.errorText = ''; this.arrow = ''; this.pct = 0; this.capturing = false; this.hintKey = ''; this.phase = 'loading'; this.cameraOn = false; this.lm = null;
      this.say('wait', this.$t('This only takes a few seconds.'), 'info');
      try {
        // The camera and the tracking engine start together, but the live picture shows as soon as the camera is ready (the engine
        // is still downloading behind a light cover). The recognition model is only needed for the few frames that are kept, so it loads
        // afterwards in the background.
        startFaceEngine();
        const trackerReady = loadTracker();
        trackerReady.catch(() => {});
        const stream = await openCamera();
        this.stream = stream;
        try { this.camera = (stream.getVideoTracks()[0] || {}).label || ''; } catch (e) { this.camera = ''; }
        const v = this.$refs.video;
        if (!v) { closeCamera(stream); return; }
        v.srcObject = stream;
        await v.play();
        this.cameraOn = true;
        await trackerReady;
        if (this.stream !== stream) return; // cancelled or restarted while the engine was loading
        loadRecogniser().catch(() => {});
      } catch (e) {
        return this.fail(this.cameraMessage(e));
      }
      this.phase = 'running';
      this.t0 = performance.now();
      this.state = newStepState();
      this.running = true;
      this.schedule();
    },
    stop() { this.running = false; closeCamera(this.stream); this.stream = null; },
    cameraMessage(e) {
      if (e && e.code === 'insecure') return this.$t('The camera only works on a secure (https) page.');
      if (e && (e.name === 'NotAllowedError' || e.name === 'SecurityError')) return this.$t('Camera access was blocked. Allow the camera for this site in your browser, then try again.');
      if (e && (e.name === 'NotFoundError' || e.name === 'OverconstrainedError')) return this.$t('No camera was found on this device.');
      if (e && e.name === 'NotReadableError') return this.$t('The camera is being used by another app. Close it and try again.');
      if (e && e.code === 'nowebgl') return this.$t('This browser cannot run the face scanner (graphics acceleration is off). Try Chrome or Edge, or sign in with your password.');
      return this.$t('The face scanner could not start on this device.');
    },
    fail(text) { this.cueActive = false; this.stop(); this.errorText = text; this.phase = 'error'; this.hint = text; this.hintKind = 'bad'; this.$emit('error', text); },
    // key identifies the message; a warning only appears once it has lasted HINT_DELAY, good news appears at once
    say(key, text, kind = 'info') {
      const now = performance.now();
      if (key === this.hintKey) { this.pending = null; return; }
      if (kind === 'good' || (kind === 'info' && key !== 'noface')) { this.hintKey = key; this.hint = text; this.hintKind = kind; this.pending = null; return; }
      if (!this.pending || this.pending.key !== key) { this.pending = { key, at: now }; return; }
      if (now - this.pending.at >= HINT_DELAY) { this.hintKey = key; this.hint = text; this.hintKind = kind; this.pending = null; }
    },
    // one pass per camera picture (about 30 a second), not on a timer
    schedule() {
      const v = this.$refs.video;
      if (!this.running || !v) return;
      const next = () => { if (!this.running) return; this.frame(); this.schedule(); };
      if (typeof v.requestVideoFrameCallback === 'function') v.requestVideoFrameCallback(next);
      else requestAnimationFrame(next);
    },
    frame() {
      const video = this.$refs.video;
      const s = this.state;
      if (!video || video.readyState < 2 || this.capturing) return;
      const step = this.plan[this.idx];
      if (Date.now() - s.since > STEP_SECONDS * 1000) return this.fail(this.$t('That took too long. Try again in better light, facing the camera.'));
      let pose = null;
      try { pose = track(video); } catch (e) { return; }
      this.lm = pose ? pose.lm : null;
      this.vw = video.videoWidth; this.vh = video.videoHeight;
      const r = advance(step, s, pose, this.enrol, performance.now());
      if (pose && s.sm) { this.live.offset = s.sm.yaw - (s.yawBase || 0.5); this.live.close = Math.max(0, Math.min(1, (pose.blink - (s.blinkBase < 0 ? 0 : s.blinkBase)) / 0.6)); }
      if (this.debug && ++this.tick % 6 === 0) console.log('face', step.k, pose ? { blink: +pose.blink.toFixed(2), yaw: +pose.yaw.toFixed(3), size: +pose.size.toFixed(2), cx: +pose.cx.toFixed(2), cy: +pose.cy.toFixed(2) } : 'no face', { base: s.yawBase && +s.yawBase.toFixed(3), closed: s.closed, sawClosed: s.sawClosed, acted: s.acted, hold: +r.hold.toFixed(2), hint: r.hint });
      this.arrow = r.arrow;
      if (r.fail) return this.fail(this.$t('Blink once, right when the ring flashes. Try again.'));
      if (r.cue) this.fireCue();
      this.cueActive = step.k === 'cue' && r.hint === 'cueNow';
      this.pct = step.k === 'cue' || step.k === 'front' || step.capture === 'turned' ? r.hold : s.acted ? 0.5 + 0.5 * r.hold : s.sawClosed ? 0.25 : 0;
      this.say(r.hint, this.hintText(r.hint), r.kind);
      if (r.capture) this.capture(step);
    },
    stepIcon(k) { return { front: 'user', smile: 'smile', blink: 'eye', cue: 'eye', left: 'reply', right: 'forward' }[k] || 'camera'; },
    stepLabel(k, i) { if (k === 'cue') return this.plan.slice(0, i).some(p => p.k === 'cue') ? this.$t('Blink again') : this.$t('Blink on the flash'); return { front: this.$t('Look straight'), smile: this.$t('Smile'), left: this.$t('Turn left'), right: this.$t('Turn right'), blink: this.$t('Blink'), cue: this.$t('Blink on the flash') }[k] || ''; },
    // the flash is the ring; a short high beep goes with it (made in the browser)
    fireCue() {
      try {
        this.unlockAudio();
        const ctx = this.audioCtx;
        if (!ctx) return;
        const o = ctx.createOscillator(), g = ctx.createGain(), t0 = ctx.currentTime;
        o.type = 'sine'; o.frequency.setValueAtTime(1175, t0);
        g.gain.setValueAtTime(0.0001, t0); g.gain.exponentialRampToValueAtTime(0.25, t0 + 0.01); g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.22);
        o.connect(g); g.connect(ctx.destination); o.start(t0); o.stop(t0 + 0.25);
      } catch (e) { /* no sound is fine */ }
    },
    // opened on the click that starts the scan, so the browser lets the chime play later
    unlockAudio() {
      try {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return;
        this.audioCtx = this.audioCtx || new AC();
        if (this.audioCtx.state === 'suspended') this.audioCtx.resume();
      } catch (e) { /* no sound is fine */ }
    },
    // a soft bell chime for each finished step (two notes), and a longer rising one for the last step: made in the browser, nothing to download
    chime(last) {
      try {
        this.unlockAudio();
        const ctx = this.audioCtx;
        if (!ctx) return;
        const notes = last ? [659.25, 880, 1318.5] : [987.77, 1318.5];
        notes.forEach((f, i) => {
          const t0 = ctx.currentTime + 0.02 + i * 0.13;
          const tail = last && i === notes.length - 1 ? 1.4 : 0.8;
          // a bell is the note plus a few higher partials that fade faster
          [[1, 0.34], [2.76, 0.12], [5.4, 0.05]].forEach(([mul, vol], k) => {
            const o = ctx.createOscillator(), g = ctx.createGain();
            o.type = 'sine'; o.frequency.setValueAtTime(f * mul, t0);
            g.gain.setValueAtTime(0.0001, t0);
            g.gain.exponentialRampToValueAtTime(vol, t0 + 0.012);
            g.gain.exponentialRampToValueAtTime(0.0001, t0 + tail / (1 + k * 0.9));
            o.connect(g); g.connect(ctx.destination);
            o.start(t0); o.stop(t0 + tail + 0.05);
          });
        });
      } catch (e) { /* no sound is fine */ }
    },
    hintText(k) {
      return {
        closer: this.$t('Move a little closer.'), farther: this.$t('Move a little back.'), centre: this.$t('Centre your face in the oval.'),
        blink: this.$t('Close your eyes for a second, then open them.'), closedNow: this.$t('Eyes closed. Now open them.'), noface: this.$t('I cannot see your face. Face the camera in good light.'),
        left: this.$t('Turn your head to your left.'), right: this.$t('Turn your head to your right.'), back: this.$t('Good. Now face the camera again.'),
        open: this.$t('Open your eyes and look at the camera.'), smile: this.$t('Smile a little, like for a photo.'), smileHold: this.$t('Nice. Keep smiling...'),
        steady: this.$t('Look at the camera. Keep your eyes open until the ring flashes.'), keepOpen: this.$t('Keep your eyes open until the ring flashes.'),
        cueNow: this.$t('Blink once now.'), early: this.$t('Too early. Wait for the flash, then blink once.'), missed: this.$t('Missed it. Wait for the flash, then blink once.'), straight: this.$t('Face the camera straight on.'), hold: this.$t('Good. Hold still...'),
      }[k] || '';
    },
    async capture(step) {
      this.capturing = true;
      let vec = null;
      try { vec = await describe(this.$refs.video); } catch (e) { vec = null; }
      if (!this.stream) return; // closed while working
      if (!vec) { this.capturing = false; this.state.holdFrom = 0; return; }
      this.frames.push(vec);
      this.done.push(step.k);
      const st = this.state;
      this.steps.push(step.k === 'cue' ? (st.result || { k: 'cue' }) : step.k === 'left' || step.k === 'right' ? { k: step.k, peak: st.peak } : { k: step.k });
      this.cueActive = false;
      const lastStep = this.idx + 1 >= this.plan.length;
      this.chime(lastStep);
      this.announce = this.$t('Step') + ' ' + (this.idx + 1) + ' ' + this.$t('complete') + ': ' + this.stepLabel(step.k);
      this.flash = true; setTimeout(() => (this.flash = false), 260);
      this.idx++; this.promptKey++; this.pct = 0; this.arrow = '';
      this.capturing = false;
      if (this.idx >= this.plan.length) {
        this.running = false;
        this.takeSnapshot();
        this.stop();
        this.phase = 'done';
        this.$emit('done', { frames: this.frames, done: this.done, telemetry: { v: 1, camera: this.camera, span: Math.round(performance.now() - this.t0), steps: this.steps } });
        return;
      }
      this.state = newStepState(this.state.yawBase, this.state.blinkBase);
      this.hintKey = '';
    },

    // the percentage under the WIMS mark counts up while the server checks, and jumps to 100 on success
    runPercent(v) {
      clearInterval(this.pctTimer);
      if (v === 'verifying') {
        this.pctShown = 3;
        this.pctTimer = setInterval(() => { this.pctShown = Math.min(94, this.pctShown + Math.max(1, Math.round((94 - this.pctShown) / 9))); }, 110);
      } else if (v === 'ok') this.pctShown = 100;
    },

    // ---- drawing -------------------------------------------------------------------------------------------------
    // the camera picture is shown "cover" fitted and mirrored; this maps a landmark (0..1 of the camera picture) onto the oval's canvas
    mapper(W, H) {
      const vw = this.vw || 640, vh = this.vh || 480;
      const sc = Math.max(W / vw, H / vh);
      const dx = (W - vw * sc) / 2, dy = (H - vh * sc) / 2;
      return p => [W - (dx + p.x * vw * sc), dy + p.y * vh * sc];
    },
    takeSnapshot() {
      const c = this.$refs.snap, v = this.$refs.video, m = this.$refs.mesh;
      if (!c || !v || !m) return;
      c.width = m.width; c.height = m.height;
      const W = c.width, H = c.height, vw = v.videoWidth || 640, vh = v.videoHeight || 480;
      const sc = Math.max(W / vw, H / vh), dx = (W - vw * sc) / 2, dy = (H - vh * sc) / 2;
      const g = c.getContext('2d');
      g.save(); g.translate(W, 0); g.scale(-1, 1); g.drawImage(v, dx, dy, vw * sc, vh * sc); g.restore();
    },
    paintLoop() {
      this.painting = true;
      // 30 pictures a second are plenty for the mesh (the camera itself gives about 30), and halves the drawing work
      let last = 0;
      const go = t => { if (!this.painting) return; if (t - last >= 30) { last = t; this.paint(t); } this.paintRaf = requestAnimationFrame(go); };
      this.paintRaf = requestAnimationFrame(go);
    },
    paint(t) {
      const c = this.$refs.mesh;
      if (!c) return;
      const box = c.getBoundingClientRect();
      const dpr = Math.min(2, window.devicePixelRatio || 1);
      const w = Math.round(box.width * dpr), h = Math.round(box.height * dpr);
      if (!w || !h) return;
      if (c.width !== w || c.height !== h) { c.width = w; c.height = h; }
      const g = c.getContext('2d');
      g.clearRect(0, 0, w, h);
      const lm = this.lm;
      if (!lm || (this.phase !== 'running' && this.phase !== 'done')) return;
      const map = this.mapper(w, h);
      const tess = getTesselation();
      const verifying = this.stage === 'verifying';
      const sweepSpeed = verifying ? 1100 : 2300;
      const sweepY = (((t % sweepSpeed) / sweepSpeed) * 1.5 - 0.25) * h;
      const base = this.stage === 'ok' ? '52, 211, 153' : this.stage === 'bad' ? '251, 113, 133' : this.hintKind === 'good' || this.capturing ? '52, 211, 153' : '103, 232, 249';
      const pts = lm.map(map);
      // 1) the mesh, dim; 2) the part of it under the sweep, bright and gold
      g.lineWidth = Math.max(0.6, dpr * 0.55);
      g.strokeStyle = `rgba(${base}, 0.30)`;
      g.beginPath();
      const hot = [];
      for (let i = 0; i < tess.length; i++) {
        const a = pts[tess[i][0]], b = pts[tess[i][1]];
        if (!a || !b) continue;
        const my = (a[1] + b[1]) / 2;
        if (Math.abs(my - sweepY) < h * 0.07) hot.push(i);
        g.moveTo(a[0], a[1]); g.lineTo(b[0], b[1]);
      }
      g.stroke();
      if (hot.length) {
        g.strokeStyle = `rgba(${GOLD}, 0.95)`; g.lineWidth = Math.max(1, dpr * 0.9);
        g.shadowColor = `rgba(${GOLD}, 0.9)`; g.shadowBlur = 6 * dpr;
        g.beginPath();
        for (const i of hot) { const a = pts[tess[i][0]], b = pts[tess[i][1]]; g.moveTo(a[0], a[1]); g.lineTo(b[0], b[1]); }
        g.stroke();
        g.shadowBlur = 0;
      }
      // 3) measuring lines: eye span, face width and face height as dashed callipers with end ticks and a reading
      const dash = (a, b, label, off) => {
        g.save();
        g.setLineDash([4 * dpr, 4 * dpr]); g.lineDashOffset = -(t / 40) % (8 * dpr);
        g.strokeStyle = `rgba(${GOLD}, 0.8)`; g.lineWidth = dpr * 0.9;
        g.beginPath(); g.moveTo(a[0], a[1]); g.lineTo(b[0], b[1]); g.stroke();
        g.setLineDash([]);
        const nx = -(b[1] - a[1]), ny = b[0] - a[0], len = Math.hypot(nx, ny) || 1, tk = 5 * dpr;
        g.beginPath();
        for (const p of [a, b]) { g.moveTo(p[0] - (nx / len) * tk, p[1] - (ny / len) * tk); g.lineTo(p[0] + (nx / len) * tk, p[1] + (ny / len) * tk); }
        g.stroke();
        if (label) {
          g.font = `${9 * dpr}px ui-monospace, Menlo, monospace`; g.fillStyle = `rgba(${GOLD}, 0.95)`;
          g.fillText(label, (a[0] + b[0]) / 2 + off[0] * dpr, (a[1] + b[1]) / 2 + off[1] * dpr);
        }
        g.restore();
      };
      const px = (i, j) => Math.round(Math.hypot((lm[i].x - lm[j].x) * (this.vw || 640), (lm[i].y - lm[j].y) * (this.vh || 480)));
      dash(pts[33], pts[263], 'IPD ' + px(33, 263), [-14, -7]);
      dash(pts[234], pts[454], 'W ' + px(234, 454), [-12, 16]);
      dash(pts[10], pts[152], 'H ' + px(10, 152), [8, 0]);
      // 4) key points: gold plus marks that pulse
      const pulse = 1 + 0.25 * Math.sin(t / 220);
      g.strokeStyle = `rgba(${GOLD}, 1)`; g.lineWidth = dpr * 1.2; g.shadowColor = `rgba(${GOLD}, 0.9)`; g.shadowBlur = 5 * dpr;
      for (const i of MARKS) {
        const p = pts[i]; if (!p) continue;
        const r = 3.2 * dpr * pulse;
        g.beginPath(); g.moveTo(p[0] - r, p[1]); g.lineTo(p[0] + r, p[1]); g.moveTo(p[0], p[1] - r); g.lineTo(p[0], p[1] + r); g.stroke();
      }
      g.shadowBlur = 0;
    },
  },
};
</script>

<style>
.fs { --fs-ink: #0f172a; --fs-mute: #64748b; --fs-line: rgba(100, 116, 139, 0.25); --fs-gold: #facc15; --fs-cyan: #22d3ee; display: grid; gap: 0.9rem; justify-items: center; color: var(--fs-ink); }
html.dark .fs { --fs-ink: #f1f5f9; --fs-mute: #94a3b8; }

/* the dark "heads-up display" card the oval sits in */
.fs__hud { position: relative; padding: 20px 22px 18px; border-radius: 26px; background: radial-gradient(120% 90% at 50% 0%, #17335f 0%, #0b1a33 55%, #070f20 100%); box-shadow: 0 22px 44px -22px rgba(7, 15, 32, 0.85), inset 0 0 0 1px rgba(103, 232, 249, 0.18); overflow: hidden; }
.fs__hud::before { content: ''; position: absolute; inset: 0; background-image: linear-gradient(rgba(103, 232, 249, 0.05) 1px, transparent 1px), linear-gradient(90deg, rgba(103, 232, 249, 0.05) 1px, transparent 1px); background-size: 22px 22px; mask-image: radial-gradient(70% 70% at 50% 50%, #000, transparent); pointer-events: none; }
.fs__stage { position: relative; width: min(240px, 62vw); aspect-ratio: 200 / 250; }
.fs__oval { position: absolute; left: 8%; right: 8%; top: 6.8%; bottom: 6.8%; border-radius: 50%; overflow: hidden; background: #050b17; box-shadow: 0 0 30px rgba(34, 211, 238, 0.25); }
.fs__video, .fs__snap, .fs__mesh { position: absolute; inset: 0; width: 100%; height: 100%; }
.fs__video { object-fit: cover; transform: scaleX(-1); }
.fs__snap { background: #050b17; }
.fs__mesh { pointer-events: none; }
.fs__oval::after { content: ''; position: absolute; inset: 0; border-radius: inherit; box-shadow: inset 0 0 26px rgba(2, 6, 23, 0.7); pointer-events: none; }
.fs__sweep { position: absolute; left: 0; right: 0; height: 26%; top: -26%; background: linear-gradient(180deg, transparent, rgba(34, 211, 238, 0.22) 60%, rgba(250, 204, 21, 0.35)); border-bottom: 1.5px solid rgba(250, 204, 21, 0.9); animation: fs-sweep 2.3s ease-in-out infinite; pointer-events: none; }
.fs__lock { position: absolute; inset: 0; background: radial-gradient(circle, rgba(52, 211, 153, 0.35), transparent 70%); animation: fs-lock 0.5s ease both; }

/* ring */
.fs__ring { position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible; pointer-events: none; }
.fs__ring ellipse, .fs__ring path { fill: none; }
.fs--cue .fs__r-track { stroke: #34d399; stroke-width: 5; animation: fs-cueflash 0.5s ease-in-out infinite alternate; }
.fs--cue .fs__oval { box-shadow: 0 0 22px rgba(52, 211, 153, 0.45); }
.fs__cue { position: absolute; left: 0; right: 0; bottom: -8px; margin: 0 auto; width: max-content; z-index: 4; display: inline-flex; align-items: center; gap: 7px; padding: 5px 13px 5px 10px; border-radius: 999px; font-size: 0.78rem; font-weight: 700; letter-spacing: 0.01em; color: #d1fae5; background: rgba(6, 24, 20, 0.78); box-shadow: inset 0 0 0 1px rgba(110, 231, 183, 0.5); pointer-events: none; animation: fs-cuepop 0.2s ease both; }
.fs__cue b { font-weight: 700; line-height: 1; } .fs__cue svg { color: #6ee7b7; }
@keyframes fs-cueflash { from { opacity: 0.55; } to { opacity: 1; } }
@keyframes fs-cuepop { from { transform: translateY(4px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.fs__r-ticks { stroke: rgba(103, 232, 249, 0.45); stroke-width: 3; stroke-dasharray: 1.2 8; animation: fs-ticks 14s linear infinite; }
.fs__r-ticks--b { stroke: rgba(250, 204, 21, 0.4); stroke-width: 2; stroke-dasharray: 18 14 4 14; animation-duration: 22s; animation-direction: reverse; }
.fs__r-track { stroke: rgba(103, 232, 249, 0.22); stroke-width: 3; }
.fs__r-fill { stroke: url(#fsg); stroke-width: 4; stroke-linecap: round; transition: stroke-dasharray 0.25s linear; }
.fs__r-dir { stroke: var(--fs-gold); stroke-width: 5; stroke-linecap: round; stroke-dasharray: 14 12; animation: fs-dir-l 0.9s linear infinite; }
.fs__r-dir--r { animation-name: fs-dir-r; }
.fs--running .fs__r-track { animation: fs-pulse 2.2s ease-in-out infinite; }
.fs__corner { position: absolute; width: 22px; height: 22px; border: 0 solid var(--fs-cyan); opacity: 0.85; animation: fs-corner 2.4s ease-in-out infinite; }
.fs__corner--tl { top: -6px; left: -8px; border-top-width: 3px; border-left-width: 3px; border-top-left-radius: 8px; } .fs__corner--tr { top: -6px; right: -8px; border-top-width: 3px; border-right-width: 3px; border-top-right-radius: 8px; }
.fs__corner--bl { bottom: -6px; left: -8px; border-bottom-width: 3px; border-left-width: 3px; border-bottom-left-radius: 8px; } .fs__corner--br { bottom: -6px; right: -8px; border-bottom-width: 3px; border-right-width: 3px; border-bottom-right-radius: 8px; }
.fs--ok .fs__corner, .fs-stage-ok .fs__corner { border-color: #34d399; } .fs-stage-bad .fs__corner { border-color: #fb7185; }

/* the turn signal */
.fs__signal { position: absolute; top: 50%; display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 8px 6px; border-radius: 16px; transform: translateY(-50%); background: rgba(250, 204, 21, 0.14); border: 1px solid rgba(250, 204, 21, 0.55); backdrop-filter: blur(6px); box-shadow: 0 0 18px rgba(250, 204, 21, 0.45); animation: fs-signal 1.1s ease-in-out infinite; z-index: 3; }
.fs__signal.is-left { left: -14px; } .fs__signal.is-right { right: -14px; }
.fs__signal small { font-size: 0.6rem; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; color: var(--fs-gold); }
.fs__signal-mark { width: 26px; height: 26px; border-radius: 8px; overflow: hidden; box-shadow: 0 0 0 2px rgba(250, 204, 21, 0.6); } .fs__signal-mark svg { display: block; width: 100%; height: 100%; }
.fs__chev { display: flex; gap: 1px; } .fs__signal.is-left .fs__chev { flex-direction: row; }
.fs__chev i { width: 8px; height: 8px; border-top: 3px solid var(--fs-gold); border-left: 3px solid var(--fs-gold); opacity: 0; transform: rotate(-45deg); animation: fs-chev-l 0.9s ease-in-out infinite; }
.fs__signal.is-right .fs__chev i { transform: rotate(135deg); animation-name: fs-chev-r; }
.fs__chev i:nth-child(2) { animation-delay: 0.15s; } .fs__chev i:nth-child(3) { animation-delay: 0.3s; }
.fs__signal.is-right .fs__chev i:nth-child(1) { animation-delay: 0.3s; } .fs__signal.is-right .fs__chev i:nth-child(3) { animation-delay: 0s; }

/* covers over the oval */
.fs__cover { position: absolute; left: 8%; right: 8%; top: 6.8%; bottom: 6.8%; z-index: 4; display: grid; place-content: center; justify-items: center; gap: 0.6rem; padding: 1.2rem; border-radius: 50%; text-align: center; background: rgba(7, 15, 32, 0.86); color: #e2e8f0; font-size: 0.8rem; backdrop-filter: blur(3px); animation: fs-fade 0.3s ease both; }
.fs__cover--live { background: rgba(7, 15, 32, 0.38); backdrop-filter: none; } .fs__cover small { font-size: 0.68rem; opacity: 0.7; } .fs__cover--bad { background: rgba(159, 18, 57, 0.9); }
.fs__orbit { position: relative; display: block; width: 56px; height: 56px; border-radius: 16px; overflow: hidden; animation: fs-bob 1.6s ease-in-out infinite; box-shadow: 0 0 0 3px rgba(34, 211, 238, 0.4), 0 0 22px rgba(34, 211, 238, 0.7); } .fs__orbit svg { display: block; width: 100%; height: 100%; }

/* verification: WIMS mark with ripples and a spinning ring, then tick or cross */
.fs__verify { position: absolute; left: 8%; right: 8%; top: 6.8%; bottom: 6.8%; z-index: 4; display: grid; place-content: center; justify-items: center; gap: 0.35rem; border-radius: 50%; color: #e2e8f0; text-align: center; background: radial-gradient(circle, rgba(7, 15, 32, 0.55), rgba(7, 15, 32, 0.82)); animation: fs-fade 0.35s ease both; }
.fs__verify b { font-size: 0.8rem; max-width: 150px; } .fs__verify.is-ok { background: radial-gradient(circle, rgba(5, 150, 105, 0.7), rgba(6, 78, 59, 0.9)); } .fs__verify.is-bad { background: radial-gradient(circle, rgba(190, 18, 60, 0.7), rgba(76, 5, 25, 0.92)); animation: fs-fade 0.35s ease both, fs-shake 0.5s 0.1s both; }
.fs__logo { position: relative; display: grid; place-items: center; width: 92px; height: 92px; }
.fs__logo i { position: absolute; inset: 0; border-radius: 50%; }
.fs__logo-r1, .fs__logo-r2 { border: 2px solid rgba(34, 211, 238, 0.7); animation: fs-ripple 1.8s ease-out infinite; } .fs__logo-r2 { animation-delay: 0.9s; border-color: rgba(250, 204, 21, 0.7); }
.fs__logo-spin { inset: 4px !important; border: 3px solid transparent; border-top-color: #22d3ee; border-right-color: #facc15; animation: fs-spin 0.9s linear infinite; filter: drop-shadow(0 0 5px rgba(34, 211, 238, 0.9)); }
.fs__logo-mark { position: relative; display: block; width: 52px; height: 52px; border-radius: 15px; overflow: hidden; box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.55), 0 0 22px rgba(34, 211, 238, 0.9); animation: fs-bob 1.4s ease-in-out infinite; } .fs__logo-mark svg { display: block; width: 100%; height: 100%; }
.fs__pct { font-size: 1.5rem; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; text-shadow: 0 0 14px rgba(34, 211, 238, 0.9); } .fs__pct small { font-size: 0.8rem; margin-left: 1px; }
.fs__bigtick { width: 64px; height: 64px; } .fs__bigtick circle { fill: none; stroke: #fff; stroke-width: 3; opacity: 0.4; animation: fs-ring 0.6s ease both; transform-origin: center; }
.fs__bigtick path { fill: none; stroke: #fff; stroke-width: 4.5; stroke-linecap: round; stroke-linejoin: round; stroke-dasharray: 40; stroke-dashoffset: 40; animation: fs-draw 0.5s 0.15s ease forwards; }

/* panel under the oval: flat, one accent (navy), greys, nothing else */
.fs { --fs-accent: #1c3f66; --fs-track: #e5e9f0; --fs-card: #ffffff; font-family: 'WIMS Avenir', 'Avenir Next', Avenir, Montserrat, 'Segoe UI', Arial, sans-serif; }
.fs *, .fs button, .fs input { font-family: inherit; }
html.dark .fs { --fs-accent: #8fb4e8; --fs-track: rgba(255, 255, 255, 0.14); --fs-card: #141a33; }
.fs__panel { display: grid; gap: 0.55rem; justify-items: center; text-align: center; max-width: 360px; width: 100%; }
.fs__prompt { margin: 0; font-size: 1.04rem; font-weight: 800; letter-spacing: -0.005em; animation: fs-rise 0.35s ease both; }
.fs__hint { display: inline-flex; align-items: center; gap: 0.45rem; margin: 0; min-height: 1.5em; padding: 0.2rem 0.8rem; border-radius: 999px; font-size: 0.8rem; font-weight: 600; color: var(--fs-mute); background: color-mix(in srgb, var(--fs-mute) 9%, transparent); transition: color 0.2s, background 0.2s; }
.fs__hint:has(span:empty) { visibility: hidden; }
.fs__hint-dot { flex: none; width: 7px; height: 7px; border-radius: 50%; background: var(--fs-mute); }
.fs__hint.is-good .fs__hint-dot { background: #16a34a; } .fs__hint.is-warn .fs__hint-dot { background: #d97706; } .fs__hint.is-bad .fs__hint-dot { background: #e11d48; }
.fs__guide { display: grid; place-items: center; width: 100%; min-height: 40px; }

/* turn gauge */
.fs__gauge { position: relative; width: min(260px, 100%); height: 38px; border-radius: 99px; background: linear-gradient(180deg, #0b1a33, #11284d); box-shadow: inset 0 0 0 1px rgba(103, 232, 249, 0.25), 0 8px 18px -10px rgba(7, 15, 32, 0.8); overflow: hidden; }
.fs__zone { position: absolute; top: 0; bottom: 0; opacity: 0.25; background: rgba(103, 232, 249, 0.4); } .fs__zone--l { left: 0; width: var(--zone); } .fs__zone--r { right: 0; width: var(--zone); }
.fs__gauge.to-left .fs__zone--l, .fs__gauge.to-right .fs__zone--r { opacity: 1; background: linear-gradient(90deg, rgba(250, 204, 21, 0.15), rgba(250, 204, 21, 0.65)); animation: fs-zone 1s ease-in-out infinite; }
.fs__gauge.to-right .fs__zone--r { background: linear-gradient(270deg, rgba(250, 204, 21, 0.15), rgba(250, 204, 21, 0.65)); }
.fs__flow { position: absolute; inset: 0; display: flex; align-items: center; justify-content: space-around; padding: 0 6px; pointer-events: none; } .fs__flow i { width: 9px; height: 9px; border-top: 3px solid rgba(250, 204, 21, 0.9); border-left: 3px solid rgba(250, 204, 21, 0.9); opacity: 0.15; }
.fs__gauge.to-left .fs__flow i { transform: rotate(-45deg); animation: fs-flow 1s linear infinite; }
.fs__gauge.to-right .fs__flow i { transform: rotate(135deg); animation: fs-flow 1s linear infinite reverse; }
.fs__flow i:nth-child(2) { animation-delay: 0.1s; } .fs__flow i:nth-child(3) { animation-delay: 0.2s; } .fs__flow i:nth-child(4) { animation-delay: 0.3s; } .fs__flow i:nth-child(5) { animation-delay: 0.4s; }
.fs__knob { position: absolute; top: 50%; width: 30px; height: 30px; margin: -15px 0 0 -15px; border-radius: 10px; overflow: hidden; box-shadow: 0 0 0 2px #fff, 0 0 16px rgba(34, 211, 238, 0.9); transition: left 0.08s linear; } .fs__knob svg { display: block; width: 100%; height: 100%; }

/* eye guide: a simple eye that blinks on its own to show what to do, then follows your own eyelids */
.fs__eye { width: 92px; } .fs__eye svg { display: block; width: 100%; overflow: visible; }
.fs__eye-g { transform-origin: 48px 32px; animation: fs-blink 2.8s ease-in-out infinite; }
.fs__eye-fill { fill: #fff; } .fs__eye-iris { fill: #2f6fb3; } .fs__eye-pupil { fill: #0b1a33; }
.fs__eye-edge { fill: none; stroke: #0f2540; stroke-width: 3; stroke-linejoin: round; } html.dark .fs__eye-edge { stroke: #e2e8f0; }
.fs__eye-lashes path { fill: none; stroke: #0f2540; stroke-width: 3; stroke-linecap: round; opacity: 0; animation: fs-lashes 2.8s ease-in-out infinite; } html.dark .fs__eye-lashes path { stroke: #e2e8f0; }
.fs__eye.shut .fs__eye-lashes path { opacity: 1; animation: none; }
.fs__eye::after { content: ''; display: block; height: 3px; width: 46px; margin: 6px auto 0; border-radius: 3px; background: linear-gradient(90deg, transparent, #22d3ee, transparent); opacity: 0.8; animation: fs-eyeline 2.8s ease-in-out infinite; }

/* hold bar: gradient fill with a soft moving sheen and a glowing tip */
.fs__holdbar { position: relative; width: min(250px, 100%); height: 9px; border-radius: 99px; background: var(--fs-track); overflow: hidden; }
.fs__holdbar span { position: relative; display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #22d3ee, #3b82f6 55%, #8b5cf6); transition: width 0.1s linear; }
.fs__holdbar span::after, .fs__stepbar span::after, .fs__total span::after { content: ''; position: absolute; inset: 0; background: linear-gradient(100deg, transparent 20%, rgba(255, 255, 255, 0.55) 50%, transparent 80%); background-size: 220% 100%; animation: fs-sheen 1.8s linear infinite; }
@keyframes fs-sheen { from { background-position: 160% 0; } to { background-position: -60% 0; } }

/* where you are: the step (with its icon), its percentage, a bar for the step and one for the whole scan */
.fs__meter { display: grid; gap: 0.4rem; width: 100%; margin-top: 0.35rem; padding-top: 0.75rem; border-top: 1px solid var(--fs-track); }
.fs__meter-row { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; }
.fs__meter-step { display: inline-flex; align-items: center; gap: 0.5rem; min-width: 0; text-align: left; } .fs__meter-step b { font-size: 0.8rem; font-weight: 800; color: var(--fs-ink); white-space: nowrap; } .fs__meter-step small { font-size: 0.78rem; font-weight: 600; color: var(--fs-mute); }
.fs__meter-ico { flex: none; display: grid; place-items: center; width: 24px; height: 24px; border-radius: 8px; color: #fff; background: linear-gradient(135deg, #22d3ee, #6366f1); box-shadow: 0 4px 10px -4px rgba(99, 102, 241, 0.7); animation: fs-bobicon 2s ease-in-out infinite; } .fs__meter-ico svg { display: block; }
@keyframes fs-bobicon { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-2px); } }
.fs__meter-pct { font-style: normal; font-size: 0.98rem; font-weight: 800; font-variant-numeric: tabular-nums; background: linear-gradient(90deg, #0ea5e9, #6366f1); -webkit-background-clip: text; background-clip: text; color: transparent; }
.fs__stepbar, .fs__total { position: relative; height: 6px; border-radius: 99px; background: var(--fs-track); overflow: hidden; }
.fs__stepbar span, .fs__total span { position: relative; display: block; height: 100%; border-radius: inherit; transition: width 0.3s ease; }
.fs__stepbar span { background: linear-gradient(90deg, #22d3ee, #6366f1); }
.fs__total span { background: linear-gradient(90deg, #34d399, #10b981 60%, #14b8a6); }
.fs__total-label { display: flex; align-items: center; justify-content: flex-end; gap: 0.35rem; font-size: 0.7rem; font-weight: 700; font-variant-numeric: tabular-nums; color: var(--fs-mute); } .fs__total-label svg { color: #10b981; }
.fs .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

/* the steps: an icon node for each, joined by lines */
.fs__dots { display: flex; align-items: flex-start; justify-content: center; width: 100%; margin: 0.45rem 0 0; padding: 0; list-style: none; }
.fs__dots li { position: relative; flex: 1 1 0; display: grid; justify-items: center; gap: 5px; min-width: 0; }
.fs__dots li:not(:last-child)::after { content: ''; position: absolute; top: 14px; left: calc(50% + 19px); width: calc(100% - 38px); height: 3px; border-radius: 3px; background: var(--fs-track); transition: background 0.3s; }
.fs__dots li.on:not(:last-child)::after { background: linear-gradient(90deg, #34d399, #10b981); }
.fs__dots li small { max-width: 100%; padding: 0 2px; font-size: 0.66rem; font-weight: 700; line-height: 1.15; color: var(--fs-mute); text-align: center; overflow-wrap: anywhere; } .fs__dots li.now small { color: #4f46e5; } .fs__dots li.on small { color: #059669; } html.dark .fs__dots li.now small { color: #a5b4fc; } html.dark .fs__dots li.on small { color: #6ee7b7; }
.fs__dot-tick { width: 14px; height: 14px; }
.fs__dots li span { position: relative; z-index: 1; display: grid; place-items: center; width: 28px; height: 28px; border-radius: 50%; color: var(--fs-mute); background: var(--fs-card); box-shadow: inset 0 0 0 2px var(--fs-track); transition: all 0.3s ease; } .fs__dots li span svg { display: block; }
.fs__dots li.now span { color: #4f46e5; border: 2px solid transparent; background: linear-gradient(var(--fs-card), var(--fs-card)) padding-box, linear-gradient(135deg, #22d3ee, #6366f1, #a855f7) border-box; box-shadow: none; animation: fs-node 1.6s ease-in-out infinite; } html.dark .fs__dots li.now span { color: #c7d2fe; }
.fs__dots li.on span { color: #fff; background: linear-gradient(135deg, #34d399, #10b981); box-shadow: 0 4px 10px -4px rgba(16, 185, 129, 0.7); }
@keyframes fs-node { 0%, 100% { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.35); } 50% { box-shadow: 0 0 0 6px rgba(99, 102, 241, 0); } }
@media (prefers-reduced-motion: reduce) { .fs__holdbar span::after, .fs__stepbar span::after, .fs__total span::after, .fs__meter-ico, .fs__dots li.now span { animation: none; } }
.fs__retry { display: inline-flex; align-items: center; gap: 0.4rem; margin-top: 0.6rem; padding: 0.5rem 1rem; border-radius: 12px; border: 0; font-weight: 800; font-size: 0.85rem; color: #fff; background: linear-gradient(135deg, #4f46e5, #2563eb); cursor: pointer; }
.fs__loadbar { width: min(200px, 100%); margin-top: 0.2rem; }
.fs--flash .fs__oval { animation: fs-snap 0.26s ease; }

@keyframes fs-blink { 0%, 50%, 74%, 100% { transform: scaleY(1); } 60%, 64% { transform: scaleY(0.06); } }
@keyframes fs-lashes { 0%, 52%, 72%, 100% { opacity: 0; } 58%, 66% { opacity: 1; } }
@keyframes fs-eyeline { 0%, 50%, 74%, 100% { transform: scaleX(0.4); opacity: 0.4; } 62% { transform: scaleX(1.5); opacity: 1; } }
@keyframes fs-spin { to { transform: rotate(360deg); } } @keyframes fs-fade { from { opacity: 0; } to { opacity: 1; } } @keyframes fs-draw { to { stroke-dashoffset: 0; } }
@keyframes fs-sweep { 0% { top: -26%; opacity: 0; } 12%, 88% { opacity: 1; } 100% { top: 100%; opacity: 0; } }
@keyframes fs-ticks { to { stroke-dashoffset: -200; } }
@keyframes fs-dir-l { to { stroke-dashoffset: -26; } } @keyframes fs-dir-r { to { stroke-dashoffset: 26; } }
@keyframes fs-pulse { 0%, 100% { opacity: 0.55; } 50% { opacity: 1; } } @keyframes fs-pulse2 { 0%, 100% { box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.18); } 50% { box-shadow: 0 0 0 7px rgba(99, 102, 241, 0.08); } }
@keyframes fs-corner { 0%, 100% { opacity: 0.45; transform: scale(0.96); } 50% { opacity: 1; transform: scale(1.04); } }
@keyframes fs-signal { 0%, 100% { box-shadow: 0 0 10px rgba(250, 204, 21, 0.3); } 50% { box-shadow: 0 0 26px rgba(250, 204, 21, 0.8); } }
@keyframes fs-chev-l { 0%, 100% { opacity: 0; transform: translateX(5px) rotate(-45deg); } 50% { opacity: 1; transform: translateX(-2px) rotate(-45deg); } }
@keyframes fs-chev-r { 0%, 100% { opacity: 0; transform: translateX(-5px) rotate(135deg); } 50% { opacity: 1; transform: translateX(2px) rotate(135deg); } }
@keyframes fs-flow { 0%, 100% { opacity: 0.15; } 50% { opacity: 1; } } @keyframes fs-zone { 0%, 100% { filter: brightness(0.9); } 50% { filter: brightness(1.5); } }
@keyframes fs-ripple { 0% { transform: scale(0.55); opacity: 0.9; } 100% { transform: scale(1.35); opacity: 0; } }
@keyframes fs-bob { 0%, 100% { transform: translateY(0) scale(1); } 50% { transform: translateY(-3px) scale(1.06); } }
@keyframes fs-lock { from { opacity: 0; } to { opacity: 1; } } @keyframes fs-ring { from { transform: scale(0.6); opacity: 0; } to { transform: scale(1); opacity: 0.4; } }
@keyframes fs-shake { 20%, 60% { transform: translateX(-6px); } 40%, 80% { transform: translateX(6px); } }
@keyframes fs-snap { 0% { filter: brightness(2.2); } 100% { filter: none; } } @keyframes fs-rise { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) { .fs *, .fs { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; } }
</style>

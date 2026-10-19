<template>
  <Teleport to="body">
    <div class="cc" role="dialog" aria-modal="true" :aria-label="$t('Camera')">
      <video ref="video" class="cc-video" autoplay playsinline muted></video>
      <div v-if="flash" class="cc-flash"></div>

      <!-- top bar -->
      <div class="cc-top">
        <button type="button" class="cc-round" :aria-label="$t('Done')" @click="done"><icons name="cross" class="w-6 h-6" /></button>
        <div class="cc-counts">
          <span v-if="canPhotos" class="cc-pill" :class="{ 'is-full': photoLeft <= 0 }"><icons name="image" class="w-3.5 h-3.5" />{{ photosTaken }} · {{ photoLeft }} {{ $t('left') }}</span>
          <span v-if="canVideos" class="cc-pill" :class="{ 'is-full': videoLeft <= 0 }"><icons name="video" class="w-3.5 h-3.5" />{{ videosTaken }} · {{ videoLeft }} {{ $t('left') }}</span>
        </div>
        <button v-if="canSwitch" type="button" class="cc-round" :aria-label="$t('Switch camera')" :disabled="recording" @click="flip"><icons name="refresh" class="w-6 h-6" /></button>
        <span v-else class="cc-round cc-ghost"></span>
      </div>

      <p v-if="error" class="cc-msg" role="alert">
        <icons name="exclamation" class="w-4 h-4" />{{ error }}
        <button v-if="fallback" type="button" class="cc-link" @click="$emit('fallback'); $emit('close')">{{ $t('Use the phone camera app instead') }}</button>
      </p>
      <p v-else-if="note" class="cc-msg is-note">{{ note }}</p>

      <!-- bottom controls -->
      <div class="cc-bottom">
        <div class="cc-strip" aria-hidden="true"><img v-for="t in strip" :key="t.id" :src="t.url" alt="" /></div>

        <div class="cc-controls">
          <div v-if="canVideos && canPhotos" class="cc-mode" role="tablist">
            <button type="button" :class="{ 'is-on': mode === 'photo' }" :disabled="recording" @click="setMode('photo')">{{ $t('Photo') }}</button>
            <button type="button" :class="{ 'is-on': mode === 'video' }" :disabled="recording" @click="setMode('video')">{{ $t('Video') }}</button>
          </div>

          <button v-if="mode === 'photo'" type="button" class="cc-shutter" :disabled="!ready || photoLeft <= 0" :aria-label="$t('Take photo')" @click="shoot"><span></span></button>
          <button v-else type="button" class="cc-shutter is-video" :class="{ 'is-rec': recording }" :disabled="!ready || (!recording && videoLeft <= 0)" :aria-label="recording ? $t('Stop recording') : $t('Start recording')" @click="toggleRecord"><span></span></button>

          <button type="button" class="cc-done" @click="done"><icons name="tick" class="w-5 h-5" />{{ $t('Done') }}</button>
        </div>
        <div v-if="recording" class="cc-timer"><i></i>{{ clock }}</div>
      </div>
    </div>
  </Teleport>
</template>

<script>
// Live in-page camera: keep taking photos (or record a clip) without leaving the page. Every shot is handed to the
// uploader, which applies the same limits as a file picker. Needs HTTPS (or localhost) — otherwise `fallback` lets the
// phone's own camera app take over.
let seq = 0;

export default {
  props: {
    canPhotos: Boolean,
    canVideos: Boolean,
    photoLeft: { type: Number, default: 0 },
    videoLeft: { type: Number, default: 0 },
    maxPx: { type: Number, default: 2048 },
    videoTypes: { type: Array, default: () => ['mp4', 'webm'] },
    videoMaxMb: { type: Number, default: 200 },
  },

  emits: ['capture', 'close', 'fallback'],

  data() {
    return {
      stream: null, facing: 'environment', mode: this.canPhotos ? 'photo' : 'video', ready: false, error: null, note: null, fallback: false,
      canSwitch: false, flash: false, photosTaken: 0, videosTaken: 0, strip: [], recording: false, seconds: 0, recorder: null, chunks: [], bytes: 0, timer: null,
    };
  },

  computed: {
    clock() {
      const m = String(Math.floor(this.seconds / 60)).padStart(2, '0');
      return m + ':' + String(this.seconds % 60).padStart(2, '0');
    },
  },

  mounted() {
    document.documentElement.style.overflow = 'hidden';
    this.start();
  },

  beforeUnmount() {
    this.stop();
    document.documentElement.style.overflow = '';
  },

  methods: {
    stop() {
      clearInterval(this.timer);
      if (this.recorder && this.recorder.state !== 'inactive') { try { this.recorder.stop(); } catch (e) { /* closing anyway */ } }
      if (this.stream) this.stream.getTracks().forEach(t => t.stop());
      this.stream = null;
      this.strip.forEach(t => URL.revokeObjectURL(t.url));
    },
    async start() {
      this.error = null;
      this.ready = false;
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        this.error = this.$t('This browser cannot open the camera here (it needs a secure https connection).');
        this.fallback = true;
        return;
      }
      if (this.stream) this.stream.getTracks().forEach(t => t.stop());
      try {
        this.stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: { ideal: this.facing }, width: { ideal: 1920 }, height: { ideal: 1080 } },
          audio: this.mode === 'video',
        });
        const v = this.$refs.video;
        v.srcObject = this.stream;
        await v.play().catch(() => {});
        this.ready = true;
        const devices = await navigator.mediaDevices.enumerateDevices().catch(() => []);
        this.canSwitch = devices.filter(d => d.kind === 'videoinput').length > 1;
      } catch (e) {
        this.fallback = true;
        this.error = e && e.name === 'NotAllowedError'
          ? this.$t('Camera permission was refused. Allow the camera for this site in your browser settings.')
          : this.$t('The camera could not be started.');
      }
    },
    flip() {
      this.facing = this.facing === 'environment' ? 'user' : 'environment';
      this.start();
    },
    setMode(m) {
      if (this.mode === m) return;
      this.mode = m;
      this.note = null;
      this.start(); // video needs the microphone as well
    },
    stamp() {
      const d = new Date();
      const p = n => String(n).padStart(2, '0');
      return d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' + p(d.getHours()) + p(d.getMinutes()) + p(d.getSeconds()) + '-' + (++seq);
    },
    shoot() {
      const v = this.$refs.video;
      if (!this.ready || !v.videoWidth || this.photoLeft <= 0) return;
      const scale = Math.min(1, this.maxPx / Math.max(v.videoWidth, v.videoHeight));
      const c = document.createElement('canvas');
      c.width = Math.round(v.videoWidth * scale);
      c.height = Math.round(v.videoHeight * scale);
      c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
      this.flash = true;
      setTimeout(() => (this.flash = false), 120);
      if (navigator.vibrate) navigator.vibrate(20);
      c.toBlob(blob => {
        if (!blob) return;
        const file = new File([blob], 'photo-' + this.stamp() + '.jpg', { type: 'image/jpeg' });
        this.photosTaken++;
        this.strip.push({ id: file.name, url: URL.createObjectURL(blob) });
        if (this.strip.length > 6) URL.revokeObjectURL(this.strip.shift().url);
        this.$emit('capture', file, 'photo');
      }, 'image/jpeg', 0.86);
    },
    pickMime() {
      if (typeof MediaRecorder === 'undefined') return null;
      const options = [['video/mp4;codecs=avc1', 'mp4'], ['video/mp4', 'mp4'], ['video/webm;codecs=vp9,opus', 'webm'], ['video/webm;codecs=vp8,opus', 'webm'], ['video/webm', 'webm']];
      return options.find(([m, ext]) => this.videoTypes.includes(ext) && MediaRecorder.isTypeSupported(m)) || null;
    },
    toggleRecord() {
      if (this.recording) { this.recorder.stop(); return; }
      const pick = this.pickMime();
      if (!pick) {
        this.note = this.$t('This browser cannot record a video in an allowed format. Use “Choose videos” instead.');
        return;
      }
      this.chunks = [];
      this.bytes = 0;
      this.recorder = new MediaRecorder(this.stream, { mimeType: pick[0] });
      this.recorder.ondataavailable = e => {
        if (e.data && e.data.size) {
          this.chunks.push(e.data);
          this.bytes += e.data.size;
          if (this.bytes > this.videoMaxMb * 1048576 * 0.92) this.recorder.stop(); // stay under the size limit
        }
      };
      this.recorder.onstop = () => {
        clearInterval(this.timer);
        this.recording = false;
        if (!this.chunks.length) return;
        const blob = new Blob(this.chunks, { type: pick[0].split(';')[0] });
        const file = new File([blob], 'video-' + this.stamp() + '.' + pick[1], { type: blob.type });
        this.videosTaken++;
        this.$emit('capture', file, 'video');
      };
      this.recorder.start(1000);
      this.recording = true;
      this.seconds = 0;
      this.timer = setInterval(() => this.seconds++, 1000);
    },
    done() {
      this.$emit('close');
    },
  },
};
</script>

<style scoped>
.cc { position: fixed; inset: 0; z-index: 10050; background: #000; color: #fff; display: flex; flex-direction: column; }
.cc-video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; background: #000; }
.cc-flash { position: absolute; inset: 0; background: #fff; opacity: 0.75; pointer-events: none; animation: cc-fade 0.15s ease-out forwards; }
@keyframes cc-fade { to { opacity: 0; } }
.cc-top { position: relative; z-index: 2; display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: calc(env(safe-area-inset-top, 0px) + 12px) 14px 12px; background: linear-gradient(#000a, transparent); }
.cc-round { width: 46px; height: 46px; display: grid; place-items: center; border-radius: 9999px; border: 0; color: #fff; background: rgba(255, 255, 255, 0.2); backdrop-filter: blur(6px); cursor: pointer; }
.cc-round:disabled { opacity: 0.4; }
.cc-ghost { background: transparent; backdrop-filter: none; }
.cc-counts { display: flex; flex-wrap: wrap; justify-content: center; gap: 6px; }
.cc-pill { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 9999px; background: rgba(0, 0, 0, 0.5); font-size: 0.8rem; font-weight: 800; }
.cc-pill.is-full { background: #b45309; }
.cc-msg { position: relative; z-index: 2; margin: 0 14px; padding: 10px 14px; border-radius: 0.9rem; background: rgba(190, 18, 60, 0.92); font-size: 0.85rem; font-weight: 700; display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.cc-msg.is-note { background: rgba(0, 0, 0, 0.65); }
.cc-link { margin-left: auto; padding: 6px 12px; border-radius: 9999px; border: 0; background: #fff; color: #be123c; font-weight: 800; font-size: 0.78rem; }
.cc-bottom { position: relative; z-index: 2; margin-top: auto; padding: 12px 16px calc(env(safe-area-inset-bottom, 0px) + 18px); background: linear-gradient(transparent, #000c); }
.cc-strip { display: flex; gap: 6px; justify-content: center; min-height: 52px; margin-bottom: 10px; }
.cc-strip img { width: 52px; height: 52px; object-fit: cover; border-radius: 0.6rem; border: 2px solid #fff; animation: cc-pop 0.25s ease; }
@keyframes cc-pop { from { transform: scale(0.4); opacity: 0; } }
.cc-controls { display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 12px; }
.cc-mode { justify-self: start; display: inline-flex; border-radius: 9999px; background: rgba(255, 255, 255, 0.18); padding: 3px; }
.cc-mode button { padding: 7px 14px; border-radius: 9999px; border: 0; background: transparent; color: #fff; font-weight: 800; font-size: 0.8rem; }
.cc-mode button.is-on { background: #fff; color: #000; }
.cc-shutter { grid-column: 2; width: 78px; height: 78px; display: grid; place-items: center; border-radius: 9999px; border: 4px solid #fff; background: transparent; cursor: pointer; touch-action: manipulation; }
.cc-shutter span { width: 58px; height: 58px; border-radius: 9999px; background: #fff; transition: transform 0.1s; }
.cc-shutter:active span { transform: scale(0.88); }
.cc-shutter:disabled { opacity: 0.4; }
.cc-shutter.is-video span { background: #e11d48; }
.cc-shutter.is-rec span { border-radius: 0.6rem; width: 32px; height: 32px; }
.cc-done { justify-self: end; display: inline-flex; align-items: center; gap: 6px; padding: 12px 18px; border-radius: 9999px; border: 0; background: #059669; color: #fff; font-weight: 800; font-size: 0.9rem; cursor: pointer; }
.cc-timer { display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 10px; font-weight: 800; font-variant-numeric: tabular-nums; }
.cc-timer i { width: 10px; height: 10px; border-radius: 9999px; background: #e11d48; animation: cc-blink 1s infinite; }
@keyframes cc-blink { 50% { opacity: 0.2; } }
@media (max-height: 480px) and (orientation: landscape) {
  .cc-bottom { position: absolute; right: 0; top: 0; bottom: 0; width: 150px; margin: 0; display: flex; flex-direction: column; justify-content: center; background: linear-gradient(90deg, transparent, #000c); }
  .cc-controls { grid-template-columns: 1fr; justify-items: center; }
  .cc-shutter { grid-column: 1; }
  .cc-strip { display: none; }
}
@media (prefers-reduced-motion: reduce) { .cc-strip img, .cc-timer i, .cc-flash { animation: none; } }
</style>

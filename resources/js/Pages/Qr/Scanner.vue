<template>
  <admin-layout :title="$t('QR Scanner')">
    <div class="lg-page px-4 md:px-0 qs-page">
      <div class="qs-layout">
        <!-- the "phone": a bezel with a notch on tablets and desktops, a bare full-width screen on phones -->
        <section class="qs-device" :class="'is-' + phase">
          <i class="qs-notch" aria-hidden="true"><b></b></i>
          <div class="qs-screen">
            <div id="qs-reader" class="qs-reader" aria-live="polite"></div>
            <div class="qs-shade" aria-hidden="true"></div>

            <!-- toolbar -->
            <div class="qs-top">
              <button type="button" class="qs-ic lg-tip tip-bottom" :class="{ 'is-on': torch }" :disabled="phase !== 'live' || !torchOk" :data-tip="$t('Light')" :aria-label="$t('Light')" @click="toggleTorch"><icons name="sparkles" class="w-5 h-5" /></button>
              <label class="qs-ic lg-tip tip-bottom" :data-tip="$t('Scan a photo')" :aria-label="$t('Scan a photo')"><icons name="image" class="w-5 h-5" /><input type="file" accept="image/*" class="hidden" @change="fromPhoto" /></label>
              <button type="button" class="qs-ic lg-tip tip-bottom" :disabled="phase !== 'live' || cameras.length < 2" :data-tip="$t('Switch camera')" :aria-label="$t('Switch camera')" @click="flip"><icons name="refresh" class="w-5 h-5" /></button>
            </div>

            <!-- viewfinder -->
            <div v-if="['live', 'starting', 'checking'].includes(phase)" class="qs-finder">
              <p class="qs-hint">{{ phase === 'starting' ? $t('Opening the camera...') : $t('Align the QR code within the frame') }}</p>
              <div class="qs-frame">
                <i class="c tl"></i><i class="c tr"></i><i class="c bl"></i><i class="c br"></i>
                <span v-if="phase === 'live'" class="qs-band"></span>
              </div>
            </div>

            <!-- idle -->
            <div v-if="phase === 'idle' || phase === 'error'" class="qs-cover">
              <div class="qs-orbit"><span class="qs-orbit-core"><icons name="qr-code" class="w-14 h-14" /></span></div>
              <h1>{{ $t('QR Scanner') }}</h1>
              <p>{{ $t('Scan a WIMS QR code and the record opens.') }}</p>
              <p v-if="message" class="qs-msg is-bad">{{ message }}</p>
              <p v-else-if="!secure" class="qs-msg">{{ $t('A phone needs a secure (https) connection to use the camera. You can still scan a photo or paste a link.') }}</p>
            </div>

            <!-- checking: the loading animation after a code is read -->
            <div v-if="phase === 'checking' || phase === 'found'" class="qs-load" role="status">
              <div class="qs-spin" :class="{ 'is-done': phase === 'found' }">
                <svg viewBox="0 0 120 120" aria-hidden="true"><circle class="bg" cx="60" cy="60" r="50" /><circle class="fg" cx="60" cy="60" r="50" /></svg>
                <span class="qs-spin-ico"><icons :name="phase === 'found' ? 'tick' : 'qr-code'" class="w-9 h-9" /></span>
              </div>
              <ol class="qs-steps">
                <li class="is-done"><i><icons name="tick" class="w-3 h-3" /></i>{{ $t('Code read') }}</li>
                <li :class="stepClass(2)"><i><icons name="tick" class="w-3 h-3" /></i>{{ $t('Checking your access') }}</li>
                <li :class="stepClass(3)"><i><icons name="tick" class="w-3 h-3" /></i>{{ $t('Opening the record') }}</li>
              </ol>
            </div>

            <!-- refused -->
            <div v-if="phase === 'denied'" class="qs-denied" role="alert">
              <span class="qs-lock"><icons :name="deny.icon" class="w-9 h-9" /></span>
              <b>{{ deny.title }}</b>
              <p>{{ deny.text }}</p>
              <button type="button" class="qs-pill" @click="again"><icons name="qr-code" class="w-5 h-5" />{{ $t('Scan another code') }}</button>
            </div>

            <!-- paste sheet -->
            <transition name="qs-sheet">
              <div v-if="sheet" class="qs-sheet" @click.self="sheet = false">
                <form class="qs-sheet-card" @submit.prevent="submitText">
                  <header><b>{{ $t('Paste a link or code') }}</b><button type="button" :aria-label="$t('Close')" @click="sheet = false"><icons name="cross" class="w-4 h-4" /></button></header>
                  <input ref="paste" v-model="text" type="text" autocomplete="off" :placeholder="$t('https://…/q/… or the code')" :aria-label="$t('QR link or code')" />
                  <p v-if="textError" class="qs-bad">{{ textError }}</p>
                  <button type="submit" class="qs-pill is-block" :disabled="!text.trim()"><icons name="forward" class="w-4 h-4" />{{ $t('Open') }}</button>
                </form>
              </div>
            </transition>

            <!-- dock -->
            <nav class="qs-dock">
              <Link v-if="can.history" :href="route('qr-codes.scans')" class="qs-dk"><icons name="list" class="w-5 h-5" /><span>{{ $t('History') }}</span></Link>
              <span v-else class="qs-dk is-ghost"></span>
              <button type="button" class="qs-main" :class="{ 'is-live': phase === 'live' }" :disabled="busy" :aria-label="phase === 'live' ? $t('Stop') : $t('Open the camera')" @click="phase === 'live' ? stop() : start()">
                <icons :name="phase === 'live' ? 'cross' : 'camera'" class="w-7 h-7" />
              </button>
              <button type="button" class="qs-dk" @click="openSheet"><icons name="link" class="w-5 h-5" /><span>{{ $t('Paste') }}</span></button>
            </nav>
          </div>
        </section>

        <aside class="qs-side">
          <section class="qs-card">
            <h2><icons name="clock" class="w-4 h-4" />{{ $t('Your recent scans') }}</h2>
            <ul v-if="recent.length" class="qs-recent">
              <li v-for="(r, i) in recent" :key="i"><span class="qs-dot" :class="'is-' + r.status"></span><span class="min-w-0"><b>{{ r.reference || r.kind || $t('A record') }}</b><small>{{ r.kind }} · {{ r.status_label }}</small></span><em>{{ r.at }}</em></li>
            </ul>
            <p v-else class="qs-empty">{{ $t('Nothing scanned yet.') }}</p>
            <Link v-if="can.history" :href="route('qr-codes.scans')" class="qs-more">{{ $t('See your scan history') }} →</Link>
          </section>
          <p class="qs-foot"><icons name="lock-closed" class="w-4 h-4" />{{ $t('A code only opens a record for people who are signed in and allowed to see it.') }}</p>
          <Link v-if="can.manage" :href="route('qr-codes.settings')" class="qs-gear"><icons name="cog" class="w-4 h-4" />{{ $t('QR code settings') }}</Link>
        </aside>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

// The WIMS QR scanner. A code is read (camera, photo or pasted link), the server is asked whether this person may open it, and only then
// is the record opened. A refusal is explained on the screen itself, before any error page. Nothing here decides what a person may see.
const READER = 'qs-reader';
let libPromise = null;
const lib = () => (libPromise ||= import('html5-qrcode'));

export default {
  components: { AdminLayout, Link },
  props: { can: Object, recent: Array },
  data() {
    return { deny: { icon: 'lock-closed', title: '', text: '' }, phase: 'idle', step: 0, busy: false, message: '', sheet: false, text: '', textError: '', cameras: [], camIndex: 0, torchOk: false, torch: false, scanner: null, checking: false, secure: typeof window === 'undefined' ? true : window.isSecureContext };
  },
  mounted() {
    // load the reader while the page paints, so the camera opens the moment it is asked for; reopen it at once when access was already granted
    lib();
    try {
      navigator.permissions?.query({ name: 'camera' }).then(p => p.state === 'granted' && this.secure && this.start()).catch(() => {});
    } catch (e) {
      /* the browser does not say: the person taps the button */
    }
  },
  beforeUnmount() {
    this.stop(true);
  },
  methods: {
    stepClass(n) {
      return this.step > n ? 'is-done' : this.step === n ? 'is-now' : '';
    },
    // the scan token inside whatever was read: our own address (/q/<token>) or the bare token
    tokenOf(raw) {
      const t = String(raw || '').trim();
      const m = t.match(/\/q\/([A-Za-z0-9]{8,64})(?:[/?#]|$)/);
      if (m) return m[1];
      return /^[A-Za-z0-9]{12,40}$/.test(t) ? t : null;
    },
    openSheet() {
      this.sheet = true;
      this.$nextTick(() => this.$refs.paste && this.$refs.paste.focus());
    },
    async go(raw) {
      const token = this.tokenOf(raw);
      if (!token) return false;
      if (this.checking) return true;
      this.checking = true;
      this.sheet = false;
      this.phase = 'checking';
      this.step = 2;
      if (navigator.vibrate) navigator.vibrate(40);
      const started = Date.now();
      this.stop(true); // the camera is released straight away: the rest is the server's answer
      try {
        const { data } = await axios.get(route('qr-codes.check', token));
        // let the animation be seen, even on a fast connection
        await new Promise(r => setTimeout(r, Math.max(0, 650 - (Date.now() - started))));
        if (data.status === 'allowed') {
          this.step = 3;
          this.phase = 'found';
          if (navigator.vibrate) navigator.vibrate(60);
          setTimeout(() => this.$inertia.visit(data.go), 380);
          return true;
        }
        this.showDenied(data);
      } catch (e) {
        this.phase = 'error';
        this.checking = false;
        this.message = this.$t('The code could not be checked. Check your connection and try again.');
      }
      return true;
    },
    showDenied(d) {
      const kind = d.kind || this.$t('record');
      const map = {
        no_permission: { icon: 'lock-closed', title: this.$t('You do not have permission'), text: this.$t('You are not allowed to view this :kind. Ask an administrator if you need access.', { kind }) },
        disabled: { icon: 'toggle', title: this.$t('QR codes are off for this module'), text: this.$t('QR codes for :kind are switched off right now.', { kind }) },
        unavailable: { icon: 'exclamation', title: this.$t('Record not available'), text: this.$t('This :kind was removed, so it cannot be opened.', { kind }) },
        invalid: { icon: 'x-circle', title: this.$t('Not a valid code'), text: this.$t('This code is not recognised, or it was replaced by a newer one.') },
      };
      this.deny = map[d.status] || map.invalid;
      if (navigator.vibrate) navigator.vibrate([80, 60, 80]);
      this.phase = 'denied';
    },
    again() {
      this.checking = false;
      this.message = '';
      this.phase = 'idle';
      this.start();
    },
    async start() {
      if (this.busy || this.phase === 'live' || this.phase === 'starting') return;
      this.busy = true;
      this.message = '';
      this.checking = false;
      this.phase = 'starting';
      try {
        const { Html5Qrcode } = await lib();
        this.cameras = await Html5Qrcode.getCameras().catch(() => []);
        const back = this.cameras.findIndex(c => /back|rear|environment/i.test(c.label));
        this.camIndex = back >= 0 ? back : 0;
        await this.run(Html5Qrcode);
      } catch (e) {
        this.fail(e);
      }
      this.busy = false;
    },
    async run(Html5Qrcode) {
      await this.stop(true);
      this.scanner = new Html5Qrcode(READER, { verbose: false });
      const cam = this.cameras[this.camIndex];
      const box = document.getElementById(READER);
      const ratio = box && box.clientWidth ? Math.max(0.5, Math.min(2.2, box.clientHeight / box.clientWidth)) : 1.6;
      await this.scanner.start(
        cam ? cam.id : { facingMode: 'environment' },
        { fps: 15, aspectRatio: ratio, disableFlip: true, qrbox: w => ({ width: Math.floor(Math.min(w.width, w.height) * 0.68), height: Math.floor(Math.min(w.width, w.height) * 0.68) }) },
        text => {
          if (this.phase === 'live' && this.go(text) === false) this.message = this.$t('That is not a WIMS QR code.');
        },
        () => {}
      );
      this.phase = 'live';
      try {
        const caps = this.scanner.getRunningTrackCapabilities();
        this.torchOk = !!(caps && caps.torch);
      } catch (e) {
        this.torchOk = false;
      }
    },
    fail(e) {
      this.phase = 'error';
      const name = (e && (e.name || e.message || String(e))) + '';
      this.message = /Permission|NotAllowed|denied/i.test(name) ? this.$t('The camera is blocked. Allow camera access for this site, then try again.') : /NotFound|no camera|Requested device/i.test(name) ? this.$t('No camera was found on this device.') : this.$t('The camera could not be opened.');
    },
    async stop(keepPhase) {
      const s = this.scanner;
      this.scanner = null;
      this.torch = false;
      this.torchOk = false;
      if (s) {
        try { await s.stop(); } catch (e) { /* already stopped */ }
        try { s.clear(); } catch (e) { /* nothing to clear */ }
      }
      if (keepPhase !== true && ['live', 'starting'].includes(this.phase)) this.phase = 'idle';
    },
    async flip() {
      this.camIndex = (this.camIndex + 1) % this.cameras.length;
      this.phase = 'starting';
      try {
        const { Html5Qrcode } = await lib();
        await this.run(Html5Qrcode);
      } catch (e) {
        this.fail(e);
      }
    },
    async toggleTorch() {
      try {
        this.torch = !this.torch;
        await this.scanner.applyVideoConstraints({ advanced: [{ torch: this.torch }] });
      } catch (e) {
        this.torch = false;
      }
    },
    async fromPhoto(event) {
      const file = event.target.files && event.target.files[0];
      event.target.value = '';
      if (!file) return;
      this.message = '';
      try {
        await this.stop(true);
        const { Html5Qrcode } = await lib();
        const reader = new Html5Qrcode(READER, { verbose: false });
        const text = await reader.scanFile(file, false);
        try { reader.clear(); } catch (e) { /* nothing */ }
        if (!(await this.go(text))) {
          this.phase = 'error';
          this.message = this.$t('That is not a WIMS QR code.');
        }
      } catch (e) {
        this.phase = 'error';
        this.message = this.$t('No QR code was found in that photo. Try again with the code sharper and filling more of the picture.');
      }
    },
    async submitText() {
      this.textError = '';
      if (!(await this.go(this.text))) this.textError = this.$t('That is not a WIMS QR link or code.');
    },
  },
};
</script>

<style scoped>
.qs-page { max-width: 980px; margin: 0 auto; padding-bottom: 5rem; }
.qs-layout { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 2rem; align-items: start; justify-content: center; }
@media (max-width: 1023.98px) { .qs-layout { grid-template-columns: minmax(0, 1fr); justify-items: center; gap: 1.2rem; } .qs-side { width: 100%; max-width: 420px; } }

/* ---- the device ---- */
.qs-device { position: relative; width: 372px; padding: 14px; border-radius: 52px; background: linear-gradient(150deg, #1f2937, #0b1220 55%, #111c30); box-shadow: 0 50px 80px -34px rgba(8, 15, 30, 0.85), 0 0 0 2px #334155, inset 0 0 0 2px #0b1220, inset 0 2px 3px rgba(255, 255, 255, 0.12); }
.qs-notch { position: absolute; z-index: 8; top: 14px; left: 50%; transform: translateX(-50%); width: 38%; height: 26px; border-radius: 0 0 18px 18px; background: #0b1220; }
.qs-notch b { position: absolute; top: 9px; right: 22%; width: 8px; height: 8px; border-radius: 9999px; background: radial-gradient(circle at 35% 35%, #475569, #020617); }
.qs-screen { position: relative; height: 700px; overflow: hidden; border-radius: 40px; background: radial-gradient(circle at 50% 35%, #16294a, #070d19); isolation: isolate; }
@media (max-width: 1023.98px) { .qs-device { width: min(100%, 392px); } }
/* a phone IS the device: no bezel, just the screen */
@media (max-width: 575.98px) {
  .qs-device { width: 100%; padding: 0; border-radius: 28px; background: none; box-shadow: none; }
  .qs-notch { display: none; }
  .qs-screen { height: clamp(500px, calc(100dvh - 230px), 720px); border-radius: 28px; box-shadow: 0 30px 50px -28px rgba(8, 15, 30, 0.8); }
}

.qs-reader { position: absolute; inset: 0; z-index: 0; }
.qs-reader :deep(video) { width: 100% !important; height: 100% !important; object-fit: cover; }
.qs-reader :deep(#qr-shaded-region) { display: none !important; }
.qs-reader :deep(img) { display: none; }
.qs-shade { position: absolute; inset: 0; z-index: 1; pointer-events: none; background: linear-gradient(180deg, rgba(7, 13, 25, 0.72), rgba(7, 13, 25, 0.1) 26%, rgba(7, 13, 25, 0.1) 62%, rgba(7, 13, 25, 0.85)); }

/* toolbar */
.qs-top { position: absolute; z-index: 5; top: 46px; left: 50%; transform: translateX(-50%); display: flex; gap: 6px; padding: 5px; border-radius: 18px; background: rgba(15, 23, 42, 0.62); backdrop-filter: blur(10px); box-shadow: 0 10px 20px -10px rgba(0, 0, 0, 0.7), inset 0 0 0 1px rgba(186, 230, 253, 0.22); }
@media (max-width: 575.98px) { .qs-top { top: 16px; } }
.qs-ic { display: inline-grid; place-items: center; width: 52px; height: 40px; border-radius: 13px; color: #e0f2fe; cursor: pointer; transition: background-color 0.15s ease, transform 0.15s ease; }
.qs-ic:hover:not(:disabled), .qs-ic.is-on { background: rgba(56, 189, 248, 0.32); } .qs-ic:active { transform: scale(0.94); } .qs-ic:disabled { opacity: 0.35; cursor: default; }

/* viewfinder */
.qs-finder { position: absolute; z-index: 3; inset: 0; display: grid; align-content: center; justify-items: center; gap: 18px; pointer-events: none; }
.qs-hint { margin: 0; padding: 0 28px; text-align: center; font-size: 0.86rem; font-weight: 700; color: #f0f9ff; text-shadow: 0 2px 8px rgba(0, 0, 0, 0.8); }
.qs-frame { position: relative; width: 68%; aspect-ratio: 1; }
.qs-frame .c { position: absolute; width: 16%; height: 16%; border: 4px solid #fcd34d; filter: drop-shadow(0 0 8px rgba(252, 211, 77, 0.7)); }
.qs-frame .tl { top: 0; left: 0; border-right: 0; border-bottom: 0; border-top-left-radius: 20px; } .qs-frame .tr { top: 0; right: 0; border-left: 0; border-bottom: 0; border-top-right-radius: 20px; } .qs-frame .bl { bottom: 0; left: 0; border-right: 0; border-top: 0; border-bottom-left-radius: 20px; } .qs-frame .br { bottom: 0; right: 0; border-left: 0; border-top: 0; border-bottom-right-radius: 20px; }
.qs-band { position: absolute; left: 3%; right: 3%; top: 0; height: 34%; border-radius: 12px; background: linear-gradient(180deg, rgba(252, 211, 77, 0), rgba(252, 211, 77, 0.38) 88%, #fcd34d 96%, rgba(252, 211, 77, 0.2)); animation: qs-sweep 2s ease-in-out infinite alternate; }
@keyframes qs-sweep { from { transform: translateY(-4%); } to { transform: translateY(190%); } }
.qs-device.is-checking .qs-reader { filter: blur(6px) brightness(0.6); transition: filter 0.3s ease; }

/* idle cover */
.qs-cover { position: absolute; z-index: 4; inset: 0; display: grid; align-content: center; justify-items: center; gap: 0.7rem; padding: 1.4rem 1.6rem 6rem; text-align: center; color: #e0f2fe; }
.qs-cover h1 { margin: 0.4rem 0 0; font-size: 1.4rem; font-weight: 900; } .qs-cover p { margin: 0; max-width: 280px; font-size: 0.86rem; line-height: 1.5; color: #bae6fd; }
.qs-msg { padding: 0.5rem 0.8rem; border-radius: 12px; background: rgba(148, 163, 184, 0.16); } .qs-msg.is-bad { color: #fecdd3 !important; background: rgba(225, 29, 72, 0.25); font-weight: 700; }
.qs-orbit { position: relative; display: grid; place-items: center; width: 150px; height: 150px; }
.qs-orbit::before, .qs-orbit::after { content: ''; position: absolute; inset: 0; border-radius: 9999px; border: 2px solid rgba(125, 211, 252, 0.5); animation: qs-ping 2.8s ease-out infinite; } .qs-orbit::after { animation-delay: 1.4s; }
.qs-orbit-core { display: grid; place-items: center; width: 92px; height: 92px; border-radius: 30px; color: #e0f2fe; background: linear-gradient(145deg, rgba(99, 102, 241, 0.6), rgba(14, 165, 233, 0.55)); box-shadow: 0 0 44px rgba(56, 189, 248, 0.5), inset 0 1px 1px rgba(255, 255, 255, 0.4); animation: qs-float 3.4s ease-in-out infinite; }
@keyframes qs-ping { 0% { transform: scale(0.7); opacity: 0.9; } 100% { transform: scale(1.4); opacity: 0; } }
@keyframes qs-float { 0%, 100% { transform: translateY(0) rotate(0); } 50% { transform: translateY(-6px) rotate(3deg); } }

/* loading after a scan */
.qs-load { position: absolute; z-index: 6; inset: 0; display: grid; align-content: center; justify-items: center; gap: 1.4rem; padding-bottom: 5rem; background: radial-gradient(circle at 50% 40%, rgba(15, 40, 80, 0.55), rgba(5, 10, 22, 0.88)); animation: qs-fade 0.25s ease; }
@keyframes qs-fade { from { opacity: 0; } }
.qs-spin { position: relative; display: grid; place-items: center; width: 130px; height: 130px; }
.qs-spin svg { position: absolute; inset: 0; width: 100%; height: 100%; transform: rotate(-90deg); }
.qs-spin circle { fill: none; stroke-width: 7; } .qs-spin .bg { stroke: rgba(186, 230, 253, 0.16); }
.qs-spin .fg { stroke: url(#none); stroke: #67e8f9; stroke-linecap: round; stroke-dasharray: 314; stroke-dashoffset: 235; filter: drop-shadow(0 0 8px rgba(103, 232, 249, 0.8)); transform-origin: 60px 60px; animation: qs-rot 1.05s linear infinite; }
@keyframes qs-rot { to { transform: rotate(360deg); } }
.qs-spin-ico { display: grid; place-items: center; width: 74px; height: 74px; border-radius: 9999px; color: #e0f2fe; background: linear-gradient(145deg, rgba(99, 102, 241, 0.55), rgba(14, 165, 233, 0.5)); animation: qs-beat 1.1s ease-in-out infinite; }
@keyframes qs-beat { 50% { transform: scale(1.08); } }
.qs-spin.is-done .fg { animation: none; stroke: #34d399; stroke-dashoffset: 0; transition: stroke-dashoffset 0.4s ease; filter: drop-shadow(0 0 10px rgba(52, 211, 153, 0.9)); }
.qs-spin.is-done .qs-spin-ico { color: #fff; background: linear-gradient(145deg, #34d399, #059669); animation: qs-bounce 0.5s cubic-bezier(0.34, 1.56, 0.64, 1); }
@keyframes qs-bounce { from { transform: scale(0.3); } }
.qs-steps { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.qs-steps li { display: flex; align-items: center; gap: 10px; font-size: 0.86rem; font-weight: 700; color: rgba(186, 230, 253, 0.5); transition: color 0.25s ease; }
.qs-steps i { display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 9999px; color: transparent; border: 2px solid rgba(186, 230, 253, 0.3); transition: all 0.25s ease; }
.qs-steps li.is-now { color: #e0f2fe; } .qs-steps li.is-now i { border-color: #67e8f9; border-top-color: transparent; animation: qs-rot 0.8s linear infinite; }
.qs-steps li.is-done { color: #a7f3d0; } .qs-steps li.is-done i { color: #fff; background: #10b981; border-color: #10b981; }

/* refused */
.qs-denied { position: absolute; z-index: 6; inset: 0; display: grid; align-content: center; justify-items: center; gap: 0.5rem; padding: 1.4rem 1.6rem 6rem; text-align: center; color: #fff1f2; background: radial-gradient(circle at 50% 30%, rgba(159, 18, 57, 0.97), rgba(76, 5, 25, 0.99)); animation: qs-fade 0.25s ease; }
.qs-denied b { font-size: 1.2rem; } .qs-denied p { margin: 0; max-width: 290px; font-size: 0.86rem; line-height: 1.5; color: #fecdd3; }
.qs-lock { display: grid; place-items: center; width: 90px; height: 90px; border-radius: 9999px; color: #fff; background: linear-gradient(145deg, #fb7185, #be123c); box-shadow: 0 0 0 10px rgba(251, 113, 133, 0.22), 0 0 44px rgba(251, 113, 133, 0.55); animation: qs-shake 0.5s ease; }
@keyframes qs-shake { 0%, 100% { transform: translateX(0); } 20% { transform: translateX(-9px); } 40% { transform: translateX(8px); } 60% { transform: translateX(-5px); } 80% { transform: translateX(3px); } }
.qs-pill { display: inline-flex; align-items: center; justify-content: center; gap: 9px; margin-top: 0.7rem; padding: 0.8rem 1.5rem; border-radius: 9999px; font-size: 0.92rem; font-weight: 800; color: #0b1220; background: linear-gradient(145deg, #fef3c7, #fcd34d); box-shadow: 0 16px 28px -14px rgba(252, 211, 77, 0.9), inset 0 1px 1px #fff; } .qs-pill:disabled { opacity: 0.5; } .qs-pill.is-block { width: 100%; margin-top: 0.4rem; }

/* paste sheet */
.qs-sheet { position: absolute; z-index: 9; inset: 0; display: grid; align-items: end; background: rgba(5, 10, 22, 0.55); }
.qs-sheet-card { display: grid; gap: 0.7rem; padding: 1rem 1rem 1.2rem; border-radius: 28px 28px 0 0; background: #f8fafc; box-shadow: 0 -20px 40px -20px rgba(0, 0, 0, 0.6); }
.qs-sheet-card header { display: flex; align-items: center; justify-content: space-between; } .qs-sheet-card header b { color: #0f172a; } .qs-sheet-card header button { display: grid; place-items: center; width: 32px; height: 32px; border-radius: 10px; color: #475569; background: #e2e8f0; }
.qs-sheet-card input { height: 46px; padding: 0 14px; border-radius: 14px; border: 1px solid #cbd5e1; background: #fff; color: #0f172a; font-size: 0.88rem; box-shadow: 0 10px 16px -12px rgba(15, 37, 64, 0.55); }
.qs-bad { margin: 0; font-size: 0.78rem; font-weight: 700; color: #be123c; }
.qs-sheet-enter-active, .qs-sheet-leave-active { transition: opacity 0.2s ease; } .qs-sheet-enter-active .qs-sheet-card, .qs-sheet-leave-active .qs-sheet-card { transition: transform 0.25s cubic-bezier(0.2, 0.8, 0.2, 1); } .qs-sheet-enter-from, .qs-sheet-leave-to { opacity: 0; } .qs-sheet-enter-from .qs-sheet-card, .qs-sheet-leave-to .qs-sheet-card { transform: translateY(100%); }

/* dock */
.qs-dock { position: absolute; z-index: 7; left: 14px; right: 14px; bottom: 14px; display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 28px; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(12px); box-shadow: 0 16px 30px -14px rgba(0, 0, 0, 0.8), inset 0 0 0 1px rgba(186, 230, 253, 0.2); }
.qs-dk { display: grid; justify-items: center; gap: 2px; padding: 6px 4px; border-radius: 14px; font-size: 0.68rem; font-weight: 800; color: #bae6fd; text-decoration: none; transition: color 0.15s ease, background-color 0.15s ease; } .qs-dk:hover { color: #fff; background: rgba(148, 163, 184, 0.16); } .qs-dk.is-ghost { visibility: hidden; }
.qs-main { display: grid; place-items: center; width: 66px; height: 66px; margin-top: -26px; border-radius: 9999px; color: #422006; background: linear-gradient(145deg, #fde68a, #f59e0b); box-shadow: 0 0 0 6px rgba(15, 23, 42, 0.85), 0 16px 26px -10px rgba(245, 158, 11, 0.9), inset 0 1px 2px rgba(255, 255, 255, 0.7); transition: transform 0.15s ease; }
.qs-main:hover:not(:disabled) { transform: translateY(-2px) scale(1.04); } .qs-main:active { transform: scale(0.94); } .qs-main:disabled { opacity: 0.7; } .qs-main.is-live { color: #fff; background: linear-gradient(145deg, #fb7185, #be123c); box-shadow: 0 0 0 6px rgba(15, 23, 42, 0.85), 0 16px 26px -10px rgba(225, 29, 72, 0.9); }
.qs-main:not(.is-live):not(:disabled) { animation: qs-pulse 2.4s ease-in-out infinite; }
@keyframes qs-pulse { 0%, 100% { box-shadow: 0 0 0 6px rgba(15, 23, 42, 0.85), 0 16px 26px -10px rgba(245, 158, 11, 0.9), 0 0 0 6px rgba(252, 211, 77, 0); } 50% { box-shadow: 0 0 0 6px rgba(15, 23, 42, 0.85), 0 16px 26px -10px rgba(245, 158, 11, 0.9), 0 0 0 16px rgba(252, 211, 77, 0.18); } }

/* side cards */
.qs-side { display: grid; gap: 1rem; align-content: start; min-width: 0; padding-top: 1rem; }
.qs-card { padding: 1rem 1.1rem; border-radius: 22px; background: var(--lg-card); box-shadow: 0 22px 36px -26px rgba(15, 37, 64, 0.6), 0 0 0 1px var(--lg-line); }
.qs-card h2 { display: flex; align-items: center; gap: 8px; margin: 0 0 0.7rem; font-size: 0.74rem; font-weight: 800; letter-spacing: 0.07em; text-transform: uppercase; color: var(--lg-faint); }
.qs-recent { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; } .qs-recent li { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 13px; background: var(--lg-soft); box-shadow: 0 8px 12px -10px rgba(15, 37, 64, 0.5); }
.qs-recent .min-w-0 { flex: 1; display: grid; line-height: 1.25; } .qs-recent b { font-size: 0.84rem; color: var(--lg-ink); overflow-wrap: anywhere; } .qs-recent small { font-size: 0.7rem; color: var(--lg-faint); } .qs-recent em { font-style: normal; font-size: 0.7rem; color: var(--lg-faint); white-space: nowrap; }
.qs-dot { flex: none; width: 9px; height: 9px; border-radius: 9999px; background: linear-gradient(145deg, #34d399, #0ea5e9); } .qs-dot.is-no_permission { background: linear-gradient(145deg, #fb7185, #be123c); } .qs-dot.is-unavailable, .qs-dot.is-disabled, .qs-dot.is-invalid { background: linear-gradient(145deg, #fbbf24, #d97706); }
.qs-empty { margin: 0; font-size: 0.82rem; color: var(--lg-faint); }
.qs-more { display: inline-block; margin-top: 0.7rem; font-size: 0.82rem; font-weight: 800; color: #2563eb; }
.qs-foot { display: flex; align-items: flex-start; gap: 8px; margin: 0; font-size: 0.76rem; line-height: 1.45; color: var(--lg-faint); } .qs-foot svg { flex: none; margin-top: 2px; }
.qs-gear { display: inline-flex; align-items: center; gap: 8px; justify-self: start; padding: 0.5rem 1rem; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; color: var(--lg-ink); background: var(--lg-card); box-shadow: 0 10px 16px -10px rgba(15, 37, 64, 0.6), 0 0 0 1px var(--lg-line); }
@media (prefers-reduced-motion: reduce) { .qs-band, .qs-orbit::before, .qs-orbit::after, .qs-orbit-core, .qs-spin .fg, .qs-spin-ico, .qs-lock, .qs-main { animation: none !important; } }
</style>

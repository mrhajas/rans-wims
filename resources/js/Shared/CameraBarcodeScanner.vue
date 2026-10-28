<template>
  <teleport to="body">
      <div v-if="open" class="cbs-overlay" role="dialog" aria-modal="true" :aria-label="title || $t('Scan Barcode')" @mousedown.self="close">
        <!-- the same "phone" as the QR scanner: a bezel with a notch on tablets and desktops, the whole screen on a phone -->
        <section class="cbs-device" :class="'is-' + phase">
          <i class="cbs-notch" aria-hidden="true"><b></b></i>
          <div class="cbs-screen">
            <div :id="scannerElementId" class="cbs-reader" aria-live="polite"></div>
            <div class="cbs-shade" aria-hidden="true"></div>

            <!-- toolbar -->
            <div class="cbs-top">
              <button type="button" class="cbs-ic lg-tip tip-bottom" :class="{ 'is-on': torch }" :disabled="phase !== 'live' || !torchOk" :data-tip="$t('Light')" :aria-label="$t('Light')" @click="toggleTorch"><icons name="sparkles" class="w-5 h-5" /></button>
              <label class="cbs-ic lg-tip tip-bottom" :data-tip="$t('Scan a photo')" :aria-label="$t('Scan a photo')"><icons name="image" class="w-5 h-5" /><input ref="photo" type="file" accept="image/*" class="hidden" @change="fromPhoto" /></label>
              <button type="button" class="cbs-ic lg-tip tip-bottom" :disabled="phase !== 'live' || cameras.length < 2" :data-tip="$t('Switch camera')" :aria-label="$t('Switch camera')" @click="flip"><icons name="refresh" class="w-5 h-5" /></button>
            </div>

            <!-- viewfinder -->
            <div v-if="['live', 'starting'].includes(phase)" class="cbs-finder">
              <p class="cbs-hint">{{ phase === 'starting' ? $t('Opening the camera...') : $t('Align the barcode or QR code within the frame') }}</p>
              <div class="cbs-frame">
                <i class="c tl"></i><i class="c tr"></i><i class="c bl"></i><i class="c br"></i>
                <span v-if="phase === 'live'" class="cbs-band"></span>
              </div>
              <p v-if="phase === 'live'" class="cbs-sub">{{ $t('Hold the barcode or QR code steady for 1-2 seconds.') }}</p>
            </div>

            <!-- idle / the camera could not open -->
            <div v-if="phase === 'idle' || phase === 'error'" class="cbs-cover">
              <div class="cbs-orbit"><span class="cbs-orbit-core"><icons name="camera" class="w-12 h-12" /></span></div>
              <h1>{{ title || $t('Scan Barcode') }}</h1>
              <p>{{ hint || $t('Point your camera at the barcode. The item will be added automatically after scan.') }}</p>
              <p v-if="message" class="cbs-msg is-bad" role="alert">{{ message }}</p>
              <p v-else-if="!canUseLiveCamera" class="cbs-msg">{{ $t('Live camera requires HTTPS on mobile. Use camera photo scan or open app on HTTPS.') }}</p>
            </div>

            <!-- after a code is read -->
            <div v-if="phase === 'checking' || phase === 'found'" class="cbs-load" role="status">
              <div class="cbs-spin" :class="{ 'is-done': phase === 'found' }">
                <svg viewBox="0 0 120 120" aria-hidden="true"><circle class="bg" cx="60" cy="60" r="50" /><circle class="fg" cx="60" cy="60" r="50" /></svg>
                <span class="cbs-spin-ico"><icons :name="phase === 'found' ? 'tick' : 'search'" class="w-9 h-9" /></span>
              </div>
              <p v-if="detected" class="cbs-code">{{ detected }}</p>
              <ol class="cbs-steps">
                <li class="is-done"><i><icons name="tick" class="w-3 h-3" /></i>{{ $t('Code read') }}</li>
                <li :class="stepClass(2)"><i><icons name="tick" class="w-3 h-3" /></i>{{ $t('Finding the item') }}</li>
                <li :class="stepClass(3)"><i><icons name="tick" class="w-3 h-3" /></i>{{ doneText || $t('Adding it to the list') }}</li>
              </ol>
            </div>

            <!-- nothing matched -->
            <div v-if="phase === 'notfound'" class="cbs-denied" role="alert">
              <span class="cbs-lock"><icons name="exclamation" class="w-9 h-9" /></span>
              <b>{{ $t('No item matched') }}</b>
              <p>{{ problem || $t('Barcode scanned but no item matched. Please verify the barcode is saved as Item Code/SKU.') }}</p>
              <p v-if="detected" class="cbs-code is-bad">{{ detected }}</p>
              <button type="button" class="cbs-pill" @click="again"><icons name="camera" class="w-5 h-5" />{{ $t('Scan another barcode') }}</button>
            </div>

            <!-- manual entry sheet -->
              <div v-if="sheet" class="cbs-sheet" @click.self="sheet = false">
                <form class="cbs-sheet-card" @submit.prevent="submitManualBarcode">
                  <header><b>{{ $t('Enter the barcode') }}</b><button type="button" :aria-label="$t('Close')" @click="sheet = false"><icons name="cross" class="w-4 h-4" /></button></header>
                  <input ref="manual" v-model="manualBarcode" type="text" inputmode="text" autocomplete="off" :placeholder="$t('Or enter barcode manually')" :aria-label="$t('Barcode')" />
                  <p v-if="manualError" class="cbs-bad">{{ manualError }}</p>
                  <button type="submit" class="cbs-pill is-block" :disabled="!manualBarcode.trim() || checking"><icons name="search" class="w-4 h-4" />{{ $t('Find Item') }}</button>
                </form>
              </div>

            <!-- dock -->
            <nav class="cbs-dock">
              <button type="button" class="cbs-dk" @click="openSheet"><icons name="search" class="w-5 h-5" /><span>{{ $t('Manual') }}</span></button>
              <button type="button" class="cbs-main" :class="{ 'is-live': phase === 'live' }" :disabled="busy || checking" :aria-label="phase === 'live' ? $t('Stop') : $t('Open the camera')" @click="phase === 'live' ? stop() : start()">
                <icons :name="phase === 'live' ? 'cross' : 'camera'" class="w-7 h-7" />
              </button>
              <button type="button" class="cbs-dk" @click="close"><icons name="chevron-down" class="w-5 h-5" /><span>{{ $t('Close') }}</span></button>
            </nav>
          </div>
        </section>
      </div>
  </teleport>
</template>

<script>
import { v4 as uuidv4 } from 'uuid';

// Drop-in live/photo barcode scanner shared by every "Scan barcode or search items" transaction form (Checkin, Checkout, Adjustment, Transfer, ...).
// It looks and behaves like the WIMS QR scanner (Qr/Scanner.vue): camera, light, photo, switch camera, and a typed barcode.
// Every code is resolved through the items.scan endpoint and item-matching rules, then the full item is handed to the caller's itemSelected().
let libPromise = null;
const lib = () => (libPromise ||= import('html5-qrcode'));

export default {
  emits: ['resolved'],

  props: {
    // wording that fits the page: the window's title, the line under it, the last step ("Opening the item")
    title: { type: String, default: '' },
    hint: { type: String, default: '' },
    doneText: { type: String, default: '' },
    // For a page that does its own thing with a code (looks the item up among the warehouse's items, opens it, ...): lookup(code) returns
    // true when it handled the code, or false / a message (shown on the red screen) when it did not. Without it, the code is matched to an
    // item by the server and the whole item is handed to the page through `resolved`.
    lookup: { type: Function, default: null },
  },

  data() {
    return {
      open: false,
      phase: 'idle', // idle | starting | live | checking | found | notfound | error
      step: 0,
      busy: false,
      checking: false,
      message: '',
      detected: '',
      problem: '',
      sheet: false,
      manualBarcode: '',
      manualError: '',
      cameras: [],
      camIndex: 0,
      torch: false,
      torchOk: false,
      scanner: null,
      lastScannedCode: null,
      scannerElementId: 'camera-barcode-scanner-' + uuidv4(),
      canUseLiveCamera: typeof window !== 'undefined' ? window.isSecureContext && !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia) : false,
    };
  },

  unmounted() {
    this.stop(true);
    this.lock(false);
    window.removeEventListener('keydown', this.onKey);
  },

  methods: {
    show() {
      this.open = true;
      this.phase = 'idle';
      this.message = '';
      this.detected = '';
      this.problem = '';
      this.sheet = false;
      this.checking = false;
      this.lastScannedCode = null;
      this.manualBarcode = '';
      this.manualError = '';
      this.lock(true);
      window.addEventListener('keydown', this.onKey);
      lib(); // load the reader while the window opens
      this.$nextTick(() => this.start());
    },
    close() {
      this.open = false;
      this.sheet = false;
      this.stop(true);
      this.lock(false);
      window.removeEventListener('keydown', this.onKey);
    },
    onKey(e) {
      if (e.key !== 'Escape') return;
      if (this.sheet) this.sheet = false;
      else this.close();
    },
    lock(on) {
      if (typeof document !== 'undefined') document.body.style.overflow = on ? 'hidden' : '';
    },
    stepClass(n) {
      return this.step > n ? 'is-done' : this.step === n ? 'is-now' : '';
    },
    normalizeBarcode(value) {
      const raw = (value || '').trim();
      if (!raw) return '';
      const noGroupSeparator = raw.replace(/[\u001D]/g, '');
      const withoutPrefix = noGroupSeparator.replace(/^\][A-Za-z0-9]{2}/, '');
      return withoutPrefix.replace(/\s+/g, '');
    },
    openSheet() {
      this.manualError = '';
      this.sheet = true;
      this.$nextTick(() => this.$refs.manual && this.$refs.manual.focus());
    },
    // A code was read (camera, photo or typed): ask the server which item it is (items.scan handles GS1 prefixes, leading zeros, code/sku/name),
    // then fetch the full item record (the shape AutoComplete's json mode gives) so the caller's itemSelected() is reused unchanged.
    async resolve(raw) {
      const code = this.normalizeBarcode(raw);
      if (!code) return false;
      if (this.checking) return true;
      this.checking = true;
      this.sheet = false;
      this.detected = code;
      this.phase = 'checking';
      this.step = 2;
      if (navigator.vibrate) navigator.vibrate(40);
      const started = Date.now();
      await this.stop(true); // the camera is released straight away: the rest is the server's answer
      this.problem = '';
      try {
        let item = null;
        let handled = false;
        if (this.lookup) {
          const r = await this.lookup(code);
          handled = r === true;
          if (typeof r === 'string') this.problem = r;
        } else if (/\/q\/[A-Za-z0-9]{8,64}(?:[/?#]|$)/.test(code)) {
          // a WIMS record QR code (the ones printed on documents), not the code of an item
          this.problem = this.$t('This is a WIMS record QR code, not an item code. Open it with the QR scanner from the quick menu.');
        } else {
          const res = await axios.post(route('items.scan'), { code });
          if (res.data.found) {
            const itemRes = await axios.post(route('items.search'), { id: res.data.id });
            item = itemRes.data[0] || null;
          }
        }
        await new Promise(r => setTimeout(r, Math.max(0, 600 - (Date.now() - started))));
        if (item || handled) {
          this.step = 3;
          this.phase = 'found';
          if (navigator.vibrate) navigator.vibrate(60);
          if (item) this.$emit('resolved', item);
          setTimeout(() => this.close(), 420);
        } else {
          if (navigator.vibrate) navigator.vibrate([80, 60, 80]);
          this.phase = 'notfound';
          this.checking = false;
        }
      } catch (e) {
        this.phase = 'error';
        this.checking = false;
        this.message = this.$t('Could not validate scanned barcode. Please try again.');
      }
      return true;
    },
    again() {
      this.checking = false;
      this.message = '';
      this.detected = '';
      this.problem = '';
      this.lastScannedCode = null;
      this.phase = 'idle';
      this.start();
    },
    async start() {
      if (!this.open || this.busy || this.phase === 'live' || this.phase === 'starting') return;
      this.message = '';
      this.checking = false;
      if (!this.canUseLiveCamera) {
        this.phase = 'error';
        this.message = this.$t('Live camera scanner is unavailable on this connection. Use "Scan From Camera Photo" or open the app over HTTPS.');
        return;
      }
      this.busy = true;
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
      const { Html5QrcodeSupportedFormats: F } = await lib();
      await this.stop(true);
      this.scanner = new Html5Qrcode(this.scannerElementId, { verbose: false });
      const cam = this.cameras[this.camIndex];
      const box = document.getElementById(this.scannerElementId);
      const ratio = box && box.clientWidth ? Math.max(0.5, Math.min(2.2, box.clientHeight / box.clientWidth)) : 1.6;
      await this.scanner.start(
        cam ? cam.id : { facingMode: 'environment' },
        {
          fps: 15,
          aspectRatio: ratio,
          disableFlip: true,
          // a wide frame, as a barcode is: the same shape the frame on the screen has
          qrbox: w => {
            const width = Math.floor(w.width * 0.84);
            return { width, height: Math.floor(width * 0.5) };
          },
          formatsToSupport: [F.CODE_128, F.CODE_39, F.CODE_93, F.CODABAR, F.EAN_13, F.EAN_8, F.UPC_A, F.UPC_E, F.ITF, F.QR_CODE, F.DATA_MATRIX],
        },
        decodedText => {
          const code = (decodedText || '').trim();
          if (this.phase !== 'live' || !code || this.lastScannedCode === code) return;
          this.lastScannedCode = code;
          this.resolve(code);
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
      this.message = /Permission|NotAllowed|denied/i.test(name)
        ? this.$t('The camera is blocked. Allow camera access for this site, then try again.')
        : /NotFound|no camera|Requested device/i.test(name)
          ? this.$t('No camera was found on this device.')
          : this.$t('Unable to start camera scanner. Please allow camera access and try again.');
    },
    async stop(keepPhase) {
      const s = this.scanner;
      this.scanner = null;
      this.torch = false;
      this.torchOk = false;
      if (s) {
        try {
          await s.stop();
        } catch (e) {
          /* already stopped */
        }
        try {
          s.clear();
        } catch (e) {
          /* nothing to clear */
        }
      }
      if (keepPhase !== true && ['live', 'starting'].includes(this.phase)) this.phase = 'idle';
    },
    async flip() {
      if (this.cameras.length < 2) return;
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
    async submitManualBarcode() {
      this.manualError = '';
      const code = this.normalizeBarcode(this.manualBarcode);
      if (!code) {
        this.manualError = this.$t('Please enter a barcode value.');
        return;
      }
      await this.resolve(code);
    },
    // a photo of a barcode (taken now or from the gallery): the reader first, then ZXing as a second try
    async fromPhoto(event) {
      const file = event && event.target && event.target.files && event.target.files[0];
      if (event && event.target) event.target.value = '';
      if (!file || this.checking) return;
      this.message = '';
      this.phase = 'checking';
      this.step = 1;
      this.detected = '';
      try {
        await this.stop(true);
        let code = '';
        try {
          const { Html5Qrcode } = await lib();
          const reader = new Html5Qrcode(this.scannerElementId, { verbose: false });
          code = ((await reader.scanFile(file, false)) || '').trim();
          try {
            reader.clear();
          } catch (e) {
            /* nothing */
          }
        } catch (e) {
          /* try the second reader */
        }
        if (!code) {
          const { BrowserMultiFormatReader } = await import('@zxing/browser');
          const reader = new BrowserMultiFormatReader();
          const imageUrl = URL.createObjectURL(file);
          try {
            const result = await reader.decodeFromImageUrl(imageUrl);
            code = (result?.getText?.() || '').trim();
          } finally {
            URL.revokeObjectURL(imageUrl);
            reader.reset();
          }
        }
        if (!code) throw new Error('none');
        this.lastScannedCode = code;
        await this.resolve(code);
      } catch (e) {
        this.phase = 'error';
        this.message = this.$t('No barcode was found in that photo. Try again with the barcode sharper and filling more of the picture.');
      }
    },
  },
};
</script>

<style scoped>
.cbs-overlay { position: fixed; inset: 0; z-index: 10000; display: grid; place-items: center; padding: 1rem; background: rgba(5, 10, 22, 0.72); backdrop-filter: blur(4px); overflow-y: auto; }
.cbs-overlay { animation: cbs-in 0.2s ease; } /* only on opening: a closing window must never wait for a transition to end */
@keyframes cbs-in { from { opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .cbs-overlay { animation: none; } }

/* ---- the device ---- */
.cbs-device { position: relative; width: 372px; max-width: 100%; padding: 14px; border-radius: 52px; background: linear-gradient(150deg, #1f2937, #0b1220 55%, #111c30); box-shadow: 0 50px 80px -34px rgba(0, 0, 0, 0.9), 0 0 0 2px #334155, inset 0 0 0 2px #0b1220, inset 0 2px 3px rgba(255, 255, 255, 0.12); }
.cbs-notch { position: absolute; z-index: 8; top: 14px; left: 50%; transform: translateX(-50%); width: 38%; height: 26px; border-radius: 0 0 18px 18px; background: #0b1220; }
.cbs-notch b { position: absolute; top: 9px; right: 22%; width: 8px; height: 8px; border-radius: 9999px; background: radial-gradient(circle at 35% 35%, #475569, #020617); }
.cbs-screen { position: relative; height: min(700px, calc(100dvh - 60px)); overflow: hidden; border-radius: 40px; background: radial-gradient(circle at 50% 35%, #16294a, #070d19); isolation: isolate; }
/* a phone IS the device: no bezel, the whole screen */
@media (max-width: 575.98px) {
  .cbs-overlay { padding: 0; place-items: stretch; background: #070d19; }
  .cbs-device { width: 100%; padding: 0; border-radius: 0; background: none; box-shadow: none; }
  .cbs-notch { display: none; }
  .cbs-screen { height: 100dvh; border-radius: 0; }
}

.cbs-reader { position: absolute; inset: 0; z-index: 0; }
.cbs-reader :deep(video) { width: 100% !important; height: 100% !important; object-fit: cover; }
.cbs-reader :deep(#qr-shaded-region) { display: none !important; }
.cbs-reader :deep(img) { display: none; }
.cbs-shade { position: absolute; inset: 0; z-index: 1; pointer-events: none; background: linear-gradient(180deg, rgba(7, 13, 25, 0.72), rgba(7, 13, 25, 0.1) 26%, rgba(7, 13, 25, 0.1) 62%, rgba(7, 13, 25, 0.85)); }
.cbs-device.is-checking .cbs-reader { filter: blur(6px) brightness(0.6); transition: filter 0.3s ease; }

/* toolbar */
.cbs-top { position: absolute; z-index: 5; top: 46px; left: 50%; transform: translateX(-50%); display: flex; gap: 6px; padding: 5px; border-radius: 18px; background: rgba(15, 23, 42, 0.62); backdrop-filter: blur(10px); box-shadow: 0 10px 20px -10px rgba(0, 0, 0, 0.7), inset 0 0 0 1px rgba(186, 230, 253, 0.22); }
@media (max-width: 575.98px) { .cbs-top { top: 16px; } }
.cbs-ic { display: inline-grid; place-items: center; width: 52px; height: 40px; border-radius: 13px; color: #e0f2fe; cursor: pointer; transition: background-color 0.15s ease, transform 0.15s ease; }
.cbs-ic:hover:not(:disabled), .cbs-ic.is-on { background: rgba(56, 189, 248, 0.32); } .cbs-ic:active { transform: scale(0.94); } .cbs-ic:disabled { opacity: 0.35; cursor: default; }

/* viewfinder: a wide frame, as a barcode is */
.cbs-finder { position: absolute; z-index: 3; inset: 0; display: grid; align-content: center; justify-items: center; gap: 18px; pointer-events: none; }
.cbs-hint { margin: 0; padding: 0 28px; text-align: center; font-size: 0.86rem; font-weight: 700; color: #f0f9ff; text-shadow: 0 2px 8px rgba(0, 0, 0, 0.8); }
.cbs-sub { margin: 0; padding: 0 28px; text-align: center; font-size: 0.74rem; color: #bae6fd; text-shadow: 0 2px 8px rgba(0, 0, 0, 0.8); }
.cbs-frame { position: relative; width: 84%; aspect-ratio: 2 / 1; }
.cbs-frame .c { position: absolute; width: 11%; aspect-ratio: 1; border: 4px solid #fcd34d; filter: drop-shadow(0 0 8px rgba(252, 211, 77, 0.7)); }
.cbs-frame .tl { top: 0; left: 0; border-right: 0; border-bottom: 0; border-top-left-radius: 20px; } .cbs-frame .tr { top: 0; right: 0; border-left: 0; border-bottom: 0; border-top-right-radius: 20px; } .cbs-frame .bl { bottom: 0; left: 0; border-right: 0; border-top: 0; border-bottom-left-radius: 20px; } .cbs-frame .br { bottom: 0; right: 0; border-left: 0; border-top: 0; border-bottom-right-radius: 20px; }
.cbs-band { position: absolute; left: 3%; right: 3%; top: 0; height: 34%; border-radius: 12px; background: linear-gradient(180deg, rgba(252, 211, 77, 0), rgba(252, 211, 77, 0.38) 88%, #fcd34d 96%, rgba(252, 211, 77, 0.2)); animation: cbs-sweep 2s ease-in-out infinite alternate; }
@keyframes cbs-sweep { from { transform: translateY(-4%); } to { transform: translateY(190%); } }

/* idle cover */
.cbs-cover { position: absolute; z-index: 4; inset: 0; display: grid; align-content: center; justify-items: center; gap: 0.7rem; padding: 1.4rem 1.6rem 6rem; text-align: center; color: #e0f2fe; }
.cbs-cover h1 { margin: 0.4rem 0 0; font-size: 1.4rem; font-weight: 900; } .cbs-cover p { margin: 0; max-width: 280px; font-size: 0.86rem; line-height: 1.5; color: #bae6fd; }
.cbs-msg { padding: 0.5rem 0.8rem; border-radius: 12px; background: rgba(148, 163, 184, 0.16); } .cbs-msg.is-bad { color: #fecdd3 !important; background: rgba(225, 29, 72, 0.25); font-weight: 700; }
.cbs-orbit { position: relative; display: grid; place-items: center; width: 150px; height: 150px; }
.cbs-orbit::before, .cbs-orbit::after { content: ''; position: absolute; inset: 0; border-radius: 9999px; border: 2px solid rgba(125, 211, 252, 0.5); animation: cbs-ping 2.8s ease-out infinite; } .cbs-orbit::after { animation-delay: 1.4s; }
.cbs-orbit-core { display: grid; place-items: center; width: 92px; height: 92px; border-radius: 30px; color: #e0f2fe; background: linear-gradient(145deg, rgba(99, 102, 241, 0.6), rgba(14, 165, 233, 0.55)); box-shadow: 0 0 44px rgba(56, 189, 248, 0.5), inset 0 1px 1px rgba(255, 255, 255, 0.4); animation: cbs-float 3.4s ease-in-out infinite; }
@keyframes cbs-ping { 0% { transform: scale(0.7); opacity: 0.9; } 100% { transform: scale(1.4); opacity: 0; } }
@keyframes cbs-float { 0%, 100% { transform: translateY(0) rotate(0); } 50% { transform: translateY(-6px) rotate(3deg); } }

/* loading after a code is read */
.cbs-load { position: absolute; z-index: 6; inset: 0; display: grid; align-content: center; justify-items: center; gap: 1.1rem; padding-bottom: 5rem; background: radial-gradient(circle at 50% 40%, rgba(15, 40, 80, 0.55), rgba(5, 10, 22, 0.88)); animation: cbs-fadein 0.25s ease; }
@keyframes cbs-fadein { from { opacity: 0; } }
.cbs-code { margin: 0; padding: 0.3rem 0.8rem; max-width: 80%; border-radius: 9999px; font-size: 0.82rem; font-weight: 800; letter-spacing: 0.04em; color: #e0f2fe; background: rgba(148, 163, 184, 0.18); overflow-wrap: anywhere; text-align: center; } .cbs-code.is-bad { color: #fecdd3; background: rgba(0, 0, 0, 0.25); }
.cbs-spin { position: relative; display: grid; place-items: center; width: 130px; height: 130px; }
.cbs-spin svg { position: absolute; inset: 0; width: 100%; height: 100%; transform: rotate(-90deg); }
.cbs-spin circle { fill: none; stroke-width: 7; } .cbs-spin .bg { stroke: rgba(186, 230, 253, 0.16); }
.cbs-spin .fg { stroke: #67e8f9; stroke-linecap: round; stroke-dasharray: 314; stroke-dashoffset: 235; filter: drop-shadow(0 0 8px rgba(103, 232, 249, 0.8)); transform-origin: 60px 60px; animation: cbs-rot 1.05s linear infinite; }
@keyframes cbs-rot { to { transform: rotate(360deg); } }
.cbs-spin-ico { display: grid; place-items: center; width: 74px; height: 74px; border-radius: 9999px; color: #e0f2fe; background: linear-gradient(145deg, rgba(99, 102, 241, 0.55), rgba(14, 165, 233, 0.5)); animation: cbs-beat 1.1s ease-in-out infinite; }
@keyframes cbs-beat { 50% { transform: scale(1.08); } }
.cbs-spin.is-done .fg { animation: none; stroke: #34d399; stroke-dashoffset: 0; transition: stroke-dashoffset 0.4s ease; filter: drop-shadow(0 0 10px rgba(52, 211, 153, 0.9)); }
.cbs-spin.is-done .cbs-spin-ico { color: #fff; background: linear-gradient(145deg, #34d399, #059669); animation: cbs-bounce 0.5s cubic-bezier(0.34, 1.56, 0.64, 1); }
@keyframes cbs-bounce { from { transform: scale(0.3); } }
.cbs-steps { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.cbs-steps li { display: flex; align-items: center; gap: 10px; font-size: 0.86rem; font-weight: 700; color: rgba(186, 230, 253, 0.5); transition: color 0.25s ease; }
.cbs-steps i { display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 9999px; color: transparent; border: 2px solid rgba(186, 230, 253, 0.3); transition: all 0.25s ease; }
.cbs-steps li.is-now { color: #e0f2fe; } .cbs-steps li.is-now i { border-color: #67e8f9; border-top-color: transparent; animation: cbs-rot 0.8s linear infinite; }
.cbs-steps li.is-done { color: #a7f3d0; } .cbs-steps li.is-done i { color: #fff; background: #10b981; border-color: #10b981; }

/* nothing matched */
.cbs-denied { position: absolute; z-index: 6; inset: 0; display: grid; align-content: center; justify-items: center; gap: 0.5rem; padding: 1.4rem 1.6rem 6rem; text-align: center; color: #fff1f2; background: radial-gradient(circle at 50% 30%, rgba(159, 18, 57, 0.97), rgba(76, 5, 25, 0.99)); animation: cbs-fadein 0.25s ease; }
.cbs-denied b { font-size: 1.2rem; } .cbs-denied p { margin: 0; max-width: 290px; font-size: 0.86rem; line-height: 1.5; color: #fecdd3; }
.cbs-lock { display: grid; place-items: center; width: 90px; height: 90px; border-radius: 9999px; color: #fff; background: linear-gradient(145deg, #fb7185, #be123c); box-shadow: 0 0 0 10px rgba(251, 113, 133, 0.22), 0 0 44px rgba(251, 113, 133, 0.55); animation: cbs-shake 0.5s ease; }
@keyframes cbs-shake { 0%, 100% { transform: translateX(0); } 20% { transform: translateX(-9px); } 40% { transform: translateX(8px); } 60% { transform: translateX(-5px); } 80% { transform: translateX(3px); } }
.cbs-pill { display: inline-flex; align-items: center; justify-content: center; gap: 9px; margin-top: 0.7rem; padding: 0.8rem 1.5rem; border-radius: 9999px; font-size: 0.92rem; font-weight: 800; color: #0b1220; background: linear-gradient(145deg, #fef3c7, #fcd34d); box-shadow: 0 16px 28px -14px rgba(252, 211, 77, 0.9), inset 0 1px 1px #fff; } .cbs-pill:disabled { opacity: 0.5; } .cbs-pill.is-block { width: 100%; margin-top: 0.4rem; }

/* manual entry sheet */
.cbs-sheet { position: absolute; z-index: 9; inset: 0; display: grid; align-items: end; background: rgba(5, 10, 22, 0.55); }
.cbs-sheet-card { display: grid; gap: 0.7rem; padding: 1rem 1rem 1.2rem; border-radius: 28px 28px 0 0; background: #f8fafc; box-shadow: 0 -20px 40px -20px rgba(0, 0, 0, 0.6); }
.cbs-sheet-card header { display: flex; align-items: center; justify-content: space-between; } .cbs-sheet-card header b { color: #0f172a; } .cbs-sheet-card header button { display: grid; place-items: center; width: 32px; height: 32px; border-radius: 10px; color: #475569; background: #e2e8f0; }
.cbs-sheet-card input { height: 46px; padding: 0 14px; border-radius: 14px; border: 1px solid #cbd5e1; background: #fff; color: #0f172a; font-size: 0.95rem; box-shadow: 0 10px 16px -12px rgba(15, 37, 64, 0.55); }
.cbs-sheet-card input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2); }
.cbs-bad { margin: 0; font-size: 0.78rem; font-weight: 700; color: #be123c; }
@keyframes cbs-sheet-up { from { transform: translateY(100%); } }
.cbs-sheet-card { animation: cbs-sheet-up 0.25s cubic-bezier(0.2, 0.8, 0.2, 1); }
@media (prefers-reduced-motion: reduce) { .cbs-sheet-card { animation: none; } }

/* dock */
.cbs-dock { position: absolute; z-index: 7; left: 14px; right: 14px; bottom: 14px; display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 28px; background: rgba(15, 23, 42, 0.7); backdrop-filter: blur(12px); box-shadow: 0 16px 30px -14px rgba(0, 0, 0, 0.8), inset 0 0 0 1px rgba(186, 230, 253, 0.2); }
@media (max-width: 575.98px) { .cbs-dock { bottom: max(14px, env(safe-area-inset-bottom)); } }
.cbs-dk { display: grid; justify-items: center; gap: 2px; padding: 6px 4px; border-radius: 14px; font-size: 0.68rem; font-weight: 800; color: #bae6fd; transition: color 0.15s ease, background-color 0.15s ease; } .cbs-dk:hover { color: #fff; background: rgba(148, 163, 184, 0.16); }
.cbs-main { display: grid; place-items: center; width: 66px; height: 66px; margin-top: -26px; border-radius: 9999px; color: #422006; background: linear-gradient(145deg, #fde68a, #f59e0b); box-shadow: 0 0 0 6px rgba(15, 23, 42, 0.85), 0 16px 26px -10px rgba(245, 158, 11, 0.9), inset 0 1px 2px rgba(255, 255, 255, 0.7); transition: transform 0.15s ease; }
.cbs-main:hover:not(:disabled) { transform: translateY(-2px) scale(1.04); } .cbs-main:active { transform: scale(0.94); } .cbs-main:disabled { opacity: 0.7; } .cbs-main.is-live { color: #fff; background: linear-gradient(145deg, #fb7185, #be123c); box-shadow: 0 0 0 6px rgba(15, 23, 42, 0.85), 0 16px 26px -10px rgba(225, 29, 72, 0.9); }
@media (prefers-reduced-motion: reduce) { .cbs-band, .cbs-orbit::before, .cbs-orbit::after, .cbs-orbit-core, .cbs-spin .fg, .cbs-spin-ico, .cbs-lock, .cbs-steps li.is-now i { animation: none !important; } }
</style>

<template>
  <div class="sg-cap">
    <!-- 1. choose how -->
    <div v-if="step === 'choose'" class="sg-methods">
      <label v-if="settings.upload" class="sg-method">
        <span class="sg-method__ico g-navy"><icons name="upload" class="w-6 h-6" /></span>
        <b>{{ $t('Upload a picture') }}</b><small>{{ $t('A scan or a photo of the signature on white paper') }} ({{ settings.formats.join(', ').toUpperCase() }}, {{ $t('up to') }} {{ settings.max_mb }} MB)</small>
        <input type="file" class="sg-file" :accept="accept" @change="onFile($event, 'upload')" />
      </label>
      <button v-if="settings.camera" type="button" class="sg-method" @click="openCamera"><span class="sg-method__ico g-teal"><icons name="camera" class="w-6 h-6" /></span><b>{{ $t('Use the camera') }}</b><small>{{ $t('Photograph the signature on plain white paper in good light') }}</small></button>
      <button v-if="settings.draw" type="button" class="sg-method" @click="openDraw"><span class="sg-method__ico g-violet"><svg viewBox='0 0 32 32' class='w-7 h-7' aria-hidden='true'><g transform='translate(3 29) rotate(-45)' fill='none' stroke='currentColor' stroke-width='2' stroke-linejoin='round' stroke-linecap='round'><path d='M0 0L8.5-3.4 11-3.4 11 3.4 8.5 3.4Z' fill='currentColor' fill-opacity='.35'/><path d='M0 0H7'/><rect x='11' y='-3.4' width='4' height='6.8' fill='currentColor' fill-opacity='.35'/><rect x='15' y='-4' width='17' height='8' rx='2.2'/><path d='M22 0h7'/></g></svg></span><b>{{ $t('Draw it') }}</b><small>{{ $t('Sign with a finger, a stylus or the mouse') }}</small></button>
      <p v-if="!settings.upload && !settings.camera && !settings.draw" class="sb-none">{{ $t('No way of adding a signature is switched on.') }}</p>
    </div>

    <!-- camera -->
    <div v-if="step === 'camera'" class="sg-stage">
      <p class="sg-tip"><icons name="info" class="w-4 h-4 flex-none" />{{ $t('Write the signature in dark ink on plain white paper, keep the whole signature inside the frame with a clear border, and avoid shadows.') }}</p>
      <div class="sg-video"><video ref="video" autoplay playsinline muted></video><i class="sg-frame"></i></div>
      <p v-if="camError" class="sg-error">{{ camError }}</p>
      <div class="sg-actions">
        <button type="button" class="sb-btn is-primary" :disabled="!camReady" @click="snap"><icons name="camera" class="w-4 h-4" />{{ $t('Take picture') }}</button>
        <button type="button" class="sb-btn" @click="closeCamera(true)">{{ $t('Cancel') }}</button>
      </div>
    </div>

    <!-- draw -->
    <div v-if="step === 'draw'" class="sg-stage">
      <p class="sg-tip"><icons name="info" class="w-4 h-4 flex-none" />{{ $t('Sign inside the box. Use Clear to start again. Only what you draw is kept.') }}</p>
      <div ref="padwrap" class="sg-padwrap">
        <canvas ref="pad" class="sg-pad" width="900" :height="padH" :style="{ aspectRatio: 900 + ' / ' + padH }" @pointerdown="down" @pointermove="move" @pointerup="up" @pointerleave="leave" @pointercancel="up" @pointerenter="trackPen"></canvas>
        <!-- the pen the person sees: its tip sits exactly on the point where the ink is laid (same pointer position, same box) -->
        <span v-show="penOn" class="sg-pen" :style="{ left: penX + 'px', top: penY + 'px' }" aria-hidden="true"><svg viewBox="0 0 32 32" width="36.4" height="36.4" xmlns="http://www.w3.org/2000/svg"><g transform="translate(2.2 29.8) rotate(-45)"><path d="M0 0L8.5-3.4 11-3.4 11 3.4 8.5 3.4Z" fill="#e2e8f0" stroke="#0f172a" stroke-width="1.2" stroke-linejoin="round"/><path d="M0 0L8.5 0" stroke="#0f172a" stroke-width="1"/><circle cx="7.6" cy="0" r="1" fill="#0f172a"/><rect x="11" y="-3.4" width="4" height="6.8" fill="#0f172a"/><rect x="15" y="-4" width="19" height="8" rx="2.2" fill="#1b3fa0" stroke="#ffffff" stroke-width="1.2"/><rect x="15" y="-4" width="19" height="3" rx="1.5" fill="#3b82c4" opacity=".7"/><rect x="23" y="-1" width="9" height="1.6" rx=".8" fill="#facc15"/></g></svg></span>
      </div>
      <div class="sg-actions">
        <button type="button" class="sb-btn is-primary" :disabled="!drawn" @click="useDrawing"><icons name="tick" class="w-4 h-4" />{{ $t('Use this drawing') }}</button>
        <button type="button" class="sb-btn" @click="clearPad"><icons name="refresh" class="w-4 h-4" />{{ $t('Clear') }}</button>
        <button type="button" class="sb-btn" @click="step = 'choose'">{{ $t('Cancel') }}</button>
      </div>
    </div>

    <!-- working -->
    <div v-if="step === 'working'" class="sb-state"><span class="sb-spin"></span>{{ $t('Removing the background and standardising the ink...') }}</div>

    <!-- review -->
    <div v-if="step === 'review'" class="sg-review">
      <div class="sg-compare">
        <figure><figcaption>{{ $t('Your picture') }}</figcaption><div class="sg-orig"><img v-if="sourceUrl" :src="sourceUrl" alt="" /></div></figure>
        <figure>
          <figcaption>{{ $t('The signature WIMS will keep') }}</figcaption>
          <div class="sg-result"><img v-if="result.preview" :src="result.preview" alt="" /><p v-else class="sg-fail"><icons name="exclamation" class="w-5 h-5" />{{ $t('The picture could not be used.') }}</p></div>
          <small v-if="result.ok">{{ $t('Transparent background, blue ink, trimmed to the signature.') }}</small>
        </figure>
      </div>
      <ul v-if="result.reasons && result.reasons.length" class="sg-reasons"><li v-for="r in result.reasons" :key="r">{{ r }}</li></ul>
      <div class="sg-adjust" v-if="sourceKind !== 'draw'">
        <label for="sg-sens">{{ $t('Adjust') }}: <b>{{ sensLabel }}</b></label>
        <input id="sg-sens" v-model.number="sens" type="range" min="0.6" max="1.6" step="0.1" />
        <button type="button" class="sb-btn" :disabled="busy" @click="reprocess"><icons name="refresh" class="w-4 h-4" />{{ $t('Process again') }}</button>
        <small>{{ $t('Move left to keep fainter strokes, right to remove more of the background.') }}</small>
      </div>
      <label v-if="result.ok && settings.consent && (isSelf || !settings.waive)" class="sg-consent">
        <input v-model="consent" type="checkbox" :disabled="!isSelf" />
        <span v-if="isSelf">{{ $t('I confirm this is my own signature and I allow WIMS to use it on documents where I am the signatory.') }}</span>
        <span v-else>{{ $t('The signatory has to confirm that this signature is theirs before it can be verified. You are registering it for them.') }}</span>
      </label>
      <p v-else-if="result.ok && settings.consent && settings.waive" class="sg-consent is-notice"><icons name="info" class="w-4 h-4 flex-none" /><span>{{ $t('You are registering this signature for another person and are allowed to do so without their confirmation. They will be told in the app bell. It is not used on any report until it is verified.') }}</span></p>
      <p v-if="error" class="sg-error">{{ error }}</p>
      <div class="sg-actions">
        <button v-if="result.ok" type="button" class="sb-btn is-primary" :disabled="busy || (settings.consent && isSelf && !consent)" @click="confirm"><icons name="tick" class="w-4 h-4" />{{ isSelf ? $t('Use this signature') : $t('Register this signature') }}</button>
        <button type="button" class="sb-btn" @click="reset"><icons name="camera" class="w-4 h-4" />{{ $t('Retake or choose another') }}</button>
      </div>
      <p v-if="result.ok" class="sg-note">{{ settings.verification ? $t('After you confirm it, the signature waits for a verifier. It is not used on any report until it is verified and active.') : $t('After you confirm it, the signature becomes active.') }}</p>
    </div>
  </div>
</template>

<script>
export default {
  props: { subjectId: Number, isSelf: Boolean, settings: Object },
  emits: ['done'],
  data() {
    return { step: 'choose', sourceKind: 'upload', sourceUrl: '', token: '', result: {}, sens: 1.0, consent: false, busy: false, error: '', camError: '', camReady: false, stream: null, drawing: false, drawn: false, padH: 300, last: null, mid: null, penOn: false, penX: 0, penY: 0, lastBlob: null };
  },
  computed: {
    accept() { return this.settings.formats.map(f => '.' + f).join(','); },
    sensLabel() { return this.sens < 0.9 ? this.$t('keeps fainter strokes') : this.sens > 1.1 ? this.$t('removes more background') : this.$t('standard'); },
  },
  beforeUnmount() { this.closeCamera(false); if (this.sourceUrl) URL.revokeObjectURL(this.sourceUrl); },
  methods: {
    reset() { this.closeCamera(false); this.step = 'choose'; this.token = ''; this.result = {}; this.error = ''; this.consent = false; this.sens = 1.0; if (this.sourceUrl) { URL.revokeObjectURL(this.sourceUrl); this.sourceUrl = ''; } },
    onFile(e, kind) {
      const f = e.target.files && e.target.files[0];
      e.target.value = '';
      if (!f) return;
      const ext = (f.name.split('.').pop() || '').toLowerCase();
      if (!this.settings.formats.includes(ext)) { this.error = this.$t('This file type is not allowed.'); this.step = 'review'; this.result = { ok: false, reasons: [this.$t('This file type is not allowed.')] }; return; }
      this.send(f, kind);
    },
    // Big phone photos are reduced in the browser first: a smaller upload and far less memory used on the server.
    async shrink(blob) {
      if (!blob.type || !/^image\/(jpeg|png|webp)$/.test(blob.type)) return blob;
      try {
        const bmp = await createImageBitmap(blob);
        const max = 2400; const side = Math.max(bmp.width, bmp.height);
        if (side <= max) { bmp.close && bmp.close(); return blob; }
        const k = max / side; const c = document.createElement('canvas'); c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k);
        c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height); bmp.close && bmp.close();
        const type = blob.type === 'image/png' ? 'image/png' : 'image/jpeg';
        return await new Promise(res => c.toBlob(b => res(b ? new File([b], 'signature.' + (type === 'image/png' ? 'png' : 'jpg'), { type }) : blob), type, 0.92));
      } catch (e) { return blob; }
    },
    async send(blob, kind, name = 'signature') {
      this.sourceKind = kind; this.error = ''; this.step = 'working';
      blob = await this.shrink(blob);
      if (this.sourceUrl) URL.revokeObjectURL(this.sourceUrl);
      this.sourceUrl = URL.createObjectURL(blob);
      this.lastBlob = blob;
      const fd = new FormData();
      fd.append('image', blob, blob.name || name + (blob.type === 'image/png' ? '.png' : '.jpg'));
      fd.append('method', kind); fd.append('user_id', this.subjectId); fd.append('sensitivity', this.sens);
      return axios.post(route('signatories.register.preview'), fd).then(r => { this.result = r.data; this.token = r.data.token; this.step = 'review'; })
        .catch(e => { const d = (e.response && e.response.data) || {}; this.result = { ok: false, reasons: d.reasons || [d.message || this.$t('The picture could not be processed.')] }; this.step = 'review'; });
    },
    reprocess() {
      if (!this.token) return;
      this.busy = true;
      const fd = new FormData();
      fd.append('method', this.sourceKind); fd.append('user_id', this.subjectId); fd.append('sensitivity', this.sens); fd.append('token', this.token);
      axios.post(route('signatories.register.preview'), fd).then(r => { this.result = r.data; }).catch(e => { this.error = (e.response && e.response.data && e.response.data.message) || this.$t('The picture could not be processed.'); }).finally(() => { this.busy = false; });
    },
    confirm() {
      this.busy = true; this.error = '';
      axios.post(route('signatories.register.confirm'), { user_id: this.subjectId, token: this.token, sensitivity: this.sens, consent: this.consent }).then(r => { this.$emit('done', r.data); if (r.data.redirect) this.$inertia.visit(r.data.redirect); })
        .catch(e => { this.error = (e.response && e.response.data && e.response.data.message) || this.$t('It could not be saved.'); }).finally(() => { this.busy = false; });
    },
    // ---- camera
    async openCamera() {
      this.step = 'camera'; this.camError = ''; this.camReady = false;
      try {
        this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false });
        await this.$nextTick();
        const v = this.$refs.video; v.srcObject = this.stream; v.onloadedmetadata = () => { this.camReady = true; };
      } catch (e) { this.camError = this.$t('The camera could not be opened. Allow camera access in the browser, or upload a picture instead.'); }
    },
    snap() {
      const v = this.$refs.video; const c = document.createElement('canvas'); c.width = v.videoWidth; c.height = v.videoHeight; c.getContext('2d').drawImage(v, 0, 0);
      this.closeCamera(false);
      c.toBlob(b => { if (b) this.send(new File([b], 'camera.jpg', { type: 'image/jpeg' }), 'camera'); }, 'image/jpeg', 0.92);
    },
    closeCamera(back) { if (this.stream) { this.stream.getTracks().forEach(t => t.stop()); this.stream = null; } this.camReady = false; if (back) this.step = 'choose'; },
    // ---- drawing
    openDraw() { this.padH = window.innerWidth < 640 ? 520 : 300; this.step = 'draw'; this.drawn = false; this.$nextTick(this.clearPad); },
    ctx() { return this.$refs.pad.getContext('2d'); },
    // The point under the pen: the pointer position inside the pad, scaled to the drawing's own pixels (so it lands exactly at the nib at any size).
    pos(e) { const c = this.$refs.pad; const r = c.getBoundingClientRect(); return [(e.clientX - r.left) * (c.width / r.width), (e.clientY - r.top) * (c.height / r.height)]; },
    // the ink is about 3 screen pixels thick whatever size the pad is shown at (a stylus presses harder for a heavier line)
    inkWidth(e) { const c = this.$refs.pad; const k = c.width / c.getBoundingClientRect().width; const pen = e.pointerType === 'pen' && e.pressure > 0; return 3.2 * k * (pen ? 0.55 + e.pressure * 0.9 : 1); },
    clearPad() { const c = this.$refs.pad; if (!c) return; this.ctx().clearRect(0, 0, c.width, c.height); this.drawn = false; },
    down(e) {
      e.preventDefault();
      try { this.$refs.pad.setPointerCapture(e.pointerId); } catch (x) { /* a pointer the browser cannot capture still draws */ }
      this.drawing = true;
      const p = this.pos(e); const g = this.ctx();
      g.lineCap = 'round'; g.lineJoin = 'round'; g.strokeStyle = '#000'; g.fillStyle = '#000';
      g.beginPath(); g.arc(p[0], p[1], this.inkWidth(e) / 2, 0, Math.PI * 2); g.fill(); // a tap leaves a dot
      this.last = p; this.mid = p; this.drawn = true;
    },
    // the pen follows the pointer inside the pad's own box (touch has no pen to show)
    trackPen(e) {
      if (e.pointerType === 'touch') { this.penOn = false; return; }
      const r = this.$refs.padwrap.getBoundingClientRect();
      this.penX = e.clientX - r.left; this.penY = e.clientY - r.top; this.penOn = true;
    },
    leave(e) { this.penOn = false; this.up(e); },
    move(e) {
      this.trackPen(e);
      if (!this.drawing) return;
      const g = this.ctx();
      // every position the pointer passed through, not only the last one: fast strokes stay smooth
      const events = e.getCoalescedEvents ? e.getCoalescedEvents() : [];
      for (const ev of (events.length ? events : [e])) {
        const p = this.pos(ev); const mid = [(this.last[0] + p[0]) / 2, (this.last[1] + p[1]) / 2];
        g.lineWidth = this.inkWidth(ev);
        g.beginPath(); g.moveTo(this.mid[0], this.mid[1]); g.quadraticCurveTo(this.last[0], this.last[1], mid[0], mid[1]); g.stroke();
        this.last = p; this.mid = mid;
      }
    },
    up() { if (this.drawing && this.last) { const g = this.ctx(); g.beginPath(); g.moveTo(this.mid[0], this.mid[1]); g.lineTo(this.last[0], this.last[1]); g.stroke(); } this.drawing = false; },
    useDrawing() { this.$refs.pad.toBlob(b => { if (b) this.send(new File([b], 'drawing.png', { type: 'image/png' }), 'draw'); }, 'image/png'); },
  },
};
</script>

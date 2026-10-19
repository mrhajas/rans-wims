<template>
  <section v-if="info && info.enabled" ref="card" class="lg-card">
    <!-- the one password box for everything on this card: it asks again whenever the server says the confirmation has run out -->
    <tec-confirms-password ref="pw" :seconds="PW_SECONDS" :content="$t('For your security, please confirm your password to continue.')" @confirmed="pwDone(true)" @cancelled="pwDone(false)">
      <template #below><face-load-bar v-if="showLoad" :pct="faceLoad.pct" :label="faceLoad.state === 'ready' ? $t('Face scanner ready') : $t('Preparing the face scanner')" /></template>
    </tec-confirms-password>
    <header class="lg-card-head">
      <span class="lg-card-ico lg-ico-sky"><icons name="camera" class="w-5 h-5" /></span>
      <div class="min-w-0">
        <h2 class="lg-card-title">{{ $t('Face Login') }}</h2>
        <p class="lg-card-sub">{{ $t('Enrol your face once, then sign in by typing your username and looking at the camera instead of typing a password.') }}</p>
      </div>
      <span class="pf-head-chip lg-chip" :class="info.enrolled ? 'lg-chip-green' : 'lg-chip-slate'">{{ info.enrolled ? $t('On') : $t('Off') }}</span>
    </header>

    <!-- scanning -->
    <div v-if="mode" class="faceform__scan">
      <div class="faceform__head">
        <span class="faceform__badge" aria-hidden="true"><icons name="camera" class="w-6 h-6" /></span>
        <div>
          <h3>{{ mode === 'enrol' ? $t('Setting up your Face ID') : mode === 'device' ? $t('Adding this device') : $t('Checking your face') }}</h3>
          <p>{{ mode === 'enrol' ? $t('Follow the prompts: look at the camera, smile, then blink. It takes about 15 seconds.') : $t('Look at the camera and blink when the ring flashes.') }}</p>
        </div>
      </div>
      <face-scanner :key="scanKey" :plan="plan" :enrol="mode === 'enrol'" :stage="stage" :stage-text="stageText" @done="onDone" @error="() => {}" />
      <p v-if="error" class="faceform__err" role="alert"><icons name="exclamation" class="w-4 h-4 flex-none" /><span>{{ error }}</span></p>
      <div class="mt-3 flex justify-center"><button type="button" class="faceform__cancel" @click="cancel"><icons name="cross" class="w-4 h-4" />{{ mode === 'check' ? $t('Cancel') : $t('Cancel setup') }}</button></div>
    </div>

    <template v-else>
      <transition name="fade"><face-load-bar v-if="showLoad" class="faceform__load" :pct="faceLoad.pct" :label="faceLoad.state === 'ready' ? $t('Face scanner ready') : $t('Preparing the face scanner')" /></transition>
      <div v-if="result" class="faceform__result" :class="result.match ? 'is-ok' : 'is-bad'">
        <icons :name="result.match ? 'tick' : 'exclamation'" class="w-5 h-5" />
        <div><b>{{ result.match ? $t('That is you. Face login will work.') : $t('That did not match your enrolled face.') }}</b>
          <span>{{ $t('Match strength') }}: {{ result.score }}%. {{ result.match ? '' : $t('Try again in better light, or enrol again.') }}</span></div>
      </div>
      <p v-if="done" class="faceform__done"><icons name="tick" class="w-4 h-4" />{{ done }}</p>
      <p v-if="error && !mode" class="faceform__err" role="alert"><icons name="exclamation" class="w-4 h-4" />{{ error }}</p>

      <div v-if="info.enrolled" class="pf-box mt-2">
        <p class="pf-lead">
          {{ $t('Your face was enrolled on') }} <b>{{ $date(info.enrolled_at) }}</b>.
          <template v-if="info.last_used_at"> {{ $t('Last used to sign in') }}: <b>{{ $date(info.last_used_at) + " " + $time(info.last_used_at) }}</b>.</template>
          <template v-else> {{ $t('Not used to sign in yet.') }}</template>
        </p>
        <div class="faceform__devices">
          <h4>{{ $t('Devices that can use Face ID') }}</h4>
          <p class="faceform__devnote">{{ $t('A face sign-in only works on a device you set up here. A photo of you, or a copy of your face data, is useless anywhere else.') }}</p>
          <ul>
            <li v-for="d in info.devices" :key="d.id">
              <icons name="camera" class="w-4 h-4" />
              <span><b>{{ d.label || $t('Device') }}</b><em v-if="isThis(d)" class="faceform__this">{{ $t('This device') }}</em>
                <small>{{ d.last_used_at ? $t('Last used') + ' ' + $date(d.last_used_at) + ' ' + $time(d.last_used_at) : $t('Not used yet') }}</small></span>
              <button type="button" class="faceform__x lg-tip" :data-tip="$t('Remove this device')" :aria-label="$t('Remove this device')" :disabled="busy" @click="removeDevice(d)"><icons name="trash" class="w-4 h-4" /></button>
            </li>
            <li v-if="!info.devices.length" class="faceform__none">{{ $t('No device yet. Set one up below.') }}</li>
          </ul>
          <button v-if="!hasThisDevice" type="button" @pointerenter="warm" @focus="warm" @touchstart.passive="warm" @click="askFace(() => begin('device'))" class="lg-btn lg-btn-primary"><icons name="camera" class="w-4 h-4" />{{ $t('Use Face ID on this device') }}</button>
        </div>
        <p v-if="info.locked" class="pf-err">{{ $t('Face login is paused for a few minutes after too many failed scans. Your password still works.') }}</p>
        <div class="pf-foot" style="justify-content: flex-start">
          <button type="button" @pointerenter="warm" @focus="warm" @touchstart.passive="warm" @click="askFace(() => begin('check'))" class="lg-btn lg-btn-primary"><icons name="camera" class="w-4 h-4" />{{ $t('Test my face') }}</button>
          <button type="button" @pointerenter="warm" @focus="warm" @touchstart.passive="warm" @click="askFace(() => begin('enrol'))" class="lg-btn lg-btn-soft"><icons name="refresh" class="w-4 h-4" />{{ $t('Enrol again') }}</button>
          <button type="button" @click="ask(() => remove())" class="lg-btn lg-btn-soft" :disabled="busy"><icons name="trash" class="w-4 h-4" />{{ $t('Remove my face') }}</button>
        </div>
      </div>

      <div v-else class="pf-box mt-2">
        <ul class="faceform__points">
          <li><icons name="tick" class="w-4 h-4" />{{ $t('Your browser turns your face into a string of numbers. No photo or video is ever sent or saved.') }}</li>
          <li><icons name="tick" class="w-4 h-4" />{{ $t('To sign in you only blink when the ring flashes. A photo, a video or a look-alike will not pass, and it only works on devices you set up.') }}</li>
          <li><icons name="tick" class="w-4 h-4" />{{ $t('If you use two-step codes, you still enter them after your face. Your password always keeps working.') }}</li>
          <li><icons name="tick" class="w-4 h-4" />{{ $t('You can remove your face at any time here.') }}</li>
        </ul>
        <label class="faceform__consent" :class="{ 'faceform__nudge': nudge }"><input v-model="consent" type="checkbox" @change="nudge = false" /><span>{{ $t('I agree that WIMS stores a mathematical template of my face to sign me in. I understand it is sensitive personal data.') }}</span></label>
        <div class="pf-foot" style="justify-content: flex-start">
          <button v-if="!consent" type="button" @pointerenter="warm" @focus="warm" class="lg-btn lg-btn-primary" :class="{ 'faceform__nudge': nudge }" @click="needConsent"><icons name="camera" class="w-4 h-4" />{{ $t('Set up face login') }}</button>
          <button v-else type="button" @pointerenter="warm" @focus="warm" @touchstart.passive="warm" @click="askFace(() => begin('enrol'))" class="lg-btn lg-btn-primary"><icons name="camera" class="w-4 h-4" />{{ $t('Set up face login') }}</button>
        </div>
        <p v-if="nudge" class="pf-err">{{ $t('Please tick the agreement above first, then press Set up face login.') }}</p>
      </div>
    </template>
  </section>
</template>

<script>
import { router } from '@inertiajs/vue3';
import FaceScanner from '@/Shared/Face/FaceScanner.vue';
import FaceLoadBar from '@/Shared/Face/FaceLoadBar.vue';
import { faceLoad, startFaceEngine } from '@/Shared/Face/faceLoad';
import TecConfirmsPassword from '@/Jetstream/ConfirmsPassword.vue';

// enrolling needs no head turning: look straight, smile, blink, then look straight twice
// how recent a password confirmation must be for anything on this card. The routes use the same number (routes/web.php, face group).
const PW_SECONDS = 300;
const ENROL = [{ k: 'front', capture: 'front' }, { k: 'smile', capture: 'front' }, { k: 'blink', capture: 'front' }, { k: 'front', capture: 'front' }, { k: 'front', capture: 'front' }];
// the check and "add this device" scans use the same blink-on-cue as the sign-in (the waits are picked here; the sign-in's are the server's)
const cuePlan = () => [{ k: 'front', capture: 'front' }, ...[0, 1].map(() => ({ k: 'cue', delay: 1100 + Math.floor(Math.random() * 1200), capture: 'front' }))];

export default {
  components: { FaceScanner, FaceLoadBar, TecConfirmsPassword },
  data() { return { PW_SECONDS, faceLoad, showLoad: false, pwResolve: null, cue: [], thisId: '', mode: '', consent: false, error: '', result: null, done: '', busy: false, scanKey: 0, nudge: false, stage: '', stageText: '' }; },
  computed: {
    info() { return this.$page.props.faceLogin; },
    plan() { return this.mode === 'enrol' ? ENROL : this.cue; },
    hasThisDevice() { return !!this.thisId; },
  },
  watch: {
    'faceLoad.state'(v) {
      if (v === 'ready' && !this.mode) setTimeout(() => { if (!this.mode) this.showLoad = false; }, 1800);
    },
    'info.devices': { immediate: true, handler() { this.findThisDevice(); } },
  },
  methods: {
    // The face scanner (MediaPipe, TensorFlow and the models, several MB) is heavy to start. It used to load as soon as the page opened, which kept the
    // browser busy for a few seconds so nothing on the page reacted to clicks. Now it only starts when the person shows interest in it (pointer over, or
    // focus / touch on, one of the face buttons), and in any case when a scan begins.
    warm() {
      if (this.warmed) return;
      this.warmed = true;
      const c = navigator.connection;
      if (c && (c.saveData || /(^|-)2g$/.test(c.effectiveType || ''))) return;
      setTimeout(() => startFaceEngine(), 120);
    },
    // a face button: the engine starts loading at once and a progress bar shows, so by the time the password is confirmed it is ready
    askFace(fn) {
      this.showLoad = true;
      startFaceEngine();
      this.ask(fn);
    },
    // a password box, then the action. Resolves true once confirmed, false when the person closes the box.
    askPassword() {
      return new Promise(resolve => { this.pwResolve = resolve; this.$refs.pw.startConfirmingPassword(); });
    },
    pwDone(ok) { const r = this.pwResolve; this.pwResolve = null; if (r) r(ok); },
    async ask(fn) { if (await this.askPassword()) fn(); },
    // runs a request; if the server says the password confirmation has run out (423), asks for the password and runs it once more
    async guarded(run) {
      try { return await run(); } catch (e) {
        if (e?.response?.status !== 423) throw e;
        if (!(await this.askPassword())) throw e;
        return run();
      }
    },
    isThis(d) { return d.device_id === this.thisId; },
    // which of the registered devices is this browser? (the one it holds a key for)
    async findThisDevice() {
      try {
        const dev = await import('@/Shared/Face/faceDevice');
        if (!dev.supported()) { this.thisId = ''; return; }
        const ids = await dev.knownIds();
        const mine = (this.info.devices || []).find(d => ids.includes(d.device_id));
        this.thisId = mine && await dev.hasKey(mine.device_id) ? mine.device_id : '';
      } catch (e) { this.thisId = ''; }
    },
    async removeDevice(d) {
      if (!(await this.$confirm(this.$t('Remove Face ID from this device? Face login will not work on it until you set it up again.')))) return;
      this.busy = true;
      try {
        await this.guarded(() => axios.delete(route('profile.face.devices.destroy', d.id)));
        try { const dev = await import('@/Shared/Face/faceDevice'); if (d.device_id === this.thisId) await dev.forget(d.device_id); } catch (e) { /* nothing to forget */ }
        router.reload({ only: ['faceLogin'], preserveScroll: true });
      } catch (e) {
        this.error = this.msg(e, this.$t('The device could not be removed.'));
      } finally { this.busy = false; }
    },
    begin(mode) {
      this.cue = cuePlan();
      this.stage = ''; this.mode = mode; this.error = ''; this.result = null; this.done = ''; this.scanKey++;
      // bring the whole Face Login section on screen so the person sees everything (again once the scanner has opened and the section has grown)
      this.$nextTick(() => { this.showCard(); setTimeout(this.showCard, 450); setTimeout(this.showCard, 1100); });
    },
    showCard() {
      const el = this.$refs.card;
      if (!el || !this.mode) return;
      const r = el.getBoundingClientRect();
      const fits = r.height <= window.innerHeight - 24;
      // centred when it fits in the window, otherwise its top edge, so the title and the camera are in view
      el.scrollIntoView({ behavior: 'smooth', block: fits ? 'center' : 'start' });
    },
    needConsent() { this.nudge = true; setTimeout(() => (this.nudge = false), 6000); },
    cancel() { this.mode = ''; this.error = ''; this.stage = ''; },
    msg(e, fallback) {
      const errors = e?.response?.data?.errors;
      if (errors) return Object.values(errors).flat()[0] || fallback;
      return e?.response?.data?.message || fallback;
    },
    async onDone({ frames }) {
      const started = Date.now();
      const pause = ms => new Promise(r => setTimeout(r, ms));
      const enrol = this.mode === 'enrol';
      const addDevice = this.mode === 'device';
      this.stage = 'verifying'; this.stageText = enrol ? this.$t('Saving your face...') : this.$t('Comparing with your face...');
      try {
        let data;
        const dev = (enrol || addDevice) ? await import('@/Shared/Face/faceDevice') : null;
        if (enrol || addDevice) {
          if (!dev.supported()) throw { response: { data: { message: this.$t('Face ID needs a secure (https) page and a current browser.') } } };
          // this browser either already holds a registered key, or makes one now (its private half never leaves the browser)
          const body = enrol ? { samples: frames, consent: true } : { frames };
          if (enrol && this.thisId) body.device_id = this.thisId; else body.device_public_key = await dev.pendingPublicKey();
          ({ data } = await this.guarded(() => axios.post(route(enrol ? 'profile.face.enrol' : 'profile.face.devices.store'), body)));
          if (data.device_id && !(this.thisId && enrol)) await dev.bind(data.device_id);
        } else {
          ({ data } = await this.guarded(() => axios.post(route('profile.face.check'), { frames })));
        }
        await pause(Math.max(0, 900 - (Date.now() - started)));
        const good = enrol || addDevice || data.match;
        this.stage = good ? 'ok' : 'bad'; this.stageText = enrol ? this.$t('Face enrolled') : addDevice ? this.$t('Device added') : good ? this.$t('That is you') : this.$t('No match');
        await pause(850);
        this.stage = ''; this.mode = '';
        if (enrol || addDevice) {
          try { localStorage.setItem('wims_face_device', '1'); } catch (e) { /* no storage */ }
          this.done = enrol ? this.$t('Your face is enrolled on this device. You can now sign in with your face.') : this.$t('This device can now sign in with your face.');
          this.consent = false; router.reload({ only: ['faceLogin'], preserveScroll: true });
        } else this.result = data;
      } catch (e) {
        await pause(Math.max(0, 700 - (Date.now() - started)));
        this.stage = 'bad'; this.stageText = this.$t('Not saved');
        await pause(900);
        this.stage = '';
        if (e?.response?.status === 423) {
          // the password box was closed without confirming: nothing was saved, go back to the buttons
          this.mode = '';
          this.error = this.$t('Nothing was saved because the password was not confirmed. Press the button again to retry.');
          return;
        }
        this.scanKey++;
        this.error = this.msg(e, enrol ? this.$t('Your face could not be saved. Please try again.') : this.$t('The check could not be completed.'));
      }
    },
    remove() {
      this.busy = true;
      this.guarded(() => axios.delete(route('profile.face.destroy')))
        .then(() => { this.done = this.$t('Your face was removed. Face login is off for your account.'); router.reload({ only: ['faceLogin'], preserveScroll: true }); })
        .finally(() => (this.busy = false));
    },
  },
};
</script>

<style>
.faceform__load { margin: 0 auto 0.9rem; }
.faceform__scan, .faceform__scan *, .faceform__load, .faceform__load * { font-family: inherit; }
.faceform__scan { padding: 1rem 0 0.4rem; }
.faceform__head { display: flex; align-items: center; gap: 0.8rem; margin: 0 auto 0.7rem; max-width: 30rem; text-align: left; } .faceform__head h3 { margin: 0; font-size: 1.05rem; font-weight: 800; color: var(--lg-ink); } .faceform__head p { margin: 2px 0 0; font-size: 0.82rem; line-height: 1.45; color: var(--lg-mute); }
.faceform__badge { display: grid; place-items: center; flex: none; width: 46px; height: 46px; border-radius: 14px; color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.85); }
.faceform__steps { display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 0.35rem; margin: 0 0 0.6rem; font-size: 0.72rem; font-weight: 800; color: #1e3a8a; } .faceform__steps span { padding: 0.2rem 0.65rem; border-radius: 99px; background: #e8f0fe; box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.25); } .faceform__steps i { width: 14px; height: 2px; border-radius: 2px; background: #93c5fd; }
@media (max-width: 560px) { .faceform__steps { gap: 0.3rem; } .faceform__steps i { display: none; } .faceform__steps span { padding: 0.18rem 0.5rem; font-size: 0.68rem; } .faceform__head h3 { font-size: 0.98rem; } }
.faceform__err { display: flex; align-items: center; justify-content: center; gap: 0.4rem; max-width: 30rem; margin: 0.6rem auto 0; padding: 0.55rem 0.8rem; border-radius: 12px; font-size: 0.84rem; font-weight: 700; color: #9f1239; background: #fff1f2; box-shadow: inset 0 0 0 1px #fecdd3; }
.faceform__cancel { display: inline-flex; align-items: center; gap: 0.4rem; min-height: 38px; padding: 0 1.1rem; border: 0; border-radius: 999px; font-size: 0.82rem; font-weight: 700; color: var(--lg-mute); background: transparent; box-shadow: inset 0 0 0 1px var(--lg-line); cursor: pointer; transition: color 0.15s, box-shadow 0.15s, background 0.15s; } .faceform__cancel:hover { color: #be123c; background: rgba(244, 63, 94, 0.06); box-shadow: inset 0 0 0 1px rgba(244, 63, 94, 0.45); }
.faceform__points li { padding: 0.5rem 0.7rem; border-radius: 12px; background: rgba(16, 185, 129, 0.07); box-shadow: inset 0 0 0 1px rgba(16, 185, 129, 0.18); }
.faceform__consent { padding: 0.75rem 0.9rem; border-radius: 14px; background: rgba(59, 130, 246, 0.06); box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.22); transition: background 0.2s; } .faceform__consent:hover { background: rgba(59, 130, 246, 0.1); } .faceform__consent input { width: 18px; height: 18px; flex: none; }
html.dark .faceform__head h3 { color: #f1f5f9; } html.dark .faceform__steps { color: #bfdbfe; } html.dark .faceform__steps span { background: rgba(59, 130, 246, 0.18); } html.dark .faceform__err { color: #fecdd3; background: rgba(244, 63, 94, 0.14); } html.dark .faceform__points li { background: rgba(16, 185, 129, 0.1); }
.faceform__points { display: grid; gap: 0.5rem; margin: 0 0 0.9rem; padding: 0; list-style: none; font-size: 0.84rem; color: var(--lg-mute); }
.faceform__points li { display: flex; gap: 0.5rem; align-items: flex-start; } .faceform__points svg { flex: none; margin-top: 2px; color: #10b981; }
.faceform__consent { display: flex; gap: 0.6rem; align-items: flex-start; font-size: 0.82rem; font-weight: 600; color: var(--lg-ink); cursor: pointer; } .faceform__consent input { margin-top: 3px; }
.faceform__devices { margin: 0.8rem 0; padding: 0.8rem 0.9rem; border-radius: 14px; background: rgba(59, 130, 246, 0.05); box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.2); } .faceform__devices h4 { margin: 0 0 0.2rem; font-size: 0.9rem; font-weight: 800; color: var(--lg-ink); } .faceform__devnote { margin: 0 0 0.6rem; font-size: 0.78rem; color: var(--lg-mute); }
.faceform__devices ul { display: grid; gap: 0.4rem; margin: 0 0 0.7rem; padding: 0; list-style: none; } .faceform__devices li { display: flex; align-items: center; gap: 0.55rem; padding: 0.45rem 0.6rem; border-radius: 10px; background: rgba(255, 255, 255, 0.6); font-size: 0.82rem; } .faceform__devices li span { flex: 1; min-width: 0; } .faceform__devices li small { display: block; color: var(--lg-mute); font-size: 0.72rem; }
.faceform__this { margin-left: 0.4rem; padding: 1px 7px; border-radius: 99px; font-style: normal; font-size: 0.66rem; font-weight: 800; color: #065f46; background: #d1fae5; } .app-shell .faceform__x { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 34px; height: 34px; min-height: 0; padding: 0; border: 0; border-radius: 10px; color: #be123c; background: rgba(244, 63, 94, 0.08); cursor: pointer; } .faceform__x > div { display: grid; place-items: center; } .app-shell .faceform__x:hover:not(:disabled) { background: rgba(244, 63, 94, 0.16); } .app-shell .faceform__x:disabled { opacity: 0.5; cursor: wait; } .faceform__none { color: var(--lg-mute); }
html.dark .faceform__devices li { background: rgba(255, 255, 255, 0.05); }
.faceform__result { display: flex; gap: 0.7rem; align-items: center; margin-bottom: 0.7rem; padding: 0.8rem 1rem; border-radius: 14px; font-size: 0.85rem; animation: pf-rise 0.4s ease both; }
.faceform__result b { display: block; } .faceform__result span { color: inherit; opacity: 0.85; }
.faceform__result.is-ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; } .faceform__result.is-bad { background: #fff1f2; color: #9f1239; border: 1px solid #fecdd3; }
.faceform__nudge { animation: lf-shake 0.45s both; } .faceform__consent.faceform__nudge { color: #e11d48; }
@keyframes lf-shake { 20%, 60% { transform: translateX(-5px); } 40%, 80% { transform: translateX(5px); } }
.faceform__done { display: flex; align-items: center; gap: 0.4rem; margin: 0 0 0.7rem; font-size: 0.85rem; font-weight: 700; color: #059669; }
</style>

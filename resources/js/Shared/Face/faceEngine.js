// Browser-side face engine.
//   Tracking (is a face there, are the eyes shut, which way is the head turned): Google MediaPipe Face Landmarker, 30+ frames a second.
//   Recognition (turn a frame into 128 numbers): face-api, only for the 3 to 5 frames that are actually kept.
// Everything is loaded from this site (public/face-models) the first time a scan opens; nothing is added to normal pages.
let tracker = null;
let tesselation = [];
let recogniser = null;
let loadingTracker = null;
let loadingRecogniser = null;

// Loading progress (0 to 100) for the "preparing the face scanner" bar. The tracker is the heavier half, so it carries more of the bar.
const parts = { tracker: 0, recogniser: 0 };
const listeners = new Set();
const total = () => Math.round(parts.tracker * 0.6 + parts.recogniser * 0.4);
const bump = (part, pct) => {
  if (pct <= parts[part]) return;
  parts[part] = Math.min(100, pct);
  listeners.forEach(fn => fn(total()));
};
/** Calls fn with the current progress now and after every step. Returns a function that stops the calls. */
export function onEngineProgress(fn) {
  listeners.add(fn);
  fn(total());
  return () => listeners.delete(fn);
}

export function loadTracker() {
  if (tracker) { bump('tracker', 100); return Promise.resolve(tracker); }
  if (!loadingTracker) {
    loadingTracker = (async () => {
      const probeGl = document.createElement('canvas');
      if (!probeGl.getContext('webgl2')) { const e = new Error('nowebgl'); e.code = 'nowebgl'; throw e; }
      bump('tracker', 5);
      const { FaceLandmarker, FilesetResolver } = await import('@mediapipe/tasks-vision');
      bump('tracker', 30);
      tesselation = FaceLandmarker.FACE_LANDMARKS_TESSELATION.map(c => [c.start, c.end]);
      const files = await FilesetResolver.forVisionTasks('/face-models/mediapipe');
      bump('tracker', 50);
      const make = delegate => FaceLandmarker.createFromOptions(files, {
        baseOptions: { modelAssetPath: '/face-models/face_landmarker.task', delegate },
        runningMode: 'VIDEO', numFaces: 1, outputFaceBlendshapes: true,
      });
      // The graphics-chip mode can start fine and then fail on the first picture (blocked drivers, remote desktops): prove it with a
      // blank picture, and fall back to the processor mode if it does not work.
      try {
        const gpu = await make('GPU');
        bump('tracker', 90);
        const probe = document.createElement('canvas');
        probe.width = probe.height = 64;
        gpu.detectForVideo(probe, performance.now());
        tracker = gpu;
      } catch (e) {
        tracker = await make('CPU');
      }
      bump('tracker', 100);
      return tracker;
    })().catch(e => { loadingTracker = null; throw e; });
  }
  return loadingTracker;
}

export function loadRecogniser() {
  if (recogniser) { bump('recogniser', 100); return Promise.resolve(recogniser); }
  if (!loadingRecogniser) {
    loadingRecogniser = (async () => {
      const faceapi = await import('@vladmandic/face-api');
      bump('recogniser', 35);
      // The bundled library lists a WebAssembly backend first, which needs extra files. Use the graphics chip, or the plain CPU.
      for (const name of ['webgl', 'cpu']) {
        try { if (await faceapi.tf.setBackend(name)) break; } catch (e) { /* try the next one */ }
      }
      await faceapi.tf.ready();
      bump('recogniser', 55);
      let loaded = 0;
      const one = p => p.then(() => bump('recogniser', 55 + Math.round((++loaded / 3) * 45)));
      await Promise.all([
        one(faceapi.nets.tinyFaceDetector.loadFromUri('/face-models')),
        one(faceapi.nets.faceLandmark68Net.loadFromUri('/face-models')),
        one(faceapi.nets.faceRecognitionNet.loadFromUri('/face-models')),
      ]);
      recogniser = faceapi;
      // The first recognition on the graphics chip compiles its programs (a second or more). Do that now, on a blank picture, in the
      // background, so the first real frame is as fast as the rest.
      try {
        const c = document.createElement('canvas');
        c.width = c.height = 160;
        faceapi.detectSingleFace(c, new faceapi.TinyFaceDetectorOptions({ inputSize: 160, scoreThreshold: 0.9 })).withFaceLandmarks().withFaceDescriptor().catch(() => {});
      } catch (e) { /* warm-up is optional */ }
      return faceapi;
    })().catch(e => { loadingRecogniser = null; throw e; });
  }
  return loadingRecogniser;
}

/** Loads both engines (in parallel). Safe to call early: it only downloads once. */
export function loadEngine() {
  return Promise.all([loadTracker(), loadRecogniser()]);
}

const EDGE_A = 234; // the two cheek edges of the face mesh, used to see which way the head is turned
const EDGE_B = 454;
const NOSE = 1;

/**
 * Reads a MediaPipe result into plain numbers, or null when no face is in view.
 *   blink: 0 (eyes wide open) to 1 (shut), the average of both eyes
 *   yaw:   where the nose sits between the two cheek edges in the camera picture (0.5 = facing the camera). Above the resting value =
 *          the person turned to THEIR left, below = to their right (the camera picture is not mirrored in these numbers)
 */
export function readPose(result) {
  const lm = result && result.faceLandmarks && result.faceLandmarks[0];
  if (!lm) return null;
  let minX = 1, maxX = 0, minY = 1, maxY = 0;
  for (const p of lm) {
    if (p.x < minX) minX = p.x; if (p.x > maxX) maxX = p.x;
    if (p.y < minY) minY = p.y; if (p.y > maxY) maxY = p.y;
  }
  const a = lm[EDGE_A].x, b = lm[EDGE_B].x;
  const lo = Math.min(a, b), hi = Math.max(a, b);
  const yaw = (lm[NOSE].x - lo) / Math.max(0.0001, hi - lo);
  let blink = 0;
  const cats = result.faceBlendshapes && result.faceBlendshapes[0] && result.faceBlendshapes[0].categories;
  if (cats) {
    const l = cats.find(c => c.categoryName === 'eyeBlinkLeft');
    const r = cats.find(c => c.categoryName === 'eyeBlinkRight');
    blink = ((l ? l.score : 0) + (r ? r.score : 0)) / 2;
  }
  let smile = 0;
  if (cats) {
    const l = cats.find(c => c.categoryName === 'mouthSmileLeft');
    const r = cats.find(c => c.categoryName === 'mouthSmileRight');
    smile = ((l ? l.score : 0) + (r ? r.score : 0)) / 2;
  }
  return { blink, smile, yaw, cx: (minX + maxX) / 2, cy: (minY + maxY) / 2, size: maxX - minX, lm };
}

/** The pairs of landmark numbers that make up the face mesh (for drawing). */
export const getTesselation = () => tesselation;

/** One tracking step on the live video. Returns a pose, or null when there is no face. */
export function track(video) {
  if (!tracker) return null;
  return readPose(tracker.detectForVideo(video, performance.now()));
}

/** Turns the current video frame into the 128 numbers that are compared. Slower (about a tenth of a second); used only for kept frames. */
export async function describe(video) {
  const faceapi = await loadRecogniser();
  const d = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.4 })).withFaceLandmarks().withFaceDescriptor();
  return d && d.descriptor && d.descriptor.length === 128 ? Array.from(d.descriptor).map(n => Math.round(n * 1e6) / 1e6) : null;
}

export async function openCamera() {
  if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    const e = new Error('insecure');
    e.code = 'insecure';
    throw e;
  }
  return navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 }, frameRate: { ideal: 30 } } });
}

export function closeCamera(stream) {
  if (stream) stream.getTracks().forEach(t => t.stop());
}

/** Is the face sitting well inside the oval, big enough and not too close? Returns a hint when it is not. */
export function framing(p) {
  if (p.size < 0.2) return 'closer';
  if (p.size > 0.68) return 'farther';
  if (Math.abs(p.cx - 0.5) > 0.2 || Math.abs(p.cy - 0.5) > 0.22) return 'centre';
  return 'ok';
}

export const SMILE_MIN = 0.35;  // how wide the smile must be (0..1) for the enrolment "smile" step
export const TURN = 0.12;       // how far the nose must move for "turn your head" at login
export const TURN_ENROL = 0.07; // gentler for the enrolment samples (taken while turned a little)
export const FRONT = 0.06;      // how close to facing the camera counts as "straight"
export const TURN_MAX = 0.36;   // further than this is not a believable head turn
export const HOLD_MS = 380;     // how long to hold still before a frame is kept
export const HOLD_TURNED_MS = 300;
// Eyes count as shut when the blink score rises well above this person's own resting value (someone looking down at the screen has
// half-lowered lids all the time), and as open again once it falls back near it. The gap stops flicker.
const CLOSE_RISE = 0.3;
const CLOSE_MIN = 0.4;
const OPEN_RISE = 0.15;

export const newStepState = (yawBase = 0, blinkBase = -1) => ({ yawBase, blinkBase, since: Date.now(), holdFrom: 0, sawClosed: false, closed: false, acted: false, sm: null, cue: null, peak: 0 });

// Blink on cue (the sign-in test). After the person has looked straight at the camera with open eyes for the server's random wait, the
// ring flashes; a real blink must START between CUE_MIN_MS and CUE_MAX_MS later and last CUE_DUR_MIN..CUE_DUR_MAX ms. The server checks
// the same limits again on what is reported, so these mirror config/face.php.
export const CUE_MIN_MS = 130;
export const CUE_MAX_MS = 1000;
export const CUE_DUR_MIN = 50;
export const CUE_DUR_MAX = 750;
export const CUE_MISSES = 3;
export const CUE_HOLD_MS = 260;

const lerp = (a, b, t) => a + (b - a) * t;

/** One frame of a "blink on cue" step. Returns the same shape as advance() plus { cue: true } on the frame the ring should flash. */
function advanceCue(step, s, p, closed, straight, now) {
  const c = s.cue || (s.cue = { phase: 'arm', stable: 0, cueAt: 0, closeAt: 0, latency: 0, dur: 0, misses: 0 });
  const say = (hint, kind, extra = {}) => ({ capture: false, hint, kind, arrow: '', hold: 0, cue: false, ...extra });

  if (c.phase === 'arm') {
    if (closed) { c.stable = 0; return say('keepOpen', 'warn'); }
    if (!straight) { c.stable = 0; return say('straight', 'warn'); }
    if (!c.stable) c.stable = now;
    const t = (now - c.stable) / step.delay;
    if (t >= 1) { c.phase = 'cue'; c.cueAt = now; c.closeAt = 0; return say('cueNow', 'good', { cue: true, hold: 0 }); }
    return say('steady', 'info', { hold: Math.min(0.9, t) * 0.5 });
  }

  if (c.phase === 'cue') {
    if (!c.closeAt) {
      if (closed) {
        c.closeAt = now;
        c.latency = Math.round(now - c.cueAt);
        if (c.latency < CUE_MIN_MS) return rearm(c, s, 'early');
        return say('closedNow', 'good', { hold: 0.6 });
      }
      if (now - c.cueAt > CUE_MAX_MS + 250) return rearm(c, s, 'missed');
      return say('cueNow', 'good', { hold: 0.5 });
    }
    if (closed) {
      if (now - c.closeAt > CUE_DUR_MAX) return rearm(c, s, 'missed');
      return say('closedNow', 'good', { hold: 0.7 });
    }
    c.dur = Math.round(now - c.closeAt);
    if (c.dur < CUE_DUR_MIN) return rearm(c, s, 'missed');
    c.phase = 'recover'; s.holdFrom = 0;
  }

  // eyes are open again: face the camera for a moment, then keep the frame
  if (!straight || closed) { s.holdFrom = 0; return say(closed ? 'open' : 'straight', 'warn', { hold: 0.8 }); }
  if (!s.holdFrom) s.holdFrom = now;
  const h = Math.min(1, (now - s.holdFrom) / CUE_HOLD_MS);
  if (h >= 1) s.result = { k: 'cue', delay: step.delay, latency: c.latency, dur: c.dur, misses: c.misses };
  return say('hold', 'good', { capture: h >= 1, hold: 0.8 + 0.2 * h });
}

function rearm(c, s, why) {
  c.misses++;
  c.phase = 'arm'; c.stable = 0; c.closeAt = 0; s.closed = false;
  if (c.misses > CUE_MISSES) return { capture: false, hint: 'toomany', kind: 'bad', arrow: '', hold: 0, cue: false, fail: true };
  return { capture: false, hint: why, kind: 'warn', arrow: '', hold: 0, cue: false };
}

/**
 * One decision per camera frame. Pure (no camera, no browser) so it can be tested. now = time in ms.
 * step: { k: 'front' | 'blink' | 'smile' | 'left' | 'right', capture: 'front' | 'turned' }
 * Returns { capture, hint, kind, arrow, hold } (hold = 0..1 how far through the "hold still" wait) and updates s in place.
 */
export function advance(step, s, pose, enrol, now) {
  if (!pose) {
    s.holdFrom = 0;
    if (s.cue && s.cue.phase === 'arm') s.cue.stable = 0;
    return { capture: false, hint: 'noface', kind: 'warn', arrow: '', hold: 0 };
  }
  // light smoothing so a single jittery frame cannot flip a decision (the blink score is left raw: it is already quick and clean)
  s.sm = s.sm ? { yaw: lerp(s.sm.yaw, pose.yaw, 0.55), cx: lerp(s.sm.cx, pose.cx, 0.5), cy: lerp(s.sm.cy, pose.cy, 0.5), size: lerp(s.sm.size, pose.size, 0.5) } : { yaw: pose.yaw, cx: pose.cx, cy: pose.cy, size: pose.size };
  const p = { ...s.sm, blink: pose.blink, smile: pose.smile || 0 };

  const fit = framing(p);
  if (fit !== 'ok') { s.holdFrom = 0; if (s.cue && s.cue.phase === 'arm') s.cue.stable = 0; return { capture: false, hint: fit, kind: 'warn', arrow: '', hold: 0 }; }

  // resting blink value: the lowest recent reading, allowed to drift up slowly so it adapts if the person moves or the light changes
  s.blinkBase = s.blinkBase < 0 ? p.blink : Math.min(p.blink, s.blinkBase + 0.003);
  const rise = p.blink - s.blinkBase;
  if (s.closed) { if (rise < OPEN_RISE || p.blink < CLOSE_MIN - 0.1) s.closed = false; } else if (rise > CLOSE_RISE && p.blink > CLOSE_MIN) s.closed = true;
  const closed = s.closed;

  // "Straight" and "turned" are measured from this person's own resting pose: every face reads a little off-centre.
  if (!closed && (step.k === 'front' || step.k === 'cue' || (step.k === 'blink' && !s.acted)) && Math.abs(p.yaw - 0.5) < 0.17) s.yawBase = s.yawBase ? lerp(s.yawBase, p.yaw, 0.15) : p.yaw;
  const offset = p.yaw - (s.yawBase || 0.5);
  const straight = Math.abs(offset) < (s.yawBase ? FRONT : 0.12) && !closed;
  const thr = enrol ? TURN_ENROL : TURN;

  const hold = (ms) => {
    if (!s.holdFrom) s.holdFrom = now;
    return Math.min(1, (now - s.holdFrom) / ms);
  };

  if (step.k === 'cue') return advanceCue(step, s, p, closed, straight, now);

  if (step.k === 'blink' && !s.acted) {
    if (closed) s.sawClosed = true;
    else if (s.sawClosed && straight) { s.acted = true; s.holdFrom = 0; }
    return { capture: false, hint: closed ? 'closedNow' : 'blink', kind: closed ? 'good' : 'info', arrow: '', hold: 0 };
  }
  if ((step.k === 'left' || step.k === 'right') && !s.acted) {
    const want = step.k === 'left' ? offset : -offset;
    if (want >= thr && want < TURN_MAX) {
      if (step.capture === 'turned') {
        const h = hold(HOLD_TURNED_MS);
        return { capture: h >= 1, hint: step.k, kind: 'good', arrow: step.k, hold: h };
      }
      s.acted = true; s.holdFrom = 0; s.peak = Math.round(want * 1000) / 1000;
      return { capture: false, hint: 'back', kind: 'good', arrow: '', hold: 0 };
    }
    s.holdFrom = 0;
    return { capture: false, hint: step.k, kind: 'info', arrow: step.k, hold: 0 };
  }
  if (step.k === 'smile') {
    if (!straight) { s.holdFrom = 0; return { capture: false, hint: closed ? 'open' : 'straight', kind: 'warn', arrow: '', hold: 0 }; }
    if (p.smile < SMILE_MIN) { s.holdFrom = 0; return { capture: false, hint: 'smile', kind: 'info', arrow: '', hold: 0 }; }
    const hs = hold(HOLD_MS);
    return { capture: hs >= 1, hint: 'smileHold', kind: 'good', arrow: '', hold: hs };
  }
  if (!straight) { s.holdFrom = 0; return { capture: false, hint: closed ? 'open' : 'straight', kind: 'warn', arrow: '', hold: 0 }; }
  const h = hold(HOLD_MS);
  return { capture: h >= 1, hint: 'hold', kind: 'good', arrow: '', hold: h };
}

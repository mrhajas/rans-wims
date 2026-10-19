// Blink-on-cue logic of the face scanner (pure: no camera). Run: node tests/js/faceCue.test.mjs
import assert from 'node:assert/strict';
import { advance, newStepState } from '../../resources/js/Shared/Face/faceEngine.js';

const pose = (blink, yaw = 0.5) => ({ blink, yaw, cx: 0.5, cy: 0.5, size: 0.4, lm: [] });
let passed = 0;
const test = (name, fn) => { fn(); passed++; console.log('  ok  ', name); };

/** Runs a script of [ms, blink] samples (30 fps interpolation of a step function) and returns what happened. */
function run(step, events, until = 12000) {
  const s = newStepState();
  const log = { cueAt: null, capture: null, hints: new Set(), fail: false };
  let t = 1000;
  for (let ms = 0; ms <= until; ms += 33) {
    const blink = events(ms, log);
    const r = advance(step, s, pose(blink), false, t + ms);
    log.hints.add(r.hint);
    if (r.cue && log.cueAt === null) log.cueAt = ms;
    if (r.fail) { log.fail = true; break; }
    if (r.capture) { log.capture = ms; log.result = s.result; break; }
  }
  return log;
}
const open = 0.1, shut = 0.9;

test('a person who looks ahead and blinks after the flash passes, with sensible numbers', () => {
  const step = { k: 'cue', delay: 1500, capture: 'front' };
  let blinkAt = null;
  const log = run(step, (ms, l) => { if (l.cueAt !== null && blinkAt === null) blinkAt = l.cueAt + 380; return blinkAt !== null && ms >= blinkAt && ms < blinkAt + 170 ? shut : open; });
  assert.ok(log.cueAt >= 1500 && log.cueAt < 1800, 'cue fires after the wait: ' + log.cueAt);
  assert.ok(log.capture, 'frame kept');
  assert.equal(log.result.k, 'cue');
  assert.equal(log.result.delay, 1500);
  assert.ok(log.result.latency >= 330 && log.result.latency <= 480, 'latency ' + log.result.latency);
  assert.ok(log.result.dur >= 130 && log.result.dur <= 240, 'duration ' + log.result.dur);
  assert.equal(log.result.misses, 0);
});

test('a photo (no blink at all) never passes: the cue is missed again and again, then it fails', () => {
  const log = run({ k: 'cue', delay: 1200, capture: 'front' }, () => open, 30000);
  assert.equal(log.capture, null);
  assert.equal(log.fail, true);
  assert.ok(log.hints.has('missed'));
});

test('a blink before the flash restarts the wait (a video that blinks by itself does not get a free pass)', () => {
  const step = { k: 'cue', delay: 1500, capture: 'front' };
  const log = run(step, (ms) => (ms >= 1000 && ms < 1150 ? shut : open));
  assert.ok(log.cueAt >= 1000 + 150 + 1500 - 100, 'the wait started again after the early blink: ' + log.cueAt);
});

test('a blink that lands within 130 ms of the flash is too early to be a reaction', () => {
  const step = { k: 'cue', delay: 1200, capture: 'front' };
  let n = 0;
  const log = run(step, (ms, l) => (l.cueAt !== null && ms >= l.cueAt + 40 && ms < l.cueAt + 200 && n++ >= 0 ? shut : open), 3000);
  assert.ok(log.hints.has('early'));
});

test('eyes held shut for a long time is not a blink', () => {
  const step = { k: 'cue', delay: 1200, capture: 'front' };
  const log = run(step, (ms, l) => (l.cueAt !== null && ms >= l.cueAt + 300 ? shut : open), 5000);
  assert.equal(log.capture, null);
  assert.ok(log.hints.has('missed'));
});

test('looking away while waiting restarts the wait', () => {
  const step = { k: 'cue', delay: 1400, capture: 'front' };
  const s = newStepState();
  let cue = null;
  for (let ms = 0; ms < 6000; ms += 33) {
    const yaw = ms > 600 && ms < 900 ? 0.8 : 0.5;
    const r = advance(step, s, pose(open, yaw), false, 1000 + ms);
    if (r.cue && cue === null) cue = ms;
  }
  assert.ok(cue >= 900 + 1400 - 150, 'cue came ' + cue);
});

console.log(passed + ' passed');

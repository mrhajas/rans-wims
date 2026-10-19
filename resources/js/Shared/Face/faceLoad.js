import { reactive } from 'vue';

/*
 * Starts the face scanner engine early and tells the page how far it is, so "Preparing the face scanner" can show a real bar while the person
 * is busy confirming their password. Light on purpose (the heavy code is imported only when start() runs).
 */
export const faceLoad = reactive({ pct: 0, state: 'idle' }); // idle | loading | ready | error

let started = null;

export function startFaceEngine() {
  if (started) return started;
  faceLoad.state = 'loading';
  started = import('./faceEngine')
    .then(async engine => {
      engine.onEngineProgress(p => { faceLoad.pct = Math.max(faceLoad.pct, p); });
      await engine.loadEngine();
      faceLoad.pct = 100;
      faceLoad.state = 'ready';
    })
    .catch(() => {
      faceLoad.state = 'error';
      started = null; // a later try may work
    });
  return started;
}

import axios from 'axios';
import { router } from '@inertiajs/vue3';

/*
 * Keeps a sign-in style page (login, forgot / reset password, two-step code) usable however long it was left open.
 *
 * Without this, a login page that sat idle for a while ended on "419 Session Expired" the moment somebody pressed Sign in: the
 * security token the browser holds (the XSRF cookie) belongs to a session that had already expired on the server.
 *
 * What it does, quietly:
 *  - every few minutes while the page is on screen, and again when the tab is shown / the computer comes back online, it asks the
 *    server for a fresh security cookie (this also keeps the visitor's session alive);
 *  - `props` lists page props that must be fresh as well (the login page's arithmetic challenge expires too): they are reloaded
 *    in place, without touching what the person has typed;
 *  - `ensureFresh()` is awaited right before a form is sent, so the request always carries a token the server accepts.
 */
const EVERY_MS = 4 * 60 * 1000;
const STALE_MS = 90 * 1000;

export default function guestKeepalive(props = []) {
  return {
    created() {
      let last = Date.now();
      let busy = null;
      const refresh = () => {
        if (busy) return busy;
        busy = axios.get('/sanctum/csrf-cookie')
          .then(() => new Promise(done => {
            last = Date.now();
            if (!props.length) return done();
            router.reload({ only: props, preserveState: true, preserveScroll: true, onFinish: done });
          }))
          .catch(() => {})
          .finally(() => { busy = null; });
        return busy;
      };
      const onShow = () => { if (document.visibilityState === 'visible' && Date.now() - last > STALE_MS) refresh(); };
      this.$guestKeepalive = {
        refresh,
        // call before sending a form: refreshes only when the page has been open a while
        ensureFresh: async (maxAge = STALE_MS) => { if (Date.now() - last > maxAge) await refresh(); },
        start: () => {
          this.__gkTimer = setInterval(() => { if (document.visibilityState === 'visible') refresh(); }, EVERY_MS);
          document.addEventListener('visibilitychange', onShow);
          window.addEventListener('focus', onShow);
          window.addEventListener('online', refresh);
        },
        stop: () => {
          clearInterval(this.__gkTimer);
          document.removeEventListener('visibilitychange', onShow);
          window.removeEventListener('focus', onShow);
          window.removeEventListener('online', refresh);
        },
      };
    },
    mounted() { this.$guestKeepalive.start(); },
    unmounted() { this.$guestKeepalive.stop(); },
  };
}

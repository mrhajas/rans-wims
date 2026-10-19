import axios from 'axios';

/*
 * The discreet choice on the login page: click the logo N times within a short window (settings come from the server, `ctx` page prop: null when the
 * feature is off, in which case this does nothing at all). It only asks the server to remember "the next sign-in is for the other WIMS" in an encrypted,
 * short-lived cookie, and shows the glow. It grants nothing: the person still signs in, and the server checks everything again.
 *
 * - nothing is shown or said, no storage is used: the state lives in this component only, so a refresh, a cancel or the expiry puts the page back.
 * - the same number of clicks again cancels the choice.
 */
export default {
  data() {
    return { ctxActive: false, ctxTimes: [], ctxTimer: null };
  },
  beforeUnmount() {
    clearTimeout(this.ctxTimer);
  },
  methods: {
    ctxLogoClick() {
      const cfg = this.$page.props.ctx;
      if (!cfg) return;
      const now = Date.now();
      this.ctxTimes = this.ctxTimes.filter(t => now - t <= cfg.window_ms);
      this.ctxTimes.push(now);
      if (this.ctxTimes.length < cfg.clicks) return;
      this.ctxTimes = [];
      this.ctxToggle();
    },
    async ctxToggle() {
      const headers = { 'X-Requested-With': 'XMLHttpRequest' };
      try {
        if (this.ctxActive) {
          await axios.delete('/auth/ctx', { headers });
          this.ctxOff(false);
        } else {
          const r = await axios.post('/auth/ctx', {}, { headers });
          this.ctxActive = true;
          clearTimeout(this.ctxTimer);
          this.ctxTimer = setTimeout(() => this.ctxOff(true), Math.max(30, Number(r.data && r.data.ttl) || 600) * 1000);
        }
        // the page's own session / security token / question must now come from the chosen side
        if (this.$guestKeepalive) await this.$guestKeepalive.refresh();
      } catch (e) {
        this.ctxOff(false);
      }
    },
    ctxOff(refresh) {
      clearTimeout(this.ctxTimer);
      this.ctxActive = false;
      if (refresh && this.$guestKeepalive) this.$guestKeepalive.refresh();
    },
  },
};

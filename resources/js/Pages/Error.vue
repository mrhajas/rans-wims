<template>
  <Head :title="title" />
  <div class="err-shell min-h-screen px-4 py-6 sm:px-8 sm:py-10 flex items-center justify-center">
    <div class="err-orb err-orb-a"></div>
    <div class="err-orb err-orb-b"></div>
    <div class="err-orb err-orb-c"></div>

    <div class="relative z-10 w-full max-w-lg">
      <div class="err-glow-wrap">
        <div class="err-glow-halo"></div>
        <div class="err-card err-card-in text-center px-6 py-9 sm:px-10 sm:py-12">
          <img src="/images/logo.png" alt="RANS Logo" class="err-logo mx-auto h-auto w-full max-w-[240px]" />

          <div class="err-illustration mx-auto mt-6 mb-2 w-full max-w-[340px]">
            <svg viewBox="0 0 500 320" xmlns="http://www.w3.org/2000/svg" class="w-full h-auto">
              <defs>
                <pattern id="errStripes" width="26" height="26" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                  <rect width="13" height="26" fill="#ef4444" />
                  <rect x="13" width="13" height="26" fill="#f8fafc" />
                </pattern>
                <linearGradient id="errSignGrad" x1="0" y1="0" x2="1" y2="1">
                  <stop offset="0%" stop-color="#fde68a" />
                  <stop offset="100%" stop-color="#f59e0b" />
                </linearGradient>
                <radialGradient id="errBeaconGlow" cx="50%" cy="50%" r="50%">
                  <stop offset="0%" stop-color="#f87171" stop-opacity="0.9" />
                  <stop offset="100%" stop-color="#f87171" stop-opacity="0" />
                </radialGradient>
              </defs>

              <ellipse cx="250" cy="290" rx="170" ry="16" class="err-ground" />

              <!-- Left cone -->
              <g class="err-cone err-cone-left">
                <rect x="55" y="255" width="46" height="16" rx="4" fill="#c2410c" />
                <polygon points="78,150 60,250 96,250" fill="#f97316" />
                <polygon points="70.5,195 85.5,195 89.5,215 66.5,215" fill="#f8fafc" />
                <polygon points="74,168 83,168 86,182 71,182" fill="#f8fafc" />
              </g>

              <!-- Right cone -->
              <g class="err-cone err-cone-right">
                <rect x="399" y="255" width="46" height="16" rx="4" fill="#c2410c" />
                <polygon points="422,150 404,250 440,250" fill="#f97316" />
                <polygon points="414.5,195 429.5,195 433.5,215 410.5,215" fill="#f8fafc" />
                <polygon points="418,168 427,168 430,182 415,182" fill="#f8fafc" />
              </g>

              <!-- Barrier legs -->
              <polygon points="150,268 178,190 196,190 178,268" fill="#cbd5e1" />
              <polygon points="350,268 322,190 304,190 322,268" fill="#cbd5e1" />
              <polygon points="150,268 178,190 186,190 160,268" fill="#e2e8f0" />
              <polygon points="350,268 322,190 314,190 340,268" fill="#e2e8f0" />

              <!-- Beacon poles -->
              <rect x="171" y="120" width="6" height="75" rx="3" fill="#94a3b8" />
              <rect x="323" y="120" width="6" height="75" rx="3" fill="#94a3b8" />

              <!-- Beacon glows -->
              <circle cx="174" cy="115" r="22" fill="url(#errBeaconGlow)" class="err-beacon-glow err-beacon-glow-1" />
              <circle cx="326" cy="115" r="22" fill="url(#errBeaconGlow)" class="err-beacon-glow err-beacon-glow-2" />
              <circle cx="174" cy="115" r="9" fill="#ef4444" class="err-beacon err-beacon-1" />
              <circle cx="326" cy="115" r="9" fill="#ef4444" class="err-beacon err-beacon-2" />

              <!-- Striped bars -->
              <rect x="120" y="205" width="260" height="30" rx="6" fill="url(#errStripes)" stroke="#fff" stroke-width="3" />
              <rect x="120" y="245" width="260" height="24" rx="6" fill="url(#errStripes)" stroke="#fff" stroke-width="3" />

              <!-- Warning sign -->
              <g class="err-sign">
                <rect x="196" y="70" width="108" height="108" rx="14" fill="url(#errSignGrad)" stroke="#92400e" stroke-width="4" transform="rotate(45 250 124)" />
                <text x="250" y="124" text-anchor="middle" dominant-baseline="central" class="err-sign-text">{{ status }}</text>
              </g>
            </svg>
          </div>

          <h1 class="err-oops">{{ $t('Oops!') }}</h1>
          <h2 class="err-title">{{ title }}</h2>
          <p class="err-description">{{ description }}</p>

          <!-- a page the person may not open (403), or that is not there (404 / 405): back to where they came from, not the dashboard -->
          <div v-if="canBack" class="err-actions mt-7">
            <button type="button" class="err-home-btn" @click="goBack">
              <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M17 10a1 1 0 01-1 1H6.414l3.293 3.293a1 1 0 01-1.414 1.414l-5-5a1 1 0 010-1.414l5-5a1 1 0 011.414 1.414L6.414 9H16a1 1 0 011 1z" clip-rule="evenodd" /></svg>
              <span>{{ $t('Go Back') }}</span>
            </button>
            <Link :href="homeHref" class="err-sub-link">{{ $t('Dashboard') }}</Link>
          </div>
          <Link v-else :href="homeHref" class="err-home-btn mt-7">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
              <path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h3a1 1 0 001-1v-3h2v3a1 1 0 001 1h3a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z" />
            </svg>
            <span>{{ $t('Go Back Home!!!') }}</span>
          </Link>
        </div>
      </div>

      <p class="err-footer">
        {{ $t('RANS Workflow Integrated Management System') }}, ({{ versionLabel }})<br />
        {{ $t('Developed by Extrion') }}
      </p>
    </div>
  </div>
</template>

<script>
import { Head, Link } from '@inertiajs/vue3';

export default {
  components: { Head, Link },

  props: {
    status: Number,
  },

  data() {
    return { recovering: false, canBack: false };
  },

  mounted() {
    // somewhere to go back to? (a page opened straight from the address bar has no history)
    this.canBack = [403, 404, 405].includes(this.status) && window.history.length > 1;
    // "Session Expired" on a form that was open for a long time: get a fresh security cookie and reload once, so the person lands on a
    // working page instead of having to refresh by hand. The stamp stops it from looping if the reload fails the same way.
    if (this.status === 419) {
      let recent = false;
      try { recent = Date.now() - Number(sessionStorage.getItem('wims-419-at') || 0) < 30000; sessionStorage.setItem('wims-419-at', String(Date.now())); } catch (e) { /* no storage */ }
      if (!recent) {
        this.recovering = true;
        const go = () => setTimeout(() => window.location.reload(), 500);
        window.axios.get('/sanctum/csrf-cookie').then(go, go);
      }
    }
  },

  methods: {
    goBack() {
      const here = window.location.href;
      window.history.back();
      // if nothing happened (the previous entry was not a page of this site) fall back to home
      setTimeout(() => { if (window.location.href === here) window.location.href = this.homeHref; }, 700);
    },
  },

  computed: {
    // Whoever's viewing this page — authenticated or not — always lands
    // somewhere real: their dashboard if logged in, the login screen
    // otherwise. Never a bare URL guess that could itself 404 or bounce
    // an unauthenticated visitor through a broken redirect chain.
    homeHref() {
      return this.$page.props.auth?.user ? this.route('dashboard') : this.route('login');
    },
    versionLabel() {
      return this.$page.props.settings?.version_label || 'V2.0';
    },
    copy() {
      return {
        400: {
          title: this.$t('Bad Request'),
          description: this.$t("We couldn't understand that request. Please try again."),
        },
        401: {
          title: this.$t('Not Logged In'),
          description: this.$t('You need to log in to access this page.'),
        },
        403: {
          title: this.$t('Access Forbidden'),
          description: this.$t("Sorry, you don't have permission to view this page."),
        },
        404: {
          title: this.$t('Page Not Found'),
          description: this.$t("The page you're looking for has been moved, renamed, or doesn't exist."),
        },
        405: {
          title: this.$t('Method Not Allowed'),
          description: this.$t("That action isn't supported on this page."),
        },
        419: {
          title: this.$t('Session Expired'),
          description: this.recovering ? this.$t('Your session expired for security. Refreshing the page for you...') : this.$t('Your session expired for security. Please refresh and try again.'),
        },
        429: {
          title: this.$t('Too Many Requests'),
          description: this.$t("You've made too many requests. Please wait a moment and try again."),
        },
        500: {
          title: this.$t('Server Error'),
          description: this.$t('Something went wrong on our end. Our team has been notified.'),
        },
        503: {
          title: this.$t('Under Maintenance'),
          description: this.$t("We're making some improvements. Please check back shortly."),
        },
      }[this.status] || {
        title: this.$t('Something Went Wrong'),
        description: this.$t('An unexpected error occurred. Please try again.'),
      };
    },
    title() {
      return this.copy.title;
    },
    description() {
      return this.copy.description;
    },
  },
};
</script>

<style scoped>
.err-shell {
  position: relative;
  overflow: hidden;
  background:
    radial-gradient(1200px 430px at -10% -15%, rgba(255, 214, 170, 0.55), rgba(255, 214, 170, 0) 56%),
    radial-gradient(900px 430px at 110% 8%, rgba(98, 148, 255, 0.35), rgba(98, 148, 255, 0) 54%),
    linear-gradient(145deg, #fff5e8 0%, #dbe8ff 45%, #eff8ff 100%);
}

.err-orb {
  position: absolute;
  border-radius: 999px;
  pointer-events: none;
}

.err-orb-a {
  width: 320px;
  height: 320px;
  top: -130px;
  left: -120px;
  background: radial-gradient(circle at 35% 35%, rgba(255, 255, 255, 0.92), rgba(251, 191, 36, 0.2) 48%, rgba(251, 191, 36, 0) 74%);
  animation: err-float-a 16s ease-in-out infinite;
}

.err-orb-b {
  width: 280px;
  height: 280px;
  right: -90px;
  top: 26%;
  background: radial-gradient(circle at 45% 35%, rgba(199, 237, 255, 0.72), rgba(64, 116, 255, 0.16) 52%, rgba(64, 116, 255, 0) 76%);
  animation: err-float-b 20s ease-in-out infinite;
}

.err-orb-c {
  width: 230px;
  height: 230px;
  left: 38%;
  bottom: -130px;
  background: radial-gradient(circle at 45% 45%, rgba(255, 255, 255, 0.75), rgba(248, 113, 113, 0.14) 48%, rgba(248, 113, 113, 0) 74%);
  animation: err-float-c 18s ease-in-out infinite;
}

@keyframes err-float-a {
  0%, 100% { transform: translate3d(0, 0, 0); }
  50% { transform: translate3d(22px, 16px, 0); }
}
@keyframes err-float-b {
  0%, 100% { transform: translate3d(0, 0, 0); }
  50% { transform: translate3d(-18px, 14px, 0); }
}
@keyframes err-float-c {
  0%, 100% { transform: translate3d(0, 0, 0); }
  50% { transform: translate3d(16px, -14px, 0); }
}

.err-glow-wrap {
  position: relative;
}

.err-glow-halo {
  position: absolute;
  inset: -18px;
  z-index: -1;
  border-radius: 40px;
  background: linear-gradient(135deg, #fbbf24, #f97316, #ef4444, #f97316, #fbbf24);
  background-size: 200% 200%;
  filter: blur(26px);
  opacity: 0.45;
  animation: err-glow-pan 6s ease-in-out infinite;
}

@keyframes err-glow-pan {
  0%, 100% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
}

.err-card {
  position: relative;
  z-index: 1;
  border-radius: 24px;
  border: 1px solid rgba(255, 255, 255, 0.7);
  background: linear-gradient(148deg, rgba(255, 255, 255, 0.62), rgba(255, 244, 230, 0.42));
  backdrop-filter: blur(22px) saturate(165%);
  -webkit-backdrop-filter: blur(22px) saturate(165%);
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.78),
    0 30px 60px rgba(120, 72, 24, 0.18);
}

.err-card-in {
  animation: err-card-in 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
}

@keyframes err-card-in {
  0% { opacity: 0; transform: translateY(18px) scale(0.98); }
  100% { opacity: 1; transform: translateY(0) scale(1); }
}

.err-logo {
  animation: err-logo-float 5s ease-in-out infinite;
}

@keyframes err-logo-float {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-4px); }
}

.err-ground {
  fill: rgba(15, 23, 42, 0.08);
}

.err-cone {
  transform-origin: center bottom;
  animation: err-cone-sway 4.5s ease-in-out infinite;
}
.err-cone-left {
  animation-delay: 0s;
}
.err-cone-right {
  animation-delay: 0.4s;
}

@keyframes err-cone-sway {
  0%, 100% { transform: rotate(0deg); }
  50% { transform: rotate(-2.5deg); }
}

.err-sign {
  transform-origin: 250px 124px;
  animation: err-sign-bob 3.6s ease-in-out infinite;
}

@keyframes err-sign-bob {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-6px) rotate(1.5deg); }
}

.err-sign-text {
  font-size: 44px;
  font-weight: 800;
  fill: #7c2d12;
  font-family: 'WIMS Avenir', 'Avenir Next', Avenir, Montserrat, 'Segoe UI', Arial, sans-serif;
}

.err-beacon {
  animation: err-beacon-blink 1.6s ease-in-out infinite;
}
.err-beacon-glow {
  animation: err-beacon-blink 1.6s ease-in-out infinite;
}
.err-beacon-2,
.err-beacon-glow-2 {
  animation-delay: 0.8s;
}

@keyframes err-beacon-blink {
  0%, 100% { opacity: 0.35; }
  50% { opacity: 1; }
}

.err-oops {
  margin-top: 1.5rem;
  font-size: 1.75rem;
  font-weight: 800;
  letter-spacing: -0.01em;
  color: #7c2d12;
}

.err-title {
  margin-top: 0.35rem;
  font-size: 1.05rem;
  font-weight: 700;
  color: #1e293b;
}

.err-description {
  margin-top: 0.5rem;
  font-size: 0.9rem;
  color: #64748b;
  max-width: 26rem;
  margin-left: auto;
  margin-right: auto;
}

.err-actions { display: flex; flex-direction: column; align-items: center; gap: 0.7rem; }
.err-sub-link { font-size: 0.82rem; font-weight: 700; color: #64748b; text-decoration: underline; text-underline-offset: 3px; }
.err-sub-link:hover { color: #1e293b; }
button.err-home-btn { border: 0; cursor: pointer; font-family: inherit; }
.err-home-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 0.75rem 1.75rem;
  border-radius: 9999px;
  font-weight: 700;
  font-size: 0.9rem;
  color: #ffffff;
  background: linear-gradient(135deg, #2563eb, #1d4ed8);
  box-shadow: 0 12px 28px -8px rgba(37, 99, 235, 0.55);
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.err-home-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 16px 32px -8px rgba(37, 99, 235, 0.65);
}

.err-home-btn:active {
  transform: translateY(0);
}

.err-footer {
  margin-top: 1.5rem;
  text-align: center;
  font-size: 0.72rem;
  font-weight: 600;
  line-height: 1.5;
  color: rgba(100, 71, 40, 0.68);
}

@media (max-width: 400px) {
  .err-oops {
    font-size: 1.5rem;
  }
}

@media (prefers-reduced-motion: reduce) {
  .err-orb,
  .err-card-in,
  .err-logo,
  .err-cone,
  .err-sign,
  .err-beacon,
  .err-beacon-glow,
  .err-glow-halo {
    animation: none;
  }
}
</style>

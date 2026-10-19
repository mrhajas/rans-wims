<template>
  <div class="sky" :class="'sky--' + scene" aria-hidden="true">
    <svg viewBox="0 0 120 120">
      <defs>
        <radialGradient id="sky-sun" cx="40%" cy="35%"><stop offset="0" stop-color="#fffbe0" /><stop offset="0.55" stop-color="#fde047" /><stop offset="1" stop-color="#f59e0b" /></radialGradient>
        <radialGradient id="sky-sun-low" cx="45%" cy="40%"><stop offset="0" stop-color="#fff1c2" /><stop offset="0.6" stop-color="#fb923c" /><stop offset="1" stop-color="#ea580c" /></radialGradient>
        <radialGradient id="sky-moon" cx="40%" cy="35%"><stop offset="0" stop-color="#fff7c2" /><stop offset="1" stop-color="#facc15" /></radialGradient>
        <linearGradient id="sky-cloud" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffffff" /><stop offset="1" stop-color="#cfe3f7" /></linearGradient>
        <linearGradient id="sky-cloud-warm" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff1e0" /><stop offset="1" stop-color="#f9a56a" /></linearGradient>
        <linearGradient id="sky-glow" x1="0" y1="1" x2="0" y2="0"><stop offset="0" stop-color="#fb923c" stop-opacity="0.65" /><stop offset="1" stop-color="#fde68a" stop-opacity="0" /></linearGradient>
        <clipPath id="sky-above"><rect x="0" y="0" width="120" height="84" /></clipPath>
      </defs>

      <!-- Fajr: a last crescent, the first light and a sun about to rise -->
      <template v-if="scene === 'fajr'">
        <ellipse cx="60" cy="86" rx="52" ry="30" fill="url(#sky-glow)" clip-path="url(#sky-above)" />
        <path class="sky-moon-s" d="M40 16a20 20 0 1 0 10.4 30.4A16.6 16.6 0 0 1 40 16z" fill="url(#sky-moon)" />
        <g class="sky-tw"><circle cx="82" cy="24" r="1.8" fill="#fde68a" /><circle cx="96" cy="40" r="1.3" fill="#fde68a" /><circle cx="22" cy="52" r="1.2" fill="#fde68a" /></g>
        <g clip-path="url(#sky-above)"><g class="sky-rays slow" style="transform-origin: 60px 84px"><path v-for="n in 7" :key="n" :transform="'rotate(' + (-80 + (n - 1) * 26.7) + ' 60 84)'" d="M58.6 44h2.8l1 12h-4.8z" fill="#fbbf24" opacity="0.8" /></g><circle cx="60" cy="84" r="22" fill="url(#sky-sun-low)" /></g>
        <path d="M8 84h104" stroke="#f59e0b" stroke-width="2.4" stroke-linecap="round" opacity="0.7" />
      </template>

      <!-- Dhuhr: high sun, mostly clear, one small cloud -->
      <template v-else-if="scene === 'dhuhr'">
        <g class="sky-rays" style="transform-origin: 56px 52px"><path v-for="n in 12" :key="n" :transform="'rotate(' + (n * 30) + ' 56 52)'" d="M54.4 6h3.2l1.6 13H52.8z" fill="#fbbf24" /></g>
        <circle cx="56" cy="52" r="26" fill="url(#sky-sun)" />
        <circle cx="48" cy="44" r="7" fill="#fff" opacity="0.35" />
        <g class="sky-cloud-d"><path d="M70 96a11 11 0 0 1 2-21.8 14 14 0 0 1 26.4 3.6A9.6 9.6 0 0 1 98 96z" fill="url(#sky-cloud)" stroke="#9db8d6" stroke-width="1.2" stroke-linejoin="round" /></g>
      </template>

      <!-- Asr: the sun getting lower, half behind a cloud -->
      <template v-else-if="scene === 'asr'">
        <g class="sky-rays" style="transform-origin: 46px 46px"><path v-for="n in 10" :key="n" :transform="'rotate(' + (n * 36) + ' 46 46)'" d="M44.6 6h2.8l1.4 11H43.2z" fill="#f59e0b" opacity="0.9" /></g>
        <circle cx="46" cy="46" r="24" fill="url(#sky-sun)" />
        <circle cx="39" cy="39" r="6" fill="#fff" opacity="0.3" />
        <g class="sky-cloud-d"><path d="M34 100a14 14 0 0 1 3-27.7 18 18 0 0 1 34-4.6A12.5 12.5 0 0 1 72 100z" fill="url(#sky-cloud)" stroke="#9db8d6" stroke-width="1.2" stroke-linejoin="round" /></g>
        <g class="sky-cloud-d slow"><path d="M78 74a8 8 0 0 1 1.5-15.8A10.5 10.5 0 0 1 99.600 60 7.600 7.600 0 0 1 98 74z" fill="url(#sky-cloud)" stroke="#9db8d6" stroke-width="1.1" stroke-linejoin="round" opacity="0.95" /></g>
      </template>

      <!-- Maghrib: the sun setting behind evening clouds -->
      <template v-else-if="scene === 'maghrib'">
        <ellipse cx="60" cy="82" rx="54" ry="34" fill="url(#sky-glow)" clip-path="url(#sky-above)" />
        <g clip-path="url(#sky-above)"><g class="sky-rays slow" style="transform-origin: 60px 84px"><path v-for="n in 9" :key="n" :transform="'rotate(' + (-84 + (n - 1) * 21) + ' 60 84)'" d="M58.6 38h2.8l1.2 14h-5.2z" fill="#fb923c" opacity="0.8" /></g><circle cx="60" cy="84" r="27" fill="url(#sky-sun-low)" /></g>
        <path d="M6 84h108" stroke="#ea580c" stroke-width="2.4" stroke-linecap="round" opacity="0.7" />
        <g class="sky-cloud-d"><path d="M10 72a9 9 0 0 1 1.5-17.9A12 12 0 0 1 35 56.400 8.200 8.200 0 0 1 34 72z" fill="url(#sky-cloud-warm)" stroke="#e8935a" stroke-width="1.1" stroke-linejoin="round" /></g>
        <g class="sky-cloud-d slow"><path d="M70 66a11 11 0 0 1 2-21.800 14 14 0 0 1 26.400 3.600A9.600 9.600 0 0 1 98 66z" fill="url(#sky-cloud-warm)" stroke="#e8935a" stroke-width="1.2" stroke-linejoin="round" /></g>
        <g class="sky-cloud-d"><path d="M26 104a10 10 0 0 1 1.800-19.800 13 13 0 0 1 24.600 3.800A9 9 0 0 1 52 104z" fill="url(#sky-cloud-warm)" stroke="#e8935a" stroke-width="1.1" stroke-linejoin="round" opacity="0.95" /></g>
      </template>

      <!-- Isha: the night, a crescent, stars and a thin cloud -->
      <template v-else>
        <path class="sky-moon-s" d="M82 14a46 46 0 1 0 24 70A38 38 0 0 1 82 14z" fill="url(#sky-moon)" />
        <path class="sky-star" d="M92 38l3.4 7.4 8 .9-6 5.4 1.7 7.9-7.1-4-7.1 4 1.7-7.9-6-5.4 8-.9z" fill="#fde68a" />
        <g class="sky-tw"><circle cx="20" cy="22" r="1.8" fill="#fde68a" /><circle cx="34" cy="10" r="1.2" fill="#fde68a" /><circle cx="14" cy="50" r="1.3" fill="#fde68a" /></g>
        <g class="sky-cloud-d"><path d="M52 104a8 8 0 0 1 1.400-15.900A10.500 10.500 0 0 1 73.500 90 7.500 7.500 0 0 1 72 104z" fill="url(#sky-cloud)" stroke="#9db8d6" stroke-width="1.1" stroke-linejoin="round" opacity="0.9" /></g>
      </template>
    </svg>
  </div>
</template>

<script>
const SCENES = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'];

// Which picture goes with which prayer: dawn, bright midday, a lowering afternoon sun, a cloudy sunset, and the night.
export default {
  props: { prayer: { type: String, default: '' } },
  computed: {
    scene() {
      const k = String(this.prayer || '').toLowerCase();
      return SCENES.includes(k) ? k : 'isha';
    },
  },
};
</script>

<style>
.sky { position: relative; width: 84px; height: 84px; margin: 0 auto 0.6rem; animation: sky-float 4s ease-in-out infinite; }
.sky svg { width: 100%; height: 100%; overflow: visible; }
.sky--fajr, .sky--maghrib { filter: drop-shadow(0 6px 14px rgba(249, 115, 22, 0.5)); }
.sky--dhuhr, .sky--asr { filter: drop-shadow(0 6px 14px rgba(245, 158, 11, 0.55)); }
.sky--isha { filter: drop-shadow(0 6px 14px rgba(245, 158, 11, 0.5)); }
.sky .sky-rays { animation: sky-spin 40s linear infinite; } .sky .sky-rays.slow { animation: sky-pulse 4s ease-in-out infinite; }
.sky .sky-cloud-d { animation: sky-drift 6s ease-in-out infinite; } .sky .sky-cloud-d.slow { animation-duration: 9s; animation-direction: reverse; }
.sky .sky-tw circle { animation: sky-twinkle 2.6s ease-in-out infinite; } .sky .sky-tw circle:nth-child(2) { animation-delay: 0.9s; } .sky .sky-tw circle:nth-child(3) { animation-delay: 1.7s; }
.sky .sky-star { transform-origin: 92px 48px; animation: sky-spin 9s linear infinite; }
@keyframes sky-float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }
@keyframes sky-spin { to { transform: rotate(360deg); } }
@keyframes sky-pulse { 0%, 100% { opacity: 0.7; transform: scale(0.96); } 50% { opacity: 1; transform: scale(1.04); } }
@keyframes sky-drift { 0%, 100% { transform: translateX(-2.5px); } 50% { transform: translateX(3px); } }
@keyframes sky-twinkle { 0%, 100% { opacity: 0.25; } 50% { opacity: 1; } }
@media (prefers-reduced-motion: reduce) { .sky, .sky .sky-rays, .sky .sky-cloud-d, .sky .sky-tw circle, .sky .sky-star { animation: none; } }
</style>

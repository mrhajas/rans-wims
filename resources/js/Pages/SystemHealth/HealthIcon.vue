<template>
  <span class="hi" :class="['hi-' + type, 'is-' + tone]" aria-hidden="true">
    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
      <!-- connection: waves that leave a dot and fade as they travel -->
      <template v-if="type === 'connection'">
        <circle cx="24" cy="36" r="2.6" fill="currentColor" stroke="none" />
        <path class="w w1" d="M16 30a11 11 0 0 1 16 0" /><path class="w w2" d="M10.5 24.5a19 19 0 0 1 27 0" /><path class="w w3" d="M5 19a27 27 0 0 1 38 0" />
      </template>
      <!-- speed: a dial with a needle that sweeps -->
      <template v-else-if="type === 'speed'">
        <path d="M7 34a17 17 0 0 1 34 0" /><path d="M12 28l2 1M24 17v3M36 28l-2 1" opacity=".55" />
        <g class="needle"><path d="M24 34l8-11" stroke-width="3" /><circle cx="24" cy="34" r="3" fill="currentColor" stroke="none" /></g>
      </template>
      <!-- system: bars of an equaliser that never sit still -->
      <template v-else-if="type === 'system'">
        <rect class="b b1" x="8" y="14" width="6" height="22" rx="2" fill="currentColor" stroke="none" /><rect class="b b2" x="18" y="14" width="6" height="22" rx="2" fill="currentColor" stroke="none" />
        <rect class="b b3" x="28" y="14" width="6" height="22" rx="2" fill="currentColor" stroke="none" /><rect class="b b4" x="38" y="14" width="4" height="22" rx="2" fill="currentColor" stroke="none" />
      </template>
      <!-- database: stacked discs with a travelling highlight -->
      <template v-else-if="type === 'database'">
        <ellipse cx="24" cy="12" rx="14" ry="5" /><path d="M10 12v10c0 2.8 6.3 5 14 5s14-2.2 14-5V12" /><path d="M10 22v10c0 2.8 6.3 5 14 5s14-2.2 14-5V22" />
        <path class="glint" d="M15 14.5c2.5 1 5.5 1.4 9 1.4" stroke-width="2" />
      </template>
      <!-- disk: a platter that turns -->
      <template v-else-if="type === 'disk'">
        <circle cx="24" cy="24" r="16" /><g class="spin"><circle cx="24" cy="24" r="9" opacity=".5" /><path d="M24 15v4M33 24h-4" stroke-width="3" /></g><circle cx="24" cy="24" r="2.4" fill="currentColor" stroke="none" />
      </template>
      <!-- sms: a battery whose bolt flashes -->
      <template v-else-if="type === 'sms'">
        <rect x="6" y="14" width="32" height="20" rx="4" /><path d="M41 21v6" stroke-width="3.4" /><path class="bolt" d="M24 17l-6 8h5l-2 7 8-10h-5z" fill="currentColor" stroke="none" />
      </template>
      <!-- cloud drive: a cloud with an arrow that floats up -->
      <template v-else-if="type === 'drive'">
        <path d="M14 34a8 8 0 0 1-.8-15.9A11 11 0 0 1 34.6 17 8.5 8.5 0 0 1 35 34z" /><path class="arrow" d="M24 30V20m-4 4l4-4 4 4" stroke-width="2.6" />
      </template>
      <!-- backups: a shield with a pulse ring -->
      <template v-else-if="type === 'backups'">
        <circle class="ring" cx="24" cy="24" r="14" opacity=".35" /><path d="M24 6l14 5v11c0 9-6 15-14 19C16 37 10 31 10 22V11z" /><path class="tick" d="M17 24l5 5 9-10" stroke-width="3" />
      </template>
      <!-- safety: a radar sweep -->
      <template v-else-if="type === 'safety'">
        <circle cx="24" cy="24" r="17" /><circle cx="24" cy="24" r="9" opacity=".55" /><g class="sweep"><path d="M24 24L24 7" stroke-width="3" /><path d="M24 24L12 12" opacity=".25" stroke-width="7" /></g><circle class="ping" cx="33" cy="17" r="2.4" fill="currentColor" stroke="none" />
      </template>
      <!-- licence: a badge with a shine -->
      <template v-else-if="type === 'licence'">
        <circle cx="24" cy="21" r="12" /><path d="M18 31l-3 11 9-5 9 5-3-11" /><path class="star" d="M24 14l2 4.4 4.8.6-3.5 3.3.9 4.7-4.2-2.3-4.2 2.3.9-4.7-3.5-3.3 4.8-.6z" fill="currentColor" stroke="none" />
      </template>
      <!-- you: a person with a pin that bobs -->
      <template v-else>
        <circle cx="19" cy="17" r="6" /><path d="M7 40c0-7 5-11 12-11" /><g class="pin"><path d="M35 40s-8-6.5-8-13a8 8 0 0 1 16 0c0 6.5-8 13-8 13z" /><circle cx="35" cy="27" r="3" fill="currentColor" stroke="none" /></g>
      </template>
    </svg>
  </span>
</template>

<script>
// The little animated pictures of System Health. Each one moves in its own way (waves leave, a needle sweeps, bars dance, a platter turns...) and takes the colour of
// the rating of its section. All motion stops for people who ask their device to reduce motion.
export default { props: { type: { type: String, default: 'you' }, tone: { type: String, default: 'good' } } };
</script>

<style scoped>
.hi { display: inline-flex; width: 2.7rem; height: 2.7rem; align-items: center; justify-content: center; border-radius: 0.9rem; flex: none; position: relative; overflow: hidden; color: var(--tone); background: color-mix(in srgb, var(--tone) 14%, transparent); }
.hi::after { content: ''; position: absolute; inset: 0; background: linear-gradient(115deg, transparent 35%, rgba(255, 255, 255, 0.55) 50%, transparent 65%); transform: translateX(-120%); animation: hi-shine 4.5s ease-in-out infinite; }
.hi svg { width: 74%; height: 74%; overflow: visible; }
.is-excellent { --tone: #059669; } .is-very_good { --tone: #16a34a; } .is-good { --tone: #2563eb; } .is-fair { --tone: #d97706; } .is-bad { --tone: #dc2626; } .is-unknown { --tone: #64748b; }
.w { animation: hi-wave 2.4s ease-out infinite; opacity: 0; } .w1 { animation-delay: 0s; } .w2 { animation-delay: 0.4s; } .w3 { animation-delay: 0.8s; }
.needle { transform-origin: 24px 34px; animation: hi-needle 3.2s ease-in-out infinite; }
.b { transform-box: fill-box; transform-origin: bottom; animation: hi-bar 1.1s ease-in-out infinite; } .b2 { animation-delay: 0.18s; animation-duration: 1.4s; } .b3 { animation-delay: 0.36s; animation-duration: 0.9s; } .b4 { animation-delay: 0.1s; animation-duration: 1.7s; }
.glint { stroke: rgba(255, 255, 255, 0.9); stroke-dasharray: 6 40; animation: hi-glint 2.6s linear infinite; }
.spin { transform-origin: 24px 24px; animation: hi-spin 3s linear infinite; }
.bolt { transform-origin: 24px 24px; animation: hi-bolt 1.6s ease-in-out infinite; }
.arrow { animation: hi-float 2s ease-in-out infinite; }
.ring { transform-origin: 24px 24px; animation: hi-ring 2.4s ease-out infinite; } .tick { stroke-dasharray: 30; animation: hi-draw 3s ease-in-out infinite; }
.sweep { transform-origin: 24px 24px; animation: hi-spin 3.4s linear infinite; } .ping { animation: hi-blink 3.4s linear infinite; }
.star { transform-origin: 24px 21px; animation: hi-star 3.2s ease-in-out infinite; }
.pin { animation: hi-bob 1.8s ease-in-out infinite; }
@keyframes hi-shine { 0%, 55% { transform: translateX(-120%); } 85%, 100% { transform: translateX(120%); } }
@keyframes hi-wave { 0% { opacity: 0; transform: translateY(2px); } 30% { opacity: 1; } 100% { opacity: 0; transform: translateY(-2px); } }
@keyframes hi-needle { 0%, 100% { transform: rotate(-70deg); } 50% { transform: rotate(60deg); } }
@keyframes hi-bar { 0%, 100% { transform: scaleY(0.3); } 50% { transform: scaleY(1); } }
@keyframes hi-glint { to { stroke-dashoffset: -92; } }
@keyframes hi-spin { to { transform: rotate(360deg); } }
@keyframes hi-bolt { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.45; transform: scale(0.88); } }
@keyframes hi-float { 0%, 100% { transform: translateY(2px); opacity: 0.6; } 50% { transform: translateY(-3px); opacity: 1; } }
@keyframes hi-ring { 0% { transform: scale(0.8); opacity: 0.6; } 100% { transform: scale(1.35); opacity: 0; } }
@keyframes hi-draw { 0% { stroke-dashoffset: 30; } 40%, 80% { stroke-dashoffset: 0; } 100% { stroke-dashoffset: -30; } }
@keyframes hi-blink { 0%, 55% { opacity: 0; } 60% { opacity: 1; } 100% { opacity: 0; } }
@keyframes hi-star { 0%, 100% { transform: scale(1) rotate(0); } 50% { transform: scale(1.18) rotate(12deg); } }
@keyframes hi-bob { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-3px); } }
@media (prefers-reduced-motion: reduce) { .hi *, .hi::after { animation: none !important; } .w { opacity: 0.7; } .b { transform: scaleY(0.7); } }
</style>

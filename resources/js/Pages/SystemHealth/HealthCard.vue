<template>
  <article :id="'sh-' + id" ref="root" class="hc" :class="['is-' + rating, { 'is-wide': wide }, enter]" :style="{ '--tone': toneColor }">
    <waves />
    <header class="hc-head">
      <health-icon :type="icon" :tone="rating" />
      <div class="hc-title"><h3>{{ title }}</h3><small v-if="subtitle">{{ subtitle }}</small></div>
      <span v-if="!plain" class="hc-badge" :class="'is-' + rating"><i></i>{{ ratingLabel }}<b v-if="section && section.score !== null && section.score !== undefined">{{ section.score }}</b></span>
    </header>
    <div class="hc-body"><slot /></div>
    <footer v-if="!plain" class="hc-foot">
      <div class="hc-foot-t"><icons :name="actions.length ? 'adjustments' : 'check-double'" class="w-3.5 h-3.5" />{{ actions.length ? $t('What can be done') : rating === 'unknown' ? $t('Not rated') : $t('Nothing to do. All is well here.') }}</div>
      <p v-if="section && section.note" class="hc-note">{{ section.note }}</p>
      <ul v-if="actions.length" class="hc-actions">
        <li v-for="(a, i) in actions" :key="i">
          <Link v-if="a.route" :href="route(a.route)" class="hc-link">{{ a.text }}<icons name="forward" class="w-3 h-3" /></Link>
          <button v-else-if="a.action" type="button" class="hc-link is-btn" @click="$emit('action', a.action)"><icons name="sparkles" class="w-3.5 h-3.5" />{{ a.text }}</button>
          <span v-else>{{ a.text }}</span>
        </li>
      </ul>
    </footer>
  </article>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import HealthIcon from './HealthIcon.vue';
import Waves from './Waves.vue';

// One part of the system: its animated picture, its name, its health rating in words (Excellent, Very Good, Good, Fair, Bad) and what can be done about it.
export default {
  components: { Link, HealthIcon, Waves },
  props: { id: String, title: String, subtitle: String, icon: String, section: Object, wide: Boolean, plain: Boolean },
  emits: ['action'],
  data() { return { enter: '', io: null, last: null, t: null }; },
  // Scrolling down, a card rises into place; scrolling back up, it settles down into place from above. The card is readable at every moment (it only starts a little
  // low or high and a little faint), so a tab that never reports scrolling still shows everything.
  mounted() {
    if (typeof IntersectionObserver === 'undefined' || !this.$refs.root) return;
    this.io = new IntersectionObserver(entries => {
      entries.forEach(e => {
        if (e.isIntersecting && this.last === false) {
          this.enter = e.boundingClientRect.top > window.innerHeight * 0.35 ? 'enter-up' : 'enter-down';
          clearTimeout(this.t);
          this.t = setTimeout(() => { this.enter = ''; }, 900);
        }
        this.last = e.isIntersecting;
      });
    }, { threshold: 0.12 });
    this.io.observe(this.$refs.root);
  },
  beforeUnmount() { if (this.io) this.io.disconnect(); clearTimeout(this.t); },
  computed: {
    rating() { return (this.section && this.section.rating) || 'unknown'; },
    actions() { return (this.section && this.section.actions) || []; },
    ratingLabel() { return { excellent: this.$t('Excellent'), very_good: this.$t('Very Good'), good: this.$t('Good'), fair: this.$t('Fair'), bad: this.$t('Bad'), unknown: this.$t('Not rated') }[this.rating]; },
    toneColor() { return { excellent: '#059669', very_good: '#16a34a', good: '#2563eb', fair: '#d97706', bad: '#dc2626', unknown: '#64748b' }[this.rating]; },
  },
};
</script>

<style scoped>
.hc { position: relative; display: flex; flex-direction: column; min-width: 0; overflow: hidden; border-radius: 1.1rem; border: 1px solid var(--line, #e5e7eb); background: var(--card, #fff); color: var(--ink, #0f172a); box-shadow: 0 10px 28px -20px rgba(15, 23, 42, 0.4); transition: transform 0.25s, box-shadow 0.25s; }
.hc::before { content: ''; position: absolute; left: 0; right: 0; top: 0; height: 3px; background: linear-gradient(90deg, transparent, var(--tone), transparent); background-size: 200% 100%; animation: hc-run 4s linear infinite; }
.hc::after { content: ''; position: absolute; top: 0; bottom: 0; width: 40%; left: -60%; background: linear-gradient(105deg, transparent, rgba(255, 255, 255, 0.28), transparent); transform: skewX(-18deg); animation: hc-shine 7s ease-in-out infinite; pointer-events: none; }
.hc:hover { transform: translateY(-2px); box-shadow: 0 16px 34px -18px color-mix(in srgb, var(--tone) 55%, transparent); }
.is-wide { grid-column: 1 / -1; }
.enter-up { animation: hc-rise 0.75s cubic-bezier(0.2, 0.85, 0.25, 1) both; }
.enter-down { animation: hc-drop 0.75s cubic-bezier(0.2, 0.85, 0.25, 1) both; }
@keyframes hc-rise { from { opacity: 0.3; transform: translateY(34px) scale(0.97); } to { opacity: 1; transform: none; } }
@keyframes hc-drop { from { opacity: 0.3; transform: translateY(-34px) scale(0.97); } to { opacity: 1; transform: none; } }
.hc-head { display: flex; align-items: center; gap: 0.75rem; padding: 0.95rem 1.05rem 0.4rem; position: relative; }
.hc-title { min-width: 0; flex: 1; } .hc-title h3 { font-size: 0.82rem; font-weight: 900; letter-spacing: 0.04em; text-transform: uppercase; } .hc-title small { display: block; font-size: 0.72rem; color: var(--mute, #64748b); font-weight: 600; }
.hc-badge { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.22rem 0.65rem; border-radius: 999px; font-size: 0.7rem; font-weight: 800; white-space: nowrap; color: var(--tone); background: color-mix(in srgb, var(--tone) 13%, transparent); }
.hc-badge i { width: 0.5rem; height: 0.5rem; border-radius: 999px; background: var(--tone); animation: hc-pulse 2s infinite; } .hc-badge b { margin-left: 0.15rem; padding-left: 0.4rem; border-left: 1px solid color-mix(in srgb, var(--tone) 35%, transparent); font-variant-numeric: tabular-nums; }
.hc-badge.is-bad i { animation-duration: 0.9s; }
.hc-body { padding: 0.4rem 1.05rem 0.8rem; flex: 1; position: relative; }
.hc-body:last-child { padding-bottom: 1.6rem; }
.hc-foot { position: relative; padding: 0.65rem 1.05rem 1.5rem; border-top: 1px dashed var(--line, #e5e7eb); background: color-mix(in srgb, var(--tone) 4%, transparent); }
.hc-foot-t { display: flex; align-items: center; gap: 0.4rem; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; color: var(--tone); }
.hc-note { font-size: 0.72rem; color: var(--mute, #64748b); margin: 0.3rem 0 0; }
.hc-actions { list-style: none; margin: 0.4rem 0 0; padding: 0; display: grid; gap: 0.3rem; font-size: 0.78rem; line-height: 1.35; }
.hc-actions li { padding-left: 0.9rem; position: relative; } .hc-actions li::before { content: ''; position: absolute; left: 0; top: 0.5rem; width: 0.35rem; height: 0.35rem; border-radius: 999px; background: var(--tone); }
.hc-link { display: inline-flex; align-items: center; gap: 0.3rem; color: var(--tone); font-weight: 800; text-decoration: none; text-align: left; } .hc-link:hover { text-decoration: underline; } .hc-link.is-btn { padding: 0; }
@keyframes hc-run { to { background-position: -200% 0; } }
@keyframes hc-shine { 0%, 62% { left: -60%; } 100% { left: 130%; } }
@keyframes hc-pulse { 0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--tone) 55%, transparent); } 70% { box-shadow: 0 0 0 6px transparent; } 100% { box-shadow: 0 0 0 0 transparent; } }
@media (prefers-reduced-motion: reduce) { .hc::before, .hc::after, .hc-badge i, .enter-up, .enter-down { animation: none; } .hc { transition: none; } }
</style>

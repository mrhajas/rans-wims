<template>
  <article
    class="fcd"
    :class="[{ 'is-off': !feature.is_enabled, 'is-held': feature.is_enabled && !feature.effective, 'is-compact': compact, 'is-focus': focused }, flash ? 'flash-' + flash : '']"
    :data-fk="feature.key"
    :style="{ '--i': Math.min(index, 14) }"
  >
    <div class="fcd-top">
      <span class="fcd-ico"><icons :name="feature.icon || 'toggle'" class="w-5 h-5" /></span>
      <div class="fcd-name" v-html="hl(feature.name)"></div>
      <span class="fcd-state" :class="chip.cls"><span class="fcd-dot"></span>{{ chip.text }}</span>
    </div>
    <p class="fcd-desc" v-html="hl(feature.description)"></p>
    <p v-if="note" class="fcd-note"><icons name="link" class="w-3 h-3" />{{ note }}</p>
    <div class="fcd-foot">
      <feature-switch :state="feature.is_enabled ? 'on' : 'off'" :label="feature.is_enabled ? $t('Switch off') + ' ' + feature.name : $t('Switch on') + ' ' + feature.name" :disabled="busy" @toggle="$emit('toggle', feature)" />
      <span class="fcd-foot-txt">{{ feature.is_enabled ? $t('On') : $t('Off') }}</span>
    </div>
  </article>
</template>

<script>
import FeatureSwitch from './FeatureSwitch.vue';
import { highlightText } from '@/Core/highlightText';

export default {
  components: { FeatureSwitch },
  props: {
    feature: { type: Object, required: true },
    query: { type: String, default: '' },
    compact: Boolean,
    index: { type: Number, default: 0 },
    note: { type: String, default: '' },
    focused: Boolean,
    busy: Boolean,
  },
  emits: ['toggle'],
  data() { return { flash: '' }; },
  computed: {
    chip() {
      const f = this.feature;
      if (!f.is_enabled) return { cls: 'is-off', text: this.$t('DISABLED') };
      if (!f.effective) return { cls: 'is-held', text: this.$t('HELD OFF') };
      return { cls: 'is-on', text: this.$t('ACTIVE') };
    },
  },
  watch: {
    'feature.is_enabled'(now, was) {
      if (now === was) return;
      clearTimeout(this.t);
      this.flash = now ? 'on' : 'off';
      this.t = setTimeout(() => { this.flash = ''; }, 1100);
    },
  },
  beforeUnmount() { clearTimeout(this.t); },
  methods: { hl(t) { return highlightText(t, this.query); } },
};
</script>

<style scoped>
.fcd { position: relative; display: flex; flex-direction: column; padding: 16px; border-radius: 20px; overflow: hidden; background: var(--lg-card); border: 1px solid color-mix(in srgb, var(--c, #2563eb) 18%, var(--lg-line)); box-shadow: 0 14px 26px -20px color-mix(in srgb, var(--c2, #1d4ed8) 70%, transparent), 0 1px 2px rgba(15, 37, 64, 0.06); transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.5s ease, border-color 0.5s ease, filter 0.5s ease; animation: fcd-in 0.5s cubic-bezier(0.2, 0.8, 0.25, 1) backwards; animation-delay: calc(var(--i) * 35ms); }
.fcd:hover { transform: translateY(-3px); box-shadow: 0 22px 32px -18px color-mix(in srgb, var(--c2, #1d4ed8) 75%, transparent); }
.fcd.is-compact { padding: 13px; border-radius: 16px; }
.fcd.is-off { background: linear-gradient(160deg, rgba(244, 63, 94, 0.06), var(--lg-card) 60%); border-color: rgba(244, 63, 94, 0.22); }
.fcd.is-off .fcd-name, .fcd.is-off .fcd-desc { opacity: 0.72; }
.fcd.is-held { background: linear-gradient(160deg, rgba(245, 158, 11, 0.07), var(--lg-card) 60%); border-color: rgba(245, 158, 11, 0.3); }
.fcd.is-held .fcd-ico { filter: grayscale(0.7); }
.fcd.is-focus { animation: fcd-focus 2.4s ease 1; }
.fcd-top { display: flex; align-items: center; gap: 10px; }
.fcd-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 40px; height: 40px; border-radius: 14px; color: var(--c, #2563eb); background: color-mix(in srgb, var(--c, #2563eb) 12%, transparent); border: 1.5px solid color-mix(in srgb, var(--c, #2563eb) 40%, transparent); transition: all 0.5s ease; }
.is-compact .fcd-ico { width: 34px; height: 34px; border-radius: 12px; }
.fcd.is-off .fcd-ico { color: #94a3b8; background: var(--lg-soft); border-color: var(--lg-line); }
.fcd-name { flex: 1; min-width: 0; font-weight: 800; font-size: 0.92rem; line-height: 1.25; color: var(--lg-ink); transition: opacity 0.4s ease; }
.is-compact .fcd-name { font-size: 0.86rem; }
.fcd-state { display: inline-flex; align-items: center; gap: 6px; flex: none; padding: 3px 9px; border-radius: 9999px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.04em; transition: color 0.4s ease, background 0.4s ease; }
.fcd-state.is-on { color: #047857; background: rgba(16, 185, 129, 0.14); }
.fcd-state.is-off { color: #be123c; background: rgba(244, 63, 94, 0.12); }
.fcd-state.is-held { color: #b45309; background: rgba(245, 158, 11, 0.16); }
.fcd-dot { position: relative; width: 7px; height: 7px; border-radius: 9999px; background: currentColor; }
.fcd-state.is-on .fcd-dot::after { content: ''; position: absolute; inset: -4px; border-radius: 9999px; border: 2px solid currentColor; opacity: 0; animation: fcd-live 1.9s ease-out 3; }
.fcd-desc { flex: 1; margin: 10px 0 0; font-size: 0.8rem; line-height: 1.5; color: var(--lg-mute); transition: opacity 0.4s ease; }
.is-compact .fcd-desc { margin-top: 8px; font-size: 0.76rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.fcd-note { display: inline-flex; align-items: center; gap: 5px; margin: 8px 0 0; font-size: 0.72rem; font-weight: 700; color: #b45309; }
.fcd-foot { display: flex; align-items: center; gap: 10px; margin-top: 14px; padding-top: 12px; border-top: 1px dashed var(--lg-line); }
.fcd-foot-txt { font-size: 0.78rem; font-weight: 800; color: var(--lg-mute); }
.fcd :deep(mark) { padding: 0 2px; border-radius: 4px; color: inherit; background: linear-gradient(transparent 55%, rgba(250, 204, 21, 0.75) 55%); animation: fcd-mark 0.5s ease backwards; }

/* the card powers down when its switch goes off, and wakes when it goes on */
.fcd.flash-off { animation: fcd-down 1.1s ease 1; }
.fcd.flash-off::after, .fcd.flash-on::after { content: ''; position: absolute; inset: 0; border-radius: inherit; pointer-events: none; animation: fcd-wash 1.1s ease-out 1 both; }
.fcd.flash-off::after { background: radial-gradient(80% 90% at 50% 100%, rgba(244, 63, 94, 0.28), transparent 70%); }
.fcd.flash-on { animation: fcd-up 0.9s ease 1; }
.fcd.flash-on::after { background: radial-gradient(80% 90% at 50% 100%, rgba(16, 185, 129, 0.3), transparent 70%); }

@keyframes fcd-in { from { opacity: 0; transform: translateY(16px) scale(0.97); } }
@keyframes fcd-live { 0% { transform: scale(0.5); opacity: 0.8; } 100% { transform: scale(1.7); opacity: 0; } }
@keyframes fcd-mark { from { background-size: 0 100%; } }
@keyframes fcd-down { 0% { transform: scale(1); } 18% { transform: scale(0.965); filter: brightness(1.08); } 40% { transform: scale(0.985) translateX(-3px); } 60% { transform: scale(0.985) translateX(3px); } 100% { transform: scale(1); } }
@keyframes fcd-up { 0% { transform: scale(0.97); } 45% { transform: scale(1.025); } 100% { transform: scale(1); } }
@keyframes fcd-wash { from { opacity: 1; } to { opacity: 0; } }
@keyframes fcd-focus { 0%, 100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); } 15%, 55% { box-shadow: 0 0 0 5px rgba(59, 130, 246, 0.55); } }
@media (prefers-reduced-motion: reduce) { .fcd, .fcd :deep(mark), .fcd-dot::after, .fcd::after { animation: none !important; } }
</style>

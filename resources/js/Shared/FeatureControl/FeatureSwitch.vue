<template>
  <button
    type="button"
    role="switch"
    class="fsw"
    :class="[state, 'is-' + size, { 'pop-off': popOff, 'pop-on': popOn }]"
    :aria-checked="state === 'mixed' ? 'mixed' : String(state === 'on')"
    :aria-label="label"
    :title="label"
    :disabled="disabled"
    @click.stop="$emit('toggle')"
  >
    <span class="fsw-track">
      <span class="fsw-ring"></span>
      <span class="fsw-txt fsw-on">{{ onText }}</span>
      <span class="fsw-txt fsw-off">{{ offText }}</span>
      <span class="fsw-thumb"></span>
    </span>
  </button>
</template>

<script>
// A real toggle switch: green and to the right when on, grey and to the left when off, amber and centred when only some of a group is on.
// Switching OFF plays a short power-down (the thumb drops back, the track dims and shakes, a red ring leaves it); switching ON glows green.
export default {
  props: {
    state: { type: String, default: 'on' }, // on | off | mixed
    size: { type: String, default: 'md' }, // md | lg
    label: { type: String, default: '' },
    disabled: Boolean,
    onText: { type: String, default: 'ON' },
    offText: { type: String, default: 'OFF' },
  },
  emits: ['toggle'],
  data() { return { popOff: false, popOn: false }; },
  watch: {
    state(now, was) {
      clearTimeout(this.t);
      this.popOff = now === 'off' && was !== 'off';
      this.popOn = now === 'on' && was !== 'on';
      if (this.popOff || this.popOn) this.t = setTimeout(() => { this.popOff = false; this.popOn = false; }, 900);
    },
  },
  beforeUnmount() { clearTimeout(this.t); },
};
</script>

<style scoped>
.fsw { position: relative; display: inline-flex; flex: none; padding: 0; border: 0 !important; background: transparent !important; box-shadow: none !important; min-height: 0 !important; border-radius: 9999px !important; cursor: pointer; -webkit-tap-highlight-color: transparent; }
.fsw:focus-visible { outline: 3px solid rgba(59, 130, 246, 0.45); outline-offset: 3px; }
.fsw:disabled { opacity: 0.55; cursor: wait; }
.fsw-track { position: relative; display: block; width: 50px; height: 28px; border-radius: 9999px; background: linear-gradient(145deg, #fb7185, #e11d48); box-shadow: inset 0 2px 5px rgba(127, 29, 29, 0.35); transition: background 0.35s ease, box-shadow 0.35s ease; }
.fsw.is-lg .fsw-track { width: 66px; height: 36px; }
.fsw.on .fsw-track { background: linear-gradient(145deg, #34d399, #059669); }
.fsw.mixed .fsw-track { background: linear-gradient(145deg, #fbbf24, #d97706); }
.fsw:hover:not(:disabled) .fsw-track { box-shadow: inset 0 2px 5px rgba(127, 29, 29, 0.35), 0 0 0 4px rgba(244, 63, 94, 0.2); }
.fsw.on:hover:not(:disabled) .fsw-track { box-shadow: inset 0 2px 5px rgba(0, 0, 0, 0.22), 0 0 0 4px rgba(16, 185, 129, 0.2); }
.fsw-thumb { position: absolute; top: 3px; left: 3px; width: 22px; height: 22px; border-radius: 9999px; background: #fff; box-shadow: 0 3px 7px rgba(15, 23, 42, 0.4); transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.5, 1), width 0.2s ease; }
.fsw.is-lg .fsw-thumb { top: 4px; left: 4px; width: 28px; height: 28px; }
.fsw:active:not(:disabled) .fsw-thumb { width: 28px; }
.fsw.is-lg:active:not(:disabled) .fsw-thumb { width: 34px; }
.fsw.on .fsw-thumb { transform: translateX(22px); }
.fsw.mixed .fsw-thumb { transform: translateX(11px); }
.fsw.is-lg.on .fsw-thumb { transform: translateX(30px); }
.fsw.is-lg.mixed .fsw-thumb { transform: translateX(15px); }
.fsw-txt { position: absolute; top: 50%; transform: translateY(-50%); font-size: 0.5rem; font-weight: 900; letter-spacing: 0.06em; color: #fff; transition: opacity 0.25s ease; pointer-events: none; }
.fsw.is-lg .fsw-txt { font-size: 0.6rem; }
.fsw-on { left: 8px; opacity: 0; }
.fsw-off { right: 7px; opacity: 1; }
.fsw.on .fsw-on { opacity: 1; }
.fsw.on .fsw-off, .fsw.mixed .fsw-off { opacity: 0; }
.fsw-ring { position: absolute; inset: 0; border-radius: 9999px; border: 2px solid transparent; pointer-events: none; }
.fsw.pop-off .fsw-track { animation: fsw-shake 0.45s ease 0.18s 1; }
.fsw.pop-off .fsw-ring { border-color: rgba(244, 63, 94, 0.75); animation: fsw-ring 0.8s ease-out 0.12s 1 both; }
.fsw.pop-on .fsw-ring { border-color: rgba(16, 185, 129, 0.8); animation: fsw-ring 0.8s ease-out 0.05s 1 both; }
@keyframes fsw-shake { 0%, 100% { transform: translateX(0); } 20% { transform: translateX(-3px); } 45% { transform: translateX(3px); } 70% { transform: translateX(-2px); } }
@keyframes fsw-ring { from { transform: scale(1); opacity: 1; } to { transform: scale(1.7, 2); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .fsw-thumb, .fsw-track, .fsw-txt { transition: none !important; } .fsw-track, .fsw-ring { animation: none !important; } }
</style>

<template>
  <modal :show="show" max-width="lg" :transparent="true" @close="cancel">
    <div class="ar-card" role="document">
      <header class="ar-head">
        <span class="ar-orb"><icons name="refresh" class="w-7 h-7 ar-spin" /></span>
        <div class="ar-titles">
          <h2>{{ $t('Auto Renewal') }}</h2>
          <p>{{ $t('The record renews itself on its expiry date, so it never lapses.') }}</p>
        </div>
        <button type="button" class="ar-x" :aria-label="$t('Close')" @click="cancel"><icons name="cross" class="w-4 h-4" /></button>
      </header>

      <div class="ar-body">
        <p v-if="title" class="ar-for"><icons name="doc-text" class="w-4 h-4" /><b>{{ title }}</b></p>

        <!-- 1: how long each renewal lasts -->
        <section class="ar-block">
          <h3><span class="ar-step is-teal">1</span>{{ $t('How long does each renewal last?') }}</h3>
          <div class="ar-chips">
            <button v-for="p in presets" :key="p.days" type="button" class="ar-chip is-teal" :class="{ on: !customOn && Number(d) === p.days }" @click="pickPreset(p.days)">
              <span class="ar-chip-ico"><icons :name="p.icon" class="w-5 h-5" /></span>
              <b>{{ p.days }}</b><small>{{ p.label }}</small>
              <i v-if="!customOn && Number(d) === p.days" class="ar-tick"><icons name="tick" class="w-3 h-3" /></i>
            </button>
            <label class="ar-chip is-teal ar-custom" :class="{ on: customOn }">
              <span class="ar-chip-ico"><icons name="edit" class="w-5 h-5" /></span>
              <input v-model="customText" type="number" min="1" max="3650" inputmode="numeric" :placeholder="$t('Other')" @focus="customOn = true" @input="onCustom" />
              <small>{{ $t('days') }}</small>
            </label>
          </div>
          <p v-if="errDays" class="ar-err">{{ errDays }}</p>
        </section>

        <!-- 2: when to be told -->
        <section class="ar-block">
          <h3><span class="ar-step is-amber">2</span>{{ $t('Tell me before it renews') }}<small>{{ $t('choose one or more') }}</small></h3>
          <div class="ar-chips">
            <button v-for="c in options" :key="c.days" type="button" class="ar-chip is-amber" :class="{ on: sel.includes(c.days) }" @click="toggle(c.days)">
              <span class="ar-chip-ico"><icons :name="c.days === 0 ? 'clock' : 'bell'" class="w-5 h-5" /></span>
              <b>{{ c.short }}</b><small>{{ c.label }}</small>
              <i v-if="sel.includes(c.days)" class="ar-tick"><icons name="tick" class="w-3 h-3" /></i>
            </button>
            <label class="ar-chip is-amber ar-add">
              <span class="ar-chip-ico"><icons name="plus" class="w-5 h-5" /></span>
              <input v-model="addText" type="number" min="0" max="365" inputmode="numeric" :placeholder="$t('Add days')" @keydown.enter.prevent="addDays" />
              <button type="button" class="ar-add-btn" :disabled="!canAdd" @click="addDays">{{ $t('Add') }}</button>
            </label>
          </div>
          <p class="ar-note">{{ sel.length ? $t('You will be told :list.', { list: toldText }) : $t('Nobody is told in advance. The record still renews itself.') }}</p>
        </section>

        <!-- what will happen -->
        <section class="ar-preview" :class="{ 'is-off': !expiry }">
          <h3><icons name="calendar" class="w-4 h-4" />{{ $t('What will happen') }}</h3>
          <p v-if="!expiry" class="ar-err">{{ $t('Set the expiry date of the record first: it renews on that day.') }}</p>
          <ol v-else class="ar-line">
            <li v-for="n in noticeSteps" :key="'n' + n.days" class="is-notice"><span class="ar-node"><icons name="bell" class="w-3 h-3" /></span><div><b>{{ n.date }}</b><small>{{ $t('Owners are told (:n day(s) before)', { n: n.days }) }}</small></div></li>
            <li class="is-renew"><span class="ar-node"><icons name="refresh" class="w-3 h-3" /></span><div><b>{{ fmt(expiry) }}</b><small>{{ $t('The record renews itself') }}</small></div></li>
            <li class="is-done"><span class="ar-node"><icons name="tick" class="w-3 h-3" /></span><div><b>{{ newExpiryText }}</b><small>{{ $t('New expiry date (:n days later)', { n: Number(d) || 0 }) }}</small></div></li>
          </ol>
        </section>
      </div>

      <footer class="ar-foot">
        <button v-if="canTurnOff" type="button" class="ar-btn is-ghost is-red" @click="$emit('turnoff')"><icons name="cross" class="w-4 h-4" />{{ $t('Turn off') }}</button>
        <span class="ar-grow"></span>
        <button type="button" class="ar-btn is-ghost" @click="cancel">{{ $t('Cancel') }}</button>
        <button type="button" class="ar-btn is-main" :disabled="!valid" @click="save"><icons name="tick" class="w-4 h-4" />{{ $t('Save auto renewal') }}</button>
      </footer>
    </div>
  </modal>
</template>

<script>
import Modal from '@/Jetstream/Modal.vue';

// The box that sets up auto renewal of a compliance record: how long each renewal lasts, which days before it the owners are told (one or several), and a live
// timeline of what will happen. It only edits values; the form keeps them and saves them with the record.
export default {
  components: { Modal },
  props: { show: Boolean, title: String, days: { type: [Number, String], default: '' }, notify: { type: Array, default: () => [] }, expiry: { type: String, default: '' }, canTurnOff: Boolean },
  emits: ['save', 'cancel', 'turnoff'],
  data() { return { d: '', customOn: false, customText: '', sel: [], extraDays: [], addText: '' }; },
  watch: { show(v) { if (v) this.load(); } },
  computed: {
    presets() { return [{ days: 30, label: this.$t('a month'), icon: 'calendar' }, { days: 90, label: this.$t('a quarter'), icon: 'calendar' }, { days: 180, label: this.$t('half a year'), icon: 'calendar' }, { days: 365, label: this.$t('a year'), icon: 'calendar' }]; },
    options() {
      const base = [{ days: 30, short: '1 ' + this.$t('month'), label: this.$t('before') }, { days: 14, short: '2 ' + this.$t('weeks'), label: this.$t('before') }, { days: 7, short: '1 ' + this.$t('week'), label: this.$t('before') }, { days: 3, short: '3 ' + this.$t('days'), label: this.$t('before') }, { days: 1, short: '1 ' + this.$t('day'), label: this.$t('before') }, { days: 0, short: this.$t('Same day'), label: this.$t('as the renewal') }];
      const known = base.map(b => b.days);
      return [...base, ...this.extraDays.filter(n => !known.includes(n)).map(n => ({ days: n, short: n + ' ' + (n === 1 ? this.$t('day') : this.$t('days')), label: this.$t('before') }))];
    },
    valid() { return Number(this.d) >= 1 && Number(this.d) <= 3650; },
    errDays() { return this.d !== '' && (Number(this.d) < 1 || Number(this.d) > 3650) ? this.$t('Enter a number of days from 1 to 3650.') : ''; },
    canAdd() { const n = Number(this.addText); return this.addText !== '' && Number.isInteger(n) && n >= 0 && n <= 365; },
    sortedSel() { return [...this.sel].sort((a, b) => b - a); },
    toldText() { return this.sortedSel.map(n => (n === 0 ? this.$t('on the day') : n + ' ' + (n === 1 ? this.$t('day') : this.$t('days')) + ' ' + this.$t('before'))).join(', '); },
    noticeSteps() { return this.sortedSel.filter(n => n > 0).map(n => ({ days: n, date: this.fmt(this.shift(this.expiry, -n)) })); },
    newExpiryText() { return this.expiry && Number(this.d) ? this.fmt(this.shift(this.expiry, Number(this.d))) : '-'; },
  },
  methods: {
    load() {
      this.d = this.days ? Number(this.days) : 365;
      this.customOn = ![30, 90, 180, 365].includes(Number(this.d));
      this.customText = this.customOn ? String(this.d) : '';
      this.sel = (this.notify && this.notify.length ? [...this.notify] : [7, 3, 1]).map(Number);
      this.extraDays = this.sel.filter(n => ![30, 14, 7, 3, 1, 0].includes(n));
      this.addText = '';
    },
    pickPreset(n) { this.customOn = false; this.customText = ''; this.d = n; },
    onCustom() { this.customOn = true; this.d = this.customText === '' ? '' : Number(this.customText); },
    toggle(n) { this.sel = this.sel.includes(n) ? this.sel.filter(x => x !== n) : [...this.sel, n]; },
    addDays() { if (!this.canAdd) return; const n = Number(this.addText); if (![30, 14, 7, 3, 1, 0].includes(n) && !this.extraDays.includes(n)) this.extraDays = [...this.extraDays, n]; if (!this.sel.includes(n)) this.sel = [...this.sel, n]; this.addText = ''; },
    shift(iso, n) { const t = new Date(String(iso).slice(0, 10) + 'T00:00:00Z'); if (isNaN(t)) return ''; t.setUTCDate(t.getUTCDate() + n); return t.toISOString().slice(0, 10); },
    fmt(iso) { if (!iso) return '-'; try { return this.$date(String(iso).slice(0, 10)); } catch (e) { return String(iso).slice(0, 10); } },
    save() { if (this.valid) this.$emit('save', { days: Number(this.d), notify: this.sortedSel }); },
    cancel() { this.$emit('cancel'); },
  },
};
</script>

<style scoped>
.ar-card { --t: #0d9488; --t2: #0f766e; --a: #d97706; --e: #059669; position: relative; overflow: hidden; border-radius: 26px; text-align: left; background: var(--lg-card, #fff); color: var(--lg-ink, #0f2540); box-shadow: 0 40px 80px -30px rgba(15, 118, 110, 0.55), 0 0 0 1px rgba(13, 148, 136, 0.25); }
.ar-head { position: relative; display: flex; align-items: center; gap: 14px; padding: 18px 20px; color: #fff; background: radial-gradient(120% 140% at 0% 0%, #2dd4bf 0%, #0d9488 45%, #0f766e 100%); overflow: hidden; }
.ar-head::after { content: ''; position: absolute; right: -40px; top: -60px; width: 190px; height: 190px; border-radius: 50%; background: radial-gradient(circle, rgba(255, 255, 255, 0.28), transparent 65%); animation: ar-glow 5s ease-in-out infinite; pointer-events: none; }
.ar-orb { flex: none; display: grid; place-items: center; width: 52px; height: 52px; border-radius: 18px; background: rgba(255, 255, 255, 0.2); box-shadow: inset 0 0 0 1.5px rgba(255, 255, 255, 0.55), 0 12px 20px -10px rgba(0, 0, 0, 0.4); }
.ar-spin { animation: ar-turn 7s linear infinite; }
.ar-titles { flex: 1; min-width: 0; position: relative; z-index: 1; } .ar-titles h2 { margin: 0; font-size: 1.2rem; font-weight: 800; } .ar-titles p { margin: 2px 0 0; font-size: 0.8rem; opacity: 0.92; }
.ar-x { position: relative; z-index: 1; flex: none; display: grid; place-items: center; width: 34px; height: 34px; border: 0; border-radius: 12px; cursor: pointer; color: #fff; background: rgba(255, 255, 255, 0.18); } .ar-x:hover { background: rgba(255, 255, 255, 0.3); }
.ar-body { display: grid; gap: 16px; max-height: min(72vh, 640px); overflow-y: auto; padding: 18px 20px 6px; }
.ar-for { display: flex; align-items: center; gap: 8px; margin: 0; padding: 8px 12px; border-radius: 12px; font-size: 0.82rem; color: var(--t2); background: rgba(13, 148, 136, 0.09); } .ar-for b { overflow-wrap: anywhere; }
.ar-block h3 { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; margin: 0 0 10px; font-size: 0.92rem; font-weight: 800; } .ar-block h3 small { font-size: 0.7rem; font-weight: 700; color: var(--lg-mute, #64748b); }
.ar-step { display: grid; place-items: center; width: 24px; height: 24px; border-radius: 50%; color: #fff; font-size: 0.78rem; font-weight: 800; } .ar-step.is-teal { background: linear-gradient(135deg, #2dd4bf, #0f766e); } .ar-step.is-amber { background: linear-gradient(135deg, #fbbf24, #d97706); }
.ar-chips { display: grid; grid-template-columns: repeat(auto-fill, minmax(104px, 1fr)); gap: 10px; }
.ar-chip { --c: var(--t); position: relative; display: grid; justify-items: center; gap: 2px; padding: 12px 8px 10px; border: 0; border-radius: 16px; cursor: pointer; text-align: center; color: var(--lg-ink, #0f2540); background: var(--lg-card, #fff); box-shadow: inset 0 0 0 1.5px color-mix(in srgb, var(--c) 35%, transparent), 0 10px 16px -12px var(--c); transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease; }
.ar-chip.is-teal { --c: #0d9488; } .ar-chip.is-amber { --c: #d97706; }
.ar-chip:hover { transform: translateY(-3px); box-shadow: inset 0 0 0 1.5px var(--c), 0 16px 22px -12px var(--c); }
.ar-chip-ico { display: grid; place-items: center; width: 34px; height: 34px; margin-bottom: 2px; border-radius: 11px; color: var(--c); background: color-mix(in srgb, var(--c) 12%, transparent); box-shadow: inset 0 0 0 1.5px var(--c); }
.ar-chip b { font-size: 1.05rem; line-height: 1.1; color: var(--c); } .ar-chip small { font-size: 0.68rem; font-weight: 700; color: var(--lg-mute, #64748b); }
.ar-chip.on { background: linear-gradient(160deg, color-mix(in srgb, var(--c) 16%, #fff), #fff 80%); box-shadow: inset 0 0 0 2px var(--c), 0 16px 24px -12px var(--c); animation: ar-pop 0.25s ease; }
.ar-chip.on .ar-chip-ico { color: #fff; background: linear-gradient(135deg, var(--c), color-mix(in srgb, var(--c) 65%, #000)); }
.ar-tick { position: absolute; top: 6px; right: 6px; display: grid; place-items: center; width: 18px; height: 18px; border-radius: 50%; color: #fff; background: var(--c); }
.ar-custom input, .ar-add input { width: 100%; max-width: 76px; height: 28px; padding: 0 6px; border: 0; border-radius: 9px; text-align: center; font-size: 1rem; font-weight: 800; color: var(--c); background: color-mix(in srgb, var(--c) 8%, transparent); box-shadow: inset 0 0 0 1.5px color-mix(in srgb, var(--c) 40%, transparent); }
.ar-custom input:focus, .ar-add input:focus { outline: none; box-shadow: inset 0 0 0 2px var(--c); }
.ar-add { cursor: default; } .ar-add:hover { transform: none; }
.ar-add input { font-size: 0.78rem; font-weight: 700; } .ar-add-btn { margin-top: 2px; padding: 3px 12px; border: 0; border-radius: 9px; cursor: pointer; font-size: 0.7rem; font-weight: 800; color: #fff; background: linear-gradient(135deg, #fbbf24, #d97706); } .ar-add-btn:disabled { opacity: 0.45; cursor: not-allowed; }
.ar-note { margin: 10px 0 0; font-size: 0.78rem; color: var(--lg-mute, #64748b); } .ar-err { margin: 8px 0 0; font-size: 0.78rem; font-weight: 700; color: #b91c1c; }
.ar-preview { padding: 14px 16px; border-radius: 18px; background: linear-gradient(135deg, rgba(13, 148, 136, 0.09), rgba(5, 150, 105, 0.05)); box-shadow: inset 0 0 0 1px rgba(13, 148, 136, 0.25); }
.ar-preview h3 { display: flex; align-items: center; gap: 8px; margin: 0 0 10px; font-size: 0.85rem; font-weight: 800; color: var(--t2); }
.ar-line { position: relative; display: grid; gap: 12px; margin: 0; padding: 0 0 0 4px; list-style: none; }
.ar-line::before { content: ''; position: absolute; left: 15px; top: 8px; bottom: 8px; width: 2px; background: linear-gradient(180deg, #f59e0b, #0d9488 70%, #059669); border-radius: 2px; }
.ar-line li { position: relative; display: flex; align-items: center; gap: 12px; }
.ar-node { z-index: 1; flex: none; display: grid; place-items: center; width: 24px; height: 24px; border-radius: 50%; color: #fff; box-shadow: 0 0 0 4px var(--lg-card, #fff); }
.is-notice .ar-node { background: #d97706; } .is-renew .ar-node { background: #0d9488; animation: ar-pulse 2.2s ease-in-out infinite; } .is-done .ar-node { background: #059669; }
.ar-line b { display: block; font-size: 0.86rem; } .ar-line small { display: block; font-size: 0.72rem; color: var(--lg-mute, #64748b); }
.ar-foot { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; padding: 14px 20px 18px; }
.ar-grow { flex: 1; }
.ar-btn { display: inline-flex; align-items: center; justify-content: center; gap: 7px; height: 42px; padding: 0 18px; border: 0; border-radius: 14px; cursor: pointer; font-size: 0.86rem; font-weight: 800; transition: transform 0.15s ease, box-shadow 0.15s ease; }
.ar-btn.is-main { color: #fff; background: linear-gradient(135deg, #14b8a6, #0f766e); box-shadow: 0 14px 22px -12px rgba(15, 118, 110, 0.9); } .ar-btn.is-main:hover:not(:disabled) { transform: translateY(-2px); } .ar-btn.is-main:disabled { opacity: 0.45; cursor: not-allowed; }
.ar-btn.is-ghost { color: var(--lg-ink, #0f2540); background: var(--lg-card, #fff); box-shadow: inset 0 0 0 1px var(--lg-line, #e3e9f2), 0 8px 14px -10px rgba(31, 70, 130, 0.5); } .ar-btn.is-red { color: #b91c1c; }
.ar-btn:focus-visible, .ar-chip:focus-visible, .ar-x:focus-visible { outline: 2px solid #0d9488; outline-offset: 2px; }
@keyframes ar-turn { to { transform: rotate(360deg); } }
@keyframes ar-glow { 0%, 100% { transform: scale(1); opacity: 0.8; } 50% { transform: scale(1.18); opacity: 1; } }
@keyframes ar-pop { 0% { transform: scale(0.96); } 60% { transform: scale(1.03); } 100% { transform: scale(1); } }
@keyframes ar-pulse { 0%, 100% { box-shadow: 0 0 0 4px var(--lg-card, #fff), 0 0 0 4px rgba(13, 148, 136, 0); } 50% { box-shadow: 0 0 0 4px var(--lg-card, #fff), 0 0 0 9px rgba(13, 148, 136, 0.25); } }
@media (max-width: 520px) { .ar-chips { grid-template-columns: repeat(2, minmax(0, 1fr)); } .ar-foot { flex-direction: column-reverse; align-items: stretch; } .ar-grow { display: none; } .ar-btn { width: 100%; } .ar-head { padding: 14px; } .ar-body { padding: 14px 14px 4px; } }
@media (prefers-reduced-motion: reduce) { .ar-spin, .ar-head::after, .ar-chip.on, .is-renew .ar-node { animation: none; } .ar-chip { transition: none; } }
</style>

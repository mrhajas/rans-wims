<template>
  <div class="al">
    <transition name="al-bar">
      <div v-if="selected.length" class="al-bar" role="region" :aria-label="$t('Selected records')">
        <span class="al-bar-ico"><icons name="refresh" class="w-4 h-4" /></span>
        <span class="al-bar-text"><b>{{ selected.length }}</b> {{ $t('selected') }}</span>
        <button type="button" class="lg-btn lg-btn-soft al-sm" @click="$emit('update:selected', [])">{{ $t('Clear') }}</button>
        <button type="button" class="lg-btn tone tone-solid al-sm" style="--tone: #0f766e" @click="$emit('restore', picked)"><span class="tone-ico"><icons name="refresh" class="w-3.5 h-3.5" /></span>{{ $t('Put back selected') }}</button>
      </div>
    </transition>

    <div class="lg-table-wrap">
      <table v-labelize class="lg-table lg-responsive al-table">
        <thead>
          <tr>
            <th v-if="canRun" class="al-ck" @click.stop><label class="al-chk" :title="$t('Select all on this page')"><input type="checkbox" :checked="allOn" :disabled="!selectable.length" @change="toggleAll" /><i><icons name="tick" class="w-3 h-3" /></i></label></th>
            <th>{{ $t('When') }}</th><th>{{ $t('Who') }}</th><th>{{ $t('What happened') }}</th><th class="al-r">{{ $t('Size') }}</th><th class="al-c">{{ $t('Actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(r, i) in records" :key="r.archive + '-' + r.id" class="al-row" :class="{ 'is-sel': isOn(r), 'is-back': r.restored }" :style="{ '--i': Math.min(i, 12) }" tabindex="0" :title="$t('Click to read this record')" @click="$emit('read', r)" @keydown.enter.self.prevent="$emit('read', r)">
            <td v-if="canRun" class="al-ck" @click.stop><label v-if="!r.restored" class="al-chk"><input type="checkbox" :checked="isOn(r)" @change="toggle(r)" /><i><icons name="tick" class="w-3 h-3" /></i></label></td>
            <td class="al-when"><b>{{ day(r.at) }}</b><small>{{ time(r.at) }}</small></td>
            <td class="al-who">
              <span v-if="r.user" class="al-user"><i class="al-av" :style="{ '--h': hue(r.user) }">{{ initials(r.user) }}</i><span>{{ r.user }}</span></span>
              <span v-else class="al-nouser">-</span>
            </td>
            <td class="al-what">
              <span class="al-line"><span v-if="parts(r.summary).head" class="al-chip">{{ parts(r.summary).head }}</span>{{ parts(r.summary).rest }}</span>
              <small>#{{ r.id }} · {{ r.ref }}<span v-if="r.restored" class="al-back"><icons name="tick" class="w-3 h-3" />{{ $t('Put back') }}</span></small>
            </td>
            <td class="al-r al-size"><b>{{ fmt(r.compressed) }}</b><small>{{ $t('from') }} {{ fmt(r.original) }}</small></td>
            <td class="al-c al-act" @click.stop>
              <div class="al-actbox">
                <button type="button" class="al-btn" @click="$emit('read', r)"><icons name="search" class="w-3.5 h-3.5" />{{ $t('Read') }}</button>
                <button v-if="canRun && !r.restored" type="button" class="al-ico lg-tip" :data-tip="$t('Put back into the live log')" :aria-label="$t('Put back into the live log')" @click="$emit('restore', [r])"><icons name="refresh" class="w-3.5 h-3.5" /></button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script>
// The records of an archive (or of a whole log) as a list that is easy on the eye: when, who, what happened, size, and what can be done. Records can be ticked to put them back.
export default {
  props: { records: { type: Array, default: () => [] }, selected: { type: Array, default: () => [] }, canRun: Boolean },
  emits: ['update:selected', 'read', 'restore'],
  computed: {
    keyOf() { return r => r.archive + ':' + r.id; },
    selectable() { return this.records.filter(r => !r.restored); },
    allOn() { return this.selectable.length > 0 && this.selectable.every(r => this.isOn(r)); },
    // the ticked records (the rows themselves, from any archive)
    picked() { return this.records.filter(r => this.isOn(r)); },
  },
  methods: {
    isOn(r) { return this.selected.includes(this.keyOf(r)); },
    toggle(r) { const k = this.keyOf(r); this.$emit('update:selected', this.isOn(r) ? this.selected.filter(x => x !== k) : [...this.selected, k]); },
    toggleAll() { const keys = this.selectable.map(this.keyOf); this.$emit('update:selected', this.allOn ? this.selected.filter(x => !keys.includes(x)) : [...new Set([...this.selected, ...keys])]); },
    fmt(b) {
      b = Number(b || 0);
      const u = ['B', 'KB', 'MB', 'GB'];
      let i = 0;
      while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
      return (i === 0 ? String(Math.round(b)) : b.toFixed(b < 10 ? 2 : 1)) + ' ' + u[i];
    },
    day(v) { const d = this.when(v); return d ? d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '-'; },
    time(v) { const d = this.when(v); return d ? d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : ''; },
    when(v) { if (!v) return null; const d = new Date(String(v).replace(' ', 'T')); return isNaN(d) ? null : d; },
    parts(text) { const t = String(text || ''); const i = t.indexOf(' · '); return i > 0 && i < 24 ? { head: t.slice(0, i), rest: t.slice(i + 3) } : { head: '', rest: t }; },
    initials(n) { const w = String(n || '?').trim().split(/\s+/); return ((w[0] || '?')[0] + (w[1] ? w[1][0] : '')).toUpperCase(); },
    hue(n) { let h = 0; String(n).split('').forEach(c => { h = (h * 31 + c.charCodeAt(0)) % 360; }); return h; },
  },
};
</script>

<style scoped>
.al-table th { padding-top: 0.9rem; padding-bottom: 0.9rem; } .al-table td { padding-top: 0.85rem; padding-bottom: 0.85rem; vertical-align: middle; }
.al-r { text-align: right; white-space: nowrap; } .al-c { text-align: center; white-space: nowrap; } .al-ck { width: 2.6rem; }
.al-row { animation: al-in 0.4s cubic-bezier(0.2, 0.8, 0.2, 1) backwards; animation-delay: calc(var(--i, 0) * 25ms); transition: background 0.2s ease; } @keyframes al-in { from { opacity: 0; transform: translateY(8px); } }
.al-row { cursor: pointer; } .al-row:hover { background: rgba(99, 102, 241, 0.07); } .al-row:focus-visible { outline: 3px solid rgba(99, 102, 241, 0.35); outline-offset: -3px; } .al-row.is-sel { background: rgba(15, 118, 110, 0.08); } .al-row.is-back { opacity: 0.78; }
.al-when { white-space: nowrap; } .al-when b, .al-size b { display: block; font-size: 0.86rem; } .al-when small, .al-size small, .al-what small { display: block; margin-top: 0.15rem; font-size: 0.72rem; color: #64748b; }
.al-who { min-width: 9rem; } .al-user { display: inline-flex; align-items: center; gap: 0.55rem; font-size: 0.84rem; font-weight: 700; } .al-nouser { color: #cbd5e1; }
.al-av { flex: none; width: 1.9rem; height: 1.9rem; display: grid; place-items: center; border-radius: 50%; font-style: normal; font-size: 0.68rem; font-weight: 900; color: hsl(var(--h) 55% 32%); background: linear-gradient(135deg, hsl(var(--h) 80% 90%), hsl(calc(var(--h) + 40) 80% 82%)); } html.dark .al-av { color: hsl(var(--h) 80% 85%); background: linear-gradient(135deg, hsl(var(--h) 45% 26%), hsl(calc(var(--h) + 40) 45% 20%)); }
.al-what { max-width: 30rem; } .al-line { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; overflow-wrap: anywhere; font-size: 0.86rem; line-height: 1.4; }
.al-chip { display: inline-block; margin-right: 0.45rem; padding: 0.05rem 0.55rem; border-radius: 999px; font-size: 0.66rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.03em; color: #4338ca; background: rgba(79, 70, 229, 0.12); vertical-align: 1px; }
.al-back { display: inline-flex; align-items: center; gap: 0.25rem; margin-left: 0.6rem; padding: 0 0.5rem; border-radius: 999px; font-weight: 800; color: #047857; background: rgba(5, 150, 105, 0.14); }
.al-act { min-width: 9.5rem; } .al-actbox { display: flex; align-items: center; justify-content: center; gap: 0.5rem; }
.al-btn { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.4rem 0.8rem; border: 0; border-radius: 999px; font-size: 0.76rem; font-weight: 800; cursor: pointer; color: #4338ca; background: rgba(79, 70, 229, 0.1); transition: transform 0.2s cubic-bezier(0.3, 1.5, 0.5, 1), background 0.2s ease, color 0.2s ease; } .al-btn:hover { color: #fff; background: #4f46e5; transform: translateY(-2px); }
.al-ico { width: 2rem; height: 2rem; display: grid; place-items: center; border: 0; border-radius: 50%; cursor: pointer; color: #0f766e; background: rgba(15, 118, 110, 0.12); transition: transform 0.2s cubic-bezier(0.3, 1.5, 0.5, 1), background 0.2s ease, color 0.2s ease; } .al-ico:hover { color: #fff; background: #0f766e; transform: rotate(120deg) scale(1.1); }
.al-chk { position: relative; display: inline-grid; cursor: pointer; } .al-chk input { position: absolute; opacity: 0; inset: 0; cursor: pointer; } .al-chk i { width: 1.3rem; height: 1.3rem; display: grid; place-items: center; border-radius: 0.45rem; border: 2px solid #cbd5e1; color: transparent; transition: all 0.2s cubic-bezier(0.3, 1.5, 0.5, 1); } .al-chk input:checked + i { color: #fff; border-color: transparent; background: linear-gradient(135deg, #14b8a6, #0f766e); transform: scale(1.08); } .al-chk input:focus-visible + i { outline: 3px solid rgba(15, 118, 110, 0.4); outline-offset: 2px; }
.al-bar { display: flex; align-items: center; gap: 0.7rem; flex-wrap: wrap; margin: 0 0 0.8rem; padding: 0.6rem 0.85rem; border-radius: 1rem; background: linear-gradient(135deg, rgba(20, 184, 166, 0.16), rgba(99, 102, 241, 0.1)); border: 1.5px solid rgba(15, 118, 110, 0.3); }
.al-bar-ico { width: 2rem; height: 2rem; display: grid; place-items: center; border-radius: 0.7rem; color: #fff; background: linear-gradient(135deg, #14b8a6, #0f766e); } .al-bar-text { flex: 1; font-size: 0.86rem; } .al-sm { padding: 0.35rem 0.8rem !important; font-size: 0.76rem !important; }
.al-bar-enter-active, .al-bar-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; } .al-bar-enter-from, .al-bar-leave-to { opacity: 0; transform: translateY(-8px); }
@media (max-width: 640px) { .al-what { max-width: none; } .al-c { text-align: left; } .al-actbox { justify-content: flex-start; } }
@media (prefers-reduced-motion: reduce) { .al-row { animation: none; } .al-btn, .al-ico, .al-chk i { transition: none; } }

/* true circles and one height, whatever the page-wide button rules say (they add padding and a 40 px minimum); the icon sits dead centre */
.al-ico { box-sizing: border-box; width: 2.1rem !important; height: 2.1rem !important; min-width: 0 !important; min-height: 0 !important; padding: 0 !important; margin: 0 !important; aspect-ratio: 1 / 1; border-radius: 50% !important; display: grid !important; place-items: center; line-height: 1; flex: none; }
.al-ico svg, .al-ico > div { display: block; margin: 0 !important; }
.al-btn { box-sizing: border-box; height: 2.1rem; min-height: 0 !important; padding: 0 0.9rem !important; margin: 0 !important; display: inline-flex !important; align-items: center; justify-content: center; line-height: 1; white-space: nowrap; }
.al-btn svg, .al-btn > div { display: block; margin: 0 !important; flex: none; }
.al-ico:hover { transform: scale(1.12); }
</style>

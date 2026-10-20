<template>
  <section id="st-typography" class="lg-card ty">
    <header class="lg-card-head">
      <span class="lg-card-ico lg-ico-indigo"><icons name="adjustments" class="w-5 h-5" /></span>
      <div class="min-w-0">
        <h2 class="lg-card-title">{{ $t('Typography') }}</h2>
        <p class="lg-card-sub">{{ $t('Choose the font of the whole application and, if you want, a different font, weight and italic for headings, paragraphs, labels, buttons, tabs, tables, tooltips and fields. Type in a font box to search the list.') }}</p>
      </div>
    </header>

    <div class="ty-rows">
      <div v-for="r in data.roles" :key="r.key" class="ty-row" :class="{ 'is-base': r.key === 'base' }">
        <div class="ty-name"><b>{{ r.label === 'Whole application' ? $t('Whole application') : $t(r.label) }}</b><small>{{ $t(r.about) }}</small></div>
        <div class="ty-f ty-font"><label>{{ $t('Font') }}</label><acct-select v-model="form[r.key].font" :options="fontOptions(r.key)" :placeholder="$t('Search fonts...')" /></div>
        <div class="ty-f ty-weight"><label>{{ $t('Weight') }}</label><acct-select v-model="form[r.key].weight" :options="data.weights" /></div>
        <div class="ty-f ty-style">
          <label>{{ $t('Style') }}</label>
          <div class="ty-toggles">
            <button type="button" class="ty-tg" :class="{ 'is-on': isBold(r.key) }" :aria-pressed="isBold(r.key)" :title="$t('Bold')" @click="toggleBold(r.key)"><b>B</b></button>
            <button type="button" class="ty-tg ty-it" :class="{ 'is-on': form[r.key].italic }" :aria-pressed="!!form[r.key].italic" :title="$t('Italic')" @click="form[r.key].italic = !form[r.key].italic"><i>I</i></button>
          </div>
        </div>
        <div class="ty-sample" :style="sampleStyle(r.key)">{{ $t('The quick brown fox jumps over the lazy dog') }} 0123456789</div>
      </div>
    </div>
    <span v-for="(m, k) in errors" :key="k" class="ty-err">{{ m }}</span>

    <div class="ty-foot">
      <p class="ty-note"><icons name="info" class="w-4 h-4" />{{ $t('Avenir is a licensed Apple font: it is used on Mac, iPhone and iPad and Montserrat is used on every other device. A part left on "Same as the whole application" and "Automatic" keeps the design as it is.') }}</p>
      <div class="ty-btns">
        <span v-if="saved" class="st-saved-badge"><icons name="tick" class="w-3.5 h-3.5" />{{ $t('Saved!') }}</span>
        <button type="button" class="lg-btn t-slate" :disabled="busy" @click="reset"><icons name="refresh" class="w-4 h-4" />{{ $t('Reset to Avenir') }}</button>
        <button type="button" class="lg-btn lg-btn-primary" :disabled="busy" @click="save"><icons name="tick" class="w-4 h-4" />{{ busy ? $t('Saving...') : $t('Save Typography') }}</button>
      </div>
    </div>
  </section>
</template>

<script>
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';

export default {
  components: { AcctSelect },
  props: { data: Object },
  data() {
    const form = {};
    this.data.roles.forEach(r => { form[r.key] = { ...this.data.current[r.key] }; });
    return { form, busy: false, saved: false, errors: {} };
  },
  watch: {
    // the chosen fonts are loaded for the preview as soon as they are picked
    form: { deep: true, handler() { this.loadChosen(); } },
  },
  mounted() { this.loadChosen(); },
  methods: {
    fontOptions(role) {
      const list = this.data.fonts.map(f => ({ value: f.value, label: f.label }));
      return role === 'base' ? list : [{ value: 'inherit', label: this.$t('Same as the whole application') }, ...list];
    },
    fontOf(key) { return this.data.fonts.find(f => f.value === key); },
    effectiveStack(role) { const k = this.form[role].font === 'inherit' ? this.form.base.font : this.form[role].font; const f = this.fontOf(k); return f ? f.stack : 'inherit'; },
    sampleStyle(role) {
      const r = this.form[role];
      return { fontFamily: this.effectiveStack(role), fontWeight: r.weight === 'auto' ? 400 : Number(r.weight), fontStyle: r.italic ? 'italic' : 'normal' };
    },
    isBold(role) { return Number(this.form[role].weight) >= 700; },
    toggleBold(role) { this.form[role].weight = this.isBold(role) ? 'auto' : '700'; },
    loadChosen() {
      const seen = new Set(Object.values(this.form).map(r => r.font).filter(f => f && f !== 'inherit'));
      seen.forEach(k => {
        const f = this.fontOf(k);
        if (!f || !f.link || document.getElementById('ty-font-' + k)) return;
        const l = document.createElement('link');
        l.id = 'ty-font-' + k; l.rel = 'stylesheet'; l.href = f.link;
        document.head.appendChild(l);
      });
    },
    payload() { const roles = {}; Object.keys(this.form).forEach(k => { roles[k] = { font: this.form[k].font, weight: String(this.form[k].weight), italic: this.form[k].italic ? 1 : 0 }; }); return { roles }; },
    done() { this.saved = true; setTimeout(() => window.location.reload(), 700); },
    save() {
      this.busy = true; this.errors = {};
      this.$inertia.put(this.route('settings.typography.update'), this.payload(), {
        preserveScroll: true, onSuccess: () => this.done(), onError: e => { this.errors = e; }, onFinish: () => { this.busy = false; },
      });
    },
    reset() {
      this.busy = true; this.errors = {};
      this.$inertia.delete(this.route('settings.typography.reset'), { preserveScroll: true, onSuccess: () => this.done(), onFinish: () => { this.busy = false; } });
    },
  },
};
</script>

<style scoped>
.ty-rows { display: grid; gap: 10px; margin-top: 4px; }
.ty-row { display: grid; grid-template-columns: minmax(0, 1.7fr) minmax(0, 1fr) auto; gap: 8px 12px; align-items: end; padding: 12px 14px; border-radius: 16px; background: var(--lg-card, #fff); box-shadow: 0 0 0 1px var(--lg-line, #e3e9f2), 0 12px 20px -16px rgba(31, 70, 130, 0.5); }
.ty-row.is-base { background: linear-gradient(135deg, rgba(79, 70, 229, 0.07), transparent 70%), var(--lg-card, #fff); box-shadow: 0 0 0 1.5px rgba(79, 70, 229, 0.4), 0 14px 22px -16px rgba(79, 70, 229, 0.6); }
.ty-name { grid-column: 1 / -1; display: flex; flex-wrap: wrap; align-items: baseline; gap: 2px 10px; min-width: 0; } .ty-name small { font-size: 0.74rem; color: var(--lg-mute, #64748b); }
.ty-f { display: grid; gap: 5px; min-width: 0; } .ty-f > label { font-size: 0.64rem; font-weight: 800; letter-spacing: 0.07em; text-transform: uppercase; color: var(--lg-faint, #94a3b8); }
.ty-toggles { display: flex; gap: 8px; }
.ty-tg { width: 42px; height: 42px; border: 0; border-radius: 12px; cursor: pointer; font-size: 1rem; color: var(--lg-ink, #0f2540); background: var(--lg-card, #fff); box-shadow: 0 0 0 1px var(--lg-line, #e3e9f2), 0 8px 12px -8px rgba(31, 70, 130, 0.6); transition: transform 0.15s ease, box-shadow 0.15s ease; }
.ty-tg:hover { transform: translateY(-1px); } .ty-tg.is-on { color: #fff; background: linear-gradient(135deg, #4f46e5, #3730a3); box-shadow: 0 10px 16px -8px rgba(79, 70, 229, 0.8); }
.ty-tg:focus-visible { outline: 2px solid #4f46e5; outline-offset: 2px; }
.ty-sample { grid-column: 1 / -1; padding: 9px 12px; border-radius: 12px; background: var(--lg-soft, #f1f5f9); font-size: 0.95rem; overflow-wrap: anywhere; }
.ty-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px 18px; margin-top: 14px; }
.ty-note { display: flex; gap: 8px; align-items: flex-start; flex: 1 1 320px; margin: 0; font-size: 0.78rem; color: var(--lg-mute, #64748b); } .ty-note svg { flex: none; margin-top: 2px; }
.ty-btns { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.ty-err { display: block; margin-top: 8px; color: #b91c1c; font-size: 0.8rem; }
@media (max-width: 520px) { .ty-row { grid-template-columns: 1fr 1fr; } .ty-font { grid-column: 1 / -1; } .ty-style { grid-column: 1 / -1; } }
@media (max-width: 480px) { .ty-btns { width: 100%; } .ty-btns .lg-btn { flex: 1 1 100%; justify-content: center; } }
</style>

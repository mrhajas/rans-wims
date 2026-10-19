<template>
  <admin-layout :title="$t('QR Code Settings')">
    <div class="lg-page px-4 md:px-0 qst">
      <page-header icon="qr-code" :title="$t('QR Code Settings')" :subtitle="$t('How QR codes look, print and behave across WIMS. Every record gets its code automatically, the first time it is asked for.')">
        <Link :href="route('qr-codes.activation')" class="lg-btn lg-btn-soft"><icons name="toggle" class="w-4 h-4" />{{ $t('QR Activation') }}</Link>
        <Link :href="route('qr-codes.scans')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('QR Scans') }}</Link>
      </page-header>

      <stat-tiles :items="tiles" />

      <div class="qst-grid">
        <form class="lg-card" @submit.prevent="save">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="cog" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Appearance and printing') }}</h2><p class="lg-card-sub">{{ $t('Applies to the screen, the label sheet and the QR on printed reports.') }}</p></div></header>

          <div class="qst-switches">
            <label v-for="s in switches" :key="s.key" class="qst-sw"><input v-model="form[s.key]" type="checkbox" /><span class="qst-track"></span><span class="qst-sw-t"><b>{{ s.label }}</b><small>{{ s.hint }}</small></span></label>
          </div>

          <div class="qst-fields">
            <div class="qst-f"><label>{{ $t('Size on screen') }}</label><select v-model="form.preview"><option value="small">{{ $t('Small') }}</option><option value="medium">{{ $t('Medium') }}</option><option value="large">{{ $t('Large') }}</option></select></div>
            <div class="qst-f"><label>{{ $t('Size on paper (mm)') }}</label><input v-model.number="form.print_mm" type="number" min="25" max="80" step="5" /></div>
            <div class="qst-f"><label>{{ $t('Labels per print') }}</label><input v-model.number="form.copies" type="number" min="1" max="60" /></div>
            <div class="qst-f"><label>{{ $t('Labels per row') }}</label><input v-model.number="form.columns" type="number" min="1" max="6" /></div>
            <div class="qst-f"><label>{{ $t('Damage tolerance') }}</label><select v-model="form.error_correction"><option value="L">{{ $t('Low (smallest code)') }}</option><option value="M">{{ $t('Medium') }}</option><option value="Q">{{ $t('High (recommended)') }}</option><option value="H">{{ $t('Highest (for rough surfaces)') }}</option></select></div>
            <div class="qst-f"><label>{{ $t('Code colour') }}</label><div class="qst-color"><input v-model="form.color" type="color" /><code>{{ form.color }}</code></div></div>
          </div>
          <p class="qst-hint"><icons name="info" class="w-4 h-4" />{{ $t('Keep the colour dark on a white background. Light colours may not scan.') }}</p>

          <div class="qst-actions"><button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing"><icons name="tick" class="w-4 h-4" />{{ form.processing ? $t('Saving...') : $t('Save settings') }}</button></div>
        </form>

        <div class="qst-side">
          <section class="lg-card qst-prev">
            <h2 class="lg-card-title">{{ $t('How a label looks') }}</h2>
            <div class="qst-label" :style="{ borderRadius: form.frame ? '14px' : '0', boxShadow: form.frame ? '0 0 0 2px ' + form.color : 'none' }">
              <img :src="preview" alt="" :style="{ width: sizePx + 'px', height: sizePx + 'px' }" />
              <template v-if="form.caption"><small>{{ $t('Transfer') }}</small><b>RST-0042</b><span v-if="form.show_company">RANS Demo Co.</span></template>
            </div>
            <p class="qst-note">{{ $t('Sample only: this code is not linked to any record.') }}</p>
          </section>

          <section class="lg-card">
            <header class="qst-ch">
              <h2 class="lg-card-title">{{ $t('Codes and scans') }}</h2>
              <button v-if="byType.length > 5" type="button" class="qst-x lg-tip" :data-tip="typesOpen ? $t('Collapse') : $t('Expand')" :aria-expanded="typesOpen ? 'true' : 'false'" :aria-label="typesOpen ? $t('Collapse') : $t('Expand')" @click="typesOpen = !typesOpen"><icons name="chevron-down" class="w-4 h-4" :class="{ 'is-open': typesOpen }" /></button>
            </header>
            <ul v-if="byType.length" class="qst-types">
              <li v-for="t in shownTypes" :key="t.type"><span>{{ t.label }}</span><em>{{ t.codes }} {{ $t('codes') }}</em><b>{{ t.scans }} {{ $t('scans') }}</b></li>
            </ul>
            <p v-if="byType.length > 5" class="qst-more">{{ typesOpen ? $t('Showing all') + ' ' + byType.length : $t('Showing 5 of') + ' ' + byType.length }}</p>
            <p v-else-if="!byType.length" class="qst-note">{{ $t('No code has been made yet. They appear as records are opened or printed.') }}</p>
          </section>
        </div>
      </div>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-teal"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Latest scans') }}</h2><p class="lg-card-sub">{{ $t('Every scan, refusal, download, print and change is also kept in the System Logs under QR Codes.') }}</p></div></header>
        <ul v-if="recent.length" class="qst-recent"><li v-for="(r, i) in recent" :key="i"><span class="qst-dot"></span><span class="min-w-0"><b>{{ r.reference || r.kind }}</b><small>{{ r.kind }} · {{ r.status_label }}</small></span><span class="qst-by">{{ r.by }}</span><em>{{ r.at }}</em></li></ul>
        <p v-else class="qst-note">{{ $t('Nobody has scanned a code yet.') }}</p>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { AdminLayout, PageHeader, StatTiles, Link },
  props: { qr: Object, stats: Object, byType: Array, recent: Array, preview: String },
  data() {
    return { form: this.$inertia.form({ ...this.qr }), typesOpen: false };
  },
  computed: {
    tiles() {
      return [
        { icon: 'qr-code', tone: 'indigo', label: this.$t('Active codes'), value: this.stats.codes },
        { icon: 'camera', tone: 'sky', label: this.$t('Scans'), value: this.stats.scans },
        { icon: 'refresh', tone: 'amber', label: this.$t('Replaced codes'), value: this.stats.replaced },
        { icon: 'collection', tone: 'emerald', label: this.$t('Kinds of record'), value: this.stats.kinds },
      ];
    },
    switches() {
      return [
        { key: 'enabled', label: this.$t('QR codes are on'), hint: this.$t('Turns the QR button, labels and the scanner on or off. Printed documents keep their normal address when off.') },
        { key: 'on_prints', label: this.$t('Put the code on printed reports'), hint: this.$t('Prints and PDFs carry a scan-to-open code instead of a plain address.') },
        { key: 'caption', label: this.$t('Show the reference under the code'), hint: this.$t('The kind of record and its number on every label.') },
        { key: 'show_company', label: this.$t('Show the company name'), hint: this.$t('Under the reference on labels.') },
        { key: 'frame', label: this.$t('Frame around labels'), hint: this.$t('A thin rounded border, useful for cutting labels out.') },
      ];
    },
    shownTypes() {
      return this.typesOpen ? this.byType : this.byType.slice(0, 5);
    },
    sizePx() {
      return { small: 110, medium: 150, large: 190 }[this.form.preview] || 150;
    },
  },
  methods: {
    save() {
      this.form.put(route('qr-codes.settings.update'), { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.qst-grid { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap: 1rem; align-items: start; margin-bottom: 1rem; }
@media (max-width: 1023.98px) { .qst-grid { grid-template-columns: minmax(0, 1fr); } }
.qst-side { display: grid; gap: 1rem; }
.qst-switches { display: grid; gap: 0.5rem; margin-bottom: 1rem; }
.qst-sw { display: flex; align-items: center; gap: 0.8rem; padding: 0.65rem 0.8rem; border-radius: 14px; cursor: pointer; background: var(--lg-card); box-shadow: 0 10px 16px -14px rgba(15, 37, 64, 0.55), 0 0 0 1px var(--lg-line); }
.qst-sw input { position: absolute; opacity: 0; pointer-events: none; }
.qst-track { flex: none; position: relative; width: 42px; height: 24px; border-radius: 9999px; background: #cbd5e1; transition: background-color 0.2s ease; box-shadow: inset 0 1px 3px rgba(15, 37, 64, 0.3); }
.qst-track::after { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 9999px; background: #fff; box-shadow: 0 2px 5px rgba(15, 37, 64, 0.35); transition: transform 0.2s ease; }
.qst-sw input:checked + .qst-track { background: linear-gradient(145deg, #34d399, #059669); } .qst-sw input:checked + .qst-track::after { transform: translateX(18px); } .qst-sw input:focus-visible + .qst-track { outline: 3px solid rgba(99, 102, 241, 0.4); outline-offset: 2px; }
.qst-sw-t { display: grid; min-width: 0; line-height: 1.3; } .qst-sw-t b { font-size: 0.86rem; color: var(--lg-ink); } .qst-sw-t small { font-size: 0.74rem; color: var(--lg-faint); }
.qst-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 0.8rem; }
.qst-f { display: grid; gap: 4px; min-width: 0; } .qst-f label { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.qst-f :is(select, input[type='number']) { width: 100%; height: 42px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-ink); font-size: 0.88rem; box-shadow: 0 10px 16px -12px rgba(15, 37, 64, 0.55); }
.qst-color { display: flex; align-items: center; gap: 10px; height: 42px; } .qst-color input { width: 52px; height: 38px; padding: 2px; border: 1px solid var(--lg-line); border-radius: 10px; background: var(--lg-card); box-shadow: 0 8px 14px -10px rgba(15, 37, 64, 0.55); cursor: pointer; } .qst-color code { font-size: 0.82rem; color: var(--lg-mute); }
.qst-hint { display: flex; gap: 8px; margin: 0.8rem 0 0; font-size: 0.76rem; color: var(--lg-faint); } .qst-hint svg { flex: none; margin-top: 1px; }
.qst-actions { margin-top: 1rem; display: flex; }
.qst-prev { display: grid; justify-items: center; gap: 0.6rem; }
.qst-label { display: grid; justify-items: center; gap: 3px; padding: 14px; background: #fff; max-width: 100%; } .qst-label img { image-rendering: pixelated; max-width: 100%; height: auto; }
.qst-label small { font-size: 0.6rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: #475569; } .qst-label b { font-size: 0.95rem; color: #111827; } .qst-label span { font-size: 0.68rem; color: #64748b; }
.qst-ch { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; margin-bottom: 0.7rem; }
.qst-x { display: inline-grid; place-items: center; width: 34px; height: 34px; border-radius: 11px; color: var(--lg-mute); background: var(--lg-card); box-shadow: 0 8px 14px -8px rgba(15, 37, 64, 0.55), 0 0 0 1px var(--lg-line); transition: color 0.15s ease; } .qst-x:hover { color: #4338ca; } .qst-x svg { transition: transform 0.25s ease; } .qst-x svg.is-open { transform: rotate(180deg); }
.qst-more { margin: 0.6rem 0 0; font-size: 0.72rem; text-align: center; color: var(--lg-faint); }
.qst-note { margin: 0; font-size: 0.78rem; color: var(--lg-faint); }
.qst-types, .qst-recent { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
.qst-types li { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; gap: 0.7rem; align-items: center; padding: 7px 10px; border-radius: 12px; background: var(--lg-card); box-shadow: 0 8px 12px -10px rgba(15, 37, 64, 0.5), 0 0 0 1px var(--lg-line); font-size: 0.82rem; } .qst-types em { font-style: normal; color: var(--lg-faint); font-size: 0.72rem; } .qst-types b { color: #4338ca; }
.qst-recent li { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 13px; background: var(--lg-card); box-shadow: 0 8px 12px -10px rgba(15, 37, 64, 0.5), 0 0 0 1px var(--lg-line); }
.qst-recent .min-w-0 { flex: 1; display: grid; line-height: 1.25; } .qst-recent b { font-size: 0.84rem; color: var(--lg-ink); } .qst-recent small, .qst-by, .qst-recent em { font-size: 0.72rem; color: var(--lg-faint); font-style: normal; } .qst-recent em { white-space: nowrap; }
.qst-dot { flex: none; width: 9px; height: 9px; border-radius: 9999px; background: linear-gradient(145deg, #34d399, #0ea5e9); }
</style>

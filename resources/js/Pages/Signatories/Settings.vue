<template>
  <admin-layout :title="$t('Signatory Settings')">
    <div class="lg-page sb px-4 md:px-0">
      <sb-hero icon="cog" :title="$t('Signatory Settings')" :subtitle="$t('The standards for signature pictures, who may register and verify them, how long originals are kept and how signatures look on reports.')">
        <Link :href="route('signatories.index')" class="sb-gbtn"><icons name="edit" class="w-4 h-4" />{{ $t('Signatories') }}</Link>
        <Link v-if="can.workflow" :href="route('signatories.workflow')" class="sb-gbtn"><icons name="bell" class="w-4 h-4" />{{ $t('Alerts') }}</Link>
      </sb-hero>
      <form @submit.prevent="save">
        <section v-for="(g, gi) in groups" :key="g.title" class="sb-card mb-4" :style="{ '--i': gi }">
          <h3 class="sb-card__title"><span class="sb-ico" :class="g.tone"><icons :name="g.icon" class="w-4 h-4" /></span>{{ g.title }}</h3>
          <div class="sg-set">
            <template v-for="f in g.fields" :key="f.k">
              <label v-if="f.t === 'bool'" class="ss-toggle ss-wide"><input v-model="form[f.k]" type="checkbox" /><span class="ss-switch"></span><span><b>{{ f.l }}</b><small>{{ f.h }}</small></span></label>
              <div v-else-if="f.t === 'select'" class="sb-field"><label>{{ f.l }}</label><auto-complete v-model="form[f.k]" :suggestions="f.o" /><small class="sg-hint">{{ f.h }}</small></div>
              <div v-else-if="f.t === 'formats'" class="sb-field sb-wide"><label>{{ f.l }}</label><div class="sg-chips"><button v-for="x in ['jpg', 'jpeg', 'png', 'webp']" :key="x" type="button" class="sb-chip" :class="{ 'is-on': form.allowed_formats.includes(x) }" @click="toggleFormat(x)">{{ x.toUpperCase() }}</button></div><small class="sg-hint">{{ f.h }}</small></div>
              <div v-else-if="f.t === 'color'" class="sb-field"><label>{{ f.l }}</label><div class="sg-color"><input v-model="form[f.k]" type="color" /><input v-model="form[f.k]" type="text" class="sb-input" maxlength="7" /></div><small class="sg-hint">{{ f.h }}</small></div>
              <div v-else class="sb-field"><label :for="'f' + f.k">{{ f.l }}</label><input :id="'f' + f.k" v-model="form[f.k]" :type="f.t === 'text' ? 'text' : 'number'" :step="f.step || 1" class="sb-input" /><small class="sg-hint">{{ f.h }}</small></div>
            </template>
          </div>
        </section>
        <div v-if="form.errors && Object.keys(form.errors).length" class="ss-err"><p v-for="(e, k) in form.errors" :key="k">{{ e }}</p></div>
        <div class="sg-actions">
          <button type="submit" class="sb-btn is-primary" :disabled="form.processing"><icons name="tick" class="w-4 h-4" />{{ $t('Save settings') }}</button>
          <button type="button" class="sb-btn" @click="reset"><icons name="refresh" class="w-4 h-4" />{{ $t('Restore defaults') }}</button>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import SbHero from '@/Shared/SmartBrief/SbHero.vue';

export default {
  components: { AdminLayout, Link, AutoComplete, SbHero },
  props: { prefs: Object, defaults: Object, can: Object },
  data() { return { form: this.$inertia.form({ ...this.prefs, version_retention: String(this.prefs.version_retention) }) }; },
  computed: {
    groups() {
      const t = k => this.$t(k);
      return [
        { title: t('The signature picture'), icon: 'sparkles', tone: 'g-violet', fields: [
          { k: 'ink_color', t: 'color', l: t('Ink colour'), h: t('Every signature is saved in this colour. Blue by default.') },
          { k: 'output_width_px', l: t('Widest picture saved (px)'), h: t('Never enlarged. 900 gives a sharp print at the usual signature size.') },
          { k: 'padding_pct', l: t('Transparent border (%)'), h: t('Space around the writing, as a share of its size.'), step: 0.5 },
          { k: 'sensitivity', l: t('Background removal strength'), h: t('0.6 keeps fainter strokes, 1.6 removes more background. Each person can still adjust it when registering.'), step: 0.1 },
          { k: 'min_contrast', l: t('Smallest ink contrast'), h: t('Pictures with fainter ink than this are refused so a misleading result is never made.'), step: 0.01 },
          { k: 'min_ink_coverage_pct', l: t('Least writing found (%)'), h: t('Below this the picture is refused as having too little writing.'), step: 0.01 },
          { k: 'max_ink_coverage_pct', l: t('Most dark area (%)'), h: t('Above this the picture is refused as too dark to separate the writing.'), step: 1 },
        ] },
        { title: t('What may be sent in'), icon: 'upload', tone: 'g-navy', fields: [
          { k: 'allowed_formats', t: 'formats', l: t('Allowed picture types'), h: t('The file is also checked by its real content, not only its name.') },
          { k: 'max_upload_mb', l: t('Largest file (MB)'), h: '' }, { k: 'min_input_px', l: t('Smallest picture (px, longest side)'), h: '' }, { k: 'max_input_px', l: t('Largest picture (px, longest side)'), h: '' },
          { k: 'camera_enabled', t: 'bool', l: t('Allow the camera'), h: t('People can photograph a signature on paper. The camera is only used with the browser\'s permission and nothing is recorded but the picture they confirm.') },
          { k: 'draw_enabled', t: 'bool', l: t('Allow drawing on screen'), h: t('With finger, stylus or mouse.') },
        ] },
        { title: t('Registering, verifying and using'), icon: 'tick', tone: 'g-green', fields: [
          { k: 'self_registration', t: 'select', l: t('Who may register'), h: t('Everyone for their own signature, or only people who may register for others.'), o: [{ value: 'self', label: t('Every user, for their own signature') }, { value: 'admin', label: t('Only people who may register for others') }] },
          { k: 'verification_required', t: 'bool', l: t('A different person must verify'), h: t('A new signature is pending until somebody other than its owner verifies it. Nothing uploaded is used on a report before that.') },
          { k: 'consent_required', t: 'bool', l: t('The signatory must confirm the signature is theirs'), h: t('They also allow WIMS to use it on the documents where they sign. If somebody registers it for them, they still have to confirm it.') },
          { k: 'apply_to_older_records', t: 'bool', l: t('Show a signature on records that were finished before it was registered'), h: t('When on, an older approved record shows the signer\'s signature now in use, marked "signature applied after the action" with the date it was registered. When off, only a signature that was on file when the action was taken is shown.') },
          { k: 'reauth_sensitive', t: 'bool', l: t('Ask for the password again on sensitive changes'), h: t('Verifying, suspending, revoking and reinstating.') },
          { k: 'default_missing_behaviour', t: 'select', l: t('When a required signature is missing'), h: t('Blank: keep the blank signature line. Flag: blank line and record it on the dashboard. Block: also stop the record being finalised (where the document is set up that way).'), o: [{ value: 'blank', label: t('Keep the blank line') }, { value: 'flag', label: t('Blank line and flag it') }, { value: 'block', label: t('Block finalising') }] },
        ] },
        { title: t('Keeping pictures'), icon: 'lock-closed', tone: 'g-rose', fields: [
          { k: 'original_retention_days', l: t('Keep the original photo for (days)'), h: t('0 deletes the original as soon as the signature is saved. Originals are only visible to people with that permission.') },
          { k: 'version_retention', t: 'text', l: t('Versions kept per person'), h: t('"all" keeps every version (recommended). A number keeps only the newest ones, never the active one.') },
        ] },
        { title: t('On reports'), icon: 'printer', tone: 'g-amber', fields: [
          { k: 'report_signature_width_mm', l: t('Signature width on a report (mm)'), h: '', step: 1 }, { k: 'report_signature_height_mm', l: t('Signature box height (mm)'), h: '', step: 1 },
          { k: 'date_format', t: 'select', l: t('Date format'), h: '', o: ['d M Y', 'd/m/Y', 'Y-m-d', 'j F Y'].map(v => ({ value: v, label: v })) },
          { k: 'time_format', t: 'select', l: t('Time format'), h: '', o: ['H:i', 'g:i A', 'H:i:s'].map(v => ({ value: v, label: v })) },
          { k: 'show_name', t: 'bool', l: t('Print the name'), h: '' }, { k: 'show_designation', t: 'bool', l: t('Print the designation'), h: t('From the staff record.') },
          { k: 'show_datetime', t: 'bool', l: t('Print the date and time of the action'), h: t('The date and time the person really did the action, never the time the report was made.') },
          { k: 'show_on_draft', t: 'bool', l: t('Show signatures on draft reports'), h: t('Off: a draft shows only blank lines and a DRAFT mark.') },
        ] },
      ];
    },
  },
  methods: {
    toggleFormat(x) { const a = this.form.allowed_formats; this.form.allowed_formats = a.includes(x) ? a.filter(y => y !== x) : [...a, x]; },
    save() { this.form.put(route('signatories.settings.update'), { preserveScroll: true }); },
    reset() { Object.assign(this.form, this.defaults, { version_retention: String(this.defaults.version_retention) }); },
  },
};
</script>

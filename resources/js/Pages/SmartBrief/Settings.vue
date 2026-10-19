<template>
  <admin-layout :title="$t('Smart Brief Settings')">
    <div class="lg-page sb sc-ui px-4 md:px-0">
      <sb-hero icon="cog" :title="$t('Smart Brief Settings')" :subtitle="$t('Defaults and limits. Who may see what is set on the Roles screen.')">
        <Link :href="route('smart-brief.management.index')" class="sb-gbtn"><icons name="group" class="w-4 h-4" />{{ $t('Management Smart Brief') }}</Link>
        <Link :href="route('smart-brief.workflow')" class="sb-gbtn"><icons name="bell" class="w-4 h-4" />{{ $t('Alerts') }}</Link>
      </sb-hero>

      <form class="sb-card ss-card" style="--i: 0" @submit.prevent="save">
        <div class="ss-grid">
          <div class="ss-field">
            <label for="ss-per">{{ $t('Default period') }}</label>
            <auto-complete id="ss-per" v-model="form.default_period" :suggestions="periodOptions" :placeholder="$t('Choose a period...')" />
            <small>{{ $t('What opens first. People can switch to any other period.') }}</small>
          </div>
          <div class="ss-field">
            <label for="ss-max">{{ $t('Longest range (days)') }}</label>
            <input id="ss-max" v-model.number="form.max_range_days" type="number" min="1" max="366" />
            <small>{{ $t('Limits custom ranges so a brief stays fast and readable.') }}</small>
          </div>
          <div class="ss-field">
            <label for="ss-lim">{{ $t('Timeline limit') }}</label>
            <input id="ss-lim" v-model.number="form.timeline_limit" type="number" min="50" max="2000" step="50" />
            <small>{{ $t('Most entries shown in one timeline. The brief says when the limit was reached.') }}</small>
          </div>
          <div class="ss-field">
            <label for="ss-clr">{{ $t('Clear Smart Brief log every (days)') }}</label>
            <input id="ss-clr" v-model.number="form.auto_clear_days" type="number" min="0" max="3650" />
            <small>{{ $t('Smart Brief\'s own log lines (a brief opened, a report previewed or exported, settings changed) older than this are removed automatically every day. 0 keeps them for ever. Records of real work are never touched.') }}</small>
          </div>
          <label class="ss-toggle ss-wide">
            <input v-model="form.show_line_items" type="checkbox" /><span class="ss-switch"></span>
            <span><b>{{ $t('Show line entries in the timeline by default') }}</b><small>{{ $t('Line entries are the rows inside a record (items, recipients, attachments). Off keeps the timeline focused on the record itself.') }}</small></span>
          </label>
          <label class="ss-toggle ss-wide">
            <input v-model="form.ai_enabled" type="checkbox" :disabled="!aiAvailable" /><span class="ss-switch"></span>
            <span><b>{{ $t('Let ARIS polish the wording of summaries') }}</b><small>{{ aiAvailable ? $t('Only permitted facts are passed on, and the wording is checked so it adds nothing that is not in the records.') : $t('ARIS is not available yet. Summaries use the built-in wording, which is built only from the records.') }}</small></span>
          </label>
          <div class="ss-field ss-wide">
            <label for="ss-ex">{{ $t('Activity logs left out (one per line)') }}</label>
            <textarea id="ss-ex" v-model="excluded" rows="4"></textarea>
            <small>{{ $t('Bookkeeping logs that are not something a person did. Defaults:') }} {{ defaults.excluded_log_names.join(', ') }}</small>
          </div>
        </div>
        <div v-if="form.errors && Object.keys(form.errors).length" class="ss-err"><p v-for="(e, k) in form.errors" :key="k">{{ e }}</p></div>
        <div class="ss-actions">
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
  props: { prefs: Object, periods: Array, aiAvailable: Boolean, defaults: Object },
  data() {
    return {
      form: this.$inertia.form({ default_period: this.prefs.default_period, max_range_days: this.prefs.max_range_days, timeline_limit: this.prefs.timeline_limit, show_line_items: !!this.prefs.show_line_items, auto_clear_days: this.prefs.auto_clear_days, ai_enabled: !!this.prefs.ai_enabled && this.aiAvailable, excluded_log_names: [] }),
      excluded: (this.prefs.excluded_log_names || []).join('\n'),
    };
  },
  computed: { periodOptions() { return this.periods.map(p => ({ value: p.key, label: this.$t(p.label) })); } },
  methods: {
    save() {
      this.form.excluded_log_names = this.excluded.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
      this.form.put(route('smart-brief.settings.update'), { preserveScroll: true });
    },
    reset() {
      const d = this.defaults;
      Object.assign(this.form, { default_period: d.default_period, max_range_days: d.max_range_days, timeline_limit: d.timeline_limit, show_line_items: !!d.show_line_items, auto_clear_days: d.auto_clear_days, ai_enabled: false });
      this.excluded = d.excluded_log_names.join('\n');
    },
  },
};
</script>

<template>
  <admin-layout :title="$t('Weather Warnings')">
    <div class="lg-page sb wx sc-ui px-4 md:px-0">
      <wx-header current="warnings" :title="$t('Weather Warnings')" :subtitle="$t('Warnings say forecast conditions may be unsuitable for an activity. They are advice to review, they never approve or forbid work, and acknowledging one changes no work package.')">
        <wx-export :project="f.project" :from="f.from" :to="f.to" />
      </wx-header>

      <section class="sb-card" style="--i: 0">
        <div class="wx-form">
          <div class="sb-field"><label>{{ $t('Project') }}</label><auto-complete v-model="f.project" :suggestions="projectOptions" :placeholder="$t('All projects')" clearable /></div>
          <div class="sb-field"><label>{{ $t('Kind') }}</label><auto-complete v-model="f.kind" :suggestions="kindOptions" :placeholder="$t('All kinds')" clearable /></div>
          <div class="sb-field"><label>{{ $t('Raised from') }}</label><date-picker v-model="f.from" :max="f.to || undefined" clearable /></div>
          <div class="sb-field"><label>{{ $t('Raised to') }}</label><date-picker v-model="f.to" :min="f.from || undefined" clearable /></div>
        </div>
        <div class="sb-periods" role="group" :aria-label="$t('Status')" style="margin-top: 0.8rem">
          <button v-for="s in statuses" :key="s.key" type="button" class="sb-chip" :class="{ 'is-on': f.status === s.key }" @click="f.status = s.key">{{ s.label }}</button>
        </div>
      </section>

      <ul v-if="warnings.data.length" class="wx-list" style="margin-top: 0.9rem">
        <li v-for="w in warnings.data" :key="w.id" :id="'wx-warning-' + w.id" class="wx-item" :class="[{ 'is-open': w.status === 'open', 'is-focus': focus === w.id }, 'is-' + w.severity]">
          <div class="wx-item__top">
            <span class="wx-chip" :class="'is-' + w.status">{{ statusWord(w.status) }}</span>
            <span class="wx-chip" :class="'is-' + w.severity">{{ w.severity === 'warning' ? $t('Warning') : $t('Caution') }}</span>
            <span class="wx-chip is-forecast">{{ w.kind === 'provider_alert' ? $t('Provider alert') : $t('Forecast') }}</span>
            <span v-if="w.starting_values" class="wx-chip is-starting lg-tip" :data-tip="w.notice">{{ $t('Starting values') }}</span>
          </div>
          <div class="wx-item__top"><b>{{ w.title }}</b></div>
          <p><b>{{ w.project }}</b></p>
          <p>{{ w.message }}</p>
          <div class="wx-meta">
            <span>{{ $t('Window') }}: {{ dt(w.window_start) }}<template v-if="w.window_end">&nbsp;{{ $t('to') }} {{ dt(w.window_end) }}</template></span>
            <span>{{ $t('Raised') }}: {{ dt(w.raised_at) }}</span>
            <span v-if="w.provider">{{ $t('Source') }}: {{ w.provider }}</span>
            <span v-if="w.work_package">{{ $t('Scheduled work') }}: {{ w.work_package }}</span>
          </div>
          <p v-if="w.uncertainty" class="wx-note"><b>{{ $t('Uncertainty') }}:</b> {{ w.uncertainty }}</p>
          <p v-if="w.reopened_because" class="wx-note">{{ w.reopened_because }}</p>
          <p v-if="w.expired_reason" class="wx-note">{{ w.expired_reason }}</p>
          <div v-if="w.acknowledged_by" class="wx-ack">
            <div class="wx-meta">
              <span><b>{{ w.status === 'dismissed' ? $t('Marked not applicable by') : $t('Acknowledged by') }}</b> {{ w.acknowledged_by }}</span>
              <span>{{ dt(w.acknowledged_at) }}</span>
              <span><b>{{ $t('Decision') }}:</b> {{ w.acknowledge_decision }}</span>
            </div>
            <p v-if="w.acknowledge_note" class="wx-note">{{ w.acknowledge_note }}</p>
            <p class="wx-note">{{ $t('This record shows who saw the warning and what they decided. It did not change any work.') }}</p>
          </div>
          <template v-if="w.status === 'open' && can.acknowledge">
            <div v-if="open === w.id" class="wx-ack">
              <div class="sb-field"><label>{{ $t('What did you decide?') }}</label><auto-complete v-model="ack.decision" :suggestions="decisionOptions" :placeholder="$t('Choose or type a decision...')" freeform /></div>
              <div class="sb-field"><label>{{ $t('Note') }}</label><textarea v-model="ack.note" class="wx-textarea" rows="3" maxlength="1000" :placeholder="$t('Optional, needed for Not applicable or your own decision')"></textarea></div>
              <p v-if="ackError" class="wx-error" role="alert">{{ ackError }}</p>
              <div class="wx-actions">
                <button type="button" class="sb-btn is-primary" :disabled="busy || !ack.decision" @click="send(w, 'acknowledged')"><icons name="tick" class="w-4 h-4" />{{ $t('Acknowledge') }}</button>
                <button type="button" class="sb-btn" :disabled="busy || !ack.decision" @click="send(w, 'dismissed')">{{ $t('Not applicable') }}</button>
                <button type="button" class="sb-btn" @click="open = null">{{ $t('Cancel') }}</button>
              </div>
            </div>
            <div v-else class="wx-actions"><button type="button" class="sb-btn is-primary" @click="start(w)"><icons name="tick" class="w-4 h-4" />{{ $t('Acknowledge') }}</button></div>
          </template>
        </li>
      </ul>
      <p v-else class="wx-state" style="margin-top: 0.9rem">{{ $t('No weather warnings match.') }}</p>

      <div v-if="warnings.meta.last_page > 1" class="wx-pager">
        <span>{{ $t('Page') }} {{ warnings.meta.current_page }} / {{ warnings.meta.last_page }}</span>
        <span class="wx-actions">
          <button type="button" class="sb-btn" :disabled="warnings.meta.current_page <= 1" @click="go(warnings.meta.current_page - 1)">{{ $t('Previous') }}</button>
          <button type="button" class="sb-btn" :disabled="warnings.meta.current_page >= warnings.meta.last_page" @click="go(warnings.meta.current_page + 1)">{{ $t('Next') }}</button>
        </span>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import WxExport from './WxExport.vue';
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import WxHeader from '@/Shared/Weather/WxHeader.vue';
import { dateTime } from '@/Shared/Weather/wxFormat';

export default {
  components: { WxExport, AdminLayout, AutoComplete, DatePicker, WxHeader },
  props: { warnings: Object, filters: Object, projectOptions: Array, decisions: Array, counts: Number, can: Object, focus: { type: Number, default: null } },
  data() {
    return { f: { project: this.filters.project ? String(this.filters.project) : '', kind: this.filters.kind || '', from: this.filters.from || '', to: this.filters.to || '', status: this.filters.status }, open: null, ack: { decision: '', note: '' }, ackError: '', busy: false, ready: false };
  },
  computed: {
    kindOptions() { return [{ value: 'forecast_rule', label: this.$t('Forecast against thresholds') }, { value: 'provider_alert', label: this.$t('Provider alert') }]; },
    decisionOptions() { return this.decisions.map(d => ({ value: d, label: d })); },
    statuses() {
      return [{ key: 'active', label: this.$t('Open and acknowledged') + (this.counts ? ' (' + this.counts + ' ' + this.$t('open') + ')' : '') }, { key: 'open', label: this.$t('Open') }, { key: 'acknowledged', label: this.$t('Acknowledged') },
        { key: 'expired', label: this.$t('Expired') }, { key: 'dismissed', label: this.$t('Not applicable') }, { key: 'all', label: this.$t('All') }];
    },
  },
  watch: { f: { deep: true, handler() { if (this.ready) this.go(1); } } },
  mounted() {
    this.$nextTick(() => { this.ready = true; });
    if (this.focus) { const el = document.getElementById('wx-warning-' + this.focus); if (el) el.scrollIntoView({ block: 'center' }); }
  },
  methods: {
    dt(s) { return dateTime(s); },
    statusWord(s) { return { open: this.$t('Open'), acknowledged: this.$t('Acknowledged'), expired: this.$t('Expired'), dismissed: this.$t('Not applicable') }[s] || s; },
    go(page) {
      const q = { status: this.f.status };
      ['project', 'kind', 'from', 'to'].forEach(k => { if (this.f[k]) q[k] = this.f[k]; });
      if (page > 1) q.page = page;
      router.get(route('weather.warnings.index'), q, { preserveScroll: true, preserveState: true, only: ['warnings', 'filters', 'counts'] });
    },
    start(w) { this.open = w.id; this.ack = { decision: '', note: '' }; this.ackError = ''; },
    send(w, outcome) {
      this.busy = true; this.ackError = '';
      axios.post(route('weather.warnings.acknowledge', w.id), { outcome, decision: this.ack.decision, note: this.ack.note }).then(() => {
        this.open = null;
        router.reload({ only: ['warnings', 'counts'], preserveScroll: true });
      }).catch(e => {
        const d = e.response && e.response.data;
        this.ackError = (d && d.errors && Object.values(d.errors)[0][0]) || (d && d.message) || this.$t('This could not be recorded.');
      }).finally(() => { this.busy = false; });
    },
  },
};
</script>

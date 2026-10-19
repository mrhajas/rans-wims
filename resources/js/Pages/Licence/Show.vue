<template>
  <admin-layout :title="$t('WIMS Licence')">
    <div class="lg-page px-4 md:px-0 lic">
      <page-header icon="check-double" :title="$t('WIMS Licence')" :subtitle="$t('The licence this system runs on: validity, key and history.')">
        <a v-if="licence" :href="route('wims-licence.print')" target="_blank" rel="noopener" class="lg-btn lg-btn-primary"><icons name="printer" class="w-4 h-4" />{{ $t('Print licence report') }}</a>
        <Link v-if="can.manager" :href="route('licence-manager.index')" class="lg-btn lg-btn-soft"><icons name="lock-closed" class="w-4 h-4" />{{ $t('Licence Manager') }}</Link>
      </page-header>

      <template v-if="licence">
        <div v-if="licence.state === 'expiring'" class="lic-warnbar lic-rise"><icons name="exclamation" />{{ $t('This licence ends in') }} {{ licence.days_left }} {{ $t('day(s)') }}. {{ $t('Ask the Licence Manager for a renewal before') }} {{ $date(licence.expires_at) }}.</div>

        <licence-hero :licence="licence" :server-now="serverNow" class="lic-rise" />

        <div class="lic-tiles lic-tiles--six">
          <div v-for="(t, i) in tiles" :key="t.label" class="lic-tile lic-rise" :class="'c-' + t.c" :style="{ '--i': i + 1 }">
            <span class="lic-tile__ico"><icons :name="t.icon" /></span>
            <div class="min-w-0"><small>{{ $t(t.label) }}</small><b>{{ t.value }}</b></div>
          </div>
        </div>

        <licence-key :licence-key="licence.key" :blocks="licence.key_blocks" class="lic-rise" style="--i: 8" />

        <section class="lic-card lic-rise" style="--i: 9; --c: #4f46e5">
          <div class="lic-card__head"><span class="lic-card__ico"><icons name="refresh" /></span><div><h2>{{ $t('Apply a new licence') }}</h2><p>{{ $t('Paste the 128-character key, or upload the encrypted licence file sent by the Licence Manager.') }}</p></div></div>
          <div v-if="!can.update" class="lic-warnbar"><icons name="lock-closed" />{{ $t('You can view the licence but you do not have permission to apply a new one.') }}</div>
          <transition name="lic-res">
            <div v-if="result" :key="result.at" class="lic-result" :class="['k-' + result.tone, 'w-' + result.kind]" role="alert">
              <span class="lic-result__ico"><icons :name="result.icon" /></span>
              <div class="min-w-0"><b>{{ result.title }}</b><p>{{ result.text }}</p><small v-if="result.hint">{{ result.hint }}</small></div>
              <button type="button" class="lic-result__x" :aria-label="$t('Dismiss')" @click="result = null"><icons name="cross" /></button>
              <i class="lic-result__bar" :style="{ animationDuration: result.tone === 'ok' ? '12s' : '20s' }"></i>
            </div>
          </transition>
          <form v-if="can.update" class="lic-apply" @submit.prevent="apply">
            <div class="lic-box">
              <h3><icons name="key" />{{ $t('Paste the licence key') }}</h3>
              <textarea :value="form.licence_text" class="lic-textarea" :class="{ 'is-over': overflow }" rows="5" spellcheck="false" autocomplete="off" :placeholder="$t('Paste the 128-character licence key here')" @input="onKey"></textarea>
              <p v-if="overflow" class="lic-keywarn bad"><icons name="exclamation" />{{ $t('Only 128 characters are allowed. :n extra character(s) were removed.', { n: overflow }) }}</p>
              <p v-else-if="pasted > 0 && pasted < 128" class="lic-keywarn"><icons name="info" />{{ $t(':n more character(s) needed. A licence key has exactly 128.', { n: 128 - pasted }) }}</p>
              <p v-else-if="pasted === 128" class="lic-keywarn ok"><icons name="tick" />{{ $t('128 characters. Ready to verify.') }}</p>
              <div class="lic-counter" :class="{ ok: pasted === 128 }"><span>{{ pasted }} / 128</span><div class="bar"><span :style="{ width: Math.min(100, pasted / 128 * 100) + '%' }"></span></div><icons v-if="pasted === 128" name="tick" class="w-4 h-4" /></div>
            </div>
            <div class="lic-box">
              <h3><icons name="upload" />{{ $t('Upload the licence file') }}</h3>
              <label class="lic-drop" :class="{ over: over, has: fileName }" @dragover.prevent="over = true" @dragleave="over = false" @drop.prevent="onDrop">
                <input type="file" class="sr-only" accept=".wimslic,.txt" @change="onFile" />
                <icons :name="fileName ? 'tick' : 'upload'" />
                <b>{{ fileName || $t('Drop the .wimslic file here') }}</b>
                <small>{{ fileName ? $t('Ready to activate') : $t('or click to browse') }}</small>
              </label>
            </div>
            <div style="grid-column: 1 / -1">
              <p v-if="errors.licence && !result" class="lic-err">{{ errors.licence }}</p>
              <div class="mt-2 flex flex-wrap items-center gap-3">
                <button type="submit" class="lic-btn lic-btn--green" :disabled="busy || (!form.licence_text && !form.licence_file)"><icons :name="busy ? 'refresh' : 'tick'" :class="{ 'animate-spin': busy }" />{{ $t('Verify and activate licence') }}</button>
                <span class="text-xs text-slate-500">{{ $t('The current licence is replaced and kept in the history below.') }}</span>
              </div>
            </div>
          </form>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
          <section class="lic-card lic-rise" style="--i: 10; --c: #059669">
            <div class="lic-card__head"><span class="lic-card__ico"><icons name="list" /></span><div><h2>{{ $t('Licences on this system') }}</h2><p>{{ $t('Every licence applied here, newest first.') }}</p></div></div>
            <div class="lic-tbl-wrap"><table class="lic-tbl" style="min-width: 0">
              <thead><tr><th>{{ $t('No') }}</th><th>{{ $t('Licence') }}</th><th>{{ $t('From') }} → {{ $t('To') }}</th><th>{{ $t('Status') }}</th></tr></thead>
              <tbody>
                <tr v-for="(h, i) in history" :key="h.id" :class="'st-' + h.state">
                  <td class="num">{{ i + 1 }}</td>
                  <td><b>{{ h.number }}</b><span class="sub">{{ h.edition }} · {{ h.duration }}</span></td>
                  <td>{{ $date(h.starts_at) }} → {{ $date(h.expires_at) }}</td>
                  <td><span class="lic-pill" :class="{ live: ['active', 'expiring'].includes(h.state) }"><i></i>{{ stateLabel(h.state) }}</span></td>
                </tr>
              </tbody>
            </table></div>
          </section>
          <section class="lic-card lic-rise" style="--i: 11; --c: #d97706">
            <div class="lic-card__head"><span class="lic-card__ico"><icons name="clock" /></span><div><h2>{{ $t('History of this licence') }}</h2><p>{{ $t('Who did what, and when.') }}</p></div></div>
            <ul class="lic-events">
              <li v-for="e in shownEvents" :key="e.id" :class="'ev-' + e.event">
                <span class="ico"><icons :name="eventIcon(e.event)" /></span>
                <div class="min-w-0"><b>{{ eventLabel(e.event) }}</b><span>{{ e.note }}</span><span>{{ $t('by') }} {{ e.by }}</span></div>
                <time>{{ when(e.at) }}</time>
              </li>
              <li v-if="!events.length"><span>{{ $t('No history yet.') }}</span></li>
            </ul>
            <button v-if="events.length > 10" type="button" class="lic-more" @click="evOpen = !evOpen"><icons :name="evOpen ? 'chevron-up' : 'chevron-down'" />{{ evOpen ? $t('Collapse') : $t('Expand') + ' (' + (events.length - 10) + ' ' + $t('more') + ')' }}</button>
          </section>
        </div>

        <p class="mt-4 text-center text-xs text-slate-500">{{ $t('Questions about this licence? Contact') }} {{ contact }}.</p>
      </template>

      <section v-else class="lic-card lic-rise st-none" style="--c: #e11d48">
        <div class="lic-card__head"><span class="lic-card__ico"><icons name="lock-closed" /></span><div><h2>{{ $t('No WIMS licence found') }}</h2><p>{{ $t('Contact') }} {{ contact }}.</p></div></div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import LicenceHero from './LicenceHero.vue';
import LicenceKey from './LicenceKey.vue';

const EVENTS = {
  issued: ['Licence issued', 'plus'], activated: ['Licence activated', 'tick'], reactivated: ['Licence reactivated', 'refresh'], deactivated: ['Licence deactivated', 'lock-closed'],
  superseded: ['Replaced by a newer licence', 'duplicate'], edited: ['Licence edited', 'edit'], 'file-downloaded': ['Licence file downloaded', 'download'], imported: ['Licence file imported', 'upload'], printed: ['Report printed', 'printer'],
};

export default {
  components: { Link, AdminLayout, PageHeader, LicenceHero, LicenceKey },
  props: { licence: Object, history: Array, events: Array, can: Object, contact: String, serverNow: String },
  data() {
    return { form: { licence_text: '', licence_file: null }, fileName: '', over: false, busy: false, poller: null, result: null, resultTimer: null, overflow: 0, evOpen: false };
  },
  mounted() {
    this.poller = setInterval(() => { if (!document.hidden && !this.busy) router.reload({ only: ['licence', 'history', 'events', 'serverNow'], preserveScroll: true, preserveState: true }); }, 15000);
  },
  beforeUnmount() { clearInterval(this.poller); clearTimeout(this.resultTimer); },
  watch: {
    '$page.props.flash.licence_result': { immediate: true, handler(v) { if (v) this.showResult(v); } },
  },
  computed: {
    errors() { return this.$page.props.errors || {}; },
    pasted() { return (this.form.licence_text || '').length; },
    shownEvents() { return this.evOpen ? this.events : this.events.slice(0, 10); },
    tiles() {
      const l = this.licence;
      return [
        { icon: 'hash', c: 'indigo', label: 'Licence number', value: l.number },
        { icon: 'building', c: 'blue', label: 'Licensed to', value: l.licensee },
        { icon: 'star', c: 'violet', label: 'Edition', value: `${l.edition} · ${l.type}` },
        { icon: 'calendar', c: 'emerald', label: 'Valid from', value: this.$date(l.starts_at) },
        { icon: 'calendar', c: 'rose', label: 'Valid until', value: this.$date(l.expires_at) },
        { icon: 'clock', c: 'amber', label: 'Duration', value: l.duration },
        { icon: 'users', c: 'teal', label: 'User limit', value: l.max_users ? l.max_users : this.$t('Unlimited') },
        { icon: 'cog', c: 'slate', label: 'Support level', value: l.support_level || this.$t('Standard') },
        { icon: 'collection', c: 'blue', label: 'Modules covered', value: l.modules || this.$t('All modules') },
        { icon: 'user', c: 'indigo', label: 'Issued by', value: `${l.issued_by || 'Licence Manager'} · ${this.$date(l.issued_at)}` },
        { icon: 'tick', c: 'emerald', label: 'Activated', value: l.applied_at ? `${this.$date(l.applied_at)}${l.applied_by ? ' · ' + l.applied_by : ''}` : '-' },
        { icon: 'chip', c: 'slate', label: 'Key signature', value: this.$t('Verified') },
      ];
    },
  },
  methods: {
    showResult(r) {
      const T = {
        applied: ['ok', 'tick', 'Licence activated', ''],
        same: ['info', 'info', 'This licence is already active', 'Use a different licence key or file if you meant to renew.'],
        length: ['bad', 'exclamation', 'The key has the wrong length', 'Copy the whole 128-character key without extra words or missing parts.'],
        unknown: ['bad', 'lock-closed', 'This licence key is not recognised', 'It was not issued for this system, or a character was changed. Contact the Technical Team if it keeps failing.'],
        unreadable: ['bad', 'exclamation', 'The licence file could not be read', 'Upload the original .wimslic file exactly as sent, without opening or editing it.'],
        tampered: ['bad', 'lock-closed', 'The licence file failed the security check', 'The file was changed after it was issued. Ask the Technical Team for a fresh copy.'],
        outdated: ['warn', 'clock', 'This licence file is out of date', 'Ask the Licence Manager for the latest file or key, then import that one.'],
        deactivated: ['bad', 'lock-closed', 'This licence was deactivated', 'Ask the Technical Team to reactivate it or issue a new licence.'],
        expired: ['warn', 'clock', 'This licence has already expired', 'An expired licence cannot be activated. Ask the Technical Team for a renewal.'],
        empty: ['warn', 'info', 'Nothing to import', 'Paste a licence key or choose a licence file first.'],
      };
      const [tone, icon, title, hint] = T[r.kind] || ['bad', 'exclamation', 'The licence was not accepted', ''];
      this.result = { ...r, tone, icon, title: this.$t(title), hint: hint ? this.$t(hint) : '' };
      clearTimeout(this.resultTimer);
      this.resultTimer = setTimeout(() => (this.result = null), tone === 'ok' ? 12000 : 20000);
    },
    stateLabel(s) { return this.$t({ active: 'Active', expiring: 'Expiring', expired: 'Expired', deactivated: 'Deactivated', scheduled: 'Scheduled', issued: 'Issued', superseded: 'Replaced' }[s] || s); },
    eventLabel(e) { return this.$t((EVENTS[e] || [e])[0]); },
    eventIcon(e) { return (EVENTS[e] || [0, 'info'])[1]; },
    when(iso) { return iso ? new Date(iso).toLocaleString([], { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : ''; },
    // The key box only ever holds the 128 key characters: spaces, dashes and line breaks are dropped and anything past 128 is cut off.
    onKey(e) {
      const clean = e.target.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
      this.overflow = Math.max(0, clean.length - 128);
      this.form.licence_text = clean.slice(0, 128);
      e.target.value = this.form.licence_text;
      this.form.licence_file = null; this.fileName = '';
    },
    onFile(e) { this.setFile(e.target.files[0]); },
    onDrop(e) { this.over = false; this.setFile(e.dataTransfer.files[0]); },
    setFile(file) {
      if (!file) return;
      this.form.licence_file = file; this.fileName = file.name; this.form.licence_text = '';
    },
    async apply() {
      if (!(await this.$confirm(this.$t('Activate this licence? It replaces the current licence on this system.')))) return;
      this.busy = true;
      router.post(this.route('wims-licence.apply'), this.form, {
        forceFormData: true, preserveScroll: true,
        onSuccess: () => { this.form = { licence_text: '', licence_file: null }; this.fileName = ''; this.overflow = 0; },
        onFinish: () => (this.busy = false),
      });
    },
  },
};
</script>

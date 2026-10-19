<template>
  <admin-layout :title="$t('QR Scans')">
    <div class="lg-page px-4 md:px-0 qsc">
      <page-header icon="list" :title="$t('QR Scans')" :subtitle="can.all ? $t('Every scan of a record QR code, and whether the person was allowed to open it.') : $t('Your scans of record QR codes, and whether you were allowed to open them.')">
        <a v-if="can.print" :href="exportUrl('qr-codes.scans.print')" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
        <a v-if="can.print" :href="exportUrl('qr-codes.scans.pdf')" class="lg-btn lg-btn-soft"><icons name="download" class="w-4 h-4" />{{ $t('Download PDF') }}</a>
        <Link v-if="can.scanner" :href="route('qr-codes.scanner')" class="lg-btn lg-btn-primary"><icons name="qr-code" class="w-4 h-4" />{{ $t('Scan a code') }}</Link>
      </page-header>

      <div class="qsc-cards">
        <stat-card v-for="(c, i) in cards" :key="c.key" :index="i" :tone="c.tone" :icon="c.icon" :foot-icon="c.icon" :label="c.label" :value="c.value" :chip="c.chip" />
      </div>

      <section class="lg-card qsc-filters">
        <div class="qsc-grid">
          <div class="qsc-f qsc-wide"><label for="qsf-q">{{ $t('Search') }}</label><input id="qsf-q" v-model="form.q" type="search" :placeholder="can.all ? $t('Person, module or document') : $t('Module or document')" /></div>
          <div v-if="can.all" class="qsc-f"><label>{{ $t('User') }}</label><auto-complete id="qsf-user" v-model="form.user_id" :suggestions="users" clearable :placeholder="$t('Everybody')" /></div>
          <div class="qsc-f"><label>{{ $t('Module') }}</label><auto-complete id="qsf-type" v-model="form.type" :suggestions="types" clearable :placeholder="$t('All modules')" /></div>
          <div class="qsc-f"><label>{{ $t('Status') }}</label><auto-complete id="qsf-status" v-model="form.status" :suggestions="statuses" clearable :placeholder="$t('Any status')" /></div>
          <div class="qsc-f"><label for="qsf-from">{{ $t('From') }}</label><date-picker id="qsf-from" v-model="form.from" :max="form.to || undefined" /></div>
          <div class="qsc-f"><label for="qsf-to">{{ $t('To') }}</label><date-picker id="qsf-to" v-model="form.to" :min="form.from || undefined" /></div>
          <div class="qsc-f qsc-reset"><button type="button" class="lg-btn lg-btn-soft" :disabled="!hasFilters" @click="reset"><icons name="refresh" class="w-4 h-4" />{{ $t('Reset') }}</button></div>
        </div>
      </section>

      <div class="lg-table-wrap qsc-wrap">
        <table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('No') }}</th><th>{{ $t('User') }}</th><th>{{ $t('Date and Time') }}</th><th>{{ $t('Module') }}</th><th>{{ $t('Document Scanned') }}</th><th>{{ $t('Status') }}</th></tr></thead>
          <tbody>
            <tr v-for="r in rows.data" :key="r.id">
              <td :data-label="$t('No')" class="qsc-no">{{ r.no }}</td>
              <td :data-label="$t('User')"><span class="lg-main">{{ r.user }}</span></td>
              <td :data-label="$t('Date and Time')"><div class="qsc-when"><b>{{ r.date }}</b><small>{{ r.time }}</small></div></td>
              <td :data-label="$t('Module')"><div class="qsc-mod"><b>{{ r.module }}</b><small v-if="r.group">{{ r.group }}</small></div></td>
              <td :data-label="$t('Document Scanned')">
                <div class="qsc-docrow">
                  <span v-if="r.qr" class="qsc-qr" role="button" tabindex="0" :aria-label="$t('The QR code that was scanned')" @click="lightbox = r" @keydown.enter="lightbox = r"><img :src="r.qr" :alt="$t('QR code scanned')" loading="lazy" width="28" height="28" /></span>
                  <a v-if="r.document && r.url" :href="r.url" target="_blank" rel="noopener" class="lg-mono qsc-doc qsc-link lg-tip" :data-tip="$t('Open the record in a new tab')">{{ r.document }}<icons name="forward" class="w-3 h-3" /></a>
                  <span v-else-if="r.document" class="lg-mono qsc-doc">{{ r.document }}</span>
                  <span v-else class="qsc-hidden"><icons name="lock-closed" class="w-3 h-3" />{{ $t('Not shown') }}</span>
                </div>
              </td>
              <td :data-label="$t('Status')"><span class="qsc-chip" :class="'is-' + r.status"><icons :name="statusIcon(r.status)" class="w-3 h-3" />{{ r.status_label }}</span></td>
            </tr>
            <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="6">{{ hasFilters ? $t('No scan matches these filters.') : $t('Nobody has scanned a code yet.') }}</td></tr>
          </tbody>
        </table>
      </div>
      <teleport to="body">
        <transition name="qsc-lb">
          <div v-if="lightbox" class="qsc-lb" role="dialog" aria-modal="true" :aria-label="$t('The QR code that was scanned')" @click="lightbox = null">
            <figure class="qsc-lb-card" @click.stop>
              <img :src="lightbox.qr" :alt="$t('QR code scanned')" />
              <figcaption><b>{{ lightbox.document || lightbox.module }}</b><small>{{ lightbox.module }} · {{ lightbox.date }} {{ lightbox.time }}</small></figcaption>
              <button type="button" class="qsc-lb-x" :aria-label="$t('Close')" @click="lightbox = null"><icons name="cross" class="w-4 h-4" /></button>
            </figure>
          </div>
        </transition>
      </teleport>
      <pagination v-if="rows.data.length" class="mt-4" :meta="rows.meta" :links="rows.links" />
    </div>
  </admin-layout>
</template>

<script>
import throttle from 'lodash/throttle';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatCard from '@/Shared/Page/StatCard.vue';
import Pagination from '@/Shared/Pagination.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';

export default {
  components: { AdminLayout, PageHeader, StatCard, Pagination, AutoComplete, DatePicker, Link },
  props: { rows: Object, stats: Object, filters: Object, statuses: Array, types: Array, users: Array, can: Object },
  data() {
    const f = this.filters;
    return { lightbox: null, form: { q: f.q || '', user_id: f.user_id ? Number(f.user_id) : null, type: f.type || null, status: f.status || null, from: f.from || '', to: f.to || '' } };
  },
  computed: {
    hasFilters() {
      return Object.values(this.form).some(v => v !== null && v !== '');
    },
    cards() {
      const s = this.stats;
      const pct = n => (s.total ? Math.round((n / s.total) * 100) + '%' : '0%');
      return [
        { key: 'total', tone: 'purple', icon: 'qr-code', label: this.$t('Scans'), value: s.total, chip: s.today + ' ' + this.$t('today') },
        { key: 'allowed', tone: 'emerald', icon: 'tick', label: this.$t('Allowed'), value: s.allowed, chip: pct(s.allowed) },
        { key: 'denied', tone: 'rose', icon: 'lock-closed', label: this.$t('No permission'), value: s.denied, chip: pct(s.denied) },
        { key: 'other', tone: 'orange', icon: 'exclamation', label: this.$t('Other refusals'), value: s.other, chip: pct(s.other) },
        { key: 'people', tone: 'blue', icon: 'users', label: this.can.all ? this.$t('People') : this.$t('You'), value: s.people, chip: this.$t('scanning') },
      ];
    },
  },
  watch: {
    form: {
      handler: throttle(function () {
        this.$inertia.get(route('qr-codes.scans'), this.query(), { preserveState: true, replace: true });
      }, 350),
      deep: true,
    },
  },
  methods: {
    query() {
      const q = {};
      Object.entries(this.form).forEach(([k, v]) => v !== null && v !== '' && (q[k] = v));
      return q;
    },
    exportUrl(name) {
      return route(name, this.query());
    },
    reset() {
      this.form = { q: '', user_id: null, type: null, status: null, from: '', to: '' };
    },
    statusIcon(s) {
      return { allowed: 'tick', no_permission: 'lock-closed', unavailable: 'exclamation', disabled: 'toggle', invalid: 'x-circle' }[s] || 'info';
    },
  },
};
</script>

<style scoped>
.qsc-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr)); gap: 0.9rem; margin-bottom: 1rem; }
.qsc-filters { margin-bottom: 1rem; }
.qsc-grid { display: grid; grid-template-columns: minmax(180px, 2fr) repeat(5, minmax(0, 1fr)) auto; gap: 0.8rem; align-items: start; }
.qsc-wide { grid-column: auto; }
@media (max-width: 1399.98px) { .qsc-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } .qsc-wide { grid-column: span 2; } .qsc-reset { grid-column: -2 / -1; justify-self: end; } }
@media (max-width: 1099.98px) { .qsc-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .qsc-reset { grid-column: 1 / -1; } }
@media (max-width: 639.98px) { .qsc-grid { grid-template-columns: minmax(0, 1fr); } .qsc-wide { grid-column: auto; } }
.qsc-f { display: grid; gap: 4px; min-width: 0; } .qsc-f label { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.qsc-f input[type='search'] { width: 100%; height: 42px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-ink); font-size: 0.88rem; box-shadow: 0 10px 16px -12px rgba(15, 37, 64, 0.55); }
.qsc-reset { align-self: start; justify-self: end; margin-top: 20px; }
.qsc-reset .lg-btn { height: 42px; padding: 0 1.1rem; box-shadow: 0 12px 18px -10px rgba(15, 37, 64, 0.6), 0 2px 5px rgba(15, 37, 64, 0.1); white-space: nowrap; }
/* every control sits on one line: the shared widgets add their own margins, so they are zeroed here, and every control gets the same height and a shadow */
.qsc-f :deep(.mb-2), .qsc-f :deep(.mt-1) { margin-top: 0 !important; margin-bottom: 0 !important; }
.qsc-f :deep(input) { height: 42px; box-shadow: 0 10px 16px -12px rgba(15, 37, 64, 0.55), 0 2px 4px rgba(15, 37, 64, 0.07); }
@media (max-width: 1399.98px) { .qsc-reset .lg-btn { width: auto; } }
.qsc-no { font-variant-numeric: tabular-nums; color: var(--lg-faint); font-weight: 800; }
.qsc-when, .qsc-mod { display: grid; line-height: 1.25; } .qsc-when b, .qsc-mod b { font-size: 0.86rem; color: var(--lg-ink); } .qsc-when small, .qsc-mod small { font-size: 0.72rem; color: var(--lg-faint); font-variant-numeric: tabular-nums; }
.qsc-doc { font-weight: 700; }
.qsc-docrow { display: inline-flex; align-items: center; gap: 10px; max-width: 100%; }
.qsc-link { display: inline-flex; align-items: center; gap: 5px; color: #2563eb; text-decoration: none; } .qsc-link:hover, .qsc-link:focus-visible { text-decoration: underline; }
.qsc-qr { --z: min(290px, 72vw, 62vh); position: relative; flex: none; display: inline-block; width: 28px; height: 28px; cursor: zoom-in; z-index: 1; outline: none; }
.qsc-qr:hover, .qsc-qr:focus { z-index: 40; }
.qsc-lb { position: fixed; z-index: 9999; inset: 0; display: grid; place-items: center; padding: 16px; background: rgba(5, 10, 22, 0.66); backdrop-filter: blur(4px); }
.qsc-lb-card { position: relative; margin: 0; display: grid; justify-items: center; gap: 10px; width: min(360px, 100%); padding: 18px 18px 16px; border-radius: 26px; background: #fff; box-shadow: 0 40px 70px -20px rgba(0, 0, 0, 0.7); }
.qsc-lb-card img { width: min(300px, 100%); aspect-ratio: 1; image-rendering: pixelated; border-radius: 12px; }
.qsc-lb-card figcaption { display: grid; justify-items: center; line-height: 1.3; text-align: center; } .qsc-lb-card figcaption b { font-size: 1rem; color: #0f172a; overflow-wrap: anywhere; } .qsc-lb-card figcaption small { font-size: 0.76rem; color: #64748b; }
.qsc-lb-x { position: absolute; top: -12px; right: -12px; display: grid; place-items: center; width: 34px; height: 34px; border-radius: 9999px; color: #0f172a; background: #fff; box-shadow: 0 8px 14px -6px rgba(0, 0, 0, 0.6); }
.qsc-lb-enter-active, .qsc-lb-leave-active { transition: opacity 0.2s ease; } .qsc-lb-enter-active .qsc-lb-card, .qsc-lb-leave-active .qsc-lb-card { transition: transform 0.25s cubic-bezier(0.2, 0.8, 0.2, 1); } .qsc-lb-enter-from, .qsc-lb-leave-to { opacity: 0; } .qsc-lb-enter-from .qsc-lb-card, .qsc-lb-leave-to .qsc-lb-card { transform: scale(0.85); }
.qsc-qr img { position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 28px; height: 28px; max-width: none; border-radius: 7px; background: #fff; image-rendering: pixelated; box-shadow: 0 0 0 1px var(--lg-line), 0 6px 10px -6px rgba(15, 37, 64, 0.55); transition: width 0.25s cubic-bezier(0.2, 0.8, 0.2, 1), height 0.25s cubic-bezier(0.2, 0.8, 0.2, 1), border-radius 0.25s ease, box-shadow 0.25s ease; }
@media (min-width: 1200px) { .qsc-qr:hover img, .qsc-qr:focus img { width: var(--z); height: var(--z); border-radius: 18px; box-shadow: 0 0 0 8px #fff, 0 24px 44px -8px rgba(15, 37, 64, 0.7); } }
/* the zoomed code grows downward from the first rows and upward from the last ones, so the table's own edge never cuts it */
.qsc-wrap { min-height: 360px; }
.qsc-wrap tbody tr:hover { position: relative; z-index: 2; }
.qsc-wrap tbody tr:nth-child(-n+2) .qsc-qr img { top: 0; transform: none; }
.qsc-wrap tbody tr:nth-last-child(-n+3) .qsc-qr img { top: auto; bottom: 0; transform: none; }
/* narrower screens: the code opens toward the left, from the icon, so it never leaves the screen */
@media (min-width: 768px) { .qsc-wrap { overflow: visible; } }
@media (prefers-reduced-motion: reduce) { .qsc-qr img { transition: none; } }
@media (max-width: 767.98px) { .qsc-docrow { justify-content: flex-end; } .qsc-wrap { min-height: 0; } .qsc-qr { --z: min(280px, 78vw); } }
.qsc-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 11px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800; white-space: nowrap; box-shadow: 0 6px 10px -8px rgba(15, 37, 64, 0.5); }
.qsc-chip.is-allowed { color: #065f46; background: #d1fae5; } .qsc-chip.is-no_permission { color: #9f1239; background: #ffe4e6; } .qsc-chip.is-unavailable, .qsc-chip.is-disabled { color: #92400e; background: #fef3c7; } .qsc-chip.is-invalid { color: #475569; background: #e2e8f0; }
html.dark .qsc-chip.is-allowed { color: #6ee7b7; background: rgba(16, 185, 129, 0.18); } html.dark .qsc-chip.is-no_permission { color: #fda4af; background: rgba(244, 63, 94, 0.18); } html.dark .qsc-chip.is-unavailable, html.dark .qsc-chip.is-disabled { color: #fcd34d; background: rgba(245, 158, 11, 0.18); }
</style>

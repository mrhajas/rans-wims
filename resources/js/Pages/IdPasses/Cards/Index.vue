<template>
  <admin-layout :title="info.label">
    <id-make-overlay />
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header :icon="info.icon" :title="info.label" :subtitle="$t('Every :k with its number, status and validity.', { k: info.singular })">
        <Link v-if="can.create" :href="route('id-passes.' + info.route + '.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" /><span>{{ $t('Issue :k', { k: info.singular }) }}</span></Link>
        <Link v-if="can.batch" :href="route('id-passes.print-centre.index')" class="lg-btn lg-btn-soft"><icons name="printer" class="w-4 h-4" /><span>{{ $t('Print Centre') }}</span></Link>
        <Link :href="route('id-passes.dashboard')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" /><span>{{ $t('Overview') }}</span></Link>
      </page-header>

      <sc-tabs :tabs="tabs" :model-value="f.status || ''" @update:model-value="setStatus" />

      <form class="lg-card" @submit.prevent="go">
        <div class="ac-filters idl-filters">
          <div class="ac-f idl-search"><label>{{ $t('Search') }}</label><input v-model="f.q" type="search" :placeholder="$t('Name, ID number, staff reference, phone or vehicle')" /></div>
          <div v-if="categories.length > 1" class="ac-f"><label>{{ $t('Category') }}</label><acct-select v-model="f.category_id" :options="categories" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ info.kind === 'staff' ? $t('Designation') : $t('Title') }}</label><simple-autocomplete v-model="f.title" :options="titleOptions" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Issued from') }}</label><date-picker v-model="f.issued_from" /></div>
          <div class="ac-f"><label>{{ $t('Issued to') }}</label><date-picker v-model="f.issued_to" /></div>
          <div class="ac-f"><label>{{ $t('Expires to') }}</label><date-picker v-model="f.expires_to" /></div>
          <div class="ac-f idl-actions"><label aria-hidden="true">&nbsp;</label><div><button type="submit" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Filter') }}</button><button type="button" class="lg-btn t-slate" @click="reset"><icons name="cross" class="w-4 h-4" />{{ $t('Clear') }}</button></div></div>
        </div>
      </form>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead><tr>
            <th></th>
            <th class="idl-sort" @click="sortBy('number')">{{ $t('ID number') }}<i>{{ arrow('number') }}</i></th>
            <th class="idl-sort" @click="sortBy('name')">{{ $t('Name') }}<i>{{ arrow('name') }}</i></th>
            <th class="idl-sort" @click="sortBy('category')">{{ $t('Category') }}<i>{{ arrow('category') }}</i></th>
            <th>{{ info.kind === 'staff' ? $t('Designation') : $t('Details') }}</th>
            <th class="idl-sort" @click="sortBy('status')">{{ $t('Status') }}<i>{{ arrow('status') }}</i></th>
            <th class="idl-sort" @click="sortBy('expires')">{{ $t('Valid until') }}<i>{{ arrow('expires') }}</i></th>
            <th class="idl-sort" @click="sortBy('printed')">{{ $t('Printed') }}<i>{{ arrow('printed') }}</i></th><th></th></tr></thead>
          <tbody>
            <tr v-for="r in rows.data" :key="r.id" class="is-click idl-row" :style="{ '--c': r.color }" @click="open(r)">
              <td class="idl-ph"><img v-if="r.has_photo" loading="lazy" :src="route('id-passes.' + info.route + '.photo-file', [r.id]) + '?w=96'" alt="" /><span v-else><icons name="user" class="w-4 h-4" /></span></td>
              <td :data-label="$t('ID number')"><span class="lg-main idl-no">{{ r.number }}</span><div v-if="r.staff_number" class="lg-subtext">{{ r.staff_number }}</div></td>
              <td :data-label="$t('Name')"><template v-if="r.pooled"><span v-if="r.holder" class="lg-chip lg-chip-amber idl-hold">{{ $t('With') }} {{ r.holder }}</span><span v-else class="lg-chip lg-chip-green idl-hold">{{ $t('Free') }}</span></template><template v-else>{{ r.name }}<div v-if="r.phone" class="lg-subtext">{{ r.phone }}</div></template></td>
              <td :data-label="$t('Category')"><category-chip :name="r.category" :color="r.color" :ink="r.ink" :icon="r.icon" /></td>
              <td :data-label="$t('Details')">{{ r.title || '-' }}<div v-if="r.vehicle" class="lg-subtext">{{ r.vehicle }}</div><div v-if="r.host" class="lg-subtext">{{ $t('Host') }}: {{ r.host }}</div></td>
              <td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(r.status)"><icons :name="statusIcon(r.status)" class="w-3 h-3" /> {{ $t(r.status) }}</span></td>
              <td :data-label="$t('Valid until')">{{ r.expires_at ? $date(r.expires_at) : $t('No expiry') }}<div v-if="r.days_left !== null && r.days_left >= 0 && r.days_left <= 30 && r.status === 'Active'" class="lg-subtext idl-soon">{{ $t(':n days left', { n: r.days_left }) }}</div></td>
              <td :data-label="$t('Printed')">{{ r.print_count }}<div v-if="r.last_printed_at" class="lg-subtext">{{ $datetime(r.last_printed_at) }}</div></td>
              <td class="lg-sticky-end" @click.stop>
                <a v-if="can.print && r.status === 'Active'" :href="route('id-passes.' + info.route + '.print', [r.id])" target="_blank" rel="noopener" @click.prevent="make(r, 'print')" class="lg-icon-btn lg-tip" :data-tip="$t('Print')" :aria-label="$t('Print')"><icons name="printer" class="w-4 h-4" /></a>
                <a v-if="can.download && r.status === 'Active'" :href="route('id-passes.' + info.route + '.pdf', [r.id])" @click.prevent="make(r, 'pdf')" class="lg-icon-btn lg-tip" :data-tip="$t('Download PDF')" :aria-label="$t('Download PDF')"><icons name="download" class="w-4 h-4" /></a>
                <Link :href="route('id-passes.' + info.route + '.show', [r.id])" class="lg-icon-btn lg-tip" :data-tip="$t('Open')" :aria-label="$t('Open')"><icons name="eye-flat" class="w-4 h-4" /></Link>
              </td>
            </tr>
            <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="9"><div class="idl-empty"><icons :name="info.icon" class="w-8 h-8" /><b>{{ $t('No :k yet.', { k: info.label }) }}</b><span v-if="can.create">{{ $t('Issue the first one with the button above.') }}</span></div></td></tr>
          </tbody>
        </table>
      </div>
      <pager :rows="rows" />
    </div>
  </admin-layout>
</template>

<script>
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import SimpleAutocomplete from '@/Shared/SimpleAutocomplete.vue';
import ScTabs from '../../Subcontracts/ScTabs.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import Pager from '@/Pages/Accounts/Pager.vue';
import { statusChip, statusIcon } from '../Parts/status';
import CategoryChip from '../Parts/CategoryChip.vue';
import IdMakeOverlay from '../Parts/IdMakeOverlay.vue';
import { idMakeFile } from '@/Core/idMake';

export default {
  components: { AdminLayout, PageHeader, Link, DatePicker, SimpleAutocomplete, ScTabs, AcctSelect, Pager, CategoryChip, IdMakeOverlay },
  props: { info: Object, rows: Object, filters: Object, counts: Object, categories: Array, titles: Array, statuses: Array, can: Object },
  data() { return { f: { ...this.filters } }; },
  computed: {
    titleOptions() { return this.titles.map(t => ({ value: t, label: t })); },
    tabs() {
      const c = this.counts;
      return [{ key: '', label: this.$t('All'), icon: 'collection', tone: 'navy', count: c.total }, { key: 'Active', label: this.$t('Active'), icon: 'tick', tone: 'emerald', count: c.active }, { key: 'Pending Approval', label: this.$t('Waiting'), icon: 'clock', tone: 'amber', count: c.pending },
        { key: 'Expired', label: this.$t('Expired'), icon: 'clock', tone: 'amber', count: c.expired }, { key: 'Suspended', label: this.$t('Suspended'), icon: 'pause', tone: 'violet', count: c.suspended }, { key: 'Revoked', label: this.$t('Revoked'), icon: 'x-circle', tone: 'rose', count: c.revoked }];
    },
  },
  methods: {
    // print / download with the "ID press" animation; an ID that was printed before needs a reason, which is asked on its own page
    async make(r, kind) {
      if (r.print_count > 0) { router.visit(route('id-passes.' + this.info.route + '.show', [r.id])); return; }
      const ok = await idMakeFile(route('id-passes.' + this.info.route + '.' + kind, [r.id]), { mode: kind, color: r.color, title: kind === 'print' ? this.$t('Preparing :n for printing', { n: r.number }) : this.$t('Creating the PDF of :n', { n: r.number }), doneText: r.number, fail: this.$t('The file could not be made.') });
      if (ok) router.reload({ preserveScroll: true });
    },
    statusChip, statusIcon,
    go() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); this.$inertia.get(route('id-passes.' + this.info.route + '.index'), q, { preserveState: true }); },
    setStatus(s) { this.f.status = s; this.go(); },
    reset() { this.f = { sort: this.f.sort, dir: this.f.dir }; this.go(); },
    sortBy(k) { this.f.dir = this.f.sort === k && this.f.dir === 'asc' ? 'desc' : 'asc'; this.f.sort = k; this.go(); },
    arrow(k) { return this.f.sort === k ? (this.f.dir === 'asc' ? ' ▲' : ' ▼') : ''; },
    open(r) { this.$inertia.visit(route('id-passes.' + this.info.route + '.show', [r.id])); },
  },
};
</script>

<style scoped>
@media (min-width: 1100px) { .idl-filters { grid-template-columns: minmax(0, 1.6fr) repeat(5, minmax(0, 1fr)) auto; } }
@media (max-width: 1099px) { .idl-filters .idl-search { grid-column: 1 / -1; } }
.idl-actions > div { display: flex; gap: 8px; }
.idl-sort { cursor: pointer; user-select: none; white-space: nowrap; } .idl-sort i { font-style: normal; font-size: 0.7em; }
.idl-ph { width: 54px; } .idl-ph img, .idl-ph span { width: 40px; height: 50px; object-fit: cover; border-radius: 10px; background: #afb3b6; display: grid; place-items: center; color: #fff; box-shadow: 0 6px 12px -8px rgba(15, 37, 64, 0.6); }
.idl-row td:first-child { box-shadow: inset 5px 0 0 var(--c); }
.idl-hold { text-transform: none; }
.idl-no { letter-spacing: 0.04em; font-weight: 800; color: #1e4467; } .idl-soon { color: #b45309; font-weight: 700; }
.idl-empty { display: grid; justify-items: center; gap: 6px; padding: 26px 8px; color: var(--lg-mute, #64748b); }
</style>

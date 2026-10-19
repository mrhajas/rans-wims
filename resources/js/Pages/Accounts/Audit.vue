<template>
  <admin-layout :title="$t('Audit Trail')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="key" :title="$t('Audit Trail')" :subtitle="$t('Who did what, when and from where. Records can be added but never changed or removed, and each is chained to the one before so tampering can be detected.')">
        <button v-if="canVerify" type="button" class="lg-btn lg-btn-primary" @click="$inertia.post(route('acct.audit.verify'), {}, { preserveScroll: true })"><icons name="tick" class="w-4 h-4" /><span>{{ $t('Check integrity') }}</span></button>
              <acct-export type="audit_log" :params="f" />
      </page-header>
      <form class="lg-card" @submit.prevent="go"><div class="ac-filters">
        <div class="ac-f" style="grid-column: span 2"><label>{{ $t('Search') }}</label><input v-model="f.q" type="search" :placeholder="$t('Event or reference')" /></div>
        <div class="ac-f"><label>{{ $t('About') }}</label><acct-select v-model="f.entity" :options="(entities).map(e => ({ value: e, label: e }))" :placeholder="$t('Everything')" clearable /></div>
        <div class="ac-f"><label>{{ $t('User') }}</label><acct-select v-model="f.user_id" :options="users" :placeholder="$t('Anyone')" clearable /></div>
        <div class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div><div class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
        <div class="ac-f"><button type="submit" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Filter') }}</button></div></div></form>
      <div class="lg-table-wrap"><table class="lg-table lg-responsive">
        <thead><tr><th>{{ $t('When') }}</th><th>{{ $t('Who') }}</th><th>{{ $t('Event') }}</th><th>{{ $t('Record') }}</th><th>{{ $t('Reason') }}</th><th>{{ $t('From') }}</th><th></th></tr></thead>
        <tbody>
          <template v-for="e in rows.data" :key="e.id">
            <tr class="is-click" @click="open = open === e.id ? null : e.id">
              <td :data-label="$t('When')">{{ e.at }}</td><td :data-label="$t('Who')">{{ e.user || '-' }}<div class="lg-subtext">{{ e.role }}</div></td><td :data-label="$t('Event')" class="lg-main">{{ e.event }}</td>
              <td :data-label="$t('Record')"><Link v-if="e.link" :href="e.link" @click.stop>{{ e.reference || e.entity }}</Link><span v-else>{{ e.reference || e.entity }}</span></td><td :data-label="$t('Reason')"><span class="ac-clip">{{ e.reason }}</span></td><td :data-label="$t('From')">{{ e.ip }}</td>
              <td><icons name="chevron-down" class="w-4 h-4" /></td>
            </tr>
            <tr v-if="open === e.id"><td colspan="7"><div class="ac-grid-2"><div><b class="lg-sub">{{ $t('Before') }}</b><pre class="ac-pre">{{ pretty(e.old) }}</pre></div><div><b class="lg-sub">{{ $t('After') }}</b><pre class="ac-pre">{{ pretty(e.new) }}</pre></div></div></td></tr>
          </template>
          <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="7">{{ $t('There is no data to display.') }}</td></tr>
        </tbody></table></div>
      <pager :rows="rows" />
    </div>
  </admin-layout>
</template>

<script>
import AcctExport from './AcctExport.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import Pager from './Pager.vue';
import AcctSelect from './AcctSelect.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import { acct } from './acct';

export default {
  components: { AcctExport, DatePicker, AcctSelect, AdminLayout, PageHeader, Pager, Link },
  mixins: [acct],
  props: { cur: Object, rows: Object, filters: Object, entities: Array, users: Array, canVerify: Boolean },
  data() { return { f: { ...this.filters }, open: null }; },
  methods: {
    pretty(v) { if (!v) return '-'; try { return JSON.stringify(typeof v === 'string' ? JSON.parse(v) : v, null, 2); } catch (e) { return String(v); } },
    go() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); this.$inertia.get(route('acct.audit.index'), q, { preserveState: true }); },
  },
};
</script>

<template>
  <admin-layout :title="$t('Transfers')">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="refresh" :title="$t('Transfers')" :subtitle="$t('Money moved between your own accounts, in the same currency or converted at an approved rate. A transfer is never income or expense.')">
        <Link v-if="can.create" :href="route('acct.transfers.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" /><span>{{ $t('New Transfer') }}</span></Link>
              <acct-export type="transfers_register" :params="f" />
      </page-header>
      <form class="lg-card" @submit.prevent="go">
        <div class="ac-filters">
          <div class="ac-f" style="grid-column: span 2"><label>{{ $t('Search') }}</label><input v-model="f.q" type="search" :placeholder="$t('Reference or reason')" /></div>
          <div class="ac-f"><label>{{ $t('Account') }}</label><acct-select v-model="f.account_id" :options="accounts" :placeholder="$t('All')" clearable /></div>
          <div class="ac-f"><label>{{ $t('Status') }}</label><acct-select v-model="f.status" :options="statuses" :placeholder="$t('Any')" clearable /></div>
          <div class="ac-f"><label>{{ $t('From') }}</label><date-picker v-model="f.from" /></div><div class="ac-f"><label>{{ $t('To') }}</label><date-picker v-model="f.to" /></div>
          <div class="ac-f"><button type="submit" class="lg-btn lg-btn-primary"><icons name="search" class="w-4 h-4" />{{ $t('Filter') }}</button></div>
        </div>
      </form>
      <div class="lg-table-wrap"><table class="lg-table lg-responsive">
        <thead><tr><th>{{ $t('Reference') }}</th><th>{{ $t('Date') }}</th><th>{{ $t('From') }}</th><th>{{ $t('To') }}</th><th class="ac-num">{{ $t('Sent') }}</th><th class="ac-num">{{ $t('Received') }}</th><th>{{ $t('Status') }}</th></tr></thead>
        <tbody>
          <tr v-for="t in rows.data" :key="t.id" class="is-click" @click="$inertia.visit(route('acct.transfers.show', t.id))">
            <td :data-label="$t('Reference')" class="lg-main">{{ t.reference }}<span v-if="t.cross" class="lg-chip lg-chip-violet" style="margin-left: 6px">{{ $t('Currency') }}</span></td><td :data-label="$t('Date')">{{ $date(t.date) }}</td><td :data-label="$t('From')">{{ t.from }}</td><td :data-label="$t('To')">{{ t.to }}</td>
            <td :data-label="$t('Sent')" class="ac-num ac-out">{{ mc(t.from_amount, t.from_currency) }}</td><td :data-label="$t('Received')" class="ac-num ac-in">{{ mc(t.to_amount, t.to_currency) }}</td>
            <td :data-label="$t('Status')"><span class="lg-chip" :class="statusChip(t.status)">{{ t.status_label }}</span></td>
          </tr>
          <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="7">{{ $t('There is no data to display.') }}</td></tr>
        </tbody></table></div>
      <pager :rows="rows" />
    </div>
  </admin-layout>
</template>

<script>
import AcctExport from '../AcctExport.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import Pager from '../Pager.vue';
import AcctSelect from '../AcctSelect.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import { acct } from '../acct';

export default {
  components: { AcctExport, DatePicker, AcctSelect, AdminLayout, PageHeader, Pager, Link },
  mixins: [acct],
  props: { cur: Object, rows: Object, filters: Object, accounts: Array, statuses: Array, can: Object },
  data() { return { f: { ...this.filters } }; },
  methods: { go() { const q = {}; Object.keys(this.f).forEach(k => { if (this.f[k]) q[k] = this.f[k]; }); this.$inertia.get(route('acct.transfers.index'), q, { preserveState: true }); } },
};
</script>

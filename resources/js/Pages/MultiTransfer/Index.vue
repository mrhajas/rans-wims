<template>
  <admin-layout :title="$t('Multi Transfers')">
    <div class="lg-page mt px-4 md:px-0">
      <page-header icon="collection" :title="$t('Multi Transfers')" :subtitle="$t('Items from several warehouses to one destination. Each source gets its own transfer, all held for approval.')">
        <Link v-if="can.create" :href="route('multi-transfers.create')" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" /><span>{{ $t('Create Multi Transfer') }}</span></Link>
      </page-header>

      <div class="lg-stats mt-stats">
        <div v-for="s in tiles" :key="s.key" class="lg-stat"><span class="lg-stat-ico" :class="s.ico"><icons :name="s.icon" class="w-5 h-5" /></span><div class="min-w-0"><div class="lg-stat-value">{{ stats[s.key] }}</div><div class="lg-stat-label">{{ s.label }}</div></div></div>
      </div>

      <form class="lg-card mt-search" @submit.prevent="go">
        <icons name="search" class="w-4 h-4" />
        <input v-model="q" type="search" :placeholder="$t('Search by request, transfer or destination')" :aria-label="$t('Search')" />
        <button type="submit" class="lg-btn lg-btn-soft">{{ $t('Search') }}</button>
      </form>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('Request') }}</th><th>{{ $t('Date') }}</th><th>{{ $t('To') }}</th><th>{{ $t('From') }}</th><th>{{ $t('Transfers') }}</th><th>{{ $t('Status') }}</th><th>{{ $t('Created by') }}</th><th></th></tr></thead>
          <tbody>
            <tr v-for="r in rows.data" :key="r.id" class="is-click" @click="$inertia.visit(route('multi-transfers.show', r.id))">
              <td :data-label="$t('Request')"><span class="lg-main">{{ r.reference }}</span></td>
              <td :data-label="$t('Date')">{{ $date(r.date) }}</td>
              <td :data-label="$t('To')">{{ r.to }}</td>
              <td :data-label="$t('From')"><span class="mt-src">{{ r.sources.join(', ') }}</span></td>
              <td :data-label="$t('Transfers')">{{ r.approved }} / {{ r.transfers }} {{ $t('approved') }}</td>
              <td :data-label="$t('Status')"><span class="lg-chip" :class="chip(r.status)">{{ $t(r.status_label) }}</span></td>
              <td :data-label="$t('Created by')">{{ r.by }}</td>
              <td class="lg-sticky-end" :data-label="$t('Actions')" @click.stop><Link :href="route('multi-transfers.show', r.id)" class="lg-icon-btn lg-tip" :data-tip="$t('Open')" :aria-label="$t('Open')"><icons name="eye-flat" class="w-4 h-4" /></Link></td>
            </tr>
            <tr v-if="!rows.data.length" class="lg-empty-row"><td class="lg-empty" colspan="8">{{ $t('There is no data to display.') }}</td></tr>
          </tbody>
        </table>
      </div>
      <nav v-if="rows.links && rows.links.length > 3" class="mt-pages" :aria-label="$t('Pages')">
        <template v-for="(l, i) in rows.links" :key="i"><Link v-if="l.url" :href="l.url" class="mt-page" :class="{ 'is-on': l.active }" v-html="l.label" /><span v-else class="mt-page is-off" v-html="l.label" /></template>
      </nav>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: { AdminLayout, PageHeader, Link },
  props: { rows: Object, stats: Object, filters: Object, can: Object },
  data() { return { q: this.filters.search || '' }; },
  computed: {
    tiles() {
      return [
        { key: 'total', label: this.$t('Requests'), icon: 'collection', ico: 'lg-ico-indigo' }, { key: 'pending', label: this.$t('Waiting for approval'), icon: 'clock', ico: 'lg-ico-amber' },
        { key: 'partial', label: this.$t('Partly approved'), icon: 'refresh', ico: 'lg-ico-violet' }, { key: 'approved', label: this.$t('Approved'), icon: 'tick', ico: 'lg-ico-emerald' },
      ];
    },
  },
  methods: {
    chip(s) { return { pending: 'lg-chip-amber', partial: 'lg-chip-violet', approved: 'lg-chip-green', received: 'lg-chip-green', cancelled: 'lg-chip-red' }[s] || 'lg-chip-slate'; },
    go() { this.$inertia.get(route('multi-transfers.index'), { search: this.q || undefined }, { preserveState: true }); },
  },
};
</script>

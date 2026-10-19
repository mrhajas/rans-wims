<template>
  <admin-layout :title="$t('Signatories')">
    <div class="lg-page sb px-4 md:px-0">
      <sb-hero icon="edit" :title="$t('Signatories')" :subtitle="$t('Authorised users whose handwritten signature WIMS places on the right reports, from the real workflow history.')">
        <Link :href="route('signatories.mine')" class="sb-gbtn"><icons name="user" class="w-4 h-4" />{{ $t('My signature') }}</Link>
        <Link v-if="can.roles" :href="route('signatories.roles.index')" class="sb-gbtn"><icons name="users" class="w-4 h-4" />{{ $t('Signing roles') }}</Link>
        <Link v-if="can.mappings" :href="route('signatories.mappings.index')" class="sb-gbtn"><icons name="doc-text" class="w-4 h-4" />{{ $t('Document setup') }}</Link>
        <Link v-if="can.reports" :href="route('signatories.reports.index')" class="sb-gbtn"><icons name="chart-bar" class="w-4 h-4" />{{ $t('Reports') }}</Link>
        <Link v-if="can.settings" :href="route('signatories.settings.index')" class="sb-gbtn"><icons name="cog" class="w-4 h-4" />{{ $t('Settings') }}</Link>
        <Link v-if="can.workflow" :href="route('signatories.workflow')" class="sb-gbtn"><icons name="bell" class="w-4 h-4" />{{ $t('Alerts') }}</Link>
      </sb-hero>

      <div class="sb-tiles mb-4">
        <div v-for="(t, i) in tiles" :key="t.label" class="sb-tile" :class="'g-' + t.tone" :style="{ '--i': i }"><span class="sb-tile__ico"><icons :name="t.icon" /></span><b><sb-count :value="t.value" /></b><span class="sb-tile__lbl">{{ t.label }}</span></div>
      </div>

      <section v-if="can.register" class="sb-card mb-4" style="--i: 1">
        <h3 class="sb-card__title"><span class="sb-ico g-teal"><icons name="plus" class="w-4 h-4" /></span>{{ $t('Register a signature for a user') }}</h3>
        <div class="sb-fields">
          <div class="sb-field sb-wide-2"><label><icons name="user" class="w-3 h-3" />{{ $t('User') }}</label><auto-complete v-model="pick" :suggestions="people" :placeholder="$t('Search a user by name or username..')" clearable /></div>
          <div class="sb-field sg-go"><Link v-if="pick" :href="route('signatories.register.user', pick)" class="sb-btn is-primary"><icons name="edit" class="w-4 h-4" />{{ $t('Open') }}</Link></div>
        </div>
      </section>

      <section class="sb-card" style="--i: 2">
        <h3 class="sb-card__title"><span class="sb-ico g-navy"><icons name="list" class="w-4 h-4" /></span>{{ $t('Registered signatories') }}<small>{{ shown.length }} / {{ rows.length }}</small></h3>
        <div class="sb-fields mb-3">
          <div class="sb-field sb-wide-2"><label><icons name="search" class="w-3 h-3" />{{ $t('Search') }}</label><input v-model="q" type="search" class="sb-input" :placeholder="$t('Name, username, position or staff number..')" /></div>
          <div class="sb-field"><label><icons name="filter" class="w-3 h-3" />{{ $t('Status') }}</label><auto-complete v-model="status" :suggestions="statusOptions" :placeholder="$t('All statuses')" clearable /></div>
        </div>
        <div class="sb-tablewrap"><table class="sb-table">
          <thead><tr>
            <th v-for="c in cols" :key="c.k"><button type="button" class="sg-sort" @click="sort(c.k)">{{ c.l }}<icons v-if="sortKey === c.k" :name="sortDir > 0 ? 'chevron-up' : 'chevron-down'" class="w-3 h-3" /></button></th><th></th>
          </tr></thead>
          <tbody>
            <tr v-for="r in shown" :key="r.id" class="sb-row" @click="$inertia.visit(route('signatories.show', r.id))">
              <td><div class="sb-who"><span class="sb-av">{{ initials(r.name) }}</span><span class="min-w-0"><b>{{ r.name }}</b><small>{{ r.username }}<template v-if="r.staff_no"> · {{ r.staff_no }}</template></small></span></div></td>
              <td>{{ r.position || '-' }}</td>
              <td><span class="sg-status" :class="'is-' + (r.status === 'pending_verification' ? 'pending' : r.status)">{{ statusLabel(r.status) }}</span><span v-if="r.has_pending && r.status !== 'pending_verification'" class="sg-flag">{{ $t('new one waiting') }}</span></td>
              <td>{{ dt(r.registered_at) }}</td><td>{{ dt(r.updated_at) }}</td>
              <td class="num"><Link :href="route('signatories.show', r.id)" class="sg-open" @click.stop><icons name="chevron-down" class="w-4 h-4" style="transform: rotate(-90deg)" /></Link></td>
            </tr>
            <tr v-if="!shown.length"><td colspan="6" class="sbr-empty">{{ $t('No signatory matches.') }}</td></tr>
          </tbody>
        </table></div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import SbHero from '@/Shared/SmartBrief/SbHero.vue';
import SbCount from '@/Shared/SmartBrief/SbCount.vue';

export default {
  components: { AdminLayout, Link, AutoComplete, SbHero, SbCount },
  props: { stats: Object, rows: Array, people: Array, can: Object },
  data() { return { q: '', status: '', pick: '', sortKey: 'name', sortDir: 1 }; },
  computed: {
    tiles() {
      const s = this.stats;
      return [
        { label: this.$t('Registered signatories'), value: s.registered, tone: 'navy', icon: 'edit' }, { label: this.$t('Active signatures'), value: s.active, tone: 'green', icon: 'tick' },
        { label: this.$t('Users without a signature'), value: s.without, tone: 'sky', icon: 'user' }, { label: this.$t('Waiting for verification'), value: s.pending, tone: 'amber', icon: 'clock' },
        { label: this.$t('Suspended or revoked'), value: s.suspended, tone: 'rose', icon: 'pause' }, { label: this.$t('Updated in 30 days'), value: s.recent, tone: 'violet', icon: 'refresh' },
        { label: this.$t('Documents set up to sign'), value: s.documents, tone: 'teal', icon: 'doc-text' }, { label: this.$t('Missing signatures (30 days)'), value: s.missing, tone: 'pink', icon: 'exclamation' },
      ];
    },
    cols() { return [{ k: 'name', l: this.$t('Signatory') }, { k: 'position', l: this.$t('Position') }, { k: 'status', l: this.$t('Status') }, { k: 'registered_at', l: this.$t('Registered') }, { k: 'updated_at', l: this.$t('Last updated') }]; },
    statusOptions() { return ['active', 'pending_verification', 'suspended', 'revoked'].map(s => ({ value: s, label: this.statusLabel(s) })); },
    shown() {
      const q = this.q.trim().toLowerCase();
      const list = this.rows.filter(r => (!this.status || r.status === this.status) && (!q || [r.name, r.username, r.position, r.staff_no].join(' ').toLowerCase().includes(q)));
      return list.slice().sort((a, b) => String(a[this.sortKey] ?? '').localeCompare(String(b[this.sortKey] ?? '')) * this.sortDir);
    },
  },
  methods: {
    initials(n) { return (n || '').split(' ').filter(Boolean).slice(0, 2).map(p => p[0].toUpperCase()).join(''); },
    dt(v) { return v ? String(v).slice(0, 10) : '-'; },
    statusLabel(s) { return { active: this.$t('Active'), pending_verification: this.$t('Pending verification'), suspended: this.$t('Suspended'), revoked: this.$t('Revoked') }[s] || s; },
    sort(k) { if (this.sortKey === k) this.sortDir = -this.sortDir; else { this.sortKey = k; this.sortDir = 1; } },
  },
};
</script>

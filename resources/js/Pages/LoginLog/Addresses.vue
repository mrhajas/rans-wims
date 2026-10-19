<template>
  <admin-layout :title="tab === 'blacklist' ? $t('Blacklist') : $t('Whitelist')">
    <div class="lg-page ac px-4 md:px-0">
      <header class="lg-hero">
        <span class="lg-hero-icon"><icons :name="tab === 'blacklist' ? 'lock-closed' : 'tick'" class="w-6 h-6" /></span>
        <div class="lg-hero-body">
          <h1 class="lg-title">{{ tab === 'blacklist' ? $t('Blacklist') : $t('Whitelist') }}</h1>
          <p class="lg-sub">{{ tab === 'blacklist' ? $t('Addresses nobody can sign in from: the ones you blocked by hand and the ones WIMS blocked after too many wrong attempts.') : $t('Trusted addresses: they are never counted for wrong attempts and never blocked, except by nothing.') }}</p>
        </div>
      </header>

      <address-tabs :counts="counts" :active="tab" />

      <div class="lg-note lg-note-blue"><icons name="info" class="w-4 h-4" /><span>
        {{ $t('More than :n wrong sign-ins in :m minutes from one address block it for :a, then :b, then :c, then :d. Sign-in codes by Email or SMS: the first :f go out normally, then a wait of :w, then :x for the account.', {
          n: limits.login.max_failures, m: Math.round(limits.login.window_seconds / 60), a: mins(limits.login.block_minutes[0]), b: mins(limits.login.block_minutes[1]), c: mins(limits.login.block_minutes[2]), d: mins(limits.login.block_minutes[3]),
          f: limits.otp.free_sends, w: limits.otp.waits_seconds.map(s => mins(s / 60)).join(', '), x: $t('a block of :h hours', { h: limits.otp.block_hours }) }) }}
      </span></div>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico" :class="tab === 'blacklist' ? 'lg-ico-rose' : 'lg-ico-emerald'"><icons name="plus" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ tab === 'blacklist' ? $t('Block an address') : $t('Trust an address') }}</h2><p class="lg-card-sub">{{ $t('Your address now:') }} <b>{{ myIp }}</b></p></div>
        </header>
        <form class="lga-form" @submit.prevent="add">
          <div class="ac-f"><label for="lga-ip">{{ $t('IP address') }} *</label><input id="lga-ip" v-model="form.ip" type="text" inputmode="text" autocomplete="off" placeholder="102.xx.xx.xx" /><span class="ac-err">{{ form.errors.ip }}</span></div>
          <div class="ac-f"><label for="lga-reason">{{ $t('Reason') }}</label><input id="lga-reason" v-model="form.reason" type="text" maxlength="190" autocomplete="off" /><span class="ac-err">{{ form.errors.reason }}</span></div>
          <div class="ac-f"><label>{{ $t('Until (optional)') }}</label><date-picker v-model="form.expires_at" :placeholder="$t('No end date')" clearable /><span class="ac-err">{{ form.errors.expires_at }}</span></div>
          <div class="ac-f lga-go"><label aria-hidden="true">&nbsp;</label><button type="submit" class="lg-btn" :class="tab === 'blacklist' ? 'lg-btn-red' : 'lg-btn-green'" :disabled="form.processing"><icons :name="tab === 'blacklist' ? 'lock-closed' : 'tick'" class="w-4 h-4" />{{ tab === 'blacklist' ? $t('Blacklist') : $t('Whitelist') }}</button></div>
        </form>
      </section>

      <section class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ tab === 'blacklist' ? $t('Addresses blocked by an administrator') : $t('Trusted addresses') }}</h2></div></header>
        <div class="lg-table-wrap"><table class="lg-table lg-responsive">
          <thead><tr><th>{{ $t('IP address') }}</th><th>{{ $t('Reason') }}</th><th>{{ $t('Until') }}</th><th v-if="tab === 'blacklist'" class="text-right">{{ $t('Refused attempts') }}</th><th>{{ $t('Added by') }}</th><th></th></tr></thead>
          <tbody>
            <tr v-for="r in rules" :key="r.id">
              <td :data-label="$t('IP address')" class="lg-main lg-mono">{{ r.ip }}</td>
              <td :data-label="$t('Reason')">{{ r.reason || '-' }}</td>
              <td :data-label="$t('Until')">{{ r.expires_at ? $date(r.expires_at) : $t('Until removed') }}</td>
              <td v-if="tab === 'blacklist'" :data-label="$t('Refused attempts')" class="text-right">{{ r.hits }}<small v-if="r.last_hit_at" class="lg-subtext block">{{ $date(r.last_hit_at) }} {{ $time(r.last_hit_at) }}</small></td>
              <td :data-label="$t('Added by')">{{ r.by || '-' }}<small class="lg-subtext block">{{ $date(r.created_at) }}</small></td>
              <td class="lga-act">
                <button v-if="tab === 'blacklist'" type="button" class="lg-btn t-emerald" @click="move(r.ip, 'white')"><icons name="tick" class="w-4 h-4" />{{ $t('Whitelist') }}</button>
                <button type="button" class="lg-btn t-red" @click="remove(r)"><icons name="trash" class="w-4 h-4" />{{ $t('Remove') }}</button>
              </td>
            </tr>
            <tr v-if="!rules.length" class="lg-empty-row"><td class="lg-empty" :colspan="tab === 'blacklist' ? 6 : 5">{{ $t('Nothing here yet.') }}</td></tr>
          </tbody>
        </table></div>
      </section>

      <template v-if="tab === 'blacklist'">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Blocked automatically (wrong sign-ins)') }}</h2><p class="lg-card-sub">{{ $t('Each new block of the same address is longer. Lift one here, or whitelist the address.') }}</p></div></header>
          <div class="lg-table-wrap"><table class="lg-table lg-responsive">
            <thead><tr><th>{{ $t('IP address') }}</th><th>{{ $t('Blocked until') }}</th><th class="text-right">{{ $t('Times blocked') }}</th><th class="text-right">{{ $t('Refused attempts') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="a in auto" :key="a.id">
                <td :data-label="$t('IP address')" class="lg-main lg-mono">{{ a.ip }}</td>
                <td :data-label="$t('Blocked until')">{{ $date(a.until) }} {{ $time(a.until) }}</td>
                <td :data-label="$t('Times blocked')" class="text-right">{{ a.blocks }}</td>
                <td :data-label="$t('Refused attempts')" class="text-right">{{ a.hits }}</td>
                <td class="lga-act"><button type="button" class="lg-btn t-emerald" @click="move(a.ip, 'white')"><icons name="tick" class="w-4 h-4" />{{ $t('Whitelist') }}</button><button type="button" class="lg-btn t-slate" @click="lift(a.id)"><icons name="refresh" class="w-4 h-4" />{{ $t('Lift block') }}</button></td>
              </tr>
              <tr v-if="!auto.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('No address is blocked right now.') }}</td></tr>
            </tbody>
          </table></div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="otp" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Accounts blocked from receiving sign-in codes') }}</h2><p class="lg-card-sub">{{ $t('Asked for too many Email / SMS codes. Lifting the block lets the person ask again.') }}</p></div></header>
          <div class="lg-table-wrap"><table class="lg-table lg-responsive">
            <thead><tr><th>{{ $t('Account') }}</th><th>{{ $t('Blocked until') }}</th><th class="text-right">{{ $t('Codes sent') }}</th><th class="text-right">{{ $t('Refused attempts') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="o in otp" :key="o.id">
                <td :data-label="$t('Account')"><span class="lg-main">{{ o.user }}</span><small class="lg-subtext block">{{ o.username }}</small></td>
                <td :data-label="$t('Blocked until')">{{ $date(o.until) }} {{ $time(o.until) }}</td>
                <td :data-label="$t('Codes sent')" class="text-right">{{ o.sent }}</td>
                <td :data-label="$t('Refused attempts')" class="text-right">{{ o.hits }}</td>
                <td class="lga-act"><button type="button" class="lg-btn t-slate" @click="lift(o.id)"><icons name="refresh" class="w-4 h-4" />{{ $t('Lift block') }}</button></td>
              </tr>
              <tr v-if="!otp.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('No account is blocked from receiving codes.') }}</td></tr>
            </tbody>
          </table></div>
        </section>
      </template>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AddressTabs from './AddressTabs.vue';

export default {
  components: { AdminLayout, DatePicker, AddressTabs },
  props: { tab: String, rules: Array, auto: Array, otp: Array, counts: Object, myIp: String, limits: Object },
  data() { return { form: this.$inertia.form({ ip: '', type: this.tab === 'whitelist' ? 'white' : 'black', reason: '', expires_at: null }) }; },
  watch: { tab(v) { this.form.type = v === 'whitelist' ? 'white' : 'black'; } },
  methods: {
    mins(m) { m = Number(m); if (m === 60) return this.$t('1 hour'); if (m > 60 && m % 60 === 0) return this.$t(':n hours', { n: m / 60 }); return this.$t(':n minutes', { n: Math.round(m) }); },
    add() { this.form.post(route('login-logs.addresses.store'), { preserveScroll: true, onSuccess: () => this.form.reset('ip', 'reason', 'expires_at') }); },
    move(ip, type) {
      if (!confirm(this.$t('Whitelist :ip? It will never be counted or blocked.', { ip }))) return;
      this.$inertia.post(route('login-logs.addresses.store'), { ip, type, reason: this.$t('Whitelisted from the Blacklist page') }, { preserveScroll: true });
    },
    remove(r) { if (confirm(this.$t('Remove :ip from the list?', { ip: r.ip }))) this.$inertia.delete(route('login-logs.addresses.destroy', r.id), { preserveScroll: true }); },
    lift(id) { if (confirm(this.$t('Lift this block now?'))) this.$inertia.post(route('login-logs.blocks.clear', id), {}, { preserveScroll: true }); },
  },
};
</script>

<style scoped>
.lga-form { display: grid; gap: 12px; grid-template-columns: minmax(180px, 1fr) minmax(220px, 2fr) minmax(180px, 1fr) auto; align-items: start; }
@media (max-width: 900px) { .lga-form { grid-template-columns: 1fr 1fr; } }
@media (max-width: 575.98px) { .lga-form { grid-template-columns: 1fr; } }
.lga-act { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
</style>

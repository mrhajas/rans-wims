<template>
  <nav v-if="counts" class="lga-tabs print:hidden" :aria-label="$t('Login security')">
    <Link :href="route('login-logs.index')" class="lga-tab" :class="{ 'is-on': active === 'logs' }"><icons name="list" class="w-4 h-4" />{{ $t('Login Logs') }}</Link>
    <Link :href="route('login-logs.addresses', 'blacklist')" class="lga-tab is-red" :class="{ 'is-on': active === 'blacklist' }"><icons name="lock-closed" class="w-4 h-4" />{{ $t('Blacklist') }}<b>{{ counts.black }}</b></Link>
    <Link :href="route('login-logs.addresses', 'whitelist')" class="lga-tab is-green" :class="{ 'is-on': active === 'whitelist' }"><icons name="tick" class="w-4 h-4" />{{ $t('Whitelist') }}<b>{{ counts.white }}</b></Link>
  </nav>
</template>

<script>
import { Link } from '@inertiajs/vue3';

// The three tabs of Login Logs: the log itself, the blocked addresses and the trusted ones (Super Admin only).
export default { components: { Link }, props: { counts: Object, active: String } };
</script>

<style scoped>
.lga-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
.lga-tab { display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 16px; border-radius: 999px; font-size: 0.86rem; font-weight: 800; color: var(--lg-ink, #1e293b); background: var(--lg-card, #fff); box-shadow: inset 0 0 0 1px var(--lg-line, #d6dfeb); transition: background 0.15s, box-shadow 0.15s; }
.lga-tab:hover { background: rgba(59, 130, 196, 0.1); }
.lga-tab b { min-width: 22px; height: 22px; padding: 0 6px; border-radius: 999px; display: inline-grid; place-items: center; font-size: 0.72rem; background: rgba(148, 163, 184, 0.22); }
.lga-tab.is-on { background: #1c3f66; color: #fff; box-shadow: none; }
.lga-tab.is-on.is-red { background: #9f1239; } .lga-tab.is-on.is-green { background: #047857; }
.lga-tab.is-on b { background: rgba(255, 255, 255, 0.24); }
</style>

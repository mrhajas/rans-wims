<template>
  <admin-layout :title="$t('Login Log Details')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="key" :title="loginLog.username || $t('Unknown User')" :subtitle="$t('Detailed metadata for this login attempt.')">
        <Link :href="route('login-logs.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-3.5 h-3.5 transform rotate-90" />
          {{ $t('Login Logs') }}
        </Link>
        <span class="lg-chip" :class="loginLog.status === 'success' ? 'lg-chip-green' : 'lg-chip-red'">
          <icons :name="loginLog.status === 'success' ? 'tick' : 'exclamation'" class="w-3.5 h-3.5" /> {{ loginLog.status }}
        </span>
      </page-header>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="user" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Account') }}</h2>
          </div>
        </header>
        <div class="llogd-grid">
          <div class="llogd-field"><span class="llogd-label">{{ $t('Date Time') }}</span><span class="llogd-value">{{ $datetime(loginLog.attempted_at) }}</span></div>
          <div class="llogd-field"><span class="llogd-label">{{ $t('Username') }}</span><span class="llogd-value">{{ loginLog.username || '—' }}</span></div>
          <div class="llogd-field"><span class="llogd-label">{{ $t('Full Name') }}</span><span class="llogd-value">{{ loginLog.user ? loginLog.user.name : '—' }}</span></div>
          <div class="llogd-field"><span class="llogd-label">{{ $t('Email') }}</span><span class="llogd-value">{{ loginLog.user ? loginLog.user.email : '—' }}</span></div>
          <div class="llogd-field"><span class="llogd-label">{{ $t('Role') }}</span><span class="llogd-value">{{ loginLog.role || '—' }}</span></div>
          <div v-if="loginLog.error_message" class="llogd-field llogd-wide"><span class="llogd-label">{{ $t('Error Message') }}</span><span class="llogd-value text-rose-700">{{ loginLog.error_message }}</span></div>
        </div>
      </section>

      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-teal"><icons name="cog" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Device and Network') }}</h2>
          </div>
        </header>
        <div class="llogd-grid">
          <div class="llogd-field"><span class="llogd-label">{{ $t('IP Address') }}</span><span class="llogd-value mono">{{ loginLog.ip_address || '—' }}</span></div>
          <div class="llogd-field"><span class="llogd-label">{{ $t('Location') }}</span><span class="llogd-value">{{ loginLog.location || '—' }}</span></div>
          <div class="llogd-field"><span class="llogd-label">{{ $t('Device') }}</span><span class="llogd-value">{{ loginLog.device || '—' }}</span></div>
          <div class="llogd-field"><span class="llogd-label">{{ $t('OS') }}</span><span class="llogd-value">{{ loginLog.os || '—' }}</span></div>
          <div class="llogd-field"><span class="llogd-label">{{ $t('Browser') }}</span><span class="llogd-value">{{ loginLog.browser || '—' }}</span></div>
          <div class="llogd-field llogd-wide"><span class="llogd-label">{{ $t('User Agent') }}</span><span class="llogd-value mono text-xs break-all">{{ loginLog.user_agent || '—' }}</span></div>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: {
    Link,
    AdminLayout,
    PageHeader,
  },

  props: {
    loginLog: Object,
  },
};
</script>

<style scoped>
.llogd-grid { display: grid; gap: 1.1rem 2rem; grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr)); padding: 1rem 1.1rem; border-radius: 16px; background: var(--lg-soft); }
.llogd-wide { grid-column: 1 / -1; }
.llogd-field { display: flex; flex-direction: column; gap: 0.2rem; min-width: 0; }
.llogd-label { font-size: 0.66rem; font-weight: 800; color: var(--lg-faint); text-transform: uppercase; letter-spacing: 0.06em; }
.llogd-value { font-size: 0.88rem; color: var(--lg-ink); font-weight: 600; overflow-wrap: anywhere; }
.mono { font-family: inherit; font-variant-numeric: tabular-nums; font-weight: 500; }
</style>

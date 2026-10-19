<template>
  <admin-layout :title="$t('QR code')">
    <div class="lg-page px-4 md:px-0">
      <section class="qu">
        <span class="qu-ico" :class="'is-' + status"><icons :name="icon" class="w-10 h-10" /></span>
        <h1>{{ title }}</h1>
        <p>{{ text }}</p>
        <div class="qu-actions">
          <Link :href="route('qr-codes.scanner')" class="lg-btn lg-btn-primary"><icons name="qr-code" class="w-4 h-4" />{{ $t('Scan another code') }}</Link>
          <Link :href="route('dashboard')" class="lg-btn lg-btn-soft">{{ $t('Dashboard') }}</Link>
        </div>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

export default {
  components: { AdminLayout, Link },
  props: { status: { type: String, default: 'invalid' }, kind: String },
  computed: {
    icon() {
      return { no_permission: 'lock-closed', disabled: 'toggle', unavailable: 'exclamation' }[this.status] || 'x-circle';
    },
    title() {
      return { no_permission: this.$t('You do not have permission'), disabled: this.$t('QR codes are off for this module'), unavailable: this.$t('Record not available') }[this.status] || this.$t('Not a valid code');
    },
    text() {
      const kind = this.kind || this.$t('record');
      return {
        no_permission: this.$t('You are not allowed to view this :kind. Ask an administrator if you need access.', { kind }),
        disabled: this.$t('QR codes for :kind are switched off right now.', { kind }),
        unavailable: this.$t('This :kind was removed, so it cannot be opened.', { kind }),
      }[this.status] || this.$t('This code is not recognised, or it was replaced by a newer one.');
    },
  },
};
</script>

<style scoped>
.qu { display: grid; justify-items: center; gap: 0.8rem; max-width: 520px; margin: 3rem auto; padding: 2.2rem 1.6rem; text-align: center; border-radius: 26px; background: var(--lg-card); box-shadow: 0 28px 46px -28px rgba(15, 37, 64, 0.6), 0 0 0 1px var(--lg-line); }
.qu-ico { display: inline-grid; place-items: center; width: 84px; height: 84px; border-radius: 26px; color: #fff; background: linear-gradient(145deg, #f59e0b, #e11d48); box-shadow: 0 18px 28px -16px rgba(225, 29, 72, 0.8); }
.qu-ico.is-no_permission { background: linear-gradient(145deg, #fb7185, #be123c); } .qu-ico.is-disabled, .qu-ico.is-unavailable { background: linear-gradient(145deg, #fbbf24, #d97706); } .qu-ico.is-invalid { background: linear-gradient(145deg, #94a3b8, #475569); }
.qu h1 { margin: 0; font-size: 1.25rem; font-weight: 800; color: var(--lg-ink); } .qu p { margin: 0; font-size: 0.88rem; line-height: 1.55; color: var(--lg-mute); }
.qu-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.6rem; margin-top: 0.4rem; }
</style>

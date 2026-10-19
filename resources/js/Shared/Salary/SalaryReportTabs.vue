<template>
  <nav class="sl-tabs print:hidden" :aria-label="$t('Reports')">
    <Link v-for="tab in tabs" :key="tab.key" :href="route(tab.route)" class="sl-tab" :class="{ 'is-active': active === tab.key }">{{ tab.label }}</Link>
  </nav>
</template>

<script>
import { Link } from '@inertiajs/vue3';

export default {
  components: { Link },
  props: { active: { type: String, default: 'register' } },
  computed: {
    tabs() {
      return [
        { key: 'register', route: 'salary.reports.register', label: this.$t('Register') },
        { key: 'outstanding', route: 'salary.reports.outstanding', label: this.$t('Outstanding') },
        { key: 'advances', route: 'salary.reports.advances', label: this.$t('Advances') },
        { key: 'loans', route: 'salary.reports.loans', label: this.$t('Loans') },
        { key: 'payment-history', route: 'salary.reports.payment-history', label: this.$t('Payment History') },
      ];
    },
  },
};
</script>

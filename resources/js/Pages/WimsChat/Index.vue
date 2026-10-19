<template>
  <admin-layout :title="$t('WIMSChat')">
    <div class="lg-page wcp px-4 md:px-0">
      <page-header icon="chat" :title="$t('WIMSChat')" :subtitle="$t('Internal messages, groups and files for WIMS users.')">
        <Link v-if="$can('view-wimschat-analytics') && featureOn('wimschat_analytics')" :href="route('wimschat.analytics')" class="lg-btn lg-btn-soft"><icons name="chart-bar" class="w-4 h-4" />{{ $t('Analytics') }}</Link>
        <Link v-if="$can('manage-wimschat-settings')" :href="route('wimschat.settings')" class="lg-btn lg-btn-soft"><icons name="cog" class="w-4 h-4" />{{ $t('Settings') }}</Link>
      </page-header>

      <div class="wcp-frame"><chat-workspace mode="page" :open="open" /></div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import ChatWorkspace from '@/Shared/WimsChat/ChatWorkspace.vue';
import { chat, tickSoon } from '@/Core/wimschat';

export default {
  components: { AdminLayout, PageHeader, Link, ChatWorkspace },
  props: { open: { type: Number, default: null } },
  mounted() {
    chat.pageOpen = true;
    chat.drawerOpen = false;
    tickSoon();
    // phones: bring the chat to the top of the screen once the page has settled (Inertia / the layout reset the scroll after mount)
    if (window.innerWidth < 768) {
      const bring = () => { const f = document.querySelector('.wcp-frame'); if (f && f.getBoundingClientRect().top > 60) f.scrollIntoView({ block: 'start' }); };
      [450, 1100, 2000].forEach(ms => setTimeout(bring, ms));
    }
  },
  beforeUnmount() {
    chat.pageOpen = false;
  },
  methods: {
    featureOn(k) {
      return this.$page.props.enabledFeatures?.[k] !== false || this.$super;
    },
  },
};
</script>

<template>
  <div v-if="visible" class="wcl print:hidden" :class="'wc-theme-' + chat.theme">
    <!-- The launcher is core WIMS: fixed position, no drag, no hide / move setting. It is positioned only by CSS (.wcl-fab). -->
    <button
      v-show="!chat.drawerOpen" type="button" class="wcl-fab lg-tip tip-left" :class="{ 'has-unread': chat.unread > 0, 'is-ping': ping }" :data-tip="chat.unread ? $t('WIMSChat - :n unread', { n: chat.unread }) : $t('WIMSChat')"
      :aria-label="chat.unread ? $t('Open WIMSChat, :n unread messages', { n: chat.unread }) : $t('Open WIMSChat')" aria-haspopup="dialog" :aria-expanded="chat.drawerOpen" @click="toggle"
    >
      <span class="wcl-halo" aria-hidden="true"></span><span class="wcl-halo wcl-halo--2" aria-hidden="true"></span>
      <icons name="chat" class="wcl-ico" />
      <span v-if="chat.unread > 0" class="wcl-badge">{{ chat.unread > 99 ? '99+' : chat.unread }}</span>
    </button>

    <transition name="wcl-fade"><div v-if="chat.drawerOpen" class="wcl-backdrop" @click="close"></div></transition>
    <transition name="wcl-drawer">
      <aside v-if="chat.drawerOpen" class="wcl-drawer" role="dialog" aria-modal="true" :aria-label="$t('WIMSChat')">
        <chat-workspace mode="drawer" @close="close" />
      </aside>
    </transition>
  </div>
</template>

<script>
import ChatWorkspace from './ChatWorkspace.vue';
import { chat, start, tickSoon } from '@/Core/wimschat';

export default {
  components: { ChatWorkspace },
  data() {
    return { chat, ping: false };
  },
  computed: {
    allowed() {
      const p = this.$page.props;
      return !!(p.auth && p.auth.user) && this.$can('view-wimschat') && (p.enabledFeatures?.wimschat !== false || this.$super);
    },
    onModulePage() {
      return String(this.$page.component || '').startsWith('WimsChat/Index');
    },
    visible() {
      return this.allowed && !this.onModulePage;
    },
  },
  watch: {
    visible: { immediate: true, handler(v) { if (v) start(); } },
    onModulePage(v) {
      if (v) chat.drawerOpen = false;
    },
    'chat.unread'(n, o) {
      if (n > o) {
        this.ping = true;
        setTimeout(() => (this.ping = false), 1200);
      }
    },
    'chat.drawerOpen'(v) {
      tickSoon();
      const narrow = window.matchMedia && window.matchMedia('(max-width: 639px)').matches;
      document.documentElement.classList.toggle('wcl-lock', v && narrow);
      if (v) document.addEventListener('keydown', this.onKey);
      else document.removeEventListener('keydown', this.onKey);
    },
  },
  beforeUnmount() {
    document.removeEventListener('keydown', this.onKey);
    document.documentElement.classList.remove('wcl-lock');
  },
  methods: {
    toggle() {
      chat.drawerOpen = !chat.drawerOpen;
    },
    close() {
      chat.drawerOpen = false;
    },
    onKey(e) {
      if (e.key === 'Escape' && !document.querySelector('.wc-modal, .wc-lightbox, .wc-pop')) this.close();
    },
  },
};
</script>

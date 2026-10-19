<template>
  <div ref="root" class="wc" :class="['wc--' + layout, 'wc-mode-' + mode, 'wc-theme-' + chat.theme, { 'is-shake': shake, 'is-compact': chat.me && chat.me.prefs && chat.me.prefs.density === 'compact', 'info-docked': infoDocked }]" @keydown.esc="onEsc">
    <!-- drawer chrome -->
    <header v-if="mode === 'drawer'" class="wc-bar">
      <span class="wc-bar-t"><icons name="chat" class="w-5 h-5" />{{ $t('WIMSChat') }}</span>
      <span class="wc-bar-a">
        <Link v-if="$can('view-wimschat')" :href="route('wimschat.index')" class="wc-ib lg-tip tip-left" :data-tip="$t('Open full page')" :aria-label="$t('Open full page')" @click="$emit('close')"><icons name="zoom-in" class="w-5 h-5" /></Link>
        <button type="button" class="wc-ib" :aria-label="$t('Close WIMSChat')" @click="$emit('close')"><icons name="cross" class="w-5 h-5" /></button>
      </span>
    </header>

    <div v-if="chat.booting && !chat.booted" class="wc-boot"><span class="wc-spin"></span>{{ $t('Opening WIMSChat…') }}</div>
    <div v-else-if="chat.bootError" class="wc-boot is-err">
      <icons name="exclamation" class="w-6 h-6" /><b>{{ chat.bootError }}</b>
      <button type="button" class="wc-btn" @click="retryBoot">{{ $t('Try again') }}</button>
    </div>

    <div v-else-if="chat.booted" class="wc-main">
      <!-- slim icon rail (module page only): chats, new chat, analytics, settings, and you -->
      <nav v-if="mode === 'page' && layout !== 'narrow'" class="wc-rail" :aria-label="$t('WIMSChat')">
        <span class="wc-rail-logo" aria-hidden="true"><img src="/icons/icon-96x96.png" alt="" width="34" height="34" /></span>
        <button type="button" class="wc-rail-b is-on lg-tip" :data-tip="$t('Chats')" :aria-label="$t('Chats')" @click="view = 'list'"><icons name="chat" class="w-5 h-5" /></button>
        <button v-if="chat.can.start || chat.can.create_group" type="button" class="wc-rail-b lg-tip" :data-tip="$t('New chat')" :aria-label="$t('New chat')" @click="newChat = true"><icons name="plus" class="w-5 h-5" /></button>
        <Link v-if="$can('view-wimschat-analytics')" :href="route('wimschat.analytics')" class="wc-rail-b lg-tip" :data-tip="$t('Analytics')" :aria-label="$t('Analytics')"><icons name="chart-bar" class="w-5 h-5" /></Link>
        <Link v-if="$can('manage-wimschat-settings')" :href="route('wimschat.settings')" class="wc-rail-b lg-tip" :data-tip="$t('Settings')" :aria-label="$t('Settings')"><icons name="cog" class="w-5 h-5" /></Link>
        <span class="wc-rail-sp"></span>
        <button type="button" class="wc-rail-b lg-tip" :data-tip="$t('Chat settings')" :aria-label="$t('Chat settings')" @click="prefsOpen = true"><icons name="adjustments" class="w-5 h-5" /></button>
        <span v-if="chat.me" class="wc-rail-me"><chat-avatar :name="chat.me.name" :photo="chat.me.photo" :presence="chat.me.presence" size="md" /></span>
      </nav>

      <conversation-list v-show="layout !== 'narrow' || view === 'list'" @select="select" @new-chat="newChat = true" @jump="jump" @prefs="prefsOpen = true" />

      <div v-show="layout !== 'narrow' || view === 'thread'" class="wc-center">
        <chat-thread
          :show-back="layout === 'narrow'" @back="view = 'list'" @toggle-info="infoOpen = !infoOpen" @forward="fwd = $event"
          @profile="profile" @confirm="ask" @open-image="box = $event"
        />
        <!-- details: docked beside the thread when wide, a sliding sheet otherwise -->
        <transition name="wc-slide">
          <info-panel v-if="infoOpen && chat.activeId" :class="{ 'is-docked': infoDocked }" @close="infoOpen = false" @open-image="box = $event" @confirm="ask" />
        </transition>
      </div>
    </div>

    <!-- dialogs live INSIDE the workspace so they always sit above it, in the drawer and on the page -->
    <new-chat v-if="newChat" @close="newChat = false" @opened="view = 'thread'" />
    <prefs-modal v-if="prefsOpen" @close="prefsOpen = false" />

    <div v-if="fwd" class="wc-modal" role="dialog" aria-modal="true" :aria-label="$t('Forward message')" @keydown.esc.stop="fwd = null">
      <div class="wc-modal-back" @click="fwd = null"></div>
      <div class="wc-modal-card">
        <header><b>{{ $t('Forward to…') }}</b><button type="button" class="wc-ib" :aria-label="$t('Close')" @click="fwd = null"><icons name="cross" class="w-5 h-5" /></button></header>
        <p class="wc-quote-box">{{ fwd.body || '📎 ' + $t('Attachment') }}</p>
        <ul class="wc-people">
          <li v-for="c in forwardTargets" :key="c.id">
            <button type="button" :class="{ 'is-picked': fwdPick.includes(c.id) }" @click="toggleFwd(c.id)">
              <chat-avatar :name="c.title" :photo="c.type === 'group' ? c.avatar : c.user && c.user.photo" :group="c.type === 'group' && !c.avatar" />
              <span class="wc-person-t"><b>{{ c.title }}</b><small>{{ c.type === 'group' ? $t('Group') : $t('Direct message') }}</small></span>
              <span class="wc-check" :class="{ 'is-on': fwdPick.includes(c.id) }"><icons name="tick" class="w-3.5 h-3.5" /></span>
            </button>
          </li>
        </ul>
        <footer><small>{{ $t(':n selected (max 5)', { n: fwdPick.length }) }}</small><button type="button" class="wc-btn is-primary" :disabled="!fwdPick.length || fwdBusy" @click="doForward"><icons name="forward" class="w-4 h-4" />{{ $t('Forward') }}</button></footer>
      </div>
    </div>

    <div v-if="confirm" class="wc-modal" role="alertdialog" aria-modal="true" :aria-label="confirm.title" @keydown.esc.stop="confirm = null">
      <div class="wc-modal-back" @click="confirm = null"></div>
      <div class="wc-modal-card is-sm">
        <header><b>{{ confirm.title }}</b></header>
        <p class="wc-confirm-t">{{ confirm.text }}</p>
        <footer><button type="button" class="wc-btn" @click="confirm = null">{{ $t('Cancel') }}</button><button ref="okBtn" type="button" class="wc-btn" :class="confirm.danger ? 'is-danger-solid' : 'is-primary'" @click="runConfirm">{{ confirm.ok }}</button></footer>
      </div>
    </div>

    <div v-if="profileCard" class="wc-modal" role="dialog" aria-modal="true" @keydown.esc.stop="profileCard = null">
      <div class="wc-modal-back" @click="profileCard = null"></div>
      <div class="wc-modal-card is-sm wc-profile">
        <chat-avatar :name="profileCard.name" :photo="profileCard.photo" :presence="profileCard.presence" size="xl" />
        <h3>{{ profileCard.name }}</h3>
        <p v-if="profileCard.position" class="wc-info-sub">{{ profileCard.position }}</p>
        <p v-if="profileCard.presence && profileCard.presence.key !== 'none'" class="wc-info-pres"><i :style="{ background: profileCard.presence.color }"></i>{{ profileCard.presence.label }}</p>
        <footer><button type="button" class="wc-btn" @click="profileCard = null">{{ $t('Close') }}</button><button v-if="profileCard.id !== chat.me.id && chat.can.start" type="button" class="wc-btn is-primary" @click="chatWith(profileCard)"><icons name="chat" class="w-4 h-4" />{{ $t('Message') }}</button></footer>
      </div>
    </div>

    <!-- image viewer -->
    <div v-if="box" class="wc-lightbox" role="dialog" aria-modal="true" :aria-label="$t('Image viewer')" tabindex="-1" @keydown.esc.stop="box = null" @keydown.left="step(-1)" @keydown.right="step(1)">
      <button type="button" class="wc-lb-x" :aria-label="$t('Close')" @click="box = null"><icons name="cross" class="w-6 h-6" /></button>
      <button v-if="box.list.length > 1" type="button" class="wc-lb-n is-prev" :aria-label="$t('Previous')" @click="step(-1)"><icons name="arrow-left" class="w-6 h-6" /></button>
      <button v-if="box.list.length > 1" type="button" class="wc-lb-n is-next" :aria-label="$t('Next')" @click="step(1)"><icons name="arrow-left" class="w-6 h-6" style="transform: rotate(180deg)" /></button>
      <div class="wc-lb-stage" @click.self="box = null"><img :src="current.src" :alt="current.name" /></div>
      <div class="wc-lb-bar"><span><b>{{ current.name }}</b><small>{{ box.index + 1 }} / {{ box.list.length }}<template v-if="current.user"> · {{ current.user }}</template></small></span><a v-if="current.download" :href="current.download" class="wc-btn is-sm"><icons name="download" class="w-4 h-4" />{{ $t('Download') }}</a></div>
    </div>

    <transition name="wc-toast"><div v-if="chat.toast" class="wc-toast" :class="'is-' + chat.toast.tone" role="status">{{ chat.toast.text }}</div></transition>
  </div>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import ChatAvatar from './ChatAvatar.vue';
import ConversationList from './ConversationList.vue';
import ChatThread from './ChatThread.vue';
import InfoPanel from './InfoPanel.vue';
import NewChat from './NewChat.vue';
import PrefsModal from './PrefsModal.vue';
import { chat, boot, openConversation, forwardMessage, startDirect, toast, errorText } from '@/Core/wimschat';

export default {
  components: { Link, ChatAvatar, ConversationList, ChatThread, InfoPanel, NewChat, PrefsModal },
  props: {
    mode: { type: String, default: 'page' }, // page | drawer
    open: { type: Number, default: null },
  },
  emits: ['close'],
  data() {
    return {
      chat, layout: 'wide', view: 'list', infoOpen: false, newChat: false, prefsOpen: false, fwd: null, fwdPick: [], fwdBusy: false, confirm: null, box: null, profileCard: null, shake: false, ro: null,
    };
  },
  computed: {
    infoDocked() {
      return this.infoOpen && this.layout === 'wide';
    },
    forwardTargets() {
      return chat.list.items.filter(i => !i.archived);
    },
    current() {
      return this.box ? this.box.list[this.box.index] : null;
    },
  },
  watch: {
    'chat.nudgeAt'() {
      const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reduce) return;
      this.shake = true;
      setTimeout(() => (this.shake = false), 750);
    },
    'chat.activeId'(id) {
      if (id) this.view = 'thread';
    },
    box(v) {
      if (v) this.$nextTick(() => { const el = this.$el.querySelector('.wc-lightbox'); el && el.focus(); });
    },
    confirm(v) {
      if (v) this.$nextTick(() => this.$refs.okBtn && this.$refs.okBtn.focus());
    },
  },
  async mounted() {
    this.measure();
    if ('ResizeObserver' in window) {
      this.ro = new ResizeObserver(() => this.measure());
      this.ro.observe(this.$refs.root);
    } else {
      window.addEventListener('resize', this.measure);
    }
    await boot();
    if (this.open) {
      await openConversation(this.open);
      this.view = 'thread';
    } else if (chat.activeId) {
      this.view = 'thread';
    }
  },
  beforeUnmount() {
    if (this.ro) this.ro.disconnect();
    window.removeEventListener('resize', this.measure);
  },
  methods: {
    measure() {
      const w = this.$refs.root ? this.$refs.root.clientWidth : window.innerWidth;
      this.layout = w >= 1000 ? 'wide' : w >= 640 ? 'mid' : 'narrow';
    },
    async retryBoot() {
      await boot();
    },
    select(id) {
      this.infoOpen = this.infoOpen && this.layout === 'wide';
      openConversation(id);
      this.view = 'thread';
    },
    async jump({ conversationId, messageId }) {
      await openConversation(conversationId, messageId);
      this.view = 'thread';
      this.$nextTick(() => {
        const el = this.$el.querySelector('#wcm-' + messageId);
        if (el) el.scrollIntoView({ block: 'center' });
      });
    },
    onEsc() {
      /* each dialog handles its own Escape; this only stops the drawer's global handler when something is open */
    },
    ask(c) {
      this.confirm = c;
    },
    async runConfirm() {
      const c = this.confirm;
      this.confirm = null;
      if (c && c.run) await c.run();
    },
    profile(u) {
      if (!u) return;
      const m = chat.thread.members.find(x => x.user.id === u.id);
      this.profileCard = m ? m.user : { id: u.id, name: u.name, photo: u.photo, presence: null, position: null };
    },
    async chatWith(u) {
      this.profileCard = null;
      try {
        await startDirect(u.id);
        this.view = 'thread';
      } catch (e) {
        toast(errorText(e), 'error');
      }
    },
    toggleFwd(id) {
      if (this.fwdPick.includes(id)) this.fwdPick = this.fwdPick.filter(x => x !== id);
      else if (this.fwdPick.length < 5) this.fwdPick = this.fwdPick.concat([id]);
    },
    async doForward() {
      this.fwdBusy = true;
      try {
        const n = await forwardMessage(this.fwd.id, this.fwdPick);
        toast(this.$t('Forwarded to :n conversation(s)', { n }));
        this.fwd = null;
        this.fwdPick = [];
      } catch (e) {
        toast(errorText(e), 'error');
      } finally {
        this.fwdBusy = false;
      }
    },
    step(d) {
      const n = this.box.list.length;
      this.box.index = (this.box.index + d + n) % n;
    },
  },
};
</script>

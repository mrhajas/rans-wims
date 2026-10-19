<template>
  <section class="wc-thread" :aria-label="$t('Conversation')">
    <!-- no conversation chosen -->
    <div v-if="!chat.activeId" class="wc-welcome">
      <span class="wc-empty-ic is-lg"><icons name="chat" class="w-10 h-10" /></span>
      <b>{{ $t('WIMSChat') }}</b>
      <p>{{ $t('Pick a conversation or start a new one.') }}</p>
    </div>

    <template v-else>
      <header class="wc-thread-head">
        <button v-if="showBack" type="button" class="wc-ib" :aria-label="$t('Back to chats')" @click="$emit('back')"><icons name="arrow-left" class="w-5 h-5" /></button>
        <button type="button" class="wc-head-who" :aria-label="$t('Conversation details')" @click="$emit('toggle-info')">
          <chat-avatar :name="title" :photo="isGroup ? (conv && conv.avatar) : other && other.photo" :presence="isGroup ? null : other && other.presence" :group="isGroup && !(conv && conv.avatar)" size="md" />
          <span class="wc-head-t">
            <b>{{ title }}</b>
            <small :class="{ 'is-online': !isGroup && other && other.presence && other.presence.key === 'online' }">{{ subtitle }}</small>
          </span>
        </button>
        <div class="wc-head-actions">
          <button v-if="chat.can.search" type="button" class="wc-ib lg-tip" :data-tip="$t('Search this conversation')" :aria-label="$t('Search this conversation')" @click="toggleSearch"><icons name="search" class="w-5 h-5" /></button>
          <offline-lock v-if="chat.can.nudge && chat.features.nudge">
            <button type="button" class="wc-ib lg-tip" :data-tip="$t('Nudge')" :aria-label="$t('Nudge')" :disabled="nudging" @click="nudge"><icons name="bell" class="w-5 h-5" /></button>
          </offline-lock>
          <button type="button" class="wc-ib lg-tip" :data-tip="$t('Details')" :aria-label="$t('Details')" @click="$emit('toggle-info')"><icons name="info" class="w-5 h-5" /></button>
        </div>
      </header>

      <!-- search in this conversation -->
      <div v-if="searchOpen" class="wc-tsearch">
        <icons name="search" class="w-4 h-4" />
        <input ref="sq" v-model="sq" type="search" :placeholder="$t('Search in this conversation…')" :aria-label="$t('Search in this conversation')" autocomplete="off" @input="runSearch" @keydown.esc="toggleSearch" />
        <button type="button" :aria-label="$t('Close search')" @click="toggleSearch"><icons name="cross" class="w-4 h-4" /></button>
        <div v-if="sq.trim().length >= chat.config.search_min_chars" class="wc-tsearch-res">
          <p v-if="sLoading" class="wc-muted">{{ $t('Searching…') }}</p>
          <p v-else-if="!sHits.length" class="wc-muted">{{ $t('No matching messages.') }}</p>
          <button v-for="h in sHits" :key="h.id" type="button" class="wc-hit" @click="jumpTo(h.id)">
            <span class="wc-hit-top"><b>{{ h.user }}</b><time>{{ dateLabel(h.at) }}</time></span>
            <span class="wc-hit-body">{{ h.snippet || (h.files[0] && h.files[0].name) }}</span>
          </button>
        </div>
      </div>

      <!-- pinned messages -->
      <div v-if="chat.thread.pinned.length" class="wc-pinned">
        <button type="button" @click="jumpTo(chat.thread.pinned[pinIdx % chat.thread.pinned.length].id); pinIdx++">
          <icons name="pin" class="w-4 h-4" />
          <span><b>{{ $t('Pinned') }} <template v-if="chat.thread.pinned.length > 1">({{ (pinIdx % chat.thread.pinned.length) + 1 }}/{{ chat.thread.pinned.length }})</template></b>{{ chat.thread.pinned[pinIdx % chat.thread.pinned.length].text }}</span>
        </button>
      </div>

      <!-- messages -->
      <div ref="scroller" class="wc-scroll" role="log" aria-live="polite" :aria-label="$t('Messages')" @scroll.passive="onScroll">
        <div v-if="chat.thread.error && !chat.thread.messages.length" class="wc-welcome">
          <span class="wc-empty-ic"><icons name="exclamation" class="w-8 h-8" /></span>
          <b>{{ chat.thread.removed ? $t('You no longer have permission to access this conversation.') : chat.thread.error }}</b>
          <button v-if="!chat.thread.removed" type="button" class="wc-btn" @click="reload">{{ $t('Try again') }}</button>
        </div>

        <template v-else>
          <div v-if="chat.thread.moreBefore" class="wc-older"><button type="button" :disabled="chat.thread.loading" @click="loadOlderKeep">{{ chat.thread.loading ? $t('Loading…') : $t('Load earlier messages') }}</button></div>
          <div v-else-if="chat.thread.messages.length" class="wc-start">{{ $t('This is the beginning of the conversation.') }}</div>

          <div v-if="chat.thread.loading && !chat.thread.messages.length" class="wc-skel is-thread" aria-hidden="true"><i v-for="n in 5" :key="n" :class="n % 2 ? '' : 'r'"></i></div>

          <div v-if="!chat.thread.loading && !chat.thread.messages.length && !pending.length && !chat.thread.error" class="wc-welcome is-inline">
            <span class="wc-empty-ic"><icons name="chat" class="w-8 h-8" /></span>
            <b>{{ $t('Start the conversation.') }}</b>
            <p>{{ $t('Say hello, share a file or @mention a colleague.') }}</p>
          </div>

          <template v-for="(row, i) in rows" :key="row.key">
            <div v-if="row.day" class="wc-day"><span>{{ row.day }}</span></div>
            <div v-if="row.unreadDivider" class="wc-newline"><span>{{ $t('New messages') }}</span></div>
            <message-bubble
              :style="{ '--d': stagger(i) }" :m="row.m" :first="row.first" :last="row.last" :show-name="isGroup" :highlight="highlightId === row.m.id" :usernames="usernames" :nudge-target="title"
              @reply="startReply" @edit="startEdit" @delete="askDelete" @forward="$emit('forward', $event)" @pin="pin" @copy="copy" @react="react" @jump="jumpTo"
              @profile="$emit('profile', $event)" @open-image="openImage" @retry="retry" @discard="discard" @delete-attachment="askDeleteAttachment"
            />
          </template>

          <div v-if="chat.thread.moreAfter" class="wc-older"><button type="button" @click="latest">{{ $t('Jump to the latest messages') }}</button></div>
          <div v-if="typingText" class="wc-typing" aria-live="polite"><span class="wc-typing-dots"><i></i><i></i><i></i></span>{{ typingText }}</div>
        </template>

        <button v-if="showJump" type="button" class="wc-jump" @click="toBottom(true)">
          <icons name="down" class="w-4 h-4" />{{ newCount ? $t(':n new', { n: newCount }) : $t('Latest') }}
        </button>
      </div>

      <composer
        v-if="chat.thread.conversation && !chat.thread.removed"
        ref="composer" :conversation-id="chat.activeId" :members="chat.thread.members" :reply-to="replyTo" :editing="editing"
        @send="send" @save-edit="saveEdit" @cancel-reply="replyTo = null" @cancel-edit="editing = null"
      />
      <p v-else-if="chat.thread.removed" class="wc-locked"><icons name="lock-closed" class="w-4 h-4" />{{ $t('You no longer have permission to access this conversation.') }}</p>
    </template>
  </section>
</template>

<script>
import ChatAvatar from './ChatAvatar.vue';
import MessageBubble from './MessageBubble.vue';
import Composer from './Composer.vue';
import { chat, openConversation, loadOlder, sendMessage, editMessage, deleteMessage, reactTo, pinMessage, sendNudge, retryPending, discardPending, deleteAttachment, pendingFor, toast, errorText } from '@/Core/wimschat';
import { route } from 'ziggy-js';

export default {
  components: { ChatAvatar, MessageBubble, Composer },
  props: { showBack: { type: Boolean, default: false } },
  emits: ['back', 'toggle-info', 'forward', 'profile', 'confirm', 'open-image'],
  data() {
    return {
      chat, replyTo: null, editing: null, highlightId: null, showJump: false, newCount: 0, unreadFrom: null, nudging: false, pinIdx: 0,
      intro: false, searchOpen: false, sq: '', sHits: [], sLoading: false, sTimer: null, stick: true, lastCount: 0, lastId: 0,
    };
  },
  computed: {
    conv() {
      return chat.thread.conversation || chat.list.items.find(i => i.id === chat.activeId) || null;
    },
    isGroup() {
      return this.conv && this.conv.type === 'group';
    },
    other() {
      return this.conv && this.conv.user;
    },
    title() {
      return this.conv ? this.conv.title : '';
    },
    subtitle() {
      if (!this.conv) return '';
      if (this.isGroup) {
        const on = chat.thread.members.filter(m => m.user.presence && m.user.presence.key === 'online').length;
        return this.$t(':n members', { n: this.conv.members_count }) + (chat.features.presence && on ? ' · ' + this.$t(':n online', { n: on }) : '');
      }
      const p = this.other && this.other.presence;
      if (!p || p.key === 'none') return this.other && this.other.position ? this.other.position : '';
      return p.label + (p.last_seen_label && p.key !== 'online' ? ' · ' + this.$t('last seen :t', { t: p.last_seen_label }) : '');
    },
    usernames() {
      return chat.thread.members.map(m => (m.user.username || '').toLowerCase());
    },
    pending() {
      return pendingFor(chat.activeId);
    },
    all() {
      return chat.thread.messages.concat(this.pending);
    },
    rows() {
      const out = [];
      let prev = null;
      let prevDay = null;
      let dividerDone = false;
      this.all.forEach((m, i) => {
        const d = new Date(m.created_at);
        const dayKey = d.toDateString();
        const row = { key: m.local ? m.uuid : m.id, m, first: true, last: true, day: null, unreadDivider: false };
        if (dayKey !== prevDay) row.day = this.dateLabel(m.created_at);
        if (!dividerDone && this.unreadFrom !== null && !m.mine && typeof m.id === 'number' && m.id > this.unreadFrom && m.type !== 'system') {
          row.unreadDivider = true;
          dividerDone = true;
        }
        const clusterable = (x) => x && x.type !== 'system' && x.type !== 'nudge';
        if (prev && clusterable(prev.m) && clusterable(m) && prev.m.user && m.user && prev.m.user.id === m.user.id && !row.day && !row.unreadDivider && Math.abs(d - new Date(prev.m.created_at)) < 300000) {
          prev.last = false;
          row.first = false;
        }
        prev = row;
        prevDay = dayKey;
        out.push(row);
      });

      return out;
    },
    typingText() {
      const t = chat.thread.typing;
      if (!t.length) return '';
      if (t.length === 1) return this.$t(':a is typing…', { a: t[0].name });
      if (t.length === 2) return this.$t(':a and :b are typing…', { a: t[0].name, b: t[1].name });
      return this.$t('Several people are typing…');
    },
  },
  watch: {
    'chat.activeId'() {
      this.replyTo = null;
      this.editing = null;
      this.unreadFrom = null;
      this.searchOpen = false;
      this.sq = '';
      this.stick = true;
      this.newCount = 0;
      this.showJump = false;
      this.lastCount = 0;
      this.lastId = 0;
    },
    'chat.thread.loading'(v, old) {
      if (old && !v && this.unreadFrom === null) {
        const me = chat.thread.members.find(m => m.user.id === chat.me.id);
        this.unreadFrom = me ? me.last_read : null;
        this.intro = true; // messages glide in one after another when a chat opens
        setTimeout(() => (this.intro = false), 1600);
        this.$nextTick(() => (chat.thread.anchor ? this.scrollToMessage(chat.thread.anchor) : this.toBottom(false)));
      }
    },
    all: {
      handler(list) {
        const n = list.length;
        const lastMsg = list[n - 1];
        const lastKey = lastMsg ? (lastMsg.local ? 1e12 + n : lastMsg.id) : 0;
        if (lastKey !== this.lastId && this.lastId !== 0) {
          // something new arrived at the bottom
          if (this.stick || (lastMsg && lastMsg.mine)) this.$nextTick(() => this.toBottom(true));
          else if (lastMsg && !lastMsg.mine) this.newCount += 1;
        }
        this.lastId = lastKey;
        this.lastCount = n;
      },
    },
    'chat.nudgeAt'() {
      this.$nextTick(() => this.toBottom(true));
    },
  },
  methods: {
    dateLabel(iso) {
      const d = new Date(iso);
      const t = new Date();
      const diff = Math.round((new Date(t.getFullYear(), t.getMonth(), t.getDate()) - new Date(d.getFullYear(), d.getMonth(), d.getDate())) / 86400000);
      if (diff === 0) return this.$t('Today');
      if (diff === 1) return this.$t('Yesterday');

      return d.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long', year: d.getFullYear() === t.getFullYear() ? undefined : 'numeric' });
    },
    stagger(i) {
      return this.intro ? Math.max(0, i - (this.rows.length - 14)) : 0;
    },
    scroller() {
      return this.$refs.scroller;
    },
    onScroll() {
      const el = this.scroller();
      if (!el) return;
      const gap = el.scrollHeight - el.scrollTop - el.clientHeight;
      this.stick = gap < 140;
      this.showJump = gap > 320;
      if (this.stick) this.newCount = 0;
      if (el.scrollTop < 60 && chat.thread.moreBefore && !chat.thread.loading) this.loadOlderKeep();
    },
    toBottom(smooth) {
      const el = this.scroller();
      if (!el) return;
      const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      el.scrollTo({ top: el.scrollHeight, behavior: smooth && !reduce ? 'smooth' : 'auto' });
      this.newCount = 0;
    },
    async loadOlderKeep() {
      const el = this.scroller();
      const before = el ? el.scrollHeight - el.scrollTop : 0;
      await loadOlder();
      this.$nextTick(() => { if (el) el.scrollTop = el.scrollHeight - before; });
    },
    reload() {
      openConversation(chat.activeId);
    },
    latest() {
      this.stick = true;
      openConversation(chat.activeId);
    },
    scrollToMessage(id) {
      const el = this.$el.querySelector('#wcm-' + id);
      if (el) {
        el.scrollIntoView({ block: 'center', behavior: 'auto' });
        this.highlightId = id;
        setTimeout(() => { if (this.highlightId === id) this.highlightId = null; }, 2200);
        return true;
      }
      return false;
    },
    async jumpTo(id) {
      if (!this.scrollToMessage(id)) {
        await openConversation(chat.activeId, id);
        this.$nextTick(() => this.scrollToMessage(id));
      }
      this.searchOpen = false;
    },
    // ---- search in conversation
    toggleSearch() {
      this.searchOpen = !this.searchOpen;
      if (this.searchOpen) this.$nextTick(() => this.$refs.sq && this.$refs.sq.focus());
      else { this.sq = ''; this.sHits = []; }
    },
    runSearch() {
      clearTimeout(this.sTimer);
      this.sTimer = setTimeout(async () => {
        const q = this.sq.trim();
        if (q.length < chat.config.search_min_chars) { this.sHits = []; return; }
        this.sLoading = true;
        try {
          const { data } = await window.axios.get(route('wimschat.search'), { params: { q, conversation: chat.activeId } });
          this.sHits = data.messages;
        } catch (e) {
          toast(errorText(e), 'error');
        } finally {
          this.sLoading = false;
        }
      }, 300);
    },
    // ---- actions
    send(p) {
      const reply = this.replyTo;
      this.replyTo = null;
      this.stick = true;
      sendMessage({ body: p.body, files: p.files, replyTo: reply, voice: p.voice || null });
    },
    startReply(m) {
      this.editing = null;
      this.replyTo = m;
    },
    startEdit(m) {
      this.replyTo = null;
      this.editing = m;
    },
    async saveEdit({ id, body }) {
      try {
        await editMessage(id, body);
        this.editing = null;
        this.$refs.composer && this.$refs.composer.reset();
      } catch (e) {
        toast(errorText(e), 'error');
      }
    },
    askDelete(m) {
      this.$emit('confirm', {
        title: this.$t('Delete this message?'), text: this.$t('It will disappear for everyone in the conversation. A record is kept for audit.'), ok: this.$t('Delete'), danger: true,
        run: async () => { try { await deleteMessage(m.id); } catch (e) { toast(errorText(e), 'error'); } },
      });
    },
    askDeleteAttachment(a) {
      this.$emit('confirm', {
        title: this.$t('Delete this file?'), text: a.name, ok: this.$t('Delete'), danger: true,
        run: async () => { try { await deleteAttachment(a.id); await openConversation(chat.activeId); } catch (e) { toast(errorText(e), 'error'); } },
      });
    },
    async pin(m) {
      try { await pinMessage(m.id); } catch (e) { toast(errorText(e), 'error'); }
    },
    async react(m, emoji) {
      try { await reactTo(m.id, emoji); } catch (e) { toast(errorText(e), 'error'); }
    },
    async copy(m) {
      try { await navigator.clipboard.writeText(m.body || ''); toast(this.$t('Copied')); } catch (e) { toast(this.$t('Could not copy. Select the text and copy it manually.'), 'error'); }
    },
    async nudge() {
      this.nudging = true;
      try { await sendNudge(); toast(this.$t('Nudge sent')); } catch (e) { toast(errorText(e), 'error'); } finally { setTimeout(() => (this.nudging = false), 800); }
    },
    retry(m) {
      retryPending(m);
    },
    discard(m) {
      discardPending(m);
    },
    openImage({ attachment }) {
      const list = [];
      this.all.forEach(m => (m.attachments || []).forEach(a => { if (a.is_image && !a.purged && (a.view_url || a.thumb_url || a.localUrl)) list.push({ id: a.id, name: a.name, src: a.view_url || a.thumb_url || a.localUrl, download: a.download_url, user: m.user && m.user.name, at: m.created_at }); }));
      const index = Math.max(0, list.findIndex(x => x.id === attachment.id));
      this.$emit('open-image', { list, index });
    },
  },
};
</script>

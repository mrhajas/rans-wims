<template>
  <section class="wc-list" :aria-label="$t('Conversations')">
    <header class="wc-list-head">
      <h2>{{ $t('Chats') }} <span v-if="chat.unread" class="wc-count-pill">{{ chat.unread > 99 ? '99+' : chat.unread }}</span></h2>
      <div class="wc-list-actions">
        <button v-if="canStatus" type="button" class="wc-me lg-tip" :data-tip="$t('Your status: :s', { s: chat.me.presence.label })" :aria-label="$t('Your status')" @click.stop="statusOpen = !statusOpen">
          <chat-avatar :name="chat.me.name" :photo="chat.me.photo" :presence="chat.me.presence" size="sm" />
        </button>
        <button v-if="chat.can.start || chat.can.create_group" type="button" class="wc-ib is-primary lg-tip" :data-tip="$t('New chat')" :aria-label="$t('New chat')" @click="$emit('new-chat')"><icons name="plus" class="w-5 h-5" /></button>
        <button type="button" class="wc-ib lg-tip" :data-tip="$t('Chat settings')" :aria-label="$t('Chat settings')" @click="$emit('prefs')"><icons name="cog" class="w-5 h-5" /></button>
        <slot name="actions" />
        <div v-if="statusOpen" class="wc-pop wc-status-pop" role="menu" @click.stop>
          <b>{{ $t('Set your status') }}</b>
          <button type="button" role="menuitem" :class="{ 'is-on': chat.me.status === 'auto' }" @click="setStatus('auto')"><i style="background:#16a34a"></i>{{ $t('Automatic (follows your activity)') }}</button>
          <button v-for="s in chat.config.statuses" :key="s.key" type="button" role="menuitem" :class="{ 'is-on': chat.me.status === s.key }" @click="setStatus(s.key)"><i :style="{ background: s.color }"></i>{{ s.label }}</button>
        </div>
      </div>
    </header>

    <div class="wc-search">
      <icons name="search" class="w-4 h-4" />
      <input v-model="q" type="search" :placeholder="$t('Search chats and messages…')" :aria-label="$t('Search chats and messages')" autocomplete="off" @input="onSearch" />
      <button v-if="q" type="button" :aria-label="$t('Clear')" @click="q = ''; onSearch()"><icons name="cross" class="w-4 h-4" /></button>
      <button v-if="chat.can.search" type="button" class="lg-tip" :class="{ 'is-on': filtersOn }" :data-tip="$t('Search filters')" :aria-label="$t('Search filters')" :aria-expanded="filtersOpen" @click="filtersOpen = !filtersOpen"><icons name="filter" class="w-4 h-4" /></button>
    </div>
    <div v-if="filtersOpen" class="wc-filters">
      <label>{{ $t('Only') }}<css-select v-model="f.kind" @change="onSearch"><option value="all">{{ $t('Everything') }}</option><option value="media">{{ $t('Images') }}</option><option value="files">{{ $t('Files') }}</option><option value="links">{{ $t('Links') }}</option><option value="unread">{{ $t('Unread') }}</option></css-select></label>
      <label>{{ $t('From') }}<date-picker v-model="f.from" :max="f.to || null" @change="onSearch" flush /></label>
      <label>{{ $t('To') }}<date-picker v-model="f.to" :min="f.from || null" @change="onSearch" flush /></label>
      <label>{{ $t('Sent by') }}<css-select v-model="f.user" @change="onSearch"><option value="">{{ $t('Anyone') }}</option><option v-for="u in people" :key="u.id" :value="u.id">{{ u.name }}</option></css-select></label>
      <button v-if="filtersOn" type="button" class="wc-btn is-sm" @click="resetFilters">{{ $t('Clear filters') }}</button>
    </div>

    <nav class="wc-tabs" role="tablist" :aria-label="$t('Filter conversations')">
      <button v-for="t in tabs" :key="t.key" type="button" role="tab" :aria-selected="chat.list.filter === t.key" :class="{ 'is-on': chat.list.filter === t.key }" @click="setFilter(t.key)">
        {{ t.label }}<b v-if="t.key === 'unread' && chat.unread">{{ chat.unread }}</b>
      </button>
    </nav>

    <div class="wc-list-body" @scroll.passive="onScroll">
      <!-- group invitations: nobody enters a group before accepting -->
      <div v-if="chat.invitations.length && !searching" class="wc-invites">
        <b class="wc-hits-t">{{ $t('Invitations') }} <span class="wc-count-pill">{{ chat.invitations.length }}</span></b>
        <div v-for="(iv, i) in chat.invitations" :key="iv.conversation_id" class="wc-invite" :style="{ '--i': i }">
          <chat-avatar :name="iv.title" :photo="iv.avatar" group size="lg" />
          <span class="wc-invite-t">
            <b>{{ iv.title }}</b>
            <small>{{ iv.invited_by ? $t(':n invited you', { n: iv.invited_by.name }) : $t('You are invited') }} · {{ $t(':n members', { n: iv.members_count }) }}</small>
            <small v-if="iv.description" class="wc-invite-d">{{ iv.description }}</small>
          </span>
          <span class="wc-invite-a">
            <button type="button" class="wc-btn is-primary is-sm" :disabled="busyInvite === iv.conversation_id" @click="answer(iv, true)"><icons name="tick" class="w-4 h-4" />{{ $t('Accept') }}</button>
            <button type="button" class="wc-btn is-sm" :disabled="busyInvite === iv.conversation_id" @click="answer(iv, false)">{{ $t('Decline') }}</button>
          </span>
        </div>
      </div>

      <!-- who is around right now -->
      <div v-if="chat.features.presence && chat.online.length && !searching && chat.list.filter === 'all'" class="wc-online" :aria-label="$t('Online now')">
        <b class="wc-hits-t"><i class="wc-live"></i>{{ $t('Online now') }} <span>{{ chat.online.length }}</span></b>
        <div class="wc-online-row">
          <button v-for="(u, i) in chat.online" :key="u.id" type="button" class="wc-online-u lg-tip" :data-tip="u.name + ' · ' + u.presence.label" :style="{ '--i': i }" :disabled="!chat.can.start" @click="chatWith(u)">
            <chat-avatar :name="u.name" :photo="u.photo" :presence="u.presence" size="md" />
            <span>{{ u.name.split(' ')[0] }}</span>
          </button>
        </div>
      </div>

      <!-- message hits -->
      <div v-if="searching" class="wc-hits">
        <b class="wc-hits-t">{{ $t('Messages') }}</b>
        <p v-if="hitsLoading" class="wc-muted">{{ $t('Searching…') }}</p>
        <p v-else-if="!hits.length" class="wc-muted">{{ $t('No matching messages.') }}</p>
        <button v-for="h in hits" :key="h.id" type="button" class="wc-hit" @click="$emit('jump', { conversationId: h.conversation_id, messageId: h.id })">
          <span class="wc-hit-top"><b>{{ h.conversation }}</b><time>{{ day(h.at) }}</time></span>
          <span class="wc-hit-body"><em>{{ h.user }}:</em> {{ h.snippet || (h.files[0] && h.files[0].name) }}</span>
        </button>
        <b v-if="chat.list.items.length" class="wc-hits-t">{{ $t('Chats') }}</b>
      </div>

      <ul class="wc-items" role="list">
        <li v-for="(c, i) in chat.list.items" :key="c.id" :style="{ '--i': Math.min(i, 12) }">
          <button type="button" class="wc-item" :class="{ 'is-active': c.id === chat.activeId, 'is-unread': c.unread > 0 || c.marked_unread }" @click="$emit('select', c.id)">
            <chat-avatar :name="c.title" :photo="c.type === 'group' ? c.avatar : c.user && c.user.photo" :presence="c.type === 'direct' && c.user ? c.user.presence : null" :group="c.type === 'group' && !c.avatar" size="lg" />
            <span class="wc-item-t">
              <span class="wc-item-row">
                <b class="wc-item-name">{{ c.title }}</b>
                <icons v-if="c.pinned" name="pin" class="wc-item-ic w-3 h-3" />
                <icons v-if="c.muted" name="bell-off" class="wc-item-ic w-3 h-3" />
                <time class="wc-item-time">{{ day(c.last && c.last.at) }}</time>
              </span>
              <span class="wc-item-row">
                <span class="wc-item-prev" :class="{ 'is-deleted': c.last && c.last.kind === 'deleted' }">
                  <template v-if="c.last && c.last.kind !== 'none'"><em v-if="c.last.mine">{{ $t('You') }}: </em>{{ c.last.text }}</template>
                  <template v-else>{{ c.type === 'group' ? $t('Group created') : $t('Say hello 👋') }}</template>
                </span>
                <span v-if="c.unread > 0" class="wc-badge" :class="{ 'is-muted': c.muted }">{{ c.unread > 99 ? '99+' : c.unread }}</span>
                <span v-else-if="c.marked_unread" class="wc-badge is-dot" :class="{ 'is-muted': c.muted }"></span>
              </span>
            </span>
          </button>
          <button type="button" class="wc-item-more lg-tip" :data-tip="$t('More')" :aria-label="$t('More')" aria-haspopup="menu" @click.stop="menuFor = menuFor === c.id ? null : c.id"><icons name="more" class="w-4 h-4" /></button>
          <div v-if="menuFor === c.id" class="wc-pop wc-menu wc-item-menu" role="menu" @click.stop>
            <button type="button" role="menuitem" @click="state(c, { pinned: !c.pinned })"><icons name="pin" class="w-4 h-4" />{{ c.pinned ? $t('Unpin') : $t('Pin') }}</button>
            <button type="button" role="menuitem" @click="state(c, c.unread || c.marked_unread ? readNow(c) : { marked_unread: true })"><icons name="tick" class="w-4 h-4" />{{ c.unread || c.marked_unread ? $t('Mark as read') : $t('Mark as unread') }}</button>
            <button type="button" role="menuitem" @click="state(c, c.muted ? { muted: false } : { muted: true })"><icons :name="c.muted ? 'bell' : 'bell-off'" class="w-4 h-4" />{{ c.muted ? $t('Unmute') : $t('Mute') }}</button>
            <button type="button" role="menuitem" @click="state(c, { archived: !c.archived })"><icons name="archive-box" class="w-4 h-4" />{{ c.archived ? $t('Unarchive') : $t('Archive') }}</button>
            <button type="button" role="menuitem" class="is-danger" @click="state(c, { hidden: true })"><icons name="trash" class="w-4 h-4" />{{ $t('Remove from my view') }}</button>
          </div>
        </li>
      </ul>

      <div v-if="chat.list.loading && !chat.list.items.length" class="wc-skel" aria-hidden="true"><i v-for="n in 6" :key="n"></i></div>

      <div v-if="chat.list.loaded && !chat.list.loading && !chat.list.items.length && !searching" class="wc-empty">
        <span class="wc-empty-ic"><icons name="chat" class="w-8 h-8" /></span>
        <b>{{ emptyTitle }}</b>
        <p>{{ emptyText }}</p>
        <button v-if="chat.list.filter === 'all' && !chat.list.q && (chat.can.start || chat.can.create_group)" type="button" class="wc-btn is-primary" @click="$emit('new-chat')"><icons name="plus" class="w-4 h-4" />{{ $t('Start a chat') }}</button>
      </div>
      <button v-if="chat.list.more" type="button" class="wc-more" :disabled="chat.list.loading" @click="loadMore">{{ $t('Load more') }}</button>
    </div>
  </section>
</template>

<script>
import ChatAvatar from './ChatAvatar.vue';
import { chat, loadList, setFilter, setQuery, setState, savePrefs, toast, errorText, respondInvitation, startDirect } from '@/Core/wimschat';
import { route } from 'ziggy-js';

export default {
  components: { ChatAvatar },
  emits: ['select', 'new-chat', 'jump', 'prefs'],
  data() {
    return { chat, busyInvite: null, q: '', f: { kind: 'all', from: '', to: '', user: '' }, filtersOpen: false, people: [], hits: [], hitsLoading: false, menuFor: null, statusOpen: false, timer: null };
  },
  computed: {
    tabs() {
      return [
        { key: 'all', label: this.$t('All') }, { key: 'unread', label: this.$t('Unread') }, { key: 'pinned', label: this.$t('Pinned') },
        { key: 'groups', label: this.$t('Groups') }, { key: 'archived', label: this.$t('Archived') },
      ];
    },
    filtersOn() {
      return this.f.kind !== 'all' || !!this.f.from || !!this.f.to || !!this.f.user;
    },
    searching() {
      return chat.can.search && (this.q.trim().length >= chat.config.search_min_chars || this.filtersOn);
    },
    canStatus() {
      return chat.features.presence && chat.config.allow_manual_status;
    },
    emptyTitle() {
      if (chat.list.q) return this.$t('No matching conversations or messages found.');
      return { unread: this.$t('You are all caught up'), pinned: this.$t('Nothing pinned yet'), groups: this.$t('No groups yet'), archived: this.$t('Nothing archived') }[chat.list.filter] || this.$t('No conversations yet');
    },
    emptyText() {
      if (chat.list.q) return this.$t('Try a different name or word.');
      return { unread: this.$t('No unread conversations.'), pinned: this.$t('Pin a conversation to keep it at the top.'), groups: this.$t('Create a group to talk with several people at once.'), archived: this.$t('Archived conversations appear here.') }[chat.list.filter] || this.$t('Start a conversation with a WIMS user.');
    },
  },
  mounted() {
    document.addEventListener('click', this.closeMenus);
    if (!chat.list.loaded) loadList(true);
  },
  beforeUnmount() {
    document.removeEventListener('click', this.closeMenus);
  },
  methods: {
    setFilter,
    closeMenus() {
      this.menuFor = null;
      this.statusOpen = false;
    },
    day(iso) {
      if (!iso) return '';
      const d = new Date(iso);
      const now = new Date();
      const diff = Math.floor((new Date(now.getFullYear(), now.getMonth(), now.getDate()) - new Date(d.getFullYear(), d.getMonth(), d.getDate())) / 86400000);
      if (diff === 0) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      if (diff === 1) return this.$t('Yesterday');
      if (diff < 7) return d.toLocaleDateString([], { weekday: 'short' });

      return d.toLocaleDateString([], { day: '2-digit', month: 'short' });
    },
    onSearch() {
      clearTimeout(this.timer);
      this.timer = setTimeout(async () => {
        const q = this.q.trim();
        setQuery(q.length >= 1 ? q : '');
        this.hits = [];
        if (!this.searching) return;
        this.hitsLoading = true;
        if (this.filtersOpen && !this.people.length) this.loadPeople();
        try {
          const { data } = await window.axios.get(route('wimschat.search'), { params: { q: q.length >= chat.config.search_min_chars ? q : '', kind: this.f.kind, from: this.f.from || undefined, to: this.f.to || undefined, user: this.f.user || undefined } });
          if (this.q.trim() === q) this.hits = data.messages;
        } catch (e) {
          toast(errorText(e), 'error');
        } finally {
          this.hitsLoading = false;
        }
      }, 300);
    },
    async answer(iv, accept) {
      this.busyInvite = iv.conversation_id;
      try {
        await respondInvitation(iv.conversation_id, accept);
        if (accept) { toast(this.$t('Welcome to :t!', { t: iv.title })); this.$emit('select', iv.conversation_id); }
        else toast(this.$t('Invitation declined.'));
      } catch (e) {
        toast(errorText(e), 'error');
      } finally {
        this.busyInvite = null;
      }
    },
    async chatWith(u) {
      try { const c = await startDirect(u.id); this.$emit('select', c.id); } catch (e) { toast(errorText(e), 'error'); }
    },
    async loadPeople() {
      try { const { data } = await window.axios.get(route('wimschat.users'), { params: { q: '' } }); this.people = data.users.concat([{ id: chat.me.id, name: this.$t('Me') }]); } catch (e) { /* optional */ }
    },
    resetFilters() {
      this.f = { kind: 'all', from: '', to: '', user: '' };
      this.onSearch();
    },
    onScroll(e) {
      const el = e.target;
      if (chat.list.more && !chat.list.loading && el.scrollTop + el.clientHeight > el.scrollHeight - 120) this.loadMore();
    },
    loadMore() {
      loadList(false);
    },
    readNow(c) {
      // marking read = same request the thread uses
      window.axios.post(route('wimschat.conversations.read', { conversation: c.id }), {}).then(({ data }) => { chat.unread = data.unread; if (data.conversation) this.$nextTick(() => { const i = chat.list.items.findIndex(x => x.id === c.id); if (i > -1) chat.list.items.splice(i, 1, data.conversation); }); }).catch(() => {});

      return null;
    },
    async state(c, patch) {
      this.menuFor = null;
      if (patch === null) return;
      try {
        await setState(c.id, patch);
        if (patch.hidden) toast(this.$t('Conversation removed from your view.'));
      } catch (e) {
        toast(errorText(e), 'error');
      }
    },
    async setStatus(key) {
      this.statusOpen = false;
      try {
        await savePrefs({ status: key });
      } catch (e) {
        toast(errorText(e), 'error');
      }
    },
  },
};
</script>

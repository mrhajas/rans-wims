<template>
  <div class="wc-modal" role="dialog" aria-modal="true" :aria-label="$t('New chat')" @keydown.esc.stop="$emit('close')">
    <div class="wc-modal-back" @click="$emit('close')"></div>
    <div class="wc-modal-card">
      <header><b>{{ $t('New chat') }}</b><button type="button" class="wc-ib" :aria-label="$t('Close')" @click="$emit('close')"><icons name="cross" class="w-5 h-5" /></button></header>

      <div v-if="chat.can.create_group && chat.can.start" class="wc-seg">
        <button type="button" :class="{ 'is-on': mode === 'direct' }" @click="mode = 'direct'"><icons name="user" class="w-4 h-4" />{{ $t('Direct message') }}</button>
        <button type="button" :class="{ 'is-on': mode === 'group' }" @click="mode = 'group'"><icons name="users" class="w-4 h-4" />{{ $t('New group') }}</button>
      </div>

      <label v-if="mode === 'group'" class="wc-field">{{ $t('Group name') }}<input ref="title" v-model="title" maxlength="120" :placeholder="$t('e.g. Site team - Chukwani')" /></label>

      <p v-if="mode === 'group' && chat.config.invite_required" class="wc-hint"><icons name="info" class="w-4 h-4" />{{ $t('People you add receive an invitation. They enter the group only after they accept.') }}</p>
      <div v-if="mode === 'group' && picked.length" class="wc-pick-chips">
        <span v-for="u in picked" :key="u.id" class="wc-pchip">{{ u.name }}<button type="button" :aria-label="$t('Remove')" @click="toggle(u)"><icons name="cross" class="w-3 h-3" /></button></span>
      </div>

      <div class="wc-search">
        <icons name="search" class="w-4 h-4" />
        <input ref="q" v-model="q" type="search" :placeholder="$t('Search by name, username, email, staff no. or position…')" :aria-label="$t('Search people')" autocomplete="off" @input="search" />
      </div>

      <ul class="wc-people" role="list">
        <li v-for="u in users" :key="u.id">
          <button type="button" :class="{ 'is-picked': isPicked(u), 'is-locked': !u.can_chat }" :disabled="!u.can_chat" :aria-disabled="!u.can_chat" @click="mode === 'direct' ? chat1(u) : toggle(u)">
            <chat-avatar :name="u.name" :photo="u.photo" :presence="u.presence" />
            <span class="wc-person-t"><b>{{ u.name }}</b><small v-if="u.can_chat">{{ [u.position, '@' + u.username].filter(Boolean).join(' · ') }}</small><small v-else class="wc-person-warn"><icons name="lock-closed" class="w-3 h-3" />{{ $t('Does not have permission to chat') }}</small></span>
            <span v-if="!u.can_chat" class="wc-noaccess lg-tip" :data-tip="$t('This person does not have WIMSChat access yet. Grant “View WIMSChat” in Roles & Permissions.')">{{ $t('No chat permission') }}</span>
            <span v-else-if="u.presence && u.presence.key !== 'none'" class="wc-person-pres" :style="{ color: u.presence.color }">{{ u.presence.label }}</span>
            <span v-if="mode === 'group' && u.can_chat" class="wc-check" :class="{ 'is-on': isPicked(u) }"><icons name="tick" class="w-3.5 h-3.5" /></span>
          </button>
        </li>
        <li v-if="loading" class="wc-muted">{{ $t('Searching…') }}</li>
        <li v-else-if="users.length" class="wc-muted wc-count-line">{{ $t(':n people', { n: users.length }) }} · {{ $t(':n can use chat', { n: users.filter(u => u.can_chat).length }) }}</li>
        <li v-else-if="!users.length" class="wc-muted">{{ $t('No people found.') }}</li>
      </ul>

      <p v-if="err" class="wc-err" role="alert"><icons name="exclamation" class="w-4 h-4" />{{ err }}</p>
      <footer v-if="mode === 'group'">
        <small>{{ $t(':n selected (up to :max)', { n: picked.length, max: chat.config.group_max_members - 1 }) }}</small>
        <button type="button" class="wc-btn is-primary" :disabled="busy || title.trim().length < 2 || !picked.length" @click="create"><icons name="users" class="w-4 h-4" />{{ chat.config.invite_required ? $t('Create & invite') : $t('Create group') }}</button>
      </footer>
    </div>
  </div>
</template>

<script>
import ChatAvatar from './ChatAvatar.vue';
import { chat, startDirect, createGroup, errorText } from '@/Core/wimschat';
import { route } from 'ziggy-js';

export default {
  components: { ChatAvatar },
  props: { startMode: { type: String, default: null } },
  emits: ['close', 'opened'],
  data() {
    return { chat, mode: this.startMode || (chat.can.start ? 'direct' : 'group'), q: '', users: [], picked: [], title: '', loading: false, busy: false, err: '', timer: null };
  },
  mounted() {
    this.search();
    this.$nextTick(() => this.$refs.q && this.$refs.q.focus());
  },
  methods: {
    search() {
      clearTimeout(this.timer);
      this.timer = setTimeout(async () => {
        this.loading = true;
        try {
          const { data } = await window.axios.get(route('wimschat.users'), { params: { q: this.q, all: 1 } });
          // people who can chat first, then everyone else (shown locked), each group A–Z
          this.users = data.users.slice().sort((a, b) => (b.can_chat - a.can_chat) || a.name.localeCompare(b.name));
        } catch (e) {
          this.err = errorText(e);
        } finally {
          this.loading = false;
        }
      }, 220);
    },
    isPicked(u) {
      return this.picked.some(p => p.id === u.id);
    },
    toggle(u) {
      this.picked = this.isPicked(u) ? this.picked.filter(p => p.id !== u.id) : this.picked.concat([u]);
    },
    async chat1(u) {
      this.busy = true;
      this.err = '';
      try {
        await startDirect(u.id);
        this.$emit('opened');
        this.$emit('close');
      } catch (e) {
        this.err = errorText(e);
      } finally {
        this.busy = false;
      }
    },
    async create() {
      this.busy = true;
      this.err = '';
      try {
        await createGroup({ title: this.title.trim(), members: this.picked.map(p => p.id) });
        this.$emit('opened');
        this.$emit('close');
      } catch (e) {
        this.err = errorText(e);
      } finally {
        this.busy = false;
      }
    },
  },
};
</script>

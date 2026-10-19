<template>
  <aside class="wc-info" :aria-label="$t('Conversation details')">
    <header class="wc-info-head">
      <b>{{ isGroup ? $t('Group info') : $t('Contact info') }}</b>
      <button type="button" class="wc-ib" :aria-label="$t('Close')" @click="$emit('close')"><icons name="cross" class="w-5 h-5" /></button>
    </header>

    <div v-if="conv" class="wc-info-body">
      <div class="wc-info-hero">
        <chat-avatar :name="conv.title" :photo="isGroup ? conv.avatar : conv.user && conv.user.photo" :presence="!isGroup && conv.user ? conv.user.presence : null" :group="isGroup && !conv.avatar" size="xl" />
        <template v-if="!editGroup">
          <h3>{{ conv.title }}</h3>
          <p v-if="!isGroup && conv.user && conv.user.position" class="wc-info-sub">{{ conv.user.position }}</p>
          <p v-if="!isGroup && conv.user && conv.user.presence && conv.user.presence.key !== 'none'" class="wc-info-pres"><i :style="{ background: conv.user.presence.color }"></i>{{ conv.user.presence.label }}<template v-if="conv.user.presence.last_seen_label && conv.user.presence.key !== 'online'"> · {{ $t('last seen :t', { t: conv.user.presence.last_seen_label }) }}</template></p>
          <p v-if="isGroup && conv.description" class="wc-info-desc">{{ conv.description }}</p>
          <p v-if="isGroup" class="wc-info-sub">{{ $t(':n members', { n: conv.members_count }) }}</p>
          <button v-if="isGroup && isAdmin" type="button" class="wc-btn is-sm" @click="startEdit"><icons name="edit" class="w-4 h-4" />{{ $t('Edit group') }}</button>
        </template>
        <form v-else class="wc-form" @submit.prevent="saveGroup">
          <label>{{ $t('Group name') }}<input v-model="form.title" maxlength="120" required /></label>
          <label>{{ $t('Description') }}<textarea v-model="form.description" rows="2" maxlength="500"></textarea></label>
          <label>{{ $t('Group picture') }}<input type="file" accept="image/png,image/jpeg,image/webp" @change="form.avatar = $event.target.files[0]" /></label>
          <p v-if="err" class="wc-err"><icons name="exclamation" class="w-4 h-4" />{{ err }}</p>
          <div class="wc-form-row"><button type="submit" class="wc-btn is-primary" :disabled="busy">{{ $t('Save') }}</button><button type="button" class="wc-btn" @click="editGroup = false">{{ $t('Cancel') }}</button></div>
        </form>
      </div>

      <!-- person details -->
      <dl v-if="!isGroup && profile" class="wc-dl">
        <div v-if="profile.email"><dt>{{ $t('Email') }}</dt><dd><a :href="'mailto:' + profile.email">{{ profile.email }}</a></dd></div>
        <div v-if="profile.phone"><dt>{{ $t('Phone') }}</dt><dd>{{ profile.phone }}</dd></div>
        <div v-if="profile.staff_number"><dt>{{ $t('Staff no.') }}</dt><dd>{{ profile.staff_number }}</dd></div>
        <div><dt>{{ $t('Username') }}</dt><dd>@{{ profile.username }}</dd></div>
        <div v-if="profile.mutual_groups && profile.mutual_groups.length"><dt>{{ $t('Groups in common') }}</dt><dd>{{ profile.mutual_groups.map(g => g.title).join(', ') }}</dd></div>
      </dl>

      <!-- quick actions -->
      <div class="wc-info-actions">
        <button type="button" @click="act({ muted: !conv.muted })"><icons :name="conv.muted ? 'bell' : 'bell-off'" class="w-4 h-4" />{{ conv.muted ? $t('Unmute') : $t('Mute') }}</button>
        <button type="button" @click="act({ pinned: !conv.pinned })"><icons name="pin" class="w-4 h-4" />{{ conv.pinned ? $t('Unpin chat') : $t('Pin chat') }}</button>
        <button type="button" @click="act({ archived: !conv.archived })"><icons name="archive-box" class="w-4 h-4" />{{ conv.archived ? $t('Unarchive') : $t('Archive') }}</button>
        <button type="button" @click="act({ marked_unread: true })"><icons name="check-double" class="w-4 h-4" />{{ $t('Mark as unread') }}</button>
      </div>

      <!-- members -->
      <section v-if="isGroup" class="wc-info-sec">
        <h4>{{ $t('Members') }} <small>{{ chat.thread.members.length }}</small>
          <button v-if="canManage" type="button" class="wc-link" @click="addOpen = !addOpen">{{ $t('Add') }}</button>
        </h4>
        <div v-if="addOpen" class="wc-add">
          <input v-model="aq" type="search" :placeholder="$t('Search people…')" :aria-label="$t('Search people')" @input="searchPeople" />
          <ul>
            <li v-for="u in addable" :key="u.id"><button type="button" @click="addMember(u)"><chat-avatar :name="u.name" :photo="u.photo" :presence="u.presence" size="sm" /><span><b>{{ u.name }}</b><small>{{ u.position || '@' + u.username }}</small></span><icons name="plus" class="w-4 h-4" /></button></li>
          </ul>
        </div>
        <ul class="wc-members">
          <li v-for="m in chat.thread.members" :key="m.user.id">
            <chat-avatar :name="m.user.name" :photo="m.user.photo" :presence="m.user.presence" size="sm" />
            <span class="wc-mem-t"><b>{{ m.user.name }}<em v-if="m.user.id === chat.me.id"> ({{ $t('you') }})</em></b><small>{{ m.role === 'admin' ? $t('Group admin') : (m.user.position || '@' + m.user.username) }}</small></span>
            <span v-if="canManage && m.user.id !== chat.me.id" class="wc-mem-act">
              <button type="button" class="lg-tip" :data-tip="m.role === 'admin' ? $t('Remove admin') : $t('Make admin')" :aria-label="m.role === 'admin' ? $t('Remove admin') : $t('Make admin')" @click="role(m)"><icons name="star" class="w-4 h-4" /></button>
              <button type="button" class="lg-tip is-danger" :data-tip="$t('Remove from group')" :aria-label="$t('Remove from group')" @click="removeMember(m)"><icons name="cross" class="w-4 h-4" /></button>
            </span>
          </li>
        </ul>
        <ul v-if="chat.thread.invited.length" class="wc-members is-invited">
          <li class="wc-members-h">{{ $t('Invited, waiting to accept') }} <small>{{ chat.thread.invited.length }}</small></li>
          <li v-for="iv in chat.thread.invited" :key="iv.user.id">
            <chat-avatar :name="iv.user.name" :photo="iv.user.photo" size="sm" />
            <span class="wc-mem-t"><b>{{ iv.user.name }}</b><small>{{ $t('Invitation sent') }}</small></span>
            <span class="wc-chip-wait">{{ $t('Pending') }}</span>
            <span v-if="canManage" class="wc-mem-act"><button type="button" class="lg-tip is-danger" :data-tip="$t('Withdraw invitation')" :aria-label="$t('Withdraw invitation')" @click="withdraw(iv)"><icons name="cross" class="w-4 h-4" /></button></span>
          </li>
        </ul>
        <button type="button" class="wc-btn is-danger is-block" @click="leave"><icons name="arrow-left" class="w-4 h-4" />{{ $t('Leave group') }}</button>
      </section>

      <!-- shared media / files / links -->
      <section class="wc-info-sec">
        <div class="wc-seg" role="tablist">
          <button v-for="t in tabs" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key" :class="{ 'is-on': tab === t.key }" @click="setTab(t.key)">{{ t.label }}<small v-if="t.n !== null">{{ t.n }}</small></button>
        </div>
        <p v-if="mLoading" class="wc-muted">{{ $t('Loading…') }}</p>
        <div v-else-if="tab === 'media'" class="wc-media-grid">
          <button v-for="i in media" :key="i.id" type="button" class="wc-media" :aria-label="i.name" @click="$emit('open-image', { list: mediaList, index: mediaList.findIndex(x => x.id === i.id) })">
            <img v-if="i.thumb_url" :src="i.thumb_url" :alt="i.name" loading="lazy" /><icons v-else name="image" class="w-5 h-5" />
          </button>
          <p v-if="!media.length" class="wc-muted">{{ $t('No shared images yet.') }}</p>
        </div>
        <ul v-else-if="tab === 'files'" class="wc-flist">
          <li v-for="f in media" :key="f.id">
            <span class="wc-file-ico"><icons name="doc" class="w-5 h-5" /></span>
            <span class="wc-file-t"><b :title="f.name">{{ f.name }}</b><small>{{ (f.ext || '').toUpperCase() }} · {{ human(f.size) }} · {{ f.user }} · {{ shortDate(f.at) }}</small></span>
            <span class="wc-file-act"><a v-if="f.view_url" :href="f.view_url" target="_blank" rel="noopener" :aria-label="$t('Open')"><icons name="eye-flat" class="w-4 h-4" /></a><a v-if="f.download_url" :href="f.download_url" :aria-label="$t('Download')"><icons name="download" class="w-4 h-4" /></a></span>
          </li>
          <li v-if="!media.length" class="wc-muted">{{ $t('No shared files yet.') }}</li>
        </ul>
        <ul v-else class="wc-flist">
          <li v-for="(l, i) in links" :key="i">
            <span class="wc-file-ico"><icons name="link" class="w-5 h-5" /></span>
            <span class="wc-file-t"><a :href="l.url" target="_blank" rel="noopener noreferrer nofollow"><b>{{ l.host }}</b></a><small>{{ l.url }}</small><small>{{ l.user }} · {{ shortDate(l.at) }}</small></span>
          </li>
          <li v-if="!links.length" class="wc-muted">{{ $t('No shared links yet.') }}</li>
        </ul>
      </section>

      <button type="button" class="wc-btn is-danger is-block" @click="act({ hidden: true })"><icons name="trash" class="w-4 h-4" />{{ $t('Remove from my view') }}</button>
      <p class="wc-keep"><icons name="clock" class="w-4 h-4" />{{ keepText }}</p>
    </div>
  </aside>
</template>

<script>
import ChatAvatar from './ChatAvatar.vue';
import { chat, human, setState, toast, errorText, upsertConversations, openConversation } from '@/Core/wimschat';
import { route } from 'ziggy-js';

export default {
  components: { ChatAvatar },
  emits: ['close', 'open-image', 'confirm'],
  data() {
    return { chat, profile: null, tab: 'media', media: [], links: [], mLoading: false, editGroup: false, form: { title: '', description: '', avatar: null }, err: '', busy: false, addOpen: false, aq: '', found: [], timer: null };
  },
  computed: {
    conv() {
      return chat.thread.conversation || chat.list.items.find(i => i.id === chat.activeId) || null;
    },
    isGroup() {
      return this.conv && this.conv.type === 'group';
    },
    myMember() {
      return chat.thread.members.find(m => m.user.id === chat.me.id);
    },
    isAdmin() {
      return (this.myMember && this.myMember.role === 'admin') || chat.can.manage_groups;
    },
    canManage() {
      return this.isGroup && this.isAdmin && chat.features.groups;
    },
    tabs() {
      return [
        { key: 'media', label: this.$t('Media'), n: chat.thread.counts.media },
        { key: 'files', label: this.$t('Files'), n: chat.thread.counts.files },
        { key: 'links', label: this.$t('Links'), n: null },
      ];
    },
    keepText() {
      const f = d => (d === 0 ? this.$t('forever') : d === 365 ? this.$t('1 year') : this.$t(':n days', { n: d }));
      const c = chat.config;

      return this.$t('Messages are kept for :m; files and voice notes for :a.', { m: f(c.message_retention_days), a: f(c.attachment_retention_days) });
    },
    mediaList() {
      return this.media.filter(i => i.thumb_url || i.view_url).map(i => ({ id: i.id, name: i.name, src: i.view_url || i.thumb_url, download: i.download_url, user: i.user, at: i.at }));
    },
    addable() {
      const ids = chat.thread.members.map(m => m.user.id);
      const inv = chat.thread.invited.map(i => i.user.id);
      return this.found.filter(u => !ids.includes(u.id) && !inv.includes(u.id));
    },
  },
  watch: {
    'chat.activeId': {
      immediate: true,
      handler() {
        this.profile = null;
        this.editGroup = false;
        this.addOpen = false;
        this.tab = 'media';
        this.loadProfile();
        this.loadTab();
      },
    },
    'chat.thread.counts': { deep: true, handler() { if (this.tab !== 'links') this.loadTab(); } },
  },
  methods: {
    human,
    shortDate(iso) {
      return new Date(iso).toLocaleDateString([], { day: '2-digit', month: 'short' });
    },
    async loadProfile() {
      const c = this.conv;
      if (!c || c.type !== 'direct' || !c.user) return;
      try {
        const { data } = await window.axios.get(route('wimschat.users.show', { user: c.user.id }));
        this.profile = data.user;
      } catch (e) { /* the basic info is already shown */ }
    },
    setTab(k) {
      this.tab = k;
      this.loadTab();
    },
    async loadTab() {
      if (!chat.activeId) return;
      this.mLoading = true;
      try {
        const { data } = await window.axios.get(route('wimschat.conversations.media', { conversation: chat.activeId }), { params: { kind: this.tab } });
        if (this.tab === 'links') this.links = data.links || [];
        else this.media = data.items || [];
      } catch (e) {
        toast(errorText(e), 'error');
      } finally {
        this.mLoading = false;
      }
    },
    async act(patch) {
      try {
        await setState(chat.activeId, patch);
        if (patch.hidden) { toast(this.$t('Conversation removed from your view.')); this.$emit('close'); }
      } catch (e) {
        toast(errorText(e), 'error');
      }
    },
    startEdit() {
      this.form = { title: this.conv.title, description: this.conv.description || '', avatar: null };
      this.err = '';
      this.editGroup = true;
    },
    async saveGroup() {
      this.busy = true;
      this.err = '';
      const fd = new FormData();
      fd.append('title', this.form.title);
      fd.append('description', this.form.description || '');
      if (this.form.avatar) fd.append('avatar', this.form.avatar);
      try {
        const { data } = await window.axios.post(route('wimschat.groups.update', { conversation: chat.activeId }), fd);
        upsertConversations([data.conversation]);
        chat.thread.conversation = data.conversation;
        this.editGroup = false;
      } catch (e) {
        this.err = errorText(e);
      } finally {
        this.busy = false;
      }
    },
    searchPeople() {
      clearTimeout(this.timer);
      this.timer = setTimeout(async () => {
        try {
          const { data } = await window.axios.get(route('wimschat.users'), { params: { q: this.aq } });
          this.found = data.users;
        } catch (e) { /* ignore */ }
      }, 250);
    },
    async withdraw(iv) {
      try {
        const { data } = await window.axios.delete(route('wimschat.groups.members.remove', { conversation: chat.activeId, user: iv.user.id }));
        chat.thread.members = data.members;
        chat.thread.invited = data.invited || [];
      } catch (e) {
        toast(errorText(e), 'error');
      }
    },
    async addMember(u) {
      try {
        const { data } = await window.axios.post(route('wimschat.groups.members.add', { conversation: chat.activeId }), { members: [u.id] });
        chat.thread.members = data.members;
        chat.thread.invited = data.invited || chat.thread.invited;
        if (data.conversation) { upsertConversations([data.conversation]); chat.thread.conversation = data.conversation; }
        if (chat.config.invite_required) toast(this.$t('Invitation sent to :n.', { n: u.name }));
      } catch (e) {
        toast(errorText(e), 'error');
      }
    },
    removeMember(m) {
      this.$emit('confirm', {
        title: this.$t('Remove :n from the group?', { n: m.user.name }), text: this.$t('They will no longer see new messages.'), ok: this.$t('Remove'), danger: true,
        run: async () => {
          try {
            const { data } = await window.axios.delete(route('wimschat.groups.members.remove', { conversation: chat.activeId, user: m.user.id }));
            chat.thread.members = data.members;
            if (chat.thread.conversation) chat.thread.conversation.members_count = data.members.length;
          } catch (e) { toast(errorText(e), 'error'); }
        },
      });
    },
    async role(m) {
      try {
        const { data } = await window.axios.put(route('wimschat.groups.members.role', { conversation: chat.activeId, user: m.user.id }), { role: m.role === 'admin' ? 'member' : 'admin' });
        chat.thread.members = data.members;
      } catch (e) {
        toast(errorText(e), 'error');
      }
    },
    leave() {
      this.$emit('confirm', {
        title: this.$t('Leave this group?'), text: this.$t('You will stop receiving its messages.'), ok: this.$t('Leave'), danger: true,
        run: async () => {
          try {
            const id = chat.activeId;
            await window.axios.post(route('wimschat.groups.leave', { conversation: id }));
            chat.list.items = chat.list.items.filter(i => i.id !== id);
            openConversation(null);
            this.$emit('close');
          } catch (e) { toast(errorText(e), 'error'); }
        },
      });
    },
  },
};
</script>

<template>
  <!-- system lines: "X created the group" -->
  <div v-if="m.type === 'system'" class="wc-sys"><span>{{ m.body }}</span></div>

  <!-- nudge -->
  <div v-else-if="m.type === 'nudge'" class="wc-sys wc-sys-nudge"><span><icons name="bell" class="w-3.5 h-3.5" />{{ m.mine ? $t('You nudged :n', { n: nudgeTarget }) : $t(':n nudged you', { n: m.user && m.user.name }) }} · {{ time }}</span></div>

  <div v-else :id="'wcm-' + m.id" class="wc-msg" :class="{ 'is-mine': m.mine, 'is-first': first, 'is-last': last, 'is-hl': highlight, 'is-failed': m.state === 'failed', 'is-open': menuOpen || reactOpen }">
    <div class="wc-msg-av">
      <chat-avatar v-if="!m.mine && last" :name="m.user && m.user.name" :photo="m.user && m.user.photo" size="sm" class="wc-click" @click="$emit('profile', m.user)" />
    </div>

    <div class="wc-msg-col">
      <div v-if="!m.mine && showName && first" class="wc-msg-name" :style="{ '--nh': nameHue }">{{ m.user && m.user.name }}</div>

      <div class="wc-bubble" :class="{ 'is-deleted': m.deleted, 'has-media': onlyMedia }" @click="tapTools">
        <!-- tools -->
        <div v-if="!m.deleted && !m.local" class="wc-tools" :class="{ 'is-on': tools }" @click.stop>
          <button v-if="m.can.react && reactions.length" type="button" class="lg-tip" :data-tip="$t('React')" :aria-label="$t('React')" @click="reactOpen = !reactOpen; menuOpen = false"><icons name="smile" class="w-4 h-4" /></button>
          <button v-if="m.can.reply" type="button" class="lg-tip" :data-tip="$t('Reply')" :aria-label="$t('Reply')" @click="$emit('reply', m)"><icons name="reply" class="w-4 h-4" /></button>
          <button type="button" class="lg-tip" :data-tip="$t('More')" :aria-label="$t('More')" aria-haspopup="menu" :aria-expanded="menuOpen" @click="menuOpen = !menuOpen; reactOpen = false"><icons name="more" class="w-4 h-4" /></button>
          <div v-if="reactOpen" class="wc-pop wc-react-pop" @click.stop>
            <button v-for="e in reactions" :key="e" type="button" @click="react(e)">{{ e }}</button>
          </div>
          <div v-if="menuOpen" class="wc-pop wc-menu" role="menu" @click.stop>
            <button v-if="m.can.reply" role="menuitem" type="button" @click="act('reply')"><icons name="reply" class="w-4 h-4" />{{ $t('Reply') }}</button>
            <button v-if="m.body" role="menuitem" type="button" @click="act('copy')"><icons name="duplicate" class="w-4 h-4" />{{ $t('Copy') }}</button>
            <button v-if="m.can.edit" role="menuitem" type="button" @click="act('edit')"><icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}</button>
            <button v-if="m.can.forward" role="menuitem" type="button" @click="act('forward')"><icons name="forward" class="w-4 h-4" />{{ $t('Forward') }}</button>
            <button v-if="m.can.pin" role="menuitem" type="button" @click="act('pin')"><icons name="pin" class="w-4 h-4" />{{ m.pinned ? $t('Unpin') : $t('Pin') }}</button>
            <button v-if="m.can.delete" role="menuitem" type="button" class="is-danger" @click="act('delete')"><icons name="trash" class="w-4 h-4" />{{ $t('Delete') }}</button>
          </div>
        </div>

        <template v-if="m.deleted">
          <span class="wc-deleted"><icons name="cross" class="w-3.5 h-3.5" />{{ $t('This message was deleted') }}</span>
        </template>
        <template v-else>
          <div v-if="m.forwarded" class="wc-fwd"><icons name="forward" class="w-3 h-3" />{{ m.forwarded.from ? $t('Forwarded from :n', { n: m.forwarded.from }) : $t('Forwarded') }}</div>
          <button v-if="m.reply_to" type="button" class="wc-quote" @click.stop="$emit('jump', m.reply_to.id)">
            <b>{{ m.reply_to.user || $t('Message') }}</b>
            <span>{{ m.reply_to.text }}</span>
          </button>

          <!-- attachments -->
          <div v-if="images.length" class="wc-imgs" :class="'n' + Math.min(images.length, 4)">
            <button v-for="(a, i) in images" :key="a.id" type="button" class="wc-img" :aria-label="a.name" @click.stop="openImage(a, i)">
              <img v-if="a.localUrl || a.thumb_url" :src="a.localUrl || a.thumb_url" :alt="a.name" loading="lazy" :class="{ 'is-gone': a.purged }" />
              <span v-else class="wc-img-ph"><icons name="image" class="w-6 h-6" /></span>
              <span v-if="a.purged" class="wc-img-gone">{{ $t('Removed') }}</span>
            </button>
          </div>
          <voice-player v-for="a in voices" :key="a.id" :src="a.localUrl || a.view_url" :duration-ms="a.duration_ms || 0" :seed="a.id" :mine="m.mine" />
          <div v-for="a in files" :key="a.id" class="wc-file">
            <span class="wc-file-ico" :class="'k-' + (a.category || 'other')"><icons :name="a.category === 'pdf' ? 'doc-text' : 'doc'" class="w-5 h-5" /></span>
            <span class="wc-file-t"><b :title="a.name">{{ a.name }}</b><small>{{ (a.ext || '').toUpperCase() }} · {{ human(a.size) }}<template v-if="a.purged"> · {{ $t('Removed after retention period') }}</template></small></span>
            <span v-if="!m.local" class="wc-file-act">
              <a v-if="a.view_url" :href="a.view_url" target="_blank" rel="noopener" class="lg-tip" :data-tip="$t('Open')" :aria-label="$t('Open')"><icons name="eye-flat" class="w-4 h-4" /></a>
              <a v-if="a.download_url" :href="a.download_url" class="lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" class="w-4 h-4" /></a>
              <button v-if="a.can_delete" type="button" class="lg-tip" :data-tip="$t('Delete file')" :aria-label="$t('Delete file')" @click.stop="$emit('delete-attachment', a)"><icons name="trash" class="w-4 h-4" /></button>
            </span>
          </div>
          <div v-if="m.local && m.state === 'sending' && m.attachments.length" class="wc-prog" role="progressbar" :aria-valuenow="m.progress"><span :style="{ width: m.progress + '%' }"></span></div>

          <p v-if="!m.body && !m.attachments.length && !m.local && !m.reply_to && m.type === 'text'" class="wc-deleted"><icons name="clip" class="w-3.5 h-3.5" />{{ $t('Attachment removed') }}</p>
          <p v-if="m.body" class="wc-text"><template v-for="(t, i) in segments" :key="i"><a v-if="t.k === 'url'" :href="t.v" target="_blank" rel="noopener noreferrer nofollow" @click.stop>{{ t.v }}</a><span v-else-if="t.k === 'me'" class="wc-mention is-me">{{ t.v }}</span><span v-else-if="t.k === 'at'" class="wc-mention">{{ t.v }}</span><template v-else>{{ t.v }}</template></template></p>
        </template>

        <span v-for="pt in particles" :key="pt.id" class="wc-part" :style="pt.style" aria-hidden="true">{{ pt.e }}</span>

        <span class="wc-meta">
          <span v-if="m.pinned && !m.deleted" class="wc-meta-pin"><icons name="pin" class="w-3 h-3" /></span>
          <span v-if="m.edited && !m.deleted" class="wc-edited">{{ $t('Edited') }}</span>
          <time :datetime="m.created_at">{{ time }}</time>
          <span v-if="m.mine && !m.deleted" class="wc-tick lg-tip" :class="'is-' + status" :data-tip="statusLabel">
            <icons :name="status === 'sending' ? 'clock' : status === 'failed' ? 'exclamation' : status === 'sent' ? 'tick' : 'check-double'" class="w-3.5 h-3.5" />
          </span>
        </span>
      </div>

      <!-- failed local message -->
      <div v-if="m.state === 'failed'" class="wc-fail" role="alert">
        <icons name="exclamation" class="w-4 h-4" />{{ m.error }}
        <button type="button" @click="$emit('retry', m)">{{ $t('Retry') }}</button>
        <button type="button" @click="$emit('discard', m)">{{ $t('Discard') }}</button>
      </div>

      <!-- reactions -->
      <div v-if="m.reactions && m.reactions.length" class="wc-reacts">
        <button v-for="r in m.reactions" :key="r.emoji" type="button" class="wc-react lg-tip" :class="{ 'is-mine': r.mine }" :data-tip="r.users.join(', ')" :disabled="!m.can.react" @click="react(r.emoji)"><span>{{ r.emoji }}</span><b>{{ r.count }}</b></button>
      </div>
    </div>
  </div>
</template>

<script>
import ChatAvatar from './ChatAvatar.vue';
import VoicePlayer from './VoicePlayer.vue';
import { chat, human, messageStatus } from '@/Core/wimschat';

const TOKEN = /(https?:\/\/[^\s<>"']+)|(@[A-Za-z0-9._-]{2,40})/g;

export default {
  components: { ChatAvatar, VoicePlayer },
  props: {
    m: { type: Object, required: true },
    first: { type: Boolean, default: true },
    last: { type: Boolean, default: true },
    showName: { type: Boolean, default: false },
    highlight: { type: Boolean, default: false },
    nudgeTarget: { type: String, default: '' },
    usernames: { type: Array, default: () => [] },
  },
  emits: ['reply', 'edit', 'delete', 'forward', 'pin', 'copy', 'react', 'jump', 'profile', 'open-image', 'retry', 'discard', 'delete-attachment'],
  data() {
    return { menuOpen: false, reactOpen: false, tools: false, particles: [] };
  },
  computed: {
    nameHue() {
      let h = 0;
      for (const c of String((this.m.user && this.m.user.name) || '')) h = (h * 31 + c.charCodeAt(0)) % 360;
      return h;
    },
    reactions() {
      return chat.config ? chat.config.reactions : [];
    },
    time() {
      return new Date(this.m.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    },
    images() {
      return (this.m.attachments || []).filter(a => a.is_image);
    },
    voices() {
      return (this.m.attachments || []).filter(a => a.is_voice);
    },
    files() {
      return (this.m.attachments || []).filter(a => !a.is_image && !a.is_voice);
    },
    onlyMedia() {
      return this.images.length > 0 && !this.m.body && !this.files.length && !this.voices.length && !this.m.reply_to;
    },
    status() {
      return messageStatus(this.m);
    },
    statusLabel() {
      return { sending: this.$t('Sending…'), failed: this.$t('Failed'), sent: this.$t('Sent'), delivered: this.$t('Delivered'), read: this.$t('Read') }[this.status];
    },
    segments() {
      const text = String(this.m.body || '');
      const out = [];
      let last = 0;
      const mine = (chat.me.username || '').toLowerCase();
      for (const mt of text.matchAll(TOKEN)) {
        if (mt.index > last) out.push({ k: 't', v: text.slice(last, mt.index) });
        let v = mt[0];
        if (mt[1]) {
          const trimmed = v.replace(/[.,;:!?)\]]+$/, '');
          out.push({ k: 'url', v: trimmed });
          if (trimmed.length < v.length) out.push({ k: 't', v: v.slice(trimmed.length) });
        } else if (this.usernames.includes(v.slice(1).toLowerCase())) {
          out.push({ k: v.slice(1).toLowerCase() === mine ? 'me' : 'at', v });
        } else {
          out.push({ k: 't', v });
        }
        last = mt.index + mt[0].length;
      }
      if (last < text.length) out.push({ k: 't', v: text.slice(last) });

      return out;
    },
  },
  mounted() {
    document.addEventListener('click', this.closeAll);
    // a little love: a fresh message from someone else carrying a heart makes hearts float up
    if (!this.m.mine && !this.m.local && this.m.body && /[❤💖💕💗😍🥰]/u.test(this.m.body) && Date.now() - new Date(this.m.created_at).getTime() < 12000) {
      this.$nextTick(() => this.burst('❤️'));
    }
  },
  beforeUnmount() {
    document.removeEventListener('click', this.closeAll);
  },
  methods: {
    human,
    closeAll() {
      this.menuOpen = false;
      this.reactOpen = false;
      this.tools = false;
    },
    toggleTools() {
      this.tools = !this.tools;
    },
    tapTools() {
      // touch screens have no hover: a tap reveals the tools
      if (window.matchMedia && window.matchMedia('(hover: none)').matches) this.tools = !this.tools;
    },
    react(e) {
      this.reactOpen = false;
      const had = (this.m.reactions || []).some(r => r.emoji === e && r.mine);
      if (!had) this.burst(e);
      this.$emit('react', this.m, e);
    },
    burst(e) {
      if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
      const batch = Array.from({ length: 7 }, (_, i) => ({
        id: Date.now() + '-' + i + Math.random(), e,
        style: { '--dx': Math.round((Math.random() - 0.5) * 90) + 'px', '--dy': -Math.round(50 + Math.random() * 70) + 'px', '--r': Math.round((Math.random() - 0.5) * 60) + 'deg', animationDelay: i * 45 + 'ms', fontSize: 14 + Math.round(Math.random() * 10) + 'px' },
      }));
      this.particles = this.particles.concat(batch);
      setTimeout(() => (this.particles = this.particles.filter(p => !batch.includes(p))), 1500);
    },
    act(name) {
      this.menuOpen = false;
      this.$emit(name, this.m);
    },
    openImage(a, i) {
      this.$emit('open-image', { message: this.m, attachment: a, index: i });
    },
  },
};
</script>

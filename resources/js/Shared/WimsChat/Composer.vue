<template>
  <div class="wc-composer" :class="{ 'is-drag': drag, 'is-edit': !!editing }" :data-drop="$t('Drop files to attach')" @dragover.prevent="drag = canAttach" @dragleave.prevent="drag = false" @drop.prevent="onDrop">
    <!-- offline / no permission -->
    <p v-if="locked" class="wc-locked" role="status"><icons name="lock-closed" class="w-4 h-4" />{{ lockedText }}</p>

    <template v-else>
      <!-- reply / edit banner -->
      <div v-if="replyTo" class="wc-banner">
        <icons name="reply" class="w-4 h-4" />
        <span><b>{{ $t('Replying to :n', { n: replyTo.user ? replyTo.user.name : '' }) }}</b><em>{{ replyTo.body || '📎' }}</em></span>
        <button type="button" :aria-label="$t('Cancel reply')" @click="$emit('cancel-reply')"><icons name="cross" class="w-4 h-4" /></button>
      </div>
      <div v-if="editing" class="wc-banner is-edit">
        <icons name="edit" class="w-4 h-4" />
        <span><b>{{ $t('Editing message') }}</b><em>{{ $t('Enter to save · Esc to cancel') }}</em></span>
        <button type="button" :aria-label="$t('Cancel')" @click="$emit('cancel-edit')"><icons name="cross" class="w-4 h-4" /></button>
      </div>

      <!-- selected files -->
      <div v-if="files.length" class="wc-chips">
        <span v-for="(f, i) in files" :key="f.name + i" class="wc-chip">
          <img v-if="previews[i]" :src="previews[i]" alt="" />
          <icons v-else name="doc" class="w-4 h-4" />
          <span class="wc-chip-t"><b>{{ f.name }}</b><small>{{ human(f.size) }}</small></span>
          <button type="button" :aria-label="$t('Remove')" @click="removeFile(i)"><icons name="cross" class="w-3.5 h-3.5" /></button>
        </span>
        <small v-if="quotaNote" class="wc-quota">{{ quotaNote }}</small>
      </div>
      <p v-if="error" class="wc-err" role="alert"><icons name="exclamation" class="w-4 h-4" />{{ error }}</p>

      <!-- voice note: recording -->
      <div v-if="rec.state === 'recording'" class="wc-rec" role="status" aria-live="polite">
        <button type="button" class="wc-ib is-danger lg-tip" :data-tip="$t('Cancel recording')" :aria-label="$t('Cancel recording')" @click="cancelRec"><icons name="trash" class="w-5 h-5" /></button>
        <span class="wc-rec-dot"></span>
        <span class="wc-rec-time">{{ fmt(rec.ms) }}<small> / {{ fmt(maxMs) }}</small></span>
        <span class="wc-rec-bars" aria-hidden="true"><i v-for="(h, i) in rec.levels" :key="i" :style="{ height: h + '%' }"></i></span>
        <span class="wc-rec-track" aria-hidden="true"><i :style="{ width: Math.min(100, rec.ms / maxMs * 100) + '%' }"></i></span>
        <button type="button" class="wc-send is-stop lg-tip" :data-tip="$t('Stop and review')" :aria-label="$t('Stop recording')" @click="stopRec"><span class="wc-stop-sq"></span></button>
      </div>
      <!-- voice note: review before sending -->
      <div v-else-if="rec.state === 'review'" class="wc-rec is-review">
        <button type="button" class="wc-ib is-danger lg-tip" :data-tip="$t('Discard')" :aria-label="$t('Discard voice note')" @click="cancelRec"><icons name="trash" class="w-5 h-5" /></button>
        <voice-player :src="rec.url" :duration-ms="rec.ms" seed="preview" :mine="false" class="wc-rec-player" />
        <button type="button" class="wc-send lg-tip" :class="{ 'is-fly': flying }" :data-tip="$t('Send voice note')" :aria-label="$t('Send voice note')" @click="sendVoice"><icons name="send" class="w-5 h-5" /></button>
      </div>
      <p v-if="rec.error" class="wc-err" role="alert"><icons name="exclamation" class="w-4 h-4" />{{ rec.error }}</p>

      <div v-if="!rec.state" class="wc-box">
        <button v-if="canAttach && !editing" type="button" class="wc-ib lg-tip" :class="{ 'is-wiggle': wiggle }" :data-tip="$t('Attach files')" :aria-label="$t('Attach files')" @click="$refs.file.click()"><icons name="clip" class="w-5 h-5" /></button>
        <input ref="file" type="file" multiple class="sr-only" tabindex="-1" :accept="accept" @change="onPick" />

        <div class="wc-ta-wrap">
          <textarea
            ref="ta" v-model="text" rows="1" class="wc-ta" :placeholder="$t('Write a message…')" :aria-label="$t('Message')" :maxlength="max || null"
            @input="onInput" @keydown="onKey" @paste="onPaste" @click="detect" @keyup="detectKey"
          ></textarea>
          <!-- @mention picker -->
          <ul v-if="mention.open && mention.list.length" class="wc-mentions" role="listbox">
            <li v-for="(u, i) in mention.list" :key="u.user.id" role="option" :aria-selected="i === mention.i" :class="{ 'is-on': i === mention.i }" @mousedown.prevent="chooseMention(u)">
              <chat-avatar :name="u.user.name" :photo="u.user.photo" size="xs" />
              <b>{{ u.user.name }}</b><small>@{{ u.user.username }}</small>
            </li>
          </ul>
        </div>

        <div v-if="chat.features.emoji" class="wc-emoji-wrap">
          <button type="button" class="wc-ib lg-tip" :data-tip="$t('Emoji')" :aria-label="$t('Emoji')" :aria-expanded="emojiOpen" @click.stop="emojiOpen = !emojiOpen"><icons name="smile" class="w-5 h-5" /></button>
          <emoji-picker v-if="emojiOpen" @pick="insertEmoji" @close="emojiOpen = false" @click.stop />
        </div>

        <button v-if="showMic" type="button" class="wc-send is-mic lg-tip" :data-tip="$t('Record a voice note (up to :n s)', { n: Math.round(maxMs / 1000) })" :aria-label="$t('Record a voice note')" @click="startRec"><icons name="microphone" class="w-5 h-5" /></button>
        <button v-else type="button" class="wc-send lg-tip" :class="{ 'is-fly': flying }" :data-tip="editing ? $t('Save') : $t('Send')" :aria-label="editing ? $t('Save') : $t('Send')" :disabled="!canSend" @click="submit">
          <icons :name="editing ? 'tick' : 'send'" class="w-5 h-5" />
        </button>
      </div>
      <p v-if="max && text.length > max * 0.85" class="wc-count" :class="{ 'is-over': text.length >= max }">{{ text.length }} / {{ max }}</p>
    </template>
  </div>
</template>

<script>
import ChatAvatar from './ChatAvatar.vue';
import EmojiPicker from './EmojiPicker.vue';
import VoicePlayer from './VoicePlayer.vue';
import { chat, human } from '@/Core/wimschat';
import { notifyTyping } from '@/Core/wimschat';

export default {
  components: { ChatAvatar, EmojiPicker, VoicePlayer },
  props: {
    conversationId: { type: Number, required: true },
    members: { type: Array, default: () => [] },
    replyTo: { type: Object, default: null },
    editing: { type: Object, default: null },
  },
  emits: ['send', 'save-edit', 'cancel-reply', 'cancel-edit'],
  data() {
    return { chat, text: '', files: [], previews: [], error: '', drag: false, emojiOpen: false, mention: { open: false, q: '', start: 0, list: [], i: 0 }, draftTimer: null, flying: false, wiggle: false, rec: { state: '', ms: 0, levels: [], url: null, error: '' } };
  },
  computed: {
    max() {
      return chat.config.max_message_chars;
    },
    canAttach() {
      return chat.can.attach && chat.features.attachments && !this.editing;
    },
    accept() {
      return (chat.config.accept || []).join(',');
    },
    locked() {
      return !chat.can.send || this.$offlineBlocked;
    },
    lockedText() {
      return !chat.can.send ? this.$t('You do not have permission to send messages.') : this.$t('WIMS is offline. You can read your messages, but sending is unavailable until you reconnect.');
    },
    maxMs() {
      return ((chat.config.voice && chat.config.voice.max_seconds) || 30) * 1000;
    },
    showMic() {
      return !!(chat.can.voice && chat.features.voice && chat.config.voice && chat.config.voice.on && !this.editing && !this.text.trim() && !this.files.length);
    },
    canSend() {
      return (this.text.trim().length > 0 || (this.files.length > 0 && !this.editing)) && !this.locked;
    },
    quotaNote() {
      const q = chat.quota;
      if (!q) return '';
      const total = this.files.reduce((s, f) => s + f.size, 0);

      return this.$t('Daily file quota: :used of :limit used · :left left after this', { used: human(q.used), limit: human(q.limit), left: human(Math.max(0, q.remaining - total)) });
    },
    usernames() {
      return this.members.map(m => m.user.username);
    },
  },
  watch: {
    conversationId: {
      immediate: true,
      handler(id, old) {
        if (old) this.saveDraft(old);
        this.cancelRec();
        this.clearFiles();
        this.error = '';
        this.text = this.loadDraft(id);
        this.$nextTick(this.grow);
      },
    },
    editing: {
      handler(m) {
        if (m) {
          this.text = m.body || '';
          this.$nextTick(() => { this.grow(); this.focus(); });
        }
      },
    },
    replyTo(m) {
      if (m) this.$nextTick(this.focus);
    },
  },
  mounted() {
    document.addEventListener('click', this.closePopups);
  },
  beforeUnmount() {
    this.cancelRec();
    document.removeEventListener('click', this.closePopups);
    this.saveDraft(this.conversationId);
    this.clearFiles();
  },
  methods: {
    human,
    focus() {
      this.$refs.ta && this.$refs.ta.focus({ preventScroll: true });
    },
    closePopups() {
      this.emojiOpen = false;
      this.mention.open = false;
    },
    // drafts survive closing the drawer or reloading, so nothing typed is lost
    draftKey(id) {
      return 'wimschat.draft.' + id;
    },
    loadDraft(id) {
      try { return localStorage.getItem(this.draftKey(id)) || ''; } catch (e) { return ''; }
    },
    saveDraft(id) {
      try {
        if (this.editing) return;
        if (this.text.trim()) localStorage.setItem(this.draftKey(id), this.text);
        else localStorage.removeItem(this.draftKey(id));
      } catch (e) { /* storage optional */ }
    },
    grow() {
      const ta = this.$refs.ta;
      if (!ta) return;
      ta.style.height = 'auto';
      ta.style.height = Math.min(ta.scrollHeight, 150) + 'px';
    },
    onInput() {
      this.grow();
      this.detect();
      if (this.text.trim() && !this.editing) notifyTyping();
      clearTimeout(this.draftTimer);
      this.draftTimer = setTimeout(() => this.saveDraft(this.conversationId), 400);
    },
    onKey(e) {
      if (this.mention.open && this.mention.list.length) {
        if (e.key === 'ArrowDown') { e.preventDefault(); this.mention.i = (this.mention.i + 1) % this.mention.list.length; return; }
        if (e.key === 'ArrowUp') { e.preventDefault(); this.mention.i = (this.mention.i - 1 + this.mention.list.length) % this.mention.list.length; return; }
        if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); this.chooseMention(this.mention.list[this.mention.i]); return; }
        if (e.key === 'Escape') { e.preventDefault(); this.mention.open = false; return; }
      }
      if (e.key === 'Escape') {
        if (this.editing) this.$emit('cancel-edit');
        else if (this.replyTo) this.$emit('cancel-reply');
        return;
      }
      if (e.key === 'Enter' && !e.isComposing) {
        const enterSends = chat.me.prefs.enter_to_send;
        if ((enterSends && !e.shiftKey) || (!enterSends && (e.ctrlKey || e.metaKey))) {
          e.preventDefault();
          this.submit();
        }
      }
    },
    detectKey(e) {
      if (['ArrowUp', 'ArrowDown', 'Enter', 'Tab', 'Escape'].includes(e.key)) return;
      this.detect();
    },
    // @mention: look at the word just before the caret
    detect() {
      if (!chat.can.mention || !chat.features.mentions) { this.mention.open = false; return; }
      const ta = this.$refs.ta;
      if (!ta) return;
      const before = this.text.slice(0, ta.selectionStart);
      const m = before.match(/(?:^|\s)@([A-Za-z0-9._-]{0,40})$/);
      if (!m) { this.mention.open = false; return; }
      const q = m[1].toLowerCase();
      this.mention.q = q;
      this.mention.start = ta.selectionStart - q.length - 1;
      this.mention.list = this.members.filter(x => x.user.id !== chat.me.id && (x.user.username.toLowerCase().startsWith(q) || x.user.name.toLowerCase().includes(q))).slice(0, 6);
      this.mention.i = 0;
      this.mention.open = true;
    },
    chooseMention(u) {
      const ta = this.$refs.ta;
      const end = ta.selectionStart;
      this.text = this.text.slice(0, this.mention.start) + '@' + u.user.username + ' ' + this.text.slice(end);
      this.mention.open = false;
      this.$nextTick(() => {
        const pos = this.mention.start + u.user.username.length + 2;
        ta.focus({ preventScroll: true });
        ta.setSelectionRange(pos, pos);
        this.onInput();
      });
    },
    insertEmoji(e) {
      const ta = this.$refs.ta;
      const s = ta ? ta.selectionStart : this.text.length;
      const en = ta ? ta.selectionEnd : this.text.length;
      this.text = this.text.slice(0, s) + e + this.text.slice(en);
      this.$nextTick(() => { if (ta) { ta.focus({ preventScroll: true }); ta.setSelectionRange(s + e.length, s + e.length); } this.onInput(); });
    },
    // ---- files ---------------------------------------------------------------------------------------------------
    onPick(e) {
      this.addFiles([...e.target.files]);
      e.target.value = '';
    },
    onDrop(e) {
      this.drag = false;
      if (this.canAttach && !this.locked) this.addFiles([...(e.dataTransfer ? e.dataTransfer.files : [])]);
    },
    onPaste(e) {
      const files = [...(e.clipboardData ? e.clipboardData.files : [])];
      if (files.length && this.canAttach && !this.locked) {
        e.preventDefault();
        this.addFiles(files);
      }
    },
    addFiles(list) {
      this.error = '';
      const c = chat.config;
      for (const f of list) {
        if (this.files.length >= c.max_files) { this.error = this.$t('You can attach up to :n files to one message.', { n: c.max_files }); break; }
        const ext = '.' + (f.name.split('.').pop() || '').toLowerCase();
        if (!c.accept.includes(ext)) { this.error = this.$t('“:name” is not an allowed file type.', { name: f.name }); continue; }
        const cat = c.categories.find(k => k.enabled && k.extensions.includes(ext.slice(1)));
        const cap = (cat ? cat.max_mb : c.max_file_mb) * 1048576;
        if (f.size > cap) { this.error = this.$t('This file exceeds the configured :n MB maximum.', { n: Math.round(cap / 1048576) }); continue; }
        if (!f.size) { this.error = this.$t('“:name” is empty.', { name: f.name }); continue; }
        this.files.push(f);
        this.wiggle = true;
        setTimeout(() => (this.wiggle = false), 700);
        this.previews.push(f.type.startsWith('image/') ? URL.createObjectURL(f) : null);
      }
      const total = this.files.reduce((s, f) => s + f.size, 0);
      if (chat.quota && total > chat.quota.remaining) {
        this.error = chat.quota.remaining <= 0 ? this.$t('Your daily attachment quota has been reached.') : this.$t('Daily WIMSChat file quota exceeded. You have :left remaining today.', { left: human(chat.quota.remaining) });
      }
    },
    removeFile(i) {
      if (this.previews[i]) URL.revokeObjectURL(this.previews[i]);
      this.files.splice(i, 1);
      this.previews.splice(i, 1);
      this.error = '';
    },
    clearFiles() {
      this.previews.forEach(p => p && URL.revokeObjectURL(p));
      this.files = [];
      this.previews = [];
    },
    // ---- voice notes ---------------------------------------------------------------------------------------------
    fmt(ms) {
      const s = Math.floor(ms / 1000);
      return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    },
    async startRec() {
      this.rec.error = '';
      if (!navigator.mediaDevices || !window.MediaRecorder) {
        this.rec.error = this.$t('Your browser cannot record audio. Try an up-to-date Chrome, Edge, Firefox or Safari.');
        return;
      }
      try {
        this._stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } });
      } catch (e) {
        this.rec.error = this.$t('The microphone is blocked. Allow it for this site in your browser settings, then try again.');
        return;
      }
      const mime = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'].find(t => MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(t)) || '';
      this._chunks = [];
      this._rec = new MediaRecorder(this._stream, mime ? { mimeType: mime, audioBitsPerSecond: 64000 } : undefined);
      this._mime = this._rec.mimeType || mime || 'audio/webm';
      this._rec.ondataavailable = e => { if (e.data && e.data.size) this._chunks.push(e.data); };
      this._rec.onstop = () => this.finishRec();
      // live level meter
      try {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        this._ctx = new Ctx();
        const src = this._ctx.createMediaStreamSource(this._stream);
        this._an = this._ctx.createAnalyser();
        this._an.fftSize = 64;
        src.connect(this._an);
      } catch (e) { this._an = null; }
      this.rec.levels = Array(24).fill(8);
      this.rec.ms = 0;
      this.rec.state = 'recording';
      this._t0 = Date.now();
      this._rec.start(250);
      this._timer = setInterval(() => {
        this.rec.ms = Date.now() - this._t0;
        if (this._an) {
          const d = new Uint8Array(this._an.frequencyBinCount);
          this._an.getByteFrequencyData(d);
          const avg = d.reduce((s, v) => s + v, 0) / d.length / 255;
          this.rec.levels = this.rec.levels.slice(1).concat([Math.max(8, Math.min(100, Math.round(avg * 260)))]);
        } else {
          this.rec.levels = this.rec.levels.slice(1).concat([20 + Math.round(Math.random() * 60)]);
        }
        if (this.rec.ms >= this.maxMs) this.stopRec(); // the limit comes from the settings, never hard-coded
      }, 80);
    },
    stopRec() {
      clearInterval(this._timer);
      this.rec.ms = Math.min(Date.now() - this._t0, this.maxMs);
      if (this._rec && this._rec.state !== 'inactive') this._rec.stop();
      this.releaseMic();
    },
    releaseMic() {
      if (this._stream) this._stream.getTracks().forEach(t => t.stop());
      if (this._ctx) { try { this._ctx.close(); } catch (e) { /* ok */ } }
      this._stream = null; this._ctx = null; this._an = null;
    },
    finishRec() {
      if (this.rec.state !== 'recording') return;
      if (this.rec.ms < 600) {
        this.rec.state = '';
        this.rec.error = this.$t('That was too short. Hold on a little longer.');
        return;
      }
      this._blob = new Blob(this._chunks, { type: this._mime });
      this.rec.url = URL.createObjectURL(this._blob);
      this.rec.state = 'review';
    },
    cancelRec() {
      clearInterval(this._timer);
      if (this._rec && this._rec.state !== 'inactive') { this._rec.onstop = null; try { this._rec.stop(); } catch (e) { /* ok */ } }
      this.releaseMic();
      if (this.rec.url) URL.revokeObjectURL(this.rec.url);
      this._blob = null;
      this.rec = { state: '', ms: 0, levels: [], url: null, error: '' };
    },
    sendVoice() {
      if (!this._blob) return;
      const m = this._mime.split(';')[0];
      const ext = m.includes('webm') ? 'webm' : m.includes('ogg') ? 'ogg' : m.includes('mp4') ? 'm4a' : 'webm';
      const file = new File([this._blob], 'voice-note.' + ext, { type: m });
      const ms = this.rec.ms;
      this.flying = true;
      setTimeout(() => (this.flying = false), 700);
      this.$emit('send', { body: '', files: [file], voice: { duration_ms: ms } });
      this.cancelRec();
    },
    // ---- send ----------------------------------------------------------------------------------------------------
    submit() {
      if (!this.canSend) return;
      if (this.editing) {
        this.$emit('save-edit', { id: this.editing.id, body: this.text });
        return;
      }
      const total = this.files.reduce((s, f) => s + f.size, 0);
      if (chat.quota && total > chat.quota.remaining) return;
      this.flying = true;
      setTimeout(() => (this.flying = false), 700);
      this.$emit('send', { body: this.text.trim(), files: this.files.slice() });
      this.text = '';
      this.clearFiles();
      this.error = '';
      try { localStorage.removeItem(this.draftKey(this.conversationId)); } catch (e) { /* ok */ }
      this.$nextTick(this.grow);
    },
    reset() {
      this.text = '';
      this.$nextTick(this.grow);
    },
  },
};
</script>

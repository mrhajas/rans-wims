<template>
  <div class="wc-emoji" role="dialog" :aria-label="$t('Emoji')" @keydown.esc.stop="$emit('close')">
    <input ref="q" v-model="q" class="wc-emoji-q" type="search" :placeholder="$t('Search emoji')" :aria-label="$t('Search emoji')" autocomplete="off" />
    <div v-if="!q && recent.length" class="wc-emoji-sec">
      <b>{{ $t('Recent') }}</b>
      <div class="wc-emoji-grid"><button v-for="e in recent" :key="'r' + e" type="button" @click="pick(e)">{{ e }}</button></div>
    </div>
    <div class="wc-emoji-body">
      <template v-if="q">
        <div class="wc-emoji-grid"><button v-for="e in found" :key="e" type="button" @click="pick(e)">{{ e }}</button></div>
        <p v-if="!found.length" class="wc-emoji-none">{{ $t('No emoji found.') }}</p>
      </template>
      <template v-else>
        <div v-for="g in groups" :id="'wce-' + g.key" :key="g.key" class="wc-emoji-sec">
          <b>{{ $t(g.label) }}</b>
          <div class="wc-emoji-grid"><button v-for="it in g.items" :key="it[0]" type="button" :title="it[1]" @click="pick(it[0])">{{ it[0] }}</button></div>
        </div>
      </template>
    </div>
    <div class="wc-emoji-tabs">
      <button v-for="g in groups" :key="g.key" type="button" :aria-label="$t(g.label)" @click="jump(g.key)">{{ g.icon }}</button>
    </div>
  </div>
</template>

<script>
import { EMOJI_GROUPS, searchEmoji } from '@/Core/emojiData';

const KEY = 'wimschat.emoji.recent';

export default {
  emits: ['pick', 'close'],
  data() {
    return { q: '', recent: [], groups: EMOJI_GROUPS };
  },
  computed: {
    found() {
      return searchEmoji(this.q);
    },
  },
  mounted() {
    try { this.recent = JSON.parse(localStorage.getItem(KEY) || '[]').slice(0, 16); } catch (e) { this.recent = []; }
    this.$nextTick(() => this.$refs.q && this.$refs.q.focus({ preventScroll: true }));
  },
  methods: {
    pick(e) {
      try {
        const r = [e, ...this.recent.filter(x => x !== e)].slice(0, 16);
        localStorage.setItem(KEY, JSON.stringify(r));
      } catch (x) { /* storage is optional */ }
      this.$emit('pick', e);
    },
    jump(k) {
      const el = this.$el.querySelector('#wce-' + k);
      if (el) el.scrollIntoView({ block: 'start', behavior: 'smooth' });
    },
  },
};
</script>

<template>
  <modal :show="show" max-width="3xl" :transparent="true" @close="close">
    <div class="rai-shell relative rounded-3xl overflow-hidden flex flex-col max-h-[85vh]">
      <div class="rai-ambient-glow" aria-hidden="true"></div>
      <div class="relative z-10 flex items-center justify-between gap-3 px-6 py-4 rai-divider-b">
        <div class="flex items-center gap-2.5">
          <div class="rai-orb rai-orb--sm"></div>
          <span class="rai-title">{{ $t('ARIS') }}</span>
        </div>
        <div class="flex items-center gap-1.5">
          <div class="rai-tooltip">
            <button
              type="button"
              @click="clearChat"
              :disabled="!history.length"
              class="rai-hbtn"
            >
              <icons name="trash" className="h-4 w-4" />
            </button>
            <span class="rai-tooltip-bubble">{{ $t('Clear chat') }}</span>
          </div>
          <div class="rai-tooltip">
            <button
              type="button"
              class="rai-hbtn"
            >
              <icons name="hash" className="h-4 w-4" />
            </button>
            <div class="rai-tooltip-panel">
              <div class="rai-panel-title">{{ $t('Quick tags') }}</div>
              <div v-for="tag in tagList" :key="tag.key" class="flex items-center gap-2 py-0.5">
                <span class="rai-tag-chip">#{{ tag.key }}</span>
                <span class="rai-body">{{ tag.label }}</span>
              </div>
            </div>
          </div>
          <button
            @click="close()"
            class="rai-hbtn"
          >
            <icons name="cross" className="h-4 w-4" />
          </button>
        </div>
      </div>

      <div ref="scrollArea" class="relative z-10 flex-1 overflow-y-auto px-6">
        <div v-if="!history.length" class="rai-hero-in flex flex-col items-center text-center py-10">
          <div class="rai-hero-orb-wrap mb-6 rai-orb-tooltip">
            <button
              type="button"
              @click="toggleListening"
              :aria-label="listening ? $t('Stop listening') : $t('Tap to talk with ARIS')"
              class="rai-orb-trigger"
            >
              <div class="rai-orb rai-orb--lg" :class="{ 'rai-orb--thinking': listening }"></div>
            </button>
            <span class="rai-orb-tooltip-bubble">{{ listening ? $t("I'm listening, go ahead") : $t('Prefer talking? Tap to speak with ARIS') }}</span>
          </div>
          <div class="text-2xl font-semibold rai-hello">{{ greeting }}, {{ firstName }}</div>
          <div class="text-2xl font-semibold rai-gradient-text mt-0.5">{{ $t('How can I help today?') }}</div>
          <p class="text-sm rai-muted mt-3 max-w-sm">
            {{ $t("I'm currently in training and learning mode, not broken, just growing. Keep using WIMS as usual, and I'll be back soon with real answers.") }}
          </p>
        </div>

        <div v-else class="space-y-5 py-5">
          <div v-for="entry in history" :key="entry.id" class="rai-msg-in space-y-2.5">
            <div class="flex justify-end">
              <div class="rai-bubble-user max-w-[80%] rounded-2xl rounded-br-sm px-4 py-2.5 text-sm text-white">
                {{ entry.question }}
              </div>
            </div>

            <div class="flex items-start gap-2.5">
              <div class="rai-orb rai-orb--sm flex-none mt-0.5" :class="{ 'rai-orb--thinking': entry.loading }"></div>

              <div class="rai-bubble-assistant max-w-[80%] rounded-2xl rounded-tl-sm px-4 py-3">
                <div v-if="entry.loading" class="text-sm rai-muted">{{ $t('Thinking...') }}</div>

                <template v-else>
                  <div v-if="entry.source" class="rai-source-badge" :class="`rai-source-badge--${entry.source}`">
                    {{ sourceBadgeLabel(entry.source) }}
                  </div>
                  <p class="text-sm rai-body whitespace-pre-line">{{ entry.answer }}</p>

                  <div v-if="extractFacts(entry.data).length" class="mt-2.5 grid grid-cols-2 sm:grid-cols-3 gap-2">
                    <div v-for="[key, value] in extractFacts(entry.data)" :key="key" class="rai-fact rounded-lg px-3 py-2">
                      <div class="text-[10px] uppercase tracking-wide rai-muted">{{ humanizeKey(key) }}</div>
                      <div class="text-sm font-semibold rai-strong">{{ value }}</div>
                    </div>
                  </div>

                  <div v-if="extractCounts(entry.data)" class="mt-2.5 flex flex-wrap gap-2">
                    <div
                      v-for="[status, count] in extractCounts(entry.data).entries"
                      :key="status"
                      class="rai-fact rounded-lg px-3 py-1.5 text-xs"
                    >
                      <span class="font-semibold rai-strong">{{ count }}</span>
                      <span class="rai-muted ml-1">{{ status }}</span>
                    </div>
                  </div>

                  <div v-if="extractTable(entry.data)" class="mt-2.5 overflow-x-auto">
                    <table class="w-full text-xs border-collapse">
                      <thead>
                        <tr>
                          <th
                            v-for="col in extractTable(entry.data).columns"
                            :key="col"
                            class="text-left font-semibold rai-muted uppercase tracking-wide px-2 py-1.5 rai-divider-b"
                          >
                            {{ humanizeKey(col) }}
                          </th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="(row, i) in extractTable(entry.data).rows" :key="i" class="rai-row last:border-0">
                          <td v-for="col in extractTable(entry.data).columns" :key="col" class="px-2 py-1.5 rai-body">
                            {{ row[col] }}
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </template>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="relative z-10 px-6 pt-4 pb-3 rai-divider-t">
        <div class="relative">
          <div v-if="activeTag && tagResults.length" class="rai-suggest-list">
            <button
              v-for="(name, idx) in tagResults"
              :key="name"
              type="button"
              class="rai-suggest-item"
              :class="{ 'rai-suggest-item--active': idx === tagActiveIndex }"
              @mousedown.prevent="selectTagResult(name)"
              @mouseenter="tagActiveIndex = idx"
            >
              <icons :name="tagIcon(activeTag)" class="w-3.5 h-3.5 opacity-50 flex-none" />
              <span class="truncate">{{ name }}</span>
            </button>
          </div>
          <div v-else-if="activeTag && tagLookupLoading" class="rai-suggest-list">
            <div class="px-3 py-2 text-xs rai-muted">{{ $t('Searching...') }}</div>
          </div>

          <form @submit.prevent="ask" class="rai-input-bar flex items-end gap-2 rounded-2xl px-3 py-2">
            <button
              v-if="speechSupported"
              type="button"
              @click="toggleListening"
              :data-tip="listening ? $t('Stop listening') : $t('Ask by voice')"
              class="rai-icon-btn flex items-center justify-center h-9 w-9 rounded-full flex-none focus:outline-none lg-tip"
              :class="listening ? 'rai-icon-btn--listening' : 'rai-icon-btn--mic'"
             :aria-label="listening ? $t('Stop listening') : $t('Ask by voice')">
              <icons name="microphone" class="w-4.5 h-4.5" />
            </button>
            <textarea
              ref="questionInput"
              v-model="question"
              rows="1"
              :placeholder="narrow ? $t('Ask anything') : $t('Ask me anything... (try #p, #i, #u, #c, #k, #r)')"
              class="flex-1 bg-transparent resize-none text-sm rai-textarea py-2 focus:outline-none"
              @input="onQuestionInput"
              @keydown="onQuestionKeydown"
            ></textarea>
            <button type="submit" :disabled="asking || !question.trim()" class="rai-send-btn flex items-center justify-center h-9 w-9 rounded-full flex-none text-white focus:outline-none">
              <icons name="send" class="w-4 h-4" />
            </button>
          </form>
        </div>
        <p v-if="speechError" class="mt-2 text-xs text-red-600">{{ speechError }}</p>
      </div>

      <div class="relative z-10 px-6 pb-4 text-center">
        <p class="rai-foot">{{ $t('ARIS - Adaptive RANS Intelligence System') }} · {{ $t('Designed by Extrion') }}</p>
      </div>
    </div>
  </modal>
</template>

<script>
import Modal from '@/Jetstream/Modal.vue';

const TAG_CONFIG = {
  p: { label: 'Projects', icon: 'building', source: 'projects' },
  i: { label: 'Items', icon: 'bag', source: 'items' },
  u: { label: 'Users', icon: 'users', source: 'users' },
  c: { label: 'Checkins', icon: 'in', source: 'keyword', keyword: 'checkins' },
  k: { label: 'Checkouts', icon: 'out', source: 'keyword', keyword: 'checkouts' },
  r: { label: 'Material requests', icon: 'doc-text', source: 'keyword', keyword: 'material requests' },
};

export default {
  emits: ['close'],

  components: { Modal },

  props: {
    show: {
      default: false,
    },
  },

  data() {
    return {
      narrow: typeof window !== 'undefined' && window.matchMedia('(max-width: 639.98px)').matches,
      question: '',
      asking: false,
      history: [],
      nextId: 1,
      speechSupported: false,
      listening: false,
      speechError: null,
      recognition: null,
      activeTag: null,
      tagFilter: '',
      tagResults: [],
      tagActiveIndex: -1,
      tagLookupLoading: false,
      tagLookupTimer: null,
      tagLookupSeq: 0,
    };
  },

  watch: {
    show(isShown) {
      if (isShown) {
        this.$nextTick(() => this.$refs.questionInput?.focus());
      }
    },
  },

  computed: {
    firstName() {
      const name = this.$page.props.auth?.user?.name || '';
      return name.split(' ')[0] || name;
    },

    greeting() {
      const hour = new Date().getHours();
      if (hour < 12) return this.$t('Good Morning');
      if (hour < 18) return this.$t('Good Afternoon');
      return this.$t('Good Evening');
    },

    tagList() {
      return Object.entries(TAG_CONFIG).map(([key, cfg]) => ({ key, label: cfg.label }));
    },
  },

  mounted() {
    this.setupSpeechRecognition();
    this.narrowQuery = window.matchMedia('(max-width: 639.98px)');
    this.narrowQuery.addEventListener?.('change', this.onNarrow);
  },

  beforeUnmount() {
    this.narrowQuery?.removeEventListener?.('change', this.onNarrow);
    clearTimeout(this.tagLookupTimer);
    if (this.recognition) {
      this.recognition.onresult = null;
      this.recognition.onend = null;
      this.recognition.onerror = null;
      this.recognition.stop();
    }
  },

  methods: {
    onNarrow(e) {
      this.narrow = e.matches;
    },
    close() {
      if (this.recognition && this.listening) {
        this.recognition.stop();
      }
      this.$emit('close');
    },

    clearChat() {
      this.history = [];
      this.$nextTick(() => this.$refs.questionInput?.focus());
    },

    tagIcon(key) {
      return TAG_CONFIG[key]?.icon || 'hash';
    },

    onQuestionInput() {
      const lastToken = this.question.split(/\s+/).pop();
      const match = /^#([a-z])(.*)$/i.exec(lastToken);

      if (!match) {
        this.closeTag();
        return;
      }

      const key = match[1].toLowerCase();
      const fragment = match[2];
      const cfg = TAG_CONFIG[key];

      if (!cfg) {
        this.closeTag();
        return;
      }

      if (cfg.source === 'keyword') {
        this.insertTagValue(cfg.keyword);
        return;
      }

      if (this.activeTag === key && fragment === '' && this.tagFilter === '') {
        this.closeTag();
        return;
      }

      this.activeTag = key;
      this.tagFilter = fragment;
      this.tagActiveIndex = -1;

      if (cfg.source === 'projects') {
        const list = (this.$page.props.myWarehouses || []).map(w => w.name).filter(Boolean);
        const q = fragment.toLowerCase();
        this.tagResults = (q ? list.filter(name => name.toLowerCase().includes(q)) : list).slice(0, 8);
        this.tagLookupLoading = false;
      } else {
        this.fetchTagResults(cfg.source, fragment);
      }
    },

    fetchTagResults(type, q) {
      clearTimeout(this.tagLookupTimer);
      this.tagLookupLoading = true;
      const seq = ++this.tagLookupSeq;

      this.tagLookupTimer = setTimeout(() => {
        axios
          .get(route('ai-assistant.lookup'), { params: { type, q } })
          .then(res => {
            if (seq !== this.tagLookupSeq) {
              return;
            }
            this.tagResults = res.data?.results || [];
          })
          .finally(() => {
            if (seq === this.tagLookupSeq) {
              this.tagLookupLoading = false;
            }
          });
      }, 200);
    },

    closeTag() {
      clearTimeout(this.tagLookupTimer);
      this.activeTag = null;
      this.tagFilter = '';
      this.tagResults = [];
      this.tagActiveIndex = -1;
      this.tagLookupLoading = false;
    },

    replaceLastToken(value) {
      const parts = this.question.split(/\s+/);
      parts[parts.length - 1] = value;
      this.question = parts.join(' ') + ' ';
    },

    insertTagValue(value) {
      this.replaceLastToken(value);
      this.closeTag();
      this.$nextTick(() => this.$refs.questionInput?.focus());
    },

    selectTagResult(name) {
      this.insertTagValue(name);
    },

    onQuestionKeydown(e) {
      const listVisible = this.activeTag && this.tagResults.length > 0;

      if (listVisible && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
        e.preventDefault();
        const count = this.tagResults.length;
        const dir = e.key === 'ArrowDown' ? 1 : -1;
        this.tagActiveIndex = (this.tagActiveIndex + dir + count) % count;
        return;
      }

      if (listVisible && this.tagActiveIndex >= 0 && (e.key === 'Enter' || e.key === 'Tab')) {
        e.preventDefault();
        this.selectTagResult(this.tagResults[this.tagActiveIndex]);
        return;
      }

      if (e.key === 'Escape' && this.activeTag) {
        e.preventDefault();
        this.closeTag();
        return;
      }

      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        this.closeTag();
        this.ask();
      }
    },

    setupSpeechRecognition() {
      const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
      if (!SpeechRecognition) {
        this.speechSupported = false;
        return;
      }

      this.speechSupported = true;
      this.recognition = new SpeechRecognition();
      this.recognition.continuous = false;
      this.recognition.interimResults = true;
      this.recognition.lang = document.documentElement.lang || 'en-US';

      this.recognition.onresult = event => {
        let transcript = '';
        for (let i = 0; i < event.results.length; i++) {
          transcript += event.results[i][0].transcript;
        }
        this.question = transcript;
      };

      this.recognition.onerror = event => {
        this.listening = false;
        this.speechError = `${this.$t('Voice input failed:')} ${event.error}`;
      };

      this.recognition.onend = () => {
        this.listening = false;
      };
    },

    toggleListening() {
      if (!this.recognition) {
        return;
      }

      if (this.listening) {
        this.recognition.stop();
        return;
      }

      this.speechError = null;
      this.question = '';
      this.listening = true;
      this.recognition.start();
    },

    scrollToBottom() {
      this.$nextTick(() => {
        const el = this.$refs.scrollArea;
        if (el) {
          el.scrollTop = el.scrollHeight;
        }
      });
    },

    ask() {
      const question = this.question.trim();
      this.closeTag();

      if (!question || this.asking) {
        return;
      }

      if (this.listening && this.recognition) {
        this.recognition.stop();
      }

      this.question = '';

      // ARIS's native path still needs a server round-trip today (the
      // "native" query engine is server-side PHP/Eloquent, not a
      // client-side IndexedDB port — see resources/js/Core/offlineSync.js),
      // and the AI-reasoning path obviously does too, so both are
      // unavailable offline. Short-circuit before ever calling
      // ai-assistant.ask rather than letting it fail against an
      // unreachable server.
      if (this.$connection.status === 'offline') {
        this.history.push({
          id: this.nextId++,
          question,
          loading: false,
          answer: this.$t(
            'ARIS is unavailable while WIMS is offline. Reconnect to ask questions. I can still show you cached data directly on the Items, Warehouses, and Stock pages.'
          ),
          data: null,
          source: null,
        });
        this.scrollToBottom();
        return;
      }

      this.asking = true;

      const entry = { id: this.nextId++, question, loading: true, answer: '', data: null, source: null };
      this.history.push(entry);
      this.scrollToBottom();

      const recentHistory = this.history
        .slice(-4, -1)
        .filter(e => !e.loading)
        .map(e => ({ question: e.question, answer: e.answer }));

      axios
        .post(route('ai-assistant.ask'), { question, history: recentHistory })
        .then(res => {
          entry.answer = res.data.answer;
          entry.data = res.data.data;
          entry.source = res.data.source || null;
          entry.loading = false;
        })
        .catch(() => {
          entry.answer = this.$t('Sorry, something went wrong answering that. Please try again.');
          entry.loading = false;
        })
        .finally(() => {
          this.asking = false;
          this.scrollToBottom();
        });
    },

    sourceBadgeLabel(source) {
      if (source === 'native') {
        return '🟢 ' + this.$t('WIMS Data');
      }
      if (source === 'training') {
        return '🌱 ' + this.$t('Learning');
      }
      return '✨ ' + this.$t('ARIS AI');
    },

    humanizeKey(key) {
      return String(key)
        .replace(/_/g, ' ')
        .replace(/\b\w/g, c => c.toUpperCase());
    },

    extractFacts(data) {
      if (!data || typeof data !== 'object') {
        return [];
      }
      const skip = new Set(['found', 'error', 'message', 'count']);
      return Object.entries(data).filter(([key, value]) => !skip.has(key) && !Array.isArray(value) && (value === null || typeof value !== 'object'));
    },

    extractCounts(data) {
      if (!data || typeof data !== 'object') {
        return null;
      }
      const key = Object.keys(data).find(k => data[k] && typeof data[k] === 'object' && !Array.isArray(data[k]));
      if (!key) {
        return null;
      }
      const entries = Object.entries(data[key]);
      return entries.length ? { label: key, entries } : null;
    },

    extractTable(data) {
      if (!data || typeof data !== 'object') {
        return null;
      }
      const arrayKey = Object.keys(data).find(k => Array.isArray(data[k]) && data[k].length && typeof data[k][0] === 'object');
      if (!arrayKey) {
        return null;
      }
      const rows = data[arrayKey];
      return { columns: Object.keys(rows[0]), rows };
    },
  },
};
</script>

<style scoped>
.rai-shell {
  background: linear-gradient(150deg, rgba(255, 255, 255, 0.93) 0%, rgba(235, 240, 255, 0.88) 52%, rgba(252, 231, 243, 0.82) 100%);
  -webkit-backdrop-filter: blur(42px) saturate(190%); backdrop-filter: blur(42px) saturate(190%);
  border: 1.5px solid rgba(255, 255, 255, 0.92);
  box-shadow: 0 40px 90px -26px rgba(49, 46, 129, 0.55), 0 12px 24px -12px rgba(15, 23, 42, 0.2), inset 0 1.5px 1px rgba(255, 255, 255, 0.98), inset 0 -20px 44px rgba(129, 140, 248, 0.1);
}
.rai-ambient-glow {
  position: absolute; top: -26%; left: 50%; width: 130%; aspect-ratio: 1.5 / 1; transform: translateX(-50%); pointer-events: none; z-index: 0;
  background: radial-gradient(closest-side, rgba(129, 140, 248, 0.34), rgba(244, 114, 182, 0.18) 52%, transparent 76%); filter: blur(34px);
  animation: rai-ambient-drift 9s ease-in-out infinite;
}
.rai-divider-b { border-bottom: 1px solid rgba(255, 255, 255, 0.75); background: rgba(255, 255, 255, 0.22); }
.rai-divider-t { border-top: 1px solid rgba(255, 255, 255, 0.75); background: rgba(255, 255, 255, 0.22); }
.rai-title { font-size: 0.9rem; font-weight: 800; letter-spacing: 0.04em; color: #1e1b4b; }
.rai-hello { color: #1e1b4b; }
.rai-body { color: #334155; }
.rai-strong { color: #0f172a; }
.rai-muted { color: #64748b; }
.rai-foot { font-size: 11px; color: #64748b; }
.rai-textarea { color: #0f172a; }
.rai-textarea::placeholder { color: #94a3b8; }
.rai-row { border-bottom: 1px solid rgba(148, 163, 184, 0.22); }
.rai-fact { background: rgba(255, 255, 255, 0.6); border: 1px solid rgba(255, 255, 255, 0.95); box-shadow: inset 0 1px 1px #fff, 0 8px 14px -12px rgba(49, 46, 129, 0.45); }
.rai-hbtn {
  display: inline-flex; align-items: center; justify-content: center; height: 34px; width: 34px; border-radius: 9999px; color: #475569; cursor: pointer;
  background: rgba(255, 255, 255, 0.62); border: 1px solid rgba(255, 255, 255, 0.95); box-shadow: inset 0 1px 1px #fff, 0 8px 14px -10px rgba(49, 46, 129, 0.45);
  transition: transform 0.15s ease, color 0.15s ease, background 0.15s ease;
}
.rai-hbtn:hover:not(:disabled) { color: #4338ca; background: rgba(255, 255, 255, 0.9); transform: translateY(-1px); }
.rai-hbtn:disabled { opacity: 0.4; cursor: not-allowed; }

.rai-tooltip { position: relative; display: inline-flex; }
.rai-tooltip-bubble {
  position: absolute; top: calc(100% + 8px); right: 0; z-index: 30; padding: 6px 10px; border-radius: 10px; font-size: 0.72rem; font-weight: 600; white-space: nowrap;
  color: #1e1b4b; background: rgba(255, 255, 255, 0.92); border: 1px solid #fff; box-shadow: 0 14px 26px -12px rgba(49, 46, 129, 0.45);
  opacity: 0; transform: translateY(-4px); pointer-events: none; transition: opacity 0.15s ease, transform 0.15s ease;
}
.rai-tooltip:hover .rai-tooltip-bubble { opacity: 1; transform: none; }
.rai-tooltip-panel {
  position: absolute; top: calc(100% + 8px); right: 0; z-index: 30; min-width: 190px; padding: 12px 14px; border-radius: 16px; font-size: 0.78rem;
  background: rgba(255, 255, 255, 0.9); -webkit-backdrop-filter: blur(16px); backdrop-filter: blur(16px); border: 1px solid #fff; box-shadow: 0 22px 40px -18px rgba(49, 46, 129, 0.5);
  opacity: 0; transform: translateY(-4px); pointer-events: none; transition: opacity 0.15s ease, transform 0.15s ease;
}
.rai-tooltip:hover .rai-tooltip-panel { opacity: 1; transform: none; pointer-events: auto; }
.rai-panel-title { font-size: 0.64rem; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; color: #6366f1; margin-bottom: 6px; }
.rai-tag-chip { display: inline-flex; padding: 1px 8px; border-radius: 9999px; font-size: 0.7rem; font-weight: 800; color: #4338ca; background: rgba(99, 102, 241, 0.14); border: 1px solid rgba(99, 102, 241, 0.25); }

.rai-source-badge { display: inline-flex; align-items: center; margin-bottom: 6px; padding: 2px 9px; border-radius: 9999px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; }
.rai-source-badge--native { color: #047857; background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); }
.rai-source-badge--ai { color: #6d28d9; background: rgba(139, 92, 246, 0.15); border: 1px solid rgba(139, 92, 246, 0.3); }
.rai-source-badge--training { color: #b45309; background: rgba(245, 158, 11, 0.16); border: 1px solid rgba(245, 158, 11, 0.32); }

.rai-suggest-list {
  position: absolute; left: 0; right: 0; bottom: calc(100% + 8px); z-index: 20; max-height: 220px; overflow-y: auto; padding: 6px; border-radius: 18px;
  background: rgba(255, 255, 255, 0.9); -webkit-backdrop-filter: blur(16px); backdrop-filter: blur(16px); border: 1px solid #fff; box-shadow: 0 22px 40px -18px rgba(49, 46, 129, 0.55);
  animation: rai-suggest-in 0.15s ease-out;
}
.rai-suggest-item { display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border-radius: 12px; text-align: left; font-size: 0.84rem; color: #1e293b; cursor: pointer; }
.rai-suggest-item--active { background: rgba(99, 102, 241, 0.14); color: #3730a3; }

.rai-gradient-text {
  background: linear-gradient(90deg, #2563eb, #7c3aed, #db2777, #7c3aed, #2563eb); background-size: 200% auto;
  -webkit-background-clip: text; background-clip: text; color: transparent; animation: rai-gradient-shift 5s linear infinite;
}
.rai-hero-in { animation: rai-hero-in 0.6s cubic-bezier(0.16, 1, 0.3, 1) both; }
.rai-hero-orb-wrap { display: inline-flex; position: relative; animation: rai-hero-orb-in 0.6s cubic-bezier(0.16, 1, 0.3, 1) both; }
.rai-orb-trigger { display: inline-flex; border-radius: 9999px; transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1); cursor: pointer; }
.rai-orb-trigger:hover { transform: scale(1.07); }
.rai-orb-trigger:active { transform: scale(0.96); }
.rai-orb-tooltip { position: relative; }
.rai-orb-tooltip-bubble {
  position: absolute; left: 50%; top: calc(100% + 12px); transform: translate(-50%, -4px); z-index: 30; padding: 7px 12px; border-radius: 12px; font-size: 0.74rem; font-weight: 600; white-space: nowrap;
  color: #1e1b4b; background: rgba(255, 255, 255, 0.92); border: 1px solid #fff; box-shadow: 0 16px 28px -14px rgba(49, 46, 129, 0.5);
  opacity: 0; pointer-events: none; transition: opacity 0.15s ease, transform 0.15s ease;
}
.rai-orb-tooltip:hover .rai-orb-tooltip-bubble { opacity: 1; transform: translate(-50%, 0); }

.rai-msg-in { animation: rai-msg-in 0.35s cubic-bezier(0.16, 1, 0.3, 1) both; }
.rai-bubble-user { background: linear-gradient(145deg, #6366f1, #7c3aed); box-shadow: 0 14px 24px -14px rgba(79, 70, 229, 0.75), inset 0 1px 1px rgba(255, 255, 255, 0.35); }
.rai-bubble-assistant { background: rgba(255, 255, 255, 0.66); border: 1px solid rgba(255, 255, 255, 0.95); box-shadow: inset 0 1px 1px #fff, 0 14px 24px -16px rgba(49, 46, 129, 0.4); -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px); }

.rai-input-bar {
  background: rgba(255, 255, 255, 0.68); border: 1px solid rgba(255, 255, 255, 0.95); -webkit-backdrop-filter: blur(14px); backdrop-filter: blur(14px);
  box-shadow: inset 0 1px 1px #fff, 0 16px 28px -18px rgba(49, 46, 129, 0.5); transition: box-shadow 0.2s ease, border-color 0.2s ease;
}
.rai-input-bar:hover { box-shadow: inset 0 1px 1px #fff, 0 18px 30px -16px rgba(49, 46, 129, 0.6); }
.rai-input-bar:focus-within { border-color: rgba(129, 140, 248, 0.75); animation: rai-input-pulse 2.6s ease-in-out infinite; }
@keyframes rai-input-pulse { 0%, 100% { box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.22), 0 16px 28px -16px rgba(79, 70, 229, 0.5); } 50% { box-shadow: 0 0 0 6px rgba(129, 140, 248, 0.12), 0 16px 28px -16px rgba(79, 70, 229, 0.5); } }
.rai-icon-btn { transition: transform 0.15s ease, background 0.15s ease, color 0.15s ease; cursor: pointer; }
.rai-icon-btn--mic { color: #4f46e5; background: rgba(99, 102, 241, 0.12); border: 1px solid rgba(99, 102, 241, 0.2); }
.rai-icon-btn--mic:hover { background: rgba(99, 102, 241, 0.22); transform: translateY(-1px); }
.rai-icon-btn--listening { color: #fff; background: linear-gradient(145deg, #fb7185, #e11d48); animation: rai-mic-pulse 1.4s ease-in-out infinite; }
@keyframes rai-mic-pulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(244, 63, 94, 0.5); } 50% { box-shadow: 0 0 0 9px rgba(244, 63, 94, 0); } }
.rai-send-btn { background: linear-gradient(145deg, #6366f1, #7c3aed); box-shadow: 0 12px 20px -10px rgba(79, 70, 229, 0.8), inset 0 1px 1px rgba(255, 255, 255, 0.4); transition: transform 0.15s ease, box-shadow 0.15s ease; cursor: pointer; }
.rai-send-btn:hover:not(:disabled) { transform: translateY(-1px) scale(1.05); }
.rai-send-btn:active:not(:disabled) { transform: scale(0.95); }
.rai-send-btn:disabled { opacity: 0.45; box-shadow: none; cursor: not-allowed; }

.rai-orb {
  position: relative; border-radius: 9999px; flex-shrink: 0; animation: rai-spin 7s linear infinite;
  background: conic-gradient(from 180deg, #67e8f9, #818cf8, #c084fc, #f472b6, #67e8f9);
  box-shadow: 0 0 34px 6px rgba(129, 140, 248, 0.45), 0 0 70px 18px rgba(244, 114, 182, 0.2), inset 0 0 0 2px rgba(255, 255, 255, 0.6);
}
.rai-orb::before {
  content: ''; position: absolute; inset: 10%; border-radius: 9999px; filter: blur(1.5px); animation: rai-pulse 3.2s ease-in-out infinite;
  background: radial-gradient(circle at 35% 30%, rgba(255, 255, 255, 0.85), transparent 48%), radial-gradient(circle at 65% 70%, rgba(236, 72, 153, 0.45), transparent 55%);
}
.rai-orb--lg { width: 104px; height: 104px; }
.rai-orb--sm { width: 30px; height: 30px; box-shadow: 0 0 14px 2px rgba(129, 140, 248, 0.5), inset 0 0 0 1.5px rgba(255, 255, 255, 0.6); }
.rai-orb--thinking { animation: rai-spin 1.1s linear infinite; }

@keyframes rai-spin { to { transform: rotate(360deg); } }
@keyframes rai-pulse { 0%, 100% { opacity: 0.7; transform: scale(1); } 50% { opacity: 1; transform: scale(1.06); } }
@keyframes rai-ambient-drift { 0%, 100% { opacity: 0.85; transform: translateX(-50%) scale(1); } 50% { opacity: 1; transform: translateX(-50%) scale(1.08); } }
@keyframes rai-gradient-shift { to { background-position: -200% center; } }
@keyframes rai-hero-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
@keyframes rai-hero-orb-in { from { opacity: 0; transform: scale(0.6); } to { opacity: 1; transform: scale(1); } }
@keyframes rai-msg-in { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
@keyframes rai-suggest-in { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
@media (prefers-reduced-motion: reduce) { .rai-orb, .rai-ambient-glow, .rai-gradient-text, .rai-input-bar { animation: none !important; } }
</style>

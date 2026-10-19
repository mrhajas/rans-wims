<template>
  <section class="ou-card" :aria-label="$t('Online Users')">
    <div class="ou-line">
      <div v-if="loading && !users.length" class="ou-skeleton"><i v-for="n in 5" :key="n"></i></div>
      <div v-else-if="!present.length" class="ou-empty">{{ $t('Nobody is online right now.') }}</div>
      <div v-else class="ou-row">
        <span v-for="u in preview" :key="u.id" class="ou-person lg-tip" :class="{ 'is-chat': canChat(u) }" :data-tip="canChat(u) ? tip(u) + ' · ' + $t('Click to chat') : tip(u)" :role="canChat(u) ? 'button' : null" :tabindex="canChat(u) ? 0 : null" @click="chatWith(u)" @keydown.enter="chatWith(u)">
          <span class="ou-avatar-wrap">
            <photo-zoom v-if="u.photo" :src="u.photo" :alt="u.name" :size="38" :zoom="84" placement="over" />
            <span v-else class="ou-initials" :style="{ '--hue': hue(u) }">{{ initials(u.name) }}</span>
            <span class="ou-dot" :class="'is-' + u.presence"></span>
          </span>
        </span>
        <button v-if="hiddenCount > 0" type="button" class="ou-more-bubble" @click="openAll">+{{ hiddenCount }}</button>
      </div>

      <button type="button" class="ou-open lg-tip" :data-tip="$t('View Online Users')" :aria-label="$t('View Online Users')" @click="openAll">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" class="ou-ico">
          <path d="M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20" />
          <circle cx="10" cy="8" r="3.2" />
          <path d="M20 20v-1.4a3.2 3.2 0 0 0-2.3-3" />
          <path d="M15.6 5a3.2 3.2 0 0 1 0 6" />
        </svg>
      </button>
    </div>

    <modal :show="showAll" max-width="3xl" transparent @close="showAll = false">
      <div class="ouv-panel">
        <div class="ouv-head">
          <div class="ouv-head-main">
            <span class="ouv-badge">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="ou-ico">
                <path d="M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20" /><circle cx="10" cy="8" r="3.2" /><path d="M20 20v-1.4a3.2 3.2 0 0 0-2.3-3" /><path d="M15.6 5a3.2 3.2 0 0 1 0 6" />
              </svg>
            </span>
            <div class="min-w-0">
              <h3 class="ouv-title">{{ $t('Online Users') }}</h3>
              <p class="ouv-sub">{{ $t('Online now, idle for up to 30 minutes, or last seen earlier.') }}</p>
            </div>
          </div>
          <button type="button" class="ouv-close" :aria-label="$t('Close')" @click="showAll = false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" class="ou-ico"><path d="M6 6l12 12M18 6L6 18" /></svg>
          </button>
        </div>

        <div class="ouv-stats">
          <button v-for="f in filters" :key="f.key" type="button" class="ouv-stat" :class="[{ 'is-active': filter === f.key }, 'is-' + f.key]" @click="filter = f.key">
            <span class="ouv-stat-num">{{ f.count }}</span>
            <span class="ouv-stat-label">{{ f.label }}</span>
          </button>
          <label class="ouv-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" class="ou-ico"><circle cx="11" cy="11" r="6.5" /><path d="M20 20l-4-4" /></svg>
            <input v-model="q" type="search" :placeholder="$t('Search name or role')" />
          </label>
        </div>

        <div class="ouv-body">
          <div v-if="!shown.length" class="ou-empty">{{ $t('No one matches.') }}</div>
          <div v-else class="ouv-grid">
            <article v-for="u in shown" :key="u.id" class="ouv-user" :class="['is-' + u.presence, { 'is-chat': canChat(u) }]" :role="canChat(u) ? 'button' : null" :tabindex="canChat(u) ? 0 : null" :title="canChat(u) ? $t('Click to chat') : null" @click="chatWith(u)" @keydown.enter="chatWith(u)">
              <span class="ou-avatar-wrap">
                <photo-zoom v-if="u.photo" :src="u.photo" :alt="u.name" :size="56" :zoom="150" placement="over" />
                <span v-else class="ou-initials ou-initials-lg" :style="{ '--hue': hue(u) }">{{ initials(u.name) }}</span>
                <span class="ou-dot ou-dot-lg" :class="'is-' + u.presence"></span>
              </span>
              <div class="ouv-info">
                <div class="ouv-name">{{ u.name }}<span v-if="u.is_me" class="ouv-you">{{ $t('You') }}</span></div>
                <div class="ouv-meta">{{ u.roles.length ? u.roles.join(', ') : '@' + u.username }}</div>
                <span v-if="canChat(u)" class="ouv-chat"><icons name="chat" class="w-3.5 h-3.5" />{{ $t('Chat') }}</span>
                <div class="ouv-seen" :class="'is-' + u.presence">
                  <i></i><template v-if="u.last_seen_label">{{ u.presence === 'online' ? $t('Online') : u.presence === 'idle' ? $t('Idle') : $t('Last seen') }} &middot; {{ u.last_seen_label }}</template><template v-else>{{ $t('Not seen yet') }}</template>
                </div>
              </div>
            </article>
          </div>
        </div>
      </div>
    </modal>
  </section>
</template>

<script>
import Modal from '@/Jetstream/Modal.vue';
import PhotoZoom from '@/Shared/Page/PhotoZoom.vue';
import { chat, startDirect, start, errorText, toast } from '@/Core/wimschat';

// the strip is refreshed in the background (one small request, nothing else on the page reloads): every 3 minutes, paused while the tab is hidden
const REFRESH_MS = 180000;
const AVATAR = 38; // px, matches .ou-initials / PhotoZoom size
const GAP = 10;
const BUTTON = 38;
const LINE_GAP = 14;

export default {
  components: { Modal, PhotoZoom },

  data() {
    return { users: [], counts: { online: 0, idle: 0, offline: 0 }, loading: true, showAll: false, filter: 'all', q: '', timer: null, slots: 5, observer: null, fetching: false, loadedAt: 0, onVisible: null };
  },

  computed: {
    // Online + idle people (offline ones only appear in the full list).
    present() {
      return this.users.filter(u => u.presence !== 'offline');
    },
    // As many faces as fit in the bar; when some don't, the last slot becomes the "+N" bubble.
    preview() {
      return this.present.length <= this.slots ? this.present : this.present.slice(0, Math.max(0, this.slots - 1));
    },
    hiddenCount() {
      return this.present.length - this.preview.length;
    },
    filters() {
      return [
        { key: 'all', label: this.$t('All'), count: this.users.length },
        { key: 'online', label: this.$t('Online'), count: this.counts.online },
        { key: 'idle', label: this.$t('Idle'), count: this.counts.idle },
        { key: 'offline', label: this.$t('Offline'), count: this.counts.offline },
      ];
    },
    shown() {
      const needle = this.q.trim().toLowerCase();

      return this.users.filter(u => {
        if (this.filter !== 'all' && u.presence !== this.filter) {
          return false;
        }

        return !needle || (u.name + ' ' + u.username + ' ' + u.roles.join(' ')).toLowerCase().includes(needle);
      });
    },
  },

  mounted() {
    this.measure();
    if (window.ResizeObserver) {
      this.observer = new ResizeObserver(() => this.measure());
      this.observer.observe(this.$el.querySelector('.ou-line'));
    }
    this.load();
    this.timer = setInterval(() => !document.hidden && this.load(), REFRESH_MS);
    // coming back to the tab after a while: refresh once now instead of showing old faces until the next tick
    this.onVisible = () => { if (!document.hidden && Date.now() - this.loadedAt > REFRESH_MS) this.load(); };
    document.addEventListener('visibilitychange', this.onVisible);
  },

  beforeUnmount() {
    clearInterval(this.timer);
    document.removeEventListener('visibilitychange', this.onVisible);
    if (this.observer) this.observer.disconnect();
  },

  methods: {
    // an online person other than me, when I may use WIMSChat: a click opens the chat drawer on the chat with them (a new chat if there is none yet)
    canChat(u) {
      const p = this.$page.props;
      return u.presence === 'online' && !u.is_me && !!u.can_chat && !!(p.auth && p.auth.user) && this.$can('view-wimschat') && this.$can('start-wimschat-conversations') && (p.enabledFeatures?.wimschat !== false || this.$super);
    },
    async chatWith(u) {
      if (!this.canChat(u)) return;
      this.showAll = false;
      chat.drawerOpen = true;
      try {
        await start();
        // the chat may still be loading when the page was just opened
        for (let i = 0; i < 50 && !chat.booted; i++) await new Promise(r => setTimeout(r, 100));
        if (!chat.booted) throw new Error(chat.bootError || 'not ready');
        await startDirect(u.id);
      } catch (e) {
        toast(errorText(e, this.$t('The chat could not be opened.')), 'error');
      }
    },
    measure() {
      const line = this.$el.querySelector('.ou-line');
      if (!line) return;
      const room = line.clientWidth - BUTTON - LINE_GAP;
      this.slots = Math.max(1, Math.floor((room + GAP) / (AVATAR + GAP)));
    },
    load() {
      if (this.fetching) return; // never two requests at once
      this.fetching = true;
      axios
        .get(this.route('dashboard.online-users'))
        .then(({ data }) => {
          this.users = data.users;
          this.loadedAt = Date.now();
          this.counts = { online: data.online, idle: data.idle, offline: data.offline };
        })
        .catch(() => {})
        .finally(() => { this.loading = false; this.fetching = false; });
    },
    openAll() {
      this.filter = 'all';
      this.q = '';
      this.showAll = true;
      this.load();
    },
    firstName(name) {
      return String(name || '').split(' ')[0];
    },
    initials(name) {
      return String(name || '')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map(w => w[0].toUpperCase())
        .join('');
    },
    tip(u) {
      const status = u.presence === 'online' ? this.$t('Online') : this.$t('Idle') + ' · ' + u.last_seen_label;

      return u.name + ' - ' + status;
    },
    hue(u) {
      return (u.id * 47) % 360;
    },
  },
};
</script>

<style scoped>
.ou-ico { width: 18px; height: 18px; flex: none; }
.ou-card { padding: 10px 14px; border-radius: 22px; background: linear-gradient(150deg, rgba(255, 255, 255, 0.78), rgba(236, 244, 255, 0.6)); border: 1.5px solid rgba(255, 255, 255, 0.9); box-shadow: 0 14px 22px -10px rgba(59, 99, 200, 0.38), 0 28px 38px -18px rgba(15, 23, 42, 0.22), inset 0 1.5px 1px #fff; backdrop-filter: blur(14px); }
.ou-line { display: flex; align-items: center; min-height: 38px; gap: 14px; min-width: 0; }
.ou-title-wrap { display: flex; align-items: center; gap: 9px; flex: none; }
.ou-title-text { display: flex; flex-direction: column; line-height: 1.25; }
.ou-title { font-size: 0.82rem; font-weight: 500; color: #475569; white-space: nowrap; }
.ou-count { font-size: 0.68rem; font-weight: 300; color: #94a3b8; white-space: nowrap; }
.ou-pulse { width: 8px; height: 8px; border-radius: 9999px; background: #cbd5e1; flex: none; }
.ou-pulse.is-on { background: #22c55e; box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.5); animation: ou-ping 2.2s ease-out infinite; }
@keyframes ou-ping { 70% { box-shadow: 0 0 0 7px rgba(34, 197, 94, 0); } 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); } }

.ou-open { padding: 0; display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; flex: none; margin-left: auto; border-radius: 12px; color: #3b82f6; background: rgba(59, 130, 246, 0.1); transition: background 0.15s ease, transform 0.15s ease; }
.ou-open:hover { background: rgba(59, 130, 246, 0.18); transform: translateY(-1px); }

.ou-row { display: flex; align-items: center; gap: 10px; min-width: 0; flex: 1 1 auto; overflow: visible; }
.ou-person { display: inline-flex; align-items: center; flex: none; height: 38px; }
.ou-person.is-chat { cursor: pointer; border-radius: 9999px; transition: transform 0.15s ease; } .ou-person.is-chat:hover { transform: translateY(-2px); } .ou-person.is-chat:focus-visible { outline: 3px solid rgba(47, 111, 179, 0.5); outline-offset: 2px; }
.ouv-user.is-chat { cursor: pointer; } .ouv-user.is-chat:hover, .ouv-user.is-chat:focus-visible { transform: translateY(-2px); outline: none; box-shadow: 0 16px 26px -14px rgba(59, 99, 200, 0.6), 0 0 0 1.5px #2f6fb3; }
.ouv-chat { display: inline-flex; align-items: center; gap: 4px; margin-left: auto; padding: 3px 10px; border-radius: 9999px; font-size: 0.7rem; font-weight: 800; color: #fff; background: linear-gradient(145deg, #3b82c4, #16365c); box-shadow: 0 8px 14px -8px rgba(22, 54, 92, 0.8); }
.ou-avatar-wrap { position: relative; display: inline-flex; align-items: center; height: 38px; }
.ou-initials { display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 9999px; font-size: 0.7rem; font-weight: 500; color: hsl(var(--hue) 45% 38%); background: hsl(var(--hue) 80% 92%); }
.ou-initials-lg { width: 56px; height: 56px; font-size: 0.95rem; }
.ou-dot { position: absolute; right: -1px; bottom: 0; width: 10px; height: 10px; border-radius: 9999px; border: 2px solid #fff; background: #cbd5e1; z-index: 2; }
.ou-dot-lg { width: 13px; height: 13px; right: 1px; bottom: 2px; }
.ou-dot.is-online { background: #22c55e; }
.ou-dot.is-idle { background: #f59e0b; }
.ou-dot.is-offline { background: #cbd5e1; }
.ou-more-bubble { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 38px; height: 38px; padding: 0; border-radius: 9999px; font-size: 0.72rem; font-weight: 500; color: #3b82f6; background: rgba(59, 130, 246, 0.1); border: 1.5px dashed rgba(59, 130, 246, 0.35); transition: background 0.15s ease; }
.ou-more-bubble:hover { background: rgba(59, 130, 246, 0.18); }
.ou-skeleton { display: flex; gap: 10px; flex: 1; }
.ou-skeleton i { width: 38px; height: 38px; border-radius: 9999px; background: linear-gradient(90deg, #e8eef9, #f4f7fd, #e8eef9); background-size: 200% 100%; animation: ou-sh 1.4s linear infinite; }
@keyframes ou-sh { to { background-position: -200% 0; } }
.ou-empty { flex: 1; font-size: 0.78rem; font-weight: 300; color: #94a3b8; }
.ou-card .ou-empty { padding: 0; }

/* Modal */
.ouv-panel { position: relative; display: flex; flex-direction: column; max-height: calc(100vh - 24px); max-height: calc(100dvh - 24px); border-radius: 28px; overflow: hidden; background: linear-gradient(150deg, rgba(255, 255, 255, 0.8) 0%, rgba(224, 236, 255, 0.62) 55%, rgba(207, 250, 254, 0.46) 100%); backdrop-filter: blur(28px) saturate(190%); -webkit-backdrop-filter: blur(28px) saturate(190%); border: 1.5px solid rgba(255, 255, 255, 0.85); box-shadow: 0 34px 80px -22px rgba(49, 78, 160, 0.5), inset 0 1.5px 1px rgba(255, 255, 255, 0.95); }
.ouv-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 20px 24px 12px; }
.ouv-head-main { display: flex; align-items: center; gap: 12px; min-width: 0; }
.ouv-badge { display: inline-flex; align-items: center; justify-content: center; width: 42px; height: 42px; border-radius: 14px; flex: none; color: #fff; background: #3b82f6; }
.ouv-badge .ou-ico { width: 21px; height: 21px; }
.ouv-title { font-size: 1.1rem; font-weight: 600; color: #1e293b; line-height: 1.2; }
.ouv-sub { font-size: 0.75rem; font-weight: 300; color: #64748b; }
.ouv-close { padding: 0; display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; flex: none; border-radius: 9999px; color: #475569; background: rgba(255, 255, 255, 0.6); border: 1px solid rgba(255, 255, 255, 0.95); transition: transform 0.15s ease; }
.ouv-close:hover { transform: rotate(90deg); }

.ouv-stats { display: flex; align-items: center; gap: 8px; padding: 4px 24px 12px; flex-wrap: wrap; }
.ouv-stat { display: inline-flex; align-items: baseline; gap: 6px; padding: 6px 14px; border-radius: 9999px; background: rgba(255, 255, 255, 0.55); border: 1px solid rgba(255, 255, 255, 0.9); color: #64748b; transition: background 0.15s ease, color 0.15s ease; }
.ouv-stat-num { font-size: 0.95rem; font-weight: 600; font-variant-numeric: tabular-nums; }
.ouv-stat-label { font-size: 0.72rem; font-weight: 400; }
.ouv-stat.is-active { background: #3b82f6; color: #fff; border-color: #3b82f6; }
.ouv-search { display: flex; align-items: center; gap: 6px; margin-left: auto; padding: 6px 12px; border-radius: 9999px; background: rgba(255, 255, 255, 0.6); border: 1px solid rgba(255, 255, 255, 0.95); color: #94a3b8; }
.ouv-search input { border: 0; outline: 0; background: transparent; font-size: 0.78rem; font-weight: 300; color: #334155; width: 150px; padding: 0; box-shadow: none; }
.ouv-search input:focus { box-shadow: none; }

.ouv-body { flex: 1 1 auto; min-height: 0; padding: 4px 24px 24px; max-height: 560px; overflow-y: auto; scrollbar-width: thin; }
.ouv-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(250px, 100%), 1fr)); gap: 12px; }
.ouv-user { display: flex; align-items: center; gap: 14px; padding: 12px 14px; border-radius: 20px; background: rgba(255, 255, 255, 0.62); border: 1px solid rgba(255, 255, 255, 0.95); box-shadow: 0 10px 22px -16px rgba(59, 99, 200, 0.4), inset 0 1px 1px #fff; transition: transform 0.18s ease, box-shadow 0.18s ease; }
.ouv-user:hover { transform: translateY(-2px); box-shadow: 0 16px 28px -16px rgba(59, 99, 200, 0.5), inset 0 1px 1px #fff; }
.ouv-info { min-width: 0; }
.ouv-name { display: flex; align-items: center; gap: 6px; font-size: 0.86rem; font-weight: 500; color: #1e293b; }
.ouv-you { padding: 0 7px; border-radius: 9999px; font-size: 0.6rem; font-weight: 500; color: #3b82f6; background: rgba(59, 130, 246, 0.12); }
.ouv-meta { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.72rem; font-weight: 300; color: #64748b; }
.ouv-seen { display: flex; align-items: center; gap: 5px; margin-top: 2px; font-size: 0.68rem; font-weight: 300; }
.ouv-seen i { width: 6px; height: 6px; border-radius: 9999px; background: currentColor; }
.ouv-seen.is-online { color: #16a34a; }
.ouv-seen.is-idle { color: #d97706; }
.ouv-seen.is-offline { color: #94a3b8; }
.ouv-user.is-offline { opacity: 0.75; }

@media (max-width: 640px) {
  .ouv-search { margin-left: 0; width: 100%; }
  .ouv-search input { width: 100%; }
  .ouv-grid { grid-template-columns: 1fr; }
  .ouv-head, .ouv-stats, .ouv-body { padding-left: 16px; padding-right: 16px; }
}
@media (prefers-reduced-motion: reduce) { .ou-pulse.is-on, .ou-skeleton i { animation: none; } }

/* Dark mode */
html.dark .ou-card { background: linear-gradient(150deg, rgba(21, 28, 54, 0.92), rgba(13, 18, 36, 0.94)); border-color: rgba(255, 255, 255, 0.09); box-shadow: 0 18px 38px -22px rgba(0, 0, 0, 0.85), 0 1px 0 rgba(255, 255, 255, 0.04) inset; }
html.dark .ou-dot { border-color: #10162b; }
html.dark .ou-initials { color: hsl(var(--hue) 80% 82%); background: hsl(var(--hue) 45% 24%); }
html.dark .ou-open { color: #cdbfff; background: rgba(124, 92, 255, 0.16); }
html.dark .ou-open:hover { background: rgba(124, 92, 255, 0.3); }
html.dark .ou-more-bubble { color: #cdbfff; background: rgba(124, 92, 255, 0.14); border-color: rgba(124, 92, 255, 0.5); }
html.dark .ouv-panel { background: linear-gradient(160deg, #151c36 0%, #0e1326 100%); border-color: rgba(255, 255, 255, 0.12); box-shadow: 0 40px 90px -30px rgba(0, 0, 0, 0.95), 0 0 0 1px rgba(124, 92, 255, 0.12); }
html.dark .ouv-title { color: #eef0fa; }
html.dark .ouv-sub { color: #a4acc8; }
html.dark .ouv-badge { background: linear-gradient(145deg, #7c5cff, #5b3df5); box-shadow: 0 10px 22px -8px rgba(124, 92, 255, 0.8); }
html.dark .ouv-close { color: #cfd4ea; background: rgba(255, 255, 255, 0.06); border-color: rgba(255, 255, 255, 0.12); }
html.dark .ouv-stat { color: #a4acc8; background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.09); }
html.dark .ouv-stat.is-active { background: #7c5cff; border-color: #7c5cff; color: #fff; box-shadow: 0 8px 20px -8px rgba(124, 92, 255, 0.8); }
html.dark .ouv-search { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.1); color: #a4acc8; }
html.dark .ouv-search input { color: #eef0fa; background: transparent; }
html.dark .ouv-user { background: rgba(255, 255, 255, 0.045); border-color: rgba(255, 255, 255, 0.08); box-shadow: none; }
html.dark .ouv-user:hover { background: rgba(124, 92, 255, 0.1); border-color: rgba(124, 92, 255, 0.4); box-shadow: 0 14px 30px -16px rgba(124, 92, 255, 0.6); }
html.dark .ouv-name { color: #eef0fa; }
html.dark .ouv-meta { color: #a4acc8; }
html.dark .ouv-you { color: #cdbfff; background: rgba(124, 92, 255, 0.2); }
html.dark .ouv-seen.is-online { color: #34d399; }
html.dark .ouv-seen.is-idle { color: #fbbf24; }
html.dark .ouv-seen.is-offline { color: #747c9b; }
html.dark .ou-empty { color: #747c9b; }
</style>

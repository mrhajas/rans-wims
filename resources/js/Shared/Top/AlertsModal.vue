<template>
  <modal :show="show" :max-width="maxWidth" :closeable="closeable" transparent @close="close">
    <div class="al-panel">
      <!-- Header -->
      <div class="al-head">
        <div class="al-head-main">
          <span class="al-bell"><icons name="bell" className="h-5 w-5" /></span>
          <div class="min-w-0">
            <h3 class="al-title">{{ $t('Application Alerts') }}</h3>
            <p class="al-sub">
              <template v-if="unreadTotal">{{ unreadTotal }} {{ $t('unread') }} &middot; {{ bundles.length }} {{ bundles.length === 1 ? $t('module') : $t('modules') }}</template>
              <template v-else>{{ $t('You are all caught up.') }}</template>
            </p>
          </div>
        </div>
        <button type="button" class="al-close" :aria-label="$t('Close')" @click="close()"><icons name="cross" className="h-4 w-4" /></button>
      </div>

      <div class="al-body">
        <loading v-if="loading" />

        <!-- Today summary -->
        <section v-if="alerts.today_cards && alerts.today_cards.length" class="al-today">
          <h4 class="al-section">{{ $t('Today Summary') }}</h4>
          <div class="al-today-grid">
            <Link v-for="card in alerts.today_cards" :key="card.key" :href="card.url" class="al-tile" :style="{ '--accent': cardAccent(card.key) }">
              <span class="al-tile-icon"><icons :name="cardIcon(card.key)" className="h-4 w-4" /></span>
              <span class="al-tile-text">
                <span class="al-tile-label">{{ $t(card.title) }}</span>
                <span class="al-tile-count">{{ $number(card.count || 0) }}</span>
              </span>
            </Link>
          </div>
        </section>

        <!-- Notifications -->
        <section v-if="alerts.notifications && alerts.notifications.length" class="al-notes">
          <div class="al-toolbar">
            <div class="al-seg" role="tablist" :aria-label="$t('Alert view')">
              <button type="button" role="tab" :aria-selected="mode === 'bundled'" class="al-seg-btn" :class="{ 'al-seg-on': mode === 'bundled' }" @click="setMode('bundled')">
                <icons name="collection" className="h-3.5 w-3.5" /> {{ $t('By module') }}
              </button>
              <button type="button" role="tab" :aria-selected="mode === 'all'" class="al-seg-btn" :class="{ 'al-seg-on': mode === 'all' }" @click="setMode('all')">
                <icons name="list" className="h-3.5 w-3.5" /> {{ $t('All alerts') }}
                <span class="al-seg-count">{{ alerts.notifications.length }}</span>
              </button>
            </div>
            <button type="button" class="al-markall" :disabled="actioning" @click="markAllRead">
              <icons name="tick" className="h-3.5 w-3.5" /> {{ $t('Mark Read All') }}
            </button>
          </div>

          <!-- Bundled: one card per module, the module with the newest alert first -->
          <div v-if="mode === 'bundled'" class="al-bundles">
            <article v-for="b in bundles" :key="b.module" class="al-bundle" :style="{ '--accent': b.accent }">
              <header class="al-bundle-head" role="button" tabindex="0" @click="toggleCollapse(b.module)" @keydown.enter="toggleCollapse(b.module)">
                <span class="al-mod-icon"><icons name="bell" className="al-bell-svg h-5 w-5" /></span>
                <div class="al-bundle-meta">
                  <div class="al-bundle-title">{{ $t(b.label) }}</div>
                  <div class="al-bundle-sub">{{ $t('Latest') }} {{ ago(b.latest) }}</div>
                </div>
                <span class="al-badge lg-tip" :data-tip="$t('Unread')" :aria-label="$t('Unread')">{{ b.items.length }}</span>
                <button type="button" class="al-mark" :disabled="actioning" @click.stop="markModule(b)">
                  <icons name="tick" className="h-3 w-3" /> {{ $t('Mark read') }}
                </button>
                <icons name="chevron-down" className="al-chev h-4 w-4" :class="{ 'al-chev-open': !isCollapsed(b.module) }" />
              </header>

              <ul v-show="!isCollapsed(b.module)" class="al-list">
                <li v-for="n in visibleItems(b)" :key="n.id">
                  <button type="button" class="al-note" @click="openNotification(n)">
                    <span class="al-dot" :class="{ 'al-dot-on': n.unread }"></span>
                    <span class="al-note-main">
                      <span class="al-note-title">{{ n.title }}</span>
                      <span class="al-note-msg">{{ n.message }}</span>
                    </span>
                    <span class="al-note-time">{{ ago(n.created_at) }}</span>
                  </button>
                </li>
              </ul>
              <button v-if="!isCollapsed(b.module) && b.items.length > PREVIEW" type="button" class="al-more" @click="toggleMore(b.module)">
                <template v-if="isMore(b.module)">{{ $t('Show less') }}</template>
                <template v-else>{{ $t('Show') }} {{ b.items.length - PREVIEW }} {{ $t('more') }}</template>
              </button>
            </article>
          </div>

          <!-- All: a single list, newest first, every alert tagged with its module -->
          <ul v-else class="al-flat">
            <li v-for="n in alerts.notifications" :key="n.id" :style="{ '--accent': n.module_accent }">
              <button type="button" class="al-flat-note" @click="openNotification(n)">
                <span class="al-mod-icon al-mod-icon-sm"><icons name="bell" className="al-bell-svg h-4 w-4" /></span>
                <span class="al-note-main">
                  <span class="al-flat-top">
                    <span class="al-chip">{{ $t(n.module_label) }}</span>
                    <span class="al-note-time">{{ ago(n.created_at) }}</span>
                  </span>
                  <span class="al-note-title">{{ n.title }}</span>
                  <span class="al-note-msg">{{ n.message }}</span>
                </span>
                <span class="al-dot" :class="{ 'al-dot-on': n.unread }"></span>
              </button>
            </li>
          </ul>
        </section>

        <div v-if="!got_alerts && !loading" class="al-empty">
          <span class="al-empty-icon"><icons name="bell" className="h-6 w-6" /></span>
          <p>{{ $t('There is no data to display.') }}</p>
        </div>
        <div v-else-if="got_alerts && !(alerts.notifications && alerts.notifications.length) && !loading" class="al-caught">
          <icons name="tick" className="h-4 w-4" /> {{ $t('No unread alerts. You are all caught up.') }}
        </div>
      </div>
    </div>
  </modal>
</template>

<script>
import Modal from '@/Jetstream/Modal.vue';
import Loading from '@/Shared/Loading.vue';

const CARD_ICONS = {
  transfers: { icon: 'refresh', accent: '#7c3aed' },
  checkins: { icon: 'in', accent: '#059669' },
  checkouts: { icon: 'out', accent: '#d97706' },
  adjustments: { icon: 'adjustments', accent: '#e11d48' },
};

const MODE_KEY = 'wims.alerts.view';

export default {
  emits: ['close', 'unread-count-change'],

  components: { Loading, Modal },

  props: {
    show: { default: false },
    maxWidth: { default: '2xl' },
    closeable: { default: true },
  },

  data() {
    let mode = 'bundled';
    try {
      mode = localStorage.getItem(MODE_KEY) === 'all' ? 'all' : 'bundled';
    } catch (e) {}

    return {
      alerts: { notifications: [], today_cards: [] },
      loading: true,
      actioning: false,
      mode,
      collapsed: {},
      showMore: {},
      PREVIEW: 3,
    };
  },

  watch: {
    show: {
      immediate: true,
      handler(show) {
        if (show) {
          this.loading = true;
          axios
            .get('/alerts')
            .then(res => (this.alerts = res.data || { notifications: [], today_cards: [] }))
            .finally(() => (this.loading = false));
        }
      },
    },
  },

  computed: {
    got_alerts() {
      const hasNotifications = this.alerts.notifications && this.alerts.notifications.length > 0;
      const hasTodayCards = this.alerts.today_cards && this.alerts.today_cards.length > 0;
      return hasNotifications || hasTodayCards;
    },

    unreadTotal() {
      return (this.alerts.notifications || []).filter(n => n.unread).length;
    },

    // One bundle per module. The API returns alerts newest-first, so the first time a module
    // appears is its newest alert — bundles come out ordered by latest activity, newest on top.
    bundles() {
      const map = new Map();
      (this.alerts.notifications || []).forEach(n => {
        const key = n.module || 'other';
        if (!map.has(key)) {
          map.set(key, { module: key, label: n.module_label || 'WIMS Notification', icon: n.module_icon || '🔔', accent: n.module_accent || '#4f46e5', latest: n.created_at, items: [] });
        }
        map.get(key).items.push(n);
      });
      return [...map.values()];
    },
  },

  methods: {
    cardIcon(key) {
      return CARD_ICONS[key]?.icon || 'chart-bar';
    },

    cardAccent(key) {
      return CARD_ICONS[key]?.accent || '#4f46e5';
    },

    setMode(mode) {
      this.mode = mode;
      try {
        localStorage.setItem(MODE_KEY, mode);
      } catch (e) {}
    },

    isCollapsed(m) {
      return !!this.collapsed[m];
    },
    toggleCollapse(m) {
      this.collapsed = { ...this.collapsed, [m]: !this.collapsed[m] };
    },
    isMore(m) {
      return !!this.showMore[m];
    },
    toggleMore(m) {
      this.showMore = { ...this.showMore, [m]: !this.showMore[m] };
    },
    visibleItems(b) {
      return this.isMore(b.module) ? b.items : b.items.slice(0, this.PREVIEW);
    },

    ago(value) {
      if (!value) return '';
      const t = new Date(String(value).replace(' ', 'T'));
      if (isNaN(t)) return String(value);
      const mins = Math.floor((Date.now() - t.getTime()) / 60000);
      if (mins < 1) return this.$t('Just now');
      if (mins < 60) return mins + ' ' + this.$t('min ago');
      if (mins < 60 * 24) return Math.floor(mins / 60) + ' ' + this.$t('h ago');
      if (mins < 60 * 48) return this.$t('Yesterday');
      return this.$datetime(value);
    },

    syncUnreadCount(count) {
      this.$emit('unread-count-change', Number(count || 0));
    },

    markAllRead() {
      if (this.actioning || !this.alerts.notifications.length) {
        return;
      }

      this.actioning = true;
      axios
        .post(route('notifications.alerts.read-all'))
        .then(res => {
          this.alerts.notifications = [];
          this.syncUnreadCount(res.data?.unread_count);
        })
        .finally(() => {
          this.actioning = false;
        });
    },

    markModule(bundle) {
      if (this.actioning) {
        return;
      }

      this.actioning = true;
      axios
        .post(route('notifications.alerts.read-module', bundle.module))
        .then(res => {
          this.alerts.notifications = (this.alerts.notifications || []).filter(n => (n.module || 'other') !== bundle.module);
          this.syncUnreadCount(res.data?.unread_count);
        })
        .finally(() => {
          this.actioning = false;
        });
    },

    openNotification(notification) {
      if (!notification || this.actioning) {
        return;
      }

      if (notification.unread) {
        this.actioning = true;
        axios
          .post(route('notifications.alerts.read', notification.id))
          .then(res => {
            this.alerts.notifications = (this.alerts.notifications || []).filter(item => item.id !== notification.id);
            this.syncUnreadCount(res.data?.unread_count);
            this.navigateToNotification(notification.url);
          })
          .finally(() => {
            this.actioning = false;
          });
        return;
      }

      this.navigateToNotification(notification.url);
    },

    navigateToNotification(url) {
      if (!url) {
        return;
      }

      this.close();
      window.location.assign(url);
    },

    close() {
      this.$emit('close');
    },
  },
};
</script>

<style scoped>
.al-panel {
  position: relative; display: flex; flex-direction: column; max-height: calc(100vh - 24px); max-height: calc(100dvh - 24px); border-radius: 28px; overflow: hidden;
  background: linear-gradient(150deg, rgba(255, 255, 255, 0.72) 0%, rgba(224, 231, 255, 0.58) 55%, rgba(251, 207, 232, 0.42) 100%);
  backdrop-filter: blur(28px) saturate(190%); -webkit-backdrop-filter: blur(28px) saturate(190%);
  border: 1.5px solid rgba(255, 255, 255, 0.85);
  box-shadow: 0 34px 80px -22px rgba(49, 46, 129, 0.55), inset 0 1.5px 1px rgba(255, 255, 255, 0.95), inset 0 -16px 36px rgba(129, 140, 248, 0.1);
}

/* Header */
.al-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 18px 22px 14px; }
.al-head-main { display: flex; align-items: center; gap: 12px; min-width: 0; }
.al-bell { display: inline-flex; align-items: center; justify-content: center; width: 42px; height: 42px; border-radius: 14px; flex: none; color: #fff; background: linear-gradient(145deg, #7aa2ff, #4f6df5); box-shadow: 0 10px 18px -8px rgba(63, 99, 240, 0.9); }
.al-title { font-size: 1.1rem; font-weight: 800; color: #0f172a; line-height: 1.2; }
.al-sub { margin-top: 1px; font-size: 0.75rem; color: #64748b; }
.al-close { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; flex: none; border-radius: 9999px; color: #475569; background: rgba(255, 255, 255, 0.6); border: 1px solid rgba(255, 255, 255, 0.95); box-shadow: inset 0 1px 1px #fff, 0 6px 12px -6px rgba(15, 23, 42, 0.25); -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px); transition: color 0.15s ease, transform 0.15s ease; }
.al-close:hover { color: #0f172a; transform: rotate(90deg); }

.al-body { flex: 1 1 auto; min-height: 0; padding: 0 22px 20px; max-height: 640px; overflow-y: auto; scrollbar-width: thin; }
.al-section { margin-bottom: 8px; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: #94a3b8; }

/* Today tiles */
.al-today { margin-bottom: 16px; }
.al-today-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
@media (min-width: 640px) { .al-today-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
.al-tile { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 18px; background: linear-gradient(145deg, rgba(255, 255, 255, 0.78), rgba(255, 255, 255, 0.4)); border: 1px solid rgba(255, 255, 255, 0.95); box-shadow: inset 0 1px 1px #fff, 0 10px 18px -12px rgba(49, 46, 129, 0.4); -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px); transition: transform 0.15s ease, box-shadow 0.15s ease; }
.al-tile:hover { transform: translateY(-2px); box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 14px 22px -12px color-mix(in srgb, var(--accent) 55%, transparent); }
.al-tile-icon { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; flex: none; border-radius: 11px; color: var(--accent); background: color-mix(in srgb, var(--accent) 13%, white); }
.al-tile-text { display: flex; flex-direction: column; min-width: 0; line-height: 1.15; }
.al-tile-label { font-size: 0.66rem; color: #64748b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.al-tile-count { font-size: 1.25rem; font-weight: 800; color: #0f172a; }

/* Toolbar */
.al-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; position: sticky; top: 0; z-index: 3; padding: 4px 0 8px; background: linear-gradient(180deg, rgba(238, 242, 255, 0.85) 70%, rgba(246, 249, 255, 0)); }
.al-seg { display: inline-flex; padding: 3px; border-radius: 14px; background: rgba(255, 255, 255, 0.45); border: 1px solid rgba(255, 255, 255, 0.85); box-shadow: inset 0 1px 1px #fff; }
.al-seg-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 9px; border: 0; background: transparent; font-size: 0.75rem; font-weight: 700; color: #64748b; cursor: pointer; transition: background 0.15s ease, color 0.15s ease, box-shadow 0.15s ease; }
.al-seg-btn:hover { color: #1e3a8a; }
.al-seg-on, .al-seg-on:hover { background: #fff; color: #1e3a8a; box-shadow: 0 4px 10px -4px rgba(15, 23, 42, 0.3); }
.al-seg-count { padding: 0 6px; border-radius: 9999px; font-size: 0.65rem; background: #dbe5ff; color: #3b52c4; }
.al-markall { display: inline-flex; align-items: center; gap: 6px; padding: 7px 13px; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; color: #4f46e5; background: #fff; border: 1px solid #dfe5f6; box-shadow: 0 3px 8px -4px rgba(15, 23, 42, 0.25); transition: background 0.15s ease; cursor: pointer; }
.al-markall:hover:not(:disabled) { background: #f3f5ff; }
.al-markall:disabled { opacity: 0.5; cursor: not-allowed; }

/* Bundles — each module is a raised card, spaced apart so the shadow reads as a partition */
.al-bundles { display: flex; flex-direction: column; gap: 16px; padding-bottom: 6px; }
.al-bundle { border-radius: 20px; background: #fff; border: 1px solid #edf1f9; overflow: hidden; box-shadow: 0 2px 4px rgba(15, 23, 42, 0.04), 0 18px 30px -18px rgba(15, 23, 42, 0.35); }
.al-bundle-head { display: flex; align-items: center; gap: 12px; padding: 12px 14px; cursor: pointer; background: linear-gradient(90deg, color-mix(in srgb, var(--accent) 10%, white), #fff 70%); border-bottom: 1px solid color-mix(in srgb, var(--accent) 14%, #edf1f9); transition: filter 0.15s ease; }
.al-bundle-head:hover { filter: brightness(0.985); }
.al-bundle-head:focus-visible { outline: 2px solid var(--accent); outline-offset: -2px; }
.al-mod-icon { display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; flex: none; border-radius: 12px; font-size: 1.15rem; background: #fff; box-shadow: 0 0 0 1.5px color-mix(in srgb, var(--accent) 35%, white), 0 8px 14px -8px color-mix(in srgb, var(--accent) 70%, transparent); }
.al-mod-icon { color: var(--accent); animation: al-bell-glow 2.8s ease-in-out infinite; }
.al-bell-svg { transform-origin: 50% 12%; animation: al-bell-sway 5s ease-in-out infinite; filter: drop-shadow(0 0 3px color-mix(in srgb, var(--accent) 55%, transparent)); }
@keyframes al-bell-glow {
  0%, 100% { box-shadow: 0 0 0 1.5px color-mix(in srgb, var(--accent) 35%, white), 0 0 6px 0 color-mix(in srgb, var(--accent) 35%, transparent); }
  50% { box-shadow: 0 0 0 1.5px color-mix(in srgb, var(--accent) 60%, white), 0 0 16px 3px color-mix(in srgb, var(--accent) 45%, transparent); }
}
@keyframes al-bell-sway {
  0%, 82%, 100% { transform: rotate(0); }
  86% { transform: rotate(14deg); }
  90% { transform: rotate(-12deg); }
  94% { transform: rotate(8deg); }
  97% { transform: rotate(-4deg); }
}
@media (prefers-reduced-motion: reduce) { .al-mod-icon, .al-bell-svg { animation: none; } }
.al-mod-icon-sm { width: 34px; height: 34px; font-size: 1rem; border-radius: 11px; }
.al-bundle-meta { min-width: 0; flex: 1; }
.al-bundle-title { font-size: 0.92rem; font-weight: 800; color: #0f172a; line-height: 1.2; }
.al-bundle-sub { font-size: 0.7rem; color: #64748b; }
.al-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 24px; height: 24px; padding: 0 8px; border-radius: 9999px; font-size: 0.72rem; font-weight: 800; color: #fff; background: var(--accent); box-shadow: 0 6px 10px -5px var(--accent); }
.al-mark { display: inline-flex; align-items: center; gap: 4px; padding: 5px 10px; border-radius: 9999px; font-size: 0.68rem; font-weight: 700; color: var(--accent); background: #fff; border: 1px solid color-mix(in srgb, var(--accent) 30%, white); cursor: pointer; transition: background 0.15s ease; }
.al-mark:hover:not(:disabled) { background: color-mix(in srgb, var(--accent) 10%, white); }
.al-mark:disabled { opacity: 0.5; cursor: not-allowed; }
.al-chev { color: #94a3b8; transition: transform 0.2s ease; transform: rotate(-90deg); flex: none; }
.al-chev-open { transform: rotate(0deg); }

/* Notifications inside a bundle — separated by a soft shadow line */
.al-list { list-style: none; margin: 0; padding: 0; }
.al-list > li + li { position: relative; box-shadow: 0 -6px 8px -8px rgba(15, 23, 42, 0.28); border-top: 1px solid #f1f4fa; }
.al-note { display: flex; align-items: flex-start; gap: 10px; width: 100%; padding: 11px 14px; text-align: left; background: transparent; border: 0; cursor: pointer; transition: background 0.15s ease; }
.al-note:hover { background: #f7f9ff; }
.al-dot { width: 9px; height: 9px; margin-top: 5px; flex: none; border-radius: 9999px; background: transparent; }
.al-dot-on { background: #ef4444; box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.18); }
.al-note-main { display: flex; flex-direction: column; min-width: 0; flex: 1; gap: 1px; }
.al-note-title { font-size: 0.82rem; font-weight: 700; color: #0f172a; }
.al-note-msg { font-size: 0.78rem; color: #475569; line-height: 1.4; overflow-wrap: anywhere; }
.al-note-time { flex: none; font-size: 0.68rem; font-weight: 600; color: #94a3b8; white-space: nowrap; }
.al-more { display: block; width: 100%; padding: 8px; font-size: 0.72rem; font-weight: 700; color: var(--accent); background: #fafbff; border: 0; border-top: 1px solid #f1f4fa; box-shadow: 0 -6px 8px -8px rgba(15, 23, 42, 0.28); cursor: pointer; transition: background 0.15s ease; }
.al-more:hover { background: color-mix(in srgb, var(--accent) 8%, white); }

/* Flat list — every alert is its own raised tile */
.al-flat { list-style: none; margin: 0; padding: 0 0 6px; display: flex; flex-direction: column; gap: 12px; }
.al-flat-note { display: flex; align-items: flex-start; gap: 12px; width: 100%; padding: 12px 14px; text-align: left; border-radius: 16px; background: #fff; border: 1px solid #edf1f9; cursor: pointer; box-shadow: 0 2px 4px rgba(15, 23, 42, 0.04), 0 12px 22px -14px rgba(15, 23, 42, 0.35); transition: transform 0.15s ease, box-shadow 0.15s ease; }
.al-flat-note:hover { transform: translateY(-1px); box-shadow: 0 2px 4px rgba(15, 23, 42, 0.04), 0 16px 26px -14px color-mix(in srgb, var(--accent) 60%, transparent); }
.al-flat-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 2px; }
.al-chip { padding: 1px 9px; border-radius: 9999px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.03em; color: var(--accent); background: color-mix(in srgb, var(--accent) 12%, white); }

/* Empty / caught up */
.al-empty { display: flex; flex-direction: column; align-items: center; text-align: center; padding: 36px 0; color: #64748b; font-size: 0.85rem; }
.al-empty-icon { display: inline-flex; align-items: center; justify-content: center; width: 54px; height: 54px; margin-bottom: 10px; border-radius: 18px; color: #94a3b8; background: #f1f5f9; }
.al-caught { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 14px; border-radius: 14px; font-size: 0.8rem; font-weight: 600; color: #047857; background: rgba(5, 150, 105, 0.08); }

@media (prefers-reduced-motion: reduce) { .al-tile, .al-flat-note, .al-chev, .al-close { transition: none; } }
</style>

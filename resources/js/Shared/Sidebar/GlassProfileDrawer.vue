<template>
  <teleport to="body">
    <transition name="gp">
      <div v-if="open" ref="panel" class="gp" :style="pos" role="menu" :aria-label="$t('Profile')" @keydown.esc.stop="$emit('close')">
        <div class="gp-head">
          <span class="gp-av"><img v-if="me.profile_photo_url" :src="me.profile_photo_url" alt="" draggable="false" /><i class="gp-online" aria-hidden="true"></i></span>
          <div class="min-w-0">
            <div class="gp-name">{{ me.name }}</div>
            <div class="gp-mail">{{ me.email }}</div>
            <div class="gp-tags"><span class="gp-role">{{ roleLabel }}</span><span class="gp-live"><i></i>{{ $t('Online') }}</span></div>
          </div>
        </div>

        <div class="gp-list">
          <div class="gp-style">
            <div class="gp-style-t"><span class="gp-chip gp-violet"><Icons name="collection" class-name="gp-ic" /></span><span class="min-w-0"><b>{{ $t('Themes') }}</b><small>{{ $t('Classic, Pro or Glass') }}</small></span></div>
            <theme-switch :model-value="style" dark @pick="setStyle" />
          </div>

          <Link :href="route('profile.show')" class="gp-item" role="menuitem" @click="$emit('close')">
            <span class="gp-chip gp-blue"><Icons name="user" class-name="gp-ic" /></span>
            <span class="min-w-0"><b>{{ $t('Profile') }}</b><small>{{ $t('Account, photo and security') }}</small></span>
          </Link>
          <Link v-if="$can('read-task-reminders') && flag('task_reminders')" :href="route('task-reminders.index')" class="gp-item" role="menuitem" @click="$emit('close')">
            <span class="gp-chip gp-amber"><Icons name="clock" class-name="gp-ic" /></span>
            <span class="min-w-0"><b>{{ $t('My Tasks') }}</b><small>{{ $t('Reminders assigned to you') }}</small></span>
          </Link>
          <Link v-if="$can(['read-case-tracking', 'read-all-case-tracking']) && flag('case_tracking')" :href="route('case-tracking.mine')" class="gp-item" role="menuitem" @click="$emit('close')">
            <span class="gp-chip gp-crimson"><Icons name="exclamation" class-name="gp-ic" /></span>
            <span class="min-w-0"><b>{{ $t('Track Cases') }}</b><small>{{ $t('Cases you follow') }}</small></span>
          </Link>
          <Link v-if="$page.props.licenceManager" :href="route('licence-manager.index')" class="gp-item" role="menuitem" @click="$emit('close')">
            <span class="gp-chip gp-crimson"><Icons name="lock-closed" class-name="gp-ic" /></span>
            <span class="min-w-0"><b>{{ $t('Licence Manager') }}</b><small>{{ $t('Issue and control licences') }}</small></span>
          </Link>
          <Link v-if="($can('read-wims-licence') || $page.props.licenceManager) && $page.props.licenceViewAllowed !== false" :href="route('wims-licence.show')" class="gp-item" role="menuitem" @click="$emit('close')">
            <span class="gp-chip gp-emerald"><Icons name="check-double" class-name="gp-ic" /></span>
            <span class="min-w-0"><b>{{ $t('WIMS Licence') }}</b><small>{{ $page.props.licenceStatus?.label || $t('Licence status and key') }}</small></span>
          </Link>
          <Link v-if="$can('read-system-health') && flag('system_health')" :href="route('system-health.index')" class="gp-item" role="menuitem" @click="$emit('close')">
            <span class="gp-chip gp-teal"><Icons name="chart-pie" class-name="gp-ic" /></span>
            <span class="min-w-0"><b>{{ $t('System Health') }}</b><small>{{ $t('Signal, speed, space and more') }}</small></span>
          </Link>
          <a v-if="kbLive" href="/wimshow" target="_blank" rel="noopener" class="gp-item" role="menuitem" @click="$emit('close')">
            <span class="gp-chip gp-teal"><Icons name="doc-text" class-name="gp-ic" /></span>
            <span class="min-w-0"><b>{{ $t('Knowledge Base') }}</b><small>{{ $t('Guides for every function') }}</small></span>
          </a>
          <Link v-if="$page.props.hasMyRansId" :href="route('id-passes.my-id')" class="gp-item" role="menuitem" @click="$emit('close')">
            <span class="gp-chip gp-blue"><Icons name="user" class-name="gp-ic" /></span>
            <span class="min-w-0"><b>{{ $t('My ID') }}</b><small>{{ $t('See your RANS Staff ID card') }}</small></span>
          </Link>
          <button type="button" class="gp-item" role="menuitem" @click="installPwa">
            <span class="gp-chip" :class="installed ? 'gp-emerald' : 'gp-blue'"><Icons :name="installed ? 'tick' : 'download'" class-name="gp-ic" /></span>
            <span class="min-w-0"><b>{{ $t('Install WIMS App') }}</b><small>{{ installSub }}</small></span>
          </button>
        </div>

        <form class="gp-foot" @submit.prevent="logout">
          <button type="submit" class="gp-item gp-logout" role="menuitem">
            <span class="gp-chip gp-rose"><Icons name="out" class-name="gp-ic" /></span>
            <span class="min-w-0"><b>{{ $t('Log Out') }}</b><small>{{ $t('End this session') }}</small></span>
          </button>
        </form>
      </div>
    </transition>
    <pwa-install-dialog v-if="pwaDialog" :kind="pwaDialog.kind" :guide="pwaDialog.guide" @close="pwaDialog = null" />
  </teleport>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import { applyMenuStyle, menuStyleOf } from '@/Core/menuStyle';
import { pwa, installApp, installGuide } from '@/Core/pwaInstall';
import { clearAllStores } from '@/Core/offlineDb';
import { defineAsyncComponent } from 'vue';
const PwaInstallDialog = defineAsyncComponent(() => import('@/Shared/PwaInstallDialog.vue'));
import ThemeSwitch from '@/Shared/ThemeSwitch.vue';

// The profile drawer of the Glass menu: the same entries as the profile menu at the top right, opened beside the menu instead of under the bar.
export default {
  components: { Link, PwaInstallDialog, ThemeSwitch },
  props: { open: Boolean, anchor: { type: Object, default: null }, compact: Boolean },
  emits: ['close'],
  data() { return { pos: {}, pwa, pwaDialog: null }; },
  computed: {
    me() { return this.$page.props.auth?.user || {}; },
    roleLabel() { const r = this.me.roles || []; return r.length ? r.map(x => x.name).filter(Boolean).join(', ') : ''; },
    kbLive() { return ['1', 1, true, 'true'].includes(this.$page.props.settings?.knowledge_base_live); },
    style() { return menuStyleOf(this.$page, this.$can); },
    styles() { return [{ key: 'classic', label: this.$t('Classic') }, { key: 'pro', label: this.$t('Pro') }, { key: 'glass', label: this.$t('Glass') }]; },
    installed() { return pwa.standalone || (pwa.installed && !pwa.canPrompt); },
    installSub() {
      if (pwa.standalone) return this.$t('You are using the installed app');
      if (pwa.installed && !pwa.canPrompt) return this.$t('Already installed on this device');
      return this.$t('Add WIMS to your home screen');
    },
  },
  watch: {
    open(v) { if (v) { this.place(); this.$nextTick(this.place); } },
    // the menu under it changed (another theme): sit beside the new menu, not where the old one was
    anchor() { this.place(); this.$nextTick(this.place); },
    '$page.url'() { this.$emit('close'); },
  },
  mounted() {
    document.addEventListener('pointerdown', this.onOutside, true);
    document.addEventListener('keydown', this.onKey);
    window.addEventListener('resize', this.place);
  },
  beforeUnmount() {
    document.removeEventListener('pointerdown', this.onOutside, true);
    document.removeEventListener('keydown', this.onKey);
    window.removeEventListener('resize', this.place);
  },
  methods: {
    flag(k) { return this.$page.props.enabledFeatures?.[k] !== false || this.$super; },
    // beside the menu, its bottom edge level with the profile card (and never off the screen)
    place() {
      if (!this.open) return;
      const a = this.anchor && this.anchor.getBoundingClientRect ? this.anchor.getBoundingClientRect() : null;
      const menu = this.anchor && this.anchor.closest ? this.anchor.closest('.gm') : null;
      const m = menu ? menu.getBoundingClientRect() : a;
      if (!m) return;
      const phone = window.innerWidth < 640;
      const w = Math.min(340, window.innerWidth - 24);
      const left = phone ? 12 : Math.min(m.right + 14, window.innerWidth - w - 12);
      this.pos = { left: left + 'px', bottom: Math.max(12, window.innerHeight - (a ? a.bottom : m.bottom) - 6) + 'px', width: w + 'px', maxHeight: 'calc(100vh - 24px)' };
    },
    onOutside(e) {
      if (!this.open) return;
      const inside = (this.$refs.panel && this.$refs.panel.contains(e.target)) || (this.anchor && this.anchor.contains && this.anchor.contains(e.target));
      if (!inside) this.$emit('close');
    },
    onKey(e) { if (e.key === 'Escape' && this.open) this.$emit('close'); },
    // the drawer stays open while the theme changes (it belongs to the dock, not to one menu)
    setStyle(s) { if (s !== this.style) applyMenuStyle(this.$page, s); },
    async installPwa() {
      const done = await installApp();
      this.$emit('close');
      if (done === 'dismissed') return;
      this.pwaDialog = { kind: done, guide: done === 'manual' ? installGuide() : null };
    },
    logout() {
      clearAllStores();
      this.$inertia.post(route('logout'));
    },
  },
};
</script>

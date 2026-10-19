<template>
  <section class="lg-card">
    <header class="lg-card-head">
      <span class="lg-card-ico lg-ico-sky"><icons name="sparkles" class="w-5 h-5" /></span>
      <div class="min-w-0">
        <h2 class="lg-card-title">{{ $t('Quick Access Menu') }}</h2>
        <p class="lg-card-sub">
          {{ $t('The floating shortcut button that follows you around WIMS, showing the 5 sections you use most.') }}
        </p>
      </div>
    </header>

    <div class="pf-toggle-row">
      <label class="wims-toggle">
        <input type="checkbox" v-model="menuEnabled" @change="saveEnabled" />
        <span class="wims-toggle-track"><span class="wims-toggle-thumb"></span></span>
        <span class="wims-toggle-label">{{ $t('Show the floating quick-access menu') }}</span>
      </label>
      <span v-if="saved" class="lg-chip lg-chip-green"><icons name="tick" class="w-3 h-3" />{{ $t('Saved.') }}</span>
      <span v-else class="lg-chip" :class="menuEnabled ? 'lg-chip-green' : 'lg-chip-slate'">{{ menuEnabled ? $t('On') : $t('Off') }}</span>
    </div>

    <div class="pf-foot" style="justify-content: space-between">
      <span v-if="positionReset" class="text-sm font-semibold text-emerald-600">{{ $t('Done.') }}</span>
      <span v-else></span>
      <button type="button" @click="resetPosition" class="lg-btn lg-btn-soft">
        <icons name="refresh" class="w-3.5 h-3.5" />
        {{ $t('Reset position to default') }}
      </button>
    </div>
  </section>
</template>

<script>

const POSITION_KEY = 'wims-quick-menu-position';

export default {
  data() {
    return {
      menuEnabled: this.$page.props.auth.user.quick_menu_enabled !== false,
      saved: false,
      positionReset: false,
    };
  },

  methods: {
    saveEnabled() {
      this.saved = false;
      window.axios
        .put(this.route('quick-menu.preferences.update'), { quick_menu_enabled: this.menuEnabled })
        .then(res => {
          this.menuEnabled = res.data.quick_menu_enabled;
          // $page.props is the same reactive object every component on the
          // page reads from (it's how Inertia propagates props across SPA
          // navigations) — writing to it directly here means the floating
          // QuickAccessMenu's `enabled` computed picks up the change on the
          // spot, without waiting for a full page visit to re-share props.
          this.$page.props.auth.user.quick_menu_enabled = res.data.quick_menu_enabled;
          this.saved = true;
          setTimeout(() => {
            this.saved = false;
          }, 2000);
        });
    },
    resetPosition() {
      window.localStorage.removeItem(POSITION_KEY);
      window.dispatchEvent(new CustomEvent('wims-quick-menu-reset-position'));
      this.positionReset = true;
      setTimeout(() => {
        this.positionReset = false;
      }, 2000);
    },
  },
};
</script>

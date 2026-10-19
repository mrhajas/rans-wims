<template>
  <section class="lg-card">
    <header class="lg-card-head">
      <span class="lg-card-ico lg-ico-indigo"><icons name="zoom-in" class="w-5 h-5" /></span>
      <div class="min-w-0">
        <h2 class="lg-card-title">{{ $t('Scan Barcode Shortcut') }}</h2>
        <p class="lg-card-sub">
          {{ $t('On Checkin, Checkout, Transfer, and Adjustment forms, this keyboard shortcut opens the camera barcode scanner without needing to click the camera icon. Choose the combination that works for your account.') }}
        </p>
      </div>
    </header>

    <form @submit.prevent="save">
      <div class="pf-box">
        <tec-label :value="$t('Current Shortcut')" />
        <div class="sc-row">
          <span class="sc-keys" :class="{ 'sc-recording': recording }">
            <template v-for="(k, ki) in keys" :key="ki"><span v-if="ki > 0" class="sc-plus">+</span><kbd class="pf-kbd">{{ k }}</kbd></template>
          </span>
          <button type="button" @click="startRecording" class="lg-btn lg-btn-soft" :disabled="recording">
            <icons name="edit" class="w-3.5 h-3.5" />
            {{ recording ? $t('Press a key combination…') : $t('Record New Shortcut') }}
          </button>
          <button type="button" @click="resetToDefault" class="lg-btn lg-btn-soft">
            <icons name="refresh" class="w-3.5 h-3.5" />
            {{ $t('Reset to Default') }}
          </button>
        </div>
        <p class="pf-hint">
          {{ $t('Default: Ctrl + Alt + S. This only changes the shortcut for your own account.') }}
        </p>
        <p v-if="recording" class="sc-listen">
          {{ $t('Listening. Press the key combination you want, or Escape to cancel.') }}
        </p>
      </div>

      <div class="pf-foot">
        <tec-action-message :on="saved" class="text-sm font-semibold text-emerald-600">{{ $t('Saved.') }}</tec-action-message>
        <button type="submit" class="lg-btn lg-btn-primary" :disabled="saving || recording">
          <icons :name="saving ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': saving }" />{{ $t('Save') }}
        </button>
      </div>
    </form>
  </section>
</template>

<script>
import TecLabel from '@/Jetstream/Label.vue';
import TecActionMessage from '@/Jetstream/ActionMessage.vue';
import { DEFAULT_SCAN_SHORTCUT, shortcutFromEvent, formatShortcut } from '@/Shared/Rauncher/shortcut';

export default {
  components: { TecLabel, TecActionMessage },

  data() {
    return {
      shortcut: { ...(this.$page.props.auth.user.extra_attributes?.scan_shortcut || DEFAULT_SCAN_SHORTCUT) },
      recording: false,
      saving: false,
      saved: false,
    };
  },

  computed: {
    keys() {
      return formatShortcut(this.shortcut).split(' + ');
    },
  },

  methods: {
    formatShortcut,

    startRecording() {
      this.recording = true;
      this.saved = false;
      window.addEventListener('keydown', this.onRecordKeydown, true);
    },

    stopRecording() {
      this.recording = false;
      window.removeEventListener('keydown', this.onRecordKeydown, true);
    },

    onRecordKeydown(e) {
      e.preventDefault();
      e.stopPropagation();

      if (e.key === 'Escape') {
        this.stopRecording();
        return;
      }

      if (['Control', 'Meta', 'Alt', 'Shift'].includes(e.key)) {
        return;
      }

      if (!e.ctrlKey && !e.metaKey && !e.altKey && !e.shiftKey) {
        // require at least one modifier so the shortcut can't swallow normal typing
        return;
      }

      this.shortcut = shortcutFromEvent(e);
      this.stopRecording();
    },

    resetToDefault() {
      this.shortcut = { ...DEFAULT_SCAN_SHORTCUT };
    },

    save() {
      this.saving = true;
      this.saved = false;
      window.axios
        .put(this.route('scan.shortcut.update'), this.shortcut)
        .then(res => {
          this.shortcut = res.data.shortcut;
          this.saved = true;
        })
        .finally(() => {
          this.saving = false;
        });
    },
  },

  beforeUnmount() {
    window.removeEventListener('keydown', this.onRecordKeydown, true);
  },
};
</script>

<style scoped>
.sc-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 6px; }
.sc-keys { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 12px; background: var(--lg-card); border: 1px solid var(--lg-line); }
.sc-plus { font-size: 0.75rem; font-weight: 800; color: var(--lg-faint); }
.sc-recording { border-color: #818cf8; box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.2); }
.sc-listen { margin-top: 8px; font-size: 0.78rem; font-weight: 700; color: #4f46e5; }
html.dark .sc-listen { color: #a5b4fc; }
</style>

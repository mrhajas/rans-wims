<template>
  <div class="wc-modal" role="dialog" aria-modal="true" :aria-label="$t('Chat settings')" @keydown.esc.stop="$emit('close')">
    <div class="wc-modal-back" @click="$emit('close')"></div>
    <div class="wc-modal-card">
      <header><b>{{ $t('Chat settings') }}</b><button type="button" class="wc-ib" :aria-label="$t('Close')" @click="$emit('close')"><icons name="cross" class="w-5 h-5" /></button></header>

      <div class="wc-prefs">
        <label v-if="chat.config.allow_manual_status && chat.features.presence" class="wc-field">{{ $t('My status') }}
          <css-select v-model="status" @change="saveStatus">
            <option value="auto">{{ $t('Automatic (follows your activity)') }}</option>
            <option v-for="s in chat.config.statuses" :key="s.key" :value="s.key">{{ s.label }}</option>
          </css-select>
        </label>

        <h4>{{ $t('Chat colour') }}</h4>
        <div class="wc-themes" role="radiogroup" :aria-label="$t('Chat colour')">
          <button v-for="t in chat.config.themes" :key="t" type="button" role="radio" :aria-checked="p.theme === t" class="wc-theme-dot" :class="['t-' + t, { 'is-on': p.theme === t }]" :aria-label="t" @click="pickTheme(t)"><icons v-if="p.theme === t" name="tick" class="w-4 h-4" /></button>
        </div>

        <h4>{{ $t('Alerts') }}</h4>
        <label class="wc-chk"><input v-model="p.sound" type="checkbox" class="sdy-cb" @change="save" />{{ $t('Play a sound for new messages') }}</label>
        <label class="wc-chk"><input :checked="p.desktop" type="checkbox" class="sdy-cb" @change="toggleDesktop($event)" />{{ $t('Desktop notifications while WIMS is in the background') }}</label>
        <label class="wc-chk"><input v-model="p.preview" type="checkbox" class="sdy-cb" @change="save" />{{ $t('Show message text in alerts') }}</label>

        <h4>{{ $t('Typing') }}</h4>
        <label class="wc-chk"><input v-model="p.enter_to_send" type="checkbox" class="sdy-cb" @change="save" />{{ $t('Enter sends the message (Shift+Enter for a new line)') }}</label>
        <label class="wc-field">{{ $t('Message density') }}
          <css-select v-model="p.density" @change="save"><option value="comfortable">{{ $t('Comfortable') }}</option><option value="compact">{{ $t('Compact') }}</option></css-select>
        </label>

        <h4>{{ $t('Privacy') }}</h4>
        <label v-if="chat.config.read_receipts" class="wc-chk"><input v-model="p.read_receipts" type="checkbox" class="sdy-cb" @change="save" />{{ $t('Let people see when I have read their messages') }}</label>
        <label v-if="chat.features.presence" class="wc-chk"><input v-model="p.show_online" type="checkbox" class="sdy-cb" @change="save" />{{ $t('Show when I am online') }}</label>
        <label v-if="chat.features.presence && chat.config.show_last_seen" class="wc-chk"><input v-model="p.show_last_seen" type="checkbox" class="sdy-cb" @change="save" />{{ $t('Show my “last seen”') }}</label>
        <label v-if="chat.features.typing" class="wc-chk"><input v-model="p.typing_visible" type="checkbox" class="sdy-cb" @change="save" />{{ $t('Let people see when I am typing') }}</label>
      </div>
      <p v-if="err" class="wc-err" role="alert"><icons name="exclamation" class="w-4 h-4" />{{ err }}</p>
    </div>
  </div>
</template>

<script>
import { chat, savePrefs, errorText } from '@/Core/wimschat';

export default {
  emits: ['close'],
  data() {
    return { chat, p: { ...chat.me.prefs }, status: chat.me.status, err: '' };
  },
  methods: {
    async save() {
      this.err = '';
      try { await savePrefs({ prefs: this.p }); } catch (e) { this.err = errorText(e); }
    },
    pickTheme(t) {
      this.p.theme = t;
      this.save();
    },
    async saveStatus() {
      this.err = '';
      try { await savePrefs({ status: this.status }); } catch (e) { this.err = errorText(e); }
    },
    async toggleDesktop(e) {
      if (e.target.checked && 'Notification' in window && Notification.permission !== 'granted') {
        const r = await Notification.requestPermission();
        if (r !== 'granted') { e.target.checked = false; this.err = this.$t('Your browser blocked notifications. Allow them in the site settings to use this.'); return; }
      }
      this.p.desktop = e.target.checked;
      this.save();
    },
  },
};
</script>

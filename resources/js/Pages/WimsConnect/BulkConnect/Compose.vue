<template>
  <admin-layout :title="$t('WIMS Connect / Bulk Connect')">
    <div class="lg-page px-4 md:px-0 bsm-shell">
      <page-header icon="send" :title="$t('Bulk Connect')" :subtitle="$t('Compose one message, send it over WhatsApp, Meta, or SMS to any number of recipients, or schedule it for later.')">
        <Link :href="route('wims-connect.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-3.5 h-3.5 transform rotate-90" />
          {{ $t('WIMS Connect') }}
        </Link>
        <Link :href="route('wims-connect.bulk-connect.history.index')" class="lg-btn lg-btn-primary">
          <icons name="list" class="w-3.5 h-3.5" />
          {{ $t('View History') }}
        </Link>
      </page-header>

      <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
        <div class="lg:col-span-3 space-y-4">
          <!-- Channel -->
          <div class="bsm-panel">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-pink"><icons name="send" class="w-5 h-5" /></span>
              <h2 class="lg-card-title">{{ $t('Channel') }}</h2>
            </header>
            <div class="flex flex-wrap items-center gap-2">
              <button
                v-for="ch in channelDefs"
                :key="ch.key"
                type="button"
                class="bsm-channel-pill"
                :class="{ 'bsm-channel-pill-active': channel === ch.key, 'bsm-channel-pill-disabled': !ch.live, 'lg-tip tip-left': !ch.live }"
                :disabled="!ch.live"
                :data-tip="ch.live ? null : $t('This channel is not currently live. Enable it under WIMS Connect first.')"
                @click="channel = ch.key"
              >
                <icons :name="ch.key" class="w-4 h-4" />
                {{ ch.label }}
                <span v-if="!ch.live" class="bsm-channel-off-dot"></span>
              </button>
            </div>
          </div>

          <!-- Recipients -->
          <div class="bsm-panel">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-indigo"><icons name="users" class="w-5 h-5" /></span>
              <h2 class="lg-card-title flex-1">{{ $t('Recipients') }}</h2>
              <span class="bsm-count-badge">{{ recipients.length }}</span>
            </header>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-2">
              <div class="relative">
                <label class="bsm-field-label">{{ $t('Name') }} <span class="bsm-required-mark">*</span></label>
                <input
                  v-model="newRecipient.name"
                  type="text"
                  :placeholder="$t('Search or type a name')"
                  class="bsm-input w-full"
                  @input="onSuggestInput('name')"
                  @focus="activeField = 'name'"
                  @blur="hideSuggestionsSoon"
                  @keydown.enter.prevent="addRecipient"
                />
                <ul v-if="activeField === 'name' && suggestions.length" class="bsm-suggest-list">
                  <li v-for="(s, i) in suggestions" :key="i" class="bsm-suggest-item" @mousedown.prevent="pickSuggestion(s)">
                    <span class="font-semibold text-gray-800">{{ s.name || $t('(no name)') }}</span>
                    <span class="text-gray-400 ml-1">{{ s.phone }}</span>
                  </li>
                </ul>
              </div>
              <div class="relative">
                <label class="bsm-field-label">{{ $t('Mobile Number') }} <span class="bsm-required-mark">*</span></label>
                <input
                  v-model="newRecipient.phone"
                  type="text"
                  :placeholder="$t('e.g. 0674020223 or +255674020223')"
                  class="bsm-input w-full"
                  :class="{ 'bsm-input-error': phoneError }"
                  @input="onSuggestInput('phone')"
                  @focus="activeField = 'phone'"
                  @blur="onPhoneBlur"
                  @keydown.enter.prevent="addRecipient"
                />
                <ul v-if="activeField === 'phone' && suggestions.length" class="bsm-suggest-list">
                  <li v-for="(s, i) in suggestions" :key="i" class="bsm-suggest-item" @mousedown.prevent="pickSuggestion(s)">
                    <span class="font-semibold text-gray-800">{{ s.name || $t('(no name)') }}</span>
                    <span class="text-gray-400 ml-1">{{ s.phone }}</span>
                  </li>
                </ul>
              </div>
            </div>

            <transition name="bsm-fade">
              <p v-if="phoneError" class="bsm-inline-notice bsm-inline-notice-error mb-2">
                <icons name="exclamation" class="w-3.5 h-3.5 flex-none" />
                {{ phoneError }}
              </p>
              <p v-else-if="phoneNotice" class="bsm-inline-notice mb-2">
                <icons name="exclamation" class="w-3.5 h-3.5 flex-none" />
                {{ phoneNotice }}
              </p>
            </transition>

            <div class="flex flex-wrap items-center gap-2 mb-1">
              <button type="button" class="lg-btn lg-btn-green" :disabled="!newRecipient.name.trim() || !newRecipient.phone.trim() || !!phoneError" @click="addRecipient">
                <icons name="plus" class="w-3.5 h-3.5" />
                {{ $t('Add') }}
              </button>
              <button type="button" class="lg-btn lg-btn-soft" @click="openImportModal">
                <icons name="download" class="w-3.5 h-3.5" />
                {{ $t('Import Contacts') }}
              </button>
              <button type="button" class="lg-btn lg-btn-soft" @click="openImportUsersModal">
                <icons name="users" class="w-3.5 h-3.5" />
                {{ $t('Import Users') }}
              </button>
              <button type="button" class="lg-btn lg-btn-soft" :disabled="excelUploading" @click="$refs.excelInput.click()">
                <icons name="doc-text" class="w-3.5 h-3.5" />
                {{ excelUploading ? $t('Importing…') : $t('Import from Excel') }}
              </button>
              <input ref="excelInput" type="file" accept=".xls,.xlsx,application/vnd.ms-excel" class="hidden" @change="onExcelSelected" />
            </div>
            <a :href="excelTemplateUrl" class="bsm-template-link">
              <icons name="download" class="w-3 h-3" />
              {{ $t('Download Excel format (name, phone columns)') }}
            </a>

            <transition name="bsm-fade">
              <p v-if="excelNotice" class="bsm-inline-notice mb-2" :class="{ 'bsm-inline-notice-error': excelNoticeError }">
                <icons name="exclamation" class="w-3.5 h-3.5 flex-none" />
                {{ excelNotice }}
              </p>
            </transition>

            <transition-group name="bsm-chip" tag="div" class="flex flex-wrap gap-2">
              <span v-for="(r, i) in recipients" :key="r.phone + i" class="bsm-chip">
                <span class="bsm-chip-avatar">{{ chipInitials(r.name) }}</span>
                <span class="min-w-0">
                  <span class="block text-xs font-semibold text-gray-800 truncate">{{ r.name || $t('(no name)') }}</span>
                  <span class="block text-[0.65rem] text-gray-400 font-mono">{{ r.phone }}</span>
                </span>
                <button type="button" class="bsm-chip-remove" :aria-label="$t('Remove')" @click="removeRecipient(i)">
                  <icons name="cross" class="w-3 h-3" />
                </button>
              </span>
            </transition-group>
            <p v-if="!recipients.length" class="text-xs text-gray-400">{{ $t('No recipients added yet.') }}</p>
          </div>

          <!-- Message -->
          <div class="bsm-panel">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-sky"><icons name="doc-text" class="w-5 h-5" /></span>
              <h2 class="lg-card-title flex-1">{{ $t('Message') }}</h2>
              <span class="bsm-max-chars-label">{{ $t('Maximum Characters') }}: {{ maxLength }}</span>
            </header>
            <div class="relative">
              <textarea
                ref="messageArea"
                v-model="body"
                rows="5"
                class="bsm-input w-full pr-10"
                :class="{ 'bsm-input-error': bodyError }"
                :placeholder="$t('Type your message…')"
              ></textarea>
              <div class="absolute right-2 top-2">
                <button type="button" class="bsm-emoji-btn" :aria-label="$t('Emoji')" @click="showEmoji = !showEmoji">🙂</button>
                <div v-if="showEmoji" class="bsm-emoji-panel">
                  <button v-for="e in emojiList" :key="e" type="button" class="bsm-emoji-item" @click="insertEmoji(e)">{{ e }}</button>
                </div>
              </div>
            </div>
            <div class="flex items-center justify-between mt-1">
              <span class="text-xs" :class="bodyError ? 'text-rose-600 font-semibold' : 'text-gray-400'">{{ body.length }} / {{ maxLength }}</span>
            </div>
            <p v-if="bodyError" class="bsm-inline-notice bsm-inline-notice-error mt-2">
              <icons name="exclamation" class="w-3.5 h-3.5 flex-none" />
              {{ bodyError }}
            </p>
            <p v-else-if="channel === 'sms' && body.length > 0" class="bsm-note mt-2">
              {{ $t('SMS segments are 160 characters each: this message will use') }} {{ smsSegmentCount }} {{ $t('segment(s).') }}
            </p>
          </div>

          <!-- Schedule -->
          <div class="bsm-panel">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-amber"><icons name="clock" class="w-5 h-5" /></span>
              <h2 class="lg-card-title">{{ $t('When to send') }}</h2>
            </header>
            <div class="flex items-center gap-3 mb-3">
              <button type="button" class="bsm-pill" :class="{ 'bsm-pill-active': !scheduleOn }" @click="scheduleOn = false">{{ $t('Send now') }}</button>
              <button type="button" class="bsm-pill" :class="{ 'bsm-pill-active': scheduleOn }" @click="scheduleOn = true">{{ $t('Schedule for later') }}</button>
            </div>
            <input v-if="scheduleOn" v-model="scheduledAt" type="datetime-local" class="bsm-input w-full sm:w-64" />
          </div>
        </div>

        <!-- Send panel -->
        <div class="lg:col-span-2">
          <div class="bsm-panel bsm-send-panel">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span>
              <h2 class="lg-card-title">{{ $t('Ready to send') }}</h2>
            </header>
            <div class="space-y-2 mb-4 text-xs ca-mute">
              <p class="flex items-center gap-1.5"><icons :name="channel" class="w-3.5 h-3.5" /> <strong class="ca-ink capitalize">{{ channel }}</strong></p>
              <p><strong class="ca-ink">{{ recipients.length }}</strong> {{ $t('recipient(s)') }}</p>
              <p><strong class="ca-ink">{{ body.length }}</strong> {{ $t('character(s)') }}</p>
              <p v-if="scheduleOn && scheduledAt">{{ $t('Scheduled for') }} <strong class="ca-ink">{{ scheduledAt }}</strong></p>
            </div>
            <p v-if="recipients.length > 20 && !scheduleOn" class="bsm-note mb-3">
              {{ $t('Sending to a large number of recipients happens in one request and may take a moment. Please wait for it to finish.') }}
            </p>
            <button type="button" class="lg-btn lg-btn-primary bsm-send-btn w-full" :class="{ 'bsm-send-btn-pulsing': sending }" :disabled="!canSend || sending" @click="send">
              <icons name="send" class="w-4 h-4" :class="{ 'animate-bounce': sending }" />
              {{ sending ? $t('Sending…') : (scheduleOn ? $t('Schedule Message') : $t('Send Now')) }}
            </button>

            <div v-if="result" class="bsm-result mt-4" :class="result.failed ? 'bsm-result-warn' : 'bsm-result-ok'">
              <icons :name="result.failed ? 'exclamation' : 'tick'" class="w-4 h-4 flex-none" />
              <span>{{ result.text }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <tec-dialog-modal :show="showImport" @close="showImport = false" max-width="lg">
      <template #title>
        <div class="bsm-modal-head">
          <div class="lg-card-ico lg-ico-indigo">
            <icons name="users" class="w-5 h-5" />
          </div>
          <div class="min-w-0">
            <div class="lg-card-title">{{ $t('Import from Contacts') }}</div>
            <div class="lg-card-sub">{{ $t('Pick saved contacts to add as recipients.') }}</div>
          </div>
        </div>
      </template>
      <template #content>
        <div class="flex gap-2 mb-3">
          <input v-model="contactSearch" type="text" class="bsm-input w-full" :placeholder="$t('Search contacts…')" @input="searchContacts" />
          <select v-model="contactCategory" class="bsm-input bsm-category-select" @change="searchContacts">
            <option value="">{{ $t('All Categories') }}</option>
            <option v-for="cat in contactCategories" :key="cat" :value="cat">{{ cat }}</option>
          </select>
        </div>
        <div class="bsm-contact-list">
          <label v-for="c in contactResults" :key="c.id" class="bsm-contact-row" :class="{ 'bsm-contact-row-disabled': !c.phone }">
            <input type="checkbox" :value="c.id" v-model="selectedContactIds" class="bsm-checkbox" :disabled="!c.phone" />
            <span class="bsm-contact-avatar">{{ chipInitials(c.name) }}</span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-semibold text-gray-800 truncate">{{ c.name }}</span>
              <span class="flex items-center gap-1.5 flex-wrap mt-0.5">
                <span v-if="c.phone" class="text-xs text-gray-400 font-mono">{{ c.phone }}</span>
                <span v-else class="bsm-no-phone-badge"><icons name="exclamation" class="w-3 h-3" />{{ $t('No mobile number') }}</span>
                <span v-if="c.category" class="bsm-category-badge">{{ c.category }}</span>
              </span>
            </span>
          </label>
          <p v-if="!contactResults.length" class="text-xs text-gray-400 text-center py-6">{{ $t('No contacts found.') }}</p>
        </div>
      </template>
      <template #footer>
        <div class="flex items-center justify-between w-full">
          <span class="text-xs text-gray-500">{{ selectedContactIds.length }} {{ $t('selected') }}</span>
          <div class="flex items-center gap-2">
            <button type="button" class="lg-btn lg-btn-soft" @click="showImport = false"><icons name="cross" class="w-3.5 h-3.5" />{{ $t('Cancel') }}</button>
            <button type="button" class="lg-btn lg-btn-green" :disabled="!selectedContactIds.length" @click="importSelectedContacts"><icons name="plus" class="w-3.5 h-3.5" />{{ $t('Add Selected') }}</button>
          </div>
        </div>
      </template>
    </tec-dialog-modal>

    <tec-dialog-modal :show="showImportUsers" @close="showImportUsers = false" max-width="lg">
      <template #title>
        <div class="bsm-modal-head">
          <div class="lg-card-ico lg-ico-sky">
            <icons name="users" class="w-5 h-5" />
          </div>
          <div class="min-w-0">
            <div class="lg-card-title">{{ $t('Import from Users') }}</div>
            <div class="lg-card-sub">{{ $t('Add WIMS users as recipients on the') }} {{ channelLabel(channel) }} {{ $t('channel.') }}</div>
          </div>
        </div>
      </template>
      <template #content>
        <div class="flex gap-2 mb-3">
          <input v-model="userSearch" type="text" class="bsm-input w-full" :placeholder="$t('Search users…')" @input="searchUsers" />
        </div>
        <div class="bsm-contact-list">
          <label v-for="u in userResults" :key="u.id" class="bsm-contact-row" :class="{ 'bsm-contact-row-disabled': !u.phone }">
            <input type="checkbox" :value="u.id" v-model="selectedUserIds" class="bsm-checkbox" :disabled="!u.phone" />
            <span class="bsm-contact-avatar bsm-contact-avatar-users">{{ chipInitials(u.name) }}</span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-semibold text-gray-800 truncate">{{ u.name }}</span>
              <span class="flex items-center gap-1.5 flex-wrap mt-0.5">
                <span v-if="u.phone" class="text-xs text-gray-400 font-mono">{{ u.phone }}</span>
                <span v-else class="bsm-no-phone-badge"><icons name="exclamation" class="w-3 h-3" />{{ $t('No mobile number for') }} {{ channelLabel(channel) }}</span>
                <span v-if="u.email" class="text-xs text-gray-300 truncate">{{ u.email }}</span>
              </span>
            </span>
          </label>
          <p v-if="!userResults.length" class="text-xs text-gray-400 text-center py-6">{{ $t('No users found.') }}</p>
        </div>
      </template>
      <template #footer>
        <div class="flex items-center justify-between w-full">
          <span class="text-xs text-gray-500">{{ selectedUserIds.length }} {{ $t('selected') }}</span>
          <div class="flex items-center gap-2">
            <button type="button" class="lg-btn lg-btn-soft" @click="showImportUsers = false"><icons name="cross" class="w-3.5 h-3.5" />{{ $t('Cancel') }}</button>
            <button type="button" class="lg-btn lg-btn-green" :disabled="!selectedUserIds.length" @click="importSelectedUsers"><icons name="plus" class="w-3.5 h-3.5" />{{ $t('Add Selected') }}</button>
          </div>
        </div>
      </template>
    </tec-dialog-modal>
  </admin-layout>
</template>

<script>
import debounce from 'lodash/debounce';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

const EMOJIS = ['😀', '😂', '😍', '👍', '🙏', '🎉', '✅', '❌', '⚠️', '📦', '🚚', '💰', '📅', '⏰', '📍', '🔔', '❤️', '👋', '💼', '📞'];

export default {
  components: { AdminLayout, Link, TecDialogModal, PageHeader },

  props: {
    channelLive: { type: Object, default: () => ({}) },
    channelMaxLength: { type: Object, default: () => ({ whatsapp: 5000, meta: 5000, sms: 150 }) },
    contactCategories: { type: Array, default: () => [] },
  },

  data() {
    return {
      channel: this.defaultChannel(),
      recipients: [],
      newRecipient: { name: '', phone: '' },
      suggestions: [],
      activeField: null,
      phoneNotice: '',
      phoneNoticeTimer: null,
      body: '',
      showEmoji: false,
      emojiList: EMOJIS,
      scheduleOn: false,
      scheduledAt: '',
      sending: false,
      result: null,
      showImport: false,
      contactSearch: '',
      contactCategory: '',
      contactResults: [],
      selectedContactIds: [],
      showImportUsers: false,
      userSearch: '',
      userResults: [],
      selectedUserIds: [],
      excelUploading: false,
      excelNotice: '',
      excelNoticeError: false,
      excelNoticeTimer: null,
    };
  },

  computed: {
    channelDefs() {
      return [
        { key: 'whatsapp', label: this.$t('WhatsApp'), live: !!this.channelLive.whatsapp },
        { key: 'meta', label: this.$t('Meta'), live: !!this.channelLive.meta },
        { key: 'sms', label: this.$t('SMS'), live: !!this.channelLive.sms },
      ];
    },
    smsSegmentCount() {
      return Math.ceil(this.body.length / 160) || 1;
    },
    maxLength() {
      return this.channelMaxLength[this.channel] || 1600;
    },
    bodyError() {
      if (this.body.length > this.maxLength) {
        return this.$t('Message exceeds the') + ' ' + this.maxLength + ' ' + this.$t('character limit for') + ' ' + this.channelLabel(this.channel) + '.';
      }
      return '';
    },
    excelTemplateUrl() {
      return this.route('wims-connect.bulk-connect.excel-template');
    },
    canSend() {
      return this.channelLive[this.channel]
        && this.recipients.length > 0
        && this.body.trim().length > 0
        && !this.bodyError
        && (!this.scheduleOn || !!this.scheduledAt);
    },

    // Tanzania mobile numbers only: digits and an optional leading + — no
    // letters or other special characters — normalizing to exactly 9 digits
    // after the +255 country code (a bare 0XXXXXXXXX is 10 digits including
    // that leading 0, which normalizePhone() strips before the +255 prefix
    // goes on, so both shapes land on the same 9-digit check here).
    phoneError() {
      const raw = this.newRecipient.phone.trim();
      if (!raw) return '';
      if (!/^\+?\d+$/.test(raw)) {
        return this.$t('Only digits and a leading + are allowed.');
      }
      if (!/^\+255\d{9}$/.test(this.normalizePhone(raw))) {
        return this.$t('Enter a valid Tanzania mobile number, e.g. 0674020223 or +255674020223.');
      }
      return '';
    },
  },

  methods: {
    defaultChannel() {
      const live = ['whatsapp', 'meta', 'sms'].find(c => this.channelLive?.[c]);
      return live || 'whatsapp';
    },

    chipInitials(name) {
      return (name || '?').split(' ').filter(Boolean).slice(0, 2).map(p => p[0].toUpperCase()).join('') || '?';
    },

    channelLabel(c) {
      return c === 'sms' ? this.$t('SMS') : c.charAt(0).toUpperCase() + c.slice(1);
    },

    // Local Tanzanian numbers are commonly typed as 0XXXXXXXXX — silently
    // sending that to a gateway expecting international format would just
    // fail, so normalize it and tell the user rather than leaving them
    // guessing why a "valid-looking" number didn't work.
    normalizePhone(raw) {
      const trimmed = raw.trim();
      if (/^0\d{9}$/.test(trimmed)) {
        return '+255' + trimmed.slice(1);
      }
      return trimmed;
    },

    onPhoneBlur() {
      this.hideSuggestionsSoon();
      const original = this.newRecipient.phone.trim();
      const normalized = this.normalizePhone(original);
      if (normalized !== original && normalized.length > 0) {
        this.newRecipient.phone = normalized;
        this.showPhoneNotice(this.$t('Converted') + ' ' + original + ' ' + this.$t('to') + ' ' + normalized);
      }
    },

    showPhoneNotice(text) {
      this.phoneNotice = text;
      clearTimeout(this.phoneNoticeTimer);
      this.phoneNoticeTimer = setTimeout(() => { this.phoneNotice = ''; }, 5000);
    },

    onSuggestInput: debounce(function (field) {
      const q = (field === 'name' ? this.newRecipient.name : this.newRecipient.phone).trim();
      if (q.length < 2) { this.suggestions = []; return; }
      axios.get(this.route('wims-connect.bulk-connect.suggest-recipients'), { params: { q } })
        .then(res => { this.suggestions = res.data; });
    }, 250),

    hideSuggestionsSoon() {
      setTimeout(() => { this.activeField = null; this.suggestions = []; }, 150);
    },

    pickSuggestion(s) {
      // Selecting a suggestion always fully replaces whatever was typed —
      // if the user then edits the phone field again, that edit replaces
      // this value in turn (plain v-model), never merges with it.
      this.newRecipient = { name: s.name || '', phone: s.phone };
      this.suggestions = [];
      this.activeField = null;
    },

    addRecipient() {
      if (this.phoneError) return;
      const phone = this.normalizePhone(this.newRecipient.phone.trim());
      if (!phone) return;
      if (this.recipients.some(r => r.phone === phone)) {
        this.newRecipient = { name: '', phone: '' };
        return;
      }
      this.recipients.push({ name: this.newRecipient.name.trim(), phone });
      this.newRecipient = { name: '', phone: '' };
    },

    removeRecipient(i) {
      this.recipients.splice(i, 1);
    },

    insertEmoji(emoji) {
      const el = this.$refs.messageArea;
      const start = el ? el.selectionStart : this.body.length;
      const end = el ? el.selectionEnd : this.body.length;
      this.body = this.body.slice(0, start) + emoji + this.body.slice(end);
      this.showEmoji = false;
      this.$nextTick(() => {
        if (el) { el.focus(); el.selectionStart = el.selectionEnd = start + emoji.length; }
      });
    },

    openImportModal() {
      this.showImport = true;
      this.selectedContactIds = [];
      this.contactSearch = '';
      this.contactCategory = '';
      this.searchContacts();
    },

    searchContacts: debounce(function () {
      axios.get(this.route('wims-connect.bulk-connect.import-contacts'), { params: { q: this.contactSearch, category: this.contactCategory } })
        .then(res => { this.contactResults = res.data; });
    }, 250),

    importSelectedContacts() {
      // The checkbox is already disabled for phone-less contacts, but a
      // stale selection (e.g. a category re-search dropping a previously
      // checked row from contactResults) is worth guarding here too.
      const chosen = this.contactResults.filter(c => this.selectedContactIds.includes(c.id) && c.phone);
      chosen.forEach(c => {
        const phone = this.normalizePhone(c.phone);
        if (!this.recipients.some(r => r.phone === phone)) {
          this.recipients.push({ name: c.name, phone });
        }
      });
      this.showImport = false;
    },

    openImportUsersModal() {
      this.showImportUsers = true;
      this.selectedUserIds = [];
      this.userSearch = '';
      this.searchUsers();
    },

    searchUsers: debounce(function () {
      axios.get(this.route('wims-connect.bulk-connect.import-users'), { params: { q: this.userSearch, channel: this.channel } })
        .then(res => { this.userResults = res.data; });
    }, 250),

    importSelectedUsers() {
      const chosen = this.userResults.filter(u => this.selectedUserIds.includes(u.id) && u.phone);
      chosen.forEach(u => {
        const phone = this.normalizePhone(u.phone);
        if (!this.recipients.some(r => r.phone === phone)) {
          this.recipients.push({ name: u.name, phone });
        }
      });
      this.showImportUsers = false;
    },

    showExcelNotice(text, isError) {
      this.excelNotice = text;
      this.excelNoticeError = !!isError;
      clearTimeout(this.excelNoticeTimer);
      this.excelNoticeTimer = setTimeout(() => { this.excelNotice = ''; }, 6000);
    },

    onExcelSelected(e) {
      const file = e.target.files[0];
      if (!file) return;

      this.excelUploading = true;
      const formData = new FormData();
      formData.append('excel', file);

      axios.post(this.route('wims-connect.bulk-connect.import-excel'), formData, { headers: { 'Content-Type': 'multipart/form-data' } })
        .then(res => {
          let added = 0;
          (res.data.recipients || []).forEach(r => {
            const phone = this.normalizePhone(r.phone);
            if (phone && !this.recipients.some(x => x.phone === phone)) {
              this.recipients.push({ name: r.name, phone });
              added++;
            }
          });
          const skipped = res.data.skipped || 0;
          this.showExcelNotice(
            added + ' ' + this.$t('recipient(s) imported.') + (skipped ? ' ' + skipped + ' ' + this.$t('row(s) skipped (missing name or phone).') : ''),
            false
          );
        })
        .catch(err => {
          this.showExcelNotice(err?.response?.data?.message || this.$t('Could not import that file.'), true);
        })
        .finally(() => {
          this.excelUploading = false;
          e.target.value = '';
        });
    },

    send() {
      if (!this.canSend || this.sending) return;
      this.sending = true;
      this.result = null;

      axios.post(this.route('wims-connect.bulk-connect.send'), {
        channel: this.channel,
        body: this.body,
        recipients: this.recipients,
        scheduled_at: this.scheduleOn ? this.scheduledAt : null,
      }).then(res => {
        this.result = { failed: false, text: res.data.message || this.$t('Sent.') };
        this.recipients = [];
        this.body = '';
        this.scheduleOn = false;
        this.scheduledAt = '';
      }).catch(err => {
        this.result = { failed: true, text: err?.response?.data?.message || this.$t('Something went wrong. Please try again.') };
      }).finally(() => {
        this.sending = false;
      });
    },
  },
};
</script>

<style scoped>
.bsm-shell { position: relative; }
.ca-ink { color: var(--lg-ink); }
.ca-mute { color: var(--lg-mute); }

.bsm-panel { padding: 1.15rem 1.3rem 1.3rem; border-radius: 22px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); }
.bsm-send-panel { position: sticky; top: 1rem; }

.bsm-channel-pill {
  display: inline-flex; align-items: center; gap: 7px; position: relative;
  padding: 0.5rem 1rem; border-radius: 9999px;
  font-size: 0.8rem; font-weight: 800; color: var(--lg-mute); background: var(--lg-soft);
  border: 1px solid var(--lg-line); transition: all 0.15s ease;
}
.bsm-channel-pill-active { color: #fff; background: linear-gradient(145deg, #f472b6, #7c3aed); border-color: transparent; box-shadow: 0 10px 18px -10px rgba(124, 58, 237, 0.7); }
.bsm-channel-pill-disabled { opacity: 0.55; cursor: not-allowed; }
.bsm-channel-off-dot { width: 6px; height: 6px; border-radius: 9999px; background: #f87171; margin-left: 2px; }

.bsm-field-label { display: block; font-size: 0.66rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: var(--lg-faint); margin-bottom: 0.3rem; }
.bsm-required-mark { color: #f472b6; }
.bsm-max-chars-label { font-size: 0.66rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: var(--lg-faint); }

.bsm-input { border: 1px solid #e2e8f0; border-radius: 0.85rem; padding: 0.55rem 0.8rem; font-size: 0.85rem; background: #fff; }
.bsm-input:focus { outline: none; border-color: #d946ef; box-shadow: 0 0 0 3px rgba(217, 70, 239, 0.15); }
.bsm-input-error { border-color: #fca5a5; }
.bsm-input-error:focus { border-color: #f87171; box-shadow: 0 0 0 3px rgba(248, 113, 113, 0.15); }
html.dark .bsm-input { background: #0f172a; border-color: rgba(255, 255, 255, 0.12); color: #e2e8f0; }

.bsm-suggest-list { position: absolute; z-index: 40; left: 0; right: 0; top: calc(100% + 4px); background: var(--lg-card, #fff); border: 1px solid var(--lg-line, #eef1f6); border-radius: 0.85rem; box-shadow: 0 12px 28px -8px rgba(15, 23, 42, 0.25); max-height: 220px; overflow-y: auto; }
.bsm-suggest-item { padding: 0.5rem 0.75rem; font-size: 0.78rem; cursor: pointer; }
.bsm-suggest-item:hover { background: rgba(217, 70, 239, 0.08); }
html.dark .bsm-suggest-list { background: #0f172a; }

.bsm-inline-notice { display: flex; align-items: center; gap: 6px; font-size: 0.72rem; color: #92400e; background: #fef3c7; border: 1px solid #fde68a; border-radius: 0.75rem; padding: 0.45rem 0.7rem; }
.bsm-inline-notice-error { color: #b91c1c; background: #fee2e2; border-color: #fecaca; }
html.dark .bsm-inline-notice { background: rgba(245, 158, 11, 0.12); border-color: rgba(245, 158, 11, 0.3); color: #fcd34d; }
html.dark .bsm-inline-notice-error { background: rgba(127, 29, 29, 0.25); border-color: rgba(248, 113, 113, 0.3); color: #fecaca; }
.bsm-fade-enter-active, .bsm-fade-leave-active { transition: opacity 0.2s ease; }
.bsm-fade-enter-from, .bsm-fade-leave-to { opacity: 0; }

.bsm-count-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 1.6rem; height: 1.6rem; padding: 0 0.45rem; border-radius: 9999px; background: linear-gradient(145deg, #f472b6, #7c3aed); color: #fff; font-size: 0.72rem; font-weight: 800; }

.bsm-contact-avatar {
  display: inline-flex; align-items: center; justify-content: center; flex: none; margin-top: 1px;
  width: 2rem; height: 2rem; border-radius: 9999px;
  background: linear-gradient(135deg, #f472b6, #7c3aed); color: #fff;
  font-size: 0.64rem; font-weight: 700;
}
.bsm-contact-avatar-users { background: linear-gradient(135deg, #38bdf8, #0369a1); }
.bsm-contact-row-disabled { opacity: 0.55; cursor: not-allowed; }
.bsm-no-phone-badge {
  display: inline-flex; align-items: center; gap: 3px;
  font-size: 0.66rem; font-weight: 700; color: #b45309;
  background: #fef3c7; border: 1px solid #fde68a; border-radius: 9999px; padding: 0.1rem 0.45rem;
}
.bsm-category-badge { display: inline-flex; align-items: center; font-size: 0.66rem; font-weight: 700; color: #4338ca; background: rgba(99, 102, 241, 0.14); border-radius: 9999px; padding: 0.1rem 0.5rem; }
html.dark .bsm-category-badge { color: #c7d2fe; }

.bsm-template-link { display: inline-flex; align-items: center; gap: 5px; margin-bottom: 1rem; font-size: 0.72rem; font-weight: 700; color: #7c3aed; }
.bsm-template-link:hover { color: #6d28d9; text-decoration: underline; }
html.dark .bsm-template-link { color: #c4b5fd; }

.bsm-category-select { width: 11rem; flex: none; }

.bsm-chip {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 0.35rem 0.6rem; border-radius: 9999px;
  background: rgba(139, 92, 246, 0.09);
  border: 1px solid rgba(139, 92, 246, 0.2);
  max-width: 220px;
}
.bsm-chip-avatar {
  display: inline-flex; align-items: center; justify-content: center; flex: none;
  height: 1.6rem; width: 1.6rem; border-radius: 9999px;
  background: linear-gradient(135deg, #f472b6, #7c3aed); color: #fff;
  font-size: 0.66rem; font-weight: 700;
}
.bsm-chip :deep(.text-gray-800), .bsm-chip .text-gray-800 { color: var(--lg-ink); }
.bsm-chip-remove { flex: none; color: #9ca3af; padding: 2px; border-radius: 9999px; }
.bsm-chip-remove:hover { color: #b91c1c; background: #fee2e2; }
.bsm-chip-enter-active { transition: all 0.25s cubic-bezier(0.34, 1.56, 0.64, 1); }
.bsm-chip-enter-from { opacity: 0; transform: scale(0.6); }
.bsm-chip-leave-active { transition: all 0.15s ease; position: absolute; }
.bsm-chip-leave-to { opacity: 0; transform: scale(0.8); }

.bsm-emoji-btn {
  display: inline-flex; align-items: center; justify-content: center;
  width: 1.8rem; height: 1.8rem; border-radius: 0.6rem;
  background: var(--lg-soft); border: 1px solid var(--lg-line); font-size: 0.95rem;
}
.bsm-emoji-btn:hover { background: rgba(139, 92, 246, 0.12); }
.bsm-emoji-panel {
  position: absolute; right: 0; top: calc(100% + 4px); z-index: 40;
  display: grid; grid-template-columns: repeat(5, 1fr); gap: 2px;
  width: 200px; padding: 0.5rem; border-radius: 1rem;
  background: var(--lg-card, #fff); border: 1px solid var(--lg-line, #eef1f6); box-shadow: 0 12px 28px -8px rgba(15, 23, 42, 0.25);
}
html.dark .bsm-emoji-panel { background: #0f172a; }
.bsm-emoji-item { font-size: 1.1rem; padding: 0.3rem; border-radius: 0.5rem; }
.bsm-emoji-item:hover { background: rgba(217, 70, 239, 0.1); }

.bsm-pill { padding: 0.45rem 1rem; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; color: var(--lg-mute); background: var(--lg-soft); border: 1px solid var(--lg-line); }
.bsm-pill-active { color: #fff; background: linear-gradient(145deg, #f472b6, #7c3aed); border-color: transparent; }

.bsm-note { font-size: 0.72rem; color: #92400e; background: #fef3c7; border: 1px solid #fde68a; border-radius: 0.85rem; padding: 0.55rem 0.8rem; }
html.dark .bsm-note { background: rgba(245, 158, 11, 0.12); border-color: rgba(245, 158, 11, 0.3); color: #fcd34d; }

.bsm-send-btn { padding: 0.8rem 0; font-size: 0.9rem; background: linear-gradient(145deg, #f472b6, #7c3aed); box-shadow: 0 10px 20px -8px rgba(124, 58, 237, 0.7); }
.bsm-send-btn-pulsing { animation: bsm-pulse 1.4s ease-in-out infinite; }
@keyframes bsm-pulse {
  0%, 100% { box-shadow: 0 8px 20px -6px rgba(124, 58, 237, 0.55); }
  50% { box-shadow: 0 8px 28px -4px rgba(124, 58, 237, 0.85); }
}

.bsm-result { display: flex; align-items: flex-start; gap: 8px; padding: 0.65rem 0.85rem; border-radius: 0.85rem; font-size: 0.78rem; }
.bsm-result-ok { background: #ecfdf5; color: #047857; border: 1px solid #d1fae5; }
.bsm-result-warn { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
html.dark .bsm-result-ok { background: rgba(16, 185, 129, 0.12); border-color: rgba(16, 185, 129, 0.3); color: #6ee7b7; }
html.dark .bsm-result-warn { background: rgba(245, 158, 11, 0.12); border-color: rgba(245, 158, 11, 0.3); color: #fcd34d; }

.bsm-checkbox { margin-top: 2px; }
.bsm-contact-list { max-height: 320px; overflow-y: auto; border: 1px solid #eef1f6; border-radius: 1rem; }
.bsm-contact-row { display: flex; align-items: flex-start; gap: 10px; padding: 0.6rem 0.75rem; border-bottom: 1px solid #f1f5f9; cursor: pointer; }
.bsm-contact-row:hover { background: rgba(217, 70, 239, 0.06); }
.bsm-contact-row:last-child { border-bottom: none; }

html.dark .bsm-contact-list { border-color: rgba(255, 255, 255, 0.08); }
html.dark .bsm-contact-row { border-color: rgba(255, 255, 255, 0.06); }
</style>

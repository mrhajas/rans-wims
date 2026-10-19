<template>
  <div class="qrb">
    <button type="button" class="qrb-btn" :aria-label="$t('Show the QR code of this record')" @click="openIt">
      <span class="qrb-ico"><icons name="qr-code" class="w-4 h-4" /></span>{{ $t('QR code') }}
    </button>

    <modal :show="open" max-width="md" :closeable="true" @close="open = false">
      <div class="qrm">
        <header class="qrm-head">
          <span class="qrm-ico"><icons name="qr-code" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1"><h3>{{ $t('QR code') }}</h3><p>{{ rec.label }}</p></div>
          <button type="button" class="qrm-x" :aria-label="$t('Close')" @click="open = false"><icons name="cross" class="w-4 h-4" /></button>
        </header>

        <div v-if="loading" class="qrm-wait">{{ $t('Preparing the code...') }}</div>
        <p v-else-if="error" class="qrm-err">{{ error }}</p>
        <template v-else-if="data">
          <div class="qrm-stage">
            <div class="qrm-frame">
              <i class="c tl"></i><i class="c tr"></i><i class="c bl"></i><i class="c br"></i>
              <img :src="data.image" :alt="$t('QR code')" :style="{ width: data.settings.preview_px + 'px', height: data.settings.preview_px + 'px' }" />
            </div>
            <div v-if="data.settings.caption" class="qrm-cap"><small>{{ data.kind }}</small><b>{{ data.reference }}</b><span v-if="data.title">{{ data.title }}</span></div>
          </div>

          <p class="qrm-note"><icons name="lock-closed" class="w-4 h-4" />{{ $t('Scanning opens this record only for people who are signed in and allowed to open it. The code holds no record details.') }}</p>

          <div class="qrm-link"><input :value="data.url" readonly :aria-label="$t('Scan address')" @focus="$event.target.select()" /><button type="button" @click="copy">{{ copied ? $t('Copied') : $t('Copy') }}</button></div>

          <div v-if="data.can.print" class="qrm-print">
            <label><span>{{ $t('Size on paper') }}</span><div class="qrm-num"><input v-model.number="mm" type="number" min="25" max="80" step="5" /><i>mm</i></div></label>
            <label><span>{{ $t('Copies') }}</span><div class="qrm-num"><input v-model.number="copies" type="number" min="1" max="60" /></div></label>
          </div>

          <div class="qrm-actions">
            <a v-if="data.can.print" :href="route('qr-codes.print', { type: rec.type, id: rec.id, mm, copies })" target="_blank" rel="noopener" class="qrm-b is-primary"><icons name="printer" class="w-4 h-4" />{{ $t('Print label') }}</a>
            <a v-if="data.can.print" :href="route('qr-codes.download', { type: rec.type, id: rec.id })" class="qrm-b"><icons name="download" class="w-4 h-4" />{{ $t('Download PNG') }}</a>
            <button v-if="data.can.manage" type="button" class="qrm-b is-warn" @click="replace"><icons name="refresh" class="w-4 h-4" />{{ $t('Replace code') }}</button>
          </div>
          <p class="qrm-meta">{{ data.scans }} {{ $t('scans') }}<template v-if="data.last_scanned_at"> · {{ $t('last') }} {{ data.last_scanned_at }}</template></p>
        </template>
      </div>
    </modal>
  </div>
</template>

<script>
import Modal from '@/Jetstream/Modal.vue';
import { confirmDialog } from '@/Core/dialogs';

// The QR button on a record's page. The page data only says WHICH record this is; the code itself is asked for when the button is opened.
export default {
  components: { Modal },
  data() {
    return { open: false, loading: false, error: null, data: null, copied: false, mm: 40, copies: 1 };
  },
  computed: {
    rec() {
      return this.$page.props.qrRecord || {};
    },
  },
  watch: {
    // a different record (client-side navigation) starts clean
    'rec.id'() {
      this.data = null;
      this.open = false;
    },
  },
  methods: {
    async openIt() {
      this.open = true;
      if (this.data) return;
      this.loading = true;
      this.error = null;
      try {
        const { data } = await axios.get(route('qr-codes.show', { type: this.rec.type, id: this.rec.id }));
        this.data = data;
        this.mm = data.settings.print_mm;
        this.copies = data.settings.copies;
      } catch (e) {
        this.error = this.$t('The QR code could not be prepared.');
      }
      this.loading = false;
    },
    async copy() {
      try {
        await navigator.clipboard.writeText(this.data.url);
      } catch (e) {
        const i = this.$el.querySelector('.qrm-link input');
        i.select();
        document.execCommand('copy');
      }
      this.copied = true;
      setTimeout(() => (this.copied = false), 1800);
    },
    async replace() {
      if (!(await confirmDialog(this.$t('Replace this QR code? Every printed copy of the old code stops working at once.'), { variant: 'danger' }))) return;
      this.loading = true;
      try {
        const { data } = await axios.post(route('qr-codes.regenerate', { type: this.rec.type, id: this.rec.id }));
        this.data = data;
      } catch (e) {
        this.error = this.$t('The QR code could not be replaced.');
      }
      this.loading = false;
    },
  },
};
</script>

<style scoped>
.qrb { display: flex; justify-content: flex-end; margin: 0 0 0.7rem; }
.qrb-btn { display: inline-flex; align-items: center; gap: 8px; padding: 5px 14px 5px 6px; border-radius: 9999px; font-size: 0.8rem; font-weight: 800; white-space: nowrap; color: var(--lg-ink); background: var(--lg-card); box-shadow: 0 10px 18px -12px rgba(15, 37, 64, 0.6), 0 0 0 1px var(--lg-line); transition: transform 0.15s ease, box-shadow 0.15s ease; }
.qrb-btn:hover { transform: translateY(-1px); box-shadow: 0 14px 22px -12px rgba(15, 37, 64, 0.65), 0 0 0 1px #818cf8; }
.qrb-ico { display: inline-grid; place-items: center; width: 28px; height: 28px; border-radius: 9999px; color: #fff; background: linear-gradient(145deg, #6366f1, #0ea5e9); }
.qrm { padding: 1.1rem 1.2rem 1.2rem; }
.qrm-head { display: flex; align-items: center; gap: 0.8rem; margin-bottom: 0.9rem; }
.qrm-ico { flex: none; display: inline-grid; place-items: center; width: 42px; height: 42px; border-radius: 14px; color: #fff; background: linear-gradient(145deg, #6366f1, #0ea5e9); box-shadow: 0 12px 20px -12px rgba(14, 165, 233, 0.8); }
.qrm-head h3 { margin: 0; font-size: 1.05rem; font-weight: 800; color: var(--lg-ink); } .qrm-head p { margin: 2px 0 0; font-size: 0.78rem; color: var(--lg-faint); }
.qrm-x { flex: none; display: inline-grid; place-items: center; width: 32px; height: 32px; border-radius: 10px; color: var(--lg-mute); background: var(--lg-soft); box-shadow: 0 6px 10px -8px rgba(15, 37, 64, 0.5); }
.qrm-wait, .qrm-err { padding: 2rem 0; text-align: center; font-size: 0.9rem; color: var(--lg-mute); } .qrm-err { color: #be123c; font-weight: 700; }
.qrm-stage { display: grid; justify-items: center; gap: 0.7rem; padding: 1.1rem; border-radius: 20px; background: linear-gradient(145deg, #eef2ff, #ecfeff); box-shadow: inset 0 0 0 1px rgba(99, 102, 241, 0.2); }
.qrm-frame { position: relative; padding: 14px; border-radius: 18px; background: #fff; box-shadow: 0 22px 36px -22px rgba(15, 37, 64, 0.6), 0 4px 10px rgba(15, 37, 64, 0.08); max-width: 100%; }
.qrm-frame img { display: block; max-width: 100%; height: auto; image-rendering: pixelated; }
.qrm-frame .c { position: absolute; width: 20px; height: 20px; border: 3px solid #6366f1; } .c.tl { top: -2px; left: -2px; border-right: 0; border-bottom: 0; border-top-left-radius: 14px; } .c.tr { top: -2px; right: -2px; border-left: 0; border-bottom: 0; border-top-right-radius: 14px; } .c.bl { bottom: -2px; left: -2px; border-right: 0; border-top: 0; border-bottom-left-radius: 14px; } .c.br { bottom: -2px; right: -2px; border-left: 0; border-top: 0; border-bottom-right-radius: 14px; }
.qrm-cap { display: grid; justify-items: center; text-align: center; line-height: 1.3; } .qrm-cap small { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: #4f46e5; } .qrm-cap b { font-size: 1.05rem; color: #111827; overflow-wrap: anywhere; } .qrm-cap span { font-size: 0.78rem; color: #475569; overflow-wrap: anywhere; }
.qrm-note { display: flex; gap: 8px; align-items: flex-start; margin: 0.8rem 0 0; padding: 0.55rem 0.75rem; border-radius: 12px; font-size: 0.74rem; line-height: 1.45; color: #065f46; background: #ecfdf5; box-shadow: inset 0 0 0 1px rgba(16, 185, 129, 0.3); } .qrm-note svg { flex: none; margin-top: 2px; }
.qrm-link { display: flex; gap: 6px; margin-top: 0.7rem; } .qrm-link input { flex: 1; min-width: 0; height: 40px; padding: 0 12px; border-radius: 12px; border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-ink); font-size: 0.78rem; box-shadow: 0 8px 14px -12px rgba(15, 37, 64, 0.55); } .qrm-link button { flex: none; padding: 0 16px; border-radius: 12px; font-size: 0.8rem; font-weight: 800; color: #fff; background: #1c3f66; box-shadow: 0 10px 16px -10px rgba(28, 63, 102, 0.8); }
.qrm-print { display: grid; grid-template-columns: 1fr 1fr; gap: 0.7rem; margin-top: 0.8rem; } .qrm-print label span { display: block; margin-bottom: 4px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.qrm-num { position: relative; } .qrm-num input { width: 100%; height: 40px; padding: 0 34px 0 12px; border-radius: 12px; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-ink); box-shadow: 0 8px 14px -12px rgba(15, 37, 64, 0.55); } .qrm-num i { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); font-style: normal; font-size: 0.72rem; color: var(--lg-faint); }
.qrm-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.9rem; }
.qrm-b { display: inline-flex; align-items: center; justify-content: center; gap: 7px; flex: 1 1 130px; min-height: 42px; padding: 0 14px; border-radius: 13px; font-size: 0.82rem; font-weight: 800; white-space: nowrap; color: var(--lg-ink); background: var(--lg-card); box-shadow: 0 12px 18px -12px rgba(15, 37, 64, 0.6), 0 0 0 1px var(--lg-line); text-decoration: none; }
.qrm-b.is-primary { color: #fff; background: linear-gradient(145deg, #3b82c4, #16365c); box-shadow: 0 14px 22px -12px rgba(22, 54, 92, 0.9); } .qrm-b.is-warn { color: #9a3412; background: #fff7ed; }
.qrm-meta { margin: 0.7rem 0 0; text-align: center; font-size: 0.72rem; color: var(--lg-faint); }
html.dark .qrm-stage { background: linear-gradient(145deg, rgba(49, 46, 129, 0.35), rgba(8, 47, 73, 0.35)); } html.dark .qrm-note { color: #6ee7b7; background: rgba(16, 185, 129, 0.12); }
@media (max-width: 479.98px) { .qrm { padding: 0.9rem; } .qrm-print { grid-template-columns: 1fr 1fr; } }
</style>

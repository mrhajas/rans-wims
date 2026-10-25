<template>
  <modal :show="show" max-width="5xl" @close="$emit('close')">
    <div class="bcm">
      <header class="bcm-head">
        <span class="bcm-ico"><icons name="doc-text" class="w-6 h-6" /></span>
        <div class="min-w-0 flex-1">
          <h3>{{ $t('BinCard') }}</h3>
          <p v-if="data">{{ data.item.name }} <span class="bcm-code">{{ data.item.code }}</span></p>
        </div>
        <!-- print and download the BinCard as a WIMS report (each use is logged with who and when) -->
        <template v-if="data && data.current">
          <a :href="route('items.bincard.print', itemId)" target="_blank" rel="noopener" class="bcm-hbtn lg-tip" :data-tip="$t('Print')" :aria-label="$t('Print')"><icons name="printer" class="w-4 h-4" /></a>
          <a :href="route('items.bincard.pdf', itemId)" class="bcm-hbtn lg-tip" :data-tip="$t('Download PDF')" :aria-label="$t('Download PDF')"><icons name="download" class="w-4 h-4" /></a>
        </template>
        <button type="button" class="bcm-x" :aria-label="$t('Close')" @click="$emit('close')"><icons name="cross" class="w-4 h-4" /></button>
      </header>

      <div v-if="loading && !data" class="bcm-empty">{{ $t('Loading...') }}</div>
      <div v-else-if="data" class="bcm-body">
        <div class="bcm-stage">
          <div v-if="shown" class="bcm-photo"><img :src="shown.url" class="bcm-img" :alt="$t('BinCard')" /></div>
          <div v-else class="bcm-empty"><icons name="doc-text" class="w-10 h-10" /><b>{{ $t('This item has no BinCard yet.') }}</b><span v-if="data.can_update">{{ $t('Press Refresh BinCard to add its photo.') }}</span></div>
        </div>

        <aside class="bcm-side">
          <div class="bcm-card">
            <small>{{ viewingOld ? $t('Earlier BinCard') : $t('Last update') }}</small>
            <template v-if="shown">
              <b>{{ $datetime(shown.at) }}</b>
              <span v-if="shown.by"><icons name="user" class="w-3.5 h-3.5" />{{ shown.by }}</span>
              <button v-if="viewingOld" type="button" class="bcm-link" @click="pick = null">{{ $t('Back to the current BinCard') }}</button>
            </template>
            <b v-else>{{ $t('Never') }}</b>
          </div>

          <template v-if="data.can_update">
            <button type="button" class="bcm-btn is-main" :disabled="busy" @click="cameraOpen = true"><icons name="camera" class="w-5 h-5" />{{ $t('Refresh BinCard') }}</button>
            <button type="button" class="bcm-btn" :disabled="busy" @click="$refs.file.click()"><icons name="upload" class="w-4 h-4" />{{ $t('Upload a photo instead') }}</button>
            <input ref="file" type="file" accept="image/*" class="hidden" @change="onFile" />
            <p class="bcm-note">{{ $t('One photo. It becomes the BinCard of this item and its Last update date and time.') }}</p>
          </template>

          <div v-if="data.history.length" class="bcm-history">
            <h4>{{ $t('Earlier BinCards') }}</h4>
            <div class="bcm-thumbs">
              <button v-for="h in data.history" :key="h.id" type="button" class="bcm-thumb" :class="{ 'is-on': pick && pick.id === h.id }" @click="pick = h">
                <img :src="h.url" alt="" /><span>{{ $date(h.at) }}</span>
              </button>
            </div>
          </div>

          <button v-if="data.can_update && data.current" type="button" class="bcm-btn is-danger" :disabled="busy" @click="remove"><icons name="trash" class="w-4 h-4" />{{ $t('Remove the BinCard from this item') }}</button>
        </aside>
      </div>

      <camera-capture v-if="cameraOpen" :can-photos="true" :can-videos="false" :photo-left="1" :video-left="0" :max-px="2400" @capture="onCapture" @fallback="$refs.file && $refs.file.click()" @close="cameraOpen = false" />
    </div>
  </modal>
</template>

<script>
import Modal from '@/Jetstream/Modal.vue';
import CameraCapture from '@/Shared/SiteDiary/CameraCapture.vue';

export default {
  components: { Modal, CameraCapture },
  props: { show: Boolean, itemId: { type: Number, default: null } },
  emits: ['close', 'updated'],
  data() {
    return { data: null, loading: false, busy: false, pick: null, cameraOpen: false };
  },
  computed: {
    shown() {
      return this.pick || (this.data && this.data.current) || null;
    },
    viewingOld() {
      return !!this.pick;
    },
  },
  watch: {
    show(v) {
      if (v) this.load();
      else {
        this.pick = null;
        this.cameraOpen = false;
      }
    },
    itemId() {
      this.data = null;
      if (this.show) this.load();
    },
  },
  mounted() {
    if (this.show) this.load();
  },
  methods: {
    load() {
      if (!this.itemId) return Promise.resolve();
      this.loading = true;
      return window.axios.get(this.route('items.bincard', this.itemId), { headers: { 'X-Idle-Ignore': '1' } }).then(r => { this.data = r.data; }).catch(() => {}).finally(() => { this.loading = false; });
    },
    onCapture(file) {
      this.cameraOpen = false;
      this.upload(file);
    },
    onFile(e) {
      const f = e.target.files && e.target.files[0];
      e.target.value = '';
      if (f) this.upload(f);
    },
    upload(file) {
      this.busy = true;
      this.$inertia.post(this.route('items.bincard.refresh', this.itemId), { photo: file }, {
        forceFormData: true, preserveScroll: true, preserveState: true,
        headers: { 'X-Progress-Label': this.$t('Updating the BinCard'), 'X-Progress-Done': this.$t('BinCard refreshed') },
        onSuccess: () => { this.pick = null; this.load().then(() => this.$emit('updated')); },
        onFinish: () => (this.busy = false),
      });
    },
    async remove() {
      if (!(await this.$confirm(this.$t('Take the BinCard off this item? The photos stay in the history.'), { confirmText: this.$t('Remove') }))) return;
      this.busy = true;
      this.$inertia.delete(this.route('items.bincard.remove', this.itemId), {
        preserveScroll: true, preserveState: true,
        onSuccess: () => { this.load().then(() => this.$emit('updated')); },
        onFinish: () => (this.busy = false),
      });
    },
  },
};
</script>

<style scoped>
.bcm { background: var(--lg-card, #fff); color: var(--lg-ink, #0f172a); border-radius: 0.6rem; overflow: hidden; --lg-line: #e6ebf3; --lg-soft: #f6f8fc; --lg-mute: #64748b; }
html.dark .bcm { background: #1e293b; color: #f1f5f9; --lg-line: rgba(255, 255, 255, 0.1); --lg-soft: rgba(255, 255, 255, 0.05); --lg-mute: #94a3b8; }
.bcm-head { display: flex; align-items: center; gap: 12px; padding: 14px 18px; color: #fff; background: linear-gradient(135deg, #0f766e, #115e59 55%, #1e3a8a 140%); }
.bcm-head h3 { margin: 0; font-size: 1.05rem; font-weight: 800; } .bcm-head p { margin: 2px 0 0; font-size: 0.82rem; opacity: 0.95; display: flex; flex-wrap: wrap; align-items: center; gap: 2px 6px; overflow-wrap: anywhere; }
.bcm-code { margin-left: 0; white-space: nowrap; flex: none; padding: 1px 8px; border-radius: 9999px; background: rgba(255, 255, 255, 0.2); font-size: 0.72rem; font-weight: 700; }
.bcm-ico { display: inline-flex; align-items: center; justify-content: center; width: 2.6rem; height: 2.6rem; flex: none; border-radius: 0.9rem; background: rgba(255, 255, 255, 0.18); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.3); }
.bcm-x { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; border: 0; border-radius: 9999px; color: #fff; background: rgba(255, 255, 255, 0.16); cursor: pointer; } .bcm-x:hover { background: rgba(255, 255, 255, 0.3); }
.bcm-body { display: grid; grid-template-columns: minmax(0, 1fr) 280px; gap: 16px; padding: 16px; }
.bcm-stage { display: grid; gap: 8px; align-content: start; min-width: 0; }
.bcm-img { display: block; width: 100%; height: 100%; object-fit: contain; }
.bcm-photo { height: min(68vh, 640px); border-radius: 1rem; overflow: hidden; border: 1px solid var(--lg-line); background: repeating-conic-gradient(#e2e8f0 0 25%, #f8fafc 0 50%) 50% / 22px 22px; }
html.dark .bcm-photo { background: repeating-conic-gradient(#0f172a 0 25%, #1e293b 0 50%) 50% / 22px 22px; }
.bcm-empty { display: grid; place-items: center; gap: 6px; min-height: 280px; padding: 24px; text-align: center; color: var(--lg-mute); border: 2px dashed var(--lg-line); border-radius: 1rem; } .bcm-empty b { color: var(--lg-ink); }
.bcm-side { display: grid; gap: 10px; align-content: start; }
.bcm-card { display: grid; gap: 4px; padding: 12px 14px; border-radius: 1rem; border: 1px solid var(--lg-line); background: var(--lg-soft); }
.bcm-card small { font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: var(--lg-mute); } .bcm-card b { font-size: 1rem; } .bcm-card span { display: inline-flex; align-items: center; gap: 6px; font-size: 0.8rem; color: var(--lg-mute); }
.bcm-link { justify-self: start; border: 0; background: none; padding: 0; color: #0f766e; font-size: 0.78rem; font-weight: 700; cursor: pointer; text-decoration: underline; }
.bcm-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 2.6rem; padding: 0 14px; border: 1px solid var(--lg-line); border-radius: 0.9rem; background: var(--lg-card, #fff); color: inherit; font-weight: 700; font-size: 0.85rem; cursor: pointer; transition: transform 0.15s ease, box-shadow 0.15s ease; }
.bcm-btn:hover:not(:disabled) { transform: translateY(-1px); } .bcm-btn:disabled { opacity: 0.55; cursor: not-allowed; }
.bcm-btn.is-main { border: 0; color: #fff; font-weight: 800; background: linear-gradient(145deg, #34d399, #059669 60%, #047857); box-shadow: 0 14px 24px -12px rgba(5, 150, 105, 0.85), inset 0 1px 0 rgba(255, 255, 255, 0.4); text-shadow: 0 1px 1px rgba(0, 0, 0, 0.25); }
.bcm-note { margin: 0; font-size: 0.74rem; line-height: 1.4; color: var(--lg-mute); }
.bcm-history h4 { margin: 4px 0 6px; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: var(--lg-mute); }
.bcm-thumbs { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
.bcm-thumb { display: grid; gap: 3px; padding: 4px; border-radius: 0.7rem; border: 1px solid var(--lg-line); background: var(--lg-soft); cursor: pointer; text-align: center; } .bcm-thumb img { width: 100%; aspect-ratio: 4 / 3; object-fit: cover; border-radius: 0.5rem; } .bcm-thumb span { font-size: 0.64rem; color: var(--lg-mute); } .bcm-thumb.is-on { border-color: #0f766e; box-shadow: 0 0 0 2px rgba(15, 118, 110, 0.3); }
.bcm-hbtn { display: inline-flex; align-items: center; justify-content: center; width: 2rem; height: 2rem; min-height: 0; padding: 0; border-radius: 9999px; color: #fff; background: rgba(255, 255, 255, 0.16); cursor: pointer; text-decoration: none; transition: background 0.15s ease, transform 0.15s ease; } .bcm-hbtn:hover { background: rgba(255, 255, 255, 0.32); transform: scale(1.08); }
.bcm-btn.is-danger { border: 0; color: #fff; font-weight: 800; background: linear-gradient(145deg, #f87171, #dc2626 60%, #b91c1c); box-shadow: 0 12px 22px -12px rgba(220, 38, 38, 0.85), inset 0 1px 0 rgba(255, 255, 255, 0.35); text-shadow: 0 1px 1px rgba(0, 0, 0, 0.25); }
.bcm-remove { display: inline-flex; align-items: center; gap: 6px; justify-self: start; border: 0; background: none; padding: 4px 0; color: #dc2626; font-size: 0.76rem; font-weight: 700; cursor: pointer; } .bcm-remove:disabled { opacity: 0.5; }
@media (max-width: 820px) { .bcm-body { grid-template-columns: 1fr; } .bcm-photo { height: min(52vh, 460px); } }
</style>

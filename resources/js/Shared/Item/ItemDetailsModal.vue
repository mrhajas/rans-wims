<template>
  <modal :show="show" max-width="4xl" :closeable="true" @close="$emit('close')">
    <div class="rounded-md overflow-hidden">
      <div class="idm-head print:hidden">
        <span class="idm-ico"><icons name="collection" class="w-5 h-5" /></span>
        <div class="idm-title">
          <b>{{ $t('Item Details') }}</b>
          <span v-if="show && item">{{ $t(item.name) }}</span>
          <span v-else-if="show && preview">{{ preview }}</span>
        </div>
        <div class="idm-actions">
          <!-- the edit page opens in a new tab, so this window and the page behind it stay as they are -->
          <a
            v-if="item && $can('update-items')"
            :href="route('items.edit', item.id)"
            target="_blank"
            rel="noopener"
            :data-tip="$t('Edit Item (opens in a new tab)')"
            class="idm-btn is-amber lg-tip"
            :aria-label="$t('Edit Item (opens in a new tab)')"
          >
            <icons name="edit" class="h-5 w-5" />
          </a>
          <a
            v-if="item"
            target="_blank"
            :data-tip="$t('Print Barcode')"
            :href="route('items.barcodes', { ids: item.id })"
            class="idm-btn lg-tip"
            :aria-label="$t('Print Barcode')"
          >
            <icons name="doc" class="h-5 w-5" />
          </a>
          <a v-if="item" :href="route('items.report.pdf', item.id)" :data-tip="$t('Download PDF')" class="idm-btn is-green lg-tip" :aria-label="$t('Download PDF')"><icons name="download" class="h-5 w-5" /></a>
          <button v-if="item" type="button" class="idm-btn is-blue lg-tip" :data-tip="$t('Print Report')" :aria-label="$t('Print')" @click="startPrint">
            <icons name="printer" class="h-5 w-5" />
          </button>
          <button type="button" class="idm-btn is-red" :aria-label="$t('Close')" @click="$emit('close')">
            <icons name="cross" class="h-5 w-5" />
          </button>
        </div>
      </div>

      <div class="p-6 print:block print:h-full bg-gray-100">
        <item-details v-if="item" :item="item" :modal="true" />
        <div v-else-if="loading" class="idm-skel" aria-busy="true" :aria-label="$t('Loading...')">
          <i class="idm-skel-photo"></i>
          <span class="idm-skel-line w1"></span><span class="idm-skel-line w2"></span><span class="idm-skel-line w3"></span>
        </div>
        <p v-else class="idm-none">{{ $t('The item could not be loaded.') }}</p>
      </div>
    </div>
  </modal>
</template>

<script>
import Modal from '@/Jetstream/Modal.vue';
import ItemDetails from '@/Pages/Item/Details.vue';
import { printWithAnimation, itemPrintSheet, printUrlInFrame } from '@/Core/printAnimation';

// The item window of the Items list, also opened from the Dashboard's warehouse lists: the whole item (with its stock by warehouse), its barcode, PDF and
// print, and Edit, which opens the item's edit page in a new tab. `item` is the full item (items.show with ?json=yes); while it is still being fetched the window
// is already open and shows `preview` (the name and code) and a placeholder.
export default {
  components: { Modal, ItemDetails },
  props: { show: Boolean, item: Object, loading: Boolean, preview: String },
  emits: ['close'],
  methods: {
    startPrint() {
      printWithAnimation(itemPrintSheet(this, this.item), () => printUrlInFrame(this.route('items.report.print', this.item.id)));
    },
  },
};
</script>

<style scoped>
.idm-head { display: flex; align-items: center; gap: 12px; padding: 14px 18px; background: var(--lg-card, #fff); border-bottom: 1px solid var(--lg-line, #e5e7eb); }
.idm-ico { flex: none; width: 40px; height: 40px; border-radius: 13px; display: grid; place-items: center; color: #fff; background: linear-gradient(135deg, #2f6199, #1c3f66); }
.idm-title { display: grid; min-width: 0; flex: 1; line-height: 1.2; } .idm-title b { font-size: 1rem; } .idm-title span { font-size: 0.82rem; color: var(--lg-mute, #64748b); overflow-wrap: anywhere; }
.idm-actions { display: flex; align-items: center; gap: 8px; flex: none; }
.idm-btn { width: 38px; height: 38px; border-radius: 50%; display: grid; place-items: center; border: 0; cursor: pointer; background: rgba(148, 163, 184, 0.16); color: #475569; transition: background 0.15s, transform 0.15s; }
.idm-btn:hover { transform: translateY(-1px); background: rgba(148, 163, 184, 0.3); }
.idm-btn.is-blue { background: rgba(59, 130, 196, 0.16); color: #1c3f66; } .idm-btn.is-blue:hover { background: rgba(59, 130, 196, 0.3); }
.idm-btn.is-green { background: rgba(16, 185, 129, 0.16); color: #047857; } .idm-btn.is-green:hover { background: rgba(16, 185, 129, 0.3); }
.idm-btn.is-amber { background: rgba(245, 158, 11, 0.18); color: #b45309; } .idm-btn.is-amber:hover { background: rgba(245, 158, 11, 0.32); }
.idm-btn.is-red { background: rgba(225, 29, 72, 0.12); color: #be123c; } .idm-btn.is-red:hover { background: rgba(225, 29, 72, 0.24); }
.idm-btn:focus-visible { outline: 3px solid rgba(59, 130, 196, 0.5); outline-offset: 2px; }
.idm-none { text-align: center; color: #64748b; padding: 2rem 0; font-weight: 600; }
.idm-skel { display: grid; gap: 0.7rem; padding: 0.5rem 0; }
.idm-skel-photo { display: block; width: 4.5rem; height: 4.5rem; border-radius: 1rem; }
.idm-skel-line { display: block; height: 0.85rem; border-radius: 999px; }
.idm-skel-line.w1 { width: 62%; } .idm-skel-line.w2 { width: 84%; } .idm-skel-line.w3 { width: 48%; }
.idm-skel i, .idm-skel span { background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%); background-size: 200% 100%; animation: idm-shimmer 1.2s linear infinite; }
@keyframes idm-shimmer { to { background-position: -200% 0; } }
@media (prefers-reduced-motion: reduce) { .idm-skel i, .idm-skel span { animation: none; } }
@media (max-width: 479.98px) { .idm-head { padding: 12px; } .idm-actions { gap: 5px; } .idm-btn { width: 34px; height: 34px; } .idm-ico { display: none; } }

.idm-head { position: relative; background: linear-gradient(135deg, color-mix(in srgb, var(--lg-card, #fff) 92%, #6366f1), var(--lg-card, #fff)); }
.idm-ico { animation: idm-pop 0.5s cubic-bezier(0.3, 1.6, 0.5, 1) both; } .idm-btn { animation: idm-pop 0.45s cubic-bezier(0.3, 1.6, 0.5, 1) both; transition: background 0.15s, transform 0.2s cubic-bezier(0.3, 1.5, 0.5, 1), box-shadow 0.2s; }
.idm-actions .idm-btn:nth-child(1) { animation-delay: 0.1s; } .idm-actions .idm-btn:nth-child(2) { animation-delay: 0.16s; } .idm-actions .idm-btn:nth-child(3) { animation-delay: 0.22s; } .idm-actions .idm-btn:nth-child(4) { animation-delay: 0.28s; } .idm-actions .idm-btn:nth-child(5) { animation-delay: 0.34s; }
.idm-btn:hover { transform: translateY(-3px) scale(1.1); box-shadow: 0 10px 16px -8px rgba(15, 23, 42, 0.45); } .idm-btn:active { transform: scale(0.92); } .idm-btn.is-red:hover { transform: translateY(-3px) scale(1.1) rotate(90deg); }
@keyframes idm-pop { from { opacity: 0; transform: scale(0.6); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) { .idm-ico, .idm-btn { animation: none; transition: none; } }
</style>

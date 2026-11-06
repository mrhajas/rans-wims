<template>
  <div v-if="item" class="lg-page dt-doc print:h-full print:block print:m-0 print:pt-0 print:bg-white">
    <div class="hidden print:flex items-center justify-between gap-6 mb-5 pb-4 border-b border-slate-200">
      <div class="flex items-center gap-4">
        <img src="/images/logo.png" alt="RANS Logo" class="h-16 w-auto" />
        <div>
          <div class="text-2xl font-bold text-slate-800">Item Details</div>
          <div class="text-sm text-slate-500">RANS WIMS</div>
        </div>
      </div>
      <div class="text-right text-sm text-slate-600">
        <div class="font-semibold text-slate-800">{{ item.name }}</div>
        <div>SKU: {{ item.sku }}</div>
        <div>Code: {{ item.code }}</div>
        <div>Printed by: {{ ($page.props.auth && $page.props.auth.user && $page.props.auth.user.name) || 'N/A' }}</div>
        <div>Printed Date: {{ new Date().toLocaleString() }}</div>
      </div>
    </div>

    <div class="relative z-[1] print:block">
      <!-- Hero -->
      <section class="dt-hero print:hidden">
        <button type="button" class="dt-photo" :aria-label="$t('Image')" @click="() => (show = true)">
          <template v-if="item.photo">
            <img class="block w-full h-full object-cover" :src="item.photo" alt="" />
            <span class="dt-photo-zoom" aria-hidden="true"><icons name="zoom-in" class="w-5 h-5" /></span>
          </template>
          <span v-else class="dt-photo-ph is-initials" :style="{ '--h': hue }" aria-hidden="true">{{ initials }}</span>
        </button>
        <div class="min-w-0 flex-1 dt-title">
          <div class="dt-kicker"><icons name="collection" class="w-3.5 h-3.5" />{{ $t('Item') }}</div>
          <h2 class="dt-ref">{{ item.name }}</h2>
          <div class="dt-chips">
            <span class="lg-chip lg-chip-blue"><icons name="hash" class="w-3 h-3" />{{ $t('Code') }}: {{ item.code || '-' }}</span>
            <button v-if="item.code" type="button" class="dt-copy lg-tip" :class="{ 'is-done': copied }" :data-tip="copied ? $t('Copied') : $t('Copy the code')" :aria-label="$t('Copy the code')" @click.stop="copyCode"><icons :name="copied ? 'tick' : 'duplicate'" class="w-3.5 h-3.5" /></button>
            <span class="dt-state" :class="'is-' + stockState.key"><i></i>{{ stockState.label }}</span>
            <span v-if="item.sku" class="lg-chip lg-chip-slate">{{ $t('SKU') }}: {{ item.sku }}</span>
            <span v-if="item.categories && item.categories.length" class="lg-chip lg-chip-violet"><icons name="folder" class="w-3 h-3" />{{ item.categories[0].name }}</span>
            <span v-if="item.is_used" class="lg-chip lg-chip-amber">{{ $t('Used Item') }}</span>
          </div>
        </div>
        <div class="dt-codes">
          <div class="dt-barcode">
            <svg class="barcode" jsbarcode-width="1.5" jsbarcode-height="40" jsbarcode-fontSize="10" :jsbarcode-value="item.code" :jsbarcode-format="item.symbology"></svg>
          </div>
          <div v-if="qr" class="dt-qr" :title="$t('Scan to open this item')">
            <img :src="qr" alt="QR" />
            <small>{{ $t('Scan to open') }}</small>
          </div>
        </div>
      </section>

      <div class="hidden print:block mb-4">
        <svg class="barcode" jsbarcode-width="2" jsbarcode-height="70" jsbarcode-fontSize="12" :jsbarcode-value="item.code" :jsbarcode-format="item.symbology"></svg>
      </div>

      <!-- Key figures: every value stays on one line -->
      <div class="dt-tiles">
        <div v-for="(t, ti) in tiles" :key="t.label" class="dt-tile" :class="'is-' + t.tone" :style="{ '--ti': ti }">
          <span class="dt-tile-ico"><icons :name="t.icon" class="w-5 h-5" /></span>
          <div class="dt-tile-t">
            <b class="dt-tile-v" :class="{ 'is-money': t.money }" :style="{ '--n': String(t.text !== undefined ? t.text : t.num).length + 4 }"><template v-if="t.text !== undefined">{{ t.text }}</template><count-number v-else :value="t.num" :prefix="t.prefix || ''" :suffix="t.suffix || ''" :delay="ti * 90" /></b>
            <small>{{ t.label }}</small>
          </div>
        </div>
      </div>

      <!-- Info -->
      <section class="lg-card dt-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1"><h3 class="lg-card-title">{{ $t('Item Information') }}</h3><p class="lg-card-sub">{{ $t('Classification, unit and price') }}</p></div>
        </header>
        <div class="dt-kv">
          <div class="dt-f is-indigo"><span class="dt-f-ico"><icons name="folder" class="w-4 h-4" /></span><div><span class="dt-k">{{ $t('Category') }}</span><span class="dt-v">{{ item.categories && item.categories.length ? item.categories[0].name : '-' }}</span></div></div>
          <div v-if="item.categories && item.categories.length > 1" class="dt-f is-violet"><span class="dt-f-ico"><icons name="collection" class="w-4 h-4" /></span><div><span class="dt-k">{{ $t('Child Category') }}</span><span class="dt-v">{{ item.categories[1].name }}</span></div></div>
          <div class="dt-f is-sky"><span class="dt-f-ico"><icons name="table" class="w-4 h-4" /></span><div><span class="dt-k">{{ $t('Unit') }}</span><span class="dt-v">{{ item.unit ? item.unit.name : '-' }}</span></div></div>
          <div class="dt-f is-emerald"><span class="dt-f-ico"><icons name="cash" class="w-4 h-4" /></span><div><span class="dt-k">{{ $t('Item Price') }}</span><span class="dt-v dt-nowrap">{{ $number(item.price) }} Tsh</span></div></div>
          <div class="dt-f is-amber"><span class="dt-f-ico"><icons name="archive-box" class="w-4 h-4" /></span><div><span class="dt-k">{{ $t('Rack') }}</span><span class="dt-v">{{ item.rack_location || '-' }}</span></div></div>
          <div v-if="Number(item.alert_quantity) > 0" class="dt-f is-rose"><span class="dt-f-ico"><icons name="exclamation" class="w-4 h-4" /></span><div><span class="dt-k">{{ $t('Alert Quantity') }}</span><span class="dt-v dt-nowrap">{{ $number(item.alert_quantity) }} {{ item.unit ? item.unit.code : '' }}</span></div></div>
        </div>

        <div class="dt-sub"><icons name="adjustments" class="w-4 h-4" />{{ $t('Tracking') }}</div>
        <div class="dt-track">
          <span class="dt-pill" :class="item.track_quantity ? 'is-on' : ''"><icons :name="item.track_quantity ? 'tick' : 'cross'" class="w-3.5 h-3.5" />{{ $t('Track Quantity') }}</span>
          <span class="dt-pill" :class="item.track_weight ? 'is-on' : ''"><icons :name="item.track_weight ? 'tick' : 'cross'" class="w-3.5 h-3.5" />{{ $t('Track Weight') }}</span>
          <span class="dt-pill" :class="item.track_serials ? 'is-on' : ''"><icons :name="item.track_serials ? 'tick' : 'cross'" class="w-3.5 h-3.5" />{{ $t('Track Serials') }}</span>
        </div>

        <div v-if="item.has_variants" class="dt-note">
          <span class="dt-k">{{ $t('Variants') }}</span>
          <div v-for="v in item.variants" :key="v.name"><strong>{{ v.name }}:</strong> {{ v.option.filter(o => o).map(o => $t(o)).join(', ') }}</div>
        </div>
        <div v-if="item.details" class="dt-note"><span class="dt-k">{{ $t('Details') }}</span><div>{{ item.details }}</div></div>
      </section>

      <!-- Warehouse stock -->
      <section v-if="item.track_quantity && availableStock.length" class="lg-card dt-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-teal"><icons name="building" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h3 class="lg-card-title">{{ $t('Stock') }}</h3>
            <p class="lg-card-sub">{{ $number(totalQuantity) }} {{ item.unit ? item.unit.code : '' }} {{ $t('across') }} {{ availableStock.length }} {{ $t('warehouse(s)') }}</p>
          </div>
        </header>
        <div class="dt-wh-grid" :class="modal ? 'is-modal' : ''">
          <div v-for="(w, wi) in availableStock" :key="'w_' + w[0].warehouse.id" class="dt-wh print:break-inside-avoid" :class="'tone-' + (wi % 6)">
            <div class="dt-wh-head">
              <span class="dt-wh-ico"><icons name="building" class="w-4 h-4" /></span>
              <span class="dt-wh-name">{{ w[0].warehouse ? w[0].warehouse.name : $t('Unknown Warehouse') }}</span>
              <span v-if="w[0].warehouse" class="dt-code">{{ w[0].warehouse.code }}</span>
            </div>
            <div v-for="stock in w" :key="stock.id" class="dt-line">
              <div v-if="stock.variation" class="dt-line-var" v-html="$meta(stock.variation.meta)" />
              <div class="dt-line-row">
                <div class="dt-line-c"><small>{{ $t('Quantity') }}</small><b class="text-maroon dt-nowrap">{{ $number(stock.quantity) }} {{ item.unit ? item.unit.code : '' }}</b><span v-if="item.track_weight" class="dt-muted dt-nowrap">({{ $number(stock.weight) }} {{ $settings.weight_unit }})</span></div>
                <div class="dt-line-c is-end"><small>{{ $t('Value') }}</small><b class="dt-nowrap">{{ $number(stock.quantity * (item.price || 0)) }} Tsh</b></div>
              </div>
              <div class="dt-share" :title="share(stock) + '%'"><i :style="{ width: share(stock) + '%' }"></i></div>
            </div>
          </div>
        </div>
      </section>
      <section v-if="item.track_quantity && !availableStock.length" class="lg-card dt-card dt-none">
        <div class="dt-none-ico"><icons name="building" class="w-6 h-6" /></div>
        <b>{{ $t('No stock yet') }}</b>
        <p>{{ $t('This item is not currently available in any project(s) / warehouse(s).') }}</p>
        <a v-if="$can('create-checkins')" :href="route('checkins.create')" target="_blank" rel="noopener" class="lg-btn tone dt-none-go" style="--tone: #059669"><span class="tone-ico"><icons name="plus" class="w-4 h-4" /></span>{{ $t('Check in stock') }}</a>
      </section>

      <template v-if="item.has_variants && variations.length">
        <div class="flex flex-col gap-4 avoid print:mb-2 print:block">
          <loading v-if="loading" />
          <section
            v-for="variation in variations"
            :key="variation.sku"
            class="lg-card dt-card print:break-inside-avoid"
          >
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-violet"><icons name="collection" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1">
                <h3 class="lg-card-title" v-html="$meta(variation.meta)"></h3>
                <p class="lg-card-sub">{{ $t('SKU') }}: {{ variation.sku }}</p>
              </div>
            </header>
            <div class="dt-var-grid">
              <div :key="stock.id" v-for="stock in variation.stock" class="dt-var avoid">
                <div class="dt-var-title">
                  {{ stock.warehouse ? $t(stock.warehouse.name) : $t('Unknown Warehouse') }}
                  <span v-if="stock.warehouse" class="dt-code">({{ stock.warehouse.code }})</span>
                </div>
                <dl class="dt-dl">
                  <template v-if="item.track_quantity">
                    <dt>{{ $t('Quantity') }}</dt>
                    <dd class="text-maroon">{{ $number(stock.quantity) }} {{ item.unit ? item.unit.code : '' }}</dd>
                  </template>
                  <template v-if="item.track_weight">
                    <dt>{{ $t('Weight') }}</dt>
                    <dd>{{ $number(stock.weight) }} {{ $settings.weight_unit }}</dd>
                  </template>
                  <template v-if="stock.rack_location">
                    <dt>{{ $t('Rack Location') }}</dt>
                    <dd>{{ stock.rack_location }}</dd>
                  </template>
                </dl>
              </div>
            </div>
          </section>
        </div>
      </template>
    </div>

    <modal :show="show" max-width="2xl" :closeable="true" :transparent="true" @close="() => (show = false)">
      <div class="print:block print:h-full">
        <img class="block max-w-full mx-auto rounded-md" :src="item.photo" />
      </div>
    </modal>
  </div>
</template>

<script>
import JsBarcode from 'jsbarcode';
import Loading from '@/Shared/Loading.vue';
import Modal from '@/Jetstream/Modal.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import CountNumber from '@/Shared/CountNumber.vue';
export default {
  components: { Loading, Modal, StatTiles, CountNumber },

  props: { item: Object, modal: { default: false } },

  data() {
    return {
      show: false,
      copied: false,
      qr: null,
      loading: false,
      variations: [],
    };
  },

  computed: {
    // Only warehouses that still hold available stock (quantity, or weight for
    // weight-tracked items) should be listed on the item details page.
    availableStock() {
      const groups = this.item.stock || {};
      return Object.values(groups).filter(w => {
        const rows = Array.isArray(w) ? w : [w];
        return rows.some(s => Number(s.quantity) > 0 || (this.item.track_weight && Number(s.weight) > 0));
      });
    },
    totalQuantity() {
      return this.availableStock.reduce((sum, w) => sum + w.reduce((s, stock) => s + Number(stock.quantity || 0), 0), 0);
    },
    totalValue() {
      const price = Number(this.item.price || 0);
      return this.totalQuantity * price;
    },
    // In stock / Low stock / Out of stock, by the same rule as the Items page
    stockState() {
      const it = this.item;
      if (!it.track_quantity) return { key: 'untracked', label: this.$t('Not stock-tracked') };
      const q = this.totalQuantity;
      if (q <= 0) return { key: 'out', label: this.$t('Out of stock') };
      if (Number(it.alert_quantity) > 0 && q <= Number(it.alert_quantity)) return { key: 'low', label: this.$t('Low stock') };
      return { key: 'in', label: this.$t('In stock') };
    },
    initials() {
      const w = String(this.item.name || '?').trim().split(/\s+/).filter(Boolean);
      return ((w[0] || '?')[0] + (w[1] ? w[1][0] : '')).toUpperCase();
    },
    // a colour for the letters, the same for the same item
    hue() {
      let h = 0;
      String(this.item.code || this.item.name || '').split('').forEach(c => { h = (h * 31 + c.charCodeAt(0)) % 360; });
      return h;
    },
    tiles() {
      const unit = this.item.unit ? this.item.unit.code : '';
      const list = [{ label: this.$t('Item Price'), num: Number(this.item.price || 0), suffix: ' Tsh', icon: 'cash', tone: 'indigo', money: true }];
      if (this.item.track_quantity) {
        const out = this.totalQuantity <= 0;
        list.push({ label: this.$t('Quantity'), num: this.totalQuantity, suffix: unit ? ' ' + unit : '', icon: 'collection', tone: out ? 'rose' : 'navy' });
        list.push({ label: this.$t('Total Value of All Warehouses'), num: this.totalValue, prefix: 'Tsh ', icon: 'chart-bar', tone: 'emerald', money: true });
        list.push({ label: this.$t('Project(s) / Warehouse(s)'), num: this.availableStock.length, icon: 'building', tone: 'teal' });
      } else {
        list.push({ label: this.$t('Stock'), text: this.$t('Not tracked'), icon: 'collection', tone: 'slate' });
      }
      return list;
    },
  },

  mounted() {
    JsBarcode('.barcode').init();
    this.loadQr();
    if (!this.item.stock) {
      this.item.stock = this.item.all_stock;
    }
    // if (this.item.has_variants == 1) {
    //   this.loading = true;
    //   axios
    //     .get(route('items.show', this.item.id) + '?json=yes&variation_stock=yes')
    //     .then(res => (this.variations = res.data.variations))
    //     .finally(() => (this.loading = false));
    // }
  },

  watch: { 'item.id'() { this.qr = null; this.loadQr(); } },

  methods: {
    // the item's scan-to-open code (the QR module): nothing is shown when QR codes are off or not allowed for this person
    loadQr() {
      if (!this.item || !this.item.id) return;
      window.axios.get(route('qr-codes.show', { type: 'item', id: this.item.id })).then(r => { this.qr = r.data && r.data.image ? r.data.image : null; }).catch(() => { this.qr = null; });
    },
    copyCode() {
      const code = String(this.item.code || '');
      const done = () => { this.copied = true; clearTimeout(this._ct); this._ct = setTimeout(() => { this.copied = false; }, 1600); };
      // the older way, for a browser that does not allow the clipboard here
      const old = () => { const t = document.createElement('textarea'); t.value = code; t.style.position = 'fixed'; t.style.opacity = '0'; document.body.appendChild(t); t.select(); try { if (document.execCommand('copy')) done(); } catch (e) { /* not copied */ } t.remove(); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(code).then(done).catch(old);
      else old();
    },
    // this stock line's share of the item's whole quantity
    share(stock) {
      const t = this.totalQuantity;
      return t > 0 ? Math.max(2, Math.round((Number(stock.quantity || 0) / t) * 100)) : 0;
    },
  },

  updated() {
    JsBarcode('.barcode').init();
  },
};
</script>
<style scoped>
.dt-hero { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; padding: 1rem 1.2rem; margin-bottom: 1rem; border-radius: 22px; background: linear-gradient(135deg, rgba(224, 233, 250, 0.8), rgba(255, 251, 235, 0.65)); border: 1px solid rgba(255, 255, 255, 0.8); box-shadow: var(--lg-shadow); }
html.dark .dt-hero { background: linear-gradient(135deg, rgba(28, 63, 102, 0.45), rgba(15, 23, 42, 0.7)); border-color: var(--lg-line); }
.dt-hero-ico { width: 48px; height: 48px; border-radius: 16px; }
.dt-kicker { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.dt-ref { font-size: 1.2rem; font-weight: 800; line-height: 1.2; color: var(--lg-ink); overflow-wrap: anywhere; }
.dt-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.dt-barcode { flex: none; max-width: 100%; padding: 6px 12px; border-radius: 14px; background: #fff; border: 1px solid var(--lg-line); overflow: hidden; }
.dt-barcode svg { max-width: 100%; height: auto; }


.dt-kv { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr)); }
.dt-kv > div { display: flex; flex-direction: column; align-items: flex-start; gap: 3px; min-width: 0; padding: 10px 14px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.dt-k { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.dt-v { font-size: 0.9rem; font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }

.dt-photo { flex: none; width: 64px; height: 64px; min-height: 0; padding: 0; overflow: hidden; border-radius: 16px; cursor: zoom-in; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); transition: transform 0.15s ease; }
.dt-photo:hover { transform: scale(1.05); }
.dt-photo-ph { display: inline-flex; align-items: center; justify-content: center; width: 100%; height: 100%; color: var(--lg-faint); }
.dt-wide { grid-column: 1 / -1; }
.dt-variants > div { margin-top: 2px; font-weight: 600; }
.dt-wh-grid { display: grid; gap: 1.1rem; grid-template-columns: repeat(auto-fill, minmax(min(300px, 100%), 1fr)); }
.dt-wh-grid.is-modal { grid-template-columns: repeat(auto-fill, minmax(min(320px, 100%), 1fr)); }
.dt-wh { min-width: 0; padding: 12px 12px 6px; border: 1px solid var(--lg-line); border-radius: 16px; background: var(--lg-card); box-shadow: 0 14px 26px -16px rgba(15, 37, 64, 0.4), 0 2px 6px rgba(15, 37, 64, 0.08); }
html.dark .dt-wh { box-shadow: 0 14px 26px -16px rgba(0, 0, 0, 0.75), 0 2px 6px rgba(0, 0, 0, 0.3); }
.dt-wh-table { overflow-x: auto; }
@media (max-width: 639.98px) {
  .dt-wh { padding: 10px 6px 4px; }
  .dt-wh-head { padding: 0 6px; flex-wrap: wrap; }
  .dt-wh-head .truncate { white-space: normal; overflow: visible; text-overflow: clip; }
  .dt-wh-table .lg-table th, .dt-wh-table .lg-table td { padding: 8px 7px; font-size: 0.8rem; }
  /* the word "Quantity" in front of a plain stock line is already the column heading: drop it so the value fits */
  .dt-wh-table .dt-qty-label { display: none; }
}
.dt-wh-table .lg-table td.num, .dt-wh-table .lg-table th.num { white-space: nowrap; }
.dt-wh-head { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: 0.9rem; font-weight: 800; color: var(--lg-ink); }
.dt-wh-table .lg-table th, .dt-wh-table .lg-table td { padding: 9px 14px; }
.dt-none { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 2rem 1rem; text-align: center; font-size: 0.88rem; font-weight: 600; color: var(--lg-mute); }
.dt-none-ico { display: inline-flex; align-items: center; justify-content: center; width: 52px; height: 52px; border-radius: 9999px; color: var(--lg-faint); background: var(--lg-soft); }
.dt-var-grid { display: grid; gap: 10px; grid-template-columns: repeat(auto-fill, minmax(min(230px, 100%), 1fr)); }
.dt-var { min-width: 0; padding: 12px 14px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.dt-var-title { margin-bottom: 6px; font-size: 0.88rem; font-weight: 800; color: var(--lg-ink); }
.dt-dl { display: grid; grid-template-columns: auto 1fr; gap: 4px 14px; font-size: 0.82rem; }
.dt-dl dt { color: var(--lg-mute); }
.dt-dl dd { text-align: right; font-weight: 700; color: var(--lg-ink); font-variant-numeric: tabular-nums; }
.text-maroon { color: #7f1d1d; }
html.dark .text-maroon { color: #f87171; }
.dt-code { font-weight: 600; color: var(--lg-faint); }
.dt-muted { color: var(--lg-faint); }


@media (max-width: 767.98px) {
  .dt-barcode { width: 100%; text-align: center; }
}
@media print {
  .dt-doc .lg-card { padding: 12px 14px; border-radius: 0; box-shadow: none; border: 1px solid #e2e8f0; background: #fff; }
  .dt-doc .lg-table-wrap { border: 0; }
}

/* hero: photo, name, barcode + QR side by side */
.dt-hero { gap: 16px; }
.dt-photo { width: 76px; height: 76px; border-radius: 20px; }
.dt-title { flex: 1 1 220px; }
.dt-kicker { display: inline-flex; align-items: center; gap: 5px; }
.dt-codes { display: flex; align-items: stretch; gap: 10px; flex: 0 1 auto; max-width: 100%; flex-wrap: wrap; justify-content: flex-end; margin-left: auto; }
.dt-codes .dt-barcode { display: grid; place-items: center; padding: 6px 12px; }
.dt-qr { display: grid; justify-items: center; align-content: center; gap: 2px; padding: 6px 10px; border-radius: 14px; background: #fff; border: 1px solid var(--lg-line); }
.dt-qr img { width: 74px; height: 74px; image-rendering: pixelated; }
.dt-qr small { font-size: 0.6rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: #6b7f9c; }
@media (max-width: 639.98px) { .dt-codes { margin-left: 0; width: 100%; justify-content: flex-start; } .dt-photo { width: 62px; height: 62px; } }

/* key figures: one line each, the number shrinks to fit its tile */
.dt-tiles { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr)); margin-bottom: 1rem; }
.dt-tile { --tone: #4f46e5; --soft: rgba(79, 70, 229, 0.12); display: flex; align-items: center; gap: 12px; min-width: 0; padding: 14px 16px; border-radius: 18px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); container-type: inline-size; position: relative; overflow: hidden; }
.dt-tile::before { content: ''; position: absolute; left: 0; top: 14px; bottom: 14px; width: 4px; border-radius: 0 4px 4px 0; background: var(--tone); }
.dt-tile.is-emerald { --tone: #059669; --soft: rgba(5, 150, 105, 0.12); } .dt-tile.is-navy { --tone: #1c3f66; --soft: rgba(28, 63, 102, 0.12); } .dt-tile.is-teal { --tone: #0d9488; --soft: rgba(13, 148, 136, 0.12); }
.dt-tile-ico { flex: none; width: 44px; height: 44px; border-radius: 14px; display: grid; place-items: center; background: var(--soft); color: var(--tone); }
.dt-tile-t { display: grid; min-width: 0; flex: 1; line-height: 1.15; }
.dt-tile-v { white-space: nowrap; font-variant-numeric: tabular-nums; color: var(--lg-ink); font-size: clamp(0.85rem, calc((100cqi - 90px) / (var(--n) * 0.62)), 1.45rem); }
.dt-tile-v.is-money { color: #7f1d1d; } html.dark .dt-tile-v.is-money { color: #fca5a5; }
.dt-tile-t small { margin-top: 3px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); overflow-wrap: break-word; }

/* information fields with their own icon colour */
.dt-kv > .dt-f { flex-direction: row; align-items: center; gap: 12px; }
.dt-f { --tone: #4f46e5; --soft: rgba(79, 70, 229, 0.12); }
.dt-f.is-violet { --tone: #7c3aed; --soft: rgba(124, 58, 237, 0.12); } .dt-f.is-sky { --tone: #0284c7; --soft: rgba(2, 132, 199, 0.12); } .dt-f.is-emerald { --tone: #059669; --soft: rgba(5, 150, 105, 0.12); }
.dt-f.is-amber { --tone: #d97706; --soft: rgba(217, 119, 6, 0.14); } .dt-f.is-rose { --tone: #e11d48; --soft: rgba(225, 29, 72, 0.12); }
.dt-f-ico { flex: none; width: 34px; height: 34px; border-radius: 11px; display: grid; place-items: center; background: var(--soft); color: var(--tone); }
.dt-f > div { display: grid; gap: 2px; min-width: 0; }
.dt-nowrap { white-space: nowrap; }
.dt-sub { display: flex; align-items: center; gap: 8px; margin: 16px 2px 8px; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.dt-track { display: flex; flex-wrap: wrap; gap: 8px; }
.dt-pill { display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 14px; border-radius: 999px; font-size: 0.8rem; font-weight: 800; background: rgba(148, 163, 184, 0.16); color: #64748b; }
.dt-pill.is-on { background: #d8f1e5; color: #0a6140; } html.dark .dt-pill.is-on { background: rgba(16, 185, 129, 0.2); color: #6ee7b7; }
.dt-note { margin-top: 12px; padding: 10px 14px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); font-weight: 600; font-size: 0.88rem; overflow-wrap: anywhere; }
.dt-note .dt-k { display: block; margin-bottom: 3px; }

/* stock: one card per warehouse, every line with its share */
.dt-wh { --tone: #0d9488; padding: 0; overflow: hidden; border-top: 3px solid var(--tone); }
.dt-wh.tone-1 { --tone: #4f46e5; } .dt-wh.tone-2 { --tone: #0284c7; } .dt-wh.tone-3 { --tone: #d97706; } .dt-wh.tone-4 { --tone: #7c3aed; } .dt-wh.tone-5 { --tone: #e11d48; }
.dt-wh-head { display: flex; align-items: center; gap: 9px; padding: 11px 14px; min-width: 0; }
.dt-wh-ico { flex: none; width: 30px; height: 30px; border-radius: 10px; display: grid; place-items: center; background: color-mix(in srgb, var(--tone) 14%, transparent); color: var(--tone); }
.dt-wh-name { flex: 1; min-width: 0; font-weight: 800; font-size: 0.86rem; overflow-wrap: anywhere; line-height: 1.2; }
.dt-wh-head .dt-code { flex: none; font-size: 0.68rem; font-weight: 800; padding: 2px 8px; border-radius: 999px; background: rgba(148, 163, 184, 0.2); color: var(--lg-mute); }
.dt-line { padding: 4px 14px 12px; }
.dt-line-var { font-size: 0.8rem; font-weight: 700; margin-bottom: 4px; }
.dt-line-row { display: flex; align-items: flex-end; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.dt-line-c { display: grid; gap: 1px; min-width: 0; } .dt-line-c.is-end { text-align: right; }
.dt-line-c small { font-size: 0.62rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.dt-line-c b { font-size: 1rem; font-variant-numeric: tabular-nums; }
.dt-share { height: 6px; margin-top: 8px; border-radius: 4px; background: rgba(148, 163, 184, 0.22); overflow: hidden; } .dt-share i { display: block; height: 100%; border-radius: inherit; background: var(--tone); }

/* ---- motion: the window builds up piece by piece ---- */
.dt-hero { position: relative; overflow: hidden; animation: dt-rise 0.5s cubic-bezier(0.2, 0.8, 0.2, 1) both; }
.dt-hero::after { content: ''; position: absolute; inset: 0; pointer-events: none; background: linear-gradient(105deg, transparent 35%, rgba(255, 255, 255, 0.5) 50%, transparent 65%); transform: translateX(-130%); animation: dt-sweep 1.4s ease 0.5s 1 both; }
.dt-photo { transition: transform 0.3s cubic-bezier(0.3, 1.4, 0.5, 1), box-shadow 0.3s ease; } .dt-photo:hover { transform: scale(1.05) rotate(-2deg); box-shadow: 0 16px 28px -14px rgba(15, 23, 42, 0.5); }
.dt-barcode, .dt-qr { transition: transform 0.3s cubic-bezier(0.3, 1.4, 0.5, 1), box-shadow 0.3s ease; } .dt-barcode:hover, .dt-qr:hover { transform: translateY(-3px) scale(1.03); box-shadow: 0 16px 26px -16px rgba(15, 23, 42, 0.5); }
.dt-tile { animation: dt-rise 0.5s cubic-bezier(0.2, 0.8, 0.2, 1) both; animation-delay: 0.1s; transition: transform 0.25s ease, box-shadow 0.25s ease; } .dt-tile:hover { transform: translateY(-3px); box-shadow: 0 16px 26px -16px rgba(15, 23, 42, 0.45); }
.dt-tile-ico { transition: transform 0.3s cubic-bezier(0.3, 1.5, 0.5, 1); } .dt-tile:hover .dt-tile-ico { transform: rotate(-8deg) scale(1.12); }
.dt-card { animation: dt-rise 0.55s cubic-bezier(0.2, 0.8, 0.2, 1) both; animation-delay: 0.2s; } .dt-card ~ .dt-card { animation-delay: 0.3s; }
.dt-f { animation: dt-rise 0.45s cubic-bezier(0.2, 0.8, 0.2, 1) both; transition: transform 0.25s ease, box-shadow 0.25s ease; } .dt-f:nth-child(1) { animation-delay: 0.28s; } .dt-f:nth-child(2) { animation-delay: 0.33s; } .dt-f:nth-child(3) { animation-delay: 0.38s; } .dt-f:nth-child(4) { animation-delay: 0.43s; } .dt-f:nth-child(5) { animation-delay: 0.48s; } .dt-f:nth-child(6) { animation-delay: 0.53s; }
.dt-f:hover { transform: translateY(-2px); box-shadow: 0 14px 22px -16px rgba(15, 23, 42, 0.4); } .dt-f-ico { transition: transform 0.3s cubic-bezier(0.3, 1.5, 0.5, 1); } .dt-f:hover .dt-f-ico { transform: rotate(-10deg) scale(1.12); }
.dt-pill { animation: dt-pop 0.45s cubic-bezier(0.3, 1.6, 0.5, 1) both; transition: transform 0.2s ease; } .dt-pill:nth-child(1) { animation-delay: 0.5s; } .dt-pill:nth-child(2) { animation-delay: 0.58s; } .dt-pill:nth-child(3) { animation-delay: 0.66s; } .dt-pill:hover { transform: translateY(-2px) scale(1.04); }
.dt-wh { animation: dt-rise 0.5s cubic-bezier(0.2, 0.8, 0.2, 1) both; transition: transform 0.25s ease, box-shadow 0.25s ease; } .dt-wh:hover { transform: translateY(-3px); box-shadow: 0 18px 28px -18px rgba(15, 23, 42, 0.45); }
.dt-wh:nth-child(1) { animation-delay: 0.4s; } .dt-wh:nth-child(2) { animation-delay: 0.48s; } .dt-wh:nth-child(3) { animation-delay: 0.56s; } .dt-wh:nth-child(n+4) { animation-delay: 0.64s; }
.dt-share i { transform-origin: left; animation: dt-grow 0.9s cubic-bezier(0.2, 0.8, 0.2, 1) 0.6s both; }
.dt-none-ico { animation: dt-float 3s ease-in-out infinite; }
@keyframes dt-rise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
@keyframes dt-pop { from { opacity: 0; transform: scale(0.7); } to { opacity: 1; transform: none; } }
@keyframes dt-grow { from { transform: scaleX(0); } to { transform: scaleX(1); } }
@keyframes dt-sweep { to { transform: translateX(130%); } }
@keyframes dt-float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }
@media (prefers-reduced-motion: reduce) { .dt-hero, .dt-hero::after, .dt-tile, .dt-card, .dt-f, .dt-pill, .dt-wh, .dt-share i, .dt-none-ico { animation: none; } .dt-photo, .dt-barcode, .dt-qr, .dt-tile, .dt-f, .dt-wh, .dt-tile-ico, .dt-f-ico { transition: none; } }
@media print { .dt-hero, .dt-tile, .dt-card, .dt-f, .dt-pill, .dt-wh, .dt-share i { animation: none !important; } .dt-hero::after { display: none; } }
@media (max-width: 640px) { .dt-hero { padding: 0.8rem; gap: 10px; } .dt-codes { width: 100%; justify-content: flex-start; } .dt-kv { grid-template-columns: 1fr !important; } }

/* ---- polish: state badge, copy, initials, richer tiles, friendlier empty stock ---- */
.dt-hero { background: radial-gradient(circle at 8% 0%, rgba(99, 102, 241, 0.16), transparent 45%), radial-gradient(circle at 96% 100%, rgba(20, 184, 166, 0.14), transparent 45%), linear-gradient(135deg, rgba(224, 233, 250, 0.8), rgba(255, 251, 235, 0.65)); }
.dt-photo { position: relative; box-shadow: 0 10px 22px -14px rgba(15, 23, 42, 0.55), 0 0 0 3px rgba(255, 255, 255, 0.85); } html.dark .dt-photo { box-shadow: 0 10px 22px -14px rgba(0, 0, 0, 0.7), 0 0 0 3px rgba(255, 255, 255, 0.08); }
.dt-photo-zoom { position: absolute; inset: 0; display: grid; place-items: center; color: #fff; background: rgba(15, 23, 42, 0.45); opacity: 0; transition: opacity 0.2s ease; } .dt-photo:hover .dt-photo-zoom, .dt-photo:focus-visible .dt-photo-zoom { opacity: 1; }
.dt-photo-ph.is-initials { font-size: 1.5rem; font-weight: 900; letter-spacing: 0.02em; color: hsl(var(--h) 55% 34%); background: linear-gradient(135deg, hsl(var(--h) 80% 90%), hsl(calc(var(--h) + 40) 80% 82%)); } html.dark .dt-photo-ph.is-initials { color: hsl(var(--h) 80% 85%); background: linear-gradient(135deg, hsl(var(--h) 45% 24%), hsl(calc(var(--h) + 40) 45% 18%)); }
.dt-copy { display: inline-grid; place-items: center; width: 1.7rem; height: 1.7rem; border: 0; border-radius: 999px; cursor: pointer; color: #1d4ed8; background: rgba(37, 99, 235, 0.12); transition: transform 0.2s cubic-bezier(0.3, 1.5, 0.5, 1), background 0.2s ease, color 0.2s ease; } .dt-copy:hover { transform: scale(1.12); background: rgba(37, 99, 235, 0.22); } .dt-copy:active { transform: scale(0.9); } .dt-copy.is-done { color: #fff; background: #059669; animation: dt-pop 0.4s cubic-bezier(0.3, 1.6, 0.5, 1); }
.dt-state { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.2rem 0.7rem; border-radius: 999px; font-size: 0.74rem; font-weight: 800; color: var(--c); background: color-mix(in srgb, var(--c) 14%, transparent); box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--c) 28%, transparent); } .dt-state i { width: 0.5rem; height: 0.5rem; border-radius: 50%; background: var(--c); }
.dt-state.is-in { --c: #059669; } .dt-state.is-low { --c: #d97706; } .dt-state.is-out { --c: #e11d48; } .dt-state.is-untracked { --c: #64748b; } .dt-state.is-out i, .dt-state.is-low i { animation: dt-dot 1.6s ease-in-out 3; } @keyframes dt-dot { 50% { transform: scale(1.7); opacity: 0.5; } }
.dt-tile { animation-delay: calc(0.1s + var(--ti, 0) * 70ms); } .dt-tile.is-rose { --tone: #e11d48; --soft: rgba(225, 29, 72, 0.12); } .dt-tile.is-slate { --tone: #64748b; --soft: rgba(100, 116, 139, 0.14); }
.dt-none { gap: 6px; } .dt-none b { font-size: 1rem; color: var(--lg-ink); } .dt-none p { margin: 0; max-width: 26rem; font-size: 0.84rem; font-weight: 500; } .dt-none-go { margin-top: 6px; }
@media (max-width: 640px) { .dt-tiles { grid-template-columns: 1fr 1fr; gap: 8px; } .dt-tile { padding: 10px 12px; gap: 8px; } .dt-tile-ico { transform: scale(0.9); } }
@media (max-width: 340px) { .dt-tiles { grid-template-columns: 1fr; } }
@media (prefers-reduced-motion: reduce) { .dt-copy.is-done, .dt-state i { animation: none; } .dt-copy, .dt-photo-zoom { transition: none; } }
@media print { .dt-copy, .dt-photo-zoom { display: none !important; } }

.dt-tiles { grid-template-columns: repeat(auto-fit, minmax(min(140px, 100%), 1fr)); }
.dt-tile-v { overflow-wrap: anywhere; }
</style>

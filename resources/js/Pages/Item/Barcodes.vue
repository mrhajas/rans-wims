<template>
  <admin-layout :title="$t('Item Barcodes')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="hash" :title="$t('Print Barcodes')" :subtitle="labels.length + ' ' + (labels.length === 1 ? $t('label') : $t('labels')) + (items.length ? ' · ' + items.length + ' ' + (items.length === 1 ? $t('item') : $t('items')) : '')">
        <Link :href="route('items.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" />{{ $t('Items') }}</Link>
        <button type="button" class="lg-btn lg-btn-primary" :disabled="!labels.length" @click="print">
          <icons name="printer" class="w-4 h-4" />{{ $t('Print') }}
        </button>
      </page-header>

      <stat-tiles :items="tiles" />

      <!-- Find the items: search (item, code, category) and any number of categories -->
      <section class="lg-card print:hidden bc-find">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="search" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Choose the items') }}</h2>
            <p class="bc-sub">{{ $t('Search by item, code or category, and pick one or more categories. The labels below and the printed report show exactly this selection.') }}</p>
          </div>
          <button type="button" class="bc-reset" :disabled="!search && !catIds.length" @click="resetFilters"><icons name="cross" class="w-3.5 h-3.5" />{{ $t('Reset') }}</button>
        </header>
        <div class="bc-find-row">
          <label class="bc-field bc-searchbox" :class="{ 'has-value': search }">
            <span class="bc-field-ico bc-ico-green"><icons name="search" class="w-4 h-4" /></span>
            <input type="text" v-model="search" class="bc-searchbox-input" autocomplete="off" :placeholder="$t('Search item, code or category')" :aria-label="$t('Search item, code or category')" />
            <button v-if="search" type="button" class="bc-clear" :aria-label="$t('Clear the search')" @click.prevent="search = ''"><icons name="cross" class="w-3 h-3" /></button>
          </label>

          <div class="bc-cat" ref="catBox">
            <button type="button" class="bc-field bc-cat-btn" :class="{ 'is-on': catIds.length }" :aria-expanded="catOpen" @click="catOpen = !catOpen">
              <span class="bc-field-ico bc-ico-violet"><icons name="folder" class="w-4 h-4" /></span>
              <span class="bc-cat-txt">{{ catSummary }}</span>
              <span v-if="catIds.length" class="bc-count">{{ catIds.length }}</span>
              <span class="bc-cat-caret" :class="{ 'is-open': catOpen }" aria-hidden="true"></span>
            </button>
            <transition name="bc-pop">
              <div v-if="catOpen" class="bc-cat-panel">
                <div class="bc-cat-top">
                  <input type="search" v-model="catQuery" class="bc-cat-search" :placeholder="$t('Search categories')" :aria-label="$t('Search categories')" />
                  <button type="button" class="bc-cat-link" @click="catIds = categoryList.map(c => c.id)">{{ $t('All') }}</button>
                  <button type="button" class="bc-cat-link" @click="catIds = []">{{ $t('None') }}</button>
                </div>
                <ul class="bc-cat-list">
                  <li v-for="c in shownCategories" :key="c.id">
                    <label class="bc-cat-item" :class="{ 'is-on': catIds.includes(c.id) }">
                      <input type="checkbox" :value="c.id" v-model="catIds" />
                      <span class="bc-cat-box"><icons name="tick" class="w-3 h-3" /></span>
                      <span class="bc-cat-name">{{ c.name }}</span>
                    </label>
                  </li>
                  <li v-if="!shownCategories.length" class="bc-cat-none">{{ $t('No category matches.') }}</li>
                </ul>
              </div>
            </transition>
          </div>
        </div>
        <div v-if="chosenCategories.length" class="bc-chips">
          <button v-for="c in chosenCategories" :key="c.id" type="button" class="bc-chip" :aria-label="$t('Remove :name', { name: c.name })" @click="catIds = catIds.filter(i => i !== c.id)">
            {{ c.name }}<icons name="cross" class="w-3 h-3" />
          </button>
        </div>
      </section>

      <!-- Controls -->
      <section class="lg-card print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="hash" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Label design') }}</h2>
            <p class="bc-sub">{{ $t('Both styles print the same details: the item name (when shown) and the item code. Your choice is remembered next time.') }}</p>
          </div>
          <span class="bc-saved" :class="{ 'is-on': savedFlash }" aria-live="polite"><icons name="tick" class="w-3.5 h-3.5" />{{ $t('Saved') }}</span>
        </header>

        <div class="bc-styles" role="radiogroup" :aria-label="$t('Label style')">
          <button type="button" role="radio" :aria-checked="style === 'barcode'" class="bc-style" :class="{ 'is-on': style === 'barcode' }" @click="style = 'barcode'">
            <span class="bc-style-art">
              <svg viewBox="0 0 120 44" aria-hidden="true"><g fill="currentColor"><rect x="4" y="2" width="3" height="32" /><rect x="10" y="2" width="1.5" height="32" /><rect x="14" y="2" width="4.5" height="32" /><rect x="21" y="2" width="1.5" height="32" /><rect x="25" y="2" width="3" height="32" /><rect x="31" y="2" width="6" height="32" /><rect x="40" y="2" width="1.5" height="32" /><rect x="44" y="2" width="3" height="32" /><rect x="50" y="2" width="4.5" height="32" /><rect x="57" y="2" width="1.5" height="32" /><rect x="61" y="2" width="3" height="32" /><rect x="67" y="2" width="6" height="32" /><rect x="76" y="2" width="1.5" height="32" /><rect x="80" y="2" width="3" height="32" /><rect x="86" y="2" width="4.5" height="32" /><rect x="93" y="2" width="1.5" height="32" /><rect x="97" y="2" width="3" height="32" /><rect x="103" y="2" width="4.5" height="32" /><rect x="110" y="2" width="3" height="32" /></g><text x="60" y="43" text-anchor="middle" font-size="8" font-family="monospace" fill="currentColor">RSI-1139</text></svg>
            </span>
            <span class="bc-style-txt"><b>{{ $t('Barcode') }}</b><small>{{ $t('Classic lines, read by any scanner') }}</small></span>
            <span class="bc-tick"><icons name="tick" class="w-3.5 h-3.5" /></span>
          </button>
          <button type="button" role="radio" :aria-checked="style === 'qr'" class="bc-style" :class="{ 'is-on': style === 'qr' }" @click="style = 'qr'">
            <span class="bc-style-art bc-style-qr"><qr-mark value="RSI-1139" :label="$t('QR Code')" /></span>
            <span class="bc-style-txt"><b>{{ $t('QR Code') }}</b><small>{{ $t('Compact square, read by any phone camera') }}</small></span>
            <span class="bc-tick"><icons name="tick" class="w-3.5 h-3.5" /></span>
          </button>
        </div>

        <div class="lg-fields bc-fields">
          <div>
            <label class="bc-label" for="bc-copies">{{ $t('Copies per item') }}</label>
            <input id="bc-copies" type="number" min="1" max="100" v-model.number="copies" class="bc-input" />
          </div>

          <div>
            <label class="bc-label" for="bc-size">{{ $t('Label size') }}</label>
            <css-select id="bc-size" v-model="size" class="bc-input">
              <option value="sm">{{ $t('Small') }}</option>
              <option value="md">{{ $t('Medium') }}</option>
              <option value="lg">{{ $t('Large') }}</option>
            </css-select>
          </div>

          <label class="bc-switch" :class="{ 'is-on': showName }">
            <input type="checkbox" v-model="showName" class="bc-switch-input" />
            <span class="bc-switch-track"><span class="bc-switch-knob"><icons name="tick" class="w-3 h-3" /></span></span>
            <span class="bc-switch-txt"><b>{{ $t('Show name') }}</b><small>{{ showName ? $t('The item name is printed above the code') : $t('Only the code is printed') }}</small></span>
          </label>
        </div>
      </section>

      <!-- Empty state -->
      <div v-if="!items.length" class="lg-card text-center print:hidden">
        <p class="lg-empty">{{ search || catIds.length ? $t('No item matches this search and these categories.') : $t('There are no items to print barcodes for.') }}</p>
      </div>

      <!-- Label sheet -->
      <div v-else class="sheet">
        <div class="labels" :class="'size-' + size">
          <div v-for="label in shownLabels" :key="label.key" class="label" :class="'is-' + style">
            <div v-if="showName" class="label-name">{{ label.name }}</div>
            <div class="label-panel">
            <svg
              v-if="style === 'barcode'"
              class="barcode"
              jsbarcode-margin="0"
              jsbarcode-displayValue="true"
              jsbarcode-lineColor="#0b1530"
              jsbarcode-fontOptions="bold"
              jsbarcode-font="Menlo, Consolas, monospace"
              jsbarcode-textMargin="4"
              :jsbarcode-width="barWidth"
              :jsbarcode-height="barHeight"
              :jsbarcode-fontSize="fontSize"
              :jsbarcode-value="label.code"
              :jsbarcode-format="label.symbology"
            ></svg>
            <template v-else>
              <div class="label-qr" :style="{ width: qrSize + 'px' }"><qr-mark :value="label.code" :label="label.code" /></div>
              <div class="label-code">{{ label.code }}</div>
            </template>
            </div>
          </div>
        </div>
        <div v-if="labels.length > shownLabels.length" class="bc-more print:hidden">
          <span>{{ $t('Showing :a of :b labels. The print report includes all of them.', { a: shownLabels.length, b: labels.length }) }}</span>
          <button type="button" class="bc-more-btn" @click="showMore">{{ $t('Show more') }}</button>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import JsBarcode from 'jsbarcode';
import QrMark from '@/Shared/Item/QrMark.vue';
import TecButton from '@/Jetstream/Button.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { TecButton, AdminLayout, PageHeader, StatTiles, QrMark },

  props: {
    items: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    prefs: { type: Object, default: () => ({}) },
    categoryList: { type: Array, default: () => [] },
  },

  data() {
    const p = this.prefs || {};
    return {
      copies: Number(p.copies) >= 1 ? Number(p.copies) : 1,
      size: ['sm', 'md', 'lg'].includes(p.size) ? p.size : 'md',
      showName: p.show_name === undefined ? true : !!p.show_name,
      style: p.style === 'qr' ? 'qr' : 'barcode',
      search: (this.filters && this.filters.search) || '',
      catIds: ((this.filters && this.filters.categories) || []).map(Number),
      catOpen: false,
      catQuery: '',
      findTimer: null,
      visible: 48,
      savedFlash: false,
      saveTimer: null,
      flashTimer: null,
    };
  },

  computed: {
    tiles() {
      return [
        { label: this.labels.length === 1 ? this.$t('label') : this.$t('labels'), value: this.labels.length, icon: 'hash', tone: 'sky' },
        { label: this.items.length === 1 ? this.$t('item') : this.$t('items'), value: this.items.length, icon: 'collection', tone: 'emerald' },
        { label: this.$t('Style'), value: this.style === 'qr' ? this.$t('QR Code') : this.$t('Barcode'), icon: 'hash', tone: 'violet' },
        { label: this.$t('Label size'), value: { sm: this.$t('Small'), md: this.$t('Medium'), lg: this.$t('Large') }[this.size], icon: 'cog', tone: 'amber' },
      ];
    },
    barWidth() {
      return { sm: 1.4, md: 1.8, lg: 2.2 }[this.size];
    },
    barHeight() {
      return { sm: 38, md: 52, lg: 70 }[this.size];
    },
    fontSize() {
      return { sm: 12, md: 14, lg: 16 }[this.size];
    },
    shownCategories() {
      const q = this.catQuery.trim().toLowerCase();
      return q ? this.categoryList.filter(c => String(c.name).toLowerCase().includes(q)) : this.categoryList;
    },
    chosenCategories() {
      return this.categoryList.filter(c => this.catIds.includes(c.id));
    },
    catSummary() {
      if (!this.catIds.length) return this.$t('All categories');
      if (this.catIds.length === 1) return this.chosenCategories[0] ? this.chosenCategories[0].name : this.$t('1 category');
      return this.$t(':n categories', { n: this.catIds.length });
    },
    // a long list is drawn in pages (a barcode is drawn by script, so 1,000+ at once made the page slow); printing always covers all of them
    shownLabels() {
      return this.labels.slice(0, this.visible);
    },
    qrSize() {
      return { sm: 96, md: 124, lg: 160 }[this.size];
    },
    labels() {
      const count = Math.max(1, Math.min(100, parseInt(this.copies) || 1));
      const out = [];
      this.items.forEach(item => {
        for (let i = 0; i < count; i++) {
          out.push({
            key: item.id + '-' + i,
            name: item.name,
            code: item.code,
            symbology: item.symbology || 'CODE128',
          });
        }
      });
      return out;
    },
  },

  watch: {
    labels() {
      this.$nextTick(this.renderBarcodes);
    },
    items() {
      this.visible = 48;
    },
    size() {
      this.$nextTick(this.renderBarcodes);
      this.queueSave();
    },
    copies() {
      this.visible = 48;
      this.queueSave();
    },
    search() {
      this.queueFind(350);
    },
    catIds() {
      this.queueFind(150);
    },
    showName() {
      this.$nextTick(this.renderBarcodes);
      this.queueSave();
    },
    style() {
      this.$nextTick(this.renderBarcodes);
      this.queueSave();
    },
  },

  mounted() {
    this.renderBarcodes();
  },

  created() {
    this.onDocClick = e => {
      if (this.catOpen && this.$refs.catBox && !this.$refs.catBox.contains(e.target)) this.catOpen = false;
    };
    this.onKey = e => {
      if (e.key === 'Escape') this.catOpen = false;
    };
    document.addEventListener('click', this.onDocClick);
    document.addEventListener('keydown', this.onKey);
  },

  beforeUnmount() {
    document.removeEventListener('click', this.onDocClick);
    document.removeEventListener('keydown', this.onKey);
    clearTimeout(this.findTimer);
    clearTimeout(this.saveTimer);
    clearTimeout(this.flashTimer);
  },

  methods: {
    renderBarcodes() {
      try {
        JsBarcode('.barcode').init();
      } catch (e) {
        // Ignore invalid symbology/value combinations; the affected label stays blank.
      }
    },
    // Print opens the WIMS report of exactly what is on the page (style, size, name, copies); each print is logged
    // the page asks the server for the items again whenever the search or the categories change (only the items travel back)
    findParams() {
      const here = new URLSearchParams(window.location.search);
      const out = {};
      if (here.get('ids')) out.ids = here.get('ids');
      if (this.search.trim()) out.search = this.search.trim();
      if (this.catIds.length) out.categories = this.catIds.join(',');
      return out;
    },
    queueFind(wait) {
      clearTimeout(this.findTimer);
      this.findTimer = setTimeout(() => {
        router.get(this.route('items.barcodes'), this.findParams(), { preserveState: true, preserveScroll: true, replace: true, only: ['items', 'filters'] });
      }, wait);
    },
    showMore() {
      this.visible += 96;
      this.$nextTick(this.renderBarcodes);
    },
    resetFilters() {
      this.search = '';
      this.catIds = [];
      this.catQuery = '';
    },
    print() {
      // exactly what is on the page: the same search and categories (and picked items) as the labels shown
      const q = new URLSearchParams(this.findParams());
      q.set('style', this.style);
      q.set('size', this.size);
      q.set('show_name', this.showName ? 1 : 0);
      q.set('copies', Math.max(1, Math.min(100, parseInt(this.copies) || 1)));
      window.open(this.route('items.barcodes.print') + '?' + q.toString(), '_blank', 'noopener');
    },
    // the choices are remembered per person: saved a moment after the last change, quietly
    queueSave() {
      clearTimeout(this.saveTimer);
      this.saveTimer = setTimeout(() => {
        const copies = Math.max(1, Math.min(100, parseInt(this.copies) || 1));
        axios
          .post(this.route('items.barcodes.preferences'), { style: this.style, size: this.size, show_name: this.showName ? 1 : 0, copies })
          .then(() => {
            this.savedFlash = true;
            clearTimeout(this.flashTimer);
            this.flashTimer = setTimeout(() => (this.savedFlash = false), 1800);
          })
          .catch(() => {});
      }, 500);
    },
  },
};
</script>

<style scoped>
.bc-label { display: block; margin-bottom: 4px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.bc-input { width: 100%; height: 48px; padding: 0 14px; border-radius: 16px; font-size: 0.9rem; font-weight: 700; color: var(--lg-ink); background: linear-gradient(180deg, var(--lg-card), var(--lg-soft)); border: 1px solid var(--lg-line); box-shadow: 0 1px 0 rgba(255, 255, 255, 0.7) inset, 0 8px 16px -12px rgba(30, 41, 59, 0.45); }
.bc-input:focus { outline: none; border-color: #818cf8; box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.18); }
.bc-check { display: flex; align-items: center; gap: 8px; align-self: end; padding: 10px 14px; border-radius: 12px; font-size: 0.86rem; font-weight: 700; color: var(--lg-ink); background: var(--lg-soft); border: 1px solid var(--lg-line); cursor: pointer; }
.bc-find-row { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); gap: 12px; padding: 4px 16px 10px; align-items: start; }
/* the two fields of "Choose the items" share one look: same height, round icon chip, soft lift, coloured focus ring */
.bc-field { position: relative; display: flex; align-items: center; gap: 10px; box-sizing: border-box; width: 100%; height: 48px; min-height: 0; padding: 0 12px 0 7px; border-radius: 16px; color: var(--lg-ink); background: linear-gradient(180deg, var(--lg-card), var(--lg-soft)); border: 1px solid var(--lg-line); box-shadow: 0 1px 0 rgba(255, 255, 255, 0.7) inset, 0 8px 16px -12px rgba(30, 41, 59, 0.45); transition: border-color 0.16s ease, box-shadow 0.16s ease, transform 0.16s ease; }
.bc-field:hover { transform: translateY(-1px); }
.bc-field-ico { flex: none; display: grid; place-items: center; width: 32px; height: 32px; border-radius: 11px; color: #fff; }
.bc-ico-green { background: linear-gradient(145deg, #34d399, #059669); box-shadow: 0 8px 14px -8px rgba(5, 150, 105, 0.85); }
.bc-ico-violet { background: linear-gradient(145deg, #a78bfa, #6d28d9); box-shadow: 0 8px 14px -8px rgba(109, 40, 217, 0.85); }
.bc-searchbox:focus-within { border-color: #10b981; box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.18), 0 10px 18px -12px rgba(5, 150, 105, 0.6); }
.bc-searchbox.has-value { border-color: #6ee7b7; background: linear-gradient(180deg, rgba(209, 250, 229, 0.55), var(--lg-card)); }
.bc-searchbox-input { flex: 1; min-width: 0; height: 100%; padding: 0; border: 0; background: transparent; font-size: 0.9rem; font-weight: 600; color: var(--lg-ink); box-shadow: none; }
.bc-searchbox-input:focus { outline: none; box-shadow: none; }
.bc-searchbox-input::placeholder { font-weight: 500; color: var(--lg-faint, #94a3b8); }
.bc-clear { flex: none; display: grid; place-items: center; width: 24px; height: 24px; min-height: 0; padding: 0; border: 0; border-radius: 9999px; color: #fff; background: linear-gradient(145deg, #94a3b8, #64748b); cursor: pointer; transition: transform 0.15s ease, background 0.15s ease; }
.bc-clear:hover { transform: scale(1.12); background: linear-gradient(145deg, #fb7185, #e11d48); }
.bc-reset { display: inline-flex; align-items: center; gap: 6px; min-height: 0; height: 34px; padding: 0 14px 0 11px; border: 0; border-radius: 9999px; font-size: 0.76rem; font-weight: 800; color: #fff; background: linear-gradient(145deg, #fb7185, #e11d48); box-shadow: 0 10px 18px -10px rgba(225, 29, 72, 0.9); cursor: pointer; transition: transform 0.15s ease, opacity 0.15s ease, filter 0.15s ease; }
.bc-reset:hover:not(:disabled) { transform: translateY(-1px); filter: brightness(1.06); }
.bc-reset:disabled { cursor: default; opacity: 0.4; filter: grayscale(0.6); box-shadow: none; }
.bc-cat { position: relative; }
.bc-cat-btn { font-size: 0.9rem; font-weight: 700; text-align: left; cursor: pointer; }
.bc-cat-btn:hover, .bc-cat-btn[aria-expanded='true'] { border-color: #a78bfa; box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.16), 0 10px 18px -12px rgba(109, 40, 217, 0.6); }
.bc-cat-btn.is-on { color: #4c1d95; border-color: #c4b5fd; background: linear-gradient(180deg, rgba(237, 233, 254, 0.7), var(--lg-card)); }
.bc-count { flex: none; min-width: 22px; height: 22px; padding: 0 7px; display: grid; place-items: center; border-radius: 9999px; font-size: 0.72rem; font-weight: 800; color: #fff; background: linear-gradient(145deg, #8b5cf6, #5b21b6); }
.bc-cat-txt { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.bc-cat-caret { flex: none; width: 8px; height: 8px; margin: 0 4px 3px 0; border: solid currentColor; border-width: 0 2px 2px 0; transform: rotate(45deg); transition: transform 0.2s ease, margin 0.2s ease; } .bc-cat-caret.is-open { transform: rotate(225deg); margin: 3px 4px 0 0; }
.bc-cat-panel { position: absolute; z-index: 40; left: 0; right: 0; top: calc(100% + 8px); padding: 10px; border-radius: 16px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: 0 24px 44px -18px rgba(30, 27, 75, 0.5); }
.bc-cat-top { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.bc-cat-search { flex: 1; min-width: 0; padding: 7px 11px; border-radius: 10px; font-size: 0.82rem; color: var(--lg-ink); background: var(--lg-soft); border: 1px solid var(--lg-line); }
.bc-cat-search:focus { outline: none; border-color: #818cf8; }
.bc-cat-link { min-height: 0; padding: 4px 8px; border: 0; background: none; font-size: 0.74rem; font-weight: 800; color: #4f46e5; cursor: pointer; }
.bc-cat-list { margin: 0; padding: 0; list-style: none; max-height: 260px; overflow-y: auto; display: grid; gap: 2px; }
.bc-cat-item { display: flex; align-items: center; gap: 10px; padding: 7px 8px; border-radius: 10px; font-size: 0.84rem; font-weight: 600; color: var(--lg-ink); cursor: pointer; transition: background 0.12s ease; }
.bc-cat-item:hover { background: var(--lg-soft); }
.bc-cat-item.is-on { background: rgba(99, 102, 241, 0.1); color: #4338ca; }
.bc-cat-item input { position: absolute; opacity: 0; pointer-events: none; }
.bc-cat-box { flex: none; display: grid; place-items: center; width: 18px; height: 18px; border-radius: 6px; border: 2px solid #cbd5e1; color: transparent; transition: all 0.15s ease; }
.bc-cat-item.is-on .bc-cat-box { color: #fff; border-color: #4f46e5; background: linear-gradient(145deg, #6366f1, #4338ca); }
.bc-cat-item input:focus-visible + .bc-cat-box { box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.3); }
.bc-cat-none { padding: 12px; text-align: center; font-size: 0.8rem; color: var(--lg-mute, #64748b); }
.bc-pop-enter-active, .bc-pop-leave-active { transition: opacity 0.14s ease, transform 0.14s ease; }
.bc-pop-enter-from, .bc-pop-leave-to { opacity: 0; transform: translateY(-4px); }
.bc-chips { display: flex; flex-wrap: wrap; gap: 6px; padding: 0 16px 14px; }
.bc-chip { display: inline-flex; align-items: center; gap: 6px; min-height: 0; padding: 4px 8px 4px 11px; border: 0; border-radius: 9999px; font-size: 0.74rem; font-weight: 800; color: #3730a3; background: linear-gradient(135deg, #e0e7ff, #ede9fe); box-shadow: 0 4px 8px -4px rgba(79, 70, 229, 0.5); cursor: pointer; }
.bc-chip:hover { background: linear-gradient(135deg, #c7d2fe, #ddd6fe); }
/* the browser's own clear cross is replaced by ours */
.bc-searchbox-input::-webkit-search-cancel-button { display: none; }
@media (max-width: 720px) { .bc-find-row { grid-template-columns: 1fr; } }
/* Show name: a switch with a line saying what it does */
.bc-switch { display: flex; align-items: center; gap: 12px; align-self: end; padding: 9px 14px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); cursor: pointer; transition: border-color 0.18s ease, background 0.18s ease, box-shadow 0.18s ease; }
.bc-switch:hover { border-color: #93c5fd; }
.bc-switch.is-on { border-color: #60a5fa; background: linear-gradient(135deg, rgba(219, 234, 254, 0.8), var(--lg-soft)); box-shadow: 0 10px 18px -14px rgba(37, 99, 235, 0.8); }
.bc-switch-input { position: absolute; opacity: 0; pointer-events: none; }
.bc-switch-track { position: relative; flex: none; width: 44px; height: 26px; border-radius: 9999px; background: #cbd5e1; box-shadow: inset 0 1px 3px rgba(15, 23, 42, 0.25); transition: background 0.2s ease; }
.bc-switch.is-on .bc-switch-track { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
.bc-switch-knob { position: absolute; top: 3px; left: 3px; display: grid; place-items: center; width: 20px; height: 20px; border-radius: 9999px; color: transparent; background: #fff; box-shadow: 0 2px 5px rgba(15, 23, 42, 0.35); transition: transform 0.22s cubic-bezier(0.34, 1.56, 0.64, 1), color 0.15s ease; }
.bc-switch.is-on .bc-switch-knob { transform: translateX(18px); color: #1d4ed8; }
.bc-switch-input:focus-visible + .bc-switch-track { box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.4); }
.bc-switch-txt { display: grid; gap: 1px; min-width: 0; }
.bc-switch-txt b { font-size: 0.86rem; font-weight: 800; color: var(--lg-ink); }
.bc-switch-txt small { font-size: 0.72rem; line-height: 1.25; color: var(--lg-mute, #64748b); }
.bc-more { display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 10px; margin-top: 14px; font-size: 0.8rem; font-weight: 600; color: #1e3a8a; }
.bc-more-btn { min-height: 0; padding: 7px 18px; border: 0; border-radius: 9999px; font-size: 0.8rem; font-weight: 800; color: #fff; background: linear-gradient(145deg, #3b82f6, #1d4ed8); box-shadow: 0 10px 18px -10px rgba(29, 78, 216, 0.9); cursor: pointer; transition: transform 0.15s ease; }
.bc-more-btn:hover { transform: translateY(-1px); }
.bc-sub { margin: 2px 0 0; font-size: 0.78rem; color: var(--lg-mute, #64748b); }
.bc-saved { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 9999px; font-size: 0.7rem; font-weight: 800; color: #047857; background: rgba(16, 185, 129, 0.14); opacity: 0; transform: translateY(2px); transition: opacity 0.2s ease, transform 0.2s ease; }
.bc-saved.is-on { opacity: 1; transform: none; }
.bc-styles { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; padding: 4px 16px 6px; }
.bc-style { position: relative; display: flex; align-items: center; gap: 14px; min-height: 0; padding: 12px 14px; text-align: left; border-radius: 18px; border: 2px solid var(--lg-line); background: var(--lg-card); color: var(--lg-ink); cursor: pointer; transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease; }
.bc-style:hover { transform: translateY(-1px); border-color: #a5b4fc; }
.bc-style:nth-child(1) .bc-style-art { background: linear-gradient(135deg, #dbeafe, #fff); border-color: #bfdbfe; }
.bc-style:nth-child(2) .bc-style-art { background: linear-gradient(135deg, #ede9fe, #fff); border-color: #ddd6fe; }
.bc-style:nth-child(1).is-on { border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.15), 0 14px 26px -16px rgba(37, 99, 235, 0.75); background: linear-gradient(135deg, rgba(219, 234, 254, 0.9), rgba(255, 255, 255, 0.4)); }
.bc-style:nth-child(1) .bc-tick { background: #2563eb; }
.bc-style:nth-child(2).is-on { border-color: #7c3aed; box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.15), 0 14px 26px -16px rgba(124, 58, 237, 0.75); background: linear-gradient(135deg, rgba(237, 233, 254, 0.9), rgba(255, 255, 255, 0.4)); }
.bc-style:nth-child(2) .bc-tick { background: #7c3aed; }
.bc-style.is-on { border-color: #4f46e5; box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.14), 0 14px 26px -16px rgba(79, 70, 229, 0.7); background: linear-gradient(135deg, rgba(238, 242, 255, 0.9), rgba(255, 255, 255, 0.4)); }
html.dark .bc-style.is-on { background: linear-gradient(135deg, rgba(67, 56, 202, 0.25), rgba(15, 23, 42, 0.2)); }
.bc-style-art { flex: none; display: grid; place-items: center; width: 118px; height: 66px; padding: 6px 8px; border-radius: 12px; background: #fff; color: #0f172a; border: 1px solid #e5e7eb; }
.bc-style-art svg { width: 100%; height: 100%; }
.bc-style-qr { width: 66px; padding: 3px; }
.bc-style-txt { display: grid; gap: 2px; min-width: 0; }
.bc-style-txt b { font-size: 0.95rem; font-weight: 800; }
.bc-style-txt small { font-size: 0.74rem; color: var(--lg-mute, #64748b); line-height: 1.3; }
.bc-tick { position: absolute; top: 10px; right: 10px; display: grid; place-items: center; width: 22px; height: 22px; border-radius: 9999px; color: #fff; background: #4f46e5; opacity: 0; transform: scale(0.6); transition: opacity 0.18s ease, transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1); }
.bc-style.is-on .bc-tick { opacity: 1; transform: scale(1); }
.bc-fields { padding-top: 12px; }
@media (max-width: 640px) { .bc-styles { grid-template-columns: 1fr; } .bc-style-art { width: 96px; } }
.bc-check input { width: 16px; height: 16px; border-radius: 4px; accent-color: #4f46e5; }

.sheet {
  background: radial-gradient(circle at 12% 0%, rgba(59, 130, 246, 0.16), transparent 45%), radial-gradient(circle at 92% 100%, rgba(139, 92, 246, 0.14), transparent 45%), var(--lg-soft);
  border: 1px solid #bfdbfe;
  padding: 1.1rem;
  border-radius: 22px;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6), 0 18px 34px -24px rgba(30, 58, 138, 0.55);
}
html.dark .sheet { border-color: rgba(96, 165, 250, 0.3); }
.labels {
  display: grid;
  gap: 0.9rem;
  justify-content: center;
}
.size-sm { grid-template-columns: repeat(auto-fill, 168px); }
.size-md { grid-template-columns: repeat(auto-fill, 222px); }
.size-lg { grid-template-columns: repeat(auto-fill, 284px); }
/* The labels are blue, with the code in a white panel: they stay the same in every theme because they are what gets printed and scanned. */
.label {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  justify-content: flex-start;
  gap: 0.55rem;
  padding: 0.85rem 0.7rem 0.8rem;
  text-align: center;
  color: #fff;
  border-radius: 1rem;
  border: 1px solid rgba(191, 219, 254, 0.55);
  background: linear-gradient(160deg, #2563eb 0%, #1d4ed8 45%, #1e3a8a 100%);
  box-shadow: 0 14px 24px -12px rgba(30, 58, 138, 0.75), 0 3px 6px rgba(30, 58, 138, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.25);
  transition: transform 0.18s ease, box-shadow 0.18s ease;
}
.label:hover { transform: translateY(-2px); box-shadow: 0 20px 30px -12px rgba(30, 58, 138, 0.8), 0 4px 8px rgba(30, 58, 138, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.25); }
.label-name {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  min-height: 2.5em;
  padding: 0 0.15rem;
  font-size: 0.8rem;
  line-height: 1.25;
  font-weight: 800;
  letter-spacing: 0.01em;
  color: #fff;
  text-shadow: 0 1px 2px rgba(15, 23, 42, 0.45);
}
.label-panel {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.3rem;
  padding: 0.7rem 0.75rem 0.6rem;
  border-radius: 0.75rem;
  background: #fff;
  color: #0b1530;
  box-shadow: 0 8px 16px -6px rgba(2, 6, 23, 0.55), 0 2px 4px rgba(2, 6, 23, 0.25);
}
.label-qr { max-width: 100%; filter: drop-shadow(0 4px 8px rgba(49, 46, 129, 0.18)); }
.label-code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8rem; font-weight: 800; letter-spacing: 0.08em; color: #0b1530; }
.size-sm .label-code { font-size: 0.7rem; } .size-lg .label-code { font-size: 0.9rem; }
.label .barcode {
  display: block;
  max-width: 100%;
  height: auto;
}
@media (max-width: 520px) {
  .size-sm, .size-md, .size-lg { grid-template-columns: repeat(auto-fill, minmax(min(150px, 100%), 1fr)); }
}

@media print {
  .sheet { background: #fff; border: 0; padding: 0; border-radius: 0; box-shadow: none; }
  .labels { justify-content: flex-start; gap: 6px; }
  .label { page-break-inside: avoid; break-inside: avoid; box-shadow: none; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>

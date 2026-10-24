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

      <!-- Controls -->
      <section class="lg-card print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="cog" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Label size') }}</h2>
          </div>
        </header>
        <div class="lg-fields">
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

          <label class="bc-check">
            <input type="checkbox" v-model="showName" />
            <span>{{ $t('Show name') }}</span>
          </label>
        </div>
      </section>

      <!-- Empty state -->
      <div v-if="!items.length" class="lg-card text-center print:hidden">
        <p class="lg-empty">{{ $t('There are no items to print barcodes for.') }}</p>
      </div>

      <!-- Label sheet -->
      <div v-else class="sheet">
        <div class="labels" :class="'size-' + size">
          <div v-for="label in labels" :key="label.key" class="label">
            <div v-if="showName" class="label-name">{{ label.name }}</div>
            <svg
              class="barcode"
              jsbarcode-margin="0"
              jsbarcode-displayValue="true"
              :jsbarcode-width="barWidth"
              :jsbarcode-height="barHeight"
              :jsbarcode-fontSize="fontSize"
              :jsbarcode-value="label.code"
              :jsbarcode-format="label.symbology"
            ></svg>
          </div>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import JsBarcode from 'jsbarcode';
import TecButton from '@/Jetstream/Button.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: { TecButton, AdminLayout, PageHeader, StatTiles },

  props: {
    items: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
  },

  data() {
    return { copies: 1, size: 'md', showName: true };
  },

  computed: {
    tiles() {
      return [
        { label: this.labels.length === 1 ? this.$t('label') : this.$t('labels'), value: this.labels.length, icon: 'hash', tone: 'indigo' },
        { label: this.items.length === 1 ? this.$t('item') : this.$t('items'), value: this.items.length, icon: 'collection', tone: 'navy' },
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
    size() {
      this.$nextTick(this.renderBarcodes);
    },
    showName() {
      this.$nextTick(this.renderBarcodes);
    },
  },

  mounted() {
    this.renderBarcodes();
  },

  methods: {
    renderBarcodes() {
      try {
        JsBarcode('.barcode').init();
      } catch (e) {
        // Ignore invalid symbology/value combinations; the affected label stays blank.
      }
    },
    print() {
      window.print();
    },
  },
};
</script>

<style scoped>
.bc-label { display: block; margin-bottom: 4px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.bc-input { width: 100%; padding: 9px 12px; border-radius: 12px; font-size: 0.86rem; color: var(--lg-ink); background: var(--lg-card); border: 1px solid var(--lg-line); }
.bc-input:focus { outline: none; border-color: #818cf8; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.18); }
.bc-check { display: flex; align-items: center; gap: 8px; align-self: end; padding: 10px 14px; border-radius: 12px; font-size: 0.86rem; font-weight: 700; color: var(--lg-ink); background: var(--lg-soft); border: 1px solid var(--lg-line); cursor: pointer; }
.bc-check input { width: 16px; height: 16px; border-radius: 4px; accent-color: #4f46e5; }

.sheet {
  background: var(--lg-soft);
  border: 1px solid var(--lg-line);
  padding: 1rem;
  border-radius: 22px;
}
.labels {
  display: grid;
  gap: 0.75rem;
  justify-content: center;
}
.size-sm {
  grid-template-columns: repeat(auto-fill, 160px);
}
.size-md {
  grid-template-columns: repeat(auto-fill, 210px);
}
.size-lg {
  grid-template-columns: repeat(auto-fill, 270px);
}
/* Labels stay white in every theme: they are what gets printed and scanned. */
.label {
  background: #fff;
  color: #0f172a;
  border: 1px solid #e5e7eb;
  border-radius: 0.75rem;
  padding: 0.5rem;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}
.label-name {
  width: 100%;
  font-size: 0.78rem;
  font-weight: 600;
  margin-bottom: 0.25rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.label .barcode {
  max-width: 100%;
  height: auto;
}
@media (max-width: 520px) {
  .size-sm, .size-md, .size-lg { grid-template-columns: repeat(auto-fill, minmax(min(140px, 100%), 1fr)); }
}

@media print {
  .sheet {
    background: #fff;
    border: 0;
    padding: 0;
    border-radius: 0;
  }
  .labels {
    justify-content: flex-start;
    gap: 0;
  }
  .label {
    border: 1px dashed #d1d5db;
    border-radius: 0.375rem;
    page-break-inside: avoid;
    break-inside: avoid;
  }
}
</style>

<template>
  <div v-if="checkin" class="lg-page dt-doc print:h-full print:block print:m-0 print:pt-0 print:bg-white">
    <div class="hidden print:flex items-center justify-between gap-6 mb-5 pb-4 border-b border-slate-200">
      <div class="flex items-center gap-4">
        <img src="/images/logo.png" alt="RANS Logo" class="h-16 w-auto" />
        <div>
          <div class="text-2xl font-bold text-slate-800">Checkin</div>
          <div class="text-sm text-slate-500">RANS WIMS</div>
        </div>
      </div>
      <div class="text-right text-sm text-slate-600">
        <div class="font-semibold text-slate-800">{{ checkin.reference }}</div>
        <div>{{ checkin.warehouse.name }} ({{ checkin.warehouse.code }})</div>
      </div>
    </div>

    <div class="relative z-[1]">
      <trashed-message v-if="checkin.draft == 1" class="mb-4 h-12 print:hidden" :action="false" icon="info">
        {{ $t('This record is still a draft.') }}
      </trashed-message>
      <trashed-message v-if="checkin.deleted_at" class="mb-4 h-12 print:hidden" :action="false">
        {{ $t('This record has been deleted.') }}
      </trashed-message>

      <!-- Hero -->
      <section class="dt-hero print:hidden">
        <span class="lg-card-ico lg-ico-emerald dt-hero-ico"><icons name="in" class="w-6 h-6" /></span>
        <div class="min-w-0 flex-1">
          <div class="dt-kicker">{{ $t('Items Delivered To Warehouse') }}</div>
          <h2 class="dt-ref">{{ checkin.reference }}</h2>
          <div class="dt-chips">
            <span class="lg-chip lg-chip-blue"><icons name="building" class="w-3 h-3" />{{ checkin.warehouse.name }} ({{ checkin.warehouse.code }})</span>
            <span class="lg-chip lg-chip-slate"><icons name="calendar" class="w-3 h-3" />{{ $date(checkin.date) }}</span>
            <span v-if="checkin.user" class="lg-chip lg-chip-violet"><icons name="user" class="w-3 h-3" />{{ checkin.user.name }}</span>
            <span v-if="start && end" class="lg-chip lg-chip-cyan">{{ start }} &ndash; {{ end }}</span>
          </div>
        </div>
        <div class="dt-barcode">
          <svg id="barcode" class="mx-auto"></svg>
        </div>
      </section>

      <div class="hidden print:block mb-4">
        <svg id="barcode-print" class="mx-auto"></svg>
      </div>

      <stat-tiles :items="tiles" />

      <!-- Info -->
      <div class="dt-two">
        <section class="lg-card dt-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h3 class="lg-card-title">{{ $t('Details') }}</h3>
            </div>
          </header>
          <div class="dt-kv dt-kv-1">
            <div>
              <span class="dt-k">{{ $t('Reference') }}</span>
              <span class="dt-v">{{ checkin.reference }}</span>
            </div>
            <div>
              <span class="dt-k">{{ $t('Date') }}</span>
              <span class="dt-v">{{ $date(checkin.date) }}</span>
            </div>
            <div v-if="checkin.user">
              <span class="dt-k">{{ $t('Person Responsible') }}</span>
              <span class="dt-v">{{ checkin.user.name }}</span>
            </div>
          </div>
        </section>

        <section class="lg-card dt-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="user" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h3 class="lg-card-title">{{ $t('For') }}</h3>
            </div>
          </header>
          <div class="dt-kv dt-kv-1">
            <div>
              <span class="dt-k">{{ $t('Name') }}</span>
              <span class="dt-v">{{ checkin.contact.name }}</span>
            </div>
            <div v-if="checkin.contact.phone">
              <span class="dt-k">{{ $t('Phone') }}</span>
              <span class="dt-v">{{ checkin.contact.phone }}</span>
            </div>
            <div v-if="checkin.contact.email">
              <span class="dt-k">{{ $t('Email') }}</span>
              <span class="dt-v">{{ checkin.contact.email }}</span>
            </div>
          </div>
        </section>
      </div>

      <!-- Items -->
      <section class="lg-card dt-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="collection" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h3 class="lg-card-title">{{ $t('Items') }}</h3>
            <p class="lg-card-sub">{{ (checkin.items || []).length }} {{ $t('items') }}</p>
          </div>
        </header>
        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Item') }}</th>
                <th v-if="$settings.track_weight" class="num">{{ $t('Unit') }}</th>
                <th class="num">{{ $t('Quantity') }}</th>
                <th class="num">{{ $t('Total') }}</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="item in checkin.items" :key="'i_' + item.id">
                <template v-if="item.variations.length">
                  <tr v-for="variation in item.variations" :key="'v_' + variation.id">
                    <td :data-label="$t('Item')">
                      <div class="dt-item">
                        <img v-if="item.item.photo" class="dt-thumb" :src="'/assets/' + item.item.photo" alt="" @click="showPhoto(item.item.photo)" />
                        <div class="min-w-0">
                          <div class="lg-main">{{ $t(item.item.name) }} <span class="dt-code">({{ item.item.code }})</span></div>
                          <div class="lg-subtext"><span v-html="$meta(variation.meta)"></span></div>
                        </div>
                      </div>
                    </td>
                    <td v-if="$settings.track_weight" class="num" :data-label="$t('Unit')">
                      <span v-if="item.item.track_weight">{{ $number(variation.pivot.weight) }} {{ $settings.weight_unit }}</span>
                    </td>
                    <td class="num" :data-label="$t('Quantity')">{{ $number(variation.pivot.quantity) }} {{ item.unit ? item.unit.code : '' }}</td>
                    <td class="num dt-hide-m"></td>
                  </tr>
                </template>
                <tr v-else>
                  <td :data-label="$t('Item')">
                    <div class="dt-item">
                      <img v-if="item.item.photo" class="dt-thumb" :src="'/assets/' + item.item.photo" alt="" @click="showPhoto(item.item.photo)" />
                      <div class="lg-main">{{ $t(item.item.name) }} <span class="dt-code">({{ item.item.code }})</span></div>
                    </div>
                  </td>
                  <td v-if="$settings.track_weight" class="num dt-muted" :data-label="$t('Unit')">{{ item.unit ? item.unit.code : '' }}</td>
                  <td class="num" :data-label="$t('Quantity')">{{ $number(item.quantity) }}</td>
                  <td class="num lg-main" :data-label="$t('Total')">{{ $number(item.quantity * item.price) }} Tsh</td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
        <div v-if="(checkin.items || []).length" class="dt-total">
          <span>{{ $t('Total') }}</span>
          <b>{{ $number(grandTotal) }} Tsh</b>
        </div>
      </section>

      <!-- Attachments -->
      <section v-if="checkin.attachments && checkin.attachments.length" class="lg-card dt-card print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h3 class="lg-card-title">{{ $t('Attachments') }}</h3>
          </div>
        </header>
        <ul class="dt-files">
          <li v-for="attachment in checkin.attachments" :key="attachment.id" class="dt-file">
            <icons name="clip" class="w-4 h-4 flex-none dt-muted" />
            <span class="dt-file-name">{{ attachment.title }}</span>
            <a class="lg-btn lg-btn-soft" :href="route('media.download', attachment.id)">
              <icons name="download" class="w-3.5 h-3.5" />{{ $t('Download') }}
            </a>
          </li>
        </ul>
      </section>

      <section v-if="checkin.details" class="lg-card dt-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="doc-text" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h3 class="lg-card-title">{{ $t('Notes') }}</h3>
          </div>
        </header>
        <p class="dt-notes">{{ $t(checkin.details) }}</p>
      </section>

      <div class="pt-2 pb-4 w-full text-center text-sm text-gray-500 hidden print:block">
        {{ $t('This is computer generated document, no signature required.') }}
      </div>
    </div>

    <modal :show="show" max-width="2xl" :closeable="true" :transparent="true" @close="hidePhoto()">
      <div class="print:block print:h-full">
        <img class="block max-w-full mx-auto rounded-md" :src="photo" />
      </div>
    </modal>
  </div>
</template>

<script>
import JsBarcode from 'jsbarcode';
import Modal from '@/Jetstream/Modal.vue';
import TrashedMessage from '@/Shared/TrashedMessage.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  props: { checkin: Object },

  components: { Modal, TrashedMessage, StatTiles },

  data() {
    return {
      photo: null,
      show: false,
      loading: false,
      start: '',
      end: '',
    };
  },

  computed: {
    // Sum of the line totals that are shown in the table (variation rows carry no price).
    grandTotal() {
      return ((this.checkin.items) || []).reduce((sum, row) => (row.variations.length ? sum : sum + Number(row.price || 0) * Number(row.quantity || 0)), 0);
    },
    tiles() {
      return [
        { label: this.$t('Items'), value: (this.checkin.items || []).length, icon: 'collection', tone: 'emerald' },
        { label: this.$t('Total'), value: this.$number(this.grandTotal) + ' Tsh', icon: 'cash', tone: 'emerald' },
        { label: this.$t('Attachments'), value: (this.checkin.attachments || []).length, icon: 'clip', tone: 'slate' },
      ];
    },
  },

  mounted() {
    const params = new URLSearchParams(window.location.search);
    const startDate = params.get('start_date');
    const endDate = params.get('end_date');
    if (startDate) {
      this.start = this.formatDate(startDate);
    }
    if (endDate) {
      this.end = this.formatDate(endDate);
    }

    this.renderBarcode();
  },

  updated() {
    this.renderBarcode();
  },

  methods: {
    renderBarcode() {
      JsBarcode('#barcode', this.checkin.reference, { width: 1.4, margin: 0, fontSize: 0, height: 32, format: 'CODE128' });
      JsBarcode('#barcode-print', this.checkin.reference, { width: 1, margin: 0, fontSize: 0, height: 20, format: 'CODE128' });
    },
    showPhoto(photo) {
      this.show = true;
      this.photo = '/assets/' + photo;
    },
    hidePhoto() {
      this.show = false;
      this.photo = null;
    },
    formatDate(dateStr) {
      const options = { year: 'numeric', month: 'short', day: 'numeric' };
      return new Intl.DateTimeFormat('en-US', options).format(new Date(dateStr));
    },
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


.dt-two { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(min(300px, 100%), 1fr)); }
.dt-two > .lg-card { min-width: 0; }
.dt-kv-1 { grid-template-columns: minmax(0, 1fr) !important; }
.dt-item { display: flex; align-items: center; gap: 10px; min-width: 0; }
.dt-thumb { flex: none; width: 34px; height: 34px; border-radius: 10px; object-fit: cover; cursor: zoom-in; border: 1px solid var(--lg-line); }
.dt-kv { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr)); }
.dt-kv > div { display: flex; flex-direction: column; align-items: flex-start; gap: 3px; min-width: 0; padding: 10px 14px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.dt-k { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.dt-v { font-size: 0.9rem; font-weight: 700; color: var(--lg-ink); overflow-wrap: anywhere; }

.dt-code { font-weight: 600; color: var(--lg-faint); }
.dt-muted { color: var(--lg-faint); }
.dt-hide-m { }
.dt-total { display: flex; align-items: center; justify-content: flex-end; gap: 14px; margin-top: 12px; padding: 10px 16px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); font-size: 0.78rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute); }
.dt-total b { font-size: 1.05rem; letter-spacing: 0; text-transform: none; color: var(--lg-ink); font-variant-numeric: tabular-nums; }

.dt-files { display: flex; flex-direction: column; gap: 8px; }
.dt-file { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.dt-file-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.85rem; font-weight: 600; color: var(--lg-ink); }
.dt-notes { font-size: 0.88rem; line-height: 1.6; white-space: pre-line; color: var(--lg-ink); }

@media (max-width: 767.98px) {
  .dt-item { justify-content: flex-end; text-align: right; }
  .dt-hide-m { display: none !important; }
  .dt-barcode { width: 100%; text-align: center; }
}
@media print {
  .dt-doc .lg-card { padding: 12px 14px; border-radius: 0; box-shadow: none; border: 1px solid #e2e8f0; background: #fff; }
  .dt-doc .lg-table-wrap { border: 0; }
}
</style>

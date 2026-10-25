<template>
  <span v-if="count || item.bincard" class="ipc">
    <!-- Item Photo: the face and the item's other photos (up to three), all in one window with Previous / Next -->
    <button v-if="count" type="button" class="ipc-chip is-photo lg-tip tip-left" :data-tip="$t('Open all photos of this item. Use Next and Previous to move between them.')" :aria-label="$t('Open the photos of :n', { n: item.name })" @click.stop="$emit('photos')">
      <span class="ipc-img"><img :src="cover" alt="" class="ipc-pic" loading="lazy" /></span>
      <span class="ipc-txt"><b>{{ $t('Item Photo') }}</b><small>{{ count === 1 ? $t('1 photo') : $t(':n photos', { n: count }) }}</small></span>
      <icons name="zoom-in" class="w-3.5 h-3.5 ipc-ico" />
    </button>
    <!-- BinCard: only when one is assigned; its own window shows just the BinCard photo -->
    <button v-if="item.bincard" type="button" class="ipc-chip is-card lg-tip tip-left" :data-tip="$t('Open the BinCard photo of this item.')" :aria-label="$t('Open the BinCard of :n', { n: item.name })" @click.stop="$emit('bincard')">
      <span class="ipc-img"><img :src="item.bincard.url" alt="" class="ipc-pic" loading="lazy" /></span>
      <span class="ipc-txt"><b>{{ $t('BinCard') }}</b><small>{{ $date(item.bincard.at) }}</small></span>
      <icons name="zoom-in" class="w-3.5 h-3.5 ipc-ico" />
    </button>
  </span>
</template>

<script>
import { itemPhotoList } from '@/Core/itemPhotos';

// The two labelled chips of an item: "Item Photo" (all its photos) and "BinCard" (only when one is assigned).
export default {
  props: { item: { type: Object, required: true } },
  emits: ['photos', 'bincard'],
  computed: {
    list() {
      return itemPhotoList(this.item);
    },
    count() {
      return this.list.length;
    },
    cover() {
      return this.list[0] ? this.list[0].url : null;
    },
  },
};

</script>

<style scoped>
.ipc { display: inline-flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; vertical-align: top; }
.ipc-chip { display: inline-flex; align-items: center; gap: 8px; min-height: 0; padding: 3px 10px 3px 3px; border: 1px solid; border-radius: 0.8rem; cursor: pointer; text-align: left; transition: transform 0.15s ease, box-shadow 0.15s ease; }
.ipc-chip:hover { transform: translateY(-1px); }
.ipc-chip.is-card { border-color: rgba(15, 118, 110, 0.35); background: linear-gradient(135deg, rgba(240, 253, 250, 0.95), rgba(236, 253, 245, 0.9)); color: #115e59; box-shadow: 0 6px 12px -8px rgba(15, 118, 110, 0.6); }
.ipc-chip.is-photo { border-color: rgba(67, 56, 202, 0.3); background: linear-gradient(135deg, rgba(238, 242, 255, 0.95), rgba(245, 243, 255, 0.9)); color: #3730a3; box-shadow: 0 6px 12px -8px rgba(67, 56, 202, 0.6); }
.ipc-pic { display: block; width: 100%; height: 100%; object-fit: cover; }
@media (min-width: 768px) { .ipc { flex-wrap: nowrap; } .ipc-chip { white-space: nowrap; } }
.ipc-img { display: block; flex: none; width: 46px; height: 34px; border-radius: 0.55rem; overflow: hidden; background: #fff; }
.ipc-txt { display: grid; line-height: 1.15; } .ipc-txt b { font-size: 0.72rem; font-weight: 800; } .ipc-txt small { font-size: 0.64rem; font-weight: 600; opacity: 0.85; }
.ipc-ico { opacity: 0.6; }
html.dark .ipc-chip.is-card { background: rgba(15, 118, 110, 0.18); color: #99f6e4; border-color: rgba(45, 212, 191, 0.4); }
html.dark .ipc-chip.is-photo { background: rgba(67, 56, 202, 0.2); color: #c7d2fe; border-color: rgba(129, 140, 248, 0.4); }
</style>

<template>
  <div v-if="items.length" class="up">
    <div v-if="showOverall" class="up-overall">
      <div class="up-overall-top">
        <b>{{ busy ? $t('Uploading…') : failedCount ? $t('Some files need attention') : doneCount === items.length ? $t('All files uploaded') : $t('Ready to upload') }}</b>
        <span>{{ doneCount }} / {{ items.length }} · {{ percent }}%</span>
      </div>
      <div class="up-bar" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100"><span :style="{ width: percent + '%' }" :class="{ 'is-bad': failedCount && !busy }"></span></div>
    </div>

    <ul class="up-list">
      <li v-for="i in items" :key="i.uid" class="up-item" :class="'is-' + i.status">
        <span class="up-ico"><icons :name="icon(i)" class="w-4 h-4" /></span>
        <div class="up-body">
          <div class="up-name" :title="i.name">{{ i.name }}</div>
          <div class="up-sub">
            <span>{{ size(i.size) }}</span>
            <span v-if="i.status === 'uploading'">· {{ i.progress }}%</span>
            <span v-else-if="i.status === 'uploaded'" class="up-ok">· {{ $t('Uploaded') }}</span>
            <span v-else-if="i.status === 'failed'" class="up-bad">· {{ i.error }}</span>
            <span v-else>· {{ $t('Waiting') }}</span>
          </div>
          <div v-if="i.status === 'uploading' || (i.status === 'uploaded' && busy)" class="up-bar is-thin"><span :style="{ width: (i.status === 'uploaded' ? 100 : i.progress) + '%' }"></span></div>
        </div>
        <div class="up-end">
          <icons v-if="i.status === 'uploaded'" name="tick" class="w-5 h-5 up-tick" />
          <button v-else-if="i.status !== 'uploading' && removable" type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="$emit('remove', i)"><icons name="trash" class="w-4 h-4" /></button>
        </div>
      </li>
    </ul>
  </div>
</template>

<script>
import { overallPercent } from '@/Core/uploadQueue';
import { formatBytes } from '@/Core/archiveFormat';

export default {
  props: { items: { type: Array, default: () => [] }, removable: { type: Boolean, default: true }, showOverall: { type: Boolean, default: true } },
  emits: ['remove'],
  computed: {
    busy() { return this.items.some(i => i.status === 'uploading'); },
    percent() { return overallPercent(this.items); },
    doneCount() { return this.items.filter(i => i.status === 'uploaded').length; },
    failedCount() { return this.items.filter(i => i.status === 'failed').length; },
  },
  methods: {
    size: formatBytes,
    icon(i) { return i.status === 'failed' ? 'exclamation' : (/\.(jpe?g|png|webp|gif)$/i.test(i.name) ? 'image' : 'clip'); },
  },
};
</script>

<style scoped>
.up { margin-top: 0.9rem; }
.up-overall { padding: 0.75rem 0.9rem; margin-bottom: 0.7rem; border-radius: 0.95rem; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.up-overall-top { display: flex; justify-content: space-between; gap: 8px; font-size: 0.8rem; color: var(--lg-ink); margin-bottom: 6px; }
.up-overall-top span { color: var(--lg-mute); font-weight: 700; font-variant-numeric: tabular-nums; }
.up-bar { height: 9px; border-radius: 9999px; background: var(--lg-line); overflow: hidden; }
.up-bar.is-thin { height: 5px; margin-top: 5px; }
.up-bar span { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #2f6fb3, #0f766e); transition: width 0.18s linear; }
.up-bar span.is-bad { background: #e11d48; }
.up-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.up-item { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 1px solid var(--lg-line); border-radius: 0.9rem; background: var(--lg-card); min-width: 0; }
.up-item.is-failed { border-color: rgba(225, 29, 72, 0.5); }
.up-item.is-uploaded { border-color: rgba(5, 150, 105, 0.4); }
.up-ico { flex: none; width: 36px; height: 36px; display: grid; place-items: center; border-radius: 0.7rem; background: var(--lg-soft); color: var(--lg-mute); }
.up-body { flex: 1; min-width: 0; }
.up-name { font-size: 0.84rem; font-weight: 700; color: var(--lg-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.up-sub { display: flex; flex-wrap: wrap; gap: 0 6px; font-size: 0.72rem; color: var(--lg-faint); }
.up-ok { color: #047857; font-weight: 700; }
.up-bad { color: #be123c; font-weight: 700; }
.up-end { flex: none; display: flex; align-items: center; }
.up-tick { color: #059669; }
</style>

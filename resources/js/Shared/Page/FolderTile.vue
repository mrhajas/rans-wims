<template>
  <component :is="to ? 'Link' : 'div'" :href="to || undefined" class="ft" :style="{ '--i': index, '--sc': scale }">
    <span class="ft-art"><folder-icon :tone="tone" :size="Math.round(118 * scale)" /></span>
    <span class="ft-name">{{ name }}</span>
    <span v-if="meta" class="ft-meta">{{ meta }}</span>
  </component>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import FolderIcon from '@/Shared/Archive/FolderIcon.vue';

// A large folder card (grey panel with a big folder, name underneath) — used for projects and categories.
export default { components: { Link, FolderIcon }, props: { tone: { type: String, default: 'amber' }, name: String, meta: String, to: String, index: { type: Number, default: 0 }, scale: { type: Number, default: 1 } } };
</script>

<style scoped>
.ft { display: flex; flex-direction: column; align-items: center; gap: 2px; padding: calc(12px * var(--sc, 1)) calc(12px * var(--sc, 1)) calc(14px * var(--sc, 1)); border-radius: calc(1.4rem * var(--sc, 1)); background: var(--lg-card); border: 1px solid var(--lg-line); text-decoration: none; box-shadow: 0 16px 30px -24px rgba(15, 23, 42, 0.5); animation: ft-rise 0.4s ease backwards; animation-delay: calc(var(--i, 0) * 50ms); transition: transform 0.18s ease, box-shadow 0.18s ease; min-width: 0; }
.ft:hover { transform: translateY(-4px); box-shadow: 0 22px 36px -22px rgba(15, 23, 42, 0.6); }
.ft-art { width: 100%; display: grid; place-items: center; padding: calc(18px * var(--sc, 1)) 0 calc(14px * var(--sc, 1)); border-radius: calc(1.05rem * var(--sc, 1)); background: var(--lg-soft); transition: transform 0.2s ease; }
.ft:hover .ft-art { transform: scale(1.03); }
.ft-name { margin-top: calc(10px * var(--sc, 1)); max-width: 100%; font-size: calc(0.92rem * var(--sc, 1)); font-weight: 800; color: var(--lg-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ft-meta { max-width: 100%; font-size: calc(0.74rem * var(--sc, 1)); color: var(--lg-faint); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
@keyframes ft-rise { from { opacity: 0; transform: translateY(10px); } }
@media (prefers-reduced-motion: reduce) { .ft { animation: none; transition: none; } .ft-art { transition: none; } }
</style>

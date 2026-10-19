<template>
  <component :is="to ? 'Link' : 'div'" :href="to || undefined" class="rc" :style="{ '--i': index }">
    <span class="rc-art" :class="'is-' + tone">
      <img v-if="cover" :src="cover" alt="" loading="lazy" />
      <icons v-else :name="icon" class="w-6 h-6" />
    </span>
    <span class="rc-body">
      <span class="rc-title">{{ title }}</span>
      <span class="rc-meta"><template v-if="size">{{ size }}</template><template v-if="size && sub"> <i></i> </template><template v-if="sub">{{ sub }}</template></span>
    </span>
    <span class="rc-dots" aria-hidden="true">⋮</span>
  </component>
</template>

<script>
import { Link } from '@inertiajs/vue3';

// A compact "recently modified" card: file/cover tile, title, "2.2 MB • Document", and the three-dot affordance.
export default { components: { Link }, props: { icon: { type: String, default: 'doc' }, tone: { type: String, default: 'blue' }, title: String, size: String, sub: String, cover: String, to: String, index: { type: Number, default: 0 } } };
</script>

<style scoped>
.rc { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 1.2rem; background: var(--lg-card); border: 1px solid var(--lg-line); text-decoration: none; min-width: 0; animation: rc-rise 0.4s ease backwards; animation-delay: calc(var(--i, 0) * 45ms); transition: transform 0.15s ease, box-shadow 0.15s ease; }
a.rc:hover { transform: translateY(-2px); box-shadow: 0 14px 26px -18px rgba(15, 23, 42, 0.55); }
.rc-art { flex: none; width: 48px; height: 48px; display: grid; place-items: center; border-radius: 0.95rem; overflow: hidden; color: #fff; box-shadow: 0 8px 16px -10px rgba(15, 23, 42, 0.6); }
.rc-art img { width: 100%; height: 100%; object-fit: cover; }
.rc-art.is-blue { background: linear-gradient(145deg, #3b82f6, #1d4ed8); }
.rc-art.is-red { background: linear-gradient(145deg, #f87171, #dc2626); }
.rc-art.is-orange { background: linear-gradient(145deg, #fb923c, #ea580c); }
.rc-art.is-green { background: linear-gradient(145deg, #34d399, #059669); }
.rc-art.is-purple { background: linear-gradient(145deg, #8b5cf6, #5b3df5); }
.rc-art.is-teal { background: linear-gradient(145deg, #14b8a6, #0f766e); }
.rc-body { display: flex; flex-direction: column; min-width: 0; flex: 1; }
.rc-title { font-size: 0.88rem; font-weight: 800; color: var(--lg-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rc-meta { display: flex; align-items: center; gap: 6px; font-size: 0.74rem; color: var(--lg-faint); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rc-meta i { width: 3px; height: 3px; border-radius: 9999px; background: currentColor; display: inline-block; }
.rc-dots { flex: none; width: 30px; height: 30px; display: grid; place-items: center; border-radius: 0.7rem; border: 1px solid var(--lg-line); color: var(--lg-mute); font-weight: 900; }
@keyframes rc-rise { from { opacity: 0; transform: translateY(8px); } }
@media (prefers-reduced-motion: reduce) { .rc { animation: none; transition: none; } }
</style>

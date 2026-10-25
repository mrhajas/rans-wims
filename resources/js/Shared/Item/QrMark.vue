<template>
  <svg v-if="art" class="qrm" :viewBox="`0 0 ${art.size} ${art.size}`" role="img" :aria-label="label" shape-rendering="geometricPrecision">
    <defs>
      <linearGradient :id="gid" gradientUnits="userSpaceOnUse" x1="0" y1="0" :x2="art.size" :y2="art.size">
        <stop offset="0" stop-color="#1e1b4b" />
        <stop offset="0.55" stop-color="#312e81" />
        <stop offset="1" stop-color="#0f766e" />
      </linearGradient>
      <linearGradient :id="gid + 'e'" gradientUnits="userSpaceOnUse" x1="0" y1="0" :x2="art.size" :y2="art.size">
        <stop offset="0" stop-color="#4338ca" />
        <stop offset="1" stop-color="#0f172a" />
      </linearGradient>
    </defs>
    <rect :width="art.size" :height="art.size" rx="3.2" fill="#ffffff" />
    <!-- the three finder eyes: a rounded ring around a rounded block, so the code still scans but reads as designed -->
    <g v-for="e in art.eyes" :key="e.x + '-' + e.y">
      <rect :x="e.x + 0.5" :y="e.y + 0.5" width="6" height="6" rx="2.1" fill="none" :stroke="`url(#${gid}e)`" stroke-width="1" />
      <rect :x="e.x + 2" :y="e.y + 2" width="3" height="3" rx="1.05" :fill="`url(#${gid}e)`" />
    </g>
    <!-- data modules as soft dots, in one path -->
    <path :d="art.dots" :fill="`url(#${gid})`" />
    <!-- centre badge (the code carries the highest error correction, so the badge never costs a scan) -->
    <g v-if="art.badge" :transform="`translate(${art.badge.x} ${art.badge.y})`">
      <rect x="-0.3" y="-0.3" :width="art.badge.s + 0.6" :height="art.badge.s + 0.6" rx="1.7" fill="#ffffff" />
      <rect x="0.35" y="0.35" :width="art.badge.s - 0.7" :height="art.badge.s - 0.7" rx="1.3" :fill="`url(#${gid}e)`" />
      <text :x="art.badge.s / 2" :y="art.badge.s / 2 + 0.95" text-anchor="middle" font-family="Nunito, Arial, sans-serif" font-weight="900" :font-size="art.badge.s * 0.58" fill="#ffffff">W</text>
    </g>
  </svg>
</template>

<script>
import qrcode from 'qrcode-generator';

let counter = 0;

// A QR code for one text (an item code), drawn as a styled SVG: gradient soft dots, rounded finder eyes, a centre badge.
export default {
  props: { value: { type: String, required: true }, label: { type: String, default: 'QR code' }, badge: { type: Boolean, default: true } },
  data() {
    return { gid: 'qrm' + ++counter };
  },
  computed: {
    art() {
      let qr;
      try {
        qr = qrcode(0, 'H');
        qr.addData(String(this.value || ''), 'Byte');
        qr.make();
      } catch (e) {
        return null; // too long / empty: nothing to draw
      }
      const n = qr.getModuleCount();
      const q = 2; // quiet zone (modules)
      const size = n + q * 2;
      const eyes = [{ x: q, y: q }, { x: q + n - 7, y: q }, { x: q, y: q + n - 7 }];
      const inEye = (r, c) => (r < 7 && c < 7) || (r < 7 && c >= n - 7) || (r >= n - 7 && c < 7);
      // the badge covers about 5% of the area (error correction H restores up to 30%); keep its size odd-aligned to the grid
      let s = Math.max(5, Math.round(n * 0.2));
      if ((n - s) % 2) s += 1;
      const from = (n - s) / 2;
      const inBadge = (r, c) => this.badge && n >= 25 && r >= from - 0.5 && r < from + s + 0.5 && c >= from - 0.5 && c < from + s + 0.5;
      let dots = '';
      for (let r = 0; r < n; r++) {
        for (let c = 0; c < n; c++) {
          if (!qr.isDark(r, c) || inEye(r, c) || inBadge(r, c)) continue;
          const cx = q + c + 0.5;
          const cy = q + r + 0.5;
          dots += `M${cx - 0.44} ${cy}a0.44 0.44 0 1 0 0.88 0a0.44 0.44 0 1 0 -0.88 0`;
        }
      }
      return { size, eyes, dots, badge: this.badge && n >= 25 ? { x: q + from, y: q + from, s } : null };
    },
  },
};
</script>

<style scoped>
.qrm { display: block; width: 100%; height: auto; }
</style>

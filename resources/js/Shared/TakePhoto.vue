<template>
  <span class="tp">
    <button type="button" class="lg-btn tone tone-solid tp-btn" style="--tone: #059669" :disabled="busy || disabled" @click="open">
      <span class="tone-ico"><icons name="camera" class="w-4 h-4" /></span>{{ busy ? status : $t('Take Photo') }}
    </button>
    <camera-capture v-if="cameraOpen" :can-photos="true" :can-videos="false" :photo-left="1" :max-px="1600" @capture="shot" @fallback="$refs.nativeCam && $refs.nativeCam.click()" @close="cameraOpen = false" />
    <!-- the phone's own camera app, used when the live camera cannot start (for example without https) -->
    <input ref="nativeCam" type="file" accept="image/*" capture="user" class="hidden" @change="e => { shot(e.target.files[0]); e.target.value = ''; }" />
    <small v-if="note" class="tp-note" :class="{ 'is-warn': warn }">{{ note }}</small>
  </span>
</template>

<script>
import CameraCapture from '@/Shared/SiteDiary/CameraCapture.vue';
import { makePortrait } from '@/Core/portraitPhoto';

// "Take Photo" for a person (staff, user): opens the camera, then removes the background on this device, puts the person on white and makes the picture small.
// The finished photo is handed to the form (event `photo`), which saves it with the record as it does any picked photo.
export default {
  components: { CameraCapture },
  props: { maxSide: { type: Number, default: 800 }, disabled: Boolean },
  emits: ['photo'],
  data() { return { cameraOpen: false, busy: false, status: '', note: '', warn: false }; },
  methods: {
    open() { this.note = ''; this.cameraOpen = true; },
    async shot(file) {
      this.cameraOpen = false;
      if (!file || this.busy) return;
      this.busy = true;
      this.warn = false;
      this.status = this.$t('Working...');
      try {
        const r = await makePortrait(file, s => { this.status = this.$t(s); }, { maxSide: this.maxSide });
        this.$emit('photo', r.file);
        this.warn = !r.cut;
        this.note = r.cut ? this.$t('Background removed, white background, :s KB. It is saved with the record.', { s: Math.max(1, Math.round(r.file.size / 1024)) })
          : this.$t('The background could not be removed here; the photo was put on white as taken (:s KB).', { s: Math.max(1, Math.round(r.file.size / 1024)) });
      } catch (e) {
        this.warn = true;
        this.note = this.$t('The photo could not be prepared. Please try again.');
      } finally {
        this.busy = false;
      }
    },
  },
};
</script>

<style scoped>
.tp { display: inline-grid; gap: 6px; justify-items: start; }
.tp-btn { white-space: nowrap; }
.tp-note { max-width: 240px; font-size: 0.72rem; line-height: 1.35; font-weight: 600; color: #047857; }
.tp-note.is-warn { color: #b45309; }
</style>

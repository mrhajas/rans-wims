<template>
  <span class="tp">
    <button type="button" class="lg-btn tone tone-solid tp-btn" style="--tone: #059669" :disabled="studio || disabled" @click="open">
      <span class="tone-ico"><icons name="camera" class="w-4 h-4" /></span>{{ $t('Take Photo') }}
    </button>
    <camera-capture v-if="cameraOpen" :can-photos="true" :can-videos="false" :photo-left="1" :max-px="1600" @capture="shot" @fallback="$refs.nativeCam && $refs.nativeCam.click()" @close="cameraOpen = false" />
    <!-- the phone's own camera app, used when the live camera cannot start (for example without https) -->
    <input ref="nativeCam" type="file" accept="image/*" capture="user" class="hidden" @change="e => { shot(e.target.files[0]); e.target.value = ''; }" />
    <photo-studio :open="studio" :file="shotFile" :default-px="maxSide" @done="done" @cancel="studio = false" @retake="retake" />
    <small v-if="note" class="tp-note" :class="{ 'is-warn': warn }">{{ note }}</small>
  </span>
</template>

<script>
import CameraCapture from '@/Shared/SiteDiary/CameraCapture.vue';
import PhotoStudio from '@/Shared/PhotoStudio.vue';

// "Take Photo" for a person (staff, user): opens the camera, then the photo studio, where the person doing the entry can remove the background (optional, white or blue), crop,
// zoom, turn and choose the size before the photo is used. The finished photo is handed to the form (event `photo`), which saves it with the record as it does any picked photo.
export default {
  components: { CameraCapture, PhotoStudio },
  props: { maxSide: { type: Number, default: 800 }, disabled: Boolean },
  emits: ['photo'],
  data() { return { cameraOpen: false, studio: false, shotFile: null, note: '', warn: false }; },
  methods: {
    open() { this.note = ''; this.cameraOpen = true; },
    shot(file) {
      this.cameraOpen = false;
      if (!file) return;
      this.shotFile = file;
      this.studio = true;
    },
    retake() { this.studio = false; this.$nextTick(() => { this.cameraOpen = true; }); },
    done(r) {
      this.studio = false;
      this.shotFile = null;
      this.warn = false;
      this.$emit('photo', r.file);
      this.note = this.$t(r.cut ? 'Background removed (:c), :s KB. It is saved with the record.' : 'Photo ready, :s KB. It is saved with the record.', { s: r.kb, c: this.$t(r.bg === 'blue' ? 'blue' : 'white') });
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

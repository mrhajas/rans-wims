<template>
  <div class="vac">
    <div class="vac-row">
      <button type="button" class="lg-btn lg-btn-soft vac-cam" :disabled="left <= 0" @click="open = true"><icons name="camera" class="w-4 h-4" /><span>{{ $t('Take a Photo') }}</span></button>
      <small class="lg-subtext">{{ $t('Uses the camera of this device or the webcam. Or add files below.') }}</small>
    </div>
    <acct-dropzone :model-value="modelValue" :hint="hint" @update:model-value="v => $emit('update:modelValue', v)" />
    <camera-capture v-if="open" :can-photos="true" :can-videos="false" :photo-left="left" :video-left="0" @capture="onCapture" @fallback="$refs.native && $refs.native.click()" @close="open = false" />
    <!-- the phone's own camera app, used when the live camera cannot start (for example without https) -->
    <input ref="native" type="file" class="sr-only" accept="image/*" capture="environment" @change="onNative" />
  </div>
</template>

<script>
import CameraCapture from '@/Shared/SiteDiary/CameraCapture.vue';
import AcctDropzone from '@/Pages/Accounts/AcctDropzone.vue';

// "Take a Photo" for the Visitor Logs forms: the same live camera as Site Diary (front / back camera, shutter, strip of what was taken, the phone's camera app as a fallback),
// next to the usual drop zone. Every photo joins the files of the form.
export default {
  components: { CameraCapture, AcctDropzone },
  props: { modelValue: { type: Array, default: () => [] }, hint: String, max: { type: Number, default: 10 } },
  emits: ['update:modelValue'],
  data() { return { open: false }; },
  computed: { left() { return this.max - this.modelValue.length; } },
  methods: {
    onCapture(file) { if (this.left > 0) this.$emit('update:modelValue', [...this.modelValue, file]); },
    onNative(e) { Array.from(e.target.files || []).forEach(f => this.onCapture(f)); e.target.value = ''; },
  },
};
</script>

<style scoped>
.vac { display: grid; gap: 10px; }
.vac-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; }
.vac-cam { min-height: 42px; }
@media (max-width: 560px) { .vac-cam { width: 100%; justify-content: center; } }
</style>

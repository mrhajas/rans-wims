<template>
  <div class="hg-photo">
    <div class="hg-photo-thumb" :class="{ 'is-none': !photo.thumb }">
      <img v-if="photo.thumb" :src="photo.thumb" :alt="photo.label" loading="lazy" />
      <span v-else>{{ $t('No photo') }}</span>
    </div>
    <div class="hg-photo-body">
      <b>{{ photo.label }}</b>
      <span class="lg-chip" :class="kindChip" style="text-transform: none">{{ kindText }}</span>
      <div v-if="can" class="hg-photo-acts">
        <button type="button" class="lg-btn tone" style="--tone: #2563eb" :disabled="busy" @click="$refs.file.click()"><span class="tone-ico"><icons name="upload" class="w-4 h-4" /></span>{{ $t('Upload a photo') }}</button>
        <button v-if="photo.kind !== 'default'" type="button" class="lg-btn tone" style="--tone: #d97706" :disabled="busy" @click="reset"><span class="tone-ico"><icons name="refresh" class="w-4 h-4" /></span>{{ $t('WIMS photo') }}</button>
        <button v-if="photo.kind !== 'none'" type="button" class="lg-btn tone" style="--tone: #475569" :disabled="busy" @click="off"><span class="tone-ico"><icons name="cross" class="w-4 h-4" /></span>{{ $t('No photo') }}</button>
        <input ref="file" type="file" accept=".jpg,.jpeg,.png,.webp" class="sr-only" @change="pick" />
      </div>
      <p v-if="error" class="text-red-600 text-xs" style="margin: 6px 0 0">{{ error }}</p>
    </div>
  </div>
</template>

<script>
import { router } from '@inertiajs/vue3';

// The photo behind the hero of one template's email: what it is now, and the three things a Super Admin can do (upload, back to the WIMS photo, no photo).
export default {
  props: { photo: Object, can: Boolean },
  data() { return { busy: false, error: '' }; },
  computed: {
    kindText() { return { custom: this.$t('Your photo'), default: this.$t('WIMS photo'), none: this.$t('No photo') }[this.photo.kind]; },
    kindChip() { return { custom: 'lg-chip-green', default: 'lg-chip-blue', none: 'lg-chip-slate' }[this.photo.kind]; },
  },
  methods: {
    pick(e) {
      const f = e.target.files && e.target.files[0];
      e.target.value = '';
      if (!f) return;
      this.error = '';
      if (f.size > 8 * 1024 * 1024) { this.error = this.$t('The picture can be up to 8 MB.'); return; }
      this.busy = true;
      router.post(route('holiday-greetings.settings.photos.store', this.photo.theme), { photo: f }, {
        forceFormData: true, preserveScroll: true, preserveState: true,
        onError: errs => { this.error = errs.photo || this.$t('The picture could not be uploaded.'); },
        onFinish: () => { this.busy = false; },
      });
    },
    async reset() {
      if (!(await this.$confirm(this.$t('Put the WIMS photo of :t back? Your own photo is removed.', { t: this.photo.label })))) return;
      this.busy = true;
      router.delete(route('holiday-greetings.settings.photos.reset', this.photo.theme), { preserveScroll: true, preserveState: true, onFinish: () => { this.busy = false; } });
    },
    off() {
      this.busy = true;
      router.post(route('holiday-greetings.settings.photos.off', this.photo.theme), {}, { preserveScroll: true, preserveState: true, onFinish: () => { this.busy = false; } });
    },
  },
};
</script>

<style scoped>
.hg-photo { display: flex; gap: 14px; align-items: stretch; padding: 12px; border-radius: 20px; background: var(--lg-card); box-shadow: inset 0 0 0 1px var(--lg-line); min-width: 0; }
.hg-photo-thumb { flex: none; width: 132px; min-height: 88px; border-radius: 14px; overflow: hidden; background: #0f2540; display: grid; place-items: center; color: #94a3b8; font-size: 0.74rem; font-weight: 700; }
.hg-photo-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.hg-photo-body { display: flex; flex-direction: column; gap: 6px; align-items: flex-start; min-width: 0; }
.hg-photo-body b { color: var(--lg-ink); }
.hg-photo-acts { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
@media (max-width: 520px) { .hg-photo { flex-direction: column; } .hg-photo-thumb { width: 100%; height: 130px; } }
</style>

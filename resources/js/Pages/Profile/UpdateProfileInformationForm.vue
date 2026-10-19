<template>
  <section class="lg-card">
    <header class="lg-card-head">
      <span class="lg-card-ico lg-ico-sky"><icons name="user" class="w-5 h-5" /></span>
      <div class="min-w-0">
        <h2 class="lg-card-title">{{ $t('Profile Information') }}</h2>
        <p class="lg-card-sub">{{ $t("Update your account's profile information and email address.") }}</p>
      </div>
    </header>

    <form @submit.prevent="updateProfileInformation">
      <div class="lg-fields">
        <!-- Profile Photo -->
        <div class="lg-span-all" v-if="$page.props.jetstream.managesProfilePhotos">
          <!-- Profile Photo File Input -->
          <input type="file" class="hidden" ref="photo" @change="updatePhotoPreview" />
          <tec-label for="photo" :value="$t('Photo')" />

          <div class="pf-photo-row">
            <!-- Current Profile Photo -->
            <div v-show="!photoPreview">
              <img :src="user.profile_photo_url" :alt="user.name" class="pf-photo" />
            </div>

            <!-- New Profile Photo Preview -->
            <div v-show="photoPreview">
              <span
                class="pf-photo block"
                :style="
                  'background-size: cover; background-repeat: no-repeat; background-position: center center; background-image: url(\'' +
                  photoPreview +
                  '\');'
                "
              >
              </span>
            </div>

            <div class="flex items-center gap-2 flex-wrap">
              <button type="button" class="lg-btn lg-btn-soft" @click.prevent="selectNewPhoto">
                <icons name="upload" class="w-4 h-4" />{{ $t('Select A New Photo') }}
              </button>
              <button type="button" class="lg-btn lg-btn-soft pf-btn-danger-soft" @click.prevent="deletePhoto" v-if="user.profile_photo_path">
                <icons name="trash" class="w-4 h-4" />{{ $t('Remove Photo') }}
              </button>
            </div>
          </div>

          <tec-input-error :message="form.errors.photo" class="mt-2" />
        </div>

        <!-- Name -->
        <div>
          <tec-label for="name" :value="$t('Name')" />
          <tec-input id="name" type="text" class="mt-1 block w-full" v-model="form.name" autocomplete="name" />
          <tec-input-error :message="form.errors.name" class="mt-2" />
        </div>

        <!-- Email -->
        <div>
          <tec-label for="email" :value="$t('Email')" />
          <tec-input id="email" type="email" class="mt-1 block w-full" v-model="form.email" />
          <tec-input-error :message="form.errors.email" class="mt-2" />
        </div>
      </div>

      <div class="pf-foot">
        <tec-action-message :on="form.recentlySuccessful" class="text-sm font-semibold text-emerald-600">{{ $t('Saved.') }}</tec-action-message>
        <button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing">
          <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />{{ $t('Save') }}
        </button>
      </div>
    </form>
  </section>
</template>

<script>
import TecInput from '@/Jetstream/Input.vue';
import TecLabel from '@/Jetstream/Label.vue';
import TecInputError from '@/Jetstream/InputError.vue';
import TecActionMessage from '@/Jetstream/ActionMessage.vue';

export default {
  components: {
    TecInput,
    TecLabel,
    TecInputError,
    TecActionMessage,
  },

  props: ['user'],

  data() {
    return {
      form: this.$inertia.form({
        _method: 'PUT',
        name: this.user.name,
        email: this.user.email,
        photo: null,
      }),

      photoPreview: null,
    };
  },

  methods: {
    updateProfileInformation() {
      if (this.$refs.photo) {
        this.form.photo = this.$refs.photo.files[0];
      }

      this.form.post(route('user-profile-information.update'), {
        errorBag: 'updateProfileInformation',
        preserveScroll: true,
      });
    },

    selectNewPhoto() {
      this.$refs.photo.click();
    },

    updatePhotoPreview() {
      const reader = new FileReader();

      reader.onload = e => {
        this.photoPreview = e.target.result;
      };

      reader.readAsDataURL(this.$refs.photo.files[0]);
    },

    deletePhoto() {
      this.$inertia.delete(route('current-user-photo.destroy'), {
        preserveScroll: true,
        onSuccess: () => (this.photoPreview = null),
      });
    },
  },
};
</script>

<style scoped>
.pf-photo-row { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-top: 8px; }
.pf-photo { width: 80px; height: 80px; border-radius: 9999px; object-fit: cover; box-shadow: 0 0 0 3px var(--lg-card), 0 10px 20px -10px rgba(15, 23, 42, 0.5); }
.pf-btn-danger-soft { color: #b91c1c; }
html.dark .pf-btn-danger-soft { color: #fca5a5; }
</style>

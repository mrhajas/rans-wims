<template>
  <admin-layout :title="$t(edit ? 'edit_x' : 'create_x', { x: $t('Item') })">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="bag"
        :title="$t(edit ? 'edit_x' : 'create_x', { x: $t('Item') })"
        :subtitle="edit ? $t('Update the record by modifying the details in the form below') : $t('Please fill the form below to add new record.')"
      >
        <span v-if="edit" class="lg-chip lg-chip-blue" style="text-transform: none">
          <img v-if="edit?.photo" class="block w-4 h-4 rounded-full object-cover" :src="edit.photo" />
          {{ edit.name }}
        </span>
        <button v-if="edit && !edit.deleted_at && !edit.is_used && $can('create-used-items')" type="button" class="fx-used-btn" :disabled="creatingUsed" @click="createUsed">
          <span class="fx-used-ico"><icons name="duplicate" class="w-3.5 h-3.5" /></span>
          <span>{{ creatingUsed ? $t('Creating…') : $t('Create USED') }}</span>
        </button>
        <Link :href="route('items.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Items') }}</Link>
      </page-header>

      <form @submit.prevent="update">
        <trashed-message v-if="edit && edit.deleted_at" class="mb-4" @restore="restore">
          {{ $t('This record has been deleted.') }}
        </trashed-message>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="bag" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Name') }} / {{ $t('Item Price') }} / {{ $t('SKU') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <text-input v-model="form.name" :error="$page.props.errors.name" :label="$t('Name')" />
            </div>
            <div>
              <text-input type="number" min="0" v-model="form.price" :error="$page.props.errors.price" :label="$t('Item Price')" />
            </div>
            <div>
              <auto-complete
                id="symbology"
                v-model="form.symbology"
                :suggestions="symbologies"
                :label="$t('Barcode Symbology')"
                :error="$page.props.errors.symbology"
              />
            </div>
            <div>
              <text-input v-model="form.sku" :error="$page.props.errors.sku" :label="$t('SKU')" />
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="collection" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Classification') }}</h2>
              <p class="lg-card-sub">{{ $t('Category') }} / {{ $t('Unit') }} / {{ $t('Rack Location') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div>
              <auto-complete
                id="category"
                :suggestions="parents"
                :label="$t('Category')"
                v-model="form.category_id"
                :error="$page.props.errors.category_id"
              />
            </div>
            <transition name="slidedown">
              <div v-if="form.category_id && subcategories.length > 0">
                <auto-complete
                  id="subcategory"
                  :suggestions="subcategories"
                  :label="$t('Child Category')"
                  v-model="form.child_category_id"
                />
              </div>
            </transition>
            <div>
              <auto-complete id="unit" :label="$t('Unit')" :suggestions="units" v-model="form.unit_id" />
            </div>
            <div>
              <text-input v-model="form.rack_location" :error="$page.props.errors.rack_location" :label="$t('Rack Location')" />
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="image" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Photo') }}</h2>
              <p class="lg-card-sub">{{ $t('Attach a photo from an image URL') }}</p>
            </div>
          </header>
          <div class="fx-photo">
            <div v-if="photoPreviewUrl && !photoLoadError" class="fx-photo-thumb">
              <img
                :src="photoPreviewUrl"
                alt=""
                class="fx-photo-img"
                @click="imagePreviewOpen = true"
                @error="photoLoadError = true"
              />
              <button
                type="button"
                @click="removePhoto"
                :data-tip="$t('Remove')"
                style="position: absolute"
                class="lg-tip fx-photo-remove"
              >
                <icons name="cross" class="w-3 h-3" />
              </button>
            </div>
            <div v-else class="fx-photo-thumb fx-photo-empty is-click" role="button" tabindex="0" :aria-label="$t('Select photos')" @click="$refs.fileInput && $refs.fileInput.browse()" @keyup.enter="$refs.fileInput && $refs.fileInput.browse()"><icons name="image" class="w-6 h-6" /></div>
            <div class="fx-photo-body">
              <file-input ref="fileInput" v-model="form.photo" multiple :max-files="3" @files="onPickedFiles" accept="image/png,image/jpeg,image/svg+xml" :error="$page.props.errors.photo" :label="$t('Photo')" />
              <button
                type="button"
                class="lg-btn lg-tip tip-left fx-fetch-btn fx-btn-blue"
                :data-tip="$t('Attach a photo from an image URL')"
                @click="openPhotoUrlModal"
              >
                <icons name="sparkles" class="w-3.5 h-3.5" />
                {{ photoPreviewUrl ? $t('Fetch Photo Again') : $t('Fetch Photo') }}
              </button>
              <button
                type="button"
                class="lg-btn lg-tip tip-left fx-fetch-btn fx-btn-green"
                :data-tip="$t('Open the camera and attach up to 3 photos of this item')"
                :disabled="photosLeft <= 0"
                @click="cameraOpen = true"
              >
                <icons name="camera" class="w-3.5 h-3.5" />
                {{ $t('Take Photo') }}
              </button>
              <camera-capture
                v-if="cameraOpen"
                :can-photos="true"
                :can-videos="false"
                :photo-left="photosLeft"
                :video-left="0"
                @capture="addCaptured"
                @fallback="$refs.nativeCam && $refs.nativeCam.click()"
                @close="cameraOpen = false"
              />
              <!-- the phone's own camera app, used when the live camera cannot start (for example without https) -->
              <input ref="nativeCam" type="file" accept="image/*" capture="environment" class="hidden" @change="e => { addCaptured(e.target.files[0]); e.target.value = ''; }" />
              <div v-if="gallery.length || pendingPhotos.length" class="fx-gal">
                <div v-for="g in gallery" :key="'g' + g.id" class="fx-gal-item">
                  <img :src="g.url" alt="" @click="galleryView = g.url" />
                  <button type="button" class="fx-gal-x lg-tip" :data-tip="$t('Remove')" @click="removeGallery(g)"><icons name="cross" class="w-3 h-3" /></button>
                  <button type="button" class="fx-gal-face lg-tip" :data-tip="$t('Make this the face of the item')" :aria-label="$t('Make this the face of the item')" @click="makeMainGallery(g)"><icons name="star" class="w-3 h-3" /></button>
                </div>
                <div v-for="(p, i) in pendingPhotos" :key="'p' + i" class="fx-gal-item is-new">
                  <img :src="p.url" alt="" @click="galleryView = p.url" />
                  <button type="button" class="fx-gal-x lg-tip" :data-tip="$t('Remove')" @click="removePending(i)"><icons name="cross" class="w-3 h-3" /></button>
                  <button type="button" class="fx-gal-face lg-tip" :data-tip="$t('Make this the face of the item')" :aria-label="$t('Make this the face of the item')" @click="makeMainPending(i)"><icons name="star" class="w-3 h-3" /></button>
                </div>
                <span class="fx-gal-count">{{ gallery.length + pendingPhotos.length }}/3 · {{ $t('Save the item to keep new photos.') }}</span>
              </div>
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-slate"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Notes') }}</h2>
              <p class="lg-card-sub">{{ $t('Details') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div class="lg-span-all">
              <textarea-input v-model="form.details" :error="$page.props.errors.details" :label="$t('Details')" />
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-violet"><icons name="adjustments" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Options') }}</h2>
              <p class="lg-card-sub">{{ $t('Track Weight') }} / {{ $t('Track Quantity') }} / {{ $t('Used Item / Material') }}</p>
            </div>
          </header>
          <!-- <check-box
            class="mb-2"
            id="has_serials"
            :label="$t('Track Serials')"
            v-model:checked="form.has_serials"
            :error="$page.props.errors.has_serials"
          /> -->
          <div class="fx-opts">
            <div class="fx-opt">
              <check-box
                id="track_weight"
                :label="$t('Track Weight')"
                v-model:checked="form.track_weight"
                :error="$page.props.errors.track_weight"
              />
            </div>
            <div class="fx-opt">
              <check-box
                id="track_quantity"
                :label="$t('Track Quantity')"
                v-model:checked="form.track_quantity"
                :error="$page.props.errors.track_quantity"
              />
            </div>
            <div class="fx-opt">
              <check-box
                id="is_used"
                :label="$t('Used Item / Material')"
                v-model:checked="form.is_used"
                :error="$page.props.errors.is_used"
              />
              <p class="fx-opt-hint">{{ $t('Leave unchecked if this item is a Consumable.') }}</p>
            </div>
          </div>

          <transition name="slidedown">
            <div v-if="form.track_quantity" class="lg-fields mt-4">
              <div>
                <text-input
                  type="number"
                  v-model="form.alert_quantity"
                  :label="$t('Alert on low stock of')"
                  :error="$page.props.errors.alert_quantity"
                />
              </div>
            </div>
          </transition>

          <div class="fx-opt fx-opt-wide mt-4">
            <check-box
              id="has_variants"
              v-model:checked="form.has_variants"
              :error="$page.props.errors.has_variants"
              :label="$t('Track Variants e.g. Size and/or Color')"
            />
            <p class="lg-note lg-note-amber mt-2">
              <icons name="exclamation" class="w-4 h-4" />
              <span>{{ $t('Modifying variants after you have stock could result in wrong stock.') }}</span>
            </p>
          </div>

          <div v-if="form.has_variants == 1" class="mt-5">
            <div class="flex items-center justify-between gap-3 flex-wrap">
              <h3 class="lg-card-title">{{ $t('Variants') }}</h3>
              <div class="fx-stepper">
                <button type="button" @click="form.variants.push({ name: '', option: ['', ''] })" class="fx-step-btn fx-step-l">
                  <span class="flex items-center justify-center text-lg w-4 h-4">+</span>
                </button>
                <button type="button" @click="form.variants.pop()" class="fx-step-btn fx-step-r">
                  <span class="flex items-center justify-center text-lg w-4 h-4">-</span>
                </button>
              </div>
            </div>
            <div class="fx-variants">
              <div v-for="(variant, vi) in form.variants" :key="vi" class="fx-variant">
                <div class="flex items-start gap-3">
                  <text-input
                    class="flex-1 min-w-0"
                    v-model="variant.name"
                    :error="form.errors['variants.' + vi + '.name']"
                    :label="$t('Variant') + ' ' + (vi + 1) + ' ' + $t('Name')"
                  />
                  <span class="fx-stepper fx-stepper-sm">
                    <button type="button" @click="variant.option.push('')" class="fx-step-btn fx-step-l fx-step-dark">
                      <span class="flex items-center justify-center text-lg w-4 h-4">+</span>
                    </button>
                    <button type="button" @click="variant.option.pop()" class="fx-step-btn fx-step-r fx-step-dark">
                      <span class="flex items-center justify-center text-lg w-4 h-4">-</span>
                    </button>
                  </span>
                </div>
                <div class="lg-fields fx-opt-grid mt-3">
                  <div v-for="(opt, oi) in variant.option" :key="oi">
                    <text-input
                      v-model="variant.option[oi]"
                      :label="$t('Option') + ' ' + (oi + 1)"
                      :error="form.errors['variants.' + vi + '.option.' + oi]"
                    />
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>

        <div class="lg-actionbar">
          <template v-if="edit">
            <button type="button" @click="destroy" v-if="!edit.deleted_at" class="lg-btn lg-btn-red">
              <icons name="trash" class="w-4 h-4" />
              {{ $t('delete_x', { x: $t('Item') }) }}
            </button>
            <button v-else type="button" @click="deletePermanently" class="lg-btn lg-btn-red">
              <icons name="trash" class="w-4 h-4" />
              {{ $t('delete_x', { x: $t('Permanently') }) }}
            </button>
          </template>
          <div v-else></div>
          <div class="lg-actionbar-btns">
            <tec-action-message :on="form.recentlySuccessful" class="lg-actionbar-note">{{ $t('Saved.') }}</tec-action-message>
            <button type="submit" :disabled="form.processing" class="lg-btn lg-btn-primary">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" /> {{ $t('Save') }}
            </button>
          </div>
        </div>
      </form>
    </div>

    <!-- Delete User Confirmation Modal -->
    <Dialog
      max-width="md"
      :show="photo"
      action-type="delete"
      :action="deletePhoto"
      title="Delete Item Photo?"
      :close="() => (photo = false)"
      action-text="Delete Item Photo?"
      :content="$t('Are you sure you want to delete the photo?')"
    />

    <Dialog
      max-width="md"
      :show="permanent"
      action-type="delete"
      title="Delete Item?"
      :close="closePermanentModal"
      action-text="Delete Permanently"
      :action="deleteCategoryPermanently"
      :content="`<p class='mb-2'>${$t('Are you sure you want to delete the record permanently?')}</p>
        <p>${$t('Once deleted, all of its resources and data will be permanently deleted.')}</p>`"
    />

    <!-- Delete Account Confirmation Modal -->
    <Dialog
      :show="confirm"
      :close="closeModal"
      :action="deleteItem"
      action-type="delete"
      title="Delete Item?"
      action-text="Delete Item"
      :content="$t('Are you sure you want to delete the record?')"
    />

    <!-- Restore Account Confirmation Modal -->
    <Dialog
      :show="restoreConf"
      :action="restoreItem"
      title="Restore Item!"
      :close="closeRestoreModal"
      action-text="Restore Item"
      :content="$t('Are you sure you want to restore the record?')"
    />

    <modal :show="!!galleryView" max-width="2xl" :closeable="true" @close="galleryView = null">
      <div class="p-3"><img v-if="galleryView" :src="galleryView" alt="" class="w-full h-auto rounded-md" /></div>
    </modal>

    <!-- Photo Preview Modal -->
    <modal :show="imagePreviewOpen" max-width="2xl" :closeable="true" @close="imagePreviewOpen = false">
      <div class="p-4">
        <div class="flex items-center justify-between mb-3">
          <div class="text-lg font-medium">{{ $t('Photo') }}</div>
          <button
            @click="imagePreviewOpen = false"
            class="flex items-center justify-center h-8 w-8 rounded-full text-gray-600 hover:text-gray-800 hover:bg-gray-200"
          >
            <icons name="cross" class="h-5 w-5" />
          </button>
        </div>
        <img v-if="photoPreviewUrl" :src="photoPreviewUrl" alt="" class="w-full h-auto rounded-md" />
      </div>
    </modal>

    <!-- Fetch Photo URL Modal -->
    <modal :show="showPhotoUrlModal" max-width="lg" :transparent="true" :closeable="!fetchingPhotoUrl" @close="closePhotoUrlModal">
      <div class="fp-panel">
        <div class="fp-header">
          <span class="fp-header-icon"><icons name="sparkles" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <div class="fp-title">{{ $t('Fetch Photo') }}</div>
            <div class="fp-subtitle">{{ $t('Paste one image URL, or up to 3 separated by a space. We\'ll download, compress, and attach them.') }}</div>
          </div>
          <button v-if="!fetchingPhotoUrl" type="button" class="fp-close-btn lg-tip" :data-tip="$t('Close')" @click="closePhotoUrlModal" :aria-label="$t('Close')">
            <icons name="cross" class="w-4 h-4" />
          </button>
        </div>

        <div class="fp-body">
          <span class="fp-field-label"><icons name="image" class="w-3.5 h-3.5" />{{ $t('Image URL') }}</span>
          <div class="fp-url-field" :class="{ 'fp-url-field-disabled': fetchingPhotoUrl }">
            <icons name="link" class="w-4 h-4 fp-url-field-icon" />
            <input
              ref="photoUrlField"
              v-model="photoUrlInput"
              type="text"
              class="fp-url-input"
              autofocus
              autocomplete="off"
              :placeholder="$t('https://example.com/photo.jpg (add up to 3 links separated by a space)')"
              :disabled="fetchingPhotoUrl"
              @keyup.enter="submitPhotoUrl"
            />
          </div>
        </div>

        <div class="fp-footer">
          <button type="button" class="fp-cancel-btn" :disabled="fetchingPhotoUrl" @click="closePhotoUrlModal">{{ $t('Cancel') }}</button>
          <button type="button" class="fp-submit-btn" :disabled="fetchingPhotoUrl || !photoUrlInput" @click="submitPhotoUrl">
            <icons :name="fetchingPhotoUrl ? 'refresh' : 'sparkles'" class="w-4 h-4" :class="{ 'animate-spin': fetchingPhotoUrl }" />
            {{ fetchingPhotoUrl ? $t('Fetching...') : $t('Fetch & Attach') }}
          </button>
        </div>
      </div>
    </modal>
  </admin-layout>
</template>

<script>
import Dialog from '@/Shared/Dialog.vue';
import Modal from '@/Jetstream/Modal.vue';
import CheckBox from '@/Shared/CheckBox.vue';
import CameraCapture from '@/Shared/SiteDiary/CameraCapture.vue';
import FileInput from '@/Shared/FileInput.vue';
import TextInput from '@/Shared/TextInput.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import TrashedMessage from '@/Shared/TrashedMessage.vue';
import TecDangerButton from '@/Jetstream/DangerButton.vue';
import TecSectionTitle from '@/Jetstream/SectionTitle.vue';
import TecActionMessage from '@/Jetstream/ActionMessage.vue';
import TecSecondaryButton from '@/Jetstream/SecondaryButton.vue';

export default {
  props: ['edit', 'categories', 'units'],

  components: {
    Dialog,
    Modal,
    CheckBox,
    FileInput,
    TextInput,
    AdminLayout,
    SelectInput,
    AutoComplete,
    PageHeader,
    TextareaInput,
    CameraCapture,
    TecDialogModal,
    TecDangerButton,
    TrashedMessage,
    TecSectionTitle,
    TecActionMessage,
    TecSecondaryButton,
  },

  data() {
    return {
      photo: false,
      cameraOpen: false,
      galleryView: null,
      pendingPhotos: [],
      creatingUsed: false,
      photoObjectUrl: null,
      photoLoadError: false,
      imagePreviewOpen: false,
      showPhotoUrlModal: false,
      photoUrlInput: '',
      fetchingPhotoUrl: false,
      confirm: false,
      permanent: false,
      restoreConf: false,
      symbologies: [
        { value: 'CODE128', label: this.$t('CODE128') },
        { value: 'CODE39', label: this.$t('CODE39') },
        { value: 'EAN5', label: this.$t('EAN-5') },
        { value: 'EAN8', label: this.$t('EAN-8') },
        { value: 'EAN13', label: this.$t('EAN-13') },
        { value: 'UPC', label: this.$t('UPC-A') },
      ],
      form: this.$inertia.form({
        price: this.edit ? this.edit.price : 0,
        photo: null,
        extra_photos: [],
        _method: this.edit ? 'put' : 'post',
        sku: this.edit ? this.edit.sku : null,
        name: this.edit ? this.edit.name : null,
        code: this.edit ? this.edit.code : null,
        stock: this.edit ? this.edit.stock : null,
        details: this.edit ? this.edit.details : null,
        unit_id: this.edit ? this.edit.unit_id : null,
        set_stock: this.edit ? this.edit.set_stock : null,
        symbology: this.edit ? this.edit.symbology : 'code128',
        rack_location: this.edit ? this.edit.rack_location : null,
        has_serials: this.edit ? this.edit.has_serials == 1 : null,
        category_id: this.edit ? this.edit.categories[0]?.id : null,
        has_variants: this.edit ? this.edit.has_variants == 1 : null,
        track_weight: this.edit ? this.edit.track_weight == 1 : null,
        track_quantity: this.edit ? this.edit.track_quantity == 1 : true,
        is_used: this.edit ? this.edit.is_used == 1 : false,
        child_category_id: this.edit ? this.edit.categories[1]?.id : null,
        alert_quantity: this.edit && this.edit.alert_quantity ? Number(this.edit.alert_quantity) : null,
        variants: this.edit && this.edit.variants ? this.edit.variants : [{ name: '', option: ['', ''] }],
      }),
    };
  },

  computed: {
    gallery() {
      return (this.edit && this.edit.photos) || [];
    },
    photosLeft() {
      return Math.max(0, 3 - this.gallery.length - this.pendingPhotos.length);
    },
    parents() {
      return this.categories.filter(c => !c.parent_id);
    },
    subcategories() {
      return this.categories.filter(c => c.parent_id == this.form.category_id);
    },
    photoPreviewUrl() {
      return this.photoObjectUrl || (this.edit && this.edit.photo) || null;
    },
  },

  watch: {
    'form.photo'(file) {
      if (this.photoObjectUrl) {
        URL.revokeObjectURL(this.photoObjectUrl);
        this.photoObjectUrl = null;
      }
      this.photoLoadError = false;
      if (file) {
        this.photoObjectUrl = URL.createObjectURL(file);
      }
    },
  },

  beforeUnmount() {
    if (this.photoObjectUrl) {
      URL.revokeObjectURL(this.photoObjectUrl);
    }
  },

  methods: {
    // Makes the "<name> - USED" twin of this item (same details, flagged Used, no stock) and opens it.
    async createUsed() {
      const ok = await this.$confirm(
        this.$t('Create ":name - USED" as a separate Used Item / Material? It copies the saved details of this item (not unsaved changes) and starts with no stock.', { name: this.edit.name }),
        { confirmText: this.$t('Create USED') }
      );
      if (!ok) return;
      this.creatingUsed = true;
      this.$inertia.post(this.route('items.create-used', this.edit.id), {}, { onFinish: () => (this.creatingUsed = false) });
    },
    addCaptured(file) {
      if (!file || this.photosLeft <= 0) return;
      this.pendingPhotos.push({ file, url: URL.createObjectURL(file) });
      this.form.extra_photos = this.pendingPhotos.map(p => p.file);
    },
    // several files picked at once: the first is the main photo (already set by the field), the others join the extra photos (three photos at most)
    onPickedFiles(files) {
      (files || []).slice(1).forEach(f => this.addCaptured(f));
    },
    makeMainGallery(g) {
      if (this.form.photo && !confirm(this.$t('The photo you just picked as the main photo has not been saved yet and will be dropped. Continue?'))) return;
      this.form.photo = null;
      this.$inertia.post(this.route('items.photos.main', [this.edit.id, g.id]), {}, { preserveScroll: true, preserveState: true });
    },
    // an unsaved photo becomes the main photo; an unsaved main photo that was already picked goes back among the extra photos
    makeMainPending(i) {
      const [picked] = this.pendingPhotos.splice(i, 1);
      if (!picked) return;
      const previous = this.form.photo;
      this.form.photo = picked.file;
      URL.revokeObjectURL(picked.url);
      if (previous instanceof File) this.pendingPhotos.splice(i, 0, { file: previous, url: URL.createObjectURL(previous) });
      this.form.extra_photos = this.pendingPhotos.map(p => p.file);
    },
    removePending(i) {
      const [gone] = this.pendingPhotos.splice(i, 1);
      if (gone) URL.revokeObjectURL(gone.url);
      this.form.extra_photos = this.pendingPhotos.map(p => p.file);
    },
    removeGallery(g) {
      this.$inertia.delete(this.route('items.photos.destroy', [this.edit.id, g.id]), { preserveScroll: true, preserveState: true });
    },
    removePhoto() {
      if (this.form.photo) {
        this.form.photo = null;
        return;
      }
      this.photo = true;
    },
    openPhotoUrlModal() {
      this.photoUrlInput = '';
      this.showPhotoUrlModal = true;
      // the field takes the cursor as soon as the dialog is open (after its opening transition)
      let tries = 0;
      const tick = () => {
        const el = this.$refs.photoUrlField;
        if (el && el.offsetParent !== null) {
          el.focus();
          if (document.activeElement === el) return;
        }
        if (this.showPhotoUrlModal && ++tries < 25) setTimeout(tick, 60);
      };
      this.$nextTick(tick);
    },
    closePhotoUrlModal() {
      if (this.fetchingPhotoUrl) return;
      this.showPhotoUrlModal = false;
      this.photoUrlInput = '';
    },
    // one URL, or two or three separated by a space: the first becomes the main photo, the others join the extra photos (three at most)
    async submitPhotoUrl() {
      if (this.fetchingPhotoUrl || !this.photoUrlInput) return;
      const urls = [...new Set(this.photoUrlInput.split(/[\s,]+/).filter(Boolean))].slice(0, 3);
      if (!urls.length) return;

      this.fetchingPhotoUrl = true;
      let attached = 0;
      let failed = 0;
      let message = this.$t('Failed to download that photo. Check the URL and try again.');
      for (const url of urls) {
        const asMain = attached === 0;
        if (!asMain && this.photosLeft <= 0) break;
        try {
          const res = await axios.post(this.route('items.fetchPhotoDownload'), { url, name: this.form.name }, { responseType: 'blob' });
          const file = new File([res.data], `${this.form.name || 'item'}-photo-${attached + 1}.jpg`, { type: res.data.type || 'image/jpeg' });
          if (asMain) this.form.photo = file;
          else this.addCaptured(file);
          attached++;
        } catch (err) {
          failed++;
          if (err.response?.data instanceof Blob) {
            try {
              message = JSON.parse(await err.response.data.text()).message || message;
            } catch (e) {
              // keep default message
            }
          }
        }
      }
      this.fetchingPhotoUrl = false;
      if (attached) {
        this.showPhotoUrlModal = false;
        this.photoUrlInput = '';
        this.$page.props.flash.message = this.$t('Photo attached. Save the item to keep it.') + (failed ? ` ${failed} ` + this.$t('link(s) could not be fetched.') : '');
      } else {
        this.$page.props.flash.error = message;
      }
    },
    update(list) {
      // this.form.post(this.route('items.update', this.edit.id) + (list ? '?listing=yes' : ''), {
      this.form.post(this.edit ? this.route('items.update', this.edit.id) : this.route('items.store'), {
        preserveScroll: true,
        onSuccess: () => {
          this.pendingPhotos.forEach(p => URL.revokeObjectURL(p.url));
          this.pendingPhotos = [];
          this.form.extra_photos = [];
        },
        // onFinish: () => {
        //   if (list) {
        //     this.$inertia.visit(route('items.index'), { preserveState: true, preserveScroll: true });
        //   }
        // },
      });
    },
    destroy() {
      this.confirm = true;
    },
    deleteItem() {
      this.$inertia.delete(route('items.destroy', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    closeModal() {
      this.confirm = false;
    },
    restore() {
      this.restoreConf = true;
    },
    restoreItem() {
      this.$inertia.put(this.route('items.restore', this.edit.id), {
        onSuccess: () => (this.restoreConf = false),
      });
    },
    closeRestoreModal() {
      this.restoreConf = false;
    },
    deletePermanently() {
      this.permanent = true;
    },
    deleteCategoryPermanently() {
      this.$inertia.delete(route('items.destroy.permanently', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    closePermanentModal() {
      this.permanent = false;
    },
    deletePhoto() {
      this.$inertia.delete(route('items.deletePhoto', this.edit.id), {
        onSuccess: () => {
          this.edit.photo = null;
          this.closeModal();
        },
      });
    },
  },
};
</script>

<style scoped>
/* Create USED: warm amber (the colour "Used" items already wear in the list) with a lifted shadow */
.fx-used-btn { display: inline-flex; align-items: center; gap: 8px; min-height: 2.5rem; padding: 0.35rem 1rem 0.35rem 0.4rem; border: 0; border-radius: 9999px; cursor: pointer; font-size: 0.82rem; font-weight: 800; letter-spacing: 0.01em; color: #fff; background: linear-gradient(145deg, #fbbf24 0%, #f59e0b 45%, #d97706 100%); box-shadow: 0 12px 22px -10px rgba(217, 119, 6, 0.85), 0 3px 6px rgba(217, 119, 6, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.45); text-shadow: 0 1px 1px rgba(146, 64, 14, 0.35); transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease; }
.fx-used-btn:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 16px 26px -10px rgba(217, 119, 6, 0.95), 0 4px 8px rgba(217, 119, 6, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.5); filter: saturate(1.08); }
.fx-used-btn:active:not(:disabled) { transform: translateY(0); box-shadow: 0 6px 12px -8px rgba(217, 119, 6, 0.9), inset 0 1px 0 rgba(255, 255, 255, 0.3); }
.fx-used-btn:focus-visible { outline: 3px solid rgba(245, 158, 11, 0.45); outline-offset: 2px; }
.fx-used-btn:disabled { opacity: 0.65; cursor: progress; }
.fx-used-ico { display: inline-flex; align-items: center; justify-content: center; width: 1.7rem; height: 1.7rem; border-radius: 9999px; background: rgba(255, 255, 255, 0.28); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.35); }
html.dark .fx-used-btn { box-shadow: 0 12px 22px -10px rgba(245, 158, 11, 0.55), 0 3px 6px rgba(0, 0, 0, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.3); }
.fx-form :deep(.lg-fields label:not(:has(input[type='checkbox']))) {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
}
.fx-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file'])),
.fx-form :deep(.lg-fields select),
.fx-form :deep(.lg-fields textarea),
.fx-form :deep(.fx-photo-body input:not([type='file'])) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  padding-top: 0.6rem !important;
  padding-bottom: 0.6rem !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.fx-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file']):hover),
.fx-form :deep(.lg-fields select:hover) {
  border-color: rgba(56, 189, 248, 0.7) !important;
}
.fx-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='file']):focus),
.fx-form :deep(.lg-fields select:focus),
.fx-form :deep(.lg-fields textarea:focus) {
  border-color: #38bdf8 !important;
  box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2) !important;
}
.fx-form :deep(.fx-photo-body button.bg-gray-600) {
  background: linear-gradient(145deg, #fb923c 0%, #f97316 55%, #ea580c 100%) !important;
  font-weight: 800;
  text-shadow: 0 1px 1px rgba(0, 0, 0, 0.25);
  box-shadow: 0 10px 18px -10px rgba(234, 88, 12, 0.9), 0 3px 6px rgba(234, 88, 12, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.4);
  transition: filter 0.18s ease, box-shadow 0.18s ease;
}
.fx-form :deep(.fx-photo-body button.bg-gray-600:hover) { filter: saturate(1.15) brightness(1.05); }
.fx-form :deep(.fx-photo-body .rounded-r-md) {
  border-radius: 0 0.85rem 0.85rem 0 !important;
}

/* photo */
.fx-photo { display: flex; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.fx-photo-body { flex: 1 1 260px; min-width: 0; }
.fx-photo-thumb { position: relative; flex: none; width: 84px; height: 84px; }
.fx-photo-img { width: 84px; height: 84px; object-fit: cover; border-radius: 16px; border: 1px solid var(--lg-line); cursor: pointer; box-shadow: var(--lg-shadow); }
.fx-photo-empty.is-click { cursor: pointer; transition: border-color 0.18s ease, background 0.18s ease, transform 0.18s ease; }
.fx-photo-empty.is-click:hover { border-color: #2563eb; background: rgba(37, 99, 235, 0.08); color: #2563eb; transform: translateY(-1px); }
.fx-photo-empty { display: flex; align-items: center; justify-content: center; border-radius: 16px; border: 2px dashed var(--lg-line); color: var(--lg-faint); background: var(--lg-soft); }
.fx-photo-remove { top: -8px; right: -8px; display: flex; align-items: center; justify-content: center; width: 22px; height: 22px; min-height: 0; padding: 0; border-radius: 9999px; color: #fff; background: #dc2626; box-shadow: 0 4px 10px -2px rgba(220, 38, 38, 0.6); }
.fx-photo-remove:hover { background: #b91c1c; }
.fx-fetch-btn { margin-top: 10px; }
.fx-btn-blue, .fx-btn-green { border: 0 !important; color: #fff !important; font-weight: 800; letter-spacing: 0.01em; text-shadow: 0 1px 1px rgba(0, 0, 0, 0.25); transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease; }
.fx-btn-blue { background: linear-gradient(145deg, #60a5fa 0%, #2563eb 55%, #1d4ed8 100%) !important; box-shadow: 0 12px 22px -10px rgba(37, 99, 235, 0.85), 0 3px 6px rgba(37, 99, 235, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.4); }
.fx-btn-green { background: linear-gradient(145deg, #34d399 0%, #10b981 55%, #059669 100%) !important; box-shadow: 0 12px 22px -10px rgba(5, 150, 105, 0.85), 0 3px 6px rgba(5, 150, 105, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.4); }
.fx-btn-blue:hover:not(:disabled), .fx-btn-green:hover:not(:disabled) { transform: translateY(-2px); filter: saturate(1.1); }
.fx-btn-blue:hover:not(:disabled) { box-shadow: 0 16px 26px -10px rgba(37, 99, 235, 0.95), 0 4px 8px rgba(37, 99, 235, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.45); }
.fx-btn-green:hover:not(:disabled) { box-shadow: 0 16px 26px -10px rgba(5, 150, 105, 0.95), 0 4px 8px rgba(5, 150, 105, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.45); }
.fx-btn-blue:active:not(:disabled), .fx-btn-green:active:not(:disabled) { transform: translateY(0); }
.fx-btn-blue:disabled, .fx-btn-green:disabled { opacity: 0.55; cursor: not-allowed; }
.fx-fetch-btn + .fx-fetch-btn, .fx-fetch-btn + .cc + .fx-fetch-btn { margin-left: 8px; }

/* options */
.fx-opts { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr)); }
.fx-opt { padding: 12px 14px; border-radius: 16px; border: 1px solid var(--lg-line); background: var(--lg-soft); min-width: 0; }
.fx-opt :deep(label span) { color: var(--lg-ink); font-weight: 600; font-size: 0.84rem; }
.fx-opt-hint { margin: 4px 0 0 1.5rem; font-size: 0.74rem; color: var(--lg-mute); }
.fx-opt-wide .lg-note { margin-bottom: 0; }

/* variants */
.fx-variants { display: grid; gap: 14px; margin-top: 14px; grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 1fr)); }
.fx-variant { min-width: 0; padding: 14px; border-radius: 18px; border: 1px solid var(--lg-line); background: var(--lg-soft); }
.fx-opt-grid { grid-template-columns: repeat(auto-fill, minmax(min(150px, 100%), 1fr)); gap: 0.75rem; }
.fx-stepper { display: inline-flex; flex: none; }
.fx-stepper-sm { margin-top: 1.5rem; }
.fx-step-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 0; padding: 10px 16px; color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); transition: filter 0.15s ease; }
.fx-step-btn:hover { filter: brightness(1.15); }
.fx-step-dark { background: linear-gradient(145deg, #64748b, #334155); padding: 11px 14px; }
.fx-step-l { border-radius: 9999px 0 0 9999px; border-right: 1px solid rgba(255, 255, 255, 0.25); }
.fx-step-r { border-radius: 0 9999px 9999px 0; }

/* photos taken for the item */
.fx-gal { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 8px; }
.fx-gal-item { position: relative; width: 64px; height: 64px; border-radius: 0.75rem; overflow: visible; }
.fx-gal-item img { width: 100%; height: 100%; object-fit: cover; border-radius: 0.75rem; cursor: zoom-in; border: 1px solid var(--lg-line); }
.fx-gal-item.is-new img { border-color: #10b981; box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.25); }
.fx-gal-x { position: absolute; top: -6px; right: -6px; width: 20px; height: 20px; display: inline-flex; align-items: center; justify-content: center; border: 0; border-radius: 9999px; background: #dc2626; color: #fff; cursor: pointer; }
.fx-gal-face { position: absolute; bottom: -6px; right: -6px; width: 20px; height: 20px; display: inline-flex; align-items: center; justify-content: center; border: 0; border-radius: 9999px; background: linear-gradient(145deg, #fbbf24, #d97706); color: #fff; cursor: pointer; box-shadow: 0 4px 10px -2px rgba(217, 119, 6, 0.7); }
.fx-gal-face:hover { filter: brightness(1.1); transform: scale(1.1); }
.fx-gal-count { font-size: 0.72rem; font-weight: 600; color: var(--lg-mute); }

/* Fetch Photo dialog */
.fp-panel {
  background: linear-gradient(165deg, rgba(255, 255, 255, 0.97), rgba(236, 254, 255, 0.9));
  backdrop-filter: blur(24px) saturate(180%);
  border: 1px solid rgba(255, 255, 255, 0.85);
  border-radius: 28px;
  box-shadow: 0 2px 4px rgba(15, 23, 42, 0.04), 0 30px 60px -16px rgba(8, 145, 178, 0.3), 0 10px 20px -8px rgba(15, 23, 42, 0.14);
  overflow: hidden;
  text-align: left;
}
.fp-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 20px 22px;
  background: linear-gradient(120deg, rgba(34, 211, 238, 0.16), transparent 65%);
  border-bottom: 1px solid rgba(8, 145, 178, 0.1);
}
.fp-header-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 44px;
  width: 44px;
  border-radius: 9999px;
  flex: none;
  color: #fff;
  background: linear-gradient(145deg, #22d3ee, #0891b2);
  box-shadow: 0 8px 18px -6px rgba(8, 145, 178, 0.6);
}
.fp-title {
  font-size: 1.05rem;
  font-weight: 700;
  color: #0f172a;
}
.fp-subtitle {
  font-size: 0.76rem;
  color: #64748b;
  margin-top: 2px;
}
.fp-close-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 32px;
  width: 32px;
  min-height: 0;
  padding: 0;
  border-radius: 9999px;
  flex: none;
  background: rgba(255, 255, 255, 0.75);
  border: 1px solid rgba(15, 23, 42, 0.08);
  color: #64748b;
  transition: background 0.15s ease, color 0.15s ease;
}
.fp-close-btn:hover {
  background: #fee2e2;
  color: #dc2626;
}

.fp-body {
  padding: 20px 22px 6px;
}
.fp-field-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 0.72rem;
  font-weight: 700;
  color: #0891b2;
  text-transform: uppercase;
  letter-spacing: 0.02em;
  margin-bottom: 8px;
}
.fp-url-field {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  background: #fff;
  border: 1.5px solid rgba(8, 145, 178, 0.18);
  border-radius: 9999px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.fp-url-field:focus-within {
  border-color: #22d3ee;
  box-shadow: 0 0 0 3px rgba(34, 211, 238, 0.2);
}
.fp-url-field-disabled {
  background: #f8fafc;
  opacity: 0.7;
}
.fp-url-field-icon {
  color: #22d3ee;
  flex: none;
}
.fp-url-input {
  flex: 1;
  min-width: 0;
  border: none;
  background: transparent;
  font-size: 0.86rem;
  color: #1e293b;
  outline: none;
}
.fp-url-input::placeholder {
  color: #94a3b8;
}

.fp-footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
  padding: 18px 22px 22px;
}
.fp-cancel-btn {
  padding: 10px 18px;
  border-radius: 9999px;
  font-size: 0.8rem;
  font-weight: 700;
  color: #475569;
  background: rgba(255, 255, 255, 0.8);
  border: 1px solid rgba(15, 23, 42, 0.1);
  min-height: 0;
  transition: background 0.15s ease;
}
.fp-cancel-btn:hover:not(:disabled) {
  background: #f1f5f9;
}
.fp-cancel-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.fp-submit-btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 10px 20px;
  border-radius: 9999px;
  font-size: 0.8rem;
  font-weight: 700;
  color: #fff;
  background: linear-gradient(135deg, #22d3ee, #0891b2);
  box-shadow: 0 8px 18px -6px rgba(8, 145, 178, 0.55);
  min-height: 0;
  transition: transform 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
}
.fp-submit-btn:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 10px 22px -6px rgba(8, 145, 178, 0.6);
}
.fp-submit-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
  transform: none;
}

html.dark .fp-panel {
  background: linear-gradient(165deg, rgba(15, 23, 42, 0.96), rgba(8, 51, 68, 0.9));
  border-color: rgba(255, 255, 255, 0.1);
}
html.dark .fp-title {
  color: #f1f5f9;
}
html.dark .fp-url-field {
  background: rgba(255, 255, 255, 0.06);
  border-color: rgba(255, 255, 255, 0.14);
}
html.dark .fp-url-input {
  color: #e2e8f0;
}
html.dark .fp-cancel-btn {
  background: rgba(255, 255, 255, 0.08);
  border-color: rgba(255, 255, 255, 0.14);
  color: #cbd5e1;
}
</style>

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
        <item-photo-chips v-if="edit" :item="edit" @photos="photoViewOpen = true" @bincard="binCardOpen = true" />
        <!-- the item code, read only: a click opens the Item Details window (the stock in each warehouse) -->
        <button v-if="edit && edit.code" type="button" class="fx-code-chip lg-tip tip-left" :data-tip="$t('Open the Item Details: the stock in each warehouse')" :aria-label="$t('Item code :code, open the Item Details', { code: edit.code })" :disabled="detailsLoading" @click="openDetails">
          <span class="fx-code-ico"><icons name="hash" class="w-4 h-4" /></span>
          <span class="fx-code-txt"><small>{{ $t('Item code') }}</small><b>{{ edit.code }}</b></span>
          <icons name="chevron-down" class="w-4 h-4 fx-code-go" />
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
              <zoom-image class="fx-photo-img" :src="photoPreviewUrl" fit="cover" :size="240" @open="imagePreviewOpen = true" @error="photoLoadError = true" />
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
              <file-input ref="fileInput" :model-value="null" multiple :max-files="Math.max(1, photosLeft)" @files="onPickedFiles" accept="image/png,image/jpeg,image/webp" :error="$page.props.errors.photo" :label="$t('Photos (up to 3)')" />
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
              <!-- one switch decides whether the photos taken or picked here lose their background (the cut-out is made on this device) -->
              <label class="fx-cut"><input v-model="cutOnCapture" type="checkbox" @change="rememberCut" /><span class="fx-cut-ui" aria-hidden="true"></span><span><b>{{ $t('Remove the background') }}</b><small>{{ $t('Keeps just the object. Done on this device, needs no internet after the first time.') }}</small></span></label>

              <div v-if="gallery.length || pendingPhotos.length || edit?.bincard" class="fx-gal">
                <!-- the BinCard of the item (its own photo, not counted among the three) -->
                <div v-if="edit && edit.bincard" class="fx-tile is-bincard">
                  <div class="fx-tile-img"><zoom-image :src="edit.bincard.url" fit="cover" preview="natural" :size="360" @open="binCardOpen = true" /></div>
                  <span class="fx-tile-tag">{{ $t('BinCard') }}</span>
                  <button type="button" class="fx-act is-wide" @click="binCardOpen = true"><icons name="doc-text" class="w-3.5 h-3.5" />{{ $date(edit.bincard.at) }}</button>
                </div>

                <div v-for="g in gallery" :key="'g' + g.id" class="fx-tile">
                  <div class="fx-tile-img">
                    <zoom-image :src="g.url" fit="cover" :size="240" @open="galleryView = g.url" />
                    <span v-if="savedBusy[g.id]" class="fx-busy"><i></i><small>{{ savedBusy[g.id] }}</small></span>
                  </div>
                  <div class="fx-acts">
                    <button type="button" class="fx-act is-face lg-tip" :data-tip="$t('Make this the face of the item')" :aria-label="$t('Make this the face of the item')" @click="makeMainGallery(g)"><icons name="star" class="w-3.5 h-3.5" /></button>
                    <button type="button" class="fx-act is-card lg-tip" :data-tip="$t('Use as the BinCard of the item')" :aria-label="$t('Use as the BinCard of the item')" @click="makeBinCardGallery(g)"><icons name="doc-text" class="w-3.5 h-3.5" /></button>
                    <button type="button" class="fx-act is-cut lg-tip" :disabled="g.cutout || savedBusy[g.id]" :data-tip="g.cutout ? $t('The background is already removed') : $t('Remove the background')" :aria-label="$t('Remove the background')" @click="cutOutSaved(g)"><icons name="sparkles" class="w-3.5 h-3.5" /></button>
                    <button type="button" class="fx-act is-del lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="removeGallery(g)"><icons name="cross" class="w-3.5 h-3.5" /></button>
                  </div>
                </div>

                <div v-for="(p, i) in pendingPhotos" :key="'p' + i + p.url" class="fx-tile is-new" :class="{ 'is-card': p.bincard }">
                  <div class="fx-tile-img">
                    <zoom-image :src="p.url" fit="cover" :size="240" @open="galleryView = p.url" />
                    <span v-if="p.busy" class="fx-busy"><i></i><small>{{ p.status || $t('Working') }}</small></span>
                  </div>
                  <span v-if="p.bincard" class="fx-tile-tag">{{ $t('BinCard') }}</span>
                  <div class="fx-acts">
                    <button type="button" class="fx-act is-face lg-tip" :data-tip="$t('Make this the face of the item')" :aria-label="$t('Make this the face of the item')" @click="makeMainPending(i)"><icons name="star" class="w-3.5 h-3.5" /></button>
                    <button type="button" class="fx-act is-card lg-tip" :class="{ 'is-on': p.bincard }" :data-tip="p.bincard ? $t('Not the BinCard') : $t('Use as the BinCard of the item')" :aria-label="$t('Use as the BinCard of the item')" @click="toggleBinCardPending(i)"><icons name="doc-text" class="w-3.5 h-3.5" /></button>
                    <button type="button" class="fx-act is-cut lg-tip" :class="{ 'is-on': p.cut }" :disabled="p.busy || p.cut" :data-tip="p.cut ? $t('The background is already removed') : $t('Remove the background')" :aria-label="$t('Remove the background')" @click="cutOut(i)"><icons name="sparkles" class="w-3.5 h-3.5" /></button>
                    <button type="button" class="fx-act is-del lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="removePending(i)"><icons name="cross" class="w-3.5 h-3.5" /></button>
                  </div>
                </div>
                <span class="fx-gal-count">{{ photoCount }}/3 · {{ $t('Save the item to keep new photos.') }}</span>
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
      <div class="p-3"><div v-if="galleryView" class="fx-zoomframe"><img :src="galleryView" class="fx-zoomimg" alt="" /></div></div>
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
        <div v-if="photoPreviewUrl" class="fx-zoomframe"><img :src="photoPreviewUrl" class="fx-zoomimg" alt="" /></div>
        
      </div>
    </modal>

    <photo-viewer-modal v-if="edit" :show="photoViewOpen" :photos="itemPhotoList(edit)" :title="edit.name" @close="photoViewOpen = false" />

    <!-- Item Details: the same window as on the Items list (stock in each warehouse, movements, print and PDF) -->
    <modal v-if="edit" :show="detailsOpen" max-width="4xl" :closeable="true" @close="detailsOpen = false">
      <div class="rounded-md overflow-hidden">
        <div class="fx-idm-head print:hidden">
          <span class="fx-idm-ico"><icons name="collection" class="w-5 h-5" /></span>
          <div class="fx-idm-title"><b>{{ $t('Item Details') }}</b><span>{{ edit.name }}</span></div>
          <div class="fx-idm-actions">
            <a :href="route('items.barcodes', { ids: edit.id })" target="_blank" rel="noopener" class="fx-idm-btn lg-tip" :data-tip="$t('Print Barcode')" :aria-label="$t('Print Barcode')"><icons name="doc" class="h-5 w-5" /></a>
            <a :href="route('items.report.pdf', edit.id)" class="fx-idm-btn is-green lg-tip" :data-tip="$t('Download PDF')" :aria-label="$t('Download PDF')"><icons name="download" class="h-5 w-5" /></a>
            <button type="button" class="fx-idm-btn is-blue lg-tip" :data-tip="$t('Print Report')" :aria-label="$t('Print')" @click="printDetails"><icons name="printer" class="h-5 w-5" /></button>
            <button type="button" class="fx-idm-btn is-red" :aria-label="$t('Close')" @click="detailsOpen = false"><icons name="cross" class="h-5 w-5" /></button>
          </div>
        </div>
        <div class="p-6 print:block print:h-full bg-gray-100">
          <item-details v-if="detailsItem" :item="detailsItem" :modal="true" />
        </div>
      </div>
    </modal>
    <bin-card-modal v-if="edit" :show="binCardOpen" :item-id="edit.id" @close="binCardOpen = false" @updated="$inertia.reload({ only: ['edit'], preserveScroll: true, preserveState: true })" />

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
import ZoomImage from '@/Shared/ZoomImage.vue';
import BinCardModal from '@/Shared/Item/BinCardModal.vue';
import PhotoViewerModal from '@/Shared/Item/PhotoViewerModal.vue';
import ItemPhotoChips from '@/Shared/Item/ItemPhotoChips.vue';
import ItemDetails from './Details.vue';
import axios from 'axios';
import { printWithAnimation, itemPrintSheet, printUrlInFrame } from '@/Core/printAnimation';
import { itemPhotoList } from '@/Core/itemPhotos';
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
    ZoomImage,
    BinCardModal,
    PhotoViewerModal,
    ItemPhotoChips,
    ItemDetails,
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
      binCardOpen: false,
      detailsOpen: false,
      detailsItem: null,
      detailsLoading: false,
      photoViewOpen: false,
      savedBusy: {},
      cutOnCapture: (() => { try { return localStorage.getItem('wims_cut_background') === '1'; } catch (e) { return false; } })(),
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
        bincard_index: null,
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
    // three photos in all: the face of the item, the saved photos and the new ones (a photo marked as the BinCard is not counted)
    photoCount() {
      return (this.photoPreviewUrl ? 1 : 0) + this.gallery.length + this.pendingPhotos.filter(x => !x.bincard).length;
    },
    photosLeft() {
      return Math.max(0, 3 - this.photoCount);
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
    // the Item Details window (as on the Items list): loaded when first opened, kept for the next time
    async openDetails() {
      if (this.detailsItem && this.detailsItem.id === this.edit.id) {
        this.detailsOpen = true;
        return;
      }
      this.detailsLoading = true;
      try {
        const res = await axios.get(this.route('items.show', this.edit.id) + '?json=yes');
        this.detailsItem = res.data;
        this.detailsOpen = true;
      } finally {
        this.detailsLoading = false;
      }
    },
    printDetails() {
      printWithAnimation(itemPrintSheet(this, this.detailsItem), () => printUrlInFrame(this.route('items.report.print', this.edit.id)));
    },
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
    rememberCut() {
      try { localStorage.setItem('wims_cut_background', this.cutOnCapture ? '1' : '0'); } catch (e) { /* the switch just is not remembered */ }
    },
    // keeps the photos the form will send (and which of them is the BinCard) in step with what the person sees
    syncPending() {
      this.form.extra_photos = this.pendingPhotos.map(p => p.file);
      const i = this.pendingPhotos.findIndex(p => p.bincard);
      this.form.bincard_index = i >= 0 ? i : null;
    },
    addCaptured(file) {
      if (!file || this.photosLeft <= 0) return;
      const p = { file, orig: file, url: URL.createObjectURL(file), cut: false, busy: false, status: '', bincard: false, ready: null };
      this.pendingPhotos.push(p);
      this.syncPending();
      // the photo is made small on this device while the person goes on (it is what gets sent when the item is saved)
      p.ready = import('@/Core/imagePrep').then(({ prepareImage }) => prepareImage(file)).then(small => {
        if (small !== file && this.pendingPhotos.includes(p) && !p.cut) {
          URL.revokeObjectURL(p.url);
          p.file = small;
          p.orig = small;
          p.url = URL.createObjectURL(small);
          this.syncPending();
        }
      }).catch(() => {});
      if (this.cutOnCapture) this.cutOut(this.pendingPhotos.length - 1);
    },
    // cuts the object out of a pending photo (or brings the original back): done in the browser, nothing is sent anywhere
    async cutOut(i) {
      const p = this.pendingPhotos[i];
      if (!p || p.busy) return;
      if (p.cut) {
        URL.revokeObjectURL(p.url);
        p.file = p.orig;
        p.url = URL.createObjectURL(p.orig);
        p.cut = false;
        this.syncPending();
        return;
      }
      p.busy = true;
      p.status = this.$t('Loading the cut-out engine');
      try {
        if (p.ready) await p.ready; // the small copy first
        const { removeBackground } = await import('@/Core/removeBackground');
        const blob = await removeBackground(p.orig, s => (p.status = this.$t(s)));
        const base = String(p.orig.name || 'photo').replace(/\.[^.]+$/, '');
        const { prepareImage } = await import('@/Core/imagePrep');
        const out = await prepareImage(new File([blob], base + '-cutout.png', { type: 'image/png' })); // a transparent WebP, a fraction of the PNG's size
        URL.revokeObjectURL(p.url);
        p.file = out;
        p.url = URL.createObjectURL(out);
        p.cut = true;
        this.syncPending();
      } catch (e) {
        this.$page.props.flash.error = (e && e.message && /object/.test(e.message)) ? this.$t('No object was found in this photo.') : this.$t('The background could not be removed. The photo was kept as it is.');
      } finally {
        p.busy = false;
        p.status = '';
      }
    },
    itemPhotoList,
    // the same cut-out for a photo that is already saved: the picture is cut out on this device and the cut-out replaces the saved file
    async cutOutSaved(g) {
      if (g.cutout || this.savedBusy[g.id]) return;
      this.savedBusy = { ...this.savedBusy, [g.id]: this.$t('Loading the cut-out engine') };
      try {
        const original = await (await fetch(g.url, { cache: 'no-store' })).blob();
        const { removeBackground } = await import('@/Core/removeBackground');
        const blob = await removeBackground(original, s => (this.savedBusy = { ...this.savedBusy, [g.id]: this.$t(s) }));
        const { prepareImage } = await import('@/Core/imagePrep');
        const file = await prepareImage(new File([blob], 'cutout.png', { type: 'image/png' }));
        await new Promise(resolve => {
          this.$inertia.post(this.route('items.photos.cutout', [this.edit.id, g.id]), { photo: file }, {
            forceFormData: true, preserveScroll: true, preserveState: true,
            headers: { 'X-Progress-Label': this.$t('Saving the cut-out'), 'X-Progress-Done': this.$t('Background removed') },
            onSuccess: () => this.$inertia.reload({ only: ['edit'], preserveScroll: true, preserveState: true }),
            onFinish: resolve,
          });
        });
      } catch (e) {
        this.$page.props.flash.error = (e && /object/.test(e.message || '')) ? this.$t('No object was found in this photo.') : this.$t('The background could not be removed. The photo was kept as it is.');
      } finally {
        const rest = { ...this.savedBusy };
        delete rest[g.id];
        this.savedBusy = rest;
      }
    },
    toggleBinCardPending(i) {
      const on = !this.pendingPhotos[i].bincard;
      this.pendingPhotos.forEach(p => (p.bincard = false)); // only one photo is the BinCard
      this.pendingPhotos[i].bincard = on;
      this.syncPending();
    },
    makeBinCardGallery(g) {
      this.$inertia.post(this.route('items.photos.bincard', [this.edit.id, g.id]), {}, { preserveScroll: true, preserveState: true, headers: { 'X-Progress-Label': this.$t('Setting the BinCard'), 'X-Progress-Done': this.$t('BinCard set') }, onSuccess: () => this.$inertia.reload({ only: ['edit'], preserveScroll: true, preserveState: true }) });
    },
    // every picked photo joins the photos of the item (three at most); the face of the item is only changed with the star
    onPickedFiles(files) {
      const list = files || [];
      if (this.photosLeft <= 0) {
        this.$page.props.flash.error = this.$t('This item already has 3 photos. Remove one first.');
        return;
      }
      const room = this.photosLeft; // read before the photos are added
      list.slice(0, room).forEach(f => this.addCaptured(f));
      if (list.length > room) this.$page.props.flash.error = this.$t('Only :n photo(s) fitted. An item can have 3.', { n: room });
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
      if (previous instanceof File) this.pendingPhotos.splice(i, 0, { file: previous, orig: previous, url: URL.createObjectURL(previous), cut: false, busy: false, status: '', bincard: false });
      this.syncPending();
    },
    removePending(i) {
      const [gone] = this.pendingPhotos.splice(i, 1);
      if (gone) URL.revokeObjectURL(gone.url);
      this.syncPending();
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
      if (this.photosLeft <= 0) {
        this.$page.props.flash.error = this.$t('This item already has 3 photos. Remove one first.');
        return;
      }
      const urls = [...new Set(this.photoUrlInput.split(/[\s,]+/).filter(Boolean))].slice(0, this.photosLeft);
      if (!urls.length) return;

      this.fetchingPhotoUrl = true;
      let attached = 0;
      let failed = 0;
      let message = this.$t('Failed to download that photo. Check the URL and try again.');
      for (const url of urls) {
        if (this.photosLeft <= 0) break;
        try {
          const res = await axios.post(this.route('items.fetchPhotoDownload'), { url, name: this.form.name }, { responseType: 'blob' });
          const file = new File([res.data], `${this.form.name || 'item'}-photo-${attached + 1}.jpg`, { type: res.data.type || 'image/jpeg' });
          this.addCaptured(file); // it joins the item's photos: the face of the item is not replaced
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
        this.$page.props.flash.message = this.$t('Photo added. Save the item to keep it.') + (failed ? ` ${failed} ` + this.$t('link(s) could not be fetched.') : '');
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
          this.form.bincard_index = null;
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
.fx-idm-head { display: flex; align-items: center; gap: 12px; padding: 14px 18px; background: var(--lg-card, #fff); border-bottom: 1px solid var(--lg-line, #e5e7eb); }
.fx-idm-ico { flex: none; width: 40px; height: 40px; border-radius: 13px; display: grid; place-items: center; color: #fff; background: linear-gradient(135deg, #2f6199, #1c3f66); }
.fx-idm-title { display: grid; min-width: 0; flex: 1; line-height: 1.2; } .fx-idm-title b { font-size: 1rem; } .fx-idm-title span { font-size: 0.82rem; color: var(--lg-mute, #64748b); overflow-wrap: anywhere; }
.fx-idm-actions { display: flex; align-items: center; gap: 8px; flex: none; }
.fx-idm-btn { width: 38px; height: 38px; min-height: 0; padding: 0; border-radius: 50%; display: grid; place-items: center; border: 0; cursor: pointer; background: rgba(148, 163, 184, 0.16); color: #475569; transition: background 0.15s ease, transform 0.15s ease; }
.fx-idm-btn:hover { transform: translateY(-1px); background: rgba(148, 163, 184, 0.3); }
.fx-idm-btn.is-blue { background: rgba(59, 130, 196, 0.16); color: #1c3f66; } .fx-idm-btn.is-green { background: rgba(16, 185, 129, 0.16); color: #047857; } .fx-idm-btn.is-red { background: rgba(225, 29, 72, 0.12); color: #be123c; }
@media (max-width: 479.98px) { .fx-idm-head { padding: 12px; } .fx-idm-actions { gap: 5px; } .fx-idm-btn { width: 34px; height: 34px; } .fx-idm-ico { display: none; } }

.fx-code-chip { display: inline-flex; align-items: center; text-align: left; border: 1px solid rgba(14, 116, 144, 0.25); gap: 10px; min-height: 0; padding: 5px 14px 5px 5px; border-radius: 1rem; text-decoration: none; cursor: pointer; color: #0c4a6e; background: linear-gradient(135deg, #e0f2fe, #ede9fe); border: 1px solid rgba(14, 116, 144, 0.25); box-shadow: 0 10px 18px -12px rgba(3, 105, 161, 0.75); transition: transform 0.15s ease, box-shadow 0.15s ease; }
.fx-code-chip:hover { transform: translateY(-1px); box-shadow: 0 14px 22px -12px rgba(3, 105, 161, 0.85); }
.fx-code-ico { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 0.8rem; color: #fff; background: linear-gradient(145deg, #38bdf8, #1d4ed8); box-shadow: 0 8px 14px -8px rgba(29, 78, 216, 0.9); }
.fx-code-txt { display: grid; line-height: 1.15; }
.fx-code-txt small { font-size: 0.62rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: #0369a1; }
.fx-code-txt b { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 1.05rem; font-weight: 800; letter-spacing: 0.04em; color: #0f172a; }
.fx-code-go { opacity: 0.55; transform: rotate(-90deg); transition: transform 0.15s ease, opacity 0.15s ease; }
.fx-code-chip:hover .fx-code-go { opacity: 1; transform: rotate(-90deg) translateY(3px); }
html.dark .fx-code-chip { color: #bae6fd; background: linear-gradient(135deg, rgba(14, 116, 144, 0.3), rgba(76, 29, 149, 0.3)); border-color: rgba(125, 211, 252, 0.3); }
html.dark .fx-code-txt b { color: #f1f5f9; } html.dark .fx-code-txt small { color: #7dd3fc; }

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
.fx-gal { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 12px; margin-top: 10px; }
.fx-tile { position: relative; display: grid; gap: 6px; width: 116px; min-width: 0; }
.fx-tile-img { position: relative; width: 116px; height: 116px; border-radius: 0.9rem; overflow: hidden; border: 1px solid var(--lg-line); background: repeating-conic-gradient(#e2e8f0 0 25%, #f8fafc 0 50%) 50% / 14px 14px; }
html.dark .fx-tile-img { background: repeating-conic-gradient(#0f172a 0 25%, #1e293b 0 50%) 50% / 14px 14px; }
.fx-tile.is-new .fx-tile-img { border-color: #10b981; box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.25); }
.fx-tile.is-card .fx-tile-img, .fx-tile.is-bincard .fx-tile-img { border-color: #0f766e; box-shadow: 0 0 0 2px rgba(15, 118, 110, 0.3); }
.fx-tile-tag { position: absolute; top: 6px; left: 6px; padding: 2px 8px; border-radius: 9999px; background: #0f766e; color: #fff; font-size: 0.62rem; font-weight: 800; letter-spacing: 0.03em; box-shadow: 0 4px 8px -2px rgba(15, 118, 110, 0.7); pointer-events: none; }
.fx-acts { display: grid; grid-template-columns: repeat(auto-fit, minmax(0, 1fr)); gap: 3px; justify-items: center; }
.fx-act { display: inline-flex; align-items: center; justify-content: center; gap: 4px; flex: none; box-sizing: border-box; width: 26px; min-width: 26px; height: 26px; min-height: 0; max-height: 26px; aspect-ratio: 1 / 1; padding: 0; border: 0; border-radius: 50%; line-height: 1; color: #fff; cursor: pointer; transition: transform 0.15s ease, filter 0.15s ease; }
.fx-act:hover:not(:disabled) { transform: scale(1.12); filter: brightness(1.08); } .fx-act:disabled { opacity: 0.5; cursor: progress; }
.fx-act.is-face { background: linear-gradient(145deg, #fbbf24, #d97706); box-shadow: 0 4px 10px -2px rgba(217, 119, 6, 0.7); }
.fx-act.is-card { background: linear-gradient(145deg, #2dd4bf, #0f766e); box-shadow: 0 4px 10px -2px rgba(15, 118, 110, 0.7); }
.fx-act.is-card.is-on, .fx-act.is-cut.is-on { outline: 2px solid #0f172a; outline-offset: 1px; }
.fx-act.is-cut { background: linear-gradient(145deg, #a78bfa, #6d28d9); box-shadow: 0 4px 10px -2px rgba(109, 40, 217, 0.7); }
.fx-act.is-del { background: linear-gradient(145deg, #f87171, #dc2626); box-shadow: 0 4px 10px -2px rgba(220, 38, 38, 0.7); }
.fx-act.is-wide { width: 100%; min-width: 0; aspect-ratio: auto; border-radius: 9999px; padding: 0 6px; font-size: 0.68rem; font-weight: 700; background: linear-gradient(145deg, #2dd4bf, #0f766e); box-shadow: 0 4px 10px -2px rgba(15, 118, 110, 0.7); }
.fx-busy { position: absolute; inset: 0; display: grid; place-content: center; justify-items: center; gap: 6px; background: rgba(15, 23, 42, 0.55); color: #fff; text-align: center; }
.fx-busy i { width: 26px; height: 26px; border-radius: 50%; background: conic-gradient(from 0deg, #f43f5e, #f59e0b, #22c55e, #06b6d4, #6366f1, #f43f5e); -webkit-mask: radial-gradient(farthest-side, transparent calc(100% - 3px), #000 calc(100% - 2px)); mask: radial-gradient(farthest-side, transparent calc(100% - 3px), #000 calc(100% - 2px)); animation: fx-spin 0.9s linear infinite; }
.fx-busy small { font-size: 0.64rem; font-weight: 700; padding: 0 6px; }
@keyframes fx-spin { to { transform: rotate(360deg); } }
.fx-cut { display: flex; align-items: flex-start; gap: 10px; margin-top: 10px; padding: 9px 12px; border-radius: 0.9rem; border: 1px solid var(--lg-line); background: var(--lg-soft); cursor: pointer; }
.fx-cut input { position: absolute; opacity: 0; pointer-events: none; }
.fx-cut-ui { position: relative; flex: none; width: 40px; height: 22px; margin-top: 2px; border-radius: 9999px; background: #94a3b8; transition: background 0.2s ease; }
.fx-cut-ui::after { content: ''; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; box-shadow: 0 2px 5px rgba(15, 23, 42, 0.3); transition: transform 0.2s ease; }
.fx-cut input:checked + .fx-cut-ui { background: linear-gradient(135deg, #a78bfa, #6d28d9); } .fx-cut input:checked + .fx-cut-ui::after { transform: translateX(18px); }
.fx-cut input:focus-visible + .fx-cut-ui { outline: 3px solid rgba(109, 40, 217, 0.4); outline-offset: 2px; }
.fx-cut span:last-child { display: grid; gap: 1px; } .fx-cut b { font-size: 0.84rem; } .fx-cut small { font-size: 0.72rem; line-height: 1.35; color: var(--lg-mute); }
.fx-zoomframe { height: min(70vh, 640px); border-radius: 0.9rem; overflow: hidden; background: repeating-conic-gradient(#e2e8f0 0 25%, #f8fafc 0 50%) 50% / 22px 22px; }
html.dark .fx-zoomframe { background: repeating-conic-gradient(#0f172a 0 25%, #1e293b 0 50%) 50% / 22px 22px; }
.fx-zoomimg { display: block; width: 100%; height: 100%; object-fit: contain; }
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

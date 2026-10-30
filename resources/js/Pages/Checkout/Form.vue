<template>
  <admin-layout :title="$t(edit ? 'edit_x' : 'create_x', { x: $t('Checkout') })">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="out"
        :title="edit ? $t('edit_x', { x: $t('Checkout') }) + ' (' + edit.reference + ')' : $t('create_x', { x: $t('Checkout') })"
        :subtitle="edit ? $t('Update the record by modifying the details in the form below') : $t('Please fill the form below to add new record.')"
      >
        <Link :href="route('checkouts.index')" class="lg-btn lg-btn-soft">
          <icons name="list" class="w-4 h-4" />
          {{ $t('Checkouts') }}
        </Link>
      </page-header>

      
      <form @submit.prevent="onFormSubmit" @keyup.enter="onFormEnter" class="txf-form">
        <trashed-message v-if="edit && edit.deleted_at" class="mb-4" @restore="restore">
          {{ $t('This record has been deleted.') }}
        </trashed-message>

        <!-- Details -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Details') }}</h2>
              <p class="lg-card-sub">{{ $t('Date, contact and warehouse for this record.') }}</p>
            </div>
          </header>
          <div class="lg-fields txf-fields">
            <div>
              <date-picker v-model="form.date" :error="$page.props.errors.date" :label="$t('Date')" />
            </div>
            <div>
              <auto-complete
                id="contact"
                :label="$t('Contact')"
                :suggestions="contacts"
                v-model="form.contact_id"
                :error="$page.props.errors.contact_id"
              />
            </div>
            <div>
              <auto-complete
                id="warehouse"
                stock-move
                :label="$t('Warehouse')"
                :suggestions="userWarehouses"
                v-model="form.warehouse_id"
                :error="$page.props.errors.warehouse_id"
              />
            </div>
            <div>
              <auto-complete
                clearable
                id="work_activity"
                :label="$t('Activity')"
                :suggestions="activities"
                v-model="form.work_activity_id"
                :error="$page.props.errors.work_activity_id"
              />
            </div>
          </div>
        </section>

        <!-- Items -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="bag" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Items') }}</h2>
              <p class="lg-card-sub">{{ $t('Add item to the list by search or scan barcode') }}</p>
            </div>
            <span class="lg-chip lg-chip-red txf-live"><span class="co-live-dot"></span>{{ $t('LIVE') }}</span>
          </header>

          <div class="txf-scan">
            <div class="flex items-center gap-2.5">
              <button
                type="button"
                @click="$refs.cameraScanner.show()"
                :aria-label="$t('Scan barcode with camera')"
                @mouseenter="showScanTooltip"
                @mouseleave="hideScanTooltip"
                class="co-camera-btn flex-none"
                :class="{ 'co-camera-btn-pulse': form.items.length === 0 }"
              >
                <svg class="w-[1.15rem] h-[1.15rem]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M4 7h3l2-2h6l2 2h3a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2z" />
                  <circle cx="12" cy="13" r="4" />
                </svg>
              </button>
              <div class="flex-1 min-w-0 lg-scan">
                <auto-complete
                  json
                  keep-focus
                  reset-search
                  scan
                  id="add-item"
                  @update:model-value="itemSelected"
                  :suggestions="route('items.search')"
                  :placeholder="$t('Scan barcode or search items')"
                  :defaultText="$t('Scan barcode or search items for next')"
                />
              </div>
            </div>

            <camera-barcode-scanner ref="cameraScanner" @resolved="itemSelected" />

            <teleport to="body">
              <div v-if="scanTooltip.show" class="co-scan-tooltip" :style="{ left: scanTooltip.x + 'px', top: scanTooltip.y + 'px' }">
                {{ $t('Scan Barcode with Camera') }}
                <span class="co-scan-tooltip-kbd">{{ formatShortcut(scanShortcut) }}</span>
              </div>
            </teleport>
          </div>

          <!-- Live totals -->
          <div class="lg-stats lg-stats-tight txf-stats">
            <div class="lg-stat">
              <span class="lg-stat-ico lg-ico-indigo"><icons name="bag" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <div class="lg-stat-value"><animated-number :value="form.items.length" /></div>
                <div class="lg-stat-label">{{ $t('Items') }}</div>
              </div>
            </div>
            <div class="lg-stat">
              <span class="lg-stat-ico lg-ico-emerald"><icons name="hash" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <div class="lg-stat-value"><animated-number :value="totalQuantity" use-grouping /></div>
                <div class="lg-stat-label">{{ $t('Quantity') }}</div>
              </div>
            </div>
            <div v-if="$settings.track_weight == 1" class="lg-stat">
              <span class="lg-stat-ico lg-ico-amber"><icons name="calculator" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <div class="lg-stat-value"><animated-number :value="totalWeight" use-grouping /></div>
                <div class="lg-stat-label">{{ $t('Weight') }}</div>
              </div>
            </div>
          </div>

          <div v-if="smallScreen">
            <div
              v-if="form.items.length === 0"
              class="txf-empty"
              :class="{ 'txf-empty-error': $page.props.errors.items }"
            >
              {{ $t('Add item to the list by search or scan barcode') }}
              <div v-if="$page.props.errors.items" class="text-red-600">{{ $page.props.errors.items }}</div>
            </div>
            <div v-else class="txf-rows">
              <div
                :key="item.id"
                v-for="(item, ii) in form.items"
                class="co-row-in txf-row"
                :class="{
                  error:
                    $page.props.errors['items.' + ii + '.variation_id'] ||
                    $page.props.errors['items.' + ii + '.quantity'] ||
                    $page.props.errors['items.' + ii + '.weight'],
                }"
              >
                <div class="flex items-start justify-between gap-2">
                  <h4 class="text-base font-bold" :class="{ '-mb-4': item.has_variants }">{{ item.name }} ({{ item.code }})</h4>
                  <button
                    type="button"
                    @click="removeItem(item, ii)"
                    class="text-red-600 hover:text-red-700 flex-none p-1 -m-1"
                    :aria-label="$t('Delete')"
                  >
                    <icons name="trash" class="w-4 h-4" />
                  </button>
                </div>
                <template v-if="item.has_variants && item.variants.length && item.selected.variations">
                  <div v-for="v in item.selected.variations" :key="v.sku">
                    <span class="mt-8 block" v-html="$meta(v.meta)" />
                    <div class="w-full block sm:flex items-center justify-stretch gap-4">
                      <div class="mt-4 grow flex items-center gap-4">
                        <text-input :label="$t('Quantity')" type="number" v-model="v.quantity" class="w-1/2" />
                        <div class="w-1/2">
                          <select-input :label="$t('Unit')" v-model="v.unit_id" class="w-full">
                            <option :value="item.unit.id">{{ item.unit.name }}</option>
                            <template v-if="item.unit.subunits && item.unit.subunits.length">
                              <option v-for="sub in item.unit.subunits" :key="sub.id" :value="sub.id">{{ sub.name }}</option>
                            </template>
                          </select-input>
                        </div>
                      </div>
                      <text-input
                        type="number"
                        v-model="v.weight"
                        :label="$t('Weight')"
                        class="mt-4 w-full sm:w-1/3"
                        v-if="$settings.track_weight == 1 && item.track_weight == 1"
                      />
                    </div>
                  </div>
                </template>
                <template v-else>
                  <div class="w-full block sm:flex items-center justify-stretch gap-4">
                    <div class="mt-4 grow flex items-center gap-4">
                      <text-input :label="$t('Quantity')" type="number" v-model="item.quantity" class="w-1/2" />
                      <div class="w-1/2">
                        <select-input :label="$t('Unit')" v-model="item.unit_id" class="w-full">
                          <option :value="item.unit.id">{{ item.unit.name }}</option>
                          <template v-if="item.unit.subunits && item.unit.subunits.length">
                            <option v-for="sub in item.unit.subunits" :key="sub.id" :value="sub.id">{{ sub.name }}</option>
                          </template>
                        </select-input>
                      </div>
                    </div>
                    <text-input
                      type="number"
                      :label="$t('Weight')"
                      v-model="item.weight"
                      class="mt-4 w-full sm:w-1/3"
                      v-if="$settings.track_weight == 1 && item.track_weight == 1"
                    />
                  </div>
                </template>
                <div class="mt-4">
                  <div v-if="$page.props.errors['items.' + ii + '.variation_id']" class="text-red-600 pt-1 rounded-md">
                    {{ $page.props.errors['items.' + ii + '.variation_id'].split('when').shift() }}.
                  </div>
                  <div v-if="$page.props.errors['items.' + ii + '.quantity']" class="text-red-600 pt-1 rounded-md">
                    {{ $page.props.errors['items.' + ii + '.quantity'] }}
                  </div>
                  <div v-if="$page.props.errors['items.' + ii + '.weight']" class="text-red-600 pt-1 rounded-md">
                    {{ $page.props.errors['items.' + ii + '.weight'].split('when').shift() }}.
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div v-else class="lg-table-wrap">
            <table class="lg-table txf-table">
              <thead>
              <tr class="text-left font-bold">
                <th class="w-4"><icons name="trash" className="w-4 h-4 text-gray-500" /></th>
                <th>{{ $t('Item') }}</th>
                <th class="text-center" :class="$settings.track_weight ? 'w-32 xl:w-56' : 'w-px'">
                  <span v-if="$settings.track_weight">{{ $t('Weight') }}</span>
                </th>
                <th class="text-center w-32 xl:w-56">{{ $t('Quantity') }}</th>
                <th class="text-center w-32 xl:w-56">{{ $t('Unit') }}</th>
              </tr>
              </thead>
              <template v-if="form.items.length">
                <template v-for="(item, ii) in form.items" :key="item.id">
                  <template v-if="item.selected && item.selected.variations && item.selected.variations.length">
                    <tbody class="group co-row-in">
                      <tr
                        :class="{
                          error:
                            $page.props.errors['items.' + ii + '.variation_id'] ||
                            $page.props.errors['items.' + ii + '.quantity'] ||
                            $page.props.errors['items.' + ii + '.weight'],
                        }"
                      >
                        <td class="border-t">
                          <div class="px-2 lg:pl-6 pb-2 focus:text-indigo-500"></div>
                        </td>
                        <td class="border-t" colspan="4">
                          <div class="px-2 lg:px-6 py-2 focus:text-indigo-500">
                            <!-- <button
                              type="button"
                              class="w-full text-left rounded-md transition-all hover:bg-blue-100 -my-1 py-1 hover:pl-5 focus:outline-none focus:ring focus:ring-gray-300 focus:bg-blue-100"
                            > -->
                            <h4 class="w-full lg:w-auto font-bold">
                              <span class="text-base">{{ item.name }} ({{ item.code }})</span>
                            </h4>
                            <!-- </button> -->
                          </div>
                        </td>
                      </tr>
                      <tr
                        :key="variation.id"
                        v-for="(variation, vi) in item.selected.variations"
                        :class="{
                          error:
                            $page.props.errors['items.' + ii + '.variation_id'] ||
                            $page.props.errors['items.' + ii + '.quantity'] ||
                            $page.props.errors['items.' + ii + '.weight'],
                        }"
                      >
                        <td>
                          <div class="px-2 lg:pl-6 pb-2 focus:text-indigo-500">
                            <button
                              type="button"
                              @click="removeItem(item, ii, vi)"
                              class="text-red-600 hover:text-red-700"
                              :aria-label="$t('Delete')"
                            >
                              <icons name="trash" class="w-4 h-4" />
                            </button>
                          </div>
                        </td>
                        <td>
                          <div class="px-2 lg:px-6 pb-2 focus:text-indigo-500">
                            <div v-html="$meta(variation.meta)"></div>
                          </div>
                        </td>
                        <td>
                          <div class="px-2 xl:px-6 pb-2 text-right" v-if="$settings.track_weight == 1 && item.track_weight == 1">
                            <text-input type="number" v-model="variation.weight" size="small" class="w-full block" />
                          </div>
                        </td>
                        <td>
                          <div class="px-2 xl:px-6 pb-2 text-right">
                            <text-input type="number" v-model="variation.quantity" size="small" class="w-full block" />
                          </div>
                        </td>
                        <td>
                          <div class="px-2 xl:px-6 pb-2 text-right" v-if="item.unit">
                            <select-input v-model="variation.unit_id" size="small" class="w-full block">
                              <option :value="item.unit.id">{{ item.unit.name }}</option>
                              <template v-if="item.unit.subunits && item.unit.subunits.length">
                                <option v-for="sub in item.unit.subunits" :key="sub.id" :value="sub.id">{{ sub.name }}</option>
                              </template>
                            </select-input>
                          </div>
                        </td>
                      </tr>
                      <tr
                        v-if="
                          $page.props.errors['items.' + ii + '.variation_id'] ||
                          $page.props.errors['items.' + ii + '.quantity'] ||
                          $page.props.errors['items.' + ii + '.weight']
                        "
                        :class="{
                          error:
                            $page.props.errors['items.' + ii + '.variation_id'] ||
                            $page.props.errors['items.' + ii + '.quantity'] ||
                            $page.props.errors['items.' + ii + '.weight'],
                        }"
                      >
                        <td>
                          <div class="px-2 lg:pl-6 pb-2 focus:text-indigo-500"></div>
                        </td>
                        <td colspan="4">
                          <div class="px-2 lg:px-6 pb-2 focus:text-indigo-500">
                            <div v-if="$page.props.errors['items.' + ii + '.variation_id']" class="text-red-600 pt-1 rounded-md">
                              {{ $page.props.errors['items.' + ii + '.variation_id'].split('when').shift() }}.
                            </div>
                            <div v-if="$page.props.errors['items.' + ii + '.quantity']" class="text-red-600 pt-1 rounded-md">
                              {{ $page.props.errors['items.' + ii + '.quantity'] }}
                            </div>
                            <div v-if="$page.props.errors['items.' + ii + '.weight']" class="text-red-600 pt-1 rounded-md">
                              {{ $page.props.errors['items.' + ii + '.weight'].split('when').shift() }}.
                            </div>
                          </div>
                        </td>
                      </tr>
                    </tbody>
                  </template>
                  <tbody v-else class="co-row-in">
                  <tr
                    :class="{
                      error:
                        $page.props.errors['items.' + ii + '.variation_id'] ||
                        $page.props.errors['items.' + ii + '.quantity'] ||
                        $page.props.errors['items.' + ii + '.weight'],
                    }"
                  >
                    <td class="border-t">
                      <div class="px-2 lg:pl-6 py-2 focus:text-indigo-500">
                        <button
                          type="button"
                          @click="removeItem(item, ii)"
                          class="text-red-600 hover:text-red-700"
                          :aria-label="$t('Delete')"
                        >
                          <icons name="trash" class="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                    <td class="border-t">
                      <div class="px-2 lg:px-6 py-2 focus:text-indigo-500">
                        <!-- <button
                          type="button"
                          class="w-full text-left rounded-md hover:bg-blue-100 -m-1 p-1 lg:-mx-5 lg:px-5 focus:outline-none focus:ring focus:ring-gray-300 focus:bg-blue-100"
                        > -->
                        <h4 class="w-full lg:w-auto">
                          <span class="text-base">{{ item.name }} ({{ item.code }})</span>
                        </h4>
                        <!-- </button> -->

                        <div v-if="$page.props.errors['items.' + ii + '.variation_id']" class="text-red-600 pt-1 rounded-md">
                          {{ $page.props.errors['items.' + ii + '.variation_id'].split('when').shift() }}.
                        </div>
                        <div v-if="$page.props.errors['items.' + ii + '.quantity']" class="text-red-600 pt-1 rounded-md">
                          {{ $page.props.errors['items.' + ii + '.quantity'] }}
                        </div>
                        <div v-if="$page.props.errors['items.' + ii + '.weight']" class="text-red-600 pt-1 rounded-md">
                          {{ $page.props.errors['items.' + ii + '.weight'].split('when').shift() }}.
                        </div>
                      </div>
                    </td>
                    <td class="border-t">
                      <div class="px-2 xl:px-6 py-2 text-right" v-if="$settings.track_weight == 1 && item.track_weight == 1">
                        <text-input type="number" v-model="item.weight" size="small" class="w-full block" />
                      </div>
                    </td>
                    <td class="border-t">
                      <div class="px-2 xl:px-6 py-2 text-right">
                        <text-input type="number" v-model="item.quantity" size="small" class="w-full block" />
                      </div>
                    </td>
                    <td class="border-t">
                      <div class="px-2 xl:px-6 py-2 text-right" v-if="item.unit">
                        <select-input v-model="item.unit_id" size="small" class="w-full block">
                          <option :value="item.unit.id">{{ item.unit.name }}</option>
                          <template v-if="item.unit.subunits && item.unit.subunits.length">
                            <option v-for="sub in item.unit.subunits" :key="sub.id" :value="sub.id">{{ sub.name }}</option>
                          </template>
                        </select-input>
                      </div>
                    </td>
                  </tr>
                  </tbody>
                </template>
              </template>
              <tbody v-if="form.items.length === 0">
              <tr>
                <td class="border-t px-2 lg:px-6 py-4 lg-empty" colspan="5" :class="{ 'bg-red-100 border-red-600': $page.props.errors.items }">
                  {{ $t('Add item to the list by search or scan barcode') }}
                  <div v-if="$page.props.errors.items" class="text-red-600">{{ $page.props.errors.items }}</div>
                </td>
              </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Notes -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="doc-text" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Notes') }}</h2>
              <p class="lg-card-sub">{{ $t('Optional remarks, and whether this record is still a draft.') }}</p>
            </div>
          </header>
          <div class="lg-fields txf-fields">
            <div class="lg-span-all">
              <textarea-input v-model="form.details" :error="$page.props.errors.details" :label="$t('Details')" />
            </div>
            <div class="lg-span-all" v-if="!edit || edit.draft == 1">
              <check-box
                id="draft"
                v-model:checked="form.draft"
                :error="$page.props.errors.draft"
                :label="$t('This record is draft')"
              />
            </div>
          </div>
        </section>

        <!-- Attachments -->
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
              <p class="lg-card-sub">{{ $t('You can select .png, .jpg, .pdf, .docx, .xlsx & .zip files.') }}</p>
            </div>
          </header>
          <div>
            <label for="checkout-file-upload" class="sr-only">{{ $t('Attachments') }}</label>
            <div v-if="edit && edit.attachments && edit.attachments.length" class="print:hidden pb-4 w-full">
              <ul role="list" class="txf-files">
                <li v-for="attachment in edit.attachments" :key="attachment.id" class="txf-file">
                  <div class="w-0 flex-1 flex items-center">
                    <icons name="clip" class="flex-shrink-0 h-5 w-5 text-gray-400" />
                    <span class="ml-2 flex-1 w-0 truncate"> {{ attachment.title }} </span>
                  </div>
                  <div class="ml-4 flex-shrink-0 flex items-center gap-4">
                    <a class="font-medium text-indigo-600 hover:text-indigo-500" :href="route('media.download', attachment.id)">
                      {{ $t('Download') }}
                    </a>
                    <button type="button" class="font-medium text-red-600 hover:text-red-500" @click="deleteAttachment(attachment.id)">
                      {{ $t('Delete') }}
                    </button>
                  </div>
                </li>
              </ul>
            </div>
            <attachment-picker
              v-model="chosenFiles"
              input-id="checkout-file-upload"
              accept=".png,.jpg,.jpeg,.pdf,.doc,.docx,.xls,.xlsx,.zip"
              :help-text="$t('You can select .png, .jpg, .pdf, .docx, .xlsx & .zip files.')"
            />
            <div v-if="$page.props.errors.attachments" class="text-red-600 text-sm mt-1">{{ $page.props.errors.attachments }}</div>
          </div>
        </section>

        <!-- Sticky action bar -->
        <div class="lg-actionbar">
          <div class="flex items-center flex-wrap gap-3 min-w-0">
            <template v-if="edit">
              <button
                type="button"
                @click="destroy"
                v-if="!edit.deleted_at"
                class="lg-btn lg-btn-soft txf-danger"
              >
                <icons name="trash" class="w-4 h-4" />
                {{ $t('delete_x', { x: $t('Checkout') }) }}
              </button>
              <button v-else type="button" @click="deletePermanently" class="lg-btn lg-btn-soft txf-danger">
                <icons name="trash" class="w-4 h-4" />
                {{ $t('delete_x', { x: $t('Permanently') }) }}
              </button>
            </template>
            <span class="lg-actionbar-note">
              <animated-number :value="form.items.length" /> {{ $t('Items') }} &middot;
              <animated-number :value="totalQuantity" use-grouping /> {{ $t('Quantity') }}
            </span>
          </div>
          <div class="lg-actionbar-btns">
            <tec-action-message :on="form.recentlySuccessful" class="mx-1">{{ $t('Saved.') }}</tec-action-message>
            <Link :href="route('checkouts.index')" class="lg-btn lg-btn-soft">
              {{ $t('Cancel') }}
            </Link>
            <loading-button type="submit" :loading="form.processing" :disabled="form.processing" class="lg-btn lg-btn-primary txf-save gap-1.5">
              <icons name="tick" class="w-4 h-4" />
              {{ $t('Save') }}
            </loading-button>
          </div>
        </div>
      </form>

      <section v-if="edit" class="lg-card mt-4 print:hidden">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Audit History') }}</h2>
          </div>
        </header>
        <div v-if="!history.length" class="text-sm text-center py-6 txf-muted">
          {{ $t('No history recorded for this transaction yet.') }}
        </div>
        <div v-else class="space-y-3">
          <div v-for="(entry, idx) in history" :key="idx" class="txf-history-row">
            <div class="flex items-center justify-between gap-3">
              <span class="font-semibold text-sm lg-main">{{ entry.description }}</span>
              <span class="text-xs txf-muted">{{ entry.created_at }}</span>
            </div>
            <div class="text-xs mt-0.5 txf-muted">{{ $t('By') }} {{ entry.causer }}</div>
          </div>
        </div>
      </section>
    </div>

    <!-- Select Variation Modal -->
    <select-variant-modal
      v-if="select_variant"
      :show="select_variant"
      :nf="unknown_variation"
      :variants="item.variants"
      @selected="variantSelected"
      @close="select_variant = false"
    />

    <!-- Delete User Confirmation Modal -->
    <Dialog
      max-width="md"
      :show="permanent"
      action-type="delete"
      title="Delete Checkout?"
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
      title="Delete Checkout?"
      action-text="Delete Checkout"
      :content="$t('Are you sure you want to delete the record?')"
    />

    <!-- Restore Account Confirmation Modal -->
    <Dialog
      :show="restoreConf"
      :action="restoreItem"
      title="Restore Checkout!"
      :close="closeRestoreModal"
      action-text="Restore Checkout"
      :content="$t('Are you sure you want to restore the record?')"
    />
  </admin-layout>
</template>

<script>
import _isEqual from 'lodash/isEqual';
import debounce from 'lodash/debounce';
import throttle from 'lodash/throttle';
import Dialog from '@/Shared/Dialog.vue';
import CheckBox from '@/Shared/CheckBox.vue';
import TextInput from '@/Shared/TextInput.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import TecFormSection from '@/Jetstream/FormSection.vue';
import TrashedMessage from '@/Shared/TrashedMessage.vue';
import AttachmentPicker from '@/Shared/AttachmentPicker.vue';
import TecActionMessage from '@/Jetstream/ActionMessage.vue';
import SelectVariantModal from '@/Shared/SelectVariantModal.vue';
import TecSecondaryButton from '@/Jetstream/SecondaryButton.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AnimatedNumber from '@/Shared/AnimatedNumber.vue';
import CameraBarcodeScanner from '@/Shared/CameraBarcodeScanner.vue';
import { DEFAULT_SCAN_SHORTCUT, matchesShortcut, formatShortcut } from '@/Shared/Rauncher/shortcut';

export default {
  props: {
    edit: Object,
    contacts: Array,
    warehouses: Array,
    activities: Array,
    default_activity_id: [Number, String],
    history: { type: Array, default: () => [] },
  },

  components: {
    Dialog,
    CheckBox,
    TextInput,
    DatePicker,
    SelectInput,
    AdminLayout,
    AutoComplete,
    LoadingButton,
    TextareaInput,
    TecFormSection,
    TrashedMessage,
    AttachmentPicker,
    TecActionMessage,
    SelectVariantModal,
    TecSecondaryButton,
    PageHeader,
    CameraBarcodeScanner,
    AnimatedNumber,
  },

  data() {
    return {
      chosenFiles: [],
      item: null,
      confirm: false,
      selected: false,
      permanent: false,
      restoreConf: false,
      select_variant: false,
      unknown_variation: null,
      wIW: window.innerWidth,
      enterStop: false,
      scanTooltip: { show: false, x: 0, y: 0 },
      form: this.$inertia.form({
        _method: this.edit ? 'PUT' : 'POST',
        items: [],
        attachments: null,
        details: this.edit ? this.edit.details : null,
        draft: this.edit ? this.edit.draft == 1 : false,
        reference: this.edit ? this.edit.reference : null,
        contact_id: this.edit ? this.edit.contact_id : null,
        date: this.edit ? this.edit.date : this.$formatJSDate(new Date()),
        warehouse_id: this.edit ? this.edit.warehouse_id : this.defaultWarehouseId(),
        work_activity_id: this.edit ? this.edit.work_activity_id : this.default_activity_id,
      }),
    };
  },

  computed: {
    smallScreen() {
      return this.wIW < 1024;
    },
    // Warehouses this user can transact from: their assigned ones (admins get all active).
    userWarehouses() {
      const mine = this.$page.props.myWarehouses || [];
      return mine.length ? mine : this.warehouses;
    },
    scanShortcut() {
      return this.$page.props.auth?.user?.extra_attributes?.scan_shortcut || DEFAULT_SCAN_SHORTCUT;
    },
    totalQuantity() {
      return this.form.items.reduce((sum, item) => sum + this.itemQuantity(item), 0);
    },
    totalWeight() {
      return this.form.items.reduce((sum, item) => sum + this.itemWeight(item), 0);
    },
  },

  watch: {
    select_variant: function (show) {
      this.unknown_variation = null;
      document.body.style.overflow = show ? 'hidden' : 'auto';
    },
    'form.items': {
      handler() {
        this.checkStock();
      },
      deep: true,
    },
    'form.warehouse_id': function () {
      this.checkStock();
    },
    // When the active warehouse is switched from the top bar, follow it on a new record.
    '$page.props.currentWarehouse': function (current) {
      if (!this.edit && current && current.id) {
        this.form.warehouse_id = current.id;
      }
    },
  },

  created() {
    if (this.edit) {
      this.form.items = this.edit.items.map(i => {
        let item = {
          ...i.item,
          id: i.id,
          checkout_item_id: i.id,
          item_id: i.item_id,
          price: parseFloat(i.price ?? i.item?.price ?? 0),
          unit_id: i.unit_id,
          weight: parseFloat(i.weight),
          quantity: parseFloat(i.quantity),
          old_quantity: parseFloat(i.quantity),
          selected: {
            serials: [],
            variations: i.variations.map(v => {
              let variations = {
                ...v,
                weight: parseFloat(v.pivot.weight),
                unit_id: v.pivot.unit_id || i.unit_id,
                quantity: parseFloat(v.pivot.quantity),
                old_quantity: parseFloat(v.pivot.quantity),
              };
              return variations;
            }),
          },
        };
        return item;
      });
    }
  },

  mounted() {
    if (!this.edit) {
      this.form.warehouse_id = this.defaultWarehouseId();
    }
    window.addEventListener('resize', this.onResize);
    window.addEventListener('keydown', this.onScanShortcut);
  },

  unmounted() {
    window.removeEventListener('resize', this.onResize);
    window.removeEventListener('keydown', this.onScanShortcut);
  },

  methods: {
    defaultWarehouseId() {
      const current = this.$page.props.currentWarehouse;
      if (current && current.id) return current.id;
      const mine = this.$page.props.myWarehouses || [];
      if (mine.length === 1) return mine[0].id;
      if (mine.length === 0 && !this.$super && this.$user?.warehouse_id) return this.$user.warehouse_id;
      return null;
    },
    onResize: throttle(function () {
      this.wIW = window.innerWidth;
    }, 250),
    removeItem(item, ii, vi) {
      if (vi) {
        item.selected.variations.splice(vi, 1);
        if (item.selected.variations.length < 1) {
          item.selected.variations = [];
          this.form.items.splice(ii, 1);
          this.shiftItemErrors(ii);
        } else {
          this.dropItemErrors(ii);
        }
      } else {
        this.form.items.splice(ii, 1);
        this.shiftItemErrors(ii);
      }
    },
    // Validation errors are keyed by item index (items.{i}.quantity). When a row is
    // removed the array re-indexes, so drop the removed row's errors and shift the
    // rest down to keep them aligned with their items.
    shiftItemErrors(removedIndex) {
      const errors = this.$page.props.errors;
      if (!errors) return;
      const moved = {};
      Object.keys(errors).forEach(key => {
        const m = key.match(/^items\.(\d+)\.(.+)$/);
        if (!m) return;
        const idx = parseInt(m[1], 10);
        const value = errors[key];
        delete errors[key];
        if (idx === removedIndex) return;
        const newIdx = idx > removedIndex ? idx - 1 : idx;
        moved['items.' + newIdx + '.' + m[2]] = value;
      });
      Object.assign(errors, moved);
    },
    // Clear stale errors for a single row (e.g. one of its variations was removed).
    dropItemErrors(index) {
      const errors = this.$page.props.errors;
      if (!errors) return;
      Object.keys(errors).forEach(key => {
        if (key.startsWith('items.' + index + '.')) delete errors[key];
      });
    },
    // Shapes form.items into the payload the server expects (used by both submit and
    // the live stock check) so variation keys match the validation logic.
    itemsPayload() {
      return this.form.items.map(i => ({
        ...i,
        // Persist line rows by checkout_item id; using product id here can break sync.
        id: i.checkout_item_id || (this.edit ? i.id : null),
        // Checkout validation requires a numeric price even though it is not editable in UI.
        price: Number(i.price ?? 0),
        unit: null,
        variants: null,
        variations: null,
        unit_id: i.unit_id || null,
        selected: {
          serials: i.selected.serials && i.selected.serials.length ? i.selected.serials.map(s => s.id) : [],
          variations: i.selected.variations.map(v => ({
            weight: v.weight,
            variation_id: v.id,
            quantity: v.quantity,
            unit_id: v.unit_id || null,
            old_quantity: v.old_quantity,
          })),
        },
      }));
    },
    // Live availability check: runs as items/quantities/warehouse change so stock
    // errors surface on selection instead of only on submit. Debounced to avoid spamming.
    checkStock: debounce(function () {
      const warehouse_id = this.form.warehouse_id;
      if (!warehouse_id || !this.form.items.length) {
        this.applyStockErrors({});
        return;
      }
      axios
        .post(route('stock.check'), { warehouse_id, items: this.itemsPayload() })
        .then(({ data }) => this.applyStockErrors(data.errors || {}))
        .catch(() => {});
    }, 450),
    // Replace the previous live stock (quantity) errors without touching other field errors.
    applyStockErrors(stockErrors) {
      if (!this.$page.props.errors) this.$page.props.errors = {};
      const errors = this.$page.props.errors;
      Object.keys(errors).forEach(key => {
        if (/^items\.\d+\.quantity$/.test(key)) delete errors[key];
      });
      Object.keys(stockErrors).forEach(key => {
        errors[key] = stockErrors[key];
      });
    },
    variantSelected(meta) {
      let variation = null;
      if (typeof meta === 'object' && meta !== null) {
        variation = this.item.variations.find(v => _isEqual(v.meta, meta));
      } else {
        variation = this.item.variations.find(v => v.sku == meta);
      }
      if (variation) {
        if (!this.item.selected) {
          this.item.selected = { variations: [], serials: [] };
        }
        variation.quantity = 1;
        variation.unit_id = this.item.unit_id;
        variation.weight = this.$settings.track_weight == 1 && this.item.track_weight == 1 ? 1 : 0;
        let item = this.form.items.find(i => i.item_id == this.item.id);
        if (item) {
          item.quantity += 1;
          item.weight += this.$settings.track_weight == 1 && item.track_weight == 1 ? 1 : 0;
          let exist = item.selected.variations.length ? item.selected.variations.find(v => v.id == variation.id) : null;
          if (exist) {
            exist.quantity += 1;
            exist.weight += this.$settings.track_weight == 1 && item.track_weight == 1 ? 1 : 0;
          } else {
            item.selected.variations.push(variation);
          }
        } else {
          this.item.selected.variations = [{ ...variation }];
          this.form.items.push({
            ...this.item,
            quantity: 1,
            unit_id: this.item.unit_id,
            weight: this.$settings.track_weight == 1 && this.item.track_weight == 1 ? 1 : 0,
          });
        }
        this.item = null;
        this.select_variant = false;
      } else {
        this.unknown_variation = this.$t('No match found for the item variation.');
        this.$page.props.flash.error = this.unknown_variation;
      }
    },
    itemQuantity(item) {
      if (item.selected && item.selected.variations && item.selected.variations.length) {
        return item.selected.variations.reduce((s, v) => s + (parseFloat(v.quantity) || 0), 0);
      }
      return parseFloat(item.quantity) || 0;
    },
    itemWeight(item) {
      if (item.selected && item.selected.variations && item.selected.variations.length) {
        return item.selected.variations.reduce((s, v) => s + (parseFloat(v.weight) || 0), 0);
      }
      return parseFloat(item.weight) || 0;
    },
    formatShortcut,
    onScanShortcut(e) {
      if (matchesShortcut(e, this.scanShortcut)) {
        e.preventDefault();
        this.$refs.cameraScanner.show();
      }
    },
    showScanTooltip(event) {
      const rect = event.currentTarget.getBoundingClientRect();
      this.scanTooltip = { show: true, x: rect.left + rect.width / 2, y: rect.top - 10 };
    },
    hideScanTooltip() {
      this.scanTooltip.show = false;
    },
    itemSelected(v) {
      v.item_id = v.id;
      v.selected = v.selected || { variations: [], serials: [] };
      if (v.has_variants && v.variants.length > 0) {
        this.item = { ...v, variants: v.variants.map(vr => ({ ...vr, selected: null })) };
        this.select_variant = true;
      } else {
        let item = this.form.items.find(i => i.id == v.id);
        if (item) {
          item.quantity += 1;
          item.weight += this.$settings.track_weight == 1 && item.track_weight == 1 ? 1 : 0;
        } else {
          this.form.items.push({ ...v, quantity: 1, unit_id: v.unit_id, weight: v.track_weight == 1 ? 1 : 0 });
        }
      }
    },
    // Replaces the old <tec-form-section> wrapper: same delayed submit and Enter-key guard.
    onFormSubmit() {
      setTimeout(() => {
        if (!this.enterStop) this.submit();
      }, 100);
    },
    onFormEnter() {
      this.enterStop = true;
      setTimeout(() => (this.enterStop = false), 250);
    },
    submit() {
      this.form.attachments = this.chosenFiles;

      this.form
        .transform(data => ({
          ...data,
          items: this.itemsPayload(),
        }))
        .post(this.edit ? route('checkouts.update', this.edit.id) : route('checkouts.store'), { preserveScroll: true });
    },
    destroy() {
      this.confirm = true;
    },
    deleteItem() {
      this.form.delete(route('checkouts.destroy', this.edit.id), {
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
      this.$inertia.put(this.route('checkouts.restore', this.edit.id), {
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
      this.form.delete(route('checkouts.destroy.permanently', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    closePermanentModal() {
      this.permanent = false;
    },
    deleteAttachment(id) {
      this.$inertia.form({ _method: 'DELETE' }).delete(route('media.delete', id));
    },
  },
};
</script>

<style scoped>
/* ---- Camera / scan button ---- */
.co-camera-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 2.625rem;
  width: 2.625rem;
  padding: 0;
  border: 0;
  border-radius: 0.8rem;
  background: linear-gradient(145deg, #2f6fb3, #0f2540);
  color: #fff;
  box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.9);
  transition: filter 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}
.co-camera-btn:hover {
  filter: brightness(1.12);
  transform: translateY(-1px);
}
.co-camera-btn:active {
  transform: translateY(0) scale(0.95);
}
.co-camera-btn-pulse {
  animation: co-camera-pulse 2.2s ease-in-out infinite;
}
@keyframes co-camera-pulse {
  0%, 100% { box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.9), 0 0 0 0 rgba(47, 111, 179, 0.45); }
  50% { box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.9), 0 0 0 7px rgba(47, 111, 179, 0); }
}
@media (prefers-reduced-motion: reduce) {
  .co-camera-btn-pulse { animation: none; }
}

/* ---- Row-mount flash for newly scanned/added items (plays once per DOM node) ---- */
.co-row-in {
  animation: co-row-in 0.35s ease-out both;
}
@keyframes co-row-in {
  from { opacity: 0; transform: translateY(-6px); background-color: rgba(56, 189, 248, 0.14); }
  to { opacity: 1; transform: translateY(0); }
}
@media (prefers-reduced-motion: reduce) {
  .co-row-in { animation: none; }
}

/* ---- LIVE chip ---- */
.txf-live {
  margin-left: auto;
  flex: none;
  font-size: 0.65rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}
.co-live-dot {
  width: 0.4rem;
  height: 0.4rem;
  border-radius: 9999px;
  background: currentColor;
  animation: co-live-blink 1.6s ease-in-out infinite;
}
@keyframes co-live-blink {
  0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.5); }
  50% { opacity: 0.55; box-shadow: 0 0 0 4px rgba(220, 38, 38, 0); }
}
@media (prefers-reduced-motion: reduce) {
  .co-live-dot { animation: none; }
}

/* ---- Camera button tooltip (teleported to body, positioned via getBoundingClientRect) ---- */
.co-scan-tooltip {
  position: fixed;
  transform: translate(-50%, -100%);
  z-index: 9999;
  padding: 0.4rem 0.7rem;
  border-radius: 0.5rem;
  white-space: nowrap;
  font-size: 0.75rem;
  font-weight: 600;
  line-height: 1.3;
  color: #f8fafc;
  background: linear-gradient(145deg, rgba(15, 23, 42, 0.96), rgba(30, 41, 59, 0.92));
  box-shadow: 0 10px 20px rgba(15, 23, 42, 0.25);
  pointer-events: none;
  animation: co-tooltip-in 0.12s ease-out;
}
.co-scan-tooltip-kbd {
  display: block;
  margin-top: 0.2rem;
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.03em;
  color: #93c5fd;
  font-family: inherit;
  font-variant-numeric: tabular-nums;
}
.co-scan-tooltip::after {
  content: '';
  position: absolute;
  top: 100%;
  left: 50%;
  transform: translateX(-50%);
  border: 5px solid transparent;
  border-top-color: rgba(30, 41, 59, 0.96);
}
@keyframes co-tooltip-in {
  from { opacity: 0; transform: translate(-50%, -94%); }
  to { opacity: 1; transform: translate(-50%, -100%); }
}

/* ---- Layout ---- */
.txf-form { display: block; }
@media (max-width: 480px) {
  .lg-card { padding: 1rem 0.9rem 1.1rem; }
}

/* Form inputs inside the field grids: softer, rounder, with a clear focus ring */
.txf-fields :deep(input:not([type='checkbox']):not([type='radio'])),
.txf-fields :deep(select),
.txf-fields :deep(textarea) {
  border-radius: 0.85rem !important;
  border-color: rgba(148, 163, 184, 0.45) !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.txf-fields :deep(input:not([type='checkbox']):not([type='radio']):focus),
.txf-fields :deep(select:focus),
.txf-fields :deep(textarea:focus) {
  border-color: #2f6fb3 !important;
  box-shadow: 0 0 0 3px rgba(47, 111, 179, 0.18) !important;
}
html.dark .txf-fields :deep(input:not([type='checkbox']):not([type='radio'])),
html.dark .txf-fields :deep(select),
html.dark .txf-fields :deep(textarea) {
  background: rgba(15, 23, 42, 0.55);
  border-color: rgba(255, 255, 255, 0.14) !important;
  color: #e2e8f0;
}

/* Scan panel */
.txf-scan {
  padding: 0.9rem;
  border-radius: 16px;
  background: var(--lg-soft);
  border: 1px solid var(--lg-line);
}
.txf-stats {
  margin-top: 1rem;
  margin-bottom: 1rem;
}

/* Empty state + mobile item cards */
.txf-empty {
  padding: 1.25rem 1rem;
  border-radius: 16px;
  text-align: center;
  font-size: 0.83rem;
  color: var(--lg-faint);
  border: 1px dashed var(--lg-line);
}
.txf-empty-error {
  color: #7f1d1d;
  background: rgba(254, 226, 226, 0.65);
  border: 1px solid rgba(248, 113, 113, 0.5);
}
html.dark .txf-empty-error { color: #fecaca; background: rgba(127, 29, 29, 0.25); }
.txf-rows { display: flex; flex-direction: column; gap: 0.75rem; }
.txf-row {
  padding: 0.9rem 1rem 1rem;
  border-radius: 16px;
  color: var(--lg-ink);
  background: var(--lg-card);
  border: 1px solid var(--lg-line);
  box-shadow: var(--lg-shadow);
}
.txf-row.error {
  border-color: rgba(248, 113, 113, 0.6);
  background: rgba(254, 226, 226, 0.45);
}
html.dark .txf-row.error { background: rgba(127, 29, 29, 0.25); }

/* Desktop line-item table (rows keep their own cell padding wrappers) */
.txf-table {
  white-space: normal;
  min-width: 640px;
}
.txf-table th {
  padding: 12px 8px;
  white-space: nowrap;
}
.txf-table th:first-child { padding-left: 16px; }
.txf-table td {
  padding: 0;
  border-bottom: 0;
  vertical-align: middle;
}
.txf-table tbody tr:first-child td { border-top: 1px solid var(--lg-line); }
.txf-table tbody:first-of-type tr:first-child td { border-top: 0; }
.txf-table tbody:hover td,
.txf-table tbody tr:focus-within td { background: var(--lg-soft); }
.txf-table tr.error td { background: rgba(254, 226, 226, 0.55); }
html.dark .txf-table tr.error td { background: rgba(127, 29, 29, 0.25); }
.txf-table td.lg-empty { padding: 28px 16px !important; }
@media (min-width: 1024px) {
  .txf-table th { padding: 12px 24px; }
  .txf-table th:first-child { padding-left: 24px; }
}

/* Attachments list */
.txf-files {
  overflow: hidden;
  border-radius: 14px;
  border: 1px solid var(--lg-line);
  background: var(--lg-soft);
}
.txf-file {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.65rem 1rem 0.65rem 0.8rem;
  font-size: 0.83rem;
  color: var(--lg-ink);
  border-top: 1px solid var(--lg-line);
}
.txf-file:first-child { border-top: 0; }

/* Action bar buttons */
.txf-danger { color: #be123c; border-color: rgba(244, 63, 94, 0.35); }
html.dark .txf-danger { color: #fda4af; border-color: rgba(251, 113, 133, 0.35); }
.lg-actionbar-note { white-space: nowrap; }
.txf-save.lg-btn {
  padding-top: 8px;
  padding-bottom: 8px;
  padding-left: 15px;
  min-height: 0;
  border: 0;
  border-radius: 9999px;
  font-size: 0.78rem;
  font-weight: 800;
  letter-spacing: 0;
  text-transform: none;
  color: #fff;
  background: linear-gradient(145deg, #2f6fb3, #0f2540);
  box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.9);
}
.txf-save.lg-btn:hover:not(:disabled) { background: linear-gradient(145deg, #3b82c4, #153358); }
@media (max-width: 640px) {
  .lg-actionbar-note { white-space: normal; }
}

/* Audit history */
.txf-muted { color: var(--lg-faint); }
.txf-history-row {
  padding-bottom: 10px;
  border-bottom: 1px solid var(--lg-line);
}
.txf-history-row:last-child {
  padding-bottom: 0;
  border-bottom: 0;
}
</style>

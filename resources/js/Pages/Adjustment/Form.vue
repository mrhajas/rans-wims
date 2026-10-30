<template>
  <admin-layout :title="$t(edit ? 'edit_x' : 'create_x', { x: $t('Adjustment') })">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="adjustments"
        :title="edit ? $t('edit_x', { x: $t('Adjustment') }) + ' (' + edit.reference + ')' : $t('create_x', { x: $t('Adjustment') })"
        :subtitle="edit ? $t('Update the record by modifying the details in the form below') : $t('Please fill the form below to add new record.')"
      >
        <span v-if="edit" class="lg-chip" :class="statusChip(edit.status)"><icons :name="statusIcon(edit.status)" class="w-3 h-3" />{{ $t(statusLabel(edit.status)) }}</span>
        <approval-actions v-if="edit" :adjustment="edit" />
        <change-status v-if="edit" :adjustment="edit" />
        <Link :href="route('adjustments.index')" class="lg-btn lg-btn-soft">
          <icons name="list" class="w-4 h-4" />
          <span>{{ $t('Back') }}</span>
        </Link>
      </page-header>

      <form @submit.prevent="onFormSubmit" @keyup.enter="onFormKeyup">
        <trashed-message v-if="edit && edit.deleted_at" class="mb-4" @restore="restore">
          {{ $t('This record has been deleted.') }}
        </trashed-message>

        <!-- Details -->
        <section class="lg-card">
          <div class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="info" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <div class="lg-card-title">{{ $t('Details') }}</div>
              <div class="lg-card-sub">{{ $t('Adjustment date, type and warehouse') }}</div>
            </div>
          </div>
          <div class="lg-fields apple-field-grid">
            <div>
              <date-picker v-model="form.date" :error="$page.props.errors.date" :label="$t('Date')" />
            </div>
            <div>
              <auto-complete
                id="type"
                :label="$t('Type')"
                v-model="form.type"
                :error="$page.props.errors.type"
                :suggestions="[
                  { value: 'Damage', label: $t('Damage') },
                  { value: 'Addition', label: $t('Addition') },
                  { value: 'Subtraction', label: $t('Subtraction') },
                ]"
              />
            </div>
            <div>
              <template v-if="!$super && $user.warehouse_id">
                <text-input
                  disabled
                  readonly
                  :label="$t('Warehouse')"
                  :modelValue="warehouses.find(w => w.id == $user.warehouse_id)?.name"
                />
              </template>
              <template v-else>
                <auto-complete
                  id="warehouse"
                  stock-move
                  :label="$t('Warehouse')"
                  :suggestions="warehouses"
                  v-model="form.warehouse_id"
                  :error="$page.props.errors.warehouse_id"
                />
              </template>
            </div>
          </div>
        </section>

        <!-- Items -->
        <section class="lg-card">
          <div class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="bag" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <div class="lg-card-title">{{ $t('Items') }}</div>
              <div class="lg-card-sub">{{ $t('Add item to the list by search or scan barcode') }}</div>
            </div>
            <span class="lg-chip lg-chip-red"><span class="adj-live-dot"></span>{{ $t('LIVE') }}</span>
          </div>

          <div class="adj-scan-zone apple-field-grid">
            <div class="flex items-center gap-2.5">
              <button
                type="button"
                @click="$refs.cameraScanner.show()"
                :aria-label="$t('Scan barcode with camera')"
                @mouseenter="showScanTooltip"
                @mouseleave="hideScanTooltip"
                class="adj-camera-btn flex-none"
                :class="{ 'adj-camera-btn-pulse': form.items.length === 0 }"
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
          </div>
          <div v-if="itemNotice" class="mt-2 text-sm" :class="itemNoticeType === 'warn' ? 'text-amber-700' : 'text-gray-600'">
            {{ itemNotice }}
          </div>

          <div class="lg-stats lg-stats-tight adj-totals">
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

          <camera-barcode-scanner ref="cameraScanner" @resolved="itemSelected" />

          <teleport to="body">
            <div v-if="scanTooltip.show" class="adj-scan-tooltip" :style="{ left: scanTooltip.x + 'px', top: scanTooltip.y + 'px' }">
              {{ $t('Scan Barcode with Camera') }}
              <span class="adj-scan-tooltip-kbd">{{ formatShortcut(scanShortcut) }}</span>
            </div>
          </teleport>

          <div v-if="smallScreen">
            <div
              v-if="form.items.length === 0"
              class="adj-empty"
              :class="{ 'adj-empty-error': $page.props.errors.items }"
            >
              {{ $t('Add item to the list by search or scan barcode') }}
              <div v-if="$page.props.errors.items" class="text-red-600">{{ $page.props.errors.items }}</div>
            </div>
            <div v-else>
              <div
                :key="item.id"
                v-for="(item, ii) in form.items"
                class="adj-row-in adj-m-row"
                :class="{
                  'adj-m-alt': ii % 2 != 0,
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
          <div v-else class="lg-table-wrap liquid-item-table adj-items-table">
            <table class="w-full">
              <tr class="text-left font-bold">
                <th class="px-2 lg:pl-6 py-4 w-4"><icons name="trash" /></th>
                <th class="px-2 lg:px-6 py-4">{{ $t('Item') }}</th>
                <th class="px-2 lg:px-6 py-4 text-center" :class="$settings.track_weight ? 'w-32 xl:w-56' : 'w-px'">
                  <span v-if="$settings.track_weight">{{ $t('Weight') }}</span>
                </th>
                <th class="px-2 lg:px-6 py-4 text-center w-32 xl:w-56">{{ $t('Quantity') }}</th>
                <th class="px-2 lg:px-6 py-4 text-center w-32 xl:w-56">{{ $t('Unit') }}</th>
              </tr>
              <template v-if="form.items.length">
                <template v-for="(item, ii) in form.items" :key="item.id">
                  <template v-if="item.selected && item.selected.variations && item.selected.variations.length">
                    <tbody class="group adj-row-in">
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
                            <h4 class="w-full lg:w-auto font-bold">
                              <span class="text-base">{{ item.name }} ({{ item.code }})</span>
                            </h4>
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
                        <td colspan="3">
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
                  <tr
                    v-else
                    class="adj-row-in"
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
                        <h4 class="w-full lg:w-auto">
                          <span class="text-base">{{ item.name }} ({{ item.code }})</span>
                        </h4>

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
                </template>
              </template>
              <tr v-if="form.items.length === 0">
                <td class="border-t px-2 lg:px-6 py-4 adj-empty-cell" colspan="5" :class="{ 'bg-red-100 border-red-600': $page.props.errors.items }">
                  {{ $t('Add item to the list by search or scan barcode') }}
                  <div v-if="$page.props.errors.items" class="text-red-600">{{ $page.props.errors.items }}</div>
                </td>
              </tr>
            </table>
          </div>
        </section>

        <!-- Notes & Attachments -->
        <section class="lg-card">
          <div class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <div class="lg-card-title">{{ $t('Notes & Attachments') }}</div>
              <div class="lg-card-sub">{{ $t('Optional notes and supporting files for this record') }}</div>
            </div>
          </div>
          <div class="lg-fields apple-field-grid">
            <div>
              <label for="adjustment-file-upload" class="font-medium text-gray-700">{{ $t('Attachments') }}</label>
              <div v-if="edit && edit.attachments && edit.attachments.length" class="print:hidden py-4 w-full">
                <ul role="list" class="border border-gray-200 rounded-md divide-y divide-gray-200">
                  <li
                    v-for="attachment in edit.attachments"
                    :key="attachment.id"
                    class="pl-3 pr-4 py-3 flex items-center justify-between text-sm"
                  >
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
                input-id="adjustment-file-upload"
                accept=".png,.jpg,.jpeg,.pdf,.doc,.docx,.xls,.xlsx,.zip"
                :help-text="$t('You can select .png, .jpg, .pdf, .docx, .xlsx & .zip files.')"
                class="mt-1"
              />
              <div v-if="$page.props.errors.attachments" class="text-red-600 text-sm mt-1">{{ $page.props.errors.attachments }}</div>
            </div>
            <div class="lg-span-2 flex flex-col gap-4">
              <textarea-input v-model="form.details" :error="$page.props.errors.details" :label="$t('Details')" />
              <p class="text-sm text-gray-600">
                <template v-if="!edit">{{ $t('New adjustments are created as Pending and only change stock once an Admin approves them.') }}</template>
                <template v-else-if="edit.status === 'Pending' && edit.can_approve">{{ $t('This adjustment is waiting for approval, use Approve or Reject at the top of the page.') }}</template>
                <template v-else-if="edit.status === 'Pending'">{{ $t('This adjustment is waiting for an Admin to approve it.') }}</template>
              </p>
            </div>
          </div>
        </section>

        <!-- Sticky action bar -->
        <div class="lg-actionbar print:hidden">
          <div class="lg-actionbar-note">
            <animated-number :value="form.items.length" /> {{ $t('Items') }}
            &middot;
            <animated-number :value="totalQuantity" use-grouping /> {{ $t('Quantity') }}
          </div>
          <div class="lg-actionbar-btns">
            <template v-if="edit">
              <button
                type="button"
                @click="destroy"
                v-if="!edit.deleted_at"
                class="lg-btn lg-btn-soft fm-danger"
              >
                <icons name="trash" class="w-4 h-4" />
                <span>{{ $t('delete_x', { x: $t('Adjustment') }) }}</span>
              </button>
              <button
                v-else
                type="button"
                @click="deletePermanently"
                class="lg-btn lg-btn-soft fm-danger"
              >
                <icons name="trash" class="w-4 h-4" />
                <span>{{ $t('delete_x', { x: $t('Permanently') }) }}</span>
              </button>
            </template>
            <tec-action-message :on="form.recentlySuccessful" class="mx-1">{{ $t('Saved.') }}</tec-action-message>
            <Link :href="route('adjustments.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <loading-button type="submit" :loading="form.processing" :disabled="form.processing" class="lg-btn lg-btn-primary fm-save gap-1.5">
              <icons name="tick" class="w-4 h-4" /> {{ $t('Save') }}
            </loading-button>
          </div>
        </div>
      </form>

      <section v-if="edit" class="lg-card print:hidden">
        <div class="lg-card-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="lg-card-title">{{ $t('Audit History') }}</div>
          </div>
        </div>
        <div v-if="!history.length" class="lg-subtext text-center py-6">
          {{ $t('No history recorded for this transaction yet.') }}
        </div>
        <div v-else class="space-y-3">
          <div v-for="(entry, idx) in history" :key="idx" class="txn-history-row">
            <div class="flex flex-wrap items-center justify-between gap-x-3">
              <span class="lg-main text-sm">{{ entry.description }}</span>
              <span class="lg-subtext">{{ entry.created_at }}</span>
            </div>
            <div class="lg-subtext">{{ $t('By') }} {{ entry.causer }}</div>
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
      title="Delete Adjustment?"
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
      title="Delete Adjustment?"
      action-text="Delete Adjustment"
      :content="$t('Are you sure you want to delete the record?')"
    />

    <!-- Restore Account Confirmation Modal -->
    <Dialog
      :show="restoreConf"
      :action="restoreItem"
      title="Restore Adjustment!"
      :close="closeRestoreModal"
      action-text="Restore Adjustment"
      :content="$t('Are you sure you want to restore the record?')"
    />
  </admin-layout>
</template>

<script>
import { onRowAdded } from '@/Core/revealLast';
import _isEqual from 'lodash/isEqual';
import throttle from 'lodash/throttle';
import Dialog from '@/Shared/Dialog.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import CheckBox from '@/Shared/CheckBox.vue';
import ApprovalActions from '@/Shared/Adjustment/ApprovalActions.vue';
import ChangeStatus from '@/Shared/Adjustment/ChangeStatus.vue';
import { adjustmentStatusMethods } from '@/Shared/Adjustment/status';
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
import AnimatedNumber from '@/Shared/AnimatedNumber.vue';
import CameraBarcodeScanner from '@/Shared/CameraBarcodeScanner.vue';
import { DEFAULT_SCAN_SHORTCUT, matchesShortcut, formatShortcut } from '@/Shared/Rauncher/shortcut';

export default {
  props: {
    edit: Object,
    contacts: Array,
    warehouses: Array,
    history: { type: Array, default: () => [] },
  },

  components: {
    Dialog,
    PageHeader,
    CheckBox,
    ApprovalActions,
    ChangeStatus,
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
    CameraBarcodeScanner,
    AnimatedNumber,
  },

  data() {
    return {
      chosenFiles: [],
      enterStop: false,
      item: null,
      confirm: false,
      selected: false,
      permanent: false,
      restoreConf: false,
      select_variant: false,
      unknown_variation: null,
      itemNotice: null,
      itemNoticeType: 'info',
      itemNoticeTimer: null,
      wIW: window.innerWidth,
      scanTooltip: { show: false, x: 0, y: 0 },
      form: this.$inertia.form({
        _method: this.edit ? 'PUT' : 'POST',
        items: [],
        attachments: null,
        type: this.edit ? this.edit.type : 'Damage',
        details: this.edit ? this.edit.details : null,
        reference: this.edit ? this.edit.reference : null,
        date: this.edit ? this.edit.date : this.$formatJSDate(new Date()),
        warehouse_id: this.edit ? this.edit.warehouse_id : this.$super ? null : this.$user?.warehouse_id,
      }),
    };
  },

  computed: {
    smallScreen() {
      return this.wIW < 1024;
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
    'form.items.length': onRowAdded('.adj-row-in'),
    select_variant: function (show) {
      this.unknown_variation = null;
      document.body.style.overflow = show ? 'hidden' : 'auto';
    },
  },

  created() {
    if (this.edit) {
      this.form.items = this.edit.items.map(i => {
        let item = {
          ...i.item,
          id: i.id,
          details: '',
          item_id: i.item_id,
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
    if (!this.edit && !this.$super) {
      this.form.warehouse_id = this.$user.warehouse_id;
    }
    window.addEventListener('resize', this.onResize);
    window.addEventListener('keydown', this.onScanShortcut);
  },

  unmounted() {
    window.removeEventListener('resize', this.onResize);
    window.removeEventListener('keydown', this.onScanShortcut);
    if (this.itemNoticeTimer) {
      clearTimeout(this.itemNoticeTimer);
    }
  },

  methods: {
    ...adjustmentStatusMethods,
    setItemNotice(message, type = 'info') {
      this.itemNotice = message;
      this.itemNoticeType = type;
      if (this.itemNoticeTimer) {
        clearTimeout(this.itemNoticeTimer);
      }
      this.itemNoticeTimer = setTimeout(() => {
        this.itemNotice = null;
      }, 3000);
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
        }
      } else {
        this.form.items.splice(ii, 1);
      }
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
          this.setItemNotice(this.$t('Item already exists in the list. Quantity has been increased.'), 'warn');
        } else {
          this.form.items.push({ ...v, quantity: 1, unit_id: v.unit_id, weight: v.track_weight == 1 ? 1 : 0 });
          this.setItemNotice(this.$t('Item added to the list.'), 'info');
        }
      }
    },
    // Replaces the old FormSection wrapper's submit handling: a keyup of Enter briefly
    // suppresses the (debounced) submit so Enter in autocompletes does not save the form.
    onFormKeyup(e) {
      if (e.code == 'Enter') {
        this.enterStop = true;
        setTimeout(() => (this.enterStop = false), 250);
      }
    },
    onFormSubmit() {
      setTimeout(() => {
        if (!this.enterStop) {
          this.submit();
        }
      }, 100);
    },
    submit() {
      this.form.attachments = this.chosenFiles;

      this.form
        .transform(data => ({
          ...data,
          items: data.items.map(i => ({
            ...i,
            unit: null,
            variants: null,
            variations: null,
            unit_id: i.unit_id || null,
            selected: {
              serials: i.selected.serials && i.selected.serials.length ? i.selected.serials.map(s => s.id) : [],
              variations: i.selected.variations.map(v => {
                let fv = {};
                return (fv[v.id] = {
                  weight: v.weight,
                  variation_id: v.id,
                  quantity: v.quantity,
                  unit_id: v.unit_id || null,
                  old_quantity: v.old_quantity,
                });
              }),
            },
          })),
        }))
        .post(this.edit ? route('adjustments.update', this.edit.id) : route('adjustments.store'), {
          preserveScroll: true,
          preserveState: false,
        });
    },
    destroy() {
      this.confirm = true;
    },
    deleteItem() {
      this.form.delete(route('adjustments.destroy', this.edit.id), {
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
      this.$inertia.put(this.route('adjustments.restore', this.edit.id), {
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
      this.form.delete(route('adjustments.destroy.permanently', this.edit.id), {
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
/* Sticky action bar buttons */
.fm-danger { color: #b91c1c; border-color: rgba(239, 68, 68, 0.35); }
html.dark .fm-danger { color: #fca5a5; }
.fm-save {
  padding-top: 8px !important;
  padding-bottom: 8px !important;
  border-radius: 9999px !important;
  border-color: transparent !important;
  text-transform: none !important;
  letter-spacing: normal !important;
  font-size: 0.82rem !important;
  font-weight: 700 !important;
  background: linear-gradient(145deg, #2f6fb3, #0f2540) !important;
  box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.9);
}
.fm-save:disabled { opacity: 0.6; }

.adj-camera-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 2.625rem;
  width: 2.625rem;
  padding: 0;
  border: 0;
  border-radius: 0.85rem;
  background: linear-gradient(145deg, #2563eb, #1d4ed8);
  color: #fff;
  box-shadow: 0 2px 6px -1px rgba(29, 78, 216, 0.45), 0 0 0 1px rgba(29, 78, 216, 0.15);
  transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}
.adj-camera-btn:hover {
  background: linear-gradient(145deg, #3b82f6, #1e40af);
  box-shadow: 0 4px 10px -1px rgba(29, 78, 216, 0.5), 0 0 0 1px rgba(29, 78, 216, 0.2);
  transform: translateY(-1px);
}
.adj-camera-btn:active {
  transform: translateY(0) scale(0.95);
  box-shadow: 0 1px 3px -1px rgba(29, 78, 216, 0.45);
}
.adj-camera-btn-pulse {
  animation: adj-camera-pulse 2.2s ease-in-out infinite;
}
@keyframes adj-camera-pulse {
  0%, 100% { box-shadow: 0 2px 6px -1px rgba(29, 78, 216, 0.45), 0 0 0 0 rgba(37, 99, 235, 0.45); }
  50% { box-shadow: 0 2px 6px -1px rgba(29, 78, 216, 0.45), 0 0 0 7px rgba(37, 99, 235, 0); }
}
@media (prefers-reduced-motion: reduce) {
  .adj-camera-btn-pulse { animation: none; }
}

/* Scan zone + totals tiles */
.adj-scan-zone {
  padding: 0.8rem;
  border-radius: 16px;
  border: 1px dashed color-mix(in srgb, var(--lg-navy) 30%, transparent);
  background: var(--lg-soft);
}
.adj-totals { margin-top: 1rem; margin-bottom: 1rem; }

/* Row-mount flash for newly scanned/added items. */
.adj-row-in {
  animation: adj-row-in 0.35s ease-out both;
}
@keyframes adj-row-in {
  from { opacity: 0; transform: translateY(-6px); background-color: rgba(56, 189, 248, 0.14); }
  to { opacity: 1; transform: translateY(0); }
}
@media (prefers-reduced-motion: reduce) {
  .adj-row-in { animation: none; }
}

.adj-live-dot {
  width: 0.4rem;
  height: 0.4rem;
  border-radius: 9999px;
  background: currentColor;
  animation: adj-live-blink 1.6s ease-in-out infinite;
}
@keyframes adj-live-blink {
  0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.5); }
  50% { opacity: 0.55; box-shadow: 0 0 0 4px rgba(220, 38, 38, 0); }
}
@media (prefers-reduced-motion: reduce) {
  .adj-live-dot { animation: none; }
}

.adj-scan-tooltip {
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
  animation: adj-tooltip-in 0.12s ease-out;
}
.adj-scan-tooltip-kbd {
  display: block;
  margin-top: 0.2rem;
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.03em;
  color: #93c5fd;
  font-family: inherit;
  font-variant-numeric: tabular-nums;
}
.adj-scan-tooltip::after {
  content: '';
  position: absolute;
  top: 100%;
  left: 50%;
  transform: translateX(-50%);
  border: 5px solid transparent;
  border-top-color: rgba(30, 41, 59, 0.96);
}
@keyframes adj-tooltip-in {
  from { opacity: 0; transform: translate(-50%, -94%); }
  to { opacity: 1; transform: translate(-50%, -100%); }
}

/* Item list: empty state + mobile cards */
.adj-empty {
  padding: 1.4rem 1rem;
  border-radius: 16px;
  text-align: center;
  font-size: 0.85rem;
  color: var(--lg-mute);
  border: 1px dashed var(--lg-line);
  background: var(--lg-soft);
}
.adj-empty-error { background: rgba(254, 226, 226, 0.7); border-color: #dc2626; }
.adj-empty-cell { color: var(--lg-mute); }
.adj-m-row {
  margin-bottom: 0.75rem;
  padding: 0.9rem;
  border-radius: 16px;
  border: 1px solid var(--lg-line);
  background: var(--lg-soft);
}
.adj-m-row.adj-m-alt { background: var(--lg-card); }
.adj-m-row h4 { color: var(--lg-ink); min-width: 0; overflow-wrap: anywhere; }

/* Item table (desktop) */
.liquid-item-table :deep(table) { border-collapse: separate; border-spacing: 0; }
.liquid-item-table :deep(th) {
  font-size: 0.66rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--lg-faint);
  background: var(--lg-soft);
  border-bottom: 1px solid var(--lg-line);
}
.liquid-item-table :deep(td) { vertical-align: middle; color: var(--lg-ink); border-color: var(--lg-line); }
.liquid-item-table :deep(tr:hover > td),
.liquid-item-table :deep(tbody:hover > tr > td),
.liquid-item-table :deep(tr:focus-within > td) { background: var(--lg-soft); }

/* Field styling inherited from the old form: uppercase labels + rounded inputs. */
.apple-field-grid :deep(label:not(:has(input[type='checkbox']))) {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: #64748b;
}
.apple-field-grid :deep(input:not([type='checkbox']):not([type='radio'])),
.apple-field-grid :deep(select),
.apple-field-grid :deep(textarea) {
  border-radius: 0.85rem !important;
  border-color: rgba(148, 163, 184, 0.4) !important;
  background: rgba(255, 255, 255, 0.85);
  padding-top: 0.6rem !important;
  padding-bottom: 0.6rem !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}
.apple-field-grid :deep(input:not([type='checkbox']):not([type='radio']):hover),
.apple-field-grid :deep(select:hover) {
  border-color: rgba(251, 146, 60, 0.6) !important;
}
.apple-field-grid :deep(input:not([type='checkbox']):not([type='radio']):focus),
.apple-field-grid :deep(select:focus),
.apple-field-grid :deep(textarea:focus) {
  border-color: #fb923c !important;
  box-shadow: 0 0 0 3px rgba(251, 146, 60, 0.18) !important;
  background: #ffffff;
}
html.dark .apple-field-grid :deep(label:not(:has(input[type='checkbox']))) {
  color: #94a3b8;
}
html.dark .apple-field-grid :deep(input:not([type='checkbox']):not([type='radio'])),
html.dark .apple-field-grid :deep(select),
html.dark .apple-field-grid :deep(textarea) {
  background: rgba(15, 23, 42, 0.55);
  border-color: rgba(255, 255, 255, 0.12) !important;
  color: #e2e8f0;
}
html.dark .adj-empty-error { background: rgba(127, 29, 29, 0.25); }

.txn-history-row {
  border-bottom: 1px solid var(--lg-line);
  padding-bottom: 10px;
}
.txn-history-row:last-child {
  border-bottom: none;
  padding-bottom: 0;
}
</style>

<template>
  <admin-layout :title="$t(edit ? 'edit_x' : 'create_x', { x: $t('Super Adjustment') })">
    <div class="lg-page px-4 md:px-0">
      <page-header
        icon="adjustments"
        :title="edit ? $t('edit_x', { x: $t('Super Adjustment') }) + ' (' + edit.reference + ')' : $t('create_x', { x: $t('Super Adjustment') })"
        :subtitle="$t('Record a physical stock count for a project/warehouse. The system compares it against current stock and, once Super Admin approves, creates the correcting Adjustment(s).')"
      >
        <Link :href="route('super-adjustments.index')" class="lg-btn lg-btn-soft">
          <icons name="list" class="w-4 h-4" />
          <span>{{ $t('Back') }}</span>
        </Link>
      </page-header>

      <form @submit.prevent="submit">
        <div v-if="edit && edit.status !== 'Draft'" class="lg-note lg-note-amber mb-3">
          <icons name="exclamation" class="w-4 h-4" />
          <span>{{ edit.status === 'Approved' ? $t('This count is Approved. Saving returns it to Draft and reverses the stock changes it applied; it then needs approving again.') : $t('This count was Rejected. Saving returns it to Draft so it can be approved again.') }}</span>
        </div>
        <!-- Details -->
        <section class="lg-card">
          <div class="lg-card-head">
            <span class="lg-card-ico lg-ico-sky"><icons name="info" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <div class="lg-card-title">{{ $t('Details') }}</div>
              <div class="lg-card-sub">{{ $t('Where, when and why the count was taken') }}</div>
            </div>
          </div>
          <div class="lg-fields sa-fields">
            <div>
              <tec-label :value="$t('Project(s) / Warehouse(s)')" />
              <auto-complete
                id="warehouse"
                class="mt-1"
                :suggestions="warehouses"
                v-model="form.warehouse_id"
                :placeholder="$t('Search project / warehouse…')"
                :error="$page.props.errors.warehouse_id"
                :disable="!!edit"
              />
            </div>
            <div>
              <tec-label :value="$t('Date')" />
              <text-input disabled readonly class="mt-1 block w-full" :model-value="$date(today)" />
            </div>
            <div>
              <tec-label :value="$t('Responsible User')" />
              <text-input disabled readonly class="mt-1 block w-full" :model-value="responsibleUser?.name" />
            </div>
            <div class="lg-span-all">
              <tec-label for="reason" :value="$t('Reason')" />
              <auto-complete
                id="reason"
                class="mt-1"
                freeform
                :suggestions="reasonOptions"
                v-model="form.reason"
                :placeholder="$t('Select or type a reason…')"
                :error="$page.props.errors.reason"
              />
            </div>
            <div class="lg-span-all">
              <tec-label for="details" :value="$t('Details / Remarks')" />
              <textarea id="details" class="sa-textarea mt-1 block w-full" rows="3" v-model="form.details" :placeholder="$t('Any additional context about this physical count…')"></textarea>
              <input-error :message="$page.props.errors.details" class="mt-1" />
            </div>
          </div>
        </section>

        <!-- Physical count -->
        <section class="lg-card">
          <div class="lg-card-head">
            <span class="lg-card-ico lg-ico-amber"><icons name="collection" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <div class="lg-card-title">{{ $t('Physical Stock Count') }}</div>
              <div class="lg-card-sub">{{ $t('Add the items you counted and enter the physical quantity for each') }}</div>
            </div>
          </div>

          <!-- an open Adjustment Session of this Project / Warehouse: this count is added to it and the "items not in this count" question is off -->
          <div v-if="openSession" class="lg-note lg-note-amber sa-session">
            <icons name="clock" class="w-4 h-4" />
            <span>{{ $t('Adjustment Session :r is open for this Project / Warehouse. This count is added to it, and the items you do not count here are not asked about: the session keeps track of them and you decide when you end it.', { r: openSession.reference }) }} <a :href="openSession.url" target="_blank" rel="noopener" class="sa-session-link">{{ $t('Open the session') }}</a></span>
          </div>
          <div v-if="!form.warehouse_id" class="lg-note lg-note-blue">
            <icons name="info" class="w-4 h-4" />
            <span>{{ $t('Select a Project / Warehouse above before adding items.') }}</span>
          </div>
          <template v-else>
            <div class="sa-scan-zone">
              <div class="sa-scan-top">
                <div class="sa-scan-hint">
                  <icons name="search" class="w-4 h-4" />
                  <span>{{ $t('Search items to count and add them to the list') }}</span>
                </div>
                <div class="sa-import-btns">
                  <button type="button" class="lg-btn lg-btn-soft sa-import" :disabled="importing" @click="importFromWarehouse">
                    <icons :name="importing === 'all' ? 'cog' : 'download'" class="w-4 h-4" :class="{ 'animate-spin': importing === 'all' }" />
                    <span>{{ importing === 'all' ? $t('Importing…') : $t('Import from Warehouse') }}</span>
                  </button>
                  <button v-if="form.items.length" type="button" class="lg-btn lg-btn-soft sa-import sa-import--zero" :disabled="importing" @click="resetItems">
                    <icons name="cog" class="w-4 h-4" />
                    <span>{{ $t('Reset') }}</span>
                  </button>
                  <button v-if="zeroCount" type="button" class="lg-btn lg-btn-soft sa-import sa-import--zero" :disabled="importing" @click="removeZeroItems">
                    <icons name="trash" class="w-4 h-4" />
                    <span>{{ $t('Remove Zero Quantity Items') }} ({{ zeroCount }})</span>
                  </button>
                  <button type="button" class="lg-btn lg-btn-soft sa-import" :disabled="importing" @click="openCategoryImport">
                    <icons :name="importing === 'category' ? 'cog' : 'collection'" class="w-4 h-4" :class="{ 'animate-spin': importing === 'category' }" />
                    <span>{{ importing === 'category' ? $t('Importing…') : $t('Import by Category') }}</span>
                  </button>
                </div>
              </div>
              <div v-if="importNote" class="sa-import-note" :class="{ 'is-err': importErr }" role="status"><icons :name="importErr ? 'exclamation' : 'tick'" class="w-4 h-4" /><span>{{ importNote }}</span></div>
              <div class="sa-scanrow">
                <div class="lg-scan">
                  <auto-complete
                    json
                    keep-focus
                    reset-search
                  scan
                    id="add-item"
                    @update:model-value="itemSelected"
                    :suggestions="route('items.search')"
                    :placeholder="$t('Search items to count…')"
                  />
                </div>
                <button type="button" class="lg-btn lg-btn-soft flex-none" :aria-label="$t('Scan barcode with camera')" @click="$refs.scanner.show()"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h3l2-2h6l2 2h3a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2z" /><circle cx="12" cy="13" r="4" /></svg>{{ $t('Scan') }}</button>
                <camera-barcode-scanner ref="scanner" @resolved="itemSelected" />
                <!-- jumps to the Physical Count of the last item added, with the cursor ready to type -->
                <button v-if="form.items.length" type="button" class="sa-jump lg-tip" :data-tip="$t('Go to the last item added')" :aria-label="$t('Go to the last item added')" @click="goToLastCount"><icons name="down" class="w-5 h-5" /></button>
              </div>
            </div>

            <stat-tiles v-if="form.items.length" :items="statItems" class="sa-tiles" />

            <div class="lg-table-wrap sa-wrap" :class="{ 'is-importing': importing }" :aria-busy="!!importing">
              <!-- shown while the stock is being imported: a moving bar and a few shimmering rows -->
              <div v-if="importing" class="sa-loading" role="status">
                <div class="sa-loading__bar"><span></span></div>
                <div class="sa-loading__msg"><icons name="cog" class="w-5 h-5 animate-spin" /><b>{{ importing === 'all' ? $t('Importing the whole stock of this Project / Warehouse…') : $t('Importing the items of :c…', { c: importLabel }) }}</b></div>
                <div v-for="n in 4" :key="n" class="sa-loading__row"><i></i><i></i><i></i></div>
              </div>
              <table class="lg-table lg-responsive">
                <thead>
                  <tr>
                    <th class="sa-no-th">{{ $t('No') }}</th>
                    <th>{{ $t('Item') }}</th>
                    <th>{{ $t('Unit') }}</th>
                    <th class="num">{{ $t('System Stock') }}</th>
                    <th class="num">{{ $t('Physical Count') }}</th>
                    <th class="sa-clear-th">
                      <label class="sa-clear-all" :title="$t('Tick to clear every listed item from stock')">
                        <input type="checkbox" class="sa-clear-cb" :checked="allCleared" :disabled="!form.items.length" :aria-label="$t('Clear all listed items from stock')" @change="toggleAll($event.target.checked)" />
                        <span>{{ $t('Clear From Stock') }}</span>
                      </label>
                    </th>
                    <th class="num">{{ $t('Variance') }}</th>
                    <th class="sa-act-th"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, ri) in form.items" :key="row.item_id" class="sa-row">
                    <td class="sa-no-td" :data-label="$t('No')">{{ ri + 1 }}</td>
                    <td :data-label="$t('Item')">
                      <span class="lg-main">{{ row.name }}</span> <span class="sa-code">({{ row.code }})</span>
                      <span
                        v-if="row.auto_cleared"
                        class="lg-chip lg-chip-red sa-cleared-tag"
                        tabindex="0"
                        @mouseenter="showTooltip($event, $t('Auto-added at zero via Clear, not physically counted'))"
                        @mouseleave="hideTooltip"
                        @focus="showTooltip($event, $t('Auto-added at zero via Clear, not physically counted'))"
                        @blur="hideTooltip"
                      >{{ $t('Cleared') }}</span>
                    </td>
                    <td :data-label="$t('Unit')" class="sa-muted">{{ row.unit_name }}</td>
                    <td class="num" :data-label="$t('System Stock')">
                      <span v-if="row.loadingStock" class="inline-flex items-center gap-1.5 sa-muted">
                        <icons name="cog" class="w-4 h-4 animate-spin inline-flex" />
                        <span class="text-xs">{{ $t('Loading') }}…</span>
                      </span>
                      <span v-else class="tabular-nums">{{ $number(row.system_quantity) }}</span>
                    </td>
                    <td class="num" :data-label="$t('Physical Count')">
                      <span class="sa-qtywrap">
                        <input type="number" step="0.0001" min="0" v-model="row.physical_quantity" class="sa-qty-input" :disabled="!!row.auto_cleared" />
                      </span>
                    </td>
                    <td class="sa-clear-td" :data-label="$t('Clear From Stock')">
                      <label class="sa-clear-lbl" :class="{ 'is-on': row.auto_cleared }">
                        <input type="checkbox" class="sa-clear-cb" :checked="!!row.auto_cleared" :aria-label="$t('Clear From Stock')" @change="toggleClear(row, $event.target.checked)" />
                        <span class="sa-clear-txt">{{ $t('Clear From Stock') }}</span>
                      </label>
                    </td>
                    <td class="num" :data-label="$t('Variance')">
                      <span class="lg-chip sa-variance" :class="variancePillClass(row)">
                        <icons :name="varianceIcon(row)" class="w-3 h-3 inline-flex" />
                        {{ varianceLabel(row) }}
                      </span>
                    </td>
                    <td class="lg-sticky-end sa-act-td" :data-label="$t('Actions')">
                      <div class="lg-actions sa-actions">
                        <!-- on the last item added: back to the search field, cursor ready to type (sits beside the delete icon; the delete icons stay in one centred line) -->
                        <button v-if="ri === form.items.length - 1" type="button" class="sa-jump sa-jump--act lg-tip" :data-tip="$t('Back to the item search')" :aria-label="$t('Back to the item search')" @click="goToSearch"><icons name="up" class="w-4 h-4" /></button>
                        <button type="button" @click="form.items.splice(ri, 1)" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')"><icons name="trash" /></button>
                      </div>
                    </td>
                  </tr>
                  <tr v-if="!form.items.length" class="lg-empty-row">
                    <td class="lg-empty" colspan="8">{{ $t('No items added yet.') }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-if="$page.props.errors.items" class="text-sm text-red-600 mt-2">{{ $page.props.errors.items }}</div>
          </template>
        </section>

        <!-- Attachments -->
        <section class="lg-card">
          <div class="lg-card-head">
            <span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <div class="lg-card-title">{{ $t('Attachments') }}</div>
              <div class="lg-card-sub">{{ $t('Optional supporting files such as count sheets or photos') }}</div>
            </div>
          </div>

          <ul class="sa-att-list" v-if="existingAttachments.length">
            <li v-for="a in existingAttachments" :key="a.id" class="sa-att-item">
              <span class="inline-flex items-center gap-2 min-w-0">
                <icons :name="a.is_image ? 'image' : 'doc'" class="w-4 h-4 inline-flex sa-muted flex-none" />
                <span class="truncate text-sm">{{ a.title }}</span>
              </span>
              <span class="flex items-center gap-1.5 flex-none">
                <a :href="a.preview_url" target="_blank" rel="noopener" class="lg-icon-btn lg-tip" :data-tip="$t('Preview')" :aria-label="$t('Preview')"><icons name="view" /></a>
                <a :href="a.download_url" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" /></a>
                <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" @click="deleteExistingAttachment(a)" :aria-label="$t('Remove')">
                  <icons name="trash" />
                </button>
              </span>
            </li>
          </ul>

          <input id="sa-attachments" type="file" multiple class="sr-only" @change="onAttachmentsChange" accept=".png,.jpg,.jpeg,.pdf,.doc,.docx,.xls,.xlsx,.zip" />
          <label for="sa-attachments" class="sa-file-pick">
            <span class="inline-flex items-center gap-2 text-sm font-semibold">
              <icons name="clip" class="w-4 h-4 inline-flex sa-pick-ico" />
              <span>{{ $t('Select files') }}</span>
            </span>
            <span class="lg-chip lg-chip-blue">
              {{ form.attachments.length ? form.attachments.length + ' ' + $t('file(s)') : $t('No files chosen') }}
            </span>
          </label>

          <ul class="sa-att-list sa-att-new" v-if="form.attachments.length">
            <li v-for="(file, fi) in form.attachments" :key="fi" class="sa-att-item sa-file-row">
              <span class="inline-flex items-center gap-2 min-w-0">
                <icons name="doc" class="w-3.5 h-3.5 inline-flex sa-muted flex-none" />
                <span class="truncate text-xs">{{ fileLabel(file) }}</span>
              </span>
              <span class="flex items-center gap-1.5 flex-none">
                <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Preview')" @click="previewNewAttachment(file)" :aria-label="$t('Preview')"><icons name="view" /></button>
                <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" @click="removeAttachment(fi)" :aria-label="$t('Remove')"><icons name="cross" /></button>
              </span>
            </li>
          </ul>
        </section>

        <!-- Sticky action bar -->
        <div class="lg-actionbar print:hidden">
          <div class="lg-actionbar-note">
            {{ form.items.length }} {{ $t('Items') }}
            <span v-if="!edit && $super" class="sa-auto-note">&middot; {{ requiredApprovals === 2 ? $t('Two different Super Admins must approve this count before the stock changes.') : $t('As Super Admin your count is approved on submit and stock is updated.') }}</span>
          </div>
          <div class="lg-actionbar-btns">
            <Link :href="route('super-adjustments.index')" class="lg-btn lg-btn-soft">
              <icons name="cross" class="w-4 h-4 inline-flex" />
              {{ $t('Cancel') }}
            </Link>
            <loading-button type="submit" :loading="form.processing || checkingUncounted" :disabled="form.processing || checkingUncounted || !form.items.length" class="lg-btn lg-btn-green sa-submit">
              <icons v-if="!form.processing && !checkingUncounted" name="tick" class="w-4 h-4 inline-flex mr-1.5" />
              {{ $t(edit ? 'Save' : $super && requiredApprovals !== 2 ? 'Submit & Approve' : $super ? 'Submit for Approval' : 'Submit for Review') }}
            </loading-button>
          </div>
        </div>
      </form>

      <!-- Import by Category: pick the category (its sub-categories come with it), see what will be added, import -->
      <div v-if="catOpen" class="sa-modal-overlay" @click.self="catOpen = false" @keydown.esc="catOpen = false">
        <div class="sa-cat" role="dialog" aria-modal="true" :aria-label="$t('Import by Category')">
          <header class="sa-cat__head">
            <span class="sa-cat__ico"><icons name="collection" class="w-6 h-6" /></span>
            <div class="sa-cat__title">
              <h3>{{ $t('Import by Category') }}</h3>
              <p>{{ $t('Tick one or more categories: every item of them that is in this Project / Warehouse is added to the count.') }}</p>
            </div>
            <span class="sa-cat__wh"><icons name="building" class="w-4 h-4" />{{ warehouseName }}</span>
            <button type="button" class="sa-cat__x" :aria-label="$t('Close')" @click="catOpen = false"><icons name="cross" class="w-4 h-4" /></button>
          </header>

          <div class="sa-cat__body">
            <div class="sa-cat__tools">
              <label class="sa-cat__search"><icons name="search" class="w-4 h-4" /><input v-model="catSearch" type="search" :placeholder="$t('Search category…')" :disabled="catLoading" /></label>
              <span v-if="!catLoading && catList.length" class="sa-cat__count">{{ catShown.length }} {{ $t('of') }} {{ catList.length }} {{ $t('categories') }}</span>
            </div>
            <div v-if="!catLoading && catList.length" class="sa-cat__bulk">
              <button type="button" class="lg-btn lg-btn-soft" :disabled="!catShown.length || allShownPicked" @click="pickAllCategories"><icons name="tick" class="w-4 h-4" />{{ $t('Select All') }}</button>
              <button type="button" class="lg-btn lg-btn-soft" :disabled="!catPicked.length" @click="clearCategories"><icons name="cross" class="w-4 h-4" />{{ $t('Deselect All') }}</button>
              <span v-if="catPicked.length" class="sa-cat__picked">{{ catPicked.length }} {{ $t('selected') }}</span>
            </div>

            <div v-if="catLoading" class="sa-cat__skeleton" role="status"><div v-for="n in 5" :key="n"><i></i><i></i></div></div>
            <p v-else-if="catError" class="sa-cat__err"><icons name="exclamation" class="w-4 h-4" />{{ catError }}</p>
            <p v-else-if="!catList.length" class="sa-cat__empty"><icons name="info" class="w-5 h-5" />{{ $t('No item of this Project / Warehouse has a category.') }}</p>

            <ul v-else class="sa-cat__list" role="listbox">
              <li v-for="c in catShown" :key="c.id" role="option" :aria-selected="catPicked.includes(c.id)" :class="{ 'is-on': catPicked.includes(c.id), 'is-sub': c.depth > 0 }" :style="{ '--d': Math.min(c.depth, 3) }" @click="pickCategory(c)">
                <span class="sa-cat__badge" :class="c.has_sub ? 'is-parent' : 'is-leaf'"><icons :name="catPicked.includes(c.id) ? 'tick' : (c.has_sub ? 'folder' : 'collection')" class="w-4 h-4" /></span>
                <span class="sa-cat__name">
                  <b>{{ c.name }}</b>
                  <small v-if="c.depth > 0">{{ c.label }}</small>
                  <small v-else-if="c.has_sub">{{ $t('includes its sub-categories') }}</small>
                </span>
                <span class="sa-cat__pills">
                  <em v-if="c.positive" class="p-green" :title="$t('with stock')">{{ c.positive }} {{ $t('with stock') }}</em>
                  <em v-if="c.zero" class="p-slate" :title="$t('zero stock')">{{ c.zero }} {{ $t('zero') }}</em>
                  <em v-if="c.negative" class="p-red" :title="$t('negative stock')">{{ c.negative }} {{ $t('negative') }}</em>
                </span>
                <span class="sa-cat__total"><b>{{ c.items }}</b><small>{{ $t('items') }}</small></span>
              </li>
              <li v-if="!catShown.length" class="sa-cat__none">{{ $t('No category matches.') }}</li>
            </ul>
          </div>

          <!-- what will happen with the chosen category -->
          <section class="sa-cat__sum" :class="{ 'is-empty': !catPicked.length }" aria-live="polite">
            <template v-if="!catPicked.length"><icons name="info" class="w-5 h-5" /><span>{{ $t('Choose one or more categories to see what will be added.') }}</span></template>
            <template v-else-if="catPreview.loading"><icons name="cog" class="w-5 h-5 animate-spin" /><span>{{ $t('Checking the items of the chosen categories…') }}</span></template>
            <template v-else-if="catPreview.error"><icons name="exclamation" class="w-5 h-5" /><span>{{ catPreview.error }}</span></template>
            <template v-else>
              <span class="sa-cat__sumico"><icons name="download" class="w-5 h-5" /></span>
              <div class="sa-cat__sumtxt">
                <b>{{ $t(':n item(s) will be added', { n: catPreview.add }) }}</b>
                <small>
                  <span v-if="catPreview.zero" class="p-slate">{{ catPreview.zero }} {{ $t('zero stock') }}</span>
                  <span v-if="catPreview.negative" class="p-red">{{ catPreview.negative }} {{ $t('negative stock (counted from 0)') }}</span>
                  <span v-if="catPreview.already" class="p-amber">{{ catPreview.already }} {{ $t('already in the list, skipped') }}</span>
                </small>
                <div v-if="catPreview.names.length" class="sa-cat__names"><span v-for="(n, i) in catPreview.names" :key="i">{{ n }}</span><span v-if="catPreview.add > catPreview.names.length" class="is-more">+{{ catPreview.add - catPreview.names.length }}</span></div>
              </div>
            </template>
          </section>

          <footer class="sa-cat__foot">
            <button type="button" class="lg-btn lg-btn-soft" @click="catOpen = false">{{ $t('Cancel') }}</button>
            <button type="button" class="lg-btn lg-btn-primary sa-cat__go" :disabled="!catPicked.length || catLoading || catPreview.loading || !!catPreview.error || !catPreview.data.length" @click="importCategory">
              <icons name="download" class="w-4 h-4" />{{ catPicked.length && !catPreview.loading && catPreview.data.length ? $t('Import :n item(s)', { n: catPreview.add }) : $t('Import') }}
            </button>
          </footer>
        </div>
      </div>

      <!-- Uncounted-items check: items still stocked in this warehouse that
           weren't added to the physical count. Surfaced only when the check
           (fired right before submit) finds any — see checkUncountedThenSubmit(). -->
      <div v-if="uncountedModal.open" class="sa-modal-overlay" @click.self="uncountedModal.open = false">
        <div class="sa-modal-card sa-um-card">
          <div class="flex items-start gap-3 mb-4">
            <span class="lg-card-ico lg-ico-amber"><icons name="exclamation" class="w-5 h-5 inline-flex" /></span>
            <div>
              <h3 class="sa-modal-title">{{ $t('Some stocked items were not counted') }}</h3>
              <p class="sa-modal-sub">
                {{ uncountedModal.items.length }} {{ $t('item(s) currently have stock in this Project / Warehouse but were not added to this physical count. Choose how to handle them.') }}
              </p>
            </div>
          </div>

          <!-- find, filter, sort, print and download the list (Preserve and Clear below always apply to the whole list) -->
          <div class="sa-um-bar">
            <div class="sa-um-search"><icons name="search" class="w-4 h-4" /><input v-model="uncountedModal.search" type="search" :placeholder="$t('Search by item, code or category')" :aria-label="$t('Search the list')" autocomplete="off" /></div>
            <acct-select v-model="uncountedModal.category" class="sa-um-cat" :options="uncountedCategories" :placeholder="$t('All categories')" clearable />
            <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Print')" :aria-label="$t('Print')" :disabled="!uncountedShown.length" @click="printUncounted"><icons name="printer" class="w-4 h-4" /></button>
            <button type="button" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')" :disabled="!uncountedShown.length" @click="downloadUncounted"><icons name="download" class="w-4 h-4" /></button>
          </div>
          <p v-if="uncountedShown.length !== uncountedModal.items.length" class="sa-um-note">{{ $t('Showing :n of :t. Preserve and Clear apply to all :t items.', { n: uncountedShown.length, t: uncountedModal.items.length }) }}</p>
          <div class="sa-modal-list sa-um-list">
            <table class="sa-um-table">
              <thead><tr>
                <th><button type="button" @click="sortUncounted('name')">{{ $t('Item') }}<i>{{ umArrow('name') }}</i></button></th>
                <th><button type="button" @click="sortUncounted('category')">{{ $t('Category') }}<i>{{ umArrow('category') }}</i></button></th>
                <th class="num"><button type="button" @click="sortUncounted('system_quantity')">{{ $t('Stock') }}<i>{{ umArrow('system_quantity') }}</i></button></th>
              </tr></thead>
              <tbody>
                <tr v-for="u in uncountedShown" :key="u.item_id">
                  <td>{{ u.name }} <span class="sa-code">({{ u.code }})</span></td>
                  <td class="sa-muted">{{ u.category || '-' }}</td>
                  <td class="num tabular-nums sa-muted">{{ $number(u.system_quantity) }} {{ u.unit_name }}</td>
                </tr>
                <tr v-if="!uncountedShown.length"><td colspan="3" class="sa-um-empty">{{ $t('No item matches.') }}</td></tr>
              </tbody>
            </table>
          </div>

          <div class="flex flex-col sm:flex-row justify-end gap-2 mt-5">
            <button type="button" class="lg-btn lg-btn-soft" @click="uncountedModal.open = false">
              <icons name="out" class="w-4 h-4 inline-flex" />
              {{ $t('Exit') }}
            </button>
            <button type="button" class="lg-btn lg-btn-dark" @click="resolveUncounted(false)">
              <icons name="lock-closed" class="w-4 h-4 inline-flex" />
              {{ $t('Preserve') }}
            </button>
            <button type="button" class="lg-btn lg-btn-red" @click="resolveUncounted(true)">
              <icons name="trash" class="w-4 h-4 inline-flex" />
              {{ $t('Clear') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Single shared tooltip, teleported to <body> and positioned via
         getBoundingClientRect (same mechanism as Show.vue) so it escapes the
         items table's own overflow-x-auto clipping and stays within the
         viewport regardless of horizontal scroll position. -->
    <Teleport to="body">
      <div
        v-if="tooltip.visible"
        class="sa-teleport-tooltip"
        :class="'sa-tooltip-' + tooltip.placement"
        :style="{ top: tooltip.top + 'px', left: tooltip.left + 'px', width: tooltip.width + 'px' }"
      >
        {{ tooltip.text }}
        <span class="sa-tooltip-arrow" :style="{ left: tooltip.arrowLeft + 'px' }"></span>
      </div>
    </Teleport>
  </admin-layout>
</template>

<script>
import { onRowAdded } from '@/Core/revealLast';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TecLabel from '@/Jetstream/Label.vue';
import TextInput from '@/Shared/TextInput.vue';
import InputError from '@/Jetstream/InputError.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import CameraBarcodeScanner from '@/Shared/CameraBarcodeScanner.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

export default {
  components: {
    CameraBarcodeScanner,
    AcctSelect, Link, AdminLayout, TecLabel, TextInput, InputError, AutoComplete, LoadingButton, PageHeader, StatTiles },

  props: {
    edit: Object,
    warehouses: Array,
    today: String,
    responsibleUser: Object,
    existingItems: { type: Array, default: () => [] },
    existingAttachments: { type: Array, default: () => [] },
    openSessions: { type: Object, default: () => ({}) },
    requiredApprovals: { type: Number, default: 1 },
    preselectWarehouseId: { type: Number, default: null },
  },

  data() {
    return {
      reasonOptions: [
        this.$t('Periodic Stock Count'),
        this.$t('Cycle Count'),
        this.$t('Annual Inventory Audit'),
        this.$t('Damaged Goods Found'),
        this.$t('Theft / Loss Suspected'),
        this.$t('Data Entry Correction'),
        this.$t('Received Stock Not Recorded'),
        this.$t('Issued Stock Not Recorded'),
        this.$t('Warehouse Relocation Count'),
        this.$t('Spot Check'),
      ],
      form: this.$inertia.form({
        _method: this.edit ? 'PUT' : 'POST',
        warehouse_id: this.edit ? this.edit.warehouse_id : (this.preselectWarehouseId && (this.warehouses || []).some(w => Number(w.value ?? w.id) === this.preselectWarehouseId) ? this.preselectWarehouseId : null),
        reason: this.edit ? this.edit.reason : '',
        details: this.edit ? [this.edit.details, this.edit.remarks].filter(Boolean).join('\n\n') : '',
        items: this.existingItems.map(i => ({
          item_id: i.item_id,
          name: i.name,
          code: i.code,
          unit_id: i.unit_id,
          unit_name: i.unit_name,
          system_quantity: i.system_quantity,
          physical_quantity: i.physical_quantity,
          loadingStock: false,
          auto_cleared: !!i.auto_cleared,
        })),
        attachments: [],
      }),
      importing: false, // '' | 'all' | 'category': what is being imported now
      importLabel: '',
      catOpen: false,
      catLoading: false,
      catList: [],
      catSearch: '',
      catPicked: [],
      catPreview: { loading: false, error: '', data: [], add: 0, already: 0, zero: 0, negative: 0, names: [] },
      catError: '',
      importNote: '',
      importErr: false,
      checkingUncounted: false,
      uncountedModal: { open: false, items: [], search: '', category: '', sort: 'name', dir: 'asc' },
      tooltip: { visible: false, text: '', top: 0, left: 0, arrowLeft: 0, width: 260, placement: 'above' },
    };
  },

  mounted() {
    // Capture phase so this also fires for scrolling inside the items
    // table's own overflow-x-auto container, not just the window itself.
    window.addEventListener('scroll', this.hideTooltip, true);
    window.addEventListener('resize', this.hideTooltip);
  },

  beforeUnmount() {
    window.removeEventListener('scroll', this.hideTooltip, true);
    window.removeEventListener('resize', this.hideTooltip);
  },

  computed: {
    // the not-counted list as the person sees it: searched, filtered by category and sorted
    uncountedCategories() {
      const names = new Set();
      this.uncountedModal.items.forEach(u => (u.category || '').split(', ').filter(Boolean).forEach(c => names.add(c)));
      return [...names].sort((a, b) => a.localeCompare(b)).map(c => ({ value: c, label: c }));
    },
    uncountedShown() {
      const m = this.uncountedModal;
      const q = (m.search || '').trim().toLowerCase();
      let rows = m.items.filter(u => (!q || [u.name, u.code, u.category].some(x => (x || '').toLowerCase().includes(q))) && (!m.category || (u.category || '').split(', ').includes(m.category)));
      const dir = m.dir === 'asc' ? 1 : -1;
      rows = [...rows].sort((a, b) => (m.sort === 'system_quantity' ? a.system_quantity - b.system_quantity : String(a[m.sort] || '').localeCompare(String(b[m.sort] || ''), undefined, { numeric: true, sensitivity: 'base' })) * dir);
      return rows;
    },
    warehouseName() { const w = (this.warehouses || []).find(x => Number(x.value ?? x.id) === Number(this.form.warehouse_id)); return w ? (w.label || w.name) : ''; },
    // rows whose system stock is exactly 0 (items added from a warehouse or a category with no stock)
    zeroCount() { return this.form.items.filter(i => !i.loadingStock && Number(i.system_quantity) === 0).length; },
    allShownPicked() { return this.catShown.length > 0 && this.catShown.every(c => this.catPicked.includes(c.id)); },
    catShown() { const q = this.catSearch.trim().toLowerCase(); return q ? this.catList.filter(c => c.label.toLowerCase().includes(q)) : this.catList; },
    // the open Adjustment Session of the chosen Project / Warehouse, if there is one
    openSession() { return this.form.warehouse_id ? (this.openSessions || {})[this.form.warehouse_id] || null : null; },
    allCleared() {
      return this.form.items.length > 0 && this.form.items.every(r => r.auto_cleared);
    },
    statItems() {
      const rows = this.form.items.filter(r => !r.loadingStock);
      const counts = { add: 0, sub: 0, match: 0 };
      rows.forEach(r => {
        const v = this.variance(r);
        if (Math.abs(v) < 0.0001) counts.match++;
        else if (v > 0) counts.add++;
        else counts.sub++;
      });
      return [
        { icon: 'collection', tone: 'navy', label: this.$t('Items'), value: this.form.items.length },
        { icon: 'tick', tone: 'slate', label: this.$t('Match'), value: counts.match },
        { icon: 'up', tone: 'emerald', label: this.$t('Additions'), value: counts.add },
        { icon: 'down', tone: 'rose', label: this.$t('Subtractions'), value: counts.sub },
      ];
    },
  },

  watch: {
    'form.items.length': onRowAdded('.sa-row'),
    // The warehouse is locked only when editing. While creating, switching it must not leave the previous warehouse's items (or its import) in the list.
    'form.warehouse_id'() {
      if (this.edit) return;
      // The list belongs to one Project / Warehouse: a different one starts a new, empty list (never the old one's items), and what an import had loaded is dropped.
      const had = this.form.items.length;
      this.form.items = [];
      this.catOpen = false; this.catList = []; this.catPicked = []; this.resetPreview();
      this.importNote = had ? this.$t('The Project / Warehouse was changed, so the list was cleared. Use the import buttons to load the stock of the new one.') : '';
      this.importErr = false;
    },
  },

  methods: {
    // Positions the single shared, teleported tooltip against whichever
    // trigger fired it — clamped horizontally to the viewport and flipped
    // to appear below the trigger when there isn't room above, so it always
    // stays fully visible regardless of table scroll position or screen size.
    showTooltip(event, text) {
      const rect = event.currentTarget.getBoundingClientRect();
      const width = Math.min(260, Math.max(180, window.innerWidth - 24));
      const gap = 10;
      const placement = rect.top < 110 ? 'below' : 'above';
      let left = rect.left + rect.width / 2 - width / 2;
      left = Math.max(8, Math.min(left, window.innerWidth - width - 8));
      const triggerCenter = rect.left + rect.width / 2;
      const arrowLeft = Math.max(12, Math.min(width - 12, triggerCenter - left));

      this.tooltip = {
        visible: true,
        text,
        top: placement === 'above' ? rect.top - gap : rect.bottom + gap,
        left,
        arrowLeft,
        width,
        placement,
      };
    },
    hideTooltip() {
      this.tooltip.visible = false;
    },
    sortUncounted(key) { const m = this.uncountedModal; m.dir = m.sort === key && m.dir === 'asc' ? 'desc' : 'asc'; m.sort = key; },
    umArrow(key) { const m = this.uncountedModal; return m.sort === key ? (m.dir === 'asc' ? ' ▲' : ' ▼') : ''; },
    // Print and Download carry exactly the rows on the screen (the search, the category and the sort)
    uncountedTitle() { return this.$t('Items not counted') + ' - ' + (this.warehouseName || ''); },
    downloadUncounted() {
      const esc = v => { let t = String(v ?? ''); if (/^[=+\-@\t\r]/.test(t)) t = "'" + t; return '"' + t.replace(/"/g, '""') + '"'; };
      const head = [this.$t('No'), this.$t('Item'), this.$t('Code'), this.$t('Category'), this.$t('Stock'), this.$t('Unit')];
      const lines = [head.map(esc).join(',')].concat(this.uncountedShown.map((u, i) => [i + 1, u.name, u.code, u.category, u.system_quantity, u.unit_name].map(esc).join(',')));
      const blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'items-not-counted-' + new Date().toISOString().slice(0, 10) + '.csv';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    },
    printUncounted() {
      const h = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
      const user = (this.$page.props.auth && this.$page.props.auth.user && this.$page.props.auth.user.name) || '';
      const rows = this.uncountedShown.map((u, i) => '<tr><td>' + (i + 1) + '</td><td>' + h(u.name) + ' <span class="c">(' + h(u.code) + ')</span></td><td>' + h(u.category || '-') + '</td><td class="n">' + h(this.$number(u.system_quantity)) + ' ' + h(u.unit_name) + '</td></tr>').join('');
      const w = window.open('', '_blank');
      if (!w) return;
      w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' + h(this.uncountedTitle()) + '</title><style>body{font:13px Nunito,Arial,sans-serif;color:#0f2540;margin:24px}h1{font-size:18px;margin:0 0 4px}p{margin:0 0 14px;color:#64748b}table{border-collapse:collapse;width:100%}th{background:#dbeafe;color:#1e3a8a;text-align:left;padding:7px 9px}td{padding:6px 9px;border-bottom:1px solid #e3e9f2}.n{text-align:right}.c{color:#64748b}</style></head><body><h1>' + h(this.uncountedTitle()) + '</h1><p>' + h(this.$t('Stocked items that were not added to the physical count')) + ' - ' + this.uncountedShown.length + ' - ' + h(new Date().toLocaleString()) + (user ? ' - ' + h(user) : '') + '</p><table><thead><tr><th>#</th><th>' + h(this.$t('Item')) + '</th><th>' + h(this.$t('Category')) + '</th><th class="n">' + h(this.$t('Stock')) + '</th></tr></thead><tbody>' + rows + '</tbody></table></body></html>');
      w.document.close();
      w.focus();
      setTimeout(() => w.print(), 300);
    },
    // Arrow Down: the Physical Count of the last item in the list (the last one added), selected so the number can be typed straight away
    goToLastCount() {
      const inputs = Array.from(document.querySelectorAll('.sa-qty-input'));
      const target = inputs[inputs.length - 1] && !inputs[inputs.length - 1].disabled ? inputs[inputs.length - 1] : inputs.filter(i => !i.disabled).pop();
      if (!target) return;
      target.scrollIntoView({ behavior: 'smooth', block: 'center' });
      setTimeout(() => { target.focus({ preventScroll: true }); target.select(); }, 250);
    },
    // Arrow Up: back to the item search, with the cursor inside it
    goToSearch() {
      const box = document.getElementById('add-item');
      if (!box) return;
      box.scrollIntoView({ behavior: 'smooth', block: 'center' });
      setTimeout(() => { box.focus({ preventScroll: true }); box.select && box.select(); }, 250);
    },
    async itemSelected(v) {
      if (this.form.items.find(i => i.item_id === v.id)) {
        return;
      }
      const row = {
        item_id: v.id,
        name: v.name,
        code: v.code,
        unit_id: v.unit_id,
        unit_name: v.unit?.name || '',
        system_quantity: 0,
        physical_quantity: 0,
        loadingStock: true,
      };
      this.form.items.push(row);
      await this.refreshStock(row);
    },
    // "Import from Warehouse": REPLACES the whole count list with every stocked item of the chosen warehouse, each line
    // pre-filled with its system quantity (so nothing changes until the user edits a count or ticks Clear From Stock).
    // The list is emptied first, so an item can never appear twice; if there is already something in the list the user is
    // asked before it is thrown away. Stock that is negative in the system is counted at 0 (a count can never be negative).
    async importFromWarehouse() {
      if (!this.form.warehouse_id || this.importing) return;
      if (this.form.items.length) {
        const ok = await this.$confirm(this.$t('Replace the :n item(s) in the list below with the stock of this Project / Warehouse? Any counts you entered will be lost.', { n: this.form.items.length }), { variant: 'danger' });
        if (!ok) return;
      }
      const warehouseId = this.form.warehouse_id;
      this.importing = 'all';
      this.importNote = '';
      this.importErr = false;
      try {
        const [{ data }] = await Promise.all([axios.get(this.route('super-adjustments.warehouse-stock'), { params: { warehouse_id: warehouseId } }), new Promise(r => setTimeout(r, 600))]); // the animation is seen even when the answer is quick
        if (warehouseId !== this.form.warehouse_id) return; // the warehouse was changed while loading: ignore this answer
        const seen = new Set();
        const rows = [];
        let negative = 0;
        data.forEach(u => {
          if (seen.has(u.item_id)) return; // one line per item, whatever the server sent
          seen.add(u.item_id);
          if (u.system_quantity < 0) negative++;
          rows.push({
            item_id: u.item_id, name: u.name, code: u.code, unit_id: u.unit_id, unit_name: u.unit_name,
            system_quantity: u.system_quantity, physical_quantity: Math.max(0, u.system_quantity), loadingStock: false, auto_cleared: false,
          });
        });
        this.form.items = rows; // reset, then fill
        this.importNote = rows.length
          ? this.$t(':n item(s) imported from this Project / Warehouse. Change a count, or tick Clear From Stock for items to remove.', { n: rows.length }) + (negative ? ' ' + this.$t(':n item(s) have negative stock in the system and start at a count of 0.', { n: negative }) : '')
          : this.$t('This Project / Warehouse has no stock to import.');
      } catch (e) {
        this.importErr = true;
        this.importNote = (e.response && e.response.data && e.response.data.message) || this.$t('Could not import the stock. Please try again.');
      } finally {
        this.importing = false;
      }
    },
    // Import by Category: choose a category (and its sub-categories) of the chosen Project / Warehouse; every item of it that has a stock record there is ADDED to the list
    // (positive, negative and zero stock alike), the ones already in the list are skipped, so categories can be imported one after another.
    async openCategoryImport() {
      if (!this.form.warehouse_id || this.importing) return;
      this.catOpen = true; this.catLoading = true; this.catError = ''; this.catList = []; this.catSearch = ''; this.catPicked = []; this.resetPreview();
      const warehouseId = this.form.warehouse_id;
      try {
        const [{ data }] = await Promise.all([axios.get(this.route('super-adjustments.warehouse-categories'), { params: { warehouse_id: warehouseId } }), new Promise(r => setTimeout(r, 350))]);
        if (warehouseId === this.form.warehouse_id) this.catList = data;
      } catch (e) {
        this.catError = (e.response && e.response.data && e.response.data.message) || this.$t('Could not load the categories. Please try again.');
      } finally {
        this.catLoading = false;
      }
    },
    resetPreview() { this.catPreview = { loading: false, error: '', data: [], add: 0, already: 0, zero: 0, negative: 0, names: [] }; },
    // choosing a category loads its items at once, so the person sees exactly what will be added before importing
    async pickCategory(c) {
      const i = this.catPicked.indexOf(c.id);
      if (i >= 0) this.catPicked.splice(i, 1); else this.catPicked.push(c.id);
      this.loadPreview();
    },
    // Reset: the table goes back to empty (no items, nothing counted) so the count can start again, from a search or an import.
    async resetItems() {
      const n = this.form.items.length;
      if (!n) return;
      const ok = await this.$confirm(this.$t('Reset the table? All :n item(s) and the counts you entered will be removed.', { n }), { variant: 'danger', confirmText: this.$t('Reset') });
      if (!ok) return;
      this.form.items = [];
      this.importErr = false;
      this.importNote = '';
    },
    // Takes every item with a system stock of 0 out of the table (a count is usually about what is on the shelves); the items can be added again by search or import.
    async removeZeroItems() {
      const n = this.zeroCount;
      if (!n) return;
      const ok = await this.$confirm(this.$t('Remove the :n item(s) with zero stock from the list below? You can add them again by search or import.', { n }), { variant: 'danger', confirmText: this.$t('Remove') });
      if (!ok) return;
      this.form.items = this.form.items.filter(i => i.loadingStock || Number(i.system_quantity) !== 0);
      this.importErr = false;
      this.importNote = this.$t(':n item(s) with zero stock were removed from the list.', { n });
    },
    // Select All ticks every category in view (the search narrows it); Deselect All empties the choice
    pickAllCategories() {
      this.catShown.forEach(c => { if (!this.catPicked.includes(c.id)) this.catPicked.push(c.id); });
      this.loadPreview();
    },
    clearCategories() { this.catPicked = []; this.resetPreview(); },
    async loadPreview() {
      this.resetPreview();
      if (!this.catPicked.length) return;
      const picked = [...this.catPicked];
      const key = picked.join(',');
      this.catPreview.loading = true;
      const warehouseId = this.form.warehouse_id;
      try {
        const { data } = await axios.get(this.route('super-adjustments.category-stock'), { params: { warehouse_id: warehouseId, category_ids: picked } });
        if (this.catPicked.join(',') !== key || warehouseId !== this.form.warehouse_id) return; // the choice changed meanwhile
        const have = new Set(this.form.items.map(i => i.item_id));
        const fresh = data.filter(u => !have.has(u.item_id));
        this.catPreview = {
          loading: false, error: '', data, add: fresh.length, already: data.length - fresh.length,
          zero: fresh.filter(u => u.system_quantity === 0).length, negative: fresh.filter(u => u.system_quantity < 0).length, names: fresh.slice(0, 6).map(u => u.name),
        };
      } catch (e) {
        if (this.catPicked.join(',') === key) this.catPreview = { ...this.catPreview, loading: false, error: (e.response && e.response.data && e.response.data.message) || this.$t('Could not load these categories. Please try again.') };
      }
    },
    async importCategory() {
      if (!this.catPicked.length || !this.form.warehouse_id || this.importing || this.catPreview.loading || this.catPreview.error) return;
      const label = this.catList.filter(c => this.catPicked.includes(c.id)).map(c => c.name).join(', ');
      const warehouseId = this.form.warehouse_id;
      const data = this.catPreview.data;
      this.catOpen = false;
      this.importing = 'category'; this.importLabel = label; this.importNote = ''; this.importErr = false;
      try {
        await new Promise(r => setTimeout(r, 700)); // the animation is seen even though the items are already loaded
        if (warehouseId !== this.form.warehouse_id) return;
        const have = new Set(this.form.items.map(i => i.item_id));
        let added = 0, already = 0, zero = 0, negative = 0;
        data.forEach(u => {
          if (have.has(u.item_id)) { already++; return; }
          have.add(u.item_id); added++;
          if (u.system_quantity === 0) zero++;
          if (u.system_quantity < 0) negative++;
          this.form.items.push({ item_id: u.item_id, name: u.name, code: u.code, unit_id: u.unit_id, unit_name: u.unit_name, system_quantity: u.system_quantity, physical_quantity: Math.max(0, u.system_quantity), loadingStock: false, auto_cleared: false });
        });
        const parts = [];
        if (zero) parts.push(this.$t(':n had zero stock', { n: zero }));
        if (negative) parts.push(this.$t(':n had negative stock (counted from 0)', { n: negative }));
        if (already) parts.push(this.$t(':n were already in the list', { n: already }));
        this.importNote = data.length
          ? this.$t(':n item(s) added from :c.', { n: added, c: this.importLabel }) + (parts.length ? ' (' + parts.join(', ') + ')' : '') + ' ' + this.$t('Change a count, or tick Clear From Stock for items to remove.')
          : this.$t('There are no items of :c in this Project / Warehouse.', { c: this.importLabel });
      } finally {
        this.importing = false;
      }
    },
    // Clear From Stock: the item is counted at zero and flagged as cleared (the same flag the "uncounted items → Clear"
    // choice uses), so approval subtracts the whole system quantity. Unticking puts the previous count back.
    toggleClear(row, on) {
      if (on) {
        row._prevCount = row.physical_quantity;
        row.physical_quantity = 0;
        row.auto_cleared = true;
      } else {
        row.auto_cleared = false;
        row.physical_quantity = row._prevCount !== undefined && row._prevCount !== null ? row._prevCount : row.system_quantity;
      }
    },
    toggleAll(on) {
      this.form.items.forEach(r => { if (!!r.auto_cleared !== on) this.toggleClear(r, on); });
    },
    async refreshStock(row) {
      row.loadingStock = true;
      try {
        const { data } = await axios.get(this.route('super-adjustments.item-stock'), {
          params: { item_id: row.item_id, warehouse_id: this.form.warehouse_id },
        });
        row.system_quantity = data.system_quantity;
      } finally {
        row.loadingStock = false;
      }
    },
    variance(row) {
      return Number((parseFloat(row.physical_quantity || 0) - parseFloat(row.system_quantity || 0)).toFixed(4));
    },
    varianceLabel(row) {
      const v = this.variance(row);
      if (Math.abs(v) < 0.0001) return this.$t('Match');
      return (v > 0 ? '+' : '') + this.$number(v) + ' ' + this.$t(v > 0 ? 'Addition' : 'Subtraction');
    },
    variancePillClass(row) {
      const v = this.variance(row);
      if (Math.abs(v) < 0.0001) return 'lg-chip-slate';
      return v > 0 ? 'lg-chip-green' : 'lg-chip-red';
    },
    varianceIcon(row) {
      const v = this.variance(row);
      if (Math.abs(v) < 0.0001) return 'tick';
      return v > 0 ? 'up' : 'down';
    },
    onAttachmentsChange(e) {
      const newFiles = Array.from(e.target.files || []);
      const existingKeys = new Set(this.form.attachments.map(f => `${f.name}:${f.size}:${f.lastModified}`));
      newFiles.forEach(file => {
        const key = `${file.name}:${file.size}:${file.lastModified}`;
        if (!existingKeys.has(key)) {
          this.form.attachments.push(file);
          existingKeys.add(key);
        }
      });
      e.target.value = '';
    },
    removeAttachment(index) {
      this.form.attachments.splice(index, 1);
    },
    fileLabel(file) {
      if (!file || typeof file !== 'object') return '';
      return file.name || 'Attachment';
    },
    previewNewAttachment(file) {
      window.open(URL.createObjectURL(file), '_blank', 'noopener');
    },
    async deleteExistingAttachment(attachment) {
      if (!(await this.$confirm(this.$t('Remove this attachment?'), { variant: 'danger' }))) return;
      this.$inertia.delete(attachment.delete_url, { preserveScroll: true });
    },
    // Fires on form submit — checks for items still stocked in this
    // warehouse that weren't added to the count, and asks the user how to
    // handle them, before actually posting (see uncountedModal / resolveUncounted).
    async submit() {
      if (!this.form.warehouse_id) {
        this.doSubmit();
        return;
      }
      this.checkingUncounted = true;
      try {
        const { data } = await axios.post(this.route('super-adjustments.uncounted-items'), {
          warehouse_id: this.form.warehouse_id,
          item_ids: this.form.items.map(i => i.item_id),
        });
        if (data.length) {
          this.uncountedModal = { open: true, items: data, search: '', category: '', sort: 'name', dir: 'asc' };
        } else {
          this.doSubmit();
        }
      } finally {
        this.checkingUncounted = false;
      }
    },
    resolveUncounted(clearFromStock) {
      if (clearFromStock) {
        this.uncountedModal.items.forEach(u => {
          this.form.items.push({
            item_id: u.item_id,
            name: u.name,
            code: u.code,
            unit_id: u.unit_id,
            unit_name: u.unit_name,
            system_quantity: u.system_quantity,
            physical_quantity: 0,
            loadingStock: false,
            auto_cleared: true,
          });
        });
      }
      this.uncountedModal.open = false;
      this.doSubmit();
    },
    async doSubmit() {
      if (this.$super && !this.edit) {
        // With two approvals nothing is approved on submit: the count waits for two different Super Admins. With one, the Super Admin's own count is approved at once and changes real stock.
        const two = this.requiredApprovals === 2;
        const ok = await this.$confirm(
          two
            ? this.$t('Submit this Super Adjustment for approval? It needs two different Super Admins, each with their password. The stock does not change until the second approval.')
            : this.$t('Submit and approve this Super Adjustment now? Stock will be updated straight away.'),
          { confirmText: this.$t(two ? 'Submit' : 'Submit & Approve') },
        );
        if (!ok) return;
      }
      this.form
        .transform(data => ({
          ...data,
          items: data.items.map(i => ({ item_id: i.item_id, unit_id: i.unit_id, physical_quantity: i.physical_quantity, auto_cleared: !!i.auto_cleared })),
        }))
        .post(this.edit ? this.route('super-adjustments.update', this.edit.id) : this.route('super-adjustments.store'), {
          forceFormData: true,
          preserveScroll: true,
        });
    },
  },
};
</script>

<style scoped>
.sa-auto-note { color: var(--lg-faint); font-weight: 600; }
.sa-muted { color: var(--lg-mute); }
.sa-import--zero { color: #b91c1c; }
.sa-cat__bulk { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin: 10px 0 2px; }
.sa-cat__picked { margin-left: auto; font-size: 0.8rem; font-weight: 800; color: #0369a1; }
.sa-code { color: var(--lg-faint); font-weight: 500; }
.sa-no-th, .sa-no-td { width: 3rem; text-align: center; color: var(--lg-faint); font-weight: 700; font-variant-numeric: tabular-nums; }

/* Field styling (labels + rounded inputs), dark-mode safe */
.sa-fields :deep(label) {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
}
.sa-fields :deep(input:not([type='checkbox']):not([type='radio'])),
.sa-fields :deep(select),
.sa-textarea {
  border-radius: 0.85rem !important;
  border: 1px solid var(--lg-line);
  border-color: rgba(148, 163, 184, 0.4) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  padding-top: 0.6rem !important;
  padding-bottom: 0.6rem !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.sa-textarea { padding: 0.6rem 0.75rem !important; font-size: 0.875rem; }
.sa-fields :deep(input:disabled) { background: var(--lg-soft) !important; color: var(--lg-mute); }
.sa-fields :deep(input:not([type='checkbox']):not([type='radio']):focus),
.sa-fields :deep(select:focus),
.sa-textarea:focus {
  outline: none;
  border-color: #60a5fa !important;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18) !important;
}

/* Scan / search zone */
.sa-scan-zone {
  padding: 0.8rem;
  margin-bottom: 1rem;
  border-radius: 16px;
  border: 1px dashed color-mix(in srgb, var(--lg-navy) 30%, transparent);
  background: var(--lg-soft);
}
.sa-scan-top { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 12px; margin-bottom: 0.6rem; }
.sa-scan-top .sa-scan-hint { margin-bottom: 0; }
/* import buttons and the animation while the stock is being imported */
.sa-import-btns { display: flex; flex-wrap: wrap; gap: 8px; }
@media (max-width: 560px) { .sa-import-btns { width: 100%; } .sa-import-btns .sa-import { flex: 1 1 100%; justify-content: center; } }
.sa-wrap { position: relative; }
.sa-wrap.is-importing > table { opacity: 0.25; filter: blur(1px); transition: opacity 0.2s ease; pointer-events: none; }
.sa-loading { position: absolute; inset: 0; z-index: 5; display: grid; align-content: start; gap: 12px; padding: 14px 16px; background: rgba(255, 255, 255, 0.55); backdrop-filter: blur(2px); border-radius: inherit; }
html.dark .sa-loading { background: rgba(15, 23, 42, 0.55); }
.sa-loading__bar { height: 5px; border-radius: 999px; background: rgba(148, 163, 184, 0.3); overflow: hidden; }
.sa-loading__bar span { display: block; width: 38%; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #22c55e, #0ea5e9); animation: sa-slide 1.1s ease-in-out infinite; }
@keyframes sa-slide { 0% { transform: translateX(-110%); } 100% { transform: translateX(290%); } }
.sa-loading__msg { display: flex; align-items: center; gap: 10px; font-size: 0.92rem; color: var(--lg-ink, #0f2540); }
.sa-loading__row { display: grid; grid-template-columns: 3fr 1fr 1fr; gap: 12px; }
.sa-loading__row i { display: block; height: 16px; border-radius: 8px; background: linear-gradient(90deg, rgba(148, 163, 184, 0.18) 25%, rgba(148, 163, 184, 0.38) 50%, rgba(148, 163, 184, 0.18) 75%); background-size: 200% 100%; animation: sa-shimmer 1.2s linear infinite; }
@keyframes sa-shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
@media (prefers-reduced-motion: reduce) { .sa-loading__bar span, .sa-loading__row i { animation: none; } }
/* Import by Category popup */
.sa-cat { width: min(680px, 96vw); max-height: 92vh; display: flex; flex-direction: column; overflow: hidden; border-radius: 22px; --lg-card: #ffffff; --lg-line: #e3e9f2; --lg-soft: #f4f7fb; --lg-ink: #0f172a; --lg-mute: #64748b; color: var(--lg-ink); background: var(--lg-card); box-shadow: 0 40px 80px -20px rgba(15, 23, 42, 0.55); animation: sa-cat-in 0.22s ease-out; }
html.dark .sa-cat { --lg-card: #1e293b; --lg-line: rgba(255, 255, 255, 0.1); --lg-soft: rgba(255, 255, 255, 0.05); --lg-ink: #f1f5f9; --lg-mute: #94a3b8; }
@keyframes sa-cat-in { from { opacity: 0; transform: translateY(14px) scale(0.98); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) { .sa-cat { animation: none; } }
.sa-cat__head { display: flex; align-items: center; gap: 0.9rem; padding: 1rem 1.2rem; color: #fff; background: radial-gradient(circle at 12% 0%, rgba(255, 255, 255, 0.22), transparent 55%), linear-gradient(135deg, #0ea5e9, #4f46e5); }
.sa-cat__ico { display: grid; place-items: center; width: 46px; height: 46px; flex: none; border-radius: 15px; background: rgba(255, 255, 255, 0.2); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.3); }
.sa-cat__title { min-width: 0; flex: 1; } .sa-cat__title h3 { margin: 0; font-size: 1.1rem; font-weight: 800; } .sa-cat__title p { margin: 0.15rem 0 0; font-size: 0.8rem; opacity: 0.95; line-height: 1.35; }
.sa-cat__wh { display: inline-flex; align-items: center; gap: 6px; max-width: 200px; padding: 5px 11px; border-radius: 999px; font-size: 0.74rem; font-weight: 800; background: rgba(255, 255, 255, 0.2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sa-cat__x { display: flex; align-items: center; justify-content: center; box-sizing: border-box; width: 32px; height: 32px; min-width: 0 !important; min-height: 0 !important; padding: 0 !important; flex: none; border: 0; border-radius: 50%; color: #fff; background: rgba(255, 255, 255, 0.2); cursor: pointer; } .sa-cat__x:hover { background: rgba(255, 255, 255, 0.32); }
.sa-cat__x > div { flex: none; width: 16px; height: 16px; margin: 0; }
.sa-cat__body { display: flex; flex-direction: column; gap: 0.7rem; padding: 0.9rem 1.2rem 0.4rem; min-height: 0; overflow-y: auto; }
.sa-cat__tools { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.sa-cat__search { flex: 1 1 220px; display: flex; align-items: center; gap: 8px; padding: 0 12px; border-radius: 14px; border: 1.5px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-mute); } .sa-cat__search:focus-within { border-color: #0ea5e9; box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.15); }
.sa-cat__search input { flex: 1; min-width: 0; padding: 10px 0; border: 0; outline: 0; background: transparent; color: var(--lg-ink); font-size: 0.9rem; }
.sa-cat__count { font-size: 0.76rem; font-weight: 700; color: var(--lg-mute); }
.sa-cat__skeleton { display: grid; gap: 8px; } .sa-cat__skeleton div { display: grid; grid-template-columns: 3fr 1fr; gap: 12px; padding: 12px; border-radius: 14px; background: var(--lg-soft); }
.sa-cat__skeleton i { display: block; height: 14px; border-radius: 7px; background: linear-gradient(90deg, rgba(148, 163, 184, 0.18) 25%, rgba(148, 163, 184, 0.4) 50%, rgba(148, 163, 184, 0.18) 75%); background-size: 200% 100%; animation: sa-shimmer 1.2s linear infinite; }
.sa-cat__err, .sa-cat__empty { display: flex; align-items: center; gap: 8px; margin: 0; padding: 12px 14px; border-radius: 14px; font-size: 0.86rem; font-weight: 600; } .sa-cat__err { color: #b91c1c; background: rgba(244, 63, 94, 0.1); } .sa-cat__empty { color: var(--lg-mute); background: var(--lg-soft); }
.sa-cat__list { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; max-height: 330px; overflow-y: auto; padding-right: 3px; }
.sa-cat__list li:not(.sa-cat__none) { --d: 0; display: grid; grid-template-columns: auto minmax(0, 1fr) auto auto; align-items: center; gap: 12px; padding: 9px 12px 9px calc(12px + var(--d) * 18px); border-radius: 16px; border: 1.5px solid var(--lg-line); background: var(--lg-card); cursor: pointer; transition: border-color 0.15s, box-shadow 0.15s, transform 0.15s; }
.sa-cat__list li:not(.sa-cat__none):hover { border-color: #7dd3fc; transform: translateY(-1px); box-shadow: 0 10px 18px -14px rgba(14, 165, 233, 0.7); }
.sa-cat__list li.is-on { border-color: #0ea5e9; background: linear-gradient(135deg, rgba(14, 165, 233, 0.1), rgba(79, 70, 229, 0.07)); box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.16); }
.sa-cat__list li.is-sub { border-style: dashed; }
.sa-cat__none { padding: 14px; text-align: center; color: var(--lg-mute); font-size: 0.86rem; }
.sa-cat__badge { display: grid; place-items: center; width: 34px; height: 34px; border-radius: 11px; color: #fff; } .sa-cat__badge.is-parent { background: linear-gradient(145deg, #f59e0b, #b45309); } .sa-cat__badge.is-leaf { background: linear-gradient(145deg, #14b8a6, #0f766e); } .is-on .sa-cat__badge { background: linear-gradient(145deg, #0ea5e9, #4f46e5); }
.sa-cat__name { display: grid; min-width: 0; line-height: 1.25; overflow-wrap: anywhere; } .sa-cat__name b { font-size: 0.92rem; } .sa-cat__name small { color: var(--lg-mute); font-size: 0.72rem; }
.sa-cat__pills { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 4px; } .sa-cat__pills em, .sa-cat__sumtxt small span { font-style: normal; padding: 2px 8px; border-radius: 999px; font-size: 0.68rem; font-weight: 800; white-space: nowrap; }
.p-green { color: #166534; background: rgba(34, 197, 94, 0.16); } .p-slate { color: #475569; background: rgba(100, 116, 139, 0.16); } .p-red { color: #9f1239; background: rgba(244, 63, 94, 0.14); } .p-amber { color: #92400e; background: rgba(245, 158, 11, 0.2); }
html.dark .p-green { color: #86efac; } html.dark .p-slate { color: #cbd5e1; } html.dark .p-red { color: #fda4af; } html.dark .p-amber { color: #fcd34d; }
.sa-cat__total { display: grid; justify-items: center; min-width: 46px; padding: 3px 8px; border-radius: 12px; background: var(--lg-soft); } .sa-cat__total b { font-size: 1.05rem; line-height: 1.1; font-variant-numeric: tabular-nums; } .sa-cat__total small { font-size: 0.6rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute); }
.sa-cat__sum { display: flex; align-items: flex-start; gap: 12px; margin: 0.6rem 1.2rem 0; padding: 12px 14px; border-radius: 16px; background: linear-gradient(135deg, rgba(14, 165, 233, 0.1), rgba(34, 197, 94, 0.1)); border: 1.5px solid rgba(14, 165, 233, 0.3); font-size: 0.86rem; }
.sa-cat__sum.is-empty { background: var(--lg-soft); border: 1.5px dashed var(--lg-line); color: var(--lg-mute); align-items: center; }
.sa-cat__sumico { display: grid; place-items: center; width: 38px; height: 38px; flex: none; border-radius: 12px; color: #fff; background: linear-gradient(145deg, #22c55e, #15803d); }
.sa-cat__sumtxt { display: grid; gap: 5px; min-width: 0; } .sa-cat__sumtxt b { font-size: 0.98rem; } .sa-cat__sumtxt small { display: flex; flex-wrap: wrap; gap: 5px; }
.sa-cat__names { display: flex; flex-wrap: wrap; gap: 5px; } .sa-cat__names span { max-width: 190px; padding: 2px 9px; border-radius: 8px; font-size: 0.7rem; font-weight: 600; background: var(--lg-card); border: 1px solid var(--lg-line); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .sa-cat__names .is-more { font-weight: 800; color: #0369a1; }
.sa-cat__foot { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 0.6rem; padding: 0.9rem 1.2rem 1.1rem; }
.sa-cat__go { min-width: 160px; justify-content: center; }
@media (max-width: 560px) { .sa-cat__head { flex-wrap: wrap; } .sa-cat__wh { order: 3; max-width: 100%; } .sa-cat__list li:not(.sa-cat__none) { grid-template-columns: auto minmax(0, 1fr) auto; grid-template-areas: 'b n t' '. p p'; } .sa-cat__badge { grid-area: b; } .sa-cat__name { grid-area: n; } .sa-cat__total { grid-area: t; } .sa-cat__pills { grid-area: p; justify-content: flex-start; } .sa-cat__foot .lg-btn { flex: 1 1 100%; justify-content: center; } }
.sa-import { white-space: nowrap; }
.sa-import-note { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 0.6rem; padding: 0.55rem 0.8rem; border-radius: 0.8rem; background: rgba(5, 150, 105, 0.1); color: #047857; font-size: 0.8rem; font-weight: 600; }
.sa-import-note.is-err { background: rgba(190, 18, 60, 0.1); color: #be123c; }
/* maroon "Clear From Stock" checkbox */
.sa-clear-cb { appearance: none; -webkit-appearance: none; width: 22px; height: 22px; flex: none; margin: 0; border: 2px solid #7f1d1d; border-radius: 6px; background: transparent; cursor: pointer; display: inline-grid; place-content: center; transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.12s ease; }
.sa-clear-cb::after { content: ''; width: 11px; height: 6px; border-left: 2.5px solid #fff; border-bottom: 2.5px solid #fff; transform: rotate(-45deg) scale(0); margin-top: -2px; transition: transform 0.12s ease; }
.sa-clear-cb:checked { background: #7f1d1d; box-shadow: 0 6px 12px -6px #7f1d1d; }
.sa-clear-cb:checked::after { transform: rotate(-45deg) scale(1); }
.sa-clear-cb:hover:not(:disabled) { transform: scale(1.08); }
.sa-clear-cb:focus-visible { outline: 3px solid rgba(127, 29, 29, 0.35); outline-offset: 2px; }
.sa-clear-cb:disabled { opacity: 0.45; cursor: not-allowed; }
.sa-clear-lbl, .sa-clear-all { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 700; }
.sa-clear-lbl .sa-clear-txt { display: none; color: #7f1d1d; font-size: 0.78rem; }
.sa-clear-lbl.is-on .sa-clear-txt { color: #7f1d1d; }
.sa-clear-all { flex-direction: column; gap: 4px; }
.sa-clear-all span { color: #7f1d1d; font-size: 0.68rem; letter-spacing: 0.03em; text-transform: uppercase; white-space: normal; line-height: 1.15; max-width: 5.2rem; text-align: center; }
.sa-clear-td, .sa-clear-th { text-align: center; }
.sa-clear-td { width: 5.2rem; }
.sa-row:has(.sa-clear-cb:checked) { background: rgba(127, 29, 29, 0.06); }
.sa-row:has(.sa-clear-cb:checked) .lg-main { text-decoration: line-through; text-decoration-color: rgba(127, 29, 29, 0.55); }
.sa-qty-input:disabled { opacity: 0.5; cursor: not-allowed; }
.sa-scan-hint {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 0.6rem;
  font-size: 0.72rem;
  font-weight: 700;
  color: var(--lg-mute);
}
.sa-tiles { margin-bottom: 1rem; }

/* Count table */
.sa-qty-input {
  width: 7rem;
  max-width: 100%;
  text-align: right;
  font-size: 0.85rem;
  font-variant-numeric: tabular-nums;
  padding: 0.4rem 0.6rem;
  border-radius: 0.7rem;
  border: 1px solid rgba(148, 163, 184, 0.45);
  background: var(--lg-card);
  color: var(--lg-ink);
}
.sa-qty-input:focus {
  outline: none;
  border-color: #60a5fa;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18);
}
.sa-variance { gap: 0.3rem; text-transform: none; }
.sa-cleared-tag {
  margin-left: 0.4rem;
  padding: 0.05rem 0.55rem;
  font-size: 0.66rem;
  text-transform: uppercase;
  letter-spacing: 0.02em;
  vertical-align: middle;
  cursor: help;
  outline: none;
}

/* Attachments */
.sa-att-list {
  margin-bottom: 0.9rem;
  border: 1px solid var(--lg-line);
  border-radius: 16px;
  background: var(--lg-soft);
  list-style: none;
  padding: 0;
}
.sa-att-new { margin-top: 0.9rem; margin-bottom: 0; }
.sa-att-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.55rem 0.85rem;
  color: var(--lg-ink);
  border-top: 1px solid var(--lg-line);
}
.sa-att-item:first-child { border-top: 0; }
.sa-file-pick {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  width: 100%;
  min-height: 44px;
  padding: 0.55rem 0.9rem;
  border-radius: 14px;
  border: 1px dashed var(--lg-line);
  background: var(--lg-soft);
  color: var(--lg-ink);
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease;
}
@media (min-width: 640px) { .sa-file-pick { width: auto; min-width: 20rem; } }
.sa-file-pick:hover { border-color: #60a5fa; background: var(--lg-card); }
.sa-pick-ico { color: #2563eb; }
.sa-file-row { animation: sa-file-in 0.18s ease-out; }
@keyframes sa-file-in {
  from { opacity: 0; transform: translateY(-4px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Submit button */
.sa-submit {
  padding-top: 8px !important;
  padding-bottom: 8px !important;
  border-radius: 9999px !important;
  border-color: transparent !important;
  text-transform: none !important;
  letter-spacing: normal !important;
  font-size: 0.82rem !important;
  font-weight: 800 !important;
}
.sa-submit:disabled { opacity: 0.55; }

/* Teleported to <body> — fixed positioning computed in JS (showTooltip), see
   Show.vue for the identical mechanism and rationale. */
.sa-teleport-tooltip {
  position: fixed;
  z-index: 9999;
  background: #1f2937;
  color: #f8fafc;
  font-size: 0.72rem;
  font-weight: 500;
  line-height: 1.42;
  text-align: left;
  padding: 0.55rem 0.7rem;
  border-radius: 0.6rem;
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.3);
  pointer-events: none;
}
.sa-tooltip-above { transform: translateY(-100%); }
.sa-tooltip-below { transform: translateY(0); }
.sa-tooltip-arrow {
  position: absolute;
  width: 0;
  height: 0;
  border: 5px solid transparent;
  margin-left: -5px;
}
.sa-tooltip-above .sa-tooltip-arrow { top: 100%; border-top-color: #1f2937; }
.sa-tooltip-below .sa-tooltip-arrow { bottom: 100%; border-bottom-color: #1f2937; }
@media (max-width: 480px) {
  .sa-teleport-tooltip { font-size: 0.68rem; }
}

/* Uncounted-items modal */
.sa-modal-overlay {
  position: fixed; inset: 0; z-index: 99999;
  display: flex; align-items: center; justify-content: center; padding: 1rem;
  background: rgba(15, 23, 42, 0.55);
  backdrop-filter: blur(2px);
  animation: sa-modal-fade 0.15s ease-out;
}
@keyframes sa-modal-fade { from { opacity: 0; } to { opacity: 1; } }
.sa-modal-card {
  width: 100%; max-width: 32rem; max-height: 85vh; overflow-y: auto;
  background: var(--lg-card); border: 1px solid var(--lg-line); border-radius: 22px; padding: 1.4rem;
  box-shadow: 0 20px 50px rgba(15, 23, 42, 0.3);
  animation: sa-modal-in 0.18s ease-out;
}
html.dark .sa-modal-card { background: #0f172a; }
@keyframes sa-modal-in {
  from { opacity: 0; transform: translateY(8px) scale(0.98); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
.sa-modal-title { font-size: 1rem; font-weight: 800; color: var(--lg-ink); }
.sa-modal-sub { margin-top: 0.25rem; font-size: 0.82rem; color: var(--lg-mute); }
.sa-modal-list {
  max-height: 14rem; overflow-y: auto;
  border: 1px solid var(--lg-line); border-radius: 14px;
}
.sa-modal-row {
  display: flex; align-items: center; justify-content: space-between; gap: 0.75rem;
  padding: 0.5rem 0.85rem; font-size: 0.85rem; color: var(--lg-ink);
  border-top: 1px solid var(--lg-line);
}
.sa-modal-row:first-child { border-top: none; }

@media (prefers-reduced-motion: reduce) {
  .sa-file-row { animation: none; }
}
.sa-session { margin-bottom: 10px; } .sa-session-link { font-weight: 800; text-decoration: underline; }
.sa-scanrow { display: flex; align-items: center; gap: 10px; }
.sa-scanrow > .lg-scan { flex: 1 1 auto; min-width: 0; }
.sa-jump { flex: none; display: grid; place-items: center; width: 44px; height: 44px; border: 0; border-radius: 14px; cursor: pointer; color: #0369a1; background: var(--lg-card, #fff); box-shadow: 0 0 0 1px var(--lg-line, #e3e9f2), 0 10px 16px -10px rgba(3, 105, 161, 0.55); transition: transform 0.15s ease, box-shadow 0.15s ease; }
.sa-jump:hover { transform: translateY(-2px); box-shadow: 0 0 0 1px #0369a1, 0 14px 20px -10px rgba(3, 105, 161, 0.65); }
.sa-jump:focus-visible { outline: 2px solid #0369a1; outline-offset: 2px; }
.sa-jump--act { position: absolute; top: 50%; right: calc(50% + 26px); width: 32px; height: 32px; border-radius: 10px; transform: translateY(-50%); }
.sa-jump--act:hover { transform: translateY(calc(-50% - 1px)); }
.sa-actions { position: relative; justify-content: center; }
.sa-act-th, .sa-act-td { width: 6.5rem; text-align: center; }
.sa-qtywrap { display: inline-flex; align-items: center; gap: 8px; justify-content: flex-end; }
@media (prefers-reduced-motion: reduce) { .sa-jump { transition: none; } .sa-jump:hover { transform: none; } }
.sa-um-card { max-width: 46rem; }
.sa-um-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
.sa-um-search { flex: 1 1 220px; position: relative; display: flex; align-items: center; min-width: 0; }
.sa-um-search > div { position: absolute; left: 12px; color: var(--lg-mute); pointer-events: none; }
.sa-um-search input { width: 100%; height: 2.5rem; padding: 0 12px 0 36px; border-radius: 12px; border: 1px solid var(--lg-line); background: var(--lg-card); color: inherit; }
.sa-um-cat { flex: 1 1 200px; min-width: 0; max-width: 260px; }
.sa-um-cat :deep(.mb-2) { margin-bottom: 0; }
.sa-um-cat :deep(input) { height: 2.5rem; }
.sa-um-note { margin: 0 0 8px; font-size: 0.78rem; color: var(--lg-mute); }
.sa-um-list { max-height: 20rem; }
.sa-um-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.sa-um-table th { position: sticky; top: 0; z-index: 1; background: #dbeafe; color: #1e3a8a; text-align: left; padding: 0.5rem 0.85rem; font-size: 0.72rem; letter-spacing: 0.04em; text-transform: uppercase; }
html.dark .sa-um-table th { background: #1e3a6e; color: #dbeafe; }
.sa-um-table th button { background: none; border: 0; padding: 0; font: inherit; color: inherit; text-transform: inherit; letter-spacing: inherit; cursor: pointer; white-space: nowrap; }
.sa-um-table th button i { font-style: normal; font-size: 0.8em; }
.sa-um-table th.num, .sa-um-table td.num { text-align: right; white-space: nowrap; }
.sa-um-table td { padding: 0.5rem 0.85rem; border-top: 1px solid var(--lg-line); vertical-align: top; }
.sa-um-empty { text-align: center; color: var(--lg-mute); padding: 1rem; }
@media (max-width: 560px) { .sa-um-cat { max-width: none; flex-basis: 100%; } }
</style>

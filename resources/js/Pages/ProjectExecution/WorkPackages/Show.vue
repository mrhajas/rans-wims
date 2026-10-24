<template>
  <admin-layout :title="workPackage.title">
    <div class="lg-page px-4 md:px-0 wps">
      <page-header icon="clip" :title="workPackage.title" :subtitle="headerSubtitle">
        <span class="lg-chip lg-chip-indigo lg-mono" style="text-transform: none">{{ workPackage.reference }}</span>
        <Link :href="route('work-packages.index')" class="lg-btn lg-btn-soft print:hidden">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Work Packages') }}
        </Link>
        <Link v-if="workPackage.can_edit" :href="route('work-packages.edit', workPackage.id)" class="lg-btn lg-btn-dark print:hidden">
          <icons name="edit" class="w-4 h-4" />
          {{ $t('Edit') }}
        </Link>
        <button v-if="workPackage.can_delete" type="button" @click="destroy" class="lg-btn lg-btn-red print:hidden">
          <icons name="trash" class="w-4 h-4" />
          {{ $t('Delete') }}
        </button>
      </page-header>

      <stat-tiles :items="statTiles" />

      <!-- General -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="doc" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('General') }}</h2>
            <p class="lg-card-sub">{{ workPackage.reference }}</p>
          </div>
          <span class="lg-chip" :class="healthChip(workPackage.schedule.health)" style="text-transform: none">{{ healthLabel(workPackage.schedule.health) }}</span>
        </header>

        <div class="wps-progress" aria-hidden="true">
          <span :style="{ width: actualBar + '%' }"></span>
        </div>

        <div class="wps-facts">
          <div>
            <p class="wps-k">{{ $t('Priority') }}</p>
            <p class="wps-v"><span class="lg-chip" :class="priorityChip(workPackage.priority)" style="text-transform: none">{{ $t(workPackage.priority) }}</span></p>
          </div>
          <div>
            <p class="wps-k">{{ $t('Status') }}</p>
            <p class="wps-v"><span class="lg-chip" :class="wpStatusChip(workPackage.status)" style="text-transform: none">{{ $t(workPackage.status) }}</span></p>
          </div>
          <div>
            <p class="wps-k">{{ $t('Engineer') }}</p>
            <p class="wps-v">{{ workPackage.engineer?.name || '-' }}</p>
          </div>
          <div>
            <p class="wps-k">{{ $t('Foreman') }}</p>
            <p class="wps-v">{{ workPackage.foreman?.name || '-' }}</p>
          </div>
          <div>
            <p class="wps-k">{{ $t('Subcontractor') }}</p>
            <p class="wps-v">{{ workPackage.subcontractor?.name || '-' }}</p>
          </div>
          <div>
            <p class="wps-k">{{ $t('Planned Start') }}</p>
            <p class="wps-v">{{ $date(workPackage.planned_start) }}</p>
          </div>
          <div>
            <p class="wps-k">{{ $t('Planned Finish') }}</p>
            <p class="wps-v">{{ $date(workPackage.planned_finish) }}</p>
          </div>
          <div>
            <p class="wps-k">{{ $t('Estimated Finish') }}</p>
            <p class="wps-v">{{ workPackage.schedule.estimated_finish ? $date(workPackage.schedule.estimated_finish) : '-' }}</p>
          </div>
        </div>
      </section>

      <!-- Attachments -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="clip" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
          </div>
          <span class="lg-chip lg-chip-slate" style="text-transform: none">{{ attachments.length }}</span>
        </header>

        <ul class="wps-list" v-if="attachments.length">
          <li v-for="a in attachments" :key="a.id" class="wps-list-row">
            <span class="inline-flex items-center gap-2 min-w-0">
              <icons :name="a.is_image ? 'image' : 'doc'" class="w-4 h-4 wps-faint flex-none" />
              <span class="truncate text-sm lg-main">{{ a.title }}</span>
              <span v-if="a.filesize" class="lg-subtext flex-none">({{ humanSize(a.filesize) }})</span>
            </span>
            <span class="flex items-center gap-1.5 flex-none print:hidden">
              <a :href="a.preview_url" target="_blank" class="lg-icon-btn lg-tip" :data-tip="$t('View')" :aria-label="$t('View')"><icons name="eye-flat" class="w-4 h-4" /></a>
              <a :href="a.download_url" class="lg-icon-btn lg-tip" :data-tip="$t('Download')" :aria-label="$t('Download')"><icons name="download" class="w-4 h-4" /></a>
              <button v-if="canManageAttachments" type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')" @click="deleteAttachment(a)">
                <icons name="trash" class="w-4 h-4" />
              </button>
            </span>
          </li>
        </ul>
        <p v-else class="lg-subtext wps-empty-text">{{ $t('No attachments yet.') }}</p>

        <form v-if="canManageAttachments" @submit.prevent="uploadAttachments" class="space-y-2 mt-4 print:hidden">
          <input
            id="wp-attachments"
            type="file"
            multiple
            class="sr-only"
            @change="onAttachmentsChange"
            accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx,.dwg,.zip"
          />
          <label for="wp-attachments" class="wps-drop">
            <span class="inline-flex items-center gap-2 text-sm font-semibold">
              <icons name="upload" class="w-4 h-4" />
              <span>{{ $t('Select files') }}</span>
            </span>
            <span class="lg-chip lg-chip-blue" style="text-transform: none">
              {{ attachmentForm.attachments.length ? attachmentForm.attachments.length + ' ' + $t('file(s)') : $t('No files chosen') }}
            </span>
          </label>

          <ul class="space-y-1" v-if="attachmentForm.attachments.length">
            <li v-for="(file, fi) in attachmentForm.attachments" :key="fi" class="wps-file">
              <span class="truncate">{{ fi + 1 }}. {{ fileLabel(file) }}</span>
              <button type="button" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove') + ' ' + fileLabel(file)" :aria-label="$t('Remove')" @click="removeNewAttachment(fi)">
                <icons name="cross" class="w-3.5 h-3.5" />
              </button>
            </li>
          </ul>
          <div class="text-sm text-red-600" v-if="attachmentForm.errors.attachments">{{ attachmentForm.errors.attachments }}</div>

          <div class="flex justify-end">
            <loading-button :loading="attachmentForm.processing" :disabled="!attachmentForm.attachments.length">{{ $t('Upload') }}</loading-button>
          </div>
        </form>
      </section>

      <!-- Submit daily update -->
      <section v-if="canSubmitUpdate" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="edit" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <h2 class="lg-card-title">{{ $t('Submit Daily Update') }}</h2>
            <p class="lg-card-sub">{{ $t('Progress %') }} / {{ $t('Activities Completed') }} / {{ $t('Photos') }}</p>
          </div>
        </header>

        <form @submit.prevent="submitUpdate" class="space-y-4">
          <div class="lg-fields">
            <div>
              <tec-label for="progress_percent" :value="$t('Progress %')" />
              <tec-input id="progress_percent" type="number" min="0" max="100" step="0.1" class="mt-1 block w-full" v-model="updateForm.progress_percent" required />
              <input-error :message="updateForm.errors.progress_percent" class="mt-2" />
            </div>
            <div class="lg-span-2">
              <tec-label for="weather" :value="$t('Weather')" />
              <tec-input id="weather" type="text" class="mt-1 block w-full" v-model="updateForm.weather" placeholder="Sunny, Rainy..." />
            </div>
          </div>

          <div>
            <tec-label for="activities_completed" :value="$t('Activities Completed')" />
            <textarea id="activities_completed" class="mt-1 block w-full rounded-md border-gray-300" rows="3" v-model="updateForm.activities_completed"></textarea>
          </div>

          <div class="lg-fields">
            <div class="lg-span-2">
              <tec-label for="safety_observations" :value="$t('Safety Observations')" />
              <textarea id="safety_observations" class="mt-1 block w-full rounded-md border-gray-300" rows="2" v-model="updateForm.safety_observations"></textarea>
            </div>
            <div class="lg-span-2">
              <tec-label for="inspection_results" :value="$t('Inspection Results')" />
              <textarea id="inspection_results" class="mt-1 block w-full rounded-md border-gray-300" rows="2" v-model="updateForm.inspection_results"></textarea>
            </div>
          </div>

          <div>
            <tec-label for="remarks" :value="$t('Remarks')" />
            <textarea id="remarks" class="mt-1 block w-full rounded-md border-gray-300" rows="2" v-model="updateForm.remarks"></textarea>
          </div>

          <div class="lg-fields">
            <div>
              <tec-label for="photos" :value="$t('Photos')" />
              <input id="photos" type="file" multiple accept="image/*" class="mt-1 block w-full text-sm" @change="updateForm.photos = Array.from($event.target.files)" />
            </div>
            <div>
              <tec-label for="videos" :value="$t('Videos')" />
              <input id="videos" type="file" multiple accept="video/*" class="mt-1 block w-full text-sm" @change="updateForm.videos = Array.from($event.target.files)" />
            </div>
            <div>
              <tec-label for="documents" :value="$t('Documents')" />
              <input id="documents" type="file" multiple class="mt-1 block w-full text-sm" @change="updateForm.documents = Array.from($event.target.files)" />
            </div>
          </div>

          <div class="flex justify-end">
            <loading-button :loading="updateForm.processing">{{ $t('Submit Update') }}</loading-button>
          </div>
        </form>
      </section>

      <!-- Daily updates -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="list" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Daily Updates') }}</h2>
          </div>
          <span class="lg-chip lg-chip-slate" style="text-transform: none">{{ dailyUpdates.length }}</span>
        </header>

        <div class="space-y-3">
          <div v-for="update in dailyUpdates" :key="update.id" class="wps-item">
            <div class="flex items-start justify-between gap-4 flex-wrap">
              <div class="min-w-0">
                <span class="lg-chip" :class="statusChip(update.status)" style="text-transform: none">{{ $t(update.status) }}</span>
                <p class="lg-main mt-1">{{ update.progress_percent }}% {{ $t('progress reported') }}</p>
                <p class="lg-subtext">{{ update.submitter_name }} on {{ $datetime(update.created_at) }}</p>
              </div>
              <div v-if="update.can_review && update.status === 'Pending Approval'" class="flex items-center gap-2 print:hidden">
                <button type="button" @click="approveUpdate(update)" class="lg-btn lg-btn-green"><icons name="tick" class="w-4 h-4" />{{ $t('Approve') }}</button>
                <button type="button" @click="rejectUpdate(update)" class="lg-btn lg-btn-red"><icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}</button>
              </div>
            </div>
            <p v-if="update.activities_completed" class="wps-text">{{ update.activities_completed }}</p>
            <p v-if="update.approval_remarks" class="wps-reject">{{ $t('Reviewer remarks') }}: {{ update.approval_remarks }}</p>
          </div>
          <p v-if="!dailyUpdates.length" class="lg-subtext wps-empty-text">{{ $t('No daily updates submitted yet.') }}</p>
        </div>
      </section>

      <!-- Material forecast -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="collection" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Material Forecast') }}</h2>
          </div>
          <span class="lg-chip lg-chip-slate" style="text-transform: none">{{ materialForecasts.length }}</span>
        </header>

        <form v-if="canManageForecast" @submit.prevent="addMaterialForecast" class="lg-fields wps-add">
          <div class="lg-span-2 relative">
            <auto-complete :suggestions="itemSuggestions" :placeholder="$t('Search item')" position="bottom-100 mb-2" v-model="materialForm.item_id" clearable />
          </div>
          <tec-input type="number" step="0.01" min="0.01" v-model="materialForm.required_quantity" :placeholder="$t('Required Qty')" />
          <tec-input type="date" v-model="materialForm.required_date" />
          <loading-button :loading="materialForm.processing">{{ $t('Add') }}</loading-button>
        </form>

        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Item') }}</th>
                <th>{{ $t('Required') }}</th>
                <th>{{ $t('Warehouse Stock') }}</th>
                <th>{{ $t('Shortage') }}</th>
                <th>{{ $t('Recommendation') }}</th>
                <th v-if="canManageForecast"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in materialForecasts" :key="f.id">
                <td :data-label="$t('Item')">
                  <span class="lg-main">{{ f.item?.name }}</span> <span class="wps-faint">({{ f.item?.code }})</span>
                </td>
                <td :data-label="$t('Required')">{{ f.required_quantity }} {{ f.item?.unit }}</td>
                <td :data-label="$t('Warehouse Stock')">{{ f.available_stock }}</td>
                <td :data-label="$t('Shortage')">
                  <span class="lg-chip" :class="f.has_shortage ? 'lg-chip-red' : 'lg-chip-green'" style="text-transform: none">{{ f.shortage }}</span>
                </td>
                <td :data-label="$t('Recommendation')">
                  <Link v-if="f.has_shortage" :href="f.material_request_url" class="lg-btn lg-btn-soft">{{ $t('Create Material Request') }}</Link>
                  <span v-else class="lg-chip lg-chip-green" style="text-transform: none">{{ $t('Sufficient') }}</span>
                </td>
                <td v-if="canManageForecast" :data-label="$t('Remove')" class="num">
                  <button type="button" @click="deleteForecast('material', f.id)" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')"><icons name="trash" class="w-4 h-4" /></button>
                </td>
              </tr>
              <tr v-if="!materialForecasts.length" class="lg-empty-row">
                <td class="lg-empty" :colspan="canManageForecast ? 6 : 5">{{ $t('No material forecasts yet.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Labour forecast -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-teal"><icons name="users" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Labour Forecast') }}</h2>
          </div>
          <span class="lg-chip lg-chip-slate" style="text-transform: none">{{ labourForecasts.length }}</span>
        </header>

        <form v-if="canManageForecast" @submit.prevent="addLabourForecast" class="lg-fields wps-add">
          <div class="relative">
            <auto-complete :suggestions="contactSuggestions" :placeholder="$t('Contact (optional)')" position="bottom-100 mb-2" v-model="labourForm.contact_id" clearable />
          </div>
          <tec-input type="text" v-model="labourForm.trade" :placeholder="$t('Trade')" />
          <tec-input type="number" min="1" v-model="labourForm.quantity" :placeholder="$t('Quantity')" />
          <tec-input type="number" min="1" v-model="labourForm.duration_days" :placeholder="$t('Duration (days)')" />
          <loading-button :loading="labourForm.processing">{{ $t('Add') }}</loading-button>
        </form>

        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Contact') }}</th>
                <th>{{ $t('Trade') }}</th>
                <th>{{ $t('Quantity') }}</th>
                <th>{{ $t('Duration') }}</th>
                <th v-if="canManageForecast"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in labourForecasts" :key="f.id">
                <td :data-label="$t('Contact')" class="lg-main">{{ f.contact?.name || '-' }}</td>
                <td :data-label="$t('Trade')">{{ f.trade || '-' }}</td>
                <td :data-label="$t('Quantity')">{{ f.quantity }}</td>
                <td :data-label="$t('Duration')">{{ f.duration_days ? f.duration_days + ' ' + $t('days') : '-' }}</td>
                <td v-if="canManageForecast" :data-label="$t('Remove')" class="num">
                  <button type="button" @click="deleteForecast('labour', f.id)" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')"><icons name="trash" class="w-4 h-4" /></button>
                </td>
              </tr>
              <tr v-if="!labourForecasts.length" class="lg-empty-row">
                <td class="lg-empty" :colspan="canManageForecast ? 5 : 4">{{ $t('No labour forecasts yet.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Equipment forecast -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-rose"><icons name="truck" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Equipment Forecast') }}</h2>
          </div>
          <span class="lg-chip lg-chip-slate" style="text-transform: none">{{ equipmentForecasts.length }}</span>
        </header>

        <form v-if="canManageForecast" @submit.prevent="addEquipmentForecast" class="lg-fields wps-add">
          <div class="lg-span-2 relative">
            <auto-complete :suggestions="itemSuggestions" :placeholder="$t('Search item')" position="bottom-100 mb-2" v-model="equipmentForm.item_id" clearable />
          </div>
          <tec-input type="number" step="0.01" min="0.01" v-model="equipmentForm.required_quantity" :placeholder="$t('Required Qty')" />
          <loading-button :loading="equipmentForm.processing">{{ $t('Add') }}</loading-button>
        </form>

        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive">
            <thead>
              <tr>
                <th>{{ $t('Item') }}</th>
                <th>{{ $t('Required') }}</th>
                <th>{{ $t('Warehouse Stock') }}</th>
                <th>{{ $t('Shortage') }}</th>
                <th v-if="canManageForecast"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in equipmentForecasts" :key="f.id">
                <td :data-label="$t('Item')">
                  <span class="lg-main">{{ f.item?.name }}</span> <span class="wps-faint">({{ f.item?.code }})</span>
                </td>
                <td :data-label="$t('Required')">{{ f.required_quantity }}</td>
                <td :data-label="$t('Warehouse Stock')">{{ f.available_stock }}</td>
                <td :data-label="$t('Shortage')">
                  <span class="lg-chip" :class="f.has_shortage ? 'lg-chip-red' : 'lg-chip-green'" style="text-transform: none">{{ f.shortage }}</span>
                </td>
                <td v-if="canManageForecast" :data-label="$t('Remove')" class="num">
                  <button type="button" @click="deleteForecast('equipment', f.id)" class="lg-icon-btn is-red lg-tip" :data-tip="$t('Remove')" :aria-label="$t('Remove')"><icons name="trash" class="w-4 h-4" /></button>
                </td>
              </tr>
              <tr v-if="!equipmentForecasts.length" class="lg-empty-row">
                <td class="lg-empty" :colspan="canManageForecast ? 5 : 4">{{ $t('No equipment forecasts yet.') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Issues -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-rose"><icons name="exclamation" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Issues') }}</h2>
          </div>
          <span class="lg-chip lg-chip-slate" style="text-transform: none">{{ issues.length }}</span>
        </header>

        <form v-if="canManageForecast" @submit.prevent="addIssue" class="space-y-3 mb-4">
          <div class="lg-fields">
            <div class="lg-span-2">
              <tec-input type="text" v-model="issueForm.title" :placeholder="$t('Issue title')" required />
            </div>
            <css-select v-model="issueForm.priority" class="rounded-md border-gray-300 text-sm">
              <option v-for="p in issuePriorities" :key="p" :value="p">{{ $t(p) }}</option>
            </css-select>
            <css-select v-model="issueForm.assigned_user_id" class="rounded-md border-gray-300 text-sm">
              <option :value="null">{{ $t('Unassigned') }}</option>
              <option v-for="u in assignableUsers" :key="u.id" :value="u.id">{{ u.name }}</option>
            </css-select>
          </div>
          <textarea v-model="issueForm.description" class="block w-full rounded-md border-gray-300 text-sm" rows="2" :placeholder="$t('Description')"></textarea>
          <div class="lg-fields items-end">
            <tec-input type="date" v-model="issueForm.deadline" :placeholder="$t('Deadline')" />
            <input type="file" multiple accept="image/*" class="text-sm" @change="issueForm.photos = Array.from($event.target.files)" />
            <loading-button :loading="issueForm.processing">{{ $t('Report Issue') }}</loading-button>
          </div>
        </form>

        <div class="space-y-3">
          <div v-for="issue in issues" :key="issue.id" class="wps-item">
            <div class="flex items-start justify-between gap-4 flex-wrap">
              <div class="min-w-0">
                <div class="flex items-center gap-1.5 flex-wrap">
                  <span class="lg-chip" :class="priorityChip(issue.priority)" style="text-transform: none">{{ $t(issue.priority) }}</span>
                  <span class="lg-chip" :class="issueStatusChip(issue.status)" style="text-transform: none">{{ $t(issue.status) }}</span>
                  <span class="lg-subtext lg-mono">{{ issue.reference }}</span>
                </div>
                <p class="lg-main mt-1">{{ issue.title }}</p>
                <p class="lg-subtext">{{ $t('Assigned to') }}: {{ issue.assignee_name || $t('Unassigned') }}<span v-if="issue.deadline"> · {{ $t('Deadline') }}: {{ $date(issue.deadline) }}</span></p>
                <p v-if="issue.description" class="wps-text">{{ issue.description }}</p>
                <p v-if="issue.resolution" class="wps-resolution">{{ $t('Resolution') }}: {{ issue.resolution }}</p>
              </div>
              <div v-if="issue.can_update" class="flex items-center gap-2 print:hidden">
                <css-select :value="issue.status" @change="updateIssueStatus(issue, $event.target.value)" class="rounded-md border-gray-300 text-xs">
                  <option v-for="s in issueStatuses" :key="s" :value="s">{{ $t(s) }}</option>
                </css-select>
                <button v-if="issue.can_delete" type="button" @click="deleteIssue(issue)" class="lg-icon-btn is-red lg-tip tip-left" :data-tip="$t('Delete')" :aria-label="$t('Delete')"><icons name="trash" class="w-4 h-4" /></button>
              </div>
            </div>
          </div>
          <p v-if="!issues.length" class="lg-subtext wps-empty-text">{{ $t('No issues reported yet.') }}</p>
        </div>
      </section>

      <!-- Timeline -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Timeline') }}</h2>
          </div>
        </header>

        <ol class="wps-timeline">
          <li v-for="event in timeline" :key="event.id">
            <span class="wps-dot"></span>
            <p class="lg-main">{{ event.action }}</p>
            <p class="lg-subtext">
              {{ event.user || $t('System') }}<span v-if="event.role"> ({{ event.role }})</span> on {{ $datetime(event.created_at) }}
            </p>
            <p v-if="event.remarks" class="wps-text">{{ event.remarks }}</p>
          </li>
          <li v-if="!timeline.length" class="lg-subtext">{{ $t('No activity recorded yet.') }}</li>
        </ol>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TecInput from '@/Jetstream/Input.vue';
import TecLabel from '@/Jetstream/Label.vue';
import InputError from '@/Jetstream/InputError.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';

export default {
  components: { AdminLayout, TecInput, TecLabel, InputError, LoadingButton, AutoComplete, PageHeader, StatTiles },

  props: {
    workPackage: Object,
    timeline: Array,
    attachments: Array,
    canManageAttachments: Boolean,
    dailyUpdates: Array,
    canSubmitUpdate: Boolean,
    canManageForecast: Boolean,
    materialForecasts: Array,
    labourForecasts: Array,
    equipmentForecasts: Array,
    issues: Array,
    issuePriorities: Array,
    issueStatuses: Array,
    assignableUsers: Array,
    items: Array,
    contacts: Array,
  },

  data() {
    return {
      attachmentForm: this.$inertia.form({ attachments: [] }),
      updateForm: this.$inertia.form({
        progress_percent: this.workPackage.actual_progress_percent,
        weather: '',
        activities_completed: '',
        safety_observations: '',
        inspection_results: '',
        remarks: '',
        photos: [],
        videos: [],
        documents: [],
      }),
      materialForm: this.$inertia.form({ item_id: null, required_quantity: '', required_date: '' }),
      labourForm: this.$inertia.form({ contact_id: null, trade: '', quantity: 1, duration_days: '' }),
      equipmentForm: this.$inertia.form({ item_id: null, required_quantity: '' }),
      issueForm: this.$inertia.form({
        title: '', description: '', priority: 'Medium', assigned_user_id: null, deadline: '', photos: [],
      }),
    };
  },

  computed: {
    headerSubtitle() {
      const wp = this.workPackage;
      return [wp.warehouse?.name, wp.category, wp.building].filter(Boolean).join(' · ');
    },
    statTiles() {
      const sc = this.workPackage.schedule;
      return [
        { icon: 'calendar', tone: 'sky', label: this.$t('Expected Progress'), value: `${sc.expected_progress}%` },
        { icon: 'chart-bar', tone: 'navy', label: this.$t('Actual Progress'), value: `${sc.actual_progress}%` },
        {
          icon: sc.variance >= 0 ? 'up' : 'down',
          tone: sc.variance >= 0 ? 'emerald' : 'rose',
          label: this.$t('Variance'),
          value: `${sc.variance >= 0 ? '+' : ''}${sc.variance}%`,
          valueClass: sc.variance >= 0 ? 'is-good' : 'is-bad',
        },
        { icon: 'clock', tone: 'amber', label: this.$t('Estimated Delay'), value: `${sc.estimated_delay_days} ${this.$t('Days')}` },
      ];
    },
    actualBar() {
      return Math.min(100, Math.max(0, Number(this.workPackage.schedule.actual_progress) || 0));
    },
    itemSuggestions() {
      return this.items.map(item => ({ id: item.id, label: `${item.code} - ${item.name}` }));
    },
    contactSuggestions() {
      return this.contacts.map(contact => ({ id: contact.id, label: contact.name + (contact.category ? ` (${contact.category})` : '') }));
    },
  },

  methods: {
    async destroy() {
      if (!(await this.$confirm(this.$t('Are you sure you want to delete this work package?'), { variant: 'danger' }))) {
        return;
      }
      this.$inertia.delete(this.route('work-packages.destroy', this.workPackage.id));
    },
    statusClass(status) {
      return { 'Pending Approval': 'amber', Approved: 'emerald', Rejected: 'rose' }[status] || 'slate';
    },
    statusChip(status) {
      return { 'Pending Approval': 'lg-chip-amber', Approved: 'lg-chip-green', Rejected: 'lg-chip-red' }[status] || 'lg-chip-slate';
    },
    wpStatusChip(status) {
      return { 'Not Started': 'lg-chip-slate', 'In Progress': 'lg-chip-blue', 'On Hold': 'lg-chip-amber', Completed: 'lg-chip-green' }[status] || 'lg-chip-slate';
    },
    priorityChip(priority) {
      return { Low: 'lg-chip-slate', Normal: 'lg-chip-blue', Medium: 'lg-chip-blue', High: 'lg-chip-amber', Urgent: 'lg-chip-red', Critical: 'lg-chip-red' }[priority] || 'lg-chip-slate';
    },
    issueStatusChip(status) {
      return { Open: 'lg-chip-red', 'In Progress': 'lg-chip-amber', Resolved: 'lg-chip-green', Closed: 'lg-chip-slate' }[status] || 'lg-chip-slate';
    },
    healthChip(health) {
      return { green: 'lg-chip-green', yellow: 'lg-chip-amber', orange: 'lg-chip-amber', red: 'lg-chip-red' }[health] || 'lg-chip-slate';
    },
    healthLabel(health) {
      return { green: this.$t('Ahead'), yellow: this.$t('Slight Delay'), orange: this.$t('Behind'), red: this.$t('Critical') }[health] || health;
    },
    // Native <input type=file multiple> replaces its own selection every
    // time the picker is used — choosing 2 files, then choosing 2 more,
    // would otherwise drop the first 2. Appending here (and resetting the
    // input after) lets the picker be opened repeatedly to build up one
    // combined list, matching removeNewAttachment()'s per-file delete below.
    onAttachmentsChange(e) {
      const newFiles = Array.from(e.target.files || []);
      const existingKeys = new Set(this.attachmentForm.attachments.map(f => `${f.name}:${f.size}:${f.lastModified}`));
      newFiles.forEach(file => {
        const key = `${file.name}:${file.size}:${file.lastModified}`;
        if (!existingKeys.has(key)) {
          this.attachmentForm.attachments.push(file);
          existingKeys.add(key);
        }
      });
      e.target.value = '';
    },
    removeNewAttachment(index) {
      this.attachmentForm.attachments.splice(index, 1);
    },
    fileLabel(file) {
      if (!file || typeof file !== 'object') return '';
      const name = file.name || 'Attachment';
      const size = Number(file.size);
      if (!Number.isFinite(size) || size < 0) return name;
      return `${name} (${this.humanSize(size)})`;
    },
    humanSize(size) {
      const n = Number(size);
      if (!n) return '0 B';
      const units = ['B', 'kB', 'MB', 'GB', 'TB'];
      const i = Math.min(Math.floor(Math.log(n) / Math.log(1024)), units.length - 1);
      return `${(n / Math.pow(1024, i)).toFixed(2)} ${units[i]}`;
    },
    uploadAttachments() {
      this.attachmentForm.post(this.route('work-packages.attachments.store', this.workPackage.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => this.attachmentForm.reset('attachments'),
      });
    },
    async deleteAttachment(attachment) {
      if (!(await this.$confirm(this.$t('Remove this attachment?'), { variant: 'danger' }))) return;
      this.$inertia.delete(attachment.delete_url, { preserveScroll: true });
    },
    submitUpdate() {
      this.updateForm.post(this.route('work-package-daily-updates.store', this.workPackage.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
          this.updateForm.reset('activities_completed', 'weather', 'safety_observations', 'inspection_results', 'remarks', 'photos', 'videos', 'documents');
        },
      });
    },
    async approveUpdate(update) {
      if (!(await this.$confirm(this.$t('Approve this daily update? The reported progress will be applied to the Work Package.')))) {
        return;
      }
      this.$inertia.put(this.route('work-package-daily-updates.approve', update.id), {}, { preserveScroll: true });
    },
    async rejectUpdate(update) {
      const remarks = await this.$prompt(this.$t('Reason for rejecting this update:'));
      if (!remarks) {
        return;
      }
      this.$inertia.put(this.route('work-package-daily-updates.reject', update.id), { approval_remarks: remarks }, { preserveScroll: true });
    },
    addMaterialForecast() {
      this.materialForm.post(this.route('work-package-material-forecasts.store', this.workPackage.id), {
        preserveScroll: true,
        onSuccess: () => this.materialForm.reset(),
      });
    },
    addLabourForecast() {
      this.labourForm.post(this.route('work-package-labour-forecasts.store', this.workPackage.id), {
        preserveScroll: true,
        onSuccess: () => this.labourForm.reset(),
      });
    },
    addEquipmentForecast() {
      this.equipmentForm.post(this.route('work-package-equipment-forecasts.store', this.workPackage.id), {
        preserveScroll: true,
        onSuccess: () => this.equipmentForm.reset(),
      });
    },
    async deleteForecast(type, id) {
      if (!(await this.$confirm(this.$t('Remove this forecast entry?'), { variant: 'danger' }))) {
        return;
      }
      const routeName = { material: 'work-package-material-forecasts.destroy', labour: 'work-package-labour-forecasts.destroy', equipment: 'work-package-equipment-forecasts.destroy' }[type];
      this.$inertia.delete(this.route(routeName, [this.workPackage.id, id]), { preserveScroll: true });
    },
    addIssue() {
      this.issueForm.post(this.route('work-package-issues.store', this.workPackage.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => this.issueForm.reset(),
      });
    },
    async updateIssueStatus(issue, status) {
      let resolution = issue.resolution;
      if (status === 'Resolved' && !resolution) {
        resolution = await this.$prompt(this.$t('Resolution details:'));
        if (!resolution) {
          return;
        }
      }
      this.$inertia.put(this.route('work-package-issues.update', [this.workPackage.id, issue.id]), { status, resolution }, { preserveScroll: true });
    },
    async deleteIssue(issue) {
      if (!(await this.$confirm(this.$t('Delete this issue?'), { variant: 'danger' }))) {
        return;
      }
      this.$inertia.delete(this.route('work-package-issues.destroy', [this.workPackage.id, issue.id]), { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.wps-k {
  font-size: 0.64rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--lg-faint);
}
.wps-v {
  margin-top: 3px;
  font-size: 0.88rem;
  font-weight: 700;
  color: var(--lg-ink);
}
.wps-faint {
  color: var(--lg-faint);
}
.wps-facts {
  display: grid;
  gap: 14px 18px;
  grid-template-columns: repeat(auto-fit, minmax(min(160px, 100%), 1fr));
}
.wps-progress {
  height: 9px;
  margin-bottom: 16px;
  border-radius: 9999px;
  overflow: hidden;
  background: var(--lg-soft);
  border: 1px solid var(--lg-line);
}
.wps-progress span {
  display: block;
  height: 100%;
  border-radius: 9999px;
  background: linear-gradient(90deg, #60a5fa, #1d4ed8);
}
.wps-list {
  border: 1px solid var(--lg-line);
  border-radius: 16px;
}
.wps-list-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 9px 14px;
  border-bottom: 1px solid var(--lg-line);
}
.wps-list-row:last-child {
  border-bottom: 0;
}
.wps-empty-text {
  padding: 4px 0;
}
.wps-drop {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
  min-height: 44px;
  padding: 8px 14px;
  border-radius: 14px;
  cursor: pointer;
  color: var(--lg-navy);
  border: 1.5px dashed #93c5fd;
  background: rgba(59, 130, 246, 0.05);
  transition: background 0.15s ease;
}
.wps-drop:hover {
  background: rgba(59, 130, 246, 0.1);
}
html.dark .wps-drop {
  color: #bfdbfe;
}
.wps-file {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 5px 10px;
  font-size: 0.76rem;
  color: var(--lg-mute);
  border-radius: 10px;
  background: var(--lg-soft);
  border: 1px solid var(--lg-line);
}
.wps-item {
  padding: 14px 16px;
  border-radius: 16px;
  background: var(--lg-soft);
  border: 1px solid var(--lg-line);
}
.wps-text {
  margin-top: 8px;
  font-size: 0.84rem;
  line-height: 1.55;
  color: var(--lg-ink);
  white-space: pre-line;
  overflow-wrap: anywhere;
}
.wps-reject {
  margin-top: 8px;
  font-size: 0.76rem;
  color: #be123c;
}
.wps-resolution {
  margin-top: 8px;
  font-size: 0.76rem;
  color: #047857;
}
html.dark .wps-reject { color: #fda4af; }
html.dark .wps-resolution { color: #6ee7b7; }
.wps-add {
  align-items: start;
  margin-bottom: 1rem;
}
.wps-timeline {
  position: relative;
  margin-left: 10px;
  padding-left: 22px;
  border-left: 2px solid var(--lg-line);
}
.wps-timeline li {
  position: relative;
  margin-bottom: 18px;
}
.wps-timeline li:last-child {
  margin-bottom: 0;
}
.wps-dot {
  position: absolute;
  left: -29px;
  top: 4px;
  width: 12px;
  height: 12px;
  border-radius: 9999px;
  background: #3b82f6;
  box-shadow: 0 0 0 3px var(--lg-card);
}
</style>

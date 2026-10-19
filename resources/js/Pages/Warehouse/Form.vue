<template>
  <admin-layout :title="$t(edit ? 'edit_x' : 'create_x', { x: $t('Warehouse') })">
    <div class="lg-page px-4 md:px-0 fx-form">
      <page-header
        icon="building"
        :title="$t(edit ? 'edit_x' : 'create_x', { x: $t('Warehouse') })"
        :subtitle="edit ? $t('Update the record by modifying the details in the form below') : $t('Please fill the form below to add new record.')"
      >
        <span v-if="edit" class="lg-chip lg-chip-indigo" style="text-transform: none">{{ edit.name }}</span>
        <span v-if="edit && completed.is_completed" class="lg-chip lg-chip-amber">
          <icons name="lock-closed" class="w-3 h-3" />
          {{ $t('Completed') }}
        </span>
        <Link :href="route('warehouses.index')" class="lg-btn lg-btn-soft"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Warehouses') }}</Link>
      </page-header>

      <project-diary-panel v-if="edit && edit.id" :warehouse-id="edit.id" />

      <div v-if="completed.is_completed" class="lg-note lg-note-amber wf-completed mb-4">
        <icons name="lock-closed" class="w-5 h-5" />
        <div class="min-w-0 flex-1">
          <p class="font-bold">{{ $t('This project has been marked complete.') }}</p>
          <p class="text-xs mt-0.5">
            {{ $t('Completed on') }} {{ completed.completed_at || '-' }}
            <span v-if="completed.completed_by_name">&middot; {{ $t('by') }} {{ completed.completed_by_name }}</span>
          </p>
        </div>
        <button v-if="canManageCompletion" type="button" class="lg-btn lg-btn-green" :disabled="togglingReopen" @click="openReopenDialog">
          <icons name="refresh" class="w-4 h-4" :class="{ 'animate-spin': togglingReopen }" />
          {{ $t('Re-Open Project') }}
        </button>
      </div>

      <form @submit.prevent="update">
        <trashed-message v-if="edit && edit.deleted_at" class="mb-4" @restore="restore">
          {{ $t('This record has been deleted.') }}
        </trashed-message>

        <fieldset :disabled="lockedForEditing" class="wf-fieldset" :class="{ 'opacity-60': lockedForEditing }">
          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-navy"><icons name="building" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Basic Information') }}</h2>
                <p class="lg-card-sub">{{ $t('Code') }} / {{ $t('Name') }} / {{ $t('Phone') }} / {{ $t('Email') }}</p>
              </div>
            </header>
            <div class="lg-fields">
              <div>
                <text-input v-model="form.code" :error="$page.props.errors.code" :label="$t('Code')" />
              </div>
              <div>
                <text-input v-model="form.name" :error="$page.props.errors.name" :label="$t('Name')" />
              </div>
              <div>
                <text-input v-model="form.phone" :error="$page.props.errors.phone" :label="$t('Phone')" />
              </div>
              <div>
                <text-input type="email" v-model="form.email" :error="$page.props.errors.email" :label="$t('Email')" />
              </div>
            </div>
          </section>

          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-indigo"><icons name="collection" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Project(s) / Warehouse(s) Type & Stage') }}</h2>
                <p class="lg-card-sub">{{ $t('Project Completion') }}</p>
              </div>
            </header>
            <div class="lg-fields">
              <div>
                <select-input v-model="form.status" :error="$page.props.errors.status" :label="$t('Project(s) / Warehouse(s) Type')">
                  <option value="Main Warehouse">{{ $t('Main Warehouse') }}</option>
                  <option value="Directors">{{ $t('Directors') }}</option>
                  <option value="Private">{{ $t('Private') }}</option>
                  <option value="Government">{{ $t('Government') }}</option>
                  <option value="General">{{ $t('General') }}</option>
                  <option value="Rans Warehouse">{{ $t('Rans Warehouse') }}</option>
                </select-input>
              </div>
              <div>
                <creatable-select
                  v-model="form.stage"
                  :options="projectStages"
                  :error="$page.props.errors.stage"
                  :label="$t('Project(s) / Warehouse(s) Stage')"
                  :placeholder="$t('Search or add a stage...')"
                />
              </div>

              <div class="lg-span-all wf-slider">
                <div class="flex items-center justify-between gap-3">
                  <tec-label :value="$t('Project Completion')" />
                  <span class="lg-chip lg-chip-green">{{ form.completion_percentage ?? 0 }}%</span>
                </div>
                <input
                  type="range"
                  min="0"
                  max="100"
                  step="1"
                  v-model.number="form.completion_percentage"
                  class="mt-2 block w-full accent-indigo-600"
                />
                <div class="flex justify-between text-xs text-gray-400 mt-1">
                  <span>0%</span>
                  <span>50%</span>
                  <span>100%</span>
                </div>
                <div v-if="$page.props.errors.completion_percentage" class="text-red-600 text-sm mt-1 wims-field-error">
                  {{ $page.props.errors.completion_percentage }}
                </div>
              </div>
            </div>
          </section>

          <section id="project-location" ref="locationSection" class="lg-card" :class="{ 'is-flash': flashLocation }">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-teal"><icons name="map-pin" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Logo & Address') }}</h2>
                <p class="lg-card-sub">{{ $t('Logo') }} / {{ $t('Address') }}</p>
              </div>
            </header>
            <div class="lg-fields">
              <div class="lg-span-all flex items-center justify-between gap-4 flex-wrap">
                <file-input class="grow" v-model="form.logo" :error="$page.props.errors.logo" :label="$t('Logo')" />
                <div v-if="edit && edit.logo">
                  <p class="text-sm">{{ $t('Current Logo') }}</p>
                  <img class="block h-12 rounded" :src="edit.logo" />
                </div>
              </div>
              <div class="lg-span-all">
                <textarea-input v-model="form.address" :error="$page.props.errors.address" :label="$t('Address') + ' (' + $t('from the map pin') + ')'" readonly class="wh-addr-ro" :placeholder="$t('Choose the location on the map below. The address is filled in from the pin.')" />
              </div>
              <div class="lg-span-all">
                <location-picker
                  v-model:latitude="form.latitude"
                  v-model:longitude="form.longitude"
                  v-model:address="form.address"
                />
              </div>
            </div>
          </section>

          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-sky"><icons name="cog" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Project Details') }}</h2>
                <p class="lg-card-sub">{{ $t('Site timeline, budget, and contract summary') }}</p>
              </div>
            </header>
            <div class="lg-fields">
              <div>
                <tec-label :value="$t('Start Date')" />
                <date-picker v-model="form.start_date" class="mt-1 block w-full" />
                <div v-if="$page.props.errors.start_date" class="text-red-600 text-sm mt-1 wims-field-error">{{ $page.props.errors.start_date }}</div>
              </div>
              <div>
                <tec-label :value="$t('Expected End Date')" />
                <date-picker v-model="form.expected_end_date" class="mt-1 block w-full" />
                <div v-if="$page.props.errors.expected_end_date" class="text-red-600 text-sm mt-1 wims-field-error">{{ $page.props.errors.expected_end_date }}</div>
              </div>

              <div>
                <auto-complete
                  v-model="form.for_who"
                  :label="$t('For Who')"
                  :error="$page.props.errors.for_who"
                  :suggestions="clients"
                  value-key="name"
                  placeholder="Search clients…"
                  freeform
                  clearable
                />
              </div>
              <div>
                <text-input v-model="form.registration_number" :error="$page.props.errors.registration_number" :label="$t('Registration Number')" />
              </div>

              <div>
                <text-input
                  v-model="form.external_body_personnel"
                  :error="$page.props.errors.external_body_personnel"
                  :label="$t('External Body / Personnel')"
                />
              </div>
              <div>
                <tec-label :value="$t('Estimated Daily Running Costs') + ' (Tsh)'" />
                <input
                  :value="displayAmount('site_budget')"
                  @focus="onAmountFocus('site_budget')"
                  @input="onAmountInput('site_budget', $event)"
                  @blur="onAmountBlur('site_budget')"
                  type="text" inputmode="decimal"
                  class="mt-1 block w-full border-gray-300 focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 rounded-md shadow-sm"
                />
                <div v-if="$page.props.errors.site_budget" class="text-red-600 text-sm mt-1 wims-field-error">{{ $page.props.errors.site_budget }}</div>
              </div>

              <div class="lg-span-all">
                <textarea-input v-model="form.other_info" :error="$page.props.errors.other_info" :label="$t('Other Info')" />
              </div>

              <div>
                <auto-complete
                  v-model="form.project_manager"
                  :label="$t('Project Manager / Site Engineer')"
                  :error="$page.props.errors.project_manager"
                  :suggestions="projectManagers"
                  value-key="name"
                  placeholder="Search users…"
                  freeform
                  clearable
                />
              </div>
              <div>
                <auto-complete
                  v-model="form.floors_units"
                  :label="$t('Number of Floors / Units')"
                  :error="$page.props.errors.floors_units"
                  :suggestions="floorOptions"
                  placeholder="Search floors…"
                  clearable
                />
              </div>

              <div>
                <auto-complete
                  v-model="form.project_category"
                  :label="$t('Project Category / Type')"
                  :error="$page.props.errors.project_category"
                  :suggestions="categoryOptions"
                  placeholder="Select category…"
                  clearable
                />
              </div>
              <div>
                <auto-complete
                  v-model="form.priority_level"
                  :label="$t('Priority Level')"
                  :error="$page.props.errors.priority_level"
                  :suggestions="priorityOptions"
                  placeholder="Select priority…"
                  clearable
                />
              </div>

              <div>
                <text-input
                  type="number"
                  v-model="form.estimated_workforce"
                  :error="$page.props.errors.estimated_workforce"
                  :label="$t('Estimated Workforce')"
                />
              </div>

              <div v-if="canViewCertificates" class="lg-span-all whd-contract-block">
                <div class="flex items-center justify-between gap-3 mb-3 flex-wrap">
                  <h4 class="whd-contract-heading">{{ $t('Contract Details') }}</h4>
                  <Link v-if="edit" :href="route('project-certificates.project.index', edit.id)" class="whd-contract-link">
                    <icons name="cog" class="w-3.5 h-3.5" />
                    {{ certificateContract ? $t('Edit Contract Details') : $t('Setup Contract Details') }}
                  </Link>
                </div>

                <!-- Creating a new project: no id yet, so there's nowhere to link to. -->
                <div v-if="!edit" class="whd-contract-empty">
                  <icons name="info" class="w-4 h-4 flex-none" />
                  {{ $t("You'll be able to set up this project's contract details once it's created.") }}
                </div>

                <!-- Existing project, contract not set up yet. -->
                <div v-else-if="!certificateContract" class="whd-contract-empty">
                  <icons name="exclamation" class="w-4 h-4 flex-none" />
                  {{ $t('No contract has been set up for this project yet.') }}
                </div>

                <!-- Read-only mirror of Contract Setup — editing only ever happens there. -->
                <div v-else class="whd-contract-grid">
                  <div><div class="whd-contract-label">{{ $t('Client') }}</div><div class="whd-contract-value">{{ certificateContract.client?.name || '—' }}</div></div>
                  <div><div class="whd-contract-label">{{ $t('Consultant') }}</div><div class="whd-contract-value">{{ certificateContract.consultant?.name || '—' }}</div></div>
                  <div><div class="whd-contract-label">{{ $t('Contractor Name') }}</div><div class="whd-contract-value">{{ certificateContract.contractor_name || '—' }}</div></div>
                  <div><div class="whd-contract-label">{{ $t('Contract Reference') }}</div><div class="whd-contract-value">{{ certificateContract.contract_reference || '—' }}</div></div>
                  <div><div class="whd-contract-label">{{ $t('Currency') }}</div><div class="whd-contract-value">{{ certificateContract.currency }}</div></div>
                  <div><div class="whd-contract-label">{{ $t('VAT') }}</div><div class="whd-contract-value">{{ certificateContract.vat_included ? $t('Included') + ' (' + Number(certificateContract.vat_rate).toFixed(2) + '%)' : $t('Not Included') }}</div></div>
                  <div><div class="whd-contract-label">{{ $t('Original Contract Sum') }}</div><div class="whd-contract-value">{{ money(certificateContract.original_contract_sum, certificateContract.currency) }}</div></div>
                  <div><div class="whd-contract-label">{{ $t('Contract Variation Amount') }}</div><div class="whd-contract-value">{{ money(certificateContract.contract_variation_amount, certificateContract.currency) }}</div></div>
                  <div><div class="whd-contract-label">{{ $t('Revised Sum (VAT Exclusive)') }}</div><div class="whd-contract-value whd-contract-value-strong">{{ money(certificateContract.revised_contract_sum, certificateContract.currency) }}</div></div>
                  <div><div class="whd-contract-label">{{ $t('Contract Start Date') }}</div><div class="whd-contract-value">{{ certificateContract.contract_start_date || '—' }}</div></div>
                  <div><div class="whd-contract-label">{{ $t('Contract End Date') }}</div><div class="whd-contract-value">{{ certificateContract.contract_end_date || '—' }}</div></div>
                </div>
              </div>
            </div>
          </section>

          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Attachments') }}</h2>
                <p class="lg-card-sub">{{ $t('Drawings') }} / {{ $t('Photos') }} / {{ $t('BOQ Attachment') }}</p>
              </div>
            </header>
            <div class="flex flex-col gap-6">
              <multi-file-upload
                v-model="form.drawings"
                :label="$t('Drawings')"
                :existing-attachments="edit ? edit.drawings : []"
                :parent-id="edit ? edit.id : null"
                :error="$page.props.errors.drawings"
                download-route-name="warehouses.attachments.download"
                destroy-route-name="warehouses.attachments.destroy"
              />

              <multi-file-upload
                v-model="form.photos"
                :label="$t('Photos')"
                accept="image/*"
                :existing-attachments="edit ? edit.photos : []"
                :parent-id="edit ? edit.id : null"
                :error="$page.props.errors.photos"
                download-route-name="warehouses.attachments.download"
                destroy-route-name="warehouses.attachments.destroy"
              />

              <multi-file-upload
                v-model="form.boq_attachment"
                :label="$t('BOQ Attachment')"
                :existing-attachments="edit ? edit.boq_attachments : []"
                :parent-id="edit ? edit.id : null"
                :error="$page.props.errors.boq_attachment"
                download-route-name="warehouses.attachments.download"
                destroy-route-name="warehouses.attachments.destroy"
              />
            </div>
          </section>

          <section class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Project Status') }}</h2>
                <p class="lg-card-sub">{{ $t('Active') }}</p>
              </div>
            </header>
            <div class="flex flex-col gap-4">
              <div class="wf-row" :class="form.active ? 'is-on' : ''">
                <label :for="'active'" class="flex items-center gap-2" :class="canToggleActive ? 'cursor-pointer' : ''">
                  <icons name="tick" class="w-5 h-5" :class="form.active ? 'text-emerald-600' : 'text-gray-400'" />
                  <span class="font-bold" :class="form.active ? 'wf-on-text' : 'wf-off-text'">{{ $t('Active') }}</span>
                </label>

                <check-box v-if="!edit" id="active" v-model:checked="form.active" :error="$page.props.errors.active" />
                <check-box v-else-if="canToggleActive" id="active" :checked="form.active" @update:checked="onActiveToggleRequest" :error="$page.props.errors.active" />
                <span v-else class="lg-chip" :class="form.active ? 'lg-chip-green' : 'lg-chip-slate'">
                  {{ form.active ? $t('Active') : $t('Inactive') }}
                </span>
              </div>

              <div v-if="canManageCompletion && !completed.is_completed" class="lg-note lg-note-amber wf-complete">
                <icons name="lock-closed" class="w-5 h-5" />
                <div class="min-w-0 flex-1">
                  <p class="font-bold">{{ $t('Complete Project') }}</p>
                  <p class="text-xs mt-0.5">
                    {{ $t('Locks this project for everyone except Super Admin. No data is changed or deleted.') }}
                  </p>
                </div>
                <button type="button" @click="openCompleteDialog" class="lg-btn lg-btn-dark flex-none">
                  <icons name="lock-closed" class="w-4 h-4" />
                  {{ $t('Complete Project') }}
                </button>
              </div>
            </div>
          </section>

          <section v-if="edit" class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-pink"><icons name="doc-text" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Related Records') }}</h2>
                <p class="lg-card-sub">{{ $t('Payment Vouchers') }}</p>
              </div>
            </header>
            <div class="wf-row">
              <div class="flex items-center gap-2">
                <icons name="doc-text" class="w-5 h-5 text-pink-600" />
                <span class="font-bold">{{ $t('Payment Vouchers') }}</span>
              </div>
              <Link :href="route('payment-vouchers.index', { warehouse_id: edit.id })" class="lg-btn lg-btn-primary">
                <icons name="view" class="w-4 h-4" /> {{ $t('View') }}
              </Link>
            </div>
          </section>
        </fieldset>

        <div v-if="!lockedForEditing" class="lg-actionbar">
          <template v-if="edit">
            <button type="button" @click="destroy" v-if="!edit.deleted_at" class="lg-btn lg-btn-red">
              <icons name="trash" class="w-4 h-4" />
              {{ $t('delete_x', { x: $t('Warehouse') }) }}
            </button>
            <button v-else type="button" @click="deletePermanently" class="lg-btn lg-btn-red">
              <icons name="trash" class="w-4 h-4" />
              {{ $t('delete_x', { x: $t('Permanently') }) }}
            </button>
          </template>
          <div v-else></div>
          <div class="lg-actionbar-btns">
            <tec-action-message :on="form.recentlySuccessful" class="lg-actionbar-note">{{ $t('Saved.') }}</tec-action-message>
            <Link :href="route('warehouses.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" :disabled="form.processing" class="lg-btn lg-btn-primary">
              <icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
              {{ $t('Save / Update') }}
            </button>
          </div>
        </div>
      </form>
    </div>

    <!-- Delete User Confirmation Modal -->
    <Dialog
      max-width="md"
      :show="permanent"
      action-type="delete"
      title="Delete Role?"
      :close="closePermanentModal"
      :action="deleteRolePermanently"
      action-text="Delete Permanently"
      :content="`<p class='mb-2'>${$t('Are you sure you want to delete the record permanently?')}</p>
        <p>${$t('Once deleted, all of its resources and data will be permanently deleted.')}</p>`"
    />

    <!-- Delete Account Confirmation Modal -->
    <Dialog
      :show="confirm"
      :close="closeModal"
      :action="deleteWarehouse"
      action-type="delete"
      title="Delete Project(s) / Warehouse(s)?"
      action-text="Delete Project(s) / Warehouse(s)"
      :content="$t('Are you sure you want to delete the record?')"
    />

    <!-- Restore Account Confirmation Modal -->
    <Dialog
      :show="restoreConf"
      :action="restoreWarehouse"
      title="Restore Project(s) / Warehouse(s)!"
      :close="closeRestoreModal"
      action-text="Restore Project(s) / Warehouse(s)"
      :content="$t('Are you sure you want to restore the record?')"
    />

    <!-- Deactivate Confirmation Modal -->
    <tec-dialog-modal :show="showActiveDialog" @close="closeActiveDialog" max-width="sm">
      <template #title>
        <div class="lg-page wf-modal-head">
          <span class="lg-card-ico lg-ico-rose"><icons name="lock-closed" class="w-5 h-5" /></span>
          <span class="lg-card-title">{{ $t('Deactivate Project(s) / Warehouse(s)') }}</span>
        </div>
      </template>

      <template #content>
        <p class="mb-4 text-sm text-gray-700 wf-modal-text">
          {{ $t('Enter your account password to confirm deactivating this project/warehouse.') }}
        </p>
        <div class="wf-pass">
          <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $t('Password') }}</label>
          <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
              <icons name="key" class="h-4 w-4" />
            </span>
            <input
              v-model="activePassword"
              type="password"
              autocomplete="current-password"
              class="w-full rounded-md border-gray-300 pl-10 shadow-sm focus:border-red-300 focus:ring focus:ring-red-200 focus:ring-opacity-50"
              @keyup.enter="submitActiveChange"
            />
          </div>
          <div v-if="activeFormError" class="mt-2 text-xs text-red-600">{{ activeFormError }}</div>
        </div>
      </template>

      <template #footer>
        <div class="lg-page flex items-center justify-between">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeActiveDialog">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-red" @click="submitActiveChange" :disabled="!activePassword || togglingActive">
            <icons v-if="togglingActive" name="refresh" class="w-4 h-4 animate-spin" />
            {{ $t('Deactivate') }}
          </button>
        </div>
      </template>
    </tec-dialog-modal>

    <!-- Complete Project Confirmation Modal -->
    <tec-dialog-modal :show="showCompleteDialog" @close="closeCompleteDialog" max-width="sm">
      <template #title>
        <div class="lg-page wf-modal-head">
          <span class="lg-card-ico lg-ico-amber"><icons name="lock-closed" class="w-5 h-5" /></span>
          <span class="lg-card-title">{{ $t('Complete Project') }}</span>
        </div>
      </template>

      <template #content>
        <p class="mb-4 text-sm text-gray-700 wf-modal-text">
          {{ $t('This will lock the project for everyone except Super Admin. No data is changed or deleted. Enter your account password to confirm.') }}
        </p>
        <div class="wf-pass">
          <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $t('Password') }}</label>
          <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
              <icons name="key" class="h-4 w-4" />
            </span>
            <input
              v-model="completePassword"
              type="password"
              autocomplete="current-password"
              class="w-full rounded-md border-gray-300 pl-10 shadow-sm focus:border-amber-300 focus:ring focus:ring-amber-200 focus:ring-opacity-50"
              @keyup.enter="submitCompleteProject"
            />
          </div>
          <div v-if="completeFormError" class="mt-2 text-xs text-red-600">{{ completeFormError }}</div>
        </div>
      </template>

      <template #footer>
        <div class="lg-page flex items-center justify-between">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeCompleteDialog">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-dark" @click="submitCompleteProject" :disabled="!completePassword || togglingComplete">
            <icons v-if="togglingComplete" name="refresh" class="w-4 h-4 animate-spin" />
            {{ $t('Complete Project') }}
          </button>
        </div>
      </template>
    </tec-dialog-modal>

    <!-- Re-Open Project Confirmation Modal -->
    <tec-dialog-modal :show="showReopenDialog" @close="closeReopenDialog" max-width="sm">
      <template #title>
        <div class="lg-page wf-modal-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="refresh" class="w-5 h-5" /></span>
          <span class="lg-card-title">{{ $t('Re-Open Project') }}</span>
        </div>
      </template>

      <template #content>
        <p class="mb-4 text-sm text-gray-700 wf-modal-text">
          {{ $t('This will make the project visible and editable again for everyone with access. Enter your account password to confirm.') }}
        </p>
        <div class="wf-pass">
          <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $t('Password') }}</label>
          <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
              <icons name="key" class="h-4 w-4" />
            </span>
            <input
              v-model="reopenPassword"
              type="password"
              autocomplete="current-password"
              class="w-full rounded-md border-gray-300 pl-10 shadow-sm focus:border-emerald-300 focus:ring focus:ring-emerald-200 focus:ring-opacity-50"
              @keyup.enter="submitReopenProject"
            />
          </div>
          <div v-if="reopenFormError" class="mt-2 text-xs text-red-600">{{ reopenFormError }}</div>
        </div>
      </template>

      <template #footer>
        <div class="lg-page flex items-center justify-between">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeReopenDialog">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-green" @click="submitReopenProject" :disabled="!reopenPassword || togglingReopen">
            <icons v-if="togglingReopen" name="refresh" class="w-4 h-4 animate-spin" />
            {{ $t('Re-Open Project') }}
          </button>
        </div>
      </template>
    </tec-dialog-modal>
  </admin-layout>
</template>

<script>
import Dialog from '@/Shared/Dialog.vue';
import CheckBox from '@/Shared/CheckBox.vue';
import FileInput from '@/Shared/FileInput.vue';
import TextInput from '@/Shared/TextInput.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import ProjectDiaryPanel from '@/Shared/SiteDiary/ProjectDiaryPanel.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import TrashedMessage from '@/Shared/TrashedMessage.vue';
import TecFormSection from '@/Jetstream/FormSection.vue';
import TecDangerButton from '@/Jetstream/DangerButton.vue';
import TecSectionTitle from '@/Jetstream/SectionTitle.vue';
import TecActionMessage from '@/Jetstream/ActionMessage.vue';
import TecSecondaryButton from '@/Jetstream/SecondaryButton.vue';
import TecLabel from '@/Jetstream/Label.vue';
import MultiFileUpload from '@/Shared/MultiFileUpload.vue';
import CreatableSelect from '@/Shared/CreatableSelect.vue';
import LocationPicker from '@/Shared/LocationPicker.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import DatePicker from '@/Shared/DatePicker.vue';

export default {
  props: ['edit', 'stageSuggestions', 'certificateContract', 'canViewCertificates', 'clients', 'projectManagers'],

  components: {
    ProjectDiaryPanel,
    Dialog,
    CheckBox,
    FileInput,
    TextInput,
    SelectInput,
    AdminLayout,
    PageHeader,
    LoadingButton,
    TextareaInput,
    TecDialogModal,
    TecFormSection,
    TecDangerButton,
    TrashedMessage,
    TecSectionTitle,
    CreatableSelect,
    TecActionMessage,
    TecSecondaryButton,
    TecLabel,
    MultiFileUpload,
    LocationPicker,
    AutoComplete,
    DatePicker,
  },

  data() {
    return {
      focusedAmountField: null,
      confirm: false,
      permanent: false,
      restoreConf: false,
      showActiveDialog: false,
      pendingActiveState: false,
      activePassword: '',
      activeFormError: '',
      togglingActive: false,
      showCompleteDialog: false,
      completePassword: '',
      completeFormError: '',
      togglingComplete: false,
      flashLocation: false,
      showReopenDialog: false,
      reopenPassword: '',
      reopenFormError: '',
      togglingReopen: false,
      projectStages: this.stageSuggestions || [],
      form: this.$inertia.form({
        _method: this.edit ? 'put' : 'post',
        logo: null,
        code: this.edit ? this.edit.code : null,
        name: this.edit ? this.edit.name : null,
        email: this.edit ? this.edit.email : null,
        phone: this.edit ? this.edit.phone : null,
        address: this.edit ? this.edit.address : null,
        latitude: this.edit && this.edit.latitude !== null && this.edit.latitude !== undefined ? Number(this.edit.latitude) : null,
        longitude: this.edit && this.edit.longitude !== null && this.edit.longitude !== undefined ? Number(this.edit.longitude) : null,
        active: this.edit ? this.edit.active == 1 : true,
        status: this.edit ? this.edit.status : 'Rans Warehouse',
        start_date: this.edit ? this.edit.start_date : null,
        expected_end_date: this.edit ? this.edit.expected_end_date : null,
        for_who: this.edit ? this.edit.for_who : null,
        registration_number: this.edit ? this.edit.registration_number : null,
        external_body_personnel: this.edit ? this.edit.external_body_personnel : null,
        site_budget: this.edit ? this.edit.site_budget : null,
        other_info: this.edit ? this.edit.other_info : null,
        project_manager: this.edit ? this.edit.project_manager : null,
        project_category: this.edit ? this.edit.project_category : null,
        floors_units: this.edit ? this.edit.floors_units : null,
        priority_level: this.edit ? this.edit.priority_level : null,
        estimated_workforce: this.edit ? this.edit.estimated_workforce : null,
        stage: this.edit ? this.edit.stage : null,
        completion_percentage: this.edit ? this.edit.completion_percentage ?? 0 : 0,
        drawings: [],
        photos: [],
        boq_attachment: [],
      }),
      completed: {
        is_completed: this.edit ? !!this.edit.is_completed : false,
        completed_at: this.edit ? this.edit.completed_at : null,
        completed_by_name: this.edit ? this.edit.completed_by_name : null,
      },
    };
  },

  computed: {
    canToggleActive() {
      return this.$admin;
    },
    canManageCompletion() {
      return this.$super && !!this.edit;
    },
    lockedForEditing() {
      return this.completed.is_completed && !this.$super;
    },
    floorOptions() {
      return Array.from({ length: 20 }, (_, i) => i + 1).map(n => ({ id: String(n), name: n + (n === 1 ? ' Floor' : ' Floors') }));
    },
    categoryOptions() {
      return ['Residential', 'Commercial', 'Institutional', 'Renovation', 'Infrastructure', 'Other'].map(v => ({ id: v, name: this.$t(v) }));
    },
    priorityOptions() {
      return ['Low', 'Medium', 'High'].map(v => ({ id: v, name: this.$t(v) }));
    },
  },

  mounted() {
    // "Setup Location" on the Weather page opens this page at #location: scroll to the Logo & Address section and flash it once
    if (typeof window !== 'undefined' && window.location.hash === '#location') {
      this.$nextTick(() => setTimeout(() => {
        const el = this.$refs.locationSection;
        if (!el) return;
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        this.flashLocation = true;
        setTimeout(() => { this.flashLocation = false; }, 2600);
      }, 350));
    }
  },
  methods: {
    money(v, currency) {
      return (currency || '') + ' ' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    // Comma-formatted while settled, plain digits while actively being
    // typed into — reformatting on every keystroke fights the cursor
    // position, so the field only re-formats on blur. Same pattern as
    // ProjectCertificates/ContractForm.vue's amount fields.
    displayAmount(field) {
      if (this.focusedAmountField === field) {
        return this.form[field] === null || this.form[field] === undefined ? '' : String(this.form[field]);
      }

      return Number(this.form[field] || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    onAmountFocus(field) {
      this.focusedAmountField = field;
    },
    onAmountInput(field, event) {
      const raw = event.target.value.replace(/[^0-9.-]/g, '');
      this.form[field] = raw === '' || raw === '-' ? null : Number(raw);
    },
    onAmountBlur(field) {
      if (this.focusedAmountField === field) this.focusedAmountField = null;
      if (this.form[field] === null || Number.isNaN(this.form[field])) this.form[field] = 0;
    },
    update() {
      this.form.post(this.edit ? this.route('warehouses.update', this.edit.id) : this.route('warehouses.store'), {
        preserveScroll: true,
      });
    },
    onActiveToggleRequest(newValue) {
      if (newValue) {
        this.applyActiveChange(true);
        return;
      }

      this.pendingActiveState = false;
      this.activePassword = '';
      this.activeFormError = '';
      this.showActiveDialog = true;
    },
    submitActiveChange() {
      if (!this.activePassword || this.togglingActive) {
        return;
      }
      this.applyActiveChange(false, this.activePassword);
    },
    applyActiveChange(active, password = null) {
      this.togglingActive = true;
      this.activeFormError = '';
      window.axios
        .post(this.route('warehouses.toggle-active', this.edit.id), { active, password })
        .then(res => {
          this.form.active = Boolean(res.data.active);
          this.showActiveDialog = false;
          this.activePassword = '';
        })
        .catch(error => {
          const message = error.response?.data?.errors?.password?.[0] || error.response?.data?.errors?.active?.[0] || 'Could not update active status.';
          this.activeFormError = message;
        })
        .finally(() => {
          this.togglingActive = false;
        });
    },
    closeActiveDialog() {
      if (this.togglingActive) {
        return;
      }
      this.showActiveDialog = false;
      this.activePassword = '';
      this.activeFormError = '';
    },
    openCompleteDialog() {
      this.completePassword = '';
      this.completeFormError = '';
      this.showCompleteDialog = true;
    },
    closeCompleteDialog() {
      if (this.togglingComplete) {
        return;
      }
      this.showCompleteDialog = false;
      this.completePassword = '';
      this.completeFormError = '';
    },
    submitCompleteProject() {
      if (!this.completePassword || this.togglingComplete) {
        return;
      }
      this.togglingComplete = true;
      this.completeFormError = '';
      window.axios
        .post(this.route('warehouses.complete-project', this.edit.id), { password: this.completePassword })
        .then(res => {
          this.completed.is_completed = true;
          this.completed.completed_at = res.data.completed_at;
          this.completed.completed_by_name = res.data.completed_by_name;
          this.showCompleteDialog = false;
          this.completePassword = '';
        })
        .catch(error => {
          this.completeFormError = error.response?.data?.errors?.password?.[0] || 'Could not complete this project.';
        })
        .finally(() => {
          this.togglingComplete = false;
        });
    },
    openReopenDialog() {
      this.reopenPassword = '';
      this.reopenFormError = '';
      this.showReopenDialog = true;
    },
    closeReopenDialog() {
      if (this.togglingReopen) {
        return;
      }
      this.showReopenDialog = false;
      this.reopenPassword = '';
      this.reopenFormError = '';
    },
    submitReopenProject() {
      if (!this.reopenPassword || this.togglingReopen) {
        return;
      }
      this.togglingReopen = true;
      this.reopenFormError = '';
      window.axios
        .post(this.route('warehouses.reopen-project', this.edit.id), { password: this.reopenPassword })
        .then(() => {
          this.completed.is_completed = false;
          this.completed.completed_at = null;
          this.completed.completed_by_name = null;
          this.showReopenDialog = false;
          this.reopenPassword = '';
        })
        .catch(error => {
          this.reopenFormError = error.response?.data?.errors?.password?.[0] || 'Could not re-open this project.';
        })
        .finally(() => {
          this.togglingReopen = false;
        });
    },
    destroy() {
      this.confirm = true;
    },
    deleteWarehouse() {
      this.form.delete(route('warehouses.destroy', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    closeModal() {
      this.confirm = false;
    },
    restore() {
      this.restoreConf = true;
    },
    restoreWarehouse() {
      this.$inertia.put(this.route('warehouses.restore', this.edit.id), {
        onSuccess: () => (this.restoreConf = false),
      });
    },
    closeRestoreModal() {
      this.restoreConf = false;
    },
    deletePermanently() {
      this.permanent = true;
    },
    deleteRolePermanently() {
      this.form.delete(route('warehouses.destroy.permanently', this.edit.id), {
        onSuccess: () => this.closeModal(),
      });
    },
    closePermanentModal() {
      this.permanent = false;
    },
  },
};
</script>

<style scoped>
#project-location { scroll-margin-top: 90px; }
:deep(.wh-addr-ro textarea) { cursor: default; background: var(--lg-soft, #f1f5f9); color: var(--lg-ink, #0f172a); }
.is-flash { animation: loc-flash 1.3s ease 2; }
@keyframes loc-flash { 0%, 100% { box-shadow: var(--lg-shadow); } 50% { box-shadow: 0 0 0 3px #2f6fb3, 0 18px 30px -16px rgba(47, 111, 179, 0.6); } }
@media (prefers-reduced-motion: reduce) { .is-flash { animation: none; box-shadow: 0 0 0 3px #2f6fb3; } }
.wf-fieldset {
  min-width: 0;
  border: 0;
  padding: 0;
  margin: 0;
}
.wf-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  padding: 0.75rem 1rem;
  border-radius: 1rem;
  border: 1px solid var(--lg-line);
  background: var(--lg-soft);
  transition: background 0.15s ease, border-color 0.15s ease;
}
.wf-row.is-on {
  border-color: rgba(16, 185, 129, 0.4);
  background: rgba(16, 185, 129, 0.08);
}
.wf-on-text {
  color: #047857;
}
.wf-off-text {
  color: var(--lg-mute);
}
html.dark .wf-on-text {
  color: #6ee7b7;
}
.wf-completed,
.wf-complete {
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}
.wf-modal-head {
  display: flex;
  align-items: center;
  gap: 12px;
}
.wf-modal-head .lg-card-title {
  font-size: 1.05rem;
}
.wf-pass input {
  border-radius: 0.85rem;
}
.wf-slider :deep(label) {
  margin-bottom: 0;
}

.whd-contract-block { border-top: 1px dashed var(--lg-line); padding-top: 1.25rem; }
.whd-contract-heading { font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: var(--lg-navy); }
.whd-contract-link { display: inline-flex; align-items: center; gap: 6px; font-size: 0.72rem; font-weight: 700; color: #1c3f66; background: #eef2ff; border-radius: 9999px; padding: 0.4rem 0.85rem; transition: transform 0.15s ease, background 0.15s ease; }
.whd-contract-link:hover { background: #e0e7ff; transform: translateY(-1px); }
.whd-contract-empty { display: flex; align-items: center; gap: 8px; background: var(--lg-soft); border: 1px dashed var(--lg-line); border-radius: 0.75rem; padding: 0.85rem 1rem; font-size: 0.78rem; color: var(--lg-mute); }
.whd-contract-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.9rem 1.5rem; background: var(--lg-soft); border-radius: 0.75rem; padding: 1rem 1.1rem; }
@media (min-width: 1024px) { .whd-contract-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 480px) { .whd-contract-grid { grid-template-columns: 1fr; } }
.whd-contract-label { font-size: 0.64rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: var(--lg-faint); margin-bottom: 0.15rem; }
.whd-contract-value { font-size: 0.85rem; font-weight: 600; color: var(--lg-ink); overflow-wrap: anywhere; }
.whd-contract-value-strong { font-size: 1rem; font-weight: 800; color: #7f1d1d; }

html.dark .whd-contract-link { background: rgba(99, 102, 241, 0.15); color: #93c5fd; }
html.dark .whd-contract-value-strong { color: #fca5a5; }

.fx-form :deep(.lg-fields label:not(:has(input[type='checkbox']))) {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--lg-mute);
}
.fx-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='range'])),
.fx-form :deep(.lg-fields select),
.fx-form :deep(.lg-fields textarea) {
  border-radius: 0.85rem !important;
  border-color: var(--lg-line) !important;
  background: var(--lg-card);
  color: var(--lg-ink);
  padding-top: 0.6rem !important;
  padding-bottom: 0.6rem !important;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.fx-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='range']):hover),
.fx-form :deep(.lg-fields select:hover) {
  border-color: rgba(59, 130, 246, 0.55) !important;
}
.fx-form :deep(.lg-fields input:not([type='checkbox']):not([type='radio']):not([type='range']):focus),
.fx-form :deep(.lg-fields select:focus),
.fx-form :deep(.lg-fields textarea:focus) {
  border-color: #3b82f6 !important;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18) !important;
}
</style>

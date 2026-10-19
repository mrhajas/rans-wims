<template>
  <admin-layout :title="mine ? $t('My Tasks') : $t('Team Manager')">
    <div class="tm-bg-decor" aria-hidden="true">
      <span class="tm-blob tm-blob-1"></span>
      <span class="tm-blob tm-blob-2"></span>
    </div>
    <div class="lg-page px-4 md:px-0 max-w-[1600px] mx-auto relative z-[1] grid grid-cols-1 gap-6" :class="!mine && sidebarVisible ? 'xl:grid-cols-[1fr_320px]' : ''">
      <div class="min-w-0" :class="{ 'tm-board-main-split': !mine && sidebarVisible }">
        <page-header
          :icon="mine ? 'user' : 'users'"
          :title="mine ? $t('My Tasks') : $t('Team Manager')"
          :subtitle="mine ? $t('Your personal tasks, created by you or assigned to you.') : $t('Team operations center. Drag a card to move it, click a card for details.')"
        >
          <div v-if="!mine && canUseGlobalCreatorFilter" class="tm-tooltip-wrap">
            <button type="button" class="tm-sidebar-toggle" :class="{ 'tm-sidebar-toggle-active': sidebarVisible }" :aria-label="$t('Toggle sidebar')" :aria-pressed="sidebarVisible ? 'true' : 'false'" @click="toggleSidebar">
              <icons :name="sidebarVisible ? 'eye' : 'eye-off'" class="w-4 h-4" />
            </button>
            <div class="tm-tooltip tm-sidebar-tooltip">{{ sidebarVisible ? $t('Hide side panel') : $t('Show side panel') }}</div>
          </div>
          <button v-if="$can('create-task-manager')" type="button" class="lg-btn lg-btn-primary" @click="openAdd">
            <icons name="plus" class="w-4 h-4" />
            {{ mine ? $t('New Personal Task') : $t('New Team Task') }}
          </button>
        </page-header>

        <stat-tiles :items="statTiles" @select="item => item.columnKey && scrollToColumn(item.columnKey)" />

        <div class="tm-filterbar relative z-20 print:hidden">
          <div class="tm-filterbar-row">
            <div class="relative flex-1 sm:min-w-[220px]">
              <icons name="search" class="tm-filter-icon" />
              <input v-model="searchTerm" type="text" :placeholder="$t('Search tasks...')" class="tm-search-input w-full pl-9 pr-3 py-2.5 text-sm rounded-full" @input="search" />
            </div>
            <simple-autocomplete v-model="priority" :options="priorityFilterOptions" icon="exclamation" :placeholder="$t('Any priority')" class="sm:w-44" @update:modelValue="search" />
            <simple-autocomplete v-if="!mine && canUseGlobalCreatorFilter" v-model="assignedToUserId" :options="userFilterOptions" icon="user" :placeholder="$t('Anyone')" class="sm:w-52" @update:modelValue="search" />
            <simple-autocomplete v-model="category" :options="categoryFilterOptions" icon="folder" :placeholder="$t('Any category')" class="sm:w-44" @update:modelValue="search" />
            <simple-autocomplete v-if="!mine && canUseGlobalCreatorFilter" v-model="creatorFilter" :options="creatorFilterOptions" :clearable="false" icon="edit" :placeholder="$t('Created by')" class="sm:w-48" @update:modelValue="search" />
          </div>
          <div v-if="calendarDateFilter" class="relative mt-3">
            <button type="button" class="tm-date-filter-chip" @click="calendarDateFilter = null">
              <icons name="calendar" class="w-3.5 h-3.5" />
              {{ $t('Due') }} {{ formatDateStr(calendarDateFilter) }}
              <icons name="cross" class="w-3 h-3" />
            </button>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
          <div v-for="col in columnDefs" :key="col.key" class="tm-column" :data-column-key="col.key" :style="{ '--tm-col-accent': statusColors[col.key] }" :class="{ 'tm-column-drag-over': dragOverColumn === col.key }" @dragover.prevent="onColumnDragOver(col.key)" @drop.prevent="onColumnDrop(col.key)">
            <div class="tm-column-header">
              <div class="tm-tooltip-wrap">
                <button type="button" class="tm-column-collapse" :aria-label="$t('Collapse column')" @click="toggleColumn(col.key)">
                  <icons name="chevron-down" class="w-4 h-4" :class="{ 'tm-collapse-icon-flipped': collapsedColumns[col.key] }" />
                </button>
                <div class="tm-tooltip">{{ collapsedColumns[col.key] ? $t('Expand') : $t('Collapse') }}</div>
              </div>
              <span class="tm-column-icon-badge" :style="{ background: statusColors[col.key], '--tm-col-glow': statusColors[col.key] }">
                <icons :name="columnIcon(col.key)" class="w-3 h-3" />
              </span>
              <span class="tm-column-title">{{ col.label }}</span>
              <span class="tm-column-count-circle" :style="{ background: statusColors[col.key] }">{{ animatedColumnCounts[col.key] }}</span>
            </div>

            <div v-show="!collapsedColumns[col.key]" class="tm-column-body">
              <transition-group name="tm-card-pop" tag="div" class="contents" appear>
                <div
                  v-for="(task, idx) in visibleColumnTasks[col.key]"
                  :key="task.id"
                  class="tm-card tm-tooltip-wrap"
                  :class="[`tm-card-status-${col.key}`, { 'tm-card-drag-over': dragOverColumn === col.key && dragOverCard === task.id, 'tm-card-completing': completingId === task.id }]"
                  :style="{ borderLeftColor: statusColors[col.key], '--tm-card-delay': Math.min(idx, 10) * 45 + 'ms' }"
                  :draggable="canChangeStatus"
                  @dragstart="onCardDragStart($event, col.key, task.id)"
                  @dragover.prevent.stop="onCardDragOver(col.key, task.id)"
                  @drop.prevent.stop="onCardDrop(col.key, idx)"
                  @dragend="onCardDragEnd"
                  @click="openDetail(task)"
                >
                  <div class="flex items-start justify-between gap-2">
                    <span class="tm-priority-badge" :class="`tm-priority-badge-${task.priority}`">
                      <icons :name="priorityIcon(task.priority)" class="w-2.5 h-2.5" />
                      {{ priorityLabel(task.priority) }}
                    </span>
                    <div class="flex items-center gap-1">
                      <span v-if="task.category" class="tm-category-chip">{{ task.category }}</span>
                      <button
                        v-if="col.key !== 'done' && $can('mark-complete-task-manager')"
                        type="button"
                        class="tm-complete-btn tm-tip tm-tip-left"
                        :data-tip="$t('Mark Complete')"
                        @click.stop="markComplete(task, col.key)"
                      >
                        <icons name="tick" class="w-3 h-3" />
                      </button>
                      <button
                        v-else-if="col.key === 'done' && canDeleteTask(task)"
                        type="button"
                        class="tm-card-delete-btn tm-tip tm-tip-left"
                        :data-tip="$t('Delete Task')"
                        @click.stop="askDelete(task)"
                      >
                        <icons name="trash" class="w-3 h-3" />
                      </button>
                    </div>
                  </div>
                  <div class="tm-card-title">
                    <span>{{ task.title }}</span>
                    <icons v-if="task.is_private" name="lock-closed" class="w-3 h-3 text-gray-400 flex-none tm-tip tm-tip-up" :data-tip="$t('Private, only visible to you and anyone assigned')" />
                  </div>
                  <span v-if="!mine && creatorFilter === 'all' && (task.created_by || task.createdBy)" class="tm-creator-chip">
                    <icons name="edit" class="w-2.5 h-2.5" />{{ $t('By') }} {{ (task.created_by || task.createdBy).name }}
                  </span>
                  <p v-if="task.description" class="tm-card-desc line-clamp-2">{{ task.description }}</p>

                  <div v-if="task.checklist_items_count" class="tm-progress mt-3">
                    <div class="tm-progress-track">
                      <div class="tm-progress-fill" :style="{ width: checklistPct(task) + '%' }"></div>
                    </div>
                    <span class="tm-progress-label">{{ task.checklist_done_count }}/{{ task.checklist_items_count }}</span>
                  </div>

                  <div class="tm-card-foot">
                    <div class="tm-avatar-stack">
                      <span v-for="(a, i) in (task.assignees || []).slice(0, 3)" :key="i" class="tm-avatar">{{ initials(a.assignee?.name) }}</span>
                      <span v-if="(task.assignees || []).length > 3" class="tm-avatar tm-avatar-more">+{{ task.assignees.length - 3 }}</span>
                      <span v-if="!(task.assignees || []).length" class="text-[11px] text-gray-400">{{ $t('Unassigned') }}</span>
                    </div>
                    <div class="tm-card-meta">
                      <span v-if="task.due_date" class="tm-due-chip" :class="{ 'tm-due-chip-overdue': isOverdue(task), 'tm-due-chip-today': !isOverdue(task) && isDueToday(task) }">
                        <icons name="clock" class="w-3 h-3" />
                        {{ formatDate(task.due_date) }}<template v-if="task.due_time">, {{ formatTime(task.due_time) }}</template>
                      </span>
                      <span v-if="task.comments_count" class="tm-meta-item"><icons name="sms" class="w-3 h-3" />{{ task.comments_count }}</span>
                      <span v-if="task.attachments_count" class="tm-meta-item"><icons name="clip" class="w-3 h-3" />{{ task.attachments_count }}</span>
                    </div>
                  </div>

                  <div class="tm-tooltip tm-card-tooltip">
                    <div class="font-semibold mb-1">{{ task.title }}</div>
                    <div v-if="task.description" class="tm-card-tooltip-desc">{{ task.description }}</div>
                    <div class="tm-card-tooltip-assignees">
                      {{ $t('Assigned to') }}: {{ (task.assignees || []).map(a => a.assignee?.name).filter(Boolean).join(', ') || $t('Unassigned') }}
                    </div>
                  </div>
                </div>
              </transition-group>
              <div v-if="!columns[col.key].length" class="tm-column-empty">
                <icons name="collection" class="w-6 h-6" />
                {{ $t('No tasks here yet.') }}
              </div>
              <button v-if="columns[col.key].length > columnPageSize" type="button" class="tm-column-showall" @click="expandedColumns[col.key] = !expandedColumns[col.key]">
                <icons :name="expandedColumns[col.key] ? 'up' : 'down'" class="w-3.5 h-3.5" />
                {{ expandedColumns[col.key] ? $t('Collapse') : $t('Show All ({n})', { n: columns[col.key].length }) }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Right sidebar: Team roster / Calendar / Recent Activity — team board only, hidden by default -->
      <div v-if="!mine && sidebarVisible" ref="sidebarPanel" class="flex flex-col gap-5">
        <div class="tm-side-card">
          <div class="tm-side-header">
            <icons name="users" class="w-4 h-4" />
            {{ $t('Team') }}
            <span class="tm-side-count">{{ team.length }}</span>
          </div>
          <div class="tm-team-list">
            <div
              v-for="member in team"
              :key="member.id"
              class="tm-team-row"
              @click="filterByTeamMember(member)"
              @mouseenter="showTeamTooltip(member, $event)"
              @mouseleave="hideTeamTooltip"
            >
              <span class="tm-avatar tm-avatar-lg" :class="{ 'tm-avatar-has-photo': member.has_photo }">
                <img v-if="member.has_photo" :src="member.profile_photo_url" :alt="member.name" class="tm-avatar-photo-img" />
                <template v-else>{{ initials(member.name) }}</template>
              </span>
              <div class="min-w-0 flex-1">
                <div class="text-sm font-semibold text-gray-800 truncate">{{ member.name }}</div>
                <div class="text-xs text-gray-500">{{ member.task_count }} {{ $t('tasks') }}</div>
              </div>
              <icons name="chevron-down" class="w-3.5 h-3.5 text-gray-300 -rotate-90" />
            </div>
            <p v-if="!team.length" class="text-xs text-gray-400 text-center py-4">{{ $t('No one has tasks assigned yet.') }}</p>
          </div>
        </div>

        <Teleport to="body">
          <div v-if="teamTooltipMember" class="tm-team-tooltip-float" :style="teamTooltipStyle">
            <div class="tm-team-tooltip-float-name">{{ teamTooltipMember.name }}</div>
            <div v-if="!teamTooltipMember.tasks.length" class="tm-team-tooltip-float-empty">{{ $t('No active tasks.') }}</div>
            <div v-for="t in teamTooltipMember.tasks.slice(0, 6)" :key="t.id" class="tm-team-tooltip-float-row">
              <span class="tm-team-tooltip-dot" :style="{ background: statusColors[t.status] }"></span>
              <span class="tm-team-tooltip-float-title">{{ t.title }}</span>
            </div>
            <div v-if="teamTooltipMember.tasks.length > 6" class="tm-team-tooltip-float-more">
              {{ $t('+{n} more', { n: teamTooltipMember.tasks.length - 6 }) }}
            </div>
          </div>
        </Teleport>

        <div class="tm-side-card">
          <div class="tm-side-header">
            <icons name="calendar" class="w-4 h-4" />
            <span class="flex-1">{{ calendarLabel }}</span>
            <div class="tm-tooltip-wrap">
              <button type="button" class="tm-cal-nav" @click="shiftMonth(-1)"><icons name="chevron-down" class="w-4 h-4 rotate-90" /></button>
              <div class="tm-tooltip">{{ $t('Previous month') }}</div>
            </div>
            <div class="tm-tooltip-wrap">
              <button type="button" class="tm-cal-nav" @click="shiftMonth(1)"><icons name="chevron-down" class="w-4 h-4 -rotate-90" /></button>
              <div class="tm-tooltip">{{ $t('Next month') }}</div>
            </div>
          </div>
          <div class="tm-calendar-grid">
            <span v-for="d in ['S', 'M', 'T', 'W', 'T', 'F', 'S']" :key="d" class="tm-calendar-dow">{{ d }}</span>
            <div v-for="(day, idx) in calendarDays" :key="idx" class="tm-calendar-cell-wrap">
              <div
                v-if="day"
                class="tm-calendar-cell tm-tooltip-wrap"
                :class="{ 'tm-calendar-cell-today': day.isToday, 'tm-calendar-cell-due': day.tasks.length, 'tm-calendar-cell-clickable': day.tasks.length, 'tm-calendar-cell-active': calendarDateFilter === day.dateStr }"
                @click="day.tasks.length && toggleCalendarDateFilter(day.dateStr)"
              >
                {{ day.day }}
                <span v-if="day.tasks.length" class="tm-calendar-dots">
                  <span v-for="t in day.tasks.slice(0, 4)" :key="t.id" class="tm-calendar-dot" :style="{ background: statusColors[t.status] }"></span>
                </span>
                <div v-if="day.tasks.length" class="tm-tooltip tm-calendar-tooltip">
                  <div v-for="t in day.tasks" :key="t.id" class="tm-cal-task-row">
                    <div class="tm-cal-task-head">
                      <span class="tm-cal-task-dot" :style="{ background: statusColors[t.status] }"></span>
                      <span class="tm-cal-task-title">{{ t.title }}</span>
                    </div>
                    <div class="tm-cal-task-meta">
                      <span class="tm-cal-task-status" :style="{ color: statusColors[t.status] }">{{ statusLabel(t.status) }}</span>
                      <span class="tm-cal-task-sep">·</span>
                      <span class="tm-cal-task-assignees">{{ (t.assignees || []).map(a => a.assignee?.name).filter(Boolean).join(', ') || $t('Unassigned') }}</span>
                    </div>
                    <div class="tm-cal-task-due"><icons name="clock" class="w-2.5 h-2.5" />{{ formatDate(t.due_date) }}<template v-if="t.due_time"> {{ formatTime(t.due_time) }}</template></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="tm-side-card">
          <div class="tm-side-header">
            <icons name="refresh" class="w-4 h-4" />
            {{ $t('Recent Activity') }}
          </div>
          <div class="tm-activity-feed">
            <div
              v-for="(entry, idx) in recentActivity"
              :key="idx"
              class="tm-activity-feed-row"
              role="button"
              tabindex="0"
              @click="openDetailById(entry.task_id)"
              @keydown.enter="openDetailById(entry.task_id)"
              @keydown.space.prevent="openDetailById(entry.task_id)"
            >
              <span class="tm-activity-feed-icon" :class="`tm-activity-feed-icon-${activityIconClass(entry.description)}`">
                <icons :name="activityIcon(entry.description)" class="w-3.5 h-3.5" />
              </span>
              <div class="min-w-0 flex-1 text-left">
                <div class="tm-activity-title">{{ entry.description }}</div>
                <div class="tm-activity-meta">
                  <span class="tm-activity-meta-text">{{ entry.causer }}<template v-if="entry.actingAs"> {{ $t('(as {name})', { name: entry.actingAs }) }}</template> · {{ formatDateTime(entry.created_at) }}</span>
                  <span v-if="entry.assignees && entry.assignees.length" class="tm-activity-assignees">
                    <span v-for="(name, i) in entry.assignees.slice(0, 3)" :key="i" class="tm-mini-avatar">{{ initials(name) }}</span>
                  </span>
                </div>
              </div>
            </div>
            <p v-if="!recentActivity.length" class="text-xs text-gray-400 text-center py-4">{{ $t('No recent activity yet.') }}</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Add Task modal — Team vs Personal, distinct styling, same backend -->
    <modal :show="showAddModal" :max-width="'2xl'" :transparent="true" @close="showAddModal = false">
      <div class="tm-modal-panel lg-page" :class="mine ? 'tm-modal-panel-personal' : 'tm-modal-panel-team'">
        <div class="tm-modal-header">
          <span class="lg-card-ico" :class="mine ? 'lg-ico-amber' : 'lg-ico-indigo'">
            <icons :name="mine ? 'user' : 'users'" class="w-5 h-5" />
          </span>
          <div class="min-w-0 flex-1">
            <div class="lg-card-title">{{ mine ? $t('New Personal Task') : $t('New Team Task') }}</div>
            <div class="lg-card-sub">{{ mine ? $t("It's just for you, no assignment needed.") : $t('Create a task and assign it to your team.') }}</div>
          </div>
          <button type="button" class="tm-modal-close-btn" :aria-label="$t('Close')" @click="showAddModal = false">
            <icons name="cross" class="w-4 h-4" />
          </button>
        </div>
        <div class="tm-modal-body">
          <div class="lg-fields">
            <div class="tm-field-container lg-span-all">
              <span class="tm-field-label"><icons name="edit" class="w-3.5 h-3.5" />{{ $t('Title') }}</span>
              <text-input v-model="addForm.title" :placeholder="$t('What needs to be done?')" :error="addForm.errors.title" class="tm-field-bare" />
            </div>
            <div class="tm-field-container lg-span-all">
              <span class="tm-field-label"><icons name="doc-text" class="w-3.5 h-3.5" />{{ $t('Description') }}</span>
              <textarea-input v-model="addForm.description" rows="2" :error="addForm.errors.description" class="tm-field-bare tm-textarea-wide" />
            </div>
            <div class="tm-field-container">
              <span class="tm-field-label"><icons name="exclamation" class="w-3.5 h-3.5" />{{ $t('Priority') }}</span>
              <div class="flex flex-wrap gap-2">
                <button v-for="p in priorities" :key="p.value" type="button" class="tm-priority-chip" :class="[`tm-priority-chip-${p.value}`, { 'tm-priority-chip-active': addForm.priority === p.value }]" @click="addForm.priority = p.value">
                  <icons :name="p.icon" class="w-3 h-3" />
                  {{ p.label }}
                </button>
              </div>
            </div>
            <div class="tm-field-container">
              <span class="tm-field-label"><icons name="folder" class="w-3.5 h-3.5" />{{ $t('Category') }}</span>
              <creatable-select v-model="addForm.category" :options="taskCategories" :placeholder="$t('Search or type...')" :error="addForm.errors.category" />
            </div>
            <div class="tm-sched-grid lg-span-all">
              <div class="tm-field-container">
                <span class="tm-field-label"><icons name="calendar" class="w-3.5 h-3.5" />{{ $t('Start Date') }}</span>
                <div class="tm-dt">
                  <date-picker v-model="addForm.start_date" class="tm-dt-date" />
                  <label class="tm-dt-time"><icons name="clock" class="w-4 h-4" /><input v-model="addForm.start_time" type="time" class="tm-time-input" :aria-label="$t('Start Time')" /></label>
                </div>
              </div>
              <div class="tm-field-container">
                <span class="tm-field-label"><icons name="clock" class="w-3.5 h-3.5" />{{ $t('Due Date') }}</span>
                <div class="tm-dt">
                  <date-picker v-model="addForm.due_date" class="tm-dt-date" />
                  <label class="tm-dt-time"><icons name="clock" class="w-4 h-4" /><input v-model="addForm.due_time" type="time" class="tm-time-input" :aria-label="$t('Due Time')" /></label>
                </div>
                <tec-input-error v-if="addForm.errors.due_date" :message="addForm.errors.due_date" class="mt-1" />
                <tec-input-error v-if="addForm.errors.due_time" :message="addForm.errors.due_time" class="mt-1" />
              </div>
            </div>
            <div v-if="!mine && $can('assign-task-manager')" class="tm-field-container">
              <span class="tm-field-label"><icons name="users" class="w-3.5 h-3.5" />{{ $t('Assign To') }}</span>
              <assignee-picker v-model="addAssignees" :users="assignableUsers" />
            </div>
            <div class="tm-field-container" :class="{ 'lg-span-all': !(!mine && $can('assign-task-manager')) }">
              <span class="tm-field-label"><icons name="clip" class="w-3.5 h-3.5" />{{ $t('Attachments') }}</span>
              <attachment-picker v-model="addForm.attachments" :max-files="10" :max-size-kb="10240" />
            </div>
          </div>
        </div>
        <div class="tm-modal-footer">
          <button type="button" class="lg-btn lg-btn-soft" @click="showAddModal = false">{{ $t('Cancel') }}</button>
          <loading-button type="button" class="tm-submit-btn" :class="mine ? 'tm-submit-btn-personal' : 'tm-submit-btn-team'" :loading="addForm.processing" :disabled="addForm.processing" @click="submitAdd">
            <icons name="plus" class="w-4 h-4 mr-1 -ml-0.5" />
            {{ mine ? $t('Create Personal Task') : $t('Create Team Task') }}
          </loading-button>
        </div>
      </div>
    </modal>

    <!-- Task Detail Drawer -->
    <transition name="tm-drawer-backdrop">
      <div v-if="detailTask" class="tm-drawer-backdrop" @click="closeDetail"></div>
    </transition>
    <transition name="tm-drawer-panel">
      <aside v-if="detailTask" class="tm-drawer" role="dialog" aria-modal="true" :aria-label="detailTask.title" :style="{ '--tm-drawer-accent': statusColors[detailTask.status] }">
        <header class="tm-dr-head">
          <span class="tm-dr-icon"><icons name="collection" class="w-5 h-5" /></span>
          <div class="tm-dr-headtext">
            <div class="tm-dr-title">{{ detailTask.title }}</div>
            <div class="tm-dr-meta">
              <span class="tm-dr-chip tm-dr-chip-status">{{ statusLabel(detailTask.status) }}</span>
              <span class="tm-dr-chip" :class="`tm-dr-chip-${detailTask.priority || 'normal'}`">{{ priorityLabel(detailTask.priority) }}</span>
              <span class="tm-dr-by">{{ $t('Created by') }} {{ detailTask.created_by?.name || detailTask.createdBy?.name || '-' }}</span>
            </div>
          </div>
          <button type="button" class="tm-dr-close tm-tip tm-tip-left" :data-tip="$t('Close')" :aria-label="$t('Close')" @click="closeDetail">
            <icons name="cross" class="w-4 h-4" />
          </button>
        </header>

        <nav class="tm-dr-tabs" role="tablist">
          <button
            v-for="tab in drawerTabs"
            :key="tab.key"
            type="button"
            role="tab"
            :aria-selected="activeTab === tab.key"
            class="tm-dr-tab tm-tip tm-tip-down"
            :class="{ 'tm-dr-tab-on': activeTab === tab.key }"
            :data-tip="tabTip(tab)"
            @click="activeTab = tab.key"
          >
            <icons :name="tab.icon" class="w-4 h-4" />
            <span class="tm-dr-tab-label">{{ tab.label }}</span>
            <span v-if="tabBadge(tab.key)" class="tm-dr-tab-badge">{{ tabBadge(tab.key) }}</span>
          </button>
        </nav>

        <div class="tm-dr-body">
          <div v-if="detailLoading" class="tm-drawer-loading">{{ $t('Loading...') }}</div>

          <!-- Overview -->
          <template v-else-if="activeTab === 'overview' && detailForm">
            <section class="tm-dr-sec">
              <h4 class="tm-dr-sec-title"><icons name="edit" class="w-3.5 h-3.5" />{{ $t('Task') }}</h4>
              <div class="tm-f">
                <span class="tm-f-label">{{ $t('Title') }}</span>
                <text-input v-model="detailForm.title" :error="detailForm.errors.title" class="tm-field-bare" :disabled="!canEditTask" />
              </div>
              <div class="tm-f">
                <span class="tm-f-label">{{ $t('Description') }}</span>
                <textarea-input v-model="detailForm.description" rows="3" :error="detailForm.errors.description" class="tm-field-bare" :disabled="!canEditTask" />
              </div>
            </section>

            <section class="tm-dr-sec">
              <h4 class="tm-dr-sec-title"><icons name="sliders" class="w-3.5 h-3.5" />{{ $t('Details') }}</h4>
              <div class="tm-f">
                <span class="tm-f-label">{{ $t('Priority') }}</span>
                <div class="tm-prio-row">
                  <button
                    v-for="p in priorities"
                    :key="p.value"
                    type="button"
                    class="tm-priority-chip tm-tip tm-tip-up"
                    :class="[`tm-priority-chip-${p.value}`, { 'tm-priority-chip-active': detailForm.priority === p.value }]"
                    :data-tip="priorityTip(p.value)"
                    :disabled="!canEditTask"
                    @click="detailForm.priority = p.value"
                  >
                    <icons :name="p.icon" class="w-3 h-3" />
                    {{ p.label }}
                  </button>
                </div>
              </div>
              <div class="tm-f">
                <span class="tm-f-label">{{ $t('Category') }}</span>
                <creatable-select v-model="detailForm.category" :options="taskCategories" />
              </div>
            </section>

            <section class="tm-dr-sec">
              <h4 class="tm-dr-sec-title"><icons name="calendar" class="w-3.5 h-3.5" />{{ $t('Schedule') }}</h4>
              <div class="tm-f">
                <span class="tm-f-label">{{ $t('Start') }}</span>
                <div class="tm-dt">
                  <date-picker v-model="detailForm.start_date" class="tm-dt-date" />
                  <label class="tm-dt-time tm-tip tm-tip-up" :data-tip="$t('Start time (optional)')">
                    <icons name="clock" class="w-4 h-4" />
                    <input v-model="detailForm.start_time" type="time" class="tm-time-input" :aria-label="$t('Start Time')" :disabled="!canEditTask" />
                  </label>
                </div>
              </div>
              <div class="tm-f">
                <span class="tm-f-label">{{ $t('Due') }}</span>
                <div class="tm-dt">
                  <date-picker v-model="detailForm.due_date" class="tm-dt-date" />
                  <label class="tm-dt-time tm-tip tm-tip-up" :data-tip="$t('Due time (optional), reminders are timed from this')">
                    <icons name="clock" class="w-4 h-4" />
                    <input v-model="detailForm.due_time" type="time" class="tm-time-input" :aria-label="$t('Due Time')" :disabled="!canEditTask" />
                  </label>
                </div>
              </div>
            </section>
          </template>

          <!-- Assignment -->
          <template v-else-if="activeTab === 'assignment'">
            <section class="tm-dr-sec" v-if="$can('assign-task-manager')">
              <h4 class="tm-dr-sec-title"><icons name="users" class="w-3.5 h-3.5" />{{ $t('Assigned To') }}</h4>
              <assignee-picker v-model="assigneesDraft" :users="assignableUsers" />
              <loading-button type="button" class="tm-submit-btn tm-submit-btn-team w-fit mt-3" :loading="assigning" @click="submitAssignees">
                <icons name="tick" class="w-3.5 h-3.5 mr-1" />{{ $t('Save Assignment') }}
              </loading-button>
            </section>
            <section v-else class="tm-dr-sec">
              <h4 class="tm-dr-sec-title"><icons name="users" class="w-3.5 h-3.5" />{{ $t('Assigned To') }}</h4>
              <div class="tm-assignee-readonly">
                <div v-for="a in (detailData?.assignees || [])" :key="a.id" class="tm-assignee-row">
                  <span class="tm-avatar tm-avatar-lg">{{ initials(a.assignee?.name) }}</span>
                  {{ a.assignee?.name }}
                </div>
                <p v-if="!(detailData?.assignees || []).length" class="tm-empty">{{ $t('Nobody is assigned yet.') }}</p>
              </div>
            </section>
          </template>

          <!-- Checklist -->
          <template v-else-if="activeTab === 'checklist'">
            <section class="tm-dr-sec">
              <h4 class="tm-dr-sec-title"><icons name="tick" class="w-3.5 h-3.5" />{{ $t('Checklist') }}
                <span v-if="checklistTotal" class="tm-dr-sec-count">{{ checklistDone }}/{{ checklistTotal }}</span>
              </h4>
              <div v-if="checklistTotal" class="tm-progress" role="progressbar" :aria-valuenow="checklistPercent" aria-valuemin="0" aria-valuemax="100">
                <span class="tm-progress-fill" :style="{ width: checklistPercent + '%' }"></span>
              </div>
              <div class="tm-checklist-list">
                <div v-for="item in (detailData?.checklist_items || [])" :key="item.id" class="tm-checklist-row">
                  <button type="button" class="tm-checklist-check tm-tip tm-tip-up" :data-tip="item.is_done ? $t('Mark as not done') : $t('Mark as done')" :class="{ 'tm-checklist-check-done': item.is_done }" @click="toggleChecklistItem(item)">
                    <icons name="tick" class="w-3 h-3" />
                  </button>
                  <span class="flex-1 min-w-0" :class="{ 'line-through text-gray-400': item.is_done }" v-html="renderChecklistText(item.title)"></span>
                  <button type="button" class="tm-checklist-remove tm-tip tm-tip-left" :data-tip="$t('Remove item')" @click="removeChecklistItem(item)">
                    <icons name="trash" class="w-3.5 h-3.5" />
                  </button>
                </div>
                <p v-if="!checklistTotal" class="tm-empty">{{ $t('No checklist items yet.') }}</p>
              </div>
              <form class="flex flex-col gap-1 mt-3 relative" @submit.prevent="addChecklistItem">
                <div class="flex items-center gap-2">
                  <input
                    ref="checklistInput"
                    v-model="newChecklistTitle"
                    type="text"
                    class="tm-search-input flex-1 min-w-0 px-3 py-2 text-sm rounded-lg"
                    :placeholder="$t('Add checklist item...')"
                    @input="onChecklistInput"
                    @keyup="detectMention"
                    @click="detectMention"
                    @keydown="onChecklistKeydown"
                    @blur="scheduleCloseMention"
                  />
                  <button type="submit" class="tm-assign-btn">{{ $t('Add') }}</button>
                </div>
                <span v-if="checklistError" class="text-xs text-red-500">{{ checklistError }}</span>
                <span class="tm-checklist-hint">{{ $t('Tip: type @ to link a record, e.g. @materialrequest (RMR-0086).') }}</span>

                <Teleport to="body">
                  <div v-if="mentionOpen" class="tm-mention-dropdown" :style="mentionDropdownStyle">
                    <div class="tm-mention-dropdown-label">
                      {{ mentionStage === 'module' ? $t('Link a record: choose a module') : $t('Search') + ' ' + mentionModuleLabel }}
                    </div>
                    <button
                      v-for="(opt, idx) in mentionResults"
                      :key="mentionStage === 'module' ? opt.key : opt.code"
                      type="button"
                      class="tm-mention-option"
                      :class="{ 'tm-mention-option-active': idx === mentionHighlight }"
                      @mousedown.prevent="selectMentionOption(opt)"
                      @mouseenter="mentionHighlight = idx"
                    >
                      <icons v-if="mentionStage === 'module'" :name="opt.icon" class="w-3.5 h-3.5 tm-mention-option-icon" />
                      <span v-if="mentionStage === 'module'" class="font-semibold">{{ opt.label }}</span>
                      <template v-else>
                        <span class="font-semibold">{{ opt.code }}</span>
                        <span v-if="opt.title" class="tm-mention-option-title">{{ opt.title }}</span>
                      </template>
                    </button>
                    <div v-if="mentionStage === 'record' && mentionLoading" class="tm-mention-empty">{{ $t('Searching...') }}</div>
                    <div v-else-if="!mentionResults.length" class="tm-mention-empty">{{ $t('No matches.') }}</div>
                  </div>
                </Teleport>
              </form>
            </section>
          </template>

          <!-- Comments -->
          <template v-else-if="activeTab === 'comments'">
            <section class="tm-dr-sec">
              <h4 class="tm-dr-sec-title"><icons name="sms" class="w-3.5 h-3.5" />{{ $t('Comments') }}<span v-if="(detailData?.comments || []).length" class="tm-dr-sec-count">{{ detailData.comments.length }}</span></h4>
              <div v-for="c in (detailData?.comments || [])" :key="c.id" class="tm-comment-row">
                <span class="tm-avatar tm-avatar-lg">{{ initials(c.user?.name) }}</span>
                <div class="tm-comment-bubble">
                  <div class="flex items-center justify-between gap-2">
                    <span class="font-semibold text-sm text-gray-800">{{ c.user?.name }}</span>
                    <span class="text-xs text-gray-400">{{ formatDateTime(c.created_at) }}</span>
                  </div>
                  <p class="text-sm text-gray-600 mt-1 tm-wrap">{{ c.body }}</p>
                </div>
              </div>
              <p v-if="!(detailData?.comments || []).length" class="tm-empty">{{ $t('No comments yet.') }}</p>
              <form class="flex flex-col gap-2 mt-3" @submit.prevent="submitComment">
                <textarea-input v-model="newCommentBody" rows="2" :placeholder="$t('Write a comment...')" class="tm-field-bare" />
                <loading-button type="submit" class="tm-submit-btn tm-submit-btn-team w-fit" :loading="commenting">{{ $t('Comment') }}</loading-button>
              </form>
            </section>
          </template>

          <!-- Attachments -->
          <template v-else-if="activeTab === 'attachments'">
            <section class="tm-dr-sec">
              <h4 class="tm-dr-sec-title"><icons name="clip" class="w-3.5 h-3.5" />{{ $t('Attachments') }}<span v-if="(detailData?.attachments || []).length" class="tm-dr-sec-count">{{ detailData.attachments.length }}</span></h4>
              <div v-for="att in (detailData?.attachments || [])" :key="att.id" class="tm-attachment-row">
                <span class="tm-attachment-icon"><icons name="clip" class="w-4 h-4" /></span>
                <a :href="route('task-manager.attachments.download', { task: detailTask.id, attachment: att.id })" class="flex-1 min-w-0 truncate">{{ att.title || att.filename }}</a>
                <button v-if="$can('attach-task-manager')" type="button" class="tm-checklist-remove tm-tip tm-tip-left" :data-tip="$t('Remove attachment')" @click="removeAttachment(att)">
                  <icons name="trash" class="w-3.5 h-3.5" />
                </button>
              </div>
              <p v-if="!(detailData?.attachments || []).length" class="tm-empty">{{ $t('No attachments yet.') }}</p>
              <attachment-picker v-if="$can('attach-task-manager')" v-model="newAttachments" :max-files="10" :max-size-kb="10240" class="mt-3" />
              <loading-button v-if="$can('attach-task-manager') && newAttachments.length" type="button" class="tm-submit-btn tm-submit-btn-team w-fit mt-2" :loading="uploadingAttachments" @click="submitAttachments">
                {{ $t('Upload') }}
              </loading-button>
            </section>
          </template>

          <!-- Activity -->
          <template v-else-if="activeTab === 'activity'">
            <section class="tm-dr-sec">
              <h4 class="tm-dr-sec-title"><icons name="refresh" class="w-3.5 h-3.5" />{{ $t('Activity') }}</h4>
              <div class="tm-timeline">
                <div v-for="(entry, idx) in visibleActivity" :key="idx" class="tm-activity-row">
                  <span class="tm-activity-row-icon" :class="`tm-activity-feed-icon-${activityIconClass(entry.description)}`">
                    <icons :name="activityIcon(entry.description)" class="w-3.5 h-3.5" />
                  </span>
                  <div class="min-w-0 flex-1">
                    <div class="font-semibold text-gray-800 text-sm tm-wrap">{{ entry.description }}</div>
                    <div class="text-xs text-gray-500 mt-0.5">
                      {{ $t('By') }} {{ entry.causer }}
                      <span v-if="entry.actingAs" class="tm-activity-acting-as">{{ $t('(as {name})', { name: entry.actingAs }) }}</span>
                      &middot; {{ formatDateTime(entry.created_at) }}
                    </div>
                  </div>
                </div>
              </div>
              <p v-if="!(detailData?.activity || []).length" class="tm-empty">{{ $t('No activity yet.') }}</p>
              <button v-if="(detailData?.activity || []).length > 5" type="button" class="tm-column-showall" @click="activityExpanded = !activityExpanded">
                <icons :name="activityExpanded ? 'up' : 'down'" class="w-3.5 h-3.5" />
                {{ activityExpanded ? $t('Collapse') : $t('Show All ({n})', { n: detailData.activity.length }) }}
              </button>
            </section>
          </template>
        </div>

        <!-- Actions stay pinned to the bottom of the drawer while the form scrolls -->
        <footer v-if="activeTab === 'overview' && detailForm && !detailLoading" class="tm-dr-foot">
          <button v-if="canDeleteTask(detailTask)" type="button" class="tm-delete-btn tm-tip tm-tip-up" :data-tip="$t('Permanently remove this task')" @click="askDelete(detailTask)">
            <icons name="trash" class="w-3.5 h-3.5" />
            {{ $t('Delete Task') }}
          </button>
          <div class="tm-dr-foot-right">
            <button v-if="detailTask.status !== 'done' && $can('mark-complete-task-manager')" type="button" class="tm-complete-inline-btn tm-tip tm-tip-up" :data-tip="$t('Move this task to Completed')" @click="markComplete(detailTask, detailTask.status)">
              <icons name="tick" class="w-3.5 h-3.5" />
              {{ $t('Mark Complete') }}
            </button>
            <loading-button v-if="canEditTask" type="button" class="tm-submit-btn tm-submit-btn-team" :loading="detailForm.processing" @click="submitDetail">
              <icons name="tick" class="w-3.5 h-3.5 mr-1 -ml-0.5" />
              {{ $t('Save') }}
            </loading-button>
          </div>
        </footer>
      </aside>
    </transition>
  </admin-layout>
</template>

<script>
import debounce from 'lodash/debounce';
import Modal from '@/Jetstream/Modal.vue';
import TextInput from '@/Shared/TextInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import TecInputError from '@/Jetstream/InputError.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import AssigneePicker from '@/Shared/AssigneePicker.vue';
import AttachmentPicker from '@/Shared/AttachmentPicker.vue';
import CreatableSelect from '@/Shared/CreatableSelect.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import SimpleAutocomplete from '@/Shared/SimpleAutocomplete.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';

const STATUS_COLORS = { todo: '#94a3b8', in_progress: '#2563eb', review: '#d97706', done: '#059669' };

export default {
  props: {
    tasks: { type: Array, default: () => [] },
    assignableUsers: { type: Array, default: () => [] },
    taskCategories: { type: Array, default: () => [] },
    team: { type: Array, default: () => [] },
    recentActivity: { type: Array, default: () => [] },
    taskCreators: { type: Array, default: () => [] },
    referenceModules: { type: Array, default: () => [] },
    referenceModuleList: { type: Array, default: () => [] },
    canUseGlobalCreatorFilter: { type: Boolean, default: false },
    currentUser: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    mine: { type: Boolean, default: false },
  },

  components: {
    Modal,
    TextInput,
    AdminLayout,
    LoadingButton,
    TecInputError,
    TextareaInput,
    AssigneePicker,
    AttachmentPicker,
    CreatableSelect,
    DatePicker,
    SimpleAutocomplete,
    PageHeader,
    StatTiles,
  },

  data() {
    const now = new Date();
    return {
      sidebarVisible: false,
      collapsedColumns: { todo: false, in_progress: false, review: false, done: false },
      expandedColumns: { todo: false, in_progress: false, review: false, done: false },
      columnPageSize: 8,
      searchTerm: this.filters.search || '',
      priority: this.filters.priority || '',
      assignedToUserId: this.filters.assigned_to_user_id || '',
      category: this.filters.category || '',
      creatorFilter: this.filters.created_by_user_id || 'all',
      statusColors: STATUS_COLORS,
      calendarViewYear: now.getFullYear(),
      calendarViewMonth: now.getMonth(),
      calendarDateFilter: null,
      completingId: null,
      checklistError: '',
      activityExpanded: false,
      mentionOpen: false,
      mentionStage: 'module',
      mentionModule: null,
      mentionQuery: '',
      mentionStartPos: 0,
      mentionAnchorPos: 0,
      mentionResults: [],
      mentionHighlight: 0,
      mentionLoading: false,
      mentionDropdownStyle: {},
      mentionSearchSeq: 0,
      teamTooltipMember: null,
      teamTooltipStyle: {},
      columnDefs: [
        { key: 'todo', label: this.$t('To Do') },
        { key: 'in_progress', label: this.$t('In Progress') },
        { key: 'review', label: this.$t('Review') },
        { key: 'done', label: this.$t('Completed') },
      ],
      priorities: [
        { value: 'low', label: this.$t('Low'), icon: 'down' },
        { value: 'normal', label: this.$t('Normal'), icon: 'toggle' },
        { value: 'high', label: this.$t('High'), icon: 'up' },
        { value: 'urgent', label: this.$t('Urgent'), icon: 'exclamation' },
      ],
      statDefs: [
        { key: 'total', label: this.$t('Total'), icon: 'chart-bar', tooltip: n => this.$t('{n} tasks in total', { n }) },
        { key: 'todo', label: this.$t('To Do'), icon: 'collection', columnKey: 'todo', tooltip: n => this.$t('{n} tasks to do', { n }) },
        { key: 'inProgress', label: this.$t('In Progress'), icon: 'refresh', columnKey: 'in_progress', tooltip: n => this.$t('{n} tasks currently in progress', { n }) },
        { key: 'review', label: this.$t('Review'), icon: 'eye', columnKey: 'review', tooltip: n => this.$t('{n} tasks in review', { n }) },
        { key: 'completed', label: this.$t('Completed'), icon: 'tick', columnKey: 'done', tooltip: n => this.$t('{n} tasks completed', { n }) },
      ],
      animatedStats: { total: 0, todo: 0, inProgress: 0, review: 0, completed: 0 },
      animatedColumnCounts: { todo: 0, in_progress: 0, review: 0, done: 0 },
      columnsOrder: { todo: [], in_progress: [], review: [], done: [] },
      draggedId: null,
      draggedFrom: null,
      dragOverColumn: null,
      dragOverCard: null,

      showAddModal: false,
      addForm: this.$inertia.form({ title: '', description: '', priority: 'normal', category: '', start_date: '', start_time: '', due_date: '', due_time: '', attachments: [] }),
      addAssignees: [],

      detailTask: null,
      detailData: null,
      detailLoading: false,
      detailForm: null,
      activeTab: 'overview',
      drawerTabs: [
        { key: 'overview', label: this.$t('Overview'), icon: 'doc-text' },
        { key: 'assignment', label: this.$t('Assignment'), icon: 'users' },
        { key: 'checklist', label: this.$t('Checklist'), icon: 'tick' },
        { key: 'comments', label: this.$t('Comments'), icon: 'sms' },
        { key: 'attachments', label: this.$t('Attachments'), icon: 'clip' },
        { key: 'activity', label: this.$t('Activity'), icon: 'refresh' },
      ],
      assigneesDraft: [],
      assigning: false,
      newChecklistTitle: '',
      newCommentBody: '',
      commenting: false,
      newAttachments: [],
      uploadingAttachments: false,
    };
  },

  computed: {
    canEditTask() {
      if (!this.detailTask) return false;
      return this.$can('update-any-task-manager') || this.$can('update-task-manager');
    },
    canChangeStatus() {
      return this.$can('change-status-task-manager');
    },
    canMarkComplete() {
      return this.$can('mark-complete-task-manager');
    },
    tasksById() {
      const map = {};
      this.tasks.forEach(t => {
        map[t.id] = t;
      });
      return map;
    },
    columns() {
      const byDate = t => !this.calendarDateFilter || (t.due_date && String(t.due_date).slice(0, 10) === this.calendarDateFilter);
      return {
        todo: this.columnsOrder.todo.map(id => this.tasksById[id]).filter(Boolean).filter(byDate),
        in_progress: this.columnsOrder.in_progress.map(id => this.tasksById[id]).filter(Boolean).filter(byDate),
        review: this.columnsOrder.review.map(id => this.tasksById[id]).filter(Boolean).filter(byDate),
        done: this.columnsOrder.done.map(id => this.tasksById[id]).filter(Boolean).filter(byDate),
      };
    },
    visibleColumnTasks() {
      const result = {};
      for (const key of Object.keys(this.columns)) {
        const list = this.columns[key];
        result[key] = this.expandedColumns[key] ? list : list.slice(0, this.columnPageSize);
      }
      return result;
    },
    stats() {
      return {
        total: this.tasks.length,
        todo: this.tasks.filter(t => t.status === 'todo').length,
        inProgress: this.tasks.filter(t => t.status === 'in_progress').length,
        review: this.tasks.filter(t => t.status === 'review').length,
        completed: this.tasks.filter(t => t.status === 'done').length,
      };
    },
    statTiles() {
      const tones = { total: 'indigo', todo: 'slate', inProgress: 'sky', review: 'amber', completed: 'emerald' };
      return this.statDefs.map(s => {
        const tile = { key: s.key, columnKey: s.columnKey, icon: s.icon, tone: tones[s.key] || 'navy', label: s.label, value: this.animatedStats[s.key] };
        if (s.columnKey) tile.active = false;
        return tile;
      });
    },
    columnCounts() {
      return {
        todo: this.columns.todo.length,
        in_progress: this.columns.in_progress.length,
        review: this.columns.review.length,
        done: this.columns.done.length,
      };
    },
    priorityFilterOptions() {
      return this.priorities.map(p => ({ value: p.value, label: p.label, icon: p.icon }));
    },
    userFilterOptions() {
      return this.assignableUsers.map(u => ({ value: u.id, label: u.name, icon: 'user' }));
    },
    categoryFilterOptions() {
      return this.taskCategories.map(c => ({ value: c, label: c, icon: 'folder' }));
    },
    creatorFilterOptions() {
      const mineId = String(this.currentUser?.id || '');
      const others = this.taskCreators.filter(u => String(u.id) !== mineId).map(u => ({ value: String(u.id), label: u.name, icon: 'user' }));
      return [
        { value: 'all', label: this.$t('All'), icon: 'collection' },
        { value: mineId, label: this.$t('Mine'), icon: 'user' },
        ...others,
      ];
    },
    calendarLabel() {
      return new Date(this.calendarViewYear, this.calendarViewMonth, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
    },
    mentionModuleLabel() {
      return this.referenceModuleList.find(m => m.key === this.mentionModule)?.label || this.mentionModule;
    },
    checklistTotal() {
      return (this.detailData?.checklist_items || []).length;
    },
    checklistDone() {
      return (this.detailData?.checklist_items || []).filter(i => i.is_done).length;
    },
    checklistPercent() {
      return this.checklistTotal ? Math.round((this.checklistDone / this.checklistTotal) * 100) : 0;
    },
    visibleActivity() {
      const list = this.detailData?.activity || [];
      return this.activityExpanded ? list : list.slice(0, 5);
    },
    calendarDays() {
      const year = this.calendarViewYear;
      const month = this.calendarViewMonth;
      const today = new Date();
      const firstWeekday = new Date(year, month, 1).getDay();
      const daysInMonth = new Date(year, month + 1, 0).getDate();
      const days = [];
      for (let i = 0; i < firstWeekday; i++) days.push(null);
      for (let d = 1; d <= daysInMonth; d++) {
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const dueTasks = this.tasks.filter(t => t.status !== 'done' && t.due_date && String(t.due_date).slice(0, 10) === dateStr);
        days.push({ day: d, dateStr, tasks: dueTasks, isToday: d === today.getDate() && month === today.getMonth() && year === today.getFullYear() });
      }
      return days;
    },
  },

  watch: {
    tasks() {
      this.syncColumnsFromTasks();
      this.animateStats();
      this.animateColumnCounts();
    },
  },

  mounted() {
    this.syncColumnsFromTasks();
    this.animateStats();
    this.animateColumnCounts();

    const params = new URLSearchParams(window.location.search);
    const taskId = params.get('task');
    if (taskId && this.tasksById[taskId]) {
      this.openDetail(this.tasksById[taskId]);
    }

    window.addEventListener('keydown', this.onKeydown);
  },

  beforeUnmount() {
    window.removeEventListener('keydown', this.onKeydown);
  },

  methods: {
    onKeydown(e) {
      if (e.key !== 'Escape') return;
      if (this.detailTask) {
        this.closeDetail();
      } else if (this.sidebarVisible) {
        this.sidebarVisible = false;
      }
    },
    syncColumnsFromTasks() {
      const buckets = { todo: [], in_progress: [], review: [], done: [] };
      this.tasks.forEach(t => {
        (buckets[t.status] || buckets.todo).push(t.id);
      });
      this.columnsOrder = buckets;
    },
    animateStats() {
      const targets = this.stats;
      const start = performance.now();
      const startValues = { ...this.animatedStats };
      const duration = 800;
      const step = now => {
        const t = Math.min(1, (now - start) / duration);
        const ease = 1 - Math.pow(1 - t, 3);
        for (const key in targets) {
          this.animatedStats[key] = Math.round(startValues[key] + (targets[key] - startValues[key]) * ease);
        }
        if (t < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    },
    animateColumnCounts() {
      const targets = this.columnCounts;
      const start = performance.now();
      const startValues = { ...this.animatedColumnCounts };
      const duration = 700;
      const step = now => {
        const t = Math.min(1, (now - start) / duration);
        const ease = 1 - Math.pow(1 - t, 3);
        for (const key in targets) {
          this.animatedColumnCounts[key] = Math.round(startValues[key] + (targets[key] - startValues[key]) * ease);
        }
        if (t < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    },
    toggleColumn(key) {
      this.collapsedColumns[key] = !this.collapsedColumns[key];
    },
    scrollToColumn(columnKey) {
      if (window.innerWidth >= 1024) return;
      document.querySelector(`[data-column-key="${columnKey}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    },
    toggleSidebar() {
      this.sidebarVisible = !this.sidebarVisible;
      // Hover tooltips mean nothing on a touch screen, so on narrow
      // viewports (below the `xl` breakpoint where the side panel drops
      // beneath the board instead of sitting beside it) a tap scrolls
      // straight to the panel instead, so opening it is actually visible.
      if (this.sidebarVisible && window.innerWidth < 1280) {
        this.$nextTick(() => {
          this.$refs.sidebarPanel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      }
    },
    priorityLabel(value) {
      return this.priorities.find(p => p.value === value)?.label || value;
    },
    priorityIcon(value) {
      return this.priorities.find(p => p.value === value)?.icon || 'toggle';
    },
    isOverdue(task) {
      return task.due_date && task.status !== 'done' && new Date(task.due_date) < new Date(new Date().toDateString());
    },
    isDueToday(task) {
      if (!task.due_date || task.status === 'done') return false;
      const d = new Date();
      const today = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
      return String(task.due_date).slice(0, 10) === today;
    },
    formatDate(date) {
      return new Date(date).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    },
    // `time` arrives as "HH:MM" or "HH:MM:SS" (a plain string, not a date) —
    // parsed against an arbitrary fixed date purely so toLocaleTimeString
    // can format it, the date part itself is discarded.
    formatTime(time) {
      if (!time) return '';
      const [h, m] = time.split(':').map(Number);
      return new Date(2000, 0, 1, h, m).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    },
    formatDateStr(dateStr) {
      if (!dateStr) return '';
      const [y, m, d] = dateStr.split('-').map(Number);
      return new Date(y, m - 1, d).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    },
    statusLabel(key) {
      return this.columnDefs.find(c => c.key === key)?.label || key;
    },
    columnIcon(key) {
      return { todo: 'collection', in_progress: 'refresh', review: 'eye', done: 'tick' }[key] || 'collection';
    },
    // Renders "@module (CODE)" mentions inside checklist item text as
    // clickable links to the real record (see TaskReferenceRegistry on the
    // backend for the whitelist). Everything else is HTML-escaped first —
    // only the recognized mention syntax ever becomes a tag.
    renderChecklistText(text) {
      const escapeHtml = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
      const re = /@(\w+)\s*\(([^)]+)\)/g;
      let out = '';
      let lastIndex = 0;
      let m;
      while ((m = re.exec(text)) !== null) {
        out += escapeHtml(text.slice(lastIndex, m.index));
        const module = m[1].toLowerCase();
        const code = m[2].trim();
        if (this.referenceModules.includes(module) && code) {
          const url = this.route('task-manager.reference', { module, code });
          out += `<a href="${escapeHtml(url)}" target="_blank" rel="noopener" class="tm-ref-link" onclick="event.stopPropagation()">@${escapeHtml(module)} (${escapeHtml(code)})</a>`;
        } else {
          out += escapeHtml(m[0]);
        }
        lastIndex = re.lastIndex;
      }
      out += escapeHtml(text.slice(lastIndex));
      return out;
    },
    statusLabel(key) {
      return this.columnDefs.find(c => c.key === key)?.label || key;
    },
    priorityLabel(key) {
      return this.priorities.find(p => p.value === (key || 'normal'))?.label || key;
    },
    priorityTip(value) {
      return {
        low: this.$t('Low: no rush, routine work'),
        normal: this.$t('Normal: standard priority'),
        high: this.$t('High: do this soon'),
        urgent: this.$t('Urgent: needs attention now'),
      }[value];
    },
    tabBadge(key) {
      if (!this.detailData) return '';
      if (key === 'checklist') return this.checklistTotal ? `${this.checklistDone}/${this.checklistTotal}` : '';
      if (key === 'comments') return (this.detailData.comments || []).length || '';
      if (key === 'attachments') return (this.detailData.attachments || []).length || '';
      if (key === 'assignment') return (this.detailData.assignees || []).length || '';
      return '';
    },
    tabTip(tab) {
      const b = this.tabBadge(tab.key);
      return b ? `${tab.label} (${b})` : tab.label;
    },
    formatDateTime(date) {
      return date ? new Date(date).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : '';
    },
    checklistPct(task) {
      return task.checklist_items_count ? Math.round((task.checklist_done_count / task.checklist_items_count) * 100) : 0;
    },
    initials(name) {
      if (!name) return '?';
      return name.split(' ').filter(Boolean).slice(0, 2).map(n => n[0].toUpperCase()).join('');
    },
    shiftMonth(delta) {
      let m = this.calendarViewMonth + delta;
      let y = this.calendarViewYear;
      if (m < 0) { m = 11; y -= 1; }
      if (m > 11) { m = 0; y += 1; }
      this.calendarViewMonth = m;
      this.calendarViewYear = y;
    },
    toggleCalendarDateFilter(dateStr) {
      this.calendarDateFilter = this.calendarDateFilter === dateStr ? null : dateStr;
    },
    activityIcon(description) {
      const d = (description || '').toLowerCase();
      if (d.includes('created.')) return 'plus';
      if (d.includes('assigned to') || d.includes('removed from')) return 'users';
      if (d.includes('moved from')) return 'refresh';
      if (d.includes('comment')) return 'sms';
      if (d.includes('checklist')) return 'tick';
      if (d.includes('attachment')) return 'clip';
      if (d.includes('deleted')) return 'trash';
      return 'collection';
    },
    activityIconClass(description) {
      const icon = this.activityIcon(description);
      return { plus: 'green', users: 'indigo', refresh: 'blue', sms: 'amber', tick: 'emerald', clip: 'violet', trash: 'rose', collection: 'slate' }[icon] || 'slate';
    },
    filterByTeamMember(member) {
      this.assignedToUserId = member.id;
      this.search();
    },
    showTeamTooltip(member, event) {
      const rect = event.currentTarget.getBoundingClientRect();
      const width = 240;
      const estHeight = 40 + Math.min(member.tasks.length, 6) * 22 + (member.tasks.length > 6 ? 20 : 0);
      const gap = 10;
      const fitsLeft = rect.left - gap - width >= 8;
      const left = fitsLeft
        ? rect.left - gap - width
        : Math.min(Math.max(rect.right - width, 8), window.innerWidth - width - 8);
      const centerY = rect.top + rect.height / 2;
      const top = Math.min(Math.max(centerY - estHeight / 2, 8), window.innerHeight - estHeight - 8);
      this.teamTooltipMember = member;
      this.teamTooltipStyle = {
        position: 'fixed',
        left: left + 'px',
        top: top + 'px',
        width: width + 'px',
      };
    },
    hideTeamTooltip() {
      this.teamTooltipMember = null;
    },
    showFlashError(message) {
      this.$page.props.flash.error = message;
    },
    openDetailById(taskId) {
      if (!taskId) return;
      const task = this.tasksById[taskId];
      if (task) {
        this.openDetail(task);
      } else {
        this.$inertia.visit(this.route('task-manager.index', { task: taskId }));
      }
    },

    search: debounce(function () {
      const target = this.mine ? 'task-manager.mine' : 'task-manager.index';
      const params = { search: this.searchTerm, priority: this.priority, assigned_to_user_id: this.assignedToUserId, category: this.category };
      if (!this.mine) {
        params.created_by_user_id = this.creatorFilter;
      }
      this.$inertia.get(
        this.route(target),
        params,
        { preserveState: true, replace: true }
      );
    }, 300),

    onCardDragStart(event, status, id) {
      if (!this.canChangeStatus) {
        event.preventDefault();
        return;
      }
      this.draggedId = id;
      this.draggedFrom = status;
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', String(id));
    },
    onColumnDragOver(status) {
      if (this.draggedId === null) return;
      this.dragOverColumn = status;
    },
    onCardDragOver(status, id) {
      if (this.draggedId === null) return;
      this.dragOverColumn = status;
      this.dragOverCard = id;
    },
    onColumnDrop(status) {
      this.performDrop(status, undefined);
    },
    onCardDrop(status, index) {
      this.performDrop(status, index);
    },
    performDrop(status, index) {
      if (this.draggedId === null) {
        this.dragOverColumn = null;
        this.dragOverCard = null;
        return;
      }

      if (status === 'done' && !this.canMarkComplete) {
        this.draggedId = null;
        this.draggedFrom = null;
        this.dragOverColumn = null;
        this.dragOverCard = null;
        this.showFlashError(this.$t("Completing tasks isn't part of your permissions. Ask an admin if you need access."));
        return;
      }

      const from = this.draggedFrom;
      const fromArr = [...this.columnsOrder[from]];
      const existingIdx = fromArr.indexOf(this.draggedId);
      if (existingIdx !== -1) fromArr.splice(existingIdx, 1);

      const toArr = from === status ? fromArr : [...this.columnsOrder[status]];
      const insertAt = index === undefined || index === null ? toArr.length : Math.min(index, toArr.length);
      toArr.splice(insertAt, 0, this.draggedId);

      this.columnsOrder = { ...this.columnsOrder, [from]: from === status ? toArr : fromArr, [status]: toArr };
      this.animateColumnCounts();

      const movedId = this.draggedId;
      this.draggedId = null;
      this.draggedFrom = null;
      this.dragOverColumn = null;
      this.dragOverCard = null;

      this.$inertia.patch(
        this.route('task-manager.move', movedId),
        { status, ordered_ids: toArr },
        { preserveScroll: true, preserveState: true, only: ['flash'] }
      );
    },
    onCardDragEnd() {
      this.draggedId = null;
      this.draggedFrom = null;
      this.dragOverColumn = null;
      this.dragOverCard = null;
    },

    markComplete(task, fromStatus) {
      this.completingId = task.id;
      const fromArr = [...this.columnsOrder[fromStatus]];
      const idx = fromArr.indexOf(task.id);
      if (idx !== -1) fromArr.splice(idx, 1);
      const toArr = [...this.columnsOrder.done, task.id];

      setTimeout(() => {
        this.columnsOrder = { ...this.columnsOrder, [fromStatus]: fromArr, done: toArr };
        this.animateColumnCounts();
        this.completingId = null;
      }, 220);

      this.$inertia.patch(
        this.route('task-manager.move', task.id),
        { status: 'done', ordered_ids: toArr },
        {
          preserveScroll: true,
          preserveState: true,
          only: ['flash'],
          onSuccess: () => {
            if (this.detailTask?.id === task.id) this.closeDetail();
          },
        }
      );
    },

    openAdd() {
      this.addForm.reset();
      this.addForm.clearErrors();
      this.addForm.priority = 'normal';
      this.addAssignees = [];

      // Default Start to right now, and Due to 3 days later at the same
      // time — the user can still change either; this just saves having to
      // set them manually for the common case.
      const now = new Date();
      const due = new Date(now);
      due.setDate(due.getDate() + 3);

      this.addForm.start_date = this.toDateInputValue(now);
      this.addForm.start_time = this.toTimeInputValue(now);
      this.addForm.due_date = this.toDateInputValue(due);
      this.addForm.due_time = this.toTimeInputValue(now);

      this.showAddModal = true;
    },
    toDateInputValue(date) {
      const y = date.getFullYear();
      const m = String(date.getMonth() + 1).padStart(2, '0');
      const d = String(date.getDate()).padStart(2, '0');
      return `${y}-${m}-${d}`;
    },
    toTimeInputValue(date) {
      const h = String(date.getHours()).padStart(2, '0');
      const m = String(date.getMinutes()).padStart(2, '0');
      return `${h}:${m}`;
    },
    submitAdd() {
      this.addForm
        .transform(data => ({ ...data, assignees: this.mine ? [] : this.addAssignees.map(a => ({ id: a.id })), personal_task: this.mine }))
        .post(this.route('task-manager.store'), {
          forceFormData: true,
          preserveScroll: true,
          onSuccess: () => {
            this.showAddModal = false;
            this.addForm.reset();
            this.addAssignees = [];
          },
        });
    },

    openDetail(task) {
      this.detailTask = task;
      this.detailData = null;
      this.activeTab = 'overview';
      this.detailLoading = true;
      this.newCommentBody = '';
      this.newChecklistTitle = '';
      this.checklistError = '';
      this.newAttachments = [];
      this.activityExpanded = false;

      const url = new URL(window.location.href);
      url.searchParams.set('task', task.id);
      window.history.replaceState({}, '', url);

      axios
        .get(this.route('task-manager.detail', task.id))
        .then(res => {
          const t = res.data.task;
          this.detailData = { ...t, activity: res.data.activity };
          this.detailForm = this.$inertia.form({
            title: t.title,
            description: t.description || '',
            priority: t.priority,
            category: t.category || '',
            start_date: t.start_date || '',
            start_time: t.start_time ? t.start_time.slice(0, 5) : '',
            due_date: t.due_date || '',
            due_time: t.due_time ? t.due_time.slice(0, 5) : '',
          });
          this.assigneesDraft = (t.assignees || []).map(a => ({
            id: a.assignee_id,
            label: a.assignee?.name || '-',
          }));
        })
        .finally(() => {
          this.detailLoading = false;
        });
    },
    closeDetail() {
      this.detailTask = null;
      this.detailData = null;
      this.detailForm = null;
      const url = new URL(window.location.href);
      url.searchParams.delete('task');
      window.history.replaceState({}, '', url);
    },
    submitDetail() {
      this.detailForm.patch(this.route('task-manager.update', this.detailTask.id), {
        preserveScroll: true,
        onSuccess: () => this.closeDetail(),
      });
    },
    submitAssignees() {
      this.assigning = true;
      this.$inertia.patch(
        this.route('task-manager.assignees.sync', this.detailTask.id),
        { assignees: this.assigneesDraft.map(a => ({ id: a.id })) },
        {
          preserveScroll: true,
          onSuccess: () => this.refreshDetail(),
          onFinish: () => (this.assigning = false),
        }
      );
    },
    refreshDetail() {
      if (!this.detailTask) return;
      axios.get(this.route('task-manager.detail', this.detailTask.id)).then(res => {
        this.detailData = { ...res.data.task, activity: res.data.activity };
      });
    },
    addChecklistItem() {
      if (!this.newChecklistTitle.trim()) {
        this.checklistError = this.$t('Please enter a checklist item.');
        return;
      }
      this.checklistError = '';
      this.$inertia.post(
        this.route('task-manager.checklist-items.store', this.detailTask.id),
        { title: this.newChecklistTitle },
        { preserveScroll: true, onSuccess: () => { this.newChecklistTitle = ''; this.refreshDetail(); } }
      );
    },
    onChecklistInput() {
      this.checklistError = '';
      this.detectMention();
    },
    // Re-evaluated on every keystroke/click/caret-move rather than tracked
    // incrementally, so it works correctly no matter where in the sentence
    // the "@" sits or how the cursor got there (typing, clicking, arrow
    // keys, paste).
    detectMention() {
      const el = this.$refs.checklistInput;
      if (!el) return;
      // Read the live DOM value/caret rather than the (possibly not yet
      // reactively-updated) v-model value, so this never races Vue's own
      // input handler.
      const value = el.value;
      const pos = el.selectionStart ?? value.length;
      const before = value.slice(0, pos);

      // Already inside an open "@module (" with no closing ")" yet —
      // search that module's records.
      const recordMatch = before.match(/@(\w+)\s*\(([^)]*)$/);
      if (recordMatch && this.referenceModules.includes(recordMatch[1].toLowerCase())) {
        this.mentionStage = 'record';
        this.mentionModule = recordMatch[1].toLowerCase();
        this.mentionQuery = recordMatch[2];
        this.mentionAnchorPos = pos - recordMatch[2].length;
        this.mentionStartPos = pos - recordMatch[0].length;
        this.mentionHighlight = 0;
        this.openMentionDropdown();
        this.searchMentionRecords();
        return;
      }

      // A bare "@word" with no space/paren typed yet — picking the module.
      const moduleMatch = before.match(/@(\w*)$/);
      if (moduleMatch) {
        this.mentionStage = 'module';
        this.mentionQuery = moduleMatch[1].toLowerCase();
        this.mentionStartPos = pos - moduleMatch[0].length;
        this.mentionAnchorPos = pos;
        this.mentionHighlight = 0;
        this.mentionResults = this.referenceModuleList.filter(
          m => m.key.includes(this.mentionQuery) || m.label.toLowerCase().includes(this.mentionQuery)
        );
        this.openMentionDropdown();
        return;
      }

      this.closeMention();
    },
    openMentionDropdown() {
      this.mentionOpen = true;
      this.$nextTick(() => {
        const el = this.$refs.checklistInput;
        if (!el) return;
        const rect = el.getBoundingClientRect();
        this.mentionDropdownStyle = { position: 'fixed', left: rect.left + 'px', top: rect.bottom + 6 + 'px', width: Math.max(rect.width, 240) + 'px', zIndex: 9999 };
      });
    },
    closeMention() {
      this.mentionOpen = false;
      this.mentionStage = 'module';
      this.mentionModule = null;
      this.mentionResults = [];
    },
    scheduleCloseMention() {
      // Delayed so a click on a dropdown option (which blurs the input
      // first) still registers before the list disappears.
      setTimeout(() => this.closeMention(), 150);
    },
    searchMentionRecords: debounce(function () {
      const module = this.mentionModule;
      const term = this.mentionQuery;
      const seq = ++this.mentionSearchSeq;
      this.mentionLoading = true;
      axios.get(this.route('task-manager.references.search'), { params: { module, q: term } })
        .then(res => {
          if (seq !== this.mentionSearchSeq || this.mentionModule !== module) return; // stale response
          this.mentionResults = res.data.results || [];
        })
        .finally(() => {
          if (seq === this.mentionSearchSeq) this.mentionLoading = false;
        });
    }, 250),
    onChecklistKeydown(e) {
      if (!this.mentionOpen) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        this.mentionHighlight = Math.min(this.mentionHighlight + 1, this.mentionResults.length - 1);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        this.mentionHighlight = Math.max(this.mentionHighlight - 1, 0);
      } else if (e.key === 'Enter') {
        if (this.mentionResults[this.mentionHighlight]) {
          e.preventDefault();
          this.selectMentionOption(this.mentionResults[this.mentionHighlight]);
        }
      } else if (e.key === 'Escape') {
        this.closeMention();
      }
    },
    selectMentionOption(opt) {
      const text = this.newChecklistTitle;
      const el = this.$refs.checklistInput;
      if (this.mentionStage === 'module') {
        const insertion = `@${opt.key} (`;
        this.newChecklistTitle = text.slice(0, this.mentionStartPos) + insertion + text.slice(this.mentionAnchorPos);
        const newCursor = this.mentionStartPos + insertion.length;
        this.$nextTick(() => {
          el?.focus();
          el?.setSelectionRange(newCursor, newCursor);
          this.detectMention();
        });
      } else {
        const cursor = this.mentionAnchorPos + this.mentionQuery.length;
        const rest = text.slice(cursor);
        const insertion = `${opt.code})` + (rest.startsWith(' ') || rest === '' ? '' : ' ');
        this.newChecklistTitle = text.slice(0, this.mentionAnchorPos) + insertion + rest;
        const newCursor = this.mentionAnchorPos + insertion.length;
        this.$nextTick(() => {
          el?.focus();
          el?.setSelectionRange(newCursor, newCursor);
          this.closeMention();
        });
      }
    },
    toggleChecklistItem(item) {
      this.$inertia.patch(
        this.route('task-manager.checklist-items.update', { task: this.detailTask.id, checklist_item: item.id }),
        { is_done: !item.is_done },
        { preserveScroll: true, onSuccess: () => this.refreshDetail() }
      );
    },
    removeChecklistItem(item) {
      this.$inertia.delete(this.route('task-manager.checklist-items.destroy', { task: this.detailTask.id, checklist_item: item.id }), {
        preserveScroll: true,
        onSuccess: () => this.refreshDetail(),
      });
    },
    submitComment() {
      if (!this.newCommentBody.trim()) return;
      this.commenting = true;
      this.$inertia.post(
        this.route('task-manager.comments.store', this.detailTask.id),
        { body: this.newCommentBody },
        {
          preserveScroll: true,
          onSuccess: () => { this.newCommentBody = ''; this.refreshDetail(); },
          onFinish: () => (this.commenting = false),
        }
      );
    },
    submitAttachments() {
      this.uploadingAttachments = true;
      this.$inertia.post(
        this.route('task-manager.update', this.detailTask.id),
        {
          _method: 'put',
          title: this.detailData.title,
          description: this.detailData.description,
          priority: this.detailData.priority,
          category: this.detailData.category,
          start_date: this.detailData.start_date,
          due_date: this.detailData.due_date,
          attachments: this.newAttachments,
        },
        {
          forceFormData: true,
          preserveScroll: true,
          onSuccess: () => { this.newAttachments = []; this.refreshDetail(); },
          onFinish: () => (this.uploadingAttachments = false),
        }
      );
    },
    removeAttachment(att) {
      this.$inertia.delete(this.route('task-manager.attachments.destroy', { task: this.detailTask.id, attachment: att.id }), {
        preserveScroll: true,
        onSuccess: () => this.refreshDetail(),
      });
    },
    async askDelete(task) {
      if (!(await this.$confirm(this.$t('Delete this task? This cannot be undone from here.'), { variant: 'danger' }))) return;
      this.$inertia.delete(this.route('task-manager.destroy', task.id), {
        preserveScroll: true,
        onSuccess: () => this.closeDetail(),
      });
    },
    canDeleteTask(task) {
      const roles = (this.$page.props.auth.user?.roles || []).map(r => r.name);
      if (roles.includes('Super Admin') || roles.includes('Admin')) return true;
      return this.$can('delete-task-manager') && Number(task.created_by_user_id) === Number(this.currentUser.id);
    },
  },
};
</script>

<style scoped>
.tm-bg-decor { position: fixed; inset: 0; z-index: 0; overflow: hidden; pointer-events: none; }
@media (min-width: 1280px) {
  .tm-board-main-split { box-shadow: 10px 0 24px -12px rgba(15, 23, 42, 0.22); }
}
.tm-blob { position: absolute; border-radius: 9999px; filter: blur(80px); opacity: 0.25; }
.tm-blob-1 { top: -80px; left: -60px; height: 320px; width: 320px; background: radial-gradient(circle, #c7d2fe, transparent 70%); }
.tm-blob-2 { top: 100px; right: -100px; height: 360px; width: 360px; background: radial-gradient(circle, #bfdbfe, transparent 70%); }


.tm-sidebar-toggle {
  display: inline-flex !important;
  align-items: center;
  justify-content: center;
  height: 40px !important;
  width: 40px !important;
  min-height: 0 !important;
  padding: 0 !important;
  border-radius: 11px;
  background: linear-gradient(135deg, rgba(99, 102, 241, 0.14), rgba(139, 92, 246, 0.14));
  border: 1.5px solid rgba(99, 102, 241, 0.3);
  color: #6366f1;
  animation: tm-icon-pulse 2.4s ease-in-out infinite;
  flex: none;
}
.tm-sidebar-toggle :deep(div) { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; }
.tm-sidebar-toggle :deep(svg) { width: 16px; height: 16px; flex: none; }
.tm-sidebar-toggle-active { background: linear-gradient(135deg, rgba(99, 102, 241, 0.26), rgba(139, 92, 246, 0.26)); }
@keyframes tm-icon-pulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.35); }
  50% { box-shadow: 0 0 0 6px rgba(99, 102, 241, 0); }
}

/* Generic CSS-only tooltip, reused for stats/team/calendar/cards */
.tm-tooltip-wrap { position: relative; }
.tm-tooltip-wrap:hover { z-index: 40; }
.tm-tooltip {
  position: absolute;
  left: 50%;
  bottom: calc(100% + 8px);
  transform: translateX(-50%) translateY(4px);
  background: rgba(15, 23, 42, 0.95);
  color: #f8fafc;
  font-size: 0.7rem;
  font-weight: 600;
  padding: 8px 11px;
  border-radius: 10px;
  white-space: nowrap;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.15s ease, transform 0.15s ease;
  z-index: 30;
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.25);
}
.tm-tooltip-wrap:hover .tm-tooltip { opacity: 1; transform: translateX(-50%) translateY(0); }

.tm-sidebar-tooltip { bottom: auto; top: calc(100% + 10px); left: 50%; right: auto; transform: translateX(-50%) translateY(4px); z-index: 80; white-space: normal; width: max-content; max-width: min(160px, 60vw); text-align: center; }
.tm-tooltip-wrap:hover .tm-sidebar-tooltip { transform: translateX(-50%) translateY(0); }


@keyframes tm-glow-blue { 0%, 100% { box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.4); } 50% { box-shadow: 0 0 0 7px rgba(37, 99, 235, 0); } }
@keyframes tm-glow-rose { 0%, 100% { box-shadow: 0 0 0 0 rgba(225, 29, 72, 0.4); } 50% { box-shadow: 0 0 0 7px rgba(225, 29, 72, 0); } }

.tm-filter-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: #94a3b8; pointer-events: none; z-index: 1; }
.tm-search-input { border: 1.5px solid rgba(15, 23, 42, 0.14); background: rgba(255, 255, 255, 0.75); }
.tm-search-input:focus { outline: none; border-color: #818cf8; background: #fff; box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.2); }

.tm-column { background: rgba(255, 255, 255, 0.6); border: 1px solid rgba(255, 255, 255, 0.9); border-radius: 16px; padding: 12px; min-height: 200px; backdrop-filter: blur(14px) saturate(150%); -webkit-backdrop-filter: blur(14px) saturate(150%); transition: box-shadow 0.15s ease, border-color 0.15s ease; }
@media (min-width: 1024px) {
  .tm-column:not(:last-child) { box-shadow: 6px 0 14px -9px rgba(15, 23, 42, 0.2); }
}
.tm-column-drag-over { border-color: #818cf8; box-shadow: 0 0 0 2px rgba(129, 140, 248, 0.25); }
.tm-column-header { display: flex; align-items: center; gap: 8px; padding: 4px 6px 12px; }
.tm-column-collapse { display: inline-flex !important; align-items: center; justify-content: center; height: 26px !important; width: 26px !important; min-height: 0 !important; padding: 0 !important; border-radius: 8px; color: #64748b; flex: none; background: rgba(100, 116, 139, 0.08); border: 1.5px solid rgba(100, 116, 139, 0.18); animation: tm-collapse-glow 3s ease-in-out infinite; }
.tm-column-collapse:hover { background: rgba(99, 102, 241, 0.14); color: #4338ca; border-color: rgba(99, 102, 241, 0.35); }
.tm-column-collapse :deep(div) { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; }
.tm-column-collapse :deep(svg) { width: 13px; height: 13px; flex: none; }
@keyframes tm-collapse-glow { 0%, 100% { box-shadow: 0 0 0 0 rgba(100, 116, 139, 0.3); } 50% { box-shadow: 0 0 0 4px rgba(100, 116, 139, 0); } }
.tm-collapse-icon-flipped { transform: rotate(-90deg); }
.tm-column-icon-badge { display: inline-flex; align-items: center; justify-content: center; height: 22px; width: 22px; border-radius: 7px; color: #fff; flex: none; animation: tm-column-icon-glow 2.6s ease-in-out infinite; }
.tm-column-icon-badge :deep(div) { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; }
.tm-column-icon-badge :deep(svg) { width: 11px; height: 11px; flex: none; }
@keyframes tm-column-icon-glow {
  0%, 100% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--tm-col-glow, #6366f1) 55%, transparent); }
  50% { box-shadow: 0 0 0 6px transparent; }
}
.tm-column-title { font-size: 0.93rem; font-weight: 700; color: #334155; flex: 1; }
.tm-column-count-circle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 26px;
  width: 26px;
  border-radius: 9999px;
  color: #fff;
  font-size: 0.72rem;
  font-weight: 700;
  animation: tm-count-glow 2.8s ease-in-out infinite;
}
@keyframes tm-count-glow { 0%, 100% { box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.3); } 50% { box-shadow: 0 0 0 5px rgba(99, 102, 241, 0); } }
.tm-column-body { display: flex; flex-direction: column; gap: 8px; min-height: 60px; }
.tm-column-empty { display: flex; flex-direction: column; align-items: center; gap: 6px; text-align: center; font-size: 0.75rem; color: #94a3b8; padding: 24px 0; }
.tm-column-showall { display: flex !important; align-items: center; justify-content: center; gap: 6px; width: 100%; margin-top: 10px; padding: 8px !important; min-height: 0 !important; border-radius: 10px; font-size: 0.78rem; font-weight: 700; color: #4f46e5; background: rgba(99, 102, 241, 0.08); border: 1.5px dashed rgba(99, 102, 241, 0.3); }
.tm-column-showall:hover { background: rgba(99, 102, 241, 0.16); }
.tm-column-showall :deep(div) { display: flex; align-items: center; justify-content: center; }
.tm-column-showall :deep(svg) { width: 12px; height: 12px; flex: none; }

.tm-card {
  position: relative;
  background: #fff;
  border: 1.5px solid rgba(15, 23, 42, 0.08);
  border-left-width: 4px;
  border-radius: 12px;
  padding: 12px;
  cursor: grab;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 2px 6px rgba(15, 23, 42, 0.03);
  transition: box-shadow 0.15s ease, transform 0.15s ease, opacity 0.2s ease, margin-top 0.15s ease;
  margin-bottom: 8px;
}
.tm-card:last-child { margin-bottom: 0; }
.tm-card:active { cursor: grabbing; }
.tm-card:hover { box-shadow: 0 6px 18px rgba(99, 102, 241, 0.14); transform: translateY(-1px); z-index: 40; }
.tm-card-drag-over { margin-top: 46px; box-shadow: 0 0 0 2px rgba(129, 140, 248, 0.35); }
.tm-card-completing { opacity: 0; transform: scale(0.94); }
.tm-card-pop-enter-active { transition: opacity 0.35s ease, transform 0.35s cubic-bezier(0.16, 1, 0.3, 1); transition-delay: var(--tm-card-delay, 0ms); }
.tm-card-pop-enter-from { opacity: 0; transform: translateX(-36px); }
.tm-card-pop-leave-active { transition: all 0.2s ease; position: absolute; }
.tm-card-pop-leave-to { opacity: 0; transform: scale(0.95); }
.tm-card-pop-move { transition: transform 0.25s ease; }

.tm-card-tooltip { bottom: auto; top: calc(100% + 8px); left: 50%; max-width: 260px; white-space: normal; text-align: left; z-index: 50; }
.tm-card-tooltip-desc { color: #cbd5e1; margin-bottom: 4px; }
.tm-card-tooltip-assignees { color: #a5b4fc; font-size: 0.66rem; }

.tm-complete-btn { display: inline-flex !important; align-items: center; justify-content: center; height: 24px !important; width: 24px !important; min-height: 0 !important; padding: 0 !important; border-radius: 9999px; background: rgba(5, 150, 105, 0.14); border: 1.5px solid rgba(5, 150, 105, 0.3); color: #059669; flex: none; transition: background 0.15s ease, transform 0.15s ease; animation: tm-complete-glow 2.6s ease-in-out infinite; }
.tm-complete-btn:hover { background: #059669; color: #fff; transform: scale(1.12); }
.tm-complete-btn :deep(div) { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; }
.tm-complete-btn :deep(svg) { width: 12px; height: 12px; flex: none; color: #059669; fill: currentColor; }
.tm-complete-btn:hover :deep(svg) { color: #fff; }
@keyframes tm-complete-glow { 0%, 100% { box-shadow: 0 0 0 0 rgba(5, 150, 105, 0.35); } 50% { box-shadow: 0 0 0 5px rgba(5, 150, 105, 0); } }

.tm-card-delete-btn { display: inline-flex !important; align-items: center; justify-content: center; height: 24px !important; width: 24px !important; min-height: 0 !important; padding: 0 !important; border-radius: 9999px; background: rgba(225, 29, 72, 0.12); border: 1.5px solid rgba(225, 29, 72, 0.28); color: #e11d48; flex: none; transition: background 0.15s ease, transform 0.15s ease; }
.tm-card-delete-btn:hover { background: #e11d48; color: #fff; transform: scale(1.12); }
.tm-card-delete-btn :deep(div) { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; }
.tm-card-delete-btn :deep(svg) { width: 12px; height: 12px; flex: none; color: #e11d48; fill: currentColor; }
.tm-card-delete-btn:hover :deep(svg) { color: #fff; }

.tm-priority-badge { display: inline-flex; align-items: center; gap: 3px; font-size: 0.66rem; font-weight: 700; padding: 2px 8px; border-radius: 9999px; text-transform: uppercase; letter-spacing: 0.03em; }
.tm-priority-badge-low { background: #f1f5f9; color: #64748b; }
.tm-priority-badge-normal { background: #e0e7ff; color: #4338ca; }
.tm-priority-badge-high { background: #fef3c7; color: #d97706; }
.tm-priority-badge-urgent { background: #fee2e2; color: #dc2626; }
.tm-category-chip { font-size: 0.66rem; font-weight: 600; color: #6d28d9; background: rgba(124, 58, 237, 0.1); padding: 2px 8px; border-radius: 8px; }
.tm-creator-chip { display: inline-flex; align-items: center; gap: 3px; margin-top: 4px; font-size: 0.64rem; font-weight: 600; color: #64748b; }
.tm-creator-chip :deep(svg) { width: 9px; height: 9px; flex: none; }

.tm-progress { display: flex; align-items: center; gap: 8px; }
.tm-progress-track { flex: 1; height: 5px; border-radius: 9999px; background: rgba(100, 116, 139, 0.15); overflow: hidden; }
.tm-progress-fill { height: 100%; background: linear-gradient(90deg, #818cf8, #6366f1); border-radius: 9999px; transition: width 0.25s ease; }
.tm-progress-label { font-size: 0.66rem; font-weight: 600; color: #64748b; }

.tm-avatar-stack { display: flex; align-items: center; }
.tm-avatar { display: inline-flex; align-items: center; justify-content: center; height: 22px; width: 22px; border-radius: 9999px; font-size: 0.6rem; font-weight: 700; color: #fff; border: 2px solid #fff; margin-left: -6px; background: linear-gradient(145deg, #818cf8, #6366f1); }
.tm-avatar:first-child { margin-left: 0; }
.tm-avatar-more { background: #94a3b8; }
.tm-avatar-lg { height: 32px; width: 32px; font-size: 0.72rem; margin-left: 0; border-width: 0; }
.tm-avatar-has-photo { background: none; padding: 0; overflow: hidden; }
.tm-avatar-photo-img { width: 100%; height: 100%; object-fit: cover; border-radius: 9999px; }
.tm-mini-avatar { display: inline-flex; align-items: center; justify-content: center; height: 14px; width: 14px; border-radius: 9999px; font-size: 0.5rem; font-weight: 700; color: #fff; background: #6366f1; margin-left: -3px; border: 1px solid #fff; }

.tm-card-meta { display: flex; align-items: center; gap: 8px; }
.tm-due-chip, .tm-meta-item { display: inline-flex; align-items: center; gap: 3px; font-size: 0.66rem; font-weight: 600; color: #64748b; }
.tm-due-chip-overdue { color: #dc2626; }

.tm-priority-chip { display: inline-flex; align-items: center; gap: 5px; justify-content: center; padding: 6px 12px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; background: rgba(255, 255, 255, 0.7); border: 1.5px solid rgba(15, 23, 42, 0.1); color: #6b7280; transition: all 0.15s ease; }
.tm-priority-chip:disabled { opacity: 0.6; cursor: not-allowed; }
.tm-priority-chip-low.tm-priority-chip-active { background: #f1f5f9; border-color: #cbd5e1; color: #475569; }
.tm-priority-chip-normal.tm-priority-chip-active { background: #e0e7ff; border-color: #a5b4fc; color: #4338ca; }
.tm-priority-chip-high.tm-priority-chip-active { background: #fef3c7; border-color: #fcd34d; color: #b45309; }
.tm-priority-chip-urgent.tm-priority-chip-active { background: #fee2e2; border-color: #fca5a5; color: #b91c1c; }

.tm-assign-btn { padding: 8px 14px; border-radius: 10px; font-size: 0.78rem; font-weight: 700; color: #fff; background: linear-gradient(135deg, #6366f1, #8b5cf6); white-space: nowrap; }

/* Sidebar cards */
.tm-side-card { background: rgba(255, 255, 255, 0.7); border: 1px solid rgba(255, 255, 255, 0.9); border-radius: 16px; padding: 14px; backdrop-filter: blur(14px); }
.tm-side-header { display: flex; align-items: center; gap: 8px; font-size: 0.82rem; font-weight: 700; color: #334155; margin-bottom: 10px; }
.tm-side-count { margin-left: auto; font-size: 0.68rem; font-weight: 700; color: #6366f1; background: rgba(99, 102, 241, 0.1); padding: 1px 7px; border-radius: 9999px; }
.tm-cal-nav { display: inline-flex !important; align-items: center; justify-content: center; height: 32px !important; width: 32px !important; min-height: 0 !important; padding: 0 !important; border-radius: 9px; color: #64748b; flex: none; background: rgba(100, 116, 139, 0.08); border: 1.5px solid rgba(100, 116, 139, 0.18); }
.tm-cal-nav:hover { background: rgba(99, 102, 241, 0.14); color: #4338ca; border-color: rgba(99, 102, 241, 0.35); }
.tm-cal-nav :deep(div) { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; }
.tm-cal-nav :deep(svg) { width: 17px; height: 17px; flex: none; }
.tm-team-list { display: flex; flex-direction: column; gap: 4px; max-height: 260px; overflow-y: auto; }
.tm-team-row { display: flex; align-items: center; gap: 8px; padding: 6px; border-radius: 10px; cursor: pointer; transition: background 0.15s ease; }
.tm-team-row:hover { background: rgba(99, 102, 241, 0.08); }

.tm-team-tooltip-float {
  background: rgba(15, 23, 42, 0.97);
  color: #f8fafc;
  border-radius: 12px;
  padding: 10px 12px;
  box-shadow: 0 14px 32px rgba(15, 23, 42, 0.32);
  z-index: 9999;
  pointer-events: none;
  animation: tm-team-tooltip-in 0.12s ease-out;
  max-width: calc(100vw - 16px);
  box-sizing: border-box;
}
@keyframes tm-team-tooltip-in { from { opacity: 0; transform: translateY(2px); } to { opacity: 1; transform: translateY(0); } }
.tm-team-tooltip-float-name { font-size: 0.75rem; font-weight: 700; margin-bottom: 6px; padding-bottom: 6px; border-bottom: 1px solid rgba(248, 250, 252, 0.15); }
.tm-team-tooltip-float-empty { font-size: 0.7rem; color: #94a3b8; }
.tm-team-tooltip-float-row { display: flex; align-items: center; gap: 6px; padding: 3px 0; }
.tm-team-tooltip-float-title { font-size: 0.7rem; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; min-width: 0; }
.tm-team-tooltip-float-more { font-size: 0.66rem; color: #94a3b8; margin-top: 4px; padding-top: 4px; border-top: 1px solid rgba(248, 250, 252, 0.15); }
.tm-team-tooltip-dot { height: 6px; width: 6px; border-radius: 9999px; flex: none; }

.tm-calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; text-align: center; }
.tm-calendar-dow { font-size: 0.66rem; font-weight: 700; color: #94a3b8; padding-bottom: 4px; }
.tm-calendar-cell-wrap { position: relative; }
.tm-calendar-cell { position: relative; aspect-ratio: 1; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; border-radius: 8px; color: #475569; }
.tm-calendar-cell-today { background: rgba(99, 102, 241, 0.14); font-weight: 700; color: #4338ca; }
.tm-calendar-cell-due { font-weight: 700; }
.tm-calendar-cell-clickable { cursor: pointer; transition: background 0.15s ease, transform 0.15s ease; }
.tm-calendar-cell-clickable:hover { background: rgba(99, 102, 241, 0.12); transform: translateY(-1px); }
.tm-calendar-cell-active { background: rgba(99, 102, 241, 0.9) !important; color: #fff !important; }
.tm-calendar-dots { position: absolute; bottom: 3px; left: 50%; transform: translateX(-50%); display: flex; align-items: center; gap: 2px; }
.tm-calendar-dot { height: 4px; width: 4px; border-radius: 9999px; flex: none; }
.tm-calendar-cell-active .tm-calendar-dot { background: #fff !important; }
.tm-calendar-tooltip { bottom: calc(100% + 4px); white-space: normal; text-align: center; width: 230px; max-width: 230px; padding: 10px 11px; }
.tm-cal-task-row { padding: 7px 0; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
.tm-cal-task-row:last-child { border-bottom: none; padding-bottom: 0; }
.tm-cal-task-row:first-child { padding-top: 0; }
.tm-cal-task-head { display: flex; align-items: center; justify-content: center; gap: 6px; }
.tm-cal-task-dot { height: 7px; width: 7px; border-radius: 9999px; flex: none; }
.tm-cal-task-title { font-weight: 700; color: #f8fafc; font-size: 0.72rem; line-height: 1.25; max-width: 190px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tm-cal-task-meta { display: flex; align-items: center; justify-content: center; gap: 4px; font-size: 0.64rem; color: #cbd5e1; margin-top: 3px; }
.tm-cal-task-status { font-weight: 700; flex: none; }
.tm-cal-task-sep { color: #64748b; flex: none; }
.tm-cal-task-assignees { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 140px; }
.tm-cal-task-due { display: flex; align-items: center; justify-content: center; gap: 3px; font-size: 0.64rem; color: #94a3b8; margin-top: 3px; }
.tm-date-filter-chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 9999px; background: rgba(99, 102, 241, 0.12); border: 1.5px solid rgba(99, 102, 241, 0.3); color: #4338ca; font-size: 0.76rem; font-weight: 700; }
.tm-date-filter-chip:hover { background: rgba(220, 38, 38, 0.12); border-color: rgba(220, 38, 38, 0.3); color: #dc2626; }
.tm-cal-task-due :deep(svg) { width: 9px; height: 9px; }

.tm-activity-feed { display: flex; flex-direction: column; gap: 0; max-height: 300px; overflow-y: auto; }
.tm-activity-feed-row { display: flex; align-items: flex-start; gap: 8px; width: 100%; cursor: pointer; text-align: left; padding: 12px 6px; background: transparent; box-shadow: 0 1px 0 rgba(15, 23, 42, 0.1); transition: background 0.15s ease; }
.tm-activity-feed-row:focus-visible { outline: 2px solid #6366f1; outline-offset: 2px; border-radius: 8px; }
.tm-activity-title { font-size: 0.76rem; line-height: 1.4; font-weight: 600; color: #374151; overflow-wrap: break-word; }
.tm-activity-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 5px; line-height: 1.5; }
.tm-activity-meta-text { font-size: 0.68rem; color: #94a3b8; white-space: normal; }
html.dark .tm-activity-title { color: #e2e8f0; }
.tm-activity-feed-row:last-child { box-shadow: none; }
.tm-activity-feed-row:hover { background: rgba(99, 102, 241, 0.08); box-shadow: 0 4px 10px rgba(99, 102, 241, 0.12); }
.tm-activity-feed-icon { display: inline-flex; align-items: center; justify-content: center; height: 24px; width: 24px; border-radius: 8px; flex: none; }
.tm-activity-feed-icon-green { background: rgba(5, 150, 105, 0.14); color: #059669; }
.tm-activity-feed-icon-indigo { background: rgba(79, 70, 229, 0.14); color: #4f46e5; }
.tm-activity-feed-icon-blue { background: rgba(37, 99, 235, 0.14); color: #2563eb; }
.tm-activity-feed-icon-amber { background: rgba(217, 119, 6, 0.14); color: #d97706; }
.tm-activity-feed-icon-emerald { background: rgba(5, 150, 105, 0.14); color: #059669; }
.tm-activity-feed-icon-violet { background: rgba(124, 58, 237, 0.14); color: #7c3aed; }
.tm-activity-feed-icon-rose { background: rgba(225, 29, 72, 0.14); color: #e11d48; }
.tm-activity-feed-icon-slate { background: rgba(100, 116, 139, 0.14); color: #475569; }
.tm-activity-assignees { display: inline-flex; margin-left: 4px; }

.tm-modal-panel { background: linear-gradient(160deg, rgba(255, 255, 255, 0.96), rgba(238, 242, 255, 0.85)); backdrop-filter: blur(32px) saturate(180%); -webkit-backdrop-filter: blur(32px) saturate(180%); border: 1px solid rgba(255, 255, 255, 0.85); border-radius: 26px; box-shadow: 0 2px 4px rgba(15, 23, 42, 0.04), 0 30px 70px -16px rgba(99, 102, 241, 0.28), 0 12px 24px -8px rgba(15, 23, 42, 0.16); overflow: hidden; text-align: left; }
.tm-modal-panel-personal { background: linear-gradient(160deg, rgba(255, 255, 255, 0.96), rgba(255, 247, 237, 0.85)); }
.tm-modal-header { display: flex; align-items: center; gap: 12px; padding: 20px 24px; border-bottom: 1px solid rgba(15, 23, 42, 0.06); }
.tm-modal-close-btn { display: inline-flex; align-items: center; justify-content: center; height: 30px; width: 30px; min-height: 0; padding: 0; border-radius: 10px; flex: none; background: rgba(255, 255, 255, 0.7); border: 1px solid rgba(15, 23, 42, 0.08); color: #64748b; transition: background 0.15s ease, color 0.15s ease; }
.tm-modal-close-btn:hover { background: #fee2e2; color: #dc2626; }
.tm-modal-body { padding: 16px 26px 18px; max-height: 82vh; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; background: #fff; }
.tm-modal-body .tm-field-label { text-transform: none; }
.tm-modal-body :deep(input:not([type='checkbox']):not([type='radio'])), .tm-modal-body :deep(textarea), .tm-modal-body :deep(select) { border-radius: 12px !important; }
.tm-modal-footer { display: flex; align-items: center; justify-content: space-between; padding: 14px 22px; background: #fff; border-top: 1px solid rgba(15, 23, 42, 0.06); }
.tm-submit-btn { border: none; border-radius: 12px; padding: 10px 18px; min-height: 0; text-transform: none; letter-spacing: normal; color: #fff; display: inline-flex; align-items: center; }
.tm-submit-btn-team { background: linear-gradient(135deg, #6366f1, #8b5cf6); box-shadow: 0 8px 18px -5px rgba(99, 102, 241, 0.5); }
.tm-submit-btn-personal { background: linear-gradient(135deg, #f59e0b, #ec4899); box-shadow: 0 8px 18px -5px rgba(236, 72, 153, 0.4); }
.tm-delete-btn { display: inline-flex; align-items: center; gap: 6px; font-size: 0.78rem; font-weight: 700; color: #dc2626; padding: 9px 14px; border-radius: 10px; background: rgba(220, 38, 38, 0.06); border: 1px solid rgba(220, 38, 38, 0.14); }
.tm-delete-btn:hover { background: rgba(220, 38, 38, 0.12); }
.tm-complete-inline-btn { display: inline-flex; align-items: center; gap: 6px; font-size: 0.78rem; font-weight: 700; color: #059669; padding: 9px 14px; border-radius: 10px; background: rgba(5, 150, 105, 0.08); border: 1px solid rgba(5, 150, 105, 0.16); }
.tm-complete-inline-btn:hover { background: rgba(5, 150, 105, 0.16); }
.tm-drawer-footer-row { display: flex; align-items: center; gap: 10px; padding-top: 14px; border-top: 1px solid rgba(15, 23, 42, 0.08); flex-wrap: wrap; }

.tm-field-container { display: flex; flex-direction: column; background: rgba(255, 255, 255, 0.55); border: 1px solid rgba(15, 23, 42, 0.07); border-radius: 14px; padding: 9px 14px; }
.tm-field-label { display: inline-flex; align-items: center; gap: 6px; font-size: 0.76rem; font-weight: 700; color: #4f46e5; text-transform: uppercase; letter-spacing: 0.02em; margin-bottom: 6px; }
.tm-field-bare { margin-bottom: 0 !important; }
.tm-textarea-wide :deep(textarea) { min-height: 54px; width: 100%; resize: vertical; }
.tm-time-input {
  flex: none;
  width: 6.4rem;
  padding: 0.5rem 0.6rem;
  border: 1px solid #d1d5db;
  border-radius: 0.375rem;
  background: #fff;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
  font-size: 0.85rem;
  color: #1e293b;
  transition: border-color 0.12s ease, box-shadow 0.12s ease;
}
.tm-time-input:hover { border-color: #94a3b8; }
.tm-time-input:focus-visible { outline: none; border-color: #a5b4fc; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2); }
.tm-time-input:disabled { cursor: not-allowed; opacity: 0.6; background: #f8fafc; }

/* Detail drawer */
.tm-drawer-backdrop { position: fixed; inset: 0; z-index: 100; background: rgba(15, 23, 42, 0.35); backdrop-filter: blur(2px); }
.tm-drawer-backdrop-enter-active, .tm-drawer-backdrop-leave-active { transition: opacity 0.25s ease; }
.tm-drawer-backdrop-enter-from, .tm-drawer-backdrop-leave-to { opacity: 0; }
.tm-drawer { position: fixed; inset-block: 0; right: 0; z-index: 101; width: 100%; max-width: 600px; background: linear-gradient(160deg, rgba(255, 255, 255, 0.98), rgba(238, 242, 255, 0.95)); backdrop-filter: blur(24px) saturate(160%); -webkit-backdrop-filter: blur(24px) saturate(160%); box-shadow: -20px 0 60px -10px rgba(15, 23, 42, 0.25); display: flex; flex-direction: column; }
.tm-drawer-panel-enter-active, .tm-drawer-panel-leave-active { transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.tm-drawer-panel-enter-from, .tm-drawer-panel-leave-to { transform: translateX(100%); }
.tm-drawer-header { display: flex; align-items: center; gap: 12px; padding: 18px 22px; border-bottom: 1px solid rgba(15, 23, 42, 0.08); background: linear-gradient(120deg, color-mix(in srgb, var(--tm-drawer-accent) 10%, transparent), transparent 65%); }
.tm-drawer-header-icon { display: inline-flex; align-items: center; justify-content: center; height: 44px; width: 44px; border-radius: 14px; flex: none; color: #fff; box-shadow: 0 8px 18px -6px rgba(15, 23, 42, 0.35); animation: tm-icon-pulse 2.8s ease-in-out infinite; }
.tm-drawer-title { font-size: 1.08rem; font-weight: 700; color: #1e293b; }
.tm-drawer-subtitle { font-size: 0.76rem; color: #6b7280; margin-top: 1px; }
.tm-drawer-tabs { display: flex; gap: 2px; padding: 10px 14px 0; border-bottom: 1px solid rgba(15, 23, 42, 0.08); }
.tm-drawer-tab { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 4px; padding: 8px 4px; font-size: 0.72rem; font-weight: 600; color: #64748b; border-bottom: 2px solid transparent; white-space: nowrap; }
.tm-drawer-tab-active { color: #4f46e5; border-bottom-color: #4f46e5; }
.tm-drawer-body { flex: 1; overflow-y: auto; padding: 18px 22px; display: flex; flex-direction: column; gap: 14px; }
.tm-drawer-loading { text-align: center; color: #94a3b8; font-size: 0.85rem; padding: 40px 0; }

.tm-assignee-readonly { display: flex; flex-direction: column; gap: 8px; }
.tm-assignee-row { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #334155; }

.tm-checklist-row { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; padding: 4px 0; }
.tm-ref-link { display: inline-flex; align-items: center; color: #4338ca; font-weight: 700; background: rgba(99, 102, 241, 0.1); padding: 1px 6px; border-radius: 6px; text-decoration: none; }
.tm-ref-link:hover { background: rgba(99, 102, 241, 0.2); text-decoration: underline; }
.tm-checklist-hint { font-size: 0.68rem; color: #94a3b8; line-height: 1.4; }
.tm-mention-dropdown { max-height: 260px; overflow-y: auto; background: #fff; border: 1px solid rgba(15, 23, 42, 0.1); border-radius: 12px; box-shadow: 0 16px 32px rgba(15, 23, 42, 0.18); padding: 6px; }
.tm-mention-dropdown-label { font-size: 0.64rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; color: #94a3b8; padding: 4px 8px 6px; }
.tm-mention-option { display: flex; align-items: center; gap: 8px; width: 100%; text-align: left; padding: 7px 10px; border-radius: 8px; font-size: 0.82rem; color: #1e293b; }
.tm-mention-option:hover, .tm-mention-option-active { background: rgba(99, 102, 241, 0.1); }
.tm-mention-option-icon { color: #6366f1; flex: none; }
.tm-mention-option-title { color: #94a3b8; font-size: 0.74rem; margin-left: auto; padding-left: 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 55%; }
.tm-mention-empty { padding: 10px 8px; font-size: 0.78rem; color: #94a3b8; text-align: center; }
html.dark .tm-mention-dropdown { background: #1e293b; border-color: rgba(255, 255, 255, 0.1); }
html.dark .tm-mention-option { color: #e2e8f0; }
html.dark .tm-mention-option:hover, html.dark .tm-mention-option-active { background: rgba(255, 255, 255, 0.06); }
.tm-checklist-check { display: inline-flex !important; align-items: center; justify-content: center; height: 20px !important; width: 20px !important; min-height: 0 !important; padding: 0 !important; border-radius: 6px; border: 1.5px solid #cbd5e1; color: transparent; flex: none; }
.tm-checklist-check-done { background: #6366f1; border-color: #6366f1; color: #fff; }
.tm-checklist-check :deep(div) { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; }
.tm-checklist-check :deep(svg) { width: 10px; height: 10px; flex: none; }
.tm-checklist-remove { margin-left: auto; display: inline-flex !important; align-items: center; justify-content: center; height: 22px !important; width: 22px !important; min-height: 0 !important; padding: 0 !important; border-radius: 9999px; background: rgba(127, 29, 29, 0.1); color: #7f1d1d; flex: none; }
.tm-checklist-remove:hover { background: #7f1d1d; color: #fff; }
.tm-checklist-remove :deep(div) { display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; }
.tm-checklist-remove :deep(svg) { width: 11px; height: 11px; flex: none; }

.tm-comment-row { padding: 10px 0; border-top: 1px solid rgba(15, 23, 42, 0.06); }
.tm-comment-row:first-child { border-top: none; }

.tm-attachment-row { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; padding: 6px 0; color: #334155; }

.tm-activity-row { display: flex; align-items: flex-start; gap: 10px; padding: 0.6rem 0; border-top: 1px solid rgba(15, 23, 42, 0.06); }
.tm-activity-row:first-child { border-top: none; }
.tm-activity-row-icon { display: inline-flex; align-items: center; justify-content: center; height: 28px; width: 28px; border-radius: 9px; flex: none; }
.tm-activity-acting-as { font-style: italic; color: #6366f1; font-weight: 600; }
.tm-activity-row:first-child { border-top: none; }

html.dark 
/* ================= Task drawer redesign (v2) ================= */

/* Pure-CSS tooltip: add class "tm-tip" + a data-tip attribute (and a placement class). No JS, no native title. */
.tm-tip { position: relative; }
.tm-tip::after {
  content: attr(data-tip);
  position: absolute; z-index: 300; pointer-events: none; opacity: 0;
  width: max-content; max-width: 230px; padding: 6px 10px; border-radius: 9px;
  background: rgba(15, 23, 42, 0.96); color: #f8fafc; font-size: 0.7rem; font-weight: 600; line-height: 1.35; text-align: center; white-space: normal;
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.28);
  transition: opacity 0.14s ease, transform 0.14s ease;
}
.tm-tip:hover::after, .tm-tip:focus-visible::after { opacity: 1; }
.tm-tip-up::after { bottom: calc(100% + 8px); left: 50%; transform: translate(-50%, 4px); }
.tm-tip-up:hover::after, .tm-tip-up:focus-visible::after { transform: translate(-50%, 0); }
.tm-tip-down::after { top: calc(100% + 8px); left: 50%; transform: translate(-50%, -4px); }
.tm-tip-down:hover::after, .tm-tip-down:focus-visible::after { transform: translate(-50%, 0); }
/* anchored to the element's right edge so it never runs off-screen on right-hand controls */
.tm-tip-left::after { bottom: calc(100% + 8px); right: 0; transform: translateY(4px); }
.tm-tip-left:hover::after, .tm-tip-left:focus-visible::after { transform: translateY(0); }

.tm-drawer { max-width: 640px; border-left: 1px solid rgba(99, 102, 241, 0.12); }

/* Header */
.tm-dr-head { display: flex; align-items: flex-start; gap: 14px; padding: 18px 20px 14px; background: linear-gradient(120deg, color-mix(in srgb, var(--tm-drawer-accent) 12%, white), rgba(255, 255, 255, 0) 70%); }
.tm-dr-icon { display: inline-flex; align-items: center; justify-content: center; width: 46px; height: 46px; flex: none; border-radius: 15px; color: #fff; background: linear-gradient(145deg, color-mix(in srgb, var(--tm-drawer-accent) 70%, white), var(--tm-drawer-accent)); box-shadow: 0 10px 20px -8px var(--tm-drawer-accent); }
.tm-dr-headtext { min-width: 0; flex: 1; }
.tm-dr-title { font-size: 1.1rem; font-weight: 800; line-height: 1.25; color: #0f172a; overflow-wrap: anywhere; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.tm-dr-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 7px; }
.tm-dr-chip { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 9999px; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.02em; }
.tm-dr-chip-status { background: color-mix(in srgb, var(--tm-drawer-accent) 15%, white); color: color-mix(in srgb, var(--tm-drawer-accent) 85%, black); }
.tm-dr-chip-low { background: #f1f5f9; color: #475569; }
.tm-dr-chip-normal { background: #e0e7ff; color: #4338ca; }
.tm-dr-chip-high { background: #fef3c7; color: #b45309; }
.tm-dr-chip-urgent { background: #fee2e2; color: #b91c1c; }
.tm-dr-by { font-size: 0.72rem; color: #64748b; }
.tm-dr-close { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; min-height: 0; padding: 0; flex: none; border-radius: 9999px; color: #64748b; background: #fff; border: 1px solid #e5eaf4; box-shadow: 0 2px 6px -2px rgba(15, 23, 42, 0.15); transition: color 0.15s ease, background 0.15s ease, transform 0.15s ease; }
.tm-dr-close:hover { background: #fee2e2; color: #dc2626; transform: rotate(90deg); }

/* Tabs */
.tm-dr-tabs { display: flex; gap: 4px; padding: 6px 14px 10px; overflow-x: auto; scrollbar-width: none; border-bottom: 1px solid rgba(15, 23, 42, 0.07); }
.tm-dr-tabs::-webkit-scrollbar { display: none; }
.tm-dr-tab { flex: 1 0 auto; display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 8px 10px; min-height: 0; border-radius: 12px; border: 1px solid transparent; font-size: 0.74rem; font-weight: 700; color: #64748b; background: transparent; white-space: nowrap; transition: background 0.15s ease, color 0.15s ease, box-shadow 0.15s ease; }
.tm-dr-tab:hover { background: rgba(99, 102, 241, 0.07); color: #4338ca; }
.tm-dr-tab-on, .tm-dr-tab-on:hover { background: #fff; color: #4338ca; border-color: #dfe3fb; box-shadow: 0 6px 14px -8px rgba(79, 70, 229, 0.6); }
.tm-dr-tab-badge { padding: 0 6px; min-width: 18px; border-radius: 9999px; font-size: 0.64rem; font-weight: 800; text-align: center; color: #4338ca; background: #e0e7ff; }
.tm-dr-tab-on .tm-dr-tab-badge { background: linear-gradient(145deg, #818cf8, #6366f1); color: #fff; }
@media (max-width: 560px) { .tm-dr-tab:not(.tm-dr-tab-on) .tm-dr-tab-label { display: none; } }

/* Body + section cards */
.tm-dr-body { flex: 1; overflow-y: auto; overflow-x: hidden; padding: 16px 20px 22px; display: flex; flex-direction: column; gap: 14px; }
.tm-dr-sec { min-width: 0; padding: 14px 16px 16px; border-radius: 18px; background: rgba(255, 255, 255, 0.92); border: 1px solid #e8ecf7; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 14px 26px -20px rgba(15, 23, 42, 0.3); }
.tm-dr-sec-title { display: flex; align-items: center; gap: 7px; margin-bottom: 12px; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: #4f46e5; }
.tm-dr-sec-count { margin-left: auto; padding: 1px 9px; border-radius: 9999px; font-size: 0.68rem; font-weight: 800; letter-spacing: 0; color: #4338ca; background: #e0e7ff; }

/* Fields — every control may shrink (min-width: 0), so nothing can spill out of its container */
.tm-f { display: flex; flex-direction: column; min-width: 0; margin-bottom: 12px; }
.tm-f:last-child { margin-bottom: 0; }
.tm-f-label { margin-bottom: 5px; font-size: 0.72rem; font-weight: 700; color: #64748b; }
.tm-dr-body :deep(input:not([type='checkbox']):not([type='radio'])), .tm-dr-body :deep(textarea), .tm-dr-body :deep(select) { max-width: 100%; box-sizing: border-box; border-radius: 12px !important; }
.tm-prio-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(92px, 100%), 1fr)); gap: 8px; }
.tm-prio-row .tm-priority-chip { justify-content: center; width: 100%; padding: 8px 6px; }

.tm-dt { display: grid; grid-template-columns: minmax(0, 1fr) 9.5rem; gap: 8px; align-items: stretch; min-width: 0; }
.tm-dt-date { min-width: 0; grid-column: auto !important; margin-bottom: 0 !important; }
.tm-dt-date :deep(.wdp-trigger), .tm-dt-date :deep(button) { min-height: 42px; }
.tm-dt-time { display: flex; align-items: center; gap: 8px; min-width: 0; height: 42px; padding: 0 12px; border-radius: 12px; background: #fff; border: 1px solid #d1d5db; color: #94a3b8; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03); transition: border-color 0.12s ease, box-shadow 0.12s ease; cursor: text; }
.tm-dt-time:hover { border-color: #94a3b8; }
.tm-dt-time:focus-within { border-color: #a5b4fc; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2); color: #6366f1; }
.tm-dt-time .tm-time-input { flex: 1 1 auto; width: 100%; min-width: 0; height: 100%; padding: 0; border: 0 !important; background: transparent; box-shadow: none !important; outline: none; font-size: 0.85rem; color: #1e293b; }
@media (max-width: 440px) { .tm-dt { grid-template-columns: minmax(0, 1fr); } }
.tm-sched-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(290px, 100%), 1fr)); gap: 16px; }

/* Pinned action bar */
.tm-dr-foot { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 12px 20px; background: rgba(255, 255, 255, 0.96); border-top: 1px solid rgba(15, 23, 42, 0.07); box-shadow: 0 -12px 24px -18px rgba(15, 23, 42, 0.35); }
.tm-dr-foot-right { display: flex; align-items: center; gap: 8px; margin-left: auto; }

/* Checklist */
.tm-progress { height: 8px; margin-bottom: 12px; border-radius: 9999px; background: #e8ecf7; overflow: hidden; }
.tm-progress-fill { display: block; height: 100%; border-radius: 9999px; background: linear-gradient(90deg, #818cf8, #10b981); transition: width 0.3s ease; }
.tm-checklist-list { display: flex; flex-direction: column; gap: 6px; }
.tm-checklist-row { gap: 10px; padding: 8px 10px; border-radius: 12px; background: #f8faff; border: 1px solid #edf0fa; transition: background 0.15s ease, box-shadow 0.15s ease; }
.tm-checklist-row:hover { background: #fff; box-shadow: 0 6px 14px -10px rgba(79, 70, 229, 0.5); }

/* Comments */
.tm-comment-row { display: flex; gap: 10px; padding: 6px 0; border-top: none !important; }
.tm-comment-bubble { flex: 1; min-width: 0; padding: 9px 12px; border-radius: 4px 14px 14px 14px; background: #f4f6ff; border: 1px solid #e6eafb; }
.tm-wrap { overflow-wrap: anywhere; word-break: break-word; }

/* Attachments */
.tm-attachment-row { gap: 10px; padding: 8px 10px; margin-bottom: 6px; border-radius: 12px; background: #f8faff; border: 1px solid #edf0fa; }
.tm-attachment-icon { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; flex: none; border-radius: 10px; color: #4f46e5; background: #e0e7ff; }

/* Activity timeline */
.tm-timeline { position: relative; display: flex; flex-direction: column; gap: 8px; }
.tm-timeline .tm-activity-row { padding: 9px 11px; border-top: none; border-radius: 12px; background: #f8faff; border: 1px solid #edf0fa; }

.tm-empty { padding: 14px 4px; text-align: center; font-size: 0.8rem; color: #94a3b8; }

html.dark .tm-dr-sec, html.dark .tm-dr-foot, html.dark .tm-dr-close { background: rgba(15, 23, 42, 0.8); border-color: rgba(255, 255, 255, 0.08); }
html.dark .tm-dr-title { color: #f1f5f9; }
html.dark .tm-checklist-row, html.dark .tm-attachment-row, html.dark .tm-comment-bubble, html.dark .tm-timeline .tm-activity-row { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.08); }
@media (prefers-reduced-motion: reduce) { .tm-tip::after, .tm-dr-close, .tm-progress-fill { transition: none; } }
/* ================= Board redesign (shared lg look) ================= */

/* Header side-panel toggle sits beside the primary action */
.tm-sidebar-toggle { height: 34px !important; width: 34px !important; border-radius: 9999px; animation: none; color: var(--lg-navy); background: var(--lg-card); border: 1px solid #d3deec; }
.tm-sidebar-toggle:hover, .tm-sidebar-toggle-active { background: rgba(28, 63, 102, 0.1); border-color: rgba(28, 63, 102, 0.35); }
html.dark .tm-sidebar-toggle { color: #bfdbfe; border-color: rgba(147, 197, 253, 0.3); }

/* Filter bar */
.tm-filterbar { margin-bottom: 1rem; padding: 0.7rem 0.8rem; border-radius: 22px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); }
.tm-filterbar-row { display: flex; flex-direction: column; gap: 10px; }
@media (min-width: 640px) { .tm-filterbar-row { flex-direction: row; flex-wrap: wrap; align-items: center; } }
.tm-filterbar .tm-search-input { border: 1px solid var(--lg-line); background: var(--lg-soft); color: var(--lg-ink); }
.tm-filterbar .tm-search-input:focus { border-color: #818cf8; background: var(--lg-card); box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.2); }
.tm-filterbar .tm-filter-icon { color: var(--lg-faint); }

/* Board columns */
.tm-column { background: var(--lg-soft); border: 1px solid var(--lg-line); border-top: 3px solid var(--tm-col-accent, #94a3b8); border-radius: 20px; padding: 12px; box-shadow: none; backdrop-filter: none; -webkit-backdrop-filter: none; }
.tm-column:not(:last-child) { box-shadow: none; }
.tm-column.tm-column-drag-over { border-color: #818cf8; border-top-color: #818cf8; box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.22); }
.tm-column-collapse { animation: none; background: var(--lg-card); border: 1px solid var(--lg-line); }
.tm-column-icon-badge { animation: none; width: 24px; height: 24px; border-radius: 9px; }
.tm-column-title { font-size: 0.9rem; font-weight: 800; color: var(--lg-ink); }
.tm-column-count-circle { animation: none; width: auto; min-width: 26px; padding: 0 9px; height: 24px; font-size: 0.72rem; font-weight: 800; }
.tm-column-empty { border: 1.5px dashed var(--lg-line); border-radius: 16px; padding: 22px 8px; color: var(--lg-faint); font-weight: 600; }
.tm-column-showall { border-radius: 9999px; }

/* Task cards */
.tm-card { background: var(--lg-card); border: 1px solid var(--lg-line); border-left-width: 4px; border-radius: 16px; padding: 12px 12px 10px; box-shadow: var(--lg-shadow); }
.tm-card:hover { box-shadow: 0 16px 28px -16px rgba(28, 63, 102, 0.5); }
.tm-card.tm-card-drag-over { box-shadow: 0 0 0 2px rgba(129, 140, 248, 0.45); }
.tm-card-title { display: flex; align-items: center; gap: 6px; margin-top: 9px; font-size: 0.86rem; font-weight: 800; line-height: 1.3; color: var(--lg-ink); overflow-wrap: anywhere; }
.tm-card-desc { margin-top: 4px; font-size: 0.75rem; line-height: 1.45; color: var(--lg-mute); }
.tm-card-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 10px; padding-top: 9px; border-top: 1px dashed var(--lg-line); }
.tm-card-meta { flex-wrap: wrap; justify-content: flex-end; gap: 6px; }
.tm-card .tm-progress { height: auto; margin: 10px 0 0; border-radius: 0; background: transparent; overflow: visible; }
.tm-card .tm-progress-track { height: 6px; background: rgba(100, 116, 139, 0.16); }
.tm-card .tm-progress-fill { background: linear-gradient(90deg, #818cf8, #10b981); }
.tm-card .tm-progress-label { color: var(--lg-mute); font-weight: 800; }
.tm-due-chip { padding: 2px 8px; border-radius: 9999px; background: rgba(100, 116, 139, 0.12); }
.tm-due-chip-overdue { color: #be123c; background: rgba(244, 63, 94, 0.13); }
.tm-due-chip-today { color: #92400e; background: rgba(245, 158, 11, 0.17); }
.tm-meta-item { color: var(--lg-mute); }
.tm-creator-chip { color: var(--lg-mute); }

.tm-priority-badge { padding: 2px 9px; font-weight: 800; letter-spacing: 0.04em; }
.tm-priority-badge-low { background: rgba(100, 116, 139, 0.14); color: #475569; }
.tm-priority-badge-normal { background: rgba(99, 102, 241, 0.13); color: #4338ca; }
.tm-priority-badge-high { background: rgba(245, 158, 11, 0.17); color: #92400e; }
.tm-priority-badge-urgent { background: rgba(244, 63, 94, 0.13); color: #be123c; }
.tm-category-chip { padding: 2px 9px; border-radius: 9999px; font-weight: 800; }
html.dark .tm-priority-badge-low { color: #cbd5e1; }
html.dark .tm-priority-badge-normal { color: #a5b4fc; }
html.dark .tm-priority-badge-high { color: #fcd34d; }
html.dark .tm-priority-badge-urgent { color: #fda4af; }
html.dark .tm-category-chip { color: #c4b5fd; }
html.dark .tm-due-chip-overdue { color: #fda4af; }
html.dark .tm-due-chip-today { color: #fcd34d; }

.tm-avatar { border-color: var(--lg-card, #fff); }
.tm-avatar-stack .tm-avatar:nth-child(2) { background: linear-gradient(145deg, #f472b6, #be185d); }
.tm-avatar-stack .tm-avatar:nth-child(3) { background: linear-gradient(145deg, #2dd4bf, #0f766e); }
.tm-avatar-stack .tm-avatar-more { background: #94a3b8; }

html.dark .tm-column { background: rgba(255, 255, 255, 0.03); border-color: var(--lg-line); border-top-color: var(--tm-col-accent, #94a3b8); }
html.dark .tm-column.tm-column-drag-over { border-top-color: #818cf8; }
html.dark .tm-card { background: rgba(15, 23, 42, 0.85); border-color: var(--lg-line); }
html.dark .tm-card-drag-over { border-color: #818cf8; }

/* Side panel cards */
.tm-side-card { background: var(--lg-card); border: 1px solid var(--lg-line); border-radius: 22px; box-shadow: var(--lg-shadow); backdrop-filter: none; }
.tm-side-header { color: var(--lg-ink); font-weight: 800; }
html.dark .tm-side-card { background: var(--lg-card); border-color: var(--lg-line); }
html.dark .tm-side-header { color: var(--lg-ink); }

/* New task modal */
.tm-modal-panel, .tm-modal-panel-personal { background: var(--lg-card); border: 1px solid var(--lg-line); border-radius: 24px; backdrop-filter: none; -webkit-backdrop-filter: none; box-shadow: 0 30px 60px -20px rgba(15, 23, 42, 0.45); }
html.dark .tm-modal-panel { background: #111a2e; border-color: var(--lg-line); }
.tm-modal-header { padding: 18px 22px; border-bottom: 1px solid var(--lg-line); background: linear-gradient(135deg, rgba(224, 233, 250, 0.75), rgba(255, 251, 235, 0.55)); }
html.dark .tm-modal-header { background: linear-gradient(135deg, rgba(28, 63, 102, 0.45), rgba(15, 23, 42, 0.7)); }
.tm-modal-close-btn { border-radius: 9999px; background: var(--lg-card); border-color: var(--lg-line); color: var(--lg-mute); }
.tm-modal-body { padding: 18px 22px 20px; background: transparent; gap: 0; }
.tm-modal-body .lg-fields { gap: 1rem 1.25rem; }
.tm-modal-body .tm-field-container { background: transparent; border: 0; border-radius: 0; padding: 0; min-width: 0; }
.tm-modal-body .tm-field-label { margin-bottom: 6px; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.04em; color: var(--lg-mute); }
.tm-modal-body .tm-field-label :deep(svg) { color: #6366f1; }
.tm-modal-body .tm-sched-grid { gap: 1rem 1.25rem; grid-template-columns: repeat(auto-fit, minmax(min(270px, 100%), 1fr)); }
html.dark .tm-modal-body .tm-field-container { background: transparent; border: 0; }
.tm-modal-footer { justify-content: flex-end; gap: 8px; padding: 12px 22px; background: var(--lg-soft); border-top: 1px solid var(--lg-line); }
.tm-modal-footer .tm-submit-btn { padding: 8px 18px; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; }
.tm-modal-footer .tm-submit-btn-team { background: linear-gradient(145deg, #2f6fb3, #0f2540); box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.9); }
.tm-modal-footer .tm-submit-btn-personal { background: linear-gradient(145deg, #f59e0b, #b45309); box-shadow: 0 10px 18px -10px rgba(180, 83, 9, 0.9); }
.tm-modal-footer .tm-submit-btn:hover:not(:disabled) { transform: translateY(-1px); }
@media (max-width: 480px) { .tm-modal-footer { flex-direction: column-reverse; align-items: stretch; } }
</style>

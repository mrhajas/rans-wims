<template>
  <div ref="navRoot" class="relative z-50 md:flex md:shrink-0 nb-root" :class="{ 'nb-pro': pro }">
    <div
      v-if="$page.props.settings.sidebar == 'mini'"
      class="liquid-side-rail md:shrink-0 md:w-16 px-6 py-3 flex items-center justify-between md:justify-center"
    >
      <Link class="text-gray-100" :href="route('dashboard')">
        <tec-application-mark class="block h-8" />
      </Link>
      <mobile-menu-icon :show="show" @on-change="v => (show = v)" />
    </div>
    <div v-else class="liquid-side-rail rail-full md:shrink-0 md:w-64 px-6 py-3 flex items-center justify-between md:justify-center" :class="{ 'rail-collapsed': (sidebarHidden || menuUnavailable) && !pro, 'rail-pro': pro }">
      <Link v-if="pro" class="rail-brand rail-brand-pro" :href="route('dashboard')" aria-label="RANS Workflow Integrated Management System"><animated-logo /></Link>
      <Link v-else class="rail-brand lg-tip" :href="route('dashboard')" :data-tip="sidebarHidden || menuUnavailable ? 'RANS Workflow Integrated Management System' : null" :aria-label="sidebarHidden || menuUnavailable ? 'RANS Workflow Integrated Management System' : null">
        <img class="rail-brand-logo" src="/images/logo-white.png" alt="RANS Workflow Integrated Management System" />
        <img class="rail-brand-mark" src="/images/logo-white-mark.png" alt="RANS" />
      </Link>
      <mobile-menu-icon :show="show" @on-change="v => (show = v)" />
      <orbit-line v-if="pro" class="ol-rail" :length="90" :speed="150" />
    </div>
    <div class="bg-white border-b border-slate-100 shadow-sm w-full pt-3.5 pb-2 px-4 md:py-2.5 md:text-md flex flex-wrap justify-between items-start md:items-center gap-y-2">
      <!-- <div class="mt-1 mr-4">{{ $page.props.auth.user.name.account.name }}</div> -->
      <div class="order-2 md:order-1 nb-left mt-1 mr-0 md:mr-4 flex flex-nowrap items-center gap-y-2 min-w-0 w-full md:w-auto overflow-visible">
        <button
          v-if="!menuUnavailable"
          type="button"
          @click="$emit('on-sidebar-toggle')"
          :data-tip="sidebarHidden ? $t('Show menu') : $t('Hide menu')"
          class="hidden nav:inline-flex items-center justify-center min-w-[44px] min-h-[44px] mr-2 rounded-lg bg-slate-50 text-slate-500 hover:bg-slate-100 hover:text-slate-700 focus:outline-none transition duration-150 ease-in-out shrink-0 lg-tip"
         :aria-label="sidebarHidden ? $t('Show menu') : $t('Hide menu')">
          <icons name="chevron-down" class="h-5 w-5 transition-transform" :class="sidebarHidden ? '-rotate-90' : 'rotate-90'" />
        </button>

        <tec-dropdown align="left" width="48">
          <template #trigger>
            <button class="nb-lang-btn flex items-center px-2 sm:px-3 py-2 mr-1 sm:mr-2 rounded-lg text-slate-600 hover:bg-slate-100 focus:outline-none transition duration-150 ease-in-out lg-tip" :data-tip="$t('Language')" :aria-label="$t('Language')">
              <span class="" v-html="current.replace(/./g, char => String.fromCodePoint(char.charCodeAt(0) + 127397))"></span>
            </button>
          </template>

          <template #content>
            <template v-for="lang in languages" :key="lang.value">
              <tec-dropdown-link as="button" @click="changeLang(lang.value)">
                <span class="mr-2" v-html="lang.flag.replace(/./g, char => String.fromCodePoint(char.charCodeAt(0) + 127397))"> </span>
                {{ lang.label }}
              </tec-dropdown-link>
            </template>
          </template>
        </tec-dropdown>

        <tec-dropdown align="left" width="80" :center-on-mobile="true" v-if="myWarehouses && myWarehouses.length">
          <template #trigger>
            <button class="wh-picker-trigger lg-tip flex items-center gap-1.5 sm:gap-2.5 pl-2 sm:pl-2.5 pr-2 sm:pr-3 py-1.5 mr-2 rounded-xl focus:outline-none shrink-0" :data-tip="$t('Switch the project or warehouse you are managing')" :aria-label="$t('Switch the project or warehouse you are managing')">
              <span class="wh-picker-icon"><icons name="map-pin" class="w-4 h-4"></icons></span>
              <span class="flex flex-col items-start leading-tight min-w-0">
                <span class="wh-picker-label hidden sm:block nb-c-hide">{{ $t('Managing') }}</span>
                <span class="wh-picker-value truncate max-w-[5.5rem] sm:max-w-[11rem]">{{ currentWarehouse ? currentWarehouse.name : $t('All Warehouses') }}</span>
              </span>
              <icons name="chevron-down" class="wh-picker-chevron ml-0.5 sm:ml-1 -mr-1" />
            </button>
          </template>

          <template #content>
            <div class="wh-picker-panel-header shrink-0">
              <icons name="map-pin" class="w-3.5 h-3.5" />
              {{ $t('Managing Warehouse') }}
            </div>
            <div class="wh-picker-scroll">
              <div class="px-3 pt-2 pb-2 sticky top-0 bg-white z-10" @click.stop>
                <div class="relative">
                  <icons name="search" class="wh-picker-search-icon" />
                  <input
                    type="text"
                    v-model="warehouseSearch"
                    :placeholder="$t('Search warehouse')"
                    class="wh-picker-search-input w-full text-xs rounded-lg pl-8"
                  />
                </div>
              </div>
              <div class="border-t border-slate-100 pt-1">
                <tec-dropdown-link as="button" @click="selectWarehouse(null)">
                  <span class="wh-picker-item" :class="{ 'wh-picker-item-active': !currentWarehouse }">
                    <icons name="collection" class="w-4 h-4 shrink-0" />
                    <span class="truncate">{{ $t('All Warehouses') }}</span>
                    <icons v-if="!currentWarehouse" name="tick" class="wh-picker-check" />
                  </span>
                </tec-dropdown-link>
                <div v-for="sec in warehouseSections" :key="sec.key" class="wh-sec">
                  <div class="wh-sec-head" :class="'wh-sec-' + sec.tone">
                    <span class="wh-sec-ico"><icons :name="sec.icon" class="w-3 h-3" /></span>
                    <span class="wh-sec-name">{{ sec.label }}</span>
                    <span class="wh-sec-count">{{ sec.items.length }}</span>
                  </div>
                  <tec-dropdown-link as="button" v-for="w in sec.items" :key="w.id" @click="selectWarehouse(w.id)">
                    <span class="wh-picker-item wh-has-cat" :class="{ 'wh-picker-item-active': currentWarehouse && currentWarehouse.id === w.id }" :data-cat="catText(w)">
                      <span v-if="progressOf(w)" class="wh-dot flex-none" :class="'wh-dot-' + progressOf(w)"></span>
                      <span class="truncate">{{ w.name }}</span>
                      <icons v-if="currentWarehouse && currentWarehouse.id === w.id" name="tick" class="wh-picker-check" />
                    </span>
                  </tec-dropdown-link>
                </div>
                <div v-if="warehouseSections.length === 0" class="px-4 py-3 text-sm text-gray-400">
                  {{ $t('No warehouse found.') }}
                </div>
              </div>
            </div>
          </template>
        </tec-dropdown>

        <quick-create-menu class="mr-2" v-if="$page.props.auth.user" />

        <div
          class="inline-flex items-center nb-role-chip nb-role-chip-lg lg-tip max-w-full"
          :data-tip="`${$t('User role')}: ${userRoleLabel}`"
          :aria-label="`${$t('User role')}: ${userRoleLabel}`"
          tabindex="0"
        >
          <span class="nb-role-icon">
            <icons name="key" class="w-4 h-4 nb-role-key" />
            <span class="nb-role-initials-badge">{{ userRoleInitials }}</span>
          </span>
          <span class="nb-role-text">
            <small class="nb-c-hide">{{ $t('User role') }}</small>
            <b class="truncate">{{ userRoleLabel }}</b>
          </span>
          <span class="nb-role-dot nb-role-dot-live"></span>
        </div>
      </div>

      <div class="order-1 md:order-2 nb-right flex flex-wrap md:flex-nowrap items-center justify-end gap-x-2 gap-y-1.5 md:gap-x-3 w-full md:w-auto md:shrink-0 md:ml-auto">
        <prayer-pill v-if="prayerOn" />
        <button
          v-if="prayerOn"
          type="button"
          class="inline-flex items-center justify-center min-w-[44px] min-h-[44px] rounded-lg bg-emerald-50 text-emerald-700 hover:bg-emerald-100 focus:outline-none transition duration-150 ease-in-out lg-tip theme-toggle-btn nb-prayer-btn"
          :class="{ 'is-playing': adhanPlaying }"
          :data-tip="adhanPlaying ? $t('Adhan playing. Open to stop it') : $t('Prayer times')"
          :aria-label="$t('Prayer times')"
          @click="prayerState.drawer = true"
        >
          <icons name="mosque" class="h-6 w-6" />
          <span v-if="adhanPlaying" class="nb-prayer-eq" aria-hidden="true"><i></i><i></i><i></i></span>
        </button>
        <prayer-drawer v-if="prayerOn" />

        <button
          v-if="darkModeFeatureEnabled"
          type="button"
          @click="toggleTheme"
          :data-tip="theme === 'dark' ? $t('Switch to normal mode') : $t('Switch to dark mode')"
          class="hidden sm:inline-flex items-center justify-center min-w-[44px] min-h-[44px] rounded-lg bg-cyan-50 text-cyan-600 hover:bg-cyan-100 focus:outline-none transition duration-150 ease-in-out lg-tip theme-toggle-btn"
         :aria-label="theme === 'dark' ? $t('Switch to normal mode') : $t('Switch to dark mode')">
          <theme-icon class="h-6 w-6" />
        </button>

        <button
          type="button"
          @click="openRauncher"
          :data-tip="$t('Quick search') + ' (' + rauncherShortcutLabel + ')'"
          class="inline-flex items-center justify-center min-w-[44px] min-h-[44px] rounded-lg bg-indigo-50 text-indigo-600 hover:bg-indigo-100 focus:outline-none transition duration-150 ease-in-out lg-tip theme-toggle-btn nb-rauncher-glow"
          :aria-label="$t('Quick search')">
          <icons name="search-spark" class="h-6 w-6" />
        </button>

        <tec-dropdown v-if="$can('read-digital-attendance')" align="right" width="80" center-on-mobile :content-classes="['da-nav-panel-shell']">
          <template #trigger>
            <button
              type="button"
              class="da-nav-btn lg-tip"
              :class="{ 'da-nav-btn-in': attendanceToday && attendanceToday.status === 'CLOCKED_IN', 'da-nav-btn-warn': attendanceToday && attendanceToday.status === 'MISSING_CLOCK_OUT', 'nb-tooltip': attendanceToday && attendanceToday.status === 'MISSING_CLOCK_OUT' }"
              :data-tip="attendanceToday && attendanceToday.status === 'MISSING_CLOCK_OUT' ? null : $t('Digital Attendance')"
              :data-tooltip="attendanceToday && attendanceToday.status === 'MISSING_CLOCK_OUT' ? $t('Missing Clock-Out from Previous Session. Contact Admin to Rectify the Issue.') : null"
             :aria-label="attendanceToday && attendanceToday.status === 'MISSING_CLOCK_OUT' ? null : $t('Digital Attendance')">
              <span class="da-nav-btn-icon">
                <icons v-if="attendanceToday && attendanceToday.status === 'MISSING_CLOCK_OUT'" name="exclamation" class="w-3.5 h-3.5" />
                <icons v-else name="clock" class="w-3.5 h-3.5" />
                <span v-if="attendanceToday && attendanceToday.status === 'CLOCKED_IN'" class="da-nav-dot"></span>
              </span>
              <span v-if="attendanceToday && attendanceToday.status === 'CLOCKED_IN'" class="da-nav-time">{{ attendanceWorkedLabel }}</span>
              <span v-else-if="attendanceToday && attendanceToday.status === 'MISSING_CLOCK_OUT'" class="hidden sm:inline">{{ $t('Clock Out') }}</span>
              <span v-else class="hidden sm:inline nb-c-hide">{{ $t('Attendance') }}</span>
            </button>
          </template>
          <template #content>
            <div class="da-nav-panel">
              <div
                class="da-nav-hero"
                :class="{
                  'da-nav-hero-warn': attendanceToday && attendanceToday.status === 'MISSING_CLOCK_OUT',
                  'da-nav-hero-idle': !attendanceToday || attendanceToday.status === 'CLOCKED_OUT',
                }"
              >
                <div class="da-nav-hero-top">
                  <span class="da-nav-hero-label">{{ attendanceHeroLabel }}</span>
                  <span
                    class="da-nav-hero-pill"
                    :class="{
                      'da-nav-hero-pill-in': attendanceToday && attendanceToday.status === 'CLOCKED_IN',
                      'da-nav-hero-pill-warn': attendanceToday && attendanceToday.status === 'MISSING_CLOCK_OUT',
                    }"
                  >
                    <span class="da-nav-hero-pill-dot"></span>{{ attendancePillText }}
                  </span>
                </div>
                <div class="da-nav-hero-clock">{{ attendanceHeroClock }}</div>
                <template v-if="attendanceToday && attendanceToday.clock_in_location_label">
                  <div class="da-nav-hero-divider"></div>
                  <div class="da-nav-hero-location">
                    <icons name="map-pin" class="w-3.5 h-3.5" />
                    <span class="truncate">{{ attendanceToday.clock_in_location_label }}</span>
                  </div>
                </template>
              </div>
              <div class="da-nav-hero-footer">
                <div class="da-nav-hero-footer-label">
                  <span class="truncate">{{ $t('Clocked In hours for') }} {{ attendanceTodayLabel }}</span>
                  <button
                    v-if="attendanceToday && attendanceToday.sessions_count > 1"
                    type="button"
                    class="da-nav-session-chip lg-tip"
                    :data-tip="attendanceToday.sessions_count + ' ' + $t('clock-in sessions today, all counted under one record.')"
                    @click.stop="toggleAttendanceSessions"
                   :aria-label="attendanceToday.sessions_count + ' ' + $t('clock-in sessions today, all counted under one record.')">
                    &times;{{ attendanceToday.sessions_count }}
                    <icons name="chevron-down" class="w-2.5 h-2.5 da-nav-session-chevron" :class="{ 'rotate-180': attendanceSessionsExpanded }" />
                  </button>
                </div>
                <Link
                  :href="route('digital-attendance.history')"
                  class="da-nav-hero-footer-value da-nav-tooltip"
                  :data-tooltip="$t('View attendance history')"
                >{{ attendanceWorkedClock }}</Link>
              </div>

              <div v-if="attendanceSessionsExpanded" class="da-nav-sessions-box">
                <div v-if="attendanceSessionsLoading" class="da-nav-sessions-loading">{{ $t('Loading sessions…') }}</div>
                <div v-else-if="!attendanceSessions.length" class="da-nav-sessions-loading">{{ $t('No sessions found.') }}</div>
                <div v-else class="da-nav-sessions-list">
                  <div v-for="(s, i) in attendanceSessions" :key="i" class="da-nav-session-row">
                    <span class="da-nav-session-num">{{ $t('Session') }} {{ i + 1 }}</span>
                    <span class="da-nav-session-times">{{ s.clock_in_label }} <span class="opacity-50">&rarr;</span> {{ s.clock_out_label || $t('open') }}</span>
                    <span v-if="s.worked_label" class="da-nav-session-worked">{{ s.worked_label }}</span>
                  </div>
                </div>
              </div>

              <template v-if="attendanceToday && attendanceToday.status === 'MISSING_CLOCK_OUT'">
                <div class="da-nav-hint">{{ $t('Ask an administrator to review this session.') }}</div>
              </template>

              <div class="da-nav-panel-actions">
                <button
                  v-if="$can('clock-in-digital-attendance') && (!attendanceToday || attendanceToday.status === 'CLOCKED_OUT')"
                  type="button"
                  class="da-nav-panel-btn da-nav-panel-btn-in"
                  :disabled="attendanceProcessing || $offlineBlocked"
                  @click="navClockIn"
                >
                  <icons name="in" class="w-3.5 h-3.5 flex-none" /><span class="truncate">{{ $t('Clock In') }}</span>
                </button>
                <button
                  v-if="$can('clock-out-digital-attendance') && attendanceToday && attendanceToday.status === 'CLOCKED_IN'"
                  type="button"
                  class="da-nav-panel-btn da-nav-panel-btn-out"
                  :disabled="attendanceProcessing || $offlineBlocked"
                  @click="navClockOut"
                >
                  <icons name="out" class="w-3.5 h-3.5 flex-none" /><span class="truncate">{{ $t('Clock Out') }}</span>
                </button>
                <Link :href="route('digital-attendance.show')" class="da-nav-panel-btn">
                  <icons name="eye-flat" class="w-3.5 h-3.5 flex-none" /><span class="truncate">{{ $t('View Attendance') }}</span>
                </Link>
              </div>
            </div>
          </template>
        </tec-dropdown>

        <status-indicator />

        <icon-menus class="flex gap-x-3 items-center" />

        <tec-dropdown
          align="right"
          width="72"
          :content-classes="['bg-white']"
        >
          <template #trigger>
            <button
              class="group flex items-center px-2 py-1.5 rounded-full hover:bg-slate-100 !text-slate-900 dark:!text-slate-100 focus:outline-none transition duration-150 ease-in-out lg-tip"
              :data-tip="$t('My account')"
              :aria-label="$t('My account')"
            >
              <span class="avatar-live-wrap relative inline-flex shrink-0 sm:mr-2" v-if="$page.props.jetstream.managesProfilePhotos">
                <photo-zoom
                  :src="$page.props.auth.user.profile_photo_url"
                  :alt="$page.props.auth.user.name"
                  :size="32"
                  :zoom="128"
                  placement="below-end"
                  :img-class="'avatar-live-ring ring-2 ring-slate-100 group-hover:ring-blue-100 transition duration-150 ease-in-out'"
                />
                <span class="avatar-live-dot lg-tip" :data-tip="$t('Online')" :aria-label="$t('Online')"></span>
              </span>
              <span
                class="nb-c-name hidden sm:inline-flex items-center mr-0 sm:mr-2 !text-slate-700 dark:!text-slate-700 font-semibold opacity-100 text-xs md:text-xs lg:text-sm text-left lg-tip"
                :data-tip="$page.props.auth.user.name"
               :aria-label="$page.props.auth.user.name">
                {{ userDisplayName }}
                <icons name="chevron-down" class="hidden lg:inline-block ml-2 -mr-1" />
              </span>
            </button>
          </template>

          <template #content>
            <div class="pm">
              <div class="pm-head">
                <photo-zoom
                  v-if="$page.props.jetstream.managesProfilePhotos"
                  :src="$page.props.auth.user.profile_photo_url"
                  :alt="$page.props.auth.user.name"
                  :size="60"
                  :zoom="168"
                  placement="corner"
                  :img-class="'pm-avatar'"
                />
                <div class="min-w-0">
                  <div class="pm-name">{{ $page.props.auth.user.name }}</div>
                  <div class="pm-email">{{ $page.props.auth.user.email }}</div>
                  <div class="pm-tags">
                    <span class="pm-role">{{ userRoleLabel }}</span>
                    <span class="pm-online"><i></i>{{ $t('Online') }}</span>
                  </div>
                </div>
              </div>

              <div class="pm-list">
                <div v-if="proAllowed" class="pm-item pm-style" @click.stop>
                  <span class="profile-menu-icon-chip chip-violet"><icons name="collection" class="h-4 w-4" /></span>
                  <span class="min-w-0"><span class="pm-title">{{ $t('Menu style') }}</span><span class="pm-sub">{{ $t('Classic or Pro') }}</span></span>
                  <span class="pm-seg" role="group" :aria-label="$t('Menu style')"><button type="button" :class="{ on: menuStyle !== 'pro' }" @click.stop.prevent="setMenuStyle('classic')">{{ $t('Classic') }}</button><button type="button" :class="{ on: menuStyle === 'pro' }" @click.stop.prevent="setMenuStyle('pro')">{{ $t('Pro') }}</button></span>
                </div>
                <Link :href="route('profile.show')" class="pm-item">
                  <span class="profile-menu-icon-chip chip-blue"><icons name="user" class="h-4 w-4" /></span>
                  <span class="min-w-0"><span class="pm-title">{{ $t('Profile') }}</span><span class="pm-sub">{{ $t('Account, photo and security') }}</span></span>
                </Link>
                <Link v-if="$can('read-task-reminders') && taskRemindersEnabled" :href="route('task-reminders.index')" class="pm-item">
                  <span class="profile-menu-icon-chip chip-amber"><icons name="clock" class="h-4 w-4" /></span>
                  <span class="min-w-0"><span class="pm-title">{{ $t('My Tasks') }}</span><span class="pm-sub">{{ $t('Reminders assigned to you') }}</span></span>
                </Link>
                <Link v-if="$can(['read-case-tracking', 'read-all-case-tracking']) && caseTrackingEnabled" :href="route('case-tracking.mine')" class="pm-item">
                  <span class="profile-menu-icon-chip chip-crimson"><icons name="exclamation" class="h-4 w-4" /></span>
                  <span class="min-w-0"><span class="pm-title">{{ $t('Track Cases') }}</span><span class="pm-sub">{{ $t('Cases you follow') }}</span></span>
                </Link>
                <!-- WIMS Licence: Super Admins (permission read-wims-licence); the Licence Manager is Dr Hajas only (hard-coded on the server) -->
                <Link v-if="$page.props.licenceManager" :href="route('licence-manager.index')" class="pm-item">
                  <span class="profile-menu-icon-chip chip-crimson"><icons name="lock-closed" class="h-4 w-4" /></span>
                  <span class="min-w-0"><span class="pm-title">{{ $t('Licence Manager') }}</span><span class="pm-sub">{{ $t('Issue and control licences') }}</span></span>
                </Link>
                <Link v-if="($can('read-wims-licence') || $page.props.licenceManager) && $page.props.licenceViewAllowed !== false" :href="route('wims-licence.show')" class="pm-item">
                  <span class="profile-menu-icon-chip chip-emerald"><icons name="check-double" class="h-4 w-4" /></span>
                  <span class="min-w-0"><span class="pm-title">{{ $t('WIMS Licence') }}</span><span class="pm-sub" :class="'pm-licence-' + ($page.props.licenceStatus?.state || 'active')">{{ $page.props.licenceStatus?.label || $t('Licence status and key') }}</span></span>
                </Link>
                <!-- Knowledge base: opens /wimshow in a new tab once it is live (setting knowledge_base_live); until then a "coming soon" box with a countdown -->
                <a :href="kbLive ? '/wimshow' : '#'" :target="kbLive ? '_blank' : null" rel="noopener" class="pm-item" @click="openKnowledgeBase">
                  <span class="profile-menu-icon-chip chip-teal"><icons name="doc-text" class="h-4 w-4" /></span>
                  <span class="min-w-0"><span class="pm-title">{{ $t('Knowledge Base') }}</span><span class="pm-sub">{{ kbLive ? $t('Guides for every function') : $t('Under development, opens soon') }}</span></span>
                </a>
                <Link v-if="$page.props.hasMyRansId" :href="route('id-passes.my-id')" class="pm-item">
                  <span class="profile-menu-icon-chip chip-blue"><icons name="user" class="h-4 w-4" /></span>
                  <span class="min-w-0"><span class="pm-title">{{ $t('My ID') }}</span><span class="pm-sub">{{ $t('See your RANS Staff ID card') }}</span></span>
                </Link>
                <!-- Install WIMS as an app: knows whether it is already installed on this device -->
                <button type="button" class="pm-item" :class="{ 'pm-installed': pwa.standalone || (pwa.installed && !pwa.canPrompt) }" @click="installPwa">
                  <span class="profile-menu-icon-chip" :class="pwa.standalone || (pwa.installed && !pwa.canPrompt) ? 'chip-emerald' : 'chip-blue'"><icons :name="pwa.standalone || (pwa.installed && !pwa.canPrompt) ? 'tick' : 'download'" class="h-4 w-4" /></span>
                  <span class="min-w-0"><span class="pm-title">{{ $t('Install WIMS App') }}</span><span class="pm-sub">{{ pwaSub }}</span></span>
                </button>
                <Link v-if="$page.props.jetstream.hasApiFeatures" :href="route('api-tokens.index')" class="pm-item">
                  <span class="profile-menu-icon-chip chip-violet"><icons name="key" class="h-4 w-4" /></span>
                  <span class="min-w-0"><span class="pm-title">{{ $t('API Tokens') }}</span><span class="pm-sub">{{ $t('Manage access tokens') }}</span></span>
                </Link>
              </div>

              <form class="pm-foot" @submit.prevent="logout">
                <button type="submit" class="pm-item pm-logout">
                  <span class="profile-menu-icon-chip chip-rose"><icons name="out" class="h-4 w-4" /></span>
                  <span class="min-w-0"><span class="pm-title">{{ $t('Log Out') }}</span><span class="pm-sub">{{ $t('End this session') }}</span></span>
                </button>
              </form>
            </div>
          </template>
        </tec-dropdown>
      </div>
      <orbit-line v-if="pro" class="ol-bar" />
    </div>

    <pwa-install-dialog v-if="pwaDialog" :kind="pwaDialog.kind" :guide="pwaDialog.guide" @close="pwaDialog = null" />
    <knowledge-base-soon v-if="showKb" :launch-at="kbLaunchAt" @close="showKb = false" />

    <teleport to="body">
      <div v-if="attendanceNudgeVisible" class="da-nudge">
        <icons name="clock" class="w-4 h-4 flex-none" />
        <span class="da-nudge-text">{{ $t("You haven't clocked in today.") }}</span>
        <button type="button" class="da-nudge-btn" :disabled="attendanceProcessing" @click="nudgeClockIn">{{ $t('Clock In') }}</button>
        <button type="button" class="da-nudge-close" @click="dismissAttendanceNudge"><icons name="cross" class="w-3.5 h-3.5" /></button>
      </div>
    </teleport>
  </div>
</template>

<script>
import { groupWarehouses, progressOf } from '@/Core/warehouseGroups';
import PhotoZoom from '@/Shared/Page/PhotoZoom.vue';
import IconMenus from './IconMenus.vue';
import AnimatedLogo from '@/Shared/AnimatedLogo.vue';
import OrbitLine from '@/Shared/Top/OrbitLine.vue';
import { applyMenuStyle, pageAllowsPro } from '@/Core/menuStyle';
import { LANGUAGES, loadLocale } from '@/Core/i18n.js';
import { SITE_ENGINEER_ROLES } from '@/Core/siteEngineerRoles';
import QuickCreateMenu from '@/Shared/Top/QuickCreateMenu.vue';
import TecDropdown from '@/Jetstream/Dropdown.vue';
import TecDropdownLink from '@/Jetstream/DropdownLink.vue';
import MobileMenuIcon from '@/Shared/Top/MobileMenuIcon.vue';
import TecApplicationLogo from '@/Jetstream/ApplicationLogo.vue';
import TecApplicationMark from '@/Jetstream/ApplicationMark.vue';
import Icons from '@/Shared/Icons.vue';
import ThemeIcon from '@/Shared/Top/ThemeIcon.vue';
import StatusIndicator from '@/Shared/Top/StatusIndicator.vue';
import KnowledgeBaseSoon from '@/Shared/KnowledgeBaseSoon.vue';
import { pwa, startPwaWatch, installApp, installGuide } from '@/Core/pwaInstall';
import PwaInstallDialog from '@/Shared/PwaInstallDialog.vue';
import { clearAllStores } from '@/Core/offlineDb';
import PrayerPill from '@/Shared/Prayer/PrayerPill.vue';
import PrayerDrawer from '@/Shared/Prayer/PrayerDrawer.vue';
import { prayerState, adhan as adhanState } from '@/Shared/Prayer/prayerStore';
import { DEFAULT_SHORTCUT, formatShortcut } from '@/Shared/Rauncher/shortcut';

export default {
  props: {
    mobileMenuOpen: { type: Boolean, default: false },
    sidebarHidden: { type: Boolean, default: false },
    // Offline read mode shows no side menu, so the tall logo card collapses to its compact form.
    menuUnavailable: { type: Boolean, default: false },
    // the Pro menu carries the logo itself on a wide screen; below that the bar keeps its own logo card (with the animated house)
    pro: { type: Boolean, default: false },
  },
  emits: ['on-mobile-menu-change', 'on-sidebar-toggle'],
  components: {
    AnimatedLogo,
    OrbitLine,
    ThemeIcon,
    QuickCreateMenu,
    PhotoZoom,
    IconMenus,
    Icons,
    TecDropdown,
    MobileMenuIcon,
    TecDropdownLink,
    TecApplicationLogo,
    TecApplicationMark,
    StatusIndicator,
    KnowledgeBaseSoon,
    PrayerPill,
    PrayerDrawer,
    PwaInstallDialog,
  },

  data() {
    return {
      pwa,
      pwaDialog: null,
      showKb: false,
      show: false,
      languages: LANGUAGES,
      warehouseSearch: '',
      theme: this.$theme || 'light',
      attendanceToday: null,
      attendanceNow: new Date(),
      attendancePollTimer: null,
      attendanceTickTimer: null,
      attendanceProcessing: false,
      attendanceSettings: null,
      attendanceNudgeVisible: false,
      attendanceSessionsExpanded: false,
      attendanceSessionsLoading: false,
      attendanceSessions: [],
    };
  },

  watch: {
    show(show) {
      this.$emit('on-mobile-menu-change', show);
    },
    mobileMenuOpen(open) {
      this.show = open;
    },
    '$page.component'() {
      this.theme = this.$theme || 'light';
    },
  },

  computed: {
    proAllowed() { return pageAllowsPro(this.$page, this.$can); },
    menuStyle() { return this.$page.props.auth?.user?.extra_attributes?.menu_style === 'pro' ? 'pro' : 'classic'; },
    pwaSub() {
      if (pwa.standalone) return this.$t('You are using the installed app');
      if (pwa.installed && !pwa.canPrompt) return this.$t('Already installed on this device');
      return pwa.canPrompt ? this.$t('Add WIMS to this device') : this.$t('Add WIMS to your home screen');
    },
    prayerOn() { return !!prayerState.data; },
    adhanPlaying() { return adhanState.playing; },
    prayerState() { return prayerState; },
    rauncherShortcutLabel() {
      return formatShortcut(this.$page.props.auth?.user?.extra_attributes?.launcher_shortcut || DEFAULT_SHORTCUT);
    },
    // The knowledge base opens in a new tab once `knowledge_base_live` is switched on in the settings table; until then a countdown box shows.
    kbLive() { return ['1', 1, true, 'true'].includes(this.$page.props.settings?.knowledge_base_live); },
    kbLaunchAt() { return this.$page.props.settings?.knowledge_base_launch_at || '2026-10-20T12:00:00+03:00'; },
    current() {
      return LANGUAGES.find(l => l.value == this.$root.$i18n.locale)?.flag || 'US';
    },
    myWarehouses() {
      return this.$page.props.myWarehouses || [];
    },
    currentWarehouse() {
      return this.$page.props.currentWarehouse || null;
    },
    userRoleLabel() {
      const roles = this.$page.props.auth?.user?.roles || [];
      if (Array.isArray(roles) && roles.length) {
        return roles.map(role => role.name).filter(Boolean).join(', ');
      }

      return 'User';
    },
    userRoleInitials() {
      const first = this.userRoleLabel.split(',')[0].trim();
      const words = first.split(/\s+/).filter(Boolean);
      if (!words.length) return 'U';
      return words.length === 1 ? words[0].slice(0, 2).toUpperCase() : (words[0][0] + words[1][0]).toUpperCase();
    },
    // the list in sections: Main Warehouses, Projects (Government and Private), Others
    warehouseSections() {
      return groupWarehouses(this.myWarehouses, this.warehouseSearch);
    },
    filteredWarehouses() {
      const q = this.warehouseSearch.trim().toLowerCase();
      if (!q) return this.myWarehouses;
      return this.myWarehouses.filter(w => (w.name || '').toLowerCase().includes(q));
    },
    isSiteEngineer() {
      const roles = this.$page.props.auth?.user?.roles || [];
      return roles.some(role => SITE_ENGINEER_ROLES.includes(role.name));
    },
    isStoreManager() {
      const roles = this.$page.props.auth?.user?.roles || [];
      return roles.some(role => role.name === 'Store Manager');
    },
    canCreateMaterialRequest() {
      const roles = this.$page.props.auth?.user?.roles || [];
      return roles.some(role => ['Project Manager', 'Project Managers'].includes(role.name));
    },
    showNewTransactions() {
      return (
        this.$can('create-checkins') ||
        this.$can('create-checkouts') ||
        (!this.isStoreManager && (this.$can('create-issue-returns') || this.$admin || this.isSiteEngineer)) ||
        (!this.isStoreManager && this.canCreateMaterialRequest)
      );
    },
    showNewCatalog() {
      return this.$can('create-items') || this.$can('create-categories') || this.$can('create-units');
    },
    showNewOrganization() {
      return this.$can('create-contacts') || this.$can('create-warehouses') || this.$can('create-notices');
    },
    showNewAdministration() {
      return this.$can('create-users') || this.$can('create-roles');
    },
    userDisplayName() {
      const name = this.$page.props.auth.user.name || '';
      return name.length > 9 ? name.slice(0, 9) + '…' : name;
    },
    taskRemindersEnabled() {
      return this.$page.props.enabledFeatures?.task_reminders !== false || this.$super;
    },
    caseTrackingEnabled() {
      return this.$page.props.enabledFeatures?.case_tracking !== false || this.$super;
    },
    darkModeFeatureEnabled() {
      return this.$page.props.enabledFeatures?.dark_mode !== false || this.$super;
    },
    attendanceWorkedLabel() {
      if (!this.attendanceToday || !this.attendanceToday.clock_in_at) return '';
      const start = new Date(this.attendanceToday.clock_in_at).getTime();
      const minutes = Math.max(0, Math.floor((this.attendanceNow.getTime() - start) / 60000));
      const h = Math.floor(minutes / 60);
      const m = minutes % 60;
      return `${h}:${String(m).padStart(2, '0')}`;
    },
    // HH:MM:SS for the panel's big clock face — attendanceWorkedLabel above
    // stays H:MM (no seconds) since that's also used in the compact topbar
    // badge, where ticking seconds would be noisy.
    //
    // Only ticks live against attendanceNow while actually CLOCKED_IN — once
    // clocked out (or missing a clock-out), clock_in_at is still set on
    // today's record, so counting elapsed-time-since-clock-in against the
    // current time would keep climbing forever past the real clock-out
    // instead of freezing. Anything else uses the server's own frozen
    // worked_minutes (DigitalAttendanceRecord::getWorkedMinutesLiveAttribute
    // already handles the accumulated/frozen total correctly).
    attendanceWorkedClock() {
      if (this.attendanceToday && this.attendanceToday.status === 'CLOCKED_IN' && this.attendanceToday.clock_in_at) {
        const start = new Date(this.attendanceToday.clock_in_at).getTime();
        return this.formatHms((this.attendanceNow.getTime() - start) / 1000);
      }

      return this.formatHms((this.attendanceToday?.worked_minutes || 0) * 60);
    },
    attendanceTodayLabel() {
      // attendanceToday can be a flagged prior-day record (MISSING_CLOCK_OUT
      // keeps surfacing until an admin corrects it) — label it with its own
      // attendance_date, not today's date, or the popover misleadingly reads
      // "Clocked In hours for <today>" against an older, still-open session.
      const dateStr = this.attendanceToday?.attendance_date;
      let date = new Date();
      if (dateStr) {
        const [y, m, d] = dateStr.split('-').map(Number);
        date = new Date(y, m - 1, d);
      }

      return new Intl.DateTimeFormat(undefined, { weekday: 'short', month: 'short', day: 'numeric' }).format(date);
    },
    attendancePillText() {
      if (this.attendanceToday && this.attendanceToday.status === 'CLOCKED_IN') return this.$t('Working');
      if (this.attendanceToday && this.attendanceToday.status === 'MISSING_CLOCK_OUT') return this.$t('Missing Clock-Out');
      return this.$t('Not Working');
    },
    // Counts up from this browser session's own login, independent of
    // Digital Attendance — resets to 0 only on a fresh login, not on page
    // reloads within the same session (sessionStartedAt is set once by
    // CaptureSuccessfulLoginAttempt and shared on every request after).
    sessionLoggedInClock() {
      const startedAt = this.$page.props.sessionStartedAt;
      if (!startedAt) return '00:00:00';
      const start = new Date(startedAt).getTime();
      return this.formatHms((this.attendanceNow.getTime() - start) / 1000);
    },
    // The panel's big clock face is contextual: while clocked in it tracks
    // the Digital Attendance session (attendanceWorkedClock); otherwise it
    // falls back to how long this browser session has been logged in.
    attendanceHeroLabel() {
      return this.attendanceToday && this.attendanceToday.status === 'CLOCKED_IN' ? this.$t('Clocked In Time') : this.$t('Logged In Time');
    },
    attendanceHeroClock() {
      return this.attendanceToday && this.attendanceToday.status === 'CLOCKED_IN' ? this.attendanceWorkedClock : this.sessionLoggedInClock;
    },
  },

  mounted() {
    startPwaWatch();
    window.addEventListener('scroll', this.onPageScroll, { passive: true });
    document.addEventListener('inertia:navigate', this.onPageScroll);
    if (this.$can('read-digital-attendance')) {
      this.fetchAttendanceWidget();
      this.attendancePollTimer = setInterval(() => !document.hidden && this.fetchAttendanceWidget(), 180000);
      this.attendanceTickTimer = setInterval(() => (this.attendanceNow = new Date()), 1000);
      // Clock in/out can also happen from MyAttendance.vue's own buttons —
      // a separate page component, not a child of this persistent layout —
      // so this listens for its broadcast instead of waiting up to 60s.
      window.addEventListener('digital-attendance:refresh', this.fetchAttendanceWidget);
    }
  },
  beforeUnmount() {
    window.removeEventListener('scroll', this.onPageScroll);
    document.removeEventListener('inertia:navigate', this.onPageScroll);
    if (this.pinFrame) cancelAnimationFrame(this.pinFrame);
    if (this.attendancePollTimer) clearInterval(this.attendancePollTimer);
    if (this.attendanceTickTimer) clearInterval(this.attendanceTickTimer);
    window.removeEventListener('digital-attendance:refresh', this.fetchAttendanceWidget);
  },

  methods: {
    // the menu changes at once; the choice is kept on the person's own settings
    setMenuStyle(style) {
      applyMenuStyle(this.$page, style);
    },
    // Phones: the logo bar and icon row stay pinned (CSS: .nb-root is sticky). Once the page scrolls, the project/role row is hidden
    // so the pinned part stays small; the space it took is kept as a margin so the content below does not jump when this happens.
    onPageScroll() {
      if (this.pinFrame) return;
      this.pinFrame = requestAnimationFrame(() => {
        this.pinFrame = 0;
        const root = this.$refs.navRoot;
        if (!root || !window.matchMedia('(max-width: 767.98px)').matches) {
          if (root && root.classList.contains('nb-pinned')) {
            root.classList.remove('nb-pinned');
            root.style.marginBottom = '';
          }
          return;
        }
        const y = window.scrollY || document.documentElement.scrollTop || 0;
        const pinned = root.classList.contains('nb-pinned');
        if (!pinned && y > 12) {
          const before = root.offsetHeight;
          root.classList.add('nb-pinned');
          root.style.marginBottom = Math.max(0, before - root.offsetHeight) + 'px';
          document.documentElement.style.setProperty('--nb-pin-h', root.offsetHeight + 'px');
        } else if (pinned && y <= 2) {
          root.classList.remove('nb-pinned');
          root.style.marginBottom = '';
        }
      });
    },
    // The Rauncher lives in AdminLayout (it also opens with the keyboard shortcut); this asks it to toggle.
    openRauncher() {
      window.dispatchEvent(new CustomEvent('wims:rauncher'));
    },
    // Install WIMS as an app: says so when it is already installed, uses the browser's own prompt when there is one, and otherwise
    // shows the steps for this browser (some people never get the browser's install notice, or use a browser that does not give it)
    async installPwa() {
      const done = await installApp();
      this.show = false;
      if (done === 'dismissed') return;
      this.pwaDialog = { kind: done, guide: done === 'manual' ? installGuide() : null };
    },
    openKnowledgeBase(event) {
      if (this.kbLive) return;
      event.preventDefault();
      this.show = false;
      this.showKb = true;
    },
    formatHms(totalSeconds) {
      const s = Math.max(0, Math.floor(totalSeconds));
      const h = Math.floor(s / 3600);
      const m = Math.floor((s % 3600) / 60);
      const sec = s % 60;
      return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`;
    },
    fetchAttendanceWidget() {
      axios.get(route('digital-attendance.widget')).then(res => {
        this.attendanceToday = res.data.today;
        this.attendanceSettings = res.data.settings;
        this.maybeShowAttendanceNudge();
        // A fresh clock-in/out can change today's session count — collapse
        // rather than risk showing a stale breakdown from before it changed.
        this.attendanceSessionsExpanded = false;
      });
    },
    // Multiple clock-in/out sessions on the same day are all rolled into
    // one DigitalAttendanceRecord (see sessions_count on it) — this expands
    // that single "Clocked In hours" total into its actual per-session
    // breakdown, same data + presentation as DigitalAttendance/History.vue's
    // own session chip, just inline in the compact panel.
    toggleAttendanceSessions() {
      if (this.attendanceSessionsExpanded) {
        this.attendanceSessionsExpanded = false;
        return;
      }
      this.attendanceSessionsExpanded = true;
      if (!this.attendanceToday?.id) return;
      this.attendanceSessionsLoading = true;
      axios.get(route('digital-attendance.history-sessions', this.attendanceToday.id)).then(res => {
        this.attendanceSessions = res.data.sessions || [];
        this.attendanceSessionsLoading = false;
      });
    },
    maybeShowAttendanceNudge() {
      if (this.attendanceToday || !this.attendanceSettings?.enabled) return;
      if (window.sessionStorage.getItem('da_nudge_dismissed') === '1') return;
      if (!this.$can('clock-in-digital-attendance')) return;

      const now = new Date();
      const [startH, startM] = (this.attendanceSettings.working_start || '08:00').split(':').map(Number);
      const [endH, endM] = (this.attendanceSettings.working_end || '17:00').split(':').map(Number);
      const minutesNow = now.getHours() * 60 + now.getMinutes();
      const startMinutes = startH * 60 + startM;
      const endMinutes = endH * 60 + endM;

      if (minutesNow >= startMinutes && minutesNow <= endMinutes) {
        this.attendanceNudgeVisible = true;
      }
    },
    dismissAttendanceNudge() {
      this.attendanceNudgeVisible = false;
      window.sessionStorage.setItem('da_nudge_dismissed', '1');
    },
    nudgeClockIn() {
      this.dismissAttendanceNudge();
      this.navClockIn();
    },
    captureAttendanceLocation() {
      return new Promise(resolve => {
        if (!navigator.geolocation) {
          resolve({ location_status: 'unavailable' });
          return;
        }
        navigator.geolocation.getCurrentPosition(
          position => resolve({
            location_status: 'captured',
            latitude: position.coords.latitude,
            longitude: position.coords.longitude,
            accuracy: position.coords.accuracy,
          }),
          error => resolve({ location_status: error.code === 1 ? 'denied' : 'unavailable' }),
          { enableHighAccuracy: true, timeout: 10000 }
        );
      });
    },
    // Best-effort — see the matching, more heavily-commented copy in
    // MyAttendance.vue for why this can legitimately resolve to null.
    detectLocalIp() {
      return new Promise(resolve => {
        if (typeof RTCPeerConnection === 'undefined') { resolve(null); return; }
        let done = false;
        const finish = ip => {
          if (done) return;
          done = true;
          clearTimeout(timer);
          try { pc.close(); } catch (e) {}
          resolve(ip);
        };
        let pc;
        try {
          pc = new RTCPeerConnection({ iceServers: [] });
          pc.createDataChannel('');
          pc.onicecandidate = e => {
            if (!e.candidate) { finish(null); return; }
            const match = /([0-9]{1,3}(?:\.[0-9]{1,3}){3})/.exec(e.candidate.candidate);
            if (match && !match[1].startsWith('0.')) finish(match[1]);
          };
          pc.createOffer().then(offer => pc.setLocalDescription(offer)).catch(() => finish(null));
        } catch (e) {
          resolve(null);
          return;
        }
        var timer = setTimeout(() => finish(null), 1500);
      });
    },
    async navClockIn() {
      this.attendanceProcessing = true;
      const [location, localIp] = await Promise.all([this.captureAttendanceLocation(), this.detectLocalIp()]);
      location.local_ip = localIp;
      this.$inertia.post(route('digital-attendance.clock-in'), location, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
          this.attendanceProcessing = false;
          this.fetchAttendanceWidget();
          window.dispatchEvent(new Event('digital-attendance:refresh'));
        },
      });
    },
    async navClockOut() {
      this.attendanceProcessing = true;
      const [location, localIp] = await Promise.all([this.captureAttendanceLocation(), this.detectLocalIp()]);
      location.local_ip = localIp;
      this.$inertia.post(route('digital-attendance.clock-out'), location, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
          this.attendanceProcessing = false;
          this.fetchAttendanceWidget();
          window.dispatchEvent(new Event('digital-attendance:refresh'));
        },
      });
    },
    logout() {
      clearAllStores();
      this.$inertia.post(route('logout'));
    },

    progressOf,
    // the hover text of a row: its category and, for a project, whether it is finished or still running
    catText(w) {
      const p = progressOf(w);
      return (w.category || '') + (p ? (w.category ? ' - ' : '') + (p === 'done' ? 'Finished' : 'In progress') : '') || null;
    },
    selectWarehouse(warehouseId) {
      this.warehouseSearch = '';
      if (this.currentWarehouse && this.currentWarehouse.id === warehouseId) return;
      this.$inertia.post(route('current.warehouse'), { warehouse_id: warehouseId }, { preserveScroll: true });
    },

    changeLang(lang) {
      this.$inertia.visit('/language/' + lang, {
        onFinish: async () => {
          window.Locale = lang;
          await loadLocale(lang);
          document.querySelector('html').setAttribute('lang', lang);
          this.$root.$i18n.locale = lang;
        },
      });
    },

    toggleTheme() {
      const next = this.theme === 'dark' ? 'light' : 'dark';
      this.theme = next;
      this.$setTheme(next);
    },
  },
};
</script>

<style scoped>
.liquid-side-rail {
  border-right: 1px solid rgba(255, 255, 255, 0.14);
  background: #0a1637;
  box-shadow:
    inset -1px 0 0 rgba(255, 255, 255, 0.08),
    inset 0 1px 0 rgba(255, 255, 255, 0.08);
}

.wh-picker-trigger {
  background: linear-gradient(145deg, #eff6ff, #f8fafc);
  border: 1px solid #dbeafe;
  transition: background 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
}
.wh-picker-trigger:hover {
  background: linear-gradient(145deg, #dbeafe, #eff6ff);
  border-color: #bfdbfe;
  box-shadow: 0 2px 8px rgba(37, 99, 235, 0.1);
}
.wh-picker-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 26px;
  width: 26px;
  border-radius: 8px;
  flex-shrink: 0;
  background: #2563eb;
  color: #ffffff;
  box-shadow: 0 2px 5px rgba(37, 99, 235, 0.35);
}
.wh-picker-label {
  font-size: 0.66rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #60a5fa;
}
.wh-picker-value {
  font-size: 0.83rem;
  font-weight: 600;
  color: #1e3a8a;
}
.wh-picker-chevron {
  color: #93c5fd;
}
.wh-picker-panel-header {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 10px 16px 6px;
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #2563eb;
}
.wh-picker-scroll {
  flex: 1 1 auto;
  min-height: 0;
  max-height: min(60vh, 22rem);
  overflow-y: auto;
  overscroll-behavior: contain;
}
.wh-picker-search-icon {
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  width: 14px;
  height: 14px;
  color: #94a3b8;
  pointer-events: none;
}
.wh-picker-search-input {
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  padding-top: 0.4rem;
  padding-bottom: 0.4rem;
}
.wh-picker-search-input:focus {
  background: #ffffff;
  border-color: #93c5fd;
  box-shadow: 0 0 0 3px rgba(147, 197, 253, 0.3);
  outline: none;
}
.wh-picker-item {
  display: flex;
  align-items: center;
  gap: 7px;
  color: #475569;
  width: 100%;
  font-size: 0.74rem;
  line-height: 1.25;
}
.wh-picker-item :deep(svg) {
  width: 14px;
  height: 14px;
}
.wh-picker-item :deep(svg:first-child) {
  color: #94a3b8;
}
.wh-picker-item-active {
  color: #1d4ed8;
  font-weight: 600;
}
.wh-picker-item-active :deep(svg:first-child) {
  color: #2563eb;
}
.wh-picker-check {
  margin-left: auto;
  width: 14px;
  height: 14px;
  color: #2563eb;
  flex-shrink: 0;
}

/* the sections of the project / warehouse list: Main Warehouses, Projects (Government and Private), Others */
.wh-sec { padding-bottom: 4px; }
.wh-sec + .wh-sec { border-top: 1px solid #eef2f7; margin-top: 2px; }
.wh-sec-head { --sc: #2563eb; display: flex; align-items: center; gap: 8px; padding: 9px 16px 4px; font-size: 0.62rem; font-weight: 800; letter-spacing: 0.09em; text-transform: uppercase; color: var(--sc); }
.wh-sec-blue { --sc: #2563eb; } .wh-sec-emerald { --sc: #059669; } .wh-sec-slate { --sc: #64748b; }
.wh-sec-ico { display: inline-grid; place-items: center; width: 20px; height: 20px; border-radius: 7px; background: #fff; box-shadow: inset 0 0 0 1.5px var(--sc), 0 4px 7px -4px var(--sc); }
.wh-sec-name { flex: 1; min-width: 0; }
.wh-sec-count { min-width: 20px; padding: 0 6px; border-radius: 999px; background: color-mix(in srgb, var(--sc) 12%, transparent); color: var(--sc); text-align: center; font-size: 0.62rem; line-height: 1.6; }
.wh-sec .wh-picker-item { padding-left: 8px; }
/* the category (Main Warehouse, Government, Private, Directors...) shows as a small tooltip on hover or keyboard focus, inside the row so the scroll area never clips it */
.wh-has-cat { position: relative; }
.wh-dot { width: 8px; height: 8px; border-radius: 50%; } .wh-dot-done { background: #10b981; box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.25); } .wh-dot-running { background: #f97316; box-shadow: 0 0 0 2px rgba(249, 115, 22, 0.25); }
.wh-has-cat[data-cat]::after { content: 'Category: ' attr(data-cat); position: absolute; right: 22px; top: 50%; transform: translateY(-50%) scale(0.96); z-index: 20; padding: 3px 9px; border-radius: 8px; background: #0f2540; color: #fff; font-size: 0.64rem; font-weight: 700; letter-spacing: 0.02em; line-height: 1.4; white-space: nowrap; box-shadow: 0 8px 16px -6px rgba(15, 37, 64, 0.55); opacity: 0; pointer-events: none; transition: opacity 0.12s ease, transform 0.12s ease; }
.wh-has-cat[data-cat]:hover::after, button:focus-visible .wh-has-cat[data-cat]::after { opacity: 1; transform: translateY(-50%) scale(1); }

.nb-new-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.5rem 0.9rem;
  border: 0;
  border-radius: 0.65rem;
  background: linear-gradient(145deg, #3b82f6, #1d4ed8);
  color: #fff;
  font-weight: 600;
  font-size: 0.875rem;
  box-shadow: 0 2px 6px -1px rgba(29, 78, 216, 0.4), 0 0 0 1px rgba(29, 78, 216, 0.1);
  transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}
.nb-new-btn:hover {
  background: linear-gradient(145deg, #60a5fa, #2563eb);
  box-shadow: 0 4px 12px -1px rgba(29, 78, 216, 0.45), 0 0 0 1px rgba(29, 78, 216, 0.15);
  transform: translateY(-1px);
}
.nb-new-btn:active {
  transform: translateY(0) scale(0.97);
}

.nb-new-panel-header {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 10px 16px 6px;
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #2563eb;
}
.nb-new-scroll {
  flex: 1 1 auto;
  min-height: 0;
  max-height: min(70vh, 26rem);
  overflow-y: auto;
  overscroll-behavior: contain;
  padding-bottom: 6px;
}
.nb-new-section-label {
  padding: 10px 16px 4px;
  font-size: 0.66rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #94a3b8;
}
.nb-new-item {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  color: #334155;
  font-weight: 500;
}
.nb-new-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.9rem;
  height: 1.9rem;
  border-radius: 0.6rem;
  flex-shrink: 0;
  transition: transform 0.15s ease;
}
.nb-new-icon-blue { background: linear-gradient(145deg, #dbeafe, #bfdbfe); color: #1d4ed8; }
.nb-new-icon-amber { background: linear-gradient(145deg, #fef3c7, #fde68a); color: #b45309; }
.nb-new-icon-emerald { background: linear-gradient(145deg, #d1fae5, #a7f3d0); color: #047857; }
.nb-new-icon-violet { background: linear-gradient(145deg, #ede9fe, #ddd6fe); color: #6d28d9; }
.nb-new-arrow {
  margin-left: auto;
  width: 14px;
  height: 14px;
  flex-shrink: 0;
  color: #cbd5e1;
  transform: rotate(-90deg);
  opacity: 0;
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.nb-new-item:hover .nb-new-arrow {
  opacity: 1;
  transform: rotate(-90deg) translateX(2px);
}
.nb-new-item:hover .nb-new-icon {
  transform: scale(1.06);
}

.nb-role-chip {
  background: linear-gradient(145deg, #f8fafc, #f1f5f9);
  border: 1px solid #e2e8f0;
  color: #091738;
  transition: background 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
}
.nb-role-chip:hover {
  background: linear-gradient(145deg, #eef2ff, #e0e7ff);
  border-color: #c7d2fe;
  box-shadow: 0 2px 8px rgba(99, 102, 241, 0.1);
}
.nb-role-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.5rem;
  height: 1.5rem;
  border-radius: 9999px;
  background: #e2e8f0;
  color: #475569;
  flex-shrink: 0;
  transition: background 0.15s ease, color 0.15s ease;
}
.nb-role-chip:hover .nb-role-icon { background: #c7d2fe; color: #4338ca; }
.nb-role-icon-sm { width: 1.3rem; height: 1.3rem; }
.nb-role-dot { width: 0.4rem; height: 0.4rem; border-radius: 9999px; background: #22c55e; flex-shrink: 0; }
.nb-role-dot-corner { position: absolute; top: 0.3rem; right: 0.3rem; }
.nb-role-chip-sm { color: #334155; }

.nb-role-icon-btn {
  background: linear-gradient(145deg, #f8fafc, #f1f5f9);
  border: 1px solid #e2e8f0;
  color: #475569;
  transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}
.nb-role-icon-btn:hover, .nb-role-icon-btn:focus { background: linear-gradient(145deg, #eef2ff, #e0e7ff); border-color: #c7d2fe; color: #4338ca; outline: none; }
.nb-role-initials { font-size: 0.75rem; font-weight: 800; letter-spacing: 0.02em; line-height: 1; color: inherit; }


/* ---- User role (desktop): flat, no container - blue badge (same blue as the + button), light text, tight live dot ---- */
.nb-role-chip.nb-role-chip-lg {
  display: inline-flex; align-items: center; gap: 8px; padding: 0 2px; border-radius: 0; min-width: 0; cursor: default; background: transparent; border: 0; box-shadow: none;
  -webkit-backdrop-filter: none; backdrop-filter: none; width: auto;
}
.nb-role-chip.nb-role-chip-lg:hover, .nb-role-chip.nb-role-chip-lg:focus-visible { outline: none; background: transparent; box-shadow: none; border: 0; }
.nb-role-chip-lg .nb-role-icon { width: 2rem; height: 2rem; border-radius: 10px; color: #fff; background: #3b82f6; box-shadow: none; }
.nb-role-chip-lg:hover .nb-role-icon { background: #2563eb; color: #fff; }
.nb-role-text { display: flex; flex-direction: column; flex: 0 1 auto; min-width: 0; line-height: 1.15; }
.nb-role-text small { font-size: 0.66rem; font-weight: 500; letter-spacing: 0.06em; text-transform: uppercase; color: #64748b; }
.nb-role-text b { font-size: 0.84rem; font-weight: 500; color: #1e293b; white-space: nowrap; }
.nb-role-dot-live { position: relative; flex: none; width: 0.5rem; height: 0.5rem; margin-left: 2px; }
.nb-role-dot-live::after { content: ''; position: absolute; inset: -4px; border-radius: 9999px; border: 2px solid #22c55e; opacity: 0; animation: nb-role-live 2s ease-out infinite; }
@keyframes nb-role-live { 0% { transform: scale(0.5); opacity: 0.8; } 100% { transform: scale(1.6); opacity: 0; } }

/* ---- Role chip on small screens: same line as the + button; badge with initials (e.g. SA), no live dot ---- */
.nb-role-initials-badge { display: none; font-size: 0.72rem; font-weight: 600; letter-spacing: 0.02em; line-height: 1; }
@media (max-width: 1023.98px) {
  .nb-role-chip-lg .nb-role-text, .nb-role-chip-lg .nb-role-dot-live { display: none; }
  .nb-role-chip-lg .nb-role-key { display: none; }
  .nb-role-chip-lg .nb-role-initials-badge { display: inline; }
  .nb-role-chip.nb-role-chip-lg { padding: 0; }
  .nb-role-chip-lg .nb-role-icon { width: 2.5rem; height: 2.5rem; border-radius: 12px; color: #2563eb; background: #e8f0fe; }
  .nb-role-chip-lg:hover .nb-role-icon { background: #dbe8fd; color: #1d4ed8; }
}
@media (max-width: 767.98px) {
  .nb-root .nb-left { container-type: inline-size; container-name: nbleft; }
}
/* Wide enough on a phone-sized layout (landscape / large phones): show the full role text again, still without the dot. */
@container nbleft (min-width: 560px) {
  .nb-role-chip-lg .nb-role-text { display: flex; }
  .nb-role-chip-lg .nb-role-key { display: block; }
  .nb-role-chip-lg .nb-role-initials-badge { display: none; }
  .nb-role-chip.nb-role-chip-lg { padding: 0 2px; }
  .nb-role-chip-lg .nb-role-icon { width: 2rem; height: 2rem; border-radius: 10px; color: #fff; background: #3b82f6; }
}
html.dark .nb-role-chip.nb-role-chip-lg { background: transparent; border: 0; }
html.dark .nb-role-text b { color: #e2e8f0; }
html.dark .nb-role-text small { color: #94a3b8; }
@media (prefers-reduced-motion: reduce) { .nb-role-dot-live::after { animation: none; } }

/* Pure-CSS tooltip, shown on hover/focus — the latter lets a tap register
   it on touch devices before the click's own action fires. */
.nb-tooltip { position: relative; }
.nb-tooltip::after {
  content: attr(data-tooltip);
  position: absolute;
  top: calc(100% + 0.5rem);
  left: 0;
  max-width: min(80vw, 16rem);
  white-space: normal;
  background: #0f172a;
  color: #f1f5f9;
  font-size: 0.7rem;
  font-weight: 600;
  padding: 0.4rem 0.65rem;
  border-radius: 0.5rem;
  box-shadow: 0 6px 16px -4px rgba(15, 23, 42, 0.35);
  opacity: 0;
  pointer-events: none;
  transform: translateY(-4px);
  transition: opacity 0.15s ease, transform 0.15s ease;
  z-index: 60;
}
.nb-tooltip:hover::after, .nb-tooltip:focus::after, .nb-tooltip:focus-visible::after { opacity: 1; transform: translateY(0); }

html.dark .nb-role-chip { background: linear-gradient(145deg, rgba(15, 23, 42, 0.6), rgba(15, 23, 42, 0.4)); border-color: rgba(255, 255, 255, 0.1); color: #e2e8f0; }
html.dark .nb-role-chip:hover { background: linear-gradient(145deg, rgba(30, 41, 59, 0.85), rgba(30, 41, 59, 0.65)); border-color: rgba(129, 140, 248, 0.35); }
html.dark .nb-role-icon-btn { background: linear-gradient(145deg, rgba(15, 23, 42, 0.6), rgba(15, 23, 42, 0.4)); border-color: rgba(255, 255, 255, 0.1); color: #cbd5e1; }
html.dark .nb-role-icon-btn:hover, html.dark .nb-role-icon-btn:focus { background: linear-gradient(145deg, rgba(30, 41, 59, 0.85), rgba(30, 41, 59, 0.65)); border-color: rgba(129, 140, 248, 0.35); color: #c7d2fe; }
html.dark .nb-role-icon { background: rgba(255, 255, 255, 0.08); color: #94a3b8; }
html.dark .nb-role-chip:hover .nb-role-icon { background: rgba(129, 140, 248, 0.25); color: #c7d2fe; }

.pm { overflow: visible; }
.pm-head { display: flex; align-items: center; gap: 14px; padding: 16px 16px 14px; border-radius: 12px 12px 0 0; background: linear-gradient(135deg, #e8eefc, #fff8e7); border-bottom: 1px solid #eef1f6; }
.pm-head :deep(.pm-avatar) { box-shadow: 0 0 0 3px #fff, 0 10px 18px -8px rgba(28, 63, 102, 0.55); }
.pm-name { font-size: 1rem; font-weight: 800; color: #0f172a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pm-email { margin-top: 1px; font-size: 0.75rem; color: #64748b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pm-tags { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 7px; }
.pm-role { max-width: 100%; padding: 2px 9px; border-radius: 9999px; font-size: 0.66rem; font-weight: 800; color: #1c3f66; background: rgba(28, 63, 102, 0.1); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pm-online { display: inline-flex; align-items: center; gap: 5px; font-size: 0.66rem; font-weight: 800; color: #047857; }
.pm-online i { width: 7px; height: 7px; border-radius: 9999px; background: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2); }
.pm-list { padding: 8px; }
.pm-item { display: flex; align-items: center; gap: 12px; width: 100%; padding: 8px 10px; border-radius: 12px; text-align: left; transition: background 0.15s ease, transform 0.15s ease; }
.pm-item:hover { background: #f1f5fd; transform: translateX(2px); }
.pm-item .profile-menu-icon-chip { width: 36px; height: 36px; border-radius: 11px; }
.pm-title { display: block; font-size: 0.86rem; font-weight: 800; color: #1e293b; }
.pm-sub { display: block; margin-top: 1px; font-size: 0.7rem; color: #94a3b8; }
.pm-foot { padding: 8px; border-top: 1px solid #eef1f6; }
.pm-logout:hover { background: #fff1f2; }
.pm-logout .pm-title { color: #be123c; }
html.dark .pm-head { background: linear-gradient(135deg, rgba(28, 63, 102, 0.5), rgba(15, 23, 42, 0.6)); border-color: rgba(255, 255, 255, 0.08); }
html.dark .pm-name, html.dark .pm-title { color: #f1f5f9; }
html.dark .pm-item:hover { background: rgba(255, 255, 255, 0.06); }
html.dark .pm-foot { border-color: rgba(255, 255, 255, 0.08); }

.profile-menu-icon-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 8px;
  flex-shrink: 0;
}
.chip-blue {
  background: #eff6ff;
  color: #2563eb;
}
.chip-violet {
  background: #f5f3ff;
  color: #7c3aed;
}
.chip-rose {
  background: #fff1f2;
  color: #e11d48;
}
.chip-amber {
  background: #fffbeb;
  color: #d97706;
}
.chip-crimson {
  background: #fff1f2;
  color: #9f1239;
}
.chip-teal {
  background: #f0fdfa;
  color: #0d9488;
}
.chip-emerald {
  background: #ecfdf5;
  color: #059669;
}
.pm-licence-active { color: #059669 !important; font-weight: 700; }
.pm-licence-expiring { color: #d97706 !important; font-weight: 700; }
.pm-licence-expired, .pm-licence-deactivated, .pm-licence-none { color: #e11d48 !important; font-weight: 700; }

.avatar-live-wrap {
  line-height: 0;
}
.avatar-live-ring {
  animation: avatar-live-glow 2.6s ease-in-out infinite;
}
.avatar-live-dot {
  position: absolute;
  right: -1px;
  bottom: -1px;
  height: 9px;
  width: 9px;
  border-radius: 9999px;
  background: #22c55e;
  border: 2px solid #ffffff;
  animation: avatar-live-pulse 2.2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

@keyframes avatar-live-glow {
  0%, 100% {
    box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.35);
  }
  50% {
    box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.16);
  }
}

@keyframes avatar-live-pulse {
  0% {
    box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.6);
  }
  70% {
    box-shadow: 0 0 0 6px rgba(34, 197, 94, 0);
  }
  100% {
    box-shadow: 0 0 0 0 rgba(34, 197, 94, 0);
  }
}

@media (prefers-reduced-motion: reduce) {
  .avatar-live-ring,
  .avatar-live-dot {
    animation: none;
  }
}

.da-nav-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  height: 2.4rem;
  padding: 0 0.7rem 0 0.4rem;
  border: 1px solid rgba(148, 163, 184, 0.35);
  border-radius: 0.75rem;
  background: #f8fafc;
  color: #475569;
  font-size: 0.78rem;
  font-weight: 700;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
  transition: background 0.2s ease, color 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.da-nav-btn:hover {
  background: #ffffff;
  border-color: rgba(100, 116, 139, 0.5);
  box-shadow: 0 6px 16px -6px rgba(15, 23, 42, 0.22);
  transform: translateY(-1px);
}
.da-nav-btn:active { transform: translateY(0) scale(0.97); }

/* Icon chip — its own little gradient badge rather than a bare glyph, with
   the "clocked in" pulse living as a small corner dot instead of replacing
   the icon outright, so the clock glyph never disappears. */
.da-nav-btn-icon {
  position: relative;
  display: inline-flex; align-items: center; justify-content: center;
  width: 1.6rem; height: 1.6rem; flex-shrink: 0;
  border-radius: 0.55rem;
  background: linear-gradient(145deg, #cbd5e1, #94a3b8);
  color: #fff;
  box-shadow: 0 2px 6px -2px rgba(71, 85, 105, 0.45), inset 0 1px 0 rgba(255, 255, 255, 0.3);
  transition: background 0.2s ease, box-shadow 0.2s ease;
}
.da-nav-dot {
  position: absolute; top: -0.15rem; right: -0.15rem;
  width: 0.55rem; height: 0.55rem; border-radius: 9999px;
  background: #10b981; border: 2px solid #f8fafc;
  animation: da-nav-blink 1.8s ease-in-out infinite;
}
.da-nav-btn:hover .da-nav-dot { border-color: #ffffff; }
@keyframes da-nav-blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.45; } }
@media (prefers-reduced-motion: reduce) { .da-nav-dot { animation: none; } }
.da-nav-time { font-variant-numeric: tabular-nums; }

.da-nav-btn-in {
  background: #ecfdf5; color: #047857;
  border-color: rgba(16, 185, 129, 0.45);
  box-shadow: 0 0 0 1px rgba(16, 185, 129, 0.12), 0 2px 10px -3px rgba(16, 185, 129, 0.4);
}
.da-nav-btn-in:hover { background: #d1fae5; border-color: rgba(5, 150, 105, 0.6); }
.da-nav-btn-in .da-nav-btn-icon { background: linear-gradient(145deg, #34d399, #059669); box-shadow: 0 2px 8px -2px rgba(5, 150, 105, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.3); }

.da-nav-btn-warn {
  background: #fffbeb; color: #92400e;
  border-color: rgba(217, 119, 6, 0.45);
  box-shadow: 0 0 0 1px rgba(217, 119, 6, 0.12), 0 2px 10px -3px rgba(217, 119, 6, 0.4);
}
.da-nav-btn-warn:hover { background: #fef3c7; border-color: rgba(180, 83, 9, 0.6); }
.da-nav-btn-warn .da-nav-btn-icon { background: linear-gradient(145deg, #fbbf24, #d97706); box-shadow: 0 2px 8px -2px rgba(217, 119, 6, 0.55), inset 0 1px 0 rgba(255, 255, 255, 0.3); }

/* Ambient outline glow while actively clocked in — a slow breathing ring
   around the whole button, not just the icon, so it reads from a glance. */
@media (prefers-reduced-motion: no-preference) {
  .da-nav-btn-in { animation: da-nav-glow 2.8s ease-in-out infinite; }
}
@keyframes da-nav-glow {
  0%, 100% { box-shadow: 0 0 0 1px rgba(16, 185, 129, 0.12), 0 2px 10px -3px rgba(16, 185, 129, 0.4); }
  50% { box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.22), 0 0 16px 2px rgba(16, 185, 129, 0.35); }
}

.da-nav-panel-shell { background: transparent; padding: 0; }
.da-nav-panel {
  padding: 0.75rem;
  width: 100%;
  box-sizing: border-box;
  border-radius: 1rem;
  background: rgba(255, 255, 255, 0.96);
  backdrop-filter: blur(16px) saturate(160%);
  -webkit-backdrop-filter: blur(16px) saturate(160%);
}

/* The gradient "clock face" card + its lighter attached footer bar read as
   one continuous card (rounded top / rounded bottom, no gap between them). */
.da-nav-hero {
  position: relative;
  border-radius: 0.85rem 0.85rem 0 0;
  padding: 0.85rem 0.9rem 0.75rem;
  background: linear-gradient(135deg, #4338ca 0%, #4f46e5 55%, #4338ca 100%);
  color: #fff;
}
.da-nav-hero-warn { background: linear-gradient(135deg, #b45309 0%, #d97706 55%, #b45309 100%); }
.da-nav-hero-idle { background: linear-gradient(135deg, #475569 0%, #64748b 55%, #475569 100%); }

.da-nav-hero-top { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
.da-nav-hero-label { font-size: 0.72rem; font-weight: 600; color: rgba(255, 255, 255, 0.85); white-space: nowrap; }
.da-nav-hero-pill {
  display: inline-flex; align-items: center; gap: 0.35rem; flex: none;
  background: #fff; color: #1e293b;
  padding: 0.2rem 0.65rem 0.2rem 0.5rem;
  border-radius: 9999px;
  font-size: 0.68rem; font-weight: 700;
  white-space: nowrap;
}
.da-nav-hero-pill-dot { width: 0.4rem; height: 0.4rem; border-radius: 9999px; background: #94a3b8; flex-shrink: 0; }
.da-nav-hero-pill-in .da-nav-hero-pill-dot { background: #22c55e; }
@media (prefers-reduced-motion: no-preference) { .da-nav-hero-pill-in .da-nav-hero-pill-dot { animation: da-nav-blink 1.8s ease-in-out infinite; } }
.da-nav-hero-pill-warn .da-nav-hero-pill-dot { background: #fbbf24; }

.da-nav-hero-clock {
  font-size: 1.85rem;
  font-weight: 800;
  letter-spacing: 0.01em;
  font-variant-numeric: tabular-nums;
  margin-top: 0.5rem;
  line-height: 1;
}
.da-nav-hero-divider { height: 1px; background: rgba(255, 255, 255, 0.22); margin: 0.7rem 0 0.55rem; }
.da-nav-hero-location { display: flex; align-items: center; gap: 0.35rem; font-size: 0.72rem; color: rgba(255, 255, 255, 0.85); min-width: 0; }

.da-nav-hero-footer {
  display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;
  background: linear-gradient(135deg, #3b82f6, #2563eb);
  color: #fff;
  padding: 0.5rem 0.9rem;
  border-radius: 0 0 0.85rem 0.85rem;
  font-size: 0.7rem;
  font-weight: 600;
  min-width: 0;
}
.da-nav-hero-footer-label { display: flex; align-items: center; gap: 0.35rem; min-width: 0; }

/* Multiple same-day sessions chip — same "×N sessions" convention as
   DigitalAttendance/History.vue's own chip, recolored for the blue footer. */
.da-nav-session-chip {
  display: inline-flex; align-items: center; gap: 0.15rem; flex: none;
  font-size: 0.66rem; font-weight: 800; color: #fff;
  background: rgba(255, 255, 255, 0.22);
  border: 0; border-radius: 9999px;
  padding: 0.1rem 0.45rem;
  transition: background 0.15s ease;
}
.da-nav-session-chip:hover { background: rgba(255, 255, 255, 0.34); }
.da-nav-session-chevron { transition: transform 0.15s ease; }

.da-nav-sessions-box {
  margin-top: 0.5rem;
  background: rgba(219, 234, 254, 0.5);
  border: 1px dashed #93c5fd;
  border-radius: 0.65rem;
  padding: 0.6rem 0.7rem;
}
.da-nav-sessions-loading { font-size: 0.7rem; color: #64748b; }
.da-nav-sessions-list { display: flex; flex-direction: column; gap: 0.4rem; }
.da-nav-session-row { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; font-size: 0.68rem; }
.da-nav-session-num { font-weight: 700; color: #1e3a8a; flex: none; }
.da-nav-session-times { color: #475569; flex: 1; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.da-nav-session-worked { font-weight: 700; color: #2563eb; font-variant-numeric: tabular-nums; flex: none; }

html.dark .da-nav-sessions-box { background: rgba(29, 78, 216, 0.12); border-color: rgba(96, 165, 250, 0.35); }
html.dark .da-nav-session-num { color: #93c5fd; }
html.dark .da-nav-session-times { color: #cbd5e1; }
html.dark .da-nav-session-worked { color: #60a5fa; }
.da-nav-hero-footer-value {
  position: relative;
  font-weight: 800; font-variant-numeric: tabular-nums; flex: none;
  color: inherit;
  cursor: pointer;
  border-radius: 0.35rem;
  padding: 0.1rem 0.3rem;
  margin: -0.1rem -0.3rem;
  transition: background 0.15s ease;
}
.da-nav-hero-footer-value:hover { background: rgba(255, 255, 255, 0.18); }

/* Pure-CSS tooltip, anchored to the value's right edge and opening upward —
   this element sits at the bottom-right corner of the dropdown panel, whose
   own wrapper clips overflow (Jetstream/Dropdown.vue), so a centered or
   downward tooltip would just get cut off. */
.da-nav-tooltip::after {
  content: attr(data-tooltip);
  position: absolute;
  bottom: calc(100% + 0.4rem);
  right: 0;
  white-space: nowrap;
  background: #0f172a;
  color: #f1f5f9;
  font-size: 0.65rem;
  font-weight: 600;
  padding: 0.35rem 0.6rem;
  border-radius: 0.45rem;
  box-shadow: 0 6px 16px -4px rgba(15, 23, 42, 0.4);
  opacity: 0;
  pointer-events: none;
  transform: translateY(4px);
  transition: opacity 0.15s ease, transform 0.15s ease;
  z-index: 20;
}
.da-nav-tooltip:hover::after { opacity: 1; transform: translateY(0); }

.da-nav-hint { font-size: 0.7rem; color: #b45309; margin: 0.6rem 0.15rem 0; }

.da-nav-panel-actions { display: flex; gap: 0.45rem; margin-top: 0.7rem; }
.da-nav-panel-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 0.3rem; flex: 1;
  min-width: 0;
  padding: 0.5rem 0.6rem; border: 1px solid #e2e8f0; border-radius: 0.6rem;
  background: #fff; color: #334155; font-weight: 700; font-size: 0.74rem;
  white-space: nowrap;
  transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}
.da-nav-panel-btn:hover { background: #f8fafc; transform: translateY(-1px); box-shadow: 0 4px 10px -4px rgba(15, 23, 42, 0.15); }
.da-nav-panel-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }
.da-nav-panel-btn-in { background: linear-gradient(145deg, #34d399, #059669); color: #fff; border-color: transparent; }
.da-nav-panel-btn-in:hover { background: linear-gradient(145deg, #34d399, #047857); box-shadow: 0 6px 14px -4px rgba(5, 150, 105, 0.45); }
.da-nav-panel-btn-out { background: linear-gradient(145deg, #f87171, #dc2626); color: #fff; border-color: transparent; }
.da-nav-panel-btn-out:hover { background: linear-gradient(145deg, #f87171, #b91c1c); box-shadow: 0 6px 14px -4px rgba(220, 38, 38, 0.45); }

html.dark .da-nav-panel { background: rgba(15, 23, 42, 0.94); }
html.dark .da-nav-panel-btn { background: rgba(15, 23, 42, 0.6); color: #cbd5e1; border-color: rgba(255, 255, 255, 0.12); }
html.dark .da-nav-panel-btn:hover { background: rgba(15, 23, 42, 0.85); }

html.dark .da-nav-btn { background: rgba(30, 41, 59, 0.6); color: #cbd5e1; border-color: rgba(255, 255, 255, 0.12); }
html.dark .da-nav-btn:hover { background: rgba(30, 41, 59, 0.9); border-color: rgba(148, 163, 184, 0.4); }
html.dark .da-nav-btn-in { background: rgba(6, 78, 59, 0.35); color: #6ee7b7; }
html.dark .da-nav-btn-in:hover { background: rgba(6, 78, 59, 0.55); }
html.dark .da-nav-btn-warn { background: rgba(120, 53, 15, 0.35); color: #fcd34d; }
html.dark .da-nav-btn-warn:hover { background: rgba(120, 53, 15, 0.55); }
html.dark .da-nav-dot { border-color: rgba(30, 41, 59, 0.6); }

.da-nudge {
  position: fixed;
  z-index: 150;
  top: 5.5rem;
  right: 1rem;
  left: 1rem;
  max-width: 22rem;
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.7rem 0.9rem;
  border-radius: 0.85rem;
  background: linear-gradient(150deg, rgba(255,255,255,0.95), rgba(240,253,244,0.9));
  border: 1px solid rgba(16, 185, 129, 0.25);
  backdrop-filter: blur(16px) saturate(160%);
  box-shadow: 0 10px 24px -6px rgba(5, 150, 105, 0.25);
  color: #065f46;
  animation: da-nudge-in 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}
@keyframes da-nudge-in {
  from { opacity: 0; transform: translateY(-10px) scale(0.96); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
@media (prefers-reduced-motion: reduce) { .da-nudge { animation: none; } }
.da-nudge-text { font-size: 0.8rem; font-weight: 600; flex: 1; min-width: 0; }
.da-nudge-btn { flex-shrink: 0; padding: 0.35rem 0.7rem; border: 0; border-radius: 0.55rem; background: linear-gradient(145deg, #34d399, #059669); color: #fff; font-weight: 700; font-size: 0.75rem; }
.da-nudge-btn:disabled { opacity: 0.6; }
.da-nudge-close { flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; width: 1.4rem; height: 1.4rem; padding: 0; border: 0; border-radius: 9999px; color: #6b7280; }
.da-nudge-close:hover { background: rgba(0,0,0,0.06); }

html.dark .da-nudge { background: linear-gradient(150deg, rgba(15,23,42,0.92), rgba(6,78,59,0.55)); border-color: rgba(16,185,129,0.25); color: #a7f3d0; }

/* Very narrow phones (320-359px): the language flag, project picker, "+" and role badge still have to share one row,
   so the flag loses its side padding and the picker shows a shorter project name. */
@media (max-width: 359.98px) {
  .nb-root .nb-lang-btn { padding-left: 0.3rem; padding-right: 0.3rem; margin-right: 0.15rem; min-width: 36px; justify-content: center; }
  .nb-root .wh-picker-trigger { padding-left: 0.4rem; padding-right: 0.3rem; margin-right: 0.25rem; gap: 0.35rem; }
  .nb-root .wh-picker-trigger .wh-picker-value { max-width: 3.4rem !important; }
  .nb-root .qc-root { margin-right: 0.25rem !important; }
}

/* Rauncher button: a soft glow that slowly brightens and dims */
.nb-rauncher-glow { animation: nb-rauncher-glow 2.6s ease-in-out infinite; }
.nb-rauncher-glow svg { animation: nb-rauncher-icon 2.6s ease-in-out infinite; }
.nb-rauncher-glow:hover { animation-play-state: paused; box-shadow: 0 0 22px 3px rgba(99, 102, 241, 0.75); }
@keyframes nb-rauncher-glow {
  0%, 100% { box-shadow: 0 0 3px 0 rgba(99, 102, 241, 0.15), 0 4px 10px -6px rgba(15, 37, 64, 0.35); opacity: 0.72; }
  50% { box-shadow: 0 0 20px 4px rgba(99, 102, 241, 0.65), 0 0 6px 1px rgba(167, 139, 250, 0.7); opacity: 1; }
}
@keyframes nb-rauncher-icon { 0%, 100% { filter: drop-shadow(0 0 0 rgba(99, 102, 241, 0)); } 50% { filter: drop-shadow(0 0 4px rgba(99, 102, 241, 0.95)); } }
html.dark .nb-rauncher-glow { animation-name: nb-rauncher-glow-dark; }
@keyframes nb-rauncher-glow-dark {
  0%, 100% { box-shadow: 0 0 3px 0 rgba(124, 92, 255, 0.2); opacity: 0.7; }
  50% { box-shadow: 0 0 22px 4px rgba(124, 92, 255, 0.75), 0 0 8px 1px rgba(196, 181, 253, 0.6); opacity: 1; }
}
@media (prefers-reduced-motion: reduce) { .nb-rauncher-glow, .nb-rauncher-glow svg { animation: none; opacity: 1; } }

/* Prayer times button: a gentle green glow, orange with moving bars while the adhan plays */
.nb-prayer-btn { position: relative; animation: nb-prayer-glow 3s ease-in-out infinite; }
.nb-prayer-btn.is-playing { background: #ffedd5; color: #c2410c; animation: nb-prayer-glow-on 1.2s ease-in-out infinite; }
.nb-prayer-eq { position: absolute; right: 4px; bottom: 4px; display: inline-flex; align-items: flex-end; gap: 1.5px; height: 10px; } .nb-prayer-eq i { width: 2.5px; height: 3px; border-radius: 2px; background: #ea580c; animation: nb-prayer-eq 0.7s ease-in-out infinite; } .nb-prayer-eq i:nth-child(2) { animation-delay: 0.15s; } .nb-prayer-eq i:nth-child(3) { animation-delay: 0.3s; }
@keyframes nb-prayer-glow { 0%, 100% { box-shadow: 0 0 3px rgba(34, 197, 94, 0.2); } 50% { box-shadow: 0 0 16px 2px rgba(34, 197, 94, 0.55); } }
@keyframes nb-prayer-glow-on { 0%, 100% { box-shadow: 0 0 6px rgba(249, 115, 22, 0.4); } 50% { box-shadow: 0 0 20px 3px rgba(249, 115, 22, 0.8); } }
@keyframes nb-prayer-eq { 0%, 100% { height: 3px; } 50% { height: 10px; } }
html.dark .nb-prayer-btn { background: rgba(16, 185, 129, 0.16); color: #6ee7b7; } html.dark .nb-prayer-btn.is-playing { background: rgba(251, 146, 60, 0.2); color: #fdba74; }
@media (prefers-reduced-motion: reduce) { .nb-prayer-btn, .nb-prayer-btn.is-playing, .nb-prayer-eq i { animation: none; } }
</style>

<template>
  <admin-layout :title="$t('Dashboard')">
    <div class="dashboard-liquid px-4 md:px-0">
      <div class="relative z-10">

      <section class="liquid-panel -mt-2 mb-4 mx-auto">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4 items-start">
          <div class="hero-card sky-card" :class="skyDay ? 'is-day' : 'is-night'">
            <span class="sky-glow" aria-hidden="true"></span>
            <div class="sky-inner">
              <div class="sky-greet">
                <span class="sky-hello">{{ greeting }},</span>
                <h2 class="sky-name">{{ firstName }}</h2>
                <div v-if="$can('read-wims-connect')" class="hero-channels">
                  <Link
                    v-for="ch in heroChannels"
                    :key="ch.key"
                    :href="route(ch.route)"
                    class="hero-channel-btn"
                    :class="[ch.chip, { 'is-live': ch.live }]"
                    @mouseenter="showChannelTooltip($event, ch)"
                    @mouseleave="hideChannelTooltip"
                  >
                    <icons :name="ch.icon" class="w-3.5 h-3.5" />
                    <span class="hero-channel-dot" :class="ch.live ? 'is-live' : 'is-off'"></span>
                  </Link>
                </div>
              </div>

              <div class="sky-avatar">
                <div class="hero-avatar-ring">
                  <div class="hero-avatar" :class="{ 'hero-avatar-photo': hasUserPhoto }" @mouseenter="showAvatarZoom($event)" @mouseleave="hideAvatarZoom">
                    <img v-if="hasUserPhoto" :src="userPhotoUrl" :alt="$page.props.auth.user.name" class="hero-avatar-img" />
                    <template v-else>{{ userInitials }}</template>
                  </div>
                </div>
              </div>

              <div class="sky-temp">
                <template v-if="weather">
                  <div class="sky-temp-num">{{ weather.temperature }}<sup>&deg;</sup></div>
                  <div class="sky-temp-desc"><span class="sky-wicon">{{ weather.icon }}</span> {{ weather.description }}</div>
                </template>
              </div>

              <div class="sky-side">
                <div class="sky-time lg-tip" :data-tip="$t('East Africa Time (Tanzania)')" :aria-label="$t('East Africa Time (Tanzania)')">{{ nowTime }}<small class="sky-eat">EAT</small></div>
                <div v-if="prayerCardOn" class="sky-prayer lg-tip" :data-tip="$t('Next prayer') + ': ' + nextPrayerInfo.label + ' ' + at12(nextPrayerInfo.time)">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 3.2a8.8 8.8 0 1 0 5.3 15.8A7.2 7.2 0 0 1 15.5 3.2z" fill="currentColor" /></svg>
                  <span>{{ nextPrayerInfo.label }} {{ at12(nextPrayerInfo.time) }}</span><i>{{ $t('in') }} {{ prayerRest }}</i>
                </div>
                <div class="sky-date">
                  <span>{{ todayLabel }}</span>
                  <button
                    v-if="$can('read-compliance-records')"
                    type="button"
                    class="hero-calendar-btn lg-tip"
                    :class="{ 'hero-calendar-btn-pulse': complianceDueCount > 0 }"
                    :data-tip="$t('Compliance Calendar')"
                    @click="openComplianceCalendar"
                    @mouseenter="showComplianceTooltip($event)"
                    @mouseleave="hideComplianceTooltip"
                    :aria-label="$t('Compliance Calendar')"
                  >
                    <icons name="calendar" class="w-4 h-4 hero-calendar-icon" />
                    <span v-if="complianceDueCount > 0" :key="complianceDueCount" class="hero-calendar-badge">{{ complianceDueCount > 99 ? '99+' : complianceDueCount }}</span>
                  </button>
                  <Link
                    v-if="$can('read-site-meetings')"
                    :href="route('site-meetings.index')"
                    class="hero-meet-btn hero-meet-blue"
                    :aria-label="meetLabel"
                    @mouseenter="showMeetTooltip($event)"
                    @mouseleave="meetTooltip.show = false"
                  >
                    <icons name="meeting-calendar" class="w-4 h-4 hero-meet-icon" />
                    <span v-if="meetWeek && meetWeek.count > 0" class="hero-meet-badge">{{ meetWeek.count > 9 ? '9+' : meetWeek.count }}</span>
                  </Link>
                  <Link
                    v-if="$can('read-staff-contracts')"
                    :href="route('staffs.contracts.calendar')"
                    class="hero-meet-btn hero-contract-btn hero-meet-green"
                    :aria-label="$t('Staff Contracts')"
                    @mouseenter="showContractTooltip($event)"
                    @mouseleave="contractTooltip.show = false"
                  >
                    <icons name="doc-text" class="w-4 h-4 hero-meet-icon" />
                    <span v-if="contractsDue > 0" class="hero-meet-badge">{{ contractsDue > 9 ? '9+' : contractsDue }}</span>
                  </Link>
                </div>
              </div>
            </div>
          </div>
          <div class="kpi-stack" :class="{ 'is-grid': canViewOnlineUsers || prayerCardOn, 'has-online': canViewOnlineUsers, 'has-prayer': prayerCardOn }">
            <div class="kpi-card">
              <div class="flex items-start justify-between">
                <div>
                  <p class="kpi-label">{{ $t('Checkins') }}</p>
                  <h3 class="kpi-value"><animated-number :value="data.checkins" /></h3>
                </div>
                <span
                  v-if="data.checkins != 0 && data.previous_checkins != 0"
                  class="kpi-trend"
                  :class="data.checkins > data.previous_checkins ? 'kpi-trend-up' : 'kpi-trend-down'"
                >
                  <icons :name="data.checkins > data.previous_checkins ? 'up' : 'down'" className="h-3 w-3" />
                  {{ $number((data.checkins / data.previous_checkins) * 100 - 100) }}%
                </span>
              </div>
              <vue-highcharts type="chart" :options="checkinSparkline" class="kpi-sparkline" />
            </div>
            <div class="kpi-card">
              <div class="flex items-start justify-between">
                <div>
                  <p class="kpi-label">{{ $t('Checkouts') }}</p>
                  <h3 class="kpi-value"><animated-number :value="data.checkouts" /></h3>
                </div>
                <span
                  v-if="data.checkouts != 0 && data.previous_checkouts != 0"
                  class="kpi-trend"
                  :class="data.checkouts > data.previous_checkouts ? 'kpi-trend-up' : 'kpi-trend-down'"
                >
                  <icons :name="data.checkouts > data.previous_checkouts ? 'up' : 'down'" className="h-3 w-3" />
                  {{ $number((data.checkouts / data.previous_checkouts) * 100 - 100) }}%
                </span>
              </div>
              <vue-highcharts type="chart" :options="checkoutSparkline" class="kpi-sparkline" />
            </div>
            <div v-if="prayerCardOn" class="kpi-card prayer-card" :class="{ 'is-soon': prayerSoon }">
              <div class="pc-head"><span class="pc-title"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 3.2a8.8 8.8 0 1 0 5.3 15.8A7.2 7.2 0 0 1 15.5 3.2z" fill="currentColor" /></svg>{{ $t('Prayer Times') }}</span><span class="pc-eat">{{ eatNow }} <b>EAT</b></span></div>
              <div class="pc-next"><span class="pc-label">{{ $t('Next prayer') }}</span><b>{{ nextPrayerInfo.label }}</b><em>{{ at12(nextPrayerInfo.time) }}</em><i>{{ $t('in') }} {{ prayerRest }}</i></div>
              <div class="pc-bar"><span :style="{ width: prayerProgress + '%' }"></span></div>
              <ul class="pc-list"><li v-for="t in prayerToday" :key="t.key" :class="{ next: t.isNext, passed: t.passed && !t.isNext }"><b>{{ t.label }}</b><time>{{ t.time }}</time></li></ul>
            </div>
            <online-users v-if="canViewOnlineUsers" />
          </div>
        </div>

        <section v-if="showCardStack" class="dc-panel mb-4" :aria-label="$t('Your shortcuts')">
          <header class="dc-head">
            <h2 class="dc-title">{{ $t('Your shortcuts') }}</h2>
            <Link v-if="$can('customize-dashboard-cards')" :href="route('profile.show') + '#dashboard-cards'" class="dc-edit">
              <icons name="adjustments" className="h-4 w-4" />{{ $t('Customize') }}
            </Link>
          </header>
          <div class="dc-grid">
            <Link
              v-for="(card, idx) in stackCards"
              :key="card.key"
              :href="card.href"
              class="dc-card"
              :class="{ 'dc-over': dragOverIndex === idx }"
              draggable="true"
              @dragstart="onCardDragStart($event, idx)"
              @dragover.prevent="onCardDragOver($event, idx)"
              @dragleave="onCardDragLeave(idx)"
              @drop.prevent="onCardDrop($event, idx)"
              @dragend="onCardDragEnd"
              @click="onCardClick"
            >
              <span class="dc-ico"><icons :name="card.icon" className="h-5 w-5" /></span>
              <span class="dc-text">
                <b>{{ $t(card.title) }}</b>
                <small>{{ $t(card.description) }}</small>
              </span>
              <span class="dc-go" aria-hidden="true"><icons name="chevron-down" className="h-3.5 w-3.5 -rotate-90" /></span>
            </Link>
          </div>
        </section>

        <div v-if="canViewLockdownStatus" class="liquid-lockdown mb-4 rounded-2xl p-4 text-white shadow-lg">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
            <div class="flex items-center gap-3 min-w-0">
              <span
                class="system-status-badge inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
                :class="systemLockdownEnabled ? 'bg-red-500/20 text-red-200 system-status-badge--alert' : 'bg-emerald-500/20 text-emerald-200 system-status-badge--live'"
              >
                <icons :name="systemLockdownEnabled ? 'lock-closed' : 'lock-open'" className="h-6 w-6" />
              </span>
              <div class="min-w-0">
                <p class="text-sm font-semibold tracking-wide uppercase text-slate-200">{{ $t('System Access Control') }}</p>
                <p class="text-sm text-slate-100">
                  <span class="sm:hidden">
                    {{ systemLockdownEnabled ? $t('Maintenance mode is ON.') : $t('Maintenance mode is OFF.') }}
                  </span>
                  <span class="hidden sm:inline">
                    {{
                      systemLockdownEnabled
                        ? $t('Maintenance mode is ON. System access is restricted except for Admin, Super Admin, and Project Manager.')
                        : $t('Maintenance mode is OFF. All permitted users can access the system.')
                    }}
                  </span>
                </p>
              </div>
            </div>

            <button
              v-if="canManageLockdown"
              type="button"
              @click="toggleSystemLockdown"
              :disabled="isTogglingLockdown"
              class="inline-flex w-full items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-70 sm:w-auto"
              :class="systemLockdownEnabled ? 'bg-red-500 hover:bg-red-600 text-white' : 'bg-emerald-500 hover:bg-emerald-600 text-white'"
            >
              <icons :name="systemLockdownEnabled ? 'lock-closed' : 'lock-open'" className="h-4 w-4" />
              <span>
                {{
                  isTogglingLockdown
                    ? $t('Updating...')
                    : systemLockdownEnabled
                    ? $t('Disable Maintenance Lock')
                    : $t('Enable Maintenance Lock')
                }}
              </span>
            </button>
            <div v-else class="inline-flex w-full items-center justify-center rounded-lg border border-slate-500/60 bg-slate-700/50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-200 sm:w-auto">
              {{ $t('Read Only') }}
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 items-start">
          <component
            :is="isSiteEngineer ? 'div' : Link"
            v-bind="isSiteEngineer ? {} : { href: route('login-logs.index') }"
            :class="summaryCardClass(isSiteEngineer)">
            <div class="flex items-start justify-between">
              <h2 class="mb-2 text-xl font-semibold leading-none text-gray-900 truncate"><animated-number :value="data.login_logs" /></h2>
            </div>
            <p class="leading-none text-gray-600 flex items-center gap-2">
              <icons name="key" className="h-4 w-4 text-emerald-600" />
              <span>{{ $t('Login Logs') }}</span>
            </p>
          </component>
          <component
            :is="isSiteEngineer ? 'div' : Link"
            v-bind="isSiteEngineer ? {} : { href: route('activity') }"
            :class="summaryCardClass(isSiteEngineer)">
            <div class="flex items-start justify-between">
              <h2 class="mb-2 text-xl font-semibold leading-none text-gray-900 truncate"><animated-number :value="data.system_logs" /></h2>
            </div>
            <p class="leading-none text-gray-600 flex items-center gap-2">
              <icons name="list" className="h-4 w-4 text-slate-600" />
              <span>{{ $t('System Logs') }}</span>
            </p>
          </component>
          <component
            :is="isSiteEngineer ? 'div' : Link"
            v-bind="isSiteEngineer ? {} : { href: route('download-print-logs.index') }"
            :class="summaryCardClass(isSiteEngineer)">
            <div class="flex items-start justify-between">
              <h2 class="mb-2 text-xl font-semibold leading-none text-gray-900 truncate"><animated-number :value="data.download_print_logs" /></h2>
            </div>
            <p class="leading-none text-gray-600 flex items-center gap-2">
              <icons name="download" className="h-4 w-4 text-cyan-600" />
              <span>{{ $t('Download / Print Logs') }}</span>
            </p>
          </component>
          <component
            :is="isSiteEngineer ? 'div' : Link"
            v-bind="isSiteEngineer ? {} : { href: route('deleted.index') }"
            :class="summaryCardClass(isSiteEngineer)">
            <div class="flex items-start justify-between">
              <h2 class="mb-2 text-xl font-semibold leading-none text-gray-900 truncate"><animated-number :value="data.deleted_records" /></h2>
            </div>
            <p class="leading-none text-gray-600 flex items-center gap-2">
              <icons name="trash" className="h-4 w-4 text-red-600" />
              <span>{{ $t('Deleted Records') }}</span>
            </p>
          </component>
          <component
            :is="isSiteEngineer ? 'div' : Link"
            v-bind="isSiteEngineer ? {} : { href: route('reports.index') }"
            :class="summaryCardClass(isSiteEngineer)">
            <div class="flex items-start justify-between">
              <h2 class="mb-2 text-xl font-semibold leading-none text-gray-900 truncate"><animated-number :value="data.total_records" /></h2>
            </div>
            <p class="leading-none text-gray-600 flex items-center gap-2">
              <icons name="chart-bar" className="h-4 w-4 text-indigo-600" />
              <span>{{ $t('Total Records') }}</span>
            </p>
          </component>
        </div>
      </section>

      <div class="liquid-filter-row df-row mb-3">
        <div class="df-field df-month">
          <span class="df-icon"><icons name="calendar" class="w-5 h-5" /></span>
          <div class="df-body">
            <span class="df-label">{{ $t('Month') }}</span>
            <auto-complete v-model="month" :suggestions="months" class="w-full" @update:modelValue="reload" />
          </div>
        </div>
        <div class="df-field df-year">
          <span class="df-icon"><icons name="chart-bar" class="w-5 h-5" /></span>
          <div class="df-body">
            <span class="df-label">{{ $t('Year') }}</span>
            <auto-complete v-model="year" :suggestions="years" class="w-full" @update:modelValue="reload" />
          </div>
        </div>
      </div>

      <div class="liquid-chart-shell overflow-x-auto">
        <vue-highcharts type="chart" :options="barChartData" :redrawOnUpdate="true" :oneToOneUpdate="false"
          :animateOnUpdate="true" />
      </div>
      <div class="mt-4 flex items-start flex-col md:flex-row gap-4">
        <div class="w-full md:w-1/2 liquid-chart-shell overflow-x-auto">
          <vue-highcharts type="chart" :options="pieChartData" :redrawOnUpdate="true" :oneToOneUpdate="false"
            :animateOnUpdate="true" />
        </div>
        <div class="w-full md:w-1/2 liquid-chart-shell overflow-x-auto">
          <vue-highcharts type="chart" :options="radialChartData" :redrawOnUpdate="true" :oneToOneUpdate="false"
            :animateOnUpdate="true" />
        </div>
      </div>

      <section v-if='$can("create-users")' class="liquid-table-shell sv-shell mt-6">
        <header class="sv-head">
          <div class="sv-title-row">
            <span class="sv-title-icon"><icons name="chart-bar" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h3 class="sv-title">{{ $t('Warehouse Stock Value Summary') }}</h3>
              <p class="sv-sub">{{ $t('Click a warehouse to see the items it holds') }}</p>
            </div>
          </div>
          <div class="sv-tools">
            <div class="sv-search">
              <icons name="search" class="sv-search-icon" />
              <input v-model="warehouseSearch" type="text" class="sv-search-input" :placeholder="$t('Search warehouse by name or stock value')" />
            </div>
            <button type="button" @click="printWarehouseStock" class="sv-btn sv-btn-ghost">
              <icons name="printer" class="w-4 h-4" /><span>{{ $t('Print') }}</span>
            </button>
            <button type="button" @click="downloadWarehouseStock" class="sv-btn sv-btn-solid">
              <icons name="download" class="w-4 h-4" /><span>{{ $t('Download') }}</span>
            </button>
          </div>
        </header>

        <div class="sv-grand">
          <span class="sv-grand-icon"><icons name="cash" class="w-5 h-5" /></span>
          <div class="min-w-0">
            <div class="sv-grand-label">{{ $t('Total of all sections') }}</div>
            <div class="sv-grand-sub">{{ filteredWarehouseListing.length }} {{ $t('listed') }}: {{ svGroups.map(g => g.title + ' ' + g.rows.length).join(' / ') }}</div>
          </div>
          <div class="sv-grand-total"><b>{{ formatPrice(totalWarehouseStockValue) }}</b> <em>Tsh</em></div>
        </div>

        <div class="sv-kpis">
          <div v-for="g in svGroups" :key="g.key" class="sv-kpi" :class="'sv-kpi-' + g.tone">
            <span class="sv-kpi-icon"><icons :name="g.icon" class="w-4 h-4" /></span>
            <div class="min-w-0">
              <div class="sv-kpi-label">{{ g.title }} <span class="sv-kpi-count">{{ g.rows.length }}</span></div>
              <div class="sv-kpi-value sv-maroon">{{ formatPrice(g.total) }} <small>Tsh</small></div>
            </div>
          </div>
        </div>

        <div class="sv-list">
          <section v-for="g in svGroups" v-show="g.rows.length || !warehouseSearch" :key="g.key" class="sv-group" :class="'sv-group-' + g.tone">
            <header class="sv-group-head">
              <span class="sv-group-icon"><icons :name="g.icon" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1">
                <div class="sv-group-title">{{ g.title }}</div>
                <div class="sv-group-sub">{{ g.sub }} &middot; {{ g.rows.length }}</div>
              </div>
              <div class="sv-group-total"><small>{{ $t('Total') }}</small><b>{{ formatPrice(g.total) }}</b> <em>Tsh</em></div>
            </header>

            <div class="sv-colhead">
              <span>{{ $t('SN') }}</span>
              <span>{{ $t('Name') }}</span>
              <span>{{ $t('Stock Value') }}</span>
              <span>{{ $t('Completion %') }}</span>
              <span></span>
            </div>

            <template :key="ci" v-for="(item, ci) in svVisible(g)">
              <div
                v-if="openIndex === null || openIndex === warehouseKey(item, ci)"
                class="sv-row"
                :class="{ 'sv-row-open': openIndex === warehouseKey(item, ci), 'dash-row-in': justLoaded }"
                :style="justLoaded ? { animationDelay: Math.min(ci * 20, 400) + 'ms' } : {}"
                role="button"
                tabindex="0"
                :aria-expanded="openIndex === warehouseKey(item, ci)"
                @click="toggleAccordion(warehouseKey(item, ci))"
                @keydown.enter.prevent="toggleAccordion(warehouseKey(item, ci))"
              >
                <span class="sv-rank" :class="{ 'sv-rank-top': ci < 3 }">{{ ci + 1 }}</span>
                <div class="sv-name">
                  <span class="sv-name-icon"><icons name="building" class="w-3.5 h-3.5" /></span>
                  <span class="sv-name-text">{{ item.name }}</span>
                  <span v-if="g.key === 'other' && item.status" class="sv-name-tag">{{ item.status }}</span>
                  <span v-else-if="g.key === 'project' && item.status" class="sv-name-tag">{{ item.status }}</span>
                </div>
                <div class="sv-value">
                  <div class="sv-value-top">
                    <b>{{ formatPrice(item.amount) }}</b><small>Tsh</small>
                    <span class="sv-share">{{ groupPct(item, g) }}%</span>
                  </div>
                  <div class="sv-bar"><i :style="{ width: groupPct(item, g) + '%' }"></i></div>
                </div>
                <div class="sv-completion">
                  <template v-if="item.completion_percentage !== null && item.completion_percentage !== undefined">
                    <span class="sv-ring" :style="{ '--p': Math.min(Math.max(Number(item.completion_percentage), 0), 100) }">
                      <span>{{ item.completion_percentage }}%</span>
                    </span>
                  </template>
                  <span v-else class="sv-none">-</span>
                </div>
                <span class="sv-chevron" :class="{ 'sv-chevron-open': openIndex === warehouseKey(item, ci) }"><icons name="chevron-down" class="w-4 h-4" /></span>
              </div>

              <div v-if="openIndex === warehouseKey(item, ci)" class="sv-detail" :key="'accordion-' + warehouseKey(item, ci)">
              <div class="sv-detail-scroll">
                <table class="sv-detail-table">
                  <thead>
                    <tr>
                      <th class="text-left">{{ $t('Item Code') }}</th>
                      <th class="text-left">{{ $t('Item Name') }}</th>
                      <th class="text-right">{{ $t('Item Price') }}</th>
                      <th class="text-right">{{ $t('Stock Quantity') }}</th>
                      <th class="text-center">{{ $t('Unit') }}</th>
                      <th class="text-right">{{ $t('Stock Value') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <template :key="si" v-for="(stock, si) in item.items">
                      <tr v-if="stock.quantity > 0">
                        <td class="sv-code">{{ stock.code }}</td>
                        <td>{{ stock.name }}</td>
                        <td class="text-right">{{ $number(stock.price) }} Tsh</td>
                        <td class="text-right">{{ stock.quantity }}</td>
                        <td class="text-center">{{ stock.unit || '-' }}</td>
                        <td class="text-right sv-strong">{{ $number(stock.total_amount) }} Tsh</td>
                      </tr>
                    </template>
                    <tr v-if="!item.items.some(i => i.quantity > 0)">
                      <td class="sv-empty" colspan="6">{{ $t('The warehouse is currently empty.') }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
            </template>

            <div v-if="g.rows.length > SV_LIMIT && openIndex === null" class="sv-more">
              <button v-if="!svOpen[g.key]" type="button" class="sv-more-btn sv-more-expand" @click="svOpen[g.key] = true">
                <icons name="chevron-down" class="w-4 h-4" />
                <span>{{ $t('Expand') }}</span>
                <em>{{ $t('show {count} more', { count: g.rows.length - SV_LIMIT }) }}</em>
              </button>
              <button v-else type="button" class="sv-more-btn sv-more-collapse" @click="svOpen[g.key] = false">
                <icons name="chevron-down" class="w-4 h-4 sv-flip" />
                <span>{{ $t('Collapse') }}</span>
                <em>{{ $t('show first {count} only', { count: SV_LIMIT }) }}</em>
              </button>
            </div>

            <div v-if="!g.rows.length" class="sv-empty-state">{{ $t('Nothing in this group.') }}</div>
          </section>

          <div v-if="filteredWarehouseListing.length === 0 && warehouseSearch" class="sv-empty-state">
            <icons name="search" class="w-6 h-6" />
            {{ $t('No matching warehouse found.') }}
          </div>
        </div>
      </section>

      </div>
    </div>

    <tec-dialog-modal :show="showLockdownDialog" @close="closeLockdownDialog" :max-width="'sm'">
      <template #title>
        <div class="flex items-center gap-3">
          <span
            class="inline-flex h-10 w-10 items-center justify-center rounded-full"
            :class="pendingLockdownState ? 'bg-red-100 text-red-600' : 'bg-emerald-100 text-emerald-700'"
          >
            <icons :name="pendingLockdownState ? 'lock-closed' : 'lock-open'" className="h-5 w-5" />
          </span>
          <span>{{ pendingLockdownState ? $t('Enable Maintenance Lock') : $t('Disable Maintenance Lock') }}</span>
        </div>
      </template>

      <template #content>
        <p class="mb-2 text-sm font-semibold" :class="pendingLockdownState ? 'text-red-700' : 'text-emerald-700'">
          {{
            pendingLockdownState
              ? $t('This will immediately restrict system access except for Admin, Super Admin, and Project Manager.')
              : $t('This will restore access for all permitted users in the system.')
          }}
        </p>
        <p class="mb-4 text-sm text-gray-700">
          {{ $t('Enter your current password to confirm this security action.') }}
        </p>

        <div>
          <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $t('Password') }}</label>
          <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-400">
              <icons name="key" className="h-4 w-4" />
            </span>
            <input
              v-model="lockdownPassword"
              type="password"
              autocomplete="current-password"
              class="w-full rounded-md border-gray-300 pl-10 shadow-sm focus:border-red-300 focus:ring focus:ring-red-200 focus:ring-opacity-50"
              @keyup.enter="submitLockdownChange"
            />
          </div>
          <div v-if="lockdownFormError" class="mt-2 text-xs text-red-600">{{ lockdownFormError }}</div>
        </div>
      </template>

      <template #footer>
        <div class="flex items-center justify-between">
          <button @click="closeLockdownDialog" class="px-3 py-2 bg-gray-200 rounded-md">{{ $t('Cancel') }}</button>
          <button
            @click="submitLockdownChange"
            :disabled="!lockdownPassword || isTogglingLockdown"
            class="ml-2 inline-flex items-center gap-2 px-4 py-2 text-white text-sm uppercase rounded-md disabled:opacity-50 disabled:cursor-not-allowed"
            :class="pendingLockdownState ? 'bg-red-600 hover:bg-red-700' : 'bg-emerald-600 hover:bg-emerald-700'"
          >
            <icons :name="pendingLockdownState ? 'lock-closed' : 'lock-open'" className="h-4 w-4" />
            <span v-if="isTogglingLockdown">{{ $t('Updating') }}...</span>
            <span v-else>{{ pendingLockdownState ? $t('Enable Lock') : $t('Disable Lock') }}</span>
          </button>
        </div>
      </template>
    </tec-dialog-modal>

    <teleport to="body">
      <div class="hero-avatar-zoom" :class="{ 'hero-avatar-zoom-show': avatarZoom.show }" :style="{ left: avatarZoom.x + 'px', top: avatarZoom.y + 'px', '--az': avatarZoom.size + 'px' }">
        <img v-if="hasUserPhoto" :src="userPhotoUrl" alt="" />
        <div v-else class="hero-avatar-zoom-initials">{{ userInitials }}</div>
        <div class="hero-avatar-zoom-name">{{ $page.props.auth.user.name }}</div>
      </div>
    </teleport>

    <teleport to="body">
      <div class="cc-tooltip cc-tooltip-meet" :class="{ 'cc-tooltip-show': meetTooltip.show }" :style="{ left: meetTooltip.x + 'px', top: meetTooltip.y + 'px' }">
        <div class="cc-tooltip-title meet-title">
          <span class="meet-title__a">{{ $t('Site Meetings of') }}</span>
          <span class="meet-title__b">{{ meetWeek ? $t('Week') + ' ' + meetWeek.week + ' (' + meetWeek.range + ')' : $t('this week') }}</span>
        </div>
        <div v-if="!meetWeek" class="cc-tooltip-empty">{{ $t('Loading…') }}</div>
        <div v-else-if="!meetWeek.meetings.length" class="cc-tooltip-empty">{{ $t('No meetings scheduled this week.') }}</div>
        <div v-else class="cc-tooltip-list">
          <div v-for="(m, idx) in meetWeek.meetings" :key="m.id" class="cc-tooltip-row" :style="{ animationDelay: idx * 0.05 + 's' }">
            <span class="cc-tooltip-row-dot" :class="m.today ? 'cc-dot-warning' : 'cc-dot-info'"></span>
            <span class="meet-when">{{ m.day }} · {{ m.date }}</span>
            <span class="cc-tooltip-row-title">{{ m.project }}</span>
            <span v-if="m.time" class="cc-tooltip-row-days cc-text-info">{{ m.time }}</span>
          </div>
        </div>
        <div v-if="meetWeek && meetWeek.count > meetWeek.meetings.length" class="cc-tooltip-more">{{ $t('+{count} more', { count: meetWeek.count - meetWeek.meetings.length }) }}</div>
        <div class="cc-tooltip-hint">{{ $t('Click the icon to open Site Meetings') }}</div>
      </div>
      <div class="cc-tooltip cc-tooltip-meet cc-tooltip-contract" :class="{ 'cc-tooltip-show': contractTooltip.show }" :style="{ left: contractTooltip.x + 'px', top: contractTooltip.y + 'px' }">
        <div class="cc-tooltip-title meet-title">
          <span class="meet-title__a">{{ $t('Staff Contracts') }}</span>
          <span class="meet-title__b">{{ contractSummary ? $t('Ending within 60 days or already overdue') : $t('Loading…') }}</span>
        </div>
        <div v-if="contractSummary && contractSummary.count" class="ct-pills">
          <span v-if="contractSummary.expired" class="ct-pill is-expired">{{ contractSummary.expired }} {{ $t('overdue') }}</span>
          <span v-if="contractSummary.urgent" class="ct-pill is-urgent">{{ contractSummary.urgent }} {{ $t('within 14 days') }}</span>
          <span v-if="contractSummary.soon" class="ct-pill is-soon">{{ contractSummary.soon }} {{ $t('within 60 days') }}</span>
        </div>
        <div v-if="!contractSummary" class="cc-tooltip-empty">{{ $t('Loading…') }}</div>
        <div v-else-if="!contractSummary.contracts.length" class="cc-tooltip-empty">{{ $t('No contract needs attention right now.') }}</div>
        <div v-else class="cc-tooltip-list">
          <div v-for="(c, idx) in contractSummary.contracts" :key="c.id" class="cc-tooltip-row ct-row" :style="{ animationDelay: idx * 0.05 + 's' }">
            <span class="cc-tooltip-row-dot" :class="c.state === 'expired' ? 'cc-dot-critical' : c.state === 'urgent' ? 'cc-dot-warning' : 'cc-dot-info'"></span>
            <span class="ct-who"><b>{{ c.name }}</b><small>{{ [c.detail, c.end].filter(Boolean).join(' · ') }}</small></span>
            <span class="cc-tooltip-row-days" :class="c.state === 'expired' ? 'cc-text-critical' : c.state === 'urgent' ? 'cc-text-warning' : 'cc-text-info'">{{ c.label }}</span>
          </div>
        </div>
        <div v-if="contractSummary && contractSummary.count > contractSummary.contracts.length" class="cc-tooltip-more">{{ $t('+{count} more', { count: contractSummary.count - contractSummary.contracts.length }) }}</div>
        <div class="cc-tooltip-hint">{{ $t('Click the icon to open the contracts calendar') }}</div>
      </div>
      <div class="cc-tooltip" :class="{ 'cc-tooltip-show': complianceTooltip.show }" :style="{ left: complianceTooltip.x + 'px', top: complianceTooltip.y + 'px' }">
        <div class="cc-tooltip-title">
          {{ $t('Coming Up') }}
          <span v-if="complianceDueCount" class="cc-tooltip-count">{{ complianceDueCount }}</span>
        </div>
        <div v-if="complianceTooltipLoading" class="cc-tooltip-empty">{{ $t('Loading…') }}</div>
        <div v-else-if="!complianceUpcoming.length" class="cc-tooltip-empty">{{ $t('Nothing outstanding right now.') }}</div>
        <div v-else class="cc-tooltip-list">
          <div
            v-for="(r, idx) in complianceUpcoming"
            :key="r.id"
            class="cc-tooltip-row"
            :style="{ animationDelay: idx * 0.05 + 's' }"
          >
            <span class="cc-tooltip-row-dot" :class="`cc-dot-${complianceSeverity(r.days_remaining)}`"></span>
            <span class="cc-tooltip-row-title">{{ r.title }}</span>
            <span class="cc-tooltip-row-days" :class="`cc-text-${complianceSeverity(r.days_remaining)}`">{{ r.days_remaining_label }}</span>
          </div>
        </div>
        <div v-if="complianceDueCount > complianceUpcoming.length" class="cc-tooltip-more">
          {{ $t('+{count} more', { count: complianceDueCount - complianceUpcoming.length }) }}
        </div>
        <div class="cc-tooltip-hint">{{ $t('Click the icon for the full calendar') }}</div>
      </div>
    </teleport>

    <teleport to="body">
      <div class="hero-channel-fixed-tip" :class="{ 'hero-channel-fixed-tip-show': channelTooltip.show }" :style="{ left: channelTooltip.x + 'px', top: channelTooltip.y + 'px' }">
        {{ channelTooltip.label }}
      </div>
    </teleport>

    <modal :show="complianceCalendarOpen" @close="complianceCalendarOpen = false" max-width="2xl">
      <div class="p-4 sm:p-5 compliance-calendar-modal">
        <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
          <div class="flex items-center gap-2">
            <button type="button" class="cc-nav-btn" @click="shiftComplianceCalendar(-4)"><icons name="chevron-down" class="w-4 h-4 rotate-90" /></button>
            <h3 class="text-sm font-bold text-gray-900 flex items-center gap-1.5"><icons name="calendar" class="w-4 h-4 text-indigo-500" />{{ $t('Compliance Calendar') }}</h3>
            <button type="button" class="cc-nav-btn" @click="shiftComplianceCalendar(4)"><icons name="chevron-down" class="w-4 h-4 -rotate-90" /></button>
          </div>
          <Link :href="route('compliance-records.calendar')" class="text-xs font-semibold text-indigo-600 hover:text-indigo-700 inline-flex items-center gap-1">
            {{ $t('Open Full Calendar') }} <icons name="out" class="w-3 h-3" />
          </Link>
        </div>
        <div v-if="complianceCalendarLoading" class="text-center text-sm text-gray-400 py-10">{{ $t('Loading…') }}</div>
        <div v-else class="grid grid-cols-2 gap-3">
          <month-calendar
            v-for="m in complianceCalendarWindow"
            :key="`${m.year}-${m.month}`"
            :year="m.year"
            :month="m.month"
            :events="complianceCalendarEvents"
            hide-nav
            compact
            @event-click="goToComplianceEvent"
          />
        </div>
      </div>
    </modal>
  </admin-layout>
</template>

<script>
import { cardCatalog, canOpenCard, savedCardKeys, defaultKeysFor, MAX_CARDS } from '@/Core/dashboardCards';

// the standard stack's own keys -> the matching side-menu entry (so its title and description come from one place)
const LEGACY_TO_CATALOG = { warehouses: 'warehouses', reports: 'reports', notices: 'notice-board', backup: 'backup', users: 'users', pem: 'project-execution', material_reallocation: 'material-reallocations', site_reports: 'daily-site-reports', material_value: 'material-value', material_requests: 'material-requests' };
import VueHighcharts from 'vue3-highcharts';
import Highcharts from 'highcharts';
import { Link } from '@inertiajs/vue3';
import { prayerState, nextPrayer, today as prayerTodayList, words as prayerWords, clock12, eatClock } from '@/Shared/Prayer/prayerStore';
import { SITE_ENGINEER_ROLES } from '@/Core/siteEngineerRoles';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import OnlineUsers from './OnlineUsers.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import AnimatedNumber from '@/Shared/AnimatedNumber.vue';
import TecSectionTitle from '@/Jetstream/SectionTitle.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import Modal from '@/Jetstream/Modal.vue';
import MonthCalendar from '@/Shared/Calendar/MonthCalendar.vue';

export default {
  props: ['data', 'chart', 'top_products', 'listing', 'weather', 'shortcutCardOrder', 'channelLive', 'canViewOnlineUsers', 'contractsDue'],

  components: { OnlineUsers, AdminLayout, AutoComplete, AnimatedNumber, VueHighcharts, TecSectionTitle, TecDialogModal, Modal, MonthCalendar, Link },

  data() {
    return {
      year: new Date().getFullYear(),
      month: new Date().getMonth() + 1,
      complianceCalendarOpen: false,
      complianceCalendarLoading: false,
      complianceCalendarEvents: [],
      complianceCalendarMonth: new Date().getMonth() + 1,
      complianceCalendarYear: new Date().getFullYear(),
      complianceDueCount: 0,
      complianceUpcoming: [],
      complianceTooltipLoading: false,
      complianceTooltip: { show: false, x: 0, y: 0 },
      meetWeek: null,
      meetTooltip: { show: false, x: 0, y: 0 },
      contractTooltip: { show: false, x: 0, y: 0 },
      contractSummary: null,
      warehouseSearch: '',
      openIndex: null,
      now: new Date(),
      clockTimer: null,
      svExpanded: false,
      svOpen: { warehouse: false, project: false, other: false },
      SV_LIMIT: 10,
      // Gates the warehouse table's row entrance animation to the initial
      // page load only. Rows toggle via v-if (the accordion hides every
      // other row while one is open), so without this flag, collapsing an
      // accordion back to the full list would re-insert those rows and
      // replay the stagger every time — this flag turns itself off shortly
      // after mount, well past when the reveal has finished playing.
      justLoaded: true,
      // Teleported to <body> and positioned via getBoundingClientRect() on
      // hover, so the enlarged preview is never clipped by the hero card.
      avatarZoom: { show: false, x: 0, y: 0, size: 160 },
      // Same teleported/fixed-position approach as avatarZoom/complianceTooltip
      // above, so this tooltip is never clipped or painted-over by sibling
      // stacking contexts (dashboard cards, grid rows, etc.).
      channelTooltip: { show: false, x: 0, y: 0, label: '' },
      isTogglingLockdown: false,
      systemLockdownEnabled: String(this.$page.props.settings?.system_lockdown || '0') === '1',
      showLockdownDialog: false,
      pendingLockdownState: false,
      lockdownPassword: '',
      lockdownFormError: '',
      months: [
        { label: this.$monthName('2021-01-01'), value: 1 },
        { label: this.$monthName('2021-02-01'), value: 2 },
        { label: this.$monthName('2021-03-01'), value: 3 },
        { label: this.$monthName('2021-04-01'), value: 4 },
        { label: this.$monthName('2021-05-01'), value: 5 },
        { label: this.$monthName('2021-06-01'), value: 6 },
        { label: this.$monthName('2021-07-01'), value: 7 },
        { label: this.$monthName('2021-08-01'), value: 8 },
        { label: this.$monthName('2021-09-01'), value: 9 },
        { label: this.$monthName('2021-10-01'), value: 10 },
        { label: this.$monthName('2021-11-01'), value: 11 },
        { label: this.$monthName('2021-12-01'), value: 12 },
      ],
      // Local working copy the user drags, initialized from whatever order
      // was saved last time (see visibleShortcutCards below).
      cardOrder: [...(this.shortcutCardOrder || [])],
      draggedIndex: null,
      dragOverIndex: null,
      suppressNextCardClick: false,
    };
  },

  computed: {
    meetLabel() {
      return this.meetWeek ? this.$t('Site Meetings of Week') + ' ' + this.meetWeek.week + ' (' + this.meetWeek.range + ')' : this.$t('Site Meetings this week');
    },
    complianceCalendarWindow() {
      return Array.from({ length: 4 }, (_, i) => {
        let month = this.complianceCalendarMonth + i;
        let year = this.complianceCalendarYear;
        while (month > 12) {
          month -= 12;
          year += 1;
        }
        return { year, month };
      });
    },
    isSiteEngineer() {
      const roles = this.$page.props.auth?.user?.roles || [];
      return roles.some(role => SITE_ENGINEER_ROLES.includes(role.name));
    },
    isStoreManager() {
      const roles = this.$page.props.auth?.user?.roles || [];
      return roles.some(role => {
        const name = String(role.name || '').toLowerCase();
        return name === 'store manager' || name === 'store manger';
      });
    },
    canManageLockdown() {
      const allowedRoles = ['super admin', 'admin'];
      const roles = this.$page.props.auth?.user?.roles || [];
      return roles.some(role => allowedRoles.includes(String(role.name || '').toLowerCase()));
    },
    canViewLockdownStatus() {
      const allowedRoles = ['super admin', 'admin', 'project manager'];
      const roles = this.$page.props.auth?.user?.roles || [];
      return roles.some(role => allowedRoles.includes(String(role.name || '').toLowerCase()));
    },
    years() {
      let years = [];
      let date = new Date();
      let year = date.getFullYear();
      for (let y = 2020; y <= year; y++) {
        years.push({ label: y + '', value: y + '' });
      }
      return years;
    },
    filteredWarehouseListing() {
      const rows = Array.isArray(this.listing) ? this.listing : [];
      const needle = String(this.warehouseSearch || '').trim().toLowerCase();
      if (!needle) {
        return rows;
      }

      return rows.filter((row) => {
        const name = String(row.name || '').toLowerCase();
        const amountFormatted = this.formatPrice(Number(row.amount || 0)).toLowerCase();
        const amountRaw = String(Number(row.amount || 0)).toLowerCase();
        return name.includes(needle) || amountFormatted.includes(needle) || amountRaw.includes(needle);
      });
    },
    totalWarehouseStockValue() {
      return this.filteredWarehouseListing.reduce((sum, row) => sum + Number(row.amount || 0), 0);
    },
    visibleWarehouseListing() {
      const rows = this.filteredWarehouseListing;
      return this.svExpanded ? rows : rows.slice(0, this.SV_LIMIT);
    },
    // The summary in three groups: Warehouses (Main Warehouses), Projects (Private, Government) and Others (every other category)
    svGroups() {
      const defs = [
        { key: 'warehouse', title: 'Warehouses', sub: 'Main warehouses only', icon: 'building', tone: 'blue' },
        { key: 'project', title: this.$t('Projects'), sub: this.$t('Private and Government'), icon: 'collection', tone: 'emerald' },
        { key: 'other', title: this.$t('Others'), sub: this.$t('All other categories'), icon: 'group', tone: 'violet' },
      ];
      return defs.map(d => {
        const rows = this.filteredWarehouseListing.filter(r => (r.group || 'other') === d.key).slice().sort((a, b) => Number(b.amount || 0) - Number(a.amount || 0) || String(a.name || '').localeCompare(String(b.name || '')));
        return { ...d, rows, total: rows.reduce((s, r) => s + Number(r.amount || 0), 0) };
      });
    },
    topWarehouse() {
      return this.filteredWarehouseListing.reduce((best, row) => (!best || Number(row.amount || 0) > Number(best.amount || 0) ? row : best), null);
    },
    canViewDashboardShortcuts() {
      const roles = (this.$page.props.auth?.user?.roles || []).map(role => role.name);
      const restricted = [...SITE_ENGINEER_ROLES, 'Store Manager'];

      return !roles.some(name => restricted.includes(name));
    },
    canViewPemShortcut() {
      const allowedRoles = [...SITE_ENGINEER_ROLES, 'Project Manager', 'Project Managers', 'Admin', 'Super Admin'];
      const roles = (this.$page.props.auth?.user?.roles || []).map(role => role.name);
      return roles.some(name => allowedRoles.includes(name));
    },
    canViewMaterialReallocationShortcut() {
      const allowedRoles = [
        'Project Manager', 'Project Managers', 'Procurement Manager', 'Procurement Manger', 'Admin', 'Super Admin',
        ...SITE_ENGINEER_ROLES, 'Store Manager',
      ];
      const roles = (this.$page.props.auth?.user?.roles || []).map(role => role.name);
      return roles.some(name => allowedRoles.includes(name));
    },
    canViewSiteReportsShortcut() {
      const allowedRoles = [...SITE_ENGINEER_ROLES, 'Project Manager', 'Project Managers', 'Admin', 'Super Admin'];
      const roles = (this.$page.props.auth?.user?.roles || []).map(role => role.name);
      return roles.some(name => allowedRoles.includes(name));
    },
    // Every possible shortcut card, in the app's own default order — each
    // one's `visible` mirrors exactly the v-if condition it used to have
    // inline. Order/visibility are kept separate on purpose: a user's saved
    // drag order should survive their permissions changing later, and a
    // newly-visible card should still slot in sensibly rather than vanish
    // from their saved arrangement.
    shortcutCards() {
      return [
        { key: 'warehouses', visible: this.$can('read-warehouses') && this.featureOn('warehouses'), href: route('warehouses.index'), iconBg: 'bg-sky-100', iconColor: 'text-sky-700', icon: 'building', title: 'Warehouses', description: 'Manage warehouses' },
        { key: 'reports', visible: this.$can('read-reports') && this.featureOn('reports'), href: route('reports.index'), iconBg: 'bg-violet-100', iconColor: 'text-violet-700', icon: 'chart-bar', title: 'Reports', description: 'View all reports' },
        { key: 'notices', visible: this.$can('read-notices') && this.featureOn('notices'), href: route('notices.index'), iconBg: 'bg-amber-100', iconColor: 'text-amber-700', icon: 'bell', title: 'Notices', description: 'Notice board' },
        { key: 'backup', visible: this.$super, href: route('backup.index'), iconBg: 'bg-slate-200', iconColor: 'text-slate-700', icon: 'cog', title: 'Backup', description: 'Database backup & restore' },
        { key: 'users', visible: this.$can('read-users') && this.featureOn('users'), href: route('users.index'), iconBg: 'bg-indigo-100', iconColor: 'text-indigo-700', icon: 'users', title: 'Users', description: 'Manage users' },
        { key: 'pem', visible: this.canViewPemShortcut && this.featureOn('project_execution'), href: route('project-execution.dashboard'), iconBg: 'bg-violet-100', iconColor: 'text-violet-700', icon: 'clip', title: 'PEM', description: 'Project execution management' },
        { key: 'material_reallocation', visible: this.canViewMaterialReallocationShortcut && this.featureOn('material_reallocations'), href: route('material-reallocations.index'), iconBg: 'bg-orange-100', iconColor: 'text-orange-700', icon: 'toggle', title: 'Material Reallocation', description: 'Move stock between projects' },
        { key: 'site_reports', visible: this.canViewSiteReportsShortcut && this.featureOn('daily_site_reports'), href: route('daily-site-reports.index'), iconBg: 'bg-emerald-100', iconColor: 'text-emerald-700', icon: 'doc', title: 'Site Reports', description: 'Daily site reports' },
        { key: 'material_value', visible: this.$can('read-material-value') && this.featureOn('material_value'), href: route('material-value.index'), iconBg: 'bg-teal-100', iconColor: 'text-teal-700', icon: 'chart-bar', title: 'Material Value', description: 'Stock value by material' },
        { key: 'material_requests', visible: this.$can('read-material-requests') && this.featureOn('material_requests'), href: route('material-requests.index'), iconBg: 'bg-rose-100', iconColor: 'text-rose-700', icon: 'doc-text', title: 'Material Requests', description: 'Request materials for a project' },
      ];
    },
    // Visible cards, arranged per the user's saved order. Cards missing from
    // a stale saved order (new ones added later, or ones this user only just
    // gained permission for) are appended at the end in the app's default
    // order rather than disappearing.
    visibleShortcutCards() {
      const visible = this.shortcutCards.filter(c => c.visible);
      const byKey = Object.fromEntries(visible.map(c => [c.key, c]));
      const ordered = this.cardOrder.map(key => byKey[key]).filter(Boolean);
      const remaining = visible.filter(c => !this.cardOrder.includes(c.key));
      return [...ordered, ...remaining];
    },
    // The person's own stack (Profile > Dashboard cards) or, when they never chose one, the standard stack with its older role rules.
    savedKeys() {
      return savedCardKeys(this);
    },
    stackCards() {
      if (this.savedKeys) {
        const byKey = Object.fromEntries(cardCatalog().map(c => [c.key, c]));
        return this.savedKeys
          .map(k => byKey[k])
          .filter(c => c && canOpenCard(this, c))
          .map(c => ({ key: c.key, href: route(c.route), icon: c.icon, title: c.title, description: c.description }));
      }
      const catalog = Object.fromEntries(cardCatalog().map(c => [c.key, c]));
      // site engineers and store managers: the everyday modules they are allowed to open
      if (!this.canViewDashboardShortcuts) {
        return defaultKeysFor(this)
          .map(k => catalog[k])
          .filter(c => c && canOpenCard(this, c))
          .slice(0, MAX_CARDS)
          .map(c => ({ key: c.key, href: route(c.route), icon: c.icon, title: c.title, description: c.description }));
      }
      return this.visibleShortcutCards.map(c => {
        const mapped = catalog[LEGACY_TO_CATALOG[c.key]] || null;
        return { key: c.key, href: c.href, icon: c.icon, title: mapped ? mapped.title : c.title, description: mapped ? mapped.description : c.description };
      });
    },
    showCardStack() {
      return this.stackCards.length > 0;
    },
    barChartData() {
      return this.barChartOptions();
    },
    pieChartData() {
      return this.pieChartOptions();
    },
    radialChartData() {
      return this.radialChartOptions();
    },
    firstName() {
      const name = this.$page.props.auth?.user?.name || '';
      return name.split(' ')[0] || name;
    },
    skyDay() {
      if (this.weather && typeof this.weather.is_day === 'boolean') return this.weather.is_day;
      const h = this.now.getHours();
      return h >= 6 && h < 18;
    },
    // the greeting card runs on East Africa Time (Tanzania), whatever the computer's own clock says
    nowTime() {
      return this.now.toLocaleTimeString('en-US', { timeZone: 'Africa/Dar_es_Salaam', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
    },
    eatHour() {
      return Number(new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Dar_es_Salaam', hour: 'numeric', hourCycle: 'h23' }).format(this.now));
    },
    prayerCardOn() { return !!(prayerState.data && prayerState.data.dashboard && nextPrayer.value); },
    nextPrayerInfo() { return nextPrayer.value || { label: '', time: '00:00', t: 0 }; },
    prayerRest() { return nextPrayer.value ? prayerWords(nextPrayer.value.t) : ''; },
    prayerSoon() { return !!(nextPrayer.value && nextPrayer.value.t - prayerState.now < (prayerState.data.alert_before_min + 10) * 60000); },
    prayerToday() { return prayerTodayList.value.filter(t => t.is_prayer).map(t => ({ ...t, time: clock12(t.time).replace(/^0/, '') })); },
    prayerProgress() {
      const n = nextPrayer.value;
      if (!n) return 0;
      const past = prayerTodayList.value.filter(t => t.is_prayer && t.t <= prayerState.now);
      const from = past.length ? past[past.length - 1].t : n.t - 6 * 3600000;
      return Math.round(Math.min(1, Math.max(0, (prayerState.now - from) / Math.max(1, n.t - from))) * 100);
    },
    eatNow() { return prayerState.now ? eatClock(true).replace(/ (AM|PM)$/, ' $1') : ''; },
    greeting() {
      const hour = this.eatHour;
      if (hour >= 5 && hour < 12) return this.$t('Good Morning');
      if (hour >= 12 && hour < 17) return this.$t('Good Afternoon');
      if (hour >= 17 && hour < 21) return this.$t('Good Evening');
      return this.$t('Good Night');
    },
    todayLabel() {
      const locale = this.$page.props.settings?.default_locale || undefined;
      return new Date().toLocaleDateString(locale, { weekday: 'long', month: 'long', day: 'numeric', timeZone: 'Africa/Dar_es_Salaam' });
    },
    heroChannels() {
      const live = this.channelLive || {};
      return [
        { key: 'whatsapp', icon: 'whatsapp', label: this.$t('WhatsApp Gateway'), route: 'wims-connect.gateways.whatsapp', chip: 'hero-ch-emerald' },
        { key: 'meta', icon: 'meta', label: this.$t('Meta Gateway'), route: 'wims-connect.gateways.meta', chip: 'hero-ch-indigo' },
        { key: 'sms', icon: 'sms', label: this.$t('SMS Gateway'), route: 'wims-connect.gateways.sms', chip: 'hero-ch-amber' },
      ].map(ch => ({ ...ch, live: !!live[ch.key] }));
    },
    // Always true: users without a photo get the shared default avatar from profile_photo_url.
    hasUserPhoto() {
      return !!this.$page.props.auth?.user?.profile_photo_url;
    },
    userPhotoUrl() {
      return this.$page.props.auth?.user?.profile_photo_url;
    },
    userInitials() {
      const name = this.$page.props.auth?.user?.name || '';
      return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map(part => part[0].toUpperCase())
        .join('');
    },
    checkinSparkline() {
      return this.sparklineOptions(
        this.sortValues(this.chart.month.checkin),
        this.data.checkins > this.data.previous_checkins ? '#10b981' : '#ef4444'
      );
    },
    checkoutSparkline() {
      return this.sparklineOptions(
        this.sortValues(this.chart.month.checkout),
        this.data.checkouts > this.data.previous_checkouts ? '#10b981' : '#ef4444'
      );
    },
  },

  // watch: {
  //   month: function (m) {
  //     // let month = this.months.find(mn => mn.value == m).label;
  //     this.$inertia.visit(route('dashboard', { month: +m, year: +this.year }), { preserveState: false, preserveScroll: false });
  //   },
  //   year: function (y) {
  //     this.$inertia.visit(route('dashboard', { month: +this.month, year: +y }), { preserveState: false, preserveScroll: false });
  //   },
  // },

  created() {
    let params = new URLSearchParams(this.$page.url.substring(1));
    if (params.get('month') && params.get('year')) {
      this.year = params.get('year');
      this.month = params.get('month');
    }

    // if (params.get('year') && this.year != params.get('year')) {
    //   this.year = params.get('year');
    // }
    // if (params.get('month') && this.month != params.get('month')) {
    //   this.month = params.get('month');
    // }

    if (this.$can('read-compliance-records')) {
      this.fetchComplianceDueCount();
    }
    if (this.$can('read-site-meetings')) {
      this.fetchMeetWeek();
    }
  },

  beforeUnmount() {
    clearInterval(this.clockTimer);
  },

  mounted() {
    this.clockTimer = setInterval(() => { this.now = new Date(); }, 1000);
    // See `justLoaded` in data() — long enough for the slowest row's
    // staggered delay (capped at 400ms) plus its own animation to finish.
    setTimeout(() => {
      this.justLoaded = false;
    }, 900);
  },

  methods: {
    at12(hhmm) { return clock12(hhmm).replace(/^0/, ''); },
    featureOn(key) {
      return this.$page.props.enabledFeatures?.[key] !== false || this.$super;
    },
    goToComplianceEvent(ev) {
      window.location.href = ev.url;
    },
    showContractTooltip(event) {
      const rect = event.currentTarget.getBoundingClientRect();
      const half = 300 / 2 + 12;
      const x = Math.min(Math.max(rect.left + rect.width / 2, half), window.innerWidth - half);
      this.contractTooltip = { show: true, x, y: rect.bottom + 10 };
      axios.get(this.route('staffs.contracts.summary')).then(res => (this.contractSummary = res.data)).catch(() => {});
    },
    fetchMeetWeek() {
      axios.get(this.route('site-meetings.week-summary')).then(res => (this.meetWeek = res.data)).catch(() => {});
    },
    showMeetTooltip(event) {
      const rect = event.currentTarget.getBoundingClientRect();
      const half = 270 / 2 + 12;
      const x = Math.min(Math.max(rect.left + rect.width / 2, half), window.innerWidth - half);
      this.meetTooltip = { show: true, x, y: rect.bottom + 10 };
      this.fetchMeetWeek();
    },
    fetchComplianceDueCount() {
      this.complianceTooltipLoading = true;
      axios
        .get(this.route('compliance-records.calendar.due-count'))
        .then(res => {
          this.complianceDueCount = res.data.count;
          this.complianceUpcoming = res.data.upcoming;
        })
        .catch(() => {})
        .finally(() => (this.complianceTooltipLoading = false));
    },
    showComplianceTooltip(event) {
      const rect = event.currentTarget.getBoundingClientRect();
      // The tooltip is a fixed 270px box centered on this x — anchoring it
      // straight to the icon's own center overflows off-screen on narrow
      // viewports when the icon sits near an edge. Clamp instead, so it
      // always stays fully within the viewport regardless of device width.
      const tooltipWidth = 270;
      const margin = 12;
      const minX = tooltipWidth / 2 + margin;
      const maxX = window.innerWidth - tooltipWidth / 2 - margin;
      const idealX = rect.left + rect.width / 2;
      const x = Math.min(Math.max(idealX, minX), maxX);
      this.complianceTooltip = { show: true, x, y: rect.bottom + 10 };
    },
    hideComplianceTooltip() {
      this.complianceTooltip.show = false;
    },
    complianceSeverity(daysRemaining) {
      if (daysRemaining === null || daysRemaining === undefined) return 'info';
      if (daysRemaining <= 7) return 'critical';
      if (daysRemaining <= 30) return 'warning';
      return 'info';
    },
    openComplianceCalendar() {
      this.complianceCalendarOpen = true;
      this.complianceCalendarMonth = new Date().getMonth() + 1;
      this.complianceCalendarYear = new Date().getFullYear();
      this.fetchComplianceCalendar();
    },
    fetchComplianceCalendar() {
      this.complianceCalendarLoading = true;
      axios
        .get(this.route('compliance-records.calendar.widget'), {
          params: { month: this.complianceCalendarMonth, year: this.complianceCalendarYear, months: 4 },
        })
        .then(res => {
          this.complianceCalendarEvents = res.data.events;
        })
        .finally(() => (this.complianceCalendarLoading = false));
    },
    shiftComplianceCalendar(delta) {
      // Normalize via a 0-based month index so any delta magnitude (±1 for
      // a single month, ±4 for the whole visible window) rolls over years
      // correctly in one step, instead of the old loop-per-month approach.
      const total = (this.complianceCalendarYear * 12 + (this.complianceCalendarMonth - 1)) + delta;
      this.complianceCalendarYear = Math.floor(total / 12);
      this.complianceCalendarMonth = (((total % 12) + 12) % 12) + 1;
      this.fetchComplianceCalendar();
    },
    // The enlarged photo always opens fully on screen: sized to the viewport, centred on the photo, below it when
    // there is room and above it otherwise (the photo sits in a card near the top/right edge, so a fixed side offset overflows).
    showAvatarZoom(event) {
      const rect = event.currentTarget.getBoundingClientRect();
      const vw = document.documentElement.clientWidth || window.innerWidth;
      const vh = window.innerHeight;
      const size = Math.max(120, Math.min(220, vw - 40, vh - 90));
      const pw = size + 20;
      const ph = size + 20 + 30;
      const x = Math.max(8, Math.min(rect.left + rect.width / 2 - pw / 2, vw - pw - 8));
      let y = rect.bottom + 10;
      if (y + ph > vh - 8) y = rect.top - ph - 10;
      y = Math.max(8, Math.min(y, vh - ph - 8));
      this.avatarZoom = { show: true, x: Math.round(x), y: Math.round(y), size };
    },
    hideAvatarZoom() {
      this.avatarZoom.show = false;
    },
    showChannelTooltip(event, ch) {
      const rect = event.currentTarget.getBoundingClientRect();
      this.channelTooltip = {
        show: true,
        x: rect.left + rect.width / 2,
        y: rect.bottom + 10,
        label: `${ch.label} (${ch.live ? this.$t('Live') : this.$t('Not Connected')})`,
      };
    },
    hideChannelTooltip() {
      this.channelTooltip.show = false;
    },
    onCardDragStart(event, idx) {
      this.draggedIndex = idx;
      event.dataTransfer.effectAllowed = 'move';
      // Firefox requires data actually be set for the drag to start at all.
      event.dataTransfer.setData('text/plain', String(idx));
    },
    onCardDragOver(event, idx) {
      if (this.draggedIndex === null) {
        return;
      }
      this.dragOverIndex = idx;
    },
    onCardDragLeave(idx) {
      if (this.dragOverIndex === idx) {
        this.dragOverIndex = null;
      }
    },
    onCardDrop(event, dropIndex) {
      if (this.draggedIndex === null || this.draggedIndex === dropIndex) {
        this.dragOverIndex = null;
        return;
      }
      const cards = [...this.stackCards];
      const [moved] = cards.splice(this.draggedIndex, 1);
      cards.splice(dropIndex, 0, moved);
      this.dragOverIndex = null;
      this.draggedIndex = null;
      // A drop fires a click right after on most browsers — swallow just
      // that one so it doesn't navigate the card that was just dropped on.
      this.suppressNextCardClick = true;
      if (this.savedKeys || !this.canViewDashboardShortcuts) {
        if (this.$can('customize-dashboard-cards')) {
          this.$inertia.put(route('dashboard.cards.update'), { keys: cards.map(c => c.key) }, { preserveScroll: true, preserveState: true });
        }
        return;
      }
      this.cardOrder = cards.map(c => c.key);
      this.$inertia.post(route('dashboard.shortcut-card-order.update'), { order: this.cardOrder }, { preserveScroll: true, preserveState: true, only: [] });
    },
    onCardDragEnd() {
      this.draggedIndex = null;
      this.dragOverIndex = null;
    },
    onCardClick(event) {
      if (this.suppressNextCardClick) {
        event.preventDefault();
        this.suppressNextCardClick = false;
      }
    },
    summaryCardClass(disabled) {
      return [
        'liquid-stat-card',
        disabled ? 'cursor-default opacity-90' : '',
      ];
    },
    warehouseKey(item, index) {
      if (item && item.id) {
        return `warehouse-${item.id}`;
      }
      return `warehouse-${index}-${item?.name || 'unknown'}`;
    },
    toggleAccordion(key) {
      this.openIndex = this.openIndex === key ? null : key;
    },
    escapeHtml(value) {
      return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    },
    warehouseSummaryReportHtml(rows, { forDownload = false } = {}) {
      const generatedAt = new Date().toLocaleString();
      const generatedBy = this.$page.props.auth?.user?.name || 'N/A';
      const logoSrc = `${window.location.origin}/images/logo.png`;

      return `
        <html>
          <head>
            <meta charset="utf-8" />
            <title>Project(s) / Warehouse(s) Stock Value Summary</title>
            <style>
              :root {
                --ink: #0f172a;
                --muted: #475569;
                --line: #dbe6ef;
                --headBg: #f1f5f9;
                --paper: #ffffff;
                --soft: #f8fbff;
                --brand: #0e7490;
              }
              * { box-sizing: border-box; }
              body {
                margin: 0;
                background: ${forDownload ? '#f8fafc' : '#ffffff'};
                color: var(--ink);
                font-family: "Segoe UI", Tahoma, Arial, sans-serif;
                padding: 28px;
              }
              .sheet {
                max-width: 1100px;
                margin: 0 auto;
                background: var(--paper);
                border: 1px solid var(--line);
                border-radius: 14px;
                overflow: hidden;
                box-shadow: ${forDownload ? '0 18px 50px rgba(15, 23, 42, 0.10)' : 'none'};
              }
              .header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 20px;
                padding: 18px 22px;
                background: linear-gradient(135deg, #f8fafc, #ecfeff);
                border-bottom: 1px solid var(--line);
              }
              .brand {
                display: flex;
                align-items: center;
                gap: 12px;
                min-width: 0;
              }
              .logo {
                width: 300px;
                height: 100px;
                object-fit: contain;
                border-radius: 10px;
                background: #fff;
                border: 1px solid #e2e8f0;
                padding: 6px;
              }
              .title {
                margin: 0;
                font-size: 22px;
                line-height: 1.2;
                letter-spacing: 0.2px;
              }
              .sub {
                margin: 3px 0 0;
                color: var(--muted);
                font-size: 13px;
              }
              .meta {
                text-align: right;
                font-size: 12px;
                color: var(--muted);
              }
              .meta div { margin: 2px 0; }
              .meta strong { color: var(--ink); }
              .content {
                padding: 16px 22px 20px;
                background: var(--soft);
              }
              .stat {
                font-size: 13px;
                color: var(--muted);
                margin: 0 0 10px;
              }
              .stat strong {
                color: var(--brand);
                font-size: 15px;
              }
              table {
                width: 100%;
                border-collapse: collapse;
                background: #fff;
                border: 1px solid var(--line);
                border-radius: 10px;
                overflow: hidden;
              }
              thead {
                background: var(--headBg);
              }
              th, td {
                border-bottom: 1px solid var(--line);
                padding: 10px 12px;
                font-size: 13px;
              }
              th {
                text-align: left;
                text-transform: uppercase;
                letter-spacing: 0.6px;
                color: #334155;
                font-size: 12px;
              }
              td:last-child, th:last-child {
                text-align: right;
              }
              tbody tr:nth-child(even) {
                background: #fcfdff;
              }
              tbody tr:hover {
                background: #f0f9ff;
              }
              .empty {
                text-align: center;
                color: #64748b;
                font-style: italic;
              }
              .footer {
                padding: 10px 22px 14px;
                font-size: 11px;
                color: #64748b;
                border-top: 1px solid var(--line);
                background: #fff;
              }
              @media print {
                body { padding: 0; background: #fff; }
                .sheet { border: 0; border-radius: 0; box-shadow: none; }
                .content { background: #fff; }
                tbody tr:hover { background: transparent; }
              }
            </style>
          </head>
          <body>
            <div class="sheet">
              <div class="header">
                <div class="brand">
                  <img src="${this.escapeHtml(logoSrc)}" alt="Logo" class="logo" />
                  <div>
                    <h1 class="title">Project(s) / Warehouse(s) Stock Value Summary</h1>
                    <p class="sub">RANS WIMS</p>
                  </div>
                </div>
                <div class="meta">
                  <div><strong>Generated By:</strong> ${this.escapeHtml(generatedBy)}</div>
                  <div><strong>Generated Date:</strong> ${this.escapeHtml(generatedAt)}</div>
                </div>
              </div>
              <div class="content">
                <p class="stat">Project(s) / Warehouse(s) Listed: <strong>${rows.length}</strong></p>
                <p class="stat">Total Stock Value: <strong>${this.escapeHtml(this.formatPrice(rows.reduce((sum, row) => sum + Number(row.amount || 0), 0)))} Tsh</strong></p>
                <table>
                  <thead>
                    <tr>
                      <th style="width: 70px;">SN</th>
                      <th>Project(s) / Warehouse(s) Name</th>
                      <th style="width: 220px;">Stock Value</th>
                    </tr>
                  </thead>
                  <tbody>
                    ${rows.map((row, idx) => `
                      <tr>
                        <td>${idx + 1}</td>
                        <td>${this.escapeHtml(row.name)}</td>
                        <td>${this.escapeHtml(this.formatPrice(row.amount))} Tsh</td>
                      </tr>
                    `).join('') || '<tr><td class="empty" colspan="3">No matching project(s) / warehouse(s) found.</td></tr>'}
                  </tbody>
                </table>
              </div>
              <div class="footer">RANS WIMS • Project(s) / Warehouse(s) Stock Value Summary</div>
            </div>
          </body>
        </html>
      `;
    },
    printWarehouseStock() {
      window.open(this.route('dashboard.warehouse_stock_value_print', {
        search: this.warehouseSearch || undefined,
      }), '_blank');
    },
    downloadWarehouseStock() {
      window.open(this.route('dashboard.warehouse_stock_value_pdf', {
        search: this.warehouseSearch || undefined,
      }), '_blank');
    },
    toggleSystemLockdown() {
      if (this.isTogglingLockdown) {
        return;
      }

      this.pendingLockdownState = !this.systemLockdownEnabled;
      this.showLockdownDialog = true;
      this.lockdownFormError = '';
      this.lockdownPassword = '';
    },

    submitLockdownChange() {
      if (!this.lockdownPassword || this.isTogglingLockdown) {
        return;
      }

      this.lockdownFormError = '';
      this.updateSystemLockdown(this.pendingLockdownState, this.lockdownPassword, true);
    },

    closeLockdownDialog(force = false) {
      if (this.isTogglingLockdown && !force) {
        return;
      }

      this.showLockdownDialog = false;
      this.pendingLockdownState = false;
      this.lockdownPassword = '';
      this.lockdownFormError = '';
    },

    updateSystemLockdown(enabled, password = null, fromDialog = false) {
      this.isTogglingLockdown = true;
      const payload = { enabled };
      if (password) {
        payload.password = password;
      }

      axios
        .post(route('settings.system-lockdown.toggle'), payload)
        .then(res => {
          this.systemLockdownEnabled = Boolean(res.data?.enabled);
          if (fromDialog) {
            this.closeLockdownDialog(true);
          }
          this.$inertia.reload({ only: ['settings'] });
        })
        .catch(async error => {
          const message = error?.response?.data?.errors?.password?.[0] || this.$t('Unable to update maintenance lock right now.');
          if (fromDialog) {
            this.lockdownFormError = message;
            return;
          }
          await this.$alert(message);
        })
        .finally(() => {
          this.isTogglingLockdown = false;
        });
    },
    reload() {
      this.$inertia.visit(route('dashboard', { month: +this.month, year: +this.year }), { preserveState: false, preserveScroll: false });
    },
    formatPrice(value) {
      return new Intl.NumberFormat('en-US', { style: 'decimal', minimumFractionDigits: 2 }).format(value);
    },
    svVisible(g) {
      return this.svOpen[g.key] ? g.rows : g.rows.slice(0, this.SV_LIMIT);
    },
    groupPct(row, g) {
      return g.total > 0 ? Math.round((Number(row.amount || 0) / g.total) * 1000) / 10 : 0;
    },
    sharePct(row) {
      const total = this.totalWarehouseStockValue;
      return total > 0 ? Math.round((Number(row.amount || 0) / total) * 1000) / 10 : 0;
    },
    sortValues(data) {
      return Object.values(
        Object.keys(data)
          .sort()
          .reduce((obj, key) => {
            obj[key] = data[key];
            return obj;
          }, {})
      );
    },
    openTopProduct(point) {
      if (!point || !point.item_id) {
        return;
      }
      this.$inertia.visit(this.route('items.show', point.item_id));
    },

    sparklineOptions(data, color) {
      return {
        chart: {
          type: 'spline',
          height: this.canViewOnlineUsers ? 34 : 46,
          margin: [4, 0, 4, 0],
          backgroundColor: 'transparent',
        },
        credits: { enabled: false },
        title: { text: undefined },
        xAxis: { visible: false },
        yAxis: { visible: false, min: 0 },
        legend: { enabled: false },
        tooltip: { enabled: false },
        plotOptions: {
          series: {
            enableMouseTracking: false,
            marker: { enabled: false },
            lineWidth: 2,
          },
        },
        series: [{ data, color }],
      };
    },

    // Shared look for the three big dashboard charts: left-aligned title, dashed
    // light grid, no axis lines, white "glass" tooltip, and a legend that moves
    // below the plot on narrow screens so nothing needs a horizontal scrollbar.
    chartFrame(title, subtitle) {
      return {
        credits: { enabled: false },
        title: { text: title, align: 'left', x: 6, style: { fontWeight: '800', fontSize: '17px', color: '#1e293b' } },
        subtitle: { text: subtitle, align: 'left', x: 6, style: { color: '#64748b', fontSize: '12px' } },
        legend: {
          align: 'right',
          verticalAlign: 'top',
          layout: 'horizontal',
          floating: true,
          y: -2,
          symbolRadius: 6,
          symbolHeight: 10,
          symbolWidth: 10,
          itemStyle: { fontWeight: '700', color: '#475569', fontSize: '12px' },
          itemHoverStyle: { color: '#1e3a8a' },
        },
        tooltip: {
          shared: true,
          useHTML: true,
          shadow: { color: 'rgba(15, 23, 42, 0.25)', offsetX: 0, offsetY: 10, width: 28, opacity: 0.2 },
          borderWidth: 0,
          borderRadius: 14,
          padding: 12,
          backgroundColor: 'rgba(255, 255, 255, 0.97)',
          style: { color: '#0f172a', fontSize: '12px' },
          headerFormat: '<div style="font-weight:800;margin-bottom:6px;color:#1e3a8a">{point.key}</div>',
          pointFormat:
            '<div style="display:flex;justify-content:space-between;gap:22px;line-height:1.7"><span><span style="color:{point.color}">&#9679;</span> {series.name}</span><b>{point.y}</b></div>',
        },
        responsive: {
          rules: [
            {
              condition: { maxWidth: 640 },
              chartOptions: {
                legend: { floating: false, align: 'center', verticalAlign: 'bottom', y: 0 },
                title: { style: { fontSize: '15px' } },
              },
            },
          ],
        },
      };
    },
    chartAxes(categories) {
      return {
        xAxis: {
          categories,
          crosshair: { width: 1, color: 'rgba(100, 116, 139, 0.35)', dashStyle: 'Dash' },
          lineWidth: 0,
          tickLength: 0,
          labels: { style: { color: '#94a3b8', fontSize: '11px', fontWeight: '600' } },
        },
        yAxis: {
          min: 0,
          allowDecimals: false,
          title: { text: null },
          gridLineDashStyle: 'Dash',
          gridLineColor: '#e2e8f0',
          labels: { style: { color: '#94a3b8', fontSize: '11px' } },
        },
      };
    },
    movementSeries(source, type, extra = {}) {
      const defs = [
        { key: 'checkin', name: this.$t('Checkins'), color: '#10b981' },
        { key: 'checkout', name: this.$t('Checkouts'), color: '#f59e0b' },
        { key: 'transfer', name: this.$t('Transfers'), color: '#3b82f6' },
        { key: 'adjustment', name: this.$t('Adjustments'), color: '#a855f7' },
      ];
      return defs.map(d => {
        const base = { type, name: d.name, color: d.color, data: this.sortValues(source[d.key]) };
        if (type === 'areaspline') {
          base.lineWidth = 2.5;
          base.fillColor = {
            linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 },
            stops: [
              [0, Highcharts.color(d.color).setOpacity(0.32).get('rgba')],
              [1, Highcharts.color(d.color).setOpacity(0).get('rgba')],
            ],
          };
          base.marker = { enabled: false, symbol: 'circle', radius: 4, states: { hover: { enabled: true, lineWidth: 2, lineColor: '#ffffff' } } };
        }
        return { ...base, ...extra };
      });
    },
    // Year Overview: smooth gradient areas (was grouped columns).
    barChartOptions() {
      const keys = Object.keys(this.chart.year.checkin).sort();
      const frame = this.chartFrame(this.$t('Year Overview'), this.$t('Please review the chart below'));
      return {
        ...frame,
        ...this.chartAxes(keys.map(d => this.$month(d))),
        chart: { type: 'areaspline', height: 340, spacingTop: 18, backgroundColor: 'transparent', style: { fontFamily: 'Montserrat' } },
        series: this.movementSeries(this.chart.year, 'areaspline'),
      };
    },
    // Month Overview: rounded stacked columns (was four spline lines).
    radialChartOptions() {
      const keys = Object.keys(this.chart.month.checkin).sort();
      const frame = this.chartFrame(this.$t('Month Overview'), this.$t('Please review the chart below'));
      const axes = this.chartAxes(keys.map(d => new Date(d + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' })));
      axes.xAxis.labels = { ...axes.xAxis.labels, rotation: 0, autoRotation: false, step: Math.max(1, Math.ceil(keys.length / 7)) };
      return {
        ...frame,
        ...axes,
        chart: { type: 'column', height: 340, spacingTop: 18, backgroundColor: 'transparent', style: { fontFamily: 'Montserrat' } },
        plotOptions: {
          column: { stacking: 'normal', borderWidth: 0, borderRadius: 3, groupPadding: 0.08, pointPadding: 0.04, maxPointWidth: 22 },
        },
        series: this.movementSeries(this.chart.month, 'column'),
      };
    },
    // Top Products: donut with the total in the middle (was a plain pie).
    pieChartOptions() {
      const vm = this;
      const data = [...this.top_products]
        .sort((a, b) => (a.y < b.y ? 1 : b.y < a.y ? -1 : 0))
        .map((i, ii) => (ii ? i : { ...i, sliced: true, selected: true }));
      const total = data.reduce((sum, p) => sum + Number(p.y || 0), 0);
      const frame = this.chartFrame(this.$t('Top Products'), this.$t('Top 10 products for the month'));

      return {
        credits: frame.credits,
        title: frame.title,
        subtitle: frame.subtitle,
        colors: ['#2563eb', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4', '#ec4899', '#64748b', '#84cc16', '#f97316'],
        accessibility: { point: { valueSuffix: '%' } },
        legend: {
          layout: 'vertical',
          align: 'right',
          verticalAlign: 'middle',
          itemMarginBottom: 6,
          symbolRadius: 6,
          symbolHeight: 10,
          symbolWidth: 10,
          itemStyle: { fontWeight: '700', color: '#475569', fontSize: '12px', textOverflow: 'ellipsis' },
          itemHoverStyle: { color: '#1e3a8a' },
        },
        tooltip: {
          shared: false,
          useHTML: true,
          shadow: frame.tooltip.shadow,
          borderWidth: 0,
          borderRadius: 14,
          padding: 12,
          backgroundColor: 'rgba(255, 255, 255, 0.97)',
          style: { color: '#0f172a' },
          formatter: function () {
            const point = this.point;
            const itemName = point.item_name || point.name || vm.$t('Unknown');
            const unit = point.unit ? ' ' + point.unit : '';
            return (
              '<div style="min-width:180px">' +
              '<div style="font-weight:800;margin-bottom:6px;color:#1e3a8a">' + itemName + '</div>' +
              '<div style="color:#475569">' + vm.$t('Code') + ': <b>' + point.name + '</b></div>' +
              '<div style="color:#475569">' + vm.$t('Stock in Hand') + ': <b>' + Highcharts.numberFormat(point.stock_on_hand || 0, 0, '.', ',') + unit + '</b></div>' +
              '<div style="color:#475569">' + vm.$t('Share') + ': <b style="color:' + point.color + '">' + Highcharts.numberFormat(point.percentage, 1) + '%</b></div>' +
              '</div>'
            );
          },
        },
        chart: {
          type: 'pie',
          height: 340,
          spacingTop: 18,
          backgroundColor: 'transparent',
          plotShadow: false,
          style: { fontFamily: 'Montserrat' },
          events: {
            render: function () {
              const chart = this;
              const serie = chart.series[0];

              // Total in the donut hole, re-drawn on every render/resize.
              if (chart.centerLabel) {
                chart.centerLabel.destroy();
              }
              if (serie && serie.center) {
                const cx = chart.plotLeft + serie.center[0];
                const cy = chart.plotTop + serie.center[1];
                // SVG text anchored 'middle' on the ring's own centre, so the pair sits dead-centre
                // in the hole regardless of how wide the number is (the old fixed-width HTML box
                // drifted left/up). Number baseline is nudged so the two-line block is balanced.
                chart.centerLabel = chart.renderer.g('donut-center-label').add();
                chart.renderer
                  .text(Highcharts.numberFormat(total, 0, '.', ','), cx, cy + 1)
                  .attr({ align: 'center' })
                  .css({ fontSize: '22px', fontWeight: '800', color: '#1e293b', fontFamily: 'Montserrat, sans-serif' })
                  .add(chart.centerLabel);
                chart.renderer
                  .text(String(vm.$t('Movement')).toUpperCase(), cx, cy + 17)
                  .attr({ align: 'center' })
                  .css({ fontSize: '10px', fontWeight: '700', color: '#94a3b8', letterSpacing: '.05em', fontFamily: 'Montserrat, sans-serif' })
                  .add(chart.centerLabel);
              }

              (serie?.points || []).forEach(function (point) {
                const legendEl = point.legendItem && point.legendItem.group && point.legendItem.group.element;
                if (!legendEl || legendEl.dataset.tooltipBound) {
                  return;
                }
                legendEl.dataset.tooltipBound = '1';
                legendEl.style.cursor = 'pointer';
                legendEl.addEventListener('mouseover', function () {
                  point.setState('hover');
                  chart.tooltip.refresh(point);
                });
                legendEl.addEventListener('mouseout', function () {
                  point.setState('');
                  chart.tooltip.hide();
                });
              });
            },
          },
        },
        plotOptions: {
          pie: {
            innerSize: '68%',
            size: '92%',
            borderWidth: 3,
            borderColor: '#ffffff',
            borderRadius: 8,
            slicedOffset: 8,
            allowPointSelect: true,
            cursor: 'pointer',
            dataLabels: { enabled: false },
            showInLegend: true,
            center: ['38%', '52%'],
            point: {
              events: {
                click: function () {
                  vm.openTopProduct(this);
                },
              },
            },
          },
        },
        responsive: {
          rules: [
            {
              condition: { maxWidth: 560 },
              chartOptions: {
                legend: { layout: 'horizontal', align: 'center', verticalAlign: 'bottom' },
                plotOptions: { pie: { center: ['50%', '42%'], size: '78%' } },
                chart: { height: 400 },
              },
            },
          ],
        },
        series: [{ colorByPoint: true, name: this.$t('Movement'), data }],
      };
    },
  },
};
</script>

<style scoped>
.dashboard-liquid {
  position: relative;
  border-radius: 28px;
  padding: 12px;
  background: linear-gradient(180deg, #eef4ff 0%, #f6f9ff 45%, #fbfdff 100%);
}

.liquid-panel,
.liquid-chart-shell,
.liquid-table-shell,
.liquid-stat-card,
.liquid-filter-row,
.hero-card,
.kpi-card {
  border: 1px solid #eef1f6;
  background: #ffffff;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03), 0 10px 24px rgba(15, 23, 42, 0.05);
}

/* Thin, slow-drifting silver/blue gradient outline on every dashboard card —
   a mask-composite ring (not a filled background) so it stays a hairline
   regardless of each card's border-radius, and a 10s ease-in-out drift
   rather than a spin/pulse so it reads as ambient, not attention-grabbing. */
.hero-card,
.kpi-card,
.liquid-stat-card {
  position: relative;
}
.hero-card::before,
.kpi-card::before,
.liquid-stat-card::before {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: inherit;
  padding: 2px;
  background: linear-gradient(115deg, rgba(148, 163, 184, 0.3) 0%, rgba(226, 232, 240, 0.95) 22%, rgba(96, 165, 250, 0.7) 45%, rgba(226, 232, 240, 0.95) 68%, rgba(148, 163, 184, 0.3) 100%);
  background-size: 260% 260%;
  -webkit-mask: linear-gradient(#000, #000) content-box, linear-gradient(#000, #000);
  -webkit-mask-composite: xor;
  mask-composite: exclude;
  animation: card-glow-drift 9s ease-in-out infinite;
  pointer-events: none;
}
@keyframes card-glow-drift {
  0%, 100% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
}
@media (prefers-reduced-motion: reduce) {
  .hero-card::before,
  .kpi-card::before,
  .liquid-stat-card::before {
    animation: none;
    background-position: 30% 50%;
  }
}

.liquid-panel {
  border-radius: 24px;
  padding: 16px;
  background: transparent;
  border: none;
  box-shadow: none;
}

.liquid-lockdown {
  border-radius: 20px;
  background: linear-gradient(135deg, #0f172a, #1e3a8a);
  border: 1px solid rgba(148, 163, 184, 0.25);
  box-shadow: 0 10px 30px rgba(15, 23, 42, 0.18);
}

.system-status-badge {
  position: relative;
  transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.system-status-badge:hover {
  transform: scale(1.1) rotate(-4deg);
}
.system-status-badge::after {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: 9999px;
  animation: system-status-pulse 2.4s ease-out infinite;
}
.system-status-badge--live::after {
  box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.55);
}
.system-status-badge--alert::after {
  box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.55);
  animation-duration: 1.3s;
}

@keyframes system-status-pulse {
  0% {
    box-shadow: 0 0 0 0 currentColor;
    opacity: 0.55;
  }
  100% {
    box-shadow: 0 0 0 10px currentColor;
    opacity: 0;
  }
}

.liquid-stat-card {
  display: block;
  padding: 14px;
  border-radius: 18px;
  transition: transform 160ms ease, box-shadow 160ms ease, border-color 160ms ease;
}

.liquid-stat-card:hover {
  transform: translateY(-2px);
  border-color: #dbe7ff;
  box-shadow: 0 12px 28px rgba(37, 99, 235, 0.12);
}

.liquid-stat-card,
.kpi-card,
.hero-card {
  animation: card-fade-in 0.45s cubic-bezier(0.16, 1, 0.3, 1) both;
}

.liquid-stat-card:active {
  transform: translateY(0) scale(0.98);
}

@keyframes card-fade-in {
  from {
    opacity: 0;
    transform: translateY(10px) scale(0.98);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

.grid > .hero-card:nth-child(1),
.grid > .kpi-card:nth-child(1),
.grid > .liquid-stat-card:nth-child(1) { animation-delay: 0.02s; }
.grid > .kpi-card:nth-child(2),
.grid > .liquid-stat-card:nth-child(2) { animation-delay: 0.07s; }
.grid > .liquid-stat-card:nth-child(3) { animation-delay: 0.12s; }
.grid > .liquid-stat-card:nth-child(4) { animation-delay: 0.17s; }
.grid > .liquid-stat-card:nth-child(5) { animation-delay: 0.22s; }
.grid > .liquid-stat-card:nth-child(6) { animation-delay: 0.27s; }
.grid > .liquid-stat-card:nth-child(7) { animation-delay: 0.32s; }
.grid > .liquid-stat-card:nth-child(8) { animation-delay: 0.37s; }

/* Low-tone (soft, muted) gradients per card so the dashboard doesn't read as
   a wall of identical white boxes — cycles through 8 pastel hues, restarting
   for each grid section since nth-child resets per parent. Kept subtle
   (light-to-lighter, same low saturation family) so the existing dark-gray
   text stays fully readable and no card visually shouts over the others. */
/* Gradients are intentionally limited to the shortcut cards + the hero
   greeting card — the Checkins/Checkouts graph cards and everything below
   the System Access Control banner (Transfers/Adjustments/.../Total
   Records) stay plain white so the numeric data reads cleanly. */
.gradient-cards > *:nth-child(8n+1) { background: linear-gradient(135deg, #fdf2f8, #fffbfd); }
.gradient-cards > *:nth-child(8n+2) { background: linear-gradient(135deg, #fefce8, #fffdf5); }
.gradient-cards > *:nth-child(8n+3) { background: linear-gradient(135deg, #eff6ff, #f9fbff); }
.gradient-cards > *:nth-child(8n+4) { background: linear-gradient(135deg, #f0fdf4, #f8fdfa); }
.gradient-cards > *:nth-child(8n+5) { background: linear-gradient(135deg, #f5f3ff, #fbfaff); }
.gradient-cards > *:nth-child(8n+6) { background: linear-gradient(135deg, #fff7ed, #fffbf5); }
.gradient-cards > *:nth-child(8n+7) { background: linear-gradient(135deg, #ecfeff, #f7feff); }
.gradient-cards > *:nth-child(8n+8) { background: linear-gradient(135deg, #fff1f2, #fffafa); }

.hero-card {
  background: linear-gradient(135deg, #fdf2f8, #fffbfd);
}

/* Warehouse Stock Value Summary table — one-time staggered row reveal on
   load (see the `justLoaded` flag gating it in the template), matching the
   stat cards' own card-fade-in timing/easing above for a consistent feel. */
.dash-row-in {
  animation: card-fade-in 0.4s cubic-bezier(0.16, 1, 0.3, 1) both;
}

@media (prefers-reduced-motion: reduce) {
  .liquid-stat-card,
  .kpi-card,
  .hero-card,
  .dash-row-in {
    animation: none;
  }
}

.liquid-shortcut-card {
  padding: 12px 14px;
}

.liquid-live-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #059669;
  background: #ecfdf5;
  border: 1px solid #bbf7d0;
}

.liquid-live-dot {
  width: 7px;
  height: 7px;
  border-radius: 999px;
  background: #10b981;
  box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.6);
  animation: liquid-live-pulse 1.8s ease-out infinite;
}

@keyframes liquid-live-pulse {
  0% {
    box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.55);
  }
  70% {
    box-shadow: 0 0 0 7px rgba(16, 185, 129, 0);
  }
  100% {
    box-shadow: 0 0 0 0 rgba(16, 185, 129, 0);
  }
}

.liquid-shortcut-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 40px;
  width: 40px;
  border-radius: 12px;
  flex-shrink: 0;
  animation: icon-float 4s ease-in-out infinite;
}

@keyframes icon-float {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-3px); }
}

.liquid-shortcut-card:nth-of-type(2) .liquid-shortcut-icon { animation-delay: 0.3s; }
.liquid-shortcut-card:nth-of-type(3) .liquid-shortcut-icon { animation-delay: 0.6s; }
.liquid-shortcut-card:nth-of-type(4) .liquid-shortcut-icon { animation-delay: 0.9s; }

@media (prefers-reduced-motion: reduce) {
  .liquid-shortcut-icon {
    animation: none;
  }
}

.liquid-shortcut-card {
  cursor: grab;
  transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease, opacity 0.15s ease;
}
.liquid-shortcut-card:active { cursor: grabbing; }
.liquid-shortcut-drag-handle {
  flex: none;
  color: #cbd5e1;
  cursor: grab;
}
.liquid-shortcut-drag-over {
  border-color: #be185d !important;
  box-shadow: 0 0 0 2px rgba(190, 24, 93, 0.25) !important;
  transform: scale(1.02);
}

html.dark .liquid-shortcut-drag-handle { color: #475569; }

.liquid-filter-row {
  position: relative;
  z-index: 30;
  border-radius: 16px;
  padding: 10px;
}

.liquid-chart-shell {
  border-radius: 20px;
}

.liquid-table-shell {
  border-radius: 24px;
}

.liquid-search-input {
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  box-shadow: none;
}

.liquid-search-input:focus {
  outline: none;
  border-color: #93c5fd;
  background: #ffffff;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
}

.liquid-action-btn {
  border-radius: 12px;
  color: #fff;
  border: none;
  transition: transform 150ms ease, filter 150ms ease;
}

.liquid-action-btn:hover {
  transform: translateY(-1px);
  filter: brightness(1.08);
}

.liquid-action-btn-dark {
  background: #1e293b;
}

.liquid-action-btn-cyan {
  background: #2563eb;
}

/* Hero greeting card */
.hero-card {
  border-radius: 20px;
  padding: 18px 20px;
  background: linear-gradient(135deg, #eff6ff 0%, #ffffff 100%);
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.hero-avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 42px;
  border-radius: 999px;
  background: #2563eb;
  color: #fff;
  font-weight: 700;
  font-size: 0.85rem;
  margin-bottom: 10px;
  cursor: zoom-in;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.hero-avatar:hover {
  transform: scale(1.08);
  box-shadow: 0 4px 10px -2px rgba(15, 23, 42, 0.25);
}

.hero-avatar-zoom {
  position: fixed;
  transform: scale(0.88);
  transform-origin: top center;
  z-index: 2147483000;
  background: #fff;
  border-radius: 1rem;
  padding: 0.6rem;
  box-shadow: 0 20px 40px -10px rgba(15, 23, 42, 0.35), 0 0 0 1px rgba(15, 23, 42, 0.05);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.16s ease, transform 0.16s ease;
}
.hero-avatar-zoom-show { opacity: 1; transform: scale(1); }
.hero-avatar-zoom img {
  display: block;
  width: var(--az, 160px);
  height: var(--az, 160px);
  object-fit: cover;
  border-radius: 0.7rem;
}
.hero-avatar-zoom-initials {
  display: flex;
  align-items: center;
  justify-content: center;
  width: var(--az, 160px);
  height: var(--az, 160px);
  border-radius: 0.7rem;
  background: #2563eb;
  color: #fff;
  font-weight: 700;
  font-size: 2.5rem;
}
.hero-avatar-zoom-name { text-align: center; font-size: 0.78rem; font-weight: 700; color: #374151; margin-top: 0.5rem; }

html.dark .hero-avatar-zoom { background: #1e293b; box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.08); }
html.dark .hero-avatar-zoom-name { color: #e2e8f0; }

.hero-avatar-photo {
  padding: 0;
  overflow: hidden;
  background: #e2e8f0;
}

.hero-avatar-img {
  width: 100%;
  height: 100%;
  border-radius: 999px;
  object-fit: cover;
}

@keyframes avatar-pulse {
  0% { box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.4); }
  70% { box-shadow: 0 0 0 9px rgba(37, 99, 235, 0); }
  100% { box-shadow: 0 0 0 0 rgba(37, 99, 235, 0); }
}

.hero-greeting {
  font-size: 1.65rem;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.2;
}

.hero-subtitle {
  font-size: 0.95rem;
  color: #64748b;
  margin-top: 4px;
}

/* Hero channel quick-links */
.hero-channels {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 10px;
}
.hero-channel-btn {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 9999px;
  color: #fff;
  flex-shrink: 0;
  opacity: 0.42;
  filter: grayscale(0.6);
  box-shadow: 0 2px 6px -2px rgba(15, 23, 42, 0.25);
  transition: transform 0.18s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.2s ease, filter 0.2s ease, box-shadow 0.2s ease;
}
.hero-channel-btn.is-live {
  opacity: 1;
  filter: grayscale(0);
}
.hero-channel-btn:hover {
  transform: translateY(-2px) scale(1.08);
  box-shadow: 0 8px 16px -4px rgba(15, 23, 42, 0.35);
}
.hero-channel-btn:active { transform: translateY(0) scale(0.96); }

.hero-ch-emerald { background: linear-gradient(145deg, #34d399, #047857); }
.hero-ch-indigo { background: linear-gradient(145deg, #818cf8, #4338ca); }
.hero-ch-amber { background: linear-gradient(145deg, #fbbf24, #b45309); }

.hero-channel-dot {
  position: absolute;
  bottom: -1px;
  right: -1px;
  width: 9px;
  height: 9px;
  border-radius: 9999px;
  border: 2px solid #ffffff;
  background: #cbd5e1;
}
.hero-channel-dot.is-live {
  background: #22c55e;
  animation: hero-channel-dot-pulse 2s ease-out infinite;
}
@keyframes hero-channel-dot-pulse {
  0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.55); }
  70% { box-shadow: 0 0 0 5px rgba(34, 197, 94, 0); }
  100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
}

.hero-channel-fixed-tip {
  position: fixed;
  transform: translate(-50%, -4px);
  z-index: 9999;
  white-space: nowrap;
  padding: 0.4rem 0.7rem;
  border-radius: 0.5rem;
  font-size: 0.7rem;
  font-weight: 600;
  background: #0f172a;
  color: #f1f5f9;
  box-shadow: 0 12px 24px -8px rgba(15, 23, 42, 0.45);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.hero-channel-fixed-tip-show { opacity: 1; transform: translate(-50%, 0); }

html.dark .hero-channel-dot { border-color: #1e293b; }

.hero-meta {
  text-align: right;
}

.hero-date {
  font-size: 0.92rem;
  font-weight: 600;
  color: #475569;
  white-space: nowrap;
}

.hero-calendar-btn {
  display: inline-flex; align-items: center; justify-content: center; width: 1.9rem; height: 1.9rem; padding: 0; border: 0; border-radius: 9999px;
  background: rgba(99, 102, 241, 0.1); color: #4f46e5; flex-shrink: 0; transition: background 0.15s ease, transform 0.15s ease; position: relative;
}
.hero-calendar-btn:hover { background: rgba(99, 102, 241, 0.18); transform: translateY(-1px); }
.hero-calendar-btn-pulse::after {
  content: ''; position: absolute; inset: -3px; border-radius: 9999px; border: 2px solid #f43f5e; opacity: 0.6;
  animation: hero-calendar-pulse 1.8s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}
@keyframes hero-calendar-pulse {
  0% { transform: scale(0.9); opacity: 0.7; }
  70% { transform: scale(1.4); opacity: 0; }
  100% { transform: scale(1.4); opacity: 0; }
}
.hero-calendar-badge {
  position: absolute; top: -4px; right: -4px; min-width: 1rem; height: 1rem; padding: 0 3px; border-radius: 9999px;
  background: #f43f5e; color: #fff; font-size: 0.6rem; font-weight: 800; line-height: 1rem; text-align: center;
  animation: hero-badge-pop 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
}
@keyframes hero-badge-pop {
  0% { transform: scale(0); }
  70% { transform: scale(1.3); }
  100% { transform: scale(1); }
}
.hero-calendar-icon { animation: hero-calendar-flip 3.2s ease-in-out infinite; transform-origin: 50% 15%; }
@keyframes hero-calendar-flip {
  0%, 60%, 100% { transform: rotate(0deg); }
  63% { transform: rotate(-8deg); }
  66% { transform: rotate(6deg); }
  69% { transform: rotate(-3deg); }
  72% { transform: rotate(0deg); }
}

/* Compliance calendar and Site Meetings icons share one size; only the icon, colour and glow differ */
.hero-calendar-btn, .hero-meet-btn { box-sizing: border-box; width: 2rem !important; height: 2rem !important; min-width: 2rem !important; min-height: 2rem !important; max-height: 2rem; margin: 0 !important; padding: 0 !important; border-radius: 9999px !important; flex: 0 0 2rem; }
/* Compliance calendar: a warm amber ring that breathes, and a soft glow */
.hero-calendar-btn { background: linear-gradient(145deg, rgba(251, 191, 36, 0.22), rgba(249, 115, 22, 0.14)); color: #c2410c; box-shadow: 0 0 0 1.5px rgba(251, 146, 60, 0.55), 0 0 12px rgba(251, 146, 60, 0.35); animation: hero-cal-glow 2.8s ease-in-out infinite; }
.hero-calendar-btn:hover { background: linear-gradient(145deg, rgba(251, 191, 36, 0.35), rgba(249, 115, 22, 0.25)); box-shadow: 0 0 0 2px rgba(249, 115, 22, 0.8), 0 0 18px rgba(249, 115, 22, 0.6); animation-play-state: paused; }
@keyframes hero-cal-glow { 0%, 100% { box-shadow: 0 0 0 1.5px rgba(251, 146, 60, 0.5), 0 0 6px rgba(251, 146, 60, 0.25); } 50% { box-shadow: 0 0 0 2px rgba(249, 115, 22, 0.9), 0 0 16px rgba(249, 115, 22, 0.6); } }
/* Site Meetings icon: a spinning teal-to-violet outline and a cool glow */
.hero-meet-btn { position: relative; display: inline-flex; align-items: center; justify-content: center; color: #0f766e; background: linear-gradient(145deg, rgba(45, 212, 191, 0.2), rgba(129, 140, 248, 0.2)); text-decoration: none; isolation: isolate; transition: transform 0.15s ease; animation: hero-meet-glow 3.2s ease-in-out infinite; }
.hero-meet-btn::before { content: ''; position: absolute; inset: -2px; z-index: -1; border-radius: inherit; padding: 2px; background: conic-gradient(from var(--lic-angle, 0deg), #2dd4bf, #6366f1, #a78bfa, #2dd4bf); -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0); -webkit-mask-composite: xor; mask-composite: exclude; animation: hero-meet-spin 4s linear infinite; }
@property --lic-angle { syntax: '<angle>'; initial-value: 0deg; inherits: false; }
@keyframes hero-meet-spin { to { --lic-angle: 360deg; } }
@keyframes hero-meet-glow { 0%, 100% { box-shadow: 0 0 6px rgba(45, 212, 191, 0.3); } 50% { box-shadow: 0 0 16px rgba(99, 102, 241, 0.6), 0 0 4px rgba(45, 212, 191, 0.7); } }
.hero-meet-btn:hover { transform: translateY(-1px) scale(1.06); }
.hero-meet-icon { animation: hero-meet-nod 4s ease-in-out infinite; }
@keyframes hero-meet-nod { 0%, 70%, 100% { transform: translateY(0); } 76% { transform: translateY(-2px); } 82% { transform: translateY(1px); } 88% { transform: translateY(0); } }
.hero-meet-badge { position: absolute; top: -5px; right: -5px; min-width: 1rem; height: 1rem; padding: 0 3px; border-radius: 9999px; background: #4f46e5; color: #fff; font-size: 0.6rem; font-weight: 800; line-height: 1rem; text-align: center; box-shadow: 0 0 0 2px #fff; animation: hero-badge-pop 0.35s cubic-bezier(0.34, 1.56, 0.64, 1); }
.cc-tooltip-meet { width: 290px; } .cc-tooltip-contract { width: 310px; } .cc-tooltip-contract .cc-tooltip-title .meet-title__a { color: #047857; }
.ct-pills { display: flex; flex-wrap: wrap; gap: 5px; margin: 2px 0 8px; } .ct-pill { padding: 2px 9px; border-radius: 9999px; font-size: 0.66rem; font-weight: 800; white-space: nowrap; }
.ct-pill.is-expired { color: #9f1239; background: #ffe4e6; } .ct-pill.is-urgent { color: #9a3412; background: #ffedd5; } .ct-pill.is-soon { color: #1e40af; background: #dbeafe; }
.ct-who { display: grid; min-width: 0; flex: 1; line-height: 1.25; } .ct-who b { font-size: 0.78rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .ct-who small { font-size: 0.66rem; color: #64748b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .cc-tooltip-meet .cc-tooltip-title { color: #4f46e5; }
.cc-tooltip-meet .meet-title { display: grid; gap: 2px; line-height: 1.25; } .cc-tooltip-meet .meet-title__a { color: #7f1d1d; font-weight: 800; } .cc-tooltip-meet .meet-title__b { color: #4f46e5; font-weight: 700; font-size: 0.92em; }
html.dark .cc-tooltip-meet .meet-title__a { color: #fda4af; } html.dark .cc-tooltip-meet .meet-title__b { color: #c7d2fe; }
.meet-when { font-weight: 800; font-size: 0.66rem; color: #6366f1; white-space: nowrap; flex-shrink: 0; }
html.dark .hero-meet-btn { color: #99f6e4; } html.dark .hero-meet-badge { box-shadow: 0 0 0 2px #0f172a; }
@media (prefers-reduced-motion: reduce) { .hero-calendar-btn, .hero-meet-btn, .hero-meet-btn::before, .hero-meet-icon { animation: none; } }
/* each of the three dashboard icons has its own colours: outline, icon, badge and number */
.hero-calendar-badge { background: #ea580c; box-shadow: 0 0 0 2px #fff; }
.hero-meet-blue { color: #1d4ed8; background: linear-gradient(145deg, rgba(56, 189, 248, 0.22), rgba(37, 99, 235, 0.16)); --g1: #38bdf8; --g2: #2563eb; --g3: #818cf8; --gl1: rgba(56, 189, 248, 0.35); --gl2: rgba(37, 99, 235, 0.6); }
.hero-meet-blue::before { background: conic-gradient(from var(--lic-angle, 0deg), #38bdf8, #2563eb, #818cf8, #38bdf8); }
.hero-meet-blue .hero-meet-badge { background: #2563eb; }
.hero-meet-green { color: #047857; background: linear-gradient(145deg, rgba(52, 211, 153, 0.24), rgba(163, 230, 53, 0.18)); animation-name: hero-green-glow; }
.hero-meet-green::before { background: conic-gradient(from var(--lic-angle, 0deg), #34d399, #84cc16, #059669, #34d399); }
.hero-meet-green .hero-meet-badge { background: #059669; }
.hero-meet-blue { animation-name: hero-blue-glow; }
@keyframes hero-blue-glow { 0%, 100% { box-shadow: 0 0 6px rgba(56, 189, 248, 0.3); } 50% { box-shadow: 0 0 16px rgba(37, 99, 235, 0.6), 0 0 4px rgba(56, 189, 248, 0.7); } }
@keyframes hero-green-glow { 0%, 100% { box-shadow: 0 0 6px rgba(52, 211, 153, 0.3); } 50% { box-shadow: 0 0 16px rgba(5, 150, 105, 0.6), 0 0 4px rgba(132, 204, 22, 0.6); } }
html.dark .hero-meet-blue { color: #93c5fd; } html.dark .hero-meet-green { color: #6ee7b7; } html.dark .hero-calendar-badge { box-shadow: 0 0 0 2px #0f172a; }


.cc-tooltip {
  position: fixed; transform: translate(-50%, 0); z-index: 9999; width: 270px; pointer-events: none;
  background: #ffffff; border-radius: 0.9rem; padding: 0.75rem 0.85rem; box-shadow: 0 20px 40px -10px rgba(15, 23, 42, 0.35), 0 0 0 1px rgba(15, 23, 42, 0.05);
  opacity: 0; transition: opacity 0.16s ease, transform 0.16s ease;
}
.cc-tooltip-show { opacity: 1; transform: translate(-50%, 4px); }
.cc-tooltip-title {
  display: flex; align-items: center; gap: 6px; font-size: 0.68rem; font-weight: 800; color: #6366f1; text-transform: uppercase;
  letter-spacing: 0.03em; margin-bottom: 0.5rem;
}
.cc-tooltip-count {
  display: inline-flex; align-items: center; justify-content: center; min-width: 1.1rem; height: 1.1rem; padding: 0 4px; border-radius: 9999px;
  background: #eef2ff; color: #4338ca; font-size: 0.66rem;
}
.cc-tooltip-empty { font-size: 0.75rem; color: #9ca3af; padding: 0.5rem 0; text-align: center; }
.cc-tooltip-list { display: flex; flex-direction: column; gap: 0.4rem; }
.cc-tooltip-row {
  display: flex; align-items: center; gap: 0.45rem; font-size: 0.75rem; padding: 0.2rem 0; border-bottom: 1px solid #f1f5f9;
  animation: cc-row-in 0.3s ease backwards;
}
@keyframes cc-row-in {
  from { opacity: 0; transform: translateX(-6px); }
  to { opacity: 1; transform: translateX(0); }
}
.cc-tooltip-row:last-child { border-bottom: none; }
.cc-tooltip-row-dot { width: 0.4rem; height: 0.4rem; border-radius: 9999px; flex-shrink: 0; }
.cc-tooltip-row-title { color: #1f2937; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; min-width: 0; }
.cc-tooltip-row-days { font-weight: 700; font-size: 0.68rem; white-space: nowrap; flex-shrink: 0; }
.cc-dot-critical { background-color: #e11d48; }
.cc-dot-warning { background-color: #f59e0b; }
.cc-dot-info { background-color: #818cf8; }
.cc-text-critical { color: #e11d48; }
.cc-text-warning { color: #b45309; }
.cc-text-info { color: #4338ca; }
.cc-tooltip-more { font-size: 0.68rem; color: #6366f1; font-weight: 700; text-align: center; margin-top: 0.4rem; }
.cc-tooltip-hint { margin-top: 0.5rem; font-size: 0.66rem; color: #9ca3af; font-style: italic; text-align: center; }

html.dark .cc-tooltip { background: #1e293b; box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.08); }
html.dark .cc-tooltip-row { border-color: rgba(255, 255, 255, 0.08); }
html.dark .cc-tooltip-row-title { color: #e2e8f0; }
html.dark .cc-tooltip-count { background: rgba(99, 102, 241, 0.2); color: #c7d2fe; }

.compliance-calendar-modal { max-width: 100%; overflow-x: auto; }
.cc-nav-btn {
  display: inline-flex; align-items: center; justify-content: center; width: 1.9rem; height: 1.9rem; padding: 0; border: 0; border-radius: 9999px;
  background: #f1f5f9; color: #475569; flex-shrink: 0;
}
.cc-nav-btn:hover { background: #e2e8f0; }
html.dark .cc-nav-btn { background: rgba(255, 255, 255, 0.08); color: #cbd5e1; }

html.dark .hero-calendar-btn { background: rgba(129, 140, 248, 0.18); color: #c7d2fe; }

.hero-weather {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 6px;
  margin-top: 6px;
  white-space: nowrap;
}

.hero-weather-icon {
  font-size: 1.4rem;
  line-height: 1;
}

.hero-weather-temp {
  font-size: 1.05rem;
  font-weight: 700;
  color: #0f172a;
}

.hero-weather-desc {
  font-size: 0.85rem;
  color: #64748b;
}

@media (max-width: 480px) {
  .hero-weather-desc {
    display: none;
  }
}

/* KPI cards with sparkline */
.kpi-card {
  border-radius: 20px;
  padding: 16px 18px;
}

.kpi-label {
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #94a3b8;
}

.kpi-value {
  font-size: 1.75rem;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.2;
  margin-top: 2px;
}

.kpi-trend {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 3px 8px;
  border-radius: 999px;
  font-size: 0.72rem;
  font-weight: 700;
}

.kpi-trend-up {
  background: #ecfdf5;
  color: #059669;
  animation: trend-pulse-up 4s ease-in-out infinite;
}

.kpi-trend-down {
  background: #fef2f2;
  color: #dc2626;
  animation: trend-pulse-down 4s ease-in-out infinite;
}

@keyframes trend-pulse-up {
  0%, 80%, 100% { box-shadow: 0 0 0 0 rgba(5, 150, 105, 0); }
  90% { box-shadow: 0 0 0 4px rgba(5, 150, 105, 0.22); }
}

@keyframes trend-pulse-down {
  0%, 80%, 100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0); }
  90% { box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.22); }
}

@media (prefers-reduced-motion: reduce) {
  .hero-avatar,
  .kpi-trend-up,
  .kpi-trend-down {
    animation: none;
  }
}

.kpi-sparkline {
  margin-top: 4px;
}

/* Checkins/Checkouts + Online Users sit in the space beside the greeting card and never grow taller than it:
   the stack takes the hero's row height (height:0 + min-height:100%) and shares it between compact KPI cards and the panel. */
.kpi-stack { display: contents; }
@media (min-width: 1024px) {
  .kpi-stack.is-grid {
    display: grid;
    grid-column: span 2 / span 2;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    grid-template-rows: minmax(0, 1fr) auto;
    gap: 1rem;
    height: 0;
    min-height: 100%;
    align-self: stretch;
  }
  /* with the Prayer Times card there are three across: Checkins and Checkouts are squeezed to make room for it */
  .kpi-stack.is-grid.has-prayer { grid-template-columns: minmax(0, 0.8fr) minmax(0, 0.8fr) minmax(0, 1.3fr); }
  .kpi-stack.is-grid > .online-users-slot,
  .kpi-stack.is-grid > section { grid-column: 1 / -1; }
  .kpi-stack.is-grid .kpi-card { min-height: 0; overflow: hidden; padding: 12px 16px; }
  .kpi-stack.is-grid .kpi-value { line-height: 1.1; }
  .kpi-stack.is-grid .kpi-sparkline { margin-top: 0; }
}

@media (max-width: 767.98px) {
  .dashboard-liquid {
    border-radius: 18px;
    padding: 8px;
  }

  .liquid-panel {
    border-radius: 16px;
    padding: 12px;
  }

  .liquid-stat-card {
    border-radius: 14px;
    padding: 12px;
  }

  .liquid-chart-shell,
  .liquid-table-shell {
    border-radius: 16px;
  }
}

/* ---- Charts: breathing room inside each card ---- */
.liquid-chart-shell {
  padding: 10px 12px 6px;
}

/* ---- Warehouse Stock Value Summary ---- */
.sv-shell { overflow: hidden; padding: 0; }
.sv-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  padding: 20px 22px 14px;
}
.sv-title-row { display: flex; align-items: center; gap: 12px; min-width: 0; }
.sv-title-icon {
  display: inline-flex; align-items: center; justify-content: center; flex: none;
  width: 44px; height: 44px; border-radius: 14px; color: #fff;
  background: linear-gradient(145deg, #60a5fa, #2563eb);
  box-shadow: 0 10px 20px -10px rgba(37, 99, 235, 0.7);
}
.sv-title { font-size: 1.1rem; font-weight: 800; color: #1e293b; letter-spacing: -0.01em; }
.sv-sub { font-size: 0.78rem; color: #64748b; margin-top: 1px; }
.sv-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; width: 100%; }
@media (min-width: 1024px) { .sv-tools { width: auto; } }
.sv-search { position: relative; flex: 1 1 220px; min-width: 0; }
@media (min-width: 1024px) { .sv-search { flex: none; width: 18rem; } }
.sv-search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: #94a3b8; }
.sv-search-input {
  width: 100%; height: 40px; padding: 0 14px 0 36px; font-size: 0.82rem; color: #1e293b;
  border-radius: 9999px; border: 1px solid #dbe4f3; background: #f8fbff;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.sv-search-input:focus { outline: none; border-color: #60a5fa; box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.22); background: #fff; }
.sv-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 7px;
  height: 40px; padding: 0 16px; border-radius: 9999px; font-size: 0.78rem; font-weight: 700;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.sv-btn:hover { transform: translateY(-1px); }
.sv-btn-ghost { color: #1e3a8a; background: #eff6ff; border: 1px solid #bfdbfe; }
.sv-btn-solid { color: #fff; background: linear-gradient(145deg, #60a5fa, #2563eb); box-shadow: 0 10px 18px -10px rgba(37, 99, 235, 0.8); }

.sv-kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; padding: 0 22px 16px; }
@media (max-width: 767.98px) { .sv-kpis { grid-template-columns: 1fr; padding: 0 14px 14px; } .sv-head { padding: 16px 14px 12px; } }
.sv-kpi { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 16px; border: 1px solid transparent; min-width: 0; box-shadow: 0 14px 26px -14px rgba(15, 37, 64, 0.38), 0 2px 6px rgba(15, 37, 64, 0.08); }
html.dark .sv-kpi { box-shadow: 0 14px 28px -14px rgba(0, 0, 0, 0.75), 0 2px 6px rgba(0, 0, 0, 0.3); }
.sv-kpi-icon { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 34px; height: 34px; border-radius: 11px; }
.sv-kpi-label { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #64748b; }
.sv-kpi-value { font-size: 1.05rem; font-weight: 800; color: #1e293b; }
.sv-kpi-value small { font-size: 0.7rem; font-weight: 700; color: #64748b; }
.sv-kpi-clip { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sv-kpi-blue { background: #eff6ff; border-color: #dbeafe; } .sv-kpi-blue .sv-kpi-icon { background: #dbeafe; color: #2563eb; }
.sv-kpi-emerald { background: #ecfdf5; border-color: #d1fae5; } .sv-kpi-emerald .sv-kpi-icon { background: #d1fae5; color: #059669; }
.sv-kpi-violet { background: #f5f3ff; border-color: #ede9fe; } .sv-kpi-violet .sv-kpi-icon { background: #ede9fe; color: #7c3aed; }

.sv-list { padding: 0 14px 16px; }
@media (min-width: 768px) { .sv-list { padding: 0 22px 20px; } }
.sv-colhead, .sv-row {
  display: grid;
  grid-template-columns: 40px minmax(0, 1.5fr) minmax(0, 1.5fr) 74px 24px;
  align-items: center;
  column-gap: 14px;
}
.sv-colhead { white-space: nowrap; padding: 6px 14px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #94a3b8; }
.sv-row {
  padding: 11px 14px; margin-top: 8px; border-radius: 16px; cursor: pointer;
  background: #fff; border: 1px solid #e8eef8;
  transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
}
.sv-row:hover, .sv-row:focus-visible { outline: none; transform: translateY(-1px); border-color: #bfdbfe; box-shadow: 0 14px 26px -18px rgba(37, 99, 235, 0.55); }
.sv-row-open { border-color: #93c5fd; background: #f5f9ff; border-bottom-left-radius: 0; border-bottom-right-radius: 0; margin-bottom: 0; }
.sv-rank { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 10px; font-size: 0.78rem; font-weight: 800; color: #64748b; background: #f1f5f9; }
.sv-rank-top { color: #fff; background: linear-gradient(145deg, #60a5fa, #2563eb); }
.sv-name { display: flex; align-items: center; gap: 10px; min-width: 0; }
.sv-name-icon { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 28px; height: 28px; border-radius: 9px; color: #2563eb; background: #eaf2ff; }
.sv-name-text { font-weight: 700; color: #1e293b; font-size: 0.86rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sv-value-top { display: flex; align-items: baseline; gap: 5px; }
.sv-value-top b { font-size: 0.9rem; color: #0f172a; font-weight: 800; }
.sv-value-top small { font-size: 0.68rem; color: #94a3b8; font-weight: 700; }
.sv-share { margin-left: auto; font-size: 0.68rem; font-weight: 800; color: #2563eb; background: #eff6ff; padding: 1px 7px; border-radius: 9999px; }
.sv-bar { height: 6px; margin-top: 6px; border-radius: 9999px; background: #eaf0fa; overflow: hidden; }
.sv-bar i { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #93c5fd, #2563eb); transition: width 0.6s ease; }
.sv-ring {
  --p: 0;
  display: inline-flex; align-items: center; justify-content: center; width: 44px; height: 44px; border-radius: 50%;
  background: conic-gradient(#10b981 calc(var(--p) * 1%), #e2e8f0 0);
}
.sv-ring span { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 50%; background: #fff; font-size: 0.6rem; font-weight: 800; color: #047857; }
.sv-none { color: #cbd5e1; font-weight: 700; }
.sv-completion { display: flex; justify-content: center; }
.sv-chevron { color: #94a3b8; transition: transform 0.2s ease, color 0.2s ease; display: inline-flex; }
.sv-chevron-open { transform: rotate(180deg); color: #2563eb; }

.sv-detail { border: 1px solid #93c5fd; border-top: 0; border-radius: 0 0 16px 16px; background: #fff; overflow: hidden; }
.sv-detail-scroll { overflow-x: auto; }
.sv-detail-table { width: 100%; min-width: 640px; border-collapse: collapse; font-size: 0.8rem; }
.sv-detail-table th { padding: 10px 14px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: #64748b; background: #f5f9ff; }
.sv-detail-table td { padding: 9px 14px; border-top: 1px solid #eef2f9; color: #334155; }
.sv-detail-table tbody tr:hover td { background: #f8fbff; }
.sv-code { font-weight: 700; color: #1e3a8a; }
.sv-strong { font-weight: 800; color: #0f172a; }
.sv-empty { text-align: center; color: #94a3b8; padding: 22px 14px !important; }
.sv-empty-state { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 36px 14px; color: #94a3b8; font-size: 0.85rem; font-weight: 600; }

@media (max-width: 639.98px) {
  .sv-search { flex: 1 1 100%; }
  .sv-btn { flex: 1 1 0; }
}
@media (max-width: 767.98px) {
  .sv-name-text { white-space: normal; line-height: 1.25; }
  .sv-colhead { display: none; }
  .sv-row {
    grid-template-columns: 34px minmax(0, 1fr) 56px 22px;
    grid-template-areas: 'rank name ring chev' 'value value value value';
    row-gap: 10px;
  }
  .sv-rank { grid-area: rank; }
  .sv-name { grid-area: name; }
  .sv-value { grid-area: value; }
  .sv-completion { grid-area: ring; }
  .sv-chevron { grid-area: chev; }
}

/* ---- Month / Year filter ---- */
.df-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; padding: 12px 14px; }
@media (max-width: 639.98px) { .df-row { grid-template-columns: 1fr; gap: 10px; padding: 10px; } }
.df-field { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 18px; border: 1px solid transparent; }
.df-month { background: linear-gradient(135deg, #eff6ff, #e0ecff); border-color: #bfdbfe; }
.df-year { background: linear-gradient(135deg, #f5f3ff, #ede9fe); border-color: #ddd6fe; }
.df-icon { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 44px; height: 44px; border-radius: 14px; color: #fff; }
.df-month .df-icon { background: linear-gradient(145deg, #60a5fa, #2563eb); box-shadow: 0 10px 18px -10px rgba(37, 99, 235, 0.8); }
.df-year .df-icon { background: linear-gradient(145deg, #a78bfa, #7c3aed); box-shadow: 0 10px 18px -10px rgba(124, 58, 237, 0.8); }
.df-body { flex: 1 1 auto; min-width: 0; }
.df-label { display: block; margin-bottom: 3px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; }
.df-month .df-label { color: #2563eb; }
.df-year .df-label { color: #7c3aed; }
.df-body :deep(input) { background: rgba(255, 255, 255, 0.9); border-radius: 12px; font-weight: 700; color: #1e293b; }

/* ---- Stock summary: Expand / Collapse ---- */
.sv-more { display: flex; justify-content: center; padding-top: 16px; }
.sv-more-btn {
  display: inline-flex; align-items: center; gap: 8px; height: 44px; padding: 0 22px; border-radius: 9999px;
  font-size: 0.84rem; font-weight: 800; color: #fff; transition: transform 0.15s ease, filter 0.15s ease;
}
.sv-more-btn:hover { transform: translateY(-1px); filter: brightness(1.06); }
.sv-more-btn em { font-style: normal; font-weight: 600; font-size: 0.72rem; opacity: 0.92; padding-left: 10px; border-left: 1px solid rgba(255, 255, 255, 0.55); }
.sv-more-expand { background: linear-gradient(145deg, #34d399, #059669); box-shadow: 0 12px 22px -12px rgba(5, 150, 105, 0.85); }
.sv-more-collapse { background: linear-gradient(145deg, #fbbf24, #ea580c); box-shadow: 0 12px 22px -12px rgba(234, 88, 12, 0.85); }
.sv-flip { transform: rotate(180deg); }

/* ---- Hero avatar: animated glow ring + live clock ---- */
.hero-avatar-ring {
  position: relative;
  display: inline-flex;
  width: 56px;
  height: 56px;
  padding: 3px;
  margin-bottom: 10px;
  border-radius: 50%;
  isolation: isolate;
}
.hero-avatar-ring::before,
.hero-avatar-ring::after {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: 50%;
  background: conic-gradient(from 0deg, #38bdf8, #818cf8, #e879f9, #38bdf8, #34d399, #38bdf8);
  animation: hero-ring-spin 3.4s linear infinite;
}
.hero-avatar-ring::before { z-index: -1; }
.hero-avatar-ring::after {
  z-index: -2;
  filter: blur(9px);
  opacity: 0.8;
  animation: hero-ring-spin 3.4s linear infinite, hero-ring-breathe 2.4s ease-in-out infinite;
}
.hero-avatar-ring .hero-avatar {
  width: 100%;
  height: 100%;
  margin-bottom: 0;
  box-shadow: 0 0 0 2.5px #ffffff;
}
@keyframes hero-ring-spin { to { transform: rotate(360deg); } }
@keyframes hero-ring-breathe { 0%, 100% { opacity: 0.55; } 50% { opacity: 0.95; } }
@media (prefers-reduced-motion: reduce) {
  .hero-avatar-ring::before, .hero-avatar-ring::after { animation: none; }
}

.hero-clock {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
  padding: 3px 11px;
  border-radius: 9999px;
  font-size: 0.95rem;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.02em;
  color: #1d4ed8;
  background: rgba(37, 99, 235, 0.1);
}

/* EAT badge on the greeting clock and the next-prayer chip. Light (soft green and orange) by default; dark only in WIMS dark mode. */
.sky-eat { margin-left: 6px; padding: 1px 6px; border-radius: 6px; font-size: 0.55em; font-weight: 800; letter-spacing: 0.12em; vertical-align: middle; color: #fff; background: linear-gradient(135deg, #fb923c, #ea580c); }
.sky-prayer { display: inline-flex; align-items: center; justify-content: center; gap: 7px; min-height: 34px; line-height: 1; margin-top: 8px; padding: 0 14px; border-radius: 99px; font-size: 0.72rem; font-weight: 700; color: #14532d; background: linear-gradient(135deg, rgba(236, 253, 245, 0.95), rgba(255, 247, 237, 0.95)); box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.4), 0 0 12px rgba(251, 146, 60, 0.25); animation: pc-chip 3s ease-in-out infinite; }
.sky-prayer svg { flex: none; width: 14px; height: 14px; color: #f59e0b; } .sky-prayer i { font-style: normal; color: #c2410c; font-weight: 800; }
@keyframes pc-chip { 0%, 100% { box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.35), 0 0 6px rgba(251, 146, 60, 0.15); } 50% { box-shadow: inset 0 0 0 1px rgba(249, 115, 22, 0.7), 0 0 16px rgba(251, 146, 60, 0.45); } }
/* Prayer Times card */
.kpi-card.prayer-card { color: #14532d; border: 0; background: linear-gradient(150deg, #f0fdf4 0%, #fffbeb 55%, #fff1e0 100%) !important; box-shadow: 0 14px 28px -18px rgba(22, 101, 52, 0.55), inset 0 0 0 1px rgba(34, 197, 94, 0.35) !important; display: flex; flex-direction: column; gap: 6px; }
.kpi-card.prayer-card::before { display: none; }
.kpi-card.prayer-card.is-soon { animation: pc-soon 1.6s ease-in-out infinite; }
@keyframes pc-soon { 0%, 100% { box-shadow: 0 14px 28px -18px rgba(194, 65, 12, 0.5), inset 0 0 0 1px rgba(251, 146, 60, 0.5); } 50% { box-shadow: 0 14px 28px -18px rgba(194, 65, 12, 0.5), inset 0 0 0 2px rgba(234, 88, 12, 0.9), 0 0 20px rgba(251, 146, 60, 0.45); } }
.pc-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; }
.pc-title { display: inline-flex; align-items: center; gap: 6px; color: #c2410c; } .pc-title svg { width: 14px; height: 14px; color: #f59e0b; filter: drop-shadow(0 0 4px rgba(245, 158, 11, 0.6)); animation: pc-moon 4s ease-in-out infinite; }
.pc-eat { color: #5b7a66; font-variant-numeric: tabular-nums; letter-spacing: 0.04em; text-transform: none; font-size: 0.72rem; } .pc-eat b { padding: 0 4px; border-radius: 4px; font-size: 0.58rem; color: #fff; background: #ea580c; }
.pc-next { display: flex; align-items: baseline; flex-wrap: wrap; gap: 6px; } .pc-label { width: 100%; font-size: 0.6rem; font-weight: 800; letter-spacing: 0.14em; text-transform: uppercase; color: #5b7a66; }
.pc-next b { font-size: 1.35rem; line-height: 1; color: #14532d; } .pc-next em { font-style: normal; font-size: 0.95rem; font-weight: 800; color: #c2410c; } .pc-next i { margin-left: auto; font-style: normal; font-size: 0.74rem; font-weight: 800; color: #15803d; }
.pc-bar { height: 4px; border-radius: 4px; background: rgba(22, 101, 52, 0.14); overflow: hidden; } .pc-bar span { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #22c55e, #f59e0b, #f97316); transition: width 1s linear; }
.pc-list { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 4px; margin: 0; padding: 0; list-style: none; }
.pc-list li { display: grid; gap: 1px; padding: 5px 2px; border-radius: 9px; text-align: center; font-size: 0.62rem; background: rgba(255, 255, 255, 0.78); box-shadow: inset 0 0 0 1px rgba(22, 101, 52, 0.1), 0 5px 10px -5px rgba(22, 101, 52, 0.4), 0 1px 2px rgba(22, 101, 52, 0.12); } .pc-list b { font-weight: 800; color: #166534; } .pc-list time { font-variant-numeric: tabular-nums; color: #5b7a66; font-weight: 700; font-size: 0.6rem; white-space: nowrap; }
.pc-list li.passed { opacity: 0.55; } .pc-list li.next { background: linear-gradient(180deg, rgba(251, 146, 60, 0.35), rgba(251, 146, 60, 0.12)); box-shadow: inset 0 0 0 1.5px rgba(234, 88, 12, 0.75), 0 0 10px rgba(251, 146, 60, 0.4), 0 6px 12px -5px rgba(234, 88, 12, 0.5); } .pc-list li.next b, .pc-list li.next time { color: #7c2d12; }
@keyframes pc-moon { 0%, 100% { transform: rotate(0); } 50% { transform: rotate(-12deg) translateY(-1px); } }
html.dark .sky-prayer { color: #ecfdf5; background: linear-gradient(135deg, rgba(6, 78, 59, 0.85), rgba(7, 20, 43, 0.85)); box-shadow: inset 0 0 0 1px rgba(250, 204, 21, 0.45), 0 0 14px rgba(250, 204, 21, 0.25); } html.dark .sky-prayer svg { color: #fde68a; } html.dark .sky-prayer i { color: #6ee7b7; }
html.dark .kpi-card.prayer-card { color: #ecfdf5; background: linear-gradient(150deg, #0b3b34 0%, #0a2a40 60%, #07142b 100%) !important; box-shadow: 0 14px 28px -16px rgba(7, 20, 43, 0.9), inset 0 0 0 1px rgba(250, 204, 21, 0.3) !important; }
html.dark .pc-title { color: #fcd34d; } html.dark .pc-title svg { color: #fde68a; } html.dark .pc-eat { color: #cbd5e1; } html.dark .pc-eat b { color: #422006; background: #fcd34d; } html.dark .pc-label { color: #94a3b8; } html.dark .pc-next b { color: #fff; } html.dark .pc-next em { color: #fde68a; } html.dark .pc-next i { color: #6ee7b7; } html.dark .pc-bar { background: rgba(255, 255, 255, 0.12); }
html.dark .pc-list li { background: rgba(255, 255, 255, 0.05); box-shadow: 0 5px 10px -5px rgba(0, 0, 0, 0.6), 0 1px 2px rgba(0, 0, 0, 0.3); } html.dark .pc-list b { color: #cbd5e1; } html.dark .pc-list time { color: #94a3b8; } html.dark .pc-list li.next { background: linear-gradient(180deg, rgba(250, 204, 21, 0.3), rgba(250, 204, 21, 0.08)); box-shadow: inset 0 0 0 1px rgba(250, 204, 21, 0.7), 0 0 10px rgba(250, 204, 21, 0.4); } html.dark .pc-list li.next b, html.dark .pc-list li.next time { color: #fff; }
@media (prefers-reduced-motion: reduce) { .sky-prayer, .kpi-card.prayer-card.is-soon, .pc-title svg { animation: none; } }

/* ===== Shortcut cards: glass tiles on a teal to violet panel ===== */
.dc-panel { position: relative; padding: 16px 16px 18px; border-radius: 28px; color: #fff; overflow: hidden; background: linear-gradient(155deg, #0d9488 0%, #2563eb 52%, #7c3aed 100%); box-shadow: 0 24px 40px -24px rgba(37, 99, 235, 0.65), inset 0 1px 0 rgba(255, 255, 255, 0.28); }
.dc-panel::before { content: ''; position: absolute; inset: 0; pointer-events: none; background: radial-gradient(120% 80% at 0% 0%, rgba(255, 255, 255, 0.22), transparent 55%), radial-gradient(90% 70% at 100% 100%, rgba(167, 139, 250, 0.38), transparent 60%); }
.dc-head { position: relative; display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 2px 4px 14px; }
.dc-title { margin: 0; font-size: 1.05rem; font-weight: 700; letter-spacing: 0.01em; color: #fff; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.25); }
.dc-edit { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 999px; font-size: 0.8rem; font-weight: 700; color: #fff; background: rgba(255, 255, 255, 0.18); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.38); transition: background 0.2s ease; }
.dc-edit:hover { background: rgba(255, 255, 255, 0.3); }
.dc-grid { position: relative; display: grid; grid-template-columns: 1fr; gap: 12px; }
@media (min-width: 520px) { .dc-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (min-width: 768px) { .dc-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (min-width: 1100px) { .dc-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); } }
.dc-card { position: relative; display: flex; align-items: center; gap: 12px; min-height: 72px; padding: 12px 12px 12px 14px; border-radius: 20px; color: #fff; cursor: grab; background: rgba(255, 255, 255, 0.15); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.3), 0 10px 18px -14px rgba(15, 23, 42, 0.7); backdrop-filter: blur(6px); transition: transform 0.18s ease, background 0.2s ease, box-shadow 0.2s ease; }
.dc-card:hover { transform: translateY(-2px); background: rgba(255, 255, 255, 0.25); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.55), 0 16px 24px -14px rgba(15, 23, 42, 0.8); }
.dc-card:active { cursor: grabbing; }
.dc-card:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
.dc-ico { flex: none; display: grid; place-items: center; width: 44px; height: 44px; border-radius: 50%; color: #fff; background: rgba(255, 255, 255, 0.24); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.42); }
.dc-text { flex: 1; min-width: 0; display: grid; gap: 2px; line-height: 1.2; text-align: left; }
.dc-text b { font-size: 0.92rem; font-weight: 700; color: #fff; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3); overflow-wrap: anywhere; }
.dc-text small { font-size: 0.76rem; font-weight: 600; color: rgba(255, 255, 255, 0.94); text-shadow: 0 1px 2px rgba(0, 0, 0, 0.28); overflow-wrap: anywhere; }
.dc-go { flex: none; display: grid; place-items: center; width: 30px; height: 30px; border-radius: 50%; color: #fff; background: rgba(255, 255, 255, 0.14); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.55); transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease; }
.dc-card:hover .dc-go { background: #fff; color: #2563eb; transform: translateX(2px); }
.dc-over { box-shadow: 0 0 0 3px #fff !important; transform: scale(1.02); }
@media (max-width: 520px) { .dc-panel { border-radius: 24px; padding: 14px 12px 14px; } }
@media (prefers-reduced-motion: reduce) { .dc-card, .dc-go { transition: none; } .dc-card:hover { transform: none; } }

.sv-kpi-count { display: inline-block; margin-left: 4px; padding: 0 7px; border-radius: 999px; background: rgba(15, 37, 64, 0.08); color: #334155; font-size: 0.66rem; }
.sv-list { display: grid; gap: 16px; }
.sv-group { border-radius: 20px; padding: 14px 12px 12px; background: var(--lg-card, #fff); border: 1px solid transparent; box-shadow: 0 22px 36px -22px rgba(15, 37, 64, 0.5), 0 4px 10px rgba(15, 37, 64, 0.08); }
html.dark .sv-group { box-shadow: 0 22px 36px -22px rgba(0, 0, 0, 0.8), 0 4px 10px rgba(0, 0, 0, 0.35); }
.sv-group-blue { background: linear-gradient(180deg, #eff6ff 0, var(--lg-card, #fff) 120px); border-color: #dbeafe; }
.sv-group-emerald { background: linear-gradient(180deg, #ecfdf5 0, var(--lg-card, #fff) 120px); border-color: #d1fae5; }
.sv-group-violet { background: linear-gradient(180deg, #f5f3ff 0, var(--lg-card, #fff) 120px); border-color: #ede9fe; }
html.dark .sv-group-blue, html.dark .sv-group-emerald, html.dark .sv-group-violet { background: var(--lg-card, #0f172a); border-color: rgba(148, 163, 184, 0.2); }
.sv-group-head { display: flex; align-items: center; gap: 12px; padding: 4px 6px 12px; flex-wrap: wrap; }
.sv-group-icon { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 40px; height: 40px; border-radius: 13px; box-shadow: 0 10px 18px -10px rgba(15, 37, 64, 0.5); }
.sv-group-blue .sv-group-icon { background: #dbeafe; color: #2563eb; } .sv-group-emerald .sv-group-icon { background: #d1fae5; color: #059669; } .sv-group-violet .sv-group-icon { background: #ede9fe; color: #7c3aed; }
.sv-group-title { font-weight: 800; font-size: 1rem; color: #1e293b; } html.dark .sv-group-title { color: #e2e8f0; }
.sv-group-sub { font-size: 0.76rem; color: #64748b; }
.sv-group-total { text-align: right; white-space: nowrap; } .sv-group-total small { display: block; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #94a3b8; }
.sv-group-total b { font-size: 1.1rem; color: #0f172a; font-variant-numeric: tabular-nums; } html.dark .sv-group-total b { color: #f1f5f9; } .sv-group-total em { font-style: normal; font-size: 0.72rem; color: #64748b; font-weight: 700; }
.sv-name-tag { margin-left: 8px; padding: 1px 8px; border-radius: 999px; font-size: 0.66rem; font-weight: 700; background: rgba(100, 116, 139, 0.14); color: #475569; white-space: nowrap; }

.sv-maroon, .sv-maroon small { color: #800000 !important; font-weight: 800; }
html.dark .sv-maroon, html.dark .sv-maroon small { color: #fb7185 !important; }
.sv-group-total b, .sv-group-total em { color: #800000 !important; font-weight: 800; }
html.dark .sv-group-total b, html.dark .sv-group-total em { color: #fb7185 !important; }
.sv-grand { display: flex; align-items: center; gap: 14px; margin: 0 22px 14px; padding: 14px 18px; border-radius: 18px; background: linear-gradient(135deg, #fff1f2 0, #fff 70%); border: 1px solid #fecdd3; box-shadow: 0 18px 30px -18px rgba(128, 0, 0, 0.45), 0 3px 8px rgba(15, 37, 64, 0.08); flex-wrap: wrap; }
html.dark .sv-grand { background: linear-gradient(135deg, rgba(190, 18, 60, 0.18) 0, var(--lg-card, #0f172a) 70%); border-color: rgba(251, 113, 133, 0.3); }
.sv-grand-icon { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 42px; height: 42px; border-radius: 14px; background: #ffe4e6; color: #800000; box-shadow: 0 10px 18px -10px rgba(128, 0, 0, 0.5); }
.sv-grand-label { font-size: 0.7rem; font-weight: 800; letter-spacing: 0.07em; text-transform: uppercase; color: #64748b; }
.sv-grand-sub { font-size: 0.74rem; color: #94a3b8; }
.sv-grand-total { margin-left: auto; white-space: nowrap; font-variant-numeric: tabular-nums; }
.sv-grand-total b { font-size: 1.6rem; font-weight: 900; color: #800000; } .sv-grand-total em { font-style: normal; font-weight: 800; font-size: 0.9rem; color: #800000; }
html.dark .sv-grand-total b, html.dark .sv-grand-total em { color: #fb7185; }
@media (max-width: 767.98px) { .sv-grand { margin: 0 14px 12px; } .sv-grand-total { margin-left: 0; } .sv-grand-total b { font-size: 1.3rem; } }
</style>

<template>
  <admin-layout :title="allocation.reference">
    <div class="lg-page px-4 md:px-0 fx-show">
      <page-header
        icon="cash"
        :title="allocation.reference"
        :subtitle="[$date(allocation.allocation_date), allocation.title].filter(Boolean).join(' - ')"
      >
        <span class="fx-status" :class="statusTone(allocation.status)">
          <span class="fx-status-dot" :class="{ 'is-live': isLive(allocation.status) }"></span>
          <icons :name="statusIcon(allocation.status)" class="w-4 h-4" />
          {{ $t(allocation.status) }}
        </span>
        <Link :href="route('fund-allocations.index')" class="fx-hbtn is-slate"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('All sheets') }}</Link>
        <a v-if="can.print" :href="route('fund-allocations.print', allocation.id)" target="_blank" class="fx-hbtn is-violet"><icons name="printer" class="w-4 h-4" />{{ $t('Print') }}</a>
        <a v-if="can.print" :href="route('fund-allocations.pdf', allocation.id)" class="fx-hbtn is-rose"><icons name="download" class="w-4 h-4" />{{ $t('PDF') }}</a>
        <Link v-if="can.update" :href="route('fund-allocations.edit', allocation.id)" class="fx-hbtn is-green"><icons name="edit" class="w-4 h-4" />{{ $t('Edit') }}</Link>
      </page-header>

      <!-- Alerts -->
      <div v-if="allocation.status === 'Rejected' && allocation.rejection_reason" class="lg-note lg-note-red mb-4">
        <icons name="exclamation" class="w-4 h-4" /><span><b>{{ $t('Rejected') }}:</b> {{ allocation.rejection_reason }}</span>
      </div>
      <div v-if="allocation.status === 'Cancelled' && allocation.cancel_reason" class="lg-note lg-note-amber mb-4">
        <icons name="exclamation" class="w-4 h-4" /><span><b>{{ $t('Cancelled') }}:</b> {{ allocation.cancel_reason }}</span>
      </div>
      <div v-if="pageError" class="lg-note lg-note-red mb-4 fx-prewrap"><icons name="exclamation" class="w-4 h-4" /><span>{{ pageError }}</span></div>

      <!-- Paid in full: ask the payer to close the allocation -->
      <section v-if="allocation.status === 'Completed'" class="fx-done">
        <span class="fx-done-ico"><icons name="tick" class="w-7 h-7" /></span>
        <div class="min-w-0 flex-1">
          <div class="fx-done-title">{{ $t('Payment progress is 100% - every payment is done') }}</div>
          <div class="fx-done-sub">
            {{ totals.paid_item_count }}/{{ totals.item_count }} {{ $t('items paid') }} &middot; {{ fmt(totals.paid) }}<i class="fx-cur">TZS</i>
            <template v-if="allocation.completed_at"> &middot; {{ $datetime(allocation.completed_at) }}</template>
            <template v-if="allocation.paid_by"> &middot; {{ $t('Paid by') }} {{ allocation.paid_by.name }}</template>
          </div>
          <div v-if="can.clear" class="fx-done-ask">{{ $t('Please close this allocation to clear it. Once closed it is locked and only a Super Admin can change it or assign it again.') }}</div>
        </div>
        <button v-if="can.clear" type="button" class="fx-close-btn" :disabled="busyAction" @click="clearSheet"><icons name="lock-closed" class="w-4 h-4" />{{ $t('Close allocation') }}</button>
        <span class="fx-spark s1"></span><span class="fx-spark s2"></span><span class="fx-spark s3"></span><span class="fx-spark s4"></span>
      </section>

      <!-- Cleared indicator -->
      <section v-if="allocation.status === 'Cleared'" class="fx-cleared">
        <span class="fx-cleared-ico"><icons name="lock-closed" class="w-6 h-6" /></span>
        <div class="min-w-0 flex-1">
          <div class="fx-cleared-title">{{ $t('CLEARED') }} <span class="fx-cleared-pill"><icons name="tick" class="w-3 h-3" />{{ $t('Closed and reconciled') }}</span></div>
          <div class="fx-cleared-sub">
            {{ $t('Paid by') }} <b>{{ personName(allocation.paid_by) }}</b>
            &middot; {{ $t('Cleared by') }} <b>{{ personName(allocation.cleared_by) }}</b>
            <template v-if="allocation.cleared_at"> &middot; {{ $datetime(allocation.cleared_at) }}</template>
          </div>
        </div>
        <span class="fx-cleared-amt">{{ fmt(totals.paid) }}<i class="fx-cur">TZS</i></span>
      </section>

      <stat-tiles :items="tiles" />

      <!-- Progress + workflow -->
      <div class="fx-top">
        <section class="lg-card fx-progress-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-teal"><icons name="tick" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Payment progress') }}</h2>
              <p class="lg-card-sub">{{ paidLines }}/{{ totalLines }} {{ $t('payments ticked') }}</p>
            </div>
            <span class="fx-bigpct">{{ Math.round(totals.progress) }}%</span>
          </header>
          <div class="fx-bar fx-bar-lg"><span :style="{ width: Math.min(100, totals.progress) + '%' }" :class="{ done: totals.progress >= 100 }"></span></div>
          <div class="fx-prog-meta">
            <span><small>{{ $t('Planned') }}</small>{{ fmt(totals.planned) }}<i class="fx-cur">TZS</i></span>
            <span><small>{{ $t('Paid') }}</small><b class="fx-pos">{{ fmt(totals.paid) }}<i class="fx-cur">TZS</i></b></span>
            <span><small>{{ $t('Still to pay') }}</small><b :class="totals.unpaid > 0 ? 'fx-warn' : ''">{{ fmt(totals.unpaid) }}<i class="fx-cur">TZS</i></b></span>
          </div>
          <div v-if="can.pay && allocation.status !== 'Completed'" class="lg-note lg-note-blue mt-3">
            <icons name="info" class="w-4 h-4" />
            <span>{{ $t('You are the payer. Tick each payment after you have made it, and link or create a payment voucher for every item.') }}</span>
          </div>
        </section>

        <section class="lg-card fx-flow-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="users" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Workflow') }}</h2>
              <p class="lg-card-sub">{{ $t('Who did what') }}</p>
            </div>
          </header>
          <ul class="fx-people">
            <li><span>{{ $t('Prepared by') }}</span><b>{{ personName(allocation.prepared_by) }}</b><small v-if="allocation.submitted_at">{{ $datetime(allocation.submitted_at) }}</small></li>
            <li><span>{{ $t('Approved by') }}</span><b>{{ personName(allocation.approved_by) }}</b><small v-if="allocation.approved_at">{{ $datetime(allocation.approved_at) }}</small></li>
            <li><span>{{ allocation.payers && allocation.payers.length > 1 ? $t('Payers') : $t('Payer') }}</span><b>{{ payerNames }}</b></li>
            <li v-if="allocation.paid_by"><span>{{ $t('Paid by') }}</span><b>{{ personName(allocation.paid_by) }}</b><small v-if="allocation.completed_at">{{ $datetime(allocation.completed_at) }}</small></li>
            <li v-if="allocation.cleared_by"><span>{{ $t('Cleared by') }}</span><b>{{ personName(allocation.cleared_by) }}</b><small v-if="allocation.cleared_at">{{ $datetime(allocation.cleared_at) }}</small></li>
          </ul>
          <div class="fx-actions">
            <button v-if="can.submit" type="button" class="lg-btn lg-btn-primary" :disabled="busyAction" @click="submitSheet"><icons name="send" class="w-4 h-4" />{{ $t('Submit for approval') }}</button>
            <button v-if="can.clear" type="button" class="lg-btn lg-btn-green" :disabled="busyAction" @click="clearSheet"><icons name="lock-closed" class="w-4 h-4" />{{ $t('Close allocation') }}</button>
            <button v-if="can.approve" type="button" class="lg-btn lg-btn-green" @click="openModal('approve')"><icons name="tick" class="w-4 h-4" />{{ $t('Approve') }}</button>
            <button v-if="can.reject" type="button" class="lg-btn lg-btn-red" @click="openModal('reject')"><icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}</button>
            <button v-if="can.reassign" type="button" class="lg-btn lg-btn-soft fx-btn-shadow fx-btn-blue" @click="openModal('reassign')"><icons name="refresh" class="w-4 h-4" />{{ ['Completed', 'Cleared'].includes(allocation.status) ? $t('Assign payer again') : $t('Change payer') }}</button>
            <button v-if="can.cancel" type="button" class="lg-btn lg-btn-soft fx-btn-shadow fx-btn-rose" @click="openModal('cancel')"><icons name="x-circle" class="w-4 h-4" />{{ $t('Cancel sheet') }}</button>
            <button v-if="can.reopen" type="button" class="lg-btn lg-btn-soft" :disabled="busyAction" @click="reopenSheet"><icons name="lock-open" class="w-4 h-4" />{{ $t('Reopen as draft') }}</button>
            <button v-if="can.delete" type="button" class="lg-btn lg-btn-red" :disabled="busyAction" @click="deleteSheet"><icons name="trash" class="w-4 h-4" />{{ $t('Delete') }}</button>
            <span v-if="!hasActions" class="fx-hint">{{ $t('No actions are available for you on this sheet right now.') }}</span>
          </div>
        </section>
      </div>

      <!-- Available balances (hidden from the assigned payer once approved) -->
      <section v-if="can.see_balances !== false" class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-emerald"><icons name="cash" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Available balances') }}</h2>
            <p class="lg-card-sub">{{ $t('Money on hand for this date') }}</p>
          </div>
        </header>
        <div class="lg-table-wrap">
          <table v-labelize class="lg-table lg-responsive">
            <thead>
              <tr><th>{{ $t('Account') }}</th><th>{{ $t('Currency') }}</th><th class="num">{{ $t('Amount') }}</th><th class="num">{{ $t('Rate') }}</th><th class="num">{{ $t('Value in TZS') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="b in allocation.balances" :key="b.id">
                <td class="lg-main">{{ b.label }}</td>
                <td><span class="lg-chip lg-chip-slate" style="text-transform: none">{{ b.currency }}</span></td>
                <td class="num">{{ fmt(b.amount) }}<i class="fx-cur">{{ b.currency }}</i></td>
                <td class="num">{{ b.currency === 'TZS' ? '-' : fmt(b.exchange_rate) }}</td>
                <td class="num fx-strong">{{ fmt(b.amount_tzs) }}<i class="fx-cur">TZS</i></td>
              </tr>
              <tr v-if="!allocation.balances.length"><td colspan="5" class="lg-empty">{{ $t('No balances were recorded.') }}</td></tr>
              <tr class="fx-tot-row"><td colspan="4">{{ $t('Total available') }}</td><td class="num fx-tot-val">{{ fmt(totals.available) }}<i class="fx-cur">TZS</i></td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- The sheet -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-navy"><icons name="table" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Allocation sheet') }}</h2>
            <p class="lg-card-sub">{{ allocation.items.length }} {{ $t('items') }} &middot; {{ cats.length }} {{ $t('categories') }}</p>
          </div>
        </header>

        <div v-if="allocation.items.length && (can.pay_bulk || can.generate_vouchers)" class="fx-bulkbar">
          <label v-if="can.pay_bulk" class="fx-check">
            <input type="checkbox" :checked="allUnpaidSelected" :disabled="!unpaidItems.length" @change="toggleAll($event.target.checked)" />
            <span>{{ $t('Select all unpaid') }} ({{ unpaidItems.length }})</span>
          </label>
          <span v-if="can.pay_bulk" class="fx-bulk-count">{{ selectedItems.length }} {{ $t('selected') }}<template v-if="selectedItems.length"> &middot; {{ fmt(selectedAmount) }}<i class="fx-cur">TZS</i></template></span>
          <span class="fx-grow"></span>
          <button v-if="can.pay_bulk" type="button" class="fx-bulk-btn is-green" :disabled="!selectedItems.length || busyAction" @click="openBulk(false)"><icons name="tick" class="w-4 h-4" />{{ $t('Mark selected as paid') }}</button>
          <button v-if="can.pay_bulk" type="button" class="fx-bulk-btn is-green-soft" :disabled="!unpaidItems.length || busyAction" @click="openBulk(true)"><icons name="tick" class="w-4 h-4" />{{ $t('Mark all as paid') }}</button>
          <button v-if="can.generate_vouchers" type="button" class="fx-bulk-btn is-blue" :disabled="!voucherReady.length || busyAction" @click="openGen"><icons name="doc-text" class="w-4 h-4" />{{ $t('Generate payment vouchers') }}<span class="fx-bulk-n">{{ voucherReady.length }}</span></button>
        </div>

        <div v-if="!allocation.items.length" class="fx-emptybox">{{ $t('This sheet has no items.') }}</div>

        <!-- Desktop / tablet table -->
        <div v-else class="lg-table-wrap fx-desktop">
          <table class="lg-table fx-sheet">
            <thead>
              <tr>
                <th class="w-10" :aria-sort="ariaSort('n')"><button type="button" class="fx-th-btn" @click="sortBy('n')">{{ $t('SN') }}<span class="fx-sort" :class="sortCls('n')"></span></button></th>
                <th :aria-sort="ariaSort('description')"><button type="button" class="fx-th-btn" @click="sortBy('description')">{{ $t('Description') }}<span class="fx-sort" :class="sortCls('description')"></span></button></th>
                <th :aria-sort="ariaSort('beneficiary')"><button type="button" class="fx-th-btn" @click="sortBy('beneficiary')">{{ $t('Beneficiary') }}<span class="fx-sort" :class="sortCls('beneficiary')"></span></button></th>
                <th class="num" :aria-sort="ariaSort('request')"><button type="button" class="fx-th-btn is-num" @click="sortBy('request')">{{ $t('Request') }}<span class="fx-sort" :class="sortCls('request')"></span></button></th>
                <th class="num fx-alloc-col" :aria-sort="ariaSort('allocation')"><button type="button" class="fx-th-btn is-num" @click="sortBy('allocation')">{{ $t('Allocation') }}<span class="fx-sort" :class="sortCls('allocation')"></span></button></th>
                <th v-for="m in methodsUsed" :key="m.id" class="num fx-mctr-h" :class="{ 'fx-usd-col': m.currency === 'USD' }" :aria-sort="ariaSort('m:' + m.id)">
                  <button type="button" class="fx-th-btn fx-th-method" :class="'fx-m-' + tone(m)" @click="sortBy('m:' + m.id)">
                    <i v-if="m.currency === 'USD'" class="fx-usd-sign fx-usd-head">$</i><icons v-else :name="mIcon(m)" class="fx-hico w-4 h-4" :class="'fx-hi-' + tone(m)" />{{ m.short || m.name }}<span class="fx-sort" :class="sortCls('m:' + m.id)"></span>
                  </button>
                </th>
                <th :aria-sort="ariaSort('voucher')"><button type="button" class="fx-th-btn fx-th-voucher" @click="sortBy('voucher')"><icons name="doc-text" class="fx-hico w-4 h-4 fx-hi-voucher" />{{ $t('Voucher') }}<span class="fx-sort" :class="sortCls('voucher')"></span></button></th>
              </tr>
            </thead>
            <tbody v-for="(cat, ci) in cats" :key="cat.id" :class="ci % 2 ? 'fx-z1' : 'fx-z0'">
              <tr class="fx-cat-row">
                <td colspan="99">
                  <span class="fx-letter">{{ cat.letter }}</span> {{ cat.name }}
                  <span class="fx-cat-prog">{{ cat.paidItems }}/{{ cat.rows.length }} {{ $t('paid') }}</span>
                </td>
              </tr>
              <tr v-for="r in cat.rows" :key="r.item.id" :class="{ 'fx-paid-row': r.item.paid }">
                <td class="fx-sn-cell">
                  <label v-if="can.pay_bulk && !r.item.paid" class="fx-rowcheck lg-tip" :class="{ 'is-disabled': !isPayable(r.item) }" :data-tip="isPayable(r.item) ? '' : $t('No payment method or allocated value on this item')" :aria-label="isPayable(r.item) ? '' : $t('No payment method or allocated value on this item')"><input type="checkbox" :checked="!!selected[r.item.id]" :disabled="!isPayable(r.item)" :aria-label="$t('Select')" @change="toggleItem(r.item.id, $event.target.checked)" /><span>{{ r.n }}</span></label>
                  <template v-else>{{ r.n }}</template>
                </td>
                <td class="fx-wrap">
                  <div class="lg-main">{{ r.item.description }}</div>
                  <div v-if="r.item.paid" class="fx-paid-line"><span class="lg-chip lg-chip-green"><icons name="tick" class="w-3 h-3" />{{ $t('Paid') }}</span></div>
                  <div v-if="r.item.notes" class="lg-subtext">{{ r.item.notes }}</div>
                  <div v-if="r.item.warehouse" class="lg-subtext">{{ $t('Project') }}: {{ r.item.warehouse.name }}</div>
                </td>
                <td class="fx-wrap">
                  <div>{{ r.item.beneficiary || (r.item.contact && r.item.contact.name) || (r.item.staff && r.item.staff.name) || '-' }}</div>
                  <div v-if="r.item.contact && r.item.beneficiary !== r.item.contact.name" class="lg-subtext">{{ $t('Contact') }}: {{ r.item.contact.name }}</div>
                  <div v-if="r.item.staff && r.item.beneficiary !== r.item.staff.name" class="lg-subtext">{{ $t('Staff') }}: {{ r.item.staff.name }}</div>
                </td>
                <td class="num">{{ fmt(r.item.request_amount) }}<i class="fx-cur">TZS</i></td>
                <td class="num fx-strong fx-alloc-col">{{ fmt(r.item.allocated_amount) }}<i class="fx-cur">TZS</i></td>
                <td v-for="m in methodsUsed" :key="m.id" class="num fx-mcell fx-mctr" :class="{ 'fx-usd-col': m.currency === 'USD' }">
                  <div v-for="l in linesFor(r.item, m.id)" :key="l.id" class="fx-pline" :class="{ paid: l.is_paid }">
                    <div class="fx-line-amt">
                      <span class="fx-line-n"><template v-if="m.currency === 'USD' && l.usd_amount !== null">{{ fmt(l.usd_amount) }}<i class="fx-cur">USD</i></template><template v-else>{{ fmt(l.amount) }}<i class="fx-cur">TZS</i></template></span>
                      <button v-if="can.pay && !l.is_paid && canTick" type="button" class="fx-tick" :disabled="busy[l.id]" :aria-label="$t('Mark as paid')" @click="tick(l, r.item)"><icons name="tick" class="w-3.5 h-3.5" /></button>
                      <button v-else-if="can.pay && l.is_paid" type="button" class="fx-tick is-on" :disabled="busy[l.id]" :aria-label="$t('Remove tick')" @click="untick(l, r.item)"><icons name="tick" class="w-3.5 h-3.5" /></button>
                      <span v-else class="fx-dot" :class="{ on: l.is_paid }"><icons v-if="l.is_paid" name="tick" class="w-3 h-3" /></span>
                    </div>
                    <div v-if="m.currency === 'USD'" class="fx-line-conv" :class="{ 'is-warn': l.usd_amount === null }">
                      <template v-if="l.usd_amount !== null">{{ fmt(l.amount) }} TZS &middot; @ {{ rate(l.exchange_rate) }}</template>
                      <template v-else>{{ $t('No exchange rate yet - edit the sheet to add it') }}</template>
                    </div>
                    <div v-if="l.is_paid" class="fx-line-meta">
                      {{ l.paid_by ? l.paid_by.name : '' }}<template v-if="l.paid_at"> &middot; {{ $datetime(l.paid_at) }}</template><template v-if="l.reference"> &middot; {{ l.reference }}</template>
                    </div>
                  </div>
                </td>
                <td class="fx-wrap fx-voucher-cell">
                  <div class="fx-vbox">
                  <template v-if="r.item.payment_voucher">
                    <a :href="r.item.payment_voucher.url" target="_blank" rel="noopener" class="lg-chip lg-chip-cyan"><icons name="link" class="w-3 h-3" />{{ r.item.payment_voucher.pv_number }}</a>
                    <div class="lg-subtext">{{ r.item.payment_voucher.status }}</div>
                    <button v-if="can.link_voucher" type="button" class="fx-mini" @click="unlinkVoucher(r.item)">{{ $t('Unlink') }}</button>
                  </template>
                  <template v-else>
                    <span v-if="!can.link_voucher" class="lg-subtext">{{ $t('Not linked') }}</span>
                  </template>
                  <div v-if="can.link_voucher && !r.item.payment_voucher" class="fx-voucher-actions">
                    <button type="button" class="fx-mini fx-mini-link" @click="openVoucher(r.item)"><icons name="link" class="w-3 h-3" />{{ $t('Link voucher') }}</button>
                    <a :href="r.item.voucher_create_url" target="_blank" rel="noopener" class="fx-mini fx-mini-create"><icons name="plus" class="w-3 h-3" />{{ $t('Create voucher') }}</a>
                  </div>
                  </div>
                </td>
              </tr>
              <tr class="fx-sub-row">
                <td></td>
                <td colspan="2">{{ $t('Subtotal') }} {{ cat.name }}</td>
                <td class="num">{{ fmt(cat.request) }}<i class="fx-cur">TZS</i></td>
                <td class="num fx-alloc-col">{{ fmt(cat.allocation) }}<i class="fx-cur">TZS</i></td>
                <td v-for="m in methodsUsed" :key="m.id" class="num fx-mctr" :class="{ 'fx-usd-col': m.currency === 'USD' }"><template v-if="m.currency === 'USD'"><template v-if="cat.byMethodUsd[m.id]">{{ fmt(cat.byMethodUsd[m.id]) }}<i class="fx-cur">USD</i></template></template><template v-else-if="cat.byMethod[m.id]">{{ fmt(cat.byMethod[m.id]) }}<i class="fx-cur">TZS</i></template></td>
                <td></td>
              </tr>
            </tbody>
            <tbody>
              <tr class="fx-grand-row">
                <td></td>
                <td colspan="2">{{ $t('TOTAL') }}</td>
                <td class="num">{{ fmt(totals.requested) }}<i class="fx-cur">TZS</i></td>
                <td class="num fx-alloc-col">{{ fmt(totals.allocated) }}<i class="fx-cur">TZS</i></td>
                <td v-for="m in methodsUsed" :key="m.id" class="num fx-mctr" :class="{ 'fx-usd-col': m.currency === 'USD' }"><template v-if="m.currency === 'USD'">{{ fmt(m.usd_planned) }}<i class="fx-cur">USD</i></template><template v-else>{{ fmt(m.planned) }}<i class="fx-cur">TZS</i></template></td>
                <td></td>
              </tr>
              <tr class="fx-paidtot-row">
                <td></td>
                <td colspan="4" class="fx-alloc-col">{{ $t('Paid so far') }}</td>
                <td v-for="m in methodsUsed" :key="m.id" class="num fx-mctr" :class="{ 'fx-usd-col': m.currency === 'USD' }"><span class="fx-pos"><template v-if="m.currency === 'USD'">{{ fmt(m.usd_paid) }}<i class="fx-cur">USD</i></template><template v-else>{{ fmt(m.paid) }}<i class="fx-cur">TZS</i></template></span></td>
                <td></td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Phone cards -->
        <div v-if="allocation.items.length" class="fx-mobile">
          <div v-for="cat in cats" :key="cat.id" class="fx-mcat">
            <div class="fx-mcat-head"><span class="fx-letter">{{ cat.letter }}</span><b>{{ cat.name }}</b><span class="fx-mcat-tot">{{ fmt(cat.allocation) }}<i class="fx-cur">TZS</i></span></div>
            <div v-for="r in cat.rows" :key="r.item.id" class="fx-mitem" :class="{ 'fx-paid-row': r.item.paid }">
              <div class="fx-mitem-top">
                <div class="min-w-0">
                  <div class="lg-main">{{ r.n }}. {{ r.item.description }}</div>
                  <div class="lg-subtext">{{ r.item.beneficiary || (r.item.contact && r.item.contact.name) || (r.item.staff && r.item.staff.name) || '-' }}</div>
                  <div v-if="r.item.paid" class="fx-paid-line"><span class="lg-chip lg-chip-green"><icons name="tick" class="w-3 h-3" />{{ $t('Paid') }}</span></div>
                </div>
              </div>
              <div class="fx-mamts"><span>{{ $t('Request') }} <b>{{ fmt(r.item.request_amount) }}<i class="fx-cur">TZS</i></b></span><span>{{ $t('Allocation') }} <b>{{ fmt(r.item.allocated_amount) }}<i class="fx-cur">TZS</i></b></span></div>
              <div v-for="l in r.item.lines" :key="l.id" class="fx-mline" :class="{ paid: l.is_paid }">
                <span class="lg-chip lg-chip-blue">{{ l.short_label || l.method_name }}</span>
                <b class="fx-mline-amt"><template v-if="l.currency === 'USD' && l.usd_amount !== null">{{ fmt(l.usd_amount) }}<i class="fx-cur">USD</i></template><template v-else>{{ fmt(l.amount) }}<i class="fx-cur">TZS</i></template></b>
                <button v-if="can.pay && !l.is_paid && canTick" type="button" class="fx-tick" :disabled="busy[l.id]" :aria-label="$t('Mark as paid')" @click="tick(l, r.item)"><icons name="tick" class="w-3.5 h-3.5" /></button>
                <button v-else-if="can.pay && l.is_paid" type="button" class="fx-tick is-on" :disabled="busy[l.id]" :aria-label="$t('Remove tick')" @click="untick(l, r.item)"><icons name="tick" class="w-3.5 h-3.5" /></button>
                <span v-else class="fx-dot" :class="{ on: l.is_paid }"><icons v-if="l.is_paid" name="tick" class="w-3 h-3" /></span>
                <div v-if="l.currency === 'USD' && l.usd_amount !== null" class="fx-line-conv fx-mline-meta">{{ fmt(l.amount) }} TZS &middot; @ {{ rate(l.exchange_rate) }}</div>
                <div v-if="l.is_paid" class="fx-line-meta fx-mline-meta">{{ l.paid_by ? l.paid_by.name : '' }}<template v-if="l.reference"> &middot; {{ l.reference }}</template></div>
              </div>
              <div class="fx-mvoucher">
                <a v-if="r.item.payment_voucher" :href="r.item.payment_voucher.url" target="_blank" rel="noopener" class="lg-chip lg-chip-cyan"><icons name="link" class="w-3 h-3" />{{ r.item.payment_voucher.pv_number }}</a>
                <button v-if="can.link_voucher && r.item.payment_voucher" type="button" class="fx-mini" @click="unlinkVoucher(r.item)">{{ $t('Unlink') }}</button>
                <template v-if="can.link_voucher && !r.item.payment_voucher">
                  <button type="button" class="fx-mini" @click="openVoucher(r.item)"><icons name="link" class="w-3 h-3" />{{ $t('Link voucher') }}</button>
                  <a :href="r.item.voucher_create_url" target="_blank" rel="noopener" class="fx-mini"><icons name="plus" class="w-3 h-3" />{{ $t('Create voucher') }}</a>
                </template>
              </div>
            </div>
          </div>
          <div class="fx-mgrand"><span>{{ $t('TOTAL') }}</span><span>{{ fmt(totals.requested) }}<i class="fx-cur">TZS</i></span><b>{{ fmt(totals.allocated) }}<i class="fx-cur">TZS</i></b></div>
        </div>
      </section>

      <!-- Reconciliation -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="calculator" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Reconciliation') }}</h2>
            <p class="lg-card-sub">{{ $t('Planned against paid, by category and by payment method') }}</p>
          </div>
          <span v-if="!totals.unreconciled_items.length && totals.unpaid <= 0.009 && totals.planned > 0" class="lg-chip lg-chip-green"><icons name="tick" class="w-3 h-3" />{{ $t('Reconciled') }}</span>
        </header>
        <div class="fx-recon">
          <div>
            <h3 class="fx-h3">{{ $t('By category') }}</h3>
            <div v-for="(c, i) in totals.by_category" :key="c.id" class="fx-rrow">
              <div class="fx-rrow-top"><span><span class="fx-letter fx-letter-sm">{{ letterFor(i) }}</span> {{ c.name }}</span><span class="fx-nums">{{ fmt(c.paid) }}<i class="fx-cur">TZS</i> / {{ fmt(c.allocated) }}<i class="fx-cur">TZS</i></span></div>
              <div class="fx-bar"><span :style="{ width: pct(c.paid, c.allocated) + '%' }" :class="{ done: pct(c.paid, c.allocated) >= 100 }"></span></div>
            </div>
          </div>
          <div>
            <h3 class="fx-h3">{{ $t('By payment method') }}</h3>
            <div v-for="m in totals.by_method" :key="m.id" class="fx-rrow">
              <div class="fx-rrow-top"><span>{{ m.name }}</span><span class="fx-nums"><template v-if="m.currency === 'USD'">{{ fmt(m.usd_paid) }}<i class="fx-cur">USD</i> / {{ fmt(m.usd_planned) }}<i class="fx-cur">USD</i></template><template v-else>{{ fmt(m.paid) }}<i class="fx-cur">TZS</i> / {{ fmt(m.planned) }}<i class="fx-cur">TZS</i></template> <small>({{ m.paid_lines }}/{{ m.lines }})</small></span></div>
              <div class="fx-bar"><span :style="{ width: pct(m.paid, m.planned) + '%' }" :class="{ done: pct(m.paid, m.planned) >= 100 }"></span></div>
            </div>
            <div v-if="!totals.by_method.length" class="fx-hint">{{ $t('No payment lines yet.') }}</div>
          </div>
        </div>
        <div v-if="totals.unreconciled_items.length" class="lg-note lg-note-amber mt-4">
          <icons name="exclamation" class="w-4 h-4" />
          <div>
            <b>{{ $t('Items whose payment split does not equal the allocation') }}:</b>
            <ul class="fx-unrec">
              <li v-for="u in totals.unreconciled_items" :key="u.id">{{ u.description }} - {{ $t('allocation') }} {{ fmt(u.allocated) }}<i class="fx-cur">TZS</i>, {{ $t('split') }} {{ fmt(u.lines) }}<i class="fx-cur">TZS</i></li>
            </ul>
          </div>
        </div>
        <div v-else-if="stillToPayItems.length && allocation.status !== 'Draft'" class="fx-unpaid">
          <h3 class="fx-h3">{{ $t('Still to pay') }} ({{ stillToPayItems.length }})</h3>
          <div class="fx-chips"><span v-for="i in stillToPayItems" :key="i.id" class="lg-chip lg-chip-amber" style="text-transform: none">{{ i.description }}</span></div>
        </div>
      </section>

      <!-- Notes -->
      <section v-if="allocation.notes" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="doc-text" class="w-5 h-5" /></span><h2 class="lg-card-title">{{ $t('Notes') }}</h2></header>
        <p class="fx-prewrap fx-notes">{{ allocation.notes }}</p>
      </section>

      <!-- Audit history -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span>
          <div class="min-w-0"><h2 class="lg-card-title">{{ $t('Audit History') }}</h2><p class="lg-card-sub">{{ $t('Every change to this sheet') }}</p></div>
        </header>
        <ul v-if="history.length" class="fx-timeline">
          <li v-for="h in history" :key="h.id">
            <span class="fx-tl-dot"></span>
            <div class="fx-tl-body">
              <div class="fx-tl-desc">{{ h.description }}</div>
              <div class="fx-tl-meta">{{ h.causer }} &middot; {{ $datetime(h.created_at) }}</div>
            </div>
          </li>
        </ul>
        <div v-else class="fx-emptybox">{{ $t('No history yet.') }}</div>
      </section>
    </div>

    <!-- Modals -->
    <tec-dialog-modal :show="modal === 'bulkpay'" max-width="md" @close="closeModal">
      <template #title><span class="fx-modal-title">{{ $t('Mark payments as paid') }}</span></template>
      <template #content>
        <div class="lg-page fx-modal">
          <div v-if="pageError" class="lg-note lg-note-red mb-3"><icons name="exclamation" class="w-4 h-4" /><span>{{ pageError }}</span></div>
          <div class="fx-bsum">
            <span class="fx-bsum-ico"><icons name="tick" class="w-6 h-6" /></span>
            <div>
              <div class="fx-bsum-big">{{ bulkTargets.length }} {{ $t('items') }} &middot; {{ bulkLines }} {{ $t('payments') }}</div>
              <div class="fx-bsum-sub">{{ fmt(bulkAmount) }}<i class="fx-cur">TZS</i></div>
            </div>
          </div>
          <label class="fx-lbl">{{ $t('Payment reference (optional)') }}</label>
          <input v-model="bulkRef" class="fx-input" :placeholder="$t('e.g. bank slip or batch number')" />
          <p class="fx-modal-help mt-3">{{ $t('Everyone who raises payment vouchers will be notified to generate the vouchers for this Funds Allocation.') }}</p>
        </div>
      </template>
      <template #footer>
        <div class="lg-page fx-modal fx-modal-foot">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeModal">{{ $t('Close') }}</button>
          <button type="button" class="lg-btn lg-btn-green" :disabled="busyAction" @click="submitBulk"><icons name="tick" class="w-4 h-4" />{{ $t('Mark as paid') }}</button>
        </div>
      </template>
    </tec-dialog-modal>

    <tec-dialog-modal :show="modal === 'genv'" max-width="lg" @close="closeModal">
      <template #title><span class="fx-modal-title">{{ $t('Generate payment vouchers') }}</span></template>
      <template #content>
        <div class="lg-page fx-modal">
          <div v-if="pageError" class="lg-note lg-note-red mb-3"><icons name="exclamation" class="w-4 h-4" /><span>{{ pageError }}</span></div>
          <p class="fx-modal-help">{{ $t('One payment voucher will be created for each paid item below and sent straight to the approvers. They approve them one by one.') }}</p>
          <ul class="fx-genlist">
            <li v-for="i in voucherReady" :key="i.id">
              <span class="fx-genname"><b>{{ i.description }}</b><small>{{ i.beneficiary || '-' }}</small></span>
              <span class="fx-genamt">{{ fmt(i.lines.reduce((t, l) => t + Number(l.amount), 0)) }}<i class="fx-cur">TZS</i></span>
            </li>
          </ul>
          <div class="fx-gentot">{{ voucherReady.length }} {{ $t('vouchers') }} &middot; {{ fmt(voucherReady.reduce((t, i) => t + i.lines.reduce((x, l) => x + Number(l.amount), 0), 0)) }}<i class="fx-cur">TZS</i></div>
        </div>
      </template>
      <template #footer>
        <div class="lg-page fx-modal fx-modal-foot">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeModal">{{ $t('Close') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" :disabled="busyAction || !voucherReady.length" @click="submitGen"><icons name="send" class="w-4 h-4" />{{ $t('Confirm and generate') }}</button>
        </div>
      </template>
    </tec-dialog-modal>

    <tec-dialog-modal :show="!!modal && !['voucher', 'bulkpay', 'genv'].includes(modal)" max-width="md" @close="closeModal">
      <template #title><span class="fx-modal-title">{{ modalTitle }}</span></template>
      <template #content>
        <div class="lg-page fx-modal">
          <div v-if="modalError" class="lg-note lg-note-red mb-3"><icons name="exclamation" class="w-4 h-4" /><span>{{ modalError }}</span></div>
          <template v-if="modal === 'approve' || modal === 'reassign'">
            <div class="fx-pm-head">
              <span class="fx-pm-ico"><icons name="users" class="w-5 h-5" /></span>
              <p class="fx-modal-help">{{ modal === 'approve' ? $t('Choose who will make the payments. You can choose more than one person; each of them can tick the payments as they pay them.') : $t('Choose the payers. You can choose more than one person. Ticks already made are kept.') }}</p>
            </div>
            <div v-if="allocation.assigned_to && modal === 'reassign'" class="fx-pm-current">
              <span>{{ allocation.payers && allocation.payers.length > 1 ? $t('Current payers') : $t('Current payer') }}</span><b>{{ payerNames }}</b>
            </div>
            <div class="fx-pm-bar">
              <span class="fx-pm-count" :class="{ 'is-on': modalForm.payers.length }">{{ modalForm.payers.length }} {{ $t('selected') }}</span>
              <button type="button" class="fx-pm-link" :disabled="!filteredPayers.length" @click="selectAllPayers"><icons name="tick" class="w-3.5 h-3.5" />{{ $t('Select All') }}</button>
              <button type="button" class="fx-pm-link is-red" :disabled="!modalForm.payers.length" @click="modalForm.payers = []"><icons name="cross" class="w-3.5 h-3.5" />{{ $t('Deselect All') }}</button>
            </div>
            <div class="fx-vm-search">
              <icons name="search" class="w-4 h-4" />
              <input v-model="payerQuery" :placeholder="$t('Search users by name, username or role')" />
            </div>
            <ul class="fx-plist">
              <li
                v-for="p in filteredPayers"
                :key="p.id"
                class="fx-pcard"
                :class="{ 'is-on': modalForm.payers.includes(p.id) }"
                role="checkbox"
                :aria-checked="modalForm.payers.includes(p.id)"
                tabindex="0"
                @click="togglePayer(p.id)"
                @keydown.enter.prevent="togglePayer(p.id)"
                @keydown.space.prevent="togglePayer(p.id)"
              >
                <span class="fx-pav" :style="{ background: avatarBg(p.id) }">{{ initials(p.name) }}</span>
                <div class="min-w-0 flex-1">
                  <div class="fx-pname">{{ p.name }}<span v-if="isCurrentPayer(p.id)" class="fx-ptag is-blue">{{ $t('Current') }}</span></div>
                  <div class="fx-pmeta">
                    <span v-if="p.username" class="fx-num">@{{ p.username }}</span>
                    <span v-for="r in p.roles.slice(0, 2)" :key="r" class="fx-ptag">{{ r }}</span>
                    <span v-if="!p.can_pay" class="fx-ptag is-amber">{{ $t('No pay permission yet') }}</span>
                  </div>
                </div>
                <span class="fx-pradio is-check"><icons v-if="modalForm.payers.includes(p.id)" name="tick" class="w-3.5 h-3.5" /></span>
              </li>
              <li v-if="!filteredPayers.length" class="fx-vempty"><b>{{ $t('No users found.') }}</b></li>
            </ul>
            <div v-if="payersWithoutPermission.length" class="lg-note lg-note-amber mt-3">
              <icons name="exclamation" class="w-4 h-4" />
              <span>{{ payersWithoutPermission.map(p => p.name).join(', ') }}: {{ $t(payersWithoutPermission.length > 1 ? 'These users cannot tick payments until they are given the "Funds Payer" role.' : 'This user cannot tick payments until they are given the "Funds Payer" role.') }}</span>
            </div>
          </template>
          <template v-else>
            <p class="fx-modal-help">{{ modal === 'reject' ? $t('Tell the preparer what to fix. They can edit and submit again.') : $t('Ticked payments are kept for the audit trail.') }}</p>
            <label class="fx-lbl">{{ $t('Reason') }}</label>
            <textarea v-model="modalForm.reason" rows="3" class="fx-input" :placeholder="$t('Reason')"></textarea>
          </template>
        </div>
      </template>
      <template #footer>
        <div class="lg-page fx-modal fx-modal-foot">
          <button type="button" class="lg-btn lg-btn-soft" @click="closeModal">{{ $t('Close') }}</button>
          <button type="button" class="lg-btn" :class="modal === 'approve' ? 'lg-btn-green' : modal === 'reassign' ? 'lg-btn-primary' : 'lg-btn-red'" :disabled="modalBusy || !modalValid" @click="confirmModal">{{ modalButton }}</button>
        </div>
      </template>
    </tec-dialog-modal>

    <tec-dialog-modal :show="modal === 'voucher'" max-width="lg" @close="closeModal">
      <template #title>
        <span class="fx-vm-title">
          <span class="fx-vm-ico"><icons name="link" class="w-5 h-5" /></span>
          <span>
            <span class="fx-vm-h">{{ $t('Link a payment voucher') }}</span>
            <span class="fx-vm-sub">{{ $t('Attach an existing voucher to this item, or create a new one.') }}</span>
          </span>
        </span>
      </template>
      <template #content>
        <div class="lg-page fx-modal fx-vm">
          <div v-if="voucherItem" class="fx-vm-item">
            <span class="fx-vm-item-ico"><icons name="cash" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <div class="fx-vm-item-title">{{ voucherItem.description }}</div>
              <div class="fx-vm-item-meta">{{ voucherItem.beneficiary || '-' }}</div>
            </div>
            <div class="fx-vm-item-amt"><small>{{ $t('Allocation') }}</small>{{ fmt(voucherItem.allocated_amount) }}<i class="fx-cur">TZS</i></div>
          </div>

          <div class="fx-vm-search">
            <icons name="search" class="w-4 h-4" />
            <input v-model="voucherQuery" :placeholder="$t('Search by voucher number or payee')" @input="searchVouchers" />
            <span v-if="voucherLoading" class="fx-vm-spin"></span>
          </div>

          <ul class="fx-vcards">
            <li v-for="v in voucherResults" :key="v.id" class="fx-vcard" :class="{ 'is-linked': v.already_linked }">
              <span class="fx-vcard-ico"><icons name="doc-text" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1">
                <div class="fx-vcard-top">
                  <b class="fx-num">{{ v.pv_number }}</b>
                  <span class="fx-vstatus" :class="vStatusTone(v.status)">{{ v.status }}</span>
                </div>
                <div class="fx-vcard-meta">
                  <span><icons name="user" class="w-3 h-3" />{{ v.payee_name || '-' }}</span>
                  <span v-if="v.voucher_date"><icons name="calendar" class="w-3 h-3" />{{ $date(v.voucher_date) }}</span>
                </div>
                <div v-if="v.already_linked" class="fx-vcard-warn"><icons name="exclamation" class="w-3 h-3" />{{ $t('Already linked to another allocation item') }}</div>
              </div>
              <div class="fx-vcard-amt">{{ fmt(v.total_amount) }}<i class="fx-cur">TZS</i></div>
              <button type="button" class="fx-vcard-btn" :disabled="modalBusy" @click="linkVoucher(v)"><icons name="link" class="w-3.5 h-3.5" />{{ $t('Link') }}</button>
            </li>
            <li v-if="!voucherResults.length && !voucherLoading" class="fx-vempty">
              <span class="fx-vempty-ico"><icons name="search" class="w-6 h-6" /></span>
              <b>{{ $t('No vouchers found.') }}</b>
              <small>{{ $t('Try another number or payee, or create a new voucher below.') }}</small>
            </li>
            <li v-if="voucherLoading && !voucherResults.length" class="fx-vempty"><small>{{ $t('Searching...') }}</small></li>
          </ul>

          <a v-if="voucherItem" :href="voucherItem.voucher_create_url" target="_blank" rel="noopener" class="fx-vcreate">
            <span class="fx-vcreate-ico"><icons name="plus" class="w-5 h-5" /></span>
            <span><b>{{ $t('Create a new voucher for this item') }}</b><small>{{ $t('Opens the voucher form in a new tab with this item filled in.') }}</small></span>
            <icons name="chevron-down" class="w-4 h-4 fx-vcreate-go" />
          </a>
        </div>
      </template>
      <template #footer>
        <div class="lg-page fx-modal fx-modal-foot"><button type="button" class="lg-btn lg-btn-soft" @click="closeModal">{{ $t('Close') }}</button></div>
      </template>
    </tec-dialog-modal>
  </admin-layout>
</template>

<script>
import debounce from 'lodash/debounce';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import { fmt, statusChip, num } from './fundFormat';

export default {
  components: { AdminLayout, PageHeader, StatTiles, TecDialogModal },

  props: {
    allocation: Object,
    totals: Object,
    history: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({}) },
    payers: { type: Array, default: () => [] },
  },

  data() {
    return {
      sort: { key: null, dir: 'asc' },
      selected: {},
      bulkAll: false,
      bulkRef: '',
      busy: {},
      busyAction: false,
      modal: null,
      modalForm: { payers: [], reason: '' },
      payerQuery: '',
      modalBusy: false,
      modalError: '',
      voucherItem: null,
      voucherQuery: '',
      voucherResults: [],
      voucherLoading: false,
    };
  },

  computed: {
    unpaidItems() {
      return this.allocation.items.filter(i => !i.paid && this.isPayable(i));
    },
    selectedItems() {
      return this.unpaidItems.filter(i => this.selected[i.id]);
    },
    allUnpaidSelected() {
      return this.unpaidItems.length > 0 && this.selectedItems.length === this.unpaidItems.length;
    },
    selectedAmount() {
      return this.selectedItems.reduce((t, i) => t + i.lines.filter(l => !l.is_paid).reduce((x, l) => x + Number(l.amount), 0), 0);
    },
    bulkTargets() {
      return this.bulkAll ? this.unpaidItems : this.selectedItems;
    },
    bulkLines() {
      return this.bulkTargets.reduce((t, i) => t + i.lines.filter(l => !l.is_paid).length, 0);
    },
    bulkAmount() {
      return this.bulkTargets.reduce((t, i) => t + i.lines.filter(l => !l.is_paid).reduce((x, l) => x + Number(l.amount), 0), 0);
    },
    voucherReady() {
      return this.allocation.items.filter(i => i.paid && !i.payment_voucher && i.lines.length);
    },
    pageError() {
      const e = this.$page.props.errors || {};
      return e.workflow || e.submit || e.assigned_to_user_id || e.reason || e.payment_voucher_id || e.reference || '';
    },
    canTick() {
      return ['Approved', 'In Progress'].includes(this.allocation.status);
    },
    hasActions() {
      const c = this.can;
      return c.submit || c.approve || c.reject || c.reassign || c.cancel || c.reopen || c.delete || c.clear;
    },
    methodsUsed() {
      return this.totals.by_method;
    },
    totalLines() {
      return this.allocation.items.reduce((s, i) => s + i.lines.length, 0);
    },
    paidLines() {
      return this.allocation.items.reduce((s, i) => s + i.lines.filter(l => l.is_paid).length, 0);
    },
    stillToPayItems() {
      return this.allocation.items.filter(i => !i.paid && i.lines.length);
    },
    tiles() {
      const t = this.totals;
      const showBal = this.can.see_balances !== false;
      return [
        showBal && { icon: 'cash', tone: 'navy', label: this.$t('Available'), value: fmt(t.available) + ' TZS' },
        { icon: 'collection', tone: 'indigo', label: this.$t('Allocated'), value: fmt(t.allocated) + ' TZS' },
        showBal && { icon: 'calculator', tone: t.balance < 0 ? 'rose' : 'emerald', label: this.$t('Balance after'), value: fmt(t.balance) + ' TZS', valueClass: t.balance < 0 ? 'is-bad' : 'is-good' },
        { icon: 'tick', tone: 'teal', label: this.$t('Paid so far') + ' (' + Math.round(t.progress) + '%)', value: fmt(t.paid) + ' TZS' },
        t.usd && t.usd.planned > 0 && { icon: 'cash', tone: 'emerald', label: this.$t('Total USD to pay') + ' (' + fmt(t.usd.paid) + ' ' + this.$t('paid') + ')', value: fmt(t.usd.planned) + ' USD' },
      ].filter(Boolean);
    },
    cats() {
      const groups = [];
      const index = {};
      this.allocation.items.forEach(item => {
        if (!(item.fund_category_id in index)) {
          index[item.fund_category_id] = groups.length;
          groups.push({ id: item.fund_category_id, name: item.category_name, rows: [] });
        }
        const g = groups[index[item.fund_category_id]];
        g.rows.push({ item, n: g.rows.length + 1 });
      });
      const sk = this.sort.key;
      if (sk) {
        const dir = this.sort.dir === 'desc' ? -1 : 1;
        const val = r => {
          const it = r.item;
          if (sk === 'n') return r.n;
          if (sk === 'description') return (it.description || '').toLowerCase();
          if (sk === 'beneficiary') return ((it.beneficiary || (it.contact && it.contact.name) || (it.staff && it.staff.name) || '') + '').toLowerCase();
          if (sk === 'request') return num(it.request_amount);
          if (sk === 'allocation') return num(it.allocated_amount);
          if (sk === 'voucher') return it.payment_voucher ? it.payment_voucher.pv_number || '' : '';
          if (sk.startsWith('m:')) return it.lines.filter(l => String(l.fund_payment_method_id) === sk.slice(2)).reduce((t, l) => t + num(l.amount), 0);
          return 0;
        };
        groups.forEach(g => g.rows.sort((a, b) => {
          const x = val(a), y = val(b);
          return (typeof x === 'string' ? x.localeCompare(y, undefined, { numeric: true }) : x - y) * dir;
        }));
      }
      return groups.map((g, i) => {
        const byMethod = {};
        const byMethodUsd = {};
        g.rows.forEach(r =>
          r.item.lines.forEach(l => {
            byMethod[l.fund_payment_method_id] = (byMethod[l.fund_payment_method_id] || 0) + num(l.amount);
            if (l.usd_amount !== null && l.usd_amount !== undefined) byMethodUsd[l.fund_payment_method_id] = (byMethodUsd[l.fund_payment_method_id] || 0) + num(l.usd_amount);
          })
        );
        return {
          ...g,
          letter: this.letterFor(i),
          request: g.rows.reduce((s, r) => s + num(r.item.request_amount), 0),
          allocation: g.rows.reduce((s, r) => s + num(r.item.allocated_amount), 0),
          paidItems: g.rows.filter(r => r.item.paid).length,
          byMethod,
          byMethodUsd,
        };
      });
    },
    filteredPayers() {
      const q = this.payerQuery.trim().toLowerCase();
      if (!q) return this.payers;
      return this.payers.filter(p => [p.name, p.username, ...(p.roles || [])].join(' ').toLowerCase().includes(q));
    },
    payersWithoutPermission() {
      return this.payers.filter(p => this.modalForm.payers.includes(p.id) && !p.can_pay);
    },
    payerNames() {
      const list = (this.allocation.payers && this.allocation.payers.length ? this.allocation.payers : (this.allocation.assigned_to ? [this.allocation.assigned_to] : []));
      return list.length ? list.map(p => p.name).join(', ') : this.personName(null);
    },
    modalTitle() {
      return { approve: this.$t('Approve and assign the payer'), reject: this.$t('Reject this sheet'), cancel: this.$t('Cancel this sheet'), reassign: ['Completed', 'Cleared'].includes(this.allocation.status) ? this.$t('Assign the payer again') : this.$t('Change the payer') }[this.modal] || '';
    },
    modalButton() {
      return { approve: this.$t('Approve'), reject: this.$t('Reject'), cancel: this.$t('Cancel sheet'), reassign: this.$t('Change payer') }[this.modal] || '';
    },
    modalValid() {
      if (this.modal === 'approve' || this.modal === 'reassign') return this.modalForm.payers.length > 0;
      return (this.modalForm.reason || '').trim().length > 0;
    },
  },

  methods: {
    fmt,
    statusChip,
    letterFor(i) {
      return String.fromCharCode(65 + (i % 26));
    },
    personName(p) {
      return p ? p.name : '-';
    },
    pct(a, b) {
      return b > 0 ? Math.min(100, Math.round((a / b) * 100)) : 0;
    },
    sortBy(key) {
      if (this.sort.key !== key) this.sort = { key, dir: 'asc' };
      else if (this.sort.dir === 'asc') this.sort = { key, dir: 'desc' };
      else this.sort = { key: null, dir: 'asc' };
    },
    sortCls(key) {
      return this.sort.key === key ? 'is-' + this.sort.dir : '';
    },
    ariaSort(key) {
      return this.sort.key === key ? (this.sort.dir === 'asc' ? 'ascending' : 'descending') : 'none';
    },
    isLive(st) {
      return ['Pending Approval', 'Approved', 'In Progress', 'Completed'].includes(st);
    },
    statusIcon(st) {
      return { Draft: 'doc', 'Pending Approval': 'clock', Approved: 'tick', 'In Progress': 'refresh', Completed: 'tick', Cleared: 'lock-closed', Rejected: 'x-circle', Cancelled: 'x-circle' }[st] || 'doc';
    },
    statusTone(st) {
      return { Draft: 'is-slate', 'Pending Approval': 'is-amber', Approved: 'is-blue', 'In Progress': 'is-violet', Completed: 'is-cyan', Cleared: 'is-green', Rejected: 'is-red', Cancelled: 'is-slate' }[st] || 'is-slate';
    },
    initials(n) {
      return String(n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase();
    },
    avatarBg(id) {
      const h = [215, 160, 270, 25, 190, 330, 100, 245][id % 8];
      return `linear-gradient(145deg, hsl(${h} 80% 62%), hsl(${h} 70% 38%))`;
    },
    vStatusTone(st) {
      const k = String(st || '').toLowerCase();
      if (k.includes('paid')) return 'is-green';
      if (k.includes('reject') || k.includes('cancel')) return 'is-red';
      if (k.includes('approv') || k.includes('authoris')) return 'is-blue';
      return 'is-slate';
    },
    tone(m) {
      if (m.currency === 'USD') return 'usd';
      const k = String(m.short || m.name || '').toUpperCase();
      return ['TT', 'CASH', 'MOBILE', 'CHEQUE', 'USD'].includes(k) ? k.toLowerCase() : 'other';
    },
    mIcon(m) {
      return { tt: 'send', cash: 'cash', mobile: 'mobile', cheque: 'doc-text', usd: 'sparkles' }[this.tone(m)] || 'cash';
    },
    rate(v) {
      return Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 4 });
    },
    linesFor(item, methodId) {
      return item.lines.filter(l => l.fund_payment_method_id === methodId);
    },

    // payer actions
    async tick(line, item) {
      const reference = await this.$prompt(this.$t('Payment reference (cheque, transaction or receipt number). Optional.'), {
        placeholder: this.$t('Reference'),
        confirmText: this.$t('Mark as paid'),
      });
      if (reference === null || reference === undefined) return;
      this.busy = { ...this.busy, [line.id]: true };
      this.$inertia.patch(this.route('fund-allocations.lines.pay', line.id), { reference }, {
        preserveScroll: true,
        onFinish: () => (this.busy = { ...this.busy, [line.id]: false }),
      });
    },
    async untick(line, item) {
      const ok = await this.$confirm(this.$t('Remove the tick from this payment? It will count as not paid again.'), { variant: 'danger', confirmText: this.$t('Remove tick') });
      if (!ok) return;
      this.busy = { ...this.busy, [line.id]: true };
      this.$inertia.patch(this.route('fund-allocations.lines.unpay', line.id), {}, {
        preserveScroll: true,
        onFinish: () => (this.busy = { ...this.busy, [line.id]: false }),
      });
    },

    // preparer actions
    submitSheet() {
      this.busyAction = true;
      this.$inertia.patch(this.route('fund-allocations.submit', this.allocation.id), {}, { preserveScroll: true, onFinish: () => (this.busyAction = false) });
    },
    // An item can be paid only when it has an allocated value and at least one unpaid payment line with an amount.
    isPayable(item) {
      return Number(item.allocated_amount) > 0 && item.lines.some(l => !l.is_paid && Number(l.amount) > 0);
    },
    toggleItem(id, on) {
      this.selected = { ...this.selected, [id]: on };
    },
    toggleAll(on) {
      const next = {};
      if (on) this.unpaidItems.forEach(i => (next[i.id] = true));
      this.selected = next;
    },
    openBulk(all) {
      this.bulkAll = all;
      this.bulkRef = '';
      this.modal = 'bulkpay';
    },
    submitBulk() {
      this.busyAction = true;
      const payload = this.bulkAll ? { all: true } : { item_ids: this.selectedItems.map(i => i.id) };
      if (this.bulkRef.trim()) payload.reference = this.bulkRef.trim();
      this.$inertia.post(this.route('fund-allocations.pay-bulk', this.allocation.id), payload, {
        preserveScroll: true,
        onSuccess: () => {
          this.selected = {};
          this.closeModal();
        },
        onFinish: () => (this.busyAction = false),
      });
    },
    openGen() {
      this.modal = 'genv';
    },
    submitGen() {
      this.busyAction = true;
      this.$inertia.post(this.route('fund-allocations.generate-vouchers', this.allocation.id), { item_ids: this.voucherReady.map(i => i.id) }, {
        preserveScroll: true,
        onSuccess: () => this.closeModal(),
        onFinish: () => (this.busyAction = false),
      });
    },
    async clearSheet() {
      if (!(await this.$confirm(this.$t('Close this allocation? It will be marked Cleared and locked. Only a Super Admin can change it or assign it again.')))) return;
      this.busyAction = true;
      this.$inertia.patch(this.route('fund-allocations.clear', this.allocation.id), {}, { preserveScroll: true, onFinish: () => (this.busyAction = false) });
    },
    async reopenSheet() {
      if (!(await this.$confirm(this.$t('Reopen this sheet as a draft?')))) return;
      this.busyAction = true;
      this.$inertia.patch(this.route('fund-allocations.reopen', this.allocation.id), {}, { preserveScroll: true, onFinish: () => (this.busyAction = false) });
    },
    async deleteSheet() {
      if (!(await this.$confirm(this.$t('Delete this allocation sheet?'), { variant: 'danger', confirmText: this.$t('Delete') }))) return;
      this.busyAction = true;
      this.$inertia.delete(this.route('fund-allocations.destroy', this.allocation.id), { onFinish: () => (this.busyAction = false) });
    },

    // approver modals
    openModal(kind) {
      this.modal = kind;
      this.modalError = '';
      this.modalForm = {
        payers: kind === 'approve' || kind === 'reassign' ? this.currentPayerIds().filter(id => this.payers.some(p => p.id === id)) : [],
        reason: '',
      };
    },
    currentPayerIds() {
      const list = this.allocation.payers && this.allocation.payers.length ? this.allocation.payers : (this.allocation.assigned_to ? [this.allocation.assigned_to] : []);
      return list.map(p => p.id);
    },
    isCurrentPayer(id) { return this.currentPayerIds().includes(id); },
    togglePayer(id) {
      const i = this.modalForm.payers.indexOf(id);
      if (i >= 0) this.modalForm.payers.splice(i, 1); else this.modalForm.payers.push(id);
    },
    // ticks everyone in the list (the search narrows it)
    selectAllPayers() { this.filteredPayers.forEach(p => { if (!this.modalForm.payers.includes(p.id)) this.modalForm.payers.push(p.id); }); },
    closeModal() {
      this.modal = null;
      this.voucherItem = null;
    },
    confirmModal() {
      const kind = this.modal;
      const id = this.allocation.id;
      const data = kind === 'approve' || kind === 'reassign' ? { payer_ids: this.modalForm.payers } : { reason: this.modalForm.reason.trim() };
      this.modalBusy = true;
      this.modalError = '';
      this.$inertia.patch(this.route('fund-allocations.' + kind, id), data, {
        preserveScroll: true,
        onSuccess: () => this.closeModal(),
        onError: errs => (this.modalError = Object.values(errs)[0] || this.$t('Something went wrong.')),
        onFinish: () => (this.modalBusy = false),
      });
    },

    // vouchers
    openVoucher(item) {
      this.voucherItem = item;
      this.voucherQuery = '';
      this.voucherResults = [];
      this.modal = 'voucher';
      this.modalError = '';
      this.runVoucherSearch();
    },
    searchVouchers: debounce(function () {
      this.runVoucherSearch();
    }, 300),
    runVoucherSearch() {
      this.voucherLoading = true;
      window.axios
        .post(this.route('fund-allocations.vouchers.search'), { search: this.voucherQuery })
        .then(res => (this.voucherResults = res.data))
        .catch(() => (this.voucherResults = []))
        .finally(() => (this.voucherLoading = false));
    },
    linkVoucher(v) {
      this.modalBusy = true;
      this.$inertia.patch(this.route('fund-allocations.items.voucher', this.voucherItem.id), { payment_voucher_id: v.id }, {
        preserveScroll: true,
        onSuccess: () => this.closeModal(),
        onFinish: () => (this.modalBusy = false),
      });
    },
    async unlinkVoucher(item) {
      if (!(await this.$confirm(this.$t('Unlink this payment voucher from the item?')))) return;
      this.$inertia.patch(this.route('fund-allocations.items.voucher', item.id), { payment_voucher_id: null }, { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
.fx-neg { color: #be123c; }
.fx-pos { color: #047857; font-weight: 700; }
.fx-warn { color: #b45309; font-weight: 700; }
.fx-strong { font-weight: 800; }
.fx-prewrap { white-space: pre-line; }
.fx-hint { font-size: 0.78rem; color: var(--lg-mute); }
.fx-emptybox { padding: 24px; text-align: center; color: var(--lg-faint); font-size: 0.85rem; border: 1px dashed var(--lg-line); border-radius: 14px; }
.fx-notes { font-size: 0.86rem; color: var(--lg-ink); }
.fx-status-chip { font-size: 0.8rem; padding: 4px 14px; }
.fx-h3 { font-size: 0.7rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); margin-bottom: 10px; }

/* celebration */
.fx-done {
  position: relative; overflow: hidden; display: flex; align-items: center; gap: 16px; padding: 1rem 1.3rem; margin-bottom: 1rem; border-radius: 22px;
  color: #064e3b; background: linear-gradient(135deg, rgba(209, 250, 229, 0.95), rgba(236, 253, 245, 0.9)); border: 1px solid rgba(16, 185, 129, 0.45);
  box-shadow: 0 14px 30px -18px rgba(4, 120, 87, 0.7);
}
html.dark .fx-done { color: #a7f3d0; background: linear-gradient(135deg, rgba(6, 78, 59, 0.55), rgba(15, 23, 42, 0.7)); }
.fx-done-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 54px; height: 54px; border-radius: 9999px; color: #fff; background: linear-gradient(145deg, #34d399, #047857); animation: fx-pop 0.6s ease both, fx-pulse 2.4s ease-in-out 0.6s infinite; }
.fx-done-title { font-size: 1.1rem; font-weight: 800; }
.fx-done-sub { font-size: 0.8rem; opacity: 0.85; }
.fx-spark { position: absolute; width: 8px; height: 8px; border-radius: 9999px; opacity: 0; animation: fx-rise 3s ease-in-out infinite; }
.fx-spark.s1 { left: 55%; bottom: 8px; background: #fbbf24; animation-delay: 0s; }
.fx-spark.s2 { left: 70%; bottom: 8px; background: #60a5fa; animation-delay: 0.6s; }
.fx-spark.s3 { left: 84%; bottom: 8px; background: #f472b6; animation-delay: 1.2s; }
.fx-spark.s4 { left: 93%; bottom: 8px; background: #a78bfa; animation-delay: 1.8s; }
@keyframes fx-pop { 0% { transform: scale(0.4); opacity: 0; } 70% { transform: scale(1.12); } 100% { transform: scale(1); opacity: 1; } }
@keyframes fx-pulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.45); } 50% { box-shadow: 0 0 0 12px rgba(16, 185, 129, 0); } }
@keyframes fx-rise { 0% { transform: translateY(0); opacity: 0; } 20% { opacity: 1; } 100% { transform: translateY(-70px); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .fx-done-ico, .fx-spark { animation: none; } }

.fx-top { display: grid; gap: 1rem; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); align-items: start; }
.fx-top > .lg-card { margin-bottom: 1rem; }
@media (max-width: 900px) { .fx-top { grid-template-columns: minmax(0, 1fr); } }
.fx-bigpct { font-size: 1.5rem; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--lg-ink); }
.fx-bar { height: 8px; border-radius: 9999px; background: var(--lg-soft); border: 1px solid var(--lg-line); overflow: hidden; }
.fx-bar-lg { height: 14px; }
.fx-bar span { display: block; height: 100%; border-radius: 9999px; background: linear-gradient(90deg, #60a5fa, #1d4ed8); transition: width 0.4s ease; }
.fx-bar span.done { background: linear-gradient(90deg, #34d399, #047857); }
.fx-prog-meta { display: flex; flex-wrap: wrap; gap: 8px 28px; margin-top: 12px; font-variant-numeric: tabular-nums; font-weight: 700; }
.fx-prog-meta small { display: block; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }

.fx-people { list-style: none; margin: 0 0 12px; padding: 0; }
.fx-people li { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 10px; padding: 6px 0; border-bottom: 1px solid var(--lg-line); font-size: 0.84rem; }
.fx-people li span { width: 92px; font-size: 0.64rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.fx-people li small { color: var(--lg-faint); font-size: 0.72rem; }
.fx-actions { display: flex; flex-wrap: wrap; gap: 8px; }

/* sheet table */
.fx-sheet th, .fx-sheet td { white-space: nowrap; }
.fx-sheet td.fx-wrap, .fx-sheet th.fx-wrap { white-space: normal; min-width: 150px; }
.fx-sheet td.fx-mcell { vertical-align: middle; min-width: 110px; }
.fx-sheet tbody td { vertical-align: middle; }
.fx-sn-cell { color: var(--lg-faint); }
.fx-cat-row td { background: rgba(59, 130, 246, 0.09); font-weight: 800; color: var(--lg-navy); padding-top: 9px; padding-bottom: 9px; }
html.dark .fx-cat-row td { color: #bfdbfe; }
.fx-cat-prog { margin-left: 10px; font-size: 0.7rem; font-weight: 700; color: var(--lg-mute); }
.fx-sub-row td { background: var(--lg-soft); font-weight: 800; border-top: 1.5px solid var(--lg-line); }
.fx-grand-row td { background: linear-gradient(145deg, #7f1d1d, #5c1010) !important; color: #fff !important; font-weight: 800; }
.fx-paidtot-row td { background: rgba(16, 185, 129, 0.08); font-weight: 800; }
.fx-tot-row td { background: var(--lg-soft); font-weight: 800; font-size: calc(1em + 1px); position: relative; z-index: 1; box-shadow: 0 -8px 14px -8px rgba(15, 37, 64, 0.45), inset 0 -7px 9px -7px rgba(15, 37, 64, 0.35), inset 0 2px 0 rgba(255, 255, 255, 0.8); }
.fx-tot-row td.fx-tot-val { color: #7f1d1d; font-weight: 800; }
html.dark .fx-tot-row td.fx-tot-val { color: #fca5a5; }
.fx-paid-row td { background: rgba(16, 185, 129, 0.05); }
.fx-letter { display: inline-flex; align-items: center; justify-content: center; flex: none; box-sizing: border-box; min-width: 24px; min-height: 24px; width: 24px; height: 24px; margin-right: 6px; border-radius: 8px; background: var(--lg-navy); color: #fff; font-size: 0.75rem; font-weight: 800; }
.fx-letter-sm { min-width: 20px; min-height: 20px; width: 20px; height: 20px; font-size: 0.66rem; border-radius: 6px; }
.fx-line { padding: 3px 0; }
.fx-line-amt { display: inline-flex; align-items: center; justify-content: flex-end; gap: 7px; font-variant-numeric: tabular-nums; }
.fx-paid-line { margin-top: 4px; }
/* USD is a currency: its own, visibly different column ($ badge, green tint) with USD figures */
.fx-usd-sign { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 18px; height: 18px; border-radius: 9999px; font-style: normal; font-size: 0.72rem; font-weight: 800; line-height: 1; color: #fff; background: linear-gradient(145deg, #34d399, #059669); }
.fx-usd-head { margin-right: 6px; }
.fx-sheet th.fx-usd-col { background: rgba(16, 185, 129, 0.12); }
.fx-sheet td.fx-usd-col { background: rgba(16, 185, 129, 0.06); }
.fx-line-conv { margin-top: 1px; font-size: 0.64rem; font-weight: 700; color: #047857; font-variant-numeric: tabular-nums; }
.fx-line-conv.is-warn { color: #b45309; }
.fx-sheet .fx-pline .fx-line-conv { text-align: center; }
.fx-num { font-variant-numeric: tabular-nums; }
.fx-line-meta { font-size: 0.66rem; color: var(--lg-faint); white-space: normal; max-width: 180px; margin-left: auto; }
.fx-line.paid .fx-line-amt > span:first-child { color: #047857; font-weight: 700; }
.fx-tick { box-sizing: border-box; padding: 0 !important; min-height: 0 !important; min-width: 0 !important; display: inline-flex; align-items: center; justify-content: center; flex: none; width: 24px; height: 24px; border-radius: 9999px; border: 2px solid #94a3b8; color: transparent; background: var(--lg-card); transition: all 0.15s ease; }
.fx-tick:hover:not(:disabled) { border-color: #10b981; color: #10b981; transform: scale(1.1); }
.fx-tick.is-on { border-color: #10b981; background: #10b981; color: #fff; }
.fx-tick.is-on:hover:not(:disabled) { background: #dc2626; border-color: #dc2626; color: #fff; }
.fx-tick:disabled { opacity: 0.5; cursor: wait; }
.fx-dot { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 18px; height: 18px; border-radius: 9999px; border: 2px solid #cbd5e1; color: #fff; }
.fx-dot.on { background: #10b981; border-color: #10b981; }
.fx-sheet td.fx-voucher-cell { min-width: 0; white-space: nowrap; }
.fx-voucher-actions { display: flex; flex-wrap: nowrap; gap: 6px; margin-top: 0; }
.fx-mini { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 9999px; font-size: 0.7rem; font-weight: 800; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-navy); }
.fx-mini:hover { background: rgba(28, 63, 102, 0.1); }
html.dark .fx-mini { color: #bfdbfe; }


/* sortable headers */
.fx-th-btn { display: inline-flex; align-items: center; gap: 6px; padding: 0 !important; min-height: 0 !important; background: transparent; border: 0; color: inherit; font: inherit; font-weight: inherit; letter-spacing: inherit; text-transform: inherit; cursor: pointer; white-space: nowrap; }
.fx-th-btn.is-num { justify-content: flex-end; }
.fx-th-btn:hover { color: #1d4ed8; }
.fx-sort { display: inline-flex; flex-direction: column; gap: 2px; opacity: 0.45; }
.fx-sort::before, .fx-sort::after { content: ''; border-left: 4px solid transparent; border-right: 4px solid transparent; }
.fx-sort::before { border-bottom: 5px solid currentColor; }
.fx-sort::after { border-top: 5px solid currentColor; }
.fx-sort.is-asc, .fx-sort.is-desc { opacity: 1; color: #2563eb; }
.fx-sort.is-asc::after { opacity: 0.2; }
.fx-sort.is-desc::before { opacity: 0.2; }

/* method / voucher containers */
.fx-mico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 22px; height: 22px; border-radius: 8px; color: #fff; background: linear-gradient(145deg, var(--m1), var(--m2)); box-shadow: 0 6px 10px -5px var(--m2); }
.fx-m-tt { --m1: #60a5fa; --m2: #1d4ed8; --mt: rgba(59, 130, 246, 0.12); }
.fx-m-cash { --m1: #34d399; --m2: #047857; --mt: rgba(16, 185, 129, 0.13); }
.fx-m-mobile { --m1: #a78bfa; --m2: #6d28d9; --mt: rgba(139, 92, 246, 0.13); }
.fx-m-cheque { --m1: #fbbf24; --m2: #b45309; --mt: rgba(245, 158, 11, 0.15); }
.fx-m-usd { --m1: #2dd4bf; --m2: #0f766e; --mt: rgba(20, 184, 166, 0.14); }
.fx-m-other { --m1: #94a3b8; --m2: #475569; --mt: rgba(100, 116, 139, 0.12); }
.fx-sheet thead th, .fx-sheet thead th .fx-th-btn { color: #0f2f5a; font-weight: 800; }
.fx-sheet thead th .fx-th-btn.fx-th-method, .fx-sheet thead th .fx-th-btn.fx-th-voucher { padding: 0 !important; background: none; box-shadow: none; border-radius: 0; color: #7f1d1d; font-weight: 800; }
.fx-sheet thead th .fx-th-btn:hover { color: #1d4ed8; }
.fx-sheet thead th .fx-th-btn.fx-th-method:hover, .fx-sheet thead th .fx-th-btn.fx-th-voucher:hover { color: #991b1b; }
html.dark .fx-sheet thead th, html.dark .fx-sheet thead th .fx-th-btn { color: #bfdbfe; }
html.dark .fx-sheet thead th .fx-th-btn.fx-th-method, html.dark .fx-sheet thead th .fx-th-btn.fx-th-voucher { color: #fca5a5; }
.fx-sheet .fx-pline { display: block; padding: 1px 0; }
.fx-sheet .fx-pline .fx-line-amt { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; }
.fx-sheet .fx-pline .fx-line-n { font-weight: 800; color: #7f1d1d; font-variant-numeric: tabular-nums; }
.fx-sheet .fx-pline.paid .fx-line-n { color: #047857; }
.fx-sheet .fx-pline .fx-line-meta { margin: 0 auto; text-align: center; }
.fx-sheet td.fx-mctr, .fx-sheet th.fx-mctr-h { text-align: center; }
.fx-sheet td.fx-mcell .fx-pline { margin: 0 auto; }
.fx-vbox { display: inline-flex; flex-direction: row; align-items: center; flex-wrap: nowrap; gap: 6px; white-space: nowrap; }
.fx-vbox .fx-voucher-actions { display: inline-flex; flex-wrap: nowrap; gap: 6px; margin-top: 0; }
.fx-vbox > template, .fx-vbox .lg-subtext { margin: 0; }
.fx-mini { white-space: nowrap; box-shadow: 0 5px 10px -4px rgba(15, 37, 64, 0.45), 0 1px 2px rgba(15, 37, 64, 0.15); transition: box-shadow 0.15s ease, transform 0.15s ease; cursor: pointer; }
.fx-mini:hover { transform: translateY(-1px); box-shadow: 0 8px 14px -5px rgba(15, 37, 64, 0.5), 0 1px 2px rgba(15, 37, 64, 0.15); }
.fx-mini-link { background: linear-gradient(145deg, #eff6ff, #dbeafe); border-color: #93c5fd; color: #1d4ed8; box-shadow: 0 5px 10px -4px rgba(29, 78, 216, 0.55), 0 1px 2px rgba(15, 37, 64, 0.15); }
.fx-mini-create { background: linear-gradient(145deg, #ecfdf5, #d1fae5); border-color: #6ee7b7; color: #047857; box-shadow: 0 5px 10px -4px rgba(4, 120, 87, 0.55), 0 1px 2px rgba(15, 37, 64, 0.15); }
html.dark .fx-mini-link, html.dark .fx-mini-create { background: var(--lg-card); }
html.dark .fx-mini-link { color: #93c5fd; }
html.dark .fx-mini-create { color: #6ee7b7; }
html.dark .fx-sheet .fx-pline .fx-line-n { color: #fca5a5; }

/* horizontal row lines with soft shadow, and a big shadow after Allocation */
.fx-sheet tbody tr > td { padding-top: 7px; padding-bottom: 7px; box-shadow: inset 0 -1px 0 var(--lg-line); }
.fx-sheet .fx-alloc-col { position: relative; z-index: 2; box-shadow: inset 0 -1px 0 var(--lg-line), 14px 0 18px -8px rgba(15, 37, 64, 0.45); }
.fx-sheet thead th.fx-alloc-col { box-shadow: inset 0 6px 7px -6px rgba(15, 37, 64, 0.4), inset 0 -6px 7px -6px rgba(15, 37, 64, 0.4), 14px 0 18px -8px rgba(15, 37, 64, 0.45); }

/* zebra sections: dark blue / light blue */
.fx-sheet tbody .fx-cat-row td, .fx-sheet tbody.fx-z0 .fx-cat-row td, .fx-sheet tbody.fx-z1 .fx-cat-row td { background: #c9d2f2; color: #0f2540; }
.fx-sheet tbody.fx-z0 .fx-cat-row .fx-letter, .fx-sheet tbody.fx-z1 .fx-cat-row .fx-letter { background: #1c3f66; color: #fff; }
.fx-sheet tbody.fx-z0 .fx-cat-prog, .fx-sheet tbody.fx-z1 .fx-cat-prog { color: #1c3f66; }
.fx-sheet tbody.fx-z0 tr:not(.fx-cat-row) > td { background-color: #ffffff; }
.fx-sheet tbody.fx-z0 .fx-sub-row td { background-color: #ffffff; }
.fx-sheet tbody.fx-z1 tr:not(.fx-cat-row) > td { background-color: #ffffff; }
.fx-sheet tbody.fx-z1 .fx-sub-row td { background-color: #ffffff; }
.fx-sheet tbody tr.fx-paid-row > td:first-child { box-shadow: inset 4px 0 0 #10b981, inset 0 -1px 0 var(--lg-line); }
html.dark .fx-sheet tbody.fx-z0 tr:not(.fx-cat-row) > td { background-color: #14233a; }
html.dark .fx-sheet tbody.fx-z0 .fx-sub-row td { background-color: #1b3354; }
html.dark .fx-sheet tbody.fx-z1 tr:not(.fx-cat-row) > td { background-color: #1b2d4a; }
html.dark .fx-sheet tbody.fx-z1 .fx-sub-row td { background-color: #264268; }
html.dark html.dark .fx-sheet tbody.fx-z1 .fx-cat-prog, html.dark .fx-sheet tbody.fx-z0 .fx-cat-prog { color: #bfdbfe; }
html.dark .fx-sheet tbody .fx-cat-row td, html.dark .fx-sheet tbody.fx-z0 .fx-cat-row td, html.dark .fx-sheet tbody.fx-z1 .fx-cat-row td { background: #2b3a63; color: #e2e8f0; }
html.dark .fx-sheet tbody.fx-z0 .fx-lg-main, html.dark .fx-sheet tbody tr:not(.fx-cat-row) td { color: #e2e8f0; }


/* link voucher modal */
.fx-vm-title { display: flex; align-items: center; gap: 12px; }
.fx-vm-ico { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 13px; color: #fff; background: linear-gradient(145deg, #22d3ee, #0e7490); box-shadow: 0 10px 18px -8px #0e7490; }
.fx-vm-h { display: block; font-weight: 800; font-size: 1.05rem; line-height: 1.2; }
.fx-vm-sub { display: block; font-size: 0.74rem; font-weight: 500; color: #64748b; }
.fx-vm-item { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 16px; background: linear-gradient(135deg, rgba(99, 132, 255, 0.14), rgba(255, 255, 255, 0.6)); border: 1px solid rgba(99, 132, 255, 0.3); box-shadow: 0 8px 16px -10px rgba(28, 63, 102, 0.5); }
.fx-vm-item-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 36px; height: 36px; border-radius: 12px; color: #fff; background: linear-gradient(145deg, #818cf8, #4338ca); box-shadow: 0 8px 14px -7px #4338ca; }
.fx-vm-item-title { font-weight: 800; font-size: 0.9rem; color: var(--lg-ink); }
.fx-vm-item-meta { font-size: 0.74rem; color: var(--lg-mute); }
.fx-vm-item-amt { text-align: right; font-weight: 800; font-variant-numeric: tabular-nums; color: var(--lg-ink); }
.fx-vm-item-amt small { display: block; font-size: 0.6rem; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.fx-vm-search { display: flex; align-items: center; gap: 10px; margin: 14px 0 10px; padding: 0 14px; height: 46px; border-radius: 14px; border: 1px solid var(--lg-line); background: var(--lg-card); color: #64748b; box-shadow: 0 6px 14px -9px rgba(15, 37, 64, 0.45); }
.fx-vm-search:focus-within { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18); }
.fx-vm-search input { flex: 1; min-width: 0; border: 0 !important; outline: none !important; box-shadow: none !important; background: transparent; font-size: 0.9rem; color: var(--lg-ink); padding: 0; }
.fx-vm-spin { width: 16px; height: 16px; border-radius: 9999px; border: 2px solid #cbd5e1; border-top-color: #3b82f6; animation: fx-spin 0.7s linear infinite; }
@keyframes fx-spin { to { transform: rotate(360deg); } }
.fx-vcards { list-style: none; margin: 0 0 12px; padding: 2px 4px 6px 2px; max-height: 330px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; }
.fx-vcard { display: flex; align-items: center; gap: 12px; padding: 11px 12px; border-radius: 16px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: 0 8px 16px -10px rgba(15, 37, 64, 0.5), 0 1px 2px rgba(15, 37, 64, 0.08); transition: transform 0.15s ease, box-shadow 0.15s ease; }
.fx-vcard:hover { transform: translateY(-1px); box-shadow: 0 12px 20px -10px rgba(14, 116, 144, 0.55), 0 1px 2px rgba(15, 37, 64, 0.1); }
.fx-vcard.is-linked { opacity: 0.8; background: #fffbeb; border-color: #fcd34d; }
.fx-vcard-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 38px; height: 38px; border-radius: 12px; color: #fff; background: linear-gradient(145deg, #38bdf8, #0369a1); box-shadow: 0 8px 14px -7px #0369a1; }
.fx-vcard-top { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.fx-vcard-top b { font-size: 0.9rem; color: var(--lg-ink); }
.fx-vcard-meta { display: flex; flex-wrap: wrap; gap: 4px 14px; margin-top: 2px; font-size: 0.74rem; color: var(--lg-mute); }
.fx-vcard-meta span { display: inline-flex; align-items: center; gap: 4px; }
.fx-vcard-warn { display: inline-flex; align-items: center; gap: 4px; margin-top: 3px; font-size: 0.7rem; font-weight: 700; color: #b45309; }
.fx-vcard-amt { font-weight: 800; font-variant-numeric: tabular-nums; color: var(--lg-ink); white-space: nowrap; }
.fx-vcard-btn { display: inline-flex; align-items: center; gap: 5px; padding: 7px 14px; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; color: #fff; border: 0; cursor: pointer; background: linear-gradient(145deg, #3b82f6, #1c3f66); box-shadow: 0 8px 14px -6px rgba(28, 63, 102, 0.7); transition: transform 0.15s ease, box-shadow 0.15s ease; }
.fx-vcard-btn:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 12px 18px -7px rgba(28, 63, 102, 0.75); }
.fx-vcard-btn:disabled { opacity: 0.55; cursor: wait; }
.fx-vstatus { padding: 2px 9px; border-radius: 9999px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.03em; text-transform: uppercase; }
.fx-vstatus.is-slate { background: #e2e8f0; color: #334155; }
.fx-vstatus.is-blue { background: #dbeafe; color: #1d4ed8; }
.fx-vstatus.is-green { background: #d1fae5; color: #047857; }
.fx-vstatus.is-red { background: #ffe4e6; color: #be123c; }
.fx-vempty { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 22px 12px; text-align: center; border: 1px dashed var(--lg-line); border-radius: 16px; color: var(--lg-mute); }
.fx-vempty b { color: var(--lg-ink); font-size: 0.88rem; }
.fx-vempty small { font-size: 0.74rem; }
.fx-vempty-ico { display: inline-flex; align-items: center; justify-content: center; width: 46px; height: 46px; border-radius: 9999px; background: var(--lg-soft); color: #94a3b8; }
.fx-vcreate { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 16px; text-decoration: none; background: linear-gradient(135deg, rgba(16, 185, 129, 0.14), rgba(255, 255, 255, 0.6)); border: 1px solid rgba(16, 185, 129, 0.4); box-shadow: 0 8px 16px -10px rgba(4, 120, 87, 0.6); color: var(--lg-ink); transition: transform 0.15s ease, box-shadow 0.15s ease; }
.fx-vcreate:hover { transform: translateY(-1px); box-shadow: 0 12px 20px -10px rgba(4, 120, 87, 0.65); }
.fx-vcreate-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 38px; height: 38px; border-radius: 12px; color: #fff; background: linear-gradient(145deg, #34d399, #047857); box-shadow: 0 8px 14px -7px #047857; }
.fx-vcreate b { display: block; font-size: 0.88rem; }
.fx-vcreate small { display: block; font-size: 0.72rem; color: var(--lg-mute); }
.fx-vcreate-go { margin-left: auto; transform: rotate(-90deg); color: #047857; }
@media (max-width: 560px) {
  .fx-vcard { flex-wrap: wrap; }
  .fx-vcard-amt { margin-left: auto; }
  .fx-vcard-btn { width: 100%; justify-content: center; }
}


/* payer modal + action button shadows */
.fx-btn-shadow { transition: transform 0.15s ease, box-shadow 0.15s ease; }
.fx-btn-shadow:hover { transform: translateY(-1px); }
.fx-btn-blue { border-color: #93c5fd !important; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12), 0 10px 18px -8px rgba(29, 78, 216, 0.6), 0 2px 4px rgba(15, 37, 64, 0.12) !important; }
.fx-btn-blue:hover { box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.18), 0 14px 22px -8px rgba(29, 78, 216, 0.65), 0 2px 4px rgba(15, 37, 64, 0.12) !important; }
.fx-btn-rose { border-color: #fda4af !important; color: #be123c !important; box-shadow: 0 0 0 3px rgba(244, 63, 94, 0.12), 0 10px 18px -8px rgba(190, 18, 60, 0.6), 0 2px 4px rgba(15, 37, 64, 0.12) !important; }
.fx-btn-rose:hover { box-shadow: 0 0 0 4px rgba(244, 63, 94, 0.18), 0 14px 22px -8px rgba(190, 18, 60, 0.65), 0 2px 4px rgba(15, 37, 64, 0.12) !important; }
.fx-pm-head { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
.fx-pm-head .fx-modal-help { margin: 0; }
.fx-pm-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 40px; height: 40px; border-radius: 13px; color: #fff; background: linear-gradient(145deg, #818cf8, #4338ca); box-shadow: 0 10px 18px -8px #4338ca; }
.fx-pm-current { display: flex; justify-content: space-between; gap: 10px; padding: 8px 12px; margin-bottom: 4px; border-radius: 12px; background: rgba(99, 132, 255, 0.12); border: 1px solid rgba(99, 132, 255, 0.3); font-size: 0.8rem; }
.fx-pm-current span { color: var(--lg-mute); }
.fx-plist { list-style: none; margin: 0; padding: 2px 4px 6px 2px; max-height: 320px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; }
.fx-pcard { display: flex; align-items: center; gap: 12px; padding: 9px 12px; border-radius: 15px; cursor: pointer; background: var(--lg-card); border: 1.5px solid var(--lg-line); box-shadow: 0 8px 16px -10px rgba(15, 37, 64, 0.5), 0 1px 2px rgba(15, 37, 64, 0.08); transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease; }
.fx-pcard:hover { transform: translateY(-1px); border-color: #93c5fd; }
.fx-pcard:focus-visible { outline: 2px solid #3b82f6; outline-offset: 2px; }
.fx-pcard.is-on { border-color: #3b82f6; background: linear-gradient(135deg, rgba(59, 130, 246, 0.12), rgba(255, 255, 255, 0.7)); box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18), 0 12px 20px -10px rgba(29, 78, 216, 0.6); }
.fx-pav { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 38px; height: 38px; border-radius: 9999px; color: #fff; font-size: 0.8rem; font-weight: 800; box-shadow: 0 6px 12px -5px rgba(15, 37, 64, 0.6); }
.fx-pname { display: flex; align-items: center; gap: 8px; font-weight: 800; font-size: 0.9rem; color: var(--lg-ink); }
.fx-pmeta { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 6px; margin-top: 2px; font-size: 0.72rem; color: var(--lg-mute); }
.fx-ptag { padding: 1px 8px; border-radius: 9999px; font-size: 0.64rem; font-weight: 800; background: #e2e8f0; color: #334155; }
.fx-ptag.is-blue { background: #dbeafe; color: #1d4ed8; }
.fx-ptag.is-amber { background: #fef3c7; color: #b45309; }
.fx-pradio { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 22px; height: 22px; border-radius: 9999px; border: 2px solid #cbd5e1; color: #fff; }
.fx-pcard.is-on .fx-pradio { background: #3b82f6; border-color: #3b82f6; }
.fx-pradio.is-check { border-radius: 7px; }
.fx-pm-bar { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin: 0 0 10px; }
.fx-pm-count { margin-right: auto; padding: 3px 10px; border-radius: 999px; font-size: 0.78rem; font-weight: 800; color: #64748b; background: #f1f5f9; } .fx-pm-count.is-on { color: #1d4ed8; background: #dbeafe; }
.fx-pm-link { display: inline-flex; align-items: center; gap: 5px; padding: 5px 11px; border: 0; border-radius: 999px; font-size: 0.78rem; font-weight: 800; color: #1d4ed8; background: #eff6ff; cursor: pointer; } .fx-pm-link:hover:not(:disabled) { background: #dbeafe; } .fx-pm-link.is-red { color: #b91c1c; background: #fef2f2; } .fx-pm-link.is-red:hover:not(:disabled) { background: #fee2e2; } .fx-pm-link:disabled { opacity: 0.45; cursor: not-allowed; }
.fx-modal-foot .lg-btn { box-shadow: 0 10px 18px -8px rgba(15, 37, 64, 0.5), 0 2px 4px rgba(15, 37, 64, 0.12); }


/* subtotal rows: dark blue, white bold */
.fx-sheet tbody .fx-sub-row > td, .fx-sheet tbody.fx-z0 .fx-sub-row > td, .fx-sheet tbody.fx-z1 .fx-sub-row > td { background: linear-gradient(145deg, #1c3f66, #0f2540) !important; color: #fff !important; font-weight: 800; box-shadow: none; border-top: 0; }
.fx-sheet tbody .fx-sub-row > td.fx-alloc-col { box-shadow: 14px 0 18px -8px rgba(15, 37, 64, 0.45); }


/* header row: shadow above and below, coloured method icons */
.fx-sheet thead th { box-shadow: inset 0 6px 7px -6px rgba(15, 37, 64, 0.4), inset 0 -6px 7px -6px rgba(15, 37, 64, 0.4); padding-top: 12px; padding-bottom: 12px; }
.fx-hico { flex: none; }
.fx-hi-tt { color: #2563eb; }
.fx-hi-cash { color: #059669; }
.fx-hi-mobile { color: #7c3aed; }
.fx-hi-cheque { color: #d97706; }
.fx-hi-usd { color: #0d9488; }
.fx-hi-other { color: #64748b; }
.fx-hi-voucher { color: #0891b2; }

.fx-cur { font-style: normal; font-size: 0.72em; font-weight: 700; margin-left: 4px; opacity: 0.8; letter-spacing: 0.03em; }


/* close / cleared */
.fx-done-ask { margin-top: 6px; font-size: 0.8rem; font-weight: 700; }
.fx-close-btn { position: relative; z-index: 1; display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 9999px; border: 0; cursor: pointer; font-weight: 800; font-size: 0.85rem; color: #fff; background: linear-gradient(145deg, #1c3f66, #0f2540); box-shadow: 0 12px 20px -8px rgba(15, 37, 64, 0.7), 0 0 0 4px rgba(28, 63, 102, 0.12); transition: transform 0.15s ease; }
.fx-close-btn:hover:not(:disabled) { transform: translateY(-1px); }
.fx-close-btn:disabled { opacity: 0.6; cursor: wait; }
.fx-cleared { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 1rem; padding: 14px 18px; border-radius: 20px; color: #0f2540; background: linear-gradient(135deg, #dbe6ff, #f2f6ff); border: 1px solid #b8c8f5; box-shadow: 0 14px 26px -16px rgba(28, 63, 102, 0.6); }
html.dark .fx-cleared { color: #dbeafe; background: linear-gradient(135deg, rgba(30, 58, 138, 0.5), rgba(15, 23, 42, 0.7)); border-color: rgba(147, 197, 253, 0.3); }
.fx-cleared-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 48px; height: 48px; border-radius: 9999px; color: #fff; background: linear-gradient(145deg, #3b82f6, #1c3f66); box-shadow: 0 10px 18px -8px #1c3f66; }
.fx-cleared-title { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; font-size: 1.1rem; font-weight: 900; letter-spacing: 0.08em; }
.fx-cleared-pill { display: inline-flex; align-items: center; gap: 4px; padding: 2px 10px; border-radius: 9999px; font-size: 0.68rem; letter-spacing: 0.03em; background: #d1fae5; color: #047857; }
.fx-cleared-sub { font-size: 0.82rem; margin-top: 2px; }
.fx-cleared-amt { font-weight: 900; font-size: 1.1rem; color: #7f1d1d; font-variant-numeric: tabular-nums; }
html.dark .fx-cleared-amt { color: #fca5a5; }


/* bulk pay + voucher generation */
.fx-bulkbar { display: flex; align-items: center; flex-wrap: wrap; gap: 10px 12px; margin: 0 0 12px; padding: 10px 14px; border-radius: 16px; background: linear-gradient(135deg, rgba(99, 132, 255, 0.1), rgba(255, 255, 255, 0.6)); border: 1px solid rgba(99, 132, 255, 0.3); box-shadow: 0 10px 20px -14px rgba(28, 63, 102, 0.6); }
.fx-grow { flex: 1; }
.fx-check { display: inline-flex; align-items: center; gap: 8px; font-size: 0.82rem; font-weight: 700; cursor: pointer; }
.fx-check input, .fx-rowcheck input { width: 16px; height: 16px; accent-color: #1c3f66; cursor: pointer; }
.fx-rowcheck { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
.fx-bulk-count { font-size: 0.8rem; color: var(--lg-mute); font-weight: 700; }
.fx-bulk-btn { display: inline-flex; align-items: center; gap: 7px; padding: 8px 14px; border-radius: 9999px; border: 0; cursor: pointer; font-weight: 800; font-size: 0.8rem; color: #fff; transition: transform 0.15s ease, box-shadow 0.15s ease; }
.fx-bulk-btn:hover:not(:disabled) { transform: translateY(-1px); }
.fx-bulk-btn:disabled { opacity: 0.45; cursor: not-allowed; }
.fx-bulk-btn.is-green { background: linear-gradient(145deg, #34d399, #047857); box-shadow: 0 10px 18px -8px rgba(4, 120, 87, 0.75); }
.fx-bulk-btn.is-green-soft { color: #047857; background: #ecfdf5; border: 1px solid #6ee7b7; box-shadow: 0 8px 14px -8px rgba(4, 120, 87, 0.6); }
.fx-bulk-btn.is-blue { background: linear-gradient(145deg, #3b82f6, #1c3f66); box-shadow: 0 10px 18px -8px rgba(28, 63, 102, 0.75); }
.fx-bulk-n { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 9999px; background: rgba(255, 255, 255, 0.25); font-size: 0.7rem; }
.fx-bsum { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; padding: 12px 14px; border-radius: 16px; background: linear-gradient(135deg, rgba(16, 185, 129, 0.14), rgba(255, 255, 255, 0.6)); border: 1px solid rgba(16, 185, 129, 0.4); box-shadow: 0 8px 16px -10px rgba(4, 120, 87, 0.6); }
.fx-bsum-ico { display: inline-flex; align-items: center; justify-content: center; width: 46px; height: 46px; border-radius: 9999px; color: #fff; background: linear-gradient(145deg, #34d399, #047857); box-shadow: 0 10px 18px -8px #047857; }
.fx-bsum-big { font-weight: 800; font-size: 1rem; color: var(--lg-ink); }
.fx-bsum-sub { font-weight: 800; color: #7f1d1d; font-size: 1.1rem; font-variant-numeric: tabular-nums; }
.fx-genlist { list-style: none; margin: 10px 0; padding: 2px 4px 6px 2px; max-height: 300px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; }
.fx-genlist li { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 9px 12px; border-radius: 14px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: 0 8px 14px -10px rgba(15, 37, 64, 0.5); }
.fx-genname { display: flex; flex-direction: column; min-width: 0; font-size: 0.85rem; }
.fx-genname small { color: var(--lg-mute); font-size: 0.72rem; }
.fx-genamt { font-weight: 800; color: #7f1d1d; font-variant-numeric: tabular-nums; white-space: nowrap; }
.fx-gentot { text-align: right; font-weight: 800; padding-top: 4px; }


/* checkboxes with a soft shadow */
.fx-check input[type='checkbox'], .fx-rowcheck input[type='checkbox'] {
  appearance: none; -webkit-appearance: none; flex: none; width: 18px; height: 18px; margin: 0; border-radius: 6px; cursor: pointer;
  border: 1.5px solid #94a3b8; background: var(--lg-card, #fff); position: relative;
  box-shadow: 0 5px 9px -3px rgba(15, 37, 64, 0.5), 0 1px 2px rgba(15, 37, 64, 0.18);
  transition: box-shadow 0.15s ease, background 0.15s ease, transform 0.15s ease, border-color 0.15s ease;
}
.fx-check input[type='checkbox']:hover:not(:disabled), .fx-rowcheck input[type='checkbox']:hover:not(:disabled) { transform: translateY(-1px); border-color: #3b82f6; box-shadow: 0 8px 12px -4px rgba(29, 78, 216, 0.55), 0 1px 2px rgba(15, 37, 64, 0.18); }
.fx-check input[type='checkbox']:checked, .fx-rowcheck input[type='checkbox']:checked { border-color: #1c3f66; background: linear-gradient(145deg, #3b82f6, #1c3f66); }
.fx-check input[type='checkbox']:checked::after, .fx-rowcheck input[type='checkbox']:checked::after {
  content: ''; position: absolute; left: 5px; top: 1.5px; width: 5px; height: 9px; border: solid #fff; border-width: 0 2.2px 2.2px 0; transform: rotate(45deg);
}
.fx-check input[type='checkbox']:focus-visible, .fx-rowcheck input[type='checkbox']:focus-visible { outline: 2px solid #3b82f6; outline-offset: 2px; }
.fx-check input[type='checkbox']:disabled, .fx-rowcheck input[type='checkbox']:disabled { cursor: not-allowed; opacity: 0.4; box-shadow: none; background: var(--lg-soft, #f1f5f9); border-style: dashed; }
.fx-rowcheck.is-disabled { cursor: not-allowed; opacity: 0.75; }
.fx-rowcheck.is-disabled span { color: var(--lg-faint); }


/* header: live status pill + coloured buttons */
.fx-status { --sc: #475569; --sb: rgba(100, 116, 139, 0.14); display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px 8px 12px; border-radius: 9999px; font-size: 0.82rem; font-weight: 800; letter-spacing: 0.02em; color: var(--sc); background: var(--sb); border: 1px solid color-mix(in srgb, var(--sc) 30%, transparent); box-shadow: 0 10px 18px -12px var(--sc); }
.fx-status.is-amber { --sc: #b45309; --sb: rgba(245, 158, 11, 0.16); }
.fx-status.is-blue { --sc: #1d4ed8; --sb: rgba(59, 130, 246, 0.14); }
.fx-status.is-violet { --sc: #6d28d9; --sb: rgba(139, 92, 246, 0.15); }
.fx-status.is-cyan { --sc: #0e7490; --sb: rgba(6, 182, 212, 0.16); }
.fx-status.is-green { --sc: #047857; --sb: rgba(16, 185, 129, 0.16); }
.fx-status.is-red { --sc: #be123c; --sb: rgba(244, 63, 94, 0.14); }
.fx-status-dot { position: relative; width: 9px; height: 9px; border-radius: 9999px; background: var(--sc); flex: none; }
.fx-status-dot.is-live::after { content: ''; position: absolute; inset: -4px; border-radius: 9999px; border: 2px solid var(--sc); opacity: 0; animation: fx-live 1.8s ease-out infinite; }
@keyframes fx-live { 0% { transform: scale(0.5); opacity: 0.8; } 100% { transform: scale(1.6); opacity: 0; } }
.fx-hbtn { display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px; border-radius: 9999px; font-size: 0.85rem; font-weight: 800; text-decoration: none; cursor: pointer; border: 1px solid transparent; transition: transform 0.15s ease, box-shadow 0.15s ease; }
.fx-hbtn:hover { transform: translateY(-1px); }
.fx-hbtn.is-slate { color: #1c3f66; background: linear-gradient(145deg, #ffffff, #eef2fb); border-color: #c9d2f2; box-shadow: 0 10px 16px -10px rgba(28, 63, 102, 0.55), 0 1px 2px rgba(15, 37, 64, 0.1); }
.fx-hbtn.is-violet { color: #fff; background: linear-gradient(145deg, #a78bfa, #6d28d9); box-shadow: 0 12px 20px -10px rgba(109, 40, 217, 0.8); }
.fx-hbtn.is-rose { color: #fff; background: linear-gradient(145deg, #fb7185, #be123c); box-shadow: 0 12px 20px -10px rgba(190, 18, 60, 0.8); }
.fx-hbtn.is-green { color: #fff; background: linear-gradient(145deg, #34d399, #047857); box-shadow: 0 12px 20px -10px rgba(4, 120, 87, 0.85); }
html.dark .fx-hbtn.is-slate { color: #bfdbfe; background: var(--lg-card); border-color: rgba(147, 197, 253, 0.3); }
@media (prefers-reduced-motion: reduce) { .fx-status-dot.is-live::after { animation: none; } }

/* reconciliation */
.fx-recon { display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(min(280px, 100%), 1fr)); }
.fx-rrow { margin-bottom: 12px; }
.fx-rrow-top { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 4px; font-size: 0.82rem; font-weight: 700; }
.fx-nums { font-variant-numeric: tabular-nums; color: var(--lg-mute); }
.fx-nums small { font-weight: 600; }
.fx-unrec { margin: 4px 0 0 16px; list-style: disc; }
.fx-unpaid { margin-top: 16px; }
.fx-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.fx-chips { min-width: 0; } .fx-chips .lg-chip { max-width: 100%; white-space: normal !important; border-radius: 14px; overflow-wrap: anywhere; line-height: 1.3; text-align: left; }
.fx-mcat-head { min-width: 0; } .fx-mcat-head b { min-width: 0; overflow-wrap: anywhere; }

/* timeline */
.fx-timeline { list-style: none; margin: 0; padding: 0 0 0 6px; }
.fx-timeline li { position: relative; display: flex; gap: 12px; padding: 0 0 14px 14px; border-left: 2px solid var(--lg-line); }
.fx-timeline li:last-child { border-left-color: transparent; padding-bottom: 0; }
.fx-tl-dot { position: absolute; left: -7px; top: 3px; width: 12px; height: 12px; border-radius: 9999px; background: #3b82f6; box-shadow: 0 0 0 3px var(--lg-card); }
.fx-tl-desc { font-size: 0.86rem; font-weight: 700; color: var(--lg-ink); }
.fx-tl-meta { font-size: 0.72rem; color: var(--lg-faint); }

/* modals */
html .fx-modal.lg-page { --lg-card: #ffffff; --lg-line: #e6ebf3; --lg-soft: #f6f8fc; --lg-ink: #0f172a; --lg-mute: #64748b; --lg-faint: #94a3b8; --lg-navy: #1c3f66; }
.fx-modal-title { font-weight: 800; }
.fx-modal-help { font-size: 0.82rem; color: var(--lg-mute); margin-bottom: 10px; }
.fx-modal-foot { display: flex; justify-content: flex-end; gap: 8px; }
.fx-lbl { display: block; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); margin-bottom: 4px; }
.fx-input { width: 100%; padding: 0.55rem 0.75rem; border: 1px solid var(--lg-line); border-radius: 0.8rem; background: var(--lg-card); color: var(--lg-ink); font-size: 0.88rem; }
.fx-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18); }
.fx-vlist { list-style: none; margin: 12px 0; padding: 0; max-height: 320px; overflow-y: auto; border: 1px solid var(--lg-line); border-radius: 14px; }
.fx-vlist li { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 14px; border-bottom: 1px solid var(--lg-line); }
.fx-vlist li:last-child { border-bottom: 0; }
.fx-create-link { display: inline-flex; }

/* phone layout */
.fx-mobile { display: none; }
@media (max-width: 767.98px) {
  .fx-desktop { display: none; }
  .fx-mobile { display: block; }
  .fx-mcat { margin-bottom: 14px; }
  .fx-mcat-head { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 12px; background: rgba(59, 130, 246, 0.09); color: var(--lg-navy); }
  .fx-mcat-tot { margin-left: auto; font-variant-numeric: tabular-nums; font-weight: 800; }
  .fx-mitem { margin-top: 8px; padding: 10px 12px; border-radius: 14px; border: 1px solid var(--lg-line); background: var(--lg-soft); }
  .fx-mitem-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
  .fx-mamts { display: flex; flex-wrap: wrap; gap: 4px 16px; margin: 6px 0; font-size: 0.74rem; color: var(--lg-faint); font-variant-numeric: tabular-nums; }
  .fx-mamts b { color: var(--lg-ink); }
  .fx-mline { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 5px 0; border-top: 1px dashed var(--lg-line); }
  .fx-mline-amt { margin-left: auto; font-variant-numeric: tabular-nums; }
  .fx-mline-meta { flex-basis: 100%; max-width: none; margin-left: 0; }
  .fx-mvoucher { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
  .fx-mgrand { display: flex; gap: 14px; justify-content: flex-end; padding: 10px 12px; border-radius: 12px; background: linear-gradient(145deg, #1c3f66, #0f2540); color: #fff; font-variant-numeric: tabular-nums; }
  .fx-mgrand span:first-child { margin-right: auto; font-weight: 800; }
  .fx-people li span { width: 100%; }
}
</style>

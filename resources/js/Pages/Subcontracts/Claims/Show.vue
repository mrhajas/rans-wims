<template>
  <admin-layout :title="claim.reference">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="collection" :title="claim.reference" :subtitle="claim.contact + (claim.project ? ' - ' + claim.project : '')">
        <Link :href="route('subcontracts.claims.index')" class="lg-btn lg-btn-soft"><icons name="list" class="w-4 h-4" /><span>{{ $t('Contract Payment Requests') }}</span></Link>
        <Link v-if="claim.contact_url" :href="claim.contact_url" class="lg-btn lg-btn-soft"><icons name="users" class="w-4 h-4" /><span>{{ claim.contact }}</span></Link>
        <Link v-if="can.edit" :href="route('subcontracts.claims.edit', claim.id)" class="lg-btn lg-btn-soft"><icons name="edit" class="w-4 h-4" /><span>{{ $t('Edit') }}</span></Link>
        <a v-if="can.print && money" :href="route('subcontracts.claims.print', claim.id)" target="_blank" rel="noopener" class="lg-btn t-slate"><icons name="printer" class="w-4 h-4" /><span>{{ $t('Print') }}</span></a>
        <a v-if="can.print && money" :href="route('subcontracts.claims.pdf', claim.id)" class="lg-btn t-teal"><icons name="download" class="w-4 h-4" /><span>PDF</span></a>
      </page-header>

      <div v-if="errorList.length" class="lg-note lg-note-red" role="alert" style="margin-bottom: 10px"><icons name="exclamation" class="w-4 h-4" /><ul class="cl-errlist"><li v-for="(e, i) in errorList" :key="i">{{ e }}</li></ul></div>

      <section class="lg-card">
        <div class="ac-grid-2" style="align-items: center">
          <div>
            <span class="lg-chip" :class="chip(claim.status)">{{ claim.status_label }}</span>
            <span v-if="claim.progress && claim.progress !== 'Unpaid'" class="lg-chip" :class="progressChip(claim.progress)">{{ $t(claim.progress) }}</span>
            <div v-if="money" class="ac-cur-total" style="margin-top: 6px">{{ mc(claim.requested, claim.currency) }} <small class="lg-subtext">{{ $t('requested') }}<template v-if="claim.approved !== null && Number(claim.approved) > 0"> · {{ $t('approved') }} {{ mc(claim.approved, claim.currency) }}</template></small></div>
          </div>
          <div class="ac-actions" style="justify-content: flex-end; margin: 0">
            <button v-if="can.submit" type="button" class="lg-btn lg-btn-primary" :disabled="busy" @click="post('submit')"><icons name="send" class="w-4 h-4" />{{ $t('Submit for decision') }}</button>
            <button v-if="can.review && claim.status === 'Submitted'" type="button" class="lg-btn lg-btn-soft t-teal" :disabled="busy" @click="post('review')"><icons name="eye-flat" class="w-4 h-4" />{{ $t('Take into review') }}</button>
            <button v-if="canReviewMode" type="button" class="lg-btn lg-btn-green" @click="startReview"><icons name="check-double" class="w-4 h-4" />{{ reviewing ? $t('Review mode is on') : $t('Review and decide') }}</button>
            <button v-if="can.voucher" type="button" class="lg-btn lg-btn-primary" @click="openVoucher"><icons name="plus" class="w-4 h-4" />{{ $t('Start Payment Voucher') }}</button>
            <button v-if="can.cancel" type="button" class="lg-btn lg-btn-soft t-slate" @click="asking = asking === 'cancel' ? '' : 'cancel'; reason = ''"><icons name="x-circle" class="w-4 h-4" />{{ $t('Cancel request') }}</button>
            <button v-if="can.delete" type="button" class="lg-btn lg-btn-soft t-red" @click="remove"><icons name="trash" class="w-4 h-4" />{{ $t('Delete draft') }}</button>
          </div>
        </div>
        <form v-if="asking === 'cancel'" class="ac-reason" @submit.prevent="cancelClaim"><input v-model="reason" :placeholder="$t('Why is it cancelled? (required)')" required /><button type="submit" class="lg-btn lg-btn-primary" :disabled="busy"><icons name="tick" class="w-4 h-4" />{{ $t('Confirm') }}</button><button type="button" class="lg-btn lg-btn-soft" @click="asking = ''"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></form>
        <div v-if="claim.description" class="lg-sub" style="margin-top: 8px"><b>{{ $t('Description') }}:</b> {{ claim.description }}</div>
        <div v-if="claim.status === 'Approved' || claim.status === 'Partially Approved'" class="lg-note lg-note-blue" style="margin-top: 10px"><icons name="info" class="w-4 h-4" /><span>{{ $t('Approved is not paid. Start a Payment Voucher below; the money counts as paid only when the voucher is authorised and marked paid.') }}</span></div>
        <div v-if="open_payment" class="lg-note lg-note-amber" style="margin-top: 10px"><icons name="clock" class="w-4 h-4" /><span>{{ $t('A payment of :n line(s) was started and waits for its Payment Voucher.', { n: open_payment.count }) }}<template v-if="money && open_payment.total_tzs">{{ ' ' }}{{ $t('Total') }}: <b class="amt-appr">{{ mc(open_payment.total_tzs, 'TZS') }}</b>.</template></span>
          <span class="cl-inline"><a v-if="can.cancel_payment" :href="open_payment.voucher_url" class="lg-btn lg-btn-soft t-green"><icons name="send" class="w-4 h-4" />{{ $t('Continue to the voucher') }}</a><button v-if="can.cancel_payment" type="button" class="lg-btn lg-btn-soft t-red" @click="cancelPayment"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel this payment') }}</button></span></div>
      </section>

      <section v-if="money" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-indigo"><icons name="cash" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('This request') }} ({{ claim.currency }})</h2></div></header>
        <div class="sc-figs">
          <div class="sc-fig"><small>{{ $t('Requested') }}</small><b class="amt-rev">{{ m(claim.requested) }}</b></div>
          <div class="sc-fig"><small>{{ $t('Approved') }}</small><b class="amt-appr">{{ m(claim.approved || '0.00') }}</b></div>
          <div class="sc-fig is-good"><small>{{ $t('Paid (vouchers paid)') }}</small><b class="amt-paid">{{ m(claim.paid || '0.00') }}</b></div>
          <div class="sc-fig"><small>{{ $t('In a voucher, not paid yet') }}</small><b class="amt-appr">{{ m(claim.in_process || '0.00') }}</b></div>
          <div class="sc-fig is-warn"><small>{{ $t('Left to pay') }}</small><b class="amt-out">{{ m(claim.left_to_pay || '0.00') }}</b></div>
          <div class="sc-fig"><small>{{ $t('Lines') }}</small><b>{{ claim.lines }}<template v-if="claim.pending"> <em class="lg-subtext">({{ claim.pending }} {{ $t('waiting') }})</em></template></b></div>
        </div>
      </section>

      <collapse-section>
        <template #head><span class="lg-card-ico lg-ico-teal"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Contract lines') }}</h2><p class="lg-card-sub">{{ reviewing ? $t('Review mode: choose what to do with each line, check the total, then confirm all decisions together.') : $t('Each contract is decided on its own. A line approved here becomes an approved payment request of that agreement.') }}</p></div></template>

        <div v-if="reviewing" class="lg-note lg-note-blue" style="margin-bottom: 12px"><icons name="info" class="w-4 h-4" /><span>{{ $t('Nothing is saved until you press Confirm decisions. All the decisions are saved together, or none if one of them is refused.') }}</span></div>

        <article v-for="l in lines" :key="l.id" class="cl-line" :class="['st-' + l.status.toLowerCase(), { 'is-pick': reviewing && canPick(l), 'is-bad': lineError(l) }]">
          <header class="cl-line-head">
            <div class="min-w-0">
              <Link :href="l.agreement_url" class="lg-main cl-ref">{{ l.agreement }}</Link>
              <div class="lg-subtext">{{ l.title }}</div>
            </div>
            <div class="cl-line-chips"><span class="lg-chip" :class="chip(l.status)">{{ l.status_label }}</span><span v-if="l.override" class="lg-chip lg-chip-red">{{ $t('Override') }}</span><span v-if="l.agreement_status && l.agreement_status !== 'Completed'" class="lg-chip lg-chip-amber">{{ $t('Agreement') }}: {{ $t(l.agreement_status) }}</span></div>
          </header>

          <div v-if="money" class="cl-figs">
            <div><small>{{ $t('Requested') }}</small><b class="amt-rev">{{ mc(l.requested, claim.currency) }}</b></div>
            <div><small>{{ $t('Approved') }}</small><b class="amt-appr">{{ l.approved !== null ? mc(l.approved, claim.currency) : '-' }}</b></div>
            <div v-if="l.figures"><small>{{ $t('Revised contract value') }}</small><b class="amt-rev">{{ mc(l.figures.revised, claim.currency) }}</b></div>
            <div v-if="l.figures"><small>{{ $t('Previous payments') }}</small><b class="amt-paid">{{ mc(l.figures.paid_total, claim.currency) }}</b></div>
            <div v-if="l.snapshot"><small>{{ $t('Other pending when requested') }}</small><b class="amt-appr">{{ mc(l.snapshot.pending, claim.currency) }}</b></div>
            <div v-if="l.figures"><small>{{ $t('Approved, not paid') }}</small><b class="amt-appr">{{ mc(l.figures.approved_unpaid, claim.currency) }}</b></div>
            <div v-if="l.snapshot || l.figures"><small>{{ l.snapshot ? $t('Eligible when requested') : $t('Eligible balance now') }}</small><b :class="neg(l.snapshot ? l.snapshot.remaining_eligible : l.figures.remaining_eligible_signed) ? 'amt-neg' : 'amt-out'">{{ mc(l.snapshot ? l.snapshot.remaining_eligible : l.figures.remaining_eligible, claim.currency) }}</b></div>
            <div v-if="l.figures"><small>{{ $t('Still allowed by the contract') }}</small><b :class="neg(l.figures.headroom) ? 'amt-neg' : 'amt-out'">{{ mc(l.figures.headroom, claim.currency) }}</b></div>
          </div>

          <div class="cl-meta">
            <span><icons name="clip" class="w-4 h-4" /><Link :href="l.agreement_url + '?tab=documents'">{{ $t('Completion evidence') }}</Link></span>
            <span v-if="l.request"><icons name="send" class="w-4 h-4" /><Link :href="l.request_url">{{ l.request }}</Link> <small class="lg-subtext">({{ $t(l.request_status) }})</small></span>
            <span v-if="l.note" class="lg-subtext">{{ $t('Note') }}: {{ l.note }}</span>
          </div>

          <div v-if="l.decision_reason || (l.decided_by && l.status !== 'Pending')" class="cl-decision" :class="'is-' + l.status.toLowerCase()">
            <b>{{ l.status_label }}</b><template v-if="l.decided_by"> · {{ l.decided_by }}</template><template v-if="l.decided_at"> · {{ String(l.decided_at).slice(0, 16) }}</template><template v-if="l.decision_reason">: {{ l.decision_reason }}</template>
            <div v-if="l.override_reason" class="cl-ovr">{{ $t('Override') }}: {{ l.override_reason }}</div>
          </div>
          <ul v-if="l.history && l.history.length" class="cl-hist"><li v-for="(h, i) in l.history" :key="i"><b>{{ $t(h.status) }}</b><template v-if="h.at"> · {{ String(h.at).slice(0, 16) }}</template><template v-if="money && h.requested"> · {{ $t('asked') }} {{ mc(h.requested, claim.currency) }}</template><template v-if="h.reason">: {{ h.reason }}</template></li></ul>

          <div v-if="l.payment && l.status === 'Approved'" class="cl-pay">
            <div class="cl-pay-top"><span class="lg-chip" :class="progressChip(l.payment.progress)">{{ $t(l.payment.progress) }}</span>
              <span v-if="money" class="cl-pay-nums">{{ $t('Paid') }} <b class="amt-paid">{{ mc(l.payment.paid, claim.currency) }}</b> {{ $t('of') }} <b class="amt-appr">{{ mc(l.payment.approved, claim.currency) }}</b> · {{ $t('left') }} <b class="amt-out">{{ mc(l.payment.left, claim.currency) }}</b></span></div>
            <div class="sc-bar-track"><i :style="{ width: pct(l.payment) + '%' }"></i></div>
            <ul v-if="liveVouchers(l).length" class="cl-vlist"><li v-for="v in liveVouchers(l)" :key="v.payment_id"><Link v-if="v.voucher_id && can.vouchers" :href="route('payment-vouchers.show', v.voucher_id)" class="lg-main">{{ v.voucher }}</Link><b v-else>{{ v.voucher || '-' }}</b> <span class="lg-chip" :class="chip(v.voucher_status)">{{ $t(v.voucher_status) }}</span><template v-if="money && v.amount"> <span class="amt-appr">{{ mc(v.amount, claim.currency) }}</span></template><template v-if="v.paid_at"> <small class="lg-subtext">{{ $date(v.paid_at) }}</small></template></li></ul>
          </div>

          <!-- review mode: one decision per line, confirmed together -->
          <div v-if="reviewing && canPick(l)" class="cl-pick">
            <div class="cl-seg" role="radiogroup" :aria-label="$t('Decision for :r', { r: l.agreement })">
              <button v-if="l.can.approve" type="button" role="radio" class="cl-opt o-approve" :class="{ 'is-on': rv[l.id].action === 'approve' }" :aria-checked="rv[l.id].action === 'approve'" @click="choose(l, 'approve')"><icons name="tick" class="w-4 h-4" />{{ $t('Approve full') }}</button>
              <button v-if="l.can.approve && money && claimSettings.allow_partial" type="button" role="radio" class="cl-opt o-partial" :class="{ 'is-on': rv[l.id].action === 'partial' }" :aria-checked="rv[l.id].action === 'partial'" @click="choose(l, 'partial')"><icons name="check-double" class="w-4 h-4" />{{ $t('Approve partial') }}</button>
              <button v-if="l.can.reject" type="button" role="radio" class="cl-opt o-reject" :class="{ 'is-on': rv[l.id].action === 'reject' }" :aria-checked="rv[l.id].action === 'reject'" @click="choose(l, 'reject')"><icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}</button>
              <button v-if="l.can.return" type="button" role="radio" class="cl-opt o-return" :class="{ 'is-on': rv[l.id].action === 'return' }" :aria-checked="rv[l.id].action === 'return'" @click="choose(l, 'return')"><icons name="refresh" class="w-4 h-4" />{{ $t('Return') }}</button>
              <button v-if="l.can.defer" type="button" role="radio" class="cl-opt o-defer" :class="{ 'is-on': rv[l.id].action === 'defer' }" :aria-checked="rv[l.id].action === 'defer'" @click="choose(l, 'defer')"><icons name="pause" class="w-4 h-4" />{{ $t('Defer') }}</button>
              <button v-if="rv[l.id].action" type="button" class="cl-opt o-none" @click="choose(l, '')"><icons name="x-circle" class="w-4 h-4" />{{ $t('Leave it') }}</button>
            </div>
            <div v-if="rv[l.id].action" class="cl-pick-fields">
              <div v-if="rv[l.id].action === 'partial'" class="ac-f"><label>{{ $t('Amount to approve') }} ({{ claim.currency }})</label><input v-model="rv[l.id].amount" inputmode="decimal" /></div>
              <div v-if="['reject', 'return', 'defer'].includes(rv[l.id].action)" class="ac-f cl-wide"><label>{{ reasonLabel(rv[l.id].action) }}</label><input v-model="rv[l.id].reason" :placeholder="$t('Required')" /></div>
              <div v-else class="ac-f cl-wide"><label>{{ $t('Remarks') }} <small class="lg-subtext">({{ $t('optional') }})</small></label><input v-model="rv[l.id].reason" /></div>
              <div v-if="over(l, rvAmount(l))" class="ac-f cl-wide"><label>{{ $t('Override reason') }}</label><input v-model="rv[l.id].override" :placeholder="$t('Required: this is above what the contract still allows')" :disabled="!can.override" /><small class="lg-subtext">{{ can.override ? $t('An override is recorded in the audit log and alerts the administrators.') : $t('You cannot give an override, so this amount cannot be approved.') }}</small></div>
            </div>
            <div v-if="rvError(l)" class="cl-err">{{ rvError(l) }}</div>
          </div>
          <div v-if="lineError(l)" class="cl-err" role="alert">{{ lineError(l) }}</div>

          <!-- one line at a time -->
          <div v-if="!reviewing && hasActions(l)" class="cl-acts">
            <button v-if="l.can.approve" type="button" class="lg-btn lg-btn-green" :disabled="busy" @click="approveFull(l)"><icons name="tick" class="w-4 h-4" />{{ $t('Approve full') }}</button>
            <button v-if="l.can.approve && money && claimSettings.allow_partial" type="button" class="lg-btn lg-btn-soft t-green" @click="mode(l, 'partial')"><icons name="check-double" class="w-4 h-4" />{{ $t('Approve partial') }}</button>
            <button v-if="l.can.reject" type="button" class="lg-btn lg-btn-soft t-red" @click="mode(l, 'reject')"><icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}</button>
            <button v-if="l.can.return" type="button" class="lg-btn lg-btn-soft t-violet" @click="mode(l, 'return')"><icons name="refresh" class="w-4 h-4" />{{ $t('Return') }}</button>
            <button v-if="l.can.defer" type="button" class="lg-btn lg-btn-soft t-amber" @click="mode(l, 'defer')"><icons name="pause" class="w-4 h-4" />{{ $t('Defer') }}</button>
            <button v-if="l.can.resubmit" type="button" class="lg-btn lg-btn-primary" @click="mode(l, 'resubmit')"><icons name="send" class="w-4 h-4" />{{ $t('Correct and send again') }}</button>
            <button v-if="l.can.reverse && !liveVouchers(l).length" type="button" class="lg-btn lg-btn-soft t-maroon" @click="mode(l, 'reverse')"><icons name="refresh" class="w-4 h-4" />{{ $t('Take the approval back') }}</button>
            <button v-if="l.can.cancel" type="button" class="lg-btn lg-btn-soft t-slate" @click="mode(l, 'cancel')"><icons name="x-circle" class="w-4 h-4" />{{ $t('Cancel line') }}</button>
          </div>
          <form v-if="!reviewing && ui[l.id].mode" class="sc-approve cl-form" @submit.prevent="runLine(l)">
            <div class="ac-filters">
              <div v-if="['partial', 'resubmit'].includes(ui[l.id].mode) && money" class="ac-f"><label>{{ ui[l.id].mode === 'partial' ? $t('Amount to approve') : $t('New amount requested') }} ({{ claim.currency }})</label><input v-model="ui[l.id].amount" inputmode="decimal" /></div>
              <div v-if="ui[l.id].mode === 'resubmit'" class="ac-f cl-wide"><label>{{ $t('Note') }}</label><input v-model="ui[l.id].reason" /></div>
              <div v-else class="ac-f cl-wide"><label>{{ modeLabel(ui[l.id].mode) }}</label><input v-model="ui[l.id].reason" :required="needsReason(ui[l.id].mode)" /></div>
              <div v-if="ui[l.id].mode === 'partial' && over(l, ui[l.id].amount)" class="ac-f cl-wide"><label>{{ $t('Override reason') }}</label><input v-model="ui[l.id].override" :placeholder="$t('Required: this is above what the contract still allows')" :disabled="!can.override" /><small class="lg-subtext">{{ can.override ? $t('An override is recorded in the audit log and alerts the administrators.') : $t('You cannot give an override, so this amount cannot be approved.') }}</small></div>
            </div>
            <div class="ac-actions"><button type="submit" class="lg-btn lg-btn-primary" :disabled="busy"><icons name="tick" class="w-4 h-4" />{{ $t('Confirm') }}</button><button type="button" class="lg-btn lg-btn-soft" @click="ui[l.id].mode = ''"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></div>
          </form>
          <div v-if="l.self_blocked && (l.status === 'Pending' || l.status === 'Deferred')" class="lg-subtext cl-self"><icons name="info" class="w-4 h-4" />{{ $t('You made or submitted this request, so someone else must decide it.') }}</div>
        </article>
        <p v-if="!lines.length" class="lg-sub">{{ $t('This request has no lines.') }}</p>
      </collapse-section>

      <collapse-section v-if="can.voucher || vouchers.length">
        <template #head><span class="lg-card-ico lg-ico-teal"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Payment Vouchers') }}</h2><p class="lg-card-sub">{{ $t('One voucher can pay several contracts. It keeps the amount of each contract, so a part payment is allowed and the rest can be paid by another voucher.') }}</p></div>
          <button v-if="can.voucher" type="button" class="lg-btn lg-btn-primary" @click="openVoucher"><icons name="plus" class="w-4 h-4" />{{ $t('Start Payment Voucher') }}</button></template>

        <form v-if="starting && can.voucher" ref="vform" class="sc-approve cl-vform" @submit.prevent="startVoucher">
          <p class="lg-sub" style="margin: 0 0 8px">{{ $t('Tick the approved contracts to pay now. The amount starts as what is left to pay on the line; you may pay less.') }}</p>
          <div v-for="l in payable" :key="l.id" class="cl-vrow" :class="{ 'is-on': vsel[l.id].on }">
            <label class="ac-check"><input v-model="vsel[l.id].on" type="checkbox" /><span><b>{{ l.agreement }}</b> <small class="lg-subtext">{{ l.title }}</small></span></label>
            <div v-if="money" class="ac-f"><label>{{ $t('Amount to pay now') }} ({{ claim.currency }})</label><input v-model="vsel[l.id].amount" :disabled="!vsel[l.id].on" inputmode="decimal" /><small class="lg-subtext">{{ $t('At most :l (left to pay).', { l: mc(l.payment.left, claim.currency) }) }}</small><span class="ac-err">{{ err('lines.' + l.id) }}</span></div>
          </div>
          <div class="cl-vsum"><span><b>{{ vCount }}</b> {{ $t('line(s) ticked') }}</span><span v-if="money">{{ $t('Total') }}: <b class="amt-appr">{{ mc(vTotal, claim.currency) }}</b></span></div>
          <div v-if="!currency_is_tzs" class="ac-filters"><div class="ac-f"><label>{{ $t('Exchange rate to TZS') }} <b class="wims-req">*</b></label><input v-model="vf.exchange_rate" inputmode="decimal" /><span class="ac-err">{{ err('exchange_rate') }}</span></div><div class="ac-f"><label>{{ $t('Rate date') }} <b class="wims-req">*</b></label><date-picker v-model="vf.rate_date" :clearable="false" /></div></div>
          <span class="ac-err">{{ err('lines') }}</span>
          <div class="ac-actions"><button type="submit" class="lg-btn lg-btn-primary" :disabled="busy || !vCount"><icons name="send" class="w-4 h-4" />{{ $t('Continue to the voucher') }}</button><button type="button" class="lg-btn lg-btn-soft" @click="starting = false"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></div>
        </form>

        <div v-for="v in vouchers" :key="v.id" class="cl-voucher">
          <div class="cl-voucher-head"><Link v-if="v.url" :href="v.url" class="lg-main">{{ v.pv_number || $t('Voucher') }}</Link><b v-else>{{ v.pv_number || '-' }}</b> <span class="lg-chip" :class="chip(v.status)">{{ $t(v.status) }}</span><small v-if="v.paid_at" class="lg-subtext">{{ $t('Paid on') }} {{ $date(v.paid_at) }}</small><span v-if="money && v.total_tzs" class="cl-voucher-total">{{ $t('Voucher total') }} <b class="amt-appr">{{ mc(v.total_tzs, 'TZS') }}</b></span></div>
          <div class="lg-table-wrap"><table class="lg-table lg-responsive cl-table"><thead><tr><th><span class="sc-th"><icons name="doc-text" class="w-4 h-4" />{{ $t('Contract') }}</span></th><th><span class="sc-th"><icons name="send" class="w-4 h-4" />{{ $t('Payment request') }}</span></th><th v-if="money" class="ac-num"><span class="sc-th amt-appr"><icons name="cash" class="w-4 h-4" />{{ $t('Allocated') }}</span></th><th v-if="money && !currency_is_tzs" class="ac-num"><span class="sc-th amt-appr"><icons name="cash" class="w-4 h-4" />{{ $t('In TZS') }}</span></th></tr></thead>
            <tbody><tr v-for="a in v.allocations" :key="a.payment_id"><td :data-label="$t('Contract')"><div class="cl-cell"><Link :href="route('subcontracts.agreements.show', a.agreement_id)" class="lg-main">{{ a.agreement }}</Link><div class="lg-subtext">{{ a.title }}</div></div></td><td :data-label="$t('Payment request')">{{ a.request || '-' }}</td><td v-if="money" :data-label="$t('Allocated')" class="ac-num"><span class="amt-appr">{{ mc(a.amount, a.currency) }}</span></td><td v-if="money && !currency_is_tzs" :data-label="$t('In TZS')" class="ac-num"><span class="amt-appr">{{ mc(a.amount_tzs, 'TZS') }}</span></td></tr></tbody></table></div>
        </div>
        <p v-if="!vouchers.length && !starting" class="lg-sub">{{ $t('No voucher has been started.') }}</p>
      </collapse-section>

      <collapse-section>
        <template #head><span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Details') }}</h2></div></template>
        <dl class="ac-kv"><div><dt>{{ $t('Subcontractor') }}</dt><dd><Link v-if="claim.contact_url" :href="claim.contact_url">{{ claim.contact }}</Link><template v-else>{{ claim.contact }}</template></dd></div><div><dt>{{ $t('Project(s) / Warehouse(s)') }}</dt><dd>{{ claim.project || '-' }}</dd></div><div><dt>{{ $t('Request date') }}</dt><dd>{{ claim.date ? $date(claim.date) : '-' }}</dd></div><div><dt>{{ $t('Currency') }}</dt><dd>{{ claim.currency }}</dd></div><div><dt>{{ $t('Requested by') }}</dt><dd>{{ claim.by || '-' }}</dd></div><div v-if="claim.submitted_at"><dt>{{ $t('Submitted') }}</dt><dd>{{ claim.submitted_by || '-' }} · {{ String(claim.submitted_at).slice(0, 16) }}</dd></div><div v-if="claim.reviewed_at"><dt>{{ $t('Reviewed') }}</dt><dd>{{ claim.reviewed_by || '-' }} · {{ String(claim.reviewed_at).slice(0, 16) }}</dd></div></dl>
      </collapse-section>

      <collapse-section>
        <template #head><span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Supporting documents') }}</h2></div></template>
        <acct-file-list :files="attachments" :download-url="f => route('subcontracts.claims.download', [claim.id, f.id])" :removable="can.attach" @remove="removeFile" />
        <form v-if="can.attach" class="ac-attach" @submit.prevent="upload"><acct-dropzone v-model="up.attachments" :hint="$t('PDF, images and office files up to 8 MB each.')" /><div><button type="submit" class="lg-btn lg-btn-primary" :disabled="!up.attachments.length || up.processing"><icons name="upload" class="w-4 h-4" />{{ $t('Attach :n file(s)', { n: up.attachments.length }) }}</button></div></form>
      </collapse-section>

      <collapse-section v-if="history.length">
        <template #head><span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('History') }}</h2></div></template>
        <ul class="sc-list"><li v-for="(h, i) in history" :key="i"><b>{{ h.description }}</b> <small>{{ h.causer }} · {{ String(h.created_at).slice(0, 16) }}</small></li></ul>
      </collapse-section>

      <!-- review mode: running total and ONE confirm button -->
      <div v-if="reviewing" class="lg-actionbar cl-bar">
        <div class="cl-sum">
          <div class="cl-sum-main">
            <span v-for="o in outcomes" :key="o.key" class="cl-count" :class="'o-' + o.key"><b>{{ o.n }}</b> {{ o.label }}</span>
            <span class="cl-count o-none"><b>{{ counts.none }}</b> {{ $t('undecided') }}</span>
          </div>
          <div v-if="money" class="cl-total">{{ $t('Total approved by this decision') }}: <b class="amt-appr">{{ mc(rvTotalText, claim.currency) }}</b> <small class="lg-subtext">{{ $t('of :r requested on the lines being decided', { r: mc(rvRequestedText, claim.currency) }) }}</small></div>
        </div>
        <div class="lg-actionbar-btns"><button type="button" class="lg-btn t-slate" :disabled="busy" @click="stopReview"><icons name="cross" class="w-4 h-4" />{{ $t('Stop reviewing') }}</button>
          <button type="button" class="lg-btn lg-btn-green" :disabled="busy || !chosen.length || rvHasErrors" @click="confirmDecisions"><icons name="tick" class="w-4 h-4" />{{ $t('Confirm decisions') }} ({{ chosen.length }})</button></div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctDropzone from '@/Pages/Accounts/AcctDropzone.vue';
import AcctFileList from '@/Pages/Accounts/AcctFileList.vue';
import CollapseSection from '../CollapseSection.vue';
import { sc } from '../sc';
import { toCents, fromCents, ZERO } from './money';

export default {
  components: { CollapseSection, AdminLayout, PageHeader, Link, DatePicker, AcctDropzone, AcctFileList },
  mixins: [sc],
  props: { claim: Object, lines: Array, vouchers: Array, open_payment: Object, attachments: Array, history: Array, money: Boolean, claimSettings: Object, currency_is_tzs: Boolean, today: String, actions: Array, can: Object },
  data() {
    const ui = {}; const rv = {}; const vsel = {};
    this.lines.forEach(l => {
      ui[l.id] = { mode: '', amount: l.requested || '', reason: '', override: '' };
      rv[l.id] = { action: '', amount: l.requested || '', reason: '', override: '' };
      vsel[l.id] = { on: true, amount: l.payment ? l.payment.left : '' };
    });
    return { ui, rv, vsel, reviewing: false, starting: false, busy: false, asking: '', reason: '', vf: this.$inertia.form({ exchange_rate: '', rate_date: this.today }), up: this.$inertia.form({ attachments: [] }) };
  },
  watch: {
    // the page was reloaded after an action: the payment figures and the line fields start again from the new numbers
    lines() {
      this.lines.forEach(l => {
        this.ui[l.id] = { mode: '', amount: l.requested || '', reason: '', override: '' };
        this.vsel[l.id] = { on: true, amount: l.payment ? l.payment.left : '' };
        if (!this.reviewing || !this.rv[l.id]) this.rv[l.id] = { action: '', amount: l.requested || '', reason: '', override: '' };
      });
    },
  },
  computed: {
    canReviewMode() { return this.can.decide && this.lines.some(l => this.canPick(l)); },
    payable() { return this.lines.filter(l => l.can.pay); },
    // errors of the page that are not tied to one line (those show under the line)
    errorList() { const e = this.$page.props.errors || {}; return Object.keys(e).filter(k => !k.startsWith('line_') && !k.startsWith('exchange_rate') && !(k.startsWith('lines.') && this.starting)).map(k => e[k]).filter(Boolean); },
    chosen() { return this.lines.filter(l => this.rv[l.id] && this.rv[l.id].action); },
    rvHasErrors() { return this.chosen.some(l => !!this.rvError(l)); },
    counts() {
      const c = { approve: 0, partial: 0, reject: 0, return: 0, defer: 0, none: 0 };
      this.lines.filter(l => this.canPick(l)).forEach(l => { const a = this.rv[l.id].action; if (a) c[a]++; else c.none++; });
      return c;
    },
    outcomes() { return [['approve', this.$t('approved in full')], ['partial', this.$t('approved in part')], ['reject', this.$t('rejected')], ['return', this.$t('returned')], ['defer', this.$t('deferred')]].map(([key, label]) => ({ key, label, n: this.counts[key] })); },
    rvTotal() { return this.chosen.filter(l => ['approve', 'partial'].includes(this.rv[l.id].action)).reduce((t, l) => { const c = toCents(this.rvAmount(l)); return c === null ? t : t + c; }, ZERO); },
    rvTotalText() { return fromCents(this.rvTotal); },
    rvRequestedText() { return fromCents(this.chosen.reduce((t, l) => { const c = toCents(l.requested); return c === null ? t : t + c; }, ZERO)); },
    vCount() { return this.payable.filter(l => this.vsel[l.id].on).length; },
    vTotal() { return fromCents(this.payable.filter(l => this.vsel[l.id].on).reduce((t, l) => { const c = toCents(this.vsel[l.id].amount); return c === null ? t : t + c; }, ZERO)); },
  },
  methods: {
    liveVouchers(l) { return (l.vouchers || []).filter(v => !v.cancelled); },
    progressChip(p) { return { Paid: 'lg-chip-green', 'Partly Paid': 'lg-chip-amber', Unpaid: 'lg-chip-slate' }[p] || 'lg-chip-slate'; },
    pct(p) { const a = Number(p.approved) || 0; return a > 0 ? Math.max(0, Math.min(100, Math.round((Number(p.paid) || 0) / a * 100))) : 0; },
    canPick(l) { return (l.can.approve || l.can.reject || l.can.return || l.can.defer) && ['Pending', 'Deferred'].includes(l.status); },
    hasActions(l) { const c = l.can; return !!(c.approve || c.reject || c.return || c.defer || c.resubmit || c.cancel || (c.reverse && !this.liveVouchers(l).length)); },
    lineError(l) { return this.err('line_' + l.id) || ''; },
    // an amount above what the contract still allows needs an override with a reason
    over(l, amount) { if (!this.money || !l.figures) return false; const a = toCents(amount); const h = toCents(l.figures.headroom); return a !== null && h !== null && a > h; },
    reasonLabel(a) { return { reject: this.$t('Why is it rejected?'), return: this.$t('What must be corrected?'), defer: this.$t('Why is the decision deferred?') }[a] || ''; },
    modeLabel(m) { return { partial: this.$t('Remarks (optional)'), reject: this.$t('Why is it rejected? (required)'), return: this.$t('What must be corrected? (required)'), defer: this.$t('Why is the decision deferred? (required)'), cancel: this.$t('Why is the line cancelled? (required)'), reverse: this.$t('Why is the approval taken back? (required)') }[m] || this.$t('Remarks'); },
    needsReason(m) { return ['reject', 'return', 'defer', 'cancel', 'reverse'].includes(m); },
    mode(l, m) { const u = this.ui[l.id]; u.mode = u.mode === m ? '' : m; u.reason = ''; u.override = ''; u.amount = l.requested || ''; },
    // ---- review mode ----
    startReview() { this.reviewing = true; this.$nextTick(() => window.scrollTo({ top: 0, behavior: 'smooth' })); },
    stopReview() { this.reviewing = false; },
    choose(l, a) { const r = this.rv[l.id]; r.action = a; if (a === 'approve') r.amount = l.requested; if (a === 'partial' && r.amount === l.requested) r.amount = ''; },
    rvAmount(l) { const r = this.rv[l.id]; return r.action === 'partial' ? r.amount : l.requested; },
    rvError(l) {
      const r = this.rv[l.id]; if (!r || !r.action) return '';
      if (['reject', 'return', 'defer'].includes(r.action) && !String(r.reason).trim()) return this.reasonLabel(r.action);
      if (['approve', 'partial'].includes(r.action) && this.money) {
        const a = toCents(this.rvAmount(l)); const q = toCents(l.requested);
        if (a === null || a <= ZERO) return this.$t('The approved amount must be above zero.');
        if (q !== null && a > q) return this.$t('The approved amount cannot be above the amount requested (:a).', { a: this.mc(l.requested, this.claim.currency) });
        if (r.action === 'partial' && q !== null && a >= q) return this.$t('A partial approval is less than the amount requested. Use Approve full instead.');
        if (this.over(l, this.rvAmount(l))) { if (!this.can.override) return this.$t('Above what the contract still allows, and you cannot give an override.'); if (!String(r.override).trim()) return this.$t('Give the override reason.'); }
      }
      return '';
    },
    confirmDecisions() {
      const d = this.chosen.map(l => { const r = this.rv[l.id]; const ap = ['approve', 'partial'].includes(r.action); return { line_id: l.id, action: ap ? 'approve' : r.action, ...(ap && this.money && r.action === 'partial' ? { amount: r.amount } : {}), reason: r.reason || '', ...(ap && this.over(l, this.rvAmount(l)) ? { override_reason: r.override } : {}) }; });
      const msg = this.money ? this.$t('Confirm :n decision(s)? Total approved: :t.', { n: d.length, t: this.mc(this.rvTotalText, this.claim.currency) }) : this.$t('Confirm :n decision(s)?', { n: d.length });
      this.$confirm(msg).then(ok => { if (!ok) return; this.busy = true; this.$inertia.post(route('subcontracts.claims.decide', this.claim.id), { decisions: d }, { preserveScroll: true, onSuccess: () => { this.reviewing = false; }, onFinish: () => (this.busy = false) }); });
    },
    // ---- one line ----
    approveFull(l) { this.lineCall('subcontracts.claims.lines.decide', [this.claim.id, l.id, 'approve'], {}, l); },
    runLine(l) {
      const u = this.ui[l.id]; const t = u.mode;
      if (t === 'partial') return this.lineCall('subcontracts.claims.lines.decide', [this.claim.id, l.id, 'approve'], { amount: u.amount, reason: u.reason, ...(this.over(l, u.amount) ? { override_reason: u.override } : {}) }, l);
      if (['reject', 'return', 'defer'].includes(t)) return this.lineCall('subcontracts.claims.lines.decide', [this.claim.id, l.id, t], { reason: u.reason }, l);
      if (t === 'resubmit') return this.lineCall('subcontracts.claims.lines.resubmit', [this.claim.id, l.id], { amount: this.money ? u.amount : null, note: u.reason }, l);
      if (t === 'cancel') return this.lineCall('subcontracts.claims.lines.cancel', [this.claim.id, l.id], { reason: u.reason }, l);
      if (t === 'reverse') return this.lineCall('subcontracts.claims.lines.reverse', [this.claim.id, l.id], { reason: u.reason }, l);
    },
    lineCall(name, params, data, l) { this.busy = true; this.$inertia.post(route(name, params), data, { preserveScroll: true, onSuccess: () => { this.ui[l.id].mode = ''; }, onFinish: () => (this.busy = false) }); },
    // ---- the request ----
    post(action, extra) { this.busy = true; this.$inertia.post(route('subcontracts.claims.' + action, this.claim.id), extra || {}, { preserveScroll: true, onFinish: () => (this.busy = false) }); },
    cancelClaim() { this.asking = ''; this.post('cancel', { reason: this.reason }); },
    remove() { this.$confirm(this.$t('Delete this draft request?')).then(ok => { if (ok) this.$inertia.delete(route('subcontracts.claims.destroy', this.claim.id)); }); },
    // ---- paying ----
    openVoucher() { this.starting = true; this.$nextTick(() => { const el = this.$refs.vform; if (el && el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'center' }); }); },
    startVoucher() {
      const lines = {}; this.payable.forEach(l => { if (this.vsel[l.id].on) lines[l.id] = this.money ? this.vsel[l.id].amount : ''; });
      this.busy = true;
      this.$inertia.post(route('subcontracts.claims.voucher', this.claim.id), { lines, exchange_rate: this.currency_is_tzs ? null : this.vf.exchange_rate, rate_date: this.currency_is_tzs ? null : this.vf.rate_date }, { preserveScroll: true, onFinish: () => (this.busy = false) });
    },
    cancelPayment() { this.$confirm(this.$t('Cancel this started payment?')).then(ok => { if (ok) this.post('payments.cancel'); }); },
    upload() { this.up.post(route('subcontracts.claims.attach', this.claim.id), { forceFormData: true, preserveScroll: true, onSuccess: () => this.up.reset('attachments') }); },
    removeFile(f) { this.$inertia.delete(route('subcontracts.claims.remove-file', [this.claim.id, f.id]), { preserveScroll: true }); },
  },
};
</script>

<style scoped>
.sc-figs { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
.sc-fig { padding: 12px 14px; border-radius: 16px; background: var(--lg-card); box-shadow: 0 12px 20px -16px rgba(15, 37, 64, 0.55), 0 0 0 1px var(--lg-line); display: grid; gap: 2px; min-width: 0; }
.sc-fig small { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute); } .sc-fig b { font-size: 1.15rem; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.sc-approve { padding: 12px; border-radius: 16px; background: rgba(148, 163, 184, 0.08); box-shadow: inset 0 0 0 1px var(--lg-line); margin: 10px 0; }
</style>

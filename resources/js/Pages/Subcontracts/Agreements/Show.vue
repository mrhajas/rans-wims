<template>
  <admin-layout :title="a.reference">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header icon="doc-text" :title="a.reference" :subtitle="a.title + ' - ' + (a.contact || '')">
        <Link :href="route('subcontracts.agreements.index')" class="lg-btn lg-btn-soft"><icons name="arrow-left" class="w-4 h-4" /><span>{{ $t('Agreements') }}</span></Link>
        <Link v-if="can.edit" :href="route('subcontracts.agreements.edit', a.id)" class="lg-btn lg-btn-soft"><icons name="edit" class="w-4 h-4" /><span>{{ $t('Edit') }}</span></Link>
        <Link v-if="can.request" :href="route('subcontracts.requests.create', { agreement: a.id })" class="lg-btn lg-btn-primary"><icons name="send" class="w-4 h-4" /><span>{{ $t('New payment request') }}</span></Link>
        <Link v-if="can.claim" :href="route('subcontracts.claims.create', { contact_id: a.contact_id })" class="lg-btn lg-btn-soft t-green"><icons name="collection" class="w-4 h-4" /><span>{{ $t('Request payment (contract)') }}</span></Link>
        <a v-if="can.print && money" :href="route('subcontracts.reports.print', { type: 'agreement_detail', agreement_id: a.id })" target="_blank" rel="noopener" class="lg-btn t-slate"><icons name="printer" class="w-4 h-4" /><span>{{ $t('Print') }}</span></a>
        <a v-if="can.print && money" :href="route('subcontracts.reports.pdf', { type: 'agreement_detail', agreement_id: a.id })" class="lg-btn t-teal"><icons name="download" class="w-4 h-4" /><span>{{ $t('Download') }}</span></a>
        <Link v-if="can.opening" :href="route('subcontracts.opening.edit', a.id)" class="lg-btn t-amber"><icons name="folder" class="w-4 h-4" /><span>{{ $t('Opening Balance') }}</span></Link>
        <Link v-if="can.statement && money" :href="route('subcontracts.statements.agreement', a.id)" class="lg-btn t-violet"><icons name="table" class="w-4 h-4" /><span>{{ $t('Statement') }}</span></Link>
      </page-header>

      <section class="lg-card">
        <div class="ac-grid-2" style="align-items: center">
          <div>
            <span class="lg-chip" :class="chip(a.status)">{{ a.status_label }}</span> <span class="lg-chip lg-chip-slate" style="text-transform: none">{{ a.agreement_type_label }}</span> <span v-if="a.source === 'import'" class="lg-chip lg-chip-violet" style="text-transform: none">{{ $t('Imported') }}</span>
            <div v-if="figures" class="ac-cur-total" style="margin-top: 6px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap"><progress-ring v-if="Number(figures.revised) > 0" :value="Math.round(Number(figures.paid_total) / Number(figures.revised) * 100)" :size="54" /><span>{{ mc(figures.revised, a.currency) }} <small class="lg-subtext">{{ $t('revised contract value') }}</small></span></div>
          </div>
          <div class="ac-actions" style="justify-content: flex-end; margin: 0">
            <button v-if="can.submit" type="button" class="lg-btn lg-btn-primary" @click="doit('submit')"><icons name="send" class="w-4 h-4" />{{ $t('Submit for review') }}</button>
            <button v-if="can.review" type="button" class="lg-btn lg-btn-soft" @click="doit('review')"><icons name="eye-flat" class="w-4 h-4" />{{ $t('Take into review') }}</button>
            <button v-if="can.approve" type="button" class="lg-btn lg-btn-green" @click="doit('approve')"><icons name="tick" class="w-4 h-4" />{{ $t('Approve') }}</button>
            <button v-if="can.reject" type="button" class="lg-btn lg-btn-red" @click="ask('reject', $t('Why is it rejected? (required)'))"><icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}</button>
            <button v-if="can.manage && ['Approved', 'Suspended'].includes(a.status)" type="button" class="lg-btn lg-btn-green" @click="doit('activate')"><icons name="play" class="w-4 h-4" />{{ $t('Make active') }}</button>
            <button v-if="can.manage && ['Approved', 'Active'].includes(a.status)" type="button" class="lg-btn t-amber" @click="ask('suspend', $t('Why is it suspended?'))"><icons name="pause" class="w-4 h-4" />{{ $t('Suspend') }}</button>
            <button v-if="can.manage && ['Approved', 'Active'].includes(a.status)" type="button" class="lg-btn t-green" @click="openComp('complete')"><icons name="check-double" class="w-4 h-4" />{{ $t('Complete') }}</button>
            <button v-if="can.manage && ['Approved', 'Active', 'Suspended'].includes(a.status)" type="button" class="lg-btn lg-btn-red" @click="ask('terminate', $t('Why is it terminated? (required)'))"><icons name="x-circle" class="w-4 h-4" />{{ $t('Terminate') }}</button>
            <button v-if="can.archive" type="button" class="lg-btn t-maroon lg-tip" :data-tip="$t('Move to the archive: read-only, not paid, left out of the totals. Its balance stays visible.')" @click="ask('archive', $t('Why is it archived? (optional)'))"><icons name="folder" class="w-4 h-4" />{{ $t('Archive') }}</button>
            <button v-if="can.restore" type="button" class="lg-btn lg-btn-primary" @click="doit('restore')"><icons name="refresh" class="w-4 h-4" />{{ $t('Restore from the archive') }}</button>
            <button v-if="can.delete" type="button" class="lg-btn t-red" @click="remove"><icons name="trash" class="w-4 h-4" />{{ $t('Delete draft') }}</button>
          </div>
        </div>
        <form v-if="asking" class="ac-reason" @submit.prevent="send"><input v-model="reason" :placeholder="askLabel" /><button type="submit" class="lg-btn lg-btn-primary" :disabled="busy"><icons name="tick" class="w-4 h-4" />{{ $t('Confirm') }}</button><button type="button" class="lg-btn t-slate" @click="asking = ''"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button></form>
        <div v-if="a.decision_remarks" class="lg-note lg-note-amber" style="margin-top: 10px">{{ a.decision_remarks }}</div>
        <div v-if="a.status === 'Archived'" class="lg-note lg-note-amber" style="margin-top: 10px"><icons name="folder" class="w-4 h-4" /><span>{{ $t('This account is archived: it is kept for reference (an older version or a finished account). It is read-only, cannot be paid, and is not part of the totals on the dashboard and the register. Its own balance is shown below.') }}<template v-if="a.archived_note">{{ ' ' + a.archived_note }}</template></span></div>
        <div v-if="a.status === 'Draft'" class="lg-note lg-note-blue" style="margin-top: 10px"><icons name="info" class="w-4 h-4" /><span>{{ $t('A draft is not a payable commitment. Complete the work schedule, then submit it.') }}</span></div>
      </section>

      <sc-tabs :tabs="tabs.map(t => ({ ...t, count: t.n }))" v-model="tab" />

      <template v-if="tab === 'overview'">
        <collapse-section v-if="figures">
          <template #head><span class="lg-card-ico lg-ico-indigo"><icons name="cash" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Contract and payments') }} ({{ a.currency }})</h2></div></template>
          <div class="sc-figs">
            <div class="sc-fig is-link" role="link" tabindex="0" @click="tab = 'schedule'" @keydown.enter="tab = 'schedule'"><small>{{ $t('Original contract value') }}</small><b>{{ m(figures.original) }}</b></div><div class="sc-fig is-link" role="link" tabindex="0" @click="tab = 'variations'" @keydown.enter="tab = 'variations'"><small>{{ $t('Approved increases') }}</small><b>{{ m(figures.variations_up) }}</b></div><div class="sc-fig is-link" role="link" tabindex="0" @click="tab = 'variations'" @keydown.enter="tab = 'variations'"><small>{{ $t('Approved reductions') }}</small><b>{{ m(figures.variations_down) }}</b></div>
            <div class="sc-fig is-link is-strong" role="link" tabindex="0" @click="tab = 'variations'" @keydown.enter="tab = 'variations'"><small>{{ $t('Revised contract value') }}</small><b>{{ m(figures.revised) }}</b></div><div class="sc-fig is-link" role="link" tabindex="0" @click="tab = 'schedule'" @keydown.enter="tab = 'schedule'"><small>{{ $t('Work schedule total') }}</small><b>{{ m(figures.scheduled) }}</b></div><div class="sc-fig is-link" role="link" tabindex="0" @click="tab = 'payments'" @keydown.enter="tab = 'payments'"><small>{{ $t('Approved work value') }}</small><b>{{ m(figures.approved_work) }}</b></div>
            <div class="sc-fig is-link" role="link" tabindex="0" @click="tab = 'payments'" @keydown.enter="tab = 'payments'"><small>{{ $t('Pending requests') }}</small><b>{{ m(figures.requested_pending) }}</b></div><div class="sc-fig is-link" role="link" tabindex="0" @click="tab = 'payments'" @keydown.enter="tab = 'payments'"><small>{{ $t('Approved, not yet paid') }}</small><b>{{ m(figures.approved_unpaid) }}</b></div><div class="sc-fig is-link" role="link" tabindex="0" @click="tab = 'payments'" @keydown.enter="tab = 'payments'"><small>{{ $t('In a voucher, not paid yet') }}</small><b>{{ m(figures.in_process) }}</b></div>
            <div class="sc-fig is-link is-good" role="link" tabindex="0" @click="tab = 'payments'" @keydown.enter="tab = 'payments'"><small>{{ $t('Actually paid') }}</small><b>{{ m(figures.paid_total) }}</b><em>{{ $t('vouchers') }} {{ m(figures.paid_live) }} + {{ $t('historical') }} {{ m(figures.paid_legacy) }}</em></div>
            <div class="sc-fig is-link is-warn" role="link" tabindex="0" @click="tab = 'payments'" @keydown.enter="tab = 'payments'"><small>{{ $t('Outstanding contract commitment') }}</small><b :class="{ 'ac-neg': neg(figures.commitment_left) }">{{ m(figures.commitment_left) }}</b></div>
            <div class="sc-fig is-link" role="link" tabindex="0" @click="tab = 'payments'" @keydown.enter="tab = 'payments'"><small>{{ $t('Still open for approval') }}</small><b :class="{ 'ac-neg': neg(figures.headroom) }">{{ m(figures.headroom) }}</b><em>{{ neg(figures.headroom) ? $t('Negative balance carried forward') : $t('revised value minus approved work') }}</em></div>
          </div>
          <div v-if="figures && schedDiff" class="lg-note lg-note-amber" style="margin-top: 10px"><icons name="info" class="w-4 h-4" /><span>{{ $t('The work schedule adds up to :s but the original amount is :o.', { s: m(figures.scheduled), o: m(figures.original) }) }}</span></div>
        </collapse-section>
        <collapse-section v-if="completion && showCompletion">
          <template #head><span class="lg-card-ico lg-ico-emerald"><icons name="check-double" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Completion') }}</h2><p class="lg-card-sub">{{ $t('Completing a contract and verifying it never pays anything. A completed contract can be put on a Contract Payment Request when its completion is verified.') }}</p></div></template>
          <div class="cl-comp">
            <div class="cl-comp-state">
              <div><span class="lg-chip" :class="completion.state === 'verified' ? 'lg-chip-green' : completion.state === 'awaiting_verification' ? 'lg-chip-amber' : 'lg-chip-slate'">{{ completion.state_label }}</span> <span v-if="completion.state === 'verified' || completion.state === 'awaiting_verification'" class="lg-chip" :class="completion.eligible ? 'lg-chip-green' : 'lg-chip-slate'">{{ completion.eligible ? $t('Can be requested for payment') : $t('Cannot be requested yet') }}</span></div>
              <div v-if="completion.blocked_message && completion.state !== 'not_completed'" class="lg-note lg-note-amber"><icons name="info" class="w-4 h-4" /><span>{{ completion.blocked_message }}</span></div>
              <div class="cl-facts">
                <div v-if="completion.state !== 'not_completed'" class="cl-fact"><small>{{ $t('Completion date') }}</small><b>{{ sd(completion.actual_completion) }}</b></div>
                <div v-if="completion.completed_by" class="cl-fact"><small>{{ $t('Completed by') }}</small><b>{{ completion.completed_by }}</b><em class="lg-subtext">{{ completion.completed_at ? String(completion.completed_at).slice(0, 16) : '' }}</em></div>
                <div v-if="completion.verified" class="cl-fact"><small>{{ $t('Verified by') }}</small><b>{{ completion.verified_by || '-' }}</b><em class="lg-subtext">{{ completion.verified_at ? String(completion.verified_at).slice(0, 16) : '' }}</em></div>
                <div v-if="completion.state !== 'not_completed'" class="cl-fact"><small>{{ $t('Evidence') }}</small><b><a v-if="can.documents" href="#" @click.prevent="tab = 'documents'">{{ completion.evidence_count }} {{ $t('document(s)') }}</a><template v-else>{{ completion.evidence_count }} {{ $t('document(s)') }}</template></b><em v-if="completion.requires_evidence" class="lg-subtext">{{ $t('Required before the completion is verified') }}</em></div>
                <div v-if="money && completion.remaining_eligible !== null" class="cl-fact"><small>{{ $t('Remaining eligible amount') }}</small><b :class="neg(completion.remaining_eligible) ? 'amt-neg' : 'amt-out'">{{ mc(completion.remaining_eligible, a.currency) }}</b></div>
              </div>
              <p v-if="completion.note" class="cl-note lg-sub"><b>{{ $t('Completion note') }}:</b> {{ completion.note }}</p>
              <p v-if="completion.verify_note" class="cl-note lg-sub"><b>{{ $t('Verification note') }}:</b> {{ completion.verify_note }}</p>
              <p class="lg-subtext">{{ completion.work_groups_note }}</p>
            </div>
            <div class="cl-comp-acts">
              <button v-if="can.complete" type="button" class="lg-btn lg-btn-green" @click="openComp('complete')"><icons name="check-double" class="w-4 h-4" />{{ $t('Mark completed') }}</button>
              <button v-if="can.verify_completion" type="button" class="lg-btn lg-btn-primary" @click="openComp('verify-completion')"><icons name="tick" class="w-4 h-4" />{{ $t('Verify completion') }}</button>
              <button v-if="can.unverify_completion" type="button" class="lg-btn lg-btn-soft t-amber" @click="openComp('unverify-completion')"><icons name="refresh" class="w-4 h-4" />{{ $t('Withdraw verification') }}</button>
              <Link v-if="can.claim && completion.eligible" :href="route('subcontracts.claims.create', { contact_id: a.contact_id })" class="lg-btn lg-btn-soft t-green"><icons name="collection" class="w-4 h-4" />{{ $t('Request payment (contract)') }}</Link>
              <p v-if="completion.verify_blocked_reason" class="lg-subtext" style="flex-basis: 100%">{{ completion.verify_blocked_reason }}</p>
            </div>
          </div>
        </collapse-section>
        <collapse-section>
          <template #head><span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Details') }}</h2></div></template>
          <div class="agd-grid">
            <section class="agd-box is-indigo">
              <h3><span><icons name="users" class="w-4 h-4" /></span>{{ $t('Parties and project') }}</h3>
              <div class="agd-row"><small>{{ $t('Subcontractor') }}</small><b><Link v-if="contactUrl" :href="contactUrl">{{ a.contact }}</Link><template v-else>{{ a.contact || '-' }}</template></b><em v-if="a.contact_phone">{{ a.contact_phone }}</em></div>
              <div class="agd-row"><small>{{ $t('Project(s) / Warehouse(s)') }}</small>
                <div v-if="projList.length" class="agd-chips"><span v-for="(p, pi) in projList" :key="pi" class="agd-chip"><icons name="building" class="w-3 h-3" />{{ p }}</span></div>
                <b v-else class="agd-none">-</b></div>
              <div v-if="a.site" class="agd-row"><small>{{ $t('Site / building / phase') }}</small><b>{{ a.site }}</b></div>
            </section>
            <section class="agd-box is-violet">
              <h3><span><icons name="calendar" class="w-4 h-4" /></span>{{ $t('Dates') }}</h3>
              <div class="agd-two">
                <div class="agd-row"><small>{{ $t('Agreement date') }}</small><b :class="{ 'agd-none': !a.agreement_date }">{{ sd(a.agreement_date) }}</b></div>
                <div class="agd-row"><small>{{ $t('Start date') }}</small><b :class="{ 'agd-none': !a.start_date }">{{ sd(a.start_date) }}</b></div>
                <div class="agd-row"><small>{{ $t('Expected completion') }}</small><b :class="{ 'agd-none': !a.expected_completion }">{{ sd(a.expected_completion) }}</b></div>
                <div class="agd-row"><small>{{ $t('Completed on') }}</small><b :class="{ 'agd-none': !a.actual_completion }">{{ sd(a.actual_completion) }}</b></div>
              </div>
              <div v-if="a.as_at_date" class="agd-asat"><icons name="clock" class="w-4 h-4" /><span>{{ $t('Position as at (workbook)') }}</span><b>{{ sd(a.as_at_date) }}</b></div>
            </section>
            <section class="agd-box is-teal">
              <h3><span><icons name="user" class="w-4 h-4" /></span>{{ $t('Administration') }}</h3>
              <div class="agd-two">
                <div class="agd-row"><small>{{ $t('Administrator') }}</small><b :class="{ 'agd-none': !a.administrator }">{{ a.administrator || '-' }}</b></div>
                <div class="agd-row"><small>{{ $t('Created by') }}</small><b>{{ a.created_by || '-' }}</b></div>
                <div v-if="Number(a.retention_percent) > 0" class="agd-row"><small>{{ $t('Retention') }}</small><b>{{ a.retention_percent }}%</b></div>
                <div v-if="a.vat_treatment" class="agd-row"><small>{{ $t('VAT / tax') }}</small><b>{{ a.vat_treatment }}</b></div>
              </div>
            </section>
            <section v-if="customDefs.length" class="agd-box is-amber">
              <h3><span><icons name="adjustments" class="w-4 h-4" /></span>{{ $t('More details') }}</h3>
              <dl class="ac-kv agd-custom"><custom-view :defs="customDefs" :values="custom || {}" /></dl>
            </section>
          </div>
          <div v-if="a.scope || a.payment_terms || a.advance_terms || a.remarks" class="agd-notes">
            <div v-if="a.scope" class="agd-note"><span><icons name="clip" class="w-4 h-4" />{{ $t('Scope of work') }}</span><p>{{ a.scope }}</p></div>
            <div v-if="a.payment_terms" class="agd-note"><span><icons name="cash" class="w-4 h-4" />{{ $t('Payment terms') }}</span><p>{{ a.payment_terms }}</p></div>
            <div v-if="a.advance_terms" class="agd-note"><span><icons name="forward" class="w-4 h-4" />{{ $t('Advance payment terms') }}</span><p>{{ a.advance_terms }}</p></div>
            <div v-if="a.remarks" class="agd-note"><span><icons name="info" class="w-4 h-4" />{{ $t('Remarks') }}</span><p>{{ a.remarks }}</p></div>
          </div>
        </collapse-section>
        <section v-if="detailsError" class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Details (plain view)') }}</h2></div></header>
          <div class="lg-note lg-note-red" role="alert"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t('The Details boxes could not be drawn on this screen. Please send this message to support:') }} <b>{{ detailsError }}</b></span></div>
          <dl class="ac-kv" style="margin-top: 10px">
            <dt>{{ $t('Subcontractor') }}</dt><dd>{{ a.contact || '-' }}</dd>
            <dt>{{ $t('Project(s) / Warehouse(s)') }}</dt><dd>{{ projList.length ? projList.join(', ') : '-' }}</dd>
            <dt>{{ $t('Agreement date') }}</dt><dd>{{ sd(a.agreement_date) }}</dd>
            <dt>{{ $t('Start date') }}</dt><dd>{{ sd(a.start_date) }}</dd>
            <dt>{{ $t('Expected completion') }}</dt><dd>{{ sd(a.expected_completion) }}</dd>
            <dt>{{ $t('Completed on') }}</dt><dd>{{ sd(a.actual_completion) }}</dd>
            <dt>{{ $t('Administrator') }}</dt><dd>{{ a.administrator || '-' }}</dd>
            <dt>{{ $t('Created by') }}</dt><dd>{{ a.created_by || '-' }}</dd>
            <dt>{{ $t('Scope of work') }}</dt><dd>{{ a.scope || '-' }}</dd>
          </dl>
        </section>
      </template>

      <template v-if="tab === 'schedule'">
        <collapse-section>
          <template #head><span class="lg-card-ico lg-ico-teal"><icons name="list" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Work schedule') }}</h2><p class="lg-card-sub">{{ $t('Groups (buildings, locations, phases) and their items. A group total is the sum of its items.') }}</p></div>
            <div class="ac-actions" style="margin: 0"><button v-if="groups.length > 1" type="button" class="lg-btn t-slate" @click="setAll(true)"><icons name="chevron-down" class="w-4 h-4" />{{ $t('Expand all') }}</button><button v-if="groups.length > 1" type="button" class="lg-btn t-slate" @click="setAll(false)"><icons name="chevron-up" class="w-4 h-4" />{{ $t('Collapse all') }}</button><template v-if="can.edit_schedule"><button type="button" class="lg-btn lg-btn-soft" @click="showGroup = !showGroup"><icons name="plus" class="w-4 h-4" />{{ $t('Add group') }}</button><button v-if="money" type="button" class="lg-btn t-amber" @click="scheduleTotal"><icons name="calculator" class="w-4 h-4" />{{ $t('Use schedule total as original amount') }}</button></template></div></template>
          <div v-if="groups.length || loose.length" class="sc-tfind"><search-bar v-model="tq" :placeholder="$t('Search items, beneficiaries, units, amounts...')" :count="tq.trim() ? shownItemCount : null" /></div>
          <form v-if="showGroup" class="sc-item-form" @submit.prevent="addGroup"><div class="ac-filters"><div class="ac-f"><label>{{ $t('Group name') }} *</label><input v-model="gf.name" required /></div><div class="ac-f"><label>{{ $t('Kind') }}</label><acct-select v-model="gf.kind" :options="group_kinds" /></div><div class="ac-f"><label aria-hidden="true">&nbsp;</label><button type="submit" class="lg-btn lg-btn-primary" :disabled="gf.processing"><icons name="plus" class="w-4 h-4" />{{ $t('Add group') }}</button></div></div></form>

          <div v-for="g in shownGroups" :key="g.id" class="sc-group">
            <div class="sc-group-head is-click" :class="{ 'is-open': isOpen(g) }" role="button" tabindex="0" :aria-expanded="isOpen(g) ? 'true' : 'false'" :title="isOpen(g) ? $t('Click to collapse') : $t('Click to expand')" @click="toggle(g)" @keydown.enter.self.prevent="toggle(g)" @keydown.space.self.prevent="toggle(g)"><span class="sc-chev" aria-hidden="true"><icons name="chevron-down" class="w-4 h-4" /></span><b>{{ g.name }}</b><small v-if="g.excel_name && g.excel_name !== g.name" class="sc-xl" :title="$t('Name typed in the Excel workbook')">{{ $t('Excel') }}: {{ g.excel_name }}</small><span class="lg-chip lg-chip-slate" style="text-transform: none">{{ tq.trim() ? g.items.length + ' / ' + g.all : g.items.length }} {{ $t('items') }}</span><span v-if="money" class="sc-gt">{{ mc(g.total, a.currency) }}</span>
              <span v-if="can.edit_schedule" class="sc-ga" @click.stop><button type="button" class="lg-btn lg-btn-soft" @click="newItem = g.id; editItem = null"><icons name="plus" class="w-4 h-4" />{{ $t('Add Item') }}</button><button v-if="!g.items.length" type="button" class="lg-icon-btn is-red" :aria-label="$t('Remove')" @click="removeGroup(g)"><icons name="trash" class="w-4 h-4" /></button></span></div>
            <template v-if="isOpen(g)">
            <item-form :fields="item_fields" v-if="newItem === g.id" :agreement-id="a.id" :groups="groups" :default-group="g.id" :units="units" :categories="categories" :pricing="pricing" @close="newItem = null" />
            <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th>#</th><th>{{ $t('Description') }}</th><th>{{ $t('Beneficiary') }}</th><th>{{ $t('Unit') }}</th><th class="ac-num">{{ $t('Qty') }}</th><th v-if="money" class="ac-num">{{ $t('Rate') }}</th><th v-if="money" class="ac-num">{{ $t('Amount') }}</th><th></th></tr></thead>
              <tbody><template v-for="(i, k) in g.items" :key="i.id"><tr :class="{ 'sc-pend': i.pending_variation, 'is-click': can.edit_schedule && !i.variation_id }" :tabindex="can.edit_schedule && !i.variation_id ? 0 : null" @click="rowEdit(i)" @keydown.enter="rowEdit(i)">
                <td :data-label="'#'">{{ k + 1 }}</td><td :data-label="$t('Description')">{{ i.description }}<div v-if="i.remarks || i.pending_variation || i.item_type !== 'original'" class="lg-subtext"><span v-if="i.item_type !== 'original'" class="lg-chip lg-chip-violet" style="text-transform: none">{{ i.item_type }}</span> <span v-if="i.pending_variation" class="lg-chip lg-chip-amber" style="text-transform: none">{{ $t('variation not approved') }}</span> {{ i.remarks }}</div></td>
                <td :data-label="$t('Beneficiary')">{{ i.beneficiary || '-' }}</td><td :data-label="$t('Unit')">{{ i.unit || '-' }}</td><td :data-label="$t('Qty')" class="ac-num">{{ i.quantity !== null ? Number(i.quantity).toLocaleString(undefined, { maximumFractionDigits: 4 }) : '-' }}</td>
                <td v-if="money" :data-label="$t('Rate')" class="ac-num">{{ i.rate !== null ? m(i.rate) : '-' }}</td><td v-if="money" :data-label="$t('Amount')" class="ac-num"><b class="amt-rev">{{ m(i.amount) }}</b></td>
                <td class="lg-sticky-end"><span v-if="can.edit_schedule && !i.variation_id" class="sc-ga"><button type="button" class="lg-icon-btn is-blue" :aria-label="$t('Edit')" @click.stop="editItem = i.id; newItem = null"><icons name="edit" class="w-4 h-4" /></button><button type="button" class="lg-icon-btn is-red" :aria-label="$t('Remove')" @click.stop="removeItem(i)"><icons name="trash" class="w-4 h-4" /></button></span></td></tr>
                <tr v-if="editItem === i.id"><td colspan="8"><item-form :fields="item_fields" :agreement-id="a.id" :item="i" :groups="groups" :units="units" :categories="categories" :pricing="pricing" @close="editItem = null" /></td></tr></template>
                <tr v-if="!g.items.length" class="lg-empty-row"><td class="lg-empty" colspan="8">{{ $t('No items in this group.') }}</td></tr></tbody></table></div>
            </template>
          </div>
          <div v-if="shownLoose.length || (!tq.trim() && can.edit_schedule)" class="sc-group">
            <div class="sc-group-head is-click" :class="{ 'is-open': looseOpen }" role="button" tabindex="0" :aria-expanded="looseOpen ? 'true' : 'false'" @click="looseOpen = !looseOpen" @keydown.enter.self.prevent="looseOpen = !looseOpen" @keydown.space.self.prevent="looseOpen = !looseOpen"><span class="sc-chev" aria-hidden="true"><icons name="chevron-down" class="w-4 h-4" /></span><b>{{ $t('Items without a group') }}</b><span class="lg-chip lg-chip-slate" style="text-transform: none">{{ shownLoose.length }}</span><span v-if="can.edit_schedule" class="sc-ga" @click.stop><button type="button" class="lg-btn lg-btn-soft" @click="newItem = 0; editItem = null"><icons name="plus" class="w-4 h-4" />{{ $t('Item') }}</button></span></div>
            <item-form :fields="item_fields" v-if="newItem === 0" v-show="looseOpen" :agreement-id="a.id" :groups="groups" :units="units" :categories="categories" :pricing="pricing" @close="newItem = null" />
            <div v-if="shownLoose.length" v-show="looseOpen" class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th>#</th><th>{{ $t('Description') }}</th><th>{{ $t('Unit') }}</th><th class="ac-num">{{ $t('Qty') }}</th><th v-if="money" class="ac-num">{{ $t('Amount') }}</th><th></th></tr></thead>
              <tbody><template v-for="(i, k) in shownLoose" :key="i.id"><tr><td>{{ k + 1 }}</td><td>{{ i.description }}</td><td>{{ i.unit || '-' }}</td><td class="ac-num">{{ i.quantity !== null ? i.quantity : '-' }}</td><td v-if="money" class="ac-num"><b>{{ m(i.amount) }}</b></td><td class="lg-sticky-end"><span v-if="can.edit_schedule && !i.variation_id" class="sc-ga"><button type="button" class="lg-icon-btn" :aria-label="$t('Edit')" @click="editItem = i.id"><icons name="edit" class="w-4 h-4" /></button><button type="button" class="lg-icon-btn is-red" :aria-label="$t('Remove')" @click="removeItem(i)"><icons name="trash" class="w-4 h-4" /></button></span></td></tr>
                <tr v-if="editItem === i.id"><td colspan="6"><item-form :fields="item_fields" :agreement-id="a.id" :item="i" :groups="groups" :units="units" :categories="categories" :pricing="pricing" @close="editItem = null" /></td></tr></template></tbody></table></div>
          </div>
          <p v-if="!groups.length && !loose.length" class="lg-sub">{{ $t('The work schedule is empty.') }}</p><p v-else-if="tq.trim() && !shownItemCount" class="lg-sub">{{ $t('No item matches your search.') }}</p>
        </collapse-section>
      </template>

      <template v-if="tab === 'variations'">
        <collapse-section>
          <template #head><span class="lg-card-ico lg-ico-violet"><icons name="adjustments" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Variations') }}</h2><p class="lg-card-sub">{{ $t('Additional works and revised costs. Only an approved variation changes the revised contract value; the original amount stays.') }}</p></div>
            <button v-if="can.variation" type="button" class="lg-btn lg-btn-primary" @click="showVar = !showVar"><icons name="plus" class="w-4 h-4" />{{ $t('New variation') }}</button></template>
          <form v-if="showVar" class="sc-item-form" @submit.prevent="addVariation"><div class="ac-filters">
            <div class="ac-f" style="grid-column: span 2"><label>{{ $t('Title') }} *</label><input v-model="vf.title" required /><span class="ac-err">{{ err('title') }}</span></div>
            <div class="ac-f"><label>{{ $t('Kind') }}</label><acct-select v-model="vf.direction" :options="[{ value: 'increase', label: $t('Increase (additional works)') }, { value: 'reduction', label: $t('Reduction') }]" /></div>
            <div v-if="money" class="ac-f"><label>{{ $t('Amount') }}</label><input v-model="vf.amount" inputmode="decimal" /><small class="lg-subtext">{{ $t('Or add items to the variation and the amount is their total.') }}</small><span class="ac-err">{{ err('amount') }}</span></div>
            <div v-if="fon(variation_fields, 'reason')" class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Reason') }}{{ fstar(variation_fields, 'reason') }}</label><textarea v-model="vf.reason"></textarea><span class="ac-err">{{ err('reason') }}</span></div>
            <custom-fields v-if="variation_fields && variation_fields.custom.length" v-model="vf.custom" :defs="variation_fields.custom" :errors="vf.errors" /></div>
            <div class="ac-actions"><button type="submit" class="lg-btn lg-btn-primary" :disabled="vf.processing"><icons name="tick" class="w-4 h-4" />{{ $t('Save as draft') }}</button></div></form>
          <div v-if="variations.length > 5" class="sc-tfind"><search-bar v-model="tq" :placeholder="$t('Search variations...')" :count="tq.trim() ? shownVariations.length : null" /></div>
          <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th>{{ $t('Variation') }}</th><th>{{ $t('Kind') }}</th><th v-if="money" class="ac-num">{{ $t('Amount') }}</th><th>{{ $t('Status') }}</th></tr></thead>
            <tbody><tr v-for="v in shownVariations" :key="v.id" class="is-click" tabindex="0" @click="$inertia.visit(route('subcontracts.variations.show', v.id))" @keydown.enter="$inertia.visit(route('subcontracts.variations.show', v.id))"><td :data-label="$t('Variation')"><span class="lg-main">{{ v.reference }}</span><div class="lg-subtext">{{ v.title }}</div></td><td :data-label="$t('Kind')">{{ v.direction === 'reduction' ? $t('Reduction') : $t('Increase') }}</td>
              <td v-if="money" :data-label="$t('Amount')" class="ac-num"><span :class="v.direction === 'reduction' ? 'amt-neg' : 'amt-paid'">{{ v.direction === 'reduction' ? '-' : '+' }}{{ m(v.amount) }}</span></td><td :data-label="$t('Status')"><span class="lg-chip" :class="chip(v.status)">{{ $t(v.status) }}</span></td></tr>
              <tr v-if="!variations.length" class="lg-empty-row"><td class="lg-empty" colspan="4">{{ $t('No variations.') }}</td></tr></tbody></table></div>
        </collapse-section>
      </template>

      <template v-if="tab === 'payments'">
        <collapse-section v-if="can.claims">
          <template #head><span class="lg-card-ico lg-ico-emerald"><icons name="collection" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Contract Payment Requests') }}</h2><p class="lg-card-sub">{{ $t('Requests that include this contract, with what was decided and paid on its line.') }}</p></div>
            <Link v-if="can.claim" :href="route('subcontracts.claims.create', { contact_id: a.contact_id })" class="lg-btn lg-btn-primary"><icons name="plus" class="w-4 h-4" />{{ $t('Request payment (contract)') }}</Link></template>
          <div class="lg-table-wrap"><table class="lg-table lg-responsive cl-table"><thead><tr><th>{{ $t('Request') }}</th><th>{{ $t('Date') }}</th><th>{{ $t('Request status') }}</th><th>{{ $t('Line') }}</th><th v-if="money" class="ac-num">{{ $t('Requested') }}</th><th v-if="money" class="ac-num">{{ $t('Approved') }}</th><th v-if="money" class="ac-num">{{ $t('Paid') }}</th></tr></thead>
            <tbody><tr v-for="c in claims" :key="c.line_id" class="is-click" tabindex="0" @click="$inertia.visit(c.url)" @keydown.enter="$inertia.visit(c.url)"><td :data-label="$t('Request')"><span class="lg-main">{{ c.reference }}</span></td><td :data-label="$t('Date')">{{ c.date ? $date(c.date) : '-' }}</td><td :data-label="$t('Request status')"><span class="lg-chip" :class="chip(c.claim_status)">{{ $t(c.claim_status) }}</span></td><td :data-label="$t('Line')"><div class="cl-cell"><span class="lg-chip" :class="chip(c.line_status)">{{ $t(c.line_status) }}</span><div v-if="c.progress && c.line_status === 'Approved'" class="lg-subtext">{{ $t(c.progress) }}</div></div></td>
              <td v-if="money" :data-label="$t('Requested')" class="ac-num"><span class="amt-rev">{{ mc(c.requested, a.currency) }}</span></td><td v-if="money" :data-label="$t('Approved')" class="ac-num"><span class="amt-appr">{{ c.approved !== null ? mc(c.approved, a.currency) : '-' }}</span></td><td v-if="money" :data-label="$t('Paid')" class="ac-num"><span class="amt-paid">{{ c.paid !== null ? mc(c.paid, a.currency) : '-' }}</span></td></tr>
              <tr v-if="!claims.length" class="lg-empty-row"><td class="lg-empty" :colspan="money ? 7 : 4">{{ $t('This contract is on no Contract Payment Request yet.') }}</td></tr></tbody></table></div>
        </collapse-section>
        <collapse-section>
          <template #head><span class="lg-card-ico lg-ico-sky"><icons name="send" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Payment requests') }}</h2></div></template>
          <div v-if="requests.length > 5" class="sc-tfind"><search-bar v-model="tq" :placeholder="$t('Search requests...')" :count="tq.trim() ? shownRequests.length : null" /></div>
          <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th>{{ $t('Request') }}</th><th>{{ $t('Date') }}</th><th>{{ $t('Status') }}</th><th v-if="money" class="ac-num">{{ $t('Requested') }}</th><th v-if="money" class="ac-num">{{ $t('Approved') }}</th><th v-if="money" class="ac-num">{{ $t('Paid') }}</th></tr></thead>
            <tbody><tr v-for="r in shownRequests" :key="r.id" class="is-click" tabindex="0" @click="$inertia.visit(route('subcontracts.requests.show', r.id))" @keydown.enter="$inertia.visit(route('subcontracts.requests.show', r.id))"><td :data-label="$t('Request')"><span class="lg-main">{{ r.reference }}</span></td><td :data-label="$t('Date')">{{ r.date ? $date(r.date) : '-' }}</td><td :data-label="$t('Status')"><span class="lg-chip" :class="chip(r.status)">{{ $t(r.status) }}</span></td>
              <td v-if="money" :data-label="$t('Requested')" class="ac-num"><span class="amt-rev">{{ m(r.requested) }}</span></td><td v-if="money" :data-label="$t('Approved')" class="ac-num"><span class="amt-appr">{{ r.approved !== null ? m(r.approved) : '-' }}</span></td><td v-if="money" :data-label="$t('Paid')" class="ac-num"><span class="amt-paid">{{ m(r.paid) }}</span></td></tr>
              <tr v-if="!requests.length" class="lg-empty-row"><td class="lg-empty" colspan="6">{{ $t('No payment requests.') }}</td></tr></tbody></table></div>
        </collapse-section>
        <collapse-section>
          <template #head><span class="lg-card-ico lg-ico-teal"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Payment Vouchers') }}</h2><p class="lg-card-sub">{{ $t('A payment counts only when its voucher is Authorised and marked paid.') }}</p></div></template>
          <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th>{{ $t('Request') }}</th><th>{{ $t('Voucher') }}</th><th>{{ $t('State') }}</th><th v-if="money" class="ac-num">{{ $t('Amount') }}</th><th>{{ $t('Paid on') }}</th></tr></thead>
            <tbody><tr v-for="p in shownPayments" :key="p.id" :class="{ 'is-click': p.voucher_id }" :tabindex="p.voucher_id ? 0 : null" @click="p.voucher_id && $inertia.visit(route('payment-vouchers.show', p.voucher_id))" @keydown.enter="p.voucher_id && $inertia.visit(route('payment-vouchers.show', p.voucher_id))"><td :data-label="$t('Request')">{{ p.request }}</td><td :data-label="$t('Voucher')"><Link v-if="p.voucher_id" :href="route('payment-vouchers.show', p.voucher_id)" class="lg-main" @click.stop>{{ p.voucher }}</Link><span v-else>-</span></td><td :data-label="$t('State')"><span class="lg-chip" :class="chip(p.voucher_status)">{{ p.voucher_status }}</span></td><td v-if="money" :data-label="$t('Amount')" class="ac-num"><span class="amt-paid">{{ mc(p.amount, p.currency) }}</span></td><td :data-label="$t('Paid on')">{{ p.paid_at ? $date(p.paid_at) : '-' }}</td></tr>
              <tr v-if="!payments.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('No vouchers yet.') }}</td></tr></tbody></table></div>
        </collapse-section>
        <collapse-section v-if="legacy.counted_n || legacy.dated_n">
          <template #head><span class="lg-card-ico lg-ico-violet"><icons name="archive-box" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Historical payments (before WIMS)') }}</h2><p class="lg-card-sub">{{ $t('Imported from the workbook. They are not Payment Vouchers.') }}</p></div></template>
          <dl class="ac-kv"><div><dt>{{ $t('Counted as paid') }}</dt><dd>{{ legacy.counted_n }} {{ $t('record(s)') }}<span v-if="money">: <b>{{ mc(legacy.counted, a.currency) }}</b></span></dd></div>
            <div><dt>{{ $t('Dated payment list (evidence, not counted)') }}</dt><dd>{{ legacy.dated_n }} {{ $t('line(s)') }}<span v-if="money && legacy.dated_sum">: {{ mc(legacy.dated_sum, a.currency) }}</span><span v-if="legacy.dated_from"> ({{ $date(legacy.dated_from) }} - {{ $date(legacy.dated_to) }})</span></dd></div></dl>
          <Link v-if="reconcile" :href="route('subcontracts.reconciliation.show', a.id)" class="lg-btn t-violet" style="margin-top: 8px"><icons name="tick" class="w-4 h-4" />{{ $t('Reconciliation') }}</Link>
        </collapse-section>
      </template>

      <template v-if="tab === 'documents'">
        <collapse-section>
          <template #head><span class="lg-card-ico lg-ico-violet"><icons name="clip" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Documents') }}</h2></div></template>
          <acct-file-list :files="attachments" :download-url="f => route('subcontracts.agreements.download', [a.id, f.id])" :removable="can.edit" @remove="removeFile" />
          <form v-if="can.attach" class="ac-attach" @submit.prevent="upload"><acct-dropzone v-model="up.attachments" :hint="$t('PDF, images and office files up to 8 MB each.')" /><span class="ac-err">{{ err('attachments') || err('attachments.0') }}</span>
            <div><button type="submit" class="lg-btn lg-btn-primary" :disabled="!up.attachments.length || up.processing"><icons name="upload" class="w-4 h-4" />{{ up.processing ? $t('Uploading...') : $t('Attach :n file(s)', { n: up.attachments.length }) }}</button></div></form>
        </collapse-section>
      </template>

      <template v-if="tab === 'history'">
        <collapse-section>
          <template #head><span class="lg-card-ico lg-ico-slate"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('History') }}</h2></div></template>
          <ul class="sc-list"><li v-for="(h, i) in history" :key="i"><b>{{ h.description }}</b> <small>{{ h.causer }} · {{ String(h.created_at).slice(0, 16) }}</small></li><li v-if="!history.length" class="lg-sub">{{ $t('No history to show.') }}</li></ul>
        </collapse-section>
      </template>

      <tec-dialog-modal :show="!!comp.action" max-width="lg" @close="comp.action = ''">
        <template #title><span class="cl-mtitle">{{ compTitle }}</span></template>
        <template #content>
          <p class="cl-mtext">{{ compText }}</p>
          <label v-if="comp.action === 'complete'" class="cl-mlabel">{{ $t('Completion date') }}<input v-model="comp.date" type="date" :max="todayStr" /></label>
          <label class="cl-mlabel">{{ compLabel }}<textarea v-model="comp.note" rows="3" maxlength="1000"></textarea></label>
          <span v-if="err('reason') || err('actual_completion') || err('agreement')" class="cl-merr">{{ err('reason') || err('actual_completion') || err('agreement') }}</span>
        </template>
        <template #footer>
          <div class="flex items-center justify-between gap-2 w-full"><button type="button" class="lg-btn lg-btn-soft" @click="comp.action = ''"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</button>
            <button type="button" class="lg-btn lg-btn-primary" :disabled="busy || (comp.action === 'unverify-completion' && !comp.note.trim())" @click="runComp"><icons name="tick" class="w-4 h-4" />{{ $t('Confirm') }}</button></div>
        </template>
      </tec-dialog-modal>
    </div>
  </admin-layout>
</template>

<script>
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import ScTabs from '../ScTabs.vue';
import CollapseSection from '../CollapseSection.vue';
import ProgressRing from '../ProgressRing.vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import AcctDropzone from '@/Pages/Accounts/AcctDropzone.vue';
import AcctFileList from '@/Pages/Accounts/AcctFileList.vue';
import ItemForm from '../ItemForm.vue';
import CustomFields from '../CustomFields.vue';
import CustomView from '../CustomView.vue';
import SearchBar from '../SearchBar.vue';
import { sc, fieldCfg, tableFind } from '../sc';

export default {
  components: { TecDialogModal, CollapseSection, SearchBar, CustomFields, CustomView, ProgressRing, ScTabs, AdminLayout, PageHeader, Link, AcctSelect, AcctDropzone, AcctFileList, ItemForm },
  mixins: [sc, fieldCfg, tableFind],
  props: { completion: Object, claims: { type: Array, default: () => [] }, fields: Object, custom: Object, item_fields: Object, variation_fields: Object, a: Object, figures: Object, groups: Array, loose: Array, variations: Array, requests: Array, payments: Array, legacy: Object, attachments: Array, history: Array, units: Array, categories: Array, projects: Array, group_kinds: Array, pricing: Array, money: Boolean, can: Object },
  data() {
    return { detailsError: '', comp: { action: '', note: '', date: '' }, looseOpen: true, opened: {}, tab: this.startTab(), asking: '', reason: '', busy: false, showGroup: false, showVar: false, newItem: null, editItem: null, gf: this.$inertia.form({ name: '', kind: 'location' }), vf: this.$inertia.form({ custom: {}, title: '', direction: 'increase', amount: '', reason: '' }), up: this.$inertia.form({ attachments: [] }) };
  },
  computed: {
    // the Details boxes never break the page: every value is read defensively
    projList() { const p = this.a && this.a.projects; return Array.isArray(p) ? p.filter(Boolean).map(String) : (this.a && this.a.project ? String(this.a.project).split(', ').filter(Boolean) : []); },
    customDefs() { return (this.fields && Array.isArray(this.fields.custom)) ? this.fields.custom : []; },
    contactUrl() { try { return this.a && this.a.contact_id ? route('subcontracts.register.show', this.a.contact_id) : ''; } catch (e) { return ''; } },
    shownGroups() { return this.groups.map(g => ({ ...g, items: g.items.filter(i => this.has(i.description, i.beneficiary, i.unit, i.remarks, i.item_type, i.amount)), all: g.items.length })).filter(g => !this.tq.trim() || g.items.length || this.has(g.name, g.excel_name)); },
    shownLoose() { return this.loose.filter(i => this.has(i.description, i.unit, i.amount)); },
    shownVariations() { return this.variations.filter(v => this.has(v.reference, v.title, v.status, v.amount)); },
    shownRequests() { return this.requests.filter(r => this.has(r.reference, r.status, r.date, r.requested)); },
    shownPayments() { return this.payments.filter(p => this.has(p.request, p.voucher, p.voucher_status, p.amount)); },
    shownItemCount() { return this.shownGroups.reduce((n, g) => n + g.items.length, 0) + this.shownLoose.length; },
    showCompletion() { return ['Approved', 'Active', 'Suspended', 'Completed'].includes(this.a.status) && (this.a.status === 'Completed' || this.can.complete || this.completion.state !== 'not_completed'); },
    todayStr() { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); },
    compTitle() { return { complete: this.$t('Mark the contract as completed'), 'verify-completion': this.$t('Verify the completion'), 'unverify-completion': this.$t('Withdraw the verification') }[this.comp.action] || ''; },
    compText() { return { complete: this.$t('The contract becomes Completed. Nothing is paid by this. Someone else may then verify the completion.'), 'verify-completion': this.$t('You confirm that the work is completed. Nothing is paid by this; the contract can then be put on a Contract Payment Request.'), 'unverify-completion': this.$t('The contract can no longer be put on a new Contract Payment Request until it is verified again.') }[this.comp.action] || ''; },
    compLabel() { return { complete: this.$t('Completion note (optional)'), 'verify-completion': this.$t('Verification note (optional)'), 'unverify-completion': this.$t('Why is the verification withdrawn? (required)') }[this.comp.action] || ''; },
    askLabel() { return { reject: this.$t('Why is it rejected? (required)'), suspend: this.$t('Why is it suspended?'), complete: this.$t('Remarks (optional)'), archive: this.$t('Why is it archived? (optional)'), terminate: this.$t('Why is it terminated? (required)') }[this.asking] || ''; },
    schedDiff() { return this.figures && Number(this.figures.scheduled) > 0 && Math.abs(Number(this.figures.scheduled) - Number(this.figures.original)) > 0.5; },
    reconcile() { return this.legacy.counted_n > 0 && this.legacy.has_reconciliation; },
    tabs() {
      return [{ key: 'overview', label: this.$t('Overview'), icon: 'info', tone: 'navy' }, { key: 'schedule', label: this.$t('Schedule'), icon: 'list', tone: 'teal', n: this.groups.reduce((s, g) => s + g.items.length, 0) + this.loose.length }, { key: 'variations', label: this.$t('Variations'), icon: 'adjustments', tone: 'violet', n: this.variations.length },
        { key: 'payments', label: this.$t('Payments'), icon: 'cash', tone: 'emerald', n: this.requests.length }, ...(this.can.documents ? [{ key: 'documents', label: this.$t('Documents'), icon: 'clip', tone: 'sky', n: this.attachments.length }] : []), ...(this.history.length ? [{ key: 'history', label: this.$t('History'), icon: 'clock', tone: 'slate' }] : [])];
    },
  },
  // a Details box that cannot be drawn must never leave the card blank: the reason is shown with a plain view of the same facts
  errorCaptured(err) { this.detailsError = String((err && err.message) || err).slice(0, 300); return false; },
  mounted() { this.checkDetails(); },
  watch: { tab() { this.tq = ''; this.checkDetails(); } },
  methods: {
    // ?tab=documents opens that tab (the claim page links to the completion evidence this way)
    startTab() { try { const t = new URLSearchParams(window.location.search).get('tab'); return ['overview', 'schedule', 'variations', 'payments', 'documents', 'history'].includes(t) ? t : 'overview'; } catch (e) { return 'overview'; } },
    openComp(action) { this.comp = { action, note: '', date: this.todayStr }; },
    runComp() {
      const c = this.comp; this.busy = true;
      this.$inertia.post(route('subcontracts.agreements.transition', [this.a.id, c.action]), { reason: c.note, ...(c.action === 'complete' && c.date ? { actual_completion: c.date } : {}) }, { preserveScroll: true, onSuccess: () => { this.comp.action = ''; }, onFinish: () => (this.busy = false) });
    },
    // after drawing, the Details boxes must have a size; when they do not, say what was measured and show the plain view
    checkDetails() {
      if (this.tab !== 'overview') return;
      setTimeout(() => {
        try {
          if (this.detailsError || !this.$el || !this.$el.querySelector) return;
          const g = this.$el.querySelector('.agd-grid');
          if (!g) { this.detailsError = 'the Details boxes are not on the page'; return; }
          const h = g.getBoundingClientRect().height; const cs = window.getComputedStyle(g); const body = g.closest('.sc-cbody'); const bs = body ? window.getComputedStyle(body) : null;
          if (h < 20) this.detailsError = 'the Details boxes have no height (boxes ' + g.children.length + ', display ' + cs.display + ', visibility ' + cs.visibility + ', opacity ' + cs.opacity + ', body display ' + (bs ? bs.display : '-') + ', body opacity ' + (bs ? bs.opacity : '-') + ', width ' + Math.round(g.getBoundingClientRect().width) + ')';
          else if (bs && (bs.display === 'none' || Number(bs.opacity) < 0.05)) this.detailsError = 'the Details card is hidden (body display ' + bs.display + ', opacity ' + bs.opacity + ')';
        } catch (e) { /* never break the page */ }
      }, 600);
    },
    sd(v) { if (!v) return '-'; try { return this.$date(v); } catch (e) { return String(v); } },
    doit(action, reason) { this.busy = true; this.$inertia.post(route('subcontracts.agreements.transition', [this.a.id, action]), { reason: reason || '' }, { preserveScroll: true, onFinish: () => (this.busy = false) }); },
    ask(a, label) { this.asking = a; this.reason = ''; },
    send() { const a = this.asking; this.doit(a, this.reason); this.asking = ''; },
    remove() { this.$confirm(this.$t('Delete this draft agreement?')).then(ok => { if (ok) this.$inertia.delete(route('subcontracts.agreements.destroy', this.a.id)); }); },
    totalItems() { return this.groups.reduce((n, g) => n + g.items.length, 0); },
    isOpen(g) { if (this.tq.trim()) return true; if (this.newItem === g.id || g.items.some(i => i.id === this.editItem)) return true; return this.opened[g.id] !== undefined ? this.opened[g.id] : this.totalItems() <= 80; },
    rowEdit(i) { if (this.can.edit_schedule && !i.variation_id) { this.editItem = i.id; this.newItem = null; } },
    toggle(g) { this.opened = { ...this.opened, [g.id]: !this.isOpen(g) }; },
    setAll(v) { const o = {}; this.groups.forEach(g => { o[g.id] = v; }); this.opened = o; },
    addGroup() { this.gf.post(route('subcontracts.agreements.groups.store', this.a.id), { preserveScroll: true, onSuccess: () => { this.gf.reset('name'); this.showGroup = false; } }); },
    removeGroup(g) { this.$inertia.delete(route('subcontracts.agreements.groups.destroy', [this.a.id, g.id]), { preserveScroll: true }); },
    removeItem(i) { this.$confirm(this.$t('Remove this item?')).then(ok => { if (ok) this.$inertia.delete(route('subcontracts.agreements.items.destroy', [this.a.id, i.id]), { preserveScroll: true }); }); },
    scheduleTotal() { this.$inertia.post(route('subcontracts.agreements.schedule-total', this.a.id), {}, { preserveScroll: true }); },
    addVariation() { this.vf.post(route('subcontracts.variations.store', this.a.id), { preserveScroll: true }); },
    upload() { this.up.post(route('subcontracts.agreements.attach', this.a.id), { forceFormData: true, preserveScroll: true, onSuccess: () => this.up.reset('attachments') }); },
    removeFile(f) { this.$inertia.delete(route('subcontracts.agreements.remove-file', [this.a.id, f.id]), { preserveScroll: true }); },
  },
};
</script>

<style scoped>
.sc-figs { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
.sc-fig { padding: 12px 14px; border-radius: 16px; background: var(--lg-card); box-shadow: 0 12px 20px -16px rgba(15, 37, 64, 0.55), 0 0 0 1px var(--lg-line); display: grid; gap: 2px; min-width: 0; }
.sc-fig small { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute); } .sc-fig b { font-size: 1.15rem; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; } .sc-fig em { font-style: normal; font-size: 0.72rem; color: var(--lg-mute); }
.sc-fig.is-strong { background: linear-gradient(135deg, rgba(37, 99, 235, 0.1), transparent); } 
.sc-group { margin: 14px 0; padding: 10px; border-radius: 18px; box-shadow: 0 14px 24px -18px rgba(15, 37, 64, 0.5), 0 0 0 1px var(--lg-line); }
.sc-group-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 6px; } .sc-gt { margin-left: auto; font-weight: 800; font-variant-numeric: tabular-nums; } .sc-ga { display: inline-flex; gap: 6px; align-items: center; margin-left: 8px; }
.sc-item-form { padding: 12px; border-radius: 16px; background: rgba(148, 163, 184, 0.08); box-shadow: inset 0 0 0 1px var(--lg-line); margin: 10px 0; }
.sc-group td, .sc-group th { white-space: normal; overflow-wrap: anywhere; } .sc-group td.ac-num, .sc-group th.ac-num { white-space: nowrap; overflow-wrap: normal; } .sc-group .lg-table { min-width: 0; table-layout: auto; }
.sc-pend td { opacity: 0.7; } .sc-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; } .sc-list li { font-size: 0.86rem; } .sc-list small { color: var(--lg-mute); margin-left: 6px; }
</style>
<style>
.cl-mtitle { font-weight: 800; font-size: 1.05rem; }
.cl-mtext { font-size: 0.86rem; color: #64748b; margin-bottom: 10px; line-height: 1.45; }
.cl-mlabel { display: grid; gap: 4px; margin-top: 10px; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: #64748b; }
.cl-mlabel input, .cl-mlabel textarea { width: 100%; border-radius: 14px; padding: 10px 12px; border: 1px solid #d6dfec; background: #f4f7fb; font-size: 0.9rem; font-weight: 500; letter-spacing: 0; text-transform: none; color: #0f2540; }
.cl-merr { display: block; margin-top: 8px; font-size: 0.8rem; font-weight: 700; color: #c0264f; }
</style>

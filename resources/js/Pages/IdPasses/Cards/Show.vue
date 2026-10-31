<template>
  <admin-layout :title="card.number">
    <div class="lg-page ac sc-ui px-4 md:px-0">
      <page-header :icon="info.icon" :title="card.number + ' · ' + card.name" :subtitle="info.singular + (card.category && card.category !== info.singular ? ' · ' + card.category : '')">
        <Link :href="route('id-passes.' + info.route + '.index')" class="lg-btn tone" style="--tone: #2563eb"><span class="tone-ico"><icons name="list" class="w-4 h-4" /></span><span>{{ $t('Back') }}</span></Link>
      </page-header>

      <div class="ids-chip"><category-chip large :name="card.category" :color="card.color" :ink="card.ink" :icon="card.icon" /><span class="lg-chip" :class="statusChip(card.status)">{{ $t(card.status) }}</span></div>

      <section v-if="statusNote" class="lg-note" :class="'lg-note-' + statusNote.tone" style="margin-bottom: 14px"><icons :name="statusIcon(card.status)" class="w-4 h-4" /><span>{{ statusNote.text }}</span></section>

      <div class="ids-grid">
        <section class="ids-cards">
          <id-cutout :front="front" :back="back" :css="css" :color="card.color" :can-print="can.print" :can-download="can.download" :blocked="card.status !== 'Active'" :blocked-text="$t('Only an active ID can be printed or downloaded.')" @print="printOpen = true" @download="download" />
          <div v-if="pooled" class="ids-holder" :class="holder ? 'is-with' : 'is-free'">
            <icons :name="holder ? 'user' : 'tick'" class="w-5 h-5" />
            <div v-if="holder"><b>{{ $t('With :n', { n: holder.name }) }}</b><small>{{ holder.company }}<template v-if="holder.host"> · {{ $t('visiting') }} {{ holder.host }}</template> · {{ $t('since') }} {{ $datetime(holder.from) }}</small></div>
            <div v-else><b>{{ $t('Free') }}</b><small>{{ $t('Not with anybody. It can be given to a visitor.') }}</small></div>
            <button v-if="can.give && !holder" type="button" class="lg-btn tone tone-solid" @click="openGive" style="--tone: #2563eb"><span class="tone-ico"><icons name="plus" class="w-4 h-4" /></span>{{ $t('Give to a visitor') }}</button>
            <button v-if="can.give && holder" type="button" class="lg-btn tone" @click="backOpen = true" style="--tone: #d97706"><span class="tone-ico"><icons name="refresh" class="w-4 h-4" /></span>{{ $t('Take back') }}</button>
          </div>
          <div v-if="can.assign" class="ids-holder" :class="card.unassigned ? 'is-with' : 'is-free'">
            <icons :name="card.unassigned ? 'info' : 'user'" class="w-5 h-5" />
            <div><b>{{ card.unassigned ? $t('Not yet given to a person') : $t('Held by :n', { n: card.name }) }}</b><small>{{ card.unassigned ? $t('Made in advance in the name of the company.') : $t('You can give this pass to another person; the history keeps who had it.') }}</small></div>
            <button type="button" class="lg-btn tone tone-solid" style="--tone: #d97706" @click="openAssign"><span class="tone-ico"><icons name="user" class="w-4 h-4" /></span>{{ card.unassigned ? $t('Give to a person') : $t('Change person') }}</button>
          </div>
          <div class="ids-actions">
            <button v-if="can.approve" type="button" class="lg-btn tone tone-solid" @click="ask('approve')" style="--tone: #16a34a"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Approve') }}</button>
            <button v-if="can.approve" type="button" class="lg-btn tone tone-solid" @click="ask('reject')" style="--tone: #dc2626"><span class="tone-ico"><icons name="x-circle" class="w-4 h-4" /></span>{{ $t('Reject') }}</button>
            <button v-if="can.sync" type="button" class="lg-btn tone" @click="openSync" style="--tone: #0d9488"><span class="tone-ico"><icons name="refresh" class="w-4 h-4" /></span>{{ info.kind === 'staff' ? $t('Sync Data') : $t('Sync Contacts') }}</button>
            <button v-if="can.renew" type="button" class="lg-btn tone" @click="ask('renew')" style="--tone: #d97706"><span class="tone-ico"><icons name="refresh" class="w-4 h-4" /></span>{{ $t('Renew') }}</button>
            <button v-if="can.replace" type="button" class="lg-btn tone" @click="ask('replace')" style="--tone: #7c3aed"><span class="tone-ico"><icons name="duplicate" class="w-4 h-4" /></span>{{ $t('Replace') }}</button>
            <button v-if="can.suspend" type="button" class="lg-btn tone" @click="ask('suspend')" style="--tone: #d97706"><span class="tone-ico"><icons name="pause" class="w-4 h-4" /></span>{{ $t('Suspend') }}</button>
            <button v-if="can.reactivate" type="button" class="lg-btn tone tone-solid" @click="ask('reactivate')" style="--tone: #16a34a"><span class="tone-ico"><icons name="play" class="w-4 h-4" /></span>{{ $t('Reactivate') }}</button>
            <button v-if="can.revoke" type="button" class="lg-btn tone tone-solid" @click="ask('revoke')" style="--tone: #dc2626"><span class="tone-ico"><icons name="x-circle" class="w-4 h-4" /></span>{{ $t('Revoke') }}</button>
          </div>
        </section>

        <div class="ids-right">
          <sc-tabs :tabs="tabs" :model-value="tab" @update:model-value="tab = $event" />

          <section v-show="tab === 'details'" class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-navy"><icons name="info" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('RANS Identification') }}</h2></div>
              <button v-if="can.edit" type="button" class="lg-btn tone ids-edit" @click="openEdit" style="--tone: #0891b2"><span class="tone-ico"><icons name="edit" class="w-4 h-4" /></span>{{ $t('Edit') }}</button></header>
            <dl class="ac-kv">
              <div><dt>{{ $t('ID number') }}</dt><dd><b>{{ card.number }}</b></dd></div>
              <div><dt>{{ $t('Status') }}</dt><dd><span class="lg-chip" :class="statusChip(card.status)">{{ $t(card.status) }}</span></dd></div>
              <div><dt>{{ $t('Holder') }}</dt><dd>{{ card.name }}</dd></div>
              <div v-if="card.title"><dt>{{ $t('Title / work') }}</dt><dd>{{ card.title }}</dd></div>
              <div v-if="card.staff"><dt>{{ $t('WIMS staff reference') }}</dt><dd><a :href="card.staff.url" class="ids-link">{{ card.staff.number }}</a></dd></div>
              <div v-if="card.contact"><dt>{{ $t('Contact') }}</dt><dd>{{ card.contact.name }} <small class="lg-subtext">{{ card.contact.category }}</small></dd></div>
              <div v-if="card.visitor"><dt>{{ $t('Visitor record') }}</dt><dd>{{ card.visitor.name }}</dd></div>
              <div v-if="card.phone"><dt>{{ $t('Phone') }}</dt><dd>{{ card.phone }}</dd></div>
              <div v-if="card.company"><dt>{{ $t('Company') }}</dt><dd>{{ card.company }}</dd></div>
              <div><dt>{{ $t('Date of issue') }}</dt><dd>{{ $date(card.issued_at) }}</dd></div>
              <div><dt>{{ $t('Valid until') }}</dt><dd>{{ card.validity_text }}<small v-if="card.days_left !== null && card.status === 'Active'" class="lg-subtext"> · {{ card.days_left >= 0 ? $t(':n days left', { n: card.days_left }) : '' }}</small></dd></div>
              <div v-if="card.host"><dt>{{ $t('Person visited') }}</dt><dd>{{ card.host }}</dd></div>
              <div v-if="card.purpose"><dt>{{ $t('Purpose / reason for access') }}</dt><dd>{{ card.purpose }}</dd></div>
              <div v-if="card.authorisation"><dt>{{ $t('Authorisation') }}</dt><dd>{{ card.authorisation }}</dd></div>
              <div v-if="card.vehicle"><dt>{{ $t('Vehicle') }}</dt><dd>{{ card.vehicle }} <small v-if="card.vehicle_description" class="lg-subtext">{{ card.vehicle_description }}</small></dd></div>
              <div v-if="card.restrictions"><dt>{{ $t('Restrictions') }}</dt><dd>{{ card.restrictions }}</dd></div>
              <div v-if="card.notes"><dt>{{ $t('Notes') }}</dt><dd>{{ card.notes }}</dd></div>
              <div><dt>{{ $t('Issued by') }}</dt><dd>{{ card.created_by || '-' }}</dd></div>
              <div v-if="card.approved_by"><dt>{{ $t('Decided by') }}</dt><dd>{{ card.approved_by }} <small class="lg-subtext">{{ $datetime(card.approved_at) }}</small></dd></div>
              <div v-if="card.status_reason"><dt>{{ $t('Reason') }}</dt><dd>{{ card.status_reason }}</dd></div>
              <div v-if="card.replaces_number"><dt>{{ $t('Replaces') }}</dt><dd>{{ card.replaces_number }}</dd></div>
              <div v-if="card.replaced_by_number"><dt>{{ $t('Replaced by') }}</dt><dd>{{ card.replaced_by_number }}</dd></div>
              <div><dt>{{ $t('Printed') }}</dt><dd>{{ card.print_count }} {{ $t('times') }}<small v-if="card.last_printed_at" class="lg-subtext"> · {{ $t('last') }} {{ $datetime(card.last_printed_at) }}</small></dd></div>
            </dl>
            <div v-if="cardHistory.length" class="ids-earlier"><b>{{ $t('Other IDs of this person') }}</b>
              <a v-for="h in cardHistory" :key="h.id" :href="h.url"><span>{{ h.number }}</span><em>{{ h.from ? $date(h.from) : '' }} - {{ h.to ? $date(h.to) : $t('now') }}</em><i class="lg-chip" :class="statusChip(h.status)">{{ $t(h.status) }}</i><small v-if="h.reason">{{ h.reason }}</small></a></div>
            <div v-if="can.edit && !pooled" class="ids-photo"><b>{{ $t('Photograph') }}</b>
              <img v-if="card.has_photo" :src="route('id-passes.' + info.route + '.photo-file', [card.id]) + '?w=160&v=' + photoV" alt="" />
              <button type="button" class="lg-btn tone" @click="photoOpen = true" style="--tone: #0284c7"><span class="tone-ico"><icons name="camera" class="w-4 h-4" /></span>{{ card.has_photo ? $t('Change photo') : $t('Add photo') }}</button></div>
          </section>

          <section v-if="pooled" v-show="tab === 'holders'" class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="users" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Who held this pass') }}</h2><p class="lg-card-sub">{{ $t('Every hand-over: who had it, from when to when, and who gave and took it back.') }}</p></div></header>
            <div class="lg-table-wrap"><table class="lg-table lg-responsive"><thead><tr><th>{{ $t('Held by') }}</th><th>{{ $t('From') }}</th><th>{{ $t('Until') }}</th><th>{{ $t('Given by') }}</th><th>{{ $t('Taken back by') }}</th></tr></thead>
              <tbody><tr v-for="a in assignments" :key="a.id"><td :data-label="$t('Held by')"><b>{{ a.holder }}</b><div class="lg-subtext">{{ [a.company, a.host ? $t('visiting') + ' ' + a.host : '', a.note].filter(Boolean).join(' · ') }}</div></td><td :data-label="$t('From')">{{ $datetime(a.from) }}</td><td :data-label="$t('Until')">{{ a.to ? $datetime(a.to) : $t('Still with them') }}<div v-if="a.reason" class="lg-subtext">{{ a.reason }}</div></td><td :data-label="$t('Given by')">{{ a.given_by || '-' }}</td><td :data-label="$t('Taken back by')">{{ a.taken_by || '-' }}</td></tr>
                <tr v-if="!assignments.length" class="lg-empty-row"><td class="lg-empty" colspan="5">{{ $t('This pass has not been given to anybody yet.') }}</td></tr></tbody></table></div>
          </section>

          <section v-show="tab === 'life'" class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Life of this ID') }}</h2><p class="lg-card-sub">{{ $t('Written once and never changed.') }}</p></div></header>
            <ol class="ids-tl"><li v-for="e in events" :key="e.id"><i></i><div><b>{{ eventText(e) }}</b><small>{{ e.by || $t('System') }} · {{ $datetime(e.at) }}</small><em v-if="eventNote(e)">{{ eventNote(e) }}</em></div></li></ol>
            <p v-if="!events.length" class="lg-sub">{{ $t('Nothing yet.') }}</p>
          </section>

          <section v-if="canHistory" v-show="tab === 'prints'" class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="printer" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Print & Export History') }}</h2><p class="lg-card-sub">{{ $t('What WIMS made, for whom and when. "Print initiated" means the PDF was opened for printing: WIMS cannot see the printer.') }}</p></div></header>
            <div class="lg-table-wrap"><table class="lg-table lg-responsive">
              <thead><tr><th>{{ $t('When') }}</th><th>{{ $t('Action') }}</th><th>{{ $t('Layout') }}</th><th>{{ $t('Copies') }}</th><th>{{ $t('Result') }}</th><th>{{ $t('By') }}</th></tr></thead>
              <tbody><tr v-for="p in prints" :key="p.id"><td :data-label="$t('When')">{{ $datetime(p.at) }}</td><td :data-label="$t('Action')">{{ actionText(p.action) }}</td><td :data-label="$t('Layout')">{{ layoutText(p.layout) }}</td><td :data-label="$t('Copies')">{{ p.copies }}</td><td :data-label="$t('Result')">{{ p.outcome === 'Initiated' ? $t('Print initiated') : p.outcome === 'Generated' ? $t('PDF generated') : $t('Failed') }}</td><td :data-label="$t('By')">{{ p.by }}</td></tr>
                <tr v-if="!prints.length" class="lg-empty-row"><td class="lg-empty" colspan="6">{{ $t('This ID has not been printed yet.') }}</td></tr></tbody></table></div>
          </section>
        </div>
      </div>
    </div>

    <!-- print options -->
    <travel-modal :show="printOpen" :title="$t('Print :n', { n: card.number })" :subtitle="$t('Choose how the card is laid out on paper.')" icon="printer" @close="printOpen = false">
      <div class="ids-opts">
        <label v-for="o in printOptions" :key="o.value" class="ids-opt" :class="{ 'is-on': layout === o.value }"><input v-model="layout" type="radio" :value="o.value" /><span><b>{{ o.label }}</b><small>{{ o.help }}</small></span></label>
      </div>
      <div v-if="layout === 'a4_side'" class="ac-f"><label>{{ $t('Sheet') }}</label><div class="idi-src"><button type="button" :class="{ 'is-on': orient === 'portrait' }" @click="orient = 'portrait'">{{ $t('Portrait') }}</button><button type="button" :class="{ 'is-on': orient === 'landscape' }" @click="orient = 'landscape'">{{ $t('Landscape') }}</button></div></div>
      <div v-if="card.print_count > 0" class="ac-f"><label>{{ $t('This ID was printed before. Why is it printed again?') }} <b class="wims-req">*</b></label>
        <div class="idi-src ids-reasons"><button v-for="r in reasons" :key="r" type="button" :class="{ 'is-on': reason === r }" @click="reason = r">{{ r }}</button></div><input v-model="note" maxlength="150" :placeholder="$t('More about it (optional)')" /></div>
      <p v-if="layout === 'a4_duplex'" class="lg-note lg-note-amber"><icons name="info" class="w-4 h-4" /><span>{{ $t('Print both pages on one sheet of paper, double sided, and choose "flip on the long edge". Print at 100% (actual size): do not use "fit to page".') }}</span></p>
      <p v-else class="ids-size">{{ $t('Print at 100% (actual size). Do not use "fit to page": the card must stay 55 x 85 mm.') }}</p>
      <template #footer><button type="button" class="lg-btn tone" @click="printOpen = false" style="--tone: #64748b"><span class="tone-ico"><icons name="cross" class="w-4 h-4" /></span>{{ $t('Cancel') }}</button><button type="button" class="lg-btn tone tone-solid" :disabled="needReason" @click="doPrint" style="--tone: #4f46e5"><span class="tone-ico"><icons name="printer" class="w-4 h-4" /></span>{{ $t('Open for printing') }}</button></template>
    </travel-modal>

    <id-make-overlay />
    <travel-modal :show="dlOpen" :title="$t('Download :n', { n: card.number })" :subtitle="$t('The card as two pages of 55 x 85 mm: front, then back.')" icon="download" @close="dlOpen = false">
      <div class="ac-f"><label>{{ $t('This ID was printed before. Why is it printed again?') }} <b class="wims-req">*</b></label>
        <div class="idi-src ids-reasons"><button v-for="r in reasons" :key="r" type="button" :class="{ 'is-on': reason === r }" @click="reason = r">{{ r }}</button></div><input v-model="note" maxlength="150" :placeholder="$t('More about it (optional)')" /></div>
      <template #footer><button type="button" class="lg-btn tone" @click="dlOpen = false" style="--tone: #64748b"><span class="tone-ico"><icons name="cross" class="w-4 h-4" /></span>{{ $t('Cancel') }}</button><button type="button" class="lg-btn tone tone-solid" :disabled="!reason" @click="doDownload" style="--tone: #0d9488"><span class="tone-ico"><icons name="download" class="w-4 h-4" /></span>{{ $t('Download PDF') }}</button></template>
    </travel-modal>

    <travel-modal :show="giveOpen" :title="$t('Give :n to a visitor', { n: card.number })" :subtitle="$t('The visitor holds it until it is taken back.')" icon="users" :busy="gf.processing" @close="giveOpen = false">
      <form id="ids-give" @submit.prevent="submitGive">
        <div class="ac-f"><label>{{ $t('Visitor directory') }}</label><person-search v-model="pickedVisitor" source="visitor" :placeholder="$t('Type a name, number or phone')" @update:model-value="pickVisitor" /></div>
        <div class="lg-fields"><div class="ac-f"><label>{{ $t('Name') }} <b class="wims-req">*</b></label><input v-model="gf.name" maxlength="190" /><span class="ac-err">{{ errs.name }}</span></div><div class="ac-f"><label>{{ $t('Company') }}</label><input v-model="gf.company" maxlength="150" /></div><div class="ac-f"><label>{{ $t('Phone') }}</label><input v-model="gf.phone" maxlength="60" /></div><div class="ac-f"><label>{{ $t('Person visited') }}</label><input v-model="gf.host" maxlength="150" /></div></div>
        <span class="ac-err">{{ errs.pass }}</span>
      </form>
      <template #footer><button type="button" class="lg-btn tone" @click="giveOpen = false" style="--tone: #64748b"><span class="tone-ico"><icons name="cross" class="w-4 h-4" /></span>{{ $t('Cancel') }}</button><button type="submit" form="ids-give" class="lg-btn tone tone-solid" :disabled="gf.processing || !gf.name" style="--tone: #16a34a"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Give the pass') }}</button></template>
    </travel-modal>

    <travel-modal :show="backOpen" :title="$t('Take back :n', { n: card.number })" :subtitle="holder ? $t('It is with :n.', { n: holder.name }) : ''" icon="refresh" :busy="backForm.processing" @close="backOpen = false">
      <div class="ac-f"><label>{{ $t('Note (optional)') }}</label><input v-model="backForm.reason" maxlength="200" :placeholder="$t('For example: returned, lost by the visitor')" /></div>
      <template #footer><button type="button" class="lg-btn tone" @click="backOpen = false" style="--tone: #64748b"><span class="tone-ico"><icons name="cross" class="w-4 h-4" /></span>{{ $t('Cancel') }}</button><button type="button" class="lg-btn tone tone-solid" :disabled="backForm.processing" @click="takeBack" style="--tone: #16a34a"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Take it back') }}</button></template>
    </travel-modal>

    <!-- one dialog for every reason-giving action -->
    <travel-modal :show="!!ac" :title="ac ? ac.title : ''" :subtitle="ac ? ac.sub : ''" :icon="ac ? ac.icon : 'info'" :busy="af.processing" @close="ac = null">
      <form v-if="ac" id="ids-act" @submit.prevent="submitAction">
        <div v-if="ac.key === 'renew'" class="ac-f"><label>{{ $t('New expiry date') }}</label><date-picker v-model="af.expires_at" /><small class="lg-subtext">{{ $t('Empty: the standard validity of the category, from today.') }}</small><span class="ac-err">{{ errs.expires_at }}</span></div>
        <div v-if="ac.reason" class="ac-f"><label>{{ ac.reasonLabel }} <b class="wims-req">*</b></label><input v-model="af.reason" maxlength="500" autocomplete="off" /><span class="ac-err">{{ errs.reason }}</span></div>
        <div v-if="ac.key === 'approve'" class="ac-f"><label>{{ $t('Remarks') }}</label><input v-model="af.remarks" maxlength="500" /></div>
        <div v-if="ac.key === 'replace'" class="ac-f"><label>{{ $t('New photograph (optional)') }}</label><photo-cropper v-model="af.photo" :min-width="minPhotoWidth" :background="card.photo_background || ''" @busy="cropBusy = $event" /><small class="lg-subtext">{{ $t('The old photograph is used if you do not choose one.') }}</small></div>
        <p v-if="ac.warn" class="lg-note lg-note-amber"><icons name="exclamation" class="w-4 h-4" /><span>{{ ac.warn }}</span></p>
      </form>
      <template #footer><button type="button" class="lg-btn tone" :disabled="af.processing" @click="ac = null" style="--tone: #64748b"><span class="tone-ico"><icons name="cross" class="w-4 h-4" /></span>{{ $t('Cancel') }}</button><button type="submit" form="ids-act" class="lg-btn tone tone-solid" :disabled="af.processing || cropBusy" :style="{ '--tone': ac && ac.danger ? '#dc2626' : '#16a34a' }"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ ac ? ac.button : '' }}</button></template>
    </travel-modal>

    <!-- Sync Data: what differs between the ID and the company record, and the question before anything is changed -->
    <travel-modal :show="syncOpen" :title="info.kind === 'staff' ? $t('Sync Data of :n', { n: card.number }) : $t('Sync Contacts of :n', { n: card.number })" :subtitle="syncData ? $t('Compared with :r: :n', { r: syncData.target.label, n: syncData.target.name }) : $t('Comparing...')" icon="refresh" max-width="2xl" :busy="syncBusy" @close="syncOpen = false">
      <div v-if="!syncData" class="sy-wait"><i></i><i></i><i></i></div>
      <div v-else class="sy-list">
        <p v-if="!syncChanges.length" class="lg-note lg-note-green"><icons name="tick" class="w-4 h-4" /><span>{{ $t('The record already holds the same as this ID. Nothing to sync.') }}</span></p>
        <label v-for="f in syncData.fields" :key="f.key" class="sy-row" :class="['is-' + f.state, { 'is-on': syncPick[f.key] }]">
          <input v-if="f.state === 'new' || f.state === 'different'" v-model="syncPick[f.key]" type="checkbox" />
          <span v-else class="sy-nobox"><icons :name="f.state === 'same' ? 'tick' : 'x-circle'" class="w-4 h-4" /></span>
          <span class="sy-body">
            <span class="sy-top"><b>{{ f.label }}</b>
              <em v-if="f.state === 'new'" class="sy-chip new">{{ $t('Will be added') }}</em><em v-else-if="f.state === 'different'" class="sy-chip diff">{{ $t('Different') }}</em><em v-else-if="f.state === 'blocked'" class="sy-chip bad">{{ $t('Cannot be saved') }}</em><em v-else class="sy-chip same">{{ $t('Same') }}</em></span>
            <span class="sy-cmp">
              <span class="sy-side"><small>{{ $t('In the record now') }}</small><template v-if="f.kind === 'photo'"><img v-if="f.record" :src="f.record" alt="" /><span v-else class="sy-empty">{{ $t('Empty') }}</span></template><template v-else><b v-if="f.record">{{ f.record }}</b><span v-else class="sy-empty">{{ $t('Empty') }}</span></template></span>
              <icons name="forward" class="w-4 h-4 sy-arrow" />
              <span class="sy-side is-new"><small>{{ $t('On the ID') }}</small><template v-if="f.kind === 'photo'"><img v-if="f.card" :src="f.card" alt="" /><span v-else class="sy-empty">{{ $t('Empty') }}</span></template><template v-else><b v-if="f.card">{{ f.save || f.card }}</b><span v-else class="sy-empty">{{ $t('Empty') }}</span></template></span>
            </span>
            <small v-if="f.note" class="sy-note">{{ f.note }}</small>
          </span>
        </label>
        <p v-if="syncReplace.length" class="lg-note lg-note-amber"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t(':n field(s) in the record hold other data and will be replaced: :f. The old value is kept in the history.', { n: syncReplace.length, f: syncReplace.join(', ') }) }}</span></p>
      </div>
      <template #footer><button type="button" class="lg-btn tone" :disabled="syncBusy" @click="syncOpen = false" style="--tone: #64748b"><span class="tone-ico"><icons name="cross" class="w-4 h-4" /></span>{{ $t('Cancel') }}</button><button type="button" class="lg-btn tone tone-solid" :disabled="syncBusy || !syncKeys.length" @click="doSync(syncKeys)" :style="{ '--tone': syncReplace.length ? '#d97706' : '#0d9488' }"><span class="tone-ico"><icons name="refresh" class="w-4 h-4" /></span>{{ syncReplace.length ? $t('Replace and sync') : $t('Sync now') }}</button></template>
    </travel-modal>

    <!-- right after an ID was made: the record lacks what the ID now holds. Yes or No. -->
    <travel-modal :show="offerOpen" :title="$t('Save it to the company record too?')" :subtitle="syncOffer ? $t('The record of :n has nothing for:', { n: syncOffer.target.name }) : ''" icon="refresh" :busy="syncBusy" @close="offerOpen = false">
      <ul v-if="syncOffer" class="sy-offer"><li v-for="f in syncOffer.fields" :key="f.key"><span class="sy-ico"><icons :name="f.key === 'photo' ? 'camera' : f.key === 'phone' ? 'phone' : 'doc-text'" class="w-4 h-4" /></span><b>{{ f.label }}</b><template v-if="f.kind === 'photo'"><img v-if="f.card" :src="f.card" alt="" /></template><template v-else><em>{{ f.card }}</em></template></li></ul>
      <p class="sy-ask">{{ $t('You added these on the ID. Do you want to sync them with the company record (:r) so everyone in WIMS has them?', { r: syncOffer ? syncOffer.target.label : '' }) }}</p>
      <template #footer><button type="button" class="lg-btn tone" :disabled="syncBusy" @click="offerOpen = false" style="--tone: #64748b"><span class="tone-ico"><icons name="cross" class="w-4 h-4" /></span>{{ $t('No, keep them on the ID only') }}</button><button type="button" class="lg-btn tone tone-solid" :disabled="syncBusy" @click="doSync(syncOffer.fields.map(f => f.key), true)" style="--tone: #0d9488"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Yes, sync') }}</button></template>
    </travel-modal>

    <!-- give a Gate Pass to a person -->
    <travel-modal :show="assignOpen" :title="$t('Give :n to a person', { n: card.number })" :subtitle="$t('The number and the QR code stay. The history keeps who held it before.')" icon="user" max-width="2xl" :busy="asf.processing" @close="assignOpen = false">
      <form id="ids-assign" @submit.prevent="submitAssign">
        <div class="idi-src"><button type="button" :class="{ 'is-on': asSource === 'contact' }" @click="setAsSource('contact')">{{ $t('Gate Pass contacts') }}</button><button type="button" :class="{ 'is-on': asSource === 'visitor' }" @click="setAsSource('visitor')">{{ $t('Visitor directory') }}</button><button type="button" :class="{ 'is-on': asSource === 'type' }" @click="setAsSource('type')">{{ $t('Type the details') }}</button></div>
        <div v-if="asSource !== 'type'" class="ac-f" style="margin-top: 12px"><label>{{ asSource === 'contact' ? $t('Contact') : $t('Visitor') }} <b class="wims-req">*</b></label><person-search v-model="asPerson" :source="asSource" :category-id="asSource === 'contact' ? card.category_id : null" :placeholder="$t('Type a name, number or phone')" @update:model-value="pickAssign" /><span class="ac-err">{{ errs.contact_id || errs.full_name }}</span></div>
        <div class="lg-fields" style="margin-top: 12px">
          <div v-if="asSource === 'type'" class="ac-f"><label>{{ $t('Full name') }} <b class="wims-req">*</b></label><input v-model="asf.full_name" maxlength="190" /><span class="ac-err">{{ errs.full_name }}</span></div>
          <div class="ac-f"><label>{{ $t('Phone') }}</label><input v-model="asf.phone" maxlength="60" inputmode="tel" /></div>
          <div class="ac-f"><label>{{ $t('Company') }}</label><input v-model="asf.company" maxlength="150" /></div>
          <div class="ac-f"><label>{{ $t('Reason for access') }} <b class="wims-req">*</b></label><input v-model="asf.purpose" maxlength="255" /><span class="ac-err">{{ errs.purpose }}</span></div>
          <div class="ac-f"><label>{{ $t('Authorisation') }}</label><acct-select v-model="asf.authorisation_type" :options="gateOptions" clearable /></div>
          <div class="ac-f"><label>{{ $t('Vehicle registration') }}</label><input v-model="asf.vehicle_registration" maxlength="40" /><span class="ac-err">{{ errs.vehicle_registration }}</span></div>
        </div>
        <p class="lg-note lg-note-amber" style="margin-top: 12px"><icons name="info" class="w-4 h-4" /><span>{{ $t('If the new person needs a photograph on the card, use Change photograph after this.') }}</span></p>
      </form>
      <template #footer><button type="button" class="lg-btn tone" :disabled="asf.processing" @click="assignOpen = false" style="--tone: #64748b"><span class="tone-ico"><icons name="cross" class="w-4 h-4" /></span>{{ $t('Cancel') }}</button><button type="submit" form="ids-assign" class="lg-btn tone tone-solid" :disabled="asf.processing" style="--tone: #16a34a"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Give the pass') }}</button></template>
    </travel-modal>

    <!-- edit details -->
    <travel-modal :show="editOpen" :title="$t('Edit details')" :subtitle="$t('The number, category and person cannot be changed. Replace the ID for that.')" icon="edit" max-width="2xl" :busy="ef.processing" @close="editOpen = false">
      <form id="ids-edit" class="lg-fields" @submit.prevent="saveEdit">
        <div v-if="info.kind === 'visitor' || info.kind === 'gate'" class="ac-f"><label>{{ $t('Full name') }} <b class="wims-req">*</b></label><input v-model="ef.full_name" maxlength="190" /></div>
        <div class="ac-f"><label>{{ $t('Title / work') }}</label><input v-model="ef.title" maxlength="120" /></div>
        <div class="ac-f"><label>{{ $t('Phone') }}</label><input v-model="ef.phone" maxlength="60" /></div>
        <div class="ac-f"><label>{{ $t('Company') }}</label><input v-model="ef.company" maxlength="150" /></div>
        <div class="ac-f"><label>{{ $t('Expiry date') }}</label><date-picker v-model="ef.expires_at" clearable /><span class="ac-err">{{ errs.expires_at }}</span></div>
        <div v-if="info.kind === 'visitor'" class="ac-f"><label>{{ $t('Person visited') }}</label><input v-model="ef.host_name" maxlength="150" /></div>
        <div v-if="info.kind === 'visitor' || info.kind === 'gate'" class="ac-f"><label>{{ $t('Purpose / reason for access') }}</label><input v-model="ef.purpose" maxlength="255" /></div>
        <div v-if="info.kind === 'gate'" class="ac-f"><label>{{ $t('Authorisation') }}</label><acct-select v-model="ef.authorisation_type" :options="gateOptions" clearable /></div>
        <div v-if="info.kind === 'visitor' || info.kind === 'gate'" class="ac-f"><label>{{ $t('Vehicle registration') }}</label><input v-model="ef.vehicle_registration" maxlength="40" /><span class="ac-err">{{ errs.vehicle_registration }}</span></div>
        <div v-if="info.kind === 'visitor' || info.kind === 'gate'" class="ac-f"><label>{{ $t('Vehicle description') }}</label><input v-model="ef.vehicle_description" maxlength="150" /></div>
        <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Restrictions') }}</label><textarea v-model="ef.restrictions" rows="2" maxlength="2000"></textarea></div>
        <div class="ac-f" style="grid-column: 1 / -1"><label>{{ $t('Notes') }}</label><textarea v-model="ef.notes" rows="2" maxlength="2000"></textarea></div>
      </form>
      <template #footer><button type="button" class="lg-btn tone" :disabled="ef.processing" @click="editOpen = false" style="--tone: #64748b"><span class="tone-ico"><icons name="cross" class="w-4 h-4" /></span>{{ $t('Cancel') }}</button><button type="submit" form="ids-edit" class="lg-btn tone tone-solid" :disabled="ef.processing" style="--tone: #16a34a"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Save') }}</button></template>
    </travel-modal>

    <travel-modal :show="photoOpen" :title="$t('Photograph')" :subtitle="$t('Choose or take a picture; the part inside the frame goes on the card.')" icon="camera" max-width="2xl" :busy="photoBusy" @close="photoOpen = false">
      <photo-cropper v-model="newPhoto" :min-width="minPhotoWidth" :background="card.photo_background || ''" @busy="cropBusy = $event" />
      <span class="ac-err">{{ errs.photo }}</span>
      <template #footer><button type="button" class="lg-btn tone" @click="photoOpen = false" style="--tone: #64748b"><span class="tone-ico"><icons name="cross" class="w-4 h-4" /></span>{{ $t('Cancel') }}</button><button type="button" class="lg-btn tone tone-solid" :disabled="!newPhoto || photoBusy || cropBusy" @click="savePhoto" style="--tone: #16a34a"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Save photograph') }}</button></template>
    </travel-modal>
  </admin-layout>
</template>

<script>
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import DatePicker from '@/Shared/DatePicker.vue';
import AcctSelect from '@/Pages/Accounts/AcctSelect.vue';
import TravelModal from '@/Pages/Travels/TravelModal.vue';
import ScTabs from '../../Subcontracts/ScTabs.vue';
import IdCutout from '../Parts/IdCutout.vue';
import PhotoCropper from '../Parts/PhotoCropper.vue';
import { statusChip, statusIcon } from '../Parts/status';
import CategoryChip from '../Parts/CategoryChip.vue';
import IdMakeOverlay from '../Parts/IdMakeOverlay.vue';
import { idMakeFile, idMakeStart, idMakeDone, idMakeFail } from '@/Core/idMake';
import PersonSearch from '../Parts/PersonSearch.vue';

export default {
  components: { AdminLayout, PageHeader, Link, DatePicker, AcctSelect, TravelModal, ScTabs, IdCutout, PhotoCropper, CategoryChip, PersonSearch, IdMakeOverlay },
  props: { syncOffer: { type: Object, default: null }, info: Object, card: Object, front: String, back: String, css: String, events: Array, prints: Array, canHistory: Boolean, gateTypes: Array, can: Object, layouts: Array, minPhotoWidth: Number, pooled: Boolean, holder: Object, assignments: { type: Array, default: () => [] }, cardHistory: { type: Array, default: () => [] } },
  data() {
    return { syncOpen: false, syncData: null, syncPick: {}, syncBusy: false, offerOpen: false, assignOpen: false, asSource: 'contact', asPerson: null, asf: this.$inertia.form({ contact_id: null, visitor_id: null, full_name: '', phone: '', company: '', purpose: '', authorisation_type: null, vehicle_registration: '' }), tab: 'details', printOpen: false, layout: 'a4_side', orient: 'portrait', ac: null, af: this.$inertia.form({ reason: '', remarks: '', expires_at: null, photo: null }), editOpen: false, ef: this.$inertia.form({}), photoOpen: false, newPhoto: null, photoBusy: false, cropBusy: false, photoV: Date.now(), reason: '', note: '', dlOpen: false, giveOpen: false, gf: this.$inertia.form({ visitor_id: null, name: '', company: '', phone: '', host: '' }), pickedVisitor: null, backForm: this.$inertia.form({ reason: '' }), backOpen: false };
  },
  mounted() {
    if (this.syncOffer) { this.offerOpen = true; try { history.replaceState(history.state, '', location.pathname); } catch (e) { /* the address stays */ } }
  },
  computed: {
    errs() { return this.$page.props.errors || {}; },
    syncChanges() { return (this.syncData ? this.syncData.fields : []).filter(f => f.state === 'new' || f.state === 'different'); },
    syncKeys() { return this.syncChanges.filter(f => this.syncPick[f.key]).map(f => f.key); },
    syncReplace() { return this.syncChanges.filter(f => f.state === 'different' && this.syncPick[f.key]).map(f => f.label); },
    gateOptions() { return this.gateTypes.map(g => ({ value: g, label: g })); },
    tabs() { const t = [{ key: 'details', label: this.$t('Details'), icon: 'info', tone: 'navy' }]; if (this.pooled) t.push({ key: 'holders', label: this.$t('Who held it'), icon: 'users', tone: 'amber', count: this.assignments.length }); t.push({ key: 'life', label: this.$t('Life'), icon: 'clock', tone: 'violet', count: this.events.length }); if (this.canHistory) t.push({ key: 'prints', label: this.$t('Print & Export History'), icon: 'printer', tone: 'sky', count: this.prints.length }); return t; },
    statusNote() {
      const s = this.card.status; const r = this.card.status_reason;
      const m = { Revoked: ['rose', this.$t('This ID is revoked and can never be used again.') + (r ? ' ' + r : '')], Suspended: ['amber', this.$t('This ID is suspended: it does not work until it is reactivated.') + (r ? ' ' + r : '')], Expired: ['amber', this.$t('This ID has expired. Renew it to make it valid again.')],
        'Pending Approval': ['amber', this.$t('This pass is waiting for approval. It cannot be printed yet.')], Rejected: ['rose', this.$t('This pass was rejected.') + (this.card.decision_reason ? ' ' + this.card.decision_reason : '')], Replaced: ['slate', this.$t('This ID was replaced by :n.', { n: this.card.replaced_by_number || '' })] }[s];
      return m ? { tone: m[0], text: m[1] } : null;
    },
    needReason() { return this.card.print_count > 0 && !this.reason; },
    reasons() { return [this.$t('Lost'), this.$t('Broken or damaged'), this.$t('Details changed'), this.$t('Other')]; },
    printOptions() {
      return [{ value: 'a4_side', label: this.$t('A4: front and back side by side'), help: this.$t('Both sides next to each other with cutting marks. The usual choice.') }, { value: 'a4_duplex', label: this.$t('A4: double sided'), help: this.$t('A sheet of fronts and a sheet of backs, lined up for double-sided printing.') },
        { value: 'report', label: this.$t('WIMS report'), help: this.$t('The ID with its details in the WIMS report design (cards shown at real size).') }];
    },
  },
  methods: {
    statusChip, statusIcon,
    reasonParams(q) { if (this.card.print_count > 0 && this.reason) { q.set('reason', this.reason); if (this.note) q.set('note', this.note); } return q; },
    download() { if (this.card.print_count > 0) { this.dlOpen = true; return; } this.make('pdf', ''); },
    doDownload() { this.dlOpen = false; this.make('pdf', this.reasonParams(new URLSearchParams()).toString()); },
    // the PDF is asked for while the "ID press" animation plays; print opens it in a new tab, download saves it
    async make(kind, qs) {
      const ok = await idMakeFile(route('id-passes.' + this.info.route + '.' + (kind === 'print' ? 'print' : 'pdf'), [this.card.id]) + (qs ? '?' + qs : ''), { mode: kind, color: this.card.color, title: kind === 'print' ? this.$t('Preparing :n for printing', { n: this.card.number }) : this.$t('Creating the PDF of :n', { n: this.card.number }), doneText: this.card.number, fail: this.$t('The file could not be made.') });
      if (ok) router.reload({ only: ['card', 'prints', 'events'], preserveScroll: true });
    },
    openGive() { this.gf.reset(); this.gf.clearErrors(); this.pickedVisitor = null; this.giveOpen = true; },
    pickVisitor(p) { if (p) { this.gf.visitor_id = p.id; this.gf.name = p.name; this.gf.company = p.company || ''; this.gf.phone = p.phone || ''; } else { this.gf.visitor_id = null; } },
    submitGive() { this.gf.post(route('id-passes.' + this.info.route + '.give', [this.card.id]), { preserveScroll: true, onSuccess: () => { this.giveOpen = false; } }); },
    takeBack() { this.backForm.post(route('id-passes.' + this.info.route + '.take-back', [this.card.id]), { preserveScroll: true, onSuccess: () => { this.backOpen = false; this.backForm.reset(); } }); },
    doPrint() {
      const q = this.reasonParams(new URLSearchParams({ layout: this.layout })); if (this.layout === 'a4_side') q.set('orientation', this.orient);
      this.printOpen = false; this.make('print', q.toString());
    },
    ask(key) {
      const n = this.card.number;
      const defs = {
        approve: { title: this.$t('Approve :n', { n }), sub: this.$t('The pass becomes active and can be printed.'), icon: 'tick', button: this.$t('Approve') },
        reject: { title: this.$t('Reject :n', { n }), sub: this.$t('The pass cannot be printed.'), icon: 'x-circle', reason: true, reasonLabel: this.$t('Reason'), button: this.$t('Reject'), danger: true },
        renew: { title: this.$t('Renew :n', { n }), sub: this.$t('Same number, new expiry date.'), icon: 'refresh', button: this.$t('Renew') },
        replace: { title: this.$t('Replace :n', { n }), sub: this.$t('A new ID with a new number and QR code is made for the same person.'), icon: 'duplicate', reason: true, reasonLabel: this.$t('Why is it replaced?'), button: this.$t('Replace'), warn: this.$t('The old ID stops working at once and stays in the history.') },
        suspend: { title: this.$t('Suspend :n', { n }), sub: this.$t('It will not work until it is reactivated.'), icon: 'pause', reason: true, reasonLabel: this.$t('Why is it suspended?'), button: this.$t('Suspend') },
        reactivate: { title: this.$t('Reactivate :n', { n }), sub: this.$t('The ID works again.'), icon: 'play', button: this.$t('Reactivate') },
        revoke: { title: this.$t('Revoke :n', { n }), sub: this.$t('This cannot be undone.'), icon: 'x-circle', reason: true, reasonLabel: this.$t('Why is it revoked?'), button: this.$t('Revoke'), danger: true, warn: this.$t('A revoked ID can never be used again. A new ID has to be issued.') },
      };
      this.af.reset(); this.af.clearErrors(); this.ac = { key, ...defs[key] };
    },
    submitAction() {
      const k = this.ac.key; const f = this.af; const opts = { preserveScroll: true, onSuccess: () => { this.ac = null; this.photoV = Date.now(); } };
      const url = route('id-passes.' + this.info.route + '.' + k, [this.card.id]);
      f.transform(d => { const o = {}; if (['reject', 'suspend', 'revoke', 'replace'].includes(k)) o.reason = d.reason; if (k === 'approve') o.remarks = d.remarks; if (k === 'renew') o.expires_at = d.expires_at; if (k === 'replace' && d.photo) { o.photo = d.photo.file; if (d.photo.original) o.photo_original = d.photo.original; if (d.photo.cutout) o.photo_cutout = d.photo.cutout; } return o; }).post(url, { ...opts, forceFormData: k === 'replace' });
    },
    async openSync() {
      this.syncOpen = true; this.syncData = null; this.syncPick = {};
      try {
        const { data } = await window.axios.get(route('id-passes.' + this.info.route + '.sync-preview', [this.card.id]), { headers: { 'X-Quiet': '1' } });
        const pick = {}; data.fields.forEach(f => { pick[f.key] = f.state === 'new' || f.state === 'different'; }); this.syncPick = pick; this.syncData = data;
      } catch (e) { this.syncOpen = false; this.$alert((e.response && e.response.data && e.response.data.message) || this.$t('The records could not be compared.')); }
    },
    // the details travel to the record while the sync animation plays; nothing was changed before this
    async doSync(keys, fromOffer = false) {
      if (this.syncBusy || !keys.length) return;
      this.syncBusy = true; this.syncOpen = false; this.offerOpen = false;
      idMakeStart({ mode: 'sync', color: this.card.color, title: this.info.kind === 'staff' ? this.$t('Syncing with the Staff record') : this.$t('Syncing with the Contact') });
      try {
        const { data } = await window.axios.post(route('id-passes.' + this.info.route + '.sync', [this.card.id]), { fields: keys }, { headers: { 'X-Quiet': '1', Accept: 'application/json' } });
        idMakeDone(data.message || this.$t('Synced.'));
        router.reload({ only: ['card', 'events', 'syncOffer'], preserveScroll: true });
      } catch (e) { idMakeFail((e.response && e.response.data && (e.response.data.message || Object.values(e.response.data.errors || {}).flat()[0])) || this.$t('The sync could not be made.')); }
      this.syncBusy = false;
    },
    openAssign() { this.asSource = 'contact'; this.asPerson = null; this.asf.reset(); this.asf.clearErrors(); this.asf.purpose = this.card.unassigned ? '' : (this.card.purpose || ''); this.assignOpen = true; },
    setAsSource(k) { this.asSource = k; this.asPerson = null; this.asf.contact_id = this.asf.visitor_id = null; if (k !== 'type') this.asf.full_name = ''; },
    pickAssign(p) {
      this.asf.contact_id = this.asSource === 'contact' && p ? p.id : null; this.asf.visitor_id = this.asSource === 'visitor' && p ? p.id : null;
      if (p) { this.asf.full_name = p.name; this.asf.phone = p.phone || this.asf.phone; if (p.company) this.asf.company = p.company; if (p.vehicle && !this.asf.vehicle_registration) this.asf.vehicle_registration = p.vehicle; }
    },
    submitAssign() { this.asf.post(route('id-passes.' + this.info.route + '.assign', [this.card.id]), { preserveScroll: true, onSuccess: () => { this.assignOpen = false; } }); },
    openEdit() {
      const c = this.card;
      this.ef = this.$inertia.form({ full_name: c.name, title: c.title || '', phone: c.phone || '', company: c.company || '', expires_at: c.expires_at, host_name: c.host || '', purpose: c.purpose || '', authorisation_type: c.authorisation, vehicle_registration: c.vehicle || '', vehicle_description: c.vehicle_description || '', restrictions: c.restrictions || '', notes: c.notes || '' });
      this.editOpen = true;
    },
    saveEdit() { this.ef.put(route('id-passes.' + this.info.route + '.update', [this.card.id]), { preserveScroll: true, onSuccess: () => { this.editOpen = false; } }); },
    savePhoto() {
      if (!this.newPhoto) return; this.photoBusy = true;
      const fd = new FormData(); fd.append('photo', this.newPhoto.file); if (this.newPhoto.original) fd.append('photo_original', this.newPhoto.original); if (this.newPhoto.cutout) fd.append('photo_cutout', this.newPhoto.cutout);
      router.post(route('id-passes.' + this.info.route + '.photo', [this.card.id]), fd, { preserveScroll: true, forceFormData: true, onSuccess: () => { this.photoOpen = false; this.newPhoto = null; this.photoV = Date.now(); }, onFinish: () => { this.photoBusy = false; } });
    },
    eventText(e) {
      const m = { holder_changed: this.$t('Given to a person'), synced: this.$t('Synced to the company record'), assigned: this.$t('Given to a visitor'), released: this.$t('Taken back'), reprinted: this.$t('Printed again'), issued: this.$t('Issued'), approved: this.$t('Approved'), rejected: this.$t('Rejected'), renewed: this.$t('Renewed'), suspended: this.$t('Suspended'), reactivated: this.$t('Reactivated'), revoked: this.$t('Revoked'), replaced: this.$t('Replaced by a new ID'), edited: this.$t('Details changed'), photo_changed: this.$t('Photograph changed'), expired: this.$t('Expired') };
      return m[e.event] || e.event;
    },
    eventNote(e) {
      const d = e.detail || {}; if (e.event === 'renewed') return this.$t('Valid until :a (was :b)', { a: d.to || this.$t('no expiry'), b: d.from || this.$t('no expiry') });
      if (e.event === 'edited' && d.after) return Object.keys(d.after).join(', ');
      if (e.event === 'synced' && d.after) return (d.record ? d.record + ': ' : '') + Object.keys(d.after).join(', ') + (d.only_gaps ? ' · ' + this.$t('empty fields only') : '');
      if (e.event === 'holder_changed') return (d.before && d.before.full_name ? d.before.full_name : this.$t('no one')) + ' → ' + ((d.after && d.after.full_name) || this.card.name) + (d.after && d.after.purpose ? ' · ' + d.after.purpose : '');
      if (e.event === 'issued' && d.bulk) return (d.stock ? this.$t('Made in advance in the name of :h', { h: d.holder }) + ' · ' : '') + this.$t('Run :r', { r: d.bulk });
      if (e.event === 'photo_changed' && d.background) return this.$t('Background colour :c', { c: d.background });
      if (e.event === 'assigned') return d.holder || '';
      if (e.event === 'released') return (d.holder || '') + (d.reason ? ' · ' + d.reason : '');
      return d.reason || d.remarks || d.note || '';
    },
    actionText(a) { return { print: this.$t('Print'), pdf: this.$t('PDF download'), batch_print: this.$t('Batch print'), batch_pdf: this.$t('Batch PDF export') }[a] || a; },
    layoutText(l) { return (this.layouts.find(x => x.value === l) || {}).label || l; },
  },
};
</script>

<style scoped>
.ids-grid { display: grid; gap: 18px; grid-template-columns: minmax(0, 1fr); align-items: start; }
@media (min-width: 1000px) { .ids-grid { grid-template-columns: 340px minmax(0, 1fr); } }
.ids-cards { display: grid; justify-items: center; gap: 4px; padding: 6px 0 4px; } .ids-cards .ids-holder, .ids-cards .ids-actions { width: 100%; }
.ids-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; } .ids-pair figure { margin: 0; } .ids-pair figcaption { text-align: center; margin-top: 8px; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--lg-mute, #64748b); }
.ids-chip { display: flex; gap: 10px; align-items: center; margin: -4px 0 14px; }
.ids-size { margin: 12px 0 0; font-size: 0.76rem; color: var(--lg-mute, #64748b); text-align: center; }
.ids-actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; margin-top: 16px; }
.ids-edit { margin-left: auto; }
.ids-link { color: #1565c0; font-weight: 700; }
.ids-photo { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--lg-line, #e2e8f0); } .ids-photo img { width: 64px; height: 80px; object-fit: cover; border-radius: 12px; box-shadow: 0 8px 16px -10px rgba(15, 37, 64, 0.6); }
.ids-tl { list-style: none; margin: 0; padding: 0 0 0 4px; display: grid; gap: 14px; } .ids-tl li { display: flex; gap: 12px; position: relative; } .ids-tl i { width: 11px; height: 11px; border-radius: 50%; background: #1565c0; margin-top: 5px; flex: none; box-shadow: 0 0 0 4px rgba(21, 101, 192, 0.15); }
.ids-tl div { display: grid; gap: 1px; } .ids-tl small { color: var(--lg-mute, #64748b); font-size: 0.76rem; } .ids-tl em { font-style: normal; font-size: 0.82rem; color: #475569; }
.ids-opts { display: grid; gap: 10px; margin-bottom: 14px; }
.ids-opt { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border-radius: 16px; cursor: pointer; background: var(--lg-card, #fff); box-shadow: 0 0 0 1px var(--lg-line, #e2e8f0); }
.ids-opt.is-on { box-shadow: 0 0 0 2px #1565c0, 0 12px 20px -14px rgba(21, 101, 192, 0.7); } .ids-opt input { margin-top: 4px; } .ids-opt span { display: grid; gap: 2px; } .ids-opt small { color: var(--lg-mute, #64748b); }
.idi-src { display: inline-flex; gap: 4px; padding: 4px; border-radius: 999px; background: var(--lg-soft, #f1f5f9); } .idi-src button { border: 0; padding: 7px 14px; border-radius: 999px; background: transparent; font-weight: 700; font-size: 0.82rem; color: var(--lg-mute, #64748b); cursor: pointer; }
.idi-src button.is-on { background: #fff; color: #1e4467; box-shadow: 0 6px 12px -8px rgba(15, 37, 64, 0.6); }
.sy-wait { display: flex; gap: 8px; justify-content: center; padding: 28px; } .sy-wait i { width: 12px; height: 12px; border-radius: 50%; background: #0d9488; animation: sy-b 1s ease-in-out infinite; } .sy-wait i:nth-child(2) { animation-delay: 0.15s; } .sy-wait i:nth-child(3) { animation-delay: 0.3s; } @keyframes sy-b { 50% { transform: translateY(-8px); opacity: 0.4; } }
.sy-list { display: grid; gap: 10px; }
.sy-row { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border-radius: 16px; background: var(--lg-card, #fff); box-shadow: 0 0 0 1px var(--lg-line, #e2e8f0); cursor: pointer; transition: box-shadow 0.2s, background 0.2s; } .sy-row.is-same, .sy-row.is-blocked { cursor: default; } .sy-row.is-on.is-new { box-shadow: 0 0 0 2px #16a34a; background: rgba(22, 163, 74, 0.05); } .sy-row.is-on.is-different { box-shadow: 0 0 0 2px #d97706; background: rgba(217, 119, 6, 0.06); } .sy-row.is-blocked { box-shadow: 0 0 0 1.5px #fca5a5; }
.sy-row > input { margin-top: 4px; width: 18px; height: 18px; } .sy-nobox { width: 18px; margin-top: 3px; color: #94a3b8; } .sy-row.is-blocked .sy-nobox { color: #dc2626; } .sy-row.is-same .sy-nobox { color: #16a34a; }
.sy-body { flex: 1; min-width: 0; display: grid; gap: 8px; } .sy-top { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.sy-chip { font-style: normal; font-size: 0.7rem; font-weight: 800; padding: 2px 9px; border-radius: 999px; } .sy-chip.new { background: rgba(22, 163, 74, 0.14); color: #15803d; } .sy-chip.diff { background: rgba(217, 119, 6, 0.16); color: #b45309; } .sy-chip.bad { background: rgba(220, 38, 38, 0.13); color: #b91c1c; } .sy-chip.same { background: rgba(100, 116, 139, 0.14); color: #475569; }
.sy-cmp { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); gap: 10px; align-items: center; } .sy-side { display: grid; gap: 4px; padding: 8px 10px; border-radius: 12px; background: var(--lg-soft, #f1f5f9); min-width: 0; } .sy-side.is-new { background: rgba(13, 148, 136, 0.09); } .sy-side small { font-size: 0.66rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute, #64748b); } .sy-side b { overflow-wrap: anywhere; } .sy-side img { width: 64px; height: 80px; object-fit: cover; border-radius: 10px; } .sy-empty { color: #94a3b8; font-style: italic; } .sy-arrow { color: #0d9488; } .sy-note { color: #b91c1c; font-weight: 700; }
.sy-offer { list-style: none; margin: 0 0 14px; padding: 0; display: grid; gap: 8px; } .sy-offer li { display: flex; gap: 10px; align-items: center; padding: 10px 12px; border-radius: 14px; background: rgba(13, 148, 136, 0.09); } .sy-offer img { width: 44px; height: 56px; object-fit: cover; border-radius: 8px; margin-left: auto; } .sy-offer em { margin-left: auto; font-style: normal; font-weight: 700; } .sy-ico { display: grid; place-items: center; width: 30px; height: 30px; border-radius: 10px; background: #0d9488; color: #fff; }
.sy-ask { margin: 0; line-height: 1.5; color: #334155; }
@media (max-width: 560px) { .sy-cmp { grid-template-columns: 1fr; } .sy-arrow { transform: rotate(90deg); justify-self: center; } }
.is-disabled { opacity: 0.45; pointer-events: none; }
.ids-holder { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 14px; padding: 12px 14px; border-radius: 18px; } .ids-holder > div { flex: 1; min-width: 160px; display: grid; } .ids-holder small { color: var(--lg-mute, #64748b); }
.ids-holder.is-with { background: rgba(217, 119, 6, 0.12); color: #92400e; } .ids-holder.is-free { background: rgba(22, 163, 74, 0.12); color: #166534; }
.ids-earlier { margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--lg-line, #e2e8f0); display: grid; gap: 6px; } .ids-earlier a { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 6px 10px; border-radius: 12px; color: inherit; text-decoration: none; background: var(--lg-soft, #f1f5f9); } .ids-earlier em { font-style: normal; color: var(--lg-mute, #64748b); font-size: 0.82rem; } .ids-earlier small { flex-basis: 100%; color: var(--lg-mute, #64748b); }
.ids-reasons { flex-wrap: wrap; border-radius: 18px; margin-bottom: 8px; }
</style>

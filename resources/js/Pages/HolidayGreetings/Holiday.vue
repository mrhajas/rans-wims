<template>
  <admin-layout :title="holiday.name + ' ' + holiday.dateText.slice(-4)">
    <div class="lg-page ac sc-ui tr-ui hg-ui px-4 md:px-0" :style="vars">
      <page-header icon="sparkles" :title="holiday.name" :subtitle="holiday.day + ', ' + holiday.dateText">
        <Link :href="route('holiday-greetings.dashboard', { year: holiday.date.slice(0, 4) })" class="lg-btn tone" style="--tone: #475569"><span class="tone-ico"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" /></span>{{ $t('Holidays') }}</Link>
        <button v-if="can.manage" type="button" class="lg-btn tone" style="--tone: #be185d" @click="openEdit"><span class="tone-ico"><icons name="edit" class="w-4 h-4" /></span>{{ $t('Change Holiday') }}</button>
        <template v-if="can.print && holiday.locked">
          <a :href="route('holiday-greetings.records.print', holiday.id)" target="_blank" rel="noopener" class="lg-btn tone" style="--tone: #2563eb"><span class="tone-ico"><icons name="printer" class="w-4 h-4" /></span>{{ $t('Print') }}</a>
          <a :href="route('holiday-greetings.records.pdf', holiday.id)" class="lg-btn tone" style="--tone: #dc2626"><span class="tone-ico"><icons name="doc-text" class="w-4 h-4" /></span>{{ $t('PDF') }}</a>
          <a :href="route('holiday-greetings.records.csv', holiday.id)" class="lg-btn tone" style="--tone: #059669"><span class="tone-ico"><icons name="download" class="w-4 h-4" /></span>{{ $t('CSV') }}</a>
        </template>
      </page-header>

      <section class="hg-hero">
        <span class="hg-bigemoji" aria-hidden="true">{{ emoji }}</span>
        <div class="min-w-0">
          <div class="hg-eyebrow" style="font-size: 0.7rem; font-weight: 800; letter-spacing: 0.16em; text-transform: uppercase; color: var(--a)">{{ holiday.themeLabel }} · {{ holiday.kindLabel }}</div>
          <h2>{{ holiday.name }}</h2>
          <p>{{ holiday.daysAway > 0 ? $t('in :n days', { n: holiday.daysAway }) : holiday.daysAway === 0 ? $t('Today') : $t(':n days ago', { n: -holiday.daysAway }) }} · {{ $t('Reminder to you: :t', { t: holiday.reminderDueText }) }}</p>
        </div>
        <div class="hg-hero-end">
          <span class="lg-chip" style="text-transform: none">{{ phaseText }}</span>
          <span v-if="!holiday.confirmed" class="lg-chip" style="text-transform: none">{{ $t('Date to be confirmed') }}</span>
        </div>
      </section>

      <div v-if="!holiday.counts" class="lg-note lg-note-amber" role="alert" style="margin-bottom: 14px">
        <icons name="exclamation" class="w-4 h-4" />
        <div>
          <b v-if="!holiday.enabled">{{ $t('This holiday is switched off.') }}</b>
          <b v-else>{{ $t('This holiday falls on a :d, so it does not count.', { d: holiday.day }) }}</b>
          {{ $t('No reminder is sent and the greetings cannot be sent.') }}
          <button v-if="can.manage" type="button" class="lg-btn lg-btn-soft" style="margin-left: 8px" @click="quickInclude"><icons name="tick" class="w-4 h-4" />{{ holiday.enabled ? $t('Include it anyway') : $t('Switch it on') }}</button>
        </div>
      </div>
      <div v-if="!holiday.confirmed && !holiday.locked" class="lg-note lg-note-blue" style="margin-bottom: 14px"><icons name="info" class="w-4 h-4" /><div>{{ $t('This holiday follows the moon, so the date was worked out from the calendar and may be a day out. When the date is announced, confirm it:') }} <button v-if="can.manage" type="button" class="lg-btn lg-btn-soft" style="margin-left: 8px" @click="openEdit"><icons name="calendar" class="w-4 h-4" />{{ $t('Confirm the date') }}</button></div></div>
      <div v-if="smsReady === false && lists.counts.sms" class="lg-note lg-note-amber" style="margin-bottom: 14px"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t('SMS is switched off in WIMS Connect, so the SMS messages would fail. Switch it on, or send now and retry the SMS later.') }}</span></div>
      <div v-if="$page.props.errors.send" class="lg-note lg-note-red" role="alert" style="margin-bottom: 14px"><icons name="exclamation" class="w-4 h-4" /><span>{{ $page.props.errors.send }}</span></div>
      <div v-if="$page.props.errors.holiday" class="lg-note lg-note-red" role="alert" style="margin-bottom: 14px"><icons name="exclamation" class="w-4 h-4" /><span>{{ $page.props.errors.holiday }}</span></div>

      <!-- the send bar -->
      <section class="hg-sendbar" style="margin-bottom: 16px">
        <div class="hg-grow">
          <template v-if="!holiday.locked">
            <b>{{ $t('Ready to send') }}</b><br />
            <span class="hg-note">{{ $t(':e emails and :s SMS to :p people. :d repeated addresses were removed.', { e: lists.counts.email, s: lists.counts.sms, p: people, d: lists.counts.duplicates }) }}</span>
            <div v-if="holiday.counts && auto.enabled" class="hg-autochip"><icons name="clock" class="w-4 h-4" /><span>{{ $t('Sends itself on :t to :b', { t: auto.atText, b: auto.bundleName }) }}<template v-if="!auto.own"> · {{ $t('from the Settings') }}</template></span></div>
          </template>
          <template v-else>
            <b>{{ summary.state === 'sending' ? $t('Sending...') : summary.state === 'partly' ? $t('Sent, some failed') : $t('Greetings sent') }}</b>
            <span class="hg-note"> · {{ $t('by :n', { n: holiday.sentBy || '-' }) }} · {{ $datetime(holiday.sendStarted) }}</span>
            <div class="hg-bar" style="margin-top: 8px"><i class="is-ok" :style="{ width: pct(summary.sent) + '%' }"></i><i class="is-bad" :style="{ width: pct(summary.failed) + '%' }"></i><i class="is-wait" :style="{ width: pct(summary.pending) + '%' }"></i></div>
            <div v-if="sentTo.length" class="hg-note" style="margin-top: 4px">{{ $t('Sent to: :l', { l: sentTo.join(', ') }) }}</div>
            <span class="hg-note">{{ $t(':a of :t sent', { a: summary.sent, t: summary.total }) }}<template v-if="summary.failed"> · {{ $t(':n failed', { n: summary.failed }) }}</template><template v-if="summary.pending"> · {{ $t(':n waiting', { n: summary.pending }) }}</template></span>
          </template>
        </div>
        <template v-if="can.send">
          <button v-if="!holiday.locked || freshCount > 0" type="button" class="lg-btn tone tone-solid" style="--tone: #be185d" :disabled="busy || !holiday.counts || (!lists.counts.email && !lists.counts.sms)" @click="openSend">
            <span class="tone-ico"><icons :name="busy ? 'refresh' : 'send'" class="w-4 h-4" :class="{ 'animate-spin': busy }" /></span>{{ holiday.locked ? $t('Send to :n new people', { n: freshCount }) : $t('Send the Greetings') }}
          </button>
          <button v-if="!holiday.locked && holiday.counts" type="button" class="lg-btn tone" style="--tone: #7c3aed" :disabled="busy" @click="openAuto"><span class="tone-ico"><icons name="clock" class="w-4 h-4" /></span>{{ $t('Automatic Sending') }}</button>
          <button v-if="summary.failed" type="button" class="lg-btn tone" style="--tone: #d97706" :disabled="busy" @click="retryAll"><span class="tone-ico"><icons name="refresh" class="w-4 h-4" /></span>{{ $t('Retry :n failed', { n: summary.failed }) }}</button>
        </template>
      </section>

      <sc-tabs :tabs="tabs" :model-value="tab" @update:model-value="tab = $event" />

      <!-- the message -->
      <div v-show="tab === 'message'">
        <div class="hg-two">
          <section class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-rose"><icons name="edit" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('The message') }}</h2><p class="lg-card-sub">{{ holiday.edited ? $t('Your own wording.') : $t('The WIMS template. Change anything you like.') }}</p></div></header>
            <div v-if="holiday.locked" class="lg-note lg-note-blue" style="margin-bottom: 12px"><icons name="info" class="w-4 h-4" /><span>{{ $t('The greetings were sent, so the message is locked and shows what was sent.') }}</span></div>
            <div class="hg-field"><label>{{ $t('Email subject') }}</label><input v-model="msg.subject" maxlength="190" :disabled="locked" /><span class="ac-err">{{ $page.props.errors.subject }}</span></div>
            <div class="hg-field"><label>{{ $t('Email headline') }}</label><input v-model="msg.headline" maxlength="160" :disabled="locked" /><span class="hg-hint">{{ $t('Put | between the English and the Swahili line.') }}</span><span class="ac-err">{{ $page.props.errors.headline }}</span></div>
            <div class="hg-field"><label>{{ $t('Email message') }}</label><textarea ref="body" v-model="msg.body" maxlength="6000" :disabled="locked"></textarea><span class="hg-hint">{{ $t('A blank line starts a new paragraph. A paragraph that starts with > is shown as the highlighted wish.') }}</span><span class="ac-err">{{ $page.props.errors.body }}</span></div>
            <div class="hg-field"><label>{{ $t('SMS') }}</label><textarea ref="sms" v-model="msg.sms" maxlength="600" style="min-height: 96px" :disabled="locked"></textarea><span class="hg-hint">{{ $t('Plain letters only and no links: the SMS gateway refuses links, and accents make a message cost more.') }}</span><span class="ac-err">{{ $page.props.errors.sms }}</span></div>
            <div v-if="!locked" class="hg-field"><label>{{ $t('Put in a name or detail') }}</label><div class="hg-chips"><button v-for="(d, k) in placeholders" :key="k" type="button" :title="$t(d)" @click="insert(k)">{{ k }}</button></div></div>
            <div v-if="!locked && can.prepare" style="display: flex; gap: 8px; flex-wrap: wrap">
              <button type="button" class="lg-btn tone tone-solid" style="--tone: #059669" :disabled="busy || !dirty" @click="saveMessage"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Save the Message') }}</button>
              <button type="button" class="lg-btn tone" style="--tone: #d97706" :disabled="busy" @click="resetMessage"><span class="tone-ico"><icons name="refresh" class="w-4 h-4" /></span>{{ $t('Back to the WIMS Template') }}</button>
            </div>
          </section>

          <section class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="eye-flat" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('How it will look') }}</h2><p class="lg-card-sub">{{ $t('With the name and title of one person from the list.') }}</p></div></header>
            <div class="tm-grid" style="margin-bottom: 12px">
              <div class="ac-f"><label>{{ $t('Title') }}</label><input v-model="sampleP.title" maxlength="40" autocomplete="off" /></div>
              <div class="ac-f"><label>{{ $t('Name') }}</label><input v-model="sampleP.name" maxlength="120" autocomplete="off" /></div>
            </div>
            <div class="hg-hint" style="margin-bottom: 6px"><b>{{ $t('Subject') }}:</b> {{ preview.subject }}</div>
            <iframe class="hg-frame" :srcdoc="preview.html" sandbox="" :title="$t('Email preview')"></iframe>
            <div v-if="photo" style="margin-top: 16px"><div class="hg-hint" style="margin-bottom: 6px"><b>{{ $t('Photo behind the email') }}</b> · {{ $t('used in every :t email', { t: photo.label }) }}</div><photo-card :photo="photo" :can="can.photos" /></div>
            <div class="hg-phone" style="margin-top: 16px">
              <small>{{ $t('SMS') }}</small>
              <div class="hg-bubble">{{ preview.sms }}</div>
              <div class="hg-sms-meter"><span>{{ $t(':n characters', { n: preview.smsLength }) }}</span><span>{{ $t(':n SMS part(s)', { n: preview.smsParts }) }}</span></div>
            </div>
          </section>
        </div>
      </div>

      <!-- who receives it -->
      <div v-show="tab === 'people'">
        <div v-if="lists.counts.duplicates" class="lg-note lg-note-blue" style="margin-bottom: 14px"><icons name="info" class="w-4 h-4" /><div>{{ $t(':n repeated email addresses or mobile numbers were left out, so nobody gets the same message twice:', { n: lists.counts.duplicates }) }} <span class="hg-note">{{ lists.duplicates.slice(0, 6).map(d => d.name + ' (' + d.address + ')').join(', ') }}<template v-if="lists.duplicates.length > 6">...</template></span></div></div>
        <div class="hg-two">
          <section v-for="ch in ['email', 'sms']" :key="ch" class="lg-card">
            <header class="lg-card-head"><span class="lg-card-ico" :class="ch === 'email' ? 'lg-ico-indigo' : 'lg-ico-emerald'"><icons :name="ch === 'email' ? 'email' : 'sms'" class="w-5 h-5" /></span>
              <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ ch === 'email' ? $t('Will receive the email') : $t('Will receive the SMS') }} ({{ lists.counts[ch] }})</h2><p class="lg-card-sub"><template v-if="lists.counts[ch + '_left_out']">{{ $t(':n left out of this holiday', { n: lists.counts[ch + '_left_out'] }) }}</template><template v-else>{{ $t('Everybody on the list who has this.') }}</template></p></div></header>
            <div class="hg-tools"><input v-model="find[ch]" type="search" :placeholder="$t('Find a person...')" autocomplete="off" />
              <simple-autocomplete v-if="bundles.length > 1" v-model="showBundle" class="hg-sac" :options="bundleFilterOptions" icon="collection" :placeholder="$t('Whole greeting list')" />
              <template v-if="can.prepare && !holiday.locked"><button type="button" class="lg-btn lg-btn-soft" @click="leaveOut(ch, shown(ch).filter(r => !r.excluded), true)">{{ $t('Leave out shown') }}</button><button type="button" class="lg-btn lg-btn-soft" @click="leaveOut(ch, shown(ch).filter(r => r.excluded), false)">{{ $t('Include shown') }}</button></template></div>
            <div class="hg-list"><table>
              <thead><tr><th style="width: 40px">{{ $t('No') }}</th><th>{{ $t('Person') }}</th><th>{{ ch === 'email' ? $t('Email') : $t('Mobile') }}</th><th style="width: 90px"></th></tr></thead>
              <tbody>
                <tr v-for="(r, i) in shown(ch)" :key="r.id" :class="{ 'is-out': r.excluded }">
                  <td>{{ i + 1 }}</td><td><b>{{ r.full }}</b><br /><small class="hg-note">{{ r.group }}</small></td><td class="hg-addr">{{ r.address }}</td>
                  <td style="text-align: right"><span v-if="r.delivery" class="hg-ind" :class="indClass(r.delivery.status)"><i></i>{{ indText(r.delivery.status) }}</span>
                    <button v-else-if="can.prepare" type="button" class="lg-btn lg-btn-soft" @click="leaveOut(ch, [r], !r.excluded)">{{ r.excluded ? $t('Include') : $t('Leave out') }}</button></td>
                </tr>
                <tr v-if="!shown(ch).length"><td colspan="4" style="text-align: center; padding: 22px" class="hg-note">{{ lists.counts[ch] + (lists.counts[ch + '_left_out'] || 0) === 0 ? $t('Nobody yet. Add people to the greeting list.') : $t('Nobody matches.') }}</td></tr>
              </tbody></table></div>
          </section>
        </div>
        <p class="hg-note" style="margin-top: 10px"><Link :href="route('holiday-greetings.contacts.index')" style="color: #be185d; font-weight: 800">{{ $t('Open the greeting list to add or change people') }}</Link></p>
      </div>

      <!-- delivery -->
      <div v-show="tab === 'delivery'">
        <section class="lg-card">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-emerald"><icons name="tick" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Delivery of every greeting') }}</h2><p class="lg-card-sub">{{ $t('"Sent" means the mail server or the SMS gateway accepted the message.') }}</p></div></header>
          <div v-if="!sentRows.length" class="hg-note" style="padding: 18px">{{ $t('Nothing has been sent for this holiday yet.') }}</div>
          <template v-else>
            <div class="hg-stats" style="margin: 0 0 12px">
              <div class="hg-stat is-green"><i><icons name="email" class="w-5 h-5" /></i><span><b>{{ summary.email.sent }} / {{ summary.email.total }}</b>{{ $t('Emails sent') }}</span></div>
              <div class="hg-stat is-green"><i><icons name="sms" class="w-5 h-5" /></i><span><b>{{ summary.sms.sent }} / {{ summary.sms.total }}</b>{{ $t('SMS sent') }}</span></div>
              <div class="hg-stat is-red"><i><icons name="exclamation" class="w-5 h-5" /></i><span><b>{{ summary.failed }}</b>{{ $t('Failed') }}</span></div>
              <div class="hg-stat is-amber"><i><icons name="clock" class="w-5 h-5" /></i><span><b>{{ summary.pending }}</b>{{ $t('Waiting') }}</span></div>
            </div>
            <div class="hg-tools"><input v-model="find.delivery" type="search" :placeholder="$t('Find a person...')" autocomplete="off" />
              <simple-autocomplete v-model="fStatus" class="hg-sac" :options="statusOptions" icon="tick" :placeholder="$t('All results')" />
              <simple-autocomplete v-model="fChannel" class="hg-sac" :options="channelOptions" icon="email" :placeholder="$t('Email and SMS')" /></div>
            <div class="hg-list"><table>
              <thead><tr><th style="width: 40px">{{ $t('No') }}</th><th>{{ $t('Person') }}</th><th>{{ $t('Channel') }}</th><th>{{ $t('Address') }}</th><th>{{ $t('Result') }}</th><th style="width: 90px"></th></tr></thead>
              <tbody>
                <tr v-for="(r, i) in deliveryRows" :key="r.channel + r.id">
                  <td>{{ i + 1 }}</td><td><b>{{ r.full }}</b></td><td>{{ r.channel === 'email' ? $t('Email') : $t('SMS') }}</td><td class="hg-addr">{{ r.address }}</td>
                  <td><span class="hg-ind" :class="indClass(r.delivery.status)"><i></i>{{ indText(r.delivery.status) }}</span><div v-if="r.delivery.error" class="hg-note" style="color: #be123c; margin-top: 3px">{{ r.delivery.error }}</div></td>
                  <td style="text-align: right"><button v-if="can.send && r.delivery.status === 'Failed'" type="button" class="lg-btn tone" style="--tone: #d97706" :disabled="busy" @click="retryOne(r)"><span class="tone-ico"><icons name="refresh" class="w-4 h-4" /></span>{{ $t('Retry') }}</button></td>
                </tr>
                <tr v-if="!deliveryRows.length"><td colspan="6" style="text-align: center; padding: 22px" class="hg-note">{{ $t('Nobody matches.') }}</td></tr>
              </tbody></table></div>
          </template>
        </section>
      </div>
    </div>

    <travel-modal :show="sendOpen" :title="$t('Send the Greetings')" :subtitle="$t('Choose which greeting list receives them.')" icon="send" :busy="busy" @close="sendOpen = false">
      <div class="hg-field"><label>{{ $t('Send to') }}</label><simple-autocomplete v-model="sendBundle" :options="sendOptions" icon="collection" :clearable="false" /></div>
      <div v-if="sendInfo" class="hg-sendinfo">
        <div><b>{{ sendInfo.people }}</b><span>{{ $t('people') }}</span></div>
        <div><b>{{ sendInfo.email }}</b><span>{{ $t('emails') }}</span></div>
        <div><b>{{ sendInfo.sms }}</b><span>{{ $t('SMS') }}</span></div>
        <div><b>{{ sendInfo.fresh }}</b><span>{{ $t('not yet sent') }}</span></div>
      </div>
      <p v-if="sendInfo && !sendInfo.fresh" class="lg-note lg-note-amber" style="margin-top: 12px"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t('Everybody in this list has already been sent this greeting.') }}</span></p>
      <p class="hg-hint" style="margin-top: 12px">{{ holiday.locked ? $t('Only the people not yet sent this greeting will receive it.') : $t('This cannot be undone. Create or change bundles on the Greeting Bundles page.') }}</p>
      <template #footer>
        <button type="button" class="lg-btn lg-btn-soft" :disabled="busy" @click="sendOpen = false">{{ $t('Cancel') }}</button>
        <button type="button" class="lg-btn tone tone-solid" style="--tone: #be185d" :disabled="busy || !sendInfo || !sendInfo.fresh" @click="send"><span class="tone-ico"><icons :name="busy ? 'refresh' : 'send'" class="w-4 h-4" :class="{ 'animate-spin': busy }" /></span>{{ $t('Yes, Send') }}</button>
      </template>
    </travel-modal>

    <travel-modal :show="autoOpen" :title="$t('Automatic Sending')" :subtitle="$t('Let this holiday send its greetings by itself.')" icon="clock" :busy="busy" @close="autoOpen = false">
      <auto-send-fields v-model="autoForm" :bundles="bundleList" :date="holiday.date" :errors="$page.props.errors" />
      <p class="hg-hint" style="margin-top: 12px">{{ $t('Settings default: :s.', { s: auto.default.enabled ? $t(':t before, to :b', { t: humanMinutes(auto.default.minutes), b: auto.default.bundleName }) : $t('off') }) }}</p>
      <p v-if="auto.attempted" class="lg-note lg-note-amber" style="margin-top: 12px"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t('Automatic sending already ran for this holiday and found nobody to send to. Change the plan to run it again.') }}</span></p>
      <template #footer>
        <button v-if="auto.own" type="button" class="lg-btn tone" style="--tone: #475569; margin-right: auto" :disabled="busy" @click="followSettings"><span class="tone-ico"><icons name="refresh" class="w-4 h-4" /></span>{{ $t('Follow the Settings') }}</button>
        <button type="button" class="lg-btn lg-btn-soft" :disabled="busy" @click="autoOpen = false">{{ $t('Cancel') }}</button>
        <button type="button" class="lg-btn tone tone-solid" style="--tone: #059669" :disabled="busy" @click="saveAuto"><span class="tone-ico"><icons :name="busy ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': busy }" /></span>{{ $t('Save') }}</button>
      </template>
    </travel-modal>

    <travel-modal :show="editOpen" :title="$t('Change Holiday')" :subtitle="$t('The name, the date and how it counts.')" icon="calendar" :busy="form.processing" @close="editOpen = false">
      <form id="hg-edit-form" @submit.prevent="saveHoliday">
        <div class="tm-grid">
          <div class="ac-f tm-full"><label>{{ $t('Name of the holiday') }}</label><input v-model="form.name" maxlength="160" autocomplete="off" /><span class="ac-err">{{ form.errors.name }}</span></div>
          <div class="ac-f"><label>{{ $t('Date') }}</label><input v-model="form.date" type="date" :disabled="holiday.locked" /><span class="ac-err">{{ form.errors.date }}</span></div>
          <div class="ac-f"><label>{{ $t('Look of the greeting') }}</label><select v-model="form.theme"><option v-for="(label, key) in themes" :key="key" :value="key">{{ label }}</option></select></div>
          <div class="ac-f tm-full"><label class="hg-check"><input v-model="form.date_confirmed" type="checkbox" /> {{ $t('The date is confirmed') }}</label></div>
          <div class="ac-f tm-full"><label class="hg-check"><input v-model="form.is_enabled" type="checkbox" /> {{ $t('This holiday is on (reminders and greetings)') }}</label></div>
          <div class="ac-f tm-full"><label class="hg-check"><input v-model="form.force_include" type="checkbox" /> {{ $t('Count it even if it falls on a Saturday or Sunday') }}</label></div>
          <div class="ac-f tm-full"><label>{{ $t('Note') }}</label><input v-model="form.note" maxlength="500" autocomplete="off" /></div>
        </div>
      </form>
      <template #footer>
        <button v-if="!holiday.locked" type="button" class="lg-btn tone" style="--tone: #dc2626; margin-right: auto" :disabled="form.processing" @click="removeHoliday"><span class="tone-ico"><icons name="trash" class="w-4 h-4" /></span>{{ $t('Remove') }}</button>
        <button type="button" class="lg-btn lg-btn-soft" :disabled="form.processing" @click="editOpen = false">{{ $t('Cancel') }}</button>
        <button type="submit" form="hg-edit-form" class="lg-btn tone tone-solid" style="--tone: #059669" :disabled="form.processing"><span class="tone-ico"><icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" /></span>{{ $t('Save') }}</button>
      </template>
    </travel-modal>
  </admin-layout>
</template>

<script>
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import ScTabs from '@/Pages/Subcontracts/ScTabs.vue';
import TravelModal from '@/Pages/Travels/TravelModal.vue';
import PhotoCard from './PhotoCard.vue';
import SimpleAutocomplete from '@/Shared/SimpleAutocomplete.vue';
import AutoSendFields from './AutoSendFields.vue';

export default {
  components: { AdminLayout, PageHeader, Link, ScTabs, TravelModal, PhotoCard, SimpleAutocomplete, AutoSendFields },
  props: { bundles: { type: Array, default: () => [] }, auto: Object, sentTo: { type: Array, default: () => [] }, photo: Object, holiday: Object, template: Object, defaults: Object, placeholders: Object, sample: Object, lists: Object, summary: Object, smsReady: Boolean, themes: Object, kinds: Object, can: Object },
  data() {
    return {
      tab: this.holiday.locked ? 'delivery' : 'message', busy: false, editOpen: false, poll: null, previewTimer: null, reqId: 0,
      msg: { subject: this.template.subject, headline: this.template.headline, body: this.template.body, sms: this.template.sms },
      sampleP: { title: this.sample.title, name: this.sample.name },
      preview: { html: '', subject: '', sms: '', smsLength: 0, smsParts: 1 },
      find: { email: '', sms: '', delivery: '' }, fStatus: '', fChannel: '', showBundle: 0, sendOpen: false, sendBundle: this.auto && this.auto.bundle_id ? this.auto.bundle_id : 0, autoOpen: false, autoForm: { enabled: false, minutes: 60, bundle_id: 0 },
      form: this.$inertia.form({ name: this.holiday.name, date: this.holiday.date, theme: this.holiday.theme, is_enabled: this.holiday.enabled, force_include: this.holiday.forced, date_confirmed: this.holiday.confirmed, note: this.holiday.note || '' }),
    };
  },
  computed: {
    bundleList() { return this.bundles.filter(b => b.id); },
    bundleFilterOptions() { return this.bundles.map(b => ({ value: b.id, label: b.name })); },
    sendOptions() { return this.bundles.map(b => ({ value: b.id, label: b.name + ' (' + this.$t(':n people', { n: b.people }) + ')' })); },
    sendInfo() { return this.bundles.find(b => b.id === Number(this.sendBundle)) || null; },
    statusOptions() { return [{ value: 'Sent', label: this.$t('Sent') }, { value: 'Failed', label: this.$t('Failed') }, { value: 'wait', label: this.$t('Waiting') }]; },
    channelOptions() { return [{ value: 'email', label: this.$t('Email') }, { value: 'sms', label: this.$t('SMS') }]; },
    vars() { const p = this.holiday.palette || {}; return { '--p': p.primary, '--s': p.secondary, '--a': p.accent, '--soft': p.soft }; },
    emoji() { return this.holiday.emoji; },
    locked() { return this.holiday.locked || !this.can.prepare; },
    dirty() { return ['subject', 'headline', 'body', 'sms'].some(k => (this.msg[k] || '').trim() !== (this.template[k] || '').trim()); },
    people() { return new Set([...this.lists.email, ...this.lists.sms].filter(r => !r.excluded).map(r => r.id)).size; },
    freshCount() { return [...this.lists.email, ...this.lists.sms].filter(r => !r.excluded && !r.delivery).length; },
    sentRows() { return [...this.lists.email.map(r => ({ ...r, channel: 'email' })), ...this.lists.sms.map(r => ({ ...r, channel: 'sms' }))].filter(r => r.delivery); },
    deliveryRows() {
      const q = this.find.delivery.trim().toLowerCase();
      return this.sentRows.filter(r => (!this.fChannel || r.channel === this.fChannel)
        && (!this.fStatus || (this.fStatus === 'wait' ? ['Queued', 'Sending'].includes(r.delivery.status) : r.delivery.status === this.fStatus))
        && (!q || (r.full + ' ' + r.address).toLowerCase().includes(q)));
    },
    tabs() {
      return [
        { key: 'message', label: this.$t('Message'), icon: 'edit', tone: 'rose' },
        { key: 'people', label: this.$t('Who receives it'), icon: 'users', tone: 'navy', count: this.lists.counts.email + this.lists.counts.sms },
        { key: 'delivery', label: this.$t('Delivery'), icon: 'tick', tone: 'emerald', count: this.summary.total || null },
      ];
    },
    phaseText() {
      return { upcoming: this.$t('Coming'), reminded: this.$t('Reminder sent'), sending: this.$t('Sending'), sent: this.$t('Greetings sent'), partly: this.$t('Sent, some failed'), missed: this.$t('Not sent'), weekend: this.$t('Weekend, not counted'), off: this.$t('Switched off') }[this.holiday.phase] || '';
    },
  },
  watch: {
    'photo.kind': { handler() { this.queuePreview(); } },
    'photo.thumb': { handler() { this.queuePreview(); } },
    msg: { deep: true, handler() { this.queuePreview(); } },
    sampleP: { deep: true, handler() { this.queuePreview(); } },
    'summary.pending': { immediate: true, handler(n) { this.setPolling(n > 0); } },
  },
  created() { this.sampleP = this.sample ? { ...this.sample } : { title: '', name: '' }; },
  mounted() { this.loadPreview(); },
  beforeUnmount() { this.setPolling(false); clearTimeout(this.previewTimer); },
  methods: {
    pct(n) { return this.summary.total ? Math.round((n / this.summary.total) * 100) : 0; },
    shown(ch) {
      const q = this.find[ch].trim().toLowerCase();
      const b = Number(this.showBundle) || 0;
      return this.lists[ch].filter(r => (!b || (r.bundles || []).includes(b)) && (!q || (r.full + ' ' + r.address + ' ' + (r.group || '')).toLowerCase().includes(q)));
    },
    indClass(s) { return { Sent: 'is-sent', Failed: 'is-failed', Queued: 'is-wait', Sending: 'is-wait' }[s] || 'is-none'; },
    indText(s) { return { Sent: this.$t('Sent'), Failed: this.$t('Failed'), Queued: this.$t('Waiting'), Sending: this.$t('Sending') }[s] || s; },
    queuePreview() { clearTimeout(this.previewTimer); this.previewTimer = setTimeout(this.loadPreview, 450); },
    async loadPreview() {
      const id = ++this.reqId;
      try {
        const r = await window.axios.post(route('holiday-greetings.send.preview', this.holiday.id), { ...this.msg, title: this.sampleP.title, name: this.sampleP.name });
        if (id === this.reqId) this.preview = r.data;
      } catch (e) { /* the preview is a convenience: the page works without it */ }
    },
    insert(token) {
      const el = document.activeElement && (document.activeElement === this.$refs.sms || document.activeElement === this.$refs.body) ? document.activeElement : this.$refs.body;
      const key = el === this.$refs.sms ? 'sms' : 'body';
      const at = el.selectionStart ?? this.msg[key].length;
      this.msg[key] = this.msg[key].slice(0, at) + token + this.msg[key].slice(el.selectionEnd ?? at);
      this.$nextTick(() => { el.focus(); el.setSelectionRange(at + token.length, at + token.length); });
    },
    only() { return { preserveScroll: true, preserveState: true, only: ['holiday', 'lists', 'summary', 'template', 'defaults', 'bundles', 'auto', 'sentTo'] }; },
    saveMessage() {
      this.busy = true;
      router.put(route('holiday-greetings.send.template', this.holiday.id), this.msg, { ...this.only(), onFinish: () => { this.busy = false; } });
    },
    async resetMessage() {
      if (!(await this.$confirm(this.$t('Put the WIMS message back? Your own wording for this holiday is dropped.')))) return;
      this.busy = true;
      router.post(route('holiday-greetings.send.reset', this.holiday.id), {}, { ...this.only(), onSuccess: () => { this.msg = { ...this.defaults }; }, onFinish: () => { this.busy = false; } });
    },
    leaveOut(channel, rows, excluded) {
      if (!rows.length) return;
      router.post(route('holiday-greetings.send.exclude', this.holiday.id), { channel, ids: rows.map(r => r.id), excluded }, this.only());
    },
    openSend() {
      if (!this.bundles.some(b => b.id === Number(this.sendBundle))) this.sendBundle = 0;
      this.sendOpen = true;
    },
    send() {
      if (!this.sendInfo || !this.sendInfo.fresh) return;
      this.busy = true;
      router.post(route('holiday-greetings.send.start', this.holiday.id), { bundle_id: Number(this.sendBundle) || 0 }, { preserveScroll: true, onSuccess: () => { this.sendOpen = false; this.tab = 'delivery'; }, onError: () => { this.sendOpen = false; }, onFinish: () => { this.busy = false; } });
    },
    humanMinutes(m) {
      if (m === 0) return this.$t('at the start of the holiday');
      if (m % 1440 === 0) return m / 1440 + ' ' + (m / 1440 === 1 ? this.$t('day') : this.$t('days'));
      if (m % 60 === 0) return m / 60 + ' ' + (m / 60 === 1 ? this.$t('hour') : this.$t('hours'));
      return m + ' ' + this.$t('minutes');
    },
    openAuto() {
      this.autoForm = { enabled: this.auto.enabled, minutes: this.auto.minutes, bundle_id: this.auto.bundle_id || 0 };
      this.autoOpen = true;
    },
    saveAuto() {
      this.busy = true;
      router.put(route('holiday-greetings.send.auto', this.holiday.id), { enabled: this.autoForm.enabled, minutes: this.autoForm.minutes, bundle_id: this.autoForm.bundle_id || 0 }, { preserveScroll: true, onSuccess: () => { this.autoOpen = false; }, onFinish: () => { this.busy = false; } });
    },
    followSettings() {
      this.busy = true;
      router.put(route('holiday-greetings.send.auto', this.holiday.id), { follow: true }, { preserveScroll: true, onSuccess: () => { this.autoOpen = false; }, onFinish: () => { this.busy = false; } });
    },
    retryAll() { this.busy = true; router.post(route('holiday-greetings.send.retry-failed', this.holiday.id), {}, { ...this.only(), onFinish: () => { this.busy = false; } }); },
    retryOne(r) { router.post(route('holiday-greetings.send.retry-one', { holiday: this.holiday.id, delivery: r.delivery.id }), {}, this.only()); },
    setPolling(on) {
      if (on && !this.poll) {
        this.poll = setInterval(() => router.reload({ only: ['holiday', 'lists', 'summary', 'bundles', 'sentTo'], preserveScroll: true, preserveState: true }), 2500);
      } else if (!on && this.poll) { clearInterval(this.poll); this.poll = null; }
    },
    openEdit() {
      this.form.defaults({ name: this.holiday.name, date: this.holiday.date, theme: this.holiday.theme, is_enabled: this.holiday.enabled, force_include: this.holiday.forced, date_confirmed: this.holiday.confirmed, note: this.holiday.note || '' }).reset();
      this.form.clearErrors();
      this.editOpen = true;
    },
    saveHoliday() { this.form.put(route('holiday-greetings.holidays.update', this.holiday.id), { preserveScroll: true, onSuccess: () => { this.editOpen = false; } }); },
    quickInclude() {
      const h = this.holiday;
      router.put(route('holiday-greetings.holidays.update', h.id), { name: h.name, date: h.date, theme: h.theme, is_enabled: true, force_include: true, date_confirmed: h.confirmed, note: h.note || '' }, { preserveScroll: true });
    },
    async removeHoliday() {
      if (!(await this.$confirm(this.$t('Remove :n from the holidays?', { n: this.holiday.name })))) return;
      this.form.delete(route('holiday-greetings.holidays.destroy', this.holiday.id));
    },
  },
};
</script>

<style scoped>
.hg-sac { width: 200px; flex: 0 0 auto; }
.hg-autochip { display: inline-flex; align-items: center; gap: 6px; margin-top: 6px; padding: 4px 12px; border-radius: 999px; background: rgba(124, 58, 237, 0.1); color: #6d28d9; font-size: 0.78rem; font-weight: 800; }
.hg-sendinfo { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; margin-top: 4px; }
.hg-sendinfo > div { display: grid; gap: 2px; padding: 10px 12px; border-radius: 14px; background: var(--lg-soft); text-align: center; box-shadow: 0 6px 14px -8px rgba(15, 23, 42, 0.3); }
.hg-sendinfo b { font-size: 1.3rem; color: var(--lg-ink); }
.hg-sendinfo span { font-size: 0.7rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute); }
.hg-sel { padding: 8px 14px; border-radius: 999px; border: 1px solid var(--lg-line); background: var(--lg-card); color: var(--lg-ink); font: inherit; }
.hg-check { display: flex; align-items: center; gap: 8px; font-weight: 600; text-transform: none; letter-spacing: 0; }
.hg-check input { width: 18px; height: 18px; flex: none; padding: 0; }
</style>

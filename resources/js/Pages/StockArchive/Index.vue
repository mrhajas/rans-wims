<template>
  <admin-layout :title="$t('Stock Archive')">
    <div class="lg-page px-4 md:px-0 sa-page">
      <page-header icon="archive-box" :title="$t('Stock Archive')" :subtitle="$t('A kept record of the whole company\'s stock: every warehouse and project, by category.')">
        <button v-if="can.create" type="button" class="lg-btn lg-btn-primary" :disabled="making" @click="generate"><icons name="plus" class="w-4 h-4" />{{ making ? $t('Making the report...') : $t('Make a report now') }}</button>
      </page-header>

      <stat-tiles :items="tiles" />

      <!-- a wipe-off is waiting for a second Super Admin -->
      <section v-if="can.super && wipe" class="sa-wipe" role="status">
        <span class="sa-wipe-ico"><icons name="lock-closed" class="w-6 h-6" /></span>
        <div class="sa-wipe-main">
          <h3>{{ $t('A wipe-off of :n deleted entries is waiting for a second Super Admin', { n: wipe.count }) }}</h3>
          <p>{{ $t('Started by :name on :at. Wiping removes the entries and their history for good, so a different Super Admin must confirm it with their password.', { name: wipe.by, at: $datetime(wipe.at) }) }}</p>
          <div class="sa-wipe-refs"><span v-for="r in wipe.refs" :key="r">{{ r }}</span><span v-if="wipe.more" class="is-more">+ {{ wipe.more }}</span></div>
        </div>
        <div class="sa-wipe-act">
          <button v-if="!wipe.by_me" type="button" class="lg-btn tone tone-solid" style="--tone: #dc2626" @click="openConfirm"><span class="tone-ico"><icons name="trash" class="w-4 h-4" /></span>{{ $t('Confirm and wipe off') }}</button>
          <span v-else class="sa-wipe-wait"><icons name="clock" class="w-4 h-4" />{{ $t('Waiting for another Super Admin') }}</span>
          <button type="button" class="lg-btn lg-btn-soft" @click="cancelWipe">{{ wipe.by_me ? $t('Cancel the wipe-off') : $t('Turn it down') }}</button>
        </div>
      </section>

      <!-- the entries -->
      <section class="lg-card">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-sky"><icons name="archive-box" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Kept reports') }}</h2>
            <p class="lg-card-sub">{{ scheduleText }}</p>
          </div>
        </header>

        <div class="sa-filters">
          <label class="sa-search"><span>{{ $t('Search') }}</span>
            <span class="sa-searchbox"><icons name="search" class="w-4 h-4" /><input v-model="f.search" type="text" :placeholder="$t('Reference or person')" @input="soon" /></span>
          </label>
          <label><span>{{ $t('Status') }}</span>
            <css-select v-model="f.status" class="w-full" @change="go"><option value="">{{ $t('All') }}</option><option value="active">{{ $t('Kept') }}</option><option value="deleted">{{ $t('Deleted') }}</option></css-select>
          </label>
          <label><span>{{ $t('Made by') }}</span>
            <css-select v-model="f.kind" class="w-full" @change="go"><option value="">{{ $t('Everyone') }}</option><option value="Scheduled">{{ $t('WIMS (every week)') }}</option><option value="Manual">{{ $t('A person') }}</option></css-select>
          </label>
          <label><span>{{ $t('From') }}</span><date-picker v-model="f.from" @change="go" flush /></label>
          <label><span>{{ $t('To') }}</span><date-picker v-model="f.to" @change="go" flush /></label>
          <button v-if="hasFilters" type="button" class="sa-reset" @click="reset"><icons name="cross" class="w-3.5 h-3.5" />{{ $t('Reset') }}</button>
        </div>

        <div v-if="can.super" class="sa-bulk" :class="{ 'is-on': sel.length || allMode }">
          <span class="sa-bulk-info">
            <icons name="tick" class="w-4 h-4" />
            <template v-if="allMode">{{ $t('All :n entries of this list are checked', { n: archives.total }) }}</template>
            <template v-else-if="sel.length">{{ $t(':n checked', { n: sel.length }) }}<template v-if="selDeleted.length && selKept.length"> ({{ selKept.length }} {{ $t('kept') }}, {{ selDeleted.length }} {{ $t('deleted') }})</template></template>
            <template v-else>{{ $t('Tick entries to delete or wipe them off several at once.') }}</template>
          </span>
          <button v-if="!allMode && sel.length && sel.length === archives.data.length && archives.total > archives.data.length && f.status" type="button" class="sa-link" @click="allMode = true">{{ $t('Check all :n in this list', { n: archives.total }) }}</button>
          <button type="button" class="sa-link" @click="checkAll">{{ allChecked ? $t('Uncheck all') : $t('Check all') }}</button>
          <span class="sa-bulk-gap"></span>
          <button type="button" class="lg-btn tone" style="--tone: #be123c" :disabled="!bulkKeptCount" @click="askBulkDelete"><span class="tone-ico"><icons name="trash" class="w-4 h-4" /></span>{{ $t('Delete checked') }}<b v-if="bulkKeptCount" class="sa-count">{{ bulkKeptCount }}</b></button>
          <button type="button" class="lg-btn tone tone-solid" style="--tone: #7f1d1d" :disabled="!!wipe || !stats.deleted" :title="wipe ? $t('A wipe-off is already waiting for a second Super Admin') : ''" @click="askWipe"><span class="tone-ico"><icons name="lock-closed" class="w-4 h-4" /></span>{{ $t('Wipe off deleted entries') }}<b v-if="bulkDeletedCount" class="sa-count">{{ bulkDeletedCount }}</b></button>
        </div>

        <div class="lg-table-wrap">
          <table class="lg-table lg-responsive sa-table">
            <thead>
              <tr>
                <th v-if="can.super" class="sa-ck"><label class="sa-box" :title="$t('Check all on this page')"><input type="checkbox" :checked="allChecked" :indeterminate.prop="someChecked" @change="checkAll" /><span><icons name="tick" class="w-3 h-3" /></span></label></th>
                <th>{{ $t('Report') }}</th><th>{{ $t('Made') }}</th><th>{{ $t('Made by') }}</th><th>{{ $t('Contents') }}</th><th>{{ $t('Size') }}</th><th>{{ $t('E-mailed') }}</th><th>{{ $t('Status') }}</th><th class="sa-actions-th">{{ $t('Actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="a in archives.data" :key="a.id" :class="{ 'sa-row-deleted': a.deleted, 'sa-row-on': isChecked(a.id) }">
                <td v-if="can.super" class="sa-ck" :data-label="$t('Check')"><label class="sa-box"><input type="checkbox" :checked="isChecked(a.id)" :aria-label="$t('Check :ref', { ref: a.reference })" @change="toggle(a.id)" /><span><icons name="tick" class="w-3 h-3" /></span></label></td>
                <td :data-label="$t('Report')">
                  <b class="sa-ref">{{ a.reference }}</b>
                  <small class="sa-sub">{{ a.title }}</small>
                </td>
                <td :data-label="$t('Made')" class="sa-nowrap">{{ $datetime(a.generated_at) }}</td>
                <td :data-label="$t('Made by')">
                  <span class="lg-chip" :class="a.by_wims ? 'lg-chip-indigo' : 'lg-chip-sky'" style="text-transform: none"><icons :name="a.by_wims ? 'sparkles' : 'user'" class="w-3 h-3" />{{ a.by }}</span>
                </td>
                <td :data-label="$t('Contents')"><b>{{ count(a.warehouses) }}</b> {{ $t('warehouses / projects') }}<small class="sa-sub">{{ count(a.items) }} {{ $t('items') }} · TZS {{ money(a.value) }}</small></td>
                <td :data-label="$t('Size')" class="sa-nowrap">{{ a.deleted ? '-' : size(a.size) }}</td>
                <td :data-label="$t('E-mailed')" class="sa-nowrap"><template v-if="a.emailed_at">{{ $datetime(a.emailed_at) }}<small class="sa-sub">{{ $t(':n address(es)', { n: a.emailed_to }) }}</small></template><template v-else>-</template></td>
                <td :data-label="$t('Status')">
                  <span v-if="!a.deleted" class="lg-chip lg-chip-green" style="text-transform: none"><icons name="tick" class="w-3 h-3" />{{ $t('Kept') }}</span>
                  <template v-else>
                    <span class="lg-chip lg-chip-red" style="text-transform: none"><icons name="trash" class="w-3 h-3" />{{ $t('Deleted') }}</span>
                    <small class="sa-sub sa-red">{{ a.deleted_by }} · {{ $datetime(a.deleted_at) }}<template v-if="a.deleted_reason"><br />{{ a.deleted_reason }}</template></small>
                  </template>
                </td>
                <td :data-label="$t('Actions')" class="sa-actions">
                  <a v-if="!a.deleted" :href="route('stock-archives.preview', a.id)" target="_blank" rel="noopener" class="sa-ib is-sky lg-tip" :data-tip="$t('Preview in a new tab')" :aria-label="$t('Preview in a new tab')"><icons name="eye" class="w-4 h-4" /></a>
                  <a v-if="!a.deleted" :href="route('stock-archives.download', a.id)" class="sa-ib is-green lg-tip" :data-tip="$t('Download the PDF')" :aria-label="$t('Download the PDF')"><icons name="download" class="w-4 h-4" /></a>
                  <button type="button" class="sa-ib is-violet lg-tip" :data-tip="$t('History: what happened to it')" :aria-label="$t('History')" @click="openHistory(a)"><icons name="clock" class="w-4 h-4" /><span v-if="a.views" class="sa-badge">{{ a.views }}</span></button>
                  <button v-if="can.super && !a.deleted" type="button" class="sa-ib is-amber lg-tip" :data-tip="$t('Send it by e-mail again')" :aria-label="$t('Send by e-mail')" @click="askEmail(a)"><icons name="mail" class="w-4 h-4" /></button>
                  <button v-if="can.super && !a.deleted" type="button" class="sa-ib is-red lg-tip" :data-tip="$t('Delete (password needed)')" :aria-label="$t('Delete')" @click="askDelete(a)"><icons name="trash" class="w-4 h-4" /></button>
                </td>
              </tr>
              <tr v-if="!archives.data.length" class="lg-empty-row"><td class="lg-empty" :colspan="can.super ? 9 : 8">{{ hasFilters ? $t('Nothing matches these filters.') : $t('No report has been kept yet. WIMS makes one every week; you can also make one now.') }}</td></tr>
            </tbody>
          </table>
        </div>
        <nav v-if="archives.last_page > 1" class="sa-pager" :aria-label="$t('Pages')">
          <template v-for="(l, i) in archives.links" :key="i">
            <span v-if="!l.url" class="sa-pg is-off" v-html="clean(l.label)"></span>
            <Link v-else :href="l.url" preserve-scroll class="sa-pg" :class="{ 'is-on': l.active }" v-html="clean(l.label)" />
          </template>
        </nav>
      </section>

      <!-- settings: Super Admin only -->
      <section v-if="can.super && form" class="lg-card" id="sa-settings">
        <header class="lg-card-head">
          <span class="lg-card-ico lg-ico-violet"><icons name="cog" class="w-5 h-5" /></span>
          <div class="min-w-0 flex-1">
            <h2 class="lg-card-title">{{ $t('Settings') }}</h2>
            <p class="lg-card-sub">{{ $t('When the report is made, who receives it and how long it is kept. Super Admins only.') }}</p>
          </div>
          <button type="button" class="lg-btn tone tone-solid" style="--tone: #4f46e5" :disabled="form.processing" @click="save"><span class="tone-ico"><icons name="tick" class="w-4 h-4" /></span>{{ $t('Save settings') }}</button>
        </header>
        <div class="sa-set">
          <label class="sa-switch" :class="{ 'is-on': form.enabled }">
            <input v-model="form.enabled" type="checkbox" />
            <span class="sa-switch-ui" aria-hidden="true"></span>
            <span><b>{{ $t('Make the report every week') }}</b><small>{{ form.enabled ? $t('WIMS makes it by itself on the day and time below.') : $t('Switched off: nothing is made by itself (you can still make one by hand).') }}</small></span>
          </label>
          <div class="sa-grid">
            <label class="sa-field"><span>{{ $t('Day') }}</span>
              <css-select v-model="form.send_day" class="w-full"><option v-for="(d, i) in days" :key="i" :value="i">{{ d }}</option></css-select>
            </label>
            <label class="sa-field"><span>{{ $t('Time (East Africa Time)') }}</span><input v-model="form.send_time" type="time" class="sa-input" /></label>
            <label class="sa-field"><span>{{ $t('Keep every report for (days)') }}</span><input v-model.number="form.keep_days" type="number" min="0" max="3650" class="sa-input" /><small>{{ $t('0 = keep for ever. After this many days the report and its file are removed; its history stays.') }}</small></label>
          </div>
          <label class="sa-switch" :class="{ 'is-on': form.email }">
            <input v-model="form.email" type="checkbox" />
            <span class="sa-switch-ui" aria-hidden="true"></span>
            <span><b>{{ $t('E-mail the report with the PDF attached') }}</b><small>{{ $t(':n address(es) will receive it now', { n: recipientCount }) }}</small></span>
          </label>

          <h3 class="sa-sub-h">{{ $t('Who receives it') }}</h3>
          <p class="sa-hint">{{ $t('Choose whole roles, single people, or type any e-mail address. Anyone you do not choose does not get it.') }}</p>
          <div class="sa-chips"><label v-for="r in roles" :key="r" class="sa-chip" :class="{ on: form.role_names.includes(r) }"><input v-model="form.role_names" type="checkbox" :value="r" />{{ r }}</label></div>
          <div class="sa-people">
            <span v-for="u in people" :key="u.id" class="sa-person">{{ u.name }}<button type="button" :aria-label="$t('Remove')" @click="form.user_ids = form.user_ids.filter(x => x !== u.id)"><icons name="cross" class="w-3 h-3" /></button></span>
            <css-select v-model="pick" class="sa-pick" @change="pickUser"><option value="">{{ $t('Add a person...') }}</option><option v-for="u in userOptions" :key="u.id" :value="u.id">{{ u.name }}</option></css-select>
          </div>
          <div class="sa-people">
            <span v-for="e in form.extra_emails" :key="e" class="sa-person is-mail">{{ e }}<button type="button" :aria-label="$t('Remove')" @click="form.extra_emails = form.extra_emails.filter(x => x !== e)"><icons name="cross" class="w-3 h-3" /></button></span>
            <input v-model="newEmail" type="email" class="sa-input sa-mail" :placeholder="$t('Add an e-mail address, then press Enter')" @keydown.enter.prevent="addEmail" @blur="addEmail" />
          </div>
          <p v-if="mailError" class="sa-err">{{ mailError }}</p>
        </div>
      </section>

      <details v-if="can.super && wipes && wipes.length" class="sa-wipes">
        <summary>{{ $t('Wipe-off history') }} <small>{{ wipes.length }}</small></summary>
        <ul>
          <li v-for="w in wipes" :key="w.id">
            <span class="lg-chip" :class="{ 'lg-chip-green': w.status === 'completed', 'lg-chip-amber': w.status === 'cancelled', 'lg-chip-red': w.status === 'rejected' }" style="text-transform: none">{{ { completed: $t('Wiped off'), cancelled: $t('Cancelled'), rejected: $t('Turned down') }[w.status] }}</span>
            <b>{{ w.count }}</b> {{ $t('entries') }} · {{ $t('started by') }} {{ w.by }} · {{ $datetime(w.at) }}<template v-if="w.decided_by"> · {{ w.status === 'completed' ? $t('confirmed by') : $t('decided by') }} {{ w.decided_by }}</template>
            <small>{{ w.refs.join(', ') }}<template v-if="w.more"> + {{ w.more }}</template></small>
          </li>
        </ul>
      </details>

      <!-- history -->
      <tec-dialog-modal :show="hist.open" max-width="2xl" @close="hist.open = false">
        <template #title><span class="sa-dlg-title"><icons name="clock" class="w-5 h-5" />{{ $t('History') }} <b v-if="hist.entry">{{ hist.entry.reference }}</b></span></template>
        <template #content>
          <p v-if="hist.loading" class="sa-hint">{{ $t('Loading...') }}</p>
          <template v-else-if="hist.entry">
            <div class="sa-sum" :class="{ 'is-deleted': hist.entry.deleted }">
              <span><b>{{ $datetime(hist.entry.generated_at) }}</b>{{ $t('made by') }} {{ hist.entry.by }}</span>
              <span>{{ count(hist.entry.warehouses) }} {{ $t('warehouses / projects') }} · {{ count(hist.entry.items) }} {{ $t('items') }}</span>
              <span v-if="!hist.entry.deleted">{{ size(hist.entry.size) }}</span>
              <span v-else class="sa-red"><icons name="trash" class="w-3.5 h-3.5" /> {{ $t('Deleted by') }} {{ hist.entry.deleted_by }}, {{ $datetime(hist.entry.deleted_at) }}</span>
            </div>
            <ol class="sa-tl">
              <li v-for="e in hist.events" :key="e.id" :class="['is-' + e.action]">
                <span class="sa-tl-dot"><icons :name="eventIcon(e.action)" class="w-3.5 h-3.5" /></span>
                <div>
                  <b>{{ eventLabel(e.action) }}</b> <span class="sa-by">{{ $t('by') }} {{ e.by }}</span>
                  <small>{{ $datetime(e.at) }}<template v-if="e.note"> · {{ e.note }}</template></small>
                </div>
              </li>
              <li v-if="!hist.events.length"><div><small>{{ $t('Nothing yet.') }}</small></div></li>
            </ol>
          </template>
        </template>
        <template #footer><button type="button" class="lg-btn lg-btn-soft" @click="hist.open = false">{{ $t('Close') }}</button></template>
      </tec-dialog-modal>

      <!-- delete (password) -->
      <tec-dialog-modal :show="del.open" max-width="md" @close="del.open = false">
        <template #title><span class="sa-dlg-title is-red"><icons name="lock-closed" class="w-5 h-5" />{{ $t('Delete this report') }}</span></template>
        <template #content>
          <p class="sa-hint">{{ $t('The PDF file of :ref is removed for good. The entry stays in the list, marked red, with its history of who made, opened and deleted it.', { ref: del.entry ? del.entry.reference : '' }) }}</p>
          <input v-model="del.reason" type="text" maxlength="120" class="sa-input" :placeholder="$t('Reason (optional)')" />
          <input v-model="del.password" type="password" class="sa-input" autocomplete="current-password" :placeholder="$t('Enter your password to confirm')" @keyup.enter="confirmDelete" />
          <p v-if="del.error" class="sa-err">{{ del.error }}</p>
        </template>
        <template #footer>
          <button type="button" class="lg-btn lg-btn-soft" @click="del.open = false">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn tone tone-solid" style="--tone: #dc2626" :disabled="!del.password || busy" @click="confirmDelete"><span class="tone-ico"><icons name="trash" class="w-4 h-4" /></span>{{ $t('Delete') }}</button>
        </template>
      </tec-dialog-modal>

      <!-- delete several -->
      <tec-dialog-modal :show="bulk.open" max-width="md" @close="bulk.open = false">
        <template #title><span class="sa-dlg-title is-red"><icons name="lock-closed" class="w-5 h-5" />{{ $t('Delete :n checked reports', { n: bulkKeptCount }) }}</span></template>
        <template #content>
          <p class="sa-hint">{{ $t('The PDF files are removed for good. The entries stay in the list, marked red, with their history.') }}</p>
          <input v-model="bulk.reason" type="text" maxlength="120" class="sa-input" :placeholder="$t('Reason (optional)')" />
          <input v-model="bulk.password" type="password" class="sa-input" autocomplete="current-password" :placeholder="$t('Enter your password to confirm')" @keyup.enter="confirmBulk" />
          <p v-if="bulk.error" class="sa-err">{{ bulk.error }}</p>
        </template>
        <template #footer>
          <button type="button" class="lg-btn lg-btn-soft" @click="bulk.open = false">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn tone tone-solid" style="--tone: #dc2626" :disabled="!bulk.password || busy" @click="confirmBulk"><span class="tone-ico"><icons name="trash" class="w-4 h-4" /></span>{{ $t('Delete') }}</button>
        </template>
      </tec-dialog-modal>

      <!-- wipe off: first Super Admin -->
      <tec-dialog-modal :show="wp.open" max-width="md" @close="wp.open = false">
        <template #title><span class="sa-dlg-title is-red"><icons name="lock-closed" class="w-5 h-5" />{{ $t('Wipe off deleted entries') }}</span></template>
        <template #content>
          <p class="sa-hint"><b>{{ wipeCount }}</b> {{ $t('deleted entries will be wiped off for good: the entries and their history are removed.') }}</p>
          <p class="sa-hint">{{ $t('This needs two Super Admins. You start it with your password; then a different Super Admin must confirm it with theirs before anything is removed.') }}</p>
          <input v-model="wp.password" type="password" class="sa-input" autocomplete="current-password" :placeholder="$t('Enter your password to start')" @keyup.enter="confirmWipeStart" />
          <p v-if="wp.error" class="sa-err">{{ wp.error }}</p>
        </template>
        <template #footer>
          <button type="button" class="lg-btn lg-btn-soft" @click="wp.open = false">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn tone tone-solid" style="--tone: #7f1d1d" :disabled="!wp.password || busy || !wipeCount" @click="confirmWipeStart"><span class="tone-ico"><icons name="lock-closed" class="w-4 h-4" /></span>{{ $t('Start the wipe-off') }}</button>
        </template>
      </tec-dialog-modal>

      <!-- wipe off: second Super Admin -->
      <tec-dialog-modal :show="wc.open" max-width="md" @close="wc.open = false">
        <template #title><span class="sa-dlg-title is-red"><icons name="lock-closed" class="w-5 h-5" />{{ $t('Confirm the wipe-off') }}</span></template>
        <template #content>
          <p class="sa-hint">{{ $t(':n deleted entries started by :name will be removed for good, with their history. This cannot be undone.', { n: wipe ? wipe.count : 0, name: wipe ? wipe.by : '' }) }}</p>
          <input v-model="wc.password" type="password" class="sa-input" autocomplete="current-password" :placeholder="$t('Enter your password to confirm')" @keyup.enter="confirmWipe" />
          <p v-if="wc.error" class="sa-err">{{ wc.error }}</p>
        </template>
        <template #footer>
          <button type="button" class="lg-btn lg-btn-soft" @click="wc.open = false">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn tone tone-solid" style="--tone: #7f1d1d" :disabled="!wc.password || busy" @click="confirmWipe"><span class="tone-ico"><icons name="trash" class="w-4 h-4" /></span>{{ $t('Wipe off for good') }}</button>
        </template>
      </tec-dialog-modal>

      <!-- send again -->
      <tec-dialog-modal :show="mail.open" max-width="md" @close="mail.open = false">
        <template #title><span class="sa-dlg-title"><icons name="mail" class="w-5 h-5" />{{ $t('Send by e-mail') }}</span></template>
        <template #content>
          <p class="sa-hint">{{ $t('Leave the address empty to send :ref to everybody chosen in the settings, or type one address.', { ref: mail.entry ? mail.entry.reference : '' }) }}</p>
          <input v-model="mail.to" type="email" class="sa-input" :placeholder="$t('One e-mail address (optional)')" @keyup.enter="confirmEmail" />
        </template>
        <template #footer>
          <button type="button" class="lg-btn lg-btn-soft" @click="mail.open = false">{{ $t('Cancel') }}</button>
          <button type="button" class="lg-btn lg-btn-primary" :disabled="busy" @click="confirmEmail"><icons name="mail" class="w-4 h-4" />{{ $t('Send') }}</button>
        </template>
      </tec-dialog-modal>
    </div>
  </admin-layout>
</template>

<script>
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import StatTiles from '@/Shared/Page/StatTiles.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';

export default {
  components: { AdminLayout, PageHeader, StatTiles, TecDialogModal, Link },
  props: { archives: Object, filters: Object, stats: Object, schedule: Object, can: Object, config: Object, users: Array, roles: Array, recipientCount: Number, wipe: Object, wipes: Array },
  data() {
    return {
      f: { search: '', status: '', kind: '', from: '', to: '', ...(this.filters || {}) },
      timer: null,
      making: false,
      busy: false,
      form: this.config ? this.$inertia.form({ ...this.config, extra_emails: [...this.config.extra_emails], role_names: [...this.config.role_names], user_ids: [...this.config.user_ids] }) : null,
      pick: '',
      newEmail: '',
      mailError: '',
      hist: { open: false, loading: false, entry: null, events: [] },
      del: { open: false, entry: null, password: '', reason: '', error: '' },
      mail: { open: false, entry: null, to: '' },
      sel: [],
      allMode: false,
      bulk: { open: false, password: '', reason: '', error: '' },
      wp: { open: false, password: '', error: '' },
      wc: { open: false, password: '', error: '' },
    };
  },
  computed: {
    days() {
      return [this.$t('Sunday'), this.$t('Monday'), this.$t('Tuesday'), this.$t('Wednesday'), this.$t('Thursday'), this.$t('Friday'), this.$t('Saturday')];
    },
    hasFilters() {
      return !!(this.f.search || this.f.status || this.f.kind || this.f.from || this.f.to);
    },
    tiles() {
      return [
        { label: this.$t('Reports kept'), value: this.stats.entries, icon: 'archive-box', tone: 'sky' },
        { label: this.$t('Disk used'), value: this.size(this.stats.size), icon: 'collection', tone: 'emerald' },
        { label: this.$t('Last report'), value: this.stats.last ? this.$datetime(this.stats.last) : '-', icon: 'clock', tone: 'violet' },
        { label: this.$t('Next report'), value: this.schedule.next ? this.$datetime(this.schedule.next) : this.$t('Not scheduled'), icon: 'calendar', tone: 'amber' },
      ];
    },
    scheduleText() {
      const d = this.days[this.schedule.day];
      const keep = this.schedule.keep_days > 0 ? this.$t('Reports are kept for :n days.', { n: this.schedule.keep_days }) : this.$t('Reports are kept for ever.');
      return (this.schedule.enabled ? this.$t('WIMS makes a report every :day at :time and e-mails it. ', { day: d, time: this.schedule.time }) : this.$t('The weekly report is switched off. ')) + keep + (this.stats.deleted ? ' ' + this.$t(':n deleted entr(ies) keep their history.', { n: this.stats.deleted }) : '');
    },
    rows() {
      return this.archives.data || [];
    },
    selRows() {
      return this.rows.filter(r => this.sel.includes(r.id));
    },
    selKept() {
      return this.selRows.filter(r => !r.deleted);
    },
    selDeleted() {
      return this.selRows.filter(r => r.deleted);
    },
    allChecked() {
      return this.rows.length > 0 && this.rows.every(r => this.sel.includes(r.id));
    },
    someChecked() {
      return this.sel.length > 0 && !this.allChecked;
    },
    // how many a bulk action touches: the checked ones, or every entry of the list when "all" is chosen
    bulkKeptCount() {
      return this.allMode ? (this.f.status === 'active' ? this.archives.total : 0) : this.selKept.length;
    },
    bulkDeletedCount() {
      return this.allMode ? (this.f.status === 'deleted' ? this.archives.total : 0) : this.selDeleted.length;
    },
    // nothing checked: the wipe-off covers every deleted entry of the list shown
    wipeCount() {
      return this.bulkDeletedCount || (this.f.status === 'deleted' ? this.archives.total : this.stats.deleted);
    },
    userOptions() {
      const taken = new Set(this.form ? this.form.user_ids : []);
      return (this.users || []).filter(u => !taken.has(u.id));
    },
    people() {
      return this.form ? this.form.user_ids.map(id => (this.users || []).find(u => u.id === id)).filter(Boolean) : [];
    },
  },
  watch: {
    archives() {
      this.sel = this.sel.filter(id => this.rows.some(r => r.id === id));
    },
  },
  methods: {
    count(n) {
      return Number(n || 0).toLocaleString();
    },
    money(n) {
      return Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
    },
    size(bytes) {
      const b = Number(bytes || 0);
      if (b < 1024) return b + ' B';
      if (b < 1048576) return (b / 1024).toFixed(0) + ' KB';
      return (b / 1048576).toFixed(1) + ' MB';
    },
    clean(label) {
      return String(label).replace('&laquo; Previous', '&lsaquo;').replace('Next &raquo;', '&rsaquo;');
    },
    soon() {
      clearTimeout(this.timer);
      this.timer = setTimeout(this.go, 350);
    },
    go() {
      const q = {};
      Object.entries(this.f).forEach(([k, v]) => v && (q[k] = v));
      this.$inertia.get(this.route('stock-archives.index'), q, { preserveState: true, preserveScroll: true, replace: true, only: ['archives', 'filters'] });
    },
    reset() {
      this.f = { search: '', status: '', kind: '', from: '', to: '' };
      this.go();
    },
    generate() {
      this.making = true;
      this.$inertia.post(this.route('stock-archives.generate'), {}, { preserveScroll: true, onFinish: () => (this.making = false) });
    },
    isChecked(id) {
      return this.sel.includes(id);
    },
    toggle(id) {
      this.allMode = false;
      this.sel = this.isChecked(id) ? this.sel.filter(x => x !== id) : [...this.sel, id];
    },
    checkAll() {
      this.allMode = false;
      this.sel = this.allChecked ? [] : this.rows.map(r => r.id);
    },
    bulkBody(extra = {}) {
      const body = { ...extra };
      if (this.allMode) {
        body.all = 1;
        ['search', 'status', 'kind', 'from', 'to'].forEach(k => this.f[k] && (body[k] = this.f[k]));
      } else {
        body.ids = this.sel;
      }
      return body;
    },
    askBulkDelete() {
      this.bulk = { open: true, password: '', reason: '', error: '' };
    },
    confirmBulk() {
      this.busy = true;
      this.bulk.error = '';
      this.$inertia.post(this.route('stock-archives.bulk-delete'), this.bulkBody({ password: this.bulk.password, reason: this.bulk.reason }), {
        preserveScroll: true,
        onSuccess: () => { this.bulk.open = false; this.sel = []; this.allMode = false; },
        onError: errors => (this.bulk.error = errors.password || Object.values(errors)[0] || this.$t('It could not be deleted.')),
        onFinish: () => (this.busy = false),
      });
    },
    askWipe() {
      this.wp = { open: true, password: '', error: '' };
    },
    confirmWipeStart() {
      this.busy = true;
      this.wp.error = '';
      // checked deleted entries, or (nothing checked) every deleted entry of the list shown
      const body = this.selDeleted.length && !this.allMode ? { ids: this.selDeleted.map(r => r.id) } : { all: 1, search: this.f.search || undefined, kind: this.f.kind || undefined, from: this.f.from || undefined, to: this.f.to || undefined };
      this.$inertia.post(this.route('stock-archives.wipes.start'), { ...body, password: this.wp.password }, {
        preserveScroll: true,
        onSuccess: () => { this.wp.open = false; this.sel = []; this.allMode = false; },
        onError: errors => (this.wp.error = errors.password || Object.values(errors)[0] || this.$t('It could not be started.')),
        onFinish: () => (this.busy = false),
      });
    },
    openConfirm() {
      this.wc = { open: true, password: '', error: '' };
    },
    confirmWipe() {
      this.busy = true;
      this.wc.error = '';
      this.$inertia.post(this.route('stock-archives.wipes.confirm', this.wipe.id), { password: this.wc.password }, {
        preserveScroll: true,
        onSuccess: () => (this.wc.open = false),
        onError: errors => (this.wc.error = errors.password || Object.values(errors)[0] || this.$t('It could not be confirmed.')),
        onFinish: () => (this.busy = false),
      });
    },
    cancelWipe() {
      this.$inertia.delete(this.route('stock-archives.wipes.cancel', this.wipe.id), { preserveScroll: true });
    },
    eventLabel(a) {
      return { generated: this.$t('Made'), viewed: this.$t('Opened the history'), previewed: this.$t('Previewed'), downloaded: this.$t('Downloaded'), emailed: this.$t('Sent by e-mail'), email_failed: this.$t('E-mail failed'), deleted: this.$t('Deleted'), wipe_requested: this.$t('Wipe-off started'), wipe_cancelled: this.$t('Wipe-off cancelled'), wipe_rejected: this.$t('Wipe-off turned down'), expired: this.$t('Removed (kept long enough)') }[a] || a;
    },
    eventIcon(a) {
      return { generated: 'plus', viewed: 'eye', previewed: 'eye', downloaded: 'download', emailed: 'mail', email_failed: 'cross', deleted: 'trash', wipe_requested: 'lock-closed', wipe_cancelled: 'cross', wipe_rejected: 'cross', expired: 'clock' }[a] || 'info';
    },
    async openHistory(a) {
      this.hist = { open: true, loading: true, entry: null, events: [] };
      try {
        const { data } = await axios.get(this.route('stock-archives.history', a.id));
        this.hist = { open: true, loading: false, entry: data.entry, events: data.events };
        if (a.views !== undefined) a.views = a.views + 1;
      } catch (e) {
        this.hist.open = false;
      }
    },
    askDelete(a) {
      this.del = { open: true, entry: a, password: '', reason: '', error: '' };
    },
    confirmDelete() {
      this.busy = true;
      this.del.error = '';
      this.$inertia.delete(this.route('stock-archives.destroy', this.del.entry.id), {
        data: { password: this.del.password, reason: this.del.reason },
        preserveScroll: true,
        onSuccess: () => (this.del.open = false),
        onError: errors => (this.del.error = errors.password || Object.values(errors)[0] || this.$t('It could not be deleted.')),
        onFinish: () => (this.busy = false),
      });
    },
    askEmail(a) {
      this.mail = { open: true, entry: a, to: '' };
    },
    confirmEmail() {
      this.busy = true;
      this.$inertia.post(this.route('stock-archives.email', this.mail.entry.id), { to: this.mail.to || null }, { preserveScroll: true, onSuccess: () => (this.mail.open = false), onFinish: () => (this.busy = false) });
    },
    pickUser() {
      if (this.pick && !this.form.user_ids.includes(this.pick)) this.form.user_ids.push(Number(this.pick));
      this.$nextTick(() => (this.pick = ''));
    },
    addEmail() {
      const e = this.newEmail.trim().toLowerCase();
      this.mailError = '';
      if (!e) return;
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e)) {
        this.mailError = this.$t('That is not a valid e-mail address.');
        return;
      }
      if (!this.form.extra_emails.includes(e)) this.form.extra_emails.push(e);
      this.newEmail = '';
    },
    save() {
      this.addEmail();
      this.form.put(this.route('stock-archives.settings'), { preserveScroll: true });
    },
  },
};
</script>

<style scoped>
/* a wide table scrolls inside its own frame instead of widening the whole page */
.sa-page { display: grid; width: 100%; min-width: 0; max-width: 100%; grid-template-columns: minmax(0, 1fr); justify-items: stretch; }
.sa-page > * { min-width: 0; max-width: 100%; width: 100%; }
.sa-page .lg-table-wrap { max-width: 100%; overflow-x: auto; }
.sa-filters { display: grid; grid-template-columns: minmax(0, 1.5fr) repeat(4, minmax(0, 1fr)) auto; gap: 10px; align-items: end; padding: 4px 18px 12px; }
.sa-filters label > span:first-child { display: block; margin-bottom: 4px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.sa-searchbox { display: flex; align-items: center; gap: 8px; height: 44px; padding: 0 12px; border-radius: 14px; background: var(--lg-card); border: 1px solid var(--lg-line); color: #0369a1; }
.sa-searchbox:focus-within { border-color: #38bdf8; box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.2); }
.sa-searchbox input { flex: 1; min-width: 0; border: 0; background: transparent; padding: 0; font-size: 0.88rem; color: var(--lg-ink); box-shadow: none; }
.sa-searchbox input:focus { outline: none; box-shadow: none; }
.sa-reset { display: inline-flex; align-items: center; gap: 4px; height: 44px; min-height: 0; padding: 0 14px; border: 0; border-radius: 14px; font-size: 0.76rem; font-weight: 800; color: #be123c; background: rgba(244, 63, 94, 0.12); cursor: pointer; }
@media (max-width: 960px) { .sa-filters { grid-template-columns: 1fr 1fr; } .sa-search { grid-column: 1 / -1; } }

.sa-table td, .sa-table th { vertical-align: middle; }
.sa-ref { display: block; font-size: 0.86rem; color: var(--lg-ink); }
.sa-sub { display: block; margin-top: 2px; font-size: 0.72rem; font-weight: 600; color: var(--lg-mute, #64748b); }
.sa-red { color: #b91c1c; }
.sa-nowrap { white-space: nowrap; }
.sa-row-deleted td { background: rgba(254, 226, 226, 0.55) !important; }
.sa-row-deleted td:first-child { box-shadow: inset 4px 0 0 #dc2626; }
.sa-row-deleted .sa-ref { color: #991b1b; text-decoration: line-through; text-decoration-color: rgba(185, 28, 28, 0.5); }
html.dark .sa-row-deleted td { background: rgba(127, 29, 29, 0.28) !important; }
.sa-actions { white-space: nowrap; text-align: right; }
.sa-actions-th { text-align: right; }
.sa-ib { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; min-height: 0; padding: 0; margin-left: 5px; border: 0; border-radius: 12px; color: #fff; cursor: pointer; transition: transform 0.15s ease, box-shadow 0.15s ease; }
.sa-ib:hover { transform: translateY(-2px); }
.sa-ib.is-sky { background: linear-gradient(145deg, #38bdf8, #0369a1); box-shadow: 0 10px 16px -10px rgba(3, 105, 161, 0.9); }
.sa-ib.is-green { background: linear-gradient(145deg, #34d399, #047857); box-shadow: 0 10px 16px -10px rgba(4, 120, 87, 0.9); }
.sa-ib.is-violet { background: linear-gradient(145deg, #a78bfa, #6d28d9); box-shadow: 0 10px 16px -10px rgba(109, 40, 217, 0.9); }
.sa-ib.is-amber { background: linear-gradient(145deg, #fbbf24, #b45309); box-shadow: 0 10px 16px -10px rgba(180, 83, 9, 0.9); }
.sa-ib.is-red { background: linear-gradient(145deg, #fb7185, #be123c); box-shadow: 0 10px 16px -10px rgba(190, 18, 60, 0.9); }
.sa-badge { position: absolute; top: -6px; right: -6px; min-width: 17px; height: 17px; padding: 0 4px; display: grid; place-items: center; border-radius: 9999px; font-size: 0.62rem; font-weight: 800; color: #6d28d9; background: #fff; box-shadow: 0 2px 6px rgba(15, 23, 42, 0.3); }

.sa-pager { display: flex; flex-wrap: wrap; justify-content: center; gap: 6px; padding: 14px; }
.sa-pg { display: inline-grid; place-items: center; min-width: 36px; height: 36px; padding: 0 10px; border-radius: 11px; font-size: 0.82rem; font-weight: 800; color: var(--lg-ink); background: var(--lg-card); border: 1px solid var(--lg-line); }
.sa-pg.is-on { color: #fff; border-color: transparent; background: linear-gradient(145deg, #3b82f6, #1d4ed8); box-shadow: 0 10px 16px -10px rgba(29, 78, 216, 0.9); }
.sa-pg.is-off { opacity: 0.4; }

.sa-set { display: grid; gap: 14px; padding: 4px 18px 18px; }
.sa-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
@media (max-width: 760px) { .sa-grid { grid-template-columns: 1fr; } }
.sa-field > span { display: block; margin-bottom: 4px; font-size: 0.66rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.sa-field small { display: block; margin-top: 4px; font-size: 0.72rem; color: var(--lg-mute, #64748b); }
.sa-input { width: 100%; height: 44px; padding: 0 14px; border-radius: 14px; font-size: 0.88rem; font-weight: 600; color: var(--lg-ink); background: var(--lg-card); border: 1px solid var(--lg-line); }
.sa-input:focus { outline: none; border-color: #818cf8; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2); }
.sa-switch { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); cursor: pointer; }
.sa-switch input { position: absolute; opacity: 0; pointer-events: none; }
.sa-switch b { display: block; font-size: 0.88rem; }
.sa-switch small { display: block; font-size: 0.74rem; color: var(--lg-mute, #64748b); }
.sa-switch-ui { position: relative; flex: none; width: 44px; height: 26px; border-radius: 9999px; background: #cbd5e1; transition: background 0.2s ease; }
.sa-switch-ui::after { content: ''; position: absolute; top: 3px; left: 3px; width: 20px; height: 20px; border-radius: 9999px; background: #fff; box-shadow: 0 2px 5px rgba(15, 23, 42, 0.35); transition: transform 0.22s cubic-bezier(0.34, 1.56, 0.64, 1); }
.sa-switch.is-on { border-color: #93c5fd; background: linear-gradient(135deg, rgba(219, 234, 254, 0.7), var(--lg-soft)); }
.sa-switch.is-on .sa-switch-ui { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
.sa-switch.is-on .sa-switch-ui::after { transform: translateX(18px); }
.sa-sub-h { margin: 6px 0 0; font-size: 0.9rem; font-weight: 800; }
.sa-hint { margin: 0; font-size: 0.8rem; color: var(--lg-mute, #64748b); }
.sa-err { margin: 0; font-size: 0.78rem; font-weight: 700; color: #b91c1c; }
.sa-chips, .sa-people { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.sa-chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; color: var(--lg-ink); background: var(--lg-card); border: 1px solid var(--lg-line); cursor: pointer; transition: all 0.15s ease; }
.sa-chip input { position: absolute; opacity: 0; pointer-events: none; }
.sa-chip.on { color: #fff; border-color: transparent; background: linear-gradient(145deg, #6366f1, #4338ca); box-shadow: 0 8px 14px -8px rgba(67, 56, 202, 0.9); }
.sa-person { display: inline-flex; align-items: center; gap: 6px; padding: 5px 6px 5px 12px; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; color: #0c4a6e; background: linear-gradient(135deg, #e0f2fe, #ede9fe); }
.sa-person.is-mail { color: #064e3b; background: linear-gradient(135deg, #d1fae5, #e0f2fe); }
.sa-person button { display: grid; place-items: center; width: 20px; height: 20px; min-height: 0; padding: 0; border: 0; border-radius: 9999px; color: #fff; background: #64748b; cursor: pointer; }
.sa-person button:hover { background: #e11d48; }
.sa-pick { min-width: 210px; }
.sa-mail { width: auto; flex: 1; min-width: 240px; }

.sa-dlg-title { display: inline-flex; align-items: center; gap: 8px; font-weight: 800; }
.sa-dlg-title.is-red { color: #b91c1c; }
.sa-sum { display: flex; flex-wrap: wrap; gap: 6px 16px; margin-bottom: 14px; padding: 10px 14px; border-radius: 14px; font-size: 0.8rem; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.sa-sum b { margin-right: 6px; }
.sa-sum.is-deleted { background: rgba(254, 226, 226, 0.6); border-color: #fca5a5; }
.sa-tl { position: relative; margin: 0; padding: 0 0 0 4px; list-style: none; display: grid; gap: 12px; max-height: 52vh; overflow-y: auto; }
.sa-tl li { position: relative; display: flex; gap: 12px; }
.sa-tl li:not(:last-child)::before { content: ''; position: absolute; left: 13px; top: 28px; bottom: -12px; width: 2px; background: var(--lg-line); }
.sa-tl-dot { flex: none; display: grid; place-items: center; width: 28px; height: 28px; border-radius: 9999px; color: #fff; background: #64748b; }
.sa-tl b { font-size: 0.86rem; }
.sa-by { font-size: 0.8rem; font-weight: 700; color: var(--lg-mute, #64748b); }
.sa-tl small { display: block; font-size: 0.74rem; color: var(--lg-mute, #64748b); }
.is-generated .sa-tl-dot { background: linear-gradient(145deg, #38bdf8, #0369a1); }
.is-viewed .sa-tl-dot, .is-previewed .sa-tl-dot { background: linear-gradient(145deg, #a78bfa, #6d28d9); }
.is-downloaded .sa-tl-dot { background: linear-gradient(145deg, #34d399, #047857); }
.is-emailed .sa-tl-dot { background: linear-gradient(145deg, #fbbf24, #b45309); }
.is-deleted .sa-tl-dot, .is-expired .sa-tl-dot, .is-email_failed .sa-tl-dot { background: linear-gradient(145deg, #fb7185, #be123c); }
.is-deleted b, .is-expired b, .is-email_failed b { color: #b91c1c; }

/* checking entries */
.sa-ck { width: 44px; text-align: center; }
.sa-box { position: relative; display: inline-grid; place-items: center; cursor: pointer; }
.sa-box input { position: absolute; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
.sa-box > span { display: grid; place-items: center; width: 22px; height: 22px; border-radius: 7px; border: 2px solid #cbd5e1; color: transparent; background: var(--lg-card); transition: all 0.15s ease; }
.sa-box input:checked + span { color: #fff; border-color: #4338ca; background: linear-gradient(145deg, #6366f1, #4338ca); box-shadow: 0 6px 12px -6px rgba(67, 56, 202, 0.9); }
.sa-box input:indeterminate + span { border-color: #4338ca; background: linear-gradient(145deg, #6366f1, #4338ca); }
.sa-box input:indeterminate + span::after { content: ''; width: 10px; height: 2px; border-radius: 2px; background: #fff; }
.sa-box input:indeterminate + span svg { display: none; }
.sa-box input:focus-visible + span { box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.35); }
.sa-row-on td { background: rgba(99, 102, 241, 0.07); }
.sa-row-deleted.sa-row-on td { background: rgba(254, 202, 202, 0.7) !important; }
.sa-bulk { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 10px; margin: 0 18px 12px; padding: 9px 12px; border-radius: 14px; font-size: 0.82rem; font-weight: 700; color: var(--lg-mute, #64748b); background: var(--lg-soft); border: 1px dashed var(--lg-line); transition: all 0.18s ease; }
.sa-bulk.is-on { color: #3730a3; border-style: solid; border-color: #a5b4fc; background: linear-gradient(135deg, rgba(224, 231, 255, 0.8), var(--lg-soft)); }
.sa-bulk-info { display: inline-flex; align-items: center; gap: 6px; }
.sa-bulk-gap { flex: 1; }
.sa-link { min-height: 0; padding: 4px 8px; border: 0; background: none; font-size: 0.78rem; font-weight: 800; color: #4f46e5; text-decoration: underline; text-underline-offset: 3px; cursor: pointer; }
.sa-count { display: inline-grid; place-items: center; min-width: 20px; height: 20px; margin-left: 6px; padding: 0 6px; border-radius: 9999px; font-size: 0.7rem; color: #fff; background: #be123c; }

/* a wipe-off waiting for the second Super Admin */
.sa-wipe { display: flex; flex-wrap: wrap; align-items: center; gap: 14px; padding: 16px 18px; border-radius: 20px; border: 1px solid #fca5a5; background: linear-gradient(135deg, rgba(254, 226, 226, 0.9), rgba(255, 247, 237, 0.9)); box-shadow: 0 18px 30px -22px rgba(190, 18, 60, 0.7); }
html.dark .sa-wipe { background: linear-gradient(135deg, rgba(127, 29, 29, 0.35), rgba(120, 53, 15, 0.25)); border-color: rgba(248, 113, 113, 0.4); }
.sa-wipe-ico { flex: none; display: grid; place-items: center; width: 46px; height: 46px; border-radius: 15px; color: #fff; background: linear-gradient(145deg, #fb7185, #9f1239); box-shadow: 0 12px 20px -10px rgba(159, 18, 57, 0.9); }
.sa-wipe-main { flex: 1; min-width: 240px; }
.sa-wipe-main h3 { margin: 0; font-size: 0.95rem; font-weight: 800; color: #7f1d1d; }
html.dark .sa-wipe-main h3 { color: #fecaca; }
.sa-wipe-main p { margin: 4px 0 8px; font-size: 0.8rem; color: var(--lg-mute, #64748b); }
.sa-wipe-refs { display: flex; flex-wrap: wrap; gap: 5px; }
.sa-wipe-refs span { padding: 2px 9px; border-radius: 9999px; font-size: 0.7rem; font-weight: 800; color: #991b1b; background: rgba(255, 255, 255, 0.8); border: 1px solid #fecaca; }
.sa-wipe-refs .is-more { color: #64748b; border-color: var(--lg-line); }
.sa-wipe-act { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.sa-wipe-wait { display: inline-flex; align-items: center; gap: 6px; font-size: 0.8rem; font-weight: 800; color: #9a3412; }
.sa-wipes { padding: 4px 6px; font-size: 0.82rem; }
.sa-wipes summary { cursor: pointer; font-weight: 800; padding: 6px 4px; color: var(--lg-mute, #64748b); }
.sa-wipes ul { margin: 6px 0 0; padding: 0; list-style: none; display: grid; gap: 8px; }
.sa-wipes li { padding: 10px 12px; border-radius: 14px; background: var(--lg-card); border: 1px solid var(--lg-line); }
.sa-wipes li small { display: block; margin: 4px 0 0; font-size: 0.7rem; color: var(--lg-mute, #64748b); word-break: break-word; }
.is-wipe_requested .sa-tl-dot { background: linear-gradient(145deg, #fb923c, #c2410c); }
.is-wipe_cancelled .sa-tl-dot, .is-wipe_rejected .sa-tl-dot { background: linear-gradient(145deg, #94a3b8, #475569); }
</style>

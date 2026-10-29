<template>
  <admin-layout :title="$t('Holiday Greetings')">
    <div class="lg-page ac sc-ui tr-ui hg-ui px-4 md:px-0">
      <page-header icon="sparkles" :title="$t('Holiday Greetings')" :subtitle="$t('Warm email and SMS greetings for the holidays. You are reminded :h hours before each one, then you review and send.', { h: hours })">
        <button v-if="can.manage" type="button" class="lg-btn tone" style="--tone: #be185d" @click="openAdd"><span class="tone-ico"><icons name="plus" class="w-4 h-4" /></span>{{ $t('Add Holiday or Greeting') }}</button>
        <Link v-if="can.contacts" :href="route('holiday-greetings.contacts.index')" class="lg-btn tone" style="--tone: #2563eb"><span class="tone-ico"><icons name="users" class="w-4 h-4" /></span>{{ $t('Greeting List') }}</Link>
        <Link v-if="can.settings" :href="route('holiday-greetings.settings')" class="lg-btn tone" style="--tone: #475569"><span class="tone-ico"><icons name="adjustments" class="w-4 h-4" /></span>{{ $t('Settings') }}</Link>
      </page-header>

      <!-- the next holiday -->
      <section v-if="next" class="hg-next" :style="vars(next)">
        <span class="hg-bigemoji" aria-hidden="true">{{ next.emoji }}</span>
        <div class="min-w-0">
          <div class="hg-eyebrow">{{ $t('Next holiday') }}</div>
          <h2>{{ next.name }}</h2>
          <div class="hg-when">{{ next.day }}, {{ next.dateText }}<span v-if="!next.confirmed"> · {{ $t('date to be confirmed') }}</span></div>
          <div class="hg-facts">
            <span class="hg-fact"><icons name="clock" class="w-3.5 h-3.5" />{{ $t('Reminder to you: :t', { t: next.reminderDueText }) }}</span>
            <span class="hg-fact"><icons name="email" class="w-3.5 h-3.5" />{{ $t(':n emails', { n: stats.emails }) }}</span>
            <span class="hg-fact"><icons name="sms" class="w-3.5 h-3.5" />{{ $t(':n SMS', { n: stats.mobiles }) }}</span>
          </div>
          <Link :href="route('holiday-greetings.holidays.show', next.id)" class="lg-btn"><icons name="sparkles" class="w-4 h-4" />{{ next.phase === 'sent' || next.phase === 'partly' || next.phase === 'sending' ? $t('Open the record') : $t('Prepare the greetings') }}</Link>
        </div>
        <div class="hg-countdown"><b>{{ next.daysAway }}</b><span>{{ next.daysAway === 1 ? $t('day to go') : next.daysAway === 0 ? $t('Today') : $t('days to go') }}</span></div>
      </section>
      <section v-else class="lg-card"><p class="hg-note" style="margin: 0">{{ $t('There is no coming holiday on the list. Add one, or look at next year.') }}</p></section>

      <div v-if="smsReady === false" class="lg-note lg-note-amber" style="margin-bottom: 14px"><icons name="exclamation" class="w-4 h-4" /><span>{{ $t('SMS is switched off in WIMS Connect, so the SMS part of the greetings and of the reminder cannot go out until it is switched on. Emails are not affected.') }}</span></div>

      <div class="hg-stats">
        <div class="hg-stat is-blue"><i><icons name="calendar" class="w-5 h-5" /></i><span><b>{{ stats.holidays }}</b>{{ $t('Holidays in :y', { y: year }) }}</span></div>
        <div class="hg-stat is-amber"><i><icons name="clock" class="w-5 h-5" /></i><span><b>{{ stats.remaining }}</b>{{ $t('Still to come') }}</span></div>
        <div class="hg-stat is-green"><i><icons name="tick" class="w-5 h-5" /></i><span><b>{{ stats.sent }}</b>{{ $t('Holidays greeted') }}</span></div>
        <div class="hg-stat is-cyan"><i><icons name="send" class="w-5 h-5" /></i><span><b>{{ stats.messages }}</b>{{ $t('Messages sent') }}</span></div>
        <div class="hg-stat is-red"><i><icons name="exclamation" class="w-5 h-5" /></i><span><b>{{ stats.failed }}</b>{{ $t('Failed') }}</span></div>
        <div class="hg-stat"><i><icons name="users" class="w-5 h-5" /></i><span><b>{{ stats.people }}</b>{{ $t('People in the list') }}</span></div>
      </div>

      <section v-if="upcoming.length > 1" class="lg-card">
        <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t('Coming holidays') }}</h2><p class="lg-card-sub">{{ $t('The next holidays that count. Saturdays and Sundays do not.') }}</p></div></header>
        <div class="hg-stats" style="margin: 0">
          <Link v-for="h in upcoming" :key="h.id" :href="route('holiday-greetings.holidays.show', h.id)" class="hg-stat" :style="vars(h)">
            <span class="hg-date"><small>{{ h.month }}</small><b>{{ h.dom }}</b></span>
            <span><b style="font-size: 1rem">{{ h.emoji }} {{ h.name }}</b>{{ h.daysAway === 0 ? $t('Today') : h.daysAway === 1 ? $t('Tomorrow') : $t('in :n days', { n: h.daysAway }) }}</span>
          </Link>
        </div>
      </section>

      <div class="hg-yearbar">
        <div class="hg-years" role="navigation" :aria-label="$t('Year')">
          <Link :href="route('holiday-greetings.dashboard', { year: year - 1 })" class="lg-tip" :data-tip="year - 1" :aria-label="$t('Previous year')"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" /></Link>
          <span class="is-on">{{ year }}</span>
          <Link :href="route('holiday-greetings.dashboard', { year: year + 1 })" class="lg-tip" :data-tip="year + 1" :aria-label="$t('Next year')"><icons name="chevron-down" class="w-4 h-4 transform -rotate-90" /></Link>
        </div>
        <Link v-if="year !== thisYear" :href="route('holiday-greetings.dashboard')" class="lg-btn lg-btn-soft"><icons name="calendar" class="w-4 h-4" />{{ $t('Back to :y', { y: thisYear }) }}</Link>
      </div>

      <div class="lg-table-wrap">
        <table class="lg-table lg-responsive tr-table">
          <thead><tr><th class="tr-no">{{ $t('No') }}</th><th>{{ $t('Date') }}</th><th>{{ $t('Holiday') }}</th><th>{{ $t('Day') }}</th><th>{{ $t('Status') }}</th><th>{{ $t('Messages') }}</th><th class="lg-sticky-end"></th></tr></thead>
          <tbody>
            <tr v-for="(h, n) in holidays" :key="h.id" :class="{ 'is-dim': !h.counts, 'is-next': next && next.id === h.id }" :style="vars(h)" class="is-click" @click="$inertia.visit(route('holiday-greetings.holidays.show', h.id))">
              <td :data-label="$t('No')" class="tr-no">{{ n + 1 }}</td>
              <td :data-label="$t('Date')"><span class="hg-date"><small>{{ h.month }}</small><b>{{ h.dom }}</b></span></td>
              <td :data-label="$t('Holiday')" class="tr-wrap"><div class="hg-name"><span class="hg-emoji">{{ h.emoji }}</span><span><b>{{ h.name }}</b><small>{{ $t(kinds[h.kind] || h.kind) }}<template v-if="!h.confirmed"> · {{ $t('date to be confirmed') }}</template></small></span></div></td>
              <td :data-label="$t('Day')" class="tr-nw">{{ h.day }}</td>
              <td :data-label="$t('Status')"><span class="lg-chip" :class="chip(h.phase)" style="text-transform: none">{{ phaseText(h) }}</span></td>
              <td :data-label="$t('Messages')">
                <span v-if="h.total" class="hg-counts"><span class="hg-dot is-ok">{{ h.sent }}</span><span v-if="h.failed" class="hg-dot is-bad">{{ h.failed }}</span><span v-if="h.pending" class="hg-dot is-wait">{{ h.pending }}</span></span>
                <span v-else class="hg-note">-</span>
              </td>
              <td class="lg-sticky-end tr-acts-cell" @click.stop>
                <span class="tr-acts"><Link :href="route('holiday-greetings.holidays.show', h.id)" class="lg-btn tone" style="--tone: #be185d"><span class="tone-ico"><icons name="eye-flat" class="w-4 h-4" /></span>{{ $t('Open') }}</Link></span>
              </td>
            </tr>
            <tr v-if="!holidays.length" class="lg-empty-row"><td class="lg-empty" colspan="7">{{ $t('No holidays are on the list for this year.') }}</td></tr>
          </tbody>
        </table>
      </div>
      <p class="hg-note" style="margin-top: 10px">{{ $t('Holidays on a Saturday or Sunday are shown but not counted.') }} {{ $t('Holidays that follow the moon (Eid, Maulid) are worked out from the calendar: confirm the date on the holiday page once it is announced.') }}</p>
    </div>

    <travel-modal :show="addOpen" :title="$t('Add Holiday or Greeting')" :subtitle="$t('A day or an occasion of your own, such as a holiday given to staff. It starts from the RANS greeting.')" icon="calendar" :busy="form.processing" @close="addOpen = false">
      <form id="hg-add-form" @submit.prevent="add">
        <div class="tm-grid">
          <div class="ac-f tm-full"><label>{{ $t('Name of the holiday') }}</label><input ref="addName" v-model="form.name" maxlength="160" autocomplete="off" /><span class="ac-err">{{ form.errors.name }}</span></div>
          <div class="ac-f"><label>{{ $t('Date') }}</label><input v-model="form.date" type="date" /><span class="ac-err">{{ form.errors.date }}</span></div>
          <div class="ac-f"><label>{{ $t('Look of the greeting') }}</label><select v-model="form.theme"><option v-for="(label, key) in themes" :key="key" :value="key">{{ label }}</option></select></div>
          <div class="ac-f tm-full"><label class="hg-check"><input v-model="form.force_include" type="checkbox" /> {{ $t('Count it even if it falls on a Saturday or Sunday') }}</label></div>
          <div class="ac-f tm-full"><label>{{ $t('Note (only you see it)') }}</label><input v-model="form.note" maxlength="500" autocomplete="off" /></div>
        </div>
      </form>
      <template #footer>
        <button type="button" class="lg-btn lg-btn-soft" :disabled="form.processing" @click="addOpen = false">{{ $t('Cancel') }}</button>
        <button type="submit" form="hg-add-form" class="lg-btn tone tone-solid" style="--tone: #be185d" :disabled="form.processing"><span class="tone-ico"><icons :name="form.processing ? 'refresh' : 'tick'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" /></span>{{ $t('Add and Open') }}</button>
      </template>
    </travel-modal>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TravelModal from '@/Pages/Travels/TravelModal.vue';

export default {
  components: { AdminLayout, PageHeader, Link, TravelModal },
  props: { year: Number, thisYear: Number, today: String, holidays: Array, upcoming: Array, stats: Object, hours: Number, skipWeekends: Boolean, themes: Object, smsReady: Boolean, can: Object },
  data() {
    return {
      addOpen: false,
      form: this.$inertia.form({ name: '', date: '', theme: 'generic', force_include: false, note: '' }),
      kinds: { public: 'Public holiday', religious: 'Religious holiday', observance: 'Observance', custom: 'Company holiday or greeting' },
    };
  },
  computed: {
    next() { return this.upcoming[0] || null; },
  },
  methods: {
    vars(h) { const p = h.palette || {}; return { '--p': p.primary, '--s': p.secondary, '--a': p.accent, '--soft': p.soft }; },
    chip(phase) {
      return { upcoming: 'lg-chip-blue', reminded: 'lg-chip-amber', sending: 'lg-chip-cyan', sent: 'lg-chip-green', partly: 'lg-chip-red', missed: 'lg-chip-slate', weekend: 'lg-chip-slate', off: 'lg-chip-slate' }[phase] || 'lg-chip-slate';
    },
    phaseText(h) {
      const t = { upcoming: 'Coming', reminded: 'Reminder sent', sending: 'Sending', sent: 'Greetings sent', partly: 'Sent, some failed', missed: 'Not sent', weekend: 'Weekend, not counted', off: 'Switched off' };
      return this.$t(t[h.phase] || h.phase);
    },
    openAdd() { this.form.reset(); this.form.clearErrors(); this.form.date = this.today; this.addOpen = true; this.$nextTick(() => this.$refs.addName && this.$refs.addName.focus()); },
    add() { this.form.post(route('holiday-greetings.holidays.store'), { preserveScroll: true, onSuccess: () => { this.addOpen = false; } }); },
  },
};
</script>

<style scoped>
.hg-check { display: flex; align-items: center; gap: 8px; font-weight: 600; text-transform: none; letter-spacing: 0; }
.hg-check input { width: 18px; height: 18px; flex: none; padding: 0; }
</style>

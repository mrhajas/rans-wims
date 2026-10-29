<template>
  <article class="mrr-card" :class="{ 'is-bad': reminder.counts.email.failed + reminder.counts.sms.failed + reminder.counts.app.failed > 0 }">
    <header>
      <span class="mrr-dot"><icons name="bell" class="w-4 h-4" /></span>
      <div class="mrr-head">
        <b>{{ $datetime(reminder.at) }}</b>
        <span>{{ $t('from :n', { n: reminder.by || '-' }) }} · {{ $t('request was :s', { s: reminder.status }) }}</span>
      </div>
      <div v-if="$slots.aside" class="mrr-aside"><slot name="aside" /></div>
    </header>
    <p v-if="reminder.note" class="mrr-note">{{ reminder.note }}</p>
    <p v-else class="mrr-note is-none">{{ $t('No note.') }}</p>
    <ul v-if="reminder.files.length" class="mrr-files">
      <li v-for="f in reminder.files" :key="f.id"><a :href="route('media.download', f.id)" target="_blank" rel="noopener"><icons name="doc-text" class="w-3.5 h-3.5" />{{ f.title }}</a></li>
    </ul>
    <div class="mrr-counts">
      <span v-for="c in channels" :key="c.key" class="mrr-pill" :class="pillClass(reminder.counts[c.key])"><icons :name="c.icon" class="w-3.5 h-3.5" />{{ c.label }} {{ reminder.counts[c.key].sent }}/{{ reminder.counts[c.key].total }}</span>
      <button v-if="reminder.people && reminder.people.length" type="button" class="mrr-more" @click="open = !open">{{ open ? $t('Hide who was told') : $t('Who was told (:n)', { n: reminder.counts.people }) }}</button>
    </div>
    <div v-if="open" class="mrr-people">
      <table>
        <thead><tr><th>{{ $t('Person') }}</th><th>{{ $t('How') }}</th><th>{{ $t('Email / Mobile') }}</th><th>{{ $t('Result') }}</th></tr></thead>
        <tbody>
          <tr v-for="(p, i) in reminder.people" :key="i">
            <td>{{ p.name }}</td><td>{{ label(p.channel) }}</td><td>{{ p.address || '-' }}</td>
            <td><span class="mrr-res" :class="'is-' + p.status.toLowerCase()">{{ $t(p.status) }}</span><small v-if="p.error">{{ p.error }}</small></td>
          </tr>
        </tbody>
      </table>
    </div>
  </article>
</template>

<script>
export default {
  props: { reminder: Object },
  data() { return { open: false }; },
  computed: {
    channels() { return [{ key: 'app', icon: 'bell', label: this.$t('App') }, { key: 'email', icon: 'email', label: this.$t('Email') }, { key: 'sms', icon: 'sms', label: this.$t('SMS') }]; },
  },
  methods: {
    label(c) { return { email: this.$t('Email'), sms: this.$t('SMS'), app: this.$t('App alert') }[c] || c; },
    pillClass(c) { return c.total === 0 ? 'is-off' : c.failed ? 'is-bad' : c.sent === c.total ? 'is-ok' : 'is-part'; },
  },
};
</script>

<style>
.mrr-card { display: grid; gap: 10px; padding: 14px 16px; border-radius: 18px; background: var(--lg-card); box-shadow: 0 8px 22px -12px rgba(15, 23, 42, 0.35), inset 0 0 0 1px var(--lg-line); }
.mrr-card.is-bad { box-shadow: 0 8px 22px -12px rgba(190, 18, 60, 0.4), inset 0 0 0 1px rgba(190, 18, 60, 0.3); }
.mrr-card header { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
.mrr-aside { display: flex; align-items: center; flex-wrap: wrap; gap: 8px 10px; margin-left: auto; justify-content: flex-end; }
.mrr-dot { flex: none; display: grid; place-items: center; width: 32px; height: 32px; border-radius: 11px; color: #fff; background: linear-gradient(135deg, #f59e0b, #d97706); box-shadow: 0 6px 14px -6px rgba(217, 119, 6, 0.7); }
.mrr-head { display: grid; min-width: 190px; flex: 1 1 190px; }
.mrr-head b { font-size: 0.9rem; color: var(--lg-ink); }
.mrr-head span { font-size: 0.76rem; color: var(--lg-mute); }
.mrr-note { margin: 0; font-size: 0.88rem; line-height: 1.55; color: var(--lg-ink); white-space: pre-wrap; overflow-wrap: anywhere; }
.mrr-note.is-none { color: var(--lg-mute); font-style: italic; }
.mrr-files { display: flex; flex-wrap: wrap; gap: 6px; margin: 0; padding: 0; list-style: none; }
.mrr-files a { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; background: var(--lg-soft); color: #2563eb; font-size: 0.76rem; font-weight: 700; box-shadow: 0 3px 8px -4px rgba(15, 23, 42, 0.3); }
.mrr-counts { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.mrr-pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 0.74rem; font-weight: 800; }
.mrr-pill.is-ok { color: #047857; background: rgba(16, 185, 129, 0.14); } .mrr-pill.is-part { color: #b45309; background: rgba(245, 158, 11, 0.16); }
.mrr-pill.is-bad { color: #be123c; background: rgba(244, 63, 94, 0.14); } .mrr-pill.is-off { color: var(--lg-mute); background: var(--lg-soft); }
.mrr-more { margin-left: auto; font-size: 0.76rem; font-weight: 800; color: #be185d; cursor: pointer; }
.mrr-people { overflow-x: auto; }
.mrr-people table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
.mrr-people th { text-align: left; font-size: 0.68rem; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-mute); padding: 4px 8px; }
.mrr-people td { padding: 6px 8px; border-top: 1px solid var(--lg-line); vertical-align: top; overflow-wrap: anywhere; }
.mrr-people small { display: block; color: #be123c; font-size: 0.72rem; }
.mrr-res { display: inline-block; padding: 2px 9px; border-radius: 999px; font-weight: 800; font-size: 0.72rem; }
.mrr-res.is-sent { color: #047857; background: rgba(16, 185, 129, 0.14); } .mrr-res.is-failed { color: #be123c; background: rgba(244, 63, 94, 0.14); } .mrr-res.is-skipped { color: #b45309; background: rgba(245, 158, 11, 0.16); }
</style>

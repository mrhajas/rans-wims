<template>
  <admin-layout :title="subject.is_self ? $t('My Signature') : subject.name">
    <div class="lg-page sb px-4 md:px-0">
      <sb-hero icon="edit" :title="subject.is_self ? $t('My Signature') : subject.name" :subtitle="subject.is_self ? $t('Your handwritten signature as WIMS keeps it. It is only used on the documents where you actually act as signatory.') : $t('Signature profile')">
        <Link v-if="can.history || !subject.is_self" :href="route('signatories.index')" class="sb-gbtn"><icons name="list" class="w-4 h-4" />{{ $t('All signatories') }}</Link>
      </sb-hero>

      <!-- who and where it stands -->
      <section class="sb-card mb-4" style="--i: 0">
        <div class="sg-head">
          <span class="sb-av">{{ initials(subject.name) }}</span>
          <div class="min-w-0">
            <h2>{{ subject.name }}</h2>
            <p><span v-if="subject.position">{{ subject.position }} · </span><span v-if="subject.staff_no">{{ $t('Staff no') }} {{ subject.staff_no }} · </span>{{ subject.username }}</p>
          </div>
          <span class="sg-status" :class="'is-' + statusKey">{{ statusLabel }}</span>
        </div>
        <div class="sg-now">
          <div class="sg-now__img">
            <img v-if="activeVersion && activeVersion.image_url" :src="activeVersion.image_url" alt="" />
            <p v-else-if="activeVersion && !can.see_images" class="sb-none">{{ $t('The picture is not shown to you.') }}</p>
            <p v-else class="sb-none">{{ $t('No active signature.') }}</p>
          </div>
          <dl class="sg-facts">
            <div><dt>{{ $t('Registered') }}</dt><dd>{{ dt(signatory && signatory.registered_at) }}</dd></div>
            <div><dt>{{ $t('Verified') }}</dt><dd>{{ signatory && signatory.verified_at ? dt(signatory.verified_at) + (signatory.verified_by ? ' · ' + signatory.verified_by : '') : '-' }}</dd></div>
            <div v-if="activeVersion"><dt>{{ $t('Version in use') }}</dt><dd>{{ activeVersion.version_no }}</dd></div>
            <div v-if="signatory && signatory.suspended_at"><dt>{{ $t('Suspended') }}</dt><dd>{{ dt(signatory.suspended_at) }} · {{ signatory.suspend_reason }}</dd></div>
            <div v-if="signatory && signatory.revoked_at"><dt>{{ $t('Revoked') }}</dt><dd>{{ dt(signatory.revoked_at) }} · {{ signatory.revoke_reason }}</dd></div>
          </dl>
        </div>
        <p v-if="$page.props.errors && $page.props.errors.signature" class="sg-error">{{ $page.props.errors.signature }}</p>
        <p v-if="$page.props.errors && $page.props.errors.password" class="sg-error">{{ $page.props.errors.password }}</p>

        <!-- status actions -->
        <div v-if="signatory && (can.suspend || can.revoke)" class="sg-actions mt-3">
          <button v-if="can.suspend && signatory.status === 'active'" type="button" class="sb-btn" @click="act('suspend')"><icons name="pause" class="w-4 h-4" />{{ $t('Suspend') }}</button>
          <button v-if="can.suspend && (signatory.status === 'suspended' || signatory.status === 'revoked')" type="button" class="sb-btn" @click="act('reinstate')"><icons name="play" class="w-4 h-4" />{{ $t('Reinstate') }}</button>
          <button v-if="can.revoke && signatory.status !== 'revoked'" type="button" class="sb-btn" @click="act('revoke')"><icons name="trash" class="w-4 h-4" />{{ $t('Revoke') }}</button>
        </div>
      </section>

      <!-- register / replace -->
      <section v-if="can.register_here && (!signatory || signatory.status !== 'revoked')" class="sb-card mb-4" style="--i: 1">
        <h3 class="sb-card__title"><span class="sb-ico g-teal"><icons name="camera" class="w-4 h-4" /></span>{{ signatory ? $t('Replace the signature') : $t('Register the signature') }}
          <small>{{ prefs.ink ? $t('Always saved as a transparent blue-ink picture') : '' }}</small></h3>
        <signature-capture :subject-id="subject.id" :is-self="subject.is_self" :settings="prefs" />
      </section>
      <p v-else-if="signatory && signatory.status === 'revoked'" class="sg-error mb-4">{{ $t('This signatory is revoked. A person who may reinstate signatures has to reinstate them before a new one can be registered.') }}</p>

      <!-- versions -->
      <section v-if="versions.length" class="sb-card mb-4" style="--i: 2">
        <h3 class="sb-card__title"><span class="sb-ico g-navy"><icons name="list" class="w-4 h-4" /></span>{{ $t('Versions') }}<small>{{ versions.length }}</small></h3>
        <div class="sg-versions">
          <article v-for="v in versions" :key="v.id" class="sg-ver" :class="'is-' + v.status">
            <div class="sg-ver__img"><img v-if="v.image_url" :src="v.image_url" alt="" loading="lazy" /><span v-else>{{ $t('hidden') }}</span></div>
            <div class="sg-ver__body">
              <b>{{ $t('Version') }} {{ v.version_no }} <em :class="'is-' + v.status">{{ versionLabel(v.status) }}</em></b>
              <small>{{ $t('Added') }} {{ dt(v.uploaded_at) }}<template v-if="v.uploaded_by"> · {{ v.uploaded_by }}</template> · {{ methodLabel(v.method) }}</small>
              <small v-if="v.consent_at">{{ $t('Confirmed by the signatory') }}: {{ v.consented_by_owner ? $t('yes') : $t('no, confirmed by') + ' ' + v.consent_by }} · {{ dt(v.consent_at) }}</small>
              <small v-else>{{ $t('Not confirmed by the signatory yet') }}</small>
              <small v-if="v.verified_at">{{ $t('Verified') }} {{ dt(v.verified_at) }}<template v-if="v.verified_by"> · {{ v.verified_by }}</template></small>
              <small v-if="v.activated_at">{{ $t('In use from') }} {{ dt(v.activated_at) }}<template v-if="v.ended_at"> {{ $t('to') }} {{ dt(v.ended_at) }}</template></small>
              <small v-if="v.ended_reason && v.status !== 'active'">{{ v.ended_reason }}</small>
              <div class="sg-actions">
                <button v-if="v.status === 'pending_verification' && subject.is_self && !v.consented_by_owner" type="button" class="sb-btn" @click="consent(v)"><icons name="tick" class="w-4 h-4" />{{ $t('This is my signature') }}</button>
                <button v-if="v.status === 'pending_verification' && can.verify && can.activate" type="button" class="sb-btn is-primary" @click="ask('verify', v)"><icons name="tick" class="w-4 h-4" />{{ $t('Verify and activate') }}</button>
                <button v-if="v.can_use" type="button" class="sb-btn is-primary" @click="ask('use', v)"><icons name="tick" class="w-4 h-4" />{{ $t('Use this signature') }}</button>
                <button v-if="v.status === 'pending_verification' && can.verify" type="button" class="sb-btn" @click="ask('reject', v)"><icons name="cross" class="w-4 h-4" />{{ $t('Reject') }}</button>
                <a v-if="v.has_original && can.see_originals" :href="route('signatories.image', [v.id, 'original'])" target="_blank" rel="noopener" class="sb-btn"><icons name="eye" class="w-4 h-4" />{{ $t('Original picture') }}</a>
              </div>
            </div>
          </article>
        </div>
      </section>

      <section v-if="audit.length" class="sb-card" style="--i: 3">
        <h3 class="sb-card__title"><span class="sb-ico g-amber"><icons name="clock" class="w-4 h-4" /></span>{{ $t('Audit trail') }}</h3>
        <ul class="sg-audit"><li v-for="(a, i) in audit" :key="i"><time>{{ dt(a.at) }}</time><span>{{ a.what }}</span><em>{{ a.by }}</em></li></ul>
      </section>

      <!-- confirm a verify / reject / suspend / revoke / reinstate: a pop-up in the middle of the screen, so it is always seen -->
      <div v-if="pending" class="sg-modal" role="dialog" aria-modal="true" :aria-label="pendingTitle" @keydown.esc="pending = null" @click.self="pending = null">
        <div class="sg-modal__box">
          <h3>{{ pendingTitle }}</h3>
          <p class="sg-modal__who">{{ subject.name }}</p>
          <div class="sb-fields">
            <div v-if="pending.reason" class="sb-field sb-wide"><label for="sg-why">{{ $t('Reason') }}</label><input id="sg-why" v-model="pending.reasonText" type="text" class="sb-input" maxlength="255" autofocus /></div>
            <div v-if="prefs.reauth" class="sb-field sb-wide"><label for="sg-pw">{{ $t('Your password') }}</label><input id="sg-pw" v-model="pending.password" type="password" class="sb-input" autocomplete="current-password" @keydown.enter="canConfirm && doAct()" /></div>
          </div>
          <p v-if="modalError" class="sg-error" role="alert">{{ modalError }}</p>
          <div class="sg-actions mt-3">
            <button type="button" class="sb-btn is-primary" :disabled="busy || !canConfirm" @click="doAct"><icons name="tick" class="w-4 h-4" />{{ busy ? $t('Working...') : $t('Confirm') }}</button>
            <button type="button" class="sb-btn" @click="pending = null">{{ $t('Cancel') }}</button>
          </div>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SbHero from '@/Shared/SmartBrief/SbHero.vue';
import SignatureCapture from '@/Shared/Signatories/SignatureCapture.vue';

export default {
  components: { AdminLayout, Link, SbHero, SignatureCapture },
  props: { subject: Object, signatory: Object, versions: Array, prefs: Object, can: Object, audit: Array },
  data() { return { pending: null, busy: false, modalError: '' }; },
  computed: {
    activeVersion() { return this.signatory && this.signatory.active_version_id ? this.versions.find(v => v.id === this.signatory.active_version_id) || null : null; },
    statusKey() { return this.signatory ? (this.signatory.status === 'pending_verification' ? 'pending' : this.signatory.status) : 'none'; },
    canConfirm() { const p = this.pending; return !!p && !(p.reason && !p.reasonText) && !(this.prefs.reauth && !p.password); },
    statusLabel() { return { none: this.$t('Not registered'), pending: this.$t('Pending verification'), active: this.$t('Active'), suspended: this.$t('Suspended'), revoked: this.$t('Revoked') }[this.statusKey]; },
    pendingTitle() { return this.pending ? { use: this.$t('Use this earlier signature again? It moves to the top and becomes the one used on reports.'), suspend: this.$t('Suspend this signature?'), revoke: this.$t('Revoke this signature for good?'), reinstate: this.$t('Reinstate this signature?'), verify: this.$t('Verify this signature and make it active?'), reject: this.$t('Reject this signature?') }[this.pending.kind] : ''; },
  },
  methods: {
    initials(n) { return (n || '').split(' ').filter(Boolean).slice(0, 2).map(p => p[0].toUpperCase()).join(''); },
    dt(v) { return v ? String(v).slice(0, 16).replace('T', ' ') : '-'; },
    versionLabel(s) { return { pending_verification: this.$t('Pending'), active: this.$t('Active'), replaced: this.$t('Replaced'), rejected: this.$t('Rejected'), suspended: this.$t('Suspended'), revoked: this.$t('Revoked') }[s] || s; },
    methodLabel(m) { return { upload: this.$t('uploaded'), camera: this.$t('camera'), draw: this.$t('drawn') }[m] || m; },
    act(kind) { this.modalError = ''; this.pending = { kind, reason: kind !== 'reinstate', reasonText: '', password: '', target: this.signatory }; },
    ask(kind, v) { this.modalError = ''; this.pending = { kind, reason: kind === 'reject', reasonText: '', password: '', target: v }; },
    consent(v) { this.$inertia.post(route('signatories.versions.consent', v.id), {}, { preserveScroll: true }); },
    doAct() {
      const p = this.pending; this.busy = true;
      const body = { password: p.password, reason: p.reasonText };
      const url = { suspend: route('signatories.status.suspend', p.target.id), revoke: route('signatories.status.revoke', p.target.id), reinstate: route('signatories.status.reinstate', p.target.id), verify: route('signatories.versions.verify', p.target.id), use: route('signatories.versions.use', p.target.id), reject: route('signatories.versions.reject', p.target.id) }[p.kind];
      this.modalError = '';
      this.$inertia.post(url, body, {
        preserveScroll: true,
        onSuccess: () => { this.pending = null; },
        // a refused step (wrong password, a rule of the process) is explained inside the pop-up, where the person is looking
        onError: errs => { this.modalError = errs.signature || errs.password || errs.reason || Object.values(errs)[0] || this.$t('It could not be done.'); },
        onFinish: () => { this.busy = false; },
      });
    },
  },
};
</script>

<template>
  <div class="dl-page">
    <Head :title="$t('Too many devices')" />
    <main class="dl-card" role="main">
      <header class="dl-head">
        <span class="dl-ico"><icons name="desktop-computer" class="w-6 h-6" /></span>
        <div class="min-w-0">
          <h1>{{ $t('Too many devices') }}</h1>
          <p>{{ intro }}</p>
        </div>
      </header>

      <p v-if="flashMessage" class="dl-note is-ok" role="status">{{ flashMessage }}</p>
      <p v-if="errors.device" class="dl-note is-bad" role="alert">{{ errors.device }}</p>

      <section class="dl-here" v-if="here">
        <span class="dl-small">{{ $t('This device (waiting)') }}</span>
        <b>{{ here.platform }} · {{ here.browser }}</b>
        <em>{{ here.ip }}</em>
      </section>

      <ul class="dl-list" :aria-label="$t('Devices you are signed in on')">
        <li v-for="d in devices" :key="d.token" class="dl-dev">
          <span class="dl-dev-ico" :class="{ 'is-on': d.online }"><icons :name="d.mobile ? 'mobile' : 'desktop-computer'" class="w-5 h-5" /></span>
          <div class="dl-dev-txt">
            <b>{{ d.platform }} · {{ d.browser }}</b>
            <span>{{ d.ip }}</span>
            <small><i :class="{ 'is-on': d.online }"></i>{{ d.online ? $t('Online now') : $t('Last active') + ' ' + d.last_active }}</small>
          </div>
          <button type="button" class="dl-btn is-out" :disabled="busy" @click="signOut(d)"><icons name="out" class="w-4 h-4" /><span>{{ $t('Sign out') }}</span></button>
        </li>
        <li v-if="!devices.length" class="dl-empty">{{ $t('No other device is signed in any more. Press Continue.') }}</li>
      </ul>

      <div class="dl-actions">
        <button type="button" class="dl-btn is-main" :disabled="busy || !devices.length" @click="signOutOthers"><icons name="out" class="w-4 h-4" /><span>{{ $t('Sign out all other devices and continue') }}</span></button>
        <button v-if="!devices.length" type="button" class="dl-btn is-main" @click="go"><icons name="tick" class="w-4 h-4" /><span>{{ $t('Continue') }}</span></button>
        <button type="button" class="dl-btn" :disabled="busy" @click="signOutAll"><icons name="lock-closed" class="w-4 h-4" /><span>{{ $t('Sign out of every device, including this one') }}</span></button>
        <button type="button" class="dl-link" :disabled="busy" @click="logout">{{ $t('Not now, log out here') }}</button>
      </div>
      <p class="dl-foot">{{ $t('A device that used "Remember me" signs itself in again unless you sign out of every device.') }}</p>
    </main>
  </div>
</template>

<script>
import { Head, router } from '@inertiajs/vue3';

// Shown to a sign-in that would be one device too many (Settings > Devices per user). Nothing else in WIMS can be reached until there is room.
export default {
  components: { Head },
  props: { limit: Number, devices: { type: Array, default: () => [] }, here: Object, userName: String },
  data() { return { busy: false }; },
  computed: {
    intro() {
      return this.limit === 1
        ? this.$t(':name, you are already signed in on 1 device, which is the most allowed. Sign it out to continue here.', { name: this.userName })
        : this.$t(':name, you are already signed in on :n devices, which is the most allowed. Sign out one or more of them to continue here.', { name: this.userName, n: this.limit });
    },
    errors() { return this.$page.props.errors || {}; },
    flashMessage() { const f = this.$page.props.flash || {}; return f.message || ''; },
  },
  methods: {
    done() { this.busy = false; },
    signOut(d) { this.busy = true; router.post(route('device-limit.sign-out', d.token), {}, { preserveScroll: true, onFinish: this.done }); },
    signOutOthers() { this.busy = true; router.post(route('device-limit.sign-out-others'), {}, { preserveScroll: true, onFinish: this.done }); },
    signOutAll() { this.busy = true; router.post(route('device-limit.sign-out-all'), {}, { onFinish: this.done }); },
    go() { router.visit(route('dashboard')); },
    logout() { this.busy = true; router.post(route('logout'), {}, { onFinish: this.done }); },
  },
};
</script>

<style scoped>
.dl-page { min-height: 100vh; display: grid; place-items: center; padding: 20px 16px; background: radial-gradient(1200px 600px at 10% -10%, #dbe7ff 0, transparent 60%), radial-gradient(900px 500px at 100% 110%, #e2f5ea 0, transparent 55%), #f3f6fb; color: #14213d; }
html.dark .dl-page { background: #0b1220; color: #e8eef9; }
.dl-card { width: min(560px, 100%); padding: 22px 20px; border-radius: 24px; background: #fff; border: 1px solid #e3e9f2; box-shadow: 0 30px 60px -30px rgba(15, 37, 64, 0.45); display: grid; gap: 14px; }
html.dark .dl-card { background: #111a2e; border-color: rgba(255, 255, 255, 0.1); }
.dl-head { display: flex; gap: 14px; align-items: flex-start; }
.dl-head h1 { margin: 0 0 4px; font-size: 1.25rem; font-weight: 800; }
.dl-head p { margin: 0; font-size: 0.9rem; line-height: 1.5; color: #55657a; }
html.dark .dl-head p { color: #9fb0c7; }
.dl-ico { flex: none; display: grid; place-items: center; width: 46px; height: 46px; border-radius: 15px; color: #fff; background: linear-gradient(145deg, #f59e0b, #d97706); box-shadow: 0 10px 18px -10px rgba(217, 119, 6, 0.8); }
.dl-note { margin: 0; padding: 9px 12px; border-radius: 12px; font-size: 0.85rem; font-weight: 600; }
.dl-note.is-ok { background: #dcfce7; color: #166534; } .dl-note.is-bad { background: #fee2e2; color: #991b1b; }
.dl-here { display: grid; gap: 2px; padding: 10px 14px; border-radius: 14px; border: 1.5px dashed #94a3b8; }
.dl-small { font-size: 0.64rem; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; color: #64748b; }
.dl-here em { font-style: normal; font-size: 0.8rem; color: #64748b; }
.dl-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.dl-dev { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 16px; background: #f4f7fb; border: 1px solid #e3e9f2; }
html.dark .dl-dev { background: rgba(255, 255, 255, 0.04); border-color: rgba(255, 255, 255, 0.08); }
.dl-dev-ico { flex: none; display: grid; place-items: center; width: 40px; height: 40px; border-radius: 12px; background: #fff; color: #64748b; border: 1px solid #e3e9f2; }
.dl-dev-ico.is-on { color: #fff; background: linear-gradient(145deg, #34d399, #047857); border-color: transparent; }
.dl-dev-txt { min-width: 0; flex: 1; display: grid; gap: 1px; }
.dl-dev-txt b { font-size: 0.9rem; overflow-wrap: anywhere; } .dl-dev-txt span { font-size: 0.8rem; color: #64748b; }
.dl-dev-txt small { display: inline-flex; align-items: center; gap: 6px; font-size: 0.74rem; color: #64748b; }
.dl-dev-txt small i { width: 7px; height: 7px; border-radius: 50%; background: #94a3b8; } .dl-dev-txt small i.is-on { background: #10b981; }
.dl-empty { padding: 12px; text-align: center; font-size: 0.88rem; color: #55657a; }
.dl-actions { display: grid; gap: 8px; }
.dl-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 14px; border-radius: 14px; border: 1px solid #d3dcea; background: #fff; color: #14213d; font-size: 0.88rem; font-weight: 700; cursor: pointer; text-align: center; }
html.dark .dl-btn { background: rgba(255, 255, 255, 0.06); border-color: rgba(255, 255, 255, 0.14); color: #e8eef9; }
.dl-btn.is-main { background: linear-gradient(135deg, #1b3f86, #12285c); color: #fff; border-color: transparent; }
.dl-btn.is-out { flex: none; padding: 8px 12px; color: #991b1b; border-color: #fecaca; background: #fff5f5; }
.dl-btn:disabled { opacity: 0.55; cursor: not-allowed; }
.dl-link { background: none; border: 0; padding: 6px; font-size: 0.84rem; font-weight: 700; color: #475569; text-decoration: underline; cursor: pointer; }
.dl-foot { margin: 0; font-size: 0.74rem; color: #64748b; text-align: center; }
@media (max-width: 480px) { .dl-dev { flex-wrap: wrap; } .dl-btn.is-out { width: 100%; } }
</style>

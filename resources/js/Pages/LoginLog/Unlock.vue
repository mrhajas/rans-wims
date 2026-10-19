<template>
  <admin-layout :title="$t('Confirm your password')">
    <div class="lg-page px-4 md:px-0 llu-wrap">
      <section class="lg-card llu-card">
        <span class="llu-ico"><icons name="lock-closed" class="w-7 h-7" /></span>
        <h1 class="llu-title">{{ $t('Login Logs are protected') }}</h1>
        <p class="llu-sub">{{ $t('They show who signed in, from where and on what device. Type your password to open them, even though you are signed in.') }}</p>
        <form class="llu-form" autocomplete="on" @submit.prevent="submit">
          <input type="text" name="username" class="llu-hidden" :value="username" autocomplete="username" tabindex="-1" aria-hidden="true" readonly />
          <label for="llu-pass">{{ $t('Your password') }}</label>
          <div class="llu-field">
            <input id="llu-pass" ref="pass" v-model="form.password" :type="show ? 'text' : 'password'" autocomplete="current-password" autofocus />
            <button type="button" class="llu-eye" :aria-label="show ? $t('Hide') : $t('Show')" @click="show = !show"><icons name="eye-flat" class="w-4 h-4" /></button>
          </div>
          <p v-if="form.errors.password" class="llu-err" role="alert">{{ form.errors.password }}</p>
          <p class="llu-hint"><icons name="info" class="w-4 h-4" />{{ $t('A wrong password takes you back to the page you came from.') }}</p>
          <div class="llu-actions">
            <a :href="back" class="lg-btn lg-btn-soft"><icons name="cross" class="w-4 h-4" />{{ $t('Cancel') }}</a>
            <button type="submit" class="lg-btn lg-btn-primary" :disabled="form.processing || !form.password"><icons name="tick" class="w-4 h-4" />{{ $t('Open Login Logs') }}</button>
          </div>
        </form>
      </section>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';

export default {
  components: { AdminLayout },
  props: { back: String },
  data() { return { show: false, form: this.$inertia.form({ password: '' }) }; },
  computed: { username() { const u = (this.$page.props.auth || {}).user || {}; return u.username || u.email || ''; } },
  methods: { submit() { this.form.post(route('login-logs.unlock.check'), { preserveScroll: true }); } },
};
</script>

<style scoped>
.llu-wrap { display: grid; place-items: start center; padding-top: 8vh; }
.llu-card { width: min(460px, 100%); text-align: center; padding: 28px 26px 24px; }
.llu-ico { display: inline-grid; place-items: center; width: 64px; height: 64px; border-radius: 20px; color: #fff; background: linear-gradient(135deg, #2f6199, #1c3f66); box-shadow: 0 14px 24px -14px rgba(28, 63, 102, 0.8); }
.llu-title { margin: 14px 0 6px; font-size: 1.2rem; font-weight: 800; }
.llu-sub { margin: 0 auto 16px; max-width: 36ch; font-size: 0.86rem; color: var(--lg-mute, #64748b); }
.llu-form { text-align: left; display: grid; gap: 8px; }
.llu-form label { font-size: 0.7rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint, #94a3b8); }
.llu-field { position: relative; }
.llu-field input { width: 100%; height: 46px; padding: 0 46px 0 14px; border-radius: 14px; border: 1px solid var(--lg-line, #d6dfeb); background: var(--lg-card, #fff); font-size: 1rem; }
.llu-field input:focus { outline: none; border-color: #3b82c4; box-shadow: 0 0 0 3px rgba(59, 130, 196, 0.22); }
.llu-eye { position: absolute; right: 6px; top: 6px; width: 34px; height: 34px; border: 0; border-radius: 10px; background: transparent; color: #64748b; cursor: pointer; display: grid; place-items: center; }
.llu-eye:hover { background: rgba(148, 163, 184, 0.2); }
.llu-err { margin: 0; font-size: 0.8rem; font-weight: 700; color: #be123c; }
.llu-hint { display: flex; gap: 6px; align-items: center; margin: 2px 0 6px; font-size: 0.76rem; color: var(--lg-mute, #64748b); }
.llu-actions { display: flex; gap: 10px; justify-content: space-between; flex-wrap: wrap; }
.llu-actions .lg-btn { flex: 1 1 140px; justify-content: center; }
.llu-hidden { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
</style>

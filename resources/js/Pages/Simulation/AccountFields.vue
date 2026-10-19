<template>
  <div class="sx-fields">
    <div class="lg-fields">
      <div class="ac-f"><label :for="id + 'u'">{{ $t('Username') }} *</label><input :id="id + 'u'" v-model="state.username" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" /><span class="ac-err">{{ errors.username }}</span></div>
      <div class="ac-f"><label :for="id + 'n'">{{ $t('Full name') }} *</label><input :id="id + 'n'" v-model="state.name" type="text" autocomplete="off" /><span class="ac-err">{{ errors.name }}</span></div>
      <div class="ac-f lg-span-all"><label :for="id + 'p'">{{ edit ? $t('New password (leave empty to keep the current one)') : $t('Password') + ' *' }}</label>
        <div class="sx-pw"><input :id="id + 'p'" v-model="state.password" :type="show ? 'text' : 'password'" autocomplete="new-password" /><button type="button" class="lg-btn t-slate" @click="generate"><icons name="refresh" class="w-4 h-4" />{{ $t('Generate') }}</button><button type="button" class="lg-btn t-slate" @click="show = !show"><icons name="eye-flat" class="w-4 h-4" />{{ show ? $t('Hide') : $t('Show') }}</button></div>
        <span class="ac-err">{{ errors.password }}</span><small class="lg-subtext">{{ hint }}</small></div>
    </div>
    <div class="sx-opts" role="radiogroup" :aria-label="$t('What they can do')">
      <label class="sx-opt" :class="{ 'is-on': state.mode === 'full' }"><input type="radio" class="sx-in" value="full" v-model="state.mode" /><span class="sx-dot"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7" /></svg></span><span class="sx-opt-t"><b>{{ $t('Full access') }}</b><small>{{ $t('Every module, except what runs production itself: backups, licence, gateways and roles.') }}</small><small class="sx-role">{{ $t('Role') }}: {{ role }}</small></span></label>
      <label class="sx-opt" :class="{ 'is-on': state.mode === 'custom' }"><input type="radio" class="sx-in" value="custom" v-model="state.mode" /><span class="sx-dot"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7" /></svg></span><span class="sx-opt-t"><b>{{ $t('Selected modules only') }}</b><small>{{ $t('Choose the modules and abilities this person may use.') }}</small></span></label>
    </div>
    <permission-picker v-if="state.mode === 'custom'" :modules="modules" v-model="state.permissions" />
    <p v-if="state.mode === 'custom' && !state.permissions.length" class="lg-note lg-note-amber"><icons name="info" class="w-4 h-4" /><span>{{ $t('Nothing is selected yet: with no abilities chosen the account gets Full access.') }}</span></p>
  </div>
</template>

<script>
import PermissionPicker from './PermissionPicker.vue';

// The fields of a simulation account (new or edited): username, full name, password, and what the person may do.
export default {
  components: { PermissionPicker },
  props: { state: Object, errors: { type: Object, default: () => ({}) }, modules: Array, hint: String, role: String, edit: Boolean, id: { type: String, default: 'sx' } },
  data() { return { show: false }; },
  methods: {
    generate() {
      // random from the browser's own secure generator; one of each kind is guaranteed so the password policy always accepts it
      const pick = (set, n) => { const b = new Uint32Array(n); crypto.getRandomValues(b); return Array.from(b, x => set[x % set.length]).join(''); };
      const all = (pick('ABCDEFGHJKLMNPQRSTUVWXYZ', 4) + pick('abcdefghijkmnopqrstuvwxyz', 8) + pick('23456789', 4) + pick('!#$%&*+-?', 3)).split('');
      const sh = new Uint32Array(all.length); crypto.getRandomValues(sh);
      this.state.password = all.map((c, i) => [sh[i], c]).sort((x, y) => x[0] - y[0]).map(x => x[1]).join('');
      this.show = true;
    },
  },
};
</script>

<template>
  <admin-layout :title="$t('Low Stock Alert')">
    <div class="lg-page px-4 md:px-0 ls-page">
      <page-header icon="exclamation" :title="$t('Low Stock Alert')" :subtitle="$t('A daily email and / or text message about the materials that are running low: you choose who receives it, at what time, and which materials in which projects.')">
        <a :href="route('low-stock-alert.preview')" target="_blank" rel="noopener" class="lg-btn lg-btn-soft"><icons name="eye" class="w-4 h-4" />{{ $t('Preview email') }}</a>
        <a :href="route('low-stock-alert.pdf')" class="lg-btn lg-btn-primary" :title="$t('Download today\'s alert as a PDF. The download is recorded in the Download / Print Logs.')"><icons name="download" class="w-4 h-4" />{{ $t('Generate PDF') }}</a>
      </page-header>

      <!-- status + right now -->
      <section class="lg-card ls-hero" :class="{ on: form.enabled }">
        <label class="ls-switch ls-switch--big"><input v-model="form.enabled" type="checkbox" /><i></i><span>{{ $t('Low Stock Alert') }} <small>{{ form.enabled ? $t('On: it is sent every day at the time below') : $t('Off: nothing is sent') }}</small></span></label>
        <div class="ls-now">
          <div class="ls-now__nums">
            <div><b>{{ now.totals.items }}</b><small>{{ $t('items low right now') }}</small></div>
            <div><b>{{ now.totals.with_problem }}</b><small>{{ $t('projects with low items') }}</small></div>
            <div class="out"><b>{{ now.totals.out }}</b><small>{{ $t('out of stock') }}</small></div>
          </div>
          <ul v-if="now.top.length" class="ls-now__top"><li v-for="p in now.top" :key="p.name"><span>{{ p.name }}</span><em>{{ p.items }} {{ $t('item(s)') }}<template v-if="p.out"> · {{ p.out }} {{ $t('out') }}</template></em></li></ul>
          <p v-else class="ls-hint">{{ $t('Nothing watched is low right now (saved settings).') }}</p>
        </div>
      </section>

      <form @submit.prevent="save">
        <!-- who and how -->
        <section class="lg-card ls-card">
          <header class="ls-head"><span class="ls-ico ls-ico--amber"><icons name="users" class="w-5 h-5" /></span><div><h2>{{ $t('Who receives it, and how') }}</h2><p>{{ $t('Everyone with the roles you tick, plus the people you pick.') }}</p></div></header>
          <div class="ls-tiles">
            <label class="ls-tile" :class="{ on: form.email, warn: form.email && !channels.email }"><input v-model="form.email" type="checkbox" /><span class="ls-tile__i ls-i-email"><icons name="email" class="w-5 h-5" /></span><b>{{ $t('Email') }}</b><small>{{ form.email && !channels.email ? $t('Email is not set up on this system') : $t('The full report with every project') }}</small></label>
            <label class="ls-tile" :class="{ on: form.sms, warn: form.sms && !channels.sms }"><input v-model="form.sms" type="checkbox" /><span class="ls-tile__i ls-i-sms"><icons name="sms" class="w-5 h-5" /></span><b>{{ $t('Text message') }}</b><small>{{ form.sms && !channels.sms ? $t('The SMS gateway is off in WIMS Connect') : $t('A short summary, no links') }}</small></label>
          </div>
          <p v-if="!form.email && !form.sms" class="ls-warn">{{ $t('Neither is on, so nobody will receive it.') }}</p>

          <h3 class="ls-sub">{{ $t('Roles') }}</h3>
          <div class="ls-roles"><label v-for="r in roles" :key="r" class="ls-chip" :class="{ on: form.role_names.includes(r) }"><input v-model="form.role_names" type="checkbox" :value="r" />{{ r }}</label></div>
          <h3 class="ls-sub">{{ $t('People') }} <small>{{ form.user_ids.length }}</small></h3>
          <div class="ls-pick"><auto-complete id="ls_pick_user" v-model="pickUser" :suggestions="userOptions" reset-search :placeholder="$t('Search a person to add')" /></div>
          <div class="ls-people"><span v-for="u in people" :key="u.id" class="ls-person">{{ u.name }}<button type="button" :aria-label="$t('Remove')" @click="form.user_ids = form.user_ids.filter(x => x !== u.id)">×</button></span><em v-if="!people.length" class="ls-hint">{{ $t('Nobody picked by name yet.') }}</em></div>
          <p class="ls-hint">{{ $t('About') }} {{ recipientCount }} {{ $t('people receive it with the saved settings.') }}</p>

          <label class="ls-switch mt-3"><input v-model="form.scope_by_assignment" type="checkbox" /><i></i><span>{{ $t('Each person only hears about their own projects') }} <small>{{ $t('Super Admin and Admin always see every project. Everyone else sees the projects they are assigned to.') }}</small></span></label>
          <label class="ls-switch"><input v-model="form.only_when_low" type="checkbox" /><i></i><span>{{ $t('Stay quiet when nothing is low') }} <small>{{ $t('If you turn this off, a "all well stocked" email is sent every day.') }}</small></span></label>
          <label class="ls-switch"><input v-model="form.exclude_main_warehouses" type="checkbox" /><i></i><span>{{ $t('Leave out Main Warehouses') }} <small>{{ $t('Main Warehouses are stores, not projects, so they are not listed or counted.') }}</small></span></label>
          <label class="ls-switch"><input v-model="form.show_all_items" type="checkbox" /><i></i><span>{{ $t('Show every project with its quantities') }} <small>{{ $t('On: every project and every watched item is listed with its quantity, and the low or empty ones are highlighted. Off: only projects with something low.') }}</small></span></label>
          <label class="ls-switch"><input v-model="form.attach_pdf" type="checkbox" /><i></i><span>{{ $t('Attach the PDF overview to the email') }} <small>{{ $t('A one-page table of projects against items.') }}</small></span></label>
          <label class="ls-switch"><input v-model="form.warehouse_email" type="checkbox" /><i></i><span>{{ $t('Also email each project\'s own address') }} <small>{{ $t('The older alert to the email saved on a project, for items with their own alert quantity.') }}</small></span></label>
        </section>

        <!-- when -->
        <section class="lg-card ls-card">
          <header class="ls-head"><span class="ls-ico ls-ico--sky"><icons name="clock" class="w-5 h-5" /></span><div><h2>{{ $t('What time') }}</h2><p>{{ $t('East Africa Time. If the system was off at that time it is sent as soon as it is back, once a day.') }}</p></div></header>
          <div class="ls-when">
            <label class="ls-field"><span>{{ $t('Send at') }}</span><input v-model="form.send_time" type="time" class="ls-input" /></label>
            <div class="ls-field"><span>{{ $t('On these days (none ticked = every day)') }}</span><div class="ls-days"><label v-for="d in days" :key="d.v" class="ls-day" :class="{ on: form.send_days.includes(d.v) }"><input v-model="form.send_days" type="checkbox" :value="d.v" />{{ d.l }}</label></div></div>
          </div>
        </section>

        <!-- what to watch -->
        <section class="lg-card ls-card">
          <header class="ls-head"><span class="ls-ico ls-ico--indigo"><icons name="collection" class="w-5 h-5" /></span><div><h2>{{ $t('Which materials, in which projects') }}</h2><p>{{ $t('Make a group for each kind of material. Each group has its own words to find the items, its own projects and its own low level, so different items can be watched in different projects.') }}</p></div></header>

          <div v-for="(g, gi) in form.groups" :key="g.id" class="ls-group" :class="{ off: !g.enabled }" :style="{ '--c': g.color }">
            <div class="ls-group__top">
              <input v-model="g.color" type="color" class="ls-color" :title="$t('Colour in the email')" />
              <input v-model="g.name" class="ls-input ls-name" maxlength="60" :placeholder="$t('Group name, for example Cement')" />
              <label class="ls-switch ls-switch--sm" :title="$t('Watch this group')"><input v-model="g.enabled" type="checkbox" /><i></i></label>
              <button type="button" class="ls-x" :aria-label="$t('Delete this group')" @click="removeGroup(gi)"><icons name="trash" class="w-4 h-4" /></button>
            </div>

            <div class="ls-cols">
              <div>
                <h4>{{ $t('Find items whose name contains') }}</h4>
                <div class="ls-tags"><span v-for="(k, ki) in g.keywords" :key="k + ki" class="ls-tag">{{ k }}<button type="button" :aria-label="$t('Remove')" @click="g.keywords.splice(ki, 1)">×</button></span>
                  <input class="ls-tagin" :placeholder="$t('type a word, press Enter')" @keydown="tagKey(g.keywords, $event)" @blur="addTag(g.keywords, $event)" /></div>
                <h4>{{ $t('...but leave out names containing') }}</h4>
                <div class="ls-tags ls-tags--out"><span v-for="(k, ki) in g.exclude" :key="k + ki" class="ls-tag">{{ k }}<button type="button" :aria-label="$t('Remove')" @click="g.exclude.splice(ki, 1)">×</button></span>
                  <input class="ls-tagin" :placeholder="$t('e.g. white, sample')" @keydown="tagKey(g.exclude, $event)" @blur="addTag(g.exclude, $event)" /></div>
                <h4>{{ $t('Also watch these exact items') }}</h4>
                <auto-complete :id="'ls_item_' + g.id" json keep-focus reset-search :suggestions="route('items.search')" :placeholder="$t('Search an item to add')" @update:model-value="it => addItem(g, it)" />
                <div class="ls-tags"><span v-for="id in g.item_ids" :key="id" class="ls-tag ls-tag--item">{{ itemName(id) }}<button type="button" :aria-label="$t('Remove')" @click="g.item_ids = g.item_ids.filter(x => x !== id)">×</button></span></div>
              </div>

              <div>
                <h4>{{ $t('Low level') }}</h4>
                <label class="ls-level"><input v-model.number="g.below" type="number" min="0" step="any" class="ls-input" /><span>{{ $t('Alert when the stock is at or below this number') }}</span></label>
                <p class="ls-hint">{{ $t('Out of stock (zero) is always reported. Use 0 to rely on each item\'s own alert quantity instead.') }}</p>

                <h4>{{ $t('In which projects') }}</h4>
                <div class="ls-modes"><label :class="{ on: g.projects.mode === 'all' }"><input v-model="g.projects.mode" type="radio" value="all" />{{ $t('All projects') }}</label><label :class="{ on: g.projects.mode === 'selected' }"><input v-model="g.projects.mode" type="radio" value="selected" />{{ $t('Only selected') }}</label></div>
                <template v-if="g.projects.mode === 'selected'">
                  <input v-model="projectFilter[g.id]" type="search" class="ls-input" :placeholder="$t('Filter projects')" />
                  <div class="ls-proj"><label v-for="p in filteredProjects(g)" :key="p.id" class="ls-chip" :class="{ on: g.projects.ids.includes(p.id) }"><input v-model="g.projects.ids" type="checkbox" :value="p.id" />{{ p.name }}</label></div>
                  <p class="ls-hint">{{ g.projects.ids.length }} {{ $t('selected') }} <button type="button" class="ls-link" @click="g.projects.ids = projects.map(p => p.id)">{{ $t('Select all') }}</button> <button type="button" class="ls-link" @click="g.projects.ids = []">{{ $t('Clear') }}</button></p>
                </template>
              </div>
            </div>
          </div>

          <button type="button" class="ls-btn ls-btn--soft" @click="addGroup"><icons name="plus" class="w-4 h-4" />{{ $t('Add a group of materials') }}</button>
        </section>

        <div class="ls-save">
          <div class="ls-tests">
            <input v-model="testTo" type="email" class="ls-input" :placeholder="$t('Send a sample to...')" />
            <button type="button" class="ls-btn ls-btn--soft" :disabled="!testTo || busy" @click="sendTest"><icons name="send" class="w-4 h-4" />{{ $t('Send sample') }}</button>
            <button type="button" class="ls-btn ls-btn--soft" :disabled="busy" @click="sendNow"><icons name="bell" class="w-4 h-4" />{{ $t('Send to everyone now') }}</button>
          </div>
          <button type="submit" class="ls-btn ls-btn--go" :disabled="busy"><icons name="tick" class="w-4 h-4" />{{ $t('Save') }}</button>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';

const COLORS = ['#b45309', '#0f766e', '#4f46e5', '#be123c', '#0369a1', '#7c3aed'];

export default {
  components: { AdminLayout, PageHeader, AutoComplete },
  props: { config: Object, users: Array, roles: Array, projects: Array, pickedItems: Array, now: Object, channels: Object, myEmail: String, recipientCount: Number },
  data() {
    return {
      form: JSON.parse(JSON.stringify(this.config)), busy: false, pickUser: '', testTo: this.myEmail || '', projectFilter: {},
      itemNames: Object.fromEntries((this.pickedItems || []).map(i => [i.id, i.name])),
      days: [{ v: 1, l: 'Mon' }, { v: 2, l: 'Tue' }, { v: 3, l: 'Wed' }, { v: 4, l: 'Thu' }, { v: 5, l: 'Fri' }, { v: 6, l: 'Sat' }, { v: 0, l: 'Sun' }],
    };
  },
  computed: {
    userOptions() { const taken = new Set(this.form.user_ids); return this.users.filter(u => !taken.has(u.id)).map(u => ({ id: u.id, label: u.name + (u.email ? ' (' + u.email + ')' : '') })); },
    people() { return this.form.user_ids.map(id => this.users.find(u => u.id === id)).filter(Boolean); },
  },
  watch: {
    pickUser(id) { if (id) { if (!this.form.user_ids.includes(id)) this.form.user_ids.push(id); this.$nextTick(() => (this.pickUser = '')); } },
  },
  methods: {
    tagKey(list, e) { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); this.addTag(list, e); } },
    addTag(list, e) {
      const v = (e.target.value || '').replace(/,/g, '').trim();
      if (v && !list.some(x => x.toLowerCase() === v.toLowerCase())) list.push(v);
      e.target.value = '';
    },
    addItem(g, it) {
      if (!it || typeof it !== 'object' || !it.id) return;
      this.itemNames[it.id] = it.name;
      if (!g.item_ids.includes(it.id)) g.item_ids.push(it.id);
    },
    itemName(id) { return this.itemNames[id] || '#' + id; },
    filteredProjects(g) { const q = (this.projectFilter[g.id] || '').toLowerCase(); return this.projects.filter(p => !q || p.name.toLowerCase().includes(q)); },
    addGroup() {
      const n = this.form.groups.length;
      this.form.groups.push({ id: 'g' + Date.now().toString(36), name: '', enabled: true, color: COLORS[n % COLORS.length], keywords: [], exclude: [], item_ids: [], below: 10, projects: { mode: 'all', ids: [] } });
    },
    async removeGroup(i) { if (await this.$confirm(this.$t('Delete this group of materials?'))) this.form.groups.splice(i, 1); },
    save() { this.busy = true; router.put(this.route('low-stock-alert.update'), this.form, { preserveScroll: true, onFinish: () => (this.busy = false) }); },
    sendTest() { this.busy = true; router.post(this.route('low-stock-alert.test'), { to: this.testTo }, { preserveScroll: true, onFinish: () => (this.busy = false) }); },
    async sendNow() { if (await this.$confirm(this.$t('Send today\'s Low Stock Alert to everyone chosen, right now? Save your changes first.'))) { this.busy = true; router.post(this.route('low-stock-alert.send-now'), {}, { preserveScroll: true, onFinish: () => (this.busy = false) }); } },
  },
};
</script>

<style>
.ls-page { display: grid; gap: 1rem; }
.ls-card { padding: 1.2rem 1.3rem; margin-bottom: 1rem; }
.ls-hero { display: grid; grid-template-columns: minmax(0, 0.8fr) minmax(0, 1.2fr); gap: 1.2rem; align-items: center; padding: 1.2rem 1.3rem; background: linear-gradient(135deg, #fffbeb, #fff1e0 55%, #ecfdf5) !important; color: #14532d; box-shadow: inset 0 0 0 1px rgba(245, 158, 11, 0.4) !important; }
.ls-hero.on { animation: ls-glow 3.4s ease-in-out infinite; } @keyframes ls-glow { 0%, 100% { box-shadow: inset 0 0 0 1px rgba(245, 158, 11, 0.35), 0 0 0 rgba(251, 146, 60, 0); } 50% { box-shadow: inset 0 0 0 1px rgba(234, 88, 12, 0.6), 0 0 22px rgba(251, 146, 60, 0.25); } }
.ls-now__nums { display: flex; gap: 0.7rem; } .ls-now__nums > div { flex: 1; padding: 0.55rem 0.4rem; border-radius: 12px; text-align: center; background: rgba(255, 255, 255, 0.75); box-shadow: inset 0 0 0 1px rgba(180, 83, 9, 0.2); } .ls-now__nums b { display: block; font-size: 1.5rem; line-height: 1.1; color: #b45309; } .ls-now__nums .out b { color: #be123c; } .ls-now__nums small { font-size: 0.62rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #5b7a66; }
.ls-now__top { margin: 0.6rem 0 0; padding: 0; list-style: none; display: grid; gap: 3px; font-size: 0.8rem; } .ls-now__top li { display: flex; justify-content: space-between; gap: 0.6rem; padding: 3px 8px; border-radius: 8px; background: rgba(255, 255, 255, 0.55); } .ls-now__top em { font-style: normal; font-weight: 700; color: #c2410c; }
.ls-head { display: flex; align-items: center; gap: 0.8rem; margin-bottom: 0.9rem; } .ls-head h2 { margin: 0; font-size: 1.05rem; font-weight: 800; color: var(--lg-ink); } .ls-head p { margin: 0.1rem 0 0; font-size: 0.8rem; color: var(--lg-mute); }
.ls-ico { display: grid; place-items: center; flex: none; width: 42px; height: 42px; border-radius: 14px; color: #fff; box-shadow: 0 10px 18px -10px rgba(15, 23, 42, 0.7); } .ls-ico--amber { background: linear-gradient(145deg, #f59e0b, #b45309); } .ls-ico--sky { background: linear-gradient(145deg, #38bdf8, #0369a1); } .ls-ico--indigo { background: linear-gradient(145deg, #818cf8, #4338ca); }
.ls-sub { margin: 1rem 0 0.4rem; font-size: 0.78rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-navy, #0f2540); } .ls-sub small { padding: 1px 7px; border-radius: 99px; background: var(--lg-soft); font-size: 0.7rem; }
.ls-hint { margin: 0.3rem 0 0; font-size: 0.76rem; color: var(--lg-mute); } .ls-warn { margin: 0.5rem 0 0; font-size: 0.8rem; font-weight: 700; color: #be123c; }
.ls-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.7rem; } .ls-tile { position: relative; display: grid; grid-template-columns: auto 1fr; grid-template-areas: 'i b' 'i s'; gap: 0 0.7rem; align-items: center; padding: 0.8rem 0.9rem; border-radius: 16px; cursor: pointer; background: var(--lg-card, #fff); box-shadow: inset 0 0 0 1.5px var(--lg-line); transition: all 0.18s; } .ls-tile input { position: absolute; opacity: 0; } .ls-tile__i { grid-area: i; display: grid; place-items: center; width: 42px; height: 42px; border-radius: 13px; color: #64748b; background: var(--lg-soft); } .ls-tile b { grid-area: b; font-size: 0.92rem; color: var(--lg-ink); } .ls-tile small { grid-area: s; font-size: 0.72rem; color: var(--lg-faint); }
.ls-tile.on { box-shadow: inset 0 0 0 2px #10b981, 0 8px 16px -10px rgba(16, 185, 129, 0.7); } .ls-tile.on .ls-i-email { color: #fff; background: linear-gradient(145deg, #38bdf8, #0369a1); } .ls-tile.on .ls-i-sms { color: #fff; background: linear-gradient(145deg, #22c55e, #15803d); } .ls-tile.warn { box-shadow: inset 0 0 0 2px #f59e0b; } .ls-tile.warn small { color: #b45309; font-weight: 700; } .ls-tile:focus-within { outline: 3px solid rgba(99, 102, 241, 0.4); }
.ls-roles, .ls-people, .ls-proj { display: flex; flex-wrap: wrap; gap: 0.35rem; margin-bottom: 0.4rem; } .ls-proj { max-height: 190px; overflow: auto; margin-top: 0.5rem; }
.ls-chip { padding: 4px 11px; border-radius: 99px; font-size: 0.75rem; font-weight: 700; cursor: pointer; color: var(--lg-mute); background: var(--lg-soft); box-shadow: inset 0 0 0 1px var(--lg-line); transition: all 0.15s; } .ls-chip input { position: absolute; opacity: 0; } .ls-chip.on { color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); }
.ls-person { display: inline-flex; align-items: center; gap: 6px; padding: 3px 4px 3px 10px; border-radius: 99px; font-size: 0.78rem; font-weight: 700; color: #065f46; background: #d1fae5; } .ls-person button { width: 19px; height: 19px; border-radius: 50%; background: rgba(0, 0, 0, 0.12); color: inherit; line-height: 1; }
.ls-pick .mb-2 { margin-bottom: 0.3rem; }
.ls-switch { display: flex; align-items: flex-start; gap: 0.7rem; margin: 0.45rem 0; font-weight: 700; font-size: 0.88rem; cursor: pointer; } .ls-switch input { position: absolute; opacity: 0; width: 1px; height: 1px; }
.ls-switch i { position: relative; flex: none; width: 42px; height: 24px; margin-top: 1px; border-radius: 99px; background: #cbd5e1; transition: background 0.25s; } .ls-switch i::after { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3); transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1); } .ls-switch input:checked + i { background: linear-gradient(135deg, #10b981, #059669); } .ls-switch input:checked + i::after { transform: translateX(18px); } .ls-switch input:focus-visible + i { outline: 3px solid rgba(99, 102, 241, 0.5); }
.ls-switch small { display: block; font-weight: 600; font-size: 0.72rem; opacity: 0.7; } .ls-switch--big i { width: 54px; height: 30px; } .ls-switch--big i::after { width: 24px; height: 24px; } .ls-switch--big input:checked + i::after { transform: translateX(24px); } .ls-switch--big { font-size: 1.1rem; align-items: center; } .ls-switch--sm { margin: 0; }
.ls-when { display: flex; flex-wrap: wrap; gap: 1.2rem; align-items: flex-start; } .ls-field { display: grid; gap: 0.3rem; font-size: 0.78rem; font-weight: 700; color: var(--lg-mute); }
.ls-input { width: 100%; min-height: 40px; padding: 0.45rem 0.75rem; border-radius: 12px; font-weight: 600; font-size: 0.88rem; color: var(--lg-ink); background: var(--lg-card, #fff); border: 1px solid var(--lg-line); transition: border-color 0.2s, box-shadow 0.2s; } .ls-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2); } .ls-when .ls-input { width: 150px; }
.ls-days { display: flex; flex-wrap: wrap; gap: 4px; } .ls-day { padding: 6px 12px; border-radius: 10px; font-size: 0.78rem; cursor: pointer; background: var(--lg-soft); box-shadow: inset 0 0 0 1px var(--lg-line); } .ls-day input { position: absolute; opacity: 0; } .ls-day.on { color: #fff; background: linear-gradient(145deg, #10b981, #047857); }
.ls-group { position: relative; margin-bottom: 0.9rem; padding: 0.9rem 1rem; border-radius: 18px; background: var(--lg-soft, #f8fafc); box-shadow: inset 4px 0 0 var(--c), inset 0 0 0 1px var(--lg-line); transition: opacity 0.2s; } .ls-group.off { opacity: 0.55; }
.ls-group__top { display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.7rem; } .ls-color { flex: none; width: 36px; height: 36px; padding: 0; border: 0; border-radius: 10px; background: none; cursor: pointer; } .ls-name { font-size: 1rem; font-weight: 800; } .ls-x { display: grid; place-items: center; flex: none; width: 34px; height: 34px; border: 0; border-radius: 10px; cursor: pointer; color: #be123c; background: rgba(244, 63, 94, 0.1); }
.ls-cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem; } .ls-cols h4 { margin: 0.7rem 0 0.3rem; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: var(--lg-mute); } .ls-cols h4:first-child { margin-top: 0; }
.ls-tags { display: flex; flex-wrap: wrap; align-items: center; gap: 0.35rem; padding: 0.35rem; border-radius: 12px; background: var(--lg-card, #fff); box-shadow: inset 0 0 0 1px var(--lg-line); min-height: 42px; } .ls-tag { display: inline-flex; align-items: center; gap: 6px; padding: 3px 4px 3px 10px; border-radius: 99px; font-size: 0.78rem; font-weight: 700; color: #92400e; background: #fef3c7; } .ls-tags--out .ls-tag { color: #9f1239; background: #ffe4e6; } .ls-tag--item { color: #3730a3; background: #e0e7ff; } .ls-tag button { width: 18px; height: 18px; border-radius: 50%; background: rgba(0, 0, 0, 0.12); color: inherit; line-height: 1; } .ls-tagin { flex: 1; min-width: 130px; padding: 4px 6px; border: 0; background: transparent; font-size: 0.82rem; font-weight: 600; color: var(--lg-ink); outline: none; }
.ls-level { display: flex; align-items: center; gap: 0.6rem; } .ls-level .ls-input { width: 110px; font-size: 1.1rem; font-weight: 800; } .ls-level span { font-size: 0.8rem; color: var(--lg-mute); }
.ls-modes { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 0.5rem; } .ls-modes label { padding: 6px 12px; border-radius: 10px; font-size: 0.78rem; font-weight: 700; cursor: pointer; background: var(--lg-card, #fff); box-shadow: inset 0 0 0 1.5px var(--lg-line); } .ls-modes input { position: absolute; opacity: 0; } .ls-modes label.on { box-shadow: inset 0 0 0 2px #2f6fb3; color: var(--lg-navy); } .ls-link { font-weight: 800; color: #2f6fb3; margin-left: 6px; }
.ls-btn { display: inline-flex; align-items: center; gap: 0.45rem; padding: 0.6rem 1.1rem; border-radius: 12px; font-weight: 800; font-size: 0.84rem; border: 0; cursor: pointer; transition: transform 0.15s; } .ls-btn:hover:not(:disabled) { transform: translateY(-1px); } .ls-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.ls-btn--soft { color: var(--lg-ink); background: var(--lg-soft); box-shadow: inset 0 0 0 1px var(--lg-line); } .ls-btn--go { color: #fff; background: linear-gradient(135deg, #059669, #10b981); box-shadow: 0 12px 22px -12px rgba(5, 150, 105, 0.9); }
.ls-save { position: sticky; bottom: 12px; z-index: 5; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.8rem; padding: 0.7rem 1rem; border-radius: 16px; background: var(--lg-card, #fff); box-shadow: 0 14px 30px -14px rgba(15, 23, 42, 0.5), 0 0 0 1px var(--lg-line); } .ls-tests { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; } .ls-tests .ls-input { width: 240px; }
html.dark .ls-hero { background: linear-gradient(135deg, #3b2a0b, #0a2a40 60%, #07142b) !important; color: #ecfdf5; box-shadow: inset 0 0 0 1px rgba(250, 204, 21, 0.3) !important; } html.dark .ls-now__nums > div { background: rgba(255, 255, 255, 0.07); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.12); } html.dark .ls-now__nums small { color: #94a3b8; } html.dark .ls-now__top li { background: rgba(255, 255, 255, 0.06); }
@media (max-width: 760px) { .ls-hero { grid-template-columns: 1fr; } .ls-tests .ls-input { width: 100%; } }
@media (prefers-reduced-motion: reduce) { .ls-hero.on { animation: none; } }
</style>

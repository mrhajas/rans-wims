<template>
  <admin-layout :title="$t('Site Meetings Settings')">
    <div class="lg-page px-4 md:px-0">
      <page-header icon="cog" :title="$t('Site Meetings Settings')" :subtitle="$t('The week, reminders, wording and the layout of the minutes document.')">
        <Link :href="route('site-meetings.index')" class="lg-btn lg-btn-soft"><icons name="calendar" class="w-4 h-4" />{{ $t('Schedule') }}</Link>
      </page-header>

      <div class="sm-tabs" role="tablist">
        <button type="button" class="sm-tab" :class="{ 'is-on': tab === 'general' }" role="tab" @click="tab = 'general'">{{ $t('General') }}</button>
        <button type="button" class="sm-tab" :class="{ 'is-on': tab === 'notify' }" role="tab" @click="tab = 'notify'">{{ $t('People of the meeting') }}</button>
        <button type="button" class="sm-tab" :class="{ 'is-on': tab === 'layout' }" role="tab" @click="tab = 'layout'">{{ $t('Minutes layout') }}</button>
      </div>

      <!-- ------------------------------------------------------------------------------------ general -->
      <form v-show="tab === 'general'" @submit.prevent="saveGeneral">
        <section class="lg-card sm-card-section">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-sky"><icons name="calendar" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Weekly board') }}</h2><p class="lg-card-sub">{{ $t('Which days the schedule shows and when a week starts.') }}</p></div></header>
          <div class="sm-fields">
            <div class="sm-field">
              <label for="ss_ws">{{ $t('Week starts on') }}</label>
              <auto-complete id="ss_ws" v-model="g.week_start" :suggestions="weekdayOptions" :placeholder="$t('Choose a day…')" />
            </div>
            <div class="sm-field is-wide">
              <label>{{ $t('Days shown on the board') }}</label>
              <div class="sm-days"><button v-for="d in weekdays" :key="d.v" type="button" class="sm-day-toggle" :class="{ 'is-on': g.weekdays.includes(d.v) }" :aria-pressed="g.weekdays.includes(d.v)" @click="toggleDay(d.v)">{{ $t(d.l) }}</button></div>
              <span v-if="errors.weekdays" class="sm-err">{{ errors.weekdays }}</span>
            </div>
          </div>
        </section>

        <section class="lg-card sm-card-section">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-violet"><icons name="clock" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('New meeting defaults') }}</h2><p class="lg-card-sub">{{ $t('What a new meeting starts with.') }}</p></div></header>
          <div class="sm-fields">
            <div class="sm-field"><label for="ss_t">{{ $t('Start time') }}</label><input id="ss_t" v-model="g.default_start_time" type="time" class="sm-input" /></div>
            <div class="sm-field"><label for="ss_d">{{ $t('Length (minutes)') }}</label><input id="ss_d" v-model.number="g.default_duration_min" type="number" min="15" max="720" class="sm-input" /></div>
            <div class="sm-field"><label for="ss_v">{{ $t('Venue') }}</label><input id="ss_v" v-model="g.default_venue" type="text" maxlength="190" class="sm-input" /></div>
            <div class="sm-field"><label for="ss_p">{{ $t('Title wording') }}</label><input id="ss_p" v-model="g.title_prefix" type="text" maxlength="80" class="sm-input" /><span class="sm-hint">{{ g.title_prefix }} 07</span></div>
            <div class="sm-field"><label for="ss_r">{{ $t('Longest weekly repeat (weeks)') }}</label><input id="ss_r" v-model.number="g.repeat_max_weeks" type="number" min="0" max="52" class="sm-input" /></div>
          </div>
        </section>

        <section class="lg-card sm-card-section">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-amber"><icons name="bell" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Reminders & history') }}</h2><p class="lg-card-sub">{{ $t('Who is told, on which channel and with which words is set on the Site Meetings Workflow page.') }}</p></div></header>
          <div class="sm-fields">
            <label class="sm-switch"><input v-model="g.reminders_enabled" type="checkbox" /><span>{{ $t('Daily reminders') }}<small>{{ $t('Reminds invited people on the day, and the chosen number of days before.') }}</small></span></label>
            <div class="sm-field"><label for="ss_rd">{{ $t('Remind this many days before') }}</label><input id="ss_rd" v-model.number="g.reminder_days_before" type="number" min="0" max="14" class="sm-input" :disabled="!g.reminders_enabled" /></div>
            <div class="sm-field"><label for="ss_hv">{{ $t('Count repeated views within (minutes) as one') }}</label><input id="ss_hv" v-model.number="g.history_view_minutes" type="number" min="1" max="1440" class="sm-input" /></div>
          </div>
        </section>

        <section class="lg-card sm-card-section">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-teal"><icons name="doc-text" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Printed documents') }}</h2><p class="lg-card-sub">{{ $t('The two signature lines at the end of the meeting sheet and the minutes.') }}</p></div></header>
          <div class="sm-fields">
            <div class="sm-field"><label for="ss_sl">{{ $t('Left signature') }}</label><input id="ss_sl" v-model="g.sign_left" type="text" maxlength="80" class="sm-input" /></div>
            <div class="sm-field"><label for="ss_sr">{{ $t('Right signature') }}</label><input id="ss_sr" v-model="g.sign_right" type="text" maxlength="80" class="sm-input" /></div>
          </div>
        </section>

        <section class="lg-card sm-card-section">
          <header class="lg-card-head"><span class="lg-card-ico lg-ico-rose"><icons name="star" class="w-5 h-5" /></span><div class="min-w-0"><h2 class="lg-card-title">{{ $t('Status wording & colours') }}</h2><p class="lg-card-sub">{{ $t('How each status is named and coloured everywhere.') }}</p></div></header>
          <div v-for="k in styleKeys" :key="k" class="sm-style-row">
            <span class="sm-chip" :class="'sm-c-' + st[k].color">{{ k }}</span>
            <input v-model="st[k].label" type="text" maxlength="40" class="sm-input" :aria-label="k" />
            <div class="sm-swatches"><button v-for="c in colors" :key="c" type="button" class="sm-swatch" :class="['sm-c-' + c, { 'is-on': st[k].color === c }]" :aria-label="c" :aria-pressed="st[k].color === c" @click="st[k].color = c"></button></div>
          </div>
        </section>

        <div class="sm-bar"><span class="sm-bar-note">{{ $t('Changes apply to everyone straight away.') }}</span><div class="sm-bar-btns"><button type="submit" class="lg-btn lg-btn-green" :disabled="saving"><icons name="tick" class="w-4 h-4" />{{ saving ? $t('Saving…') : $t('Save settings') }}</button></div></div>
      </form>

      <!-- ------------------------------------------------------------------------------------ notifications -->
      <div v-show="tab === 'notify'">
        <p class="sm-note sm-c-sky"><icons name="info" class="w-5 h-5 flex-none" /><span>{{ $t('For every notice choose which people of the meeting it is for. Channels (bell, SMS, e-mail, WhatsApp), extra roles or people and the wording are set on the Site Meetings Workflow page. The person who does the action is never notified about it.') }}</span></p>
        <p v-if="canOpenWorkflow" class="mb-3"><Link :href="route('site-meetings.workflow')" class="lg-btn lg-btn-soft"><icons name="bell" class="w-4 h-4" />{{ $t('Open the Site Meetings Workflow') }}</Link></p>

        <section v-for="ev in events" :key="ev.key" class="lg-card sm-card-section sm-rule">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-indigo"><icons name="bell" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1"><h2 class="lg-card-title">{{ $t(ev.label) }}</h2><p class="lg-card-sub">{{ $t(ev.hint) }}</p></div>
          </header>
          <div class="sm-label mt-1 mb-1">{{ $t('Who receives it') }}</div>
          <div class="sm-days">
            <button v-for="w in whoGroups" :key="w.key" type="button" class="sm-day-toggle" :class="{ 'is-on': rl[ev.key].who.includes(w.key) }" :aria-pressed="rl[ev.key].who.includes(w.key)" @click="toggleWho(ev.key, w.key)">{{ $t(w.label) }}</button>
          </div>
        </section>

        <div class="sm-bar"><span class="sm-bar-note">{{ $t('Applies to new notifications straight away.') }}</span><div class="sm-bar-btns"><button type="button" class="lg-btn lg-btn-green" :disabled="saving" @click="saveNotifications"><icons name="tick" class="w-4 h-4" />{{ saving ? $t('Saving…') : $t('Save') }}</button></div></div>
      </div>

      <!-- ------------------------------------------------------------------------------------ layout -->
      <div v-show="tab === 'layout'">
        <p class="sm-note sm-c-sky"><icons name="info" class="w-5 h-5 flex-none" /><span>{{ $t('This is the layout of the minutes document: its sections, their columns and the rows each one starts with. New meetings use the layout as it is when they are scheduled; meetings already scheduled keep theirs, so old minutes never change. Keep a column’s name when you only want to reword it.') }}</span></p>

        <section v-for="(sec, i) in sections" :key="sec._k" class="sm-sec-edit" :class="['sm-c-' + sec.color, { 'is-off': !sec.enabled }]">
          <div class="sm-sec-top">
            <span class="sm-doc-no">{{ i + 1 }}</span>
            <input v-model="sec.title" type="text" maxlength="120" class="sm-input" :aria-label="$t('Section title')" :placeholder="$t('Section title')" />
            <label class="sm-switch" style="padding: 6px 10px"><input v-model="sec.enabled" type="checkbox" /><span>{{ $t('Used') }}</span></label>
            <button type="button" class="sm-iconbtn" :disabled="i === 0" :aria-label="$t('Move up')" @click="move(i, -1)"><icons name="chevron-down" class="w-4 h-4 transform rotate-180" /></button>
            <button type="button" class="sm-iconbtn" :disabled="i === sections.length - 1" :aria-label="$t('Move down')" @click="move(i, 1)"><icons name="chevron-down" class="w-4 h-4" /></button>
            <button type="button" class="sm-iconbtn is-danger" :aria-label="$t('Remove section')" @click="removeSection(i)"><icons name="trash" class="w-4 h-4" /></button>
          </div>
          <div class="sm-sec-body">
            <div class="sm-fields">
              <div class="sm-field"><label>{{ $t('Kind') }}</label><auto-complete :id="'sec_type_' + i" v-model="sec.type" :suggestions="kindOptions" :placeholder="$t('Choose a kind…')" /></div>
              <div class="sm-field"><label>{{ $t('Icon') }}</label><auto-complete :id="'sec_icon_' + i" v-model="sec.icon" :suggestions="iconOptions" :placeholder="$t('Search icon…')" /></div>
              <div class="sm-field is-wide"><label>{{ $t('Colour') }}</label><div class="sm-swatches"><button v-for="c in colors" :key="c" type="button" class="sm-swatch" :class="['sm-c-' + c, { 'is-on': sec.color === c }]" :aria-label="c" :aria-pressed="sec.color === c" @click="sec.color = c"></button></div></div>
              <div class="sm-field is-wide"><label>{{ $t('Hint shown under the title') }}</label><input v-model="sec.hint" type="text" maxlength="200" class="sm-input" /></div>
            </div>

            <template v-if="sec.type === 'table'">
              <label class="sm-switch"><input v-model="sec.grouped" type="checkbox" /><span>{{ $t('Allow headings that group the rows') }}<small>{{ $t('e.g. Mobilisation, Substructure. Rows are then numbered 9.1.1, 9.1.2…') }}</small></span></label>
              <div>
                <div class="sm-label mb-1">{{ $t('Columns') }}</div>
                <div v-for="(c, ci) in sec.columns" :key="c._k" class="sm-col-edit mb-2">
                  <input v-model="c.label" type="text" maxlength="80" class="sm-input" :aria-label="$t('Column name')" :placeholder="$t('Column name')" />
                  <auto-complete :id="'col_type_' + i + '_' + ci" class="sm-col-type" v-model="c.type" :suggestions="columnTypeOptions" :placeholder="$t('Column type')" />
                  <input v-model.number="c.width" type="number" min="0" max="60" class="sm-input" :aria-label="$t('Width %')" :placeholder="'%'" />
                  <span class="inline-flex gap-1">
                    <button type="button" class="sm-iconbtn" :disabled="ci === 0" :aria-label="$t('Move left')" @click="moveCol(sec, ci, -1)"><icons name="chevron-down" class="w-4 h-4 transform rotate-90" /></button>
                    <button type="button" class="sm-iconbtn" :disabled="ci === sec.columns.length - 1" :aria-label="$t('Move right')" @click="moveCol(sec, ci, 1)"><icons name="chevron-down" class="w-4 h-4 transform -rotate-90" /></button>
                    <button type="button" class="sm-iconbtn is-danger" :disabled="sec.columns.length <= 1" :aria-label="$t('Remove column')" @click="sec.columns.splice(ci, 1)"><icons name="cross" class="w-4 h-4" /></button>
                  </span>
                  <input v-if="c.type === 'select'" v-model="c.optionsText" type="text" class="sm-input" style="grid-column: 1 / -1" :placeholder="$t('Choices, separated by commas')" />
                </div>
                <button type="button" class="lg-btn lg-btn-soft" :disabled="sec.columns.length >= 12" @click="addColumn(sec)"><icons name="plus" class="w-4 h-4" />{{ $t('Add column') }}</button>
              </div>
              <div class="sm-field">
                <label>{{ $t('Rows every new meeting starts with') }}</label>
                <textarea v-model="sec.rowsText" class="sm-textarea" rows="6" :placeholder="rowsHint(sec)"></textarea>
                <span class="sm-hint">{{ $t('One row per line, cells separated by | in the order of the columns.') }}<template v-if="sec.grouped"> {{ $t('Start a line with ## for a heading.') }}</template></span>
              </div>
            </template>
          </div>
        </section>

        <div class="sm-addbar mb-3"><button type="button" class="lg-btn lg-btn-soft" :disabled="sections.length >= 30" @click="addSection"><icons name="plus" class="w-4 h-4" />{{ $t('Add section') }}</button></div>

        <div class="sm-bar">
          <span class="sm-bar-note">{{ isDefaultTemplate ? $t('Using the WIMS default layout (the RANS Management Meeting form).') : $t('Using your own layout.') }}</span>
          <div class="sm-bar-btns">
            <button type="button" class="lg-btn lg-btn-soft" :disabled="saving || isDefaultTemplate" @click="resetLayout"><icons name="refresh" class="w-4 h-4" />{{ $t('Reset to default') }}</button>
            <button type="button" class="lg-btn lg-btn-green" :disabled="saving" @click="saveLayout"><icons name="tick" class="w-4 h-4" />{{ saving ? $t('Saving…') : $t('Save layout') }}</button>
          </div>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';

let seq = 0;
const slug = s => String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 40);

export default {
  components: { AdminLayout, PageHeader, AutoComplete },

  props: { config: Object, styles: Object, template: Array, colors: Array, columnTypes: Array, weekdays: Array, isDefaultTemplate: Boolean, rules: Object, events: Array, whoGroups: Array, canOpenWorkflow: Boolean },

  data() {
    const g = { ...this.config };
    g.week_start = String(g.week_start ?? 1);
    g.weekdays = String(g.weekdays || '').split(',').filter(x => x !== '').map(Number);
    ['reminders_enabled'].forEach(k => (g[k] = !!Number(g[k])));
    return {
      tab: 'general',
      g,
      st: JSON.parse(JSON.stringify(this.styles)),
      sections: this.template.map(s => this.toEdit(s)),
      saving: false,
      errors: {},
      rl: JSON.parse(JSON.stringify(this.rules)),
      icons: ['doc-text', 'users', 'user', 'collection', 'key', 'exclamation', 'chart-pie', 'chart-bar', 'tick', 'calendar', 'clock', 'building', 'truck', 'cart', 'camera', 'folder', 'info', 'star', 'heart', 'bell', 'lock-closed', 'clip', 'map-pin', 'cash', 'calculator'],
    };
  },

  computed: {
    weekdayOptions() {
      const full = { 0: 'Sunday', 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday', 6: 'Saturday' };
      return (this.weekdays || []).map(d => ({ value: String(d.v), label: this.$t(full[d.v] || d.l) }));
    },
    kindOptions() {
      return [{ value: 'table', label: this.$t('Table (rows and columns)') }, { value: 'text', label: this.$t('Free text') }];
    },
    iconOptions() {
      return this.icons.map(ic => ({ value: ic, label: ic }));
    },
    columnTypeOptions() {
      return (this.columnTypes || []).map(t => ({ value: t, label: t }));
    },
    styleKeys() {
      return Object.keys(this.st);
    },
  },

  methods: {
    toEdit(s) {
      const cols = (s.columns || []).map(c => ({ ...c, _k: 'c' + ++seq, optionsText: (c.options || []).join(', ') }));
      const lines = (s.default_rows || []).map(r => (r._group !== undefined ? '## ' + r._group : (s.columns || []).map(c => String(r[c.key] || '').replace(/\|/g, '/')).join(' | ')));
      return { ...s, _k: 's' + ++seq, columns: cols, rowsText: lines.join('\n') };
    },
    toSave(sec) {
      const columns = sec.columns.map(c => {
        const col = { key: c.key || slug(c.label) || undefined, label: c.label, type: c.type, width: Number(c.width) || 0 };
        if (c.type === 'select') col.options = String(c.optionsText || '').split(',').map(x => x.trim()).filter(Boolean);
        return col;
      });
      const keys = columns.map(c => c.key);
      const default_rows = sec.type === 'table'
        ? String(sec.rowsText || '').split('\n').map(l => l.trim()).filter(Boolean).map(line => {
            if (line.startsWith('##')) return { _group: line.replace(/^#+\s*/, '') };
            const cells = line.split('|').map(x => x.trim());
            const row = {};
            keys.forEach((k, i) => (row[k] = cells[i] || ''));
            return row;
          })
        : [];
      return { key: sec.key || slug(sec.title) || undefined, title: sec.title, type: sec.type, icon: sec.icon, color: sec.color, enabled: !!sec.enabled, grouped: !!sec.grouped, hint: sec.hint, columns, default_rows };
    },
    rowsHint(sec) {
      return sec.columns.map(c => c.label).join(' | ');
    },
    toggleDay(v) {
      this.g.weekdays = this.g.weekdays.includes(v) ? this.g.weekdays.filter(d => d !== v) : [...this.g.weekdays, v];
    },
    move(i, d) {
      const j = i + d;
      if (j < 0 || j >= this.sections.length) return;
      const [x] = this.sections.splice(i, 1);
      this.sections.splice(j, 0, x);
    },
    moveCol(sec, i, d) {
      const j = i + d;
      const [x] = sec.columns.splice(i, 1);
      sec.columns.splice(j, 0, x);
    },
    addColumn(sec) {
      sec.columns.push({ key: '', label: '', type: 'text', width: 0, _k: 'c' + ++seq, optionsText: '' });
    },
    addSection() {
      this.sections.push(this.toEdit({ key: '', title: '', type: 'table', icon: 'doc-text', color: this.colors[this.sections.length % this.colors.length], enabled: true, grouped: false, hint: '', columns: [{ key: '', label: 'Description', type: 'text', width: 0 }, { key: '', label: 'Remarks', type: 'textarea', width: 0 }], default_rows: [] }));
    },
    async removeSection(i) {
      if (!(await this.$confirm(this.$t('Remove this section from the layout? Meetings already scheduled keep it.'), { variant: 'danger' }))) return;
      this.sections.splice(i, 1);
    },
    toggleWho(ev, key) {
      const w = this.rl[ev].who;
      this.rl[ev].who = w.includes(key) ? w.filter(x => x !== key) : [...w, key];
    },
    saveNotifications() {
      this.saving = true;
      this.$inertia.put(this.route('site-meetings.settings.notifications'), { rules: this.rl }, { preserveScroll: true, onFinish: () => (this.saving = false) });
    },
    saveGeneral() {
      this.saving = true;
      this.errors = {};
      const g = this.g;
      const payload = {
        ...g, week_start: Number(g.week_start), weekdays: g.weekdays,
        reminders_enabled: !!g.reminders_enabled,
        styles: this.st,
      };
      this.$inertia.put(this.route('site-meetings.settings.update'), payload, {
        preserveScroll: true,
        onError: e => (this.errors = e),
        onFinish: () => (this.saving = false),
      });
    },
    saveLayout() {
      if (this.sections.some(s => !String(s.title || '').trim())) {
        this.$alert(this.$t('Every section needs a title.'));
        return;
      }
      this.saving = true;
      this.$inertia.put(this.route('site-meetings.settings.template'), { sections: this.sections.map(s => this.toSave(s)) }, {
        preserveScroll: true,
        onFinish: () => (this.saving = false),
      });
    },
    async resetLayout() {
      if (!(await this.$confirm(this.$t('Go back to the WIMS default layout? Your own sections and columns are removed. Meetings already scheduled keep theirs.'), { variant: 'danger' }))) return;
      this.saving = true;
      this.$inertia.delete(this.route('site-meetings.settings.template-reset'), {
        preserveScroll: true,
        onSuccess: () => {
          this.sections = this.$page.props.template.map(s => this.toEdit(s));
        },
        onFinish: () => (this.saving = false),
      });
    },
  },
};
</script>

<style scoped>
.sm-chan { display: flex; flex-wrap: wrap; gap: 8px; }
.sm-chan-btn { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 14px; font-size: 0.84rem; font-weight: 800; color: var(--lg-mute); background: var(--lg-soft); border: 1px solid var(--lg-line); cursor: pointer; transition: transform 0.15s, box-shadow 0.15s; }
.sm-chan-btn i { font-style: normal; font-size: 0.64rem; letter-spacing: 0.06em; text-transform: uppercase; opacity: 0.8; }
.sm-chan-btn:hover { transform: translateY(-1px); }
.sm-chan-btn.is-on { color: #fff; background: linear-gradient(145deg, #34d399, #047857); border-color: transparent; box-shadow: 0 10px 18px -10px rgba(4, 120, 87, 0.9); }
.sm-chan-btn.is-off:not(.is-on) { opacity: 0.55; }
.sm-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.sm-chips .sm-chip button { margin-left: 4px; font-weight: 800; line-height: 1; cursor: pointer; opacity: 0.7; }
.sm-chips .sm-chip button:hover { opacity: 1; }
.sm-hint { font-size: 0.72rem; color: var(--lg-faint); }
.sm-err { font-size: 0.74rem; font-weight: 600; color: #dc2626; }
</style>

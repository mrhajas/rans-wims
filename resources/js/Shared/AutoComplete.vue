<template>
  <div class="col-span-6 sm:col-span-4">
    <tec-label :for="id" :value="label" v-if="label" />
    <div class="relative mb-2" :class="{ 'mt-1': label }">
      <label :for="id" name="chevron-down" class="cursor-pointer absolute top-0 right-0 border border-transparent p-2 text-gray-500">
        <icons name="chevron-down" class-name="w-5 h-6" />
      </label>
      <button
        type="button"
        @click="reset()"
        v-if="clearable && modelValue"
        class="cursor-pointer focus:outline-none absolute top-0 right-8 border border-transparent p-2 text-gray-500 hover:text-red-500"
      >
        <icons name="cross" class-name="w-4 h-6" />
      </button>
      <input
        :id="id"
        ref="input"
        @blur="hide"
        @focus="show"
        @click="show"
        @keyup.up="up"
        v-model="search"
        autocomplete="off"
        @keyup.down="down"
        @keyup.enter="enter"
        :readonly="!searchable"
        :placeholder="placeholder || label"
        :title="currentLabel || ''"
        :class="{ 'border-red-500': error, 'pr-8': !clearable, 'pr-16': clearable }"
        class="
          w-full
          block
          text-base
          py-2
          pl-4
          placeholder-gray-400
          border border-gray-300
          focus:outline-none
          focus:border-indigo-300
          focus:ring focus:ring-indigo-200 focus:ring-opacity-50
          rounded-md
          shadow-sm
        "
      />
      <!-- Teleported to <body> (unless absoluteDropdown is explicitly opted
           out) and positioned via getBoundingClientRect, exactly like the
           option tooltip below — a plain absolutely-positioned sibling gets
           silently clipped by any ancestor with overflow:hidden, which is
           exactly what the Jetstream Dropdown panel (Filter button, etc.)
           wraps its slot content in. Teleporting escapes that entirely. -->
      <Teleport to="body" :disabled="!absoluteDropdown">
        <div
          v-if="open"
          :class="absoluteDropdown ? 'rounded-md' : [position, 'mt-2 w-full rounded-md z-[9999]']"
          :style="absoluteDropdown ? dropdownStyle : {}"
        >
          <ul class="max-h-56 rounded-md bg-white shadow-lg text-base overflow-auto focus:outline-none sm:text-sm">
            <template v-if="result && result.length">
              <template :key="ri" v-for="(r, ri) in result">
                <!-- v-if="disable && !disabledOptions.includes(cValue(r))" -->
                <template v-if="!(disable && disable == cValue())">
                  <!-- a full project / warehouse list is shown in sections: Main Warehouses, Projects, Others (the same as the top bar) -->
                  <li v-if="grouped && (ri === 0 || groupKey(r) !== groupKey(result[ri - 1]))" class="ac-group-head" :class="'ac-group-' + groupInfo(r).tone" role="presentation" @mousedown.prevent>
                    <span class="ac-group-ico"><icons :name="groupInfo(r).icon" class-name="w-3 h-3" /></span>
                    <span class="ac-group-name">{{ groupInfo(r).label }}</span>
                    <span class="ac-group-count">{{ groupCount(r) }}</span>
                  </li>
                  <li>
                    <button
                      type="button"
                      @click="suggestionClick(ri)"
                      :class="[haltOf(r) ? 'ac-halted' : isActive(ri) ? 'bg-blue-600 text-white' : 'bg-white text-gray-900', haltOf(r) ? '' : 'hover:bg-blue-600 hover:text-white']"
                      :aria-disabled="haltOf(r) ? 'true' : null"
                      class="w-full text-left flex items-center select-none relative py-2 pl-3 pr-9"
                    >
                      <icons v-if="haltOf(r)" name="lock-closed" class-name="w-3.5 h-3.5 flex-none ac-halted-ico" />
                      <span v-if="statusDotClass(r)" class="ac-status-dot flex-none" :class="statusDotClass(r)"></span>
                      <span
                        class="ml-3 block font-normal truncate"
                        @mouseenter="showOptionTooltip($event, optionTipText(r))"
                        @mouseleave="hideOptionTooltip"
                      >
                        {{ cLabel(r) }}
                      </span>
                      <span v-if="statusDotClass(r) === 'ac-dot-green'" class="ml-2 text-[10px] font-semibold uppercase tracking-wide opacity-80">
                        {{ $t('Completed') }}
                      </span>
                      <span v-if="haltOf(r)" class="ac-halted-tag">{{ $t('Stock movement halted') }} · {{ haltOf(r).by }}</span>
                    </button>
                  </li>
                </template>
              </template>
            </template>
            <li v-else class="bg-blue-600 text-white cursor-default select-none relative py-2 pl-3 pr-9">
              <div class="flex items-center">
                <span class="ml-3 block font-normal truncate">
                  <span v-if="loading">{{ $t('Searching for results') }}...</span>
                  <span v-else-if="freeform && search">{{ $t('Press Enter to add') }} "{{ search }}"</span>
                  <span v-else-if="result == null">{{ $t('Scan barcode or search items for next') }}</span>
                  <span v-else-if="json">{{ defaultText || $t('Please type to search') }}</span>
                  <span v-else>{{ $t('No result suggestions to list.') }}</span>
                </span>
              </div>
            </li>
          </ul>
        </div>
      </Teleport>
      <tec-input-error v-if="error" :message="error"  class="mt-1" />
      <!-- a halted warehouse already chosen (for example the one the person is working in): say so at once and name who halted it -->
      <p v-if="selectedHalt" class="ac-halt-note" role="alert"><icons name="lock-closed" class-name="w-3.5 h-3.5 flex-none" /><span>{{ selectedHalt.message }}</span></p>
    </div>

    <!-- Teleported to <body> and positioned via getBoundingClientRect (not a
         plain absolutely-positioned sibling) so it can never be clipped by
         the dropdown list's own overflow-auto scrolling, or trapped behind
         a later sibling section by an ancestor panel's backdrop-filter
         stacking context — same technique already used by
         Shared/Sidebar/IconSubMenu.vue for the identical clipping problem. -->
    <Teleport to="body">
      <div
        v-if="optionTooltip.visible"
        class="ac-option-tooltip"
        :style="{
          top: optionTooltip.top + 'px',
          left: optionTooltip.left !== null ? optionTooltip.left + 'px' : 'auto',
          right: optionTooltip.right !== null ? optionTooltip.right + 'px' : 'auto',
        }"
      >
        {{ optionTooltip.text }}
      </div>
    </Teleport>
  </div>
</template>

<script>
import { v4 as uuidv4 } from 'uuid';
import throttle from 'lodash/throttle';
import TecLabel from '@/Jetstream/Label.vue';
import TecInputError from '@/Jetstream/InputError.vue';
import { isFullWarehouseList, orderWarehouses, groupKeyOf, groupMeta, progressOf } from '@/Core/warehouseGroups';

export default {
  emits: ['update:modelValue'],

  components: { TecLabel, TecInputError },

  data() {
    return {
      search: '',
      result: [],
      open: false,
      current: null,
      loading: false,
      selected: null,
      optionTooltip: { visible: false, text: '', top: 0, left: null, right: null },
      dropdownStyle: {},
    };
  },
  props: {
    id: {
      type: String,
      default() {
        return uuidv4();
      },
    },
    json: Boolean,
    label: String,
    error: String,
    position: String,
    valueKey: String,
    labelKey: String,
    defaultText: String,
    placeholder: String,
    disable: [String, Number],
    modelValue: [String, Number],
    suggestions: [String, Array],
    clearable: { type: Boolean, default: false },
    searchable: { type: Boolean, default: true },
    freeform: { type: Boolean, default: false },
    keepFocus: { type: Boolean, default: false },
    // a remote (URL) list shows its first choices as soon as the field is clicked, before anything is typed
    loadOnFocus: { type: Boolean, default: false },
    resetSearch: { type: Boolean, default: false },
    absoluteDropdown: { type: Boolean, default: true },
    // set to false to keep a project / warehouse list flat even when it holds every category
    group: { type: Boolean, default: true },
    // set on the warehouse pickers of the forms that move stock: a warehouse whose stock movement is halted (Adjustment Session) is shown locked and cannot be chosen
    stockMove: { type: Boolean, default: false },
  },

  watch: {
    // an option's tooltip belongs to an open list: the moment the list closes (a pick, a click away, Escape) or its options are re-filtered,
    // the hovered option is gone and its mouseleave never fires, so the bubble would stay on screen
    open: function (o) {
      if (!o) this.hideOptionTooltip();
    },
    result: function () {
      this.hideOptionTooltip();
    },
    modelValue: function (v) {
      if (!v) {
        this.search = '';
        this.$nextTick(() => (this.open = false));
        return;
      }
      // A truthy modelValue that doesn't match what's currently displayed
      // means the parent set it programmatically (not via this component's
      // own suggestionClick(), which already keeps `search`/`selected` in
      // sync itself) — e.g. a form defaulting one field from another after
      // mount. Without this, the underlying value is correct but the
      // visible label silently stays blank/stale.
      if (!this.selected || this.cValue(this.selected) != v) {
        this.resolveFromModelValue();
      }
    },
    search: function (search) {
      if (this.searchable) {
        this.open = true;
        if (Array.isArray(this.suggestions)) {
          this.result = this.ordered(search ? this.suggestions.filter(r => this.cLabel(r, search)) : this.suggestions);
        } else {
          this.loading = true;
          this.getSuggestions(search);
        }
      }
    },
    suggestions: {
      deep: true,
      handler() {
        if (Array.isArray(this.suggestions)) {
          this.result = this.ordered(this.search ? this.suggestions.filter(r => this.cLabel(r, this.search)) : this.suggestions);
        }
      },
    },
  },

  computed: {
    // sections apply only to a full project / warehouse list (Main Warehouses and Projects together) and can be switched off in Settings
    // a list whose options carry their own `section` (for example Staff and Contacts) is shown in those sections, in the order they first appear
    sectioned() {
      const l = this.suggestions;
      if (!this.group || !Array.isArray(l) || l.length < 2) return false;
      if (!l.every(o => o !== null && typeof o === 'object' && typeof o.section === 'string' && o.section !== '')) return false;

      return new Set(l.map(o => o.section)).size > 1;
    },
    grouped() {
      if (this.sectioned) return true;
      if (!this.group || !Array.isArray(this.suggestions)) return false;
      const page = this.$page && this.$page.props;
      if (page && page.groupedWarehouseLists === false) return false;

      return isFullWarehouseList(this.suggestions);
    },
    // id => who halted it, when, which session (shared by the server on every page)
    haltMap() {
      const p = this.$page && this.$page.props;
      return (this.stockMove && p && p.stockHalts) || {};
    },
    // the halt of the warehouse that is chosen now (also one that came in as the default value)
    selectedHalt() {
      if (!this.stockMove || !Object.keys(this.haltMap).length || this.modelValue === '' || this.modelValue === null || this.modelValue === undefined) return null;
      return this.haltMap[this.modelValue] || null;
    },
    currentLabel() {
      return this.cLabel(this.selected);
    },
    currentValue() {
      return this.cValue(this.selected);
    },
    // disabledOptions() {
    //   if (this.disable) {
    //     if (Array.isArray(this.disable)) {
    //       return this.disable;
    //     }
    //     return [this.disable];
    //   }
    //   return undefined;
    // },
  },

  created() {
    if (Array.isArray(this.suggestions)) {
      this.result = this.ordered(this.suggestions);
    }
  },

  mounted() {
    window.addEventListener('resize', this.onWindowReposition);
    window.addEventListener('scroll', this.onWindowReposition, true);
    this.resolveFromModelValue();
  },

  beforeUnmount() {
    this.hideOptionTooltip();
    window.removeEventListener('resize', this.onWindowReposition);
    window.removeEventListener('scroll', this.onWindowReposition, true);
  },

  methods: {
    // Resolves `selected`/`search` (the visible label) from the current
    // modelValue — run once on mount, and again from the modelValue
    // watcher whenever the parent changes it programmatically afterward.
    resolveFromModelValue() {
      if (!this.modelValue) return;
      if (Array.isArray(this.suggestions)) {
        this.selected = this.suggestions.find(s => {
          if (s !== null && typeof s === 'object') {
            return s[this.valueKey] == this.modelValue || s.value == this.modelValue || s.id == this.modelValue;
          }
          return s == this.modelValue;
        });
        // Freeform values aren't required to exist in the suggestions list
        // (e.g. a bank entry outside the top-200 preload) — show the raw
        // modelValue itself rather than blanking the field when no match is found.
        this.search = this.freeform ? String(this.modelValue) : this.currentLabel;
        this.$nextTick(() => (this.open = false));
      } else {
        this.loading = true;
        axios
          .post(this.suggestions, { id: this.modelValue })
          .then(res => {
            this.result = res.data;
            this.selected = res.data[0];
            this.search = this.cLabel(this.selected);
          })
          .finally(() => {
            this.open = false;
            this.loading = false;
          });
      }
    },
    onWindowReposition() {
      this.hideOptionTooltip();
      if (this.open && this.absoluteDropdown) {
        this.positionDropdown();
      }
    },
    getSuggestions: throttle(function (search) {
      if ((search && !this.result) || (this.result && (!this.result.length || this.modelValue != this.result[0].id))) {
        axios
          .post(this.suggestions, { search })
          .then(res => {
            this.result = res.data;
            if (this.result.length == 1) {
              this.suggestionClick(0, true);
            }
          })
          .finally(() => (this.loading = false));
      }
    }, 250),
    cLabel(r, search) {
      if (r === null) {
        return null;
      } else if (typeof r !== 'object') {
        return search ? String(r).toLowerCase().includes(String(search).toLowerCase()) : r;
      }
      let key = '';
      if (this.labelKey) {
        key = this.labelKey;
      } else if (r.label !== undefined) {
        key = 'label';
      } else if (r.name !== undefined) {
        key = 'name';
      }
      if (search) {
        return key ? String(r[key] ?? '').toLowerCase().includes(String(search).toLowerCase()) : String(r).toLowerCase().includes(String(search).toLowerCase());
      }
      return key ? r[key] : r;
    },
    cValue(r) {
      if (r === null) {
        return null;
      } else if (typeof r !== 'object') {
        return r;
      }
      let key = '';
      if (this.valueKey) {
        key = this.valueKey;
      } else if (r.value !== undefined) {
        key = 'value';
      } else if (r.id !== undefined) {
        key = 'id';
      }
      return key ? r[key] : r;
    },
    show() {
      this.open = true;
      // Freeform fields keep the current text in place (selected, ready to
      // edit or replace) instead of clearing it — the whole point is that
      // it may already hold a value that isn't in the suggestions list.
      if (!this.freeform) {
        this.search = '';
      }
      this.$refs.input.select();
      if (!this.selected) {
        this.current = 0;
      }
      if (this.loadOnFocus && typeof this.suggestions === 'string' && !this.search && (!this.result || !this.result.length)) {
        this.loading = true;
        axios
          .post(this.suggestions, { search: '' })
          .then(res => (this.result = res.data))
          .finally(() => (this.loading = false));
      }
      if (this.absoluteDropdown) {
        this.$nextTick(this.positionDropdown);
      }
    },
    // Computes the Teleported panel's viewport position from the input's own
    // rect. `position="bottom-100 mb-2"` (a couple of forms deliberately
    // open the list upward when they sit near the bottom of the viewport)
    // is honored by anchoring to the input's top edge instead of its bottom.
    positionDropdown() {
      if (!this.$refs.input) {
        return;
      }
      const rect = this.$refs.input.getBoundingClientRect();
      const gap = 8;
      const openUpward = (this.position || '').includes('bottom');
      this.dropdownStyle = {
        position: 'fixed',
        left: rect.left + 'px',
        width: rect.width + 'px',
        zIndex: 9999,
        ...(openUpward ? { bottom: window.innerHeight - rect.top + gap + 'px' } : { top: rect.bottom + gap + 'px' }),
      };
    },
    hide() {
      // Freeform: take what was typed at once, so a Save clicked straight after typing already sees it (the delayed block
      // below still lets a suggestion click that follows replace it).
      if (this.freeform && this.search) {
        this.$emit('update:modelValue', this.search);
      }
      setTimeout(() => {
        if (this.freeform) {
          // Typed something new (whether or not it matches a suggestion) —
          // that becomes the value, growing the bank on save. Blurred
          // without typing anything — leave the existing value untouched.
          if (this.search) {
            this.$emit('update:modelValue', this.search);
          } else {
            this.search = this.modelValue || '';
          }
        } else if (this.selected) {
          this.search = this.currentLabel;
        }
        setTimeout(() => (this.open = false), 100);
      }, 200);
    },
    enter() {
      if (this.freeform && (!this.result || !this.result.length)) {
        this.$emit('update:modelValue', this.search);
        this.open = false;
        return false;
      }
      this.suggestionClick(this.current);
      this.hide();
      return false;
    },
    up() {
      if (this.current > 0) this.current--;
    },
    down() {
      if (this.current < this.result.length - 1) this.current++;
    },
    isActive(index) {
      return index == this.current;
    },
    // the halt on this option; only a picker marked stock-move (a warehouse picker of a form that moves stock) looks at it
    haltOf(r) {
      if (!this.stockMove || r === null || typeof r !== 'object') return null;
      return this.haltMap[this.cValue(r)] || null;
    },
    suggestionClick(index, reset = false) {
      const halt = index !== undefined ? this.haltOf(this.result && this.result[index]) : null;
      if (halt) {
        // refused: the warehouse stays unselected and the person is told who stopped it
        this.open = false;
        this.$alert(halt.message);
        return;
      }
      if (index !== undefined) {
        this.current = index;
        this.selected = this.result[index];
        this.$emit('update:modelValue', this.json ? this.selected : this.currentValue);
        this.search = this.resetSearch ? '' : this.currentLabel;
        this.open = false;
      }
      if (reset) {
        this.result = null;
      }
      if (this.resetSearch) {
        this.selected = null;
      }
      if (this.keepFocus) {
        this.$refs.input.focus();
      }
    },
    reset() {
      this.search = '';
      this.$emit('update:modelValue', '');
      this.$nextTick(() => (this.open = false));
    },
    showOptionTooltip(event, text) {
      const rect = event.currentTarget.getBoundingClientRect();
      // Flip to the left of the dropdown when there isn't roughly enough
      // room on the right (a rough guard, not an exact measurement of the
      // bubble's real width, but enough to keep it from running off-screen
      // for dropdowns sitting near the right edge, e.g. Category next to
      // Item in a filter grid).
      const flip = window.innerWidth - rect.right < 260;
      this.optionTooltip = {
        visible: true,
        text,
        top: rect.top + rect.height / 2,
        left: flip ? null : rect.right + 8,
        right: flip ? window.innerWidth - rect.left + 8 : null,
      };
    },
    hideOptionTooltip() {
      if (this.optionTooltip && this.optionTooltip.visible) this.optionTooltip.visible = false;
    },
    // Generic opt-in indicator: any suggestion object carrying a
    // `completed_at` field (warehouses/projects only, today) renders a
    // green "Live" dot or red "Completed" dot. Suggestions without that
    // field (items, contacts, categories, etc.) render nothing — this
    // stays a no-op for every other AutoComplete usage in the app.
    statusDotClass(r) {
      const p = progressOf(r);
      return p === null ? null : p === 'done' ? 'ac-dot-green' : 'ac-dot-orange';
    },
    // section order for a full project / warehouse list, otherwise the list exactly as it came
    ordered(list) {
      if (this.sectioned && Array.isArray(list)) {
        const order = [...new Set(this.suggestions.map(o => o.section))];
        return list.map((o, i) => [o, i]).sort((a, b) => order.indexOf(a[0].section) - order.indexOf(b[0].section) || a[1] - b[1]).map(x => x[0]);
      }
      return this.grouped && Array.isArray(list) ? orderWarehouses(list) : list;
    },
    groupKey(r) {
      return this.sectioned ? r.section : groupKeyOf(r);
    },
    groupInfo(r) {
      return this.sectioned ? { label: r.section, icon: r.section_icon || 'folder', tone: r.section_tone || 'blue' } : groupMeta(groupKeyOf(r));
    },
    groupCount(r) {
      const k = this.groupKey(r);
      return this.result.filter(x => this.groupKey(x) === k).length;
    },
    // the hover text of an option: its full name and, in a sectioned list, its category (the same tooltip idea as the top bar)
    optionTipText(r) {
      const l = this.cLabel(r);
      const h = this.haltOf(r);
      if (h) return l + '\n' + h.message;
      if (this.sectioned) return r && r.section ? l + '\n' + r.section : l;
      if (!(this.grouped && r && typeof r === 'object' && r.status)) return l;
      const p = progressOf(r);
      return l + '\nCategory: ' + r.status + (p ? ' - ' + (p === 'done' ? 'Finished' : 'In progress') : '');
    },
  },
};
</script>

<style scoped>
.ac-status-dot {
  display: inline-block;
  height: 0.5rem;
  width: 0.5rem;
  border-radius: 9999px;
}
.ac-dot-green {
  background: #10b981;
  box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.25);
}
/* a warehouse whose stock movement is halted: shown locked and greyed, it cannot be chosen */
.ac-halted { background: #fff7f7; color: #7f1d1d; cursor: not-allowed; opacity: 0.92; }
.ac-halted-ico { color: #b91c1c; margin-right: 2px; }
.ac-halted .block { text-decoration: line-through; text-decoration-color: rgba(185, 28, 28, 0.45); }
.ac-halted { flex-wrap: wrap; }
.ac-halted-tag { flex: 0 0 100%; margin: 1px 0 0 2.35rem; color: #b91c1c; font-size: 10.5px; font-weight: 800; letter-spacing: 0.01em; line-height: 1.3; }
html.dark .ac-halted-tag { color: #fca5a5; }
.ac-halt-note { display: flex; gap: 6px; align-items: flex-start; margin: 4px 0 0; padding: 6px 10px; border-radius: 10px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; font-size: 0.78rem; font-weight: 600; line-height: 1.35; }
.ac-halt-note svg { margin-top: 2px; }
html.dark .ac-halted { background: rgba(185, 28, 28, 0.14); color: #fecaca; }
html.dark .ac-halt-note { background: rgba(185, 28, 28, 0.16); border-color: rgba(248, 113, 113, 0.4); color: #fecaca; }
/* a project's progress: green = finished, orange = still running */
.ac-dot-orange {
  background: #f97316;
  box-shadow: 0 0 0 2px rgba(249, 115, 22, 0.25);
}
/* the section headers of a project / warehouse list */
.ac-group-head { --gc: #2563eb; display: flex; align-items: center; gap: 8px; padding: 8px 12px 4px; font-size: 0.62rem; font-weight: 800; letter-spacing: 0.09em; text-transform: uppercase; color: var(--gc); background: #fff; cursor: default; }
.ac-group-head:not(:first-child) { border-top: 1px solid #eef2f7; margin-top: 2px; }
.ac-group-emerald { --gc: #059669; } .ac-group-slate { --gc: #64748b; } .ac-group-amber { --gc: #d97706; } .ac-group-teal { --gc: #0d9488; }
.ac-group-ico { display: inline-grid; place-items: center; width: 20px; height: 20px; border-radius: 7px; background: #fff; color: var(--gc); box-shadow: inset 0 0 0 1.5px var(--gc), 0 4px 7px -4px var(--gc); }
.ac-group-name { flex: 1; min-width: 0; }
.ac-group-count { min-width: 20px; padding: 0 6px; border-radius: 999px; background: color-mix(in srgb, var(--gc) 12%, transparent); color: var(--gc); text-align: center; line-height: 1.6; }

.ac-option-tooltip {
  position: fixed;
  transform: translateY(-50%);
  z-index: 9999;
  padding: 0.4rem 0.7rem;
  border-radius: 0.5rem;
  max-width: 320px;
  white-space: pre-line;
  font-size: 0.75rem;
  font-weight: 600;
  line-height: 1.3;
  color: #f8fafc;
  background: linear-gradient(145deg, rgba(15, 23, 42, 0.96), rgba(30, 41, 59, 0.92));
  box-shadow: 0 10px 20px rgba(15, 23, 42, 0.25);
  pointer-events: none;
}
</style>

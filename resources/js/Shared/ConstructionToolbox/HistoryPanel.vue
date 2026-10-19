<template>
  <div>
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-sm font-semibold text-slate-700">History</h3>
      <button v-if="entries.length" type="button" @click="clear" class="text-xs text-red-500 hover:text-red-600 font-medium">
        Clear
      </button>
    </div>

    <p v-if="entries.length === 0" class="text-sm text-slate-400">No calculations yet.</p>

    <ul v-else class="space-y-2">
      <li v-for="(entry, i) in entries" :key="i" class="rounded-lg border border-white/70 bg-white/50 px-3 py-2">
        <button type="button" @click="$emit('select-tool', entry.toolId)" class="text-left w-full">
          <div class="flex items-center justify-between">
            <span class="text-sm font-medium text-slate-700 hover:text-indigo-600">{{ entry.title }}</span>
            <span class="text-xs text-slate-400">{{ formatTime(entry.timestamp) }}</span>
          </div>
          <div v-if="entry.lines" class="mt-1 text-xs text-slate-500 space-y-0.5">
            <div v-for="(line, j) in entry.lines.slice(0, 3)" :key="j">{{ line[0] }}: {{ line[1] }}</div>
          </div>
        </button>
      </li>
    </ul>
  </div>
</template>

<script>
import { getHistory, clearHistory } from './storage';

export default {
  data() {
    return { entries: getHistory() };
  },

  methods: {
    formatTime(ts) {
      return new Date(ts).toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' });
    },
    clear() {
      clearHistory();
      this.entries = [];
    },
  },
};
</script>

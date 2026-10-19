<template>
  <div>
    <h3 class="text-sm font-semibold text-slate-700 mb-3">Favorites</h3>

    <p v-if="entries.length === 0" class="text-sm text-slate-400">
      No favorites yet: star any calculator to pin it here.
    </p>

    <ul v-else class="space-y-2">
      <li
        v-for="(tool, i) in entries"
        :key="tool.id"
        class="flex items-center justify-between rounded-lg border border-white/70 bg-white/50 px-3 py-2"
      >
        <button type="button" @click="$emit('select-tool', tool.id)" class="text-left flex-1 text-sm text-slate-700 hover:text-indigo-600">
          {{ tool.label }}
          <span class="text-xs text-slate-400 block">{{ categoryLabel(tool.category) }}</span>
        </button>
        <div class="flex items-center gap-1 ml-2">
          <button type="button" @click="move(tool.id, -1)" :disabled="i === 0" class="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30">
            <icons name="up" className="w-3.5 h-3.5" />
          </button>
          <button type="button" @click="move(tool.id, 1)" :disabled="i === entries.length - 1" class="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30">
            <icons name="down" className="w-3.5 h-3.5" />
          </button>
          <button type="button" @click="remove(tool.id)" class="p-1 text-red-400 hover:text-red-600">
            <icons name="cross" className="w-3.5 h-3.5" />
          </button>
        </div>
      </li>
    </ul>
  </div>
</template>

<script>
import { getFavorites, toggleFavorite, moveFavorite } from './storage';
import { CATEGORIES, findTool } from './registry';

export default {
  data() {
    return { favoriteIds: getFavorites() };
  },

  computed: {
    entries() {
      return this.favoriteIds.map(id => findTool(id)).filter(Boolean);
    },
  },

  methods: {
    categoryLabel(id) {
      return CATEGORIES.find(c => c.id === id)?.label || id;
    },
    remove(id) {
      this.favoriteIds = toggleFavorite(id);
      this.$emit('favorites-changed');
    },
    move(id, direction) {
      this.favoriteIds = moveFavorite(id, direction);
    },
  },
};
</script>

<template>
  <div
    v-if="show"
    class="ctb-shell fixed z-[70] flex flex-col rounded-2xl print:hidden"
    :style="windowStyle"
  >
    <!-- Title bar -->
    <div
      class="ctb-titlebar flex items-center justify-between px-4 py-3 text-white select-none shrink-0 cursor-move"
      @pointerdown="onDragStart($event)"
    >
      <div class="flex items-center gap-2.5 text-sm font-semibold">
        <span class="ctb-titlebar-icon"><icons name="calculator" className="w-4 h-4" /></span>
        <span>Construction Toolbox</span>
      </div>
      <button type="button" @click="close" class="ctb-close-btn" aria-label="Close">
        <icons name="cross" className="w-4 h-4" />
      </button>
    </div>

    <!-- Search -->
    <div class="p-3 pb-2 shrink-0">
      <div class="relative">
        <icons name="search" className="ctb-search-icon" />
        <input
          v-model="search"
          type="text"
          placeholder="Search tools…"
          class="ctb-search-input w-full text-sm rounded-lg pl-8"
        />
      </div>
    </div>

    <!-- Category tabs -->
    <div v-if="!search.trim()" class="flex gap-1.5 overflow-x-auto px-3 pb-2.5 shrink-0">
      <button
        v-for="cat in categories"
        :key="cat.id"
        type="button"
        @click="selectCategory(cat.id)"
        :data-tip="cat.label"
        class="ctb-tab flex flex-col items-center justify-center gap-0.5 px-3 py-1.5 text-[10px] whitespace-nowrap rounded-lg transition-all lg-tip"
        :class="{ 'ctb-tab-active': activeCategory === cat.id }"
       :aria-label="cat.label">
        <icons :name="cat.icon" className="w-4 h-4" />
        <span>{{ cat.label }}</span>
      </button>
    </div>

    <!-- Content -->
    <div class="ctb-content flex-1 overflow-y-auto p-3 pt-2 min-h-0">
      <template v-if="search.trim()">
        <p class="text-xs text-slate-400 mb-2">{{ searchResults.length }} result(s)</p>
        <button
          v-for="tool in searchResults"
          :key="tool.id"
          type="button"
          @click="openTool(tool.id)"
          class="ctb-row flex items-center justify-between w-full text-left"
        >
          <span>{{ tool.label }}</span>
          <span class="text-xs text-slate-400">{{ categoryLabel(tool.category) }}</span>
        </button>
      </template>

      <template v-else-if="activeCategory === 'favorites'">
        <favorites-panel @select-tool="openTool" />
      </template>

      <template v-else-if="activeCategory === 'history'">
        <history-panel @select-tool="openTool" />
      </template>

      <template v-else-if="activeTool">
        <button type="button" @click="activeToolId = null" class="ctb-back-btn inline-flex items-center gap-1 text-xs font-semibold mb-3">
          <icons name="chevron-down" className="w-3 h-3 transform rotate-90" />
          Back
        </button>
        <component :is="componentFor(activeTool)" :config="activeTool.config" :key="activeTool.id" @result="onResult" />
      </template>

      <template v-else>
        <button
          v-for="tool in toolsInCategory"
          :key="tool.id"
          type="button"
          @click="openTool(tool.id)"
          class="ctb-row flex items-center justify-between w-full text-left"
        >
          <span>{{ tool.label }}</span>
          <icons v-if="isFavorite(tool.id)" name="heart" className="w-3.5 h-3.5 text-rose-500" />
        </button>
      </template>
    </div>

    <!-- Action bar -->
    <div v-if="activeTool && currentResult" class="ctb-actionbar flex items-center gap-2 px-3 py-2.5 shrink-0 flex-wrap">
      <button type="button" @click="copyResult" class="toolbox-action-btn">
        <icons name="doc" className="w-3.5 h-3.5" /> Copy
      </button>
      <button type="button" @click="printResult" class="toolbox-action-btn">
        <icons name="printer" className="w-3.5 h-3.5" /> Print
      </button>
      <button type="button" @click="exportPdf" class="toolbox-action-btn">
        <icons name="download" className="w-3.5 h-3.5" /> PDF
      </button>
      <button type="button" @click="toggleFavorite(activeTool.id)" class="toolbox-action-btn ml-auto" :class="{ 'toolbox-action-btn-active': isFavorite(activeTool.id) }">
        <icons name="heart" className="w-3.5 h-3.5" :class="isFavorite(activeTool.id) ? 'text-rose-500' : ''" />
        {{ isFavorite(activeTool.id) ? 'Favorited' : 'Favorite' }}
      </button>
    </div>

    <!-- Resize handle -->
    <div
      class="ctb-resize-handle absolute bottom-0 right-0 w-4 h-4 cursor-nwse-resize"
      @pointerdown="onResizeStart"
    >
      <icons name="chevron-down" className="w-3 h-3 transform -rotate-45 pointer-events-none" />
    </div>
  </div>
</template>

<script>
import { CATEGORIES, findTool, toolsForCategory, searchTools } from './registry';
import { getFavorites, toggleFavorite, isFavorite, pushHistory, updateLatestHistoryEntry, getSessionState, setSessionState } from './storage';
import GenericCalculator from './GenericCalculator.vue';
import UnitConverter from './UnitConverter.vue';
import StandardCalculator from './StandardCalculator.vue';
import ScientificCalculator from './ScientificCalculator.vue';
import PercentageCalculator from './PercentageCalculator.vue';
import QuickReference from './QuickReference.vue';
import FavoritesPanel from './FavoritesPanel.vue';
import HistoryPanel from './HistoryPanel.vue';

const COMPONENTS = { GenericCalculator, UnitConverter, StandardCalculator, ScientificCalculator, PercentageCalculator, QuickReference };
const MIN_WIDTH = 340;
const MIN_HEIGHT = 420;
const DEFAULT_WIDTH = 400;
const DEFAULT_HEIGHT = 580;

export default {
  components: { FavoritesPanel, HistoryPanel },

  props: {
    show: { type: Boolean, default: false },
  },

  emits: ['close'],

  data() {
    const session = getSessionState();
    return {
      categories: CATEGORIES,
      search: '',
      activeCategory: session.activeCategory || 'basic',
      activeToolId: session.activeToolId || null,
      currentResult: null,
      geometry: session.geometry || this.defaultGeometry(),
      favoriteIds: getFavorites(),
      drag: null,
      resize: null,
    };
  },

  computed: {
    activeTool() {
      return this.activeToolId ? findTool(this.activeToolId) : null;
    },
    toolsInCategory() {
      return toolsForCategory(this.activeCategory);
    },
    searchResults() {
      return searchTools(this.search);
    },
    windowStyle() {
      return {
        left: `${this.geometry.left}px`,
        top: `${this.geometry.top}px`,
        width: `${this.geometry.width}px`,
        height: `${this.geometry.height}px`,
      };
    },
  },

  mounted() {
    window.addEventListener('keydown', this.onKeydown);
    window.addEventListener('resize', this.onWindowResize);
  },
  beforeUnmount() {
    window.removeEventListener('keydown', this.onKeydown);
    window.removeEventListener('resize', this.onWindowResize);
  },

  methods: {
    defaultGeometry() {
      const width = DEFAULT_WIDTH;
      const height = DEFAULT_HEIGHT;
      return {
        width,
        height,
        left: Math.max((window.innerWidth - width) / 2, 8),
        top: Math.max((window.innerHeight - height) / 2, 8),
      };
    },

    onWindowResize() {
      // Desktop/laptop only — auto-close if the window is narrowed into tablet/mobile territory.
      if (window.innerWidth < 1024) {
        this.close();
      }
    },

    close() {
      this.$emit('close');
    },

    categoryLabel(id) {
      return this.categories.find(c => c.id === id)?.label || id;
    },

    componentFor(tool) {
      return COMPONENTS[tool.component];
    },

    selectCategory(id) {
      this.activeCategory = id;
      this.activeToolId = null;
      this.currentResult = null;
      this.search = '';
      setSessionState({ activeCategory: id, activeToolId: null });
    },

    openTool(id) {
      const tool = findTool(id);
      if (!tool) return;
      this.activeToolId = id;
      this.activeCategory = tool.category;
      this.currentResult = null;
      this.search = '';
      pushHistory({ toolId: id, title: tool.label });
      setSessionState({ activeCategory: tool.category, activeToolId: id });
    },

    onResult(payload) {
      this.currentResult = payload;
      updateLatestHistoryEntry(payload);
    },

    isFavorite(id) {
      return this.favoriteIds.includes(id);
    },

    toggleFavorite(id) {
      this.favoriteIds = toggleFavorite(id);
    },

    resultAsText() {
      if (!this.currentResult) return '';
      if (this.currentResult.lines) {
        return this.currentResult.lines.map(([label, value]) => `${label}: ${value}`).join('\n');
      }
      if (this.currentResult.rows) {
        const columns = this.currentResult.columns || [];
        return [columns.join('\t'), ...this.currentResult.rows.map(r => r.join('\t'))].join('\n');
      }
      return '';
    },

    async copyResult() {
      try {
        await navigator.clipboard.writeText(this.resultAsText());
      } catch (e) {
        // clipboard API unavailable/denied — no further action needed
      }
    },

    exportPayload() {
      if (!this.currentResult) return null;
      const columns = this.currentResult.columns || ['Field', 'Value'];
      const rows = this.currentResult.rows || this.currentResult.lines || [];
      return {
        title: this.currentResult.title,
        category: this.categoryLabel(this.activeTool.category),
        columns,
        rows,
      };
    },

    async printResult() {
      const payload = this.exportPayload();
      if (!payload) return;
      const win = window.open('', '_blank');
      try {
        const response = await window.axios.post(this.route('construction-toolbox.print'), payload, { responseType: 'text' });
        if (win) {
          win.document.write(response.data);
          win.document.close();
        }
      } catch (e) {
        if (win) win.close();
      }
    },

    async exportPdf() {
      const payload = this.exportPayload();
      if (!payload) return;
      try {
        const response = await window.axios.post(this.route('construction-toolbox.pdf'), payload, { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = `construction-toolbox-${Date.now()}.pdf`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
      } catch (e) {
        // export failed — silently ignore, user can retry
      }
    },

    onKeydown(e) {
      if (e.key === 'Escape') {
        this.close();
      }
    },

    onDragStart(e) {
      this.drag = {
        startX: e.clientX,
        startY: e.clientY,
        startLeft: this.geometry.left,
        startTop: this.geometry.top,
      };
      window.addEventListener('pointermove', this.onDragMove);
      window.addEventListener('pointerup', this.onDragEnd);
    },
    onDragMove(e) {
      if (!this.drag) return;
      const left = this.drag.startLeft + (e.clientX - this.drag.startX);
      const top = this.drag.startTop + (e.clientY - this.drag.startY);
      this.geometry.left = Math.min(Math.max(left, -this.geometry.width + 80), window.innerWidth - 80);
      this.geometry.top = Math.min(Math.max(top, 0), window.innerHeight - 40);
    },
    onDragEnd() {
      this.drag = null;
      window.removeEventListener('pointermove', this.onDragMove);
      window.removeEventListener('pointerup', this.onDragEnd);
      setSessionState({ geometry: this.geometry });
    },

    onResizeStart(e) {
      e.stopPropagation();
      this.resize = {
        startX: e.clientX,
        startY: e.clientY,
        startWidth: this.geometry.width,
        startHeight: this.geometry.height,
      };
      window.addEventListener('pointermove', this.onResizeMove);
      window.addEventListener('pointerup', this.onResizeEnd);
    },
    onResizeMove(e) {
      if (!this.resize) return;
      const width = this.resize.startWidth + (e.clientX - this.resize.startX);
      const height = this.resize.startHeight + (e.clientY - this.resize.startY);
      this.geometry.width = Math.min(Math.max(width, MIN_WIDTH), window.innerWidth - this.geometry.left - 8);
      this.geometry.height = Math.min(Math.max(height, MIN_HEIGHT), window.innerHeight - this.geometry.top - 8);
    },
    onResizeEnd() {
      this.resize = null;
      window.removeEventListener('pointermove', this.onResizeMove);
      window.removeEventListener('pointerup', this.onResizeEnd);
      setSessionState({ geometry: this.geometry });
    },
  },
};
</script>

<style scoped>
.ctb-shell {
  background: linear-gradient(165deg, rgba(255, 255, 255, 0.85), rgba(238, 242, 255, 0.75));
  backdrop-filter: blur(24px) saturate(180%);
  -webkit-backdrop-filter: blur(24px) saturate(180%);
  border: 1px solid rgba(255, 255, 255, 0.6);
  box-shadow: 0 25px 60px -12px rgba(15, 23, 42, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.6);
  overflow: hidden;
}

.ctb-titlebar {
  background: linear-gradient(135deg, #4f46e5, #4338ca);
  border-bottom: 1px solid rgba(255, 255, 255, 0.14);
}
.ctb-titlebar-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 24px;
  width: 24px;
  border-radius: 7px;
  background: rgba(255, 255, 255, 0.16);
}
.ctb-close-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  height: 26px;
  width: 26px;
  min-height: 0;
  padding: 0;
  border-radius: 8px;
  background: #fff;
  color: #000;
  transition: background 0.15s ease, color 0.15s ease;
}
.ctb-close-btn:hover {
  background: #000;
  color: #fff;
}

.ctb-search-icon {
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  width: 14px;
  height: 14px;
  color: #94a3b8;
  pointer-events: none;
}
.ctb-search-input {
  border: 1px solid rgba(255, 255, 255, 0.8);
  background: rgba(255, 255, 255, 0.6);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  transition: border-color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
}
.ctb-search-input:focus {
  outline: none;
  background: rgba(255, 255, 255, 0.9);
  border-color: #a5b4fc;
  box-shadow: 0 0 0 3px rgba(165, 180, 252, 0.25);
}

.ctb-tab {
  color: #64748b;
  background: rgba(255, 255, 255, 0.4);
  border: 1px solid rgba(255, 255, 255, 0.7);
}
.ctb-tab:hover {
  background: rgba(255, 255, 255, 0.7);
  color: #4338ca;
}
.ctb-tab-active {
  background: linear-gradient(135deg, #4f46e5, #6366f1);
  border-color: transparent;
  color: #fff;
  box-shadow: 0 4px 12px -3px rgba(79, 70, 229, 0.5);
}

.ctb-content {
  scrollbar-width: thin;
}

.ctb-row {
  padding: 9px 12px;
  border-radius: 10px;
  font-size: 0.875rem;
  color: #334155;
  background: transparent;
  border: 1px solid transparent;
  transition: background 0.15s ease, border-color 0.15s ease, transform 0.1s ease;
}
.ctb-row:hover {
  background: rgba(255, 255, 255, 0.65);
  border-color: rgba(255, 255, 255, 0.9);
  transform: translateX(1px);
}

.ctb-back-btn {
  color: #4f46e5;
  transition: color 0.15s ease;
}
.ctb-back-btn:hover {
  color: #4338ca;
}

.ctb-actionbar {
  background: rgba(255, 255, 255, 0.4);
  border-top: 1px solid rgba(255, 255, 255, 0.7);
}

.toolbox-action-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.6);
  border: 1px solid rgba(255, 255, 255, 0.8);
  color: #475569;
  font-size: 0.75rem;
  font-weight: 600;
  padding: 6px 10px;
  transition: background 0.15s ease, color 0.15s ease;
}
.toolbox-action-btn:hover {
  background: #eef2ff;
  color: #4338ca;
}
.toolbox-action-btn-active {
  background: #fff1f2;
  border-color: #fecdd3;
  color: #e11d48;
}

.ctb-resize-handle {
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(100, 116, 139, 0.5);
}
.ctb-resize-handle:hover {
  color: rgba(79, 70, 229, 0.6);
}

html.dark .ctb-shell {
  background: linear-gradient(165deg, rgba(30, 41, 59, 0.92), rgba(15, 23, 42, 0.9));
  border-color: rgba(255, 255, 255, 0.1);
  box-shadow: 0 25px 60px -12px rgba(0, 0, 0, 0.6), inset 0 1px 0 rgba(255, 255, 255, 0.06);
}
html.dark .ctb-search-input {
  background: rgba(255, 255, 255, 0.06);
  border-color: rgba(255, 255, 255, 0.12);
  color: #e2e8f0;
}
html.dark .ctb-tab {
  background: rgba(255, 255, 255, 0.05);
  border-color: rgba(255, 255, 255, 0.1);
  color: #cbd5e1;
}
html.dark .ctb-tab:hover {
  background: rgba(255, 255, 255, 0.1);
  color: #c7d2fe;
}
html.dark .ctb-row {
  color: #e2e8f0;
}
html.dark .ctb-row:hover {
  background: rgba(255, 255, 255, 0.08);
  border-color: rgba(255, 255, 255, 0.12);
}
html.dark .ctb-actionbar {
  background: rgba(255, 255, 255, 0.04);
  border-top-color: rgba(255, 255, 255, 0.1);
}
html.dark .toolbox-action-btn {
  background: rgba(255, 255, 255, 0.06);
  border-color: rgba(255, 255, 255, 0.12);
  color: #cbd5e1;
}
html.dark .toolbox-action-btn:hover {
  background: rgba(255, 255, 255, 0.12);
  color: #c7d2fe;
}
</style>

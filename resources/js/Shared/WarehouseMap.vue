<template>
  <div>
    <div v-if="located.length" class="liquid-warehouse-map rounded-xl border border-white/70 overflow-hidden shadow-lg relative">
      <div class="liquid-map-search-row">
        <div class="liquid-map-search">
          <icons name="search" class="w-4 h-4 liquid-map-search-icon" />
          <input
            type="text"
            v-model="searchQuery"
            class="liquid-map-search-input"
            :placeholder="$t('Search project(s) / warehouse(s)...')"
            @keydown.esc="searchQuery = ''"
          />
        </div>
        <ul v-if="searchQuery.trim().length && searchSuggestions.length" class="liquid-map-search-suggestions">
          <li v-for="warehouse in searchSuggestions" :key="warehouse.id" @click="selectSearchResult(warehouse)">
            <span class="liquid-map-pin-mini" :class="warehouse.active ? 'liquid-map-pin-active' : 'liquid-map-pin-inactive'"></span>
            {{ warehouse.name }}
          </li>
        </ul>
      </div>
      <div ref="mapEl" style="height: 420px"></div>
    </div>
    <div v-else class="liquid-warehouse-map-empty rounded-xl border border-dashed border-gray-300 bg-gray-50 p-8 text-center">
      <icons name="building" class="w-8 h-8 mx-auto text-gray-400 mb-2" />
      <p class="text-sm font-medium text-gray-600">{{ $t('No project/warehouse locations set yet') }}</p>
      <p class="text-xs text-gray-500 mt-1">
        {{ $t('Open a project/warehouse and use the location picker on its edit page to plot it here.') }}
      </p>
    </div>

    <div v-if="located.length" class="liquid-map-filters">
      <button
        v-for="filter in typeFilterDefs"
        :key="'type-' + filter.key"
        type="button"
        class="liquid-map-filter-btn"
        :class="{ 'liquid-map-filter-btn-active': activeTypeFilters.includes(filter.key) }"
        @click="toggleTypeFilter(filter.key)"
      >{{ $t(filter.label) }}</button>
      <span class="liquid-map-filter-divider"></span>
      <button
        v-for="filter in regionFilterDefs"
        :key="'region-' + filter.key"
        type="button"
        class="liquid-map-filter-btn"
        :class="{ 'liquid-map-filter-btn-active': activeRegionFilters.includes(filter.key) }"
        @click="toggleRegionFilter(filter.key)"
      >{{ $t(filter.label) }}</button>
      <button
        v-if="activeTypeFilters.length || activeRegionFilters.length"
        type="button"
        class="liquid-map-filter-btn liquid-map-filter-btn-clear"
        @click="clearFilters"
      >{{ $t('Clear filters') }}</button>
    </div>

    <div class="liquid-map-legend">
      <span class="liquid-map-legend-item">
        <span class="liquid-map-legend-pin liquid-map-pin-active"></span>
        {{ $t('Active') }}
      </span>
      <span class="liquid-map-legend-item">
        <span class="liquid-map-legend-pin liquid-map-pin-inactive"></span>
        {{ $t('Inactive') }}
      </span>
      <span class="liquid-map-legend-item text-gray-400">
        <icons name="in" class="w-3.5 h-3.5" />
        {{ $t('Click a pin to open its stock report') }}
      </span>
      <span v-if="unlocated.length" class="liquid-map-legend-item text-gray-400">
        {{ unlocated.length }} {{ $t('without a location set') }}
      </span>
    </div>
  </div>
</template>

<script>
import 'leaflet/dist/leaflet.css';
import L from 'leaflet';
import Icons from '@/Shared/Icons.vue';

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  }[char]));
}

function buildPinIcon(active) {
  return L.divIcon({
    className: 'liquid-map-pin-wrapper',
    html: `<div class="liquid-map-pin ${active ? 'liquid-map-pin-active' : 'liquid-map-pin-inactive'}"><span class="liquid-map-pin-dot"></span></div>`,
    iconSize: [28, 40],
    iconAnchor: [14, 40],
  });
}

function classifyRegion(warehouse) {
  const address = (warehouse.address || '').toLowerCase();
  if (address.includes('pemba')) {
    return 'pemba';
  }
  if (address.includes('zanzibar') || address.includes('unguja')) {
    return 'unguja';
  }
  return 'mainland';
}

function classifyType(warehouse) {
  const status = (warehouse.status || '').toLowerCase().trim();
  if (status.startsWith('main warehouse')) {
    return 'main-warehouses';
  }
  if (status === 'directors') {
    return 'directors';
  }
  if (status === 'private') {
    return 'private';
  }
  if (status === 'government') {
    return 'government';
  }
  return null;
}

const TYPE_FILTERS = [
  { key: 'private', label: 'Private' },
  { key: 'government', label: 'Government' },
  { key: 'main-warehouses', label: 'MainWarehouses' },
  { key: 'directors', label: 'Directors' },
];

const REGION_FILTERS = [
  { key: 'unguja', label: 'Unguja' },
  { key: 'pemba', label: 'Pemba' },
  { key: 'mainland', label: 'MainLand' },
];

const TOOLTIP_CLEARANCE_PX = 260;
const CLUSTER_THRESHOLD_DEG = 0.35;

export default {
  components: { Icons },

  props: {
    warehouses: { type: Array, default: () => [] },
  },

  data() {
    return {
      map: null,
      markersById: {},
      searchQuery: '',
      activeTypeFilters: [],
      activeRegionFilters: [],
      typeFilterDefs: TYPE_FILTERS,
      regionFilterDefs: REGION_FILTERS,
    };
  },

  computed: {
    located() {
      return (this.warehouses || []).filter(w => w.latitude !== null && w.latitude !== undefined && w.longitude !== null && w.longitude !== undefined);
    },
    unlocated() {
      return (this.warehouses || []).filter(w => w.latitude === null || w.latitude === undefined || w.longitude === null || w.longitude === undefined);
    },
    filteredWarehouses() {
      return this.located.filter(w => {
        const typeOk = this.activeTypeFilters.length === 0 || this.activeTypeFilters.includes(classifyType(w));
        const regionOk = this.activeRegionFilters.length === 0 || this.activeRegionFilters.includes(classifyRegion(w));
        return typeOk && regionOk;
      });
    },
    searchSuggestions() {
      const query = this.searchQuery.trim().toLowerCase();
      if (!query) {
        return [];
      }
      return this.filteredWarehouses.filter(w => w.name.toLowerCase().includes(query)).slice(0, 6);
    },
  },

  mounted() {
    if (this.located.length) {
      this.renderMap();
    }
  },

  beforeUnmount() {
    if (this.map) {
      this.map.remove();
    }
  },

  methods: {
    renderMap() {
      this.map = L.map(this.$refs.mapEl, { scrollWheelZoom: false, touchZoom: true, tap: true });

      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 19,
      }).addTo(this.map);

      this.located.forEach(warehouse => {
        const marker = L.marker([warehouse.latitude, warehouse.longitude], { icon: buildPinIcon(warehouse.active) });
        marker._trueLatLng = L.latLng(warehouse.latitude, warehouse.longitude);

        marker.on('click', () => {
          window.open(route('reports.warehousestock', { warehouseId: warehouse.id }), '_blank', 'noopener');
        });

        marker.addTo(this.map);
        this.markersById[warehouse.id] = { marker, warehouse };
      });

      this.focusRegion(this.located);
      this.spreadOverlappingMarkers();

      this.refreshTooltipDirections();
      this.map.on('moveend zoomend', () => this.refreshTooltipDirections());
      this.map.on('zoomend', () => this.spreadOverlappingMarkers());
    },
    spreadOverlappingMarkers() {
      const MIN_CHORD_PX = 34;
      const groups = {};

      Object.values(this.markersById).forEach(({ marker }) => {
        const key = `${marker._trueLatLng.lat.toFixed(5)},${marker._trueLatLng.lng.toFixed(5)}`;
        (groups[key] = groups[key] || []).push(marker);
      });

      const zoom = this.map.getZoom();

      Object.values(groups).forEach(group => {
        if (group.length === 1) {
          group[0].setLatLng(group[0]._trueLatLng);
          return;
        }

        const radius = MIN_CHORD_PX / (2 * Math.sin(Math.PI / group.length));
        const center = this.map.project(group[0]._trueLatLng, zoom);
        group.forEach((marker, i) => {
          const angle = (2 * Math.PI * i) / group.length;
          const point = L.point(center.x + radius * Math.cos(angle), center.y + radius * Math.sin(angle));
          marker.setLatLng(this.map.unproject(point, zoom));
        });
      });
    },
    groupByProximity(points, thresholdDeg) {
      const parent = points.map((_, i) => i);
      const find = i => {
        while (parent[i] !== i) {
          parent[i] = parent[parent[i]];
          i = parent[i];
        }
        return i;
      };
      const union = (i, j) => {
        const ri = find(i);
        const rj = find(j);
        if (ri !== rj) {
          parent[ri] = rj;
        }
      };

      for (let i = 0; i < points.length; i++) {
        for (let j = i + 1; j < points.length; j++) {
          const dLat = points[i].latitude - points[j].latitude;
          const dLng = points[i].longitude - points[j].longitude;
          if (Math.sqrt(dLat * dLat + dLng * dLng) <= thresholdDeg) {
            union(i, j);
          }
        }
      }

      const groups = {};
      points.forEach((point, i) => {
        const root = find(i);
        (groups[root] = groups[root] || []).push(point);
      });
      return Object.values(groups);
    },
    focusRegion(warehouseList) {
      if (!warehouseList.length) {
        return;
      }

      if (warehouseList.length === 1) {
        this.map.setView([warehouseList[0].latitude, warehouseList[0].longitude], 14);
        return;
      }

      const groups = this.groupByProximity(warehouseList, CLUSTER_THRESHOLD_DEG);
      let largest = groups[0];
      groups.forEach(group => {
        if (group.length > largest.length) {
          largest = group;
        }
      });

      const bounds = L.latLngBounds(largest.map(w => [w.latitude, w.longitude]));
      this.map.fitBounds(bounds.pad(0.35), { maxZoom: 14 });
    },
    refreshTooltipDirections() {
      Object.values(this.markersById).forEach(({ marker, warehouse }) => {
        const point = this.map.latLngToContainerPoint(marker.getLatLng());
        const direction = point.y < TOOLTIP_CLEARANCE_PX ? 'bottom' : 'top';

        if (marker._liquidTooltipDirection === direction) {
          return;
        }

        marker._liquidTooltipDirection = direction;
        if (marker.getTooltip()) {
          marker.unbindTooltip();
        }

        marker.bindTooltip(this.tooltipHtml(warehouse), {
          className: 'liquid-map-tooltip',
          direction,
          offset: direction === 'bottom' ? [0, 14] : [0, -38],
          opacity: 1,
        });
      });
    },
    toggleTypeFilter(key) {
      const index = this.activeTypeFilters.indexOf(key);
      if (index === -1) {
        this.activeTypeFilters.push(key);
      } else {
        this.activeTypeFilters.splice(index, 1);
      }
      this.applyFilters();
    },
    toggleRegionFilter(key) {
      const index = this.activeRegionFilters.indexOf(key);
      if (index === -1) {
        this.activeRegionFilters.push(key);
      } else {
        this.activeRegionFilters.splice(index, 1);
      }
      this.applyFilters();
    },
    clearFilters() {
      this.activeTypeFilters = [];
      this.activeRegionFilters = [];
      this.applyFilters();
    },
    applyFilters() {
      const visibleIds = new Set(this.filteredWarehouses.map(w => String(w.id)));

      Object.entries(this.markersById).forEach(([id, { marker }]) => {
        const shouldShow = visibleIds.has(String(id));
        const isShown = this.map.hasLayer(marker);
        if (shouldShow && !isShown) {
          marker.addTo(this.map);
        } else if (!shouldShow && isShown) {
          this.map.removeLayer(marker);
        }
      });

      if (this.filteredWarehouses.length) {
        this.focusRegion(this.filteredWarehouses);
      }
    },
    selectSearchResult(warehouse) {
      this.searchQuery = '';
      const entry = this.markersById[warehouse.id];
      if (!entry) {
        return;
      }
      this.map.setView([warehouse.latitude, warehouse.longitude], 16);
      setTimeout(() => entry.marker.openTooltip(), 350);
    },
    tooltipHtml(warehouse) {
      const statusClass = warehouse.active ? 'liquid-map-tooltip-status-active' : 'liquid-map-tooltip-status-inactive';
      const statusDotColor = warehouse.active ? '#10b981' : '#94a3b8';
      const statusLabel = warehouse.active ? this.$t('Active') : this.$t('Inactive');

      const rows = [];
      if (warehouse.status) {
        rows.push(['\u{1F3D7}', escapeHtml(warehouse.status)]);
      }
      if (warehouse.address) {
        rows.push(['\u{1F4CD}', escapeHtml(warehouse.address)]);
      }
      if (warehouse.phone) {
        rows.push(['\u{260E}', escapeHtml(warehouse.phone)]);
      }
      if (warehouse.email) {
        rows.push(['\u{2709}', escapeHtml(warehouse.email)]);
      }

      const rowsHtml = rows
        .map(([symbol, text]) => `<div class="liquid-map-tooltip-row"><span class="liquid-map-tooltip-symbol">${symbol}</span><span>${text}</span></div>`)
        .join('');

      return `
        <div class="liquid-map-tooltip-card">
          <p class="liquid-map-tooltip-title">${escapeHtml(warehouse.name)}</p>
          <p class="liquid-map-tooltip-code">${escapeHtml(warehouse.code)}</p>
          <span class="liquid-map-tooltip-status ${statusClass}">
            <span class="liquid-map-tooltip-dot" style="background:${statusDotColor}"></span>
            ${escapeHtml(statusLabel)}
          </span>
          ${rowsHtml}
          <p class="liquid-map-tooltip-hint">${escapeHtml(this.$t('Click to view stock report'))}</p>
        </div>
      `;
    },
  },
};
</script>

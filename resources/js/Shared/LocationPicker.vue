<template>
  <div class="lp-card">
    <header class="lp-head">
      <span class="lp-ico"><icons name="map-pin" class="w-5 h-5" /></span>
      <div class="lp-head-txt">
        <h3 class="lp-title">{{ $t('Location on Map') }}</h3>
        <p class="lp-sub">{{ $t('Click on the map to set this project/warehouse\'s location, or drag the pin to adjust it.') }} {{ $t('The address above follows the pin.') }}</p>
      </div>
      <span class="lp-state" :class="hasLocation ? 'is-set' : 'is-empty'">
        <span class="lp-state-dot"></span>
        {{ hasLocation ? $t('Pinned') : $t('Not set') }}
      </span>
    </header>

    <div class="lp-stage">
      <div class="lp-map" ref="mapEl"></div>

      <!-- floating glass search -->
      <div class="lp-search">
        <icons name="search" class="lp-search-ico" />
        <input
          type="text"
          v-model="searchQuery"
          class="lp-search-input"
          :placeholder="$t('Search for a place to move the pin...')"
          @keydown.esc="searchQuery = ''"
          @keydown.enter.prevent="searchPlace"
        />
        <button v-if="searchQuery" type="button" class="lp-search-x" :aria-label="$t('Clear')" @click="searchQuery = ''"><icons name="cross" class="w-3.5 h-3.5" /></button>
        <ul v-if="placeSuggestions.length" class="lp-suggest">
          <li v-for="place in placeSuggestions" :key="place.place_id" @click="selectPlace(place)">
            <icons name="map-pin" class="w-3.5 h-3.5" />
            <span>{{ place.display_name }}</span>
          </li>
        </ul>
      </div>

      <!-- hint while nothing is pinned -->
      <div v-if="!hasLocation" class="lp-hint">
        <span class="lp-hint-pin"><icons name="map-pin" class="w-5 h-5" /></span>
        {{ $t('Tap the map to drop the pin') }}
      </div>

      <!-- floating glass actions -->
      <div class="lp-actions">
        <button type="button" class="lp-btn lp-btn-blue" @click="useCurrentLocation(false)" :disabled="locating">
          <icons name="in" class="w-4 h-4" />
          {{ locating ? $t('Locating...') : $t('Use My Current Location') }}
        </button>
        <button type="button" class="lp-btn lp-btn-red" v-if="hasLocation" @click="clearLocation">
          <icons name="cross" class="w-4 h-4" />
          {{ $t('Clear Location') }}
        </button>
      </div>
    </div>

    <footer class="lp-foot">
      <div class="lp-coords" v-if="hasLocation">
        <span class="lp-coord"><small>{{ $t('Latitude') }}</small><b>{{ latitude.toFixed(6) }}</b></span>
        <span class="lp-coord"><small>{{ $t('Longitude') }}</small><b>{{ longitude.toFixed(6) }}</b></span>
      </div>
      <span v-else class="lp-none">{{ $t('No location set yet') }}</span>
      <span v-if="geocoding" class="lp-note">{{ $t('Locating address on map...') }}</span>
      <span v-if="locateError" class="lp-note lp-note-err">{{ locateError }}</span>
    </footer>
  </div>
</template>

<script>
import 'leaflet/dist/leaflet.css';
import L from 'leaflet';
import TecLabel from '@/Jetstream/Label.vue';
import Icons from '@/Shared/Icons.vue';

const DEFAULT_CENTER = [-6.1659, 39.2026]; // Zanzibar, as a sensible default map center
const DEFAULT_ZOOM = 12;
const PIN_ZOOM = 12; // about 3 km on the scale bar: the pin with its surroundings, zoom in on the map to be exact

function buildPinIcon() {
  return L.divIcon({
    className: 'liquid-map-pin-wrapper',
    html: '<div class="liquid-map-pin liquid-map-pin-active"><span class="liquid-map-pin-dot"></span></div>',
    iconSize: [30, 42],
    iconAnchor: [15, 42],
  });
}

export default {
  components: { TecLabel, Icons },

  props: {
    latitude: { type: Number, default: null },
    longitude: { type: Number, default: null },
    address: { type: String, default: '' },
    autoFollowAddress: { type: Boolean, default: true },
  },

  emits: ['update:latitude', 'update:longitude', 'update:address'],

  data() {
    return {
      map: null,
      marker: null,
      accuracyCircle: null,
      lastSetAddress: null,
      reverseSeq: 0,
      reverseTimer: null,
      locating: false,
      locateError: '',
      geocoding: false,
      manuallyPlaced: this.latitude !== null && this.latitude !== undefined && this.longitude !== null && this.longitude !== undefined,
      geocodeTimer: null,
      searchQuery: '',
      placeSuggestions: [],
      searchTimer: null,
    };
  },

  computed: {
    hasLocation() {
      return this.latitude !== null && this.latitude !== undefined && this.longitude !== null && this.longitude !== undefined;
    },
  },

  watch: {
    searchQuery(newValue) {
      clearTimeout(this.searchTimer);

      const query = (newValue || '').trim();
      if (query.length < 3) {
        this.placeSuggestions = [];
        return;
      }

      this.searchTimer = setTimeout(() => this.fetchPlaceSuggestions(query), 400);
    },
  },

  mounted() {
    const center = this.hasLocation ? [this.latitude, this.longitude] : DEFAULT_CENTER;

    this.map = L.map(this.$refs.mapEl, { scrollWheelZoom: false, touchZoom: true, tap: true, zoomControl: false }).setView(center, this.hasLocation ? PIN_ZOOM : DEFAULT_ZOOM);
    L.control.zoom({ position: 'topright' }).addTo(this.map);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
      maxZoom: 19,
    }).addTo(this.map);

    if (this.hasLocation) {
      this.placeMarker(this.latitude, this.longitude);
    }

    L.control.scale({ position: 'bottomleft', imperial: false }).addTo(this.map);

    this.map.on('click', e => {
      this.manuallyPlaced = true;
      this.placeMarker(e.latlng.lat, e.latlng.lng, true);
    });

    if (!this.hasLocation) {
      // nothing chosen yet: start from where this person is (they can still click the map or search for a place)
      this.useCurrentLocation(true);
    } else if (!(this.address || '').trim()) {
      // a pin with no address yet: write the name of the place under the pin
      this.reverseSoon(this.latitude, this.longitude, true);
    }
  },

  beforeUnmount() {
    clearTimeout(this.geocodeTimer);
    clearTimeout(this.reverseTimer);
    if (this.map) {
      this.map.remove();
    }
  },

  methods: {
    placeMarker(lat, lng, emit = false, reverse = true) {
      if (this.marker) {
        this.marker.setLatLng([lat, lng]);
      } else {
        this.marker = L.marker([lat, lng], { icon: buildPinIcon(), draggable: true }).addTo(this.map);
        this.marker.on('dragend', () => {
          this.manuallyPlaced = true;
          const pos = this.marker.getLatLng();
          this.$emit('update:latitude', Number(pos.lat.toFixed(7)));
          this.$emit('update:longitude', Number(pos.lng.toFixed(7)));
          this.reverseSoon(pos.lat, pos.lng);
        });
      }

      if (emit) {
        this.$emit('update:latitude', Number(lat.toFixed(7)));
        this.$emit('update:longitude', Number(lng.toFixed(7)));
        if (reverse) {
          this.reverseSoon(lat, lng);
        }
      }
    },
    // map -> address: write the name of the place under the pin into the address field
    reverseSoon(lat, lng, onlyIfEmpty = false) {
      clearTimeout(this.reverseTimer);
      const seq = ++this.reverseSeq;
      this.reverseTimer = setTimeout(() => {
        if (onlyIfEmpty && (this.address || '').trim() !== '') {
          return;
        }
        fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=18&lat=${lat}&lon=${lng}`)
          .then(response => response.json())
          .then(result => {
            // a newer click, or the person typing an address meanwhile, wins over this answer
            if (seq !== this.reverseSeq || !result || !result.display_name) {
              return;
            }
            if (onlyIfEmpty && (this.address || '').trim() !== '') {
              return;
            }
            const text = String(result.display_name).slice(0, 250);
            this.lastSetAddress = text;
            this.$emit('update:address', text);
          })
          .catch(() => {});
      }, 500);
    },
    geocodeAddress(query, onMiss = null) {
      this.geocoding = true;

      fetch(`https://nominatim.openstreetmap.org/search?format=json&limit=1&q=${encodeURIComponent(query)}`)
        .then(response => response.json())
        .then(results => {
          this.geocoding = false;

          // the address may have been changed again, or replaced by a map click, while this answer was on its way
          if ((this.address || '').trim() !== query) {
            return;
          }
          if (!results || !results.length) {
            if (onMiss) onMiss();
            return;
          }

          const lat = Number(results[0].lat);
          const lng = Number(results[0].lon);
          this.map.setView([lat, lng], PIN_ZOOM);
          this.placeMarker(lat, lng, true, false);
        })
        .catch(() => {
          this.geocoding = false;
          if (onMiss) onMiss();
        });
    },
    fetchPlaceSuggestions(query) {
      fetch(`https://nominatim.openstreetmap.org/search?format=json&limit=5&q=${encodeURIComponent(query)}`)
        .then(response => response.json())
        .then(results => {
          this.placeSuggestions = results || [];
        })
        .catch(() => {
          this.placeSuggestions = [];
        });
    },
    searchPlace() {
      if (this.placeSuggestions.length) {
        this.selectPlace(this.placeSuggestions[0]);
      }
    },
    selectPlace(place) {
      this.searchQuery = '';
      this.placeSuggestions = [];
      this.manuallyPlaced = true;
      const lat = Number(place.lat);
      const lng = Number(place.lon);
      this.map.setView([lat, lng], PIN_ZOOM);
      this.placeMarker(lat, lng, true);
    },
    useCurrentLocation(auto = false) {
      auto = auto === true;
      if (!navigator.geolocation) {
        if (!auto) this.locateError = this.$t('Geolocation is not supported by this browser.');
        return;
      }

      this.locating = true;
      this.locateError = '';

      navigator.geolocation.getCurrentPosition(
        position => {
          this.locating = false;
          if (auto && (this.hasLocation || !this.map)) return; // a location arrived meanwhile (address lookup, a click): never override it
          this.manuallyPlaced = !auto;
          const { latitude, longitude, accuracy } = position.coords;
          this.map.setView([latitude, longitude], PIN_ZOOM);
          this.placeMarker(latitude, longitude, true, !auto);
          if (auto) this.reverseSoon(latitude, longitude, true);
          this.showAccuracy(latitude, longitude, accuracy);
        },
        () => {
          this.locating = false;
          this.locateError = auto
            ? this.$t('Allow location access to start from where you are, or click the map, search for a place, or type the address.')
            : this.$t('Could not get your current location. Please allow location access or click on the map instead.');
        },
        { enableHighAccuracy: true, timeout: 10000 }
      );
    },
    showAccuracy(lat, lng, meters) {
      if (this.accuracyCircle) {
        this.map.removeLayer(this.accuracyCircle);
        this.accuracyCircle = null;
      }
      if (meters && meters < 5000) {
        this.accuracyCircle = L.circle([lat, lng], { radius: meters, color: '#2f6fb3', weight: 1, fillColor: '#2f6fb3', fillOpacity: 0.12, interactive: false }).addTo(this.map);
      }
    },
    clearLocation() {
      if (this.accuracyCircle) {
        this.map.removeLayer(this.accuracyCircle);
        this.accuracyCircle = null;
      }
      if (this.marker) {
        this.map.removeLayer(this.marker);
        this.marker = null;
      }
      this.manuallyPlaced = false;
      this.$emit('update:latitude', null);
      this.$emit('update:longitude', null);
    },
  },
};
</script>

<style scoped>
.lp-card { border-radius: 24px; padding: 14px; background: linear-gradient(150deg, rgba(239, 246, 255, 0.9), rgba(245, 243, 255, 0.85) 60%, rgba(236, 253, 245, 0.8)); border: 1px solid rgba(255, 255, 255, 0.9); box-shadow: 0 22px 44px -26px rgba(37, 99, 235, 0.5), inset 0 1px 1px #fff; }
.lp-head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
.lp-ico { display: inline-flex; align-items: center; justify-content: center; width: 42px; height: 42px; border-radius: 14px; color: #fff; flex: none; background: linear-gradient(145deg, #34d399, #0369a1); box-shadow: 0 12px 20px -9px rgba(3, 105, 161, 0.8); }
.lp-head-txt { flex: 1; min-width: 180px; }
.lp-title { margin: 0; font-size: 1rem; font-weight: 800; color: #0f172a; }
.lp-sub { margin: 1px 0 0; font-size: 0.75rem; color: #64748b; }
.lp-state { display: inline-flex; align-items: center; gap: 7px; padding: 5px 12px; border-radius: 9999px; font-size: 0.74rem; font-weight: 800; border: 1px solid transparent; }
.lp-state.is-set { color: #047857; background: rgba(16, 185, 129, 0.14); border-color: rgba(16, 185, 129, 0.35); }
.lp-state.is-empty { color: #b45309; background: rgba(245, 158, 11, 0.14); border-color: rgba(245, 158, 11, 0.35); }
.lp-state-dot { position: relative; width: 8px; height: 8px; border-radius: 9999px; background: currentColor; }
.lp-state.is-set .lp-state-dot::after { content: ''; position: absolute; inset: -4px; border-radius: 9999px; border: 2px solid currentColor; opacity: 0; animation: lp-live 1.8s ease-out infinite; }
@keyframes lp-live { 0% { transform: scale(0.5); opacity: 0.8; } 100% { transform: scale(1.7); opacity: 0; } }

.lp-stage { position: relative; border-radius: 20px; overflow: hidden; border: 3px solid #fff; box-shadow: 0 18px 34px -18px rgba(15, 37, 64, 0.55), 0 0 0 1px rgba(148, 163, 184, 0.35); }
.lp-map { height: 340px; width: 100%; z-index: 0; }
.lp-search { position: absolute; z-index: 500; top: 12px; left: 12px; right: 12px; max-width: 460px; display: flex; align-items: center; gap: 8px; padding: 0 12px; height: 42px; border-radius: 14px; background: rgba(255, 255, 255, 0.72); -webkit-backdrop-filter: blur(14px) saturate(180%); backdrop-filter: blur(14px) saturate(180%); border: 1px solid rgba(255, 255, 255, 0.95); box-shadow: 0 14px 26px -14px rgba(15, 37, 64, 0.5), inset 0 1px 1px #fff; }
.lp-search:focus-within { box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25), 0 14px 26px -14px rgba(15, 37, 64, 0.5); }
.lp-search-ico { width: 16px; height: 16px; color: #64748b; flex: none; }
.lp-search-input { flex: 1; min-width: 0; border: 0 !important; outline: none !important; box-shadow: none !important; background: transparent; font-size: 0.86rem; color: #0f172a; padding: 0 !important; }
.lp-search-x { display: inline-flex; padding: 4px; border: 0; background: rgba(15, 23, 42, 0.06); border-radius: 9999px; color: #475569; cursor: pointer; }
.lp-suggest { position: absolute; left: 0; right: 0; top: calc(100% + 6px); margin: 0; padding: 6px; list-style: none; max-height: 220px; overflow-y: auto; border-radius: 16px; background: rgba(255, 255, 255, 0.92); -webkit-backdrop-filter: blur(16px); backdrop-filter: blur(16px); border: 1px solid #fff; box-shadow: 0 22px 40px -18px rgba(15, 37, 64, 0.6); }
.lp-suggest li { display: flex; align-items: flex-start; gap: 8px; padding: 8px 10px; border-radius: 11px; font-size: 0.8rem; color: #1e293b; cursor: pointer; }
.lp-suggest li svg { margin-top: 2px; color: #0369a1; flex: none; }
.lp-suggest li:hover { background: rgba(59, 130, 246, 0.12); }
.lp-hint { position: absolute; z-index: 450; left: 50%; bottom: 74px; transform: translateX(-50%); display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 9999px; font-size: 0.8rem; font-weight: 700; color: #0f172a; background: rgba(255, 255, 255, 0.78); -webkit-backdrop-filter: blur(12px); backdrop-filter: blur(12px); border: 1px solid #fff; box-shadow: 0 14px 26px -14px rgba(15, 37, 64, 0.55); pointer-events: none; animation: lp-bob 2.4s ease-in-out infinite; white-space: nowrap; }
.lp-hint-pin { color: #e11d48; display: inline-flex; }
@keyframes lp-bob { 0%, 100% { transform: translate(-50%, 0); } 50% { transform: translate(-50%, -5px); } }
.lp-actions { position: absolute; z-index: 500; left: 12px; right: 12px; bottom: 12px; display: flex; flex-wrap: wrap; gap: 8px; }
.lp-btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 15px; border-radius: 9999px; font-size: 0.8rem; font-weight: 800; cursor: pointer; border: 1px solid rgba(255, 255, 255, 0.95); -webkit-backdrop-filter: blur(12px) saturate(170%); backdrop-filter: blur(12px) saturate(170%); box-shadow: 0 14px 24px -12px rgba(15, 37, 64, 0.55), inset 0 1px 1px rgba(255, 255, 255, 0.9); transition: transform 0.15s ease; }
.lp-btn:hover:not(:disabled) { transform: translateY(-1px); }
.lp-btn:disabled { opacity: 0.6; cursor: wait; }
.lp-btn-blue { color: #1d4ed8; background: rgba(239, 246, 255, 0.82); }
.lp-btn-red { color: #be123c; background: rgba(255, 241, 242, 0.84); }
.lp-foot { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
.lp-coords { display: flex; gap: 10px; flex-wrap: wrap; }
.lp-coord { display: inline-flex; flex-direction: column; padding: 6px 14px; border-radius: 14px; background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 8px 16px -12px rgba(15, 37, 64, 0.5); }
.lp-coord small { font-size: 0.6rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: #94a3b8; }
.lp-coord b { font-size: 0.9rem; font-variant-numeric: tabular-nums; color: #0f172a; }
.lp-none { font-size: 0.8rem; color: #94a3b8; }
.lp-note { font-size: 0.74rem; color: #64748b; }
.lp-note-err { color: #dc2626; font-weight: 700; }
@media (max-width: 640px) { .lp-map { height: 300px; } .lp-hint { bottom: 118px; } .lp-btn { flex: 1 1 100%; justify-content: center; } }
html.dark .lp-card { background: linear-gradient(150deg, rgba(30, 41, 59, 0.85), rgba(15, 23, 42, 0.9)); border-color: rgba(255, 255, 255, 0.1); }
html.dark .lp-title { color: #e2e8f0; }
html.dark .lp-coord { background: #1e293b; border-color: rgba(255, 255, 255, 0.1); }
html.dark .lp-coord b { color: #e2e8f0; }
@media (prefers-reduced-motion: reduce) { .lp-hint, .lp-state-dot::after { animation: none !important; } }
</style>
<style>
/* Leaflet controls drawn in the same frosted-glass language as the picker */
.lp-map .leaflet-bar { border: 0 !important; border-radius: 14px; overflow: hidden; box-shadow: 0 14px 24px -12px rgba(15, 37, 64, 0.55); }
.lp-map .leaflet-bar a, .lp-map .leaflet-bar a:hover { display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; padding: 0; line-height: 1; text-indent: 0; font: 700 20px/1 inherit; text-decoration: none; }
.lp-map .leaflet-bar a > span { display: block; line-height: 1; margin: 0; transform: translateY(-1px); }
.lp-map .leaflet-bar a { background: rgba(255, 255, 255, 0.82); -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px); color: #1e293b; border-bottom: 1px solid rgba(15, 23, 42, 0.08); }
.lp-map .leaflet-bar a:last-child { border-bottom: 0; }
.lp-map .leaflet-bar a.leaflet-disabled { opacity: 0.45; }
.lp-map .leaflet-control-scale-line { background: rgba(255, 255, 255, 0.7); border-color: #475569; color: #334155; font-size: 10px; border-radius: 0 6px 6px 0; }
.lp-map .leaflet-bottom.leaflet-left { bottom: 58px; }
.lp-map .leaflet-bar a:hover { background: #fff; color: #1d4ed8; }
.lp-map .leaflet-control-attribution { background: rgba(255, 255, 255, 0.7); border-radius: 8px 0 0 0; font-size: 10px; }
.lp-map .leaflet-top.leaflet-right { top: 56px; }
</style>

<template>
  <modal :show="open" max-width="lg" :closeable="true" @close="close">
    <div class="rounded-md overflow-hidden">
      <div class="px-6 py-4 bg-white border-b flex items-center justify-between">
        <div class="text-lg font-semibold">{{ $t('Scan Barcode') }}</div>
        <button
          type="button"
          @click="close"
          class="flex items-center justify-center h-8 w-8 rounded-full text-gray-600 hover:text-gray-800 hover:bg-gray-300 focus:outline-none"
        >
          <icons name="cross" class="h-5 w-5" />
        </button>
      </div>

      <div class="p-6 bg-gray-50 space-y-4">
        <p class="text-sm text-gray-600">{{ $t('Point your camera at the barcode. The item will be added automatically after scan.') }}</p>
        <div class="flex items-center gap-2 flex-wrap">
          <button type="button" @click="openCameraCapture" class="cbs-btn cbs-btn-primary">
            {{ $t('Scan From Camera Photo') }}
          </button>
          <span v-if="!canUseLiveCamera" class="text-xs text-amber-700">
            {{ $t('Live camera requires HTTPS on mobile. Use camera photo scan or open app on HTTPS.') }}
          </span>
        </div>
        <input ref="cameraCaptureInput" type="file" accept="image/*" capture="environment" class="hidden" @change="scanCapturedImage" />
        <div class="grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-2">
          <input
            v-model="manualBarcode"
            type="text"
            class="w-full rounded-md border-gray-300 focus:border-blue-500 focus:ring-blue-500"
            :placeholder="$t('Or enter barcode manually')"
            @keyup.enter="submitManualBarcode"
          />
          <button type="button" @click="submitManualBarcode" class="cbs-btn cbs-btn-dark">{{ $t('Find Item') }}</button>
        </div>
        <div :id="scannerElementId" class="w-full min-h-[260px] rounded-md overflow-hidden bg-black"></div>
        <p v-if="cameraLoading" class="text-sm text-gray-600">{{ $t('Opening camera...') }}</p>
        <p v-if="cameraStatusMessage" class="text-sm text-blue-700">{{ cameraStatusMessage }}</p>
        <p v-if="cameraError" class="text-sm text-red-600">{{ cameraError }}</p>
      </div>
    </div>
  </modal>
</template>

<script>
import { v4 as uuidv4 } from 'uuid';
import Modal from '@/Jetstream/Modal.vue';

// Drop-in live/photo barcode scanner shared by every "Scan barcode or search
// items" transaction form (Checkin, Checkout, Adjustment, Transfer, ...).
// Ported from the working camera scanner on Item/Index.vue so every form
// resolves barcodes through the same items.scan endpoint and item-matching
// rules instead of drifting into per-page copies.
export default {
  components: { Modal },

  emits: ['resolved'],

  data() {
    return {
      open: false,
      scanning: false,
      cameraLoading: false,
      cameraError: null,
      cameraStatusMessage: null,
      cameraScanner: null,
      lastScannedCode: null,
      manualBarcode: '',
      scannerElementId: 'camera-barcode-scanner-' + uuidv4(),
      canUseLiveCamera: typeof window !== 'undefined' ? window.isSecureContext && !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia) : false,
    };
  },

  unmounted() {
    this.stopCameraScanner();
  },

  methods: {
    show() {
      this.open = true;
      this.cameraError = null;
      this.cameraStatusMessage = this.$t('Scanner ready. Point the barcode inside the frame.');
      this.lastScannedCode = null;
      this.manualBarcode = '';
      this.$nextTick(() => this.startCameraScanner());
    },
    close() {
      this.open = false;
      this.cameraStatusMessage = null;
      this.stopCameraScanner();
    },
    normalizeBarcode(value) {
      const raw = (value || '').trim();
      if (!raw) return '';
      const noGroupSeparator = raw.replace(/[\u001D]/g, '');
      const withoutPrefix = noGroupSeparator.replace(/^\][A-Za-z0-9]{2}/, '');
      return withoutPrefix.replace(/\s+/g, '');
    },
    // Resolves a raw scanned/typed code to an item via items.scan (which
    // handles GS1 prefixes, leading zeros, code/sku/name fallback search),
    // then fetches the full item record (same shape AutoComplete's `json`
    // mode emits) so the caller's existing itemSelected() handler can be
    // reused unchanged.
    scanSearch(value, { showNotFound = false } = {}) {
      const code = this.normalizeBarcode(value);
      if (!code || this.scanning) return Promise.resolve(false);
      this.scanning = true;
      return axios
        .post(route('items.scan'), { code })
        .then(res => {
          if (res.data.found) {
            this.cameraError = null;
            return axios.post(route('items.search'), { id: res.data.id }).then(itemRes => {
              const item = itemRes.data[0];
              if (item) {
                this.$emit('resolved', item);
              }
              return !!item;
            });
          }

          if (showNotFound) {
            this.cameraError = this.$t('Barcode scanned but no item matched. Please verify the barcode is saved as Item Code/SKU.');
          }

          return false;
        })
        .catch(() => {
          if (showNotFound) {
            this.cameraError = this.$t('Could not validate scanned barcode. Please try again.');
          }

          return false;
        })
        .finally(() => (this.scanning = false));
    },
    async startCameraScanner() {
      if (!this.open || this.cameraLoading) {
        return;
      }

      if (!this.canUseLiveCamera) {
        this.cameraError = this.$t('Live camera scanner is unavailable on this connection. Use "Scan From Camera Photo" or open the app over HTTPS.');
        return;
      }

      this.cameraLoading = true;
      this.cameraStatusMessage = this.$t('Opening camera...');

      try {
        const { Html5Qrcode, Html5QrcodeSupportedFormats } = await import('html5-qrcode');

        if (this.cameraScanner) {
          await this.stopCameraScanner();
        }

        this.cameraScanner = new Html5Qrcode(this.scannerElementId);

        await this.cameraScanner.start(
          { facingMode: 'environment' },
          {
            fps: 10,
            qrbox: (viewfinderWidth, viewfinderHeight) => ({
              width: Math.floor(viewfinderWidth * 0.9),
              height: Math.floor(viewfinderHeight * 0.4),
            }),
            aspectRatio: 1.333334,
            rememberLastUsedCamera: true,
            formatsToSupport: [
              Html5QrcodeSupportedFormats.CODE_128,
              Html5QrcodeSupportedFormats.CODE_39,
              Html5QrcodeSupportedFormats.CODE_93,
              Html5QrcodeSupportedFormats.CODABAR,
              Html5QrcodeSupportedFormats.EAN_13,
              Html5QrcodeSupportedFormats.EAN_8,
              Html5QrcodeSupportedFormats.UPC_A,
              Html5QrcodeSupportedFormats.UPC_E,
              Html5QrcodeSupportedFormats.ITF,
              Html5QrcodeSupportedFormats.QR_CODE,
              Html5QrcodeSupportedFormats.DATA_MATRIX,
            ],
          },
          decodedText => {
            const code = (decodedText || '').trim();
            if (!code || this.lastScannedCode === code || this.scanning) {
              return;
            }

            this.lastScannedCode = code;
            this.cameraStatusMessage = this.$t('Detected barcode') + ': ' + code;
            this.scanSearch(code, { showNotFound: true }).then(found => {
              if (found) {
                this.close();
              } else {
                this.lastScannedCode = null;
              }
            });
          },
          () => {}
        );

        this.cameraStatusMessage = this.$t('Camera started. Hold barcode steady for 1-2 seconds.');
      } catch (error) {
        this.cameraError = this.$t('Unable to start camera scanner. Please allow camera access and try again.');
        this.cameraStatusMessage = null;
      } finally {
        this.cameraLoading = false;
      }
    },
    openCameraCapture() {
      this.cameraError = null;
      this.cameraStatusMessage = this.$t('Opening camera photo capture...');
      this.$refs.cameraCaptureInput?.click();
    },
    submitManualBarcode() {
      const code = this.normalizeBarcode(this.manualBarcode);
      if (!code) {
        this.cameraError = this.$t('Please enter a barcode value.');
        return;
      }

      this.cameraError = null;
      this.cameraStatusMessage = this.$t('Searching item for') + ': ' + code;
      this.scanSearch(code, { showNotFound: true }).then(found => {
        if (found) {
          this.close();
        }
      });
    },
    async scanCapturedImage(event) {
      const file = event?.target?.files?.[0];
      if (!file || this.scanning) {
        return;
      }

      this.cameraLoading = true;
      this.cameraError = null;
      this.cameraStatusMessage = this.$t('Processing selected image...');

      try {
        let code = '';

        // Pass 1: html5-qrcode
        try {
          const { Html5Qrcode } = await import('html5-qrcode');
          const fileScanner = new Html5Qrcode(this.scannerElementId);
          const decodedText = await fileScanner.scanFile(file, true);
          code = (decodedText || '').trim();

          try {
            await fileScanner.clear();
          } catch (e) {
          }
        } catch (e) {
        }

        // Pass 2: ZXing fallback
        if (!code) {
          const { BrowserMultiFormatReader } = await import('@zxing/browser');
          const reader = new BrowserMultiFormatReader();
          const imageUrl = URL.createObjectURL(file);

          try {
            const result = await reader.decodeFromImageUrl(imageUrl);
            code = (result?.getText?.() || '').trim();
          } finally {
            URL.revokeObjectURL(imageUrl);
            reader.reset();
          }
        }

        if (!code) {
          this.cameraError = this.$t('No barcode detected in the selected image.');
          return;
        }

        this.lastScannedCode = code;
        this.cameraStatusMessage = this.$t('Detected barcode') + ': ' + code;
        const found = await this.scanSearch(code, { showNotFound: true });
        if (found) {
          this.close();
        }
      } catch (error) {
        this.cameraError = this.$t('Could not read barcode from image. Please retake with clearer focus and better lighting.');
        this.cameraStatusMessage = null;
      } finally {
        this.cameraLoading = false;
        if (event?.target) {
          event.target.value = '';
        }
      }
    },
    async stopCameraScanner() {
      if (!this.cameraScanner) {
        return;
      }

      try {
        await this.cameraScanner.stop();
      } catch (e) {
      }

      try {
        await this.cameraScanner.clear();
      } catch (e) {
      }

      this.cameraScanner = null;
      this.cameraLoading = false;
    },
  },
};
</script>

<style scoped>
.cbs-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.5rem 0.75rem;
  border-radius: 0.375rem;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  border: 0;
}
.cbs-btn-primary { background: #1d4ed8; color: #fff; }
.cbs-btn-primary:hover { background: #1e40af; }
.cbs-btn-dark { background: #374151; color: #fff; }
.cbs-btn-dark:hover { background: #1f2937; }
</style>

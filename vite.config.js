import path from 'path';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';
import laravel from 'laravel-vite-plugin';

// lang/en.json lists ~3500 phrases that map to themselves (English text is the key) next to ~90 real templates such as
// "Create New {x}". A phrase missing from the messages is shown exactly as written, so the self-mapped ones are dropped
// from the bundle: about 190 KB less JavaScript to download and parse on every first visit.
const slimEnglishMessages = () => ({
  name: 'wims-slim-en-json',
  enforce: 'pre',
  transform(code, id) {
    if (!id.replace(/\\/g, '/').endsWith('/lang/en.json')) return null;
    const all = JSON.parse(code);
    const keep = {};
    Object.keys(all).forEach(k => {
      const selfMapped = all[k] === k && !/[{}|@$]/.test(k);
      if (!selfMapped) keep[k] = all[k];
    });
    return { code: JSON.stringify(keep), map: null };
  },
});

export default defineConfig({
  base: '/',
  resolve: {
    alias: {
      '@base': path.resolve(__dirname, './'),
      '@lang': path.resolve(__dirname, './lang'),
      '@r': path.resolve(__dirname, './resources'),
      '@': path.resolve(__dirname, './resources/js'),
      'ziggy-js': path.resolve('vendor/tightenco/ziggy'),
    },
  },
  build: {
    outDir: './public',
    emptyOutDir: false,
    sourcemap: process.env.NODE_ENV == 'development',
  },
  plugins: [
    slimEnglishMessages(),
    laravel({
      input: 'resources/js/app.js',
      // ssr: 'resources/js/ssr.js',
      refresh: true,
    }),
    vue({
      template: {
        transformAssetUrls: {
          base: null,
          includeAbsolute: false,
        },
      },
    }),
    VitePWA({
      strategies: 'generateSW', // 'generateSW' | 'injectManifest'
      injectRegister: 'auto',
      // 'autoUpdate' instead of 'prompt': during active development this
      // repeatedly caused confusion (a fix would ship, but browsers kept
      // showing the old build until someone noticed and clicked the
      // "new version available" banner). skipWaiting + clientsClaim make
      // a new service worker take over as soon as it finishes installing,
      // and workbox-window reloads the open tab once that happens — so a
      // deployed fix now shows up on next navigation, no manual step.
      registerType: 'autoUpdate',
      workbox: {
        skipWaiting: true,
        clientsClaim: true,
        // outDir is './public' (the whole public web root, not a dedicated
        // build folder), so Workbox's default glob would also sweep up
        // public/vendor/installer's own unrelated JS/CSS bundle (a one-time
        // setup wizard nothing in this app ever links to) into every user's
        // service worker. Scope precaching to the actual build output.
        // NOT precached any more: precaching every one of the ~360 build files made each new visitor (and each deploy)
        // download about 9 MB in the background, which hurts most on a slow line. The hashed build files are instead
        // cached the first time a page needs them (runtimeCaching below), so only what is actually used is downloaded
        // and it is then served from the device.
        globPatterns: ['manifest.webmanifest'],
        globIgnores: ['vendor/**'],
        runtimeCaching: [
          {
            urlPattern: ({ url }) => url.pathname.startsWith('/assets/') && /\.(?:js|css|woff2?|png|svg|jpe?g|webp)$/.test(url.pathname),
            handler: 'CacheFirst',
            options: {
              cacheName: 'wims-build-assets',
              expiration: { maxEntries: 400, maxAgeSeconds: 60 * 60 * 24 * 60 },
              cacheableResponse: { statuses: [200] },
            },
          },
        ],
        // This is a server-rendered Laravel/Inertia app, not a static-shell SPA —
        // every URL is rendered fresh per request with that user's session data.
        // vite-plugin-pwa defaults navigateFallback to 'index.html' (there is no
        // such file here), which made the service worker intercept full-page
        // navigations — including the post-login redirect — and serve back
        // whatever page was last cached, leaking one user's data to the next
        // person who logged in on the same browser. Disabling it forces every
        // navigation to always hit the network.
        navigateFallback: null,
      },
      manifest: {
        start_url: '/dashboard',
        short_name: 'WIMS',
        name: 'Workflow Integrated Management System',
        display: 'standalone',
        orientation: 'portrait-primary',
        background_color: '#ffffff',
        theme_color: '#27447a',
        description:
          'Workflow Integrated Management System (WIMS) v1.6 is a comprehensive enterprise platform that centralizes warehouse management, inventory control, project workflows, approvals, reporting, BOQ compliance, daily reports, document management, and operational processes for RANS Company Limited.',
        icons: [
          { src: '/icons/icon-72x72.png', type: 'image/png', sizes: '72x72' },
          { src: '/icons/icon-96x96.png', type: 'image/png', sizes: '96x96' },
          { src: '/icons/icon-128x128.png', type: 'image/png', sizes: '128x128' },
          { src: '/icons/icon-144x144.png', type: 'image/png', sizes: '144x144' },
          { src: '/icons/icon-152x152.png', type: 'image/png', sizes: '152x152' },
          { src: '/icons/icon-180x180.png', type: 'image/png', sizes: '180x180' },
          { src: '/icons/icon-192x192.png', type: 'image/png', sizes: '192x192' },
          { src: '/icons/icon-384x384.png', type: 'image/png', sizes: '384x384' },
          { src: '/icons/icon-512x512.png', type: 'image/png', sizes: '512x512' },
          { src: '/icons/icon-maskable-192x192.png', type: 'image/png', sizes: '192x192', purpose: 'maskable' },
          { src: '/icons/icon-maskable-512x512.png', type: 'image/png', sizes: '512x512', purpose: 'maskable' },
        ],
        shortcuts: [
          { name: 'Dashboard', url: '/dashboard', icons: [{ src: '/icons/icon-96x96.png', sizes: '96x96' }] },
          { name: 'Inventory', url: '/items', icons: [{ src: '/icons/icon-96x96.png', sizes: '96x96' }] },
          { name: 'Warehouses', url: '/warehouses', icons: [{ src: '/icons/icon-96x96.png', sizes: '96x96' }] },
          { name: 'Check In', url: '/checkins', icons: [{ src: '/icons/icon-96x96.png', sizes: '96x96' }] },
          { name: 'Check Out', url: '/checkouts', icons: [{ src: '/icons/icon-96x96.png', sizes: '96x96' }] },
          { name: 'Transfers', url: '/transfers', icons: [{ src: '/icons/icon-96x96.png', sizes: '96x96' }] },
          { name: 'Issue & Return', url: '/issue-returns/dashboard', icons: [{ src: '/icons/icon-96x96.png', sizes: '96x96' }] },
          { name: 'Daily Reports', url: '/daily-site-reports', icons: [{ src: '/icons/icon-96x96.png', sizes: '96x96' }] },
          { name: 'Reports', url: '/reports', icons: [{ src: '/icons/icon-96x96.png', sizes: '96x96' }] },
          { name: 'Notifications', url: '/dashboard', icons: [{ src: '/icons/icon-96x96.png', sizes: '96x96' }] },
          { name: 'Approvals', url: '/material-requests?status=Pending%20Approval', icons: [{ src: '/icons/icon-96x96.png', sizes: '96x96' }] },
          { name: 'Projects', url: '/my-warehouses', icons: [{ src: '/icons/icon-96x96.png', sizes: '96x96' }] },
          { name: 'System Logs', url: '/activity', icons: [{ src: '/icons/icon-96x96.png', sizes: '96x96' }] },
          { name: 'Settings', url: '/settings', icons: [{ src: '/icons/icon-96x96.png', sizes: '96x96' }] },
        ],
      },
    }),
  ],
});

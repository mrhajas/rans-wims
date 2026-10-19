import axios from 'axios';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
let refreshingWithNewSw = false;

function isLocalDevHost() {
  const host = window.location.hostname;
  // wims.localhost, sicom.localhost ... (the local gateway names) count as local too
  return host === '127.0.0.1' || host === 'localhost' || host.endsWith('.localhost');
}

async function clearLegacyServiceWorkers() {
  if (!('serviceWorker' in navigator)) {
    return;
  }

  const registrations = await navigator.serviceWorker.getRegistrations();
  const staleWorkers = registrations.filter(reg => reg.active?.scriptURL?.includes('/service-worker.js'));

  await Promise.all(staleWorkers.map(reg => reg.unregister()));
}

window.isUpdateAvailable = new Promise(function (resolve, reject) {
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', async function () {
      try {
        // In local development, disable SW caching to avoid stale UI after rebuilds.
        if (isLocalDevHost()) {
          const regs = await navigator.serviceWorker.getRegistrations();
          await Promise.all(regs.map(reg => reg.unregister()));
          resolve(false);
          return;
        }

        await clearLegacyServiceWorkers();

        const reg = await navigator.serviceWorker
          // .register('/service-worker.js')
          .register('/sw.js');

        navigator.serviceWorker.addEventListener('controllerchange', () => {
          if (refreshingWithNewSw) {
            return;
          }

          refreshingWithNewSw = true;
          window.location.reload();
        });

        await reg.update();

        if (reg.waiting) {
          reg.waiting.postMessage({ type: 'SKIP_WAITING' });
        }

        reg.onupdatefound = () => {
          const installingWorker = reg.installing;
          if (!installingWorker) {
            return;
          }

          installingWorker.onstatechange = () => {
            switch (installingWorker.state) {
              case 'installed':
                if (navigator.serviceWorker.controller) {
                  resolve(true);
                  installingWorker.postMessage({ type: 'SKIP_WAITING' });
                } else {
                  resolve(false);
                }
                break;
            }
          };
        };
      } catch (err) {
        window.console.error('[SW ERROR]', err);
        resolve(false);
      }
    });
  } else {
    resolve(false);
  }
});

var deferredPrompt;
window.addEventListener('beforeinstallprompt', function (event) {
  event.preventDefault();
  deferredPrompt = event;
  return false;
});

function addToHomeScreen() {
  if (deferredPrompt) {
    deferredPrompt.prompt();
    deferredPrompt.userChoice.then(function (choiceResult) {
      console.log(choiceResult.outcome);
      if (choiceResult.outcome === 'dismissed') {
        console.log('User canceled installation');
      } else {
        console.log('User added to home screen');
      }
    });
    deferredPrompt = null;
  }
}

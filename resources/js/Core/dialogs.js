import { reactive } from 'vue';

// A single shared reactive state object, read directly by GlobalDialog.vue
// (mounted once, app-wide — see app.js's mountGlobalDialog(), same pattern
// idleLogoutState/IdleLogoutModal.vue already use). confirm()/alert()/
// prompt() below are drop-in async replacements for window.confirm/alert/
// prompt: same call-site shape (just `await` the result), but rendered as
// a real WIMS-styled dialog instead of the browser's own unstyled popup.
export const dialogState = reactive({
  visible: false,
  type: 'confirm', // 'confirm' | 'alert' | 'prompt'
  message: '',
  variant: 'default', // 'default' | 'danger'
  confirmText: '',
  cancelText: '',
  placeholder: '',
  inputValue: '',
  resolver: null,
});

function open(type, message, opts) {
  return new Promise(resolve => {
    dialogState.type = type;
    dialogState.message = message;
    dialogState.variant = opts?.variant || 'default';
    dialogState.confirmText = opts?.confirmText || '';
    dialogState.cancelText = opts?.cancelText || '';
    dialogState.placeholder = opts?.placeholder || '';
    dialogState.inputValue = opts?.defaultValue || '';
    dialogState.resolver = resolve;
    dialogState.visible = true;
  });
}

// Resolves to true/false — same truthiness as `if (window.confirm(...))`.
export function confirmDialog(message, opts) {
  return open('confirm', message, opts);
}

// Resolves once dismissed — nothing to check, same as `window.alert(...)`.
export function alertDialog(message, opts) {
  return open('alert', message, opts);
}

// Resolves to the typed string, or null if cancelled — same as
// `window.prompt(...)`.
export function promptDialog(message, opts) {
  return open('prompt', message, opts);
}

// Called only by GlobalDialog.vue's own button handlers.
export function resolveDialog(value) {
  dialogState.visible = false;
  const resolver = dialogState.resolver;
  dialogState.resolver = null;
  if (resolver) resolver(value);
}

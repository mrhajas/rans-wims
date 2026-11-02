<template>
  <span class="item-label"><span class="item-label-name">{{ name }}</span><span v-if="code" class="item-code"> ({{ code }})</span></span>
</template>

<script>
// An item as it is shown everywhere in WIMS: its name, then its code in brackets, smaller (85%), bold and dark blue:  1 GANG 2 WAY SWITCH SOCKET (RSI-0123).
// The look of the code lives in one place (.item-code in resources/css/app.css), so a page that has its own markup can use the same class.
export default { props: { name: { type: String, default: '' }, code: { type: String, default: '' } } };
</script>

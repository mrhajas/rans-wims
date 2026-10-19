<template>
  <div v-show="message" class="max-w-full wims-field-error" role="alert">
    <p class="text-sm text-red-600 break-words">
      {{ message ? message.replace(' id', '') : '' }}
    </p>
  </div>
</template>

<script>
export default {
  props: ['message'],
};
</script>

<template>
  <div>
    <button
      type="submit"
      v-if="as == 'button'"
      class="block w-full mx-1.5 my-0.5 rounded-lg px-3 py-2 leading-5 text-slate-700 text-left hover:bg-blue-50 hover:text-blue-700 focus:outline-none focus:bg-blue-50 transition duration-150 ease-in-out"
      style="width: calc(100% - 0.75rem)"
    >
      <slot></slot>
    </button>

    <Link
      v-else
      :href="href"
      class="block mx-1.5 my-0.5 rounded-lg px-3 py-2 leading-5 text-slate-700 hover:bg-blue-50 hover:text-blue-700 focus:outline-none focus:bg-blue-50 transition duration-150 ease-in-out"
    >
      <slot></slot>
    </Link>
  </div>
</template>

<script>
export default {
  props: ['href', 'as'],
};
</script>

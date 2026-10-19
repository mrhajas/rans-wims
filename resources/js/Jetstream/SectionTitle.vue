<template>
  <div class="px-4 md:px-0 md:col-span-1">
    <h3 class="text-xl font-extrabold !text-black dark:!text-black tracking-tight">
      <slot name="title"></slot>
    </h3>
    <p class="mt-1 text-sm font-medium !text-black dark:!text-black">
      <slot name="description"></slot>
    </p>
  </div>
</template>

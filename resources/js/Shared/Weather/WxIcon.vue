<template>
  <svg class="wx-glyph" :class="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <template v-if="name === 'clear'">
      <circle cx="12" cy="12" r="4.2" class="sun" fill="currentColor" fill-opacity="0.25" stroke="#f59e0b" />
      <path d="M12 2.5v2.3M12 19.2v2.3M2.5 12h2.3M19.2 12h2.3M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M18.7 5.3l-1.6 1.6M6.9 17.1l-1.6 1.6" stroke="#f59e0b" />
    </template>
    <template v-else-if="name === 'partly'">
      <circle cx="9" cy="9" r="3.2" fill="#f59e0b" fill-opacity="0.25" stroke="#f59e0b" />
      <path d="M9 2.8v1.6M2.8 9h1.6M4.6 4.6l1.1 1.1M13.4 4.6l-1.1 1.1" stroke="#f59e0b" />
      <path d="M8 19h9.2a3.6 3.6 0 0 0 .4-7.2 5 5 0 0 0-9.3 1.4A3 3 0 0 0 8 19z" fill="currentColor" fill-opacity="0.12" />
    </template>
    <template v-else-if="name === 'fog'">
      <path d="M7 14h10.2a3.4 3.4 0 0 0 .3-6.8 4.8 4.8 0 0 0-9 1.4A2.8 2.8 0 0 0 7 14z" fill="currentColor" fill-opacity="0.12" />
      <path d="M4 17.5h16M7 20.5h10" />
    </template>
    <template v-else-if="name === 'drizzle' || name === 'rain' || name === 'showers'">
      <path d="M7 14.5h10.2a3.4 3.4 0 0 0 .3-6.8 4.8 4.8 0 0 0-9 1.4A2.8 2.8 0 0 0 7 14.5z" fill="currentColor" fill-opacity="0.12" />
      <path d="M8.5 17.5l-1 2.2M12.5 17.5l-1 2.2M16.5 17.5l-1 2.2" />
    </template>
    <template v-else-if="name === 'storm'">
      <path d="M7 14h10.2a3.4 3.4 0 0 0 .3-6.8 4.8 4.8 0 0 0-9 1.4A2.8 2.8 0 0 0 7 14z" fill="currentColor" fill-opacity="0.12" />
      <path d="M12.5 13.5l-2.2 3.6h3l-2 3.6" stroke="#f59e0b" />
    </template>
    <template v-else-if="name === 'snow'">
      <path d="M7 14.5h10.2a3.4 3.4 0 0 0 .3-6.8 4.8 4.8 0 0 0-9 1.4A2.8 2.8 0 0 0 7 14.5z" fill="currentColor" fill-opacity="0.12" />
      <path d="M8.5 18v.1M12 19.5v.1M15.5 18v.1" stroke-width="2.4" />
    </template>
    <template v-else-if="name === 'wind'"><path d="M3 9h11a2.5 2.5 0 1 0-2.4-3M3 14h14a2.5 2.5 0 1 1-2.4 3M3 19h6" /></template>
    <template v-else-if="name === 'drop'"><path d="M12 3.5s6 6.2 6 10.3a6 6 0 0 1-12 0C6 9.700 12 3.500 12 3.500z" fill="currentColor" fill-opacity="0.15" /></template>
    <template v-else-if="name === 'thermo'"><path d="M10 14.200V5a2 2 0 1 1 4 0v9.200a4 4 0 1 1-4 0z" fill="currentColor" fill-opacity="0.12" /></template>
    <template v-else>
      <path d="M7 15h10.2a3.4 3.4 0 0 0 .3-6.800 4.800 4.800 0 0 0-9 1.400A2.800 2.800 0 0 0 7 15z" fill="currentColor" fill-opacity="0.12" />
    </template>
  </svg>
</template>

<script>
// A weather glyph for an icon key (clear, partly, cloudy, fog, drizzle, rain, showers, storm, snow, unknown, wind, drop, thermo).
export default { props: { name: { type: String, default: 'unknown' }, size: { type: String, default: '' } } };
</script>

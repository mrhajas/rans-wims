<template>
  <div :class="className ? className : 'h-4 w-4'">
    <svg v-if="name == 'link'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M12.586 4.586a2 2 0 112.828 2.828l-3 3a2 2 0 01-2.828 0 1 1 0 00-1.414 1.414 4 4 0 005.656 0l3-3a4 4 0 00-5.656-5.656l-1.5 1.5a1 1 0 101.414 1.414l1.5-1.5zm-5 5a2 2 0 012.828 0 1 1 0 101.414-1.414 4 4 0 00-5.656 0l-3 3a4 4 0 105.656 5.656l1.5-1.5a1 1 0 10-1.414-1.414l-1.5 1.5a2 2 0 11-2.828-2.828l3-3z"
      />
    </svg>
    <svg v-else-if="name == 'in'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M3 3a1 1 0 011 1v12a1 1 0 11-2 0V4a1 1 0 011-1zm7.707 3.293a1 1 0 010 1.414L9.414 9H17a1 1 0 110 2H9.414l1.293 1.293a1 1 0 01-1.414 1.414l-3-3a1 1 0 010-1.414l3-3a1 1 0 011.414 0z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'out'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M3 3a1 1 0 00-1 1v12a1 1 0 102 0V4a1 1 0 00-1-1zm10.293 9.293a1 1 0 001.414 1.414l3-3a1 1 0 000-1.414l-3-3a1 1 0 10-1.414 1.414L14.586 9H7a1 1 0 100 2h7.586l-1.293 1.293z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'zoom-in'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M5 8a1 1 0 011-1h1V6a1 1 0 012 0v1h1a1 1 0 110 2H9v1a1 1 0 11-2 0V9H6a1 1 0 01-1-1z" />
      <path
        fill-rule="evenodd"
        d="M2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8zm6-4a4 4 0 100 8 4 4 0 000-8z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'zoom-out'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z"
        clip-rule="evenodd"
      />
      <path fill-rule="evenodd" d="M5 8a1 1 0 011-1h4a1 1 0 110 2H6a1 1 0 01-1-1z" clip-rule="evenodd" />
    </svg>
    <svg v-else-if="name == 'home'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"
      />
    </svg>
    <svg v-else-if="name == 'list'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M3 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'cog'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
      <path
        d="M19.14,12.94c0.04,-0.3 0.06,-0.61 0.06,-0.94c0,-0.32 -0.02,-0.64 -0.07,-0.94l2.03,-1.58c0.18,-0.14 0.23,-0.41 0.12,-0.61l-1.92,-3.32c-0.12,-0.22 -0.37,-0.29 -0.59,-0.22l-2.39,0.96c-0.5,-0.38 -1.03,-0.7 -1.62,-0.94L14.4,2.81c-0.04,-0.24 -0.24,-0.41 -0.48,-0.41h-3.84c-0.24,0 -0.43,0.17 -0.47,0.41L9.25,5.35C8.66,5.59 8.12,5.92 7.63,6.29L5.24,5.33c-0.22,-0.08 -0.47,0 -0.59,0.22L2.74,8.87C2.62,9.08 2.66,9.34 2.86,9.48l2.03,1.58C4.84,11.36 4.8,11.69 4.8,12s0.02,0.64 0.07,0.94l-2.03,1.58c-0.18,0.14 -0.23,0.41 -0.12,0.61l1.92,3.32c0.12,0.22 0.37,0.29 0.59,0.22l2.39,-0.96c0.5,0.38 1.03,0.7 1.62,0.94l0.36,2.54c0.05,0.24 0.24,0.41 0.48,0.41h3.84c0.24,0 0.44,-0.17 0.47,-0.41l0.36,-2.54c0.59,-0.24 1.13,-0.56 1.62,-0.94l2.39,0.96c0.22,0.08 0.47,0 0.59,-0.22l1.92,-3.32c0.12,-0.22 0.07,-0.47 -0.12,-0.61L19.14,12.94zM12,15.6c-1.98,0 -3.6,-1.62 -3.6,-3.6s1.62,-3.6 3.6,-3.6s3.6,1.62 3.6,3.6S13.98,15.6 12,15.6z"
      />
    </svg>
    <svg v-else-if="name == 'plus'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" />
    </svg>
    <svg v-else-if="name == 'minus'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path fill-rule="evenodd" d="M3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd" />
    </svg>
    <svg v-else-if="name == 'eye'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M10 12a2 2 0 100-4 2 2 0 000 4z" />
      <path
        fill-rule="evenodd"
        d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'cart'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M3 1a1 1 0 000 2h1.22l.305 1.222a.997.997 0 00.01.042l1.358 5.43-.893.892C3.74 11.846 4.632 14 6.414 14H15a1 1 0 000-2H6.414l1-1H14a1 1 0 00.894-.553l3-6A1 1 0 0017 3H6.28l-.31-1.243A1 1 0 005 1H3zM16 16.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM6.5 18a1.5 1.5 0 100-3 1.5 1.5 0 000 3z"
      />
    </svg>
    <svg v-else-if="name == 'bag'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M10 2a4 4 0 00-4 4v1H5a1 1 0 00-.994.89l-1 9A1 1 0 004 18h12a1 1 0 00.994-1.11l-1-9A1 1 0 0015 7h-1V6a4 4 0 00-4-4zm2 5V6a2 2 0 10-4 0v1h4zm-6 3a1 1 0 112 0 1 1 0 01-2 0zm7-1a1 1 0 100 2 1 1 0 000-2z"
      />
    </svg>
    <svg v-else-if="name == 'cash'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M4 4a2 2 0 00-2 2v1h16V6a2 2 0 00-2-2H4zM18 9H2v5a2 2 0 002 2h12a2 2 0 002-2V9zM4 13a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm5-1a1 1 0 100 2h1a1 1 0 100-2H9z"
      />
    </svg>
    <svg v-else-if="name == 'key'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M10 4a6 6 0 104.473 10.03l2.24 2.24a1 1 0 001.414-1.415l-.997-.996.997-.997a1 1 0 10-1.414-1.414l-.997.997-.864-.864 1.028-1.028a1 1 0 10-1.414-1.414l-1.028 1.028A6 6 0 0010 4zm-4 6a4 4 0 118 0 4 4 0 01-8 0z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'user'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" />
    </svg>
    <svg v-else-if="name == 'users'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"
      />
    </svg>
    <svg v-else-if="name == 'view'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M10 12a2 2 0 100-4 2 2 0 000 4z" />
      <path
        d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z"
      />
    </svg>
    <svg v-else-if="name == 'eye-flat'" class="w-full h-full" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z" />
      <circle cx="12" cy="12" r="3" />
    </svg>
    <svg v-else-if="name == 'eye-off'" class="w-full h-full" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M17.94 17.94A10.94 10.94 0 0112 20c-7 0-11-8-11-8a19.72 19.72 0 015.06-6.18M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a19.86 19.86 0 01-3.22 4.44" />
      <path d="M14.12 14.12a3 3 0 11-4.24-4.24" />
      <path d="M1 1l22 22" />
    </svg>
    <svg v-else-if="name == 'impersonate'" class="w-full h-full" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M6 21v-1a5 5 0 015-5h1" />
      <circle cx="10" cy="7" r="4" />
      <path d="M15 13l5 4-5 4M20 17h-9" />
    </svg>
    <svg v-else-if="name == 'edit'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M17.414 2.586a2 2 0 00-2.828 0L7 10.172V13h2.828l7.586-7.586a2 2 0 000-2.828z" />
      <path d="M2 6a2 2 0 012-2h4a1 1 0 010 2H4v10h10v-4a1 1 0 112 0v4a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" />
    </svg>
    <svg v-else-if="name == 'trash'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z"
      />
    </svg>
    <svg v-else-if="name == 'cross'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
      />
    </svg>
    <svg v-else-if="name == 'x-circle'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'road'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        clip-rule="evenodd"
        d="M7 2L13 2L16.5 18L3.5 18Z M9.3 5L10.7 5L10.7 6.6L9.3 6.6Z M9 9.4L11 9.4L11 11.2L9 11.2Z M8.6 14L11.4 14L11.4 16L8.6 16Z"
      />
    </svg>
    <svg v-else-if="name == 'search-spark'" class="w-full h-full" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="10.5" cy="10.5" r="6.6" />
      <path d="M15.4 15.4 L20.6 20.6" stroke-width="2.6" />
      <path d="M10.5 6.9 L11.5 9.5 L14.1 10.5 L11.5 11.5 L10.5 14.1 L9.5 11.5 L6.9 10.5 L9.5 9.5 Z" fill="currentColor" stroke="none" />
    </svg>
    <svg v-else-if="name == 'meeting-calendar'" class="w-full h-full" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
      <rect x="3" y="4.5" width="18" height="16" rx="3" />
      <path d="M8 2.8v3.4M16 2.8v3.4M3 9.5h18" />
      <circle cx="9.3" cy="13.6" r="1.7" /><path d="M6.2 18.2c.3-1.6 1.5-2.4 3.1-2.4s2.8.8 3.1 2.4" />
      <circle cx="15.6" cy="13.9" r="1.4" /><path d="M13.9 17.6c.4-1.1 1.1-1.6 1.9-1.6s1.6.5 1.9 1.6" />
    </svg>
    <svg v-else-if="name == 'play'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M6.5 4.2v11.6a.8.8 0 001.2.7l9-5.8a.8.8 0 000-1.4l-9-5.8a.8.8 0 00-1.2.7z" /></svg>
    <svg v-else-if="name == 'pause'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><rect x="5" y="4" width="3.6" height="12" rx="1" /><rect x="11.4" y="4" width="3.6" height="12" rx="1" /></svg>
    <svg v-else-if="name == 'mosque'" class="w-full h-full" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
      <path d="M5.5 20v-6.2a6.5 6.5 0 0113 0V20" /><path d="M3 20h18" /><path d="M12 7.3V4.6" /><path d="M10.7 3.6a1.5 1.5 0 102 1.6 1.2 1.2 0 01-2-1.6z" fill="currentColor" stroke-width="1" /><path d="M10 20v-2.6a2 2 0 014 0V20" /><path d="M3 20v-6M21 20v-6" /><path d="M2.4 12.4L3 11l.6 1.4M20.4 12.4L21 11l.6 1.4" stroke-width="1.4" />
    </svg>
    <svg v-else-if="name == 'search'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" />
    </svg>
    <svg v-else-if="name == 'printer'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M5 4v3H4a2 2 0 00-2 2v3a2 2 0 002 2h1v2a2 2 0 002 2h6a2 2 0 002-2v-2h1a2 2 0 002-2V9a2 2 0 00-2-2h-1V4a2 2 0 00-2-2H7a2 2 0 00-2 2zm8 0H7v3h6V4zm0 8H7v4h6v-4z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'refresh'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'menu'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z"
      />
    </svg>
    <svg v-else-if="name == 'adjustments'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M5 4a1 1 0 00-2 0v7.268a2 2 0 000 3.464V16a1 1 0 102 0v-1.268a2 2 0 000-3.464V4zM11 4a1 1 0 10-2 0v1.268a2 2 0 000 3.464V16a1 1 0 102 0V8.732a2 2 0 000-3.464V4zM16 3a1 1 0 011 1v7.268a2 2 0 010 3.464V16a1 1 0 11-2 0v-1.268a2 2 0 010-3.464V4a1 1 0 011-1z"
      />
    </svg>
    <svg v-else-if="name == 'up'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M12 7a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0V8.414l-4.293 4.293a1 1 0 01-1.414 0L8 10.414l-4.293 4.293a1 1 0 01-1.414-1.414l5-5a1 1 0 011.414 0L11 10.586 14.586 7H12z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'down'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M12 13a1 1 0 100 2h5a1 1 0 001-1V9a1 1 0 10-2 0v2.586l-4.293-4.293a1 1 0 00-1.414 0L8 9.586 3.707 5.293a1 1 0 00-1.414 1.414l5 5a1 1 0 001.414 0L11 9.414 14.586 13H12z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'toggle'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M5 12a1 1 0 102 0V6.414l1.293 1.293a1 1 0 001.414-1.414l-3-3a1 1 0 00-1.414 0l-3 3a1 1 0 001.414 1.414L5 6.414V12zM15 8a1 1 0 10-2 0v5.586l-1.293-1.293a1 1 0 00-1.414 1.414l3 3a1 1 0 001.414 0l3-3a1 1 0 00-1.414-1.414L15 13.586V8z"
      />
    </svg>
    <svg v-else-if="name == 'truck'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M8 16.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM15 16.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z" />
      <path
        d="M3 4a1 1 0 00-1 1v10a1 1 0 001 1h1.05a2.5 2.5 0 014.9 0H10a1 1 0 001-1V5a1 1 0 00-1-1H3zM14 7a1 1 0 00-1 1v6.05A2.5 2.5 0 0115.95 16H17a1 1 0 001-1v-5a1 1 0 00-.293-.707l-2-2A1 1 0 0015 7h-1z"
      />
    </svg>
    <!-- A car/motorbike-style vehicle glyph, distinct from the generic
         'truck' used for the wider Transport Routes group — used for
         the Vehicle Register specifically. -->
    <svg v-else-if="name == 'car'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
      <path
        d="M18.92 6.01A1.5 1.5 0 0017.5 5h-11a1.5 1.5 0 00-1.42 1.01L3.16 11.15A2 2 0 002 13v5a1 1 0 001 1h1a1 1 0 001-1v-1h14v1a1 1 0 001 1h1a1 1 0 001-1v-5a2 2 0 00-1.16-1.85l-1.92-5.14zM6.85 7h10.3l1.4 3.75H5.45L6.85 7zM6.5 15.5A1.5 1.5 0 118 14a1.5 1.5 0 01-1.5 1.5zm11 0A1.5 1.5 0 1119 14a1.5 1.5 0 01-1.5 1.5z"
      />
    </svg>
    <svg v-else-if="name == 'email'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" />
      <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" />
    </svg>
    <svg v-else-if="name == 'info'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'folder'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z" />
    </svg>
    <svg v-else-if="name == 'open-folder'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M2 6a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1H8a3 3 0 00-3 3v1.5a1.5 1.5 0 01-3 0V6z" />
      <path d="M6 12a2 2 0 012-2h8a2 2 0 012 2v2a2 2 0 01-2 2H2h2a2 2 0 002-2v-2z" />
    </svg>
    <svg v-else-if="name == 'chevron-up'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z" />
    </svg>
    <svg v-else-if="name == 'chevron-down'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" />
    </svg>
    <svg v-else-if="name == 'chart-bar'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z"
      />
    </svg>
    <svg v-else-if="name == 'chart-pie'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M2 10a8 8 0 018-8v8h8a8 8 0 11-16 0z" />
      <path d="M12 2.252A8.014 8.014 0 0117.748 8H12V2.252z" />
    </svg>
    <svg v-else-if="name == 'bell'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"
      />
    </svg>
    <svg v-else-if="name == 'heart'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M3.172 5.172a4 4 0 015.656 0L10 6.343l1.172-1.171a4 4 0 115.656 5.656L10 17.657l-6.828-6.829a4 4 0 010-5.656z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'group'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-3a5.972 5.972 0 00-.75-2.906A3.005 3.005 0 0119 15v3h-3zM4.75 12.094A5.973 5.973 0 004 15v3H1v-3a3 3 0 013.75-2.906z"
      />
    </svg>
    <svg v-else-if="name == 'collection'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M7 3a1 1 0 000 2h6a1 1 0 100-2H7zM4 7a1 1 0 011-1h10a1 1 0 110 2H5a1 1 0 01-1-1zM2 11a2 2 0 012-2h12a2 2 0 012 2v4a2 2 0 01-2 2H4a2 2 0 01-2-2v-4z"
      />
    </svg>
    <svg v-else-if="name == 'chip'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M13 7H7v6h6V7z" />
      <path
        fill-rule="evenodd"
        d="M7 2a1 1 0 012 0v1h2V2a1 1 0 112 0v1h2a2 2 0 012 2v2h1a1 1 0 110 2h-1v2h1a1 1 0 110 2h-1v2a2 2 0 01-2 2h-2v1a1 1 0 11-2 0v-1H9v1a1 1 0 11-2 0v-1H5a2 2 0 01-2-2v-2H2a1 1 0 110-2h1V9H2a1 1 0 010-2h1V5a2 2 0 012-2h2V2zM5 5h10v10H5V5z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'building'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a1 1 0 110 2h-3a1 1 0 01-1-1v-2a1 1 0 00-1-1H9a1 1 0 00-1 1v2a1 1 0 01-1 1H4a1 1 0 110-2V4zm3 1h2v2H7V5zm2 4H7v2h2V9zm2-4h2v2h-2V5zm2 4h-2v2h2V9z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'map-pin'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M9.69 18.933l.003.001C9.89 19.02 10 19 10 19s.11.02.308-.066l.002-.001.006-.003.018-.008a5.741 5.741 0 00.281-.14c.186-.096.446-.24.757-.433.62-.384 1.445-.966 2.274-1.765C15.302 14.988 17 12.493 17 9A7 7 0 003 9c0 3.492 1.698 5.988 3.355 7.584a13.731 13.731 0 002.273 1.765 11.842 11.842 0 00.976.544l.062.029.018.008.006.003zM10 11.25a2.25 2.25 0 100-4.5 2.25 2.25 0 000 4.5z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'plane'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"
      />
    </svg>
    <svg v-else-if="name == 'upload'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z"
      />
    </svg>
    <svg v-else-if="name == 'clip'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M8 4a3 3 0 00-3 3v4a5 5 0 0010 0V7a1 1 0 112 0v4a7 7 0 11-14 0V7a5 5 0 0110 0v4a3 3 0 11-6 0V7a1 1 0 012 0v4a1 1 0 102 0V7a3 3 0 00-3-3z"
      />
    </svg>
    <svg v-else-if="name == 'duplicate'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M7 9a2 2 0 012-2h6a2 2 0 012 2v6a2 2 0 01-2 2H9a2 2 0 01-2-2V9z" />
      <path d="M5 3a2 2 0 00-2 2v6a2 2 0 002 2V5h8a2 2 0 00-2-2H5z" />
    </svg>
    <svg v-else-if="name == 'doc'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" />
    </svg>
    <svg v-else-if="name == 'doc-text'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z"
      />
    </svg>
    <svg v-else-if="name == 'tick'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <polygon points="0 11 2 9 7 14 18 3 20 5 7 18" />
    </svg>
    <svg v-else-if="name == 'mobile'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path fill-rule="evenodd" d="M7 2a2 2 0 00-2 2v12a2 2 0 002 2h6a2 2 0 002-2V4a2 2 0 00-2-2H7zm3 14a1 1 0 100-2 1 1 0 000 2z" />
    </svg>
    <svg v-else-if="name == 'globe'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path fill-rule="evenodd" d="M4.083 9h1.946c.089-1.546.383-2.97.837-4.118A6.004 6.004 0 004.083 9zM10 2a8 8 0 100 16 8 8 0 000-16zm0 2c-.076 0-.232.032-.465.262-.238.234-.497.623-.737 1.182-.389.907-.673 2.142-.766 3.556h3.936c-.093-1.414-.377-2.649-.766-3.556-.24-.56-.5-.948-.737-1.182C10.232 4.032 10.076 4 10 4zm3.971 5c-.089-1.546-.383-2.97-.837-4.118A6.004 6.004 0 0115.917 9h-1.946zm-2.003 2H8.032c.093 1.414.377 2.649.766 3.556.24.56.5.948.737 1.182.233.23.389.262.465.262.076 0 .232-.032.465-.262.238-.234.498-.623.737-1.182.389-.907.673-2.142.766-3.556zm1.166 4.118c.454-1.147.748-2.572.837-4.118h1.946a6.004 6.004 0 01-2.783 4.118zm-6.268 0C6.412 13.97 6.118 12.546 6.03 11H4.083a6.004 6.004 0 002.783 4.118z" clip-rule="evenodd" />
    </svg>
    <svg v-else-if="name == 'desktop-computer'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path fill-rule="evenodd" d="M3 5a2 2 0 012-2h10a2 2 0 012 2v8a2 2 0 01-2 2h-2.22l.123.489.804.804A1 1 0 0113 18H7a1 1 0 01-.707-1.707l.804-.804L7.22 15H5a2 2 0 01-2-2V5zm5.771 7H5V5h10v7H8.771z" clip-rule="evenodd" />
    </svg>
    <svg v-else-if="name == 'at'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M14.243 5.757a6 6 0 10-.986 9.284 1 1 0 111.087 1.678A8 8 0 1118 10a3 3 0 01-4.8 2.401A4 4 0 1114 10a1 1 0 102 0c0-1.537-.586-3.07-1.757-4.243zM12 10a2 2 0 10-4 0 2 2 0 004 0z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'otp'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 448">
      <path d="m128 416c0 4.417969-3.582031 8-8 8s-8-3.582031-8-8 3.582031-8 8-8 8 3.582031 8 8zm0 0" />
      <path
        d="m240 224h-144v-128h144v-72c0-13.253906-10.746094-24-24-24h-16v8c0 13.253906-10.746094 24-24 24h-112c-13.253906 0-24-10.746094-24-24v-8h-16c-13.253906 0-24 10.746094-24 24v400c0 13.253906 10.746094 24 24 24h192c13.253906 0 24-10.746094 24-24zm-120 216c-13.253906 0-24-10.746094-24-24s10.746094-24 24-24 24 10.746094 24 24-10.746094 24-24 24zm104-56h-208v-16h208zm0 0"
      />
      <path d="m64 16h112c4.417969 0 8-3.582031 8-8v-8h-128v8c0 4.417969 3.582031 8 8 8zm0 0" />
      <path
        d="m448 112h-336v96h336zm-256.289062 57.070312-8 13.859376-15.710938-9.074219v18.144531h-16v-18.144531l-15.710938 9.074219-8-13.859376 15.710938-9.070312-15.710938-9.070312 8-13.859376 15.710938 9.074219v-18.144531h16v18.144531l15.710938-9.074219 8 13.859376-15.710938 9.070312zm80 0-8 13.859376-15.710938-9.074219v18.144531h-16v-18.144531l-15.710938 9.074219-8-13.859376 15.710938-9.070312-15.710938-9.070312 8-13.859376 15.710938 9.074219v-18.144531h16v18.144531l15.710938-9.074219 8 13.859376-15.710938 9.070312zm80 0-8 13.859376-15.710938-9.074219v18.144531h-16v-18.144531l-15.710938 9.074219-8-13.859376 15.710938-9.070312-15.710938-9.070312 8-13.859376 15.710938 9.074219v-18.144531h16v18.144531l15.710938-9.074219 8 13.859376-15.710938 9.070312zm80 0-8 13.859376-15.710938-9.074219v18.144531h-16v-18.144531l-15.710938 9.074219-8-13.859376 15.710938-9.070312-15.710938-9.070312 8-13.859376 15.710938 9.074219v-18.144531h16v18.144531l15.710938-9.074219 8 13.859376-15.710938 9.070312zm0 0"
      />
    </svg>
    <svg v-else-if="name == 'download'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd" />
    </svg>
    <svg v-else-if="name == 'lock-closed'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M6 8V7a4 4 0 118 0v1h1a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2h1zm2 0h4V7a2 2 0 10-4 0v1z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'lock-open'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M6 8V7a4 4 0 117.47-2.003 1 1 0 11-1.84.782A2 2 0 008 7v1h7a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2h1z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'exclamation'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'clock'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'calendar'" class="w-full h-full" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <rect x="3" y="5" width="18" height="16" rx="2" />
      <line x1="3" y1="10" x2="21" y2="10" />
      <line x1="8" y1="3" x2="8" y2="7" />
      <line x1="16" y1="3" x2="16" y2="7" />
      <circle cx="8" cy="14.5" r="1" fill="currentColor" stroke="none" />
      <circle cx="12" cy="14.5" r="1" fill="currentColor" stroke="none" />
      <circle cx="16" cy="14.5" r="1" fill="currentColor" stroke="none" />
    </svg>
    <svg v-else-if="name == 'media'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M3 5.5A2.5 2.5 0 015.5 3h6A2.5 2.5 0 0114 5.5V6h-1.5v-.5a1 1 0 00-1-1h-6a1 1 0 00-1 1v5a1 1 0 001 1H6V13h-.5A2.5 2.5 0 013 10.5v-5z" />
      <path fill-rule="evenodd" d="M7 8.5A2.5 2.5 0 019.5 6h5A2.5 2.5 0 0117 8.5v5a2.5 2.5 0 01-2.5 2.5h-5A2.5 2.5 0 017 13.5v-5zm4 1a.5.5 0 00-.76-.43l-1.7 1.04a.5.5 0 000 .86l1.7 1.04a.5.5 0 00.76-.43V9.5z" clip-rule="evenodd" />
    </svg>
    <svg v-else-if="name == 'qr-code'" class="w-full h-full" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M4 8V6a2 2 0 012-2h2M16 4h2a2 2 0 012 2v2M20 16v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2" />
      <rect x="7.5" y="7.5" width="3" height="3" rx="0.5" /><rect x="13.5" y="7.5" width="3" height="3" rx="0.5" /><rect x="7.5" y="13.5" width="3" height="3" rx="0.5" />
      <path d="M13.5 13.5h1.5v1.5M16.5 16.5V15M13.5 16.5h.01" />
    </svg>
    <svg v-else-if="name == 'camera'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M1 8a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 018.07 3h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0016.07 6H17a2 2 0 012 2v7a2 2 0 01-2 2H3a2 2 0 01-2-2V8zm13.5 3a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM10 14a3 3 0 100-6 3 3 0 000 6z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'video'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M3.25 4A2.25 2.25 0 001 6.25v7.5A2.25 2.25 0 003.25 16h7.5A2.25 2.25 0 0013 13.75v-7.5A2.25 2.25 0 0010.75 4h-7.5zM19 4.75a.75.75 0 00-1.28-.53l-3 3a.75.75 0 00-.22.53v4.5c0 .199.079.39.22.53l3 3a.75.75 0 001.28-.53V4.75z" />
    </svg>
    <svg v-else-if="name == 'image'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'filter'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M3 3a1 1 0 011-1h12a1 1 0 01.8 1.6L12 10.5V16a1 1 0 01-.5.87l-3 1.5A1 1 0 017 17.5v-7L3.2 4.6A1 1 0 013 3z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'table'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M3 4a1 1 0 011-1h12a1 1 0 011 1v12a1 1 0 01-1 1H4a1 1 0 01-1-1V4zm2 1v3h3V5H5zm5 0v3h5V5h-5zm-5 5v3h3v-3H5zm5 0v3h5v-3h-5z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'calculator'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M4 3a1 1 0 011-1h10a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V3zm2 1v3h8V4H6zm0 5v1.5h2V9H6zm3 0v1.5h2V9H9zm3 0v1.5h2V9h-2zM6 12v1.5h2V12H6zm3 0v1.5h2V12H9zm3 0v4.5h2V12h-2zM6 15v1.5h2V15H6zm3 0v1.5h2V15H9z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'cloud'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M5.5 16A3.5 3.5 0 015.12 9.02 5 5 0 0114.9 8.2 3.75 3.75 0 0114.25 16H5.5z" />
    </svg>
    <svg v-else-if="name == 'sparkles'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M5 2a1 1 0 011 1v1h1a1 1 0 110 2H6v1a1 1 0 11-2 0V6H3a1 1 0 110-2h1V3a1 1 0 011-1zm0 12a1 1 0 011 1v1h1a1 1 0 110 2H6v1a1 1 0 11-2 0v-1H3a1 1 0 110-2h1v-1a1 1 0 011-1zM12 2a1 1 0 01.967.744L14.146 7.2 17.9 8.033a1 1 0 010 1.934l-3.754.833-1.179 4.456a1 1 0 01-1.934 0L9.854 10.8 6.1 9.967a1 1 0 010-1.934l3.754-.833 1.179-4.456A1 1 0 0112 2z"
      />
    </svg>
    <svg v-else-if="name == 'microphone'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M7 4a3 3 0 016 0v6a3 3 0 11-6 0V4z" />
      <path
        d="M5.5 9.643a.75.75 0 00-1.5 0V10c0 3.06 2.29 5.585 5.25 5.954V17.5h-1.5a.75.75 0 000 1.5h4.5a.75.75 0 000-1.5h-1.5v-1.546A6.001 6.001 0 0016 10v-.357a.75.75 0 00-1.5 0V10a4.5 4.5 0 01-9 0v-.357z"
      />
    </svg>
    <svg v-else-if="name == 'send'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M3.105 2.289a.75.75 0 00-.826.95l1.414 4.925A1.5 1.5 0 005.135 9.25h6.115a.75.75 0 010 1.5H5.135a1.5 1.5 0 00-1.442 1.086l-1.414 4.926a.75.75 0 00.826.95 28.896 28.896 0 0015.293-7.154.75.75 0 000-1.115A28.897 28.897 0 003.105 2.289z"
      />
    </svg>
    <svg v-else-if="name == 'hash'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M7.65 2.043a.75.75 0 01.531.918L7.585 6h4.83l.669-2.539a.75.75 0 011.451.386L14.021 6H16.5a.75.75 0 010 1.5h-2.85l-1.05 4h2.9a.75.75 0 010 1.5h-3.271l-.68 2.582a.75.75 0 01-1.451-.386L10.729 13H5.898l-.68 2.582a.75.75 0 01-1.451-.386L4.398 13H1.5a.75.75 0 010-1.5h2.529l1.05-4H2.25a.75.75 0 010-1.5h2.958l.68-2.582a.75.75 0 01.918-.531l.844.225zM6.16 7.5l-1.05 4h4.831l1.05-4H6.16z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'wifi'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <rect x="2" y="12" width="3" height="6" rx="1" />
      <rect x="8.5" y="8" width="3" height="10" rx="1" />
      <rect x="15" y="3" width="3" height="15" rx="1" />
    </svg>
    <svg v-else-if="name == 'whatsapp'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M10 2a8 8 0 00-6.93 12.02L2 18l4.11-1.05A8 8 0 1010 2zm4.64 11.3c-.2.55-1.15 1.05-1.6 1.11-.41.06-.92.09-1.49-.09-.34-.11-.79-.26-1.36-.5-2.39-1.03-3.95-3.44-4.07-3.6-.12-.16-.97-1.29-.97-2.46s.61-1.74.83-1.98c.22-.24.48-.3.64-.3.16 0 .32 0 .46.01.15.01.35-.06.55.42.2.49.68 1.68.74 1.8.06.12.1.27.02.43-.08.16-.12.26-.24.4-.12.14-.25.31-.36.42-.12.12-.24.25-.11.49.14.24.6 1 1.29 1.62.89.8 1.63 1.05 1.87 1.17.24.12.38.1.53-.06.15-.16.62-.72.79-.97.16-.24.33-.2.55-.12.22.08 1.41.67 1.66.79.24.12.4.18.46.28.06.11.06.61-.15 1.16z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'meta'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M5.6 4C3.06 4 1 6.7 1 10.02s2.06 6.02 4.6 6.02c1.5 0 2.83-.94 3.9-2.42 1.07 1.48 2.4 2.42 3.9 2.42 2.54 0 4.6-2.7 4.6-6.02S16.94 4 14.4 4c-1.5 0-2.83.94-3.9 2.42C9.43 4.94 8.1 4 6.6 4h-1zm.5 2.3c1 0 1.95.85 2.7 2.18-.75 1.87-1.7 3.24-2.7 3.24-1.16 0-2.1-1.66-2.1-3.71S4.94 6.3 6.1 6.3zm7.8 0c1.16 0 2.1 1.66 2.1 3.71s-.94 3.71-2.1 3.71c-1 0-1.95-1.37-2.7-3.24.75-1.33 1.7-2.18 2.7-2.18z"
      />
    </svg>
    <svg v-else-if="name == 'sms'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        fill-rule="evenodd"
        d="M7 2a2 2 0 00-2 2v12a2 2 0 002 2h6a2 2 0 002-2V4a2 2 0 00-2-2H7zm.5 2h5a.5.5 0 010 1h-5a.5.5 0 010-1zM10 16a1 1 0 110-2 1 1 0 010 2z"
        clip-rule="evenodd"
      />
    </svg>
    <svg v-else-if="name == 'chat'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M3.505 2.365A41.369 41.369 0 019 2c1.863 0 3.697.124 5.495.365 1.247.167 2.18 1.108 2.435 2.268a4.45 4.45 0 00-.577-.069 43.141 43.141 0 00-4.706 0C9.229 4.696 7.5 6.727 7.5 8.998v2.24c0 1.413.67 2.735 1.76 3.562l-2.98 2.98A.75.75 0 015 17.25v-3.443c-.501-.048-1-.106-1.495-.172C2.033 13.438 1 12.162 1 10.72V5.28c0-1.441 1.033-2.717 2.505-2.914z" />
      <path d="M14 6c-.762 0-1.52.02-2.271.062C10.157 6.148 9 7.472 9 8.998v2.24c0 1.519 1.147 2.839 2.71 2.935.214.013.428.024.642.034.2.009.385.09.518.224l2.35 2.35a.75.75 0 001.28-.531v-2.07c1.453-.195 2.5-1.463 2.5-2.915V8.998c0-1.526-1.157-2.85-2.729-2.936A41.645 41.645 0 0014 6z" />
    </svg>
    <svg v-else-if="name == 'smile'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path fill-rule="evenodd" clip-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.536-4.464a.75.75 0 10-1.061-1.061 3.5 3.5 0 01-4.95 0 .75.75 0 00-1.06 1.06 5 5 0 007.07 0zM9 8.5c0 .828-.448 1.5-1 1.5s-1-.672-1-1.5S7.448 7 8 7s1 .672 1 1.5zm3 1.5c.552 0 1-.672 1-1.5S12.552 7 12 7s-1 .672-1 1.5.448 1.5 1 1.5z" />
    </svg>
    <svg v-else-if="name == 'pin'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M12.9 2.3a1 1 0 011.4 0l3.4 3.4a1 1 0 010 1.4l-1.1 1.1a1 1 0 01-1.2.2l-1.6-.8-3.3 3.3a4.5 4.5 0 01-.3 2.9 1 1 0 01-1.6.3L8 13.6l-3.6 3.6a.75.75 0 01-1.1-1.1L6.9 12.5 4.4 10a1 1 0 01.3-1.6 4.5 4.5 0 012.9-.3l3.3-3.3-.8-1.6a1 1 0 01.2-1.2z" />
    </svg>
    <svg v-else-if="name == 'reply'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path fill-rule="evenodd" clip-rule="evenodd" d="M7.793 2.232a.75.75 0 01-.025 1.06L3.622 7.25h10.003a5.375 5.375 0 010 10.75H10.75a.75.75 0 010-1.5h2.875a3.875 3.875 0 000-7.75H3.622l4.146 3.957a.75.75 0 01-1.036 1.085l-5.5-5.25a.75.75 0 010-1.085l5.5-5.25a.75.75 0 011.06.025z" />
    </svg>
    <svg v-else-if="name == 'forward'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <g transform="matrix(-1 0 0 1 20 0)"><path fill-rule="evenodd" clip-rule="evenodd" d="M7.793 2.232a.75.75 0 01-.025 1.06L3.622 7.25h10.003a5.375 5.375 0 010 10.75H10.75a.75.75 0 010-1.5h2.875a3.875 3.875 0 000-7.75H3.622l4.146 3.957a.75.75 0 01-1.036 1.085l-5.5-5.25a.75.75 0 010-1.085l5.5-5.25a.75.75 0 011.06.025z" /></g>
    </svg>
    <svg v-else-if="name == 'more'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M3 10a1.5 1.5 0 113 0 1.5 1.5 0 01-3 0zM8.5 10a1.5 1.5 0 113 0 1.5 1.5 0 01-3 0zM15.5 8.5a1.5 1.5 0 100 3 1.5 1.5 0 000-3z" />
    </svg>
    <svg v-else-if="name == 'check-double'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M1.7 10.4l3.2 3.2 6.8-6.8-1.2-1.2-5.6 5.6-2-2z" />
      <path d="M9.3 12.6l1.2 1.2 7.8-7.8-1.2-1.2z" />
    </svg>
    <svg v-else-if="name == 'arrow-left'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path fill-rule="evenodd" clip-rule="evenodd" d="M17 10a.75.75 0 01-.75.75H5.612l4.158 3.96a.75.75 0 11-1.04 1.08l-5.5-5.25a.75.75 0 010-1.08l5.5-5.25a.75.75 0 111.04 1.08L5.612 9.25H16.25A.75.75 0 0117 10z" />
    </svg>
    <svg v-else-if="name == 'bell-off'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M4 8a6 6 0 019-5.2L4.3 11.5A6 6 0 014 8zM16 8c0 2.5.5 4 1.5 5.2.3.4 0 .8-.5.8H8.4L16 6.9V8zM2.3 2.3a.75.75 0 011.06 0l14.35 14.35a.75.75 0 11-1.06 1.06L2.3 3.36a.75.75 0 010-1.06zM8 16.5a2 2 0 004 0H8z" />
    </svg>
    <svg v-else-if="name == 'archive-box'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M2 3a1 1 0 00-1 1v1a1 1 0 001 1h16a1 1 0 001-1V4a1 1 0 00-1-1H2z" />
      <path fill-rule="evenodd" clip-rule="evenodd" d="M2 7.5h16l-.811 7.71a2 2 0 01-1.99 1.79H4.802a2 2 0 01-1.99-1.79L2 7.5zM7 11a1 1 0 011-1h4a1 1 0 110 2H8a1 1 0 01-1-1z" />
    </svg>
    <svg v-else-if="name == 'star'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M10.868 2.884c-.321-.772-1.415-.772-1.736 0l-1.83 4.401-4.753.381c-.833.067-1.171 1.107-.536 1.651l3.62 3.102-1.106 4.637c-.194.813.691 1.456 1.405 1.02L10 15.191l4.064 2.485c.714.436 1.598-.207 1.404-1.02l-1.106-4.637 3.62-3.102c.635-.544.297-1.584-.536-1.65l-4.752-.382-1.831-4.401z" />
    </svg>
    <svg v-else-if="name == 'identification'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path fill-rule="evenodd" clip-rule="evenodd" d="M10 2a1 1 0 00-1 1v1a1 1 0 002 0V3a1 1 0 00-1-1zM4 4h3a3 3 0 006 0h3a2 2 0 012 2v9a2 2 0 01-2 2H4a2 2 0 01-2-2V6a2 2 0 012-2zm2.5 7a1.5 1.5 0 100-3 1.5 1.5 0 000 3zm2.45 4a2.5 2.5 0 10-4.9 0h4.9zM12 9a1 1 0 100 2h3a1 1 0 100-2h-3zm-1 4a1 1 0 011-1h2a1 1 0 110 2h-2a1 1 0 01-1-1z" />
    </svg>
    <svg v-else-if="name == 'gift'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path fill-rule="evenodd" clip-rule="evenodd" d="M5 5a3 3 0 015-2.236A3 3 0 0114.83 6H16a2 2 0 110 4h-5V9a1 1 0 10-2 0v1H4a2 2 0 110-4h1.17C5.06 5.687 5 5.35 5 5zm4 1V5a1 1 0 10-1 1h1zm3 0a1 1 0 10-1-1v1h1z" />
      <path d="M9 11H3v5a2 2 0 002 2h4v-7zM11 18h4a2 2 0 002-2v-5h-6v7z" />
    </svg>
    <svg v-else-if="name == 'briefcase'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path fill-rule="evenodd" clip-rule="evenodd" d="M6 6V5a3 3 0 013-3h2a3 3 0 013 3v1h2a2 2 0 012 2v3.57A22.952 22.952 0 0110 13a22.95 22.95 0 01-8-1.43V8a2 2 0 012-2h2zm2-1a1 1 0 011-1h2a1 1 0 011 1v1H8V5zm1 5a1 1 0 011-1h.01a1 1 0 110 2H10a1 1 0 01-1-1z" />
      <path d="M2 13.692V16a2 2 0 002 2h12a2 2 0 002-2v-2.308A24.974 24.974 0 0110 15c-2.796 0-5.487-.46-8-1.308z" />
    </svg>
    <svg v-else-if="name == 'scale'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path fill-rule="evenodd" clip-rule="evenodd" d="M10 2a1 1 0 011 1v1.323l3.954 1.582 1.599-.8a1 1 0 01.894 1.79l-1.233.616 1.738 5.42a1 1 0 01-.285 1.05A3.989 3.989 0 0115 15a3.989 3.989 0 01-2.667-1.019 1 1 0 01-.285-1.05l1.715-5.349L11 6.477V16h2a1 1 0 110 2H7a1 1 0 110-2h2V6.477L6.237 7.582l1.715 5.349a1 1 0 01-.285 1.05A3.989 3.989 0 015 15a3.989 3.989 0 01-2.667-1.019 1 1 0 01-.285-1.05l1.738-5.42-1.233-.617a1 1 0 01.894-1.788l1.599.799L9 4.323V3a1 1 0 011-1zm-5 8.274l-.818 2.552c.25.112.526.174.818.174.292 0 .569-.062.818-.174L5 10.274zm10 0l-.818 2.552c.25.112.526.174.818.174.292 0 .569-.062.818-.174L15 10.274z" />
    </svg>
    <svg v-else-if="name == 'wrench'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
      <path d="M22.7 19l-9.1-9.1c.9-2.3.4-5-1.5-6.9-2-2-5-2.4-7.4-1.3L9 6 6 9 1.6 4.7C.4 7.1.9 10.1 2.9 12.1c1.9 1.9 4.6 2.4 6.9 1.5l9.1 9.1c.4.4 1 .4 1.4 0l2.3-2.3c.5-.4.5-1.1.1-1.4z" />
    </svg>
    <svg v-else-if="name == 'shield-check'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path fill-rule="evenodd" clip-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" />
    </svg>
    <svg v-else-if="name == 'lightning-bolt'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path fill-rule="evenodd" clip-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" />
    </svg>
    <svg v-else-if="name == 'bookmark'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M5 4a2 2 0 012-2h6a2 2 0 012 2v14l-5-2.5L5 18V4z" />
    </svg>
    <svg v-else-if="name == 'user-circle'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path fill-rule="evenodd" clip-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-6-3a2 2 0 11-4 0 2 2 0 014 0zm-2 4a5 5 0 00-4.546 2.916A5.986 5.986 0 0010 16a5.986 5.986 0 004.546-2.084A5 5 0 0010 11z" />
    </svg>
    <svg v-else-if="name == 'trending-up'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path fill-rule="evenodd" clip-rule="evenodd" d="M12 7a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0V8.414l-4.293 4.293a1 1 0 01-1.414 0L8 10.414l-4.293 4.293a1 1 0 01-1.414-1.414l5-5a1 1 0 011.414 0L11 10.586 14.586 7H12z" />
    </svg>
    <svg v-else-if="name == 'database'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M3 12v3c0 1.657 3.134 3 7 3s7-1.343 7-3v-3c0 1.657-3.134 3-7 3s-7-1.343-7-3z" />
      <path d="M3 7v3c0 1.657 3.134 3 7 3s7-1.343 7-3V7c0 1.657-3.134 3-7 3S3 8.657 3 7z" />
      <path d="M17 5c0 1.657-3.134 3-7 3S3 6.657 3 5s3.134-3 7-3 7 1.343 7 3z" />
    </svg>
    <svg v-else-if="name == 'speakerphone'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path fill-rule="evenodd" clip-rule="evenodd" d="M18 3a1 1 0 00-1.447-.894L8.763 6H5a3 3 0 000 6h.28l1.771 5.316A1 1 0 008 18h1a1 1 0 001-1v-4.382l6.553 3.276A1 1 0 0018 15V3z" />
    </svg>
    <svg v-else-if="name == 'light-bulb'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path d="M11 3a1 1 0 10-2 0v1a1 1 0 102 0V3zM15.657 5.757a1 1 0 00-1.414-1.414l-.707.707a1 1 0 001.414 1.414l.707-.707zM18 10a1 1 0 01-1 1h-1a1 1 0 110-2h1a1 1 0 011 1zM5.05 6.464A1 1 0 106.464 5.05l-.707-.707a1 1 0 00-1.414 1.414l.707.707zM5 10a1 1 0 01-1 1H3a1 1 0 110-2h1a1 1 0 011 1zM8 16v-1h4v1a2 2 0 11-4 0zM12 14c.015-.34.208-.646.477-.859a4 4 0 10-4.954 0c.27.213.462.519.476.859h4.002z" />
    </svg>
    <svg v-else-if="name == 'terminal'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path fill-rule="evenodd" clip-rule="evenodd" d="M2 5a2 2 0 012-2h12a2 2 0 012 2v10a2 2 0 01-2 2H4a2 2 0 01-2-2V5zm3.293 1.293a1 1 0 011.414 0l3 3a1 1 0 010 1.414l-3 3a1 1 0 01-1.414-1.414L7.586 10 5.293 7.707a1 1 0 010-1.414zM11 12a1 1 0 100 2h3a1 1 0 100-2h-3z" />
    </svg>
    <svg v-else-if="name == 'server'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path fill-rule="evenodd" clip-rule="evenodd" d="M2 5a2 2 0 012-2h12a2 2 0 012 2v2a2 2 0 01-2 2H4a2 2 0 01-2-2V5zm14 1a1 1 0 11-2 0 1 1 0 012 0zM2 13a2 2 0 012-2h12a2 2 0 012 2v2a2 2 0 01-2 2H4a2 2 0 01-2-2v-2zm14 1a1 1 0 11-2 0 1 1 0 012 0z" />
    </svg>
    <svg v-else-if="name == 'wallet'" class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
      <path d="M21 18v1c0 1.1-.9 2-2 2H5c-1.11 0-2-.9-2-2V5c0-1.1.89-2 2-2h14c1.1 0 2 .9 2 2v1h-9c-1.11 0-2 .9-2 2v8c0 1.1.89 2 2 2h9zm-9-2h10V8H12v8zm4-2.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z" />
    </svg>
    <svg v-else class="w-full h-full fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
      <path
        d="M10 3.5a1.5 1.5 0 013 0V4a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-.5a1.5 1.5 0 000 3h.5a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-.5a1.5 1.5 0 00-3 0v.5a1 1 0 01-1 1H6a1 1 0 01-1-1v-3a1 1 0 00-1-1h-.5a1.5 1.5 0 010-3H4a1 1 0 001-1V6a1 1 0 011-1h3a1 1 0 001-1v-.5z"
      />
    </svg>
  </div>
</template>

<script>
export default {
  props: ['name', 'className'],
};
</script>

import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
    './vendor/laravel/jetstream/**/*.blade.php',
    './storage/framework/views/*.php',
    './resources/views/**/*.blade.php',
    './resources/js/**/*.vue',
    './resources/js/**/*.js',
  ],
  // Jetstream/Dropdown.vue builds its `sm:w-*` class as a runtime template
  // literal (`sm:${this.widthClass}`) for centerOnMobile dropdowns — Tailwind
  // can't statically see that, so every width option that component supports
  // needs its `sm:` variant listed here explicitly, or it silently falls
  // back to whatever `sm:w-*` class happens to already exist elsewhere in
  // the codebase (which is exactly how this went unnoticed before: w-64 and
  // w-72 both appear literally in other pages already).
  safelist: ['sm:w-40', 'sm:w-48', 'sm:w-56', 'sm:w-64', 'sm:w-72', 'sm:w-80'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Nunito', ...defaultTheme.fontFamily.sans],
      },
      screens: {
        // Dedicated breakpoint for the main nav/sidebar: tablets (768-1024px) get the
        // off-canvas drawer too, only switching to the static desktop sidebar at 1024px+.
        // Kept separate from `lg` so this decision doesn't ripple into unrelated `lg:` usage.
        nav: '1024px',
      },
    },
  },
  plugins: [forms, typography],
};

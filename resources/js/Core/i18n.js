import en from '@lang/en.json';
import { createI18n } from 'vue-i18n';
import languages from '@lang/languages.json';

const messages = { en };
export const LANGUAGES = languages.available;
export const SUPPORT_LOCALES = languages.available.map(l => l.value).filter(l => l != 'en');

const i18n = createI18n({
  messages,
  legacy: false,
  // English text is its own key: a key without a translation simply shows as written, so a missing key needs no
  // handling (the old handler wrote two console lines on EVERY render of EVERY untranslated string).
  missingWarn: false,
  mode: 'composition',
  fallbackWarn: false,
  fallbackLocale: 'en',
  warnHtmlMessage: false,
  locale: typeof window === 'undefined' ? 'en' : window.Locale || 'en',
});

export default i18n;

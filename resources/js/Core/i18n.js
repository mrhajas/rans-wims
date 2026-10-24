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

// Only the language the person uses is downloaded (English is always there): the others are fetched the moment somebody switches to them.
const loaded = new Set(['en']);
export async function loadLocale(lang) {
  if (!lang || loaded.has(lang) || !SUPPORT_LOCALES.includes(lang)) return;
  let messages = await import(`../../../lang/${lang}.json`);
  messages = JSON.parse(JSON.stringify(messages));
  messages = { ...messages, ...messages?.default, default: 'default' };
  i18n.global.setLocaleMessage(lang, messages);
  loaded.add(lang);
}

export default i18n;

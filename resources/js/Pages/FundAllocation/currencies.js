// The 15 most used currencies come first (also shown as one-tap shortcuts); the rest are searchable.
export const TOP_CURRENCIES = [
  ['TZS', 'Tanzanian Shilling'], ['USD', 'US Dollar'], ['EUR', 'Euro'], ['GBP', 'British Pound'], ['KES', 'Kenyan Shilling'],
  ['UGX', 'Ugandan Shilling'], ['AED', 'UAE Dirham'], ['CNY', 'Chinese Yuan'], ['INR', 'Indian Rupee'], ['ZAR', 'South African Rand'],
  ['SAR', 'Saudi Riyal'], ['JPY', 'Japanese Yen'], ['CHF', 'Swiss Franc'], ['CAD', 'Canadian Dollar'], ['AUD', 'Australian Dollar'],
];

export const OTHER_CURRENCIES = [
  ['RWF', 'Rwandan Franc'], ['BIF', 'Burundian Franc'], ['MZN', 'Mozambican Metical'], ['ZMW', 'Zambian Kwacha'], ['ETB', 'Ethiopian Birr'],
  ['EGP', 'Egyptian Pound'], ['NGN', 'Nigerian Naira'], ['OMR', 'Omani Rial'], ['QAR', 'Qatari Riyal'], ['KWD', 'Kuwaiti Dinar'],
  ['TRY', 'Turkish Lira'], ['SEK', 'Swedish Krona'], ['NOK', 'Norwegian Krone'], ['DKK', 'Danish Krone'], ['SGD', 'Singapore Dollar'],
  ['HKD', 'Hong Kong Dollar'], ['MYR', 'Malaysian Ringgit'], ['THB', 'Thai Baht'], ['PKR', 'Pakistani Rupee'], ['BRL', 'Brazilian Real'],
];

export const CURRENCY_OPTIONS = [...TOP_CURRENCIES, ...OTHER_CURRENCIES].map(([code, name]) => ({ id: code, label: code + ' - ' + name }));

import { offlineState } from '@/Core/offlineStatus';

const mixin = {
  computed: {
    $user() {
      return this.$page.props.auth.user;
    },
    $connection() {
      return offlineState;
    },
    // Super Admin (via $can()'s own unconditional override) or anyone
    // explicitly granted bypass-offline-restrictions can keep performing
    // otherwise-offline-blocked actions (e.g. Clock In/Out) — everyone else
    // stays blocked while the connection is down.
    $offlineBlocked() {
      return offlineState.status === 'offline' && !this.$can('bypass-offline-restrictions');
    },
    $super() {
      return this.$page.props.auth.user.roles.find(r => r.name == 'Super Admin') ? true : false;
    },
    $admin() {
      return this.$page.props.auth.user.roles.find(r => r.name == 'Super Admin' || r.name == 'Admin') ? true : false;
    },
    $settings() {
      return { ...this.$page.props.settings, track_weight: this.$page.props.settings.track_weight == 1 };
    },
  },
  methods: {
    $capitalize(string) {
      return string.charAt(0).toUpperCase() + string.slice(1);
    },
    $number(amount, locale, options) {
      let formatted = parseFloat(amount);
      if (!locale || (locale.length != 2 && locale.length != 5)) {
        locale = this.$page.props.settings.default_locale;
      }
      if (!options) {
        options = { minimumFractionDigits: this.$page.props.settings.fraction, maximumFractionDigits: this.$page.props.settings.fraction };
      }
      return new Intl.NumberFormat(locale, options).format(formatted);
    },
    // Comma-formatted number with an explicit currency code prefix (e.g.
    // "TZS 1,234,567.00") — used wherever a money amount needs to carry its
    // currency instead of a bare number. `currency` is the record's own
    // currency (PO/invoice/payment/etc.); falls back to the account default.
    $money(amount, currency, locale) {
      let formatted = parseFloat(amount);
      if (isNaN(formatted)) {
        formatted = 0;
      }
      if (!locale || (locale.length != 2 && locale.length != 5)) {
        locale = this.$page.props.settings.default_locale;
      }
      let code = currency && String(currency).length === 3 ? String(currency).toUpperCase() : this.$page.props.settings.currency_code;
      let number = new Intl.NumberFormat(locale, {
        minimumFractionDigits: this.$page.props.settings.fraction,
        maximumFractionDigits: this.$page.props.settings.fraction,
      }).format(formatted);
      return code + ' ' + number;
    },
    $currency(amount, locale, options) {
      let formatted = parseFloat(amount);
      if (!locale || (locale.length != 2 && locale.length != 5)) {
        locale = this.$page.props.settings.default_locale;
      }
      if (options.currency && options.currency.length != 3) {
        options.currency = this.$page.props.settings.currency_code;
      }
      if (!options) {
        options = {
          style: 'currency',
          signDisplay: 'always',
          currencyDisplay: 'symbol',
          // currencySign: 'accounting',
          currency: this.$page.props.settings.currency_code,
          minimumFractionDigits: this.$page.props.settings.fraction,
        };
      }
      return new Intl.NumberFormat(locale, options).format(formatted);
    },
    $parseNumber(amount, locale) {
      if (!locale || (locale.length != 2 && locale.length != 5)) {
        locale = this.$page.props.settings.default_locale;
      }
      var thousandSeparator = Intl.NumberFormat(locale)
        .format(11111)
        .replace(/\p{Number}/gu, '');
      var decimalSeparator = Intl.NumberFormat(locale)
        .format(1.1)
        .replace(/\p{Number}/gu, '');
      return parseFloat(amount.replace(new RegExp('\\' + thousandSeparator, 'g'), '').replace(new RegExp('\\' + decimalSeparator), '.'));
    },
    $date(date, locale, style) {
      let formatted = new Date(Date.parse(date));
      if (!locale || (locale.length != 2 && locale.length != 5)) {
        locale = this.$page.props.settings.default_locale;
      }
      return formatted.toLocaleString(locale, { dateStyle: style ? style : 'medium' });
    },
    $formatJSDate(date) {
      var d = new Date(date),
        month = '' + (d.getMonth() + 1),
        day = '' + d.getDate(),
        year = d.getFullYear();
      if (month.length < 2) month = '0' + month;
      if (day.length < 2) day = '0' + day;
      return [year, month, day].join('-');
    },
    $dateDay(date, locale) {
      let formatted = new Date(Date.parse(date));
      if (!locale || (locale.length != 2 && locale.length != 5)) {
        locale = this.$page.props.settings.default_locale;
      }
      return formatted.toLocaleString(locale, { day: 'numeric', weekday: 'short' });
    },
    $month(month, locale, style = 'short') {
      let formatted = new Date(Date.parse(month));
      if (!locale || (locale.length != 2 && locale.length != 5)) {
        locale = this.$page.props.settings.default_locale;
      }
      return formatted.toLocaleString(locale, { month: style, year: '2-digit' });
    },
    $monthName(month, locale, style = 'long') {
      let formatted = new Date(Date.parse(month));
      if (!locale || (locale.length != 2 && locale.length != 5)) {
        locale = this.$page.props.settings.default_locale;
      }
      return formatted.toLocaleString(locale, { month: style });
    },
    $time(date, locale, style) {
      let formatted = new Date(Date.parse(date));
      if (!locale || (locale.length != 2 && locale.length != 5)) {
        locale = this.$page.props.settings.default_locale;
      }
      return formatted.toLocaleString(locale, { timeStyle: 'short', hour12: true });
    },
    $datetime(datetime, locale, style) {
      let formatted = new Date(Date.parse(datetime));
      if (!locale || (locale.length != 2 && locale.length != 5)) {
        locale = this.$page.props.settings.default_locale;
      }
      return formatted.toLocaleString(locale, { dateStyle: style ? style : 'medium', timeStyle: 'short', hour12: true });
    },
    $can(permissions) {
      if (this.$page.props.auth.user && this.$page.props.auth.user.roles.find(r => r.name == 'Super Admin')) {
        return true;
      }
      let allow = false;
      if (!Array.isArray(permissions)) {
        permissions = [permissions];
      }
      if (permissions && permissions.length > 0) {
        if (permissions.includes('all')) {
          allow = true;
        } else {
          permissions.map(p => {
            if (
              this.$page.props.auth.user &&
              this.$page.props.auth.user.all_permissions &&
              this.$page.props.auth.user.all_permissions.includes(p)
            ) {
              allow = true;
            }
          });
        }
      }
      return allow;
    },
    $meta(meta, noHtml = false, join = false) {
      let str = [];
      for (const [key, value] of Object.entries(meta)) {
        noHtml ? str.push(key + ': ' + value) : str.push(key + ': <strong>' + value + '</strong>');
      }
      return str.join(join ? '\n ' : ', ');
    },
  },
};

export default mixin;

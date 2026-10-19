<template>
  <admin-layout :title="$t('Settings')">
    <div class="st-root lg-page px-4 md:px-0">
      <page-header icon="cog" :title="$t('Settings')" :subtitle="$t('Configure how WIMS looks, formats data and behaves for everyone in your organisation.')">
        <span class="lg-chip lg-chip-indigo" style="text-transform: none"><icons name="sparkles" class="w-3.5 h-3.5" />{{ form.version_label }}</span>
      </page-header>

      <div class="st-layout">
        <!-- Section navigation -->
        <nav class="st-nav" :aria-label="$t('Settings sections')">
          <a
            v-for="item in navItems"
            :key="item.id"
            :href="'#st-' + item.id"
            class="st-nav-item"
            :class="{ 'is-active': activeSection === item.id }"
            @click.prevent="goTo(item.id)"
          >
            <span class="st-nav-ico"><icons :name="item.icon" class="w-4 h-4" /></span>
            <span class="st-nav-label">{{ item.label }}</span>
          </a>
        </nav>

        <div class="st-main">
          <!-- Super Admin: Feature control -->
          <section v-if="$super" id="st-features" class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-indigo"><icons name="toggle" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Global Feature Control') }}</h2>
                <p class="lg-card-sub">{{ $t('Turn individual WIMS modules on or off for everyone except Super Admins.') }}</p>
              </div>
              <Link :href="route('system-features.index')" class="lg-btn lg-btn-primary st-card-action">{{ $t('Manage Feature Control') }}</Link>
            </header>
            <p class="lg-note lg-note-blue"><icons name="info" class="w-4 h-4" />{{ $t('Disabling a module hides it from navigation and blocks it server-side for everyone else. No data is ever deleted.') }}</p>
          </section>

          <!-- Main settings form -->
          <form @submit.prevent="submit" class="st-form">
            <section id="st-general" class="lg-card">
              <header class="lg-card-head">
                <span class="lg-card-ico lg-ico-slate"><icons name="cog" class="w-5 h-5" /></span>
                <div class="min-w-0">
                  <h2 class="lg-card-title">{{ $t('General') }}</h2>
                  <p class="lg-card-sub">{{ $t('Your site name, language and how records are numbered.') }}</p>
                </div>
              </header>
              <div class="lg-fields">
                <div>
                  <text-input v-model="form.name" :error="$page.props.errors.name" :label="$t('Site Name')" />
                </div>
                <div>
                  <auto-complete id="language" :searchable="false" v-model="form.language" :label="$t('Language')" :error="$page.props.errors.language" :suggestions="$page.props.languages" />
                </div>
                <div>
                  <auto-complete
                  id="reference"
                  :searchable="false"
                  v-model="form.reference"
                  :label="$t('Reference')"
                  :error="$page.props.errors.reference"
                  :suggestions="[
                    { value: 'ulid', label: 'ULID - Universally Unique Lexicographically Sortable Identifier' },
                    { value: 'ai', label: 'Auto Increment (MYSQL only)' },
                    { value: 'uniqid', label: 'Uniqid - PHP Generate a Unique ID' },
                    { value: 'uuid', label: 'UUID - Universally Unique Identifier' },
                  ]"
                />
                </div>
                <div>
                  <auto-complete
                  id="per_page"
                  :searchable="false"
                  v-model="form.per_page"
                  :label="$t('Rows per page')"
                  :error="$page.props.errors.per_page"
                  :suggestions="[10, 15, 25, 50, 100].map(n => ({ value: n, label: n }))"
                />
                </div>
              </div>
            </section>

            <section id="st-regional" class="lg-card">
              <header class="lg-card-head">
                <span class="lg-card-ico lg-ico-sky"><icons name="calendar" class="w-5 h-5" /></span>
                <div class="min-w-0">
                  <h2 class="lg-card-title">{{ $t('Currency, Number & Date') }}</h2>
                  <p class="lg-card-sub">{{ $t('Controls how amounts and dates appear across screens, reports and PDFs.') }}</p>
                </div>
              </header>
              <div class="lg-fields">
                <div>
                  <text-input v-model="form.currency_code" :error="$page.props.errors.currency_code" :label="$t('Currency Code')" />
                </div>
                <div>
                  <text-input v-model="form.default_locale" :error="$page.props.errors.default_locale" :label="$t('Date & Number Locale')" />
                </div>
                <div>
                  <auto-complete
                  id="fraction"
                  :searchable="false"
                  v-model="form.fraction"
                  :label="$t('Decimal Fractions')"
                  :error="$page.props.errors.fraction"
                  :suggestions="[0, 1, 2, 3, 4].map(n => ({ value: n, label: n }))"
                />
                </div>
              </div>

              <div class="st-preview" aria-live="polite">
                <div class="st-preview-head">
                  <icons name="eye-flat" class="w-3.5 h-3.5" />{{ $t('Live preview') }}
                </div>
                <dl class="st-preview-grid">
                  <div>
                    <dt><icons name="hash" class="w-3.5 h-3.5" />{{ $t('Number') }}</dt>
                    <dd>{{ $number(20000000.5, form.default_locale, { minimumFractionDigits: form.fraction }) }}</dd>
                  </div>
                  <div>
                    <dt><icons name="calendar" class="w-3.5 h-3.5" />{{ $t('Date') }}</dt>
                    <dd>{{ $date(new Date(), form.default_locale) }}</dd>
                  </div>
                  <div>
                    <dt><icons name="clock" class="w-3.5 h-3.5" />{{ $t('Date Time') }}</dt>
                    <dd>{{ $datetime(new Date(), form.default_locale) }}</dd>
                  </div>
                </dl>
              </div>
            </section>

            <section id="st-display" class="lg-card">
              <header class="lg-card-head">
                <span class="lg-card-ico lg-ico-violet"><icons name="adjustments" class="w-5 h-5" /></span>
                <div class="min-w-0">
                  <h2 class="lg-card-title">{{ $t('Display') }}</h2>
                  <p class="lg-card-sub">{{ $t('How the navigation sidebar is laid out.') }}</p>
                </div>
              </header>
              <div class="lg-fields">
                <div>
                  <auto-complete
                  id="sidebar"
                  :searchable="false"
                  :label="$t('Sidebar')"
                  v-model="form.sidebar"
                  :error="$page.props.errors.sidebar"
                  :suggestions="[
                    { value: 'full', label: $t('Default') },
                    { value: 'mini', label: $t('Collapsed') },
                  ]"
                />
                </div>
                <transition name="slidedown">
                  <div v-if="form.sidebar == 'full'">
                  <auto-complete
                    id="sidebar_style"
                    :searchable="false"
                    :label="$t('Sidebar Style')"
                    v-model="form.sidebar_style"
                    :error="$page.props.errors.sidebar_style"
                    :suggestions="[
                      { value: 'full', label: $t('Full') },
                      { value: 'dropdown', label: $t('Dropdown') },
                    ]"
                  />
                  </div>
                </transition>
              </div>
            </section>

            <section id="st-inventory" class="lg-card">
              <header class="lg-card-head">
                <span class="lg-card-ico lg-ico-amber"><icons name="collection" class="w-5 h-5" /></span>
                <div class="min-w-0">
                  <h2 class="lg-card-title">{{ $t('Inventory Rules') }}</h2>
                  <p class="lg-card-sub">{{ $t('Stock behaviour that applies to every warehouse.') }}</p>
                </div>
              </header>

              <div class="st-switch-row">
                <div class="min-w-0">
                  <div class="st-switch-title">{{ $t('Enable over selling') }}</div>
                  <div class="st-switch-text">{{ $t('Allow stock to go below zero when an item is issued or sold.') }}</div>
                </div>
                <label class="wims-toggle">
                  <input type="checkbox" v-model="form.over_selling" />
                  <span class="wims-toggle-track"><span class="wims-toggle-thumb"></span></span>
                </label>
              </div>

              <div class="st-switch-row">
                <div class="min-w-0">
                  <div class="st-switch-title">{{ $t('Track weight of item') }}</div>
                  <div class="st-switch-text">{{ $t('Record a weight for each item and show totals in the chosen unit.') }}</div>
                </div>
                <label class="wims-toggle">
                  <input type="checkbox" v-model="form.track_weight" />
                  <span class="wims-toggle-track"><span class="wims-toggle-thumb"></span></span>
                </label>
              </div>
              <transition name="slidedown">
                <div v-if="form.track_weight" class="lg-fields st-grid-inset">
                  <div><text-input v-model="form.weight_unit" :error="$page.props.errors.weight_unit" :label="$t('Weight Unit')" /></div>
                </div>
              </transition>
            </section>

            <section id="st-automation" class="lg-card">
              <header class="lg-card-head">
                <span class="lg-card-ico lg-ico-emerald"><icons name="clock" class="w-5 h-5" /></span>
                <div class="min-w-0">
                  <h2 class="lg-card-title">{{ $t('Site Report Auto-Archive') }}</h2>
                  <p class="lg-card-sub">{{ $t('Keeps the Daily Site Reports list tidy by archiving old submissions on a schedule.') }}</p>
                </div>
              </header>
              <p class="lg-note lg-note-blue mb-4">
                <icons name="info" class="w-4 h-4" />
                {{ $t('Every night at midnight, submitted reports older than this many days are marked Archived and their attachment files are removed to free up space. The report itself, and the record of what was attached, is never deleted, only its files.') }}
              </p>
              <div class="lg-fields">
                <div>
                  <text-input
                    type="number"
                    min="1"
                    max="365"
                    v-model="form.daily_site_report_auto_archive_days"
                    :error="$page.props.errors.daily_site_report_auto_archive_days"
                    :label="$t('Archive after (days)')"
                  />
                  <p class="st-hint">{{ $t('Applies from the next nightly run onward. Already-archived reports are unaffected.') }}</p>
                </div>
              </div>
            </section>

            <section id="st-branding" class="lg-card">
              <header class="lg-card-head">
                <span class="lg-card-ico lg-ico-pink"><icons name="image" class="w-5 h-5" /></span>
                <div class="min-w-0">
                  <h2 class="lg-card-title">{{ $t('Branding') }}</h2>
                  <p class="lg-card-sub">{{ $t('The logo and text shown on the login screen and Forgot Password Page.') }}</p>
                </div>
              </header>

              <div class="st-logo-row">
                <div class="st-logo-box"><img :src="logoPreviewUrl" alt="" @error="onLogoPreviewError" /></div>
                <div class="min-w-0">
                  <input ref="logoInput" type="file" accept="image/png,image/jpeg" class="hidden" @change="onLogoChosen" />
                  <button type="button" class="lg-btn lg-btn-pink" :disabled="logoForm.processing" @click="$refs.logoInput.click()">
                    <icons name="upload" class="w-4 h-4" /> {{ logoForm.processing ? $t('Uploading…') : $t('Upload New Logo') }}
                  </button>
                  <p class="st-hint">{{ $t('PNG or JPG, up to 2MB.') }}</p>
                  <p v-if="logoForm.errors.logo" class="st-error">{{ logoForm.errors.logo }}</p>
                </div>
              </div>

              <div class="lg-fields">
                <div>
                  <text-input v-model="form.app_name" :error="$page.props.errors.app_name" :label="$t('Application Name')" />
                </div>
                <div>
                  <text-input v-model="form.footer_credit" :error="$page.props.errors.footer_credit" :label="$t('Footer Credit')" />
                </div>
                <div>
                  <text-input v-model="form.version_label" :error="$page.props.errors.version_label" :label="$t('Version Label')" />
                </div>
              </div>
              <p class="st-hint">{{ $t('Application Name, Footer Credit, and Version Label save with the main form below.') }}</p>
            </section>

            <!-- Sticky save bar for everything above -->
            <div class="lg-actionbar st-savebar" :class="{ 'is-dirty': form.isDirty }">
              <span class="lg-actionbar-note st-savebar-status">
                <span class="st-dot" :class="form.isDirty ? 'st-dot-amber' : 'st-dot-green'"></span>
                <template v-if="form.recentlySuccessful && !form.isDirty">{{ $t('Saved.') }}</template>
                <template v-else-if="form.isDirty">{{ $t('You have unsaved changes') }}</template>
                <template v-else>{{ $t('All changes saved') }}</template>
              </span>
              <span class="lg-actionbar-btns">
                <button v-if="form.isDirty" type="button" class="lg-btn lg-btn-soft" :disabled="form.processing" @click="form.reset()">{{ $t('Discard') }}</button>
                <loading-button type="submit" :loading="form.processing" :disabled="form.processing || !form.isDirty">
                  <span class="inline-flex items-center gap-2">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                      <path d="M4 3a1 1 0 00-1 1v12a1 1 0 001 1h12a1 1 0 001-1V7.41a1 1 0 00-.29-.7l-3.42-3.42A1 1 0 0012.59 3H4zm1 2h6v3H5V5zm0 6h10v5H5v-5z" />
                    </svg>
                    <span>{{ $t('Save') }}</span>
                  </span>
                </loading-button>
              </span>
            </div>
          </form>

          <!-- Terms & Conditions -->
          <section id="st-terms" class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-indigo"><icons name="doc-text" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('Terms & Conditions') }}</h2>
                <p class="lg-card-sub">{{ $t('The legal notice shown at /terms, linked from the footer on every page.') }} · {{ $t('Currently version') }} {{ current.terms_version || 1 }}</p>
              </div>
              <Link :href="route('terms.show')" target="_blank" class="lg-btn lg-btn-soft st-card-action">
                <icons name="eye-flat" class="w-3.5 h-3.5" /> {{ $t('View Live Page') }}
              </Link>
            </header>
            <form @submit.prevent="submitTerms">
              <p class="lg-note lg-note-blue mb-4">
                <icons name="info" class="w-4 h-4" />
                {{ $t('Start a line with "## " to begin a new section: each becomes its own labeled card on the Terms page, with an icon chosen automatically from the heading. Use **double asterisks** for bold text. Saving bumps the version, so every user is asked to re-read it and their acknowledgment is logged again.') }}
              </p>
              <div class="flex items-center justify-between gap-2 mb-2">
                <label class="st-label">{{ $t('Terms & Conditions Content') }}</label>
                <button type="button" class="lg-btn lg-btn-soft" :class="{ 'is-open': termsContentVisible }" :aria-expanded="termsContentVisible" @click="termsContentVisible = !termsContentVisible">
                  <icons :name="termsContentVisible ? 'eye-off' : 'eye-flat'" class="w-3.5 h-3.5" />
                  <span>{{ termsContentVisible ? $t('Hide') : $t('Show') }}</span>
                  <icons name="chevron-down" class="st-chev w-3 h-3" />
                </button>
              </div>
              <div class="st-collapse" :class="{ 'is-open': termsContentVisible || !!$page.props.errors.content }">
                <div class="st-collapse-inner">
                  <textarea-input v-model="termsForm.content" :rows="16" :aria-label="$t('Terms & Conditions Content')" :error="$page.props.errors.content" />
                </div>
              </div>
              <div class="st-card-foot">
                <tec-action-message :on="termsForm.recentlySuccessful" class="text-sm text-emerald-600 font-semibold">{{ $t('Saved.') }}</tec-action-message>
                <loading-button type="submit" :loading="termsForm.processing" :disabled="termsForm.processing">
                  {{ $t('Save Terms & Conditions') }}
                </loading-button>
              </div>
            </form>
          </section>

          <!-- Impersonation (Super Admin) -->
          <section v-if="$super" id="st-security" class="lg-card st-card-danger">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-rose"><icons name="impersonate" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('User Impersonation') }}</h2>
                <p class="lg-card-sub">{{ $t('How long a "Login As User" session stays active before it auto-expires.') }} · {{ $t('Currently') }} {{ impersonationExpiryCurrent }} {{ $t('minutes') }}</p>
              </div>
            </header>
            <form @submit.prevent="submitImpersonationExpiry">
              <p class="lg-note lg-note-red mb-4">
                <icons name="info" class="w-4 h-4" />
                {{ $t('A Super Admin using "Login As User" is automatically returned to their own session after this many minutes. Changing it requires your own password, entered below, never the impersonated user\'s.') }}
              </p>
              <div class="lg-fields">
                <div>
                  <text-input type="number" min="1" max="1440" v-model="impersonationExpiryForm.minutes" :label="$t('Minutes before auto-expiry')" :error="impersonationExpiryForm.errors.minutes" />
                </div>
                <div>
                  <text-input
                  type="password"
                  autocomplete="current-password"
                  v-model="impersonationExpiryForm.password"
                  :label="$t('Confirm your password')"
                  :placeholder="$t('Your own Super Admin password')"
                  :error="impersonationExpiryForm.errors.password"
                />
                </div>
              </div>
              <div class="st-card-foot">
                <span v-if="impersonationExpirySaved" class="st-saved-badge"><icons name="tick" class="w-3.5 h-3.5" />{{ $t('Saved!') }}</span>
                <span v-else></span>
                <button type="submit" class="lg-btn lg-btn-red" :disabled="impersonationExpiryForm.processing">
                  <icons name="lock-closed" class="w-4 h-4" />
                  {{ impersonationExpiryForm.processing ? $t('Saving…') : $t('Save Session Duration') }}
                </button>
              </div>
            </form>
          </section>

          <!-- About -->
          <section id="st-about" class="lg-card">
            <header class="lg-card-head">
              <span class="lg-card-ico lg-ico-slate"><icons name="info" class="w-5 h-5" /></span>
              <div class="min-w-0">
                <h2 class="lg-card-title">{{ $t('About') }}</h2>
                <p class="lg-card-sub">{{ $t('Application Version Information') }}</p>
              </div>
            </header>
            <dl class="st-about">
              <div>
                <dt>{{ $t('Application Name') }}</dt>
                <dd>{{ form.app_name || 'RANS Workflow Integrated Management System' }}</dd>
              </div>
              <div>
                <dt>{{ $t('Version') }}</dt>
                <dd>{{ form.version_label || 'v1.6' }}</dd>
              </div>
            </dl>
            <p class="st-hint">{{ $t('Edit this in the Branding section above.') }}</p>
          </section>
        </div>
      </div>
    </div>
  </admin-layout>
</template>

<script>
import { Link } from '@inertiajs/vue3';
import CheckBox from '@/Shared/CheckBox.vue';
import TextInput from '@/Shared/TextInput.vue';
import SelectInput from '@/Shared/SelectInput.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AutoComplete from '@/Shared/AutoComplete.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';
import TecActionMessage from '@/Jetstream/ActionMessage.vue';

export default {
  props: ['current'],

  components: {
    Link,
    CheckBox,
    TextInput,
    SelectInput,
    AdminLayout,
    AutoComplete,
    LoadingButton,
    TextareaInput,
    PageHeader,
    TecActionMessage,
  },

  data() {
    return {
      form: this.$inertia.form({
        _method: 'POST',
        name: this.current.name,
        sidebar: this.current.sidebar,
        language: this.current.language,
        fraction: +this.current.fraction,
        per_page: +this.current.per_page,
        weight_unit: this.current.weight_unit,
        currency_code: this.current.currency_code,
        sidebar_style: this.current.sidebar_style,
        default_locale: this.current.default_locale,
        reference: this.current.reference || 'ulid',
        over_selling: this.current.over_selling == 1,
        track_weight: this.current.track_weight == 1,
        version_label: this.current.version_label || 'v1.6',
        daily_site_report_auto_archive_days: this.current.daily_site_report_auto_archive_days ? +this.current.daily_site_report_auto_archive_days : 14,
        app_name: this.current.app_name || 'RANS Workflow Integrated Management System',
        footer_credit: this.current.footer_credit || 'Developed by Extrion',
      }),
      logoForm: this.$inertia.form({ logo: null }),
      termsForm: this.$inertia.form({ content: this.current.terms_content || '' }),
      termsContentVisible: false,
      impersonationExpiryForm: {
        minutes: this.current.impersonation_expiry_minutes ? +this.current.impersonation_expiry_minutes : 30,
        password: '',
        processing: false,
        errors: {},
      },
      // Local mirror of the saved value for the "Currently N minutes" line —
      // `current` is a prop, so the saved confirmation updates this instead
      // of mutating it directly.
      impersonationExpiryCurrent: this.current.impersonation_expiry_minutes ? +this.current.impersonation_expiry_minutes : 30,
      impersonationExpirySaved: false,
      // A dedicated login-logo.png may not exist yet (nothing uploaded so
      // far) — this tracks whether the preview has already fallen back to
      // the shared app logo, matching Login.vue/ForgotPassword.vue's own
      // fallback exactly, so the preview always shows what those pages
      // actually show right now.
      loginLogoMissing: false,
      // Busts the browser cache so a freshly uploaded logo shows up
      // immediately instead of the previous image staying cached at the
      // same fixed path.
      logoCacheBust: Date.now(),
      activeSection: 'general',
      observer: null,
    };
  },

  computed: {
    navItems() {
      const items = [
        { id: 'general', icon: 'cog', label: this.$t('General') },
        { id: 'regional', icon: 'calendar', label: this.$t('Currency, Number & Date') },
        { id: 'display', icon: 'adjustments', label: this.$t('Display') },
        { id: 'inventory', icon: 'collection', label: this.$t('Inventory Rules') },
        { id: 'automation', icon: 'clock', label: this.$t('Auto-Archive') },
        { id: 'branding', icon: 'image', label: this.$t('Branding') },
        { id: 'terms', icon: 'doc-text', label: this.$t('Terms & Conditions') },
      ];
      if (this.$super) {
        items.unshift({ id: 'features', icon: 'toggle', label: this.$t('Feature Control') });
        items.push({ id: 'security', icon: 'impersonate', label: this.$t('User Impersonation') });
      }
      items.push({ id: 'about', icon: 'info', label: this.$t('About') });
      return items;
    },

    logoPreviewUrl() {
      return this.loginLogoMissing ? '/images/logo.png' : `/images/login-logo.png?v=${this.logoCacheBust}`;
    },
  },

  mounted() {
    if (typeof IntersectionObserver === 'undefined') {
      return;
    }
    this.observer = new IntersectionObserver(
      entries => {
        const visible = entries.filter(e => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
        if (visible.length) {
          this.activeSection = visible[0].target.id.replace('st-', '');
        }
      },
      { rootMargin: '-15% 0px -70% 0px' }
    );
    this.navItems.forEach(i => {
      const el = document.getElementById('st-' + i.id);
      if (el) {
        this.observer.observe(el);
      }
    });
  },

  beforeUnmount() {
    if (this.observer) {
      this.observer.disconnect();
    }
  },

  methods: {
    goTo(id) {
      const el = document.getElementById('st-' + id);
      if (el) {
        this.activeSection = id;
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    },
    submit() {
      this.form.post(route('settings.store'), { preserveScroll: true });
    },
    onLogoPreviewError() {
      // Only the first (login-logo.png) attempt should fall back — once
      // already showing the shared logo.png, a further error means that's
      // missing too, and retrying would just loop.
      if (!this.loginLogoMissing) {
        this.loginLogoMissing = true;
      }
    },
    onLogoChosen(e) {
      const file = e.target.files && e.target.files[0];
      if (!file) {
        return;
      }
      this.logoForm.logo = file;
      this.logoForm.post(route('settings.logo.upload'), {
        preserveScroll: true,
        onSuccess: () => {
          this.loginLogoMissing = false;
          this.logoCacheBust = Date.now();
          this.logoForm.reset();
        },
      });
    },
    submitTerms() {
      this.termsForm.put(route('settings.terms.update'), { preserveScroll: true });
    },
    submitImpersonationExpiry() {
      if (this.impersonationExpiryForm.processing) {
        return;
      }
      this.impersonationExpirySaved = false;
      const errors = {};
      if (!this.impersonationExpiryForm.minutes) {
        errors.minutes = this.$t('Enter how many minutes a session should last.');
      }
      if (!this.impersonationExpiryForm.password) {
        errors.password = this.$t('Enter your password to confirm this change.');
      }
      if (errors.minutes || errors.password) {
        this.impersonationExpiryForm.errors = errors;
        return;
      }
      this.impersonationExpiryForm.processing = true;
      this.impersonationExpiryForm.errors = {};
      axios
        .put(this.route('settings.impersonation-expiry.update'), {
          minutes: this.impersonationExpiryForm.minutes,
          password: this.impersonationExpiryForm.password,
        })
        .then(res => {
          this.impersonationExpiryForm.processing = false;
          this.impersonationExpiryForm.password = '';
          this.impersonationExpiryCurrent = res.data.minutes;
          this.impersonationExpirySaved = true;
          setTimeout(() => {
            this.impersonationExpirySaved = false;
          }, 3000);
        })
        .catch(err => {
          this.impersonationExpiryForm.processing = false;
          const rawErrors = err.response?.data?.errors || {};
          this.impersonationExpiryForm.errors = {
            minutes: rawErrors.minutes?.[0] || '',
            password: rawErrors.password?.[0] || (!rawErrors.minutes ? err.response?.data?.message || this.$t('Could not save right now.') : ''),
          };
        });
    },
  },
};
</script>

<style scoped>
.st-root { position: relative; }

/* Layout */
.st-layout { display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: 1.4rem; align-items: start; }
.st-main .lg-card { margin-bottom: 0; scroll-margin-top: 5.5rem; }
.st-main { display: flex; flex-direction: column; gap: 1.1rem; min-width: 0; }
.st-form .lg-card { margin-bottom: 0; }
.st-form { display: flex; flex-direction: column; gap: 1.1rem; }

/* Nav */
.st-nav { position: sticky; top: 5.5rem; display: flex; flex-direction: column; gap: 2px; padding: 8px; border-radius: 18px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: var(--lg-shadow); }
.st-nav-item { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 12px; font-size: 0.8rem; font-weight: 700; color: var(--lg-mute); transition: background 0.15s ease, color 0.15s ease; }
.st-nav-item:hover { background: var(--lg-soft); color: var(--lg-ink); }
.st-nav-ico { display: inline-flex; align-items: center; justify-content: center; flex: none; width: 26px; height: 26px; border-radius: 9px; background: var(--lg-soft); }
.st-nav-item.is-active { color: #3730a3; background: #eef2ff; }
.st-nav-item.is-active .st-nav-ico { color: #fff; background: linear-gradient(145deg, #7aa2ff, #4f46e5); box-shadow: 0 6px 12px -6px rgba(79, 70, 229, 0.8); }
html.dark .st-nav-item.is-active { color: #c7d2fe; background: rgba(99, 102, 241, 0.16); }
.st-nav-label { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* Cards: each section is its own object, separated by a soft shadow */
.st-card-action { margin-left: auto; flex: none; }
.lg-btn-pink { color: #fff; background: linear-gradient(145deg, #f472b6, #be185d); box-shadow: 0 10px 18px -10px rgba(190, 24, 93, 0.9); }
.lg-btn-soft.is-open { color: #fff; background: linear-gradient(145deg, #818cf8, #4338ca); border-color: #4338ca; }
.st-chev { transition: transform 0.25s ease; opacity: 0.75; }
.lg-btn.is-open .st-chev { transform: rotate(180deg); }
.st-card-danger { border-color: rgba(220, 38, 38, 0.22); }
.st-card-foot { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-top: 1rem; }
.st-label { font-size: 0.82rem; font-weight: 700; color: var(--lg-ink); }

.st-grid-inset { margin-top: 0.9rem; }
.st-hint { margin-top: 6px; font-size: 0.72rem; color: var(--lg-faint); }
.st-error { margin-top: 6px; font-size: 0.74rem; color: #b42318; }
/* Switch rows */
.st-switch-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 0.85rem 0; border-top: 1px solid var(--lg-line); }
.st-switch-row:first-of-type { border-top: 0; padding-top: 0; }
.st-switch-title { font-size: 0.86rem; font-weight: 800; color: var(--lg-ink); }
.st-switch-text { margin-top: 1px; font-size: 0.74rem; color: var(--lg-mute); }

/* Live preview */
.st-preview { margin-top: 1.1rem; padding: 0.8rem 1rem 1rem; border-radius: 16px; background: var(--lg-soft); border: 1px dashed var(--lg-line); }
.st-preview-head { display: flex; align-items: center; gap: 6px; font-size: 0.68rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: var(--lg-faint); }
.st-preview-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-top: 10px; }
.st-preview-grid > div { min-width: 0; padding: 10px 12px; border-radius: 12px; background: var(--lg-card); border: 1px solid var(--lg-line); box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03); }
.st-preview-grid dt { display: flex; align-items: center; gap: 5px; font-size: 0.68rem; font-weight: 800; color: var(--lg-faint); }
.st-preview-grid dd { margin-top: 4px; font-size: 0.95rem; font-weight: 800; color: var(--lg-ink); overflow-wrap: anywhere; font-variant-numeric: tabular-nums; }

/* Branding */
.st-logo-row { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 1.2rem; }
.st-logo-box { display: flex; align-items: center; justify-content: center; width: 168px; height: 84px; padding: 8px; border-radius: 16px; background: repeating-conic-gradient(#f1f5f9 0% 25%, #fff 0% 50%) 50% / 16px 16px; border: 1px solid var(--lg-line); }
.st-logo-box img { max-width: 100%; max-height: 100%; object-fit: contain; }
html.dark .st-logo-box { background: repeating-conic-gradient(rgba(255, 255, 255, 0.06) 0% 25%, rgba(255, 255, 255, 0.02) 0% 50%) 50% / 16px 16px; }

/* Collapse */
.st-collapse { display: grid; grid-template-rows: 0fr; opacity: 0; transition: grid-template-rows 0.35s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.25s ease; }
.st-collapse.is-open { grid-template-rows: 1fr; opacity: 1; }
.st-collapse-inner { overflow: hidden; min-height: 0; }

.st-saved-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 11px; border-radius: 9999px; font-size: 0.78rem; font-weight: 800; color: #15803d; background: #dcfce7; }
html.dark .st-saved-badge { color: #86efac; background: rgba(22, 163, 74, 0.18); }

/* About */
.st-about { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.st-about > div { padding: 10px 14px; border-radius: 14px; background: var(--lg-soft); border: 1px solid var(--lg-line); }
.st-about dt { font-size: 0.68rem; font-weight: 800; letter-spacing: 0.05em; text-transform: uppercase; color: var(--lg-faint); }
.st-about dd { margin-top: 3px; font-weight: 800; color: var(--lg-ink); overflow-wrap: anywhere; }

/* Sticky save bar */
.st-savebar.is-dirty { border-color: #fcd34d; box-shadow: 0 18px 34px -16px rgba(217, 119, 6, 0.5), 0 2px 4px rgba(15, 23, 42, 0.05); }
.st-savebar-status { display: inline-flex; align-items: center; gap: 8px; font-size: 0.8rem; font-weight: 700; color: var(--lg-mute); }
.st-dot { width: 9px; height: 9px; border-radius: 9999px; }
.st-dot-green { background: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.18); }
.st-dot-amber { background: #f59e0b; box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.22); }

@media (max-width: 1023px) {
  .st-layout { grid-template-columns: minmax(0, 1fr); }
  .st-nav { position: static; flex-direction: row; overflow-x: auto; padding: 6px; border-radius: 9999px; scrollbar-width: none; }
  .st-nav::-webkit-scrollbar { display: none; }
  .st-nav-item { flex: none; padding: 6px 12px 6px 6px; border-radius: 9999px; }
}
@media (max-width: 640px) {
  .st-about, .st-preview-grid { grid-template-columns: minmax(0, 1fr); }
    .st-card-action { margin-left: 0; }
  }
</style>

<template>
  <admin-layout :title="$t('Send Notice')">
    <div class="lg-page px-4 md:px-0 nb-form">
      <page-header icon="bell" :title="$t('Send Notice')" :subtitle="$t('Write a notice and choose who should receive it.')">
        <Link :href="route('notices.index')" class="lg-btn lg-btn-soft">
          <icons name="chevron-down" class="w-4 h-4 transform rotate-90" />{{ $t('Notice Board') }}
        </Link>
      </page-header>

      <form @submit.prevent="submit">
        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-navy"><icons name="email" class="w-5 h-5" /></span>
            <div class="min-w-0">
              <h2 class="lg-card-title">{{ $t('Compose a new notice') }}</h2>
              <p class="lg-card-sub">{{ $t('Title') }} / {{ $t('Message') }}</p>
            </div>
          </header>
          <div class="lg-fields">
            <div class="lg-span-all">
              <text-input :label="$t('Title')" v-model="form.title" :error="$page.props.errors.title" class="notice-field" />
            </div>
            <div class="lg-span-all">
              <textarea-input :label="$t('Message')" v-model="form.message" :error="$page.props.errors.message" class="notice-field" />
            </div>
          </div>
        </section>

        <section class="lg-card">
          <header class="lg-card-head">
            <span class="lg-card-ico lg-ico-emerald"><icons name="users" class="w-5 h-5" /></span>
            <div class="min-w-0 flex-1">
              <h2 class="lg-card-title">{{ $t('Recipient Rules') }}</h2>
              <p class="lg-card-sub">{{ recipientSummary }}</p>
            </div>
            <span class="lg-chip" :class="form.send_to_all ? 'lg-chip-green' : 'lg-chip-indigo'">
              {{ form.send_to_all ? $t('Everyone') : $t('Selected recipients') }}
            </span>
          </header>

          <label class="nb-all" :class="{ 'is-on': form.send_to_all }">
            <input type="checkbox" v-model="form.send_to_all" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500" />
            <span class="text-sm font-semibold inline-flex items-center gap-1.5">
              <icons name="group" class="h-3.5 w-3.5" />
              {{ $t('Send to all users in this account') }}
            </span>
          </label>

          <div v-if="!form.send_to_all" class="lg-fields mt-4">
            <div class="nb-rule">
              <label class="nb-rule-head">
                <icons name="group" class="h-3.5 w-3.5" />
                {{ $t('Target Roles') }}
              </label>
              <select multiple v-model="form.role_ids" class="nb-select">
                <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.name }}</option>
              </select>
              <div v-if="$page.props.errors.role_ids" class="text-red-600 text-xs mt-1 wims-field-error">{{ $page.props.errors.role_ids }}</div>
            </div>

            <div class="nb-rule">
              <label class="nb-rule-head">
                <icons name="user" class="h-3.5 w-3.5" />
                {{ $t('Selected Users') }}
              </label>
              <select multiple v-model="form.user_ids" class="nb-select">
                <option v-for="user in users" :key="user.id" :value="user.id">{{ user.name }}</option>
              </select>
              <div v-if="$page.props.errors.user_ids" class="text-red-600 text-xs mt-1 wims-field-error">{{ $page.props.errors.user_ids }}</div>
            </div>
          </div>
        </section>

        <div class="lg-actionbar">
          <span class="lg-actionbar-note">{{ recipientSummary }}</span>
          <div class="lg-actionbar-btns">
            <Link :href="route('notices.index')" class="lg-btn lg-btn-soft">{{ $t('Cancel') }}</Link>
            <button type="submit" :disabled="form.processing" class="lg-btn lg-btn-primary">
              <icons :name="form.processing ? 'refresh' : 'email'" class="w-4 h-4" :class="{ 'animate-spin': form.processing }" />
              <span>{{ $t('Send Notice') }}</span>
            </button>
          </div>
        </div>
      </form>
    </div>
  </admin-layout>
</template>

<script>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TextInput from '@/Shared/TextInput.vue';
import TextareaInput from '@/Shared/TextareaInput.vue';
import PageHeader from '@/Shared/Page/PageHeader.vue';

export default {
  components: {
    AdminLayout,
    TextInput,
    TextareaInput,
    PageHeader,
  },

  props: {
    roles: Array,
    users: Array,
  },

  data() {
    return {
      form: this.$inertia.form({
        title: '',
        message: '',
        send_to_all: false,
        role_ids: [],
        user_ids: [],
      }),
    };
  },

  computed: {
    recipientSummary() {
      if (this.form.send_to_all) return this.$t('Sent to all users in this account.');
      return `${(this.form.role_ids || []).length} ${this.$t('Roles')} · ${(this.form.user_ids || []).length} ${this.$t('Selected Users')}`;
    },
  },

  methods: {
    submit() {
      this.form.post(route('notices.store'));
    },
  },
};
</script>

<style scoped>
.nb-all {
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  padding: 0.85rem 1rem;
  border-radius: 16px;
  color: var(--lg-ink);
  background: var(--lg-soft);
  border: 1px solid var(--lg-line);
  transition: border-color 0.15s ease, background 0.15s ease;
}
.nb-all.is-on {
  border-color: rgba(16, 185, 129, 0.5);
  background: rgba(16, 185, 129, 0.1);
}
.nb-rule {
  padding: 0.85rem 1rem;
  border-radius: 16px;
  background: var(--lg-soft);
  border: 1px solid var(--lg-line);
}
.nb-rule-head {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 8px;
  font-size: 0.66rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--lg-faint);
}
.nb-select {
  width: 100%;
  min-height: 10rem;
  border-radius: 12px;
  border-color: var(--lg-line);
  background: var(--lg-card);
  color: var(--lg-ink);
}
.nb-select:focus {
  border-color: #3b82f6;
  --tw-ring-color: #3b82f6;
}
</style>

<template>
  <auth-shell :ctx="ctxActive">
    <template #form>
      <div class="mb-7">
            <img :src="loginLogoUrl" alt="RANS Logo" class="login-logo h-20 max-w-[242px] w-auto object-contain" style="touch-action: manipulation; -webkit-tap-highlight-color: transparent" @click="ctxLogoClick" @error="loginLogoFailed = true" />
            <h1 class="mt-4 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-[1.7rem]">Log in to your account</h1>
            <p class="mt-2 text-sm text-slate-500 login-tagline">
              <span class="login-tagline-word" style="animation-delay: 0.1s">{{ $t('Connecting') }}</span><span class="login-tagline-sep">,</span>
              <span class="login-tagline-word" style="animation-delay: 0.35s">{{ $t('Informing') }}</span><span class="login-tagline-sep">,</span>
              <span class="login-tagline-word" style="animation-delay: 0.6s">{{ $t('Delivering') }}</span>
              <span class="login-tagline-brand" style="animation-delay: 0.9s">- WIMS &copy;</span>
            </p>
          </div>

      <tec-validation-errors class="mb-4" />

          <div v-if="$page.props.flash && $page.props.flash.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" v-html="$page.props.flash.error" />

          <div v-if="status" class="mb-4 font-medium text-green-600">
            {{ status }}
          </div>

          <div v-if="isLocked" class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-700">
            {{ lockMessage }}
          </div>

          <div v-if="$page.props.loginBlock" class="mb-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">
            {{ $page.props.loginBlock.message }}
          </div>

          <div class="auth-note mb-4">
            <span class="auth-note-icon">
              <svg class="h-3.5 w-3.5 text-white" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.2-9.6a1 1 0 10-1.4-1.4L9 9.8 8.2 9a1 1 0 10-1.4 1.4l1.5 1.5a1 1 0 001.4 0l3.5-3.5z" clip-rule="evenodd" />
              </svg>
            </span>
            <span>Secure login. Your session is protected and monitored.</span>
          </div>

          <form @submit.prevent="submit">
            <!-- Honeypot: real users never see this (off-screen, not display:none
                 so it can't be trivially skipped), so any value here means an
                 automated form-filler touched it. -->
            <div class="login-honeypot" aria-hidden="true">
              <label for="hp_check">Website</label>
              <input id="hp_check" type="text" v-model="form.hp_check" tabindex="-1" autocomplete="off" />
            </div>

            <div>
              <tec-label for="username" :value="$t('Username')" />
              <div class="relative mt-1">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                  <svg class="auth-field-icon h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 8a7 7 0 1114 0H3z" />
                  </svg>
                </span>
                <tec-input id="username" type="text" class="auth-input block w-full pl-10" v-model="form.username" required autofocus />
              </div>
              <div v-if="form.errors.username" class="mt-1 text-xs text-red-600 wims-field-error">{{ form.errors.username }}</div>
            </div>

            <div class="mt-4">
              <tec-label for="password" :value="$t('Password')" />
              <div class="relative mt-1">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                  <svg class="auth-field-icon h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5 8a5 5 0 1110 0v1h1a2 2 0 012 2v5a2 2 0 01-2 2H4a2 2 0 01-2-2v-5a2 2 0 012-2h1V8zm2 1h6V8a3 3 0 10-6 0v1z" clip-rule="evenodd" />
                  </svg>
                </span>
                <tec-input
                  required
                  id="password"
                  type="password"
                  v-model="form.password"
                  class="auth-input block w-full pl-10"
                  autocomplete="current-password"
                />
              </div>
              <div v-if="form.errors.password" class="mt-1 text-xs text-red-600 wims-field-error">{{ form.errors.password }}</div>
            </div>

            <div v-if="showChallenge" class="mt-4">
              <tec-label for="challenge_answer" :value="$t('Verification') + ': ' + $t('What is') + ' ' + challenge.a + ' + ' + challenge.b + '?'" />
              <tec-input
                id="challenge_answer"
                type="number"
                inputmode="numeric"
                class="auth-input mt-1 block w-full"
                v-model="form.challenge_answer"
                required
              />
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
              <label class="flex items-center whitespace-nowrap" :title="$t('Stay signed in on this device. You will not be signed out automatically when idle.')">
                <tec-checkbox name="remember" v-model:checked="form.remember" />
                <span class="ml-2 inline-flex items-center gap-1 text-gray-600">
                  <svg class="h-4 w-4 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M10 2a8 8 0 100 16 8 8 0 000-16zm.75 4a.75.75 0 00-1.5 0v4.19l-1.72 1.72a.75.75 0 101.06 1.06l1.94-1.94a.75.75 0 00.22-.53V6z" />
                  </svg>
                  <span>{{ $t('Remember me') }}</span>
                </span>
              </label>

              <Link
                v-if="canResetPassword"
                :href="route('password.request')"
                class="auth-link inline-flex items-center gap-1 whitespace-nowrap text-sm font-medium"
              >
                <span class="auth-link-chip" aria-hidden="true">
                  <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M14.5 1A4.5 4.5 0 0010 5.5V9H3a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-1.5V5.5a3 3 0 116 0v2.75a.75.75 0 001.5 0V5.5A4.5 4.5 0 0014.5 1z" />
                  </svg>
                </span>
                {{ $t('Forgot your password?') }}
              </Link>
            </div>

            <div class="w-full mt-6">
              <loading-button
                type="submit"
                class="auth-submit login-submit block w-full"
                :class="[isLocked ? 'bg-red-900 hover:bg-red-900 active:bg-red-900 focus:ring-red-300' : '', form.processing ? 'is-busy' : '']"
                @click="ripple"
                :loading="form.processing"
                :disabled="form.processing || isLocked"
              >
                <span class="inline-flex items-center gap-2">
                  <svg class="login-arrow h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M3 10a1 1 0 011-1h8.59L10.3 6.7a1 1 0 111.4-1.4l4 4a1 1 0 010 1.4l-4 4a1 1 0 01-1.4-1.4L12.59 11H4a1 1 0 01-1-1z" clip-rule="evenodd" />
                  </svg>
                  <span>{{ isLocked ? `Login In (${remainingLockSeconds} Second (s))` : $t('Login') }}</span>
                </span>
              </loading-button>

              <template v-if="faceLoginEnabled">
                <div class="login-or"><span>{{ $t('or') }}</span></div>
                <button type="button" class="login-face-btn" :class="{ 'is-checking': faceChecking }" :disabled="form.processing || isLocked || faceChecking" @click="openFace">
                  <span class="login-face-ico" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8V6a2 2 0 012-2h2M16 4h2a2 2 0 012 2v2M20 16v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2" /><path d="M9 10v1.5M15 10v1.5M12 10v4h-1M9 16.2c1.7 1.2 4.3 1.2 6 0" /></svg>
                    <i class="login-face-scan"></i>
                  </span>
                  <span class="login-face-txt">{{ $t('Face ID Login') }}</span>
                  <span class="login-face-shine" aria-hidden="true"></span>
                </button>
                <transition name="lf-hint">
                  <p v-if="faceHint" class="login-face-hint" role="alert"><svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" /></svg><span>{{ faceHint }}</span></p>
                </transition>
              </template>

              <p class="mt-3 text-center text-xs font-semibold tracking-wide text-slate-500 login-footer-credit">{{ $page.props.settings.footer_credit || 'Developed by Extrion' }}</p>
            </div>
          </form>
    </template>

    <template #aside-head>
      <p class="auth-art-badge">
              <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M11.983 1.907a1 1 0 00-1.966 0l-.516 2.859a1 1 0 01-.798.798l-2.859.516a1 1 0 000 1.966l2.859.516a1 1 0 01.798.798l.516 2.859a1 1 0 001.966 0l.516-2.859a1 1 0 01.798-.798l2.859-.516a1 1 0 000-1.966l-2.859-.516a1 1 0 01-.798-.798l-.516-2.859zM4.5 12a.75.75 0 01.728.568l.258 1.036c.088.353.36.625.713.713l1.036.258a.75.75 0 010 1.456l-1.036.258a.913.913 0 00-.713.713l-.258 1.036a.75.75 0 01-1.456 0l-.258-1.036a.913.913 0 00-.713-.713l-1.036-.258a.75.75 0 010-1.456l1.036-.258a.913.913 0 00.713-.713L3.772 12.568A.75.75 0 014.5 12z" clip-rule="evenodd" />
              </svg>
              About The System
            </p>
      <h2 class="auth-art-title">
        {{ $page.props.settings.app_name || 'RANS Workflow Integrated Management System' }} (WIMS) {{ $page.props.settings.version_label || 'v1.6' }}
      </h2>
      <p class="auth-art-text">
        One platform connecting every side of the operation: inventory and procurement, supplier and financial records, workforce management, and site reporting, so every project, warehouse, and team works from the same real-time data.
      </p>
    </template>

    <template #aside-items>
            <div class="auth-row">
              <span class="auth-row-icon">
                <svg class="h-[18px] w-[18px]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                  <path d="M2 4a2 2 0 012-2h5a1 1 0 011 1v14a1 1 0 01-1 1H4a2 2 0 01-2-2V4zM12.5 3.5a1 1 0 011-1H16a2 2 0 012 2v3.5a1 1 0 01-1 1h-3.5a1 1 0 01-1-1v-4.5zM12.5 12a1 1 0 011-1H17a1 1 0 011 1v3a2 2 0 01-2 2h-2.5a1 1 0 01-1-1v-4z" />
                </svg>
              </span>
              <span>Unified operations: stock, purchasing, supplier records, and payments all managed in one connected workflow, end to end.</span>
            </div>
            <div class="auth-row">
              <span class="auth-row-icon">
                <svg class="h-[18px] w-[18px]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                  <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd" />
                </svg>
              </span>
              <span>Role-based approvals and permissions on every action, with a full audit trail behind every edit, transfer, and payment.</span>
            </div>
            <div class="auth-row">
              <span class="auth-row-icon">
                <svg class="h-[18px] w-[18px]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                  <path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1H3a1 1 0 01-1-1v-6zM8 7a1 1 0 011-1h2a1 1 0 011 1v10a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 3a1 1 0 011-1h2a1 1 0 011 1v14a1 1 0 01-1 1h-2a1 1 0 01-1-1V3z" />
                </svg>
              </span>
              <span>Live visibility into stock levels, supplier balances, and project spend, always current across every warehouse and site.</span>
            </div>
            <div class="auth-row">
              <span class="auth-row-icon">
                <svg class="h-[18px] w-[18px]" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                  <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h5.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm7 1v3a1 1 0 001 1h3l-4-4zM6 9a1 1 0 011-1h1a1 1 0 110 2H7a1 1 0 01-1-1zm0 3a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm0 3a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1z" clip-rule="evenodd" />
                </svg>
              </span>
              <span>Every report, voucher, and record is printable and traceable, from a single staff file to a full site report history.</span>
            </div>
    </template>

    <template #after>
      <tec-dialog-modal :show="showMaintenancePrompt" @close="showMaintenancePrompt = false" :max-width="'sm'">
      <template #title>
        <div class="flex items-center gap-3">
          <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-amber-100 text-amber-700">
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
              <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l6.518 11.591c.75 1.334-.213 2.99-1.742 2.99H3.48c-1.53 0-2.492-1.656-1.743-2.99L8.257 3.1zM11 14a1 1 0 10-2 0 1 1 0 002 0zm-1-7a1 1 0 00-1 1v4a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
            </svg>
          </span>
          <span>{{ $t('System Maintenance Notice') }}</span>
        </div>
      </template>
      <template #content>
        <p class="text-sm text-slate-700">
          {{ maintenanceNoticeMessage }}
        </p>
      </template>
      <template #footer>
        <button @click="showMaintenancePrompt = false" class="px-4 py-2 rounded-md bg-amber-600 text-white hover:bg-amber-700">
          {{ $t('OK') }}
        </button>
      </template>
    </tec-dialog-modal>

    <tec-dialog-modal :show="showLicencePrompt" @close="showLicencePrompt = false" :max-width="'sm'">
      <template #title>
        <div class="flex items-center gap-3">
          <span class="licence-lock inline-flex h-10 w-10 items-center justify-center rounded-full bg-rose-100 text-rose-700">
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
              <path fill-rule="evenodd" d="M10 1a4.5 4.5 0 00-4.5 4.5V9H5a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-.5V5.5A4.5 4.5 0 0010 1zm3 8V5.5a3 3 0 10-6 0V9h6z" clip-rule="evenodd" />
            </svg>
          </span>
          <span>{{ $t('WIMS Licence Notice') }}</span>
        </div>
      </template>
      <template #content>
        <p class="text-sm font-semibold text-slate-800">{{ licenceNoticeMessage }}</p>
        <p class="mt-3 text-xs text-slate-500">{{ $t('Your data is safe. Access returns as soon as the licence is renewed or reactivated.') }}</p>
      </template>
      <template #footer>
        <button @click="showLicencePrompt = false" class="px-4 py-2 rounded-md bg-[#7f1d1d] text-white hover:bg-[#601515]">{{ $t('OK') }}</button>
      </template>
    </tec-dialog-modal>

    <tec-dialog-modal :show="showDisabledPrompt" @close="showDisabledPrompt = false" :max-width="'sm'">
      <template #title>
        <div class="flex items-center gap-3">
          <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-red-100 text-red-800">
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
              <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 10-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
            </svg>
          </span>
          <span>{{ $t('Account On Hold') }}</span>
        </div>
      </template>
      <template #content>
        <p class="text-sm text-slate-700">
          {{ disabledNoticeMessage }}
        </p>
      </template>
      <template #footer>
        <button @click="showDisabledPrompt = false" class="px-4 py-2 rounded-md bg-[#7f1d1d] text-white hover:bg-[#601515]">
          {{ $t('OK') }}
        </button>
      </template>
    </tec-dialog-modal>

    <teleport to="body">
      <div v-if="faceOpen" class="login-face-overlay" @keydown.esc="closeFace" tabindex="-1">
        <div class="login-face-card" role="dialog" aria-modal="true" :aria-label="$t('Face ID Login')">
          <button type="button" class="login-face-x" :aria-label="$t('Close')" @click="closeFace"><svg viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg></button>
          <span class="login-face-badge" :class="{ 'is-err': faceError }" aria-hidden="true">
            <svg v-if="!faceError" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8V6a2 2 0 012-2h2M16 4h2a2 2 0 012 2v2M20 16v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2" /><path d="M9 10v1.5M15 10v1.5M12 10v4h-1M9 16.2c1.7 1.2 4.3 1.2 6 0" /></svg>
            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5M12 16.5v.01" /><path d="M10.3 3.9L2.4 17.5A2 2 0 004.1 20.5h15.8a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z" /></svg>
          </span>
          <h3>{{ faceError ? faceErrorTitle : $t('Face ID Login') }}</h3>
          <p class="login-face-user"><span>@{{ form.username }}</span></p>
          <face-scanner v-if="facePlan" :key="faceKey" :plan="facePlan" :stage="faceStage" :stage-text="faceStageText" @done="faceDone" />
          <div v-else-if="!faceError" class="login-face-wait"><span></span>{{ $t('Preparing...') }}</div>
          <p v-if="faceError" class="login-face-err" :class="'is-' + faceErrorKind" role="alert">{{ faceError }}</p>
          <div v-if="faceError" class="login-face-actions">
            <button type="button" class="login-face-retry" @click="openFace"><svg viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.1A7 7 0 0117 10a1 1 0 11-2 0 5 5 0 00-9.5-2.2H8a1 1 0 110 2H3a1 1 0 01-1-1V3a1 1 0 011-1zM3 10a1 1 0 112 0 5 5 0 009.5 2.2H12a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.1A7 7 0 013 10z" clip-rule="evenodd" /></svg>{{ $t('Try again') }}</button>
            <button type="button" class="login-face-pw" @click="closeFace">{{ $t('Use my password') }}</button>
          </div>
          <p v-else class="login-face-note">{{ $t('Your face is turned into numbers on this device. No photo is sent.') }}</p>
        </div>
      </div>
    </teleport>

    <prayer-lock-notice v-if="prayerNotice" :notice="prayerNotice" :show="showPrayerPrompt" @close="showPrayerPrompt = false" />

    <gate-reveal :show="justLoggedOut" />
    </template>
  </auth-shell>
</template>

<script>
import guestKeepalive from '@/Core/guestKeepalive';
import environmentTrigger from '@/Core/environmentTrigger';
import TecInput from '@/Jetstream/Input.vue';
import TecLabel from '@/Jetstream/Label.vue';
import TecCheckbox from '@/Jetstream/Checkbox.vue';
import TecDialogModal from '@/Jetstream/DialogModal.vue';
import LoadingButton from '@/Shared/LoadingButton.vue';
import TecValidationErrors from '@/Jetstream/ValidationErrors.vue';
import GateReveal from '@/Shared/GateReveal.vue';
import AuthShell from '@/Shared/Auth/AuthShell.vue';
import FaceScanner from '@/Shared/Face/FaceScanner.vue';
import PrayerLockNotice from '@/Shared/Prayer/PrayerLockNotice.vue';

const LOGIN_LOCK_KEY = 'wims_login_lock_until';
const LOGIN_FAIL_COUNT_KEY = 'wims_login_fail_count';

export default {
  mixins: [guestKeepalive(['challenge']), environmentTrigger],

  components: {
    TecLabel,
    TecInput,
    TecCheckbox,
    TecDialogModal,
    LoadingButton,
    TecValidationErrors,
    GateReveal,
    AuthShell,
    FaceScanner,
    PrayerLockNotice,
  },

  props: {
    canResetPassword: Boolean,
    status: String,
    challenge: {
      type: Object,
      default: () => ({ a: 0, b: 0, token: '' }),
    },
    justLoggedOut: { type: Boolean, default: false },
    faceLoginEnabled: { type: Boolean, default: false },
    prayerNotice: { type: Object, default: null },
  },

  mounted() {
    // A device that has used face login before starts downloading the face scanner right away, so the scan opens instantly.
    // (a browser that holds a Face ID device key is the only kind that can sign in with a face, so only it downloads the scanner)
    if (this.faceLoginEnabled) {
      const idle = window.requestIdleCallback || (f => setTimeout(f, 900));
      idle(async () => {
        try {
          const dev = await import('@/Shared/Face/faceDevice');
          if (!dev.supported() || !(await dev.knownIds()).length) return;
          const m = await import('@/Shared/Face/faceEngine');
          m.loadEngine().catch(() => {});
        } catch (e) { /* no storage or no support: the scan will load it when needed */ }
      });
    }
  },

  created() {
    document.title = this.$t('Login') + ' • ' + this.$settings.name;
  },

  data() {
    return {
      form: this.$inertia.form({
        username: this.$page.props.demo == 1 ? 'admin' : '',
        password: this.$page.props.demo == 1 ? 'amI$tup!D?n0' : '',
        remember: false,
        hp_check: '',
        challenge_answer: '',
      }),
      failedAttempts: 0,
      forceShowChallenge: false,
      lockUntil: null,
      remainingLockSeconds: 0,
      lockInterval: null,
      showMaintenancePrompt: false,
      showDisabledPrompt: false,
      showLicencePrompt: false,
      licenceNoticeMessage: '',
      disabledNoticeMessage: this.$t('Your account has been put on hold. Please contact your administrator.'),
      // A dedicated login-logo.png (Settings → Branding) takes priority; if
      // one was never uploaded, this falls back to the shared app logo used
      // everywhere else (print letterheads, etc.) so the page never shows
      // a broken image.
      loginLogoFailed: false,
      showPrayerPrompt: !!this.prayerNotice,
      faceOpen: false, faceChecking: false, faceHint: '', faceHintTimer: null, faceErrorKind: 'bad', facePlan: null, faceKey: 0, faceStage: '', faceStageText: '', faceNonce: '', faceDeviceId: '', faceError: '', faceErrorTitle: '',
    };
  },

  computed: {
    loginLogoUrl() {
      return this.loginLogoFailed ? '/images/logo.png' : '/images/login-logo.png';
    },
    isLocked() {
      return this.remainingLockSeconds > 0;
    },
    // Reveals the verification challenge after this browser has seen a
    // failed attempt (failedAttempts, persisted in localStorage so it
    // survives a reload), or immediately if the server says it's required
    // (forceShowChallenge — covers the case where earlier failures on this
    // account happened from a different browser/device, so this browser's
    // own counter is still at 0).
    showChallenge() {
      return this.failedAttempts >= 1 || this.forceShowChallenge;
    },
    lockMessage() {
      return `Too many failed login attempts. Please wait {${this.remainingLockSeconds}} seconds.`;
    },
    maintenanceNoticeMessage() {
      const hasMaintenanceQuery = typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('maintenance') === '1';
      return (
        this.$page.props.flash?.maintenance_notice ||
        (hasMaintenanceQuery ? this.$t('System is currently under maintenance. Access is temporarily limited. Please try again later.') : null) ||
        this.$page.props.flash?.error ||
        this.$t('System is currently under maintenance. Please try again later.')
      );
    },
  },

  mounted() {
    document.body.classList.add('spinner-normal');
    this.restoreLockState();
    const hasMaintenanceQuery = new URLSearchParams(window.location.search).get('maintenance') === '1';
    if (hasMaintenanceQuery) {
      this.showMaintenancePrompt = true;
    }
  },

  watch: {
    '$page.props.flash.licence_notice': {
      immediate: true,
      handler(value) {
        if (value) {
          this.licenceNoticeMessage = value;
          this.showLicencePrompt = true;
        }
      },
    },
    '$page.props.flash.maintenance_notice': {
      immediate: true,
      handler(value) {
        if (value) {
          this.showMaintenancePrompt = true;
        }
      },
    },
  },

  unmounted() {
    document.body.classList.remove('spinner-normal');
    this.clearLockInterval();
  },

  methods: {
    // a soft light ripple from where the Login button was pressed
    ripple(e) {
      const b = e.currentTarget;
      if (!b || b.disabled) return;
      const r = b.getBoundingClientRect();
      const d = Math.max(r.width, r.height) * 2;
      const s = document.createElement('span');
      s.className = 'login-ripple';
      s.style.cssText = `width:${d}px;height:${d}px;left:${e.clientX - r.left - d / 2}px;top:${e.clientY - r.top - d / 2}px`;
      b.appendChild(s);
      setTimeout(() => s.remove(), 750);
    },
    // ---- Face login: username first, then the scan (blink / turn your head, chosen by the server) ----
    // a POST that quietly gets a fresh security token and tries once more when the page was open for long (error 419)
    async postFresh(name, payload) {
      try {
        return await axios.post(route(name), payload);
      } catch (e) {
        if (e?.response?.status !== 419) throw e;
        await axios.get('/sanctum/csrf-cookie');
        return await axios.post(route(name), payload);
      }
    },
    faceMessage(e, fallback) {
      const st = e?.response?.status;
      if (st === 419) return { kind: 'info', title: this.$t('Page refreshed'), text: this.$t('This page was open for a while, so it was refreshed. Please try again.') };
      if (!e?.response) return { kind: 'bad', title: this.$t('No connection'), text: this.$t('Could not reach WIMS. Check your internet connection and try again.') };
      if (st === 429) return { kind: 'info', title: this.$t('Please wait a moment'), text: e.response.data?.message || this.$t('Too many tries. Wait a moment, or sign in with your password.') };
      return { kind: 'bad', title: this.$t('Face ID could not continue'), text: e.response.data?.message || fallback };
    },
    showFaceHint(text) {
      this.faceHint = text;
      clearTimeout(this.faceHintTimer);
      this.faceHintTimer = setTimeout(() => (this.faceHint = ''), 8000);
    },
    // ---- Face login: username first, then the scan (blink / turn your head, chosen by the server) ----
    async openFace() {
      if (!String(this.form.username || '').trim()) {
        this.form.errors.username = this.$t('Type your username first, then use your face.');
        document.getElementById('username')?.focus();
        return;
      }
      this.form.clearErrors();
      this.faceHint = '';
      const retry = this.faceOpen; // pressed "Try again" inside the box
      if (retry) { this.facePlan = null; this.faceError = ''; this.faceStage = ''; } else this.faceChecking = true;
      try {
        const dev = await import('@/Shared/Face/faceDevice');
        if (!dev.supported()) { this.faceOpen = false; this.showFaceHint(this.$t('Face ID needs a secure (https) page and a current browser.')); return; }
        const { data } = await this.postFresh('face-login.challenge', { username: this.form.username.trim(), device_ids: await dev.knownIds() });
        this.faceNonce = data.nonce; this.faceDeviceId = data.device_id;
        if (!(await dev.hasKey(data.device_id))) { this.faceOpen = false; this.showFaceHint(this.$t('Face ID is not set up on this device. Sign in with your password, then open your profile > Face Login and press "Use Face ID on this device".')); return; }
        // front, then the server's blinks on cue (and one head turn when the sign-in looks unusual)
        this.facePlan = [{ k: 'front', capture: 'front' }, ...data.plan.map(p => ({ k: p.k, delay: p.delay, capture: 'front' }))];
        this.faceKey++;
        this.faceOpen = true;
      } catch (e) {
        const d = e?.response?.data;
        if (d?.code === 'no_face' || d?.code === 'no_device' || d?.code === 'locked') {
          // no Face ID for this username: the scanner never opens, the person is told right under the button
          this.faceOpen = false; this.facePlan = null; this.faceError = '';
          this.showFaceHint(d.message);
        } else {
          const m = this.faceMessage(e, this.$t('Face login is not available right now. Sign in with your password.'));
          this.faceOpen = true; this.facePlan = null; this.faceErrorKind = m.kind; this.faceErrorTitle = m.title; this.faceError = m.text;
        }
      } finally {
        this.faceChecking = false;
      }
    },
    closeFace() { this.faceOpen = false; this.facePlan = null; this.faceError = ''; this.faceStage = ''; },
    async faceDone({ frames, telemetry }) {
      const started = Date.now();
      this.faceStage = 'verifying'; this.faceStageText = '';
      const pause = ms => new Promise(r => setTimeout(r, ms));
      try {
        // everything the scan saw goes in one text, signed with this device's private key (it never leaves the browser)
        const username = this.form.username.trim();
        const payload = JSON.stringify({ frames, telemetry });
        const dev = await import('@/Shared/Face/faceDevice');
        const signature = await dev.sign(this.faceDeviceId, 'wims-face-v1|' + this.faceNonce + '|' + username.toLowerCase() + '|' + payload);
        const { data } = await this.postFresh('face-login.verify', { username, nonce: this.faceNonce, device_id: this.faceDeviceId, payload, signature, remember: !!this.form.remember });
        try { localStorage.setItem('wims_face_device', '1'); } catch (e) { /* no storage */ }
        await pause(Math.max(0, 1500 - (Date.now() - started))); // let the verifying animation play, even on a fast answer
        this.faceStage = 'ok'; this.faceStageText = this.$t('Welcome back');
        await pause(1100);
        window.location.href = data.redirect || '/';
      } catch (e) {
        await pause(Math.max(0, 1200 - (Date.now() - started)));
        this.faceStage = 'bad'; this.faceStageText = this.$t('Not recognised');
        await pause(1400);
        this.facePlan = null; this.faceStage = '';
        const m = this.faceMessage(e, this.$t('Face not recognised. Try again, or sign in with your password.'));
        this.faceErrorKind = m.kind; this.faceErrorTitle = e?.response?.status === 422 ? this.$t('Face not recognised') : m.title; this.faceError = m.text;
      }
    },
    persistLockState() {
      if (this.lockUntil) {
        localStorage.setItem(LOGIN_LOCK_KEY, String(this.lockUntil));
      } else {
        localStorage.removeItem(LOGIN_LOCK_KEY);
      }

      localStorage.setItem(LOGIN_FAIL_COUNT_KEY, String(this.failedAttempts));
    },
    restoreLockState() {
      const savedLockUntil = Number(localStorage.getItem(LOGIN_LOCK_KEY) || 0);
      const savedFailCount = Number(localStorage.getItem(LOGIN_FAIL_COUNT_KEY) || 0);
      this.failedAttempts = Number.isNaN(savedFailCount) ? 0 : savedFailCount;

      if (savedLockUntil > Date.now()) {
        const remaining = Math.ceil((savedLockUntil - Date.now()) / 1000);
        this.startLock(remaining);
      } else {
        this.failedAttempts = 0;
        this.lockUntil = null;
        this.remainingLockSeconds = 0;
        localStorage.removeItem(LOGIN_LOCK_KEY);
        localStorage.removeItem(LOGIN_FAIL_COUNT_KEY);
      }
    },
    clearLockInterval() {
      if (this.lockInterval) {
        clearInterval(this.lockInterval);
        this.lockInterval = null;
      }
    },
    startLock(seconds = 60) {
      this.lockUntil = Date.now() + seconds * 1000;
      this.remainingLockSeconds = seconds;
      this.clearLockInterval();
      this.persistLockState();

      this.lockInterval = setInterval(() => {
        const remaining = Math.ceil((this.lockUntil - Date.now()) / 1000);
        this.remainingLockSeconds = Math.max(0, remaining);

        if (this.remainingLockSeconds <= 0) {
          this.clearLockInterval();
          this.failedAttempts = 0;
          this.lockUntil = null;
          this.persistLockState();
        }
      }, 250);
    },
    extractRetryAfterSeconds(errors) {
      const usernameErrors = errors?.username || [];
      const firstMessage = Array.isArray(usernameErrors) ? usernameErrors[0] : usernameErrors;
      const match = String(firstMessage || '').match(/(\d+)/);
      return match ? Number(match[1]) : null;
    },
    isCredentialError(errors) {
      return Boolean(errors?.username || errors?.password);
    },
    isLicenceError(errors) {
      const all = [...(Array.isArray(errors?.username) ? errors.username : errors?.username ? [errors.username] : []), ...(Array.isArray(errors?.password) ? errors.password : errors?.password ? [errors.password] : [])];
      const match = all.find(msg => String(msg || '').toLowerCase().includes('licence'));
      if (match) {
        this.licenceNoticeMessage = match;
      }
      return Boolean(match);
    },
    isMaintenanceError(errors) {
      const usernameErrors = Array.isArray(errors?.username) ? errors.username : errors?.username ? [errors.username] : [];
      const passwordErrors = Array.isArray(errors?.password) ? errors.password : errors?.password ? [errors.password] : [];
      const all = [...usernameErrors, ...passwordErrors].map(v => String(v || '').toLowerCase());
      return all.some(msg => msg.includes('maintenance') || msg.includes('restricted'));
    },
    isDisabledError(errors) {
      const usernameErrors = Array.isArray(errors?.username) ? errors.username : errors?.username ? [errors.username] : [];
      const passwordErrors = Array.isArray(errors?.password) ? errors.password : errors?.password ? [errors.password] : [];
      const all = [...usernameErrors, ...passwordErrors];
      const match = all.find(msg => String(msg || '').toLowerCase().includes('put on hold'));
      if (match) {
        this.disabledNoticeMessage = match;
      }
      return Boolean(match);
    },
    isVerificationRequiredError(errors) {
      const usernameErrors = Array.isArray(errors?.username) ? errors.username : errors?.username ? [errors.username] : [];
      return usernameErrors.some(msg => String(msg || '').toLowerCase().includes('verification question'));
    },
    async submit() {
      if (this.isLocked) {
        return;
      }
      // the verification question the person is looking at and has just answered: it must travel with its OWN token. The refresh below reloads the
      // `challenge` prop (a new question and token), so reading the token after it would pair the answer with a different question.
      const shownChallenge = { ...this.challenge };
      // a page left open for a while holds an old security token: get a fresh one first, so Sign in never ends on "Session Expired"
      await this.$guestKeepalive.ensureFresh(0); // always: one tiny request, and the session may have ended at any moment

      this.form
        .transform(data => ({
          ...data,
          remember: this.form.remember ? 'on' : '',
          // The token of the question that was on screen when Sign in was pressed (taken before the refresh above), so the answer typed
          // and the token sent are always for the same question.
          challenge_token: shownChallenge.token,
        }))
        .post(this.route('login'), {
          onSuccess: () => {
            this.failedAttempts = 0;
            this.clearLockInterval();
            this.remainingLockSeconds = 0;
            this.lockUntil = null;
            this.persistLockState();
          },
          onError: errors => {
            if (this.isVerificationRequiredError(errors)) {
              this.forceShowChallenge = true;
            }
            const retryAfter = this.extractRetryAfterSeconds(errors);

            if (this.isLicenceError(errors)) {
              this.showLicencePrompt = true;
              return;
            }

            if (Object.values(errors || {}).flat().some(m => String(m || '').toLowerCase().includes('prayer break'))) {
              if (this.prayerNotice) this.showPrayerPrompt = true;
              else this.$inertia.reload({ only: ['prayerNotice'], onSuccess: () => (this.showPrayerPrompt = true) });
              return;
            }

            if (this.isDisabledError(errors)) {
              this.showDisabledPrompt = true;
              return;
            }

            if (this.isMaintenanceError(errors)) {
              this.showMaintenancePrompt = true;
              return;
            }

            if (retryAfter) {
              this.startLock(Math.max(60, retryAfter));
              return;
            }

            if (this.isCredentialError(errors)) {
              this.failedAttempts += 1;
              this.persistLockState();
              if (this.failedAttempts >= 5) {
                this.startLock(60);
              }
            }
          },
          onFinish: () => this.form.reset('password', 'challenge_answer'),
        });
    },
  },
};
</script>

<style scoped>
.login-logo {
  animation: login-logo-float 5s ease-in-out infinite;
}

@keyframes login-logo-float {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-4px); }
}

.login-tagline { display: inline-flex; flex-wrap: wrap; align-items: baseline; gap: 0 0.3em; }
.login-tagline-word,
.login-tagline-brand {
  display: inline-block;
  opacity: 0;
  animation: login-tagline-in 0.5s ease-out both;
}
.login-tagline-sep { color: #94a3b8; margin-right: 0.1em; }
.login-tagline-brand {
  margin-left: 0.2em;
  font-weight: 700;
  background: linear-gradient(90deg, #0ea5e9, #6366f1, #ec4899, #0ea5e9);
  background-size: 250% 100%;
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
  animation: login-tagline-in 0.5s ease-out both, login-tagline-shimmer 5s linear 1.4s infinite;
}

@keyframes login-tagline-in {
  0% { opacity: 0; transform: translateY(6px); }
  100% { opacity: 1; transform: translateY(0); }
}

@keyframes login-tagline-shimmer {
  0% { background-position: 0% 0; }
  100% { background-position: 250% 0; }
}

@media (prefers-reduced-motion: reduce) {
  .login-tagline-word,
  .login-tagline-brand,
  .login-footer-credit {
    animation: none;
    opacity: 1;
  }
}

.login-footer-credit {
  opacity: 0;
  animation: login-footer-in 0.6s ease-out 0.9s both, login-footer-breathe 4s ease-in-out 1.5s infinite;
}

@keyframes login-footer-in {
  0% { opacity: 0; transform: translateY(4px); }
  100% { opacity: 1; transform: translateY(0); }
}

@keyframes login-footer-breathe {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.55; }
}

.login-honeypot {
  position: absolute;
  left: -9999px;
  top: -9999px;
  height: 0;
  width: 0;
  overflow: hidden;
}

@media (prefers-reduced-motion: reduce) {
  .login-logo { animation: none; }
}
</style>

<style>
.login-or { display: flex; align-items: center; gap: 0.8rem; margin: 1rem 0 0.8rem; color: #94a3b8; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; }
.login-or::before, .login-or::after { content: ''; flex: 1; height: 1px; background: rgba(148, 163, 184, 0.4); }
.login-face-overlay { position: fixed; inset: 0; z-index: 9999; display: grid; place-items: center; padding: 1rem; background: rgba(8, 14, 28, 0.62); backdrop-filter: blur(5px); animation: lf-fade 0.25s ease both; }
.login-face-card { position: relative; width: min(400px, 100%); padding: 1.5rem 1.2rem 1.2rem; border-radius: 24px; text-align: center; background: #fff; color: #0f172a; box-shadow: 0 30px 80px rgba(2, 6, 23, 0.5); animation: lf-pop 0.4s cubic-bezier(0.2, 1.3, 0.4, 1) both; }
.login-face-card h3 { margin: 0; font-size: 1.15rem; font-weight: 800; } .login-face-user { margin: 0.35rem 0 0.8rem; } .login-face-user span { display: inline-block; padding: 0.15rem 0.7rem; border-radius: 99px; font-size: 0.8rem; font-weight: 700; color: #475569; background: #f1f5f9; }
.login-face-badge { display: grid; place-items: center; width: 52px; height: 52px; margin: 0 auto 0.6rem; border-radius: 16px; color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); box-shadow: 0 10px 20px -10px rgba(15, 37, 64, 0.8); } .login-face-badge svg { width: 28px; height: 28px; } .login-face-badge.is-err { background: linear-gradient(145deg, #fb7185, #be123c); box-shadow: 0 10px 20px -10px rgba(190, 18, 60, 0.8); animation: lf-shake 0.45s both; }
.login-face-x { position: absolute; top: 0.7rem; right: 0.7rem; display: grid; place-items: center; width: 32px; height: 32px; border-radius: 50%; border: 0; background: transparent; color: #64748b; cursor: pointer; } .login-face-x:hover { background: rgba(100, 116, 139, 0.15); }
.login-face-note { margin: 0.8rem 0 0; font-size: 0.74rem; color: #94a3b8; }
.login-face-err { margin: 0.2rem 0 0; padding: 0.7rem 0.9rem; border-radius: 14px; font-size: 0.86rem; font-weight: 600; line-height: 1.45; color: #9f1239; background: #fff1f2; box-shadow: inset 0 0 0 1px #fecdd3; } .login-face-err.is-info { color: #92400e; background: #fffbeb; box-shadow: inset 0 0 0 1px #fde68a; }
.login-face-actions { display: flex; flex-wrap: wrap; gap: 0.6rem; justify-content: center; margin-top: 0.9rem; }
.login-face-retry, .login-face-pw { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.55rem 1rem; border-radius: 12px; font-weight: 800; font-size: 0.85rem; cursor: pointer; border: 0; }
.login-face-retry { color: #fff; background: linear-gradient(135deg, #4f46e5, #2563eb); } .login-face-pw { color: #334155; background: #f1f5f9; }
.login-face-wait { display: flex; align-items: center; justify-content: center; gap: 0.6rem; min-height: 220px; color: #64748b; font-weight: 700; } .login-face-wait span { width: 22px; height: 22px; border-radius: 50%; border: 3px solid rgba(99, 102, 241, 0.25); border-top-color: #6366f1; animation: lf-spin 0.8s linear infinite; }
@keyframes lf-fade { from { opacity: 0; } to { opacity: 1; } } @keyframes lf-pop { from { opacity: 0; transform: translateY(16px) scale(0.94); } to { opacity: 1; transform: none; } } @keyframes lf-spin { to { transform: rotate(360deg); } }
@keyframes lf-shake { 20%, 60% { transform: translateX(-5px); } 40%, 80% { transform: translateX(5px); } }

/* Login button: a moving sheen, a press ripple, and a flowing glow while it signs you in */
.auth-shell .login-submit { position: relative; overflow: hidden; background: linear-gradient(115deg, #1f2430 0%, #2b3a67 45%, #1f2430 100%) !important; background-size: 220% 100% !important; background-position: 0 0 !important; transition: transform 0.18s ease, box-shadow 0.25s ease, background-position 0.6s ease !important; }
.auth-shell .login-submit::before { content: ''; position: absolute; top: 0; bottom: 0; left: -60%; width: 40%; transform: skewX(-20deg); background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.28), transparent); transition: left 0.7s ease; pointer-events: none; }
.auth-shell .login-submit:hover:not(:disabled) { background-position: 100% 0 !important; box-shadow: 0 18px 30px -12px rgba(43, 58, 103, 0.85), 0 0 0 1px rgba(129, 140, 248, 0.45) !important; }
.auth-shell .login-submit:hover:not(:disabled)::before { left: 130%; }
.login-submit .login-arrow { transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1); }
.auth-shell .login-submit:hover:not(:disabled) .login-arrow { transform: translateX(4px); }
.auth-shell .login-submit:active:not(:disabled) { transform: scale(0.975) !important; }
.auth-shell .login-submit:active:not(:disabled) .login-arrow { transform: translateX(10px); }
.login-ripple { position: absolute; border-radius: 50%; background: radial-gradient(circle, rgba(255, 255, 255, 0.55), rgba(255, 255, 255, 0) 70%); transform: scale(0); animation: login-ripple 0.7s ease-out forwards; pointer-events: none; }
@keyframes login-ripple { to { transform: scale(1); opacity: 0; } }
.auth-shell .login-submit.is-busy { opacity: 1 !important; background-image: linear-gradient(110deg, #1f2430 0%, #3b4f94 25%, #6d7fe6 50%, #3b4f94 75%, #1f2430 100%) !important; background-size: 300% 100% !important; animation: login-flow 1.1s linear infinite; box-shadow: 0 0 0 1px rgba(129, 140, 248, 0.6), 0 0 22px rgba(99, 102, 241, 0.55) !important; }
.auth-shell .login-submit.is-busy .login-arrow { animation: login-arrow 0.7s ease-in-out infinite; }
@keyframes login-flow { to { background-position: -300% 0; } }
@keyframes login-arrow { 0%, 100% { transform: translateX(0); opacity: 0.6; } 50% { transform: translateX(7px); opacity: 1; } }

/* Face ID Login button */
.login-face-btn { box-shadow: 0 12px 24px -14px rgba(67, 56, 202, 0.7), 0 3px 8px rgba(15, 37, 64, 0.14); position: relative; overflow: hidden; display: flex; align-items: center; justify-content: center; gap: 0.7rem; width: 100%; padding: 0.72rem 1rem; border-radius: 14px; font-weight: 800; font-size: 0.9rem; letter-spacing: 0.04em; color: #0f2540; border: 1.5px solid transparent; background: linear-gradient(#f4f7ff, #eaf0ff) padding-box, conic-gradient(from var(--lic-angle, 0deg), #22d3ee, #6366f1, #facc15, #34d399, #22d3ee) border-box; cursor: pointer; transition: transform 0.2s ease, box-shadow 0.25s ease; animation: login-face-spin 5s linear infinite; }
@property --lic-angle { syntax: '<angle>'; initial-value: 0deg; inherits: false; }
@keyframes login-face-spin { to { --lic-angle: 360deg; } }
.login-face-btn:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 16px 30px -14px rgba(67, 56, 202, 0.9), 0 0 18px rgba(34, 211, 238, 0.35); }
.login-face-btn.is-checking { cursor: progress; opacity: 0.85; }
.login-face-hint { display: flex; align-items: flex-start; gap: 0.5rem; margin: 0.6rem 0 0; padding: 0.6rem 0.8rem; border-radius: 12px; font-size: 0.8rem; font-weight: 600; line-height: 1.4; color: #92400e; background: #fffbeb; box-shadow: inset 0 0 0 1px #fde68a, 0 8px 18px -14px rgba(180, 83, 9, 0.7); } .login-face-hint svg { flex: none; margin-top: 1px; color: #d97706; }
.lf-hint-enter-active, .lf-hint-leave-active { transition: opacity 0.25s, transform 0.25s; } .lf-hint-enter-from, .lf-hint-leave-to { opacity: 0; transform: translateY(-6px); }
.login-face-btn:active:not(:disabled) { transform: scale(0.98); }
.login-face-btn:disabled { opacity: 0.55; cursor: not-allowed; animation: none; }
.login-face-ico { position: relative; display: grid; place-items: center; width: 30px; height: 30px; border-radius: 10px; color: #fff; background: linear-gradient(145deg, #2f6fb3, #0f2540); box-shadow: 0 6px 12px -6px rgba(15, 37, 64, 0.9); overflow: hidden; } .login-face-ico svg { width: 20px; height: 20px; }
.login-face-scan { position: absolute; left: 3px; right: 3px; height: 2px; border-radius: 2px; background: linear-gradient(90deg, transparent, #67e8f9, transparent); box-shadow: 0 0 6px #22d3ee; animation: login-face-scan 2s ease-in-out infinite; }
@keyframes login-face-scan { 0%, 100% { top: 4px; opacity: 0; } 20%, 80% { opacity: 1; } 50% { top: 24px; } }
.login-face-shine { position: absolute; top: 0; bottom: 0; left: -50%; width: 30%; transform: skewX(-20deg); background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.7), transparent); animation: login-face-shine 3.6s ease-in-out infinite; pointer-events: none; }
@keyframes login-face-shine { 0%, 60% { left: -50%; } 100% { left: 140%; } }
@media (prefers-reduced-motion: reduce) { .login-face-btn, .login-face-scan, .login-face-shine, .auth-shell .login-submit.is-busy, .auth-shell .login-submit.is-busy .login-arrow { animation: none !important; } }
</style>



<template>
  <div class="rounded-lg bg-amber-50 border border-amber-200 p-6 text-center">
    <icons name="wifi" class="w-6 h-6 text-amber-600 mx-auto mb-2" />
    <p class="font-semibold text-amber-800">{{ $t('This report requires an active connection.') }}</p>
    <p class="text-sm text-amber-700 mt-1">{{ $t('Reconnect to WIMS to view or generate this report.') }}</p>
  </div>
</template>

<script>
// Guards the case of going offline while already viewing a report page, or
// loading a bookmarked report URL while offline via a cached app shell.
// Reports are explicitly out of scope for real offline functionality (see
// the plan's deferred list) — this is the honest "can't do this here"
// message rather than attempting a broken partial render, per spec §22.
export default {};
</script>

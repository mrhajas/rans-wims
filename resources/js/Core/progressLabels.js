// What the centred progress pill says while a request is sent, when it is done, and when it failed. "Saving" is only right for a save: signing in says "Logging in",
// the second step says "Verifying", a password reset says "Resetting credentials", an approval says "Approving", and so on. The first matching rule wins; a page can still
// override all three with the X-Progress-Label / X-Progress-Done headers.
import i18n from '@/Core/i18n';

const t = key => {
  try {
    return i18n.global.t(key);
  } catch (e) {
    return key;
  }
};

// [what the path must match, working, done, failed, options]
const RULES = [
  // signing in and out
  [/^\/login$/, 'Logging in', 'Logged in', 'Login failed', { auth: true }],
  [/^\/logout$/, 'Logging out', 'Logged out', 'Could not log out'],
  [/^\/two-factor-challenge\/send$/, 'Sending the code', 'Code sent', 'Code not sent', { auth: true }],
  [/^\/two-factor-challenge$/, 'Verifying', 'Verified', 'Verification failed', { auth: true }],
  [/^\/face-login/, 'Verifying your face', 'Verified', 'Verification failed', { auth: true }],
  [/^\/forgot-password$/, 'Sending the reset link', 'Reset link sent', 'Link not sent', { auth: true }],
  [/^\/reset-password$/, 'Resetting credentials', 'Credentials reset', 'Reset failed', { auth: true }],
  [/^\/user\/password$/, 'Updating the password', 'Password updated', 'Password not updated'],
  [/^\/(user\/)?confirm-password$/, 'Confirming', 'Confirmed', 'Not confirmed'],
  [/^\/user\/two-factor/, 'Updating the two-step setup', 'Two-step updated', 'Not updated'],
  [/^\/user\/other-browser-sessions$/, 'Signing out other devices', 'Other devices signed out', 'Not signed out'],
  [/^\/language\//, 'Changing the language', 'Language changed', 'Not changed'],
  [/^\/current-warehouse|\/current\/warehouse/, 'Switching', 'Switched', 'Not switched'],
  // actions by the last words of the path
  [/\/(approve|authorise|authorize)(\/|$)/, 'Approving', 'Approved', 'Not approved'],
  [/\/reject(\/|$)/, 'Rejecting', 'Rejected', 'Not rejected'],
  [/\/(cancel|void)(\/|$)/, 'Cancelling', 'Cancelled', 'Not cancelled'],
  [/\/(restore|reinstate)(\/|$)/, 'Restoring', 'Restored', 'Not restored'],
  [/\/submit(\/|$)/, 'Submitting', 'Submitted', 'Not submitted'],
  [/\/import(\/|$)/, 'Importing', 'Imported', 'Import failed'],
  [/\/(send|resend)(\/|$)/, 'Sending', 'Sent', 'Not sent'],
  [/\/verify(\/|$)/, 'Verifying', 'Verified', 'Verification failed'],
  [/\/retry(\/|$)/, 'Retrying', 'Done', 'Failed'],
  [/\/reverse(\/|$)/, 'Reversing', 'Reversed', 'Not reversed'],
  [/\/acknowledge(\/|$)/, 'Acknowledging', 'Acknowledged', 'Not acknowledged'],
  [/\/(assign|reassign)(\/|$)/, 'Assigning', 'Assigned', 'Not assigned'],
  [/\/duplicate(\/|$)/, 'Duplicating', 'Duplicated', 'Not duplicated'],
  [/\/(pay|payments?)(\/|$)/, 'Recording the payment', 'Payment recorded', 'Payment not recorded'],
  [/\/receive(\/|$)/, 'Receiving', 'Received', 'Not received'],
  [/\/reopen(\/|$)/, 'Reopening', 'Reopened', 'Not reopened'],
  [/\/unlock(\/|$)/, 'Unlocking', 'Unlocked', 'Not unlocked'],
  [/\/(read|read-all)(\/|$)/, 'Marking as read', 'Marked as read', 'Not marked'],
  [/\/resolve(\/|$)/, 'Resolving', 'Resolved', 'Not resolved'],
  [/\/escalate(\/|$)/, 'Escalating', 'Escalated', 'Not escalated'],
  [/\/reorder(\/|$)/, 'Reordering', 'Reordered', 'Not reordered'],
  [/\/(photo|dropzone|media|files|attachments?)(\/|$)/, 'Uploading', 'Uploaded', 'Upload failed'],
  [/\/apply(\/|$)/, 'Applying', 'Applied', 'Not applied'],
  [/\/(run|process)(\/|$)/, 'Running', 'Done', 'Not completed'],
  [/\/correct(\/|$)/, 'Correcting', 'Corrected', 'Not corrected'],
  [/\/move(\/|$)/, 'Moving', 'Moved', 'Not moved'],
  [/\/reset(\/|$)/, 'Resetting', 'Reset', 'Not reset'],
  [/\/(confirm|clock-in|clockin)(\/|$)/, 'Confirming', 'Confirmed', 'Not confirmed'],
  [/\/clock-out(\/|$)/, 'Clocking out', 'Clocked out', 'Not clocked out'],
  [/\/test(\/|$)/, 'Testing', 'Test finished', 'Test failed'],
  [/\/clear(\/|$)/, 'Clearing', 'Cleared', 'Not cleared'],
  [/\/post(\/|$)/, 'Posting', 'Posted', 'Not posted'],
  [/\/permanently(\/|$)/, 'Deleting for good', 'Deleted for good', 'Not deleted'],
];

/** @returns {{working:string, done:string, fail:string, auth:boolean}} */
export function describeRequest(pathname, method) {
  const path = String(pathname || '').split('?')[0].replace(/\/+$/, '') || '/';
  for (const [re, working, done, fail, opt] of RULES) {
    // a DELETE is always a deletion, whatever the words in its path
    if (method === 'delete' && !opt?.auth && !/permanently/.test(path) && !/\/(clear|reset)(\/|$)/.test(path)) continue;
    if (re.test(path)) return { working: t(working) + '...', done: t(done), fail: t(fail), auth: !!opt?.auth };
  }
  const base = { delete: ['Deleting', 'Deleted', 'Not deleted'], put: ['Updating', 'Updated', 'Not updated'], patch: ['Updating', 'Updated', 'Not updated'] }[method] || ['Saving', 'Saved', 'Not saved'];

  return { working: t(base[0]) + '...', done: t(base[1]), fail: t(base[2]), auth: false };
}

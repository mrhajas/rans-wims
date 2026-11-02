// Wording of the "This record is still a draft" prompt (Core/draftApprove.js) for the Subcontracts records and Compliance renewals. Same shape as Core/draftApprovals.js:
// `t` is the page's $t, `rec` the record the page holds, `extra` any text the page has already formatted (an amount in its currency, which the builder cannot format itself).
// These records pass through Submitted before they are approved, so "Yes" removes the draft status by submitting, then approves when the rules allow it. If the person
// also made or submitted the record, the separation-of-duties rule still applies and it stays submitted: every builder says so, so nobody is surprised afterwards.
const base = (t, extra) => ({ flow: [t('Draft'), t('Submitted'), t('Approved')], confirmText: t('Yes, approve'), cancelText: t('No, keep as draft'), ...extra });
const duty = t => t('If you made it yourself, it stays submitted and someone else approves it.');

export const draftApprovalsSubcontracts = {
  agreement: (t, a) => base(t, {
    record: a.reference,
    title: t('Draft agreements cannot be approved yet'),
    intro: t(':ref is still a draft, so it is not a payable commitment. It has to be submitted before it can be approved.', { ref: a.reference }),
    effects: [
      t('The draft status is removed and the agreement is submitted.'),
      t('Once approved, payments and variations can be requested against it and it can be made active.'),
      duty(t),
    ],
    question: t('Remove the draft status and approve this agreement?'),
    confirmText: t('Yes, approve agreement'),
  }),

  variation: (t, v, extra = {}) => base(t, {
    record: v.reference,
    title: t('Draft variations cannot be approved yet'),
    intro: t(':ref is still a draft and changes nothing on the agreement. It has to be submitted before it can be approved.', { ref: v.reference }),
    effects: [
      t('The draft status is removed and the variation is submitted.'),
      extra.amount
        ? (v.direction === 'reduction' ? t('Approving lowers the revised contract value by :amount.', { amount: extra.amount }) : t('Approving raises the revised contract value by :amount.', { amount: extra.amount }))
        : t('Approving changes the revised contract value of the agreement.'),
      duty(t),
    ],
    question: t('Remove the draft status and approve this variation?'),
    confirmText: t('Yes, approve variation'),
  }),

  request: (t, r, extra = {}) => base(t, {
    record: r.reference,
    title: t('Draft payment requests cannot be approved yet'),
    intro: t(':ref is still a draft, so nobody has been asked to approve it. It has to be submitted before it can be approved.', { ref: r.reference }),
    effects: [
      t('The draft status is removed and the request is submitted.'),
      extra.amount ? t('It is approved for the full amount requested, :amount.', { amount: extra.amount }) : t('It is approved for the full amount requested.'),
      t('If that is more than the contract still allows, it stays submitted and needs an approval with an override.'),
      duty(t),
    ],
    question: t('Remove the draft status and approve this request?'),
    confirmText: t('Yes, approve request'),
  }),

  // A claim is approved line by line, each with its own amount, reason and headroom check, so "Yes" only takes it out of draft and sends it for decision.
  claim: (t, c) => base(t, {
    record: c.reference,
    title: t('Draft claims cannot be decided yet'),
    intro: t(':ref is still a draft. A contract payment request is approved line by line, and only after it has been submitted.', { ref: c.reference }),
    flow: [t('Draft'), t('Submitted'), t('Lines decided')],
    effects: [
      t('The draft status is removed and the claim is submitted.'),
      t('You then approve or refuse each line on this page, with the amount to pay, so no line is approved without being looked at.'),
    ],
    question: t('Remove the draft status and submit this claim for decision?'),
    confirmText: t('Yes, submit for decision'),
  }),

  complianceRenewal: (t, rec) => base(t, {
    record: rec.compliance_number || rec.title,
    title: t('This renewal is still a draft'),
    intro: t('The renewal of :title has been started but not submitted, so there is no application to approve yet.', { title: rec.title }),
    flow: [t('In progress'), t('Submitted'), t('Approved')],
    effects: [
      t('The renewal is submitted now, without application details.'),
      t('It is then approved and waits for final sign-off, where you enter the new issue and expiry dates.'),
    ],
    question: t('Submit the renewal and approve it now?'),
    confirmText: t('Yes, approve renewal'),
  }),
};

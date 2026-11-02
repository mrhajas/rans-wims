// Wording of the "This record is still a draft" prompt for the finance side: Salary Periods, Labour Lists, Accounts (transactions, transfers,
// reconciliations), Project Certificates and Contract Addendums. Same shape as Core/draftApprovals.js: `t` is the page's $t, `rec` the record the page holds.
// Usage on a page:  if (await this.$draftApprove(financeDraftApprovals.salaryPeriod(this.$t, period))) this.$inertia.patch(url, { remove_draft: true });
const base = (t, extra) => ({ flow: [t('Draft'), t('Submitted'), t('Approved')], confirmText: t('Yes, approve'), cancelText: t('No, keep as draft'), ...extra });

export const financeDraftApprovals = {
  salaryPeriod: (t, p) => base(t, {
    record: p.label || p.batch_reference,
    title: t('Draft salary periods cannot be approved yet'),
    intro: t(':name is still a draft, so it has not been sent to an approver. It has to be submitted before it can be approved.', { name: p.label || p.batch_reference }),
    effects: [
      t('The draft status is removed and the period is submitted for approval.'),
      t('The salary records become payable once it is approved.'),
      t('The people on the Salary notification list are told.'),
    ],
    question: t('Remove the draft status and approve this salary period?'),
    confirmText: t('Yes, approve salary'),
  }),

  labourList: (t, l) => base(t, {
    record: l.reference,
    flow: [t('Draft'), t('Submitted'), t('Cleared'), t('Approved')],
    title: t('Draft labour lists cannot be approved yet'),
    intro: t(':ref is still a draft. A labour list is submitted, cleared by the Project Manager and then approved for payment, one step after another.', { ref: l.reference }),
    effects: [
      t('The draft status is removed and the list is submitted.'),
      t('It is cleared and approved for payment in the same go, as far as your rights allow.'),
      t('If a step needs someone else, it stops there and you are told what is still waiting.'),
    ],
    question: t('Remove the draft status and approve this labour list?'),
    confirmText: t('Yes, approve labour list'),
  }),

  acctTransaction: (t, x) => base(t, {
    record: x.reference || x.ref || '',
    title: t('Draft transactions cannot be approved yet'),
    intro: t(':ref is still a draft, so it has not been submitted. It has to be submitted before it can be approved.', { ref: x.reference || x.ref || t('This transaction') }),
    effects: [
      t('The draft status is removed and the transaction is submitted.'),
      t('The approval rules of the account are checked as usual.'),
      t('It can then be posted to the ledger.'),
    ],
    question: t('Remove the draft status and approve this transaction?'),
    confirmText: t('Yes, approve transaction'),
  }),

  acctTransfer: (t, x) => base(t, {
    record: x.reference || x.ref || '',
    title: t('Draft transfers cannot be approved yet'),
    intro: t(':ref is still a draft, so it has not been submitted. It has to be submitted before it can be approved.', { ref: x.reference || x.ref || t('This transfer') }),
    effects: [
      t('The draft status is removed and the transfer is submitted.'),
      t('The approval rules of the accounts are checked as usual.'),
      t('Money only moves between the accounts once it is posted.'),
    ],
    question: t('Remove the draft status and approve this transfer?'),
    confirmText: t('Yes, approve transfer'),
  }),

  acctReconciliation: (t, x) => base(t, {
    record: x.reference || x.ref || x.account_name || '',
    title: t('Draft reconciliations cannot be approved yet'),
    intro: t('This reconciliation is still a draft, so it has not been submitted. It has to be submitted before it can be approved.'),
    effects: [
      t('The draft status is removed and the reconciliation is submitted.'),
      t('Approving it confirms the statement balance agrees with the ledger.'),
    ],
    question: t('Remove the draft status and approve this reconciliation?'),
    confirmText: t('Yes, approve reconciliation'),
  }),

  projectCertificate: (t, c) => base(t, {
    record: c.reference || (c.certificate_number ? t('Certificate #:n', { n: c.certificate_number }) : ''),
    title: t('Draft certificates cannot be approved yet'),
    intro: t('This certificate is still a draft, so it has not been submitted. It has to be submitted before it can be approved.'),
    effects: [
      t('The draft status is removed and the certificate is submitted.'),
      t('Once approved it can be issued to the client.'),
    ],
    question: t('Remove the draft status and approve this certificate?'),
    confirmText: t('Yes, approve certificate'),
  }),

  contractAddendum: (t, a) => base(t, {
    flow: [t('Draft'), t('Pending approval'), t('Approved')],
    record: a.addendum_number ? t('Addendum #:n', { n: a.addendum_number }) + (a.title ? ' - ' + a.title : '') : a.title || '',
    title: t('Draft addendums cannot be approved yet'),
    intro: t('This addendum is still a draft, so it has not been sent for approval. It has to be submitted before it can be approved.'),
    effects: [
      t('The draft status is removed and the addendum is sent for approval.'),
      t('Once approved, its changes become part of the contract.'),
    ],
    question: t('Remove the draft status and approve this addendum?'),
    confirmText: t('Yes, approve addendum'),
  }),
};

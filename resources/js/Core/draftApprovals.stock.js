// Wording of the "This record is still a draft" prompt for the stock modules (see Core/draftApprove.js and Core/draftApprovals.js for the pattern).
// Only Material Reallocation needs one: Material Request, Adjustment and Super Adjustment have no draft that is blocked from approval (see each controller).
const base = (t, extra) => ({ flow: [t('Draft'), t('Final'), t('Approved')], confirmText: t('Yes, approve'), cancelText: t('No, keep as draft'), ...extra });

export const draftApprovalsStock = {
  /** The first approval of a reallocation is the Project Manager clearance; the later steps (allocation, dispatch, receiving, final approval) stay with their own roles. */
  materialReallocation: (t, mr) => base(t, {
    record: mr.reference,
    title: t('Draft reallocations cannot be cleared yet'),
    intro: t(':ref is still a draft that has not been sent for review, so it cannot be cleared for approval as it is.', { ref: mr.reference }),
    flow: [t('Draft'), t('Submitted'), t('Cleared')],
    effects: [
      t('The draft status is removed and the request is submitted for review.'),
      t('It is then cleared for approval at the Project Manager step.'),
      t('Warehouse Allocation, dispatch, receiving and final approval still follow, each by its own role. No stock moves yet.'),
    ],
    question: t('Remove the draft status and clear this reallocation for approval?'),
    confirmText: t('Yes, clear for approval'),
  }),
};

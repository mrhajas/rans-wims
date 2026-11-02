// Wording of the "This record is still a draft" prompt for the procurement and finance records: Purchase Request, Purchase Order, Payment Voucher and
// Fund Allocation (see Core/draftApprove.js). `t` is the page's $t; `rec` is the record the page already holds.
// Usage on a page:  if (await this.$draftApprove(draftApprovalsProcurement.purchaseOrder(this.$t, po))) this.form.transform(d => ({ ...d, remove_draft: true })).patch(url);
const base = (t, extra) => ({ flow: [t('Draft'), t('Submitted'), t('Approved')], confirmText: t('Yes, approve'), cancelText: t('No, keep as draft'), ...extra });

export const draftApprovalsProcurement = {
  purchaseRequest: (t, pr) => base(t, {
    record: pr.reference,
    title: t('Draft purchase requests cannot be approved yet'),
    intro: t(':ref is still a draft and has not been sent to the approvers. A draft has to be submitted before it can be approved.', { ref: pr.reference }),
    effects: [
      t('The draft status is removed and the request is submitted.'),
      pr.warehouse_name ? t('It is approved straight away, so quotations and orders can start for :project.', { project: pr.warehouse_name }) : t('It is approved straight away, so quotations and orders can start.'),
    ],
    question: t('Remove the draft status and approve this purchase request?'),
    confirmText: t('Yes, approve request'),
  }),

  purchaseOrder: (t, po) => base(t, {
    record: po.reference,
    title: t('Draft purchase orders cannot be approved yet'),
    intro: t(':ref is still a draft, so it has not been put forward for approval. A draft has to be submitted before it can be approved.', { ref: po.reference }),
    effects: [
      t('The draft status is removed and the order is submitted.'),
      po.supplier_name ? t('It is approved straight away and can then be sent to :supplier.', { supplier: po.supplier_name }) : t('It is approved straight away and can then be sent to the supplier.'),
    ],
    question: t('Remove the draft status and approve this purchase order?'),
    confirmText: t('Yes, approve order'),
  }),

  paymentVoucher: (t, pv) => base(t, {
    record: pv.pv_number,
    title: t('Draft payment vouchers cannot be approved yet'),
    intro: t(':ref is still a draft, so it has not been sent for review. A draft has to be submitted before the first stage can approve it.', { ref: pv.pv_number }),
    effects: [
      t('The draft status is removed and the voucher is submitted.'),
      t('The first stage (Approve) is signed by you straight away.'),
      t('The voucher then waits for authorisation, and no payment is recorded until it is authorised.'),
    ],
    question: t('Remove the draft status and approve this payment voucher?'),
    confirmText: t('Yes, approve voucher'),
  }),

  fundAllocation: (t, a) => base(t, {
    record: a.reference,
    title: t('Draft allocation sheets cannot be approved yet'),
    intro: t(':ref is still a draft, so it has not been submitted for approval. A draft has to be submitted before it can be approved.', { ref: a.reference }),
    flow: [t('Draft'), t('Pending approval'), t('Approved')],
    effects: [
      t('The sheet is checked and submitted, then approved in one step.'),
      t('You choose the payer or payers next, and they can then start ticking the payments.'),
    ],
    question: t('Remove the draft status and continue to approve this sheet?'),
    confirmText: t('Yes, choose payer'),
  }),
};

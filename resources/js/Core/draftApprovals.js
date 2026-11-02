import axios from 'axios';

// The wording of the "This record is still a draft" prompt for every module that has both Draft and Approve (see Core/draftApprove.js). One builder per
// module, so each record explains what approving it will do. `t` is the page's $t; `rec` is the record the page already holds.
// Usage on a page:  if (await this.$draftApprove(draftApprovals.transfer(this.$t, transfer))) this.$inertia.post(url, { remove_draft: true });
const base = (t, extra) => ({ flow: [t('Draft'), t('Final'), t('Approved')], confirmText: t('Yes, approve'), cancelText: t('No, keep as draft'), ...extra });

export const draftApprovals = {
  transfer: (t, tr) => base(t, {
    record: tr.reference,
    title: t('Draft transfers cannot be approved yet'),
    intro: t(':ref is still saved as a draft, so no stock has been reserved or moved. A draft has to be finalised before it can be approved.', { ref: tr.reference }),
    effects: [
      t('The draft status is removed from this transfer.'),
      t('Stock moves from :from to :to as soon as it is approved.', { from: (tr.from_warehouse || {}).name || '', to: (tr.to_warehouse || {}).name || '' }),
    ],
    question: t('Remove the draft status and approve this transfer?'),
    confirmText: t('Yes, approve transfer'),
  }),
};

/** True for a transfer saved as a plain draft that leaves a main warehouse: the only kind of draft transfer an approver can release. */
export const isApprovableDraftTransfer = tr => tr.draft == 1 && !['pending', 'cancelled'].includes(tr.approval_status) && !!tr.from_is_main;

/**
 * The whole "approve a draft transfer" journey for a page: the draft is checked first (stock, items, warehouses; a draft is stored without any checking).
 * Problems found: a blocking prompt lists them item by item and offers to open the transfer, where each item shows its own message. Nothing found:
 * the "remove the draft status and approve?" prompt, and on Yes the approval is sent with remove_draft.
 */
export async function approveDraftTransfer(vm, tr) {
  let problems = [];
  try {
    const { data } = await axios.get(vm.route('transfers.draft-check', tr.id));
    problems = data.ok ? [] : data.problems || [];
  } catch (e) {
    problems = [];
  }
  if (problems.length) {
    const t = vm.$t;
    const open = await vm.$draftApprove(base(t, {
      record: tr.reference,
      title: t('This draft cannot leave draft yet'),
      intro: t(':ref was saved as a draft without being checked. These items must be corrected before the draft status can be removed.', { ref: tr.reference }),
      problems,
      confirmText: t('Open and fix'),
      cancelText: t('Not now'),
    }));
    if (open) vm.$inertia.visit(vm.route('transfers.edit', tr.id) + '?verify=1');
    return;
  }
  if (await vm.$draftApprove(draftApprovals.transfer(vm.$t, tr))) {
    vm.$inertia.post(vm.route('transfers.approve', tr.id), { remove_draft: true }, { preserveScroll: true });
  }
}

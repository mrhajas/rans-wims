import { reactive } from 'vue';

// The prompt an approver gets when they try to approve a record that is still a draft (see Shared/DraftApproveModal.vue, mounted once in app.js).
// `await draftApprove({ record, intro, effects, flow })` resolves to true when the approver answers "Yes" (the page then asks the server to remove the
// draft status and approve in one step) and to false for "No". Each module passes its own wording, so every record explains what approving it will do.
export const draftApproveState = reactive({
  visible: false,
  record: '',
  title: '',
  intro: '',
  question: '',
  effects: [],
  problems: [], // when set the prompt is a BLOCK: the draft failed its checks, the person is asked to open the record and fix these first
  flow: [],
  confirmText: '',
  cancelText: '',
  resolver: null,
});

export function draftApprove(opts = {}) {
  return new Promise(resolve => {
    draftApproveState.record = opts.record || '';
    draftApproveState.title = opts.title || '';
    draftApproveState.intro = opts.intro || '';
    draftApproveState.question = opts.question || '';
    draftApproveState.effects = opts.effects || [];
    draftApproveState.problems = opts.problems || [];
    draftApproveState.flow = opts.flow || [];
    draftApproveState.confirmText = opts.confirmText || '';
    draftApproveState.cancelText = opts.cancelText || '';
    draftApproveState.resolver = resolve;
    draftApproveState.visible = true;
  });
}

export function resolveDraftApprove(value) {
  draftApproveState.visible = false;
  const resolver = draftApproveState.resolver;
  draftApproveState.resolver = null;
  if (resolver) resolver(value);
}

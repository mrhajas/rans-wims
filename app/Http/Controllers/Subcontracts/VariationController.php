<?php

namespace App\Http\Controllers\Subcontracts;

use App\Http\Controllers\Controller;
use App\Models\SubcontractAgreement as A;
use App\Models\SubcontractVariation as V;
use App\Models\SubcontractWorkItem;
use App\Services\Subcontracts\SubcontractAmounts;
use App\Services\Subcontracts\SubcontractFields;
use App\Services\Subcontracts\SubcontractLedger;
use App\Services\Subcontracts\SubcontractNotifier;
use App\Services\Subcontracts\SubcontractSettings;
use App\Support\ReferenceLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Variations (additional works, revised costs, reductions): Draft > Submitted > Reviewed > Approved or Rejected. An approved variation changes the REVISED contract
 * value (original + net variations); the original amount and the history stay. Draft, submitted and rejected variations change nothing.
 */
class VariationController extends Controller
{
    use SortsLists;

    use SubcontractHelpers;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    private function visible()
    {
        $ids = $this->visibleAgreements()->pluck('id');

        return V::query()->whereIn('agreement_id', $ids);
    }

    private function find(int $id): V
    {
        return $this->visible()->findOrFail($id);
    }

    public function index(Request $request)
    {
        $this->scalarQuery($request);
        $this->need('read-subcontract-variations');
        $f = ['search' => trim((string) $request->query('search', '')), 'status' => (string) $request->query('status', ''), 'agreement_id' => (string) $request->query('agreement_id', '')];
        $like = fn (string $v) => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $v) . '%';
        $q = $this->visible()->with(['agreement:id,reference,title,contact_id,currency', 'agreement.contact:id,name', 'requester:id,name'])
            ->when(in_array($f['status'], V::STATUSES, true), fn ($q) => $q->where('status', $f['status']))->when(ctype_digit($f['agreement_id']) && $f['agreement_id'] !== '', fn ($q) => $q->where('agreement_id', (int) $f['agreement_id']))
            ->when($f['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', $like($f['search']))->orWhere('title', 'like', $like($f['search']))->orWhere('reason', 'like', $like($f['search']))->orWhereHas('agreement', fn ($ag) => $ag->where('reference', 'like', $like($f['search']))->orWhere('title', 'like', $like($f['search'])))->orWhereHas('agreement.contact', fn ($c) => $c->where('name', 'like', $like($f['search'])))))
            ;
        [$sort, $dir] = $this->sortOf($request, array_merge(['reference', 'agreement', 'contact', 'kind', 'status'], $this->money() ? ['amount'] : []));
        if ($sort !== '') {
            $rows = $this->sortedPage((clone $q)->get(), fn ($v, $c) => match ($c) {
                'reference' => $v->reference, 'agreement' => optional($v->agreement)->reference, 'contact' => optional(optional($v->agreement)->contact)->name, 'kind' => $v->direction, 'status' => $v->status,
                'amount' => $v->amount, default => null,
            }, $sort, $dir, $request, 20);
        } else {
            $rows = (clone $q)->orderByDesc('id')->paginate(20)->withQueryString();
        }
        $f['sort'] = $sort;
        $f['dir'] = $sort !== '' ? $dir : '';
        $rows->through(fn ($v) => $this->row($v));
        $counts = $this->visible()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return Inertia::render('Subcontracts/Variations/Index', ['rows' => $rows, 'filters' => $f, 'statuses' => collect(V::STATUSES)->map(fn ($s) => ['value' => $s, 'label' => __($s)])->values(), 'counts' => $counts, 'money' => $this->money()]);
    }

    public function show(int $variation)
    {
        $this->need('read-subcontract-variations');
        $v = $this->find($variation)->load(['agreement.contact', 'requester:id,name']);
        $fig = $this->money() ? SubcontractLedger::forAgreement($v->agreement_id) : null;
        $items = SubcontractWorkItem::where('variation_id', $v->id)->orderBy('line_no')->get();

        return Inertia::render('Subcontracts/Variations/Show', [
            'fields' => SubcontractFields::forPage('variation'), 'custom' => SubcontractFields::values('variation', $v->extra_attributes), 'item_fields' => SubcontractFields::forPage('work_item'),
            'v' => $this->row($v) + ['reason' => $v->reason, 'decision_remarks' => $v->decision_remarks, 'effective_date' => optional($v->effective_date)->toDateString(), 'approved_at' => optional($v->approved_at)->toDateTimeString(), 'reviewed_at' => optional($v->reviewed_at)->toDateTimeString()],
            'items' => $items->map(fn ($i) => ['custom' => SubcontractFields::values('work_item', $i->extra_attributes), 'id' => $i->id, 'description' => $i->description, 'unit' => $i->unit, 'quantity' => $i->quantity !== null ? (string) $i->quantity : null, 'rate' => $this->m($i->rate), 'amount' => $this->m($i->amount), 'pricing_method' => $i->pricing_method, 'category' => $i->category, 'beneficiary' => $i->beneficiary_name, 'remarks' => $i->remarks])->values(),
            'figures' => $fig, 'attachments' => $this->filesOf($v), 'history' => $this->can('view-audit-subcontracts') ? $this->safeHistory(V::class, $v->id) : [],
            'units' => array_keys(SubcontractSettings::get()['units']), 'categories' => SubcontractSettings::get()['categories'], 'money' => $this->money(), 'pricing' => collect(SubcontractWorkItem::PRICING)->map(fn ($l, $k) => ['value' => $k, 'label' => __($l)])->values(),
            'can' => ['edit' => $v->status === V::DRAFT && $this->can('create-subcontract-variations'), 'submit' => $v->status === V::DRAFT && $this->can('submit-subcontract-variations'), 'review' => $v->status === V::SUBMITTED && $this->can('review-subcontract-variations'),
                'approve' => in_array($v->status, [V::SUBMITTED, V::REVIEWED], true) && $this->can('approve-subcontract-variations'), 'reject' => in_array($v->status, [V::SUBMITTED, V::REVIEWED], true) && ($this->can('approve-subcontract-variations') || $this->can('review-subcontract-variations')),
                'delete' => $v->status === V::DRAFT && $this->can('create-subcontract-variations'), 'attach' => $this->can('create-subcontract-variations') && in_array($v->status, [V::DRAFT, V::SUBMITTED], true), 'documents' => $this->can('view-documents-subcontracts')],
        ]);
    }

    public function store(Request $request, int $agreement)
    {
        $this->need('create-subcontract-variations');
        $a = $this->agreementOrFail($agreement);
        abort_unless($a->isPayable(), 422, __('Variations are for approved agreements.'));
        $d = $this->validated($request);
        $v = ReferenceLock::run('subcontract_variations', fn () => V::create($d + ['reference' => V::nextReference(), 'agreement_id' => $a->id, 'account_id' => $a->account_id, 'status' => V::DRAFT, 'requested_by_user_id' => $request->user()->id]));
        $this->saveFiles($v, $request);
        log_activity('Subcontract variation ' . $v->reference . ' created', ['agreement' => $a->reference, 'direction' => $v->direction, 'amount' => (string) $v->amount], $v, 'Subcontracts');

        return redirect()->route('subcontracts.variations.show', $v->id)->with('message', __(':r saved as a draft. It changes nothing until it is approved.', ['r' => $v->reference]));
    }

    public function update(Request $request, int $variation)
    {
        $this->need('create-subcontract-variations');
        $v = $this->find($variation);
        abort_unless($v->status === V::DRAFT, 422, __('Only a draft variation can be changed.'));
        $d = $this->validated($request, $v);
        $v->update($d);
        $this->syncAmount($v);
        $this->saveFiles($v, $request);
        log_activity('Subcontract variation ' . $v->reference . ' updated', ['amount' => (string) $v->amount], $v, 'Subcontracts');

        return back()->with('message', __('Saved.'));
    }

    public function destroy(int $variation)
    {
        $this->need('create-subcontract-variations');
        $v = $this->find($variation);
        abort_unless($v->status === V::DRAFT, 422, __('Only a draft variation can be deleted.'));
        DB::transaction(function () use ($v) {
            SubcontractWorkItem::where('variation_id', $v->id)->delete();
            $v->delete();
        });
        log_activity('Subcontract variation ' . $v->reference . ' deleted', [], $v, 'Subcontracts');

        return redirect()->route('subcontracts.agreements.show', $v->agreement_id)->with('message', __(':r deleted.', ['r' => $v->reference]));
    }

    /** submit | review | approve | reject */
    public function transition(Request $request, int $variation, string $action)
    {
        $v = $this->find($variation);
        $me = $request->user();
        $reason = mb_substr(trim((string) $request->input('reason', '')), 0, 1000);
        $err = null;
        $event = null;
        DB::transaction(function () use ($v, $me, $action, $reason, &$err, &$event) {
            // the agreement is locked first (the same order as a payment request), then the variation
            A::withoutGlobalScopes()->whereKey($v->agreement_id)->lockForUpdate()->first();
            $v = V::query()->whereKey($v->id)->lockForUpdate()->first();
            $from = $v->status;
            $this->syncAmount($v);
            switch ($action) {
                case 'submit':
                    $this->need('submit-subcontract-variations');
                    if ($from !== V::DRAFT) { $err = __('Only a draft can be submitted.'); return; }
                    if (bccomp((string) $v->amount, '0', 2) <= 0) { $err = __('Enter the amount of the variation (or add its items).'); return; }
                    $v->forceFill(['status' => V::SUBMITTED, 'decision_remarks' => null, 'submitted_by_user_id' => $me->id, 'submitted_at' => now()])->save();
                    $event = 'variation_review';
                    break;
                case 'review':
                    $this->need('review-subcontract-variations');
                    if ($from !== V::SUBMITTED) { $err = __('Only a submitted variation can be reviewed.'); return; }
                    if ($this->selfDecision($v->requested_by_user_id) || $this->selfDecision($v->submitted_by_user_id)) { $err = __('You made or submitted this variation, so someone else must review it.'); return; }
                    $v->forceFill(['status' => V::REVIEWED, 'reviewed_by_user_id' => $me->id, 'reviewed_at' => now()])->save();
                    break;
                case 'approve':
                    $this->need('approve-subcontract-variations');
                    if (! in_array($from, [V::SUBMITTED, V::REVIEWED], true)) { $err = __('Only a submitted variation can be approved.'); return; }
                    if ($this->selfDecision($v->requested_by_user_id) || $this->selfDecision($v->submitted_by_user_id)) { $err = __('You made or submitted this variation, so someone else must approve it.'); return; }
                    $a = A::withoutGlobalScopes()->find($v->agreement_id);
                    if (! $a || ! $a->isPayable()) { $err = __('The agreement is not in a status that accepts variations.'); return; }
                    if ($v->direction !== 'increase') {
                        $now = SubcontractLedger::forAgreement($v->agreement_id);
                        $after = bcsub($now['revised'], (string) $v->amount, 2);
                        if (bccomp($after, $now['approved_work'], 2) < 0) { $err = __('This reduction would bring the contract value to :r, below the :w that is already paid or approved on the agreement.', ['r' => SubcontractAmounts::fmt($after, $a->currency), 'w' => SubcontractAmounts::fmt($now['approved_work'], $a->currency)]); return; }
                    }
                    $v->forceFill(['status' => V::APPROVED, 'approved_by_user_id' => $me->id, 'approved_at' => now(), 'effective_date' => $v->effective_date ?: now()->toDateString(), 'decision_remarks' => $reason ?: null])->save();
                    $event = 'variation_decided';
                    break;
                case 'reject':
                    $this->need('approve-subcontract-variations', 'review-subcontract-variations');
                    if (! in_array($from, [V::SUBMITTED, V::REVIEWED], true)) { $err = __('Only a submitted variation can be rejected.'); return; }
                    if ($reason === '') { $err = __('Say why it is rejected.'); return; }
                    $v->forceFill(['status' => V::REJECTED, 'decision_remarks' => $reason])->save();
                    $event = 'variation_decided';
                    break;
                default:
                    abort(404);
            }
            $fig = SubcontractLedger::forAgreement($v->agreement_id);
            log_activity('Subcontract variation ' . $v->reference . ' ' . $action, ['from' => $from, 'to' => $v->status, 'amount' => (string) $v->amount, 'direction' => $v->direction, 'revised_after' => $fig['revised'] ?? null, 'reason' => $reason ?: null], $v, 'Subcontracts');
        });
        if ($err) {
            return back()->with('error', e($err));
        }
        $v->refresh();
        if ($event) {
            SubcontractNotifier::fire($event, 'variation', $v, $me, $v->decision_remarks ?: $v->status, ['amount' => (string) $v->amount]);
        }

        return back()->with('message', __(':r is now :s.', ['r' => $v->reference, 's' => strtolower(__($v->status))]));
    }

    public function attach(Request $request, int $variation)
    {
        $this->need('create-subcontract-variations');
        $v = $this->find($variation);
        $request->validate(['attachments' => 'required|array|min:1|max:10', 'attachments.*' => 'file|max:8192|mimes:' . self::FILE_TYPES]);
        $n = $this->saveFiles($v, $request);
        log_activity('Subcontract variation ' . $v->reference . ' document added', ['files' => $n], $v, 'Subcontracts');

        return back()->with('message', trans_choice(':n document attached.|:n documents attached.', $n, ['n' => $n]));
    }

    public function download(int $variation, int $file)
    {
        return $this->downloadFile($this->find($variation), $file, 'Subcontract variation');
    }

    public function removeFile(int $variation, int $file)
    {
        $this->need('create-subcontract-variations');
        $v = $this->find($variation);
        abort_unless($v->status === V::DRAFT, 422);
        $this->removeFileOf($v, $file, 'Subcontract variation');

        return back()->with('message', __('Document removed.'));
    }

    /** With items, the amount of an increase is the sum of its items (never typed twice). */
    private function syncAmount(V $v): void
    {
        $n = SubcontractWorkItem::where('variation_id', $v->id)->count();
        if ($n > 0 && $v->direction === 'increase') {
            $v->forceFill(['amount' => bcadd((string) SubcontractWorkItem::where('variation_id', $v->id)->where('status', 'active')->sum('amount'), '0', 2)])->save();
        }
    }

    private function row(V $v): array
    {
        return ['id' => $v->id, 'reference' => $v->reference, 'title' => $v->title, 'direction' => $v->direction, 'amount' => $this->m($v->amount), 'status' => $v->status, 'status_label' => __($v->status), 'agreement_id' => $v->agreement_id,
            'agreement' => optional($v->agreement)->reference, 'agreement_title' => optional($v->agreement)->title, 'contact' => optional(optional($v->agreement)->contact)->name, 'currency' => optional($v->agreement)->currency, 'by' => optional($v->requester)->name, 'created_at' => optional($v->created_at)->toDateString()];
    }

    private function validated(Request $request, $existing = null): array
    {
        $d = SubcontractFields::validate($request, 'variation', ['title' => 'required|string|max:190', 'reason' => 'nullable|string|max:3000', 'direction' => 'required|in:increase,reduction', 'amount' => 'nullable|numeric|regex:/^-?\d+(\.\d+)?$/|min:0|max:99999999999999', 'effective_date' => 'nullable|date'] + $this->fileRules(), $existing ? ($existing->extra_attributes ?: []) : null);
        unset($d['attachments']);
        $d['amount'] = SubcontractAmounts::money((string) ($d['amount'] ?? '0'));
        if (array_key_exists('reason', $d)) {
            $d['reason'] = isset($d['reason']) && trim($d['reason']) !== '' ? trim($d['reason']) : null;
        }

        return $d;
    }
}

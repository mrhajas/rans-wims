<?php

namespace App\Http\Controllers\WeeklyAdministration;

use App\Http\Controllers\Controller;
use App\Models\AdminItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * The template and the list of items of the Administration documents. An item marked "in the template" starts every new weekly document (with its beneficiary and
 * amount); every other line that was ever typed is kept here too so a report can follow it. Changing an item never changes a document that was already made.
 */
class ItemController extends Controller
{
    public function __construct()
    {
        $this->setAbilities([]);
    }

    /** A query value as plain text: an array (?search[]=x) or anything else odd becomes empty instead of an error. */
    private function qs(Request $r, string $k): string
    {
        $v = $r->query($k, '');

        return is_string($v) ? trim($v) : '';
    }

    private function manage(): void
    {
        abort_unless(auth()->user()->can('manage-administration-items'), 403);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can('read-weekly-administration'), 403);
        $f = ['search' => $this->qs($request, 'search'), 'view' => in_array($this->qs($request, 'view'), ['template', 'other', 'inactive'], true) ? $this->qs($request, 'view') : '', 'category' => $this->qs($request, 'category'), 'staff_id' => $this->qs($request, 'staff_id'), 'contact_id' => $this->qs($request, 'contact_id'), 'person' => $this->qs($request, 'person')];
        $pid = \App\Services\Administration\AdminCategories::parsePerson($f['person']);
        if (! $pid['staff_id'] && ctype_digit($f['staff_id']) && $f['staff_id'] !== '') {
            $pid['staff_id'] = (int) $f['staff_id'];
        }
        if (! $pid['staff_id'] && ! $pid['contact_id'] && ctype_digit($f['contact_id']) && $f['contact_id'] !== '') {
            $pid['contact_id'] = (int) $f['contact_id'];
        }
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $f['search']) . '%';
        // how many times an item was put on a document, and what has really been PAID for it (payments marked paid, or paid through a voucher)
        $use = DB::table('admin_document_items as l')->join('admin_documents as d', 'd.id', '=', 'l.admin_document_id')->whereNull('l.deleted_at')->whereNull('d.deleted_at')->whereIn('d.status', ['Requested', 'Approved', 'Authorized'])
            ->groupBy('l.admin_item_id')->selectRaw('l.admin_item_id, COUNT(*) n')->get()->keyBy('admin_item_id');
        $paid = DB::table('admin_document_payment_lines as pl')->join('admin_document_payments as p', 'p.id', '=', 'pl.admin_document_payment_id')->join('admin_document_items as l', function ($j) {
            $j->on('l.id', '=', 'pl.line_id')->where('pl.line_type', '=', 'item');
        })->whereNull('pl.deleted_at')->whereNull('p.deleted_at')->whereNull('l.deleted_at')->where('p.status', 'Paid')->groupBy('l.admin_item_id')->selectRaw('l.admin_item_id, SUM(pl.amount) total, MAX(p.paid_on) last_on')->get()->keyBy('admin_item_id');
        $rows = AdminItem::query()->when($f['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('default_beneficiary', 'like', $like)))
            ->when($f['category'] !== '', fn ($q) => $q->where('category', $f['category']))->when($pid['staff_id'], fn ($q, $v) => $q->where('staff_id', $v))->when($pid['contact_id'], fn ($q, $v) => $q->where('contact_id', $v))
            ->when($f['view'] === 'template', fn ($q) => $q->where('in_template', true))->when($f['view'] === 'other', fn ($q) => $q->where('in_template', false))->when($f['view'] === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderByDesc('in_template')->orderBy('sort_order')->orderBy('name')->with('staff:id,name', 'contact:id,name')->paginate(40)->withQueryString();
        $rows->through(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'category' => $i->category, 'staff_id' => $i->staff_id, 'contact_id' => $i->contact_id, 'person' => $i->staff_id ? 's:' . $i->staff_id : ($i->contact_id ? 'c:' . $i->contact_id : ''), 'person_name' => optional($i->staff)->name ?: optional($i->contact)->name, 'person_type' => $i->staff_id ? 'staff' : ($i->contact_id ? 'contact' : null), 'default_beneficiary' => (string) $i->default_beneficiary, 'default_beneficiary_user_id' => $i->default_beneficiary_user_id, 'default_amount' => (string) $i->default_amount,
            'in_template' => (bool) $i->in_template, 'is_active' => (bool) $i->is_active, 'sort_order' => (int) $i->sort_order, 'used' => (int) optional($use->get($i->id))->n, 'spent' => number_format((float) optional($paid->get($i->id))->total, 2, '.', ''), 'last_on' => optional($paid->get($i->id))->last_on]);
        $c = AdminItem::query()->selectRaw('SUM(in_template = 1) t, SUM(in_template = 0) o, SUM(is_active = 0) i, COUNT(*) n')->first();

        return Inertia::render('WeeklyAdministration/Items', [
            'rows' => $rows, 'filters' => $f, 'stats' => ['all' => (int) $c->n, 'template' => (int) $c->t, 'other' => (int) $c->o, 'inactive' => (int) $c->i],
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name])->values(),
            'currency' => \App\Models\AdminDocument::currency(), 'categories' => \App\Services\Administration\AdminCategories::list(),
            'people' => \App\Services\Administration\AdminCategories::peopleOptions(), 'can' => ['manage' => auth()->user()->can('manage-administration-items')],
        ]);
    }

    private function validated(Request $request): array
    {
        $d = $request->validate(['name' => 'required|string|max:190', 'default_beneficiary' => 'nullable|string|max:190', 'default_beneficiary_user_id' => 'nullable|integer|exists:users,id', 'default_amount' => 'nullable|numeric|min:0|max:999999999999',
            'in_template' => 'nullable|boolean', 'is_active' => 'nullable|boolean', 'category' => 'nullable|string|max:60', 'person' => 'nullable|string|max:20', 'staff_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('staffs', 'id')->whereNull('deleted_at')], 'contact_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('contacts', 'id')->whereNull('deleted_at')]]);
        $d['name'] = trim(preg_replace('/\s+/', ' ', $d['name']));
        $d['name_key'] = AdminItem::keyOf($d['name']);
        $d['default_amount'] = round((float) ($d['default_amount'] ?? 0), 2);
        $d['category'] = trim((string) ($d['category'] ?? '')) ?: \App\Services\Administration\AdminCategories::guess($d['name']);
        // one person: the combined dropdown value ("s:5" staff, "c:7" contact), or a plain staff_id / contact_id; staff wins if both are sent
        $p = \App\Services\Administration\AdminCategories::parsePerson($d['person'] ?? '');
        $sid = $p['staff_id'] ?: (! empty($d['staff_id']) ? (int) $d['staff_id'] : null);
        $cid = $sid ? null : ($p['contact_id'] ?: (! empty($d['contact_id']) ? (int) $d['contact_id'] : null));
        unset($d['person']);
        if (($sid && ! \App\Models\Staff::query()->whereKey($sid)->exists()) || ($cid && ! \App\Models\Contact::query()->whereKey($cid)->whereNull('deleted_at')->exists())) {
            throw \Illuminate\Validation\ValidationException::withMessages(['person' => __('Choose a staff member or contact from the list.')]);
        }
        $d['staff_id'] = $sid;
        $d['contact_id'] = $cid;
        if (($sid || $cid) && trim((string) ($d['default_beneficiary'] ?? '')) === '') {
            $d['default_beneficiary'] = $sid ? optional(\App\Models\Staff::find($sid))->name : optional(\App\Models\Contact::find($cid))->name;
        }
        $d['in_template'] = (bool) ($d['in_template'] ?? false);
        $d['is_active'] = (bool) ($d['is_active'] ?? true);

        return $d;
    }

    public function store(Request $request)
    {
        $this->manage();
        $d = $this->validated($request);
        if (AdminItem::query()->where('name_key', $d['name_key'])->exists()) {
            return back()->withErrors(['name' => __('There is already an item with this name.')]);
        }
        $i = AdminItem::create($d + ['sort_order' => (int) AdminItem::query()->max('sort_order') + 1]);
        log_activity('Administration item "' . $i->name . '" added', ['in_template' => $i->in_template, 'amount' => $i->default_amount, 'category' => $i->category, 'staff_id' => $i->staff_id, 'contact_id' => $i->contact_id], $i, 'Administration');

        return back()->with('message', __('Item added.'));
    }

    public function update(Request $request, AdminItem $item)
    {
        $this->manage();
        $d = $this->validated($request);
        if (AdminItem::query()->where('name_key', $d['name_key'])->where('id', '<>', $item->id)->exists()) {
            return back()->withErrors(['name' => __('There is already an item with this name.')]);
        }
        $before = $item->only(['name', 'default_beneficiary', 'default_amount', 'in_template', 'is_active', 'category', 'staff_id', 'contact_id']);
        $item->update($d);
        log_activity('Administration item "' . $item->name . '" changed', ['before' => $before, 'after' => $item->only(['name', 'default_beneficiary', 'default_amount', 'in_template', 'is_active', 'category', 'staff_id', 'contact_id'])], $item, 'Administration');

        return back()->with('message', __('Item saved. Documents already made do not change.'));
    }

    public function destroy(AdminItem $item)
    {
        $this->manage();
        $name = $item->name;
        $item->delete();
        log_activity('Administration item "' . $name . '" removed', ['id' => $item->id], $item, 'Administration');

        return back()->with('message', __('Item removed. Documents already made keep their lines.'));
    }

    /** The order of the template: the ids in the order they should start a new document. */
    public function reorder(Request $request)
    {
        $this->manage();
        $ids = $request->validate(['ids' => 'required|array|max:500', 'ids.*' => 'integer'])['ids'];
        // the items swap the places they already hold, so items on other pages keep their own places
        DB::transaction(function () use ($ids) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $slots = AdminItem::query()->whereIn('id', $ids)->orderBy('sort_order')->orderBy('id')->pluck('sort_order')->values()->all();
            foreach ($ids as $n => $id) {
                AdminItem::query()->whereKey($id)->update(['sort_order' => $slots[$n] ?? $n + 1]);
            }
        });
        log_activity('Administration template reordered', ['items' => count($ids)], null, 'Administration');

        return back()->with('message', __('Order saved.'));
    }
}

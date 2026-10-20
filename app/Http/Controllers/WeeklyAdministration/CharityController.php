<?php

namespace App\Http\Controllers\WeeklyAdministration;

use App\Http\Controllers\Controller;
use App\Models\AdminCharityPerson;
use App\Models\AdminDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** The Charity list: the people and places that receive a monthly charity. The Administration document pulls its Charity section from this list (and the amounts can be changed on each document). */
class CharityController extends Controller
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
        abort_unless(auth()->user()->can('manage-administration-charity'), 403);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can('read-administration-charity'), 403);
        $f = ['search' => $this->qs($request, 'search'), 'view' => in_array($this->qs($request, 'view'), ['active', 'inactive'], true) ? $this->qs($request, 'view') : '', 'hq' => $this->qs($request, 'hq')];
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $f['search']) . '%';
        // what has really been PAID to each person (payments marked paid, or paid through a voucher)
        $paid = DB::table('admin_document_payment_lines as pl')->join('admin_document_payments as p', 'p.id', '=', 'pl.admin_document_payment_id')->join('admin_document_charities as c', function ($j) {
            $j->on('c.id', '=', 'pl.line_id')->where('pl.line_type', '=', 'charity');
        })->whereNull('pl.deleted_at')->whereNull('p.deleted_at')->whereNull('c.deleted_at')->where('p.status', 'Paid')->whereNotNull('c.admin_charity_person_id')
            ->groupBy('c.admin_charity_person_id')->selectRaw('c.admin_charity_person_id pid, COUNT(DISTINCT p.id) n, SUM(pl.amount) total, MAX(p.paid_on) last_on')->get()->keyBy('pid');
        $rows = AdminCharityPerson::query()->when($f['search'] !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('notes', 'like', $like)))
            ->when($f['hq'] !== '', fn ($q) => $q->where('hq', $f['hq']))
            ->when($f['view'] === 'active', fn ($q) => $q->where('is_active', true))->when($f['view'] === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderByDesc('is_active')->orderBy('sort_order')->orderBy('name')->paginate(40)->withQueryString();
        $rows->through(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'hq' => $p->hq, 'phone' => (string) $p->phone, 'notes' => (string) $p->notes, 'monthly_amount' => (string) $p->monthly_amount, 'is_active' => (bool) $p->is_active,
            'times' => (int) optional($paid->get($p->id))->n, 'paid' => number_format((float) optional($paid->get($p->id))->total, 2, '.', ''), 'last_on' => optional($paid->get($p->id))->last_on]);
        $active = AdminCharityPerson::query()->where('is_active', true);

        return Inertia::render('WeeklyAdministration/Charity', [
            'rows' => $rows, 'filters' => $f, 'stats' => ['all' => AdminCharityPerson::query()->count(), 'active' => (clone $active)->count(), 'inactive' => AdminCharityPerson::query()->where('is_active', false)->count()],
            'monthly_total' => number_format((float) (clone $active)->sum('monthly_amount'), 2, '.', ''), 'hqs' => \App\Models\AdminDocument::hqs(),
            'hq_totals' => collect(\App\Models\AdminDocument::hqs())->map(fn ($h) => ['hq' => $h, 'people' => AdminCharityPerson::query()->where('is_active', true)->where(fn ($w) => $w->where('hq', $h)->orWhereNull('hq')->orWhere('hq', ''))->count(), 'monthly' => number_format((float) AdminCharityPerson::query()->where('is_active', true)->where(fn ($w) => $w->where('hq', $h)->orWhereNull('hq')->orWhere('hq', ''))->sum('monthly_amount'), 2, '.', '')])->values(), 'currency' => AdminDocument::currency(), 'can' => ['manage' => $request->user()->can('manage-administration-charity')],
        ]);
    }

    private function validated(Request $request): array
    {
        $d = $request->validate(['name' => 'required|string|max:190', 'hq' => 'nullable|string|max:60', 'phone' => 'nullable|string|max:60', 'notes' => 'nullable|string|max:1000', 'monthly_amount' => 'required|numeric|min:0|max:999999999999', 'is_active' => 'nullable|boolean']);
        $d['name'] = trim(preg_replace('/\s+/', ' ', $d['name']));
        $hq = trim((string) ($d['hq'] ?? ''));
        $d['hq'] = $hq === '' ? null : (collect(\App\Models\AdminDocument::hqs())->first(fn ($x) => mb_strtolower($x) === mb_strtolower($hq)) ?: abort(422, __('Choose one of the headquarters of the list, or none.')));
        $d['monthly_amount'] = round((float) $d['monthly_amount'], 2);
        $d['is_active'] = (bool) ($d['is_active'] ?? true);

        return $d;
    }

    public function store(Request $request)
    {
        $this->manage();
        $d = $this->validated($request);
        $p = AdminCharityPerson::create($d + ['sort_order' => (int) AdminCharityPerson::query()->max('sort_order') + 1]);
        log_activity('Administration charity "' . $p->name . '" added', ['monthly' => $p->monthly_amount], $p, 'Administration Charity');

        return back()->with('message', __(':n added to the Charity list.', ['n' => $p->name]));
    }

    public function update(Request $request, AdminCharityPerson $person)
    {
        $this->manage();
        $d = $this->validated($request);
        $before = $person->only(['name', 'monthly_amount', 'is_active']);
        $person->update($d);
        log_activity('Administration charity "' . $person->name . '" changed', ['before' => $before, 'after' => $person->only(['name', 'monthly_amount', 'is_active'])], $person, 'Administration Charity');

        return back()->with('message', __('Saved. Documents already made do not change.'));
    }

    public function destroy(AdminCharityPerson $person)
    {
        $this->manage();
        $name = $person->name;
        $person->delete();
        log_activity('Administration charity "' . $name . '" removed', ['id' => $person->id], $person, 'Administration Charity');

        return back()->with('message', __(':n removed from the Charity list. Documents already made keep their lines.', ['n' => $name]));
    }
}

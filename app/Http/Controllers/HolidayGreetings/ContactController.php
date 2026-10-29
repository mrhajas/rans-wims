<?php

namespace App\Http\Controllers\HolidayGreetings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\HolidayGreetings\Concerns\Guards;
use App\Models\HolidayContact;
use App\Services\HolidayGreetings\ContactBook;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** The greeting list: who receives the holiday greetings. Imports from users, staff and contacts; people can be added by hand; an email or a mobile number is never kept twice. */
class ContactController extends Controller
{
    use Guards;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    public function index(Request $request)
    {
        $this->need('read-holiday-contacts');
        $me = $request->user();
        $q = trim((string) $request->query('q', ''));
        $source = (string) $request->query('source', '');
        $has = (string) $request->query('has', '');
        $state = (string) $request->query('state', '');
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $query = HolidayContact::query()
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('mobile', 'like', $like)->orWhere('title', 'like', $like)->orWhere('group_label', 'like', $like)))
            ->when(array_key_exists($source, HolidayContact::SOURCES), fn ($w) => $w->where('source', $source))
            ->when($has === 'email', fn ($w) => $w->whereNotNull('email'))
            ->when($has === 'mobile', fn ($w) => $w->whereNotNull('mobile'))
            ->when($has === 'both', fn ($w) => $w->whereNotNull('email')->whereNotNull('mobile'))
            ->when($state === 'active', fn ($w) => $w->where('is_active', true))
            ->when($state === 'inactive', fn ($w) => $w->where('is_active', false))
            ->orderBy('name')->orderBy('id');
        $rows = $query->paginate(25)->withQueryString();
        $all = HolidayContact::query();

        return Inertia::render('HolidayGreetings/Contacts', [
            'rows' => ['data' => collect($rows->items())->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'name' => $c->name, 'email' => $c->email, 'mobile' => $c->mobile, 'source' => $c->source, 'group' => $c->group_label, 'active' => (bool) $c->is_active])->values(),
                'links' => $rows->linkCollection()->map(fn ($l) => ['url' => $l['url'], 'label' => $l['label'], 'active' => $l['active']])->values(), 'from' => $rows->firstItem(), 'to' => $rows->lastItem(), 'total' => $rows->total()],
            'filters' => ['q' => $q, 'source' => $source, 'has' => $has, 'state' => $state],
            'stats' => ['people' => (clone $all)->count(), 'active' => (clone $all)->where('is_active', true)->count(), 'emails' => (clone $all)->where('is_active', true)->whereNotNull('email')->distinct()->count('email'), 'mobiles' => (clone $all)->where('is_active', true)->whereNotNull('mobile')->distinct()->count('mobile')],
            'sources' => collect(HolidayContact::SOURCES)->map(fn ($l) => __($l))->all(), 'titles' => ContactBook::TITLE_SUGGESTIONS,
            'importSources' => collect(ContactBook::SOURCES)->map(fn ($l) => __($l))->all(), 'categories' => ContactBook::contactCategories((int) $me->account_id),
            'can' => ['manage' => $me->can('manage-holiday-contacts')],
        ]);
    }

    public function store(Request $request)
    {
        $this->need('manage-holiday-contacts');
        $acc = (int) $request->user()->account_id;
        $c = ContactBook::clean($request->all(), $acc);
        $row = HolidayContact::create(['account_id' => $acc, 'title' => $c['title'], 'name' => $c['name'], 'email' => $c['email'], 'mobile' => $c['mobile'], 'source' => 'manual', 'group_label' => null, 'is_active' => true, 'created_by_user_id' => $request->user()->id]);
        log_activity('Holiday greeting person added', ['name' => $row->fullName(), 'email' => $row->email, 'mobile' => $row->mobile], $row, 'Holiday Greetings');
        $note = $c['mobile_fixed'] ? ' ' . __('The mobile number was written as :m.', ['m' => $c['mobile']]) : '';

        return back()->with('message', __(':n was added to the greeting list.', ['n' => $row->fullName()]) . $note);
    }

    public function update(Request $request, int $contact)
    {
        $this->need('manage-holiday-contacts');
        $row = HolidayContact::query()->findOrFail($contact);
        if ($request->has('active') && ! $request->has('name')) {
            $row->update(['is_active' => $request->boolean('active')]);
            log_activity($row->is_active ? 'Holiday greeting person switched on' : 'Holiday greeting person switched off', ['name' => $row->fullName()], $row, 'Holiday Greetings');

            return back()->with('message', $row->is_active ? __(':n will receive the greetings.', ['n' => $row->fullName()]) : __(':n will no longer receive the greetings.', ['n' => $row->fullName()]));
        }
        $before = $row->only(['title', 'name', 'email', 'mobile']);
        $c = ContactBook::clean($request->all(), (int) $request->user()->account_id, $row->id);
        $row->update(['title' => $c['title'], 'name' => $c['name'], 'email' => $c['email'], 'mobile' => $c['mobile']]);
        log_activity('Holiday greeting person changed', ['before' => $before, 'after' => $row->only(['title', 'name', 'email', 'mobile'])], $row, 'Holiday Greetings');
        $note = $c['mobile_fixed'] ? ' ' . __('The mobile number was written as :m.', ['m' => $c['mobile']]) : '';

        return back()->with('message', __('Saved.') . $note);
    }

    public function destroy(Request $request, int $contact)
    {
        $this->need('manage-holiday-contacts');
        $row = HolidayContact::query()->findOrFail($contact);
        log_activity('Holiday greeting person removed', ['name' => $row->fullName(), 'email' => $row->email, 'mobile' => $row->mobile], $row, 'Holiday Greetings');
        $row->delete();

        return back()->with('message', __(':n was removed from the greeting list.', ['n' => $row->fullName()]));
    }

    /** What an import would do, without doing it. */
    public function importPreview(Request $request)
    {
        $this->need('manage-holiday-contacts');
        [$sources, $cats] = $this->importInput($request);

        return response()->json(ContactBook::import((int) $request->user()->account_id, $sources, $cats, true));
    }

    public function import(Request $request)
    {
        $this->need('manage-holiday-contacts');
        [$sources, $cats] = $this->importInput($request);
        $r = ContactBook::import((int) $request->user()->account_id, $sources, $cats, false, $request->user()->id);
        log_activity('Holiday greeting list imported', ['from' => $sources, 'added' => $r['added'], 'already_in_list' => $r['already'], 'without_email_or_mobile' => $r['no_contact']], null, 'Holiday Greetings');

        return back()->with('message', __(':a people were added to the greeting list. :d were already in it and :n have no email or mobile number.', ['a' => $r['added'], 'd' => $r['already'], 'n' => $r['no_contact']]));
    }

    private function importInput(Request $request): array
    {
        $d = $request->validate(['sources' => 'required|array|min:1', 'sources.*' => 'in:users,staff,contacts', 'categories' => 'nullable|array', 'categories.*' => 'string|max:80'], ['sources.required' => __('Choose where to import from.')]);

        return [$d['sources'], $d['categories'] ?? []];
    }
}

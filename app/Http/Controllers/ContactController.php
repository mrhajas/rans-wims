<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\Contact;
use Illuminate\Http\Request;
use App\Http\Requests\ContactRequest;
use App\Http\Resources\ContactResource;
use App\Http\Resources\ContactCollection;

class ContactController extends Controller
{
    /** Contacts workflow page (Who Gets Notified / Messages): which event happened; the page decides who is told. */
    private function alertContact(string $event, string $title, string $message, $contact): void
    {
        \App\Services\Workflow\WorkflowMessenger::dispatch('contacts', $event, null, auth()->user(), [
            'account_id' => auth()->user()?->account_id ?? 1, 'title' => $title, 'message' => $message, 'url' => route('contacts.index'),
            'dedupe' => 'contacts|' . $event . '|' . ($contact->id ?? 0) . '|' . intdiv(time(), 20),
            'values' => ['name' => (string) $contact->name, 'category' => (string) ($contact->category ?? ''), 'phone' => (string) ($contact->phone ?? '')],
        ]);
    }

    public function create(Request $request)
    {
        // ?category=... starts the form with that category already chosen (used by the "Add Personnel" buttons).
        $default = Contact::normalizeCategory(is_string($request->query('category')) ? mb_substr($request->query('category'), 0, 50) : null);

        return Inertia::render('Contact/Form', ['categories' => Contact::categoryOptions(), 'defaultCategory' => $default]);
    }

    public function destroy(Contact $contact)
    {
        if ($contact->del()) {
            $this->alertContact('deleted', __('Contact Deleted'), $contact->name . ' was deleted.', $contact);

            return redirect()->route('contacts.index')->with('message', __choice('action_text', ['record' => 'Contact', 'action' => 'deleted']));
        }

        return back()->with('error', __('The record can not be deleted.'));
    }

    public function destroyPermanently(Contact $contact)
    {
        if ($contact->delP()) {
            $this->alertContact('deleted', __('Contact Permanently Deleted'), $contact->name . ' was permanently deleted.', $contact);

            return redirect()->route('contacts.index')->with('message', __choice('action_text', ['record' => 'Contact', 'action' => 'deleted']));
        }

        return back()->with('error', __('The record can not be deleted.'));
    }

    public function edit(Contact $contact)
    {
        return Inertia::render('Contact/Form', [
            'edit'       => new ContactResource($contact),
            'categories' => Contact::categoryOptions(),
            // what belongs to this contact, by its category (visiting logs, agent statement, attendance, trips...)
            'panels'     => \App\Support\ContactPanels::for($contact, auth()->user()),
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->all('search', 'trashed', 'category');

        return Inertia::render('Contact/Index', [
            'filters'    => $filters,
            'stats'      => [
                'total'      => Contact::count(),
                'categories' => Contact::query()->selectRaw('category, COUNT(*) as total')->whereNotNull('category')->groupBy('category')->pluck('total', 'category'),
            ],
            'categories' => Contact::categoryOptions(),
            'contacts'   => new ContactCollection(
                Contact::filter($filters)->orderByDesc('id')->paginate()->withQueryString()
            ),
        ]);
    }

    /** Print / Download of exactly what the Contacts table shows for the current search, category and trashed filter. */
    public function printList(Request $request)
    {
        $this->authorize('print-contacts');

        return view('reports.register-list', $this->listPayload($request, false));
    }

    public function pdfList(Request $request)
    {
        $this->authorize('print-contacts');

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.register-list', $this->listPayload($request, true))
            ->setPaper('a4', 'landscape')
            ->download('contacts-' . now()->format('Ymd-His') . '.pdf');
    }

    private function listPayload(Request $request, bool $isPdf): array
    {
        $filters = $request->all('search', 'trashed', 'category');
        if (! in_array($filters['trashed'] ?? null, ['with', 'only'], true)) {
            $filters['trashed'] = null;
        }

        $contacts = Contact::filter($filters)->orderByDesc('id')->limit(5000)->get();

        $chips = [];
        if (! empty($filters['search'])) {
            $chips[] = ['Search', $filters['search']];
        }
        if (! empty($filters['category'])) {
            $chips[] = ['Category', $filters['category']];
        }
        if ($filters['trashed']) {
            $chips[] = ['Trashed', $filters['trashed'] === 'only' ? 'Only trashed' : 'With trashed'];
        }

        $rows = [];
        foreach ($contacts as $i => $c) {
            $rows[] = [
                $i + 1,
                ['text' => $c->name, 'strong' => true, 'sub' => $c->deleted_at ? 'Trashed' : null],
                $c->category ? ['text' => $c->category, 'tone' => 'indigo'] : '-',
                $c->email,
                $c->phone,
                \Illuminate\Support\Str::limit((string) $c->details, 140),
            ];
        }

        $byCategory = $contacts->groupBy(fn ($c) => $c->category ?: 'Uncategorised')->map->count()->sortDesc();
        $cards = [
            ['label' => $filters['category'] ? $filters['category'] : 'Contacts', 'value' => number_format($contacts->count()), 'tone' => 'navy', 'icon' => 'users'],
            ['label' => 'With Phone', 'value' => number_format($contacts->filter(fn ($c) => filled($c->phone))->count()), 'tone' => 'emerald', 'icon' => 'phone'],
            ['label' => 'With Email', 'value' => number_format($contacts->filter(fn ($c) => filled($c->email))->count()), 'tone' => 'sky', 'icon' => 'mail'],
            ['label' => 'Categories', 'value' => number_format($byCategory->count()), 'tone' => 'violet', 'icon' => 'tag'],
        ];

        return [
            'title' => $filters['category'] ? 'Contacts - ' . $filters['category'] : 'Contacts',
            'isPdf' => $isPdf,
            'autoPrint' => ! $isPdf,
            'companyName' => optional($request->user()->account)->name ?? 'RANS Company Limited',
            'generatedBy' => $request->user()->name,
            'generatedAt' => now(),
            'filters' => $chips,
            'scopeNote' => $chips ? 'Exactly the contacts listed on screen for the filters above, nothing more.' : 'All contacts.',
            'groups' => [['title' => 'Summary of the listed contacts', 'cards' => $cards]],
            'breakdown' => $byCategory->count() > 1 ? [
                'title' => 'Contacts by category',
                'columns' => ['Category', 'Contacts'],
                'rows' => $byCategory->map(fn ($n, $cat) => [$cat, number_format($n)])->values()->all(),
            ] : null,
            'tableTitle' => 'Contacts (' . number_format($contacts->count()) . ')',
            'columns' => [
                ['label' => 'No', 'width' => '26px'], ['label' => 'Name'], ['label' => 'Category'],
                ['label' => 'Email'], ['label' => 'Phone'], ['label' => 'Details'],
            ],
            'rows' => $rows,
            'emptyText' => 'No contacts found.',
        ];
    }

    public function restore(Contact $contact)
    {
        $contact->restore();
        $this->alertContact('restored', __('Contact Restored'), $contact->name . ' was restored.', $contact);

        return back()->with('message', __choice('action_text', ['record' => 'Contact', 'action' => 'restored']));
    }

    public function store(ContactRequest $request)
    {
        $contact = Contact::create($request->validated());
        $this->alertContact('created', __('Contact Created'), $contact->name . ' was created.', $contact);

        // Lets quick-add-a-contact modals (e.g. on the Payment Voucher Create
        // form) create a Contact via axios and get it straight back, without
        // disturbing the normal full-page Inertia redirect below.
        if ($request->expectsJson()) {
            return response()->json(['id' => $contact->id, 'name' => $contact->name, 'category' => $contact->category]);
        }

        return redirect()->route('contacts.index')->with('message', __choice('action_text', ['record' => 'Contact', 'action' => 'created']));
    }

    public function update(ContactRequest $request, Contact $contact)
    {
        $contact->update($request->validated());
        $this->alertContact('updated', __('Contact Edited'), $contact->name . ' was edited.', $contact);

        return back()->with('message', __choice('action_text', ['record' => 'Contact', 'action' => 'updated']));
    }
}

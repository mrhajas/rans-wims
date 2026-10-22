<?php

namespace App\Http\Controllers\VisitorLogs;

use App\Http\Controllers\Controller;
use App\Http\Controllers\VisitorLogs\Concerns\Guards;
use App\Models\Staff;
use App\Models\User;
use App\Models\Visitor;
use App\Services\VisitorLogs\VisitorSettings;
use Illuminate\Http\Request;

/** The two searches behind the forms' auto-complete fields: visitors (the Visitor Directory) and people (the users of WIMS a visitor comes to see, or an item is for). */
class LookupController extends Controller
{
    use Guards;

    public function __construct()
    {
        $this->setAbilities([]);
    }

    /** POST {search} or {id}: up to 30 matches (the first 30 by name when nothing is typed yet, so the list can be picked from). People come from the existing users, with the position from their Staff record; nothing is copied. */
    public function people(Request $request)
    {
        $this->needAny(['create-visitor-logs', 'update-visitor-logs', 'correct-visitor-logs', 'create-incoming-items', 'update-incoming-items']);
        if ($request->filled('id')) {
            $users = User::query()->whereKey((int) $request->input('id'))->get();
        } else {
            $s = mb_substr(trim((string) $request->input('search', '')), 0, 80);
            $users = VisitorSettings::peopleQuery()->when($s !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $this->like($s))->orWhere('username', 'like', $this->like($s))))->orderBy('name')->limit(30)->get();
        }
        $position = Staff::query()->whereIn('user_id', $users->pluck('id'))->pluck('position', 'user_id');

        return response()->json($users->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'position' => $position[$u->id] ?? null, 'label' => $u->name . (! empty($position[$u->id]) ? ' · ' . $position[$u->id] : '')])->values());
    }

    /** POST {search} or {id}. Active visitors only when searching; an id always answers (an old visit may name a visitor who was deactivated since). */
    public function visitors(Request $request)
    {
        $this->needAny(['read-visitors', 'create-visitor-logs', 'create-incoming-items', 'update-visitor-logs']);
        $u = $request->user();
        $history = $u->can('view-visitor-history');
        if ($request->filled('id')) {
            $rows = Visitor::query()->whereKey((int) $request->input('id'))->get();
        } else {
            $s = mb_substr(trim((string) $request->input('search', '')), 0, 80);
            $rows = Visitor::query()->where('is_active', true)->when($s !== '', fn ($q) => $q->where(fn ($w) => $w->where('full_name', 'like', $this->like($s))->orWhere('company', 'like', $this->like($s))
                ->orWhere('phone', 'like', $this->like($s))->orWhere('reference', 'like', $this->like($s))->when(Visitor::phoneKey($s), fn ($x, $k) => $x->orWhere('phone_key', $k))))
                ->orderBy('full_name')->limit(30)->get();
        }

        return response()->json($rows->map(fn ($v) => $v->summary($u, $history))->values());
    }

    /** POST {search} or {id}: the Contacts a sender can be chosen from (every Contact, whatever its category), up to 15. */
    public function contacts(Request $request)
    {
        $this->needAny(['create-incoming-items', 'update-incoming-items']);
        if ($request->filled('id')) {
            $rows = \App\Models\Contact::query()->whereKey((int) $request->input('id'))->get();
        } else {
            $s = mb_substr(trim((string) $request->input('search', '')), 0, 80);
            $rows = \App\Models\Contact::query()->when($s !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $this->like($s))->orWhere('phone', 'like', $this->like($s))->orWhere('email', 'like', $this->like($s))))
                ->orderByRaw('category = ? DESC', [\App\Models\Contact::VISITORS_CATEGORY])->orderBy('name')->limit(30)->get();
        }

        return response()->json($rows->map(fn ($c) => \App\Services\VisitorLogs\VisitorContacts::card($c))->values());
    }

    /**
     * "+ Add Contact" on the incoming-item form: the sender is saved as a Contact of the category "Visitors" and handed straight back. A name that is already a Contact is not duplicated:
     * the existing contact is offered instead (409).
     */
    public function storeContact(Request $request)
    {
        $this->need('create-incoming-items');
        $d = $request->validate(['name' => 'required|string|max:50', 'phone' => 'nullable|string|max:40', 'email' => 'nullable|email|max:190', 'details' => 'nullable|string|max:300'] + [],
            ['name.required' => __('Type the name of the sender.'), 'email.email' => __('That email address is not valid.')]);
        if (trim((string) ($d['phone'] ?? '')) === '' && trim((string) ($d['email'] ?? '')) === '') {
            return response()->json(['message' => __('Give a phone number or an email address.'), 'errors' => ['phone' => [__('Give a phone number or an email address.')]]], 422);
        }
        $name = trim(preg_replace('/\s+/', ' ', $d['name']));
        $phone = \App\Models\Visitor::cleanPhone($d['phone'] ?? null);
        $same = \App\Models\Contact::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first() ?: \App\Services\VisitorLogs\VisitorContacts::byPhone($phone);
        if ($same && ! $request->boolean('force')) {
            return response()->json(['message' => __('This looks like a contact that already exists.'), 'existing' => \App\Services\VisitorLogs\VisitorContacts::card($same)], 409);
        }
        if (\App\Models\Contact::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            return response()->json(['message' => __('A contact with this name already exists.'), 'errors' => ['name' => [__('A contact with this name already exists. Use it, or add something to tell them apart.')]]], 422);
        }
        $c = \App\Models\Contact::create(['name' => $name, 'category' => \App\Models\Contact::VISITORS_CATEGORY, 'phone' => $phone, 'email' => trim((string) ($d['email'] ?? '')) ?: null, 'details' => trim((string) ($d['details'] ?? '')) ?: null]);
        log_activity('Contact ' . $c->name . ' added from Incoming Mail', ['category' => $c->category], $c, 'Visitor Logs');

        return response()->json(['contact' => \App\Services\VisitorLogs\VisitorContacts::card($c)], 201);
    }
}

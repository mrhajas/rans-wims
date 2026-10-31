<?php

namespace App\Services\IdPass;

use App\Models\Contact;
use App\Models\IdPassCard;
use App\Models\IdPassCategory;
use App\Models\IdPassEvent;
use App\Models\Staff;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Issuing and looking after the IDs and passes. Every change is inside a transaction, leaves a line in the card's own history (idpass_events) and in the WIMS activity log,
 * and a number given out is written in idpass_numbers for good: a deleted, revoked or expired card never frees its number.
 */
class CardService
{
    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private static function today(): Carbon
    {
        return Carbon::today(config('app.timezone'));
    }

    /** The number that would be given next, for the form to show (the real number is taken inside the transaction when the card is saved). */
    public static function suggest(IdPassCategory $cat): array
    {
        $seq = max(1, (int) $cat->next_number);
        while (DB::table('idpass_numbers')->where('category_id', $cat->id)->where('seq_no', $seq)->exists()) {
            $seq++;
        }

        return ['seq' => $seq, 'number' => $cat->format($seq)];
    }

    /** Whether this person already holds an ID of this kind that is still in use (pending, active or suspended). */
    public static function openCardOf(string $kind, ?int $staffId, ?int $contactId, ?int $visitorId, ?int $exceptId = null): ?IdPassCard
    {
        $q = IdPassCard::query()->where('kind', $kind)->whereIn('status', IdPassCard::OPEN)->when($exceptId, fn ($w) => $w->where('id', '<>', $exceptId));
        if ($staffId) {
            return $q->where('staff_id', $staffId)->first();
        }
        if ($contactId) {
            return $q->where('contact_id', $contactId)->first();
        }
        if ($visitorId) {
            return $q->where('visitor_id', $visitorId)->first();
        }

        return null;
    }

    private static function log(string $what, IdPassCard $c, array $extra = []): void
    {
        log_activity($what, ['number' => $c->number, 'kind' => $c->kind, 'holder' => $c->full_name] + $extra, $c, 'RANS ID & Pass Management');
    }

    /**
     * Issues a card. $in: staff_id | contact_id | visitor_id, full_name, title, phone, company, issued_at, expires_at, host_name, purpose, authorisation_type, vehicle_registration,
     * vehicle_description, restrictions, notes. $photo: the result of PhotoService. $manualSeq: a number typed by someone who may (never one that was used before).
     */
    public static function issue(IdPassCategory $cat, array $in, ?array $photo, User $by, ?int $manualSeq = null, bool $quiet = false): IdPassCard
    {
        abort_unless($cat->is_active, 422, __('This category is switched off.'));
        $staff = $contact = null;
        if ($cat->pooled) {
            // a pass that is handed out and taken back belongs to nobody until it is given to a visitor
            $in['full_name'] = $cat->label ?: $cat->name;
            $in['staff_id'] = $in['contact_id'] = $in['visitor_id'] = null;
            $in['title'] = null;
        } elseif ($cat->kind === 'staff') {
            $staff = Staff::query()->find((int) ($in['staff_id'] ?? 0)) ?: self::fail('staff_id', __('Choose a staff member from the list.'));
            if (! in_array($staff->status, ['Active', 'Retired Active'], true)) {
                self::fail('staff_id', __('Only a staff member who is working can be given a Staff ID (this one is :s).', ['s' => $staff->status]));
            }
            $in['full_name'] = $staff->name;
            $in['title'] = trim((string) ($in['title'] ?? '')) !== '' ? $in['title'] : $staff->position;
            $in['phone'] = $in['phone'] ?? $staff->mobile_no;
        } elseif ($cat->kind === 'compound' || ($cat->kind === 'gate' && ! empty($in['contact_id']))) {
            $contact = Contact::query()->find((int) ($in['contact_id'] ?? 0)) ?: self::fail('contact_id', __('Choose a contact from the list.'));
            $allowed = array_map('mb_strtolower', (array) ($cat->contact_categories ?: []));
            if ($allowed && ! in_array(mb_strtolower(trim((string) $contact->category)), $allowed, true)) {
                self::fail('contact_id', __('This contact is in the category :c, which cannot be given a :n ID.', ['c' => $contact->category ?: __('none'), 'n' => $cat->name]));
            }
            $in['full_name'] = $contact->name;
            $in['title'] = $cat->kind === 'compound' ? $cat->name : ($in['title'] ?? null);
            $in['phone'] = $in['phone'] ?? $contact->phone;
        } else {
            if (trim((string) ($in['full_name'] ?? '')) === '') {
                self::fail('full_name', __('Type the name of the person.'));
            }
        }
        $stock = ! empty($in['_stock']);     // a Gate Pass made in advance, in the name of the company: no person, purpose or photograph yet
        if ($cat->kind === 'gate' && ! $cat->pooled && ! $stock && trim((string) ($in['purpose'] ?? '')) === '') {
            self::fail('purpose', __('Say why this person may enter (the reason for access).'));
        }
        if ($cat->vehicle_required && ! $stock && trim((string) ($in['vehicle_registration'] ?? '')) === '') {
            self::fail('vehicle_registration', __('The vehicle registration number is required for this category.'));
        }
        if ($cat->photo_required && ! $photo && ! $cat->pooled && ! $stock) {
            self::fail('photo', $staff ? __('This staff member has no profile photograph. Upload or take one for the ID.') : __('A photograph is required for this category.'));
        }
        $issued = Carbon::parse($in['issued_at'] ?? self::today(), config('app.timezone'))->startOfDay();
        $expires = ! empty($in['expires_at']) ? Carbon::parse($in['expires_at'], config('app.timezone'))->startOfDay() : null;
        if ($expires && $expires->lt($issued)) {
            self::fail('expires_at', __('The expiry date cannot be before the issue date.'));
        }

        $card = DB::transaction(function () use ($cat, $in, $photo, $by, $manualSeq, $staff, $contact, $issued, $expires, $stock) {
            $locked = IdPassCategory::query()->whereKey($cat->id)->lockForUpdate()->first();
            if ($open = self::openCardOf($cat->kind, $staff?->id, $contact?->id, ! empty($in['visitor_id']) ? (int) $in['visitor_id'] : null)) {
                self::fail($staff ? 'staff_id' : ($contact ? 'contact_id' : 'full_name'), __(':n already holds :k :num (:s). Renew, replace or revoke it first.', ['n' => $open->full_name, 'k' => __(IdPassCategory::KINDS[$open->kind]), 'num' => $open->number, 's' => $open->effective_status]));
            }
            $seq = $manualSeq ?: (int) $locked->next_number;
            while (DB::table('idpass_numbers')->where('category_id', $locked->id)->where('seq_no', $seq)->exists()) {
                if ($manualSeq) {
                    self::fail('number', __('The number :n has been used before and cannot be given again.', ['n' => $locked->format($seq)]));
                }
                $seq++;
            }
            $number = $locked->format($seq);
            if (DB::table('idpass_cards')->where('category_id', $locked->id)->where('number', $number)->exists()) {
                self::fail('number', __('The number :n is already on a card.', ['n' => $number]));
            }
            $locked->next_number = max((int) $locked->next_number, $seq + 1);
            $locked->save();
            $pending = $cat->requires_approval && ! $stock;   // a pass made in advance has no person yet: it is approved when it is given to one
            $card = IdPassCard::create([
                'kind' => $cat->kind, 'category_id' => $cat->id, 'number' => $number, 'seq_no' => $seq, 'token' => Str::random(40), 'status' => $pending ? IdPassCard::PENDING : IdPassCard::ACTIVE,
                'staff_id' => $staff?->id, 'contact_id' => $contact?->id, 'visitor_id' => ! empty($in['visitor_id']) ? (int) $in['visitor_id'] : null,
                'full_name' => trim(preg_replace('/\s+/', ' ', (string) $in['full_name'])), 'title' => self::clean($in['title'] ?? null), 'phone' => self::clean($in['phone'] ?? null), 'company' => self::clean($in['company'] ?? null),
                'photo_path' => $photo['path'] ?? null, 'photo_original' => $photo['original'] ?? null, 'photo_cutout' => $photo['cutout'] ?? null, 'photo_bg' => $photo['bg'] ?? null, 'issued_at' => $issued->toDateString(), 'expires_at' => $expires?->toDateString(),
                'host_name' => self::clean($in['host_name'] ?? null), 'purpose' => self::clean($in['purpose'] ?? null), 'authorisation_type' => self::clean($in['authorisation_type'] ?? null),
                'vehicle_registration' => ($v = self::clean($in['vehicle_registration'] ?? null)) ? mb_strtoupper($v) : null, 'vehicle_description' => self::clean($in['vehicle_description'] ?? null),
                'restrictions' => self::clean($in['restrictions'] ?? null), 'notes' => self::clean($in['notes'] ?? null), 'replaces_id' => $in['replaces_id'] ?? null,
                'created_by_user_id' => $by->id, 'status_by_user_id' => $by->id, 'status_at' => now(),
            ]);
            DB::table('idpass_numbers')->insert(['category_id' => $cat->id, 'seq_no' => $seq, 'number' => $number, 'card_id' => $card->id, 'manual' => (bool) $manualSeq, 'assigned_by_user_id' => $by->id, 'assigned_at' => now()]);
            IdPassEvent::record($card, 'issued', null, $card->status, array_filter(['number' => $number, 'manual' => (bool) $manualSeq, 'expires' => $expires?->toDateString(), 'bulk' => $in['_bulk'] ?? null, 'stock' => $stock ?: null, 'holder' => $stock ? $card->full_name : null], fn ($v) => $v !== null && $v !== false));

            return $card;
        });
        if (! $quiet) {     // a stock of passes made in one go is logged once, not once per pass
            self::log($card->status === IdPassCard::PENDING ? 'RANS ID requested' : 'RANS ID issued', $card, ['category' => $cat->name, 'expires' => $card->expires_at?->toDateString(), 'manual_number' => (bool) $manualSeq]);
            IdPassNotifier::fire($card->status === IdPassCard::PENDING ? 'approval_needed' : 'issued', $card, $by);
        }

        return $card;
    }

    /** Makes $count Gate Passes in advance, all in the name of the company ($holder), numbers in a row. Each is given to a person later (assign). @return IdPassCard[] */
    public static function issueStock(IdPassCategory $cat, int $count, User $by, ?string $expires = null, ?string $issued = null, ?string $ref = null): array
    {
        abort_unless($cat->kind === 'gate' && ! $cat->pooled, 422, __('Only Gate Passes of people can be made in advance.'));
        $count = max(1, min(200, $count));
        $holder = IdPassSettings::get('idpass_stock_holder');
        $ref = $ref ?: 'STOCK-' . now()->format('ymdHis') . '-' . Str::upper(Str::random(3));
        $made = [];
        DB::transaction(function () use ($cat, $count, $by, $expires, $issued, $holder, $ref, &$made) {
            for ($i = 0; $i < $count; $i++) {
                $made[] = self::issue($cat, ['full_name' => $holder, 'issued_at' => $issued ?: self::today()->toDateString(), 'expires_at' => $expires, '_stock' => true, '_bulk' => $ref, 'purpose' => null], null, $by, null, true);
            }
        });
        log_activity('RANS Gate Passes made in advance', ['category' => $cat->name, 'count' => count($made), 'from' => $made[0]->number, 'to' => end($made)->number, 'holder' => $holder, 'reference' => $ref], $made[0], 'RANS ID & Pass Management');

        return $made;
    }

    /** Gives a Gate Pass (usually one made in advance in the name of the company) to a person. The number, the QR code and the history stay; the holder, the contact and the reason change, and the old and the new holder are written to the history. */
    public static function assign(IdPassCard $card, array $in, User $by): IdPassCard
    {
        abort_unless($card->kind === 'gate' && in_array($card->status, IdPassCard::OPEN, true), 422, __('Only a Gate Pass that is still in use can be given to another person.'));
        $contact = ! empty($in['contact_id']) ? (Contact::query()->find((int) $in['contact_id']) ?: self::fail('contact_id', __('Choose a contact from the list.'))) : null;
        $visitorId = ! empty($in['visitor_id']) ? (int) $in['visitor_id'] : null;
        $name = $contact ? $contact->name : trim(preg_replace('/\s+/', ' ', (string) ($in['full_name'] ?? '')));
        if ($name === '') {
            self::fail('full_name', __('Choose the person or type the name.'));
        }
        if (trim((string) ($in['purpose'] ?? '')) === '') {
            self::fail('purpose', __('Say why this person may enter (the reason for access).'));
        }
        if ($open = self::openCardOf('gate', null, $contact?->id, $visitorId, $card->id)) {
            self::fail($contact ? 'contact_id' : 'full_name', __(':n already holds :k :num (:s). Renew, replace or revoke it first.', ['n' => $open->full_name, 'k' => __(IdPassCategory::KINDS[$open->kind]), 'num' => $open->number, 's' => $open->effective_status]));
        }
        $before = $after = [];
        $needsApproval = (bool) optional($card->category)->requires_approval;
        $c = self::change($card, 'holder_changed', function (IdPassCard $c) use ($in, $contact, $visitorId, $name, $by, $needsApproval, &$before, &$after) {
            $new = ['full_name' => $name, 'contact_id' => $contact?->id, 'visitor_id' => $visitorId, 'phone' => self::clean($in['phone'] ?? ($contact?->phone)), 'company' => self::clean($in['company'] ?? null), 'purpose' => self::clean($in['purpose']),
                'authorisation_type' => self::clean($in['authorisation_type'] ?? null), 'vehicle_registration' => ($v = self::clean($in['vehicle_registration'] ?? null)) ? mb_strtoupper($v) : null, 'vehicle_description' => self::clean($in['vehicle_description'] ?? null)];
            foreach ($new as $f => $v) {
                if ((string) $c->{$f} !== (string) $v) {
                    $before[$f] = $c->{$f};
                    $after[$f] = $v;
                    $c->{$f} = $v;
                }
            }
            if ($needsApproval && $c->status === IdPassCard::ACTIVE) {
                // a new person needs the approval of the category before the pass works for them
                $c->status = IdPassCard::PENDING;
                $c->approved_by_user_id = null;
                $c->approved_at = null;
                $c->decision_reason = null;
                $c->status_by_user_id = $by->id;
                $c->status_at = now();
            }
        }, null, function () use (&$before, &$after, $needsApproval) {
            return ['before' => $before, 'after' => $after, 'needs_approval' => $needsApproval ?: null];
        });
        if ($c->status === IdPassCard::PENDING) {
            IdPassNotifier::fire('approval_needed', $c, $by);
        }
        self::log('RANS Gate Pass given to a person', $c, ['before' => $before['full_name'] ?? null, 'after' => $after['full_name'] ?? $c->full_name]);

        return $c;
    }

    /** Makes a stock of numbered passes that are handed out and taken back (Visitor Passes): $count of them, numbers in a row. @return IdPassCard[] */
    public static function issuePool(IdPassCategory $cat, int $count, User $by, ?string $expires = null, ?string $ref = null): array
    {
        abort_unless($cat->pooled, 422, __('This category is not a stock of passes.'));
        $count = max(1, min(200, $count));
        $made = [];
        for ($i = 0; $i < $count; $i++) {
            $made[] = self::issue($cat, ['issued_at' => self::today()->toDateString(), 'expires_at' => $expires, '_bulk' => $ref], null, $by, null, true);
        }
        log_activity('RANS passes made', ['category' => $cat->name, 'count' => count($made), 'from' => $made[0]->number, 'to' => end($made)->number], $made[0], 'RANS ID & Pass Management');

        return $made;
    }

    private static function clean($v): ?string
    {
        $v = is_string($v) ? trim($v) : $v;

        return ($v === null || $v === '') ? null : (string) $v;
    }

    /** Runs a change on one card under a lock, writes its event, and returns it fresh. */
    private static function change(IdPassCard $card, string $event, \Closure $do, ?string $to = null, array|\Closure $detail = []): IdPassCard
    {
        $fresh = DB::transaction(function () use ($card, $event, $do, $to, $detail) {
            $c = IdPassCard::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();
            $from = $c->status;
            $do($c);
            $c->save();
            IdPassEvent::record($c, $event, $from, $to ?? $c->status, $detail instanceof \Closure ? $detail() : $detail);

            return $c;
        });

        return $fresh;
    }

    public static function approve(IdPassCard $card, User $by, ?string $remarks = null): IdPassCard
    {
        abort_unless($card->status === IdPassCard::PENDING, 422, __('Only a pass that is waiting for approval can be approved.'));
        if ((int) $card->created_by_user_id === (int) $by->id && ! $by->hasRole('Super Admin')) {
            abort(422, __('You asked for this pass, so someone else must approve it.'));
        }
        $c = self::change($card, 'approved', function (IdPassCard $c) use ($by, $remarks) {
            abort_unless($c->status === IdPassCard::PENDING, 422, __('This pass has already been decided.'));
            $c->status = IdPassCard::ACTIVE;
            $c->approved_by_user_id = $by->id;
            $c->approved_at = now();
            $c->decision_reason = $remarks;
            $c->status_by_user_id = $by->id;
            $c->status_at = now();
        }, IdPassCard::ACTIVE, ['remarks' => $remarks]);
        self::log('RANS pass approved', $c);
        IdPassNotifier::fire('approved', $c, $by);
        IdPassNotifier::fire('issued', $c, $by);

        return $c;
    }

    public static function reject(IdPassCard $card, User $by, string $reason): IdPassCard
    {
        abort_unless($card->status === IdPassCard::PENDING, 422, __('Only a pass that is waiting for approval can be rejected.'));
        $c = self::change($card, 'rejected', function (IdPassCard $c) use ($by, $reason) {
            abort_unless($c->status === IdPassCard::PENDING, 422, __('This pass has already been decided.'));
            $c->status = IdPassCard::REJECTED;
            $c->decision_reason = $reason;
            $c->approved_by_user_id = $by->id;
            $c->approved_at = now();
            $c->status_by_user_id = $by->id;
            $c->status_at = now();
            $c->status_reason = $reason;
        }, IdPassCard::REJECTED, ['reason' => $reason]);
        self::log('RANS pass rejected', $c, ['reason' => $reason]);
        IdPassNotifier::fire('rejected', $c, $by, $reason);

        return $c;
    }

    public static function suspend(IdPassCard $card, User $by, string $reason): IdPassCard
    {
        abort_unless($card->status === IdPassCard::ACTIVE, 422, __('Only an active ID can be suspended.'));
        $c = self::change($card, 'suspended', function (IdPassCard $c) use ($by, $reason) {
            abort_unless($c->status === IdPassCard::ACTIVE, 422, __('Only an active ID can be suspended.'));
            $c->status = IdPassCard::SUSPENDED;
            $c->status_by_user_id = $by->id;
            $c->status_at = now();
            $c->status_reason = $reason;
        }, IdPassCard::SUSPENDED, ['reason' => $reason]);
        self::log('RANS ID suspended', $c, ['reason' => $reason]);
        IdPassNotifier::fire('suspended', $c, $by, $reason);

        return $c;
    }

    public static function reactivate(IdPassCard $card, User $by): IdPassCard
    {
        abort_unless($card->status === IdPassCard::SUSPENDED, 422, __('Only a suspended ID can be reactivated.'));
        $c = self::change($card, 'reactivated', function (IdPassCard $c) use ($by) {
            abort_unless($c->status === IdPassCard::SUSPENDED, 422, __('Only a suspended ID can be reactivated.'));
            $c->status = IdPassCard::ACTIVE;
            $c->status_by_user_id = $by->id;
            $c->status_at = now();
            $c->status_reason = null;
        }, IdPassCard::ACTIVE);
        self::log('RANS ID reactivated', $c);

        return $c;
    }

    public static function revoke(IdPassCard $card, User $by, string $reason): IdPassCard
    {
        abort_unless(in_array($card->status, IdPassCard::OPEN, true), 422, __('This ID can no longer be revoked (it is :s).', ['s' => strtolower((string) $card->status)]));
        $c = self::change($card, 'revoked', function (IdPassCard $c) use ($by, $reason) {
            abort_unless(in_array($c->status, IdPassCard::OPEN, true), 422, __('This ID can no longer be revoked.'));
            $c->status = IdPassCard::REVOKED;
            $c->status_by_user_id = $by->id;
            $c->status_at = now();
            $c->status_reason = $reason;
        }, IdPassCard::REVOKED, ['reason' => $reason]);
        self::log('RANS ID revoked', $c, ['reason' => $reason]);
        IdPassNotifier::fire('revoked', $c, $by, $reason);

        return $c;
    }

    /** Extends the same card (same number) to a new expiry date. */
    public static function renew(IdPassCard $card, User $by, ?string $newExpiry, ?string $note = null): IdPassCard
    {
        abort_unless(in_array($card->status, [IdPassCard::ACTIVE, IdPassCard::SUSPENDED], true), 422, __('Only an active or suspended ID can be renewed.'));
        if ($newExpiry === null || $newExpiry === '') {
            $e = $card->category->expiryFrom(self::today());
            $newExpiry = $e?->toDateString();
        }
        if ($newExpiry && Carbon::parse($newExpiry)->lt(self::today())) {
            self::fail('expires_at', __('The new expiry date must be today or later.'));
        }
        $old = $card->expires_at?->toDateString();
        $c = self::change($card, 'renewed', function (IdPassCard $c) use ($newExpiry, $by) {
            $c->expires_at = $newExpiry ?: null;
            $c->expiry_notified_at = null;
            $c->status_by_user_id = $by->id;
            $c->status_at = now();
        }, null, ['from' => $old, 'to' => $newExpiry, 'note' => $note]);
        self::log('RANS ID renewed', $c, ['from' => $old, 'to' => $newExpiry]);

        return $c;
    }

    /** A new card (new number, new QR code) for the same person; the old one stops working. The old card keeps its history. */
    public static function replace(IdPassCard $card, User $by, string $reason, ?array $photo = null): IdPassCard
    {
        abort_unless(in_array($card->status, IdPassCard::OPEN, true), 422, __('Only an ID that is still in use can be replaced.'));
        $cat = $card->category;
        $new = DB::transaction(function () use ($card, $by, $reason, $photo, $cat) {
            $old = IdPassCard::query()->whereKey($card->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($old->status, IdPassCard::OPEN, true), 422, __('Only an ID that is still in use can be replaced.'));
            $from = $old->status;
            $old->status = IdPassCard::REPLACED;
            $old->status_by_user_id = $by->id;
            $old->status_at = now();
            $old->status_reason = $reason;
            $old->save();
            $in = $old->only(['staff_id', 'contact_id', 'visitor_id', 'full_name', 'title', 'phone', 'company', 'host_name', 'purpose', 'authorisation_type', 'vehicle_registration', 'vehicle_description', 'restrictions', 'notes'])
                + ['issued_at' => self::today()->toDateString(), 'expires_at' => $old->expires_at?->toDateString(), 'replaces_id' => $old->id];
            $photoInfo = $photo ?: ($old->photo_path ? ['path' => $old->photo_path, 'original' => $old->photo_original, 'cutout' => $old->photo_cutout, 'bg' => $old->photo_bg] : null);
            // the new card is made in the same transaction: no moment where the person has no valid card and no new one either
            $cat->requires_approval = false;
            $new = self::issue($cat, $in, $photoInfo, $by);
            $old->replaced_by_id = $new->id;
            $old->save();
            IdPassEvent::record($old, 'replaced', $from, IdPassCard::REPLACED, ['reason' => $reason, 'new_number' => $new->number]);

            return $new;
        });
        self::log('RANS ID replaced', $new, ['reason' => $reason, 'replaces' => $card->number]);
        IdPassNotifier::fire('replaced', $card->fresh(), $by, $reason);

        return $new;
    }

    /** Details (not the number, category or person link). The old and the new values are written to the history. */
    public static function update(IdPassCard $card, array $in, User $by): IdPassCard
    {
        $fields = ['title', 'phone', 'company', 'host_name', 'purpose', 'authorisation_type', 'vehicle_registration', 'vehicle_description', 'restrictions', 'notes'];
        if (in_array($card->kind, ['visitor', 'gate'], true)) {
            $fields[] = 'full_name';
        }
        $expires = array_key_exists('expires_at', $in) ? (self::clean($in['expires_at']) ?: null) : $card->expires_at?->toDateString();
        if ($expires && Carbon::parse($expires)->lt($card->issued_at)) {
            self::fail('expires_at', __('The expiry date cannot be before the issue date.'));
        }
        $before = $after = [];
        $c = self::change($card, 'edited', function (IdPassCard $c) use ($in, $fields, $expires, &$before, &$after) {
            foreach ($fields as $f) {
                if (! array_key_exists($f, $in)) {
                    continue;
                }
                $v = self::clean($in[$f]);
                if ($f === 'vehicle_registration' && $v) {
                    $v = mb_strtoupper($v);
                }
                if ($f === 'full_name' && $v === null) {
                    continue;
                }
                if ((string) $c->{$f} !== (string) $v) {
                    $before[$f] = $c->{$f};
                    $after[$f] = $v;
                    $c->{$f} = $v;
                }
            }
            if (($c->expires_at?->toDateString()) !== $expires) {
                $before['expires_at'] = $c->expires_at?->toDateString();
                $after['expires_at'] = $expires;
                $c->expires_at = $expires;
                $c->expiry_notified_at = null;
            }
        }, null, function () use (&$before, &$after) {
            return ['before' => $before, 'after' => $after];
        });
        if ($after) {
            self::log('RANS ID details changed', $c, ['before' => $before, 'after' => $after]);
        }

        return $c;
    }

    public static function setPhoto(IdPassCard $card, array $photo, User $by): IdPassCard
    {
        $old = $card->photo_path;
        $c = self::change($card, 'photo_changed', function (IdPassCard $c) use ($photo) {
            $c->photo_path = $photo['path'];
            $c->photo_original = $photo['original'] ?? $c->photo_original;
            $c->photo_cutout = $photo['cutout'] ?? null;
            $c->photo_bg = $photo['bg'] ?? null;
        }, null, ['low_resolution' => (bool) ($photo['low'] ?? false)]);
        self::log('RANS ID photograph changed', $c);

        return $c;
    }

    /** Every print or PDF is counted on the card (the history itself is idpass_prints). */
    public static function printed(IdPassCard $card, int $copies = 1): void
    {
        IdPassCard::query()->whereKey($card->id)->update(['print_count' => DB::raw('print_count + ' . max(1, $copies)), 'last_printed_at' => now()]);
    }
}

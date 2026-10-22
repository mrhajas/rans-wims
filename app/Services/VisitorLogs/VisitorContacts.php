<?php

namespace App\Services\VisitorLogs;

use App\Models\Contact;
use App\Models\Visitor;

/**
 * Visitors and the people who send things to reception are Contacts of the category "Visitors": one list of people for the whole of WIMS. A visitor of the directory is linked to
 * a Contact (an existing one with the same phone number, else a new one), and a sender added from the incoming-item form is created as a Contact in that category.
 */
class VisitorContacts
{
    /** The contact that already has this phone number (any way of writing it), if any. */
    public static function byPhone(?string $phone): ?Contact
    {
        $key = Visitor::phoneKey($phone);
        if (! $key) {
            return null;
        }

        return Contact::query()->whereNotNull('phone')->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '+', ''), '-', ''), '(', '') LIKE ?", ['%' . $key])->orderBy('id')->first();
    }

    private static function uniqueName(string $name, string $suffix): string
    {
        $name = mb_substr(trim($name), 0, 50);
        if (! Contact::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            return $name;
        }
        $try = mb_substr($name, 0, 50 - mb_strlen($suffix) - 3) . ' (' . $suffix . ')';
        $n = 2;
        while (Contact::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($try)])->exists()) {
            $try = mb_substr($name, 0, 50 - mb_strlen((string) $n) - 3) . ' (' . $n++ . ')';
        }

        return $try;
    }

    /** Gives a visitor its Contact (and returns it). Never throws: the visitor is saved whatever happens here. */
    public static function link(Visitor $v): ?Contact
    {
        try {
            if ($v->contact_id && ($c = Contact::query()->find($v->contact_id))) {
                return $c;
            }
            $c = self::byPhone($v->phone) ?: Contact::create(['name' => self::uniqueName($v->full_name, $v->reference), 'category' => Contact::VISITORS_CATEGORY, 'phone' => $v->phone, 'email' => $v->email,
                'details' => $v->company ? __('Company') . ': ' . $v->company : null]);
            $v->forceFill(['contact_id' => $c->id])->saveQuietly();

            return $c;
        } catch (\Throwable $e) {
            \Log::warning('Visitor contact not linked', ['visitor' => $v->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** A visitor's name, phone and email changed: the Contact that was made for them follows (a Contact that belongs to someone else's category is left alone). */
    public static function follow(Visitor $v): void
    {
        try {
            $c = $v->contact_id ? Contact::query()->find($v->contact_id) : null;
            if ($c && $c->category === Contact::VISITORS_CATEGORY) {
                $c->update(['name' => mb_strtolower($c->name) === mb_strtolower($v->full_name) ? $c->name : self::uniqueName($v->full_name, $v->reference), 'phone' => $v->phone ?: $c->phone, 'email' => $v->email ?: $c->email]);
            }
        } catch (\Throwable $e) {
            \Log::warning('Visitor contact not updated', ['visitor' => $v->id, 'error' => $e->getMessage()]);
        }
    }

    /** One line for the sender picker: {id, name, phone, email, category, label}. */
    public static function card(Contact $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'email' => $c->email, 'category' => $c->category, 'label' => trim($c->name . ($c->phone ? ' · ' . $c->phone : '') . ($c->category ? ' · ' . $c->category : ''))];
    }
}

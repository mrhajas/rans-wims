<?php

namespace App\Services\WimsConnect;

use App\Models\Staff;
use App\Models\User;
use App\Models\WimsConnectRecipientChannel;
use Illuminate\Support\Facades\Schema;

/**
 * Pulls every person's contact details into Recipients (the contact book) from what WIMS already knows about them: the email of their
 * login (or their staff record's personal email) and the mobile number on their staff record (or the number verified for SMS codes).
 * A pulled address is only a suggestion: it starts SWITCHED OFF, and nothing is sent to it until a Super Admin enables the channel for
 * that person. An address somebody typed in is never overwritten; a pulled one follows the user / staff record when that changes.
 */
class RecipientDirectory
{
    /** channel => address, from the user's own records. Empty when WIMS knows nothing for that channel. */
    public static function candidates(User $user): array
    {
        $staff = $user->relationLoaded('staff') ? $user->staff : $user->staff()->first();
        $phone = self::phone($staff?->mobile_no) ?: self::phone(data_get($user->extra_attributes, 'phone')) ?: self::phone($user->two_factor_phone);
        $email = self::email($user->email) ?: self::email($staff?->personal_email);

        return array_filter(['sms' => $phone, 'email' => $email, 'whatsapp' => $phone], fn ($v) => filled($v));
    }

    /** Creates / refreshes the pulled rows of one user. Returns how many rows changed. */
    public static function syncUser(User $user, ?\Illuminate\Support\Collection $rows = null): int
    {
        // the table check and the channel rows are passed in by syncAll for a whole chunk of people (they were asked again for every person)
        if ($rows === null) {
            if (! self::ready()) {
                return 0;
            }
            $rows = WimsConnectRecipientChannel::where('user_id', $user->id)->get()->keyBy('channel');
        }
        $changed = 0;
        foreach (self::candidates($user) as $channel => $address) {
            $row = $rows->get($channel);
            if (! $row) {
                WimsConnectRecipientChannel::create(['user_id' => $user->id, 'channel' => $channel, 'address' => $address, 'is_enabled' => false, 'auto_filled' => true]);
                $changed++;
            } elseif ($row->auto_filled && $row->address !== $address) {
                $row->update(['address' => $address]);
                $changed++;
            }
        }

        return $changed;
    }

    private static ?bool $ready = null;

    private static function ready(): bool
    {
        return self::$ready ??= \App\Support\SchemaCache::hasTable('wims_connect_recipient_channels') && \App\Support\SchemaCache::hasColumn('wims_connect_recipient_channels', 'auto_filled');
    }

    /** Every user of the account (deactivated ones are never alerted, but their details are kept in the book). */
    public static function syncAll(int $accountId): int
    {
        $n = 0;
        if (! self::ready()) {
            return 0;
        }
        User::where('account_id', $accountId)->with('staff')->chunkById(200, function ($users) use (&$n) {
            $all = WimsConnectRecipientChannel::whereIn('user_id', $users->pluck('id'))->get()->groupBy('user_id');
            foreach ($users as $u) {
                $n += self::syncUser($u, ($all->get($u->id) ?? collect())->keyBy('channel'));
            }
        });

        return $n;
    }

    public static function phone(?string $raw): ?string
    {
        return \App\Support\TzPhone::normalize($raw);
    }

    public static function email(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        return ($raw !== '' && filter_var($raw, FILTER_VALIDATE_EMAIL)) ? $raw : null;
    }
}

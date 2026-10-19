<?php

namespace App\Support\Accounts;

use App\Models\Accounts\AcctTransaction;
use App\Models\Accounts\AcctTransfer;

/**
 * One place that says which ability is needed for which money movement. Money in, money out, transfers and adjustments each have their own
 * "create", "approve" and "post" ability, so a role can be allowed to record payments but not approve them, approve transfers but not post them, and so on.
 * Approval is always required (see AcctPosting::requiredApprovals): these abilities say WHO may give it.
 */
class AcctPermissions
{
    public const SUBJECTS = ['inflow' => 'inflows', 'outflow' => 'outflows', 'transfer' => 'transfers', 'adjustment' => 'adjustments'];

    /** inflow | outflow | transfer | adjustment, for a transaction or a transfer. */
    public static function subject($record): string
    {
        if ($record instanceof AcctTransfer) {
            return 'transfer';
        }
        if ($record instanceof AcctTransaction) {
            return $record->kind === 'adjustment' ? 'adjustment' : ($record->direction === 'in' ? 'inflow' : 'outflow');
        }

        return (string) $record;
    }

    /** e.g. need('approve', $payment) = "approve-acct-outflows". */
    public static function need(string $action, $record): string
    {
        return $action . '-acct-' . self::SUBJECTS[self::subject($record)];
    }

    /** Every subject this person holds the ability for ("approve" or "post" or "create"). */
    public static function subjectsFor($user, string $action): array
    {
        return array_values(array_filter(array_keys(self::SUBJECTS), fn ($s) => $user && $user->can($action . '-acct-' . self::SUBJECTS[$s])));
    }

    /** Does a transaction row belong to one of these subjects? Used to limit lists to what the person may decide on. */
    public static function scopeTransactions($q, array $subjects)
    {
        return $q->where(function ($w) use ($subjects) {
            $w->whereRaw('1 = 0');
            if (in_array('inflow', $subjects, true)) {
                $w->orWhere(fn ($x) => $x->where('direction', 'in')->where('kind', '<>', 'adjustment'));
            }
            if (in_array('outflow', $subjects, true)) {
                $w->orWhere(fn ($x) => $x->where('direction', 'out')->where('kind', '<>', 'adjustment'));
            }
            if (in_array('adjustment', $subjects, true)) {
                $w->orWhere('kind', 'adjustment');
            }
        });
    }
}

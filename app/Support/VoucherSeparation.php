<?php

namespace App\Support;

use App\Models\PaymentVoucher;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Separation of duties on Payment Vouchers: nobody takes two steps of the same voucher. The person who prepared it cannot approve it, the person who approved
 * it (or prepared it) cannot authorise it, and the person who authorised it cannot record its payment. Applies to everyone, Super Admins included. A Super
 * Admin can switch it off on the Payment Vouchers page (setting `pv_separation_of_duties`, on by default).
 */
class VoucherSeparation
{
    public const KEY = 'pv_separation_of_duties';

    /** Read straight from the settings table each time, so a change is seen at once. */
    public static function enabled(): bool
    {
        $v = DB::table('settings')->where('tec_key', self::KEY)->where('account_id', (int) (function_exists('getAccountId') ? (getAccountId() ?: 1) : 1))->value('tec_value');

        return $v === null || $v === '' ? true : $v !== '0';
    }

    public static function setEnabled(bool $on): void
    {
        $account = (int) (function_exists('getAccountId') ? (getAccountId() ?: 1) : 1);
        $row = DB::table('settings')->where('tec_key', self::KEY)->where('account_id', $account);
        if ($row->exists()) {
            $row->update(['tec_value' => $on ? '1' : '0']);
        } else {
            DB::table('settings')->insert(['tec_key' => self::KEY, 'tec_value' => $on ? '1' : '0', 'account_id' => $account]);
        }
    }

    /** Everybody who prepared the voucher: whoever made it and whoever edited or submitted it afterwards (kept in the voucher's extra attributes). */
    public static function handlers(PaymentVoucher $v): array
    {
        $list = [];
        try {
            $list = (array) $v->extra_attributes->get('handled_by', []);
        } catch (\Throwable $e) {
            $list = [];
        }
        if ($v->prepared_by_user_id) {
            $list[] = $v->prepared_by_user_id;
        }

        return array_values(array_unique(array_map('intval', $list)));
    }

    /** Remembers that this person edited or submitted the voucher, so they cannot then approve or authorise it either. */
    public static function noteHandler(PaymentVoucher $v, ?User $u): void
    {
        if (! $u) {
            return;
        }
        $list = self::handlers($v);
        if (! in_array((int) $u->id, $list, true)) {
            $list[] = (int) $u->id;
            $v->extra_attributes->set('handled_by', $list);
            $v->save();
        }
    }

    /** Why this person may not approve the voucher, or null when they may. */
    public static function approveRefusal(PaymentVoucher $v, ?User $u): ?string
    {
        return self::enabled() && $u && in_array((int) $u->id, self::handlers($v), true)
            ? __('You prepared :pv, so someone else must approve it.', ['pv' => $v->pv_number]) : null;
    }

    /** Why this person may not authorise the voucher, or null when they may. */
    public static function authoriseRefusal(PaymentVoucher $v, ?User $u): ?string
    {
        if (! self::enabled() || ! $u) {
            return null;
        }
        if ($v->approved_by_user_id && (int) $v->approved_by_user_id === (int) $u->id) {
            return __('You approved :pv, so someone else must authorise it.', ['pv' => $v->pv_number]);
        }
        if (in_array((int) $u->id, self::handlers($v), true)) {
            return __('You prepared :pv, so someone else must authorise it.', ['pv' => $v->pv_number]);
        }

        return null;
    }

    /** Why this person may not record the payment of the voucher, or null when they may. */
    public static function payRefusal(PaymentVoucher $v, ?User $u): ?string
    {
        return self::enabled() && $u && $v->authorised_by_user_id && (int) $v->authorised_by_user_id === (int) $u->id
            ? __('You authorised :pv, so someone else must record its payment.', ['pv' => $v->pv_number]) : null;
    }
}

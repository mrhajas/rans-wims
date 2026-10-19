<?php

namespace App\Models\Accounts;

class AcctAccount extends AcctModel
{
    protected $table = 'acct_accounts';

    public const KINDS = [
        'bank_account' => 'Bank Account', 'cash_in_hand' => 'Cash in Hand', 'petty_cash' => 'Petty Cash', 'project_cash' => 'Project Cash', 'office_cash' => 'Office Cash',
        'foreign_cash' => 'Foreign Currency Cash', 'mobile_money' => 'Mobile Money Account', 'other' => 'Other Financial Account',
    ];

    /** Which section of the module a kind belongs to. */
    public const GROUPS = ['bank' => ['bank_account'], 'cash' => ['cash_in_hand', 'petty_cash', 'project_cash', 'office_cash', 'foreign_cash'], 'other' => ['mobile_money', 'other']];

    protected $casts = ['is_primary' => 'boolean', 'allow_negative' => 'boolean', 'requires_approval' => 'boolean', 'alert_user_ids' => 'array', 'allowed_user_ids' => 'array', 'closed_at' => 'datetime', 'balance_updated_at' => 'datetime', 'opening_date' => 'date'];

    /** Accounts a person looks after or is named on. Ids saved as text ("12") count as well as numbers (12). */
    public function scopeVisibleTo($q, int $userId)
    {
        return $q->where(fn ($w) => $w->where('custodian_user_id', $userId)->orWhereJsonContains('allowed_user_ids', $userId)->orWhereJsonContains('allowed_user_ids', (string) $userId));
    }

    public static function groupOf(string $kind): string
    {
        foreach (self::GROUPS as $g => $kinds) {
            if (in_array($kind, $kinds, true)) {
                return $g;
            }
        }

        return 'other';
    }

    public function isOpenForPosting(): bool
    {
        return $this->status === 'active';
    }
}

<?php

namespace App\Policies;

use App\Models\TransportRecord;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class TransportRecordPolicy
{
    use HandlesAuthorization;

    /**
     * Structurally mirrors LabourPolicy::createPaymentVoucher() (checked
     * per-record before TransportRecordController::createPaymentVoucher()
     * opens its DB transaction, so a partially-invalid multi-select fails
     * fast with a clear message) — but NOT its hardcoded Super Admin role
     * bypass. Every other Transport Routes ability (see
     * verify-transport-records' own migration docblock) is deliberately
     * permission-only so Super Admin's access stays revocable/reassignable
     * like any other role; Super Admin already holds
     * create-payment-voucher-from-transport via the permissions migration,
     * so dropping the hasRole() branch changes nothing about who currently
     * has this ability, only how it's granted.
     */
    public function createPaymentVoucher(User $user, TransportRecord $transportRecord): bool
    {
        if ($transportRecord->payment_voucher_id !== null || $transportRecord->payment_status === TransportRecord::STATUS_CANCELLED) {
            return false;
        }

        return $user->can('create-payment-voucher-from-transport');
    }
}

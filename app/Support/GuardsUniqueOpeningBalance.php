<?php

namespace App\Support;

use App\Models\WorkforceAgreement;
use Illuminate\Contracts\Validation\Validator;

/**
 * Shared by WorkforceAgreementStoreRequest and UpdateRequest — an opening
 * balance is meant to be a one-time carry-over from before this system
 * tracked the worker's balance on a project, so a single worker/project
 * pairing must never accumulate more than one. Further work after that goes
 * through Agreed Amount on a new (or the same) agreement instead, never a
 * second opening balance.
 */
class GuardsUniqueOpeningBalance
{
    public static function check(array $data, Validator $validator, ?int $excludeId = null): void
    {
        $openingBalance = (float) ($data['opening_balance'] ?? 0);
        if ($openingBalance <= 0) {
            return;
        }

        $query = WorkforceAgreement::query()
            ->where('warehouse_id', $data['warehouse_id'] ?? null)
            ->where('status', WorkforceAgreement::STATUS_ACTIVE)
            ->where('opening_balance', '>', 0);

        if (($data['payee_type'] ?? null) === WorkforceAgreement::PAYEE_TYPE_STAFF) {
            $query->where('staff_id', $data['staff_id'] ?? null);
        } else {
            $query->where('contact_id', $data['contact_id'] ?? null);
        }

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $existing = $query->first();
        if ($existing) {
            $validator->errors()->add(
                'opening_balance',
                __('This worker already has an opening balance recorded on this project via :reference. Only one opening balance is allowed per worker per project, record further amounts through the Agreed Amount instead.', [
                    'reference' => $existing->reference,
                ])
            );
        }
    }
}

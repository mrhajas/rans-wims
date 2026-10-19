<?php

namespace App\Support;

use App\Models\LabourListItem;
use App\Models\User;
use App\Models\WorkforceAgreement;
use Illuminate\Contracts\Validation\Validator;

/**
 * Shared by LabourListStoreRequest and LabourListUpdateRequest — a Labour
 * List line that references a WorkforceAgreement must not request more than
 * that agreement's current outstanding balance. Deliberately conditional on
 * workforce_agreement_id actually being set: agreements are an opt-in
 * feature, so a line with no agreement selected is never blocked by this
 * check, keeping the existing Labour submission flow fully backward
 * compatible for any project that hasn't adopted agreements yet.
 */
class GuardsWorkforceBalance
{
    public static function checkItems(array $items, Validator $validator, User $user): void
    {
        if ($user->hasRole(['Super Admin', 'Admin'])) {
            return;
        }

        // Running per-agreement tally so multiple lines in this SAME
        // submission that draw from the same agreement are checked against
        // what's truly left after each other, not the same starting figure.
        $reservedInThisSubmission = [];

        foreach ($items as $index => $item) {
            $agreementId = $item['workforce_agreement_id'] ?? null;
            if (! $agreementId || empty($item['rate']) || empty($item['rate_type'])) {
                continue;
            }

            $agreement = WorkforceAgreement::find($agreementId);
            if (! $agreement) {
                continue;
            }

            // The agreement chosen for this line must actually belong to the
            // same worker the line is for — without this, a request for
            // Worker C could reference Worker D's agreement (any agreement
            // ID is accepted by the plain exists: rule alone), passing the
            // balance check below against room that isn't really theirs and
            // corrupting Worker D's ledger once paid.
            $payeeType = $item['payee_type'] ?? null;
            $belongsToPayee = $payeeType === $agreement->payee_type && match ($payeeType) {
                LabourListItem::PAYEE_TYPE_STAFF => (int) ($item['staff_id'] ?? 0) === (int) $agreement->staff_id,
                LabourListItem::PAYEE_TYPE_EXTERNAL => (int) ($item['contact_id'] ?? 0) === (int) $agreement->contact_id,
                default => false,
            };

            if (! $belongsToPayee) {
                $validator->errors()->add(
                    "items.{$index}.workforce_agreement_id",
                    __('This Workforce Agreement does not belong to the selected worker.')
                );

                continue;
            }

            $requested = LabourListItem::computeAmount(
                $item['rate_type'],
                (float) $item['rate'],
                isset($item['days']) && $item['days'] !== '' ? (float) $item['days'] : null,
                isset($item['hours']) && $item['hours'] !== '' ? (float) $item['hours'] : null
            );

            $alreadyReserved = $reservedInThisSubmission[$agreementId] ?? 0.0;
            $available = round($agreement->availableBalance() - $alreadyReserved, 2);

            if ($requested > $available) {
                $validator->errors()->add(
                    "items.{$index}.rate",
                    __('Requested amount (:requested) exceeds :name\'s available balance of :balance on this agreement (:committed already committed to other in-progress requests).', [
                        'requested' => number_format($requested, 2),
                        'name' => $agreement->payee_name,
                        'balance' => number_format(max($available, 0), 2),
                        'committed' => number_format($agreement->committedUnpaidAmount() + $alreadyReserved, 2),
                    ])
                );

                continue;
            }

            $reservedInThisSubmission[$agreementId] = $alreadyReserved + $requested;
        }
    }
}

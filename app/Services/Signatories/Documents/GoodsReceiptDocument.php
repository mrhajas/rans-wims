<?php

namespace App\Services\Signatories\Documents;

use App\Models\GoodsReceipt;

/**
 * Goods Receipt (Supplier Records).
 *
 * A receipt has ONE workflow step: store() creates it already Confirmed, with received_by_user_id = the signed-in user. There is no draft, no
 * verification and no approval. The action is therefore:
 *  - received  received_by_user_id + created_at (the moment the receipt was entered in WIMS; date_received is only the DATE the goods arrived, entered by
 *              the user and possibly earlier, so it is printed as a date but is not a signing time). GOOD.
 * Nothing else is invented: no "prepared" (it is the same person and the same instant), no "verified".
 *
 * A Super Admin can later edit a confirmed receipt (quantities, date, remarks). That does not change received_by_user_id, so the stamp keeps
 * describing the person who first recorded the receipt; the edit is in the activity log ("Goods Receipt edited").
 *
 * State: a receipt exists only once confirmed and nothing signs after it: always 'final' (Confirmed). Deleted receipts do not resolve.
 */
final class GoodsReceiptDocument implements SignableDocument
{
    public static function key(): string
    {
        return 'goods_receipt';
    }

    public static function label(): string
    {
        return 'Goods Receipt';
    }

    public static function actions(): array
    {
        return ['received' => 'Received (goods receipt recorded)'];
    }

    public function find(int $id): ?object
    {
        return GoodsReceipt::query()->find($id);
    }

    public function state(object $record): string
    {
        return 'final';
    }

    public function history(object $record): array
    {
        $at = StockSigningGuard::at($record->created_at);
        $userId = $record->received_by_user_id ? (int) $record->received_by_user_id : null;
        $ok = $userId && $at;

        return ['received' => ['user_id' => $userId, 'at' => $at, 'name' => null, 'quality' => $ok ? 'good' : 'partial', 'note' => $ok ? null : 'Who received the goods, or when it was recorded, is not fully recorded on this receipt.']];
    }

    /** A copy of a receipt as it will be once $userId records it (nothing is saved): for the finalisation guard. */
    public function asIfRecorded(int $userId): object
    {
        return (new GoodsReceipt())->forceFill(['status' => GoodsReceipt::STATUS_CONFIRMED, 'received_by_user_id' => $userId, 'created_at' => now()]);
    }

    public static function defaultMappings(): array
    {
        return [
            ['role' => 'received_by', 'action' => 'received', 'slot' => 1, 'required' => false, 'show_when' => 'always'],
        ];
    }

    public function reference(object $record): string
    {
        return (string) ($record->reference ?? ('#' . $record->id));
    }
}

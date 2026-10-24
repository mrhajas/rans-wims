<?php

namespace App\Http\Requests;

use App\Models\IssueTransaction;
use App\Models\IssueTransactionItem;
use App\Models\Item;
use App\Models\Stock;
use App\Models\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class IssueMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'issue_date' => 'required|date',
            'warehouse_id' => 'required|exists:warehouses,id',
            'project' => 'required|string|max:190',
            'issued_to' => 'required|string|max:190',
            'contact_number' => 'nullable|string|max:60',
            'department' => 'nullable|string|max:120',
            'expected_return_date' => 'nullable|date',
            'remarks' => 'nullable|string',

            'attachments' => 'nullable|array',
            'attachments.*' => 'mimes:' . attachment_exts('jpg,png,pdf,docx,xlsx,zip'),

            'items' => 'required|array|min:1',
            'items.*.issue_transaction_item_id' => 'nullable|integer',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.unit_id' => 'nullable|exists:units,id',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.remarks' => 'nullable|string',
        ];
    }

    protected function prepareForValidation(): void
    {
        $issueDate = $this->input('issue_date');
        if (!is_string($issueDate) || $issueDate === '') {
            return;
        }

        try {
            $this->merge([
                'issue_date' => Carbon::parse($issueDate)->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Keep the original value so the validator can return a user-facing message.
        }
    }

    protected function passedValidation(): void
    {
        $warehouseId = (int) $this->input('warehouse_id');
        $items = collect($this->input('items', []));

        $issue = $this->route('issueMaterial');
        $editingIssueId = $issue instanceof IssueTransaction ? (int) $issue->id : null;

        $originalItems = collect();
        if ($editingIssueId) {
            $originalItems = IssueTransactionItem::query()
                ->where('issue_transaction_id', $editingIssueId)
                ->get(['id', 'item_id', 'issued_quantity'])
                ->keyBy('id');
        }

        $errors = [];

        $grouped = $items->groupBy('item_id');
        foreach ($grouped as $itemId => $lines) {
            $itemId = (int) $itemId;
            $stock = (float) (Stock::query()
                ->whereNull('variation_id')
                ->where('warehouse_id', $warehouseId)
                ->where('item_id', $itemId)
                ->value('quantity') ?? 0);

            $required = 0.0;
            foreach ($lines as $line) {
                $lineQty = (float) ($line['quantity'] ?? 0);
                $unit = !empty($line['unit_id']) ? Unit::query()->find((int) $line['unit_id']) : null;
                $baseQty = $unit ? (float) convert_to_base_quantity($lineQty, $unit) : $lineQty;
                $required += $baseQty;

                // While editing, allow the existing line quantity for the same item to be re-used.
                $lineId = (int) ($line['issue_transaction_item_id'] ?? 0);
                if ($lineId && $originalItems->has($lineId)) {
                    $original = $originalItems->get($lineId);
                    if ((int) $original->item_id === $itemId) {
                        $required -= (float) $original->issued_quantity;
                    }
                }
            }

            if ($required > $stock) {
                $item = Item::query()->find($itemId);
                $name = $item ? $item->name : ('Item #' . $itemId);
                $errors['items'] = 'Insufficient stock for ' . $name . '. Required: ' . formatNumber($required, 4) . ', available: ' . formatNumber($stock, 4) . '.';
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }
}

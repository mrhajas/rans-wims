<?php

namespace App\Http\Requests;

use App\Models\MaterialReallocationItem;
use Illuminate\Foundation\Http\FormRequest;

class MaterialReallocationReceiveRequest extends FormRequest
{
    public function authorize()
    {
        return auth()->check();
    }

    public function rules()
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer|exists:material_reallocation_items,id',
            'items.*.received_quantity' => 'required|numeric|gte:0',
            'attachments' => 'nullable',
            'attachments.*' => 'mimes:pdf,doc,docx,jpg,jpeg,png',
        ];
    }

    /**
     * received_quantity alone (gte:0) had no upper bound — Final Approval
     * deducts exactly this value from the source warehouse's stock with no
     * re-check, so an unbounded figure here could drive stock negative.
     * Capped against the item's own allocated_quantity, looked up
     * server-side rather than trusted from the client.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $items = $this->input('items', []);
            $ids = collect($items)->pluck('id')->filter()->all();
            $allocated = MaterialReallocationItem::whereIn('id', $ids)->pluck('allocated_quantity', 'id');

            foreach ($items as $index => $item) {
                $itemId = $item['id'] ?? null;
                if (! $itemId || ! isset($allocated[$itemId])) {
                    continue;
                }

                $cap = (float) $allocated[$itemId];
                $received = (float) ($item['received_quantity'] ?? 0);
                if ($received > $cap + 0.0001) {
                    $validator->errors()->add(
                        "items.{$index}.received_quantity",
                        __('Received quantity (:received) cannot exceed the allocated quantity (:allocated).', [
                            'received' => $received, 'allocated' => $cap,
                        ])
                    );
                }
            }
        });
    }
}

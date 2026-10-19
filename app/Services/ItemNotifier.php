<?php

namespace App\Services;

use App\Models\Item;
use App\Services\Workflow\WorkflowMessenger;

/**
 * The one door every Items event goes through (created, edited, deleted, restored, deleted for good, imported, used copy created, price
 * history changed, price applied). Who is told, on which channels and with which words is set on the Items workflow page (items/workflow).
 * A failure to notify never stops the work that triggered it.
 */
class ItemNotifier
{
    /** $item is left null when the item no longer exists (deleted), so nothing tries to use a record that is gone. */
    public static function fire(string $event, string $title, string $message, ?Item $item = null, array $ctx = [], array $values = []): void
    {
        try {
            $actor = auth()->user();
            if ($item && ! isset($values['item'])) {
                $values += ['item' => (string) $item->name, 'code' => (string) $item->code, 'price' => number_format((float) $item->price, 2)];
            }
            WorkflowMessenger::dispatch('items', $event, $item, $actor, $ctx + [
                'account_id' => $item?->account_id ?? $actor?->account_id ?? 1, 'title' => $title, 'message' => $message,
                'url' => $item && $item->exists ? route('items.show', $item->id) : route('items.index'),
                'values' => $values, 'dedupe' => $event . '|' . ($item?->getKey() ?? md5($message)) . '|' . intdiv(time(), 20),
                'details' => array_filter([__('Item') => $values['item'] ?? null, __('Code') => $values['code'] ?? null, __('Done by') => $actor?->name], fn ($v) => filled($v)),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ItemNotifier failed', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /** "Name (CODE)" for the sentences. */
    public static function label(Item $item): string
    {
        return $item->name . ($item->code ? ' (' . $item->code . ')' : '');
    }

    public static function by(): string
    {
        return auth()->user() ? ' by ' . auth()->user()->name : '';
    }
}

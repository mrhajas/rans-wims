<?php

namespace App\Services;

use App\Models\FundAllocation;
use App\Models\User;
use App\Services\Workflow\WorkflowMessenger;

/**
 * Funds Allocation notices that do not come from a status step of one sheet: the settings screens (accounts, categories, payment
 * methods, order) and the sheet template. Who is told, on which channels and with which words is set on the Funds Allocation workflow
 * page. Events: settings_changed, template_changed, template_generated.
 */
class FundAllocationNotifier
{
    public static function settings(string $what, string $change, ?User $actor = null): void
    {
        $actor ??= auth()->user();
        self::send('settings_changed', null, $actor, __('Funds Allocation Settings Changed'), $what . ' ' . $change . ' by ' . ($actor?->name ?? '') . '.', ['what' => $what, 'change' => $change]);
    }

    public static function template(string $event, ?FundAllocation $generated, ?User $actor, string $message): void
    {
        $title = $event === 'template_generated' ? __('Draft Generated From The Template') : __('Allocation Template Saved');
        self::send($event, $generated, $actor ?? auth()->user(), $title, $message, []);
    }

    private static function send(string $event, ?FundAllocation $a, ?User $actor, string $title, string $message, array $values): void
    {
        try {
            $a?->loadMissing(['preparedBy:id,name']);
            WorkflowMessenger::dispatch('fund_allocations', $event, $a, $actor, [
                'account_id' => $actor?->account_id ?? 1, 'skip_actor' => true,
                'title' => $title, 'message' => $message, 'url' => $a ? route('fund-allocations.show', $a->id) : route('fund-allocations.index'),
                'dedupe' => md5($event . '|' . $message) . '|' . intdiv(time(), 20),
                'details' => array_filter([__('Reference') => $a?->reference, __('Change') => $values['what'] ?? null], fn ($v) => filled($v)),
                'values' => $values + [
                    'reference' => $a?->reference ?? '', 'sheettitle' => $a?->title ?? '', 'date' => optional($a?->allocation_date)->format('d M Y') ?? '',
                    'preparer' => optional($a?->preparedBy)->name ?? '', 'approver' => '', 'payer' => '', 'status' => $a?->status ?? '',
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}

<?php

namespace App\Services\VisitorLogs;

use App\Models\User;
use App\Models\VisitorIncomingItem;
use App\Models\VisitorVisit;
use App\Services\Workflow\WorkflowMessenger;

/**
 * Hands a Visitor Logs event to its Notification page (who is told, on which channel, with which words). Never throws: a failed SMS or email must not stop a visit or
 * an item from being saved. The messages carry no link, and a confidential item is announced without its sender or subject.
 */
class VisitorNotifier
{
    public static function visit(string $event, VisitorVisit $v, ?User $actor = null): void
    {
        try {
            $v->loadMissing(['visitor:id,full_name,company', 'host:id,name', 'warehouse:id,name']);
            $name = optional($v->visitor)->full_name ?: '';
            $company = (string) optional($v->visitor)->company;
            $who = $company !== '' ? $name . ' ' . __('from') . ' ' . $company : $name;
            $fmt = fn ($d) => $d ? $d->format('d M Y, H:i') : '';
            $minutes = $v->check_out_at ? (int) $v->duration_minutes : $v->minutesOnPremises();
            $host = $v->host_name ?: optional($v->host)->name ?: '';
            WorkflowMessenger::dispatch('visitor_logs', $event, $v, $actor, [
                'account_id' => $v->account_id ?? $actor?->account_id ?? 1,
                'title' => __('Visitor :r', ['r' => $v->reference]), 'message' => $who . ' - ' . $host,
                'url' => route('visitor-logs.visits.show', $v->id), 'owner_user_id' => (int) $v->host_user_id,
                'dedupe' => 'visit-' . $v->id . '-' . $event . ($event === 'visitor_checked_out' ? '-' . ($v->check_out_at ? $v->check_out_at->timestamp : '') : ''),
                'values' => ['visit_ref' => $v->reference, 'visitor' => $name, 'company' => $company, 'who' => $who, 'host' => $host, 'purpose' => (string) ($v->purpose_other ?: $v->purpose),
                    'checkin_time' => $fmt($v->check_in_at), 'checkout_time' => $fmt($v->check_out_at), 'duration' => VisitorVisit::durationText($minutes), 'location' => VisitorSettings::location(), 'project' => (string) optional($v->warehouse)->name],
                'details' => array_filter([__('Visit') => $v->reference, __('Visitor') => $name, __('Company') => $company, __('Person visited') => $host, __('Purpose') => (string) ($v->purpose_other ?: $v->purpose), __('Check-in') => $fmt($v->check_in_at), __('Reception') => VisitorSettings::location()]),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Visitor alert failed', ['visit' => $v->id, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }

    public static function item(string $event, VisitorIncomingItem $i, ?User $actor = null): void
    {
        try {
            $i->loadMissing('warehouse:id,name');
            $type = (string) ($i->item_type ?: __('Item'));
            // a confidential item is announced as such: never its sender or its subject
            $line = $i->is_confidential ? __('A confidential :t', ['t' => mb_strtolower($type)]) : trim(__('A :t', ['t' => mb_strtolower($type)]) . ($i->sender_name || $i->sender_company ? ' ' . __('from') . ' ' . ($i->sender_company ?: $i->sender_name) : ''));
            $fmt = fn ($d) => $d ? $d->format('d M Y, H:i') : '';
            WorkflowMessenger::dispatch('visitor_logs', $event, $i, $actor, [
                'account_id' => $i->account_id ?? $actor?->account_id ?? 1,
                'title' => __('Item :r', ['r' => $i->reference]), 'message' => $line,
                'url' => route('visitor-logs.items.show', $i->id), 'owner_user_id' => (int) $i->recipient_user_id,
                'dedupe' => 'item-' . $i->id . '-' . $event . '-' . ($i->status_changed_at ? $i->status_changed_at->timestamp : ''),
                'values' => ['item_ref' => $i->reference, 'item_line' => $line, 'received_time' => $fmt($i->received_at), 'handed_to' => (string) $i->handed_to, 'handed_time' => $fmt($i->handed_at), 'location' => VisitorSettings::location(), 'project' => (string) optional($i->warehouse)->name],
                'details' => array_filter([__('Item') => $i->reference, __('Type') => $type, __('Received') => $fmt($i->received_at), __('Reception') => VisitorSettings::location()]),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Incoming item alert failed', ['item' => $i->id, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }
}

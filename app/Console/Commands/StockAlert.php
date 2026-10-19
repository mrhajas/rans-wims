<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\User;
use App\Mail\LowStockAlert;
use App\Services\WimsConnect\CommunicationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class StockAlert extends Command
{
    protected $description = 'Send low stock notifications';

    protected $signature = 'stock:alert';

    public function handle()
    {
        Account::all()->each(function ($account) {
            $this->info('Sending low stock alerts');
            $warehouses = $account->warehouses()->withCount([
                'stock'          => fn ($q) => $q->whereNull('variation_id'),
                'stock as alert' => fn ($q) => $q->whereNull('variation_id')->whereColumn('alert_quantity', '>=', 'quantity'),
            ])->active()->get();

            // WIMS Notification > Low Stock Alert now decides who is told, how, when, and about which materials. While it is switched on it
            // replaces the unconditional Super Admin email, the admin digest and the WIMS Connect message below; each project's own
            // address is still emailed unless that is turned off there.
            $center = \App\Services\LowStock\LowStockService::available() ? \App\Services\LowStock\LowStockConfig::get($account->id) : null;
            $centerOn = $center && $center['enabled'];

            if ($warehouses->isNotEmpty()) {
                foreach ($warehouses as $warehouse) {
                    if ($warehouse->email && $warehouse->alert && (! $centerOn || $center['warehouse_email'])) {
                        $this->info('Sending email to warehouse ' . $warehouse->code);
                        Mail::to($warehouse->email)->queue(new LowStockAlert($warehouse, true));
                    }
                }
                $users = $centerOn ? collect() : $account->users()->role('Super Admin')->get();
                if ($users->isNotEmpty()) {
                    foreach ($users as $user) {
                        if (! ($to = $user->contactEmail())) {
                            continue; // their email is switched off in Recipients
                        }
                        $this->info('Sending email to user ' . $user->username);
                        Mail::to($to)->queue(new LowStockAlert($warehouses, false, $user));
                    }
                }

                // Stock Alerts workflow page: the daily digest for people who follow low stock (only when something is low today)
                $lowStockCount = $warehouses->filter(fn ($w) => $w->alert > 0)->count();
                if ($lowStockCount > 0 && ! $centerOn) {
                    \App\Services\Workflow\WorkflowMessenger::dispatch('stock_alerts', 'low_stock_digest', $account, null, [
                        'account_id' => $account->id, 'title' => __('Low Stock Alert'),
                        'message' => __(':count warehouse(s) have items at or below their alert threshold today.', ['count' => $lowStockCount]),
                        'url' => route('warehouses.index'), 'dedupe' => now()->toDateString(),
                    ]);
                }

                if (! $centerOn) {
                    $this->notifyWimsConnect($account, $warehouses);
                }
            }
        });
    }

    /**
     * Additive WIMS Connect hook, one message per affected warehouse rather
     * than a single combined digest — mirrors this command's own per-warehouse
     * email above, just to Super Admin/Procurement Manager instead of the
     * warehouse's own contact email. This is the one "existing hook" the
     * critical-low-stock event has (see CommunicationEventRegistry): it rides
     * this daily command, it doesn't fire on every stock change.
     */
    protected function notifyWimsConnect($account, $warehouses): void
    {
        foreach ($warehouses as $warehouse) {
            if (! $warehouse->alert) {
                continue;
            }

            // Stock Alerts workflow page (Who Gets Notified / Messages): one notice per affected warehouse
            \App\Services\Workflow\WorkflowMessenger::dispatch('stock_alerts', 'critical_low_stock', $warehouse, null, [
                'account_id' => $account->id, 'title' => 'Critical / Low Stock', 'message' => "Warehouse: {$warehouse->name}\n{$warehouse->alert} item(s) at or below their alert threshold.",
                'values' => ['warehouse' => $warehouse->name, 'alerts' => (string) $warehouse->alert], 'dedupe' => now()->toDateString() . '|' . $warehouse->id,
            ]);
        }
    }
}

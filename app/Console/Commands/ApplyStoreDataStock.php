<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Checkin;
use App\Models\StockTrail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ApplyStoreDataStock extends Command
{
    protected $signature = 'import:applystock
        {--details=Imported from StoreData.xlsx : Marker on the check-ins to apply}
        {--as=1 : User id to run as}
        {--attempts=20 : Retry attempts per check-in on connection drop}
        {--dry-run : Roll back each check-in after applying (no writes)}';

    protected $description = 'Apply stock for the StoreData import check-ins via the real CheckinEvent engine (idempotent)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $user = User::find((int) $this->option('as'));
        if (! $user) {
            $this->error('Run-as user not found.');
            return self::FAILURE;
        }
        Auth::login($user);

        $checkins = Checkin::where('details', $this->option('details'))
            ->withCount('items')->get()
            ->sortBy('items_count')->values(); // smallest first

        if ($checkins->isEmpty()) {
            $this->error('No import check-ins found with that marker.');
            return self::FAILURE;
        }

        $this->info(($dry ? '[DRY RUN] ' : '[LIVE] ') . "Applying stock for {$checkins->count()} check-ins as {$user->name}");

        $applied = 0; $skipped = 0; $failed = 0;

        foreach ($checkins as $c) {
            $c->load(['items.item', 'items.unit', 'items.variations']);
            $itemIds = $c->items->pluck('id')->all();

            // Idempotency: already has stock trails referencing its lines?
            $already = StockTrail::where('subject_type', 'App\\Models\\CheckinItem')
                ->whereIn('subject_id', $itemIds)->exists();
            if ($already && ! $dry) {
                $this->line("  = {$c->reference} ({$c->items_count} lines), already applied, skip");
                $skipped++;
                continue;
            }

            $ok = false; $lastErr = null;
            for ($a = 1; $a <= (int) $this->option('attempts'); $a++) {
                try {
                    DB::purge();
                    DB::reconnect();
                    DB::transaction(function () use ($c, $dry) {
                        event(new \App\Events\CheckinEvent($c, 'created'));
                        if ($dry) {
                            throw new ApplyDryRunRollback();
                        }
                    });
                    $ok = true;
                    break;
                } catch (ApplyDryRunRollback $e) {
                    $ok = true; // applied then rolled back successfully
                    break;
                } catch (\Throwable $e) {
                    $lastErr = $e->getMessage();
                    usleep(500000); // 0.5s before retry
                }
            }

            if ($ok) {
                $this->line(($dry ? '  ~ ' : '  + ') . "{$c->reference} ({$c->items_count} lines) - " . ($dry ? 'applied+rolledback' : 'APPLIED'));
                $applied++;
            } else {
                $this->error("  ! {$c->reference} ({$c->items_count} lines) - FAILED after retries: {$lastErr}");
                $failed++;
            }
        }

        $this->newLine();
        $this->table(['metric', 'count'], [
            ['applied', $applied], ['skipped', $skipped], ['failed', $failed],
        ]);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

class ApplyDryRunRollback extends \Exception {}

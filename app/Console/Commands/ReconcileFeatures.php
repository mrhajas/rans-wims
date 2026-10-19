<?php

namespace App\Console\Commands;

use App\Services\FeatureReconciler;
use Illuminate\Console\Command;

class ReconcileFeatures extends Command
{
    protected $signature = 'features:reconcile {--apply : Insert the registered functions that have no Feature Control row (never changes or removes anything else)} {--json : Print the raw report}';

    protected $description = 'List WIMS functions that are not (or not correctly) registered in Global Feature Control, the menu / Launcher or the permission registry';

    public function handle(FeatureReconciler $reconciler): int
    {
        $applied = $this->option('apply') ? $reconciler->apply() : [];
        $r = $reconciler->report();

        if ($this->option('json')) {
            $this->line(json_encode($r + ['applied' => $applied], JSON_PRETTY_PRINT));

            return $r['problems'] ? self::FAILURE : self::SUCCESS;
        }

        $this->info("Registered in code: {$r['registered']}   Rows in Feature Control: {$r['in_db']}");
        if ($applied) {
            $this->info('Added: ' . implode(', ', $applied));
        }
        $show = function (string $title, array $items, callable $fmt) {
            if (! $items) {
                $this->line("<fg=green>ok</>  {$title}");

                return;
            }
            $this->warn("{$title} (" . count($items) . ')');
            foreach ($items as $i) {
                $this->line('   - ' . $fmt($i));
            }
        };
        $show('Registered in code but missing from Feature Control (run with --apply)', $r['missing_in_db'], fn ($k) => $k);
        $show('In Feature Control but not in the code registry', $r['orphan_in_db'], fn ($k) => $k);
        $show('Depends on a function that does not exist', $r['bad_dependency'], fn ($i) => "{$i['key']} -> {$i['depends_on']}");
        $show('Route prefix claimed by more than one function', $r['duplicate_prefix'], fn ($i) => "{$i['prefix']}: " . implode(', ', $i['features']));
        $show('Menu names a feature that is not registered', $r['menu_unknown'], fn ($k) => $k);
        $show('Top-level menu group with no feature', $r['menu_ungated'], fn ($k) => $k);
        $show('Routes behind the gate that no function covers', $r['routes_ungoverned'], fn ($i) => "{$i['prefix']}.* ({$i['routes']} routes)");
        $show('Registered permissions with no permission row (run permissions:sync)', $r['missing_permissions'], fn ($k) => $k);

        return $r['problems'] ? self::FAILURE : self::SUCCESS;
    }
}

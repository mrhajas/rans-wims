<?php

namespace App\Support\Optimizer;

use App\Support\Performance\Tables;
use Illuminate\Support\Facades\DB;

/**
 * A fixed set of the kind of reads the pages make (item list, stock value, history, alerts...), timed in the database. The same set is timed before and after an
 * optimisation, so "speed" in the audit is a measurement, not a promise. Each read runs once to warm up, then five times; the middle time counts.
 */
class SpeedProbe
{
    /** @return array<string, array{table:string, sql:string}> */
    private static function probes(): array
    {
        return [
            'Item list with stock' => ['items', 'select items.id, items.name, (select sum(stocks.quantity) from stocks where stocks.item_id = items.id) as q from items where items.deleted_at is null order by items.name limit 300'],
            'Stock value per warehouse' => ['stocks', 'select stocks.warehouse_id, sum(stocks.quantity * coalesce(items.price, 0)) as amount from stocks left join items on items.id = stocks.item_id where stocks.deleted_at is null group by stocks.warehouse_id'],
            'Latest activity' => ['activity_log', 'select id, description, created_at from activity_log order by id desc limit 300'],
            'Material requests' => ['material_requests', 'select id, status, created_at from material_requests order by id desc limit 300'],
            'Alerts' => ['notifications', "select id, type, read_at from notifications order by created_at desc limit 200"],
            'Transfers' => ['transfers', 'select id, status, created_at from transfers order by id desc limit 300'],
            'Delivery logs' => ['wims_connect_logs', 'select id, event_key, channel, status, created_at from wims_connect_logs order by id desc limit 25'],
            'Delivery logs (total)' => ['wims_connect_logs', 'select count(*) as n from wims_connect_logs'],
            'Weather latest' => ['weather_snapshots', "select id, kind, fetched_at from weather_snapshots where kind = 'current' order by fetched_at desc limit 50"],
            'Download and print log' => ['download_print_logs', 'select id, user_name, module, performed_at from download_print_logs order by performed_at desc limit 25'],
            'Users and roles' => ['users', 'select users.id, users.name, count(model_has_roles.role_id) as r from users left join model_has_roles on model_has_roles.model_id = users.id group by users.id, users.name'],
        ];
    }

    /** @return array{total_ms: float, items: array<int, array{name:string, ms: float}>} */
    public static function run(): array
    {
        $items = [];
        $total = 0.0;
        foreach (self::probes() as $name => [$table, $sql]) {
            if (! Tables::has($table)) {
                continue;
            }
            try {
                DB::select($sql); // warm up
                $times = [];
                for ($i = 0; $i < 5; $i++) {
                    $t = microtime(true);
                    DB::select($sql);
                    $times[] = (microtime(true) - $t) * 1000;
                }
                sort($times);
                $ms = round($times[2], 2);
            } catch (\Throwable $e) {
                continue; // a table without the columns of the probe: left out of both measurements
            }
            $items[] = ['name' => $name, 'ms' => $ms];
            $total += $ms;
        }

        return ['total_ms' => round($total, 2), 'items' => $items];
    }
}

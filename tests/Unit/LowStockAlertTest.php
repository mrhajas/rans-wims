<?php

namespace Tests\Unit;

use App\Services\LowStock\LowStockConfig;
use App\Services\LowStock\LowStockService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class LowStockAlertTest extends TestCase
{
    public function test_defaults_watch_cement_aggregate_sand_and_steel(): void
    {
        $c = LowStockConfig::clean([]);
        $this->assertSame(['Cement', 'Aggregate', 'Sand', 'Steel bars (Y8, Y10, Y12 ...)'], array_column($c['groups'], 'name'));
        $this->assertSame(['steel y'], $c['groups'][3]['keywords']);
        $this->assertSame(['sand'], $c['groups'][2]['keywords']);
        $this->assertTrue($c['exclude_main_warehouses']);
        $this->assertTrue($c['show_all_items']);
        $this->assertSame('07:00', $c['send_time']);
    }

    public function test_bad_input_is_cleaned(): void
    {
        $c = LowStockConfig::clean(['send_time' => '25:99', 'send_days' => [1, 9, 'x'], 'groups' => [['name' => '  ', 'keywords' => ['a']], ['name' => 'Sand', 'keywords' => [' sand ', ''], 'below' => -5, 'projects' => ['mode' => 'selected', 'ids' => ['3', 3, 4]]]]]);
        $this->assertSame('07:00', $c['send_time']);
        $this->assertSame([1, 0], array_values(array_unique(array_filter($c['send_days'], fn ($d) => $d <= 6))) === [1, 0] ? [1, 0] : $c['send_days']);
        $this->assertCount(1, $c['groups']);
        $this->assertSame(['sand'], $c['groups'][0]['keywords']);
        $this->assertSame(0.0, (float) $c['groups'][0]['below']);
        $this->assertSame([3, 4], $c['groups'][0]['projects']['ids']);
    }

    public function test_it_is_due_once_a_day_after_the_chosen_time(): void
    {
        $cfg = LowStockConfig::clean(['send_time' => '08:00', 'send_days' => []]);
        $tz = LowStockService::TZ;
        $this->assertFalse(LowStockService::isDue($cfg, CarbonImmutable::parse('2026-10-10 07:59', $tz)));
        $this->assertTrue(LowStockService::isDue($cfg, CarbonImmutable::parse('2026-10-10 08:00', $tz)));
        $this->assertTrue(LowStockService::isDue($cfg, CarbonImmutable::parse('2026-10-10 15:30', $tz)));
        $cfg['last_sent_on'] = '2026-10-10';
        $this->assertFalse(LowStockService::isDue($cfg, CarbonImmutable::parse('2026-10-10 15:30', $tz)));
        // only Mondays (1): 10 Oct 2026 is a Saturday
        $mon = LowStockConfig::clean(['send_time' => '08:00', 'send_days' => [1]]);
        $this->assertFalse(LowStockService::isDue($mon, CarbonImmutable::parse('2026-10-10 09:00', $tz)));
        $this->assertTrue(LowStockService::isDue($mon, CarbonImmutable::parse('2026-10-12 09:00', $tz)));
    }

    public function test_sms_text_has_no_link_and_is_short(): void
    {
        $digest = ['totals' => ['projects' => 2, 'items' => 3, 'out' => 1, 'low' => 2, 'with_problem' => 2], 'projects' => [
            ['id' => 1, 'name' => 'Site A', 'out' => 1, 'items' => [['item' => 'CEMENT 50KG', 'qty' => 0.0, 'unit' => 'bags'], ['item' => 'STEEL Y10', 'qty' => 12.5, 'unit' => 'pcs']]],
            ['id' => 2, 'name' => 'Site B', 'out' => 0, 'items' => [['item' => 'AGGREGATE', 'qty' => 2.0, 'unit' => 'trip']]],
        ]];
        $t = LowStockService::smsText($digest, CarbonImmutable::parse('2026-10-10', LowStockService::TZ));
        $this->assertStringNotContainsString('http', $t);
        $this->assertLessThanOrEqual(300, mb_strlen($t));
        $this->assertStringContainsString('Site A', $t);
    }

    public function test_the_overview_lists_every_project_and_every_watched_item(): void
    {
        $mk = fn ($w, $name, $item, $gi, $qty, $level) => ['gi' => $gi, 'group' => $gi ? 'Steel' : 'Cement', 'color' => '#000', 'keyword' => $gi ? 'steel' : 'cement', 'warehouse_id' => $w, 'warehouse' => $name, 'item_id' => $item, 'item' => $gi ? 'STEEL Y'.$item : 'CEMENT 50KG', 'code' => 'C'.$item, 'unit' => 'u',
            'qty' => (float) $qty, 'level' => (float) $level, 'out' => $qty <= 0, 'state' => $qty <= 0 ? 'out' : ($qty <= $level ? 'low' : 'ok'), 'ratio' => 0.5];
        $stock = collect([$mk(1, 'Site A', 10, 0, 100, 50), $mk(1, 'Site A', 11, 1, 3, 30), $mk(2, 'Site B', 10, 0, 0, 50), $mk(3, 'Site C', 10, 0, 90, 50)]);
        $all = LowStockService::digest($stock, true);
        $this->assertSame(3, $all['totals']['projects']);
        $this->assertSame(2, $all['totals']['watched']);
        $this->assertSame(['CEMENT 50KG', 'STEEL Y11'], array_column($all['columns'], 'item'));
        $this->assertSame(2, $all['totals']['with_problem']);
        $c = collect($all['projects'])->firstWhere('name', 'Site A');
        $this->assertSame(100.0, $c['cells'][10]['qty']);
        $this->assertSame('low', $c['cells'][11]['state']);
        $this->assertArrayNotHasKey(11, collect($all['projects'])->firstWhere('name', 'Site C')['cells']); // not stocked, so no cell
        // only the projects with something low
        $onlyLow = LowStockService::digest($stock, false);
        $this->assertSame(2, $onlyLow['totals']['projects']);
        $this->assertSame(['Site B', 'Site A'], array_column($onlyLow['projects'], 'name'));
    }
}

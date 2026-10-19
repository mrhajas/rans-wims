<?php

namespace Tests\Unit;

use App\Models\Transfer;
use App\Services\Transport\TransferRoute;
use App\Support\Workflow\WorkflowModules;
use App\Support\PermissionRegistry;
use Tests\TestCase;

class TransportRouteTest extends TestCase
{
    public function test_transport_events_default_to_super_admin_and_use_the_transport_module(): void
    {
        $events = collect(WorkflowModules::get('transport')['events']);
        $this->assertCount(12, $events);
        $this->assertTrue($events->every(fn ($e) => $e['role_names'] === ['Super Admin']));
        $this->assertTrue($events->has('trip_from_transfer'));
    }

    public function test_new_abilities_are_listed_in_the_roles_page(): void
    {
        $all = collect(PermissionRegistry::modules())->flatMap(fn ($m) => array_keys($m['abilities']));
        $this->assertTrue($all->contains('generate-transport-route-transfers'));
    }

    public function test_the_transfer_date_is_read_from_plain_text(): void
    {
        $t = new Transfer(['date' => '2026-10-11']);
        $this->assertSame('2026-10-11', TransferRoute::dateOf($t));
        $this->assertSame(now()->format('Y-m-d'), TransferRoute::dateOf(new Transfer(['date' => 'not a date'])));
    }
}

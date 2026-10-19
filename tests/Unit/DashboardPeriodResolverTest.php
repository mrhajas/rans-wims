<?php

namespace Tests\Unit;

use App\Services\DashboardPeriodResolver;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class DashboardPeriodResolverTest extends TestCase
{
    public function test_it_uses_the_current_period_when_no_period_is_selected(): void
    {
        $resolver = new DashboardPeriodResolver();

        $request = new Request();
        $result = $resolver->resolve($request);

        $this->assertSame([now()->month, now()->year], $result);
    }

    public function test_it_uses_the_requested_period_when_provided(): void
    {
        $resolver = new DashboardPeriodResolver();

        $request = new Request(['month' => 6, 'year' => 2026]);
        $result = $resolver->resolve($request);

        $this->assertSame([6, 2026], $result);
    }
}

<?php

namespace Tests\Feature;

use App\Http\Middleware\FeatureGate;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** A person whose Super Admin role the test decides. */
class FcTestUser extends User
{
    protected $table = 'users';

    public bool $superAdmin = false;

    public function hasRole($roles, ?string $guard = null): bool
    {
        return $this->superAdmin;
    }
}

// Global Feature Control: a switched-off function is off for EVERYONE, Super Admins included; Feature Control itself and the notice page stay reachable;
// a Main switch (a function and everything under it) can be found; the function that holds another one off is named.
class FeatureControlSwitchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('system_features');
        Schema::create('system_features', function (Blueprint $t) {
            $t->id();
            $t->string('key');
            $t->string('name')->nullable();
            $t->boolean('is_enabled')->default(true);
            $t->string('depends_on')->nullable();
            $t->json('route_prefixes')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        $rows = [
            ['weather_risk', 'Weather', 1, null, ['weather.']],
            ['weather_locations', 'Weather locations', 1, 'weather_risk', ['weather.locations.']],
            ['weather_reports', 'Weather reports', 1, 'weather_risk', ['weather.reports.']],
            ['weather_deep', 'Weather deep', 1, 'weather_reports', ['weather.deep.']],
            ['items', 'Items', 1, null, ['items.']],
        ];
        foreach ($rows as [$key, $name, $on, $dep, $prefixes]) {
            DB::table('system_features')->insert(['key' => $key, 'name' => $name, 'is_enabled' => $on, 'depends_on' => $dep, 'route_prefixes' => json_encode($prefixes)]);
        }
        FeatureManager::flushCache();
    }

    protected function tearDown(): void
    {
        FeatureManager::flushCache();
        parent::tearDown();
    }

    private function off(string ...$keys): void
    {
        DB::table('system_features')->whereIn('key', $keys)->update(['is_enabled' => 0]);
        FeatureManager::flushCache();
    }

    private function user(bool $super): FcTestUser
    {
        $u = new FcTestUser();
        $u->superAdmin = $super;

        return $u;
    }

    private function through(string $routeName, FcTestUser $user, string $method = 'GET')
    {
        $request = Request::create('/' . str_replace('.', '/', $routeName), $method);
        $request->setUserResolver(fn () => $user);
        $route = new Route($method, '/x', fn () => 'ok');
        $route->name($routeName);
        $request->setRouteResolver(fn () => $route);

        return (new FeatureGate())->handle($request, fn () => response('passed'));
    }

    public function test_a_super_admin_is_blocked_from_a_switched_off_function_like_everyone_else(): void
    {
        $this->off('items');

        $this->assertFalse(FeatureManager::canAccess('items', $this->user(true)));
        $this->assertFalse(FeatureManager::canAccess('items', $this->user(false)));

        foreach ([true, false] as $super) {
            $response = $this->through('items.index', $this->user($super));
            $this->assertSame(302, $response->getStatusCode(), 'the page is not served');
            $this->assertStringContainsString('feature-unavailable', $response->headers->get('Location'));
            $this->assertStringContainsString('feature=items', $response->headers->get('Location'));
        }
    }

    public function test_a_function_that_is_on_passes_for_a_super_admin(): void
    {
        $this->assertSame('passed', $this->through('items.index', $this->user(true))->getContent());
    }

    public function test_the_way_back_is_kept_for_a_page_that_was_opened_directly_but_not_for_a_form_post(): void
    {
        $this->off('items');

        $get = $this->through('items.index', $this->user(true));
        $this->assertStringContainsString('from=', $get->headers->get('Location'));

        $post = $this->through('items.store', $this->user(true), 'POST');
        $this->assertStringNotContainsString('from=', $post->headers->get('Location'));
    }

    public function test_feature_control_and_the_notice_page_stay_reachable_whatever_is_off(): void
    {
        $this->off('items', 'weather_risk');

        $this->assertTrue(FeatureManager::alwaysReachable('system-features.index'));
        $this->assertTrue(FeatureManager::alwaysReachable('system-features.family'));
        $this->assertTrue(FeatureManager::alwaysReachable('feature-unavailable'));
        $this->assertFalse(FeatureManager::alwaysReachable('items.index'));
        $this->assertFalse(FeatureManager::alwaysReachable(null));

        $this->assertSame('passed', $this->through('system-features.index', $this->user(true))->getContent());
        $this->assertSame('passed', $this->through('feature-unavailable', $this->user(true))->getContent());
    }

    public function test_the_main_switch_covers_a_function_and_everything_under_it(): void
    {
        $this->assertEqualsCanonicalizing(['weather_locations', 'weather_reports', 'weather_deep'], FeatureManager::descendantsOf('weather_risk'));
        $this->assertSame(['weather_deep'], FeatureManager::descendantsOf('weather_reports'));
        $this->assertSame([], FeatureManager::descendantsOf('items'));
    }

    public function test_switching_the_parent_off_holds_every_sub_function_off_and_the_parent_is_named(): void
    {
        $this->off('weather_risk');

        foreach (['weather_risk', 'weather_locations', 'weather_reports', 'weather_deep'] as $key) {
            $this->assertFalse(FeatureManager::canAccess($key, $this->user(true)), $key);
        }
        $this->assertTrue(FeatureManager::canAccess('items', $this->user(true)));

        $this->assertSame('weather_risk', FeatureManager::blockingAncestor('weather_deep')->key);
        $this->assertSame('weather_risk', FeatureManager::blockingAncestor('weather_risk')->key);
        $this->assertNull(FeatureManager::blockingAncestor('items'));
    }

    public function test_one_sub_function_can_be_off_on_its_own(): void
    {
        $this->off('weather_locations');

        $this->assertFalse(FeatureManager::canAccess('weather_locations', $this->user(true)));
        $this->assertTrue(FeatureManager::canAccess('weather_risk', $this->user(true)));
        $this->assertTrue(FeatureManager::canAccess('weather_reports', $this->user(true)));
        $this->assertSame('weather_locations', FeatureManager::blockingAncestor('weather_locations')->key);
    }

    public function test_the_nearest_switched_off_function_is_the_one_named(): void
    {
        $this->off('weather_reports');

        $this->assertSame('weather_reports', FeatureManager::blockingAncestor('weather_deep')->key);
        $this->assertTrue(FeatureManager::canAccess('weather_risk', $this->user(true)));
    }
}

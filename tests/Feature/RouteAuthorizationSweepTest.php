<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Scenario I (permissions): every application route without URL parameters is requested
 * by a guest and by a signed-in user who holds no permission at all.
 */
class RouteAuthorizationSweepTest extends TestCase
{
    use RefreshDatabase;

    /** Routes every signed-in user may use regardless of permissions. */
    private const OPEN_TO_ANY_USER = [
    ];

    /** @return array<int, RoutingRoute> */
    private function parameterlessRoutes(string $method): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => in_array($method, $route->methods(), true)
                && ! str_contains($route->uri(), '{')
                && ! str_starts_with($route->uri(), '_')
                && ! str_starts_with($route->uri(), 'storage')
                && $route->uri() !== 'up'
                && ! in_array('guest', $route->gatherMiddleware(), true))
            ->values()->all();
    }

    public function test_discovery(): void
    {
        $user = User::factory()->create();
        $report = [];
        foreach (['GET', 'POST'] as $method) {
            foreach ($this->parameterlessRoutes($method) as $route) {
                $uri = '/'.ltrim($route->uri(), '/');
                $this->app['auth']->forgetGuards();
                $guest = $this->call($method, $uri);
                $asUser = $this->actingAs($user)->call($method, $uri);
                $report[] = sprintf('%s %s [%s] guest=%d user=%d mw=%s', $method, $uri, $route->getName(), $guest->getStatusCode(), $asUser->getStatusCode(),
                    implode(',', array_intersect($route->gatherMiddleware(), ['auth', 'web', 'verified'])));
            }
        }
        $interesting = array_filter($report, fn ($line) => ! preg_match('/guest=302 user=403/', $line));
        $this->fail("ROUTES ".count($report)."\n".implode("\n", $interesting));
    }
}

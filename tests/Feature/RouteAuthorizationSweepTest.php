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
 *
 * A new page or form that forgets its permission check fails this test. Routes with URL
 * parameters are covered by the individual workflow tests, not here.
 */
class RouteAuthorizationSweepTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Self-service routes every signed-in user may use: own profile and password, own
     * notifications, own approval requests, language, sign-out and the dashboard shell.
     */
    private const OPEN_TO_ANY_USER = [
        'dashboard', 'approvals.index', 'approvals.store', 'language.update', 'logout',
        'profile.*', 'password.*', 'verification.*', 'notifications.*',
    ];

    /** @return array<int, RoutingRoute> */
    private function parameterlessRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => ! str_contains($route->uri(), '{')
                && ! str_starts_with($route->uri(), '_')
                && ! str_starts_with($route->uri(), 'storage')
                && ! in_array($route->uri(), ['up', '/'], true)
                && ! in_array('guest', $route->gatherMiddleware(), true))
            ->values()->all();
    }

    private function isSelfService(RoutingRoute $route): bool
    {
        $name = (string) $route->getName();
        if ($name === '') {
            return $route->uri() === 'confirm-password';
        }
        foreach (self::OPEN_TO_ANY_USER as $pattern) {
            if (fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    public function test_guests_are_sent_to_login_and_users_without_permissions_are_refused_everywhere(): void
    {
        $user = User::factory()->create();
        $checked = 0;
        $guestLeaks = [];
        $permissionLeaks = [];
        foreach ($this->parameterlessRoutes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $uri = '/'.ltrim($route->uri(), '/');
                $label = $method.' '.$uri;

                $this->app['auth']->forgetGuards();
                $guest = $this->call($method, $uri);
                if (! ($guest->isRedirect() && str_contains((string) $guest->headers->get('Location'), '/login'))) {
                    $guestLeaks[] = $label.' => '.$guest->getStatusCode();
                }

                if (! $this->isSelfService($route)) {
                    $response = $this->actingAs($user)->call($method, $uri);
                    if ($response->getStatusCode() !== 403) {
                        $permissionLeaks[] = $label.' => '.$response->getStatusCode();
                    }
                }
                $checked++;
            }
        }

        $this->assertSame([], $guestLeaks, 'Routes reachable without signing in.');
        $this->assertSame([], $permissionLeaks, 'Routes that do not refuse a user who has no permissions.');
        // Guards against the sweep silently checking nothing if route registration changes.
        $this->assertGreaterThan(150, $checked);
    }

    public function test_home_page_redirects_and_public_registration_stays_closed(): void
    {
        $this->get('/')->assertRedirect();
        $this->assertFalse(Route::has('register'));
        $this->get('/register')->assertNotFound();
    }
}

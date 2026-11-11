<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Legacy accounting recovery postings have no acceptance tests yet, so a release keeps
 * them switched off even for administrators unless explicitly enabled.
 */
class LegacyRecoverySwitchTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        foreach (['administration.view', 'administration.update', 'finance.update', 'inventory.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create();
        $user->givePermissionTo(['administration.view', 'administration.update', 'finance.update', 'inventory.update']);

        return $user;
    }

    public function test_unverified_legacy_recovery_postings_are_refused_by_default(): void
    {
        $this->assertFalse(config('erp_release.legacy_recovery_postings'));
        $admin = $this->administrator();

        $this->actingAs($admin)->post(route('inventory.legacy-fifo-reviews.consumed'), [])
            ->assertForbidden()->assertSee('Legacy accounting recovery is switched off');
        $this->actingAs($admin)->post('/inventory/legacy-fifo-reviews/production-recovery', [])->assertForbidden();
        $this->actingAs($admin)->post(route('finance.historical-corrections.execute', 1), [])->assertForbidden();
        $this->assertDatabaseCount('journal_entries', 0);

        // Read-only screens and the tested correction workflow stay available.
        $this->actingAs($admin)->get(route('inventory.legacy-fifo-reviews.index'))->assertOk();
        $this->actingAs($admin)->get(route('finance.historical-corrections.index'))->assertOk();
        $this->actingAs($admin)->post(route('finance.historical-corrections.store'), [])->assertSessionHasErrors('case_type');
    }

    public function test_switch_can_be_enabled_deliberately(): void
    {
        config(['erp_release.legacy_recovery_postings' => true]);
        $admin = $this->administrator();

        // With the switch on the request reaches the action's own validation instead of the block.
        $response = $this->actingAs($admin)->post(route('inventory.legacy-fifo-reviews.consumed'), []);
        $this->assertNotSame(403, $response->getStatusCode());
    }

    public function test_business_timezone_is_configurable_and_defaults_to_utc(): void
    {
        $config = file_get_contents(config_path('app.php'));
        $this->assertStringContainsString("env('APP_TIMEZONE')", $config);
        $this->assertSame('UTC', config('app.timezone'));
    }
}

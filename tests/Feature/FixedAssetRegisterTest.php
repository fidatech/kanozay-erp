<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\EmployeeProfile;
use App\Models\FixedAsset;
use App\Models\FixedAssetAssignment;
use App\Models\FixedAssetCategory;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FixedAssetRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_asset_source_and_assignment_history_are_preserved_without_financial_posting(): void
    {
        $user = User::factory()->create();
        foreach (['maintenance.view', 'maintenance.create', 'maintenance.update'] as $permission) Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo(['maintenance.view', 'maintenance.create', 'maintenance.update']);
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Synthetic Afghani', 'is_active' => true]);
        $employee = EmployeeProfile::create([
            'employee_number' => 'SYN-001', 'full_name' => 'Synthetic Custodian',
            'monthly_salary' => '100.0000', 'salary_currency_id' => $currency->id,
            'is_active' => true, 'created_by' => $user->id,
        ]);

        $this->actingAs($user)->post(route('assets.categories.store'), ['code' => 'equipment', 'name' => 'Synthetic equipment'])->assertRedirect();
        $category = FixedAssetCategory::sole();
        $this->assertSame('EQUIPMENT', $category->code);
        $this->actingAs($user)->post(route('assets.store'), [
            'asset_tag' => ' syn-001 ', 'name' => 'Synthetic machine',
            'fixed_asset_category_id' => $category->id,
            'acquired_on' => now()->subDay()->toDateString(),
            'acquisition_cost' => '1,250.5000', 'currency_id' => $currency->id,
            'source_reference' => 'SYNTHETIC-UAT-001',
        ])->assertRedirect();
        $asset = FixedAsset::sole();
        $this->assertSame('SYN-001', $asset->asset_tag);
        $this->assertSame('1250.5000', $asset->acquisition_cost);
        $this->assertSame(0, JournalEntry::count());

        $this->actingAs($user)->post(route('assets.assignments.store', $asset), [
            'employee_profile_id' => $employee->id, 'assigned_on' => now()->toDateString(),
        ])->assertRedirect();
        $this->assertSame($employee->id, $asset->fresh()->custodian_id);
        $assignment = FixedAssetAssignment::sole();
        $this->actingAs($user)->post(route('assets.assignments.store', $asset), [
            'employee_profile_id' => $employee->id, 'assigned_on' => now()->toDateString(),
        ])->assertSessionHasErrors('assignment');
        $this->assertSame(1, FixedAssetAssignment::count());

        $this->actingAs($user)->post(route('assets.assignments.return', [$asset, $assignment]), [
            'returned_on' => now()->toDateString(),
        ])->assertRedirect();
        $this->assertNull($asset->fresh()->custodian_id);
        $this->assertNotNull($assignment->fresh()->returned_on);
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_unprivileged_user_cannot_register_an_asset(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('assets.store'), [])->assertForbidden();
    }
}

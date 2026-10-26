<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ModulePageStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_scaffolded_page_is_accessible_to_authorized_users(): void
    {
        $user=User::factory()->create();
        Permission::findOrCreate('administration.view','web');
        $user->givePermissionTo('administration.view');
        foreach(config('erp_pages') as $module=>$data){
            $this->actingAs($user)->get(route('workspace.page',[$module,'overview']))
                ->assertOk()->assertSee($data['label']);
            foreach($data['pages'] as $page=>$label){
                $this->actingAs($user)->get(route('workspace.page',[$module,$page]))
                    ->assertOk()->assertSee($page === 'overview' ? $data['label'] : $label)
                    ->assertSee($page === 'overview' ? 'Daily operations' : 'Workflow pending');
            }
        }
    }

    public function test_unknown_pages_and_unauthorized_access_are_rejected(): void
    {
        $user=User::factory()->create();
        $this->actingAs($user)->get(route('workspace.page',['purchases','orders']))->assertForbidden();
        Permission::findOrCreate('administration.view','web');
        $user->givePermissionTo('administration.view');
        $this->actingAs($user)->get(route('workspace.page',['unknown','orders']))->assertNotFound();
        $this->actingAs($user)->get(route('workspace.page',['purchases','unknown']))->assertNotFound();
    }
}

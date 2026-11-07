<?php

namespace Tests\Feature;

use App\Models\TradeParty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CrmWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        foreach(['crm.view','crm.create','crm.update','crm.delete'] as $name) {
            Permission::findOrCreate($name,'web');
        }
        $user=User::factory()->create();
        $user->givePermissionTo(['crm.view','crm.create','crm.update','crm.delete']);

        return $user;
    }

    public function test_all_eight_crm_pages_have_real_registers_not_placeholders():void
    {
        $user=$this->manager();
        foreach(array_keys(config('crm_workflows')) as $page) {
            $this->actingAs($user)->get(route('workspace.page',['crm',$page]))
                ->assertOk()->assertSee('register')->assertDontSee('Workflow pending');
        }
    }

    public function test_crm_lead_create_update_search_archive_with_audit_trail():void
    {
        $user=$this->manager();
        $this->actingAs($user)->post(route('crm.records.store','leads'),[
            'name'=>'Synthetic Buyer','company'=>'Sample Manufacturing Ltd',
            'status'=>'new','phone'=>'0700000000',
        ])->assertRedirect(route('workspace.page',['crm','leads']));
        $lead=\Illuminate\Support\Facades\DB::table('crm_leads')->first();
        $this->assertSame('LEAD-0000001',$lead->number);
        $this->actingAs($user)->get(route('workspace.page',['crm','leads','search'=>'Synthetic Buyer']))
            ->assertOk()->assertSee('Sample Manufacturing');
        $this->actingAs($user)->patch(route('crm.records.update',['leads',$lead->id]),[
            'name'=>'Synthetic Buyer','company'=>'Sample Manufacturing Ltd','status'=>'qualified',
        ])->assertRedirect();
        $this->assertDatabaseHas('crm_leads',['id'=>$lead->id,'status'=>'qualified']);
        $this->actingAs($user)->delete(route('crm.records.delete',['leads',$lead->id]))->assertRedirect();
        $this->assertDatabaseMissing('crm_leads',['id'=>$lead->id,'deleted_at'=>null]);
        $this->assertDatabaseHas('activity_logs',['action'=>'crm.leads.archived']);
    }

    public function test_complaint_is_scoped_to_existing_customers():void
    {
        $user=$this->manager();
        $supplier=TradeParty::create(['type'=>'supplier','name'=>'Supplier','is_active'=>true]);
        $customer=TradeParty::create(['type'=>'customer','name'=>'Customer','is_active'=>true]);
        $data=['subject'=>'Package seal issue','priority'=>'high','status'=>'open','description'=>'Synthetic issue'];
        $this->actingAs($user)->post(route('crm.records.store','complaints'),$data+['trade_party_id'=>$supplier->id])
            ->assertSessionHasErrors('trade_party_id');
        $this->actingAs($user)->post(route('crm.records.store','complaints'),$data+['trade_party_id'=>$customer->id])
            ->assertRedirect();
        $this->assertDatabaseHas('crm_complaints',['subject'=>'Package seal issue','trade_party_id'=>$customer->id,'number'=>'COMP-0000001']);
    }

    public function test_crm_exports_escape_formula_cells_and_enforce_permissions():void
    {
        $manager=$this->manager();
        $this->actingAs($manager)->post(route('crm.records.store','segments'),['name'=>'=1+1','is_active'=>'1'])->assertRedirect();
        $response=$this->actingAs($manager)->get(route('workspace.page',['crm','segments','export'=>'csv']));
        $response->assertOk()->assertHeader('content-type','text/csv; charset=UTF-8');
        $this->assertStringContainsString("'=1+1",$response->streamedContent());
        $unauthorized=User::factory()->create();
        $this->actingAs($unauthorized)->get(route('workspace.page',['crm','segments']))->assertForbidden();
        $this->actingAs($unauthorized)->post(route('crm.records.store','leads'),['name'=>'Bad'])->assertForbidden();
    }
}

<?php
namespace Tests\Feature;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
class PaginationUiTest extends TestCase {
 use RefreshDatabase;
 public function test_chart_of_accounts_pagination_is_styled_and_preserves_filter():void {
  $user=User::factory()->create();Permission::findOrCreate('administration.view','web');$user->givePermissionTo('administration.view');
  foreach(range(1,45) as $i)LedgerAccount::create(['code'=>sprintf('A%04d',$i),'name'=>'Account '.$i,'type'=>'asset','is_group'=>false,'is_active'=>true]);
  $this->actingAs($user)->get(route('admin.accounts.index',['type'=>'asset']))->assertOk()->assertSee('k-pagination')->assertSee('45 records')->assertSee('type=asset')->assertSee('aria-current="page"',false);
  $this->actingAs($user)->get(route('admin.accounts.index',['type'=>'asset','page'=>2]))->assertOk()->assertSee('Showing')->assertSee('Account 45');
 }
}

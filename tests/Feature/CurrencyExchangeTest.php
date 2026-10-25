<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CurrencyExchangeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user=User::factory()->create();
        Permission::findOrCreate('administration.view','web');
        Permission::findOrCreate('administration.update','web');
        $user->givePermissionTo(['administration.view','administration.update']);
        return $user;
    }

    public function test_currencies_and_rates_are_permission_protected(): void
    {
        $user=User::factory()->create();
        $this->actingAs($user)->get(route('admin.currencies.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.exchange-rates.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.currencies.store'),['code'=>'AFN','name'=>'Afghani','decimal_places'=>2])->assertForbidden();
    }

    public function test_admin_can_create_currencies_and_record_rate_history(): void
    {
        $user=$this->admin();
        $this->actingAs($user)->post(route('admin.currencies.store'),['code'=>'afn','name'=>'Afghan Afghani','symbol'=>'؋','decimal_places'=>2])->assertRedirect();
        $this->actingAs($user)->post(route('admin.currencies.store'),['code'=>'USD','name'=>'US Dollar','symbol'=>'$','decimal_places'=>2])->assertRedirect();
        $afn=Currency::where('code','AFN')->firstOrFail();
        $usd=Currency::where('code','USD')->firstOrFail();
        $this->assertTrue($afn->is_base);
        $this->assertDatabaseCount('currencies',2);
        $this->actingAs($user)->get(route('admin.currencies.index'))->assertOk()->assertSee('Afghan Afghani');
        $this->actingAs($user)->get(route('admin.exchange-rates.index'))->assertOk();
        $data=['from_currency_id'=>$usd->id,'to_currency_id'=>$afn->id,'rate'=>'68.250000000000','effective_date'=>'2026-10-25','source'=>'Manual'];
        $this->actingAs($user)->post(route('admin.exchange-rates.store'),$data)->assertRedirect();
        $this->assertDatabaseCount('exchange_rates',1);
        $this->actingAs($user)->post(route('admin.exchange-rates.store'),array_merge($data,['rate'=>'69.100000000000']))->assertRedirect();
        $this->assertDatabaseCount('exchange_rates',2);
        $this->actingAs($user)->patch(route('admin.currencies.base',$usd))->assertSessionHasErrors('currency');
        $this->assertTrue($afn->fresh()->is_base);
    }

    public function test_invalid_exchange_pairs_are_rejected(): void
    {
        $user=$this->admin();
        $afn=Currency::create(['code'=>'AFN','name'=>'Afghani','is_base'=>true]);
        $this->actingAs($user)->post(route('admin.exchange-rates.store'),['from_currency_id'=>$afn->id,'to_currency_id'=>$afn->id,'rate'=>'1','effective_date'=>'2026-10-25'])->assertSessionHasErrors('to_currency_id');
        $this->actingAs($user)->post(route('admin.exchange-rates.store'),['from_currency_id'=>$afn->id,'to_currency_id'=>999,'rate'=>'-5','effective_date'=>'2026-10-25'])->assertSessionHasErrors(['to_currency_id','rate']);
        $this->assertSame(0,ExchangeRate::count());
    }
}

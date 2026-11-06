<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\TradeParty;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TradePartyStatementPeriodTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $type='customer'):array
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $user=User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('finance.view','web'));
        $afn=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);
        $usd=Currency::create(['code'=>'USD','name'=>'US Dollar','is_active'=>true]);
        $party=TradeParty::create([
            'type'=>$type,'name'=>'Statement Party','is_active'=>true,'created_by'=>$user->id
        ]);
        $party->currencies()->attach([$afn->id,$usd->id]);
        foreach([$afn,$usd] as $currency)$party->provisionLedgerAccountsForCurrency($currency->id);
        return [$user,$afn,$usd,$party];
    }

    private function journal(User $user,TradeParty $party,Currency $currency,string $date,string $number,string $amount,string $status='posted'):void
    {
        $purpose=$party->type==='customer'?'receivable':'payable';
        $id=DB::table('entity_ledger_accounts')->where([
            'entity_type'=>$party->type,'entity_id'=>$party->id,
            'currency_id'=>$currency->id,'purpose'=>$purpose
        ])->value('ledger_account_id');
        $entry=JournalEntry::create([
            'number'=>$number,'entry_date'=>$date,'description'=>'Period entry '.$number,
            'status'=>$status,'currency_id'=>$currency->id,'created_by'=>$user->id
        ]);
        $entry->lines()->create([
            'ledger_account_id'=>$id,'debit'=>$amount,'credit'=>'0'
        ]);
    }

    public function test_date_range_carries_opening_balance_without_including_earlier_rows():void
    {
        [$user,$afn,$usd,$party]=$this->fixture();
        $this->journal($user,$party,$afn,'2026-10-01','BEFORE-AFN','125.00');
        $this->journal($user,$party,$afn,'2026-11-02','IN-AFN','75.00');
        $this->journal($user,$party,$usd,'2026-11-03','IN-USD','22.00');
        $this->journal($user,$party,$afn,'2026-11-04','DRAFT-AFN','800.00','draft');
        $this->journal($user,$party,$afn,'2026-12-01','AFTER-AFN','600.00');
        $this->actingAs($user)->get(route('finance.parties.statement',[
            'party'=>$party,'from'=>'2026-11-01','to'=>'2026-11-30'
        ]))->assertOk()->assertSee('Opening: 125.00')
          ->assertSee('IN-AFN')->assertSee('IN-USD')
          ->assertSee('200.00')->assertDontSee('BEFORE-AFN')
          ->assertDontSee('AFTER-AFN')->assertDontSee('DRAFT-AFN');
    }

    public function test_supplier_opening_and_closing_balances_keep_payable_direction():void
    {
        [$user,$afn,$usd,$supplier]=$this->fixture('supplier');
        $this->journal($user,$supplier,$afn,'2026-10-01','SUP-BEFORE','40.00');
        $this->journal($user,$supplier,$afn,'2026-11-04','SUP-IN','10.00');
        $this->actingAs($user)->get(route('finance.parties.statement',[
            'party'=>$supplier,'from'=>'2026-11-01','currency_id'=>$afn->id
        ]))->assertOk()->assertSee('Opening: -40.00')
          ->assertSee('50.00')->assertSee('They owe us')
          ->assertDontSee('SUP-BEFORE');
    }

    public function test_csv_export_contains_only_filtered_posted_rows_and_no_other_currency():void
    {
        [$user,$afn,$usd,$party]=$this->fixture();
        $this->journal($user,$party,$afn,'2026-10-01','OLD-AFN','30.00');
        $this->journal($user,$party,$afn,'2026-11-04','CURRENT-AFN','60.00');
        $this->journal($user,$party,$usd,'2026-11-04','CURRENT-USD','900.00');
        $this->journal($user,$party,$afn,'2026-11-05','DRAFT-AFN','999.00','draft');
        $response=$this->actingAs($user)->get(route('finance.parties.statement.export',[
            'party'=>$party,'currency_id'=>$afn->id,'from'=>'2026-11-01','to'=>'2026-11-30'
        ]))->assertOk()->assertHeader('Content-Type','text/csv; charset=UTF-8');
        $csv=$response->streamedContent();
        $this->assertStringContainsString('CURRENT-AFN',$csv);
        $this->assertStringContainsString('30.00',$csv);
        $this->assertStringContainsString('90',$csv);
        $this->assertStringNotContainsString('OLD-AFN',$csv);
        $this->assertStringNotContainsString('CURRENT-USD',$csv);
        $this->assertStringNotContainsString('DRAFT-AFN',$csv);
    }

    public function test_statement_rejects_unenabled_currency_and_invalid_period():void
    {
        [$user,$afn,$usd,$party]=$this->fixture();
        $eur=Currency::create(['code'=>'EUR','name'=>'Euro','is_active'=>true]);
        $this->actingAs($user)->get(route('finance.parties.statement',[
            'party'=>$party,'currency_id'=>$eur->id
        ]))->assertSessionHasErrors('currency_id');
        $this->actingAs($user)->get(route('finance.parties.statement',[
            'party'=>$party,'from'=>'2026-12-01','to'=>'2026-11-01'
        ]))->assertSessionHasErrors('to');
    }

    public function test_statement_export_requires_view_permission():void
    {
        [$user,$afn,$usd,$party]=$this->fixture();
        $unauthorized=User::factory()->create();
        $this->actingAs($unauthorized)->get(route('finance.parties.statement.export',$party))->assertForbidden();
    }
}

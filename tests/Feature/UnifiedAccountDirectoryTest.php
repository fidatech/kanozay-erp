<?php
namespace Tests\Feature;

use App\Models\Currency;
use App\Models\FinancialAccount;
use App\Models\TradeParty;
use App\Models\User;
use App\Models\JournalEntry;
use App\Services\Accounting\EntityAccountProvisioner;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class UnifiedAccountDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture():array
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $user=User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('finance.view','web'));
        $afn=Currency::create(['code'=>'AFN','name'=>'Afghani','is_active'=>true,'is_base'=>true]);
        $usd=Currency::create(['code'=>'USD','name'=>'US Dollar','is_active'=>true]);
        $provisioner=app(EntityAccountProvisioner::class);
        $customer=TradeParty::create(['type'=>'customer','name'=>'Kabul Customer','is_active'=>true,'created_by'=>$user->id]);
        $customer->currencies()->attach([$afn->id,$usd->id]);
        $provisioner->provision('customer',$customer->id,$customer->name,$afn->id);
        $provisioner->provision('customer',$customer->id,$customer->name,$usd->id);
        $supplier=TradeParty::create(['type'=>'supplier','name'=>'Kanozay Supplier','is_active'=>true,'created_by'=>$user->id]);
        $supplier->currencies()->attach($afn->id);
        $provisioner->provision('supplier',$supplier->id,$supplier->name,$afn->id);
        foreach(['cashbox'=>'Main Cash','bank'=>'Operating Bank','sarafi'=>'Trading Sarafi'] as $type=>$name){
            $account=FinancialAccount::create(['type'=>$type,'name'=>$name,'currency_id'=>$afn->id,'is_active'=>true,'created_by'=>$user->id]);
            $provisioner->provision($type,$account->id,$name,$afn->id);
        }
        return [$user,$afn,$usd,$customer,$supplier];
    }

    public function test_directory_combines_parties_and_financial_accounts_with_separate_currency_rows():void
    {
        [$user,$afn,$usd]=$this->fixture();
        $this->actingAs($user)->get(route('finance.directory.index'))->assertOk()
            ->assertSee('All Accounts Directory')->assertSee('Kabul Customer')
            ->assertSee('Kanozay Supplier')->assertSee('Main Cash')
            ->assertSee('Operating Bank')->assertSee('Trading Sarafi')
            ->assertSee('Credit / Debit')->assertSee('Money Movements');
        $this->actingAs($user)->get(route('finance.directory.index',['type'=>'cashbox']))
            ->assertOk()->assertSee('Main Cash')->assertDontSee('Operating Bank');
        $this->actingAs($user)->get(route('finance.directory.index',['type'=>'customer','currency_id'=>$usd->id]))
            ->assertOk()->assertSee('Kabul Customer')->assertDontSee('Kanozay Supplier')
            ->assertSee('USD');
    }

    public function test_posted_balances_are_currency_specific_and_drafts_are_excluded():void
    {
        [$user,$afn,$usd,$customer]=$this->fixture();
        $account=DB::table('entity_ledger_accounts')->where([
            'entity_type'=>'customer','entity_id'=>$customer->id,
            'currency_id'=>$afn->id,'purpose'=>'receivable'
        ])->value('ledger_account_id');
        $journal=JournalEntry::create(['number'=>'JE-DR-01','entry_date'=>'2026-11-04',
            'description'=>'Test','currency_id'=>$afn->id,'status'=>'posted','created_by'=>$user->id]);
        $journal->lines()->create(['ledger_account_id'=>$account,'debit'=>'125.0000','credit'=>'0']);
        $draft=JournalEntry::create(['number'=>'JE-DR-02','entry_date'=>'2026-11-04',
            'description'=>'Draft','currency_id'=>$afn->id,'status'=>'draft','created_by'=>$user->id]);
        $draft->lines()->create(['ledger_account_id'=>$account,'debit'=>'999.0000','credit'=>'0']);
        $this->actingAs($user)->get(route('finance.directory.index',['type'=>'customer','currency_id'=>$afn->id]))
            ->assertOk()->assertSee('125.00 Dr')->assertDontSee('1124.00');
        $this->actingAs($user)->get(route('finance.directory.index',['type'=>'customer','currency_id'=>$usd->id]))
            ->assertOk()->assertDontSee('125.00 Dr');
    }

    public function test_directory_requires_finance_read_permission():void
    {
        $this->actingAs(User::factory()->create())->get(route('finance.directory.index'))->assertForbidden();
    }
}

<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\DocumentSequence;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMaterial;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Evidence-backed recognition of an existing, completely unconsumed
 * physical opening FIFO lot. Physical stock is never added a second time.
 *
 * The historical opening source date is preserved. The approved financial
 * entry is posted in an explicitly selected OPEN accounting period.
 */
class HistoricalOpeningStockValuationService
{
    public function __construct(private readonly JournalPostingService $journals) {}

    private function fail(string $message):never
    {
        throw ValidationException::withMessages(['opening_stock'=>$message]);
    }

    public function post(int $caseId,array $data,int $actorId):object
    {
        return DB::transaction(function()use($caseId,$data,$actorId){
            $period=app(FinancePeriodClosingService::class);
            $period->holdPostingGate();
            $period->assertOpenDate($data['posting_date']);

            $case=DB::table('finance_historical_correction_cases')
                ->where('id',$caseId)->lockForUpdate()->first();
            abort_unless($case,404);
            if($case->case_type!=='opening_fifo_unvalued' ||
                $case->status!=='approved'){
                $this->fail('Only an independently approved opening FIFO correction case can be financially capitalized.');
            }
            if((int)$case->requested_by===$actorId ||
                (int)$case->reviewed_by===(int)$case->requested_by){
                $this->fail('The historical stock requester cannot post their own opening inventory correction.');
            }
            $source=app(HistoricalFinanceCorrectionService::class)
                ->source($case->case_type,(int)$case->source_id);
            if(!hash_equals($case->source_fingerprint,$source['fingerprint'])){
                $this->fail('The original FIFO stock layer changed after independent finance review. File a new audited case.');
            }
            if($data['posting_date']<$source['date']){
                $this->fail('Opening-stock finance posting cannot predate the physical opening receipt.');
            }

            $first=InventoryCostLayer::whereKey($case->source_id)->firstOrFail();
            $material=InventoryMaterial::whereKey($first->inventory_material_id)
                ->lockForUpdate()->firstOrFail();
            $layer=InventoryCostLayer::whereKey($first->id)->lockForUpdate()->firstOrFail();

            if($layer->source_type!=='inventory_stock_movement' ||
                $layer->original_cost!==null || $layer->currency_id!==null ||
                $layer->currency_code!==null ||
                DB::table('finance_opening_fifo_valuations')
                    ->where('inventory_cost_layer_id',$layer->id)->exists()){
                $this->fail('Only an existing, entirely unvalued, never-capitalized opening stock movement can use this correction.');
            }
            $movement=DB::table('inventory_stock_movements')
                ->where('id',$layer->source_id)->first();
            if(!$movement || $movement->type!=='opening' ||
                $movement->direction!=='in' ||
                (int)$movement->inventory_material_id!==(int)$material->id ||
                !BigDecimal::of((string)$movement->quantity)
                    ->isEqualTo((string)$layer->original_quantity) ||
                (string)$movement->occurred_on!==$source['date']){
                $this->fail('FIFO lot does not match an immutable physical opening stock receipt with the original material, quantity and date.');
            }
            $original=BigDecimal::of((string)$layer->original_quantity);
            if(!$original->isPositive() ||
                !$original->isEqualTo((string)$layer->remaining_quantity) ||
                DB::table('inventory_cost_consumptions')
                    ->where('inventory_cost_layer_id',$layer->id)->exists()){
                $this->fail('Opening FIFO lot has already been partially consumed or changed. Historic source cost must be reconciled without repricing earlier issues.');
            }
            if(BigDecimal::of((string)$material->on_hand_quantity)->isLessThan($original)){
                $this->fail('Current physical material balance is less than the unchanged original opening FIFO quantity.');
            }
            $positions=DB::table('inventory_cost_layer_locations')
                ->where('inventory_cost_layer_id',$layer->id)
                ->lockForUpdate()->get();
            if($positions->isEmpty()){
                $this->fail('Opening stock warehouse locations have not been physically reconciled.');
            }
            $located=BigDecimal::zero();
            foreach($positions as $position){
                if(!$position->company_warehouse_id ||
                    !DB::table('company_warehouses')
                        ->where('id',$position->company_warehouse_id)
                        ->where('is_active',true)->exists()){
                    $this->fail('Every valued opening-stock quantity must be assigned to an active, physically verified warehouse.');
                }
                $quantity=BigDecimal::of((string)$position->quantity);
                if(!$quantity->isPositive())continue;
                $balance=DB::table('inventory_warehouse_balances')
                    ->where('inventory_material_id',$material->id)
                    ->where('company_warehouse_id',$position->company_warehouse_id)
                    ->lockForUpdate()->first();
                if(!$balance || BigDecimal::of((string)$balance->quantity)
                    ->isLessThan($quantity)){
                    $this->fail('Original opening FIFO quantity exceeds the physical material balance at a listed warehouse.');
                }
                $located=$located->plus($quantity);
            }
            if(!$located->isEqualTo($original)){
                $this->fail('Warehouse positions do not add up to the original opening lot quantity.');
            }

            $currency=Currency::whereKey($data['currency_id'])
                ->where('is_active',true)->firstOrFail();
            $policy=app(ManufacturingLedgerPolicyService::class)
                ->approved((int)$currency->id);
            $inventory=(int)$policy->material_inventory_ledger_id;
            $equity=LedgerAccount::whereKey($data['opening_equity_ledger_id'])->first();
            if(!$equity || !$equity->is_active || $equity->is_group ||
                $equity->type!=='equity' ||
                DB::table('entity_ledger_accounts')
                    ->where('ledger_account_id',$equity->id)->exists()){
                $this->fail('Choose an active, independently approved opening-equity posting account, never a customer, supplier or cashbox-specific ledger.');
            }
            $amount=BigDecimal::of((string)$data['original_cost']);
            if(!$amount->isPositive() ||
                $amount->isGreaterThan('9999999999999999.9999')){
                $this->fail('Original opening inventory valuation must be positive and within GL precision.');
            }
            $postedAmount=(string)$amount->toScale(4,RoundingMode::Unnecessary);
            DocumentSequence::firstOrCreate(['document_type'=>'historical_opening_fifo'],[
                'prefix'=>'HFO-','next_number'=>1,'padding'=>7,'is_active'=>true,
            ]);
            $journal=JournalEntry::create([
                'number'=>DocumentSequence::issue('historical_opening_fifo'),
                'entry_date'=>$data['posting_date'],
                'reference'=>substr($case->case_number,0,100),
                'description'=>'Independent opening stock original-cost recognition for '.
                    $material->sku.' / lot '.$layer->id.
                    ' (physical receipt unchanged)',
                'currency_id'=>$currency->id,
                'status'=>'draft','created_by'=>$actorId,
            ]);
            $journal->lines()->createMany([
                ['ledger_account_id'=>$inventory,'debit'=>$postedAmount,
                    'credit'=>'0','memo'=>'Existing, physically reconciled opening FIFO lot'],
                ['ledger_account_id'=>$equity->id,'debit'=>'0',
                    'credit'=>$postedAmount,'memo'=>'Signed historical opening equity source'],
            ]);
            $this->journals->post($journal,$actorId);

            // The original stock movement remains immutable. This value is
            // attached only after confirming there were zero FIFO issues.
            DB::table('inventory_cost_layers')->where('id',$layer->id)->update([
                'original_cost'=>(string)$amount->toScale(10),
                'currency_id'=>$currency->id,
                'currency_code'=>$currency->code,
                'updated_at'=>now(),
            ]);
            $postingId=DB::table('finance_opening_fifo_valuations')->insertGetId([
                'finance_historical_correction_case_id'=>$caseId,
                'inventory_cost_layer_id'=>$layer->id,
                'currency_id'=>$currency->id,
                'inventory_ledger_id'=>$inventory,
                'opening_equity_ledger_id'=>$equity->id,
                'original_cost'=>$postedAmount,
                'posting_date'=>$data['posting_date'],
                'journal_entry_id'=>$journal->id,
                'evidence_reference'=>trim($data['evidence_reference']),
                'reconciliation_notes'=>trim($data['reconciliation_notes']),
                'posted_by'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('finance_historical_correction_cases')
                ->where('id',$caseId)->update([
                    'status'=>'resolved','open_key'=>null,
                    'source_currency_id'=>$currency->id,
                    'resolution_reference'=>trim($data['evidence_reference']),
                    'resolution_notes'=>trim($data['reconciliation_notes']),
                    'executed_by'=>$actorId,'executed_at'=>now(),
                    'updated_at'=>now(),
                ]);
            DB::table('finance_historical_correction_events')->insert([
                'finance_historical_correction_case_id'=>$caseId,
                'action'=>'resolved','source_reference'=>$source['reference'],
                'source_fingerprint'=>$source['fingerprint'],
                'approval_reference'=>trim($data['evidence_reference']),
                'notes'=>'Unconsumed source FIFO lot valued at '.$postedAmount.' '.$currency->code.
                    '; original physical opening unchanged; journal '.$journal->number.
                    '; '.$data['reconciliation_notes'],
                'actor_id'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('finance.historical_opening_inventory.capitalized',
                'finance_historical_correction_case',$caseId,
                'Source-verified unconsumed physical FIFO opening recognized in asset/equity ledgers; no duplicate stock movement.',[
                    'layer_id'=>$layer->id,'journal_id'=>$journal->id,
                    'posting_id'=>$postingId,'currency_id'=>$currency->id,
                    'amount'=>$postedAmount,
                ]);
            return DB::table('finance_opening_fifo_valuations')
                ->where('id',$postingId)->first();
        });
    }
}

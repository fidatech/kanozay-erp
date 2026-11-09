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
 * Very narrow historical source accounting correction. Only previously
 * UNVALUED, independently inspected OPENING FIFO layers whose every
 * original consumption is a signed, original stock-adjustment WRITE-OFF
 * may be recognized.
 *
 * Production issues and supplier returns have downstream cost/liability
 * dependencies and intentionally cannot use this executor.
 */
final class HistoricalConsumedFifoAdjustmentValuationService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['historical_fifo_valuation'=>$message]);
    }

    private function money(string $value): BigDecimal
    {
        $cost=BigDecimal::of($value)->toScale(4,RoundingMode::Unnecessary);
        if(!$cost->isPositive() || $cost->isGreaterThan('9999999999999999.9999'))
            $this->fail('An authenticated historical opening source cost must be strictly positive and fit the original-currency GL precision.');
        return $cost;
    }

    private function account(int $id,string $type): object
    {
        $ledger=LedgerAccount::find($id);
        if(!$ledger || !$ledger->is_active || $ledger->is_group ||
            $ledger->type!==$type ||
            DB::table('entity_ledger_accounts')
                ->where('ledger_account_id',$id)->exists())
            $this->fail('Choose a separately approved, active posting ledger with the required type and no customer, supplier, employee or cash mapping.');
        return $ledger;
    }

    private function financialSource(int $reviewId): array
    {
        $review=DB::table('finance_legacy_fifo_source_reviews')
            ->where('id',$reviewId)->first();
        abort_unless($review,404);
        if($review->review_type!=='consumed_provenance' ||
            $review->status!=='approved' || !$review->reviewed_by ||
            !$review->source_evidence_reference ||
            (int)$review->requested_by===(int)$review->reviewed_by)
            $this->fail('An independently approved, source-fingerprinted historical consumed-opening FIFO provenance review is required.');

        $source=app(LegacyFifoSourceReviewService::class)
            ->snapshot((int)$review->inventory_cost_layer_id);
        if(!hash_equals((string)$review->source_fingerprint,
                (string)$source['fingerprint']) ||
            !$source['consumed']->isPositive() ||
            $source['layer']->original_cost!==null ||
            $source['layer']->currency_id!==null ||
            $source['layer']->currency_code!==null ||
            (int)$review->inventory_material_id!==(int)$source['material']->id ||
            !BigDecimal::of((string)$review->opening_quantity)
                ->isEqualTo($source['original']) ||
            !BigDecimal::of((string)$review->consumed_quantity)
                ->isEqualTo($source['consumed']) ||
            !BigDecimal::of((string)$review->remaining_quantity)
                ->isEqualTo($source['remaining']) ||
            DB::table('finance_opening_fifo_valuations')
                ->where('inventory_cost_layer_id',$source['layer']->id)->exists())
            $this->fail('Original FIFO issue, inventory, source price, accounting, physical location or opening evidence changed since independent documentary review.');

        if(DB::table('finance_historical_correction_cases')
            ->where('case_type','opening_fifo_unvalued')
            ->where('source_id',$source['layer']->id)
            ->whereIn('status',['submitted','approved'])->exists())
            $this->fail('An earlier original opening FIFO correction case for this same lot is still open. Withdraw or independently resolve that source case first; never book two competing historic opening asset/equity valuations.');

        if($source['consumptions']->count()>100)
            $this->fail('More than 100 original source adjustment issues need a separately supported chunked accounting correction.');
        $issues=[];$actors=[
            (int)$source['opening']->created_by,
            (int)$review->requested_by,(int)$review->reviewed_by,
        ];
        foreach($source['consumptions'] as $consumed){
            if($consumed->reference_type!=='inventory_adjustment' ||
                $consumed->type!=='adjustment_out' ||
                $consumed->direction!=='out')
                $this->fail('Historical production issues, supplier returns and other adjustments cannot be expensed as generic stock-adjustment write-offs. Reconstruct their genuine WIP, supplier or loss source separately.');
            $adjustment=DB::table('inventory_stock_adjustments')
                ->where('id',$consumed->reference_id)->first();
            if(!$adjustment || $adjustment->direction!=='adjustment_out' ||
                (int)$adjustment->inventory_material_id!==(int)$source['material']->id ||
                (int)($adjustment->company_warehouse_id??0)!==
                    (int)($consumed->company_warehouse_id??0) ||
                (string)$adjustment->occurred_on!==(string)$consumed->occurred_on ||
                (int)$adjustment->created_by!==(int)$consumed->created_by ||
                !$adjustment->reason ||
                !BigDecimal::of((string)$adjustment->quantity)
                    ->isEqualTo((string)$consumed->movement_quantity))
                $this->fail('The original consumed stock adjustment is missing its independently recorded real physical adjustment, dated reason or exact material/warehouse/quantity match.');
            if($consumed->amount!==null || $consumed->is_valued)
                $this->fail('Source adjustment already contains a financial FIFO consumption value. Do not recognize the same loss twice.');
            $issues[]=[
                'consumption_id'=>(int)$consumed->id,
                'movement_id'=>(int)$consumed->inventory_stock_movement_id,
                'date'=>substr((string)$consumed->occurred_on,0,10),
                'quantity'=>(string)BigDecimal::of((string)$consumed->quantity)->toScale(6),
                'source'=>[
                    $consumed->id,$consumed->inventory_stock_movement_id,
                    $consumed->quantity,$consumed->reference_type,
                    $consumed->reference_id,$consumed->occurred_on,
                    $consumed->company_warehouse_id,$consumed->created_by,
                    $adjustment->id,$adjustment->reason,
                    $adjustment->quantity,$adjustment->occurred_on,
                    $adjustment->idempotency_key,
                ],
            ];
            $actors[]=(int)$consumed->created_by;
            $actors[]=(int)$adjustment->created_by;
            if((int)$review->reviewed_by===(int)$adjustment->created_by ||
                (int)$review->requested_by===(int)$adjustment->created_by)
                $this->fail('Source consumption provenance requester/reviewer must be independent of original historic stock-adjustment author; seek a new fully independent audited source review.');
        }
        if(!$issues)
            $this->fail('The source has no genuine consumed FIFO stock-adjustment write-offs to correct.');
        usort($issues,fn($a,$b)=>[$a['date'],$a['movement_id'],$a['consumption_id']]
            <=>[$b['date'],$b['movement_id'],$b['consumption_id']]);

        // Remaining physical stock must already have independently
        // reconciled source lot positions. Never recognize unlocated
        // uncounted inventory as an asset.
        if($source['remaining']->isPositive()){
            if(!$source['located']->isEqualTo($source['remaining']))
                $this->fail('Original unconsumed FIFO portion must be fully attributed to real counted source warehouses before recognizing an inventory asset.');
            foreach($source['positions'] as $position){
                if(BigDecimal::of((string)$position->quantity)->isPositive() &&
                    (!$position->company_warehouse_id ||
                     !DB::table('company_warehouses')
                         ->where('id',$position->company_warehouse_id)
                         ->where('is_active',true)->exists()))
                    $this->fail('All remaining original opening FIFO quantity must be physically attributed to active warehouses, never the unassigned pool.');
            }
            if(BigDecimal::of((string)$source['material']->on_hand_quantity)
                ->isLessThan($source['remaining']))
                $this->fail('Existing global material physical stock cannot support the remaining original opening FIFO asset.');
        }
        return [
            'review'=>$review,'source'=>$source,
            'issues'=>$issues,
            'source_actors'=>array_values(array_unique(array_filter($actors))),
        ];
    }

    private function proposal(int $reviewId,array $data): array
    {
        $proof=$this->financialSource($reviewId);
        $source=$proof['source'];
        $currency=Currency::whereKey((int)$data['currency_id'])
            ->where('is_active',true)->firstOrFail();
        $policy=app(ManufacturingLedgerPolicyService::class)->approved((int)$currency->id);
        $equity=$this->account((int)$data['opening_equity_ledger_id'],'equity');
        $expense=$this->account((int)$data['adjustment_loss_ledger_id'],'expense');
        $inventory=(int)$policy->material_inventory_ledger_id;
        if(in_array((int)$expense->id,[
            $inventory,(int)$policy->work_in_progress_ledger_id,
            (int)$policy->finished_inventory_ledger_id,
            (int)$policy->cogs_ledger_id,
        ],true) || (int)$equity->id===$inventory ||
            (int)$expense->id===(int)$equity->id)
            $this->fail('An opening equity account, raw-material inventory asset and independently signed adjustment-loss expense must be distinct; historical production costs cannot be silently booked as adjustment losses.');

        $cost=$this->money((string)$data['original_total_cost']);
        $original=$source['original'];
        $remaining=$source['remaining'];
        $remainingAmount=$remaining->isZero()
            ?BigDecimal::zero()
            :$cost->multipliedBy($remaining)
                ->dividedBy($original,4,RoundingMode::HalfUp);
        $consumedAmount=$cost->minus($remainingAmount);
        if(!$consumedAmount->isPositive() ||
            ($remaining->isPositive() && !$remainingAmount->isPositive()))
            $this->fail('Historical original source cost cannot support both the genuinely consumed and remaining original opening stock in four-decimal GL precision.');

        $used=BigDecimal::zero();$allocations=[];
        foreach($proof['issues'] as $index=>$issue){
            $isLast=$index===count($proof['issues'])-1;
            $share=$isLast
                ?$consumedAmount->minus($used)
                :$cost->multipliedBy($issue['quantity'])
                    ->dividedBy($original,4,RoundingMode::HalfUp);
            if(!$share->isPositive() ||
                $share->isGreaterThan($consumedAmount->minus($used)))
                $this->fail('A genuine original FIFO adjustment has insufficient authenticated source cost to post a nonzero original-currency GL expense.');
            $used=$used->plus($share);
            $issue['amount']=(string)$share->toScale(4);
            $issue['fingerprint']=hash('sha256',json_encode([
                'original_source'=>$issue['source'],
                'source_cost'=>(string)$cost->toScale(4),
                'currency_id'=>(int)$currency->id,
                'amount'=>$issue['amount'],
                'expense_ledger'=>(int)$expense->id,
            ],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
            $allocations[]=$issue;
        }
        if(!$used->isEqualTo($consumedAmount) ||
            !$remainingAmount->plus($consumedAmount)->isEqualTo($cost))
            $this->fail('Original opening inventory source cost, remaining asset and signed historical adjustment issues must reconcile exactly with no lost rounding remainder.');

        $fingerprint=hash('sha256',json_encode([
            'source_fingerprint'=>$proof['review']->source_fingerprint,
            'source_review_id'=>$reviewId,
            'original_cost'=>(string)$cost,'currency_id'=>(int)$currency->id,
            'material_ledger_id'=>$inventory,
            'equity_ledger_id'=>(int)$equity->id,
            'loss_ledger_id'=>(int)$expense->id,
            'remaining_cost'=>(string)$remainingAmount->toScale(4),
            'consumed_cost'=>(string)$consumedAmount->toScale(4),
            'allocations'=>$allocations,
        ],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
        return [
            'proof'=>$proof,'currency'=>$currency,'policy'=>$policy,
            'cost'=>(string)$cost,
            'remaining_cost'=>(string)$remainingAmount->toScale(4),
            'consumed_cost'=>(string)$consumedAmount->toScale(4),
            'equity_id'=>(int)$equity->id,
            'expense_id'=>(int)$expense->id,
            'inventory_id'=>$inventory,
            'fingerprint'=>$fingerprint,'allocations'=>$allocations,
        ];
    }

    private function independent(array $proof,int $actorId,?object $plan=null): void
    {
        $actors=$proof['source_actors'];
        if($plan){
            $actors[]=(int)$plan->submitted_by;
            $actors[]=(int)($plan->approved_by??0);
        }
        if(in_array($actorId,$actors,true))
            $this->fail('Original receipt/adjustment registrar, source-provenance author, independent reviewer, valuation proposer, final approver and posting executor must be different authorized people.');
    }

    public function submit(int $reviewId,array $data,int $actorId): object
    {
        return DB::transaction(function()use($reviewId,$data,$actorId){
            $initial=DB::table('finance_legacy_fifo_source_reviews')
                ->where('id',$reviewId)->first();
            abort_unless($initial,404);
            InventoryMaterial::whereKey($initial->inventory_material_id)
                ->lockForUpdate()->firstOrFail();
            InventoryCostLayer::whereKey($initial->inventory_cost_layer_id)
                ->lockForUpdate()->firstOrFail();
            $state=$this->proposal($reviewId,$data);
            $this->independent($state['proof'],$actorId);
            if(DB::table('finance_historical_fifo_adjustment_valuations')
                ->where('inventory_cost_layer_id',$initial->inventory_cost_layer_id)->exists())
                $this->fail('This historically issued opening FIFO layer already has a signed financial cost proposal. A new independent amendment workflow is required; no duplicate original asset or loss may be posted.');

            $id=DB::table('finance_historical_fifo_adjustment_valuations')->insertGetId([
                'inventory_cost_layer_id'=>$initial->inventory_cost_layer_id,
                'finance_legacy_fifo_source_review_id'=>$reviewId,
                'status'=>'submitted',
                'currency_id'=>$state['currency']->id,
                'inventory_ledger_id'=>$state['inventory_id'],
                'opening_equity_ledger_id'=>$state['equity_id'],
                'adjustment_loss_ledger_id'=>$state['expense_id'],
                'original_total_cost'=>$state['cost'],
                'remaining_original_cost'=>$state['remaining_cost'],
                'consumed_original_cost'=>$state['consumed_cost'],
                'original_quantity'=>(string)$state['proof']['source']['original']->toScale(6),
                'remaining_quantity'=>(string)$state['proof']['source']['remaining']->toScale(6),
                'source_fingerprint'=>$state['fingerprint'],
                'original_cost_evidence_reference'=>trim($data['original_cost_evidence_reference']),
                'source_cost_reconciliation'=>trim($data['source_cost_reconciliation']),
                'submitted_by'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            foreach($state['allocations'] as $line)
                DB::table('finance_historical_fifo_adjustment_cost_lines')->insert([
                    'finance_historical_fifo_adjustment_valuation_id'=>$id,
                    'inventory_cost_consumption_id'=>$line['consumption_id'],
                    'inventory_stock_movement_id'=>$line['movement_id'],
                    'original_issue_date'=>$line['date'],
                    'quantity'=>$line['quantity'],
                    'amount'=>$line['amount'],
                    'source_fingerprint'=>$line['fingerprint'],
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            ActivityLog::record('finance.historical_fifo_adjustment.submitted',
                'finance_historical_fifo_adjustment_valuation',$id,
                'Original signed FIFO adjustment source cost and consumed/remaining cost fractions submitted for a different finance reviewer. No costs or GL were changed.');
            return DB::table('finance_historical_fifo_adjustment_valuations')->where('id',$id)->first();
        });
    }

    private function unchanged(object $plan): array
    {
        $state=$this->proposal((int)$plan->finance_legacy_fifo_source_review_id,[
            'currency_id'=>$plan->currency_id,
            'original_total_cost'=>$plan->original_total_cost,
            'opening_equity_ledger_id'=>$plan->opening_equity_ledger_id,
            'adjustment_loss_ledger_id'=>$plan->adjustment_loss_ledger_id,
        ]);
        if((int)$plan->inventory_cost_layer_id!==
            (int)$state['proof']['source']['layer']->id ||
            (int)$plan->inventory_ledger_id!==$state['inventory_id'] ||
            !hash_equals((string)$plan->source_fingerprint,$state['fingerprint']) ||
            !BigDecimal::of((string)$plan->remaining_original_cost)
                ->isEqualTo($state['remaining_cost']) ||
            !BigDecimal::of((string)$plan->consumed_original_cost)
                ->isEqualTo($state['consumed_cost']))
            $this->fail('The original FIFO issue, approved original cost and currency, equity, inventory or loss account changed after the signed valuation proposal.');
        $recorded=DB::table('finance_historical_fifo_adjustment_cost_lines')
            ->where('finance_historical_fifo_adjustment_valuation_id',$plan->id)
            ->orderBy('inventory_cost_consumption_id')->get()
            ->keyBy('inventory_cost_consumption_id');
        if($recorded->count()!==count($state['allocations']))
            $this->fail('Original historical cost proposal is missing a source adjustment issue or contains duplicate allocation rows.');
        foreach($state['allocations'] as $line){
            $posted=$recorded->get($line['consumption_id']);
            if(!$posted ||
                (int)$posted->inventory_stock_movement_id!==$line['movement_id'] ||
                (string)$posted->original_issue_date!==$line['date'] ||
                !BigDecimal::of((string)$posted->quantity)->isEqualTo($line['quantity']) ||
                !BigDecimal::of((string)$posted->amount)->isEqualTo($line['amount']) ||
                !hash_equals((string)$posted->source_fingerprint,$line['fingerprint']) ||
                $posted->journal_entry_id!==null)
                $this->fail('The signed original adjustment source quantity, dated cost fraction or expense allocation differs from independently approved evidence.');
        }
        return [$state,$recorded];
    }

    public function approve(int $planId,array $data,int $actorId):object
    {
        return DB::transaction(function()use($planId,$data,$actorId){
            $plan=DB::table('finance_historical_fifo_adjustment_valuations')
                ->where('id',$planId)->lockForUpdate()->first();
            abort_unless($plan,404);
            if($plan->status!=='submitted')
                $this->fail('Only a submitted, unposted historical opening FIFO valuation may be reviewed.');
            $state=$this->proposal((int)$plan->finance_legacy_fifo_source_review_id,[
                'currency_id'=>$plan->currency_id,
                'original_total_cost'=>$plan->original_total_cost,
                'opening_equity_ledger_id'=>$plan->opening_equity_ledger_id,
                'adjustment_loss_ledger_id'=>$plan->adjustment_loss_ledger_id,
            ]);
            $this->independent($state['proof'],$actorId,$plan);
            $this->unchanged($plan);
            DB::table('finance_historical_fifo_adjustment_valuations')
                ->where('id',$planId)->update([
                    'status'=>$data['decision']==='approve'?'approved':'rejected',
                    'approval_reference'=>trim($data['approval_reference']),
                    'approval_notes'=>trim($data['approval_notes']),
                    'approved_by'=>$actorId,'approved_at'=>now(),'updated_at'=>now(),
                ]);
            ActivityLog::record('finance.historical_fifo_adjustment.'.
                ($data['decision']==='approve'?'approved':'rejected'),
                'finance_historical_fifo_adjustment_valuation',$planId,
                'Independent finance review of original documented consumption adjustment cost and opening equity; source quantities and GL unchanged.');
            return DB::table('finance_historical_fifo_adjustment_valuations')
                ->where('id',$planId)->first();
        });
    }

    private function postJournal(string $date,int $currencyId,string $reference,
        string $description,array $debits,array $credits,int $actorId):JournalEntry
    {
        $debitTotal=BigDecimal::zero();$creditTotal=BigDecimal::zero();
        foreach($debits as $line)$debitTotal=$debitTotal->plus((string)$line['amount']);
        foreach($credits as $line)$creditTotal=$creditTotal->plus((string)$line['amount']);
        if(!$debitTotal->isEqualTo($creditTotal) || !$debitTotal->isPositive())
            $this->fail('The source-linked original-currency opening and adjustment journals must balance exactly.');
        DocumentSequence::firstOrCreate(['document_type'=>'historical_consumed_fifo'],[
            'prefix'=>'HCF-','next_number'=>1,'padding'=>7,'is_active'=>true,
        ]);
        $journal=JournalEntry::create([
            'number'=>DocumentSequence::issue('historical_consumed_fifo'),
            'entry_date'=>$date,'reference'=>substr($reference,0,100),
            'description'=>$description,
            'status'=>'draft','currency_id'=>$currencyId,'created_by'=>$actorId,
        ]);
        foreach($debits as $line)
            $journal->lines()->create([
                'ledger_account_id'=>$line['ledger'],'debit'=>$line['amount'],
                'credit'=>'0','memo'=>substr($line['memo'],0,255),
            ]);
        foreach($credits as $line)
            $journal->lines()->create([
                'ledger_account_id'=>$line['ledger'],'debit'=>'0',
                'credit'=>$line['amount'],'memo'=>substr($line['memo'],0,255),
            ]);
        app(JournalPostingService::class)->post($journal,$actorId);
        return $journal;
    }

    public function post(int $planId,int $actorId):object
    {
        return DB::transaction(function()use($planId,$actorId){
            $initial=DB::table('finance_historical_fifo_adjustment_valuations')
                ->where('id',$planId)->first();
            abort_unless($initial,404);
            InventoryMaterial::whereKey(
                DB::table('inventory_cost_layers')->where('id',$initial->inventory_cost_layer_id)
                    ->value('inventory_material_id')
            )->lockForUpdate()->firstOrFail();
            InventoryCostLayer::whereKey($initial->inventory_cost_layer_id)
                ->lockForUpdate()->firstOrFail();
            $plan=DB::table('finance_historical_fifo_adjustment_valuations')
                ->where('id',$planId)->lockForUpdate()->first();
            if($plan->status!=='approved' || !$plan->approved_by ||
                $plan->opening_journal_entry_id!==null)
                $this->fail('Only an independently approved, unposted original adjustment FIFO cost plan can execute.');
            [$state,$recorded]=$this->unchanged($plan);
            $this->independent($state['proof'],$actorId,$plan);
            $period=app(FinancePeriodClosingService::class);
            $period->holdPostingGate();
            $openingDate=$state['proof']['source']['layer']->received_on->toDateString();
            $period->assertOpenDate($openingDate);
            foreach($state['allocations'] as $line)
                $period->assertOpenDate($line['date']);

            $original=$this->postJournal($openingDate,(int)$plan->currency_id,
                'HCF-OPEN-'.$plan->inventory_cost_layer_id,
                'Original documented physical opening FIFO asset and opening-equity recognition',
                [['ledger'=>$plan->inventory_ledger_id,
                    'amount'=>(string)$plan->original_total_cost,
                    'memo'=>'Original physical FIFO opening lot #'.$plan->inventory_cost_layer_id]],
                [['ledger'=>$plan->opening_equity_ledger_id,
                    'amount'=>(string)$plan->original_total_cost,
                    'memo'=>'Original source-documented opening ownership/equity']],
                $actorId);
            foreach($state['allocations'] as $line){
                $posted=$this->postJournal($line['date'],(int)$plan->currency_id,
                    'HCF-ISSUE-'.$line['consumption_id'],
                    'Original physical stock adjustment write-off: cost moved from opening inventory asset to authorized adjustment loss',
                    [['ledger'=>$plan->adjustment_loss_ledger_id,
                        'amount'=>$line['amount'],
                        'memo'=>'Original stock adjustment consumption #'.$line['consumption_id']]],
                    [['ledger'=>$plan->inventory_ledger_id,
                        'amount'=>$line['amount'],
                        'memo'=>'Original FIFO opening lot cost issued on '.$line['date']]],
                    $actorId);
                DB::table('finance_historical_fifo_adjustment_cost_lines')
                    ->where('id',$recorded[$line['consumption_id']]->id)->update([
                        'journal_entry_id'=>$posted->id,'updated_at'=>now(),
                    ]);
            }
            // Original consumed movement/amount rows remain immutable.
            // Current remaining value is computed from original cost MINUS
            // these supplementary, independently posted issue allocations.
            DB::table('inventory_cost_layers')
                ->where('id',$plan->inventory_cost_layer_id)->update([
                    'original_cost'=>(string)BigDecimal::of((string)$plan->original_total_cost)
                        ->toScale(10),
                    'currency_id'=>$plan->currency_id,
                    'currency_code'=>$state['currency']->code,
                    'updated_at'=>now(),
                ]);
            DB::table('finance_historical_fifo_adjustment_valuations')
                ->where('id',$planId)->update([
                    'status'=>'posted','opening_journal_entry_id'=>$original->id,
                    'executed_by'=>$actorId,'executed_at'=>now(),'updated_at'=>now(),
                ]);
            ActivityLog::record('finance.historical_fifo_adjustment.posted',
                'finance_historical_fifo_adjustment_valuation',$planId,
                'Original signed opening FIFO asset/equity and historical adjustment loss journals posted atomically; immutable consumed FIFO rows and physical stock unchanged.',
                ['original_lot_id'=>$plan->inventory_cost_layer_id,
                    'original_cost'=>$plan->original_total_cost,
                    'consumed_cost'=>$plan->consumed_original_cost,
                    'remaining_cost'=>$plan->remaining_original_cost,
                    'currency_id'=>$plan->currency_id,
                    'original_opening_gl'=>$original->id,
                    'issue_gl_count'=>count($state['allocations'])]);
            return DB::table('finance_historical_fifo_adjustment_valuations')
                ->where('id',$planId)->first();
        });
    }
}

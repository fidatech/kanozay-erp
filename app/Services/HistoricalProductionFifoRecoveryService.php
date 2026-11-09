<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMaterial;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\ProductionBatch;
use App\Models\SalesInvoiceLine;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Narrow, complete original-date accounting recovery for ONE opening
 * FIFO source lot, ONE released production batch, ONE physical material
 * issue date and NO overhead/waste/non-sale/customer-return movements.
 *
 * Every genuine posted sale is costed with FinishedGoodsCostPostingService,
 * inside the SAME database transaction as opening asset/equity, WIP and FG.
 * Other production source shapes are deliberately blocked, not estimated.
 */
final class HistoricalProductionFifoRecoveryService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages([
            'historical_production_recovery'=>$message,
        ]);
    }

    private function money(string $amount):BigDecimal
    {
        $value=BigDecimal::of($amount)->toScale(4,RoundingMode::Unnecessary);
        if(!$value->isPositive() || $value->isGreaterThan('9999999999999999.9999'))
            $this->fail('Original source WIP cost must be positive and within the original-currency 20,4 journal precision.');
        return $value;
    }

    private function mappedAccountIsClean(int $id,string $type):bool
    {
        $ledger=LedgerAccount::find($id);
        return (bool)($ledger && $ledger->is_active && !$ledger->is_group &&
            $ledger->type===$type &&
            !DB::table('entity_ledger_accounts')->where('ledger_account_id',$id)->exists());
    }

    private function invoiceSource(object $physical,object $sale,object $invoice):array
    {
        $date=(string)$physical->dispatch_date;
        if($invoice->status!=='posted' || $invoice->invoice_date!==$date ||
            !$invoice->trade_document_id || !$invoice->journal_entry_id ||
            (int)$invoice->id!==(int)$sale->sales_invoice_id ||
            (int)$sale->dispatch_id!==(int)$physical->finished_good_dispatch_id ||
            (int)$sale->finished_good_id!==(int)$physical->finished_good_id ||
            (int)$sale->production_batch_id!==(int)$physical->production_batch_id ||
            (int)$sale->quantity_packages!==(int)$physical->quantity_packages ||
            (int)$sale->quantity_packages<1 ||
            (int)$invoice->customer_id<1)
            $this->fail('Historical finished-goods dispatch must have one genuine posted customer invoice on its EXACT physical date, product and original source quantity.');

        $document=DB::table('trade_documents')->where('id',$invoice->trade_document_id)->first();
        $revenue=DB::table('journal_entries')->where('id',$invoice->journal_entry_id)->first();
        if(!$document || !$revenue ||
            $document->type!=='receivable' ||
            (int)$document->trade_party_id!==(int)$invoice->customer_id ||
            (int)$document->currency_id!==(int)$invoice->currency_id ||
            (int)$document->journal_entry_id!==(int)$invoice->journal_entry_id ||
            !BigDecimal::of((string)$document->amount)
                ->isEqualTo((string)$invoice->total) ||
            $revenue->status!=='posted' || $revenue->reversal_of_id ||
            (string)$revenue->entry_date!==$date ||
            (int)$revenue->currency_id!==(int)$invoice->currency_id ||
            DB::table('journal_entries')
                ->where('reversal_of_id',$revenue->id)->exists())
            $this->fail('Original customer invoice has no genuine posted, unreversed and exact-date receivable/revenue journal in the real customer selling currency.');

        $receivable=DB::table('entity_ledger_accounts')->where([
            'entity_type'=>'customer','entity_id'=>$invoice->customer_id,
            'currency_id'=>$invoice->currency_id,'purpose'=>'receivable',
        ])->value('ledger_account_id');
        if(!$receivable)$this->fail('Original customer invoice receivable has no mapped genuine account in its selling currency.');
        $lines=DB::table('journal_lines as row')
            ->join('ledger_accounts as account',
                'account.id','=','row.ledger_account_id')
            ->where('row.journal_entry_id',$revenue->id)
            ->get(['row.ledger_account_id','row.debit','row.credit','account.type']);
        if($lines->count()!==2)
            $this->fail('Genuine original sales revenue must have precisely two matched original posted income and customer receivable journal lines.');
        $customerDebit=BigDecimal::zero();$incomeCredit=BigDecimal::zero();
        foreach($lines as $line){
            if((int)$line->ledger_account_id===(int)$receivable)
                $customerDebit=$customerDebit->plus((string)$line->debit)
                    ->minus((string)$line->credit);
            elseif($line->type==='income')
                $incomeCredit=$incomeCredit->plus((string)$line->credit)
                    ->minus((string)$line->debit);
            else $this->fail('Original revenue GL has unrelated ledger accounts; duplicate invoice cost cannot be safely posted.');
        }
        if(!$customerDebit->isEqualTo((string)$invoice->total) ||
            !$incomeCredit->isEqualTo((string)$invoice->total))
            $this->fail('Authentic posted invoice customer receivable and source income are not exactly equal to original customer sale value.');
        return [
            'physical'=>[
                $physical->id,$physical->finished_good_dispatch_id,
                $physical->production_batch_id,$physical->finished_good_id,
                $physical->quantity_packages,$physical->dispatch_date
            ],
            'sale'=>[
                $sale->id,$sale->sales_invoice_id,$sale->dispatch_id,
                $sale->production_batch_id,$sale->finished_good_id,
                $sale->quantity_packages,$sale->unit_price,$sale->line_total
            ],
            'invoice'=>[
                $invoice->id,$invoice->number,$invoice->status,
                $invoice->invoice_date,$invoice->customer_id,
                $invoice->currency_id,$invoice->trade_document_id,
                $invoice->journal_entry_id,$invoice->total
            ],
            'genuine_customer_GL'=>[
                $document->id,$document->amount,
                $revenue->id,$revenue->status,$revenue->entry_date,
                $receivable,$lines->toArray(),
            ],
        ];
    }

    /** Read-only snapshot. Recalculated for every independent decision. */
    private function source(int $wipReviewId,int $equityLedgerId):array
    {
        $verified=app(HistoricalProductionFifoSourceCostReviewService::class)
            ->verifiedApproved($wipReviewId);
        $wip=$verified['plan'];
        $state=$verified['state'];
        $proof=$state['proof'];

        if(count($proof['batches'])!==1)
            $this->fail('This executor supports exactly one production batch per original opening FIFO lot; multi-batch source cost recovery requires a separate coordinated approval.');
        $batchId=(int)array_key_first($proof['batches']);
        $batch=ProductionBatch::with(['costs','checks'])->findOrFail($batchId);
        if($batch->status!=='released' || (int)$batch->accepted_packages<1)
            $this->fail('The original finished goods batch must still be independently QC-released and have positive accepted physical packages.');

        $receipt=DB::table('finished_good_movements')
            ->where('production_batch_id',$batchId)
            ->where('type','production_receipt')->get();
        if($receipt->count()!==1 ||
            (int)$receipt->first()->quantity_packages!==(int)$batch->accepted_packages)
            $this->fail('A source recovered batch needs precisely one QC-approved original finished-goods physical receipt of its accepted packages.');
        $finishedDate=substr((string)$batch->released_at,0,10);
        if(!$finishedDate || strlen($finishedDate)!==10 ||
            $finishedDate<substr((string)$receipt->first()->occurred_on,0,10) ||
            $finishedDate>now()->toDateString())
            $this->fail('Original approved finished-goods cost date must match authentic historical QC release, after physical receipt, not an invented modern date.');

        $date=null;$sum=BigDecimal::zero();$quantity=BigDecimal::zero();
        $issueIds=[];$movementIds=[];$materialLineIds=[];
        foreach($state['lines'] as $line){
            if((int)$line['production_batch_id']!==$batchId)
                $this->fail('Multiple production batches consume this opening source lot. Do not capitalize only some of its original WIP costs.');
            if($date!==null && $date!==$line['date'])
                $this->fail('This first atomic executor supports a single genuinely recorded production material issue date per opening FIFO lot. Mixed historical issue dates need separate source journals and approval.');
            $date=$line['date'];
            if($date>$finishedDate ||
                $date<$proof['original']['layer']->received_on->toDateString())
                $this->fail('Historical FIFO production issue must follow its physical opening receipt and precede final QC release.');
            $sum=$sum->plus($line['proposed_wip_cost']);
            $quantity=$quantity->plus($line['quantity']);
            $issueIds[]=(int)$line['consumption_id'];
            $movementIds[]=(int)$line['movement_id'];
            $materialLineIds[]=(int)$line['material_line_id'];
        }
        if(!$date || !$sum->isEqualTo($state['consumed_cost']) ||
            !$quantity->isEqualTo($proof['original']['consumed']))
            $this->fail('Previously approved original source material issue quantities/amounts do not fully fund this production WIP.');

        $materialLineIds=array_values(array_unique($materialLineIds));
        $allLines=DB::table('production_order_materials')
            ->where('production_order_id',$batch->production_order_id)->get();
        $originalMaterialMovements=DB::table('inventory_stock_movements')
            ->where('reference_type','production_order_material')
            ->whereIn('reference_id',$allLines->pluck('id')->all())
            ->where('direction','out')->get();
        if($originalMaterialMovements->count()!==count($state['lines']) ||
            $originalMaterialMovements->pluck('id')->sort()->values()->all()!==
                collect($movementIds)->sort()->values()->all())
            $this->fail('Some original production raw materials came from other FIFO lots or unverified issues. The restricted executor cannot silently omit other ingredient or packaging costs.');
        $covered=BigDecimal::zero();
        foreach($allLines as $line){
            $expected=BigDecimal::of((string)($line->issued_quantity??'0'));
            if(!$expected->isPositive())continue;
            $sourceMovements=$originalMaterialMovements
                ->where('reference_id',$line->id);
            $actual=BigDecimal::zero();
            foreach($sourceMovements as $movement){
                $actual=$actual->plus((string)$movement->quantity);
                $originalCostRows=DB::table('inventory_cost_consumptions')
                    ->where('inventory_stock_movement_id',$movement->id)->get();
                if($originalCostRows->count()!==1 ||
                    (int)$originalCostRows->first()->inventory_cost_layer_id!==
                        (int)$wip->inventory_cost_layer_id ||
                    !in_array((int)$originalCostRows->first()->id,$issueIds,true))
                    $this->fail('Original batch consumed another FIFO layer or unverified material source; all production inputs must be costed together, not partially.');
                $covered=$covered->plus((string)$originalCostRows->first()->quantity);
            }
            if(!$expected->isEqualTo($actual))
                $this->fail('Original production order issue movement quantities do not equal the actual source production material line issued quantities.');
        }
        if(!$covered->isEqualTo($quantity))
            $this->fail('Some source material was not included in the independently signed original FIFO production cost budget.');

        $snapshot=$batch->costs;
        if($snapshot->count()!==1 ||
            $snapshot->first()->currency_id!==null ||
            !BigDecimal::of((string)$snapshot->first()->unvalued_quantity)
                ->isEqualTo($quantity) ||
            ($snapshot->first()->material_cost!==null &&
                !BigDecimal::of((string)$snapshot->first()->material_cost)->isZero()) ||
            DB::table('production_overhead_allocations')
                ->where('production_batch_id',$batchId)->exists() ||
            DB::table('production_material_wastes as waste')
                ->join('production_order_materials as original',
                    'original.id','=','waste.production_order_material_id')
                ->where('original.production_order_id',$batch->production_order_id)->exists())
            $this->fail('Only a single-original-currency batch composed exclusively of the frozen unvalued source lot with NO original overhead or documented production waste is supported. Other material/overhead/waste costs require a combined manufacturing-cost plan.');

        $policy=$state['policy'];
        if(!in_array((int)$policy->material_inventory_ledger_id,[
                (int)$wip->source_inventory_ledger_id,
            ],true) ||
            (int)$policy->work_in_progress_ledger_id!==(int)$wip->target_wip_ledger_id ||
            !$this->mappedAccountIsClean($equityLedgerId,'equity') ||
            in_array($equityLedgerId,[
                (int)$policy->material_inventory_ledger_id,
                (int)$policy->work_in_progress_ledger_id,
                (int)$policy->finished_inventory_ledger_id,
                (int)$policy->cogs_ledger_id,
            ],true))
            $this->fail('Historical opening equity and source material/WIP/finished/cost accounts are not independent approved original-currency ledger postings.');

        if(DB::table('production_batch_cost_postings')
                ->where('production_batch_id',$batchId)->exists() ||
            DB::table('finance_historical_batch_capitalizations')
                ->where('production_batch_id',$batchId)->exists() ||
            DB::table('finance_historical_correction_cases')
                ->where('case_type','batch_uncapitalized')
                ->where('source_id',$batchId)
                ->whereIn('status',['submitted','approved'])->exists() ||
            DB::table('production_invoice_cogs_postings as cogs')
                ->join('sales_invoice_lines as sale',
                    'sale.id','=','cogs.sales_invoice_line_id')
                ->where('sale.production_batch_id',$batchId)->exists())
            $this->fail('Previously signed manufacturing/COGS plans or source journals exist for the same batch. A separate audited amendment or consolidation is necessary.');

        if(DB::table('sales_credit_note_lines')
                ->where('production_batch_id',$batchId)->exists() ||
            DB::table('sales_return_quarantines')
                ->where('production_batch_id',$batchId)->exists() ||
            DB::table('production_return_cost_postings as recovered')
                ->join('production_invoice_cogs_postings as sold',
                    'sold.id','=','recovered.production_invoice_cogs_posting_id')
                ->join('sales_invoice_lines as sale',
                    'sale.id','=','sold.sales_invoice_line_id')
                ->where('sale.production_batch_id',$batchId)->exists() ||
            DB::table('finance_historical_dispatch_dispositions as purpose')
                ->join('finished_good_dispatch_lines as dispatch',
                    'dispatch.finished_good_dispatch_id','=','purpose.finished_good_dispatch_id')
                ->where('dispatch.production_batch_id',$batchId)->exists())
            $this->fail('Existing customer credits, independent QC returns, non-sale or other earlier stock dispositions require the existing signed mixed-dispatch/return cost recovery instead of this limited executor.');

        $physical=DB::table('finished_good_dispatch_lines as line')
            ->join('finished_good_dispatches as dispatch',
                'dispatch.id','=','line.finished_good_dispatch_id')
            ->where('line.production_batch_id',$batchId)
            ->orderBy('dispatch.dispatch_date')->orderBy('line.id')
            ->select('line.*','dispatch.dispatch_date','dispatch.number as dispatch_number')
            ->get();
        $sales=DB::table('sales_invoice_lines')
            ->where('production_batch_id',$batchId)->get();
        if($physical->count()!==$sales->count())
            $this->fail('Every historical physical dispatch of this narrowly recovered batch must have precisely one genuine posted customer invoice, or there must be no dispatch or sale. Non-sale/mixed sources need a different executor.');
        $saleProofs=[];$saleLineIds=[];$units=0;
        foreach($physical as $row){
            if($row->dispatch_date<$finishedDate ||
                DB::table('finished_good_dispatch_lines')
                    ->where('finished_good_dispatch_id',$row->finished_good_dispatch_id)
                    ->count()!==1)
                $this->fail('A single original customer dispatch must follow genuine source QC release and have one unambiguous physical item/batch.');
            $sale=$sales->firstWhere('dispatch_id',$row->finished_good_dispatch_id);
            if(!$sale ||
                $sales->where('dispatch_id',$row->finished_good_dispatch_id)->count()!==1 ||
                (int)$row->finished_good_id!==(int)$batch->finished_good_id ||
                DB::table('sales_invoice_lines')
                    ->where('dispatch_id',$row->finished_good_dispatch_id)->count()!==1)
                $this->fail('An original physical dispatch has missing, duplicate or inconsistent real sale documents.');
            $invoice=DB::table('sales_invoices')
                ->where('id',$sale->sales_invoice_id)->first();
            if(!$invoice)$this->fail('Genuine historical customer sale header is missing.');
            $saleProofs[]=$this->invoiceSource($row,$sale,$invoice);
            $saleLineIds[]=(int)$sale->id;
            $units+=(int)$row->quantity_packages;
        }
        if($units>(int)$batch->accepted_packages ||
            $sales->pluck('id')->sort()->values()->all()!==
                collect($saleLineIds)->sort()->values()->all())
            $this->fail('Original customer sale package/line source count exceeds actual accepted released stock, or a sale is not backed by a unique source physical dispatch.');

        $openingDate=$proof['original']['layer']->received_on->toDateString();
        if($openingDate>$date || $date>$finishedDate ||
            $finishedDate>now()->toDateString() ||
            collect($physical)->contains(
                fn($d)=>(string)$d->dispatch_date>now()->toDateString()
            ))
            $this->fail('An original physical opening, material issue, QC finished receipt or genuine sale occurs outside historical source chronology.');

        $fingerprint=hash('sha256',json_encode([
            'review'=>$wip->id,'original_wip_signature'=>$wip->source_fingerprint,
            'ledger_policy'=>[
                $policy->id,$policy->currency_id,
                $policy->material_inventory_ledger_id,
                $policy->work_in_progress_ledger_id,
                $policy->finished_inventory_ledger_id,$policy->cogs_ledger_id
            ],
            'opening_equity_account'=>$equityLedgerId,
            'source_batch'=>$proof['batches'][$batchId],
            'current_quality_check_records'=>$batch->checks->toArray(),
            'source_physical_and_sale_evidence'=>$saleProofs,
            'source_initial_costs'=>$snapshot->toArray(),
            'opening_date'=>$openingDate,'material_issue_date'=>$date,
            'final_QC_date'=>$finishedDate,'original_finished_receipt'=>$receipt->first(),
        ],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
        return [
            'verified'=>$verified,'wip'=>$wip,'source'=>$state,
            'batch'=>$batch,'policy'=>$policy,
            'opening_date'=>$openingDate,'issue_date'=>$date,
            'finished_date'=>$finishedDate,'equity_id'=>$equityLedgerId,
            'currency_id'=>(int)$wip->currency_id,
            'original_cost'=>(string)$this->money((string)$wip->original_lot_cost),
            'material_cost'=>(string)$this->money((string)$wip->consumed_production_cost),
            'remaining_raw_cost'=>(string)BigDecimal::of((string)$wip->remaining_inventory_cost)
                ->toScale(4),
            'sale_line_ids'=>$saleLineIds,'sales'=>$saleProofs,
            'source_issue_ids'=>$issueIds,'signature'=>$fingerprint
        ];
    }

    private function independence(array $source,int $actorId,?object $plan=null):void
    {
        $actors=$source['verified']['actors'];
        if($plan){
            $actors[]=(int)$plan->submitted_by;
            $actors[]=(int)($plan->approved_by??0);
        }
        if(in_array($actorId,$actors,true))
            $this->fail('The historic source opening registrar, actual production material issuer, provenance proposer/reviewer, source-cost approver, historical batch proposer, independent reviewer and final financial executor must be separate people.');
    }

    private function assertUnchanged(object $plan,array $source):void
    {
        if((int)$plan->inventory_cost_layer_id!==
                (int)$source['wip']->inventory_cost_layer_id ||
            (int)$plan->production_batch_id!==(int)$source['batch']->id ||
            (int)$plan->currency_id!==$source['currency_id'] ||
            (int)$plan->opening_equity_ledger_id!==$source['equity_id'] ||
            $plan->original_opening_date!==$source['opening_date'] ||
            $plan->original_issue_date!==$source['issue_date'] ||
            $plan->original_finished_date!==$source['finished_date'] ||
            !BigDecimal::of((string)$plan->original_lot_cost)
                ->isEqualTo($source['original_cost']) ||
            !BigDecimal::of((string)$plan->original_material_cost)
                ->isEqualTo($source['material_cost']) ||
            !BigDecimal::of((string)$plan->original_remaining_raw_cost)
                ->isEqualTo($source['remaining_raw_cost']) ||
            (int)$plan->source_issue_lines!==
                count($source['source_issue_ids']) ||
            (int)$plan->source_invoice_lines!==
                count($source['sale_line_ids']) ||
            !hash_equals((string)$plan->source_fingerprint,
                $source['signature']))
            $this->fail('Source WIP, original FIFO lot, equity/asset policy, accepted QC batch, real invoice revenues, original dated quantities or amount changed after independently signed recovery proposal.');
    }

    public function submit(int $sourceReviewId,array $data,int $actorId):object
    {
        return DB::transaction(function()use($sourceReviewId,$data,$actorId){
            $wip=DB::table('finance_historical_fifo_production_cost_reviews')
                ->where('id',$sourceReviewId)->first();
            abort_unless($wip,404);
            InventoryMaterial::whereKey(
                DB::table('inventory_cost_layers')->where('id',$wip->inventory_cost_layer_id)
                    ->value('inventory_material_id')
            )->lockForUpdate()->firstOrFail();
            InventoryCostLayer::whereKey($wip->inventory_cost_layer_id)
                ->lockForUpdate()->firstOrFail();
            $source=$this->source($sourceReviewId,(int)$data['opening_equity_ledger_id']);
            ProductionBatch::whereKey($source['batch']->id)->lockForUpdate()->firstOrFail();
            $this->independence($source,$actorId);
            if(DB::table('finance_historical_fifo_production_recoveries')
                ->where('production_batch_id',$source['batch']->id)->exists())
                $this->fail('This genuine batch already has one signed original-source recovery; duplicate proposals require independent source-level amendment.');
            $id=DB::table('finance_historical_fifo_production_recoveries')->insertGetId([
                'finance_historical_fifo_production_cost_review_id'=>$sourceReviewId,
                'inventory_cost_layer_id'=>$source['wip']->inventory_cost_layer_id,
                'production_batch_id'=>$source['batch']->id,
                'currency_id'=>$source['currency_id'],
                'opening_equity_ledger_id'=>$source['equity_id'],
                'original_opening_date'=>$source['opening_date'],
                'original_issue_date'=>$source['issue_date'],
                'original_finished_date'=>$source['finished_date'],
                'original_lot_cost'=>$source['original_cost'],
                'original_material_cost'=>$source['material_cost'],
                'original_remaining_raw_cost'=>$source['remaining_raw_cost'],
                'source_issue_lines'=>count($source['source_issue_ids']),
                'source_invoice_lines'=>count($source['sale_line_ids']),
                'source_fingerprint'=>$source['signature'],
                'status'=>'submitted',
                'approval_reference'=>trim($data['approval_reference']),
                'reconciliation_notes'=>trim($data['reconciliation_notes']),
                'submitted_by'=>$actorId,'created_at'=>now(),'updated_at'=>now()
            ]);
            ActivityLog::record('finance.historical_production_fifo_recovery.submitted',
                'finance_historical_fifo_production_recovery',$id,
                'Authentic one-lot, one-batch original raw inventory/WIP/finished/sale-COGS recovery proposed for independent approval; no journal, stock or immutable source changed.');
            return DB::table('finance_historical_fifo_production_recoveries')
                ->where('id',$id)->first();
        });
    }

    public function approve(int $id,array $data,int $actorId):object
    {
        return DB::transaction(function()use($id,$data,$actorId){
            $record=DB::table('finance_historical_fifo_production_recoveries')
                ->where('id',$id)->lockForUpdate()->first();
            abort_unless($record,404);
            if($record->status!=='submitted' || !$record->submitted_by)
                $this->fail('Only an unposted original production FIFO recovery can be independently approved or rejected.');
            $source=$this->source((int)$record->finance_historical_fifo_production_cost_review_id,
                (int)$record->opening_equity_ledger_id);
            $this->assertUnchanged($record,$source);
            $this->independence($source,$actorId,$record);
            DB::table('finance_historical_fifo_production_recoveries')
                ->where('id',$id)->update([
                    'status'=>$data['decision']==='approve'?'approved':'rejected',
                    'review_reference'=>trim($data['review_reference']),
                    'review_notes'=>trim($data['review_notes']),
                    'approved_by'=>$actorId,'approved_at'=>now(),'updated_at'=>now()
                ]);
            ActivityLog::record('finance.historical_production_fifo_recovery.'.
                ($data['decision']==='approve'?'approved':'rejected'),
                'finance_historical_fifo_production_recovery',$id,
                'Independent original source FIFO, complete production cost, QC batch and authentic customer sale evidence reviewed without journal posting.');
            return DB::table('finance_historical_fifo_production_recoveries')
                ->where('id',$id)->first();
        });
    }

    private function journal(string $date,int $currencyId,int $debitId,int $creditId,
        string $amount,string $reference,string $description,int $actorId):JournalEntry
    {
        if($debitId===$creditId || !$this->money($amount)->isPositive())
            $this->fail('Original currency WIP journal requires two distinct eligible active GL accounts and a strictly positive balanced amount.');
        DocumentSequence::firstOrCreate([
            'document_type'=>'historical_production_fifo',
        ],[
            'prefix'=>'HPF-','next_number'=>1,'padding'=>7,'is_active'=>true,
        ]);
        $entry=JournalEntry::create([
            'number'=>DocumentSequence::issue('historical_production_fifo'),
            'entry_date'=>$date,'reference'=>substr($reference,0,100),
            'description'=>$description,'status'=>'draft',
            'currency_id'=>$currencyId,'created_by'=>$actorId,
        ]);
        $entry->lines()->createMany([
            ['ledger_account_id'=>$debitId,'debit'=>$amount,'credit'=>'0',
                'memo'=>substr($description,0,255)],
            ['ledger_account_id'=>$creditId,'debit'=>'0','credit'=>$amount,
                'memo'=>substr($description,0,255)],
        ]);
        app(JournalPostingService::class)->post($entry,$actorId);
        return $entry;
    }

    /** Exact, two-line, independently posted original-currency journal. */
    private function journalValid(?int $id,int $debit,int $credit,
        string $amount,string $date,int $currencyId):bool
    {
        if(!$id || $debit===$credit)return false;
        $entry=DB::table('journal_entries')->where('id',$id)->first();
        if(!$entry || $entry->status!=='posted' || $entry->reversal_of_id ||
            (string)$entry->entry_date!==$date ||
            (int)$entry->currency_id!==$currencyId ||
            DB::table('journal_entries')->where('reversal_of_id',$id)->exists())
            return false;
        $lines=DB::table('journal_lines')->where('journal_entry_id',$id)->get();
        if($lines->count()!==2)return false;
        $dr=BigDecimal::zero();$cr=BigDecimal::zero();
        foreach($lines as $line){
            if((int)$line->ledger_account_id===$debit)
                $dr=$dr->plus((string)$line->debit)
                    ->minus((string)$line->credit);
            elseif((int)$line->ledger_account_id===$credit)
                $cr=$cr->plus((string)$line->credit)
                    ->minus((string)$line->debit);
            else return false;
        }
        return $dr->isEqualTo($amount) && $cr->isEqualTo($amount);
    }

    /**
     * Read-only finance close verification AFTER source production rows
     * became cost-valued through a separately posted accounting chain.
     * Never call verifiedApproved/source() after posting: those correctly
     * reject changed immutable opening-cost state.
     */
    public function auditPosted(int $recoveryId):bool
    {
        $p=DB::table('finance_historical_fifo_production_recoveries')
            ->where('id',$recoveryId)->first();
        if(!$p || $p->status!=='posted' || !$p->executed_by ||
            !$p->submitted_by || !$p->approved_by ||
            !$p->opening_journal_id || !$p->wip_journal_id ||
            !$p->finished_journal_id || !$p->production_batch_cost_posting_id)
            return false;
        $review=DB::table('finance_historical_fifo_production_cost_reviews')
            ->where('id',$p->finance_historical_fifo_production_cost_review_id)->first();
        $provenance=$review?DB::table('finance_legacy_fifo_source_reviews')
            ->where('id',$review->finance_legacy_fifo_source_review_id)->first():null;
        $layer=DB::table('inventory_cost_layers')
            ->where('id',$p->inventory_cost_layer_id)->first();
        $opening=$layer?DB::table('inventory_stock_movements')
            ->where('id',$layer->source_id)->first():null;
        $batch=DB::table('production_batches')
            ->where('id',$p->production_batch_id)->first();
        $receipt=$batch?DB::table('finished_good_movements')
            ->where('production_batch_id',$batch->id)
            ->where('type','production_receipt')->get():collect();
        $posted=DB::table('production_batch_cost_postings')
            ->where('id',$p->production_batch_cost_posting_id)->first();
        $policy=DB::table('manufacturing_cost_ledger_policies')
            ->where('id',$posted?->manufacturing_cost_ledger_policy_id)->first();
        $currency=DB::table('currencies')->where('id',$p->currency_id)->first();
        $equity=DB::table('ledger_accounts')
            ->where('id',$p->opening_equity_ledger_id)->first();
        if(!$review || !$provenance || !$layer || !$opening || !$batch ||
            $receipt->count()!==1 || !$posted || !$policy || !$currency ||
            !$equity || !$equity->is_active || $equity->is_group ||
            $equity->type!=='equity' ||
            $review->status!=='posted' ||
            $provenance->status!=='approved' ||
            $provenance->review_type!=='consumed_provenance' ||
            $batch->status!=='released' ||
            $opening->type!=='opening' || $opening->direction!=='in' ||
            $layer->source_type!=='inventory_stock_movement' ||
            (int)$layer->source_id!==(int)$opening->id ||
            (int)$p->inventory_cost_layer_id!==(int)$review->inventory_cost_layer_id ||
            (int)$p->production_batch_id!==(int)$posted->production_batch_id ||
            (int)$posted->manufacturing_cost_ledger_policy_id!==(int)$policy->id ||
            (int)$p->currency_id!==(int)$posted->currency_id ||
            (int)$p->currency_id!==(int)$policy->currency_id ||
            (int)$p->currency_id!==(int)$review->currency_id ||
            (int)$review->source_inventory_ledger_id!==
                (int)$policy->material_inventory_ledger_id ||
            (int)$review->target_wip_ledger_id!==
                (int)$policy->work_in_progress_ledger_id ||
            (int)$receipt->first()->quantity_packages!==(int)$batch->accepted_packages ||
            (int)$posted->accepted_packages!==(int)$batch->accepted_packages ||
            (string)$opening->occurred_on!==(string)$p->original_opening_date ||
            (string)$layer->received_on!==(string)$p->original_opening_date ||
            (string)$posted->accounting_date!==(string)$p->original_finished_date ||
            (string)$batch->released_at!==($p->original_finished_date.' 00:00:00') &&
                substr((string)$batch->released_at,0,10)!==(string)$p->original_finished_date ||
            (string)$layer->currency_code!==(string)$currency->code ||
            (int)$layer->currency_id!==(int)$p->currency_id ||
            !BigDecimal::of((string)$layer->original_cost)
                ->isEqualTo((string)$p->original_lot_cost) ||
            !BigDecimal::of((string)$review->original_lot_cost)
                ->isEqualTo((string)$p->original_lot_cost) ||
            !BigDecimal::of((string)$review->remaining_inventory_cost)
                ->isEqualTo((string)$p->original_remaining_raw_cost) ||
            !BigDecimal::of((string)$review->consumed_production_cost)
                ->isEqualTo((string)$p->original_material_cost) ||
            !BigDecimal::of((string)$p->original_lot_cost)
                ->isEqualTo(BigDecimal::of((string)$p->original_material_cost)
                    ->plus((string)$p->original_remaining_raw_cost)) ||
            !BigDecimal::of((string)$posted->material_amount)
                ->isEqualTo((string)$p->original_material_cost) ||
            !BigDecimal::of((string)$posted->total_amount)
                ->isEqualTo((string)$p->original_material_cost) ||
            !BigDecimal::of((string)$posted->overhead_amount)->isZero() ||
            !BigDecimal::of((string)$posted->abnormal_waste_amount)->isZero() ||
            count(array_unique([
                (int)$p->submitted_by,(int)$p->approved_by,(int)$p->executed_by,
                (int)$review->submitted_by,(int)$review->approved_by,
                (int)$provenance->requested_by,(int)$provenance->reviewed_by,
                (int)$opening->created_by
            ]))!==8)
            return false;

        if(!$this->journalValid((int)$p->opening_journal_id,
            (int)$policy->material_inventory_ledger_id,
            (int)$p->opening_equity_ledger_id,(string)$p->original_lot_cost,
            (string)$p->original_opening_date,(int)$p->currency_id) ||
            !$this->journalValid((int)$p->wip_journal_id,
                (int)$policy->work_in_progress_ledger_id,
                (int)$policy->material_inventory_ledger_id,
                (string)$p->original_material_cost,
                (string)$p->original_issue_date,(int)$p->currency_id) ||
            !$this->journalValid((int)$p->finished_journal_id,
                (int)$policy->finished_inventory_ledger_id,
                (int)$policy->work_in_progress_ledger_id,
                (string)$p->original_material_cost,
                (string)$p->original_finished_date,(int)$p->currency_id) ||
            (int)$posted->issue_journal_id!==(int)$p->wip_journal_id ||
            (int)$posted->capitalization_journal_id!==(int)$p->finished_journal_id)
            return false;

        $issued=DB::table('finance_historical_fifo_production_cost_lines')
            ->where('finance_historical_fifo_production_cost_review_id',$review->id)->get();
        if($issued->count()!==(int)$p->source_issue_lines)return false;
        $amount=BigDecimal::zero();$physicalQty=BigDecimal::zero();
        foreach($issued as $line){
            $cost=DB::table('inventory_cost_consumptions')
                ->where('id',$line->inventory_cost_consumption_id)->first();
            $stock=$cost?DB::table('inventory_stock_movements')
                ->where('id',$cost->inventory_stock_movement_id)->first():null;
            if(!$cost || !$stock ||
                (int)$line->production_batch_id!==(int)$batch->id ||
                (int)$cost->inventory_cost_layer_id!==(int)$layer->id ||
                (int)$line->inventory_stock_movement_id!==(int)$stock->id ||
                (int)$stock->reference_id!==(int)$line->production_order_material_id ||
                $stock->reference_type!=='production_order_material' ||
                $stock->type!=='issue' || $stock->direction!=='out' ||
                (string)$stock->occurred_on!==(string)$p->original_issue_date ||
                (string)$line->original_material_issue_date!==(string)$p->original_issue_date ||
                (int)$line->historical_wip_journal_id!==(int)$p->wip_journal_id ||
                $cost->is_valued || $cost->amount!==null ||
                !BigDecimal::of((string)$cost->quantity)
                    ->isEqualTo((string)$line->quantity))
                return false;
            $physicalQty=$physicalQty->plus((string)$line->quantity);
            $amount=$amount->plus((string)$line->proposed_original_wip_cost);
        }
        if(!$amount->isEqualTo((string)$p->original_material_cost) ||
            !BigDecimal::of((string)$provenance->consumed_quantity)
                ->isEqualTo($physicalQty) ||
            !BigDecimal::of((string)$layer->original_quantity)
                ->isEqualTo($physicalQty->plus((string)$layer->remaining_quantity)))
            return false;

        $sales=DB::table('sales_invoice_lines')
            ->where('production_batch_id',$batch->id)->get();
        $costed=DB::table('production_invoice_cogs_postings as cost')
            ->join('sales_invoice_lines as sale',
                'sale.id','=','cost.sales_invoice_line_id')
            ->where('sale.production_batch_id',$batch->id)
            ->get(['cost.*']);
        if($sales->count()!==(int)$p->source_invoice_lines ||
            $costed->count()!==$sales->count())return false;
        $totalCost=BigDecimal::zero();$units=0;
        foreach($sales as $sale){
            $entry=$costed->firstWhere('sales_invoice_line_id',$sale->id);
            $invoice=DB::table('sales_invoices')
                ->where('id',$sale->sales_invoice_id)->first();
            $physical=DB::table('finished_good_dispatch_lines as dispatch')
                ->join('finished_good_dispatches as head',
                    'head.id','=','dispatch.finished_good_dispatch_id')
                ->where('dispatch.finished_good_dispatch_id',$sale->dispatch_id)
                ->select('dispatch.*','head.dispatch_date')->first();
            if(!$entry || !$invoice || !$physical ||
                (int)$entry->production_batch_cost_posting_id!==(int)$posted->id ||
                (int)$entry->quantity_packages!==(int)$sale->quantity_packages ||
                (int)$physical->production_batch_id!==(int)$batch->id ||
                (int)$physical->quantity_packages!==(int)$sale->quantity_packages ||
                !$this->journalValid((int)$entry->journal_entry_id,
                    (int)$policy->cogs_ledger_id,
                    (int)$policy->finished_inventory_ledger_id,
                    (string)$entry->amount,(string)$invoice->invoice_date,
                    (int)$p->currency_id))
                return false;
            try{$this->invoiceSource($physical,$sale,$invoice);}
            catch(ValidationException $e){return false;}
            $totalCost=$totalCost->plus((string)$entry->amount);
            $units+=(int)$sale->quantity_packages;
        }
        return $units<=(int)$batch->accepted_packages &&
            $totalCost->isLessThanOrEqualTo((string)$p->original_material_cost);
    }

    public function post(int $id,int $actorId):object
    {
        return DB::transaction(function()use($id,$actorId){
            $initial=DB::table('finance_historical_fifo_production_recoveries')
                ->where('id',$id)->first();
            abort_unless($initial,404);
            InventoryMaterial::whereKey(
                DB::table('inventory_cost_layers')
                    ->where('id',$initial->inventory_cost_layer_id)
                    ->value('inventory_material_id')
            )->lockForUpdate()->firstOrFail();
            InventoryCostLayer::whereKey($initial->inventory_cost_layer_id)
                ->lockForUpdate()->firstOrFail();
            ProductionBatch::whereKey($initial->production_batch_id)
                ->lockForUpdate()->firstOrFail();
            $plan=DB::table('finance_historical_fifo_production_recoveries')
                ->where('id',$id)->lockForUpdate()->first();
            if(!$plan || $plan->status!=='approved' ||
                !$plan->approved_by || $plan->opening_journal_id ||
                $plan->wip_journal_id || $plan->finished_journal_id ||
                $plan->production_batch_cost_posting_id)
                $this->fail('Only a separately approved original-cost production recovery with no previous GL postings can execute.');
            $source=$this->source((int)$plan->finance_historical_fifo_production_cost_review_id,
                (int)$plan->opening_equity_ledger_id);
            $this->assertUnchanged($plan,$source);
            $this->independence($source,$actorId,$plan);
            $gate=app(FinancePeriodClosingService::class);
            $gate->holdPostingGate();
            foreach(array_merge([
                $source['opening_date'],$source['issue_date'],
                $source['finished_date'],
            ],array_map(fn($invoice)=>$invoice['invoice'][3],$source['sales'])) as $date){
                $gate->assertOpenDate((string)$date);
            }
            app(FinishedGoodLotControlService::class)
                ->assertSourceLotsClear($source['batch']);
            app(FinishedBatchQualityGate::class)
                ->assertApproved($source['batch']);
            $policy=$source['policy'];
            $currency=$source['currency_id'];
            $opening=$this->journal($source['opening_date'],$currency,
                (int)$policy->material_inventory_ledger_id,
                $source['equity_id'],$source['original_cost'],
                'HPF-OPEN-'.$source['wip']->inventory_cost_layer_id,
                'Documented original opening FIFO asset / opening equity, original physical source',
                $actorId);
            $issue=$this->journal($source['issue_date'],$currency,
                (int)$policy->work_in_progress_ledger_id,
                (int)$policy->material_inventory_ledger_id,
                $source['material_cost'],
                'HPF-WIP-'.$source['batch']->id,
                'Original physical source FIFO raw material issued to documented production WIP',
                $actorId);
            $finished=$this->journal($source['finished_date'],$currency,
                (int)$policy->finished_inventory_ledger_id,
                (int)$policy->work_in_progress_ledger_id,
                $source['material_cost'],
                'HPF-FG-'.$source['batch']->id,
                'Originally QC-approved production finished-goods inventory capitalization',
                $actorId);
            $costId=DB::table('production_batch_cost_postings')->insertGetId([
                'production_batch_id'=>$source['batch']->id,
                'manufacturing_cost_ledger_policy_id'=>$policy->id,
                'currency_id'=>$currency,
                'accepted_packages'=>$source['batch']->accepted_packages,
                'material_amount'=>$source['material_cost'],
                'abnormal_waste_amount'=>'0.0000',
                'overhead_amount'=>'0.0000',
                'total_amount'=>$source['material_cost'],
                'accounting_date'=>$source['finished_date'],
                'issue_journal_id'=>$issue->id,
                'capitalization_journal_id'=>$finished->id,
                'approval_reference'=>$plan->approval_reference,
                'reconciliation_notes'=>$plan->reconciliation_notes,
                'approved_by'=>$actorId,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $saleCount=0;
            foreach($source['sale_line_ids'] as $lineId){
                $sale=SalesInvoiceLine::findOrFail($lineId);
                $cogs=app(FinishedGoodsCostPostingService::class)
                    ->postInvoiceLine($sale,$actorId);
                if(!$cogs || (int)$cogs->sales_invoice_line_id!==(int)$lineId ||
                    (int)$cogs->production_batch_cost_posting_id!==$costId)
                    $this->fail('A genuine source customer invoice failed original-date and source-currency batch COGS posting. Entire raw/WIP/FG reconstruction must roll back.');
                $saleCount++;
            }
            DB::table('inventory_cost_layers')
                ->where('id',$source['wip']->inventory_cost_layer_id)->update([
                    'original_cost'=>(string)BigDecimal::of($source['original_cost'])
                        ->toScale(10),
                    'currency_id'=>$currency,
                    'currency_code'=>$source['source']['currency']->code,
                    'updated_at'=>now(),
                ]);
            DB::table('finance_historical_fifo_production_cost_lines')
                ->where('finance_historical_fifo_production_cost_review_id',
                    $source['wip']->id)->update([
                    'historical_wip_journal_id'=>$issue->id,'updated_at'=>now(),
                ]);
            DB::table('finance_historical_fifo_production_cost_reviews')
                ->where('id',$source['wip']->id)->update([
                    'status'=>'posted','updated_at'=>now(),
                ]);
            DB::table('finance_historical_fifo_production_recoveries')
                ->where('id',$id)->update([
                    'status'=>'posted','opening_journal_id'=>$opening->id,
                    'wip_journal_id'=>$issue->id,
                    'finished_journal_id'=>$finished->id,
                    'production_batch_cost_posting_id'=>$costId,
                    'executed_by'=>$actorId,'executed_at'=>now(),
                    'updated_at'=>now(),
                ]);
            ActivityLog::record('finance.historical_production_fifo_recovery.posted',
                'finance_historical_fifo_production_recovery',$id,
                'Original FIFO opening/equity, source production WIP, QC finished inventory and all authentic historic invoice COGS posted together. Physical stock, sale revenue, credits and frozen production cost snapshots unchanged.',
                ['original_fifo_lot'=>$source['wip']->inventory_cost_layer_id,
                    'batch_id'=>$source['batch']->id,'cost_posting_id'=>$costId,
                    'opening_journal_id'=>$opening->id,'wip_journal_id'=>$issue->id,
                    'finished_journal_id'=>$finished->id,
                    'genuine_invoice_cogs_count'=>$saleCount]);
            return DB::table('finance_historical_fifo_production_recoveries')
                ->where('id',$id)->first();
        });
    }
}

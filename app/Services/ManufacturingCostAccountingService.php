<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\JournalEntry;
use App\Models\ProductionBatch;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Source-cost-backed GL capitalization of a released batch in exactly one
 * original currency. Posts WIP expense transfers and finished inventory
 * capitalization together, never an automatic currency translation.
 */
class ManufacturingCostAccountingService
{
    public function __construct(
        private readonly ManufacturingLedgerPolicyService $policies,
        private readonly JournalPostingService $posting,
    ) {}

    private function reject(string $message):never
    {
        throw ValidationException::withMessages(['manufacturing_cost'=>$message]);
    }

    private function money(BigDecimal $amount):string
    {
        if(!$amount->isPositive() || $amount->isGreaterThan('9999999999999999.9999')){
            $this->reject('The original-currency cost must be positive and within the 20,4 GL journal limit.');
        }
        return (string)$amount->toScale(4,RoundingMode::HalfUp);
    }

    private function journal(string $date,int $currencyId,string $reference,string $text,
        array $debits,array $credits,int $actorId):JournalEntry
    {
        $debitTotal=BigDecimal::zero();$creditTotal=BigDecimal::zero();
        foreach($debits as $row)$debitTotal=$debitTotal->plus((string)$row['amount']);
        foreach($credits as $row)$creditTotal=$creditTotal->plus((string)$row['amount']);
        if(!$debitTotal->isEqualTo($creditTotal)||!$debitTotal->isPositive())
            $this->reject('Manufacturing reclassification journal debits and credits must balance exactly.');
        DocumentSequence::firstOrCreate(['document_type'=>'manufacturing_cost_journal'],[
            'prefix'=>'MCJ-','next_number'=>1,'padding'=>7,'is_active'=>true,
        ]);
        $entry=JournalEntry::create([
            'number'=>DocumentSequence::issue('manufacturing_cost_journal'),
            'entry_date'=>$date,'reference'=>substr($reference,0,100),
            'description'=>$text,'status'=>'draft',
            'currency_id'=>$currencyId,'created_by'=>$actorId,
        ]);
        foreach($debits as $row){
            $entry->lines()->create([
                'ledger_account_id'=>$row['ledger'],'debit'=>$row['amount'],
                'credit'=>'0','memo'=>substr($row['memo']??$text,0,255),
            ]);
        }
        foreach($credits as $row){
            $entry->lines()->create([
                'ledger_account_id'=>$row['ledger'],'debit'=>'0',
                'credit'=>$row['amount'],'memo'=>substr($row['memo']??$text,0,255),
            ]);
        }
        $this->posting->post($entry,$actorId);
        return $entry;
    }

    /**
     * Require each consumed FIFO lot to have a posted supplier invoice in
     * the selected raw-material asset ledger. Legacy/opening and landed-cost
     * layers without independently posted capitalization cannot be silently
     * treated as GL inventory. Exact source movements must still equal the
     * frozen production batch cost snapshot.
     */
    private function assertMaterialSources(ProductionBatch $batch,object $policy,
        string $date,string $physicalReceiptDate,string $expectedAmount):void
    {
        $lineIds=DB::table('production_order_materials')
            ->where('production_order_id',$batch->production_order_id)->pluck('id');
        if($lineIds->isEmpty())$this->reject('No production material issue lines belong to this batch.');
        $movements=DB::table('inventory_stock_movements')
            ->where('reference_type','production_order_material')
            ->whereIn('reference_id',$lineIds)->get();
        if($movements->isEmpty())$this->reject('No source material stock issues exist for this batch.');
        $checkedLots=[];$total=BigDecimal::zero();
        foreach($movements as $movement){
            if((string)$movement->occurred_on>$date ||
                (string)$movement->occurred_on>$physicalReceiptDate){
                $this->reject('An input material issue cannot post after the completed finished-goods receipt or the batch capitalization date.');
            }
            $rows=DB::table('inventory_cost_consumptions')
                ->where('inventory_stock_movement_id',$movement->id)->get();
            $covered=BigDecimal::zero();
            foreach($rows as $consumed){
                $covered=$covered->plus((string)$consumed->quantity);
                if(!$consumed->is_valued || !$consumed->inventory_cost_layer_id ||
                    (int)$consumed->currency_id!==(int)$policy->currency_id ||
                    $consumed->amount===null){
                    $this->reject('A production material quantity has unknown cost or a different original currency.');
                }
                $layer=DB::table('inventory_cost_layers')
                    ->where('id',$consumed->inventory_cost_layer_id)->first();
                if(!$layer || (int)$layer->inventory_material_id!==(int)$movement->inventory_material_id ||
                    !in_array($layer->source_type,['goods_receipt_line','inventory_stock_movement'],true)){
                    $this->reject('Unknown FIFO source layer or material cannot enter source-backed WIP capitalization.');
                }
                if((string)$layer->received_on>$date ||
                    (string)$layer->received_on>(string)$movement->occurred_on){
                    $this->reject('An input FIFO lot was received after the recorded material issue or batch capitalization date.');
                }
                if(!isset($checkedLots[$layer->id])){
                    $checkedLots[$layer->id]=true;
                    if($layer->source_type==='inventory_stock_movement'){
                        $this->assertApprovedOpeningSource(
                            $layer,$policy,$date,(string)$movement->occurred_on
                        );
                    }else{
                    // The FIFO consumption already includes any recorded
                    // freight/customs allocations. Their original expense
                    // must first be reclassified into the exact source
                    // inventory ledger without creating a second receipt.
                    app(GoodsReceiptLandedCostPostingService::class)->assertLotCostPosted(
                        (int)$layer->source_id,$date,
                        (int)$policy->material_inventory_ledger_id
                    );
                    $receiptLine=DB::table('goods_receipt_lines')->where('id',$layer->source_id)->first();
                    if(!$receiptLine){
                        $this->reject('A consumed source receipt line is missing.');
                    }
                    if($layer->original_cost===null ||
                        $receiptLine->line_subtotal_snapshot===null ||
                        BigDecimal::of((string)$layer->original_cost)->isNegative()){
                        $this->reject('Original FIFO receipt has no verified nonnegative source purchase valuation.');
                    }
                    if(BigDecimal::of((string)$layer->original_quantity)
                        ->compareTo((string)$receiptLine->quantity)!==0 ||
                        BigDecimal::of((string)$layer->original_cost)
                        ->compareTo((string)$receiptLine->line_subtotal_snapshot)!==0){
                        $this->reject('FIFO lot original quantity or cost differs from its immutable posted GRN source snapshot.');
                    }
                    $invoiced=BigDecimal::zero();
                    $invoicedValue=BigDecimal::zero();
                    $invoices=DB::table('matched_supplier_invoice_lines as item')
                        ->join('matched_supplier_invoices as invoice',
                            'invoice.id','=','item.matched_supplier_invoice_id')
                        ->join('journal_entries as journal',
                            'journal.id','=','invoice.journal_entry_id')
                        ->leftJoin('journal_entries as reversal',
                            'reversal.reversal_of_id','=','journal.id')
                        ->where('item.goods_receipt_line_id',$layer->source_id)
                        ->select('item.quantity','item.line_value',
                            'invoice.inventory_ledger_id','invoice.total as invoice_total',
                            'invoice.currency_id','invoice.journal_entry_id',
                            'journal.status','journal.entry_date',
                            'journal.reversal_of_id','reversal.id as reversal_id')->get();
                    foreach($invoices as $invoice){
                        if((int)$invoice->inventory_ledger_id!==(int)$policy->material_inventory_ledger_id ||
                            (int)$invoice->currency_id!==(int)$policy->currency_id ||
                            $invoice->status!=='posted' ||
                            $invoice->reversal_of_id!==null || $invoice->reversal_id!==null ||
                            (string)$invoice->entry_date>$date){
                            $this->reject('Consumed supplier receipt has unposted, reversed, different-ledger or later-dated asset invoicing. Reconcile source books first.');
                        }
                        $invoiceDebits=DB::table('journal_lines')
                            ->where('journal_entry_id',$invoice->journal_entry_id)
                            ->where('ledger_account_id',$policy->material_inventory_ledger_id)
                            ->get(['debit','credit']);
                        if($invoiceDebits->isEmpty()){
                            $this->reject('Supplier invoice has no matching posted inventory asset journal debit.');
                        }
                        $debit=BigDecimal::zero();
                        foreach($invoiceDebits as $glLine){
                            $debit=$debit->plus((string)$glLine->debit)
                                ->minus((string)$glLine->credit);
                        }
                        if(!$debit->isEqualTo((string)$invoice->invoice_total)){
                            $this->reject('Supplier invoice posted inventory asset debit differs from the original matched payable value.');
                        }
                        $invoiced=$invoiced->plus((string)$invoice->quantity);
                        $invoicedValue=$invoicedValue->plus((string)$invoice->line_value);
                    }
                    if(!$invoiced->isEqualTo((string)$receiptLine->quantity) ||
                        !$invoicedValue->isEqualTo((string)$layer->original_cost)){
                        $this->reject('Source supplier invoices do not exactly reconcile the original receipt quantity and FIFO cost. Review source journal postings, partial invoices and supplier returns.');
                    }
                    }
                }
                $total=$total->plus((string)$consumed->amount);
            }
            if(!$covered->isEqualTo((string)$movement->quantity)){
                $this->reject('Posted consumption quantities do not equal the production material issue movement.');
            }
        }
        if(!$total->isEqualTo($expectedAmount)){
            $this->reject('Frozen batch material cost does not match the sum of original immutable FIFO issues.');
        }
    }

    /**
     * An old opening source is costable only after a separately approved
     * physical-stock valuation created the original raw inventory asset
     * debit and opening-equity credit. Old manual or unvalued lots have no
     * automatic cost evidence and cannot pass this gate.
     */
    private function assertApprovedOpeningSource(object $layer,object $policy,
        string $capitalizationDate,string $materialIssueDate):void
    {
        $backfill=DB::table('finance_historical_fifo_production_recoveries')
            ->where('inventory_cost_layer_id',$layer->id)
            ->where('status','posted')->first();
        if($backfill){
            if((int)$backfill->currency_id!==(int)$policy->currency_id ||
                $backfill->original_opening_date>$materialIssueDate ||
                $backfill->original_opening_date>$capitalizationDate ||
                !app(HistoricalProductionFifoRecoveryService::class)
                    ->auditPosted((int)$backfill->id))
                $this->reject('The original opening FIFO material inventory/equity was historically recognized through a complete source-linked raw/WIP/FG/COGS chain, but that original source or posted journal is invalid, later dated or currency mismatched.');
            return;
        }
        $proof=DB::table('finance_opening_fifo_valuations as source')
            ->join('finance_historical_correction_cases as review',
                'review.id','=','source.finance_historical_correction_case_id')
            ->join('journal_entries as journal',
                'journal.id','=','source.journal_entry_id')
            ->where('source.inventory_cost_layer_id',$layer->id)
            ->select('source.*','review.status as case_status',
                'review.case_type','review.requested_by','review.reviewed_by',
                'journal.status as journal_status',
                'journal.entry_date as journal_date',
                'journal.currency_id as journal_currency_id',
                'journal.reversal_of_id as journal_reversal_id')->first();
        $opening=DB::table('inventory_stock_movements')
            ->where('id',$layer->source_id)->first();
        if(!$proof || !$opening || $opening->type!=='opening' ||
            $opening->direction!=='in' ||
            (int)$opening->inventory_material_id!==(int)$layer->inventory_material_id ||
            !BigDecimal::of((string)$opening->quantity)
                ->isEqualTo((string)$layer->original_quantity) ||
            $proof->case_type!=='opening_fifo_unvalued' ||
            $proof->case_status!=='resolved' ||
            (int)$proof->requested_by===(int)$proof->reviewed_by ||
            $proof->journal_status!=='posted' || $proof->journal_reversal_id ||
            DB::table('journal_entries')
                ->where('reversal_of_id',$proof->journal_entry_id)->exists() ||
            (int)$proof->currency_id!==(int)$policy->currency_id ||
            (int)$proof->journal_currency_id!==(int)$policy->currency_id ||
            (int)$proof->inventory_ledger_id!==(int)$policy->material_inventory_ledger_id ||
            (string)$proof->posting_date!==(string)$proof->journal_date ||
            $proof->posting_date>$materialIssueDate ||
            $proof->posting_date>$capitalizationDate ||
            $layer->original_cost===null ||
            !BigDecimal::of((string)$layer->original_cost)
                ->isEqualTo((string)$proof->original_cost)){
            $this->reject('Historical opening FIFO source lacks valid independent original-currency asset/equity capitalization before the material issue and batch WIP posting.');
        }
        $asset=BigDecimal::zero();$equity=BigDecimal::zero();
        foreach(DB::table('journal_lines')
            ->where('journal_entry_id',$proof->journal_entry_id)
            ->get(['ledger_account_id','debit','credit']) as $line){
            if((int)$line->ledger_account_id===(int)$proof->inventory_ledger_id){
                $asset=$asset->plus((string)$line->debit)
                    ->minus((string)$line->credit);
            }
            if((int)$line->ledger_account_id===(int)$proof->opening_equity_ledger_id){
                $equity=$equity->plus((string)$line->credit)
                    ->minus((string)$line->debit);
            }
        }
        if(!$asset->isEqualTo((string)$proof->original_cost) ||
           !$equity->isEqualTo((string)$proof->original_cost)){
            $this->reject('The recorded original opening FIFO asset/equity GL journal does not balance to the cost attributed to this production material lot.');
        }
    }

    private function assetBalanceAsOf(int $ledgerId,int $currencyId,string $date):BigDecimal
    {
        $balance=BigDecimal::zero();
        $rows=DB::table('journal_lines as line')
            ->join('journal_entries as journal',
                'journal.id','=','line.journal_entry_id')
            ->where('journal.status','posted')
            ->where('journal.currency_id',$currencyId)
            ->whereDate('journal.entry_date','<=',$date)
            ->where('line.ledger_account_id',$ledgerId)
            ->get(['line.debit','line.credit']);
        foreach($rows as $row){
            $balance=$balance->plus((string)$row->debit)
                ->minus((string)$row->credit);
        }
        return $balance;
    }

    public function capitalize(int $batchId,array $data,int $actorId,
        ?int $historicalCaseId=null):object
    {
        return DB::transaction(function()use($batchId,$data,$actorId,$historicalCaseId){
            $batch=ProductionBatch::whereKey($batchId)->lockForUpdate()->firstOrFail();
            $existing=DB::table('production_batch_cost_postings')
                ->where('production_batch_id',$batchId)->first();
            if($existing){
                if($existing->accounting_date===$data['accounting_date'] &&
                    $existing->approval_reference===trim($data['approval_reference'])){
                    return $existing;
                }
                $this->reject('This batch is already capitalized. Posting again would double inventory and WIP balances.');
            }
            if($batch->status!=='released' || (int)$batch->accepted_packages<1){
                $this->reject('Only a QC-released batch with accepted physical packages can be capitalized.');
            }
            app(ProductionWasteFinancialReviewService::class)
                ->assertReleasedCostTreatment($batch->production_order_id);
            // A QC hold may be created after physical batch release. Financial
            // capitalization must not silently treat held finished products
            // as an unrestricted, saleable factory asset.
            app(FinishedGoodLotControlService::class)->assertSourceLotsClear($batch);
            app(FinishedBatchQualityGate::class)->assertApproved($batch);
            $receipt=DB::table('finished_good_movements')
                ->where('production_batch_id',$batchId)
                ->where('type','production_receipt')->first();
            if(!$receipt || $receipt->occurred_on>$data['accounting_date']){
                $this->reject('The cost capitalization date cannot precede the QC-approved physical finished goods receipt.');
            }
            // A historical recovery is the *only* permitted bypass for an
            // already-dispatched batch. A signed, approved case plus a
            // complete original-invoice COGS executor must accompany it;
            // ordinary manufacturing capitalization keeps the original block.
            $hasDispatched=DB::table('finished_good_dispatch_lines')
                ->where('production_batch_id',$batchId)->exists();
            $hasInvoices=DB::table('sales_invoice_lines')
                ->where('production_batch_id',$batchId)->exists();
            if($hasDispatched || $hasInvoices){
                if($historicalCaseId===null){
                    $this->reject('This batch has prior physical dispatches/invoices. Use an independently approved, atomic source-costed legacy manufacturing and invoice-COGS reconciliation.');
                }
                $history=DB::table('finance_historical_batch_capitalizations')
                    ->where('finance_historical_correction_case_id',$historicalCaseId)
                    ->where('production_batch_id',$batchId)->lockForUpdate()->first();
                $case=DB::table('finance_historical_correction_cases')
                    ->where('id',$historicalCaseId)->first();
                if(!$history || !$case || $case->case_type!=='batch_uncapitalized' ||
                    $case->status!=='approved' || $history->status!=='posting' ||
                    (string)$history->accounting_date!==(string)$data['accounting_date'] ||
                    $history->approval_reference!==trim($data['approval_reference']) ||
                    $history->production_batch_cost_posting_id ||
                    in_array($actorId,[
                        (int)$history->submitted_by,(int)$history->approved_by,
                        (int)$case->requested_by,(int)$case->reviewed_by,
                    ],true)){
                    $this->reject('Already-dispatched batch capitalization requires an independently authorized, unchanged historical correction and separate finance executor.');
                }
                $earliestDispatch=DB::table('finished_good_dispatch_lines as physical')
                    ->join('finished_good_dispatches as dispatch',
                        'dispatch.id','=','physical.finished_good_dispatch_id')
                    ->where('physical.production_batch_id',$batchId)
                    ->min('dispatch.dispatch_date');
                if(!$earliestDispatch ||
                    $data['accounting_date']>$earliestDispatch){
                    $this->reject('Historical manufacturing GL posting must be on or before the first original physical batch dispatch.');
                }
                $dispatches=DB::table('finished_good_dispatch_lines')
                    ->where('production_batch_id',$batchId);
                $expectedDispatchLines=$history->dispatch_line_count===null
                    ?(int)$history->invoice_line_count
                    :(int)$history->dispatch_line_count;
                if((int)$dispatches->count()!==$expectedDispatchLines ||
                    $expectedDispatchLines!==(int)$history->invoice_line_count+
                        (int)$history->non_sale_line_count ||
                    (int)$dispatches->sum('quantity_packages')!==(int)$history->dispatched_packages){
                    $this->reject('The originally authorized physical dispatch quantities changed since the historical recovery proposal.');
                }
            }elseif($historicalCaseId!==null){
                $this->reject('Historical manufacturing override applies only to previously dispatched, actually invoiced batches.');
            }
            $costs=$batch->costs()->get();
            if($costs->count()!==1 || $costs->first()->currency_id===null ||
                $costs->first()->material_cost===null ||
                BigDecimal::of((string)$costs->first()->unvalued_quantity)->isPositive()){
                $this->reject('This batch must have exactly one source currency and fully valued frozen material issues.');
            }
            $cost=$costs->first();
            $policy=$this->policies->approved((int)$cost->currency_id);
            $material=BigDecimal::of((string)$cost->material_cost);
            if(!$material->isPositive()){
                $this->reject('A zero or missing material cost cannot be capitalized as a finished product.');
            }
            if(DB::table('production_overhead_allocations')
                ->where('production_batch_id',$batchId)->where('status','submitted')->exists()){
                $this->reject('Review all pending production overhead allocations before costing the finished batch.');
            }
            $this->assertMaterialSources($batch,$policy,$data['accounting_date'],
                (string)$receipt->occurred_on,(string)$material);
            $overheads=DB::table('production_overhead_allocations as allocation')
                ->join('journal_lines as line','line.id','=','allocation.source_journal_line_id')
                ->join('journal_entries as journal','journal.id','=','line.journal_entry_id')
                ->leftJoin('journal_entries as reversal',
                    'reversal.reversal_of_id','=','journal.id')
                ->where('allocation.production_batch_id',$batchId)
                ->where('allocation.status','approved')
                ->select('allocation.*','line.ledger_account_id','line.debit','line.credit',
                    'journal.status as journal_status','journal.entry_date',
                    'journal.currency_id as journal_currency_id','journal.reversal_of_id',
                    'reversal.id as reversed_by_id')->get();
            $abnormal=app(ProductionAbnormalWasteCostService::class)
                ->forOrder($batch->production_order_id,(int)$policy->currency_id,$data['accounting_date']);
            $credits=[];$overhead=BigDecimal::zero();
            foreach($overheads as $row){
                if((int)$row->currency_id!==(int)$policy->currency_id ||
                   (int)$row->journal_currency_id!==(int)$policy->currency_id ||
                   $row->journal_status!=='posted' || $row->reversal_of_id!==null ||
                   $row->reversed_by_id!==null || $row->entry_date>$data['accounting_date'] ||
                   !$row->reviewed_at ||
                   substr((string)$row->reviewed_at,0,10)>$data['accounting_date'] ||
                   BigDecimal::of((string)$row->debit)->isLessThan((string)$row->amount) ||
                   BigDecimal::of((string)$row->credit)->isPositive()){
                    $this->reject('A reviewed overhead source is not an eligible, earlier posted expense debit in the batch currency.');
                }
                $ledger=DB::table('ledger_accounts')->where('id',$row->ledger_account_id)->first();
                if(!$ledger || $ledger->type!=='expense' || !$ledger->is_active || $ledger->is_group ||
                    (int)$row->ledger_account_id===(int)$policy->cogs_ledger_id){
                    $this->reject('Approved overhead must come from an independent, active expense account, not the COGS ledger.');
                }
                if(array_key_exists((int)$row->ledger_account_id,$abnormal['by_ledger'])){
                    $this->reject('A source overhead expense account cannot also be the abnormal-loss expense account for this batch.');
                }
                $amount=BigDecimal::of((string)$row->amount);
                $overhead=$overhead->plus($amount);
                $credits[]=['ledger'=>(int)$row->ledger_account_id,
                    'amount'=>(string)$amount->toScale(4),
                    'memo'=>'Approved overhead '.$row->number];
            }
            $materialPosted=$this->money($material);
            if(!BigDecimal::of($materialPosted)->isPositive()){
                $this->reject('Frozen material cost rounds to zero in journal precision and cannot support a positive GL posting.');
            }
            $abnormalPosted=$abnormal['total']->toScale(4,RoundingMode::HalfUp);
            if($abnormalPosted->isGreaterThanOrEqualTo($materialPosted)){
                $this->reject('After excluding abnormal waste, the batch has no remaining eligible normal material cost for accepted finished packages.');
            }
            // Round the ledger debits as a group. The last ledger takes the
            // rounding remainder, so total loss expense exactly matches the
            // cost excluded from finished goods in the 20,4 journal.
            $abnormalDebits=[];
            $assignedAbnormal=BigDecimal::zero();
            $ledgerIds=array_keys($abnormal['by_ledger']);
            sort($ledgerIds);
            foreach($ledgerIds as $index=>$ledgerId){
                $value=$index===count($ledgerIds)-1
                    ?$abnormalPosted->minus($assignedAbnormal)
                    :$abnormal['by_ledger'][$ledgerId]->toScale(4,RoundingMode::HalfUp);
                if(!$value->isPositive()){
                    $this->reject('An abnormal waste expense split rounds to zero. Review source cost precision before posting.');
                }
                $assignedAbnormal=$assignedAbnormal->plus($value);
                $abnormalDebits[]=[
                    'ledger'=>(int)$ledgerId,'amount'=>(string)$value->toScale(4),
                    'memo'=>'Independent FIFO-valued abnormal loss '.$batch->batch_number,
                ];
            }
            $netMaterial=$material->minus($abnormal['total']);
            if(!$netMaterial->isPositive()){
                $this->reject('Original FIFO values leave no eligible production material value for finished inventory.');
            }
            if($this->assetBalanceAsOf(
                (int)$policy->material_inventory_ledger_id,
                (int)$policy->currency_id,$data['accounting_date']
            )->isLessThan($materialPosted)){
                $this->reject('The source raw-material asset GL balance is insufficient for these original-currency FIFO issues on the capitalization date. Reconcile earlier inventory and purchase journals.');
            }
            $overheadPosted=(string)$overhead->toScale(4,RoundingMode::HalfUp);
            $totalPosted=$this->money(
                BigDecimal::of($materialPosted)->plus($overheadPosted)->minus($abnormalPosted));
            $debit=[['ledger'=>(int)$policy->work_in_progress_ledger_id,
                'amount'=>$totalPosted,'memo'=>'Normal source material plus approved overhead '.$batch->batch_number]];
            $debit=array_merge($debit,$abnormalDebits);
            $credit=[['ledger'=>(int)$policy->material_inventory_ledger_id,
                'amount'=>$materialPosted,'memo'=>'FIFO raw material and packaging issues '.$batch->batch_number]];
            $credit=array_merge($credit,$credits);
            $issue=$this->journal($data['accounting_date'],$policy->currency_id,
                $batch->batch_number,'Manufacturing source costs to WIP '.$batch->batch_number,
                $debit,$credit,$actorId);
            $finished=$this->journal($data['accounting_date'],$policy->currency_id,
                $batch->batch_number,'Finished-goods inventory capitalization '.$batch->batch_number,
                [['ledger'=>(int)$policy->finished_inventory_ledger_id,
                    'amount'=>$totalPosted,'memo'=>'QC-approved finished packages']],
                [['ledger'=>(int)$policy->work_in_progress_ledger_id,
                    'amount'=>$totalPosted,'memo'=>'WIP cleared to finished stock']],
                $actorId);
            $id=DB::table('production_batch_cost_postings')->insertGetId([
                'production_batch_id'=>$batchId,
                'manufacturing_cost_ledger_policy_id'=>$policy->id,
                'currency_id'=>$policy->currency_id,
                'accepted_packages'=>$batch->accepted_packages,
                'material_amount'=>$materialPosted,
                'abnormal_waste_amount'=>(string)$abnormalPosted->toScale(4),
                'overhead_amount'=>$overheadPosted,
                'total_amount'=>$totalPosted,
                'accounting_date'=>$data['accounting_date'],
                'issue_journal_id'=>$issue->id,
                'capitalization_journal_id'=>$finished->id,
                'approval_reference'=>trim($data['approval_reference']),
                'reconciliation_notes'=>trim($data['reconciliation_notes']),
                'approved_by'=>$actorId,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('manufacturing.batch_cost.capitalized',
                'production_batch',$batchId,
                'FIFO material and reviewed overhead reclassified to WIP and finished-goods asset.',
                ['posting_id'=>$id,'currency_id'=>$policy->currency_id,
                'material'=>$materialPosted,'overhead'=>$overheadPosted,
                'abnormal_waste_expensed'=>(string)$abnormalPosted->toScale(4),
                'total'=>$totalPosted,'issue_journal'=>$issue->number,
                'capitalization_journal'=>$finished->number]);
            return DB::table('production_batch_cost_postings')->where('id',$id)->first();
        });
    }
}

<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Read-only period-close blockers and source-data warnings.
 *
 * A balanced GL is necessary but does not prove that payroll, bank, fixed
 * asset or shareholder subledgers are complete. Never silently auto-post
 * missing records to make a month appear ready.
 */
class FinanceCloseReadinessService
{
    /**
     * Scrapped customer returns do not restore finished stock. Audited
     * reclassification must credit the authentic original COGS once and
     * debit only the separately approved original-cost loss account.
     */
    private function historicalScrapReclassificationIsValid(object $plan): bool
    {
        $qc=DB::table('sales_return_quarantines')
            ->where('id',$plan->sales_return_quarantine_id)->first();
        $credit=DB::table('sales_credit_note_lines')
            ->where('id',$plan->sales_credit_note_line_id)->first();
        $note=$credit?DB::table('sales_credit_notes')
            ->where('id',$credit->sales_credit_note_id)->first():null;
        $sale=DB::table('sales_invoice_lines')
            ->where('id',$plan->sales_invoice_line_id)->first();
        $source=DB::table('production_invoice_cogs_postings')
            ->where('id',$plan->production_invoice_cogs_posting_id)->first();
        $cost=$source?DB::table('production_batch_cost_postings')
            ->where('id',$source->production_batch_cost_posting_id)->first():null;
        $policy=$cost?DB::table('manufacturing_cost_ledger_policies')
            ->where('id',$cost->manufacturing_cost_ledger_policy_id)->first():null;
        $journal=DB::table('journal_entries')
            ->where('id',$plan->journal_entry_id)->first();
        $original=$source?DB::table('journal_entries')
            ->where('id',$source->journal_entry_id)->first():null;
        $expense=DB::table('ledger_accounts')
            ->where('id',$plan->loss_ledger_id)->first();
        $review=DB::table('finance_historical_correction_cases')
            ->where('id',$plan->finance_historical_correction_case_id)->first();
        if(!$qc || !$credit || !$note || !$sale || !$source || !$cost ||
            !$policy || !$journal || !$original || !$expense || !$review ||
            $plan->status!=='posted' ||
            $plan->treatment!=='scrap_cogs_reclass' ||
            $review->status!=='resolved' ||
            $qc->status!=='scrapped' || !$qc->decided_by ||
            (int)$qc->registered_by===(int)$qc->decided_by ||
            !$qc->quality_reference || !$qc->decision_notes ||
            $qc->released_warehouse_id!==null ||
            (int)$qc->quantity_packages!==(int)$plan->quantity_packages ||
            (string)$qc->decided_on!==(string)$plan->source_date ||
            (string)$qc->decided_on!==(string)$plan->posting_date ||
            (int)$qc->sales_credit_note_line_id!==(int)$credit->id ||
            (int)$credit->sales_invoice_line_id!==(int)$sale->id ||
            (int)$credit->quantity_packages!==(int)$plan->quantity_packages ||
            (int)$credit->production_batch_id!==(int)$sale->production_batch_id ||
            (int)$qc->production_batch_id!==(int)$sale->production_batch_id ||
            (int)$source->sales_invoice_line_id!==(int)$sale->id ||
            (int)$source->quantity_packages!==(int)$sale->quantity_packages ||
            (int)$plan->currency_id!==(int)$cost->currency_id ||
            (int)$plan->cogs_ledger_id!==(int)$policy->cogs_ledger_id ||
            (int)$plan->loss_ledger_id===(int)$policy->cogs_ledger_id ||
            $expense->type!=='expense' || !$expense->is_active || $expense->is_group ||
            $journal->status!=='posted' || $journal->reversal_of_id ||
            (int)$journal->currency_id!==(int)$cost->currency_id ||
            (string)$journal->entry_date!==(string)$plan->posting_date ||
            (int)$journal->id!==(int)$plan->journal_entry_id ||
            $original->status!=='posted' || $original->reversal_of_id ||
            (int)$original->currency_id!==(int)$cost->currency_id ||
            DB::table('journal_entries')
                ->whereIn('reversal_of_id',[$journal->id,$original->id])->exists() ||
            DB::table('finished_good_warehouse_movements')
                ->where('production_batch_id',$qc->production_batch_id)
                ->where('type','customer_return')
                ->where('reference','Quarantined customer return QC '.$qc->id)
                ->exists() ||
            DB::table('production_return_cost_postings')
                ->where('sales_return_quarantine_id',$qc->id)->exists() ||
            !BigDecimal::of((string)$plan->original_invoice_cogs)
                ->isEqualTo((string)$source->amount) ||
            !BigDecimal::of((string)$plan->source_loss_amount)->isPositive())
            return false;
        $lines=DB::table('journal_lines')
            ->where('journal_entry_id',$journal->id)->get();
        if($lines->count()!==2)return false;
        $loss=BigDecimal::zero();$cogs=BigDecimal::zero();
        foreach($lines as $line){
            if((int)$line->ledger_account_id===(int)$plan->loss_ledger_id)
                $loss=$loss->plus((string)$line->debit)->minus((string)$line->credit);
            elseif((int)$line->ledger_account_id===(int)$plan->cogs_ledger_id)
                $cogs=$cogs->plus((string)$line->debit)->minus((string)$line->credit);
            else return false; // No finished-stock asset or unrelated GL account.
        }
        return $loss->isEqualTo((string)$plan->source_loss_amount) &&
            $cogs->isEqualTo(
                BigDecimal::of((string)$plan->source_loss_amount)->negated()
            );
    }

    /**
     * Independent source check for QC-scrapped customer credit COST
     * reclassification originating from a formerly uncapitalized batch.
     * These two GL lines NEVER include finished inventory or extra loss.
     */
    private function historicalBatchScrapIsValid(object $scrap,object $batch): bool
    {
        $qc=DB::table('sales_return_quarantines')
            ->where('id',$scrap->sales_return_quarantine_id)->first();
        $credit=DB::table('sales_credit_note_lines')
            ->where('id',$scrap->sales_credit_note_line_id)->first();
        $note=$credit?DB::table('sales_credit_notes')
            ->where('id',$credit->sales_credit_note_id)->first():null;
        $sale=DB::table('sales_invoice_lines')
            ->where('id',$scrap->sales_invoice_line_id)->first();
        $invoice=$sale?DB::table('sales_invoices')
            ->where('id',$sale->sales_invoice_id)->first():null;
        $cogs=DB::table('production_invoice_cogs_postings')
            ->where('id',$scrap->source_cogs_posting_id)->first();
        $original=$cogs?DB::table('journal_entries')
            ->where('id',$cogs->journal_entry_id)->first():null;
        $journal=DB::table('journal_entries')
            ->where('id',$scrap->journal_entry_id)->first();
        $manufacturing=DB::table('production_batch_cost_postings')
            ->where('id',$batch->production_batch_cost_posting_id)->first();
        $policy=$manufacturing?DB::table('manufacturing_cost_ledger_policies')
            ->where('id',$manufacturing->manufacturing_cost_ledger_policy_id)->first():null;
        $expense=DB::table('ledger_accounts')
            ->where('id',$scrap->loss_expense_ledger_id)->first();
        if(!$qc || !$credit || !$note || !$sale || !$invoice || !$cogs ||
            !$original || !$journal || !$manufacturing || !$policy || !$expense ||
            $qc->status!=='scrapped' ||
            !$qc->decided_by || !$qc->quality_reference || !$qc->decision_notes ||
            (int)$qc->registered_by===(int)$qc->decided_by ||
            $qc->released_warehouse_id!==null ||
            (int)$qc->production_batch_id!==(int)$batch->production_batch_id ||
            (int)$credit->production_batch_id!==(int)$batch->production_batch_id ||
            (int)$sale->production_batch_id!==(int)$batch->production_batch_id ||
            (int)$qc->sales_credit_note_line_id!==(int)$credit->id ||
            (int)$credit->sales_invoice_line_id!==(int)$sale->id ||
            (int)$qc->quantity_packages!==(int)$scrap->quantity_packages ||
            (int)$credit->quantity_packages!==(int)$scrap->quantity_packages ||
            (int)$cogs->sales_invoice_line_id!==(int)$sale->id ||
            (int)$cogs->production_batch_cost_posting_id!==(int)$manufacturing->id ||
            (int)$scrap->currency_id!==(int)$manufacturing->currency_id ||
            $invoice->status!=='posted' ||
            (int)$note->sales_invoice_id!==(int)$invoice->id ||
            $original->status!=='posted' || $original->reversal_of_id ||
            $journal->status!=='posted' || $journal->reversal_of_id ||
            (int)$journal->currency_id!==(int)$manufacturing->currency_id ||
            (int)$original->currency_id!==(int)$manufacturing->currency_id ||
            (string)$journal->entry_date!==(string)$scrap->original_qc_scrap_date ||
            (string)$qc->decided_on!==(string)$scrap->original_qc_scrap_date ||
            (string)$original->entry_date>(string)$journal->entry_date ||
            DB::table('journal_entries')
                ->whereIn('reversal_of_id',[$original->id,$journal->id])->exists() ||
            DB::table('finished_good_warehouse_movements')
                ->where('production_batch_id',$qc->production_batch_id)
                ->where('type','customer_return')
                ->where('reference','Quarantined customer return QC '.$qc->id)->exists() ||
            DB::table('production_return_cost_postings')
                ->where('sales_return_quarantine_id',$qc->id)->exists() ||
            !BigDecimal::of((string)$scrap->original_invoice_cogs_amount)
                ->isEqualTo((string)$cogs->amount) ||
            !BigDecimal::of((string)$scrap->scrap_reclass_amount)->isPositive() ||
            BigDecimal::of((string)$scrap->scrap_reclass_amount)
                ->isGreaterThan((string)$cogs->amount) ||
            $expense->type!=='expense' || !$expense->is_active || $expense->is_group ||
            (int)$scrap->loss_expense_ledger_id===(int)$policy->cogs_ledger_id ||
            in_array((int)$scrap->loss_expense_ledger_id,[
                (int)$policy->material_inventory_ledger_id,
                (int)$policy->work_in_progress_ledger_id,
                (int)$policy->finished_inventory_ledger_id,
            ],true))
            return false;

        $ledgerRows=DB::table('journal_lines')
            ->where('journal_entry_id',$journal->id)->get();
        if($ledgerRows->count()!==2)return false;
        $loss=BigDecimal::zero();$cogsReduction=BigDecimal::zero();
        foreach($ledgerRows as $line){
            $net=BigDecimal::of((string)$line->debit)
                ->minus((string)$line->credit);
            if((int)$line->ledger_account_id===(int)$scrap->loss_expense_ledger_id)
                $loss=$loss->plus($net);
            elseif((int)$line->ledger_account_id===(int)$policy->cogs_ledger_id)
                $cogsReduction=$cogsReduction->plus($net);
            else return false; // Unrelated, customer, or finished-stock account.
        }
        return $loss->isEqualTo((string)$scrap->scrap_reclass_amount) &&
            $cogsReduction->isEqualTo(
                BigDecimal::of((string)$scrap->scrap_reclass_amount)->negated());
    }

    /**
     * Cross-subledger proof that mixed saleable QC returns and condemned
     * customer scraps cannot claim the same original invoice COGS remainder.
     * Inspect the complete historical batch, including QC events in other
     * months, whenever its source period or a QC month is being closed.
     */
    private function historicalMixedQcInvoiceCostIsValid(object $batch): bool
    {
        $released=DB::table('finance_historical_batch_return_allocations')
            ->where('finance_historical_batch_capitalization_id',$batch->id)
            ->get();
        $scrapped=DB::table('finance_historical_batch_scrap_allocations')
            ->where('finance_historical_batch_capitalization_id',$batch->id)
            ->get();
        $bySale=[];
        foreach($released as $row){
            $bySale[(int)$row->sales_invoice_line_id][]=[
                'type'=>'return','date'=>(string)$row->original_qc_date,
                'qc_id'=>(int)$row->sales_return_quarantine_id,
                'credit_id'=>(int)$row->sales_credit_note_line_id,
                'qty'=>(int)$row->quantity_packages,
                'original'=>(string)$row->original_invoiced_cogs_amount,
                'amount'=>(string)$row->original_return_cost_amount,
                'currency_id'=>(int)$row->currency_id,
            ];
        }
        foreach($scrapped as $row){
            $bySale[(int)$row->sales_invoice_line_id][]=[
                'type'=>'scrap','date'=>(string)$row->original_qc_scrap_date,
                'qc_id'=>(int)$row->sales_return_quarantine_id,
                'credit_id'=>(int)$row->sales_credit_note_line_id,
                'qty'=>(int)$row->quantity_packages,
                'original'=>(string)$row->original_invoice_cogs_amount,
                'amount'=>(string)$row->scrap_reclass_amount,
                'currency_id'=>(int)$row->currency_id,
            ];
        }
        foreach($bySale as $saleId=>$events){
            $releasedCount=count(array_filter($events,
                fn($event)=>$event['type']==='return'));
            if(!$releasedCount || $releasedCount===count($events))continue;
            $sale=DB::table('sales_invoice_lines')->where('id',$saleId)->first();
            $cogs=DB::table('production_invoice_cogs_postings')
                ->where('sales_invoice_line_id',$saleId)->first();
            if(!$sale || !$cogs || (int)$sale->quantity_packages<1 ||
                (int)$cogs->quantity_packages!==(int)$sale->quantity_packages ||
                (int)$sale->production_batch_id!==(int)$batch->production_batch_id ||
                (int)$cogs->production_batch_cost_posting_id!==
                    (int)$batch->production_batch_cost_posting_id)
                return false;

            $total=BigDecimal::of((string)$cogs->amount);
            if(!$total->isPositive())return false;
            $seenQC=[];$seenCredit=[];$allQty=0;
            foreach($events as $event){
                if($event['qty']<1 ||
                    $event['currency_id']!==(int)$batch->currency_id ||
                    !BigDecimal::of($event['original'])->isEqualTo($total) ||
                    isset($seenQC[$event['qc_id']]) ||
                    isset($seenCredit[$event['credit_id']]))return false;
                $seenQC[$event['qc_id']]=true;
                $seenCredit[$event['credit_id']]=true;
                $allQty+=$event['qty'];
            }
            if($allQty>(int)$sale->quantity_packages)return false;
            usort($events,fn($x,$y)=>[
                $x['date'],$x['type']==='return'?0:1,$x['qc_id'],
            ]<=>[
                $y['date'],$y['type']==='return'?0:1,$y['qc_id'],
            ]);
            $usedQty=0;$usedCost=BigDecimal::zero();
            foreach($events as $event){
                $usedQty+=$event['qty'];
                $expected=$usedQty===(int)$sale->quantity_packages
                    ?$total->minus($usedCost)
                    :$total->multipliedBy((string)$event['qty'])
                        ->dividedBy((string)$sale->quantity_packages,4,
                            \Brick\Math\RoundingMode::HalfUp);
                if(!$expected->isPositive() ||
                    $expected->isGreaterThan($total->minus($usedCost)) ||
                    !$expected->isEqualTo($event['amount']))
                    return false;
                $usedCost=$usedCost->plus($expected);
            }
        }
        return true;
    }

    /**
     * Original production-batch QC return allocations form one immutable
     * cost-recovery subledger. This read-only audit is also used for the QC
     * journal's own month, even if manufacturing was capitalized earlier.
     */
    private function historicalBatchReturnCostIsValid(object $entry,object $plan): bool
    {
        $qc=DB::table('sales_return_quarantines')
            ->where('id',$entry->sales_return_quarantine_id)->first();
        $credit=DB::table('sales_credit_note_lines')
            ->where('id',$entry->sales_credit_note_line_id)->first();
        $sale=DB::table('sales_invoice_lines')
            ->where('id',$entry->sales_invoice_line_id)->first();
        $restored=DB::table('production_return_cost_postings')
            ->where('id',$entry->production_return_cost_posting_id)->first();
        $source=$sale?DB::table('production_invoice_cogs_postings')
            ->where('sales_invoice_line_id',$sale->id)->first():null;
        $journal=DB::table('journal_entries')
            ->where('id',$entry->journal_entry_id)->first();
        $batchCost=DB::table('production_batch_cost_postings')
            ->where('id',$plan->production_batch_cost_posting_id)->first();
        $policy=$batchCost?DB::table('manufacturing_cost_ledger_policies')
            ->where('id',$batchCost->manufacturing_cost_ledger_policy_id)->first():null;
        if(!$qc || !$credit || !$sale || !$restored || !$source ||
            !$journal || !$batchCost || !$policy ||
            $qc->status!=='released' ||
            (int)$qc->production_batch_id!==(int)$plan->production_batch_id ||
            (int)$sale->production_batch_id!==(int)$plan->production_batch_id ||
            (int)$credit->production_batch_id!==(int)$plan->production_batch_id ||
            (int)$credit->sales_invoice_line_id!==(int)$sale->id ||
            (int)$qc->sales_credit_note_line_id!==(int)$credit->id ||
            (int)$credit->sales_invoice_line_id!==(int)$sale->id ||
            (int)$credit->quantity_packages!==(int)$qc->quantity_packages ||
            (int)$qc->quantity_packages!==(int)$entry->quantity_packages ||
            (int)$qc->registered_by===(int)$qc->decided_by ||
            !$qc->decided_by || !$qc->quality_reference ||
            !$qc->released_warehouse_id ||
            (string)$qc->decided_on!==(string)$entry->original_qc_date ||
            (int)$source->production_batch_cost_posting_id!==(int)$batchCost->id ||
            (int)$source->id!==(int)$restored->production_invoice_cogs_posting_id ||
            (int)$restored->sales_return_quarantine_id!==(int)$qc->id ||
            (int)$restored->quantity_packages!==(int)$entry->quantity_packages ||
            (int)$entry->currency_id!==(int)$batchCost->currency_id ||
            $journal->status!=='posted' || $journal->reversal_of_id ||
            (string)$journal->entry_date!==(string)$entry->original_qc_date ||
            (int)$journal->currency_id!==(int)$batchCost->currency_id ||
            (int)$restored->journal_entry_id!==(int)$entry->journal_entry_id ||
            DB::table('journal_entries')
                ->where('reversal_of_id',$journal->id)->exists() ||
            !BigDecimal::of((string)$source->amount)
                ->isEqualTo((string)$entry->original_invoiced_cogs_amount) ||
            !BigDecimal::of((string)$restored->amount)
                ->isEqualTo((string)$entry->original_return_cost_amount))
            return false;

        $movements=DB::table('finished_good_warehouse_movements')
            ->where('production_batch_id',$qc->production_batch_id)
            ->where('type','customer_return')
            ->where('reference','Quarantined customer return QC '.$qc->id)
            ->get(['destination_warehouse_id','source_warehouse_id',
                'quantity_packages','occurred_on','created_by']);
        if($movements->count()!==1 ||
            (int)$movements->first()->destination_warehouse_id!==(int)$qc->released_warehouse_id ||
            $movements->first()->source_warehouse_id!==null ||
            (int)$movements->first()->quantity_packages!==(int)$qc->quantity_packages ||
            (string)$movements->first()->occurred_on!==(string)$entry->original_qc_date ||
            (int)$movements->first()->created_by!==(int)$qc->decided_by)
            return false;
        $asset=BigDecimal::zero();$cogs=BigDecimal::zero();
        foreach(DB::table('journal_lines')
            ->where('journal_entry_id',$journal->id)
            ->get(['ledger_account_id','debit','credit']) as $line){
            if((int)$line->ledger_account_id===(int)$policy->finished_inventory_ledger_id)
                $asset=$asset->plus((string)$line->debit)->minus((string)$line->credit);
            if((int)$line->ledger_account_id===(int)$policy->cogs_ledger_id)
                $cogs=$cogs->plus((string)$line->debit)->minus((string)$line->credit);
        }
        return $asset->isEqualTo((string)$entry->original_return_cost_amount) &&
            $cogs->isEqualTo(
                BigDecimal::of((string)$entry->original_return_cost_amount)->negated()
            );
    }

    /**
     * Validate both original receipt asset/equity and every historic
     * adjustment loss debit / inventory credit for an immutable original
     * opening FIFO layer. No manufactured amount can disappear through
     * consumed rows which are deliberately still marked unvalued.
     */
    private function sourceHistoricFifoAdjustmentIsValid(object $plan):bool
    {
        $lot=DB::table('inventory_cost_layers')
            ->where('id',$plan->inventory_cost_layer_id)->first();
        $opening=$lot?DB::table('inventory_stock_movements')
            ->where('id',$lot->source_id)->first():null;
        $sourceReview=DB::table('finance_legacy_fifo_source_reviews')
            ->where('id',$plan->finance_legacy_fifo_source_review_id)->first();
        $journal=DB::table('journal_entries')
            ->where('id',$plan->opening_journal_entry_id)->first();
        $policy=DB::table('manufacturing_cost_ledger_policies')
            ->where('currency_id',$plan->currency_id)->first();
        $sourceExpense=DB::table('ledger_accounts')
            ->where('id',$plan->adjustment_loss_ledger_id)->first();
        $sourceEquity=DB::table('ledger_accounts')
            ->where('id',$plan->opening_equity_ledger_id)->first();
        if(!$lot || !$opening || !$sourceReview || !$journal || !$policy ||
            !$sourceExpense || !$sourceEquity ||
            $plan->status!=='posted' || !$plan->executed_by ||
            !$plan->approved_by || !$plan->submitted_by ||
            count(array_unique([(int)$plan->submitted_by,
                (int)$plan->approved_by,(int)$plan->executed_by,
                (int)$sourceReview->requested_by,(int)$sourceReview->reviewed_by,
                (int)$opening->created_by]))!==6 ||
            $sourceReview->status!=='approved' ||
            $sourceReview->review_type!=='consumed_provenance' ||
            (int)$sourceReview->inventory_cost_layer_id!==(int)$lot->id ||
            $lot->source_type!=='inventory_stock_movement' ||
            $opening->type!=='opening' || $opening->direction!=='in' ||
            (int)$opening->inventory_material_id!==(int)$lot->inventory_material_id ||
            $sourceExpense->type!=='expense' || !$sourceExpense->is_active ||
            $sourceExpense->is_group ||
            $sourceEquity->type!=='equity' || !$sourceEquity->is_active ||
            $sourceEquity->is_group ||
            $policy->status!=='approved' ||
            (int)$policy->material_inventory_ledger_id!==(int)$plan->inventory_ledger_id ||
            (int)$policy->cogs_ledger_id===(int)$plan->adjustment_loss_ledger_id ||
            (int)$lot->currency_id!==(int)$plan->currency_id ||
            (string)$lot->currency_code!==(string)DB::table('currencies')
                ->where('id',$plan->currency_id)->value('code') ||
            $lot->original_cost===null ||
            !BigDecimal::of((string)$lot->original_cost)
                ->isEqualTo((string)$plan->original_total_cost) ||
            !BigDecimal::of((string)$lot->original_quantity)
                ->isEqualTo((string)$plan->original_quantity) ||
            !BigDecimal::of((string)$lot->remaining_quantity)
                ->isEqualTo((string)$plan->remaining_quantity) ||
            $journal->status!=='posted' || $journal->reversal_of_id ||
            (int)$journal->currency_id!==(int)$plan->currency_id ||
            (string)$journal->entry_date!==(string)$opening->occurred_on ||
            DB::table('journal_entries')
                ->where('reversal_of_id',$journal->id)->exists())
            return false;

        $checkJournal=function(int $id,int $debitLedger,int $creditLedger,
            string $amount,string $date,int $currency):bool{
            $entry=DB::table('journal_entries')->where('id',$id)->first();
            if(!$entry || $entry->status!=='posted' || $entry->reversal_of_id ||
                (string)$entry->entry_date!==$date ||
                (int)$entry->currency_id!==$currency ||
                DB::table('journal_entries')->where('reversal_of_id',$entry->id)->exists())
                return false;
            $lines=DB::table('journal_lines')->where('journal_entry_id',$id)->get();
            if($lines->count()!==2)return false;
            $debit=BigDecimal::zero();$credit=BigDecimal::zero();
            foreach($lines as $line){
                if((int)$line->ledger_account_id===$debitLedger)
                    $debit=$debit->plus((string)$line->debit)
                        ->minus((string)$line->credit);
                elseif((int)$line->ledger_account_id===$creditLedger)
                    $credit=$credit->plus((string)$line->credit)
                        ->minus((string)$line->debit);
                else return false;
            }
            return $debit->isEqualTo($amount) && $credit->isEqualTo($amount);
        };
        if(!$checkJournal((int)$journal->id,(int)$plan->inventory_ledger_id,
            (int)$plan->opening_equity_ledger_id,
            (string)$plan->original_total_cost,(string)$opening->occurred_on,
            (int)$plan->currency_id))return false;

        $parts=DB::table('finance_historical_fifo_adjustment_cost_lines')
            ->where('finance_historical_fifo_adjustment_valuation_id',$plan->id)->get();
        $issues=DB::table('inventory_cost_consumptions')
            ->where('inventory_cost_layer_id',$lot->id)->get();
        if($parts->isEmpty() || $parts->count()!==$issues->count())return false;
        $qty=BigDecimal::zero();$amount=BigDecimal::zero();
        foreach($parts as $part){
            $source=$issues->firstWhere('id',$part->inventory_cost_consumption_id);
            $move=$source?DB::table('inventory_stock_movements')
                ->where('id',$source->inventory_stock_movement_id)->first():null;
            $adjustment=$move?DB::table('inventory_stock_adjustments')
                ->where('id',$move->reference_id)->first():null;
            if(!$source || !$move || !$adjustment ||
                (int)$part->inventory_stock_movement_id!==(int)$move->id ||
                (int)$move->inventory_material_id!==(int)$lot->inventory_material_id ||
                $move->reference_type!=='inventory_adjustment' ||
                $move->type!=='adjustment_out' || $move->direction!=='out' ||
                (int)$move->reference_id!==(int)$adjustment->id ||
                $adjustment->direction!=='adjustment_out' ||
                (string)$move->occurred_on!==(string)$part->original_issue_date ||
                (string)$adjustment->occurred_on!==(string)$move->occurred_on ||
                (int)$adjustment->created_by!==(int)$move->created_by ||
                (int)$part->inventory_stock_movement_id!==(int)$source->inventory_stock_movement_id ||
                !BigDecimal::of((string)$source->quantity)
                    ->isEqualTo((string)$part->quantity) ||
                $source->is_valued || $source->amount!==null ||
                !BigDecimal::of((string)$part->amount)->isPositive() ||
                !$part->journal_entry_id ||
                !$checkJournal((int)$part->journal_entry_id,
                    (int)$plan->adjustment_loss_ledger_id,
                    (int)$plan->inventory_ledger_id,
                    (string)$part->amount,(string)$part->original_issue_date,
                    (int)$plan->currency_id))
                return false;
            $qty=$qty->plus((string)$part->quantity);
            $amount=$amount->plus((string)$part->amount);
        }
        $openingQty=BigDecimal::of((string)$plan->original_quantity);
        return $openingQty->isEqualTo(
                $qty->plus((string)$plan->remaining_quantity)
            ) &&
            $amount->isEqualTo((string)$plan->consumed_original_cost) &&
            $amount->plus((string)$plan->remaining_original_cost)
                ->isEqualTo((string)$plan->original_total_cost);
    }

    public function inspect(string $month):array
    {
        $start=CarbonImmutable::createFromFormat('!Y-m-d',$month.'-01');
        if(!$start || $start->format('Y-m')!==$month){
            throw \Illuminate\Validation\ValidationException::withMessages([
                'month'=>'Select a valid accounting month.',
            ]);
        }
        $begin=$start->startOfMonth()->toDateString();
        $end=$start->endOfMonth()->toDateString();
        $blockers=[];$warnings=[];
        $draftJournals=DB::table('journal_entries')
            ->whereBetween('entry_date',[$begin,$end])
            ->where('status','draft')->count();
        if($draftJournals){
            $blockers[]=$draftJournals.' draft GL journal(s) remain in the month.';
        }
        $balanceByCurrency=[];
        foreach(DB::table('journal_lines as line')
            ->join('journal_entries as entry','entry.id','=','line.journal_entry_id')
            ->where('entry.status','posted')
            ->whereBetween('entry.entry_date',[$begin,$end])
            ->get(['entry.currency_id','line.debit','line.credit']) as $row){
            $id=(int)$row->currency_id;
            if(!isset($balanceByCurrency[$id])){
                $balanceByCurrency[$id]=[
                    'debits'=>BigDecimal::zero(),
                    'credits'=>BigDecimal::zero(),
                ];
            }
            $balanceByCurrency[$id]['debits']=$balanceByCurrency[$id]['debits']
                ->plus((string)$row->debit);
            $balanceByCurrency[$id]['credits']=$balanceByCurrency[$id]['credits']
                ->plus((string)$row->credit);
        }
        foreach($balanceByCurrency as $currencyId=>$totals){
            if(!$totals['debits']->isEqualTo($totals['credits'])){
                $blockers[]='Posted GL journal debits and credits do not balance in currency ID '.
                    $currencyId.' for the accounting month. Review its original-currency journal lines.';
            }
        }
        $draftPayroll=DB::table('payroll_periods')
            ->where('period_start',$begin)->where('status','draft')->count();
        if($draftPayroll){
            $blockers[]=$draftPayroll.' draft payroll accrual(s) are not approved.';
        }
        $draftBank=DB::table('bank_reconciliation_periods')
            ->where('period_start','<=',$end)
            ->where('period_end','>=',$begin)
            ->where('status','draft')->count();
        if($draftBank){
            $blockers[]=$draftBank.' overlapping draft bank statement reconciliation(s) are awaiting approval.';
        }
        $openDisposals=DB::table('fixed_asset_disposals')
            ->whereBetween('disposed_on',[$begin,$end])
            ->where('financial_status','pending_reconciliation')->count();
        if($openDisposals){
            $blockers[]=$openDisposals.' physically disposed fixed asset(s) still lack the reconciled disposal GL journal.';
        }

        $capitalCashMissing=DB::table('shareholder_transactions as source')
            ->leftJoin('money_transactions as money',
                'money.journal_entry_id','=','source.journal_entry_id')
            ->whereBetween('source.transaction_date',[$begin,$end])
            ->whereNull('money.id')->count();
        $profitCashMissing=DB::table('shareholder_profit_distributions as source')
            ->leftJoin('money_transactions as money',
                'money.journal_entry_id','=','source.journal_entry_id')
            ->whereBetween('source.paid_on',[$begin,$end])
            ->whereNull('money.id')->count();
        if($capitalCashMissing || $profitCashMissing){
            $blockers[]=($capitalCashMissing+$profitCashMissing).
                ' posted shareholder cash movements have no matching cashbook/bank transaction. Reconcile historical postings.';
        }
        // Source-linked manufacturing movements are necessary for a
        // meaningful inventory valuation and COGS close. Block the period if
        // physical releases, invoice dispatches, or saleable customer returns
        // cannot be reconciled to original-currency GL journals.
        $missingCapital=DB::table('finished_good_movements as movement')
            ->leftJoin('production_batch_cost_postings as cost',
                'cost.production_batch_id','=','movement.production_batch_id')
            ->where('movement.type','production_receipt')
            ->whereBetween('movement.occurred_on',[$begin,$end])
            ->whereNull('cost.id')->count();
        if($missingCapital){
            $blockers[]=$missingCapital.
                ' physical finished-goods production receipt(s) have no reviewed WIP and inventory capitalization journals.';
        }
        $missingCOGS=DB::table('sales_invoice_lines as line')
            ->join('sales_invoices as invoice','invoice.id','=','line.sales_invoice_id')
            ->leftJoin('production_invoice_cogs_postings as cogs',
                'cogs.sales_invoice_line_id','=','line.id')
            ->where('invoice.status','posted')
            ->whereBetween('invoice.invoice_date',[$begin,$end])
            ->whereNull('cogs.id')->count();
        if($missingCOGS){
            $blockers[]=$missingCOGS.
                ' posted customer invoice dispatch line(s) have no original-currency COGS journal.';
        }
        $missingReturnCost=DB::table('sales_return_quarantines as ret')
            ->leftJoin('production_return_cost_postings as recovered',
                'recovered.sales_return_quarantine_id','=','ret.id')
            ->where('ret.status','released')
            ->whereBetween('ret.decided_on',[$begin,$end])
            ->whereNull('recovered.id')->count();
        if($missingReturnCost){
            $blockers[]=$missingReturnCost.
                ' saleable returned-lot recovery transaction(s) have no associated inventory asset/COGS reversal journal.';
        }
        // A previously released physical return may have been credited to the
        // customer and restocked before its factory COGS was reversed. Such a
        // source correction is valid for financial close ONLY when its exact
        // original-currency, independent-QC asset restoration remains posted.
        $historicReturns=DB::table('finance_historical_customer_return_cost_recoveries as plan')
            ->leftJoin('sales_return_quarantines as qc',
                'qc.id','=','plan.sales_return_quarantine_id')
            ->leftJoin('production_return_cost_postings as restored',
                'restored.sales_return_quarantine_id','=','plan.sales_return_quarantine_id')
            ->leftJoin('journal_entries as journal',
                'journal.id','=','restored.journal_entry_id')
            ->leftJoin('finance_historical_correction_cases as correction',
                'correction.id','=','plan.finance_historical_correction_case_id')
            ->where('plan.status','posted')
            ->whereBetween('plan.posting_date',[$begin,$end])
            ->select('plan.*','qc.status as qc_status',
                'qc.decided_on','qc.quantity_packages as qc_quantity',
                'qc.registered_by as qc_registered_by',
                'qc.decided_by as qc_decided_by',
                'restored.id as restored_id',
                'restored.production_invoice_cogs_posting_id as source_cogs_id',
                'restored.quantity_packages as restored_quantity',
                'restored.amount as restored_amount',
                'restored.journal_entry_id as restored_journal_id',
                'journal.status as journal_status',
                'journal.currency_id as journal_currency_id',
                'journal.entry_date as journal_date',
                'journal.reversal_of_id as journal_reversal',
                'correction.status as case_status')->get();
        $invalidHistoricalReturns=0;
        foreach($historicReturns as $plan){
            $valid=$plan->restored_id && $plan->qc_status==='released' &&
                $plan->case_status==='resolved' &&
                $plan->journal_status==='posted' &&
                !$plan->journal_reversal &&
                $plan->posted_by && $plan->approved_by &&
                $plan->submitted_by && $plan->qc_decided_by &&
                $plan->qc_registered_by &&
                (int)$plan->approved_by!==(int)$plan->posted_by &&
                (int)$plan->submitted_by!==(int)$plan->approved_by &&
                (int)$plan->qc_decided_by!==(int)$plan->qc_registered_by &&
                (int)$plan->source_cogs_id===(int)$plan->production_invoice_cogs_posting_id &&
                (int)$plan->restored_quantity===(int)$plan->quantity_packages &&
                (int)$plan->qc_quantity===(int)$plan->quantity_packages &&
                (int)$plan->restored_journal_id===(int)$plan->journal_entry_id &&
                (int)$plan->journal_currency_id===(int)$plan->currency_id &&
                (string)$plan->journal_date===(string)$plan->posting_date &&
                (string)$plan->decided_on===(string)$plan->original_qc_date &&
                (string)$plan->original_qc_date===(string)$plan->posting_date &&
                BigDecimal::of((string)($plan->restored_amount??'0'))
                    ->isEqualTo((string)$plan->recoverable_original_cost);
            if($valid && DB::table('journal_entries')
                    ->where('reversal_of_id',$plan->journal_entry_id)->exists())
                $valid=false;
            if($valid){
                $asset=BigDecimal::zero();$expense=BigDecimal::zero();
                foreach(DB::table('journal_lines')
                    ->where('journal_entry_id',$plan->journal_entry_id)
                    ->get(['ledger_account_id','debit','credit']) as $line){
                    if((int)$line->ledger_account_id===(int)$plan->finished_inventory_ledger_id)
                        $asset=$asset->plus((string)$line->debit)->minus((string)$line->credit);
                    if((int)$line->ledger_account_id===(int)$plan->cogs_ledger_id)
                        $expense=$expense->plus((string)$line->debit)->minus((string)$line->credit);
                }
                if(!$asset->isEqualTo((string)$plan->recoverable_original_cost) ||
                    !$expense->isEqualTo(
                        BigDecimal::of((string)$plan->recoverable_original_cost)->negated()
                    ))$valid=false;
            }
            if(!$valid)$invalidHistoricalReturns++;
        }
        if($invalidHistoricalReturns)
            $blockers[]=$invalidHistoricalReturns.
                ' originally QC-released historical customer return(s) have incomplete, reversed or mismatched source-currency finished-asset/COGS recovery journals.';
        $historicBatchQCReturns=DB::table('finance_historical_batch_return_allocations as entry')
            ->join('finance_historical_batch_capitalizations as plan',
                'plan.id','=','entry.finance_historical_batch_capitalization_id')
            ->where('plan.status','posted')
            ->whereBetween('entry.original_qc_date',[$begin,$end])
            ->select('entry.*','plan.production_batch_id',
                'plan.production_batch_cost_posting_id')->get();
        $invalidHistoricalBatchReturns=0;
        foreach($historicBatchQCReturns as $returned){
            if(!$this->historicalBatchReturnCostIsValid($returned,$returned))
                $invalidHistoricalBatchReturns++;
        }
        if($invalidHistoricalBatchReturns)
            $blockers[]=$invalidHistoricalBatchReturns.
                ' approved historical uncapitalized-batch QC-return asset / COGS restorations fail original-currency source, quantity or journal reconciliation.';
        $historicBatchScraps=DB::table('finance_historical_batch_scrap_allocations as source')
            ->join('finance_historical_batch_capitalizations as batch',
                'batch.id','=','source.finance_historical_batch_capitalization_id')
            ->where('batch.status','posted')
            ->whereBetween('source.original_qc_scrap_date',[$begin,$end])
            ->select('source.*','batch.production_batch_id',
                'batch.production_batch_cost_posting_id')->get();
        $invalidBatchScraps=0;
        foreach($historicBatchScraps as $scrap){
            if(!$this->historicalBatchScrapIsValid($scrap,$scrap))
                $invalidBatchScraps++;
        }
        if($invalidBatchScraps)
            $blockers[]=$invalidBatchScraps.
                ' original batch QC-scrapped customer return(s) have missing or mismatched original-currency approved COGS-to-expense journals or source evidence.';

        // Both outcome records may have original QC journal dates in
        // different accounting months. Recheck their entire shared invoice
        // COGS budget in EACH affected original QC period.
        $mixedIds=$historicBatchQCReturns
            ->pluck('finance_historical_batch_capitalization_id')
            ->merge($historicBatchScraps
                ->pluck('finance_historical_batch_capitalization_id'))
            ->unique()->values();
        $invalidMixedQC=0;
        foreach($mixedIds as $id){
            $review=DB::table('finance_historical_batch_capitalizations')
                ->where('id',$id)->first();
            if($review && (int)($review->return_line_count??0)>0 &&
                (int)($review->scrap_line_count??0)>0 &&
                !$this->historicalMixedQcInvoiceCostIsValid($review))
                $invalidMixedQC++;
        }
        if($invalidMixedQC)
            $blockers[]=$invalidMixedQC.
                ' historic customer invoice(s) mix saleable QC returns and scrapped losses whose shared original-currency cost or package rounding no longer reconciles to the original COGS.';
        // The authorized accounting for quarantined goods is a SOURCE
        // HOLD, not an inventory asset or automatic new expense. A later
        // independent QC scrap decision may have a source COGS loss
        // reclassification, which is verified in its original QC month.
        $dispositions=DB::table('finance_historical_customer_return_disposition_reviews')
            ->where(function($query)use($begin,$end){
                $query->where(function($held)use($begin,$end){
                    $held->where('treatment','quarantine_hold')
                        ->where('status','held')
                        ->whereBetween('source_date',[$begin,$end]);
                })->orWhere(function($scrapped)use($begin,$end){
                    $scrapped->where('treatment','scrap_cogs_reclass')
                        ->where('status','posted')
                        ->whereBetween('posting_date',[$begin,$end]);
                });
            })->get();
        $invalidScrapCases=0;$invalidQuarantineHolds=0;
        foreach($dispositions as $plan){
            if($plan->status==='posted'){
                if(!$this->historicalScrapReclassificationIsValid($plan))
                    $invalidScrapCases++;
            }else{
                $case=DB::table('finance_historical_correction_cases')
                    ->where('id',$plan->finance_historical_correction_case_id)->first();
                if($plan->treatment!=='quarantine_hold' ||
                    $plan->journal_entry_id!==null ||
                    $plan->posting_date!==null ||
                    $plan->source_loss_amount!==null ||
                    $plan->loss_ledger_id!==null ||
                    !$case || $case->status!=='resolved' ||
                    !$plan->approved_by || !$plan->submitted_by ||
                    (int)$plan->submitted_by===(int)$plan->approved_by)
                    $invalidQuarantineHolds++;
            }
        }
        if($invalidScrapCases)
            $blockers[]=$invalidScrapCases.
                ' independently signed scrapped customer return COGS reclassification(s) have missing, reversed or incorrect original-cost loss / COGS GL journals.';
        if($invalidQuarantineHolds)
            $blockers[]=$invalidQuarantineHolds.
                ' original customer-credit quarantine hold review(s) contain invalid asset/GL postings or incomplete independent authorization.';
        $unclassifiedScrap=DB::table('sales_return_quarantines as qc')
            ->leftJoin('finance_historical_customer_return_disposition_reviews as financial',
                function($join){
                    $join->on('financial.sales_return_quarantine_id','=','qc.id')
                        ->where('financial.treatment','=','scrap_cogs_reclass')
                        ->where('financial.status','=','posted');
                })
            ->leftJoin('finance_historical_batch_scrap_allocations as recovered',
                function($join){
                    $join->on('recovered.sales_return_quarantine_id','=','qc.id')
                        ->whereNotNull('recovered.journal_entry_id');
                })
            ->where('qc.status','scrapped')
            ->whereBetween('qc.decided_on',[$begin,$end])
            ->whereNull('financial.id')->whereNull('recovered.id')->count();
        if($unclassifiedScrap)
            $warnings[]=$unclassifiedScrap.
                ' QC-scrapped customer return(s) retain their original sales COGS expense without an independently posted returns-loss reclassification. Check the original customer credits and accounting treatment; no automatic expense is required.';
        $orphanDispatches=DB::table('finished_good_dispatch_lines as item')
            ->join('finished_good_dispatches as dispatch',
                'dispatch.id','=','item.finished_good_dispatch_id')
            ->leftJoin('sales_invoice_lines as invoiced',
                'invoiced.dispatch_id','=','dispatch.id')
            ->leftJoin('finance_historical_dispatch_cost_settlements as expense_case',
                function($join){
                    $join->on('expense_case.finished_good_dispatch_id','=','dispatch.id')
                        ->where('expense_case.status','=','posted');
                })
            ->leftJoin('finance_historical_dispatch_cost_lines as costed_expense',
                function($join){
                    $join->on('costed_expense.finance_historical_dispatch_cost_settlement_id','=','expense_case.id')
                        ->on('costed_expense.finished_good_dispatch_line_id','=','item.id');
                })
            ->whereBetween('dispatch.dispatch_date',[$begin,$end])
            ->whereNull('invoiced.id')
            ->whereNull('costed_expense.id')->count();
        if($orphanDispatches){
            $blockers[]=$orphanDispatches.
                ' physical finished-goods dispatch line(s) have neither an invoiced COGS cost trail nor an independently posted non-sale expense journal. Reconcile legacy standalone dispatches.';
        }
        $invalidHistoricalDispatchExpense=DB::table('finance_historical_dispatch_cost_lines as cost')
            ->join('finance_historical_dispatch_cost_settlements as settlement',
                'settlement.id','=','cost.finance_historical_dispatch_cost_settlement_id')
            ->leftJoin('journal_entries as journal','journal.id','=','cost.journal_entry_id')
            ->leftJoin('journal_entries as reverse','reverse.reversal_of_id','=','journal.id')
            ->where('settlement.status','posted')
            ->whereBetween('settlement.posting_date',[$begin,$end])
            ->where(function($query){
                $query->whereNull('journal.id')
                    ->orWhere('journal.status','!=','posted')
                    ->orWhereNotNull('journal.reversal_of_id')
                    ->orWhereNotNull('reverse.id')
                    ->orWhereColumn('journal.currency_id','!=','cost.currency_id')
                    ->orWhereColumn('journal.entry_date','!=','settlement.posting_date');
            })->count();
        if($invalidHistoricalDispatchExpense){
            $blockers[]=$invalidHistoricalDispatchExpense.
                ' source-linked non-sale dispatch expense line(s) lack a valid original-currency posted GL journal.';
        }
        // Historical recoveries must remain linked to their exact source
        // manufacturing GL and *every* original invoice's cost journal.
        // A posted recovery without the full source graph is never ready
        // for period closing, even when its individual journals balance.
        $recoveries=DB::table('finance_historical_batch_capitalizations as record')
            ->leftJoin('production_batch_cost_postings as source',
                'source.id','=','record.production_batch_cost_posting_id')
            ->leftJoin('finance_historical_correction_cases as review',
                'review.id','=','record.finance_historical_correction_case_id')
            ->leftJoin('journal_entries as issue',
                'issue.id','=','source.issue_journal_id')
            ->leftJoin('journal_entries as finished',
                'finished.id','=','source.capitalization_journal_id')
            ->where('record.status','posted')
            ->whereBetween('record.accounting_date',[$begin,$end])
            ->select('record.*','source.production_batch_id as source_batch_id',
                'source.currency_id as source_currency_id',
                'source.total_amount as source_total_amount',
                'source.accounting_date as source_accounting_date',
                'issue.id as issue_id','issue.status as issue_status',
                'issue.reversal_of_id as issue_reversal',
                'finished.id as finished_id','finished.status as finished_status',
                'finished.reversal_of_id as finished_reversal',
                'review.status as case_status')->get();
        $invalidRecovery=0;
        foreach($recoveries as $recovery){
            $valid=$recovery->source_batch_id &&
                (int)$recovery->source_batch_id===(int)$recovery->production_batch_id &&
                (int)$recovery->currency_id===(int)$recovery->source_currency_id &&
                (string)$recovery->accounting_date===(string)$recovery->source_accounting_date &&
                $recovery->case_status==='resolved' &&
                $recovery->issue_status==='posted' && $recovery->finished_status==='posted' &&
                !$recovery->issue_reversal && !$recovery->finished_reversal &&
                $recovery->issue_id && $recovery->finished_id &&
                !DB::table('journal_entries')->whereIn('reversal_of_id',[
                    $recovery->issue_id,$recovery->finished_id])->exists() &&
                BigDecimal::of((string)$recovery->expected_original_cost)
                    ->isEqualTo((string)$recovery->source_total_amount);
            if($valid){
                $matched=DB::table('finished_good_dispatch_lines as physical')
                    ->join('sales_invoice_lines as sale',
                        'sale.dispatch_id','=','physical.finished_good_dispatch_id')
                    ->leftJoin('production_invoice_cogs_postings as cogs',
                        'cogs.sales_invoice_line_id','=','sale.id')
                    ->leftJoin('journal_entries as journal',
                        'journal.id','=','cogs.journal_entry_id')
                    ->where('physical.production_batch_id',$recovery->production_batch_id)
                    ->get(['cogs.production_batch_cost_posting_id','journal.id as journal_id',
                        'journal.status as journal_status','journal.reversal_of_id as journal_reversal']);
                $valid=$matched->count()===(int)$recovery->invoice_line_count;
                foreach($matched as $line){
                    if((int)$line->production_batch_cost_posting_id!==(int)$recovery->production_batch_cost_posting_id ||
                        !$line->journal_id || $line->journal_status!=='posted' ||
                        $line->journal_reversal ||
                        DB::table('journal_entries')->where('reversal_of_id',$line->journal_id)->exists()){
                        $valid=false;break;
                    }
                }
            }
            if($valid && $recovery->dispatch_line_count!==null){
                $approved=DB::table('finance_historical_batch_dispatch_allocations')
                    ->where('finance_historical_batch_capitalization_id',$recovery->id)->get();
                $physicalCount=DB::table('finished_good_dispatch_lines')
                    ->where('production_batch_id',$recovery->production_batch_id)->count();
                $valid=$approved->count()===(int)$recovery->dispatch_line_count &&
                    $physicalCount===(int)$recovery->dispatch_line_count &&
                    (int)$recovery->posted_invoice_cogs_lines===(int)$recovery->invoice_line_count &&
                    (int)$recovery->posted_non_sale_lines===(int)$recovery->non_sale_line_count;
                $saleCount=0;$expenseCount=0;
                foreach($approved as $allocation){
                    $physical=DB::table('finished_good_dispatch_lines as line')
                        ->join('finished_good_dispatches as dispatch',
                            'dispatch.id','=','line.finished_good_dispatch_id')
                        ->where('line.id',$allocation->finished_good_dispatch_line_id)
                        ->select('line.production_batch_id','line.quantity_packages',
                            'dispatch.dispatch_date')->first();
                    $journal=DB::table('journal_entries')
                        ->where('id',$allocation->journal_entry_id)->first();
                    if(!$physical ||
                        (int)$physical->production_batch_id!==(int)$recovery->production_batch_id ||
                        (int)$physical->quantity_packages!==(int)$allocation->quantity_packages ||
                        (string)$physical->dispatch_date!==(string)$allocation->dispatch_date ||
                        (int)$allocation->currency_id!==(int)$recovery->currency_id ||
                        !$journal || $journal->status!=='posted' ||
                        $journal->reversal_of_id ||
                        (int)$journal->currency_id!==(int)$recovery->currency_id ||
                        (string)$journal->entry_date!==(string)$allocation->dispatch_date ||
                        DB::table('journal_entries')
                            ->where('reversal_of_id',$allocation->journal_entry_id)->exists()){
                        $valid=false;break;
                    }
                    if($allocation->sales_invoice_line_id){
                        $saleCount++;
                        $source=DB::table('production_invoice_cogs_postings')
                            ->where('sales_invoice_line_id',$allocation->sales_invoice_line_id)
                            ->first();
                        if($allocation->business_purpose!=='customer_sale' ||
                            $allocation->expense_ledger_id!==null ||
                            $allocation->finance_historical_dispatch_disposition_id!==null ||
                            !$source ||
                            (int)$source->production_batch_cost_posting_id!==(int)$recovery->production_batch_cost_posting_id ||
                            (int)$source->journal_entry_id!==(int)$journal->id ||
                            !BigDecimal::of((string)$allocation->source_cost_amount)
                                ->isEqualTo((string)($source->amount??'0'))){
                            $valid=false;break;
                        }
                    }else{
                        $expenseCount++;
                        $source=DB::table('finance_historical_dispatch_cost_lines as expense')
                            ->join('finance_historical_dispatch_cost_settlements as settlement',
                                'settlement.id','=','expense.finance_historical_dispatch_cost_settlement_id')
                            ->where('expense.finished_good_dispatch_line_id',$allocation->finished_good_dispatch_line_id)
                            ->where('settlement.status','posted')
                            ->select('expense.*','settlement.business_purpose as posted_purpose',
                                'settlement.finance_historical_dispatch_disposition_id as posted_disposition_id')
                            ->first();
                        if(!in_array($allocation->business_purpose,[
                                'promotional_sample','production_loss','internal_consumption',
                            ],true) || !$source ||
                            $source->posted_purpose!==$allocation->business_purpose ||
                            (int)$source->posted_disposition_id!==(int)$allocation->finance_historical_dispatch_disposition_id ||
                            (int)$source->expense_ledger_id!==(int)$allocation->expense_ledger_id ||
                            (int)$source->production_batch_cost_posting_id!==(int)$recovery->production_batch_cost_posting_id ||
                            (int)$source->journal_entry_id!==(int)$journal->id ||
                            !BigDecimal::of((string)$allocation->source_cost_amount)
                                ->isEqualTo((string)$source->source_cost_amount)){
                            $valid=false;break;
                        }
                    }
                }
                if($saleCount!==(int)$recovery->invoice_line_count ||
                    $expenseCount!==(int)$recovery->non_sale_line_count)$valid=false;
            }
            if($valid && $recovery->return_line_count!==null){
                $returns=DB::table('finance_historical_batch_return_allocations')
                    ->where('finance_historical_batch_capitalization_id',$recovery->id)
                    ->get();
                $valid=$returns->count()===(int)$recovery->return_line_count &&
                    (int)$recovery->posted_return_lines===(int)$recovery->return_line_count;
                foreach($returns as $entry){
                    if(!$this->historicalBatchReturnCostIsValid($entry,$recovery)){
                        $valid=false;break;
                    }
                }
            }
            if($valid && $recovery->scrap_line_count!==null){
                $scraps=DB::table('finance_historical_batch_scrap_allocations')
                    ->where('finance_historical_batch_capitalization_id',$recovery->id)
                    ->get();
                $valid=$scraps->count()===(int)$recovery->scrap_line_count &&
                    (int)$recovery->posted_scrap_lines===(int)$recovery->scrap_line_count;
                foreach($scraps as $scrap){
                    if(!$this->historicalBatchScrapIsValid($scrap,$recovery)){
                        $valid=false;break;
                    }
                }
            }
            if($valid && (int)($recovery->return_line_count??0)>0 &&
                (int)($recovery->scrap_line_count??0)>0)
                $valid=$this->historicalMixedQcInvoiceCostIsValid($recovery);
            if(!$valid)$invalidRecovery++;
        }
        if($invalidRecovery)
            $blockers[]=$invalidRecovery.
                ' historical batch capitalization record(s) lack valid and complete original source manufacturing/COGS journals.';
        $invalidProductionJournals=DB::table('production_batch_cost_postings as cost')
            ->join('journal_entries as wip','wip.id','=','cost.issue_journal_id')
            ->join('journal_entries as finished','finished.id','=','cost.capitalization_journal_id')
            ->leftJoin('journal_entries as wip_reversal',
                'wip_reversal.reversal_of_id','=','wip.id')
            ->leftJoin('journal_entries as finished_reversal',
                'finished_reversal.reversal_of_id','=','finished.id')
            ->whereBetween('cost.accounting_date',[$begin,$end])
            ->where(function($q){
                $q->where('wip.status','!=','posted')
                    ->orWhere('finished.status','!=','posted')
                    ->orWhereNotNull('wip.reversal_of_id')
                    ->orWhereNotNull('finished.reversal_of_id')
                    ->orWhereNotNull('wip_reversal.id')
                    ->orWhereNotNull('finished_reversal.id');
            })->distinct()->count('cost.id');
        if($invalidProductionJournals){
            $blockers[]=$invalidProductionJournals.
                ' manufacturing WIP/finished inventory cost posting(s) reference unposted or reversed journals.';
        }
        $invalidCostJournals=DB::table('production_invoice_cogs_postings as cost')
            ->join('journal_entries as journal','journal.id','=','cost.journal_entry_id')
            ->join('sales_invoice_lines as item','item.id','=','cost.sales_invoice_line_id')
            ->join('sales_invoices as invoice','invoice.id','=','item.sales_invoice_id')
            ->leftJoin('journal_entries as reversal',
                'reversal.reversal_of_id','=','journal.id')
            ->whereBetween('invoice.invoice_date',[$begin,$end])
            ->where(function($q){
                $q->where('journal.status','!=','posted')
                    ->orWhereNotNull('journal.reversal_of_id')
                    ->orWhereNotNull('reversal.id');
            })->distinct()->count('cost.id');
        if($invalidCostJournals){
            $blockers[]=$invalidCostJournals.
                ' original-currency sales COGS posting(s) reference unposted or reversed cost journals.';
        }
        $invalidRecoveries=DB::table('production_return_cost_postings as cost')
            ->join('journal_entries as journal','journal.id','=','cost.journal_entry_id')
            ->join('sales_return_quarantines as quarantine',
                'quarantine.id','=','cost.sales_return_quarantine_id')
            ->leftJoin('journal_entries as reversal',
                'reversal.reversal_of_id','=','journal.id')
            ->whereBetween('quarantine.decided_on',[$begin,$end])
            ->where(function($q){
                $q->where('journal.status','!=','posted')
                    ->orWhereNotNull('journal.reversal_of_id')
                    ->orWhereNotNull('reversal.id')
                    ->orWhere('quarantine.status','!=','released');
            })->distinct()->count('cost.id');
        if($invalidRecoveries){
            $blockers[]=$invalidRecoveries.
                ' QC-return cost recovery posting(s) are not backed by a currently released QC return and valid posted journal.';
        }
        $unreviewedOverhead=DB::table('production_overhead_allocations as allocation')
            ->join('production_batches as batch','batch.id','=','allocation.production_batch_id')
            ->join('finished_good_movements as movement',
                'movement.production_batch_id','=','batch.id')
            ->where('movement.type','production_receipt')
            ->whereBetween('movement.occurred_on',[$begin,$end])
            ->where('allocation.status','submitted')->count();
        if($unreviewedOverhead){
            $blockers[]=$unreviewedOverhead.
                ' production cost overhead allocation(s) remain under review for finished batches released this month.';
        }

        $unpostedLanded=DB::table('goods_receipt_landed_costs as cost')
            ->join('goods_receipts as receipt','receipt.id','=','cost.goods_receipt_id')
            ->leftJoin('goods_receipt_landed_cost_postings as posted',
                'posted.goods_receipt_landed_cost_id','=','cost.id')
            ->where(function($q)use($begin,$end){
                $q->whereBetween('receipt.received_on',[$begin,$end])
                  ->orWhereDate('cost.created_at','>=',$begin)
                  ->whereDate('cost.created_at','<=',$end);
            })->whereNull('posted.id')->count();
        if($unpostedLanded){
            $blockers[]=$unpostedLanded.
                ' recorded landed-cost component(s) from this period have no verified expense-to-inventory GL capitalization. Reconcile source expense documents before close.';
        }
        $invalidLanded=DB::table('goods_receipt_landed_cost_postings as posted')
            ->join('journal_entries as journal','journal.id','=','posted.journal_entry_id')
            ->leftJoin('journal_entries as reversal',
                'reversal.reversal_of_id','=','journal.id')
            ->whereBetween('posted.accounting_date',[$begin,$end])
            ->where(function($q){
                $q->where('journal.status','!=','posted')
                    ->orWhereNotNull('journal.reversal_of_id')
                    ->orWhereNotNull('reversal.id');
            })->distinct()->count('posted.id');
        if($invalidLanded){
            $blockers[]=$invalidLanded.
                ' landed-cost capitalization(s) refer to missing, reversed or nonposted inventory GL journals.';
        }

        $pendingLandedReturns=DB::table('purchase_return_records')
            ->whereBetween('returned_on',[$begin,$end])
            ->where('status','pending_landed_cost_review')->count();
        if($pendingLandedReturns){
            $blockers[]=$pendingLandedReturns.
                ' supplier return request(s) containing landed costs have neither independent GL approval nor physical return posting.';
        }
        $uncreditedPhysicalSupplierReturns=DB::table('purchase_return_records')
            ->whereBetween('returned_on',[$begin,$end])
            ->where('status','posted_pending_credit')
            ->where('financial_status','supplier_credit_pending')->count();
        if($uncreditedPhysicalSupplierReturns){
            $blockers[]=$uncreditedPhysicalSupplierReturns.
                ' supplier physical return(s) posted this month still lack a source-priced supplier payable inventory credit. Post a fully reconciled debit note or an independently reviewed correction.';
        }
        $invalidLandedReturns=DB::table('purchase_return_landed_cost_adjustments as adjustment')
            ->join('purchase_return_records as source',
                'source.id','=','adjustment.purchase_return_record_id')
            ->join('journal_entries as journal',
                'journal.id','=','adjustment.journal_entry_id')
            ->leftJoin('journal_entries as reverse',
                'reverse.reversal_of_id','=','journal.id')
            ->whereBetween('source.returned_on',[$begin,$end])
            ->where(function($q){
                $q->where('journal.status','!=','posted')
                    ->orWhereNotNull('journal.reversal_of_id')
                    ->orWhereNotNull('reverse.id')
                    ->orWhere('source.status','!=','posted_pending_credit');
            })->distinct()->count('adjustment.id');
        if($invalidLandedReturns){
            $blockers[]=$invalidLandedReturns.
                ' supplier-return landed-cost expense journal(s) or physical source statuses require reconciliation.';
        }

        $unreviewedOrAbnormalWaste=DB::table('production_material_wastes as waste')
            ->join('production_order_materials as item',
                'item.id','=','waste.production_order_material_id')
            ->leftJoin('production_waste_financial_reviews as treatment',
                'treatment.production_material_waste_id','=','waste.id')
            ->leftJoin('production_abnormal_waste_cost_reviews as value',
                'value.production_material_waste_id','=','waste.id')
            ->leftJoin('production_batches as batch',
                'batch.production_order_id','=','item.production_order_id')
            ->leftJoin('production_batch_cost_postings as posted',
                'posted.production_batch_id','=','batch.id')
            ->whereDate('waste.created_at','>=',$begin)
            ->whereDate('waste.created_at','<=',$end)
            ->where(function($q){
                $q->whereNull('treatment.id')
                  ->orWhere('treatment.treatment','rework_required')
                  ->orWhere(function($part){
                      $part->where('treatment.treatment','abnormal_loss')
                          ->where(function($missing){
                              $missing->whereNull('value.id')
                                  ->orWhereNull('posted.id');
                          });
                  });
            })->count();
        if($unreviewedOrAbnormalWaste){
            $blockers[]=$unreviewedOrAbnormalWaste.
                ' recorded material waste line(s) this month lack independent classification, remain in rework, or have abnormal FIFO costs not yet expensed in an approved batch posting.';
        }

        // A submitted/approved historical source case remains a live
        // reconciliation exception. Only the validated COGS executor
        // can resolve invoice cases automatically; arbitrary historic GL
        // journals do not silently close other exception categories.
        $openingAudit=DB::table('finance_opening_fifo_valuations as valued')
            ->join('journal_entries as journal',
                'journal.id','=','valued.journal_entry_id')
            ->join('inventory_cost_layers as source',
                'source.id','=','valued.inventory_cost_layer_id')
            ->whereBetween('valued.posting_date',[$begin,$end])
            ->select('valued.original_cost','valued.currency_id','valued.journal_entry_id',
                'valued.posting_date','source.original_cost as layer_cost',
                'source.currency_id as layer_currency_id',
                'journal.status','journal.entry_date','journal.currency_id as journal_currency_id',
                'journal.reversal_of_id')->get();
        $invalidOpenings=0;
        foreach($openingAudit as $row){
            if($row->status!=='posted' || $row->reversal_of_id ||
                DB::table('journal_entries')
                    ->where('reversal_of_id',$row->journal_entry_id)->exists() ||
                (int)$row->currency_id!==(int)$row->layer_currency_id ||
                (int)$row->currency_id!==(int)$row->journal_currency_id ||
                (string)$row->entry_date!==(string)$row->posting_date ||
                $row->layer_cost===null ||
                !BigDecimal::of((string)$row->layer_cost)
                    ->isEqualTo((string)$row->original_cost)){
                $invalidOpenings++;
            }
        }
        if($invalidOpenings){
            $blockers[]=$invalidOpenings.
                ' source-linked opening FIFO valuation(s) for this month have reversed/unposted journals or mismatched asset cost and currency.';
        }
        // Documentary evidence about an old FIFO consumption is not
        // permission to invent its source price, update an immutable
        // consumption amount or expense an unrelated batch. Missing
        // original-cost issues must remain a finance closing exception.
        // Validate all posted original-date historic FIFO adjustment journals
        // touching this month. They may be paired with a receipt or issue in
        // another historical month; examine the entire frozen allocation.
        $historicAdjustmentPlans=DB::table('finance_historical_fifo_adjustment_valuations as financial')
            ->join('inventory_cost_layers as lot','lot.id','=','financial.inventory_cost_layer_id')
            ->where('financial.status','posted')
            ->where(function($query)use($begin,$end){
                $query->whereBetween('lot.received_on',[$begin,$end])
                    ->orWhereExists(function($sub)use($begin,$end){
                        $sub->selectRaw('1')
                            ->from('finance_historical_fifo_adjustment_cost_lines as issue')
                            ->whereColumn('issue.finance_historical_fifo_adjustment_valuation_id',
                                'financial.id')
                            ->whereBetween('issue.original_issue_date',[$begin,$end]);
                    });
            })
            ->select('financial.*')->get();
        $invalidHistoricAdjustment=0;
        foreach($historicAdjustmentPlans as $plan){
            if(!$this->sourceHistoricFifoAdjustmentIsValid($plan))
                $invalidHistoricAdjustment++;
        }
        if($invalidHistoricAdjustment)
            $blockers[]=$invalidHistoricAdjustment.
                ' historic consumed opening FIFO correction(s) have unbalanced, reversed, duplicate or wrong-currency original asset/equity or source adjustment-loss journals.';

        $pendingHistoricAdjustment=DB::table('finance_historical_fifo_adjustment_valuations as financial')
            ->join('inventory_cost_layers as lot','lot.id','=','financial.inventory_cost_layer_id')
            ->whereIn('financial.status',['submitted','approved'])
            ->whereBetween('lot.received_on',[$begin,$end])->count();
        if($pendingHistoricAdjustment)
            $blockers[]=$pendingHistoricAdjustment.
                ' original consumed-opening FIFO financial valuation proposal(s) await signed review or actual journal execution. Documentation alone is not a booked source cost.';

        // Independent source-cost approval for historical PRODUCTION FIFO is
        // NOT a raw/WIP/finished-goods/COGS GL posting. The source plan
        // spans the original opening and genuine material issue months;
        // prevent either period from being certified merely because
        // an accountant approved a projected original-currency WIP budget.
        $productionFifoPlans=DB::table('finance_historical_fifo_production_cost_reviews as plan')
            ->join('inventory_cost_layers as lot',
                'lot.id','=','plan.inventory_cost_layer_id')
            ->where(function($query)use($begin,$end){
                $query->whereBetween('lot.received_on',[$begin,$end])
                    ->orWhereExists(function($sub)use($begin,$end){
                        $sub->selectRaw('1')
                            ->from('finance_historical_fifo_production_cost_lines as line')
                            ->whereColumn('line.finance_historical_fifo_production_cost_review_id',
                                'plan.id')
                            ->whereBetween('line.original_material_issue_date',[$begin,$end]);
                    });
            })->select('plan.*')->get();
        $pendingProductionWip=0;$invalidProductionSource=0;
        foreach($productionFifoPlans as $sourcePlan){
            if(!app(HistoricalProductionFifoSourceCostReviewService::class)
                ->audit((int)$sourcePlan->id))
                $invalidProductionSource++;
            if(in_array($sourcePlan->status,['submitted','approved'],true))
                $pendingProductionWip++;
        }
        if($invalidProductionSource)
            $blockers[]=$invalidProductionSource.
                ' original production FIFO source-cost proposal(s) have changed physical issue, manufacturing snapshot, currency or independent authorization evidence.';
        if($pendingProductionWip)
            $blockers[]=$pendingProductionWip.
                ' historical production FIFO original-currency WIP plans are SOURCE REVIEW ONLY, not posted cost: original raw inventory, production WIP, released finished goods and any genuine invoiced COGS need a single separately authorized original-date financial recovery before this accounting month can close.';

        // A fully posted source chain must reconcile on each original
        // opening, material issue, finished production and real customer
        // invoice month; pending chains must not close any of those periods.
        $productionRecoveries=DB::table('finance_historical_fifo_production_recoveries as recovery')
            ->where(function($query)use($begin,$end){
                $query->whereBetween('recovery.original_opening_date',[$begin,$end])
                    ->orWhereBetween('recovery.original_issue_date',[$begin,$end])
                    ->orWhereBetween('recovery.original_finished_date',[$begin,$end])
                    ->orWhereExists(function($sub)use($begin,$end){
                        $sub->selectRaw('1')
                            ->from('production_invoice_cogs_postings as cogs')
                            ->join('sales_invoice_lines as sold',
                                'sold.id','=','cogs.sales_invoice_line_id')
                            ->join('sales_invoices as invoice',
                                'invoice.id','=','sold.sales_invoice_id')
                            ->whereColumn('sold.production_batch_id',
                                'recovery.production_batch_id')
                            ->whereBetween('invoice.invoice_date',[$begin,$end]);
                    });
            })->select('recovery.*')->get();
        $badProductionGL=0;$awaitingProductionGL=0;
        foreach($productionRecoveries as $recovery){
            if($recovery->status==='posted'){
                if(!app(HistoricalProductionFifoRecoveryService::class)
                    ->auditPosted((int)$recovery->id))
                    $badProductionGL++;
            }elseif(in_array($recovery->status,['submitted','approved'],true))
                $awaitingProductionGL++;
        }
        if($badProductionGL)
            $blockers[]=$badProductionGL.
                ' source-backed historic original production FIFO raw/equity, WIP, QC finished-goods or genuine sale COGS entries are missing, altered, reversed or original-date mismatched.';
        if($awaitingProductionGL)
            $blockers[]=$awaitingProductionGL.
                ' independently signed original production FIFO recovery plan(s) still need a separate authorized all-or-nothing raw/WIP/finished/COGS posting. Document review alone is not financial capitalization.';

        $unvaluedOpeningIssues=DB::table('inventory_cost_consumptions as consumed')
            ->join('inventory_cost_layers as lot',
                'lot.id','=','consumed.inventory_cost_layer_id')
            ->join('inventory_stock_movements as issue',
                'issue.id','=','consumed.inventory_stock_movement_id')
            ->join('inventory_stock_movements as opening',
                'opening.id','=','lot.source_id')
            ->where('lot.source_type','inventory_stock_movement')
            ->where('opening.type','opening')
            ->where('opening.direction','in')
            ->leftJoin('finance_historical_fifo_adjustment_cost_lines as cost',
                'cost.inventory_cost_consumption_id','=','consumed.id')
            ->leftJoin('finance_historical_fifo_adjustment_valuations as recovery',
                'recovery.id','=','cost.finance_historical_fifo_adjustment_valuation_id')
            ->leftJoin('finance_historical_fifo_production_cost_lines as produced',
                'produced.inventory_cost_consumption_id','=','consumed.id')
            ->leftJoin('finance_historical_fifo_production_cost_reviews as plan',
                'plan.id','=','produced.finance_historical_fifo_production_cost_review_id')
            ->leftJoin('finance_historical_fifo_production_recoveries as execution',
                'execution.finance_historical_fifo_production_cost_review_id','=','plan.id')
            ->whereBetween('issue.occurred_on',[$begin,$end])
            ->where(function($query){
                $query->whereNull('lot.original_cost')
                    ->orWhereNull('consumed.amount')
                    ->orWhere('consumed.is_valued',false);
            })
            ->where(function($query){
                $query->where(function($adjustment){
                    $adjustment->whereNull('cost.journal_entry_id')
                        ->orWhereNull('recovery.id')
                        ->orWhere('recovery.status','!=','posted');
                })->where(function($production){
                    $production->whereNull('produced.historical_wip_journal_id')
                        ->orWhereNull('plan.id')
                        ->orWhere('plan.status','!=','posted')
                        ->orWhereNull('execution.id')
                        ->orWhere('execution.status','!=','posted');
                });
            })->distinct()->count('consumed.id');
        if($unvaluedOpeningIssues)
            $blockers[]=$unvaluedOpeningIssues.
                ' original physical opening FIFO issue(s) remain missing verified source-currency cost in this month. Independent warehouse/consumption provenance is documentary only and cannot substitute for a separately approved original-cost GL correction.';

        $reviewedLocations=DB::table('finance_legacy_fifo_source_reviews as review')
            ->leftJoin('inventory_legacy_lot_assignments as location',
                'location.id','=','review.inventory_legacy_lot_assignment_id')
            ->where('review.review_type','locate_remaining')
            ->where('review.status','approved')
            ->whereDate('review.reviewed_at','>=',$begin)
            ->whereDate('review.reviewed_at','<=',$end)
            ->select('review.*',
                'location.inventory_cost_layer_id as assigned_layer_id',
                'location.company_warehouse_id as assigned_warehouse_id',
                'location.quantity as assigned_quantity',
                'location.assigned_by as location_actor_id')->get();
        $invalidLegacyLocations=0;
        foreach($reviewedLocations as $review){
            $valid=$review->inventory_legacy_lot_assignment_id &&
                $review->assigned_layer_id &&
                (int)$review->assigned_layer_id===(int)$review->inventory_cost_layer_id &&
                (int)$review->assigned_warehouse_id===(int)$review->company_warehouse_id &&
                (int)$review->reviewed_by===(int)$review->location_actor_id &&
                (int)$review->requested_by!==(int)$review->reviewed_by &&
                \Brick\Math\BigDecimal::of((string)($review->assigned_quantity??'0'))
                    ->isEqualTo((string)$review->quantity);
            if(!$valid)$invalidLegacyLocations++;
        }
        if($invalidLegacyLocations)
            $blockers[]=$invalidLegacyLocations.
                ' signed historical FIFO location assignment(s) lack a matching independently approved source record, reviewer or original quantity.';
        $openHistorical=DB::table('finance_historical_correction_cases')
            ->whereBetween('source_date',[$begin,$end])
            ->whereIn('status',['submitted','approved'])->count();
        if($openHistorical){
            $blockers[]=$openHistorical.
                ' source-linked historical correction case(s) remain open for original documents dated in this month. Complete independently approved source reconciliation before closing.';
        }

        $bankAccounts=DB::table('financial_accounts')
            ->where('type','bank')->where('is_active',true)->get(['id','name']);
        foreach($bankAccounts as $account){
            $covered=DB::table('bank_reconciliation_periods')
                ->where('financial_account_id',$account->id)
                ->where('status','approved')
                ->where('period_start','<=',$begin)
                ->where('period_end','>=',$end)->exists();
            if(!$covered){
                $warnings[]='Bank account '.$account->name.
                    ' has no single approved reconciliation covering the entire month. Verify statement coverage before signing the close.';
            }
        }
        $unreconciledAssets=DB::table('fixed_assets as asset')
            ->leftJoin('fixed_asset_capitalizations as link',
                'link.fixed_asset_id','=','asset.id')
            ->whereBetween('asset.acquired_on',[$begin,$end])
            ->whereNull('link.id')->count();
        if($unreconciledAssets){
            $warnings[]=$unreconciledAssets.' fixed asset registration(s) acquired this month are not reconciled to an original posted PPE debit.';
        }

        return [
            'month'=>$month,'begin'=>$begin,'end'=>$end,
            'blockers'=>$blockers,'warnings'=>$warnings,
            'can_close'=>$start->endOfMonth()->toDateString()<today()->toDateString() && !$blockers,
        ];
    }
}

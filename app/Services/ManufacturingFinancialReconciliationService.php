<?php

namespace App\Services;

use App\Models\ProductionBatch;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only subledger-to-GL integrity audit for original-currency production
 * cost postings. Never calculates a cross-currency total or adjusts source
 * documents to force them to balance.
 */
class ManufacturingFinancialReconciliationService
{
    private function decimal(mixed $amount):BigDecimal
    {
        return BigDecimal::of((string)($amount??'0'));
    }

    private function journalIsValid(int $id):bool
    {
        $entry=DB::table('journal_entries')->where('id',$id)->first();
        return $entry && $entry->status==='posted' && $entry->reversal_of_id===null &&
            !DB::table('journal_entries')->where('reversal_of_id',$id)->exists();
    }

    /** Debit-positive, credit-negative for an exact GL ledger account. */
    private function ledgerNet(int $journalId,int $ledgerId):BigDecimal
    {
        $net=BigDecimal::zero();
        foreach(DB::table('journal_lines')
            ->where('journal_entry_id',$journalId)
            ->where('ledger_account_id',$ledgerId)
            ->get(['debit','credit']) as $line){
            $net=$net->plus((string)$line->debit)->minus((string)$line->credit);
        }
        return $net;
    }

    public function rows(Collection $batches):Collection
    {
        if($batches->isEmpty())return collect();
        $ids=$batches->pluck('id')->all();
        $posted=DB::table('production_batch_cost_postings')
            ->whereIn('production_batch_id',$ids)->get()->keyBy('production_batch_id');
        $policies=DB::table('manufacturing_cost_ledger_policies')
            ->whereIn('id',$posted->pluck('manufacturing_cost_ledger_policy_id'))
            ->get()->keyBy('id');
        $cogs=DB::table('production_invoice_cogs_postings')
            ->whereIn('production_batch_cost_posting_id',$posted->pluck('id'))
            ->get()->groupBy('production_batch_cost_posting_id');
        $nonSale=DB::table('finance_historical_dispatch_cost_lines as expense')
            ->join('finance_historical_dispatch_cost_settlements as settlement',
                'settlement.id','=','expense.finance_historical_dispatch_cost_settlement_id')
            ->whereIn('expense.production_batch_cost_posting_id',$posted->pluck('id'))
            ->where('settlement.status','posted')
            ->select('expense.*','settlement.business_purpose',
                'settlement.finished_good_dispatch_id',
                'settlement.finance_historical_dispatch_disposition_id')
            ->get()->groupBy('production_batch_cost_posting_id');
        $sourceIds=$cogs->flatten()->pluck('id');
        $returns=DB::table('production_return_cost_postings')
            ->whereIn('production_invoice_cogs_posting_id',$sourceIds)
            ->get()->groupBy('production_invoice_cogs_posting_id');
        $invoices=DB::table('sales_invoice_lines')
            ->whereIn('production_batch_id',$ids)->get()->groupBy('production_batch_id');
        $dispatches=DB::table('finished_good_dispatch_lines')
            ->whereIn('production_batch_id',$ids)->get()->groupBy('production_batch_id');
        $warehouseReceipts=DB::table('finished_good_movements')
            ->whereIn('production_batch_id',$ids)
            ->where('type','production_receipt')
            ->get()->groupBy('production_batch_id');
        $releasedReturns=DB::table('sales_return_quarantines')
            ->whereIn('production_batch_id',$ids)
            ->where('status','released')->get()->groupBy('production_batch_id');
        $legacyRestocks=DB::table('sales_credit_note_lines as credit')
            ->leftJoin('sales_return_quarantines as quarantine',
                'quarantine.sales_credit_note_line_id','=','credit.id')
            ->whereIn('credit.production_batch_id',$ids)
            ->where('credit.restocked',true)->whereNull('quarantine.id')
            ->select('credit.production_batch_id','credit.quantity_packages')
            ->get()->groupBy('production_batch_id');
        $wasteTreatments=DB::table('production_material_wastes as waste')
            ->join('production_order_materials as item',
                'item.id','=','waste.production_order_material_id')
            ->leftJoin('production_waste_financial_reviews as review',
                'review.production_material_waste_id','=','waste.id')
            ->leftJoin('production_abnormal_waste_cost_reviews as cost',
                'cost.production_material_waste_id','=','waste.id')
            ->whereIn('item.production_order_id',$batches->pluck('production_order_id'))
            ->select('item.production_order_id','waste.id','review.treatment',
                'cost.source_cost_amount','cost.expense_ledger_id','cost.currency_id')
            ->get()->groupBy('production_order_id');
        $result=collect();
        foreach($batches as $batch){
            $issues=[];$capital=$posted->get($batch->id);
            $originalPackages=(int)$batch->accepted_packages;
            $received=0;
            foreach($warehouseReceipts->get($batch->id,collect()) as $receipt){
                $received+=(int)$receipt->quantity_packages;
            }
            $physical=0;$sold=0;$recovered=0;$restoredCost=BigDecimal::zero();
            $costedCost=BigDecimal::zero();
            $nonSaleQty=0;$nonSaleCost=BigDecimal::zero();
            foreach($dispatches->get($batch->id,collect()) as $movement){
                $physical+=(int)$movement->quantity_packages;
            }
            $returnQty=0;
            foreach($releasedReturns->get($batch->id,collect()) as $return){
                $returnQty+=(int)$return->quantity_packages;
            }
            $legacyQty=0;
            foreach($legacyRestocks->get($batch->id,collect()) as $return){
                $legacyQty+=(int)$return->quantity_packages;
            }
            if(!$capital && ($received>0 || $physical || $invoices->has($batch->id))){
                $issues[]='Physically received, dispatched or invoiced batch has no source-backed financial capitalization.';
            }
            $wasteRows=$wasteTreatments->get($batch->production_order_id,collect());
            if($wasteRows->contains(fn($row)=>$row->treatment===null)){
                $issues[]='Original production material loss has no independent finance review. Reconcile the issued waste source and signed manufacturing policy.';
            }
            if($wasteRows->contains(fn($row)=>
                $row->treatment==='rework_required' ||
                ($row->treatment==='abnormal_loss' && $row->source_cost_amount===null))){
                $issues[]='Abnormal loss lacks an exact FIFO cost approval, or rework remains unresolved.';
            }
            $policy=null;$amount=null;$currency=null;
            if($capital){
                $policy=$policies->get($capital->manufacturing_cost_ledger_policy_id);
                $currency=DB::table('currencies')->where('id',$capital->currency_id)->value('code');
                $amount=$this->decimal($capital->total_amount);
                if(!$policy || $policy->status!=='approved' ||
                    (int)$capital->currency_id!==(int)($policy->currency_id??0)){
                    $issues[]='The original manufacturing ledger policy is missing, unapproved or has a different source currency.';
                }
                if((int)$capital->accepted_packages!==$originalPackages){
                    $issues[]='Accepted physical packages no longer match the original posted capitalization quantity.';
                }
                if((int)$capital->accepted_packages!==$received){
                    $issues[]='Physical warehouse receipts differ from the accepted package count financially capitalized.';
                }
                $abnormalAmount=$this->decimal($capital->abnormal_waste_amount);
                if(!$this->decimal($capital->material_amount)
                    ->plus((string)$capital->overhead_amount)
                    ->minus($abnormalAmount)->isEqualTo($amount)){
                    $issues[]='Capitalized value must equal issued materials plus approved overhead less independently expensed abnormal loss.';
                }
                $approvedAbnormal=BigDecimal::zero();
                $byLedger=[];
                foreach($wasteRows as $waste){
                    if($waste->treatment!=='abnormal_loss' || $waste->source_cost_amount===null)continue;
                    $approvedAbnormal=$approvedAbnormal->plus((string)$waste->source_cost_amount);
                    $id=(int)$waste->expense_ledger_id;
                    $byLedger[$id]=($byLedger[$id]??BigDecimal::zero())
                        ->plus((string)$waste->source_cost_amount);
                    if((int)$waste->currency_id!==(int)$capital->currency_id){
                        $issues[]='An abnormal loss source uses a different currency than the batch costing policy.';
                    }
                }
                if(!$approvedAbnormal->toScale(4,\Brick\Math\RoundingMode::HalfUp)
                    ->isEqualTo($abnormalAmount)){
                    $issues[]='The batch abnormal loss expense differs from its independently approved lot-cost sources.';
                }
                if(!$this->journalIsValid((int)$capital->issue_journal_id) ||
                    !$this->journalIsValid((int)$capital->capitalization_journal_id)){
                    $issues[]='A manufacturing WIP or finished-inventory journal is missing, reversed or unposted.';
                }elseif($policy){
                    $wip=(int)$policy->work_in_progress_ledger_id;
                    $raw=(int)$policy->material_inventory_ledger_id;
                    $fg=(int)$policy->finished_inventory_ledger_id;
                    if(!$this->ledgerNet((int)$capital->issue_journal_id,$wip)->isEqualTo($amount) ||
                       !$this->ledgerNet((int)$capital->issue_journal_id,$raw)
                            ->isEqualTo($this->decimal($capital->material_amount)->negated()) ||
                       !$this->ledgerNet((int)$capital->capitalization_journal_id,$fg)->isEqualTo($amount) ||
                       !$this->ledgerNet((int)$capital->capitalization_journal_id,$wip)->isEqualTo($amount->negated())){
                        $issues[]='Posted manufacturing journal inventory or WIP account balance does not reconcile to its frozen cost snapshot.';
                    }
                    $lossPosted=BigDecimal::zero();
                    foreach($byLedger as $expenseLedgerId=>$originalSourceCost){
                        $ledgerAmount=$this->ledgerNet(
                            (int)$capital->issue_journal_id,$expenseLedgerId
                        );
                        if(!$ledgerAmount->isPositive()){
                            $issues[]='An abnormal loss expense ledger lacks its original approved journal debit.';
                        }
                        $lossPosted=$lossPosted->plus($ledgerAmount);
                    }
                    if(!$lossPosted->isEqualTo($abnormalAmount)){
                        $issues[]='Posted abnormal loss expense does not reconcile to the total FIFO loss cost excluded from finished-goods inventory.';
                    }
                }
                $postings=$cogs->get($capital->id,collect());
                foreach($postings as $posting){
                    $invoiceLine=DB::table('sales_invoice_lines')
                        ->where('id',$posting->sales_invoice_line_id)->first();
                    if(!$invoiceLine ||
                       (int)$invoiceLine->production_batch_id!==(int)$batch->id ||
                       (int)$invoiceLine->quantity_packages!==(int)$posting->quantity_packages){
                        $issues[]='A COGS source record has an invoice batch or package count mismatch.';
                    }
                    $sold+=(int)$posting->quantity_packages;
                    $costedCost=$costedCost->plus((string)$posting->amount);
                    if(!$this->journalIsValid((int)$posting->journal_entry_id)){
                        $issues[]='An original COGS journal is missing, reversed or not posted.';
                    }elseif($policy &&
                        (!$this->ledgerNet((int)$posting->journal_entry_id,(int)$policy->cogs_ledger_id)
                            ->isEqualTo((string)$posting->amount) ||
                         !$this->ledgerNet((int)$posting->journal_entry_id,(int)$policy->finished_inventory_ledger_id)
                            ->isEqualTo($this->decimal($posting->amount)->negated()))){
                        $issues[]='A posted COGS debit and finished-inventory credit do not match their invoice-cost record.';
                    }
                    foreach($returns->get($posting->id,collect()) as $recovery){
                        $recovered+=(int)$recovery->quantity_packages;
                        $restoredCost=$restoredCost->plus((string)$recovery->amount);
                        $quarantine=DB::table('sales_return_quarantines')
                            ->where('id',$recovery->sales_return_quarantine_id)->first();
                        if(!$quarantine || $quarantine->status!=='released' ||
                           (int)$quarantine->production_batch_id!==(int)$batch->id ||
                           (int)$quarantine->quantity_packages!==(int)$recovery->quantity_packages){
                            $issues[]='A returned COGS recovery differs from its independently released physical QC return.';
                        }
                        if(!$this->journalIsValid((int)$recovery->journal_entry_id)){
                            $issues[]='A QC-return inventory recovery journal is missing, unposted or reversed.';
                        }elseif($policy &&
                            (!$this->ledgerNet((int)$recovery->journal_entry_id,(int)$policy->finished_inventory_ledger_id)
                                ->isEqualTo((string)$recovery->amount) ||
                             !$this->ledgerNet((int)$recovery->journal_entry_id,(int)$policy->cogs_ledger_id)
                                ->isEqualTo($this->decimal($recovery->amount)->negated()))){
                            $issues[]='A posted QC-return inventory debit and COGS credit differ from its source recovery.';
                        }
                    }
                }
                foreach($nonSale->get($capital->id,collect()) as $expense){
                    $physicalLine=DB::table('finished_good_dispatch_lines')
                        ->where('id',$expense->finished_good_dispatch_line_id)->first();
                    $signed=DB::table('finance_historical_dispatch_dispositions')
                        ->where('id',$expense->finance_historical_dispatch_disposition_id)->first();
                    if(!$physicalLine ||
                        (int)$physicalLine->production_batch_id!==(int)$batch->id ||
                        (int)$physicalLine->finished_good_dispatch_id!==(int)$expense->finished_good_dispatch_id ||
                        (int)$physicalLine->quantity_packages!==(int)$expense->quantity_packages ||
                        !$signed ||
                        (int)$signed->finished_good_dispatch_id!==(int)$expense->finished_good_dispatch_id ||
                        $signed->business_purpose!==$expense->business_purpose ||
                        !in_array($expense->business_purpose,[
                            'promotional_sample','production_loss','internal_consumption',
                        ],true)){
                        $issues[]='One historical non-sale expense lacks its signed original physical dispatch and quantity.';
                    }
                    $nonSaleQty+=(int)$expense->quantity_packages;
                    $nonSaleCost=$nonSaleCost->plus((string)$expense->source_cost_amount);
                    $account=DB::table('ledger_accounts')
                        ->where('id',$expense->expense_ledger_id)->first();
                    if((int)$expense->currency_id!==(int)$capital->currency_id ||
                        !$account || $account->type!=='expense' ||
                        (int)$expense->expense_ledger_id===(int)($policy?->cogs_ledger_id??0) ||
                        !$this->journalIsValid((int)$expense->journal_entry_id) ||
                        !$policy ||
                        !$this->ledgerNet((int)$expense->journal_entry_id,
                            (int)$expense->expense_ledger_id)
                            ->isEqualTo((string)$expense->source_cost_amount) ||
                        !$this->ledgerNet((int)$expense->journal_entry_id,
                            (int)$policy->finished_inventory_ledger_id)
                            ->isEqualTo($this->decimal($expense->source_cost_amount)->negated())){
                        $issues[]='A posted non-sale batch expense fails original currency, purpose-specific expense debit or finished-inventory credit GL reconciliation.';
                    }
                }
                if($invoices->get($batch->id,collect())->count()!==$postings->count()){
                    $issues[]='Some sales invoice lines do not have exactly one original-currency COGS posting.';
                }
            }
            $netPhysical=$received+$returnQty+$legacyQty-$physical;
            $netCosted=$sold+$nonSaleQty-$recovered;
            $bookPackages=$capital?($originalPackages-$netCosted):null;
            $remaining=$amount?->minus($costedCost)->minus($nonSaleCost)->plus($restoredCost);
            if($netPhysical<0 || ($capital && $bookPackages<0)){
                $issues[]='Physical or costed finished-goods stock is negative.';
            }
            if($capital && $physical!==$sold+$nonSaleQty){
                $issues[]='Gross physical dispatches do not equal packages charged to authentic sales COGS plus approved non-sale batch expenses.';
            }
            if($capital && $returnQty!==$recovered){
                $issues[]='QC-restocked packages do not match costed return asset recoveries.';
            }
            if($capital && $netPhysical!==$bookPackages){
                $issues[]='The physical and financially costed remaining package balances differ.';
            }
            if($legacyQty>0)$issues[]='Legacy direct restock lacks independently reconciled source-cost recovery.';
            if($remaining && $remaining->isNegative()){
                $issues[]='Calculated finished-inventory asset value is negative.';
            }
            $result->push([
                'batch_id'=>$batch->id,'batch'=>$batch->batch_number,
                'product'=>$batch->finishedGood?->name??'Unknown',
                'status'=>$batch->status,'currency'=>$currency,
                'accepted'=>$originalPackages,'received'=>$received,'dispatched'=>$physical,
                'qc_returns'=>$returnQty,'costed_sales'=>$sold,
                'costed_non_sale'=>$nonSaleQty,
                'costed_recoveries'=>$recovered,'net_physical'=>$netPhysical,
                'remaining_costed'=>$bookPackages,
                'gross_material_source_cost'=>$capital?(string)$this->decimal($capital->material_amount)->toScale(4):null,
                'abnormal_waste_expensed'=>$capital?(string)$this->decimal($capital->abnormal_waste_amount)->toScale(4):null,
                'total_capitalized'=>$amount?(string)$amount->toScale(4):null,
                'posted_cogs'=>$capital?(string)$costedCost->toScale(4):null,
                'posted_non_sale_expense'=>$capital?(string)$nonSaleCost->toScale(4):null,
                'returned_cost'=>$capital?(string)$restoredCost->toScale(4):null,
                'remaining_asset_value'=>$remaining?(string)$remaining->toScale(4):null,
                'issues'=>array_values(array_unique($issues)),
                'has_capitalization'=>(bool)$capital,
            ]);
        }
        return $result;
    }
}

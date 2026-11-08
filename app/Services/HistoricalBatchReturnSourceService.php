<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Immutable original source and cost projections for legacy QC-released
 * customer credits in a NOT YET financially capitalized production batch.
 *
 * Requires real, previously posted customer revenue and credit documents,
 * signed independent QC, and the exact existing warehouse return movement.
 * Never uses selling currency or price as manufacturing inventory cost.
 */
final class HistoricalBatchReturnSourceService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['historical_batch_returns'=>$message]);
    }

    private function journal(int $id,int $currencyId,string $date): object
    {
        $journal=DB::table('journal_entries')->where('id',$id)->first();
        if(!$journal || $journal->status!=='posted' || $journal->reversal_of_id ||
            (int)$journal->currency_id!==$currencyId ||
            (string)$journal->entry_date!==$date ||
            DB::table('journal_entries')->where('reversal_of_id',$id)->exists())
            $this->fail('Original posted credit or invoice GL journal is missing, reversed, dated differently or uses the wrong original sales currency.');
        return $journal;
    }

    private function net(int $journalId,int $ledgerId): BigDecimal
    {
        $total=BigDecimal::zero();
        foreach(DB::table('journal_lines')->where('journal_entry_id',$journalId)
            ->where('ledger_account_id',$ledgerId)->get(['debit','credit']) as $line)
            $total=$total->plus((string)$line->debit)->minus((string)$line->credit);
        return $total;
    }

    /**
     * $salesCost: invoice line ID => approved original-currency COGS estimate
     * from this same frozen manufacturing batch cost allocation.
     *
     * @return array<int,array<string,mixed>> sorted by original physical QC date
     */
    public function snapshot(int $batchId,Collection $dispatches,array $salesCost,
        int $currencyId,string $capitalizationDate): array
    {
        $invoiceIds=array_map('intval',array_keys($salesCost));
        $sourceCredits=DB::table('sales_credit_note_lines')
            ->where('production_batch_id',$batchId)->orderBy('id')->get();
        $returns=DB::table('sales_return_quarantines')
            ->where('production_batch_id',$batchId)->get();
        if($sourceCredits->isEmpty()){
            if($returns->isNotEmpty())
                $this->fail('Original QC return records without a real source customer credit note cannot enter historical batch capitalization.');
            return [];
        }
        if($sourceCredits->count()>100 || $sourceCredits->count()!==$returns->count())
            $this->fail('Every original customer credit line must have exactly one independently QC-released and physically restocked return, maximum 100 per batch.');
        $qcByCredit=$returns->keyBy('sales_credit_note_line_id');
        if($qcByCredit->count()!==$sourceCredits->count())
            $this->fail('Duplicate or missing independently received credit-note QC return records.');
        $now=now()->toDateString();
        $result=[];$qtyByInvoice=[];
        foreach($sourceCredits as $credit){
            $qc=$qcByCredit->get($credit->id);
            $sale=DB::table('sales_invoice_lines')->where('id',$credit->sales_invoice_line_id)->first();
            $invoice=$sale?DB::table('sales_invoices')
                ->where('id',$sale->sales_invoice_id)->first():null;
            $note=DB::table('sales_credit_notes')
                ->where('id',$credit->sales_credit_note_id)->first();
            if(!$qc || !$sale || !$note || !$invoice ||
                $invoice->status!=='posted' ||
                !in_array((int)$sale->id,$invoiceIds,true) ||
                (int)$credit->production_batch_id!==$batchId ||
                (int)$sale->production_batch_id!==$batchId ||
                (int)$credit->finished_good_id!==(int)$sale->finished_good_id ||
                (int)$note->sales_invoice_id!==(int)$invoice->id ||
                (int)$note->trade_document_id!==(int)$invoice->trade_document_id ||
                (string)$note->currency_code!==(string)$invoice->currency_code ||
                (bool)$credit->restocked ||
                (int)$credit->quantity_packages<1 ||
                (int)$credit->quantity_packages>(int)$sale->quantity_packages ||
                !BigDecimal::of((string)$sale->unit_price)
                    ->isEqualTo((string)$credit->unit_price) ||
                !BigDecimal::of((string)$credit->line_total)
                    ->isEqualTo(
                        BigDecimal::of((string)$credit->unit_price)
                            ->multipliedBy((string)$credit->quantity_packages)
                            ->toScale(4,RoundingMode::HalfUp)
                    ))
                $this->fail('Customer return is not matched to one genuine, posted, original-price invoice, credit note and approved physical batch dispatch.');
            $physical=$dispatches->first(
                fn($line)=>(int)($line->invoice_line_id??0)===(int)$sale->id
            );
            if(!$physical ||
                (int)$physical->invoiced_packages!==(int)$sale->quantity_packages ||
                (string)$physical->dispatch_date!==(string)$invoice->invoice_date)
                $this->fail('Original customer credit references a different sold batch, physical source date or package quantity.');
            $qcDate=substr((string)$qc->decided_on,0,10);
            if($qc->status!=='released' ||
                !$qcDate || strlen($qcDate)!==10 ||
                $qcDate<$capitalizationDate || $qcDate>$now ||
                $qcDate<(string)$invoice->invoice_date ||
                $qcDate<(string)$note->credit_date ||
                !$qc->registered_by || !$qc->decided_by ||
                (int)$qc->registered_by===(int)$qc->decided_by ||
                !$qc->quality_reference || !$qc->decision_notes ||
                !$qc->released_warehouse_id ||
                (int)$qc->production_batch_id!==$batchId ||
                (int)$qc->sales_credit_note_line_id!==(int)$credit->id ||
                (int)$qc->quantity_packages!==(int)$credit->quantity_packages)
                $this->fail('Only an independently signed, already QC-released original return to a known warehouse on a valid date can restore historical finished-stock assets.');
            $movements=DB::table('finished_good_warehouse_movements')
                ->where('production_batch_id',$batchId)
                ->where('type','customer_return')
                ->where('reference','Quarantined customer return QC '.$qc->id)
                ->get();
            if($movements->count()!==1 ||
                (int)$movements->first()->quantity_packages!==(int)$qc->quantity_packages ||
                (int)$movements->first()->destination_warehouse_id!==(int)$qc->released_warehouse_id ||
                $movements->first()->source_warehouse_id!==null ||
                (int)$movements->first()->created_by!==(int)$qc->decided_by ||
                (string)$movements->first()->occurred_on!==$qcDate)
                $this->fail('The original customer return must have exactly one signed, source-linked, quantity/date/warehouse-matched physical stock movement. No inventory will be created.');
            if(DB::table('production_return_cost_postings')
                ->where('sales_return_quarantine_id',$qc->id)->exists() ||
                DB::table('finance_historical_customer_return_cost_recoveries')
                ->where('sales_return_quarantine_id',$qc->id)->exists() ||
                DB::table('finance_historical_correction_cases')
                ->where('open_key','released_return_missing_cost:'.$qc->id)->exists())
                $this->fail('This original QC return already has a cost posting or a separate pending financial case and cannot be valued twice.');

            $this->journal((int)$invoice->journal_entry_id,
                (int)$invoice->currency_id,(string)$invoice->invoice_date);
            $this->journal((int)$note->journal_entry_id,
                (int)$invoice->currency_id,(string)$note->credit_date);
            $customerLedger=DB::table('entity_ledger_accounts')->where([
                'entity_type'=>'customer','entity_id'=>$invoice->customer_id,
                'currency_id'=>$invoice->currency_id,'purpose'=>'receivable',
            ])->value('ledger_account_id');
            $document=DB::table('trade_documents')
                ->where('id',$invoice->trade_document_id)->first();
            if(!$customerLedger || !$document ||
                $document->type!=='receivable' ||
                (int)$document->trade_party_id!==(int)$invoice->customer_id ||
                (int)$document->currency_id!==(int)$invoice->currency_id ||
                (int)$document->journal_entry_id!==(int)$invoice->journal_entry_id ||
                !BigDecimal::of((string)$document->amount)
                    ->isEqualTo((string)$invoice->total) ||
                !$this->net((int)$invoice->journal_entry_id,(int)$customerLedger)
                    ->isEqualTo((string)$invoice->total) ||
                !$this->net((int)$note->journal_entry_id,(int)$customerLedger)
                    ->isEqualTo(BigDecimal::of((string)$note->total)->negated()))
                $this->fail('Existing original customer receivable, posted sale and matching credit note must be real, correctly linked and reconciled before factory-cost backfill.');
            $incomes=DB::table('journal_lines as line')
                ->join('ledger_accounts as ledger','ledger.id','=','line.ledger_account_id')
                ->where('line.journal_entry_id',$invoice->journal_entry_id)
                ->where('ledger.type','income')
                ->get(['line.ledger_account_id','line.credit','line.debit']);
            if($incomes->count()!==1 ||
                !BigDecimal::of((string)$incomes->first()->credit)
                    ->minus((string)$incomes->first()->debit)
                    ->isEqualTo((string)$invoice->total) ||
                !$this->net((int)$note->journal_entry_id,
                    (int)$incomes->first()->ledger_account_id)
                    ->isEqualTo((string)$note->total))
                $this->fail('Original customer credit must debit the same genuine income ledger originally credited by that customer invoice.');

            $noteLines=DB::table('sales_credit_note_lines')
                ->where('sales_credit_note_id',$note->id)->get(['line_total']);
            $noteTotal=BigDecimal::zero();
            foreach($noteLines as $noteLine)
                $noteTotal=$noteTotal->plus((string)$noteLine->line_total);
            if(!$noteTotal->isPositive() ||
                !$noteTotal->isEqualTo((string)$note->total))
                $this->fail('The original posted customer credit-note header and all original lines must balance.');

            $qtyByInvoice[(int)$sale->id]=($qtyByInvoice[(int)$sale->id]??0)
                +(int)$qc->quantity_packages;
            if($qtyByInvoice[(int)$sale->id]>(int)$sale->quantity_packages)
                $this->fail('Historic customer returns exceed the actual source-invoiced physical batch quantity.');

            $result[]=[
                'qc_id'=>(int)$qc->id,
                'credit_line_id'=>(int)$credit->id,
                'sales_invoice_line_id'=>(int)$sale->id,
                'date'=>$qcDate,'quantity'=>(int)$qc->quantity_packages,
                'currency_id'=>$currencyId,
                'original_sale_cost'=>(string)$salesCost[(int)$sale->id],
                'source'=>[
                    'credit'=>[$credit->id,$credit->sales_credit_note_id,
                        $credit->sales_invoice_line_id,$credit->quantity_packages,
                        $credit->unit_price,$credit->line_total,$credit->restocked],
                    'note'=>[$note->id,$note->number,$note->sales_invoice_id,
                        $note->trade_document_id,$note->journal_entry_id,
                        $note->credit_date,$note->total,$note->currency_code,
                        $note->created_by],
                    'sale'=>[$sale->id,$sale->sales_invoice_id,$sale->dispatch_id,
                        $sale->production_batch_id,$sale->quantity_packages,
                        $sale->unit_price,$sale->line_total],
                    'invoice'=>[$invoice->id,$invoice->status,$invoice->invoice_date,
                        $invoice->currency_id,$invoice->total,
                        $invoice->journal_entry_id,$invoice->trade_document_id,
                        $invoice->customer_id],
                    'qc'=>[$qc->id,$qc->production_batch_id,$qc->status,
                        $qc->registered_by,$qc->decided_by,$qc->decided_on,
                        $qc->quality_reference,$qc->decision_notes,
                        $qc->quantity_packages,$qc->received_warehouse_id,
                        $qc->released_warehouse_id],
                    'warehouse'=>$movements->first(),
                    'source_income_ledger_id'=>$incomes->first()->ledger_account_id,
                    'source_receivable_ledger_id'=>$customerLedger,
                ],
                'actors'=>array_values(array_unique(array_filter([
                    (int)$qc->registered_by,(int)$qc->decided_by,
                    (int)($note->created_by??0),
                ]))),
            ];
        }
        usort($result,fn($a,$b)=>[$a['date'],$a['qc_id']]
            <=>[$b['date'],$b['qc_id']]);
        $used=[];
        foreach($result as &$row){
            $id=$row['sales_invoice_line_id'];
            $prior=$used[$id]??['qty'=>0,'cost'=>BigDecimal::zero()];
            $allQty=(int)$dispatches->first(
                fn($line)=>(int)($line->invoice_line_id??0)===$id
            )->quantity_packages;
            $orig=BigDecimal::of($row['original_sale_cost']);
            $qty=$row['quantity'];
            $amount=$prior['qty']+$qty===$allQty
                ?$orig->minus($prior['cost'])
                :$orig->multipliedBy((string)$qty)
                    ->dividedBy((string)$allQty,4,RoundingMode::HalfUp);
            if(!$amount->isPositive() ||
                $amount->isGreaterThan($orig->minus($prior['cost'])))
                $this->fail('Original customer-return cost fraction exceeds the invoice source COGS or precision.');
            $used[$id]=['qty'=>$prior['qty']+$qty,
                'cost'=>$prior['cost']->plus($amount)];
            $row['expected_cost']=(string)$amount->toScale(4,RoundingMode::HalfUp);
            $row['fingerprint']=hash('sha256',json_encode([
                'source'=>$row['source'],'original_sale_cost'=>$row['original_sale_cost'],
                'expected_return_cost'=>$row['expected_cost'],
                'prior_return_qty'=>$prior['qty'],
                'prior_return_cost'=>(string)$prior['cost'],
            ],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
        }
        unset($row);
        return $result;
    }
}

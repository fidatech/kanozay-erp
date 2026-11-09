<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Read-only source snapshot of independently QC-scrapped customer credits
 * for a released factory batch whose manufacturing and invoice COGS have
 * not yet been capitalized. No imaginary source costs or stock movements.
 */
final class HistoricalBatchScrapSourceService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['historical_batch_scrap'=>$message]);
    }

    private function journal(int $id,int $currencyId,string $date): void
    {
        $gl=DB::table('journal_entries')->where('id',$id)->first();
        if(!$gl || $gl->status!=='posted' || $gl->reversal_of_id ||
            (int)$gl->currency_id!==$currencyId ||
            (string)$gl->entry_date!==$date ||
            DB::table('journal_entries')->where('reversal_of_id',$id)->exists() ||
            DB::table('journal_lines')->where('journal_entry_id',$id)->count()!==2)
            $this->fail('The original sale/credit journal must be posted, unreversed, original-date, correct currency and limited to genuine revenue and receivable lines.');
    }

    private function net(int $journalId,int $ledgerId): BigDecimal
    {
        $result=BigDecimal::zero();
        foreach(DB::table('journal_lines')->where('journal_entry_id',$journalId)
            ->where('ledger_account_id',$ledgerId)->get(['debit','credit']) as $line)
            $result=$result->plus((string)$line->debit)
                ->minus((string)$line->credit);
        return $result;
    }

    public function snapshot(int $batchId,Collection $dispatches,array $saleCosts,
        int $factoryCurrencyId,string $capitalizationDate): array
    {
        $qcs=DB::table('sales_return_quarantines')
            ->where('production_batch_id',$batchId)
            ->where('status','scrapped')->orderBy('id')->get();
        if($qcs->isEmpty())return [];
        if($qcs->count()>100)
            $this->fail('A maximum of 100 original source-evidenced QC scrap lines may be reconciled in one manufacturing batch.');
        $now=now()->toDateString();
        $rows=[];$soldQuantities=[];
        foreach($qcs as $qc){
            $credit=DB::table('sales_credit_note_lines')
                ->where('id',$qc->sales_credit_note_line_id)->first();
            $note=$credit?DB::table('sales_credit_notes')
                ->where('id',$credit->sales_credit_note_id)->first():null;
            $sale=$credit?DB::table('sales_invoice_lines')
                ->where('id',$credit->sales_invoice_line_id)->first():null;
            $invoice=$sale?DB::table('sales_invoices')
                ->where('id',$sale->sales_invoice_id)->first():null;
            if(!$credit || !$note || !$sale || !$invoice ||
                $invoice->status!=='posted' ||
                !array_key_exists((int)$sale->id,$saleCosts) ||
                (int)$sale->production_batch_id!==$batchId ||
                (int)$credit->production_batch_id!==$batchId ||
                (int)$credit->finished_good_id!==(int)$sale->finished_good_id ||
                (int)$note->sales_invoice_id!==(int)$invoice->id ||
                (int)$note->trade_document_id!==(int)$invoice->trade_document_id ||
                (string)$note->currency_code!==(string)$invoice->currency_code ||
                (bool)$credit->restocked ||
                (int)$credit->quantity_packages<1 ||
                (int)$credit->quantity_packages>(int)$sale->quantity_packages ||
                (int)$qc->quantity_packages!==(int)$credit->quantity_packages ||
                (int)$qc->production_batch_id!==$batchId ||
                (int)$qc->sales_credit_note_line_id!==(int)$credit->id ||
                !BigDecimal::of((string)$credit->unit_price)
                    ->isEqualTo((string)$sale->unit_price) ||
                !BigDecimal::of((string)$credit->line_total)->isEqualTo(
                    BigDecimal::of((string)$sale->unit_price)
                        ->multipliedBy((string)$credit->quantity_packages)
                        ->toScale(4,RoundingMode::HalfUp)
                ))
                $this->fail('Signed QC scrap is missing an authentic posted customer sale and exact original-price credit for the same factory batch and quantities.');

            $physical=$dispatches->first(fn($row)=>
                (int)($row->invoice_line_id??0)===(int)$sale->id);
            if(!$physical ||
                (int)$physical->invoiced_packages!==(int)$sale->quantity_packages ||
                (int)$physical->invoiced_batch_id!==$batchId ||
                (string)$physical->dispatch_date!==(string)$invoice->invoice_date)
                $this->fail('The scrapped customer credit does not match one genuine original finished-batch invoice/physical dispatch.');

            $qcDate=substr((string)$qc->decided_on,0,10);
            if(!$qcDate || strlen($qcDate)!==10 ||
                $qcDate<$capitalizationDate || $qcDate>$now ||
                $qcDate<(string)$note->credit_date ||
                $qcDate<(string)$invoice->invoice_date ||
                !$qc->registered_by || !$qc->decided_by ||
                (int)$qc->registered_by===(int)$qc->decided_by ||
                !$qc->quality_reference || !$qc->decision_notes ||
                $qc->released_warehouse_id!==null)
                $this->fail('Original scrapping needs a real, dated and independently signed QC condemnation, later than the genuine customer credit and batch capitalization, with no release to saleable inventory.');

            $warehouse=DB::table('finished_good_warehouse_movements')
                ->where('production_batch_id',$batchId)
                ->where('type','customer_return')
                ->where('reference','Quarantined customer return QC '.$qc->id);
            if($warehouse->exists() ||
                DB::table('production_return_cost_postings')
                    ->where('sales_return_quarantine_id',$qc->id)->exists() ||
                DB::table('finance_historical_customer_return_cost_recoveries')
                    ->where('sales_return_quarantine_id',$qc->id)->exists() ||
                DB::table('finance_historical_customer_return_disposition_reviews')
                    ->where('sales_return_quarantine_id',$qc->id)
                    ->whereNotIn('status',['held','rejected'])->exists() ||
                DB::table('finance_historical_correction_cases')
                    ->where('open_key','customer_return_disposition:'.$qc->id)->exists())
                $this->fail('Scrapped return has saleable warehouse stock, a separately posted asset/COGS/loss recovery or an unresolved competing finance case.');

            $allCredits=DB::table('sales_credit_note_lines')
                ->where('sales_invoice_line_id',$sale->id)->get();
            if($allCredits->sum('quantity_packages')>(int)$sale->quantity_packages)
                $this->fail('Original customer credit-note quantities exceed the genuine original invoice packages.');

            $document=DB::table('trade_documents')
                ->where('id',$invoice->trade_document_id)->first();
            $receivable=DB::table('entity_ledger_accounts')->where([
                'entity_type'=>'customer',
                'entity_id'=>$invoice->customer_id,
                'currency_id'=>$invoice->currency_id,
                'purpose'=>'receivable',
            ])->value('ledger_account_id');
            if(!$document || !$receivable || $document->type!=='receivable' ||
                (int)$document->trade_party_id!==(int)$invoice->customer_id ||
                (int)$document->currency_id!==(int)$invoice->currency_id ||
                (int)$document->journal_entry_id!==(int)$invoice->journal_entry_id ||
                !BigDecimal::of((string)$document->amount)
                    ->isEqualTo((string)$invoice->total))
                $this->fail('Source customer trade receivable does not match the original genuine posted sale.');

            $this->journal((int)$invoice->journal_entry_id,
                (int)$invoice->currency_id,(string)$invoice->invoice_date);
            $this->journal((int)$note->journal_entry_id,
                (int)$invoice->currency_id,(string)$note->credit_date);
            if(!$this->net((int)$invoice->journal_entry_id,(int)$receivable)
                    ->isEqualTo((string)$invoice->total) ||
                !$this->net((int)$note->journal_entry_id,(int)$receivable)
                    ->isEqualTo(BigDecimal::of((string)$note->total)->negated()))
                $this->fail('Genuine original customer receivable and credit-note journals do not reconcile in selling currency.');

            $income=DB::table('journal_lines as line')
                ->join('ledger_accounts as ledger','ledger.id','=','line.ledger_account_id')
                ->where('line.journal_entry_id',$invoice->journal_entry_id)
                ->where('ledger.type','income')
                ->get(['line.ledger_account_id','line.credit','line.debit']);
            if($income->count()!==1 ||
                !BigDecimal::of((string)$income->first()->credit)
                    ->minus((string)$income->first()->debit)
                    ->isEqualTo((string)$invoice->total) ||
                !$this->net((int)$note->journal_entry_id,
                    (int)$income->first()->ledger_account_id)
                    ->isEqualTo((string)$note->total))
                $this->fail('Historical customer credit must debit the genuine original income ledger, never an unrelated GL account.');
            $noteLines=DB::table('sales_credit_note_lines')
                ->where('sales_credit_note_id',$note->id)->get(['line_total']);
            $sum=BigDecimal::zero();
            foreach($noteLines as $line)$sum=$sum->plus((string)$line->line_total);
            if(!$sum->isPositive() || !$sum->isEqualTo((string)$note->total))
                $this->fail('Original source credit note lines do not balance to the genuine posted total.');

            $soldQuantities[(int)$sale->id]=($soldQuantities[(int)$sale->id]??0)
                +(int)$credit->quantity_packages;
            if($soldQuantities[(int)$sale->id]>(int)$sale->quantity_packages)
                $this->fail('Previously QC-scrapped package count exceeds its original genuine invoice.');

            // Both authentic outcomes may now occur on one invoice line.
            // Their source records are independently verified here and by
            // HistoricalBatchReturnSourceService, then all original-currency
            // cost fractions and rounding are reconciled together by
            // HistoricalBatchMixedQcAllocationService before approval.
            $rows[]=[
                'qc_id'=>(int)$qc->id,
                'credit_line_id'=>(int)$credit->id,
                'sales_invoice_line_id'=>(int)$sale->id,
                'date'=>$qcDate,
                'quantity'=>(int)$credit->quantity_packages,
                'currency_id'=>$factoryCurrencyId,
                'original_sale_cost'=>(string)$saleCosts[(int)$sale->id],
                'source'=>[
                    'qc'=>[$qc->id,$qc->status,$qc->registered_by,$qc->decided_by,
                        $qc->decided_on,$qc->received_warehouse_id,
                        $qc->released_warehouse_id,$qc->quality_reference,
                        $qc->decision_notes,$qc->quantity_packages],
                    'credit'=>[$credit->id,$credit->sales_credit_note_id,
                        $credit->sales_invoice_line_id,$credit->finished_good_id,
                        $credit->production_batch_id,$credit->quantity_packages,
                        $credit->unit_price,$credit->line_total,$credit->restocked],
                    'note'=>[$note->id,$note->number,$note->journal_entry_id,
                        $note->sales_invoice_id,$note->trade_document_id,
                        $note->credit_date,$note->currency_code,$note->total,
                        $note->created_by],
                    'sale'=>[$sale->id,$sale->sales_invoice_id,$sale->dispatch_id,
                        $sale->production_batch_id,$sale->quantity_packages,
                        $sale->unit_price,$sale->line_total],
                    'invoice'=>[$invoice->id,$invoice->status,$invoice->customer_id,
                        $invoice->invoice_date,$invoice->currency_id,
                        $invoice->journal_entry_id,$invoice->trade_document_id,
                        $invoice->total],
                    'receivable_ledger'=>$receivable,
                    'income_ledger'=>$income->first()->ledger_account_id,
                    'recorded_customer_return_stock_count'=>$warehouse->count(),
                ],
                'actors'=>array_values(array_unique(array_filter([
                    (int)$qc->registered_by,(int)$qc->decided_by,
                    (int)($note->created_by??0),
                ]))),
            ];
        }
        usort($rows,fn($a,$b)=>[$a['date'],$a['qc_id']]<=>[$b['date'],$b['qc_id']]);
        $used=[];
        foreach($rows as &$row){
            $id=$row['sales_invoice_line_id'];
            $qty=$row['quantity'];
            $prior=$used[$id]??['qty'=>0,'cost'=>BigDecimal::zero()];
            $sold=$dispatches->first(
                fn($physical)=>(int)($physical->invoice_line_id??0)===$id);
            $fullQty=(int)$sold->quantity_packages;
            $total=BigDecimal::of($row['original_sale_cost']);
            $amount=$prior['qty']+$qty===$fullQty
                ?$total->minus($prior['cost'])
                :$total->multipliedBy((string)$qty)
                    ->dividedBy((string)$fullQty,4,RoundingMode::HalfUp);
            if(!$amount->isPositive() || $amount->isGreaterThan($total->minus($prior['cost'])))
                $this->fail('QC-scrapped returned units have no remaining authentic original factory COGS to reclassify.');
            $row['amount']=(string)$amount->toScale(4,RoundingMode::HalfUp);
            $row['fingerprint']=hash('sha256',json_encode([
                'source'=>$row['source'],
                'original_factory_cogs'=>$row['original_sale_cost'],
                'source_currency_id'=>$factoryCurrencyId,
                'prior_scrap_qty'=>$prior['qty'],
                'prior_scrap_value'=>(string)$prior['cost'],
                'reclassified_original_cost'=>$row['amount'],
            ],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));
            $used[$id]=['qty'=>$prior['qty']+$qty,'cost'=>$prior['cost']->plus($amount)];
        }
        unset($row);
        return $rows;
    }
}

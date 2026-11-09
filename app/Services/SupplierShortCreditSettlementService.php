<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Historical source-priced supplier returns with partial/zero supplier credit. */
final class SupplierShortCreditSettlementService
{
    public function __construct(private readonly JournalPostingService $posting) {}

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['short_credit'=>$message]);
    }

    private function source(int $caseId, int $documentId): array
    {
        $case=DB::table('finance_historical_correction_cases')->where('id',$caseId)->lockForUpdate()->first();
        abort_unless($case,404);
        if($case->case_type!=='supplier_return_unreconciled' || $case->status!=='approved')
            $this->fail('Only a still-approved historical supplier credit case may be settled.');
        $snapshot=app(HistoricalFinanceCorrectionService::class)
            ->source($case->case_type,(int)$case->source_id);
        if(!hash_equals((string)$case->source_fingerprint,$snapshot['fingerprint']))
            $this->fail('Original source return changed after historical approval.');
        $dispute=DB::table('finance_supplier_return_credit_disputes')
            ->where('finance_historical_correction_case_id',$caseId)->lockForUpdate()->first();
        if(!$dispute || (int)$dispute->purchase_return_record_id!==(int)$case->source_id ||
            $dispute->requested_treatment==='unresolved')
            $this->fail('Document a signed supplier claim or potential loss decision before settlement.');
        $return=DB::table('purchase_return_records')->where('id',$case->source_id)->lockForUpdate()->first();
        if(!$return || $return->status!=='posted_pending_credit' ||
            $return->financial_status!=='supplier_credit_pending' ||
            (int)$dispute->currency_id!==(int)$return->currency_id ||
            DB::table('supplier_debit_notes')->where('purchase_return_record_id',$return->id)->exists())
            $this->fail('This source return has already been settled or changed.');
        $order=DB::table('purchase_orders')->where('id',$return->purchase_order_id)->first();
        $document=DB::table('trade_documents')->where('id',$documentId)->lockForUpdate()->first();
        if(!$order || !$document || $document->type!=='payable' ||
            (int)$document->trade_party_id!==(int)$order->supplier_id ||
            (int)$document->currency_id!==(int)$return->currency_id)
            $this->fail('Select a same-supplier, same-currency original purchase payable.');
        $invoice=DB::table('matched_supplier_invoices')
            ->where('goods_receipt_id',$return->goods_receipt_id)
            ->where('trade_document_id',$documentId)->where('status','posted')->first();
        if(!$invoice || (int)$invoice->purchase_order_id!==(int)$return->purchase_order_id ||
            (int)$invoice->supplier_id!==(int)$order->supplier_id ||
            (int)$invoice->currency_id!==(int)$return->currency_id)
            $this->fail('Only posted, source-line-matched supplier invoices can fund this historical GL correction.');
        foreach(array_unique([(int)$invoice->journal_entry_id,(int)$document->journal_entry_id]) as $id){
            $j=DB::table('journal_entries')->where('id',$id)->first();
            if(!$j || $j->status!=='posted' || $j->reversal_of_id ||
                (int)$j->currency_id!==(int)$return->currency_id ||
                DB::table('journal_entries')->where('reversal_of_id',$id)->exists())
                $this->fail('Original supplier payable/inventory accounting entry is unposted or reversed.');
        }
        // A real supplier invoice must have already booked enough original
        // source-currency inventory debit and payable credit to support the
        // historical physical goods return. A posted but unrelated journal
        // cannot silently fund a supplier claim or nonrecoverable loss.
        $sourceInvoiceGL=DB::table('journal_entries')
            ->where('id',$invoice->journal_entry_id)->first();
        if(!$sourceInvoiceGL ||
            (string)$sourceInvoiceGL->entry_date>
                substr((string)$return->returned_on,0,10))
            $this->fail('Original supplier purchase inventory/liability journal cannot occur after the actual dated physical return.');

        $lines=DB::table('purchase_return_record_lines')->where('purchase_return_record_id',$return->id)->get();
        $billed=DB::table('matched_supplier_invoice_lines')
            ->where('matched_supplier_invoice_id',$invoice->id)->get()->keyBy('goods_receipt_line_id');
        if($lines->isEmpty())$this->fail('Original return must contain priced physical lines.');
        $base=BigDecimal::zero();
        foreach($lines as $line){
            $match=$billed->get($line->goods_receipt_line_id);
            if(!$match || $line->unit_price_snapshot===null ||
                $line->supplier_credit_value_snapshot===null ||
                !BigDecimal::of((string)$line->quantity)->isPositive() ||
                BigDecimal::of((string)$match->quantity)->isLessThan((string)$line->quantity) ||
                !BigDecimal::of((string)$match->unit_price_snapshot)->isEqualTo((string)$line->unit_price_snapshot) ||
                (int)$match->inventory_material_id!==(int)$line->inventory_material_id ||
                !BigDecimal::of((string)$line->supplier_credit_value_snapshot)->isEqualTo(
                    BigDecimal::of((string)$line->quantity)->multipliedBy((string)$line->unit_price_snapshot)))
                $this->fail('Returned quantities, original supplier prices and source matched invoice lines disagree.');
            $base=$base->plus((string)$line->supplier_credit_value_snapshot);
        }
        $base=$base->toScale(4,RoundingMode::HalfUp);
        $recognizedInventory=BigDecimal::zero();
        foreach(DB::table('journal_lines')
            ->where('journal_entry_id',$sourceInvoiceGL->id)
            ->where('ledger_account_id',$invoice->inventory_ledger_id)
            ->get(['debit','credit']) as $row){
            $recognizedInventory=$recognizedInventory
                ->plus((string)$row->debit)->minus((string)$row->credit);
        }
        $originalSourcePayable=DB::table('entity_ledger_accounts')->where([
            'entity_type'=>'supplier','entity_id'=>$order->supplier_id,
            'currency_id'=>$return->currency_id,'purpose'=>'payable',
        ])->value('ledger_account_id');
        if(!$originalSourcePayable)
            $this->fail('The original posted supplier invoice has no mapped same-currency payable ledger.');
        $recognizedPayable=BigDecimal::zero();
        foreach(DB::table('journal_lines')
            ->where('journal_entry_id',$sourceInvoiceGL->id)
            ->where('ledger_account_id',$originalSourcePayable)
            ->get(['debit','credit']) as $row){
            $recognizedPayable=$recognizedPayable
                ->plus((string)$row->credit)->minus((string)$row->debit);
        }
        if($recognizedInventory->isLessThan($base) ||
            $recognizedPayable->isLessThan($base))
            $this->fail('Original matched supplier invoice source GL must contain enough raw-inventory debit and supplier payable credit to cover the authenticated physical returned base cost; no invented or duplicated payable credit is allowed.');
        $offered=BigDecimal::of((string)$dispute->supplier_offered_credit);
        $difference=$base->minus($offered);
        if(!$base->isPositive() || $offered->isNegative() || !$difference->isPositive() ||
            !$base->isEqualTo((string)$dispute->source_base_return_value) ||
            !$difference->isEqualTo((string)$dispute->unresolved_difference))
            $this->fail('The signed supplier offer or shortfall no longer reconciles to original purchase value.');
        $remaining=BigDecimal::of((string)$document->amount);
        foreach(DB::table('trade_settlement_allocations')->where('trade_document_id',$documentId)->get() as $a)
            $remaining=$remaining->minus((string)$a->amount);
        foreach(DB::table('trade_document_credits')->where('trade_document_id',$documentId)->get() as $c)
            $remaining=$remaining->minus((string)$c->amount);
        if($remaining->isNegative() || $offered->isGreaterThan($remaining))
            $this->fail('Accepted supplier credit exceeds unpaid original supplier invoice balance.');
        $payable=DB::table('entity_ledger_accounts')->where([
            'entity_type'=>'supplier','entity_id'=>$order->supplier_id,
            'currency_id'=>$return->currency_id,'purpose'=>'payable',
        ])->value('ledger_account_id');
        $inventory=LedgerAccount::whereKey($invoice->inventory_ledger_id)->first();
        $ap=$payable ? LedgerAccount::whereKey($payable)->first() : null;
        if(!$inventory || !$inventory->is_active || $inventory->is_group || $inventory->type!=='asset' ||
            DB::table('entity_ledger_accounts')->where('ledger_account_id',$inventory->id)->exists() ||
            !$ap || !$ap->is_active || $ap->is_group || (int)$inventory->id===(int)$ap->id)
            $this->fail('The matched invoice inventory asset or mapped supplier payable account is not valid.');
        return compact('case','dispute','return','document','invoice','base','offered',
            'difference','inventory','payable');
    }

    private function account(int $id,string $treatment,array $source): void
    {
        $required=$source['dispute']->requested_treatment==='supplier_claim_pending'
            ? 'recognized_supplier_claim' : 'approved_nonrecoverable_loss';
        if($treatment!==$required)
            $this->fail('The proposal must follow the original signed supplier dispute treatment.');
        $ledger=LedgerAccount::whereKey($id)->first();
        $type=$treatment==='recognized_supplier_claim'?'asset':'expense';
        if(!$ledger || !$ledger->is_active || $ledger->is_group || $ledger->type!==$type ||
            (int)$source['inventory']->id===$id || (int)$source['payable']===$id ||
            DB::table('entity_ledger_accounts')->where('ledger_account_id',$id)->exists())
            $this->fail('Select a distinct active, unmapped and independently authorized claim asset or loss expense ledger.');
    }

    private function unchanged(object $p,array $s): void
    {
        if((int)$p->purchase_return_record_id!==(int)$s['return']->id ||
            (int)$p->finance_supplier_return_credit_dispute_id!==(int)$s['dispute']->id ||
            (int)$p->currency_id!==(int)$s['return']->currency_id ||
            (int)$p->inventory_ledger_id!==(int)$s['inventory']->id ||
            (int)$p->payable_ledger_id!==(int)$s['payable'] ||
            !BigDecimal::of((string)$p->source_base_return_value)->isEqualTo($s['base']) ||
            !BigDecimal::of((string)$p->supplier_offered_credit)->isEqualTo($s['offered']) ||
            !BigDecimal::of((string)$p->unresolved_difference)->isEqualTo($s['difference']))
            $this->fail('Original source amount, account or currency changed after proposal.');
    }

    public function submit(int $caseId,array $data,int $actor): object
    {
        return DB::transaction(function()use($caseId,$data,$actor){
            $s=$this->source($caseId,(int)$data['trade_document_id']);
            if(in_array($actor,[(int)$s['case']->requested_by,(int)$s['dispute']->reviewed_by],true))
                $this->fail('The settlement proposer must be independent from the case requester and dispute reviewer.');
            if(DB::table('finance_supplier_short_credit_settlements')
                ->where('purchase_return_record_id',$s['return']->id)->exists())
                $this->fail('A unique settlement already exists for this physical return.');
            $this->account((int)$data['difference_ledger_id'],$data['treatment'],$s);
            if($data['posting_date']<$s['return']->returned_on ||
                $data['posting_date']>now()->toDateString())
                $this->fail('Journal date cannot predate return or be in the future.');
            $id=DB::table('finance_supplier_short_credit_settlements')->insertGetId([
                'finance_historical_correction_case_id'=>$caseId,
                'finance_supplier_return_credit_dispute_id'=>$s['dispute']->id,
                'purchase_return_record_id'=>$s['return']->id,
                'trade_document_id'=>$s['document']->id,
                'currency_id'=>$s['return']->currency_id,
                'inventory_ledger_id'=>$s['inventory']->id,
                'payable_ledger_id'=>$s['payable'],
                'difference_ledger_id'=>$data['difference_ledger_id'],
                'treatment'=>$data['treatment'],'posting_date'=>$data['posting_date'],
                'source_base_return_value'=>(string)$s['base'],
                'supplier_offered_credit'=>(string)$s['offered'],
                'unresolved_difference'=>(string)$s['difference'],
                'settlement_reference'=>trim($data['settlement_reference']),
                'recognition_evidence'=>trim($data['recognition_evidence']),
                'status'=>'submitted','submitted_by'=>$actor,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('finance.supplier_short_credit.submitted',
                'finance_supplier_short_credit_settlement',$id,
                'Original-currency finance settlement awaiting independent approval; no GL posting.');
            return DB::table('finance_supplier_short_credit_settlements')->where('id',$id)->first();
        });
    }

    public function approve(int $caseId,array $data,int $actor): object
    {
        return DB::transaction(function()use($caseId,$data,$actor){
            $p=DB::table('finance_supplier_short_credit_settlements')
                ->where('finance_historical_correction_case_id',$caseId)->lockForUpdate()->first();
            abort_unless($p,404);
            if($p->status!=='submitted')$this->fail('Only a submitted unposted settlement can be reviewed.');
            $s=$this->source($caseId,(int)$p->trade_document_id);
            if(in_array($actor,[(int)$p->submitted_by,(int)$s['case']->requested_by,
                (int)$s['dispute']->reviewed_by],true))
                $this->fail('The settlement approver must be independent of earlier reviewers and proposer.');
            $this->unchanged($p,$s);
            $this->account((int)$p->difference_ledger_id,$p->treatment,$s);
            $approved=$data['decision']==='approve';
            DB::table('finance_supplier_short_credit_settlements')->where('id',$p->id)->update([
                'status'=>$approved?'approved':'rejected',
                'approval_reference'=>trim($data['approval_reference']),
                'approval_notes'=>trim($data['approval_notes']),
                'approved_by'=>$actor,'approved_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('finance.supplier_short_credit.'.($approved?'approved':'rejected'),
                'finance_supplier_short_credit_settlement',$p->id,
                'Independent financial recognition decision recorded without GL posting.');
            return DB::table('finance_supplier_short_credit_settlements')->where('id',$p->id)->first();
        });
    }

    public function post(int $caseId,int $actor): object
    {
        return DB::transaction(function()use($caseId,$actor){
            $period=app(FinancePeriodClosingService::class);
            $period->holdPostingGate();
            $p=DB::table('finance_supplier_short_credit_settlements')
                ->where('finance_historical_correction_case_id',$caseId)->lockForUpdate()->first();
            abort_unless($p,404);
            if($p->status!=='approved' || $p->journal_entry_id)
                $this->fail('Only an approved unposted settlement may create a journal.');
            if(in_array($actor,[(int)$p->submitted_by,(int)$p->approved_by],true))
                $this->fail('The settlement executor must be independent of the proposer and approver.');
            $s=$this->source($caseId,(int)$p->trade_document_id);
            if((int)$s['case']->requested_by===$actor)
                $this->fail('Original case requester cannot execute the settlement.');
            $this->unchanged($p,$s);
            $this->account((int)$p->difference_ledger_id,$p->treatment,$s);
            if($p->posting_date<$s['return']->returned_on ||
                $p->posting_date>now()->toDateString())
                $this->fail('Proposed financial date must follow the physical return.');
            $period->assertOpenDate((string)$p->posting_date);
            $number='HSC-'.str_pad((string)$p->id,7,'0',STR_PAD_LEFT);
            $journal=JournalEntry::create([
                'number'=>$number,'entry_date'=>$p->posting_date,
                'reference'=>$s['case']->case_number,
                'description'=>'Approved supplier return short credit '.$s['return']->number.
                    ' / '.$p->settlement_reference,
                'currency_id'=>$s['return']->currency_id,
                'status'=>'draft','created_by'=>$actor,
            ]);
            $lines=[
                ['ledger_account_id'=>$p->difference_ledger_id,
                    'debit'=>(string)$s['difference'],'credit'=>'0',
                    'memo'=>'Independently recognized supplier claim / nonrecoverable loss'],
                ['ledger_account_id'=>$p->inventory_ledger_id,
                    'debit'=>'0','credit'=>(string)$s['base'],
                    'memo'=>'Reverse full source-priced physical return inventory'],
            ];
            if($s['offered']->isPositive())
                $lines[]=['ledger_account_id'=>$p->payable_ledger_id,
                    'debit'=>(string)$s['offered'],'credit'=>'0',
                    'memo'=>'Actual offered supplier payable credit'];
            $journal->lines()->createMany($lines);
            $this->posting->post($journal,$actor);
            DB::table('finance_supplier_short_credit_settlements')->where('id',$p->id)->update([
                'journal_entry_id'=>$journal->id,'status'=>'posted',
                'posted_by'=>$actor,'posted_at'=>now(),'updated_at'=>now(),
            ]);
            if($s['offered']->isPositive())
                DB::table('trade_document_credits')->insert([
                    'trade_document_id'=>$p->trade_document_id,
                    'sales_credit_note_id'=>null,'supplier_debit_note_id'=>null,
                    'supplier_short_credit_settlement_id'=>$p->id,
                    'credit_date'=>$p->posting_date,'amount'=>(string)$s['offered'],
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            DB::table('purchase_return_records')->where('id',$s['return']->id)->update([
                'financial_status'=>'supplier_short_credit_posted','updated_at'=>now(),
            ]);
            DB::table('finance_historical_correction_cases')->where('id',$caseId)->update([
                'status'=>'resolved','open_key'=>null,
                'resolution_reference'=>$p->settlement_reference,
                'resolution_notes'=>$p->recognition_evidence,
                'executed_by'=>$actor,'executed_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('finance_historical_correction_events')->insert([
                'finance_historical_correction_case_id'=>$caseId,'action'=>'resolved',
                'source_reference'=>$s['case']->source_reference,
                'source_fingerprint'=>$s['case']->source_fingerprint,
                'approval_reference'=>$p->approval_reference,
                'notes'=>'Journal '.$number.': supplier payable '.$s['offered'].
                    ', '.$p->treatment.' '.$s['difference'].', return inventory '.$s['base'].
                    ', currency #'.$s['return']->currency_id.'. Source: '.$p->settlement_reference,
                'actor_id'=>$actor,'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('finance.supplier_short_credit.posted',
                'finance_supplier_short_credit_settlement',$p->id,
                'Original-currency supplier short-credit and approved difference posted.',
                ['journal_entry_id'=>$journal->id,'currency_id'=>$s['return']->currency_id]);
            return DB::table('finance_supplier_short_credit_settlements')->where('id',$p->id)->first();
        });
    }
}

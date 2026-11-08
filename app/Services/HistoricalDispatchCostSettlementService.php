<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\ProductionBatch;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Source-costed, independently authorized expense postings for historic
 * NON-SALE physical dispatches. A sales/unresolved disposition cannot post.
 * Physical quantities and original dispatch documents are never rewritten.
 */
final class HistoricalDispatchCostSettlementService
{
    private const NON_SALE_PURPOSES = [
        'promotional_sample', 'production_loss', 'internal_consumption',
    ];

    public function __construct(
        private readonly JournalPostingService $journals,
        private readonly ManufacturingLedgerPolicyService $policies,
    ) {}

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['historical_dispatch_cost'=>$message]);
    }

    private function source(int $caseId): array
    {
        $case=DB::table('finance_historical_correction_cases')->where('id',$caseId)
            ->lockForUpdate()->first();
        abort_unless($case,404);
        if($case->case_type!=='standalone_dispatch' || $case->status!=='approved')
            $this->fail('Only an approved, still open warehouse-only historical dispatch can be recognized.');
        $fingerprint=app(HistoricalFinanceCorrectionService::class)
            ->source('standalone_dispatch',(int)$case->source_id);
        if(!hash_equals((string)$case->source_fingerprint,(string)$fingerprint['fingerprint']))
            $this->fail('Original warehouse dispatch source changed after finance approval.');
        $disposition=DB::table('finance_historical_dispatch_dispositions')
            ->where('finance_historical_correction_case_id',$caseId)
            ->lockForUpdate()->first();
        if(!$disposition || (int)$disposition->finished_good_dispatch_id!==(int)$case->source_id ||
            !in_array($disposition->business_purpose,self::NON_SALE_PURPOSES,true))
            $this->fail('A signed non-sale business-purpose classification is required. Customer sales and unresolved purposes cannot be recorded as expenses.');
        $dispatch=DB::table('finished_good_dispatches')->where('id',$case->source_id)->first();
        if(!$dispatch || (string)$dispatch->dispatch_date!==(string)$case->source_date)
            $this->fail('Original dispatch date and approved historical source must match.');
        if(DB::table('sales_invoice_lines')->where('dispatch_id',$dispatch->id)->exists())
            $this->fail('A customer invoice now refers to this dispatch. Never charge it as an expense and also COGS.');
        $physical=DB::table('finished_good_dispatch_lines')
            ->where('finished_good_dispatch_id',$dispatch->id)->orderBy('id')->get();
        if($physical->isEmpty() || $physical->count()>50)
            $this->fail('A physical dispatch must have between one and 50 fully source-linked batch lines.');
        return compact('case','disposition','dispatch','physical');
    }

    /**
     * Return value comes ONLY from an earlier, already posted and unreversed
     * approved manufacturing WIP/finished-inventory source-cost journal.
     * Never use the indicative dispatch report or an exchange rate.
     */
    private function cost(object $line, string $physicalDate, string $postingDate): array
    {
        $batch=ProductionBatch::whereKey($line->production_batch_id)->lockForUpdate()->first();
        if(!$batch || $batch->status!=='released' ||
            (int)$batch->finished_good_id!==(int)$line->finished_good_id ||
            (int)$line->quantity_packages<1 || (int)$batch->accepted_packages<1)
            $this->fail('Physical dispatch batch, finished product, QC release or package quantity is not valid.');
        $cost=DB::table('production_batch_cost_postings')->where('production_batch_id',$batch->id)->first();
        if(!$cost || (int)$cost->accepted_packages!==(int)$batch->accepted_packages ||
            $cost->accounting_date>$physicalDate)
            $this->fail('The original batch was not fully capitalized before its actual physical dispatch. Historical source GL backfill is required first.');
        $policy=$this->policies->approved((int)$cost->currency_id);
        if((int)$policy->id!==(int)$cost->manufacturing_cost_ledger_policy_id)
            $this->fail('Original production batch accounting policy no longer matches the authorized factory ledgers.');
        foreach([$cost->issue_journal_id,$cost->capitalization_journal_id] as $sourceId){
            $sourceJournal=DB::table('journal_entries')->where('id',$sourceId)->first();
            if(!$sourceJournal || $sourceJournal->status!=='posted' || $sourceJournal->reversal_of_id ||
                (int)$sourceJournal->currency_id!==(int)$cost->currency_id ||
                $sourceJournal->entry_date>$physicalDate ||
                DB::table('journal_entries')->where('reversal_of_id',$sourceId)->exists())
                $this->fail('Original WIP/finished capitalization journal is absent, reversed or postdates the physical dispatch.');
        }
        $finishedJournal=DB::table('journal_entries')
            ->where('id',$cost->capitalization_journal_id)->first();
        if((string)$finishedJournal->entry_date!==(string)$cost->accounting_date)
            $this->fail('Manufacturing capitalization journal date is not its approved original cost date.');
        $assetDebit=BigDecimal::zero();
        $wipCredit=BigDecimal::zero();
        foreach(DB::table('journal_lines')->where('journal_entry_id',$finishedJournal->id)->get() as $gl){
            if((int)$gl->ledger_account_id===(int)$policy->finished_inventory_ledger_id)
                $assetDebit=$assetDebit->plus((string)$gl->debit)->minus((string)$gl->credit);
            if((int)$gl->ledger_account_id===(int)$policy->work_in_progress_ledger_id)
                $wipCredit=$wipCredit->plus((string)$gl->credit)->minus((string)$gl->debit);
        }
        $total=BigDecimal::of((string)$cost->total_amount);
        if(!$total->isPositive() || $total->isGreaterThan('9999999999999999.9999') ||
            !$assetDebit->isEqualTo($total) || !$wipCredit->isEqualTo($total))
            $this->fail('Finished-goods asset and WIP credit do not match the verified original capitalized batch total.');
        $priorQty=0;
        $priorAmount=BigDecimal::zero();
        $lastDate=null;
        $cogs=DB::table('production_invoice_cogs_postings as costed')
            ->join('journal_entries as journal','journal.id','=','costed.journal_entry_id')
            ->where('costed.production_batch_cost_posting_id',$cost->id)
            ->get(['costed.quantity_packages','costed.amount','journal.status',
                'journal.entry_date','journal.reversal_of_id','journal.id as journal_id']);
        $other=DB::table('finance_historical_dispatch_cost_lines as charged')
            ->join('finance_historical_dispatch_cost_settlements as settlement',
                'settlement.id','=','charged.finance_historical_dispatch_cost_settlement_id')
            ->join('journal_entries as journal','journal.id','=','charged.journal_entry_id')
            ->where('charged.production_batch_cost_posting_id',$cost->id)
            ->where('settlement.status','posted')
            ->get(['charged.quantity_packages','charged.source_cost_amount as amount',
                'journal.status','journal.entry_date','journal.reversal_of_id',
                'journal.id as journal_id']);
        foreach($cogs->concat($other) as $row){
            if($row->status!=='posted' || $row->reversal_of_id ||
                DB::table('journal_entries')->where('reversal_of_id',$row->journal_id)->exists())
                $this->fail('Existing sale/non-sale batch consumption journal is invalid or reversed.');
            $priorQty+=(int)$row->quantity_packages;
            $priorAmount=$priorAmount->plus((string)$row->amount);
            if($lastDate===null || $row->entry_date>$lastDate)$lastDate=$row->entry_date;
        }
        $returns=DB::table('production_return_cost_postings as returned')
            ->join('production_invoice_cogs_postings as sold',
                'sold.id','=','returned.production_invoice_cogs_posting_id')
            ->join('journal_entries as journal','journal.id','=','returned.journal_entry_id')
            ->where('sold.production_batch_cost_posting_id',$cost->id)
            ->get(['returned.quantity_packages','returned.amount',
                'journal.status','journal.entry_date','journal.reversal_of_id',
                'journal.id as journal_id']);
        foreach($returns as $row){
            if($row->status!=='posted' || $row->reversal_of_id ||
                DB::table('journal_entries')->where('reversal_of_id',$row->journal_id)->exists())
                $this->fail('Previously recovered sale return cost journal is invalid or reversed.');
            $priorQty-=(int)$row->quantity_packages;
            $priorAmount=$priorAmount->minus((string)$row->amount);
            if($lastDate===null || $row->entry_date>$lastDate)$lastDate=$row->entry_date;
        }
        if($lastDate && $postingDate<$lastDate)
            $this->fail('Historical accounting date precedes another already posted batch COGS, non-sale issue or QC-return cost. Post only after those journals.');
        $quantity=(int)$line->quantity_packages;
        $available=$total->minus($priorAmount);
        if($priorQty<0 || $priorQty+$quantity>(int)$cost->accepted_packages ||
            $available->isNegative() || $available->isZero() || $priorAmount->isNegative())
            $this->fail('Original capitalized batch packages or cost have already been exhausted by sale and non-sale dispatches.');
        $amount=$priorQty+$quantity===(int)$cost->accepted_packages
            ? $available
            : $total->multipliedBy((string)$quantity)
                ->dividedBy((string)$cost->accepted_packages,4,RoundingMode::HalfUp);
        if(!$amount->isPositive() || $amount->isGreaterThan($available) ||
            $amount->isGreaterThan('9999999999999999.9999'))
            $this->fail('The exact source-backed expense amount exceeds remaining original batch inventory.');
        return [
            'batch_id'=>(int)$batch->id,
            'cost_id'=>(int)$cost->id,
            'currency_id'=>(int)$cost->currency_id,
            'asset_ledger'=>(int)$policy->finished_inventory_ledger_id,
            'cogs_ledger'=>(int)$policy->cogs_ledger_id,
            'material_ledger'=>(int)$policy->material_inventory_ledger_id,
            'wip_ledger'=>(int)$policy->work_in_progress_ledger_id,
            'total'=>(string)$total->toScale(4),
            'quantity'=>$quantity,
            'amount'=>(string)$amount->toScale(4,RoundingMode::HalfUp),
        ];
    }

    private function account(int $ledgerId, array $cost): void
    {
        $ledger=LedgerAccount::whereKey($ledgerId)->first();
        if(!$ledger || !$ledger->is_active || $ledger->is_group || $ledger->type!=='expense' ||
            in_array($ledgerId,[$cost['asset_ledger'],$cost['cogs_ledger'],
                $cost['material_ledger'],$cost['wip_ledger']],true) ||
            DB::table('entity_ledger_accounts')->where('ledger_account_id',$ledgerId)->exists())
            $this->fail('Choose a distinct active, unmapped expense account authorized for the classified sampling, damage or internal-use purpose; never sales COGS.');
    }

    private function sameLine(object $saved, object $source, array $calculated): void
    {
        if((int)$saved->finished_good_dispatch_line_id!==(int)$source->id ||
            (int)$saved->production_batch_cost_posting_id!==$calculated['cost_id'] ||
            (int)$saved->currency_id!==$calculated['currency_id'] ||
            (int)$saved->finished_inventory_ledger_id!==$calculated['asset_ledger'] ||
            (int)$saved->quantity_packages!==$calculated['quantity'] ||
            !BigDecimal::of((string)$saved->original_batch_total)->isEqualTo($calculated['total']) ||
            !BigDecimal::of((string)$saved->source_cost_amount)->isEqualTo($calculated['amount']))
            $this->fail('Historical dispatch approved line, quantity, original currency, cost or stock account changed. Stop and reconcile.');
    }

    private function assetBalanceAsOf(int $ledgerId,int $currencyId,string $date): BigDecimal
    {
        $amount=BigDecimal::zero();
        foreach(DB::table('journal_lines as row')
            ->join('journal_entries as journal','journal.id','=','row.journal_entry_id')
            ->where('journal.status','posted')
            ->where('journal.currency_id',$currencyId)
            ->whereDate('journal.entry_date','<=',$date)
            ->where('row.ledger_account_id',$ledgerId)
            ->get(['row.debit','row.credit']) as $line){
            $amount=$amount->plus((string)$line->debit)->minus((string)$line->credit);
        }
        return $amount;
    }

    public function submit(int $caseId, array $data, int $actorId): object
    {
        return DB::transaction(function()use($caseId,$data,$actorId){
            $s=$this->source($caseId);
            if(in_array($actorId,[(int)$s['case']->requested_by,
                (int)$s['disposition']->reviewed_by],true))
                $this->fail('The accounting proposer must be independent of the original case and business-purpose reviewers.');
            if(DB::table('finance_historical_dispatch_cost_settlements')
                ->where('finished_good_dispatch_id',$s['dispatch']->id)->exists())
                $this->fail('This original physical dispatch already has a financial settlement proposal. Do not duplicate the cost.');
            $date=$data['posting_date'];
            if($date<$s['dispatch']->dispatch_date || $date>now()->toDateString())
                $this->fail('Accounting date must not predate the physical dispatch or be in the future.');
            $accounts=$data['expense_ledgers'];
            $ids=$s['physical']->pluck('id')->map(fn($id)=>(string)$id)->sort()->values()->all();
            $submitted=collect(array_keys($accounts))->map(fn($id)=>(string)(int)$id)->sort()->values()->all();
            if($ids!==$submitted)
                $this->fail('Finance must explicitly choose a recognized expense account for EVERY original dispatch source line.');
            $id=DB::table('finance_historical_dispatch_cost_settlements')->insertGetId([
                'finance_historical_correction_case_id'=>$caseId,
                'finance_historical_dispatch_disposition_id'=>$s['disposition']->id,
                'finished_good_dispatch_id'=>$s['dispatch']->id,
                'business_purpose'=>$s['disposition']->business_purpose,
                'status'=>'submitted','posting_date'=>$date,
                'source_authorization_reference'=>trim($data['source_authorization_reference']),
                'expense_recognition_evidence'=>trim($data['expense_recognition_evidence']),
                'submitted_by'=>$actorId,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            foreach($s['physical'] as $line){
                $calc=$this->cost($line,(string)$s['dispatch']->dispatch_date,$date);
                $ledgerId=(int)$accounts[$line->id];
                $this->account($ledgerId,$calc);
                DB::table('finance_historical_dispatch_cost_lines')->insert([
                    'finance_historical_dispatch_cost_settlement_id'=>$id,
                    'finished_good_dispatch_line_id'=>$line->id,
                    'production_batch_cost_posting_id'=>$calc['cost_id'],
                    'currency_id'=>$calc['currency_id'],
                    'finished_inventory_ledger_id'=>$calc['asset_ledger'],
                    'expense_ledger_id'=>$ledgerId,
                    'quantity_packages'=>$calc['quantity'],
                    'original_batch_total'=>$calc['total'],
                    'source_cost_amount'=>$calc['amount'],
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            ActivityLog::record('finance.historical_dispatch_cost.submitted',
                'finance_historical_dispatch_cost_settlement',$id,
                'Source-valued non-sale finished dispatch awaiting independent GL expense approval.');
            return DB::table('finance_historical_dispatch_cost_settlements')->where('id',$id)->first();
        });
    }

    public function approve(int $caseId,array $data,int $actorId): object
    {
        return DB::transaction(function()use($caseId,$data,$actorId){
            $proposal=DB::table('finance_historical_dispatch_cost_settlements')
                ->where('finance_historical_correction_case_id',$caseId)->lockForUpdate()->first();
            abort_unless($proposal,404);
            if($proposal->status!=='submitted')$this->fail('Only a submitted, unposted dispatch expense proposal can be reviewed.');
            $s=$this->source($caseId);
            if(in_array($actorId,[(int)$proposal->submitted_by,(int)$s['case']->requested_by,
                (int)$s['disposition']->reviewed_by],true))
                $this->fail('The expense reviewer must differ from the case requester, original purpose reviewer and expense proposer.');
            $this->unchanged($proposal,$s);
            $approved=$data['decision']==='approve';
            DB::table('finance_historical_dispatch_cost_settlements')->where('id',$proposal->id)->update([
                'status'=>$approved?'approved':'rejected',
                'approval_reference'=>trim($data['approval_reference']),
                'approval_notes'=>trim($data['approval_notes']),
                'approved_by'=>$actorId,'approved_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('finance.historical_dispatch_cost.'.($approved?'approved':'rejected'),
                'finance_historical_dispatch_cost_settlement',$proposal->id,
                'Independent source-cost and expense GL recognition decision; no journal posted.');
            return DB::table('finance_historical_dispatch_cost_settlements')->where('id',$proposal->id)->first();
        });
    }

    private function unchanged(object $proposal,array $s): array
    {
        if((int)$proposal->finance_historical_dispatch_disposition_id!==(int)$s['disposition']->id ||
            (int)$proposal->finished_good_dispatch_id!==(int)$s['dispatch']->id ||
            $proposal->business_purpose!==$s['disposition']->business_purpose)
            $this->fail('Physical dispatch purpose changed after financial proposal.');
        $rows=DB::table('finance_historical_dispatch_cost_lines')
            ->where('finance_historical_dispatch_cost_settlement_id',$proposal->id)
            ->orderBy('finished_good_dispatch_line_id')->get()->keyBy('finished_good_dispatch_line_id');
        if($rows->count()!==$s['physical']->count())
            $this->fail('Finance settlement does not contain every original physical dispatch line.');
        $result=[];
        foreach($s['physical'] as $line){
            $saved=$rows->get($line->id);
            if(!$saved)$this->fail('An original physical batch line is missing from the finance proposal.');
            $calculated=$this->cost($line,(string)$s['dispatch']->dispatch_date,(string)$proposal->posting_date);
            $this->sameLine($saved,$line,$calculated);
            $this->account((int)$saved->expense_ledger_id,$calculated);
            $result[]=['saved'=>$saved,'line'=>$line,'cost'=>$calculated];
        }
        return $result;
    }

    public function post(int $caseId,int $actorId): object
    {
        return DB::transaction(function()use($caseId,$actorId){
            $proposal=DB::table('finance_historical_dispatch_cost_settlements')
                ->where('finance_historical_correction_case_id',$caseId)->lockForUpdate()->first();
            abort_unless($proposal,404);
            if($proposal->status!=='approved' || $proposal->posted_at)
                $this->fail('Only an independently approved and unposted expense settlement can post.');
            $s=$this->source($caseId);
            if(in_array($actorId,[(int)$proposal->submitted_by,(int)$proposal->approved_by,
                (int)$s['case']->requested_by,(int)$s['disposition']->reviewed_by],true))
                $this->fail('The final financial executor must be independent of source requester, purpose reviewer, proposer and approver.');
            if($proposal->posting_date<$s['dispatch']->dispatch_date ||
                $proposal->posting_date>now()->toDateString())
                $this->fail('Historic expense journal cannot predate physical dispatch or be in the future.');
            $validated=$this->unchanged($proposal,$s);
            // Batch locks taken by unchanged() before acquiring the finance
            // close gate; existing sales COGS transactions use that same order.
            $period=app(FinancePeriodClosingService::class);
            $period->holdPostingGate();
            $period->assertOpenDate((string)$proposal->posting_date);
            foreach($validated as $row){
                $line=$row['saved'];
                $calc=$row['cost'];
                $balance=$this->assetBalanceAsOf($calc['asset_ledger'],
                    $calc['currency_id'],(string)$proposal->posting_date);
                if($balance->isLessThan($calc['amount']))
                    $this->fail('Original-currency posted finished inventory GL asset is insufficient for this non-sale expense. Reconcile actual inventory capitalization first.');
                DocumentSequence::firstOrCreate(['document_type'=>'historical_dispatch_expense'],[
                    'prefix'=>'HDE-','next_number'=>1,'padding'=>7,'is_active'=>true,
                ]);
                $journal=JournalEntry::create([
                    'number'=>DocumentSequence::issue('historical_dispatch_expense'),
                    'entry_date'=>$proposal->posting_date,
                    'reference'=>substr($s['dispatch']->number,0,100),
                    'description'=>'Source-priced '.$proposal->business_purpose.
                        ' original dispatch '.$s['dispatch']->number.' / batch '.$calc['batch_id'],
                    'currency_id'=>$calc['currency_id'],
                    'status'=>'draft','created_by'=>$actorId,
                ]);
                $journal->lines()->createMany([
                    ['ledger_account_id'=>$line->expense_ledger_id,'debit'=>$calc['amount'],
                        'credit'=>'0','memo'=>'Approved non-sale physical dispatch '.$line->finished_good_dispatch_line_id],
                    ['ledger_account_id'=>$calc['asset_ledger'],'debit'=>'0',
                        'credit'=>$calc['amount'],'memo'=>'Remove already-dispatched original finished inventory value'],
                ]);
                $this->journals->post($journal,$actorId);
                DB::table('finance_historical_dispatch_cost_lines')->where('id',$line->id)
                    ->update(['journal_entry_id'=>$journal->id,'updated_at'=>now()]);
            }
            DB::table('finance_historical_dispatch_cost_settlements')->where('id',$proposal->id)->update([
                'status'=>'posted','posted_by'=>$actorId,'posted_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('finance_historical_correction_cases')->where('id',$caseId)->update([
                'status'=>'resolved','open_key'=>null,
                'resolution_reference'=>$proposal->source_authorization_reference,
                'resolution_notes'=>$proposal->expense_recognition_evidence,
                'executed_by'=>$actorId,'executed_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('finance_historical_correction_events')->insert([
                'finance_historical_correction_case_id'=>$caseId,
                'action'=>'resolved','source_reference'=>$s['case']->source_reference,
                'source_fingerprint'=>$s['case']->source_fingerprint,
                'approval_reference'=>$proposal->approval_reference,
                'notes'=>'Independently approved '.$proposal->business_purpose.
                    ' original-currency non-sale expense for physical dispatch '.
                    $s['dispatch']->number.'; journals posted per source batch line. '.
                    $proposal->source_authorization_reference,
                'actor_id'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('finance.historical_dispatch_cost.posted',
                'finance_historical_dispatch_cost_settlement',$proposal->id,
                'Original source-batch non-sale inventory expense, no customer invoice or stock movement.',
                ['dispatch_id'=>$s['dispatch']->id,'line_count'=>count($validated)]);
            return DB::table('finance_historical_dispatch_cost_settlements')
                ->where('id',$proposal->id)->first();
        });
    }
}

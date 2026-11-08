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
        $debits=BigDecimal::zero();$credits=BigDecimal::zero();
        foreach(DB::table('journal_lines as line')
            ->join('journal_entries as entry','entry.id','=','line.journal_entry_id')
            ->where('entry.status','posted')
            ->whereBetween('entry.entry_date',[$begin,$end])
            ->get(['line.debit','line.credit']) as $row){
            $debits=$debits->plus((string)$row->debit);
            $credits=$credits->plus((string)$row->credit);
        }
        if(!$debits->isEqualTo($credits)){
            $blockers[]='Posted GL debits and credits do not balance for this accounting month.';
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
        // Dispatch-cost figures are diagnostic until all production input
        // costs, WIP capitalization and finished-good inventory journals
        // have been reviewed. Never treat estimated source cost as COGS.
        $dispatchLineCount=DB::table('finished_good_dispatch_lines as item')
            ->join('finished_good_dispatches as dispatch',
                'dispatch.id','=','item.finished_good_dispatch_id')
            ->whereBetween('dispatch.dispatch_date',[$begin,$end])->count();
        if($dispatchLineCount){
            $warnings[]=$dispatchLineCount.
                ' finished-goods dispatch line(s) require source-cost and WIP/finished-stock/COGS GL reconciliation. The source cost review is not a posted inventory valuation.';
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

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
        $orphanDispatches=DB::table('finished_good_dispatch_lines as item')
            ->join('finished_good_dispatches as dispatch',
                'dispatch.id','=','item.finished_good_dispatch_id')
            ->leftJoin('sales_invoice_lines as invoiced',
                'invoiced.dispatch_id','=','dispatch.id')
            ->whereBetween('dispatch.dispatch_date',[$begin,$end])
            ->whereNull('invoiced.id')->count();
        if($orphanDispatches){
            $blockers[]=$orphanDispatches.
                ' physical finished-goods dispatch line(s) have no linked customer invoice and COGS cost trail. Reconcile legacy standalone dispatches.';
        }
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

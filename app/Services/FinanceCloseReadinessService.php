<?php

namespace App\Services;

use Carbon\CarbonImmutable;
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

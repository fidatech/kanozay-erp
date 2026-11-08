<?php

namespace App\Services;

use App\Models\Shareholder;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Original-currency shareholder statement. Ownership %, paid-in capital,
 * drawings and approved profit distributions remain separate concepts.
 * The date filter describes selected-period activity, not a legal capital
 * balance determination or automatic FX-converted consolidated equity.
 */
class ShareholderStatementService
{
    public function rows(Shareholder $shareholder,array $filters):array
    {
        $scope=function($query,string $date,string $currency)use($filters){
            if(!empty($filters['currency_id']))$query->where($currency,$filters['currency_id']);
            if(!empty($filters['from']))$query->whereDate($date,'>=',$filters['from']);
            if(!empty($filters['to']))$query->whereDate($date,'<=',$filters['to']);
            return $query;
        };
        $capital=DB::table('shareholder_transactions as tx')
            ->join('journal_entries as entry','entry.id','=','tx.journal_entry_id')
            ->leftJoin('journal_entries as reversal','reversal.reversal_of_id','=','entry.id')
            ->whereNull('reversal.id')
            ->join('currencies as currency','currency.id','=','tx.currency_id')
            ->leftJoin('financial_accounts as account','account.id','=','tx.financial_account_id')
            ->where('tx.shareholder_id',$shareholder->id)
            ->where('entry.status','posted');
        $capital=$scope($capital,'tx.transaction_date','tx.currency_id')
            ->select('tx.number','tx.transaction_date as occurred_on',
                'tx.type','tx.amount','tx.currency_id',
                'currency.code as currency_code','tx.reference',
                'account.name as financial_account','entry.number as journal_number')
            ->get()->map(fn($row)=>(array)$row)->all();

        $profit=DB::table('shareholder_profit_distributions as distribution')
            ->join('journal_entries as entry',
                'entry.id','=','distribution.journal_entry_id')
            ->leftJoin('journal_entries as reversal','reversal.reversal_of_id','=','entry.id')
            ->whereNull('reversal.id')
            ->join('shareholder_resolutions as resolution',
                'resolution.id','=','distribution.shareholder_resolution_id')
            ->join('currencies as currency','currency.id','=','distribution.currency_id')
            ->leftJoin('financial_accounts as account',
                'account.id','=','distribution.financial_account_id')
            ->where('distribution.shareholder_id',$shareholder->id)
            ->where('entry.status','posted');
        $profit=$scope($profit,'distribution.paid_on','distribution.currency_id')
            ->select('entry.number as number','distribution.paid_on as occurred_on',
                'distribution.amount','distribution.currency_id',
                'currency.code as currency_code','resolution.number as reference',
                'account.name as financial_account','entry.number as journal_number')
            ->get()->map(function($row){
                $data=(array)$row;
                $data['type']='distribution';
                return $data;
            })->all();
        $rows=array_merge($capital,$profit);
        usort($rows,fn($a,$b)=>strcmp((string)$a['occurred_on'],(string)$b['occurred_on'])
            ?:strcmp((string)$a['number'],(string)$b['number']));
        return $rows;
    }

    public function summary(Shareholder $shareholder,array $rows):array
    {
        $summary=[];
        foreach($shareholder->currencies as $currency){
            $summary[$currency->id]=[
                'currency_id'=>$currency->id,'currency_code'=>$currency->code,
                'contribution'=>BigDecimal::zero(),'withdrawal'=>BigDecimal::zero(),
                'distribution'=>BigDecimal::zero(),
            ];
        }
        foreach($rows as $row){
            $id=(int)$row['currency_id'];
            if(!isset($summary[$id]))continue;
            $type=$row['type'];
            if(!in_array($type,['contribution','withdrawal','distribution'],true))continue;
            $summary[$id][$type]=$summary[$id][$type]->plus((string)$row['amount']);
        }
        foreach($summary as &$item){
            $item['net_capital_activity']=(string)$item['contribution']
                ->minus($item['withdrawal'])->toScale(4);
            foreach(['contribution','withdrawal','distribution'] as $type){
                $item[$type]=(string)$item[$type]->toScale(4);
            }
        }
        unset($item);
        return array_values($summary);
    }
}

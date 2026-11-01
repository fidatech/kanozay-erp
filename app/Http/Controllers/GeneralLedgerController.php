<?php
namespace App\Http\Controllers;
use Brick\Math\BigDecimal;
use App\Models\Currency;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class GeneralLedgerController extends Controller {
 public function index(Request $request) {
  abort_unless($request->user()->canAny(['administration.view', 'finance.view', 'reports.view']),403);
  $filters=$request->validate(['account_id'=>['nullable','integer',Rule::exists('ledger_accounts','id')],'currency_id'=>['nullable','integer',Rule::exists('currencies','id')],'from'=>['nullable','date'],'to'=>['nullable','date','after_or_equal:from']]);
  $accounts=LedgerAccount::orderBy('code')->get();$currencies=Currency::orderBy('code')->get();
  $account=isset($filters['account_id'])?$accounts->firstWhere('id',(int)$filters['account_id']):null;
  $currency=isset($filters['currency_id'])?$currencies->firstWhere('id',(int)$filters['currency_id']):null;
  $query=JournalLine::query()->join('journal_entries as j','j.id','=','journal_lines.journal_entry_id')->where('j.status','posted');
  if($account)$query->where('journal_lines.ledger_account_id',$account->id);
  if($currency)$query->where('j.currency_id',$currency->id);
  $from=$filters['from']??null;$to=$filters['to']??null;
  $openingQuery=clone $query;
  $openingRows=$from?$openingQuery->where('j.entry_date','<',$from)->select('j.currency_id','journal_lines.debit','journal_lines.credit')->get():collect();
  $aggregateByCurrency=function($lines){$totals=[];foreach($lines as $line){$id=$line->currency_id;if(!isset($totals[$id]))$totals[$id]=['currency_id'=>$id,'debit_total'=>BigDecimal::zero(),'credit_total'=>BigDecimal::zero()];$totals[$id]['debit_total']=$totals[$id]['debit_total']->plus((string)$line->debit);$totals[$id]['credit_total']=$totals[$id]['credit_total']->plus((string)$line->credit);}return collect(array_map(fn($total)=>(object)['currency_id'=>$total['currency_id'],'debit_total'=>(string)$total['debit_total'],'credit_total'=>(string)$total['credit_total']],array_values($totals)));};
  $opening=$aggregateByCurrency($openingRows);
  if($from)$query->where('j.entry_date','>=',$from);
  if($to)$query->where('j.entry_date','<=',$to);
  $totalRows=(clone $query)->select('j.currency_id','journal_lines.debit','journal_lines.credit')->get();$totals=$aggregateByCurrency($totalRows);
  $rows=$query->select('journal_lines.*','j.number as journal_number','j.entry_date','j.description as journal_description','j.currency_id')->orderBy('j.entry_date')->orderBy('j.id')->orderBy('journal_lines.id')->paginate(30)->withQueryString();
  $openingMap=$opening->keyBy('currency_id');$running=[];$openingNet=[];$openingDirections=[];
  foreach($opening as $item){$net=BigDecimal::of((string)$item->debit_total)->minus((string)$item->credit_total);$running[$item->currency_id]=$net;$openingNet[$item->currency_id]=(string)$net;$openingDirections[$item->currency_id]=$net->compareTo(BigDecimal::zero())>0?'in':($net->compareTo(BigDecimal::zero())<0?'out':'neutral');}
  // Calculate the balance before the requested page without using floating-point accumulation.
  if($rows->currentPage()>1){$before=JournalLine::query()->join('journal_entries as j','j.id','=','journal_lines.journal_entry_id')->where('j.status','posted');if($account)$before->where('journal_lines.ledger_account_id',$account->id);if($currency)$before->where('j.currency_id',$currency->id);if($from)$before->where('j.entry_date','>=',$from);if($to)$before->where('j.entry_date','<=',$to);
   $previous=$before->select('journal_lines.debit','journal_lines.credit','j.currency_id')->orderBy('j.entry_date')->orderBy('j.id')->orderBy('journal_lines.id')->limit(($rows->currentPage()-1)*$rows->perPage())->get();foreach($previous as $line)$running[$line->currency_id]=($running[$line->currency_id]??BigDecimal::zero())->plus((string)$line->debit)->minus((string)$line->credit);
  }
  $balances=[];$balanceDirections=[];$debitNonzero=[];$creditNonzero=[];foreach($rows as $line){$running[$line->currency_id]=($running[$line->currency_id]??BigDecimal::zero())->plus((string)$line->debit)->minus((string)$line->credit);$balances[$line->id]=(string)$running[$line->currency_id];$balanceDirections[$line->id]=$running[$line->currency_id]->compareTo(BigDecimal::zero())>0?'in':($running[$line->currency_id]->compareTo(BigDecimal::zero())<0?'out':'neutral');$debitNonzero[$line->id]=BigDecimal::of((string)$line->debit)->compareTo(BigDecimal::zero())!==0;$creditNonzero[$line->id]=BigDecimal::of((string)$line->credit)->compareTo(BigDecimal::zero())!==0;}
  return view('finance.ledger.index',compact('accounts','currencies','account','currency','filters','rows','openingMap','openingNet','openingDirections','totals','balances','balanceDirections','debitNonzero','creditNonzero'));
 }
}

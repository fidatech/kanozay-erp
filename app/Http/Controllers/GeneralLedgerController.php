<?php
namespace App\Http\Controllers;
use App\Models\Currency;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class GeneralLedgerController extends Controller {
 public function index(Request $request) {
  abort_unless($request->user()->can('administration.view'),403);
  $filters=$request->validate(['account_id'=>['nullable','integer',Rule::exists('ledger_accounts','id')],'currency_id'=>['nullable','integer',Rule::exists('currencies','id')],'from'=>['nullable','date'],'to'=>['nullable','date','after_or_equal:from']]);
  $accounts=LedgerAccount::orderBy('code')->get();$currencies=Currency::orderBy('code')->get();
  $account=isset($filters['account_id'])?$accounts->firstWhere('id',(int)$filters['account_id']):null;
  $currency=isset($filters['currency_id'])?$currencies->firstWhere('id',(int)$filters['currency_id']):null;
  $query=JournalLine::query()->join('journal_entries as j','j.id','=','journal_lines.journal_entry_id')->where('j.status','posted');
  if($account)$query->where('journal_lines.ledger_account_id',$account->id);
  if($currency)$query->where('j.currency_id',$currency->id);
  $from=$filters['from']??null;$to=$filters['to']??null;
  $openingQuery=clone $query;
  $opening=$from?$openingQuery->where('j.entry_date','<',$from)->selectRaw('j.currency_id, SUM(journal_lines.debit) as debit_total, SUM(journal_lines.credit) as credit_total')->groupBy('j.currency_id')->get():collect();
  if($from)$query->where('j.entry_date','>=',$from);
  if($to)$query->where('j.entry_date','<=',$to);
  $totals=(clone $query)->selectRaw('j.currency_id, SUM(journal_lines.debit) as debit_total, SUM(journal_lines.credit) as credit_total')->groupBy('j.currency_id')->get();
  $rows=$query->select('journal_lines.*','j.number as journal_number','j.entry_date','j.description as journal_description','j.currency_id')->orderBy('j.entry_date')->orderBy('j.id')->orderBy('journal_lines.id')->paginate(30)->withQueryString();
  $openingMap=$opening->keyBy('currency_id');$running=[];
  foreach($opening as $item)$running[$item->currency_id]=(int)round((float)$item->debit_total*10000)-(int)round((float)$item->credit_total*10000);
  // Calculate the balance before the requested page without using floating-point accumulation.
  if($rows->currentPage()>1){$before=JournalLine::query()->join('journal_entries as j','j.id','=','journal_lines.journal_entry_id')->where('j.status','posted');if($account)$before->where('journal_lines.ledger_account_id',$account->id);if($currency)$before->where('j.currency_id',$currency->id);if($from)$before->where('j.entry_date','>=',$from);if($to)$before->where('j.entry_date','<=',$to);
   $previous=$before->select('journal_lines.debit','journal_lines.credit','j.currency_id')->orderBy('j.entry_date')->orderBy('j.id')->orderBy('journal_lines.id')->limit(($rows->currentPage()-1)*$rows->perPage())->get();foreach($previous as $line)$running[$line->currency_id]=($running[$line->currency_id]??0)+(int)round((float)$line->debit*10000)-(int)round((float)$line->credit*10000);
  }
  $balances=[];foreach($rows as $line){$running[$line->currency_id]=($running[$line->currency_id]??0)+(int)round((float)$line->debit*10000)-(int)round((float)$line->credit*10000);$balances[$line->id]=number_format($running[$line->currency_id]/10000,4,'.',',');}
  return view('finance.ledger.index',compact('accounts','currencies','account','currency','filters','rows','openingMap','totals','balances'));
 }
}

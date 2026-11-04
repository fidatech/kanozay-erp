<?php
namespace App\Http\Controllers;

use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Read-only directory of mapped posting accounts. Source records and journals remain authoritative.
 */
class UnifiedAccountDirectoryController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->canAny(['administration.view','finance.view','finance.update','reports.view']), 403);
        $filters=$request->validate([
            'q'=>['nullable','string','max:120'],
            'type'=>['nullable',Rule::in(['customer','supplier','cashbox','bank','sarafi','other'])],
            'currency_id'=>['nullable','integer','exists:currencies,id'],
        ]);
        $query=DB::table('entity_ledger_accounts as map')
            ->join('ledger_accounts as gl','gl.id','=','map.ledger_account_id')
            ->join('currencies as curr','curr.id','=','map.currency_id')
            ->leftJoin('trade_parties as party',function($join){
                $join->on('party.id','=','map.entity_id')
                     ->on('party.type','=','map.entity_type');
            })
            ->leftJoin('financial_accounts as financial',function($join){
                $join->on('financial.id','=','map.entity_id')
                     ->on('financial.type','=','map.entity_type');
            })
            ->select([
                'gl.id as ledger_id','gl.code','gl.name as ledger_name','gl.type as ledger_type',
                'gl.is_active as ledger_active','map.entity_type','map.entity_id','map.purpose',
                'map.currency_id','curr.code as currency_code',
                'party.name as party_name','party.is_active as party_active',
                'financial.name as financial_name','financial.is_active as financial_active',
            ])
            ->orderBy('map.entity_type')->orderBy('gl.code');

        if(!empty($filters['type'])){
            if($filters['type']==='other')$query->whereNotIn('map.entity_type',['customer','supplier','cashbox','bank','sarafi']);
            else $query->where('map.entity_type',$filters['type']);
        }
        if(!empty($filters['currency_id']))$query->where('map.currency_id',$filters['currency_id']);
        if(!empty($filters['q'])){
            $term=trim($filters['q']);
            $query->where(function($q)use($term){
                $pattern='%'.addcslashes($term,'%_\\').'%';
                $q->where('gl.code','like',$pattern)
                  ->orWhere('gl.name','like',$pattern)
                  ->orWhere('party.name','like',$pattern)
                  ->orWhere('financial.name','like',$pattern);
            });
        }
        $accounts=$query->paginate(30)->withQueryString();

        // Aggregate per ledger + currency only, from posted entries. Never combine currencies.
        $ids=$accounts->getCollection()->pluck('ledger_id')->all();
        $balances=DB::table('journal_lines as line')
            ->join('journal_entries as journal','journal.id','=','line.journal_entry_id')
            ->where('journal.status','posted')->whereIn('line.ledger_account_id',$ids)
            ->select('line.ledger_account_id','journal.currency_id')
            ->selectRaw('SUM(line.debit) as total_debit, SUM(line.credit) as total_credit')
            ->groupBy('line.ledger_account_id','journal.currency_id')->get()
            ->keyBy(fn($row)=>$row->ledger_account_id.':'.$row->currency_id);

        return view('finance.directory.index',[
            'accounts'=>$accounts,'balances'=>$balances,'filters'=>$filters,
            'currencies'=>Currency::orderBy('code')->get()
        ]);
    }
}

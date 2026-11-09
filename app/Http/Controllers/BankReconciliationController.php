<?php

namespace App\Http\Controllers;

use App\Models\FinancialAccount;
use App\Models\MoneyTransaction;
use App\Services\BankReconciliationService;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BankReconciliationController extends Controller
{
    private function gate(Request $request,bool $write=false):void
    {
        abort_unless($request->user()->canAny($write
            ?['finance.update','administration.update']
            :['finance.view','administration.view','reports.view']),403);
    }

    public function index(Request $request)
    {
        $this->gate($request);
        $filter=$request->validate([
            'account_id'=>['nullable','integer','exists:financial_accounts,id'],
            'status'=>['nullable',Rule::in(['draft','approved'])],
        ]);
        $accounts=FinancialAccount::query()
            ->where('type','bank')->where('is_active',true)
            ->with('currency')->orderBy('name')->get();

        $periods=DB::table('bank_reconciliation_periods as period')
            ->join('financial_accounts as account',
                'account.id','=','period.financial_account_id')
            ->join('currencies as currency',
                'currency.id','=','period.currency_id')
            ->leftJoin('users as reviewer',
                'reviewer.id','=','period.approved_by')
            ->select('period.*','account.name as bank_name',
                'currency.code as currency_code',
                'reviewer.name as reviewer_name')
            ->when($filter['account_id']??null,
                fn($q,$id)=>$q->where('period.financial_account_id',$id))
            ->when($filter['status']??null,
                fn($q,$status)=>$q->where('period.status',$status))
            ->orderByDesc('period.period_end')->orderByDesc('period.id')
            ->paginate(25)->withQueryString();
        return view('finance.bank-reconciliations',compact('accounts','periods','filter'));
    }

    public function create(Request $request,BankReconciliationService $service)
    {
        $this->gate($request,true);
        $data=$request->validate([
            'financial_account_id'=>['required','integer','exists:financial_accounts,id'],
            'period_start'=>['required','date','date_format:Y-m-d'],
            'period_end'=>['required','date','date_format:Y-m-d','before_or_equal:today'],
            'statement_opening'=>['required','numeric','decimal:0,4',
                'between:-9999999999999999.9999,9999999999999999.9999'],
            'statement_closing'=>['required','numeric','decimal:0,4',
                'between:-9999999999999999.9999,9999999999999999.9999'],
            'statement_reference'=>['required','string','min:4','max:180'],
        ]);
        $period=$service->create($data,(int)$request->user()->id);
        return redirect()->route('finance.bank-reconciliations.show',$period->id)
            ->with('success','Bank statement source period created; enter authentic statement lines and match every posted bank GL transaction before independent approval.');
    }

    public function show(Request $request,int $period)
    {
        $this->gate($request);
        $row=DB::table('bank_reconciliation_periods as period')
            ->join('financial_accounts as account',
                'account.id','=','period.financial_account_id')
            ->join('currencies as currency',
                'currency.id','=','period.currency_id')
            ->where('period.id',$period)
            ->select('period.*','account.name as bank_name',
                'account.institution as bank_institution',
                'account.account_number as bank_account_number',
                'currency.code as currency_code')->first();
        abort_unless($row,404);
        $lines=DB::table('bank_statement_entries as line')
            ->leftJoin('bank_statement_matches as matched',
                'matched.bank_statement_entry_id','=','line.id')
            ->leftJoin('money_transactions as money',
                'money.id','=','matched.money_transaction_id')
            ->leftJoin('journal_entries as journal',
                'journal.id','=','money.journal_entry_id')
            ->where('line.bank_reconciliation_period_id',$period)
            ->select('line.*','matched.money_transaction_id',
                'matched.matched_by','money.reference as money_reference',
                'money.journal_entry_id as money_journal_id',
                'journal.number as money_journal_number',
                'journal.status as money_journal_status')
            ->orderBy('line.posted_on')->orderBy('line.id')->get();

        $options=MoneyTransaction::query()
            ->where('currency_id',$row->currency_id)
            ->whereBetween('transaction_date',[$row->period_start,$row->period_end])
            ->where(function($q)use($row){
                $q->where('source_account_id',$row->financial_account_id)
                    ->orWhere('destination_account_id',$row->financial_account_id);
            })
            ->whereHas('journal',fn($q)=>$q
                ->where('status','posted')->whereNull('reversal_of_id'))
            ->whereNotIn('id',DB::table('bank_statement_matches')
                ->where('financial_account_id',$row->financial_account_id)
                ->select('money_transaction_id'))
            ->orderBy('transaction_date')->orderBy('id')
            ->limit(1000)->get();
        $candidates=$options->groupBy(function($tx)use($row){
            $direction=(int)$tx->source_account_id===(int)$row->financial_account_id
                ?'withdrawal':'deposit';
            return $tx->transaction_date.'|'.$direction.'|'.
                (string)BigDecimal::of((string)$tx->amount)->toScale(4);
        });
        $deposit=BigDecimal::zero();$withdrawal=BigDecimal::zero();
        foreach($lines as $line){
            $value=BigDecimal::of((string)$line->amount);
            if($line->direction==='deposit')$deposit=$deposit->plus($value);
            else $withdrawal=$withdrawal->plus($value);
        }
        $statementExpected=BigDecimal::of((string)$row->statement_opening)
            ->plus($deposit)->minus($withdrawal);
        $matchingCount=$lines->whereNotNull('money_transaction_id')->count();
        return view('finance.bank-reconciliation-show',compact(
            'row','lines','candidates','matchingCount','deposit',
            'withdrawal','statementExpected'));
    }

    public function line(Request $request,int $period,BankReconciliationService $service)
    {
        $this->gate($request,true);
        $data=$request->validate([
            'posted_on'=>['required','date','date_format:Y-m-d'],
            'direction'=>['required',Rule::in(['deposit','withdrawal'])],
            'amount'=>['required','numeric','gt:0','decimal:0,4',
                'max:9999999999999999.9999'],
            'bank_reference'=>['required','string','min:1','max:180'],
            'description'=>['nullable','string','max:500'],
            'submission_token'=>['required','uuid'],
        ]);
        $service->addLine($period,$data,(int)$request->user()->id);
        return back()->with('success','Original bank statement line recorded. Match it to the exact source-currency posted bank GL movement.');
    }

    /**
     * Stable import tokens identify the exact file and row. Reimporting the
     * same CSV cannot silently duplicate banking rows; a changed file is a
     * new source document and must be independently reconciled.
     *
     * Required CSV header: date,direction,amount,reference,description
     * Uses period-bound, local source currency and NO FX conversion.
     */
    public function import(Request $request,int $period,BankReconciliationService $service)
    {
        $this->gate($request,true);
        $data=$request->validate([
            'csv'=>['required','file','max:2048','mimes:csv,txt'],
            'confirm_source_csv'=>['accepted'],
        ]);
        $periodRow=DB::table('bank_reconciliation_periods')->where('id',$period)->first();
        abort_unless($periodRow,404);
        if($periodRow->status!=='draft')
            throw ValidationException::withMessages(['csv'=>'Only an open draft bank statement may import original banking lines.']);
        $bytes=file_get_contents($data['csv']->getRealPath());
        if($bytes===false || strlen($bytes)>2097152 ||
            str_contains($bytes,"\0"))
            throw ValidationException::withMessages(['csv'=>'Invalid or oversized plain-text bank statement source.']);
        $stream=fopen('php://temp','w+');
        fwrite($stream,$bytes);
        rewind($stream);
        try{
            $headers=fgetcsv($stream);
            if(!$headers)throw ValidationException::withMessages([
                'csv'=>'A header row is required. Use date,direction,amount,reference,description.',
            ]);
            $headers=array_map(function($item){
                return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/','',(string)$item)));
            },$headers);
            if($headers!==['date','direction','amount','reference','description'])
                throw ValidationException::withMessages(['csv'=>
                    'CSV must have five exact columns in order: date,direction,amount,reference,description. No inferred bank metadata or exchange rates.']);
            $rows=[];
            $fingerprint=hash('sha256',$bytes);
            $lineNumber=1;
            while(($fields=fgetcsv($stream))!==false){
                $lineNumber++;
                if($fields===[null])continue;
                if(count($fields)!==5)
                    throw ValidationException::withMessages(['csv'=>
                        'Row '.$lineNumber.' must have five correctly quoted source columns.']);
                if(count($rows)>=2000)
                    throw ValidationException::withMessages(['csv'=>
                        'Import at most 2,000 original statement rows per file. Split additional bank statements with preserved references.']);
                [$date,$direction,$amount,$reference,$description]=array_map('trim',$fields);
                $valid=validator(compact('date','direction','amount','reference','description'),[
                    'date'=>['required','date_format:Y-m-d'],
                    'direction'=>['required',Rule::in(['deposit','withdrawal'])],
                    'amount'=>['required','regex:/^(?:0|[1-9]\d{0,15})(?:\.\d{1,4})?$/','numeric','gt:0'],
                    'reference'=>['required','string','min:1','max:180'],
                    'description'=>['nullable','string','max:500'],
                ]);
                if($valid->fails())
                    throw ValidationException::withMessages(['csv'=>
                        'Bank source row '.$lineNumber.': '.$valid->errors()->first()]);
                if($date<$periodRow->period_start || $date>$periodRow->period_end)
                    throw ValidationException::withMessages(['csv'=>
                        'Bank source row '.$lineNumber.' falls outside the actual statement period.']);
                $rows[]=[
                    'posted_on'=>$date,'direction'=>$direction,
                    'amount'=>$amount,'bank_reference'=>$reference,
                    'description'=>$description,
                    'submission_token'=>(string)\Ramsey\Uuid\Uuid::uuid5(
                        \Ramsey\Uuid\Uuid::NAMESPACE_URL,
                        'kanozay-bank-statement:'.$period.':'.$fingerprint.':'.$lineNumber),
                ];
            }
            if(!$rows)
                throw ValidationException::withMessages(['csv'=>
                    'This banking source file contains no transaction rows.']);
            DB::transaction(function()use($rows,$period,$service,$request){
                foreach($rows as $source)
                    $service->addLine($period,$source,(int)$request->user()->id);
            });
            return redirect()->route('finance.bank-reconciliations.show',$period)
                ->with('success',count($rows).' original bank statement records imported or safely recognized as existing from the identical source file. None was automatically matched or posted into the GL.');
        }finally{
            fclose($stream);
        }
    }

    public function match(Request $request,int $period,int $line,
        BankReconciliationService $service)
    {
        $this->gate($request,true);
        $data=$request->validate([
            'money_transaction_id'=>['required','integer','exists:money_transactions,id'],
        ]);
        $record=DB::table('bank_statement_entries')
            ->where('id',$line)->where('bank_reconciliation_period_id',$period)->first();
        abort_unless($record,404);
        $service->match($line,(int)$data['money_transaction_id'],
            (int)$request->user()->id);
        return back()->with('success',
            'Original bank statement matched to an actual posted and unreversed source-currency GL transaction.');
    }

    public function approve(Request $request,int $period,
        BankReconciliationService $service)
    {
        $this->gate($request,true);
        $data=$request->validate([
            'review_notes'=>['required','string','min:40','max:5000'],
            'confirm_all_source_lines'=>['accepted'],
            'confirm_original_gl'=>['accepted'],
        ]);
        $service->approve($period,$data['review_notes'],(int)$request->user()->id);
        return redirect()->route('finance.bank-reconciliations.show',$period)
            ->with('success','Original bank statement and source-currency GL balances approved by an independent finance reviewer. Reconciliation is now immutable.');
    }
}

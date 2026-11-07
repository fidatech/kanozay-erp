<?php

namespace App\Http\Controllers;

use App\Models\JournalLine;
use App\Models\ProductionBatch;
use App\Services\ProductionCostAllocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductionCostAllocationController extends Controller
{
 public function index(Request $request)
 {
  abort_unless($request->user()->canAny(['manufacturing.view','finance.view','administration.view']),403);
  $batches=ProductionBatch::with(['order','finishedGood'])->latest('id')->limit(500)->get();
  $materialCosts=DB::table('production_batch_costs as c')
   ->join('production_batches as b','b.id','=','c.production_batch_id')
   ->leftJoin('finished_goods as g','g.id','=','b.finished_good_id')
   ->select('c.*','b.batch_number','g.name as product_name')
   ->orderByDesc('c.id')->limit(500)->get();
  $expenseLines=DB::table('journal_lines as l')
   ->join('journal_entries as j','j.id','=','l.journal_entry_id')
   ->join('ledger_accounts as a','a.id','=','l.ledger_account_id')
   ->join('currencies as c','c.id','=','j.currency_id')
   ->where('j.status','posted')->whereNull('j.reversal_of_id')
   ->where('a.type','expense')->where('a.is_active',true)->where('a.is_group',false)
   ->where('l.debit','>',0)
   ->select('l.id','l.debit','j.number','a.code as ledger_code','a.name as ledger_name',
     'c.code as currency_code')->orderByDesc('l.id')->limit(500)->get();
  $allocations=DB::table('production_overhead_allocations as o')
   ->join('production_batches as b','b.id','=','o.production_batch_id')
   ->join('currencies as c','c.id','=','o.currency_id')
   ->join('journal_lines as l','l.id','=','o.source_journal_line_id')
   ->join('journal_entries as j','j.id','=','l.journal_entry_id')
   ->select('o.*','b.batch_number','c.code as currency_code','j.number as journal_number')
   ->orderByDesc('o.id')->paginate(35);
  $byBatch=DB::table('production_overhead_allocations as o')
   ->join('currencies as c','c.id','=','o.currency_id')
   ->where('o.status','approved')
   ->select('o.production_batch_id','c.code as currency_code')
   ->selectRaw('SUM(o.amount) as total')
   ->groupBy('o.production_batch_id','c.code')->get();
  return view('production.cost-allocations',compact('batches','materialCosts','expenseLines','allocations','byBatch'));
 }

 public function store(Request $request,ProductionCostAllocationService $service)
 {
  abort_unless($request->user()->can('administration.update') ||
    ($request->user()->can('manufacturing.update')&&$request->user()->can('finance.view')),403);
  $data=$request->validate([
   'production_batch_id'=>['required','integer','exists:production_batches,id'],
   'source_journal_line_id'=>['required','integer','exists:journal_lines,id'],
   'submission_token'=>['required','uuid'],
   'category'=>['required',Rule::in(['labor','utilities','energy','quality','equipment','packaging','other'])],
   'amount'=>['required','numeric','gt:0','decimal:0,4','max:9999999999999999.9999'],
   'source_reference'=>['required','string','min:3','max:180'],
   'notes'=>['nullable','string','max:3000'],
  ]);
  $service->submit($data,$request->user()->id);
  return redirect()->route('workspace.page',['manufacturing','costing'])
   ->with('success','Journal-backed batch overhead submitted for independent review.');
 }

 public function decide(Request $request,int $allocation,ProductionCostAllocationService $service)
 {
  abort_unless($request->user()->canAny(['finance.update','administration.update']),403);
  $data=$request->validate([
   'decision'=>['required',Rule::in(['approved','rejected'])],
   'review_note'=>['required','string','min:5','max:2000'],
  ]);
  $service->decide($allocation,$data['decision'],$data['review_note'],$request->user()->id);
  return back()->with('success','Manufacturing overhead decision recorded.');
 }
}

<?php
namespace App\Http\Controllers;

use App\Models\CompanyWarehouse;
use App\Models\SalesReturnQuarantine;
use App\Services\SalesReturnQuarantineService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalesReturnQuarantineController extends Controller {
 public function index(Request $request){
  abort_unless($request->user()->canAny([
   'sales.view','inventory.view','laboratory.view','administration.view'
  ]),403);
  $filter=$request->validate(['status'=>['nullable',Rule::in(['quarantined','released','scrapped'])]]);
  $mayViewCosts=$request->user()->canAny([
    'finance.view','reports.view','administration.view',
  ]);
  $returnCosts=collect();
  $returnDispositions=collect();
  if($mayViewCosts){
    $returnCosts=\Illuminate\Support\Facades\DB::table('production_return_cost_postings as recovered')
      ->join('production_invoice_cogs_postings as origin',
        'origin.id','=','recovered.production_invoice_cogs_posting_id')
      ->join('production_batch_cost_postings as batch',
        'batch.id','=','origin.production_batch_cost_posting_id')
      ->join('currencies as currency','currency.id','=','batch.currency_id')
      ->join('journal_entries as journal',
        'journal.id','=','recovered.journal_entry_id')
      ->select('recovered.*','currency.code as currency_code',
        'journal.number as journal_number','journal.status as journal_status')
      ->orderByDesc('recovered.id')->limit(1500)->get()
      ->keyBy('sales_return_quarantine_id');
    $returnDispositions=\Illuminate\Support\Facades\DB::table(
        'finance_historical_customer_return_disposition_reviews as reviewed'
      )
      ->leftJoin('journal_entries as journal','journal.id','=','reviewed.journal_entry_id')
      ->leftJoin('currencies as currency','currency.id','=','reviewed.currency_id')
      ->whereIn('reviewed.status',['submitted','approved','held','posted'])
      ->select('reviewed.*','journal.number as journal_number',
          'journal.status as journal_status','currency.code as currency_code')
      ->orderBy('reviewed.id')->limit(1500)->get()
      ->keyBy('sales_return_quarantine_id');
  }
  return view('sales.return-quarantine',[
   'mayViewCosts'=>$mayViewCosts,'returnCosts'=>$returnCosts,
   'returnDispositions'=>$returnDispositions,
   'entries'=>SalesReturnQuarantine::query()->with([
      'creditLine','creditLine.invoiceLine.finishedGood','batch',
   ])->when($filter['status']??null,fn($q,$s)=>$q->where('status',$s))
     ->orderByDesc('id')->paginate(25)->withQueryString(),
   'warehouses'=>CompanyWarehouse::where('is_active',true)->orderBy('name')->get(),
  ]);
 }
 public function decision(Request $request,int $quarantine,SalesReturnQuarantineService $service){
  abort_unless($request->user()->can('administration.update') ||
    ($request->user()->can('laboratory.update') && $request->user()->can('inventory.update')),403);
  $data=$request->validate([
   'action'=>['required',Rule::in(['release','scrap'])],
   'decided_on'=>['required','date','before_or_equal:today'],
   'quality_reference'=>['required','string','min:3','max:160'],
   'decision_notes'=>['required','string','min:10','max:3000'],
   'company_warehouse_id'=>['nullable','integer',Rule::exists('company_warehouses','id')->where('is_active',true)],
  ]);
  $service->decide($quarantine,$data,$request->user()->id);
  return back()->with('success','QC return disposition recorded. Only released returns are added to saleable stock.');
 }
}
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
  return view('sales.return-quarantine',[
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
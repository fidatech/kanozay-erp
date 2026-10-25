<?php
namespace App\Http\Controllers;
use App\Models\TradeDocument;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class TradeAgingController extends Controller {
 public function index(Request $request){
  abort_unless($request->user()->can('administration.view'),403);
  $filters=$request->validate(['type'=>['nullable',Rule::in(['receivable','payable'])],'as_of'=>['nullable','date']]);
  $type=$filters['type']??'receivable';$asOf=$filters['as_of']??now()->toDateString();
  $documents=TradeDocument::with(['party','currency'])->where('type',$type)->whereDate('document_date','<=',$asOf)->withSum(['allocations as allocated_as_of'=>fn($q)=>$q->whereDate('created_at','<=',$asOf)],'amount')->orderBy('due_date')->get();
  $rows=[];$totals=[];
  foreach($documents as $document){
   $balance=max(0,round((float)$document->amount-(float)($document->allocated_as_of??0),4));if($balance<=0)continue;
   $days=(int)\Carbon\Carbon::parse($document->due_date)->startOfDay()->diffInDays(\Carbon\Carbon::parse($asOf)->startOfDay(),false);
   $bucket=$days<=0?'current':($days<=30?'1_30':($days<=60?'31_60':($days<=90?'61_90':'90_plus')));
   $code=$document->currency?->code??'—';$rows[]=['document'=>$document,'balance'=>$balance,'days'=>max(0,$days),'bucket'=>$bucket];
   if(!isset($totals[$code]))$totals[$code]=['current'=>0,'1_30'=>0,'31_60'=>0,'61_90'=>0,'90_plus'=>0,'total'=>0];
   $totals[$code][$bucket]+=$balance;$totals[$code]['total']+=$balance;
  }
  return view('finance.documents.aging',['type'=>$type,'asOf'=>$asOf,'rows'=>$rows,'totals'=>$totals]);
 }
}

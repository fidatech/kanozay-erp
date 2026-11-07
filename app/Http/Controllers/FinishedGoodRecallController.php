<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ProductionBatch;
use App\Models\SalesInvoiceLine;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinishedGoodRecallController extends Controller
{
 private function guard(Request $request,bool $write=false):void
 {
  abort_unless($write
    ?($request->user()->can('administration.update') ||
      ($request->user()->can('sales.update')&&$request->user()->can('laboratory.view')))
    :$request->user()->canAny(['laboratory.view','sales.view','inventory.view','administration.view']),403);
 }

 private function recalled(ProductionBatch $batch):object
 {
  $control=DB::table('finished_good_lot_controls')->where('production_batch_id',$batch->id)->first();
  if(!$control||$control->status!=='recalled')
   throw ValidationException::withMessages(['batch'=>'A permanent QC recall must be recorded before contacting affected customers.']);
  return $control;
 }

 public function show(Request $request,ProductionBatch $batch)
 {
  $this->guard($request);
  $this->recalled($batch);
  $batch->load('finishedGood');
  $lines=DB::table('sales_invoice_lines as l')
    ->join('sales_invoices as i','i.id','=','l.sales_invoice_id')
    ->join('trade_parties as c','c.id','=','i.customer_id')
    ->leftJoin('finished_good_recall_contacts as r','r.sales_invoice_line_id','=','l.id')
    ->where('l.production_batch_id',$batch->id)
    ->select('l.id as invoice_line_id','l.quantity_packages','i.number as invoice_number',
     'i.invoice_date','i.id as invoice_id','c.name as customer_name','c.phone as customer_phone',
     'c.email as customer_email','c.address as customer_address','i.currency_code',
     'r.status as contact_status','r.method','r.evidence_reference','r.contacted_at')
    ->orderBy('i.invoice_date')->orderBy('l.id')->paginate(50);
  $ids=$lines->pluck('invoice_line_id');
  $returned=DB::table('sales_credit_note_lines as cl')
    ->join('sales_credit_notes as n','n.id','=','cl.sales_credit_note_id')
    ->whereIn('cl.sales_invoice_line_id',$ids)
    ->select('cl.sales_invoice_line_id')->selectRaw('SUM(cl.quantity_packages) as credited_packages')
    ->groupBy('cl.sales_invoice_line_id')->pluck('credited_packages','sales_invoice_line_id');
  $delivery=DB::table('sales_delivery_note_lines as dl')
    ->join('sales_delivery_notes as d','d.id','=','dl.sales_delivery_note_id')
    ->where('d.status','!=','cancelled')->whereIn('dl.sales_invoice_line_id',$ids)
    ->select('dl.sales_invoice_line_id')
    ->selectRaw("SUM(CASE WHEN d.status = 'delivered' THEN dl.quantity_packages ELSE 0 END) as delivered_packages")
    ->selectRaw('SUM(dl.quantity_packages) as documented_packages')
    ->groupBy('dl.sales_invoice_line_id')->get()->keyBy('sales_invoice_line_id');
  $total=DB::table('sales_invoice_lines')->where('production_batch_id',$batch->id)->sum('quantity_packages');
  $credited=DB::table('sales_credit_note_lines')->where('production_batch_id',$batch->id)->sum('quantity_packages');
  $contacts=DB::table('finished_good_recall_contacts')->where('production_batch_id',$batch->id)
    ->selectRaw("SUM(CASE WHEN status = 'contacted' THEN 1 ELSE 0 END) as notified_count")
    ->selectRaw("SUM(CASE WHEN status = 'recovered' THEN 1 ELSE 0 END) as recovered_count")->first();
  return view('inventory.finished-good-recall',compact(
    'batch','lines','returned','delivery','total','credited','contacts'
  ));
 }

 public function contact(Request $request,ProductionBatch $batch,SalesInvoiceLine $line)
 {
  $this->guard($request,true);
  $data=$request->validate([
   'status'=>['required',Rule::in(['contacted','recovered'])],
   'method'=>['required',Rule::in(['phone','email','whatsapp','in_person','letter'])],
   'evidence_reference'=>['required','string','min:3','max:180'],
   'notes'=>['nullable','string','max:3000'],
  ]);
  DB::transaction(function()use($request,$batch,$line,$data){
   $locked=ProductionBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
   $this->recalled($locked);
   $line=SalesInvoiceLine::whereKey($line->id)->where('production_batch_id',$locked->id)
    ->lockForUpdate()->firstOrFail();
   $existing=DB::table('finished_good_recall_contacts')
    ->where('sales_invoice_line_id',$line->id)->lockForUpdate()->first();
   if($existing && $existing->status==='recovered')
    throw ValidationException::withMessages(['status'=>'Recovery record is final. Financial refund and stock write-off remain separate.']);
   if($data['status']==='recovered' && (!$existing || $existing->status!=='contacted'))
    throw ValidationException::withMessages(['status'=>'Customer must first be contacted before recovery can be recorded.']);
   if($existing && $existing->status===$data['status'] &&
       $existing->evidence_reference===$data['evidence_reference'])
    throw ValidationException::withMessages(['evidence_reference'=>'This recall communication is already recorded.']);
   if($existing){
    DB::table('finished_good_recall_contacts')->where('id',$existing->id)->update([
      'status'=>$data['status'],'method'=>$data['method'],
      'evidence_reference'=>$data['evidence_reference'],'notes'=>$data['notes']??null,
      'updated_by'=>$request->user()->id,'updated_at'=>now(),
    ]);
    $id=$existing->id;
   }else{
    $id=DB::table('finished_good_recall_contacts')->insertGetId([
      'production_batch_id'=>$locked->id,'sales_invoice_line_id'=>$line->id,
      'status'=>$data['status'],'method'=>$data['method'],
      'evidence_reference'=>$data['evidence_reference'],'notes'=>$data['notes']??null,
      'updated_by'=>$request->user()->id,'contacted_at'=>now(),
      'created_at'=>now(),'updated_at'=>now(),
    ]);
   }
   DB::table('finished_good_recall_contact_events')->insert([
     'finished_good_recall_contact_id'=>$id,'status'=>$data['status'],
     'method'=>$data['method'],'evidence_reference'=>$data['evidence_reference'],
     'notes'=>$data['notes']??null,'actor_id'=>$request->user()->id,
     'created_at'=>now(),'updated_at'=>now(),
   ]);
   ActivityLog::record('finished_good.recall.contact','production_batch',$locked->id,
    'Recall customer follow-up recorded for invoice line '.$line->id,
    ['contact_id'=>$id,'status'=>$data['status']]);
  });
  return back()->with('success','Recall contact/recovery evidence saved. No inventory or refund was posted.');
 }

 public function export(Request $request,ProductionBatch $batch)
 {
  $this->guard($request);
  $this->recalled($batch);
  $rows=DB::table('sales_invoice_lines as l')
    ->join('sales_invoices as i','i.id','=','l.sales_invoice_id')
    ->join('trade_parties as c','c.id','=','i.customer_id')
    ->leftJoin('finished_good_recall_contacts as r','r.sales_invoice_line_id','=','l.id')
    ->where('l.production_batch_id',$batch->id)
    ->select('i.number','i.invoice_date','c.name','c.phone','c.email','c.address',
      'l.quantity_packages','r.status','r.method','r.evidence_reference')->orderBy('l.id')->cursor();
  return response()->streamDownload(function()use($rows){
   $stream=fopen('php://output','w');
   fputcsv($stream,['Invoice','Invoice date','Customer','Phone','Email','Address','Packages','Recall status','Contact method','Evidence reference']);
   foreach($rows as $record){
    $values=[$record->number,$record->invoice_date,$record->name,$record->phone,
      $record->email,$record->address,$record->quantity_packages,$record->status,
      $record->method,$record->evidence_reference];
    fputcsv($stream,array_map(function($v){
      $s=(string)($v??'');
      return preg_match('/^\s*[=+\-@]/u',$s)?"'".$s:$s;
    },$values));
   }
   fclose($stream);
  },'kanozay-recall-'.$batch->batch_number.'.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
 }
}

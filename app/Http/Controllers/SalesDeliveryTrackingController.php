<?php
namespace App\Http\Controllers;

use App\Models\SalesDeliveryNote;
use App\Models\SalesDeliveryTrackingEvent;
use App\Services\SalesDeliveryTrackingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalesDeliveryTrackingController extends Controller
{
 public function index(Request $request){
  abort_unless($request->user()->canAny(['sales.view','administration.view']),403);
  $filter=$request->validate(['note_id'=>['nullable','integer','exists:sales_delivery_notes,id']]);
  $notes=SalesDeliveryNote::with('invoice.customer')->latest('id')->limit(400)->get();
  $selected=!empty($filter['note_id'])?SalesDeliveryNote::with('invoice.customer')->findOrFail($filter['note_id']):null;
  $events=SalesDeliveryTrackingEvent::with(['note.invoice.customer','actor'])
   ->when($selected,fn($query)=>$query->where('sales_delivery_note_id',$selected->id))
   ->latest('occurred_at')->latest('id')->paginate(25)->withQueryString();
  return view('sales.delivery-tracking',compact('notes','selected','events'));
 }
 public function store(Request $request,SalesDeliveryTrackingService $service){
  abort_unless($request->user()->canAny(['sales.update','administration.update']),403);
  $data=$request->validate([
   'submission_token'=>['required','uuid'],
   'sales_delivery_note_id'=>['required','integer','exists:sales_delivery_notes,id'],
   'status'=>['required',Rule::in(['loaded','in_transit','attempted','delivered','returned'])],
   'occurred_at'=>['required','date','before_or_equal:now'],
   'location'=>['required','string','min:2','max:180'],
   'proof_reference'=>['nullable','string','max:180'],
   'receiver_name'=>['nullable','string','max:150'],
   'notes'=>['nullable','string','max:3000'],
  ]);
  $note=SalesDeliveryNote::findOrFail($data['sales_delivery_note_id']);
  $service->record($note,$data,$request->user()->id);
  return redirect()->route('workspace.page',['sales','delivery-tracking'],false)
   ->with('success','Delivery milestone recorded with source-note audit history.');
 }
}
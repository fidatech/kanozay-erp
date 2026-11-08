<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\SalesDeliveryNote;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Support\LocalizedDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalesDeliveryNoteController extends Controller
{
    private function authorizeAction(Request $request,string $action):void
    {
        $admin=$action==='view'?'administration.view':'administration.update';
        abort_unless($request->user()->canAny(['sales.'.$action,$admin]),403);
    }

    public function index(Request $request)
    {
        $this->authorizeAction($request,'view');
        $notes=SalesDeliveryNote::with(['invoice.customer','creator'])
            ->orderByDesc('id')->paginate(25);
        return view('sales.delivery-notes.index',compact('notes'));
    }

    public function create(Request $request)
    {
        $this->authorizeAction($request,'create');
        $invoices=SalesInvoice::query()->with('customer')->where('status','posted')->orderByDesc('id')->limit(500)->get();
        $invoice=null;
        $remaining=[];
        if($request->filled('invoice_id')){
            $id=$request->validate(['invoice_id'=>['required','integer','exists:sales_invoices,id']])['invoice_id'];
            $invoice=SalesInvoice::with(['lines.finishedGood','lines.batch','customer'])->where('status','posted')->findOrFail($id);
            $remaining=$this->remaining($invoice);
        }
        return view('sales.delivery-notes.create',compact('invoices','invoice','remaining'));
    }

    private function remaining(SalesInvoice $invoice):array
    {
        $allocated=DB::table('sales_delivery_note_lines as dl')
            ->join('sales_delivery_notes as d','d.id','=','dl.sales_delivery_note_id')
            ->where('d.sales_invoice_id',$invoice->id)->where('d.status','!=','cancelled')
            ->select('dl.sales_invoice_line_id')->selectRaw('SUM(dl.quantity_packages) as total')
            ->groupBy('dl.sales_invoice_line_id')->pluck('total','sales_invoice_line_id');

        $credited=DB::table('sales_credit_note_lines')
            ->whereIn('sales_invoice_line_id',$invoice->lines->pluck('id'))
            ->select('sales_invoice_line_id')
            ->selectRaw('SUM(quantity_packages) as total')
            ->groupBy('sales_invoice_line_id')->pluck('total','sales_invoice_line_id');
        $remaining=[];
        foreach($invoice->lines as $line){
            $remaining[$line->id]=max(0,(int)$line->quantity_packages
                -(int)($allocated[$line->id]??0)
                -(int)($credited[$line->id]??0));
        }

        return $remaining;
    }

    public function store(Request $request)
    {
        $this->authorizeAction($request,'create');
        $request->merge(['quantities'=>LocalizedDecimal::quantities($request->input('quantities'))]);
        $data=$request->validate([
            'submission_token'=>['required','uuid'],
            'sales_invoice_id'=>['required','integer','exists:sales_invoices,id'],
            'delivery_date'=>['required','date','before_or_equal:today'],
            'delivery_address'=>['nullable','string','max:500'],
            'vehicle_number'=>['nullable','string','max:100'],
            'driver_name'=>['nullable','string','max:150'],
            'reference'=>['nullable','string','max:100'],
            'notes'=>['nullable','string','max:2000'],
            'quantities'=>['required','array','min:1','max:200'],
            'quantities.*'=>['nullable','integer','min:0','max:999999999'],
        ]);

        $note=DB::transaction(function()use($data,$request) {
            $duplicate=SalesDeliveryNote::where('submission_token',$data['submission_token'])->first();
            if($duplicate){
                $submitted=collect($data['quantities'])
                    ->filter(fn($quantity)=>(int)$quantity>0)
                    ->mapWithKeys(fn($quantity,$lineId)=>[(int)$lineId=>(int)$quantity])
                    ->sortKeys()->all();
                $original=$duplicate->lines()
                    ->pluck('quantity_packages','sales_invoice_line_id')
                    ->mapWithKeys(fn($quantity,$lineId)=>[(int)$lineId=>(int)$quantity])
                    ->sortKeys()->all();
                if((int)$duplicate->sales_invoice_id===(int)$data['sales_invoice_id'] &&
                    $duplicate->delivery_date->toDateString()===$data['delivery_date'] &&
                    $original===$submitted &&
                    (string)($duplicate->delivery_address??'')===(string)($data['delivery_address']??'') &&
                    (string)($duplicate->vehicle_number??'')===(string)($data['vehicle_number']??'') &&
                    (string)($duplicate->driver_name??'')===(string)($data['driver_name']??'') &&
                    (string)($duplicate->reference??'')===(string)($data['reference']??'') &&
                    (string)($duplicate->notes??'')===(string)($data['notes']??'')){
                    return $duplicate;
                }
                throw ValidationException::withMessages([
                    'submission_token'=>'This submission token belongs to a different delivery document. Refresh the form before submitting a new note.',
                ]);
            }
            $invoice=SalesInvoice::where('status','posted')->whereKey($data['sales_invoice_id'])
                ->lockForUpdate()->firstOrFail();
            if($data['delivery_date']<$invoice->invoice_date->toDateString()){
                throw ValidationException::withMessages([
                    'delivery_date'=>'Delivery documentation cannot be dated before its posted sales invoice.',
                ]);
            }
            $lines=$invoice->lines()->lockForUpdate()->get()->keyBy('id');
            $allocated=DB::table('sales_delivery_note_lines as l')
                ->join('sales_delivery_notes as d','d.id','=','l.sales_delivery_note_id')
                ->where('d.sales_invoice_id',$invoice->id)->where('d.status','!=','cancelled')
                ->select('l.sales_invoice_line_id')->selectRaw('SUM(l.quantity_packages) as total')
                ->groupBy('l.sales_invoice_line_id')->pluck('total','sales_invoice_line_id');

            $credited=DB::table('sales_credit_note_lines')
                ->whereIn('sales_invoice_line_id',$lines->keys())
                ->select('sales_invoice_line_id')
                ->selectRaw('SUM(quantity_packages) as total')
                ->groupBy('sales_invoice_line_id')->pluck('total','sales_invoice_line_id');

            $requested=[];
            foreach($data['quantities'] as $lineId=>$qty) {
                if($qty===null||$qty===''||(int)$qty===0)continue;
                if(!ctype_digit((string)$lineId)||!$lines->has((int)$lineId)){
                    throw ValidationException::withMessages(['quantities'=>'Delivery includes an invoice line outside the selected invoice.']);
                }
                $line=$lines[(int)$lineId];
                $remaining=(int)$line->quantity_packages
                    -(int)($allocated[$line->id]??0)
                    -(int)($credited[$line->id]??0);
                if($qty>$remaining){
                    throw ValidationException::withMessages(['quantities'=>'Delivery quantity exceeds packages remaining after prior delivery notes and credited customer returns.']);
                }
                $requested[(int)$lineId]=(int)$qty;
            }
            if(!$requested)throw ValidationException::withMessages(['quantities'=>'Enter a positive quantity for at least one invoice line.']);

            $note=SalesDeliveryNote::create([
                'number'=>'TMP-'.Str::uuid(),
                'submission_token'=>$data['submission_token'],
                'sales_invoice_id'=>$invoice->id,
                'delivery_date'=>$data['delivery_date'],
                'delivery_address'=>$data['delivery_address']??null,
                'vehicle_number'=>$data['vehicle_number']??null,
                'driver_name'=>$data['driver_name']??null,
                'reference'=>$data['reference']??null,
                'notes'=>$data['notes']??null,
                'status'=>'issued',
                'created_by'=>$request->user()->id,
            ]);
            foreach($requested as $lineId=>$qty){
                $note->lines()->create(['sales_invoice_line_id'=>$lineId,'quantity_packages'=>$qty]);
            }
            $note->update(['number'=>'DN-'.str_pad((string)$note->id,7,'0',STR_PAD_LEFT)]);
            ActivityLog::record('sales.delivery_note.issued','sales_delivery_note',$note->id,
                'Delivery note issued from posted invoice '.$invoice->number.'. No second stock movement or journal posting.');
            return $note;
        });

        return redirect()->route('sales.delivery-notes.show',$note)->with('success','Delivery note issued.');
    }

    public function show(Request $request,SalesDeliveryNote $note)
    {
        $this->authorizeAction($request,'view');
        $note->load(['invoice.customer','invoice.currency','lines.invoiceLine.finishedGood','lines.invoiceLine.batch','creator']);
        return view('sales.delivery-notes.show',compact('note'));
    }

    public function delivered(Request $request,SalesDeliveryNote $note)
    {
        $this->authorizeAction($request,'update');
        $data=$request->validate([
            'received_by'=>['required','string','max:150'],
            'delivered_at'=>['required','date','before_or_equal:now'],
            'delivery_location'=>['nullable','string','max:180'],
            'proof_reference'=>['required','string','min:3','max:180'],
            'submission_token'=>['nullable','uuid'],
        ]);
        if(empty($data['delivery_location'])){
            throw ValidationException::withMessages([
                'delivery_location'=>'Customer delivery confirmation requires a checkpoint/location and a proof reference.',
            ]);
        }
        app(\App\Services\SalesDeliveryTrackingService::class)->record($note,[
            'submission_token'=>$data['submission_token']??(string)Str::uuid(),
            'status'=>'delivered','occurred_at'=>$data['delivered_at'],
            'location'=>$data['delivery_location'],'proof_reference'=>$data['proof_reference'],
            'receiver_name'=>$data['received_by'],'notes'=>'Confirmed from delivery note.',
        ],$request->user()->id);
        return back()->with('success','Delivery receipt confirmed.');
    }

    public function cancel(Request $request,SalesDeliveryNote $note)
    {
        $this->authorizeAction($request,'update');
        $data=$request->validate(['reason'=>['required','string','min:5','max:1000']]);
        DB::transaction(function()use($request,$note,$data){
            $locked=SalesDeliveryNote::whereKey($note->id)->lockForUpdate()->firstOrFail();
            if(!in_array($locked->status,['issued','returned'],true)){
                throw ValidationException::withMessages(['status'=>'Confirmed customer deliveries cannot be cancelled.']);
            }
            $tracking=\App\Models\SalesDeliveryTrackingEvent::where('sales_delivery_note_id',$locked->id)->get();
            if($locked->status==='issued' && $tracking->isNotEmpty() &&
               !$tracking->contains('status','returned')){
                throw ValidationException::withMessages([
                    'status'=>'A delivery with recorded loading or transit evidence cannot be cancelled until logistics records a documented return.',
                ]);
            }
            if($locked->status==='returned' && !$tracking->contains('status','returned')){
                throw ValidationException::withMessages([
                    'status'=>'A returned note requires its source tracking return event before administrative cancellation.',
                ]);
            }
            $locked->update([
                'status'=>'cancelled','cancelled_by'=>$request->user()->id,
                'cancelled_at'=>now(),'notes'=>trim(($locked->notes??'')."\nCancellation reason: ".$data['reason']),
            ]);
            ActivityLog::record('sales.delivery_note.cancelled','sales_delivery_note',$locked->id,
                'Logistics document cancelled. Previously dispatched invoice stock was not restored.');
        });
        return back()->with('success','Delivery note cancelled. Invoiced inventory dispatch remains unchanged.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\SalesDeliveryNote;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
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

        $remaining=[];
        foreach($invoice->lines as $line){
            $remaining[$line->id]=max(0,(int)$line->quantity_packages-(int)($allocated[$line->id]??0));
        }

        return $remaining;
    }

    public function store(Request $request)
    {
        $this->authorizeAction($request,'create');
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
                if($duplicate->sales_invoice_id===(int)$data['sales_invoice_id'])return $duplicate;
                throw ValidationException::withMessages(['submission_token'=>'This token belongs to a different delivery note.']);
            }
            $invoice=SalesInvoice::where('status','posted')->whereKey($data['sales_invoice_id'])
                ->lockForUpdate()->firstOrFail();
            $lines=$invoice->lines()->lockForUpdate()->get()->keyBy('id');
            $allocated=DB::table('sales_delivery_note_lines as l')
                ->join('sales_delivery_notes as d','d.id','=','l.sales_delivery_note_id')
                ->where('d.sales_invoice_id',$invoice->id)->where('d.status','!=','cancelled')
                ->select('l.sales_invoice_line_id')->selectRaw('SUM(l.quantity_packages) as total')
                ->groupBy('l.sales_invoice_line_id')->pluck('total','sales_invoice_line_id');

            $requested=[];
            foreach($data['quantities'] as $lineId=>$qty) {
                if($qty===null||$qty===''||(int)$qty===0)continue;
                if(!ctype_digit((string)$lineId)||!$lines->has((int)$lineId)){
                    throw ValidationException::withMessages(['quantities'=>'Delivery includes an invoice line outside the selected invoice.']);
                }
                $line=$lines[(int)$lineId];
                $remaining=(int)$line->quantity_packages-(int)($allocated[$line->id]??0);
                if($qty>$remaining){
                    throw ValidationException::withMessages(['quantities'=>'Delivery quantity exceeds undelivered invoice quantity for the selected line.']);
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
        ]);
        DB::transaction(function()use($request,$note,$data){
            $locked=SalesDeliveryNote::whereKey($note->id)->lockForUpdate()->firstOrFail();
            if($locked->status!=='issued')throw ValidationException::withMessages(['status'=>'Only issued delivery notes can be marked delivered.']);
            $locked->update([
                'status'=>'delivered','received_by'=>$data['received_by'],
                'delivered_at'=>$data['delivered_at'],'delivered_by'=>$request->user()->id,
            ]);
            ActivityLog::record('sales.delivery_note.delivered','sales_delivery_note',$locked->id,
                'Customer delivery confirmed; logistics record only.');
        });
        return back()->with('success','Delivery receipt confirmed.');
    }

    public function cancel(Request $request,SalesDeliveryNote $note)
    {
        $this->authorizeAction($request,'update');
        $data=$request->validate(['reason'=>['required','string','min:5','max:1000']]);
        DB::transaction(function()use($request,$note,$data){
            $locked=SalesDeliveryNote::whereKey($note->id)->lockForUpdate()->firstOrFail();
            if($locked->status!=='issued')throw ValidationException::withMessages(['status'=>'Only unconfirmed delivery notes can be cancelled.']);
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

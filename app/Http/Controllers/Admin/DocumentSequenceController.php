<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\DocumentSequence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class DocumentSequenceController extends Controller {
    public const TYPES=['purchase_request','purchase_order','goods_receipt','stock_transfer','production_order','lab_sample','sales_quote','sales_order','sales_invoice','delivery_note','journal_entry','payment','receipt'];
    public const PREFIXES=['purchase_request'=>'PR-','purchase_order'=>'PO-','goods_receipt'=>'GRN-','stock_transfer'=>'ST-','production_order'=>'PROD-','lab_sample'=>'LAB-','sales_quote'=>'SQ-','sales_order'=>'SO-','sales_invoice'=>'INV-','delivery_note'=>'DN-','journal_entry'=>'JE-','payment'=>'PAY-','receipt'=>'REC-'];
    public function index(Request $request){abort_unless($request->user()->can('administration.view'),403);return view('admin.sequences.index',['sequences'=>DocumentSequence::orderBy('document_type')->get(),'types'=>self::TYPES,'prefixes'=>self::PREFIXES]);}
    public function store(Request $request){abort_unless($request->user()->can('administration.update'),403);$data=$request->validate(['document_type'=>['required',Rule::in(self::TYPES),Rule::unique('document_sequences')],'next_number'=>['required','integer','min:1','max:999999999'],'padding'=>['required','integer','between:2,12']]);DB::transaction(function()use($data,$request){$s=DocumentSequence::create($data+['prefix'=>self::PREFIXES[$data['document_type']],'updated_by'=>$request->user()->id]);ActivityLog::record('sequence.created','document_sequence',$s->id,'Document numbering configured: '.$s->document_type);});return back()->with('status','Document sequence created.');}
    public function update(Request $request,DocumentSequence $sequence){abort_unless($request->user()->can('administration.update'),403);$data=$request->validate(['padding'=>['required','integer','between:2,12'],'is_active'=>['required','boolean']]);DB::transaction(function()use($data,$request,$sequence){$sequence->update($data+['updated_by'=>$request->user()->id]);ActivityLog::record('sequence.updated','document_sequence',$sequence->id,'Document numbering updated: '.$sequence->document_type);});return back()->with('status','Document sequence updated.');}
}

<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Support\LocalizedDecimal;
use App\Models\FinishedGood;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMaterial;
use App\Models\ProductionBatch;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LaboratoryQualityController extends Controller
{
 private const PAGES=['samples','tests','specifications','incoming','in-process','finished',
  'nonconformances','certificates','holds','inspections'];

 private function authorizeAction(Request $request,string $mode):void
 {
  $options=match($mode) {
   'manage'=>['laboratory.update','administration.update'],
   'test'=>['laboratory.create','administration.update'],
   default=>['laboratory.view','administration.view'],
  };
  abort_unless($request->user()->canAny($options),403);
 }

 public function index(Request $request,string $page)
 {
  abort_unless(in_array($page,self::PAGES,true),404);
  $this->authorizeAction($request,'view');
  $query=DB::table('quality_samples as s')
   ->leftJoin('production_batches as b','b.id','=','s.production_batch_id')
   ->leftJoin('finished_goods as f','f.id','=','b.finished_good_id')
   ->leftJoin('goods_receipt_lines as l','l.id','=','s.goods_receipt_line_id')
   ->leftJoin('inventory_materials as m','m.id','=','l.inventory_material_id')
   ->select('s.*','b.batch_number','f.name as finished_good_name','m.name as material_name');
  if($page==='incoming')$query->where('s.source_type','incoming_material');
  if($page==='finished')$query->where('s.source_type','finished_batch');
  if($page==='nonconformances')$query->where('s.status','rejected');
  if($page==='certificates')$query->where('s.status','approved');
  if($page==='holds')$query->whereIn('s.status',['registered','in_testing','rejected']);
  if($page==='in-process')$query->whereIn('s.status',['registered','in_testing']);
  $samples=$query->orderByDesc('s.id')->paginate(25)->withQueryString();
  $sampleIds=$samples->getCollection()->pluck('id');
  $results=DB::table('quality_sample_results as r')
   ->join('quality_specifications as spec','spec.id','=','r.quality_specification_id')
   ->whereIn('r.quality_sample_id',$sampleIds)->select('r.*','spec.test_name','spec.unit')
   ->orderBy('r.id')->get()->groupBy('quality_sample_id');
  return view('laboratory.quality',[
   'page'=>$page,'pageTitle'=>config('erp_pages.laboratory.pages.'.$page),
   'samples'=>$samples,'results'=>$results,
   'specs'=>DB::table('quality_specifications')->where('is_active',true)->orderBy('test_name')->get(),
   'materials'=>InventoryMaterial::where('is_active',true)->orderBy('name')->get(),
   'goods'=>FinishedGood::where('is_active',true)->orderBy('name')->get(),
   'batches'=>ProductionBatch::with('finishedGood')->where('status','pending_qc')->orderByDesc('id')->limit(500)->get(),
   'receipts'=>GoodsReceiptLine::with(['receipt','material'])->whereHas('receipt',fn($q)=>$q->where('status','posted'))
    ->orderByDesc('id')->limit(500)->get(),
  ]);
 }

 public function specification(Request $request)
 {
  $this->authorizeAction($request,'manage');
  $request->merge([
   'minimum'=>LocalizedDecimal::normalize($request->input('minimum')),
   'maximum'=>LocalizedDecimal::normalize($request->input('maximum')),
  ]);
  $data=$request->validate([
   'subject_type'=>['required',Rule::in(['material','finished_good'])],
   'subject_id'=>['required','integer','min:1'],
   'test_name'=>['required','string','min:2','max:120'],
   'method'=>['nullable','string','max:180'],
   'kind'=>['required',Rule::in(['numeric','qualitative'])],
   'minimum'=>['nullable','numeric','decimal:0,6'],
   'maximum'=>['nullable','numeric','decimal:0,6'],
   'unit'=>['nullable','string','max:40'],
  ]);
  $source=$data['subject_type']==='material'
    ?InventoryMaterial::where('is_active',true)->find($data['subject_id'])
    :FinishedGood::where('is_active',true)->find($data['subject_id']);
  if(!$source)throw ValidationException::withMessages(['subject_id'=>'Choose an active material or finished product.']);
  if($data['kind']==='numeric'){
   if(($data['minimum']??null)===null&&($data['maximum']??null)===null)
    throw ValidationException::withMessages(['minimum'=>'Numeric QC requires at least one boundary.']);
   if(isset($data['minimum'],$data['maximum'])&&BigDecimal::of((string)$data['minimum'])->isGreaterThan((string)$data['maximum']))
    throw ValidationException::withMessages(['maximum'=>'Maximum must be at least the minimum.']);
  }
  DB::transaction(function()use($request,$data){
   $existing=DB::table('quality_specifications')->where('subject_type',$data['subject_type'])
    ->where('subject_id',$data['subject_id'])->where('test_name',$data['test_name'])
    ->lockForUpdate()->orderByDesc('version')->first();
   $version=$existing?(int)$existing->version+1:1;
   if($existing)DB::table('quality_specifications')->where('subject_type',$data['subject_type'])
    ->where('subject_id',$data['subject_id'])->where('test_name',$data['test_name'])
    ->update(['is_active'=>false,'updated_at'=>now()]);
   $id=DB::table('quality_specifications')->insertGetId([
    'subject_type'=>$data['subject_type'],'subject_id'=>$data['subject_id'],
    'test_name'=>$data['test_name'],'method'=>$data['method']??null,'kind'=>$data['kind'],
    'minimum'=>$data['kind']==='numeric'?($data['minimum']??null):null,
    'maximum'=>$data['kind']==='numeric'?($data['maximum']??null):null,
    'unit'=>$data['unit']??null,'version'=>$version,'is_active'=>true,
    'approved_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
   ]);
   ActivityLog::record('quality.specification.approved','quality_specification',$id,
    'Approved QC test specification version '.$version);
  });
  return redirect()->route('workspace.page',['laboratory','specifications'])->with('success','Quality specification approved.');
 }

 public function sample(Request $request)
 {
  $this->authorizeAction($request,'test');
  $data=$request->validate([
   'submission_token'=>['required','uuid'],
   'source_type'=>['required',Rule::in(['incoming_material','finished_batch'])],
   'production_batch_id'=>['nullable','integer','exists:production_batches,id'],
   'goods_receipt_line_id'=>['nullable','integer','exists:goods_receipt_lines,id'],
   'collected_on'=>['required','date','before_or_equal:today'],
   'notes'=>['nullable','string','max:2500'],
  ]);
  DB::transaction(function()use($request,$data){
   $existing=DB::table('quality_samples')->where('submission_token',$data['submission_token'])->first();
   if($existing)throw ValidationException::withMessages(['submission_token'=>'Duplicate sample submission.']);
   $batchId=null;$receiptLineId=null;$subjectType=null;$subjectId=null;
   if($data['source_type']==='finished_batch'){
    if(empty($data['production_batch_id']) || !empty($data['goods_receipt_line_id']))
     throw ValidationException::withMessages(['production_batch_id'=>'Choose a production batch only.']);
    $batch=ProductionBatch::where('status','pending_qc')->find($data['production_batch_id']);
    if(!$batch)throw ValidationException::withMessages(['production_batch_id'=>'Choose a batch awaiting QC approval.']);
    $batchId=$batch->id;$subjectType='finished_good';$subjectId=$batch->finished_good_id;
   }else{
    if(empty($data['goods_receipt_line_id']) || !empty($data['production_batch_id']))
     throw ValidationException::withMessages(['goods_receipt_line_id'=>'Choose a posted GRN line only.']);
    $line=GoodsReceiptLine::with('receipt')->find($data['goods_receipt_line_id']);
    if(!$line||$line->receipt?->status!=='posted')
     throw ValidationException::withMessages(['goods_receipt_line_id'=>'Only posted receipt lines can be inspected.']);
    $receiptLineId=$line->id;$subjectType='material';$subjectId=$line->inventory_material_id;
   }
   $current=DB::table('quality_specifications')
    ->where('subject_type',$subjectType)->where('subject_id',$subjectId)
    ->where('is_active',true)->orderBy('id')->lockForUpdate()->get();
   if($current->isEmpty())
    throw ValidationException::withMessages(['source_type'=>'Approve a test specification for this material or product first.']);
   $latest=DB::table('quality_samples')
    ->where($batchId?'production_batch_id':'goods_receipt_line_id',$batchId??$receiptLineId)
    ->orderByDesc('id')->lockForUpdate()->first();
   if($latest && in_array($latest->status,['registered','in_testing'],true))
    throw ValidationException::withMessages(['source_type'=>'This source has a pending sample. Complete its review before collecting another.']);
   if($latest && $latest->status==='approved'){
    // A new sample must be justified by an explicit hold; otherwise a
    // duplicate may silently replace an independently approved disposition.
    $held=$receiptLineId && DB::table('quality_material_lot_holds as h')
      ->join('inventory_cost_layers as l','l.id','=','h.inventory_cost_layer_id')
      ->where('l.source_type','goods_receipt_line')->where('l.source_id',$receiptLineId)
      ->where('h.status','held')->exists();
    if(!$held)
     throw ValidationException::withMessages(['source_type'=>'Already approved source: open an authorized lot hold before collecting a replacement sample.']);
   }
   if($latest && mb_strlen(trim((string)($data['notes']??'')))<10)
    throw ValidationException::withMessages(['notes'=>'For a repeat sample, document the retest reason (at least 10 characters).']);
   $id=DB::table('quality_samples')->insertGetId([
    'number'=>'TMP-'.Str::uuid(),'submission_token'=>$data['submission_token'],
    'source_type'=>$data['source_type'],'production_batch_id'=>$batchId,
    'goods_receipt_line_id'=>$receiptLineId,'collected_on'=>$data['collected_on'],
    'status'=>'registered','notes'=>$data['notes']??null,
    'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
   ]);
   DB::table('quality_samples')->where('id',$id)
    ->update(['number'=>'QC-'.str_pad((string)$id,7,'0',STR_PAD_LEFT)]);
   foreach($current as $specification){
    DB::table('quality_sample_required_specifications')->insert([
      'quality_sample_id'=>$id,'quality_specification_id'=>$specification->id,
      'created_at'=>now(),'updated_at'=>now(),
    ]);
   }
   ActivityLog::record('quality.sample.registered','quality_sample',$id,'Laboratory sample registered.');
  });
  return back()->with('success','Laboratory sample registered.');
 }

 private function details(int $sampleId):array
 {
  $sample=DB::table('quality_samples')->where('id',$sampleId)->first();
  abort_unless($sample,404);
  if($sample->source_type==='finished_batch'){
   $batch=ProductionBatch::findOrFail($sample->production_batch_id);
   $subjectType='finished_good';$subjectId=$batch->finished_good_id;
  }else{
   $line=GoodsReceiptLine::findOrFail($sample->goods_receipt_line_id);
   $subjectType='material';$subjectId=$line->inventory_material_id;
  }
  $requirements=DB::table('quality_sample_required_specifications')
   ->where('quality_sample_id',$sampleId)->pluck('quality_specification_id');
  $legacyRequirements=$requirements->isEmpty();
  // For pre-migration samples a historical specification set was never
  // frozen. Keep a visible legacy marker and require active tests on review.
  $specs=DB::table('quality_specifications')
   ->where('subject_type',$subjectType)->where('subject_id',$subjectId)
   ->when($legacyRequirements,fn($q)=>$q->where('is_active',true))
   ->when(!$legacyRequirements,fn($q)=>$q->whereIn('id',$requirements))
   ->orderBy('test_name')->get();
  $results=DB::table('quality_sample_results')->where('quality_sample_id',$sampleId)->get()
   ->keyBy('quality_specification_id');
  return compact('sample','specs','results','legacyRequirements');
 }

 public function show(Request $request,int $sample)
 {
  $this->authorizeAction($request,'view');
  return view('laboratory.sample', $this->details($sample));
 }

 public function result(Request $request,int $sample)
 {
  $this->authorizeAction($request,'test');
  $request->merge(['observed_value'=>LocalizedDecimal::normalize($request->input('observed_value'))]);
  $data=$request->validate([
   'quality_specification_id'=>['required','integer','exists:quality_specifications,id'],
   'observed_value'=>['nullable','numeric','decimal:0,6'],
   'outcome'=>['nullable',Rule::in(['pass','fail'])],
   'observation'=>['nullable','string','max:3000'],
  ]);
  DB::transaction(function()use($request,$sample,$data){
   $s=DB::table('quality_samples')->where('id',$sample)->lockForUpdate()->first();
   abort_unless($s,404);
   if(!in_array($s->status,['registered','in_testing'],true))
    throw ValidationException::withMessages(['status'=>'Reviewed samples cannot be changed.']);
   $detail=$this->details($sample);
   $spec=$detail['specs']->firstWhere('id',$data['quality_specification_id']);
   if(!$spec)throw ValidationException::withMessages(['quality_specification_id'=>'Specification does not match sample subject.']);
   if($detail['results']->has($spec->id))
    throw ValidationException::withMessages(['quality_specification_id'=>'Result already recorded for this specification.']);
   $observed=null;
   if($spec->kind==='numeric'){
    if(($data['observed_value']??null)===null)
     throw ValidationException::withMessages(['observed_value'=>'Enter numeric observation.']);
    $observed=BigDecimal::of((string)$data['observed_value'])->toScale(6);
    $pass=($spec->minimum===null || !$observed->isLessThan((string)$spec->minimum)) &&
      ($spec->maximum===null || !$observed->isGreaterThan((string)$spec->maximum));
    $outcome=$pass?'pass':'fail';
   }else{
    if(!isset($data['outcome']))throw ValidationException::withMessages(['outcome'=>'Choose pass or fail.']);
    $outcome=$data['outcome'];
   }
   DB::table('quality_sample_results')->insert([
    'quality_sample_id'=>$sample,'quality_specification_id'=>$spec->id,
    'observed_value'=>$observed===null?null:(string)$observed,
    'outcome'=>$outcome,'observation'=>$data['observation']??null,
    'tested_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
   ]);
   DB::table('quality_samples')->where('id',$sample)->update(['status'=>'in_testing','updated_at'=>now()]);
   ActivityLog::record('quality.result.recorded','quality_sample',$sample,
    'Laboratory test result '.$outcome.' recorded.');
  });
  return back()->with('success','Laboratory test result recorded.');
 }

 public function review(Request $request,int $sample)
 {
  $this->authorizeAction($request,'manage');
  $data=$request->validate([
   'decision'=>['required',Rule::in(['approved','rejected'])],
   'review_notes'=>['required','string','min:5','max:3000'],
  ]);
  DB::transaction(function()use($request,$sample,$data){
   $s=DB::table('quality_samples')->where('id',$sample)->lockForUpdate()->first();
   abort_unless($s,404);
   if(!in_array($s->status,['registered','in_testing'],true))
    throw ValidationException::withMessages(['decision'=>'Sample has already been reviewed.']);
   if((int)$s->created_by===$request->user()->id)
    throw ValidationException::withMessages(['decision'=>'Independent review is required.']);
   $detail=$this->details($sample);
   // For new samples, requirements are frozen at collection time.
   // Revisions published later cannot rewrite the evidence reviewed here.
   $required=$detail['specs'];
   if($required->isEmpty() || $required->count()!==$detail['results']->whereIn('quality_specification_id',$required->pluck('id'))->count())
    throw ValidationException::withMessages(['decision'=>'Record a result for every specification assigned to this sample.']);
   if(DB::table('quality_sample_results')->where('quality_sample_id',$sample)
     ->where('tested_by',$request->user()->id)->exists())
    throw ValidationException::withMessages(['decision'=>'The tester cannot independently review their own sample.']);
   $hasFailure=$detail['results']->whereIn('quality_specification_id',$required->pluck('id'))->contains('outcome','fail');
   if($hasFailure && $data['decision']==='approved')
    throw ValidationException::withMessages(['decision'=>'Failed laboratory tests cannot be approved.']);
   DB::table('quality_samples')->where('id',$sample)->update([
    'status'=>$data['decision'],'review_notes'=>$data['review_notes'],
    'reviewed_by'=>$request->user()->id,'reviewed_at'=>now(),'updated_at'=>now(),
   ]);
   ActivityLog::record('quality.sample.'.$data['decision'],'quality_sample',$sample,
    'Independent quality review: '.$data['decision']);
  });
  return back()->with('success','QC decision recorded.');
 }

 public function certificate(Request $request,int $sample)
 {
  $this->authorizeAction($request,'view');
  $details=$this->details($sample);
  abort_unless($details['sample']->status==='approved',404);
  return view('laboratory.certificate',$details);
 }
}

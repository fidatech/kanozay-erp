<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\OperationalRegister;
use App\Models\OperationalRegisterEvent;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OperationalRegisterController extends Controller
{
 private const SOURCES=[
  'suppliers'=>['trade_parties','name',['type'=>'supplier','is_active'=>true]],
  'customers'=>['trade_parties','name',['type'=>'customer','is_active'=>true]],
  'materials'=>['inventory_materials','name',['is_active'=>true]],
  'warehouses'=>['company_warehouses','name',['is_active'=>true]],
  'currencies'=>['currencies','code',['is_active'=>true]],
  'purchase_orders'=>['purchase_orders','number',[]],
  'recipes'=>['production_recipes','name',[]],
  'equipment'=>['manufacturing_equipment','name',['is_active'=>true]],
  'finished_goods'=>['finished_goods','name',['is_active'=>true]],
  'sales_invoices'=>['sales_invoices','number',[]],
  'delivery_notes'=>['sales_delivery_notes','number',[]],
  'employees'=>['employee_profiles','full_name',['is_active'=>true]],
  'branches'=>['company_branches','name',['is_active'=>true]],
 ];

 private function definition(string $module,string $page):array
 {
  $definition=(config('operational_registers')??[])[$module.'.'.$page]??null;
  abort_unless(is_array($definition),404);
  return $definition;
 }

 private function allowed(Request $request,string $module,bool $write=false):void
 {
  $perms=$write?[$module.'.create',$module.'.update','administration.update']:
   [$module.'.view','administration.view'];
  abort_unless($request->user()->canAny($perms),403);
 }

 private function options(array $fields):array
 {
  $sources=[];
  foreach($fields as $field){
   if(($field['type']??'')!=='lookup')continue;
   $source=$field['source'];[$table,$label,$where]=self::SOURCES[$source];
   $query=DB::table($table)->select('id',$label)->orderBy($label)->limit(1000);
   foreach($where as $column=>$value)$query->where($column,$value);
   $sources[$source]=$query->get()->map(fn($r)=>['id'=>$r->id,'label'=>$r->$label])->all();
  }
  return $sources;
 }

 private function rules(array $definition):array
 {
  $rules=[];
  foreach($definition['fields'] as $name=>$field){
   $type=$field['type'];
   $r=[($field['required']??false)?'required':'nullable'];
   $r[]=match($type){
    'date'=>'date',
    'time'=>'date_format:H:i',
    'datetime'=>'date',
    'decimal'=>'regex:/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/',
    'integer'=>'integer',
    'text','textarea'=>'string',
    'lookup'=>'integer',
    'option'=>'string',
   };
   if($type==='option')$r[]=Rule::in(array_keys($field['options']));
   if($type==='lookup'){
    [$table,$label,$constraints]=self::SOURCES[$field['source']];
    $exists=Rule::exists($table,'id');
    foreach($constraints as $key=>$val)$exists->where($key,$val);
    $r[]=$exists;
   }
   if(in_array($type,['text','textarea'],true))$r[]='max:'.($type==='textarea'?5000:180);
   if(in_array($type,['integer','decimal'],true)){
    $r[]='numeric';
    if(isset($field['min']))$r[]='min:'.$field['min'];
    elseif($type==='decimal')$r[]='min:0';
    if(isset($field['max']))$r[]='max:'.$field['max'];
   }
   $rules['data.'.$name]=$r;
  }
  return $rules;
 }

 private function normalized(array $fields,array $data):array
 {
  $normalized=[];
  foreach($fields as $key=>$field){
   $value=$data[$key]??null;
   if($value===null||$value===''){$normalized[$key]=null;continue;}
   if($field['type']==='decimal'){
    $number=BigDecimal::of((string)$value);
    $normalized[$key]=(string)$number->toScale(6,\Brick\Math\RoundingMode::Unnecessary);
   }elseif(in_array($field['type'],['integer','lookup'],true))$normalized[$key]=(int)$value;
   else $normalized[$key]=trim((string)$value);
  }
  return $normalized;
 }

 private function validateData(Request $request,array $definition):array
 {
  return $this->normalized(
   $definition['fields'],
   $request->validate($this->rules($definition))['data']??[]
  );
 }

 public function index(Request $request,string $module,string $page)
 {
  $definition=$this->definition($module,$page);
  $this->allowed($request,$module);
  $filter=$request->validate([
   'q'=>['nullable','string','max:100'],
   'status'=>['nullable',Rule::in(['draft','submitted','approved','rejected','closed','cancelled'])],
  ]);
  $workflow=$module.'.'.$page;
  $query=OperationalRegister::with(['creator','reviewer'])->where('workflow',$workflow);
  if(!empty($filter['status']))$query->where('status',$filter['status']);
  if(!empty($filter['q']))$query->where(fn($q)=>$q->where('number','like','%'.$filter['q'].'%')
   ->orWhere('data','like','%'.$filter['q'].'%'));
  return view('operations.register',[
   'module'=>$module,'page'=>$page,'definition'=>$definition,
   'records'=>$query->latest('id')->paginate(25)->withQueryString(),
   'options'=>$this->options($definition['fields']),
  ]);
 }

 public function store(Request $request,string $module,string $page)
 {
  $def=$this->definition($module,$page);$this->allowed($request,$module,true);
  $request->validate(['submission_token'=>['required','uuid']]);
  $data=$this->validateData($request,$def);
  DB::transaction(function()use($request,$module,$page,$data){
   $token=$request->input('submission_token');
   if(OperationalRegister::where('submission_token',$token)->exists())
    throw ValidationException::withMessages(['submission_token'=>'This form was already submitted.']);
   $record=OperationalRegister::create([
    'workflow'=>$module.'.'.$page,'number'=>'TEMP-'.Str::uuid(),
    'submission_token'=>$token,'data'=>$data,'status'=>'draft',
    'created_by'=>$request->user()->id,
   ]);
   $prefix=strtoupper(substr($module,0,3)).'-'.strtoupper(substr($page,0,3));
   $record->update(['number'=>$prefix.'-'.str_pad((string)$record->id,7,'0',STR_PAD_LEFT)]);
   $record->events()->create([
    'action'=>'created','snapshot'=>$data,'user_id'=>$request->user()->id,
   ]);
   ActivityLog::record('operational.record.created','operational_register',$record->id,
    'Created '.$record->number.' ('.$record->workflow.')');
  });
  return redirect()->route('workspace.page',[$module,$page])->with('success','Draft record created.');
 }

 public function update(Request $request,string $module,string $page,OperationalRegister $record)
 {
  $def=$this->definition($module,$page);$this->allowed($request,$module,true);
  $data=$this->validateData($request,$def);
  DB::transaction(function()use($request,$module,$page,$record,$data){
   $locked=OperationalRegister::whereKey($record->id)->lockForUpdate()->firstOrFail();
   $this->sameWorkflow($locked,$module,$page);
   if($locked->status!=='draft')throw ValidationException::withMessages(['record'=>'Only drafts are editable.']);
   if($locked->created_by!==$request->user()->id && !$request->user()->can('administration.update'))
    abort(403);
   $locked->update(['data'=>$data]);
   $locked->events()->create(['action'=>'updated','snapshot'=>$data,'user_id'=>$request->user()->id]);
   ActivityLog::record('operational.record.updated','operational_register',$locked->id,'Updated '.$locked->number);
  });
  return back()->with('success','Draft updated.');
 }

 private function sameWorkflow(OperationalRegister $record,string $module,string $page):void
 {
  abort_unless($record->workflow===$module.'.'.$page,404);
 }

 public function transition(Request $request,string $module,string $page,OperationalRegister $record)
 {
  $this->definition($module,$page);$this->allowed($request,$module,true);
  $validated=$request->validate([
   'decision'=>['required',Rule::in(['submit','approve','reject','close','cancel','revise'])],
   'note'=>['nullable','required_if:decision,reject','string','max:1500'],
  ]);
  DB::transaction(function()use($request,$module,$page,$record,$validated){
   $locked=OperationalRegister::whereKey($record->id)->lockForUpdate()->firstOrFail();
   $this->sameWorkflow($locked,$module,$page);
   $decision=$validated['decision'];
   $states=[
    'draft'=>['submit'=>'submitted','cancel'=>'cancelled'],
    'submitted'=>['approve'=>'approved','reject'=>'rejected'],
    'approved'=>['close'=>'closed'],
    'rejected'=>['revise'=>'draft','cancel'=>'cancelled'],
   ];
   $to=$states[$locked->status][$decision]??null;
   if(!$to)throw ValidationException::withMessages(['decision'=>'Transition is unavailable for this record state.']);
   if(in_array($decision,['approve','reject'],true)){
    abort_unless($request->user()->canAny([$module.'.update','administration.update']),403);
    if($locked->created_by===$request->user()->id)
     throw ValidationException::withMessages(['decision'=>'Independent reviewer must differ from the creator.']);
    if(($request->user()->id===$locked->created_by))
     throw ValidationException::withMessages(['decision'=>'Reviewer cannot approve own record.']);
   }
   if(in_array($decision,['submit','cancel','revise'],true)&&
      $locked->created_by!==$request->user()->id&&!$request->user()->can('administration.update'))
    abort(403);
   $changes=['status'=>$to];
   if(in_array($decision,['approve','reject'],true))$changes+=[
    'reviewed_by'=>$request->user()->id,'reviewed_at'=>now(),
    'review_note'=>$validated['note']??null,
   ];
   if($decision==='close')$changes+=['closed_by'=>$request->user()->id,'closed_at'=>now()];
   if($decision==='revise')$changes+=['reviewed_by'=>null,'reviewed_at'=>null];
   $locked->update($changes);
   $locked->events()->create([
    'action'=>$decision,'snapshot'=>$locked->data,'note'=>$validated['note']??null,
    'user_id'=>$request->user()->id,
   ]);
   ActivityLog::record('operational.record.'.$decision,'operational_register',$locked->id,
    'Record '.$locked->number.' advanced to '.$to);
  });
  return back()->with('success','Workflow state updated.');
 }

 public function show(Request $request,string $module,string $page,OperationalRegister $record)
 {
  $def=$this->definition($module,$page);$this->allowed($request,$module);
  $this->sameWorkflow($record,$module,$page);
  $record->load(['creator','reviewer','events.actor']);
  return view('operations.detail',[
   'module'=>$module,'page'=>$page,'definition'=>$def,
   'record'=>$record,'options'=>$this->options($def['fields']),
  ]);
 }

 public function export(Request $request,string $module,string $page)
 {
  $def=$this->definition($module,$page);$this->allowed($request,$module);
  $rows=OperationalRegister::where('workflow',$module.'.'.$page)->orderBy('id')->cursor();
  return response()->streamDownload(function()use($rows,$def){
   $stream=fopen('php://output','w');
   fputcsv($stream,array_merge(['Number','Status','Created at'],array_map(
    fn($f)=>$f['label'],array_values($def['fields'])
   )));
   foreach($rows as $r){
    $values=[$r->number,$r->status,(string)$r->created_at];
    foreach(array_keys($def['fields']) as $field)$values[]=$r->data[$field]??'';
    fputcsv($stream,array_map(function($v){
     $s=(string)($v??'');
     return preg_match('/^[\s]*[=+\-@]/u',$s)?"'".$s:$s;
    },$values));
   }
   fclose($stream);
  },'kanozay-'.str_replace('.','-',$module.'.'.$page).'-'.today()->format('Ymd').'.csv',
  ['Content-Type'=>'text/csv; charset=UTF-8']);
 }
}

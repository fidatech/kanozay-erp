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


 /**
  * Dedicated cross-field integrity checks for operational records.
  * These records never change money, stock, payroll or production batches.
  */
 private function enforceDomainConstraints(string $module,string $page,array $data,?int $exclude=null):void
 {
  $key=$module.'.'.$page;
  $reject=fn(string $name,string $message)=>throw ValidationException::withMessages([$name=>$message]);

  $duplicates=function(array $fields)use($key,$exclude):bool {
   $q=DB::table('operational_registers')->where('workflow',$key)
    ->whereNotIn('status',['cancelled','rejected']);
   if($exclude!==null)$q->where('id','!=',$exclude);
   foreach($fields as $name=>$value)$q->where('data->'.$name,$value);
   return $q->exists();
  };

  if($key==='purchases.quotations'){
   $party=\App\Models\TradeParty::findOrFail($data['supplier']);
   if(!$party->currencies()->whereKey($data['currency'])->exists())
    $reject('data.currency','Quoted currency is not enabled for this supplier.');
  }
  if($key==='purchases.shipments'){
   $po=\App\Models\PurchaseOrder::findOrFail($data['purchase_order']);
   if($po->source!=='foreign')$reject('data.purchase_order','Shipment tracking is for foreign purchase orders only.');
   if(!empty($data['departed_on'])&&!empty($data['eta'])&&$data['eta']<$data['departed_on'])
    $reject('data.eta','Expected arrival must follow departure.');
  }
  if($key==='purchases.supplier-performance' && $data['period_end']<$data['period_start'])
   $reject('data.period_end','Evaluation end date cannot precede start.');
  if($key==='inventory.locations' && $duplicates(['warehouse'=>$data['warehouse'],'code'=>$data['code']]))
   $reject('data.code','A record with this storage bin code already exists in this warehouse.');
  if($key==='inventory.units' && $duplicates(['symbol'=>$data['symbol']]))
   $reject('data.symbol','This unit symbol is already registered.');
  if($key==='inventory.categories' && $duplicates(['code'=>$data['code']]))
   $reject('data.code','This category code is already registered.');
  if(in_array($key,['manufacturing.processes','manufacturing.routing'],true)){
   $seq=$key==='manufacturing.routing'?'step':'sequence';
   if($duplicates(['recipe'=>$data['recipe'],$seq=>$data[$seq]]))
    $reject('data.'.$seq,'This recipe already has an operation at this sequence number.');
  }
  if($key==='manufacturing.packaging'){
   if(!DB::table('inventory_materials')->where('id',$data['packaging_material'])->where('is_packaging',true)->exists())
    $reject('data.packaging_material','Select a material explicitly classified as packaging.');
  }
  if($key==='laboratory.calibration' && $data['due_on']<=$data['calibrated_on'])
   $reject('data.due_on','Next calibration due date must follow calibration date.');
  if($key==='sales.dispatch'){
   $invoice=\App\Models\SalesInvoice::findOrFail($data['invoice']);
   if($data['planned_on']<$invoice->invoice_date->toDateString())
    $reject('data.planned_on','Dispatch plan cannot precede the source invoice.');
  }
  if($key==='sales.delivery-tracking'){
   $note=\App\Models\SalesDeliveryNote::findOrFail($data['delivery_note']);
   if($data['event_on']<$note->delivery_date->toDateString())
    $reject('data.event_on','Delivery tracking event cannot precede the delivery note.');
   if($data['status']==='delivered' &&
    (empty($data['proof_reference']) || $note->status!=='delivered'))
    $reject('data.status','Use the delivery-note proof-of-receipt workflow and enter its proof reference first.');
  }
  if($key==='sales.sales-targets' && substr($data['target_month'],8,2)!=='01')
   $reject('data.target_month','A monthly sales target must use the first day of its month.');
  if($key==='hr.contracts' && !empty($data['ends_on']) && $data['ends_on']<$data['started_on'])
   $reject('data.ends_on','Contract end date cannot precede effective date.');
  if($key==='hr.overtime'){
   if(\Brick\Math\BigDecimal::of($data['hours'])->isGreaterThan('24'))
    $reject('data.hours','A daily overtime request cannot exceed 24 hours.');
   if($data['worked_on']>today()->toDateString())
    $reject('data.worked_on','Completed overtime must not use a future work date.');
  }
  if($key==='hr.shifts'){
   if($data['starts_at']===$data['ends_at'])
    $reject('data.ends_at','Shift start and end times must differ.');
   $start=\Carbon\CarbonImmutable::parse($data['shift_date'].' '.$data['starts_at']);
   $end=\Carbon\CarbonImmutable::parse($data['shift_date'].' '.$data['ends_at']);
   if($end->lessThanOrEqualTo($start))$end=$end->addDay();
   $rows=DB::table('operational_registers')->where('workflow',$key)
    ->where('data->employee',$data['employee'])
    ->whereNotIn('status',['cancelled','rejected'])->get();
   foreach($rows as $row){
    if($exclude!==null && (int)$row->id===$exclude)continue;
    $other=json_decode($row->data,true);
    if(empty($other['shift_date'])||empty($other['starts_at'])||empty($other['ends_at']))continue;
    $otherStart=\Carbon\CarbonImmutable::parse($other['shift_date'].' '.$other['starts_at']);
    $otherEnd=\Carbon\CarbonImmutable::parse($other['shift_date'].' '.$other['ends_at']);
    if($otherEnd->lessThanOrEqualTo($otherStart))$otherEnd=$otherEnd->addDay();
    if($start->lessThan($otherEnd)&&$end->greaterThan($otherStart))
     $reject('data.shift_date','This employee already has a conflicting shift.');
   }
  }
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
  $this->enforceDomainConstraints($module,$page,$data);
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
  $this->enforceDomainConstraints($module,$page,$data,$record->id);
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

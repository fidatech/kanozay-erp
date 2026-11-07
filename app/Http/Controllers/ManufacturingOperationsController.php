<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\FixedAsset;
use App\Models\ProductionOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManufacturingOperationsController extends Controller
{
 private const PAGES=['equipment','downtime','scheduling','plans','work-orders'];

 private function guard(Request $r,bool $write=false):void
 {
  abort_unless($r->user()->canAny($write?['manufacturing.create','manufacturing.update','administration.update']:
   ['manufacturing.view','administration.view','inventory.view']),403);
 }
 public function index(Request $request,string $page)
 {
  abort_unless(in_array($page,self::PAGES,true),404);
  $this->guard($request);
  return view('production.operations',[
   'page'=>$page,'pageTitle'=>config('erp_pages.manufacturing.pages.'.$page),
   'equipment'=>DB::table('manufacturing_equipment')->orderBy('code')->get(),
   'schedules'=>DB::table('manufacturing_schedules as s')
    ->join('manufacturing_equipment as e','e.id','=','s.manufacturing_equipment_id')
    ->join('production_orders as p','p.id','=','s.production_order_id')
    ->select('s.*','e.code as equipment_code','p.number as order_number')
    ->orderByDesc('s.id')->limit(200)->get(),
   'downtimes'=>DB::table('manufacturing_downtime_entries as d')
    ->join('manufacturing_equipment as e','e.id','=','d.manufacturing_equipment_id')
    ->select('d.*','e.code as equipment_code')->orderByDesc('d.started_at')->limit(200)->get(),
   'orders'=>ProductionOrder::with('recipe')->orderByDesc('id')->limit(300)->get(),
   'fixedAssets'=>FixedAsset::where('status','active')->orderBy('asset_tag')->get(),
  ]);
 }
 public function equipment(Request $request)
 {
  $this->guard($request,true);
  $request->merge(['code'=>strtoupper(trim((string)$request->input('code')))]);
  $data=$request->validate([
   'code'=>['required','regex:/^[A-Z0-9][A-Z0-9._-]{0,39}$/',Rule::unique('manufacturing_equipment','code')],
   'name'=>['required','string','max:180'],
   'fixed_asset_id'=>['nullable',Rule::exists('fixed_assets','id')->where('status','active'),Rule::unique('manufacturing_equipment','fixed_asset_id')],
   'capacity_per_hour'=>['nullable','numeric','gt:0','decimal:0,4'],
   'capacity_unit'=>['nullable','required_with:capacity_per_hour','string','max:30'],
  ]);
  DB::transaction(function()use($request,$data){
   $id=DB::table('manufacturing_equipment')->insertGetId([
    'code'=>$data['code'],'name'=>$data['name'],'fixed_asset_id'=>$data['fixed_asset_id']??null,
    'capacity_per_hour'=>$data['capacity_per_hour']??null,'capacity_unit'=>$data['capacity_unit']??null,
    'is_active'=>true,'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
   ]);
   ActivityLog::record('manufacturing.equipment.created','manufacturing_equipment',$id,'Manufacturing equipment registered.');
  });
  return redirect()->route('workspace.page',['manufacturing','equipment'])->with('success','Equipment registered.');
 }

 public function schedule(Request $request)
 {
  $this->guard($request,true);
  $data=$request->validate([
   'production_order_id'=>['required','integer','exists:production_orders,id',Rule::unique('manufacturing_schedules','production_order_id')],
   'manufacturing_equipment_id'=>['required','integer','exists:manufacturing_equipment,id'],
   'starts_at'=>['required','date'],'ends_at'=>['required','date','after:starts_at'],
   'notes'=>['nullable','string','max:3000'],
  ]);
  $data['starts_at']=\Carbon\CarbonImmutable::parse($data['starts_at'])->format('Y-m-d H:i:s');
  $data['ends_at']=\Carbon\CarbonImmutable::parse($data['ends_at'])->format('Y-m-d H:i:s');
  DB::transaction(function()use($request,$data){
   $equipment=DB::table('manufacturing_equipment')->where('id',$data['manufacturing_equipment_id'])
    ->lockForUpdate()->first();
   abort_unless($equipment,404);
   if(!$equipment->is_active)throw ValidationException::withMessages(['manufacturing_equipment_id'=>'Equipment inactive.']);
   // A recorded and independently approved failed/overdue calibration stops
   // new equipment reservations. Legacy machines with no register history
   // retain their existing scheduling path until a calibration policy is set.
   $calibration=DB::table('operational_registers')
    ->where('workflow','laboratory.calibration')
    ->where('data->equipment',(int)$equipment->id)
    ->whereIn('status',['approved','closed'])
    ->orderByDesc('id')->first();
   if($calibration){
    $cal=json_decode($calibration->data,true);
    if(($cal['result']??null)!=='pass' ||
        empty($cal['due_on']) || $cal['due_on']<substr($data['starts_at'],0,10))
     throw ValidationException::withMessages(['manufacturing_equipment_id'=>
      'Machine calibration is failed, conditional or overdue for the planned start. Schedule it after a new passed QC calibration.']);
   }
   $order=ProductionOrder::whereKey($data['production_order_id'])->lockForUpdate()->firstOrFail();
   if(!in_array($order->status,['planned','reserved'],true))
    throw ValidationException::withMessages(['production_order_id'=>'Schedule only planned or reserved production orders.']);
   if(DB::table('manufacturing_schedules')
    ->where('manufacturing_equipment_id',$equipment->id)->whereIn('status',['planned','running'])
    ->where('starts_at','<',$data['ends_at'])->where('ends_at','>',$data['starts_at'])->exists())
    throw ValidationException::withMessages(['starts_at'=>'Equipment is already scheduled in this time window.']);
   if(DB::table('manufacturing_downtime_entries')->where('manufacturing_equipment_id',$equipment->id)
      ->where('started_at','<',$data['ends_at'])->where('ended_at','>',$data['starts_at'])->exists())
    throw ValidationException::withMessages(['starts_at'=>'Recorded machine downtime conflicts with this planned schedule. Assign a different window or machine.']);
   $id=DB::table('manufacturing_schedules')->insertGetId([
    'production_order_id'=>$order->id,'manufacturing_equipment_id'=>$equipment->id,
    'starts_at'=>$data['starts_at'],'ends_at'=>$data['ends_at'],
    'status'=>'planned','notes'=>$data['notes']??null,
    'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
   ]);
   ActivityLog::record('manufacturing.schedule.created','manufacturing_schedule',$id,
    'Production equipment and time slot allocated.');
  });
  return redirect()->route('workspace.page',['manufacturing','scheduling'])->with('success','Production schedule assigned.');
 }

 public function transition(Request $request,int $schedule)
 {
  abort_unless($request->user()->canAny(['manufacturing.update','administration.update']),403);
  $data=$request->validate(['status'=>['required',Rule::in(['running','completed','cancelled'])]]);
  DB::transaction(function()use($request,$schedule,$data){
   $row=DB::table('manufacturing_schedules')->where('id',$schedule)->lockForUpdate()->first();
   abort_unless($row,404);
   $allowed=$row->status==='planned'?['running','cancelled']:
    ($row->status==='running'?['completed','cancelled']:[]);
   if(!in_array($data['status'],$allowed,true))
    throw ValidationException::withMessages(['status'=>'Invalid or repeated schedule transition.']);
   if($data['status']==='completed' &&
    ProductionOrder::whereKey($row->production_order_id)->value('status')!=='completed')
    throw ValidationException::withMessages(['status'=>'Production order must be completed before its schedule is closed.']);
   DB::table('manufacturing_schedules')->where('id',$schedule)->update([
    'status'=>$data['status'],'updated_by'=>$request->user()->id,'updated_at'=>now(),
   ]);
   ActivityLog::record('manufacturing.schedule.'.$data['status'],'manufacturing_schedule',$schedule,
    'Production schedule status updated.');
  });
  return back()->with('success','Schedule status updated.');
 }

 public function downtime(Request $request)
 {
  $this->guard($request,true);
  $data=$request->validate([
   'submission_token'=>['required','uuid'],
   'manufacturing_equipment_id'=>['required','integer','exists:manufacturing_equipment,id'],
   'production_order_id'=>['nullable','integer','exists:production_orders,id'],
   'started_at'=>['required','date','before_or_equal:now'],
   'ended_at'=>['required','date','before_or_equal:now','after:started_at'],
   'cause'=>['required',Rule::in(['mechanical','electrical','utilities','materials','quality','setup','other'])],
   'description'=>['required','string','min:5','max:3000'],
  ]);
  $data['started_at']=\Carbon\CarbonImmutable::parse($data['started_at'])->format('Y-m-d H:i:s');
  $data['ended_at']=\Carbon\CarbonImmutable::parse($data['ended_at'])->format('Y-m-d H:i:s');
  $start=strtotime($data['started_at']);$end=strtotime($data['ended_at']);
  $minutes=(int)floor(($end-$start)/60);
  if($minutes<1 || $minutes>129600)
   throw ValidationException::withMessages(['ended_at'=>'Downtime must last between 1 minute and 90 days.']);
  DB::transaction(function()use($request,$data,$minutes){
   $exists=DB::table('manufacturing_downtime_entries')->where('submission_token',$data['submission_token'])->first();
   if($exists){
    if((int)$exists->manufacturing_equipment_id===(int)$data['manufacturing_equipment_id'] &&
      $exists->description===$data['description'])return;
    throw ValidationException::withMessages(['submission_token'=>'Token was previously used for another downtime entry.']);
   }
   // Downtime is an actual historical event. Do not suppress a real incident even
   // when its timeframe overlaps a pre-existing plan; flag impacted plans instead.
   $affected=DB::table('manufacturing_schedules')
    ->where('manufacturing_equipment_id',$data['manufacturing_equipment_id'])
    ->whereIn('status',['planned','running'])
    ->where('starts_at','<',$data['ended_at'])
    ->where('ends_at','>',$data['started_at'])->count();
   $id=DB::table('manufacturing_downtime_entries')->insertGetId([
    'submission_token'=>$data['submission_token'],
    'manufacturing_equipment_id'=>$data['manufacturing_equipment_id'],
    'production_order_id'=>$data['production_order_id']??null,
    'started_at'=>$data['started_at'],'ended_at'=>$data['ended_at'],
    'duration_minutes'=>$minutes,'cause'=>$data['cause'],'description'=>$data['description'],
    'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
   ]);
   ActivityLog::record('manufacturing.downtime.recorded','manufacturing_downtime',$id,
    'Actual equipment downtime recorded; active schedule overlaps: '.$affected.'.',
    ['affected_schedules'=>$affected,'equipment_id'=>$data['manufacturing_equipment_id']]);
  });
  return redirect()->route('workspace.page',['manufacturing','downtime'])->with('success','Equipment downtime recorded.');
 }
}

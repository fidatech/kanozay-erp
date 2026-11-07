<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CompanyWarehouse;
use App\Models\FixedAsset;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FixedAssetLifecycleController extends Controller
{
    private const PAGES=['maintenance','schedules','service-history','transfers','disposals'];

    private function guard(Request $request,bool $write=false,bool $finance=false):void
    {
        $options=$write?['maintenance.update','administration.update']:
            ['maintenance.view','finance.view','administration.view'];
        if($finance)$options=['finance.update','administration.update'];
        abort_unless($request->user()->canAny($options),403);
    }

    public function index(Request $request,string $page)
    {
        abort_unless(in_array($page,self::PAGES,true),404);
        $this->guard($request);
        $assets=FixedAsset::with('currency')->orderBy('asset_tag')->get();
        $warehouses=CompanyWarehouse::where('is_active',true)->orderBy('code')->get();
        $tasks=DB::table('fixed_asset_maintenance_orders as t')
            ->join('fixed_assets as a','a.id','=','t.fixed_asset_id')
            ->select('t.*','a.asset_tag','a.name as asset_name','a.currency_id')
            ->when($page==='schedules',fn($q)=>$q->whereNotNull('t.planned_on')->whereNotIn('t.status',['cancelled','completed']))
            ->when($page==='service-history',fn($q)=>$q->where('t.status','completed'))
            ->orderByDesc('t.id')->paginate(25)->withQueryString();
        $movements=DB::table('fixed_asset_location_movements as m')
            ->join('fixed_assets as a','a.id','=','m.fixed_asset_id')
            ->leftJoin('company_warehouses as s','s.id','=','m.source_warehouse_id')
            ->join('company_warehouses as d','d.id','=','m.destination_warehouse_id')
            ->select('m.*','a.asset_tag','s.name as source','d.name as destination')
            ->orderByDesc('m.id')->limit(100)->get();
        $disposals=DB::table('fixed_asset_disposals as d')
            ->join('fixed_assets as a','a.id','=','d.fixed_asset_id')
            ->join('currencies as c','c.id','=','a.currency_id')
            ->select('d.*','a.asset_tag','a.name as asset_name','c.code as currency_code')
            ->orderByDesc('d.id')->limit(100)->get();

        return view('assets.lifecycle',[
            'page'=>$page,'pageTitle'=>config('erp_pages.assets.pages.'.$page),
            'assets'=>$assets,'warehouses'=>$warehouses,'tasks'=>$tasks,'movements'=>$movements,
            'disposals'=>$disposals,
        ]);
    }

    public function requestService(Request $request)
    {
        $this->guard($request,true);
        $data=$request->validate([
            'fixed_asset_id'=>['required','integer',Rule::exists('fixed_assets','id')->where('status','active')],
            'submission_token'=>['required','uuid'],
            'type'=>['required',Rule::in(['preventive','corrective'])],
            'summary'=>['required','string','max:180'],
            'planned_on'=>['nullable','date','after_or_equal:today'],
            'provider'=>['nullable','string','max:160'],
            'notes'=>['nullable','string','max:3000'],
        ]);
        DB::transaction(function()use($request,$data){
            $existing=DB::table('fixed_asset_maintenance_orders')
                ->where('submission_token',$data['submission_token'])->first();
            if($existing){
                if((int)$existing->fixed_asset_id===(int)$data['fixed_asset_id'])return;
                throw ValidationException::withMessages(['submission_token'=>'Duplicate token belongs to another asset.']);
            }
            $asset=FixedAsset::whereKey($data['fixed_asset_id'])->lockForUpdate()->firstOrFail();
            if($asset->status!=='active')throw ValidationException::withMessages(['fixed_asset_id'=>'Only active assets can be serviced.']);
            $id=DB::table('fixed_asset_maintenance_orders')->insertGetId([
                'fixed_asset_id'=>$asset->id,'ticket'=>'TMP-'.\Illuminate\Support\Str::uuid(),
                'submission_token'=>$data['submission_token'],'type'=>$data['type'],
                'status'=>empty($data['planned_on'])?'requested':'scheduled',
                'summary'=>$data['summary'],'planned_on'=>$data['planned_on']??null,
                'provider'=>$data['provider']??null,'notes'=>$data['notes']??null,
                'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('fixed_asset_maintenance_orders')->where('id',$id)
                ->update(['ticket'=>'SRV-'.str_pad((string)$id,7,'0',STR_PAD_LEFT)]);
            ActivityLog::record('asset.maintenance.requested','fixed_asset',$asset->id,
                'Asset service request registered.', ['maintenance_order_id'=>$id]);
        });
        return back()->with('success','Service request saved.');
    }

    public function updateService(Request $request,int $ticket)
    {
        $this->guard($request,true);
        $data=$request->validate([
            'status'=>['required',Rule::in(['scheduled','in_progress','completed','cancelled'])],
            'planned_on'=>['nullable','date'],
            'serviced_on'=>['nullable','required_if:status,completed','date','before_or_equal:today'],
            'actual_cost'=>['nullable','required_if:status,completed','numeric','min:0','max:99999999999999.9999','decimal:0,4'],
            'completion_notes'=>['nullable','required_if:status,completed','string','max:3000'],
        ]);
        DB::transaction(function()use($ticket,$data,$request){
            $task=DB::table('fixed_asset_maintenance_orders')->where('id',$ticket)->lockForUpdate()->first();
            abort_unless($task,404);
            if(in_array($task->status,['completed','cancelled'],true))
                throw ValidationException::withMessages(['status'=>'Completed or cancelled service records are immutable.']);
            $allowed=[
                'requested'=>['scheduled','in_progress','cancelled'],
                'scheduled'=>['in_progress','cancelled'],
                'in_progress'=>['completed','cancelled'],
            ];
            if(!in_array($data['status'],$allowed[$task->status]??[],true))
                throw ValidationException::withMessages(['status'=>'Invalid service transition. Follow request, scheduling, service, and completion.']);
            $changes=['status'=>$data['status'],'updated_at'=>now()];
            if($data['status']==='scheduled'){
                if(empty($data['planned_on']) && !$task->planned_on)
                    throw ValidationException::withMessages(['planned_on'=>'Choose a planned service date.']);
                $changes['planned_on']=$data['planned_on']??$task->planned_on;
            }
            if($data['status']==='completed'){
                $asset=FixedAsset::whereKey($task->fixed_asset_id)->lockForUpdate()->firstOrFail();
                if($asset->status!=='active')
                    throw ValidationException::withMessages(['status'=>'Disposed assets cannot have new completed service.']);
                $changes+=[
                    'serviced_on'=>$data['serviced_on'],
                    'actual_cost'=>(string)BigDecimal::of((string)$data['actual_cost'])->toScale(4),
                    'completion_notes'=>$data['completion_notes'],
                    'completed_by'=>$request->user()->id,
                ];
            }
            DB::table('fixed_asset_maintenance_orders')->where('id',$ticket)->update($changes);
            ActivityLog::record('asset.maintenance.status','fixed_asset',$task->fixed_asset_id,
                'Service '.$task->ticket.' moved to '.$data['status'],['ticket_id'=>$ticket]);
        });
        return back()->with('success','Service status updated.');
    }

    public function relocate(Request $request,FixedAsset $asset)
    {
        $this->guard($request,true);
        $data=$request->validate([
            'destination_warehouse_id'=>['required','integer',Rule::exists('company_warehouses','id')->where('is_active',true)],
            'moved_on'=>['required','date','before_or_equal:today'],
            'reason'=>['required','string','min:5','max:500'],
            'submission_token'=>['required','uuid'],
        ]);
        DB::transaction(function()use($request,$asset,$data){
            $locked=FixedAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $existing=DB::table('fixed_asset_location_movements')->where('submission_token',$data['submission_token'])->first();
            if($existing){
                if((int)$existing->fixed_asset_id===$locked->id && (int)$existing->destination_warehouse_id===(int)$data['destination_warehouse_id'])return;
                throw ValidationException::withMessages(['submission_token'=>'Duplicate transfer token used for another movement.']);
            }
            if($locked->status!=='active')
                throw ValidationException::withMessages(['asset'=>'Only active assets can move between warehouses.']);
            if($locked->custodian_id || $locked->assignments()->whereNull('returned_on')->exists())
                throw ValidationException::withMessages(['asset'=>'Return the assigned asset to custody control before transferring locations.']);
            if((int)($locked->company_warehouse_id??0)===(int)$data['destination_warehouse_id'])
                throw ValidationException::withMessages(['destination_warehouse_id'=>'Asset already belongs to this warehouse.']);
            if($data['moved_on']<$locked->acquired_on->toDateString())
                throw ValidationException::withMessages(['moved_on'=>'Movement cannot precede asset acquisition.']);
            DB::table('fixed_asset_location_movements')->insert([
                'fixed_asset_id'=>$locked->id,
                'source_warehouse_id'=>$locked->company_warehouse_id,
                'destination_warehouse_id'=>$data['destination_warehouse_id'],
                'moved_on'=>$data['moved_on'],'reason'=>$data['reason'],
                'submission_token'=>$data['submission_token'],'created_by'=>$request->user()->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $locked->update(['company_warehouse_id'=>$data['destination_warehouse_id']]);
            ActivityLog::record('asset.location.transferred','fixed_asset',$locked->id,'Asset location changed.');
        });
        return back()->with('success','Asset location updated and transfer logged.');
    }

    public function dispose(Request $request,FixedAsset $asset)
    {
        $this->guard($request,true,true);
        $data=$request->validate([
            'disposed_on'=>['required','date','before_or_equal:today'],
            'method'=>['required',Rule::in(['sale','scrap','donation','write_off'])],
            'proceeds'=>['required','numeric','min:0','decimal:0,4','max:99999999999999.9999'],
            'reason'=>['required','string','min:10','max:3000'],
            'confirm_accounting_pending'=>['accepted'],
        ]);
        DB::transaction(function()use($request,$asset,$data){
            $locked=FixedAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if($locked->status!=='active')
                throw ValidationException::withMessages(['asset'=>'Asset has already been deactivated or disposed.']);
            if($locked->custodian_id || $locked->assignments()->whereNull('returned_on')->exists())
                throw ValidationException::withMessages(['asset'=>'Return the asset from employee custody before recording disposal.']);
            if($data['disposed_on']<$locked->acquired_on->toDateString())
                throw ValidationException::withMessages(['disposed_on'=>'Disposal cannot precede acquisition.']);
            $depreciation=DB::table('fixed_asset_depreciation_entries')
                ->where('fixed_asset_id',$locked->id)->whereDate('period_month','<=',$data['disposed_on'])
                ->lockForUpdate()->get();
            $accumulated=BigDecimal::zero();
            foreach($depreciation as $item)$accumulated=$accumulated->plus((string)$item->amount);
            $cost=BigDecimal::of((string)$locked->acquisition_cost);
            if($accumulated->isGreaterThan($cost))
                throw ValidationException::withMessages(['asset'=>'Posted depreciation exceeds original acquisition cost.']);
            $book=$cost->minus($accumulated)->toScale(4);
            DB::table('fixed_asset_disposals')->insert([
                'fixed_asset_id'=>$locked->id,'disposed_on'=>$data['disposed_on'],
                'method'=>$data['method'],'proceeds'=>(string)BigDecimal::of((string)$data['proceeds'])->toScale(4),
                'accumulated_depreciation'=>(string)$accumulated->toScale(4),
                'book_value'=>(string)$book,
                'financial_status'=>'pending_reconciliation','reason'=>$data['reason'],
                'recorded_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $locked->update(['status'=>'disposed']);
            ActivityLog::record('asset.disposal.recorded','fixed_asset',$locked->id,
                'Physical disposal recorded; acquisition and gain/loss accounting require reconciliation.');
        });
        return back()->with('success','Physical disposal recorded. Financial journal reconciliation remains required.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\InventoryCostLayer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QualityNonconformanceController extends Controller
{
    private function guard(Request $request, bool $review = false):void
    {
        abort_unless($review
            ?$request->user()->canAny(['laboratory.update','administration.update'])
            :$request->user()->canAny(['laboratory.create','laboratory.update','administration.update']),403);
    }

    public function store(Request $request)
    {
        $this->guard($request);
        $data=$request->validate([
            'submission_token'=>['required','uuid'],
            'quality_sample_id'=>['nullable','integer','exists:quality_samples,id'],
            'inventory_cost_layer_id'=>['nullable','integer','exists:inventory_cost_layers,id'],
            'severity'=>['required',Rule::in(['minor','major','critical'])],
            'opened_on'=>['required','date','before_or_equal:today'],
            'due_on'=>['nullable','date','after_or_equal:opened_on'],
            'description'=>['required','string','min:15','max:5000'],
        ]);
        if(empty($data['quality_sample_id']) && empty($data['inventory_cost_layer_id'])){
            throw ValidationException::withMessages([
                'quality_sample_id'=>'Select a laboratory sample or source FIFO lot for the nonconformance.',
            ]);
        }
        DB::transaction(function()use($data,$request){
            $sample=empty($data['quality_sample_id'])?null:
                DB::table('quality_samples')->where('id',$data['quality_sample_id'])->first();
            $lot=empty($data['inventory_cost_layer_id'])?null:
                InventoryCostLayer::whereKey($data['inventory_cost_layer_id'])->firstOrFail();
            if($lot && $lot->source_type!=='goods_receipt_line'){
                throw ValidationException::withMessages([
                    'inventory_cost_layer_id'=>'Choose an incoming GRN source lot. Finished goods should be linked through their QC sample.',
                ]);
            }
            if($lot && $sample && ($sample->source_type!=='incoming_material' ||
                (int)$sample->goods_receipt_line_id!==(int)$lot->source_id)){
                throw ValidationException::withMessages([
                    'quality_sample_id'=>'The sample and source FIFO lot must represent the same incoming receipt line.',
                ]);
            }
            if(DB::table('quality_nonconformances')->where('submission_token',$data['submission_token'])->exists()){
                throw ValidationException::withMessages(['submission_token'=>'This nonconformance submission is already recorded.']);
            }
            $id=DB::table('quality_nonconformances')->insertGetId([
                'number'=>'NCR-PENDING-'.Str::uuid(),
                'submission_token'=>$data['submission_token'],
                'quality_sample_id'=>$sample?->id,
                'inventory_cost_layer_id'=>$lot?->id,
                'severity'=>$data['severity'],'status'=>'open',
                'opened_on'=>$data['opened_on'],'due_on'=>$data['due_on']??null,
                'description'=>trim($data['description']),'created_by'=>$request->user()->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('quality_nonconformances')->where('id',$id)->update([
                'number'=>'NCR-'.str_pad((string)$id,7,'0',STR_PAD_LEFT),
            ]);
            DB::table('quality_nonconformance_events')->insert([
                'quality_nonconformance_id'=>$id,'action'=>'opened',
                'notes'=>$data['description'],'actor_id'=>$request->user()->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('quality.nonconformance.opened','quality_nonconformance',$id,
                'Source-linked quality nonconformance opened.',
                ['severity'=>$data['severity'],'sample_id'=>$sample?->id,'lot_id'=>$lot?->id]);
        });
        return redirect()->route('workspace.page',['laboratory','nonconformances'])
            ->with('success','Nonconformance opened. Apply a separate physical lot hold if stock must be quarantined.');
    }

    public function investigate(Request $request,int $nonconformance)
    {
        $this->guard($request);
        $data=$request->validate([
            'root_cause'=>['required','string','min:15','max:5000'],
            'corrective_action'=>['required','string','min:15','max:5000'],
        ]);
        DB::transaction(function()use($request,$data,$nonconformance){
            $record=DB::table('quality_nonconformances')
                ->where('id',$nonconformance)->lockForUpdate()->first();
            abort_unless($record,404);
            if(!in_array($record->status,['open','investigating'],true)){
                throw ValidationException::withMessages([
                    'nonconformance'=>'Closed nonconformances are immutable. Open a new record for later findings.',
                ]);
            }
            DB::table('quality_nonconformances')->where('id',$nonconformance)->update([
                'status'=>'investigating','root_cause'=>trim($data['root_cause']),
                'corrective_action'=>trim($data['corrective_action']),
                'investigated_by'=>$request->user()->id,'updated_at'=>now(),
            ]);
            DB::table('quality_nonconformance_events')->insert([
                'quality_nonconformance_id'=>$nonconformance,'action'=>'investigated',
                'notes'=>'Root cause: '.$data['root_cause'].'; corrective action: '.$data['corrective_action'],
                'actor_id'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('quality.nonconformance.investigated','quality_nonconformance',
                $nonconformance,'Root cause and corrective action documented.');
        });
        return back()->with('success','Corrective action and investigation history saved.');
    }

    public function close(Request $request,int $nonconformance)
    {
        $this->guard($request,true);
        $data=$request->validate([
            'verification_evidence'=>['required','string','min:15','max:5000'],
        ]);
        DB::transaction(function()use($request,$data,$nonconformance){
            $record=DB::table('quality_nonconformances')->where('id',$nonconformance)
                ->lockForUpdate()->first();
            abort_unless($record,404);
            if($record->status!=='investigating' || !$record->root_cause ||
                !$record->corrective_action){
                throw ValidationException::withMessages([
                    'nonconformance'=>'Document the root cause and corrective action before an independent closure.',
                ]);
            }
            if((int)$record->created_by===$request->user()->id ||
                (int)$record->investigated_by===$request->user()->id){
                throw ValidationException::withMessages([
                    'nonconformance'=>'A separate authorized person must verify and close the investigation.',
                ]);
            }
            DB::table('quality_nonconformances')->where('id',$nonconformance)->update([
                'status'=>'closed','verification_evidence'=>trim($data['verification_evidence']),
                'closed_by'=>$request->user()->id,'closed_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('quality_nonconformance_events')->insert([
                'quality_nonconformance_id'=>$nonconformance,'action'=>'closed',
                'notes'=>$data['verification_evidence'],
                'actor_id'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('quality.nonconformance.closed','quality_nonconformance',
                $nonconformance,'Independent QA closure documented. Source QC holds remain unchanged.');
        });
        return back()->with('success','Nonconformance independently closed. Source sample disposition and material holds remain unchanged.');
    }
}

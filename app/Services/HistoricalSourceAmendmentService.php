<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Append-only, separately reviewed historical source amendment and reversal
 * requests. A reversal APPROVAL NEVER posts an accounting reversal. This
 * workflow deliberately cannot mutate already posted source journals,
 * warehouse movements, sales invoices or old approved source evidence.
 */
final class HistoricalSourceAmendmentService
{
    private function fail(string $message):never
    {
        throw ValidationException::withMessages(['historical_amendment'=>$message]);
    }

    private function evidenceEvent(int $caseId,string $action,array $source,
        string $notes,int $actorId,?string $reference=null):void
    {
        DB::table('finance_historical_correction_events')->insert([
            'finance_historical_correction_case_id'=>$caseId,
            'action'=>$action,
            'source_reference'=>$source['reference'],
            'source_fingerprint'=>$source['fingerprint'],
            'approval_reference'=>$reference,
            'notes'=>$notes,
            'actor_id'=>$actorId,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        ActivityLog::record('finance.historical_correction.'.$action,
            'finance_historical_correction_case',$caseId,
            'Source-linked append-only historical amendment audit trail.');
    }

    /** A rejected/withdrawn case is never rewritten; a fresh case is linked. */
    public function resubmit(int $previousId,array $data,int $actorId):object
    {
        return DB::transaction(function()use($previousId,$data,$actorId){
            $previous=DB::table('finance_historical_correction_cases')
                ->where('id',$previousId)->lockForUpdate()->first();
            abort_unless($previous,404);
            if(!in_array($previous->status,['rejected','withdrawn'],true) ||
                $previous->open_key!==null || $previous->executed_at!==null)
                $this->fail('Only genuinely rejected or withdrawn, unexecuted historical source cases may be refiled. Approved or resolved source journals need a separately authorized full-source reversal.');
            if((int)$previous->reviewed_by===$actorId)
                $this->fail('The prior independent reviewer cannot author a replacement of their own rejected evidence decision.');
            if(DB::table('finance_historical_source_amendments')
                ->where('original_case_id',$previousId)->exists())
                $this->fail('This original historical request has already been replaced once. Continue the existing documented amendment chain.');
            foreach([
                'finance_historical_batch_capitalizations',
                'finance_historical_customer_return_cost_recoveries',
                'finance_historical_customer_return_disposition_reviews',
                'finance_historical_dispatch_dispositions',
                'finance_supplier_return_credit_disputes',
            ] as $table){
                if(DB::table($table)
                    ->where('finance_historical_correction_case_id',$previousId)
                    ->exists())
                    $this->fail('This prior case has signed operational or cost source evidence. It cannot be replaced without first performing the appropriate independent source-level amendment.');
            }
            $source=app(HistoricalFinanceCorrectionService::class)
                ->source($previous->case_type,(int)$previous->source_id);
            if($source['type']!==$previous->case_type ||
                (int)$source['id']!==(int)$previous->source_id)
                $this->fail('The original historical document identity no longer matches the previously rejected correction.');
            $currentEvidence=trim((string)$data['source_evidence']);
            $currentReason=trim((string)$data['identified_discrepancy']);
            $reason=trim((string)$data['amendment_reason']);
            if(strlen($reason)<60 ||
                ($currentEvidence===(string)$previous->source_evidence &&
                 $currentReason===(string)$previous->identified_discrepancy &&
                 $source['fingerprint']===$previous->source_fingerprint))
                $this->fail('A fresh source-linked amendment needs a substantive documented reason and genuinely revised source evidence, discrepancy or changed original source fingerprint. Repeating an unchanged rejected case is prohibited.');

            // Calls the normal source validator and creates a brand-new case.
            // Its approval/review is independent and still required.
            $replacement=app(HistoricalFinanceCorrectionService::class)
                ->submit([
                    'case_type'=>$previous->case_type,
                    'source_id'=>$previous->source_id,
                    'identified_discrepancy'=>$currentReason,
                    'source_evidence'=>$currentEvidence,
                ],$actorId);
            DB::table('finance_historical_source_amendments')->insert([
                'original_case_id'=>$previousId,
                'replacement_case_id'=>$replacement->id,
                'original_fingerprint'=>$previous->source_fingerprint,
                'replacement_fingerprint'=>$replacement->source_fingerprint,
                'amendment_reason'=>$reason,
                'submitted_by'=>$actorId,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->evidenceEvent($previousId,'amendment_replaced',[
                'reference'=>$previous->source_reference,
                'fingerprint'=>$previous->source_fingerprint,
            ],'New source case '.$replacement->case_number.'. '.$reason,
                $actorId);
            $this->evidenceEvent((int)$replacement->id,'amendment_refiled',
                $source,'Replaces case '.$previous->case_number.'. '.$reason,
                $actorId);
            return $replacement;
        });
    }

    /**
     * A signed request to analyze effects of FULL-source reversing entries.
     * No GL posting/reversal execution exists in this deliberately
     * documentary service: partial reversal of source journal is unsafe.
     */
    public function requestReversal(int $caseId,array $data,int $actorId):object
    {
        return DB::transaction(function()use($caseId,$data,$actorId){
            $case=DB::table('finance_historical_correction_cases')
                ->where('id',$caseId)->lockForUpdate()->first();
            abort_unless($case,404);
            if($case->status!=='resolved' || !$case->executed_by ||
                !$case->executed_at)
                $this->fail('Only an actually resolved historical source correction, with recorded original executor and date, can enter the signed source-reversal assessment. Unexecuted cases should be withdrawn or rejected.');
            if((int)$case->executed_by===$actorId ||
                (int)$case->requested_by===$actorId ||
                (int)$case->reviewed_by===$actorId)
                $this->fail('An original correction source actor or executor cannot independently request reversal of their own settled financial evidence.');
            if(DB::table('finance_historical_source_reversal_requests')
                ->where('finance_historical_correction_case_id',$caseId)->exists())
                $this->fail('This historical source already has a unique signed full-reversal request.');
            $original=app(HistoricalFinanceCorrectionService::class)
                ->source($case->case_type,(int)$case->source_id);
            // Executed historical original-cost corrections may themselves
            // change the mutable valuation/status attributes of their source
            // document. Preserve BOTH fingerprints and freeze the current
            // post-execution snapshot; do not assume it equals the pre-post
            // approval fingerprint. This is only a documentary assessment.
            $id=DB::table('finance_historical_source_reversal_requests')
                ->insertGetId([
                    'finance_historical_correction_case_id'=>$caseId,
                    'status'=>'submitted',
                    'source_fingerprint'=>$case->source_fingerprint,
                    'current_source_fingerprint'=>$original['fingerprint'],
                    'original_source_reference'=>$case->source_reference,
                    'original_accounting_effects'=>trim($data['original_accounting_effects']),
                    'requested_replacement_treatment'=>
                        trim($data['requested_replacement_treatment']),
                    'evidence_reference'=>trim($data['evidence_reference']),
                    'requested_by'=>$actorId,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            $this->evidenceEvent($caseId,'reversal_requested',
                $original,'Documentary reversal request #'.$id.'. Original GL effects: '.
                trim($data['original_accounting_effects']).'. Intended full-source treatment: '.
                trim($data['requested_replacement_treatment']),$actorId);
            return DB::table('finance_historical_source_reversal_requests')
                ->where('id',$id)->first();
        });
    }

    public function reviewReversal(int $requestId,array $data,int $actorId):object
    {
        return DB::transaction(function()use($requestId,$data,$actorId){
            $request=DB::table('finance_historical_source_reversal_requests')
                ->where('id',$requestId)->lockForUpdate()->first();
            abort_unless($request,404);
            if($request->status!=='submitted')
                $this->fail('Only an unreviewed, source-signed reversal analysis can receive an independent decision.');
            $case=DB::table('finance_historical_correction_cases')
                ->where('id',$request->finance_historical_correction_case_id)
                ->lockForUpdate()->first();
            if(!$case || $case->status!=='resolved' ||
                in_array($actorId,[
                    (int)$request->requested_by,(int)$case->requested_by,
                    (int)$case->reviewed_by,(int)$case->executed_by,
                ],true))
                $this->fail('A different authorized finance reviewer must independently assess the full original source accounting impact and cannot be an earlier source or settlement actor.');
            $source=app(HistoricalFinanceCorrectionService::class)
                ->source($case->case_type,(int)$case->source_id);
            if(!$request->current_source_fingerprint ||
                !hash_equals((string)$source['fingerprint'],
                    (string)$request->current_source_fingerprint) ||
                !hash_equals((string)$case->source_fingerprint,
                    (string)$request->source_fingerprint))
                $this->fail('Source documents or monetary context changed since the signed reversal assessment. Do not approve a stale source; reassess the entire original and current account-chain evidence.');
            DB::table('finance_historical_source_reversal_requests')
                ->where('id',$requestId)->update([
                    'status'=>$data['decision']==='approve'?'approved':'rejected',
                    'review_reference'=>trim($data['review_reference']),
                    'review_notes'=>trim($data['review_notes']),
                    'reviewed_by'=>$actorId,'reviewed_at'=>now(),
                    'updated_at'=>now(),
                ]);
            $this->evidenceEvent((int)$case->id,
                $data['decision']==='approve'?
                    'reversal_assessed':'reversal_rejected',
                $source,trim($data['review_notes']),$actorId,
                trim($data['review_reference']));
            // Deliberately no JournalPostingService::reverse() call: a
            // full-source reversal requires a separate, source-specific
            // executor for inventory, WIP, finished stock, taxes and COGS.
            return DB::table('finance_historical_source_reversal_requests')
                ->where('id',$requestId)->first();
        });
    }
}

<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FixedAssetCapitalizationService
{
    public function link(FixedAsset $asset,array $data,int $actorId):object
    {
        return DB::transaction(function()use($asset,$data,$actorId){
            $locked=FixedAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $existing=DB::table('fixed_asset_capitalizations')
                ->where('fixed_asset_id',$locked->id)->lockForUpdate()->first();
            if($existing){
                if((int)$existing->journal_line_id===(int)$data['journal_line_id'] &&
                   $existing->reconciliation_reference===trim($data['reconciliation_reference'])){
                    return $existing;
                }
                throw ValidationException::withMessages([
                    'fixed_asset_id'=>'The original acquisition posting has already been reconciled to this asset.',
                ]);
            }

            $source=DB::table('journal_lines')
                ->where('id',$data['journal_line_id'])->lockForUpdate()->first();
            abort_unless($source,404);
            $journal=JournalEntry::whereKey($source->journal_entry_id)
                ->lockForUpdate()->firstOrFail();
            if($journal->status!=='posted' || $journal->reversal_of_id ||
                JournalEntry::where('reversal_of_id',$journal->id)->exists()){
                throw ValidationException::withMessages([
                    'journal_line_id'=>'Select an unreversed posted acquisition journal debit.',
                ]);
            }
            if((int)$journal->currency_id!==(int)$locked->currency_id ||
                (string)$journal->reference!==(string)$locked->source_reference){
                throw ValidationException::withMessages([
                    'journal_line_id'=>'Acquisition journal currency and source reference must match this registered asset.',
                ]);
            }
            $ledger=LedgerAccount::whereKey($source->ledger_account_id)
                ->where('is_active',true)->where('is_group',false)->first();
            if(!$ledger || $ledger->type!=='asset' ||
                DB::table('entity_ledger_accounts')
                    ->where('ledger_account_id',$ledger->id)->exists()){
                throw ValidationException::withMessages([
                    'journal_line_id'=>'The acquisition debit must belong to an active, unallocated PPE asset ledger.',
                ]);
            }
            if(!BigDecimal::of((string)$source->debit)
                ->isEqualTo((string)$locked->acquisition_cost) ||
                !BigDecimal::of((string)$source->credit)->isZero()){
                throw ValidationException::withMessages([
                    'journal_line_id'=>'Exactly one acquisition debit must equal the asset original cost; partial or combined journal lines need separate reconciliation.',
                ]);
            }
            $first=DB::table('fixed_asset_depreciation_entries as depreciation')
                ->join('journal_entries as journal','journal.id','=','depreciation.journal_entry_id')
                ->where('depreciation.fixed_asset_id',$locked->id)
                ->min('journal.entry_date');
            if($first && $journal->entry_date->toDateString()>$first){
                throw ValidationException::withMessages([
                    'journal_line_id'=>'Acquisition journal must precede existing posted depreciation entries.',
                ]);
            }
            if(DB::table('fixed_asset_capitalizations')
                ->where('journal_line_id',$source->id)->exists()){
                throw ValidationException::withMessages([
                    'journal_line_id'=>'This acquisition debit already belongs to another fixed asset.',
                ]);
            }

            $id=DB::table('fixed_asset_capitalizations')->insertGetId([
                'fixed_asset_id'=>$locked->id,
                'acquisition_journal_id'=>$journal->id,
                'journal_line_id'=>$source->id,
                'asset_ledger_id'=>$ledger->id,
                'reconciliation_reference'=>trim($data['reconciliation_reference']),
                'evidence_notes'=>trim($data['evidence_notes']),
                'reviewed_by'=>$actorId,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            ActivityLog::record('fixed_asset.capitalization.reconciled','fixed_asset',$locked->id,
                'Registered acquisition cost matched one posted journal debit, without posting a duplicate purchase entry.',[
                    'capitalization_id'=>$id,'journal_id'=>$journal->id,
                    'journal_line_id'=>$source->id,'currency_id'=>$locked->currency_id,
                    'amount'=>(string)$locked->acquisition_cost,
                ]);
            return DB::table('fixed_asset_capitalizations')->where('id',$id)->first();
        });
    }

    public function assertReconciled(FixedAsset $asset):void
    {
        $link=DB::table('fixed_asset_capitalizations')
            ->where('fixed_asset_id',$asset->id)->first();
        if(!$link){
            throw ValidationException::withMessages([
                'asset'=>'Reconcile the asset original acquisition debit to its posted GL journal before recording depreciation.',
            ]);
        }
        $journal=JournalEntry::whereKey($link->acquisition_journal_id)->first();
        if(!$journal || $journal->status!=='posted' || $journal->reversal_of_id ||
            JournalEntry::where('reversal_of_id',$journal->id)->exists()){
            throw ValidationException::withMessages([
                'asset'=>'The linked acquisition journal was reversed or is no longer posted. Finance must review capitalization.',
            ]);
        }
    }
}

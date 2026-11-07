<?php
namespace App\Services;

use App\Models\ActivityLog;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciationPolicy;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Accounting\JournalPostingService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FixedAssetDisposalPostingService {
 public function __construct(private JournalPostingService $journals){}

 public function post(int $disposalId,array $data,int $actorId):JournalEntry {
  return DB::transaction(function()use($disposalId,$data,$actorId){
   $disposal=DB::table('fixed_asset_disposals')->where('id',$disposalId)->lockForUpdate()->first();
   abort_unless($disposal,404);
   if($disposal->financial_status!=='pending_reconciliation'||
      DB::table('fixed_asset_disposal_postings')->where('fixed_asset_disposal_id',$disposalId)->exists())
    throw ValidationException::withMessages(['disposal'=>'Asset disposal accounting has already been posted.']);
   $asset=FixedAsset::whereKey($disposal->fixed_asset_id)->lockForUpdate()->firstOrFail();
   if($asset->status!=='disposed')
    throw ValidationException::withMessages(['asset'=>'Financial posting requires a physically disposed asset.']);
   $acquisition=JournalEntry::with('lines')->whereKey($data['acquisition_journal_id'])->lockForUpdate()->firstOrFail();
   if($acquisition->status!=='posted'||$acquisition->reversal_of_id ||
      JournalEntry::where('reversal_of_id',$acquisition->id)->exists()||
      $acquisition->currency_id!==$asset->currency_id||
      $acquisition->reference!==$asset->source_reference||
      $acquisition->entry_date->toDateString()>$disposal->disposed_on)
    throw ValidationException::withMessages(['acquisition_journal_id'=>
       'Select a posted, unreversed acquisition journal with the exact asset source reference and currency.']);
   $assetAccount=LedgerAccount::findOrFail($data['asset_ledger_id']);
   if($assetAccount->type!=='asset'||!$assetAccount->is_active||$assetAccount->is_group||
      DB::table('entity_ledger_accounts')->where('ledger_account_id',$assetAccount->id)->exists())
    throw ValidationException::withMessages(['asset_ledger_id'=>'Select an active, unmapped PPE ledger account.']);
   $cost=BigDecimal::of((string)$asset->acquisition_cost)->toScale(4);
   $matching=$acquisition->lines->filter(fn($line)=>
      $line->ledger_account_id===$assetAccount->id &&
      BigDecimal::of((string)$line->debit)->isEqualTo($cost) &&
      BigDecimal::of((string)$line->credit)->isZero());
   if($matching->count()!==1||
       DB::table('fixed_asset_disposal_postings')->where('acquisition_journal_id',$acquisition->id)
        ->where('asset_ledger_id',$assetAccount->id)->exists())
    throw ValidationException::withMessages(['asset_ledger_id'=>
      'Exactly one acquisition debit must match the asset cost, and must not have been used in another disposal.']);

   $accum=BigDecimal::of((string)$disposal->accumulated_depreciation)->toScale(4);
   $book=BigDecimal::of((string)$disposal->book_value)->toScale(4);
   if(!$accum->plus($book)->isEqualTo($cost))
    throw ValidationException::withMessages(['disposal'=>'Acquisition cost does not reconcile to depreciation and book value.']);
   $depreciation=DB::table('fixed_asset_depreciation_entries')->where('fixed_asset_id',$asset->id)->get();
   $postedTotal=BigDecimal::zero();
   foreach($depreciation as $line){
    if($line->period_month>$disposal->disposed_on)
      throw ValidationException::withMessages(['disposal'=>'Depreciation extends beyond disposal date.']);
    $journalStatus=JournalEntry::whereKey($line->journal_entry_id)->value('status');
    if($journalStatus!=='posted')
      throw ValidationException::withMessages(['disposal'=>'A depreciation journal is not posted.']);
    $postedTotal=$postedTotal->plus((string)$line->amount);
   }
   if(!$postedTotal->isEqualTo($accum))
    throw ValidationException::withMessages(['disposal'=>'Reconcile historical depreciation before posting disposal.']);
   $policy=$accum->isPositive()?FixedAssetDepreciationPolicy::where('fixed_asset_id',$asset->id)->first():null;
   if($accum->isPositive() && !$policy)
    throw ValidationException::withMessages(['disposal'=>'An approved depreciation policy is required for accumulated depreciation.']);
   $proceeds=BigDecimal::of((string)$disposal->proceeds)->toScale(4);
   $difference=$proceeds->minus($book)->toScale(4);
   $loss=$difference->isNegative()?$difference->negated():BigDecimal::zero();
   $gain=$difference->isPositive()?$difference:BigDecimal::zero();
   $chosenIds=array_filter([
    $data['receivable_ledger_id']??null,$data['loss_ledger_id']??null,$data['gain_ledger_id']??null,
    $assetAccount->id,$policy?->accumulated_ledger_id,
   ]);
   if(count($chosenIds)!==count(array_unique($chosenIds)))
    throw ValidationException::withMessages(['accounts'=>'Use distinct PPE, accumulated depreciation, proceeds, gain and loss accounts.']);
   $resolve=function(?int $id,string $type,string $field)use($chosenIds):?LedgerAccount {
    if(!$id)return null;
    $account=LedgerAccount::whereKey($id)->where('is_active',true)->where('is_group',false)->first();
    if(!$account||$account->type!==$type||
       DB::table('entity_ledger_accounts')->where('ledger_account_id',$id)->exists())
      throw ValidationException::withMessages([$field=>'Choose an active, unmapped '.$type.' account.']);
    return $account;
   };
   $proceedsLedger=$resolve($data['receivable_ledger_id']??null,'asset','receivable_ledger_id');
   $lossLedger=$resolve($data['loss_ledger_id']??null,'expense','loss_ledger_id');
   $gainLedger=$resolve($data['gain_ledger_id']??null,'income','gain_ledger_id');
   if($proceeds->isPositive()&&!$proceedsLedger)
    throw ValidationException::withMessages(['receivable_ledger_id'=>'Select a proceeds receivable ledger for an asset sale.']);
   if($loss->isPositive()&&!$lossLedger)
    throw ValidationException::withMessages(['loss_ledger_id'=>'A loss account is required for this book-value loss.']);
   if($gain->isPositive()&&!$gainLedger)
    throw ValidationException::withMessages(['gain_ledger_id'=>'A gain account is required for this disposal gain.']);
   $number='FAD-'.str_pad((string)$disposal->id,7,'0',STR_PAD_LEFT);
   $journal=JournalEntry::create([
    'number'=>$number,'entry_date'=>$disposal->disposed_on,'reference'=>$asset->asset_tag,
    'description'=>'Finance-reconciled disposal '.$asset->asset_tag.' / '.$data['reconciliation_reference'],
    'status'=>'draft','currency_id'=>$asset->currency_id,'created_by'=>$actorId,
   ]);
   if($accum->isPositive())$journal->lines()->create([
      'ledger_account_id'=>$policy->accumulated_ledger_id,'debit'=>(string)$accum,'credit'=>'0',
      'memo'=>'Remove accumulated depreciation',
   ]);
   if($proceeds->isPositive())$journal->lines()->create([
      'ledger_account_id'=>$proceedsLedger->id,'debit'=>(string)$proceeds,'credit'=>'0',
      'memo'=>'Proceeds receivable; separate cash collection required',
   ]);
   if($loss->isPositive())$journal->lines()->create([
      'ledger_account_id'=>$lossLedger->id,'debit'=>(string)$loss,'credit'=>'0',
      'memo'=>'Disposal book loss',
   ]);
   $journal->lines()->create([
      'ledger_account_id'=>$assetAccount->id,'debit'=>'0','credit'=>(string)$cost,
      'memo'=>'Derecognize original acquisition cost',
   ]);
   if($gain->isPositive())$journal->lines()->create([
      'ledger_account_id'=>$gainLedger->id,'debit'=>'0','credit'=>(string)$gain,
      'memo'=>'Disposal book gain',
   ]);
   $this->journals->post($journal,$actorId);
   DB::table('fixed_asset_disposal_postings')->insert([
    'fixed_asset_disposal_id'=>$disposal->id,'acquisition_journal_id'=>$acquisition->id,
    'asset_ledger_id'=>$assetAccount->id,'receivable_ledger_id'=>$proceedsLedger?->id,
    'loss_ledger_id'=>$lossLedger?->id,'gain_ledger_id'=>$gainLedger?->id,
    'disposal_journal_id'=>$journal->id,
    'reconciliation_reference'=>$data['reconciliation_reference'],
    'gain_or_loss'=>(string)$difference,'posted_by'=>$actorId,
    'created_at'=>now(),'updated_at'=>now(),
   ]);
   DB::table('fixed_asset_disposals')->where('id',$disposal->id)
    ->update(['financial_status'=>'posted','updated_at'=>now()]);
   ActivityLog::record('asset.disposal.journal_posted','fixed_asset',$asset->id,
      'Finance reconciled acquisition and posted disposal journal '.$number,
      ['gain_or_loss'=>(string)$difference,'currency_id'=>$asset->currency_id]);
   return $journal->fresh();
  });
 }
}

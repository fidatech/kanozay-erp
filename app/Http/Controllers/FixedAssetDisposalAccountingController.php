<?php
namespace App\Http\Controllers;
use App\Services\FixedAssetDisposalPostingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FixedAssetDisposalAccountingController extends Controller {
 public function post(Request $request,int $disposal,FixedAssetDisposalPostingService $service) {
  abort_unless($request->user()->canAny(['finance.update','administration.update']),403);
  $data=$request->validate([
   'acquisition_journal_id'=>['required','integer',Rule::exists('journal_entries','id')->where('status','posted')],
   'asset_ledger_id'=>['required','integer','exists:ledger_accounts,id'],
   'receivable_ledger_id'=>['nullable','integer','exists:ledger_accounts,id'],
   'loss_ledger_id'=>['nullable','integer','exists:ledger_accounts,id'],
   'gain_ledger_id'=>['nullable','integer','exists:ledger_accounts,id'],
   'reconciliation_reference'=>['required','string','min:4','max:180'],
  ]);
  $service->post($disposal,$data,$request->user()->id);
  return redirect()->route('workspace.page',['assets','disposals'])
    ->with('success','Reconciled asset disposal posted to General Ledger.');
 }
}

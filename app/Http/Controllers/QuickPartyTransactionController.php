<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\DocumentSequence;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\MoneyTransaction;
use App\Models\TradeParty;
use App\Models\TradeDocument;
use App\Services\Accounting\TradeSettlementService;
use Brick\Math\BigDecimal;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuickPartyTransactionController extends Controller
{
    public function store(Request $request, JournalPostingService $posting): RedirectResponse
    {
        abort_unless($request->user()->canAny(['administration.update','finance.update']),403);
        $raw=(string)$request->input('amount','');
        $normalized=strtr($raw,['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٫'=>'.','٬'=>',','،'=>',']);
        $request->merge(['amount'=>str_replace(',','',$normalized)]);
        $data=$request->validate([
            'submission_token'=>['required','uuid'],
            'transaction_date'=>['required','date'],
            'trade_party_id'=>['required','integer',Rule::exists('trade_parties','id')->where('is_active',true)->whereIn('type',['customer','supplier'])],
            'type'=>['required',Rule::in(['credit','debit'])],
            'currency_id'=>['required','integer',Rule::exists('currencies','id')->where('is_active',true)],
            'cash_account_id'=>['nullable','integer','exists:financial_accounts,id'],
            'financial_account_id'=>['nullable','integer','exists:financial_accounts,id'],
            'amount'=>['required','numeric','gt:0','decimal:0,4','max:999999999999.9999'],
            'description'=>['nullable','string','max:2000'],
            'trade_document_id'=>['nullable','integer','exists:trade_documents,id'],
            'return_to_party'=>['nullable','boolean'],
        ]);
        DB::transaction(function()use($data,$request,$posting){
            // A replay of the same submitted form must never post another payment.
            if(MoneyTransaction::where('submission_token',$data['submission_token'])->exists()){
                throw ValidationException::withMessages(['submission_token'=>'This transaction was already submitted. Refresh the account before entering a new payment.']);
            }
            $party=TradeParty::whereKey($data['trade_party_id'])->where('is_active',true)->firstOrFail();
            if(!$party->currencies()->whereKey($data['currency_id'])->exists())throw ValidationException::withMessages(['currency_id'=>'This currency is not enabled for the selected party.']);
            $purpose=$party->type==='customer'?'receivable':'payable';
            $partyLedgerId=DB::table('entity_ledger_accounts')->where(['entity_type'=>$party->type,'entity_id'=>$party->id,'currency_id'=>$data['currency_id'],'purpose'=>$purpose])->value('ledger_account_id');
            $partyLedger=$partyLedgerId?LedgerAccount::find($partyLedgerId):null;
            if(!$partyLedger||!$partyLedger->is_active||$partyLedger->is_group)throw ValidationException::withMessages(['trade_party_id'=>'Customer/supplier ledger mapping is missing or inactive.']);
            // Retain cashbox-only behavior for legacy quick forms. The account
            // profile explicitly selects from cashbox, bank and sarafi accounts.
            $usingModernAccount=!empty($data['financial_account_id']);
            $available=FinancialAccount::where('is_active',true)
                ->where('currency_id',$data['currency_id'])
                ->whereIn('type',$usingModernAccount?['cashbox','bank','sarafi']:['cashbox'])
                ->orderBy('id')->get();
            if($available->isEmpty())throw ValidationException::withMessages([
                'currency_id'=>'No active payment account is available for this currency.',
            ]);
            $selectedAccountId=$data['financial_account_id']??$data['cash_account_id']??null;
            if($selectedAccountId){
                $cash=$available->firstWhere('id',(int)$selectedAccountId);
                // Attach the error to the field the form actually submitted so
                // inline validation highlights the right input.
                if(!$cash)throw ValidationException::withMessages([
                    ($usingModernAccount?'financial_account_id':'cash_account_id')=>'Choose an active financial account in the selected currency.',
                ]);
            }else{
                if($available->count()!==1)throw ValidationException::withMessages([
                    'cash_account_id'=>'Select a payment account when more than one is available.',
                ]);
                $cash=$available->first();
            }
            $cashLedgerId=DB::table('entity_ledger_accounts')->where([
                'entity_type'=>$cash->type,'entity_id'=>$cash->id,
                'currency_id'=>$data['currency_id'],'purpose'=>'cash',
            ])->value('ledger_account_id');
            $cashLedger=$cashLedgerId?LedgerAccount::find($cashLedgerId):null;
            if(!$cashLedger||!$cashLedger->is_active||$cashLedger->is_group||$cashLedger->id===$partyLedger->id)
                throw ValidationException::withMessages([
                    'financial_account_id'=>'Payment account ledger mapping is missing or invalid.',
                ]);
            // Optional invoice settlement is checked before journal posting and
            // completed in the same transaction as the money movement.
            $settlementDocument=null;
            if(!empty($data['trade_document_id'])){
                $settlementDocument=TradeDocument::whereKey($data['trade_document_id'])->firstOrFail();
                $expectedDocumentType=$party->type==='customer'?'receivable':'payable';
                $expectedMovement=$party->type==='customer'?'credit':'debit';
                if($data['type']!==$expectedMovement ||
                    $settlementDocument->type!==$expectedDocumentType ||
                    (int)$settlementDocument->trade_party_id!==(int)$party->id ||
                    (int)$settlementDocument->currency_id!==(int)$data['currency_id']){
                    throw ValidationException::withMessages(['trade_document_id'=>'Choose a matching outstanding invoice for this party, currency and payment direction.']);
                }
            }
            $received=$data['type']==='credit';
            $transactionType=$received?'receipt':'payment';
            $description=trim((string)($data['description']??'')) ?: ($received?'Received from ':'Paid to ').$party->name;
            DocumentSequence::firstOrCreate(['document_type'=>$transactionType],['prefix'=>$received?'REC-':'PAY-','next_number'=>1,'padding'=>6,'is_active'=>true]);
            $number=DocumentSequence::issue($transactionType);
            $journal=JournalEntry::create(['number'=>$number,'entry_date'=>$data['transaction_date'],'description'=>$description,'currency_id'=>$data['currency_id'],'created_by'=>$request->user()->id,'status'=>'draft']);
            $journal->lines()->create(['ledger_account_id'=>$received?$cashLedger->id:$partyLedger->id,'debit'=>$data['amount'],'credit'=>'0']);
            $journal->lines()->create(['ledger_account_id'=>$received?$partyLedger->id:$cashLedger->id,'debit'=>'0','credit'=>$data['amount']]);
            $posting->post($journal,$request->user()->id);
            $transaction=MoneyTransaction::create([
                'submission_token'=>$data['submission_token'],'type'=>$transactionType,'transaction_date'=>$data['transaction_date'],
                'source_account_id'=>$received?null:$cash->id,'destination_account_id'=>$received?$cash->id:null,
                'counterpart_ledger_account_id'=>$partyLedger->id,'currency_id'=>$data['currency_id'],
                'amount'=>$data['amount'],'description'=>$description,'journal_entry_id'=>$journal->id,'created_by'=>$request->user()->id,
            ]);
            if($settlementDocument){
                app(TradeSettlementService::class)->allocate(
                    $settlementDocument->id, $transaction->id, (string)$data['amount'], $request->user()->id
                );
            }
            ActivityLog::record('party_transaction.posted','money_transaction',$transaction->id,ucfirst($data['type']).' for '.$party->name.' ('.$number.')',[
                'party_id'=>$party->id,'currency_id'=>$data['currency_id'],'type'=>$data['type'],'journal_id'=>$journal->id,
            ]);
        });
        $message=!empty($data['trade_document_id'])
            ? 'Payment posted and allocated to the selected invoice.'
            : 'Credit/debit transaction posted to the journal.';
        if(!empty($data['return_to_party'])){
            $party=TradeParty::findOrFail($data['trade_party_id']);
            return redirect()->route($party->type==='customer'?'finance.customers.show':'finance.suppliers.show',$party)
                ->with('status',$message);
        }
        return redirect()->route('finance.journals.index')->with('status',$message);
    }
}

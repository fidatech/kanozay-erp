<?php

namespace App\Http\Controllers;

use App\Models\TradeParty;
use App\Services\CustomerAdvanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerAdvanceController extends Controller
{
    private function normalize(Request $request): void
    {
        $raw = $request->input('amount');
        if (! is_string($raw)) return;

        $raw = strtr($raw, [
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٫'=>'.','٬'=>',','،'=>',',
        ]);
        $request->merge(['amount'=>str_replace(',', '', $raw)]);
    }

    public function receive(Request $request, TradeParty $party, CustomerAdvanceService $service)
    {
        abort_unless($request->user()->canAny(['administration.update','finance.update']), 403);
        abort_unless($party->type === 'customer', 404);
        $this->normalize($request);
        $data = $request->validate([
            'submission_token'=>['required','uuid'],
            'currency_id'=>['required','integer',Rule::exists('currencies','id')->where('is_active', true)],
            'destination_account_id'=>['required','integer',Rule::exists('financial_accounts','id')->where('is_active', true)],
            'received_on'=>['required','date','before_or_equal:today'],
            'amount'=>['required','numeric','gt:0','decimal:0,4','max:9999999999999999.9999'],
            'reference'=>['required','string','min:2','max:120'],
        ]);
        $service->receive($party, $data, $request->user()->id);
        return redirect()->route('finance.customers.show', $party)
            ->with('status', 'Customer deposit recorded as a liability, with balanced cash journal.');
    }

    public function apply(Request $request, TradeParty $party, int $advance, CustomerAdvanceService $service)
    {
        abort_unless($request->user()->canAny(['administration.update','finance.update']), 403);
        abort_unless($party->type === 'customer', 404);
        abort_unless(DB::table('customer_advance_receipts')
            ->where('id', $advance)->where('customer_id', $party->id)->exists(), 404);
        $this->normalize($request);
        $data = $request->validate([
            'submission_token'=>['required','uuid'],
            'trade_document_id'=>['required','integer',Rule::exists('trade_documents','id')->where('type','receivable')],
            'applied_on'=>['required','date','before_or_equal:today'],
            'amount'=>['required','numeric','gt:0','decimal:0,4','max:9999999999999999.9999'],
        ]);
        $service->apply($advance, $data, $request->user()->id);
        return redirect()->route('finance.customers.show', $party)
            ->with('status', 'Customer advance applied against invoice and balanced journal posted.');
    }
}

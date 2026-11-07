<?php
namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\FinishedGood;
use App\Models\TradeParty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerCommercialPolicyController extends Controller
{
 public function index(Request $request,string $page)
 {
  abort_unless(in_array($page,['pricing','customer-credit-limits'],true),404);
  abort_unless($request->user()->canAny(['sales.view','finance.view','administration.view']),403);
  return view('sales.commercial-policies',[
   'page'=>$page,
   'customers'=>TradeParty::where('type','customer')->where('is_active',true)->orderBy('name')->get(),
   'currencies'=>Currency::where('is_active',true)->orderBy('code')->get(),
   'products'=>FinishedGood::where('is_active',true)->orderBy('name')->get(),
   'limits'=>DB::table('customer_credit_limits as l')
    ->join('trade_parties as p','p.id','=','l.trade_party_id')
    ->join('currencies as c','c.id','=','l.currency_id')
    ->select('l.*','p.name as customer_name','c.code as currency_code')->orderBy('p.name')->get(),
   'prices'=>DB::table('customer_sales_prices as l')
    ->join('trade_parties as p','p.id','=','l.trade_party_id')
    ->join('currencies as c','c.id','=','l.currency_id')
    ->join('finished_goods as g','g.id','=','l.finished_good_id')
    ->select('l.*','p.name as customer_name','c.code as currency_code','g.sku as product_sku')
    ->orderByDesc('l.effective_on')->limit(400)->get(),
  ]);
 }

 public function credit(Request $request)
 {
  abort_unless($request->user()->canAny(['finance.update','administration.update']),403);
  $data=$request->validate([
   'trade_party_id'=>['required',Rule::exists('trade_parties','id')->where('type','customer')->where('is_active',true)],
   'currency_id'=>['required',Rule::exists('currencies','id')->where('is_active',true)],
   'limit_amount'=>['required','numeric','min:0','decimal:0,4','max:99999999999999999999.9999'],
   'is_active'=>['required','boolean'],
   'approval_reference'=>['required','string','min:3','max:180'],
  ]);
  $party=TradeParty::findOrFail($data['trade_party_id']);
  if(!$party->currencies()->whereKey($data['currency_id'])->exists())
   throw ValidationException::withMessages(['currency_id'=>'Customer is not enabled for this currency.']);
  DB::transaction(function()use($data,$request){
   DB::table('customer_credit_limits')->updateOrInsert(
    ['trade_party_id'=>$data['trade_party_id'],'currency_id'=>$data['currency_id']],
    ['limit_amount'=>$data['limit_amount'],'is_active'=>$data['is_active'],
     'approval_reference'=>$data['approval_reference'],'approved_by'=>$request->user()->id,'updated_at'=>now()]);
   ActivityLog::record('sales.credit.limit.approved','trade_party',$data['trade_party_id'],
    'Customer credit policy saved with approval reference.');
  });
  return redirect()->route('workspace.page',['sales','customer-credit-limits'])
    ->with('success','Customer credit rule saved.');
 }

 public function price(Request $request)
 {
  abort_unless($request->user()->canAny(['sales.update','administration.update']),403);
  $data=$request->validate([
   'trade_party_id'=>['required',Rule::exists('trade_parties','id')->where('type','customer')->where('is_active',true)],
   'finished_good_id'=>['required',Rule::exists('finished_goods','id')->where('is_active',true)],
   'currency_id'=>['required',Rule::exists('currencies','id')->where('is_active',true)],
   'unit_price'=>['required','numeric','gt:0','decimal:0,4','max:99999999999999.9999'],
   'effective_on'=>['required','date'],'expires_on'=>['nullable','date','after_or_equal:effective_on'],
   'is_active'=>['required','boolean'],
  ]);
  $party=TradeParty::findOrFail($data['trade_party_id']);
  if(!$party->currencies()->whereKey($data['currency_id'])->exists())
   throw ValidationException::withMessages(['currency_id'=>'Customer is not enabled for this currency.']);
  DB::transaction(function()use($data,$request){
   DB::table('customer_sales_prices')->updateOrInsert([
     'trade_party_id'=>$data['trade_party_id'],
     'finished_good_id'=>$data['finished_good_id'],'currency_id'=>$data['currency_id'],
     'effective_on'=>$data['effective_on'],
   ],['unit_price'=>$data['unit_price'],'expires_on'=>$data['expires_on']??null,
      'is_active'=>$data['is_active'],'created_by'=>$request->user()->id,'updated_at'=>now()]);
   ActivityLog::record('sales.customer.price','trade_party',$data['trade_party_id'],
    'Dated customer price reference saved.');
  });
  return redirect()->route('workspace.page',['sales','pricing'])->with('success','Price reference saved.');
 }
}

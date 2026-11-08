<?php
namespace App\Http\Controllers;

use App\Services\FinancePeriodClosingService;
use Illuminate\Http\Request;

class FinancePeriodClosingController extends Controller
{
 public function index(Request $request)
 {
  abort_unless($request->user()->canAny(['finance.view','reports.view','administration.view']),403);
  $filters=$request->validate(['month'=>['nullable','date_format:Y-m']]);
  $month=$filters['month']??now()->subMonthNoOverflow()->format('Y-m');
  $readiness=app(\App\Services\FinanceCloseReadinessService::class)->inspect($month);
  return view('finance.period-closures',[
   'readiness'=>$readiness,
   'periods'=>\Illuminate\Support\Facades\DB::table('finance_period_closures as p')
     ->join('users as u','u.id','=','p.closed_by')
     ->select('p.*','u.name as closer_name')->orderByDesc('period_start')->paginate(24),
  ]);
 }
 public function close(Request $request,FinancePeriodClosingService $service)
 {
  abort_unless($request->user()->canAny(['finance.update','administration.update']),403);
  $data=$request->validate([
   'period_start'=>['required','date','date_format:Y-m-d'],
   'authorization_reference'=>['required','string','min:3','max:160'],
   'closing_note'=>['required','string','min:10','max:3000'],
   'confirm_source_reconciliation'=>['accepted'],
  ]);
  $service->close($data,$request->user()->id);
  return back()->with('success','Accounting period closed and balanced snapshot saved.');
 }
 public function reopen(Request $request,int $period,FinancePeriodClosingService $service)
 {
  abort_unless($request->user()->canAny(['finance.update','administration.update']),403);
  $data=$request->validate([
   'reopening_reference'=>['required','string','min:3','max:160'],
   'reopening_note'=>['required','string','min:10','max:3000'],
  ]);
  $service->reopen($period,$data,$request->user()->id);
  return back()->with('success','Accounting period reopened by independent finance approval.');
 }
}
<?php
namespace App\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class ErpIntegrityAuditService
{
 /**
  * Non-mutating cross-module integrity review. Presence of no findings is NOT
  * client acceptance, proof of valuation, tax accuracy or business reconciliation.
  */
 public function check(int $limit=200):array
 {
  $issues=[];$inspected=[];
  $add=function(string $module,string $level,string $subject,string $message)use(&$issues,$limit){
   if(count($issues)>=$limit)return;
   $issues[]=['module'=>$module,'level'=>$level,'subject'=>$subject,'message'=>$message];
  };
  $cmp=fn($v)=>BigDecimal::of((string)($v??0));
  $materials=DB::table('inventory_materials')->select('id','sku','on_hand_quantity','reserved_quantity')->get();
  $assigned=DB::table('inventory_warehouse_balances')->select('inventory_material_id')
   ->selectRaw('SUM(quantity) as allocated')->groupBy('inventory_material_id')
   ->get()->keyBy('inventory_material_id');
  foreach($materials as $m){
   $inspected['raw_materials']=($inspected['raw_materials']??0)+1;
   $on=$cmp($m->on_hand_quantity);$reserved=$cmp($m->reserved_quantity);
   $warehouse=$cmp($assigned->get($m->id)?->allocated??0);
   if($on->isLessThan(0))$add('Inventory','error',$m->sku,'Company raw-material stock is negative.');
   if($reserved->isLessThan(0)||$reserved->isGreaterThan($on))
    $add('Manufacturing','error',$m->sku,'Global material reservations do not fit company on-hand stock.');
   if($warehouse->isLessThan(0)||$warehouse->isGreaterThan($on))
    $add('Warehouses','error',$m->sku,'Warehouse assigned stock exceeds company on-hand balance.');
  }
  foreach(DB::table('inventory_warehouse_balances')->where('quantity','<',0)->get() as $r)
   $add('Warehouses','error','Warehouse material '.$r->inventory_material_id,'Negative physical warehouse allocation.');
  $fgAssigned=DB::table('finished_good_warehouse_stocks as w')
   ->join('production_batches as b','b.id','=','w.production_batch_id')
   ->select('b.finished_good_id')->selectRaw('SUM(w.quantity_packages) as assigned')
   ->groupBy('b.finished_good_id')->get()->keyBy('finished_good_id');
  foreach(DB::table('finished_goods')->select('id','sku','on_hand_packages')->get() as $good){
   $inspected['finished_goods']=($inspected['finished_goods']??0)+1;
   $on=(int)$good->on_hand_packages;$assigned=(int)($fgAssigned->get($good->id)?->assigned??0);
   if($on<0||$assigned>$on)
    $add('Finished Goods','error',$good->sku,'Physical warehouse batch allocation exceeds company finished stock.');
  }
  foreach(DB::table('journal_entries')->where('status','posted')->select('id','number')->get() as $j){
   $inspected['posted_journals']=($inspected['posted_journals']??0)+1;
   $debit=BigDecimal::zero();$credit=BigDecimal::zero();$count=0;
   foreach(DB::table('journal_lines')->where('journal_entry_id',$j->id)->get(['debit','credit']) as $line){
    $debit=$debit->plus((string)$line->debit);$credit=$credit->plus((string)$line->credit);$count++;
   }
   if($count<2||$debit->compareTo($credit)!==0)
    $add('Finance','error',$j->number,'Posted journal is unbalanced or contains fewer than two lines.');
  }
  $docs=DB::table('trade_documents')->select('id','number','type','amount','currency_id','journal_entry_id')->get()->keyBy('id');
  $journals=DB::table('journal_entries')->select('id','status','currency_id')->get()->keyBy('id');
  foreach(DB::table('sales_invoices')->where('status','posted')->get() as $invoice){
   $inspected['sales_invoices']=($inspected['sales_invoices']??0)+1;
   $doc=$docs->get($invoice->trade_document_id);$journal=$journals->get($invoice->journal_entry_id);
   if(!$doc||$doc->type!=='receivable'||$cmp($doc->amount)->compareTo($cmp($invoice->total))!==0 ||
     (int)$doc->currency_id!==(int)$invoice->currency_id||!$journal||$journal->status!=='posted')
    $add('Sales','error',$invoice->number,'Sales invoice GL or customer receivable is absent or does not reconcile.');
  }
  foreach(DB::table('matched_supplier_invoices')->where('status','posted')->get() as $invoice){
   $inspected['supplier_invoices']=($inspected['supplier_invoices']??0)+1;
   $doc=$docs->get($invoice->trade_document_id);$journal=$journals->get($invoice->journal_entry_id);
   if(!$doc||$doc->type!=='payable'||$cmp($doc->amount)->compareTo($cmp($invoice->total))!==0 ||
     (int)$doc->currency_id!==(int)$invoice->currency_id||!$journal||$journal->status!=='posted')
    $add('Purchasing','error',$invoice->number,'Matched supplier invoice GL or payable is inconsistent.');
  }
  foreach(DB::table('payroll_periods')->where('status','approved')->get() as $payroll){
   $inspected['approved_payrolls']=($inspected['approved_payrolls']??0)+1;
   $journal=$journals->get($payroll->approval_journal_id);
   if(!$journal||$journal->status!=='posted')
    $add('Payroll','error','Payroll '.$payroll->id,'Approved payroll lacks its posted accrual journal.');
  }
  return ['issues'=>$issues,'inspected'=>$inspected,'capped'=>count($issues)>=$limit,
   'count'=>count($issues),'checked_at'=>now()->toIso8601String()];
 }
}
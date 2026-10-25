<?php
namespace App\Services\Accounting;
use App\Models\Currency;
use App\Models\LedgerAccount;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
/** Call from each entity creation transaction, and again whenever an entity is enabled for a new currency. */
class EntityAccountProvisioner {
 public const PARENTS=[
  'customer'=>['receivable'=>'1121','advance'=>'2120'],
  'supplier'=>['payable'=>'2111','advance'=>'1131'],
  'bank'=>['cash'=>'1112'],
  'sarafi'=>['cash'=>'1113'],
  'cashbox'=>['cash'=>'1111'],
  'shareholder'=>['capital'=>'3110','loan'=>'2220','drawing'=>'3410'],
  'employee'=>['advance'=>'1132','payable'=>'2131'],
 ];
 public function provision(string $entityType,int $entityId,string $name,int $currencyId):array {
  if($entityId<1||trim($name)===''||!isset(self::PARENTS[$entityType]))throw new InvalidArgumentException('Invalid account entity.');
  return DB::transaction(function()use($entityType,$entityId,$name,$currencyId){
   $currency=Currency::whereKey($currencyId)->where('is_active',true)->firstOrFail();
   $created=[];
   foreach(self::PARENTS[$entityType] as $purpose=>$parentCode){
    $parent=LedgerAccount::where('code',$parentCode)->lockForUpdate()->first();
    if(!$parent||!$parent->is_group||!$parent->is_active)throw new RuntimeException('Seed the chart of accounts first: '.$parentCode);
    $existing=DB::table('entity_ledger_accounts')->where(['entity_type'=>$entityType,'entity_id'=>$entityId,'currency_id'=>$currencyId,'purpose'=>$purpose])->first();
    if($existing){$created[$purpose]=LedgerAccount::findOrFail($existing->ledger_account_id);continue;}
    // A stable deterministic identifier prevents duplicate account codes on repeated calls.
    $code=$parentCode.'.'.strtoupper(substr($entityType,0,2)).$entityId.'.'.$currency->code.'.'.strtoupper(substr($purpose,0,2));
    if(strlen($code)>24)throw new InvalidArgumentException('Entity identifier is too long for account code.');
    $account=LedgerAccount::create(['code'=>$code,'name'=>trim($name).' — '.$currency->code.' ('.ucfirst($purpose).')','type'=>$parent->type,'parent_id'=>$parent->id,'is_group'=>false,'is_active'=>true,'description'=>'Automatically provisioned '.$entityType.' account']);
    DB::table('entity_ledger_accounts')->insert(['entity_type'=>$entityType,'entity_id'=>$entityId,'currency_id'=>$currencyId,'purpose'=>$purpose,'ledger_account_id'=>$account->id,'created_at'=>now(),'updated_at'=>now()]);
    $created[$purpose]=$account;
   }
   return $created;
  });
 }
}

<?php
namespace App\Services;

use App\Models\OperationalRegister;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ApprovedCatalogService {
 public function entries(string $type):Collection {
  $workflow=match($type){
   'units'=>'inventory.units','categories'=>'inventory.categories',
   default=>throw new \InvalidArgumentException('Unsupported catalog type'),
  };
  return OperationalRegister::where('workflow',$workflow)->whereIn('status',['approved','closed'])
   ->orderBy('number')->get();
 }
 public function validateUnit(?int $id,string $symbol):?int {
  if($id===null)return null;
  $unit=$this->entries('units')->firstWhere('id',$id);
  if(!$unit || ($unit->data['symbol']??null)!==$symbol)
   throw ValidationException::withMessages(['catalog_unit_id'=>'Selected approved catalog unit must exactly match the stock unit. Unit conversion is not automatic.']);
  return $unit->id;
 }
 public function validateCategory(?int $id,string $subject):?int {
  if($id===null)return null;
  $category=$this->entries('categories')->firstWhere('id',$id);
  if(!$category)throw ValidationException::withMessages(['catalog_category_id'=>'Choose an approved product category.']);
  $group=$category->data['group']??null;
  $allowed=$subject==='finished'?['finished','other']:['raw','packaging','other'];
  if(!in_array($group,$allowed,true))
   throw ValidationException::withMessages(['catalog_category_id'=>'Category type does not match this product class.']);
  return $category->id;
 }
}
<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationalDataHubController extends Controller
{
 private function definition(string $module,string $page):array
 {
  $cfg=(config('operational_data_hubs')??[])[$module.'.'.$page]??null;
  abort_unless($cfg,404);
  return $cfg;
 }

 private function authorizeAccess(Request $request,string $module):void
 {
  $required=$module==='assets'?'maintenance.view':$module.'.view';
  abort_unless($request->user()->canAny([$required,'administration.view']),403);
 }

 private function query(array $config):Builder
 {
  $q=DB::table($config['table']);
  foreach($config['joins']??[] as [$table,$first,$op,$second])
   $q->leftJoin($table,$first,$op,$second);
  foreach($config['conditions']??[] as [$column,$op,$value])
   $q->where($column,$op,$value);
  $q->select(DB::raw($config['id'].' as _source_id'));
  $i=0;
  foreach($config['columns'] as $column=>$label){
   // All identifiers are static repository configuration, never request values.
   $q->addSelect(DB::raw($column.' as col'.$i));
   $i++;
  }
  return $q->orderByDesc($config['order']);
 }

 public function show(Request $request,string $module,string $page)
 {
  $def=$this->definition($module,$page);
  $this->authorizeAccess($request,$module);
  return view('operations.data-hub',[
   'module'=>$module,'page'=>$page,'definition'=>$def,
   'rows'=>$this->query($def)->paginate(25),
  ]);
 }

 public function export(Request $request,string $module,string $page)
 {
  $def=$this->definition($module,$page);$this->authorizeAccess($request,$module);
  return response()->streamDownload(function()use($def){
   $stream=fopen('php://output','w');
   fputcsv($stream,array_values($def['columns']));
   foreach($this->query($def)->limit(5000)->cursor() as $record){
    $row=[];
    for($i=0;$i<count($def['columns']);$i++){
     $value=(string)($record->{'col'.$i}??'');
     $row[]=preg_match('/^\s*[=+\-@]/u',$value)?"'".$value:$value;
    }
    fputcsv($stream,$row);
   }
   fclose($stream);
  },'kanozay-'.str_replace('.','-',"$module.$page").'-'.today()->format('Ymd').'.csv',
  ['Content-Type'=>'text/csv; charset=UTF-8']);
 }
}

<?php

namespace App\Support;

class ErpInterfaceLabels
{
 public static function module(string $module,?string $fallback=null):string
 {
  $locale=app()->getLocale();
  return config('erp_interface_labels.'.$locale.'.modules.'.$module)
   ??$fallback??(string)config('erp_pages.'.$module.'.label',$module);
 }

 public static function page(string $module,string $page,?string $fallback=null):string
 {
  $locale=app()->getLocale();
  return (config('erp_interface_labels')??[])[$locale]['pages'][$module.'.'.$page]
   ??$fallback??(string)config('erp_pages.'.$module.'.pages.'.$page,$page);
 }

 public static function rail(string $module,string $fallback):string
 {
  return self::module($module,$fallback);
 }
}

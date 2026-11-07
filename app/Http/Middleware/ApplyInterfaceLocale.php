<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class ApplyInterfaceLocale
{
 public function handle(Request $request,Closure $next):Response
 {
  $locale=$request->session()->get('locale','en');
  if(!in_array($locale,['en','fa','ps'],true))$locale='en';
  App::setLocale($locale);
  return $next($request);
 }
}

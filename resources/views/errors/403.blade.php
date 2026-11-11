@extends('layouts.kanozay')
@section('content')
<section class="panel" style="max-width:740px;margin:55px auto;padding:42px;text-align:center">
 <div style="font-size:58px;color:#b42318;margin-bottom:12px"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></div>
 <h1 style="font-size:32px;margin:0 0 14px">403 — Access denied</h1>
 <p style="font-size:15px;line-height:1.8;color:#526783">You do not have permission to open this page. Ask an administrator if you need access.</p>
 @if(isset($exception) && $exception->getMessage() !== '' && $exception->getMessage() !== 'This action is unauthorized.')
 <p style="font-size:14px;line-height:1.8;color:#775019;background:#fffbf2;border:1px solid #f2d9b5;border-radius:9px;padding:12px">{{ $exception->getMessage() }}</p>
 @endif
 <a class="btn" style="display:inline-block;margin-top:20px" href="{{ route('dashboard') }}">Return to dashboard</a>
</section>
@endsection

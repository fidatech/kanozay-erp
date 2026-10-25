<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in | Kanozay Foods ERP</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<style>
:root{--navy:#0d1f4c;--blue:#1565e0;--mut:#6b7794;--tx:#16264f}
*{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Inter,Segoe UI,Arial,sans-serif;color:var(--tx);font-size:13px}
#login{min-height:100vh;display:flex}.lg-l{flex:1.1;background:linear-gradient(150deg,#0d1f4c,#1565e0);color:#fff;padding:60px;display:flex;flex-direction:column;justify-content:center}
.lg-l h1{font-size:38px;margin:24px 0 12px;line-height:1.15;max-width:420px}.lg-l p{max-width:400px;opacity:.85;line-height:1.6}
.lg-r{flex:1;display:flex;align-items:center;justify-content:center;background:#fff;padding:30px}
.lg-box{width:100%;max-width:380px}.lg-box h2{font-size:26px;margin:0 0 6px}.subtitle{color:var(--mut);margin-bottom:26px}
.fg{margin-bottom:16px}.fg label{display:block;font-weight:600;margin-bottom:6px;font-size:12.5px}
.in{width:100%;height:40px;border:1px solid #d5dcea;border-radius:8px;padding:0 12px;font-size:13px;color:var(--tx);background:#fff}
.in:focus{outline:2px solid var(--blue);outline-offset:-1px;border-color:var(--blue)}
.btn{height:40px;border:0;border-radius:8px;background:var(--navy);color:#fff;font-weight:600;font-size:13px;padding:0 18px;cursor:pointer;width:100%}
.row{display:flex;justify-content:space-between;align-items:center}.logo{display:flex;align-items:center;gap:8px;font-weight:800;letter-spacing:.5px}
.logo b{width:36px;height:36px;border-radius:10px 2px 10px 2px;background:#2f9e44;display:grid;place-items:center;color:#fff;font-size:18px}
a{color:var(--blue);text-decoration:none}.error{color:#b42318;font-size:12px;margin-top:5px}.status{color:#087a47;margin-bottom:16px}
@media(max-width:850px){.lg-l{display:none}.lg-r{min-height:100vh}}
</style>
</head>
<body>
<section id="login">
<div class="lg-l"><div class="logo"><b>K</b>KANOZAY FOODS</div><h1>Run the whole factory from one place.</h1><p>Purchases, inventory, production, finance and HR for Kanozay Foods, with approvals and alerts that reach you the moment they matter.</p></div>
<div class="lg-r"><form class="lg-box" method="POST" action="{{ route('login') }}">
@csrf
<h2>Welcome back</h2><div class="subtitle">Sign in to the Kanozay ERP System</div>
@if(session('status'))<div class="status">{{ session('status') }}</div>@endif
<div class="fg"><label for="email">Email</label><input class="in" type="email" id="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>@error('email')<div class="error">{{ $message }}</div>@enderror</div>
<div class="fg"><label for="password">Password</label><input class="in" type="password" id="password" name="password" autocomplete="current-password" required>@error('password')<div class="error">{{ $message }}</div>@enderror</div>
<div class="row fg"><label style="margin:0;font-weight:500"><input type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}> Remember me</label><a href="{{ route('password.request') }}">Forgot password?</a></div>
<button class="btn" type="submit">Sign in</button>
</form></div>
</section>
</body></html>
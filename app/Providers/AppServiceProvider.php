<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Security audit: sign-in, failed sign-in, lockout, sign-out and password reset.
        $audit = \App\Listeners\RecordAuthenticationActivity::class;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, [$audit, 'login']);
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Failed::class, [$audit, 'failed']);
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Logout::class, [$audit, 'logout']);
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Lockout::class, [$audit, 'lockout']);
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\PasswordReset::class, [$audit, 'passwordReset']);
    }
}

<?php

namespace App\Providers;

use App\Support\SessionLifetime;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

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
        // Idle and absolute session limits (#86), checked as Sanctum validates
        // each bearer token, before it records this request's last_used_at.
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid) => SessionLifetime::accept($token, $isValid)
        );
    }
}

<?php

namespace App\Providers;

use App\Models\User;
use App\Observers\UserObserver;
use App\Policies\RolePolicy;
use App\Services\WhatsApp\WhatsAppManager;
use App\Services\WhatsApp\WhatsAppProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WhatsAppManager::class);
        $this->app->bind(WhatsAppProvider::class, fn ($app) => $app->make(WhatsAppManager::class)->provider());
    }

    public function boot(): void
    {
        // Super Admin bypasses every policy check.
        Gate::before(fn (User $user, string $ability) => $user->hasRole('super_admin') ? true : null);
        Gate::policy(Role::class, RolePolicy::class);

        User::observe(UserObserver::class);

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinute(3)->by((string) $request->input('phone').'|'.$request->ip()),
            Limit::perHour(10)->by((string) $request->input('phone')),
        ]);
        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinute(10)->by((string) $request->input('phone').'|'.$request->ip()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
    }
}

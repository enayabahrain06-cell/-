<?php

namespace App\Providers;

use Ahl\Certificates\Events\CertificateApproved;
use Ahl\Certificates\Events\CertificateRevoked;
use App\Certificates\AuditCertificateEvents;
use App\Models\User;
use App\Observers\UserObserver;
use App\Policies\RolePolicy;
use App\Services\WhatsApp\WhatsAppManager;
use App\Services\WhatsApp\WhatsAppProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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

        // Certificates package: approvals and revocations go to the audit log.
        Event::listen(CertificateApproved::class, [AuditCertificateEvents::class, 'approved']);
        Event::listen(CertificateRevoked::class, [AuditCertificateEvents::class, 'revoked']);

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinute(3)->by((string) $request->input('phone').'|'.$request->ip()),
            Limit::perHour(10)->by((string) $request->input('phone')),
        ]);
        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinute(10)->by((string) $request->input('phone').'|'.$request->ip()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        // Public pages. Unnamed throttles (throttle:60,1 next to throttle:10,1) share one counter per IP, so page
        // loads used up the submit limit; named limiters keep separate counters.
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)->by('public|'.$request->ip()));
        RateLimiter::for('registration-submit', fn (Request $request) => Limit::perMinute(10)->by('registration-submit|'.$request->ip()));
        // Public placement test: starting and submitting attempts, kept apart from the registration POST limit.
        RateLimiter::for('placement', fn (Request $request) => Limit::perMinute(20)->by('placement|'.$request->ip()));
        // Resume and autosave (the player saves shortly after every change and every 20 s).
        RateLimiter::for('placement-answers', fn (Request $request) => Limit::perMinute(120)->by('placement-answers|'.$request->ip()));
    }
}

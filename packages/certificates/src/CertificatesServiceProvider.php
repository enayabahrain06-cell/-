<?php

namespace Ahl\Certificates;

use Ahl\Certificates\Contracts\CertificateNotifier;
use Ahl\Certificates\Contracts\FileStore;
use Ahl\Certificates\Contracts\Host;
use Ahl\Certificates\Contracts\PdfRenderer;
use Ahl\Certificates\Models\Certificate;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CertificatesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/certificates.php', 'certificates');

        $this->app->bind(Host::class, fn ($app) => $app->make(config('certificates.host')));
        $this->app->bind(CertificateNotifier::class, fn ($app) => $app->make(config('certificates.notifier')));
        $this->app->bind(FileStore::class, fn ($app) => $app->make(config('certificates.files')));
        $this->app->bind(PdfRenderer::class, fn ($app) => $app->make(config('certificates.pdf_renderer')));
        $this->app->bind(CertificateService::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'certificates');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'certificates');

        if (config('certificates.migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $policy = config('certificates.policy');
        Gate::policy(Certificate::class, $policy);
        if (Certificates::model() !== Certificate::class) {
            Gate::policy(Certificates::model(), $policy);
        }

        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/certificates.php' => config_path('certificates.php')], 'certificates-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'certificates-migrations');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/certificates')], 'certificates-views');
            $this->publishes([__DIR__.'/../lang' => $this->app->langPath('vendor/certificates')], 'certificates-lang');
        }
    }

    private function registerRoutes(): void
    {
        // {certificate} always resolves to the configured model (a host subclass keeps its own relations).
        Route::bind('certificate', fn ($value) => Certificates::model()::query()->findOrFail($value));

        if (! config('certificates.routes.enabled', true) || $this->app->routesAreCached()) {
            return;
        }
        $prefix = config('certificates.routes.prefix', 'api');

        Route::prefix($prefix)->middleware(config('certificates.routes.public_middleware', ['api']))
            ->group(__DIR__.'/../routes/public.php');
        Route::prefix($prefix)->middleware(config('certificates.routes.middleware', ['api', 'auth:sanctum']))
            ->group(__DIR__.'/../routes/api.php');
    }
}

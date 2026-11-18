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
        // admin paneldagi har bir o'zgarish (kim, qachon, nima: eski → yangi) bazaga yoziladi
        \App\Models\Site::observe(\App\Observers\PanelAuditObserver::class);
        \App\Models\LeaseContract::observe(\App\Observers\PanelAuditObserver::class);
    }
}

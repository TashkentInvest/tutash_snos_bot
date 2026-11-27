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

        // sanalar (masalan «4 soat avval») lotin yozuvida: Carbon'dagi «uz» kirillcha, «uz_Latn» — lotincha
        $latin = fn () => app()->getLocale() === 'uz' && \Carbon\Carbon::setLocale('uz_Latn');
        $latin();
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Foundation\Events\LocaleUpdated::class, $latin);
    }
}
